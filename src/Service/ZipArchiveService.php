<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\FolderCreatedEvent;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use ZipArchive;

/**
 * Handles ZIP creation (multi-file download) and opt-in hardened ZIP extraction
 * (immune to Zip-Slip, Zip-Bomb, Unix symlinks, and CVE-2026-81891 MIME/extension bypasses).
 * Gracefully degrades when PHP ext-zip is not installed.
 */
final readonly class ZipArchiveService
{
    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private SvgSanitizer $svgSanitizer = new SvgSanitizer(),
        ?AuthorizationCheckerInterface $authChecker = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?StorageQuotaChecker $quotaChecker = null,
        private ImageOptimizer $imageOptimizer = new ImageOptimizer(),
        private ?DuplicateDetector $duplicateDetector = null,
        private ?AssetUsageGuardService $usageGuard = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function isZipArchiveAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function isAvailableForProfile(ProfileConfig $profile): bool
    {
        if (($profile->zip['enabled'] ?? true) !== true) {
            return false;
        }

        return $this->isZipArchiveAvailable();
    }

    public function isExtractAvailableForProfile(ProfileConfig $profile): bool
    {
        if (($profile->zip['allow_extract'] ?? false) !== true) {
            return false;
        }

        return $this->isZipArchiveAvailable();
    }

    /**
     * @param list<string> $paths
     */
    public function createTempZip(ProfileConfig $profile, array $paths, ?string $volumeName = null): string
    {
        if (!$this->isAvailableForProfile($profile)) {
            throw new FileManagerException('ZIP download is disabled or PHP ext-zip is not available on this server.', 'errors.zip_unavailable');
        }

        $maxFiles = max(1, (int) ($profile->zip['max_files'] ?? 100));
        $maxBytes = ByteSizeParser::parse((string) ($profile->zip['max_total_size'] ?? '250M'));
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);

        $entriesToZip = $this->expandPathsForZip($filesystem, $profile, $volume->name, $paths, $maxFiles);

        $tempFile = tempnam(sys_get_temp_dir(), 'liveux_fm_');
        if ($tempFile === false) {
            throw new FileManagerException('Unable to allocate temporary file for ZIP archive.', 'errors.zip_create_failed');
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            @unlink($tempFile);
            throw new FileManagerException('Unable to open temporary ZIP archive.', 'errors.zip_create_failed');
        }

        $totalBytes = 0;
        $addedCount = 0;
        $usedNames = [];
        $stagedTemps = [];
        $closed = false;
        try {
            foreach ($entriesToZip as $item) {
                $cleanPath = $item['storagePath'];
                $declaredSize = $item['size'] ?? $filesystem->fileSize($cleanPath);
                if ($maxBytes > 0 && ($totalBytes + $declaredSize) > $maxBytes) {
                    throw new FileManagerException('Selected files exceed the maximum allowed ZIP archive size.', 'errors.zip_max_size');
                }

                $remainingBudget = $maxBytes > 0 ? max(1, $maxBytes - $totalBytes) : 262144000;
                $entryName = $this->deduplicateName($item['zipPath'], $usedNames);
                $staged = $this->stageFileToTemp($filesystem, $cleanPath, $remainingBudget);
                $entryTemp = $staged['path'];
                $stagedTemps[] = $entryTemp;
                $totalBytes += $staged['bytes'];
                if ($maxBytes > 0 && $totalBytes > $maxBytes) {
                    throw new FileManagerException('Selected files exceed the maximum allowed ZIP archive size.', 'errors.zip_max_size');
                }
                if (!$zip->addFile($entryTemp, $entryName)) {
                    throw new FileManagerException('Unable to add file to ZIP archive.', 'errors.zip_create_failed');
                }
                ++$addedCount;
            }
            $closedOk = $zip->close();
            $closed = true;
            if (!$closedOk) {
                throw new FileManagerException('Unable to finalize temporary ZIP archive.', 'errors.zip_create_failed');
            }
        } catch (\Throwable $e) {
            if (!$closed) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                    // Ignore secondary close errors during cleanup.
                }
            }
            @unlink($tempFile);
            throw $e;
        } finally {
            foreach ($stagedTemps as $staged) {
                @unlink($staged);
            }
        }

        if ($addedCount === 0) {
            @unlink($tempFile);
            throw new FileManagerException('No valid files found to include in ZIP archive.', 'errors.zip_no_files');
        }

        return $tempFile;
    }

    /**
     * @return array{path: string, bytes: int}
     */
    private function stageFileToTemp(FilesystemOperator $filesystem, string $cleanPath, int $maxBytes): array
    {
        $entryTemp = tempnam(sys_get_temp_dir(), 'liveux_fm_zipitem_');
        if ($entryTemp === false) {
            throw new FileManagerException('Unable to allocate temporary file for ZIP archive.', 'errors.zip_create_failed');
        }

        $in = $filesystem->readStream($cleanPath);
        $out = fopen($entryTemp, 'wb');
        if (!is_resource($in) || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            @unlink($entryTemp);
            throw new FileManagerException('Unable to read file for ZIP archive.', 'errors.zip_create_failed');
        }

        $copied = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new FileManagerException('Unable to read file stream for ZIP archive.', 'errors.zip_create_failed');
                }
                if ($chunk === '') {
                    if (feof($in)) {
                        break;
                    }
                    throw new FileManagerException('Unexpected empty read while building ZIP archive.', 'errors.zip_create_failed');
                }
                $copied += strlen($chunk);
                if ($maxBytes > 0 && $copied > $maxBytes) {
                    throw new FileManagerException('Selected files exceed the maximum allowed ZIP archive size.', 'errors.zip_max_size');
                }
                $written = fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new FileManagerException('Unable to write temporary ZIP archive.', 'errors.zip_create_failed');
                }
            }
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($entryTemp);
            throw $e;
        }

        fclose($in);
        fclose($out);

        return ['path' => $entryTemp, 'bytes' => $copied];
    }

    private const int MAX_SCAN_ITERATIONS = 50000;

    /**
     * Expands selected files and folders (recursively) into validated ZIP entries, honoring ignore rules,
     * safe filename rules, per-child voter permissions, and max_files.
     *
     * @param list<string> $paths
     *
     * @return list<array{storagePath: string, zipPath: string, size: ?int}>
     */
    private function expandPathsForZip(
        FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $volumeName,
        array $paths,
        int $maxFiles,
    ): array {
        $entries = [];
        $seenStorage = [];
        $iterations = 0;

        foreach ($paths as $rawPath) {
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            if ($cleanPath === '') {
                continue;
            }

            if ($filesystem->fileExists($cleanPath)) {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volumeName);
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD_ZIP, $cleanPath, $volumeName);
                if (isset($seenStorage[$cleanPath])) {
                    continue;
                }
                $seenStorage[$cleanPath] = true;
                $entries[] = [
                    'storagePath' => $cleanPath,
                    'zipPath' => $this->sanitizeZipSegment(PathNormalizer::basenameUtf8($cleanPath)),
                    'size' => null,
                ];
                if (count($entries) > $maxFiles) {
                    throw new FileManagerException(
                        sprintf('Maximum %d files can be zipped at once.', $maxFiles),
                        'errors.zip_max_files',
                        ['%max%' => $maxFiles],
                    );
                }
                continue;
            }

            if (!$filesystem->directoryExists($cleanPath)) {
                continue;
            }

            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volumeName);
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD_ZIP, $cleanPath, $volumeName);

            $rootSeg = $this->sanitizeZipSegment(PathNormalizer::basenameUtf8($cleanPath));
            foreach ($filesystem->listContents($cleanPath, true) as $attr) {
                if (++$iterations > self::MAX_SCAN_ITERATIONS) {
                    break 2;
                }
                if (!$attr instanceof FileAttributes) {
                    continue;
                }

                try {
                    $childPath = $this->pathNormalizer->normalize($attr->path());
                    $this->fileSecurityGuard->assertAccessiblePath($childPath, $profile);
                    $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($childPath));
                } catch (FileManagerException) {
                    continue;
                }

                if (
                    !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $childPath, $volumeName)
                    || !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD_ZIP, $childPath, $volumeName)
                ) {
                    continue;
                }

                if (isset($seenStorage[$childPath])) {
                    continue;
                }

                $rel = ltrim(substr($childPath, strlen($cleanPath)), '/');
                if ($rel === '') {
                    continue;
                }

                $safeParts = array_map($this->sanitizeZipSegment(...), explode('/', $rel));
                $seenStorage[$childPath] = true;
                $entries[] = [
                    'storagePath' => $childPath,
                    'zipPath' => $rootSeg . '/' . implode('/', $safeParts),
                    'size' => $attr->fileSize(),
                ];

                if (count($entries) > $maxFiles) {
                    throw new FileManagerException(
                        sprintf('Maximum %d files can be zipped at once.', $maxFiles),
                        'errors.zip_max_files',
                        ['%max%' => $maxFiles],
                    );
                }
            }
        }

        return $entries;
    }

    private function sanitizeZipSegment(string $segment): string
    {
        $nfc = UnicodeHelper::toNfc($segment);
        $clean = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\/\\\\]+/u', '_', $nfc) ?? 'file';
        $clean = UnicodeHelper::trimEdgeInvisiblesAndDots(ltrim($clean, ".\u{FF0E}\u{3002}\u{FF61}\u{FE52}\u{2024}"));

        return $clean !== '' ? $clean : 'file';
    }

    /**
     * Hardened opt-in ZIP extraction with 7-layer security defense:
     * 1. Opt-in profile toggle + ext-zip check + EXTRACT_ZIP voter check.
     * 2. Entry count cap.
     * 3. Unix symlink rejection.
     * 4. Zip-Slip path normalization & boundary check.
     * 5. CVE-2026-81891 / RCE guard: FileSecurityGuard + magic-bytes MIME + SVG sanitization.
     * 6. Zip-Bomb defense: streamed byte cap + compression ratio cap.
     * 7. Storage quota enforcement + cache invalidation events.
     *
     * @return array{extracted: int, skipped: int, targetDir: string}
     */
    public function extractArchive(
        ProfileConfig $profile,
        string $archivePath,
        ?string $targetDir = null,
        ?string $volumeName = null,
    ): array {
        if (!$this->isExtractAvailableForProfile($profile)) {
            throw new FileManagerException('ZIP extraction is disabled or PHP ext-zip is not available on this server.', 'errors.zip_extract_disabled');
        }

        $cleanArchive = $this->pathNormalizer->normalize($archivePath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanArchive, $profile);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $this->assertExtractGranted($profile, $cleanArchive, $volume->name);

        if ($cleanArchive === '' || PathNormalizer::extensionUtf8($cleanArchive) !== 'zip') {
            throw new FileManagerException('Only .zip archives can be extracted.', 'errors.zip_invalid_archive');
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        if (!$filesystem->fileExists($cleanArchive)) {
            throw new FileManagerException('Archive file not found.', 'errors.file_not_found');
        }

        $destBase = $this->resolveExtractDestination($cleanArchive, $targetDir, $profile);
        $this->assertExtractGranted($profile, $destBase, $volume->name);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $destBase, $volume->name);
        $this->assertNoAncestorFileCollision($filesystem, $destBase);
        $this->assertArchiveSubdirCreationAllowed($filesystem, $profile, $destBase, $volume->name);
        $maxExtractTotal = ByteSizeParser::parse((string) ($profile->zip['max_extract_size'] ?? '100M'));
        $tempZip = $this->copyArchiveToTemp($filesystem, $cleanArchive, $maxExtractTotal > 0 ? $maxExtractTotal : 104857600);

        $zip = new ZipArchive();
        if ($zip->open($tempZip, ZipArchive::RDONLY) !== true) {
            @unlink($tempZip);
            throw new FileManagerException('Unable to open ZIP archive.', 'errors.zip_invalid_archive');
        }

        $extracted = 0;
        $createdDirs = 0;
        /** @var list<FileUploadedEvent> $pendingUploadEvents */
        $pendingUploadEvents = [];
        try {
            $maxFiles = max(1, (int) ($profile->zip['max_extract_files'] ?? 200));
            if ($zip->numFiles > $maxFiles) {
                throw new FileManagerException(
                    sprintf('Archive contains too many entries (maximum %d allowed).', $maxFiles),
                    'errors.zip_extract_too_many',
                    ['%max%' => $maxFiles],
                );
            }

            // Fail closed on volumes whose quota scan hit MAX_SCANNED_ITEMS (`capped: true`)
            // and serialize the extraction under the per-volume quota lock to prevent parallel TOCTOU bypasses.
            $extractUnderLock = function () use (
                $zip,
                $profile,
                $volume,
                $filesystem,
                $destBase,
                $maxExtractTotal,
                &$extracted,
                &$createdDirs,
                &$pendingUploadEvents,
            ): array {
                $maxTotalBytes = $maxExtractTotal;
                $maxSingleFileBytes = ByteSizeParser::parse($profile->uploadMaxSize);
                $maxRatio = max(1, (int) ($profile->zip['max_compression_ratio'] ?? 100));

                $this->quotaChecker?->assertHasQuota($profile, $volume->name, 0);
                $quotaSummary = $this->quotaChecker?->getQuotaSummary($profile, $volume->name);
                $quotaEnabled = ($quotaSummary['enabled'] ?? false) === true;
                $initialUsedBytes = (int) ($quotaSummary['usedBytes'] ?? 0);
                $maxQuotaBytes = (int) ($quotaSummary['maxBytes'] ?? 0);

                $skipped = 0;
                $cumulativeBytes = 0;
                $cumulativeFinalBytes = 0;
                $cumulativeNetDelta = 0;
                $seenArchiveHashes = [];
                $dupCheckEnabled = $this->duplicateDetector !== null
                    && $this->duplicateDetector->isEnabled($profile)
                    && $this->duplicateDetector->getPolicy($profile) === 'reject';
                $volumeSizeIndex = $dupCheckEnabled ? $this->duplicateDetector->snapshotSizeIndex($profile, $volume->name) : [];
                $volumePathHashes = [];

                try {
                    for ($i = 0; $i < $zip->numFiles; ++$i) {
                        $stat = $zip->statIndex($i);
                        if (!is_array($stat)) {
                            ++$skipped;
                            continue;
                        }

                        $rawEntry = $stat['name'];
                        if ($this->isJunkArchiveEntry($rawEntry)) {
                            ++$skipped;
                            continue;
                        }

                        $this->assertNotSymlink($zip, $i);

                        if (str_ends_with($rawEntry, '/') || str_ends_with($rawEntry, '\\')) {
                            $cleanRelDir = $this->normalizeAndValidateArchivePath(rtrim($rawEntry, '/\\'), $profile, true);
                            if ($cleanRelDir !== '') {
                                $dirTarget = $this->pathNormalizer->join($destBase, $cleanRelDir);
                                $this->fileSecurityGuard->assertAccessiblePath($dirTarget, $profile);
                                $this->assertExtractGranted($profile, $dirTarget, $volume->name);
                                $this->assertNoAncestorFileCollision($filesystem, $dirTarget);
                                $this->assertArchiveSubdirCreationAllowed($filesystem, $profile, $dirTarget, $volume->name);
                                if ($volume->isLocalFilesystem()) {
                                    $filesystem->createDirectory($dirTarget);
                                } else {
                                    try {
                                        $filesystem->createDirectory($dirTarget);
                                    } catch (FilesystemException) {
                                        // Best-effort directory marker on MIME-restricted cloud buckets.
                                    }
                                }
                                ++$createdDirs;
                            }
                            continue;
                        }

                        $cleanRelFile = $this->normalizeAndValidateArchivePath($rawEntry, $profile, false);
                        $rawDestPath = $this->pathNormalizer->join($destBase, $cleanRelFile);
                        $this->fileSecurityGuard->assertAccessiblePath($rawDestPath, $profile);
                        $fileParentDir = PathNormalizer::dirnameUtf8($rawDestPath);
                        if ($fileParentDir !== $destBase) {
                            $this->assertExtractGranted($profile, $fileParentDir, $volume->name);
                            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $fileParentDir, $volume->name);
                            $this->assertNoAncestorFileCollision($filesystem, $fileParentDir);
                            $this->assertArchiveSubdirCreationAllowed($filesystem, $profile, $fileParentDir, $volume->name);
                        }
                        $destPath = $this->resolveExtractFileCollision($filesystem, $rawDestPath, $profile->overwritePolicy);
                        $this->fileSecurityGuard->assertAccessiblePath($destPath, $profile);
                        $isOverwriting = $profile->overwritePolicy === 'overwrite' && $filesystem->fileExists($destPath);
                        if ($isOverwriting) {
                            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $destPath, $volume->name);
                            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, $destPath, $volume->name);
                            $this->usageGuard?->assertAllowed($profile, $volume, $destPath, false, 'overwrite');
                        }

                        $compSize = $stat['comp_size'];
                        [$entryTemp] = $this->streamEntryWithBombGuard(
                            $zip,
                            $i,
                            $compSize,
                            $maxRatio,
                            $maxSingleFileBytes,
                            $maxTotalBytes,
                            $cumulativeBytes,
                        );

                        try {
                            $preOptDestPath = $destPath;
                            $entryBase = PathNormalizer::basenameUtf8($destPath);
                            [$mime, $postOptDestPath, $entryHash] = $this->validateOptimizeAndConstrainExtractedFile($entryTemp, $entryBase, $destPath, $profile, $volume->name, $seenArchiveHashes, $volumeSizeIndex, $volumePathHashes);
                            if ($postOptDestPath !== $preOptDestPath) {
                                $destPath = $this->resolveExtractFileCollision($filesystem, $postOptDestPath, $profile->overwritePolicy);
                                $this->fileSecurityGuard->assertAccessiblePath($destPath, $profile);
                                $isOverwriting = $profile->overwritePolicy === 'overwrite' && $filesystem->fileExists($destPath);
                                if ($isOverwriting) {
                                    $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $destPath, $volume->name);
                                    $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, $destPath, $volume->name);
                                    $this->usageGuard?->assertAllowed($profile, $volume, $destPath, false, 'overwrite');
                                }
                            } else {
                                $destPath = $postOptDestPath;
                            }
                            clearstatcache(true, $entryTemp);
                            $finalBytes = (int) filesize($entryTemp);
                            if ($maxSingleFileBytes > 0 && $finalBytes > $maxSingleFileBytes) {
                                $errName = PathNormalizer::basenameUtf8($destPath);
                                throw new UploadValidationException(
                                    sprintf('File "%s" exceeds maximum allowed size (%s).', $errName, $profile->uploadMaxSize),
                                    'errors.upload_too_large',
                                    ['%name%' => $errName, '%max%' => $profile->uploadMaxSize],
                                );
                            }
                            $cumulativeFinalBytes += $finalBytes;
                            if ($maxTotalBytes > 0 && $cumulativeFinalBytes > $maxTotalBytes) {
                                throw new FileManagerException('Extracted archive exceeds maximum allowed size.', 'errors.zip_extract_too_large');
                            }
                            $existingBytes = ($isOverwriting && $filesystem->fileExists($destPath)) ? $filesystem->fileSize($destPath) : 0;
                            $netDelta = $finalBytes - $existingBytes;
                            $cumulativeNetDelta += $netDelta;
                            if ($quotaEnabled && $maxQuotaBytes > 0 && $netDelta > 0 && ($initialUsedBytes + $cumulativeNetDelta) > $maxQuotaBytes) {
                                $limitLabel = $profile->storageQuota ?? (string) $maxQuotaBytes;
                                throw new UploadValidationException(
                                    sprintf('Storage quota exceeded (limit: %s).', $limitLabel),
                                    'errors.storage_quota_exceeded',
                                    ['%limit%' => $limitLabel],
                                );
                            }

                            $stream = fopen($entryTemp, 'rb');
                            if ($stream === false) {
                                throw new FileManagerException('Unable to read extracted entry.', 'errors.upload_stream_unreadable');
                            }
                            try {
                                $filesystem->writeStream($destPath, $stream);
                            } finally {
                                fclose($stream);
                            }

                            $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $destPath, $profile->basePath);
                            if ($dupCheckEnabled && $entryHash !== null) {
                                $seenArchiveHashes[$entryHash] = $destPath;
                                $volumeSizeIndex[$finalBytes][] = $destPath;
                                $volumePathHashes[$destPath] = $entryHash;
                            }
                            $pendingUploadEvents[] = new FileUploadedEvent($profile, $volume, $destPath, $canonical, $finalBytes, $mime, true);
                            ++$extracted;
                        } finally {
                            @unlink($entryTemp);
                        }
                    }

                    return [
                        'extracted' => $extracted,
                        'skipped' => $skipped,
                        'targetDir' => $destBase,
                    ];
                } finally {
                    if ($extracted > 0 || $createdDirs > 0) {
                        $this->quotaChecker?->invalidateQuotaCache($profile, $volume->name);
                    }
                }
            };

            return $this->quotaChecker !== null
                ? $this->quotaChecker->withQuotaLock($profile, $volume->name, $extractUnderLock)
                : $extractUnderLock();
        } finally {
            // Dispatch file/folder events AFTER releasing the exclusive quota lock so host listeners cannot block or deadlock the lock.
            if ($this->eventDispatcher !== null) {
                foreach ($pendingUploadEvents as $uploadEvent) {
                    $this->eventDispatcher->dispatch($uploadEvent);
                }
            }
            // Always invalidate quota/tree/directory/descendant caches if ANY file or directory was persisted before an error.
            if ($extracted > 0 || $createdDirs > 0) {
                $destCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $destBase, $profile->basePath);
                $this->eventDispatcher?->dispatch(new FolderCreatedEvent($profile, $volume, $destBase, $destCanonical, true));
            }
            $zip->close();
            @unlink($tempZip);
        }
    }

    private function resolveExtractFileCollision(FilesystemOperator $filesystem, string $candidate, string $policy): string
    {
        if ($filesystem->directoryExists($candidate)) {
            throw new FileManagerException(
                sprintf('Cannot overwrite existing folder "%s" with extracted file.', $candidate),
                'errors.destination_exists',
                ['%name%' => PathNormalizer::basenameUtf8($candidate)],
            );
        }

        if (!$filesystem->fileExists($candidate) || $policy === 'overwrite') {
            return $candidate;
        }

        $filename = PathNormalizer::basenameUtf8($candidate);
        if ($policy === 'reject') {
            throw new UploadValidationException(
                sprintf('File "%s" already exists (overwrite_policy: reject).', $filename),
                'errors.upload_file_exists',
                ['%name%' => $filename],
            );
        }

        $dir = PathNormalizer::dirnameUtf8($candidate);
        $ext = PathNormalizer::extensionUtf8($filename);
        $maxBaseBytes = max(1, min(200, 255 - ($ext !== '' ? strlen($ext) + 1 : 0) - 9));
        $base = UnicodeHelper::truncateStemBytes(PathNormalizer::stemUtf8($filename), $maxBaseBytes);
        if ($base === '') {
            $base = substr('file', 0, $maxBaseBytes);
        }
        $counter = 1;

        do {
            $nextName = $ext !== '' ? sprintf('%s-%d.%s', $base, $counter, $ext) : sprintf('%s-%d', $base, $counter);
            $next = $this->pathNormalizer->join($dir, $nextName);
            ++$counter;
        } while ($counter <= 100 && ($filesystem->fileExists($next) || $filesystem->directoryExists($next)));

        if ($filesystem->fileExists($next) || $filesystem->directoryExists($next)) {
            $suffix = bin2hex(random_bytes(4));
            $fallbackName = $ext !== '' ? sprintf('%s-%s.%s', $base, $suffix, $ext) : sprintf('%s-%s', $base, $suffix);
            $next = $this->pathNormalizer->join($dir, $fallbackName);
        }

        return $next;
    }

    private function assertNoAncestorFileCollision(FilesystemOperator $filesystem, string $dirPath): void
    {
        if ($dirPath === '') {
            return;
        }

        $cursor = $dirPath;
        while ($cursor !== '') {
            if ($filesystem->fileExists($cursor)) {
                throw new FileManagerException(
                    sprintf('Target "%s" already exists in destination.', PathNormalizer::basenameUtf8($cursor)),
                    'errors.target_exists',
                    ['%name%' => PathNormalizer::basenameUtf8($cursor)],
                );
            }
            $next = PathNormalizer::dirnameUtf8($cursor);
            if ($next === $cursor) {
                break;
            }
            $cursor = $next;
        }
    }

    private function assertExtractGranted(ProfileConfig $profile, string $cleanArchive, ?string $volumeName): void
    {
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanArchive, $volumeName);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::EXTRACT_ZIP, $cleanArchive, $volumeName);
    }

    private function assertArchiveSubdirCreationAllowed(
        FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $targetDir,
        string $volumeName,
    ): void {
        if ($targetDir === '' || $filesystem->directoryExists($targetDir)) {
            return;
        }

        $immediateParent = PathNormalizer::dirnameUtf8($targetDir);
        $existingAncestor = $immediateParent;
        while ($existingAncestor !== '' && !$filesystem->directoryExists($existingAncestor)) {
            $existingAncestor = PathNormalizer::dirnameUtf8($existingAncestor);
        }

        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::CREATE_FOLDER, $existingAncestor, $volumeName);
        if ($immediateParent !== $existingAncestor) {
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::CREATE_FOLDER, $immediateParent, $volumeName);
        }
    }

    private function resolveExtractDestination(string $cleanArchive, ?string $targetDir, ProfileConfig $profile): string
    {
        if ($targetDir !== null && trim($targetDir) !== '') {
            $cleanTarget = $this->pathNormalizer->normalize(UnicodeHelper::toNfc($targetDir));
            $this->fileSecurityGuard->assertAccessiblePath($cleanTarget, $profile);

            return $cleanTarget;
        }

        $parent = PathNormalizer::dirnameUtf8($cleanArchive);
        $nfcStem = UnicodeHelper::stripIgnorableFillers(UnicodeHelper::toNfc(PathNormalizer::stemUtf8($cleanArchive)));
        $stem = preg_replace('/[^\p{L}\p{M}\p{N}\x{200C}._-]+/u', '-', $nfcStem) ?? 'extracted';
        $stem = preg_replace('/(?<=^|-)[\p{M}]+/u', '', $stem) ?? $stem;
        $stem = UnicodeHelper::trimEdgeInvisiblesAndDots(trim($stem, '.-'));
        $stem = UnicodeHelper::truncateStemBytes($stem, 200);
        if ($stem === '' || UnicodeHelper::visibleSkeleton($stem) === '') {
            $stem = 'extracted';
        }
        try {
            $this->fileSecurityGuard->assertSafeFolderName($stem);
        } catch (FileManagerException) {
            $stem = 'extracted-' . substr(hash('sha256', $cleanArchive), 0, 8);
            $this->fileSecurityGuard->assertSafeFolderName($stem);
        }

        $dest = $this->pathNormalizer->join($parent, $stem);
        $this->fileSecurityGuard->assertAccessiblePath($dest, $profile);

        return $dest;
    }

    private function copyArchiveToTemp(FilesystemOperator $filesystem, string $cleanArchive, int $maxArchiveBytes): string
    {
        $tempZip = tempnam(sys_get_temp_dir(), 'liveux_fm_unzip_');
        if ($tempZip === false) {
            throw new FileManagerException('Unable to allocate temporary file.', 'errors.temp_file_failed');
        }

        $in = $filesystem->readStream($cleanArchive);
        $out = fopen($tempZip, 'wb');
        if (!is_resource($in) || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            @unlink($tempZip);
            throw new FileManagerException('Unable to read ZIP archive.', 'errors.zip_invalid_archive');
        }

        $copied = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new FileManagerException('Unable to read file stream for ZIP archive.', 'errors.zip_create_failed');
                }
                if ($chunk === '') {
                    if (feof($in)) {
                        break;
                    }
                    throw new FileManagerException('Unexpected empty read while building ZIP archive.', 'errors.zip_create_failed');
                }
                $copied += strlen($chunk);
                if ($maxArchiveBytes > 0 && $copied > $maxArchiveBytes) {
                    throw new UploadValidationException('Archive file exceeds maximum allowed extraction size.', 'errors.zip_bomb_detected');
                }
                $written = fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new FileManagerException('Unable to write temporary ZIP archive.', 'errors.zip_create_failed');
                }
            }
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($tempZip);
            throw $e;
        }

        fclose($in);
        fclose($out);

        return $tempZip;
    }

    private function isJunkArchiveEntry(string $rawEntry): bool
    {
        $norm = ltrim(str_replace('\\', '/', $rawEntry), '/');
        if ($norm === '' || str_starts_with($norm, '__MACOSX/') || str_contains($norm, '/__MACOSX/')) {
            return true;
        }

        $base = PathNormalizer::basenameUtf8($norm);

        return $base === '.DS_Store' || $base === 'Thumbs.db' || str_starts_with($base, '._');
    }

    private function assertNotSymlink(ZipArchive $zip, int $index): void
    {
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            $unixMode = ($attr >> 16) & 0xF000;
            // 3 = UNIX, 19 = macOS / Darwin (PKWARE APPNOTE)
            $isUnixLike = $opsys === ZipArchive::OPSYS_UNIX || $opsys === 19;
            if ($isUnixLike && $unixMode === 0xA000) {
                throw new UploadValidationException('Symbolic links inside ZIP archives are blocked for security reasons.', 'errors.zip_symlink_blocked');
            }
        }
    }

    private function normalizeAndValidateArchivePath(string $rawEntry, ProfileConfig $profile, bool $isDirectory = false): string
    {
        if (str_starts_with($rawEntry, '/') || str_starts_with($rawEntry, '\\')) {
            throw new UploadValidationException('Absolute paths inside ZIP archives are blocked.', 'errors.path_traversal');
        }

        $cleanRel = $this->pathNormalizer->normalize(UnicodeHelper::toNfc($rawEntry));
        if ($cleanRel === '') {
            return '';
        }

        $this->fileSecurityGuard->assertAccessiblePath($cleanRel, $profile);
        $parts = array_values(array_filter(explode('/', $cleanRel), static fn (string $s): bool => $s !== ''));
        $lastIdx = count($parts) - 1;
        foreach ($parts as $idx => $segment) {
            if ($isDirectory || $idx < $lastIdx) {
                $this->fileSecurityGuard->assertSafeFolderName($segment);
            } else {
                $this->fileSecurityGuard->assertSafeFilename($segment);
            }
        }

        return $cleanRel;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function streamEntryWithBombGuard(
        ZipArchive $zip,
        int $index,
        int $compressedSize,
        int $maxRatio,
        int $maxSingleBytes,
        int $maxTotalBytes,
        int &$cumulativeBytes,
    ): array {
        $entryStream = $zip->getStreamIndex($index);
        if (!is_resource($entryStream)) {
            throw new FileManagerException('Unable to read entry from ZIP archive.', 'errors.zip_invalid_archive');
        }

        $entryTemp = tempnam(sys_get_temp_dir(), 'liveux_fm_entry_');
        if ($entryTemp === false) {
            fclose($entryStream);
            throw new FileManagerException('Unable to allocate temporary file.', 'errors.temp_file_failed');
        }

        $out = fopen($entryTemp, 'wb');
        if ($out === false) {
            fclose($entryStream);
            @unlink($entryTemp);
            throw new FileManagerException('Unable to allocate temporary file.', 'errors.temp_file_failed');
        }

        $entryBytes = 0;
        try {
            while (!feof($entryStream)) {
                $chunk = fread($entryStream, 8192);
                if ($chunk === false) {
                    throw new FileManagerException('Unable to read ZIP entry stream.', 'errors.zip_invalid_archive');
                }
                if ($chunk === '') {
                    if (feof($entryStream)) {
                        break;
                    }
                    throw new FileManagerException('Unexpected empty read from ZIP entry stream.', 'errors.zip_invalid_archive');
                }
                $len = strlen($chunk);
                $entryBytes += $len;
                $cumulativeBytes += $len;

                if ($maxSingleBytes > 0 && $entryBytes > $maxSingleBytes) {
                    throw new UploadValidationException('Archive entry exceeds maximum single-file upload limit.', 'errors.zip_bomb_detected');
                }
                if ($maxTotalBytes > 0 && $cumulativeBytes > $maxTotalBytes) {
                    throw new UploadValidationException('Extracted archive exceeds maximum allowed uncompressed size.', 'errors.zip_bomb_detected');
                }
                if ($entryBytes > 10240) {
                    if ($compressedSize <= 0 && $entryBytes > 102400) {
                        throw new UploadValidationException('Suspicious compression ratio detected (possible ZIP bomb).', 'errors.zip_bomb_detected');
                    }
                    if ($compressedSize > 0 && ($entryBytes / $compressedSize) > $maxRatio) {
                        throw new UploadValidationException('Suspicious compression ratio detected (possible ZIP bomb).', 'errors.zip_bomb_detected');
                    }
                }

                $written = fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new FileManagerException('Unable to write temporary ZIP archive.', 'errors.zip_create_failed');
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            fclose($entryStream);
            @unlink($entryTemp);
            throw $e;
        }

        fclose($out);
        fclose($entryStream);

        return [$entryTemp, $entryBytes];
    }

    /**
     * @param array<string, string>    $seenArchiveHashes
     * @param array<int, list<string>> $volumeSizeIndex
     * @param array<string, string>    $volumePathHashes
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function validateOptimizeAndConstrainExtractedFile(
        string $entryTemp,
        string $filename,
        string $destPath,
        ProfileConfig $profile,
        string $volumeName,
        array &$seenArchiveHashes = [],
        array $volumeSizeIndex = [],
        array &$volumePathHashes = [],
    ): array {
        $this->fileSecurityGuard->assertSafeFilename($filename);

        $mimeTypes = MimeTypes::getDefault();
        $ext = PathNormalizer::extensionUtf8($filename);
        $detected = $mimeTypes->guessMimeType($entryTemp);
        if ($detected === null || $detected === '') {
            $detected = $mimeTypes->getMimeTypes($ext)[0] ?? 'application/octet-stream';
        }

        $normalizedMime = strtolower(trim(explode(';', $detected, 2)[0]));
        if (
            $normalizedMime === 'image/svg'
            || ($ext === 'svg' && in_array($normalizedMime, ['text/plain', 'text/xml', 'application/xml', 'application/octet-stream'], true))
        ) {
            $normalizedMime = 'image/svg+xml';
        } elseif (in_array($normalizedMime, ['text/plain', 'text/xml', 'application/xml'], true) && FileSecurityGuard::looksLikeSvgDocument($entryTemp)) {
            $normalizedMime = 'image/svg+xml';
        }
        $this->fileSecurityGuard->assertMimeMatchesExtension($filename, $normalizedMime);

        if ($this->fileSecurityGuard->matchesMimeList($normalizedMime, $profile->uploadDeny)) {
            throw new UploadValidationException(
                sprintf('MIME type "%s" inside archive is denied by security policy.', $normalizedMime),
                'errors.upload_mime_denied',
                ['%mime%' => $normalizedMime],
            );
        }

        if ($profile->uploadAllow !== [] && !$this->fileSecurityGuard->matchesMimeList($normalizedMime, $profile->uploadAllow)) {
            throw new UploadValidationException(
                sprintf('MIME type "%s" inside archive is not allowed.', $normalizedMime),
                'errors.upload_mime_not_allowed',
                ['%mime%' => $normalizedMime],
            );
        }

        $ext = PathNormalizer::extensionUtf8($filename);
        if ($ext === 'svg' || $normalizedMime === 'image/svg+xml') {
            clearstatcache(true, $entryTemp);
            $svgSize = @filesize($entryTemp);
            if ($svgSize === false || $svgSize > SvgSanitizer::MAX_SVG_BYTES) {
                throw new UploadValidationException('Invalid or unsafe SVG inside ZIP archive rejected.', 'errors.svg_invalid');
            }
            $rawSvg = file_get_contents($entryTemp);
            if (!is_string($rawSvg) || trim($rawSvg) === '') {
                throw new UploadValidationException('Invalid or unsafe SVG inside ZIP archive rejected.', 'errors.svg_invalid');
            }
            if (($profile->security['sanitize_svg'] ?? true) === true) {
                $cleanSvg = $this->svgSanitizer->sanitize($rawSvg);
                if ($cleanSvg === '') {
                    throw new UploadValidationException('Invalid or unsafe SVG inside ZIP archive rejected.', 'errors.svg_invalid');
                }
                $written = @file_put_contents($entryTemp, $cleanSvg);
                if ($written === false || $written !== strlen($cleanSvg)) {
                    throw new UploadValidationException('Unable to persist sanitized SVG entry.', 'errors.upload_failed');
                }
                clearstatcache(true, $entryTemp);
            } elseif (str_contains($rawSvg, "\0") || !mb_check_encoding($rawSvg, 'UTF-8') || preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $rawSvg) === 1) {
                throw new UploadValidationException('Invalid or unsafe SVG inside ZIP archive rejected.', 'errors.svg_invalid');
            }
        } else {
            $opt = $this->imageOptimizer->optimizeIfEnabled($entryTemp, $filename, $normalizedMime, $profile);
            $normalizedMime = strtolower(trim(explode(';', $opt['mime'], 2)[0]));
            if ($this->fileSecurityGuard->matchesMimeList($normalizedMime, $profile->uploadDeny)) {
                throw new UploadValidationException(
                    sprintf('MIME type "%s" inside archive is denied by security policy.', $normalizedMime),
                    'errors.upload_mime_denied',
                    ['%mime%' => $normalizedMime],
                );
            }
            if ($profile->uploadAllow !== [] && !$this->fileSecurityGuard->matchesMimeList($normalizedMime, $profile->uploadAllow)) {
                throw new UploadValidationException(
                    sprintf('MIME type "%s" inside archive is not allowed.', $normalizedMime),
                    'errors.upload_mime_not_allowed',
                    ['%mime%' => $normalizedMime],
                );
            }
            if ($opt['filename'] !== $filename) {
                $this->fileSecurityGuard->assertSafeFilename($opt['filename']);
                $destPath = $this->pathNormalizer->join(PathNormalizer::dirnameUtf8($destPath), $opt['filename']);
                $this->fileSecurityGuard->assertAccessiblePath($destPath, $profile);
            }
            $this->assertExtractedImageConstraints($profile, $entryTemp, $normalizedMime, $opt['filename']);
        }

        $entryHash = null;
        if ($this->duplicateDetector !== null && $this->duplicateDetector->isEnabled($profile) && $this->duplicateDetector->getPolicy($profile) === 'reject') {
            $entryHash = $this->duplicateDetector->computeFileHash($entryTemp);
            if ($entryHash !== null && isset($seenArchiveHashes[$entryHash])) {
                $existingInZip = $seenArchiveHashes[$entryHash];
                throw new UploadValidationException(
                    sprintf('Identical file already exists at "/%s".', $existingInZip),
                    'errors.duplicate_file_detected',
                    ['%existing%' => '/' . $existingInZip],
                );
            }
            $dup = $this->duplicateDetector->findDuplicateInSizeIndex(
                $profile,
                $volumeName,
                $entryTemp,
                $volumeSizeIndex,
                $volumePathHashes,
                fn (string $cand): bool => $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cand, $volumeName),
            );
            if ($dup !== null) {
                throw new UploadValidationException(
                    sprintf('Identical file already exists at "/%s".', $dup),
                    'errors.duplicate_file_detected',
                    ['%existing%' => '/' . $dup],
                );
            }
            if ($entryHash !== null) {
                $seenArchiveHashes[$entryHash] = $destPath;
            }
        }

        return [$normalizedMime, $destPath, $entryHash];
    }

    private function assertExtractedImageConstraints(ProfileConfig $profile, string $localPath, string $mime, string $name): void
    {
        $constraints = $profile->imageConstraints;
        if ($constraints === [] || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp'], true)) {
            return;
        }

        $minW = is_int($constraints['min_width'] ?? null) && $constraints['min_width'] > 0 ? $constraints['min_width'] : null;
        $minH = is_int($constraints['min_height'] ?? null) && $constraints['min_height'] > 0 ? $constraints['min_height'] : null;
        $maxW = is_int($constraints['max_width'] ?? null) && $constraints['max_width'] > 0 ? $constraints['max_width'] : null;
        $maxH = is_int($constraints['max_height'] ?? null) && $constraints['max_height'] > 0 ? $constraints['max_height'] : null;
        $ratioLabel = is_string($constraints['aspect_ratio'] ?? null) ? trim($constraints['aspect_ratio']) : '';
        if ($minW === null && $minH === null && $maxW === null && $maxH === null && $ratioLabel === '') {
            return;
        }

        $info = @getimagesize($localPath);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            throw new UploadValidationException('Unable to read extracted image dimensions.', 'errors.edited_image_invalid');
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        if (($minW !== null && $w < $minW) || ($minH !== null && $h < $minH)) {
            throw new UploadValidationException(
                sprintf('Image "%s" (%dx%dpx) is smaller than the minimum required.', $name, $w, $h),
                'errors.image_too_small',
                ['%name%' => $name, '%width%' => $w, '%height%' => $h, '%minWidth%' => $minW ?? 0, '%minHeight%' => $minH ?? 0],
            );
        }
        if (($maxW !== null && $w > $maxW) || ($maxH !== null && $h > $maxH)) {
            throw new UploadValidationException(
                sprintf('Image "%s" (%dx%dpx) exceeds maximum allowed dimensions.', $name, $w, $h),
                'errors.image_too_large_dim',
                ['%name%' => $name, '%width%' => $w, '%height%' => $h, '%maxWidth%' => $maxW ?? '∞', '%maxHeight%' => $maxH ?? '∞'],
            );
        }
        if ($ratioLabel !== '' && preg_match('/^(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)$/', $ratioLabel, $m) === 1) {
            $rw = (float) $m[1];
            $rh = (float) $m[2];
            if ($rw > 0.0 && $rh > 0.0) {
                $expected = $rw / $rh;
                $tol = is_numeric($constraints['aspect_ratio_tolerance'] ?? null) ? max(0.0, min(0.5, (float) $constraints['aspect_ratio_tolerance'])) : 0.05;
                if ((abs(($w / $h) - $expected) / $expected) > $tol) {
                    throw new UploadValidationException(
                        sprintf('Image "%s" (%dx%dpx) does not match required aspect ratio (%s).', $name, $w, $h, $ratioLabel),
                        'errors.image_aspect_ratio',
                        ['%name%' => $name, '%width%' => $w, '%height%' => $h, '%expected%' => $ratioLabel],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, int> $usedNames
     */
    private function deduplicateName(string $name, array &$usedNames): string
    {
        $key = mb_strtolower($name, 'UTF-8');
        if (!isset($usedNames[$key])) {
            $usedNames[$key] = 1;

            return $name;
        }

        $dir = PathNormalizer::dirnameUtf8($name);
        $base = PathNormalizer::stemUtf8($name);
        $ext = PathNormalizer::extensionUtf8($name);
        $index = $usedNames[$key];

        do {
            ++$index;
            $candidateLeaf = $ext !== '' ? sprintf('%s-%d.%s', $base, $index, $ext) : sprintf('%s-%d', $base, $index);
            $candidate = $dir !== '' ? $dir . '/' . $candidateLeaf : $candidateLeaf;
            $candidateKey = mb_strtolower($candidate, 'UTF-8');
        } while (isset($usedNames[$candidateKey]));

        $usedNames[$key] = $index;
        $usedNames[$candidateKey] = 1;

        return $candidate;
    }
}
