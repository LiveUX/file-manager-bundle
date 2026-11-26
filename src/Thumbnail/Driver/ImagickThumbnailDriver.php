<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Thumbnail\Driver;

use Imagick;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailDriverInterface;
use Throwable;

final readonly class ImagickThumbnailDriver implements ThumbnailDriverInterface
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
        return 'imagick';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    public function supportsWebp(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return Imagick::queryFormats('WEBP') !== [];
        } catch (Throwable) {
            return false;
        }
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

        $safeW = max(1, $width);
        $safeH = max(1, $height);
        $sourceInfo = @getimagesizefromstring($sourceBinary);
        $coder = is_array($sourceInfo) ? $this->resolveCoder((int) $sourceInfo[2]) : null;
        if ($coder === null) {
            return false;
        }
        $tmpInput = tempnam(sys_get_temp_dir(), 'liveux_imk_in_');
        if ($tmpInput === false) {
            return false;
        }

        $tmpFile = null;
        $imagick = null;

        try {
            if (@file_put_contents($tmpInput, $sourceBinary) === false) {
                return false;
            }

            $imagick = new Imagick();
            $this->applyResourceLimits($imagick);
            // Force raster coder and decode ONLY frame [0] so delegate/MVG/PDF payloads and multi-frame bombs cannot bypass raster guard.
            $imagick->readImage($coder . ':' . $tmpInput . '[0]');
            if (!$this->areDimensionsWithinBounds($imagick->getImageWidth(), $imagick->getImageHeight())) {
                return false;
            }

            $imagick->autoOrient();
            $imagick->stripImage();

            if ($fit === 'cover') {
                $imagick->cropThumbnailImage($safeW, $safeH);
            } elseif ($imagick->getImageWidth() > $safeW || $imagick->getImageHeight() > $safeH) {
                $imagick->thumbnailImage($safeW, $safeH, true);
            }

            $effectiveFormat = ($format === 'webp' && !$this->supportsWebp()) ? 'jpeg' : $format;
            if (in_array(strtolower($effectiveFormat), ['jpeg', 'jpg'], true)) {
                $imagick->setImageBackgroundColor('white');
                $flattened = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $imagick->clear();
                $imagick = $flattened;
            }
            $imagick->setImageFormat($effectiveFormat);
            $imagick->setImageCompressionQuality($quality);

            $dir = \dirname($targetFilePath);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }

            $blob = $imagick->getImageBlob();
            if ($blob === '') {
                return false;
            }

            $tmpFile = $targetFilePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $written = @file_put_contents($tmpFile, $blob, LOCK_EX);
            if ($written === false || $written !== strlen($blob)) {
                @unlink($tmpFile);

                return false;
            }

            if (!@rename($tmpFile, $targetFilePath)) {
                @unlink($tmpFile);

                return is_file($targetFilePath);
            }

            $tmpFile = null;

            return true;
        } catch (Throwable) {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }

            return false;
        } finally {
            if ($imagick instanceof Imagick) {
                $imagick->clear();
            }
            @unlink($tmpInput);
        }
    }

    private function resolveCoder(int $imageType): ?string
    {
        if (defined('IMAGETYPE_AVIF') && $imageType === \IMAGETYPE_AVIF) {
            return 'avif';
        }

        return match ($imageType) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_BMP => 'bmp',
            default => null,
        };
    }

    private function applyResourceLimits(Imagick $imagick): void
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
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_WIDTH, self::MAX_DIMENSION);
        }
        if (defined('Imagick::RESOURCETYPE_HEIGHT')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, self::MAX_DIMENSION);
        }
        if (defined('Imagick::RESOURCETYPE_DISK')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_DISK, 268_435_456);
        }
        if (defined('Imagick::RESOURCETYPE_TIME')) {
            $imagick->setResourceLimit(Imagick::RESOURCETYPE_TIME, 15);
        }
    }

    private function areDimensionsWithinBounds(int $w, int $h): bool
    {
        return $w > 0 && $h > 0 && $w <= self::MAX_DIMENSION && $h <= self::MAX_DIMENSION && ($w * $h) <= self::MAX_PIXELS;
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

        return $w > 0 && $h > 0 && $w <= self::MAX_DIMENSION && $h <= self::MAX_DIMENSION && ($w * $h) <= self::MAX_PIXELS;
    }
}
