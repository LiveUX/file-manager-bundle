<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final readonly class UploadManager
{
    private const int MAX_CHUNKS = 2000;
    private const int MAX_ACTIVE_CHUNK_SESSIONS = 150;
    private const int MAX_USER_CHUNK_SESSIONS = 20;
    private const int CHUNK_TTL_SECONDS = 7200;
    private const string SESSION_LOCK_FILE = '.session.lock';

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FilenameNormalizer $filenameNormalizer,
        private EventDispatcherInterface $eventDispatcher,
        private SvgSanitizer $svgSanitizer = new SvgSanitizer(),
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private ImageOptimizer $imageOptimizer = new ImageOptimizer(),
        private ?StorageQuotaChecker $quotaChecker = null,
        private ?DynamicPathResolver $dynamicPathResolver = null,
        private ?DuplicateDetector $duplicateDetector = null,
        private ?Security $security = null,
        private ?AssetUsageGuardService $usageGuard = null,
        private ?\Symfony\Component\HttpFoundation\RequestStack $requestStack = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    /**
     * @return array{completed: bool, path?: string, name?: string, size?: int, chunkIndex?: int}
     *
     * @throws FileException
     * @throws FilesystemException
     */
    public function handleUpload(
        ProfileConfig $profile,
        UploadedFile $uploadedFile,
        string $targetDirectory = '',
        ?string $volumeName = null,
        ?string $uploadId = null,
        int $chunkIndex = 0,
        int $totalChunks = 1,
        ?string $originalFilename = null,
    ): array {
        $cleanDir = $this->pathNormalizer->normalize($targetDirectory);
        $this->fileSecurityGuard->assertAccessiblePath($cleanDir, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertUploadGranted($profile, $cleanDir, $volume->name);
        $effectiveDir = $this->resolveEffectiveUploadDir($profile, $cleanDir);
        if ($effectiveDir !== $cleanDir) {
            $this->assertUploadGranted($profile, $effectiveDir, $volume->name);
        }
        $this->assertSubfolderCreationAllowed($profile, $cleanDir, $volume->name);

        $clientName = $originalFilename ?? $uploadedFile->getClientOriginalName();
        $this->fileSecurityGuard->assertSafeFilename($clientName, strictOsChars: false);

        if ($totalChunks > 1) {
            if (($profile->chunkUpload['enabled'] ?? true) !== true) {
                throw new UploadValidationException('Chunked uploads are disabled for this profile.', 'errors.chunk_disabled');
            }
            if ($uploadId === null || trim($uploadId) === '') {
                throw new UploadValidationException('Invalid upload session identifier.', 'errors.invalid_upload_id');
            }
            $this->filenameNormalizer->normalizeForUpload($clientName, $profile, PathNormalizer::basenameUtf8($effectiveDir));

            return $this->handleChunk($profile, $uploadedFile, $cleanDir, $volume->name, $uploadId, $chunkIndex, $totalChunks, $clientName, $effectiveDir);
        }

        $localPath = $uploadedFile->getPathname();

        return $this->finalizeFile($profile, $localPath, $clientName, $cleanDir, $volume->name, $effectiveDir);
    }

    /**
     * Finalizes a file downloaded to a local temporary path (e.g. via RemoteUrlImporter).
     *
     * @return array{completed: bool, path: string, name: string, size: int}
     *
     * @throws FilesystemException
     */
    public function handleImportedTempFile(
        ProfileConfig $profile,
        string $localTempPath,
        string $clientName,
        string $targetDirectory = '',
        ?string $volumeName = null,
    ): array {
        $cleanDir = $this->pathNormalizer->normalize($targetDirectory);
        $this->fileSecurityGuard->assertAccessiblePath($cleanDir, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertCanImportInto($profile, $cleanDir, $volume->name);
        $this->fileSecurityGuard->assertSafeFilename($clientName, strictOsChars: false);

        return $this->finalizeFile($profile, $localTempPath, $clientName, $cleanDir, $volume->name);
    }

    /**
     * Pre-authorizes upload + effective upload_subfolder + subfolder creation BEFORE
     * remote URL downloads execute so denied users cannot force outbound SSRF/bandwidth fetches.
     */
    public function assertCanImportInto(ProfileConfig $profile, string $targetDirectory = '', ?string $volumeName = null): void
    {
        $cleanDir = $this->pathNormalizer->normalize($targetDirectory);
        $this->fileSecurityGuard->assertAccessiblePath($cleanDir, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertUploadGranted($profile, $cleanDir, $volume->name);
        $effectiveDir = $this->resolveEffectiveUploadDir($profile, $cleanDir);
        if ($effectiveDir !== $cleanDir) {
            $this->assertUploadGranted($profile, $effectiveDir, $volume->name);
        }
        $this->assertSubfolderCreationAllowed($profile, $cleanDir, $volume->name);
    }

    /**
     * @return array{completed: bool, path?: string, name?: string, size?: int, chunkIndex?: int}
     */
    private function handleChunk(
        ProfileConfig $profile,
        UploadedFile $chunkFile,
        string $cleanDir,
        ?string $volumeName,
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $clientName,
        ?string $authorizedEffectiveDir = null,
    ): array {
        if ($totalChunks > self::MAX_CHUNKS) {
            throw new UploadValidationException(sprintf('Maximum allowed chunks (%d) exceeded.', self::MAX_CHUNKS), 'errors.max_chunks_exceeded', ['%max%' => self::MAX_CHUNKS]);
        }

        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new UploadValidationException('Invalid upload chunk index.', 'errors.invalid_chunk_index');
        }

        $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '', $uploadId) ?? '';
        if (strlen($safeId) < 8 || strlen($safeId) > 80) {
            throw new UploadValidationException('Invalid upload session identifier.', 'errors.invalid_upload_id');
        }

        $maxBytes = $this->parseByteSize($profile->uploadMaxSize);
        $chunkSize = (int) $chunkFile->getSize();
        if ($maxBytes > 0 && $chunkSize > $maxBytes) {
            throw new UploadValidationException(sprintf('Chunk exceeds maximum allowed file size (%s).', $profile->uploadMaxSize), 'errors.chunk_too_large', ['%max%' => $profile->uploadMaxSize]);
        }

        $chunkDir = $this->resolveChunkDir($profile, $volumeName, $cleanDir, $safeId);

        if ($chunkIndex === 0 || $chunkIndex === 1 || random_int(1, 16) === 1) {
            $this->purgeStaleChunkDirs();
        }

        // Acquire the persistent external per-session lock once across directory creation, chunk write,
        // completeness check, and assembly so POSIX unlink/recreate cannot split locks or resurrect aborted sessions.
        $lock = $this->acquireChunkSessionLock($chunkDir, false);
        $exceededMax = false;
        $assembledTempPath = null;

        try {
            if (!is_dir($chunkDir)) {
                $this->assertActiveChunkSessionCap(dirname($chunkDir));
                if (!@mkdir($chunkDir, 0750, true) && !is_dir($chunkDir)) {
                    throw new UploadValidationException('Unable to allocate temporary chunk storage.', 'errors.chunk_assemble_failed');
                }
            }

            $exceededMax = $this->persistChunkLocked(
                $chunkDir,
                $chunkFile,
                $chunkIndex,
                $chunkSize,
                $maxBytes,
                $profile,
                $volumeName,
                $clientName,
                $authorizedEffectiveDir ?? $cleanDir,
            );

            if ($exceededMax) {
                $this->cleanupChunkDir($chunkDir);
                @rmdir($chunkDir);
            } elseif ($this->allChunksPresent($chunkDir, $totalChunks)) {
                try {
                    $assembledPath = $this->assembleChunks($profile, $chunkDir, $totalChunks, $maxBytes, $clientName);
                    $assembledTempPath = $this->detachAssembledTempFile($assembledPath);
                } finally {
                    $this->cleanupChunkDir($chunkDir);
                    @rmdir($chunkDir);
                }
            }
        } finally {
            $this->releaseChunkSessionLock($lock);
        }

        if ($exceededMax) {
            throw new UploadValidationException(sprintf('Uploaded file exceeds maximum allowed size (%s).', $profile->uploadMaxSize), 'errors.upload_too_large', ['%name%' => $clientName, '%max%' => $profile->uploadMaxSize]);
        }

        if ($assembledTempPath === null) {
            return ['completed' => false, 'chunkIndex' => $chunkIndex];
        }

        try {
            return $this->finalizeFile($profile, $assembledTempPath, $clientName, $cleanDir, $volumeName, $authorizedEffectiveDir);
        } finally {
            @unlink($assembledTempPath);
        }
    }

    private function assembleChunks(ProfileConfig $profile, string $chunkDir, int $totalChunks, int $maxBytes, string $clientName): string
    {
        $assembledPath = $chunkDir . DIRECTORY_SEPARATOR . 'assembled.bin';
        $out = fopen($assembledPath, 'wb');
        if ($out === false) {
            throw new UploadValidationException('Unable to assemble uploaded chunks.', 'errors.chunk_assemble_failed');
        }

        $cumulativeBytes = 0;
        try {
            for ($i = 0; $i < $totalChunks; ++$i) {
                $part = $chunkDir . DIRECTORY_SEPARATOR . sprintf('part_%05d', $i);
                if (!is_file($part)) {
                    throw new UploadValidationException(sprintf('Missing upload chunk %d of %d.', $i + 1, $totalChunks), 'errors.chunk_missing', ['%index%' => $i + 1, '%total%' => $totalChunks]);
                }
                clearstatcache(true, $part);
                $cumulativeBytes += (int) filesize($part);
                if ($maxBytes > 0 && $cumulativeBytes > $maxBytes) {
                    throw new UploadValidationException(sprintf('Uploaded file exceeds maximum allowed size (%s).', $profile->uploadMaxSize), 'errors.upload_too_large', ['%name%' => $clientName, '%max%' => $profile->uploadMaxSize]);
                }
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new UploadValidationException('Unable to assemble uploaded chunks.', 'errors.chunk_assemble_failed');
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied === false) {
                    throw new UploadValidationException('Unable to assemble uploaded chunks.', 'errors.chunk_assemble_failed');
                }
                @unlink($part);
            }
        } finally {
            fclose($out);
        }

        return $assembledPath;
    }

    /**
     * Acquires an exclusive lock stored outside `$chunkDir` in a private UID-scoped `0700` lock directory.
     * Lock files are never unlinked on session completion, eliminating POSIX inode-split races.
     *
     * @return resource|null returns `null` only when `$nonBlocking === true` and the lock is held
     */
    private function acquireChunkSessionLock(string $chunkDir, bool $nonBlocking)
    {
        $lockPath = $this->resolveChunkSessionLockFilePath($chunkDir);
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            if ($nonBlocking) {
                return null;
            }

            throw new UploadValidationException('Unable to acquire chunk session lock.', 'errors.chunk_assemble_failed');
        }

        $flags = $nonBlocking ? (LOCK_EX | LOCK_NB) : LOCK_EX;
        if (!@flock($handle, $flags)) {
            fclose($handle);
            if ($nonBlocking) {
                return null;
            }

            throw new UploadValidationException('Unable to acquire chunk session lock.', 'errors.chunk_assemble_failed');
        }

        return $handle;
    }

    private function resolveChunkSessionLockFilePath(string $chunkDir): string
    {
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) get_current_user());
        $lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_locks_' . ($uid !== '' ? $uid : 'default');
        if (is_link($lockDir)) {
            throw new UploadValidationException('Lock directory must not be a symlink.', 'errors.chunk_assemble_failed');
        }
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new UploadValidationException('Unable to allocate chunk session lock directory.', 'errors.chunk_assemble_failed');
        }
        clearstatcache(true, $lockDir);
        // @phpstan-ignore if.alwaysFalse
        if (is_link($lockDir)) {
            throw new UploadValidationException('Lock directory must not be a symlink.', 'errors.chunk_assemble_failed');
        }

        $lockPath = $lockDir . DIRECTORY_SEPARATOR . 'chunk_' . hash('sha256', $chunkDir) . '.lock';
        if (is_link($lockPath)) {
            throw new UploadValidationException('Chunk session lock file must not be a symlink.', 'errors.chunk_assemble_failed');
        }

        return $lockPath;
    }

    private function detachAssembledTempFile(string $assembledPath): string
    {
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_assembled_' . bin2hex(random_bytes(12)) . '.bin';
        if (@rename($assembledPath, $target)) {
            return $target;
        }

        $in = @fopen($assembledPath, 'rb');
        $out = @fopen($target, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($target);
            throw new UploadValidationException('Unable to assemble uploaded chunks.', 'errors.chunk_assemble_failed');
        }

        try {
            $copied = stream_copy_to_stream($in, $out);
            if ($copied === false) {
                throw new UploadValidationException('Unable to assemble uploaded chunks.', 'errors.chunk_assemble_failed');
            }
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        } finally {
            fclose($in);
            fclose($out);
            @unlink($assembledPath);
        }

        return $target;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseChunkSessionLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Reads live filesystem state (another request may consume the parts between two calls).
     *
     * @phpstan-impure
     */
    private function allChunksPresent(string $chunkDir, int $totalChunks): bool
    {
        for ($i = 0; $i < $totalChunks; ++$i) {
            if (!is_file($chunkDir . DIRECTORY_SEPARATOR . sprintf('part_%05d', $i))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cleans up any temporary chunk files left behind when a user cancels an in-progress chunked upload.
     */
    public function abortChunkedUpload(
        ProfileConfig $profile,
        string $uploadId,
        ?string $volumeName = null,
        string $targetDirectory = '',
    ): bool {
        $cleanDir = $this->pathNormalizer->normalize($targetDirectory);
        $this->fileSecurityGuard->assertAccessiblePath($cleanDir, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertUploadGranted($profile, $cleanDir, $volume->name);
        $effectiveDir = $this->resolveEffectiveUploadDir($profile, $cleanDir);
        if ($effectiveDir !== $cleanDir) {
            $this->assertUploadGranted($profile, $effectiveDir, $volume->name);
        }

        $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '', $uploadId) ?? '';
        if (strlen($safeId) < 8 || strlen($safeId) > 80) {
            return false;
        }

        $chunkDir = $this->resolveChunkDir($profile, $volume->name, $cleanDir, $safeId);
        $lock = $this->acquireChunkSessionLock($chunkDir, true);
        if ($lock === null) {
            return false;
        }

        try {
            if (!is_dir($chunkDir)) {
                return false;
            }
            $this->cleanupChunkDir($chunkDir);
            @rmdir($chunkDir);
        } finally {
            $this->releaseChunkSessionLock($lock);
        }

        return true;
    }

    private function measureChunkDirBytes(string $chunkDir, string $excludeBasename): int
    {
        $total = 0;
        foreach (scandir($chunkDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $excludeBasename || !str_starts_with($entry, 'part_')) {
                continue;
            }
            $path = $chunkDir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                clearstatcache(true, $path);
                $total += (int) @filesize($path);
            }
        }

        return $total;
    }

    private function purgeStaleChunkDirs(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_chunks';
        if (!is_dir($root)) {
            return;
        }

        $cutoff = time() - self::CHUNK_TTL_SECONDS;
        $purged = 0;
        foreach (scandir($root) ?: [] as $userEntry) {
            if ($userEntry === '.' || $userEntry === '..') {
                continue;
            }
            $userPath = $root . DIRECTORY_SEPARATOR . $userEntry;
            if (!is_dir($userPath)) {
                continue;
            }

            // Support both legacy flat session dirs and user-partitioned session dirs cleanly.
            $mtime = @filemtime($userPath);
            if ($this->isChunkSessionDir($userPath)) {
                if (is_int($mtime) && $mtime < $cutoff && $this->safelyPurgeUnlockedChunkDir($userPath)) {
                    if (++$purged >= 100) {
                        return;
                    }
                }
                continue;
            }

            $remaining = 0;
            foreach (scandir($userPath) ?: [] as $sessionEntry) {
                if ($sessionEntry === '.' || $sessionEntry === '..') {
                    continue;
                }
                $sessionPath = $userPath . DIRECTORY_SEPARATOR . $sessionEntry;
                $sMtime = @filemtime($sessionPath);
                if (is_dir($sessionPath) && is_int($sMtime) && $sMtime < $cutoff && $this->safelyPurgeUnlockedChunkDir($sessionPath)) {
                    if (++$purged >= 100) {
                        return;
                    }
                    continue;
                }
                ++$remaining;
            }
            if ($remaining === 0) {
                @rmdir($userPath);
            }
        }
    }

    private function isChunkSessionDir(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === self::SESSION_LOCK_FILE || $entry === '.assemble.lock' || str_starts_with($entry, 'part_') || str_starts_with($entry, '.part_')) {
                return true;
            }
        }

        return false;
    }

    private function assertActiveChunkSessionCap(string $userChunkRoot): void
    {
        $userActive = $this->countDirEntries($userChunkRoot);
        if ($userActive >= self::MAX_USER_CHUNK_SESSIONS) {
            $this->purgeStaleChunkDirs();
            $userActive = $this->countDirEntries($userChunkRoot);
            if ($userActive >= self::MAX_USER_CHUNK_SESSIONS) {
                throw new UploadValidationException('Too many concurrent chunked upload sessions.', 'errors.chunk_assemble_failed');
            }
        }

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_chunks';
        $totalActive = $this->countTotalChunkSessions($root);
        if ($totalActive >= self::MAX_ACTIVE_CHUNK_SESSIONS) {
            $this->purgeStaleChunkDirs();
            if ($this->countTotalChunkSessions($root) >= self::MAX_ACTIVE_CHUNK_SESSIONS) {
                throw new UploadValidationException('Too many concurrent chunked upload sessions.', 'errors.chunk_assemble_failed');
            }
        }
    }

    private function countDirEntries(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                ++$count;
            }
        }

        return $count;
    }

    private function countTotalChunkSessions(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }

        $total = 0;
        foreach (scandir($root) ?: [] as $userEntry) {
            if ($userEntry === '.' || $userEntry === '..') {
                continue;
            }
            $userPath = $root . DIRECTORY_SEPARATOR . $userEntry;
            if (!is_dir($userPath)) {
                continue;
            }
            if ($this->isChunkSessionDir($userPath)) {
                ++$total;
                continue;
            }
            $total += $this->countDirEntries($userPath);
        }

        return $total;
    }

    /**
     * Persists a single chunk while the caller already holds the exclusive external session lock.
     *
     * @return bool true when cumulative session bytes exceed `$maxBytes` (caller must purge & throw)
     */
    private function persistChunkLocked(
        string $chunkDir,
        UploadedFile $chunkFile,
        int $chunkIndex,
        int $chunkSize,
        int $maxBytes,
        ProfileConfig $profile,
        ?string $volumeName,
        string $clientName,
        string $targetDir = '',
    ): bool {
        $partBase = sprintf('part_%05d', $chunkIndex);
        $partFile = $chunkDir . DIRECTORY_SEPARATOR . $partBase;
        $existingBytes = $this->measureChunkDirBytes($chunkDir, $partBase);
        if ($maxBytes > 0 && ($existingBytes + $chunkSize) > $maxBytes) {
            return true;
        }

        $incomingBytes = $existingBytes + $chunkSize;
        if ($incomingBytes > 0 && $this->quotaChecker !== null) {
            $existingTargetSize = $this->resolveOverwriteTargetSize($profile, $volumeName, $targetDir, $clientName);
            $deltaBytes = max(0, $incomingBytes - $existingTargetSize);
            if ($deltaBytes > 0) {
                $this->quotaChecker->assertHasQuota($profile, $volumeName, $deltaBytes);
            }
        }

        $tmpPartBase = sprintf('.part_%05d.%s.tmp', $chunkIndex, bin2hex(random_bytes(4)));
        $tmpPartPath = $chunkDir . DIRECTORY_SEPARATOR . $tmpPartBase;
        $chunkFile->move($chunkDir, $tmpPartBase);
        if (!@rename($tmpPartPath, $partFile)) {
            @unlink($tmpPartPath);
            throw new UploadValidationException('Unable to persist upload chunk.', 'errors.chunk_assemble_failed');
        }

        return false;
    }

    /**
     * When `overwrite_policy === 'overwrite'`, computes the byte size of an already-existing
     * target file so chunked uploads replacing a large file do not fail quota prematurely.
     */
    private function resolveOverwriteTargetSize(
        ProfileConfig $profile,
        ?string $volumeName,
        string $targetDir,
        string $clientName,
    ): int {
        if ($profile->overwritePolicy !== 'overwrite') {
            return 0;
        }

        try {
            $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
            $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
            $normalizedName = $this->filenameNormalizer->normalizeForUpload(
                $clientName,
                $profile,
                PathNormalizer::basenameUtf8($targetDir),
            );
            $candidate = $this->pathNormalizer->join($targetDir, $normalizedName);
            $this->fileSecurityGuard->assertAccessiblePath($candidate, $profile);
            if ($filesystem->fileExists($candidate)) {
                return max(0, $filesystem->fileSize($candidate));
            }
        } catch (Throwable) {
            return 0;
        }

        return 0;
    }

    private function resolveChunkDir(ProfileConfig $profile, ?string $volumeName, string $cleanDir, string $safeId): string
    {
        $userScope = $this->resolveChunkScopeIdentity();
        $userHash = hash('sha256', $userScope);
        $scopedId = hash('sha256', $profile->name . '|' . ($volumeName ?? '') . '|' . $cleanDir . '|' . $userScope . '|' . $safeId);

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_chunks' . DIRECTORY_SEPARATOR . $userHash . DIRECTORY_SEPARATOR . $scopedId;
    }

    private function safelyPurgeUnlockedChunkDir(string $chunkDir): bool
    {
        $lock = $this->acquireChunkSessionLock($chunkDir, true);
        if ($lock === null) {
            return false;
        }

        try {
            if (!is_dir($chunkDir)) {
                return false;
            }
            $this->cleanupChunkDir($chunkDir);
            @rmdir($chunkDir);
        } finally {
            $this->releaseChunkSessionLock($lock);
        }

        return true;
    }

    private function resolveChunkScopeIdentity(): string
    {
        $user = $this->security?->getUser();
        if ($user === null) {
            $req = $this->requestStack?->getCurrentRequest();
            if ($req !== null) {
                $sessionAvailable = $req->hasPreviousSession() || ($req->hasSession() && $req->getSession()->isStarted());
                if ($sessionAvailable) {
                    try {
                        $sid = $req->getSession()->getId();
                        if ($sid !== '') {
                            return 'anon_sess:' . $sid;
                        }
                    } catch (Throwable) {
                    }
                }
                $ip = $req->getClientIp();
                if (is_string($ip) && $ip !== '') {
                    return 'anon_ip:' . $ip;
                }
            }

            return 'anon';
        }

        return 'user:' . $user->getUserIdentifier();
    }

    private function cleanupChunkDir(string $chunkDir, string $skipBasename = ''): void
    {
        if (!is_dir($chunkDir)) {
            return;
        }

        foreach (scandir($chunkDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($skipBasename !== '' && $entry === $skipBasename)) {
                continue;
            }
            @unlink($chunkDir . DIRECTORY_SEPARATOR . $entry);
        }
        if ($skipBasename === '') {
            @rmdir($chunkDir);
        }
    }

    /**
     * @return array{completed: bool, path: string, name: string, size: int}
     */
    private function finalizeFile(
        ProfileConfig $profile,
        string $localTempPath,
        string $clientName,
        string $cleanDir,
        ?string $volumeName,
        ?string $authorizedEffectiveDir = null,
    ): array {
        clearstatcache(true, $localTempPath);
        $size = is_file($localTempPath) ? (int) filesize($localTempPath) : 0;
        $maxBytes = $this->parseByteSize($profile->uploadMaxSize);

        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new UploadValidationException(sprintf('File "%s" exceeds maximum allowed size (%s).', $clientName, $profile->uploadMaxSize), 'errors.upload_too_large', ['%name%' => $clientName, '%max%' => $profile->uploadMaxSize]);
        }

        $effectiveDir = $authorizedEffectiveDir ?? $this->resolveEffectiveUploadDir($profile, $cleanDir);
        // Normalize filename FIRST so MIME matching, SVG sanitization, and image optimization
        // inspect the exact final extension and basename that will be persisted.
        $normalizedName = $this->filenameNormalizer->normalizeForUpload($clientName, $profile, PathNormalizer::basenameUtf8($effectiveDir));

        $mimeType = $this->detectMimeType($localTempPath, $normalizedName);
        $this->validateMimeType($profile, $mimeType, $normalizedName);

        $ext = PathNormalizer::extensionUtf8($normalizedName);
        if ($ext === 'svg' || $mimeType === 'image/svg+xml') {
            clearstatcache(true, $localTempPath);
            if ($size > SvgSanitizer::MAX_SVG_BYTES) {
                throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
            }
            $rawSvg = file_get_contents($localTempPath);
            if (!is_string($rawSvg) || trim($rawSvg) === '') {
                throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
            }
            if (($profile->security['sanitize_svg'] ?? true) === true) {
                $sanitizedSvg = $this->svgSanitizer->sanitize($rawSvg);
                if ($sanitizedSvg === '') {
                    throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
                }
                $written = @file_put_contents($localTempPath, $sanitizedSvg);
                if ($written === false || $written !== strlen($sanitizedSvg)) {
                    throw new UploadValidationException('Unable to persist sanitized SVG file.', 'errors.upload_failed');
                }
                clearstatcache(true, $localTempPath);
                $size = strlen($sanitizedSvg);
            } elseif (str_contains($rawSvg, "\0") || !mb_check_encoding($rawSvg, 'UTF-8') || preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $rawSvg) === 1) {
                throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
            }
        } else {
            $optimized = $this->imageOptimizer->optimizeIfEnabled($localTempPath, $normalizedName, $mimeType, $profile);
            if ($optimized['filename'] !== $normalizedName) {
                $normalizedName = $optimized['filename'];
                $this->validateMimeType($profile, $optimized['mime'], $normalizedName);
            }
            $mimeType = $optimized['mime'];
            $size = $optimized['size'];
            if ($maxBytes > 0 && $size > $maxBytes) {
                throw new UploadValidationException(sprintf('File "%s" exceeds maximum allowed size (%s).', $normalizedName, $profile->uploadMaxSize), 'errors.upload_too_large', ['%name%' => $normalizedName, '%max%' => $profile->uploadMaxSize]);
            }
            $this->validateImageConstraints($profile, $localTempPath, $mimeType, $normalizedName);
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);

        $duplicateOf = null;
        if ($this->duplicateDetector !== null && $this->duplicateDetector->isEnabled($profile)) {
            $candidateDup = $this->duplicateDetector->findDuplicate(
                $profile,
                $volume->name,
                $localTempPath,
                fn (string $candidate): bool => $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $candidate, $volume->name),
            );
            if ($candidateDup !== null && $filesystem->fileExists($candidateDup)) {
                $duplicateOf = $candidateDup;
                $policy = $this->duplicateDetector->getPolicy($profile);
                if ($policy === 'reject') {
                    throw new UploadValidationException(sprintf('Identical file already exists at "/%s".', $duplicateOf), 'errors.duplicate_file_detected', ['%existing%' => '/' . $duplicateOf]);
                }
                if ($policy === 'reuse') {
                    $existingSize = $filesystem->fileSize($duplicateOf);

                    return [
                        'completed' => true,
                        'path' => $duplicateOf,
                        'name' => PathNormalizer::basenameUtf8($duplicateOf),
                        'size' => $existingSize,
                        'duplicateOf' => $duplicateOf,
                        'reused' => true,
                    ];
                }
            }
        }

        $commitUpload = function () use ($filesystem, $effectiveDir, $normalizedName, $profile, $volume, $size, $localTempPath): string {
            $targetPath = $this->resolveOverwritePolicy($filesystem, $effectiveDir, $normalizedName, $profile->overwritePolicy);
            // The final target must also be accessible: never overwrite hidden / excluded / trash files.
            $this->fileSecurityGuard->assertAccessiblePath($targetPath, $profile);

            $isOverwritingExisting = $profile->overwritePolicy === 'overwrite' && $filesystem->fileExists($targetPath);
            if ($isOverwritingExisting) {
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $targetPath, $volume->name);
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, $targetPath, $volume->name);
                $this->usageGuard?->assertAllowed($profile, $volume, $targetPath, false, 'overwrite');
            }
            $existingSize = $isOverwritingExisting ? $filesystem->fileSize($targetPath) : 0;
            $deltaBytes = max(0, $size - $existingSize);
            if ($deltaBytes > 0) {
                $this->quotaChecker?->assertHasQuota($profile, $volume->name, $deltaBytes);
            }

            $stream = fopen($localTempPath, 'rb');
            if ($stream === false) {
                throw new UploadValidationException('Unable to read uploaded file stream.', 'errors.upload_stream_unreadable');
            }

            try {
                $filesystem->writeStream($targetPath, $stream);
            } finally {
                fclose($stream);
            }
            $this->quotaChecker?->invalidateQuotaCache($profile, $volume->name);

            return $targetPath;
        };

        $targetPath = $this->quotaChecker !== null
            ? $this->quotaChecker->withQuotaLock($profile, $volume->name, $commitUpload, true)
            : $commitUpload();

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $targetPath, $profile->basePath);
        $this->eventDispatcher->dispatch(new FileUploadedEvent($profile, $volume, $targetPath, $canonical, $size, $mimeType));

        $response = [
            'completed' => true,
            'path' => $targetPath,
            'name' => PathNormalizer::basenameUtf8($targetPath),
            'size' => $size,
        ];
        if ($duplicateOf !== null) {
            $response['duplicateOf'] = $duplicateOf;
        }

        return $response;
    }

    private function assertUploadGranted(ProfileConfig $profile, string $cleanDir, ?string $volumeName): void
    {
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanDir, $volumeName);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $cleanDir, $volumeName);
    }

    private function assertSubfolderCreationAllowed(ProfileConfig $profile, string $cleanDir, string $volumeName): void
    {
        if ($cleanDir === '') {
            return;
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        if ($filesystem->directoryExists($cleanDir)) {
            return;
        }

        $immediateParent = PathNormalizer::dirnameUtf8($cleanDir);
        $existingAncestor = $immediateParent;
        while ($existingAncestor !== '' && !$filesystem->directoryExists($existingAncestor)) {
            $existingAncestor = PathNormalizer::dirnameUtf8($existingAncestor);
        }

        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::CREATE_FOLDER, $existingAncestor, $volumeName);
        if ($immediateParent !== $existingAncestor) {
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::CREATE_FOLDER, $immediateParent, $volumeName);
        }
    }

    private function detectMimeType(string $localTempPath, string $clientName): string
    {
        $mimeTypes = MimeTypes::getDefault();
        $guessed = $mimeTypes->guessMimeType($localTempPath);
        $ext = PathNormalizer::extensionUtf8($clientName);
        $resolved = ($guessed !== null && $guessed !== '')
            ? $guessed
            : ($mimeTypes->getMimeTypes($ext)[0] ?? 'application/octet-stream');

        return $this->canonicalizeSvgMime($localTempPath, $ext, $resolved);
    }

    private function canonicalizeSvgMime(string $localTempPath, string $ext, string $mime): string
    {
        $clean = strtolower(trim(explode(';', $mime, 2)[0]));
        if ($clean === 'image/svg') {
            return 'image/svg+xml';
        }
        if ($ext === 'svg' && in_array($clean, ['text/plain', 'text/xml', 'application/xml', 'application/octet-stream'], true)) {
            return 'image/svg+xml';
        }
        if (in_array($clean, ['text/plain', 'text/xml', 'application/xml'], true) && FileSecurityGuard::looksLikeSvgDocument($localTempPath)) {
            return 'image/svg+xml';
        }

        return $mime;
    }

    private function validateMimeType(ProfileConfig $profile, string $mimeType, string $clientName): void
    {
        $this->fileSecurityGuard->assertSafeFilename($clientName);
        $this->fileSecurityGuard->assertMimeMatchesExtension($clientName, $mimeType);

        $normalizedMime = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if ($this->fileSecurityGuard->matchesMimeList($normalizedMime, $profile->uploadDeny)) {
            throw new UploadValidationException(sprintf('MIME type "%s" is denied by upload_deny policy.', $normalizedMime), 'errors.upload_mime_denied', ['%mime%' => $normalizedMime]);
        }

        if ($profile->uploadAllow !== [] && !$this->fileSecurityGuard->matchesMimeList($normalizedMime, $profile->uploadAllow)) {
            throw new UploadValidationException(sprintf('MIME type "%s" is not in upload_allow list.', $normalizedMime), 'errors.upload_mime_not_allowed', ['%mime%' => $normalizedMime]);
        }
    }

    private function resolveOverwritePolicy(
        FilesystemOperator $filesystem,
        string $cleanDir,
        string $filename,
        string $policy,
    ): string {
        $candidate = $this->pathNormalizer->join($cleanDir, $filename);
        $isDirCollision = $filesystem->directoryExists($candidate);
        if (!$isDirCollision && !$filesystem->fileExists($candidate)) {
            return $candidate;
        }

        if ($policy === 'overwrite' && !$isDirCollision) {
            return $candidate;
        }

        if ($policy === 'reject') {
            throw new UploadValidationException(sprintf('File "%s" already exists (overwrite_policy: reject).', $filename), 'errors.upload_file_exists', ['%name%' => $filename]);
        }

        $ext = PathNormalizer::extensionUtf8($filename);
        $maxBaseBytes = max(1, min(200, 255 - ($ext !== '' ? strlen($ext) + 1 : 0) - 9));
        $base = UnicodeHelper::truncateStemBytes(PathNormalizer::stemUtf8($filename), $maxBaseBytes);
        if ($base === '') {
            $base = substr('file', 0, $maxBaseBytes);
        }
        $counter = 1;

        do {
            $nextName = $ext !== '' ? sprintf('%s-%d.%s', $base, $counter, $ext) : sprintf('%s-%d', $base, $counter);
            $candidate = $this->pathNormalizer->join($cleanDir, $nextName);
            ++$counter;
        } while ($counter <= 100 && ($filesystem->fileExists($candidate) || $filesystem->directoryExists($candidate)));

        if ($filesystem->fileExists($candidate) || $filesystem->directoryExists($candidate)) {
            $suffix = bin2hex(random_bytes(4));
            $fallbackName = $ext !== '' ? sprintf('%s-%s.%s', $base, $suffix, $ext) : sprintf('%s-%s', $base, $suffix);
            $candidate = $this->pathNormalizer->join($cleanDir, $fallbackName);
            if ($filesystem->fileExists($candidate) || $filesystem->directoryExists($candidate)) {
                throw new UploadValidationException(sprintf('File "%s" already exists.', $filename), 'errors.upload_file_exists', ['%name%' => $filename]);
            }
        }

        return $candidate;
    }

    private function resolveEffectiveUploadDir(ProfileConfig $profile, string $cleanDir): string
    {
        if ($profile->uploadSubfolder === null || trim($profile->uploadSubfolder) === '') {
            return $cleanDir;
        }

        $rawSub = $this->dynamicPathResolver !== null
            ? $this->dynamicPathResolver->resolve($profile->uploadSubfolder)
            : $profile->uploadSubfolder;
        $cleanSub = $this->pathNormalizer->normalize($rawSub);
        if ($cleanSub === '') {
            return $cleanDir;
        }

        $joined = $this->pathNormalizer->join($cleanDir, $cleanSub);
        $this->fileSecurityGuard->assertAccessiblePath($joined, $profile);

        return $joined;
    }

    private function validateImageConstraints(
        ProfileConfig $profile,
        string $localTempPath,
        string $mimeType,
        string $clientName,
    ): void {
        $constraints = $profile->imageConstraints;
        if ($constraints === []) {
            return;
        }

        $minW = is_int($constraints['min_width'] ?? null) && $constraints['min_width'] > 0 ? $constraints['min_width'] : null;
        $minH = is_int($constraints['min_height'] ?? null) && $constraints['min_height'] > 0 ? $constraints['min_height'] : null;
        $maxW = is_int($constraints['max_width'] ?? null) && $constraints['max_width'] > 0 ? $constraints['max_width'] : null;
        $maxH = is_int($constraints['max_height'] ?? null) && $constraints['max_height'] > 0 ? $constraints['max_height'] : null;
        $ratioStr = is_string($constraints['aspect_ratio'] ?? null) && trim($constraints['aspect_ratio']) !== ''
            ? trim($constraints['aspect_ratio'])
            : null;

        if ($minW === null && $minH === null && $maxW === null && $maxH === null && $ratioStr === null) {
            return;
        }

        if (!in_array(strtolower($mimeType), ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp'], true)) {
            return;
        }

        $info = @getimagesize($localTempPath);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            throw new UploadValidationException(sprintf('Unable to determine dimensions of image "%s".', $clientName), 'errors.edited_image_invalid');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $this->assertImageDimensions($clientName, $width, $height, $minW, $minH, $maxW, $maxH);
        if ($ratioStr !== null) {
            $tolerance = is_numeric($constraints['aspect_ratio_tolerance'] ?? null)
                ? max(0.0, min(0.5, (float) $constraints['aspect_ratio_tolerance']))
                : 0.05;
            $this->assertImageAspectRatio($clientName, $width, $height, $ratioStr, $tolerance);
        }
    }

    private function assertImageDimensions(
        string $clientName,
        int $width,
        int $height,
        ?int $minW,
        ?int $minH,
        ?int $maxW,
        ?int $maxH,
    ): void {
        if (($minW !== null && $width < $minW) || ($minH !== null && $height < $minH)) {
            $minWLabel = (string) ($minW ?? 0);
            $minHLabel = (string) ($minH ?? 0);
            throw new UploadValidationException(sprintf('Image "%s" (%dx%dpx) is smaller than the minimum required (%sx%spx).', $clientName, $width, $height, $minWLabel, $minHLabel), 'errors.image_too_small', ['%name%' => $clientName, '%width%' => $width, '%height%' => $height, '%minWidth%' => $minW ?? 0, '%minHeight%' => $minH ?? 0]);
        }

        if (($maxW !== null && $width > $maxW) || ($maxH !== null && $height > $maxH)) {
            $maxWLabel = $maxW !== null ? (string) $maxW : '∞';
            $maxHLabel = $maxH !== null ? (string) $maxH : '∞';
            throw new UploadValidationException(sprintf('Image "%s" (%dx%dpx) exceeds maximum allowed dimensions (%sx%spx).', $clientName, $width, $height, $maxWLabel, $maxHLabel), 'errors.image_too_large_dim', ['%name%' => $clientName, '%width%' => $width, '%height%' => $height, '%maxWidth%' => $maxW ?? '∞', '%maxHeight%' => $maxH ?? '∞']);
        }
    }

    private function assertImageAspectRatio(
        string $clientName,
        int $width,
        int $height,
        string $expectedRatioLabel,
        float $tolerance,
    ): void {
        $expected = $this->parseAspectRatio($expectedRatioLabel);
        if ($expected === null || $expected <= 0.0) {
            return;
        }

        $actual = $width / $height;
        $delta = abs($actual - $expected) / $expected;
        if ($delta > $tolerance) {
            throw new UploadValidationException(sprintf('Image "%s" (%dx%dpx) does not match required aspect ratio (%s).', $clientName, $width, $height, $expectedRatioLabel), 'errors.image_aspect_ratio', ['%name%' => $clientName, '%width%' => $width, '%height%' => $height, '%expected%' => $expectedRatioLabel]);
        }
    }

    private function parseAspectRatio(string $raw): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)$/', trim($raw), $m) === 1) {
            $w = (float) $m[1];
            $h = (float) $m[2];

            return ($w > 0.0 && $h > 0.0) ? ($w / $h) : null;
        }

        return null;
    }

    private function parseByteSize(string $size): int
    {
        return ByteSizeParser::parse($size);
    }
}
