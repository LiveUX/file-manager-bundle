<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use ZipArchive;

final class MultilingualUnicodeTest extends TestCase
{
    private string $tempDir;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_i18n_' . bin2hex(random_bytes(4));
        $this->storageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'uploads';
        mkdir($this->storageDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testLocaleIndependentUtf8PathHelpersWorkUnderCLocale(): void
    {
        $previousLocale = setlocale(LC_CTYPE, '0');
        @setlocale(LC_CTYPE, 'C');

        try {
            $cases = [
                'フォルダ/東京レポート.pdf' => ['東京レポート.pdf', 'フォルダ', '東京レポート', 'pdf'],
                'مجلد الصور/تقرير مالي 2026.PDF' => ['تقرير مالي 2026.PDF', 'مجلد الصور', 'تقرير مالي 2026', 'pdf'],
                '产品目录/新款图片#1.webp' => ['新款图片#1.webp', '产品目录', '新款图片#1', 'webp'],
                '한국어 폴더/보고서 2026.png' => ['보고서 2026.png', '한국어 폴더', '보고서 2026', 'png'],
                'Отчёты/Финансы.docx' => ['Финансы.docx', 'Отчёты', 'Финансы', 'docx'],
                '日本語.jpg' => ['日本語.jpg', '', '日本語', 'jpg'],
            ];

            foreach ($cases as $path => [$base, $dir, $stem, $ext]) {
                self::assertSame($base, PathNormalizer::basenameUtf8($path));
                self::assertSame($dir, PathNormalizer::dirnameUtf8($path));
                self::assertSame($stem, PathNormalizer::stemUtf8($path));
                self::assertSame($ext, PathNormalizer::extensionUtf8($path));
            }
        } finally {
            if (is_string($previousLocale)) {
                @setlocale(LC_CTYPE, $previousLocale);
            }
        }
    }

    public function testPathNormalizerAndSecurityGuardRejectInvalidUtf8AndBidiTrojanSpoofing(): void
    {
        $normalizer = new PathNormalizer();
        $guard = new FileSecurityGuard();

        // Legitimate RTL Arabic, Hebrew, CJK, Korean, Greek, Cyrillic pass cleanly
        foreach ([
            'مجلد/تقرير مالي 2026.pdf',
            'תיקייה/דוח_2026.pdf',
            'フォルダ/東京レポート.pdf',
            '图片/产品目录 2026.webp',
            '폴더/보고서 2026.png',
            'Αρχεία/Έκθεση.pdf',
            'Отчёты/Привет.txt',
        ] as $valid) {
            self::assertSame($valid, $normalizer->normalize($valid));
            $guard->assertSafeFilename(PathNormalizer::basenameUtf8($valid));
        }

        // Invalid UTF-8 bytes (e.g. legacy Shift-JIS / Windows-1256) rejected cleanly
        try {
            $normalizer->normalize("legacy_\x82\xA0.txt");
            self::fail('Expected InvalidPathException for invalid UTF-8 bytes.');
        } catch (InvalidPathException) {
            $this->addToAssertionCount(1);
        }

        // BiDi Trojan Source RLO (\u202E) spoofing rejected in BOTH PathNormalizer and FileSecurityGuard
        $rloAttack = "invoice_\u{202E}gpj.txt";
        try {
            $normalizer->normalize($rloAttack);
            self::fail('Expected InvalidPathException for BiDi RLO override.');
        } catch (InvalidPathException) {
            $this->addToAssertionCount(1);
        }

        try {
            $guard->assertSafeFilename($rloAttack);
            self::fail('Expected UploadValidationException for BiDi RLO override.');
        } catch (UploadValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDirectoryListerPublicUrlEncodingAndSearchAcrossJapaneseArabicChineseKorean(): void
    {
        $files = [
            'フォルダ/東京 レポート #1.txt',
            'مجلد/تقرير مالي 50%.txt',
            '产品目录/新款 100%.txt',
            '한국어/보고서 2026.txt',
            'Отчёты/Финансовый отчёт.txt',
        ];

        foreach ($files as $rel) {
            $full = $this->storageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            mkdir(dirname($full), 0775, true);
            file_put_contents($full, 'ok:' . $rel);
        }

        [$provider, $registry, $normalizer] = $this->buildStack();
        $profile = $provider->getProfile('default');
        $lister = new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage());

        // Check RFC 3986 percent-encoding of Japanese, Arabic, Chinese, Korean public URLs (no raw #, %, spaces, or raw UTF-8)
        $jaListing = $lister->list($profile, 'local', 'フォルダ');
        self::assertCount(1, $jaListing->items);
        $jaUrl = (string) $jaListing->items[0]->publicUrl;
        self::assertStringContainsString('%23', $jaUrl);
        self::assertStringContainsString('%20', $jaUrl);
        self::assertSame(
            '/uploads/' . rawurlencode('フォルダ') . '/' . rawurlencode('東京 レポート #1.txt'),
            $jaUrl,
        );

        // Recursive search works cleanly across Japanese, Arabic, Chinese, Korean, Cyrillic (case-insensitive)
        self::assertCount(1, $lister->searchRecursive($profile, 'local', '', '東京')->items);
        self::assertCount(1, $lister->searchRecursive($profile, 'local', '', 'تقرير')->items);
        self::assertCount(1, $lister->searchRecursive($profile, 'local', '', '新款')->items);
        self::assertCount(1, $lister->searchRecursive($profile, 'local', '', '보고서')->items);
        self::assertCount(1, $lister->searchRecursive($profile, 'local', '', 'финансовый')->items);
    }

    public function testFilenameNormalizerHandlesMultilingualScriptsWithAndWithoutSlugger(): void
    {
        [$provider] = $this->buildStack(slugger: false);
        $rawProfile = $provider->getProfile('default');
        $normalizer = new FilenameNormalizer();

        // slugger: false preserves native Japanese, Arabic, Chinese, Korean while normalizing Japanese full-width space \u3000
        self::assertSame('東京 レポート.pdf', $normalizer->normalizeForUpload("東京\u{3000}レポート.pdf", $rawProfile));
        self::assertSame('تقرير مالي 50%.pdf', $normalizer->normalizeForUpload('تقرير مالي 50%.pdf', $rawProfile));
        self::assertSame('产品目录-2026.webp', $normalizer->normalizeForUpload('产品目录:2026.webp', $rawProfile));
        self::assertSame('보고서 2026.png', $normalizer->normalizeForUpload('보고서 2026.png', $rawProfile));

        // slugger: true produces safe non-empty filenames (ASCII with ext-intl, Unicode-slug fallback without ext-intl)
        [$slugProvider] = $this->buildStack(slugger: true);
        $slugProfile = $slugProvider->getProfile('default');
        foreach (['東京レポート.pdf', 'تقرير مالي.pdf', '产品目录.jpg', '보고서.png'] as $sample) {
            $res = $normalizer->normalizeForUpload($sample, $slugProfile);
            self::assertNotSame('file.' . PathNormalizer::extensionUtf8($sample), $res);
            self::assertStringEndsWith('.' . PathNormalizer::extensionUtf8($sample), $res);
        }
    }

    public function testDownloadAndShareControllersHandleMultilingualFilenamesWithPercentSign(): void
    {
        $names = [
            '50% 割引 東京.txt',
            'تقرير 50% مالي.txt',
            '产品 100% 目录.txt',
            '보고서 50% 최종.txt',
        ];

        foreach ($names as $name) {
            file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . $name, 'content:' . $name);
        }

        [$provider, $registry, $normalizer] = $this->buildStack();
        $profile = $provider->getProfile('default');
        $download = new DownloadController($provider, $registry, $normalizer);

        $signer = new UriSigner('i18n-share-secret-2026');
        $urlGen = new class implements UrlGeneratorInterface {
            private RequestContext $ctx;

            public function __construct()
            {
                $this->ctx = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->ctx = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->ctx;
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'https://example.test/_liveux-file-manager/share?' . http_build_query($parameters, '', '&');
            }
        };
        $shareService = new ShareLinkService($registry, $normalizer, $urlGen, $signer);
        $shareController = new ShareController($provider, $registry, $normalizer, $shareService);

        foreach ($names as $name) {
            $dlRes = $download(Request::create('/_liveux-file-manager/download?profile=default&volume=local&path=' . rawurlencode($name)));
            self::assertSame(Response::HTTP_OK, $dlRes->getStatusCode());
            $cd = (string) $dlRes->headers->get('Content-Disposition');
            self::assertStringContainsString("filename*=utf-8''" . rawurlencode($name), $cd);

            $signed = $shareService->createSignedUrl($profile, $name, 'local', 600, false);
            $shRes = $shareController(Request::create($signed['url']));
            self::assertSame(Response::HTTP_OK, $shRes->getStatusCode());
            self::assertStringContainsString("filename*=utf-8''" . rawurlencode($name), (string) $shRes->headers->get('Content-Disposition'));
        }
    }

    public function testZipArchiveAndSafeExtractPreserveJapaneseArabicChineseKoreanFilenames(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not installed.');
        }

        $zipPath = $this->storageDir . DIRECTORY_SEPARATOR . '東京アーカイブ.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('日本語/メモ.txt', 'hello jp');
        $zip->addFromString('العربية/ملاحظة.txt', 'hello ar');
        $zip->addFromString('中文/说明.txt', 'hello zh');
        $zip->addFromString('한국어/메모.txt', 'hello ko');
        $zip->close();

        [$provider, $registry, $normalizer] = $this->buildStack(allowZipExtract: true);
        $profile = $provider->getProfile('default');
        $zipService = new ZipArchiveService($registry, $normalizer);

        $result = $zipService->extractArchive($profile, '東京アーカイブ.zip', null, 'local');
        self::assertSame(4, $result['extracted']);
        self::assertSame('東京アーカイブ', $result['targetDir']);
        self::assertFileExists($this->storageDir . '/東京アーカイブ/日本語/メモ.txt');
        self::assertFileExists($this->storageDir . '/東京アーカイブ/العربية/ملاحظة.txt');
        self::assertFileExists($this->storageDir . '/東京アーカイブ/中文/说明.txt');
        self::assertFileExists($this->storageDir . '/東京アーカイブ/한국어/메모.txt');
    }

    /**
     * @return array{0: YamlConfigProvider, 1: VolumeRegistry, 2: PathNormalizer}
     */
    private function buildStack(bool $slugger = false, bool $allowZipExtract = false): array
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->storageDir, 'url_prefix' => '/uploads'],
            [
                'local' => [
                    'label' => 'Local',
                    'type' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->storageDir,
                    'url_prefix' => '/uploads',
                    'private' => false,
                    'options' => [],
                ],
            ],
            [
                'default' => [
                    'volumes' => ['local'],
                    'naming' => ['slugger' => $slugger],
                    'share' => ['enabled' => true],
                    'zip' => ['enabled' => true, 'allow_extract' => $allowZipExtract],
                ],
            ],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));

        return [$provider, $registry, $normalizer];
    }
}
