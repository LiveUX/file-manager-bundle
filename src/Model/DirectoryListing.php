<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Model;

final readonly class DirectoryListing
{
    /**
     * @param list<FolderItem|FileItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public bool $fromCache,
        public float $durationMs,
    ) {
    }
}
