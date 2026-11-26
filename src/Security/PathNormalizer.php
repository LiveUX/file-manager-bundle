<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;

final readonly class PathNormalizer
{
    private const int MAX_PATH_LENGTH = 1024;
    private const int MAX_PATH_BYTES = 3072;
    private const int MAX_DEPTH = 32;

    private const array WINDOWS_RESERVED = [
        'con', 'prn', 'aux', 'nul', 'conin$', 'conout$',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    /**
     * Locale-independent UTF-8 basename (immune to PHP C-locale stripping of Japanese/Arabic/Chinese/Korean characters).
     */
    public static function basenameUtf8(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return '';
        }

        $pos = strrpos($normalized, '/');

        return $pos === false ? $normalized : substr($normalized, $pos + 1);
    }

    /**
     * Locale-independent UTF-8 parent directory (returns '' for root-level items).
     */
    public static function dirnameUtf8(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return '';
        }

        $pos = strrpos($normalized, '/');

        return $pos === false ? '' : substr($normalized, 0, $pos);
    }

    /**
     * Locale-independent UTF-8 file extension (lowercase ASCII/UTF-8, '' when none or dotfile).
     */
    public static function extensionUtf8(string $path): string
    {
        $base = self::basenameUtf8($path);
        $dotPos = strrpos($base, '.');
        if ($dotPos === false || $dotPos === 0 || $dotPos === strlen($base) - 1) {
            return '';
        }

        return mb_strtolower(substr($base, $dotPos + 1), 'UTF-8');
    }

    /**
     * Locale-independent UTF-8 filename without its final extension.
     */
    public static function stemUtf8(string $path): string
    {
        $base = self::basenameUtf8($path);
        $dotPos = strrpos($base, '.');
        if ($dotPos === false || $dotPos === 0) {
            return $base;
        }

        return substr($base, 0, $dotPos);
    }

    public function normalize(string $path): string
    {
        $this->assertSafeRawPath($path);

        $normalized = str_replace('\\', '/', UnicodeHelper::trimSpaces($path));
        $normalized = trim($normalized, '/');

        if ($normalized === '') {
            return '';
        }

        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            $cleanSegment = UnicodeHelper::trimSpaces($segment);
            if ($cleanSegment === '' || $cleanSegment === '.') {
                continue;
            }

            $this->assertSafeSegment($cleanSegment);
            $nfcSegment = UnicodeHelper::toNfc($cleanSegment);
            if ($nfcSegment !== $cleanSegment) {
                $this->assertSafeSegment($nfcSegment);
            }
            $segments[] = $nfcSegment;
        }

        if (count($segments) > self::MAX_DEPTH) {
            throw new InvalidPathException(
                sprintf('Path exceeds maximum allowed depth (%d).', self::MAX_DEPTH),
                'errors.path_max_depth',
                ['%max%' => self::MAX_DEPTH],
            );
        }

        return implode('/', $segments);
    }

    public function join(string ...$parts): string
    {
        $cleanParts = [];
        foreach ($parts as $part) {
            $normalized = $this->normalize($part);
            if ($normalized !== '') {
                $cleanParts[] = $normalized;
            }
        }

        return implode('/', $cleanParts);
    }

    public static function normalizeS3Endpoint(string $endpoint): string
    {
        return rtrim(strtolower(trim($endpoint)), '/');
    }

    public function buildCanonicalKey(VolumeConfig $volume, string $relativePath, string $basePath = ''): string
    {
        $cleanRelative = UnicodeHelper::toNfc($this->normalize($relativePath));

        if ($volume->driver === 'AwsS3') {
            $bucket = is_string($volume->driverOptions['bucket'] ?? null) ? $volume->driverOptions['bucket'] : $volume->name;
            $endpoint = is_string($volume->driverOptions['endpoint'] ?? null) ? self::normalizeS3Endpoint($volume->driverOptions['endpoint']) : '';
            $bucketScope = $endpoint !== '' ? substr(md5($endpoint), 0, 8) . ':' . $bucket : $bucket;
            $prefix = is_string($volume->driverOptions['prefix'] ?? null) ? UnicodeHelper::toNfc($this->normalize($volume->driverOptions['prefix'])) : '';
            $full = $this->join($prefix, $cleanRelative);

            return UnicodeHelper::toNfc($full === '' ? sprintf('s3::%s', $bucketScope) : sprintf('s3::%s/%s', $bucketScope, $full));
        }

        if ($volume->driver === 'LocalFileSystem' && $volume->path !== null) {
            $relativeToBase = $this->computeRelativeToBase(UnicodeHelper::toNfc($volume->path), UnicodeHelper::toNfc($basePath));
            if ($relativeToBase === '') {
                return UnicodeHelper::toNfc('local::' . $cleanRelative);
            }
            $full = $cleanRelative !== '' ? $relativeToBase . '/' . $cleanRelative : $relativeToBase;

            return UnicodeHelper::toNfc('local::' . $full);
        }

        return UnicodeHelper::toNfc(
            $cleanRelative === ''
                ? sprintf('%s::%s', strtolower($volume->driver), $volume->name)
                : sprintf('%s::%s/%s', strtolower($volume->driver), $volume->name, $cleanRelative),
        );
    }

    private function assertSafeRawPath(string $path): void
    {
        if (!mb_check_encoding($path, 'UTF-8')) {
            throw new InvalidPathException('Invalid UTF-8 byte sequence in file path.', 'errors.path_control_chars');
        }

        if (strlen($path) > self::MAX_PATH_BYTES || mb_strlen($path, 'UTF-8') > self::MAX_PATH_LENGTH) {
            throw new InvalidPathException('Path exceeds maximum allowed length.', 'errors.path_too_long');
        }

        // Block ASCII/C1 control characters, null bytes, line separators, and BiDi override/isolate spoofing chars
        if (UnicodeHelper::hasForbiddenControls($path)) {
            throw new InvalidPathException('Control characters and null bytes are not allowed in file paths.', 'errors.path_control_chars');
        }

        if (preg_match('#^[a-zA-Z]+://#', ltrim($path)) === 1) {
            throw new InvalidPathException('Stream wrappers and schemes are not allowed in paths.', 'errors.path_schemes_forbidden');
        }

        if (preg_match('#^[a-zA-Z]:#', ltrim($path)) === 1) {
            throw new InvalidPathException('Absolute drive paths are not allowed.', 'errors.path_drive_forbidden');
        }
    }

    private function assertSafeSegment(string $segment): void
    {
        if (strlen($segment) > 255) {
            throw new InvalidPathException(
                'Path segment exceeds maximum filesystem length (255 bytes).',
                'errors.path_too_long',
            );
        }

        $nfkc = UnicodeHelper::toNfkc($segment);
        if (
            preg_match('/[:<>"|?*]/', $segment) === 1
            || preg_match('/[\x{FF0F}\x{2215}\x{2044}\x{FF3C}]/u', $segment) === 1
            || str_contains($nfkc, '/')
            || str_contains($nfkc, '\\')
        ) {
            throw new InvalidPathException(
                'Illegal filesystem characters (e.g. ":", "<", ">", "|", "?", "*") are not allowed in paths.',
                'errors.path_control_chars',
            );
        }

        if ($segment === '..' || str_contains($segment, '..')) {
            if ($segment === '..' || preg_match('/^\.{2,}$/', $segment) === 1) {
                throw new InvalidPathException('Path traversal ("..") is not allowed.', 'errors.path_traversal');
            }
        }

        if (str_ends_with($segment, '.') && $segment !== '.') {
            throw new InvalidPathException('Path segments ending with a dot are not allowed.', 'errors.path_trailing_dot');
        }

        if (UnicodeHelper::hasEdgeInvisibleOrTrailingDot($segment)) {
            throw new InvalidPathException('Invisible or trailing dot/space characters are not allowed in path segments.', 'errors.path_trailing_dot');
        }

        $skeleton = UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($segment)));
        if ($skeleton === '' || $skeleton === '.' || $skeleton === '..' || preg_match('/^\.{2,}$/', $skeleton) === 1) {
            throw new InvalidPathException('Invisible or spoofed traversal path segment is not allowed.', 'errors.path_traversal');
        }

        $firstPart = strtolower(explode('.', UnicodeHelper::foldDotEquivalents($segment), 2)[0]);
        $skeletonFirstPart = strtolower(explode('.', $skeleton, 2)[0]);
        if (in_array($firstPart, self::WINDOWS_RESERVED, true) || in_array($skeletonFirstPart, self::WINDOWS_RESERVED, true)) {
            throw new InvalidPathException(
                sprintf('Reserved system name "%s" is not allowed in path.', $segment),
                'errors.path_reserved_name',
                ['%name%' => $segment],
            );
        }
    }

    private function computeRelativeToBase(string $volumePath, string $basePath): string
    {
        $cleanVolume = $this->canonicalizePhysicalPath($volumePath);
        $cleanBase = $this->canonicalizePhysicalPath($basePath);
        $isCaseInsensitive = DIRECTORY_SEPARATOR === '\\'
            || preg_match('/^[a-zA-Z]:(?:\/|$)/', $cleanVolume) === 1
            || preg_match('/^[a-zA-Z]:(?:\/|$)/', $cleanBase) === 1;

        $cmpVolume = $isCaseInsensitive ? mb_strtolower($cleanVolume, 'UTF-8') : $cleanVolume;
        $cmpBase = $isCaseInsensitive ? mb_strtolower($cleanBase, 'UTF-8') : $cleanBase;

        if ($cmpBase !== '' && ($cmpVolume === $cmpBase || str_starts_with($cmpVolume, $cmpBase . '/'))) {
            if ($cmpVolume === $cmpBase) {
                return '';
            }
            $volTrimmed = trim($cleanVolume, '/');
            $baseTrimmed = trim($cleanBase, '/');
            $volSegs = $volTrimmed === '' ? [] : explode('/', $volTrimmed);
            $baseSegs = $baseTrimmed === '' ? [] : explode('/', $baseTrimmed);

            return implode('/', array_slice($volSegs, count($baseSegs)));
        }

        $base = self::basenameUtf8($cleanVolume);
        $disambiguator = '@ext_' . substr(md5($cmpVolume), 0, 8);
        if ($base === '' || preg_match('/^[a-zA-Z]:$/', $base) === 1) {
            return $disambiguator;
        }
        $safeBase = UnicodeHelper::trimEdgeInvisiblesAndDots($base);
        if ($safeBase === '') {
            return $disambiguator;
        }

        return $disambiguator . '/' . $safeBase;
    }

    private function canonicalizePhysicalPath(string $path): string
    {
        $slashed = $this->normalizeDriveLetter(rtrim(str_replace('\\', '/', $path), '/'));
        if ($slashed === '') {
            return '';
        }

        $prefix = '';
        $rest = $slashed;
        if (preg_match('/^([a-z]:)(?:\/(.*))?$/i', $slashed, $m) === 1) {
            $prefix = $m[1];
            $rest = $m[2] ?? '';
        } elseif (str_starts_with($slashed, '//')) {
            $prefix = '//';
            $rest = substr($slashed, 2);
        } elseif (str_starts_with($slashed, '/')) {
            $prefix = '/';
            $rest = substr($slashed, 1);
        }

        $stack = [];
        foreach (explode('/', $rest) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($stack !== [] && end($stack) !== '..') {
                    array_pop($stack);
                } elseif ($prefix === '') {
                    $stack[] = '..';
                }
                continue;
            }
            $stack[] = $part;
        }

        $joined = implode('/', $stack);
        if ($prefix === '') {
            return $joined;
        }
        if ($joined === '') {
            return $prefix;
        }

        return str_ends_with($prefix, '/') ? $prefix . $joined : $prefix . '/' . $joined;
    }

    private function normalizeDriveLetter(string $path): string
    {
        if (preg_match('/^[A-Z]:(?=\/|$)/', $path) === 1) {
            return strtolower($path[0]) . substr($path, 1);
        }

        return $path;
    }
}
