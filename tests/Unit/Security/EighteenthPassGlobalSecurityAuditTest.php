<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use DateTimeInterface;
use League\Flysystem\DirectoryListing as FlysystemDirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
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
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
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
use ReflectionMethod;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class EighteenthPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_p18_' . bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testDynamicPathResolverDisjointTenantSpacesAndIdempotentOverride(): void
    {
        $anonResolver = new DynamicPathResolver();
        self::assertSame('/t/guest', $anonResolver->resolve('/t/{user_id}'));

        // Victim with email address gets `--h-<16hex>` hashed directory
        $victimResolver = $this->makeResolverForIdentifier('alice@example.com');
        $victimId = $victimResolver->getCurrentUserId();
        self::assertStringContainsString('--h-', $victimId);

        // Attacker registers the victim's EXACT computed directory name as their username -> MUST NOT collide!
        $attackerResolver = $this->makeResolverForIdentifier($victimId);
        $attackerId = $attackerResolver->getCurrentUserId();
        self::assertNotSame($victimId, $attackerId, 'Pre-computed hash username must not collide with victim tenant directory');

        // Authenticated user named 'guest' or 'user' MUST NOT collide with anonymous 'guest' or empty 'user'
        $guestUserId = $this->makeResolverForIdentifier('guest')->getCurrentUserId();
        $emptyLabelUserId = $this->makeResolverForIdentifier('user')->getCurrentUserId();
        self::assertNotSame('guest', $guestUserId);
        self::assertNotSame('user', $emptyLabelUserId);

        // Clean identifiers still stay readable and pre-resolved HMAC override tokens round-trip idempotently
        self::assertSame('tenant_42', $this->makeResolverForIdentifier('tenant_42')->getCurrentUserId());
        self::assertSame('/t/' . $victimId, $anonResolver->resolve('/t/{user_id}', null, $victimId));
    }

    public function testSvgSanitizerCapsDuplicateIdAndGlobalStepBudget(): void
    {
        $sanitizer = new SvgSanitizer();

        // 1. Excessive duplicate IDs (> 8) referencing target `#dup` fails closed and strips `<use>`
        $dups = str_repeat('<g id="dup"><rect width="1" height="1"/></g>', 15);
        $dupSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs>' . $dups . '</defs><use href="#dup"/></svg>';
        $outDup = $sanitizer->sanitize($dupSvg);
        self::assertStringNotContainsString('<use', $outDup, 'Excessive duplicate target IDs must fail closed');

        // 2. Adversarial fanout with 8 duplicate chains stays fast via global step budget
        $start = microtime(true);
        $layerB = str_repeat('<g id="b">' . str_repeat('<rect width="1" height="1"/>', 4) . '</g>', 8);
        $layerA = str_repeat('<g id="a"><use href="#b"/><use href="#b"/></g>', 8);
        $manyUses = str_repeat('<use href="#a"/>', 80);
        $bombSvg = '<svg xmlns="http://www.w3.org/2000/svg"><defs>' . $layerB . $layerA . '</defs>' . $manyUses . '</svg>';
        $outBomb = $sanitizer->sanitize($bombSvg);
        $elapsedMs = (microtime(true) - $start) * 1000.0;

        self::assertLessThan(500.0, $elapsedMs, 'Global step budget must prevent quadratic CPU DoS');
        self::assertLessThan(80, substr_count($outBomb, 'href="#a"'), 'Global step budget must strip excess outer <use> elements');
    }

    public function testApiFallbackCacheEvictionRequiresClearCacheAndCheckUsageValidatesSubtree(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'api_p18';
        mkdir($root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'secret-hr', 0777, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'root_file.txt', 'v1');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'secret-hr' . DIRECTORY_SEPARATOR . 'payroll.pdf', 'secret');

        $profile = $this->makeProfile('default', $root, [
            'view' => true,
            'delete' => true,
            'clear_cache' => false,
        ], true, [
            'cache' => ['enabled' => true, 'ttl' => 3600],
            'trash' => ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30, 'max_size' => '500M'],
        ]);

        // Custom voter: allows VIEW+DELETE on root and `projects`, DENIES everything under `projects/secret-hr`, DENIES CLEAR_CACHE
        $authChecker = new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                if ($attribute === FileManagerPermissions::CLEAR_CACHE) {
                    return false;
                }
                if ($subject instanceof FileManagerSubject && str_starts_with($subject->path, 'projects/secret-hr')) {
                    return false;
                }

                return true;
            }
        };

        $usageCheckerCalls = 0;
        $usageChecker = new class($usageCheckerCalls) implements AssetUsageCheckerInterface {
            public function __construct(private int &$calls)
            {
            }

            public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
            {
                ++$this->calls;

                return [new AssetUsageReference('HR Payroll', 'Document', '99', '/admin/hr/99')];
            }
        };

        $configProvider = $this->makeConfigProvider([$profile]);
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $guard = new FileSecurityGuard();
        $lister = new DirectoryLister($registry, $normalizer, $cache, new NullMetadataStorage(), $guard, $authChecker, true);
        $dispatcher = new EventDispatcher();
        $trash = new TrashManager($registry, $normalizer, $dispatcher, $guard, null, $authChecker);
        $usageGuard = new AssetUsageGuardService($registry, $normalizer, $guard, [$usageChecker]);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trash, new NullMetadataStorage(), $dispatcher, $authChecker, $guard, $usageGuard);
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher, new SvgSanitizer(), $authChecker, $guard);
        $remoteImporter = new RemoteUrlImporter($uploadMgr, $registry, $normalizer, $authChecker, $guard);
        $thumbMgr = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturn('/_liveux-file-manager/download');

        $api = new ApiController(
            configProvider: $configProvider,
            volumeRegistry: $registry,
            directoryLister: $lister,
            operations: $ops,
            remoteUrlImporter: $remoteImporter,
            trashManager: $trash,
            cacheManager: $cache,
            thumbnailManager: $thumbMgr,
            pathNormalizer: $normalizer,
            urlGenerator: $urlGen,
            twig: new Environment(new ArrayLoader([])),
            csrfValidator: new CsrfRequestValidator(null, false),
            authChecker: $authChecker,
            fileSecurityGuard: $guard,
            messageTranslator: new MessageTranslator(),
            usageGuardService: $usageGuard,
        );

        // 1. Warm root cache, then add a new file directly on disk
        $resWarm = $api->list(new Request(['profile' => 'default', 'all' => '1']));
        self::assertSame(200, $resWarm->getStatusCode());
        file_put_contents($root . DIRECTORY_SEPARATOR . 'added_after_cache.txt', 'new');

        // 2. VIEW-only user sends `?path=missing_999&fallback=1` -> MUST NOT evict root cache!
        $resFallback = $api->list(new Request(['profile' => 'default', 'path' => 'missing_999', 'fallback' => '1', 'all' => '1']));
        self::assertSame(200, $resFallback->getStatusCode());
        $fbData = json_decode((string) $resFallback->getContent(), true);
        self::assertTrue($fbData['fromCache'], 'Fallback request without CLEAR_CACHE must not evict cached root directory');

        // 3. `action=checkUsage` on `projects` containing forbidden `projects/secret-hr` MUST be denied BEFORE invoking AssetUsageChecker
        $usageReq = Request::create('/_liveux-file-manager/api/action', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'checkUsage',
            'profile' => 'default',
            'paths' => ['projects'],
        ]) ?: '{}');
        $usageRes = $api->action($usageReq);
        self::assertSame(403, $usageRes->getStatusCode());
        self::assertSame(0, $usageCheckerCalls, 'AssetUsageChecker must not run on folders containing forbidden subtrees');
    }

    public function testTwigExtensionBlocksThirdPartyOriginSpoofingOnDownloadAndShare(): void
    {
        $urlGen = $this->createStub(UrlGeneratorInterface::class);
        $urlGen->method('generate')->willReturnCallback(static function (string $name, array $params = [], int $refType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
            $path = match ($name) {
                'liveux_file_manager_download' => '/_liveux-file-manager/download',
                'liveux_file_manager_share' => '/_liveux-file-manager/share',
                'liveux_file_manager_thumb' => '/_liveux-file-manager/thumb',
                default => '/' . $name,
            };

            return $refType === UrlGeneratorInterface::ABSOLUTE_URL
                ? 'https://app.example.com' . $path
                : $path;
        });
        $ext = new FileManagerTwigExtension($urlGen);

        // Attacker origin spoofing `/_liveux-file-manager/download?path=payload.svg` MUST NOT get clickable href or rewritten thumb imgSrc
        $spoofed = $ext->describeFieldItem('https://attacker.example/_liveux-file-manager/download?path=payload.svg');
        self::assertNull($spoofed['href'], 'Third-party origin must not spoof bundle /download endpoint for SVG');
        self::assertNull($spoofed['imgSrc']);

        // Same-origin absolute signed share URL for SVG retains attachment-forced href while keeping imgSrc null
        $sameOriginShare = $ext->describeFieldItem('https://app.example.com/_liveux-file-manager/share?path=logo.svg&_hash=ok');
        self::assertNotNull($sameOriginShare['href']);
        self::assertNull($sameOriginShare['imgSrc']);

        // Root-relative bundle `/download` for SVG retains href and rewrites imgSrc to `/thumb`
        $relDl = $ext->describeFieldItem('/_liveux-file-manager/download?path=logo.svg');
        self::assertNotNull($relDl['href']);
        self::assertSame('/_liveux-file-manager/thumb?path=logo.svg', $relDl['imgSrc']);
    }

    public function testAutoInjectDialogHostMasksQuotedAttributesAndRcdataBlocks(): void
    {
        $twig = new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div id="liveux-fm-dialog-host" data-profile="{{ profile }}"></div>',
        ]));
        $profile = $this->makeProfile('default', $this->tempDir, ['view' => true]);
        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050, $this->makeConfigProvider([$profile]));

        // 1. Fragment with `<iframe srcdoc="<html><body>Preview</body></html>">` MUST NOT inject inside attribute!
        $srcdocFragment = '<div class="wrap"><iframe srcdoc="<html><body>Preview</body></html>"></iframe></div>';
        $res1 = $this->dispatchResponseEvent($subscriber, $srcdocFragment);
        self::assertSame($srcdocFragment, (string) $res1->getContent());

        // 2. Hidden input carrying full HTML email preview MUST NOT trigger attribute breakout
        $inputFragment = '<form><input type="hidden" value="<html><body>Email</body></html>"></form>';
        $res2 = $this->dispatchResponseEvent($subscriber, $inputFragment);
        self::assertSame($inputFragment, (string) $res2->getContent());

        // 3. Full HTML page with RCDATA `<title>`/`<xmp>`/`<iframe>` decoy `</body>` injects strictly before the real `</body>`
        $fullHtml = '<html><head><title><body>Fake</body></title></head><body>'
            . '<iframe srcdoc="<html><body>Inner</body></html>"></iframe>'
            . '<xmp></body></xmp><main>Real</main></body></html>';
        $res3 = $this->dispatchResponseEvent($subscriber, $fullHtml);
        $out3 = (string) $res3->getContent();
        self::assertStringContainsString('<main>Real</main>' . "\n" . '<div id="liveux-fm-dialog-host"', $out3);
        self::assertStringContainsString('srcdoc="<html><body>Inner</body></html>"', $out3);
    }

    public function testUploadManagerExternalPersistentChunkLockAndForcedVolumeLock(): void
    {
        $root = $this->tempDir . DIRECTORY_SEPARATOR . 'chunks_p18';
        mkdir($root, 0777, true);
        $profile = $this->makeProfile('default', $root, ['view' => true, 'upload' => true], false, [
            'storageQuota' => null,
            'overwritePolicy' => 'rename',
        ]);
        $registry = new VolumeRegistry($this->makeConfigProvider([$profile]), new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $quotaChecker = new StorageQuotaChecker($registry, $normalizer, $cache);
        $uploadMgr = new UploadManager($registry, $normalizer, new FilenameNormalizer(), new EventDispatcher(), new SvgSanitizer(), null, new FileSecurityGuard(), new ImageOptimizer(), $quotaChecker);

        // 1. Forced write lock executes cleanly even when storage_quota is null
        $lockedVal = $quotaChecker->withQuotaLock($profile, null, static fn (): string => 'serialized', true);
        self::assertSame('serialized', $lockedVal);

        // 2. 2-chunk upload completes cleanly using external persistent lock file and cleans up `$chunkDir`
        $p0 = $this->tempDir . DIRECTORY_SEPARATOR . 'c0.part';
        $p1 = $this->tempDir . DIRECTORY_SEPARATOR . 'c1.part';
        file_put_contents($p0, 'Hello ');
        file_put_contents($p1, 'World!');

        $uploadId = 'upload_' . bin2hex(random_bytes(8));
        $r0 = $uploadMgr->handleUpload($profile, new UploadedFile($p0, 'greet.txt', 'text/plain', null, true), '', null, $uploadId, 0, 2, 'greet.txt');
        self::assertFalse($r0['completed']);

        $r1 = $uploadMgr->handleUpload($profile, new UploadedFile($p1, 'greet.txt', 'text/plain', null, true), '', null, $uploadId, 1, 2, 'greet.txt');
        self::assertTrue($r1['completed']);
        self::assertSame('Hello World!', file_get_contents($root . DIRECTORY_SEPARATOR . 'greet.txt'));

        // 3. Aborting an active chunk session removes its chunk dir cleanly and second abort returns false
        $pAbort = $this->tempDir . DIRECTORY_SEPARATOR . 'ca.part';
        file_put_contents($pAbort, 'part0');
        $abortId = 'abort_' . bin2hex(random_bytes(8));
        $uploadMgr->handleUpload($profile, new UploadedFile($pAbort, 'cancel.txt', 'text/plain', null, true), '', null, $abortId, 0, 3, 'cancel.txt');
        self::assertTrue($uploadMgr->abortChunkedUpload($profile, $abortId));
        self::assertFalse($uploadMgr->abortChunkedUpload($profile, $abortId));
    }

    public function testTrashManagerDeletePermanentlyUnderLockAndPartialNonLocalCleanup(): void
    {
        $s3Dir = $this->tempDir . DIRECTORY_SEPARATOR . 's3_trash_mock';
        mkdir($s3Dir, 0777, true);
        $baseFs = new Filesystem(new LocalFilesystemAdapter($s3Dir));
        $baseFs->write('album/a.txt', 'aaa');
        $baseFs->write('album/b.txt', 'bbb');

        // Decorator that throws partway through copying `album/b.txt` into `.trash/`
        $failingFs = new class($baseFs) implements FilesystemOperator {
            public bool $failSecondCopy = true;
            private int $copies = 0;

            public function __construct(private Filesystem $inner)
            {
            }

            public function fileExists(string $location): bool
            {
                return $this->inner->fileExists($location);
            }

            public function directoryExists(string $location): bool
            {
                return $this->inner->directoryExists($location);
            }

            public function has(string $location): bool
            {
                return $this->inner->has($location);
            }

            public function read(string $location): string
            {
                return $this->inner->read($location);
            }

            public function readStream(string $location)
            {
                return $this->inner->readStream($location);
            }

            public function listContents(string $location, bool $deep = self::LIST_SHALLOW): FlysystemDirectoryListing
            {
                return $this->inner->listContents($location, $deep);
            }

            public function lastModified(string $location): int
            {
                return $this->inner->lastModified($location);
            }

            public function fileSize(string $location): int
            {
                return $this->inner->fileSize($location);
            }

            public function mimeType(string $location): string
            {
                return $this->inner->mimeType($location);
            }

            public function visibility(string $location): string
            {
                return $this->inner->visibility($location);
            }

            public function write(string $location, string $contents, array $config = []): void
            {
                $this->inner->write($location, $contents, $config);
            }

            public function writeStream(string $location, $contents, array $config = []): void
            {
                $this->inner->writeStream($location, $contents, $config);
            }

            public function setVisibility(string $location, string $visibility): void
            {
                $this->inner->setVisibility($location, $visibility);
            }

            public function delete(string $location): void
            {
                $this->inner->delete($location);
            }

            public function deleteDirectory(string $location): void
            {
                $this->inner->deleteDirectory($location);
            }

            public function createDirectory(string $location, array $config = []): void
            {
                $this->inner->createDirectory($location, $config);
            }

            public function move(string $source, string $destination, array $config = []): void
            {
                $this->inner->move($source, $destination, $config);
            }

            public function publicUrl(string $path, array $config = []): string
            {
                return $path;
            }

            public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
            {
                return $path;
            }

            public function checksum(string $path, array $config = []): string
            {
                return $this->inner->checksum($path, $config);
            }

            public function copy(string $source, string $destination, array $config = []): void
            {
                ++$this->copies;
                if ($this->failSecondCopy && $this->copies === 2) {
                    throw UnableToCopyFile::fromLocationTo($source, $destination);
                }
                $this->inner->copy($source, $destination, $config);
            }
        };

        $s3Vol = new VolumeConfig(name: 's3', label: 'S3', group: 'network', driver: 'AwsS3', driverOptions: ['bucket' => 'b']);
        $profile = $this->makeProfile('default', $this->tempDir, [], false, [
            'volumes' => ['s3'],
            'trash' => ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30, 'max_size' => '500M'],
        ]);
        $factory = new class($failingFs) implements StorageFactoryInterface {
            public function __construct(private FilesystemOperator $fs)
            {
            }

            public function create(VolumeConfig $volume): FilesystemOperator
            {
                return $this->fs;
            }
        };
        $registry = new VolumeRegistry($this->makeConfigProvider([$profile], [$s3Vol]), $factory);
        $dispatcher = new EventDispatcher();
        $purgedCount = 0;
        $dispatcher->addListener(TrashPurgedEvent::class, static function () use (&$purgedCount): void {
            ++$purgedCount;
        });

        $trashManager = new TrashManager($registry, new PathNormalizer(), $dispatcher);

        // 1. Mid-folder copy failure MUST roll back partial `.trash/<id>` directory so listTrash() has 0 orphans!
        try {
            $trashManager->moveToTrash($profile, 'album', 's3');
            self::fail('Expected mid-folder copy failure to propagate.');
        } catch (UnableToCopyFile) {
        }
        self::assertSame([], $trashManager->listTrash($profile, 's3'), 'Partial non-local folder moveToTrash failure must not leave orphan trash entries');

        // 2. Successful trash + locked deletePermanently dispatches TrashPurgedEvent cleanly
        $failingFs->failSecondCopy = false;
        $entry = $trashManager->moveToTrash($profile, 'album', 's3');
        $trashManager->deletePermanently($profile, $entry->id, 's3');
        self::assertSame(1, $purgedCount);
        self::assertSame([], $trashManager->listTrash($profile, 's3'));
    }

    public function testFileOperationsServiceSinglePassFolderDeleteAndBulkValidation(): void
    {
        $cloudDir = $this->tempDir . DIRECTORY_SEPARATOR . 's3_ops_mock';
        mkdir($cloudDir, 0777, true);
        $innerFs = new Filesystem(new LocalFilesystemAdapter($cloudDir));
        $innerFs->write('gallery/pic1.jpg', str_repeat('a', 120));
        $innerFs->write('gallery/pic2.png', str_repeat('b', 80));
        $innerFs->createDirectory('dest');

        $deepListCalls = 0;
        $countingFs = new class($innerFs, $deepListCalls) implements FilesystemOperator {
            public function __construct(private Filesystem $inner, private int &$deepCalls)
            {
            }

            public function fileExists(string $location): bool
            {
                return $this->inner->fileExists($location);
            }

            public function directoryExists(string $location): bool
            {
                return $this->inner->directoryExists($location);
            }

            public function has(string $location): bool
            {
                return $this->inner->has($location);
            }

            public function read(string $location): string
            {
                return $this->inner->read($location);
            }

            public function readStream(string $location)
            {
                return $this->inner->readStream($location);
            }

            public function listContents(string $location, bool $deep = self::LIST_SHALLOW): FlysystemDirectoryListing
            {
                if ($deep && $location === 'gallery') {
                    ++$this->deepCalls;
                }

                return $this->inner->listContents($location, $deep);
            }

            public function lastModified(string $location): int
            {
                return $this->inner->lastModified($location);
            }

            public function fileSize(string $location): int
            {
                return $this->inner->fileSize($location);
            }

            public function mimeType(string $location): string
            {
                return $this->inner->mimeType($location);
            }

            public function visibility(string $location): string
            {
                return $this->inner->visibility($location);
            }

            public function write(string $location, string $contents, array $config = []): void
            {
                $this->inner->write($location, $contents, $config);
            }

            public function writeStream(string $location, $contents, array $config = []): void
            {
                $this->inner->writeStream($location, $contents, $config);
            }

            public function setVisibility(string $location, string $visibility): void
            {
                $this->inner->setVisibility($location, $visibility);
            }

            public function delete(string $location): void
            {
                $this->inner->delete($location);
            }

            public function deleteDirectory(string $location): void
            {
                $this->inner->deleteDirectory($location);
            }

            public function createDirectory(string $location, array $config = []): void
            {
                $this->inner->createDirectory($location, $config);
            }

            public function move(string $source, string $destination, array $config = []): void
            {
                $this->inner->move($source, $destination, $config);
            }

            public function copy(string $source, string $destination, array $config = []): void
            {
                $this->inner->copy($source, $destination, $config);
            }

            public function publicUrl(string $path, array $config = []): string
            {
                return $path;
            }

            public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
            {
                return $path;
            }

            public function checksum(string $path, array $config = []): string
            {
                return $this->inner->checksum($path, $config);
            }
        };

        $vol = new VolumeConfig(name: 'cloud', label: 'Cloud', group: 'network', driver: 'AwsS3', driverOptions: ['bucket' => 'b']);
        $profile = $this->makeProfile('default', $this->tempDir, [], false, [
            'volumes' => ['cloud'],
            'storageQuota' => '10M',
            'trash' => ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30, 'max_size' => '500M'],
        ]);
        $factory = new class($countingFs) implements StorageFactoryInterface {
            public function __construct(private FilesystemOperator $fs)
            {
            }

            public function create(VolumeConfig $volume): FilesystemOperator
            {
                return $this->fs;
            }
        };
        $registry = new VolumeRegistry($this->makeConfigProvider([$profile], [$vol]), $factory);
        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $deletedEvent = null;
        $dispatcher->addListener(FolderDeletedEvent::class, static function (FolderDeletedEvent $e) use (&$deletedEvent): void {
            $deletedEvent = $e;
        });

        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $quotaChecker = new StorageQuotaChecker($registry, $normalizer, $cache);
        $trashManager = new TrashManager($registry, $normalizer, $dispatcher);
        $ops = new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trashManager, new NullMetadataStorage(), $dispatcher, null, new FileSecurityGuard(), null, $quotaChecker);

        // 1. preValidateBulkMoveItems (copy with quota) scans `gallery` ONCE, not twice
        $deepListCalls = 0;
        $ops->preValidateBulkMoveItems($profile, ['gallery'], 'dest', true, 'cloud', 'cloud');
        self::assertSame(1, $deepListCalls, 'preValidateBulkMoveItems must scan folder recursively only once');

        // 2. preValidateDeleteItems (with trash enabled) scans `gallery` ONCE, not twice
        $deepListCalls = 0;
        $ops->preValidateDeleteItems($profile, ['gallery'], 'cloud', false);
        self::assertSame(1, $deepListCalls, 'preValidateDeleteItems must scan folder recursively only once');

        // 3. Permanent delete of `gallery` scans `gallery` ONCE and emits both raster child paths on FolderDeletedEvent
        $deepListCalls = 0;
        $ops->delete($profile, 'gallery', 'cloud', true);
        self::assertSame(1, $deepListCalls, 'FileOperationsService::delete must scan folder recursively only once');
        self::assertInstanceOf(FolderDeletedEvent::class, $deletedEvent);
        self::assertCount(2, $deletedEvent->deletedChildPaths);
        self::assertCount(2, $deletedEvent->deletedChildCanonicalPaths);
    }

    public function testMessageTranslatorPosixEncodingLocalesAndImageOptimizer255ByteWebp(): void
    {
        // 1. POSIX encoding suffix `zh_TW.UTF-8` must allow regional catalogue `zh_TW`
        $stubTranslator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                if ($locale === 'zh_TW' && $id === 'actions.save') {
                    return '儲存';
                }

                return $id;
            }

            public function getLocale(): string
            {
                return 'zh_TW.UTF-8';
            }
        };
        $msgTranslator = new MessageTranslator($stubTranslator);
        self::assertSame('儲存', $msgTranslator->trans('actions.save', [], 'zh_TW.UTF-8'));

        // 2. ImageOptimizer::replaceExtension() keeps 255-byte CJK `.jpg` within 255 bytes when converted to `.webp`
        $optimizer = new ImageOptimizer();
        $replaceExt = new ReflectionMethod(ImageOptimizer::class, 'replaceExtension');
        $longCjkJpg = str_repeat('漢', 83) . 'ab.jpg'; // 83 * 3 + 2 + 4 = 255 bytes
        self::assertSame(255, strlen($longCjkJpg));

        $converted = $replaceExt->invoke($optimizer, $longCjkJpg, 'webp');
        self::assertIsString($converted);
        self::assertLessThanOrEqual(255, strlen($converted), 'Converted .webp filename must never exceed 255 bytes');
        self::assertTrue(str_ends_with($converted, '.webp'));
        self::assertTrue(mb_check_encoding($converted, 'UTF-8'));
    }

    private function makeResolverForIdentifier(string $identifier): DynamicPathResolver
    {
        $user = new InMemoryUser($identifier, null);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new DynamicPathResolver($security);
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
            overwritePolicy: is_string($overrides['overwritePolicy'] ?? null) ? $overrides['overwritePolicy'] : 'rename',
            chunkUpload: ['enabled' => true, 'chunk_size' => '1M', 'parallel_uploads' => 3, 'max_retries' => 2],
            naming: [],
            imageOptimization: ['enabled' => false],
            thumbnail: ['enabled' => false, 'width' => 200, 'height' => 200, 'format' => 'webp', 'quality' => 80, 'cache_dir' => $this->tempDir . '/thumbs'],
            trash: is_array($overrides['trash'] ?? null) ? $overrides['trash'] : ['enabled' => false, 'directory' => '.trash', 'retention_days' => 30, 'max_size' => '500M'],
            zip: ['enabled' => true, 'allow_extract' => false, 'max_extract_files' => 100, 'max_extract_size' => '10M'],
            ui: [],
            permissions: $permissions,
            security: ['voter_enabled' => $voterEnabled, 'csrf_protection' => false, 'sanitize_svg' => true],
            cache: is_array($overrides['cache'] ?? null) ? $overrides['cache'] : ['enabled' => false, 'ttl' => 300],
            metadata: ['enabled' => false],
            storageQuota: is_string($overrides['storageQuota'] ?? null) ? $overrides['storageQuota'] : null,
            share: ['enabled' => true, 'default_ttl' => 3600, 'max_ttl' => 86400, 'allow_inline_preview' => true],
            usageGuard: ['enabled' => true, 'policy' => 'warn'],
        );
    }

    /**
     * @param list<ProfileConfig> $profiles
     * @param list<VolumeConfig>  $volumes
     */
    private function makeConfigProvider(array $profiles, array $volumes = []): ConfigProviderInterface
    {
        $pMap = [];
        foreach ($profiles as $p) {
            $pMap[$p->name] = $p;
        }
        $vMap = [];
        foreach ($volumes as $v) {
            $vMap[$v->name] = $v;
        }

        return new class($pMap, $vMap) implements ConfigProviderInterface {
            /**
             * @param array<string, ProfileConfig> $pMap
             * @param array<string, VolumeConfig>  $vMap
             */
            public function __construct(private array $pMap, private array $vMap)
            {
            }

            public function hasProfile(string $name): bool
            {
                return isset($this->pMap[$name]);
            }

            public function getProfile(string $name): ProfileConfig
            {
                if (!isset($this->pMap[$name])) {
                    throw new RuntimeException('Unknown profile ' . $name);
                }

                return $this->pMap[$name];
            }

            public function getProfileNames(): array
            {
                return array_keys($this->pMap);
            }

            public function hasVolume(string $name): bool
            {
                return isset($this->vMap[$name]);
            }

            public function getVolume(string $name): VolumeConfig
            {
                if (!isset($this->vMap[$name])) {
                    throw new RuntimeException('Unknown volume ' . $name);
                }

                return $this->vMap[$name];
            }

            public function getVolumes(): array
            {
                return $this->vMap;
            }
        };
    }

    private function dispatchResponseEvent(AutoInjectDialogHostSubscriber $sub, string $html): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/page', 'GET');
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $sub->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
