<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use League\Flysystem\DirectoryListing as FlysystemListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\EventListener\CacheInvalidationListener;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\StorageFactoryInterface;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DirectoryCacheTtlTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_cache_ttl_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/local', 0775, true);
        mkdir($this->tempDir . '/nocache', 0775, true);
        file_put_contents($this->tempDir . '/local/a.txt', 'hello');
        file_put_contents($this->tempDir . '/nocache/b.txt', 'world');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/local/a.txt');
        @unlink($this->tempDir . '/local/c.txt');
        @unlink($this->tempDir . '/nocache/b.txt');
        @rmdir($this->tempDir . '/local');
        @rmdir($this->tempDir . '/nocache');
        @rmdir($this->tempDir);
    }

    public function testProfileCacheOverrideAndVolumeCacheTtlPrecedence(): void
    {
        $provider = new YamlConfigProvider(
            defaults: [
                'cache' => ['enabled' => true, 'ttl' => 3600],
            ],
            volumes: [
                'local_fast' => [
                    'label' => 'Local',
                    'group' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->tempDir . '/local',
                ],
                'vol_no_cache' => [
                    'label' => 'No Cache Vol',
                    'group' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->tempDir . '/nocache',
                    'cache_ttl' => 0,
                ],
                'cloud_short' => [
                    'label' => 'Cloud',
                    'group' => 'network',
                    'driver' => 'AwsS3',
                    'cache_ttl' => 60,
                    'driver_options' => ['bucket' => 'test'],
                ],
            ],
            profiles: [
                'default' => [
                    'volumes' => ['local_fast', 'vol_no_cache'],
                ],
                'custom_profile' => [
                    'volumes' => ['local_fast'],
                    'cache' => ['enabled' => true, 'ttl' => 120],
                ],
                'disabled_profile' => [
                    'volumes' => ['local_fast'],
                    'cache' => ['enabled' => false],
                ],
            ],
        );

        self::assertNull($provider->getVolume('local_fast')->cacheTtl);
        self::assertSame(0, $provider->getVolume('vol_no_cache')->cacheTtl);
        self::assertSame(60, $provider->getVolume('cloud_short')->cacheTtl);

        self::assertSame(3600, $provider->getProfile('default')->cache['ttl']);
        self::assertSame(120, $provider->getProfile('custom_profile')->cache['ttl']);
        self::assertFalse($provider->getProfile('disabled_profile')->cache['enabled']);

        $pool = new ArrayAdapter();
        $cacheManager = new DirectoryCacheManager($pool);
        $lister = new DirectoryLister(
            new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver())),
            new PathNormalizer(),
            $cacheManager,
            new NullMetadataStorage(),
        );

        $defaultProfile = $provider->getProfile('default');

        // 1. Volume with inherited TTL caches on second call
        $first = $lister->list($defaultProfile, 'local_fast');
        $second = $lister->list($defaultProfile, 'local_fast');
        self::assertFalse($first->fromCache);
        self::assertTrue($second->fromCache);

        // 2. External file created -> forceRefresh=true bypasses & refreshes server cache
        file_put_contents($this->tempDir . '/local/c.txt', 'external');
        $stale = $lister->list($defaultProfile, 'local_fast');
        self::assertSame(1, $stale->total);

        $refreshed = $lister->list($defaultProfile, 'local_fast', forceRefresh: true);
        self::assertFalse($refreshed->fromCache);
        self::assertSame(2, $refreshed->total);

        $cachedAfterRefresh = $lister->list($defaultProfile, 'local_fast');
        self::assertTrue($cachedAfterRefresh->fromCache);
        self::assertSame(2, $cachedAfterRefresh->total);

        // 3. Volume with cache_ttl: 0 never hits cache
        $noCache1 = $lister->list($defaultProfile, 'vol_no_cache');
        $noCache2 = $lister->list($defaultProfile, 'vol_no_cache');
        self::assertFalse($noCache1->fromCache);
        self::assertFalse($noCache2->fromCache);

        // 4. Profile with cache.enabled: false never hits cache
        $disabledProfile = $provider->getProfile('disabled_profile');
        $off1 = $lister->list($disabledProfile, 'local_fast');
        $off2 = $lister->list($disabledProfile, 'local_fast');
        self::assertFalse($off1->fromCache);
        self::assertFalse($off2->fromCache);
    }

    public function testResolveExistingPathWalksUpWhenDirectoryDeletedExternally(): void
    {
        mkdir($this->tempDir . '/local/level1/level2', 0775, true);

        $provider = new YamlConfigProvider(
            defaults: ['cache' => ['enabled' => true, 'ttl' => 3600]],
            volumes: [
                'local_fast' => [
                    'label' => 'Local',
                    'group' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->tempDir . '/local',
                ],
            ],
            profiles: ['default' => ['volumes' => ['local_fast']]],
        );

        $pool = new ArrayAdapter();
        $lister = new DirectoryLister(
            new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver())),
            new PathNormalizer(),
            new DirectoryCacheManager($pool),
            new NullMetadataStorage(),
        );

        $profile = $provider->getProfile('default');
        $volume = $provider->getVolume('local_fast');

        // Existing folder stays intact
        $ok = $lister->resolveExistingPath($profile, $volume, 'level1/level2');
        self::assertSame('level1/level2', $ok['path']);
        self::assertFalse($ok['fallbackApplied']);

        // Delete leaf folder externally (e.g. via FTP) -> falls back to nearest existing parent (level1)
        @rmdir($this->tempDir . '/local/level1/level2');
        $parentFallback = $lister->resolveExistingPath($profile, $volume, 'level1/level2');
        self::assertSame('level1', $parentFallback['path']);
        self::assertTrue($parentFallback['fallbackApplied']);

        // Delete remaining parent -> falls back cleanly to root ('')
        @rmdir($this->tempDir . '/local/level1');
        $rootFallback = $lister->resolveExistingPath($profile, $volume, 'level1/level2');
        self::assertSame('', $rootFallback['path']);
        self::assertTrue($rootFallback['fallbackApplied']);
    }

    public function testS3AndFlysystemRootDeletionImmediatelyInvalidatesCache(): void
    {
        $normalizer = new PathNormalizer();
        $provider = new YamlConfigProvider(
            defaults: ['cache' => ['enabled' => true, 'ttl' => 3600]],
            volumes: [
                'supabase_s3' => [
                    'label' => 'Supabase',
                    'group' => 'network',
                    'driver' => 'AwsS3',
                    'cache_ttl' => 60,
                    'driver_options' => ['bucket' => 'baski'],
                ],
                'sftp_vol' => [
                    'label' => 'SFTP',
                    'group' => 'network',
                    'driver' => 'FlysystemService',
                    'cache_ttl' => 60,
                    'driver_options' => ['service' => 'app.sftp'],
                ],
            ],
            profiles: [
                'default' => ['volumes' => ['supabase_s3', 'sftp_vol']],
            ],
        );

        $profile = $provider->getProfile('default');
        $s3Vol = $provider->getVolume('supabase_s3');
        $sftpVol = $provider->getVolume('sftp_vol');

        // Canonical keys for root must NOT have a trailing slash mismatch!
        self::assertSame('s3::baski', $normalizer->buildCanonicalKey($s3Vol, ''));
        self::assertSame('s3::baski/fatura.pdf', $normalizer->buildCanonicalKey($s3Vol, 'fatura.pdf'));
        self::assertSame('flysystemservice::sftp_vol', $normalizer->buildCanonicalKey($sftpVol, ''));

        $files = [new FileAttributes('fatura.pdf', 1024), new FileAttributes('katalog.pdf', 2048)];
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('listContents')->willReturnCallback(static function () use (&$files): FlysystemListing {
            return new FlysystemListing($files);
        });

        $factory = $this->createStub(StorageFactoryInterface::class);
        $factory->method('create')->willReturn($fs);

        $cacheManager = new DirectoryCacheManager(new ArrayAdapter());
        $listener = new CacheInvalidationListener($cacheManager, $normalizer);
        $lister = new DirectoryLister(
            new VolumeRegistry($provider, $factory),
            $normalizer,
            $cacheManager,
            new NullMetadataStorage(),
        );

        // 1. Prime cache for Supabase root
        $first = $lister->list($profile, 'supabase_s3', '');
        self::assertFalse($first->fromCache);
        self::assertSame(2, $first->total);

        $cached = $lister->list($profile, 'supabase_s3', '');
        self::assertTrue($cached->fromCache);
        self::assertSame(2, $cached->total);

        // 2. Simulate deleting root file "fatura.pdf" -> FileDeletedEvent invalidates Supabase root cache IMMEDIATELY!
        $files = [new FileAttributes('katalog.pdf', 2048)];
        $canonical = $normalizer->buildCanonicalKey($s3Vol, 'fatura.pdf', $profile->basePath);
        $listener->onMutate(new FileDeletedEvent($profile, $s3Vol, 'fatura.pdf', $canonical, true));

        $afterDelete = $lister->list($profile, 'supabase_s3', '');
        self::assertFalse($afterDelete->fromCache, 'Supabase root cache MUST be invalidated immediately on root file delete.');
        self::assertSame(1, $afterDelete->total);
        self::assertSame('katalog.pdf', $afterDelete->items[0]->name);
    }
}
