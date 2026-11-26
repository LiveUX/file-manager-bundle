<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Event;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use Symfony\Contracts\EventDispatcher\Event;

abstract class AbstractFileManagerEvent extends Event
{
    public function __construct(
        public readonly ProfileConfig $profile,
        public readonly VolumeConfig $volume,
        public readonly string $path,
        public readonly string $canonicalPath,
    ) {
    }
}
