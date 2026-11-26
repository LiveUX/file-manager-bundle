<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Model;

final readonly class TrashEntry
{
    public function __construct(
        public string $id,
        public string $name,
        public string $originalPath,
        public string $volume,
        public bool $isFolder,
        public int $size,
        public int $deletedAt,
        public ?string $deletedBy = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'original_path' => $this->originalPath,
            'volume' => $this->volume,
            'is_folder' => $this->isFolder,
            'size' => $this->size,
            'deleted_at' => $this->deletedAt,
            'deleted_by' => $this->deletedBy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            originalPath: is_string($data['original_path'] ?? null) ? $data['original_path'] : '',
            volume: is_string($data['volume'] ?? null) ? $data['volume'] : '',
            isFolder: (bool) ($data['is_folder'] ?? false),
            size: is_int($data['size'] ?? null) ? $data['size'] : 0,
            deletedAt: is_int($data['deleted_at'] ?? null) ? $data['deleted_at'] : 0,
            deletedBy: is_string($data['deleted_by'] ?? null) ? $data['deleted_by'] : null,
        );
    }
}
