<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Storage;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FlysystemStorageFactoryTest extends TestCase
{
    public function testCustomEndpointDefaultsChecksumsToWhenRequired(): void
    {
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $config = $this->invokeBuildS3ClientConfig($factory, [
            'bucket' => 'cdn-assets',
            'region' => 'auto',
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
            'key' => 'r2-access-key',
            'secret' => 'r2-secret-key',
        ]);

        self::assertSame('latest', $config['version']);
        self::assertSame('auto', $config['region']);
        self::assertSame('https://account-id.r2.cloudflarestorage.com', $config['endpoint']);
        self::assertTrue($config['use_path_style_endpoint']);
        self::assertSame('when_required', $config['request_checksum_calculation']);
        self::assertSame('when_required', $config['response_checksum_validation']);
        self::assertSame([
            'key' => 'r2-access-key',
            'secret' => 'r2-secret-key',
        ], $config['credentials']);
    }

    public function testCustomEndpointRespectsExplicitChecksumOverride(): void
    {
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $config = $this->invokeBuildS3ClientConfig($factory, [
            'bucket' => 'b2-bucket',
            'region' => 'eu-central-003',
            'endpoint' => 'https://s3.eu-central-003.backblazeb2.com',
            'request_checksum_calculation' => 'when_supported',
            'response_checksum_validation' => 'when_supported',
        ]);

        self::assertSame('when_supported', $config['request_checksum_calculation']);
        self::assertSame('when_supported', $config['response_checksum_validation']);
    }

    public function testStandardAwsS3LeavesChecksumsUnsetAndSkipsBlankCredentials(): void
    {
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $config = $this->invokeBuildS3ClientConfig($factory, [
            'bucket' => 'aws-media',
            'region' => 'eu-central-1',
            'key' => '',
            'secret' => '',
        ]);

        self::assertSame('eu-central-1', $config['region']);
        self::assertArrayNotHasKey('endpoint', $config);
        self::assertArrayNotHasKey('request_checksum_calculation', $config);
        self::assertArrayNotHasKey('response_checksum_validation', $config);
        self::assertArrayNotHasKey('credentials', $config);
    }

    public function testBuildCacheKeyIncludesResolvedS3Prefix(): void
    {
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $method = new ReflectionMethod($factory, 'buildCacheKey');

        $volumeA = new VolumeConfig(
            name: 'r2',
            label: 'R2',
            group: 'network',
            driver: 'AwsS3',
            driverOptions: ['bucket' => 'assets', 'prefix' => 'tenants/alpha'],
        );
        $volumeB = new VolumeConfig(
            name: 'r2',
            label: 'R2',
            group: 'network',
            driver: 'AwsS3',
            driverOptions: ['bucket' => 'assets', 'prefix' => 'tenants/beta'],
        );

        $keyA = $method->invoke($factory, $volumeA);
        $keyB = $method->invoke($factory, $volumeB);

        self::assertIsString($keyA);
        self::assertIsString($keyB);
        self::assertNotSame($keyA, $keyB);
        self::assertStringContainsString('tenants/alpha', $keyA);
        self::assertStringContainsString('tenants/beta', $keyB);
    }

    public function testCaBundleOptionMapsToHttpVerify(): void
    {
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $config = $this->invokeBuildS3ClientConfig($factory, [
            'bucket' => 'baski',
            'region' => 'eu-central-1',
            'endpoint' => 'https://example.storage.supabase.co/storage/v1/s3',
            'ca_bundle' => '/etc/ssl/certs/cacert.pem',
        ]);

        self::assertSame('/etc/ssl/certs/cacert.pem', $config['http']['verify'] ?? null);
    }

    public function testStripAclMiddlewareRemovesAclFromS3Commands(): void
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            self::markTestSkipped('aws/aws-sdk-php is not installed.');
        }

        $capturedAcl = 'NOT_CALLED';
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'eu-central-1',
            'credentials' => ['key' => 'test', 'secret' => 'secret'],
            'handler' => static function ($cmd) use (&$capturedAcl) {
                $capturedAcl = $cmd['ACL'] ?? null;

                return new \GuzzleHttp\Promise\FulfilledPromise(new \Aws\Result([]));
            },
        ]);

        $attach = new ReflectionMethod($factory, 'attachStripAclMiddleware');
        $attach->invoke($factory, $client);

        $command = $client->getCommand('PutObject', [
            'Bucket' => 'baski',
            'Key' => 'hello.txt',
            'Body' => 'hi',
            'ACL' => 'public-read',
        ]);

        $client->execute($command);
        self::assertNull($capturedAcl);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function invokeBuildS3ClientConfig(FlysystemStorageFactory $factory, array $options): array
    {
        $method = new ReflectionMethod($factory, 'buildS3ClientConfig');
        $result = $method->invoke($factory, $options);
        self::assertIsArray($result);

        return $result;
    }
}
