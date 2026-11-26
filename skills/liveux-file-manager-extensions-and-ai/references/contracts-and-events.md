# Extension Contracts & Domain Events Reference

## Key Interfaces

### `LiveUX\FileManagerBundle\Config\ConfigProviderInterface`
- `hasProfile(string $name): bool`
- `getProfile(string $name): ProfileConfig`
- `getProfileNames(): array` (`list<string>`)
- `hasVolume(string $name): bool`
- `getVolume(string $name): VolumeConfig`
- `getVolumes(): array` (`array<string, VolumeConfig>`)

Tag custom implementations with `liveux_file_manager.config_provider` and `priority > 0`.

### `LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface`
- `get(string $canonicalKey): FolderMetadata`
- `all(): array` (`array<string, FolderMetadata>`)
- `save(string $canonicalKey, FolderMetadata $metadata): void`
- `delete(string $canonicalKey): void`
- `relocatePrefix(string $oldCanonicalPrefix, string $newCanonicalPrefix): void`
- `copyPrefix(string $sourceCanonicalPrefix, string $targetCanonicalPrefix): void`

`FolderMetadata` stores both folder properties (`color`, `icon`, `pinned`) and editorial file properties (`alt`, `title`, `caption`, `tags`, `focalPoint`).

### `LiveUX\FileManagerBundle\AI\AssetEnricherInterface`
- `supports(string $mimeType): bool`
- `enrich(string $localFilePath, string $mimeType, string $locale = 'en', array $context = []): AssetEnrichmentResult`

`AssetEnrichmentResult` properties: `public ?string $alt`, `public ?string $title`, `public ?string $caption`, `public array $tags` (`list<string>`).

### `LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface`
- `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`
- `findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array` (`list<AssetUsageReference>`)

## All 9 Domain Events (`LiveUX\FileManagerBundle\Event\*`)

All extend `AbstractFileManagerEvent` (`$profile`, `$volume`, `$path`, `$canonicalPath`):

1. `FileUploadedEvent` — `+$size`, `+$mimeType`, `+$fromArchiveExtraction`
2. `FileDeletedEvent` — `+$movedToTrash` (bool: true = moved to trash, false = deleted permanently), `+$trashCanonicalPath` (`?string`)
3. `FolderCreatedEvent`
4. `FolderDeletedEvent` — `+$movedToTrash`, `+$trashCanonicalPath`
5. `ItemRenamedEvent` — `+$oldPath`, `+$oldCanonicalPath`, `+$newPath`, `+$newCanonicalPath`, `+$isFolder`
6. `ItemMovedEvent` — `+$sourceVolume`, `+$sourcePath`, `+$sourceCanonicalPath`, `+$targetVolume`, `+$targetPath`, `+$targetCanonicalPath`, `+$isFolder`, `+$isCopy`
7. `ItemClonedEvent` — `+$sourcePath`, `+$sourceCanonicalPath`, `+$clonedPath`, `+$clonedCanonicalPath`, `+$isFolder`
8. `TrashRestoredEvent` — `+$originalCanonicalPath` (`?string`)
9. `TrashPurgedEvent` — `+$purgedCount`, `+$originalCanonicalPath` (`?string`), `+$trashCanonicalPath` (`?string`)
