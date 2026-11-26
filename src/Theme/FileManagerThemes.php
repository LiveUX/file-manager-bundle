<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Theme;

final readonly class FileManagerThemes
{
    public const array ALL = [
        'bootstrap5',
        'tailwind',
        'shadcn',
        'flowbite',
        'daisyui',
        'tabler',
        'preline',
        'material',
        'unstyled',
    ];

    public static function isBuiltin(string $theme): bool
    {
        return in_array(trim($theme), self::ALL, true);
    }

    public static function isSafeThemeToken(string $theme): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,40}$/', trim($theme)) === 1;
    }

    public static function sanitizeDynamic(mixed $rawTheme): ?string
    {
        if (!is_string($rawTheme)) {
            return null;
        }

        $trimmed = trim($rawTheme);

        return self::isBuiltin($trimmed) ? $trimmed : null;
    }
}
