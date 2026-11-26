<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
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
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ExtremeSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_sec_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testPathNormalizerBlocksExtremeTraversalAndOsTricks(): void
    {
        $normalizer = new PathNormalizer();

        $maliciousPaths = [
            '../etc/passwd',
            'foo/..\\..\\bar',
            "folder/file\0.jpg",
            "folder/file\r\n.jpg",
            "folder/file\x07.jpg",
            'C:\Windows\win.ini',
            'c:/boot.ini',
            'phar://evil.phar/payload',
            'php://filter/convert.base64-encode/resource=index.php',
            'CON',
            'folder/NUL.jpg',
            'folder/LPT9.png',
            'folder/COM1',
            'folder/shell.php.',
            'folder/sub./file.jpg',
            implode('/', array_fill(0, 35, 'deep')),
            str_repeat('a', 1025),
        ];

        foreach ($maliciousPaths as $path) {
            try {
                $normalizer->normalize($path);
                self::fail(sprintf('PathNormalizer failed to reject malicious path: %s', $path));
            } catch (InvalidPathException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFileSecurityGuardBlocksDangerousFilenamesAndDoubleExtensions(): void
    {
        $guard = new FileSecurityGuard();

        $maliciousFilenames = [
            '.htaccess',
            '.HTACCESS',
            '.htpasswd',
            '.user.ini',
            '.env',
            '.meta.json',
            'web.config',
            'shell.php',
            'shell.PHP',
            'shell.phtml',
            'shell.phar',
            'shell.php8',
            'shell.php.jpg',
            'exploit.phtml.png',
            'evil.aspx.gif',
            'page.html',
            'page.htm',
            'archive.svgz',
            'CON.png',
            'PRN.pdf',
            'AUX.jpg',
            'NUL.webp',
            'COM1.txt',
            'photo.jpg.',
            'photo.jpg ',
            '..',
            '.',
            '',
        ];

        foreach ($maliciousFilenames as $filename) {
            try {
                $guard->assertSafeFilename($filename);
                self::fail(sprintf('FileSecurityGuard failed to reject dangerous filename: %s', $filename));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Valid filenames must pass
        $guard->assertSafeFilename('product-banner_2026.webp');
        $guard->assertSafeFilename('invoice.v2.pdf');
        $this->addToAssertionCount(2);
    }

    public function testFileSecurityGuardBlocksDirectAccessToTrashDotfilesAndExcludedPaths(): void
    {
        $guard = new FileSecurityGuard();
        $profile = $this->createProfile();

        $blockedPaths = [
            '.trash',
            '.trash/20260101_120000_abcd/secret.pdf',
            'sub/.trash/file.jpg',
            '.env',
            '.git/config',
            'folder/.htaccess',
            '_thumbs/abc.webp',
            'products/_thumbs/xyz.jpg',
        ];

        foreach ($blockedPaths as $path) {
            try {
                $guard->assertAccessiblePath($path, $profile);
                self::fail(sprintf('FileSecurityGuard failed to block protected path: %s', $path));
            } catch (InvalidPathException) {
                $this->addToAssertionCount(1);
            }
        }

        $guard->assertAccessiblePath('products/2026/photo.jpg', $profile);
        $this->addToAssertionCount(1);
    }

    public function testFilenameNormalizerBlocksDangerousRenameEvenWithAllowExtensionChange(): void
    {
        $normalizer = new FilenameNormalizer();
        $permissiveProfile = $this->createProfile(naming: [
            'slugger' => false,
            'allow_extension_change' => true,
            'apply_on_rename' => false,
        ]);

        foreach (['evil.php', 'evil.php.jpg', '.htaccess', 'CON.jpg', 'shell.php.', 'xss.html'] as $badTarget) {
            try {
                $normalizer->normalizeForRename('photo.jpg', $badTarget, $permissiveProfile);
                self::fail(sprintf('FilenameNormalizer allowed dangerous rename target: %s', $badTarget));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSvgSanitizerBlocksXxeBillionLaughsSmilCssAndProcessingInstructions(): void
    {
        $sanitizer = new SvgSanitizer();

        // 1. Billion Laughs / DOCTYPE / ENTITY must be rejected completely
        $billionLaughs = '<!DOCTYPE svg [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;">]><svg xmlns="http://www.w3.org/2000/svg">&lol2;</svg>';
        self::assertSame('', $sanitizer->sanitize($billionLaughs));

        $xxe = '<!DOCTYPE svg SYSTEM "http://127.0.0.1/xxe.dtd"><svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        self::assertSame('', $sanitizer->sanitize($xxe));

        // 2. SMIL animation, ProcessingInstruction, external CSS @import, nested SVG data URI, xml:base, external xlink:href
        $attackSvg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xml:base="https://evil.com/">'
            . '<?xml-stylesheet type="text/xsl" href="https://evil.com/evil.xsl"?>'
            . '<style>@import url(https://evil.com/evil.css);</style>'
            . '<style>.safe { fill: #2563eb; }</style>'
            . '<animate attributeName="href" values="javascript:alert(1)"/>'
            . '<set attributeName="onbegin" to="alert(1)"/>'
            . '<handler>alert(1)</handler>'
            . '<feImage xlink:href="https://evil.com/ssrf.png"/>'
            . '<image href="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSkvPg=="/>'
            . '<use xlink:href="https://evil.com/x.svg#id"/>'
            . '<rect class="safe" width="24" height="24"/>'
            . '</svg>';

        $clean = $sanitizer->sanitize($attackSvg);

        self::assertStringContainsString('<rect', $clean);
        self::assertStringContainsString('.safe', $clean);
        self::assertStringNotContainsString('xml-stylesheet', $clean);
        self::assertStringNotContainsString('@import', $clean);
        self::assertStringNotContainsString('<animate', $clean);
        self::assertStringNotContainsString('<set', $clean);
        self::assertStringNotContainsString('<handler', $clean);
        self::assertStringNotContainsString('feImage', $clean);
        self::assertStringNotContainsString('data:image/svg+xml', $clean);
        self::assertStringNotContainsString('xml:base', $clean);
        self::assertStringNotContainsString('evil.com', $clean);
    }

    public function testRemoteUrlImporterBlocksAdvancedSsrfBypasses(): void
    {
        $profile = $this->createProfile();
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getVolume')->willReturn($volume);
        $configProvider->method('getProfile')->willReturn($profile);

        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $uploadManager = new UploadManager($volumeRegistry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher());
        $importer = new RemoteUrlImporter($uploadManager);

        $ssrfUrls = [
            'http://[::ffff:127.0.0.1]/secret.jpg',
            'http://[::ffff:7f00:1]/secret.jpg',
            'http://[::ffff:169.254.169.254]/latest/meta-data/',
            'http://[64:ff9b::127.0.0.1]/secret.jpg',
            'http://0.0.0.0/secret.jpg',
            'http://[::]/secret.jpg',
            'http://[::1]/secret.jpg',
            'http://100.64.0.1/internal.jpg',
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost./secret.jpg',
            'http://app.localhost/secret.jpg',
            'http://user:pass@1.1.1.1/a.jpg',
            'http://1.1.1.1:6379/a.jpg',
            'http://1.1.1.1:3306/a.jpg',
            'http://2130706433/a.jpg',
            'http://0x7f000001/a.jpg',
            'file:///etc/passwd',
            'gopher://127.0.0.1:6379/_INFO',
        ];

        foreach ($ssrfUrls as $url) {
            try {
                $importer->assertSafeUrl($url);
                self::fail(sprintf('RemoteUrlImporter failed to block SSRF URL: %s', $url));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIconSetRegistryBlocksArbitraryDirectoryInclusionAndSanitizesCustomSets(): void
    {
        $evilDir = $this->tempDir . DIRECTORY_SEPARATOR . 'evil_icons';
        mkdir($evilDir, 0775, true);
        file_put_contents($evilDir . DIRECTORY_SEPARATOR . 'meta.json', '{"defaultFolderColor":"red; background:url(https://evil.com)"}');
        file_put_contents($evilDir . DIRECTORY_SEPARATOR . 'folder.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="16" height="16"/></svg>');

        $registry = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');

        // Default ($allowCustomDirectory = false): must NOT read arbitrary local directory
        $runtimePayload = $registry->getIconSetPayload($evilDir);
        self::assertSame('classic', $runtimePayload['name']);
        self::assertSame('#2563eb', $runtimePayload['defaultFolderColor']);
        self::assertStringNotContainsString('alert(1)', $runtimePayload['icons']['folder']);

        // Explicitly allowed custom directory (YAML config): must sanitize SVG and reject invalid color
        $configPayload = $registry->getIconSetPayload($evilDir, true);
        self::assertSame('#2563eb', $configPayload['defaultFolderColor']);
        self::assertStringContainsString('<rect', $configPayload['icons']['folder']);
        self::assertStringNotContainsString('<script', $configPayload['icons']['folder']);
        self::assertStringNotContainsString('onload', $configPayload['icons']['folder']);
    }

    public function testImageEditorControllerBlocksPhpOverwriteAttackAndNonExistentOverwrite(): void
    {
        $profile = $this->createProfile(security: ['csrf_protection' => false]);
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $controller = new ImageEditorController(
            $configProvider,
            $volumeRegistry,
            new PathNormalizer(),
            $thumbManager,
            new CsrfRequestValidator(null),
            new EventDispatcher(),
        );

        // Create a real valid 1x1 PNG file
        $pngPath = $this->tempDir . DIRECTORY_SEPARATOR . 'tmp_upload.png';
        $pngBinary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        self::assertIsString($pngBinary);
        file_put_contents($pngPath, $pngBinary);

        $uploadedFile = new UploadedFile($pngPath, 'edited.png', 'image/png', null, true);

        // Attempt 1: Overwrite shell.php
        $reqPhp = Request::create('/_liveux-file-manager/image-edit', 'POST', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'shell.php',
            'saveMode' => 'overwrite',
        ], [], ['file' => $uploadedFile]);

        $resPhp = $controller($reqPhp);
        self::assertSame(422, $resPhp->getStatusCode());
        self::assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'shell.php');

        // Attempt 2: Overwrite non-existent image
        $reqMissing = Request::create('/_liveux-file-manager/image-edit', 'POST', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'does-not-exist.png',
            'saveMode' => 'overwrite',
        ], [], ['file' => $uploadedFile]);

        $resMissing = $controller($reqMissing);
        self::assertSame(422, $resMissing->getStatusCode());
        self::assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'does-not-exist.png');
    }

    public function testGdThumbnailDriverRejectsForgedNonImagePayloads(): void
    {
        $driver = new GdThumbnailDriver();
        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'thumb.webp';

        self::assertFalse($driver->generate('<?php system("id"); ?>', $out, 200, 200, 'webp', 80, 'contain'));
        self::assertFalse($driver->generate('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', $out, 200, 200, 'webp', 80, 'contain'));
        self::assertFalse($driver->generate('<html><body>bad</body></html>', $out, 200, 200, 'webp', 80, 'contain'));
        self::assertFileDoesNotExist($out);
    }

    public function testDirectoryCacheManagerNeverWipesUnrelatedHostAppCacheKeys(): void
    {
        $pool = new ArrayAdapter();
        $hostItem = $pool->getItem('host_app_critical_data');
        $hostItem->set('must-survive');
        $pool->save($hostItem);

        $cacheManager = new DirectoryCacheManager($pool, null);
        $profile = $this->createProfile();

        $first = $cacheManager->remember($profile, 'local::uploads', static fn (): array => ['a.jpg']);
        self::assertFalse($first['hit']);

        $second = $cacheManager->remember($profile, 'local::uploads', static fn (): array => ['b.jpg']);
        self::assertTrue($second['hit']);
        self::assertSame(['a.jpg'], $second['data']);

        $cacheManager->invalidateProfile($profile->name);
        $afterProfileClear = $cacheManager->remember($profile, 'local::uploads', static fn (): array => ['c.jpg']);
        self::assertFalse($afterProfileClear['hit']);
        self::assertSame(['c.jpg'], $afterProfileClear['data']);

        $cacheManager->invalidateAll();
        $afterAllClear = $cacheManager->remember($profile, 'local::uploads', static fn (): array => ['d.jpg']);
        self::assertFalse($afterAllClear['hit']);

        // Critical assertion: host app cache entry MUST remain untouched!
        $hostCheck = $pool->getItem('host_app_critical_data');
        self::assertTrue($hostCheck->isHit());
        self::assertSame('must-survive', $hostCheck->get());
    }

    public function testTrashManagerBlocksInvalidTrashIdsAndProtectedPaths(): void
    {
        $profile = $this->createProfile();
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getVolume')->willReturn($volume);
        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $trash = new TrashManager($volumeRegistry, new PathNormalizer(), new EventDispatcher());

        foreach (['', 'a', '../etc', 'foo/bar', '2026;rm -rf'] as $badId) {
            try {
                $trash->deletePermanently($profile, $badId, 'local');
                self::fail(sprintf('TrashManager accepted invalid trashId: %s', $badId));
            } catch (FileManagerException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $trash->moveToTrash($profile, '.trash', 'local');
            self::fail('TrashManager allowed moving .trash into .trash.');
        } catch (InvalidPathException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testFolderMetadataRejectsCssInjectionOnHydration(): void
    {
        $meta = FolderMetadata::fromArray([
            'color' => 'red; } body { display: none',
            'icon' => '<script>alert(1)</script>',
            'pinned' => true,
        ]);

        self::assertNull($meta->color);
        self::assertNull($meta->icon);
        self::assertTrue($meta->pinned);

        $valid = FolderMetadata::fromArray(['color' => '#2563eb', 'icon' => 'folder-star']);
        self::assertSame('#2563eb', $valid->color);
        self::assertSame('folder-star', $valid->icon);
    }

    public function testApiControllerCapsBulkDeleteAt200Items(): void
    {
        $profile = $this->createProfile(security: ['csrf_protection' => false]);
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $pathNormalizer = new PathNormalizer();
        $cacheManager = new DirectoryCacheManager();
        $metadata = new NullMetadataStorage();
        $events = new EventDispatcher();
        $trash = new TrashManager($volumeRegistry, $pathNormalizer, $events);
        $ops = new FileOperationsService($volumeRegistry, $pathNormalizer, new FilenameNormalizer(), $trash, $metadata, $events);
        $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, $cacheManager, $metadata);
        $upload = new UploadManager($volumeRegistry, $pathNormalizer, new FilenameNormalizer(), $events);
        $importer = new RemoteUrlImporter($upload);
        $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $twig = new Environment(new ArrayLoader([]));

        $api = new ApiController(
            $configProvider,
            $volumeRegistry,
            $lister,
            $ops,
            $importer,
            $trash,
            $cacheManager,
            $thumbs,
            $pathNormalizer,
            $urlGen,
            $twig,
            new CsrfRequestValidator(null),
        );

        $paths = [];
        for ($i = 0; $i < 201; ++$i) {
            $paths[] = sprintf('file-%d.jpg', $i);
        }

        $request = Request::create('/_liveux-file-manager/api/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'delete',
            'profile' => 'default',
            'volume' => 'local',
            'paths' => $paths,
        ], JSON_THROW_ON_ERROR));

        $response = $api->action($request);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Maximum 200 items', (string) $response->getContent());
    }

    public function testApiControllerDialogBlocksArbitraryIconSetDirectoryPath(): void
    {
        $profile = $this->createProfile(security: ['csrf_protection' => false]);
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $pathNormalizer = new PathNormalizer();
        $cacheManager = new DirectoryCacheManager();
        $metadata = new NullMetadataStorage();
        $events = new EventDispatcher();
        $trash = new TrashManager($volumeRegistry, $pathNormalizer, $events);
        $ops = new FileOperationsService($volumeRegistry, $pathNormalizer, new FilenameNormalizer(), $trash, $metadata, $events);
        $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, $cacheManager, $metadata);
        $upload = new UploadManager($volumeRegistry, $pathNormalizer, new FilenameNormalizer(), $events);
        $importer = new RemoteUrlImporter($upload);
        $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $urlGen = $this->createStub(UrlGeneratorInterface::class);

        $loader = new ArrayLoader(['@LiveUXFileManager/components/DialogFrame.html.twig' => '{{ iconSet|default("NULL") }}|{{ mode }}|{{ locale|default("NULL") }}']);
        $twig = new Environment($loader);
        $iconRegistry = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');

        $api = new ApiController(
            $configProvider,
            $volumeRegistry,
            $lister,
            $ops,
            $importer,
            $trash,
            $cacheManager,
            $thumbs,
            $pathNormalizer,
            $urlGen,
            $twig,
            new CsrfRequestValidator(null),
            null,
            null,
            new FileSecurityGuard(),
            $iconRegistry,
        );

        // Attempt to inject arbitrary local directory path via ?iconSet=... and malicious mode/locale
        $req = Request::create('/_liveux-file-manager/dialog', 'GET', [
            'profile' => 'default',
            'iconSet' => $this->tempDir,
            'mode' => 'evil"><script>',
            'locale' => '../../etc',
            'path' => 'products',
        ]);

        $res = $api->dialog($req);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('NULL|modal|NULL', $res->getContent());

        // Attempt to access .trash via dialog?path=.trash must return 400
        $reqTrash = Request::create('/_liveux-file-manager/dialog', 'GET', [
            'profile' => 'default',
            'path' => '.trash',
        ]);
        self::assertSame(400, $api->dialog($reqTrash)->getStatusCode());
    }

    public function testApiControllerPreviewTextBlocksDangerousExtensionsAndBinaryAndTruncatesSafeText(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'secret.php', '<?php echo "SECRET";');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . '.env', 'APP_SECRET=123');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'binary.txt', "hello\0world");
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'large.json', str_repeat('a', 70000));

        $profile = $this->createProfile(security: ['csrf_protection' => false]);
        $api = $this->createApiController($profile);

        // .php, .env, binary .txt, and traversal must all be rejected (400)
        foreach (['secret.php', '.env', 'binary.txt', '../secret.txt'] as $blocked) {
            $res = $api->previewText(Request::create('/_liveux-file-manager/api/preview-text', 'GET', [
                'profile' => 'default',
                'volume' => 'local',
                'path' => $blocked,
            ]));
            self::assertSame(400, $res->getStatusCode(), sprintf('previewText should reject "%s".', $blocked));
        }

        // Valid large .json must return 200 and truncate cleanly to 64 KB
        $okRes = $api->previewText(Request::create('/_liveux-file-manager/api/preview-text', 'GET', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'large.json',
        ]));
        self::assertSame(200, $okRes->getStatusCode());
        $payload = json_decode((string) $okRes->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertTrue($payload['truncated']);
        self::assertSame(65536, strlen((string) $payload['content']));
    }

    public function testApiControllerCapsBulkMoveAndEnforcesGranularTrashPermissions(): void
    {
        $profile = $this->createProfile(security: ['csrf_protection' => false, 'voter_enabled' => true]);

        // Voter grants DELETE & VIEW, but explicitly DENIES TRASH_RESTORE and TRASH_PURGE
        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => !in_array($attribute, [
                FileManagerPermissions::TRASH_RESTORE,
                FileManagerPermissions::TRASH_PURGE,
            ], true),
        );

        $api = $this->createApiController($profile, $authChecker);

        // 201 paths in bulkMove must be blocked with 422
        $paths = [];
        for ($i = 0; $i < 201; ++$i) {
            $paths[] = sprintf('item-%d.jpg', $i);
        }
        $bulkRes = $api->action(Request::create('/_liveux-file-manager/api/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'bulkMove',
            'profile' => 'default',
            'volume' => 'local',
            'paths' => $paths,
            'targetDir' => 'dest',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(422, $bulkRes->getStatusCode());

        // Granular TRASH_RESTORE & TRASH_PURGE must return 403 even though DELETE is granted
        foreach (['restoreTrash', 'purgeTrash', 'emptyTrash'] as $trashAction) {
            $res = $api->action(Request::create('/_liveux-file-manager/api/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => $trashAction,
                'profile' => 'default',
                'volume' => 'local',
                'trashId' => '20260101_000000_abcd1234',
            ], JSON_THROW_ON_ERROR)));
            self::assertSame(403, $res->getStatusCode(), sprintf('Action "%s" must enforce granular trash voter permission.', $trashAction));
        }
    }

    public function testRecursiveSearchRespectsDotFilesTrashAndExcludePatterns(): void
    {
        mkdir($this->tempDir . '/visible/nested', 0775, true);
        mkdir($this->tempDir . '/.trash', 0775, true);
        mkdir($this->tempDir . '/.hidden', 0775, true);
        mkdir($this->tempDir . '/_thumbs', 0775, true);

        file_put_contents($this->tempDir . '/visible/nested/report-2026.txt', 'ok');
        file_put_contents($this->tempDir . '/.trash/report-2026.txt', 'trashed');
        file_put_contents($this->tempDir . '/.hidden/report-2026.txt', 'secret');
        file_put_contents($this->tempDir . '/_thumbs/report-2026.txt', 'thumb');

        $profile = $this->createProfile();
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $lister = new DirectoryLister($volumeRegistry, new PathNormalizer(), new DirectoryCacheManager(), new NullMetadataStorage());

        $listing = $lister->searchRecursive($profile, 'local', '', 'report-2026', 'all');

        self::assertSame(1, $listing->total);
        self::assertCount(1, $listing->items);
        self::assertSame('visible/nested/report-2026.txt', $listing->items[0]->path);
    }

    private function createApiController(ProfileConfig $profile, ?AuthorizationCheckerInterface $authChecker = null): ApiController
    {
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $volumeRegistry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $pathNormalizer = new PathNormalizer();
        $cacheManager = new DirectoryCacheManager();
        $metadata = new NullMetadataStorage();
        $events = new EventDispatcher();
        $trash = new TrashManager($volumeRegistry, $pathNormalizer, $events);
        $ops = new FileOperationsService($volumeRegistry, $pathNormalizer, new FilenameNormalizer(), $trash, $metadata, $events);
        $lister = new DirectoryLister($volumeRegistry, $pathNormalizer, $cacheManager, $metadata);
        $upload = new UploadManager($volumeRegistry, $pathNormalizer, new FilenameNormalizer(), $events);
        $importer = new RemoteUrlImporter($upload);
        $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $twig = new Environment(new ArrayLoader([]));

        return new ApiController(
            $configProvider,
            $volumeRegistry,
            $lister,
            $ops,
            $importer,
            $trash,
            $cacheManager,
            $thumbs,
            $pathNormalizer,
            $urlGen,
            $twig,
            new CsrfRequestValidator(null),
            $authChecker,
        );
    }

    /**
     * @param array<string, mixed> $naming
     * @param array<string, mixed> $security
     */
    private function createProfile(array $naming = [], array $security = []): ProfileConfig
    {
        return new ProfileConfig(
            name: 'default',
            basePath: $this->tempDir,
            theme: 'bootstrap5',
            locale: 'en',
            volumes: ['local'],
            driver: 'LocalFileSystem',
            path: $this->tempDir,
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: ['/^_thumbs$/'],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: [],
            uploadDeny: ['text/x-php', 'application/x-httpd-php'],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => true, 'chunk_size' => 2097152],
            naming: $naming,
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => true, 'driver' => 'gd', 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30],
            zip: ['enabled' => true, 'max_files' => 100, 'max_total_size' => '50M'],
            ui: [],
            permissions: [],
            security: array_merge(['voter_enabled' => false, 'csrf_protection' => true], $security),
            cache: ['enabled' => true, 'ttl' => 3600],
            metadata: ['enabled' => true],
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
