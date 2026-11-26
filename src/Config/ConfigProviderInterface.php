<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Config;

interface ConfigProviderInterface
{
    public function hasProfile(string $name): bool;

    public function getProfile(string $name): ProfileConfig;

    /**
     * @return list<string>
     */
    public function getProfileNames(): array;

    public function hasVolume(string $name): bool;

    public function getVolume(string $name): VolumeConfig;

    /**
     * @return array<string, VolumeConfig>
     */
    public function getVolumes(): array;
}
