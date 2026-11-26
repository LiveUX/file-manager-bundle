<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Event\ItemClonedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\EventListener\CacheInvalidationListener;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\EventListener\ThumbnailCleanupListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileManagerVoter;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

/**
 * Regression suite for Pass-8: Report vs Code consistency fixes (T-01..T-08) and
 * fresh security, performance, logic, and global Unicode hardening (P8-01..P8-14).
 */
final class EighthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_eighth_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testSvgSanitizerBlocksEscapedSlashBeforeStarAndSplitImportPayloads(): void
    {
        $sanitizer = new SvgSanitizer();

        // T-01: W3C CSS Syntax Level 3 §4.3.1: \/* is an escaped slash followed by *, NOT a comment opener!
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <style>@unknown \/*; @im/**/port "https://evil.example/xss.css";</style>
    <rect width="50" height="50" style="--a: \/*; background: u/**/rl(https://evil.example/a.png)" href="#javascript:alert(1)"/>
</svg>
SVG;

        $clean = $sanitizer->sanitize($svg);
        self::assertStringNotContainsString('<style', $clean);
        self::assertStringNotContainsString('evil.example', $clean);
        self::assertStringNotContainsString('style=', $clean);
        self::assertStringNotContainsString('href=', $clean);
    }

    public function testFileSecurityGuardAndPickerTypeBlockCjkDotWithZeroWidthExtensionAndUrlVectors(): void
    {
        $guard = new FileSecurityGuard();

        // P8-01: CJK dot equivalent + zero-width character inside non-blocked extension ("photo。j\u{200B}pg")
        foreach (["photo\u{3002}j\u{200B}pg", "doc\u{FF61}p\u{200C}df", "img\u{FE52}p\u{200D}ng"] as $badName) {
            try {
                $guard->assertSafeFilename($badName);
                self::fail(sprintf('Expected assertSafeFilename to reject "%s".', $badName));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // T-02: FileManagerPickerType::isSafePickerValue in default 'url' mode must reject reserved names, CJK dots, double extensions, and dot-folders
        $ref = new ReflectionMethod(FileManagerPickerType::class, 'isSafePickerValue');
        self::assertTrue($ref->invoke(null, '/uploads/banners/hero.jpg', 'url'));
        self::assertTrue($ref->invoke(null, 'https://cdn.example.com/uploads/banners/hero.webp', 'url'));
        self::assertFalse($ref->invoke(null, "/uploads/aux\u{3002}jpg", 'url'));
        self::assertFalse($ref->invoke(null, '/uploads/con.jpg', 'url'));
        self::assertFalse($ref->invoke(null, '/uploads/exploit.php.jpg', 'url'));
        self::assertFalse($ref->invoke(null, "/uploads/shell.p\u{200B}hp", 'url'));
        self::assertFalse($ref->invoke(null, '/uploads/.secret/photo.jpg', 'url'));
        self::assertFalse($ref->invoke(null, "/uploads/\u{3002}hidden/photo.jpg", 'url'));
    }

    public function testConfigurationAndVoterAndAuthorizerAlignOnExtractZipAndDefaultRules(): void
    {
        // T-03: In Symfony DI Configuration, setting zip.allow_extract: true without explicit permissions.extract_zip enables extract_zip
        $processor = new Processor();
        $processed = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'unzip_enabled' => [
                    'zip' => ['allow_extract' => true],
                ],
                'unzip_explicitly_denied' => [
                    'zip' => ['allow_extract' => true],
                    'permissions' => ['extract_zip' => false],
                ],
                'unzip_default' => [],
            ],
        ]]);

        self::assertTrue($processed['profiles']['unzip_enabled']['permissions']['extract_zip']);
        self::assertFalse($processed['profiles']['unzip_explicitly_denied']['permissions']['extract_zip']);
        self::assertFalse($processed['profiles']['unzip_default']['permissions']['extract_zip']);

        // Partial permissions array in ProfileConfig works identically in both FileManagerVoter and FileManagerAuthorizer
        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['partial' => ['volumes' => ['default'], 'permissions' => ['upload' => false]]],
        );
        $profile = $provider->getProfile('partial');

        $authorizer = new FileManagerAuthorizer(null);
        $noVoterProfile = new ProfileConfig(
            name: $profile->name,
            basePath: $profile->basePath,
            theme: $profile->theme,
            locale: $profile->locale,
            volumes: $profile->volumes,
            driver: $profile->driver,
            path: $profile->path,
            urlPrefix: $profile->urlPrefix,
            private: $profile->private,
            driverOptions: $profile->driverOptions,
            ignoreDotFiles: $profile->ignoreDotFiles,
            excludePatterns: $profile->excludePatterns,
            sortBy: $profile->sortBy,
            sortDirection: $profile->sortDirection,
            foldersFirst: $profile->foldersFirst,
            uploadSubfolder: $profile->uploadSubfolder,
            uploadAllow: $profile->uploadAllow,
            uploadDeny: $profile->uploadDeny,
            uploadMaxSize: $profile->uploadMaxSize,
            overwritePolicy: $profile->overwritePolicy,
            chunkUpload: $profile->chunkUpload,
            naming: $profile->naming,
            imageOptimization: $profile->imageOptimization,
            thumbnail: $profile->thumbnail,
            trash: $profile->trash,
            zip: $profile->zip,
            ui: $profile->ui,
            permissions: $profile->permissions,
            security: ['voter_enabled' => false],
            cache: $profile->cache,
            metadata: $profile->metadata,
        );

        self::assertTrue($authorizer->isGranted($noVoterProfile, FileManagerPermissions::VIEW));
        self::assertTrue($authorizer->isGranted($noVoterProfile, FileManagerPermissions::DOWNLOAD));
        self::assertFalse($authorizer->isGranted($noVoterProfile, FileManagerPermissions::UPLOAD));
        self::assertFalse($authorizer->isGranted($noVoterProfile, FileManagerPermissions::EXTRACT_ZIP));

        $voter = new FileManagerVoter();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createStub(UserInterface::class));
        $subject = new FileManagerSubject($profile);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $subject, [FileManagerPermissions::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $subject, [FileManagerPermissions::DOWNLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $subject, [FileManagerPermissions::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $subject, [FileManagerPermissions::EXTRACT_ZIP]));
    }

    public function testTrashFifoEvictionRespectsChildItemPurgeVoterPermissions(): void
    {
        // T-04: FIFO trash eviction must NOT evict a trashed folder if a child file inside it is denied TRASH_PURGE
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'team', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'team' . DIRECTORY_SEPARATOR . 'protected.txt', str_repeat('p', 60));
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'new_item.txt', str_repeat('n', 60));

        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['default'],
                    'trash' => ['enabled' => true, 'directory' => '.trash', 'max_size' => '100B'],
                    'security' => ['voter_enabled' => true],
                ],
            ],
        );
        $profile = $provider->getProfile('default');
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();

        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static function (mixed $attr, mixed $subject): bool {
            if (
                $attr === FileManagerPermissions::TRASH_PURGE
                && is_object($subject)
                && property_exists($subject, 'path')
                && $subject->path === 'team/protected.txt'
            ) {
                return false;
            }

            return true;
        });

        $trash = new TrashManager($registry, $normalizer, new EventDispatcher(), new FileSecurityGuard(), null, $auth);
        $trash->moveToTrash($profile, 'team', 'default');

        $this->expectException(AccessDeniedException::class);
        $trash->moveToTrash($profile, 'new_item.txt', 'default');
    }

    public function testZipExtractionWithActiveEventDispatcherDoesNotDoubleCountQuotaAndDetectsInArchiveDuplicates(): void
    {
        // T-06 & P8-02: ZipArchiveService with CacheInvalidationListener attached must not double-count quota mid-loop
        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['default'],
                    'storage_quota' => '120B',
                    'upload_allow' => ['text/plain'],
                    'zip' => ['enabled' => true, 'allow_extract' => true],
                    'duplicate_detection' => ['enabled' => true, 'policy' => 'reject'],
                ],
            ],
        );
        $profile = $provider->getProfile('default');
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cacheManager = new DirectoryCacheManager(new ArrayAdapter());
        $quotaChecker = new StorageQuotaChecker($registry, $normalizer, $cacheManager);
        $duplicateDetector = new DuplicateDetector($registry, $normalizer, $cacheManager);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new CacheInvalidationListener($cacheManager, $normalizer));

        // Create a ZIP (~0B after we delete or keep it outside quota? Wait: the zip itself is in the volume!
        // Let's give 500B quota, and two files of 200B each = 400B total + ~150B zip, or put the zip in a subfolder and test 2 files)
        $provider2 = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['default'],
                    'storage_quota' => '600B',
                    'upload_allow' => ['text/plain'],
                    'zip' => ['enabled' => true, 'allow_extract' => true],
                    'duplicate_detection' => ['enabled' => true, 'policy' => 'reject'],
                ],
            ],
        );
        $profile2 = $provider2->getProfile('default');

        $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'pack.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('one.txt', str_repeat('A', 150));
        $zip->addFromString('two.txt', str_repeat('B', 150));
        $zip->close();

        $service = new ZipArchiveService(
            volumeRegistry: $registry,
            pathNormalizer: $normalizer,
            quotaChecker: $quotaChecker,
            duplicateDetector: $duplicateDetector,
            eventDispatcher: $dispatcher,
        );

        $res = $service->extractArchive($profile2, 'pack.zip', 'out', 'default');
        self::assertSame(2, $res['extracted']);

        // Now test that duplicate files INSIDE the same archive are rejected even when eventDispatcher is null
        $dupZipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'dup_in_zip.zip';
        $dupZip = new ZipArchive();
        self::assertTrue($dupZip->open($dupZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $dupZip->addFromString('a.txt', 'identical-content-12345');
        $dupZip->addFromString('b.txt', 'identical-content-12345');
        $dupZip->close();

        $serviceNoDispatcher = new ZipArchiveService(
            volumeRegistry: $registry,
            pathNormalizer: $normalizer,
            duplicateDetector: $duplicateDetector,
        );

        $this->expectException(UploadValidationException::class);
        $serviceNoDispatcher->extractArchive($profile2, 'dup_in_zip.zip', 'out_dup', 'default');
    }

    public function testDoctrineDbalMetadataStorageBatchPreloadAndPrefixLookupAndNfcNormalization(): void
    {
        if (!class_exists(DriverManager::class) || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Doctrine DBAL and pdo_sqlite required.');
        }

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DoctrineDbalMetadataStorage($conn);

        // P8-06: Saving under NFD key and reading under NFC key (or vice versa) must resolve identically
        $nfdKey = "local::c\u{0327}o\u{0308}p/u\u{0308}ru\u{0308}n.jpg";
        $nfcKey = 'local::çöp/ürün.jpg';
        $storage->save($nfdKey, new FolderMetadata(title: 'Türkçe Ürün'));

        self::assertSame('Türkçe Ürün', $storage->get($nfcKey)->title);
        self::assertSame('Türkçe Ürün', $storage->get($nfdKey)->title);

        // T-05: preload() warms both existing and missing keys so get() works from itemCache
        $storage->reset();
        $storage->preload([$nfcKey, 'local::çöp/non_existent.jpg']);
        self::assertSame('Türkçe Ürün', $storage->get($nfcKey)->title);
        self::assertTrue($storage->get('local::çöp/non_existent.jpg')->isEmpty());

        // getByPrefix() returns only matching prefix entries
        $byPrefix = $storage->getByPrefix('local::çöp');
        self::assertCount(1, $byPrefix);
        self::assertArrayHasKey($nfcKey, $byPrefix);
    }

    public function testFilenameNormalizerDottedStemsAndRemoteUrlImporterMimeReconciliation(): void
    {
        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => ['volumes' => ['default'], 'naming' => ['slugger' => false]]],
        );
        $profile = $provider->getProfile('default');
        $fnNormalizer = new FilenameNormalizer();

        // T-07: Renaming "release.zip" to "v1.0-rc1", "v1.0_final", "Dr.Smith" preserves ".zip"
        self::assertSame('v1.0-rc1.zip', $fnNormalizer->normalizeForRename('release.zip', 'v1.0-rc1', $profile));
        self::assertSame('v1.0_final.zip', $fnNormalizer->normalizeForRename('release.zip', 'v1.0_final', $profile));
        self::assertSame('Dr.Smith.jpg', $fnNormalizer->normalizeForRename('avatar.jpg', 'Dr.Smith', $profile));

        // T-07: RemoteUrlImporter reconciles non-raster URL extensions (.json, .bin) and double-extension stems (render.php.png)
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $uploadManager = new UploadManager($registry, $normalizer, $fnNormalizer, new EventDispatcher());
        $importer = new RemoteUrlImporter($uploadManager, $registry, $normalizer);
        $extractRef = new ReflectionMethod(RemoteUrlImporter::class, 'extractFilename');

        $tempPng = $this->tempDir . DIRECTORY_SEPARATOR . 'test.png';
        file_put_contents($tempPng, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

        self::assertSame('avatar.png', $extractRef->invoke($importer, 'https://cdn.example.com/avatar.json', ['Content-Type: image/png'], $tempPng));
        self::assertSame('render-php.png', $extractRef->invoke($importer, 'https://cdn.example.com/render.php.png', ['Content-Type: image/png'], $tempPng));
    }

    public function testThumbnailCleanupPreservesSourceOnFolderCopyAndEventAliasPropertiesWork(): void
    {
        // P8-05: Copying a folder must NOT delete cached thumbnails of the source folder
        $thumbCacheDir = $this->tempDir . DIRECTORY_SEPARATOR . '_thumbs';
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'src_folder', 0775, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'dst_folder', 0775, true);
        $pngBytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'src_folder' . DIRECTORY_SEPARATOR . 'pic.png', $pngBytes);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'dst_folder' . DIRECTORY_SEPARATOR . 'pic.png', $pngBytes);

        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['default'],
                    'thumbnail' => ['enabled' => true, 'cache_dir' => $thumbCacheDir, 'format' => 'png'],
                ],
            ],
        );
        $profile = $provider->getProfile('default');
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $volume = $registry->getVolumeForProfile($profile, 'default');
        $normalizer = new PathNormalizer();
        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 'secret');

        $srcCanonical = $normalizer->buildCanonicalKey($volume, 'src_folder/pic.png', $profile->basePath);
        $cachedThumb = $thumbManager->getCachedPath($profile, $srcCanonical);
        mkdir(\dirname($cachedThumb), 0775, true);
        file_put_contents($cachedThumb, $pngBytes);
        self::assertTrue($thumbManager->hasCachedThumbnail($profile, $srcCanonical));

        $listener = new ThumbnailCleanupListener($thumbManager, $provider, $registry, $normalizer);
        $copyEvent = new ItemMovedEvent(
            $profile,
            $volume,
            'src_folder',
            $normalizer->buildCanonicalKey($volume, 'src_folder', $profile->basePath),
            $volume,
            'dst_folder',
            $normalizer->buildCanonicalKey($volume, 'dst_folder', $profile->basePath),
            true,
            true,
        );
        $listener->onItemMoved($copyEvent);

        self::assertTrue($thumbManager->hasCachedThumbnail($profile, $srcCanonical), 'Source folder thumbnail must remain intact after folder copy.');

        // T-08: Event alias properties ($oldPath, $oldCanonicalPath, $sourcePath, $sourceCanonicalPath, $sourceVolume)
        $renamedEvent = new ItemRenamedEvent($profile, $volume, 'a.jpg', 'local::a.jpg', 'b.jpg', 'local::b.jpg', false);
        self::assertSame('a.jpg', $renamedEvent->oldPath);
        self::assertSame('a.jpg', $renamedEvent->path);
        self::assertSame('local::a.jpg', $renamedEvent->oldCanonicalPath);

        $clonedEvent = new ItemClonedEvent($profile, $volume, 'a.jpg', 'local::a.jpg', 'a-copy.jpg', 'local::a-copy.jpg', false);
        self::assertSame('a.jpg', $clonedEvent->sourcePath);
        self::assertSame('local::a.jpg', $clonedEvent->sourceCanonicalPath);
        self::assertSame('src_folder', $copyEvent->sourcePath);
        self::assertSame($volume, $copyEvent->sourceVolume);
    }

    public function testTwigExtensionNullsDirectSvgHrefAndAutoInjectUsesProfileLocaleAndZIndex(): void
    {
        // P8-11: describeUrlFieldItem must set href=null on direct public .svg URLs
        $ext = new FileManagerTwigExtension($this->createStub(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class));
        $described = $ext->describeFieldItem('/uploads/diagram.svg');
        self::assertNull($described['href']);
        self::assertSame('/uploads/diagram.svg', $described['imgSrc']);

        // P8-11: AutoInjectDialogHostSubscriber uses profile's locale and ui.z_index
        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'locale' => 'ar',
                    'volumes' => ['default'],
                    'ui' => ['z_index' => 2200],
                    'security' => ['voter_enabled' => false],
                ],
            ],
        );
        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div data-controller="liveux--file-manager-bundle--dialog" data-locale="{{ locale }}" data-z="{{ zIndex }}"></div>',
        ]));
        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $provider);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response('<html><body><h1>Hi</h1></body></html>', 200, ['Content-Type' => 'text/html']);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber->onKernelResponse($event);
        self::assertStringContainsString('data-locale="ar"', (string) $response->getContent());
        self::assertStringContainsString('data-z="2200"', (string) $response->getContent());
    }
}
