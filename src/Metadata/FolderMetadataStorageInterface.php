<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Metadata;

interface FolderMetadataStorageInterface
{
    public function get(string $canonicalKey): FolderMetadata;

    /**
     * @return array<string, FolderMetadata>
     */
    public function all(): array;

    public function save(string $canonicalKey, FolderMetadata $metadata): void;

    public function delete(string $canonicalKey): void;

    public function relocatePrefix(string $oldCanonicalPrefix, string $newCanonicalPrefix): void;

    public function copyPrefix(string $sourceCanonicalPrefix, string $targetCanonicalPrefix): void;
}
