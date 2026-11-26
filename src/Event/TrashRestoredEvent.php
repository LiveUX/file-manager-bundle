<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class TrashRestoredEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        string $canonicalPath,
        public readonly ?string $originalCanonicalPath = null,
        public readonly ?bool $isFolder = null,
    ) {
        parent::__construct($profile, $volume, $path, $canonicalPath);
    }
}
