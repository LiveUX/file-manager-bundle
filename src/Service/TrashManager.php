<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\Event\TrashRestoredEvent;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Model\TrashEntry;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class TrashManager
{
    private const int MAX_TRASH_MOVE_ITEMS = 5000;

    /**
     * Re-entrant per-process lock reference counters for physical volume trash operations.
     *
     * @var array<string, int>
     */
    private static array $heldTrashLocks = [];

    private int $bulkBatchDepth = 0;

    /**
     * Batch-scoped in-memory trash index cache used during bulk delete operations to prevent
     * O(B * T) sequential S3 GetObject storms when trash.max_size FIFO planning runs per item.
     *
     * @var array<string, list<TrashEntry>>
     */
    private array $bulkBatchTrashCache = [];

    private readonly FileManagerAuthorizer $authorizer;

    public function __construct(
        private readonly VolumeRegistry $volumeRegistry,
        private readonly PathNormalizer $pathNormalizer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private readonly ?string $localMetaFallbackFile = null,
        ?AuthorizationCheckerInterface $authChecker = null,
        ?StorageQuotaChecker $quotaChecker = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
        unset($quotaChecker);
    }

    /**
     * Executes a bulk delete/trash batch with a shared in-memory trash index snapshot so
     * FIFO eviction planning reads `.meta.json` sidecars ONCE per volume instead of O(B * T) times.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withBulkTrashBatch(callable $callback): mixed
    {
        ++$this->bulkBatchDepth;
        try {
            return $callback();
        } finally {
            --$this->bulkBatchDepth;
            if ($this->bulkBatchDepth <= 0) {
                $this->bulkBatchDepth = 0;
                $this->bulkBatchTrashCache = [];
            }
        }
    }

    public function isEnabled(ProfileConfig $profile): bool
    {
        return ($profile->trash['enabled'] ?? true) === true;
    }

    public function moveToTrash(
        ProfileConfig $profile,
        string $path,
        ?string $volumeName = null,
        ?string $deletedBy = null,
        ?int $precomputedSize = null,
    ): TrashEntry {
        if (!$this->isEnabled($profile)) {
            throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $this->fileSecurityGuard->assertNotTrashAncestor($cleanPath, $profile);

        if ($cleanPath === '') {
            throw new FileManagerException('Cannot move root directory to trash.', 'errors.cannot_trash_root');
        }

        $pendingPurgedEvents = [];
        $entry = $this->withTrashLock($profile, $volume, function () use ($profile, $volume, $filesystem, $cleanPath, $deletedBy, $precomputedSize, &$pendingPurgedEvents): TrashEntry {
            $isFolder = $filesystem->directoryExists($cleanPath);
            if (!$isFolder && !$filesystem->fileExists($cleanPath)) {
                throw new FileManagerException(
                    sprintf('Item "%s" does not exist.', $cleanPath),
                    'errors.item_not_found',
                    ['%path%' => $cleanPath],
                );
            }

            $size = $precomputedSize !== null
                ? max(0, $precomputedSize)
                : ($isFolder ? $this->calculateFolderSize($filesystem, $cleanPath) : $filesystem->fileSize($cleanPath));
            $idsToEvict = $this->planFifoTrashEvictions($profile, $volume->name, $size);
            $trashId = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
            $trashDir = $this->getTrashDir($profile);
            $entryDir = $trashDir . '/' . $trashId;
            $itemName = PathNormalizer::basenameUtf8($cleanPath);
            $payloadPath = $entryDir . '/' . $itemName;
            $metaPath = $entryDir . '/.meta.json';

            try {
                if ($volume->isLocalFilesystem()) {
                    $filesystem->createDirectory($entryDir);
                }
                $this->moveItem($filesystem, $volume, $cleanPath, $payloadPath, $isFolder);
            } catch (\Throwable $e) {
                try {
                    $filesystem->deleteDirectory($entryDir);
                } catch (\Throwable) {
                    // Best-effort cleanup of partially copied non-local folder before source removal.
                }
                throw $e;
            }

            $entry = new TrashEntry(
                id: $trashId,
                name: $itemName,
                originalPath: $cleanPath,
                volume: $volume->name,
                isFolder: $isFolder,
                size: $size,
                deletedAt: time(),
                deletedBy: $deletedBy,
            );

            $this->persistMetaEntry($filesystem, $volume, $metaPath, $entry, $profile->basePath);
            $this->recordBatchTrashAddition($profile, $volume, $entry);

            foreach ($idsToEvict as $evictId) {
                $evictedEvent = $this->deletePermanentlyLocked($profile, $volume, $filesystem, $evictId, false);
                if ($evictedEvent !== null) {
                    $pendingPurgedEvents[] = $evictedEvent;
                }
            }

            return $entry;
        });

        foreach ($pendingPurgedEvents as $purgedEvent) {
            $this->eventDispatcher->dispatch($purgedEvent);
        }

        return $entry;
    }

    /**
     * @return list<TrashEntry>
     */
    public function listTrash(ProfileConfig $profile, ?string $volumeName = null, bool $enforceVoter = false): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $trashDir = $this->getTrashDir($profile);

        if (!$filesystem->directoryExists($trashDir)) {
            return [];
        }

        $fallbackMap = $this->readLocalFallbackMap();
        $entries = [];
        foreach ($filesystem->listContents($trashDir, false) as $attr) {
            if (!$attr instanceof DirectoryAttributes) {
                continue;
            }
            $entryDir = $attr->path();
            $trashId = PathNormalizer::basenameUtf8($entryDir);
            $entry = $this->resolveEntry($filesystem, $volume, $entryDir, $trashId, $fallbackMap, $profile->basePath);
            if ($entry === null) {
                continue;
            }
            if ($enforceVoter && !$this->canViewTrashEntry($profile, $volume->name, $entry->originalPath)) {
                continue;
            }
            $entries[] = $entry;
        }

        usort($entries, static fn (TrashEntry $a, TrashEntry $b): int => $b->deletedAt <=> $a->deletedAt);

        return $entries;
    }

    public function findEntry(ProfileConfig $profile, string $trashId, ?string $volumeName = null): ?TrashEntry
    {
        if (!$this->isEnabled($profile)) {
            return null;
        }

        $safeId = $this->validateTrashId($trashId);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $entryDir = $this->getTrashDir($profile) . '/' . $safeId;
        if (!$filesystem->directoryExists($entryDir)) {
            return null;
        }

        return $this->resolveEntry($filesystem, $volume, $entryDir, $safeId, null, $profile->basePath);
    }

    public function restore(ProfileConfig $profile, string $trashId, ?string $volumeName = null, bool $enforceVoter = false): TrashEntry
    {
        if (!$this->isEnabled($profile)) {
            throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
        }

        $safeId = $this->validateTrashId($trashId);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $entryDir = $this->getTrashDir($profile) . '/' . $safeId;

        $restoredData = $this->withTrashLock($profile, $volume, function () use ($profile, $volume, $filesystem, $entryDir, $safeId, $enforceVoter): array {
            $entry = $this->resolveEntry($filesystem, $volume, $entryDir, $safeId, null, $profile->basePath);

            if ($entry === null) {
                throw new FileManagerException(
                    sprintf('Trash entry "%s" was not found.', $safeId),
                    'errors.trash_entry_not_found',
                    ['%id%' => $safeId],
                );
            }

            $normalizedOrig = $this->pathNormalizer->normalize($entry->originalPath);
            $this->fileSecurityGuard->assertAccessiblePath($normalizedOrig, $profile);
            if ($enforceVoter) {
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $normalizedOrig, $volume->name);
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_RESTORE, $normalizedOrig, $volume->name);
            }
            if (!$entry->isFolder) {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($normalizedOrig));
            } else {
                $this->assertSafeRestoredFolderName(PathNormalizer::basenameUtf8($normalizedOrig));
            }

            $safePayloadName = $this->pathNormalizer->normalize(PathNormalizer::basenameUtf8($entry->name));
            if ($safePayloadName === '' || str_contains($safePayloadName, '/')) {
                throw new FileManagerException('Trash payload name is invalid.', 'errors.invalid_trash_id');
            }
            $payloadPath = $entryDir . '/' . $safePayloadName;
            $payloadExists = $entry->isFolder
                ? $filesystem->directoryExists($payloadPath)
                : $filesystem->fileExists($payloadPath);
            if (!$payloadExists) {
                throw new FileManagerException(
                    sprintf('Trash entry "%s" was not found.', $safeId),
                    'errors.trash_entry_not_found',
                    ['%id%' => $safeId],
                );
            }

            $targetPath = $this->resolveUniqueRestorePath($filesystem, $normalizedOrig, $profile, $entry->isFolder);
            if ($targetPath !== $normalizedOrig) {
                $this->fileSecurityGuard->assertAccessiblePath($targetPath, $profile);
                if ($enforceVoter) {
                    $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $targetPath, $volume->name);
                    $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_RESTORE, $targetPath, $volume->name);
                }
            }
            if ($entry->isFolder) {
                $this->validateRestoredFolderContents($filesystem, $payloadPath, $targetPath, $profile, $volume->name, $enforceVoter);
            }
            $parentDir = PathNormalizer::dirnameUtf8($targetPath);
            if ($parentDir !== '') {
                $acc = '';
                foreach (explode('/', $parentDir) as $seg) {
                    if ($seg === '') {
                        continue;
                    }
                    $acc = $acc === '' ? $seg : $acc . '/' . $seg;
                    if ($filesystem->fileExists($acc)) {
                        throw new FileManagerException(
                            sprintf('Target "%s" already exists in destination.', PathNormalizer::basenameUtf8($acc)),
                            'errors.target_exists',
                            ['%name%' => PathNormalizer::basenameUtf8($acc)],
                        );
                    }
                }
            }

            if ($parentDir !== '' && $volume->isLocalFilesystem() && !$filesystem->directoryExists($parentDir)) {
                $filesystem->createDirectory($parentDir);
            }

            $this->moveItem($filesystem, $volume, $payloadPath, $targetPath, $entry->isFolder);
            $filesystem->deleteDirectory($entryDir);
            $this->deleteLocalFallbackEntry($volume, $safeId, $profile->basePath);
            $this->recordBatchTrashRemoval($profile, $volume, $safeId);

            return ['entry' => $entry, 'targetPath' => $targetPath];
        });

        /** @var TrashEntry $entry */
        $entry = $restoredData['entry'];
        /** @var string $targetPath */
        $targetPath = $restoredData['targetPath'];

        $trashMetaCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '__trash_meta__/' . $safeId, $profile->basePath);
        $canonicalPath = $this->pathNormalizer->buildCanonicalKey($volume, $targetPath, $profile->basePath);
        $this->eventDispatcher->dispatch(new TrashRestoredEvent($profile, $volume, $targetPath, $canonicalPath, $trashMetaCanonical, $entry->isFolder));

        return new TrashEntry(
            id: $entry->id,
            name: PathNormalizer::basenameUtf8($targetPath),
            originalPath: $targetPath,
            volume: $entry->volume,
            isFolder: $entry->isFolder,
            size: $entry->size,
            deletedAt: $entry->deletedAt,
            deletedBy: $entry->deletedBy,
        );
    }

    public function deletePermanently(ProfileConfig $profile, string $trashId, ?string $volumeName = null, bool $enforceVoter = false): void
    {
        if (!$this->isEnabled($profile)) {
            throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
        }

        $safeId = $this->validateTrashId($trashId);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);

        $event = $this->withTrashLock(
            $profile,
            $volume,
            fn (): ?TrashPurgedEvent => $this->deletePermanentlyLocked($profile, $volume, $filesystem, $safeId, $enforceVoter),
        );

        if ($event !== null) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    private function deletePermanentlyLocked(
        ProfileConfig $profile,
        VolumeConfig $volume,
        FilesystemOperator $filesystem,
        string $safeId,
        bool $enforceVoter,
    ): ?TrashPurgedEvent {
        $entryDir = $this->getTrashDir($profile) . '/' . $safeId;

        if (!$filesystem->directoryExists($entryDir)) {
            return null;
        }

        $entry = $this->resolveEntry($filesystem, $volume, $entryDir, $safeId, null, $profile->basePath);
        $legacyOrigCanonical = null;
        if ($entry !== null && $entry->originalPath !== '') {
            if ($enforceVoter) {
                $cleanOrig = $this->pathNormalizer->normalize($entry->originalPath);
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanOrig, $volume->name);
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_PURGE, $cleanOrig, $volume->name);
                if ($entry->isFolder) {
                    $safePayloadName = $this->pathNormalizer->normalize(PathNormalizer::basenameUtf8($entry->name));
                    if ($safePayloadName === '' || str_contains($safePayloadName, '/')) {
                        throw new FileManagerException('Trash entry metadata is invalid.', 'errors.invalid_trash_id');
                    }
                    $this->validateTrashedFolderContentsForPurge($filesystem, $entryDir . '/' . $safePayloadName, $cleanOrig, $profile, $volume->name);
                }
                if ($cleanOrig !== '' && !$filesystem->fileExists($cleanOrig) && !$filesystem->directoryExists($cleanOrig)) {
                    $legacyOrigCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanOrig, $profile->basePath);
                }
            } else {
                try {
                    $cleanOrig = $this->pathNormalizer->normalize($entry->originalPath);
                    if ($cleanOrig !== '' && !$filesystem->fileExists($cleanOrig) && !$filesystem->directoryExists($cleanOrig)) {
                        $legacyOrigCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanOrig, $profile->basePath);
                    }
                } catch (\Throwable) {
                    $legacyOrigCanonical = null;
                }
            }
        } elseif ($enforceVoter) {
            $this->authorizer->assertGranted($profile, FileManagerPermissions::VIEW, '', $volume->name);
            $this->authorizer->assertGranted($profile, FileManagerPermissions::TRASH_PURGE, '', $volume->name);
        }

        $filesystem->deleteDirectory($entryDir);
        $this->deleteLocalFallbackEntry($volume, $safeId, $profile->basePath);
        $this->recordBatchTrashRemoval($profile, $volume, $safeId);

        $trashMetaCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '__trash_meta__/' . $safeId, $profile->basePath);
        $canonicalPath = $this->pathNormalizer->buildCanonicalKey($volume, $entryDir, $profile->basePath);

        return new TrashPurgedEvent($profile, $volume, $entryDir, $canonicalPath, 1, $legacyOrigCanonical, $trashMetaCanonical);
    }

    private function validateTrashId(string $trashId): string
    {
        $trimmed = trim($trashId);
        if (preg_match('/^[a-zA-Z0-9_-]{4,64}$/', $trimmed) !== 1) {
            throw new FileManagerException('Invalid trash entry identifier.', 'errors.invalid_trash_id');
        }

        return $trimmed;
    }

    public function purgeExpired(ProfileConfig $profile, ?string $volumeName = null, ?int $overrideDays = null, bool $enforceVoter = false): int
    {
        if (!$this->isEnabled($profile)) {
            return 0;
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $days = $overrideDays ?? (int) ($profile->trash['retention_days'] ?? 30);
        $purgeAll = $days <= 0;
        $cutoff = time() - ($days * 86400);
        $purged = 0;

        foreach ($this->listTrash($profile, $volume->name, $enforceVoter) as $entry) {
            if ($purgeAll || $entry->deletedAt <= $cutoff) {
                if ($enforceVoter && !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_PURGE, $entry->originalPath, $volume->name)) {
                    continue;
                }
                try {
                    $this->deletePermanently($profile, $entry->id, $volume->name, $enforceVoter);
                    ++$purged;
                } catch (AccessDeniedException) {
                    if (!$enforceVoter) {
                        throw new AccessDeniedException('Access denied.', 'errors.access_denied');
                    }
                }
            }
        }

        return $purged;
    }

    private function canViewTrashEntry(ProfileConfig $profile, string $volumeName, string $originalPath): bool
    {
        try {
            $cleanOrig = $this->pathNormalizer->normalize($originalPath);
        } catch (\Throwable) {
            return false;
        }

        if (!$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanOrig, $volumeName)) {
            return false;
        }

        return $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, $cleanOrig, $volumeName)
            || $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_RESTORE, $cleanOrig, $volumeName)
            || $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_PURGE, $cleanOrig, $volumeName);
    }

    private function getTrashDir(ProfileConfig $profile): string
    {
        $dir = is_string($profile->trash['directory'] ?? null) ? $profile->trash['directory'] : '.trash';
        $normalized = $this->pathNormalizer->normalize($dir);

        return $normalized !== '' ? $normalized : '.trash';
    }

    private function calculateFolderSize(FilesystemOperator $filesystem, string $folderPath): int
    {
        $bytes = 0;
        $count = 0;
        foreach ($filesystem->listContents($folderPath, true) as $attr) {
            if (++$count > self::MAX_TRASH_MOVE_ITEMS) {
                break;
            }
            if ($attr instanceof FileAttributes) {
                $bytes += (int) ($attr->fileSize() ?? 0);
            }
        }

        return $bytes;
    }

    /**
     * Validates `trash.max_size` BEFORE touching storage, and returns the exact list of oldest trash IDs
     * to evict ONLY AFTER `moveItem()` succeeds (preventing permanent data loss if the move fails, and
     * requiring `TRASH_PURGE` authorization before evicting existing trash items).
     *
     * @return list<string>
     */
    private function planFifoTrashEvictions(ProfileConfig $profile, string $volumeName, int $incomingBytes): array
    {
        $rawMaxSize = is_string($profile->trash['max_size'] ?? null) ? trim($profile->trash['max_size']) : '';
        $maxBytes = ByteSizeParser::parse($rawMaxSize !== '' ? $rawMaxSize : null);
        if ($maxBytes <= 0) {
            return [];
        }

        // An item larger than the whole quota can never fit: refuse before purging any unrelated entries.
        if ($incomingBytes > $maxBytes) {
            throw new FileManagerException(
                sprintf('Item exceeds the trash size limit (%s). Use permanent delete instead.', $rawMaxSize),
                'errors.trash_item_too_large',
                ['%max%' => $rawMaxSize],
            );
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $entries = $this->listTrashForPlanning($profile, $volume);
        $currentBytes = 0;
        foreach ($entries as $item) {
            $currentBytes += max(0, $item->size);
        }

        if (($currentBytes + $incomingBytes) <= $maxBytes) {
            return [];
        }

        $evictIds = [];
        $oldestFirst = array_reverse($entries);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        $trashDir = $this->getTrashDir($profile);
        foreach ($oldestFirst as $old) {
            if (($currentBytes + $incomingBytes) <= $maxBytes) {
                break;
            }
            if (!$this->canEvictTrashEntry($filesystem, $trashDir, $profile, $volumeName, $old)) {
                continue;
            }
            $evictIds[] = $old->id;
            $currentBytes = max(0, $currentBytes - max(0, $old->size));
        }

        if (($currentBytes + $incomingBytes) > $maxBytes) {
            throw new AccessDeniedException(
                'Trash capacity is full and you do not have permission to purge older trash items.',
                'errors.trash_item_too_large',
                ['%max%' => $rawMaxSize],
            );
        }

        return $evictIds;
    }

    /**
     * @return list<TrashEntry>
     */
    private function listTrashForPlanning(ProfileConfig $profile, VolumeConfig $volume): array
    {
        if ($this->bulkBatchDepth <= 0) {
            return $this->listTrash($profile, $volume->name);
        }

        $batchKey = $this->buildBatchTrashCacheKey($profile, $volume);
        if (!isset($this->bulkBatchTrashCache[$batchKey])) {
            $this->bulkBatchTrashCache[$batchKey] = $this->listTrash($profile, $volume->name);
        }

        return $this->bulkBatchTrashCache[$batchKey];
    }

    private function recordBatchTrashAddition(ProfileConfig $profile, VolumeConfig $volume, TrashEntry $entry): void
    {
        if ($this->bulkBatchDepth <= 0) {
            return;
        }
        $batchKey = $this->buildBatchTrashCacheKey($profile, $volume);
        if (!isset($this->bulkBatchTrashCache[$batchKey])) {
            return;
        }
        array_unshift($this->bulkBatchTrashCache[$batchKey], $entry);
    }

    private function recordBatchTrashRemoval(ProfileConfig $profile, VolumeConfig $volume, string $trashId): void
    {
        if ($this->bulkBatchDepth <= 0) {
            return;
        }
        $batchKey = $this->buildBatchTrashCacheKey($profile, $volume);
        if (!isset($this->bulkBatchTrashCache[$batchKey])) {
            return;
        }
        $this->bulkBatchTrashCache[$batchKey] = array_values(array_filter(
            $this->bulkBatchTrashCache[$batchKey],
            static fn (TrashEntry $e): bool => $e->id !== $trashId,
        ));
    }

    private function buildBatchTrashCacheKey(ProfileConfig $profile, VolumeConfig $volume): string
    {
        return $this->resolveTrashLockScope($profile, $volume);
    }

    /**
     * Re-entrant per-volume exclusive lock serializing trash moves and restores so concurrent
     * restores sharing the same originalPath cannot overwrite each other and FIFO evictions stay atomic.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withTrashLock(ProfileConfig $profile, VolumeConfig $volume, callable $callback): mixed
    {
        $lockScope = $this->resolveTrashLockScope($profile, $volume);
        $lockKey = 'trash_' . hash('sha256', $lockScope);

        if ((self::$heldTrashLocks[$lockKey] ?? 0) > 0) {
            ++self::$heldTrashLocks[$lockKey];
            try {
                return $callback();
            } finally {
                --self::$heldTrashLocks[$lockKey];
            }
        }

        $ownerScope = function_exists('posix_geteuid')
            ? (string) posix_geteuid()
            : substr(md5((string) get_current_user()), 0, 12);
        $lockDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'liveux_fm_locks_' . $ownerScope;
        if (is_link($lockDir)) {
            throw new FileManagerException('Lock directory is an unsafe symbolic link.', 'errors.action_failed');
        }
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new FileManagerException('Unable to create trash lock directory.', 'errors.action_failed');
        }

        $lockPath = $lockDir . DIRECTORY_SEPARATOR . $lockKey . '.lock';
        $lockFp = @fopen($lockPath, 'c');
        if ($lockFp === false || !@flock($lockFp, LOCK_EX)) {
            if (is_resource($lockFp)) {
                fclose($lockFp);
            }

            throw new FileManagerException('Unable to acquire trash lock.', 'errors.action_failed');
        }

        self::$heldTrashLocks[$lockKey] = 1;
        try {
            return $callback();
        } finally {
            unset(self::$heldTrashLocks[$lockKey]);
            @flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    private function resolveTrashLockScope(ProfileConfig $profile, VolumeConfig $volume): string
    {
        $trashDir = mb_strtolower($this->getTrashDir($profile), 'UTF-8');
        if ($volume->isLocalFilesystem() && $volume->path !== null) {
            $real = @realpath($volume->path);
            $resolved = is_string($real) ? $real : $volume->path;
            $normalized = rtrim(str_replace('\\', '/', $resolved), '/');
            if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin') {
                $normalized = mb_strtolower($normalized, 'UTF-8');
            }

            return 'local|' . $normalized . '|' . $trashDir;
        }

        if (strtolower($volume->driver) === 'awss3') {
            $endpoint = PathNormalizer::normalizeS3Endpoint((string) ($volume->driverOptions['endpoint'] ?? ''));
            $bucket = trim((string) ($volume->driverOptions['bucket'] ?? ''));
            $prefix = trim((string) ($volume->driverOptions['prefix'] ?? ''), '/');

            return 's3|' . $endpoint . '|' . $bucket . '|' . $prefix . '|' . $trashDir;
        }

        return $this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath) . '|' . $trashDir;
    }

    /**
     * Dry-run validation for bulk trash moves: checks cumulative/single size against `trash.max_size`
     * and FIFO `TRASH_PURGE` permissions upfront without deleting any entry.
     */
    public function assertBatchCanMoveToTrash(
        ProfileConfig $profile,
        string $volumeName,
        int $totalBytes,
        int $maxSingleItemBytes = 0,
    ): void {
        if (!$this->isEnabled($profile)) {
            return;
        }

        $effectiveTotal = max(0, $totalBytes, $maxSingleItemBytes);
        $this->planFifoTrashEvictions($profile, $volumeName, $effectiveTotal);
    }

    private function canEvictTrashEntry(
        FilesystemOperator $filesystem,
        string $trashDir,
        ProfileConfig $profile,
        string $volumeName,
        TrashEntry $old,
    ): bool {
        try {
            $cleanOrig = $this->pathNormalizer->normalize($old->originalPath);
        } catch (\Throwable) {
            return false;
        }

        if (
            !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanOrig, $volumeName)
            || !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_PURGE, $cleanOrig, $volumeName)
        ) {
            return false;
        }

        if ($old->isFolder) {
            try {
                $safePayloadName = $this->pathNormalizer->normalize(PathNormalizer::basenameUtf8($old->name));
                if ($safePayloadName === '' || str_contains($safePayloadName, '/')) {
                    return false;
                }
                $payloadPath = $trashDir . '/' . $old->id . '/' . $safePayloadName;
                $this->validateTrashedFolderContentsForPurge($filesystem, $payloadPath, $cleanOrig, $profile, $volumeName);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    private function resolveLocalFallbackFile(): ?string
    {
        $file = $this->localMetaFallbackFile;
        if ($file === null || trim($file) === '') {
            return null;
        }

        $base = basename($file);
        if ($base === 'trash-meta.json' || str_ends_with($base, '.trash-meta.json')) {
            return $file;
        }

        if ($base === 'metadata.json') {
            return \dirname($file) . DIRECTORY_SEPARATOR . 'trash-meta.json';
        }

        $stem = pathinfo($base, PATHINFO_FILENAME);
        $safeStem = $stem !== '' ? $stem : 'metadata';

        return \dirname($file) . DIRECTORY_SEPARATOR . $safeStem . '.trash-meta.json';
    }

    private function persistMetaEntry(
        FilesystemOperator $filesystem,
        VolumeConfig $volume,
        string $metaPath,
        TrashEntry $entry,
        string $basePath = '',
    ): void {
        $json = json_encode($entry->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        try {
            $filesystem->write($metaPath, $json);
        } catch (FilesystemException $exception) {
            // MIME-restricted cloud buckets (e.g., Supabase allowing only PDF/images) reject application/json sidecars.
            $fallbackFile = $this->resolveLocalFallbackFile();
            if ($volume->isLocalFilesystem() || $fallbackFile === null) {
                throw $exception;
            }
            $this->saveLocalFallbackEntry($volume, $entry, $basePath);
        }
    }

    /**
     * @param array<string, array<string, mixed>>|null $cachedFallbackMap
     */
    private function resolveEntry(
        FilesystemOperator $filesystem,
        VolumeConfig $volume,
        string $entryDir,
        string $trashId,
        ?array $cachedFallbackMap = null,
        string $basePath = '',
    ): ?TrashEntry {
        $fallbackKey = $this->buildFallbackKey($volume, $trashId, $basePath);
        $legacyKey = $volume->name . '::' . $trashId;
        if (
            !$volume->isLocalFilesystem()
            && $cachedFallbackMap !== null
            && (isset($cachedFallbackMap[$fallbackKey]) || isset($cachedFallbackMap[$legacyKey]))
        ) {
            $fallback = $this->readLocalFallbackEntry($volume, $trashId, $cachedFallbackMap, $basePath);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        $entry = $this->readMetaEntry($filesystem, $entryDir . '/.meta.json');
        if ($entry !== null) {
            return $entry;
        }

        $fallback = $this->readLocalFallbackEntry($volume, $trashId, $cachedFallbackMap, $basePath);
        if ($fallback !== null) {
            return $fallback;
        }

        return $this->recoverOrphanEntry($filesystem, $volume, $entryDir, $trashId);
    }

    private function readMetaEntry(FilesystemOperator $filesystem, string $metaPath): ?TrashEntry
    {
        try {
            $raw = $filesystem->read($metaPath);
        } catch (FilesystemException) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? TrashEntry::fromArray($decoded) : null;
    }

    private function recoverOrphanEntry(
        FilesystemOperator $filesystem,
        VolumeConfig $volume,
        string $entryDir,
        string $trashId,
    ): ?TrashEntry {
        if (preg_match('/^[a-zA-Z0-9_-]{4,64}$/', $trashId) !== 1) {
            return null;
        }

        foreach ($filesystem->listContents($entryDir, false) as $child) {
            $name = PathNormalizer::basenameUtf8($child->path());
            if ($name === '' || $name === '.meta.json') {
                continue;
            }

            $isFolder = $child instanceof DirectoryAttributes;
            $size = $child instanceof FileAttributes ? ($child->fileSize() ?? 0) : 0;
            $deletedAt = $this->extractTimestampFromTrashId($trashId) ?? ($child->lastModified() ?? time());

            return new TrashEntry(
                id: $trashId,
                name: $name,
                originalPath: $name,
                volume: $volume->name,
                isFolder: $isFolder,
                size: $size,
                deletedAt: $deletedAt,
            );
        }

        return null;
    }

    private function extractTimestampFromTrashId(string $trashId): ?int
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})_/', $trashId, $m) !== 1) {
            return null;
        }

        $ts = strtotime(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]));

        return $ts !== false ? $ts : null;
    }

    private function buildFallbackKey(VolumeConfig $volume, string $trashId, string $basePath = ''): string
    {
        return $this->pathNormalizer->buildCanonicalKey($volume, '__trash_fb__/' . $trashId, $basePath);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readLocalFallbackMap(): array
    {
        $file = $this->resolveLocalFallbackFile();
        if ($file === null || !is_file($file)) {
            return [];
        }

        // Coordinate with mutateLocalFallbackMap() on the exact same sibling `.lock` file so Windows atomic copy/rename cannot race readers.
        $lockFp = @fopen($file . '.lock', 'c+b');
        if ($lockFp !== false) {
            @flock($lockFp, LOCK_SH);
        }

        try {
            $raw = @file_get_contents($file);
        } finally {
            if ($lockFp !== false) {
                @flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Atomically mutates the local fallback JSON map under an exclusive sibling lock file
     * using write-to-temp + rename so I/O or disk-full failures can never truncate existing data.
     *
     * @param callable(array<string, array<string, mixed>>): (array<string, array<string, mixed>>|null) $mutator
     */
    private function mutateLocalFallbackMap(callable $mutator): void
    {
        $file = $this->resolveLocalFallbackFile();
        if ($file === null) {
            return;
        }

        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $lockFp = @fopen($file . '.lock', 'c+b');
        if ($lockFp === false) {
            return;
        }

        try {
            if (!flock($lockFp, LOCK_EX)) {
                return;
            }

            $raw = is_file($file) ? @file_get_contents($file) : '';
            if ($raw === false) {
                return;
            }
            $decoded = trim($raw) !== '' ? json_decode($raw, true) : [];
            if (trim($raw) !== '' && !is_array($decoded)) {
                return;
            }
            $map = is_array($decoded) ? $decoded : [];

            $next = $mutator($map);
            if ($next === null) {
                return;
            }

            $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $this->writeFallbackAtomically($file, $dir, $json);
            }
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    private function writeFallbackAtomically(string $file, string $dir, string $json): void
    {
        $tmpFile = $dir . DIRECTORY_SEPARATOR . '.' . basename($file) . '.tmp.' . bin2hex(random_bytes(6));
        $tmpFp = @fopen($tmpFile, 'wb');
        if ($tmpFp === false) {
            return;
        }

        $expectedLen = strlen($json);
        $written = 0;
        try {
            $res = fwrite($tmpFp, $json);
            $written = is_int($res) ? $res : 0;
            fflush($tmpFp);
        } finally {
            fclose($tmpFp);
        }

        if ($written !== $expectedLen) {
            @unlink($tmpFile);

            return;
        }

        if (!@rename($tmpFile, $file)) {
            if (@copy($tmpFile, $file)) {
                @unlink($tmpFile);
            } else {
                @unlink($tmpFile);
            }
        }
    }

    private function saveLocalFallbackEntry(VolumeConfig $volume, TrashEntry $entry, string $basePath = ''): void
    {
        $key = $this->buildFallbackKey($volume, $entry->id, $basePath);
        $data = $entry->toArray();
        $this->mutateLocalFallbackMap(static function (array $map) use ($key, $data): array {
            $map[$key] = $data;

            return $map;
        });
    }

    /**
     * @param array<string, array<string, mixed>>|null $cachedMap
     */
    private function readLocalFallbackEntry(VolumeConfig $volume, string $trashId, ?array $cachedMap = null, string $basePath = ''): ?TrashEntry
    {
        $map = $cachedMap ?? $this->readLocalFallbackMap();
        $key = $this->buildFallbackKey($volume, $trashId, $basePath);
        if (isset($map[$key])) {
            return TrashEntry::fromArray($map[$key]);
        }

        // Backward-compatible read for pre-existing legacy `<volumeName>::<trashId>` entries
        $legacyKey = $volume->name . '::' . $trashId;

        return isset($map[$legacyKey]) ? TrashEntry::fromArray($map[$legacyKey]) : null;
    }

    private function deleteLocalFallbackEntry(VolumeConfig $volume, string $trashId, string $basePath = ''): void
    {
        $key = $this->buildFallbackKey($volume, $trashId, $basePath);
        $legacyKey = $volume->name . '::' . $trashId;
        $this->mutateLocalFallbackMap(static function (array $map) use ($key, $legacyKey): ?array {
            if (!isset($map[$key]) && !isset($map[$legacyKey])) {
                return null;
            }
            unset($map[$key], $map[$legacyKey]);

            return $map;
        });
    }

    private function moveItem(
        FilesystemOperator $filesystem,
        VolumeConfig $volume,
        string $source,
        string $destination,
        bool $isFolder,
    ): void {
        if (!$isFolder || $volume->isLocalFilesystem()) {
            $filesystem->move($source, $destination);

            return;
        }

        $planned = [];
        $count = 0;
        foreach ($filesystem->listContents($source, true) as $attr) {
            ++$count;
            if ($count > self::MAX_TRASH_MOVE_ITEMS) {
                throw new FileManagerException(
                    sprintf('Folder exceeds maximum move item limit (%d).', self::MAX_TRASH_MOVE_ITEMS),
                    'errors.copy_limit_exceeded',
                    ['%max%' => self::MAX_TRASH_MOVE_ITEMS],
                );
            }

            $itemPath = $this->pathNormalizer->normalize($attr->path());
            $relative = ltrim(substr($itemPath, strlen($source)), '/');
            $target = $this->pathNormalizer->join($destination, $relative);
            $planned[] = ['attr' => $attr, 'src' => $itemPath, 'dst' => $target];
        }

        try {
            $filesystem->createDirectory($destination);
        } catch (FilesystemException) {
            // Virtual-prefix cloud buckets with MIME restrictions reject 0-byte directory marker PutObject.
        }

        foreach ($planned as $entry) {
            $attr = $entry['attr'];
            if ($attr instanceof DirectoryAttributes) {
                try {
                    $filesystem->createDirectory($entry['dst']);
                } catch (FilesystemException) {
                    // Best-effort directory marker on MIME-restricted cloud buckets.
                }
            } elseif ($attr instanceof FileAttributes) {
                $filesystem->copy($entry['src'], $entry['dst']);
            }
        }

        $filesystem->deleteDirectory($source);
    }

    private function assertSafeRestoredFolderName(string $folderName): void
    {
        $this->fileSecurityGuard->assertSafeFolderName($folderName);
    }

    private function validateTrashedFolderContentsForPurge(
        FilesystemOperator $filesystem,
        string $payloadPath,
        string $targetRootPath,
        ProfileConfig $profile,
        string $volumeName,
    ): void {
        if (!$filesystem->directoryExists($payloadPath)) {
            return;
        }

        $count = 0;
        foreach ($filesystem->listContents($payloadPath, true) as $attr) {
            if (++$count > self::MAX_TRASH_MOVE_ITEMS) {
                throw new FileManagerException(
                    sprintf('Folder exceeds maximum trash item limit (%d).', self::MAX_TRASH_MOVE_ITEMS),
                    'errors.copy_limit_exceeded',
                    ['%max%' => self::MAX_TRASH_MOVE_ITEMS],
                );
            }
            $itemPath = $this->pathNormalizer->normalize($attr->path());
            $relative = ltrim(substr($itemPath, strlen($payloadPath)), '/');
            if ($relative === '') {
                continue;
            }
            $restoredItemPath = $this->pathNormalizer->join($targetRootPath, $relative);
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $restoredItemPath, $volumeName);
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_PURGE, $restoredItemPath, $volumeName);
        }
    }

    private function validateRestoredFolderContents(
        FilesystemOperator $filesystem,
        string $payloadPath,
        string $targetRootPath,
        ProfileConfig $profile,
        string $volumeName,
        bool $enforceVoter,
    ): void {
        $count = 0;
        foreach ($filesystem->listContents($payloadPath, true) as $attr) {
            ++$count;
            if ($count > self::MAX_TRASH_MOVE_ITEMS) {
                throw new FileManagerException(
                    sprintf('Folder exceeds maximum trash item limit (%d).', self::MAX_TRASH_MOVE_ITEMS),
                    'errors.copy_limit_exceeded',
                    ['%max%' => self::MAX_TRASH_MOVE_ITEMS],
                );
            }

            $itemPath = $this->pathNormalizer->normalize($attr->path());
            $relative = ltrim(substr($itemPath, strlen($payloadPath)), '/');
            if ($relative === '') {
                continue;
            }

            $restoredItemPath = $this->pathNormalizer->join($targetRootPath, $relative);
            $this->fileSecurityGuard->assertAccessiblePath($restoredItemPath, $profile);

            if ($attr instanceof FileAttributes) {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($restoredItemPath));
            } else {
                $this->assertSafeRestoredFolderName(PathNormalizer::basenameUtf8($restoredItemPath));
            }

            if ($enforceVoter) {
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $restoredItemPath, $volumeName);
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::TRASH_RESTORE, $restoredItemPath, $volumeName);
            }
        }
    }

    private function resolveUniqueRestorePath(FilesystemOperator $filesystem, string $originalPath, ProfileConfig $profile, bool $isFolder = false): string
    {
        if (!$filesystem->fileExists($originalPath) && !$filesystem->directoryExists($originalPath)) {
            return $originalPath;
        }

        $dir = PathNormalizer::dirnameUtf8($originalPath);
        $ext = $isFolder ? '' : PathNormalizer::extensionUtf8($originalPath);
        $maxBaseBytes = max(1, min(180, 255 - ($ext !== '' ? strlen($ext) + 1 : 0) - 22));
        $rawBase = $isFolder ? PathNormalizer::basenameUtf8($originalPath) : PathNormalizer::stemUtf8($originalPath);
        $base = \LiveUX\FileManagerBundle\Util\UnicodeHelper::truncateStemBytes($rawBase, $maxBaseBytes);
        if ($base === '') {
            $base = substr('restored', 0, $maxBaseBytes);
        }
        $stamp = date('His');

        for ($counter = 0; $counter < 1000; ++$counter) {
            $suffix = $counter === 0 ? '_restored_' . $stamp : sprintf('_restored_%s_%d', $stamp, $counter);
            $newName = $ext !== '' ? $base . $suffix . '.' . $ext : $base . $suffix;
            $candidate = $dir !== '' ? $dir . '/' . $newName : $newName;
            $this->fileSecurityGuard->assertAccessiblePath($candidate, $profile);
            if (!$filesystem->fileExists($candidate) && !$filesystem->directoryExists($candidate)) {
                return $candidate;
            }
        }

        $fallbackName = $ext !== ''
            ? sprintf('%s_restored_%s.%s', $base, bin2hex(random_bytes(4)), $ext)
            : sprintf('%s_restored_%s', $base, bin2hex(random_bytes(4)));
        $fallbackCandidate = $dir !== '' ? $dir . '/' . $fallbackName : $fallbackName;
        $this->fileSecurityGuard->assertAccessiblePath($fallbackCandidate, $profile);

        return $fallbackCandidate;
    }
}
