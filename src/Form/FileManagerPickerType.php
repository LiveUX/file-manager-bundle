<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Form;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Theme\FileManagerThemes;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Symfony FormType for picking single or multiple (gallery) files via LiveUX FileManager.
 *
 * - multiple: false -> binds to ?string (URL or relative path)
 * - multiple: true  -> binds to list<string> (serialized as JSON in a hidden input)
 * - theme: null (inherits profile theme) | any built-in theme | 'unstyled' | any custom CSS theme name
 * - variant: 'compact' | 'card' | 'dropzone' | 'avatar' | 'input'
 * - template: optional custom Twig template path for 100% custom field markup
 */
final class FileManagerPickerType extends AbstractType
{
    public const array VARIANTS = ['compact', 'card', 'dropzone', 'avatar', 'input'];

    public function __construct(
        private readonly ?ConfigProviderInterface $configProvider = null,
        private readonly ?MessageTranslator $messageTranslator = null,
        private readonly ?VolumeRegistry $volumeRegistry = null,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isMultiple = $options['multiple'] === true;

        // Symfony UX LiveCollectionTrait::addCollectionItem() initializes new items as `[]`.
        // Normalize empty arrays cleanly so FileManagerPickerType works directly as a LiveCollectionType entry_type.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($isMultiple): void {
            $data = $event->getData();
            if ($data === []) {
                $event->setData($isMultiple ? '[]' : '');
            } elseif ($isMultiple && is_array($data)) {
                $filtered = [];
                foreach ($data as $item) {
                    if ($item === null || $item === '') {
                        continue;
                    }
                    if (!is_string($item) || !mb_check_encoding($item, 'UTF-8')) {
                        // Preserve malformed/non-UTF-8 array submissions as invalid payload instead of silently wiping to `[]`.
                        $event->setData('__liveux_fm_invalid_payload__');

                        return;
                    }
                    $filtered[] = $item;
                }
                $encoded = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $event->setData(is_string($encoded) ? $encoded : '__liveux_fm_invalid_payload__');
            }
        });

        // Server-side value validation: a tampered POST must never persist `javascript:` / `data:` / `//host` / `..` / `%2e%2e` references.
        $translator = $this->messageTranslator;
        $validationLocale = $this->resolveLocale(
            is_string($options['profile']) ? $options['profile'] : 'default',
            is_string($options['locale']) ? $options['locale'] : null,
        );
        $returnType = is_string($options['return_type']) ? $options['return_type'] : 'url';
        $profileName = is_string($options['profile']) ? $options['profile'] : 'default';
        $profileConfig = null;
        if ($this->configProvider !== null) {
            try {
                $profileConfig = $this->configProvider->getProfile($profileName);
            } catch (Throwable) {
                $profileConfig = null;
            }
        }
        $maxFiles = $isMultiple && is_int($options['max_files']) && $options['max_files'] > 0 ? $options['max_files'] : null;

        if ($maxFiles !== null) {
            $locale = $validationLocale;
            $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($maxFiles, $translator, $locale): void {
                $data = $event->getForm()->getData();
                if (is_array($data) && count($data) > $maxFiles) {
                    $message = $translator !== null
                        ? $translator->trans('errors.picker_max_files', ['%limit%' => $maxFiles], $locale, sprintf('You may select at most %d file(s).', $maxFiles))
                        : sprintf('You may select at most %d file(s).', $maxFiles);
                    $event->getForm()->addError(new FormError(
                        $message,
                        'errors.picker_max_files',
                        ['%limit%' => $maxFiles],
                    ));
                }
            });
        }

        $volumeUrlPrefixes = $this->collectProfileUrlPrefixes($profileConfig);
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($translator, $validationLocale, $returnType, $profileConfig, $maxFiles, $volumeUrlPrefixes): void {
            $data = $event->getForm()->getData();
            if ($maxFiles !== null && is_array($data) && count($data) > $maxFiles) {
                return;
            }
            $seen = [];
            foreach (is_array($data) ? $data : [$data] as $value) {
                if (!is_string($value) || $value === '' || isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                if (self::isSafePickerValueForProfile($value, $returnType, $profileConfig, $volumeUrlPrefixes)) {
                    continue;
                }

                $message = $translator !== null
                    ? $translator->trans('errors.picker_invalid_value', [], $validationLocale, 'The selected file reference is not valid.')
                    : 'The selected file reference is not valid.';
                $event->getForm()->addError(new FormError($message, 'errors.picker_invalid_value'));

                return;
            }
        });

        if ($isMultiple) {
            $hardItemCap = max(500, ($maxFiles ?? 0) + 1);
            $builder->addModelTransformer(new CallbackTransformer(
                static function (mixed $value): string {
                    if (is_array($value)) {
                        $filtered = array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== ''));

                        return json_encode($filtered, JSON_UNESCAPED_SLASHES) ?: '[]';
                    }

                    if (is_string($value) && $value !== '') {
                        return $value;
                    }

                    return '[]';
                },
                static function (mixed $value) use ($hardItemCap): array {
                    if (is_array($value)) {
                        if (count($value) > $hardItemCap) {
                            throw new TransformationFailedException('Multiple picker payload exceeds maximum allowed item count.');
                        }

                        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== ''));
                    }

                    if (!is_string($value) || trim($value) === '') {
                        return [];
                    }

                    if (strlen($value) > 262144) {
                        throw new TransformationFailedException('Multiple picker JSON payload exceeds maximum allowed size.');
                    }

                    $decoded = json_decode($value, true);
                    if (!is_array($decoded)) {
                        throw new TransformationFailedException('Malformed JSON payload submitted to multiple file picker.');
                    }

                    if (count($decoded) > $hardItemCap) {
                        throw new TransformationFailedException('Multiple picker JSON payload exceeds maximum allowed item count.');
                    }

                    foreach ($decoded as $item) {
                        if (!is_scalar($item) && $item !== null) {
                            throw new TransformationFailedException('Invalid item type inside multiple file picker payload.');
                        }
                    }

                    return array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v) && $v !== ''));
                },
            ));
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $resolvedTheme = $this->resolveTheme($options['theme'], (string) $options['profile']);

        $view->vars['profile'] = $options['profile'];
        $view->vars['volume'] = $options['volume'];
        $view->vars['multiple'] = $options['multiple'];
        $view->vars['max_files'] = $options['max_files'];
        $view->vars['mode'] = $options['mode'];
        $view->vars['position'] = $options['position'];
        $view->vars['mobile_mode'] = $this->resolveMobileDialogMode(
            is_string($options['mobile_mode']) ? $options['mobile_mode'] : null,
            (string) $options['profile'],
        );
        $view->vars['return_type'] = $options['return_type'];
        $view->vars['preview'] = $options['preview'];
        $view->vars['button_label'] = $options['button_label'];
        $view->vars['placeholder'] = $options['placeholder'];
        $view->vars['theme'] = $resolvedTheme;
        $view->vars['color_scheme'] = $this->resolveColorScheme(
            is_string($options['color_scheme']) ? $options['color_scheme'] : null,
            (string) $options['profile'],
        );
        $view->vars['variant'] = $options['variant'];
        $view->vars['template'] = $options['template'];
        $resolvedLocale = $this->resolveLocale(
            (string) $options['profile'],
            is_string($options['locale']) ? $options['locale'] : null,
        );
        $view->vars['locale'] = $resolvedLocale;
        $view->vars['bcp47_locale'] = MessageTranslator::toBcp47($resolvedLocale);
        $view->vars['is_rtl'] = MessageTranslator::isRtlLocale($resolvedLocale);
        $view->vars['thumb_url'] = $this->resolveThumbEndpointUrl();
        $view->vars['preview_url_prefix'] = $this->resolvePreviewUrlPrefix(
            (string) $options['profile'],
            is_string($options['volume']) ? $options['volume'] : null,
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'profile' => 'default',
            'volume' => null,
            'locale' => null,
            'multiple' => false,
            'max_files' => null,
            'mode' => 'modal',
            'position' => 'end',
            'mobile_mode' => null,
            'return_type' => 'url',
            'preview' => true,
            'button_label' => null,
            'placeholder' => null,
            'theme' => null,
            'color_scheme' => null,
            'variant' => 'compact',
            'template' => null,
            'required' => false,
        ]);

        $resolver->setAllowedTypes('profile', 'string');
        $resolver->setAllowedTypes('volume', ['null', 'string']);
        $resolver->setAllowedTypes('locale', ['null', 'string']);
        $resolver->setAllowedTypes('multiple', 'bool');
        $resolver->setAllowedTypes('max_files', ['null', 'int']);
        $resolver->setAllowedValues('mode', ['modal', 'offcanvas']);
        $resolver->setAllowedValues('position', ['start', 'end', 'top', 'bottom']);
        $resolver->setAllowedValues('mobile_mode', [null, 'auto', 'fullscreen', 'sheet']);
        $resolver->setAllowedValues('return_type', ['url', 'path']);
        $resolver->setAllowedTypes('preview', 'bool');
        $resolver->setAllowedTypes('button_label', ['null', 'string']);
        $resolver->setAllowedTypes('placeholder', ['null', 'string']);
        $resolver->setAllowedTypes('template', ['null', 'string']);
        $resolver->setAllowedValues('theme', static fn (mixed $v): bool => $v === null || (is_string($v) && FileManagerThemes::isSafeThemeToken($v)));
        $resolver->setAllowedValues('color_scheme', [null, 'auto', 'light', 'dark']);
        $resolver->setAllowedValues('variant', self::VARIANTS);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'liveux_file_manager_picker';
    }

    /**
     * Collects all configured public URL prefixes (profile + attached named volumes) so custom
     * single-segment trash directories under named volume prefixes cannot bypass validation.
     *
     * @return list<string>
     */
    private function collectProfileUrlPrefixes(?\LiveUX\FileManagerBundle\Config\ProfileConfig $profile): array
    {
        if ($profile === null) {
            return [];
        }

        $prefixes = [];
        $addPrefix = static function (?string $raw) use (&$prefixes): void {
            if (!is_string($raw) || trim($raw) === '') {
                return;
            }
            $trimmed = trim($raw);
            $prefixPath = preg_match('#^https?://#i', $trimmed) === 1
                ? (string) (parse_url($trimmed, PHP_URL_PATH) ?? '')
                : $trimmed;
            $clean = trim($prefixPath, '/');
            if ($clean !== '' && !in_array($clean, $prefixes, true)) {
                $prefixes[] = $clean;
            }
        };

        $addPrefix($profile->urlPrefix);

        if ($this->volumeRegistry !== null) {
            try {
                foreach ($this->volumeRegistry->getVolumesForProfile($profile) as $vol) {
                    $addPrefix($vol->urlPrefix);
                }
            } catch (Throwable) {
            }
        } elseif ($this->configProvider !== null) {
            foreach ($profile->volumes as $volName) {
                try {
                    if ($this->configProvider->hasVolume($volName)) {
                        $addPrefix($this->configProvider->getVolume($volName)->urlPrefix);
                    }
                } catch (Throwable) {
                }
            }
        }

        return $prefixes;
    }

    /**
     * @param list<string> $volumeUrlPrefixes
     */
    private static function isSafePickerValueForProfile(
        string $value,
        string $returnType,
        ?\LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        array $volumeUrlPrefixes = [],
    ): bool {
        if (!self::isSafePickerValue($value, $returnType)) {
            return false;
        }

        if ($profile === null) {
            return true;
        }

        $normalizer = new PathNormalizer();
        $guard = new \LiveUX\FileManagerBundle\Security\FileSecurityGuard();

        if ($returnType === 'path') {
            try {
                $cleanPath = $normalizer->normalize($value);
                $guard->assertAccessiblePath($cleanPath, $profile);
            } catch (Throwable) {
                return false;
            }

            return true;
        }

        return self::isUrlAccessibleForProfile($value, $profile, $normalizer, $guard, $volumeUrlPrefixes);
    }

    /**
     * @param list<string> $volumeUrlPrefixes
     */
    private static function isUrlAccessibleForProfile(
        string $value,
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        PathNormalizer $normalizer,
        \LiveUX\FileManagerBundle\Security\FileSecurityGuard $guard,
        array $volumeUrlPrefixes = [],
    ): bool {
        $withoutFragment = explode('#', $value, 2)[0];
        $parts = explode('?', $withoutFragment, 2);
        $rawQuery = $parts[1] ?? '';

        if ($rawQuery !== '') {
            $qPaths = self::extractQueryPathParams($rawQuery);
            if ($qPaths === null) {
                return false;
            }
            foreach ($qPaths as $qPath) {
                $decodedQ = trim(rawurldecode(str_replace('+', ' ', $qPath)));
                if ($decodedQ === '') {
                    continue;
                }
                try {
                    $cleanQ = $normalizer->normalize($decodedQ);
                    if ($cleanQ !== '') {
                        $guard->assertAccessiblePath($cleanQ, $profile);
                    }
                } catch (Throwable) {
                    return false;
                }
            }
        }

        $urlPath = parse_url($withoutFragment, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '') {
            $decodedPath = trim(rawurldecode($urlPath), '/');
            if ($decodedPath !== '') {
                try {
                    $cleanUrlPath = $normalizer->normalize($decodedPath);
                    if ($cleanUrlPath !== '') {
                        $guard->assertAccessiblePath($cleanUrlPath, $profile);
                        $matchedConfiguredPrefix = false;
                        foreach (self::collectCandidatePrefixes($profile, $volumeUrlPrefixes) as $cleanPrefix) {
                            $stripped = self::stripKnownPrefixPath($cleanUrlPath, $cleanPrefix);
                            if ($stripped !== null) {
                                $matchedConfiguredPrefix = true;
                                if ($stripped !== '' && $stripped !== $cleanUrlPath) {
                                    $guard->assertAccessiblePath($stripped, $profile);
                                }
                            }
                        }
                        self::assertNoNestedTrashInUrlPath($cleanUrlPath, $profile, $guard, $matchedConfiguredPrefix);
                    }
                } catch (Throwable) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<string> $volumeUrlPrefixes
     * @return list<string>
     */
    private static function collectCandidatePrefixes(
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        array $volumeUrlPrefixes,
    ): array {
        $prefixes = $volumeUrlPrefixes;
        $rawPrefix = $profile->urlPrefix;
        if (is_string($rawPrefix) && trim($rawPrefix) !== '') {
            $trimmed = trim($rawPrefix);
            $prefixPath = preg_match('#^https?://#i', $trimmed) === 1
                ? (string) (parse_url($trimmed, PHP_URL_PATH) ?? '')
                : $trimmed;
            $cleanPrefix = trim($prefixPath, '/');
            if ($cleanPrefix !== '' && !in_array($cleanPrefix, $prefixes, true)) {
                $prefixes[] = $cleanPrefix;
            }
        }

        return $prefixes;
    }

    private static function stripKnownPrefixPath(string $cleanUrlPath, string $cleanPrefix): ?string
    {
        if ($cleanPrefix === '') {
            return null;
        }
        if ($cleanUrlPath === $cleanPrefix) {
            return '';
        }
        if (str_starts_with($cleanUrlPath, $cleanPrefix . '/')) {
            return substr($cleanUrlPath, strlen($cleanPrefix) + 1);
        }

        return null;
    }

    private static function assertNoNestedTrashInUrlPath(
        string $cleanUrlPath,
        \LiveUX\FileManagerBundle\Config\ProfileConfig $profile,
        \LiveUX\FileManagerBundle\Security\FileSecurityGuard $guard,
        bool $matchedConfiguredPrefix = false,
    ): void {
        $rawTrash = is_string($profile->trash['directory'] ?? null) ? $profile->trash['directory'] : '.trash';
        $trashClean = trim(str_replace('\\', '/', UnicodeHelper::trimSpaces($rawTrash)), '/');
        if ($trashClean === '') {
            return;
        }

        // If a known profile/volume URL prefix already matched and trash is a single-segment directory,
        // the exact volume-relative path was already validated above (avoiding false positives on nested user folders).
        if (!str_contains($trashClean, '/') && $matchedConfiguredPrefix) {
            return;
        }

        // For nested custom trash directories (e.g. `system/trash`) or unmatched volume URL prefixes
        // (e.g. `/media/recycle/secret.jpg`), verify every suffix subpath against assertAccessiblePath().
        $segments = explode('/', $cleanUrlPath);
        $count = count($segments);
        for ($i = 1; $i < $count; ++$i) {
            $suffix = implode('/', array_slice($segments, $i));
            if ($suffix !== '') {
                $guard->assertAccessiblePath($suffix, $profile);
            }
        }
    }

    private static function isSafePickerValue(string $value, string $returnType = 'url'): bool {
        if (UnicodeHelper::hasForbiddenControls($value) || mb_strlen($value, 'UTF-8') > 2048) {
            return false;
        }

        $decodedOnce = rawurldecode($value);
        $decodedTwice = rawurldecode($decodedOnce);
        if (
            (mb_check_encoding($decodedOnce, 'UTF-8') && UnicodeHelper::hasForbiddenControls($decodedOnce))
            || (mb_check_encoding($decodedTwice, 'UTF-8') && UnicodeHelper::hasForbiddenControls($decodedTwice))
            || preg_match('/[\x00-\x1F\x7F]/', $decodedOnce) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $decodedTwice) === 1
        ) {
            return false;
        }

        $normalized = preg_replace('/[\x00-\x20\x7F]+/u', '', $value);
        if ($normalized === null || $normalized === '') {
            return false;
        }

        if (str_starts_with($normalized, '//') || str_starts_with($normalized, '\\') || str_starts_with($normalized, '/\\')) {
            return false;
        }

        if ($returnType === 'path') {
            if (
                str_starts_with($normalized, '/')
                || str_contains($value, '?')
                || str_contains($value, '#')
                || preg_match('/[\x{FF0F}\x{2215}\x{2044}\x{FF3C}]/u', $value) === 1
                || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c|2e)|%25(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c|2e)/i', $value) === 1
                || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $normalized) === 1
            ) {
                return false;
            }

            try {
                $cleanPath = (new PathNormalizer())->normalize($value);
                if ($cleanPath === '') {
                    return false;
                }
                $guard = new \LiveUX\FileManagerBundle\Security\FileSecurityGuard();
                foreach (explode('/', $cleanPath) as $seg) {
                    $foldedLower = mb_strtolower(
                        UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($seg))),
                        'UTF-8',
                    );
                    if ($foldedLower === '' || $foldedLower === '.trash' || str_starts_with($foldedLower, '.')) {
                        return false;
                    }
                }
                $guard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        // Any explicit scheme must be plain http(s); javascript:, data:, vbscript:, file: ... are rejected.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $normalized) === 1) {
            if (preg_match('#^https?://#i', $normalized) !== 1) {
                return false;
            }
            if (!self::isSafeHttpPickerUrl($value)) {
                return false;
            }
        }

        if (preg_match('/%25(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c|2e)/i', $value) === 1) {
            return false;
        }

        $rawNoHash = explode('#', $value, 2)[0];
        $rawParts = explode('?', $rawNoHash, 2);
        $rawBase = $rawParts[0];
        $rawQuery = $rawParts[1] ?? '';

        if (preg_match('#^https?://#i', $rawBase) === 1) {
            $parsedRawPath = parse_url($rawBase, PHP_URL_PATH);
            if ($parsedRawPath === false) {
                return false;
            }
            $rawUrlPath = is_string($parsedRawPath) ? $parsedRawPath : '';
        } else {
            $rawUrlPath = $rawBase;
        }

        if ($rawUrlPath !== '' && !self::areUrlPathSegmentsSafe($rawUrlPath)) {
            return false;
        }
        if (preg_match('/^\/(?![\/\\\\\s]|%2[fF]|%5[cC]|%25(?:2[fF]|5[cC]))/', $rawBase) === 1 && !self::areUrlPathSegmentsSafe($rawBase)) {
            return false;
        }

        $guard = new \LiveUX\FileManagerBundle\Security\FileSecurityGuard();
        $decodedOncePath = rawurldecode($rawUrlPath);
        $decodedTwicePath = rawurldecode($decodedOncePath);

        foreach ([$decodedOncePath, $decodedTwicePath] as $candidatePath) {
            // Allow encoded %23 ('#') in filenames, but reject encoded query delimiter '?' (%3F).
            if (str_contains($candidatePath, '?')) {
                return false;
            }
            $normalizedPath = preg_replace('/[\x00-\x20\x7F]+/u', '', $candidatePath);
            if ($normalizedPath === null) {
                return false;
            }
            if ($rawUrlPath !== '' && $normalizedPath === '') {
                return false;
            }
            if (
                str_starts_with($normalizedPath, '//')
                || str_starts_with($normalizedPath, '/\\')
                || str_starts_with($normalizedPath, '\\')
                || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $normalizedPath) === 1
            ) {
                return false;
            }
            if (!self::isSafeUrlPathCandidate($candidatePath, $guard)) {
                return false;
            }
        }

        if ($rawQuery !== '' && !self::isSafeUrlQueryCandidate($rawQuery, $guard)) {
            return false;
        }

        return true;
    }

    private static function isSafeUrlPathCandidate(
        string $urlPath,
        \LiveUX\FileManagerBundle\Security\FileSecurityGuard $guard,
    ): bool {
        if (!self::areUrlPathSegmentsSafe($urlPath)) {
            return false;
        }

        $baseLeaf = PathNormalizer::basenameUtf8($urlPath);
        $foldedLeaf = UnicodeHelper::foldDotEquivalents($baseLeaf);
        $leafExt = PathNormalizer::extensionUtf8($foldedLeaf);
        if ($leafExt !== '' && in_array($leafExt, \LiveUX\FileManagerBundle\Security\FileSecurityGuard::BLOCKED_EXTENSIONS, true)) {
            return false;
        }

        if ($baseLeaf !== '') {
            try {
                $guard->assertSafeFilename($baseLeaf);
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeUrlQueryCandidate(
        string $rawQuery,
        \LiveUX\FileManagerBundle\Security\FileSecurityGuard $guard,
    ): bool {
        $pathValues = self::extractQueryPathParams($rawQuery);
        if ($pathValues === null || count($pathValues) > 1) {
            // Reject array-syntax path[]=... and HTTP Parameter Pollution (duplicate path= / %70ath= parameters).
            return false;
        }

        foreach ($pathValues as $rawQPath) {
            $qPath = rawurldecode(str_replace('+', ' ', $rawQPath));
            if (trim($qPath) === '') {
                continue;
            }

            foreach ([$rawQPath, $qPath, rawurldecode($qPath), rawurldecode(rawurldecode($qPath))] as $candidateQPath) {
                if (!self::areUrlPathSegmentsSafe($candidateQPath)) {
                    return false;
                }
                $leaf = PathNormalizer::basenameUtf8($candidateQPath);
                if ($leaf !== '') {
                    try {
                        $guard->assertSafeFilename($leaf);
                    } catch (Throwable) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @return ?list<string> Null when array syntax (`path[]=...` / `+path[]=...`) or PHP key-mangling HPP is present.
     */
    private static function extractQueryPathParams(string $rawQuery): ?array
    {
        if ($rawQuery === '') {
            return [];
        }

        $values = [];
        foreach (explode('&', $rawQuery) as $pair) {
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

        parse_str($rawQuery, $parsed);
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

    private static function areUrlPathSegmentsSafe(string $path): bool
    {
        foreach (preg_split('#[/\\\\]#', $path) ?: [] as $segment) {
            $trimmedSeg = trim($segment);
            if ($trimmedSeg === '') {
                continue;
            }
            if ($trimmedSeg === '.' || $trimmedSeg === '..' || preg_match('/[\x{FF0F}\x{2215}\x{2044}\x{FF3C}]/u', $trimmedSeg) === 1) {
                return false;
            }
            $foldedSeg = mb_strtolower(UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($trimmedSeg))), 'UTF-8');
            if (
                $foldedSeg === ''
                || $foldedSeg === '.'
                || $foldedSeg === '..'
                || $foldedSeg === '.trash'
                || str_starts_with($foldedSeg, '.')
                || in_array($foldedSeg, \LiveUX\FileManagerBundle\Security\FileSecurityGuard::BLOCKED_FILENAMES, true)
            ) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeHttpPickerUrl(string $url): bool
    {
        $beforeQuery = explode('?', explode('#', $url, 2)[0], 2)[0];
        if (str_contains($beforeQuery, '\\')) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        return RemoteUrlImporter::isSafeRemoteHostLiteral($host);
    }

    private function resolveTheme(mixed $explicitTheme, string $profileName): string
    {
        if (is_string($explicitTheme) && FileManagerThemes::isSafeThemeToken($explicitTheme)) {
            return trim($explicitTheme);
        }

        if ($this->configProvider !== null) {
            try {
                $profileTheme = $this->configProvider->getProfile($profileName)->theme;
                if (FileManagerThemes::isSafeThemeToken($profileTheme)) {
                    return trim($profileTheme);
                }
            } catch (Throwable) {
            }
        }

        return 'bootstrap5';
    }

    private function resolveColorScheme(?string $explicitScheme, string $profileName): string
    {
        if (is_string($explicitScheme) && in_array($explicitScheme, ['auto', 'light', 'dark'], true)) {
            return $explicitScheme;
        }

        if ($this->configProvider !== null) {
            try {
                $scheme = $this->configProvider->getProfile($profileName)->ui['color_scheme'] ?? null;
                if (is_string($scheme) && in_array($scheme, ['auto', 'light', 'dark'], true)) {
                    return $scheme;
                }
            } catch (Throwable) {
            }
        }

        return 'auto';
    }

    private function resolveMobileDialogMode(?string $explicitMode, string $profileName): string
    {
        if (is_string($explicitMode) && in_array($explicitMode, ['auto', 'fullscreen', 'sheet'], true)) {
            return $explicitMode;
        }

        if ($this->configProvider !== null) {
            try {
                $mode = $this->configProvider->getProfile($profileName)->ui['mobile_dialog_mode'] ?? 'auto';
                if (is_string($mode) && in_array($mode, ['auto', 'fullscreen', 'sheet'], true)) {
                    return $mode;
                }
            } catch (Throwable) {
            }
        }

        return 'auto';
    }

    private function resolveLocale(string $profileName, ?string $explicitLocale = null): ?string
    {
        if ($explicitLocale !== null && MessageTranslator::isValidLocaleString($explicitLocale)) {
            return trim($explicitLocale);
        }

        if ($this->configProvider !== null) {
            try {
                $locale = $this->configProvider->getProfile($profileName)->locale;
                if (is_string($locale) && MessageTranslator::isValidLocaleString($locale)) {
                    return trim($locale);
                }
            } catch (Throwable) {
            }
        }

        $translatorLocale = $this->translator?->getLocale();
        if (is_string($translatorLocale) && MessageTranslator::isValidLocaleString($translatorLocale)) {
            return trim($translatorLocale);
        }

        return null;
    }

    private function resolveThumbEndpointUrl(): string
    {
        if ($this->urlGenerator === null) {
            return '';
        }

        try {
            return $this->urlGenerator->generate('liveux_file_manager_thumb');
        } catch (Throwable) {
            return '';
        }
    }

    private function resolvePreviewUrlPrefix(string $profileName, ?string $volumeName): string
    {
        if ($this->configProvider === null) {
            return '';
        }

        try {
            $profile = $this->configProvider->getProfile($profileName);
            $prefix = null;
            if ($this->volumeRegistry !== null) {
                $vol = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName !== null && $volumeName !== '' ? $volumeName : null);
                $prefix = $vol->private ? null : $vol->urlPrefix;
            } else {
                $targetVolume = $volumeName !== null && $volumeName !== '' ? $volumeName : ($profile->volumes[0] ?? null);
                if (is_string($targetVolume) && $targetVolume !== '' && in_array($targetVolume, $profile->volumes, true) && $this->configProvider->hasVolume($targetVolume)) {
                    $vol = $this->configProvider->getVolume($targetVolume);
                    $prefix = $vol->private ? null : $vol->urlPrefix;
                } elseif (!$profile->private) {
                    $prefix = $profile->urlPrefix;
                }
            }

            if (!is_string($prefix) || trim($prefix) === '' || str_contains($prefix, '{')) {
                return '';
            }

            $clean = rtrim(trim($prefix), '/');

            return self::isSafePickerValue($clean) ? $clean : '';
        } catch (Throwable) {
            return '';
        }
    }
}
