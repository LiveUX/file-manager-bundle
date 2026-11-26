<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Throwable;

/**
 * SHA-256 content-based duplicate file detector with cached volume hash index.
 * Uses streaming hash_update_stream() so large files never bloat PHP memory.
 */
final readonly class DuplicateDetector
{
    private const int MAX_HASHABLE_FILE_BYTES = 104_857_600; // 100 MB per-file stream cap

    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private DirectoryCacheManager $cacheManager,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
    ) {
    }

    public function isEnabled(ProfileConfig $profile): bool
    {
        return ($profile->duplicateDetection['enabled'] ?? false) === true;
    }

    public function getPolicy(ProfileConfig $profile): string
    {
        $policy = (string) ($profile->duplicateDetection['policy'] ?? 'warn');

        return in_array($policy, ['warn', 'reject', 'reuse'], true) ? $policy : 'warn';
    }

    public function computeFileHash(string $localFilePath): ?string
    {
        if (!is_file($localFilePath) || !is_readable($localFilePath)) {
            return null;
        }

        $hash = @hash_file('sha256', $localFilePath);

        return is_string($hash) ? $hash : null;
    }

    /**
     * Checks whether a file with identical SHA-256 content already exists in the volume.
     *
     * @param (callable(string): bool)|null $visibilityFilter
     */
    public function findDuplicate(
        ProfileConfig $profile,
        string $volumeName,
        string $localTempPath,
        ?callable $visibilityFilter = null,
    ): ?string {
        if (!is_file($localTempPath) || !is_readable($localTempPath)) {
            return null;
        }

        clearstatcache(true, $localTempPath);
        $targetSize = (int) @filesize($localTempPath);
        if ($targetSize <= 0 || $targetSize > self::MAX_HASHABLE_FILE_BYTES) {
            return null;
        }

        $sizeIndex = $this->getVolumeSizeIndex($profile, $volumeName);
        $sameSizePaths = $sizeIndex[$targetSize] ?? [];
        if ($sameSizePaths === []) {
            return null;
        }

        $hash = $this->computeFileHash($localTempPath);
        if ($hash === null) {
            return null;
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        $pathHashMemo = [];

        return $this->matchCandidateHash($filesystem, $sameSizePaths, $hash, $pathHashMemo, $visibilityFilter);
    }

    private const int MAX_CANDIDATES_PER_UPLOAD = 25;

    /**
     * @param list<string>             $sameSizePaths
     * @param array<string, string>    $pathHashMemo
     * @param (callable(string): bool)|null $visibilityFilter
     */
    private function matchCandidateHash(
        FilesystemOperator $filesystem,
        array $sameSizePaths,
        string $targetHash,
        array &$pathHashMemo,
        ?callable $visibilityFilter = null,
    ): ?string {
        $hashedCount = 0;
        foreach ($sameSizePaths as $candidatePath) {
            try {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($candidatePath));
            } catch (Throwable) {
                continue;
            }
            if ($visibilityFilter !== null && !$visibilityFilter($candidatePath)) {
                continue;
            }
            $candHash = $pathHashMemo[$candidatePath] ?? null;
            if ($candHash === null) {
                if ($hashedCount >= self::MAX_CANDIDATES_PER_UPLOAD) {
                    break;
                }
                ++$hashedCount;
                $candHash = $this->hashFlysystemFile($filesystem, $candidatePath);
                if ($candHash !== null) {
                    $pathHashMemo[$candidatePath] = $candHash;
                }
            }
            if ($candHash === $targetHash) {
                return $candidatePath;
            }
        }

        return null;
    }

    /**
     * @param array<int, list<string>> $sizeIndex
     * @param array<string, string>    $pathHashMemo
     * @param (callable(string): bool)|null $visibilityFilter
     */
    public function findDuplicateInSizeIndex(
        ProfileConfig $profile,
        string $volumeName,
        string $localTempPath,
        array $sizeIndex,
        array &$pathHashMemo = [],
        ?callable $visibilityFilter = null,
    ): ?string {
        if (!is_file($localTempPath) || !is_readable($localTempPath)) {
            return null;
        }

        clearstatcache(true, $localTempPath);
        $targetSize = (int) @filesize($localTempPath);
        if ($targetSize <= 0 || $targetSize > self::MAX_HASHABLE_FILE_BYTES) {
            return null;
        }

        $sameSizePaths = $sizeIndex[$targetSize] ?? [];
        if ($sameSizePaths === []) {
            return null;
        }

        $hash = $this->computeFileHash($localTempPath);
        if ($hash === null) {
            return null;
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);

        return $this->matchCandidateHash($filesystem, $sameSizePaths, $hash, $pathHashMemo, $visibilityFilter);
    }

    /**
     * @return array<int, list<string>>
     */
    public function snapshotSizeIndex(ProfileConfig $profile, string $volumeName): array
    {
        return $this->getVolumeSizeIndex($profile, $volumeName);
    }

    /**
     * @return array<string, list<array{path: string, size: int}>>
     */
    public function findAllDuplicates(ProfileConfig $profile, string $volumeName, int $minSizeBytes = 0): array
    {
        $index = $this->getVolumeHashIndex($profile, $volumeName);
        $duplicates = [];

        foreach ($index as $hash => $entries) {
            if (count($entries) < 2) {
                continue;
            }
            if ($minSizeBytes > 0 && $entries[0]['size'] < $minSizeBytes) {
                continue;
            }
            $duplicates[$hash] = $entries;
        }

        return $duplicates;
    }

    /**
     * @return array<int, list<string>>
     */
    private function getVolumeSizeIndex(ProfileConfig $profile, string $volumeName): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, '__sha256__', $profile->basePath);
        $volumeScope = $this->cacheManager->resolveVolumeScopeForRoot($this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath));

        $cached = $this->cacheManager->remember(
            $profile,
            $canonicalKey,
            fn (): array => ['sizes' => $this->scanVolumeBySize($profile, $volume->name)],
            $volume->cacheTtl,
            $volumeScope,
        );

        return is_array($cached['data']['sizes'] ?? null) ? $cached['data']['sizes'] : [];
    }

    /**
     * @return array<string, list<array{path: string, size: int}>>
     */
    private function getVolumeHashIndex(ProfileConfig $profile, string $volumeName): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, '__sha256_hashes__', $profile->basePath);
        $volumeScope = $this->cacheManager->resolveVolumeScopeForRoot($this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath));

        $cached = $this->cacheManager->remember(
            $profile,
            $canonicalKey,
            fn (): array => $this->buildVolumeHashIndex($profile, $volume->name),
            $volume->cacheTtl,
            $volumeScope,
        );

        return $cached['data'];
    }

    /**
     * Lightweight O(N) metadata scan grouping file paths by exact byte size with zero file stream reads.
     *
     * @return array<int, list<string>>
     */
    private function scanVolumeBySize(ProfileConfig $profile, string $volumeName): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        $maxFiles = max(100, (int) ($profile->duplicateDetection['max_scan_files'] ?? 5000));
        $bySize = [];
        $scanned = 0;
        $iterations = 0;

        foreach ($filesystem->listContents('', true) as $attr) {
            ++$iterations;
            if ($iterations > 50000) {
                break;
            }

            if (!$attr instanceof FileAttributes) {
                continue;
            }

            try {
                $relPath = $this->pathNormalizer->normalize($attr->path());
            } catch (Throwable) {
                continue;
            }
            if ($relPath === '' || $this->shouldSkipPath($relPath, $profile)) {
                continue;
            }
            try {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($relPath));
            } catch (Throwable) {
                continue;
            }

            ++$scanned;
            if ($scanned > $maxFiles) {
                break;
            }

            $size = (int) ($attr->fileSize() ?? 0);
            if ($size <= 0 || $size > self::MAX_HASHABLE_FILE_BYTES) {
                continue;
            }

            $bySize[$size][] = $relPath;
        }

        return $bySize;
    }

    /**
     * @return array<string, list<array{path: string, size: int}>>
     */
    private function buildVolumeHashIndex(ProfileConfig $profile, string $volumeName): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volumeName);
        $bySize = $this->getVolumeSizeIndex($profile, $volumeName);
        $index = [];
        $maxBucketCandidates = 100;
        $maxTotalBytesHashed = 536_870_912; // 512 MiB cap per scan prevents cloud I/O storms
        $bytesHashed = 0;

        foreach ($bySize as $size => $paths) {
            // Unique-sized files can never be duplicates of another file in the volume; skip reading/hashing them!
            if (count($paths) < 2) {
                continue;
            }

            $bucketCount = 0;
            foreach ($paths as $relPath) {
                if (++$bucketCount > $maxBucketCandidates || ($bytesHashed + (int) $size) > $maxTotalBytesHashed) {
                    break;
                }
                $hash = $this->hashFlysystemFile($filesystem, $relPath);
                if ($hash === null) {
                    continue;
                }
                $bytesHashed += (int) $size;
                $index[$hash][] = ['path' => $relPath, 'size' => (int) $size];
            }
            if ($bytesHashed >= $maxTotalBytesHashed) {
                break;
            }
        }

        return $index;
    }

    private function hashFlysystemFile(FilesystemOperator $filesystem, string $relPath): ?string
    {
        try {
            $stream = $filesystem->readStream($relPath);
            if (!is_resource($stream)) {
                return null;
            }

            try {
                $ctx = hash_init('sha256');
                $totalRead = 0;
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        return null;
                    }
                    if ($chunk === '') {
                        if (feof($stream)) {
                            break;
                        }

                        return null;
                    }
                    $totalRead += strlen($chunk);
                    if ($totalRead > self::MAX_HASHABLE_FILE_BYTES) {
                        return null;
                    }
                    hash_update($ctx, $chunk);
                }

                return hash_final($ctx);
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return null;
        }
    }

    private function shouldSkipPath(string $relPath, ProfileConfig $profile): bool
    {
        if ($relPath === '') {
            return true;
        }

        return $this->fileSecurityGuard->isPathIgnored($relPath, $profile);
    }
}
