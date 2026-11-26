<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

final class TrashPurgedEvent extends AbstractFileManagerEvent
{
    public function __construct(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        string $canonicalPath,
        public readonly int $purgedCount = 1,
        public readonly ?string $originalCanonicalPath = null,
        public readonly ?string $trashCanonicalPath = null,
    ) {
        parent::__construct($profile, $volume, $path, $canonicalPath);
    }
}
