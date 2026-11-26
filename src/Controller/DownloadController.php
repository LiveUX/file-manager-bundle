<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Controller;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

final readonly class DownloadController
{
    private const array ACTIVE_MIME_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'image/svg+xml',
        'text/xml',
        'application/xml',
    ];

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private MessageTranslator $messageTranslator = new MessageTranslator(),
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
            || is_array($q['inline'] ?? null)
        ) {
            throw new BadRequestHttpException('Invalid query parameter.');
        }
        $profileName = is_string($q['profile'] ?? null) ? $q['profile'] : 'default';
        $volumeName = $q['volume'] ?? null;
        $rawPath = is_string($q['path'] ?? null) ? $q['path'] : '';
        $inline = filter_var($q['inline'] ?? false, FILTER_VALIDATE_BOOL);
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

        // Always require VIEW (including ancestors) first; attachment/non-previewable files will additionally require DOWNLOAD below.
        if (!$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name)) {
            throw new AccessDeniedHttpException($this->messageTranslator->trans('errors.access_denied', [], $locale, 'Access denied.'));
        }
        if (!$inline && !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD, $cleanPath, $volume->name)) {
            throw new AccessDeniedHttpException($this->messageTranslator->trans('errors.access_denied', [], $locale, 'Access denied.'));
        }

        try {
            $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        } catch (FileManagerException $e) {
            throw new BadRequestHttpException($this->messageTranslator->translateException($e, $locale), $e);
        }

        try {
            if ($cleanPath === '' || !$filesystem->fileExists($cleanPath)) {
                throw new NotFoundHttpException($this->messageTranslator->trans('errors.file_not_found', [], $locale, 'File not found.'));
            }

            try {
                $mimeType = $filesystem->mimeType($cleanPath);
            } catch (Throwable) {
                $mimeType = 'application/octet-stream';
            }
            $filename = PathNormalizer::basenameUtf8($cleanPath);
            $fileSize = $filesystem->fileSize($cleanPath);
        } catch (FilesystemException $e) {
            throw new ServiceUnavailableHttpException(null, $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Storage unavailable.'), $e);
        }

        $effectiveMime = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        if (preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i', trim(explode(';', $effectiveMime, 2)[0])) !== 1) {
            $effectiveMime = 'application/octet-stream';
        }
        $lowerMime = trim(explode(';', strtolower($effectiveMime), 2)[0]);
        $ext = PathNormalizer::extensionUtf8($filename);
        $forceAttachment = $this->isActiveDocument($lowerMime, $ext);
        $previewDisabledByConfig = ($lowerMime === 'application/pdf' && ($profile->ui['allow_pdf_preview'] ?? true) === false)
            || (str_starts_with($lowerMime, 'video/') && ($profile->ui['allow_video_preview'] ?? true) === false);
        $isPreviewableInline = !$forceAttachment && $this->isInlinePreviewableMime($lowerMime);
        $disposition = ($inline && !$forceAttachment && !$previewDisabledByConfig && $isPreviewableInline)
            ? HeaderUtils::DISPOSITION_INLINE
            : HeaderUtils::DISPOSITION_ATTACHMENT;

        if ($disposition === HeaderUtils::DISPOSITION_ATTACHMENT
            && !$this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD, $cleanPath, $volume->name)
        ) {
            throw new AccessDeniedHttpException($this->messageTranslator->trans('errors.access_denied', [], $locale, 'Access denied.'));
        }

        $isLocal = $volume->isLocalFilesystem();
        $response = $this->buildStreamResponse($request, $filesystem, $cleanPath, $fileSize, $isLocal);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $isPrivateContext = $volume->private
            || $profile->isVoterEnabled()
            || ShareLinkService::volumeUsesUserIdToken($rawVolume)
            || ($this->security?->getUser() !== null)
            || $request->headers->has('Authorization')
            || $request->hasPreviousSession();
        if ($isPrivateContext) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Vary', 'Cookie, Authorization');
        }

        if ($response->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'");

            return $response;
        }

        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'download';
        $asciiFallback = str_replace(['/', '\\', '"', '%'], '_', $asciiFallback);
        $safeContentType = $lowerMime === 'text/plain' ? 'text/plain; charset=UTF-8' : $lowerMime;
        $response->headers->set('Content-Type', $safeContentType);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($disposition, $filename, $asciiFallback));
        $response->headers->set('Accept-Ranges', $isLocal ? 'bytes' : 'none');

        $isInlinePdf = $disposition === HeaderUtils::DISPOSITION_INLINE && $lowerMime === 'application/pdf';
        $response->headers->set(
            'Content-Security-Policy',
            $isInlinePdf
                ? "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; object-src 'self'; frame-ancestors 'self'"
                : "default-src 'none'; sandbox",
        );

        return $response;
    }

    private function isActiveDocument(string $lowerMime, string $ext): bool
    {
        return in_array($lowerMime, self::ACTIVE_MIME_TYPES, true)
            || $ext === 'svg'
            || $ext === 'svgz'
            || str_contains($lowerMime, 'svg')
            || str_contains($lowerMime, 'xml')
            || str_contains($lowerMime, 'html')
            || str_contains($lowerMime, 'script');
    }

    private function isInlinePreviewableMime(string $lowerMime): bool
    {
        if (in_array($lowerMime, self::ACTIVE_MIME_TYPES, true)) {
            return false;
        }

        return str_starts_with($lowerMime, 'image/')
            || str_starts_with($lowerMime, 'video/')
            || str_starts_with($lowerMime, 'audio/')
            || $lowerMime === 'application/pdf'
            || $lowerMime === 'text/plain';
    }

    private function buildStreamResponse(
        Request $request,
        FilesystemOperator $filesystem,
        string $cleanPath,
        int $fileSize,
        bool $allowRange = true,
    ): Response {
        $rangeHeader = $allowRange ? $request->headers->get('Range') : null;
        if ($fileSize > 0 && is_string($rangeHeader) && $rangeHeader !== '') {
            $parsedRange = \LiveUX\FileManagerBundle\Util\HttpByteRangeParser::parseSingleByteRange($rangeHeader, $fileSize);
            if ($parsedRange['status'] === 'invalid') {
                $invalid = new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE);
                $invalid->headers->set('Content-Range', sprintf('bytes */%d', $fileSize));
                $invalid->headers->set('Accept-Ranges', 'bytes');

                return $invalid;
            }
            if ($parsedRange['status'] === 'ok') {
                $start = $parsedRange['start'];
                $end = $parsedRange['end'];
                $length = ($end - $start) + 1;
                $response = new StreamedResponse(static function () use ($filesystem, $cleanPath, $start, $length): void {
                    $stream = $filesystem->readStream($cleanPath);
                    if (!is_resource($stream)) {
                        return;
                    }
                    try {
                        \LiveUX\FileManagerBundle\Util\HttpByteRangeParser::echoStreamRange($stream, $start, $length);
                    } finally {
                        fclose($stream);
                    }
                }, Response::HTTP_PARTIAL_CONTENT);

                $response->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $fileSize));
                $response->headers->set('Content-Length', (string) $length);

                return $response;
            }
        }

        $response = new StreamedResponse(static function () use ($filesystem, $cleanPath): void {
            $stream = $filesystem->readStream($cleanPath);
            if (!is_resource($stream)) {
                return;
            }
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        });
        if ($fileSize >= 0) {
            $response->headers->set('Content-Length', (string) $fileSize);
        }

        return $response;
    }
}
