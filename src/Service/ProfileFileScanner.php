<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use Generator;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

/**
 * Shared profile/volume scanner for CLI maintenance commands (thumbnail warmup/clear,
 * batch image optimization, orphan cleanup, and storage statistics).
 */
final readonly class ProfileFileScanner
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
    ) {
    }

    /**
     * @return list<ProfileConfig>
     */
    public function resolveTargetProfiles(?string $profileName): array
    {
        if ($profileName !== null && trim($profileName) !== '') {
            return [$this->configProvider->getProfile(trim($profileName))];
        }

        $profiles = [];
        foreach ($this->configProvider->getProfileNames() as $name) {
            $profiles[] = $this->configProvider->getProfile($name);
        }

        return $profiles;
    }

    /**
     * @return list<VolumeConfig>
     */
    public function resolveTargetVolumes(ProfileConfig $profile, ?string $volumeFilter): array
    {
        if ($volumeFilter !== null && trim($volumeFilter) !== '') {
            return [$this->volumeRegistry->getVolumeForProfile($profile, trim($volumeFilter))];
        }

        return $this->volumeRegistry->getVolumesForProfile($profile);
    }

    public function volumeUsesUserIdToken(ProfileConfig $profile, string $volumeName): bool
    {
        $raw = $this->volumeRegistry->getRawVolumeForProfile($profile, $volumeName);
        if ($raw->path !== null && DynamicPathResolver::containsUserIdToken($raw->path)) {
            return true;
        }
        $s3Prefix = $raw->driverOptions['prefix'] ?? null;

        return is_string($s3Prefix) && DynamicPathResolver::containsUserIdToken($s3Prefix);
    }

    public function volumeUsesDateTokens(ProfileConfig $profile, string $volumeName): bool
    {
        $raw = $this->volumeRegistry->getRawVolumeForProfile($profile, $volumeName);
        if ($raw->path !== null && DynamicPathResolver::containsDateTokens($raw->path)) {
            return true;
        }
        $s3Prefix = $raw->driverOptions['prefix'] ?? null;

        return is_string($s3Prefix) && DynamicPathResolver::containsDateTokens($s3Prefix);
    }

    /**
     * Streams eligible raster image files lazily as a Generator without buffering all paths in memory.
     *
     * @param list<string> $allowedExtensions Defaults to FileSecurityGuard::SAFE_RASTER_EXTENSIONS
     *
     * @return Generator<int, array{path: string, canonical: string, extension: string, size: int}>
     */
    public function iterateRasterImages(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $subPath = '',
        array $allowedExtensions = FileSecurityGuard::SAFE_RASTER_EXTENSIONS,
    ): Generator {
        $cleanSubPath = $this->pathNormalizer->normalize($subPath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanSubPath, $profile);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);

        foreach ($filesystem->listContents($cleanSubPath, true) as $attr) {
            if (!$attr instanceof FileAttributes) {
                continue;
            }

            try {
                $relPath = $this->pathNormalizer->normalize($attr->path());
            } catch (\LiveUX\FileManagerBundle\Exception\InvalidPathException) {
                continue;
            }
            if ($relPath === '' || $this->shouldSkipPath($relPath, $profile)) {
                continue;
            }

            $ext = PathNormalizer::extensionUtf8($relPath);
            if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
                continue;
            }

            yield [
                'path' => $relPath,
                'canonical' => $this->pathNormalizer->buildCanonicalKey($volume, $relPath, $profile->basePath),
                'extension' => $ext,
                'size' => (int) ($attr->fileSize() ?? 0),
            ];
        }
    }

    /**
     * Scans a volume (or subfolder) for eligible raster image files while honoring
     * dot-file, trash, and exclude_patterns rules on every path segment.
     *
     * @param list<string> $allowedExtensions Defaults to FileSecurityGuard::SAFE_RASTER_EXTENSIONS
     *
     * @return list<array{path: string, canonical: string, extension: string, size: int}>
     */
    public function scanRasterImages(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $subPath = '',
        array $allowedExtensions = FileSecurityGuard::SAFE_RASTER_EXTENSIONS,
    ): array {
        return iterator_to_array($this->iterateRasterImages($profile, $volume, $subPath, $allowedExtensions), false);
    }

    /**
     * @return array{files: int, folders: int, bytes: int}
     */
    public function scanStorageSummary(ProfileConfig $profile, VolumeConfig $volume): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $files = 0;
        $seenFolders = [];
        $bytes = 0;

        foreach ($filesystem->listContents('', true) as $attr) {
            try {
                $relPath = $this->pathNormalizer->normalize($attr->path());
            } catch (\LiveUX\FileManagerBundle\Exception\InvalidPathException) {
                continue;
            }
            if ($this->shouldSkipPath($relPath, $profile)) {
                continue;
            }

            if ($attr instanceof DirectoryAttributes) {
                if ($relPath !== '') {
                    $seenFolders[$relPath] = true;
                }
                continue;
            }

            if ($attr instanceof FileAttributes) {
                ++$files;
                $bytes += (int) ($attr->fileSize() ?? 0);
                $this->registerVirtualAncestors($relPath, $seenFolders);
            }
        }

        return [
            'files' => $files,
            'folders' => count($seenFolders),
            'bytes' => $bytes,
        ];
    }

    /**
     * Single-pass summary + raster image scan for CLI stats without double traversing storage.
     *
     * @param list<string> $allowedExtensions Defaults to FileSecurityGuard::SAFE_RASTER_EXTENSIONS
     *
     * @return array{files: int, folders: int, bytes: int, images: list<array{path: string, canonical: string, extension: string, size: int}>}
     */
    public function scanStorageSummaryWithImages(
        ProfileConfig $profile,
        VolumeConfig $volume,
        array $allowedExtensions = FileSecurityGuard::SAFE_RASTER_EXTENSIONS,
    ): array {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $files = 0;
        $seenFolders = [];
        $bytes = 0;
        $images = [];

        foreach ($filesystem->listContents('', true) as $attr) {
            try {
                $relPath = $this->pathNormalizer->normalize($attr->path());
            } catch (\LiveUX\FileManagerBundle\Exception\InvalidPathException) {
                continue;
            }
            if ($this->shouldSkipPath($relPath, $profile)) {
                continue;
            }

            if ($attr instanceof DirectoryAttributes) {
                if ($relPath !== '') {
                    $seenFolders[$relPath] = true;
                }
                continue;
            }

            if (!$attr instanceof FileAttributes) {
                continue;
            }

            ++$files;
            $size = (int) ($attr->fileSize() ?? 0);
            $bytes += $size;
            $this->registerVirtualAncestors($relPath, $seenFolders);

            $ext = PathNormalizer::extensionUtf8($relPath);
            if ($ext !== '' && in_array($ext, $allowedExtensions, true)) {
                $images[] = [
                    'path' => $relPath,
                    'canonical' => $this->pathNormalizer->buildCanonicalKey($volume, $relPath, $profile->basePath),
                    'extension' => $ext,
                    'size' => $size,
                ];
            }
        }

        return [
            'files' => $files,
            'folders' => count($seenFolders),
            'bytes' => $bytes,
            'images' => $images,
        ];
    }

    /**
     * @param array<string, true> $seenFolders
     */
    private function registerVirtualAncestors(string $relPath, array &$seenFolders): void
    {
        $ancestor = PathNormalizer::dirnameUtf8($relPath);
        while ($ancestor !== '' && !isset($seenFolders[$ancestor])) {
            $seenFolders[$ancestor] = true;
            $next = PathNormalizer::dirnameUtf8($ancestor);
            if ($next === $ancestor) {
                break;
            }
            $ancestor = $next;
        }
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
    }

    private function shouldSkipPath(string $relPath, ProfileConfig $profile): bool
    {
        if ($relPath === '') {
            return true;
        }

        return $this->fileSecurityGuard->isPathIgnored($relPath, $profile);
    }
}
