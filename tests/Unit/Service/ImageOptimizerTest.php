<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use PHPUnit\Framework\TestCase;

final class ImageOptimizerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_imgopt_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testNoOpWhenOptimizationDisabled(): void
    {
        $optimizer = new ImageOptimizer();
        $file = $this->createPngFile('sample.png', 200, 100);
        $profile = $this->buildProfile(['enabled' => false]);

        $result = $optimizer->optimizeIfEnabled($file, 'sample.png', 'image/png', $profile);

        self::assertFalse($result['optimized']);
        self::assertSame('sample.png', $result['filename']);
        self::assertSame('image/png', $result['mime']);
    }

    public function testNoOpForNonImageAndCorruptPayloads(): void
    {
        $optimizer = new ImageOptimizer();
        $profile = $this->buildProfile(['enabled' => true, 'max_width' => 100, 'max_height' => 100]);

        $pdf = $this->tempDir . DIRECTORY_SEPARATOR . 'doc.pdf';
        file_put_contents($pdf, '%PDF-1.4 sample');
        $pdfRes = $optimizer->optimizeIfEnabled($pdf, 'doc.pdf', 'application/pdf', $profile);
        self::assertFalse($pdfRes['optimized']);

        $corrupt = $this->tempDir . DIRECTORY_SEPARATOR . 'bad.jpg';
        file_put_contents($corrupt, 'not-a-real-jpeg-file');
        $badRes = $optimizer->optimizeIfEnabled($corrupt, 'bad.jpg', 'image/jpeg', $profile);
        self::assertFalse($badRes['optimized']);
    }

    public function testDownscalesLargeImageGracefullyWhenDriverAvailable(): void
    {
        $optimizer = new ImageOptimizer();
        $diag = $optimizer->getDiagnostics();
        if (!$diag['available']) {
            self::markTestSkipped('Neither Imagick nor GD is available on this PHP runtime.');
        }

        $file = $this->createPngFile('large.png', 400, 200);
        $profile = $this->buildProfile([
            'enabled' => true,
            'max_width' => 120,
            'max_height' => 120,
            'convert_to_webp' => false,
            'quality' => 85,
        ]);

        $result = $optimizer->optimizeIfEnabled($file, 'large.png', 'image/png', $profile);

        self::assertTrue($result['optimized']);
        self::assertSame('large.png', $result['filename']);
        $info = @getimagesize($result['path']);
        self::assertIsArray($info);
        self::assertSame(120, $info[0]);
        self::assertSame(60, $info[1]);
    }

    public function testConvertsToWebpWhenSupportedByServer(): void
    {
        $optimizer = new ImageOptimizer();
        $diag = $optimizer->getDiagnostics();
        if (!$diag['available'] || !$diag['webp']) {
            self::markTestSkipped('WebP conversion is not supported on this PHP runtime.');
        }

        $file = $this->createPngFile('banner.png', 160, 80);
        $profile = $this->buildProfile([
            'enabled' => true,
            'max_width' => 2400,
            'max_height' => 2400,
            'convert_to_webp' => true,
            'quality' => 80,
        ]);

        $result = $optimizer->optimizeIfEnabled($file, 'banner.png', 'image/png', $profile);

        self::assertTrue($result['optimized']);
        self::assertSame('banner.webp', $result['filename']);
        self::assertSame('image/webp', $result['mime']);
        self::assertGreaterThan(0, $result['size']);
    }

    /**
     * @param array<string, mixed> $imageOpt
     */
    private function buildProfile(array $imageOpt): ProfileConfig
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'image_optimization' => $imageOpt]],
        );

        return $provider->getProfile('default');
    }

    private function createPngFile(string $name, int $width, int $height): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            $img = imagecreatetruecolor($width, $height);
            self::assertNotFalse($img);
            $color = imagecolorallocate($img, 37, 99, 235);
            imagefilledrectangle($img, 0, 0, $width, $height, (int) $color);
            imagepng($img, $path);
            imagedestroy($img);

            return $path;
        }

        // Minimal 1x1 PNG fallback when GD is absent
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);
        file_put_contents($path, $png);

        return $path;
    }
}
