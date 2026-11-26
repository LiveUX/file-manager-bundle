<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Model\DirectoryListing;
use LiveUX\FileManagerBundle\Model\FileItem;
use LiveUX\FileManagerBundle\Model\FolderItem;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class DirectoryLister implements ResetInterface
{
    public const string TREE_CACHE_KEY = '__tree_v2__';
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'mov', 'avi', 'mkv', 'm4v'];
    private const int MAX_RECURSIVE_SCAN = 5000;
    private const int MAX_RECURSIVE_ITERATIONS = 50000;
    private const int MAX_TREE_NODES = 2000;
    private const int MAX_GRAPH_DEPTH = 16;
    private const int MAX_GRAPH_NODES = 5000;
    private const int MAX_COUNT_DEPTH = 16;
    private const int MAX_COUNT_DIRS = 5000;
    private const int MAX_CLOUD_TREE_ENTRIES = 25000;

    private FileManagerAuthorizer $authorizer;

    /**
     * @var array<string, list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>>
     */
    private array $requestTreeCache = [];

    /**
     * @var array<string, bool>
     */
    private array $requestGrantCache = [];

    /**
     * @var array<string, array{visibleNodes: list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>, counts: array<string, int>}>
     */
    private array $requestResolvedTreeCache = [];

    public function __construct(
        private readonly VolumeRegistry $volumeRegistry,
        private readonly PathNormalizer $pathNormalizer,
        private readonly DirectoryCacheManager $cacheManager,
        private readonly FolderMetadataStorageInterface $metadataStorage,
        private readonly FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private readonly ?AuthorizationCheckerInterface $authChecker = null,
        private readonly bool $hasExternalVoters = true,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function reset(): void
    {
        $this->requestTreeCache = [];
        $this->requestGrantCache = [];
        $this->requestResolvedTreeCache = [];
    }

    public function hasExternalVoters(): bool
    {
        return $this->hasExternalVoters;
    }

    public function list(
        ProfileConfig $profile,
        ?string $volumeName = null,
        string $path = '',
        int $page = 1,
        ?int $perPage = null,
        string $typeFilter = 'all',
        string $search = '',
        ?string $sortBy = null,
        ?string $sortDirection = null,
        bool $forceRefresh = false,
    ): DirectoryListing {
        $startTime = microtime(true);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        if (!$this->isPathAndAncestorsViewGranted($profile, $volume, '', $cleanPath)) {
            throw new AccessDeniedException('Access denied.', 'errors.access_denied');
        }
        $canonicalDir = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);

        if ($forceRefresh) {
            $this->cacheManager->invalidateDirectory($canonicalDir);
        }

        $volumeScope = $this->resolveVolumeCacheScope($profile, $volume);
        $cached = $this->cacheManager->remember(
            $profile,
            $canonicalDir,
            fn (): array => $this->scanRawDirectory($profile, $volume, $cleanPath),
            $volume->cacheTtl,
            $volumeScope,
        );

        $needsFolderCounts = $this->shouldResolveFolderCounts($profile)
            && ($typeFilter === 'all' || $typeFilter === 'folder')
            && $this->hasFolderEntry($cached['data']);
        $folderCounts = $needsFolderCounts
            ? $this->getFolderCountMapForDirectory($profile, $volume, $cached['data'], $forceRefresh)
            : [];
        $items = $this->hydrateAndFilter($profile, $volume, $cached['data'], $typeFilter, $search, $folderCounts);
        $items = $this->sortItems(
            $items,
            $sortBy ?? $profile->sortBy,
            $sortDirection ?? $profile->sortDirection,
            $profile->foldersFirst,
            $profile->locale,
        );

        $resolvedPerPage = max(1, $perPage ?? (int) ($profile->ui['per_page'] ?? 24));
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $resolvedPerPage));
        $safePage = min(max(1, $page), $totalPages);
        $slice = array_slice($items, ($safePage - 1) * $resolvedPerPage, $resolvedPerPage);

        return new DirectoryListing(
            items: $slice,
            total: $total,
            page: $safePage,
            perPage: $resolvedPerPage,
            totalPages: $totalPages,
            fromCache: $cached['hit'],
            durationMs: round((microtime(true) - $startTime) * 1000, 2),
        );
    }

    /**
     * Verifies that a non-root directory still exists on the underlying storage (e.g. after external FTP/S3 deletion).
     * Walks up parent directories until an existing folder or root ('') is found, invalidating stale cache on fallback.
     *
     * @return array{path: string, fallbackApplied: bool}
     */
    public function resolveExistingPath(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $cleanPath,
        bool $invalidateCaches = true,
    ): array {
        if ($cleanPath === '') {
            return ['path' => '', 'fallbackApplied' => false];
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        if ($filesystem->directoryExists($cleanPath)) {
            return ['path' => $cleanPath, 'fallbackApplied' => false];
        }

        $candidate = PathNormalizer::dirnameUtf8($cleanPath);
        while ($candidate !== '') {
            if ($filesystem->directoryExists($candidate)) {
                if ($invalidateCaches) {
                    $this->invalidateFallbackCaches($profile, $volume, $cleanPath, $candidate);
                }

                return ['path' => $candidate, 'fallbackApplied' => true];
            }
            $candidate = PathNormalizer::dirnameUtf8($candidate);
        }

        if ($invalidateCaches) {
            $this->invalidateFallbackCaches($profile, $volume, $cleanPath, '');
        }

        return ['path' => '', 'fallbackApplied' => true];
    }

    public function invalidateFallbackCaches(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $originalPath,
        string $resolvedPath,
        bool $invalidateTree = true,
    ): void {
        if ($originalPath === '' || $originalPath === $resolvedPath) {
            return;
        }

        $staleCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $originalPath, $profile->basePath);
        $resolvedCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $resolvedPath, $profile->basePath);
        $this->cacheManager->invalidateDirectory($staleCanonical);
        if ($invalidateTree) {
            $treeCanonical = $this->pathNormalizer->buildCanonicalKey($volume, self::TREE_CACHE_KEY, $profile->basePath);
            $this->cacheManager->invalidateDirectory($treeCanonical);
        }
        $this->cacheManager->invalidateDirectory($resolvedCanonical);
    }

    /**
     * @return list<FolderItem>
     */
    public function listSubfolders(ProfileConfig $profile, ?string $volumeName = null, string $path = '', bool $forceRefresh = false): array
    {
        $listing = $this->list(
            profile: $profile,
            volumeName: $volumeName,
            path: $path,
            page: 1,
            perPage: 1000,
            typeFilter: 'folder',
            sortBy: 'name',
            sortDirection: 'asc',
            forceRefresh: $forceRefresh,
        );

        $folders = [];
        foreach ($listing->items as $item) {
            if ($item instanceof FolderItem) {
                $folders[] = $item;
            }
        }

        return $folders;
    }

    /**
     * Searches recursively across subfolders (strictly bounded and filtered by ignore/security rules).
     */
    public function searchRecursive(
        ProfileConfig $profile,
        ?string $volumeName,
        string $basePath,
        string $search,
        string $typeFilter = 'all',
        int $maxResults = 300,
        ?string $sortBy = null,
        ?string $sortDirection = null,
    ): DirectoryListing {
        $startTime = microtime(true);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $cleanBase = $this->pathNormalizer->normalize($basePath);
        $this->fileSecurityGuard->assertAccessiblePath($cleanBase, $profile);
        if (!$this->isPathAndAncestorsViewGranted($profile, $volume, '', $cleanBase)) {
            throw new AccessDeniedException('Access denied.', 'errors.access_denied');
        }
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);

        $query = UnicodeHelper::foldForSearch(UnicodeHelper::trimSpaces($search));
        $maxResults = min(max(1, $maxResults), 1000);
        $rawMatches = [];
        $pendingMetaCandidates = [];
        $scanned = 0;
        $iterations = 0;
        $canPreloadMeta = method_exists($this->metadataStorage, 'preload');
        $cachedRootReal = ($volume->driver === 'LocalFileSystem' && $volume->path !== null && $volume->path !== '')
            ? (@realpath($volume->path) ?: null)
            : null;

        /** @var StorageAttributes $attributes */
        foreach ($filesystem->listContents($cleanBase, true) as $attributes) {
            ++$iterations;
            if ($iterations > self::MAX_RECURSIVE_ITERATIONS || count($rawMatches) >= $maxResults) {
                break;
            }

            try {
                $itemPath = $this->pathNormalizer->normalize($attributes->path());
            } catch (InvalidPathException) {
                continue;
            }

            if ($this->hasIgnoredSegment($itemPath, $profile)) {
                continue;
            }

            if (!$this->isPathAndAncestorsViewGranted($profile, $volume, $cleanBase, $itemPath)) {
                continue;
            }

            ++$scanned;
            if ($scanned > self::MAX_RECURSIVE_SCAN) {
                break;
            }

            $name = PathNormalizer::basenameUtf8($itemPath);
            if (!$this->matchesTypeFilter($attributes, $name, $typeFilter)) {
                continue;
            }

            $entry = $this->buildRawEntry($attributes, $volume, $profile, $itemPath, $name, false, false, $cachedRootReal);
            if ($query === '' || str_contains(UnicodeHelper::foldForSearch($name), $query)) {
                $rawMatches[] = $entry;
                continue;
            }

            $pendingMetaCandidates[] = $entry;
            if (count($pendingMetaCandidates) >= 250) {
                $this->flushRecursiveMetaCandidates($pendingMetaCandidates, $rawMatches, $query, $maxResults, $canPreloadMeta);
                $pendingMetaCandidates = [];
            }
        }

        if ($pendingMetaCandidates !== [] && count($rawMatches) < $maxResults) {
            $this->flushRecursiveMetaCandidates($pendingMetaCandidates, $rawMatches, $query, $maxResults, $canPreloadMeta);
        }

        foreach ($rawMatches as &$match) {
            if (($match['kind'] ?? '') === 'file' && !array_key_exists('createdAt', $match)) {
                $match['createdAt'] = $this->resolveLocalCreatedAt(
                    $volume,
                    (string) $match['path'],
                    (int) ($match['lastModified'] ?? 0),
                    $cachedRootReal,
                );
            }
        }
        unset($match);

        $needsFolderCounts = $this->shouldResolveFolderCounts($profile)
            && ($typeFilter === 'all' || $typeFilter === 'folder')
            && $this->hasFolderEntry($rawMatches);
        $folderCounts = $needsFolderCounts
            ? $this->getFolderCountMap($profile, $volume)
            : [];
        $items = $this->hydrateAndFilter($profile, $volume, $rawMatches, $typeFilter, '', $folderCounts);
        $items = $this->sortItems($items, $sortBy ?? $profile->sortBy, $sortDirection ?? $profile->sortDirection, $profile->foldersFirst, $profile->locale);

        return new DirectoryListing(
            items: $items,
            total: count($items),
            page: 1,
            perPage: max(1, count($items)),
            totalPages: 1,
            fromCache: false,
            durationMs: round((microtime(true) - $startTime) * 1000, 2),
        );
    }

    private function isPathAndAncestorsViewGranted(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $cleanBase,
        string $itemPath,
    ): bool {
        if (!$this->isViewGrantedCached($profile, $volume, $itemPath)) {
            return false;
        }
        if (!$this->hasExternalVoters || $this->authChecker === null || !$profile->isVoterEnabled()) {
            return true;
        }

        $cursor = PathNormalizer::dirnameUtf8($itemPath);
        while ($cursor !== '') {
            if (!$this->isViewGrantedCached($profile, $volume, $cursor)) {
                return false;
            }
            $parent = PathNormalizer::dirnameUtf8($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
        unset($cleanBase);

        return true;
    }

    private function matchesTypeFilter(StorageAttributes $attributes, string $name, string $typeFilter): bool
    {
        if ($typeFilter === 'all' || $typeFilter === '') {
            return true;
        }

        if ($attributes instanceof DirectoryAttributes) {
            return $typeFilter === 'folder';
        }

        return $this->detectCategory(PathNormalizer::extensionUtf8($name)) === $typeFilter;
    }

    /**
     * Returns the full folder tree for a volume in a single cached call so the sidebar
     * expands and collapses at 0ms with zero per-click AJAX requests.
     *
     * @return list<array{name: string, path: string, parent: string, color: ?string, icon: ?string, pinned: bool, itemCount: ?int}>
     */
    public function listFullTree(ProfileConfig $profile, ?string $volumeName = null, int $maxDepth = 6, bool $forceRefresh = false): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $rawTree = $this->loadCachedRawTree($profile, $volume, $forceRefresh);
        $resolved = $this->resolveVisibleTreeAndCounts($profile, $volume, $rawTree);
        $visibleNodes = $resolved['visibleNodes'];
        $folderCounts = $resolved['counts'];

        $uiNodes = [];
        $canonicalKeys = [];
        $uiCount = 0;
        foreach ($visibleNodes as $raw) {
            if ($raw['depth'] >= $maxDepth) {
                continue;
            }
            $uiNodes[] = $raw;
            $canonicalKeys[] = $raw['canonicalPath'];
            if (++$uiCount >= self::MAX_TREE_NODES) {
                break;
            }
        }

        if (method_exists($this->metadataStorage, 'preload')) {
            $this->metadataStorage->preload($canonicalKeys);
        }

        $result = [];
        foreach ($uiNodes as $raw) {
            $folderPath = $raw['path'];
            $canonical = $raw['canonicalPath'];
            $meta = $this->metadataStorage->get($canonical);
            $result[] = [
                'name' => $raw['name'],
                'path' => $folderPath,
                'parent' => $raw['parent'],
                'color' => $meta->color,
                'icon' => $meta->icon,
                'pinned' => $meta->pinned,
                'itemCount' => $folderCounts[$folderPath] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>
     */
    private function loadCachedRawTree(ProfileConfig $profile, VolumeConfig $volume, bool $forceRefresh = false): array
    {
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, self::TREE_CACHE_KEY, $profile->basePath);
        $policyFp = $this->cacheManager->buildProfilePolicyFingerprint($profile);
        $memKey = $canonicalKey . '|' . $profile->name . '|' . $policyFp . '|e' . $this->cacheManager->getInvalidationEpoch();

        if ($forceRefresh && $this->isViewGrantedCached($profile, $volume, '')) {
            unset($this->requestTreeCache[$memKey]);
            $this->requestResolvedTreeCache = [];
            $this->cacheManager->invalidateDirectory($canonicalKey);
        } elseif (isset($this->requestTreeCache[$memKey])) {
            return $this->requestTreeCache[$memKey];
        }

        $volumeScope = $this->resolveVolumeCacheScope($profile, $volume);
        $scanFn = function () use ($profile, $volume): array {
            if (!$volume->isLocalFilesystem()) {
                return $this->scanCloudFolderTree($profile, $volume, self::MAX_GRAPH_DEPTH);
            }
            $nodeCount = 0;
            $countBudget = self::MAX_COUNT_DIRS;

            return $this->scanFullFolderTree($profile, $volume, '', 0, self::MAX_GRAPH_DEPTH, $nodeCount, $countBudget)['nodes'];
        };

        try {
            $cached = $this->cacheManager->remember(
                $profile,
                $canonicalKey,
                $scanFn,
                $volume->cacheTtl,
                $volumeScope,
            );
        } catch (\Throwable) {
            return [];
        }

        $nodes = $cached['data'];
        if ($cached['hit'] && !$this->hasValidTreeCountSchema($nodes)) {
            $this->cacheManager->invalidateDirectory($canonicalKey);
            try {
                $refreshed = $this->cacheManager->remember(
                    $profile,
                    $canonicalKey,
                    $scanFn,
                    $volume->cacheTtl,
                    $volumeScope,
                );
                $nodes = $refreshed['data'];
            } catch (\Throwable) {
                return [];
            }
        }

        $storeKey = $canonicalKey . '|' . $profile->name . '|' . $policyFp . '|e' . $this->cacheManager->getInvalidationEpoch();
        $this->requestTreeCache[$storeKey] = $nodes;

        return $nodes;
    }

    /**
     * Guards against stale cached tree entries created before count/depth metadata existed.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function hasValidTreeCountSchema(array $nodes): bool
    {
        if ($nodes === []) {
            return true;
        }

        $first = $nodes[0];

        return array_key_exists('depth', $first)
            && array_key_exists('directFileCount', $first)
            && array_key_exists('directItemCount', $first)
            && array_key_exists('recursiveFileCount', $first);
    }

    /**
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $rawTree
     * @return array{visibleNodes: list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>, counts: array<string, int>}
     */
    private function resolveVisibleTreeAndCounts(ProfileConfig $profile, VolumeConfig $volume, array $rawTree): array
    {
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, self::TREE_CACHE_KEY, $profile->basePath);
        $countMode = $this->shouldResolveFolderCounts($profile) ? $this->resolveFolderCountMode($profile) : 'off';
        $cacheKey = implode('|', [
            $canonicalKey,
            'e' . $this->cacheManager->getInvalidationEpoch(),
            $profile->name,
            $this->cacheManager->buildProfilePolicyFingerprint($profile),
            $volume->name,
            $countMode,
            $profile->isVoterEnabled() ? 'v1' : 'v0',
            $this->hasExternalVoters ? 'ext' : 'int',
        ]);

        if (isset($this->requestResolvedTreeCache[$cacheKey])) {
            return $this->requestResolvedTreeCache[$cacheKey];
        }

        $visibleNodes = $this->filterVisibleRawNodes($profile, $volume, $rawTree);
        $counts = $this->shouldResolveFolderCounts($profile)
            ? $this->buildFolderCountsFromRawNodes($profile, $volume, $rawTree, $visibleNodes)
            : [];

        return $this->requestResolvedTreeCache[$cacheKey] = [
            'visibleNodes' => $visibleNodes,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function getFolderCountMap(ProfileConfig $profile, VolumeConfig $volume, bool $forceRefresh = false): array
    {
        $rawTree = $this->loadCachedRawTree($profile, $volume, $forceRefresh);

        return $this->resolveVisibleTreeAndCounts($profile, $volume, $rawTree)['counts'];
    }

    /**
     * @param list<array<string, mixed>> $rawDirEntries
     * @return array<string, int>
     */
    private function getFolderCountMapForDirectory(ProfileConfig $profile, VolumeConfig $volume, array $rawDirEntries, bool $forceRefresh = false): array
    {
        $rawTree = $this->loadCachedRawTree($profile, $volume, $forceRefresh);
        $map = $this->resolveVisibleTreeAndCounts($profile, $volume, $rawTree)['counts'];

        // On non-local cloud volumes, rely strictly on the single-pass cached tree walk and never
        // trigger uncached sequential S3 ListObjectsV2 calls per subfolder (even when depth/node caps applied).
        if (!$volume->isLocalFilesystem()) {
            return $map;
        }

        $sharedBudget = 500;
        foreach ($rawDirEntries as $entry) {
            if (($entry['kind'] ?? '') !== 'folder') {
                continue;
            }
            $folderPath = (string) ($entry['path'] ?? '');
            if ($folderPath === '' || isset($map[$folderPath])) {
                continue;
            }
            if (!$this->isViewGrantedCached($profile, $volume, $folderPath)) {
                continue;
            }
            if ($sharedBudget <= 0) {
                break;
            }
            $onDemand = $this->countFolderOnDemand($profile, $volume, $folderPath, $sharedBudget);
            if ($onDemand !== null) {
                $map[$folderPath] = $onDemand;
            }
        }

        return $map;
    }

    /**
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $rawTree
     */
    private function hasTruncatedTreeCounts(array $rawTree): bool
    {
        foreach ($rawTree as $raw) {
            if ($raw['directFileCount'] === null || $raw['recursiveFileCount'] === null) {
                return true;
            }
        }

        return false;
    }

    private function isViewGrantedCached(ProfileConfig $profile, VolumeConfig $volume, string $path): bool
    {
        $effectivePath = (!$this->hasExternalVoters || !$profile->isVoterEnabled()) ? '' : $path;
        $key = $profile->name . '|' . $volume->name . '|' . $effectivePath;
        if (isset($this->requestGrantCache[$key])) {
            return $this->requestGrantCache[$key];
        }

        if (count($this->requestGrantCache) >= 16384) {
            $this->requestGrantCache = array_slice($this->requestGrantCache, -8192, null, true);
        }

        return $this->requestGrantCache[$key] = $this->authorizer->isGranted(
            $profile,
            FileManagerPermissions::VIEW,
            $effectivePath,
            $volume->name,
        );
    }

    /**
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $rawTree
     * @return list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>
     */
    private function filterVisibleRawNodes(ProfileConfig $profile, VolumeConfig $volume, array $rawTree): array
    {
        $needsPerNodeVoter = $this->authChecker !== null && $profile->isVoterEnabled() && $this->hasExternalVoters;
        if (!$needsPerNodeVoter && !$this->isViewGrantedCached($profile, $volume, '')) {
            return [];
        }

        $visible = [];
        $allowedByPath = [];
        foreach ($rawTree as $raw) {
            $folderPath = $raw['path'];
            $parentPath = $raw['parent'];
            if ($parentPath !== '' && !isset($allowedByPath[$parentPath])) {
                continue;
            }
            // Defense-in-depth on cached trees: re-enforce current profile ignore/exclude/trash policy.
            if ($this->fileSecurityGuard->isPathIgnored($folderPath, $profile)) {
                continue;
            }
            if ($needsPerNodeVoter && !$this->isViewGrantedCached($profile, $volume, $folderPath)) {
                continue;
            }
            $allowedByPath[$folderPath] = true;
            $visible[] = $raw;
        }

        return $visible;
    }

    /**
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $rawTree
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $visibleNodes
     * @return array<string, int>
     */
    private function buildFolderCountsFromRawNodes(ProfileConfig $profile, VolumeConfig $volume, array $rawTree, array $visibleNodes): array
    {
        if (!$this->isFolderCountEnabled($profile) || $visibleNodes === []) {
            return [];
        }

        $voterFilteredTree = $this->authChecker !== null && $profile->isVoterEnabled() && $this->hasExternalVoters;
        if (!$voterFilteredTree) {
            return $this->extractDirectCountsFromRawNodes($profile, $visibleNodes);
        }

        $rawChildrenByParent = [];
        foreach ($rawTree as $raw) {
            $rawChildrenByParent[$raw['parent']][] = $raw['path'];
        }

        $isCloudTruncated = !$volume->isLocalFilesystem() && $this->hasTruncatedTreeCounts($rawTree);
        $deepBoundaryPaths = [];
        if (!$isCloudTruncated) {
            foreach ($visibleNodes as $raw) {
                $path = $raw['path'];
                $rec = $raw['recursiveFileCount'];
                $dirFiles = $raw['directFileCount'];
                $dirItems = $raw['directItemCount'];
                if (
                    ($rawChildrenByParent[$path] ?? []) === []
                    && ($rec === null || $dirFiles === null || $dirItems === null || $rec > $dirFiles || $dirItems > $dirFiles)
                ) {
                    $deepBoundaryPaths[$path] = true;
                }
            }
        }

        if (count($visibleNodes) === count($rawTree) && $deepBoundaryPaths === []) {
            return $this->extractDirectCountsFromRawNodes($profile, $visibleNodes);
        }

        $mode = $this->resolveFolderCountMode($profile);
        /** @var array<string, ?int> $visibleChildCount */
        $visibleChildCount = [];
        /** @var array<string, ?int> $directFiles */
        $directFiles = [];
        $childrenByParent = [];

        foreach ($visibleNodes as $raw) {
            $path = $raw['path'];
            $parent = $raw['parent'];
            $directFiles[$path] = $raw['directFileCount'];
            $visibleChildCount[$parent] = ($visibleChildCount[$parent] ?? 0) + 1;
            $childrenByParent[$parent][] = $path;
        }

        $filesystem = $deepBoundaryPaths !== [] ? $this->volumeRegistry->getFilesystem($profile, $volume->name) : null;
        $deepBudget = self::MAX_COUNT_DIRS;
        /** @var array<string, ?int> $recursiveTotals */
        $recursiveTotals = [];
        /** @var array<string, bool> $itemsTruncated */
        $itemsTruncated = [];
        foreach (array_reverse($visibleNodes) as $raw) {
            $path = $raw['path'];
            if (isset($deepBoundaryPaths[$path]) && $filesystem !== null) {
                $deep = $this->countDeepSubtreeVoterAware($filesystem, $profile, $volume, $path, 0, $deepBudget);
                $directFiles[$path] = $deep['directFiles'];
                $visibleChildCount[$path] = $deep['directFolders'];
                $itemsTruncated[$path] = $deep['directFolders'] === null;
                $recursiveTotals[$path] = $deep['truncated'] ? null : $deep['recursiveFiles'];
                continue;
            }
            $baseDirect = $directFiles[$path] ?? null;
            $rawRec = $raw['recursiveFileCount'];
            $rawDirItems = $raw['directItemCount'];
            $expectedRawSubdirs = ($rawDirItems !== null && $baseDirect !== null) ? max(0, $rawDirItems - $baseDirect) : null;
            $emittedRawSubdirs = count($rawChildrenByParent[$path] ?? []);
            $missingDirectSubdirs = $rawDirItems === null || ($expectedRawSubdirs !== null && $emittedRawSubdirs < $expectedRawSubdirs);
            $itemsTruncated[$path] = $missingDirectSubdirs;
            if ($baseDirect === null || $rawRec === null || $missingDirectSubdirs) {
                $recursiveTotals[$path] = null;
                continue;
            }
            $sum = $baseDirect;
            $hasUnknownChild = false;
            foreach ($childrenByParent[$path] ?? [] as $childPath) {
                $childTotal = $recursiveTotals[$childPath] ?? null;
                if ($childTotal === null) {
                    $hasUnknownChild = true;
                    break;
                }
                $sum += $childTotal;
            }
            $recursiveTotals[$path] = $hasUnknownChild ? null : $sum;
        }

        $map = [];
        foreach ($visibleNodes as $raw) {
            $path = $raw['path'];
            $direct = $directFiles[$path] ?? null;
            $childCount = $visibleChildCount[$path] ?? 0;
            $resolved = match ($mode) {
                'files' => $direct,
                'items' => ($direct !== null && !($itemsTruncated[$path] ?? false)) ? ($direct + $childCount) : null,
                default => $recursiveTotals[$path] ?? null,
            };
            if ($resolved !== null) {
                $map[$path] = $resolved;
            }
        }

        return $map;
    }

    /**
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $visibleNodes
     * @return array<string, int>
     */
    private function extractDirectCountsFromRawNodes(ProfileConfig $profile, array $visibleNodes): array
    {
        $map = [];
        foreach ($visibleNodes as $raw) {
            $count = $this->resolveFolderCount($profile, $raw);
            if ($count !== null) {
                $map[$raw['path']] = $count;
            }
        }

        return $map;
    }

    private function resolveFolderCountMode(ProfileConfig $profile): string
    {
        return is_string($profile->ui['folder_count_mode'] ?? null)
            ? $profile->ui['folder_count_mode']
            : 'recursive_files';
    }

    private function isFolderCountEnabled(ProfileConfig $profile): bool
    {
        return ($profile->ui['show_folder_item_count'] ?? true) === true;
    }

    private function shouldResolveFolderCounts(ProfileConfig $profile): bool
    {
        return $this->isFolderCountEnabled($profile);
    }

    /**
     * @param array<string, mixed> $rawNode
     */
    private function resolveFolderCount(ProfileConfig $profile, array $rawNode): ?int
    {
        if (!$this->isFolderCountEnabled($profile)) {
            return null;
        }

        $mode = $this->resolveFolderCountMode($profile);
        $value = match ($mode) {
            'files' => $rawNode['directFileCount'] ?? null,
            'items' => $rawNode['directItemCount'] ?? null,
            default => $rawNode['recursiveFileCount'] ?? null,
        };

        return is_int($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param list<array<string, mixed>> $rawMatches
     */
    private function flushRecursiveMetaCandidates(
        array $candidates,
        array &$rawMatches,
        string $foldedQuery,
        int $maxResults,
        bool $canPreloadMeta,
    ): void {
        if ($canPreloadMeta && method_exists($this->metadataStorage, 'preload')) {
            $keys = [];
            foreach ($candidates as $cand) {
                $keys[] = (string) $cand['canonicalPath'];
            }
            $this->metadataStorage->preload($keys);
        }

        foreach ($candidates as $cand) {
            if (count($rawMatches) >= $maxResults) {
                break;
            }
            $canonical = (string) $cand['canonicalPath'];
            $meta = $this->metadataStorage->get($canonical);
            if (!$meta->isEmpty() && $meta->matchesSearch($foldedQuery)) {
                $rawMatches[] = $cand;
            }
        }
    }

    private function resolveVolumeCacheScope(ProfileConfig $profile, VolumeConfig $volume): string
    {
        $rootCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath);

        return $this->cacheManager->resolveVolumeScopeForRoot($rootCanonical);
    }

    /**
     * @return array{
     *     nodes: list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>,
     *     directFiles: ?int,
     *     directFolders: ?int,
     *     recursiveFiles: ?int,
     *     truncated: bool
     * }
     */
    private function scanFullFolderTree(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $dirPath,
        int $depth,
        int $maxDepth,
        int &$nodeCount = 0,
        int &$countBudget = self::MAX_COUNT_DIRS,
    ): array {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $childFolders = [];
        $directFiles = 0;
        $truncated = false;

        try {
            /** @var StorageAttributes $attributes */
            foreach ($filesystem->listContents($dirPath, false) as $attributes) {
                try {
                    $itemPath = $this->pathNormalizer->normalize($attributes->path());
                } catch (InvalidPathException) {
                    continue;
                }

                $name = PathNormalizer::basenameUtf8($itemPath);
                if ($this->fileSecurityGuard->isChildSegmentIgnored($itemPath, $name, $profile)) {
                    continue;
                }

                if (!$attributes instanceof DirectoryAttributes) {
                    ++$directFiles;
                    continue;
                }

                if ($nodeCount >= self::MAX_GRAPH_NODES) {
                    $truncated = true;
                    continue;
                }
                ++$nodeCount;

                $childFolders[] = [
                    'name' => $name,
                    'path' => $itemPath,
                    'parent' => $dirPath,
                    'canonicalPath' => $this->pathNormalizer->buildCanonicalKey($volume, $itemPath, $profile->basePath),
                ];
            }
        } catch (\Throwable $e) {
            if ($depth === 0) {
                throw $e;
            }

            return [
                'nodes' => [],
                'directFiles' => null,
                'directFolders' => null,
                'recursiveFiles' => null,
                'truncated' => true,
            ];
        }

        usort($childFolders, static fn (array $a, array $b): int => UnicodeHelper::compareNatural($a['name'], $b['name'], $profile->locale));

        $allNodes = [];
        $recursiveFiles = $directFiles;
        $localTruncated = $truncated;
        $subtreeTruncated = false;
        $hasNullSubtree = $localTruncated;

        foreach ($childFolders as $folder) {
            if ($depth + 1 < $maxDepth) {
                $sub = $this->scanFullFolderTree($profile, $volume, $folder['path'], $depth + 1, $maxDepth, $nodeCount, $countBudget);
            } else {
                $sub = $this->countDeepSubtree($filesystem, $profile, $folder['path'], 0, $countBudget);
            }

            if ($sub['truncated']) {
                $subtreeTruncated = true;
            }
            $nodeRec = $sub['truncated'] ? null : $sub['recursiveFiles'];
            if ($nodeRec === null) {
                $hasNullSubtree = true;
            } else {
                $recursiveFiles += $nodeRec;
            }
            $dirItems = ($sub['directFiles'] !== null && $sub['directFolders'] !== null)
                ? ($sub['directFiles'] + $sub['directFolders'])
                : null;

            $allNodes[] = [
                'name' => $folder['name'],
                'path' => $folder['path'],
                'parent' => $folder['parent'],
                'canonicalPath' => $folder['canonicalPath'],
                'depth' => $depth,
                'directFileCount' => $sub['directFiles'],
                'directItemCount' => $dirItems,
                'recursiveFileCount' => $nodeRec,
            ];
            foreach ($sub['nodes'] ?? [] as $descendant) {
                $allNodes[] = $descendant;
            }
        }

        return [
            'nodes' => $allNodes,
            'directFiles' => $directFiles,
            'directFolders' => $localTruncated ? null : count($childFolders),
            'recursiveFiles' => ($localTruncated || $subtreeTruncated || $hasNullSubtree) ? null : $recursiveFiles,
            'truncated' => $localTruncated || $subtreeTruncated,
        ];
    }

    /**
     * Bounded recursive counter for folders deeper than sidebar display depth.
     *
     * @return array{directFiles: ?int, directFolders: ?int, recursiveFiles: ?int, truncated: bool}
     */
    private function countDeepSubtree(
        \League\Flysystem\FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $dirPath,
        int $relDepth,
        int &$budget,
    ): array {
        if ($relDepth > self::MAX_COUNT_DEPTH || $budget <= 0) {
            return ['directFiles' => null, 'directFolders' => null, 'recursiveFiles' => null, 'truncated' => true];
        }
        --$budget;

        $directFiles = 0;
        $childDirs = [];

        try {
            /** @var StorageAttributes $attributes */
            foreach ($filesystem->listContents($dirPath, false) as $attributes) {
                try {
                    $itemPath = $this->pathNormalizer->normalize($attributes->path());
                } catch (InvalidPathException) {
                    continue;
                }
                if ($this->fileSecurityGuard->isChildSegmentIgnored($itemPath, PathNormalizer::basenameUtf8($itemPath), $profile)) {
                    continue;
                }
                if (!$attributes instanceof DirectoryAttributes) {
                    ++$directFiles;
                } else {
                    $childDirs[] = $itemPath;
                }
            }
        } catch (\Throwable) {
            return ['directFiles' => null, 'directFolders' => null, 'recursiveFiles' => null, 'truncated' => true];
        }

        $recursiveFiles = $directFiles;
        $truncated = false;
        foreach ($childDirs as $childDir) {
            $sub = $this->countDeepSubtree($filesystem, $profile, $childDir, $relDepth + 1, $budget);
            if ($sub['truncated'] || $sub['recursiveFiles'] === null) {
                $truncated = true;
            } else {
                $recursiveFiles += $sub['recursiveFiles'];
            }
        }

        return [
            'directFiles' => $directFiles,
            'directFolders' => count($childDirs),
            'recursiveFiles' => $truncated ? null : $recursiveFiles,
            'truncated' => $truncated,
        ];
    }

    /**
     * Computes folder item count when browsing deeper than MAX_TREE_NODES or maxDepth.
     */
    private function countFolderOnDemand(ProfileConfig $profile, VolumeConfig $volume, string $folderPath, int &$budget): ?int
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $mode = $this->resolveFolderCountMode($profile);
        $summary = $this->countDeepSubtreeVoterAware($filesystem, $profile, $volume, $folderPath, 0, $budget);

        return match ($mode) {
            'files' => $summary['directFiles'],
            'items' => ($summary['directFiles'] !== null && $summary['directFolders'] !== null)
                ? ($summary['directFiles'] + $summary['directFolders'])
                : null,
            default => $summary['truncated'] ? null : $summary['recursiveFiles'],
        };
    }

    /**
     * @return array{directFiles: ?int, directFolders: ?int, recursiveFiles: ?int, truncated: bool}
     */
    private function countDeepSubtreeVoterAware(
        \League\Flysystem\FilesystemOperator $filesystem,
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $dirPath,
        int $relDepth,
        int &$budget,
    ): array {
        if ($relDepth > self::MAX_COUNT_DEPTH || $budget <= 0) {
            return ['directFiles' => null, 'directFolders' => null, 'recursiveFiles' => null, 'truncated' => true];
        }
        --$budget;

        $directFiles = 0;
        $childDirs = [];
        try {
            /** @var StorageAttributes $attributes */
            foreach ($filesystem->listContents($dirPath, false) as $attributes) {
                try {
                    $itemPath = $this->pathNormalizer->normalize($attributes->path());
                } catch (InvalidPathException) {
                    continue;
                }
                if ($this->fileSecurityGuard->isChildSegmentIgnored($itemPath, PathNormalizer::basenameUtf8($itemPath), $profile)) {
                    continue;
                }
                if (!$attributes instanceof DirectoryAttributes) {
                    if (!$this->hasExternalVoters || $this->isViewGrantedCached($profile, $volume, $itemPath)) {
                        ++$directFiles;
                    }
                } elseif ($this->isViewGrantedCached($profile, $volume, $itemPath)) {
                    $childDirs[] = $itemPath;
                }
            }
        } catch (\Throwable) {
            return ['directFiles' => null, 'directFolders' => null, 'recursiveFiles' => null, 'truncated' => true];
        }

        $recursiveFiles = $directFiles;
        $truncated = false;
        foreach ($childDirs as $childDir) {
            $sub = $this->countDeepSubtreeVoterAware($filesystem, $profile, $volume, $childDir, $relDepth + 1, $budget);
            if ($sub['truncated'] || $sub['recursiveFiles'] === null) {
                $truncated = true;
            } else {
                $recursiveFiles += $sub['recursiveFiles'];
            }
        }

        return [
            'directFiles' => $directFiles,
            'directFolders' => count($childDirs),
            'recursiveFiles' => $truncated ? null : $recursiveFiles,
            'truncated' => $truncated,
        ];
    }

    /**
     * Single-pass cloud/object-storage tree scanner (avoids O(F) sequential HTTP ListObjectsV2 calls).
     *
     * @return list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}>
     */
    private function scanCloudFolderTree(ProfileConfig $profile, VolumeConfig $volume, int $maxDepth): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $folders = [];
        $directFiles = [];
        $directFolders = [];
        $seen = 0;
        $truncated = false;

        foreach ($filesystem->listContents('', true) as $attributes) {
            if (++$seen > self::MAX_CLOUD_TREE_ENTRIES) {
                $truncated = true;
                break;
            }
            try {
                $itemPath = $this->pathNormalizer->normalize($attributes->path());
            } catch (InvalidPathException) {
                continue;
            }
            if ($itemPath === '' || $this->hasIgnoredSegment($itemPath, $profile)) {
                continue;
            }
            $this->registerCloudAncestors($profile, $volume, PathNormalizer::dirnameUtf8($itemPath), $folders, $directFolders);
            if ($attributes instanceof DirectoryAttributes) {
                $this->registerCloudAncestors($profile, $volume, $itemPath, $folders, $directFolders);
            } else {
                $parent = PathNormalizer::dirnameUtf8($itemPath);
                $directFiles[$parent] = ($directFiles[$parent] ?? 0) + 1;
            }
        }

        $childrenByParent = [];
        foreach ($folders as $path => $meta) {
            $childrenByParent[$meta['parent']][] = $meta;
        }
        $recursiveTotals = [];
        $nodes = [];
        $nodeCount = 0;
        $this->flattenSortedCloudNodes('', 0, $maxDepth, $profile->locale, $childrenByParent, $directFiles, $directFolders, $recursiveTotals, $nodes, $nodeCount, $truncated);

        return $nodes;
    }

    /**
     * @param array<string, array{name: string, path: string, parent: string, canonicalPath: string}> $folders
     * @param array<string, int> $directFolders
     */
    private function registerCloudAncestors(ProfileConfig $profile, VolumeConfig $volume, string $folderPath, array &$folders, array &$directFolders): void
    {
        $cursor = $folderPath;
        while ($cursor !== '' && !isset($folders[$cursor])) {
            $parent = PathNormalizer::dirnameUtf8($cursor);
            $folders[$cursor] = [
                'name' => PathNormalizer::basenameUtf8($cursor),
                'path' => $cursor,
                'parent' => $parent,
                'canonicalPath' => $this->pathNormalizer->buildCanonicalKey($volume, $cursor, $profile->basePath),
            ];
            $directFolders[$parent] = ($directFolders[$parent] ?? 0) + 1;
            $cursor = $parent;
        }
    }

    /**
     * @param array<string, list<array{name: string, path: string, parent: string, canonicalPath: string}>> $childrenByParent
     * @param array<string, int> $directFiles
     * @param array<string, int> $directFolders
     * @param array<string, int|null> $recursiveTotals
     * @param list<array{name: string, path: string, parent: string, canonicalPath: string, depth: int, directFileCount: ?int, directItemCount: ?int, recursiveFileCount: ?int}> $out
     */
    private function flattenSortedCloudNodes(
        string $parentPath,
        int $depth,
        int $maxDepth,
        ?string $locale,
        array $childrenByParent,
        array $directFiles,
        array $directFolders,
        array &$recursiveTotals,
        array &$out,
        int &$nodeCount,
        bool $cloudTruncated = false,
    ): ?int {
        $children = $childrenByParent[$parentPath] ?? [];
        usort($children, static fn (array $a, array $b): int => UnicodeHelper::compareNatural($a['name'], $b['name'], $locale));

        /** @var int|null $sumRecursive */
        $sumRecursive = $cloudTruncated ? null : ($directFiles[$parentPath] ?? 0);
        foreach ($children as $folder) {
            $path = $folder['path'];
            $emitNode = $depth < $maxDepth && $nodeCount < self::MAX_GRAPH_NODES;
            if ($emitNode) {
                ++$nodeCount;
            }
            $childOut = [];
            $childRec = $this->flattenSortedCloudNodes($path, $depth + 1, $maxDepth, $locale, $childrenByParent, $directFiles, $directFolders, $recursiveTotals, $childOut, $nodeCount, $cloudTruncated);
            if ($childRec === null) {
                $sumRecursive = null;
            } elseif ($sumRecursive !== null) {
                $sumRecursive += $childRec;
            }
            if ($emitNode) {
                $out[] = [
                    'name' => $folder['name'],
                    'path' => $path,
                    'parent' => $folder['parent'],
                    'canonicalPath' => $folder['canonicalPath'],
                    'depth' => $depth,
                    'directFileCount' => $cloudTruncated ? null : ($directFiles[$path] ?? 0),
                    'directItemCount' => $cloudTruncated ? null : (($directFiles[$path] ?? 0) + ($directFolders[$path] ?? 0)),
                    'recursiveFileCount' => $cloudTruncated ? null : $childRec,
                ];
            }
            foreach ($childOut as $descendant) {
                $out[] = $descendant;
            }
        }
        $recursiveTotals[$parentPath] = $sumRecursive;

        return $sumRecursive;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanRawDirectory(ProfileConfig $profile, VolumeConfig $volume, string $cleanPath): array
    {
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $entries = [];
        $cachedRootReal = ($volume->driver === 'LocalFileSystem' && $volume->path !== null && $volume->path !== '')
            ? (@realpath($volume->path) ?: null)
            : null;

        /** @var StorageAttributes $attributes */
        foreach ($filesystem->listContents($cleanPath, false) as $attributes) {
            try {
                $itemPath = $this->pathNormalizer->normalize($attributes->path());
            } catch (InvalidPathException) {
                continue;
            }

            $name = PathNormalizer::basenameUtf8($itemPath);

            if ($this->hasIgnoredSegment($itemPath, $profile)) {
                continue;
            }

            $entries[] = $this->buildRawEntry($attributes, $volume, $profile, $itemPath, $name, true, false, $cachedRootReal);
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRawEntry(
        StorageAttributes $attributes,
        VolumeConfig $volume,
        ProfileConfig $profile,
        string $itemPath,
        string $name,
        bool $includeCreatedAt = true,
        bool $includePublicUrl = false,
        ?string $cachedRootReal = null,
    ): array {
        $canonicalPath = $this->pathNormalizer->buildCanonicalKey($volume, $itemPath, $profile->basePath);

        if ($attributes instanceof DirectoryAttributes) {
            return [
                'kind' => 'folder',
                'name' => $name,
                'path' => $itemPath,
                'canonicalPath' => $canonicalPath,
                'lastModified' => $attributes->lastModified() ?? 0,
            ];
        }

        $fileSize = $attributes instanceof FileAttributes ? ($attributes->fileSize() ?? 0) : 0;
        $ext = PathNormalizer::extensionUtf8($name);
        $lastModified = $attributes->lastModified() ?? 0;

        $entry = [
            'kind' => 'file',
            'name' => $name,
            'path' => $itemPath,
            'canonicalPath' => $canonicalPath,
            'size' => $fileSize,
            'extension' => $ext,
            'category' => $this->detectCategory($ext),
            'lastModified' => $lastModified,
        ];
        if ($includeCreatedAt) {
            $entry['createdAt'] = $this->resolveLocalCreatedAt($volume, $itemPath, $lastModified, $cachedRootReal);
        }
        if ($includePublicUrl) {
            $entry['publicUrl'] = $this->buildPublicUrl($volume, $itemPath);
        }

        return $entry;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function hasFolderEntry(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (($entry['kind'] ?? '') === 'folder') {
                return true;
            }
        }

        return false;
    }

    private function hasIgnoredSegment(string $cleanPath, ProfileConfig $profile): bool
    {
        return $this->fileSecurityGuard->isPathIgnored($cleanPath, $profile);
    }

    private function detectCategory(string $extension): string
    {
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }

        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }

        return 'doc';
    }

    private function resolveLocalCreatedAt(VolumeConfig $volume, string $relativePath, int $knownMtime = 0, ?string $cachedRootReal = null): ?int
    {
        if ($volume->driver !== 'LocalFileSystem' || $volume->path === null || $volume->path === '') {
            return null;
        }

        $rootReal = $cachedRootReal ?? realpath($volume->path);
        if (!is_string($rootReal) || $rootReal === '') {
            return null;
        }

        $absPath = rtrim($volume->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
        if (is_link($absPath)) {
            return null;
        }

        $targetReal = realpath($absPath);
        if ($targetReal === false) {
            return null;
        }

        $rootPrefix = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($targetReal, $rootPrefix)) {
            return null;
        }

        $ctime = @filectime($targetReal);
        if (!is_int($ctime) || $ctime <= 0) {
            return null;
        }

        if (\PHP_OS_FAMILY !== 'Windows') {
            $mtime = $knownMtime > 0 ? $knownMtime : @filemtime($targetReal);
            if (is_int($mtime) && $mtime > 0 && $ctime > $mtime) {
                return $mtime;
            }
        }

        return $ctime;
    }

    private function buildPublicUrl(VolumeConfig $volume, string $relativePath): ?string
    {
        if ($volume->private || $volume->urlPrefix === null || $volume->urlPrefix === '') {
            return null;
        }

        $trimmed = ltrim($relativePath, '/');
        if ($trimmed === '') {
            return rtrim($volume->urlPrefix, '/');
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $trimmed)));

        return rtrim($volume->urlPrefix, '/') . '/' . $encodedPath;
    }

    /**
     * @param list<array<string, mixed>> $rawItems
     * @param array<string, int> $folderCounts
     * @return list<FolderItem|FileItem>
     */
    private function hydrateAndFilter(
        ProfileConfig $profile,
        VolumeConfig $volume,
        array $rawItems,
        string $typeFilter,
        string $search,
        array $folderCounts = [],
    ): array {
        $query = UnicodeHelper::foldForSearch(UnicodeHelper::trimSpaces($search));
        if (method_exists($this->metadataStorage, 'preload')) {
            $canonicalKeys = [];
            foreach ($rawItems as $raw) {
                $canonicalKeys[] = (string) $raw['canonicalPath'];
            }
            $this->metadataStorage->preload($canonicalKeys);
        }
        $result = [];

        foreach ($rawItems as $raw) {
            $itemPath = (string) $raw['path'];
            if ($this->fileSecurityGuard->isPathIgnored($itemPath, $profile)) {
                continue;
            }
            if (!$this->isViewGrantedCached($profile, $volume, $itemPath)) {
                continue;
            }

            $name = (string) $raw['name'];
            $canonical = (string) $raw['canonicalPath'];
            if ($query !== '' && !$this->matchesNameOrMetadata($name, $canonical, $query)) {
                continue;
            }

            if ($raw['kind'] === 'folder') {
                if ($typeFilter !== 'all' && $typeFilter !== 'folder') {
                    continue;
                }
                $result[] = new FolderItem(
                    name: $name,
                    path: $itemPath,
                    canonicalPath: $canonical,
                    metadata: $this->metadataStorage->get($canonical),
                    lastModified: (int) $raw['lastModified'],
                    itemCount: $folderCounts[$itemPath] ?? null,
                );
            } else {
                $category = (string) $raw['category'];
                if ($typeFilter !== 'all' && $typeFilter !== $category) {
                    continue;
                }
                $meta = $this->metadataStorage->get($canonical);
                $result[] = new FileItem(
                    name: $name,
                    path: $itemPath,
                    canonicalPath: $canonical,
                    size: (int) $raw['size'],
                    extension: (string) $raw['extension'],
                    category: $category,
                    lastModified: (int) $raw['lastModified'],
                    publicUrl: is_string($raw['publicUrl'] ?? null) ? $raw['publicUrl'] : $this->buildPublicUrl($volume, $itemPath),
                    createdAt: is_int($raw['createdAt'] ?? null) ? $raw['createdAt'] : null,
                    metadata: $meta->isEmpty() ? null : $meta,
                );
            }
        }

        return $result;
    }

    private function matchesNameOrMetadata(string $name, string $canonicalPath, string $normalizedQuery): bool
    {
        $foldedQuery = UnicodeHelper::foldForSearch($normalizedQuery);
        if (str_contains(UnicodeHelper::foldForSearch($name), $foldedQuery)) {
            return true;
        }

        $meta = $this->metadataStorage->get($canonicalPath);

        return !$meta->isEmpty() && $meta->matchesSearch($foldedQuery);
    }

    /**
     * @param list<FolderItem|FileItem> $items
     * @return list<FolderItem|FileItem>
     */
    private function sortItems(
        array $items,
        string $sortBy,
        string $sortDirection,
        bool $foldersFirst,
        ?string $locale = null,
    ): array {
        $multiplier = strtolower($sortDirection) === 'desc' ? -1 : 1;

        usort($items, static function (FolderItem|FileItem $a, FolderItem|FileItem $b) use ($sortBy, $multiplier, $foldersFirst, $locale): int {
            $aIsFolder = $a instanceof FolderItem;
            $bIsFolder = $b instanceof FolderItem;

            if ($foldersFirst && $aIsFolder !== $bIsFolder) {
                return $aIsFolder ? -1 : 1;
            }

            $cmp = match ($sortBy) {
                'size' => ($a instanceof FileItem ? $a->size : 0) <=> ($b instanceof FileItem ? $b->size : 0),
                'date' => $a->lastModified <=> $b->lastModified,
                'type' => (($a instanceof FileItem ? $a->extension : '') <=> ($b instanceof FileItem ? $b->extension : ''))
                    ?: UnicodeHelper::compareNatural($a->name, $b->name, $locale),
                default => UnicodeHelper::compareNatural($a->name, $b->name, $locale),
            };

            return $cmp * $multiplier;
        });

        return $items;
    }
}
