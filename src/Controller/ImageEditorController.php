<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Controller;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Persists images edited in the inline Canvas Image Editor (crop / rotate / flip)
 * either as an overwrite of an existing raster image or as a new "-edited" copy.
 */
final readonly class ImageEditorController
{
    private const array ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const array ALLOWED_IMAGETYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
    private const int MAX_DIMENSION = 8192;
    private const int MAX_PIXELS = 40_000_000;

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private ThumbnailManager $thumbnailManager,
        private CsrfRequestValidator $csrfValidator,
        private EventDispatcherInterface $eventDispatcher,
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private MessageTranslator $messageTranslator = new MessageTranslator(),
        private ?StorageQuotaChecker $quotaChecker = null,
        private ?AssetUsageGuardService $usageGuard = null,
        private ImageOptimizer $imageOptimizer = new ImageOptimizer(),
        private FilenameNormalizer $filenameNormalizer = new FilenameNormalizer(),
        private ?DuplicateDetector $duplicateDetector = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $post = $request->request->all();
        $files = $request->files->all();
        $file = $files['file'] ?? null;
        $profile = null;

        // Detect PHP post_max_size overflow BEFORE profile/CSRF checks because PHP empties $_POST & $_FILES on overflow.
        if (!$file instanceof UploadedFile && $this->isPostMaxSizeExceeded($request)) {
            $postMax = (string) ini_get('post_max_size');

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans(
                    'errors.php_post_max_size',
                    ['%limit%' => $postMax !== '' ? $postMax : 'post_max_size'],
                    $this->messageTranslator->resolveLocale($request),
                    'Upload request exceeds PHP post_max_size (%limit%).',
                ),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            if (
                is_array($post['profile'] ?? null)
                || is_array($post['volume'] ?? null)
                || is_array($post['path'] ?? null)
                || is_array($post['saveMode'] ?? null)
            ) {
                return $this->privateJsonResponse([
                    'ok' => false,
                    'error' => $this->messageTranslator->trans('errors.invalid_json', [], $this->messageTranslator->resolveLocale($request), 'Invalid request parameter.'),
                ], Response::HTTP_BAD_REQUEST);
            }
            $profileName = is_string($post['profile'] ?? null) ? $post['profile'] : 'default';
            $volumeName = $post['volume'] ?? null;
            $sourcePath = is_string($post['path'] ?? null) ? $post['path'] : '';
            $saveMode = is_string($post['saveMode'] ?? null) ? $post['saveMode'] : 'copy';

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
                    'error' => $this->messageTranslator->trans('errors.no_edited_image', [], $locale, 'No edited image uploaded.'),
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$file->isValid()) {
                return $this->buildPhpUploadErrorResponse($file, $locale);
            }

            if (!in_array($saveMode, ['copy', 'overwrite'], true)) {
                throw new FileManagerException('Invalid image save mode.', 'errors.action_failed');
            }

            $volStr = is_string($volumeName) && $volumeName !== '' ? $volumeName : null;
            $cleanPath = $this->pathNormalizer->normalize($sourcePath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            if ($cleanPath === '') {
                throw new FileManagerException('Original image does not exist.', 'errors.overwrite_missing');
            }
            $sourceExt = PathNormalizer::extensionUtf8($cleanPath);
            if (!in_array($sourceExt, FileSecurityGuard::SAFE_RASTER_EXTENSIONS, true)) {
                throw new FileManagerException('Image editor only supports raster image files.', 'errors.overwrite_raster_only');
            }
            $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volStr);
            $this->assertAuthorized($profile, $cleanPath, $volume->name, $saveMode);

            $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
            if (!$filesystem->fileExists($cleanPath)) {
                throw new FileManagerException('Original image does not exist.', 'errors.overwrite_missing');
            }

            $mime = $this->assertValidUpload($file, $profile);
            [$binary, $mime] = $this->readValidateAndSanitizeRasterBinary($file, $profile, $cleanPath, $mime, $saveMode);

            if ($saveMode === 'overwrite') {
                if ($profile->overwritePolicy === 'reject') {
                    throw new FileManagerException(
                        'Overwriting existing files is disabled for this profile.',
                        'errors.upload_file_exists',
                        ['%name%' => PathNormalizer::basenameUtf8($cleanPath)],
                    );
                }
                $this->usageGuard?->assertAllowed($profile, $volume, $cleanPath, false, 'overwrite');
            } elseif ($this->duplicateDetector !== null && $this->duplicateDetector->isEnabled($profile) && $this->duplicateDetector->getPolicy($profile) === 'reject') {
                $this->assertNotDuplicatePayload($profile, $volume->name, $binary);
            }

            $targetPath = $this->resolveTargetPath($filesystem, $cleanPath, $saveMode, $mime, $profile);
            $this->fileSecurityGuard->assertAccessiblePath($targetPath, $profile);
            if ($saveMode === 'copy') {
                $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $targetPath, $volume->name);
            }
            $commitWrite = function () use ($filesystem, $targetPath, $saveMode, $binary, $profile, $volume): void {
                $existingSize = ($saveMode === 'overwrite' && $filesystem->fileExists($targetPath)) ? $filesystem->fileSize($targetPath) : 0;
                $deltaBytes = max(0, strlen($binary) - $existingSize);
                if ($deltaBytes > 0) {
                    $this->quotaChecker?->assertHasQuota($profile, $volume->name, $deltaBytes);
                }

                $filesystem->write($targetPath, $binary);
                $this->quotaChecker?->invalidateQuotaCache($profile, $volume->name);
            };

            if ($this->quotaChecker !== null) {
                $this->quotaChecker->withQuotaLock($profile, $volume->name, $commitWrite);
            } else {
                $commitWrite();
            }
            $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $targetPath, $profile->basePath);
            $this->thumbnailManager->deleteCachedThumbnail($profile, $canonical);
            $this->eventDispatcher->dispatch(new FileUploadedEvent($profile, $volume, $targetPath, $canonical, strlen($binary), $mime));

            return $this->privateJsonResponse([
                'ok' => true,
                'path' => $targetPath,
                'message' => $this->messageTranslator->trans('messages.image_saved', ['%name%' => PathNormalizer::basenameUtf8($targetPath)], $locale, 'Image saved: %name%'),
            ]);
        } catch (AccessDeniedException $e) {
            $locale = $this->messageTranslator->resolveLocale($request, $profile);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->translateException($e, $locale),
            ], Response::HTTP_FORBIDDEN);
        } catch (FileManagerException $e) {
            $locale = $this->messageTranslator->resolveLocale($request, $profile);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->translateException($e, $locale),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (FilesystemException) {
            $locale = $this->messageTranslator->resolveLocale($request, $profile);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Operation failed.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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

    private function assertValidUpload(UploadedFile $file, ProfileConfig $profile): string
    {
        $maxBytes = $this->parseByteSize($profile->uploadMaxSize);
        if ($maxBytes > 0 && $file->getSize() > $maxBytes) {
            throw new FileManagerException(
                sprintf('Edited image exceeds maximum allowed size (%s).', $profile->uploadMaxSize),
                'errors.edited_image_too_large',
                ['%max%' => $profile->uploadMaxSize],
            );
        }

        $mime = (string) $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new FileManagerException('Only JPEG, PNG, and WebP edited outputs are allowed.', 'errors.edited_image_mime');
        }

        $this->assertProfileAllowsMime($profile, $mime);

        return $mime;
    }

    private function assertProfileAllowsMime(ProfileConfig $profile, string $mime): void
    {
        if ($this->fileSecurityGuard->matchesMimeList($mime, $profile->uploadDeny)) {
            throw new FileManagerException(
                sprintf('MIME type "%s" is denied by upload_deny policy.', $mime),
                'errors.upload_mime_denied',
                ['%mime%' => $mime],
            );
        }

        if ($profile->uploadAllow !== [] && !$this->fileSecurityGuard->matchesMimeList($mime, $profile->uploadAllow)) {
            throw new FileManagerException(
                sprintf('MIME type "%s" is not in upload_allow list.', $mime),
                'errors.upload_mime_not_allowed',
                ['%mime%' => $mime],
            );
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readValidateAndSanitizeRasterBinary(
        UploadedFile $file,
        ProfileConfig $profile,
        string $cleanPath,
        string $expectedMime,
        string $saveMode,
    ): array {
        $binary = file_get_contents($file->getPathname());
        if (!is_string($binary) || $binary === '') {
            throw new FileManagerException('Unable to read edited image.', 'errors.edited_image_unreadable');
        }

        $info = @getimagesizefromstring($binary);
        if (!is_array($info)) {
            throw new FileManagerException('Uploaded payload is not a valid raster image.', 'errors.edited_image_invalid');
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        $type = (int) $info[2];

        if (!in_array($type, self::ALLOWED_IMAGETYPES, true)) {
            throw new FileManagerException('Unsupported raster image format.', 'errors.edited_image_unsupported');
        }

        $detectedMime = image_type_to_mime_type($type);
        if ($detectedMime !== $expectedMime) {
            throw new FileManagerException('Uploaded image format does not match detected binary content.', 'errors.edited_image_mime');
        }
        $this->assertProfileAllowsMime($profile, $detectedMime);

        if ($w <= 0 || $h <= 0 || $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION || ($w * $h) > self::MAX_PIXELS) {
            throw new FileManagerException('Image dimensions exceed safe processing limits.', 'errors.edited_image_dimensions');
        }

        [$cleanBinary, $finalMime, $finalW, $finalH] = $this->reencodeAndOptimize($binary, $detectedMime, $profile, $cleanPath, $saveMode, $w, $h);
        $maxBytes = $this->parseByteSize($profile->uploadMaxSize);
        if ($maxBytes > 0 && strlen($cleanBinary) > $maxBytes) {
            throw new FileManagerException(
                sprintf('Edited image exceeds maximum allowed size (%s).', $profile->uploadMaxSize),
                'errors.edited_image_too_large',
                ['%max%' => $profile->uploadMaxSize],
            );
        }
        $this->assertProfileAllowsMime($profile, $finalMime);
        $this->assertProfileImageConstraints($profile, PathNormalizer::basenameUtf8($cleanPath) ?: 'image', $finalW, $finalH);

        return [$cleanBinary, $finalMime];
    }

    /**
     * Re-encodes edited image payloads to strip EXIF/polyglot trailing bytes and honor profile `image_optimization`.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private function reencodeAndOptimize(
        string $binary,
        string $mime,
        ProfileConfig $profile,
        string $cleanPath,
        string $saveMode,
        int $origW,
        int $origH,
    ): array {
        $temp = tempnam(sys_get_temp_dir(), 'liveux_edit_');
        if ($temp === false) {
            throw new FileManagerException('Unable to allocate temporary image buffer.', 'errors.action_failed');
        }
        $optPath = null;

        try {
            if (@file_put_contents($temp, $binary) === false) {
                throw new FileManagerException('Unable to write temporary image buffer.', 'errors.action_failed');
            }
            $optCfg = $profile->imageOptimization;
            if (($optCfg['enabled'] ?? false) !== true) {
                $optCfg = [
                    'enabled' => true,
                    'auto_orient' => false,
                    'strip_exif' => true,
                    'max_width' => self::MAX_DIMENSION,
                    'max_height' => self::MAX_DIMENSION,
                    'convert_to_webp' => false,
                    'quality' => 92,
                ];
            } elseif ($saveMode === 'overwrite') {
                // Overwriting must preserve the original file format.
                $optCfg['convert_to_webp'] = false;
            }

            $opt = $this->imageOptimizer->optimizeIfEnabled(
                $temp,
                PathNormalizer::basenameUtf8($cleanPath) ?: 'image.jpg',
                $mime,
                $profile->withImageOptimization($optCfg),
            );
            $optPath = is_string($opt['path'] ?? null) ? $opt['path'] : null;
            if ($opt['optimized'] !== true) {
                throw new FileManagerException(
                    'Image processing backend (GD or Imagick) failed to decode and sanitize the edited image.',
                    'errors.edited_image_invalid',
                );
            }
            $reencoded = file_get_contents($opt['path']);
            if (!is_string($reencoded) || $reencoded === '') {
                throw new FileManagerException(
                    'Image processing backend returned an empty image payload.',
                    'errors.edited_image_invalid',
                );
            }

            $newInfo = @getimagesizefromstring($reencoded);
            $nw = is_array($newInfo) ? (int) $newInfo[0] : $origW;
            $nh = is_array($newInfo) ? (int) $newInfo[1] : $origH;

            return [$reencoded, $opt['mime'], $nw, $nh];
        } finally {
            if (is_string($optPath) && $optPath !== '' && $optPath !== $temp) {
                @unlink($optPath);
            }
            @unlink($temp);
        }
    }

    private function assertNotDuplicatePayload(ProfileConfig $profile, string $volumeName, string $binary): void
    {
        if ($this->duplicateDetector === null) {
            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'liveux_edup_');
        if ($temp === false) {
            return;
        }

        try {
            file_put_contents($temp, $binary);
            $dup = $this->duplicateDetector->findDuplicate(
                $profile,
                $volumeName,
                $temp,
                fn (string $cand): bool => $this->authorizer->isPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cand, $volumeName),
            );
            if ($dup !== null) {
                throw new FileManagerException(
                    sprintf('Identical file already exists at "/%s".', $dup),
                    'errors.duplicate_file_detected',
                    ['%existing%' => '/' . $dup],
                );
            }
        } finally {
            @unlink($temp);
        }
    }

    private function assertProfileImageConstraints(ProfileConfig $profile, string $name, int $width, int $height): void
    {
        $constraints = $profile->imageConstraints;
        if ($constraints === []) {
            return;
        }

        $minW = is_int($constraints['min_width'] ?? null) && $constraints['min_width'] > 0 ? $constraints['min_width'] : null;
        $minH = is_int($constraints['min_height'] ?? null) && $constraints['min_height'] > 0 ? $constraints['min_height'] : null;
        $maxW = is_int($constraints['max_width'] ?? null) && $constraints['max_width'] > 0 ? $constraints['max_width'] : null;
        $maxH = is_int($constraints['max_height'] ?? null) && $constraints['max_height'] > 0 ? $constraints['max_height'] : null;

        if (($minW !== null && $width < $minW) || ($minH !== null && $height < $minH)) {
            throw new FileManagerException(
                sprintf('Image "%s" (%dx%dpx) is smaller than the minimum required.', $name, $width, $height),
                'errors.image_too_small',
                [
                    '%name%' => $name,
                    '%width%' => $width,
                    '%height%' => $height,
                    '%minWidth%' => $minW ?? 0,
                    '%minHeight%' => $minH ?? 0,
                ],
            );
        }

        if (($maxW !== null && $width > $maxW) || ($maxH !== null && $height > $maxH)) {
            throw new FileManagerException(
                sprintf('Image "%s" (%dx%dpx) exceeds maximum allowed dimensions.', $name, $width, $height),
                'errors.image_too_large_dim',
                [
                    '%name%' => $name,
                    '%width%' => $width,
                    '%height%' => $height,
                    '%maxWidth%' => $maxW ?? '∞',
                    '%maxHeight%' => $maxH ?? '∞',
                ],
            );
        }

        $ratioLabel = is_string($constraints['aspect_ratio'] ?? null) ? trim($constraints['aspect_ratio']) : '';
        if ($ratioLabel !== '' && preg_match('/^(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)$/', $ratioLabel, $m) === 1) {
            $rw = (float) $m[1];
            $rh = (float) $m[2];
            if ($rw > 0.0 && $rh > 0.0) {
                $expected = $rw / $rh;
                $tolerance = is_numeric($constraints['aspect_ratio_tolerance'] ?? null)
                    ? max(0.0, min(0.5, (float) $constraints['aspect_ratio_tolerance']))
                    : 0.05;
                if ((abs(($width / $height) - $expected) / $expected) > $tolerance) {
                    throw new FileManagerException(
                        sprintf('Image "%s" (%dx%dpx) does not match required aspect ratio (%s).', $name, $width, $height, $ratioLabel),
                        'errors.image_aspect_ratio',
                        [
                            '%name%' => $name,
                            '%width%' => $width,
                            '%height%' => $height,
                            '%expected%' => $ratioLabel,
                        ],
                    );
                }
            }
        }
    }

    private function assertAuthorized(ProfileConfig $profile, string $cleanPath, ?string $vol, string $saveMode = 'copy'): void
    {
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $vol);
        $targetDir = PathNormalizer::dirnameUtf8($cleanPath);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $saveMode === 'copy' ? $targetDir : $cleanPath, $vol);

        // Overwriting destroys the original (no trash / versioning): it additionally requires the DELETE permission.
        if ($saveMode === 'overwrite') {
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::DELETE, $cleanPath, $vol);
        }
    }

    private function resolveTargetPath(
        FilesystemOperator $filesystem,
        string $cleanPath,
        string $saveMode,
        string $mime,
        ProfileConfig $profile,
    ): string {
        if ($saveMode === 'overwrite') {
            if ($cleanPath === '') {
                throw new FileManagerException('Original image does not exist to overwrite.', 'errors.overwrite_missing');
            }

            $ext = PathNormalizer::extensionUtf8($cleanPath);
            $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));
            if (!in_array($ext, FileSecurityGuard::SAFE_RASTER_EXTENSIONS, true)) {
                throw new FileManagerException('Overwrite is only allowed on existing raster image files.', 'errors.overwrite_raster_only');
            }
            $expectedMime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => null,
            };
            if ($expectedMime === null || $expectedMime !== $mime) {
                throw new FileManagerException(
                    'Overwrite is only allowed when the output format matches the original JPEG, PNG, or WebP file.',
                    'errors.overwrite_format_mismatch',
                );
            }
            if (!$filesystem->fileExists($cleanPath)) {
                throw new FileManagerException('Original image does not exist to overwrite.', 'errors.overwrite_missing');
            }

            return $cleanPath;
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $dir = PathNormalizer::dirnameUtf8($cleanPath);
        $rawBase = UnicodeHelper::stripIgnorableFillers(UnicodeHelper::toNfc(PathNormalizer::stemUtf8($cleanPath) ?: 'image'));
        $sanitizedBase = trim(preg_replace('/[^\p{L}\p{M}\p{N}\x{200C}_-]+/u', '-', $rawBase) ?? '', '-');
        $sanitizedBase = preg_replace('/(?<=^|-)[\p{M}]+/u', '', $sanitizedBase) ?? $sanitizedBase;
        $sanitizedBase = UnicodeHelper::truncateStemBytes($sanitizedBase, 190);
        $base = $sanitizedBase !== '' ? $sanitizedBase : 'image';

        $normalizedSeed = $this->filenameNormalizer->normalizeForUpload($base . '.' . $ext, $profile, PathNormalizer::basenameUtf8($dir));
        $seedStem = UnicodeHelper::truncateStemBytes(PathNormalizer::stemUtf8($normalizedSeed), 185);
        if ($seedStem === '') {
            $seedStem = 'image';
        }

        $counter = 1;
        do {
            $suffix = $counter === 1 ? '-edited' : sprintf('-edited-%d', $counter);
            $filename = $seedStem . $suffix . '.' . $ext;
            $this->fileSecurityGuard->assertSafeFilename($filename);
            $candidate = $this->pathNormalizer->join($dir, $filename);
            $this->fileSecurityGuard->assertAccessiblePath($candidate, $profile);
            ++$counter;
        } while ($counter <= 100 && ($filesystem->fileExists($candidate) || $filesystem->directoryExists($candidate)));

        if ($filesystem->fileExists($candidate) || $filesystem->directoryExists($candidate)) {
            $filename = $seedStem . '-edited-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $this->fileSecurityGuard->assertSafeFilename($filename);
            $candidate = $this->pathNormalizer->join($dir, $filename);
            $this->fileSecurityGuard->assertAccessiblePath($candidate, $profile);
            if ($filesystem->fileExists($candidate) || $filesystem->directoryExists($candidate)) {
                throw new FileManagerException('Unable to resolve unique filename for edited image.', 'errors.upload_file_exists', ['%name%' => $filename]);
            }
        }

        return $candidate;
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

        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->trans('errors.no_edited_image', [], $locale, 'No edited image uploaded.'),
        ], Response::HTTP_BAD_REQUEST);
    }

    private function isPostMaxSizeExceeded(Request $request): bool
    {
        $contentLength = (int) $request->headers->get('Content-Length', '0');
        if ($contentLength <= 0 || $request->request->count() > 0 || $request->files->count() > 0) {
            return false;
        }

        $postMaxBytes = $this->parseByteSize((string) ini_get('post_max_size'));

        return $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    private function parseByteSize(string $size): int
    {
        return ByteSizeParser::parse($size);
    }
}
