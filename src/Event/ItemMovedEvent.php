<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class ItemMovedEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        public readonly VolumeConfig $sourceVolume,
        public readonly string $sourcePath,
        public readonly string $sourceCanonicalPath,
        public readonly VolumeConfig $targetVolume,
        public readonly string $targetPath,
        public readonly string $targetCanonicalPath,
        public readonly bool $isFolder,
        public readonly bool $isCopy = false,
    ) {
        parent::__construct($profile, $sourceVolume, $sourcePath, $sourceCanonicalPath);
    }
}
