<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class FileUploadedEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        string $canonicalPath,
        public readonly int $size,
        public readonly ?string $mimeType = null,
        public readonly bool $fromArchiveExtraction = false,
    ) {
        parent::__construct($profile, $volume, $path, $canonicalPath);
    }
}
