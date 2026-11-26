<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\EventListener;

use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\Event\ItemClonedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\Event\TrashRestoredEvent;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class MetadataSyncListener implements EventSubscriberInterface
{
    public function __construct(
        private FolderMetadataStorageInterface $metadataStorage,
        private ?AssetEnrichmentService $enrichmentService = null,
        private ?\LiveUX\FileManagerBundle\Storage\VolumeRegistry $volumeRegistry = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onFileUploaded',
            FileDeletedEvent::class => 'onFileDeleted',
            FolderDeletedEvent::class => 'onFolderDeleted',
            ItemRenamedEvent::class => 'onItemRenamed',
            ItemMovedEvent::class => 'onItemMoved',
            ItemClonedEvent::class => 'onItemCloned',
            TrashRestoredEvent::class => 'onTrashRestored',
            TrashPurgedEvent::class => 'onTrashPurged',
        ];
    }

    public function onFileUploaded(FileUploadedEvent $event): void
    {
        if (
            $event->fromArchiveExtraction
            || $this->enrichmentService === null
            || ($event->profile->ai['auto_enrich_on_upload'] ?? false) !== true
            || !$this->enrichmentService->isAvailableForProfile($event->profile)
            || $event->mimeType === null
            || !$this->enrichmentService->supportsMime($event->mimeType)
        ) {
            return;
        }

        try {
            $this->enrichmentService->enrichAndSave(
                profile: $event->profile,
                path: $event->path,
                volumeName: $event->volume->name,
                onlyMissing: true,
                skipVoter: false,
            );
        } catch (Throwable) {
            // Best-effort auto-enrichment must never abort or fail a completed file upload.
        }
    }

    public function onFileDeleted(FileDeletedEvent $event): void
    {
        if (!$event->movedToTrash) {
            $this->metadataStorage->delete($event->canonicalPath);

            return;
        }

        if ($event->trashCanonicalPath !== null && $event->trashCanonicalPath !== '') {
            $this->metadataStorage->relocatePrefix($event->canonicalPath, $event->trashCanonicalPath);
        }
    }

    public function onFolderDeleted(FolderDeletedEvent $event): void
    {
        if (!$event->movedToTrash) {
            $this->metadataStorage->delete($event->canonicalPath);

            return;
        }

        if ($event->trashCanonicalPath !== null && $event->trashCanonicalPath !== '') {
            $this->metadataStorage->relocatePrefix($event->canonicalPath, $event->trashCanonicalPath);
        }
    }

    public function onItemRenamed(ItemRenamedEvent $event): void
    {
        $this->metadataStorage->relocatePrefix($event->canonicalPath, $event->newCanonicalPath);
    }

    public function onItemMoved(ItemMovedEvent $event): void
    {
        if (!$event->isCopy) {
            $this->metadataStorage->relocatePrefix($event->canonicalPath, $event->targetCanonicalPath);

            return;
        }

        if ($event->isFolder) {
            $this->copyFilteredFolderMetadata(
                $event->profile,
                $event->targetVolume,
                $event->canonicalPath,
                $event->targetPath,
                $event->targetCanonicalPath,
            );

            return;
        }

        $meta = $this->metadataStorage->get($event->canonicalPath);
        $this->metadataStorage->save($event->targetCanonicalPath, $meta);
    }

    public function onItemCloned(ItemClonedEvent $event): void
    {
        if ($event->isFolder) {
            $this->copyFilteredFolderMetadata(
                $event->profile,
                $event->volume,
                $event->canonicalPath,
                $event->clonedPath,
                $event->clonedCanonicalPath,
            );

            return;
        }

        $meta = $this->metadataStorage->get($event->canonicalPath);
        $this->metadataStorage->save($event->clonedCanonicalPath, $meta);
    }

    /**
     * Copies folder metadata ONLY for files/subfolders that actually survived copy/clone
     * into the target directory, avoiding any transient disclosure of voter-denied or
     * excluded child metadata (and eliminating N+1 post-copy deletion queries).
     */
    private function copyFilteredFolderMetadata(
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        \LiveUX\FileManagerBundle\Config\VolumeConfig $targetVolume,
        string $sourceCanonicalPrefix,
        string $targetFolderPath,
        string $targetCanonicalPrefix,
    ): void {
        if ($this->volumeRegistry === null) {
            $this->metadataStorage->copyPrefix($sourceCanonicalPrefix, $targetCanonicalPrefix);

            return;
        }

        try {
            $sourceEntries = method_exists($this->metadataStorage, 'getByPrefix')
                ? $this->metadataStorage->getByPrefix($sourceCanonicalPrefix)
                : $this->metadataStorage->all();

            $sourceSlashPrefix = str_ends_with($sourceCanonicalPrefix, '::')
                ? $sourceCanonicalPrefix
                : $sourceCanonicalPrefix . '/';

            $relevant = [];
            foreach ($sourceEntries as $canonicalKey => $meta) {
                if ($canonicalKey === $sourceCanonicalPrefix || str_starts_with($canonicalKey, $sourceSlashPrefix)) {
                    $relevant[$canonicalKey] = $meta;
                }
            }
            if ($relevant === []) {
                return;
            }

            $existingPaths = $this->collectExistingTargetPaths($profile, $targetVolume, $targetFolderPath);

            $toSave = [];
            foreach ($relevant as $sourceKey => $meta) {
                if ($sourceKey === $sourceCanonicalPrefix) {
                    $toSave[$targetCanonicalPrefix] = $meta;
                    continue;
                }
                $rel = ltrim(substr($sourceKey, strlen($sourceCanonicalPrefix)), '/');
                if ($rel === '') {
                    continue;
                }
                $candidatePath = $targetFolderPath !== '' ? $targetFolderPath . '/' . $rel : $rel;
                if (isset($existingPaths[$candidatePath])) {
                    $targetKey = str_ends_with($targetCanonicalPrefix, '::')
                        ? $targetCanonicalPrefix . $rel
                        : $targetCanonicalPrefix . '/' . $rel;
                    $toSave[$targetKey] = $meta;
                }
            }

            if ($toSave !== []) {
                if (method_exists($this->metadataStorage, 'saveMany')) {
                    $this->metadataStorage->saveMany($toSave);
                } else {
                    foreach ($toSave as $key => $meta) {
                        $this->metadataStorage->save($key, $meta);
                    }
                }
            }
        } catch (Throwable) {
            // Best-effort sync must never leak voter-denied child metadata on storage errors.
        }
    }

    /**
     * @return array<string, true>
     */
    private function collectExistingTargetPaths(
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        \LiveUX\FileManagerBundle\Config\VolumeConfig $volume,
        string $targetFolderPath,
    ): array {
        if ($this->volumeRegistry === null) {
            return [];
        }

        $fs = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $normalizer = new \LiveUX\FileManagerBundle\Security\PathNormalizer();
        $existingPaths = [];
        foreach ($fs->listContents($targetFolderPath, true) as $attr) {
            try {
                $normalized = $normalizer->normalize($attr->path());
            } catch (Throwable) {
                continue;
            }
            if ($normalized === '') {
                continue;
            }
            $existingPaths[$normalized] = true;
            $ancestor = \LiveUX\FileManagerBundle\Security\PathNormalizer::dirnameUtf8($normalized);
            while ($ancestor !== '' && $ancestor !== $targetFolderPath) {
                $existingPaths[$ancestor] = true;
                $ancestor = \LiveUX\FileManagerBundle\Security\PathNormalizer::dirnameUtf8($ancestor);
            }
        }

        return $existingPaths;
    }

    public function onTrashRestored(TrashRestoredEvent $event): void
    {
        if ($event->originalCanonicalPath !== null && $event->originalCanonicalPath !== $event->canonicalPath) {
            $this->metadataStorage->relocatePrefix($event->originalCanonicalPath, $event->canonicalPath);
        }
    }

    public function onTrashPurged(TrashPurgedEvent $event): void
    {
        if ($event->trashCanonicalPath !== null && $event->trashCanonicalPath !== '') {
            $this->metadataStorage->delete($event->trashCanonicalPath);
        }
        if ($event->originalCanonicalPath !== null && $event->originalCanonicalPath !== '') {
            $this->metadataStorage->delete($event->originalCanonicalPath);
        }
    }
}
