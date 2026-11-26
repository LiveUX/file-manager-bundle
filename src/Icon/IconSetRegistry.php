<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Icon;

use LiveUX\FileManagerBundle\FileManagerBundle;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Loads SVG icon sets from resources/icons/<set_name>/ (or an explicitly configured custom directory path)
 * across three namespaces:
 *   - icons  (file & folder icons: folder, file, pdf, excel, word, zip, video, audio, code)
 *   - ui     (all toolbar, sidebar, context menu, inspector & modal chrome icons — overridable via <dir>/ui/<key>.svg)
 *   - badges (folder badge overlay icons — overridable & extensible via <dir>/badges/<key>.svg)
 */
final class IconSetRegistry implements ResetInterface
{
    private const array BUILTIN_SETS = ['classic', 'windows11', 'macos', 'linux', 'minimal', 'vscode'];
    private const array FILE_ICON_KEYS = ['folder', 'file', 'pdf', 'excel', 'word', 'zip', 'video', 'audio', 'code'];
    private const array UI_ICON_KEYS = [
        'menu', 'back', 'forward', 'up', 'refresh', 'search', 'search_deep',
        'grid', 'list', 'trash', 'new_folder', 'upload', 'download',
        'fullscreen', 'exit_fullscreen', 'settings', 'close', 'open',
        'preview', 'edit_image', 'tag', 'archive', 'link', 'share',
        'move', 'duplicate', 'cut', 'copy', 'paste', 'color',
        'select_all', 'sort', 'rename', 'star', 'disk', 'cloud',
        'aws_s3', 'cloudflare_r2', 'supabase', 'backblaze_b2', 'minio', 'gcs', 'sftp',
        'cloud_upload', 'quota', 'more', 'sun', 'moon', 'sparkles',
    ];
    private const array BADGE_ICON_KEYS = [
        'image', 'video', 'doc', 'bag', 'star', 'lock', 'pen',
        'cart', 'users', 'tag', 'archive', 'code', 'heart', 'globe',
    ];

    /**
     * @var array<string, array{
     *     name: string,
     *     defaultFolderColor: string,
     *     icons: array<string, string>,
     *     ui: array<string, string>,
     *     badges: array<string, string>
     * }>
     */
    private array $cache = [];

    public function __construct(
        private readonly string $bundleIconsDir,
        private readonly SvgSanitizer $svgSanitizer = new SvgSanitizer(),
        private readonly ?CacheItemPoolInterface $cachePool = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getAvailableSets(): array
    {
        return self::BUILTIN_SETS;
    }

    /**
     * @return list<string>
     */
    public function getUiIconKeys(): array
    {
        return self::UI_ICON_KEYS;
    }

    /**
     * @return list<string>
     */
    public function getBadgeIconKeys(): array
    {
        return self::BADGE_ICON_KEYS;
    }

    public function isBuiltinSet(string $iconSet): bool
    {
        return in_array(trim($iconSet), self::BUILTIN_SETS, true);
    }

    /**
     * @return array{
     *     name: string,
     *     defaultFolderColor: string,
     *     icons: array<string, string>,
     *     ui: array<string, string>,
     *     badges: array<string, string>
     * }
     */
    public function getIconSetPayload(string $iconSet = 'classic', bool $allowCustomDirectory = false): array
    {
        $normalized = trim($iconSet) !== '' ? trim($iconSet) : 'classic';
        $cacheKey = ($allowCustomDirectory ? 'custom:' : 'builtin:') . $normalized;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        [$dir, $isCustom] = $this->resolveSetDirectory($normalized, $allowCustomDirectory);
        if (!$isCustom && $this->cachePool !== null) {
            $poolKey = 'liveux_fm_iconset_' . md5(FileManagerBundle::VERSION . '|' . $dir);
            try {
                $item = $this->cachePool->getItem($poolKey);
                if ($item->isHit()) {
                    $cached = $item->get();
                    if (is_array($cached) && isset($cached['icons'], $cached['ui'], $cached['badges'])) {
                        /** @var array{name: string, defaultFolderColor: string, icons: array<string, string>, ui: array<string, string>, badges: array<string, string>} $cached */
                        return $this->cache[$cacheKey] = $cached;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $fallbackFileDir = $this->bundleIconsDir . DIRECTORY_SEPARATOR . 'classic';
        $fallbackUiDir = $this->bundleIconsDir . DIRECTORY_SEPARATOR . '_ui';
        $fallbackBadgeDir = $this->bundleIconsDir . DIRECTORY_SEPARATOR . '_folder_badges';

        $meta = $this->readMeta($dir, $fallbackFileDir);
        $icons = $this->loadIconMap(self::FILE_ICON_KEYS, $dir, $fallbackFileDir, $isCustom);
        $ui = $this->loadExtensibleMap(self::UI_ICON_KEYS, $dir . DIRECTORY_SEPARATOR . 'ui', $fallbackUiDir, $isCustom);
        $badges = $this->loadExtensibleMap(self::BADGE_ICON_KEYS, $dir . DIRECTORY_SEPARATOR . 'badges', $fallbackBadgeDir, $isCustom);

        $payload = [
            'name' => $isCustom ? PathNormalizer::basenameUtf8($dir) : ($this->isBuiltinSet($normalized) ? $normalized : 'classic'),
            'defaultFolderColor' => $meta['defaultFolderColor'],
            'icons' => $icons,
            'ui' => $ui,
            'badges' => $badges,
        ];

        if (!$isCustom && $this->cachePool !== null && isset($poolKey)) {
            try {
                $item = $this->cachePool->getItem($poolKey);
                $item->set($payload);
                $item->expiresAfter(86400);
                $this->cachePool->save($item);
            } catch (\Throwable) {
            }
        }

        return $this->cache[$cacheKey] = $payload;
    }

    /**
     * Renders a sized, CSP-safe inline SVG icon from the active icon set for Twig templates.
     */
    public function renderInlineIcon(
        string $key,
        string $iconSet = 'classic',
        int $size = 14,
        string $group = 'ui',
        string $class = '',
        bool $allowCustomDirectory = true,
        string $fallbackKey = '',
    ): string {
        $payload = $this->getIconSetPayload($iconSet, $allowCustomDirectory);
        $bucket = match ($group) {
            'icons' => $payload['icons'],
            'badges' => $payload['badges'],
            default => $payload['ui'],
        };

        $svg = $bucket[$key] ?? '';
        if ($svg === '' && $fallbackKey !== '') {
            $svg = $bucket[$fallbackKey] ?? '';
        }
        if ($svg === '') {
            return '';
        }

        return $this->applySvgAttributes($svg, max(8, min(128, $size)), $class);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function loadIconMap(array $keys, string $dir, string $fallbackDir, bool $sanitizeCustom): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$key] = $this->readSvg($dir, $fallbackDir, $key, $sanitizeCustom);
        }

        return $map;
    }

    /**
     * Loads built-in icons plus any extra *.svg files inside a custom icon set subdirectory (ui/ or badges/).
     *
     * @param list<string> $builtinKeys
     *
     * @return array<string, string>
     */
    private function loadExtensibleMap(array $builtinKeys, string $customSubDir, string $fallbackDir, bool $isCustom): array
    {
        $map = $this->loadIconMap($builtinKeys, $customSubDir, $fallbackDir, $isCustom);
        if (!$isCustom || !is_dir($customSubDir)) {
            return $map;
        }

        $entries = @scandir($customSubDir);
        if (!is_array($entries)) {
            return $map;
        }

        $extraCount = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || PathNormalizer::extensionUtf8($entry) !== 'svg') {
                continue;
            }
            $slug = PathNormalizer::stemUtf8($entry);
            if (isset($map[$slug]) || preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $slug) !== 1) {
                continue;
            }
            $filePath = $customSubDir . DIRECTORY_SEPARATOR . $entry;
            $content = $this->readBoundedCustomSvgFile($filePath);
            if ($content !== null && trim($content) !== '') {
                $sanitized = $this->svgSanitizer->sanitize($content);
                if ($sanitized !== '') {
                    $map[$slug] = $sanitized;
                    if (++$extraCount >= 200) {
                        break;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveSetDirectory(string $iconSet, bool $allowCustomDirectory): array
    {
        if ($this->isBuiltinSet($iconSet)) {
            $builtinPath = $this->bundleIconsDir . DIRECTORY_SEPARATOR . $iconSet;
            if (is_dir($builtinPath)) {
                return [$builtinPath, false];
            }
        }

        if (
            $allowCustomDirectory
            && (str_contains($iconSet, '/') || str_contains($iconSet, '\\'))
            && is_dir($iconSet)
        ) {
            return [$iconSet, true];
        }

        return [$this->bundleIconsDir . DIRECTORY_SEPARATOR . 'classic', false];
    }

    /**
     * @return array{defaultFolderColor: string}
     */
    private function readMeta(string $dir, string $fallbackDir): array
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'meta.json';
        if (!is_file($path)) {
            $path = $fallbackDir . DIRECTORY_SEPARATOR . 'meta.json';
        }

        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $color = is_array($decoded) && is_string($decoded['defaultFolderColor'] ?? null) ? trim($decoded['defaultFolderColor']) : '';
            if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
                return ['defaultFolderColor' => $color];
            }
        }

        return ['defaultFolderColor' => '#2563eb'];
    }

    private function readSvg(string $dir, string $fallbackDir, string $key, bool $sanitize): string
    {
        $customFile = $dir . DIRECTORY_SEPARATOR . $key . '.svg';
        $content = $sanitize ? $this->readBoundedCustomSvgFile($customFile) : (is_file($customFile) ? (@file_get_contents($customFile) ?: null) : null);
        if (is_string($content)) {
            $resolved = $sanitize ? $this->svgSanitizer->sanitize($content) : trim($content);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $fallbackFile = $fallbackDir . DIRECTORY_SEPARATOR . $key . '.svg';
        if ($fallbackFile === $customFile || !is_file($fallbackFile)) {
            return '';
        }

        $fallbackContent = file_get_contents($fallbackFile);

        return is_string($fallbackContent) ? trim($fallbackContent) : '';
    }

    private function readBoundedCustomSvgFile(string $filePath): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }

        $size = @filesize($filePath);
        if (!is_int($size) || $size <= 0 || $size > 262144) {
            return null;
        }

        $content = @file_get_contents($filePath, false, null, 0, 262145);
        if (!is_string($content) || strlen($content) > 262144) {
            return null;
        }

        return $content;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function applySvgAttributes(string $svg, int $size, string $class): string
    {
        $trimmed = trim($svg);
        $safeClass = preg_replace('/[^a-zA-Z0-9 _-]/', '', trim($class)) ?? '';

        // Only modify the root <svg ...> opening tag so inner elements (e.g. <rect width=".." height="..">) remain intact.
        $result = preg_replace_callback(
            '/^<((?:[a-zA-Z_][\w.-]*:)?svg)(?=[\s\/>])([^>]*)>/i',
            static function (array $matches) use ($size, $safeClass): string {
                $tagName = $matches[1];
                $attrs = $matches[2];
                $mergedClasses = [];
                if (preg_match('/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $cm) === 1) {
                    $rawExisting = $cm[1] !== '' ? $cm[1] : ($cm[2] ?? '');
                    $cleanExisting = preg_replace('/[^a-zA-Z0-9 _-]/', '', $rawExisting) ?? '';
                    foreach (preg_split('/\s+/', trim($cleanExisting)) ?: [] as $token) {
                        if ($token !== '') {
                            $mergedClasses[$token] = $token;
                        }
                    }
                }
                foreach (preg_split('/\s+/', trim($safeClass)) ?: [] as $token) {
                    if ($token !== '') {
                        $mergedClasses[$token] = $token;
                    }
                }

                $rootAttrs = preg_replace('/\s+(?:width|height|aria-hidden|class)\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $attrs) ?? $attrs;
                $extraAttr = sprintf(' width="%d" height="%d" aria-hidden="true"', $size, $size);
                if ($mergedClasses !== []) {
                    $extraAttr .= sprintf(' class="%s"', htmlspecialchars(implode(' ', $mergedClasses), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }

                return '<' . $tagName . $extraAttr . $rootAttrs . '>';
            },
            $trimmed,
            1,
        );

        return is_string($result) ? $result : $trimmed;
    }
}
