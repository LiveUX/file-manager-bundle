<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Controller;

use League\Flysystem\FilesystemException;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class ThumbnailController
{
    private const int MAX_THUMBNAIL_SOURCE_BYTES = 26_214_400; // 25 MB memory DoS guard
    private const array SAFE_FALLBACK_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon',
    ];

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private ThumbnailManager $thumbnailManager,
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private MessageTranslator $messageTranslator = new MessageTranslator(),
        private SvgSanitizer $svgSanitizer = new SvgSanitizer(),
        private ?Security $security = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function __invoke(Request $request): Response
    {
        $q = $request->query->all();
        if (
            is_array($q['profile'] ?? null)
            || is_array($q['volume'] ?? null)
            || is_array($q['path'] ?? null)
        ) {
            throw new BadRequestHttpException('Invalid query parameter.');
        }
        $profileName = is_string($q['profile'] ?? null) ? $q['profile'] : 'default';
        $volumeName = $q['volume'] ?? null;
        $rawPath = is_string($q['path'] ?? null) ? $q['path'] : '';
        $profile = null;

        try {
            $profile = $this->configProvider->getProfile($profileName);
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            if ($cleanPath !== '') {
                $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));
            }
        } catch (FileManagerException $e) {
            $locale = $this->messageTranslator->resolveLocale($request, $profile);

            throw new BadRequestHttpException($this->messageTranslator->translateException($e, $locale), $e);
        }

        $locale = $this->messageTranslator->resolveLocale($request, $profile);

        try {
            $volStr = is_string($volumeName) && $volumeName !== '' ? $volumeName : null;
            $rawVolume = $this->volumeRegistry->getRawVolumeForProfile($profile, $volStr);
            $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volStr);
        } catch (FileManagerException $e) {
            throw new BadRequestHttpException($this->messageTranslator->translateException($e, $locale), $e);
        }

        if (!$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name)) {
            throw new AccessDeniedHttpException($this->messageTranslator->trans('errors.access_denied', [], $locale, 'Access denied.'));
        }

        $ext = PathNormalizer::extensionUtf8($cleanPath);
        if ($cleanPath === '' || !in_array($ext, FileSecurityGuard::SAFE_THUMBNAIL_EXTENSIONS, true)) {
            throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
        }

        try {
            $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        } catch (FileManagerException $e) {
            throw new BadRequestHttpException($this->messageTranslator->translateException($e, $locale), $e);
        }

        $isPrivateContext = $volume->private
            || $profile->isVoterEnabled()
            || ShareLinkService::volumeUsesUserIdToken($rawVolume)
            || ($this->security?->getUser() !== null)
            || $request->headers->has('Authorization')
            || $request->hasPreviousSession();

        if ($ext === 'svg') {
            try {
                if (!$filesystem->fileExists($cleanPath)) {
                    throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
                }

                return $this->serveSanitizedSvgResponse($request, $filesystem, $profile, $cleanPath, $locale, $isPrivateContext);
            } catch (FilesystemException $e) {
                throw new ServiceUnavailableHttpException(null, $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Storage unavailable.'), $e);
            }
        }

        $canonicalPath = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $cachedFile = $this->thumbnailManager->getCachedPath($profile, $canonicalPath);

        $isStale = false;
        $skipRemoteStat = false;
        $cacheEnabled = ($profile->cache['enabled'] ?? true) === true;
        $effectiveTtl = $cacheEnabled
            ? ($volume->cacheTtl ?? (int) ($profile->cache['ttl'] ?? 0))
            : 0;
        if ($cachedFile !== '' && is_file($cachedFile)) {
            $cachedMtime = @filemtime($cachedFile);
            if ($volume->isNetwork() && $effectiveTtl > 0 && is_int($cachedMtime) && (time() - $cachedMtime) < $effectiveTtl) {
                // Skip remote HeadObject round-trips on fresh cloud thumbnail cache hits; ThumbnailCleanupListener invalidates on mutations.
                $skipRemoteStat = true;
            }
        }

        if (!$skipRemoteStat) {
            try {
                $exists = $filesystem->fileExists($cleanPath);
            } catch (FilesystemException $e) {
                throw new ServiceUnavailableHttpException(null, $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Storage unavailable.'), $e);
            }
            if (!$exists) {
                throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
            }

            if ($cachedFile !== '' && is_file($cachedFile)) {
                try {
                    $sourceMtime = $filesystem->lastModified($cleanPath);
                    $cachedMtime = @filemtime($cachedFile);
                    if (is_int($cachedMtime) && $sourceMtime > $cachedMtime) {
                        $isStale = true;
                    }
                } catch (\Throwable) {
                    // Keep cached thumbnail when source mtime is unavailable.
                }
            }
        }

        if ($cachedFile === '' || !is_file($cachedFile) || $isStale) {
            try {
                $driver = $this->thumbnailManager->resolveDriver($profile);
            } catch (FileManagerException $e) {
                return $this->serviceUnavailableTextResponse($e, $locale);
            }
            if ($driver === null) {
                $strategy = (string) ($profile->thumbnail['on_missing_driver'] ?? 'fallback_original');
                if ($strategy === 'icon' || !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD, $cleanPath, $volume->name)) {
                    throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
                }
            }

            try {
                $sourceSize = $filesystem->fileSize($cleanPath);
            } catch (\Throwable) {
                $sourceSize = -1;
            }
            if ($sourceSize > self::MAX_THUMBNAIL_SOURCE_BYTES) {
                return $this->emptyStatusResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            try {
                $binary = $this->readBoundedBinary($filesystem, $cleanPath);
            } catch (FilesystemException|FileManagerException $e) {
                throw new ServiceUnavailableHttpException(null, $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Storage unavailable.'), $e);
            }
            if ($binary === null) {
                return $this->emptyStatusResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
            if ($binary === '') {
                throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
            }

            try {
                $generated = $isStale
                    ? $this->thumbnailManager->regenerate($profile, $canonicalPath, $binary)
                    : $this->thumbnailManager->generateIfMissing($profile, $canonicalPath, $binary);
            } catch (FileManagerException $e) {
                return $this->serviceUnavailableTextResponse($e, $locale);
            }

            if ($generated === null) {
                return $this->serveValidatedOriginalFallback($request, $filesystem, $profile, $volume->name, $cleanPath, $binary, $locale, $isPrivateContext);
            }
            $cachedFile = $generated;
        }

        $etag = md5($cachedFile . ':' . (string) @filemtime($cachedFile) . ':' . (string) @filesize($cachedFile));
        $response = new BinaryFileResponse($cachedFile);
        $this->applyBrowserCacheHeaders($response, $etag, $isPrivateContext, $cacheEnabled, $this->hasCacheBustingVersion($request));
        $response->isNotModified($request);

        return $response;
    }

    private function serveSanitizedSvgResponse(
        Request $request,
        \League\Flysystem\FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $cleanPath,
        string $locale,
        bool $isPrivateContext,
    ): Response {
        $sanitizeSvg = ($profile->security['sanitize_svg'] ?? true) === true;
        $maxSvgBytes = $sanitizeSvg ? SvgSanitizer::MAX_SVG_BYTES : self::MAX_THUMBNAIL_SOURCE_BYTES;

        try {
            $sourceSize = $filesystem->fileSize($cleanPath);
        } catch (\Throwable) {
            $sourceSize = -1;
        }
        if ($sourceSize > $maxSvgBytes) {
            return $this->emptyStatusResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $binary = $this->readBoundedBinary($filesystem, $cleanPath, $maxSvgBytes);
        } catch (FileManagerException $e) {
            throw new ServiceUnavailableHttpException(null, $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Storage unavailable.'), $e);
        }
        if ($binary === null) {
            return $this->emptyStatusResponse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if ($binary === '') {
            throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
        }

        if ($sanitizeSvg) {
            $cleanSvg = $this->svgSanitizer->sanitize($binary);
        } else {
            $cleanSvg = (str_contains($binary, "\0") || !mb_check_encoding($binary, 'UTF-8') || preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $binary) === 1)
                ? ''
                : $binary;
        }

        if ($cleanSvg === '') {
            throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
        }

        $response = new Response($cleanSvg);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $this->applyBrowserCacheHeaders($response, md5($cleanSvg), $isPrivateContext, ($profile->cache['enabled'] ?? true) === true, $this->hasCacheBustingVersion($request));
        $response->isNotModified($request);

        return $response;
    }

    private function emptyStatusResponse(int $statusCode): Response
    {
        $response = new Response('', $statusCode);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function serveValidatedOriginalFallback(
        Request $request,
        \League\Flysystem\FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $volumeName,
        string $cleanPath,
        string $binary,
        string $locale,
        bool $isPrivateContext,
    ): Response {
        $strategy = (string) ($profile->thumbnail['on_missing_driver'] ?? 'fallback_original');
        if ($strategy === 'icon') {
            throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
        }

        // Streaming the unscaled original image requires DOWNLOAD permission so VIEW-only users cannot exfiltrate full originals.
        if (!$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD, $cleanPath, $volumeName)) {
            throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
        }

        $detectedMime = '';
        $info = @getimagesizefromstring($binary);
        if (is_array($info)) {
            $detectedMime = strtolower(trim($info['mime']));
        } else {
            try {
                $rawMime = $filesystem->mimeType($cleanPath);
                $detectedMime = trim(explode(';', strtolower($rawMime), 2)[0]);
            } catch (\Throwable) {
                $detectedMime = '';
            }
        }

        if (!in_array($detectedMime, self::SAFE_FALLBACK_MIMES, true)) {
            throw new NotFoundHttpException($this->messageTranslator->trans('errors.image_not_found', [], $locale, 'Image not found.'));
        }

        $response = new Response($binary);
        $response->headers->set('Content-Type', $detectedMime);
        $this->applyBrowserCacheHeaders($response, md5($binary), $isPrivateContext, ($profile->cache['enabled'] ?? true) === true, $this->hasCacheBustingVersion($request));
        $response->isNotModified($request);

        return $response;
    }

    private function readBoundedBinary(
        \League\Flysystem\FilesystemOperator $filesystem,
        string $cleanPath,
        int $maxBytes = self::MAX_THUMBNAIL_SOURCE_BYTES,
    ): ?string {
        $stream = $filesystem->readStream($cleanPath);
        if (!is_resource($stream)) {
            return '';
        }

        try {
            $buffer = '';
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new FileManagerException('Failed to read image stream.', 'errors.action_failed');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }
                    throw new FileManagerException('Unexpected empty read from image stream.', 'errors.action_failed');
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $maxBytes) {
                    return null;
                }
            }

            return $buffer;
        } finally {
            fclose($stream);
        }
    }

    private function serviceUnavailableTextResponse(FileManagerException $e, string $locale): Response
    {
        $errResp = new Response($this->messageTranslator->translateException($e, $locale), Response::HTTP_SERVICE_UNAVAILABLE);
        $errResp->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $errResp->headers->set('X-Content-Type-Options', 'nosniff');
        $errResp->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $errResp;
    }

    private function hasCacheBustingVersion(Request $request): bool
    {
        $rawV = $request->query->all()['v'] ?? null;

        return is_string($rawV) && trim($rawV) !== '';
    }

    private function applyBrowserCacheHeaders(
        Response $response,
        string $etag,
        bool $isPrivate,
        bool $cacheEnabled = true,
        bool $isVersioned = false,
    ): void {
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($isPrivate) {
            $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
            $response->headers->set('Vary', 'Cookie, Authorization');
        } elseif (!$cacheEnabled) {
            $response->headers->set('Cache-Control', 'public, no-cache, must-revalidate');
        } elseif ($isVersioned) {
            $response->setPublic();
            $response->setMaxAge(2592000);
            $response->setSharedMaxAge(2592000);
            $response->setImmutable(true);
        } else {
            $response->headers->set('Cache-Control', 'public, max-age=3600, must-revalidate');
        }
        $response->setEtag($etag);
    }
}
