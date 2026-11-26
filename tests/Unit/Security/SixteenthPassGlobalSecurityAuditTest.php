<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Event\FolderCreatedEvent;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\EventListener\CacheInvalidationListener;
use LiveUX\FileManagerBundle\EventListener\ThumbnailCleanupListener;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileManagerVoter;
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
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Components\BrowserComponent;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Debug\TraceableAccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Authorization\Voter\TraceableVoter;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SixteenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_p16_' . bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testPostgresqlPrefixQueriesUseTextPatternOpsOperators(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_p16_meta', true);

        $reflWhere = new ReflectionMethod(DoctrineDbalMetadataStorage::class, 'buildPrefixWhere');
        $mockPg = $this->createStub(Connection::class);
        $mockPg->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        [$clause, $params] = $reflWhere->invoke($dbal, $mockPg, 'local::docs');
        self::assertStringContainsString('LEFT(canonical_key, 191) ~>=~ :rangeStart', $clause);
        self::assertStringContainsString('LEFT(canonical_key, 191) ~<=~ :rangeEnd', $clause);
        self::assertStringNotContainsString(' >= :rangeStart', $clause);
        self::assertStringNotContainsString(' <= :rangeEnd', $clause);
        self::assertSame('local::docs/', $params['rangeStart']);
        self::assertSame("local::docs/\u{10FFFF}", $params['rangeEnd']);
    }

    public function testSvgSanitizerBlocksExponentialUseExpansionDualHrefAndXmlIdCycles(): void
    {
        $sanitizer = new SvgSanitizer();

        // 1. Exponential 4-level <use> bomb (< 100 <use> elements, depth 4, expands to 331k+ nodes)
        $g0 = '<g id="g0">' . str_repeat('<path d="M0 0h1v1z"/>', 12) . '</g>';
        $g1 = '<g id="g1">' . str_repeat('<use href="#g0"/>', 24) . '</g>';
        $g2 = '<g id="g2">' . str_repeat('<use href="#g1"/>', 24) . '</g>';
        $g3 = '<g id="g3">' . str_repeat('<use href="#g2"/>', 24) . '</g>';
        $bombSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs>' . $g0 . $g1 . $g2 . $g3 . '</defs><use href="#g3"/></svg>';
        $start = microtime(true);
        $cleanBomb = $sanitizer->sanitize($bombSvg);
        $elapsed = microtime(true) - $start;
        self::assertLessThan(2.0, $elapsed, 'Memoized <use> expansion guard must finish quickly');
        self::assertStringNotContainsString('#g2', $cleanBomb, 'Exponential <use href="#g2"> inside g3 exceeding expansion budget must be stripped');
        self::assertLessThanOrEqual(2, substr_count($cleanBomb, 'href="#g1"'), 'Expansion cap must prune excess <use href="#g1"> nodes');

        // 2. Dual href="#nope" + xlink:href="#a" cycle bypass
        $dualHrefSvg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<g id="a"><use href="#nope" xlink:href="#a"/></g><use href="#a"/></svg>';
        $cleanDual = $sanitizer->sanitize($dualHrefSvg);
        self::assertStringNotContainsString('xlink:href="#a"', $cleanDual);

        // 3. xml:id self-cycle bypass
        $xmlIdSvg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xml="http://www.w3.org/XML/1998/namespace">'
            . '<g xml:id="loop"><use href="#loop"/></g></svg>';
        $cleanXmlId = $sanitizer->sanitize($xmlIdSvg);
        self::assertStringNotContainsString('<use', $cleanXmlId);

        // 4. Legitimate icon sprite <use> remains intact
        $validSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><symbol id="ico"><circle r="4"/></symbol></defs>'
            . '<use href="#ico" x="0"/><use href="#ico" x="12"/></svg>';
        $cleanValid = $sanitizer->sanitize($validSvg);
        self::assertSame(2, substr_count($cleanValid, '<use'));
    }

    public function testDetectExternalVotersPassHandlesDecoratedDecisionManagerInlineVotersAndTraceableVoter(): void
    {
        $pass = new DetectExternalVotersPass();

        // Case 1: TraceableAccessDecisionManager decorating inner AccessDecisionManager with untagged custom voter
        $c1 = new ContainerBuilder();
        $c1->register(DirectoryLister::class, DirectoryLister::class);
        $c1->register('app.custom_untagged_voter', \stdClass::class);
        $c1->register('debug.security.access.decision_manager.inner', AccessDecisionManager::class)
            ->setArguments([new IteratorArgument([new Reference('app.custom_untagged_voter')])]);
        $traceableDm = $c1->register('security.access.decision_manager', TraceableAccessDecisionManager::class)
            ->setArguments([new Reference('debug.security.access.decision_manager.inner')]);
        $traceableDm->innerServiceId = 'debug.security.access.decision_manager.inner';
        $pass->process($c1);
        self::assertTrue($c1->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // Case 2: Inline Definition voter inside AccessDecisionManager
        $c2 = new ContainerBuilder();
        $c2->register(DirectoryLister::class, DirectoryLister::class);
        $c2->register('security.access.decision_manager', AccessDecisionManager::class)
            ->setArguments([[new Definition(\stdClass::class)]]);
        $pass->process($c2);
        self::assertTrue($c2->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // Case 3: TraceableVoter wrapping a custom voter
        $c3 = new ContainerBuilder();
        $c3->register(DirectoryLister::class, DirectoryLister::class);
        $c3->register('app.wrapped_custom', \stdClass::class);
        $c3->register('debug.voter.custom', TraceableVoter::class)
            ->setArguments([new Reference('app.wrapped_custom')])
            ->addTag('security.voter');
        $pass->process($c3);
        self::assertTrue($c3->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // Case 4: Pure core voters + FileManagerVoter wrapped in TraceableVoter + TraceableAccessDecisionManager -> false
        $c4 = new ContainerBuilder();
        $c4->register(DirectoryLister::class, DirectoryLister::class);
        $c4->register('core.role_voter', RoleVoter::class);
        $c4->register('fm.voter', FileManagerVoter::class);
        $c4->register('debug.voter.role', TraceableVoter::class)
            ->setArguments([new Reference('core.role_voter')])
            ->addTag('security.voter');
        $c4->register('debug.voter.fm', TraceableVoter::class)
            ->setArguments([new Reference('fm.voter')])
            ->addTag('security.voter');
        $c4->register('debug.dm.inner', AccessDecisionManager::class)
            ->setArguments([new IteratorArgument([new Reference('debug.voter.role'), new Reference('debug.voter.fm')])]);
        $dm4 = $c4->register('security.access.decision_manager', TraceableAccessDecisionManager::class)
            ->setArguments([new Reference('debug.dm.inner')]);
        $dm4->innerServiceId = 'debug.dm.inner';
        $pass->process($c4);
        self::assertFalse($c4->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));
    }

    public function testThumbnailCleanupListenerPurgesShardedDirectoryAndUses320x240Defaults(): void
    {
        $cacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'thumb-cache';
        $shardDir = $cacheDir . DIRECTORY_SEPARATOR . 'ab';
        mkdir($shardDir, 0777, true);
        $thumbFile = $shardDir . DIRECTORY_SEPARATOR . str_repeat('a', 32) . '.webp';
        file_put_contents($thumbFile, 'RIFFxxxxWEBP');

        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $defaultProfile = $this->makeProfile('default', ['cache_dir' => $cacheDir]);
        $profile300 = $this->makeProfile('custom300', ['cache_dir' => $cacheDir, 'width' => 300, 'height' => 300]);
        $provider = $this->makeProvider(['default' => $defaultProfile, 'custom300' => $profile300], new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir));
        $listener = new ThumbnailCleanupListener($thumbManager, $provider);

        $reflResolve = new ReflectionMethod(ThumbnailCleanupListener::class, 'resolveUniqueThumbnailProfiles');
        /** @var list<ProfileConfig> $targets */
        $targets = $reflResolve->invoke($listener, $defaultProfile);
        self::assertCount(2, $targets, 'Default 320x240 profile must not collide with explicit 300x300 profile');

        $reflPurge = new ReflectionMethod(ThumbnailCleanupListener::class, 'purgeAllCachedThumbnailsForTargets');
        $reflPurge->invoke($listener, $targets);
        self::assertFileDoesNotExist($thumbFile, 'Sharded thumbnail file must be purged by clearCacheDir()');
    }

    public function testCrossVolumeOverlappingS3FolderMoveIntoDescendantIsBlocked(): void
    {
        $s3Root = new VolumeConfig('s3_root', 'S3 Root', 'network', 'AwsS3', null, null, false, ['bucket' => 'media', 'prefix' => '']);
        $s3Sub = new VolumeConfig('s3_sub', 'S3 Sub', 'network', 'AwsS3', null, null, false, ['bucket' => 'media', 'prefix' => 'projects']);
        $s3Other = new VolumeConfig('s3_other', 'S3 Other', 'network', 'AwsS3', null, null, false, ['bucket' => 'other-bucket', 'prefix' => 'projects']);

        $resolver = new DynamicPathResolver();
        $registry = new VolumeRegistry($this->makeProvider([], $s3Root), new FlysystemStorageFactory($resolver), $resolver);
        $ops = new FileOperationsService(
            $registry,
            new PathNormalizer(),
            new FilenameNormalizer(),
            new TrashManager($registry, new PathNormalizer(), new EventDispatcher(), new FileSecurityGuard()),
            new NullMetadataStorage(),
            new EventDispatcher(),
        );

        $refl = new ReflectionMethod(FileOperationsService::class, 'assertNotMovingFolderIntoSelf');
        // Different bucket: allowed
        $refl->invoke($ops, $s3Root, $s3Other, 'projects', 'projects', false);

        // Overlapping prefix in same bucket: must throw cannot_move_into_self
        $this->expectException(FileManagerException::class);
        $refl->invoke($ops, $s3Root, $s3Sub, 'projects', 'projects', false);
    }

    public function testQuotaLockAndChunkOverwriteTargetSize(): void
    {
        $volDir = $this->tempDir . DIRECTORY_SEPARATOR . 'quota_vol';
        mkdir($volDir, 0777, true);
        file_put_contents($volDir . DIRECTORY_SEPARATOR . 'existing.txt', str_repeat('x', 500));

        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $volDir);
        $profileOverwrite = $this->makeProfile('default', [], '1K', 'overwrite');
        $profileRename = $this->makeProfile('rename_p', [], '1K', 'rename');
        $provider = $this->makeProvider(['default' => $profileOverwrite, 'rename_p' => $profileRename], $vol);
        $resolver = new DynamicPathResolver();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory($resolver), $resolver);
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $quota = new StorageQuotaChecker($registry, new PathNormalizer(), $cache);

        // Prime quota cache then mutate inside withQuotaLock + invalidateQuotaCache
        self::assertSame(500, $quota->getUsedBytes($profileOverwrite, 'local'));
        $result = $quota->withQuotaLock($profileOverwrite, 'local', static function () use ($volDir, $quota, $profileOverwrite): string {
            file_put_contents($volDir . DIRECTORY_SEPARATOR . 'added.txt', str_repeat('y', 200));
            $quota->invalidateQuotaCache($profileOverwrite, 'local');

            return 'locked-ok';
        });
        self::assertSame('locked-ok', $result);
        self::assertSame(700, $quota->getUsedBytes($profileOverwrite, 'local'));

        $uploadManager = new UploadManager(
            $registry,
            new PathNormalizer(),
            new FilenameNormalizer(),
            new EventDispatcher(),
            new SvgSanitizer(),
            null,
            new FileSecurityGuard(),
            new ImageOptimizer(),
            $quota,
        );
        $reflOverwrite = new ReflectionMethod(UploadManager::class, 'resolveOverwriteTargetSize');
        self::assertSame(500, $reflOverwrite->invoke($uploadManager, $profileOverwrite, 'local', '', 'existing.txt'));
        self::assertSame(0, $reflOverwrite->invoke($uploadManager, $profileRename, 'local', '', 'existing.txt'));
    }

    public function testFileManagerTwigExtensionRejectsPercentEncodedControlsAndScrubsBidiNames(): void
    {
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $ext = new FileManagerTwigExtension($urlGen);

        $rloEncoded = $ext->describeFieldItem('/uploads/report%E2%80%AEgnp.exe');
        self::assertNull($rloEncoded['href']);
        self::assertNull($rloEncoded['imgSrc']);
        self::assertStringNotContainsString("\u{202E}", $rloEncoded['name']);

        $nulEncoded = $ext->describeFieldItem('/uploads/photo%00.jpg');
        self::assertNull($nulEncoded['href']);
        self::assertNull($nulEncoded['imgSrc']);

        $rawBidi = $ext->describeFieldItem("invoice\u{202E}gpj.exe");
        self::assertNull($rawBidi['href']);
        self::assertStringNotContainsString("\u{202E}", $rawBidi['name']);
    }

    public function testAutoInjectDialogHostSubscriberRawTextCommentsAndSMaxAge(): void
    {
        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div id="liveux-fm-dialog-host" data-controller="liveux--file-manager-bundle--dialog"></div>',
        ]));
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default');

        // 1. Closed <script> containing "<textarea></body>" must NOT block injection before real </body>
        $html1 = '<html><body><script>const s = "<textarea></body>";</script><p>Hi</p></body></html>';
        $resp1 = new Response($html1, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $resp1));
        self::assertStringContainsString('liveux-fm-dialog-host', (string) $resp1->getContent());

        // 2. Trailing HTML comment containing </body> must NOT receive injected markup inside comment
        $html2 = '<html><body><p>Hi</p></body><!-- </body> --></html>';
        $resp2 = new Response($html2, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $resp2));
        $out2 = (string) $resp2->getContent();
        $hostPos = strpos($out2, 'liveux-fm-dialog-host');
        $commentPos = strpos($out2, '<!-- </body> -->');
        self::assertIsInt($hostPos);
        self::assertIsInt($commentPos);
        self::assertLessThan($commentPos, $hostPos);

        // 3. s-maxage on voter-protected profile skips injection
        $voterProfile = $this->makeProfile('default');
        $provider = $this->makeProvider(['default' => $voterProfile], new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir));
        $protectedSub = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $provider);
        $resp3 = new Response('<html><body><p>Cached</p></body></html>', 200, [
            'Content-Type' => 'text/html',
            'Cache-Control' => 's-maxage=300',
        ]);
        $protectedSub->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $resp3));
        self::assertStringNotContainsString('liveux-fm-dialog-host', (string) $resp3->getContent());
    }

    public function testCacheInvalidationArchiveExtractionAndDynamicS3Templates(): void
    {
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $s3Admin = new VolumeConfig('s3_admin', 'Admin S3', 'network', 'AwsS3', null, null, false, ['bucket' => 'app-media', 'prefix' => 'tenants']);
        $s3TenantRaw = new VolumeConfig('s3_tenant', 'Tenant S3', 'network', 'AwsS3', null, null, false, ['bucket' => 'app-media', 'prefix' => 'tenants/{user_id}']);
        $adminProfile = $this->makeProfile('admin', [], null, 'rename', ['s3_admin']);
        $tenantProfile = $this->makeProfile('tenant', [], null, 'rename', ['s3_tenant']);
        $provider = $this->makeProvider(
            ['admin' => $adminProfile, 'tenant' => $tenantProfile],
            $s3Admin,
            ['s3_admin' => $s3Admin, 's3_tenant' => $s3TenantRaw],
        );

        $normalizer = new PathNormalizer();
        $listener = new CacheInvalidationListener($cache, $normalizer, $provider);

        // Seed descendant cache entry for admin volume and tenant profile cache entry
        $adminRoot = $normalizer->buildCanonicalKey($s3Admin, '', $this->tempDir);
        $adminScope = $cache->resolveVolumeScopeForRoot($adminRoot);
        $calls = 0;
        $cache->remember($adminProfile, $normalizer->buildCanonicalKey($s3Admin, 'sub/deep', $this->tempDir), static function () use (&$calls): string {
            ++$calls;

            return 'v1';
        }, 3600, $adminScope);
        $tenantCalls = 0;
        $cache->remember($tenantProfile, 's3://app-media::tenants/42', static function () use (&$tenantCalls): string {
            ++$tenantCalls;

            return 't1';
        }, 3600);

        // Archive extraction completion dispatches FolderCreatedEvent(..., fromArchiveExtraction: true)
        $listener->onMutate(new FolderCreatedEvent($adminProfile, $s3Admin, '', $adminRoot, true));

        $cache->remember($adminProfile, $normalizer->buildCanonicalKey($s3Admin, 'sub/deep', $this->tempDir), static function () use (&$calls): string {
            ++$calls;

            return 'v2';
        }, 3600, $adminScope);
        $cache->remember($tenantProfile, 's3://app-media::tenants/42', static function () use (&$tenantCalls): string {
            ++$tenantCalls;

            return 't2';
        }, 3600);

        self::assertSame(2, $calls, 'Archive extraction must invalidate cached descendant directories in the same volume scope');
        self::assertSame(2, $tenantCalls, 'Static S3 mutation must invalidate overlapping dynamic {user_id} S3 tenant profile cache');
    }

    public function testRootAnchoredTrashGuardAndJsonMetadataVolumeRootParity(): void
    {
        $guard = new FileSecurityGuard();
        $profile = $this->makeProfile('default', [], null, 'rename', ['local'], ['enabled' => true, 'directory' => 'trash']);

        // Nested unrelated "trash" folder MUST be allowed (root-anchored trash only)
        $guard->assertAccessiblePath('projects/2026/trash/draft.jpg', $profile);
        self::assertFalse($guard->isChildSegmentIgnored('projects/2026/trash', 'trash', $profile));

        // Root-anchored trash MUST be blocked
        self::assertTrue($guard->isChildSegmentIgnored('trash/secret.jpg', 'secret.jpg', $profile));
        $blocked = false;
        try {
            $guard->assertAccessiblePath('trash/secret.jpg', $profile);
        } catch (InvalidPathException) {
            $blocked = true;
        }
        self::assertTrue($blocked);

        // JsonFileMetadataStorage handles volume-root canonical key 'local::' cleanly
        $metaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'meta.json';
        $jsonStorage = new JsonFileMetadataStorage($metaPath);
        $jsonStorage->save('local::a.png', new FolderMetadata(alt: 'Alpha'));
        $jsonStorage->save('local::docs/b.png', new FolderMetadata(alt: 'Beta'));

        self::assertCount(2, $jsonStorage->getByPrefix('local::'));
        $jsonStorage->copyPrefix('local::', 'backup::');
        self::assertSame('Alpha', $jsonStorage->get('backup::a.png')->alt);
        self::assertSame('Beta', $jsonStorage->get('backup::docs/b.png')->alt);
        $jsonStorage->relocatePrefix('backup::', 'archive::');
        self::assertSame('Beta', $jsonStorage->get('archive::docs/b.png')->alt);
        $jsonStorage->delete('archive::');
        self::assertCount(0, $jsonStorage->getByPrefix('archive::'));
    }

    public function testTrashPurgeAntiOracleAndConfigHardeningAndMultilingualLabels(): void
    {
        // 1. FileManagerVoter & FileManagerAuthorizer deny EXTRACT_ZIP when zip.allow_extract is false
        //    even when permissions.extract_zip holds 'ROLE_ADMIN' (preserved across multi-file merges)
        $processor = new Processor();
        $processed = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'strict' => [
                    'zip' => ['allow_extract' => false],
                    'permissions' => ['extract_zip' => 'ROLE_ADMIN'],
                ],
            ],
            'volumes' => [
                'ürünler_2026' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir],
                'документы' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir],
                'αρχεία' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir],
            ],
        ]]);
        self::assertSame('ROLE_ADMIN', $processed['profiles']['strict']['permissions']['extract_zip']);

        $yamlProvider = new YamlConfigProvider($processed['defaults'], $processed['volumes'], $processed['profiles']);
        $strictProfile = $yamlProvider->getProfile('strict');
        $authorizer = new \LiveUX\FileManagerBundle\Security\FileManagerAuthorizer();
        self::assertFalse($authorizer->isGranted($strictProfile, FileManagerPermissions::EXTRACT_ZIP));
        self::assertSame('Ürünler 2026', $yamlProvider->getVolume('ürünler_2026')->label);
        self::assertSame('Документы', $yamlProvider->getVolume('документы')->label);
        self::assertSame('Αρχεία', $yamlProvider->getVolume('αρχεία')->label);

        // 2. ThumbnailController only marks public cache immutable when ?v= is present
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $provider = $this->makeProvider(['default' => $this->makeProfile('default')], $vol);
        $resolver = new DynamicPathResolver();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory($resolver), $resolver);
        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $thumbCtrl = new ThumbnailController($provider, $registry, new PathNormalizer(), $thumbManager, null, new FileSecurityGuard(), new MessageTranslator(), new SvgSanitizer());
        $reflHeaders = new ReflectionMethod(ThumbnailController::class, 'applyBrowserCacheHeaders');
        $unversionedResp = new Response();
        $reflHeaders->invoke($thumbCtrl, $unversionedResp, 'etag1', false, true, false);
        self::assertStringNotContainsString('immutable', (string) $unversionedResp->headers->get('Cache-Control'));
        self::assertStringContainsString('must-revalidate', (string) $unversionedResp->headers->get('Cache-Control'));

        $versionedResp = new Response();
        $reflHeaders->invoke($thumbCtrl, $versionedResp, 'etag2', false, true, true);
        self::assertStringContainsString('immutable', (string) $versionedResp->headers->get('Cache-Control'));

        // 3. FileManagerPickerType caps huge JSON item counts to prevent CPU DoS
        $factory = Forms::createFormFactoryBuilder()->addType(new FileManagerPickerType(configProvider: $provider, messageTranslator: new MessageTranslator()))->getFormFactory();
        $form = $factory->create(FileManagerPickerType::class, null, ['multiple' => true, 'max_files' => 5]);
        $dosPayload = json_encode(array_fill(0, 600, '/uploads/a.jpg'));
        $form->submit($dosPayload);
        self::assertFalse($form->isValid());

        // 4. EasyAdmin FileManagerField exposes fluent setMobileMode() (optional dependency: verify statically)
        $fieldSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Bridge/EasyAdmin/FileManagerField.php');
        self::assertStringContainsString('function setMobileMode(?string $mobileMode): self', $fieldSrc);
        self::assertStringContainsString("setFormTypeOption('mobile_mode', \$mobileMode)", $fieldSrc);

        // 5. BrowserComponent::isAllowed() enforces ancestor permissions
        $denyAncestorAuth = new class () implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, ?\Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool
            {
                if ($subject instanceof FileManagerSubject && $attribute === FileManagerPermissions::UPLOAD) {
                    return $subject->path !== 'restricted_parent';
                }

                return true;
            }
        };
        $trashManager = new TrashManager($registry, new PathNormalizer(), new EventDispatcher(), new FileSecurityGuard());
        $listerWithDeny = new DirectoryLister($registry, new PathNormalizer(), new DirectoryCacheManager(new ArrayAdapter()), new NullMetadataStorage(), new FileSecurityGuard(), $denyAncestorAuth, true);
        $comp = new BrowserComponent(
            $provider,
            $registry,
            $listerWithDeny,
            $trashManager,
            $thumbManager,
            new ZipArchiveService($registry, new PathNormalizer(), new FileSecurityGuard()),
            new PathNormalizer(),
            $this->createStub(UrlGeneratorInterface::class),
            new IconSetRegistry($this->tempDir),
            new CsrfRequestValidator(null),
            null,
            $denyAncestorAuth,
        );
        $comp->profile = 'default';
        $comp->volume = 'local';
        $comp->path = 'restricted_parent/child';
        $reflIsAllowed = new ReflectionMethod(BrowserComponent::class, 'isAllowed');
        self::assertFalse($reflIsAllowed->invoke($comp, FileManagerPermissions::UPLOAD));

        // 6. ApiController::handlePurgeTrash returns identical 422 errors.trash_entry_not_found with interpolated %id% for missing and unauthorized IDs
        $uploadForApi = new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher());
        $api = new ApiController(
            $provider,
            $registry,
            new DirectoryLister($registry, new PathNormalizer(), new DirectoryCacheManager(new ArrayAdapter()), new NullMetadataStorage(), new FileSecurityGuard()),
            new FileOperationsService($registry, new PathNormalizer(), new FilenameNormalizer(), $trashManager, new NullMetadataStorage(), new EventDispatcher()),
            new RemoteUrlImporter($uploadForApi, $registry),
            $trashManager,
            new DirectoryCacheManager(new ArrayAdapter()),
            $thumbManager,
            new PathNormalizer(),
            $this->createStub(UrlGeneratorInterface::class),
            new Environment(new ArrayLoader([])),
            new CsrfRequestValidator(null),
            null,
            null,
            new FileSecurityGuard(),
            null,
            new MessageTranslator(),
        );
        $purgeReq = Request::create('/fm/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'action' => 'purgeTrash',
            'profile' => 'default',
            'volume' => 'local',
            'trashId' => '20260101_000000_deadbeef',
        ]));
        $purgeResp = $api->action($purgeReq);
        self::assertSame(422, $purgeResp->getStatusCode());
        $purgeData = json_decode((string) $purgeResp->getContent(), true);
        self::assertIsArray($purgeData);
        self::assertStringContainsString('20260101_000000_deadbeef', (string) ($purgeData['error'] ?? ''));
        self::assertStringNotContainsString('%id%', (string) ($purgeData['error'] ?? ''));
    }

    /**
     * @param array<string, mixed> $thumbnail
     * @param list<string>         $volumes
     * @param array<string, mixed> $trash
     */
    private function makeProfile(
        string $name,
        array $thumbnail = [],
        ?string $storageQuota = null,
        string $overwritePolicy = 'rename',
        array $volumes = ['local'],
        array $trash = ['enabled' => true, 'directory' => '.trash'],
    ): ProfileConfig {
        return new ProfileConfig(
            name: $name,
            basePath: $this->tempDir,
            theme: 'bootstrap5',
            locale: null,
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
            uploadAllow: [],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: $overwritePolicy,
            chunkUpload: ['enabled' => true, 'chunk_size' => '2M'],
            naming: ['transformer' => 'slug', 'prepend' => '', 'append' => ''],
            imageOptimization: ['enabled' => false],
            thumbnail: array_merge(['enabled' => true], $thumbnail),
            trash: $trash,
            zip: ['enabled' => true, 'allow_extract' => false],
            ui: [],
            permissions: [
                'view' => true,
                'upload' => true,
                'delete' => true,
                'trash_restore' => true,
                'trash_purge' => true,
            ],
            security: ['voter_enabled' => true],
            cache: ['enabled' => true, 'ttl' => 60],
            metadata: ['driver' => 'json_file'],
            storageQuota: $storageQuota,
            duplicateDetection: ['enabled' => false],
            share: ['enabled' => true],
            ai: [],
            usageGuard: [],
            imageConstraints: [],
        );
    }

    /**
     * @param array<string, ProfileConfig> $profiles
     * @param array<string, VolumeConfig>  $extraVolumes
     */
    private function makeProvider(array $profiles, VolumeConfig $volume, array $extraVolumes = []): ConfigProviderInterface
    {
        $volumes = array_merge([$volume->name => $volume], $extraVolumes);

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
                if (!isset($this->volumes[$name])) {
                    throw new \InvalidArgumentException(sprintf('Unknown volume "%s".', $name));
                }

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
