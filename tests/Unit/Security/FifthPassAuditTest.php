<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use FilesystemIterator;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Command\InstallSkillsCommand;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\Controller\UploadController;
use LiveUX\FileManagerBundle\DataCollector\FileManagerDataCollector;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

/**
 * Regression suite for the fifth zero-tolerance security, concurrency, and logic audit pass.
 */
final class FifthPassAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_fifth_' . bin2hex(random_bytes(4));
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

    public function testCrossVolumeMoveAbortsWithoutDestroyingVoterProtectedOrHiddenChildFiles(): void
    {
        $srcRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'vol_src';
        $dstRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'vol_dst';
        mkdir($srcRoot . DIRECTORY_SEPARATOR . 'dept', 0775, true);
        mkdir($dstRoot . DIRECTORY_SEPARATOR . 'moved_target', 0775, true);
        file_put_contents($srcRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'public.txt', 'ok');
        file_put_contents($srcRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'secret.txt', 'top-secret');

        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: [
                'src_vol' => ['driver' => 'LocalFileSystem', 'path' => $srcRoot],
                'dst_vol' => ['driver' => 'LocalFileSystem', 'path' => $dstRoot],
            ],
            profiles: [
                'default' => [
                    'volumes' => ['src_vol', 'dst_vol'],
                    'security' => ['voter_enabled' => true],
                ],
            ],
        );
        $profile = $configProvider->getProfile('default');
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static function (mixed $attr, mixed $subject): bool {
                if (is_object($subject) && property_exists($subject, 'path') && $subject->path === 'dept/secret.txt') {
                    return false;
                }

                return true;
            },
        );

        $dispatcher = new EventDispatcher();
        $trash = new TrashManager($registry, $normalizer, $dispatcher, authChecker: $auth);
        $ops = new FileOperationsService(
            $registry,
            $normalizer,
            new FilenameNormalizer(),
            $trash,
            new NullMetadataStorage(),
            $dispatcher,
            $auth,
        );

        // Copy is allowed to skip unauthorized child without touching source
        $ops->moveOrCopy($profile, 'dept', '', true, 'src_vol', 'dst_vol');
        self::assertFileExists($dstRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'public.txt');
        self::assertFileDoesNotExist($dstRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'secret.txt');
        self::assertFileExists($srcRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'secret.txt');

        // Move MUST abort before deleting source so secret.txt is never destroyed!
        try {
            $ops->moveOrCopy($profile, 'dept', 'moved_target', false, 'src_vol', 'dst_vol');
            self::fail('Expected AccessDeniedException when moving folder with Voter-denied child.');
        } catch (AccessDeniedException) {
            self::assertFileExists($srcRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'secret.txt');
        }

        // Local folder rename MUST also check child Voter permissions
        try {
            $ops->rename($profile, 'dept', 'dept-renamed', 'src_vol');
            self::fail('Expected AccessDeniedException when renaming folder with Voter-denied child.');
        } catch (AccessDeniedException) {
            self::assertFileExists($srcRoot . DIRECTORY_SEPARATOR . 'dept' . DIRECTORY_SEPARATOR . 'secret.txt');
        }
    }

    public function testCreateFolderIfNotExistsReturnsNormalizedExistingPathWithoutThrowing(): void
    {
        [$profile, $registry, $ops] = $this->buildSimpleOps(['slugger' => true, 'transformer' => 'lowercase']);

        $first = $ops->createFolder($profile, '', 'Ürün Görselleri');
        self::assertSame('urun-gorselleri', $first);

        $reused = $ops->createFolder($profile, '', 'Ürün Görselleri', null, true);
        self::assertSame('urun-gorselleri', $reused);

        // Regular file collision must still fail even when ifNotExists=true
        $fs = $registry->getFilesystem($profile);
        $fs->write('blocking-item', 'data');
        $this->expectException(FileManagerException::class);
        $ops->createFolder($profile, '', 'blocking-item', null, true);
    }

    public function testDirectoryListerEnforcesPerItemViewVoterPermission(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'visible.txt', 'hello');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'payroll.xlsx', 'secret');
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'public-docs', 0775, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'hr-private', 0775, true);

        [$profile, $registry] = $this->buildSimpleOps([], ['voter_enabled' => true]);

        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static function (mixed $attr, mixed $subject): bool {
                if (is_object($subject) && property_exists($subject, 'path')) {
                    return !in_array($subject->path, ['payroll.xlsx', 'hr-private'], true);
                }

                return true;
            },
        );

        $lister = new DirectoryLister(
            $registry,
            new PathNormalizer(),
            new DirectoryCacheManager(),
            new NullMetadataStorage(),
            new FileSecurityGuard(),
            $auth,
        );

        $listing = $lister->list($profile);
        $names = array_map(static fn (object $item): string => $item->name, $listing->items);
        self::assertContains('visible.txt', $names);
        self::assertContains('public-docs', $names);
        self::assertNotContains('payroll.xlsx', $names);
        self::assertNotContains('hr-private', $names);
    }

    public function testDynamicPathResolverPreventsTenantCollisionsEmptyEscapeAndZeroCoercion(): void
    {
        $resolver = new DynamicPathResolver();

        // User ID '0' must NOT become 'guest'
        self::assertSame('/tenants/0', $resolver->resolve('/tenants/{user_id}', null, '0'));
        // Empty override must NOT escape into '/tenants/'
        self::assertSame('/tenants/guest', $resolver->resolve('/tenants/{user_id}', null, ''));

        // Distinct email/Unicode identifiers must never collide
        $u1 = $this->makeSecurityResolverForIdentifier('john.doe@corp.com')->resolve('/t/{user_id}');
        $u2 = $this->makeSecurityResolverForIdentifier('john_doe@corp.com')->resolve('/t/{user_id}');
        $u3 = $this->makeSecurityResolverForIdentifier('john+doe@corp.com')->resolve('/t/{user_id}');
        $u4 = $this->makeSecurityResolverForIdentifier('山田')->resolve('/t/{user_id}');
        $u5 = $this->makeSecurityResolverForIdentifier('김철수')->resolve('/t/{user_id}');
        $uEmpty = $this->makeSecurityResolverForIdentifier('')->resolve('/t/{user_id}');

        self::assertNotSame($u1, $u2);
        self::assertNotSame($u1, $u3);
        self::assertNotSame($u2, $u3);
        self::assertNotSame($u4, $u5);
        self::assertSame('/t/user', $uEmpty);

        // Clean numeric / alphanumeric IDs stay unchanged AND round-trip idempotently in ShareController override
        $clean = $this->makeSecurityResolverForIdentifier('tenant_42')->getCurrentUserId();
        self::assertSame('tenant_42', $clean);
        $hashedEmailId = $this->makeSecurityResolverForIdentifier('john.doe@corp.com')->getCurrentUserId();
        self::assertSame('/t/' . $hashedEmailId, $resolver->resolve('/t/{user_id}', null, $hashedEmailId));
    }

    public function testZipExtractionWithWebpConversionHonorsOverwritePolicyOnExistingWebp(): void
    {
        if (!class_exists(ZipArchive::class) || !function_exists('imagewebp')) {
            self::markTestSkipped('ext-zip and GD WebP support required.');
        }

        // Pre-existing banners/hero.webp on storage that must NOT be clobbered when hero.png is extracted with convert_to_webp=true
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'banners', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'banners' . DIRECTORY_SEPARATOR . 'hero.webp', 'protected-existing-webp');

        $pngTmp = $this->tempDir . DIRECTORY_SEPARATOR . '_src.png';
        $im = imagecreatetruecolor(16, 16);
        self::assertNotFalse($im);
        imagepng($im, $pngTmp);
        imagedestroy($im);

        $zipFile = $this->tempDir . DIRECTORY_SEPARATOR . 'pack.zip';
        $za = new ZipArchive();
        self::assertTrue($za->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $za->addFile($pngTmp, 'hero.png');
        $za->close();
        @unlink($pngTmp);

        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['main'],
                    'overwrite_policy' => 'rename',
                    'zip' => ['enabled' => true, 'allow_extract' => true],
                    'image_optimization' => ['enabled' => true, 'convert_to_webp' => true],
                ],
            ],
        );
        $profile = $configProvider->getProfile('default');
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $zipService = new ZipArchiveService($registry, new PathNormalizer());

        $zipService->extractArchive($profile, 'pack.zip', 'banners');

        // Original banners/hero.webp MUST remain untouched, and converted PNG must be renamed to banners/hero-1.webp
        self::assertSame('protected-existing-webp', file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . 'banners' . DIRECTORY_SEPARATOR . 'hero.webp'));
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'banners' . DIRECTORY_SEPARATOR . 'hero-1.webp');
    }

    public function testTwoStepRenameAndCloneBlockMimeExtensionMismatch(): void
    {
        [$profile, $registry, $ops] = $this->buildSimpleOps(['allow_extension_change' => true]);
        $fs = $registry->getFilesystem($profile);
        $fs->write('notes.txt', 'Plain text body pretending to be binary PDF');

        $this->expectException(UploadValidationException::class);
        $ops->rename($profile, 'notes.txt', 'spoofed.pdf');
    }

    public function testImageEditorRejectsWhenBackendCannotReencodeAndTruncatesLongNames(): void
    {
        [$profile, $registry, , $configProvider] = $this->buildSimpleOps();
        $fs = $registry->getFilesystem($profile);

        $longStem = str_repeat('a', 248);
        $pngPath = $this->tempDir . DIRECTORY_SEPARATOR . $longStem . '.png';
        $im = imagecreatetruecolor(20, 20);
        self::assertNotFalse($im);
        imagepng($im, $pngPath);
        imagedestroy($im);

        $uploadTmp = $this->tempDir . DIRECTORY_SEPARATOR . 'edit_upload.png';
        copy($pngPath, $uploadTmp);

        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 'secret');
        $controller = new ImageEditorController(
            $configProvider,
            $registry,
            new PathNormalizer(),
            $thumbMgr,
            new CsrfRequestValidator(null, false),
            new EventDispatcher(),
        );

        $uploadedFile = new UploadedFile($uploadTmp, 'edited.png', 'image/png', null, true);
        $req = Request::create('/_liveux-file-manager/image-edit', 'POST', [
            'profile' => 'default',
            'path' => $longStem . '.png',
            'saveMode' => 'copy',
        ], [], ['file' => $uploadedFile]);

        $resp = $controller($req);
        self::assertSame(Response::HTTP_OK, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['ok']);
        self::assertLessThanOrEqual(255, strlen((string) $data['path']));
        self::assertTrue($fs->fileExists((string) $data['path']));
    }

    public function testControllersRejectInputBagArrayInjectionCleanlyWithoutHttp500(): void
    {
        [$profile, $registry, $ops, $configProvider] = $this->buildSimpleOps();
        $normalizer = new PathNormalizer();
        $translator = new MessageTranslator();

        // MessageTranslator with array locale must never throw BadRequestException
        $reqArrayLocale = Request::create('/test?locale[]=tr&locale[]=en');
        self::assertSame('en', $translator->resolveLocale($reqArrayLocale, $profile));

        // ApiController::list with array path[] and array locale[] must return 400 JSON, never 500
        $lister = new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage());
        $trash = new TrashManager($registry, $normalizer, new EventDispatcher());
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), new EventDispatcher());
        $remoteImporter = new RemoteUrlImporter($uploadMgr);
        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 'sec');
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $api = new ApiController(
            $configProvider,
            $registry,
            $lister,
            $ops,
            $remoteImporter,
            $trash,
            new DirectoryCacheManager(),
            $thumbMgr,
            $normalizer,
            $urlGen,
            new Environment(new ArrayLoader()),
            new CsrfRequestValidator(null, false),
        );
        $listResp = $api->list(Request::create('/_liveux-file-manager/api/list?path[]=evil&locale[]=tr'));
        self::assertSame(Response::HTTP_BAD_REQUEST, $listResp->getStatusCode());

        // UploadController with array path[] must return 400/422 JSON, never 500
        $uploadCtrl = new UploadController($configProvider, $uploadMgr, new CsrfRequestValidator(null, false));
        $upResp = $uploadCtrl(Request::create('/_liveux-file-manager/upload', 'POST', ['path' => ['evil'], 'locale' => ['tr']]));
        self::assertContains($upResp->getStatusCode(), [Response::HTTP_BAD_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY]);

        // DownloadController & ThumbnailController with array path[] must throw BadRequestHttpException (400), not BadRequestException
        $dlCtrl = new DownloadController($configProvider, $registry, $normalizer);
        try {
            $dlCtrl(Request::create('/_liveux-file-manager/download?path[]=x'));
            self::fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            $this->addToAssertionCount(1);
        }

        $thumbCtrl = new ThumbnailController($configProvider, $registry, $normalizer, $thumbMgr);
        try {
            $thumbCtrl(Request::create('/_liveux-file-manager/thumb?path[]=x'));
            self::fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTrashRestoreBlocksUnsafeChildFilesAndTruncates255ByteCollisions(): void
    {
        [$profile, $registry] = $this->buildSimpleOps();
        $trash = new TrashManager($registry, new PathNormalizer(), new EventDispatcher());

        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'infected_folder', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'infected_folder' . DIRECTORY_SEPARATOR . 'ok.txt', 'safe');
        $entry = $trash->moveToTrash($profile, 'infected_folder');

        // Simulate a legacy/external executable file inside the trashed folder
        $trashedFolderDisk = $this->tempDir . DIRECTORY_SEPARATOR . '.trash' . DIRECTORY_SEPARATOR . $entry->id . DIRECTORY_SEPARATOR . 'infected_folder';
        file_put_contents($trashedFolderDisk . DIRECTORY_SEPARATOR . 'shell.phtml', '<?php echo 1;');

        try {
            $trash->restore($profile, $entry->id);
            self::fail('Expected UploadValidationException for .phtml inside restored folder.');
        } catch (UploadValidationException) {
            self::assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'infected_folder');
        }

        // Long filename collision during restore must stay <= 255 bytes
        $longName = str_repeat('b', 245) . '.txt';
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . $longName, 'v1');
        $longEntry = $trash->moveToTrash($profile, $longName);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . $longName, 'v2-blocking');

        $restored = $trash->restore($profile, $longEntry->id);
        self::assertLessThanOrEqual(255, strlen($restored->name));
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . $restored->originalPath);
    }

    public function testMetadataCopyPrefixPurgesStaleTargetChildrenAcrossJsonAndDoctrine(): void
    {
        // JSON storage
        $jsonFile = $this->tempDir . DIRECTORY_SEPARATOR . 'meta.json';
        $jsonStore = new JsonFileMetadataStorage($jsonFile);
        $jsonStore->save('local::src', new FolderMetadata(color: '#112233'));
        $jsonStore->save('local::src/a.jpg', new FolderMetadata(alt: 'Fresh A'));
        $jsonStore->save('local::dst/stale_orphan.jpg', new FolderMetadata(alt: 'Stale'));

        $jsonStore->copyPrefix('local::src', 'local::dst');
        self::assertSame('#112233', $jsonStore->get('local::dst')->color);
        self::assertSame('Fresh A', $jsonStore->get('local::dst/a.jpg')->alt);
        self::assertTrue($jsonStore->get('local::dst/stale_orphan.jpg')->isEmpty());

        // Fresh reader instance verifies shared-lock disk persistence
        $freshReader = new JsonFileMetadataStorage($jsonFile);
        self::assertSame('Fresh A', $freshReader->get('local::dst/a.jpg')->alt);

        // Doctrine DBAL SQLite storage
        if (extension_loaded('pdo_sqlite')) {
            $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $dbStore = new DoctrineDbalMetadataStorage($conn);
            $dbStore->save('local::src', new FolderMetadata(color: '#445566'));
            $dbStore->save('local::src/a.jpg', new FolderMetadata(alt: 'DB Fresh'));
            $dbStore->save('local::dst/stale.jpg', new FolderMetadata(alt: 'DB Stale'));

            $dbStore->copyPrefix('local::src', 'local::dst');
            self::assertSame('DB Fresh', $dbStore->get('local::dst/a.jpg')->alt);
            self::assertTrue($dbStore->get('local::dst/stale.jpg')->isEmpty());
        }
    }

    public function testSsrfGuardsBlockRfc8215LocalNat64AndObfuscatedPickerUrls(): void
    {
        // RFC 8215 local-use NAT64 (64:ff9b:1::/48) must ALWAYS be blocked even with 8.8.8.8 in trailing bytes
        self::assertFalse(RemoteUrlImporter::isPublicIpAddress('64:ff9b:1:fffe::8.8.8.8'));
        // Well-known NAT64 with private IPv4 must be blocked; public 8.8.8.8 allowed
        self::assertFalse(RemoteUrlImporter::isPublicIpAddress('64:ff9b::10.0.0.1'));
        self::assertTrue(RemoteUrlImporter::isPublicIpAddress('64:ff9b::8.8.8.8'));

        $formFactory = Forms::createFormFactoryBuilder()->getFormFactory();
        foreach ([
            'http://2130706433/secret.jpg',
            'http://0177.0.0.1/secret.jpg',
            'http://0x7f000001/secret.jpg',
            'http://127.1/secret.jpg',
            'http://100.64.0.1/cgnat.jpg',
            'http://[2002:7f00:1::]/6to4.jpg',
            'http://[64:ff9b:1::8.8.8.8]/nat64.jpg',
            'https://printer.home.arpa/scan.jpg',
        ] as $unsafeUrl) {
            $form = $formFactory->create(FileManagerPickerType::class);
            $form->submit($unsafeUrl);
            self::assertFalse($form->isValid(), sprintf('Expected FileManagerPickerType to reject SSRF URL "%s".', $unsafeUrl));
        }

        $validForm = $formFactory->create(FileManagerPickerType::class);
        $validForm->submit('https://cdn.example.com/assets/hero.webp');
        self::assertTrue($validForm->isValid());
    }

    public function testUploadSubfolderCreationChecksDeepestExistingAncestorVoter(): void
    {
        [$profile, $registry] = $this->buildSimpleOps([], ['voter_enabled' => true]);

        // Voter denies CREATE_FOLDER at root (''), even if it doesn't explicitly match 'rogue'
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static function (mixed $attr, mixed $subject): bool {
                if ($attr === FileManagerPermissions::CREATE_FOLDER && is_object($subject) && property_exists($subject, 'path') && $subject->path === '') {
                    return false;
                }

                return true;
            },
        );

        $uploadMgr = new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher(), new SvgSanitizer(), $auth);
        $tmpTxt = $this->tempDir . DIRECTORY_SEPARATOR . '_up.txt';
        file_put_contents($tmpTxt, 'hello');
        $file = new UploadedFile($tmpTxt, 'hello.txt', 'text/plain', null, true);

        $this->expectException(AccessDeniedException::class);
        $uploadMgr->handleUpload($profile, $file, 'rogue/deeply/nested');
    }

    public function testTwigDescribeFieldItemHandlesUtf8PercentEncodingRelativePathsAndSvg(): void
    {
        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['pub' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads']],
            profiles: ['default' => ['volumes' => ['pub']]],
        );
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        $urlGen = new class implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $route = $name === 'liveux_file_manager_thumb' ? '/_liveux-file-manager/thumb' : '/_liveux-file-manager/download';

                return $route . '?' . http_build_query($parameters);
            }
        };

        $ext = new FileManagerTwigExtension($urlGen, null, null, $configProvider, $registry);

        // UTF-8 percent-encoded Turkish name + preserved %2B ('+')
        $utf8Info = $ext->describeFieldItem('/_liveux-file-manager/download?profile=default&path=katalog%2F%C3%BCr%C3%BCn%2B1.jpg&inline=1');
        self::assertSame('ürün+1.jpg', $utf8Info['name']);
        self::assertNotNull($utf8Info['imgSrc']);

        // Private download SVG rewrites imgSrc to /thumb
        $svgInfo = $ext->describeFieldItem('/_liveux-file-manager/download?profile=default&path=icons%2Flogo.svg');
        self::assertSame('logo.svg', $svgInfo['name']);
        self::assertSame('/_liveux-file-manager/thumb?profile=default&path=icons%2Flogo.svg', $svgInfo['imgSrc']);

        // Relative path (return_type: 'path') resolves public URL preview
        $relInfo = $ext->describeFieldItem('products/ürün görseli.webp', 'default', null, true);
        self::assertSame('ürün görseli.webp', $relInfo['name']);
        self::assertSame('/uploads/products/%C3%BCr%C3%BCn%20g%C3%B6rseli.webp', $relInfo['imgSrc']);

        // Unsafe scheme is neutralized
        $xssInfo = $ext->describeFieldItem('javascript:alert(1)');
        self::assertNull($xssInfo['href']);
        self::assertNull($xssInfo['imgSrc']);
    }

    public function testInstallSkillsRejectsOutsideProjectDirAndDataCollectorRedactsCustomIconPath(): void
    {
        $bundleSkillsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'skills';
        $tester = new CommandTester(new InstallSkillsCommand($bundleSkillsDir, $this->tempDir));
        $outsideDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside_escape_' . bin2hex(random_bytes(3));
        self::assertSame(Command::FAILURE, $tester->execute(['--dir' => $outsideDir, '--force' => true]));

        // Regular file blocking destination skill path skips without crashing when --force is false
        $agentsSkills = $this->tempDir . DIRECTORY_SEPARATOR . '.agents' . DIRECTORY_SEPARATOR . 'skills';
        mkdir($agentsSkills, 0775, true);
        file_put_contents($agentsSkills . DIRECTORY_SEPARATOR . 'liveux-file-manager-setup', 'blocking-file');
        self::assertSame(Command::SUCCESS, $tester->execute(['--only' => 'setup']));

        // DataCollector redacts custom server icon directory path
        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => ['volumes' => ['main'], 'ui' => ['icon_set' => '/var/www/secret-server/custom-icons']]],
        );
        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 'sec');
        $zipSvc = new ZipArchiveService(new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver())), new PathNormalizer());
        $collector = new FileManagerDataCollector($configProvider, $thumbMgr, $zipSvc, new IconSetRegistry(dirname(__DIR__, 3) . '/resources/icons'));
        $collector->collect(Request::create('/'), new Response());

        $profiles = $collector->getProfiles();
        self::assertSame('custom:custom-icons', $profiles[0]['iconSet']);
    }

    public function testShareControllerEnforcesStrictInlineAllowlistAndDuplicateDetectorSkipsUnsafeFiles(): void
    {
        $volRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'vol_share';
        mkdir($volRoot, 0775, true);
        file_put_contents($volRoot . DIRECTORY_SEPARATOR . 'data.rtf', '{\\rtf1\\ansi Hello}');
        file_put_contents($volRoot . DIRECTORY_SEPARATOR . 'legacy.phtml', 'identical-payload');
        $uploadTmp = $this->tempDir . DIRECTORY_SEPARATOR . '_cand.txt';
        file_put_contents($uploadTmp, 'identical-payload');

        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $volRoot]],
            profiles: [
                'default' => [
                    'volumes' => ['main'],
                    'share' => ['enabled' => true, 'allow_inline_preview' => true],
                    'duplicate_detection' => ['enabled' => true, 'policy' => 'reuse'],
                ],
            ],
        );
        $profile = $configProvider->getProfile('default');
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        // DuplicateDetector must never return .phtml as a reusable duplicate
        $detector = new DuplicateDetector($registry, $normalizer, new DirectoryCacheManager());
        self::assertNull($detector->findDuplicate($profile, 'main', $uploadTmp));

        // ShareController must force attachment for non-allowlisted MIME (e.g. application/rtf) even when inline=1
        $signer = new UriSigner('share-secret-key');
        $urlGen = new class implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'https://example.test/_liveux-file-manager/share?' . http_build_query($parameters);
            }
        };
        $shareSvc = new ShareLinkService($registry, $normalizer, $urlGen, $signer);
        $signed = $shareSvc->createSignedUrl($profile, 'data.rtf', 'main', 300, true);
        $shareCtrl = new ShareController($configProvider, $registry, $normalizer, $shareSvc);
        $resp = $shareCtrl(Request::create($signed['url']));
        self::assertStringContainsString('attachment', (string) $resp->headers->get('Content-Disposition'));
    }

    /**
     * @param array<string, mixed> $naming
     * @param array<string, mixed> $security
     *
     * @return array{0: ProfileConfig, 1: VolumeRegistry, 2: FileOperationsService, 3: ConfigProviderInterface}
     */
    private function buildSimpleOps(array $naming = [], array $security = []): array
    {
        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['main'],
                    'naming' => $naming,
                    'security' => $security,
                ],
            ],
        );
        $profile = $configProvider->getProfile('default');
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $dispatcher = new EventDispatcher();
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService(
            $registry,
            $normalizer,
            new FilenameNormalizer(),
            $trash,
            new NullMetadataStorage(),
            $dispatcher,
        );

        return [$profile, $registry, $ops, $configProvider];
    }

    private function makeSecurityResolverForIdentifier(string $identifier): DynamicPathResolver
    {
        $user = new class($identifier) implements UserInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->id;
            }
        };

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new DynamicPathResolver($security);
    }
}
