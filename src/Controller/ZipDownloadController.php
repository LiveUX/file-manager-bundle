<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Controller;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

final readonly class ZipDownloadController
{
    private const int MAX_SELECTED_PATHS = 500;
    private const int MAX_JSON_BODY_BYTES = 1_048_576;

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private ConfigProviderInterface $configProvider,
        private ZipArchiveService $zipArchiveService,
        private CsrfRequestValidator $csrfValidator,
        ?AuthorizationCheckerInterface $authChecker = null,
        private MessageTranslator $messageTranslator = new MessageTranslator(),
        private PathNormalizer $pathNormalizer = new PathNormalizer(),
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private ?VolumeRegistry $volumeRegistry = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_JSON_BODY_BYTES) {
            $locale = $this->messageTranslator->resolveLocale($request);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.invalid_json', [], $locale, 'Invalid JSON payload.'),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $locale = $this->messageTranslator->resolveLocale($request);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.invalid_json', [], $locale, 'Invalid JSON payload.'),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (
            (array_key_exists('profile', $payload) && $payload['profile'] !== null && !is_string($payload['profile']))
            || (array_key_exists('volume', $payload) && $payload['volume'] !== null && !is_string($payload['volume']))
        ) {
            $locale = $this->messageTranslator->resolveLocale($request);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.invalid_json', [], $locale, 'Invalid JSON payload.'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $profileName = is_string($payload['profile'] ?? null) ? $payload['profile'] : 'default';
        $volumeName = is_string($payload['volume'] ?? null) && $payload['volume'] !== '' ? $payload['volume'] : null;
        $paths = is_array($payload['paths'] ?? null) ? array_values(array_filter($payload['paths'], 'is_string')) : [];

        try {
            $profile = $this->configProvider->getProfile($profileName);
        } catch (FileManagerException $e) {
            $locale = $this->messageTranslator->resolveLocale($request, null, $payload);
            try {
                $this->csrfValidator->assertValid($request, null);
            } catch (AccessDeniedException $csrfError) {
                return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($csrfError, $locale)], Response::HTTP_FORBIDDEN);
            }

            return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($e, $locale)], Response::HTTP_BAD_REQUEST);
        }

        $locale = $this->messageTranslator->resolveLocale($request, $profile, $payload);

        try {
            $this->csrfValidator->assertValid($request, $profile);
        } catch (AccessDeniedException $e) {
            return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($e, $locale)], Response::HTTP_FORBIDDEN);
        }

        if ($paths === []) {
            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.zip_no_selection', [], $locale, 'No files selected.'),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (count($paths) > self::MAX_SELECTED_PATHS) {
            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans(
                    'errors.zip_max_files',
                    ['%max%' => self::MAX_SELECTED_PATHS],
                    $locale,
                    sprintf('Maximum %d items can be zipped at once.', self::MAX_SELECTED_PATHS),
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->assertAuthorizedForPaths($profile, $paths, $volumeName);
        } catch (AccessDeniedException $e) {
            return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($e, $locale)], Response::HTTP_FORBIDDEN);
        } catch (FileManagerException $e) {
            return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($e, $locale)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $zipPath = $this->zipArchiveService->createTempZip($profile, $paths, $volumeName);
        } catch (AccessDeniedException $e) {
            return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($e, $locale)], Response::HTTP_FORBIDDEN);
        } catch (FileManagerException $e) {
            return $this->privateJsonResponse(['ok' => false, 'error' => $this->messageTranslator->translateException($e, $locale)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable) {
            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.zip_failed', [], $locale, 'ZIP download failed.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'files-' . date('Ymd-His') . '.zip');
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /**
     * @param list<string> $paths
     */
    private function assertAuthorizedForPaths(ProfileConfig $profile, array $paths, ?string $volumeName): void
    {
        $resolvedVol = $this->volumeRegistry !== null
            ? $this->volumeRegistry->getVolumeForProfile($profile, $volumeName)->name
            : $volumeName;

        foreach ($paths as $rawPath) {
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $resolvedVol);
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DOWNLOAD_ZIP, $cleanPath, $resolvedVol);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJsonResponse(array $data, int $statusCode = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $statusCode);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
