<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class AssetUsageGuardTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_usage_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/katalog', 0775, true);
        file_put_contents($this->tempDir . '/katalog/termos.jpg', 'img-data');
        file_put_contents($this->tempDir . '/katalog/unused.jpg', 'unused-data');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testConfigurationDefaultsAreOptInAndConfigurablePerProfile(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [],
                'strict_catalog' => [
                    'usage_guard' => [
                        'enabled' => true,
                        'policy' => 'block',
                        'max_references' => 15,
                    ],
                ],
            ],
        ]]);

        self::assertFalse($config['profiles']['default']['usage_guard']['enabled']);
        self::assertSame('warn', $config['profiles']['default']['usage_guard']['policy']);
        self::assertSame(20, $config['profiles']['default']['usage_guard']['max_references']);

        self::assertTrue($config['profiles']['strict_catalog']['usage_guard']['enabled']);
        self::assertSame('block', $config['profiles']['strict_catalog']['usage_guard']['policy']);
        self::assertSame(15, $config['profiles']['strict_catalog']['usage_guard']['max_references']);
    }

    public function testDisabledOrNoCheckersIsSafeNoOp(): void
    {
        $provider = $this->createProvider([
            'disabled' => ['usage_guard' => ['enabled' => false]],
            'enabled_no_checkers' => ['usage_guard' => ['enabled' => true, 'policy' => 'block']],
        ]);
        $registry = $this->createRegistry($provider);
        $normalizer = new PathNormalizer();

        $guardWithoutCheckers = new AssetUsageGuardService($registry, $normalizer, new FileSecurityGuard(), []);
        self::assertFalse($guardWithoutCheckers->hasCheckers());
        self::assertFalse($guardWithoutCheckers->isEnabledForProfile($provider->getProfile('enabled_no_checkers')));

        $report = $guardWithoutCheckers->inspectPaths($provider->getProfile('enabled_no_checkers'), ['katalog/termos.jpg']);
        self::assertFalse($report['enabled']);
        self::assertFalse($report['blocked']);
        self::assertSame(0, $report['totalUsages']);
    }

    public function testAggregatesDeduplicatesSanitizesUrlsAndCapsReferences(): void
    {
        $provider = $this->createProvider([
            'warn_profile' => [
                'usage_guard' => [
                    'enabled' => true,
                    'policy' => 'warn',
                    'max_references' => 3,
                ],
            ],
        ]);
        $registry = $this->createRegistry($provider);
        $normalizer = new PathNormalizer();

        $checkerA = new class () implements AssetUsageCheckerInterface {
            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                if ($isFolder && $path === 'katalog') {
                    return [
                        new AssetUsageReference('VIP Termos (#42)', 'Ürün', '/panel/urun/42/duzenle', '42'),
                        new AssetUsageReference('Kötü Link', 'XSS', 'javascript:alert(1)'),
                    ];
                }

                return [];
            }
        };

        $checkerB = new class () implements AssetUsageCheckerInterface {
            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                if ($isFolder && $path === 'katalog') {
                    return [
                        // Duplicate of checkerA -> should be deduplicated
                        new AssetUsageReference('VIP Termos (#42)', 'Ürün', '/panel/urun/42/duzenle', '42'),
                        new AssetUsageReference('Anasayfa Banner', 'Banner', 'https://example.com/banner/1', '1'),
                        new AssetUsageReference('Protocol Relative', 'Slider', '//evil.example/steal'),
                    ];
                }

                return [];
            }
        };

        $guard = new AssetUsageGuardService($registry, $normalizer, new FileSecurityGuard(), [$checkerA, $checkerB]);
        $profile = $provider->getProfile('warn_profile');

        self::assertTrue($guard->isEnabledForProfile($profile));

        $report = $guard->inspectPaths($profile, ['katalog']);
        self::assertTrue($report['enabled']);
        self::assertSame('warn', $report['policy']);
        self::assertFalse($report['blocked']);
        self::assertTrue($report['truncated']);
        self::assertSame(3, $report['totalUsages']);
        self::assertCount(1, $report['items']);
        self::assertTrue($report['items'][0]['isFolder']);

        $refs = $report['items'][0]['references'];
        self::assertSame('/panel/urun/42/duzenle', $refs[0]['editUrl']);
        self::assertNull($refs[1]['editUrl']); // javascript: stripped!
        self::assertSame('https://example.com/banner/1', $refs[2]['editUrl']);
    }

    public function testBlockPolicyPreventsServerSideDeleteButAllowsUnusedFiles(): void
    {
        $provider = $this->createProvider([
            'strict' => [
                'usage_guard' => ['enabled' => true, 'policy' => 'block'],
                'trash' => ['enabled' => false],
            ],
            'warn' => [
                'usage_guard' => ['enabled' => true, 'policy' => 'warn'],
                'trash' => ['enabled' => false],
            ],
        ]);
        $registry = $this->createRegistry($provider);
        $normalizer = new PathNormalizer();

        $checker = new class () implements AssetUsageCheckerInterface {
            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                if ($path === 'katalog/termos.jpg') {
                    return [new AssetUsageReference('VIP Çelik Termos (#42)', 'Ürün', '/panel/urun/42')];
                }

                return [];
            }
        };

        $dispatcher = new EventDispatcher();
        $guard = new AssetUsageGuardService($registry, $normalizer, new FileSecurityGuard(), [$checker]);
        $ops = new FileOperationsService(
            $registry,
            $normalizer,
            new FilenameNormalizer(),
            new TrashManager($registry, $normalizer, $dispatcher),
            new JsonFileMetadataStorage($this->tempDir . '/meta.json'),
            $dispatcher,
            null,
            new FileSecurityGuard(),
            $guard,
        );

        // 1. Strict block policy MUST reject deleting an in-use file and keep it on disk
        try {
            $ops->delete($provider->getProfile('strict'), 'katalog/termos.jpg');
            self::fail('Expected FileManagerException when deleting an in-use file under block policy.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.delete_blocked_in_use', $e->getTranslationKey());
            self::assertSame(['%name%' => 'termos.jpg', '%count%' => 1], $e->getTranslationParams());
        }
        self::assertFileExists($this->tempDir . '/katalog/termos.jpg');

        // 2. Unused file under strict block policy CAN be deleted
        $ops->delete($provider->getProfile('strict'), 'katalog/unused.jpg');
        self::assertFileDoesNotExist($this->tempDir . '/katalog/unused.jpg');

        // 3. Warn policy allows deleting even if referenced (user confirmed in UI)
        $ops->delete($provider->getProfile('warn'), 'katalog/termos.jpg');
        self::assertFileDoesNotExist($this->tempDir . '/katalog/termos.jpg');
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function createProvider(array $profiles): YamlConfigProvider
    {
        $configuredProfiles = [];
        foreach ($profiles as $name => $cfg) {
            $configuredProfiles[$name] = array_merge(['volumes' => ['local']], $cfg);
        }

        return new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            [
                'local' => [
                    'label' => 'Local',
                    'type' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->tempDir,
                    'url_prefix' => '/uploads',
                    'private' => false,
                    'options' => [],
                ],
            ],
            $configuredProfiles,
        );
    }

    private function createRegistry(YamlConfigProvider $provider): VolumeRegistry
    {
        return new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
    }
}
