<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Model;

use LiveUX\FileManagerBundle\Metadata\FolderMetadata;

final readonly class FolderItem
{
    public function __construct(
        public string $name,
        public string $path,
        public string $canonicalPath,
        public FolderMetadata $metadata,
        public int $lastModified = 0,
        public ?int $itemCount = null,
    ) {
    }
}
