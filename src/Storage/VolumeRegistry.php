<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Storage;

use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;

final readonly class VolumeRegistry
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private StorageFactoryInterface $storageFactory,
        private DynamicPathResolver $pathResolver = new DynamicPathResolver(),
    ) {
    }

    /**
     * @return array{local: list<VolumeConfig>, network: list<VolumeConfig>}
     */
    public function getGroupedVolumes(ProfileConfig $profile): array
    {
        $grouped = ['local' => [], 'network' => []];

        foreach ($this->getVolumesForProfile($profile) as $volume) {
            if ($volume->isNetwork()) {
                $grouped['network'][] = $volume;
            } else {
                $grouped['local'][] = $volume;
            }
        }

        return $grouped;
    }

    /**
     * Returns raw (unresolved) volume definitions for inspecting dynamic placeholders (e.g. CLI orphan protection).
     *
     * @return list<VolumeConfig>
     */
    public function getRawVolumesForProfile(ProfileConfig $profile): array
    {
        if ($profile->volumes === []) {
            return [$this->createImplicitVolume($profile)];
        }

        $volumes = [];
        foreach ($profile->volumes as $volumeName) {
            $volumes[] = $this->configProvider->getVolume($volumeName);
        }

        return $volumes;
    }

    /**
     * Returns volume definitions with dynamic tokens ({user_id}, {Y}, {m}, {d}) resolved
     * so canonical cache keys, metadata keys, thumbnails, and public URLs are strictly tenant-isolated.
     *
     * @return list<VolumeConfig>
     */
    public function getVolumesForProfile(ProfileConfig $profile): array
    {
        return array_map(
            $this->resolveDynamicVolume(...),
            $this->getRawVolumesForProfile($profile),
        );
    }

    public function getVolumeForProfile(ProfileConfig $profile, ?string $volumeName = null): VolumeConfig
    {
        $volumes = $this->getVolumesForProfile($profile);
        if ($volumeName === null || $volumeName === '') {
            return $volumes[0];
        }

        foreach ($volumes as $volume) {
            if ($volume->name === $volumeName) {
                return $volume;
            }
        }

        throw new FileManagerException(sprintf('Volume "%s" is not attached to profile "%s".', $volumeName, $profile->name), 'errors.volume_not_attached', ['%volume%' => $volumeName, '%profile%' => $profile->name]);
    }

    public function getRawVolumeForProfile(ProfileConfig $profile, ?string $volumeName = null): VolumeConfig
    {
        $rawVolumes = $this->getRawVolumesForProfile($profile);
        if ($volumeName === null || $volumeName === '') {
            return $rawVolumes[0];
        }

        foreach ($rawVolumes as $volume) {
            if ($volume->name === $volumeName) {
                return $volume;
            }
        }

        throw new FileManagerException(sprintf('Volume "%s" is not attached to profile "%s".', $volumeName, $profile->name), 'errors.volume_not_attached', ['%volume%' => $volumeName, '%profile%' => $profile->name]);
    }

    public function getVolumeForProfileWithContext(
        ProfileConfig $profile,
        ?string $volumeName = null,
        ?DateTimeImmutable $fixedDate = null,
        ?string $overrideUserId = null,
    ): VolumeConfig {
        $raw = $this->getRawVolumeForProfile($profile, $volumeName);

        return $this->resolveDynamicVolume($raw, $fixedDate, $overrideUserId);
    }

    public function getFilesystem(ProfileConfig $profile, ?string $volumeName = null): FilesystemOperator
    {
        return $this->storageFactory->create($this->getVolumeForProfile($profile, $volumeName));
    }

    public function getFilesystemWithContext(
        ProfileConfig $profile,
        ?string $volumeName = null,
        ?DateTimeImmutable $fixedDate = null,
        ?string $overrideUserId = null,
    ): FilesystemOperator {
        return $this->storageFactory->create(
            $this->getVolumeForProfileWithContext($profile, $volumeName, $fixedDate, $overrideUserId),
        );
    }

    private function resolveDynamicVolume(
        VolumeConfig $volume,
        ?DateTimeImmutable $fixedDate = null,
        ?string $overrideUserId = null,
    ): VolumeConfig {
        $hasDynamicPath = $volume->path !== null && DynamicPathResolver::containsDynamicTokens($volume->path);
        $hasDynamicUrl = $volume->urlPrefix !== null && DynamicPathResolver::containsDynamicTokens($volume->urlPrefix);
        $rawPrefix = $volume->driverOptions['prefix'] ?? null;
        $hasDynamicPrefix = is_string($rawPrefix) && DynamicPathResolver::containsDynamicTokens($rawPrefix);

        if (!$hasDynamicPath && !$hasDynamicUrl && !$hasDynamicPrefix) {
            return $volume;
        }

        $options = $volume->driverOptions;
        if ($hasDynamicPrefix) {
            $options['prefix'] = $this->pathResolver->resolve($rawPrefix, $fixedDate, $overrideUserId);
        }

        return new VolumeConfig(
            name: $volume->name,
            label: $volume->label,
            group: $volume->group,
            driver: $volume->driver,
            path: $hasDynamicPath ? $this->pathResolver->resolve($volume->path, $fixedDate, $overrideUserId) : $volume->path,
            urlPrefix: $hasDynamicUrl ? $this->pathResolver->resolve($volume->urlPrefix, $fixedDate, $overrideUserId) : $volume->urlPrefix,
            private: $volume->private,
            driverOptions: $options,
            icon: $volume->icon,
            cacheTtl: $volume->cacheTtl,
        );
    }

    private function createImplicitVolume(ProfileConfig $profile): VolumeConfig
    {
        $group = $profile->driver === 'LocalFileSystem' ? 'local' : 'network';

        return new VolumeConfig(
            name: $profile->name,
            label: \LiveUX\FileManagerBundle\Util\UnicodeHelper::mbUcfirst(str_replace(['_', '-'], ' ', $profile->name)),
            group: $group,
            driver: $profile->driver,
            path: $profile->path !== '' ? $profile->path : null,
            urlPrefix: $profile->urlPrefix,
            private: $profile->private,
            driverOptions: $profile->driverOptions,
        );
    }
}
