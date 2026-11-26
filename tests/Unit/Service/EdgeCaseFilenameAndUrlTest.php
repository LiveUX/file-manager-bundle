<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Model\FileItem;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class EdgeCaseFilenameAndUrlTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_edge_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testPublicUrlEncodesSpacesHashPercentAndUnicodePerSegment(): void
    {
        $sub = $this->tempDir . DIRECTORY_SEPARATOR . 'Yaz Kampanyası #1';
        mkdir($sub, 0775, true);
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'ürün %50 indirim #2.jpg', 'img');

        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'tr', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local']]],
        );

        $lister = new DirectoryLister(
            new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver())),
            new PathNormalizer(),
            new DirectoryCacheManager(),
            new NullMetadataStorage(),
        );

        $listing = $lister->list($provider->getProfile('default'), 'local', 'Yaz Kampanyası #1');
        self::assertSame(1, $listing->total);
        self::assertInstanceOf(FileItem::class, $listing->items[0]);

        $url = (string) $listing->items[0]->publicUrl;
        self::assertStringStartsWith('/uploads/', $url);
        self::assertStringContainsString('%231/', $url);
        self::assertStringContainsString('%2550', $url);
        self::assertStringContainsString('%232.jpg', $url);
        self::assertStringNotContainsString('#', $url);
        self::assertStringNotContainsString(' ', $url);
    }

    public function testFilenameNormalizerSanitizesUnsafeCharsWhenSluggerOffAndInheritsProfileLocale(): void
    {
        $normalizer = new FilenameNormalizer();

        // 1. slugger: false must still strip OS-unsafe chars (<>:"|?*) while keeping spaces & Turkish chars
        $providerNoSlug = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'tr', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'naming' => ['slugger' => false]]],
        );
        $profileNoSlug = $providerNoSlug->getProfile('default');

        $uploaded = $normalizer->normalizeForUpload('Teklif: 2026? <Özel>.pdf', $profileNoSlug);
        self::assertSame('Teklif- 2026- -Özel-.pdf', $uploaded);

        $renamed = $normalizer->normalizeForRename('a.pdf', 'Yeni|Rapor*2026.pdf', $profileNoSlug);
        self::assertSame('Yeni-Rapor-2026.pdf', $renamed);

        // 2. slugger: true with null slugger_locale inherits profile locale ('tr') and handles emojis cleanly
        $providerTr = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'tr', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'naming' => ['slugger' => true, 'slugger_locale' => null]]],
        );
        $profileTr = $providerTr->getProfile('default');

        self::assertSame('istanbul-seker-2026.jpg', $normalizer->normalizeForUpload('İstanbul Şeker 2026.jpg', $profileTr));
        self::assertSame('file.jpg', $normalizer->normalizeForUpload('🔥🔥🔥.jpg', $profileTr));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
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
