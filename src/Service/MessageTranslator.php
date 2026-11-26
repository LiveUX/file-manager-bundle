<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Translates FileManager UI labels, backend messages, and exceptions using the active locale,
 * with a guaranteed fallback chain that always resolves to English ("en") before ever
 * exposing a raw translation key.
 */
final class MessageTranslator
{
    public const string DOMAIN = 'LiveUXFileManagerBundle';
    public const string FALLBACK_LOCALE = 'en';
    public const string LOCALE_PATTERN = '/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z]{4})?(?:[_-](?:[a-zA-Z]{2}|[0-9]{3}))?(?:\.[a-zA-Z0-9_-]{2,20})?(?:@[a-zA-Z0-9_-]{2,20})?$/';

    private const array RTL_LANGUAGES = [
        'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'khw', 'ks', 'ps', 'sd', 'syr', 'ug', 'ur', 'yi',
    ];

    private const array RTL_SCRIPTS = [
        'adlm', 'arab', 'hebr', 'mand', 'mend', 'nkoo', 'rohg', 'samr', 'syrc', 'thaa',
    ];

    private const array LTR_SCRIPTS = [
        'armn', 'beng', 'cyrl', 'deva', 'ethi', 'geor', 'grek', 'gujr', 'guru',
        'hang', 'hans', 'hant', 'hira', 'jpan', 'kana', 'khmr', 'knda', 'kore',
        'laoo', 'latn', 'mlym', 'mymr', 'orya', 'sinh', 'taml', 'telu', 'thai', 'tibt',
    ];

    /**
     * @var array<string, string>|null
     */
    private static ?array $bundledEnglishCatalog = null;

    /**
     * @var array<string, array<string, true>>
     */
    private array $allowedLocaleMapCache = [];

    /**
     * @param list<string>|null $allowedCatalogLocales
     */
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?string $fallbackCatalogPath = null,
        private readonly ?array $allowedCatalogLocales = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function resolveLocale(Request $request, ?ProfileConfig $profile = null, array $payload = []): string
    {
        $postParams = $request->request->all();
        $queryParams = $request->query->all();
        $candidates = [
            $payload['locale'] ?? null,
            $postParams['locale'] ?? null,
            $queryParams['locale'] ?? null,
            $profile?->locale,
            $request->getLocale(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && self::isValidLocaleString($candidate)) {
                return trim($candidate);
            }
        }

        return self::FALLBACK_LOCALE;
    }

    public static function isValidLocaleString(string $locale): bool
    {
        $trimmed = trim($locale);

        return $trimmed !== ''
            && strlen($trimmed) <= 35
            && preg_match(self::LOCALE_PATTERN, $trimmed) === 1;
    }

    public static function toBcp47(?string $locale): string
    {
        if ($locale === null || !self::isValidLocaleString($locale)) {
            return self::FALLBACK_LOCALE;
        }

        $clean = preg_split('/[.#@]/', trim($locale), 2)[0] ?? '';
        $parts = preg_split('/[_-]+/', $clean) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return self::FALLBACK_LOCALE;
        }

        $canonical = [strtolower($parts[0])];
        $count = count($parts);
        for ($i = 1; $i < $count; ++$i) {
            $sub = $parts[$i];
            if (strlen($sub) === 4 && ctype_alpha($sub)) {
                $canonical[] = ucfirst(strtolower($sub));
            } elseif ((strlen($sub) === 2 && ctype_alpha($sub)) || (strlen($sub) === 3 && ctype_digit($sub))) {
                $canonical[] = strtoupper($sub);
            } else {
                $canonical[] = strtolower($sub);
            }
        }

        return implode('-', $canonical);
    }

    public static function isRtlLocale(?string $locale): bool
    {
        if ($locale === null || trim($locale) === '') {
            return false;
        }

        $clean = preg_split('/[.#@]/', trim($locale), 2)[0] ?? '';
        $parts = preg_split('/[_-]+/', $clean) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return false;
        }

        foreach (array_slice($parts, 1) as $sub) {
            if (strlen($sub) === 4 && ctype_alpha($sub)) {
                $script = strtolower($sub);
                if (in_array($script, self::RTL_SCRIPTS, true)) {
                    return true;
                }
                if (in_array($script, self::LTR_SCRIPTS, true)) {
                    return false;
                }
            }
        }

        return in_array(strtolower($parts[0]), self::RTL_LANGUAGES, true);
    }

    /**
     * @param array<string, int|string> $params
     */
    public function trans(string $key, array $params = [], ?string $locale = null, ?string $fallback = null): string
    {
        if ($this->translator !== null) {
            $targetLocale = $this->resolveTargetLocale($locale);
            $translated = $this->translateWithTranslator($key, $params, $targetLocale);
            if ($translated !== null) {
                return $translated;
            }
        }

        if ($fallback !== null && $fallback !== '') {
            return $this->interpolate($fallback, $params);
        }

        $bundledEnglish = $this->lookupBundledEnglish($key);
        if ($bundledEnglish !== null) {
            return $this->interpolate($bundledEnglish, $params);
        }

        return $this->interpolate($key, $params);
    }

    public function translateException(FileManagerException $exception, ?string $locale = null): string
    {
        $key = $exception->getTranslationKey();
        if ($key === null || $key === '') {
            return $exception->getMessage();
        }

        return $this->trans($key, $exception->getTranslationParams(), $locale, $exception->getMessage());
    }

    private function resolveTargetLocale(?string $locale): string
    {
        if ($locale !== null && self::isValidLocaleString($locale)) {
            return trim($locale);
        }

        $translatorLocale = $this->translator?->getLocale();
        if (is_string($translatorLocale) && self::isValidLocaleString($translatorLocale)) {
            return trim($translatorLocale);
        }

        return self::FALLBACK_LOCALE;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function translateWithTranslator(string $key, array $params, string $targetLocale): ?string
    {
        if ($this->translator === null) {
            return null;
        }

        if ($this->translator instanceof TranslatorBagInterface) {
            return $this->translateViaBag($this->translator, $key, $params, $targetLocale);
        }

        return $this->translateViaPlainTranslator($this->translator, $key, $params, $targetLocale);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function translateViaBag(
        TranslatorBagInterface&TranslatorInterface $translator,
        string $key,
        array $params,
        string $targetLocale,
    ): ?string {
        foreach ($this->getLocaleCandidates($targetLocale) as $candidate) {
            if (!$this->isAllowedCatalogLocale($candidate, $translator)) {
                continue;
            }
            if ($translator->getCatalogue($candidate)->defines($key, self::DOMAIN)) {
                return $translator->trans($key, $params, self::DOMAIN, $candidate);
            }
        }

        if ($targetLocale !== self::FALLBACK_LOCALE
            && $translator->getCatalogue(self::FALLBACK_LOCALE)->defines($key, self::DOMAIN)
        ) {
            return $translator->trans($key, $params, self::DOMAIN, self::FALLBACK_LOCALE);
        }

        return null;
    }

    private function isAllowedCatalogLocale(string $candidate, TranslatorInterface $translator): bool
    {
        if ($this->allowedCatalogLocales === null) {
            return true;
        }

        $translatorLocale = $translator->getLocale();
        $fallbacks = method_exists($translator, 'getFallbackLocales') ? $translator->getFallbackLocales() : [];
        $fallbackKey = is_array($fallbacks) ? implode(',', array_filter($fallbacks, 'is_string')) : '';
        $cacheKey = $translatorLocale . '|' . $fallbackKey;

        if (!isset($this->allowedLocaleMapCache[$cacheKey])) {
            if (count($this->allowedLocaleMapCache) >= 32) {
                $this->allowedLocaleMapCache = [];
            }
            $this->allowedLocaleMapCache[$cacheKey] = $this->buildAllowedLocaleMap(
                $translatorLocale,
                is_array($fallbacks) ? $fallbacks : [],
            );
        }

        $baseCandidate = (preg_split('/[.#@]/', trim($candidate), 2) ?: [''])[0];
        $normalizedCandidate = strtolower(str_replace('-', '_', $baseCandidate));

        return isset($this->allowedLocaleMapCache[$cacheKey][$normalizedCandidate]);
    }

    /**
     * @param array<mixed> $fallbacks
     *
     * @return array<string, true>
     */
    private function buildAllowedLocaleMap(string $translatorLocale, array $fallbacks): array
    {
        $allowed = [self::FALLBACK_LOCALE => true];
        $addLocaleWithParents = static function (string $loc) use (&$allowed): void {
            $base = (preg_split('/[.#@]/', trim($loc), 2) ?: [''])[0];
            $norm = strtolower(str_replace('-', '_', $base));
            if ($norm === '') {
                return;
            }
            $allowed[$norm] = true;
            $parts = explode('_', $norm);
            if (count($parts) >= 3) {
                $allowed[$parts[0] . '_' . $parts[1]] = true;
                $allowed[$parts[0] . '_' . $parts[2]] = true;
            }
            if (count($parts) >= 2) {
                $allowed[$parts[0]] = true;
            }
        };

        foreach ($this->allowedCatalogLocales ?? [] as $loc) {
            $addLocaleWithParents($loc);
        }
        $addLocaleWithParents($translatorLocale);
        foreach ($fallbacks as $fb) {
            if (is_string($fb)) {
                $addLocaleWithParents($fb);
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function translateViaPlainTranslator(
        TranslatorInterface $translator,
        string $key,
        array $params,
        string $targetLocale,
    ): ?string {
        foreach ($this->getLocaleCandidates($targetLocale) as $candidate) {
            if (!$this->isAllowedCatalogLocale($candidate, $translator)) {
                continue;
            }
            $translated = $translator->trans($key, $params, self::DOMAIN, $candidate);
            if ($translated !== $key) {
                return $translated;
            }
        }

        if ($targetLocale !== self::FALLBACK_LOCALE) {
            $english = $translator->trans($key, $params, self::DOMAIN, self::FALLBACK_LOCALE);
            if ($english !== $key) {
                return $english;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function getLocaleCandidates(string $locale): array
    {
        $trimmed = trim($locale);
        $candidates = [];
        $push = static function (string $item) use (&$candidates): void {
            if ($item !== '' && !in_array($item, $candidates, true)) {
                $candidates[] = $item;
            }
        };

        $bcp47 = self::toBcp47($trimmed);
        $posix = str_replace('-', '_', $bcp47);

        $push($trimmed);
        $push($posix);
        $push($bcp47);
        $push(str_replace('-', '_', $trimmed));
        $push(strtolower(str_replace('-', '_', $trimmed)));

        $parts = explode('_', $posix);
        if (count($parts) >= 3) {
            $push($parts[0] . '_' . $parts[1]);
            $push($parts[0] . '-' . $parts[1]);
            // Language + Region shortcut when Script + Region are both present (e.g. zh_Hans_CN -> zh_CN)
            if (strlen($parts[1]) === 4 && (strlen($parts[2]) === 2 || strlen($parts[2]) === 3)) {
                $push($parts[0] . '_' . $parts[2]);
                $push($parts[0] . '-' . $parts[2]);
            }
        }

        if (count($parts) >= 2) {
            $push($parts[0]);
        }

        return $candidates;
    }

    private function lookupBundledEnglish(string $key): ?string
    {
        $catalog = $this->loadBundledEnglishCatalog();

        return $catalog[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function loadBundledEnglishCatalog(): array
    {
        if ($this->fallbackCatalogPath === null && self::$bundledEnglishCatalog !== null) {
            return self::$bundledEnglishCatalog;
        }

        $path = $this->fallbackCatalogPath ?? (\dirname(__DIR__, 2) . '/translations/' . self::DOMAIN . '.' . self::FALLBACK_LOCALE . '.yaml');
        $flat = [];

        if (class_exists(Yaml::class) && is_file($path)) {
            $parsed = Yaml::parseFile($path);
            if (is_array($parsed)) {
                $flat = $this->flattenCatalog($parsed);
            }
        }

        if ($this->fallbackCatalogPath === null) {
            self::$bundledEnglishCatalog = $flat;
        }

        return $flat;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flattenCatalog(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                foreach ($this->flattenCatalog($value, $fullKey) as $childKey => $childValue) {
                    $flat[$childKey] = $childValue;
                }
            } elseif (is_string($value)) {
                $flat[$fullKey] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function interpolate(string $message, array $params): string
    {
        if ($params === []) {
            return $message;
        }

        $replace = [];
        foreach ($params as $k => $v) {
            $replace[$k] = (string) $v;
        }

        return strtr($message, $replace);
    }
}
