<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DirectoryListerAndTrashTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/liveux_fm_dir_' . uniqid('', true);
        mkdir($this->tempDir . '/sub_b', 0775, true);
        mkdir($this->tempDir . '/sub_a', 0775, true);
        file_put_contents($this->tempDir . '/.hidden', 'secret');
        file_put_contents($this->tempDir . '/photo.webp', str_repeat('a', 2048));
        file_put_contents($this->tempDir . '/catalog.pdf', str_repeat('b', 4096));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testListingRespectsFoldersFirstIgnoreDotFilesAndFilter(): void
    {
        $profile = $this->createProfile();
        $registry = $this->createRegistry($profile);
        $lister = new DirectoryLister($registry, new PathNormalizer(), new DirectoryCacheManager(), new NullMetadataStorage());

        $all = $lister->list($profile);
        self::assertSame(4, $all->total); // .hidden ignored
        self::assertSame('sub_a', $all->items[0]->name);
        self::assertSame('sub_b', $all->items[1]->name);

        $imagesOnly = $lister->list($profile, typeFilter: 'image');
        self::assertSame(1, $imagesOnly->total);
        self::assertSame('photo.webp', $imagesOnly->items[0]->name);
    }

    public function testTrashMoveRestoreAndPurge(): void
    {
        $profile = $this->createProfile();
        $registry = $this->createRegistry($profile);
        $trash = new TrashManager($registry, new PathNormalizer(), new EventDispatcher());

        $entry = $trash->moveToTrash($profile, 'catalog.pdf', null, 'admin');
        self::assertFileDoesNotExist($this->tempDir . '/catalog.pdf');
        self::assertCount(1, $trash->listTrash($profile));

        $trash->restore($profile, $entry->id);
        self::assertFileExists($this->tempDir . '/catalog.pdf');
        self::assertCount(0, $trash->listTrash($profile));
    }

    private function createProfile(): ProfileConfig
    {
        return new ProfileConfig(
            name: 'test',
            basePath: $this->tempDir,
            theme: 'bootstrap5',
            locale: null,
            volumes: [],
            driver: 'LocalFileSystem',
            path: $this->tempDir,
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: [],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: [],
            naming: [],
            imageOptimization: [],
            thumbnail: [],
            trash: ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30],
            zip: ['enabled' => true, 'max_files' => 100, 'max_total_size' => '50M'],
            ui: ['per_page' => 24],
            permissions: [],
            security: [],
            cache: ['enabled' => false, 'ttl' => 0],
            metadata: [],
        );
    }

    private function createRegistry(ProfileConfig $profile): VolumeRegistry
    {
        $provider = new YamlConfigProvider([], [], ['test' => ['path' => $profile->path]]);
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());

        return new VolumeRegistry($provider, $factory);
    }
}
