<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use FilesystemIterator;
use GdImage;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Bridge\EasyAdmin\FileManagerField;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

/**
 * Regression suite for Pass-11: Nov-2026 CVE readiness, report/doc/skill consistency (T11-*),
 * and deep cross-function security, concurrency, performance, DBAL, and global Unicode findings (P11-*).
 */
final class EleventhPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_eleventh_' . bin2hex(random_bytes(4));
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

    public function testComposerFlysystemCveFloorAndExpandedActiveMimeDefaults(): void
    {
        $bundleRoot = dirname(__DIR__, 3);
        $composerRaw = file_get_contents($bundleRoot . '/composer.json');
        self::assertIsString($composerRaw);
        $composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        // CVE-2026-102601 requires league/flysystem >= 3.35.3
        self::assertSame('^3.35.3', $composer['require']['league/flysystem'] ?? null);

        $guardRefl = new ReflectionClass(FileSecurityGuard::class);
        /** @var list<string> $blockedMimes */
        $blockedMimes = $guardRefl->getConstant('BLOCKED_ACTIVE_MIMES');

        $processor = new Processor();
        /** @var array{profiles: array{default: array{upload_deny: list<string>}}} $processed */
        $processed = $processor->processConfiguration(new Configuration(), [['profiles' => ['default' => []]]]);
        $defaultDeny = $processed['profiles']['default']['upload_deny'];

        foreach ([
            'application/x-httpd-php',
            'application/x-msdownload',
            'application/x-dosexec',
            'application/x-executable',
            'application/x-pie-executable',
            'application/x-sharedlib',
            'application/x-mach-binary',
            'text/x-python',
            'text/x-script.python',
            'application/x-csh',
            'application/x-perl',
            'application/x-ruby',
            'application/x-tcl',
            'application/wasm',
            'application/java-archive',
            'application/javascript',
            'application/x-sh',
            'text/html',
        ] as $mime) {
            self::assertContains($mime, $blockedMimes, sprintf('Missing %s in FileSecurityGuard::BLOCKED_ACTIVE_MIMES', $mime));
            self::assertContains($mime, $defaultDeny, sprintf('Missing %s in Configuration profile upload_deny defaults', $mime));
        }
    }

    public function testSvgSanitizerPreservesSafeStyleCdataAsTextAndFailsClosedOnBadCss(): void
    {
        $sanitizer = new SvgSanitizer();

        $safeCdataSvg = '<svg xmlns="http://www.w3.org/2000/svg"><style><![CDATA[.cls-1{fill:#2563eb;}]]></style><rect class="cls-1" width="12" height="12"/></svg>';
        $cleaned = $sanitizer->sanitize($safeCdataSvg);
        self::assertStringContainsString('<style>.cls-1{fill:#2563eb;}</style>', $cleaned);
        self::assertStringNotContainsString('CDATA', $cleaned);

        $evilCdataSvg = '<svg xmlns="http://www.w3.org/2000/svg"><style><![CDATA[@import url(https://evil.test/x.css);]]></style><circle r="4"/></svg>';
        $cleanedEvil = $sanitizer->sanitize($evilCdataSvg);
        self::assertStringNotContainsString('<style', $cleanedEvil);
        self::assertStringNotContainsString('evil.test', $cleanedEvil);

        $exprSvg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{width:expression(alert(1));}</style><rect width="1" height="1"/></svg>';
        $cleanedExpr = $sanitizer->sanitize($exprSvg);
        self::assertStringNotContainsString('<style', $cleanedExpr);
        self::assertStringNotContainsString('expression', $cleanedExpr);
    }

    public function testGlobalUnicodeTruncateStemBytesAndHostCanonicalization(): void
    {
        // Regional Indicator flag pairs must not split into orphan half-flag
        $flags = '🇯🇵🇹🇷🇫🇷';
        $truncatedFlag = UnicodeHelper::truncateStemBytes($flags, 10);
        self::assertSame('🇯🇵', $truncatedFlag);

        // Devanagari combining marks must stay on grapheme boundaries
        $hindi = 'क्रिकेटmatch';
        $truncatedHindi = UnicodeHelper::truncateStemBytes($hindi, 10);
        self::assertTrue(mb_check_encoding($truncatedHindi, 'UTF-8'));
        self::assertLessThanOrEqual(10, strlen($truncatedHindi));

        // Emoji ZWJ sequence stays intact or drops completely instead of trailing ZWJ
        $family = '👨‍👩‍👧‍👦_file';
        $truncatedFamily = UnicodeHelper::truncateStemBytes($family, 15);
        self::assertFalse(str_ends_with($truncatedFamily, "\u{200D}"));
        self::assertLessThanOrEqual(15, strlen($truncatedFamily));

        // Strict RFC 1123 host canonicalization + bare IPv6 support
        self::assertSame('2001:4860:4860::8888', UnicodeHelper::canonicalizeHostToAscii('2001:4860:4860::8888'));
        self::assertSame('xn--mnchen-3ya.de', UnicodeHelper::canonicalizeHostToAscii('münchen.de'));
        self::assertNull(UnicodeHelper::canonicalizeHostToAscii('evil%2eexample.com'));
        self::assertNull(UnicodeHelper::canonicalizeHostToAscii('fe80::1%eth0'));
    }

    public function testTrashCanonicalBypassAndTrashManagerDisabledGuards(): void
    {
        $guard = new FileSecurityGuard();
        $profile = $this->createProfile(trash: ['enabled' => true, 'directory' => '.trash']);

        foreach (['.trash', '.trash/secret.jpg', '.ＴＲＡＳＨ/secret.jpg'] as $trashPath) {
            try {
                $guard->assertAccessiblePath($trashPath, $profile);
                self::fail(sprintf('Expected trash path "%s" to be rejected', $trashPath));
            } catch (FileManagerException) {
                $this->addToAssertionCount(1);
            }
        }

        // Disabled trash must fail closed in moveToTrash()
        $disabledProfile = $this->createProfile(trash: ['enabled' => false]);
        $configProvider = $this->createConfigProvider($disabledProfile);
        $trashManager = new TrashManager(
            $this->createLocalRegistry($configProvider),
            new PathNormalizer(),
            new EventDispatcher(),
            $guard,
        );
        file_put_contents($this->tempDir . '/doc.txt', 'hello');

        $this->expectException(AccessDeniedException::class);
        $trashManager->moveToTrash($disabledProfile, 'doc.txt');
    }

    public function testProfileConfigExtractZipNullFallbackFollowsAllowExtract(): void
    {
        $closedZip = $this->createProfile(
            permissions: ['extract_zip' => null],
            zip: ['enabled' => true, 'allow_extract' => false],
        );
        self::assertFalse($closedZip->getPermissionRule('extract_zip'));

        $openZip = $this->createProfile(
            permissions: ['extract_zip' => null],
            zip: ['enabled' => true, 'allow_extract' => true],
        );
        self::assertTrue($openZip->getPermissionRule('extract_zip'));

        $roleOverride = $this->createProfile(
            permissions: ['extract_zip' => 'ROLE_ADMIN'],
            zip: ['enabled' => true, 'allow_extract' => true],
        );
        self::assertSame('ROLE_ADMIN', $roleOverride->getPermissionRule('extract_zip'));
    }

    public function testDownloadAndShareNormalizeContentTypeAndSandboxHeaders(): void
    {
        file_put_contents($this->tempDir . '/notes.txt', 'Hello UTF-8 World');
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2ZkAAAAASUVORK5CYII=', true);
        self::assertIsString($pngBytes);
        file_put_contents($this->tempDir . '/pixel.png', $pngBytes);

        $profile = $this->createProfile(
            permissions: [
                'view' => 'ROLE_USER',
                'download' => 'ROLE_USER',
                'share' => 'ROLE_USER',
            ],
            share: ['enabled' => true, 'allow_inline_preview' => true],
        );
        $provider = $this->createConfigProvider($profile);
        $registry = $this->createLocalRegistry($provider);
        $normalizer = new PathNormalizer();

        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);

        $downloadController = new DownloadController($provider, $registry, $normalizer, $auth);

        $txtResponse = $downloadController(Request::create('/_liveux-file-manager/download', 'GET', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'notes.txt',
            'inline' => '1',
        ]));
        self::assertSame('text/plain; charset=UTF-8', $txtResponse->headers->get('Content-Type'));
        self::assertSame('nosniff', $txtResponse->headers->get('X-Content-Type-Options'));

        $pngResponse = $downloadController(Request::create('/_liveux-file-manager/download', 'GET', [
            'profile' => 'default',
            'volume' => 'local',
            'path' => 'pixel.png',
            'inline' => '1',
        ]));
        self::assertSame('image/png', $pngResponse->headers->get('Content-Type'));

        $uriSigner = new UriSigner('unit-test-secret');
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => 'https://localhost/_liveux-file-manager/share?' . http_build_query($params),
        );
        $shareService = new ShareLinkService($registry, $normalizer, $urlGenerator, $uriSigner, $auth);
        $shareController = new ShareController($provider, $registry, $normalizer, $shareService);

        $signed = $shareService->createSignedUrl($profile, 'notes.txt', 'local', 300, true);
        $shareResponse = $shareController(Request::create($signed['url'], 'GET'));
        self::assertSame('text/plain; charset=UTF-8', $shareResponse->headers->get('Content-Type'));
        self::assertSame('nosniff', $shareResponse->headers->get('X-Content-Type-Options'));
    }

    public function testPickerTypeAndTwigExtensionRejectEncodedAndActivePayloads(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new FileManagerPickerType())
            ->getFormFactory();

        $form = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'url']);
        $pickerType = new FileManagerPickerType();
        $reflSafe = new ReflectionMethod($pickerType, 'isSafePickerValue');

        self::assertFalse($reflSafe->invoke($pickerType, '//evil.example/a.png', 'url', false));
        self::assertFalse($reflSafe->invoke($pickerType, '/%2fevil.example/a.png', 'url', false));
        self::assertFalse($reflSafe->invoke($pickerType, '/%5cevil.example/a.png', 'url', false));
        self::assertFalse($reflSafe->invoke($pickerType, 'javascript:alert(1)', 'url', false));
        self::assertTrue($reflSafe->invoke($pickerType, '/uploads/photo.png', 'url', false));
        self::assertSame('liveux_file_manager_picker', $form->getName());

        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturn('/_liveux-file-manager/thumb?path=diagram.svg');
        $twigExt = new FileManagerTwigExtension($urlGen);

        // SVG URL: href nulled (active doc), imgSrc retained
        $svgInfo = $twigExt->describeFieldItem('/uploads/diagram.svg');
        self::assertIsArray($svgInfo);
        self::assertNull($svgInfo['href']);
        self::assertSame('/uploads/diagram.svg', $svgInfo['imgSrc']);

        // Relative .env / .trash / CJK-dot-svg paths null href and imgSrc
        foreach (['.env', '.trash/secret.jpg', "folder/test\u{3002}svg"] as $badRel) {
            $desc = $twigExt->describeFieldItem($badRel);
            self::assertIsArray($desc);
            self::assertNull($desc['href'], sprintf('href must be null for "%s"', $badRel));
            self::assertNull($desc['imgSrc'], sprintf('imgSrc must be null for "%s"', $badRel));
        }
    }

    public function testAutoInjectSubscriberChecksPublicCacheWithVoterProtectedProfileAndPickerMarker(): void
    {
        $voterProfile = $this->createProfile(
            security: ['voter_enabled' => true],
        );
        $publicProfile = $this->createProfile();
        $configProvider = $this->createConfigProvider([
            'public_default' => $publicProfile,
            'private_docs' => $voterProfile,
        ]);

        // Even when voter denies direct browser authorization, explicit picker DOM marker still triggers injection on private HTML
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div data-controller="liveux--file-manager-bundle--dialog"></div>',
        ]));

        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'public_default', 1050, $configProvider, $auth);
        $kernel = $this->createStub(HttpKernelInterface::class);

        // Publicly cached page MUST NOT receive DialogHost when ANY profile has voter_enabled: true
        $publicResponse = new Response(
            '<html><body>Hello</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=60'],
        );
        $event = new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $publicResponse);
        $subscriber->onKernelResponse($event);
        self::assertStringNotContainsString('liveux--file-manager-bundle--dialog', (string) $publicResponse->getContent());

        // Non-public HTML page WITH picker marker receives injection
        $privateResponse = new Response(
            '<html><body><div data-controller="liveux--file-manager-bundle--picker"></div></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store'],
        );
        $privateEvent = new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $privateResponse);
        $subscriber->onKernelResponse($privateEvent);
        self::assertStringContainsString('liveux--file-manager-bundle--dialog', (string) $privateResponse->getContent());
    }

    public function testEasyAdminColorSchemeOptionAndDocsConsistency(): void
    {
        $bundleRoot = dirname(__DIR__, 3);
        if (trait_exists(\EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait::class)) {
            $field = FileManagerField::new('banner', 'Banner')->setColorScheme('dark');
            $dto = $field->getAsDto();
            self::assertSame('dark', $dto->getFormTypeOption('color_scheme'));
        } else {
            $fieldSource = file_get_contents($bundleRoot . '/src/Bridge/EasyAdmin/FileManagerField.php');
            self::assertIsString($fieldSource);
            self::assertStringContainsString('public function setColorScheme(?string $colorScheme): self', $fieldSource);
            self::assertStringContainsString("setFormTypeOption('color_scheme', \$colorScheme)", $fieldSource);
        }

        $skill = file_get_contents($bundleRoot . '/skills/liveux-file-manager-theming-and-icons/SKILL.md');
        self::assertIsString($skill);
        self::assertStringContainsString("theme: 'file_manager/corporate_layout.html.twig'", $skill);
        self::assertStringContainsString("{% extends '@LiveUXFileManager/base/layout.html.twig' %}", $skill);
        self::assertStringNotContainsString("{% extends '@!LiveUXFileManager", $skill);

        $docs05 = file_get_contents($bundleRoot . '/docs/tr/05-formtype-ve-editor-entegrasyonu.md');
        self::assertIsString($docs05);
        self::assertStringContainsString('color_scheme', $docs05);
    }

    public function testRemoteUrlImporterRejectsSiitOrchidBenchmarkDocAndSrv6Prefixes(): void
    {
        $refl = new ReflectionMethod(RemoteUrlImporter::class, 'isPublicIpv6');
        $blocked = [
            '::ffff:0:0:127.0.0.1',
            '::ffff:0:0:8.8.8.8',
            '2001:2::1',
            '2001:10::1',
            '2001:20::1',
            '3fff::1',
            '5f00::1',
        ];
        foreach ($blocked as $ip) {
            $packed = inet_pton($ip);
            self::assertIsString($packed);
            self::assertFalse($refl->invoke(null, $ip, $packed), sprintf('IPv6 %s must be blocked', $ip));
        }

        $publicIp = '2606:4700:4700::1111';
        $publicPacked = inet_pton($publicIp);
        self::assertIsString($publicPacked);
        self::assertTrue($refl->invoke(null, $publicIp, $publicPacked));
    }

    public function testZipArchiveServiceAncestorCollisionDotLeaderAndSafeDestFallback(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('PHP ext-zip not installed.');
        }

        $profile = $this->createProfile(
            permissions: ['extract_zip' => true, 'view' => true, 'create_folder' => true, 'upload' => true],
            zip: ['enabled' => true, 'allow_extract' => true],
        );
        $provider = $this->createConfigProvider($profile);
        $registry = $this->createLocalRegistry($provider);
        $zipService = new ZipArchiveService($registry, new PathNormalizer());

        // U+2024 ONE DOT LEADER stripped by sanitizeZipSegment
        $reflSan = new ReflectionMethod($zipService, 'sanitizeZipSegment');
        self::assertSame('htaccess', $reflSan->invoke($zipService, "\u{2024}htaccess"));

        // resolveExtractDestination falls back safely for reserved Windows stem CON.zip
        $reflDest = new ReflectionMethod($zipService, 'resolveExtractDestination');
        /** @var string $resolvedDest */
        $resolvedDest = $reflDest->invoke($zipService, 'CON.zip', null, $profile);
        self::assertStringStartsWith('extracted-', $resolvedDest);

        // Ancestor file collision inside target_dir throws FileManagerException
        mkdir($this->tempDir . '/target_dir', 0775, true);
        file_put_contents($this->tempDir . '/target_dir/subfile', 'not-a-dir');
        $zipPath = $this->tempDir . '/bomb.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('subfile/payload.txt', 'hello');
        $zip->close();

        $this->expectException(FileManagerException::class);
        $zipService->extractArchive($profile, 'bomb.zip', 'target_dir');
    }

    public function testExifOrientationFiveAndSevenRotateAngles(): void
    {
        $optRefl = new ReflectionMethod(ImageOptimizer::class, 'applyExifOrientation');
        $gdRefl = new ReflectionMethod(GdThumbnailDriver::class, 'applyExifOrientation');

        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('ext-gd unavailable');
        }

        $makeLandscape = static function (): GdImage {
            $img = imagecreatetruecolor(20, 10);
            self::assertInstanceOf(GdImage::class, $img);
            imagefill($img, 0, 0, (int) imagecolorallocate($img, 0, 0, 0));
            $red = (int) imagecolorallocate($img, 255, 0, 0);
            imagesetpixel($img, 0, 0, $red);

            return $img;
        };

        $src5 = $makeLandscape();
        $res5 = $gdRefl->invoke(new GdThumbnailDriver(), $src5, 5);
        self::assertInstanceOf(GdImage::class, $res5);
        self::assertSame(10, imagesx($res5));
        self::assertSame(20, imagesy($res5));
        self::assertSame(255, (imagecolorat($res5, 0, 0) >> 16) & 0xFF);
        imagedestroy($src5);
        if ($res5 !== $src5) {
            imagedestroy($res5);
        }

        $srcOpt = $makeLandscape();
        $resOpt = $optRefl->invoke(new ImageOptimizer(), $srcOpt, 7);
        self::assertInstanceOf(GdImage::class, $resOpt);
        self::assertSame(10, imagesx($resOpt));
        self::assertSame(20, imagesy($resOpt));
        self::assertSame(255, (imagecolorat($resOpt, 9, 19) >> 16) & 0xFF);
        imagedestroy($srcOpt);
        if ($resOpt !== $srcOpt) {
            imagedestroy($resOpt);
        }
    }

    public function testFolderMetadataZwnjEdgeCleanupAndDbalNfdNfcDedup(): void
    {
        if (!class_exists(DriverManager::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Doctrine DBAL or pdo_sqlite unavailable');
        }

        // Persian ZWNJ sitting right before truncation boundary must be stripped cleanly
        $persianTag = str_repeat('ب', 39) . "\u{200C} extra";
        $tags = FolderMetadata::sanitizeTags([$persianTag]);
        self::assertCount(1, $tags);
        self::assertFalse(str_ends_with($tags[0], "\u{200C}"));

        // DBAL NFD legacy row + NFC row under same folder must relocate & copy without duplicate PK errors
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DoctrineDbalMetadataStorage($conn);

        $nfdKey = "local::shared/cafe\u{0301}";
        $nfcKey = 'local::shared/café';
        $reflEnsure = new ReflectionMethod($storage, 'ensureTable');
        $reflEnsure->invoke($storage, $conn);

        $conn->insert('liveux_fm_metadata', [
            'key_hash' => hash('sha256', $nfdKey),
            'canonical_key' => $nfdKey,
            'payload' => '{"alt":"old_nfd"}',
            'updated_at' => '2026-11-24 10:00:00',
        ]);
        $conn->insert('liveux_fm_metadata', [
            'key_hash' => hash('sha256', $nfcKey),
            'canonical_key' => $nfcKey,
            'payload' => '{"alt":"fresh_nfc"}',
            'updated_at' => '2026-11-24 11:00:00',
        ]);

        $storage->relocatePrefix('local::shared', 'local::bistro');
        $movedMeta = $storage->get('local::bistro/café');
        self::assertSame('fresh_nfc', $movedMeta->alt);

        $storage->copyPrefix('local::bistro', 'local::copy_bistro');
        self::assertSame('fresh_nfc', $storage->get('local::copy_bistro/café')->alt);
    }

    public function testDirectoryCacheSharedGroupKeyAndSubrootVolumeInvalidation(): void
    {
        $profile = $this->createProfile(cache: ['enabled' => true, 'ttl' => 300]);

        $tagCache = new TagAwareAdapter(new ArrayAdapter());
        $manager = new DirectoryCacheManager($tagCache);

        $baseTreeKey = 'local::__tree_v2__';
        $customDepthKey = 'local::__tree_v2___d2';

        $loads = 0;
        $loader = static function () use (&$loads): array {
            ++$loads;

            return [['name' => 'A']];
        };

        $manager->remember($profile, $customDepthKey, $loader, null, 'local', $baseTreeKey);
        self::assertSame(1, $loads);

        // Cache hit on second read
        $manager->remember($profile, $customDepthKey, $loader, null, 'local', $baseTreeKey);
        self::assertSame(1, $loads);

        // Invalidating canonical base tree key invalidates custom depth variant via sharedGroupKey
        $manager->invalidateDirectory($baseTreeKey);
        $manager->remember($profile, $customDepthKey, $loader, null, 'local', $baseTreeKey);
        self::assertSame(2, $loads);

        // Subroot volume invalidation resolves 'local_sub::tenant'
        $subScope = $manager->resolveVolumeScopeForRoot('local::tenant');
        self::assertSame('local_sub::tenant', $subScope);
        $subLoads = 0;
        $subLoader = static function () use (&$subLoads): array {
            ++$subLoads;

            return ['files' => []];
        };
        $manager->remember($profile, 'local::tenant/docs', $subLoader, null, $subScope);
        $manager->invalidateVolume('local::tenant');
        $manager->remember($profile, 'local::tenant/docs', $subLoader, null, $subScope);
        self::assertSame(2, $subLoads);
    }

    /**
     * @param list<string>         $volumes
     * @param array<string, mixed> $security
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $zip
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $share
     * @param array<string, mixed> $cache
     */
    private function createProfile(
        array $volumes = ['local'],
        array $security = [],
        array $permissions = [],
        array $zip = [],
        array $trash = ['enabled' => false],
        array $share = [],
        array $cache = ['enabled' => false, 'ttl' => 300],
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
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false, 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: $trash,
            zip: $zip,
            ui: [],
            permissions: $permissions,
            security: $security,
            cache: $cache,
            metadata: ['enabled' => false],
            share: $share,
        );
    }

    /**
     * @param ProfileConfig|array<string, ProfileConfig> $profiles
     */
    private function createConfigProvider(ProfileConfig|array $profiles, ?VolumeConfig $volume = null): ConfigProviderInterface
    {
        $profileMap = is_array($profiles) ? $profiles : [$profiles->name => $profiles];
        $vol = $volume ?? new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');

        return new class($profileMap, $vol) implements ConfigProviderInterface {
            /**
             * @param array<string, ProfileConfig> $profiles
             */
            public function __construct(private array $profiles, private VolumeConfig $vol)
            {
            }

            public function hasProfile(string $name): bool
            {
                return isset($this->profiles[$name]);
            }

            public function getProfile(string $name): ProfileConfig
            {
                if (isset($this->profiles[$name])) {
                    return $this->profiles[$name];
                }
                $firstKey = array_key_first($this->profiles);

                return $this->profiles[$firstKey];
            }

            public function getProfileNames(): array
            {
                return array_values(array_keys($this->profiles));
            }

            public function hasVolume(string $name): bool
            {
                return $name === $this->vol->name;
            }

            public function getVolume(string $name): VolumeConfig
            {
                return $this->vol;
            }

            public function getVolumes(): array
            {
                return [$this->vol->name => $this->vol];
            }
        };
    }

    private function createLocalRegistry(ConfigProviderInterface $provider): VolumeRegistry
    {
        return new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
    }
}
