<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Config;

use LiveUX\FileManagerBundle\Exception\FileManagerException;

final class ChainConfigProvider implements ConfigProviderInterface
{
    /**
     * @var list<ConfigProviderInterface>
     */
    private array $providers;

    /**
     * @param iterable<ConfigProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    public function hasProfile(string $name): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasProfile($name)) {
                return true;
            }
        }

        return false;
    }

    public function getProfile(string $name): ProfileConfig
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasProfile($name)) {
                return $provider->getProfile($name);
            }
        }

        throw new FileManagerException(
            sprintf('FileManager profile "%s" was not found in any ConfigProvider.', $name),
            'errors.profile_not_found',
            ['%profile%' => $name],
        );
    }

    public function getProfileNames(): array
    {
        $names = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getProfileNames() as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    public function hasVolume(string $name): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasVolume($name)) {
                return true;
            }
        }

        return false;
    }

    public function getVolume(string $name): VolumeConfig
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasVolume($name)) {
                return $provider->getVolume($name);
            }
        }

        throw new FileManagerException(
            sprintf('FileManager volume "%s" was not found in any ConfigProvider.', $name),
            'errors.volume_not_found',
            ['%volume%' => $name],
        );
    }

    public function getVolumes(): array
    {
        $volumes = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getVolumes() as $name => $volume) {
                if (!isset($volumes[$name])) {
                    $volumes[$name] = $volume;
                }
            }
        }

        return $volumes;
    }
}
