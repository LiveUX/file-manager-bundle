<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Forms;
use Throwable;

/**
 * Regression suite for the third (line-by-line) security audit pass.
 */
final class ThirdPassAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_third_' . bin2hex(random_bytes(4));
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

    public function testTrashDirectoryGuardIsCaseInsensitive(): void
    {
        $guard = new FileSecurityGuard();
        $profile = $this->createProfile();

        foreach (['.TRASH/20260101_ab/secret.pdf', '.Trash', 'docs/.TrAsH/x.pdf'] as $path) {
            $blocked = false;
            try {
                $guard->assertAccessiblePath($path, $profile);
            } catch (InvalidPathException) {
                $blocked = true;
            }
            self::assertTrue($blocked, sprintf('"%s" must be blocked on case-insensitive filesystems.', $path));
        }
    }

    public function testBrokenExcludePatternFailsClosedInsteadOfOpen(): void
    {
        $guard = new FileSecurityGuard();
        $profile = $this->createProfile(['/(unclosed/']);

        $this->expectException(InvalidPathException::class);
        $guard->assertAccessiblePath('docs/report.pdf', $profile);
    }

    public function testExtendedExecutableExtensionsAreBlocked(): void
    {
        $guard = new FileSecurityGuard();
        foreach (['a.php6', 'a.phtm', 'a.hta', 'a.jar', 'a.swf', 'a.xsl'] as $name) {
            $blocked = false;
            try {
                $guard->assertSafeFilename($name);
            } catch (Throwable) {
                $blocked = true;
            }
            self::assertTrue($blocked, $name . ' must be blocked.');
        }
    }

    public function testThumbnailCacheClearOnlyDeletesGeneratedFilesAndSecretMakesNamesUnguessable(): void
    {
        $hash = md5('local::/a.png|320x240|contain|q80');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . $hash . '.webp', 'x');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . '20261124.jpg', 'user-photo-8-hex-digits');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'keep-me.txt', 'important');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'config.php', '<?php');

        $manager = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        self::assertSame(1, $manager->clearCacheDir($this->tempDir));
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . '20261124.jpg');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'keep-me.txt');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'config.php');

        $profile = $this->createProfile(thumbnail: ['enabled' => true, 'cache_dir' => $this->tempDir, 'format' => 'webp']);
        $plain = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $secret = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver(), null, 's3cr3t-kernel-secret');

        $plainName = basename($plain->getCachedPath($profile, 'local::/a.png'));
        $secretName = basename($secret->getCachedPath($profile, 'local::/a.png'));

        self::assertNotSame($plainName, $secretName);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\./', $secretName);
        self::assertNotSame(md5('local::/a.png|320x240|contain|q80'), pathinfo($secretName, PATHINFO_FILENAME));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function pickerValues(): iterable
    {
        yield 'relative upload path' => ['/uploads/a.jpg', true];
        yield 'plain relative path' => ['2026/a.jpg', true];
        yield 'https cdn' => ['https://cdn.example.com/a.jpg', true];
        yield 'dots in name' => ['/uploads/a..b.png', true];
        yield 'javascript scheme' => ['javascript:alert(1)', false];
        yield 'obfuscated javascript' => ["java\tscript:alert(1)", false];
        yield 'data scheme' => ['data:text/html;base64,PHNjcmlwdD4=', false];
        yield 'protocol relative' => ['//evil.example/x.png', false];
        yield 'backslash host' => ['/\\evil.example/x.png', false];
        yield 'traversal' => ['/uploads/../../etc/passwd', false];
        yield 'crlf control char' => ["/uploads/a.jpg\r\nX-Injected: 1", false];
        yield 'nul byte' => ["/uploads/a.jpg\0.png", false];
    }

    #[DataProvider('pickerValues')]
    public function testPickerTypeRejectsUnsafeValuesServerSide(string $value, bool $valid): void
    {
        $form = Forms::createFormFactoryBuilder()->getFormFactory()->create(FileManagerPickerType::class);
        $form->submit($value);

        self::assertSame($valid, $form->isValid(), $value);
    }

    public function testPickerTypeRejectsUnsafeValueInsideMultipleSelection(): void
    {
        $form = Forms::createFormFactoryBuilder()->getFormFactory()->create(FileManagerPickerType::class, [], ['multiple' => true]);
        $form->submit(json_encode(['/uploads/a.jpg', 'javascript:alert(1)']));

        self::assertFalse($form->isValid());
    }

    public function testTrashRefusesItemLargerThanMaxSizeWithoutPurgingExistingTrash(): void
    {
        $uploadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'uploads';
        mkdir($uploadDir, 0775, true);
        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . 'small.txt', str_repeat('s', 100));
        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . 'huge.bin', str_repeat('h', 4096));

        $profile = $this->createProfile(trash: ['enabled' => true, 'directory' => '.trash', 'max_size' => '1K']);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $uploadDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($vol);
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $trash = new TrashManager($registry, new PathNormalizer(), new EventDispatcher());

        $trash->moveToTrash($profile, 'small.txt', 'local');
        self::assertCount(1, $trash->listTrash($profile, 'local'));

        try {
            $trash->moveToTrash($profile, 'huge.bin', 'local');
            self::fail('Expected FileManagerException when trashing an item larger than trash.max_size.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.trash_item_too_large', $e->getTranslationKey());
        }

        self::assertCount(1, $trash->listTrash($profile, 'local'), 'Existing trash entries must NOT be purged.');
        self::assertFileExists($uploadDir . DIRECTORY_SEPARATOR . 'huge.bin', 'Oversized file must remain untouched.');
    }

    public function testSvgSanitizerRemovesNamespacedHrefCommentsAndCdata(): void
    {
        $sanitizer = new SvgSanitizer();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/1999/xlink">'
            . '<!-- hidden comment -->'
            . '<a x:href="https://evil.example/phish"><rect width="10" height="10"/></a>'
            . '<title><![CDATA[raw-cdata]]></title>'
            . '</svg>';

        $clean = $sanitizer->sanitize($svg);
        self::assertStringNotContainsString('evil.example', $clean);
        self::assertStringNotContainsString('x:href', $clean);
        self::assertStringNotContainsString('hidden comment', $clean);
        self::assertStringNotContainsString('CDATA', $clean);
    }

    public function testRemoteUrlImporterRejectsBackslashAndObfuscatedOctalShortIpv4(): void
    {
        $profile = $this->createProfile();
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($vol);
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $normalizer = new PathNormalizer();
        $uploadManager = new UploadManager($registry, $normalizer, new FilenameNormalizer(), new EventDispatcher());
        $importer = new RemoteUrlImporter($uploadManager, $registry, $normalizer);

        foreach ([
            'https://127.0.0.1\\@example.com/a.png',
            'http://0177.0.0.1/a.png',
            'http://127.1/a.png',
            'http://0x7f.0.0.1/a.png',
        ] as $url) {
            $blocked = false;
            try {
                $importer->assertSafeUrl($url);
            } catch (FileManagerException) {
                $blocked = true;
            }
            self::assertTrue($blocked, sprintf('URL "%s" must be blocked.', $url));
        }

        // Windows conin$ / conout$ device names are also blocked by PathNormalizer
        $deviceBlocked = false;
        try {
            $normalizer->normalize('docs/conin$.txt');
        } catch (InvalidPathException) {
            $deviceBlocked = true;
        }
        self::assertTrue($deviceBlocked);
    }

    public function testDirectoryCacheInvalidateVolumeCoversLocalSubfolders(): void
    {
        $cache = new DirectoryCacheManager(new ArrayAdapter());
        $profile = $this->createProfile(cache: ['enabled' => true, 'ttl' => 300]);

        $rootFirst = $cache->remember($profile, 'local::', static fn (): string => 'root-v1');
        $subFirst = $cache->remember($profile, 'local::products/2026', static fn (): string => 'sub-v1');
        self::assertFalse($rootFirst['hit']);
        self::assertFalse($subFirst['hit']);

        self::assertTrue($cache->remember($profile, 'local::', static fn (): string => 'root-v2')['hit']);
        self::assertTrue($cache->remember($profile, 'local::products/2026', static fn (): string => 'sub-v2')['hit']);

        $cache->invalidateVolume('local::');

        $rootAfter = $cache->remember($profile, 'local::', static fn (): string => 'root-v2');
        $subAfter = $cache->remember($profile, 'local::products/2026', static fn (): string => 'sub-v2');
        self::assertFalse($rootAfter['hit']);
        self::assertFalse($subAfter['hit']);
        self::assertSame('root-v2', $rootAfter['data']);
        self::assertSame('sub-v2', $subAfter['data']);
    }

    public function testDuplicateDetectorReverifiesCachedCandidateAgainstLiveContent(): void
    {
        $uploadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'dup_uploads';
        mkdir($uploadDir, 0775, true);
        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . 'a.txt', 'identical-payload-123');
        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . 'b.txt', 'identical-payload-123');

        $incoming = $this->tempDir . DIRECTORY_SEPARATOR . 'incoming.txt';
        file_put_contents($incoming, 'identical-payload-123');

        $profile = $this->createProfile(cache: ['enabled' => true, 'ttl' => 300]);
        $vol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $uploadDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($vol);

        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $detector = new DuplicateDetector($registry, new PathNormalizer(), new DirectoryCacheManager(new ArrayAdapter()));

        // Prime the cached hash index
        $firstMatch = $detector->findDuplicate($profile, 'local', $incoming);
        self::assertContains($firstMatch, ['a.txt', 'b.txt']);

        // Mutate whichever file matched first on disk WITHOUT invalidating the cache
        $otherFile = $firstMatch === 'a.txt' ? 'b.txt' : 'a.txt';
        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $firstMatch, 'externally-modified-content');

        // Must skip the stale first candidate and return the still-matching sibling
        self::assertSame($otherFile, $detector->findDuplicate($profile, 'local', $incoming));

        // Mutate the remaining candidate as well -> must return null instead of a false positive
        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $otherFile, 'also-changed');
        self::assertNull($detector->findDuplicate($profile, 'local', $incoming));
    }

    public function testJsonFileMetadataStorageWritesAtomicallyAndParsesBooleanPinnedStrictly(): void
    {
        $metaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'folder_metadata.json';
        $storage = new JsonFileMetadataStorage($metaPath);
        $storage->save('local::docs', new FolderMetadata(color: '#2563eb', pinned: true));

        $reloaded = new JsonFileMetadataStorage($metaPath);
        self::assertTrue($reloaded->get('local::docs')->pinned);
        self::assertSame('#2563eb', $reloaded->get('local::docs')->color);

        $leftoverTemps = glob($this->tempDir . DIRECTORY_SEPARATOR . 'folder_metadata.json.tmp.*');
        self::assertSame([], is_array($leftoverTemps) ? $leftoverTemps : []);

        self::assertFalse(FolderMetadata::fromArray(['pinned' => 'false'])->pinned);
        self::assertFalse(FolderMetadata::fromArray(['pinned' => '0'])->pinned);
        self::assertTrue(FolderMetadata::fromArray(['pinned' => 'true'])->pinned);
        self::assertTrue(FolderMetadata::fromArray(['pinned' => true])->pinned);
    }

    public function testFlysystemStorageFactoryHidesInternalServiceIdInException(): void
    {
        $factory = new FlysystemStorageFactory(new DynamicPathResolver());
        $vol = new VolumeConfig(
            name: 'custom_fs',
            label: 'Custom FS',
            group: 'network',
            driver: 'FlysystemService',
            path: null,
            driverOptions: ['service' => 'app.internal.secret_flysystem_service'],
        );

        try {
            $factory->create($vol);
            self::fail('Expected FileManagerException for missing Flysystem service.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.storage_unavailable', $e->getTranslationKey());
            self::assertStringNotContainsString('app.internal.secret_flysystem_service', $e->getMessage());
        }
    }

    public function testIconSetRegistryStripsSingleQuotedWidthAndHeightAttributes(): void
    {
        $customIcons = $this->tempDir . DIRECTORY_SEPARATOR . 'custom_icons';
        mkdir($customIcons . DIRECTORY_SEPARATOR . 'ui', 0775, true);
        file_put_contents(
            $customIcons . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'star.svg',
            "<svg width='24' height='24' viewBox=\"0 0 24 24\"><rect width=\"10\" height=\"10\"/></svg>",
        );

        $bundleIconsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'icons';
        $registry = new IconSetRegistry($bundleIconsDir);

        $rendered = $registry->renderInlineIcon('star', $customIcons, 16, 'ui', 'fm-icon');
        self::assertStringContainsString('width="16" height="16"', $rendered);
        self::assertStringNotContainsString("width='24'", $rendered);
        self::assertStringNotContainsString("height='24'", $rendered);
        self::assertStringContainsString('<rect width="10" height="10"', $rendered);
    }

    /**
     * @param list<string>         $excludePatterns
     * @param array<string, mixed> $thumbnail
     * @param array<string, mixed> $trash
     * @param array<string, mixed> $cache
     */
    private function createProfile(
        array $excludePatterns = [],
        array $thumbnail = [],
        array $trash = ['enabled' => true, 'directory' => '.trash'],
        array $cache = [],
    ): ProfileConfig {
        return new ProfileConfig(
            name: 'test',
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
            uploadAllow: [],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: [],
            naming: [],
            imageOptimization: [],
            thumbnail: $thumbnail,
            trash: $trash,
            zip: [],
            ui: [],
            permissions: [],
            security: [],
            cache: $cache,
            metadata: [],
        );
    }
}
