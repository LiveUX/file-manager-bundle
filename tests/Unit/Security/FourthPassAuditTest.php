<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Regression suite for the fourth exhaustive cross-function security & logic audit pass.
 */
final class FourthPassAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_fourth_' . bin2hex(random_bytes(4));
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

    public function testBlocksActiveXmlAndJsUploadsAndPolyglotExtensionMimeMismatch(): void
    {
        $guard = new FileSecurityGuard();

        foreach (['payload.xml', 'feed.rdf', 'evil.js', 'module.mjs', 'common.cjs'] as $blockedName) {
            $blocked = false;
            try {
                $guard->assertSafeFilename($blockedName);
            } catch (UploadValidationException) {
                $blocked = true;
            }
            self::assertTrue($blocked, sprintf('Active web extension "%s" must be blocked.', $blockedName));
        }

        // GIF89a polyglot uploaded with unrelated .txt / .pdf extension must be rejected
        $polyglotRejected = false;
        try {
            $guard->assertMimeMatchesExtension('exploit.txt', 'image/gif');
        } catch (UploadValidationException $e) {
            $polyglotRejected = $e->getTranslationKey() === 'errors.extension_mime_mismatch';
        }
        self::assertTrue($polyglotRejected);

        // Plain text disguised as .png must be rejected
        $fakePngRejected = false;
        try {
            $guard->assertMimeMatchesExtension('avatar.png', 'text/plain');
        } catch (UploadValidationException $e) {
            $fakePngRejected = $e->getTranslationKey() === 'errors.extension_mime_mismatch';
        }
        self::assertTrue($fakePngRejected);

        // Active HTML disguised as .jpg must be rejected
        $htmlDisguisedRejected = false;
        try {
            $guard->assertMimeMatchesExtension('photo.jpg', 'text/html');
        } catch (UploadValidationException $e) {
            $htmlDisguisedRejected = $e->getTranslationKey() === 'errors.upload_mime_denied';
        }
        self::assertTrue($htmlDisguisedRejected);

        // Valid combinations pass cleanly
        $guard->assertMimeMatchesExtension('photo.jpg', 'image/jpeg');
        $guard->assertMimeMatchesExtension('notes.md', 'text/plain');
        $guard->assertMimeMatchesExtension('report.docx', 'application/zip');
        $this->addToAssertionCount(3);
    }

    public function testFileOperationsBlocksDangerousMoveProtectsTrashAncestorAndSupportsCaseOnlyRename(): void
    {
        $storageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'ops';
        mkdir($storageDir . DIRECTORY_SEPARATOR . 'private_src', 0775, true);
        mkdir($storageDir . DIRECTORY_SEPARATOR . 'public_dst', 0775, true);
        mkdir($storageDir . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'trash', 0775, true);
        file_put_contents($storageDir . DIRECTORY_SEPARATOR . 'private_src' . DIRECTORY_SEPARATOR . 'shell.php', '<?php echo 1;');
        file_put_contents($storageDir . DIRECTORY_SEPARATOR . 'report.txt', 'quarterly report');
        file_put_contents($storageDir . DIRECTORY_SEPARATOR . 'README', 'plain readme');

        $profile = $this->createProfile(
            basePath: $storageDir,
            ignoreDotFiles: false,
            trash: ['enabled' => true, 'directory' => 'system/trash'],
            naming: ['slugger' => false, 'apply_on_rename' => false, 'allow_extension_change' => false],
        );
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $storageDir);
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($profile);
        $provider->method('getVolume')->willReturn($vol);

        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $ops = new FileOperationsService(
            $registry,
            $normalizer,
            new FilenameNormalizer(),
            new TrashManager($registry, $normalizer, $dispatcher),
            new NullMetadataStorage(),
            $dispatcher,
        );

        // 1. Moving pre-existing .php file into another folder is blocked by assertSafeFilename
        $moveBlocked = false;
        try {
            $ops->moveOrCopy($profile, 'private_src/shell.php', 'public_dst', false, 'local', 'local');
        } catch (UploadValidationException) {
            $moveBlocked = true;
        }
        self::assertTrue($moveBlocked);

        // 2. Renaming or deleting ancestor 'system' of nested trash 'system/trash' is blocked
        $ancestorBlocked = false;
        try {
            $ops->rename($profile, 'system', 'exposed', 'local');
        } catch (InvalidPathException) {
            $ancestorBlocked = true;
        }
        self::assertTrue($ancestorBlocked);

        // 3. Adding an extension to extensionless README when allow_extension_change=false is blocked
        $extAddBlocked = false;
        try {
            $ops->rename($profile, 'README', 'README.svg', 'local');
        } catch (UploadValidationException) {
            $extAddBlocked = true;
        }
        self::assertTrue($extAddBlocked);

        // 4. Case-only rename report.txt -> REPORT.txt succeeds on case-insensitive & case-sensitive filesystems
        $renamed = $ops->rename($profile, 'report.txt', 'REPORT.txt', 'local');
        self::assertSame('REPORT.txt', $renamed);
        self::assertSame('quarterly report', file_get_contents($storageDir . DIRECTORY_SEPARATOR . 'REPORT.txt'));
    }

    public function testShareLinkBindsDynamicUserIdDateAndFileVersionPreventingConfusedDeputy(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'tenants';
        $today = date('Y/m/d');
        mkdir($root . '/alice/' . $today, 0775, true);
        mkdir($root . '/bob/' . $today, 0775, true);
        mkdir($root . '/guest/' . $today, 0775, true);
        file_put_contents($root . '/alice/' . $today . '/nda.txt', 'ALICE-SECRET-NDA');
        file_put_contents($root . '/bob/' . $today . '/nda.txt', 'BOB-PRIVATE-DOC');
        file_put_contents($root . '/guest/' . $today . '/nda.txt', 'GUEST-DOC');

        $rawVol = new VolumeConfig(
            name: 'tenant_vol',
            label: 'Tenant',
            group: 'local',
            driver: 'LocalFileSystem',
            path: $root . '/{user_id}/{Y}/{m}/{d}',
            private: true,
        );
        $profile = $this->createProfile(
            basePath: $root,
            volumes: ['tenant_vol'],
            security: ['voter_enabled' => false],
        );

        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($profile);
        $provider->method('getVolume')->willReturn($rawVol);
        $provider->method('hasProfile')->willReturn(true);

        $currentUser = new InMemoryUser('alice', 'x');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(static function () use (&$currentUser): ?InMemoryUser {
            return $currentUser;
        });

        $resolver = new DynamicPathResolver($security);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory($resolver), $resolver);
        $signer = new UriSigner('share-hmac-test-secret');
        $urlGen = new class () implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void {}

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'https://example.test/_liveux-file-manager/share?' . http_build_query($parameters);
            }
        };

        $shareService = new ShareLinkService($registry, new PathNormalizer(), $urlGen, $signer, null, new FileSecurityGuard(), $resolver);
        $shareController = new ShareController($provider, $registry, new PathNormalizer(), $shareService);

        // Alice creates a signed share link
        $signed = $shareService->createSignedUrl($profile, 'nda.txt', 'tenant_vol', 3600, false);
        self::assertStringContainsString('u=alice', $signed['url']);

        // Anonymous recipient (currentUser = null -> 'guest') opens Alice's link -> receives ALICE's file!
        $currentUser = null;
        $anonResp = $this->captureStreamedResponse($shareController(Request::create($signed['url'])));
        self::assertSame('ALICE-SECRET-NDA', $anonResp);

        // Logged-in Bob opens Alice's link -> still receives ALICE's file, NOT Bob's!
        $currentUser = new InMemoryUser('bob', 'x');
        $bobResp = $this->captureStreamedResponse($shareController(Request::create($signed['url'])));
        self::assertSame('ALICE-SECRET-NDA', $bobResp);

        // Replacing Alice's file invalidates the `v` fingerprint -> 410 Gone
        file_put_contents($root . '/alice/' . $today . '/nda.txt', 'COMPLETELY-NEW-CONFIDENTIAL-FILE-PAYLOAD');
        $this->expectException(GoneHttpException::class);
        $shareController(Request::create($signed['url']));
    }

    public function testTrashLifecycleIsolatesMetadataFromLivePathReuseAndPurge(): void
    {
        $uploadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'trash_meta';
        mkdir($uploadDir . '/2026', 0775, true);
        file_put_contents($uploadDir . '/2026/hero.jpg', 'old-image');

        $metaFile = $this->tempDir . DIRECTORY_SEPARATOR . 'metadata.json';
        $metaStorage = new JsonFileMetadataStorage($metaFile);
        $profile = $this->createProfile(basePath: $uploadDir);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $uploadDir);
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($profile);
        $provider->method('getVolume')->willReturn($vol);

        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MetadataSyncListener($metaStorage));
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, $metaStorage, $dispatcher);

        $folderCanonical = $normalizer->buildCanonicalKey($vol, '2026', $profile->basePath);
        $fileCanonical = $normalizer->buildCanonicalKey($vol, '2026/hero.jpg', $profile->basePath);
        $metaStorage->save($folderCanonical, new FolderMetadata(color: '#ef4444', pinned: true));
        $metaStorage->save($fileCanonical, new FolderMetadata(alt: 'Old Trashed Alt'));

        // Soft-delete 2026/ to Trash
        self::assertTrue($ops->delete($profile, '2026', 'local'));

        // Live path metadata is immediately clean (no ghost inheritance when recreating 2026/)
        self::assertTrue($metaStorage->get($folderCanonical)->isEmpty());
        self::assertTrue($metaStorage->get($fileCanonical)->isEmpty());

        // Create new live 2026/ + hero.jpg with fresh production metadata
        mkdir($uploadDir . '/2026', 0775, true);
        file_put_contents($uploadDir . '/2026/hero.jpg', 'new-live-image');
        $metaStorage->save($folderCanonical, new FolderMetadata(color: '#10b981', pinned: false));
        $metaStorage->save($fileCanonical, new FolderMetadata(alt: 'Fresh Production Alt'));

        // Purging the old trashed 2026/ MUST NOT wipe live production metadata!
        $entries = $trash->listTrash($profile, 'local');
        self::assertCount(1, $entries);
        $trash->deletePermanently($profile, $entries[0]->id, 'local');

        self::assertSame('#10b981', $metaStorage->get($folderCanonical)->color);
        self::assertSame('Fresh Production Alt', $metaStorage->get($fileCanonical)->alt);
    }

    public function testSvgSanitizerBlocksCssEscapedPresentationUrlsAndUtf16Doctype(): void
    {
        $sanitizer = new SvgSanitizer();

        $escapedSvg = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<rect fill="u\72l(https://evil.example/ssrf)" cursor="u/**/rl(https://evil.example/track),auto" width="10" height="10"/>'
            . '</svg>';
        $clean = $sanitizer->sanitize($escapedSvg);
        self::assertStringNotContainsString('evil.example', $clean);
        self::assertStringNotContainsString('fill=', $clean);
        self::assertStringNotContainsString('cursor=', $clean);

        $utf16Svg = "\xFF\xFE" . mb_convert_encoding(
            '<!DOCTYPE svg [<!ENTITY xxe "boom">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
            'UTF-16LE',
            'UTF-8',
        );
        self::assertSame('', $sanitizer->sanitize($utf16Svg));
    }

    public function testPickerTypeBlocksEncodedTraversalPathModeHttpAndMalformedMultipleJson(): void
    {
        $factory = Forms::createFormFactoryBuilder()->getFormFactory();

        foreach ([
            '/uploads/%2e%2e/%2e%2e/etc/passwd',
            '/_liveux-file-manager/download?path=%2e%2e%2fsecret.pdf',
            'http://127.0.0.1/a.jpg',
            'http://169.254.169.254/latest/meta-data/',
        ] as $unsafeUrl) {
            $form = $factory->create(FileManagerPickerType::class);
            $form->submit($unsafeUrl);
            self::assertFalse($form->isValid(), sprintf('Unsafe picker value "%s" must fail validation.', $unsafeUrl));
        }

        // return_type='path' must reject external http(s) URLs
        $pathForm = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'path']);
        $pathForm->submit('https://cdn.example.com/photo.jpg');
        self::assertFalse($pathForm->isValid());

        // multiple=true with malformed JSON must fail validation instead of silently wiping gallery to []
        $galleryForm = $factory->create(FileManagerPickerType::class, ['/uploads/keep.jpg'], ['multiple' => true]);
        $galleryForm->submit('{malformed-json');
        self::assertFalse($galleryForm->isValid());
    }

    public function testThumbnailControllerProtectsOriginalsSanitizesSvgAndIsolatesTenantBasePaths(): void
    {
        $imgDir = $this->tempDir . DIRECTORY_SEPARATOR . 'thumbs_test';
        mkdir($imgDir, 0775, true);
        file_put_contents($imgDir . '/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>x()</script><circle r="4"/></svg>');
        file_put_contents($imgDir . '/raw.ico', "\x00\x00\x01\x00\x01\x00\x10\x10\x00\x00\x01\x00\x20\x00\x68\x04\x00\x00\x16\x00\x00\x00");

        $profileNoDownload = $this->createProfile(
            basePath: $imgDir,
            thumbnail: ['enabled' => false, 'cache_dir' => $this->tempDir],
            security: ['voter_enabled' => true],
        );
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $imgDir, private: true);
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($profileNoDownload);
        $provider->method('getVolume')->willReturn($vol);

        $viewOnlyAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $viewOnlyAuth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr): bool => $attr === FileManagerPermissions::VIEW,
        );

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $controller = new ThumbnailController($provider, $registry, new PathNormalizer(), $thumbManager, $viewOnlyAuth);

        // 1. SVG is sanitized via SvgSanitizer and served inline with private no-cache headers
        $svgResp = $controller(Request::create('/_liveux-file-manager/thumb?profile=test&volume=local&path=evil.svg'));
        self::assertSame(Response::HTTP_OK, $svgResp->getStatusCode());
        self::assertStringNotContainsString('<script>', (string) $svgResp->getContent());
        self::assertStringNotContainsString('onload', (string) $svgResp->getContent());
        self::assertStringContainsString('no-cache', (string) $svgResp->headers->get('Cache-Control'));

        // 2. Unscaled original fallback (.ico with thumbnail.enabled=false) is blocked when DOWNLOAD is denied
        $blockedOrig = false;
        try {
            $controller(Request::create('/_liveux-file-manager/thumb?profile=test&volume=local&path=raw.ico'));
        } catch (NotFoundHttpException) {
            $blockedOrig = true;
        }
        self::assertTrue($blockedOrig);

        // 3. Separate tenant basePath roots produce distinct cached thumbnail paths even for identical `local::id.png`
        $profA = $this->createProfile(basePath: '/srv/tenant_a', thumbnail: ['cache_dir' => $this->tempDir]);
        $profB = $this->createProfile(basePath: '/srv/tenant_b', thumbnail: ['cache_dir' => $this->tempDir]);
        self::assertNotSame(
            $thumbManager->getCachedPath($profA, 'local::id.png'),
            $thumbManager->getCachedPath($profB, 'local::id.png'),
        );
    }

    public function testApiControllerRejectsNonScalarActionFieldsWith4xxAndReturnsCreatedFolderPath(): void
    {
        $dir = $this->tempDir . DIRECTORY_SEPARATOR . 'api_test';
        mkdir($dir, 0775, true);
        $profile = $this->createProfile(basePath: $dir, security: ['csrf_protection' => false, 'voter_enabled' => false]);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $dir);
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($profile);
        $provider->method('getVolume')->willReturn($vol);

        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $upload = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher);
        $api = new ApiController(
            configProvider: $provider,
            volumeRegistry: $registry,
            directoryLister: new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage()),
            operations: new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, new NullMetadataStorage(), $dispatcher),
            remoteUrlImporter: new RemoteUrlImporter($upload, $registry, $normalizer),
            trashManager: $trash,
            cacheManager: new DirectoryCacheManager(),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            pathNormalizer: $normalizer,
            urlGenerator: $this->createStub(UrlGeneratorInterface::class),
            twig: new Environment(new ArrayLoader([])),
            csrfValidator: new CsrfRequestValidator(null),
        );

        $badAction = $api->action(Request::create('/api/action', 'POST', [], [], [], [], (string) json_encode(['action' => ['bad']])));
        self::assertSame(Response::HTTP_BAD_REQUEST, $badAction->getStatusCode());

        $badParam = $api->action(Request::create('/api/action', 'POST', [], [], [], [], (string) json_encode([
            'action' => 'rename',
            'path' => ['nested'],
            'newName' => 'a.txt',
        ])));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $badParam->getStatusCode());

        $createdResp = $api->action(Request::create('/api/action', 'POST', [], [], [], [], (string) json_encode([
            'action' => 'createFolder',
            'parentPath' => '',
            'name' => 'Summer Photos',
        ])));
        self::assertSame(Response::HTTP_OK, $createdResp->getStatusCode());
        $decoded = json_decode((string) $createdResp->getContent(), true);
        self::assertIsArray($decoded);
        self::assertSame('summer-photos', $decoded['path'] ?? null);
    }

    public function testDoctrineDbalRelocateCleansStaleTargetsAndConfigSupportsEnforceServerSide(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'usage_guard' => [
                        'enabled' => true,
                        'policy' => 'block',
                        'enforce_server_side' => true,
                    ],
                ],
            ],
        ]]);
        self::assertTrue($processed['profiles']['default']['usage_guard']['enforce_server_side']);

        if (!extension_loaded('pdo_sqlite')) {
            return;
        }

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DoctrineDbalMetadataStorage($conn);

        $storage->save('local::old_dir/a.jpg', new FolderMetadata(alt: 'Moved A'));
        $storage->save('local::new_dir/stale_orphan.jpg', new FolderMetadata(alt: 'Stale Orphan'));

        $storage->relocatePrefix('local::old_dir', 'local::new_dir');

        self::assertSame('Moved A', $storage->get('local::new_dir/a.jpg')->alt);
        self::assertTrue($storage->get('local::new_dir/stale_orphan.jpg')->isEmpty());

        $longKey = 'local::' . str_repeat('segment/', 160) . 'photo.jpg';
        $storage->save($longKey, new FolderMetadata(title: 'Long Path Supported'));
        $storage->reset();
        self::assertSame('Long Path Supported', $storage->get($longKey)->title);
    }

    private function captureStreamedResponse(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @param list<string>         $volumes
     * @param array<string, mixed> $thumbnail
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $naming
     * @param array<string, mixed> $security
     */
    private function createProfile(
        string $basePath = '',
        array $volumes = ['local'],
        bool $ignoreDotFiles = true,
        array $thumbnail = [],
        array $trash = ['enabled' => true, 'directory' => '.trash'],
        array $naming = [],
        array $security = [],
    ): ProfileConfig {
        $resolvedBase = $basePath !== '' ? $basePath : $this->tempDir;

        return new ProfileConfig(
            name: 'test',
            basePath: $resolvedBase,
            theme: 'bootstrap5',
            locale: 'en',
            volumes: $volumes,
            driver: 'LocalFileSystem',
            path: $resolvedBase,
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: $ignoreDotFiles,
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
            naming: $naming,
            imageOptimization: [],
            thumbnail: $thumbnail,
            trash: $trash,
            zip: [],
            ui: [],
            permissions: [],
            security: $security,
            cache: [],
            metadata: [],
        );
    }
}
