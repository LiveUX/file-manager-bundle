<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Command;

use LiveUX\FileManagerBundle\Command\CacheClearCommand;
use LiveUX\FileManagerBundle\Command\OptimizeImagesCommand;
use LiveUX\FileManagerBundle\Command\StorageStatsCommand;
use LiveUX\FileManagerBundle\Command\ThumbnailClearCommand;
use LiveUX\FileManagerBundle\Command\ThumbnailWarmupCommand;
use LiveUX\FileManagerBundle\Command\TrashPurgeCommand;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class MaintenanceCommandsTest extends TestCase
{
    private string $tempDir;
    private string $alphaDir;
    private string $betaDir;
    private string $sharedThumbDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_cli_' . bin2hex(random_bytes(4));
        $this->alphaDir = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha';
        $this->betaDir = $this->tempDir . DIRECTORY_SEPARATOR . 'beta';
        $this->sharedThumbDir = $this->tempDir . DIRECTORY_SEPARATOR . 'thumbs';

        mkdir($this->alphaDir, 0775, true);
        mkdir($this->betaDir, 0775, true);
        mkdir($this->sharedThumbDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testThumbnailWarmupAndSharedDirScopedClearAndOrphanPurge(): void
    {
        $env = $this->createEnvironment();
        if ($env['thumbs']->getDiagnostics()['activeDriver'] === 'none') {
            self::markTestSkipped('Neither Imagick nor GD is available on this PHP runtime.');
        }

        $this->createPng($this->alphaDir . DIRECTORY_SEPARATOR . 'hero.png', 300, 150);
        $this->createPng($this->betaDir . DIRECTORY_SEPARATOR . 'banner.png', 300, 150);

        $warmupTester = new CommandTester(new ThumbnailWarmupCommand(
            $env['registry'],
            $env['thumbs'],
            $env['scanner'],
        ));

        // 1. Dry-run generates nothing on disk
        self::assertSame(Command::SUCCESS, $warmupTester->execute(['profile' => 'alpha', '--dry-run' => true]));
        self::assertSame(0, $env['thumbs']->collectCacheStats($this->sharedThumbDir)['files']);

        // 2. Warmup ALL profiles -> 2 thumbs generated
        self::assertSame(Command::SUCCESS, $warmupTester->execute([]));
        self::assertSame(2, $env['thumbs']->collectCacheStats($this->sharedThumbDir)['files']);

        // 3. Re-running without --force skips all cached thumbs
        self::assertSame(Command::SUCCESS, $warmupTester->execute([]));
        self::assertStringContainsString('0 generated, 2 skipped', $warmupTester->getDisplay());

        // 4. Re-running with --force rebuilds alpha only
        self::assertSame(Command::SUCCESS, $warmupTester->execute(['profile' => 'alpha', '--force' => true]));
        self::assertStringContainsString('1 generated, 0 skipped', $warmupTester->getDisplay());

        // 5. Unknown profile fails cleanly
        self::assertSame(Command::FAILURE, $warmupTester->execute(['profile' => 'nonexistent']));

        // 6. Scoped clear on "alpha" must preserve "beta" thumbnail even though they share cache_dir!
        $clearTester = new CommandTester(new ThumbnailClearCommand(
            $env['config'],
            $env['thumbs'],
            $env['scanner'],
        ));
        self::assertSame(Command::SUCCESS, $clearTester->execute(['profile' => 'alpha', '--dry-run' => true]));
        self::assertSame(2, $env['thumbs']->collectCacheStats($this->sharedThumbDir)['files']);

        self::assertSame(Command::SUCCESS, $clearTester->execute(['profile' => 'alpha']));
        self::assertSame(1, $env['thumbs']->collectCacheStats($this->sharedThumbDir)['files']);

        // 7. Orphan cleanup deletes stale file while keeping live beta thumbnail intact
        $orphanDir = $this->sharedThumbDir . DIRECTORY_SEPARATOR . 'ff';
        mkdir($orphanDir, 0775, true);
        $orphanFile = $orphanDir . DIRECTORY_SEPARATOR . 'ff' . str_repeat('a', 30) . '.webp';
        file_put_contents($orphanFile, 'stale-thumb');
        self::assertSame(2, $env['thumbs']->collectCacheStats($this->sharedThumbDir)['files']);

        self::assertSame(Command::SUCCESS, $clearTester->execute(['--orphans' => true]));
        self::assertFileDoesNotExist($orphanFile);
        self::assertSame(1, $env['thumbs']->collectCacheStats($this->sharedThumbDir)['files']);
    }

    public function testOptimizeImagesCommandRespectsDryRunAndDownscalesSafely(): void
    {
        $env = $this->createEnvironment();
        if (!$env['optimizer']->getDiagnostics()['available']) {
            self::markTestSkipped('Neither Imagick nor GD is available on this PHP runtime.');
        }

        $imgPath = $this->alphaDir . DIRECTORY_SEPARATOR . 'oversized.png';
        $this->createPng($imgPath, 400, 200);

        $tester = new CommandTester(new OptimizeImagesCommand(
            $env['registry'],
            $env['optimizer'],
            $env['thumbs'],
            $env['cache'],
            $env['scanner'],
        ));

        // Dry-run must NOT modify file dimensions
        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'alpha', '--force' => true, '--dry-run' => true]));
        $before = @getimagesize($imgPath);
        self::assertIsArray($before);
        self::assertSame(400, $before[0]);

        // Real run downscales to max_width=120 while preserving .png filename (even though convert_to_webp=true!)
        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'alpha', '--force' => true]));
        self::assertFileExists($imgPath);
        $after = @getimagesize($imgPath);
        self::assertIsArray($after);
        self::assertSame(120, $after[0]);
        self::assertSame(60, $after[1]);
    }

    public function testStorageStatsTrashPurgeAndCacheClearCommands(): void
    {
        $env = $this->createEnvironment();
        file_put_contents($this->alphaDir . DIRECTORY_SEPARATOR . 'doc.txt', str_repeat('X', 512));
        file_put_contents($this->alphaDir . DIRECTORY_SEPARATOR . 'remove-me.txt', 'trash-me');

        // Move one file into alpha trash
        $alphaProfile = $env['config']->getProfile('alpha');
        $env['trash']->moveToTrash($alphaProfile, 'remove-me.txt', 'vol_alpha');

        // 1. Storage stats JSON output
        $statsTester = new CommandTester(new StorageStatsCommand(
            $env['scanner'],
            $env['quota'],
            $env['thumbs'],
            $env['trash'],
        ));
        self::assertSame(Command::SUCCESS, $statsTester->execute(['profile' => 'alpha', '--json' => true]));
        $json = json_decode($statsTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $json);
        self::assertSame('alpha', $json[0]['profile']);
        self::assertSame(1, $json[0]['files']);
        self::assertSame(512, $json[0]['bytes']);
        self::assertSame(1, $json[0]['trash']['items']);

        // 2. Trash purge dry-run keeps item, real run with --days=0 purges it
        $trashTester = new CommandTester(new TrashPurgeCommand($env['trash'], $env['scanner']));
        self::assertSame(Command::SUCCESS, $trashTester->execute(['profile' => 'alpha', '--days' => '0', '--dry-run' => true]));
        self::assertCount(1, $env['trash']->listTrash($alphaProfile, 'vol_alpha'));

        self::assertSame(Command::SUCCESS, $trashTester->execute(['profile' => 'alpha', '--days' => '0']));
        self::assertCount(0, $env['trash']->listTrash($alphaProfile, 'vol_alpha'));

        // 3. Cache clear validates profile names
        $cacheTester = new CommandTester(new CacheClearCommand(
            $env['cache'],
            $env['config'],
            $env['registry'],
            $env['normalizer'],
        ));
        self::assertSame(Command::SUCCESS, $cacheTester->execute(['profile' => 'alpha']));
        self::assertSame(Command::FAILURE, $cacheTester->execute(['profile' => 'does_not_exist']));
    }

    /**
     * @return array{
     *     config: YamlConfigProvider,
     *     registry: VolumeRegistry,
     *     normalizer: PathNormalizer,
     *     cache: DirectoryCacheManager,
     *     thumbs: ThumbnailManager,
     *     optimizer: ImageOptimizer,
     *     quota: StorageQuotaChecker,
     *     trash: TrashManager,
     *     scanner: ProfileFileScanner,
     * }
     */
    private function createEnvironment(): array
    {
        $thumbCfg = [
            'enabled' => true,
            'driver' => 'auto',
            'width' => 100,
            'height' => 100,
            'format' => 'webp',
            'quality' => 80,
            'fit' => 'contain',
            'cache_dir' => $this->sharedThumbDir,
            'on_missing_driver' => 'fallback_original',
        ];

        $config = new YamlConfigProvider(
            [
                'theme' => 'bootstrap5',
                'locale' => 'en',
                'base_path' => $this->tempDir,
                'url_prefix' => '/uploads',
            ],
            [
                'vol_alpha' => ['label' => 'Alpha', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->alphaDir, 'url_prefix' => '/alpha', 'private' => false, 'options' => []],
                'vol_beta' => ['label' => 'Beta', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->betaDir, 'url_prefix' => '/beta', 'private' => false, 'options' => []],
            ],
            [
                'alpha' => [
                    'volumes' => ['vol_alpha'],
                    'storage_quota' => '10M',
                    'thumbnail' => $thumbCfg,
                    'image_optimization' => [
                        'enabled' => false,
                        'max_width' => 120,
                        'max_height' => 120,
                        'convert_to_webp' => true,
                        'quality' => 85,
                    ],
                ],
                'beta' => [
                    'volumes' => ['vol_beta'],
                    'thumbnail' => $thumbCfg,
                ],
            ],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($config, new FlysystemStorageFactory(new DynamicPathResolver()));
        $cache = new DirectoryCacheManager();
        $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $optimizer = new ImageOptimizer();
        $quota = new StorageQuotaChecker($registry, $normalizer, $cache);
        $trash = new TrashManager($registry, $normalizer, new EventDispatcher());
        $scanner = new ProfileFileScanner($config, $registry, $normalizer);

        return [
            'config' => $config,
            'registry' => $registry,
            'normalizer' => $normalizer,
            'cache' => $cache,
            'thumbs' => $thumbs,
            'optimizer' => $optimizer,
            'quota' => $quota,
            'trash' => $trash,
            'scanner' => $scanner,
        ];
    }

    private function createPng(string $path, int $width, int $height): void
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            $img = imagecreatetruecolor($width, $height);
            self::assertNotFalse($img);
            $color = imagecolorallocate($img, 16, 185, 129);
            imagefilledrectangle($img, 0, 0, $width, $height, (int) $color);
            imagepng($img, $path);
            imagedestroy($img);

            return;
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);
        file_put_contents($path, $png);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
