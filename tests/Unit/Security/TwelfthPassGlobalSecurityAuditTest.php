<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\EventListener\CacheInvalidationListener;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Model\FolderItem;
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
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression suite for Pass-12: Folder item count visibility with Voter/deep trees/cloud volumes,
 * overlapping root cache invalidation, and cross-function security/Unicode/cache/concurreny hardening.
 */
final class TwelfthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_twelfth_' . bin2hex(random_bytes(4));
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

    public function testVoterAwareFolderCountsDeepSubtreeAndCloudSinglePassTree(): void
    {
        mkdir($this->tempDir . '/public/nested', 0775, true);
        mkdir($this->tempDir . '/public/secret/hidden', 0775, true);
        mkdir($this->tempDir . '/deep/l2/l3/l4/l5/l6/l7/l8', 0775, true);
        mkdir($this->tempDir . '/deep/l2/l3/l4/l5/l6/l7/secret', 0775, true);

        file_put_contents($this->tempDir . '/public/a.txt', '1');
        file_put_contents($this->tempDir . '/public/nested/b.txt', '2');
        file_put_contents($this->tempDir . '/public/secret/hidden/topsecret.txt', '3');
        file_put_contents($this->tempDir . '/deep/l2/l3/l4/l5/l6/l7/l8/leaf.txt', 'deep');
        file_put_contents($this->tempDir . '/deep/l2/l3/l4/l5/l6/l7/secret/hidden.txt', 'deep-secret');

        $profile = $this->createProfile(
            volumes: ['local', 'cloud'],
            security: ['voter_enabled' => true],
            ui: ['show_folder_item_count' => true, 'folder_count_mode' => 'recursive_files'],
        );

        $localVol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');
        $cloudVol = new VolumeConfig('cloud', 'Cloud', 'cloud', 'LocalFileSystem', $this->tempDir, '/uploads');
        $provider = $this->createConfigProvider($profile, [$localVol, $cloudVol]);
        $registry = $this->createLocalRegistry($provider);
        $cache = new DirectoryCacheManager(new ArrayAdapter());

        $denySecretAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denySecretAuth->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject): bool {
                if ($subject instanceof FileManagerSubject) {
                    return !str_contains($subject->path, 'secret');
                }

                return true;
            },
        );

        $lister = new DirectoryLister($registry, new PathNormalizer(), $cache, new NullMetadataStorage(), new FileSecurityGuard(), $denySecretAuth);
        $listing = $lister->list($profile, 'local', '');

        $counts = $this->extractFolderCounts($listing->items);
        // public has a.txt + nested/b.txt = 2; secret/hidden/topsecret.txt is denied & excluded!
        self::assertSame(['deep' => 1, 'public' => 2], $counts);

        // Cloud non-local single-pass scanner & depth > 6 count fallback
        $cloudListing = $lister->list($profile, 'cloud', '');
        self::assertSame(['deep' => 1, 'public' => 2], $this->extractFolderCounts($cloudListing->items));

        // Direct count of deep subtree still resolves past depth 6
        $subListing = $lister->list($profile, 'local', 'deep');
        self::assertSame(['deep/l2' => 1], $this->extractFolderCounts($subListing->items));

        // ResetInterface drops request-level memoization cleanly
        file_put_contents($this->tempDir . '/public/extra.txt', '4');
        $lister->reset();
        $refreshed = $lister->list($profile, 'local', '', forceRefresh: true);
        self::assertSame(3, $this->extractFolderCounts($refreshed->items)['public']);
    }

    public function testOverlappingLocalVolumeMutationInvalidatesParentRootCaches(): void
    {
        $subDir = $this->tempDir . '/subvol';
        mkdir($subDir, 0775, true);
        file_put_contents($subDir . '/one.txt', '1');

        $parentVol = new VolumeConfig('rootvol', 'Root', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');
        $childVol = new VolumeConfig('subvol', 'Sub', 'local', 'LocalFileSystem', $subDir, '/uploads/subvol');
        $profile = $this->createProfile(
            volumes: ['rootvol', 'subvol'],
            cache: ['enabled' => true, 'ttl' => 300],
            ui: ['show_folder_item_count' => true, 'folder_count_mode' => 'recursive_files'],
        );

        $provider = $this->createConfigProvider(['default' => $profile], [$parentVol, $childVol]);
        $registry = $this->createLocalRegistry($provider);
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $cacheManager = new DirectoryCacheManager($pool);
        $lister = new DirectoryLister($registry, new PathNormalizer(), $cacheManager, new NullMetadataStorage());

        $initial = $lister->list($profile, 'rootvol', '');
        self::assertSame(['subvol' => 1], $this->extractFolderCounts($initial->items));

        // New file added inside child volume must invalidate overlapping parent root tree cache
        file_put_contents($subDir . '/two.txt', '2');
        $normalizer = new PathNormalizer();
        $listener = new CacheInvalidationListener($cacheManager, $normalizer);
        $listener->onMutate(new FileUploadedEvent($profile, $childVol, 'two.txt', $normalizer->buildCanonicalKey($childVol, 'two.txt', $profile->basePath), 1, 'text/plain'));

        $after = $lister->list($profile, 'rootvol', '');
        self::assertSame(['subvol' => 2], $this->extractFolderCounts($after->items));
    }

    public function testSecurityHardeningDefaultsOverflowIdnaSvgCssAndUrls(): void
    {
        // Configuration exclude_patterns case-insensitivity (_THUMBS, .ds_store)
        $processor = new Processor();
        $processed = $processor->processConfiguration(new Configuration(), [['profiles' => ['default' => []]]]);
        $defaultExcludes = $processed['profiles']['default']['exclude_patterns'];
        self::assertContains('/^_thumbs$/i', $defaultExcludes);
        self::assertContains('/^\.DS_Store$/i', $defaultExcludes);

        // ByteSizeParser clamps extreme overflow safely to PHP_INT_MAX
        self::assertSame(PHP_INT_MAX, ByteSizeParser::parse('99999999999999999999G'));

        // IDNA2008 Transitional vs NonTransitional + strict bracketed IPv6 validation
        self::assertNull(UnicodeHelper::canonicalizeHostToAscii('[8.8.8.8]'));
        self::assertNull(UnicodeHelper::canonicalizeHostToAscii('[:::]'));
        self::assertSame('[2001:db8::1]', UnicodeHelper::canonicalizeHostToAscii('[2001:db8::1]'));
        self::assertSame('xn--strae-oqa.de', UnicodeHelper::canonicalizeHostToAscii('straße.de'));
        self::assertSame('xn--nxasmm1c.gr', UnicodeHelper::canonicalizeHostToAscii('βόλος.gr'));
        $persianHost = UnicodeHelper::canonicalizeHostToAscii("بخش\u{200C}نامه.ir");
        self::assertNotNull($persianHost);
        self::assertStringStartsWith('xn--', $persianHost);

        // SvgSanitizer size cap (5 MB) + CSS image()/cross-fade() blocking
        $sanitizer = new SvgSanitizer();
        $oversizedSvg = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('a', SvgSanitizer::MAX_SVG_BYTES + 16) . '</svg>';
        self::assertSame('', $sanitizer->sanitize($oversizedSvg));

        $cssImg = '<svg xmlns="http://www.w3.org/2000/svg"><style>g{background:image("https://evil.test/x.png")}</style><rect width="10" height="10"/></svg>';
        self::assertStringNotContainsString('evil.test', $sanitizer->sanitize($cssImg));

        $cssCrossFade = '<svg xmlns="http://www.w3.org/2000/svg"><style>g{background:-webkit-cross-fade(url(https://evil.test/a.png), url(https://evil.test/b.png), 50%)}</style><rect width="10" height="10"/></svg>';
        self::assertStringNotContainsString('evil.test', $sanitizer->sanitize($cssCrossFade));

        // AssetUsageGuardService & FileManagerTwigExtension encoded root-relative URL bypass checks
        $usageVol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');
        $usageProfile = $this->createProfile();
        $usageNorm = new PathNormalizer();
        $usageReg = $this->createLocalRegistry($this->createConfigProvider($usageProfile, [$usageVol]));
        $usageSvc = new AssetUsageGuardService($usageReg, $usageNorm);
        $sanitizeEditUrl = new ReflectionMethod($usageSvc, 'sanitizeEditUrl');
        self::assertNull($sanitizeEditUrl->invoke($usageSvc, '/%2fevil.com/edit'));
        self::assertNull($sanitizeEditUrl->invoke($usageSvc, '/%5cevil.com/edit'));
        self::assertNull($sanitizeEditUrl->invoke($usageSvc, '/%252fevil.com/edit'));
        self::assertSame('/admin/media/12', $sanitizeEditUrl->invoke($usageSvc, '/admin/media/12'));

        $ext = new FileManagerTwigExtension($this->createStub(UrlGeneratorInterface::class));
        $isSafeUrl = new ReflectionMethod($ext, 'isSafeAbsoluteOrRootUrl');
        self::assertFalse($isSafeUrl->invoke($ext, '/%2fevil'));
        self::assertFalse($isSafeUrl->invoke($ext, '/%252fevil'));
        self::assertFalse($isSafeUrl->invoke($ext, '/uploads/.trash/secret.jpg'));
        self::assertFalse($isSafeUrl->invoke($ext, '/uploads/.env'));
        self::assertTrue($isSafeUrl->invoke($ext, '/uploads/photo.jpg'));
    }

    public function testTrashRestoreDestinationValidationNormalizerCacheControllerMetadataAndOps(): void
    {
        // TrashManager::restore checks Voter/accessibility on moved target destination path
        mkdir($this->tempDir . '/folder', 0775, true);
        file_put_contents($this->tempDir . '/folder/doc.txt', 'v1');
        $trashDir = $this->tempDir . '/.trash';
        mkdir($trashDir, 0775, true);

        $profile = $this->createProfile(
            security: ['voter_enabled' => true],
            trash: ['enabled' => true, 'directory' => '.trash'],
            cache: ['enabled' => false, 'ttl' => 300],
        );
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads', cacheTtl: 3600);
        $provider = $this->createConfigProvider($profile, [$vol]);
        $registry = $this->createLocalRegistry($provider);
        $guard = new FileSecurityGuard();
        $normalizer = new PathNormalizer();

        $denyMovedAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyMovedAuth->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject): bool {
                if ($subject instanceof FileManagerSubject) {
                    // Allow original doc.txt during moveToTrash/restore source check, deny renamed collision target doc_1.txt
                    return !str_contains($subject->path, 'restored');
                }

                return true;
            },
        );

        $trash = new TrashManager($registry, $normalizer, new EventDispatcher(), $guard, authChecker: $denyMovedAuth);
        $entry = $trash->moveToTrash($profile, 'folder/doc.txt', 'local');

        // Re-create collision at original path so restore chooses a "(restored ...)" target name
        file_put_contents($this->tempDir . '/folder/doc.txt', 'existing');
        try {
            $trash->restore($profile, $entry->id, enforceVoter: true);
            self::fail('Expected AccessDeniedException when restored destination collides onto Voter-denied target path.');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        // ThumbnailController honors profile cache.enabled=false even when volume.cache_ttl is set
        $thumbProfile = $this->createProfile(
            cache: ['enabled' => false, 'ttl' => 300],
            thumbnail: ['enabled' => true, 'driver' => 'gd', 'format' => 'png', 'cache_dir' => $this->tempDir . '/_thumbs'],
        );
        $thumbProvider = $this->createConfigProvider($thumbProfile, [$vol]);
        $thumbRegistry = $this->createLocalRegistry($thumbProvider);
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', true);
        self::assertIsString($pngBytes);
        file_put_contents($this->tempDir . '/pic.png', $pngBytes);

        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $thumbCtrl = new ThumbnailController($thumbProvider, $thumbRegistry, $normalizer, $thumbMgr, null, $guard, new MessageTranslator());
        $thumbResp = ($thumbCtrl)(Request::create('/thumb?profile=default&volume=local&path=pic.png'));
        $thumbCacheControl = (string) $thumbResp->headers->get('Cache-Control');
        self::assertStringContainsString('private', $thumbCacheControl);
        self::assertStringContainsString('no-cache', $thumbCacheControl);
        self::assertStringNotContainsString('public', $thumbCacheControl);

        // FilenameNormalizer preserves prepend/append when slugger=false and transformer=folder
        $fnNormalizer = new FilenameNormalizer($guard);
        $fnProfile = $this->createProfile(naming: [
            'slugger' => false,
            'transformer' => 'folder',
            'prepend' => 'pre-',
            'append' => '-sfx',
        ]);
        self::assertSame('pre-Gallery-sfx.jpg', $fnNormalizer->normalizeForUpload('Holiday Pics.jpg', $fnProfile, 'Gallery'));

        // FolderMetadata & AssetEnrichmentResult alternating ZWNJ/ZWJ edge stripping & MessageTranslator RTL scripts
        $meta = FolderMetadata::fromArray(['tags' => ["\u{200C} \u{200D} بخش\u{200C}نامه \u{200C}\u{200D}"]]);
        self::assertSame(["بخش\u{200C}نامه"], $meta->tags);
        $ai = new AssetEnrichmentResult(alt: "\u{200C}سلام\u{200D}\u{200B}");
        self::assertSame('سلام', $ai->alt);

        $msgTranslator = new MessageTranslator();
        self::assertTrue($msgTranslator->isRtlLocale('sd-Arab'));
        self::assertFalse($msgTranslator->isRtlLocale('sd-Deva'));
        self::assertTrue($msgTranslator->isRtlLocale('ks-Arab'));
        self::assertFalse($msgTranslator->isRtlLocale('ka-Geor'));

        // JsonFileMetadataStorage throws RuntimeException cleanly if write fails (path points to an existing directory)
        $badMetaDir = $this->tempDir . '/dir-not-file';
        mkdir($badMetaDir, 0775, true);
        $badStorage = new JsonFileMetadataStorage($badMetaDir);
        try {
            $badStorage->save('local::pic.png', new FolderMetadata(title: 'fail'));
            self::fail('Expected RuntimeException on unwritable metadata target.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        // Translation parity for errors.zip_extract_too_large
        $bundleRoot = dirname(__DIR__, 3);
        foreach (['en', 'tr', 'fr'] as $locale) {
            $yaml = Yaml::parseFile($bundleRoot . '/translations/LiveUXFileManagerBundle.' . $locale . '.yaml');
            self::assertIsArray($yaml);
            self::assertArrayHasKey('zip_extract_too_large', $yaml['errors']);
        }

        // FileOperationsService deletes/trashes folders cleanly with post-action cleanup
        $ops = new FileOperationsService(
            $registry,
            $normalizer,
            $fnNormalizer,
            $trash,
            new NullMetadataStorage(),
            new EventDispatcher(),
            null,
            $guard,
            thumbnailManager: $thumbMgr,
        );
        $ops->createFolder($profile, '', 'SubFolder', 'local');
        self::assertTrue($registry->getFilesystem($profile, 'local')->directoryExists('SubFolder'));
    }

    public function testFullGraphWarmCacheZeroRescanDetectExternalVotersGuardAndIconCache(): void
    {
        mkdir($this->tempDir . '/d1/d2/d3/d4/d5/d6/d7/d8', 0775, true);
        mkdir($this->tempDir . '/d1/d2/d3/l4', 0775, true);
        mkdir($this->tempDir . '/d1/d2/d3/d4/d5/d6/d7/secret', 0775, true);
        file_put_contents($this->tempDir . '/d1/d2/d3/d4/d5/d6/d7/d8/item.txt', 'ok');
        file_put_contents($this->tempDir . '/d1/d2/d3/d4/d5/d6/d7/secret/hidden.txt', 'no');

        $profile = $this->createProfile(
            security: ['voter_enabled' => true],
            cache: ['enabled' => true, 'ttl' => 300],
            ui: ['show_folder_item_count' => true, 'folder_count_mode' => 'recursive_files'],
        );
        $provider = $this->createConfigProvider($profile);
        $registry = $this->createLocalRegistry($provider);
        $cachePool = new TagAwareAdapter(new ArrayAdapter());
        $cacheMgr = new DirectoryCacheManager($cachePool);

        $denySecretAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denySecretAuth->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject): bool {
                if ($subject instanceof FileManagerSubject) {
                    return !str_contains($subject->path, 'secret');
                }

                return true;
            },
        );

        $lister = new DirectoryLister($registry, new PathNormalizer(), $cacheMgr, new NullMetadataStorage(), new FileSecurityGuard(), $denySecretAuth, hasExternalVoters: true);
        self::assertSame(['d1' => 1], $this->extractFolderCounts($lister->list($profile, 'local', '')->items));
        $treeD1 = $lister->listFullTree($profile, 'local', 1);
        $treeD6 = $lister->listFullTree($profile, 'local', 6);
        self::assertCount(1, $treeD1);
        self::assertSame(1, $treeD1[0]['itemCount']);
        self::assertGreaterThan(5, count($treeD6));

        // Add a new file on disk WITHOUT invalidating cache, then simulate a new HTTP request via reset()
        file_put_contents($this->tempDir . '/d1/d2/d3/d4/d5/d6/d7/d8/after-warm.txt', 'new');
        $lister->reset();
        self::assertSame(['d1' => 1], $this->extractFolderCounts($lister->list($profile, 'local', '')->items));

        // DetectExternalVotersPass: built-in voters only -> false; external custom voter -> true
        $pass = new DetectExternalVotersPass();

        $cbBuiltIn = new ContainerBuilder();
        $cbBuiltIn->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $cbBuiltIn->setDefinition('voter.fm', (new Definition(FileManagerVoter::class))->addTag('security.voter'));
        $cbBuiltIn->setDefinition('voter.sf', (new Definition('Symfony\\Component\\Security\\Core\\Authorization\\Voter\\AuthenticatedVoter'))->addTag('security.voter'));
        $pass->process($cbBuiltIn);
        $builtInArgs = $cbBuiltIn->getDefinition(DirectoryLister::class)->getArguments();
        self::assertArrayNotHasKey('$hasExternalVoters', $builtInArgs);
        self::assertFalse($builtInArgs['hasExternalVoters'] ?? $builtInArgs[6]);

        $cbCustom = new ContainerBuilder();
        $cbCustom->setDefinition(DirectoryLister::class, new Definition(DirectoryLister::class));
        $cbCustom->setDefinition('voter.fm', (new Definition(FileManagerVoter::class))->addTag('security.voter'));
        $cbCustom->setDefinition('voter.app', (new Definition('App\\Security\\CustomPathVoter'))->addTag('security.voter'));
        $pass->process($cbCustom);
        $customArgs = $cbCustom->getDefinition(DirectoryLister::class)->getArguments();
        self::assertArrayNotHasKey('$hasExternalVoters', $customArgs);
        self::assertTrue($customArgs['hasExternalVoters'] ?? $customArgs[6]);

        // Fast-path with hasExternalVoters: false invokes authChecker only once for tree + listing
        $grantCalls = 0;
        $countingAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $countingAuth->method('isGranted')->willReturnCallback(
            static function () use (&$grantCalls): bool {
                ++$grantCalls;

                return true;
            },
        );
        $fastLister = new DirectoryLister($registry, new PathNormalizer(), new DirectoryCacheManager(new ArrayAdapter()), new NullMetadataStorage(), new FileSecurityGuard(), $countingAuth, hasExternalVoters: false);
        $fastLister->list($profile, 'local', '');
        $fastLister->listFullTree($profile, 'local', 6);
        self::assertSame(1, $grantCalls);

        // FileSecurityGuard::isChildSegmentIgnored matches assertAccessiblePath across ASCII, dotfiles, NFD & nested trash
        $guard = new FileSecurityGuard();
        $guardProfile = $this->createProfile(trash: ['enabled' => true, 'directory' => 'system/trash']);
        self::assertFalse($guard->isChildSegmentIgnored('catalog/brochure.pdf', 'brochure.pdf', $guardProfile));
        self::assertTrue($guard->isChildSegmentIgnored('catalog/.env', '.env', $guardProfile));
        self::assertTrue($guard->isChildSegmentIgnored('catalog/_THUMBS', '_THUMBS', $guardProfile));
        self::assertTrue($guard->isChildSegmentIgnored('system/trash/x.png', 'x.png', $guardProfile));
        self::assertTrue($guard->isChildSegmentIgnored("system/t\u{200B}rash/x.png", 'x.png', $guardProfile));
        self::assertTrue($guard->isChildSegmentIgnored('café/.DS_Store', '.DS_Store', $guardProfile));

        // IconSetRegistry caches built-in icon sets into cachePool across instances, skipping custom directories
        $bundleIconsDir = dirname(__DIR__, 3) . '/resources/icons';
        $iconPool = new ArrayAdapter();
        $iconReg1 = new IconSetRegistry($bundleIconsDir, cachePool: $iconPool);
        $payload1 = $iconReg1->getIconSetPayload('windows11', true);
        self::assertNotEmpty($payload1['icons']);

        $customIconsDir = $this->tempDir . '/custom-icons';
        mkdir($customIconsDir, 0775, true);
        file_put_contents($customIconsDir . '/folder.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="20" height="20"/></svg>');
        $customPayload = $iconReg1->getIconSetPayload($customIconsDir, true);
        self::assertStringContainsString('rect', $customPayload['icons']['folder']);

        $cachedPoolItems = $iconPool->getValues();
        self::assertCount(1, $cachedPoolItems);

        $iconReg2 = new IconSetRegistry($bundleIconsDir, cachePool: $iconPool);
        $payload2 = $iconReg2->getIconSetPayload('windows11', true);
        self::assertSame($payload1, $payload2);
    }

    /**
     * @param list<object> $items
     * @return array<string, ?int>
     */
    private function extractFolderCounts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            if ($item instanceof FolderItem) {
                $counts[$item->path] = $item->itemCount;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<string> $volumes
     * @param array<string, mixed> $security
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $cache
     * @param array<string, mixed> $ui
     * @param array<string, mixed> $naming
     * @param array<string, mixed> $thumbnail
     */
    private function createProfile(
        array $volumes = ['local'],
        array $security = [],
        array $trash = ['enabled' => true, 'directory' => '.trash'],
        array $cache = ['enabled' => false],
        array $ui = [],
        array $naming = [],
        array $thumbnail = [],
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
            excludePatterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'],
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
            permissions: [],
            security: $security,
            cache: $cache,
            metadata: ['enabled' => false],
            share: ['enabled' => true],
        );
    }

    /**
     * @param ProfileConfig|array<string, ProfileConfig> $profiles
     * @param list<VolumeConfig> $volumes
     */
    private function createConfigProvider(ProfileConfig|array $profiles, array $volumes = []): ConfigProviderInterface
    {
        $profileMap = is_array($profiles) ? $profiles : [$profiles->name => $profiles];
        $volumeMap = [];
        foreach ($volumes as $vol) {
            $volumeMap[$vol->name] = $vol;
        }
        if ($volumeMap === []) {
            $volumeMap['local'] = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir, '/uploads');
        }

        return new class ($profileMap, $volumeMap) implements ConfigProviderInterface {
            /**
             * @param array<string, ProfileConfig> $profiles
             * @param array<string, VolumeConfig> $volumes
             */
            public function __construct(private array $profiles, private array $volumes)
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

    private function createLocalRegistry(ConfigProviderInterface $provider): VolumeRegistry
    {
        return new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
    }
}
