<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;

/**
 * Coordinates opt-in pre-delete usage checks across all host-registered
 * AssetUsageCheckerInterface services and enforces profile-level warn/block policies.
 */
final readonly class AssetUsageGuardService
{
    public const string POLICY_WARN = 'warn';
    public const string POLICY_BLOCK = 'block';
    private const int DEFAULT_MAX_REFERENCES = 20;

    /**
     * @var list<AssetUsageCheckerInterface>
     */
    private array $checkers;

    /**
     * @param iterable<AssetUsageCheckerInterface> $checkers
     */
    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        iterable $checkers = [],
    ) {
        $resolved = [];
        foreach ($checkers as $checker) {
            $resolved[] = $checker;
        }
        $this->checkers = $resolved;
    }

    public function hasCheckers(): bool
    {
        return $this->checkers !== [];
    }

    public function isEnabledForProfile(ProfileConfig $profile): bool
    {
        if (($profile->usageGuard['enabled'] ?? false) !== true) {
            return false;
        }

        return $this->hasCheckers();
    }

    public function getPolicy(ProfileConfig $profile): string
    {
        $raw = $profile->usageGuard['policy'] ?? self::POLICY_WARN;

        return $raw === self::POLICY_BLOCK ? self::POLICY_BLOCK : self::POLICY_WARN;
    }

    /**
     * Inspects one or more paths before deletion and returns aggregated usage details.
     *
     * @param list<string> $paths
     *
     * @return array{
     *     enabled: bool,
     *     policy: string,
     *     blocked: bool,
     *     totalUsages: int,
     *     truncated: bool,
     *     items: list<array{
     *         path: string,
     *         isFolder: bool,
     *         references: list<array{label: string, type: ?string, editUrl: ?string, entityId: ?string}>
     *     }>
     * }
     */
    public function inspectPaths(ProfileConfig $profile, array $paths, ?string $volumeName = null): array
    {
        $policy = $this->getPolicy($profile);
        if (!$this->isEnabledForProfile($profile)) {
            return $this->emptyReport($policy, false);
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $maxReferences = $this->resolveMaxReferences($profile);

        $items = [];
        $totalUsages = 0;
        $truncated = false;
        $seenPaths = [];

        foreach ($paths as $rawPath) {
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            if ($cleanPath === '' || isset($seenPaths[$cleanPath])) {
                continue;
            }
            $seenPaths[$cleanPath] = true;

            $isFolder = $filesystem->directoryExists($cleanPath);
            $collected = $this->collectPathReferences($profile, $volume, $cleanPath, $isFolder);
            if ($collected === []) {
                continue;
            }

            $remaining = $maxReferences - $totalUsages;
            if ($remaining <= 0) {
                $truncated = true;
                break;
            }

            if (count($collected) > $remaining) {
                $collected = array_slice($collected, 0, $remaining);
                $truncated = true;
            }

            $totalUsages += count($collected);
            $items[] = [
                'path' => $cleanPath,
                'isFolder' => $isFolder,
                'references' => array_map(
                    static fn (AssetUsageReference $ref): array => $ref->toArray(),
                    $collected,
                ),
            ];
        }

        return [
            'enabled' => true,
            'policy' => $policy,
            'blocked' => $policy === self::POLICY_BLOCK && $totalUsages > 0,
            'totalUsages' => $totalUsages,
            'truncated' => $truncated,
            'items' => $items,
        ];
    }

    /**
     * Server-side defense-in-depth: prevents deleting an in-use asset when policy is "block",
     * even if the API endpoint is invoked directly without calling checkUsage first.
     */
    public function assertDeletable(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $cleanPath,
        bool $isFolder,
    ): void {
        $this->assertAllowed($profile, $volume, $cleanPath, $isFolder, 'delete');
    }

    /**
     * Server-side defense-in-depth for delete, rename, or move actions on in-use assets.
     */
    public function assertAllowed(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $cleanPath,
        bool $isFolder,
        string $action = 'delete',
    ): void {
        if (
            !$this->isEnabledForProfile($profile)
            || $this->getPolicy($profile) !== self::POLICY_BLOCK
            || ($profile->usageGuard['enforce_server_side'] ?? true) !== true
        ) {
            return;
        }

        $references = $this->collectPathReferences($profile, $volume, $cleanPath, $isFolder);
        if ($references === []) {
            return;
        }

        $name = PathNormalizer::basenameUtf8($cleanPath);
        $count = count($references);

        [$fallbackMessage, $translationKey] = match ($action) {
            'rename' => [
                sprintf('Cannot rename "%s" because it is currently referenced in %d place(s).', $name, $count),
                'errors.rename_blocked_in_use',
            ],
            'move' => [
                sprintf('Cannot move "%s" because it is currently referenced in %d place(s).', $name, $count),
                'errors.move_blocked_in_use',
            ],
            'overwrite' => [
                sprintf('Cannot overwrite "%s" because it is currently referenced in %d place(s).', $name, $count),
                'errors.overwrite_blocked_in_use',
            ],
            default => [
                sprintf('Cannot delete "%s" because it is currently referenced in %d place(s).', $name, $count),
                'errors.delete_blocked_in_use',
            ],
        };

        throw new FileManagerException($fallbackMessage, $translationKey, ['%name%' => $name, '%count%' => $count]);
    }

    /**
     * @return list<AssetUsageReference>
     */
    private function collectPathReferences(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $cleanPath,
        bool $isFolder,
    ): array {
        $unique = [];

        foreach ($this->checkers as $checker) {
            foreach ($checker->findUsages($profile, $volume, $cleanPath, $isFolder) as $reference) {
                $sanitized = $this->sanitizeReference($reference);
                if ($sanitized === null) {
                    continue;
                }

                $dedupeKey = $this->buildDedupeKey($sanitized);
                $unique[$dedupeKey] = $sanitized;
            }
        }

        return array_values($unique);
    }

    private function sanitizeReference(AssetUsageReference $reference): ?AssetUsageReference
    {
        $label = $this->sanitizeTextField($reference->label, 255);
        if ($label === null) {
            return null;
        }

        $type = $this->sanitizeTextField($reference->type, 80);
        $editUrl = $this->sanitizeEditUrl($reference->editUrl);
        $entityId = $this->sanitizeTextField($reference->entityId, 120);

        return new AssetUsageReference($label, $type, $editUrl, $entityId);
    }

    private function sanitizeTextField(?string $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }

        $nfc = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc(mb_scrub($value, 'UTF-8'));
        $clean = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}]+/u', '', $nfc) ?? '';
        $clean = preg_replace('/^[\s\p{Z}\x{200C}\x{200D}]+|[\s\p{Z}\x{200C}\x{200D}]+$/u', '', $clean) ?? '';
        if ($clean === '') {
            return null;
        }

        if (function_exists('grapheme_substr')) {
            $sub = grapheme_substr($clean, 0, $maxLen);
            $clean = is_string($sub) ? $sub : mb_substr($clean, 0, $maxLen, 'UTF-8');
        } else {
            $clean = mb_substr($clean, 0, $maxLen, 'UTF-8');
        }

        $clean = preg_replace('/^[\s\p{Z}\x{200C}\x{200D}]+|[\s\p{Z}\x{200C}\x{200D}]+$/u', '', $clean) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function sanitizeEditUrl(?string $rawUrl): ?string
    {
        if ($rawUrl === null) {
            return null;
        }

        $trimmed = trim(mb_scrub($rawUrl, 'UTF-8'));
        if ($trimmed === '' || \LiveUX\FileManagerBundle\Util\UnicodeHelper::hasForbiddenControls($trimmed)) {
            return null;
        }

        if (str_starts_with($trimmed, '/')) {
            if (preg_match('/^\/(?![\/\\\\\s]|%2[fF]|%5[cC]|%25(?:2[fF]|5[cC]))/', $trimmed) !== 1) {
                return null;
            }
            $beforeQuery = explode('?', explode('#', $trimmed, 2)[0], 2)[0];
            if (str_contains($beforeQuery, '\\')) {
                return null;
            }
            $decodedPath = rawurldecode($beforeQuery);
            if (
                str_starts_with($decodedPath, '//')
                || str_starts_with($decodedPath, '/\\')
                || str_contains($decodedPath, '\\')
            ) {
                return null;
            }

            return $trimmed;
        }

        $beforeQuery = explode('?', explode('#', $trimmed, 2)[0], 2)[0];
        if (str_contains($beforeQuery, '\\')) {
            return null;
        }

        if (preg_match('#^https?://#i', $trimmed) !== 1) {
            return null;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'http' || $scheme === 'https') {
            return $trimmed;
        }

        return null;
    }

    private function buildDedupeKey(AssetUsageReference $reference): string
    {
        return implode('|', [
            mb_strtolower($reference->type ?? '', 'UTF-8'),
            mb_strtolower($reference->label, 'UTF-8'),
            $reference->entityId ?? '',
            $reference->editUrl ?? '',
        ]);
    }

    private function resolveMaxReferences(ProfileConfig $profile): int
    {
        $raw = $profile->usageGuard['max_references'] ?? self::DEFAULT_MAX_REFERENCES;

        return is_int($raw) && $raw >= 1 && $raw <= 200 ? $raw : self::DEFAULT_MAX_REFERENCES;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     policy: string,
     *     blocked: bool,
     *     totalUsages: int,
     *     truncated: bool,
     *     items: list<array{
     *         path: string,
     *         isFolder: bool,
     *         references: list<array{label: string, type: ?string, editUrl: ?string, entityId: ?string}>
     *     }>
     * }
     */
    private function emptyReport(string $policy, bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'policy' => $policy,
            'blocked' => false,
            'totalUsages' => 0,
            'truncated' => false,
            'items' => [],
        ];
    }
}
