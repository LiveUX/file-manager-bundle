<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Metadata;

use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;

final class JsonFileMetadataStorage implements FolderMetadataStorageInterface, ResetInterface
{
    /**
     * @var array<string, FolderMetadata>|null
     */
    private ?array $memoryCache = null;

    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function reset(): void
    {
        $this->memoryCache = null;
    }

    public function get(string $canonicalKey): FolderMetadata
    {
        $canonicalKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalKey);
        $all = $this->all();

        return $all[$canonicalKey] ?? new FolderMetadata();
    }

    /**
     * @return array<string, FolderMetadata>
     */
    public function getByPrefix(string $canonicalPrefix): array
    {
        $canonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalPrefix);
        $childPrefix = self::buildChildPrefix($canonicalPrefix);
        $result = [];
        foreach ($this->all() as $key => $meta) {
            if ($key === $canonicalPrefix || str_starts_with($key, $childPrefix)) {
                $result[$key] = $meta;
            }
        }

        return $result;
    }

    public function all(): array
    {
        if ($this->memoryCache !== null) {
            return $this->memoryCache;
        }

        $this->memoryCache = $this->readFromDisk();

        return $this->memoryCache;
    }

    public function save(string $canonicalKey, FolderMetadata $metadata): void
    {
        $canonicalKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalKey);
        $this->mutateLocked(static function (array $all) use ($canonicalKey, $metadata): ?array {
            if ($metadata->isEmpty()) {
                if (!isset($all[$canonicalKey])) {
                    return null;
                }
                unset($all[$canonicalKey]);

                return $all;
            }

            $all[$canonicalKey] = $metadata;

            return $all;
        });
    }

    /**
     * Atomically applies a batch of metadata writes under a single file lock and rewrite,
     * preventing O(N²) disk I/O during filtered folder copies and clones.
     *
     * @param array<string, FolderMetadata> $entries
     */
    public function saveMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $normalized = [];
        foreach ($entries as $rawKey => $meta) {
            $normalized[\LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($rawKey)] = $meta;
        }

        $this->mutateLocked(static function (array $all) use ($normalized): ?array {
            $changed = false;
            foreach ($normalized as $canonicalKey => $metadata) {
                if ($metadata->isEmpty()) {
                    if (isset($all[$canonicalKey])) {
                        unset($all[$canonicalKey]);
                        $changed = true;
                    }
                    continue;
                }
                $all[$canonicalKey] = $metadata;
                $changed = true;
            }

            return $changed ? $all : null;
        });
    }

    public function delete(string $canonicalKey): void
    {
        $canonicalKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalKey);
        $this->mutateLocked(static function (array $all) use ($canonicalKey): ?array {
            $prefix = self::buildChildPrefix($canonicalKey);
            $changed = false;

            foreach (array_keys($all) as $key) {
                if ($key === $canonicalKey || str_starts_with($key, $prefix)) {
                    unset($all[$key]);
                    $changed = true;
                }
            }

            return $changed ? $all : null;
        });
    }

    public function relocatePrefix(string $oldCanonicalPrefix, string $newCanonicalPrefix): void
    {
        $oldCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($oldCanonicalPrefix);
        $newCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($newCanonicalPrefix);
        if ($oldCanonicalPrefix === $newCanonicalPrefix) {
            return;
        }

        $this->mutateLocked(static function (array $all) use ($oldCanonicalPrefix, $newCanonicalPrefix): ?array {
            $oldChildPrefix = self::buildChildPrefix($oldCanonicalPrefix);
            $newChildPrefix = self::buildChildPrefix($newCanonicalPrefix);
            $updated = [];
            $changed = false;

            foreach ($all as $key => $meta) {
                if ($key === $oldCanonicalPrefix) {
                    $updated[$newCanonicalPrefix] = $meta;
                    $changed = true;
                } elseif (str_starts_with($key, $oldChildPrefix)) {
                    $relSuffix = substr($key, strlen($oldChildPrefix));
                    $updated[$newChildPrefix . $relSuffix] = $meta;
                    $changed = true;
                } elseif ($key === $newCanonicalPrefix || str_starts_with($key, $newChildPrefix)) {
                    $changed = true;
                } else {
                    $updated[$key] = $meta;
                }
            }

            return $changed ? $updated : null;
        });
    }

    public function copyPrefix(string $sourceCanonicalPrefix, string $targetCanonicalPrefix): void
    {
        $sourceCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($sourceCanonicalPrefix);
        $targetCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($targetCanonicalPrefix);
        if ($sourceCanonicalPrefix === $targetCanonicalPrefix) {
            return;
        }

        $this->mutateLocked(static function (array $all) use ($sourceCanonicalPrefix, $targetCanonicalPrefix): ?array {
            $sourceChildPrefix = self::buildChildPrefix($sourceCanonicalPrefix);
            $targetChildPrefix = self::buildChildPrefix($targetCanonicalPrefix);
            $updated = [];
            $copied = [];
            $changed = false;

            foreach ($all as $key => $meta) {
                if ($key === $targetCanonicalPrefix || str_starts_with($key, $targetChildPrefix)) {
                    $changed = true;
                } else {
                    $updated[$key] = $meta;
                }

                if ($key === $sourceCanonicalPrefix) {
                    $copied[$targetCanonicalPrefix] = $meta;
                    $changed = true;
                } elseif (str_starts_with($key, $sourceChildPrefix)) {
                    $relSuffix = substr($key, strlen($sourceChildPrefix));
                    $copied[$targetChildPrefix . $relSuffix] = $meta;
                    $changed = true;
                }
            }

            if (!$changed) {
                return null;
            }

            foreach ($copied as $dstKey => $meta) {
                $updated[$dstKey] = $meta;
            }

            return $updated;
        });
    }

    /**
     * @return array<string, FolderMetadata>
     */
    private function readFromDisk(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $lockPath = $this->filePath . '.lock';
        $lockFp = is_file($lockPath) ? @fopen($lockPath, 'rb') : @fopen($lockPath, 'c+b');
        if ($lockFp !== false) {
            @flock($lockFp, LOCK_SH);
        }

        try {
            $raw = @file_get_contents($this->filePath);
        } finally {
            if ($lockFp !== false) {
                @flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return $this->decodePayload($raw);
    }

    /**
     * @return array<string, FolderMetadata>
     */
    private function decodePayload(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $result[\LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($key)] = FolderMetadata::fromArray($entry);
            }
        }

        return $result;
    }

    /**
     * Holds an exclusive file lock across the full read-modify-write cycle to avoid lost updates.
     *
     * @param callable(array<string, FolderMetadata>): (array<string, FolderMetadata>|null) $mutator
     */
    private function mutateLocked(callable $mutator): void
    {
        $dir = \dirname($this->filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create metadata directory "%s".', $dir));
        }

        $lockFp = @fopen($this->filePath . '.lock', 'c+b');
        if ($lockFp === false) {
            throw new RuntimeException(sprintf('Unable to open metadata lock file "%s.lock".', $this->filePath));
        }

        try {
            if (!flock($lockFp, LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to acquire exclusive lock on "%s.lock".', $this->filePath));
            }

            $current = $this->readCurrentUnderLock();
            $next = $mutator($current);
            if ($next === null) {
                $this->memoryCache = $current;

                return;
            }

            $payload = [];
            foreach ($next as $key => $meta) {
                $payload[$key] = $meta->toArray();
            }

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw new RuntimeException('Failed to encode metadata JSON payload.');
            }
            $this->writeAtomically($json);

            $this->memoryCache = $next;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Fails closed instead of wiping existing metadata if the file exists but cannot be read or decoded.
     *
     * @return array<string, FolderMetadata>
     */
    private function readCurrentUnderLock(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $raw = @file_get_contents($this->filePath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read existing metadata file "%s".', $this->filePath));
        }
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException(sprintf('Refusing to overwrite unreadable or corrupt metadata JSON file "%s".', $this->filePath));
        }

        return $this->decodePayload($raw);
    }

    private function writeAtomically(string $json): void
    {
        $tmpPath = $this->filePath . '.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmpPath, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('Unable to write metadata temporary file "%s".', $tmpPath));
        }

        if (!@rename($tmpPath, $this->filePath)) {
            // Windows fallback when target is transiently read-locked by another process.
            $copied = @copy($tmpPath, $this->filePath);
            @unlink($tmpPath);
            if (!$copied) {
                throw new RuntimeException(sprintf('Unable to persist metadata file "%s".', $this->filePath));
            }
        }
    }

    private static function buildChildPrefix(string $canonicalPrefix): string
    {
        return str_ends_with($canonicalPrefix, '::') ? $canonicalPrefix : $canonicalPrefix . '/';
    }
}
