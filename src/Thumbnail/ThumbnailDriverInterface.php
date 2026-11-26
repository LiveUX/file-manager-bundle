<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Thumbnail;

interface ThumbnailDriverInterface
{
    public function getName(): string;

    public function isAvailable(): bool;

    public function supportsWebp(): bool;

    public function generate(
        string $sourceBinary,
        string $targetFilePath,
        int $width,
        int $height,
        string $format,
        int $quality,
        string $fit,
    ): bool;
}
