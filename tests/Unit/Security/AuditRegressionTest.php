<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use FilesystemIterator;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\FlysystemServiceLocatorPass;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\Event\TrashRestoredEvent;
use LiveUX\FileManagerBundle\EventListener\AuditLogListener;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
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
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

/**
 * End-to-end regression suite covering all CRITICAL, HIGH, and MEDIUM security/bug findings
 * identified and fixed during the full-bundle audit.
 */
final class AuditRegressionTest extends TestCase
{
    private string $tempDir;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_audit_' . bin2hex(random_bytes(4));
        $this->uploadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'uploads';
        mkdir($this->uploadDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAuditLogListenerHandlesTrashRestoredAndPurgedEventsWithoutCrash(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            /** @param array<mixed> $context */
            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $profile = $this->createProfile();
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->uploadDir);

        $disabledListener = new AuditLogListener(false, 'info', $logger);
        $disabledListener->onTrashRestored(new TrashRestoredEvent($profile, $volume, 'docs/a.pdf', 'local::/docs/a.pdf'));
        $disabledListener->onTrashPurged(new TrashPurgedEvent($profile, $volume, '.trash/id1', 'local::/.trash/id1', 3));
        self::assertCount(0, $records);

        $enabledListener = new AuditLogListener(true, 'info', $logger);
        $enabledListener->onTrashRestored(new TrashRestoredEvent($profile, $volume, 'docs/a.pdf', 'local::/docs/a.pdf'));
        $enabledListener->onTrashPurged(new TrashPurgedEvent($profile, $volume, '.trash/id1', 'local::/.trash/id1', 3));

        self::assertCount(2, $records);
        self::assertSame('trash.restored', $records[0]['context']['action']);
        self::assertSame('docs/a.pdf', $records[0]['context']['path']);
        self::assertSame('trash.purged', $records[1]['context']['action']);
        self::assertSame(3, $records[1]['context']['purgedCount']);
    }

    public function testDoctrineDbalMetadataStorageHandlesUnchangedUpsertLikeEscapingAndReset(): void
    {
        if (!class_exists(DriverManager::class) || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('doctrine/dbal + pdo_sqlite required.');
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DoctrineDbalMetadataStorage($connection);

        $meta = new FolderMetadata(color: '#2563eb', pinned: true, alt: 'First');
        $storage->save('local::/50%_sale!dir/item.png', $meta);
        // Re-saving identical values must NOT trigger UniqueConstraintViolationException
        $storage->save('local::/50%_sale!dir/item.png', $meta);
        $storage->save('local::/50X_sale!dir/keep.png', new FolderMetadata(alt: 'Keep Me'));

        $storage->reset();
        self::assertSame('First', $storage->get('local::/50%_sale!dir/item.png')->alt);

        // Deleting prefix with SQL wildcards (%, _, !) must NOT delete sibling 50X_sale!dir
        $storage->delete('local::/50%_sale!dir');
        $storage->reset();
        self::assertTrue($storage->get('local::/50%_sale!dir/item.png')->isEmpty());
        self::assertSame('Keep Me', $storage->get('local::/50X_sale!dir/keep.png')->alt);
    }

    public function testSvgSanitizerBlocksCssEscapesProtocolRelativeAndPresentationUrlAttributes(): void
    {
        $sanitizer = new SvgSanitizer();

        $malicious = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
            <style>
                @\69 mport "https://evil.example/x.css";
                rect { fill: url("//evil.example/tracker.svg#a"); background: url("data:image/svg+xml;base64,PHN2Zz4="); }
                circle { stroke: url(#safe-grad); }
            </style>
            <rect width="50" height="50" filter="url(https://evil.example/f.svg#x)" fill="url(//evil.example/a)" />
            <circle cx="50" cy="50" r="20" fill="url(#safe-grad)" />
        </svg>
        SVG;

        $clean = $sanitizer->sanitize($malicious);
        self::assertNotSame('', $clean);
        self::assertStringNotContainsString('evil.example', $clean);
        self::assertStringNotContainsString('data:image/svg', $clean);
        self::assertStringNotContainsString('filter=', $clean);
        self::assertStringContainsString('url(#safe-grad)', $clean);
    }

    public function testDirectoryCacheManagerIsolatesProfilesSharingCanonicalDirectory(): void
    {
        $pool = new ArrayAdapter();
        $cache = new DirectoryCacheManager($pool);

        $profileA = $this->createProfile(name: 'profile_a', excludePatterns: ['/^secret/']);
        $profileB = $this->createProfile(name: 'profile_b', excludePatterns: []);

        $resA = $cache->remember($profileA, 'local::/shared', static fn (): array => ['public.pdf']);
        $resB = $cache->remember($profileB, 'local::/shared', static fn (): array => ['public.pdf', 'secret.pdf']);

        self::assertFalse($resA['hit']);
        self::assertFalse($resB['hit'], 'Profile B must NOT receive Profile A cached listing.');
        self::assertSame(['public.pdf'], $resA['data']);
        self::assertSame(['public.pdf', 'secret.pdf'], $resB['data']);

        // Invalidating the shared canonical directory invalidates BOTH profiles
        $cache->invalidateDirectory('local::/shared');
        $resA2 = $cache->remember($profileA, 'local::/shared', static fn (): array => ['updated.pdf']);
        $resB2 = $cache->remember($profileB, 'local::/shared', static fn (): array => ['updated.pdf']);
        self::assertFalse($resA2['hit']);
        self::assertFalse($resB2['hit']);
    }

    public function testFileOperationsEnforcesTrashPurgeOnForceDeleteUsageGuardOnRenameAndQuotaOnClone(): void
    {
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'contract.pdf', str_repeat('A', 2048));

        // 1. Force delete requires FILE_MANAGER_TRASH_PURGE (not just DELETE)
        $profileVoter = $this->createProfile(security: ['voter_enabled' => true, 'csrf_protection' => false]);
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr): bool => $attr === FileManagerPermissions::DELETE,
        );

        $opsVoter = $this->createFileOperations($profileVoter, authChecker: $auth);
        try {
            $opsVoter->delete($profileVoter, 'contract.pdf', 'local', forcePermanent: true);
            self::fail('Expected AccessDeniedException when forcing delete without TRASH_PURGE permission.');
        } catch (AccessDeniedException) {
            self::assertFileExists($this->uploadDir . DIRECTORY_SEPARATOR . 'contract.pdf');
        }

        // 2. Usage guard with policy=block prevents renaming an in-use asset
        $profileGuard = $this->createProfile(usageGuard: ['enabled' => true, 'policy' => 'block', 'enforce_server_side' => true]);
        $checker = new class implements AssetUsageCheckerInterface {
            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                return [new AssetUsageReference('Order #99', 'Order')];
            }
        };
        $usageGuard = new AssetUsageGuardService(
            volumeRegistry: $this->createRegistry($profileGuard),
            pathNormalizer: new PathNormalizer(),
            checkers: [$checker],
        );
        $opsGuard = $this->createFileOperations($profileGuard, usageGuard: $usageGuard);

        try {
            $opsGuard->rename($profileGuard, 'contract.pdf', 'renamed.pdf', 'local');
            self::fail('Expected FileManagerException when renaming an asset protected by usage guard.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.rename_blocked_in_use', $e->getTranslationKey());
        }

        // 2b. Usage guard with policy=block uses action-specific move error on move
        mkdir($this->uploadDir . DIRECTORY_SEPARATOR . 'archive', 0775, true);
        try {
            $opsGuard->moveOrCopy($profileGuard, 'contract.pdf', 'archive', isCopy: false, sourceVolumeName: 'local');
            self::fail('Expected FileManagerException when moving an asset protected by usage guard.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.move_blocked_in_use', $e->getTranslationKey());
        }

        // 3. Quota enforcement blocks cloning when exceeding profile storage_quota
        $profileQuota = $this->createProfile(storageQuota: '3K');
        $registryQuota = $this->createRegistry($profileQuota);
        $quotaChecker = new StorageQuotaChecker($registryQuota, new PathNormalizer(), new DirectoryCacheManager());
        $opsQuota = $this->createFileOperations($profileQuota, quotaChecker: $quotaChecker);

        $this->expectException(UploadValidationException::class);
        $opsQuota->cloneItem($profileQuota, 'contract.pdf', 'contract-copy.pdf', 'local');
    }

    public function testStorageQuotaDetailedBreakdownHidesDotfilesAndExcludedPatterns(): void
    {
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'visible.pdf', str_repeat('A', 100));
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . '.env', str_repeat('B', 500));
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'secret_dump.sql', str_repeat('C', 800));
        mkdir($this->uploadDir . DIRECTORY_SEPARATOR . '.trash/entry1', 0775, true);
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . '.trash/entry1/.meta.json', '{}');
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . '.trash/entry1/old.pdf', str_repeat('D', 50));

        $profile = $this->createProfile(excludePatterns: ['/^secret_/']);
        $quotaChecker = new StorageQuotaChecker($this->createRegistry($profile), new PathNormalizer(), new DirectoryCacheManager());

        $breakdown = $quotaChecker->getDetailedBreakdown($profile, 'local');
        $names = array_column($breakdown['largestFiles'], 'name');

        self::assertSame(['visible.pdf'], $names);
        self::assertSame(1, $breakdown['counts']['files']);
        self::assertSame(1, $breakdown['counts']['trashFiles'], '.meta.json sidecar must not be counted as a user trash file');
    }

    public function testZipArchiveServiceRecursivelyZipsSelectedFoldersAndHonorsIgnoreRules(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required.');
        }

        mkdir($this->uploadDir . DIRECTORY_SEPARATOR . 'katalog' . DIRECTORY_SEPARATOR . 'alt', 0775, true);
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'katalog' . DIRECTORY_SEPARATOR . 'brochure.pdf', '%PDF-1.4');
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'katalog' . DIRECTORY_SEPARATOR . 'alt' . DIRECTORY_SEPARATOR . 'spec.txt', 'hello');
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'katalog' . DIRECTORY_SEPARATOR . '.hidden', 'secret');
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'root.txt', 'top');

        $profile = $this->createProfile();
        $zipService = new ZipArchiveService($this->createRegistry($profile), new PathNormalizer());

        $tempZip = $zipService->createTempZip($profile, ['katalog', 'root.txt'], 'local');
        try {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($tempZip));
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entries[] = $zip->getNameIndex($i);
            }
            $zip->close();

            self::assertContains('katalog/brochure.pdf', $entries);
            self::assertContains('katalog/alt/spec.txt', $entries);
            self::assertContains('root.txt', $entries);
            self::assertNotContains('katalog/.hidden', $entries);
        } finally {
            @unlink($tempZip);
        }
    }

    public function testPathNormalizerAndFileSecurityGuardBlockMultiDotReservedNamesAndMultiSegmentTrash(): void
    {
        $normalizer = new PathNormalizer();
        $guard = new FileSecurityGuard();
        $profile = $this->createProfile(trash: ['enabled' => true, 'directory' => 'var/trash']);

        $this->expectException(InvalidPathException::class);
        try {
            $normalizer->normalize('docs/con.foo.txt');
        } finally {
            $thrown = false;
            try {
                $guard->assertAccessiblePath('var/trash/20260101_abcd/file.pdf', $profile);
            } catch (AccessDeniedException) {
                $thrown = true;
            }
            self::assertTrue($thrown, 'Multi-segment trash directory prefix must be blocked.');
        }
    }

    public function testFileManagerPickerTypeEnforcesMaxFilesServerSide(): void
    {
        $factory = Forms::createFormFactoryBuilder()->getFormFactory();
        $form = $factory->create(FileManagerPickerType::class, [], [
            'multiple' => true,
            'max_files' => 2,
        ]);

        $form->submit(json_encode(['/uploads/a.jpg', '/uploads/b.jpg', '/uploads/c.jpg']));
        self::assertFalse($form->isValid(), 'Submitting 3 files when max_files=2 must fail server-side validation.');
        self::assertGreaterThan(0, count($form->getErrors()));
    }

    public function testApiControllerDialogReturnsHardenedPlainTextOnUnknownProfileXss(): void
    {
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willThrowException(
            new FileManagerException('FileManager profile "<script>alert(1)</script>" is not configured.', 'errors.profile_not_found', ['%profile%' => '<script>alert(1)</script>']),
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $dispatcher = new EventDispatcher();
        $uploadManager = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher);
        $controller = new ApiController(
            configProvider: $configProvider,
            volumeRegistry: $registry,
            directoryLister: new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage()),
            operations: new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), new TrashManager($registry, $normalizer, $dispatcher), new NullMetadataStorage(), $dispatcher),
            remoteUrlImporter: new RemoteUrlImporter($uploadManager, $registry, $normalizer),
            trashManager: new TrashManager($registry, $normalizer, $dispatcher),
            cacheManager: new DirectoryCacheManager(),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            pathNormalizer: $normalizer,
            urlGenerator: $this->createStub(UrlGeneratorInterface::class),
            twig: new Environment(new ArrayLoader([])),
            csrfValidator: new CsrfRequestValidator(null),
        );

        $res = $controller->dialog(Request::create('/_liveux-file-manager/dialog?profile=' . urlencode('<script>alert(1)</script>')));
        self::assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
        self::assertSame('text/plain; charset=UTF-8', $res->headers->get('Content-Type'));
        self::assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'", $res->headers->get('Content-Security-Policy'));
    }

    public function testAuthorizerAndCsrfFailClosedWhenSecurityServicesUnavailable(): void
    {
        $profile = $this->createProfile(
            security: ['voter_enabled' => false, 'csrf_protection' => true],
            permissions: [
                'view' => true,
                'delete' => false,
                'rename' => 'ROLE_ADMIN',
                'move' => ['ROLE_EDITOR'],
            ],
        );

        $authorizer = new FileManagerAuthorizer(null);
        self::assertTrue($authorizer->isGranted($profile, FileManagerPermissions::VIEW));
        self::assertFalse($authorizer->isGranted($profile, FileManagerPermissions::DELETE), 'Explicit false must deny even when voter_enabled=false.');
        self::assertFalse($authorizer->isGranted($profile, FileManagerPermissions::RENAME), 'Role string must fail closed when authChecker is null.');
        self::assertFalse($authorizer->isGranted($profile, FileManagerPermissions::MOVE), 'Role array must fail closed when authChecker is null.');

        $csrfFailClosed = new CsrfRequestValidator(null, failClosedWhenUnavailable: true);
        $this->expectException(AccessDeniedException::class);
        $csrfFailClosed->assertValid(Request::create('/api', 'POST'), $profile);
    }

    public function testPathNormalizerDisambiguatesOutOfBaseLocalAndCustomS3EndpointsAndBlocksAds(): void
    {
        $normalizer = new PathNormalizer();

        // NTFS Alternate Data Stream & illegal chars rejected
        $rejected = false;
        try {
            $normalizer->normalize('docs/readme.txt::$DATA');
        } catch (InvalidPathException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'NTFS ADS syntax with colon must be rejected.');

        // Guard blocks .env.local
        $guard = new FileSecurityGuard();
        $envBlocked = false;
        try {
            $guard->assertSafeFilename('.env.local');
        } catch (FileManagerException) {
            $envBlocked = true;
        }
        self::assertTrue($envBlocked, '.env.* files must be blocked.');

        // Out-of-base local volumes in different roots produce distinct canonical keys
        $volA = new VolumeConfig('a', 'A', 'local', 'LocalFileSystem', '/var/storage/tenant-a');
        $volB = new VolumeConfig('b', 'B', 'local', 'LocalFileSystem', '/var/storage/tenant-b');
        $keyA = $normalizer->buildCanonicalKey($volA, 'photo.jpg', '/srv/default-uploads');
        $keyB = $normalizer->buildCanonicalKey($volB, 'photo.jpg', '/srv/default-uploads');
        self::assertNotSame($keyA, $keyB);

        // S3 volumes with same bucket name on different endpoints produce distinct canonical keys
        $s3A = new VolumeConfig('s3a', 'A', 'network', 'AwsS3', driverOptions: ['bucket' => 'media', 'endpoint' => 'https://a.r2.cloudflarestorage.com']);
        $s3B = new VolumeConfig('s3b', 'B', 'network', 'AwsS3', driverOptions: ['bucket' => 'media', 'endpoint' => 'https://b.storage.supabase.co/storage/v1/s3']);
        $s3Aws = new VolumeConfig('aws', 'AWS', 'network', 'AwsS3', driverOptions: ['bucket' => 'media']);
        self::assertNotSame($normalizer->buildCanonicalKey($s3A, 'a.png'), $normalizer->buildCanonicalKey($s3B, 'a.png'));
        self::assertSame('s3::media/a.png', $normalizer->buildCanonicalKey($s3Aws, 'a.png'));
    }

    public function testDownloadControllerEnforcesDownloadPermissionOnAttachmentFallback(): void
    {
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'archive.bin', 'binary-content');

        $profile = $this->createProfile(
            security: ['voter_enabled' => true, 'csrf_protection' => false],
            permissions: ['view' => true, 'download' => false],
        );
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr): bool => $attr === FileManagerPermissions::VIEW,
        );

        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn(new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->uploadDir));

        $controller = new DownloadController(
            $configProvider,
            $this->createRegistry($profile),
            new PathNormalizer(),
            $auth,
        );

        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create('/_liveux-file-manager/download?profile=default&volume=local&path=archive.bin&inline=1'));
    }

    public function testByteSizeParserFilenameNormalizerAndIconSetChildAttributes(): void
    {
        // ByteSizeParser handles MB, KB, GB, TB, decimals, -1
        self::assertSame(10485760, ByteSizeParser::parse('10MB'));
        self::assertSame(512000, ByteSizeParser::parse('500KB'));
        self::assertSame(1610612736, ByteSizeParser::parse('1.5G'));
        self::assertSame(0, ByteSizeParser::parse('-1'));
        self::assertSame(0, ByteSizeParser::parse('0'));

        // FilenameNormalizer preserves uppercase, snake_case & camelCase even when slugger=true
        $norm = new FilenameNormalizer();
        self::assertSame('HELLO-WORLD.pdf', $norm->normalizeForUpload('Hello World.pdf', $this->createProfile(naming: ['slugger' => true, 'transformer' => 'uppercase'])));
        self::assertSame('hello_world.pdf', $norm->normalizeForUpload('Hello World.pdf', $this->createProfile(naming: ['slugger' => true, 'transformer' => 'snake'])));
        self::assertSame('myProductImage.png', $norm->normalizeForUpload('My Product Image.png', $this->createProfile(naming: ['slugger' => true, 'transformer' => 'camel'])));

        // IconSetRegistry only updates root <svg> width/height, never child <rect> attributes
        $customDir = $this->tempDir . DIRECTORY_SEPARATOR . 'custom_icons';
        mkdir($customDir . DIRECTORY_SEPARATOR . 'ui', 0775, true);
        file_put_contents(
            $customDir . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'grid.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/></svg>',
        );
        $icons = new IconSetRegistry(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'icons');
        $rendered = $icons->renderInlineIcon('grid', $customDir, 16, group: 'ui', class: 'test-cls', allowCustomDirectory: true);
        self::assertStringContainsString('width="16" height="16"', $rendered);
        self::assertStringContainsString('<rect x="3" y="3" width="7" height="7"', $rendered);
    }

    public function testImageEditorRejectsGifOverwriteAndZipExcludesBlockedFiles(): void
    {
        // 1. ImageEditorController rejects overwriting .gif even with valid PNG payload
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($pngBytes);
        file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'anim.gif', 'GIF89a');
        $tmpPng = $this->tempDir . DIRECTORY_SEPARATOR . 'edited.png';
        file_put_contents($tmpPng, $pngBytes);

        $profile = $this->createProfile();
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn(new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->uploadDir));

        $editorCtrl = new ImageEditorController(
            configProvider: $configProvider,
            volumeRegistry: $this->createRegistry($profile),
            pathNormalizer: new PathNormalizer(),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            csrfValidator: new CsrfRequestValidator(null),
            eventDispatcher: new EventDispatcher(),
        );

        $req = Request::create('/_liveux-file-manager/image-edit', 'POST', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'anim.gif',
            'saveMode' => 'overwrite',
        ], [], [
            'file' => new UploadedFile($tmpPng, 'anim.png', 'image/png', null, true),
        ]);

        $res = $editorCtrl($req);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $res->getStatusCode());

        // 2. ZipArchiveService excludes blocked system files (web.config) when zipping folders
        if (class_exists(ZipArchive::class)) {
            mkdir($this->uploadDir . DIRECTORY_SEPARATOR . 'pack', 0775, true);
            file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'pack' . DIRECTORY_SEPARATOR . 'safe.txt', 'ok');
            file_put_contents($this->uploadDir . DIRECTORY_SEPARATOR . 'pack' . DIRECTORY_SEPARATOR . 'web.config', '<configuration/>');

            $zipService = new ZipArchiveService($this->createRegistry($profile), new PathNormalizer());
            $tempZip = $zipService->createTempZip($profile, ['pack'], 'local');
            try {
                $zip = new ZipArchive();
                self::assertTrue($zip->open($tempZip));
                $names = [];
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $names[] = $zip->getNameIndex($i);
                }
                $zip->close();
                self::assertContains('pack/safe.txt', $names);
                self::assertNotContains('pack/web.config', $names);
            } finally {
                @unlink($tempZip);
            }
        }
    }

    public function testFlysystemServiceLocatorPassWiresPrivateServicesAndMetadataSyncCopiesAndPurges(): void
    {
        // 1. Compiler pass collects configured Flysystem service IDs into a ServiceLocator
        $container = new ContainerBuilder();
        $container->setParameter('liveux_file_manager.volumes', [
            'custom_s3' => ['driver' => 'FlysystemService', 'driver_options' => ['service' => 'app.private_storage']],
        ]);
        $container->setParameter('liveux_file_manager.profiles', []);
        $container->setDefinition('app.private_storage', (new Definition(FilesystemOperator::class))->setPublic(false));
        $container->setDefinition(FlysystemStorageFactory::class, new Definition(FlysystemStorageFactory::class));

        (new FlysystemServiceLocatorPass())->process($container);
        $args = $container->getDefinition(FlysystemStorageFactory::class)->getArguments();
        self::assertArrayHasKey('$serviceLocator', $args);
        self::assertInstanceOf(Reference::class, $args['$serviceLocator']);
        $locatorDef = $container->getDefinition((string) $args['$serviceLocator']);
        $locatorMap = $locatorDef->getArgument(0);
        self::assertIsArray($locatorMap);
        self::assertArrayHasKey('app.private_storage', $locatorMap);

        // 2. MetadataSyncListener copies nested folder metadata on copy and purges on TrashPurgedEvent
        $metaFile = $this->tempDir . DIRECTORY_SEPARATOR . 'meta.json';
        $storage = new JsonFileMetadataStorage($metaFile);
        $listener = new MetadataSyncListener($storage);

        $profile = $this->createProfile();
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->uploadDir);
        $storage->save('local::/src-dir', new FolderMetadata(color: '#2563eb'));
        $storage->save('local::/src-dir/hero.jpg', new FolderMetadata(alt: 'Hero Alt'));

        $listener->onItemMoved(new ItemMovedEvent($profile, $volume, 'src-dir', 'local::/src-dir', $volume, 'copy-dir', 'local::/copy-dir', isFolder: true, isCopy: true));
        self::assertSame('#2563eb', $storage->get('local::/copy-dir')->color);
        self::assertSame('Hero Alt', $storage->get('local::/copy-dir/hero.jpg')->alt);

        $listener->onTrashPurged(new TrashPurgedEvent($profile, $volume, '.trash/id1', 'local::/.trash/id1', 1, 'local::/copy-dir'));
        self::assertTrue($storage->get('local::/copy-dir')->isEmpty());
        self::assertTrue($storage->get('local::/copy-dir/hero.jpg')->isEmpty());
    }

    /**
     * @param list<string>         $excludePatterns
     * @param array<string, mixed> $security
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $usageGuard
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $naming
     */
    private function createProfile(
        string $name = 'default',
        array $excludePatterns = [],
        array $security = ['voter_enabled' => false, 'csrf_protection' => false],
        array $trash = ['enabled' => true, 'directory' => '.trash'],
        array $usageGuard = ['enabled' => false],
        ?string $storageQuota = null,
        array $permissions = [],
        array $naming = ['slugger' => true],
    ): ProfileConfig {
        return new ProfileConfig(
            name: $name,
            basePath: $this->uploadDir,
            theme: 'shadcn',
            locale: 'en',
            volumes: ['local'],
            driver: 'LocalFileSystem',
            path: $this->uploadDir,
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: $excludePatterns,
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: [],
            uploadDeny: ['text/x-php'],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false],
            naming: $naming,
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false],
            trash: $trash,
            zip: ['enabled' => true, 'max_files' => 100, 'max_total_size' => '50M'],
            ui: [],
            permissions: $permissions,
            security: $security,
            cache: ['enabled' => true, 'ttl' => 3600],
            metadata: ['enabled' => false],
            storageQuota: $storageQuota,
            usageGuard: $usageGuard,
        );
    }

    private function createRegistry(ProfileConfig $profile): VolumeRegistry
    {
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->uploadDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        return new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
    }

    private function createFileOperations(
        ProfileConfig $profile,
        ?AuthorizationCheckerInterface $authChecker = null,
        ?AssetUsageGuardService $usageGuard = null,
        ?StorageQuotaChecker $quotaChecker = null,
    ): FileOperationsService {
        $registry = $this->createRegistry($profile);
        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();

        return new FileOperationsService(
            volumeRegistry: $registry,
            pathNormalizer: $normalizer,
            filenameNormalizer: new FilenameNormalizer(),
            trashManager: new TrashManager($registry, $normalizer, $dispatcher),
            metadataStorage: new NullMetadataStorage(),
            eventDispatcher: $dispatcher,
            authChecker: $authChecker,
            usageGuard: $usageGuard,
            quotaChecker: $quotaChecker,
        );
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
