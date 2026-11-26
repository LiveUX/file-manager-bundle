<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class FileDeletedEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        string $canonicalPath,
        public readonly bool $movedToTrash = false,
        public readonly ?string $trashCanonicalPath = null,
    ) {
        parent::__construct($profile, $volume, $path, $canonicalPath);
    }
}
