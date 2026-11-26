<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Storage;

use ArrayAccess;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class FlysystemStorageFactory implements StorageFactoryInterface, ResetInterface
{
    /**
     * @var array<string, FilesystemOperator>
     */
    private array $instances = [];

    public function __construct(
        private readonly DynamicPathResolver $pathResolver,
        private readonly ?ContainerInterface $serviceLocator = null,
    ) {
    }

    public function reset(): void
    {
        $this->instances = [];
    }

    public function create(VolumeConfig $volume): FilesystemOperator
    {
        $cacheKey = $this->buildCacheKey($volume);
        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $operator = match ($volume->driver) {
            'LocalFileSystem' => $this->createLocalFilesystem($volume),
            'AwsS3' => $this->createS3Filesystem($volume),
            'FlysystemService' => $this->resolveFlysystemService($volume),
            default => throw new FileManagerException(sprintf('Unsupported storage driver "%s".', $volume->driver)),
        };

        if (count($this->instances) >= 64) {
            $this->instances = array_slice($this->instances, -32, null, true);
        }

        $this->instances[$cacheKey] = $operator;

        return $operator;
    }

    private function buildCacheKey(VolumeConfig $volume): string
    {
        $resolvedPath = $volume->path !== null ? $this->pathResolver->resolve($volume->path) : '';
        $rawPrefix = $volume->driverOptions['prefix'] ?? null;
        $resolvedPrefix = is_string($rawPrefix) && $rawPrefix !== '' ? $this->pathResolver->resolve($rawPrefix) : '';
        $optionsHash = $this->hashDriverOptions($volume->driverOptions);

        return $volume->name . '::' . $volume->driver . '::' . $resolvedPath . '::' . $resolvedPrefix . '::' . $optionsHash;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function hashDriverOptions(array $options): string
    {
        if ($options === []) {
            return '0';
        }

        $relevantKeys = [
            'bucket', 'endpoint', 'region', 'version', 'key', 'secret', 'token',
            'service', 'acl', 'disable_acl', 'retain_visibility', 'use_path_style_endpoint',
            'ca_bundle', 'request_checksum_calculation', 'response_checksum_validation',
        ];
        $normalized = [];
        foreach ($relevantKeys as $k) {
            if (array_key_exists($k, $options)) {
                $normalized[$k] = $options[$k];
            }
        }

        return hash('sha256', (string) json_encode($normalized));
    }

    private function createLocalFilesystem(VolumeConfig $volume): FilesystemOperator
    {
        if ($volume->path === null || $volume->path === '') {
            throw new FileManagerException(sprintf('Volume "%s" requires a non-empty local path.', $volume->name));
        }

        $resolvedPath = $this->pathResolver->resolve($volume->path);
        if (!is_dir($resolvedPath)) {
            if (!@mkdir($resolvedPath, 0775, true) && !is_dir($resolvedPath)) {
                throw new FileManagerException('Unable to initialize storage directory.', 'errors.storage_unavailable');
            }
        }

        return new Filesystem(new ConfinedLocalFilesystemAdapter($resolvedPath));
    }

    private function createS3Filesystem(VolumeConfig $volume): FilesystemOperator
    {
        if (!class_exists(AwsS3V3Adapter::class) || !class_exists(S3Client::class)) {
            throw new FileManagerException('To use AwsS3 driver, install "league/flysystem-aws-s3-v3" and "aws/aws-sdk-php".');
        }

        $options = $volume->driverOptions;
        $bucket = $options['bucket'] ?? null;
        if (!is_string($bucket) || $bucket === '') {
            throw new FileManagerException(sprintf('AwsS3 volume "%s" requires a "bucket" option.', $volume->name));
        }

        $clientConfig = $this->buildS3ClientConfig($options);
        $prefix = is_string($options['prefix'] ?? null) ? $this->pathResolver->resolve($options['prefix']) : '';
        $client = new S3Client($clientConfig);

        $hasCustomEndpoint = isset($clientConfig['endpoint']);
        $hasExplicitAcl = is_string($options['acl'] ?? null) && $options['acl'] !== '';
        $disableAcl = isset($options['disable_acl'])
            ? (bool) $options['disable_acl']
            : ($hasCustomEndpoint && !$hasExplicitAcl);
        if ($disableAcl) {
            $this->attachStripAclMiddleware($client);
        }

        $filesystemConfig = [
            Config::OPTION_RETAIN_VISIBILITY => isset($options['retain_visibility']) && (bool) $options['retain_visibility'],
        ];
        if ($hasExplicitAcl) {
            $filesystemConfig['ACL'] = $options['acl'];
        }

        return new Filesystem(new AwsS3V3Adapter($client, $bucket, $prefix), $filesystemConfig);
    }

    private function attachStripAclMiddleware(S3Client $client): void
    {
        $client->getHandlerList()->appendInit(static function (callable $handler): callable {
            return static function ($command, $request = null) use ($handler) {
                if ($command instanceof ArrayAccess && isset($command['ACL'])) {
                    unset($command['ACL']);
                }

                return $handler($command, $request);
            };
        }, 'liveux_fm_strip_acl');
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildS3ClientConfig(array $options): array
    {
        $version = $options['version'] ?? null;
        $region = $options['region'] ?? null;

        $clientConfig = [
            'version' => is_string($version) && $version !== '' ? $version : 'latest',
            'region' => is_string($region) && $region !== '' ? $region : 'us-east-1',
        ];

        $endpoint = $options['endpoint'] ?? null;
        $hasCustomEndpoint = is_string($endpoint) && $endpoint !== '';
        if ($hasCustomEndpoint) {
            $clientConfig['endpoint'] = $endpoint;
        }

        $clientConfig = $this->applyS3ChecksumOptions($clientConfig, $options, $hasCustomEndpoint);

        if (isset($options['use_path_style_endpoint'])) {
            $clientConfig['use_path_style_endpoint'] = (bool) $options['use_path_style_endpoint'];
        }

        $key = $options['key'] ?? null;
        $secret = $options['secret'] ?? null;
        $token = $options['token'] ?? null;
        if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
            $creds = [
                'key' => $key,
                'secret' => $secret,
            ];
            if (is_string($token) && $token !== '') {
                $creds['token'] = $token;
            }
            $clientConfig['credentials'] = $creds;
        }

        $caBundle = $options['ca_bundle'] ?? null;
        if (is_string($caBundle) && $caBundle !== '') {
            $clientConfig['http']['verify'] = $caBundle;
        }

        return $clientConfig;
    }

    /**
     * Compatibility with aws/aws-sdk-php >= 3.337 on S3-compatible providers
     * (Cloudflare R2, Backblaze B2, Supabase Storage, MinIO, RustFS).
     *
     * @param array<string, mixed> $clientConfig
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function applyS3ChecksumOptions(array $clientConfig, array $options, bool $hasCustomEndpoint): array
    {
        $requestChecksum = $options['request_checksum_calculation'] ?? null;
        if (is_string($requestChecksum) && $requestChecksum !== '') {
            $clientConfig['request_checksum_calculation'] = $requestChecksum;
        } elseif ($hasCustomEndpoint) {
            $clientConfig['request_checksum_calculation'] = 'when_required';
        }

        $responseChecksum = $options['response_checksum_validation'] ?? null;
        if (is_string($responseChecksum) && $responseChecksum !== '') {
            $clientConfig['response_checksum_validation'] = $responseChecksum;
        } elseif ($hasCustomEndpoint) {
            $clientConfig['response_checksum_validation'] = 'when_required';
        }

        return $clientConfig;
    }

    private function resolveFlysystemService(VolumeConfig $volume): FilesystemOperator
    {
        $serviceId = $volume->driverOptions['service'] ?? null;
        if (!is_string($serviceId) || $serviceId === '') {
            throw new FileManagerException(sprintf('FlysystemService volume "%s" requires a "service" option.', $volume->name));
        }

        if ($this->serviceLocator === null || !$this->serviceLocator->has($serviceId)) {
            throw new FileManagerException('Configured storage service is unavailable.', 'errors.storage_unavailable');
        }

        $service = $this->serviceLocator->get($serviceId);
        if (!$service instanceof FilesystemOperator) {
            throw new FileManagerException(sprintf('Service "%s" must implement %s.', $serviceId, FilesystemOperator::class));
        }

        return $service;
    }
}
