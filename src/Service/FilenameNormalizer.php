<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use DateTimeImmutable;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Throwable;

final readonly class FilenameNormalizer
{
    public function __construct(
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
    ) {
    }

    public function normalizeForUpload(
        string $originalFilename,
        ProfileConfig $profile,
        string $folderName = '',
        ?DateTimeImmutable $now = null,
    ): string {
        $nfcInput = UnicodeHelper::toNfc($originalFilename);
        $this->fileSecurityGuard->assertSafeFilename($nfcInput, strictOsChars: false);
        $naming = $profile->naming;
        [$base, $extension] = $this->splitFilename($nfcInput);

        $base = $this->applyBaseNamingPipeline($base, $naming, UnicodeHelper::toNfc($folderName), $now, true, $profile->locale);
        $result = $this->joinFilename($base, $extension);
        $this->fileSecurityGuard->assertSafeFilename($result);

        return $result;
    }

    public function normalizeForRename(
        string $currentFilename,
        string $newFilename,
        ProfileConfig $profile,
        string $folderName = '',
        ?DateTimeImmutable $now = null,
    ): string {
        $nfcNew = UnicodeHelper::trimSpaces(UnicodeHelper::toNfc($newFilename));
        $naming = $profile->naming;
        [, $currentExt] = $this->splitFilename(UnicodeHelper::toNfc($currentFilename));
        [$newBase, $newExt] = $this->splitFilename($nfcNew);

        $allowExtChange = ($naming['allow_extension_change'] ?? false) === true;
        if (!$allowExtChange && $currentExt !== '' && $newExt !== $currentExt) {
            if ($newExt !== '' && $this->isStemInternalDotNotExtension($newExt)) {
                $newBase = PathNormalizer::basenameUtf8($nfcNew);
                $newExt = $currentExt;
                $nfcNew = $newBase . '.' . $currentExt;
            }
        }

        $this->fileSecurityGuard->assertSafeFilename($nfcNew, strictOsChars: false);

        if (!$allowExtChange && $currentExt === '' && $newExt !== '') {
            throw new UploadValidationException(sprintf('Adding file extension ".%s" during rename is not allowed.', $newExt), 'errors.extension_change_forbidden', ['%from%' => '', '%to%' => $newExt]);
        }
        if (!$allowExtChange && $currentExt !== '' && $newExt !== $currentExt) {
            if ($newExt !== '') {
                throw new UploadValidationException(sprintf('Changing file extension from ".%s" to ".%s" is not allowed.', $currentExt, $newExt), 'errors.extension_change_forbidden', ['%from%' => $currentExt, '%to%' => $newExt]);
            }
            $newExt = $currentExt;
        }

        if (($naming['apply_on_rename'] ?? true) === true) {
            $applyAffixes = ($naming['apply_affixes_on_rename'] ?? false) === true;
            $newBase = $this->applyBaseNamingPipeline($newBase, $naming, UnicodeHelper::toNfc($folderName), $now, $applyAffixes, $profile->locale);
        } else {
            $newBase = $this->sanitizeFilesystemUnsafeChars($newBase);
        }

        $result = $this->joinFilename($newBase, $newExt);
        $this->fileSecurityGuard->assertSafeFilename($result);

        return $result;
    }

    public function normalizeFolderName(string $folderName, ProfileConfig $profile): string
    {
        $naming = $profile->naming;
        $clean = UnicodeHelper::trimSpaces(UnicodeHelper::toNfc($folderName));
        if ($clean === '' || UnicodeHelper::visibleSkeleton($clean) === '') {
            throw new UploadValidationException('Folder name cannot be empty.', 'errors.folder_name_empty');
        }

        if (($naming['slugger'] ?? true) === true) {
            $result = $this->slugify($clean, $naming, $profile->locale, allowFallbackWord: false);
        } else {
            $result = $this->sanitizeFilesystemUnsafeChars($clean, allowFallbackWord: false);
        }

        if ($result === '') {
            throw new UploadValidationException('Folder name cannot be empty.', 'errors.folder_name_empty');
        }

        $this->fileSecurityGuard->assertSafeFolderName($result);

        return $result;
    }

    private function isStemInternalDotNotExtension(string $candidateExt): bool
    {
        if (in_array($candidateExt, FileSecurityGuard::BLOCKED_EXTENSIONS, true)) {
            return false;
        }

        if (ctype_digit($candidateExt)) {
            return true;
        }

        if (preg_match('/^[a-z0-9_+-]{1,12}$/i', $candidateExt) !== 1) {
            return true;
        }

        return \Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes(strtolower($candidateExt)) === [];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitFilename(string $filename): array
    {
        $clean = PathNormalizer::basenameUtf8(UnicodeHelper::trimSpaces($filename));
        $dotPos = strrpos($clean, '.');

        if ($dotPos === false || $dotPos === 0) {
            return [$clean, ''];
        }

        return [
            substr($clean, 0, $dotPos),
            mb_strtolower(substr($clean, $dotPos + 1), 'UTF-8'),
        ];
    }

    /**
     * @param array<string, mixed> $naming
     */
    private function transformBaseName(string $base, array $naming, string $folderName): string
    {
        $transformer = is_string($naming['transformer'] ?? null) ? $naming['transformer'] : 'none';

        return match ($transformer) {
            'uppercase' => mb_strtoupper($base, 'UTF-8'),
            'lowercase' => mb_strtolower($base, 'UTF-8'),
            'snake' => $this->toSnakeCase($base),
            'camel' => UnicodeHelper::mbLcfirst($this->toPascalCase($base)),
            'cameltitle' => $this->toPascalCase($base),
            'title' => UnicodeHelper::mbUcfirst(mb_strtolower($base, 'UTF-8')),
            'titleall' => mb_convert_case($base, MB_CASE_TITLE, 'UTF-8'),
            'folder' => $folderName !== '' ? $folderName : $base,
            default => $base,
        };
    }

    /**
     * @param array<string, mixed> $naming
     */
    private function applyAffixes(string $base, array $naming, ?DateTimeImmutable $now, string &$resolvedAppendOut = ''): string
    {
        $date = $now ?? new DateTimeImmutable();
        $prepend = is_string($naming['prepend'] ?? null) ? $this->replaceDatePlaceholders($naming['prepend'], $date) : '';
        $append = is_string($naming['append'] ?? null) ? $this->replaceDatePlaceholders($naming['append'], $date) : '';
        $resolvedAppendOut = $append;

        // Reserve byte budget for prepend/append BEFORE combining so long CJK/multibyte stems never truncate away `{uniqid}` / `{timestamp}` suffixes.
        $affixBytes = strlen($prepend) + strlen($append);
        if ($affixBytes > 0 && (strlen($base) + $affixBytes) > 200) {
            $budget = max(1, 200 - $affixBytes);
            $trimmedBase = UnicodeHelper::truncateStemBytes($base, $budget);
            $base = $trimmedBase !== '' ? $trimmedBase : substr('file', 0, $budget);
        }

        return $prepend . $base . $append;
    }

    private function replaceDatePlaceholders(string $value, DateTimeImmutable $date): string
    {
        if (!str_contains($value, '{')) {
            return $value;
        }

        return strtr($value, [
            '{Y}' => $date->format('Y'),
            '{m}' => $date->format('m'),
            '{d}' => $date->format('d'),
            '{timestamp}' => (string) $date->getTimestamp(),
            '{uniqid}' => substr(bin2hex(random_bytes(4)), 0, 8),
        ]);
    }

    /**
     * @param array<string, mixed> $naming
     */
    private function slugify(string $value, array $naming, ?string $profileLocale = null, bool $allowFallbackWord = true): string
    {
        $nfc = UnicodeHelper::toNfc($value);

        // When ext-intl transliteration is unavailable and the input contains non-Latin scripts
        // (CJK, Arabic, Cyrillic, Indic, Thai, Korean, etc.), preserve the Unicode script slug
        // instead of letting AsciiSlugger strip non-Latin words down to bare digits ("產品 2026" -> "2026").
        if (!function_exists('transliterator_transliterate') && preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $nfc) === 1) {
            return $this->sanitizeUnicodeSlug($nfc, $allowFallbackWord);
        }

        $configured = is_string($naming['slugger_locale'] ?? null) && trim($naming['slugger_locale']) !== ''
            ? str_replace('-', '_', trim($naming['slugger_locale']))
            : ($profileLocale !== null && trim($profileLocale) !== '' ? str_replace('-', '_', trim($profileLocale)) : 'en');

        try {
            $slugger = new AsciiSlugger($configured);
            $slug = $slugger->slug($nfc, '-')->lower()->toString();
        } catch (Throwable) {
            $slugger = new AsciiSlugger('en');
            $slug = $slugger->slug($nfc, '-')->lower()->toString();
        }

        if ($slug !== '') {
            return UnicodeHelper::truncateStemBytes($slug, 200);
        }

        // Graceful fallback when ext-intl is absent or input consists of non-transliterable scripts/symbols
        return $this->sanitizeUnicodeSlug($nfc, $allowFallbackWord);
    }

    /**
     * Slugifies a combined base name while ensuring transliteration expansion (e.g. CJK -> Pinyin)
     * never silently chops off a configured `append` suffix (such as `-{uniqid}` or `-{timestamp}`).
     *
     * @param array<string, mixed> $naming
     */
    private function slugifyPreservingAffixes(
        string $combinedBase,
        array $naming,
        string $resolvedAppend,
        ?string $profileLocale,
    ): string {
        if ($resolvedAppend === '') {
            return $this->slugify($combinedBase, $naming, $profileLocale);
        }

        $appendSlug = ltrim($this->slugify($resolvedAppend, $naming, $profileLocale, allowFallbackWord: false), '-');
        if ($appendSlug === '' || strlen($appendSlug) >= 180) {
            return $this->slugify($combinedBase, $naming, $profileLocale);
        }

        $fullSlug = $this->slugify($combinedBase, $naming, $profileLocale);
        if (str_ends_with($fullSlug, $appendSlug)) {
            return $fullSlug;
        }

        $budget = max(1, 200 - strlen($appendSlug) - 1);
        $trimmedPrefix = rtrim(UnicodeHelper::truncateStemBytes($fullSlug, $budget), '-');

        return $trimmedPrefix !== '' ? $trimmedPrefix . '-' . $appendSlug : $appendSlug;
    }

    private function sanitizeUnicodeSlug(string $value, bool $allowFallbackWord = true): string
    {
        $stripped = UnicodeHelper::stripIgnorableFillers(UnicodeHelper::toNfc($value));
        $cleaned = preg_replace('/[^\p{L}\p{M}\p{N}\x{200C}_-]+/u', '-', $stripped) ?? '';
        $cleaned = preg_replace('/(?<=^|-)[\p{M}]+/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/-+/u', '-', $cleaned) ?? '';
        $cleaned = UnicodeHelper::trimEdgeInvisiblesAndDots(trim($cleaned, '-_. '));
        $cleaned = UnicodeHelper::truncateStemBytes($cleaned, 200);

        if ($cleaned !== '' && UnicodeHelper::visibleSkeleton($cleaned) !== '') {
            return mb_strtolower($cleaned, 'UTF-8');
        }

        return $allowFallbackWord ? 'file' : '';
    }

    private function sanitizeFilesystemUnsafeChars(string $value, bool $allowFallbackWord = true): string
    {
        // Strip controls/BiDi/BOM, normalize Unicode spaces (\p{Z}), replace OS-forbidden chars
        $cleaned = UnicodeHelper::toNfc($value);
        $cleaned = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/[\p{Z}]+/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/[\/\\\\<>:"|?*\x{FF0F}\x{2215}\x{2044}\x{FF3C}]+/u', '-', $cleaned) ?? $cleaned;
        $cleaned = preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static function (array $m): string {
                $nfkc = UnicodeHelper::toNfkc($m[0]);

                return (str_contains($nfkc, '/') || str_contains($nfkc, '\\')) ? '-' : $m[0];
            },
            $cleaned,
        ) ?? $cleaned;
        $cleaned = UnicodeHelper::trimEdgeInvisiblesAndDots($cleaned);
        $cleaned = UnicodeHelper::truncateStemBytes($cleaned, 200);

        if ($cleaned !== '' && UnicodeHelper::visibleSkeleton($cleaned) !== '') {
            return $cleaned;
        }

        return $allowFallbackWord ? 'file' : '';
    }

    private function toSnakeCase(string $value): string
    {
        $replaced = preg_replace('/[\s\p{Z}\-]+/u', '_', UnicodeHelper::trimSpaces($value)) ?? $value;

        return mb_strtolower($replaced, 'UTF-8');
    }

    private function toPascalCase(string $value): string
    {
        $words = preg_split('/[\s\p{Z}\-_]+/u', UnicodeHelper::trimSpaces($value)) ?: [$value];
        $result = '';

        foreach ($words as $word) {
            if ($word !== '') {
                $result .= UnicodeHelper::mbUcfirst(mb_strtolower($word, 'UTF-8'));
            }
        }

        return $result;
    }

    /**
     * Applies slugger, transformer, and affixes in a predictable order so case/separator transformers
     * (uppercase, snake, camel, cameltitle, title, titleall) are preserved even when `slugger: true`.
     *
     * @param array<string, mixed> $naming
     */
    private function applyBaseNamingPipeline(
        string $base,
        array $naming,
        string $folderName,
        ?DateTimeImmutable $now,
        bool $applyAffixes,
        ?string $profileLocale,
    ): string {
        $transformer = is_string($naming['transformer'] ?? null) ? $naming['transformer'] : 'none';
        $useSlugger = ($naming['slugger'] ?? true) === true;

        if ($transformer === 'folder' && $folderName !== '') {
            $base = $folderName;
        }

        $resolvedAppend = '';
        if ($applyAffixes) {
            $base = $this->applyAffixes($base, $naming, $now, $resolvedAppend);
        }

        if (!$useSlugger) {
            $transformed = ($transformer === 'folder' && $folderName !== '')
                ? $base
                : $this->transformBaseName($base, $naming, $folderName);

            return $this->sanitizeFilesystemUnsafeChars($transformed);
        }

        // Default lowercase slugger when no explicit case/separator transformer is configured.
        if ($transformer === 'none' || $transformer === 'lowercase' || $transformer === 'folder') {
            return $this->slugifyPreservingAffixes($base, $naming, $resolvedAppend, $profileLocale);
        }

        // Preserve case/separator transformer while still ASCII-transliterating via slugger.
        $asciiSlug = $this->slugifyPreservingAffixes($base, $naming, $resolvedAppend, $profileLocale);
        $spaced = str_replace('-', ' ', $asciiSlug);
        $transformed = match ($transformer) {
            'uppercase' => mb_strtoupper($asciiSlug, 'UTF-8'),
            'snake' => $this->toSnakeCase($asciiSlug),
            'camel' => UnicodeHelper::mbLcfirst($this->toPascalCase($spaced)),
            'cameltitle' => $this->toPascalCase($spaced),
            'title' => UnicodeHelper::mbUcfirst($asciiSlug),
            'titleall' => str_replace(' ', '-', mb_convert_case($spaced, MB_CASE_TITLE, 'UTF-8')),
            default => $asciiSlug,
        };

        return $this->sanitizeFilesystemUnsafeChars($transformed);
    }

    private function joinFilename(string $base, string $extension): string
    {
        $maxBaseBytes = $extension !== '' ? min(220, max(1, 254 - strlen($extension))) : 220;
        $safeBase = UnicodeHelper::truncateStemBytes($base !== '' ? $base : 'file', $maxBaseBytes);
        if ($safeBase === '') {
            $safeBase = substr('file', 0, $maxBaseBytes);
        }

        // Disambiguate stems that transliterated into Windows reserved device names (e.g. `côn.png` -> `con.png`).
        $foldedCheck = mb_strtolower(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($safeBase))), 'UTF-8');
        $firstDotSeg = explode('.', $foldedCheck, 2)[0];
        if (preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9]|conin\$|conout\$)$/i', $firstDotSeg) === 1) {
            $safeBase = UnicodeHelper::truncateStemBytes('file-' . $safeBase, $maxBaseBytes);
            if ($safeBase === '') {
                $safeBase = substr('f', 0, $maxBaseBytes);
            }
        }

        if ($extension === '') {
            return $safeBase;
        }

        if (preg_match('/^[a-z0-9_+-]+$/i', $extension) !== 1) {
            throw new UploadValidationException('Filename extension contains non-ASCII characters.', 'errors.invalid_filename');
        }

        return $safeBase . '.' . strtolower($extension);
    }
}
