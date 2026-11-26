<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use FilesystemIterator;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\Controller\UploadController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\EventListener\AuditLogListener;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\FileManagerBundle;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerVoter;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\StorageFactoryInterface;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Components\BrowserComponent;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;
use stdClass;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Pass-13 verification & regression suite covering DI compiler priority & decorators,
 * locale catalog DoS protection, Emoji VS16/CJK IVS stems, pre-read thumbnail auth,
 * UTF-8 long picker URLs & %23, DialogHost profile leak & HTML comments, deferred cache
 * invalidation, cross-profile tree isolation, fail-closed voter disabled mode, deep tree counts,
 * commented-SVG MIME normalization, DBAL empty delete & PostgreSQL expression predicates, and icon CWD hijack.
 */
final class ThirteenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_thirteenth_' . bin2hex(random_bytes(4));
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

    public function testDetectExternalVotersPassPriorityAndDecoratedChecker(): void
    {
        $container = new ContainerBuilder();
        $container->register(DirectoryLister::class, DirectoryLister::class);
        $container->register('app.core_voter', FileManagerVoter::class)->addTag('security.voter');
        $container->register('security.authorization_checker', AuthorizationChecker::class);
        $container->register('app.custom_auth_decorator', stdClass::class)
            ->setDecoratedService('security.authorization_checker');

        $bundle = new FileManagerBundle();
        $bundle->build($container);

        $beforeRemovingPasses = $container->getCompilerPassConfig()->getBeforeRemovingPasses();
        $hasBeforeRemoving = false;
        foreach ($beforeRemovingPasses as $pass) {
            if ($pass instanceof DetectExternalVotersPass) {
                $hasBeforeRemoving = true;
                break;
            }
        }
        self::assertTrue($hasBeforeRemoving, 'DetectExternalVotersPass must run at PassConfig::TYPE_BEFORE_REMOVING.');

        (new DetectExternalVotersPass())->process($container);
        $args = $container->getDefinition(DirectoryLister::class)->getArguments();
        self::assertArrayNotHasKey('$hasExternalVoters', $args, 'DetectExternalVotersPass must never leave $-prefixed argument keys after ResolveNamedArgumentsPass.');
        self::assertTrue((bool) ($args['hasExternalVoters'] ?? $args[6] ?? false));

        // Without custom decorator or external voter -> false even when AuthorizationChecker is core
        $cleanContainer = new ContainerBuilder();
        $cleanContainer->register(DirectoryLister::class, DirectoryLister::class);
        $cleanContainer->register('app.core_voter', FileManagerVoter::class)->addTag('security.voter');
        $cleanContainer->register('security.authorization_checker', AuthorizationChecker::class);
        (new DetectExternalVotersPass())->process($cleanContainer);
        $cleanArgs = $cleanContainer->getDefinition(DirectoryLister::class)->getArguments();
        self::assertArrayNotHasKey('$hasExternalVoters', $cleanArgs);
        self::assertFalse((bool) ($cleanArgs['hasExternalVoters'] ?? $cleanArgs[6] ?? true));
    }

    public function testMessageTranslatorLocaleAllowlistPreventsCatalogueFlood(): void
    {
        self::assertTrue(MessageTranslator::isValidLocaleString('zh-Hans-CN'));
        self::assertTrue(MessageTranslator::isValidLocaleString('sr_Latn_RS'));
        self::assertTrue(MessageTranslator::isValidLocaleString('es_419'));
        self::assertTrue(MessageTranslator::isValidLocaleString('ckb'));
        self::assertFalse(MessageTranslator::isValidLocaleString('aa_00000001'));

        $baseTranslator = new Translator('en');
        $requestedCatalogues = [];
        $spyTranslator = new class($baseTranslator, $requestedCatalogues) extends Translator {
            /** @var list<string> */
            private array $log;

            public function __construct(Translator $inner, array &$log)
            {
                parent::__construct('en');
                $this->log = &$log;
                unset($inner);
            }

            public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
            {
                $this->log[] = (string) $locale;

                return parent::getCatalogue($locale);
            }
        };

        $translator = new MessageTranslator($spyTranslator, null, ['en', 'tr']);
        $msg = $translator->trans('actions.save', [], 'aa');
        self::assertNotSame('actions.save', $msg);
        self::assertNotContains('aa', $requestedCatalogues, 'Disallowed dynamic locale must NEVER trigger getCatalogue().');
        self::assertContains('en', $requestedCatalogues);
    }

    public function testEmojiVariationSelectorAndIvsAllowedAtTailWhileBlockingDotOrEdgeFillers(): void
    {
        self::assertFalse(UnicodeHelper::hasEdgeInvisibleOrTrailingDot('❤️'));
        self::assertFalse(UnicodeHelper::hasEdgeInvisibleOrTrailingDot('🖼️'));
        self::assertFalse(UnicodeHelper::hasEdgeInvisibleOrTrailingDot("葛\u{E0100}"));
        self::assertTrue(UnicodeHelper::hasEdgeInvisibleOrTrailingDot("\u{FE0F}"));
        self::assertTrue(UnicodeHelper::hasEdgeInvisibleOrTrailingDot("foo.\u{FE0F}"));
        self::assertTrue(UnicodeHelper::hasEdgeInvisibleOrTrailingDot("foo\u{200B}\u{FE0F}"));

        $guard = new FileSecurityGuard();
        $guard->assertSafeFilename('❤️.png');
        $guard->assertSafeFilename("葛\u{E0100}.pdf");

        $this->expectException(UploadValidationException::class);
        $guard->assertSafeFilename("xss.s\u{FE0F}vg");
    }

    public function testThumbnailPreReadAuthAndPrivateJsonHeaders(): void
    {
        $profile = $this->makeProfile(thumbnail: ['enabled' => false, 'on_missing_driver' => 'icon', 'cache_dir' => $this->tempDir]);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        file_put_contents($this->tempDir . '/raw.png', 'not-read');

        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($profile);
        $provider->method('getVolume')->willReturn($vol);

        $readCalled = false;
        $fakeFs = $this->createStub(FilesystemOperator::class);
        $fakeFs->method('fileExists')->willReturn(true);
        $fakeFs->method('fileSize')->willReturn(1024);
        $fakeFs->method('readStream')->willReturnCallback(static function () use (&$readCalled) {
            $readCalled = true;

            return false;
        });

        $volRegistry = new VolumeRegistry($provider, new class($fakeFs) implements StorageFactoryInterface {
            public function __construct(private readonly FilesystemOperator $fs)
            {
            }

            public function create(VolumeConfig $volume): FilesystemOperator
            {
                return $this->fs;
            }
        });

        $thumbCtrl = new ThumbnailController(
            $provider,
            $volRegistry,
            new PathNormalizer(),
            new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
        );

        try {
            $thumbCtrl(Request::create('/_liveux-file-manager/thumb?profile=default&volume=local&path=raw.png'));
            self::fail('Expected NotFoundHttpException when driver is missing and on_missing_driver=icon.');
        } catch (NotFoundHttpException) {
            self::assertFalse($readCalled, 'Thumbnail binary MUST NOT be buffered when driver is missing and icon fallback is configured.');
        }

        // UploadController, ZipDownloadController error responses carry private, no-store + nosniff
        $dispatcher = new EventDispatcher();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $uploadCtrl = new UploadController(
            $provider,
            new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher),
            new CsrfRequestValidator(null),
        );
        $upErr = $uploadCtrl(Request::create('/_liveux-file-manager/upload', 'POST', ['profile' => 'default']));
        self::assertSame(Response::HTTP_BAD_REQUEST, $upErr->getStatusCode());
        self::assertStringContainsString('no-store', (string) $upErr->headers->get('Cache-Control'));
        self::assertSame('nosniff', $upErr->headers->get('X-Content-Type-Options'));

        $zipCtrl = new ZipDownloadController(
            $provider,
            new ZipArchiveService($registry, $normalizer),
            new CsrfRequestValidator(null),
        );
        $zipErr = $zipCtrl(Request::create('/_liveux-file-manager/zip', 'POST', [], [], [], [], '{bad-json'));
        self::assertSame(Response::HTTP_BAD_REQUEST, $zipErr->getStatusCode());
        self::assertStringContainsString('no-store', (string) $zipErr->headers->get('Cache-Control'));
        self::assertSame('nosniff', $zipErr->headers->get('X-Content-Type-Options'));
        unset($trash);
    }

    public function testPickerLongUtf8UrlsEncodedHashAndTwigExtension(): void
    {
        $reflSafe = new ReflectionMethod(FileManagerPickerType::class, 'isSafePickerValue');
        $longCjkStem = str_repeat('中', 35) . '.png';
        $longCjkUrl = 'https://cdn.example.com/uploads/' . rawurlencode($longCjkStem);
        self::assertGreaterThan(255, strlen(rawurlencode($longCjkStem)));
        self::assertLessThanOrEqual(255, strlen($longCjkStem));
        self::assertTrue((bool) $reflSafe->invoke(null, $longCjkUrl, 'url'), 'Long valid UTF-8 filename URL must be accepted when decoded leaf <= 255 bytes.');
        self::assertTrue((bool) $reflSafe->invoke(null, '/uploads/' . rawurlencode('notes#1.pdf'), 'url'));
        self::assertFalse((bool) $reflSafe->invoke(null, '/uploads/notes%3Faction=1/../.env', 'url'));
        self::assertFalse((bool) $reflSafe->invoke(null, 'https://cdn.example.com/uploads/bad%23/../.env', 'url'));
        self::assertFalse((bool) $reflSafe->invoke(null, ".\u{200B}trash/secret.png", 'path'));

        $ext = new FileManagerTwigExtension($this->createStub(UrlGeneratorInterface::class));
        $absBad = $ext->describeFieldItem('https://evil.example/.trash/secret.png');
        self::assertNull($absBad['href']);
        self::assertNull($absBad['imgSrc']);

        $absShareSvg = $ext->describeFieldItem('https://cdn.example.com/_liveux-file-manager/share?path=vector.svg&sig=1');
        self::assertNull($absShareSvg['imgSrc'], 'Absolute share SVG URL must not render unsafe imgSrc.');

        $badQueryDownload = $ext->describeFieldItem('/_liveux-file-manager/download?path=../.env&inline=1');
        self::assertNull($badQueryDownload['href']);
    }

    public function testAutoInjectDialogHostProfileLeakAndCommentBypass(): void
    {
        $defaultProf = $this->makeProfile('default', ['local']);
        $publicProf = $this->makeProfile('public', ['pub']);
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfileNames')->willReturn(['default', 'public']);
        $provider->method('hasProfile')->willReturn(true);
        $provider->method('getProfile')->willReturnCallback(
            static fn (string $name): ProfileConfig => $name === 'public' ? $publicProf : $defaultProf,
        );

        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr, mixed $subj): bool => $subj instanceof \LiveUX\FileManagerBundle\Security\FileManagerSubject
                && $subj->profile->name === 'public',
        );

        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div data-profile="{{ profile }}"></div>',
        ]));

        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $provider, $auth);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $resp = new Response('<html><body>Hello</body></html>', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store']);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $resp));
        self::assertStringContainsString('data-profile="public"', (string) $resp->getContent());
        self::assertStringNotContainsString('data-profile="default"', (string) $resp->getContent());

        // Commented-out picker markup must NOT trigger DialogHost when no profile is granted
        $denyAllAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyAllAuth->method('isGranted')->willReturn(false);
        $denySub = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $provider, $denyAllAuth);
        $commentResp = new Response(
            '<html><body><!-- <div data-controller="liveux--file-manager-bundle--picker"></div> --></body></html>',
            200,
            ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store'],
        );
        $denySub->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $commentResp));
        self::assertStringNotContainsString('data-profile=', (string) $commentResp->getContent());
    }

    public function testAuditLogAssetEnrichAndDbalEmptyDeleteAndPostgresIndex(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            private array $records;

            public function __construct(array &$records)
            {
                $this->records = &$records;
            }

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $listener = new AuditLogListener(true, 'info', $logger);
        $prof = $this->makeProfile('default', ['local'], security: ['audit_log' => true]);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $listener->onFileDeleted(new FileDeletedEvent($prof, $vol, '{ip}.jpg', 'local::{ip}.jpg'));
        self::assertCount(1, $records);
        self::assertSame('[LiveUX FileManager Audit] {action} "{path}"', $records[0]['message']);

        // AssetEnrichmentService::enrichAndSave requires EDIT_METADATA even when AI_ENRICH is granted
        file_put_contents($this->tempDir . '/photo.txt', 'hello');
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfile')->willReturn($this->makeProfile('default', ['local'], ai: ['enabled' => true], metadata: ['enabled' => true]));
        $provider->method('getVolume')->willReturn($vol);
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $enricher = $this->createStub(AssetEnricherInterface::class);
        $enricher->method('supports')->willReturn(true);
        $enricher->method('enrich')->willReturn(new AssetEnrichmentResult('Alt', 'Title', 'Cap', ['t']));
        $noEditMetaAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $noEditMetaAuth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr): bool => $attr !== FileManagerPermissions::EDIT_METADATA,
        );
        $enrichSvc = new AssetEnrichmentService(
            volumeRegistry: $registry,
            pathNormalizer: new PathNormalizer(),
            metadataStorage: new NullMetadataStorage(),
            cacheManager: new DirectoryCacheManager(),
            enricher: $enricher,
            authChecker: $noEditMetaAuth,
        );
        $editDenied = false;
        try {
            $enrichSvc->enrichAndSave($provider->getProfile('default'), 'photo.txt', 'local');
        } catch (AccessDeniedException) {
            $editDenied = true;
        }
        self::assertTrue($editDenied, 'enrichAndSave must deny when EDIT_METADATA is denied.');

        // DoctrineDbalMetadataStorage: saving empty deletes DB row even if itemCache held empty, and PostgreSQL uses LEFT(191) predicate
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_meta_test', true);
        self::assertTrue($dbal->get('local::race.png')->isEmpty());
        $conn->insert('liveux_fm_meta_test', [
            'key_hash' => hash('sha256', 'local::race.png'),
            'canonical_key' => 'local::race.png',
            'payload' => '{"alt":"Stale"}',
            'updated_at' => '2026-11-24 00:00:00',
        ]);
        $dbal->save('local::race.png', new FolderMetadata());
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM liveux_fm_meta_test'));

        $reflWhere = new ReflectionMethod(DoctrineDbalMetadataStorage::class, 'buildPrefixWhere');
        $mockPg = $this->createStub(\Doctrine\DBAL\Connection::class);
        $mockPg->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        [$pgClause, $pgParams] = $reflWhere->invoke($dbal, $mockPg, 'local::folder');
        self::assertStringContainsString('LEFT(canonical_key, 191) ~>=~ :rangeStart', $pgClause);
        self::assertStringContainsString('LEFT(canonical_key, 191) ~<=~ :rangeEnd', $pgClause);
        self::assertArrayHasKey('rangeStart', $pgParams);
        self::assertArrayHasKey('rangeEnd', $pgParams);
    }

    public function testDirectoryListerCrossProfileFailClosedDeferredInvalidationAndDeepCounts(): void
    {
        mkdir($this->tempDir . '/visible/nested', 0775, true);
        mkdir($this->tempDir . '/restricted', 0775, true);
        file_put_contents($this->tempDir . '/visible/nested/a.txt', 'ok');
        file_put_contents($this->tempDir . '/restricted/secret.txt', 'secret');

        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $profOpen = $this->makeProfile('open', ['local']);
        $profExcl = $this->makeProfile('excl', ['local'], excludePatterns: ['/^restricted$/i']);

        $lister = new DirectoryLister(
            new VolumeRegistry($this->makeProvider(['open' => $profOpen, 'excl' => $profExcl], $vol), new FlysystemStorageFactory(new DynamicPathResolver())),
            new PathNormalizer(),
            new DirectoryCacheManager(),
            new NullMetadataStorage(),
            hasExternalVoters: false,
        );

        // Cross-profile requestTreeCache isolation on same volume
        $openTree = array_column($lister->listFullTree($profOpen, 'local'), 'path');
        $exclTree = array_column($lister->listFullTree($profExcl, 'local'), 'path');
        self::assertContains('restricted', $openTree);
        self::assertNotContains('restricted', $exclTree);

        // Fail-closed when voter_enabled: false and permissions.view: false
        $profDenied = $this->makeProfile('denied', ['local'], permissions: ['view' => false], security: ['voter_enabled' => false]);
        self::assertSame([], $lister->listFullTree($profDenied, 'local'));

        // searchRecursive checks ancestor folder VIEW permission
        $ancAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $ancAuth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr, mixed $subj): bool => !(
                $subj instanceof \LiveUX\FileManagerBundle\Security\FileManagerSubject
                && $subj->path === 'restricted'
            ),
        );
        $voterLister = new DirectoryLister(
            new VolumeRegistry($this->makeProvider(['open' => $profOpen], $vol), new FlysystemStorageFactory(new DynamicPathResolver())),
            new PathNormalizer(),
            new DirectoryCacheManager(),
            new NullMetadataStorage(),
            authChecker: $ancAuth,
            hasExternalVoters: true,
        );
        $searchRes = $voterLister->searchRecursive($profOpen, 'local', '', 'secret');
        self::assertCount(0, $searchRes->items, 'Denied ancestor directory must block nested matching file.');

        // Transient root scan failure MUST NOT be cached for 3600s
        $pool = new ArrayAdapter();
        $cacheMgr = new DirectoryCacheManager($pool);
        $calls = 0;
        $failingFs = $this->createStub(FilesystemOperator::class);
        $failingFs->method('listContents')->willReturnCallback(static function (string $dir = '') use (&$calls): \League\Flysystem\DirectoryListing {
            if ($dir === '') {
                ++$calls;
                if ($calls === 1) {
                    throw new RuntimeException('Transient I/O error');
                }

                return new \League\Flysystem\DirectoryListing([new DirectoryAttributes('recovered')]);
            }

            return new \League\Flysystem\DirectoryListing([]);
        });
        $transientFs = $failingFs;
        $transientRegistry = new VolumeRegistry($this->makeProvider(['open' => $profOpen], $vol), new class($transientFs) implements StorageFactoryInterface {
            public function __construct(private readonly FilesystemOperator $fs)
            {
            }

            public function create(VolumeConfig $volume): FilesystemOperator
            {
                return $this->fs;
            }
        });
        $transientLister = new DirectoryLister($transientRegistry, new PathNormalizer(), $cacheMgr, new NullMetadataStorage(), hasExternalVoters: false);
        self::assertSame([], $transientLister->listFullTree($profOpen, 'local'));
        $transientLister->reset();
        $recoveredTree = array_column($transientLister->listFullTree($profOpen, 'local'), 'path');
        self::assertSame(['recovered'], $recoveredTree, 'Transient scan failure must not poison warm tree cache.');

        // DirectoryCacheManager bumpGeneration uses randomized string tokens
        $reflBump = new ReflectionMethod(DirectoryCacheManager::class, 'bumpGeneration');
        $reflRead = new ReflectionMethod(DirectoryCacheManager::class, 'readGenerations');
        $reflBump->invoke($cacheMgr, 'test_gen_key');
        $gens = $reflRead->invoke($cacheMgr, ['test_gen_key']);
        self::assertMatchesRegularExpression('/^r[0-9a-f]{16}$/', $gens['test_gen_key']);
    }

    public function testSvgCommentBypassTrashFallbackMapRemoteZipFileOpsAndIconCwdHijack(): void
    {
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $prof = $this->makeProfile('default', ['local'], uploadDeny: ['image/svg+xml']);
        $provider = $this->makeProvider(['default' => $prof], $vol);
        $dispatcher = new EventDispatcher();
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher);

        // Commented SVG uploaded as .svg MUST be canonicalized to image/svg+xml and blocked by upload_deny
        $svgPath = $this->tempDir . '/commented.svg';
        file_put_contents($svgPath, "<!-- sneaky comment before svg root -->\n<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>");
        $upFile = new UploadedFile($svgPath, 'commented.svg', 'text/plain', null, true);
        $svgBlocked = false;
        try {
            $uploadMgr->handleUpload($prof, $upFile, '', 'local');
        } catch (UploadValidationException $e) {
            $svgBlocked = true;
        }
        self::assertTrue($svgBlocked, 'Commented SVG disguised with text/plain MIME must be blocked when image/svg+xml is denied.');

        // RemoteUrlImporter::resolveSafeExtractedCandidate preserves .docx/.csv while blocking GIF+PHP polyglot
        $importer = new RemoteUrlImporter($uploadMgr, $registry, $normalizer);
        $reflCand = new ReflectionMethod(RemoteUrlImporter::class, 'resolveSafeExtractedCandidate');
        $mimeTypes = MimeTypes::getDefault();
        self::assertSame('report.docx', $reflCand->invoke($importer, 'report.docx', 'application/zip', $mimeTypes));
        self::assertSame('data.csv', $reflCand->invoke($importer, 'data.csv', 'text/plain', $mimeTypes));
        self::assertNull($reflCand->invoke($importer, 'shell.jpg', 'text/x-php', $mimeTypes));

        // FileOperationsService::updateFolderAppearance sanitizes FolderMetadata::fromArray
        mkdir($this->tempDir . '/folder_a', 0775, true);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), new TrashManager($registry, $normalizer, $dispatcher), new NullMetadataStorage(), $dispatcher);
        $updatedMeta = $ops->updateFolderAppearance($prof, 'folder_a', '#ff0000', 'star', true, 'local', true);
        self::assertSame('#ff0000', $updatedMeta->color);
        self::assertSame('star', $updatedMeta->icon);

        // IconSetRegistry ignores CWD directory literally named 'classic'
        $prevCwd = getcwd();
        $hijackDir = $this->tempDir . DIRECTORY_SEPARATOR . 'cwd_hijack';
        mkdir($hijackDir . DIRECTORY_SEPARATOR . 'classic', 0775, true);
        file_put_contents($hijackDir . DIRECTORY_SEPARATOR . 'classic' . DIRECTORY_SEPARATOR . 'meta.json', '{"defaultFolderColor":"#00ff00"}');
        try {
            chdir($hijackDir);
            $icons = new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons');
            $payload = $icons->getIconSetPayload('classic', true);
            self::assertNotSame('#00ff00', $payload['defaultFolderColor'], 'CWD folder named "classic" must not hijack built-in icon set.');
        } finally {
            if (is_string($prevCwd)) {
                chdir($prevCwd);
            }
        }

        // TrashManager::resolveEntry uses buildFallbackKey() when reading $cachedFallbackMap
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $memFs = new FilesystemOperatorStubForTrash();
        $fbKey = $normalizer->buildCanonicalKey(new VolumeConfig('s3', 'S3', 's3', 's3', null, null, false, ['bucket' => 'b', 'prefix' => 'p']), '__trash_fb__/t1', '');
        $reflResolve = new ReflectionMethod(TrashManager::class, 'resolveEntry');
        $resolved = $reflResolve->invoke(
            $trash,
            $memFs,
            new VolumeConfig('s3', 'S3', 's3', 's3', null, null, false, ['bucket' => 'b', 'prefix' => 'p']),
            '.trash/t1',
            't1',
            [$fbKey => ['id' => 't1', 'name' => 'from_cache.txt', 'originalPath' => 'from_cache.txt', 'volume' => 's3', 'isFolder' => false, 'size' => 12, 'deletedAt' => 1730000000]],
            '',
        );
        self::assertNotNull($resolved);
        self::assertSame('from_cache.txt', $resolved->name);

        // BrowserComponent skips listFullTree when show_folder_tree is false
        $noTreeProf = $this->makeProfile('notree', ['local'], ui: ['show_folder_tree' => false]);
        $comp = new BrowserComponent(
            $this->makeProvider(['notree' => $noTreeProf], $vol),
            $registry,
            new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage(), hasExternalVoters: false),
            $trash,
            new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            new ZipArchiveService($registry, $normalizer),
            $normalizer,
            $this->createStub(UrlGeneratorInterface::class),
            new IconSetRegistry(\dirname(__DIR__, 3) . '/resources/icons'),
            new CsrfRequestValidator(null),
        );
        $comp->mount('notree', 'local');
        self::assertSame([], $comp->getInitialTreeJson());
    }

    /**
     * @param list<string>         $volumes
     * @param list<string>         $uploadDeny
     * @param list<string>         $excludePatterns
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $security
     * @param array<string, mixed> $thumbnail
     * @param array<string, mixed> $ai
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $ui
     */
    private function makeProfile(
        string $name = 'default',
        array $volumes = ['local'],
        array $uploadDeny = [],
        array $excludePatterns = [],
        array $permissions = [],
        array $security = ['voter_enabled' => true],
        array $thumbnail = ['enabled' => false],
        array $ai = [],
        array $metadata = ['enabled' => true],
        array $ui = [],
    ): ProfileConfig {
        return new ProfileConfig(
            name: $name,
            basePath: $this->tempDir,
            theme: 'shadcn',
            locale: 'en',
            volumes: $volumes,
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
            uploadAllow: [],
            uploadDeny: $uploadDeny,
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: [],
            naming: [],
            imageOptimization: [],
            thumbnail: $thumbnail,
            trash: ['enabled' => true, 'directory' => '.trash'],
            zip: ['enabled' => true],
            ui: $ui,
            permissions: $permissions,
            security: $security,
            cache: ['enabled' => true, 'ttl' => 3600],
            metadata: $metadata,
            ai: $ai,
        );
    }

    /**
     * @param array<string, ProfileConfig> $profiles
     */
    private function makeProvider(array $profiles, VolumeConfig $vol): ConfigProviderInterface
    {
        $provider = $this->createStub(ConfigProviderInterface::class);
        $provider->method('getProfileNames')->willReturn(array_keys($profiles));
        $provider->method('hasProfile')->willReturnCallback(static fn (string $n): bool => isset($profiles[$n]));
        $provider->method('getProfile')->willReturnCallback(static fn (string $n): ProfileConfig => $profiles[$n]);
        $provider->method('getVolume')->willReturn($vol);

        return $provider;
    }
}

/**
 * Minimal Flysystem stub ensuring resolveEntry uses $cachedFallbackMap before remote fileExists().
 */
final class FilesystemOperatorStubForTrash extends \League\Flysystem\Filesystem
{
    public function __construct()
    {
        parent::__construct(new class implements \League\Flysystem\FilesystemAdapter {
            public function fileExists(string $path): bool
            {
                return false;
            }

            public function directoryExists(string $path): bool
            {
                return false;
            }

            public function write(string $path, string $contents, \League\Flysystem\Config $config): void
            {
            }

            public function writeStream(string $path, $contents, \League\Flysystem\Config $config): void
            {
            }

            public function read(string $path): string
            {
                return '';
            }

            public function readStream(string $path)
            {
                throw new RuntimeException('Unused');
            }

            public function delete(string $path): void
            {
            }

            public function deleteDirectory(string $path): void
            {
            }

            public function createDirectory(string $path, \League\Flysystem\Config $config): void
            {
            }

            public function setVisibility(string $path, string $visibility): void
            {
            }

            public function visibility(string $path): \League\Flysystem\FileAttributes
            {
                throw new RuntimeException('Unused');
            }

            public function mimeType(string $path): \League\Flysystem\FileAttributes
            {
                throw new RuntimeException('Unused');
            }

            public function lastModified(string $path): \League\Flysystem\FileAttributes
            {
                throw new RuntimeException('Unused');
            }

            public function fileSize(string $path): \League\Flysystem\FileAttributes
            {
                throw new RuntimeException('Unused');
            }

            public function listContents(string $path, bool $deep): iterable
            {
                return [];
            }

            public function move(string $source, string $destination, \League\Flysystem\Config $config): void
            {
            }

            public function copy(string $source, string $destination, \League\Flysystem\Config $config): void
            {
            }
        });
    }

    public function fileExists(string $location): bool
    {
        throw new RuntimeException('Remote fileExists() must not run when cachedFallbackMap hits.');
    }
}
