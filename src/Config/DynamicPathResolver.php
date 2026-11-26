<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Config;

use DateTimeImmutable;
use Stringable;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class DynamicPathResolver
{
    private const string HASH_MARKER = '--h-';

    private const array RESERVED_NAMESPACE_IDS = [
        'guest',
        'user',
    ];

    private const array WINDOWS_RESERVED_IDS = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    public function __construct(
        private ?Security $security = null,
    ) {
    }

    public static function containsDynamicTokens(string $template): bool
    {
        return str_contains($template, '{user_id}')
            || str_contains($template, '{Y}')
            || str_contains($template, '{m}')
            || str_contains($template, '{d}');
    }

    public static function containsUserIdToken(string $template): bool
    {
        return str_contains($template, '{user_id}');
    }

    public static function containsDateTokens(string $template): bool
    {
        return str_contains($template, '{Y}')
            || str_contains($template, '{m}')
            || str_contains($template, '{d}');
    }

    public function resolve(
        string $template,
        ?DateTimeImmutable $now = null,
        ?string $overrideUserId = null,
    ): string {
        if (!str_contains($template, '{')) {
            return $template;
        }

        $date = $now ?? new DateTimeImmutable();
        $safeUser = $overrideUserId !== null
            ? $this->sanitizeOverrideIdentifier($overrideUserId, 'guest')
            : $this->resolveUserId();
        $replacements = [
            '{Y}' => $date->format('Y'),
            '{m}' => $date->format('m'),
            '{d}' => $date->format('d'),
            '{user_id}' => $safeUser,
        ];

        return strtr($template, $replacements);
    }

    public function getCurrentUserId(): string
    {
        return $this->resolveUserId();
    }

    private function resolveUserId(): string
    {
        $user = $this->security?->getUser();
        if ($user === null) {
            return 'guest';
        }

        if (method_exists($user, 'getId')) {
            $id = $user->getId();
            if (is_int($id) || is_string($id) || $id instanceof Stringable) {
                $rawId = (string) $id;
                if ($rawId !== '') {
                    return $this->sanitizeIdentifier($rawId, 'user');
                }
            }
        }

        return $this->sanitizeIdentifier($user->getUserIdentifier(), 'user');
    }

    /**
     * Validates pre-resolved tenant identifiers (e.g. HMAC-signed ShareController `u` tokens)
     * idempotently without re-hashing values that already carry the reserved `--h-` marker.
     */
    private function sanitizeOverrideIdentifier(string $raw, string $fallback): string
    {
        if ($raw === '') {
            return $fallback;
        }

        if (
            preg_match('/^[a-z0-9_-]{1,128}$/', $raw) === 1
            && trim($raw, '_-') !== ''
            && !in_array($raw, self::WINDOWS_RESERVED_IDS, true)
        ) {
            return $raw;
        }

        return $this->sanitizeIdentifier($raw, $fallback);
    }

    /**
     * Maps live authenticated user identifiers into a collision-free filesystem segment.
     * The fast-path space (`[a-z0-9_-]` without `--h-` and excluding `'guest'`/`'user'`)
     * and the hashed space (`<prefix>--h-<16-hex>`) are strictly disjoint so an attacker
     * cannot register a pre-computed hash string to access another tenant's directory.
     */
    private function sanitizeIdentifier(string $raw, string $fallback): string
    {
        if ($raw === '') {
            return $fallback;
        }

        if (
            preg_match('/^[a-z0-9_-]{1,128}$/', $raw) === 1
            && trim($raw, '_-') !== ''
            && !str_contains($raw, self::HASH_MARKER)
            && !in_array($raw, self::RESERVED_NAMESPACE_IDS, true)
            && !in_array($raw, self::WINDOWS_RESERVED_IDS, true)
        ) {
            return $raw;
        }

        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $raw), '_'));
        if (in_array($slug, self::WINDOWS_RESERVED_IDS, true)) {
            $slug = 'id_' . $slug;
        }
        $prefix = $slug !== '' ? substr($slug, 0, 64) : $fallback;

        return $prefix . self::HASH_MARKER . substr(hash('sha256', $raw), 0, 16);
    }
}
