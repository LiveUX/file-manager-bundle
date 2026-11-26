<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Twig\Extension;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Stringable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class FileManagerTwigExtension extends AbstractExtension
{
    private const array PREVIEW_IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico',
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?IconSetRegistry $iconSetRegistry = null,
        private readonly ?MessageTranslator $messageTranslator = null,
        private readonly ?ConfigProviderInterface $configProvider = null,
        private readonly ?VolumeRegistry $volumeRegistry = null,
        private readonly PathNormalizer $pathNormalizer = new PathNormalizer(),
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('liveux_fm_trans', [$this, 'translate']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('liveux_fm_thumb_url', [$this, 'getThumbnailUrl']),
            new TwigFunction('liveux_fm_download_url', [$this, 'getDownloadUrl']),
            new TwigFunction('liveux_fm_icon', [$this, 'renderIcon'], ['is_safe' => ['html']]),
            new TwigFunction('liveux_fm_trans', [$this, 'translate']),
            new TwigFunction('liveux_fm_describe_field_item', [$this, 'describeFieldItem']),
            new TwigFunction('liveux_fm_normalize_field_items', [$this, 'normalizeFieldItems']),
            new TwigFunction('liveux_fm_bcp47_locale', [$this, 'getBcp47Locale']),
            new TwigFunction('liveux_fm_is_rtl', [$this, 'isRtlLocale']),
        ];
    }

    public function getBcp47Locale(?string $locale = null): string
    {
        return MessageTranslator::toBcp47($locale);
    }

    public function isRtlLocale(?string $locale = null): bool
    {
        return MessageTranslator::isRtlLocale($locale);
    }

    /**
     * Normalizes EasyAdmin / Twig field values (iterables, JSON array strings like `'["a.jpg"]'`, `'[]'`, or scalars).
     *
     * @return list<mixed>
     */
    public function normalizeFieldItems(mixed $rawValue): array
    {
        if ($rawValue === null || $rawValue === '' || $rawValue === []) {
            return [];
        }

        if (is_iterable($rawValue)) {
            $list = [];
            foreach ($rawValue as $item) {
                if ($item !== null && $item !== '') {
                    $list[] = $item;
                }
            }

            return $list;
        }

        if (is_string($rawValue)) {
            $trimmed = trim($rawValue);
            if ($trimmed === '' || $trimmed === '[]') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return array_values(array_filter($decoded, static fn (mixed $v): bool => $v !== null && $v !== ''));
                }
            }
        }

        return [$rawValue];
    }

    /**
     * Translates a bundle key with guaranteed English ("en") fallback.
     *
     * @param array<string, int|string> $params
     */
    public function translate(string $key, array $params = [], ?string $locale = null): string
    {
        $translator = $this->messageTranslator ?? new MessageTranslator();

        return $translator->trans($key, $params, $locale);
    }

    public function getThumbnailUrl(string $profile, ?string $volume, string $path): string
    {
        return $this->urlGenerator->generate('liveux_file_manager_thumb', [
            'profile' => $profile,
            'volume' => $volume,
            'path' => $path,
        ]);
    }

    public function getDownloadUrl(string $profile, ?string $volume, string $path, bool $inline = false): string
    {
        return $this->urlGenerator->generate('liveux_file_manager_download', [
            'profile' => $profile,
            'volume' => $volume,
            'path' => $path,
            'inline' => $inline ? 1 : 0,
        ]);
    }

    public function renderIcon(
        string $key,
        string $iconSet = 'classic',
        int $size = 14,
        string $class = '',
        string $group = 'ui',
        string $fallbackKey = '',
    ): string {
        if ($this->iconSetRegistry === null) {
            return '';
        }

        return $this->iconSetRegistry->renderInlineIcon($key, $iconSet, $size, $group, $class, true, $fallbackKey);
    }

    /**
     * Safely extracts UTF-8 display name, safe link URL, and preview image URL for EasyAdmin / Twig displays.
     *
     * @return array{name: string, href: ?string, imgSrc: ?string}
     */
    public function describeFieldItem(
        mixed $rawItem,
        string $profile = 'default',
        ?string $volume = null,
        bool $preview = true,
    ): array {
        if (!is_string($rawItem) && !is_int($rawItem) && !is_float($rawItem) && !$rawItem instanceof Stringable) {
            return ['name' => '', 'href' => null, 'imgSrc' => null];
        }

        $itemStr = trim((string) $rawItem);
        if ($itemStr === '' || UnicodeHelper::hasForbiddenControls($itemStr)) {
            return ['name' => $itemStr !== '' ? self::sanitizeDisplayName($itemStr) : '', 'href' => null, 'imgSrc' => null];
        }

        if (self::isSafeAbsoluteOrRootUrl($itemStr)) {
            return $this->describeUrlFieldItem($itemStr, $preview);
        }

        if (str_starts_with($itemStr, '/') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $itemStr) === 1) {
            $decoded = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8(rawurldecode(explode('?', explode('#', $itemStr, 2)[0], 2)[0]))));
            $leaf = PathNormalizer::basenameUtf8($decoded);

            return ['name' => self::sanitizeDisplayName($leaf !== '' ? $leaf : $itemStr), 'href' => null, 'imgSrc' => null];
        }

        return $this->describeRelativePathFieldItem($itemStr, $profile, $volume, $preview);
    }

    /**
     * @return array{name: string, href: ?string, imgSrc: ?string}
     */
    private function describeUrlFieldItem(string $itemStr, bool $preview): array
    {
        $noHash = explode('#', $itemStr, 2)[0];
        $parts = explode('?', $noHash, 2);
        $basePart = $parts[0];
        $queryPart = $parts[1] ?? '';

        $isRootRelative = str_starts_with($basePart, '/') && self::isSafeRootRelativeUrl($basePart);
        $urlPathForChecks = $basePart;
        if (preg_match('#^https?://#i', $basePart) === 1) {
            $parsedPath = parse_url($basePart, PHP_URL_PATH);
            $urlPathForChecks = is_string($parsedPath) ? $parsedPath : '';
        }
        $normalizedCheckPath = $urlPathForChecks !== '' ? '/' . ltrim($urlPathForChecks, '/') : '';
        if (
            preg_match('/%25(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c|2e)/i', $itemStr) === 1
            || ($normalizedCheckPath !== '' && self::hasDangerousRootRelativeSegments($normalizedCheckPath, $itemStr))
        ) {
            $decodedBase = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8(rawurldecode($normalizedCheckPath))));
            $leaf = PathNormalizer::basenameUtf8($decodedBase);

            return ['name' => self::sanitizeDisplayName($leaf !== '' ? $leaf : $itemStr), 'href' => null, 'imgSrc' => null];
        }

        $trimmedEndpointPath = rtrim($normalizedCheckPath, '/');
        $downloadRoutePath = $this->resolveBundleEndpointPath('liveux_file_manager_download', '/_liveux-file-manager/download');
        $shareRoutePath = $this->resolveBundleEndpointPath('liveux_file_manager_share', '/_liveux-file-manager/share');
        $isDownloadEndpoint = $trimmedEndpointPath !== ''
            && ($trimmedEndpointPath === $downloadRoutePath || $trimmedEndpointPath === '/_liveux-file-manager/download')
            && $this->matchesBundleEndpointOrigin('liveux_file_manager_download', $basePart, $isRootRelative);
        $isShareEndpoint = !$isDownloadEndpoint
            && $trimmedEndpointPath !== ''
            && ($trimmedEndpointPath === $shareRoutePath || $trimmedEndpointPath === '/_liveux-file-manager/share')
            && $this->matchesBundleEndpointOrigin('liveux_file_manager_share', $basePart, $isRootRelative);

        $queryPathValues = self::extractRawQueryPathValues($queryPart);
        $hasInvalidQueryPath = $queryPathValues === null || count($queryPathValues) > 1;
        $anyQueryPath = is_array($queryPathValues) ? ($queryPathValues[0] ?? '') : '';
        $rawQueryPath = ($isDownloadEndpoint || $isShareEndpoint) && !$hasInvalidQueryPath ? $anyQueryPath : '';

        $urlPathSource = $basePart;
        if (preg_match('#^https?://#i', $basePart) === 1) {
            $parsedPath = parse_url($basePart, PHP_URL_PATH);
            $urlPathSource = is_string($parsedPath) ? $parsedPath : '';
        }

        $decodedUrlPath = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8(rawurldecode($urlPathSource))));
        $decodedPath = $rawQueryPath !== ''
            ? rawurldecode(str_replace('+', ' ', $rawQueryPath))
            : $decodedUrlPath;
        $cleanPath = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8($decodedPath)));
        $fileName = PathNormalizer::basenameUtf8($cleanPath);
        if ($fileName === '') {
            $fileName = self::scrubUtf8($itemStr);
        }

        $rawUrlLeaf = PathNormalizer::basenameUtf8($decodedUrlPath);
        $rawUrlPlainExt = PathNormalizer::extensionUtf8($rawUrlLeaf);
        $rawLeafPlainExt = PathNormalizer::extensionUtf8($fileName);

        $foldedUrlLeaf = UnicodeHelper::trimEdgeInvisiblesAndDots(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($rawUrlLeaf))));
        $foldedFileLeaf = UnicodeHelper::trimEdgeInvisiblesAndDots(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($fileName))));

        $ext = PathNormalizer::extensionUtf8($foldedFileLeaf);
        $urlExt = PathNormalizer::extensionUtf8($foldedUrlLeaf);
        $isDownload = $rawQueryPath !== '' && $isDownloadEndpoint;
        $isShare = $rawQueryPath !== '' && $isShareEndpoint;
        $hasUnsafeQueryPath = $hasInvalidQueryPath || self::hasAnyUnsafeQueryPath($queryPathValues ?? [], $itemStr, !$isDownload && !$isShare);
        $hasBlockedExt = self::isBlockedDocumentExtension($ext) || self::isBlockedDocumentExtension($urlExt);
        $hasActiveDoc = self::isActiveDocumentExtension($ext) || self::isActiveDocumentExtension($urlExt);

        $hasDirtyLeaf = $hasUnsafeQueryPath
            || ($rawUrlLeaf !== '' && (UnicodeHelper::hasEdgeInvisibleOrTrailingDot($rawUrlLeaf) || str_ends_with($rawUrlLeaf, ' ') || str_ends_with($rawUrlLeaf, '.') || ($rawUrlPlainExt !== '' && UnicodeHelper::visibleSkeleton($rawUrlPlainExt) !== $rawUrlPlainExt)))
            || ($fileName !== '' && (UnicodeHelper::hasEdgeInvisibleOrTrailingDot($fileName) || str_ends_with($fileName, ' ') || str_ends_with($fileName, '.') || ($rawLeafPlainExt !== '' && UnicodeHelper::visibleSkeleton($rawLeafPlainExt) !== $rawLeafPlainExt)));
        $hasSpoofedActiveUrlExt = $urlExt !== '' && $urlExt !== $rawUrlPlainExt && self::isActiveDocumentExtension($urlExt);

        $canPreviewImg = $preview
            && !$hasDirtyLeaf
            && !$hasSpoofedActiveUrlExt
            && !$hasBlockedExt
            && in_array($ext, self::PREVIEW_IMAGE_EXTENSIONS, true)
            && !($ext === 'svg' && $isShare)
            && !($urlExt !== 'svg' && self::isActiveDocumentExtension($urlExt) && !$isDownload);

        $imgSrc = null;
        if ($canPreviewImg) {
            if ($ext === 'svg' && $isDownload) {
                $thumbRoutePath = $this->resolveBundleEndpointPath('liveux_file_manager_thumb', '/_liveux-file-manager/thumb');
                $origin = '';
                if (!$isRootRelative && preg_match('~^(https?://[^/?#]+)~i', $basePart, $originMatch) === 1) {
                    $origin = $originMatch[1];
                }
                $imgSrc = $origin . $thumbRoutePath . '?' . $queryPart;
            } else {
                $imgSrc = $itemStr;
            }
        }

        // Only safe non-blocked files (plus sanitized/attachment-forced SVG on bundle /download & /share) get a clickable href.
        $allowHref = !$hasDirtyLeaf && !$hasBlockedExt && (!$hasActiveDoc || $isDownload || $isShare);

        return [
            'name' => self::sanitizeDisplayName($fileName),
            'href' => $allowHref ? $itemStr : null,
            'imgSrc' => $imgSrc,
        ];
    }

    private function resolveBundleEndpointPath(string $routeName, string $fallbackPath): string
    {
        try {
            $generated = $this->urlGenerator->generate($routeName);
            $parsed = parse_url($generated, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                return '/' . trim($parsed, '/');
            }
        } catch (Throwable) {
        }

        return $fallbackPath;
    }

    /**
     * Prevents third-party origins (e.g. `https://attacker.example/_liveux-file-manager/download?path=x.svg`)
     * from spoofing bundle attachment-forced endpoints and bypassing the active-SVG href guard.
     */
    private function matchesBundleEndpointOrigin(string $routeName, string $basePart, bool $isRootRelative): bool
    {
        if ($isRootRelative) {
            return true;
        }

        $candidateOrigin = self::extractNormalizedOrigin($basePart);
        if ($candidateOrigin === null) {
            return false;
        }

        try {
            $absoluteUrl = $this->urlGenerator->generate($routeName, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return false;
        }

        $expectedOrigin = self::extractNormalizedOrigin($absoluteUrl);

        return $expectedOrigin !== null && $expectedOrigin === $candidateOrigin;
    }

    private static function extractNormalizedOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme . '://' . $host . ($port !== null && !$isDefaultPort ? ':' . $port : '');
    }

    private static function isBlockedDocumentExtension(string $ext): bool
    {
        return in_array($ext, \LiveUX\FileManagerBundle\Security\FileSecurityGuard::BLOCKED_EXTENSIONS, true);
    }

    private static function isSafeRootRelativeUrl(string $pathOnly): bool
    {
        if (preg_match('/^\/(?![\/\\\\\s]|%2[fF]|%5[cC]|%25(?:2[fF]|5[cC]))/', $pathOnly) !== 1) {
            return false;
        }
        if (str_contains($pathOnly, '\\')) {
            return false;
        }

        $decoded = rawurldecode($pathOnly);

        return !str_starts_with($decoded, '//')
            && !str_starts_with($decoded, '/\\')
            && !str_contains($decoded, '\\');
    }

    /**
     * Extracts all query values whose raw or URL-decoded (including PHP parse_str leading-space-stripped)
     * parameter name is `path`, catching `%70ath`, `%20path`, `+path`, and HPP duplicates.
     * Returns null if array syntax (`path[]=...` / `+path[0]=...`) is encountered, which must always be rejected.
     *
     * @return ?list<string>
     */
    private static function extractRawQueryPathValues(string $queryPart): ?array
    {
        if ($queryPart === '') {
            return [];
        }

        $values = [];
        foreach (explode('&', $queryPart) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $rawKey = $kv[0];
            $decodedOnce = rawurldecode(str_replace('+', ' ', $rawKey));
            $decodedTwice = rawurldecode($decodedOnce);
            foreach ([$rawKey, $decodedOnce, $decodedTwice] as $candKey) {
                $trimmedLower = strtolower(ltrim($candKey, " \t\n\r\0\x0B"));
                if (str_starts_with($trimmedLower, 'path[')) {
                    return null;
                }
            }
            $canonicalKey = strtolower(ltrim($decodedOnce, " \t\n\r\0\x0B"));
            $canonicalTwice = strtolower(ltrim($decodedTwice, " \t\n\r\0\x0B"));
            if ($rawKey === 'path' || $canonicalKey === 'path' || $canonicalTwice === 'path') {
                $values[] = $kv[1] ?? '';
            }
        }

        parse_str($queryPart, $parsed);
        if (array_key_exists('path', $parsed)) {
            if (!is_string($parsed['path'])) {
                return null;
            }
            if ($values === []) {
                $values[] = $parsed['path'];
            }
        }

        return $values;
    }

    /**
     * @param list<string> $queryPathValues
     */
    private static function hasAnyUnsafeQueryPath(array $queryPathValues, string $fullUrl, bool $blockActiveLeafExt): bool
    {
        foreach ($queryPathValues as $rawQPath) {
            if ($rawQPath === '') {
                continue;
            }
            $decodedQPath = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8(rawurldecode(str_replace('+', ' ', $rawQPath)))));
            if (
                self::hasDangerousRootRelativeSegments('/' . ltrim($rawQPath, '/'), $fullUrl)
                || ($decodedQPath !== '' && self::hasDangerousRootRelativeSegments('/' . ltrim($decodedQPath, '/'), $fullUrl))
            ) {
                return true;
            }
            if ($blockActiveLeafExt && $decodedQPath !== '') {
                $qLeaf = PathNormalizer::basenameUtf8($decodedQPath);
                $qFolded = UnicodeHelper::trimEdgeInvisiblesAndDots(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($qLeaf))));
                $qExt = PathNormalizer::extensionUtf8($qFolded);
                if ($qExt !== '' && self::isActiveDocumentExtension($qExt)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasDangerousRootRelativeSegments(string $basePart, string $fullUrl): bool
    {
        $decodedOnce = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8(rawurldecode($basePart))));
        $decodedTwice = UnicodeHelper::toNfc(str_replace('\\', '/', self::scrubUtf8(rawurldecode($decodedOnce))));
        foreach ([$decodedOnce, $decodedTwice] as $candidate) {
            if (
                UnicodeHelper::hasForbiddenControls($candidate)
                || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
                || str_starts_with($candidate, '//')
                || preg_match('/[\x{FF0F}\x{2215}\x{2044}\x{FF3C}]/u', $candidate) === 1
            ) {
                return true;
            }
            foreach (explode('/', ltrim($candidate, '/')) as $seg) {
                if ($seg === '') {
                    continue;
                }
                $folded = mb_strtolower(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($seg))), 'UTF-8');
                if ($folded === '.' || $folded === '..' || $folded === '.trash' || str_starts_with($folded, '.')) {
                    return true;
                }
            }
        }
        unset($fullUrl);

        return false;
    }

    /**
     * Strips C0/C1 controls, line/paragraph separators, and BiDi override/isolate controls
     * from display names so legacy/tampered URLs cannot visually spoof extensions in badges.
     */
    private static function sanitizeDisplayName(string $raw): string
    {
        $scrubbed = self::scrubUtf8($raw);
        $clean = preg_replace('/[\x00-\x1F\x7F\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}\x{200E}\x{200F}\x{E0000}-\x{E007F}]+/u', '', $scrubbed);

        return is_string($clean) ? trim($clean) : trim($scrubbed);
    }

    private static function isActiveDocumentExtension(string $ext): bool
    {
        return in_array($ext, \LiveUX\FileManagerBundle\Security\FileSecurityGuard::BLOCKED_EXTENSIONS, true) || $ext === 'svg';
    }

    private function hasSpoofedRelativeLeaf(string $fileName): bool
    {
        if (UnicodeHelper::hasEdgeInvisibleOrTrailingDot($fileName) || str_ends_with($fileName, ' ') || str_ends_with($fileName, '.')) {
            return true;
        }

        $rawPlainExt = PathNormalizer::extensionUtf8($fileName);
        if ($rawPlainExt !== '' && UnicodeHelper::visibleSkeleton($rawPlainExt) !== $rawPlainExt) {
            return true;
        }

        $foldedLeaf = UnicodeHelper::trimEdgeInvisiblesAndDots(
            UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($fileName))),
        );
        $foldedExt = PathNormalizer::extensionUtf8($foldedLeaf);

        return $foldedExt !== '' && $foldedExt !== $rawPlainExt && self::isActiveDocumentExtension($foldedExt);
    }

    /**
     * @return array{name: string, href: ?string, imgSrc: ?string}
     */
    private function describeRelativePathFieldItem(
        string $itemStr,
        string $profileName,
        ?string $volumeName,
        bool $preview,
    ): array {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $itemStr) === 1) {
            $fallback = PathNormalizer::basenameUtf8(str_replace('\\', '/', self::scrubUtf8($itemStr)));

            return ['name' => $fallback !== '' ? $fallback : self::scrubUtf8($itemStr), 'href' => null, 'imgSrc' => null];
        }

        try {
            $cleanRel = $this->pathNormalizer->normalize($itemStr);
        } catch (Throwable) {
            $fallback = PathNormalizer::basenameUtf8(str_replace('\\', '/', self::scrubUtf8($itemStr)));

            return ['name' => $fallback !== '' ? $fallback : self::scrubUtf8($itemStr), 'href' => null, 'imgSrc' => null];
        }

        if ($cleanRel === '') {
            return ['name' => self::scrubUtf8($itemStr), 'href' => null, 'imgSrc' => null];
        }

        $fileName = PathNormalizer::basenameUtf8($cleanRel);
        try {
            $guard = new \LiveUX\FileManagerBundle\Security\FileSecurityGuard();
            foreach (explode('/', $cleanRel) as $seg) {
                $foldedLower = mb_strtolower(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($seg))), 'UTF-8');
                if ($foldedLower === '.trash' || str_starts_with($foldedLower, '.')) {
                    return ['name' => $fileName, 'href' => null, 'imgSrc' => null];
                }
            }
            if ($this->configProvider !== null && $this->configProvider->hasProfile($profileName)) {
                $guard->assertAccessiblePath($cleanRel, $this->configProvider->getProfile($profileName));
            }
            $guard->assertSafeFilename($fileName);
            if ($this->hasSpoofedRelativeLeaf($fileName)) {
                return ['name' => $fileName, 'href' => null, 'imgSrc' => null];
            }
        } catch (Throwable) {
            return ['name' => $fileName, 'href' => null, 'imgSrc' => null];
        }

        $ext = PathNormalizer::extensionUtf8($fileName);
        $isImg = in_array($ext, self::PREVIEW_IMAGE_EXTENSIONS, true);
        $isActiveDoc = self::isActiveDocumentExtension($ext);

        $publicUrl = $this->resolvePublicUrlForRelativePath($cleanRel, $profileName, $volumeName);
        $href = $isActiveDoc ? null : $publicUrl;
        $imgSrc = null;

        if ($href === null) {
            try {
                $href = $this->getDownloadUrl($profileName, $volumeName, $cleanRel, $isImg && !$isActiveDoc);
            } catch (Throwable) {
                $href = $isActiveDoc ? null : $publicUrl;
            }
        }

        if ($preview && $isImg) {
            if ($publicUrl !== null && !$isActiveDoc) {
                $imgSrc = $publicUrl;
            } else {
                try {
                    $imgSrc = $this->getThumbnailUrl($profileName, $volumeName, $cleanRel);
                } catch (Throwable) {
                    $imgSrc = $isActiveDoc ? null : $publicUrl;
                }
            }
        }

        return [
            'name' => self::sanitizeDisplayName($fileName),
            'href' => $href,
            'imgSrc' => $imgSrc,
        ];
    }

    private function resolvePublicUrlForRelativePath(string $cleanRel, string $profileName, ?string $volumeName): ?string
    {
        if ($this->configProvider === null || $this->volumeRegistry === null) {
            return null;
        }

        try {
            $profile = $this->configProvider->getProfile($profileName);
            $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
            if ($volume->private || $volume->urlPrefix === null || trim($volume->urlPrefix) === '') {
                return null;
            }

            $prefix = rtrim(trim($volume->urlPrefix), '/');
            if (!self::isSafeAbsoluteOrRootUrl($prefix)) {
                return null;
            }

            $encoded = implode('/', array_map('rawurlencode', explode('/', $cleanRel)));

            return $prefix . '/' . $encoded;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isSafeAbsoluteOrRootUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return self::isSafeRootRelativeUrl($url) && !self::hasDangerousRootRelativeSegments(explode('?', explode('#', $url, 2)[0], 2)[0], $url);
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        $beforeQuery = explode('?', explode('#', $url, 2)[0], 2)[0];
        if (str_contains($beforeQuery, '\\')) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return RemoteUrlImporter::isSafeRemoteHostLiteral((string) ($parts['host'] ?? ''));
    }

    private static function scrubUtf8(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
