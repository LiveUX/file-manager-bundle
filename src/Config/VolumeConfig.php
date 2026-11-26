<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Config;

final readonly class VolumeConfig
{
    /**
     * @param array<string, mixed> $driverOptions
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $group,
        public string $driver,
        public ?string $path = null,
        public ?string $urlPrefix = null,
        public bool $private = false,
        public array $driverOptions = [],
        public ?string $icon = null,
        public ?int $cacheTtl = null,
    ) {
    }

    public function isNetwork(): bool
    {
        return $this->group === 'network';
    }

    public function isLocal(): bool
    {
        return $this->group === 'local';
    }

    public function isLocalFilesystem(): bool
    {
        return $this->driver === 'LocalFileSystem';
    }

    /**
     * Resolves the UI SVG icon key for this volume (explicit config icon or auto-detected provider icon).
     */
    public function resolveIconKey(): string
    {
        if ($this->icon !== null && $this->icon !== '') {
            $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($this->icon));
            if (is_string($sanitized) && $sanitized !== '') {
                return $sanitized;
            }
        }

        if ($this->driver === 'LocalFileSystem') {
            return 'disk';
        }

        if ($this->driver === 'AwsS3') {
            return $this->detectS3IconKey();
        }

        if ($this->driver === 'FlysystemService') {
            return $this->detectFlysystemIconKey();
        }

        return $this->isNetwork() ? 'cloud' : 'disk';
    }

    private function detectS3IconKey(): string
    {
        $endpoint = is_string($this->driverOptions['endpoint'] ?? null)
            ? strtolower(trim($this->driverOptions['endpoint']))
            : '';

        if ($endpoint === '' || str_contains($endpoint, 'amazonaws.com')) {
            return 'aws_s3';
        }
        if (str_contains($endpoint, 'r2.cloudflarestorage.com')) {
            return 'cloudflare_r2';
        }
        if (str_contains($endpoint, 'supabase.co') || str_contains($endpoint, 'supabase.in')) {
            return 'supabase';
        }
        if (str_contains($endpoint, 'backblazeb2.com')) {
            return 'backblaze_b2';
        }
        if (str_contains($endpoint, 'storage.googleapis.com')) {
            return 'gcs';
        }
        if (str_contains($endpoint, 'minio') || str_contains($endpoint, ':9000')) {
            return 'minio';
        }

        return 'cloud';
    }

    private function detectFlysystemIconKey(): string
    {
        $service = is_string($this->driverOptions['service'] ?? null)
            ? strtolower(trim($this->driverOptions['service']))
            : '';

        if (str_contains($service, 'sftp') || str_contains($service, 'ftp')) {
            return 'sftp';
        }
        if (str_contains($service, 'gcs') || str_contains($service, 'google')) {
            return 'gcs';
        }
        if (str_contains($service, 's3') || str_contains($service, 'aws')) {
            return 'aws_s3';
        }

        return $this->isNetwork() ? 'cloud' : 'disk';
    }
}
