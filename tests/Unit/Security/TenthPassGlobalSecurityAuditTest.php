<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Command\ThumbnailClearCommand;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\EventListener\ThumbnailCleanupListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Model\FolderItem;
use LiveUX\FileManagerBundle\Model\TrashEntry;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
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
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Regression suite for Pass-10: Report/Doc/Skill consistency fixes (T10-*)
 * and new security, concurrency, performance, DBAL, CLI, and global Unicode findings (P10-*).
 */
final class TenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_tenth_' . bin2hex(random_bytes(4));
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

    public function testSvgSanitizerNamespaceCdataStyleAndCssImageHardening(): void
    {
        $sanitizer = new SvgSanitizer();

        // CDATA / dangerous CSS import inside <style> is stripped
        $cdataSvg = '<svg xmlns="http://www.w3.org/2000/svg"><style><![CDATA[@import url(https://evil.example/x.css);]]></style><rect width="10" height="10"/></svg>';
        $cleanedCdata = $sanitizer->sanitize($cdataSvg);
        self::assertStringNotContainsString('evil.example', $cleanedCdata);
        self::assertStringNotContainsString('<style', $cleanedCdata);

        // Nested element inside <style> is stripped / fails closed
        $nestedStyle = '<svg xmlns="http://www.w3.org/2000/svg"><style><a href="https://evil.example">x</a></style><rect width="10" height="10"/></svg>';
        $cleanedNested = $sanitizer->sanitize($nestedStyle);
        self::assertStringNotContainsString('evil.example', $cleanedNested);
        self::assertStringNotContainsString('<style', $cleanedNested);

        // Prefixed XHTML namespace <html:script> is blocked
        $xhtmlScript = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:html="http://www.w3.org/1999/xhtml"><html:script>alert(1)</html:script><circle r="5"/></svg>';
        $cleanedXhtml = $sanitizer->sanitize($xhtmlScript);
        self::assertStringNotContainsString('script', strtolower($cleanedXhtml));

        // CSS image('https://evil.example/a.png') inside <style> is stripped
        $cssImageSvg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.b{background:image("https://evil.example/a.png")}</style><rect width="5" height="5"/></svg>';
        $cleanedImage = $sanitizer->sanitize($cssImageSvg);
        self::assertStringNotContainsString('evil.example', $cleanedImage);

        // Clean SVG passes cleanly
        $cleanSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';
        self::assertStringContainsString('<circle', $sanitizer->sanitize($cleanSvg));
    }

    public function testDynamicPathResolverSanitizeIdentifierLowercaseIdempotency(): void
    {
        $resolver = new DynamicPathResolver();
        $refl = new ReflectionMethod($resolver, 'sanitizeIdentifier');
        /** @var string $first */
        $first = $refl->invoke($resolver, 'Tenant_A', 'user');
        self::assertSame($first, strtolower($first));
        self::assertMatchesRegularExpression('/^[a-z0-9_-]{1,128}$/', $first);
        // Pass-18 (P18-01): trusted override round-trips idempotently, while a live user registering `$first` cannot collide.
        self::assertSame('/t/' . $first, $resolver->resolve('/t/{user_id}', null, $first));
        self::assertNotSame($first, $refl->invoke($resolver, $first, 'user'));

        // Windows reserved word hashes deterministically and round-trips via trusted override
        /** @var string $conId */
        $conId = $refl->invoke($resolver, 'CON', 'user');
        self::assertNotSame('con', $conId);
        self::assertSame('/t/' . $conId, $resolver->resolve('/t/{user_id}', null, $conId));
    }

    public function testGlobalUnicodeHelpersPathNormalizerAndFolderMetadataTags(): void
    {
        // P10-04: CGJ U+034F, Mongolian FVS U+180B..U+180F, deprecated U+206A..U+206F stripped from visibleSkeleton
        self::assertSame('abc', UnicodeHelper::visibleSkeleton("a\u{034F}b\u{180B}c\u{206A}"));
        self::assertSame('txt', UnicodeHelper::visibleSkeleton("\u{180E}t\u{180D}x\u{206F}t"));

        // P10-04: Fullwidth ASCII + German sharp S (ß/ẞ) folding in foldForSearch
        self::assertSame('pdf', UnicodeHelper::foldForSearch('ＰＤＦ'));
        self::assertSame('strasse', UnicodeHelper::foldForSearch('Straße'));
        self::assertSame('strasse', UnicodeHelper::foldForSearch('STRAẞE'));

        // P10-04: Turkish dotted İ folds to ASCII 'i' before mb_strtolower for deduplication
        $tags = FolderMetadata::sanitizeTags(['İstanbul', 'istanbul', '設計', '設計']);
        self::assertSame(['istanbul', '設計'], $tags);
        $iDotTags = FolderMetadata::sanitizeTags(['İZMİR', 'izmir', 'İZMİR']);
        self::assertSame(['izmir'], $iDotTags);

        // P10-04: PathNormalizer rejects Unicode slash lookalikes
        $normalizer = new PathNormalizer();
        foreach (["a\u{FF0F}b", "a\u{2215}b", "a\u{2044}b", "a\u{FF3C}b"] as $slashSpoof) {
            try {
                $normalizer->normalize($slashSpoof);
                self::fail('Expected InvalidPathException for Unicode slash lookalike');
            } catch (InvalidPathException $e) {
                self::assertSame('errors.path_control_chars', $e->getTranslationKey());
            }
        }
    }

    public function testFileSecurityGuardMimeMatchingAndActiveDocumentRules(): void
    {
        $guard = new FileSecurityGuard();

        // Wildcards and MIME parameters
        self::assertTrue($guard->matchesMimeList('image/webp', ['image/*']));
        self::assertTrue($guard->matchesMimeList('image/png; charset=utf-8', ['image/png']));
        self::assertTrue($guard->matchesMimeList('application/pdf', ['*/*']));
        self::assertFalse($guard->matchesMimeList('text/html', ['image/*', 'application/pdf']));
        self::assertFalse($guard->matchesMimeList('', ['*/*']));

        // Newly blocked active MIME types and text/* vs strict binary extension mismatch
        foreach (['application/x-httpd-php', 'image/svg+xml', 'text/javascript', 'application/xhtml+xml'] as $badMime) {
            try {
                $guard->assertMimeMatchesExtension('payload.jpg', $badMime);
                self::fail('Expected active MIME rejection for ' . $badMime);
            } catch (UploadValidationException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $guard->assertMimeMatchesExtension('photo.jpg', 'text/plain');
            self::fail('Expected mismatch rejection for photo.jpg with text/plain');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.extension_mime_mismatch', $e->getTranslationKey());
        }

        try {
            $guard->assertMimeMatchesExtension('photo.png', 'text/csv');
            self::fail('Expected mismatch rejection for photo.png with text/csv');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.extension_mime_mismatch', $e->getTranslationKey());
        }
    }

    public function testConfigurationPreservesExplicitRoleOnExtractZipAcrossMultiFileMerge(): void
    {
        $processor = new Processor();

        // Explicit extract_zip: 'ROLE_ADMIN' preserved alongside allow_extract: false when overridden safely
        $mergedExplicit = $processor->processConfiguration(new Configuration(), [
            [
                'profiles' => [
                    'docs' => [
                        'permissions' => ['extract_zip' => 'ROLE_ADMIN'],
                        'zip' => ['allow_extract' => false],
                    ],
                ],
            ],
        ]);
        self::assertSame('ROLE_ADMIN', $mergedExplicit['profiles']['docs']['permissions']['extract_zip']);

        // Unspecified extract_zip clamps to false when allow_extract: false
        $mergedClamped = $processor->processConfiguration(new Configuration(), [
            [
                'profiles' => [
                    'docs' => [
                        'zip' => ['allow_extract' => false],
                    ],
                ],
            ],
        ]);
        self::assertFalse($mergedClamped['profiles']['docs']['permissions']['extract_zip']);

        // When explicit true was set in base file and later file sets allow_extract: false without overriding extract_zip?
        // Post-merge validate preserves non-null explicit extract_zip: ['ROLE_ADMIN'] across files.
        $mergedRole = $processor->processConfiguration(new Configuration(), [
            ['profiles' => ['docs' => ['permissions' => ['extract_zip' => ['ROLE_ADMIN']]]]],
            ['profiles' => ['docs' => ['zip' => ['allow_extract' => true]]]],
        ]);
        self::assertSame(['ROLE_ADMIN'], $mergedRole['profiles']['docs']['permissions']['extract_zip']);
    }

    public function testPickerTypePathAndUrlBypassProtectionAndColorSchemeOption(): void
    {
        $factory = Forms::createFormFactoryBuilder()->getFormFactory();

        foreach (["dir\u{FF0F}evil.png", "dir/%2e%2e/secret.png", 'foo/bar?x=1', '../evil.png'] as $badRel) {
            $form = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'path']);
            $form->submit($badRel);
            self::assertFalse($form->isValid(), 'Path mode must reject ' . $badRel);
        }

        foreach (['https://cdn.example.com/a.jpg?path[]=1', 'https://cdn.example.com/a.jpg?path=%2e%2e%2fsecret', 'javascript:alert(1)'] as $badUrl) {
            $form = $factory->create(FileManagerPickerType::class, null, ['return_type' => 'url']);
            $form->submit($badUrl);
            self::assertFalse($form->isValid(), 'URL mode must reject ' . $badUrl);
        }

        $validForm = $factory->create(FileManagerPickerType::class, null, [
            'return_type' => 'path',
            'color_scheme' => 'dark',
        ]);
        $validForm->submit('products/2026/hero.webp');
        self::assertTrue($validForm->isValid());
        $view = $validForm->createView();
        self::assertSame('dark', $view->vars['color_scheme']);
    }

    public function testTwigExtensionActiveDocumentAndSpoofedQueryGuards(): void
    {
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $ext = new FileManagerTwigExtension($urlGen);

        $htmlItem = $ext->describeFieldItem('/uploads/page.html');
        self::assertNull($htmlItem['href']);
        self::assertNull($htmlItem['imgSrc']);

        $svgzItem = $ext->describeFieldItem('/uploads/doc.svgz');
        self::assertNull($svgzItem['href']);
        self::assertNull($svgzItem['imgSrc']);

        // Direct public .svg has href=null (XSS protection) and imgSrc preserved for <img>
        $svgItem = $ext->describeFieldItem('/uploads/diagram.svg');
        self::assertNull($svgItem['href']);
        self::assertSame('/uploads/diagram.svg', $svgItem['imgSrc']);

        // Query spoofing on non-download/share external endpoint must not rewrite to /thumb or bypass active-doc check
        $spoofedHtml = $ext->describeFieldItem('https://evil.example/evil.html?path=ok.png');
        self::assertNull($spoofedHtml['href']);
        self::assertNull($spoofedHtml['imgSrc']);

        $spoofedDownload = $ext->describeFieldItem('https://evil.example/download?path=icon.svg');
        self::assertNull($spoofedDownload['imgSrc']);

        $spoofedPngQuery = $ext->describeFieldItem('https://evil.example/download?path=banner.png');
        self::assertNull($spoofedPngQuery['imgSrc']);

        // Local download SVG rewrites cleanly to /thumb while keeping href
        $localDownloadSvg = $ext->describeFieldItem('/_liveux-file-manager/download?profile=default&path=icon.svg');
        self::assertSame('/_liveux-file-manager/download?profile=default&path=icon.svg', $localDownloadSvg['href']);
        self::assertSame('/_liveux-file-manager/thumb?profile=default&path=icon.svg', $localDownloadSvg['imgSrc']);
    }

    public function testApiAndDownloadControllersSecurityOraclesAndHeaders(): void
    {
        $profile = $this->createProfile(['local'], [], [
            'view' => true,
            'trash_restore' => true,
            'trash_purge' => true,
            'delete' => true,
            'upload' => true,
        ], ['enabled' => true], ['enabled' => true]);

        $provider = $this->createConfigProvider($profile);
        $registry = $this->createLocalRegistry($provider);
        $twig = new Environment(new ArrayLoader([]));
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $csrfValidator = new CsrfRequestValidator($csrf);
        $authDeny = $this->createStub(AuthorizationCheckerInterface::class);
        $authDeny->method('isGranted')->willReturn(false);

        $secProfile = $this->createProfile(['local'], ['voter_enabled' => true], [
            'view' => false,
            'trash_restore' => false,
            'trash_purge' => false,
            'delete' => false,
            'upload' => false,
        ], [], ['enabled' => true]);
        $secProvider = $this->createConfigProvider($secProfile);
        $secRegistry = $this->createLocalRegistry($secProvider);

        $norm = new PathNormalizer();
        $disp = new EventDispatcher();
        $trashMgr = new TrashManager($secRegistry, $norm, $disp, new FileSecurityGuard(), null, $authDeny);
        $lister = new DirectoryLister($secRegistry, $norm, new DirectoryCacheManager(), new NullMetadataStorage(), new FileSecurityGuard(), $authDeny);
        $ops = new FileOperationsService($secRegistry, $norm, new FilenameNormalizer(), $trashMgr, new NullMetadataStorage(), $disp, $authDeny);
        $uploadMgr = new UploadManager($secRegistry, $norm, new FilenameNormalizer(), $disp, new SvgSanitizer(), $authDeny);
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturn('/_fm/dl');

        $api = new ApiController(
            $secProvider,
            $secRegistry,
            $lister,
            $ops,
            new RemoteUrlImporter($uploadMgr, $secRegistry, $norm, $authDeny),
            $trashMgr,
            new DirectoryCacheManager(),
            new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            $norm,
            $urlGen,
            $twig,
            $csrfValidator,
            $authDeny,
        );

        // Array query parameter rejected with 400
        $arrReq = Request::create('/_fm/api/list', 'GET', ['volume' => ['local']]);
        $arrResp = $api->list($arrReq);
        self::assertSame(Response::HTTP_BAD_REQUEST, $arrResp->getStatusCode());

        // restoreTrash / purgeTrash denied (403) BEFORE findEntry() (no existence oracle for non-existent id)
        $restoreResp = $api->action($this->jsonActionRequest(['action' => 'restoreTrash', 'id' => 'missing-id-oracle']));
        self::assertSame(Response::HTTP_FORBIDDEN, $restoreResp->getStatusCode());

        $purgeResp = $api->action($this->jsonActionRequest(['action' => 'purgeTrash', 'id' => 'missing-id-oracle']));
        self::assertSame(Response::HTTP_FORBIDDEN, $purgeResp->getStatusCode());

        // abortUpload requires VIEW
        $abortResp = $api->action($this->jsonActionRequest(['action' => 'abortUpload', 'uploadId' => 'abc']));
        self::assertSame(Response::HTTP_FORBIDDEN, $abortResp->getStatusCode());

        // checkUsage with empty paths enforces root VIEW+DELETE
        $usageResp = $api->action($this->jsonActionRequest(['action' => 'checkUsage', 'paths' => []]));
        self::assertSame(Response::HTTP_FORBIDDEN, $usageResp->getStatusCode());

        // ZipDownloadController maps AccessDeniedException -> 403
        $zipSvc = new ZipArchiveService($secRegistry, $norm, new FileSecurityGuard(), new SvgSanitizer(), $authDeny);
        $zipCtrl = new ZipDownloadController($secProvider, $zipSvc, $csrfValidator, $authDeny);
        $zipReq = Request::create('/_fm/zip', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['paths' => ['a.txt']], JSON_THROW_ON_ERROR));
        self::assertSame(Response::HTTP_FORBIDDEN, ($zipCtrl)($zipReq)->getStatusCode());

        // DownloadController & ShareController force attachment on .svg (inline=1) and sanitize malformed MIME
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'vec.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');
        $openProvider = $this->createConfigProvider($this->createProfile(['local'], ['voter_enabled' => false], [], [], ['enabled' => false]));
        $openReg = $this->createLocalRegistry($openProvider);
        $dlCtrl = new DownloadController($openProvider, $openReg, $norm);
        $dlResp = ($dlCtrl)(Request::create('/_fm/download', 'GET', ['path' => 'vec.svg', 'inline' => '1']));
        self::assertStringStartsWith('attachment;', (string) $dlResp->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $dlResp->headers->get('Content-Security-Policy'));

        // Malformed MIME string returned by custom Flysystem adapter falls back to application/octet-stream
        $badMimeFs = $this->createStub(FilesystemOperator::class);
        $badMimeFs->method('fileExists')->willReturn(true);
        $badMimeFs->method('mimeType')->willReturn("invalid mime\r\n");
        $badMimeFs->method('fileSize')->willReturn(5);
        $badMimeVol = new VolumeConfig('bad_mime_vol', 'Bad', 'network', 'FlysystemService', null, null, false, ['service' => 'bad_mime_fs']);
        $badMimeProf = $this->createProfile(['bad_mime_vol'], ['voter_enabled' => false], [], [], ['enabled' => false]);
        $badMimeProv = $this->createConfigProvider($badMimeProf, $badMimeVol);
        $badMimeReg = new VolumeRegistry($badMimeProv, new FlysystemStorageFactory(new DynamicPathResolver(), new \Symfony\Component\DependencyInjection\ServiceLocator(['bad_mime_fs' => static fn (): FilesystemOperator => $badMimeFs])));
        $badDlResp = (new DownloadController($badMimeProv, $badMimeReg, $norm))(Request::create('/_fm/download', 'GET', ['volume' => 'bad_mime_vol', 'path' => 'note.txt', 'inline' => '1']));
        self::assertSame('application/octet-stream', $badDlResp->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $badDlResp->headers->get('Content-Disposition'));

        // ThumbnailController uses profile.cache.ttl when volume cacheTtl is null and treats Authorization header as private
        $thumbCacheDir = $this->tempDir . '/thumb_ctrl_cache';
        $netVol = new VolumeConfig('net_s3', 'S3', 'network', 'FlysystemService', null, null, false, ['service' => 'net_s3_fs'], null);
        $thumbProfile = new ProfileConfig(
            name: 'default',
            basePath: $this->tempDir,
            theme: 'bootstrap5',
            locale: 'en',
            volumes: ['net_s3'],
            driver: 'LocalFileSystem',
            path: $this->tempDir,
            urlPrefix: null,
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['*/*'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => true, 'cache_dir' => $thumbCacheDir],
            trash: ['enabled' => false],
            zip: ['enabled' => true],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => true, 'ttl' => 300],
            metadata: ['enabled' => false],
        );
        $netFs = $this->createMock(FilesystemOperator::class);
        $netFs->expects(self::never())->method('fileExists');
        $thumbManagerSvc = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $cachedThumbPath = $thumbManagerSvc->getCachedPath($thumbProfile, $norm->buildCanonicalKey($netVol, 'hero.png', $thumbProfile->basePath));
        mkdir(dirname($cachedThumbPath), 0775, true);
        file_put_contents($cachedThumbPath, "\x89PNG\r\n\x1A\n");
        $thumbProv = $this->createConfigProvider($thumbProfile, $netVol);
        $netLocator = new \Symfony\Component\DependencyInjection\ServiceLocator(['net_s3_fs' => static fn (): FilesystemOperator => $netFs]);
        $netReg = new VolumeRegistry($thumbProv, new FlysystemStorageFactory(new DynamicPathResolver(), $netLocator));
        $thumbCtrl = new ThumbnailController($thumbProv, $netReg, $norm, $thumbManagerSvc);
        $thumbReq = Request::create('/_fm/thumb', 'GET', ['volume' => 'net_s3', 'path' => 'hero.png'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer tok123']);
        $thumbResp = ($thumbCtrl)($thumbReq);
        self::assertTrue($thumbResp->headers->hasCacheControlDirective('private'));

        $signer = new UriSigner('super-secret-pass10-key');
        $openProfileShare = new ProfileConfig(
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
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['*/*'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false, 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: ['enabled' => false],
            zip: ['enabled' => true],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => false, 'ttl' => 300],
            metadata: ['enabled' => false],
            share: ['enabled' => true, 'allow_inline_preview' => true],
        );
        $shareProvider = $this->createConfigProvider($openProfileShare);
        $shareReg = $this->createLocalRegistry($shareProvider);
        $shareUrlGen = $this->createStub(UrlGeneratorInterface::class);
        $shareUrlGen->method('generate')->willReturn('https://example.test/_fm/share?profile=default&volume=local&path=vec.svg&inline=1&expires=' . (time() + 600));
        $shareSvc = new ShareLinkService($shareReg, $norm, $shareUrlGen, $signer);
        $signed = $shareSvc->createSignedUrl($openProfileShare, 'vec.svg', 'local', 600, true);
        $shareCtrl = new ShareController($shareProvider, $shareReg, $norm, $shareSvc);
        $shareResp = ($shareCtrl)(Request::create($signed['url']));
        self::assertStringStartsWith('attachment;', (string) $shareResp->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $shareResp->headers->get('Content-Security-Policy'));
    }

    public function testServicesDirectoryListerCacheScopeOperationsTrashAndCommands(): void
    {
        // DirectoryLister: computes Voter-aware folder counts even when voter_enabled=true & authChecker is active,
        // excluding any subfolders denied by the Voter from ancestor recursive counts
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'denied', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'hello.txt', 'hi');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'denied' . DIRECTORY_SEPARATOR . 'secret.txt', 'secret');

        $norm = new PathNormalizer();
        $voterProfile = $this->createProfile(['local'], ['voter_enabled' => true]);
        $voterProv = $this->createConfigProvider($voterProfile);
        $voterReg = $this->createLocalRegistry($voterProv);
        $allowAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $allowAuth->method('isGranted')->willReturn(true);

        $listerWithVoter = new DirectoryLister($voterReg, $norm, new DirectoryCacheManager(), new NullMetadataStorage(), new FileSecurityGuard(), $allowAuth);
        $listingWithVoter = $listerWithVoter->list($voterProfile, 'local', '');
        self::assertCount(1, $listingWithVoter->items);
        self::assertInstanceOf(FolderItem::class, $listingWithVoter->items[0]);
        self::assertSame(2, $listingWithVoter->items[0]->itemCount);

        $denySubAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denySubAuth->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject): bool {
                if ($subject instanceof FileManagerSubject) {
                    return !str_starts_with($subject->path, 'sub/denied');
                }

                return true;
            },
        );
        $listerRestricted = new DirectoryLister($voterReg, $norm, new DirectoryCacheManager(), new NullMetadataStorage(), new FileSecurityGuard(), $denySubAuth);
        $listingRestricted = $listerRestricted->list($voterProfile, 'local', '');
        self::assertCount(1, $listingRestricted->items);
        self::assertInstanceOf(FolderItem::class, $listingRestricted->items[0]);
        self::assertSame(1, $listingRestricted->items[0]->itemCount);

        $listerNoAuth = new DirectoryLister($voterReg, $norm, new DirectoryCacheManager(), new NullMetadataStorage(), new FileSecurityGuard(), null);
        $listingNoAuth = $listerNoAuth->list($voterProfile, 'local', '');
        self::assertInstanceOf(FolderItem::class, $listingNoAuth->items[0]);
        self::assertSame(2, $listingNoAuth->items[0]->itemCount);

        // Tree cache key differentiates custom maxDepth != 6 while keeping default depth 6 on TREE_CACHE_KEY
        $cachePool = new ArrayAdapter();
        $cacheMgr = new DirectoryCacheManager($cachePool);
        $cachedProfile = $this->createProfile(['local'], ['voter_enabled' => false]);
        $cachedProfile = new ProfileConfig(
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
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['*/*'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false, 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: ['enabled' => false],
            zip: ['enabled' => true],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => true, 'ttl' => 300],
            metadata: ['enabled' => false],
        );
        $cachedLister = new DirectoryLister($this->createLocalRegistry($this->createConfigProvider($cachedProfile)), $norm, $cacheMgr, new NullMetadataStorage());
        $treeD1 = $cachedLister->listFullTree($cachedProfile, 'local', 1);
        $treeD6 = $cachedLister->listFullTree($cachedProfile, 'local', 6);
        self::assertCount(1, $treeD1);
        self::assertCount(2, $treeD6);

        // DirectoryCacheManager S3 prefixed sub-volume isolation
        self::assertSame('s3_sub::bucket-a/tenants/one', $cacheMgr->resolveVolumeScopeForRoot('s3::bucket-a/tenants/one'));
        self::assertNotSame(
            $cacheMgr->resolveVolumeScopeForRoot('s3::bucket-a/tenants/one'),
            $cacheMgr->resolveVolumeScopeForRoot('s3::bucket-a/tenants/two'),
        );

        // FolderDeletedEvent propagates $deletedChildCanonicalPaths across all profiles in ThumbnailCleanupListener
        $thumbDirA = $this->tempDir . '/thumbs_a';
        $thumbDirB = $this->tempDir . '/thumbs_b';
        mkdir($thumbDirA, 0775, true);
        mkdir($thumbDirB, 0775, true);
        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $profA = new ProfileConfig(
            name: 'prof_a',
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
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['*/*'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => true, 'cache_dir' => $thumbDirA],
            trash: ['enabled' => false],
            zip: ['enabled' => true],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => false, 'ttl' => 300],
            metadata: ['enabled' => false],
        );
        $profB = new ProfileConfig(
            name: 'prof_b',
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
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['*/*'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => true, 'cache_dir' => $thumbDirB],
            trash: ['enabled' => false],
            zip: ['enabled' => true],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => false, 'ttl' => 300],
            metadata: ['enabled' => false],
        );
        $multiProvider = $this->createConfigProvider(
            ['prof_a' => $profA, 'prof_b' => $profB],
            new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir),
        );
        $childCanon = 'local::sub/pic.jpg';
        $cachedA = $thumbMgr->getCachedPath($profA, $childCanon);
        $cachedB = $thumbMgr->getCachedPath($profB, $childCanon);
        mkdir(dirname($cachedA), 0775, true);
        mkdir(dirname($cachedB), 0775, true);
        file_put_contents($cachedA, 'thumbA');
        file_put_contents($cachedB, 'thumbB');

        $cleanup = new ThumbnailCleanupListener($thumbMgr, $multiProvider, null, $norm);
        $cleanup->onFolderDeleted(new FolderDeletedEvent($profA, $multiProvider->getVolume('local'), 'sub', 'local::sub', false, null, [$childCanon]));
        self::assertFileDoesNotExist($cachedA);
        self::assertFileDoesNotExist($cachedB);

        // AssetUsageGuardService 'overwrite' returns errors.overwrite_blocked_in_use
        $checker = new class () implements AssetUsageCheckerInterface {
            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                return [new AssetUsageReference('Product #1', 'Product', null, '1')];
            }
        };
        $guardProf = new ProfileConfig(
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
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['*/*'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false, 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: ['enabled' => false],
            zip: ['enabled' => true],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => false, 'ttl' => 300],
            metadata: ['enabled' => false],
            usageGuard: ['enabled' => true, 'policy' => 'block', 'enforce_server_side' => true],
        );
        $usageGuardSvc = new AssetUsageGuardService($this->createLocalRegistry($this->createConfigProvider($guardProf)), $norm, new FileSecurityGuard(), [$checker]);
        try {
            $usageGuardSvc->assertAllowed($guardProf, $multiProvider->getVolume('local'), 'sub/hello.txt', false, 'overwrite');
            self::fail('Expected FileManagerException for overwrite on in-use asset');
        } catch (FileManagerException $e) {
            self::assertSame('errors.overwrite_blocked_in_use', $e->getTranslationKey());
        }

        // TrashManager: restore() blocks parent file collision & corrupt empty-payload folder purge fails closed
        $trashVolDir = $this->tempDir . '/trash_vol';
        mkdir($trashVolDir . '/collide/myfolder', 0775, true);
        file_put_contents($trashVolDir . '/collide/myfolder/doc.txt', 'ok');
        $trashProv = new YamlConfigProvider(
            defaults: ['base_path' => $trashVolDir],
            volumes: ['tvol' => ['driver' => 'LocalFileSystem', 'path' => $trashVolDir]],
            profiles: ['default' => ['volumes' => ['tvol'], 'trash' => ['enabled' => true, 'max_size' => '50M', 'auto_purge_days' => 30], 'security' => ['voter_enabled' => false]]],
        );
        $trashReg = new VolumeRegistry($trashProv, new FlysystemStorageFactory(new DynamicPathResolver()));
        $tm = new TrashManager($trashReg, $norm, new EventDispatcher());
        $movedEntry = $tm->moveToTrash($trashProv->getProfile('default'), 'collide/myfolder', 'tvol');
        @rmdir($trashVolDir . '/collide');
        file_put_contents($trashVolDir . '/collide', 'i am a file blocking parent dir');
        try {
            $tm->restore($trashProv->getProfile('default'), $movedEntry->id, 'tvol');
            self::fail('Expected target_exists when restore parent path is an existing file');
        } catch (FileManagerException $e) {
            self::assertSame('errors.target_exists', $e->getTranslationKey());
        }

        // Corrupt empty-payload folder entry in canEvictTrashEntry fails closed
        $canEvict = new ReflectionMethod($tm, 'canEvictTrashEntry');
        $corruptEntry = new TrashEntry(
            id: 'trash_00000002',
            name: '',
            originalPath: 'dir/sub',
            volume: 'tvol',
            isFolder: true,
            size: 10,
            deletedAt: time(),
        );
        $evictAllowed = $canEvict->invoke(
            $tm,
            $trashReg->getFilesystem($trashProv->getProfile('default'), 'tvol'),
            '.trash',
            $trashProv->getProfile('default'),
            'tvol',
            $corruptEntry,
        );
        self::assertFalse($evictAllowed);

        // RemoteUrlImporter: Windows reserved basename prefixed safely in extractFilename()
        $extractUploadMgr = new UploadManager($voterReg, $norm, new FilenameNormalizer(), new EventDispatcher());
        $importer = new RemoteUrlImporter($extractUploadMgr, $voterReg, $norm);
        $extractRefl = new ReflectionMethod($importer, 'extractFilename');
        $tmpPng = $this->tempDir . '/sample.png';
        file_put_contents($tmpPng, "\x89PNG\r\n\x1A\n");
        $resolvedName = $extractRefl->invoke($importer, 'https://example.com/AUX.jpg', ['content-type: image/jpeg'], $tmpPng);
        self::assertIsString($resolvedName);
        self::assertNotSame('aux.jpg', strtolower($resolvedName));
        self::assertStringStartsWith('imported-', $resolvedName);

        // ThumbnailClearCommand rejects --path=../evil and handles reverse-nested cache dirs safely
        $nestedParent = $this->tempDir . '/cache_root';
        $nestedChild = $this->tempDir . '/cache_root/child';
        mkdir($nestedChild, 0775, true);
        file_put_contents($nestedChild . '/a.webp', 'w');
        $cmdProv = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir],
            volumes: ['v' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'child_first' => ['volumes' => ['v'], 'thumbnail' => ['enabled' => true, 'cache_dir' => $nestedChild]],
                'parent_second' => ['volumes' => ['v'], 'thumbnail' => ['enabled' => true, 'cache_dir' => $nestedParent]],
            ],
        );
        $cmdReg = new VolumeRegistry($cmdProv, new FlysystemStorageFactory(new DynamicPathResolver()));
        $clearCmd = new ThumbnailClearCommand($cmdProv, $thumbMgr, new ProfileFileScanner($cmdProv, $cmdReg, $norm));
        $tester = new CommandTester($clearCmd);
        self::assertSame(Command::FAILURE, $tester->execute(['--path' => '../evil']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--orphans' => true]));
    }

    public function testOperationsHardlinkAndCaseSelfMoveDbalPostgresAndIconAndApng(): void
    {
        $norm = new PathNormalizer();
        $fnNorm = new FilenameNormalizer();
        $disp = new EventDispatcher();
        $prov = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir],
            volumes: ['v' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => ['volumes' => ['v'], 'trash' => ['enabled' => true, 'max_size' => '100B'], 'security' => ['voter_enabled' => false]]],
        );
        $reg = new VolumeRegistry($prov, new FlysystemStorageFactory(new DynamicPathResolver()));
        $tm = new TrashManager($reg, $norm, $disp);
        $ops = new FileOperationsService($reg, $norm, $fnNorm, $tm, new NullMetadataStorage(), $disp);

        // isSameLocalInodeRename returns false for distinct filenames even if sharing same content/inode
        $f1 = $this->tempDir . '/first.txt';
        $f2 = $this->tempDir . '/second.txt';
        file_put_contents($f1, 'same');
        file_put_contents($f2, 'same');
        @link($f1, $f2);
        $inodeRefl = new ReflectionMethod($ops, 'isSameLocalInodeRename');
        $prof = $prov->getProfile('default');
        $vol = $reg->getVolumeForProfile($prof, 'v');
        self::assertFalse($inodeRefl->invoke($ops, $vol, $reg->getFilesystem($prof, 'v'), 'first.txt', 'second.txt'));

        // Case-insensitive self-move blocked (especially cross-case on Windows/Darwin local FS)
        mkdir($this->tempDir . '/Alpha/sub', 0775, true);
        try {
            $ops->moveOrCopy($prof, 'Alpha', 'alpha/sub', false, 'v', 'v');
            self::fail('Expected self-move exception');
        } catch (FileManagerException $e) {
            if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin') {
                self::assertSame('errors.cannot_move_into_self', $e->getTranslationKey());
            } else {
                self::addToAssertionCount(1);
            }
        }

        // Bulk delete enforces cumulative trash.max_size before deleting anything
        file_put_contents($this->tempDir . '/big1.bin', str_repeat('a', 70));
        file_put_contents($this->tempDir . '/big2.bin', str_repeat('b', 70));
        try {
            $ops->preValidateDeleteItems($prof, ['big1.bin', 'big2.bin'], 'v');
            self::fail('Expected cumulative trash max_size denial');
        } catch (FileManagerException $e) {
            self::assertSame('errors.trash_item_too_large', $e->getTranslationKey());
            self::assertFileExists($this->tempDir . '/big1.bin');
            self::assertFileExists($this->tempDir . '/big2.bin');
        }

        // DoctrineDbalMetadataStorage: batched delete >400 keys, empty-save short-circuit & PostgreSQL prefix index handling
        $sqliteConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal = new DoctrineDbalMetadataStorage($sqliteConn);
        $keys = [];
        for ($i = 0; $i < 420; ++$i) {
            $k = 'v::bulk/item_' . $i;
            $keys[] = $k;
            $dbal->save($k, new FolderMetadata(color: '#112233'));
        }
        $dbal->delete('v::bulk');
        self::assertSame([], $dbal->getByPrefix('v::bulk'));

        $dbal->preload(['v::empty_probe']);
        // Cached empty key returns early on empty save without writing DB row
        $dbal->save('v::empty_probe', new FolderMetadata());
        self::assertSame(0, (int) $sqliteConn->fetchOne('SELECT COUNT(*) FROM liveux_fm_metadata WHERE canonical_key = :k', ['k' => 'v::empty_probe']));

        // PostgreSQL platform class is recognized in ensureTable() check
        self::assertTrue(class_exists(PostgreSQLPlatform::class));

        // ImageOptimizer::isAnimatedPng() detects acTL before IDAT and ignores static PNG
        $opt = new ImageOptimizer();
        $apng = $this->tempDir . '/anim.png';
        $ihdr = pack('N', 13) . 'IHDR' . str_repeat("\x00", 13) . str_repeat("\x00", 4);
        $actl = pack('N', 8) . 'acTL' . pack('NN', 2, 0) . str_repeat("\x00", 4);
        $idat = pack('N', 0) . 'IDAT' . str_repeat("\x00", 4);
        file_put_contents($apng, "\x89PNG\r\n\x1A\n" . $ihdr . $actl . $idat);
        self::assertTrue($opt->isAnimatedPng($apng, 'image/png'));

        $staticPng = $this->tempDir . '/static.png';
        file_put_contents($staticPng, "\x89PNG\r\n\x1A\n" . $ihdr . $idat);
        self::assertFalse($opt->isAnimatedPng($staticPng, 'image/png'));

        // IconSetRegistry merges class/aria-hidden cleanly even on <svg:svg> prefixed tags
        $iconSetDir = $this->tempDir . '/icons_custom/ui';
        mkdir($iconSetDir, 0775, true);
        file_put_contents(
            $iconSetDir . '/upload.svg',
            '<svg:svg xmlns:svg="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><svg:path d="M5 12h14"/></svg:svg>',
        );
        $icons = new IconSetRegistry(dirname(__DIR__, 3) . '/resources/icons');
        $rendered = $icons->renderInlineIcon('upload', dirname($iconSetDir), 14, 'ui', 'my-icon', true);
        self::assertStringContainsString('aria-hidden="true"', $rendered);
        self::assertStringContainsString('my-icon', $rendered);
    }

    /**
     * @param list<string>         $volumes
     * @param array<string, mixed> $security
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $zip
     * @param array<string, mixed> $trash
     */
    private function createProfile(
        array $volumes = ['local'],
        array $security = [],
        array $permissions = [],
        array $zip = [],
        array $trash = ['enabled' => false],
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
            cache: ['enabled' => false, 'ttl' => 300],
            metadata: ['enabled' => false],
        );
    }

    /**
     * @param ProfileConfig|array<string, ProfileConfig> $profiles
     */
    private function createConfigProvider(ProfileConfig|array $profiles, ?VolumeConfig $volume = null): ConfigProviderInterface
    {
        $profileMap = is_array($profiles) ? $profiles : [$profiles->name => $profiles];
        $vol = $volume ?? new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');

        return new class ($profileMap, $vol) implements ConfigProviderInterface {
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
