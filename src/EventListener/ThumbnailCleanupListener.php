<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\EventListener;

use League\Flysystem\FileAttributes;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\Event\ItemClonedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\Event\TrashRestoredEvent;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class ThumbnailCleanupListener implements EventSubscriberInterface
{
    private const int MAX_FOLDER_SCAN = 5000;

    public function __construct(
        private ThumbnailManager $thumbnailManager,
        private ?ConfigProviderInterface $configProvider = null,
        private ?VolumeRegistry $volumeRegistry = null,
        private PathNormalizer $pathNormalizer = new PathNormalizer(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileDeletedEvent::class => 'onFileDeleted',
            FolderDeletedEvent::class => 'onFolderDeleted',
            FileUploadedEvent::class => 'onFileUploaded',
            ItemRenamedEvent::class => 'onItemRenamed',
            ItemMovedEvent::class => 'onItemMoved',
            ItemClonedEvent::class => 'onItemCloned',
            TrashRestoredEvent::class => 'onTrashRestored',
            TrashPurgedEvent::class => 'onTrashPurged',
        ];
    }

    public function onFileDeleted(FileDeletedEvent $event): void
    {
        $this->deleteAcrossProfiles($event->profile, $event->canonicalPath, $event->volume, $event->path);
    }

    public function onFolderDeleted(FolderDeletedEvent $event): void
    {
        $targets = $this->resolveUniqueThumbnailProfiles($event->profile);
        $this->deleteAcrossTargets($targets, $event->canonicalPath, $event->profile, $event->volume, $event->path);
        foreach ($event->deletedChildCanonicalPaths as $idx => $childCanonical) {
            if ($childCanonical !== '') {
                $childRelPath = $event->deletedChildPaths[$idx] ?? null;
                $this->deleteAcrossTargets(
                    $targets,
                    $childCanonical,
                    $childRelPath !== null ? $event->profile : null,
                    $childRelPath !== null ? $event->volume : null,
                    $childRelPath,
                );
            }
        }
        if ($event->volume->isNetwork() && count($event->deletedChildCanonicalPaths) >= self::MAX_FOLDER_SCAN) {
            $this->purgeAllCachedThumbnailsForTargets($targets);
        }
    }

    public function onFileUploaded(FileUploadedEvent $event): void
    {
        $this->deleteAcrossProfiles($event->profile, $event->canonicalPath, $event->volume, $event->path);
    }

    public function onItemRenamed(ItemRenamedEvent $event): void
    {
        if (!$event->isFolder) {
            $this->deleteAcrossProfiles($event->profile, $event->canonicalPath, $event->volume, $event->path);
            $this->deleteAcrossProfiles($event->profile, $event->newCanonicalPath, $event->volume, $event->newPath);

            return;
        }

        $this->cleanupFolderTransition($event->profile, $event->volume, $event->path, $event->volume, $event->newPath);
    }

    public function onItemMoved(ItemMovedEvent $event): void
    {
        if (!$event->isFolder) {
            if (!$event->isCopy) {
                $this->deleteAcrossProfiles($event->profile, $event->canonicalPath, $event->volume, $event->path);
            }
            $this->deleteAcrossProfiles($event->profile, $event->targetCanonicalPath, $event->targetVolume, $event->targetPath);

            return;
        }

        $this->cleanupFolderTransition($event->profile, $event->volume, $event->path, $event->targetVolume, $event->targetPath, !$event->isCopy);
    }

    public function onItemCloned(ItemClonedEvent $event): void
    {
        if (!$event->isFolder) {
            $this->deleteAcrossProfiles($event->profile, $event->clonedCanonicalPath, $event->volume, $event->clonedPath);

            return;
        }

        $this->cleanupFolderTransition($event->profile, $event->volume, $event->path, $event->volume, $event->clonedPath, false);
    }

    public function onTrashRestored(TrashRestoredEvent $event): void
    {
        $targets = $this->resolveUniqueThumbnailProfiles($event->profile);
        $this->deleteAcrossTargets($targets, $event->canonicalPath, $event->profile, $event->volume, $event->path);
        if ($event->originalCanonicalPath !== null && $event->originalCanonicalPath !== '') {
            $this->deleteAcrossTargets($targets, $event->originalCanonicalPath);
        }
        if ($event->isFolder === false) {
            return;
        }
        if ($event->isFolder === null && $this->volumeRegistry !== null) {
            try {
                $fs = $this->volumeRegistry->getFilesystem($event->profile, $event->volume->name);
                if ($fs->fileExists($event->path)) {
                    return;
                }
            } catch (Throwable) {
            }
        }
        $this->cleanupLiveFolderThumbnails($event->profile, $event->volume, $event->path);
    }

    public function onTrashPurged(TrashPurgedEvent $event): void
    {
        if ($event->originalCanonicalPath !== null && $event->originalCanonicalPath !== '') {
            $this->deleteAcrossProfiles($event->profile, $event->originalCanonicalPath);
        }
    }

    private function deleteAcrossProfiles(
        ProfileConfig $triggerProfile,
        string $canonicalPath,
        ?VolumeConfig $volume = null,
        ?string $path = null,
    ): void {
        $this->deleteAcrossTargets($this->resolveUniqueThumbnailProfiles($triggerProfile), $canonicalPath, $triggerProfile, $volume, $path);
    }

    /**
     * Resolves and deduplicates thumbnail target profiles once per batch to avoid N*P
     * profile hydrations and redundant filesystem unlinks when profiles share thumbnail settings.
     *
     * @return list<ProfileConfig>
     */
    private function resolveUniqueThumbnailProfiles(ProfileConfig $triggerProfile): array
    {
        $targets = [];
        $seen = [];

        $addIfUnique = static function (ProfileConfig $profile) use (&$targets, &$seen): void {
            $thumb = $profile->thumbnail;
            $cacheDir = rtrim(trim((string) ($thumb['cache_dir'] ?? '')), '/\\');
            if ($cacheDir === '') {
                return;
            }
            $sig = implode('|', [
                $cacheDir,
                (string) ($thumb['width'] ?? 320),
                (string) ($thumb['height'] ?? 240),
                (string) ($thumb['fit'] ?? 'contain'),
                (string) ($thumb['quality'] ?? 80),
                (string) ($thumb['format'] ?? 'webp'),
                $profile->basePath,
            ]);
            if (isset($seen[$sig])) {
                return;
            }
            $seen[$sig] = true;
            $targets[] = $profile;
        };

        $addIfUnique($triggerProfile);
        if ($this->configProvider === null) {
            return $targets;
        }

        foreach ($this->configProvider->getProfileNames() as $name) {
            if ($name === $triggerProfile->name) {
                continue;
            }
            try {
                $addIfUnique($this->configProvider->getProfile($name));
            } catch (Throwable) {
                continue;
            }
        }

        return $targets;
    }

    /**
     * @param list<ProfileConfig> $targets
     */
    private function deleteAcrossTargets(
        array $targets,
        string $canonicalPath,
        ?ProfileConfig $triggerProfile = null,
        ?VolumeConfig $volume = null,
        ?string $path = null,
    ): void {
        foreach ($targets as $targetProfile) {
            $key = $canonicalPath;
            if (
                $volume !== null
                && $path !== null
                && $triggerProfile !== null
                && $targetProfile->basePath !== $triggerProfile->basePath
            ) {
                $key = $this->pathNormalizer->buildCanonicalKey($volume, $path, $targetProfile->basePath);
            }
            $this->thumbnailManager->deleteCachedThumbnail($targetProfile, $key);
        }
    }

    /**
     * Fail-closed purge when a network folder mutation exceeds MAX_FOLDER_SCAN raster files
     * so stale cached cloud thumbnails cannot be disclosed after move/delete.
     *
     * @param list<ProfileConfig> $targets
     */
    private function purgeAllCachedThumbnailsForTargets(array $targets): void
    {
        $clearedDirs = [];
        foreach ($targets as $targetProfile) {
            $cacheDir = rtrim(trim((string) ($targetProfile->thumbnail['cache_dir'] ?? '')), '/\\');
            if ($cacheDir === '' || isset($clearedDirs[$cacheDir])) {
                continue;
            }
            $clearedDirs[$cacheDir] = true;
            $this->thumbnailManager->clearCacheDir($cacheDir);
        }
    }

    private function cleanupFolderTransition(
        ProfileConfig $profile,
        VolumeConfig $oldVolume,
        string $oldFolderPath,
        VolumeConfig $newVolume,
        string $newFolderPath,
        bool $deleteOld = true,
    ): void {
        if ($this->volumeRegistry === null) {
            return;
        }

        $cleanOldFolder = $this->pathNormalizer->normalize($oldFolderPath);
        $cleanNewFolder = $this->pathNormalizer->normalize($newFolderPath);
        $targets = $this->resolveUniqueThumbnailProfiles($profile);
        if ($targets === []) {
            return;
        }

        try {
            $fs = $this->volumeRegistry->getFilesystem($profile, $newVolume->name);
            if ($newVolume->isLocalFilesystem() && !$fs->directoryExists($cleanNewFolder)) {
                return;
            }

            $rasterCount = 0;
            $truncated = false;
            foreach ($fs->listContents($cleanNewFolder, true) as $attr) {
                if (!$attr instanceof FileAttributes) {
                    continue;
                }
                try {
                    $childNewPath = $this->pathNormalizer->normalize($attr->path());
                } catch (\LiveUX\FileManagerBundle\Exception\InvalidPathException) {
                    continue;
                }
                $ext = PathNormalizer::extensionUtf8($childNewPath);
                if (!in_array($ext, FileSecurityGuard::SAFE_RASTER_EXTENSIONS, true)) {
                    continue;
                }
                if (++$rasterCount > self::MAX_FOLDER_SCAN) {
                    $truncated = true;
                    break;
                }

                if ($deleteOld) {
                    $rel = ltrim(substr($childNewPath, strlen($cleanNewFolder)), '/');
                    $childOldPath = $this->pathNormalizer->join($cleanOldFolder, $rel);
                    $oldCanonical = $this->pathNormalizer->buildCanonicalKey($oldVolume, $childOldPath, $profile->basePath);
                    $this->deleteAcrossTargets($targets, $oldCanonical, $profile, $oldVolume, $childOldPath);
                }

                $newCanonical = $this->pathNormalizer->buildCanonicalKey($newVolume, $childNewPath, $profile->basePath);
                $this->deleteAcrossTargets($targets, $newCanonical, $profile, $newVolume, $childNewPath);
            }

            if ($truncated && ($oldVolume->isNetwork() || $newVolume->isNetwork())) {
                $this->purgeAllCachedThumbnailsForTargets($targets);
            }
        } catch (Throwable) {
            // Best-effort cleanup must never abort completed folder operations.
        }
    }

    private function cleanupLiveFolderThumbnails(ProfileConfig $profile, VolumeConfig $volume, string $folderPath): void
    {
        $this->cleanupFolderTransition($profile, $volume, $folderPath, $volume, $folderPath, false);
    }
}
