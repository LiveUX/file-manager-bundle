<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Twig;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Components\BrowserComponent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BrowserComponentUploadConfigTest extends TestCase
{
    public function testUploadMaxSizeChunkLimitAndAcceptAttribute(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_cfg_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0775, true);

        try {
            $profile = new ProfileConfig(
                name: 'default',
                basePath: $tempDir,
                theme: 'tailwind',
                locale: 'en',
                volumes: ['local'],
                driver: 'LocalFileSystem',
                path: $tempDir,
                urlPrefix: '/uploads',
                private: false,
                driverOptions: [],
                ignoreDotFiles: true,
                excludePatterns: [],
                sortBy: 'name',
                sortDirection: 'asc',
                foldersFirst: true,
                uploadSubfolder: null,
                uploadAllow: ['image/jpeg', 'image/png', 'application/pdf'],
                uploadDeny: ['text/x-php'],
                uploadMaxSize: '25M',
                overwritePolicy: 'rename',
                chunkUpload: ['enabled' => true, 'chunk_size' => 10485760],
                naming: [],
                imageOptimization: ['enabled' => false],
                thumbnail: ['enabled' => true, 'driver' => 'gd', 'cache_dir' => $tempDir . '/_thumbs'],
                trash: ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30],
                zip: ['enabled' => true, 'max_files' => 100, 'max_total_size' => '50M'],
                ui: [],
                permissions: [],
                security: ['voter_enabled' => false, 'csrf_protection' => true],
                cache: ['enabled' => false, 'ttl' => 0],
                metadata: ['enabled' => false],
            );

            $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $tempDir);
            $configProvider = $this->createStub(ConfigProviderInterface::class);
            $configProvider->method('getProfile')->willReturn($profile);
            $configProvider->method('getVolume')->willReturn($volume);

            $pathNormalizer = new PathNormalizer();
            $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
            $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, new DirectoryCacheManager(), new NullMetadataStorage());
            $trash = new TrashManager($volumeRegistry, $pathNormalizer, new EventDispatcher());
            $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
            $zip = new ZipArchiveService($volumeRegistry, $pathNormalizer);
            $urlGen = $this->createStub(UrlGeneratorInterface::class);
            $icons = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');

            $component = new BrowserComponent(
                $configProvider,
                $volumeRegistry,
                $lister,
                $trash,
                $thumbs,
                $zip,
                $pathNormalizer,
                $urlGen,
                $icons,
                new CsrfRequestValidator(null),
            );
            $component->mount('default');

            self::assertSame(26214400, $component->getUploadMaxSizeBytes());
            self::assertSame('25M', $component->getUploadMaxSizeLabel());
            self::assertSame('image/jpeg,image/png,application/pdf', $component->getUploadAcceptAttribute());

            $effectiveChunk = $component->getEffectiveChunkSizeBytes();
            self::assertGreaterThanOrEqual(262144, $effectiveChunk);
            self::assertLessThanOrEqual(10485760, $effectiveChunk);

            // Default UI behavior checks (z-index, double-click folders, perf badge hidden, per-page options)
            self::assertSame(1050, $component->getResolvedZIndex());
            self::assertSame('dblclick', $component->getFolderOpenOn());
            self::assertSame([24, 48, 96], $component->getPerPageOptions());
            $caps = $component->getCapabilities();
            self::assertFalse($caps['showPerfBadge']);
            self::assertTrue($caps['showNavButtons']);
            self::assertTrue($caps['showContextNav']);
            self::assertTrue($caps['allowDropzoneUpload']);
        } finally {
            @rmdir($tempDir);
        }
    }

    public function testCustomUiVisibilityZIndexAndPerPageOptions(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_ui_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0775, true);

        try {
            $profile = new ProfileConfig(
                name: 'custom',
                basePath: $tempDir,
                theme: 'shadcn',
                locale: 'tr',
                volumes: ['local'],
                driver: 'LocalFileSystem',
                path: $tempDir,
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
                thumbnail: ['enabled' => true],
                trash: ['enabled' => true],
                zip: ['enabled' => true],
                ui: [
                    'z_index' => 99999,
                    'folder_open_on' => 'click',
                    'per_page' => 12,
                    'per_page_options' => [12, 36, 72],
                    'show_perf_badge' => true,
                    'show_nav_buttons' => false,
                    'show_pagination' => false,
                    'show_context_nav' => false,
                    'allow_dropzone_upload' => false,
                ],
                permissions: ['download' => false],
                security: ['voter_enabled' => false, 'csrf_protection' => true],
                cache: ['enabled' => false],
                metadata: ['enabled' => false],
            );

            $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $tempDir);
            $configProvider = $this->createStub(ConfigProviderInterface::class);
            $configProvider->method('getProfile')->willReturn($profile);
            $configProvider->method('getVolume')->willReturn($volume);

            $pathNormalizer = new PathNormalizer();
            $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
            $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, new DirectoryCacheManager(), new NullMetadataStorage());
            $trash = new TrashManager($volumeRegistry, $pathNormalizer, new EventDispatcher());
            $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
            $zip = new ZipArchiveService($volumeRegistry, $pathNormalizer);
            $urlGen = $this->createStub(UrlGeneratorInterface::class);
            $icons = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');

            $component = new BrowserComponent(
                $configProvider,
                $volumeRegistry,
                $lister,
                $trash,
                $thumbs,
                $zip,
                $pathNormalizer,
                $urlGen,
                $icons,
                new CsrfRequestValidator(null),
            );
            $component->mount('custom');

            self::assertSame(99999, $component->getResolvedZIndex());
            self::assertSame('click', $component->getFolderOpenOn());
            self::assertSame([12, 36, 72], $component->getPerPageOptions());

            $caps = $component->getCapabilities();
            self::assertTrue($caps['showPerfBadge']);
            self::assertFalse($caps['showNavButtons']);
            self::assertFalse($caps['showPagination']);
            self::assertFalse($caps['showContextNav']);
            self::assertFalse($caps['allowDropzoneUpload']);
        } finally {
            @rmdir($tempDir);
        }
    }

    public function testVolumeOrderModesLocalFirstNetworkFirstAndConfigured(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_vol_ord_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0775, true);

        try {
            $localVol = new VolumeConfig('local_a', 'Local A', 'local', 'LocalFileSystem', $tempDir);
            $s3Vol = new VolumeConfig('supabase_s3', 'Supabase', 'network', 'AwsS3', driverOptions: ['endpoint' => 'https://ref.storage.supabase.co/storage/v1/s3']);
            $r2Vol = new VolumeConfig('cloudflare_r2', 'R2', 'network', 'AwsS3', driverOptions: ['endpoint' => 'https://acc.r2.cloudflarestorage.com']);

            $makeComponent = function (string $order) use ($tempDir, $localVol, $s3Vol, $r2Vol): BrowserComponent {
                $profile = new ProfileConfig(
                    name: 'default',
                    basePath: $tempDir,
                    theme: 'shadcn',
                    locale: 'en',
                    volumes: ['supabase_s3', 'local_a', 'cloudflare_r2'],
                    driver: 'LocalFileSystem',
                    path: $tempDir,
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
                    thumbnail: ['enabled' => false, 'cache_dir' => $tempDir],
                    trash: ['enabled' => false],
                    zip: ['enabled' => false],
                    ui: ['volume_order' => $order],
                    permissions: [],
                    security: ['voter_enabled' => false],
                    cache: ['enabled' => false],
                    metadata: ['enabled' => false],
                );

                $volumes = ['local_a' => $localVol, 'supabase_s3' => $s3Vol, 'cloudflare_r2' => $r2Vol];
                $configProvider = $this->createStub(ConfigProviderInterface::class);
                $configProvider->method('getProfile')->willReturn($profile);
                $configProvider->method('getVolume')->willReturnCallback(static fn (string $name): VolumeConfig => $volumes[$name]);

                $pathNormalizer = new PathNormalizer();
                $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
                $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, new DirectoryCacheManager(), new NullMetadataStorage());
                $trash = new TrashManager($volumeRegistry, $pathNormalizer, new EventDispatcher());
                $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
                $zip = new ZipArchiveService($volumeRegistry, $pathNormalizer);
                $urlGen = $this->createStub(UrlGeneratorInterface::class);
                $icons = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');

                $component = new BrowserComponent(
                    $configProvider,
                    $volumeRegistry,
                    $lister,
                    $trash,
                    $thumbs,
                    $zip,
                    $pathNormalizer,
                    $urlGen,
                    $icons,
                    new CsrfRequestValidator(null),
                );
                $component->mount('default', 'local_a');

                return $component;
            };

            $localFirst = $makeComponent('local_first');
            self::assertSame(['local_a', 'supabase_s3', 'cloudflare_r2'], array_map(static fn (VolumeConfig $v): string => $v->name, $localFirst->getOrderedVolumes()));
            self::assertSame(['local', 'network'], array_column($localFirst->getVolumeSections(), 'group'));

            $networkFirst = $makeComponent('network_first');
            self::assertSame(['supabase_s3', 'cloudflare_r2', 'local_a'], array_map(static fn (VolumeConfig $v): string => $v->name, $networkFirst->getOrderedVolumes()));
            self::assertSame(['network', 'local'], array_column($networkFirst->getVolumeSections(), 'group'));

            $configured = $makeComponent('configured');
            self::assertSame(['supabase_s3', 'local_a', 'cloudflare_r2'], array_map(static fn (VolumeConfig $v): string => $v->name, $configured->getOrderedVolumes()));
            self::assertSame(['configured'], array_column($configured->getVolumeSections(), 'group'));
        } finally {
            @rmdir($tempDir);
        }
    }

    public function testUiSizingDisplayOptionsAndImageConstraintsJson(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_sizing_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0775, true);

        try {
            $localVol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $tempDir);

            $makeComponent = function (array $ui, array $constraints = [], array $imgOpt = []) use ($tempDir, $localVol): BrowserComponent {
                $profile = new ProfileConfig(
                    name: 'gallery',
                    basePath: $tempDir,
                    theme: 'shadcn',
                    locale: 'en',
                    volumes: ['local'],
                    driver: 'LocalFileSystem',
                    path: $tempDir,
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
                    imageOptimization: $imgOpt,
                    thumbnail: ['enabled' => false, 'cache_dir' => $tempDir],
                    trash: ['enabled' => false],
                    zip: ['enabled' => false],
                    ui: $ui,
                    permissions: [],
                    security: ['voter_enabled' => false],
                    cache: ['enabled' => false],
                    metadata: ['enabled' => false],
                    imageConstraints: $constraints,
                );

                $configProvider = $this->createStub(ConfigProviderInterface::class);
                $configProvider->method('getProfile')->willReturn($profile);
                $configProvider->method('getVolume')->willReturn($localVol);

                $pathNormalizer = new PathNormalizer();
                $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
                $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, new DirectoryCacheManager(), new NullMetadataStorage());
                $trash = new TrashManager($volumeRegistry, $pathNormalizer, new EventDispatcher());
                $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
                $zip = new ZipArchiveService($volumeRegistry, $pathNormalizer);
                $urlGen = $this->createStub(UrlGeneratorInterface::class);
                $icons = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');

                $component = new BrowserComponent(
                    $configProvider,
                    $volumeRegistry,
                    $lister,
                    $trash,
                    $thumbs,
                    $zip,
                    $pathNormalizer,
                    $urlGen,
                    $icons,
                    new CsrfRequestValidator(null),
                );
                $component->mount('gallery');

                return $component;
            };

            // Defaults
            $defaultComp = $makeComponent([]);
            self::assertSame(158, $defaultComp->getResolvedItemSizePx());
            self::assertSame('4/3', $defaultComp->getResolvedCardAspectRatio());
            self::assertSame('tile', $defaultComp->getResolvedFolderStyle());
            self::assertTrue($defaultComp->getCapabilities()['showSizeSlider']);

            // Presets and clamping
            self::assertSame(108, $makeComponent(['item_size' => 'xs'])->getResolvedItemSizePx());
            self::assertSame(132, $makeComponent(['item_size' => 'sm'])->getResolvedItemSizePx());
            self::assertSame(204, $makeComponent(['item_size' => 'lg'])->getResolvedItemSizePx());
            self::assertSame(260, $makeComponent(['item_size' => 'xl'])->getResolvedItemSizePx());
            self::assertSame(220, $makeComponent(['item_size' => 220])->getResolvedItemSizePx());
            self::assertSame(96, $makeComponent(['item_size' => 20])->getResolvedItemSizePx());
            self::assertSame(320, $makeComponent(['item_size' => 999])->getResolvedItemSizePx());

            // Custom display + image constraints JSON
            $custom = $makeComponent(
                ui: [
                    'item_size' => 'lg',
                    'card_aspect_ratio' => '16/9',
                    'folder_style' => 'card',
                    'show_size_slider' => false,
                ],
                constraints: [
                    'min_width' => 1200,
                    'min_height' => 675,
                    'max_width' => 4000,
                    'aspect_ratio' => '16:9',
                    'aspect_ratio_tolerance' => 0.08,
                ],
                imgOpt: ['enabled' => true],
            );

            self::assertSame('16/9', $custom->getResolvedCardAspectRatio());
            self::assertSame('card', $custom->getResolvedFolderStyle());
            self::assertFalse($custom->getCapabilities()['showSizeSlider']);

            $json = $custom->getImageConstraintsJson();
            self::assertSame(1200, $json['minWidth']);
            self::assertSame(675, $json['minHeight']);
            self::assertSame(4000, $json['maxWidth']);
            self::assertNull($json['maxHeight']);
            self::assertSame('16:9', $json['aspectRatio']);
            self::assertSame(0.08, $json['aspectRatioTolerance']);
            self::assertTrue($json['imageOptEnabled']);
        } finally {
            @rmdir($tempDir);
        }
    }
}
