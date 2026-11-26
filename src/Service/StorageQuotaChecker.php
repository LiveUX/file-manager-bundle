<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use SplMinHeap;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/**
 * Enforces optional per-profile storage quotas (e.g. "500M", "2G") across local & cloud volumes
 * and provides cached detailed storage breakdowns (by file category, top folders, largest files & trash).
 */
final class StorageQuotaChecker
{
    public const string QUOTA_CACHE_KEY = '__quota__';
    public const string QUOTA_DETAIL_CACHE_KEY = '__quota_detail__';
    private const int MAX_SCANNED_ITEMS = 25000;
    private const int TOP_LIST_LIMIT = 6;

    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'mp3', 'wav', 'ogg', 'flac', 'm4a'];
    private const array DOC_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'odt', 'ods', 'json', 'xml', 'yaml', 'yml'];
    private const array ARCHIVE_EXTENSIONS = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'];

    /**
     * Re-entrancy reference counters keyed by physical lock path so nested writes within the
     * same PHP process (e.g. an event listener reacting to FileUploadedEvent) never self-deadlock.
     *
     * @var array<string, int>
     */
    private static array $heldLocks = [];

    private readonly FileManagerAuthorizer $authorizer;

    public function __construct(
        private readonly VolumeRegistry $volumeRegistry,
        private readonly PathNormalizer $pathNormalizer,
        private readonly DirectoryCacheManager $cacheManager,
        private readonly FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        ?AuthorizationCheckerInterface $authChecker = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function assertHasQuota(ProfileConfig $profile, ?string $volumeName, int $incomingBytes): void
    {
        $maxBytes = $this->parseByteSize($profile->storageQuota);
        if ($maxBytes <= 0) {
            return;
        }

        $usage = $this->getUsedBytesState($profile, $volumeName);
        if ($usage['capped'] || ($usage['bytes'] + max(0, $incomingBytes)) > $maxBytes) {
            $limitLabel = $profile->storageQuota ?? $this->formatBytes($maxBytes);

            throw new UploadValidationException(sprintf('Storage quota exceeded (limit: %s).', $limitLabel), 'errors.storage_quota_exceeded', ['%limit%' => $limitLabel]);
        }
    }

    /**
     * Serializes quota-checked write operations per physical volume root when `storage_quota` is active
     * so parallel uploads, archive extractions, copies, or image edits cannot bypass the quota via TOCTOU.
     * Re-entrant within the same PHP process and fail-closed if the OS lock cannot be acquired.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withQuotaLock(ProfileConfig $profile, ?string $volumeName, callable $callback, bool $forceLock = false): mixed
    {
        if (!$forceLock && $this->parseByteSize($profile->storageQuota) <= 0) {
            return $callback();
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $lockScope = $this->resolvePhysicalLockScope($profile, $volume);
        $lockKey = 'quota_' . hash('sha256', $lockScope);

        if ((self::$heldLocks[$lockKey] ?? 0) > 0) {
            ++self::$heldLocks[$lockKey];
            try {
                return $callback();
            } finally {
                --self::$heldLocks[$lockKey];
            }
        }

        $lockPath = $this->resolvePrivateLockFilePath($lockKey);
        $lockFp = @fopen($lockPath, 'c');
        if ($lockFp === false || !@flock($lockFp, LOCK_EX)) {
            if (is_resource($lockFp)) {
                fclose($lockFp);
            }

            throw new FileManagerException('Unable to acquire storage quota lock.', 'errors.action_failed');
        }

        self::$heldLocks[$lockKey] = 1;
        try {
            return $callback();
        } finally {
            unset(self::$heldLocks[$lockKey]);
            @flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    private function resolvePhysicalLockScope(ProfileConfig $profile, VolumeConfig $volume): string
    {
        if ($volume->isLocalFilesystem() && $volume->path !== null) {
            $real = @realpath($volume->path);
            $resolved = is_string($real) ? $real : $volume->path;
            $normalized = rtrim(str_replace('\\', '/', $resolved), '/');
            if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin') {
                $normalized = mb_strtolower($normalized, 'UTF-8');
            }

            return 'local|' . $normalized;
        }

        if (strtolower($volume->driver) === 'awss3') {
            $endpoint = PathNormalizer::normalizeS3Endpoint((string) ($volume->driverOptions['endpoint'] ?? ''));
            $bucket = trim((string) ($volume->driverOptions['bucket'] ?? ''));

            return 's3|' . $endpoint . '|' . $bucket;
        }

        return $this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath);
    }

    private function resolvePrivateLockFilePath(string $lockKey): string
    {
        $ownerScope = function_exists('posix_geteuid')
            ? (string) posix_geteuid()
            : substr(md5((string) get_current_user()), 0, 12);
        $lockDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'liveux_fm_locks_' . $ownerScope;
        if (is_link($lockDir)) {
            throw new FileManagerException('Lock directory is an unsafe symbolic link.', 'errors.action_failed');
        }
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new FileManagerException('Unable to create storage lock directory.', 'errors.action_failed');
        }

        return $lockDir . DIRECTORY_SEPARATOR . $lockKey . '.lock';
    }

    /**
     * Immediately invalidates cached quota summaries for the target volume so subsequent locked writes
     * observe newly committed bytes even before asynchronous/deferred listeners execute.
     */
    public function invalidateQuotaCache(ProfileConfig $profile, ?string $volumeName = null): void
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->cacheManager->invalidateDirectory(
            $this->pathNormalizer->buildCanonicalKey($volume, self::QUOTA_CACHE_KEY, $profile->basePath),
        );
        $this->cacheManager->invalidateDirectory(
            $this->pathNormalizer->buildCanonicalKey($volume, self::QUOTA_DETAIL_CACHE_KEY, $profile->basePath),
        );
    }

    /**
     * @return array{
     *     enabled: bool,
     *     usedBytes: int,
     *     maxBytes: int,
     *     remainingBytes: int,
     *     percent: float,
     *     usedFormatted: string,
     *     maxFormatted: string,
     *     remainingFormatted: string
     * }
     */
    public function getQuotaSummary(ProfileConfig $profile, ?string $volumeName = null): array
    {
        $maxBytes = $this->parseByteSize($profile->storageQuota);
        if ($maxBytes <= 0) {
            return [
                'enabled' => false,
                'usedBytes' => 0,
                'maxBytes' => 0,
                'remainingBytes' => 0,
                'percent' => 0.0,
                'usedFormatted' => '0 B',
                'maxFormatted' => '',
                'remainingFormatted' => '',
            ];
        }

        $usedBytes = $this->getUsedBytes($profile, $volumeName);

        return $this->buildSummaryFromUsedBytes($profile, $usedBytes);
    }

    /**
     * Builds a quota summary array from an already-computed byte total (avoids double volume scans in CLI commands).
     *
     * @return array{
     *     enabled: bool,
     *     usedBytes: int,
     *     maxBytes: int,
     *     remainingBytes: int,
     *     percent: float,
     *     usedFormatted: string,
     *     maxFormatted: string,
     *     remainingFormatted: string
     * }
     */
    public function buildSummaryFromUsedBytes(ProfileConfig $profile, int $usedBytes): array
    {
        $maxBytes = $this->parseByteSize($profile->storageQuota);
        if ($maxBytes <= 0) {
            return [
                'enabled' => false,
                'usedBytes' => 0,
                'maxBytes' => 0,
                'remainingBytes' => 0,
                'percent' => 0.0,
                'usedFormatted' => '0 B',
                'maxFormatted' => '',
                'remainingFormatted' => '',
            ];
        }

        $safeUsed = max(0, $usedBytes);
        $remainingBytes = max(0, $maxBytes - $safeUsed);
        $percent = min(100.0, round(($safeUsed / $maxBytes) * 100, 1));

        return [
            'enabled' => true,
            'usedBytes' => $safeUsed,
            'maxBytes' => $maxBytes,
            'remainingBytes' => $remainingBytes,
            'percent' => $percent,
            'usedFormatted' => $this->formatBytes($safeUsed),
            'maxFormatted' => $profile->storageQuota ?? $this->formatBytes($maxBytes),
            'remainingFormatted' => $this->formatBytes($remainingBytes),
        ];
    }

    /**
     * Returns a detailed, cached breakdown of storage usage by category, top folders, largest files, and Trash.
     *
     * @return array<string, mixed>
     */
    public function getDetailedBreakdown(ProfileConfig $profile, ?string $volumeName = null): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, self::QUOTA_DETAIL_CACHE_KEY, $profile->basePath);

        $volumeScope = $this->cacheManager->resolveVolumeScopeForRoot($this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath));
        $cached = $this->cacheManager->remember(
            $profile,
            $canonicalKey,
            fn (): array => $this->scanDetailedVolumeStats($profile, $volume->name),
            $volume->cacheTtl,
            $volumeScope,
        );

        $stats = $cached['data'];
        $maxBytes = $this->parseByteSize($profile->storageQuota);
        $usedBytes = (int) ($stats['totalBytes'] ?? 0);
        $remainingBytes = $maxBytes > 0 ? max(0, $maxBytes - $usedBytes) : 0;
        $percent = $maxBytes > 0 ? min(100.0, round(($usedBytes / $maxBytes) * 100, 1)) : 0.0;
        $baseDenominator = $maxBytes > 0 ? $maxBytes : max(1, $usedBytes);

        $categories = [];
        foreach (['image', 'video', 'doc', 'archive', 'other'] as $catKey) {
            $catBytes = (int) ($stats['categories'][$catKey]['bytes'] ?? 0);
            $catCount = (int) ($stats['categories'][$catKey]['count'] ?? 0);
            $categories[] = [
                'key' => $catKey,
                'bytes' => $catBytes,
                'formatted' => $this->formatBytes($catBytes),
                'count' => $catCount,
                'percent' => $catBytes > 0 ? round(($catBytes / $baseDenominator) * 100, 1) : 0.0,
            ];
        }

        $trashBytes = (int) ($stats['trashBytes'] ?? 0);

        $visibleFolders = array_slice(
            array_values(array_filter(
                $stats['folders'] ?? [],
                fn (array $f): bool => $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, (string) ($f['path'] ?? ''), $volume->name),
            )),
            0,
            self::TOP_LIST_LIMIT,
        );
        $visibleFiles = array_slice(
            array_values(array_filter(
                $stats['files'] ?? [],
                fn (array $f): bool => $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, (string) ($f['path'] ?? ''), $volume->name),
            )),
            0,
            self::TOP_LIST_LIMIT,
        );

        return [
            'volume' => $volume->name,
            'volumeLabel' => $volume->label,
            'summary' => [
                'enabled' => $maxBytes > 0,
                'usedBytes' => $usedBytes,
                'maxBytes' => $maxBytes,
                'remainingBytes' => $remainingBytes,
                'percent' => $percent,
                'usedFormatted' => $this->formatBytes($usedBytes),
                'maxFormatted' => $maxBytes > 0 ? ($profile->storageQuota ?? $this->formatBytes($maxBytes)) : '',
                'remainingFormatted' => $maxBytes > 0 ? $this->formatBytes($remainingBytes) : '',
            ],
            'counts' => [
                'files' => (int) ($stats['fileCount'] ?? 0),
                'folders' => (int) ($stats['folderCount'] ?? 0),
                'trashFiles' => (int) ($stats['trashFiles'] ?? 0),
                'trashBytes' => $trashBytes,
                'trashFormatted' => $this->formatBytes($trashBytes),
                'trashPercent' => $trashBytes > 0 ? round(($trashBytes / $baseDenominator) * 100, 1) : 0.0,
            ],
            'categories' => $categories,
            'topFolders' => $this->formatTopFolders($visibleFolders),
            'largestFiles' => $this->formatLargestFiles($visibleFiles),
            'capped' => ($stats['capped'] ?? false) === true,
        ];
    }

    public function getUsedBytes(ProfileConfig $profile, ?string $volumeName = null): int
    {
        return $this->getUsedBytesState($profile, $volumeName)['bytes'];
    }

    /**
     * @return array{bytes: int, capped: bool}
     */
    private function getUsedBytesState(ProfileConfig $profile, ?string $volumeName = null): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, self::QUOTA_CACHE_KEY, $profile->basePath);

        $volumeScope = $this->cacheManager->resolveVolumeScopeForRoot($this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath));
        $cached = $this->cacheManager->remember(
            $profile,
            $canonicalKey,
            fn (): array => $this->scanVolumeBytesState($profile, $volume->name),
            $volume->cacheTtl,
            $volumeScope,
        );

        return [
            'bytes' => (int) ($cached['data']['bytes'] ?? 0),
            'capped' => ($cached['data']['capped'] ?? false) === true,
        ];
    }

    /**
     * @return array{bytes: int, capped: bool}
     */
    private function scanVolumeBytesState(ProfileConfig $profile, string $volumeName): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        $trashDir = $this->resolveTrashDirectory($profile);
        $total = 0;
        $countedFiles = 0;
        $iterations = 0;
        $maxIterations = self::MAX_SCANNED_ITEMS * 4;
        $capped = false;

        foreach ($filesystem->listContents('', true) as $attr) {
            ++$iterations;
            if ($iterations > $maxIterations) {
                $capped = true;
                break;
            }
            if (!$attr instanceof FileAttributes) {
                continue;
            }
            try {
                $rawPath = $this->pathNormalizer->normalize($attr->path());
            } catch (\LiveUX\FileManagerBundle\Exception\InvalidPathException) {
                continue;
            }
            if ($rawPath === '') {
                continue;
            }
            $lowerPath = mb_strtolower($rawPath, 'UTF-8');
            $inTrash = $trashDir !== '' && ($lowerPath === $trashDir || str_starts_with($lowerPath, $trashDir . '/'));
            if (!$inTrash && $this->isHiddenOrExcludedPath($rawPath, $profile)) {
                continue;
            }
            ++$countedFiles;
            if ($countedFiles > self::MAX_SCANNED_ITEMS) {
                $capped = true;
                break;
            }
            $total += (int) ($attr->fileSize() ?? 0);
        }

        return ['bytes' => $total, 'capped' => $capped];
    }

    /**
     * @return array<string, mixed>
     */
    private function scanDetailedVolumeStats(ProfileConfig $profile, string $volumeName): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        $trashDir = $this->resolveTrashDirectory($profile);

        $totalBytes = 0;
        $fileCount = 0;
        $seenFolders = [];
        $trashBytes = 0;
        $trashFiles = 0;
        $billableScanned = 0;
        $iterations = 0;
        $maxIterations = self::MAX_SCANNED_ITEMS * 4;
        $capped = false;

        $categories = [
            'image' => ['bytes' => 0, 'count' => 0],
            'video' => ['bytes' => 0, 'count' => 0],
            'doc' => ['bytes' => 0, 'count' => 0],
            'archive' => ['bytes' => 0, 'count' => 0],
            'other' => ['bytes' => 0, 'count' => 0],
        ];
        $folderTotals = [];
        $topFilesLimit = self::TOP_LIST_LIMIT * 16;
        /** @var SplMinHeap<array{bytes: int, entry: array{name: string, path: string, folder: string, bytes: int, category: string}}> $topFilesHeap */
        $topFilesHeap = new class extends SplMinHeap {
            protected function compare(mixed $value1, mixed $value2): int
            {
                return $value2['bytes'] <=> $value1['bytes'];
            }
        };

        foreach ($filesystem->listContents('', true) as $attr) {
            ++$iterations;
            if ($iterations > $maxIterations) {
                $capped = true;
                break;
            }

            try {
                $rawPath = $this->pathNormalizer->normalize($attr->path());
            } catch (\LiveUX\FileManagerBundle\Exception\InvalidPathException) {
                continue;
            }
            if ($rawPath === '') {
                continue;
            }

            $topSegment = explode('/', $rawPath)[0];
            $lowerPath = mb_strtolower($rawPath, 'UTF-8');
            $inTrash = $trashDir !== '' && ($lowerPath === $trashDir || str_starts_with($lowerPath, $trashDir . '/'));

            if ($attr instanceof DirectoryAttributes) {
                if (!$inTrash && !$this->isHiddenOrExcludedPath($rawPath, $profile)) {
                    $seenFolders[$rawPath] = true;
                }
                continue;
            }

            if (!$attr instanceof FileAttributes) {
                continue;
            }

            if (!$inTrash && $this->isHiddenOrExcludedPath($rawPath, $profile)) {
                continue;
            }

            ++$billableScanned;
            if ($billableScanned > self::MAX_SCANNED_ITEMS) {
                $capped = true;
                break;
            }

            $size = (int) ($attr->fileSize() ?? 0);

            if ($inTrash) {
                $totalBytes += $size;
                $trashBytes += $size;
                if (PathNormalizer::basenameUtf8($rawPath) !== '.meta.json') {
                    ++$trashFiles;
                }
                continue;
            }

            // Track virtual ancestor folders so S3/R2/MinIO volumes omitting DirectoryAttributes report accurate folder counts.
            $ancestor = PathNormalizer::dirnameUtf8($rawPath);
            while ($ancestor !== '' && !isset($seenFolders[$ancestor])) {
                $seenFolders[$ancestor] = true;
                $nextAncestor = PathNormalizer::dirnameUtf8($ancestor);
                if ($nextAncestor === $ancestor) {
                    break;
                }
                $ancestor = $nextAncestor;
            }

            $totalBytes += $size;
            ++$fileCount;
            $name = PathNormalizer::basenameUtf8($rawPath);
            $ext = PathNormalizer::extensionUtf8($name);
            $cat = $this->classifyCategory($ext);
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['bytes' => 0, 'count' => 0];
            }
            $categories[$cat]['bytes'] += $size;
            ++$categories[$cat]['count'];

            $bucket = str_contains($rawPath, '/') ? $topSegment : '';
            if (!isset($folderTotals[$bucket])) {
                $folderTotals[$bucket] = ['path' => $bucket, 'bytes' => 0, 'fileCount' => 0];
            }
            $folderTotals[$bucket]['bytes'] += $size;
            ++$folderTotals[$bucket]['fileCount'];

            $entry = [
                'name' => $name,
                'path' => $rawPath,
                'folder' => PathNormalizer::dirnameUtf8($rawPath),
                'bytes' => $size,
                'category' => $cat,
            ];
            if ($topFilesHeap->count() < $topFilesLimit) {
                $topFilesHeap->insert(['bytes' => $size, 'entry' => $entry]);
            } elseif ($size > $topFilesHeap->top()['bytes']) {
                $topFilesHeap->extract();
                $topFilesHeap->insert(['bytes' => $size, 'entry' => $entry]);
            }
        }

        $topFiles = [];
        while (!$topFilesHeap->isEmpty()) {
            $topFiles[] = $topFilesHeap->extract()['entry'];
        }
        usort($folderTotals, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        usort($topFiles, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return [
            'totalBytes' => $totalBytes,
            'fileCount' => $fileCount,
            'folderCount' => count($seenFolders),
            'trashBytes' => $trashBytes,
            'trashFiles' => $trashFiles,
            'categories' => $categories,
            'folders' => array_slice($folderTotals, 0, $topFilesLimit),
            'files' => $topFiles,
            'capped' => $capped,
        ];
    }

    private function resolveTrashDirectory(ProfileConfig $profile): string
    {
        $raw = is_string($profile->trash['directory'] ?? null) ? $profile->trash['directory'] : '.trash';
        try {
            $normalized = $this->pathNormalizer->normalize($raw);

            return mb_strtolower($normalized, 'UTF-8');
        } catch (Throwable) {
            return '.trash';
        }
    }

    private function isHiddenOrExcludedPath(string $rawPath, ProfileConfig $profile): bool
    {
        return $this->fileSecurityGuard->isPathIgnored($rawPath, $profile);
    }

    private function classifyCategory(string $extension): string
    {
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }
        if (in_array($extension, self::DOC_EXTENSIONS, true)) {
            return 'doc';
        }
        if (in_array($extension, self::ARCHIVE_EXTENSIONS, true)) {
            return 'archive';
        }

        return 'other';
    }

    /**
     * @param list<array{path: string, bytes: int, fileCount: int}> $folders
     *
     * @return list<array{path: string, bytes: int, formatted: string, fileCount: int}>
     */
    private function formatTopFolders(array $folders): array
    {
        $result = [];
        foreach ($folders as $row) {
            $bytes = $row['bytes'];
            $result[] = [
                'path' => $row['path'],
                'bytes' => $bytes,
                'formatted' => $this->formatBytes($bytes),
                'fileCount' => $row['fileCount'],
            ];
        }

        return $result;
    }

    /**
     * @param list<array{name: string, path: string, folder: string, bytes: int, category: string}> $files
     *
     * @return list<array{name: string, path: string, folder: string, bytes: int, formatted: string, category: string}>
     */
    private function formatLargestFiles(array $files): array
    {
        $result = [];
        foreach ($files as $row) {
            $bytes = $row['bytes'];
            $result[] = [
                'name' => $row['name'],
                'path' => $row['path'],
                'folder' => $row['folder'],
                'bytes' => $bytes,
                'formatted' => $this->formatBytes($bytes),
                'category' => $row['category'],
            ];
        }

        return $result;
    }

    private function parseByteSize(?string $raw): int
    {
        return ByteSizeParser::parse($raw);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
    }
}
