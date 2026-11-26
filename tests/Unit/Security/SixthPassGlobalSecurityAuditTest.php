<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Command\DuplicatesCommand;
use LiveUX\FileManagerBundle\Command\StorageStatsCommand;
use LiveUX\FileManagerBundle\Command\TrashPurgeCommand;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileManagerVoter;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Normalizer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

/**
 * Regression suite for Pass-6: Global Multilingual Unicode/IDNA/RTL support and deep security hardening.
 */
final class SixthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_sixth_' . bin2hex(random_bytes(4));
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

    public function testUploadManagerBlocksZeroWidthExtensionStoredSvgXssAndSpoofedExtensions(): void
    {
        $guard = new FileSecurityGuard();
        foreach (["xss.s\u{200B}vg", "shell.p\u{200C}hp", "doc.pdf\u{3002}", "nul\u{200B}.txt"] as $maliciousName) {
            try {
                $guard->assertSafeFilename($maliciousName);
                self::fail(sprintf('Expected FileSecurityGuard to reject "%s".', $maliciousName));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $normalizer = new PathNormalizer();
        foreach (["..\u{200B}/secret.txt", "\u{FF0E}\u{FF0E}/secret.txt", "folder\u{3002}/a.txt"] as $badPath) {
            try {
                $normalizer->normalize($badPath);
                self::fail(sprintf('Expected PathNormalizer to reject "%s".', $badPath));
            } catch (InvalidPathException) {
                $this->addToAssertionCount(1);
            }
        }

        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'svg_enabled' => [
                    'volumes' => ['main'],
                    'upload_deny' => [],
                    'security' => ['allow_svg_upload' => true],
                ],
            ],
        );
        $profile = $configProvider->getProfile('svg_enabled');
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), new EventDispatcher(), new SvgSanitizer());

        $svgTmp = $this->tempDir . DIRECTORY_SEPARATOR . '_raw_xss_payload';
        file_put_contents($svgTmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>');
        $uploaded = new UploadedFile($svgTmp, "xss.s\u{200B}vg", 'image/svg+xml', null, true);

        try {
            $uploadMgr->handleUpload($profile, $uploaded, '');
            self::fail('Expected zero-width extension SVG upload to be rejected.');
        } catch (UploadValidationException) {
            self::assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'xss.svg');
        }
    }

    public function testBaselineViewPermissionGatesVoterAuthorizerDownloadZipShareAndFolderDelete(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'restricted' => [
                    'path' => $this->tempDir,
                    'permissions' => ['view' => 'ROLE_EDITOR'],
                ],
            ],
        ]]);

        $configProvider = new YamlConfigProvider(
            defaults: $processed['defaults'],
            volumes: $processed['volumes'],
            profiles: $processed['profiles'],
        );
        $profile = $configProvider->getProfile('restricted');

        $adm = $this->createStub(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes): bool => in_array('ROLE_EDITOR', $token->getRoleNames(), true) || in_array($attributes[0] ?? '', $token->getRoleNames(), true),
        );
        $voter = new FileManagerVoter($adm);
        $subject = new FileManagerSubject($profile, 'report.pdf');

        $regularUserToken = $this->makeTokenWithRoles(['ROLE_USER']);
        $editorToken = $this->makeTokenWithRoles(['ROLE_EDITOR']);

        foreach ([
            FileManagerPermissions::VIEW,
            FileManagerPermissions::DOWNLOAD,
            FileManagerPermissions::DOWNLOAD_ZIP,
            FileManagerPermissions::SHARE,
            FileManagerPermissions::RENAME,
            FileManagerPermissions::DELETE,
            FileManagerPermissions::UPLOAD,
        ] as $perm) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($regularUserToken, $subject, [$perm]));
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($editorToken, $subject, [$perm]));
        }

        // Static authorizer fallback (voter_enabled: false) also honors baseline `view: false`
        $noViewProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'noview' => [
                    'volumes' => ['main'],
                    'security' => ['voter_enabled' => false],
                    'permissions' => ['view' => false, 'download' => true, 'share' => true],
                ],
            ],
        );
        $noViewProfile = $noViewProvider->getProfile('noview');
        $staticAuthorizer = new FileManagerAuthorizer(null);
        self::assertFalse($staticAuthorizer->isGranted($noViewProfile, FileManagerPermissions::DOWNLOAD, 'report.pdf'));
        self::assertFalse($staticAuthorizer->isGranted($noViewProfile, FileManagerPermissions::SHARE, 'report.pdf'));

        $this->assertControllersAndDeleteHonorVoterViewAndChildDelete($configProvider, $profile);
    }

    public function testSvgSanitizerBlocksCssStringCommentDifferentialAndUnclosedComments(): void
    {
        $sanitizer = new SvgSanitizer();

        $bypassAttempt = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{content:"/*"} @import url("https://evil.example/steal.css"); .b{content:"*/"}</style><rect width="10" height="10"/></svg>';
        $sanitizedBypass = $sanitizer->sanitize($bypassAttempt);
        self::assertStringNotContainsString('@import', $sanitizedBypass);
        self::assertStringNotContainsString('<style', $sanitizedBypass);

        $unclosedComment = '<svg xmlns="http://www.w3.org/2000/svg"><style>/* unclosed @import "https://evil.example/x.css"</style><rect width="10" height="10"/></svg>';
        $sanitizedUnclosed = $sanitizer->sanitize($unclosedComment);
        self::assertStringNotContainsString('@import', $sanitizedUnclosed);
        self::assertStringNotContainsString('<style', $sanitizedUnclosed);

        $cleanSvg = '<svg xmlns="http://www.w3.org/2000/svg"><style>/* safe comment */ .box { fill: #2563eb; }</style><rect class="box" width="12" height="12"/></svg>';
        $sanitized = $sanitizer->sanitize($cleanSvg);
        self::assertStringContainsString('#2563eb', $sanitized);
    }

    public function testUts46IdnaSsrfDefensePickerPathValidationAndRfc6266FilenameStar(): void
    {
        foreach ([
            "127\u{3002}0\u{3002}0\u{3002}1",
            "127\u{FF0E}0\u{FF0E}0\u{FF0E}1",
            "127\u{FF61}0\u{FF61}0\u{FF61}1",
            "\u{FF4C}\u{FF4F}\u{FF43}\u{FF41}\u{FF4C}\u{FF48}\u{FF4F}\u{FF53}\u{FF54}",
            "\u{FF10}\u{FF11}\u{FF17}\u{FF17}.0.0.1",
            "intranet.\u{FF4C}\u{FF4F}\u{FF43}\u{FF41}\u{FF4C}",
        ] as $ssrfHost) {
            self::assertFalse(RemoteUrlImporter::isSafeRemoteHostLiteral($ssrfHost), sprintf('Expected SSRF host "%s" to be rejected.', $ssrfHost));
        }

        self::assertTrue(RemoteUrlImporter::isSafeRemoteHostLiteral('cdn.example.com'));
        if (function_exists('idn_to_ascii')) {
            self::assertTrue(RemoteUrlImporter::isSafeRemoteHostLiteral('bücher.de'));
        }

        [$profile, $registry] = $this->buildSimpleSetup();
        $uploadMgr = new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher());
        $importer = new RemoteUrlImporter($uploadMgr, $registry);

        foreach ([
            "http://127\u{3002}0\u{3002}0\u{3002}1/secret.jpg",
            "http://\u{FF4C}\u{FF4F}\u{FF43}\u{FF41}\u{FF4C}\u{FF48}\u{FF4F}\u{FF53}\u{FF54}/secret.jpg",
            "https://example.com\u{3002}/photo.jpg",
        ] as $ssrfUrl) {
            try {
                $importer->assertSafeUrl($ssrfUrl);
                self::fail(sprintf('Expected RemoteUrlImporter::assertSafeUrl to reject "%s".', $ssrfUrl));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // RFC 6266 filename* MUST win over ASCII fallback filename="..."
        $refExtract = new ReflectionMethod(RemoteUrlImporter::class, 'extractFilename');
        $extracted = $refExtract->invoke(
            $importer,
            'https://cdn.example.com/download',
            ['Content-Disposition: attachment; filename="ascii-fallback.png"; filename*=UTF-8\'\'%E6%9D%B1%E4%BA%AC%E3%82%BF%E3%83%AF%E3%83%BC.png'],
            '',
        );
        self::assertSame('東京タワー.png', $extracted);

        $this->assertPickerFormValidationAndViewVars($profile);
    }

    public function testGlobalScriptsCombiningMarksEmojiFoldersAnd200ByteTruncation(): void
    {
        $normalizer = new FilenameNormalizer();
        $cfg = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'raw' => ['volumes' => ['main'], 'naming' => ['slugger' => false, 'transformer' => 'none']],
                'slug' => ['volumes' => ['main'], 'naming' => ['slugger' => true, 'transformer' => 'lowercase']],
            ],
        );
        $rawProfile = $cfg->getProfile('raw');
        $slugProfile = $cfg->getProfile('slug');

        $worldSamples = [
            'हिन्दी फ़ाइल २०२६.png',
            'ไฟล์ภาพสินค้า.webp',
            'বাংলা ছবি.jpg',
            'தமிழ் படம்.png',
            'مُدِيرُ الْمِلَفَّاتِ.pdf',
            "پروژه\u{200C}ها.pdf",
            'קובץ תמונה.jpg',
            '產品目錄-2026.pdf',
            '製品カタログ.webp',
            '제품_카탈로그.png',
            "Cafe\u{0301}-Ti\u{1EBF}ng_Vi\u{1EC7}t.jpg",
        ];

        foreach ($worldSamples as $sample) {
            $cleanRaw = $normalizer->normalizeForUpload($sample, $rawProfile);
            $cleanSlug = $normalizer->normalizeForUpload($sample, $slugProfile);
            self::assertTrue(mb_check_encoding($cleanRaw, 'UTF-8'));
            self::assertTrue(mb_check_encoding($cleanSlug, 'UTF-8'));
            self::assertNotSame('file.' . PathNormalizer::extensionUtf8($sample), $cleanRaw);
            self::assertNotSame('file.' . PathNormalizer::extensionUtf8($sample), $cleanSlug);
            if (class_exists(Normalizer::class)) {
                self::assertTrue(Normalizer::isNormalized($cleanRaw, Normalizer::FORM_C));
            }
        }

        // Devanagari combining vowel signs (\p{M}) must survive slugging intact
        $hindiRaw = $normalizer->normalizeForUpload('हिन्दी.png', $rawProfile);
        self::assertSame('हिन्दी.png', $hindiRaw);

        // Long Cyrillic multi-byte filename (70 * 'Щ' = 140 chars = 280 bytes) must truncate cleanly <= 205 bytes
        $longCyrillic = str_repeat('Щ', 120) . '.webp';
        $truncated = $normalizer->normalizeForUpload($longCyrillic, $rawProfile);
        self::assertTrue(mb_check_encoding($truncated, 'UTF-8'));
        self::assertLessThanOrEqual(205, strlen($truncated));
        self::assertStringEndsWith('.webp', $truncated);

        // Emoji-only folder name under slugger MUST throw errors.folder_name_empty rather than silently creating 'file'
        try {
            $normalizer->normalizeFolderName('🚀✨', $slugProfile);
            self::fail('Expected emoji-only folder name to be rejected when slugger is enabled.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.folder_name_empty', $e->getTranslationKey());
        }

        // Zero-width invisible folder name under raw profile MUST also throw errors.folder_name_empty
        try {
            $normalizer->normalizeFolderName("\u{200B}\u{200C}", $rawProfile);
            self::fail('Expected invisible-only folder name to be rejected.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.folder_name_empty', $e->getTranslationKey());
        }

        // Emoji ZWJ file upload safely falls back to 'file.png'
        self::assertSame('file.png', $normalizer->normalizeForUpload("👩\u{200D}💻.png", $slugProfile));
    }

    public function testGlobalSearchFoldingNfdExcludePatternsAndBcp47RtlLocales(): void
    {
        [$profile, $registry] = $this->buildSimpleSetup([], ['/café-secret/iu']);
        $guard = new FileSecurityGuard();

        // macOS NFD path MUST NOT bypass NFC exclude_patterns!
        try {
            $guard->assertAccessiblePath("Cafe\u{0301}-secret.pdf", $profile);
            self::fail('Expected NFD filename to be blocked by NFC exclude_patterns.');
        } catch (InvalidPathException) {
            $this->addToAssertionCount(1);
        }

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'İletişim.pdf', 'tr');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'Işık.png', 'tr2');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'IMG_2026.jpg', 'en');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'café.txt', 'fr');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'がぞく.txt', 'ja');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'Ὀδυσσεύς.txt', 'el');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'banner.webp', 'tag');

        $metaStore = new JsonFileMetadataStorage($this->tempDir . DIRECTORY_SEPARATOR . 'meta.json');
        $vol = $registry->getVolumeForProfile($profile);
        $normalizer = new PathNormalizer();
        $bannerKey = $normalizer->buildCanonicalKey($vol, 'banner.webp', $profile->basePath);
        $parsedMeta = FolderMetadata::fromArray(['tags' => '東京،प्रमोशन、프로모션，акция']);
        self::assertSame(['東京', 'प्रमोशन', '프로모션', 'акция'], $parsedMeta->tags);
        $metaStore->save($bannerKey, $parsedMeta);

        $lister = new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), $metaStore, $guard);

        self::assertCount(1, $lister->list($profile, search: 'iletişim')->items);
        self::assertCount(1, $lister->list($profile, search: 'ışık')->items);
        self::assertCount(1, $lister->list($profile, search: 'img_2026')->items);
        self::assertCount(1, $lister->list($profile, search: "Cafe\u{0301}")->items);
        self::assertCount(1, $lister->list($profile, search: "か\u{3099}ぞく")->items);
        self::assertCount(1, $lister->list($profile, search: 'ὀδυσσεύσ')->items);
        // Fullwidth hash `＃` tag search + recursive search
        self::assertCount(1, $lister->list($profile, search: "\u{FF03}東京")->items);
        self::assertCount(1, $lister->searchRecursive($profile, null, '', "\u{FF03}प्रमोशन")->items);

        // Locale validation, BCP-47 normalization, and RTL script detection
        self::assertTrue(MessageTranslator::isValidLocaleString('zh-Hans-CN'));
        self::assertTrue(MessageTranslator::isValidLocaleString('sr_Latn_RS'));
        self::assertTrue(MessageTranslator::isValidLocaleString('es_419'));
        self::assertTrue(MessageTranslator::isValidLocaleString('ckb'));
        self::assertFalse(MessageTranslator::isValidLocaleString('../etc/passwd'));

        self::assertSame('pt-BR', MessageTranslator::toBcp47('pt_BR'));
        self::assertSame('zh-Hans-CN', MessageTranslator::toBcp47('zh_hans_cn'));

        foreach (['ar', 'ar_SA', 'he_IL', 'fa-IR', 'ur', 'ckb', 'ug', 'pa_Arab', 'az-Arab'] as $rtl) {
            self::assertTrue(MessageTranslator::isRtlLocale($rtl), sprintf('Expected "%s" to be RTL.', $rtl));
        }
        foreach (['en', 'tr_TR', 'fr_FR', 'zh_Hans_CN', 'ja', 'hi', 'th', 'uz_Latn', 'pa_Guru'] as $ltr) {
            self::assertFalse(MessageTranslator::isRtlLocale($ltr), sprintf('Expected "%s" to be LTR.', $ltr));
        }
    }

    public function testConfigurationHyphenatedKeysUtf16TextPreviewAndCliUserIdVolumeSafety(): void
    {
        $tenantPattern = $this->tempDir . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . '{user_id}';
        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'volumes' => [
                's3-media' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir],
                'tenant-docs' => ['driver' => 'LocalFileSystem', 'path' => $tenantPattern],
            ],
            'profiles' => [
                'blog-editor' => [
                    'volumes' => ['s3-media', 'tenant-docs'],
                    'trash' => ['enabled' => true],
                ],
            ],
        ]]);

        self::assertArrayHasKey('s3-media', $processed['volumes']);
        self::assertArrayHasKey('blog-editor', $processed['profiles']);

        $configProvider = new YamlConfigProvider($processed['defaults'], $processed['volumes'], $processed['profiles']);
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        // UTF-16LE & UTF-8 BOM text preview via ApiController::previewText
        $utf16Content = "\xFF\xFE" . mb_convert_encoding('مرحبا 東京 नमस्ते Привет', 'UTF-16LE', 'UTF-8');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'multilingual.txt', $utf16Content);

        $lister = new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage());
        $dispatcher = new EventDispatcher();
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, new NullMetadataStorage(), $dispatcher);
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher);
        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 'sec');
        $api = new ApiController(
            $configProvider,
            $registry,
            $lister,
            $ops,
            new RemoteUrlImporter($uploadMgr, $registry),
            $trash,
            new DirectoryCacheManager(),
            $thumbMgr,
            $normalizer,
            $this->createStub(UrlGeneratorInterface::class),
            new Environment(new ArrayLoader()),
            new CsrfRequestValidator(null, false),
        );

        $previewResp = $api->previewText(Request::create('/_liveux-file-manager/api/preview-text?profile=blog-editor&volume=s3-media&path=multilingual.txt'));
        self::assertSame(Response::HTTP_OK, $previewResp->getStatusCode());
        $previewData = json_decode((string) $previewResp->getContent(), true);
        self::assertIsArray($previewData);
        self::assertTrue($previewData['ok']);
        self::assertSame('مرحبا 東京 नमस्ते Привет', $previewData['content']);

        // CLI commands must skip `{user_id}` volumes and NEVER create a `tenants/guest` directory on disk!
        $scanner = new ProfileFileScanner($configProvider, $registry, $normalizer);
        $quota = new StorageQuotaChecker($registry, $normalizer, new DirectoryCacheManager());
        $dupDetector = new DuplicateDetector($registry, $normalizer, new DirectoryCacheManager());

        self::assertSame(Command::SUCCESS, (new CommandTester(new StorageStatsCommand($scanner, $quota, $thumbMgr, $trash)))->execute(['profile' => 'blog-editor', '--json' => true]));
        self::assertSame(Command::SUCCESS, (new CommandTester(new DuplicatesCommand($scanner, $dupDetector)))->execute(['profile' => 'blog-editor', '--json' => true]));
        self::assertSame(Command::SUCCESS, (new CommandTester(new TrashPurgeCommand($trash, $scanner)))->execute(['profile' => 'blog-editor', '--dry-run' => true]));
        self::assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . 'guest');
    }

    private function assertControllersAndDeleteHonorVoterViewAndChildDelete(
        YamlConfigProvider $configProvider,
        ProfileConfig $profile,
    ): void {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'doc.txt', 'hello');
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'team', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'team' . DIRECTORY_SEPARATOR . 'locked.txt', 'keep');

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        // Auth checker that denies VIEW but returns true for DOWNLOAD / DOWNLOAD_ZIP / SHARE
        $denyViewAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyViewAuth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr): bool => $attr !== FileManagerPermissions::VIEW,
        );

        $dlCtrl = new DownloadController($configProvider, $registry, $normalizer, $denyViewAuth);
        try {
            $dlCtrl(Request::create('/_liveux-file-manager/download?profile=restricted&path=doc.txt'));
            self::fail('Expected DownloadController to require VIEW permission.');
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        $urlGen = $this->makeDummyUrlGenerator();
        $shareSvc = new ShareLinkService($registry, $normalizer, $urlGen, new UriSigner('sec-key'), $denyViewAuth);
        try {
            $shareSvc->createSignedUrl($profile, 'doc.txt');
            self::fail('Expected ShareLinkService to require VIEW permission.');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        if (class_exists(ZipArchive::class)) {
            $zipSvcDenied = new ZipArchiveService($registry, $normalizer, authChecker: $denyViewAuth);
            $zipCtrlDenied = new ZipDownloadController($configProvider, $zipSvcDenied, new CsrfRequestValidator(null, false), $denyViewAuth);
            $zipDeniedResp = $zipCtrlDenied(Request::create(
                '/_liveux-file-manager/zip',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode(['profile' => 'restricted', 'paths' => ['doc.txt']]),
            ));
            self::assertSame(Response::HTTP_FORBIDDEN, $zipDeniedResp->getStatusCode());

            // Valid ZIP download sets Content-Type: application/zip and CSP: sandbox
            $allowAllAuth = $this->createStub(AuthorizationCheckerInterface::class);
            $allowAllAuth->method('isGranted')->willReturn(true);
            $zipSvcOk = new ZipArchiveService($registry, $normalizer, authChecker: $allowAllAuth);
            $zipCtrlOk = new ZipDownloadController($configProvider, $zipSvcOk, new CsrfRequestValidator(null, false), $allowAllAuth);
            $zipOkResp = $zipCtrlOk(Request::create(
                '/_liveux-file-manager/zip',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode(['profile' => 'restricted', 'paths' => ['doc.txt']]),
            ));
            self::assertSame(Response::HTTP_OK, $zipOkResp->getStatusCode());
            self::assertSame('application/zip', $zipOkResp->headers->get('Content-Type'));
            self::assertSame("default-src 'none'; sandbox", $zipOkResp->headers->get('Content-Security-Policy'));
            if ($zipOkResp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
                @unlink($zipOkResp->getFile()->getPathname());
            }
        }

        // Permanent folder delete must check Voter DELETE on nested child files
        $denyChildDeleteAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyChildDeleteAuth->method('isGranted')->willReturnCallback(
            static function (mixed $attr, mixed $subj): bool {
                if ($attr === FileManagerPermissions::DELETE && is_object($subj) && property_exists($subj, 'path') && $subj->path === 'team/locked.txt') {
                    return false;
                }

                return true;
            },
        );
        $dispatcher = new EventDispatcher();
        $noTrashProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'notrash' => [
                    'volumes' => ['main'],
                    'trash' => ['enabled' => false],
                    'security' => ['voter_enabled' => true],
                ],
            ],
        );
        $noTrashProfile = $noTrashProvider->getProfile('notrash');
        $noTrashRegistry = new VolumeRegistry($noTrashProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $trash = new TrashManager($noTrashRegistry, $normalizer, $dispatcher, authChecker: $denyChildDeleteAuth);
        $ops = new FileOperationsService($noTrashRegistry, $normalizer, new FilenameNormalizer(), $trash, new NullMetadataStorage(), $dispatcher, $denyChildDeleteAuth);
        try {
            $ops->delete($noTrashProfile, 'team', null, true);
            self::fail('Expected folder delete to abort when child file DELETE is denied by Voter.');
        } catch (AccessDeniedException) {
            self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'team' . DIRECTORY_SEPARATOR . 'locked.txt');
        }
    }

    private function assertPickerFormValidationAndViewVars(ProfileConfig $profile): void
    {
        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads']],
            profiles: ['default' => ['volumes' => ['main'], 'locale' => 'ar_SA']],
        );
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $pickerType = new FileManagerPickerType($configProvider, new MessageTranslator(), $registry, $this->makeDummyUrlGenerator());
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([$pickerType], []))
            ->getFormFactory();

        // Check RTL, BCP-47 locale, and thumb_url view vars
        $rtlView = $factory->create(FileManagerPickerType::class, null, ['profile' => 'default'])->createView();
        self::assertSame('ar-SA', $rtlView->vars['bcp47_locale']);
        self::assertTrue($rtlView->vars['is_rtl']);
        self::assertNotSame('', $rtlView->vars['thumb_url']);

        // return_type: 'path' rejects full URLs, leading slashes, query strings, and traversal
        foreach (['https://cdn.example.com/a.jpg', '/uploads/a.jpg', 'banners/a.jpg?x=1', '../secret.jpg'] as $badPathVal) {
            $f = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'path']);
            $f->submit($badPathVal);
            self::assertFalse($f->isValid(), sprintf('Expected return_type=path to reject "%s".', $badPathVal));
        }

        $okPathForm = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'path']);
        $okPathForm->submit('banners/東京-2026.webp');
        self::assertTrue($okPathForm->isValid());

        // return_type: 'url' blocks fullwidth SSRF hosts and accepts UTF-8 path URLs
        $badUrlForm = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'url']);
        $badUrlForm->submit("http://127\u{3002}0\u{3002}0\u{3002}1/secret.jpg");
        self::assertFalse($badUrlForm->isValid());

        $okUrlForm = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'url']);
        $okUrlForm->submit('https://cdn.example.com/產品/圖片.png');
        self::assertTrue($okUrlForm->isValid());
    }

    /**
     * @param array<string, mixed> $security
     * @param list<string>         $excludePatterns
     *
     * @return array{0: ProfileConfig, 1: VolumeRegistry}
     */
    private function buildSimpleSetup(array $security = [], array $excludePatterns = []): array
    {
        $configProvider = new YamlConfigProvider(
            defaults: [],
            volumes: ['main' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => ['volumes' => ['main'], 'security' => $security, 'exclude_patterns' => $excludePatterns]],
        );
        $profile = $configProvider->getProfile('default');
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        return [$profile, $registry];
    }

    /**
     * @param list<string> $roles
     */
    private function makeTokenWithRoles(array $roles): TokenInterface
    {
        $user = new class($roles) implements UserInterface {
            /** @param list<string> $roles */
            public function __construct(private readonly array $roles)
            {
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'tester';
            }
        };

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    private function makeDummyUrlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $path = $name === 'liveux_file_manager_thumb' ? '/_liveux-file-manager/thumb' : '/_liveux-file-manager/share';

                return ($referenceType === self::ABSOLUTE_URL ? 'https://example.test' : '')
                    . $path
                    . ($parameters !== [] ? '?' . http_build_query($parameters) : '');
            }
        };
    }
}
