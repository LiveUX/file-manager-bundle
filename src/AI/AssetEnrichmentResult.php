<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\AI;

use LiveUX\FileManagerBundle\Metadata\FolderMetadata;

/**
 * Immutable DTO representing AI-generated editorial metadata for a file asset.
 */
final readonly class AssetEnrichmentResult
{
    public ?string $alt;
    public ?string $title;
    public ?string $caption;

    /** @var list<string> */
    public array $tags;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        ?string $alt = null,
        ?string $title = null,
        ?string $caption = null,
        array $tags = [],
        public ?string $provider = null,
        public ?string $model = null,
    ) {
        $this->alt = self::sanitizeText($alt, 255);
        $this->title = self::sanitizeText($title, 255);
        $this->caption = self::sanitizeText($caption, 1000);
        $this->tags = FolderMetadata::sanitizeTags($tags);
    }

    public function isEmpty(): bool
    {
        return $this->alt === null
            && $this->title === null
            && $this->caption === null
            && $this->tags === [];
    }

    /**
     * @return array{alt: ?string, title: ?string, caption: ?string, tags: list<string>, provider: ?string, model: ?string}
     */
    public function toArray(): array
    {
        return [
            'alt' => $this->alt,
            'title' => $this->title,
            'caption' => $this->caption,
            'tags' => $this->tags,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }

    private static function sanitizeText(?string $value, int $maxLen): ?string
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
}
