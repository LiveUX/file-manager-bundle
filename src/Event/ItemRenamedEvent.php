<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class ItemRenamedEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        VolumeConfig $volume,
        public readonly string $oldPath,
        public readonly string $oldCanonicalPath,
        public readonly string $newPath,
        public readonly string $newCanonicalPath,
        public readonly bool $isFolder,
    ) {
        parent::__construct($profile, $volume, $oldPath, $oldCanonicalPath);
    }
}
