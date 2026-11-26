<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Model\FolderItem;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;

final class DirectoryListerFolderCountTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_fcount_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/katalog/promosyon', 0775, true);
        mkdir($this->tempDir . '/editor', 0775, true);
        mkdir($this->tempDir . '/.trash', 0775, true);

        file_put_contents($this->tempDir . '/katalog/root-doc.pdf', '%PDF-1.4');
        file_put_contents($this->tempDir . '/katalog/.hidden-secret.txt', 'hidden');
        file_put_contents($this->tempDir . '/katalog/promosyon/a.jpg', 'img1');
        file_put_contents($this->tempDir . '/katalog/promosyon/b.jpg', 'img2');
        file_put_contents($this->tempDir . '/.trash/deleted.jpg', 'trash');
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

    public function testConfigurationExposesFolderItemCountAndModePerProfile(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [],
                'minimal' => [
                    'ui' => [
                        'show_folder_item_count' => false,
                        'folder_count_mode' => 'files',
                    ],
                ],
                'items_view' => [
                    'ui' => [
                        'show_folder_item_count' => true,
                        'folder_count_mode' => 'items',
                    ],
                ],
            ],
        ]]);

        self::assertTrue($config['profiles']['default']['ui']['show_folder_item_count']);
        self::assertSame('recursive_files', $config['profiles']['default']['ui']['folder_count_mode']);
        self::assertFalse($config['profiles']['minimal']['ui']['show_folder_item_count']);
        self::assertSame('files', $config['profiles']['minimal']['ui']['folder_count_mode']);
        self::assertSame('items', $config['profiles']['items_view']['ui']['folder_count_mode']);
    }

    public function testFolderCountsRespectProfileFlagAndAllThreeModes(): void
    {
        [$lister, $provider] = $this->createLister([
            'disabled' => ['ui' => ['show_folder_item_count' => false]],
            'recursive' => ['ui' => ['show_folder_item_count' => true, 'folder_count_mode' => 'recursive_files']],
            'direct_files' => ['ui' => ['show_folder_item_count' => true, 'folder_count_mode' => 'files']],
            'direct_items' => ['ui' => ['show_folder_item_count' => true, 'folder_count_mode' => 'items']],
        ]);

        // 1. Disabled -> all counts are null
        $disabledCounts = $this->extractFolderCounts($lister->list($provider->getProfile('disabled'))->items);
        self::assertSame(['editor' => null, 'katalog' => null], $disabledCounts);
        $disabledTree = $lister->listFullTree($provider->getProfile('disabled'));
        foreach ($disabledTree as $node) {
            self::assertNull($node['itemCount']);
        }

        // 2. Recursive files (default) -> katalog includes root-doc.pdf + 2 files in promosyon = 3 (dotfile & .trash ignored!)
        $recursiveCounts = $this->extractFolderCounts($lister->list($provider->getProfile('recursive'))->items);
        self::assertSame(['editor' => 0, 'katalog' => 3], $recursiveCounts);
        $treeByPath = [];
        foreach ($lister->listFullTree($provider->getProfile('recursive')) as $node) {
            $treeByPath[$node['path']] = $node['itemCount'];
        }
        self::assertSame(['editor' => 0, 'katalog' => 3, 'katalog/promosyon' => 2], $treeByPath);

        // 3. Direct files only -> katalog has 1 direct file (root-doc.pdf)
        $directFileCounts = $this->extractFolderCounts($lister->list($provider->getProfile('direct_files'))->items);
        self::assertSame(['editor' => 0, 'katalog' => 1], $directFileCounts);

        // 4. Direct items (files + subfolders) -> katalog has 1 direct file + 1 subfolder = 2
        $directItemCounts = $this->extractFolderCounts($lister->list($provider->getProfile('direct_items'))->items);
        self::assertSame(['editor' => 0, 'katalog' => 2], $directItemCounts);
    }

    public function testStaleCachedTreeWithoutCountSchemaAutoRefreshes(): void
    {
        $pool = new ArrayAdapter();
        $cacheManager = new DirectoryCacheManager($pool);

        $provider = new YamlConfigProvider(
            [
                'theme' => 'bootstrap5',
                'locale' => 'en',
                'base_path' => $this->tempDir,
                'url_prefix' => '/uploads',
                'cache' => ['enabled' => true, 'ttl' => 3600],
            ],
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
                'default' => [
                    'volumes' => ['local'],
                    'ui' => [
                        'show_folder_item_count' => true,
                        'folder_count_mode' => 'recursive_files',
                    ],
                ],
            ],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $lister = new DirectoryLister(
            $registry,
            $normalizer,
            $cacheManager,
            new JsonFileMetadataStorage($this->tempDir . '/meta.json'),
        );

        $profile = $provider->getProfile('default');
        $volume = $registry->getVolumeForProfile($profile);
        $canonicalKey = $normalizer->buildCanonicalKey($volume, DirectoryLister::TREE_CACHE_KEY, $profile->basePath);

        // Pre-seed cache with a legacy schema missing directFileCount / directItemCount / recursiveFileCount
        $cacheManager->remember($profile, $canonicalKey, static fn (): array => [
            [
                'name' => 'katalog',
                'path' => 'katalog',
                'parent' => '',
                'canonicalPath' => $normalizer->buildCanonicalKey($volume, 'katalog', $profile->basePath),
            ],
        ]);

        $counts = $this->extractFolderCounts($lister->list($profile)->items);
        self::assertSame(['editor' => 0, 'katalog' => 3], $counts);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @return array{0: DirectoryLister, 1: YamlConfigProvider}
     */
    private function createLister(array $profiles): array
    {
        $configuredProfiles = [];
        foreach ($profiles as $name => $cfg) {
            $configuredProfiles[$name] = array_merge(['volumes' => ['local']], $cfg);
        }

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
            $configuredProfiles,
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $lister = new DirectoryLister(
            $registry,
            $normalizer,
            new DirectoryCacheManager(),
            new JsonFileMetadataStorage($this->tempDir . '/meta.json'),
        );

        return [$lister, $provider];
    }

    /**
     * @param list<object> $items
     * @return array<string, ?int>
     */
    private function extractFolderCounts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            if ($item instanceof FolderItem) {
                $counts[$item->path] = $item->itemCount;
            }
        }
        ksort($counts);

        return $counts;
    }
}
