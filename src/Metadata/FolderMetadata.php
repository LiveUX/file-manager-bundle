<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Metadata;

use LiveUX\FileManagerBundle\Util\UnicodeHelper;

/**
 * Immutable metadata record stored for both folders (color, icon, pinned)
 * and files (alt, title, caption, tags, focalPoint).
 */
final readonly class FolderMetadata
{
    /**
     * @param list<string>                   $tags
     * @param array{x: float, y: float}|null $focalPoint Normalized focal point coordinates in [0.0..1.0]
     */
    public function __construct(
        public ?string $color = null,
        public ?string $icon = null,
        public bool $pinned = false,
        public ?string $alt = null,
        public ?string $title = null,
        public ?string $caption = null,
        public array $tags = [],
        public ?array $focalPoint = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return ($this->color === null || $this->color === '')
            && ($this->icon === null || $this->icon === '' || $this->icon === 'folder')
            && !$this->pinned
            && ($this->alt === null || $this->alt === '')
            && ($this->title === null || $this->title === '')
            && ($this->caption === null || $this->caption === '')
            && $this->tags === []
            && $this->focalPoint === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'color' => $this->color,
            'icon' => $this->icon,
            'pinned' => $this->pinned,
        ];

        if ($this->alt !== null && $this->alt !== '') {
            $data['alt'] = $this->alt;
        }
        if ($this->title !== null && $this->title !== '') {
            $data['title'] = $this->title;
        }
        if ($this->caption !== null && $this->caption !== '') {
            $data['caption'] = $this->caption;
        }
        if ($this->tags !== []) {
            $data['tags'] = $this->tags;
        }
        if ($this->focalPoint !== null) {
            $data['focalPoint'] = $this->focalPoint;
        }

        return $data;
    }

    /**
     * Compact client-facing payload for files (excludes folder-only fields).
     *
     * @return array{alt: ?string, title: ?string, caption: ?string, tags: list<string>, focalPoint: ?array{x: float, y: float}}
     */
    public function toFileMetaArray(): array
    {
        return [
            'alt' => $this->alt,
            'title' => $this->title,
            'caption' => $this->caption,
            'tags' => $this->tags,
            'focalPoint' => $this->focalPoint,
        ];
    }

    public function matchesSearch(string $normalizedQuery): bool
    {
        $foldedQuery = UnicodeHelper::foldForSearch($normalizedQuery);
        if ($foldedQuery === '') {
            return true;
        }

        $tagOnly = str_starts_with($foldedQuery, '#');
        $needle = $tagOnly ? ltrim($foldedQuery, '#') : $foldedQuery;
        if ($needle === '') {
            return false;
        }

        foreach ($this->tags as $tag) {
            if (str_contains(UnicodeHelper::foldForSearch($tag), $needle)) {
                return true;
            }
        }

        if ($tagOnly) {
            return false;
        }

        foreach ([$this->alt, $this->title, $this->caption] as $field) {
            if ($field !== null && str_contains(UnicodeHelper::foldForSearch($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawColor = is_string($data['color'] ?? null) ? trim($data['color']) : '';
        $safeColor = $rawColor !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $rawColor) === 1 ? $rawColor : null;
        $rawIcon = is_string($data['icon'] ?? null) ? trim($data['icon']) : '';
        $safeIcon = $rawIcon !== '' && preg_match('/^[a-zA-Z0-9:_-]{1,40}$/', $rawIcon) === 1 ? $rawIcon : null;

        return new self(
            color: $safeColor,
            icon: $safeIcon,
            pinned: filter_var($data['pinned'] ?? false, FILTER_VALIDATE_BOOL),
            alt: self::sanitizeText($data['alt'] ?? null, 255),
            title: self::sanitizeText($data['title'] ?? null, 255),
            caption: self::sanitizeText($data['caption'] ?? null, 1000),
            tags: self::sanitizeTags($data['tags'] ?? []),
            focalPoint: self::sanitizeFocalPoint($data['focalPoint'] ?? null),
        );
    }

    private static function sanitizeText(mixed $raw, int $maxLength): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $scrubbed = UnicodeHelper::toNfc(mb_scrub($raw, 'UTF-8'));
        $stripped = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}]+/u',
            '',
            $scrubbed,
        ) ?? '';
        $cleaned = self::truncateAndCleanInvisibleEdges($stripped, $maxLength);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * @return list<string>
     */
    public static function sanitizeTags(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,،、，]+/u', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $unique = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $scrubbed = UnicodeHelper::toNfc(mb_scrub($item, 'UTF-8'));
            $stripped = preg_replace(
                '/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}#\x{FF03}]+/u',
                '',
                $scrubbed,
            ) ?? '';
            $tag = self::truncateAndCleanInvisibleEdges($stripped, 40);
            $tag = str_replace('İ', 'i', $tag);
            $tag = mb_strtolower($tag, 'UTF-8');
            if ($tag !== '') {
                $unique[$tag] = $tag;
            }
            if (count($unique) >= 20) {
                break;
            }
        }

        return array_values($unique);
    }

    /**
     * @return array{x: float, y: float}|null
     */
    public static function sanitizeFocalPoint(mixed $raw): ?array
    {
        if (!is_array($raw) || !isset($raw['x'], $raw['y']) || !is_numeric($raw['x']) || !is_numeric($raw['y'])) {
            return null;
        }

        $x = round(min(1.0, max(0.0, (float) $raw['x'])), 4);
        $y = round(min(1.0, max(0.0, (float) $raw['y'])), 4);

        return ['x' => $x, 'y' => $y];
    }

    private static function truncateAndCleanInvisibleEdges(string $value, int $maxLength): string
    {
        $cleaned = self::stripEdgeZwnjZwj(UnicodeHelper::trimSpaces($value));
        if ($cleaned === '') {
            return '';
        }

        if (function_exists('grapheme_substr')) {
            $truncated = grapheme_substr($cleaned, 0, $maxLength);
            $cleaned = is_string($truncated) ? $truncated : mb_substr($cleaned, 0, $maxLength, 'UTF-8');
        } else {
            $cleaned = mb_substr($cleaned, 0, $maxLength, 'UTF-8');
        }

        return self::stripEdgeZwnjZwj(UnicodeHelper::trimSpaces($cleaned));
    }

    private static function stripEdgeZwnjZwj(string $value): string
    {
        return preg_replace('/^[\s\p{Z}\x{200C}\x{200D}]+|[\s\p{Z}\x{200C}\x{200D}]+$/u', '', $value) ?? '';
    }
}
