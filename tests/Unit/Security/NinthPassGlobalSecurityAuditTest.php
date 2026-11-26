<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use FilesystemIterator;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Command\DuplicatesCommand;
use LiveUX\FileManagerBundle\Command\ThumbnailClearCommand;
use LiveUX\FileManagerBundle\Command\TrashPurgeCommand;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\FlysystemServiceLocatorPass;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Model\FolderItem;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

/**
 * Regression suite for Pass-9: Report vs Code consistency fixes (T9-*) and
 * security, concurrency, performance, DI, CLI, and global Unicode hardening (P9-*).
 */
final class NinthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_ninth_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testMetadataSyncListenerRetainsVirtualSubfoldersOnNonLocalCopyAndPrunesOrphans(): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('listContents')->willReturn(new DirectoryListing([
            new FileAttributes('target/sub/file.txt'),
        ]));

        $volume = new VolumeConfig(
            name: 's3_vol',
            label: 'S3',
            group: 'network',
            driver: 'FlysystemService',
            driverOptions: ['service' => 's3_stub'],
        );
        $profile = $this->createProfile(volumes: ['s3_vol']);

        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $locator = new ServiceLocator(['s3_stub' => static fn (): FilesystemOperator => $fs]);
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver(), $locator));
        $normalizer = new PathNormalizer();
        $storage = new JsonFileMetadataStorage($this->tempDir . '/meta.json');

        $srcCanonical = $normalizer->buildCanonicalKey($volume, 'source', $profile->basePath);
        $srcSubCanonical = $normalizer->buildCanonicalKey($volume, 'source/sub', $profile->basePath);
        $srcOrphanCanonical = $normalizer->buildCanonicalKey($volume, 'source/sub/orphan', $profile->basePath);
        $tgtCanonical = $normalizer->buildCanonicalKey($volume, 'target', $profile->basePath);
        $tgtSubCanonical = $normalizer->buildCanonicalKey($volume, 'target/sub', $profile->basePath);
        $tgtOrphanCanonical = $normalizer->buildCanonicalKey($volume, 'target/sub/orphan', $profile->basePath);

        $storage->save($srcSubCanonical, new FolderMetadata(color: '#ff0000'));
        $storage->save($srcOrphanCanonical, new FolderMetadata(title: 'orphan'));

        $listener = new MetadataSyncListener($storage, null, $registry);
        $listener->onItemMoved(new ItemMovedEvent($profile, $volume, 'source', $srcCanonical, $volume, 'target', $tgtCanonical, true, true));

        self::assertSame('#ff0000', $storage->get($tgtSubCanonical)->color);
        self::assertTrue($storage->get($tgtOrphanCanonical)->isEmpty());
    }

    public function testJsonFileMetadataStorageSkipsRewriteWhenSavingEmptyOnAbsentKey(): void
    {
        $metaPath = $this->tempDir . '/meta.json';
        $storage = new JsonFileMetadataStorage($metaPath);
        $storage->save('local::existing', new FolderMetadata(title: 'keep'));

        $past = time() - 30;
        touch($metaPath, $past);
        clearstatcache(true, $metaPath);
        $mtimeBefore = filemtime($metaPath);

        $storage->save('local::absent', new FolderMetadata());
        clearstatcache(true, $metaPath);
        self::assertSame($mtimeBefore, filemtime($metaPath));

        $storage->save('local::existing', new FolderMetadata());
        self::assertTrue($storage->get('local::existing')->isEmpty());
    }

    public function testIconSetRegistryFallsBackOnUnsafeCustomSvgAndMergesRootAttributes(): void
    {
        $customDir = $this->tempDir . '/custom-icons';
        mkdir($customDir, 0775, true);
        file_put_contents($customDir . '/folder.svg', '<script>alert(1)</script>');
        file_put_contents(
            $customDir . '/file.svg',
            '<svg class="custom" aria-hidden="false" viewBox="0 0 24 24"><path d="M0 0h24v24"/></svg>',
        );

        $bundleIconsDir = dirname(__DIR__, 3) . '/resources/icons';
        $registry = new IconSetRegistry($bundleIconsDir);
        $payload = $registry->getIconSetPayload($customDir, true);

        self::assertStringContainsString('<svg', $payload['icons']['folder'] ?? '');
        self::assertStringContainsString('<path', $payload['icons']['folder'] ?? '');

        $apply = new ReflectionMethod($registry, 'applySvgAttributes');
        $rendered = $apply->invoke($registry, $payload['icons']['file'] ?? '', 18, 'fm-extra');
        self::assertIsString($rendered);
        self::assertSame(1, preg_match_all('/\bclass=/i', $rendered));
        self::assertSame(1, preg_match_all('/\baria-hidden=/i', $rendered));
        self::assertStringContainsString('custom fm-extra', $rendered);
        self::assertStringContainsString('aria-hidden="true"', $rendered);
    }

    public function testDoctrineDbalMetadataStorageNfcNormalizesDeleteRelocateCopyAndCapsItemCache(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite driver is required.');
        }

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DoctrineDbalMetadataStorage($conn);

        $nfc = "caf\u{00E9}";
        $nfd = "cafe\u{0301}";

        $storage->save('local::' . $nfc, new FolderMetadata(title: 'nfc-title'));
        $storage->delete('local::' . $nfd);
        self::assertTrue($storage->get('local::' . $nfc)->isEmpty());

        $folderNfc = "g\u{00FC}n";
        $folderNfd = "gu\u{0308}n";
        $storage->save('local::' . $folderNfc, new FolderMetadata(color: '#00aa00'));
        $storage->save('local::' . $folderNfc . '/child', new FolderMetadata(caption: 'child'));
        $storage->relocatePrefix('local::' . $folderNfd, 'local::archived');

        self::assertSame('#00aa00', $storage->get('local::archived')->color);
        self::assertSame('child', $storage->get('local::archived/child')->caption);

        $storage->copyPrefix('local::archived', 'local::copied');
        self::assertSame('child', $storage->get('local::copied/child')->caption);

        $keys = [];
        $conn->transactional(static function () use ($storage, &$keys): void {
            for ($i = 0; $i < 16500; ++$i) {
                $k = 'local::batch/k_' . $i;
                $keys[] = $k;
                $storage->save($k, new FolderMetadata(caption: 'n' . $i));
            }
        });

        $cacheProp = new ReflectionProperty($storage, 'itemCache');
        /** @var array<string, FolderMetadata> $cacheValue */
        $cacheValue = $cacheProp->getValue($storage);
        self::assertLessThanOrEqual(16384, count($cacheValue));

        $storage->preload(array_slice($keys, 0, 2100));
        self::assertSame('n2099', $storage->get($keys[2099])->caption);
    }

    public function testFolderMetadataAndUnicodeHelperHandleGlobalUnicodeAndOneDotLeader(): void
    {
        $meta = FolderMetadata::fromArray([
            'title' => " \u{202E}ok\u{2028}\u{200F} ",
            'tags' => ["\u{200B} tag1 ", "\u{202A}tag2\u{2066}"],
        ]);
        self::assertSame('ok', $meta->title);
        self::assertSame(['tag1', 'tag2'], $meta->tags);

        self::assertSame('shell.php', UnicodeHelper::foldDotEquivalents("shell\u{2024}php"));
        self::assertTrue(UnicodeHelper::hasEdgeInvisibleOrTrailingDot("foo\u{2024}"));
        self::assertSame('foo', UnicodeHelper::trimEdgeInvisiblesAndDots("foo\u{2024}"));

        self::assertSame('', UnicodeHelper::decodeTextPreview("\xFE\xFF", 1024));
        self::assertSame('', UnicodeHelper::decodeTextPreview("\xFF\xFE\x00\x00", 1024));
    }

    public function testSvgSanitizerPickerTypeAndFileSecurityGuardHardening(): void
    {
        $sanitizer = new SvgSanitizer();
        $sanitized = $sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><style>a{content:"\\d800\\dfff"}</style><circle r="5"/></svg>');
        self::assertStringContainsString('<svg', $sanitized);
        self::assertStringContainsString('<circle', $sanitized);

        $isSafe = new ReflectionMethod(FileManagerPickerType::class, 'isSafePickerValue');
        self::assertFalse($isSafe->invoke(null, 'https://cdn.example.com/evil.php?v=1', 'url'));
        self::assertTrue($isSafe->invoke(null, 'https://cdn.example.com/photo.jpg?w=800', 'url'));

        $guard = new FileSecurityGuard();
        foreach (['avatar.js.png', 'doc.xhtml.jpg', 'payload.hta.webp', 'mod.wasm.gif', 'page.phtm.png'] as $bad) {
            $caught = false;
            try {
                $guard->assertSafeFilename($bad);
            } catch (UploadValidationException) {
                $caught = true;
            }
            self::assertTrue($caught, sprintf('Double extension "%s" must be blocked.', $bad));
        }

        $guard->assertSafeFilename('summary.final.pdf');
        self::assertTrue(true);
    }

    public function testDirectoryListerCacheIsolationNoPoisoningAndBatchRecursiveSearch(): void
    {
        mkdir($this->tempDir . '/public', 0775, true);
        mkdir($this->tempDir . '/secret/nested', 0775, true);
        file_put_contents($this->tempDir . '/public/needle-public.txt', 'ok');
        file_put_contents($this->tempDir . '/secret/nested/needle-secret.txt', 'secret');

        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $profile = $this->createProfile(security: ['voter_enabled' => true]);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cachePool = new ArrayAdapter();
        $cacheManager = new DirectoryCacheManager($cachePool);
        $metadata = new JsonFileMetadataStorage($this->tempDir . '/meta.json');
        $metadata->save($normalizer->buildCanonicalKey($volume, 'public/needle-public.txt', $profile->basePath), new FolderMetadata(tags: ['featured']));

        $denySecretChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $denySecretChecker->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject): bool {
                if ($subject instanceof FileManagerSubject) {
                    return !str_starts_with($subject->path, 'secret');
                }

                return true;
            },
        );
        $allowAllChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $allowAllChecker->method('isGranted')->willReturn(true);

        $restrictedLister = new DirectoryLister($registry, $normalizer, $cacheManager, $metadata, authChecker: $denySecretChecker);
        $adminLister = new DirectoryLister($registry, $normalizer, $cacheManager, $metadata, authChecker: $allowAllChecker);

        $restrictedTree = $restrictedLister->listFullTree($profile, 'local');
        self::assertSame(['public'], array_column($restrictedTree, 'path'));

        // Shared cache warmed by restricted user must NOT strip 'secret' for privileged user
        $adminTree = $adminLister->listFullTree($profile, 'local');
        self::assertSame(['public', 'secret', 'secret/nested'], array_column($adminTree, 'path'));

        // Voter-enabled lister restricts denied subtrees from BOTH listing and folder counts
        $restrictedListing = $restrictedLister->list($profile, 'local');
        $restrictedFolderCounts = [];
        foreach ($restrictedListing->items as $item) {
            self::assertNotSame('secret', $item->path);
            if ($item instanceof FolderItem) {
                $restrictedFolderCounts[$item->path] = $item->itemCount;
            }
        }
        self::assertSame(['public' => 1], $restrictedFolderCounts);

        // Authorized user with voter_enabled: true sees visible folder counts (public=1, secret=1)
        $adminListing = $adminLister->list($profile, 'local');
        $adminCounts = [];
        foreach ($adminListing->items as $item) {
            if ($item instanceof FolderItem) {
                $adminCounts[$item->path] = $item->itemCount;
            }
        }
        self::assertSame(['public' => 1, 'secret' => 1], $adminCounts);

        // Batch metadata preload during searchRecursive (tag search routes through flushRecursiveMetaCandidates)
        $searchResult = $adminLister->searchRecursive($profile, 'local', '', 'featured');
        self::assertCount(1, $searchResult->items);
        self::assertSame(['featured'], $searchResult->items[0]->metadata?->tags);

        // Multi-tenant local sub-volumes inside base_path must use distinct cache keys
        $tenantADir = $this->tempDir . '/tenants/a';
        $tenantBDir = $this->tempDir . '/tenants/b';
        mkdir($tenantADir, 0775, true);
        mkdir($tenantBDir, 0775, true);
        file_put_contents($tenantADir . '/only-a.txt', 'a');
        file_put_contents($tenantBDir . '/only-b.txt', 'b');

        $volA = new VolumeConfig('vol_a', 'Tenant A', 'local', 'LocalFileSystem', $tenantADir);
        $volB = new VolumeConfig('vol_b', 'Tenant B', 'local', 'LocalFileSystem', $tenantBDir);
        $multiProvider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir],
            [
                'vol_a' => ['label' => 'A', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $tenantADir],
                'vol_b' => ['label' => 'B', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $tenantBDir],
            ],
            ['default' => ['volumes' => ['vol_a', 'vol_b'], 'cache' => ['enabled' => true, 'ttl' => 300]]],
        );
        $multiRegistry = new VolumeRegistry($multiProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $multiCache = new DirectoryCacheManager(new ArrayAdapter());
        $multiLister = new DirectoryLister($multiRegistry, $normalizer, $multiCache, new NullMetadataStorage());

        $listA = $multiLister->list($multiProvider->getProfile('default'), 'vol_a');
        $listB = $multiLister->list($multiProvider->getProfile('default'), 'vol_b');
        self::assertSame('only-a.txt', $listA->items[0]->name);
        self::assertSame('only-b.txt', $listB->items[0]->name);
    }

    public function testZipArchiveServiceEnforcesCreateFolderAndBaseFileCollision(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required.');
        }

        mkdir($this->tempDir . '/existing', 0775, true);
        file_put_contents($this->tempDir . '/occupied', 'i am a file');

        $flatZipPath = $this->tempDir . '/flat.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($flatZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('hello.txt', 'hello world');
        $zip->close();

        $nestedZipPath = $this->tempDir . '/nested.zip';
        $zip2 = new ZipArchive();
        self::assertTrue($zip2->open($nestedZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip2->addFromString('sub/hello.txt', 'hello nested');
        $zip2->close();

        $profile = $this->createProfile(
            permissions: ['extract_zip' => true, 'create_folder' => false, 'upload' => true, 'view' => true],
            zip: ['enabled' => true, 'allow_extract' => true],
            security: ['voter_enabled' => false],
        );
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $zipService = new ZipArchiveService($registry, $normalizer);

        // Flat ZIP into existing folder works without CREATE_FOLDER
        $extracted = $zipService->extractArchive($profile, 'flat.zip', 'existing', 'local');
        self::assertSame(['extracted' => 1, 'skipped' => 0, 'targetDir' => 'existing'], $extracted);
        self::assertFileExists($this->tempDir . '/existing/hello.txt');

        // Nested ZIP creating folder requires CREATE_FOLDER
        $denied = false;
        try {
            $zipService->extractArchive($profile, 'nested.zip', 'existing', 'local');
        } catch (AccessDeniedException) {
            $denied = true;
        }
        self::assertTrue($denied);

        // Target directory colliding with existing file throws errors.target_exists
        $profileCanCreate = $this->createProfile(
            permissions: ['extract_zip' => true, 'create_folder' => true, 'upload' => true, 'view' => true],
            zip: ['enabled' => true, 'allow_extract' => true],
            security: ['voter_enabled' => false],
        );
        $collisionCaught = false;
        try {
            $zipService->extractArchive($profileCanCreate, 'flat.zip', 'occupied', 'local');
        } catch (FileManagerException $e) {
            $collisionCaught = $e->getTranslationKey() === 'errors.target_exists';
        }
        self::assertTrue($collisionCaught);
    }

    public function testTrashManagerImageOptimizerAndDynamicPathResolverEdgeCases(): void
    {
        // TrashManager::deletePermanently must NOT swallow MAX_TRASH_MOVE_ITEMS FileManagerException
        mkdir($this->tempDir . '/bulkfolder', 0775, true);
        file_put_contents($this->tempDir . '/bulkfolder/seed.txt', 'seed');

        $profile = $this->createProfile(trash: ['enabled' => true], security: ['voter_enabled' => true]);
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $allowAll = $this->createStub(AuthorizationCheckerInterface::class);
        $allowAll->method('isGranted')->willReturn(true);

        $trashManager = new TrashManager($registry, $normalizer, new EventDispatcher(), authChecker: $allowAll);
        $entry = $trashManager->moveToTrash($profile, 'bulkfolder', 'local');

        $trashRoot = $this->tempDir . '/.trash';
        $seedParent = null;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trashRoot, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $item */
        foreach ($it as $item) {
            if ($item->isFile() && $item->getFilename() === 'seed.txt') {
                $seedParent = $item->getPath();
                break;
            }
        }
        self::assertNotNull($seedParent);

        for ($i = 0; $i < 5001; ++$i) {
            touch($seedParent . DIRECTORY_SEPARATOR . 'extra_' . $i . '.txt');
        }

        $limitThrown = false;
        try {
            $trashManager->deletePermanently($profile, $entry->id, 'local', true);
        } catch (FileManagerException $e) {
            $limitThrown = $e->getTranslationKey() === 'errors.copy_limit_exceeded';
        }
        self::assertTrue($limitThrown);

        // Animated WebP must be bypassed by ImageOptimizer
        $webpPath = $this->tempDir . '/anim.webp';
        $animatedWebp = "RIFF\x20\x00\x00\x00WEBPVP8X\x0A\x00\x00\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x00ANIM";
        file_put_contents($webpPath, $animatedWebp);

        $optProfile = $this->createProfile(imageOptimization: ['enabled' => true, 'quality' => 80, 'format' => 'webp']);
        $optimizer = new ImageOptimizer();
        $result = $optimizer->optimizeIfEnabled($webpPath, 'anim.webp', 'image/webp', $optProfile);
        self::assertSame($webpPath, $result['path']);
        self::assertSame('image/webp', $result['mime']);
        self::assertFalse($result['optimized']);

        // DynamicPathResolver::sanitizeIdentifier collision & Windows reserved device names
        $resolver = new DynamicPathResolver();
        $sanitizeId = new ReflectionMethod($resolver, 'sanitizeIdentifier');
        self::assertSame('tenant_42', $sanitizeId->invoke($resolver, 'tenant_42', 'guest'));
        self::assertStringStartsWith('id_', (string) $sanitizeId->invoke($resolver, 'con', 'guest'));
        self::assertStringStartsWith('id_', (string) $sanitizeId->invoke($resolver, 'PRN', 'guest'));
        self::assertNotSame($sanitizeId->invoke($resolver, 'Tenant_A', 'guest'), $sanitizeId->invoke($resolver, 'tenant_a', 'guest'));
    }

    public function testApiControllerAndImageEditorControllerHardening(): void
    {
        mkdir($this->tempDir . '/docs', 0775, true);
        mkdir($this->tempDir . '/dirA', 0775, true);
        mkdir($this->tempDir . '/dirB', 0775, true);
        mkdir($this->tempDir . '/dest', 0775, true);
        mkdir($this->tempDir . '/protected', 0775, true);
        file_put_contents($this->tempDir . '/docs/ok.txt', 'ok');
        file_put_contents($this->tempDir . '/dirA/same.txt', 'a');
        file_put_contents($this->tempDir . '/dirB/same.txt', 'b');
        file_put_contents($this->tempDir . '/protected/secret.txt', 'secret');

        $profile = $this->createProfile(
            security: ['csrf_protection' => false, 'voter_enabled' => true],
            metadata: ['enabled' => true],
            trash: ['enabled' => true],
        );
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cacheManager = new DirectoryCacheManager(new ArrayAdapter());
        $metaStorage = new JsonFileMetadataStorage($this->tempDir . '/meta.json');
        $metaStorage->save($normalizer->buildCanonicalKey($volume, 'docs', $profile->basePath), new FolderMetadata(pinned: true));

        $events = new EventDispatcher();
        $trash = new TrashManager($registry, $normalizer, $events);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, $metaStorage, $events);
        $lister = new DirectoryLister($registry, $normalizer, $cacheManager, $metaStorage);
        $upload = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $events);
        $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());

        $denyProtectedChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $denyProtectedChecker->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject): bool {
                if ($subject instanceof FileManagerSubject && str_starts_with($subject->path, 'protected')) {
                    return false;
                }
                if ($attribute === FileManagerPermissions::VIEW && $subject instanceof FileManagerSubject && $subject->path === '' && str_contains($subject->volumeName ?? '', 'denied')) {
                    return false;
                }

                return true;
            },
        );

        $opsWithChecker = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, $metaStorage, $events, $denyProtectedChecker);
        $apiWithOpsChecker = new ApiController(
            $configProvider,
            $registry,
            $lister,
            $opsWithChecker,
            new RemoteUrlImporter($upload),
            $trash,
            $cacheManager,
            $thumbs,
            $normalizer,
            $this->createStub(UrlGeneratorInterface::class),
            new Environment(new ArrayLoader([])),
            new CsrfRequestValidator(null),
            $denyProtectedChecker,
        );

        // Controller-only authChecker (even when FileOperationsService has null authChecker) denies denied path atomically
        $api = new ApiController(
            $configProvider,
            $registry,
            $lister,
            $ops,
            new RemoteUrlImporter($upload),
            $trash,
            $cacheManager,
            $thumbs,
            $normalizer,
            $this->createStub(UrlGeneratorInterface::class),
            new Environment(new ArrayLoader([])),
            new CsrfRequestValidator(null),
            $denyProtectedChecker,
        );

        // dialog with array query parameters must return 400 JSON, never 500
        $dialogRes = $api->dialog(Request::create('/fm/dialog', 'GET', ['profile' => ['evil'], 'locale' => ['fr']]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $dialogRes->getStatusCode());

        // saveMetadata with "pinned": null preserves existing pinned=true
        $saveRes = $api->action($this->jsonActionRequest([
            'action' => 'saveMetadata',
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'docs',
            'pinned' => null,
            'title' => 'updated',
        ]));
        self::assertSame(Response::HTTP_OK, $saveRes->getStatusCode());
        self::assertTrue($metaStorage->get($normalizer->buildCanonicalKey($volume, 'docs', $profile->basePath))->pinned);

        // clearCache rejects invalid scope
        $badScopeRes = $api->action($this->jsonActionRequest([
            'action' => 'clearCache',
            'profile' => 'default',
            'volume' => 'local',
            'scope' => 'invalid_scope',
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $badScopeRes->getStatusCode());

        // Atomic bulkMove rejects colliding basenames in same batch leaving both sources untouched
        $collideMoveRes = $apiWithOpsChecker->action($this->jsonActionRequest([
            'action' => 'bulkMove',
            'profile' => 'default',
            'volume' => 'local',
            'paths' => ['dirA/same.txt', 'dirB/same.txt'],
            'targetDir' => 'dest',
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $collideMoveRes->getStatusCode());
        self::assertFileExists($this->tempDir . '/dirA/same.txt');
        self::assertFileDoesNotExist($this->tempDir . '/dest/same.txt');

        // Atomic bulk delete rejects whole batch when second folder is voter-denied (both controller-only and service-auth paths)
        $atomicDelRes = $api->action($this->jsonActionRequest([
            'action' => 'delete',
            'profile' => 'default',
            'volume' => 'local',
            'paths' => ['docs/ok.txt', 'protected'],
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $atomicDelRes->getStatusCode());
        self::assertFileExists($this->tempDir . '/docs/ok.txt');
        self::assertSame(Response::HTTP_FORBIDDEN, $apiWithOpsChecker->action($this->jsonActionRequest([
            'action' => 'delete',
            'profile' => 'default',
            'volume' => 'local',
            'paths' => ['docs/ok.txt', 'protected'],
        ]))->getStatusCode());

        // Unauthorized VIEW denial across trash endpoints + anti-existence oracle
        $denyAll = $this->createStub(AuthorizationCheckerInterface::class);
        $denyAll->method('isGranted')->willReturn(false);
        $deniedOps = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, $metaStorage, $events, $denyAll);
        $deniedApi = new ApiController(
            $configProvider,
            $registry,
            $lister,
            $deniedOps,
            new RemoteUrlImporter($upload),
            $trash,
            $cacheManager,
            $thumbs,
            $normalizer,
            $this->createStub(UrlGeneratorInterface::class),
            new Environment(new ArrayLoader([])),
            new CsrfRequestValidator(null),
            $denyAll,
        );

        self::assertSame(403, $deniedApi->trash(Request::create('/fm/trash', 'GET', ['profile' => 'default', 'volume' => 'local']))->getStatusCode());
        self::assertSame(403, $deniedApi->action($this->jsonActionRequest([
            'action' => 'restoreTrash',
            'profile' => 'default',
            'volume' => 'local',
            'trashId' => '20260101_000000_abcd1234',
        ]))->getStatusCode());
        self::assertSame(403, $deniedApi->action($this->jsonActionRequest([
            'action' => 'purgeTrash',
            'profile' => 'default',
            'volume' => 'local',
            'trashId' => '20260101_000000_abcd1234',
        ]))->getStatusCode());
        self::assertSame(403, $deniedApi->action($this->jsonActionRequest([
            'action' => 'emptyTrash',
            'profile' => 'default',
            'volume' => 'local',
        ]))->getStatusCode());
        self::assertSame(403, $deniedApi->action($this->jsonActionRequest([
            'action' => 'clearCache',
            'profile' => 'default',
            'volume' => 'local',
        ]))->getStatusCode());

        // Authorized missing trashId returns clean 422 not found message
        $missingTrashRes = $api->action($this->jsonActionRequest([
            'action' => 'restoreTrash',
            'profile' => 'default',
            'volume' => 'local',
            'trashId' => '20260101_000000_abcd1234',
        ]));
        self::assertSame(422, $missingTrashRes->getStatusCode());

        // ImageEditorController enforces authorization BEFORE existence check
        $imgTemp = $this->tempDir . '/upload.png';
        file_put_contents($imgTemp, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $uploadedFile = new UploadedFile($imgTemp, 'edited.png', 'image/png', null, true);

        $imageEditor = new ImageEditorController(
            $configProvider,
            $registry,
            $normalizer,
            $thumbs,
            new CsrfRequestValidator(null),
            $events,
            $denyAll,
        );
        $editReq = Request::create('/fm/image-edit', 'POST', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'does-not-exist.png',
        ], [], ['file' => $uploadedFile]);
        $editRes = $imageEditor($editReq);
        self::assertSame(Response::HTTP_FORBIDDEN, $editRes->getStatusCode());
    }

    public function testCliCommandsDiConfigAutoInjectAndPathNormalizerEdgeCases(): void
    {
        // CLI commands skipping {user_id} private volumes with SUCCESS and skip note
        $privateDir = $this->tempDir . '/users/{user_id}';
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir],
            ['private' => ['label' => 'Private', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $privateDir]],
            ['default' => ['volumes' => ['private'], 'trash' => ['enabled' => true], 'thumbnail' => ['cache_dir' => $this->tempDir . '/thumbs']]],
        );
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $scanner = new ProfileFileScanner($provider, $registry, $normalizer);
        $cacheManager = new DirectoryCacheManager(new ArrayAdapter());
        $dupDetector = new DuplicateDetector($registry, $normalizer, $cacheManager);
        $trashManager = new TrashManager($registry, $normalizer, new EventDispatcher());
        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 'test-secret');

        $dupTester = new CommandTester(new DuplicatesCommand($scanner, $dupDetector));
        self::assertSame(Command::SUCCESS, $dupTester->execute(['--volume' => 'private']));

        $purgeTester = new CommandTester(new TrashPurgeCommand($trashManager, $scanner));
        self::assertSame(Command::SUCCESS, $purgeTester->execute(['--volume' => 'private']));

        $clearCmd = new ThumbnailClearCommand($provider, $thumbManager, $scanner);
        $clearTester = new CommandTester($clearCmd);
        self::assertSame(Command::SUCCESS, $clearTester->execute(['--volume' => 'private']));
        self::assertStringContainsString('{user_id}', $clearTester->getDisplay());

        // Nested cache_dir overlap helper in ThumbnailClearCommand
        $isCovered = new ReflectionMethod($clearCmd, 'isCoveredByClearedDir');
        self::assertTrue($isCovered->invoke($clearCmd, 'var/thumbs/sub', ['var/thumbs' => true]));
        self::assertFalse($isCovered->invoke($clearCmd, 'var/thumbs_other', ['var/thumbs' => true]));

        // Configuration zip.allow_extract: false overrides inherited profiles.default.permissions.extract_zip: true
        $processor = new Processor();
        $mergedConfig = $processor->processConfiguration(new Configuration(), [
            [
                'profiles' => ['default' => ['permissions' => ['extract_zip' => true], 'zip' => ['allow_extract' => true]]],
            ],
            [
                'profiles' => ['default' => ['zip' => ['allow_extract' => false]]],
            ],
        ]);
        self::assertFalse($mergedConfig['profiles']['default']['permissions']['extract_zip']);

        // FlysystemServiceLocatorPass resolves %parameter% in driver_options.service
        $container = new ContainerBuilder();
        $container->setParameter('app.s3_service_id', 'app.flysystem.s3');
        $container->setParameter('liveux_file_manager.volumes', [
            'cloud' => ['driver' => 'FlysystemService', 'options' => ['service' => '%app.s3_service_id%']],
        ]);
        $container->setParameter('liveux_file_manager.profiles', []);
        $container->setDefinition(FlysystemStorageFactory::class, new Definition(FlysystemStorageFactory::class));
        $container->setDefinition('app.flysystem.s3', (new Definition(FilesystemOperator::class))->setPublic(true));

        (new FlysystemServiceLocatorPass())->process($container);
        $args = $container->getDefinition(FlysystemStorageFactory::class)->getArguments();
        self::assertArrayHasKey('$serviceLocator', $args);

        // AutoInjectDialogHostSubscriber allows custom <template-card> elements while skipping inside <template>
        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div data-fm-host="1"></div>',
        ]));
        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $provider);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $customElResp = new Response('<html><body><template-card>OK</template-card></body></html>', 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $customElResp));
        self::assertStringContainsString('data-fm-host="1"', (string) $customElResp->getContent());

        // PathNormalizer canonicalizes physical '.' / '..' in computeRelativeToBase() & rejects '..' in virtual paths
        self::assertSame('a/b', $normalizer->normalize('a/./b'));
        if (DIRECTORY_SEPARATOR === '\\') {
            $subVol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', 'c:\\site\\admin\\..\\public\\uploads\\sub');
            self::assertSame('local::uploads/sub/photo.jpg', $normalizer->buildCanonicalKey($subVol, 'photo.jpg', 'C:\\Site\\Public'));
        }
    }

    /**
     * @param list<string>         $volumes
     * @param array<string, mixed> $security
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $zip
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $imageOptimization
     */
    private function createProfile(
        array $volumes = ['local'],
        array $security = [],
        array $permissions = [],
        array $zip = [],
        array $trash = ['enabled' => false],
        array $metadata = ['enabled' => false],
        array $imageOptimization = ['enabled' => false],
    ): ProfileConfig {
        return new ProfileConfig(
            name: 'default',
            basePath: $this->tempDir,
            theme: 'bootstrap5',
            locale: 'en',
            volumes: $volumes,
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
            uploadAllow: ['image/*', 'text/plain', 'application/pdf'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: $imageOptimization,
            thumbnail: ['enabled' => false, 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: $trash,
            zip: $zip,
            ui: [],
            permissions: $permissions,
            security: $security,
            cache: ['enabled' => false, 'ttl' => 300],
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonActionRequest(array $payload): Request
    {
        return Request::create(
            '/_liveux-file-manager/api/action',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
