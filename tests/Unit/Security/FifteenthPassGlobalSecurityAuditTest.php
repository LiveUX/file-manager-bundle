<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use League\Flysystem\Config;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\SymbolicLinkEncountered;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\Event\FolderCreatedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\FileManagerBundle;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\ConfinedLocalFilesystemAdapter;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use LiveUX\FileManagerBundle\Util\HttpByteRangeParser;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use ZipArchive;

/**
 * Pass-15 (15. Tur) comprehensive security, cross-function, concurrency, performance & global multilingual tests.
 */
final class FifteenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_p15_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testConfinedLocalFilesystemAdapterBlocksTraversalAndSymlinks(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'vol';
        $outside = $this->tempDir . DIRECTORY_SEPARATOR . 'outside';
        mkdir($root . DIRECTORY_SEPARATOR . 'sub', 0775, true);
        mkdir($outside, 0775, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'ok.txt', 'safe');
        file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'TOP-SECRET');

        $adapter = new ConfinedLocalFilesystemAdapter($root);
        self::assertTrue($adapter->fileExists('ok.txt'));
        self::assertSame('safe', $adapter->read('ok.txt'));

        // Traversal and null-byte blocked unconditionally on every platform
        foreach (['../outside/secret.txt', "ok.txt\0.png"] as $badPath) {
            try {
                $adapter->read($badPath);
                self::fail('ConfinedLocalFilesystemAdapter must reject ' . $badPath);
            } catch (PathTraversalDetected) {
                $this->addToAssertionCount(1);
            }
        }

        // If OS allows symlink creation, verify read/write/delete/deleteDirectory block symlinks and listContents skips them
        $linkFile = $root . DIRECTORY_SEPARATOR . 'escape.txt';
        $linkInSub = $root . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'nested-link.txt';
        if (@symlink($outside . DIRECTORY_SEPARATOR . 'secret.txt', $linkFile)) {
            try {
                $adapter->read('escape.txt');
                self::fail('ConfinedLocalFilesystemAdapter must block direct symlink read');
            } catch (SymbolicLinkEncountered) {
                $this->addToAssertionCount(1);
            }

            try {
                $adapter->write('escape.txt', 'pwned', new Config());
                self::fail('ConfinedLocalFilesystemAdapter must block direct symlink write');
            } catch (SymbolicLinkEncountered) {
                $this->addToAssertionCount(1);
            }

            // listContents uses SKIP_LINKS so stray symlinks do not DoS listings with 500
            $listedPaths = [];
            foreach ($adapter->listContents('', false) as $item) {
                $listedPaths[] = $item->path();
            }
            self::assertContains('ok.txt', $listedPaths);
            self::assertNotContains('escape.txt', $listedPaths);
        }

        if (@symlink($outside . DIRECTORY_SEPARATOR . 'secret.txt', $linkInSub)) {
            try {
                $adapter->deleteDirectory('sub');
                self::fail('ConfinedLocalFilesystemAdapter must reject deleteDirectory containing nested symlinks');
            } catch (SymbolicLinkEncountered) {
                $this->addToAssertionCount(1);
            }
            self::assertFileExists($outside . DIRECTORY_SEPARATOR . 'secret.txt');
        }
    }

    public function testAuthorizerBlocksSlashOnlyFailOpenAndEnforcesAncestorsAcrossServices(): void
    {
        $profile = $this->makeProfile('default', ['local'], voterEnabled: true);

        // Voter denies root '' and denies 'restricted' ancestor, allows everything else
        $voterChecker = new class () implements AuthorizationCheckerInterface {
            /** @var list<array{attr: string, path: string}> */
            public array $calls = [];

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                if (!$subject instanceof FileManagerSubject) {
                    return false;
                }
                $this->calls[] = ['attr' => (string) $attribute, 'path' => $subject->path];

                return $subject->path !== '' && $subject->path !== 'restricted';
            }
        };

        $authorizer = new FileManagerAuthorizer($voterChecker);

        // T15-01: Slash-only paths MUST evaluate against root '' instead of failing open as true
        self::assertFalse($authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, '/'));
        self::assertFalse($authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, '///'));

        // Ancestor denial on 'restricted' MUST block descendant 'restricted/nested/file.txt'
        self::assertFalse($authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, 'restricted/nested/file.txt'));
        self::assertFalse($authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, 'restricted/nested/file.txt'));

        // Allowed path evaluates cleanly
        self::assertTrue($authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, 'public/ok.txt'));

        // Wire into FileOperationsService: deleting a child under a voter-denied ancestor MUST throw AccessDeniedException
        $volRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'ops_vol';
        mkdir($volRoot . DIRECTORY_SEPARATOR . 'restricted' . DIRECTORY_SEPARATOR . 'sub', 0775, true);
        file_put_contents($volRoot . DIRECTORY_SEPARATOR . 'restricted' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'doc.txt', 'hello');

        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $volRoot);
        $provider = $this->makeProvider(['default' => $profile], $vol);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $ops = new FileOperationsService(
            $registry,
            new PathNormalizer(),
            new FilenameNormalizer(),
            new TrashManager($registry, new PathNormalizer(), new EventDispatcher(), new FileSecurityGuard(), null, $voterChecker),
            new NullMetadataStorage(),
            new EventDispatcher(),
            $voterChecker,
        );

        try {
            $ops->delete($profile, 'restricted/sub/doc.txt', 'local');
            self::fail('FileOperationsService::delete must deny deletion under voter-denied ancestor');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
        self::assertFileExists($volRoot . DIRECTORY_SEPARATOR . 'restricted' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'doc.txt');

        // DirectoryLister: ancestor voter denial blocks listing a child directory even when child itself is allowed
        $lister = new DirectoryLister(
            $registry,
            new PathNormalizer(),
            new DirectoryCacheManager(),
            new NullMetadataStorage(),
            new FileSecurityGuard(),
            $voterChecker,
            hasExternalVoters: true,
        );

        try {
            $lister->list($profile, 'local', 'restricted/sub');
            self::fail('DirectoryLister::list must deny listing under voter-denied ancestor');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPickerAndTwigBlockLeadingSpaceQueryHppAndNestedCustomTrashAndExcludedPaths(): void
    {
        $volRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'picker_vol';
        mkdir($volRoot, 0775, true);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $volRoot, urlPrefix: '/uploads');
        $profile = $this->makeProfile(
            'default',
            ['local'],
            excludePatterns: ['/^private$/i'],
            trashDirectory: 'system/trash',
        );
        $provider = $this->makeProvider(['default' => $profile], $vol);

        $pickerType = new FileManagerPickerType($provider, null, null, null);
        $isSafeUrl = new ReflectionMethod(FileManagerPickerType::class, 'isSafePickerValue');
        $isUrlAccessible = new ReflectionMethod(FileManagerPickerType::class, 'isUrlAccessibleForProfile');

        // T15-02: Leading-space / plus / array / HPP query keys MUST be rejected
        $hostileUrls = [
            '/_liveux-file-manager/download?%20path=../../etc/passwd',
            '/_liveux-file-manager/download?+path=..%2Fsecret.png',
            '/_liveux-file-manager/download?path=ok.png&+path=../../etc/passwd',
            '/_liveux-file-manager/download?+path[]=ok.png',
            '/_liveux-file-manager/download?%20path[]=ok.png',
            'https://cdn.example.com\\..\\.trash\\secret.png',
        ];
        foreach ($hostileUrls as $badUrl) {
            self::assertFalse($isSafeUrl->invoke($pickerType, $badUrl), 'PickerType must reject ' . $badUrl);
        }

        // Nested custom trash directory ('system/trash') MUST be blocked in public URLs too
        self::assertFalse($isUrlAccessible->invoke(null, '/uploads/system/trash/deleted.png', $profile, new PathNormalizer(), new FileSecurityGuard()));
        self::assertTrue($isUrlAccessible->invoke(null, '/uploads/public/banner.png', $profile, new PathNormalizer(), new FileSecurityGuard()));

        // Twig extension must also block leading-space query HPP and excluded/trash relative paths
        $twigExt = new FileManagerTwigExtension(
            $this->createStub(UrlGeneratorInterface::class),
            null,
            null,
            $provider,
            new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver())),
        );
        foreach ($hostileUrls as $badUrl) {
            $desc = $twigExt->describeFieldItem($badUrl, 'default');
            self::assertNull($desc['href'], 'TwigExtension must reject href for ' . $badUrl);
            self::assertNull($desc['imgSrc'], 'TwigExtension must reject imgSrc for ' . $badUrl);
        }

        // Excluded profile path 'private/secret.png' and nested custom trash must return null href/imgSrc
        $privDesc = $twigExt->describeFieldItem('private/secret.png', 'default');
        self::assertNull($privDesc['href']);
        self::assertNull($privDesc['imgSrc']);
        $trashDesc = $twigExt->describeFieldItem('system/trash/item.png', 'default');
        self::assertNull($trashDesc['href']);
        self::assertNull($trashDesc['imgSrc']);
    }

    public function testSvgSanitizerEnforcesStrictTagAllowlistAndUseExpansionCaps(): void
    {
        $sanitizer = new SvgSanitizer();

        // P15-08: HTML5 foreign-content breakout tags (<br>, <plaintext>, <xmp>, <noembed>) MUST be stripped
        $breakoutSvg = '<svg xmlns="http://www.w3.org/2000/svg"><g><br/><plaintext>pwn</plaintext><xmp>x</xmp><noembed>y</noembed><path d="M0 0h10v10z"/></g></svg>';
        $cleaned = $sanitizer->sanitize($breakoutSvg);
        self::assertNotNull($cleaned);
        self::assertStringNotContainsString('<br', strtolower($cleaned));
        self::assertStringNotContainsString('<plaintext', strtolower($cleaned));
        self::assertStringNotContainsString('<xmp', strtolower($cleaned));
        self::assertStringNotContainsString('<noembed', strtolower($cleaned));
        self::assertStringContainsString('<path', $cleaned);

        // Billion-Laughs SVG <use> DoS (cyclic + >100 <use> nodes) MUST be neutralized/capped
        $uses = str_repeat('<use href="#dot"/>', 130);
        $dosSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><g id="cyc"><use href="#cyc"/></g><circle id="dot" r="2"/></defs>' . $uses . '</svg>';
        $cleanedDos = $sanitizer->sanitize($dosSvg);
        self::assertNotNull($cleanedDos);
        self::assertLessThanOrEqual(100, substr_count(strtolower($cleanedDos), '<use'));
        self::assertStringNotContainsString('href="#cyc"', $cleanedDos);
    }

    public function testFileSecurityGuardHardensActiveAliasesOctetStreamCustomTrashAndInternalDtdSvg(): void
    {
        $guard = new FileSecurityGuard();
        $profile = $this->makeProfile('default', ['local'], trashDirectory: 'my trash');

        // T15-03: Newly blocked active web aliases
        foreach (['doc.mht', 'page.xhtm', 'app.jhtml', 'card.wml', 'icon.svg2'] as $badFile) {
            try {
                $guard->assertSafeFilename($badFile);
                self::fail('FileSecurityGuard must block ' . $badFile);
            } catch (FileManagerException) {
                $this->addToAssertionCount(1);
            }
        }

        // Strict binary extensions MUST reject opaque application/octet-stream MIME
        try {
            $guard->assertMimeMatchesExtension('photo.jpg', 'application/octet-stream');
            self::fail('.jpg must reject application/octet-stream');
        } catch (FileManagerException) {
            $this->addToAssertionCount(1);
        }

        // Custom trash directory containing spaces cannot be bypassed with zero-width characters
        $zwspTrash = "my\u{200B} trash/secret.txt";
        try {
            $guard->assertAccessiblePath($zwspTrash, $profile);
            self::fail('Space + ZWSP custom trash path must be rejected');
        } catch (FileManagerException) {
            $this->addToAssertionCount(1);
        }

        // Internal DTD subset with nested '>' inside <!DOCTYPE svg [<!ELEMENT ...>]> MUST be recognized as SVG
        $dtdSvgFile = $this->tempDir . DIRECTORY_SEPARATOR . 'dtd_svg.xml';
        file_put_contents(
            $dtdSvgFile,
            "<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ELEMENT foo ANY><!ENTITY x \"a>b\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><rect width=\"10\" height=\"10\"/></svg>",
        );
        self::assertTrue(FileSecurityGuard::looksLikeSvgDocument($dtdSvgFile));

        // Unclosed huge XML prologue (>256KB) fails closed as suspicious SVG-like document
        $hugePrologueFile = $this->tempDir . DIRECTORY_SEPARATOR . 'huge_prologue.xml';
        file_put_contents($hugePrologueFile, '<?xml version="1.0"?><!-- ' . str_repeat('A', 270000));
        self::assertTrue(FileSecurityGuard::looksLikeSvgDocument($hugePrologueFile));
    }

    public function testZipArchivePartialExtractionDispatchesEventAndEnforcesQuota(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required.');
        }

        $volRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'zip_vol';
        mkdir($volRoot, 0775, true);
        $zipPath = $volRoot . DIRECTORY_SEPARATOR . 'bundle.zip';

        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('ok.txt', 'hello world');
        $zip->addFromString('evil.php', '<?php echo 1;');
        $zip->close();

        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $volRoot);
        $profile = $this->makeProfile('default', ['local'], zipExtractEnabled: true);
        $provider = $this->makeProvider(['default' => $profile], $vol);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));

        $dispatcher = new EventDispatcher();
        $dispatchedEvents = [];
        $dispatcher->addListener(FolderCreatedEvent::class, static function (FolderCreatedEvent $e) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = $e->path;
        });

        $zipService = new ZipArchiveService(
            $registry,
            new PathNormalizer(),
            new FileSecurityGuard(),
            new SvgSanitizer(),
            null,
            $dispatcher,
        );

        // Partial failure on evil.php MUST still dispatch FolderCreatedEvent in finally for ok.txt
        try {
            $zipService->extractArchive($profile, 'bundle.zip', 'out', 'local');
            self::fail('Extracting ZIP containing evil.php must fail');
        } catch (FileManagerException) {
            $this->addToAssertionCount(1);
        }
        self::assertNotEmpty($dispatchedEvents, 'Partial extraction must still dispatch FolderCreatedEvent in finally');
    }

    public function testRemoteUrlImporterStrictIpv6GlobalUnicastAllowlistAndExtensionlessSvg(): void
    {
        $isPublicIp = new ReflectionMethod(RemoteUrlImporter::class, 'isPublicIp');
        $extractFilename = new ReflectionMethod(RemoteUrlImporter::class, 'extractFilename');

        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $profile = $this->makeProfile('default', ['local']);
        $provider = $this->makeProvider(['default' => $profile], $vol);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $uploadManager = new UploadManager(
            $registry,
            new PathNormalizer(),
            new FilenameNormalizer(),
            new EventDispatcher(),
        );
        $importer = new RemoteUrlImporter(
            $uploadManager,
            $registry,
            new PathNormalizer(),
        );

        // Non-GUA & special-purpose IPv6 ranges MUST be blocked
        foreach (['::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', '100::1', '2001:db8::1', '2002:0a00:0001::1', '3fff::1', 'ff02::1', '::ffff:127.0.0.1', '64:ff9b::10.0.0.1'] as $blockedIp) {
            self::assertFalse($isPublicIp->invoke($importer, $blockedIp), 'IPv6 must be blocked: ' . $blockedIp);
        }
        // Legitimate public Global Unicast IPv6 MUST be allowed
        self::assertTrue($isPublicIp->invoke($importer, '2606:4700:4700::1111'));
        self::assertTrue($isPublicIp->invoke($importer, '2001:4860:4860::8888'));

        // Extensionless SVG URL gets .svg extension from Content-Type / SVG sniffing
        $svgTmp = $this->tempDir . DIRECTORY_SEPARATOR . 'remote_svg_tmp';
        file_put_contents($svgTmp, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');
        $resolvedName = $extractFilename->invoke($importer, 'https://example.com/icon-endpoint', ['Content-Type: image/svg+xml'], $svgTmp);
        self::assertSame('icon-endpoint.svg', $resolvedName);
    }

    public function testHttpByteRangeParserRfc9110Compliance(): void
    {
        // Case-insensitive BYTES= single range
        self::assertSame(['status' => 'ok', 'start' => 0, 'end' => 9], HttpByteRangeParser::parseSingleByteRange('BYTES=0-9', 100));
        // Suffix range
        self::assertSame(['status' => 'ok', 'start' => 90, 'end' => 99], HttpByteRangeParser::parseSingleByteRange('bytes=-10', 100));
        // Multi-range falls back to ignore (200 OK), NOT 416
        self::assertSame(['status' => 'ignore'], HttpByteRangeParser::parseSingleByteRange('bytes=0-10, 20-30', 100));
        // Unknown unit falls back to ignore (200 OK)
        self::assertSame(['status' => 'ignore'], HttpByteRangeParser::parseSingleByteRange('items=0-5', 100));
        // Unsatisfiable single range returns invalid (416)
        self::assertSame(['status' => 'invalid'], HttpByteRangeParser::parseSingleByteRange('bytes=500-600', 100));
        self::assertSame(['status' => 'invalid'], HttpByteRangeParser::parseSingleByteRange('bytes=-0', 100));
    }

    public function testGlobalMultilingualUnicodeNormalizerTranslatorAndIcuBundleDiscovery(): void
    {
        // Emoji VS16 and Japanese CJK IVS preserved on visible base chars
        self::assertSame("❤️", UnicodeHelper::trimEdgeInvisiblesAndDots("❤️"));
        self::assertSame("葛\u{E0100}", UnicodeHelper::trimEdgeInvisiblesAndDots("葛\u{E0100}"));
        // Standalone / trailing dots & edge invisibles still stripped (leading dots preserved for dotfile detection)
        self::assertSame('file', UnicodeHelper::trimEdgeInvisiblesAndDots("\u{200B}file..\u{200B}"));

        // Search folding normalizes Halfwidth/Fullwidth Katakana & Arabic/Persian ك/ي
        self::assertSame(UnicodeHelper::foldForSearch('カタカナ'), UnicodeHelper::foldForSearch('ｶﾀｶﾅ'));
        self::assertSame(UnicodeHelper::foldForSearch('کیک'), UnicodeHelper::foldForSearch('كيك'));

        // FilenameNormalizer: append budget preserved within 255 bytes, Persian ZWNJ preserved, côn.png disambiguated
        $normalizer = new FilenameNormalizer();
        $profile = $this->makeProfile('default', ['local'], normalizeMode: 'unicode', append: '-suffix2026');
        $longCjk = str_repeat('漢', 83) . '.png';
        $normalized = $normalizer->normalizeForUpload($longCjk, $profile);
        self::assertLessThanOrEqual(255, strlen($normalized));
        self::assertStringEndsWith('-suffix2026.png', $normalized);

        $sanitizeUnicodeSlug = new ReflectionMethod(FilenameNormalizer::class, 'sanitizeUnicodeSlug');
        $persian = (string) $sanitizeUnicodeSlug->invoke($normalizer, "می\u{200C}خواهم");
        self::assertStringContainsString("\u{200C}", $persian);

        $asciiProfile = $this->makeProfile('default', ['local'], normalizeMode: 'ascii');
        self::assertSame('file-con.png', $normalizer->normalizeForUpload('côn.png', $asciiProfile));

        // MessageTranslator handles POSIX .UTF-8 / @variant locales
        self::assertSame('ar-SA', MessageTranslator::toBcp47('ar_SA.UTF-8@euro'));
        self::assertTrue(MessageTranslator::isRtlLocale('ar_SA.UTF-8'));

        // FileManagerBundle discovers +intl-icu translation catalogs as well
        $icuTransDir = $this->tempDir . DIRECTORY_SEPARATOR . 'translations';
        mkdir($icuTransDir, 0775, true);
        file_put_contents($icuTransDir . DIRECTORY_SEPARATOR . 'LiveUXFileManagerBundle+intl-icu.ar.yaml', "title: \"مدير الملفات\"\n");
        $icuBuilder = new ContainerBuilder();
        $icuBuilder->setParameter('translator.default_path', $icuTransDir);
        $bundle = new FileManagerBundle();
        $reflLocales = new ReflectionMethod(FileManagerBundle::class, 'collectAllowedCatalogLocales');
        /** @var list<string> $locales */
        $locales = $reflLocales->invoke($bundle, [], $icuBuilder);
        self::assertContains('en', $locales);
        self::assertContains('tr', $locales);
        self::assertContains('fr', $locales);
        self::assertContains('ar', $locales, '+intl-icu translation catalog locale must be discovered');
    }

    public function testImageOptimizerPngCompressionDbalRootPrefixPgRangeStorageQuotaVirtualFoldersAndListeners(): void
    {
        // ImageOptimizer GD PNG compression produces compact output (level 7, not level 0)
        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            $pngPath = $this->tempDir . DIRECTORY_SEPARATOR . 'sample.png';
            $im = imagecreatetruecolor(120, 120);
            self::assertNotFalse($im);
            $blue = imagecolorallocate($im, 30, 120, 220);
            self::assertNotFalse($blue);
            imagefilledrectangle($im, 0, 0, 119, 119, $blue);
            // Save uncompressed level 0 PNG first
            imagepng($im, $pngPath, 0);
            imagedestroy($im);
            $rawSize = filesize($pngPath);
            self::assertIsInt($rawSize);

            $optimizer = new ImageOptimizer();
            $optProfile = $this->makeProfile('opt', ['local'], imageOptEnabled: true);
            $res = $optimizer->optimizeIfEnabled($pngPath, 'sample.png', 'image/png', $optProfile);
            self::assertTrue($res['optimized']);
            self::assertLessThan($rawSize, $res['size']);
        }

        // DoctrineDbalMetadataStorage: root prefix 'local::' (no double slash) + PG B-Tree equality & range predicates + batched copy
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_p15_meta', true);
        $dbal->save('local::a.png', new FolderMetadata(alt: 'A'));
        $dbal->save('local::sub/b.png', new FolderMetadata(alt: 'B'));
        $dbal->copyPrefix('local::', 'backup::');
        self::assertSame('A', $dbal->get('backup::a.png')->alt);
        self::assertSame('B', $dbal->get('backup::sub/b.png')->alt);

        $reflWhere = new ReflectionMethod(DoctrineDbalMetadataStorage::class, 'buildPrefixWhere');
        $mockPg = $this->createStub(Connection::class);
        $mockPg->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        [$shortClause, $shortParams] = $reflWhere->invoke($dbal, $mockPg, 'local::dir');
        self::assertStringContainsString('LEFT(canonical_key, 191) ~>=~ :rangeStart', $shortClause);
        self::assertStringContainsString('LEFT(canonical_key, 191) ~<=~ :rangeEnd', $shortClause);
        self::assertArrayHasKey('rangeStart', $shortParams);

        $longKey = 'local::' . str_repeat('a', 210);
        [$longClause, $longParams] = $reflWhere->invoke($dbal, $mockPg, $longKey);
        self::assertStringContainsString('LEFT(canonical_key, 191) = :prefix191', $longClause);
        self::assertArrayHasKey('prefix191', $longParams);

        // StorageQuotaChecker virtual ancestor folder counting on object-style S3 listing (no DirectoryAttributes) + buildSummaryFromUsedBytes
        $memFs = $this->createStub(FilesystemOperator::class);
        $memFs->method('listContents')->willReturnCallback(
            static fn (string $path, bool $deep): DirectoryListing => $path === 'dst'
                ? new DirectoryListing([new FileAttributes('dst/allowed.txt', 2)])
                : new DirectoryListing([new FileAttributes('deep/nested/file.txt', 10)]),
        );
        $memFs->method('fileExists')->willReturnCallback(
            static fn (string $p): bool => $p === 'dst/allowed.txt',
        );
        $memFs->method('directoryExists')->willReturn(false);

        $locator = new class ($memFs) implements ContainerInterface {
            public function __construct(private readonly FilesystemOperator $fs)
            {
            }

            public function get(string $id): mixed
            {
                return $this->fs;
            }

            public function has(string $id): bool
            {
                return $id === 's3.storage';
            }
        };

        $vol = new VolumeConfig('s3', 'S3', 's3', 'FlysystemService', '', driverOptions: ['service' => 's3.storage']);
        $profile = $this->makeProfile('default', ['s3'], maxStorageMb: 10);
        $provider = $this->makeProvider(['default' => $profile], $vol);
        $factory = new FlysystemStorageFactory(new DynamicPathResolver(), $locator);
        $registry = new VolumeRegistry($provider, $factory);
        $quota = new StorageQuotaChecker($registry, new PathNormalizer(), new DirectoryCacheManager());

        $breakdown = $quota->getDetailedBreakdown($profile);
        self::assertGreaterThanOrEqual(2, $breakdown['counts']['folders'], 'Virtual S3 ancestors (deep, deep/nested) must be counted');
        $summary = $quota->buildSummaryFromUsedBytes($profile, 1024);
        self::assertSame(1024, $summary['usedBytes']);
        self::assertTrue($summary['enabled']);

        // MetadataSyncListener pre-filters voter-denied children before saving destination metadata
        $metaStore = new JsonFileMetadataStorage($this->tempDir . DIRECTORY_SEPARATOR . 'sync-meta.json');
        $metaStore->save('s3::src/allowed.txt', new FolderMetadata(alt: 'Allowed'));
        $metaStore->save('s3::src/denied.txt', new FolderMetadata(alt: 'Secret'));
        $syncListener = new MetadataSyncListener($metaStore, null, $registry);
        $syncListener->onItemMoved(new ItemMovedEvent($profile, $vol, 'src', 's3::src', $vol, 'dst', 's3::dst', true, isCopy: true));
        self::assertSame('Allowed', $metaStore->get('s3::dst/allowed.txt')->alt);
        self::assertTrue($metaStore->get('s3::dst/denied.txt')->isEmpty(), 'Voter-denied child metadata must never be copied');

        // DetectExternalVotersPass detects untagged custom voter wired into Symfony AccessDecisionManager
        $container = new ContainerBuilder();
        $container->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class, [null, null, null, null, null, null, false]));
        $container->setDefinition('app.custom_fm_voter', new Definition(\stdClass::class));
        $container->setDefinition(
            AccessDecisionManagerInterface::class,
            new Definition(\Symfony\Component\Security\Core\Authorization\AccessDecisionManager::class, [[new Reference('app.custom_fm_voter')]]),
        );
        (new DetectExternalVotersPass())->process($container);
        self::assertTrue($container->getDefinition(DirectoryLister::class)->getArgument(6));
    }

    public function testMobileDialogModeConfigurationAndPickerResolution(): void
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        $cfg = $processor->processConfiguration(new \LiveUX\FileManagerBundle\DependencyInjection\Configuration(), [[
            'profiles' => [
                'default' => [
                    'ui' => ['mobile_dialog_mode' => 'sheet'],
                ],
                'fs' => [
                    'ui' => ['mobile_dialog_mode' => 'fullscreen'],
                ],
                'auto_prof' => [],
            ],
        ]]);
        self::assertSame('sheet', $cfg['profiles']['default']['ui']['mobile_dialog_mode']);
        self::assertSame('fullscreen', $cfg['profiles']['fs']['ui']['mobile_dialog_mode']);
        self::assertSame('auto', $cfg['profiles']['auto_prof']['ui']['mobile_dialog_mode']);

        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $sheetProfile = new ProfileConfig(
            name: 'sheet_prof',
            basePath: '',
            theme: 'bootstrap5',
            locale: null,
            volumes: ['local'],
            driver: 'local',
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
            chunkUpload: ['enabled' => true],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false],
            trash: ['enabled' => true],
            zip: ['enabled' => true],
            ui: ['mobile_dialog_mode' => 'sheet'],
            permissions: ['view' => true],
            security: [],
            cache: ['enabled' => false],
            metadata: ['driver' => 'json_file'],
        );
        $provider = $this->makeProvider(['sheet_prof' => $sheetProfile], $vol);
        $pickerType = new FileManagerPickerType($provider, null, null, null);
        $resolveMobileMode = new ReflectionMethod(FileManagerPickerType::class, 'resolveMobileDialogMode');
        self::assertSame('sheet', $resolveMobileMode->invoke($pickerType, null, 'sheet_prof'));
        self::assertSame('fullscreen', $resolveMobileMode->invoke($pickerType, 'fullscreen', 'sheet_prof'));
        self::assertSame('auto', $resolveMobileMode->invoke($pickerType, null, 'missing_prof'));
    }

    /**
     * @param list<string> $volumes
     * @param list<string> $excludePatterns
     */
    private function makeProfile(
        string $name,
        array $volumes,
        bool $voterEnabled = false,
        array $excludePatterns = [],
        string $trashDirectory = '.trash',
        bool $zipExtractEnabled = false,
        string $normalizeMode = 'ascii',
        string $append = '',
        int $maxStorageMb = 0,
        bool $imageOptEnabled = false,
    ): ProfileConfig {
        return new ProfileConfig(
            name: $name,
            basePath: '',
            theme: 'bootstrap5',
            locale: null,
            volumes: $volumes,
            driver: 'local',
            path: $this->tempDir,
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
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => true],
            naming: [
                'slugger' => true,
                'slugger_mode' => $normalizeMode,
                'prepend' => '',
                'append' => $append,
            ],
            imageOptimization: [
                'enabled' => $imageOptEnabled,
                'quality' => 85,
                'max_width' => 4096,
                'max_height' => 4096,
                'convert_to_webp' => false,
                'auto_orient' => false,
                'strip_metadata' => false,
            ],
            thumbnail: ['enabled' => false],
            trash: ['enabled' => true, 'directory' => $trashDirectory],
            zip: ['enabled' => true, 'allow_extract' => $zipExtractEnabled],
            ui: [],
            permissions: [
                'view' => true,
                'upload' => true,
                'create_folder' => true,
                'rename' => true,
                'move' => true,
                'copy' => true,
                'delete' => true,
                'download' => true,
                'edit_metadata' => true,
                'edit_image' => true,
                'share' => true,
                'trash_restore' => true,
                'trash_purge' => true,
            ],
            security: ['voter_enabled' => $voterEnabled],
            cache: ['enabled' => false, 'ttl' => 60],
            metadata: ['driver' => 'json_file'],
            storageQuota: $maxStorageMb > 0 ? ($maxStorageMb . 'M') : null,
            share: ['enabled' => true],
        );
    }

    /**
     * @param array<string, ProfileConfig> $profiles
     */
    private function makeProvider(array $profiles, VolumeConfig $volume): ConfigProviderInterface
    {
        $volumes = [$volume->name => $volume];

        return new class ($profiles, $volumes) implements ConfigProviderInterface {
            /**
             * @param array<string, ProfileConfig> $profiles
             * @param array<string, VolumeConfig>  $volumes
             */
            public function __construct(private readonly array $profiles, private readonly array $volumes)
            {
            }

            public function hasProfile(string $name): bool
            {
                return isset($this->profiles[$name]);
            }

            public function getProfile(string $name): ProfileConfig
            {
                if (!isset($this->profiles[$name])) {
                    throw new \InvalidArgumentException(sprintf('Unknown profile "%s".', $name));
                }

                return $this->profiles[$name];
            }

            public function getProfileNames(): array
            {
                return array_keys($this->profiles);
            }

            public function hasVolume(string $name): bool
            {
                return isset($this->volumes[$name]);
            }

            public function getVolume(string $name): VolumeConfig
            {
                return $this->volumes[$name];
            }

            public function getVolumes(): array
            {
                return $this->volumes;
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($full) || is_file($full)) {
                @unlink($full);
            } elseif (is_dir($full)) {
                $this->removeDirectory($full);
            }
        }
        @rmdir($dir);
    }
}
