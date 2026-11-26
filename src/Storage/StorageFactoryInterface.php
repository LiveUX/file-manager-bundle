<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Storage;

use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\VolumeConfig;

interface StorageFactoryInterface
{
    public function create(VolumeConfig $volume): FilesystemOperator;
}
