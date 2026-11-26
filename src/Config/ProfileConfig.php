<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Config;

final readonly class ProfileConfig
{
    /**
     * @param list<string>         $volumes
     * @param list<string>         $excludePatterns
     * @param list<string>         $uploadAllow
     * @param list<string>         $uploadDeny
     * @param array<string, mixed> $chunkUpload
     * @param array<string, mixed> $naming
     * @param array<string, mixed> $imageOptimization
     * @param array<string, mixed> $thumbnail
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $zip
     * @param array<string, mixed> $ui
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $security
     * @param array<string, mixed> $cache
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $driverOptions
     * @param array<string, mixed> $duplicateDetection
     * @param array<string, mixed> $share
     * @param array<string, mixed> $ai
     * @param array<string, mixed> $usageGuard
     * @param array<string, mixed> $imageConstraints
     */
    public function __construct(
        public string $name,
        public string $basePath,
        public string $theme,
        public ?string $locale,
        public array $volumes,
        public string $driver,
        public string $path,
        public ?string $urlPrefix,
        public bool $private,
        public array $driverOptions,
        public bool $ignoreDotFiles,
        public array $excludePatterns,
        public string $sortBy,
        public string $sortDirection,
        public bool $foldersFirst,
        public ?string $uploadSubfolder,
        public array $uploadAllow,
        public array $uploadDeny,
        public string $uploadMaxSize,
        public string $overwritePolicy,
        public array $chunkUpload,
        public array $naming,
        public array $imageOptimization,
        public array $thumbnail,
        public array $trash,
        public array $zip,
        public array $ui,
        public array $permissions,
        public array $security,
        public array $cache,
        public array $metadata,
        public ?string $storageQuota = null,
        public array $duplicateDetection = [],
        public array $share = [],
        public array $ai = [],
        public array $usageGuard = [],
        public array $imageConstraints = [],
    ) {
    }

    public function getPermissionRule(string $action): mixed
    {
        if (!array_key_exists($action, $this->permissions) || ($action === 'extract_zip' && $this->permissions[$action] === null)) {
            return $action === 'extract_zip'
                ? (($this->zip['allow_extract'] ?? false) === true)
                : true;
        }

        return $this->permissions[$action];
    }

    public function isVoterEnabled(): bool
    {
        return ($this->security['voter_enabled'] ?? true) === true;
    }

    /**
     * Returns a copy of this profile with overridden image_optimization settings without dropping any field.
     *
     * @param array<string, mixed> $imageOptimization
     */
    public function withImageOptimization(array $imageOptimization): self
    {
        return new self(
            name: $this->name,
            basePath: $this->basePath,
            theme: $this->theme,
            locale: $this->locale,
            volumes: $this->volumes,
            driver: $this->driver,
            path: $this->path,
            urlPrefix: $this->urlPrefix,
            private: $this->private,
            driverOptions: $this->driverOptions,
            ignoreDotFiles: $this->ignoreDotFiles,
            excludePatterns: $this->excludePatterns,
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            foldersFirst: $this->foldersFirst,
            uploadSubfolder: $this->uploadSubfolder,
            uploadAllow: $this->uploadAllow,
            uploadDeny: $this->uploadDeny,
            uploadMaxSize: $this->uploadMaxSize,
            overwritePolicy: $this->overwritePolicy,
            chunkUpload: $this->chunkUpload,
            naming: $this->naming,
            imageOptimization: $imageOptimization,
            thumbnail: $this->thumbnail,
            trash: $this->trash,
            zip: $this->zip,
            ui: $this->ui,
            permissions: $this->permissions,
            security: $this->security,
            cache: $this->cache,
            metadata: $this->metadata,
            storageQuota: $this->storageQuota,
            duplicateDetection: $this->duplicateDetection,
            share: $this->share,
            ai: $this->ai,
            usageGuard: $this->usageGuard,
            imageConstraints: $this->imageConstraints,
        );
    }
}
