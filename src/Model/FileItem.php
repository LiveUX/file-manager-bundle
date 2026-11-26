<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Model;

use LiveUX\FileManagerBundle\Metadata\FolderMetadata;

final readonly class FileItem
{
    public function __construct(
        public string $name,
        public string $path,
        public string $canonicalPath,
        public int $size,
        public string $extension,
        public string $category,
        public int $lastModified,
        public ?string $publicUrl = null,
        public ?string $mimeType = null,
        public ?int $createdAt = null,
        public ?FolderMetadata $metadata = null,
    ) {
    }

    public function isImage(): bool
    {
        return $this->category === 'image';
    }

    public function isVideo(): bool
    {
        return $this->category === 'video';
    }

    public function formatSize(): string
    {
        if ($this->size < 1024) {
            return $this->size . ' B';
        }
        if ($this->size < 1048576) {
            return round($this->size / 1024, 1) . ' KB';
        }
        if ($this->size < 1073741824) {
            return round($this->size / 1048576, 1) . ' MB';
        }

        return round($this->size / 1073741824, 2) . ' GB';
    }
}
