<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Metadata;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Model\FileItem;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class FileMetadataAndFocalPointTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_fmeta_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testHydrationSanitizesTagsAndClampsFocalPoint(): void
    {
        $meta = FolderMetadata::fromArray([
            'alt' => "  Hero\0 Banner  ",
            'title' => 'Summer Campaign',
            'caption' => 'Main homepage hero visual',
            'tags' => ['#Hero', ' Campaign ', 'HERO', ''],
            'focalPoint' => ['x' => 1.75, 'y' => -0.2],
        ]);

        self::assertSame('Hero Banner', $meta->alt);
        self::assertSame('Summer Campaign', $meta->title);
        self::assertSame(['hero', 'campaign'], $meta->tags);
        self::assertSame(['x' => 1.0, 'y' => 0.0], $meta->focalPoint);
        self::assertTrue($meta->matchesSearch('#hero'));
        self::assertTrue($meta->matchesSearch('homepage'));
        self::assertFalse($meta->matchesSearch('#nonexistent'));
    }

    public function testTagSearchAndMetadataRelocationOnFileRename(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'product.jpg', 'img');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'other.jpg', 'img2');

        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'security' => ['voter_enabled' => false]]],
        );

        $profile = $provider->getProfile('default');
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $storage = new JsonFileMetadataStorage($this->tempDir . DIRECTORY_SEPARATOR . 'meta.json');
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MetadataSyncListener($storage));

        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, $storage, $dispatcher);
        $lister = new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), $storage);

        $ops->updateFileMetadata(
            $profile,
            'product.jpg',
            'Blue Thermos Flask',
            'Thermos',
            'Stainless steel promotional flask',
            ['thermos', 'promo-2026'],
            ['x' => 0.35, 'y' => 0.25],
            'local',
        );

        // Search by #tag finds only product.jpg
        $tagSearch = $lister->list($profile, 'local', '', 1, 24, 'all', '#promo-2026');
        self::assertSame(1, $tagSearch->total);
        self::assertInstanceOf(FileItem::class, $tagSearch->items[0]);
        self::assertSame('Blue Thermos Flask', $tagSearch->items[0]->metadata?->alt);
        self::assertSame(['x' => 0.35, 'y' => 0.25], $tagSearch->items[0]->metadata?->focalPoint);

        // Renaming product.jpg -> flask.jpg must relocate metadata cleanly
        $ops->rename($profile, 'product.jpg', 'flask.jpg', 'local');
        $afterRename = $lister->list($profile, 'local', '', 1, 24, 'all', '#thermos');
        self::assertSame(1, $afterRename->total);
        self::assertSame('flask.jpg', $afterRename->items[0]->name);
        self::assertSame('Blue Thermos Flask', $afterRename->items[0]->metadata?->alt);
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
