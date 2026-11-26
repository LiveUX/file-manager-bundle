<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Util;

/**
 * Parses human-readable byte sizes (e.g. "2M", "10MB", "1.5G", "512KB", "2048")
 * and PHP ini size values (returning 0 for "-1", "", or invalid inputs).
 */
final readonly class ByteSizeParser
{
    public static function parse(?string $raw): int
    {
        if ($raw === null) {
            return 0;
        }

        $clean = trim($raw);
        if ($clean === '' || $clean === '-1') {
            return 0;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT])?B?$/i', $clean, $matches) !== 1) {
            return 0;
        }

        $value = (float) $matches[1];
        if ($value <= 0.0) {
            return 0;
        }

        $unit = strtoupper($matches[2] ?? '');

        $bytes = match ($unit) {
            'T' => $value * 1099511627776,
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => $value,
        };

        if (!is_finite($bytes) || $bytes >= (float) PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int) round($bytes);
    }
}
