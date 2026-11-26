<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Storage;

use FilesystemIterator;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\StorageAttributes;
use League\Flysystem\SymbolicLinkEncountered;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Symlink-confined decorator around Flysystem's LocalFilesystemAdapter.
 *
 * Flysystem v3's LocalFilesystemAdapter::DISALLOW_LINKS only guards listContents();
 * direct read/write/copy/move/delete/deleteDirectory/stat operations follow pre-existing
 * symlinks outside the volume root. This adapter verifies every path segment against
 * is_link() and realpath() confinement on every operation, blocks recursive deletes of
 * directories containing nested symlinks, and uses SKIP_LINKS in listContents() so stray
 * symlinks cannot crash directory listings.
 */
final readonly class ConfinedLocalFilesystemAdapter implements FilesystemAdapter
{
    private LocalFilesystemAdapter $inner;
    private string $rootLocation;

    public function __construct(string $rootLocation)
    {
        $this->rootLocation = rtrim($rootLocation, '/\\');
        $this->inner = new LocalFilesystemAdapter(
            location: $rootLocation,
            linkHandling: LocalFilesystemAdapter::SKIP_LINKS,
        );
    }

    public function fileExists(string $path): bool
    {
        $this->assertPathConfined($path);

        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        $this->assertPathConfined($path);

        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->assertPathConfined($path);
        $this->inner->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->assertPathConfined($path);
        $this->inner->writeStream($path, $contents, $config);
    }

    public function read(string $path): string
    {
        $this->assertPathConfined($path);

        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        $this->assertPathConfined($path);

        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->assertPathConfined($path);
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $fullPath = $this->assertPathConfined($path);
        if (is_dir($fullPath)) {
            $this->assertSubtreeContainsNoSymlinks($fullPath);
        }

        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->assertPathConfined($path);
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->assertPathConfined($path);
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        $this->assertPathConfined($path);

        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        $this->assertPathConfined($path);

        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        $this->assertPathConfined($path);

        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $this->assertPathConfined($path);

        return $this->inner->fileSize($path);
    }

    /**
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $this->assertPathConfined($path);

        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $fullSource = $this->assertPathConfined($source);
        $this->assertPathConfined($destination);
        if (is_dir($fullSource)) {
            $this->assertSubtreeContainsNoSymlinks($fullSource);
        }
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->assertPathConfined($source);
        $this->assertPathConfined($destination);
        $this->inner->copy($source, $destination, $config);
    }

    /**
     * Ensures every existing segment of `$path` is a real file/directory inside the volume root
     * and never traverses a symlink or escapes the canonical root directory.
     */
    private function assertPathConfined(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw PathTraversalDetected::forPath($path);
        }

        clearstatcache(true, $this->rootLocation);
        $rootReal = realpath($this->rootLocation);
        if ($rootReal === false) {
            throw PathTraversalDetected::forPath($path);
        }

        $trimmed = trim(str_replace('\\', '/', $path), '/');
        if ($trimmed === '') {
            return $rootReal;
        }

        $normalizedRoot = $this->normalizeComparablePath($rootReal);
        $current = $rootReal;

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw PathTraversalDetected::forPath($path);
            }

            $current .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $current);

            if (is_link($current)) {
                throw SymbolicLinkEncountered::atLocation($current);
            }

            if (file_exists($current)) {
                $resolved = realpath($current);
                if ($resolved === false || !$this->isWithinRoot($resolved, $normalizedRoot)) {
                    throw PathTraversalDetected::forPath($path);
                }
            }
        }

        return $current;
    }

    private function assertSubtreeContainsNoSymlinks(string $directory): void
    {
        $realRoot = realpath($this->rootLocation);
        $normalizedRoot = $this->normalizeComparablePath($realRoot !== false ? $realRoot : $this->rootLocation);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $pathname = $entry->getPathname();
            if ($entry->isLink()) {
                throw SymbolicLinkEncountered::atLocation($pathname);
            }
            $resolved = realpath($pathname);
            if ($resolved === false || !$this->isWithinRoot($resolved, $normalizedRoot)) {
                throw PathTraversalDetected::forPath($pathname);
            }
        }
    }

    private function isWithinRoot(string $resolvedPath, string $normalizedRoot): bool
    {
        $comparable = $this->normalizeComparablePath($resolvedPath);
        if ($comparable === $normalizedRoot) {
            return true;
        }

        return str_starts_with($comparable, $normalizedRoot . '/');
    }

    private function normalizeComparablePath(string $path): string
    {
        $forward = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($forward, 'UTF-8') : $forward;
    }
}
