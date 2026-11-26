<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Usage;

/**
 * Immutable value object describing a single place in the host application
 * where an asset (file or folder) is currently referenced.
 */
final readonly class AssetUsageReference
{
    public function __construct(
        public string $label,
        public ?string $type = null,
        public ?string $editUrl = null,
        public ?string $entityId = null,
    ) {
    }

    /**
     * @return array{label: string, type: ?string, editUrl: ?string, entityId: ?string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'type' => $this->type,
            'editUrl' => $this->editUrl,
            'entityId' => $this->entityId,
        ];
    }
}
