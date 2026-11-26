<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\EventListener;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;
use Twig\Environment;

/**
 * Automatically injects the lazy-loaded DialogHost markup right before </body>
 * on full HTML responses (similar to Symfony's WebDebugToolbarListener), so
 * Modal, OffCanvas, FormType pickers and TinyMCE work with zero base.html.twig edits.
 */
final readonly class AutoInjectDialogHostSubscriber implements EventSubscriberInterface
{
    private const string HOST_MARKER = 'liveux--file-manager-bundle--dialog';

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private ?Environment $twig = null,
        private bool $enabled = true,
        private string $defaultProfile = 'default',
        private int $defaultZIndex = 1050,
        private ?ConfigProviderInterface $configProvider = null,
        ?AuthorizationCheckerInterface $authChecker = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -128],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->shouldInject($event)) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }

        if (str_contains($content, self::HOST_MARKER)) {
            return;
        }

        $bodyPos = $this->findLastTopLevelBodyCloseOffset($content);
        if ($bodyPos === null) {
            return;
        }

        $resolvedProfile = $this->resolveProfileName();
        $authorizedProfile = $resolvedProfile !== null
            ? $this->resolveAuthorizedProfileName($resolvedProfile, $content)
            : null;
        if ($authorizedProfile === null) {
            return;
        }

        $snippet = $this->renderDialogHost($event->getRequest(), $authorizedProfile);
        if ($snippet === null) {
            return;
        }

        $updated = substr($content, 0, $bodyPos) . "\n" . $snippet . "\n" . substr($content, $bodyPos);
        $response->setContent($updated);
        $response->headers->remove('Content-Length');
        if ($response->headers->has('ETag')) {
            $response->setEtag(md5($updated), true);
        }
    }

    private function resolveAuthorizedProfileName(string $profileName, string $htmlContent = ''): ?string
    {
        if ($this->configProvider === null) {
            return $profileName;
        }

        try {
            $primaryProfile = $this->configProvider->getProfile($profileName);
            $primaryVolumes = $primaryProfile->volumes !== [] ? $primaryProfile->volumes : [$primaryProfile->name];
            foreach ($primaryVolumes as $volumeName) {
                if ($this->authorizer->isGranted($primaryProfile, FileManagerPermissions::VIEW, '', $volumeName)) {
                    return $profileName;
                }
            }

            foreach ($this->configProvider->getProfileNames() as $otherName) {
                if ($otherName === $profileName) {
                    continue;
                }
                $otherProfile = $this->configProvider->getProfile($otherName);
                $otherVolumes = $otherProfile->volumes !== [] ? $otherProfile->volumes : [$otherProfile->name];
                foreach ($otherVolumes as $volumeName) {
                    if ($this->authorizer->isGranted($otherProfile, FileManagerPermissions::VIEW, '', $volumeName)) {
                        return $otherName;
                    }
                }
            }

            if ($htmlContent !== '' && $this->hasActivePickerMarkup($htmlContent)) {
                return $profileName;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hasActivePickerMarkup(string $htmlContent): bool
    {
        $masked = $this->maskIgnoredHtmlRegions($htmlContent);

        return preg_match('/data-controller\s*=\s*["\'][^"\']*\bliveux--file-manager-bundle--picker\b/i', $masked) === 1;
    }

    private function shouldInject(ResponseEvent $event): bool
    {
        if (!$this->enabled || $this->twig === null || !$event->isMainRequest()) {
            return false;
        }

        $request = $event->getRequest();
        if (
            $request->isXmlHttpRequest()
            || $request->headers->has('Turbo-Frame')
            || ($request->headers->has('HX-Request') && !$request->headers->has('HX-Boosted'))
            || $request->getRequestFormat() !== 'html'
        ) {
            return false;
        }

        $response = $event->getResponse();
        if (
            $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || $response->isRedirection()
            || $response->headers->has('Content-Disposition')
            || $response->headers->has('Content-Encoding')
            || $response->headers->has('X-LiveUX-FM-Dialog')
            || ($this->isSharedCacheableResponse($response) && $this->hasAnyVoterProtectedProfile())
        ) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType === '' || !str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        return true;
    }

    private function isSharedCacheableResponse(Response $response): bool
    {
        if (
            $response->headers->has('Surrogate-Control')
            || $response->headers->has('CDN-Cache-Control')
            || $response->headers->has('Cloudflare-CDN-Cache-Control')
        ) {
            return true;
        }

        if (
            $response->headers->hasCacheControlDirective('private')
            || $response->headers->hasCacheControlDirective('no-store')
        ) {
            return false;
        }

        return $response->headers->hasCacheControlDirective('public')
            || $response->headers->hasCacheControlDirective('s-maxage')
            || $response->isCacheable();
    }

    private function hasAnyVoterProtectedProfile(): bool
    {
        if ($this->configProvider === null) {
            return false;
        }

        foreach ($this->configProvider->getProfileNames() as $name) {
            try {
                $profile = $this->configProvider->getProfile($name);
                if ($profile->isVoterEnabled() || ($profile->permissions['view'] ?? true) !== true) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        return false;
    }

    /**
     * Masks HTML comments, CDATA, raw-text elements (`<script>`, `<style>`, `<textarea>`),
     * and arbitrarily nested `<template>` blocks with equal-length spaces in O(N) time without
     * PCRE backtracking limits, respecting quoted attribute values so `<meta content="<script>">`
     * never corrupts injection offset detection.
     */
    private function findLastTopLevelBodyCloseOffset(string $html): ?int
    {
        $masked = $this->maskIgnoredHtmlRegions($html, true);
        if (preg_match('/<body(?=[\t\n\f\r \/>])/i', $masked) !== 1) {
            return null;
        }

        if (preg_match_all('/<\/body[\t\n\f\r ]*>/i', $masked, $matches, PREG_OFFSET_CAPTURE) === 0 || $matches[0] === []) {
            return null;
        }

        $last = $matches[0][count($matches[0]) - 1];

        return (int) $last[1];
    }

    private const array RAW_TEXT_AND_RCDATA_TAGS = [
        'script' => true,
        'style' => true,
        'textarea' => true,
        'title' => true,
        'iframe' => true,
        'xmp' => true,
        'noembed' => true,
        'noframes' => true,
    ];

    private function maskIgnoredHtmlRegions(string $html, bool $maskAttributeValues = false): string
    {
        $len = strlen($html);
        $out = '';
        $cursor = 0;

        while ($cursor < $len) {
            $lt = strpos($html, '<', $cursor);
            if ($lt === false) {
                $out .= substr($html, $cursor);
                break;
            }

            if ($lt > $cursor) {
                $out .= substr($html, $cursor, $lt - $cursor);
            }

            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $end = strpos($html, '-->', $lt + 4);
                $next = $end === false ? $len : $end + 3;
                $out .= str_repeat(' ', $next - $lt);
                $cursor = $next;
                continue;
            }

            if (substr_compare($html, '<![CDATA[', $lt, 9, true) === 0) {
                $end = strpos($html, ']]>', $lt + 9);
                $next = $end === false ? $len : $end + 3;
                $out .= str_repeat(' ', $next - $lt);
                $cursor = $next;
                continue;
            }

            $tagName = $this->readOpeningTagName($html, $lt + 1, $len);
            if ($tagName === null) {
                $out .= '<';
                $cursor = $lt + 1;
                continue;
            }

            $tagEnd = $this->findOpeningTagClose($html, $lt + 1 + strlen($tagName), $len);
            if ($tagEnd === null) {
                $out .= substr($html, $lt);
                break;
            }

            $lowerTag = strtolower($tagName);
            $isSelfClosing = $tagEnd > $lt + 1 && $html[$tagEnd - 1] === '/';
            if (!$isSelfClosing && isset(self::RAW_TEXT_AND_RCDATA_TAGS[$lowerTag])) {
                $blockEnd = $this->findRawTextElementEnd($html, $tagEnd + 1, $len, $lowerTag);
                $out .= str_repeat(' ', $blockEnd - $lt);
                $cursor = $blockEnd;
                continue;
            }

            if (!$isSelfClosing && $lowerTag === 'template') {
                $blockEnd = $this->findNestedTemplateEnd($html, $tagEnd + 1, $len);
                $out .= str_repeat(' ', $blockEnd - $lt);
                $cursor = $blockEnd;
                continue;
            }

            $rawTag = substr($html, $lt, $tagEnd - $lt + 1);
            $out .= $maskAttributeValues ? $this->maskQuotedAttributeValuesInTag($rawTag) : $rawTag;
            $cursor = $tagEnd + 1;
        }

        return $out;
    }

    private function maskQuotedAttributeValuesInTag(string $rawTag): string
    {
        if (!str_contains($rawTag, '"') && !str_contains($rawTag, "'")) {
            return $rawTag;
        }

        $len = strlen($rawTag);
        $quote = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $rawTag[$i];
            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                } else {
                    $rawTag[$i] = ' ';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            }
        }

        return $rawTag;
    }

    private function readOpeningTagName(string $html, int $start, int $len): ?string
    {
        if ($start >= $len || !ctype_alpha($html[$start])) {
            return null;
        }

        $i = $start + 1;
        while ($i < $len && (ctype_alnum($html[$i]) || $html[$i] === '-' || $html[$i] === ':')) {
            ++$i;
        }

        if ($i < $len && !in_array($html[$i], [' ', "\t", "\n", "\r", "\f", '/', '>'], true)) {
            return null;
        }

        return substr($html, $start, $i - $start);
    }

    private function findOpeningTagClose(string $html, int $start, int $len): ?int
    {
        $quote = '';
        for ($i = $start; $i < $len; ++$i) {
            $ch = $html[$i];
            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '>') {
                return $i;
            }
        }

        return null;
    }

    private function findRawTextElementEnd(string $html, int $start, int $len, string $lowerTag): int
    {
        $needle = '</' . $lowerTag;
        $needleLen = strlen($needle);
        $pos = $start;

        while ($pos < $len) {
            $hit = stripos($html, $needle, $pos);
            if ($hit === false) {
                return $len;
            }
            $after = $hit + $needleLen;
            while ($after < $len && in_array($html[$after], [' ', "\t", "\n", "\r", "\f"], true)) {
                ++$after;
            }
            if ($after < $len && $html[$after] === '>') {
                return $after + 1;
            }
            $pos = $hit + 2;
        }

        return $len;
    }

    private function findNestedTemplateEnd(string $html, int $start, int $len): int
    {
        $depth = 1;
        $pos = $start;

        while ($pos < $len && $depth > 0) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                return $len;
            }

            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $end = strpos($html, '-->', $lt + 4);
                $pos = $end === false ? $len : $end + 3;
                continue;
            }

            if (substr_compare($html, '</template', $lt, 10, true) === 0) {
                $after = $lt + 10;
                while ($after < $len && in_array($html[$after], [' ', "\t", "\n", "\r", "\f"], true)) {
                    ++$after;
                }
                if ($after < $len && $html[$after] === '>') {
                    --$depth;
                    $pos = $after + 1;
                    continue;
                }
            }

            $tagName = $this->readOpeningTagName($html, $lt + 1, $len);
            if ($tagName !== null) {
                $tagEnd = $this->findOpeningTagClose($html, $lt + 1 + strlen($tagName), $len);
                if ($tagEnd === null) {
                    return $len;
                }
                $lowerTag = strtolower($tagName);
                $isSelfClosing = $tagEnd > $lt + 1 && $html[$tagEnd - 1] === '/';
                if (!$isSelfClosing && $lowerTag === 'template') {
                    ++$depth;
                    $pos = $tagEnd + 1;
                    continue;
                }
                if (!$isSelfClosing && ($lowerTag === 'script' || $lowerTag === 'style' || $lowerTag === 'textarea')) {
                    $pos = $this->findRawTextElementEnd($html, $tagEnd + 1, $len, $lowerTag);
                    continue;
                }
                $pos = $tagEnd + 1;
                continue;
            }

            $pos = $lt + 1;
        }

        return $pos;
    }

    private function resolveProfileName(): ?string
    {
        if ($this->configProvider === null) {
            return $this->defaultProfile;
        }

        if ($this->configProvider->hasProfile($this->defaultProfile)) {
            return $this->defaultProfile;
        }

        $names = $this->configProvider->getProfileNames();

        return $names[0] ?? null;
    }

    private function renderDialogHost(Request $request, string $profileName): ?string
    {
        if ($this->twig === null) {
            return null;
        }

        $locale = $request->getLocale();
        $zIndex = $this->defaultZIndex;
        if ($this->configProvider !== null && $this->configProvider->hasProfile($profileName)) {
            try {
                $profile = $this->configProvider->getProfile($profileName);
                if (is_string($profile->locale) && trim($profile->locale) !== '') {
                    $locale = trim($profile->locale);
                }
                if (is_int($profile->ui['z_index'] ?? null)) {
                    $zIndex = $profile->ui['z_index'];
                }
            } catch (Throwable) {
                // Keep defaults on profile hydration error.
            }
        }

        try {
            return $this->twig->render('@LiveUXFileManager/components/DialogHost.html.twig', [
                'profile' => $profileName,
                'locale' => $locale,
                'zIndex' => $zIndex,
            ]);
        } catch (Throwable) {
            return null;
        }
    }
}
