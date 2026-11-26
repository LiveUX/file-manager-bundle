<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use LiveUX\FileManagerBundle\Bridge\SymfonyAI\SymfonyAiAssetEnricher;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use ZipArchive;

/**
 * Regression suite for Pass-7: W3C CSS url() comment-desync SVG hardening, CJK dot-equivalence,
 * canonical NFC path normalization, dotted folder names, Hangul/Emoji variation selector slugging,
 * restore quota accounting, metadata pruning on partial copy, and default-deny extract_zip without voter.
 */
final class SeventhPassGlobalSecurityAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_seventh_' . bin2hex(random_bytes(4));
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

    /**
     * @param array<string, mixed> $profileOverrides
     */
    private function createProfile(array $profileOverrides = []): ProfileConfig
    {
        $provider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5', 'locale' => 'en'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => array_merge(['volumes' => ['default']], $profileOverrides)],
        );

        return $provider->getProfile('default');
    }

    public function testSvgSanitizerBlocksUnquotedUrlCommentLexerDesynchronization(): void
    {
        $sanitizer = new SvgSanitizer();

        // W3C CSS Syntax Level 3 §4.3.6: /* inside unquoted url(#x?a=/*) does NOT open a comment in browsers.
        $maliciousSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <style>
        rect { filter: url(#x?a=/*); }
        @import "https://evil.example/xss.css";
        circle { filter: url(#y?b=*/); fill: red; }
    </style>
    <rect width="50" height="50" style="filter: url(#x?a=/*); background: url(https://evil.example/track.png); --x: url(#y?b=*/)"/>
</svg>
SVG;

        $clean = $sanitizer->sanitize($maliciousSvg);
        self::assertStringNotContainsString('<style', $clean);
        self::assertStringNotContainsString('@import', $clean);
        self::assertStringNotContainsString('evil.example', $clean);
        self::assertStringNotContainsString('style=', $clean);

        // Legitimate local SVG fragment IDs in <style> and style="" must still be preserved.
        $safeSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <defs><linearGradient id="grad-1_a"><stop offset="0%" stop-color="#f00"/></linearGradient></defs>
    <style>rect { fill: url(#grad-1_a); } circle { fill: url("#grad-1_a"); }</style>
    <rect width="50" height="50" style="fill: url(#grad-1_a)"/>
</svg>
SVG;
        $cleanSafe = $sanitizer->sanitize($safeSvg);
        self::assertStringContainsString('<style>', $cleanSafe);
        self::assertStringContainsString('url(#grad-1_a)', $cleanSafe);
        self::assertStringContainsString('style="fill: url(#grad-1_a)"', $cleanSafe);
    }

    public function testCjkDotEquivalentsBlockedInReservedNamesHiddenFilesAndExtensions(): void
    {
        $guard = new FileSecurityGuard();
        foreach (["aux\u{3002}jpg", "con\u{FF61}txt", "nul\u{FE52}png", "\u{3002}htaccess", "\u{FF61}env", "shell\u{3002}php\u{3002}jpg"] as $badName) {
            try {
                $guard->assertSafeFilename($badName);
                self::fail(sprintf('Expected FileSecurityGuard::assertSafeFilename to reject "%s".', $badName));
            } catch (UploadValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $normalizer = new PathNormalizer();
        foreach (["aux\u{3002}jpg/file.txt", "lpt1\u{FF61}log/a.txt", "\u{3002}\u{3002}/secret.txt", "folder\u{FF61}/a.txt"] as $badPath) {
            try {
                $normalizer->normalize($badPath);
                self::fail(sprintf('Expected PathNormalizer::normalize to reject "%s".', $badPath));
            } catch (InvalidPathException) {
                $this->addToAssertionCount(1);
            }
        }

        $profile = $this->createProfile(['ignore_dot_files' => true]);
        foreach (["\u{3002}secret/file.txt", "\u{FF61}hidden"] as $hiddenCjkPath) {
            try {
                $guard->assertAccessiblePath($hiddenCjkPath, $profile);
                self::fail(sprintf('Expected assertAccessiblePath to block "%s".', $hiddenCjkPath));
            } catch (InvalidPathException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPathNormalizerReturnsCanonicalNfcForDecomposedNfdInputs(): void
    {
        $normalizer = new PathNormalizer();
        // Decomposed NFD "c\u{0327}o\u{0308}p/u\u{0308}ru\u{0308}n.jpg" -> NFC "çöp/ürün.jpg"
        $nfdPath = "c\u{0327}o\u{0308}p/u\u{0308}ru\u{0308}n.jpg";
        $normalized = $normalizer->normalize($nfdPath);

        self::assertSame('çöp/ürün.jpg', $normalized);
        self::assertSame(strlen('çöp'), strpos($normalized, '/'));
    }

    public function testDottedFolderNamesAndRenameWithInternalDotsAreSupportedGlobally(): void
    {
        $profile = $this->createProfile([
            'naming' => [
                'slugger' => false,
                'transformer' => 'none',
                'allow_extension_change' => false,
                'apply_on_rename' => true,
            ],
        ]);

        $fnNormalizer = new FilenameNormalizer();

        // Folders with dots + spaces, Persian ZWNJ, or ".js" project folder names must be allowed.
        self::assertSame('Acme. Co', $fnNormalizer->normalizeFolderName('Acme. Co', $profile));
        self::assertSame('v1.0 beta', $fnNormalizer->normalizeFolderName('v1.0 beta', $profile));
        self::assertSame("v1.0_\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}", $fnNormalizer->normalizeFolderName("v1.0_\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}", $profile));
        self::assertSame('nextjs.js', $fnNormalizer->normalizeFolderName('nextjs.js', $profile));

        // Renaming "avatar.jpg" by typing "Dr. Smith" or "v1.0" (omitting .jpg) preserves ".jpg".
        self::assertSame('Dr. Smith.jpg', $fnNormalizer->normalizeForRename('avatar.jpg', 'Dr. Smith', $profile));
        self::assertSame('v1.0.jpg', $fnNormalizer->normalizeForRename('avatar.jpg', 'v1.0', $profile));

        // Explicitly changing extension to another file extension (.png or .php) is still blocked when allow_extension_change=false.
        $this->expectException(UploadValidationException::class);
        $fnNormalizer->normalizeForRename('avatar.jpg', 'avatar.png', $profile);
    }

    public function testHangulFillersVariationSelectorsAndByteTruncationNeverProduceInvalidFilenames(): void
    {
        $profileSlugOff = $this->createProfile([
            'naming' => [
                'slugger' => false,
                'transformer' => 'none',
            ],
        ]);
        $profileSlugOn = $this->createProfile([
            'naming' => [
                'slugger' => true,
                'transformer' => 'none',
            ],
        ]);
        $fnNormalizer = new FilenameNormalizer();

        // Emoji + Variation Selector-16 (U+FE0F) + ZWJ or Hangul Filler (U+3164) must normalize cleanly in both slugger modes.
        $emojiUploadOff = $fnNormalizer->normalizeForUpload("👁️‍🗨️.jpg", $profileSlugOff);
        (new FileSecurityGuard())->assertSafeFilename($emojiUploadOff);
        self::assertSame('🚀.jpg', $fnNormalizer->normalizeForUpload("🚀\u{3164}.jpg", $profileSlugOff));
        self::assertSame('file.jpg', $fnNormalizer->normalizeForUpload("👁️‍🗨️.jpg", $profileSlugOn));
        self::assertSame('file.jpg', $fnNormalizer->normalizeForUpload("🚀\u{3164}.jpg", $profileSlugOn));

        // Truncating a stem where byte 200 lands right after a Persian ZWNJ (U+200C) or CJK full stop (U+3002)
        // must strip the exposed trailing ZWNJ / CJK dot so FileSecurityGuard::assertSafeFilename passes.
        $prefix = str_repeat('a', 196);
        $stemWithZwnj = $prefix . "\u{200C}" . 'ببب';
        $truncated = UnicodeHelper::truncateStemBytes($stemWithZwnj, 200);
        self::assertFalse(UnicodeHelper::hasEdgeInvisibleOrTrailingDot($truncated));
        (new FileSecurityGuard())->assertSafeFilename($truncated . '.jpg');
    }

    public function testAuthorizerDeniesExtractZipByDefaultWhenOmittedFromPermissionsAndVoterDisabled(): void
    {
        $profile = $this->createProfile([
            'permissions' => ['view' => true, 'upload' => true],
            'security' => ['voter_enabled' => false],
        ]);
        $authorizer = new FileManagerAuthorizer(null);

        self::assertTrue($authorizer->isGranted($profile, FileManagerPermissions::VIEW));
        self::assertTrue($authorizer->isGranted($profile, FileManagerPermissions::UPLOAD));
        self::assertFalse($authorizer->isGranted($profile, FileManagerPermissions::EXTRACT_ZIP));
    }

    public function testExternalVolumeCanonicalKeyDoesNotCollideWithEightHexDateFolder(): void
    {
        $baseDir = $this->tempDir . DIRECTORY_SEPARATOR . 'public_uploads';
        $extDir = $this->tempDir . DIRECTORY_SEPARATOR . 'external_storage';
        mkdir($baseDir, 0775, true);
        mkdir($extDir, 0775, true);

        $normalizer = new PathNormalizer();
        $defaultVol = new VolumeConfig(name: 'default', label: 'Default', group: 'local', driver: 'LocalFileSystem', path: $baseDir);
        $extVol = new VolumeConfig(name: 'ext', label: 'External', group: 'local', driver: 'LocalFileSystem', path: $extDir);

        $normalizedExtDir = rtrim(str_replace('\\', '/', $extDir), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedExtDir = mb_strtolower($normalizedExtDir, 'UTF-8');
        }
        $extHash = substr(md5($normalizedExtDir), 0, 8);
        $keyInDefault = $normalizer->buildCanonicalKey($defaultVol, $extHash . '/external_storage/photo.jpg', $baseDir);
        $keyInExternal = $normalizer->buildCanonicalKey($extVol, 'photo.jpg', $baseDir);

        self::assertNotSame($keyInDefault, $keyInExternal);
        self::assertStringStartsWith('local::@ext_' . $extHash, $keyInExternal);

        $cacheManager = new DirectoryCacheManager();
        $ref = new ReflectionMethod(DirectoryCacheManager::class, 'extractVolumeScope');
        self::assertSame('local', $ref->invoke($cacheManager, $keyInDefault));
        self::assertSame('local::@ext_' . $extHash, $ref->invoke($cacheManager, $keyInExternal));
    }

    public function testTrashRestoreDoesNotDoubleCountQuotaAndPreservesDottedFolderNames(): void
    {
        $configProvider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['default'],
                    'storage_quota' => '100B',
                    'trash' => ['enabled' => true, 'directory' => '.trash'],
                ],
            ],
        );
        $profile = $configProvider->getProfile('default');
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $quotaChecker = new StorageQuotaChecker($registry, $normalizer, new DirectoryCacheManager());
        $trashManager = new TrashManager($registry, $normalizer, new EventDispatcher(), new FileSecurityGuard(), null, null, $quotaChecker);

        // Create a 70-byte file inside a dotted folder "v1.0.release" (70% of 100B quota).
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'v1.0.release', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'v1.0.release' . DIRECTORY_SEPARATOR . 'notes.txt', str_repeat('x', 70));

        $entry = $trashManager->moveToTrash($profile, 'v1.0.release', 'default');

        // Re-create "v1.0.release" (0 bytes) to force collision resolution during restore.
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'v1.0.release', 0775, true);

        // Restoring must NOT throw storage_quota_exceeded (70B in trash is moved back, net delta = 0)
        // and must append "_restored_..." at the end of the folder name rather than before ".release".
        $restored = $trashManager->restore($profile, $entry->id, 'default');
        self::assertStringStartsWith('v1.0.release_restored_', $restored->originalPath);
        self::assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . $restored->originalPath);
    }

    public function testZipExtractionAllowsDottedDirectoriesInArchive(): void
    {
        $configProvider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: [
                'default' => [
                    'volumes' => ['default'],
                    'upload_allow' => ['text/plain'],
                    'zip' => ['enabled' => true, 'allow_extract' => true],
                    'permissions' => ['view' => true, 'upload' => true, 'create_folder' => true, 'extract_zip' => true],
                    'security' => ['voter_enabled' => false],
                ],
            ],
        );
        $profile = $configProvider->getProfile('default');
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'release.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addEmptyDir('v1.0 beta/');
        $zip->addFromString('v1.0 beta/readme.txt', 'hello world');
        $zip->close();

        $service = new ZipArchiveService($registry, new PathNormalizer());
        $result = $service->extractArchive($profile, 'release.zip', 'extracted_out', 'default');

        self::assertSame(1, $result['extracted']);
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'extracted_out' . DIRECTORY_SEPARATOR . 'v1.0 beta' . DIRECTORY_SEPARATOR . 'readme.txt');
    }

    public function testCopyDirectoryPrunesMetadataOfVoterDeniedChildFiles(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'team', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'team' . DIRECTORY_SEPARATOR . 'public.txt', 'hello');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'team' . DIRECTORY_SEPARATOR . 'secret.txt', 'classified');

        $configProvider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => ['volumes' => ['default'], 'security' => ['voter_enabled' => true]]],
        );
        $profile = $configProvider->getProfile('default');
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $metaStorage = new JsonFileMetadataStorage($this->tempDir . DIRECTORY_SEPARATOR . 'meta.json');
        $volume = $registry->getVolumeForProfile($profile, 'default');

        $metaStorage->save(
            $normalizer->buildCanonicalKey($volume, 'team/public.txt', $profile->basePath),
            new FolderMetadata(title: 'Public Doc'),
        );
        $metaStorage->save(
            $normalizer->buildCanonicalKey($volume, 'team/secret.txt', $profile->basePath),
            new FolderMetadata(title: 'Secret Salaries'),
        );

        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(static function (mixed $attribute, mixed $subject): bool {
            if (is_object($subject) && property_exists($subject, 'path') && $subject->path === 'team/secret.txt') {
                return false;
            }

            return true;
        });

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MetadataSyncListener($metaStorage, null, $registry));

        $ops = new FileOperationsService(
            $registry,
            $normalizer,
            new FilenameNormalizer(),
            new TrashManager($registry, $normalizer, $dispatcher),
            $metaStorage,
            $dispatcher,
            $authChecker,
        );

        $ops->cloneItem($profile, 'team', 'team-copy', 'default');

        $copiedPublicKey = $normalizer->buildCanonicalKey($volume, 'team-copy/public.txt', $profile->basePath);
        $copiedSecretKey = $normalizer->buildCanonicalKey($volume, 'team-copy/secret.txt', $profile->basePath);

        self::assertSame('Public Doc', $metaStorage->get($copiedPublicKey)->title);
        self::assertTrue($metaStorage->get($copiedSecretKey)->isEmpty(), 'Metadata for uncopied secret.txt must not leak into team-copy/');
    }

    public function testPickerValidatorBlocksDangerousExtensionsAndHiddenDotPaths(): void
    {
        $ref = new ReflectionMethod(FileManagerPickerType::class, 'isSafePickerValue');

        self::assertTrue($ref->invoke(null, 'banners/hero.jpg', 'path'));
        self::assertFalse($ref->invoke(null, '.trash/2026_abc/photo.jpg', 'path'));
        self::assertFalse($ref->invoke(null, 'banners/shell.php', 'path'));
        self::assertFalse($ref->invoke(null, "banners/aux\u{3002}jpg", 'path'));
        self::assertFalse($ref->invoke(null, '/_liveux_file_manager/download?profile=default&path=.trash/a.jpg', 'url'));
        self::assertFalse($ref->invoke(null, '/uploads/shell.php', 'url'));
        self::assertTrue($ref->invoke(null, '/_liveux_file_manager/download?profile=default&path=docs%2Fguide.pdf', 'url'));
    }

    public function testRemoteUrlImporterAndAiSanitizationAndIconRegistryFixes(): void
    {
        $configProvider = new YamlConfigProvider(
            defaults: ['base_path' => $this->tempDir, 'theme' => 'bootstrap5'],
            volumes: ['default' => ['driver' => 'LocalFileSystem', 'path' => $this->tempDir]],
            profiles: ['default' => ['volumes' => ['default']]],
        );
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $uploadManager = new UploadManager($registry, $normalizer, new FilenameNormalizer(), new EventDispatcher());

        // RemoteUrlImporter ignores blocked extensions (.php / .html) from URL when content is a valid image
        $tempImg = $this->tempDir . DIRECTORY_SEPARATOR . 'sample.png';
        file_put_contents($tempImg, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $importer = new RemoteUrlImporter($uploadManager, $registry, $normalizer);
        $extractRef = new ReflectionMethod(RemoteUrlImporter::class, 'extractFilename');
        $extractedName = $extractRef->invoke($importer, 'https://cdn.example.com/render.php', ['Content-Type: image/png'], $tempImg);
        self::assertSame('render.png', $extractedName);

        // AssetEnrichmentResult strips BiDi override/isolate controls and normalizes NFD to NFC
        $aiResult = new AssetEnrichmentResult(
            alt: "\u{202E}o\u{0308}rnek\u{2066}",
            title: "T\u{0085}itle",
        );
        self::assertSame('örnek', $aiResult->alt);
        self::assertSame('Title', $aiResult->title);

        // SymfonyAiAssetEnricher does not advertise blocked XML MIME types
        $enricher = new SymfonyAiAssetEnricher(null);
        self::assertFalse($enricher->supports('text/xml'));
        self::assertFalse($enricher->supports('application/xml'));

        // IconSetRegistry loads custom badge SVGs inside folders containing glob metacharacters like "[custom]"
        $bracketDir = $this->tempDir . DIRECTORY_SEPARATOR . 'icons_[v1]';
        mkdir($bracketDir . DIRECTORY_SEPARATOR . 'badges', 0775, true);
        file_put_contents(
            $bracketDir . DIRECTORY_SEPARATOR . 'badges' . DIRECTORY_SEPARATOR . 'custom_badge.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6"/></svg>',
        );
        $iconRegistry = new IconSetRegistry(\dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'icons');
        $payload = $iconRegistry->getIconSetPayload($bracketDir, true);
        self::assertArrayHasKey('custom_badge', $payload['badges']);
        self::assertStringContainsString('<circle', $payload['badges']['custom_badge']);
    }
}
