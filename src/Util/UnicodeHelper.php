<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Util;

use Collator;
use Normalizer;
use Throwable;

/**
 * Centralized global Unicode, NFC/NFKC normalization, invisible-skeleton detection,
 * UTS-46 IDNA host canonicalization, BOM-aware text decoding, and locale-aware collation helper.
 */
final class UnicodeHelper
{
    /**
     * @var array<string, Collator>
     */
    private static array $collators = [];

    public static function toNfc(string $value): string
    {
        if ($value === '' || !class_exists(Normalizer::class)) {
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $value;
    }

    public static function toNfkc(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return self::toNfc(self::foldFullwidthAscii($value));
    }

    public static function trimSpaces(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? trim($value);
    }

    public static function hasForbiddenControls(string $value): bool
    {
        // Block C0/C1 controls (\p{Cc}), line/paragraph separators (\p{Zl}, \p{Zp}),
        // BiDi marks/overrides/isolates, and interlinear/tag controls.
        return preg_match(
            '/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}]/u',
            $value,
        ) === 1;
    }

    public static function foldDotEquivalents(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return str_replace(["\u{3002}", "\u{FF0E}", "\u{FF61}", "\u{FE52}", "\u{2024}"], '.', $value);
    }

    public static function stripIgnorableFillers(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_replace(
            '/[\x{00AD}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{E0100}-\x{E01EF}]+/u',
            '',
            $value,
        ) ?? $value;
    }

    public static function trimEdgeInvisiblesAndDots(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $trimmed = $value;
        do {
            $before = $trimmed;
            $trimmed = preg_replace(
                '/^[\s\p{Z}\p{M}\x{00AD}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{E0100}-\x{E01EF}]+|[\s\p{Z}\x{00AD}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FEFF}\x{FFA0}.\x{FF0E}\x{3002}\x{FF61}\x{FE52}\x{2024}]+$/u',
                '',
                $trimmed,
            ) ?? $trimmed;

            // Preserve trailing Emoji/CJK variation selectors (VS1..VS16, IVS) ONLY when attached to a surviving visible base char.
            if (preg_match('/^(.+?)([\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]+)$/su', $trimmed, $m) === 1) {
                $baseTail = $m[1];
                $cleanedBase = preg_replace(
                    '/[\s\p{Z}\x{00AD}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FEFF}\x{FFA0}.\x{FF0E}\x{3002}\x{FF61}\x{FE52}\x{2024}]+$/u',
                    '',
                    $baseTail,
                ) ?? $baseTail;
                if ($cleanedBase !== $baseTail) {
                    $trimmed = $cleanedBase;
                }
            }
        } while ($trimmed !== $before && $trimmed !== '');

        return $trimmed;
    }

    public static function hasEdgeInvisibleOrTrailingDot(string $segment): bool
    {
        if ($segment === '') {
            return false;
        }

        if (preg_match(
            '/^[\s\p{Z}\x{00AD}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{E0100}-\x{E01EF}]/u',
            $segment,
        ) === 1) {
            return true;
        }

        // Allow trailing Emoji/CJK variation selectors (VS1..VS16, IVS) ONLY when they attach to a safe visible char.
        $baseTail = preg_replace('/[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]+$/u', '', $segment) ?? $segment;
        if ($baseTail === '') {
            return true;
        }

        if (
            str_ends_with($baseTail, '.')
            || str_ends_with($baseTail, "\u{FF0E}")
            || str_ends_with($baseTail, "\u{3002}")
            || str_ends_with($baseTail, "\u{FF61}")
            || str_ends_with($baseTail, "\u{FE52}")
            || str_ends_with($baseTail, "\u{2024}")
        ) {
            return true;
        }

        return preg_match(
            '/[\s\p{Z}\x{00AD}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FEFF}\x{FFA0}]$/u',
            $baseTail,
        ) === 1;
    }

    public static function visibleSkeleton(string $value): string
    {
        return preg_replace(
            '/[\s\p{Z}\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180D}\x{180F}\x{206A}-\x{206F}\x{2800}\x{3164}\x{FFA0}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]+/u',
            '',
            $value,
        ) ?? $value;
    }

    public static function foldForSearch(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // NFKC folds fullwidth ASCII, halfwidth/fullwidth Katakana (ｶﾀｶﾅ <-> カタカナ), and canonical NFD/NFC marks.
        $nfkc = self::toNfkc($value);
        $folded = mb_convert_case($nfkc, \MB_CASE_FOLD, 'UTF-8');

        return strtr($folded, [
            "\u{0307}" => '',
            'ı' => 'i',
            'ς' => 'σ',
            "\u{0643}" => "\u{06A9}", // Arabic Kaf -> Persian/Urdu Keheh
            "\u{064A}" => "\u{06CC}", // Arabic Yeh -> Persian/Urdu Farsi Yeh
        ]);
    }

    public static function compareNatural(string $a, string $b, ?string $locale = null): int
    {
        if ($a === $b) {
            return 0;
        }

        $collator = self::resolveCollator($locale);
        if ($collator !== null) {
            $cmp = $collator->compare(self::toNfc($a), self::toNfc($b));
            if (is_int($cmp)) {
                return $cmp;
            }
        }

        return strnatcmp(self::foldForSearch($a), self::foldForSearch($b));
    }

    public static function truncateStemBytes(string $stem, int $maxBytes = 200): string
    {
        if (strlen($stem) <= $maxBytes) {
            return self::trimEdgeInvisiblesAndDots($stem);
        }

        $cut = '';
        if (function_exists('grapheme_extract')) {
            $extracted = @grapheme_extract($stem, $maxBytes, \GRAPHEME_EXTR_MAXBYTES);
            if (is_string($extracted)) {
                $cut = $extracted;
            }
        }

        if ($cut === '') {
            $cut = mb_strcut($stem, 0, $maxBytes, 'UTF-8');
            $cut = preg_replace('/[\p{M}\x{200C}\x{200D}]+$/u', '', $cut) ?? $cut;
            $cut = self::stripTrailingDanglingRegionalIndicator($cut);
        }

        $cleaned = self::trimEdgeInvisiblesAndDots($cut);

        return self::trimEdgeInvisiblesAndDots(rtrim($cleaned, '.-_'));
    }

    private static function stripTrailingDanglingRegionalIndicator(string $value): string
    {
        if ($value === '' || preg_match('/[\x{1F1E6}-\x{1F1FF}]+$/u', $value, $match) !== 1) {
            return $value;
        }

        $count = mb_strlen($match[0], 'UTF-8');
        if ($count % 2 === 1) {
            return mb_substr($value, 0, mb_strlen($value, 'UTF-8') - 1, 'UTF-8');
        }

        return $value;
    }

    public static function mbUcfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }

    public static function mbLcfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }

    public static function canonicalizeHostToAscii(string $rawHost): ?string
    {
        $trimmed = trim($rawHost);
        if ($trimmed === '' || self::hasForbiddenControls($trimmed)) {
            return null;
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $inner = substr($trimmed, 1, -1);

            return filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . strtolower($inner) . ']'
                : null;
        }

        if (str_contains($trimmed, ':')) {
            return filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? strtolower($trimmed) : null;
        }

        $host = str_replace(["\u{3002}", "\u{FF0E}", "\u{FF61}"], '.', $trimmed);
        $host = self::foldFullwidthAscii($host);
        $host = rtrim($host, '.');
        if ($host === '') {
            return null;
        }

        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? \INTL_IDNA_VARIANT_UTS46 : 1;
            $flags = 0;
            if (defined('IDNA_NONTRANSITIONAL_TO_ASCII')) {
                $flags |= \IDNA_NONTRANSITIONAL_TO_ASCII;
            }
            if (defined('IDNA_CHECK_BIDI')) {
                $flags |= \IDNA_CHECK_BIDI;
            }
            if (defined('IDNA_CHECK_CONTEXTJ')) {
                $flags |= \IDNA_CHECK_CONTEXTJ;
            }
            $ascii = @idn_to_ascii($host, $flags, $variant);
            if (!is_string($ascii) || $ascii === '') {
                return null;
            }
            $host = rtrim($ascii, '.');
        }

        $lower = strtolower($host);
        if (
            $lower === ''
            || strlen($lower) > 253
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $lower) !== 1
        ) {
            return null;
        }

        return $lower;
    }

    public static function decodeTextPreview(string $rawBytes, int $maxUtf8Bytes): ?string
    {
        if ($rawBytes === '') {
            return '';
        }

        $utf8 = self::convertBomEncodedBytes($rawBytes);
        if (str_contains($utf8, "\0")) {
            return null;
        }

        $scrubbed = mb_scrub($utf8, 'UTF-8');
        if (strlen($scrubbed) > $maxUtf8Bytes) {
            return mb_strcut($scrubbed, 0, $maxUtf8Bytes, 'UTF-8');
        }

        return $scrubbed;
    }

    private static function convertBomEncodedBytes(string $rawBytes): string
    {
        $fixedWidthBoms = [
            ["\x00\x00\xFE\xFF", 4, 4, 'UTF-32BE'],
            ["\xFF\xFE\x00\x00", 4, 4, 'UTF-32LE'],
            ["\xFE\xFF", 2, 2, 'UTF-16BE'],
            ["\xFF\xFE", 2, 2, 'UTF-16LE'],
        ];

        foreach ($fixedWidthBoms as [$bom, $bomLen, $unitBytes, $encoding]) {
            if (!str_starts_with($rawBytes, $bom)) {
                continue;
            }
            $body = substr($rawBytes, $bomLen);
            $evenLen = strlen($body) - (strlen($body) % $unitBytes);
            if ($evenLen === 0) {
                return '';
            }

            return @mb_convert_encoding(substr($body, 0, $evenLen), 'UTF-8', $encoding);
        }

        if (str_starts_with($rawBytes, "\xEF\xBB\xBF")) {
            return substr($rawBytes, 3);
        }

        return $rawBytes;
    }

    private static function foldFullwidthAscii(string $value): string
    {
        return preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static function (array $m): string {
                $cp = mb_ord($m[0], 'UTF-8');

                return ($cp >= 0xFF01 && $cp <= 0xFF5E) ? chr($cp - 0xFEE0) : $m[0];
            },
            $value,
        ) ?? $value;
    }

    private static function resolveCollator(?string $locale): ?Collator
    {
        if (!class_exists(Collator::class)) {
            return null;
        }

        $normLocale = is_string($locale) && trim($locale) !== ''
            ? str_replace('-', '_', trim($locale))
            : 'en';

        if (array_key_exists($normLocale, self::$collators)) {
            return self::$collators[$normLocale];
        }

        try {
            $collator = new Collator($normLocale);
            $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
            if (count(self::$collators) >= 64) {
                $oldestKey = array_key_first(self::$collators);
                if ($oldestKey !== null) {
                    unset(self::$collators[$oldestKey]);
                }
            }
            self::$collators[$normLocale] = $collator;

            return $collator;
        } catch (Throwable) {
            return null;
        }
    }
}
