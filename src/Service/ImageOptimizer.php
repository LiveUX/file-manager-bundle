<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use GdImage;
use Imagick;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use Throwable;

/**
 * Server-aware upload image optimizer with 100% graceful degradation.
 * Automatically uses Imagick -> GD -> no-op depending on installed PHP extensions,
 * checks ext-exif before EXIF orientation fixes, and verifies WebP support before converting.
 */
final readonly class ImageOptimizer
{
    private const int MAX_SOURCE_DIMENSION = 8192;
    private const int MAX_SOURCE_PIXELS = 40_000_000;
    private const array OPTIMIZABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array{path: string, filename: string, mime: string, size: int, optimized: bool}
     */
    public function optimizeIfEnabled(string $localTempPath, string $filename, string $mimeType, ProfileConfig $profile): array
    {
        clearstatcache(true, $localTempPath);
        $size = is_file($localTempPath) ? (int) filesize($localTempPath) : 0;
        $fallback = [
            'path' => $localTempPath,
            'filename' => $filename,
            'mime' => $mimeType,
            'size' => $size,
            'optimized' => false,
        ];

        $cfg = $profile->imageOptimization;
        if (($cfg['enabled'] ?? false) !== true || !in_array(strtolower($mimeType), self::OPTIMIZABLE_MIMES, true)) {
            return $fallback;
        }

        if (!is_file($localTempPath) || $size <= 0) {
            return $fallback;
        }

        if ($this->isAnimatedWebp($localTempPath, $mimeType) || $this->isAnimatedPng($localTempPath, $mimeType)) {
            return $fallback;
        }

        $info = @getimagesize($localTempPath);
        if (!is_array($info) || !$this->areSafeDimensions((int) $info[0], (int) $info[1])) {
            return $fallback;
        }

        $options = $this->normalizeOptions($cfg);

        if ($this->isImagickAvailable()) {
            $result = $this->optimizeWithImagick($localTempPath, $filename, $mimeType, $options);
            if ($result !== null) {
                return $result;
            }
        }

        if ($this->isGdAvailable()) {
            $result = $this->optimizeWithGd($localTempPath, $filename, $mimeType, $options);
            if ($result !== null) {
                return $result;
            }
        }

        return $fallback;
    }

    /**
     * @return array{available: bool, driver: string, exif: bool, webp: bool}
     */
    public function getDiagnostics(): array
    {
        $imagick = $this->isImagickAvailable();
        $gd = $this->isGdAvailable();
        $driver = $imagick ? 'imagick' : ($gd ? 'gd' : 'none');

        return [
            'available' => $driver !== 'none',
            'driver' => $driver,
            'exif' => function_exists('exif_read_data'),
            'webp' => $this->supportsImagickWebp() || $this->supportsGdWebp(),
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     *
     * @return array{autoOrient: bool, stripExif: bool, maxWidth: int, maxHeight: int, convertToWebp: bool, quality: int}
     */
    private function normalizeOptions(array $cfg): array
    {
        return [
            'autoOrient' => ($cfg['auto_orient'] ?? true) === true,
            'stripExif' => ($cfg['strip_exif'] ?? true) === true,
            'maxWidth' => max(100, (int) ($cfg['max_width'] ?? 2400)),
            'maxHeight' => max(100, (int) ($cfg['max_height'] ?? 2400)),
            'convertToWebp' => ($cfg['convert_to_webp'] ?? false) === true,
            'quality' => min(100, max(10, (int) ($cfg['quality'] ?? 85))),
        ];
    }

    /**
     * @param array{autoOrient: bool, stripExif: bool, maxWidth: int, maxHeight: int, convertToWebp: bool, quality: int} $opt
     *
     * @return array{path: string, filename: string, mime: string, size: int, optimized: bool}|null
     */
    private function optimizeWithImagick(string $localTempPath, string $filename, string $mimeType, array $opt): ?array
    {
        $info = @getimagesize($localTempPath);
        if (!is_array($info)) {
            return null;
        }
        $coder = $this->resolveImagickCoder((int) $info[2]);
        if ($coder === null || !$this->areSafeDimensions((int) $info[0], (int) $info[1])) {
            return null;
        }

        $imagick = null;
        try {
            $imagick = new Imagick();
            $this->applyImagickResourceLimits($imagick);
            $imagick->readImage($coder . ':' . $localTempPath . '[0]');

            $w = $imagick->getImageWidth();
            $h = $imagick->getImageHeight();
            if (!$this->areSafeDimensions($w, $h)) {
                $imagick->clear();

                return null;
            }

            if ($opt['autoOrient']) {
                $imagick->autoOrient();
                $w = $imagick->getImageWidth();
                $h = $imagick->getImageHeight();
            }

            if ($w > $opt['maxWidth'] || $h > $opt['maxHeight']) {
                if ($opt['stripExif']) {
                    $imagick->thumbnailImage($opt['maxWidth'], $opt['maxHeight'], true);
                } else {
                    $imagick->scaleImage($opt['maxWidth'], $opt['maxHeight'], true);
                }
            }

            if ($opt['stripExif']) {
                $imagick->stripImage();
            }

            $targetMime = $mimeType;
            $targetFilename = $filename;

            if ($opt['convertToWebp'] && $this->supportsImagickWebp()) {
                $imagick->setImageFormat('webp');
                $targetMime = 'image/webp';
                $targetFilename = $this->replaceExtension($filename, 'webp');
            } elseif ($mimeType === 'image/jpeg') {
                $imagick->setImageFormat('jpeg');
            } elseif ($mimeType === 'image/png') {
                $imagick->setImageFormat('png');
            }

            $imagick->setImageCompressionQuality($opt['quality']);
            $blob = $imagick->getImageBlob();
            $imagick->clear();

            if ($blob === '') {
                return null;
            }

            if (!$this->writeBlobAtomically($localTempPath, $blob)) {
                return null;
            }

            return [
                'path' => $localTempPath,
                'filename' => $targetFilename,
                'mime' => $targetMime,
                'size' => strlen($blob),
                'optimized' => true,
            ];
        } catch (Throwable) {
            return null;
        } finally {
            if ($imagick instanceof Imagick) {
                $imagick->clear();
            }
        }
    }

    private function resolveImagickCoder(int $imageType): ?string
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
    }

    private function applyImagickResourceLimits(Imagick $imagick): void
    {
        if (defined('Imagick::RESOURCETYPE_MEMORY')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 134_217_728);
        }
        if (defined('Imagick::RESOURCETYPE_MAP')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, 268_435_456);
        }
        if (defined('Imagick::RESOURCETYPE_AREA')) {
            // RESOURCETYPE_AREA is measured in bytes (width * height * bytes_per_pixel), not raw pixel count.
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_AREA, 134_217_728);
        }
        if (defined('Imagick::RESOURCETYPE_WIDTH')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_WIDTH, self::MAX_SOURCE_DIMENSION);
        }
        if (defined('Imagick::RESOURCETYPE_HEIGHT')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, self::MAX_SOURCE_DIMENSION);
        }
        if (defined('Imagick::RESOURCETYPE_DISK')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_DISK, 268_435_456);
        }
        if (defined('Imagick::RESOURCETYPE_TIME')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_TIME, 15);
        }
    }

    /**
     * @param array{autoOrient: bool, stripExif: bool, maxWidth: int, maxHeight: int, convertToWebp: bool, quality: int} $opt
     *
     * @return array{path: string, filename: string, mime: string, size: int, optimized: bool}|null
     */
    private function optimizeWithGd(string $localTempPath, string $filename, string $mimeType, array $opt): ?array
    {
        $info = @getimagesize($localTempPath);
        if (!is_array($info) || !$this->hasEnoughMemoryForGd((int) $info[0], (int) $info[1])) {
            return null;
        }

        $binary = file_get_contents($localTempPath);
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $src = @imagecreatefromstring($binary);
        if (!$src instanceof GdImage) {
            return null;
        }

        try {
            if ($opt['autoOrient'] && $mimeType === 'image/jpeg') {
                $src = $this->autoOrientGd($src, $localTempPath);
            }

            $src = $this->downscaleGdIfNeeded($src, $opt['maxWidth'], $opt['maxHeight']);

            return $this->encodeGdToFile($src, $localTempPath, $filename, $mimeType, $opt);
        } finally {
            imagedestroy($src);
        }
    }

    private function autoOrientGd(GdImage $image, string $localTempPath): GdImage
    {
        if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return $image;
        }

        $exif = @exif_read_data($localTempPath);
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

    private function downscaleGdIfNeeded(GdImage $src, int $maxWidth, int $maxHeight): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= $maxWidth && $h <= $maxHeight) {
            return $src;
        }

        $ratio = min($maxWidth / max(1, $w), $maxHeight / max(1, $h));
        $newW = max(1, (int) round($w * $ratio));
        $newH = max(1, (int) round($h * $ratio));

        $dst = imagecreatetruecolor($newW, $newH);
        if (!$dst instanceof GdImage) {
            return $src;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);

        return $dst;
    }

    /**
     * @param array{autoOrient: bool, stripExif: bool, maxWidth: int, maxHeight: int, convertToWebp: bool, quality: int} $opt
     *
     * @return array{path: string, filename: string, mime: string, size: int, optimized: bool}|null
     */
    private function encodeGdToFile(GdImage $img, string $localTempPath, string $filename, string $mimeType, array $opt): ?array
    {
        $targetMime = $mimeType;
        $targetFilename = $filename;

        if ($opt['convertToWebp'] && $this->supportsGdWebp()) {
            $targetMime = 'image/webp';
            $targetFilename = $this->replaceExtension($filename, 'webp');
        }

        $initialLevel = ob_get_level();
        ob_start();
        $ok = false;
        $data = false;

        try {
            if ($targetMime === 'image/webp' && function_exists('imagewebp')) {
                imagepalettetotruecolor($img);
                imagealphablending($img, true);
                imagesavealpha($img, true);
                $ok = imagewebp($img, null, $opt['quality']);
            } elseif ($targetMime === 'image/png' && function_exists('imagepng')) {
                imagesavealpha($img, true);
                // PNG is lossless; zlib level 7 gives strong size reduction without CPU spikes (0 is uncompressed!).
                $ok = imagepng($img, null, 7);
            } elseif (function_exists('imagejpeg')) {
                if ($targetMime !== 'image/jpeg') {
                    $targetFilename = $this->replaceExtension($filename, 'jpg');
                }
                $targetMime = 'image/jpeg';
                $flattened = $this->flattenOnWhite($img);
                $ok = imagejpeg($flattened, null, $opt['quality']);
                if ($flattened !== $img) {
                    imagedestroy($flattened);
                }
            }

            $data = ob_get_clean();
        } finally {
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
        }

        if (!$ok || !is_string($data) || $data === '') {
            return null;
        }

        if (!$this->writeBlobAtomically($localTempPath, $data)) {
            return null;
        }

        return [
            'path' => $localTempPath,
            'filename' => $targetFilename,
            'mime' => $targetMime,
            'size' => strlen($data),
            'optimized' => true,
        ];
    }

    private function writeBlobAtomically(string $localTempPath, string $blob): bool
    {
        $tmpTarget = $localTempPath . '.opt.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmpTarget, $blob, LOCK_EX);
        if ($written === false || $written !== strlen($blob)) {
            @unlink($tmpTarget);

            return false;
        }
        if (!@rename($tmpTarget, $localTempPath)) {
            @unlink($tmpTarget);

            return false;
        }
        clearstatcache(true, $localTempPath);

        return true;
    }

    private function flattenOnWhite(GdImage $img): GdImage
    {
        $w = max(1, imagesx($img));
        $h = max(1, imagesy($img));
        $bg = imagecreatetruecolor($w, $h);
        if (!$bg instanceof GdImage) {
            return $img;
        }

        $white = imagecolorallocate($bg, 255, 255, 255);
        if ($white !== false) {
            imagefill($bg, 0, 0, $white);
        }
        imagealphablending($bg, true);
        imagecopy($bg, $img, 0, 0, 0, 0, $w, $h);

        return $bg;
    }

    private function hasEnoughMemoryForGd(int $width, int $height): bool
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return true;
        }

        $unit = strtolower(substr($raw, -1));
        $num = (int) $raw;
        $limit = match ($unit) {
            'g' => $num * 1073741824,
            'm' => $num * 1048576,
            'k' => $num * 1024,
            default => $num,
        };
        if ($limit <= 0) {
            return true;
        }

        $estimated = ($width * $height * 10) + 16_777_216;

        return ($limit - memory_get_usage(true)) >= $estimated;
    }

    private function areSafeDimensions(int $width, int $height): bool
    {
        return $width > 0
            && $height > 0
            && $width <= self::MAX_SOURCE_DIMENSION
            && $height <= self::MAX_SOURCE_DIMENSION
            && ($width * $height) <= self::MAX_SOURCE_PIXELS;
    }

    private function replaceExtension(string $filename, string $newExt): string
    {
        $base = PathNormalizer::stemUtf8($filename);
        $maxStemBytes = max(1, 254 - strlen($newExt));
        $truncated = \LiveUX\FileManagerBundle\Util\UnicodeHelper::truncateStemBytes($base, $maxStemBytes);

        return ($truncated !== '' ? $truncated : 'image') . '.' . $newExt;
    }

    private function isImagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    private function isGdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    private function supportsImagickWebp(): bool
    {
        if (!$this->isImagickAvailable()) {
            return false;
        }

        try {
            return Imagick::queryFormats('WEBP') !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function supportsGdWebp(): bool
    {
        return $this->isGdAvailable() && function_exists('imagewebp');
    }

    private function isAnimatedWebp(string $localTempPath, string $mimeType): bool
    {
        if (strtolower($mimeType) !== 'image/webp') {
            return false;
        }

        $handle = @fopen($localTempPath, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 128);
        fclose($handle);
        if (!is_string($header) || strlen($header) < 21) {
            return false;
        }

        if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            return false;
        }

        if (substr($header, 12, 4) !== 'VP8X') {
            return false;
        }

        return (ord($header[20]) & 0x02) !== 0 || str_contains(substr($header, 24), 'ANIM');
    }

    public function isAnimatedPng(string $localTempPath, string $mimeType = 'image/png'): bool
    {
        if (strtolower($mimeType) !== 'image/png') {
            return false;
        }

        $handle = @fopen($localTempPath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $sig = fread($handle, 8);
            if ($sig !== "\x89PNG\r\n\x1A\n") {
                return false;
            }

            for ($chunks = 0; $chunks < 64 && !feof($handle); ++$chunks) {
                $chunkHeader = fread($handle, 8);
                if (!is_string($chunkHeader) || strlen($chunkHeader) < 8) {
                    return false;
                }
                /** @var array{len: int}|false $unpacked */
                $unpacked = unpack('Nlen', substr($chunkHeader, 0, 4));
                if (!is_array($unpacked)) {
                    return false;
                }
                $length = $unpacked['len'];
                $type = substr($chunkHeader, 4, 4);
                if ($type === 'acTL') {
                    return true;
                }
                if ($type === 'IDAT' || $type === 'IEND') {
                    return false;
                }
                if ($length < 0 || $length > 10_000_000) {
                    return false;
                }
                if (fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}
