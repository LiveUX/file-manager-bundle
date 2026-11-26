<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Translation;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Automated contributor & PR quality guard:
 *  - Ensures all translation catalogs parse cleanly and have 100% key parity with English.
 *  - Ensures every i18n key exported by BrowserComponent::getI18nJson() exists in all catalogs.
 *  - Ensures all bundle PHP source files enforce strict_types=1.
 */
final class TranslationParityTest extends TestCase
{
    private string $bundleRoot;

    protected function setUp(): void
    {
        $this->bundleRoot = dirname(__DIR__, 3);
    }

    public function testAllTranslationCatalogsHaveExactKeyParityWithEnglish(): void
    {
        $translationsDir = $this->bundleRoot . DIRECTORY_SEPARATOR . 'translations';
        $files = glob($translationsDir . DIRECTORY_SEPARATOR . 'LiveUXFileManagerBundle.*.yaml');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'Expected at least one translation catalog in translations/.');

        $catalogs = [];
        foreach ($files as $file) {
            if (preg_match('/LiveUXFileManagerBundle\.([^.]+)\.yaml$/', $file, $m) !== 1) {
                continue;
            }
            $parsed = Yaml::parseFile($file);
            self::assertIsArray($parsed, sprintf('Translation file "%s" must parse to an array.', basename($file)));
            $catalogs[$m[1]] = $this->flattenKeys($parsed);
        }

        self::assertArrayHasKey('en', $catalogs, 'English reference catalog (en) is required.');
        self::assertGreaterThanOrEqual(
            12,
            count($catalogs),
            'Expected at least 12 built-in UI translation catalogs in translations/.',
        );
        $referenceKeys = $catalogs['en'];

        foreach ($catalogs as $locale => $keys) {
            if ($locale === 'en') {
                continue;
            }

            $missing = array_values(array_diff($referenceKeys, $keys));
            $extra = array_values(array_diff($keys, $referenceKeys));

            self::assertSame(
                [],
                $missing,
                sprintf('Locale "%s" is missing translation key(s): %s', $locale, implode(', ', $missing)),
            );
            self::assertSame(
                [],
                $extra,
                sprintf('Locale "%s" has extra key(s) not present in "en": %s', $locale, implode(', ', $extra)),
            );
        }
    }

    public function testAllTranslationCatalogsPreservePlaceholdersAndNonEmptyValues(): void
    {
        $translationsDir = $this->bundleRoot . DIRECTORY_SEPARATOR . 'translations';
        $files = glob($translationsDir . DIRECTORY_SEPARATOR . 'LiveUXFileManagerBundle.*.yaml');
        self::assertIsArray($files);

        $catalogValues = [];
        foreach ($files as $file) {
            if (preg_match('/LiveUXFileManagerBundle\.([^.]+)\.yaml$/', $file, $m) !== 1) {
                continue;
            }
            $parsed = Yaml::parseFile($file);
            self::assertIsArray($parsed);
            $catalogValues[$m[1]] = $this->flattenValues($parsed);
        }

        self::assertArrayHasKey('en', $catalogValues);
        $referenceValues = $catalogValues['en'];

        foreach ($catalogValues as $locale => $values) {
            if ($locale === 'en') {
                continue;
            }

            $mismatches = $this->collectPlaceholderMismatches($locale, $referenceValues, $values);
            self::assertSame(
                [],
                $mismatches,
                sprintf('Locale "%s" has placeholder/value issue(s): %s', $locale, implode('; ', $mismatches)),
            );
        }
    }

    public function testEveryBrowserComponentI18nKeyExistsInReferenceCatalog(): void
    {
        $enFile = $this->bundleRoot . DIRECTORY_SEPARATOR . 'translations' . DIRECTORY_SEPARATOR . 'LiveUXFileManagerBundle.en.yaml';
        $parsed = Yaml::parseFile($enFile);
        self::assertIsArray($parsed);
        $enKeys = array_flip($this->flattenKeys($parsed));

        $componentFile = $this->bundleRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Twig' . DIRECTORY_SEPARATOR . 'Components' . DIRECTORY_SEPARATOR . 'BrowserComponent.php';
        $source = (string) file_get_contents($componentFile);

        $start = strpos($source, 'public function getI18nJson()');
        self::assertNotFalse($start, 'BrowserComponent::getI18nJson() not found.');
        $end = strpos($source, 'return $map;', $start);
        self::assertNotFalse($end, 'End of BrowserComponent::getI18nJson() not found.');

        $methodBody = substr($source, $start, $end - $start);
        preg_match_all("/'([a-z0-9_]+\.[a-z0-9_]+)'/", $methodBody, $matches);
        $requestedKeys = array_unique($matches[1]);

        self::assertNotEmpty($requestedKeys, 'Expected i18n keys in BrowserComponent::getI18nJson().');

        $missing = [];
        foreach ($requestedKeys as $key) {
            if (!isset($enKeys[$key])) {
                $missing[] = $key;
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf('BrowserComponent::getI18nJson() requests undefined translation key(s): %s', implode(', ', $missing)),
        );
    }

    public function testEveryTwigTemplateTranslationKeyExistsInReferenceCatalog(): void
    {
        $enFile = $this->bundleRoot . DIRECTORY_SEPARATOR . 'translations' . DIRECTORY_SEPARATOR . 'LiveUXFileManagerBundle.en.yaml';
        $parsed = Yaml::parseFile($enFile);
        self::assertIsArray($parsed);
        $enKeys = array_flip($this->flattenKeys($parsed));

        $templatesDir = $this->bundleRoot . DIRECTORY_SEPARATOR . 'templates';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesDir, FilesystemIterator::SKIP_DOTS));
        $missing = [];
        $checkedKeys = 0;

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            preg_match_all("/'([a-z0-9_]+(?:\.[a-z0-9_]+)*)'\|liveux_fm_trans/", $content, $matches);
            foreach ($matches[1] as $key) {
                ++$checkedKeys;
                if (!isset($enKeys[$key])) {
                    $missing[] = basename($file->getPathname()) . ':' . $key;
                }
            }
        }

        self::assertGreaterThan(50, $checkedKeys);
        self::assertSame(
            [],
            $missing,
            sprintf('Twig templates reference undefined translation key(s): %s', implode(', ', $missing)),
        );
    }

    public function testAllBundleSourceFilesDeclareStrictTypes(): void
    {
        $srcDir = $this->bundleRoot . DIRECTORY_SEPARATOR . 'src';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            ++$checked;
            $content = (string) file_get_contents($file->getPathname());
            $rel = str_replace($this->bundleRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());

            self::assertStringContainsString(
                'declare(strict_types=1);',
                $content,
                sprintf('Source file "%s" must include declare(strict_types=1);.', $rel),
            );
        }

        self::assertGreaterThan(20, $checked);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                foreach ($this->flattenKeys($value, $fullKey) as $childKey) {
                    $keys[] = $childKey;
                }
            } else {
                $keys[] = $fullKey;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function flattenValues(array $data, string $prefix = ''): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                foreach ($this->flattenValues($value, $fullKey) as $childKey => $childValue) {
                    $values[$childKey] = $childValue;
                }
            } else {
                $values[$fullKey] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $referenceValues
     * @param array<string, string> $localeValues
     *
     * @return list<string>
     */
    private function collectPlaceholderMismatches(
        string $locale,
        array $referenceValues,
        array $localeValues,
    ): array {
        $mismatches = [];
        foreach ($referenceValues as $key => $refText) {
            $locText = $localeValues[$key] ?? '';
            if (trim($locText) === '') {
                $mismatches[] = sprintf('%s (empty in %s)', $key, $locale);
                continue;
            }

            $refPlaceholders = $this->extractPlaceholders($refText);
            $locPlaceholders = $this->extractPlaceholders($locText);
            if ($refPlaceholders !== $locPlaceholders) {
                $mismatches[] = sprintf(
                    '%s (expected [%s], got [%s])',
                    $key,
                    implode(', ', $refPlaceholders),
                    implode(', ', $locPlaceholders),
                );
            }
        }

        return $mismatches;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $text): array
    {
        preg_match_all('/%[a-zA-Z0-9_]+%/', $text, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}
