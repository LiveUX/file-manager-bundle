<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Thumbnail\Driver;

use GdImage;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailDriverInterface;

final readonly class GdThumbnailDriver implements ThumbnailDriverInterface
{
    private const int MAX_DIMENSION = 8192;
    private const int MAX_PIXELS = 40_000_000;
    private const array ALLOWED_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_GIF,
        IMAGETYPE_WEBP,
        IMAGETYPE_BMP,
    ];

    public function getName(): string
    {
        return 'gd';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public function supportsWebp(): bool
    {
        return function_exists('imagewebp');
    }

    public function generate(
        string $sourceBinary,
        string $targetFilePath,
        int $width,
        int $height,
        string $format,
        int $quality,
        string $fit,
    ): bool {
        if (!$this->isAvailable() || !$this->isSafeRasterImage($sourceBinary)) {
            return false;
        }

        $source = @imagecreatefromstring($sourceBinary);
        if (!$source instanceof GdImage) {
            return false;
        }

        $source = $this->autoOrientFromBinary($source, $sourceBinary);
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $effectiveFormat = ($format === 'webp' && !$this->supportsWebp()) ? 'jpeg' : $format;
        $flattenWhite = in_array(strtolower($effectiveFormat), ['jpeg', 'jpg'], true);
        $canvas = $this->resizeImage($source, $srcW, $srcH, max(1, $width), max(1, $height), $fit, $flattenWhite);
        imagedestroy($source);

        if ($canvas === null) {
            return false;
        }

        $saved = $this->saveCanvas($canvas, $targetFilePath, $effectiveFormat, $quality);
        imagedestroy($canvas);

        return $saved;
    }

    private function resizeImage(
        GdImage $source,
        int $srcW,
        int $srcH,
        int $targetW,
        int $targetH,
        string $fit,
        bool $flattenWhite = false,
    ): ?GdImage {
        $safeTargetW = max(1, $targetW);
        $safeTargetH = max(1, $targetH);
        $safeSrcW = max(1, $srcW);
        $safeSrcH = max(1, $srcH);

        if ($fit === 'cover') {
            $scale = max($safeTargetW / $safeSrcW, $safeTargetH / $safeSrcH);
            $cropW = max(1, (int) round($safeTargetW / $scale));
            $cropH = max(1, (int) round($safeTargetH / $scale));
            $srcX = (int) max(0, floor(($safeSrcW - $cropW) / 2));
            $srcY = (int) max(0, floor(($safeSrcH - $cropH) / 2));
            $dstW = $safeTargetW;
            $dstH = $safeTargetH;
        } else {
            $scale = min($safeTargetW / $safeSrcW, $safeTargetH / $safeSrcH, 1.0);
            $dstW = max(1, (int) round($safeSrcW * $scale));
            $dstH = max(1, (int) round($safeSrcH * $scale));
            $srcX = 0;
            $srcY = 0;
            $cropW = $safeSrcW;
            $cropH = $safeSrcH;
        }

        $canvas = imagecreatetruecolor($dstW, $dstH);
        if (!$canvas instanceof GdImage) {
            return null;
        }

        if ($flattenWhite) {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            if ($white !== false) {
                imagefill($canvas, 0, 0, $white);
            }
            imagealphablending($canvas, true);
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }

        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $dstW, $dstH, $cropW, $cropH);

        return $canvas;
    }

    private function saveCanvas(GdImage $canvas, string $targetFilePath, string $format, int $quality): bool
    {
        $dir = \dirname($targetFilePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $effectiveFormat = ($format === 'webp' && !$this->supportsWebp()) ? 'jpeg' : $format;
        $tmpFile = $targetFilePath . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $written = match ($effectiveFormat) {
            'webp' => imagewebp($canvas, $tmpFile, $quality),
            'png' => imagepng($canvas, $tmpFile, 6),
            default => imagejpeg($canvas, $tmpFile, $quality),
        };

        if (!$written) {
            @unlink($tmpFile);

            return false;
        }

        if (!@rename($tmpFile, $targetFilePath)) {
            @unlink($tmpFile);

            return is_file($targetFilePath);
        }

        return true;
    }

    private function isSafeRasterImage(string $sourceBinary): bool
    {
        if ($sourceBinary === '') {
            return false;
        }

        $info = @getimagesizefromstring($sourceBinary);
        if (!is_array($info)) {
            return false;
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        $type = (int) $info[2];

        $isAllowed = in_array($type, self::ALLOWED_TYPES, true)
            || (defined('IMAGETYPE_AVIF') && $type === \IMAGETYPE_AVIF);
        if (!$isAllowed) {
            return false;
        }

        if ($w <= 0 || $h <= 0 || $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION || ($w * $h) > self::MAX_PIXELS) {
            return false;
        }

        return $this->hasEnoughMemoryForPixels($w, $h);
    }

    private function hasEnoughMemoryForPixels(int $width, int $height): bool
    {
        $limit = $this->parseIniBytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }

        // RGBA GD bitmap (~5 bytes/pixel overhead) x2 safety margin for rotation/resample + 16 MB headroom
        $estimatedBytes = ($width * $height * 10) + 16_777_216;
        $available = $limit - memory_get_usage(true);

        return $available >= $estimatedBytes;
    }

    private function parseIniBytes(string $raw): int
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed === '-1') {
            return -1;
        }

        $unit = strtolower(substr($trimmed, -1));
        $num = (int) $trimmed;

        return match ($unit) {
            'g' => $num * 1073741824,
            'm' => $num * 1048576,
            'k' => $num * 1024,
            default => $num,
        };
    }

    private function autoOrientFromBinary(GdImage $image, string $sourceBinary): GdImage
    {
        if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return $image;
        }

        // Only JPEG headers start with FF D8
        if (!str_starts_with($sourceBinary, "\xFF\xD8")) {
            return $image;
        }

        $stream = @fopen('php://temp', 'r+b');
        if ($stream === false) {
            return $image;
        }

        try {
            fwrite($stream, substr($sourceBinary, 0, 65536));
            rewind($stream);
            $exif = @exif_read_data($stream);
        } finally {
            fclose($stream);
        }

        if (!is_array($exif)) {
            return $image;
        }

        return $this->applyExifOrientation($image, (int) ($exif['Orientation'] ?? 1));
    }

    private function applyExifOrientation(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true) && function_exists('imageflip')) {
            @imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4 && function_exists('imageflip')) {
            @imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);
        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
