<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use ZipArchive;

final class SafeUnzipSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('PHP ext-zip is not installed.');
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_unzip_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testExtractIsDisabledByDefault(): void
    {
        $this->createZip('bundle.zip', ['hello.txt' => 'world']);
        [$svc, $profile] = $this->createZipService(['allow_extract' => false]);

        self::assertFalse($svc->isExtractAvailableForProfile($profile));

        $this->expectException(FileManagerException::class);
        $svc->extractArchive($profile, 'bundle.zip', null, 'local');
    }

    public function testBlocksZipSlipPathTraversalAndAbsolutePaths(): void
    {
        $this->createZip('slip.zip', ['../../pwned.txt' => 'escape']);
        [$svc, $profile] = $this->createZipService(['allow_extract' => true]);

        try {
            $svc->extractArchive($profile, 'slip.zip', null, 'local');
            self::fail('Zip-Slip traversal should be blocked.');
        } catch (FileManagerException) {
            self::assertFileDoesNotExist(\dirname($this->tempDir) . DIRECTORY_SEPARATOR . 'pwned.txt');
        }
    }

    public function testBlocksCve202681891ExecutableAndDoubleExtensionsInsideZip(): void
    {
        $dangerousEntries = [
            'shell.php' => '<?php system("id"); ?>',
            'bypass.php5' => '<?php echo 1; ?>',
            'nested/backdoor.phtml' => '<?php phpinfo(); ?>',
            'fake.php.jpg' => '<?php echo 2; ?>',
            '.htaccess' => 'AddType application/x-httpd-php .jpg',
        ];

        [$svc, $profile] = $this->createZipService(['allow_extract' => true]);

        foreach ($dangerousEntries as $entryName => $content) {
            $zipName = 'evil_' . md5($entryName) . '.zip';
            $this->createZip($zipName, [$entryName => $content]);

            try {
                $svc->extractArchive($profile, $zipName, 'out', 'local');
                self::fail(sprintf('SafeUnzip failed to block dangerous entry "%s".', $entryName));
            } catch (FileManagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBlocksUnixSymlinkEntriesInsideZip(): void
    {
        $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'symlink.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('link.txt', '/etc/passwd');
        // Mark as Unix symlink (0120777 << 16)
        $zip->setExternalAttributesName('link.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        [$svc, $profile] = $this->createZipService(['allow_extract' => true]);

        try {
            $svc->extractArchive($profile, 'symlink.zip', 'out', 'local');
            self::fail('Unix symlink inside ZIP should be rejected.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.zip_symlink_blocked', $e->getTranslationKey());
        }
    }

    public function testBlocksZipBombCompressionRatioAndMaxEntries(): void
    {
        // Highly compressible 200 KB of zeroes -> compression ratio > 200:1!
        $this->createZip('bomb.zip', ['zeros.txt' => str_repeat("\x41", 250_000)]);
        [$svc, $profile] = $this->createZipService([
            'allow_extract' => true,
            'max_compression_ratio' => 20,
            'max_extract_size' => '10M',
        ]);

        try {
            $svc->extractArchive($profile, 'bomb.zip', 'out', 'local');
            self::fail('High compression ratio ZIP bomb should be blocked.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.zip_bomb_detected', $e->getTranslationKey());
        }

        // Entry count overflow
        $this->createZip('many.zip', ['a.txt' => '1', 'b.txt' => '2', 'c.txt' => '3']);
        [$svc2, $profile2] = $this->createZipService(['allow_extract' => true, 'max_extract_files' => 2]);
        try {
            $svc2->extractArchive($profile2, 'many.zip', 'out', 'local');
            self::fail('Archive exceeding max_extract_files should be blocked.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.zip_extract_too_many', $e->getTranslationKey());
        }
    }

    public function testEnforcesExtractZipVoterAndExtractsSafeArchiveWithSvgSanitization(): void
    {
        $this->createZip('good.zip', [
            'docs/readme.txt' => 'Hello Safe Unzip',
            'icons/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>evil()</script><circle r="5"/></svg>',
            '__MACOSX/._junk' => 'ignored',
        ]);

        // Voter denying EXTRACT_ZIP must throw AccessDeniedException
        $denyAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyAuth->method('isGranted')->willReturn(false);
        [$svcDenied, $profileVoter] = $this->createZipService(['allow_extract' => true], $denyAuth, true);
        try {
            $svcDenied->extractArchive($profileVoter, 'good.zip', null, 'local');
            self::fail('Denied voter must block extraction.');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        // Allowed extraction succeeds and sanitizes SVG
        [$svc, $profile] = $this->createZipService(['allow_extract' => true]);
        $result = $svc->extractArchive($profile, 'good.zip', null, 'local');

        self::assertSame(2, $result['extracted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('good', $result['targetDir']);
        self::assertSame('Hello Safe Unzip', file_get_contents($this->tempDir . '/good/docs/readme.txt'));

        $svgContent = (string) file_get_contents($this->tempDir . '/good/icons/logo.svg');
        self::assertStringContainsString('<circle', $svgContent);
        self::assertStringNotContainsString('<script>', $svgContent);
        self::assertStringNotContainsString('onload', $svgContent);
    }

    /**
     * @param array<string, string> $files
     */
    private function createZip(string $name, array $files): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->tempDir . DIRECTORY_SEPARATOR . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();
    }

    /**
     * @param array<string, mixed> $zipCfg
     *
     * @return array{0: ZipArchiveService, 1: ProfileConfig}
     */
    private function createZipService(
        array $zipCfg,
        ?AuthorizationCheckerInterface $authChecker = null,
        bool $voterEnabled = false,
    ): array {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => [
                'volumes' => ['local'],
                'zip' => $zipCfg,
                'security' => ['voter_enabled' => $voterEnabled],
            ]],
        );
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $svc = new ZipArchiveService(
            volumeRegistry: $registry,
            pathNormalizer: new PathNormalizer(),
            authChecker: $authChecker,
        );

        return [$svc, $provider->getProfile('default')];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
