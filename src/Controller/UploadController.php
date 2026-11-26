<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Controller;

use League\Flysystem\FilesystemException;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class UploadController
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private UploadManager $uploadManager,
        private CsrfRequestValidator $csrfValidator,
        private MessageTranslator $messageTranslator = new MessageTranslator(),
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $post = $request->request->all();
        $files = $request->files->all();
        $file = $files['file'] ?? null;
        $profile = null;

        // Detect PHP post_max_size overflow BEFORE profile/CSRF checks because PHP empties $_POST & $_FILES on overflow.
        if (!$file instanceof UploadedFile && $this->isPostMaxSizeExceeded($request)) {
            return $this->buildPostMaxSizeResponse($request);
        }

        try {
            $profileName = $this->scalarPostString($post, 'profile', 'default');
            $volumeName = $post['volume'] ?? null;
            if (is_array($volumeName) || is_array($post['uploadId'] ?? null) || is_array($post['filename'] ?? null)) {
                return $this->privateJsonResponse([
                    'ok' => false,
                    'error' => $this->messageTranslator->trans('errors.invalid_json', [], $this->messageTranslator->resolveLocale($request), 'Invalid request parameter.'),
                ], Response::HTTP_BAD_REQUEST);
            }
            $directory = $this->scalarPostString($post, 'path', '');
            $uploadId = $post['uploadId'] ?? null;
            $chunkIndex = (int) $this->scalarPostString($post, 'chunkIndex', '0');
            $totalChunks = max(1, (int) $this->scalarPostString($post, 'totalChunks', '1'));
            $originalName = $post['filename'] ?? null;

            try {
                $profile = $this->configProvider->getProfile($profileName);
            } catch (FileManagerException $e) {
                $this->csrfValidator->assertValid($request, null);

                throw $e;
            }
            $locale = $this->messageTranslator->resolveLocale($request, $profile);
            $this->csrfValidator->assertValid($request, $profile);

            if (!$file instanceof UploadedFile) {
                return $this->privateJsonResponse([
                    'ok' => false,
                    'error' => $this->messageTranslator->trans('errors.no_file_uploaded', [], $locale, 'No file uploaded.'),
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$file->isValid()) {
                return $this->buildPhpUploadErrorResponse($file, $locale);
            }

            $result = $this->uploadManager->handleUpload(
                profile: $profile,
                uploadedFile: $file,
                targetDirectory: $directory,
                volumeName: is_string($volumeName) && $volumeName !== '' ? $volumeName : null,
                uploadId: is_string($uploadId) ? $uploadId : null,
                chunkIndex: $chunkIndex,
                totalChunks: $totalChunks,
                originalFilename: is_string($originalName) && $originalName !== '' ? $originalName : null,
            );

            return $this->privateJsonResponse($this->buildSuccessPayload($result, $locale));
        } catch (AccessDeniedException $e) {
            return $this->buildExceptionResponse($e, Response::HTTP_FORBIDDEN, $request, $profile);
        } catch (FileManagerException $e) {
            return $this->buildExceptionResponse($e, Response::HTTP_UNPROCESSABLE_ENTITY, $request, $profile);
        } catch (FileException $e) {
            return $this->buildUploadFailureResponse($e, $request, $profile);
        } catch (FilesystemException $e) {
            return $this->buildUploadFailureResponse($e, $request, $profile);
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function buildSuccessPayload(array $result, string $locale): array
    {
        $payload = ['ok' => true, ...$result];
        if (is_string($result['duplicateOf'] ?? null) && $result['duplicateOf'] !== '') {
            $existing = '/' . ltrim($result['duplicateOf'], '/');
            $payload['warning'] = ($result['reused'] ?? false) === true
                ? $this->messageTranslator->trans('messages.duplicate_reused', ['%existing%' => $existing], $locale, 'Duplicate detected — reused existing file: %existing%')
                : $this->messageTranslator->trans('messages.duplicate_warning', ['%existing%' => $existing], $locale, 'Duplicate file notice: identical content already exists at %existing%');
        }

        return $payload;
    }

    private function buildPostMaxSizeResponse(Request $request): JsonResponse
    {
        $locale = $this->messageTranslator->resolveLocale($request);
        $postMax = (string) ini_get('post_max_size');

        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->trans(
                'errors.php_post_max_size',
                ['%limit%' => $postMax !== '' ? $postMax : 'post_max_size'],
                $locale,
                'Upload request exceeds PHP post_max_size (%limit%).',
            ),
        ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    private function buildExceptionResponse(
        FileManagerException $exception,
        int $statusCode,
        Request $request,
        ?ProfileConfig $profile,
    ): JsonResponse {
        $locale = $this->messageTranslator->resolveLocale($request, $profile);

        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->translateException($exception, $locale),
        ], $statusCode);
    }

    private function buildUploadFailureResponse(
        Throwable $exception,
        Request $request,
        ?ProfileConfig $profile,
    ): JsonResponse {
        $locale = $this->messageTranslator->resolveLocale($request, $profile);

        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->trans('errors.upload_failed', [], $locale, 'Upload failed.'),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
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

    /**
     * @param array<string, mixed> $post
     */
    private function scalarPostString(array $post, string $key, string $default = ''): string
    {
        $val = $post[$key] ?? null;
        if (is_string($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }
        if (is_array($val)) {
            throw new FileManagerException(sprintf('Parameter "%s" must be a string.', $key), 'errors.invalid_json');
        }

        return $default;
    }

    private function buildPhpUploadErrorResponse(UploadedFile $file, string $locale): JsonResponse
    {
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            $iniMax = (string) ini_get('upload_max_filesize');

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans(
                    'errors.php_upload_max_filesize',
                    ['%limit%' => $iniMax !== '' ? $iniMax : 'upload_max_filesize'],
                    $locale,
                    'Upload chunk exceeds PHP upload_max_filesize (%limit%).',
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Never echo PHP's raw upload error (untranslated, embeds the client-supplied filename).
        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->trans('errors.upload_failed', [], $locale, 'Upload failed.'),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function isPostMaxSizeExceeded(Request $request): bool
    {
        $contentLength = (int) $request->headers->get('Content-Length', '0');
        if ($contentLength <= 0 || $request->request->count() > 0 || $request->files->count() > 0) {
            return false;
        }

        $postMaxBytes = $this->parseIniBytes((string) ini_get('post_max_size'));

        return $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    private function parseIniBytes(string $raw): int
    {
        return ByteSizeParser::parse($raw);
    }
}
