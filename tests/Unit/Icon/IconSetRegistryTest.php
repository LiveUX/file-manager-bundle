<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Icon;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class IconSetRegistryTest extends TestCase
{
    private string $bundleIconsDir;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->bundleIconsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'icons';
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_icons_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/ui', 0775, true);
        mkdir($this->tempDir . '/badges', 0775, true);
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

    public function testAllBuiltinIconSetsExportCompleteFileUiAndBadgeIcons(): void
    {
        $registry = new IconSetRegistry($this->bundleIconsDir);

        foreach ($registry->getAvailableSets() as $set) {
            $payload = $registry->getIconSetPayload($set, false);
            self::assertSame($set, $payload['name']);
            self::assertArrayHasKey('folder', $payload['icons']);
            self::assertNotEmpty($payload['icons']['folder']);

            foreach ($registry->getUiIconKeys() as $uiKey) {
                self::assertArrayHasKey($uiKey, $payload['ui'], sprintf('Missing UI icon "%s" in set "%s".', $uiKey, $set));
                self::assertStringContainsString('<svg', $payload['ui'][$uiKey]);
            }

            foreach ($registry->getBadgeIconKeys() as $badgeKey) {
                self::assertArrayHasKey($badgeKey, $payload['badges'], sprintf('Missing badge icon "%s" in set "%s".', $badgeKey, $set));
                self::assertStringContainsString('<svg', $payload['badges'][$badgeKey]);
            }
        }
    }

    public function testCustomDirectoryOverridesUiAndAddsSanitizedExtraBadges(): void
    {
        file_put_contents($this->tempDir . '/meta.json', json_encode(['defaultFolderColor' => '#ea580c']));
        // Custom UI icon containing hostile script/event handler -> must be sanitized!
        file_put_contents(
            $this->tempDir . '/ui/upload.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><path d="M5 12h14"/></svg>',
        );
        // Brand-new custom folder badge icon
        file_put_contents(
            $this->tempDir . '/badges/invoice.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>',
        );

        $registry = new IconSetRegistry($this->bundleIconsDir);
        $payload = $registry->getIconSetPayload($this->tempDir, true);

        self::assertSame('#ea580c', $payload['defaultFolderColor']);
        self::assertStringContainsString('M5 12h14', $payload['ui']['upload']);
        self::assertStringNotContainsString('<script', $payload['ui']['upload']);
        self::assertStringNotContainsString('onload', $payload['ui']['upload']);
        // Unoverridden UI icon falls back cleanly
        self::assertStringContainsString('<svg', $payload['ui']['trash']);
        // Extra custom folder badge auto-discovered
        self::assertArrayHasKey('invoice', $payload['badges']);
        self::assertStringContainsString('M4 4h16v16H4z', $payload['badges']['invoice']);

        $rendered = $registry->renderInlineIcon('upload', $this->tempDir, 16, 'ui', 'my-icon-cls', true);
        self::assertStringContainsString('width="16"', $rendered);
        self::assertStringContainsString('height="16"', $rendered);
        self::assertStringContainsString('class="my-icon-cls"', $rendered);
        self::assertStringContainsString('aria-hidden="true"', $rendered);

        // Extra custom UI volume icon auto-discovered & sanitized
        file_put_contents(
            $this->tempDir . '/ui/my_nas.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2 6h20v12H2z"/></svg>',
        );
        $freshRegistry = new IconSetRegistry($this->bundleIconsDir);
        $customVolIcon = $freshRegistry->renderInlineIcon('my_nas', $this->tempDir, 15, 'ui', 'shrink', true, 'disk');
        self::assertStringContainsString('M2 6h20v12H2z', $customVolIcon);

        // Missing icon key cleanly falls back to fallbackKey
        $fallbackIcon = $freshRegistry->renderInlineIcon('does_not_exist', 'classic', 15, 'ui', '', false, 'cloud');
        self::assertStringContainsString('<svg', $fallbackIcon);
    }

    public function testVolumeConfigResolvesExplicitAndAutoDetectedProviderIcons(): void
    {
        self::assertSame('disk', (new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', '/tmp'))->resolveIconKey());
        self::assertSame('aws_s3', (new VolumeConfig('s3', 'S3', 'network', 'AwsS3', driverOptions: ['bucket' => 'b']))->resolveIconKey());
        self::assertSame('cloudflare_r2', (new VolumeConfig('r2', 'R2', 'network', 'AwsS3', driverOptions: ['endpoint' => 'https://abc.r2.cloudflarestorage.com']))->resolveIconKey());
        self::assertSame('supabase', (new VolumeConfig('sb', 'SB', 'network', 'AwsS3', driverOptions: ['endpoint' => 'https://ref.storage.supabase.co/storage/v1/s3']))->resolveIconKey());
        self::assertSame('backblaze_b2', (new VolumeConfig('b2', 'B2', 'network', 'AwsS3', driverOptions: ['endpoint' => 'https://s3.eu-central-003.backblazeb2.com']))->resolveIconKey());
        self::assertSame('minio', (new VolumeConfig('m', 'MinIO', 'network', 'AwsS3', driverOptions: ['endpoint' => 'http://localhost:9000']))->resolveIconKey());
        self::assertSame('gcs', (new VolumeConfig('g', 'GCS', 'network', 'AwsS3', driverOptions: ['endpoint' => 'https://storage.googleapis.com']))->resolveIconKey());
        self::assertSame('sftp', (new VolumeConfig('sf', 'SFTP', 'network', 'FlysystemService', driverOptions: ['service' => 'app.sftp.storage']))->resolveIconKey());
        self::assertSame('my_custom', (new VolumeConfig('x', 'X', 'network', 'AwsS3', icon: 'my_custom'))->resolveIconKey());
    }
}
