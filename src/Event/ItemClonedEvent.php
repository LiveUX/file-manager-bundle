<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class ItemClonedEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        VolumeConfig $volume,
        public readonly string $sourcePath,
        public readonly string $sourceCanonicalPath,
        public readonly string $clonedPath,
        public readonly string $clonedCanonicalPath,
        public readonly bool $isFolder,
    ) {
        parent::__construct($profile, $volume, $sourcePath, $sourceCanonicalPath);
    }
}
