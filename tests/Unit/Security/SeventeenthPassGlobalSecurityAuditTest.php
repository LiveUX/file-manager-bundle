<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use Doctrine\DBAL\DriverManager;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\Controller\UploadController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\EventListener\ThumbnailCleanupListener;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
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
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
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
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Debug\TraceableAccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ZipArchive;

final class SeventeenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_p17_' . bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testDetectExternalVotersPassCatchesAllPass17DiWiringPatterns(): void
    {
        $pass = new DetectExternalVotersPass();

        // 0. Clean baseline: standard AuthorizationChecker + AccessDecisionManager + TaggedIterator('security.voter') + FileManagerVoter
        $clean = new ContainerBuilder();
        $clean->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $clean->setDefinition('fm.voter', (new Definition(FileManagerVoter::class))->addTag('security.voter'));
        $clean->setDefinition('security.access.decision_manager', new Definition(AccessDecisionManager::class, [
            new TaggedIteratorArgument('security.voter'),
        ]));
        $clean->setDefinition('security.authorization_checker', new Definition(AuthorizationChecker::class, [
            new Reference('security.token_storage'),
            new Reference('security.access.decision_manager'),
        ]));
        $pass->process($clean);
        self::assertFalse($clean->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 1. AuthorizationChecker wired to a custom AccessDecisionManager under a non-default service ID
        $c1 = new ContainerBuilder();
        $c1->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c1->setDefinition('app.custom_dm', new Definition(\stdClass::class));
        $c1->setDefinition('security.authorization_checker', new Definition(AuthorizationChecker::class, [
            new Reference('security.token_storage'),
            new Reference('app.custom_dm'),
        ]));
        $pass->process($c1);
        self::assertTrue($c1->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 2. Factory-created voter masquerading with FileManagerVoter class
        $c2 = new ContainerBuilder();
        $c2->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c2->setDefinition('fm.factory_voter', (new Definition(FileManagerVoter::class))
            ->setFactory([new Reference('app.voter_factory'), 'create'])
            ->addTag('security.voter'));
        $pass->process($c2);
        self::assertTrue($c2->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 3. Synthetic AuthorizationChecker service
        $c3 = new ContainerBuilder();
        $c3->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c3->setDefinition('security.authorization_checker', (new Definition(AuthorizationChecker::class))->setSynthetic(true));
        $pass->process($c3);
        self::assertTrue($c3->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 4. TraceableAccessDecisionManager wrapping an untagged custom inner decision manager by Reference
        $c4 = new ContainerBuilder();
        $c4->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c4->setDefinition('app.inner_custom_dm', new Definition(\stdClass::class));
        $c4->setDefinition('security.access.decision_manager', new Definition(TraceableAccessDecisionManager::class, [
            new Reference('app.inner_custom_dm'),
        ]));
        $pass->process($c4);
        self::assertTrue($c4->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 5. AccessDecisionManager with custom-tag TaggedIteratorArgument or inline voter iterator Definition
        $c5 = new ContainerBuilder();
        $c5->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c5->setDefinition('security.access.decision_manager', new Definition(AccessDecisionManager::class, [
            new TaggedIteratorArgument('app.custom_voter'),
        ]));
        $pass->process($c5);
        self::assertTrue($c5->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        $c5b = new ContainerBuilder();
        $c5b->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c5b->setDefinition('security.access.decision_manager', new Definition(AccessDecisionManager::class, [
            new Definition(\ArrayIterator::class, [[new Reference('app.hidden_voter')]]),
        ]));
        $pass->process($c5b);
        self::assertTrue($c5b->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 6. AccessDecisionManager with addMethodCall() voter injection
        $c6 = new ContainerBuilder();
        $c6->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c6->setDefinition('security.access.decision_manager', (new Definition(AccessDecisionManager::class, [
            new IteratorArgument([new Reference('core.role_voter')]),
        ]))->addMethodCall('addVoter', [new Reference('app.secret_voter')]));
        $c6->setDefinition('core.role_voter', new Definition(RoleVoter::class));
        $pass->process($c6);
        self::assertTrue($c6->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 7. Inline Definition wired directly as DirectoryLister::$authChecker
        $c7 = new ContainerBuilder();
        $listerDef7 = new Definition(DirectoryLister::class);
        $listerDef7->setArgument('$authChecker', new Definition(\stdClass::class));
        $c7->setDefinition(DirectoryLister::class, $listerDef7);
        $pass->process($c7);
        self::assertTrue($c7->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));

        // 8. Decorator targeting an alias chain (`security.authorization_checker` -> `app.real_checker`)
        $c8 = new ContainerBuilder();
        $c8->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $c8->setDefinition('app.real_checker', new Definition(AuthorizationChecker::class));
        $c8->setAlias('security.authorization_checker', 'app.real_checker');
        $c8->setDefinition('app.decorator', (new Definition(\stdClass::class))->setDecoratedService('app.real_checker'));
        $pass->process($c8);
        self::assertTrue($c8->getDefinition(DirectoryLister::class)->getArgument('hasExternalVoters'));
    }

    public function testSvgSanitizerBlocksDuplicateShadowedXmlIdAndWhitespacePaddedUseCycles(): void
    {
        $sanitizer = new SvgSanitizer();

        // 1. Detached <use> subtree decoy shadowing real cyclic <g id="bomb">
        $detachedShadowSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg">
  <use id="self" href="#self"><g id="bomb"><rect width="1" height="1"/></g></use>
  <g id="bomb"><use href="#bomb"/></g>
  <use href="#bomb"/>
</svg>
SVG;
        $out1 = $sanitizer->sanitize($detachedShadowSvg);
        self::assertStringNotContainsString('<g id="bomb"><use', $out1, 'Detached <use> decoy must not shadow live cyclic target');
        self::assertStringNotContainsString('href="#self"', $out1);

        // 2. xml:id decoy shadowing real id="bomb" cycle and exponential DAG (12 * 10 * 10 = 1200+ expanded nodes)
        $g0 = '<g id="g0">' . str_repeat('<path d="M0 0h1v1z"/>', 12) . '</g>';
        $g1 = '<g id="g1">' . str_repeat('<use href="#g0"/>', 10) . '</g>';
        $g2 = '<g id="g2">' . str_repeat('<use href="#g1"/>', 10) . '</g>';
        $xmlIdShadowSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs>'
            . '<rect xml:id="g2" width="1" height="1"/>' . $g0 . $g1 . $g2
            . '</defs><use href="#g2"/></svg>';
        $out2 = $sanitizer->sanitize($xmlIdShadowSvg);
        self::assertStringNotContainsString('href="#g2"', $out2, 'xml:id decoy must not shadow exponential DAG budget check');

        // 3. Whitespace-padded id=" bomb " decoy shadowing exact id="bomb" cycle
        $wsShadowSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg">
  <rect id=" bomb " width="1" height="1"/>
  <g id="bomb"><use href="#bomb"/></g>
  <use href="#bomb"/>
</svg>
SVG;
        $out3 = $sanitizer->sanitize($wsShadowSvg);
        self::assertStringNotContainsString('<g id="bomb"><use', $out3, 'Whitespace-padded id decoy must not shadow exact id cycle');

        // 4. Dual id/xml:id self-cycle on same <use>
        $dualSelfSvg = '<svg xmlns="http://www.w3.org/2000/svg"><use id="a" xml:id="b" href="#b"/></svg>';
        $out4 = $sanitizer->sanitize($dualSelfSvg);
        self::assertStringNotContainsString('<use', $out4);

        // 5. Benign non-cyclic <use> remains intact
        $benignSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><circle id="dot" r="5"/></defs><use href="#dot"/></svg>';
        $out5 = $sanitizer->sanitize($benignSvg);
        self::assertStringContainsString('<use href="#dot"', $out5);
    }

    public function testAutoInjectDialogHostLinearScannerAndSharedCacheGuard(): void
    {
        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div id="liveux-fm-dialog-host" data-profile="{{ profile }}"></div>',
        ]));

        $publicProfile = $this->makeProfile('public', $this->tempDir, ['view' => true]);
        $voterProfile = $this->makeProfile('secured', $this->tempDir, ['view' => 'ROLE_ADMIN'], voterEnabled: true);

        $publicSubscriber = new AutoInjectDialogHostSubscriber($twig, true, 'public', 1050, $this->makeConfigProvider([$publicProfile]));
        $voterSubscriber = new AutoInjectDialogHostSubscriber($twig, true, 'secured', 1050, $this->makeConfigProvider([$voterProfile]), $this->makeAuthChecker(true));

        // 1. Quoted attributes containing `<script>` or `<!--` + trailing whitespace `</body >` + nested `<template>`
        $html = '<html><head><meta name="desc" content="Avoid <script> tags"><div data-x="<!--"></div></head>'
            . '<body><template><template></template></body></template><main>Hi</main></body ></html>';
        $res1 = $this->dispatchResponseEvent($publicSubscriber, $html);
        self::assertStringContainsString('<main>Hi</main>' . "\n" . '<div id="liveux-fm-dialog-host"', $res1->getContent() ?: '');

        // 2. 1.2 MB inline <script> (exceeding PHP's default pcre.backtrack_limit of 1,000,000) still injects cleanly
        $hugeScript = str_repeat('a', 1_200_000);
        $hugeHtml = '<html><body><script>const x = "' . $hugeScript . '</body>";</script><p>End</p></body></html>';
        $res2 = $this->dispatchResponseEvent($publicSubscriber, $hugeHtml);
        self::assertStringContainsString('<p>End</p>' . "\n" . '<div id="liveux-fm-dialog-host"', $res2->getContent() ?: '');

        // 3. Shared-cacheable responses (Expires without Cache-Control:private, Surrogate-Control, CDN-Cache-Control) must skip injection on voter-protected profiles
        $sharedRes = new Response('<html><body><p>Cached</p></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $sharedRes->setExpires(new \DateTimeImmutable('+1 hour'));
        $sharedRes->headers->remove('Cache-Control');
        self::assertTrue($sharedRes->isCacheable());
        $this->dispatchResponseEvent($voterSubscriber, null, $sharedRes);
        self::assertStringNotContainsString('liveux-fm-dialog-host', (string) $sharedRes->getContent());

        $surrogateRes = new Response('<html><body><p>CDN</p></body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Surrogate-Control' => 'max-age=120',
        ]);
        $this->dispatchResponseEvent($voterSubscriber, null, $surrogateRes);
        self::assertStringNotContainsString('liveux-fm-dialog-host', (string) $surrogateRes->getContent());

        $cdnRes = new Response('<html><body><p>RFC9213</p></body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'CDN-Cache-Control' => 'max-age=300',
        ]);
        $this->dispatchResponseEvent($voterSubscriber, null, $cdnRes);
        self::assertStringNotContainsString('liveux-fm-dialog-host', (string) $cdnRes->getContent());

        // Explicit `private, max-age=60` MUST still inject for authorized user
        $privateRes = new Response('<html><body><p>Private</p></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $privateRes->setPrivate()->setMaxAge(60);
        $this->dispatchResponseEvent($voterSubscriber, null, $privateRes);
        self::assertStringContainsString('liveux-fm-dialog-host', (string) $privateRes->getContent());
    }

    public function testTwigExtensionExactBundleRouteCheckSignedShareLinksAndDoubleEncodedExt(): void
    {
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturnCallback(static function (string $name, array $params = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
            $path = match ($name) {
                'liveux_file_manager_download' => '/_liveux-file-manager/download',
                'liveux_file_manager_share' => '/_liveux-file-manager/share',
                'liveux_file_manager_thumb' => '/_liveux-file-manager/thumb',
                default => '/' . $name,
            };

            return $referenceType === UrlGeneratorInterface::ABSOLUTE_URL
                ? 'https://cdn.example.com' . $path
                : $path;
        });
        $ext = new FileManagerTwigExtension($urlGen);

        // Arbitrary `/custom/download?path=xss.html` must NOT be trusted as bundle download endpoint
        $fakeDl = $ext->describeFieldItem('/custom/download?path=xss.html');
        self::assertNull($fakeDl['href']);
        self::assertNull($fakeDl['imgSrc']);

        // Active blocked extensions (.html / .php) even on real bundle `/download` or `/share` get href=null
        $bundleHtml = $ext->describeFieldItem('/_liveux-file-manager/download?profile=default&path=xss.html');
        self::assertNull($bundleHtml['href']);

        // Double-encoded active extension `%252ehtml` rejected
        $doubleEnc = $ext->describeFieldItem('/uploads/evil%252ehtml');
        self::assertNull($doubleEnc['href']);
        self::assertNull($doubleEnc['imgSrc']);

        // Legitimate absolute signed ShareLink image URL renders filename, href, and imgSrc
        $signedImg = $ext->describeFieldItem('https://cdn.example.com/_liveux-file-manager/share?profile=default&volume=local&path=gallery%2Fphoto.jpg&_hash=abc');
        self::assertSame('photo.jpg', $signedImg['name']);
        self::assertNotNull($signedImg['href']);
        self::assertNotNull($signedImg['imgSrc']);

        // Legitimate absolute signed ShareLink SVG keeps href (attachment-forced) while keeping imgSrc null
        $signedSvg = $ext->describeFieldItem('https://cdn.example.com/_liveux-file-manager/share?profile=default&path=icons%2Flogo.svg&_hash=abc');
        self::assertSame('logo.svg', $signedSvg['name']);
        self::assertNotNull($signedSvg['href']);
        self::assertNull($signedSvg['imgSrc']);
    }

    public function testFileManagerPickerTypeNamedVolumeTrashAndInvalidUtf8NoSilentWipe(): void
    {
        $mediaDir = $this->tempDir . DIRECTORY_SEPARATOR . 'media';
        mkdir($mediaDir, 0777, true);
        $mediaVol = new VolumeConfig(name: 'media', label: 'Media', group: 'local', driver: 'LocalFileSystem', path: $mediaDir, urlPrefix: '/media');
        $profile = $this->makeProfile('default', $this->tempDir, [], false, [
            'urlPrefix' => '/uploads',
            'volumes' => ['media'],
            'trash' => ['enabled' => true, 'directory' => 'recycle', 'retention_days' => 30, 'max_size' => '500M'],
        ]);
        $configProvider = $this->makeConfigProvider([$profile], [$mediaVol]);
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        $pickerType = new FileManagerPickerType($configProvider, null, $registry);
        $formFactory = Forms::createFormFactoryBuilder()->addType($pickerType)->getFormFactory();

        // 1. Named volume custom single-segment trash directory `/media/recycle/secret.jpg` MUST be rejected
        $fTrash = $formFactory->create(FileManagerPickerType::class, null, ['profile' => 'default', 'return_type' => 'url']);
        $fTrash->submit('/media/recycle/secret.jpg');
        self::assertFalse($fTrash->isValid(), 'Named-volume custom single-segment trash URL must be rejected');

        // 2. Legitimate subfolder `/uploads/photos/recycle/ok.jpg` under configured `/uploads` prefix remains allowed
        $fLegit = $formFactory->create(FileManagerPickerType::class, null, ['profile' => 'default', 'return_type' => 'url']);
        $fLegit->submit('/uploads/photos/recycle/ok.jpg');
        self::assertTrue($fLegit->isValid(), 'Legitimate nested folder matching trash name must remain allowed');

        // 3. Invalid UTF-8 bytes in multiple array submission MUST fail validation instead of silently wiping to []
        $fUtf8 = $formFactory->create(FileManagerPickerType::class, ['/uploads/keep.jpg'], ['profile' => 'default', 'multiple' => true]);
        $fUtf8->submit(["/uploads/keep.jpg", "\xFF\xFEinvalid"]);
        self::assertFalse($fUtf8->isValid(), 'Invalid UTF-8 array submission must fail validation instead of silently wiping gallery');
    }

    public function testDoctrineDbalMetadataStorageRootTransitionsTransactionalUpsertAndSaveMany(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_p17_meta', true);

        // 1. Root -> Subfolder relocatePrefix must preserve `/` separator
        $dbal->save('local::docs/a.png', FolderMetadata::fromArray(['title' => 'Alpha']));
        $dbal->relocatePrefix('local::', 'local::archive');
        self::assertSame('Alpha', $dbal->get('local::archive/docs/a.png')->title);
        self::assertTrue($dbal->get('local::archivedocs/a.png')->isEmpty());

        // 2. Subfolder -> Root relocatePrefix must NOT create double slash `local::/docs/a.png`
        $dbal->relocatePrefix('local::archive', 'local::');
        self::assertSame('Alpha', $dbal->get('local::docs/a.png')->title);
        self::assertTrue($dbal->get('local::/docs/a.png')->isEmpty());

        // 3. copyPrefix Root <-> Subfolder
        $dbal->copyPrefix('local::', 'local::backup');
        self::assertSame('Alpha', $dbal->get('local::backup/docs/a.png')->title);
        $dbal->copyPrefix('local::backup', 'local::');
        self::assertSame('Alpha', $dbal->get('local::docs/a.png')->title);

        // 4. Native atomic UPSERT works inside an active transaction without aborting
        $conn->transactional(static function () use ($dbal): void {
            $dbal->save('local::tx.jpg', FolderMetadata::fromArray(['title' => 'v1']));
            $dbal->save('local::tx.jpg', FolderMetadata::fromArray(['title' => 'v2']));
        });
        self::assertSame('v2', $dbal->get('local::tx.jpg')->title);

        // 5. Batch saveMany on both DBAL and JSON metadata storages
        $dbal->saveMany([
            'local::b1.jpg' => FolderMetadata::fromArray(['alt' => 'One']),
            'local::b2.jpg' => FolderMetadata::fromArray(['alt' => 'Two']),
            'local::tx.jpg' => new FolderMetadata(),
        ]);
        self::assertSame('One', $dbal->get('local::b1.jpg')->alt);
        self::assertSame('Two', $dbal->get('local::b2.jpg')->alt);
        self::assertTrue($dbal->get('local::tx.jpg')->isEmpty());

        $jsonStore = new JsonFileMetadataStorage($this->tempDir . DIRECTORY_SEPARATOR . 'meta.json');
        $jsonStore->saveMany([
            'local::j1.jpg' => FolderMetadata::fromArray(['alt' => 'J1']),
            'local::j2.jpg' => FolderMetadata::fromArray(['alt' => 'J2']),
        ]);
        $jsonStore->reset();
        self::assertSame('J1', $jsonStore->get('local::j1.jpg')->alt);
        self::assertSame('J2', $jsonStore->get('local::j2.jpg')->alt);
    }

    public function testDirectoryCacheManagerPolicyFingerprintIsolatesFilterPolicies(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'cache_iso';
        mkdir($root, 0777, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'public.txt', 'ok');
        file_put_contents($root . DIRECTORY_SEPARATOR . '.env', 'SECRET=1');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'secret.bak', 'BAK');

        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $adminProf = $this->makeProfile('shared', $root, [], false, [
            'ignoreDotFiles' => false,
            'excludePatterns' => [],
            'cache' => ['enabled' => true, 'ttl' => 3600],
        ]);
        $restrictedProf = $this->makeProfile('shared', $root, [], false, [
            'ignoreDotFiles' => true,
            'excludePatterns' => ['/\.bak$/i'],
            'cache' => ['enabled' => true, 'ttl' => 3600],
        ]);

        self::assertNotSame(
            $cache->buildProfilePolicyFingerprint($adminProf),
            $cache->buildProfilePolicyFingerprint($restrictedProf),
        );

        $registry = new VolumeRegistry($this->makeConfigProvider([$adminProf]), new FlysystemStorageFactory(new DynamicPathResolver()));
        $lister = new DirectoryLister($registry, new PathNormalizer(), $cache, new NullMetadataStorage(), new FileSecurityGuard());

        // Admin warms the cache first (sees .env and secret.bak)
        $adminListing = $lister->list($adminProf);
        $adminNames = array_map(static fn ($i) => $i->name, $adminListing->items);
        self::assertContains('.env', $adminNames);
        self::assertContains('secret.bak', $adminNames);

        // Restricted profile under same profile name MUST NOT receive admin's poisoned cached listing
        $restrictedListing = $lister->list($restrictedProf);
        $restrictedNames = array_map(static fn ($i) => $i->name, $restrictedListing->items);
        self::assertSame(['public.txt'], $restrictedNames);
    }

    public function testStorageQuotaCheckerReentrantLockAndZipPostLockEventDispatchAndPathNormalizer(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'quota_reent';
        mkdir($root, 0777, true);

        $profile = $this->makeProfile('q', $root, ['view' => true, 'upload' => true, 'extract_zip' => true, 'create_folder' => true], false, [
            'storageQuota' => '5M',
            'zip' => ['allow_extract' => true, 'max_extract_files' => 20, 'max_extract_size' => '5M'],
        ]);
        $registry = new VolumeRegistry($this->makeConfigProvider([$profile]), new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $quotaChecker = new StorageQuotaChecker($registry, $normalizer, $cache);

        // 1. Re-entrant withQuotaLock must NOT self-deadlock
        $nestedResult = $quotaChecker->withQuotaLock($profile, null, static function () use ($quotaChecker, $profile): string {
            return $quotaChecker->withQuotaLock($profile, null, static fn (): string => 'reentrant-ok');
        });
        self::assertSame('reentrant-ok', $nestedResult);

        // 2. ZipArchiveService dispatches FileUploadedEvent AFTER releasing quota lock (listener can safely re-acquire)
        $zipPath = $root . DIRECTORY_SEPARATOR . 'sample.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('hello.txt', 'world');
        $zip->close();

        $listenerRan = false;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(FileUploadedEvent::class, static function () use ($quotaChecker, $profile, &$listenerRan): void {
            $quotaChecker->withQuotaLock($profile, null, static function () use (&$listenerRan): void {
                $listenerRan = true;
            });
        });

        $zipService = new ZipArchiveService(
            $registry,
            $normalizer,
            new FileSecurityGuard(),
            new SvgSanitizer(),
            null,
            $dispatcher,
            $quotaChecker,
        );
        $extractRes = $zipService->extractArchive($profile, 'sample.zip', 'out');
        self::assertSame(1, $extractRes['extracted']);
        self::assertTrue($listenerRan);

        // 3. S3 endpoint normalization strips trailing slashes & lowercases consistently
        $s3A = new VolumeConfig(name: 's3a', label: 'A', group: 'network', driver: 'AwsS3', driverOptions: ['bucket' => 'b', 'endpoint' => 'https://R2.Example.COM/']);
        $s3B = new VolumeConfig(name: 's3b', label: 'B', group: 'network', driver: 'AwsS3', driverOptions: ['bucket' => 'b', 'endpoint' => 'https://r2.example.com']);
        self::assertSame($normalizer->buildCanonicalKey($s3A, 'a.jpg'), $normalizer->buildCanonicalKey($s3B, 'a.jpg'));

        // 4. Windows `@ext_` disambiguator is case-insensitive
        $winA = new VolumeConfig(name: 'wa', label: 'W', group: 'local', driver: 'LocalFileSystem', path: 'D:\\Media\\Shared');
        $winB = new VolumeConfig(name: 'wb', label: 'W', group: 'local', driver: 'LocalFileSystem', path: 'd:\\media\\shared');
        self::assertSame(
            strtolower($normalizer->buildCanonicalKey($winA, 'pic.jpg', 'C:\\App')),
            strtolower($normalizer->buildCanonicalKey($winB, 'pic.jpg', 'C:\\App')),
        );
    }

    public function testTrashBulkBatchReducesMetaReadsAndCrossBasePathFolderThumbCleanup(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'trash_batch';
        mkdir($root, 0777, true);
        for ($i = 1; $i <= 4; ++$i) {
            file_put_contents($root . DIRECTORY_SEPARATOR . "f{$i}.txt", str_repeat('x', 64));
        }

        $profile = $this->makeProfile('default', $root, [], false, [
            'trash' => ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30, 'max_size' => '500M'],
        ]);
        $registry = new VolumeRegistry($this->makeConfigProvider([$profile]), new FlysystemStorageFactory(new DynamicPathResolver()));
        $dispatcher = new EventDispatcher();
        $trashManager = new TrashManager($registry, new PathNormalizer(), $dispatcher);

        // Pre-populate 2 existing trash entries so FIFO planner inspects existing trash
        file_put_contents($root . DIRECTORY_SEPARATOR . 'old1.txt', 'old1');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'old2.txt', 'old2');
        $trashManager->moveToTrash($profile, 'old1.txt');
        $trashManager->moveToTrash($profile, 'old2.txt');

        // Bulk batch moves remaining 4 items safely under shared batch snapshot
        $trashManager->withBulkTrashBatch(static function () use ($trashManager, $profile): void {
            $trashManager->assertBatchCanMoveToTrash($profile, 'default', 256, 64);
            for ($i = 1; $i <= 4; ++$i) {
                $trashManager->moveToTrash($profile, "f{$i}.txt", null, null, 64);
            }
        });
        self::assertCount(6, $trashManager->listTrash($profile));

        // Cross-basePath folder deletion thumbnail cleanup via FolderDeletedEvent::$deletedChildPaths
        $thumbDirA = $this->tempDir . DIRECTORY_SEPARATOR . 'thumbs_a';
        $thumbDirB = $this->tempDir . DIRECTORY_SEPARATOR . 'thumbs_b';
        $profA = $this->makeProfile('a', $root, [], false, ['basePath' => $this->tempDir, 'thumbnail' => ['cache_dir' => $thumbDirA, 'format' => 'jpeg']]);
        $profB = $this->makeProfile('b', $root, [], false, ['basePath' => $root, 'thumbnail' => ['cache_dir' => $thumbDirB, 'format' => 'jpeg']]);
        $vol = new VolumeConfig(name: 'a', label: 'A', group: 'local', driver: 'LocalFileSystem', path: $root);

        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $norm = new PathNormalizer();
        $canonA = $norm->buildCanonicalKey($vol, 'album/pic.jpg', $profA->basePath);
        $canonB = $norm->buildCanonicalKey($vol, 'album/pic.jpg', $profB->basePath);
        self::assertNotSame($canonA, $canonB);

        $pathA = $thumbManager->getCachedPath($profA, $canonA);
        $pathB = $thumbManager->getCachedPath($profB, $canonB);
        mkdir(\dirname($pathA), 0777, true);
        mkdir(\dirname($pathB), 0777, true);
        file_put_contents($pathA, 'imgA');
        file_put_contents($pathB, 'imgB');

        $listener = new ThumbnailCleanupListener($thumbManager, $this->makeConfigProvider([$profA, $profB]), $registry, $norm);
        $listener->onFolderDeleted(new FolderDeletedEvent(
            $profA,
            $vol,
            'album',
            $norm->buildCanonicalKey($vol, 'album', $profA->basePath),
            false,
            null,
            [$canonA],
            ['album/pic.jpg'],
        ));

        self::assertFileDoesNotExist($pathA);
        self::assertFileDoesNotExist($pathB, 'Cross-basePath profile thumbnail must be cleaned on folder deletion');
    }

    public function testApiRefreshGatePostCsrfNoProfileEnumerationAndFastUrlSerialization(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'api_sec';
        mkdir($root, 0777, true);
        for ($i = 1; $i <= 25; ++$i) {
            file_put_contents($root . DIRECTORY_SEPARATOR . sprintf('file_%02d.txt', $i), 'hello');
        }

        $profile = $this->makeProfile('default', $root, ['view' => true, 'clear_cache' => false], false, [
            'cache' => ['enabled' => true, 'ttl' => 3600],
        ]);
        $configProvider = $this->makeConfigProvider([$profile]);
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $guard = new FileSecurityGuard();
        $lister = new DirectoryLister($registry, $normalizer, $cache, new NullMetadataStorage(), $guard);
        $dispatcher = new EventDispatcher();
        $trash = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, new NullMetadataStorage(), $dispatcher, null, $guard);
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher, new SvgSanitizer(), null, $guard);
        $remoteImporter = new RemoteUrlImporter($uploadMgr, $registry, $normalizer, null, $guard);
        $twig = new Environment(new ArrayLoader([]));

        $generateCalls = 0;
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturnCallback(static function (string $name, array $params = []) use (&$generateCalls): string {
            ++$generateCalls;
            $base = match ($name) {
                'liveux_file_manager_download' => '/_liveux-file-manager/download',
                'liveux_file_manager_thumb' => '/_liveux-file-manager/thumb',
                default => '/' . $name,
            };

            return $params === [] ? $base : $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        });

        $strictCsrfTokenManager = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken { return new CsrfToken($tokenId, 'good-token'); }
            public function refreshToken(string $tokenId): CsrfToken { return new CsrfToken($tokenId, 'good-token'); }
            public function removeToken(string $tokenId): ?string { return null; }
            public function isTokenValid(CsrfToken $token): bool { return $token->getValue() === 'good-token'; }
        };
        $csrfValidator = new CsrfRequestValidator($strictCsrfTokenManager, true);
        $translator = new MessageTranslator();

        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $api = new ApiController(
            $configProvider,
            $registry,
            $lister,
            $ops,
            $remoteImporter,
            $trash,
            $cache,
            $thumbMgr,
            $normalizer,
            $urlGen,
            $twig,
            $csrfValidator,
            null,
            null,
            $guard,
            null,
            $translator,
        );

        // 1. Warm directory cache via normal list, check fast URL serialization makes O(1) router calls (<= 4 probes, not 50+)
        $res1 = $api->list(new Request(['profile' => 'default', 'all' => '1']));
        self::assertSame(200, $res1->getStatusCode());
        self::assertLessThanOrEqual(4, $generateCalls, 'Fast item URL serialization must not invoke UrlGenerator per file');

        // 2. VIEW-only user (clear_cache: false) passing ?refresh=1 MUST NOT bust the cached directory!
        file_put_contents($root . DIRECTORY_SEPARATOR . 'newly_added.txt', 'fresh');
        $res2 = $api->list(new Request(['profile' => 'default', 'all' => '1', 'refresh' => '1']));
        $payload2 = json_decode((string) $res2->getContent(), true);
        self::assertTrue($payload2['fromCache'], 'VIEW-only user must not bust cache via ?refresh=1 when clear_cache is denied');

        // 3. POST endpoints with unknown profile and missing CSRF return uniform 403 (no profile enumeration)
        $badActionReq = Request::create('/_liveux-file-manager/api/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'createFolder',
            'profile' => 'does_not_exist',
        ]) ?: '{}');
        self::assertSame(403, $api->action($badActionReq)->getStatusCode());

        $zipCtrl = new ZipDownloadController(
            $configProvider,
            new ZipArchiveService($registry, $normalizer, $guard, new SvgSanitizer()),
            $csrfValidator,
            null,
            $translator,
            $normalizer,
            $guard,
            $registry,
        );
        $badZipReq = Request::create('/_liveux-file-manager/zip', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'profile' => 'does_not_exist',
            'paths' => ['a.txt'],
        ]) ?: '{}');
        self::assertSame(403, $zipCtrl($badZipReq)->getStatusCode());

        $uploadCtrl = new UploadController($configProvider, $uploadMgr, $csrfValidator, $translator);
        $badUpReq = Request::create('/_liveux-file-manager/upload', 'POST', ['profile' => 'does_not_exist']);
        self::assertSame(403, $uploadCtrl($badUpReq)->getStatusCode());

        $imgCtrl = new ImageEditorController(
            $configProvider,
            $registry,
            $normalizer,
            $thumbMgr,
            $csrfValidator,
            $dispatcher,
            null,
            $guard,
            $translator,
        );
        $badImgReq = Request::create('/_liveux-file-manager/image-edit', 'POST', ['profile' => 'does_not_exist']);
        self::assertSame(403, $imgCtrl($badImgReq)->getStatusCode());
    }

    public function testPersianIndicZwnjPreservationAndThumbnailCandidateVariants(): void
    {
        // 1. Persian ZWNJ (`می‌خواهم` containing U+200C) preserved in ZipArchiveService & RemoteUrlImporter stem sanitizers
        $profile = $this->makeProfile('default', $this->tempDir);
        $registry = new VolumeRegistry($this->makeConfigProvider([$profile]), new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $guard = new FileSecurityGuard();
        $zipService = new ZipArchiveService($registry, $normalizer, $guard, new SvgSanitizer());
        $resolveDest = new ReflectionMethod(ZipArchiveService::class, 'resolveExtractDestination');
        $dest = $resolveDest->invoke($zipService, "می\u{200C}خواهم.zip", null, $profile);
        self::assertSame("می\u{200C}خواهم", $dest, 'Persian ZWNJ (U+200C) must be preserved in extracted folder names');

        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), new EventDispatcher(), new SvgSanitizer(), null, $guard);
        $importer = new RemoteUrlImporter($uploadMgr, $registry, $normalizer, null, $guard);
        $extractName = new ReflectionMethod(RemoteUrlImporter::class, 'extractFilename');
        $importedName = $extractName->invoke($importer, 'https://example.com/file', [
            "content-disposition: attachment; filename*=UTF-8''" . rawurlencode("می\u{200C}خواهم"),
            'content-type: image/png',
        ]);
        self::assertSame("می\u{200C}خواهم.png", $importedName, 'Persian ZWNJ (U+200C) must be preserved in imported filenames');

        // 2. ThumbnailManager::getCandidateCachedPaths() returns both .webp and .jpeg fallback paths
        $thumbManager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $p = $this->makeProfile('t', $this->tempDir, [], false, ['thumbnail' => ['cache_dir' => $this->tempDir . '/t', 'format' => 'webp']]);
        $candidates = $thumbManager->getCandidateCachedPaths($p, 'local::photo.jpg');
        self::assertGreaterThanOrEqual(2, count($candidates));
        self::assertTrue(str_ends_with($candidates[0], '.webp') || str_ends_with($candidates[1], '.webp'));
        self::assertTrue(str_ends_with($candidates[0], '.jpeg') || str_ends_with($candidates[1], '.jpeg'));
    }

    /**
     * @param array<string, mixed> $permissions
     * @param array<string, mixed> $overrides
     */
    private function makeProfile(
        string $name,
        string $path,
        array $permissions = [],
        bool $voterEnabled = false,
        array $overrides = [],
    ): ProfileConfig {
        return new ProfileConfig(
            name: $name,
            basePath: is_string($overrides['basePath'] ?? null) ? $overrides['basePath'] : $path,
            theme: 'bootstrap5',
            locale: null,
            volumes: is_array($overrides['volumes'] ?? null) ? $overrides['volumes'] : [],
            driver: 'LocalFileSystem',
            path: $path,
            urlPrefix: is_string($overrides['urlPrefix'] ?? null) ? $overrides['urlPrefix'] : '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: (bool) ($overrides['ignoreDotFiles'] ?? true),
            excludePatterns: is_array($overrides['excludePatterns'] ?? null) ? $overrides['excludePatterns'] : [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: [],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: is_array($overrides['thumbnail'] ?? null) ? $overrides['thumbnail'] : [],
            trash: is_array($overrides['trash'] ?? null) ? $overrides['trash'] : ['enabled' => true, 'directory' => '.trash'],
            zip: is_array($overrides['zip'] ?? null) ? $overrides['zip'] : ['allow_extract' => false],
            ui: [],
            permissions: $permissions,
            security: ['voter_enabled' => $voterEnabled, 'csrf_protection' => true, 'sanitize_svg' => true],
            cache: is_array($overrides['cache'] ?? null) ? $overrides['cache'] : ['enabled' => false, 'ttl' => 0],
            metadata: [],
            storageQuota: is_string($overrides['storageQuota'] ?? null) ? $overrides['storageQuota'] : null,
        );
    }

    /**
     * @param list<ProfileConfig> $profiles
     * @param list<VolumeConfig>  $volumes
     */
    private function makeConfigProvider(array $profiles, array $volumes = []): ConfigProviderInterface
    {
        $profMap = [];
        foreach ($profiles as $p) {
            $profMap[$p->name] = $p;
        }
        $volMap = [];
        foreach ($volumes as $v) {
            $volMap[$v->name] = $v;
        }

        return new class($profMap, $volMap) implements ConfigProviderInterface {
            /**
             * @param array<string, ProfileConfig> $profiles
             * @param array<string, VolumeConfig>  $volumes
             */
            public function __construct(private array $profiles, private array $volumes)
            {
            }

            public function getProfile(string $name): ProfileConfig
            {
                if (!isset($this->profiles[$name])) {
                    throw new FileManagerException(sprintf('Unknown profile "%s".', $name));
                }

                return $this->profiles[$name];
            }

            public function hasProfile(string $name): bool
            {
                return isset($this->profiles[$name]);
            }

            public function getProfileNames(): array
            {
                return array_keys($this->profiles);
            }

            public function getVolume(string $name): VolumeConfig
            {
                if (!isset($this->volumes[$name])) {
                    throw new FileManagerException(sprintf('Unknown volume "%s".', $name));
                }

                return $this->volumes[$name];
            }

            public function hasVolume(string $name): bool
            {
                return isset($this->volumes[$name]);
            }

            public function getVolumes(): array
            {
                return $this->volumes;
            }
        };
    }

    private function makeAuthChecker(bool $granted): AuthorizationCheckerInterface
    {
        return new class($granted) implements AuthorizationCheckerInterface {
            public function __construct(private bool $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return $this->granted;
            }
        };
    }

    private function dispatchResponseEvent(
        AutoInjectDialogHostSubscriber $subscriber,
        ?string $html = null,
        ?Response $response = null,
    ): Response {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/test', 'GET');
        $res = $response ?? new Response($html ?? '', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $res);
        $subscriber->onKernelResponse($event);

        return $res;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $entry */
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
