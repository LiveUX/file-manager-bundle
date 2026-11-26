<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Config;

use LiveUX\FileManagerBundle\Exception\FileManagerException;

final readonly class YamlConfigProvider implements ConfigProviderInterface
{
    /**
     * @param array<string, mixed>               $defaults
     * @param array<string, array<string, mixed>> $volumes
     * @param array<string, array<string, mixed>> $profiles
     */
    public function __construct(
        private array $defaults,
        private array $volumes,
        private array $profiles,
    ) {
    }

    public function hasProfile(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function getProfile(string $name): ProfileConfig
    {
        if (!isset($this->profiles[$name])) {
            throw new FileManagerException(
                sprintf('FileManager profile "%s" is not configured.', $name),
                'errors.profile_not_found',
                ['%profile%' => $name],
            );
        }

        return $this->hydrateProfile($name, $this->profiles[$name]);
    }

    public function getProfileNames(): array
    {
        return array_keys($this->profiles);
    }

    public function hasVolume(string $name): bool
    {
        return isset($this->volumes[$name]);
    }

    public function getVolume(string $name): VolumeConfig
    {
        if (!isset($this->volumes[$name])) {
            throw new FileManagerException(
                sprintf('FileManager volume "%s" is not configured.', $name),
                'errors.volume_not_found',
                ['%volume%' => $name],
            );
        }

        return $this->hydrateVolume($name, $this->volumes[$name]);
    }

    public function getVolumes(): array
    {
        $result = [];
        foreach ($this->volumes as $name => $raw) {
            $result[$name] = $this->hydrateVolume($name, $raw);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function hydrateVolume(string $name, array $raw): VolumeConfig
    {
        $label = $raw['label'] ?? \LiveUX\FileManagerBundle\Util\UnicodeHelper::mbUcfirst(str_replace(['_', '-'], ' ', $name));
        $driver = is_string($raw['driver'] ?? null) ? $raw['driver'] : 'LocalFileSystem';
        $group = is_string($raw['group'] ?? null) && $raw['group'] !== ''
            ? $raw['group']
            : ($driver === 'LocalFileSystem' ? 'local' : 'network');

        return new VolumeConfig(
            name: $name,
            label: is_string($label) ? $label : $name,
            group: $group,
            driver: $driver,
            path: is_string($raw['path'] ?? null) ? $raw['path'] : null,
            urlPrefix: is_string($raw['url_prefix'] ?? null) ? $raw['url_prefix'] : null,
            private: (bool) ($raw['private'] ?? false),
            driverOptions: is_array($raw['driver_options'] ?? null) ? $raw['driver_options'] : [],
            icon: is_string($raw['icon'] ?? null) && trim($raw['icon']) !== '' ? trim($raw['icon']) : null,
            cacheTtl: is_int($raw['cache_ttl'] ?? null) ? max(0, $raw['cache_ttl']) : null,
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function hydrateProfile(string $name, array $raw): ProfileConfig
    {
        $ui = is_array($raw['ui'] ?? null) ? $raw['ui'] : [];
        if (!isset($ui['z_index']) || !is_int($ui['z_index'])) {
            $ui['z_index'] = is_int($this->defaults['z_index'] ?? null) ? $this->defaults['z_index'] : 1050;
        }
        if (!is_string($ui['color_scheme'] ?? null) || !in_array($ui['color_scheme'], ['auto', 'light', 'dark'], true)) {
            $defaultScheme = $this->defaults['color_scheme'] ?? 'auto';
            $ui['color_scheme'] = is_string($defaultScheme) && in_array($defaultScheme, ['auto', 'light', 'dark'], true)
                ? $defaultScheme
                : 'auto';
        }
        $theme = is_string($ui['theme'] ?? null) && $ui['theme'] !== ''
            ? $ui['theme']
            : (is_string($this->defaults['theme'] ?? null) ? $this->defaults['theme'] : 'bootstrap5');

        $basePath = is_string($this->defaults['base_path'] ?? null) ? $this->defaults['base_path'] : '';
        $driver = is_string($raw['driver'] ?? null) ? $raw['driver'] : 'LocalFileSystem';
        $resolvedPath = is_string($raw['path'] ?? null) && $raw['path'] !== ''
            ? $raw['path']
            : ($driver === 'LocalFileSystem' ? $basePath : '');
        $resolvedUrlPrefix = is_string($raw['url_prefix'] ?? null)
            ? $raw['url_prefix']
            : ($driver === 'LocalFileSystem' ? '/uploads' : null);

        $resolvedLocale = is_string($raw['locale'] ?? null) && trim($raw['locale']) !== ''
            ? trim($raw['locale'])
            : (is_string($ui['locale'] ?? null) && trim($ui['locale']) !== ''
                ? trim($ui['locale'])
                : (is_string($this->defaults['locale'] ?? null) ? $this->defaults['locale'] : null));

        $zip = is_array($raw['zip'] ?? null) ? $raw['zip'] : [];
        $permissions = is_array($raw['permissions'] ?? null) ? $raw['permissions'] : [];
        $allowExtract = ($zip['allow_extract'] ?? false) === true;
        $rawExtractPerm = $permissions['extract_zip'] ?? null;
        if (!$allowExtract && ($rawExtractPerm === null || $rawExtractPerm === true)) {
            $permissions['extract_zip'] = false;
        } elseif ($rawExtractPerm === null) {
            $permissions['extract_zip'] = true;
        }

        return new ProfileConfig(
            name: $name,
            basePath: $basePath,
            theme: $theme,
            locale: $resolvedLocale,
            volumes: is_array($raw['volumes'] ?? null) ? array_values($raw['volumes']) : [],
            driver: $driver,
            path: $resolvedPath,
            urlPrefix: $resolvedUrlPrefix,
            private: (bool) ($raw['private'] ?? false),
            driverOptions: is_array($raw['driver_options'] ?? null) ? $raw['driver_options'] : [],
            ignoreDotFiles: (bool) ($raw['ignore_dot_files'] ?? true),
            excludePatterns: is_array($raw['exclude_patterns'] ?? null) ? array_values($raw['exclude_patterns']) : [],
            sortBy: is_string($raw['sort_by'] ?? null) ? $raw['sort_by'] : 'name',
            sortDirection: is_string($raw['sort_direction'] ?? null) ? $raw['sort_direction'] : 'asc',
            foldersFirst: (bool) ($raw['folders_first'] ?? true),
            uploadSubfolder: is_string($raw['upload_subfolder'] ?? null) ? $raw['upload_subfolder'] : null,
            uploadAllow: is_array($raw['upload_allow'] ?? null) ? array_values($raw['upload_allow']) : [],
            uploadDeny: is_array($raw['upload_deny'] ?? null) ? array_values($raw['upload_deny']) : [],
            uploadMaxSize: is_string($raw['upload_max_size'] ?? null) ? $raw['upload_max_size'] : '10M',
            overwritePolicy: is_string($raw['overwrite_policy'] ?? null) ? $raw['overwrite_policy'] : 'rename',
            chunkUpload: is_array($raw['chunk_upload'] ?? null) ? $raw['chunk_upload'] : [],
            naming: is_array($raw['naming'] ?? null) ? $raw['naming'] : [],
            imageOptimization: is_array($raw['image_optimization'] ?? null) ? $raw['image_optimization'] : [],
            thumbnail: is_array($raw['thumbnail'] ?? null) ? $raw['thumbnail'] : [],
            trash: is_array($raw['trash'] ?? null) ? $raw['trash'] : [],
            zip: $zip,
            ui: $ui,
            permissions: $permissions,
            security: is_array($raw['security'] ?? null) ? $raw['security'] : [],
            cache: $this->mergeProfileCache($raw),
            metadata: is_array($this->defaults['metadata'] ?? null) ? $this->defaults['metadata'] : [],
            storageQuota: is_string($raw['storage_quota'] ?? null) && trim($raw['storage_quota']) !== '' ? trim($raw['storage_quota']) : null,
            duplicateDetection: is_array($raw['duplicate_detection'] ?? null) ? $raw['duplicate_detection'] : [],
            share: is_array($raw['share'] ?? null) ? $raw['share'] : [],
            ai: is_array($raw['ai'] ?? null) ? $raw['ai'] : [],
            usageGuard: is_array($raw['usage_guard'] ?? null) ? $raw['usage_guard'] : [],
            imageConstraints: is_array($raw['image_constraints'] ?? null) ? $raw['image_constraints'] : [],
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function mergeProfileCache(array $raw): array
    {
        $merged = is_array($this->defaults['cache'] ?? null) ? $this->defaults['cache'] : [];
        $override = is_array($raw['cache'] ?? null) ? $raw['cache'] : [];

        if (is_bool($override['enabled'] ?? null)) {
            $merged['enabled'] = $override['enabled'];
        }
        if (is_int($override['ttl'] ?? null)) {
            $merged['ttl'] = max(0, $override['ttl']);
        }

        return $merged;
    }
}
