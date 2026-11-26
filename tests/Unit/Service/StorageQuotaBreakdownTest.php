<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class StorageQuotaBreakdownTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_qdetail_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/images', 0775, true);
        mkdir($this->tempDir . '/docs', 0775, true);
        mkdir($this->tempDir . '/.trash', 0775, true);

        file_put_contents($this->tempDir . '/images/hero.jpg', str_repeat('A', 4096));
        file_put_contents($this->tempDir . '/images/thumb.png', str_repeat('B', 1024));
        file_put_contents($this->tempDir . '/docs/report.pdf', str_repeat('C', 2048));
        file_put_contents($this->tempDir . '/docs/pack.zip', str_repeat('D', 512));
        file_put_contents($this->tempDir . '/.trash/old.jpg', str_repeat('E', 1024));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
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

    public function testDetailedBreakdownAggregatesCategoriesTopFoldersLargestFilesAndTrash(): void
    {
        $provider = new YamlConfigProvider(
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
            [
                'limited' => [
                    'volumes' => ['local'],
                    'storage_quota' => '16K',
                ],
            ],
        );

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $checker = new StorageQuotaChecker($registry, new PathNormalizer(), new DirectoryCacheManager());
        $profile = $provider->getProfile('limited');

        $detail = $checker->getDetailedBreakdown($profile);

        // Total: 4096 + 1024 + 2048 + 512 + 1024 (trash) = 8704 bytes out of 16384 bytes
        self::assertTrue($detail['summary']['enabled']);
        self::assertSame(8704, $detail['summary']['usedBytes']);
        self::assertSame(16384, $detail['summary']['maxBytes']);
        self::assertSame(7680, $detail['summary']['remainingBytes']);
        self::assertSame(4, $detail['counts']['files']);
        self::assertSame(2, $detail['counts']['folders']);
        self::assertSame(1, $detail['counts']['trashFiles']);
        self::assertSame(1024, $detail['counts']['trashBytes']);

        $byKey = [];
        foreach ($detail['categories'] as $cat) {
            $byKey[$cat['key']] = $cat;
        }
        self::assertSame(5120, $byKey['image']['bytes']);
        self::assertSame(2, $byKey['image']['count']);
        self::assertSame(2048, $byKey['doc']['bytes']);
        self::assertSame(512, $byKey['archive']['bytes']);

        self::assertSame('images', $detail['topFolders'][0]['path']);
        self::assertSame(5120, $detail['topFolders'][0]['bytes']);
        self::assertSame('hero.jpg', $detail['largestFiles'][0]['name']);
        self::assertSame(4096, $detail['largestFiles'][0]['bytes']);
    }
}
