<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\EventListener;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\FolderCreatedEvent;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\Event\ItemClonedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\Event\TrashRestoredEvent;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class CacheInvalidationListener implements EventSubscriberInterface
{
    public function __construct(
        private DirectoryCacheManager $cacheManager,
        private PathNormalizer $pathNormalizer,
        private ?ConfigProviderInterface $configProvider = null,
        private ?VolumeRegistry $volumeRegistry = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onMutate',
            FileDeletedEvent::class => 'onMutate',
            FolderDeletedEvent::class => 'onMutate',
            FolderCreatedEvent::class => 'onMutate',
            ItemRenamedEvent::class => 'onMutate',
            ItemMovedEvent::class => 'onMove',
            ItemClonedEvent::class => 'onMutate',
            TrashRestoredEvent::class => 'onMutate',
            TrashPurgedEvent::class => 'onMutate',
        ];
    }

    public function onMutate(AbstractFileManagerEvent $event): void
    {
        $this->invalidatePathAndParent($event, $event->path, $event->canonicalPath);
        if ($event instanceof FileUploadedEvent && $event->fromArchiveExtraction) {
            // ZipArchiveService dispatches a single FolderCreatedEvent at the end of extraction
            // to invalidate ancestors, tree, quota, hash indexes, and overlapping roots once.
            return;
        }
        $this->invalidateAncestors($event, $event->path);

        if ($event instanceof ItemRenamedEvent && $event->newCanonicalPath !== $event->canonicalPath) {
            $this->cacheManager->invalidateDirectory($event->newCanonicalPath);
            $this->invalidateAncestors($event, $event->newPath);
            if ($event->isFolder) {
                $this->invalidateScopedVolume($event->volume, $event->profile->basePath);
            }
        } elseif ($event instanceof ItemClonedEvent) {
            $this->invalidatePathAndParent($event, $event->clonedPath, $event->clonedCanonicalPath);
            $this->invalidateAncestors($event, $event->clonedPath);
            if ($event->isFolder) {
                $this->invalidateScopedVolume($event->volume, $event->profile->basePath);
            }
        } elseif (
            $event instanceof FolderDeletedEvent
            || $event instanceof TrashRestoredEvent
            || ($event instanceof FolderCreatedEvent && $event->fromArchiveExtraction)
        ) {
            $this->invalidateScopedVolume($event->volume, $event->profile->basePath);
        }

        $this->invalidateVolumeTree($event);
    }

    public function onMove(ItemMovedEvent $event): void
    {
        $this->onMutate($event);

        $targetParentPath = PathNormalizer::dirnameUtf8($event->targetPath);
        $targetParentCanonical = $this->pathNormalizer->buildCanonicalKey($event->targetVolume, $targetParentPath, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($targetParentCanonical);
        $this->invalidateAncestorsForVolume($event->targetVolume, $event->profile->basePath, $event->targetPath);

        $extractedTargetParent = $this->extractParentCanonical($event->targetCanonicalPath);
        if ($extractedTargetParent !== '' && $extractedTargetParent !== $targetParentCanonical) {
            $this->cacheManager->invalidateDirectory($extractedTargetParent);
        }

        $this->cacheManager->invalidateDirectory($event->targetCanonicalPath);
        if ($event->isFolder) {
            $this->invalidateScopedVolume($event->volume, $event->profile->basePath);
            if ($event->targetVolume->name !== $event->volume->name) {
                $this->invalidateScopedVolume($event->targetVolume, $event->profile->basePath);
            }
        }
        $targetTreeKey = $this->pathNormalizer->buildCanonicalKey($event->targetVolume, DirectoryLister::TREE_CACHE_KEY, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($targetTreeKey);
        $targetQuotaKey = $this->pathNormalizer->buildCanonicalKey($event->targetVolume, StorageQuotaChecker::QUOTA_CACHE_KEY, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($targetQuotaKey);
        $targetQuotaDetailKey = $this->pathNormalizer->buildCanonicalKey($event->targetVolume, StorageQuotaChecker::QUOTA_DETAIL_CACHE_KEY, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($targetQuotaDetailKey);
        $targetHashKey = $this->pathNormalizer->buildCanonicalKey($event->targetVolume, '__sha256__', $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($targetHashKey);
        $targetHashesKey = $this->pathNormalizer->buildCanonicalKey($event->targetVolume, '__sha256_hashes__', $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($targetHashesKey);
        $this->invalidateOverlappingLocalRoots($event->targetVolume, $event->profile->basePath);
    }

    private function invalidateScopedVolume(
        VolumeConfig $volume,
        string $basePath,
    ): void {
        $rootCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '', $basePath);
        $scope = $this->cacheManager->resolveVolumeScopeForRoot($rootCanonical);
        $this->cacheManager->invalidateVolumeScope($scope);
    }

    private function invalidatePathAndParent(AbstractFileManagerEvent $event, string $path, string $canonicalPath): void
    {
        $parentPath = PathNormalizer::dirnameUtf8($path);
        $parentCanonical = $this->pathNormalizer->buildCanonicalKey($event->volume, $parentPath, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($parentCanonical);

        $extractedParent = $this->extractParentCanonical($canonicalPath);
        if ($extractedParent !== '' && $extractedParent !== $parentCanonical) {
            $this->cacheManager->invalidateDirectory($extractedParent);
        }

        $this->cacheManager->invalidateDirectory($canonicalPath);
    }

    private function invalidateAncestors(AbstractFileManagerEvent $event, string $path): void
    {
        $this->invalidateAncestorsForVolume($event->volume, $event->profile->basePath, $path);
    }

    private function invalidateAncestorsForVolume(VolumeConfig $volume, string $basePath, string $path): void
    {
        $cursor = PathNormalizer::dirnameUtf8($path);
        $depth = 0;
        while ($cursor !== '' && $depth < 32) {
            $parent = PathNormalizer::dirnameUtf8($cursor);
            $parentCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $parent, $basePath);
            $this->cacheManager->invalidateDirectory($parentCanonical);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
            ++$depth;
        }
    }

    private function invalidateVolumeTree(AbstractFileManagerEvent $event): void
    {
        $treeKey = $this->pathNormalizer->buildCanonicalKey($event->volume, DirectoryLister::TREE_CACHE_KEY, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($treeKey);
        $quotaKey = $this->pathNormalizer->buildCanonicalKey($event->volume, StorageQuotaChecker::QUOTA_CACHE_KEY, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($quotaKey);
        $quotaDetailKey = $this->pathNormalizer->buildCanonicalKey($event->volume, StorageQuotaChecker::QUOTA_DETAIL_CACHE_KEY, $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($quotaDetailKey);
        $hashKey = $this->pathNormalizer->buildCanonicalKey($event->volume, '__sha256__', $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($hashKey);
        $hashesKey = $this->pathNormalizer->buildCanonicalKey($event->volume, '__sha256_hashes__', $event->profile->basePath);
        $this->cacheManager->invalidateDirectory($hashesKey);
        $this->invalidateOverlappingLocalRoots($event->volume, $event->profile->basePath);
    }

    private function invalidateOverlappingLocalRoots(
        VolumeConfig $volume,
        string $basePath,
    ): void {
        $rootCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '', $basePath);
        if ($volume->isLocalFilesystem() && str_starts_with($rootCanonical, 'local::') && !str_starts_with($rootCanonical, 'local::@ext_') && $rootCanonical !== 'local::') {
            $relPrefix = substr($rootCanonical, 7);
            $cursor = '';
            $segments = explode('/', trim($relPrefix, '/'));
            // Invalidate parent root ('local::') and every intermediate sub-root up to, but excluding, $rootCanonical itself.
            $lastIdx = count($segments) - 1;
            foreach ($segments as $idx => $segment) {
                $prefix = $cursor === '' ? 'local::' : 'local::' . $cursor . '/';
                $this->invalidateRootCachesByPrefix($prefix, 'local::' . $cursor);

                if ($idx < $lastIdx && $segment !== '') {
                    $cursor = $cursor === '' ? $segment : $cursor . '/' . $segment;
                }
            }
        }

        if ($this->configProvider !== null) {
            $this->invalidateOverlappingConfiguredVolumes($volume, $basePath, $rootCanonical);
        }
    }

    private function invalidateRootCachesByPrefix(string $prefix, string $rootKey): void
    {
        foreach ([
            DirectoryLister::TREE_CACHE_KEY,
            StorageQuotaChecker::QUOTA_CACHE_KEY,
            StorageQuotaChecker::QUOTA_DETAIL_CACHE_KEY,
            '__sha256__',
            '__sha256_hashes__',
        ] as $suffix) {
            $this->cacheManager->invalidateDirectory($prefix . $suffix);
        }
        $this->cacheManager->invalidateDirectory($rootKey);
    }

    private function invalidateOverlappingConfiguredVolumes(
        VolumeConfig $mutatedVolume,
        string $basePath,
        string $mutatedRootCanonical,
    ): void {
        if ($this->configProvider === null) {
            return;
        }

        $seen = [];
        foreach ($this->configProvider->getProfileNames() as $profileName) {
            try {
                $profile = $this->configProvider->getProfile($profileName);
            } catch (Throwable) {
                continue;
            }
            $profileBase = $profile->basePath;
            $candidates = $this->resolveProfileCandidateVolumes($profile);
            foreach ($candidates as $candidate) {
                $candRoot = $this->pathNormalizer->buildCanonicalKey($candidate, '', $profileBase);
                if ($candRoot === $mutatedRootCanonical || isset($seen[$candRoot])) {
                    continue;
                }
                $seen[$candRoot] = true;

                if ($this->canonicalRootsOverlap($mutatedRootCanonical, $candRoot) || $this->localPhysicalPathsOverlap($mutatedVolume, $candidate)) {
                    $this->invalidateScopedVolume($candidate, $profileBase);
                    $prefix = str_ends_with($candRoot, '::') ? $candRoot : $candRoot . '/';
                    $this->invalidateRootCachesByPrefix($prefix, $candRoot);
                }
            }
            $this->invalidateDynamicTenantTemplatesForProfile($profile, $mutatedVolume, $mutatedRootCanonical);
        }
        unset($basePath);
    }

    private function localPhysicalPathsOverlap(VolumeConfig $a, VolumeConfig $b): bool
    {
        if (!$a->isLocalFilesystem() || !$b->isLocalFilesystem()) {
            return false;
        }
        if ($a->path === null || $b->path === null || $a->path === '' || $b->path === '') {
            return false;
        }
        $normA = $this->normalizePhysicalPath($a->path);
        $normB = $this->normalizePhysicalPath($b->path);
        if ($normA === '' || $normB === '') {
            return false;
        }

        return $normA === $normB
            || str_starts_with($normB, $normA . '/')
            || str_starts_with($normA, $normB . '/');
    }

    private function normalizePhysicalPath(string $path): string
    {
        $real = @realpath($path);
        $resolved = is_string($real) ? $real : $path;
        $forward = rtrim(str_replace('\\', '/', $resolved), '/');

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($forward, 'UTF-8') : $forward;
    }

    /**
     * When a static admin volume (e.g. `/uploads/users` or S3 prefix `users`) mutates a directory
     * overlapping a dynamic `{user_id}` / `{Y}/{m}/{d}` template volume (local OR AwsS3),
     * invalidates that profile's cache scope so affected tenant trees/quotas never stay stale.
     */
    private function invalidateDynamicTenantTemplatesForProfile(
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        VolumeConfig $mutatedVolume,
        string $mutatedRootCanonical,
    ): void {
        if ($this->configProvider === null) {
            return;
        }

        if ($mutatedVolume->isLocalFilesystem() && $mutatedVolume->path !== null) {
            $this->invalidateLocalDynamicTemplatesForProfile($profile, $mutatedVolume->path);

            return;
        }

        if (strtolower($mutatedVolume->driver) === 'awss3') {
            $this->invalidateS3DynamicTemplatesForProfile($profile, $mutatedVolume);
        }
        unset($mutatedRootCanonical);
    }

    private function invalidateLocalDynamicTemplatesForProfile(
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        string $mutatedLocalPath,
    ): void {
        if ($this->configProvider === null) {
            return;
        }

        $mutatedNorm = $this->normalizePhysicalPath($mutatedLocalPath);
        $rawVolumes = $profile->volumes !== [] ? $profile->volumes : [$profile->name];
        foreach ($rawVolumes as $volName) {
            $rawPath = null;
            if ($profile->volumes === []) {
                $rawPath = $profile->driver === 'LocalFileSystem' ? $profile->path : null;
            } elseif ($this->configProvider->hasVolume($volName)) {
                $rawVol = $this->configProvider->getVolume($volName);
                $rawPath = $rawVol->isLocalFilesystem() ? $rawVol->path : null;
            }
            if (!is_string($rawPath) || !str_contains($rawPath, '{')) {
                continue;
            }
            $staticPrefix = explode('{', $rawPath, 2)[0];
            $normPrefix = $this->normalizePhysicalPath($staticPrefix);
            if (
                $normPrefix !== ''
                && ($normPrefix === $mutatedNorm || str_starts_with($normPrefix, $mutatedNorm . '/') || str_starts_with($mutatedNorm, $normPrefix . '/'))
            ) {
                $this->cacheManager->invalidateProfile($profile->name);
                break;
            }
        }
    }

    private function invalidateS3DynamicTemplatesForProfile(
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        VolumeConfig $mutatedVolume,
    ): void {
        if ($this->configProvider === null) {
            return;
        }

        $mutatedBucket = trim((string) ($mutatedVolume->driverOptions['bucket'] ?? ''));
        if ($mutatedBucket === '') {
            return;
        }
        $mutatedEndpoint = PathNormalizer::normalizeS3Endpoint((string) ($mutatedVolume->driverOptions['endpoint'] ?? ''));
        $mutatedPrefix = trim((string) ($mutatedVolume->driverOptions['prefix'] ?? ''), '/');

        $rawVolumes = $profile->volumes !== [] ? $profile->volumes : [$profile->name];
        foreach ($rawVolumes as $volName) {
            $rawOptions = null;
            if ($profile->volumes === []) {
                $rawOptions = strtolower($profile->driver) === 'awss3' ? $profile->driverOptions : null;
            } elseif ($this->configProvider->hasVolume($volName)) {
                $rawVol = $this->configProvider->getVolume($volName);
                $rawOptions = strtolower($rawVol->driver) === 'awss3' ? $rawVol->driverOptions : null;
            }
            if (!is_array($rawOptions)) {
                continue;
            }
            $candBucket = trim((string) ($rawOptions['bucket'] ?? ''));
            $candEndpoint = PathNormalizer::normalizeS3Endpoint((string) ($rawOptions['endpoint'] ?? ''));
            $rawPrefix = (string) ($rawOptions['prefix'] ?? '');
            if ($candBucket !== $mutatedBucket || $candEndpoint !== $mutatedEndpoint || !str_contains($rawPrefix, '{')) {
                continue;
            }
            $staticPrefix = trim(explode('{', $rawPrefix, 2)[0], '/');
            if (
                $mutatedPrefix === ''
                || $staticPrefix === ''
                || $staticPrefix === $mutatedPrefix
                || str_starts_with($staticPrefix, $mutatedPrefix . '/')
                || str_starts_with($mutatedPrefix, $staticPrefix . '/')
            ) {
                $this->cacheManager->invalidateProfile($profile->name);
                break;
            }
        }
    }

    /**
     * Resolves both explicit and implicit (inline) profile volumes, expanding dynamic tokens
     * when VolumeRegistry is available so overlapping tenant/date roots stay coherent.
     *
     * @return list<VolumeConfig>
     */
    private function resolveProfileCandidateVolumes(\LiveUX\FileManagerBundle\Config\ProfileConfig $profile): array
    {
        if ($this->volumeRegistry !== null) {
            try {
                return $this->volumeRegistry->getVolumesForProfile($profile);
            } catch (Throwable) {
                // Fall through to static config fallback below.
            }
        }

        if ($this->configProvider === null) {
            return [];
        }

        if ($profile->volumes === []) {
            return [new VolumeConfig(
                name: $profile->name,
                label: $profile->name,
                group: $profile->driver === 'LocalFileSystem' ? 'local' : 'network',
                driver: $profile->driver,
                path: $profile->path,
                urlPrefix: $profile->urlPrefix,
                private: $profile->private,
                driverOptions: $profile->driverOptions,
            )];
        }

        $volumes = [];
        foreach ($profile->volumes as $volName) {
            try {
                $volumes[] = $this->configProvider->getVolume($volName);
            } catch (Throwable) {
            }
        }

        return $volumes;
    }

    private function canonicalRootsOverlap(string $a, string $b): bool
    {
        $sepA = strpos($a, '::');
        $sepB = strpos($b, '::');
        if ($sepA === false || $sepB === false) {
            return false;
        }
        if (substr($a, 0, $sepA) !== substr($b, 0, $sepB)) {
            return false;
        }
        $restA = trim(substr($a, $sepA + 2), '/');
        $restB = trim(substr($b, $sepB + 2), '/');
        $isExtA = str_starts_with($restA, '@ext_');
        $isExtB = str_starts_with($restB, '@ext_');
        if ($isExtA !== $isExtB) {
            // External paths outside base_path (@ext_<hash>) cannot overlap relative paths under base_path.
            return false;
        }
        if ($restA === '' || $restB === '') {
            return true;
        }

        return $restA === $restB
            || str_starts_with($restB, $restA . '/')
            || str_starts_with($restA, $restB . '/');
    }

    private function extractParentCanonical(string $canonicalPath): string
    {
        $sepPos = strpos($canonicalPath, '::');
        if ($sepPos === false) {
            return '';
        }

        $prefix = substr($canonicalPath, 0, $sepPos + 2);
        $rest = substr($canonicalPath, $sepPos + 2);
        $lastSlash = strrpos($rest, '/');

        if ($lastSlash === false) {
            return $prefix;
        }

        return $prefix . substr($rest, 0, $lastSlash);
    }
}
