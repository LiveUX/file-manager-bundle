<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FolderCreatedEvent;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\Event\ItemClonedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class FileOperationsService
{
    private const int MAX_RECURSIVE_COPY_ITEMS = 5000;

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FilenameNormalizer $filenameNormalizer,
        private TrashManager $trashManager,
        private FolderMetadataStorageInterface $metadataStorage,
        private EventDispatcherInterface $eventDispatcher,
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private ?AssetUsageGuardService $usageGuard = null,
        private ?StorageQuotaChecker $quotaChecker = null,
        private SvgSanitizer $svgSanitizer = new SvgSanitizer(),
        ?ThumbnailManager $thumbnailManager = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
        unset($thumbnailManager);
    }

    public function createFolder(
        ProfileConfig $profile,
        string $parentPath,
        string $folderName,
        ?string $volumeName = null,
        bool $ifNotExists = false,
    ): string {
        $cleanParent = $this->pathNormalizer->normalize($parentPath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanParent, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanParent, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::CREATE_FOLDER, $cleanParent, $volume->name);

        $safeName = $this->filenameNormalizer->normalizeFolderName($folderName, $profile);
        $newPath = $this->pathNormalizer->join($cleanParent, $safeName);
        $this->fileSecurityGuard->assertAccessiblePath($newPath, $profile);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);

        if ($cleanParent !== '') {
            $this->assertParentFolderCreatable($filesystem, $profile, $cleanParent, $volume->name, $volume->isLocalFilesystem());
        }

        if ($filesystem->fileExists($newPath)) {
            throw new FileManagerException(
                sprintf('Folder "%s" already exists.', $safeName),
                'errors.folder_exists',
                ['%name%' => $safeName],
            );
        }

        if ($filesystem->directoryExists($newPath)) {
            if ($ifNotExists) {
                return $newPath;
            }

            throw new FileManagerException(
                sprintf('Folder "%s" already exists.', $safeName),
                'errors.folder_exists',
                ['%name%' => $safeName],
            );
        }

        $filesystem->createDirectory($newPath);

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $newPath, $profile->basePath);
        $this->eventDispatcher->dispatch(new FolderCreatedEvent($profile, $volume, $newPath, $canonical));

        return $newPath;
    }

    public function rename(ProfileConfig $profile, string $path, string $newName, ?string $volumeName = null): string
    {
        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $this->fileSecurityGuard->assertNotTrashAncestor($cleanPath, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::RENAME, $cleanPath, $volume->name);

        if ($cleanPath === '') {
            throw new FileManagerException('Cannot rename root directory.', 'errors.cannot_rename_root');
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $isFolder = $filesystem->directoryExists($cleanPath);

        if (!$isFolder && !$filesystem->fileExists($cleanPath)) {
            throw new FileManagerException(
                sprintf('Item "%s" does not exist.', $cleanPath),
                'errors.item_not_found',
                ['%path%' => $cleanPath],
            );
        }

        $parentDir = PathNormalizer::dirnameUtf8($cleanPath);
        $safeName = $isFolder
            ? $this->filenameNormalizer->normalizeFolderName($newName, $profile)
            : $this->filenameNormalizer->normalizeForRename(PathNormalizer::basenameUtf8($cleanPath), $newName, $profile, PathNormalizer::basenameUtf8($parentDir));

        $newPath = $this->pathNormalizer->join($parentDir, $safeName);
        $this->fileSecurityGuard->assertAccessiblePath($newPath, $profile);
        if ($newPath === $cleanPath) {
            return $cleanPath;
        }

        $isCaseOnlyRename = $this->isSameLocalInodeRename($volume, $filesystem, $cleanPath, $newPath);
        if (!$isCaseOnlyRename && ($filesystem->fileExists($newPath) || $filesystem->directoryExists($newPath))) {
            throw new FileManagerException(
                sprintf('Target "%s" already exists.', $safeName),
                'errors.target_exists',
                ['%name%' => $safeName],
            );
        }

        $this->usageGuard?->assertAllowed($profile, $volume, $cleanPath, $isFolder, 'rename');

        if ($isFolder && $volume->isLocalFilesystem()) {
            $this->validateDirectoryContentsForMove($filesystem, $cleanPath, $profile, FileManagerPermissions::RENAME, $volume->name, $newPath);
        }

        $sanitizedSvgPayload = !$isFolder
            ? $this->validateExtensionChangeSecurity($filesystem, $profile, $cleanPath, $newPath)
            : null;

        if ($isFolder && !$volume->isLocalFilesystem()) {
            // copyDirectory(..., strictMove: true) performs the full recursive pre-validation before any write,
            // avoiding a redundant second S3/R2 recursive listContents() scan.
            try {
                $this->copyDirectory($filesystem, $filesystem, $cleanPath, $newPath, $profile, false, FileManagerPermissions::RENAME, $volume->name, true);
                $filesystem->deleteDirectory($cleanPath);
            } finally {
                $this->quotaChecker?->invalidateQuotaCache($profile, $volume->name);
            }
        } elseif ($sanitizedSvgPayload !== null) {
            $filesystem->write($newPath, $sanitizedSvgPayload);
            if (!$isCaseOnlyRename) {
                $filesystem->delete($cleanPath);
            }
        } elseif ($isCaseOnlyRename && $volume->isLocalFilesystem()) {
            $this->performCaseOnlyLocalRename($filesystem, $cleanPath, $newPath);
        } else {
            $filesystem->move($cleanPath, $newPath);
        }

        $oldCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $newCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $newPath, $profile->basePath);
        $this->eventDispatcher->dispatch(new ItemRenamedEvent($profile, $volume, $cleanPath, $oldCanonical, $newPath, $newCanonical, $isFolder));

        return $newPath;
    }

    public function cloneItem(ProfileConfig $profile, string $path, string $newName, ?string $volumeName = null): string
    {
        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::CLONE, $cleanPath, $volume->name);

        if ($cleanPath === '') {
            throw new FileManagerException('Cannot clone root directory.', 'errors.cannot_clone_root');
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $isFolder = $filesystem->directoryExists($cleanPath);

        if (!$isFolder && !$filesystem->fileExists($cleanPath)) {
            throw new FileManagerException(
                sprintf('Item "%s" does not exist.', $cleanPath),
                'errors.item_not_found',
                ['%path%' => $cleanPath],
            );
        }

        $parentDir = PathNormalizer::dirnameUtf8($cleanPath);
        $safeName = $isFolder
            ? $this->filenameNormalizer->normalizeFolderName($newName, $profile)
            : $this->filenameNormalizer->normalizeForRename(PathNormalizer::basenameUtf8($cleanPath), $newName, $profile, PathNormalizer::basenameUtf8($parentDir));

        $clonedPath = $this->pathNormalizer->join($parentDir, $safeName);
        $this->fileSecurityGuard->assertAccessiblePath($clonedPath, $profile);
        if ($filesystem->fileExists($clonedPath) || $filesystem->directoryExists($clonedPath)) {
            throw new FileManagerException(
                sprintf('Target "%s" already exists.', $safeName),
                'errors.target_exists',
                ['%name%' => $safeName],
            );
        }

        $executeClone = function () use ($profile, $filesystem, $cleanPath, $clonedPath, $safeName, $isFolder, $volume): void {
            if ($filesystem->fileExists($clonedPath) || $filesystem->directoryExists($clonedPath)) {
                throw new FileManagerException(
                    sprintf('Target "%s" already exists.', $safeName),
                    'errors.target_exists',
                    ['%name%' => $safeName],
                );
            }
            $this->assertTransferQuota($profile, $filesystem, $cleanPath, $isFolder, $volume->name);

            $sanitizedSvgPayload = !$isFolder
                ? $this->validateExtensionChangeSecurity($filesystem, $profile, $cleanPath, $clonedPath)
                : null;

            try {
                if ($isFolder) {
                    $this->copyDirectory($filesystem, $filesystem, $cleanPath, $clonedPath, $profile, $volume->isLocalFilesystem(), FileManagerPermissions::CLONE, $volume->name);
                } elseif ($sanitizedSvgPayload !== null) {
                    $filesystem->write($clonedPath, $sanitizedSvgPayload);
                } else {
                    $filesystem->copy($cleanPath, $clonedPath);
                }
            } finally {
                $this->quotaChecker?->invalidateQuotaCache($profile, $volume->name);
            }
        };

        if ($this->quotaChecker !== null) {
            $this->quotaChecker->withQuotaLock($profile, $volume->name, $executeClone, true);
        } else {
            $executeClone();
        }

        $sourceCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $clonedCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $clonedPath, $profile->basePath);
        $this->eventDispatcher->dispatch(new ItemClonedEvent($profile, $volume, $cleanPath, $sourceCanonical, $clonedPath, $clonedCanonical, $isFolder));

        return $clonedPath;
    }

    public function moveOrCopy(
        ProfileConfig $profile,
        string $sourcePath,
        string $targetDirectory,
        bool $isCopy = false,
        ?string $sourceVolumeName = null,
        ?string $targetVolumeName = null,
    ): string {
        $cleanSource = $this->pathNormalizer->normalize($sourcePath);
        if ($cleanSource === '') {
            throw new FileManagerException('Cannot move or copy root directory.', 'errors.cannot_move_root');
        }

        $context = $this->resolveTransferContext($profile, $targetDirectory, $isCopy, $sourceVolumeName, $targetVolumeName);
        $this->assertTargetTransferPermissions(
            $profile,
            $context['targetVolume'],
            $context['cleanTargetDir'],
            $context['permission'],
            $isCopy || !$context['sameVolume'],
        );
        $this->assertLocalTargetDirectoryExists($context['targetVolume'], $context['targetFs'], $context['cleanTargetDir']);

        $candidate = $this->validateTransferCandidate($profile, $context, $cleanSource, $isCopy);
        if ($candidate['noOp']) {
            return $candidate['targetPath'];
        }

        $sourceVolume = $context['sourceVolume'];
        $targetVolume = $context['targetVolume'];
        $permission = $context['permission'];
        $sourceFs = $context['sourceFs'];
        $targetFs = $context['targetFs'];
        $sameVolume = $context['sameVolume'];
        $cleanSource = $candidate['cleanSource'];
        $targetPath = $candidate['targetPath'];
        $isFolder = $candidate['isFolder'];

        $needsWriteLock = $this->quotaChecker !== null;
        $executeTransfer = function () use (
            $profile,
            $sourceFs,
            $targetFs,
            $cleanSource,
            $targetPath,
            $isFolder,
            $isCopy,
            $sameVolume,
            $sourceVolume,
            $targetVolume,
            $permission,
        ): void {
            if ($targetFs->fileExists($targetPath) || $targetFs->directoryExists($targetPath)) {
                throw new FileManagerException(
                    sprintf('Target "%s" already exists in destination.', PathNormalizer::basenameUtf8($targetPath)),
                    'errors.target_exists',
                    ['%name%' => PathNormalizer::basenameUtf8($targetPath)],
                );
            }
            if ($isCopy || !$sameVolume) {
                $this->assertTransferQuota($profile, $sourceFs, $cleanSource, $isFolder, $targetVolume->name);
            }

            try {
                $this->transferItem(
                    $sourceFs,
                    $targetFs,
                    $cleanSource,
                    $targetPath,
                    $isFolder,
                    $isCopy,
                    $sameVolume,
                    $sourceVolume->isLocalFilesystem(),
                    $targetVolume->isLocalFilesystem(),
                    $profile,
                    $permission,
                    $sourceVolume->name,
                    $targetVolume->name,
                );
            } finally {
                if ($isCopy || !$sameVolume) {
                    $this->quotaChecker?->invalidateQuotaCache($profile, $targetVolume->name);
                }
            }
        };

        if ($needsWriteLock) {
            $this->quotaChecker->withQuotaLock($profile, $targetVolume->name, $executeTransfer, true);
        } else {
            $executeTransfer();
        }

        $srcCanonical = $this->pathNormalizer->buildCanonicalKey($sourceVolume, $cleanSource, $profile->basePath);
        $dstCanonical = $this->pathNormalizer->buildCanonicalKey($targetVolume, $targetPath, $profile->basePath);
        $this->eventDispatcher->dispatch(new ItemMovedEvent($profile, $sourceVolume, $cleanSource, $srcCanonical, $targetVolume, $targetPath, $dstCanonical, $isFolder, $isCopy));

        return $targetPath;
    }

    public function delete(
        ProfileConfig $profile,
        string $path,
        ?string $volumeName = null,
        bool $forcePermanent = false,
        ?string $deletedBy = null,
    ): bool {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $checkPurgeOnChildren = $forcePermanent && $this->trashManager->isEnabled($profile);
        $validated = $this->validateDeleteCandidate($profile, $volume, $filesystem, $path, $checkPurgeOnChildren);
        $cleanPath = $validated['cleanPath'];
        $isFolder = $validated['isFolder'];

        $useTrash = !$forcePermanent && $this->trashManager->isEnabled($profile);
        $trashCanonical = null;
        if ($useTrash) {
            $trashEntry = $this->trashManager->moveToTrash($profile, $cleanPath, $volume->name, $deletedBy, $validated['totalBytes']);
            $trashCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '__trash_meta__/' . $trashEntry->id, $profile->basePath);
        } elseif ($isFolder) {
            $filesystem->deleteDirectory($cleanPath);
        } else {
            $filesystem->delete($cleanPath);
        }

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $event = $isFolder
            ? new FolderDeletedEvent($profile, $volume, $cleanPath, $canonical, $useTrash, $trashCanonical, $validated['canonicals'], $validated['paths'])
            : new FileDeletedEvent($profile, $volume, $cleanPath, $canonical, $useTrash, $trashCanonical);

        $this->eventDispatcher->dispatch($event);

        return $useTrash;
    }

    public function updateFolderMetadata(
        ProfileConfig $profile,
        string $folderPath,
        FolderMetadata $metadata,
        ?string $volumeName = null,
    ): void {
        $this->assertMetadataEnabled($profile);
        $cleanPath = $this->pathNormalizer->normalize($folderPath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::EDIT_METADATA, $cleanPath, $volume->name);
        $this->assertFolderExists($profile, $volume->name, $cleanPath);

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $this->metadataStorage->save($canonical, FolderMetadata::fromArray($metadata->toArray()));
    }

    public function updateFolderAppearance(
        ProfileConfig $profile,
        string $folderPath,
        ?string $color,
        ?string $icon,
        ?bool $pinned = null,
        ?string $volumeName = null,
        bool $iconExplicit = false,
    ): FolderMetadata {
        $this->assertMetadataEnabled($profile);
        $cleanPath = $this->pathNormalizer->normalize($folderPath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::EDIT_METADATA, $cleanPath, $volume->name);
        $this->assertFolderExists($profile, $volume->name, $cleanPath);

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $existing = $this->metadataStorage->get($canonical);
        $resolvedIcon = $iconExplicit ? $icon : ($icon ?? $existing->icon);
        $updated = FolderMetadata::fromArray([
            'color' => $color,
            'icon' => $resolvedIcon,
            'pinned' => $pinned ?? $existing->pinned,
            'alt' => $existing->alt,
            'title' => $existing->title,
            'caption' => $existing->caption,
            'tags' => $existing->tags,
            'focalPoint' => $existing->focalPoint,
        ]);
        $this->metadataStorage->save($canonical, $updated);

        return $updated;
    }

    public function toggleFolderPin(
        ProfileConfig $profile,
        string $folderPath,
        ?string $volumeName = null,
    ): bool {
        $this->assertMetadataEnabled($profile);
        $cleanPath = $this->pathNormalizer->normalize($folderPath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::EDIT_METADATA, $cleanPath, $volume->name);
        $this->assertFolderExists($profile, $volume->name, $cleanPath);

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $existing = $this->metadataStorage->get($canonical);
        $nextPinned = !$existing->pinned;
        $this->metadataStorage->save($canonical, new FolderMetadata(
            color: $existing->color,
            icon: $existing->icon,
            pinned: $nextPinned,
            alt: $existing->alt,
            title: $existing->title,
            caption: $existing->caption,
            tags: $existing->tags,
            focalPoint: $existing->focalPoint,
        ));

        return $nextPinned;
    }

    /**
     * @param list<string>                     $tags
     * @param array{x: float, y: float}|null $focalPoint
     */
    public function updateFileMetadata(
        ProfileConfig $profile,
        string $filePath,
        ?string $alt,
        ?string $title,
        ?string $caption,
        array $tags,
        ?array $focalPoint,
        ?string $volumeName = null,
    ): FolderMetadata {
        $this->assertMetadataEnabled($profile);
        $cleanPath = $this->pathNormalizer->normalize($filePath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::EDIT_METADATA, $cleanPath, $volume->name);

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        if ($cleanPath === '' || !$filesystem->fileExists($cleanPath)) {
            throw new FileManagerException(
                sprintf('Item "%s" does not exist.', $cleanPath),
                'errors.item_not_found',
                ['%path%' => $cleanPath],
            );
        }

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $existing = $this->metadataStorage->get($canonical);

        $updated = FolderMetadata::fromArray([
            'color' => $existing->color,
            'icon' => $existing->icon,
            'pinned' => $existing->pinned,
            'alt' => $alt,
            'title' => $title,
            'caption' => $caption,
            'tags' => $tags,
            'focalPoint' => $focalPoint,
        ]);

        $this->metadataStorage->save($canonical, $updated);

        return $updated;
    }

    private function assertMetadataEnabled(ProfileConfig $profile): void
    {
        if (($profile->metadata['enabled'] ?? true) !== true) {
            throw new AccessDeniedException('Metadata editing is disabled for this profile.', 'errors.access_denied');
        }
    }

    private function assertFolderExists(ProfileConfig $profile, string $volumeName, string $cleanPath): void
    {
        if ($cleanPath === '') {
            return;
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        if (!$filesystem->directoryExists($cleanPath)) {
            throw new FileManagerException(
                sprintf('Item "%s" does not exist.', $cleanPath),
                'errors.item_not_found',
                ['%path%' => $cleanPath],
            );
        }
    }

    /**
     * Prevents Stored XSS and upload_allow/upload_deny bypasses when `naming.allow_extension_change: true`
     * allows renaming or cloning a file into a different extension (especially `.svg`).
     */
    private function validateExtensionChangeSecurity(
        FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $sourcePath,
        string $targetPath,
    ): ?string {
        $oldExt = PathNormalizer::extensionUtf8($sourcePath);
        $newExt = PathNormalizer::extensionUtf8($targetPath);
        if ($oldExt === $newExt) {
            return null;
        }

        $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($targetPath));

        $candidateMimes = $newExt !== '' ? MimeTypes::getDefault()->getMimeTypes($newExt) : [];
        $targetMime = strtolower($candidateMimes[0] ?? ($newExt === 'svg' ? 'image/svg+xml' : 'application/octet-stream'));

        $actualMime = $this->sniffFlysystemMimeType($filesystem, $sourcePath);
        if ($actualMime !== '') {
            $this->fileSecurityGuard->assertMimeMatchesExtension($targetPath, $actualMime);
        }

        $mimesToCheck = [$targetMime];
        if ($actualMime !== '' && $actualMime !== $targetMime) {
            $mimesToCheck[] = $actualMime;
        }

        foreach ($mimesToCheck as $mime) {
            if ($this->fileSecurityGuard->matchesMimeList($mime, $profile->uploadDeny)) {
                throw new UploadValidationException(
                    sprintf('MIME type "%s" is denied by upload_deny policy.', $mime),
                    'errors.upload_mime_denied',
                    ['%mime%' => $mime],
                );
            }

            if ($profile->uploadAllow !== [] && !$this->fileSecurityGuard->matchesMimeList($mime, $profile->uploadAllow)) {
                throw new UploadValidationException(
                    sprintf('MIME type "%s" is not in upload_allow list.', $mime),
                    'errors.upload_mime_not_allowed',
                    ['%mime%' => $mime],
                );
            }
        }

        if ($newExt === 'svg') {
            if ($filesystem->fileSize($sourcePath) > SvgSanitizer::MAX_SVG_BYTES) {
                throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
            }
            $raw = $filesystem->read($sourcePath);
            if (($profile->security['sanitize_svg'] ?? true) === true) {
                $sanitized = $this->svgSanitizer->sanitize($raw);
                if ($sanitized === '') {
                    throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
                }

                return $sanitized;
            }
            if (str_contains($raw, "\0") || !mb_check_encoding($raw, 'UTF-8') || preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $raw) === 1) {
                throw new UploadValidationException('Invalid or unsafe SVG file rejected.', 'errors.svg_invalid');
            }
        }

        return null;
    }

    private function sniffFlysystemMimeType(FilesystemOperator $filesystem, string $sourcePath): string
    {
        try {
            $stream = $filesystem->readStream($sourcePath);
            if (is_resource($stream)) {
                try {
                    $header = fread($stream, 65536);
                } finally {
                    fclose($stream);
                }
                if (is_string($header) && $header !== '' && class_exists(\finfo::class)) {
                    $finfo = new \finfo(\FILEINFO_MIME_TYPE);
                    $detected = $finfo->buffer($header);
                    if (is_string($detected) && $detected !== '') {
                        return strtolower(trim(explode(';', $detected, 2)[0]));
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to adapter metadata lookup.
        }

        try {
            return strtolower(trim(explode(';', $filesystem->mimeType($sourcePath), 2)[0]));
        } catch (\Throwable) {
            return '';
        }
    }

    private function isSameLocalInodeRename(
        \LiveUX\FileManagerBundle\Config\VolumeConfig $volume,
        FilesystemOperator $filesystem,
        string $cleanPath,
        string $newPath,
    ): bool {
        if (mb_strtolower($cleanPath, 'UTF-8') !== mb_strtolower($newPath, 'UTF-8')) {
            return false;
        }

        if (!$volume->isLocalFilesystem() || $volume->path === null || $volume->path === '') {
            return false;
        }

        if (!$filesystem->fileExists($newPath) && !$filesystem->directoryExists($newPath)) {
            return false;
        }

        $root = rtrim($volume->path, '/\\');
        $absOld = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);
        $absNew = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);

        $realOld = @realpath($absOld);
        $realNew = @realpath($absNew);
        if (is_string($realOld) && is_string($realNew) && $realOld === $realNew) {
            return true;
        }

        $statOld = @stat($absOld);
        $statNew = @stat($absNew);

        return is_array($statOld)
            && is_array($statNew)
            && $statOld['dev'] === $statNew['dev']
            && $statOld['ino'] !== 0
            && $statOld['ino'] === $statNew['ino'];
    }

    /**
     * Validates descendant delete permissions and hidden-file rules while accumulating raster thumbnail keys
     * and exact cumulative byte size in a single recursive traversal (zero duplicate S3 listContents scans).
     *
     * @return array{canonicals: list<string>, paths: list<string>, totalBytes: int}
     */
    private function validateDirectoryContentsForDelete(
        FilesystemOperator $filesystem,
        string $folderPath,
        ProfileConfig $profile,
        VolumeConfig $volume,
        bool $checkPurge,
    ): array {
        $count = 0;
        $canonicals = [];
        $paths = [];
        $totalBytes = 0;

        foreach ($filesystem->listContents($folderPath, true) as $attr) {
            ++$count;
            if ($count > self::MAX_RECURSIVE_COPY_ITEMS) {
                throw new FileManagerException(
                    sprintf('Folder exceeds maximum copy item limit (%d).', self::MAX_RECURSIVE_COPY_ITEMS),
                    'errors.copy_limit_exceeded',
                    ['%max%' => self::MAX_RECURSIVE_COPY_ITEMS],
                );
            }

            $cleanItem = $this->pathNormalizer->normalize($attr->path());
            if ($this->fileSecurityGuard->isPathIgnored($cleanItem, $profile)) {
                throw new AccessDeniedException(
                    'Folder contains protected or hidden items that cannot be deleted.',
                    'errors.access_denied',
                );
            }

            $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanItem, $volume->name);
            $this->assertGranted($profile, FileManagerPermissions::DELETE, $cleanItem, $volume->name);
            if ($checkPurge) {
                $this->assertGranted($profile, FileManagerPermissions::TRASH_PURGE, $cleanItem, $volume->name);
            }

            if ($attr instanceof FileAttributes) {
                $totalBytes += (int) ($attr->fileSize() ?? 0);
                $ext = PathNormalizer::extensionUtf8($cleanItem);
                if (in_array($ext, FileSecurityGuard::SAFE_RASTER_EXTENSIONS, true)) {
                    $canonicals[] = $this->pathNormalizer->buildCanonicalKey($volume, $cleanItem, $profile->basePath);
                    $paths[] = $cleanItem;
                }
            }
        }

        return [
            'canonicals' => $canonicals,
            'paths' => $paths,
            'totalBytes' => $totalBytes,
        ];
    }

    private function performCaseOnlyLocalRename(FilesystemOperator $filesystem, string $cleanPath, string $newPath): void
    {
        $dir = PathNormalizer::dirnameUtf8($cleanPath);
        $tempPath = ($dir !== '' ? $dir . '/' : '') . '.__ren_' . bin2hex(random_bytes(8));
        $filesystem->move($cleanPath, $tempPath);
        try {
            $filesystem->move($tempPath, $newPath);
        } catch (\Throwable $e) {
            try {
                $filesystem->move($tempPath, $cleanPath);
            } catch (\Throwable) {
                // Best-effort rollback on case-only rename failure.
            }
            throw $e;
        }
    }

    private function assertGranted(
        ProfileConfig $profile,
        string $attribute,
        string $path,
        ?string $volumeName = null,
        ?string $targetPath = null,
    ): void {
        $this->authorizer->assertPathAndAncestorsGranted($profile, $attribute, $path, $volumeName, $targetPath);
    }

    private function transferItem(
        FilesystemOperator $sourceFs,
        FilesystemOperator $targetFs,
        string $sourcePath,
        string $targetPath,
        bool $isFolder,
        bool $isCopy,
        bool $sameVolume,
        bool $canNativeMoveFolder,
        bool $targetIsLocal,
        ProfileConfig $profile,
        string $permission = FileManagerPermissions::COPY,
        string $sourceVolumeName = '',
        ?string $targetVolumeName = null,
    ): void {
        if ($sameVolume && !$isCopy && (!$isFolder || $canNativeMoveFolder)) {
            if ($isFolder) {
                $this->validateDirectoryContentsForMove($sourceFs, $sourcePath, $profile, $permission, $sourceVolumeName, $targetPath);
            }
            $sourceFs->move($sourcePath, $targetPath);

            return;
        }

        if ($sameVolume && !$isFolder) {
            $sourceFs->copy($sourcePath, $targetPath);

            return;
        }

        if ($isFolder) {
            $this->copyDirectory($sourceFs, $targetFs, $sourcePath, $targetPath, $profile, $targetIsLocal, $permission, $sourceVolumeName, !$isCopy, $targetVolumeName);
        } else {
            $this->copyStream($sourceFs, $targetFs, $sourcePath, $targetPath);
        }

        if (!$isCopy) {
            if ($isFolder) {
                $sourceFs->deleteDirectory($sourcePath);
            } else {
                $sourceFs->delete($sourcePath);
            }
        }
    }

    private function validateDirectoryContentsForMove(
        FilesystemOperator $sourceFs,
        string $sourceDir,
        ProfileConfig $profile,
        string $permission,
        string $sourceVolumeName,
        string $targetDir = '',
        bool $strictMove = true,
    ): int {
        $count = 0;
        $totalBytes = 0;
        foreach ($sourceFs->listContents($sourceDir, true) as $attr) {
            ++$count;
            if ($count > self::MAX_RECURSIVE_COPY_ITEMS) {
                throw new FileManagerException(
                    sprintf('Folder exceeds maximum copy item limit (%d).', self::MAX_RECURSIVE_COPY_ITEMS),
                    'errors.copy_limit_exceeded',
                    ['%max%' => self::MAX_RECURSIVE_COPY_ITEMS],
                );
            }
            $cleanItem = $this->pathNormalizer->normalize($attr->path());
            if ($this->fileSecurityGuard->isPathIgnored($cleanItem, $profile)) {
                if ($strictMove) {
                    throw new AccessDeniedException(
                        'Folder contains protected or hidden items that cannot be moved.',
                        'errors.access_denied',
                    );
                }
                continue;
            }
            $destTarget = null;
            if ($targetDir !== '') {
                $relative = ltrim(substr($cleanItem, strlen($sourceDir)), '/');
                $destTarget = $this->pathNormalizer->join($targetDir, $relative);
            }
            if ($sourceVolumeName !== '' && (
                !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanItem, $sourceVolumeName)
                || !$this->authorizer->isPathAndAncestorsGranted($profile, $permission, $cleanItem, $sourceVolumeName, $destTarget)
            )) {
                if ($strictMove) {
                    throw new AccessDeniedException('Access denied.', 'errors.access_denied');
                }
                continue;
            }
            if ($attr instanceof FileAttributes) {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanItem));
                $totalBytes += (int) ($attr->fileSize() ?? 0);
            } else {
                $this->fileSecurityGuard->assertSafeFolderName(PathNormalizer::basenameUtf8($cleanItem));
            }
            if ($destTarget !== null) {
                $this->fileSecurityGuard->assertAccessiblePath($destTarget, $profile);
            }
        }

        return $totalBytes;
    }

    private function copyDirectory(
        FilesystemOperator $sourceFs,
        FilesystemOperator $targetFs,
        string $sourceDir,
        string $targetDir,
        ProfileConfig $profile,
        bool $targetIsLocal = true,
        string $permission = FileManagerPermissions::COPY,
        string $sourceVolumeName = '',
        bool $strictMove = false,
        ?string $targetVolumeName = null,
    ): void {
        // Pre-validate item count, safe filenames, and per-child Voter permissions BEFORE writing any target files.
        $planned = [];
        $count = 0;
        $isCrossVolume = $targetVolumeName !== null && $targetVolumeName !== '' && $targetVolumeName !== $sourceVolumeName;
        foreach ($sourceFs->listContents($sourceDir, true) as $attr) {
            ++$count;
            if ($count > self::MAX_RECURSIVE_COPY_ITEMS) {
                throw new FileManagerException(
                    sprintf('Folder exceeds maximum copy item limit (%d).', self::MAX_RECURSIVE_COPY_ITEMS),
                    'errors.copy_limit_exceeded',
                    ['%max%' => self::MAX_RECURSIVE_COPY_ITEMS],
                );
            }
            $cleanItem = $this->pathNormalizer->normalize($attr->path());
            if ($this->fileSecurityGuard->isPathIgnored($cleanItem, $profile)) {
                if ($strictMove) {
                    throw new AccessDeniedException(
                        'Folder contains protected or hidden items that cannot be moved.',
                        'errors.access_denied',
                    );
                }
                continue;
            }
            $relative = ltrim(substr($cleanItem, strlen($sourceDir)), '/');
            $dest = $this->pathNormalizer->join($targetDir, $relative);
            $sourceDenied = $sourceVolumeName !== '' && (
                !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanItem, $sourceVolumeName)
                || !$this->authorizer->isPathAndAncestorsGranted($profile, $permission, $cleanItem, $sourceVolumeName, $dest)
            );
            $targetDenied = $isCrossVolume
                && !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $dest, $targetVolumeName);
            if ($sourceDenied || $targetDenied) {
                if ($strictMove) {
                    throw new AccessDeniedException('Access denied.', 'errors.access_denied');
                }
                continue;
            }
            if ($attr instanceof FileAttributes) {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanItem));
            } else {
                $this->fileSecurityGuard->assertSafeFolderName(PathNormalizer::basenameUtf8($cleanItem));
            }
            $this->fileSecurityGuard->assertAccessiblePath($dest, $profile);
            $planned[] = ['attr' => $attr, 'src' => $cleanItem, 'dest' => $dest];
        }

        $this->createDirectoryBestEffort($targetFs, $targetDir, $targetIsLocal);
        $sameOperator = $sourceFs === $targetFs;
        foreach ($planned as $entry) {
            $attr = $entry['attr'];
            if ($attr instanceof DirectoryAttributes) {
                $this->createDirectoryBestEffort($targetFs, $entry['dest'], $targetIsLocal);
            } elseif ($attr instanceof FileAttributes) {
                if ($sameOperator) {
                    $sourceFs->copy($entry['src'], $entry['dest']);
                } else {
                    $this->copyStream($sourceFs, $targetFs, $entry['src'], $entry['dest']);
                }
            }
        }
    }

    /**
     * Prevents recursive mkdir() from silently creating disallowed top-level/intermediate ancestor directories
     * or colliding with an existing file along the parent path.
     */
    private function assertParentFolderCreatable(
        FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $cleanParent,
        string $volumeName,
        bool $isLocal,
    ): void {
        $segments = explode('/', $cleanParent);
        $prefix = '';
        $nearestExisting = '';
        foreach ($segments as $seg) {
            $prefix = $prefix === '' ? $seg : $prefix . '/' . $seg;
            if ($filesystem->fileExists($prefix)) {
                throw new FileManagerException(
                    sprintf('Parent path "%s" is a file.', $prefix),
                    'errors.invalid_target_directory',
                );
            }
            if ($filesystem->directoryExists($prefix)) {
                $nearestExisting = $prefix;
            }
        }

        if ($nearestExisting !== $cleanParent) {
            $this->assertGranted($profile, FileManagerPermissions::VIEW, $nearestExisting, $volumeName);
            $this->assertGranted($profile, FileManagerPermissions::CREATE_FOLDER, $nearestExisting, $volumeName);
        }
        unset($isLocal);
    }

    private function createDirectoryBestEffort(FilesystemOperator $fs, string $path, bool $isLocal): void
    {
        if ($isLocal) {
            $fs->createDirectory($path);

            return;
        }

        try {
            $fs->createDirectory($path);
        } catch (FilesystemException) {
            // MIME-restricted cloud buckets (e.g. Supabase Storage) reject 0-byte directory markers;
            // non-empty directories materialize implicitly when child objects are written.
        }
    }

    /**
     * Pre-validates all items of a bulk move or copy batch before mutating any item.
     *
     * @param list<string> $sourcePaths
     */
    public function preValidateBulkMoveItems(
        ProfileConfig $profile,
        array $sourcePaths,
        string $targetDirectory,
        bool $isCopy = false,
        ?string $sourceVolumeName = null,
        ?string $targetVolumeName = null,
        ?AssetUsageGuardService $usageGuard = null,
    ): void {
        $context = $this->resolveTransferContext($profile, $targetDirectory, $isCopy, $sourceVolumeName, $targetVolumeName);
        $this->assertTargetTransferPermissions(
            $profile,
            $context['targetVolume'],
            $context['cleanTargetDir'],
            $context['permission'],
            $isCopy || !$context['sameVolume'],
        );
        $this->assertLocalTargetDirectoryExists($context['targetVolume'], $context['targetFs'], $context['cleanTargetDir']);

        $needsQuotaCheck = ($isCopy || !$context['sameVolume']) && $this->requiresQuotaCheck($profile);
        $totalTransferBytes = 0;
        $seenTargets = [];
        $caseInsensitiveTarget = $context['targetVolume']->isLocalFilesystem() && (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin');

        foreach ($sourcePaths as $sourcePath) {
            $candidate = $this->validateTransferCandidate($profile, $context, $sourcePath, $isCopy, $usageGuard);
            if ($candidate['noOp']) {
                continue;
            }

            $targetKey = $caseInsensitiveTarget ? mb_strtolower($candidate['targetPath'], 'UTF-8') : $candidate['targetPath'];
            if (isset($seenTargets[$targetKey])) {
                $basename = PathNormalizer::basenameUtf8($candidate['targetPath']);

                throw new FileManagerException(
                    sprintf('Target "%s" already exists in destination.', $basename),
                    'errors.target_exists',
                    ['%name%' => $basename],
                );
            }
            $seenTargets[$targetKey] = true;

            $folderBytes = null;
            if ($candidate['isFolder']) {
                $folderBytes = $this->validateDirectoryContentsForMove(
                    $context['sourceFs'],
                    $candidate['cleanSource'],
                    $profile,
                    $context['permission'],
                    $context['sourceVolume']->name,
                    $candidate['targetPath'],
                    !$isCopy,
                );
            }

            if ($needsQuotaCheck) {
                $totalTransferBytes += $folderBytes ?? $this->measureTransferBytes(
                    $context['sourceFs'],
                    $candidate['cleanSource'],
                    false,
                );
            }
        }

        if ($needsQuotaCheck) {
            $this->quotaChecker?->assertHasQuota($profile, $context['targetVolume']->name, $totalTransferBytes);
        }
    }

    /**
     * Pre-validates all items of a bulk delete batch before deleting or trashing any item.
     *
     * @param list<string> $paths
     */
    public function preValidateDeleteItems(
        ProfileConfig $profile,
        array $paths,
        ?string $volumeName = null,
        bool $forcePermanent = false,
        ?AssetUsageGuardService $usageGuard = null,
        bool $checkTrashQuota = true,
    ): void {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $useTrash = $checkTrashQuota && !$forcePermanent && $this->trashManager->isEnabled($profile);
        $checkPurgeOnChildren = $forcePermanent && $this->trashManager->isEnabled($profile);
        $totalTrashBytes = 0;
        $maxSingleItemBytes = 0;

        foreach ($paths as $path) {
            $validated = $this->validateDeleteCandidate($profile, $volume, $filesystem, $path, $checkPurgeOnChildren, $usageGuard);
            if ($useTrash) {
                $itemBytes = $validated['totalBytes'] ?? $this->measureTransferBytes($filesystem, $validated['cleanPath'], false);
                $totalTrashBytes += $itemBytes;
                if ($itemBytes > $maxSingleItemBytes) {
                    $maxSingleItemBytes = $itemBytes;
                }
            }
        }

        if ($useTrash) {
            $this->trashManager->assertBatchCanMoveToTrash($profile, $volume->name, $totalTrashBytes, $maxSingleItemBytes);
        }
    }

    /**
     * @return array{
     *     cleanTargetDir: string,
     *     sourceVolume: VolumeConfig,
     *     targetVolume: VolumeConfig,
     *     sourceFs: FilesystemOperator,
     *     targetFs: FilesystemOperator,
     *     permission: string,
     *     sameVolume: bool
     * }
     */
    private function resolveTransferContext(
        ProfileConfig $profile,
        string $targetDirectory,
        bool $isCopy,
        ?string $sourceVolumeName,
        ?string $targetVolumeName,
    ): array {
        $cleanTargetDir = $this->pathNormalizer->normalize($targetDirectory);
        $this->fileSecurityGuard->assertAccessiblePath($cleanTargetDir, $profile);
        $sourceVolume = $this->volumeRegistry->getVolumeForProfile($profile, $sourceVolumeName);
        $targetVolume = $this->volumeRegistry->getVolumeForProfile($profile, $targetVolumeName ?? $sourceVolume->name);

        return [
            'cleanTargetDir' => $cleanTargetDir,
            'sourceVolume' => $sourceVolume,
            'targetVolume' => $targetVolume,
            'sourceFs' => $this->volumeRegistry->getFilesystem($profile, $sourceVolume->name),
            'targetFs' => $this->volumeRegistry->getFilesystem($profile, $targetVolume->name),
            'permission' => $isCopy ? FileManagerPermissions::COPY : FileManagerPermissions::MOVE,
            'sameVolume' => $sourceVolume->name === $targetVolume->name,
        ];
    }

    private function assertTargetTransferPermissions(
        ProfileConfig $profile,
        VolumeConfig $targetVolume,
        string $cleanTargetDir,
        string $permission,
        bool $requiresUploadPermission,
    ): void {
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanTargetDir, $targetVolume->name);
        $this->assertGranted($profile, $permission, $cleanTargetDir, $targetVolume->name);
        if ($requiresUploadPermission) {
            $this->assertGranted($profile, FileManagerPermissions::UPLOAD, $cleanTargetDir, $targetVolume->name);
        }
    }

    private function assertLocalTargetDirectoryExists(
        VolumeConfig $targetVolume,
        FilesystemOperator $targetFs,
        string $cleanTargetDir,
    ): void {
        if ($cleanTargetDir !== '' && $targetVolume->isLocalFilesystem() && !$targetFs->directoryExists($cleanTargetDir)) {
            throw new FileManagerException(
                sprintf('Target folder "%s" does not exist.', $cleanTargetDir),
                'errors.item_not_found',
                ['%path%' => $cleanTargetDir],
            );
        }
    }

    /**
     * @param array{
     *     cleanTargetDir: string,
     *     sourceVolume: VolumeConfig,
     *     targetVolume: VolumeConfig,
     *     sourceFs: FilesystemOperator,
     *     targetFs: FilesystemOperator,
     *     permission: string,
     *     sameVolume: bool
     * } $context
     *
     * @return array{cleanSource: string, targetPath: string, isFolder: bool, noOp: bool}
     */
    private function validateTransferCandidate(
        ProfileConfig $profile,
        array $context,
        string $sourcePath,
        bool $isCopy,
        ?AssetUsageGuardService $usageGuard = null,
    ): array {
        $cleanSource = $this->pathNormalizer->normalize($sourcePath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanSource, $profile);
        if (!$isCopy) {
            $this->fileSecurityGuard->assertNotTrashAncestor($cleanSource, $profile);
        }
        if ($cleanSource === '') {
            throw new FileManagerException('Cannot move or copy root directory.', 'errors.cannot_move_root');
        }

        $sourceVolume = $context['sourceVolume'];
        $sourceFs = $context['sourceFs'];
        $targetFs = $context['targetFs'];
        $cleanTargetDir = $context['cleanTargetDir'];
        $permission = $context['permission'];
        $sameVolume = $context['sameVolume'];

        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanSource, $sourceVolume->name);
        $this->assertGranted($profile, $permission, $cleanSource, $sourceVolume->name, $cleanTargetDir);

        $isFolder = $sourceFs->directoryExists($cleanSource);
        if (!$isFolder && !$sourceFs->fileExists($cleanSource)) {
            throw new FileManagerException(
                sprintf('Source item "%s" does not exist.', $cleanSource),
                'errors.source_not_found',
                ['%path%' => $cleanSource],
            );
        }

        $sourceBasename = PathNormalizer::basenameUtf8($cleanSource);
        if ($isFolder) {
            $this->fileSecurityGuard->assertSafeFolderName($sourceBasename);
        } else {
            $this->fileSecurityGuard->assertSafeFilename($sourceBasename);
        }

        $targetPath = $this->pathNormalizer->join($cleanTargetDir, $sourceBasename);
        $this->fileSecurityGuard->assertAccessiblePath($targetPath, $profile);
        if ($isFolder) {
            $this->fileSecurityGuard->assertSafeFolderName(PathNormalizer::basenameUtf8($targetPath));
        } else {
            $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($targetPath));
        }

        if ($targetPath === $cleanSource && $sameVolume && !$isCopy) {
            return [
                'cleanSource' => $cleanSource,
                'targetPath' => $targetPath,
                'isFolder' => $isFolder,
                'noOp' => true,
            ];
        }

        if ($isFolder) {
            $this->assertNotMovingFolderIntoSelf($sourceVolume, $context['targetVolume'], $cleanSource, $targetPath, $sameVolume);
        }

        if ($targetFs->fileExists($targetPath) || $targetFs->directoryExists($targetPath)) {
            throw new FileManagerException(
                sprintf('Target "%s" already exists in destination.', PathNormalizer::basenameUtf8($targetPath)),
                'errors.target_exists',
                ['%name%' => PathNormalizer::basenameUtf8($targetPath)],
            );
        }

        if (!$isCopy) {
            ($usageGuard ?? $this->usageGuard)?->assertAllowed($profile, $sourceVolume, $cleanSource, $isFolder, 'move');
        }

        return [
            'cleanSource' => $cleanSource,
            'targetPath' => $targetPath,
            'isFolder' => $isFolder,
            'noOp' => false,
        ];
    }

    /**
     * @return array{cleanPath: string, isFolder: bool, canonicals: list<string>, paths: list<string>, totalBytes: ?int}
     */
    private function validateDeleteCandidate(
        ProfileConfig $profile,
        VolumeConfig $volume,
        FilesystemOperator $filesystem,
        string $path,
        bool $checkPurgeOnChildren,
        ?AssetUsageGuardService $usageGuard = null,
    ): array {
        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $this->fileSecurityGuard->assertNotTrashAncestor($cleanPath, $profile);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::DELETE, $cleanPath, $volume->name);

        if ($checkPurgeOnChildren) {
            $this->assertGranted($profile, FileManagerPermissions::TRASH_PURGE, $cleanPath, $volume->name);
        }

        if ($cleanPath === '') {
            throw new FileManagerException('Cannot delete root directory.', 'errors.cannot_delete_root');
        }

        $isFolder = $filesystem->directoryExists($cleanPath);
        if (!$isFolder && !$filesystem->fileExists($cleanPath)) {
            throw new FileManagerException(
                sprintf('Item "%s" does not exist.', $cleanPath),
                'errors.item_not_found',
                ['%path%' => $cleanPath],
            );
        }

        ($usageGuard ?? $this->usageGuard)?->assertDeletable($profile, $volume, $cleanPath, $isFolder);
        $summary = ['canonicals' => [], 'paths' => [], 'totalBytes' => null];
        if ($isFolder) {
            $summary = $this->validateDirectoryContentsForDelete($filesystem, $cleanPath, $profile, $volume, $checkPurgeOnChildren);
        }

        return [
            'cleanPath' => $cleanPath,
            'isFolder' => $isFolder,
            'canonicals' => $summary['canonicals'],
            'paths' => $summary['paths'],
            'totalBytes' => $summary['totalBytes'],
        ];
    }

    private function requiresQuotaCheck(ProfileConfig $profile): bool
    {
        return $this->quotaChecker !== null && $profile->storageQuota !== null && trim($profile->storageQuota) !== '';
    }

    private function assertTransferQuota(
        ProfileConfig $profile,
        FilesystemOperator $sourceFs,
        string $cleanSource,
        bool $isFolder,
        string $targetVolumeName,
    ): void {
        if (!$this->requiresQuotaCheck($profile)) {
            return;
        }

        $bytes = $this->measureTransferBytes($sourceFs, $cleanSource, $isFolder);
        $this->quotaChecker?->assertHasQuota($profile, $targetVolumeName, $bytes);
    }

    private function measureTransferBytes(
        FilesystemOperator $sourceFs,
        string $cleanSource,
        bool $isFolder,
    ): int {
        $bytes = 0;
        if (!$isFolder) {
            try {
                return $sourceFs->fileSize($cleanSource);
            } catch (\Throwable) {
                return 0;
            }
        }

        $count = 0;
        foreach ($sourceFs->listContents($cleanSource, true) as $attr) {
            if (++$count > self::MAX_RECURSIVE_COPY_ITEMS) {
                break;
            }
            if ($attr instanceof FileAttributes) {
                $bytes += (int) ($attr->fileSize() ?? 0);
            }
        }

        return $bytes;
    }

    private function assertNotMovingFolderIntoSelf(
        VolumeConfig $sourceVolume,
        VolumeConfig $targetVolume,
        string $cleanSource,
        string $targetPath,
        bool $sameVolume,
    ): void {
        $caseInsensitive = $sourceVolume->isLocalFilesystem() && (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin');
        if ($sameVolume) {
            $cmpSource = $caseInsensitive ? mb_strtolower($cleanSource, 'UTF-8') : $cleanSource;
            $cmpTarget = $caseInsensitive ? mb_strtolower($targetPath, 'UTF-8') : $targetPath;
            if ($cmpTarget === $cmpSource || str_starts_with($cmpTarget, $cmpSource . '/')) {
                throw new FileManagerException('Cannot move or copy a folder into itself.', 'errors.cannot_move_into_self');
            }

            return;
        }

        if (!$sourceVolume->isLocalFilesystem() && !$targetVolume->isLocalFilesystem()) {
            $srcCanonical = $this->pathNormalizer->buildCanonicalKey($sourceVolume, $cleanSource);
            $dstCanonical = $this->pathNormalizer->buildCanonicalKey($targetVolume, $targetPath);
            if ($dstCanonical === $srcCanonical || str_starts_with($dstCanonical, $srcCanonical . '/')) {
                throw new FileManagerException('Cannot move or copy a folder into itself.', 'errors.cannot_move_into_self');
            }

            return;
        }

        if (
            !$sourceVolume->isLocalFilesystem()
            || !$targetVolume->isLocalFilesystem()
            || $sourceVolume->path === null
            || $targetVolume->path === null
        ) {
            return;
        }

        $srcRoot = @realpath($sourceVolume->path);
        $dstRoot = @realpath($targetVolume->path);
        if (!is_string($srcRoot) || !is_string($dstRoot)) {
            return;
        }

        $absSrc = str_replace('\\', '/', rtrim($srcRoot, '/\\')) . '/' . $cleanSource;
        $absDst = str_replace('\\', '/', rtrim($dstRoot, '/\\')) . '/' . $targetPath;
        $cmpSrc = $caseInsensitive ? mb_strtolower($absSrc, 'UTF-8') : $absSrc;
        $cmpDst = $caseInsensitive ? mb_strtolower($absDst, 'UTF-8') : $absDst;
        if ($cmpDst === $cmpSrc || str_starts_with($cmpDst, $cmpSrc . '/')) {
            throw new FileManagerException('Cannot move or copy a folder into itself.', 'errors.cannot_move_into_self');
        }
    }

    private function copyStream(
        FilesystemOperator $sourceFs,
        FilesystemOperator $targetFs,
        string $sourcePath,
        string $targetPath,
    ): void {
        $stream = $sourceFs->readStream($sourcePath);
        try {
            $targetFs->writeStream($targetPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
