<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Thumbnail;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class ThumbnailManager implements ResetInterface
{
    private bool $missingDriverWarned = false;

    public function __construct(
        private readonly ImagickThumbnailDriver $imagickDriver,
        private readonly GdThumbnailDriver $gdDriver,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $cacheSecret = '',
    ) {
    }

    public function reset(): void
    {
        $this->missingDriverWarned = false;
    }

    public function resolveDriver(ProfileConfig $profile): ?ThumbnailDriverInterface
    {
        if (($profile->thumbnail['enabled'] ?? true) !== true) {
            return null;
        }

        $requested = is_string($profile->thumbnail['driver'] ?? null) ? $profile->thumbnail['driver'] : 'auto';
        if ($requested === 'none') {
            return null;
        }

        if ($requested === 'liip_imagine') {
            $this->handleMissingDriver($profile, 'liip_imagine');

            return null;
        }

        if ($requested === 'imagick') {
            if ($this->imagickDriver->isAvailable()) {
                return $this->imagickDriver;
            }
            $this->handleMissingDriver($profile, 'imagick');

            return null;
        }

        if ($requested === 'gd') {
            if ($this->gdDriver->isAvailable()) {
                return $this->gdDriver;
            }
            $this->handleMissingDriver($profile, 'gd');

            return null;
        }

        if ($this->imagickDriver->isAvailable()) {
            return $this->imagickDriver;
        }

        if ($this->gdDriver->isAvailable()) {
            return $this->gdDriver;
        }

        $this->handleMissingDriver($profile, 'auto (imagick/gd)');

        return null;
    }

    public function getCachedPath(ProfileConfig $profile, string $canonicalPath): string
    {
        $canonicalPath = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalPath);
        $cacheDir = rtrim(trim((string) ($profile->thumbnail['cache_dir'] ?? '')), '/\\');
        if ($cacheDir === '') {
            return '';
        }

        $format = $this->resolveEffectiveFormat($profile);
        $width = max(1, (int) ($profile->thumbnail['width'] ?? 320));
        $height = max(1, (int) ($profile->thumbnail['height'] ?? 240));
        $quality = min(100, max(10, (int) ($profile->thumbnail['quality'] ?? 80)));
        $fit = (string) ($profile->thumbnail['fit'] ?? 'contain');
        $baseScope = ($profile->basePath !== '' && str_starts_with($canonicalPath, 'local::'))
            ? '|b:' . substr(md5(strtolower(rtrim(str_replace('\\', '/', $profile->basePath), '/'))), 0, 8)
            : '';
        $material = $canonicalPath . $baseScope . '|' . $width . 'x' . $height . '|' . $fit . '|q' . $quality;
        // When a secret is configured the file name is not guessable, so a publicly served cache dir cannot be probed.
        $hash = $this->cacheSecret !== '' ? hash_hmac('sha256', $material, $this->cacheSecret) : md5($material);

        return $cacheDir . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.' . $format;
    }

    public function generateIfMissing(ProfileConfig $profile, string $canonicalPath, string $sourceBinary): ?string
    {
        $driver = $this->resolveDriver($profile);
        if ($driver === null) {
            return null;
        }

        $targetPath = $this->getCachedPath($profile, $canonicalPath);
        if ($targetPath === '') {
            return null;
        }

        if (is_file($targetPath)) {
            return $targetPath;
        }

        $ok = $driver->generate(
            sourceBinary: $sourceBinary,
            targetFilePath: $targetPath,
            width: max(1, (int) ($profile->thumbnail['width'] ?? 320)),
            height: max(1, (int) ($profile->thumbnail['height'] ?? 240)),
            format: $this->resolveEffectiveFormat($profile, $driver),
            quality: min(100, max(10, (int) ($profile->thumbnail['quality'] ?? 80))),
            fit: (string) ($profile->thumbnail['fit'] ?? 'contain'),
        );

        return $ok ? $targetPath : null;
    }

    public function hasCachedThumbnail(ProfileConfig $profile, string $canonicalPath): bool
    {
        foreach ($this->getCandidateCachedPaths($profile, $canonicalPath) as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    public function regenerate(ProfileConfig $profile, string $canonicalPath, string $sourceBinary): ?string
    {
        $this->deleteCachedThumbnail($profile, $canonicalPath);

        return $this->generateIfMissing($profile, $canonicalPath, $sourceBinary);
    }

    public function deleteCachedThumbnail(ProfileConfig $profile, string $canonicalPath): void
    {
        foreach ($this->getCandidateCachedPaths($profile, $canonicalPath) as $targetPath) {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    /**
     * Returns all possible cached file paths for the given profile & canonical key,
     * including both `.webp` and `.jpeg` fallback extensions so CLI/Web SAPI differences
     * in WebP support never orphan cached thumbnails.
     *
     * @return list<string>
     */
    public function getCandidateCachedPaths(ProfileConfig $profile, string $canonicalPath): array
    {
        $primary = $this->getCachedPath($profile, $canonicalPath);
        if ($primary === '') {
            return [];
        }

        $paths = [$primary];
        $configured = strtolower((string) ($profile->thumbnail['format'] ?? 'webp'));
        $dotPos = strrpos($primary, '.');
        if ($dotPos !== false && in_array($configured, ['webp', 'jpeg', 'jpg'], true)) {
            $stem = substr($primary, 0, $dotPos);
            foreach (['webp', 'jpeg'] as $altExt) {
                $alt = $stem . '.' . $altExt;
                if ($alt !== $primary) {
                    $paths[] = $alt;
                }
            }
        }

        return $paths;
    }

    public function clearCacheDir(string $cacheDir, bool $dryRun = false): int
    {
        $resolved = $this->resolveSafeCacheDir($cacheDir);
        if ($resolved === null) {
            return 0;
        }

        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !$this->isManagedCacheFile($file)) {
                continue;
            }
            if ($dryRun || @unlink($file->getPathname())) {
                ++$deleted;
            }
        }

        if (!$dryRun) {
            $this->deleteEmptySubdirs($resolved);
        }

        return $deleted;
    }

    /**
     * Only files this manager generated (`<hex-hash>.<image-ext>`) may ever be deleted from a cache directory,
     * so a misconfigured `cache_dir` can never wipe unrelated files.
     */
    private function isManagedCacheFile(\SplFileInfo $file): bool
    {
        return preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{64})\.(?:webp|jpe?g|png|gif|avif)$/i', $file->getFilename()) === 1;
    }

    /**
     * Deletes cached thumbnail files inside $cacheDir whose normalized path is NOT in $validPathsMap.
     *
     * @param array<string, true> $validPathsMap Map of normalized real/absolute thumbnail paths to keep
     */
    public function purgeOrphans(string $cacheDir, array $validPathsMap, bool $dryRun = false): int
    {
        $resolved = $this->resolveSafeCacheDir($cacheDir);
        if ($resolved === null) {
            return 0;
        }

        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !$this->isManagedCacheFile($file)) {
                continue;
            }
            $normalized = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
            if (isset($validPathsMap[$normalized])) {
                continue;
            }
            if ($dryRun || @unlink($file->getPathname())) {
                ++$deleted;
            }
        }

        if (!$dryRun) {
            $this->deleteEmptySubdirs($resolved);
        }

        return $deleted;
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public function collectCacheStats(string $cacheDir): array
    {
        $resolved = $this->resolveSafeCacheDir($cacheDir);
        if ($resolved === null) {
            return ['files' => 0, 'bytes' => 0];
        }

        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                ++$files;
                $bytes += (int) $file->getSize();
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function resolveSafeCacheDir(string $cacheDir): ?string
    {
        $trimmed = trim($cacheDir);
        if ($trimmed === '' || !is_dir($trimmed)) {
            return null;
        }

        $resolved = realpath($trimmed);
        if ($resolved === false || strlen($resolved) < 5 || \dirname($resolved) === $resolved) {
            return null;
        }

        return $resolved;
    }

    private function deleteEmptySubdirs(string $resolvedRoot): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
    }

    /**
     * @return array{activeDriver: string, imagick: bool, gd: bool, webp: bool}
     */
    public function getDiagnostics(): array
    {
        $active = $this->imagickDriver->isAvailable()
            ? 'imagick'
            : ($this->gdDriver->isAvailable() ? 'gd' : 'none');

        return [
            'activeDriver' => $active,
            'imagick' => $this->imagickDriver->isAvailable(),
            'gd' => $this->gdDriver->isAvailable(),
            'webp' => $this->imagickDriver->supportsWebp() || $this->gdDriver->supportsWebp(),
        ];
    }

    private function resolveEffectiveFormat(ProfileConfig $profile, ?ThumbnailDriverInterface $activeDriver = null): string
    {
        $requested = (string) ($profile->thumbnail['format'] ?? 'webp');
        if ($requested === 'webp') {
            $driver = $activeDriver ?? $this->resolveDriverSilently($profile);
            if ($driver !== null) {
                return $driver->supportsWebp() ? 'webp' : 'jpeg';
            }
            if (!$this->imagickDriver->supportsWebp() && !$this->gdDriver->supportsWebp()) {
                return 'jpeg';
            }
        }

        return $requested;
    }

    private function resolveDriverSilently(ProfileConfig $profile): ?ThumbnailDriverInterface
    {
        $requested = is_string($profile->thumbnail['driver'] ?? null) ? $profile->thumbnail['driver'] : 'auto';
        if ($requested === 'imagick') {
            return $this->imagickDriver->isAvailable() ? $this->imagickDriver : null;
        }
        if ($requested === 'gd') {
            return $this->gdDriver->isAvailable() ? $this->gdDriver : null;
        }
        if ($requested === 'none' || $requested === 'liip_imagine') {
            return null;
        }

        if ($this->imagickDriver->isAvailable()) {
            return $this->imagickDriver;
        }

        return $this->gdDriver->isAvailable() ? $this->gdDriver : null;
    }

    private function handleMissingDriver(ProfileConfig $profile, string $driverName): void
    {
        $strategy = (string) ($profile->thumbnail['on_missing_driver'] ?? 'fallback_original');

        if ($strategy === 'exception') {
            throw new FileManagerException(sprintf(
                'LiveUX FileManager thumbnail driver "%s" is not available on this server.',
                $driverName,
            ));
        }

        if (!$this->missingDriverWarned) {
            $this->missingDriverWarned = true;
            $this->logger?->warning('LiveUX FileManager thumbnail driver "{driver}" is unavailable; using fallback strategy "{strategy}".', [
                'driver' => $driverName,
                'strategy' => $strategy,
            ]);
        }
    }
}
