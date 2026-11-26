<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Bridge\SymfonyAI\SymfonyAiAssetEnricher;
use LiveUX\FileManagerBundle\Command\InstallSkillsCommand;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\EventListener\AuditLogListener;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\EventListener\CacheInvalidationListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\FileManagerBundle;
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
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\StorageFactoryInterface;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\TraceableAccessDecisionManager;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Pass-14 verification & regression suite:
 * - Compiled Symfony ContainerBuilder + PhpDumper syntax verification for DetectExternalVotersPass
 * - Post-DecoratorServicePass innerServiceId traversal (Traceable* wrapping custom checker)
 * - PostgreSQL UTF-8 mb_substr prefix predicate & text_pattern_ops index
 * - Anchored SVG root sniffing (.md/.txt allowed, <s:svg> & long comment blocked)
 * - ThumbnailController pre-read resolveDriver() 503 & cache.enabled=false headers
 * - SvgSanitizer cross-fade()/data: CSS, newline string rule & element-scoped href
 * - ApiController private no-store JSON headers on early errors
 * - Configuration defaults.locale = null dynamic auto-detection
 * - HPP duplicate path= / %70ath= / fullwidth slash / profile URL exclusion guards
 * - AutoInjectDialogHostSubscriber raw-text (<textarea>) & public-cache permission guards
 * - AuditLogListener BiDi / invalid UTF-8 / $extra log injection sanitization
 * - FileSecurityGuard HFS+ zero-width exclude_patterns protection
 * - CacheInvalidationListener inline-volume & @ext_ non-overlap isolation
 * - ShareController allow_pdf_preview, Range 206, and non-mkdir missing dir
 * - DirectoryLister truncated cloud tree no-I/O-storm & null count propagation
 * - DirectoryCacheManager generation marker TTL longevity
 * - TrashManager sibling fallback file isolation from custom metadata.file
 * - StorageQuotaChecker capped fail-closed & ancestor voter filtering
 * - FileOperationsService createFolder ancestor voter & copyDirectory ancestor/cross-volume guards
 * - JsonFileMetadataStorage corrupt JSON fail-closed
 * - FilenameNormalizer CJK fullwidth slash & long extension 255-byte truncation
 * - InstallSkillsCommand --dir=. rejection & deep symlink fail-closed
 * - AssetUsageGuardService hostless https:foo rejection & SymfonyAiAssetEnricher multilingual refusals
 */
final class FourteenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_fourteenth_' . bin2hex(random_bytes(4));
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

    public function testCompiledContainerPhpDumperProducesValidPhpForDirectoryListerAndDetectsWrappedCustomChecker(): void
    {
        // Case 1: Full Symfony compile() + PhpDumper::dump() WITH SecurityBundle-like autowired auth checker
        $container = new ContainerBuilder();
        $container->register(VolumeRegistry::class, VolumeRegistry::class)->setSynthetic(true);
        $container->register(PathNormalizer::class, PathNormalizer::class);
        $container->register(DirectoryCacheManager::class, DirectoryCacheManager::class);
        $container->register(\LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface::class, NullMetadataStorage::class);
        $container->register(FileSecurityGuard::class, FileSecurityGuard::class);
        $container->register('security.authorization_checker', AuthorizationChecker::class)->setSynthetic(true);
        $container->setAlias(AuthorizationCheckerInterface::class, 'security.authorization_checker');
        $container->register('app.core_voter', FileManagerVoter::class)->addTag('security.voter');

        $container->register(DirectoryLister::class, DirectoryLister::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->setArgument('$hasExternalVoters', true);

        $container->addCompilerPass(new DetectExternalVotersPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        $dumped = (new PhpDumper($container))->dump(['class' => 'Pass14CompiledContainerA', 'as_files' => false]);
        self::assertIsString($dumped);
        self::assertStringNotContainsString('$hasExternalVoters:', $dumped, 'PhpDumper MUST NEVER emit invalid $-prefixed named argument syntax.');

        $dumpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'compiled_container_a.php';
        file_put_contents($dumpFile, $dumped);
        $lintOutput = [];
        $lintExit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($dumpFile) . ' 2>&1', $lintOutput, $lintExit);
        self::assertSame(0, $lintExit, 'Compiled container PHP must pass php -l: ' . implode("\n", $lintOutput));

        // Case 2: Post-DecoratorServicePass chain where a core Symfony decorator wraps a custom checker
        $wrappedContainer = new ContainerBuilder();
        $wrappedContainer->register(VolumeRegistry::class, VolumeRegistry::class)->setSynthetic(true);
        $wrappedContainer->register(PathNormalizer::class, PathNormalizer::class);
        $wrappedContainer->register(DirectoryCacheManager::class, DirectoryCacheManager::class);
        $wrappedContainer->register(\LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface::class, NullMetadataStorage::class);
        $wrappedContainer->register(FileSecurityGuard::class, FileSecurityGuard::class);
        $wrappedContainer->register('security.access.decision_manager', \stdClass::class);
        $wrappedContainer->register('debug.security.access.decision_manager', TraceableAccessDecisionManager::class)
            ->setDecoratedService('security.access.decision_manager')
            ->setSynthetic(true);
        $wrappedContainer->register(DirectoryLister::class, DirectoryLister::class)
            ->setAutowired(true)
            ->setPublic(true);
        $wrappedContainer->addCompilerPass(new DetectExternalVotersPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $wrappedContainer->compile();

        $args = $wrappedContainer->getDefinition(DirectoryLister::class)->getArguments();
        self::assertArrayNotHasKey('$hasExternalVoters', $args);
        self::assertTrue((bool) ($args[6] ?? $args['hasExternalVoters'] ?? false), 'Core decorator wrapping custom decision manager must set hasExternalVoters=true.');

        $dumped2 = (new PhpDumper($wrappedContainer))->dump(['class' => 'Pass14CompiledContainerB', 'as_files' => false]);
        self::assertIsString($dumped2);
        $dumpFile2 = $this->tempDir . DIRECTORY_SEPARATOR . 'compiled_container_b.php';
        file_put_contents($dumpFile2, $dumped2);
        $lintOut2 = [];
        $lintExit2 = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($dumpFile2) . ' 2>&1', $lintOut2, $lintExit2);
        self::assertSame(0, $lintExit2, 'No-SecurityBundle compiled container PHP must pass php -l: ' . implode("\n", $lintOut2));
    }

    public function testPostgresPrefixWhereUsesMbSubstrAndMultibyteCharCount(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pgConn = $this->createStub(\Doctrine\DBAL\Connection::class);
        $pgConn->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $storage = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_metadata', false);
        $refl = new ReflectionMethod(DoctrineDbalMetadataStorage::class, 'buildPrefixWhere');

        // 100 CJK chars (300 bytes > 191 bytes, but 100 chars < 191 chars) must use B-Tree rangeStart/rangeEnd with valid UTF-8!
        $cjkPrefix = 'local::' . str_repeat('文', 100);
        /** @var array{0: string, 1: array<string, string>} $result */
        $result = $refl->invoke($storage, $pgConn, $cjkPrefix);
        self::assertTrue(mb_check_encoding($result[1]['rangeStart'], 'UTF-8'), 'PostgreSQL rangeStart must be valid UTF-8.');
        self::assertSame($cjkPrefix . '/', $result[1]['rangeStart']);
        self::assertStringContainsString('~>=~ :rangeStart', $result[0]);

        // 200 CJK chars (>= 191 chars) must use B-Tree equality LEFT(canonical_key, 191) = :prefix191 without trailing '%'
        $longCjkPrefix = 'local::' . str_repeat('あ', 200);
        /** @var array{0: string, 1: array<string, string>} $longResult */
        $longResult = $refl->invoke($storage, $pgConn, $longCjkPrefix);
        self::assertTrue(mb_check_encoding($longResult[1]['prefix191'], 'UTF-8'));
        self::assertSame(191, mb_strlen($longResult[1]['prefix191'], 'UTF-8'));
        self::assertStringContainsString('LEFT(canonical_key, 191) = :prefix191', $longResult[0]);
        self::assertStringEndsNotWith('%', $longResult[1]['prefix191']);
    }

    public function testAnchoredSvgRootSniffingAllowsMarkdownProseAndBlocksNamespacedOrCommentedSvg(): void
    {
        $mdFile = $this->tempDir . DIRECTORY_SEPARATOR . 'guide.md';
        file_put_contents($mdFile, "# Icon Guide\nUse `<svg width=\"16\"></svg>` in your templates.\n");
        self::assertFalse(FileSecurityGuard::looksLikeSvgDocument($mdFile), 'Markdown prose mentioning <svg> must NOT be reclassified as image/svg+xml.');

        $nsSvg = $this->tempDir . DIRECTORY_SEPARATOR . 'ns.txt';
        file_put_contents($nsSvg, "\xEF\xBB\xBF<?xml version=\"1.0\"?><!-- " . str_repeat('x', 6000) . " --><s:svg xmlns:s=\"http://www.w3.org/2000/svg\"></s:svg>");
        self::assertTrue(FileSecurityGuard::looksLikeSvgDocument($nsSvg), 'Namespaced <s:svg> after >4KB comment and BOM must be detected as SVG.');
    }

    public function testThumbnailControllerCatchesMissingDriverExceptionAs503AndRespectsCacheDisabled(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pic.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));

        $profile = $this->makeProfile(
            thumbnail: ['enabled' => true, 'driver' => 'liip_imagine', 'on_missing_driver' => 'exception', 'cache_dir' => $this->tempDir . '/_thumbs'],
            cache: ['enabled' => false],
        );
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');
        $provider = $this->makeProvider($profile, [$vol]);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));

        $controller = new ThumbnailController(
            $provider,
            $registry,
            new PathNormalizer(),
            new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
        );

        $res = $controller(Request::create('/_liveux-file-manager/thumb?profile=default&volume=local&path=pic.png'));
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $res->getStatusCode(), 'on_missing_driver=exception must return sanitized 503 instead of uncaught 500.');
        self::assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        // Public SVG thumbnail with cache.enabled=false must NOT send immutable max-age=2592000
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
        $svgRes = $controller(Request::create('/_liveux-file-manager/thumb?profile=default&volume=local&path=icon.svg'));
        self::assertSame(Response::HTTP_OK, $svgRes->getStatusCode());
        self::assertStringNotContainsString('immutable', (string) $svgRes->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $svgRes->headers->get('Cache-Control'));
    }

    public function testSvgSanitizerBlocksCrossFadeDataCssAndAnchorDataHref(): void
    {
        $sanitizer = new SvgSanitizer();
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<style>rect { fill: cross-fade(50% "https://evil.example/track.png", red); }</style>'
            . '<a href="data:image/png;base64,iVBORw0KGgo="><rect width="10" height="10"/></a>'
            . '<image href="data:image/png;base64,iVBORw0KGgo=" width="10" height="10"/>'
            . '</svg>';
        $clean = $sanitizer->sanitize($dirty);
        self::assertStringNotContainsString('cross-fade', $clean);
        self::assertStringNotContainsString('<a href=', $clean);
        self::assertStringContainsString('<image href="data:image/png;base64,iVBORw0KGgo="', $clean);
    }

    public function testConfigurationDefaultsLocaleIsNullAndApiEarlyErrorsHavePrivateHeaders(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertNull($config['defaults']['locale'], 'defaults.locale must default to null so request/translator locale auto-detection works.');

        $profile = $this->makeProfile();
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $provider = $this->makeProvider($profile, [$vol]);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $lister = new DirectoryLister($registry, $normalizer, $cache, new NullMetadataStorage());
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, new NullMetadataStorage(), $dispatcher);
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher);
        $remoteImporter = new RemoteUrlImporter($uploadMgr, $registry, $normalizer);
        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $api = new ApiController(
            $provider,
            $registry,
            $lister,
            $ops,
            $remoteImporter,
            $trash,
            $cache,
            $thumbMgr,
            $normalizer,
            $this->createStub(UrlGeneratorInterface::class),
            new Environment(new ArrayLoader([])),
            new CsrfRequestValidator(null),
        );

        $badJson = $api->action(Request::create('/_liveux-file-manager/api/action', 'POST', [], [], [], [], '{broken'));
        self::assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());
        self::assertStringContainsString('no-store', (string) $badJson->headers->get('Cache-Control'));
        self::assertSame('nosniff', $badJson->headers->get('X-Content-Type-Options'));
    }

    public function testHppDuplicatePathEncodedKeyFullwidthSlashAndProfileUrlExclusions(): void
    {
        $ext = new FileManagerTwigExtension($this->createStub(UrlGeneratorInterface::class));

        // Duplicate path= HPP
        $hpp = $ext->describeFieldItem('/_liveux-file-manager/download?path=../../.env&path=safe.png');
        self::assertNull($hpp['href']);
        self::assertNull($hpp['imgSrc']);

        // Encoded parameter key %70ath=
        $encKey = $ext->describeFieldItem('/_liveux-file-manager/download?%70ath=../../.env');
        self::assertNull($encKey['href']);
        self::assertNull($encKey['imgSrc']);

        // Fullwidth slash in path
        $fwSlash = $ext->describeFieldItem("/uploads/a\u{FF0F}..%2f.env");
        self::assertNull($fwSlash['href']);

        // PickerType HPP + %70ath + profile custom trash exclusion on return_type='url'
        $reflProfile = new ReflectionMethod(FileManagerPickerType::class, 'isSafePickerValueForProfile');
        $customTrashProfile = $this->makeProfile(trash: ['enabled' => true, 'directory' => 'recycle_bin'], excludePatterns: ['/^_thumbs$/i']);

        self::assertFalse((bool) $reflProfile->invoke(null, '/_liveux-file-manager/download?path=../../.env&path=ok.png', 'url', $customTrashProfile));
        self::assertFalse((bool) $reflProfile->invoke(null, '/_liveux-file-manager/download?%70ath=../../.env', 'url', $customTrashProfile));
        self::assertFalse((bool) $reflProfile->invoke(null, '/uploads/recycle_bin/secret.png', 'url', $customTrashProfile));
        self::assertFalse((bool) $reflProfile->invoke(null, '/uploads/_thumbs/thumb.webp', 'url', $customTrashProfile));
        self::assertTrue((bool) $reflProfile->invoke(null, '/uploads/gallery/hero.webp', 'url', $customTrashProfile));
    }

    public function testDialogHostRawTextBypassAuditLogBidiAndZeroWidthExcludePatterns(): void
    {
        // AutoInjectDialogHostSubscriber ignores picker markup inside <textarea> and skips public responses when view=false
        $deniedProfile = $this->makeProfile(permissions: ['view' => false], security: ['voter_enabled' => false]);
        $provider = $this->makeProvider($deniedProfile, [new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir)]);
        $twig = new Environment(new ArrayLoader(['@LiveUXFileManager/components/DialogHost.html.twig' => '<div id="injected"></div>']));
        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $provider);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $html = '<html><body><textarea>data-controller="liveux--file-manager-bundle--picker"</textarea></body></html>';
        $res = new Response($html, 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $res));
        self::assertStringNotContainsString('id="injected"', (string) $res->getContent());

        // AuditLogListener sanitizes BiDi overrides, CRLF, invalid UTF-8, and $extra fields
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $log;

            public function __construct(array &$records)
            {
                $this->log = &$records;
            }

            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $audit = new AuditLogListener(true, 'info', $logger);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $audit->onItemMoved(new ItemMovedEvent(
            $this->makeProfile(),
            $vol,
            "bad\xFF\r\n\u{202E}path.txt",
            'local::bad',
            $vol,
            "target\n\u{202E}evil.txt",
            'local::target',
            false,
            false,
        ));
        self::assertCount(1, $records);
        self::assertStringNotContainsString("\r", (string) $records[0]['context']['path']);
        self::assertStringNotContainsString("\u{202E}", (string) $records[0]['context']['path']);
        self::assertStringNotContainsString("\n", (string) $records[0]['context']['targetPath']);
        self::assertStringNotContainsString("\u{202E}", (string) $records[0]['context']['targetPath']);

        // FileSecurityGuard blocks HFS+ zero-width filler bypass against exclude_patterns
        $guard = new FileSecurityGuard();
        $this->expectException(InvalidPathException::class);
        $guard->assertAccessiblePath("_th\u{200B}umbs/cache.jpg", $this->makeProfile(excludePatterns: ['/^_thumbs$/i']));
    }

    public function testShareControllerRespectsPdfPreviewRangeAndDoesNotCreateMissingDir(): void
    {
        $pdfPath = $this->tempDir . DIRECTORY_SEPARATOR . 'doc.pdf';
        file_put_contents($pdfPath, "%PDF-1.4\n0123456789\n%%EOF\n");

        $profile = $this->makeProfile(
            ui: ['allow_pdf_preview' => false],
            share: ['enabled' => true, 'allow_inline_preview' => true],
        );
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');
        $provider = $this->makeProvider($profile, [$vol]);
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $signer = new UriSigner('pass14-share-secret');
        $urlGen = new class() implements UrlGeneratorInterface {
            private \Symfony\Component\Routing\RequestContext $ctx;
            public function __construct() { $this->ctx = new \Symfony\Component\Routing\RequestContext(); }
            public function setContext(\Symfony\Component\Routing\RequestContext $context): void { $this->ctx = $context; }
            public function getContext(): \Symfony\Component\Routing\RequestContext { return $this->ctx; }
            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'https://example.test/_liveux-file-manager/share?' . http_build_query($parameters, '', '&');
            }
        };
        $shareService = new ShareLinkService($registry, $normalizer, $urlGen, $signer);
        $shareController = new ShareController($provider, $registry, $normalizer, $shareService);

        $signed = $shareService->createSignedUrl($profile, 'doc.pdf', 'local', 600, true);
        $req = Request::create($signed['url'], 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=0-3']);
        $res = $shareController($req);
        self::assertSame(Response::HTTP_PARTIAL_CONTENT, $res->getStatusCode());
        self::assertSame('bytes 0-3/' . filesize($pdfPath), $res->headers->get('Content-Range'));
        self::assertSame('4', $res->headers->get('Content-Length'));
        self::assertStringStartsWith('attachment;', (string) $res->headers->get('Content-Disposition'), 'allow_pdf_preview=false must force attachment on ShareController.');
    }

    public function testTrashFallbackIsolationJsonCorruptFailClosedAndQuotaAncestorGuards(): void
    {
        // 1. TrashManager derives distinct .trash-meta.json sibling for custom metadata.file
        $customMetaFile = $this->tempDir . DIRECTORY_SEPARATOR . 'fm-meta.json';
        $profile = $this->makeProfile();
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $provider = $this->makeProvider($profile, [$vol]);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $trash = new TrashManager($registry, new PathNormalizer(), new EventDispatcher(), localMetaFallbackFile: $customMetaFile);
        $reflFb = new ReflectionMethod(TrashManager::class, 'resolveLocalFallbackFile');
        $resolvedFb = (string) $reflFb->invoke($trash);
        self::assertNotSame($customMetaFile, $resolvedFb);
        self::assertStringEndsWith('fm-meta.trash-meta.json', $resolvedFb);

        // 2. JsonFileMetadataStorage fails closed instead of wiping corrupt metadata JSON
        file_put_contents($customMetaFile, '{corrupt-json');
        $jsonStore = new JsonFileMetadataStorage($customMetaFile);
        try {
            $jsonStore->save('local::a.jpg', new FolderMetadata(alt: 'Hero'));
            self::fail('Expected RuntimeException on corrupt existing metadata JSON.');
        } catch (\RuntimeException) {
            self::assertSame('{corrupt-json', file_get_contents($customMetaFile), 'Corrupt metadata file must not be overwritten.');
        }

        // 3. FileManagerAuthorizer::isPathAndAncestorsGranted denies descendant when parent folder is denied
        $authStub = $this->createStub(AuthorizationCheckerInterface::class);
        $authStub->method('isGranted')->willReturnCallback(
            static fn (mixed $attr, mixed $subj): bool => !($subj instanceof FileManagerSubject && $subj->path === 'projects/secret'),
        );
        $authorizer = new FileManagerAuthorizer($authStub);
        $voterProfile = $this->makeProfile(security: ['voter_enabled' => true]);
        self::assertFalse($authorizer->isPathAndAncestorsGranted($voterProfile, FileManagerPermissions::VIEW, 'projects/secret/plan.pdf', 'local'));
        self::assertTrue($authorizer->isPathAndAncestorsGranted($voterProfile, FileManagerPermissions::VIEW, 'projects/public/plan.pdf', 'local'));

        // 4. FileOperationsService::createFolder blocks creating disallowed top-level ancestor via recursive mkdir
        $denyRootCreate = $this->createStub(AuthorizationCheckerInterface::class);
        $denyRootCreate->method('isGranted')->willReturnCallback(
            static function (mixed $attr, mixed $subj): bool {
                if ($subj instanceof FileManagerSubject && $attr === FileManagerPermissions::CREATE_FOLDER && $subj->path === '') {
                    return false;
                }

                return true;
            },
        );
        $ops = new FileOperationsService(
            $registry,
            new PathNormalizer(),
            new FilenameNormalizer(),
            $trash,
            new NullMetadataStorage(),
            new EventDispatcher(),
            $denyRootCreate,
        );
        try {
            $ops->createFolder($voterProfile, 'new_top/sub', 'leaf', 'local');
            self::fail('Expected AccessDeniedException when parent ancestor does not exist and root CREATE_FOLDER is denied.');
        } catch (AccessDeniedException) {
            self::assertDirectoryDoesNotExist($this->tempDir . '/new_top');
        }
    }

    public function testFilenameNormalizerCjkSlashLongExtInstallSkillsUsageGuardAndAiRefusal(): void
    {
        // 1. FilenameNormalizer sanitizes CJK fullwidth slash ／ under slugger=false & caps total <= 255 bytes
        $normalizer = new FilenameNormalizer();
        $noSlugProfile = $this->makeProfile(naming: ['slugger' => false]);
        $cjkClean = $normalizer->normalizeForUpload("請求書\u{FF0F}2026.pdf", $noSlugProfile);
        self::assertSame('請求書-2026.pdf', $cjkClean);
        (new PathNormalizer())->normalize($cjkClean);

        $longExt = str_repeat('a', 60);
        $prependProfile = $this->makeProfile(naming: ['slugger' => false, 'prepend' => str_repeat('p', 50)]);
        $longName = $normalizer->normalizeForUpload(str_repeat('b', 180) . '.' . $longExt, $prependProfile);
        self::assertLessThanOrEqual(255, strlen($longName));
        self::assertStringEndsWith('.' . $longExt, $longName);

        // 2. InstallSkillsCommand rejects --dir=.
        $cmd = new InstallSkillsCommand($this->tempDir, $this->tempDir);
        $reflDir = new ReflectionMethod(InstallSkillsCommand::class, 'resolveCustomDirectory');
        self::assertNull($reflDir->invoke($cmd, '.'));
        self::assertNull($reflDir->invoke($cmd, './.'));
        self::assertNotNull($reflDir->invoke($cmd, '.agents/skills'));

        // 3. AssetUsageGuardService rejects hostless https:foo and https:///foo
        $checker = new class() implements AssetUsageCheckerInterface {
            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                return [
                    new AssetUsageReference('Bad1', 'p', 'https:foo', '1'),
                    new AssetUsageReference('Bad2', 'p', 'https:///foo', '2'),
                    new AssetUsageReference('Good', 'p', 'https://example.test/edit/3', '3'),
                ];
            }
        };
        $usageProfile = $this->makeProfile(usageGuard: ['enabled' => true, 'policy' => 'warn']);
        $usageVol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $usageRegistry = new VolumeRegistry($this->makeProvider($usageProfile, [$usageVol]), new FlysystemStorageFactory(new DynamicPathResolver()));
        $guardSvc = new AssetUsageGuardService($usageRegistry, new PathNormalizer(), new FileSecurityGuard(), [$checker]);
        $report = $guardSvc->inspectPaths($usageProfile, ['a.jpg'], 'local');
        self::assertCount(1, $report['items']);
        $refs = $report['items'][0]['references'];
        self::assertNull($refs[0]['editUrl']);
        self::assertNull($refs[1]['editUrl']);
        self::assertSame('https://example.test/edit/3', $refs[2]['editUrl']);

        // 4. SymfonyAiAssetEnricher rejects multilingual refusals (Turkish/French/Arabic/Japanese)
        foreach ([
            'Üzgünüm, bu görseli analiz edemiyorum.',
            'Désolé, je ne peux pas traiter cette image.',
            'آسف، لا أستطيع وصف هذه الصورة.',
            '申し訳ありませんが、この画像は処理できません。',
        ] as $refusal) {
            $parsed = SymfonyAiAssetEnricher::parseJsonResponse($refusal);
            self::assertNull($parsed->alt, sprintf('Multilingual refusal must not become SEO alt: %s', $refusal));
        }

        // 5. CacheInvalidationListener::canonicalRootsOverlap does not collide @ext_ with base root
        $cacheListener = new CacheInvalidationListener(new DirectoryCacheManager(new ArrayAdapter()), new PathNormalizer());
        $reflOverlap = new ReflectionMethod(CacheInvalidationListener::class, 'canonicalRootsOverlap');
        self::assertFalse((bool) $reflOverlap->invoke($cacheListener, 'local::@ext_deadbeef', 'local::'));
        self::assertTrue((bool) $reflOverlap->invoke($cacheListener, 'local::sub', 'local::'));
    }

    /**
     * @param array<string, mixed> $thumbnail
     * @param array<string, mixed> $cache
     * @param array<string, mixed> $ui
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $security
     * @param array<string, mixed> $share
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $naming
     * @param list<string>         $excludePatterns
     * @param array<string, mixed> $usageGuard
     */
    private function makeProfile(
        array $thumbnail = [],
        array $cache = ['enabled' => false],
        array $ui = [],
        array $permissions = [],
        array $security = [],
        array $share = ['enabled' => true],
        array $trash = ['enabled' => true, 'directory' => '.trash'],
        array $naming = [],
        array $excludePatterns = [],
        array $usageGuard = [],
    ): ProfileConfig {
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
            excludePatterns: $excludePatterns,
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['image/*', 'text/plain', 'application/pdf'],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false, 'chunk_size' => 1048576, 'max_file_size' => '10M'],
            naming: $naming,
            imageOptimization: ['enabled' => false],
            thumbnail: $thumbnail !== [] ? $thumbnail : ['enabled' => false, 'cache_dir' => $this->tempDir . '/_thumbs'],
            trash: $trash,
            zip: ['enabled' => true],
            ui: $ui,
            permissions: $permissions,
            security: $security,
            cache: $cache,
            metadata: ['enabled' => false],
            share: $share,
            usageGuard: $usageGuard,
        );
    }

    /**
     * @param list<VolumeConfig> $volumes
     */
    private function makeProvider(ProfileConfig $profile, array $volumes): ConfigProviderInterface
    {
        $volMap = [];
        foreach ($volumes as $v) {
            $volMap[$v->name] = $v;
        }

        return new class($profile, $volMap) implements ConfigProviderInterface {
            /** @param array<string, VolumeConfig> $volumes */
            public function __construct(private ProfileConfig $profile, private array $volumes)
            {
            }

            public function hasProfile(string $name): bool
            {
                return $name === $this->profile->name;
            }

            public function getProfile(string $name): ProfileConfig
            {
                return $this->profile;
            }

            public function getProfileNames(): array
            {
                return [$this->profile->name];
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
}
