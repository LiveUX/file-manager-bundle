<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MessageTranslatorTest extends TestCase
{
    public function testTranslatesSecurityAndPathExceptionsToTurkishEnglishAndFrench(): void
    {
        $translator = $this->createBundleTranslator();
        $messageTranslator = new MessageTranslator($translator);

        $guard = new FileSecurityGuard();
        try {
            $guard->assertSafeFilename('evil.php');
            self::fail('Expected UploadValidationException was not thrown.');
        } catch (FileManagerException $e) {
            self::assertSame('".php" dosya uzantısı güvenlik nedeniyle engellendi.', $messageTranslator->translateException($e, 'tr'));
            self::assertSame('File extension ".php" is blocked for security reasons.', $messageTranslator->translateException($e, 'en'));
            self::assertSame('L\'extension « .php » est bloquée pour des raisons de sécurité.', $messageTranslator->translateException($e, 'fr'));
        }

        $normalizer = new PathNormalizer();
        try {
            $normalizer->normalize('../secret');
            self::fail('Expected InvalidPathException was not thrown.');
        } catch (FileManagerException $e) {
            self::assertSame('Üst dizine geçiş ("..") güvenlik nedeniyle engellendi.', $messageTranslator->translateException($e, 'tr'));
            self::assertSame('Path traversal ("..") is not allowed.', $messageTranslator->translateException($e, 'en'));
            self::assertSame('La traversée de répertoire (« .. ») n\'est pas autorisée.', $messageTranslator->translateException($e, 'fr'));
        }
    }

    public function testResolveLocalePrioritizesExplicitPayloadThenRequestThenProfileAndBlocksInvalidValues(): void
    {
        $messageTranslator = new MessageTranslator();
        $trProfile = (new YamlConfigProvider(['locale' => 'tr'], [], ['default' => []]))->getProfile('default');
        $nullProfile = (new YamlConfigProvider([], [], ['default' => []]))->getProfile('default');

        $req = Request::create('/api', 'POST', ['locale' => 'fr']);
        self::assertSame('de', $messageTranslator->resolveLocale($req, $trProfile, ['locale' => 'de']));
        self::assertSame('fr', $messageTranslator->resolveLocale($req, $trProfile, []));

        $queryReq = Request::create('/api?locale=tr');
        self::assertSame('tr', $messageTranslator->resolveLocale($queryReq, $nullProfile));

        $plainReq = Request::create('/api');
        self::assertSame('tr', $messageTranslator->resolveLocale($plainReq, $trProfile));

        $maliciousReq = Request::create('/api?locale=../../etc/passwd');
        self::assertSame('tr', $messageTranslator->resolveLocale($maliciousReq, $trProfile));
    }

    public function testFallsBackToInterpolatedEnglishWhenTranslatorIsNull(): void
    {
        $messageTranslator = new MessageTranslator();
        $msg = $messageTranslator->trans('messages.folder_created', ['%name%' => 'docs'], 'tr', 'Folder created: %name%');

        self::assertSame('Folder created: docs', $msg);
    }

    public function testFallsBackToBundledEnglishCatalogWhenTranslatorIsNullAndNoFallbackProvided(): void
    {
        $messageTranslator = new MessageTranslator();

        self::assertSame('Upload', $messageTranslator->trans('actions.upload', [], 'de'));
        self::assertSame('Max: 5', $messageTranslator->trans('picker.max', ['%max%' => 5], 'ja'));
        self::assertSame('custom.unknown_key', $messageTranslator->trans('custom.unknown_key', [], 'de'));
    }

    public function testUnsupportedLocaleAlwaysFallsBackToEnglishEvenWhenHostFallbackIsNonEnglish(): void
    {
        $translator = $this->createBundleTranslator();
        $translator->setFallbackLocales(['tr']);
        $messageTranslator = new MessageTranslator($translator);

        self::assertSame('Upload', $messageTranslator->trans('actions.upload', [], 'de'));
        self::assertSame('Upload', $messageTranslator->trans('actions.upload', [], 'ja'));
        self::assertSame('Yükle', $messageTranslator->trans('actions.upload', [], 'tr'));
        self::assertSame('Téléverser', $messageTranslator->trans('actions.upload', [], 'fr'));
    }

    public function testUnsupportedLocaleFallsBackToEnglishEvenWhenHostFallbackHasNoEnglish(): void
    {
        $translator = $this->createBundleTranslator();
        $translator->setFallbackLocales(['de']);
        $messageTranslator = new MessageTranslator($translator);

        self::assertSame('Upload', $messageTranslator->trans('actions.upload', [], 'de'));
        self::assertSame('Pause', $messageTranslator->trans('actions.pause', [], 'es'));
    }

    public function testPartialCustomLocaleUsesCustomKeysAndFallsBackToEnglishForMissingKeys(): void
    {
        $translator = $this->createBundleTranslator();
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'actions.upload' => 'Hochladen',
        ], 'de', MessageTranslator::DOMAIN);
        $translator->setFallbackLocales(['tr']);

        $messageTranslator = new MessageTranslator($translator);

        self::assertSame('Hochladen', $messageTranslator->trans('actions.upload', [], 'de'));
        self::assertSame('Pause', $messageTranslator->trans('actions.pause', [], 'de'));
    }

    public function testRegionalLocaleResolvesToBaseLanguageAndUnknownRegionalFallsBackToEnglish(): void
    {
        $translator = $this->createBundleTranslator();
        $translator->setFallbackLocales(['tr']);
        $messageTranslator = new MessageTranslator($translator);

        self::assertSame('Yükle', $messageTranslator->trans('actions.upload', [], 'tr_TR'));
        self::assertSame('Téléverser', $messageTranslator->trans('actions.upload', [], 'fr-CA'));
        self::assertSame('Upload', $messageTranslator->trans('actions.upload', [], 'es-MX'));
    }

    public function testInvalidLocaleStringSafelyFallsBackToEnglishWithoutThrowing(): void
    {
        $translator = $this->createBundleTranslator();
        $messageTranslator = new MessageTranslator($translator);

        self::assertSame('Upload', $messageTranslator->trans('actions.upload', [], '../../etc/passwd'));
    }

    public function testPlainTranslatorWithoutBagInterfaceRetriesEnglishThenBundledCatalog(): void
    {
        $plainTranslator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                if ($locale === 'en' && $id === 'actions.upload') {
                    return 'Custom English Upload';
                }

                return $id;
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        $messageTranslator = new MessageTranslator($plainTranslator);

        self::assertSame('Custom English Upload', $messageTranslator->trans('actions.upload', [], 'de'));
        self::assertSame('Pause', $messageTranslator->trans('actions.pause', [], 'de'));
    }

    public function testTwigExtensionTranslateUsesGuaranteedEnglishFallback(): void
    {
        $translator = $this->createBundleTranslator();
        $translator->setFallbackLocales(['de']);
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $extension = new FileManagerTwigExtension($urlGenerator, null, new MessageTranslator($translator));

        self::assertSame('Yükle', $extension->translate('actions.upload', [], 'tr'));
        self::assertSame('Upload', $extension->translate('actions.upload', [], 'de'));
        self::assertSame('Max: 8', $extension->translate('picker.max', ['%max%' => 8], 'de'));
    }

    public function testAllTranslationCatalogsHaveIdenticalKeys(): void
    {
        $dir = \dirname(__DIR__, 3) . '/translations';
        $enKeys = $this->flattenKeys((array) Yaml::parseFile($dir . '/LiveUXFileManagerBundle.en.yaml'));
        $trKeys = $this->flattenKeys((array) Yaml::parseFile($dir . '/LiveUXFileManagerBundle.tr.yaml'));
        $frKeys = $this->flattenKeys((array) Yaml::parseFile($dir . '/LiveUXFileManagerBundle.fr.yaml'));

        sort($enKeys);
        sort($trKeys);
        sort($frKeys);

        self::assertSame($enKeys, $trKeys, 'Turkish catalog keys must match English catalog keys 1:1.');
        self::assertSame($enKeys, $frKeys, 'French catalog keys must match English catalog keys 1:1.');
    }

    private function createBundleTranslator(): Translator
    {
        $dir = \dirname(__DIR__, 3) . '/translations';
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $dir . '/LiveUXFileManagerBundle.en.yaml', 'en', MessageTranslator::DOMAIN);
        $translator->addResource('yaml', $dir . '/LiveUXFileManagerBundle.tr.yaml', 'tr', MessageTranslator::DOMAIN);
        $translator->addResource('yaml', $dir . '/LiveUXFileManagerBundle.fr.yaml', 'fr', MessageTranslator::DOMAIN);

        return $translator;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $k => $v) {
            $full = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                foreach ($this->flattenKeys($v, $full) as $child) {
                    $keys[] = $child;
                }
            } else {
                $keys[] = $full;
            }
        }

        return $keys;
    }
}
