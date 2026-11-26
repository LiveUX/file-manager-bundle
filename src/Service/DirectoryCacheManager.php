<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class DirectoryCacheManager
{
    private const string GLOBAL_GEN_KEY = 'liveux_fm_gen_all';

    private readonly ?TagAwareCacheInterface $resolvedTagAwareCache;

    private int $invalidationEpoch = 0;

    public function __construct(
        private readonly ?CacheItemPoolInterface $cachePool = null,
        ?TagAwareCacheInterface $tagAwareCache = null,
    ) {
        $this->resolvedTagAwareCache = $tagAwareCache
            ?? ($cachePool instanceof TagAwareCacheInterface ? $cachePool : null);
    }

    public function getInvalidationEpoch(): int
    {
        return $this->invalidationEpoch;
    }

    /**
     * @template T
     *
     * @param callable(): T $loader
     *
     * @return array{data: T, hit: bool}
     */
    public function remember(
        ProfileConfig $profile,
        string $canonicalDirPath,
        callable $loader,
        ?int $overrideTtl = null,
        ?string $volumeScope = null,
        ?string $sharedGroupKey = null,
    ): array {
        $ttl = $this->resolveTtl($profile, $overrideTtl);
        if ($ttl <= 0 || ($this->resolvedTagAwareCache === null && $this->cachePool === null)) {
            return ['data' => $loader(), 'hit' => false];
        }

        $effectiveVolumeScope = $volumeScope ?? $this->extractVolumeScope($canonicalDirPath);

        if ($this->resolvedTagAwareCache !== null) {
            return $this->rememberWithTags($this->resolvedTagAwareCache, $profile, $canonicalDirPath, $effectiveVolumeScope, $ttl, $loader, $sharedGroupKey);
        }

        return $this->rememberWithGenerations($this->cachePool, $profile, $canonicalDirPath, $effectiveVolumeScope, $ttl, $loader, $sharedGroupKey);
    }

    public function invalidateDirectory(string $canonicalDirPath): void
    {
        ++$this->invalidationEpoch;

        if ($this->resolvedTagAwareCache !== null) {
            $this->resolvedTagAwareCache->invalidateTags([$this->buildDirectoryTag($canonicalDirPath)]);

            return;
        }

        $this->bumpGeneration($this->buildDirGenKey($canonicalDirPath));
    }

    public function invalidateVolume(string $volumeCanonicalRoot): void
    {
        $this->invalidateVolumeScope($this->resolveVolumeScopeForRoot($volumeCanonicalRoot));
    }

    public function invalidateVolumeScope(string $volumeScope): void
    {
        ++$this->invalidationEpoch;

        if ($this->resolvedTagAwareCache !== null) {
            $this->resolvedTagAwareCache->invalidateTags([$this->buildVolumeTag($volumeScope)]);

            return;
        }

        $this->bumpGeneration($this->buildVolumeGenKey($volumeScope));
    }

    public function resolveVolumeScopeForRoot(string $volumeCanonicalRoot): string
    {
        if (
            str_starts_with($volumeCanonicalRoot, 'local::')
            && $volumeCanonicalRoot !== 'local::'
            && !str_starts_with($volumeCanonicalRoot, 'local::@ext_')
        ) {
            return 'local_sub::' . substr($volumeCanonicalRoot, 7);
        }

        if (str_starts_with($volumeCanonicalRoot, 's3::')) {
            $rest = substr($volumeCanonicalRoot, 4);
            if (str_contains($rest, '/')) {
                return 's3_sub::' . $rest;
            }
        }

        return $this->extractVolumeScope($volumeCanonicalRoot);
    }

    public function invalidateProfile(string $profileName): void
    {
        ++$this->invalidationEpoch;

        if ($this->resolvedTagAwareCache !== null) {
            $this->resolvedTagAwareCache->invalidateTags([$this->buildProfileTag($profileName)]);

            return;
        }

        // Never call $this->cachePool->clear() — it would wipe the host application's entire cache.app!
        $this->bumpGeneration($this->buildProfileGenKey($profileName));
    }

    public function invalidateAll(): void
    {
        ++$this->invalidationEpoch;

        if ($this->resolvedTagAwareCache !== null) {
            $this->resolvedTagAwareCache->invalidateTags(['liveux_fm_all']);

            return;
        }

        // Bump only FileManager's global generation key so unrelated host app cache entries remain intact.
        $this->bumpGeneration(self::GLOBAL_GEN_KEY);
    }

    public function buildProfilePolicyFingerprint(ProfileConfig $profile): string
    {
        $patterns = [];
        foreach ($profile->excludePatterns as $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $patterns[] = trim($pattern);
            }
        }
        sort($patterns);

        $rawTrashDir = is_string($profile->trash['directory'] ?? null) ? $profile->trash['directory'] : '.trash';
        $trashDir = trim(str_replace('\\', '/', $rawTrashDir), '/');
        $locale = is_string($profile->locale) ? trim($profile->locale) : '';
        $maxScanFiles = max(100, (int) ($profile->duplicateDetection['max_scan_files'] ?? 5000));

        $seed = json_encode([
            $profile->ignoreDotFiles,
            $patterns,
            $trashDir !== '' ? $trashDir : '.trash',
            $locale,
            $maxScanFiles,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr(md5(is_string($seed) ? $seed : ''), 0, 10);
    }

    public function buildDirectoryKey(string $canonicalDirPath, string $profileName = '', string $policyFingerprint = ''): string
    {
        $seed = $profileName !== '' ? $profileName . '|' . $canonicalDirPath : $canonicalDirPath;
        if ($policyFingerprint !== '') {
            $seed .= '|fp:' . $policyFingerprint;
        }

        return 'liveux_fm_dir_' . md5($seed);
    }

    public function buildDirectoryTag(string $canonicalDirPath): string
    {
        return 'liveux_fm_path_' . md5($canonicalDirPath);
    }

    public function buildVolumeTag(string $volumeScope): string
    {
        return 'liveux_fm_vol_' . md5($volumeScope);
    }

    public function buildProfileTag(string $profileName): string
    {
        return 'liveux_fm_profile_' . md5($profileName);
    }

    /**
     * @template T
     *
     * @param callable(): T $loader
     *
     * @return array{data: T, hit: bool}
     */
    private function rememberWithTags(
        TagAwareCacheInterface $tagAwareCache,
        ProfileConfig $profile,
        string $canonicalDirPath,
        string $volumeScope,
        int $ttl,
        callable $loader,
        ?string $sharedGroupKey = null,
    ): array {
        $key = $this->buildDirectoryKey($canonicalDirPath, $profile->name, $this->buildProfilePolicyFingerprint($profile));
        $wasHit = true;

        $data = $tagAwareCache->get($key, function (ItemInterface $item) use ($loader, $ttl, $canonicalDirPath, $volumeScope, $profile, $sharedGroupKey, &$wasHit) {
            $wasHit = false;
            $item->expiresAfter($ttl);
            $tags = [
                $this->buildDirectoryTag($canonicalDirPath),
                $this->buildVolumeTag($volumeScope),
                $this->buildProfileTag($profile->name),
                'liveux_fm_all',
            ];
            if ($sharedGroupKey !== null && $sharedGroupKey !== $canonicalDirPath) {
                $tags[] = $this->buildDirectoryTag($sharedGroupKey);
            }
            $item->tag($tags);

            return $loader();
        });

        return ['data' => $data, 'hit' => $wasHit];
    }

    /**
     * @template T
     *
     * @param callable(): T $loader
     *
     * @return array{data: T, hit: bool}
     */
    private function rememberWithGenerations(
        CacheItemPoolInterface $cachePool,
        ProfileConfig $profile,
        string $canonicalDirPath,
        string $volumeScope,
        int $ttl,
        callable $loader,
        ?string $sharedGroupKey = null,
    ): array {
        $profileGenKey = $this->buildProfileGenKey($profile->name);
        $volumeGenKey = $this->buildVolumeGenKey($volumeScope);
        $dirGenKey = $this->buildDirGenKey($canonicalDirPath);
        $genKeys = [
            self::GLOBAL_GEN_KEY,
            $profileGenKey,
            $volumeGenKey,
            $dirGenKey,
        ];
        $sharedGenKey = null;
        if ($sharedGroupKey !== null && $sharedGroupKey !== $canonicalDirPath) {
            $sharedGenKey = $this->buildDirGenKey($sharedGroupKey);
            $genKeys[] = $sharedGenKey;
        }
        $gens = $this->readGenerations($genKeys);
        $key = sprintf(
            '%s_g%s_p%s_v%s_d%s_s%s',
            $this->buildDirectoryKey($canonicalDirPath, $profile->name, $this->buildProfilePolicyFingerprint($profile)),
            $gens[self::GLOBAL_GEN_KEY],
            $gens[$profileGenKey],
            $gens[$volumeGenKey],
            $gens[$dirGenKey],
            $sharedGenKey !== null ? $gens[$sharedGenKey] : '0',
        );

        $cacheItem = $cachePool->getItem($key);
        if ($cacheItem->isHit()) {
            return ['data' => $cacheItem->get(), 'hit' => true];
        }

        $data = $loader();
        $cacheItem->set($data);
        $cacheItem->expiresAfter($ttl);
        $cachePool->save($cacheItem);

        return ['data' => $data, 'hit' => false];
    }

    private function extractVolumeScope(string $canonicalPath): string
    {
        $sepPos = strpos($canonicalPath, '::');
        if ($sepPos === false) {
            return $canonicalPath;
        }

        $driver = substr($canonicalPath, 0, $sepPos);
        $rest = substr($canonicalPath, $sepPos + 2);
        if ($driver === 'local') {
            if (preg_match('/^(@ext_[0-9a-f]{8})(?:\/|$)/', $rest, $m) === 1) {
                return 'local::' . $m[1];
            }

            return 'local';
        }

        $firstSlash = strpos($rest, '/');
        $rootSeg = $firstSlash === false ? $rest : substr($rest, 0, $firstSlash);

        return $driver . '::' . $rootSeg;
    }

    private function buildVolumeGenKey(string $volumeScope): string
    {
        return 'liveux_fm_gen_vol_' . md5($volumeScope);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function readGenerations(array $keys): array
    {
        $result = array_fill_keys($keys, '0');
        if ($this->cachePool === null) {
            return $result;
        }

        foreach ($this->cachePool->getItems($keys) as $key => $item) {
            if ($item->isHit()) {
                $val = $item->get();
                $result[(string) $key] = (is_string($val) && $val !== '') || is_int($val) ? (string) $val : '0';
            }
        }

        return $result;
    }

    private function bumpGeneration(string $key): void
    {
        if ($this->cachePool === null) {
            return;
        }

        $next = 'r' . bin2hex(random_bytes(8));
        $item = $this->cachePool->getItem($key);
        $item->set($next);
        // Keep generation markers alive far longer than directory/volume TTLs so pool eviction/default_lifetime
        // cannot reset the generation back to '0' and resurrect stale pre-mutation entries.
        $item->expiresAfter(2_592_000);
        $this->cachePool->save($item);
    }

    private function buildProfileGenKey(string $profileName): string
    {
        return 'liveux_fm_gen_profile_' . md5($profileName);
    }

    private function buildDirGenKey(string $canonicalDirPath): string
    {
        return 'liveux_fm_gen_dir_' . md5($canonicalDirPath);
    }

    private function resolveTtl(ProfileConfig $profile, ?int $overrideTtl = null): int
    {
        if (($profile->cache['enabled'] ?? true) !== true) {
            return 0;
        }

        if ($overrideTtl !== null) {
            return max(0, $overrideTtl);
        }

        $ttl = $profile->cache['ttl'] ?? 3600;

        return is_int($ttl) ? max(0, $ttl) : 3600;
    }
}
