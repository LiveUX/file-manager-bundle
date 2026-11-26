<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use LiveUX\FileManagerBundle\AI\NullAssetEnricher;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/**
 * Orchestrates optional AI metadata enrichment (alt, title, caption, tags) with:
 * - Zero forced AI dependency (gracefully unavailable when no AssetEnricherInterface is configured)
 * - Automatic image downscaling (default 1024px) before sending to Vision LLMs (~80% token savings)
 * - Per-profile opt-in, RBAC Voter enforcement, and optional private-volume cloud privacy guard
 */
final readonly class AssetEnrichmentService
{
    private const int MAX_SOURCE_BYTES = 20_971_520; // 20 MB safety cap

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
        private DirectoryCacheManager $cacheManager,
        private ImageOptimizer $imageOptimizer = new ImageOptimizer(),
        private ?AssetEnricherInterface $enricher = null,
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function hasActiveEnricher(): bool
    {
        return $this->enricher !== null && !$this->enricher instanceof NullAssetEnricher;
    }

    public function isAvailableForProfile(ProfileConfig $profile): bool
    {
        return $this->hasActiveEnricher() && (($profile->ai['enabled'] ?? false) === true);
    }

    public function supportsMime(string $mimeType): bool
    {
        return $this->hasActiveEnricher() && ($this->enricher?->supports($mimeType) ?? false);
    }

    /**
     * Generates AI metadata suggestions WITHOUT mutating stored metadata (for editor review in the modal).
     */
    public function suggestMetadata(
        ProfileConfig $profile,
        string $path,
        ?string $volumeName = null,
        ?string $localeOverride = null,
        bool $skipVoter = false,
    ): AssetEnrichmentResult {
        if (!$this->isAvailableForProfile($profile) || $this->enricher === null) {
            throw new FileManagerException('AI enrichment is disabled or no AI enricher is configured.', 'errors.ai_unavailable');
        }

        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        if (!$skipVoter) {
            $this->assertEnrichGranted($profile, $cleanPath, $volume->name);
        }

        if ($cleanPath === '') {
            throw new FileManagerException('File not found.', 'errors.file_not_found');
        }

        if ($volume->private && ($profile->ai['block_private_volumes'] ?? true) === true) {
            throw new AccessDeniedException(
                'AI enrichment is blocked for private storage volumes by profile security policy.',
                'errors.ai_private_blocked',
            );
        }

        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        if (!$filesystem->fileExists($cleanPath)) {
            throw new FileManagerException('File not found.', 'errors.file_not_found');
        }

        return $this->runEnricherOnFile($filesystem, $profile, $cleanPath, $localeOverride);
    }

    /**
     * Generates AI metadata and persists it into FolderMetadataStorageInterface.
     * When $onlyMissing is true, existing manual alt/title/caption/tags are never overwritten.
     */
    public function enrichAndSave(
        ProfileConfig $profile,
        string $path,
        ?string $volumeName = null,
        bool $onlyMissing = true,
        ?string $localeOverride = null,
        bool $skipVoter = false,
    ): AssetEnrichmentResult {
        if (($profile->metadata['enabled'] ?? true) !== true) {
            throw new AccessDeniedException('Metadata editing is disabled for this profile.', 'errors.access_denied');
        }

        $cleanPath = $this->pathNormalizer->normalize($path);
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        if (!$skipVoter) {
            $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::EDIT_METADATA, $cleanPath, $volume->name);
        }

        $suggestion = $this->suggestMetadata($profile, $cleanPath, $volume->name, $localeOverride, $skipVoter);
        if ($suggestion->isEmpty()) {
            return $suggestion;
        }

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $existing = $this->metadataStorage->get($canonical);

        $merged = FolderMetadata::fromArray([
            'color' => $existing->color,
            'icon' => $existing->icon,
            'pinned' => $existing->pinned,
            'alt' => ($onlyMissing && $existing->alt !== null) ? $existing->alt : ($suggestion->alt ?? $existing->alt),
            'title' => ($onlyMissing && $existing->title !== null) ? $existing->title : ($suggestion->title ?? $existing->title),
            'caption' => ($onlyMissing && $existing->caption !== null) ? $existing->caption : ($suggestion->caption ?? $existing->caption),
            'tags' => ($onlyMissing && $existing->tags !== []) ? $existing->tags : ($suggestion->tags !== [] ? $suggestion->tags : $existing->tags),
            'focalPoint' => $existing->focalPoint,
        ]);

        $this->metadataStorage->save($canonical, $merged);
        $parentDir = PathNormalizer::dirnameUtf8($cleanPath);
        $parentCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $parentDir, $profile->basePath);
        $this->cacheManager->invalidateDirectory($parentCanonical);

        return $suggestion;
    }

    private function runEnricherOnFile(
        FilesystemOperator $filesystem,
        ProfileConfig $profile,
        string $cleanPath,
        ?string $localeOverride,
    ): AssetEnrichmentResult {
        $size = $filesystem->fileSize($cleanPath);
        if ($size <= 0 || $size > self::MAX_SOURCE_BYTES) {
            throw new FileManagerException('File size exceeds safe AI processing limits.', 'errors.ai_file_too_large');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'liveux_fm_ai_');
        if ($tempFile === false) {
            throw new FileManagerException('Unable to allocate temporary file.', 'errors.temp_file_failed');
        }

        try {
            $stream = $filesystem->readStream($cleanPath);
            if (!is_resource($stream)) {
                throw new FileManagerException('Unable to read file stream.', 'errors.upload_stream_unreadable');
            }
            $out = fopen($tempFile, 'wb');
            if ($out === false) {
                fclose($stream);
                throw new FileManagerException('Unable to allocate temporary file.', 'errors.temp_file_failed');
            }
            try {
                stream_copy_to_stream($stream, $out, self::MAX_SOURCE_BYTES);
            } finally {
                fclose($stream);
                fclose($out);
            }

            $filename = PathNormalizer::basenameUtf8($cleanPath);
            $mime = $this->detectMime($tempFile, $filename);

            if ($this->enricher === null || !$this->enricher->supports($mime)) {
                throw new FileManagerException(
                    sprintf('AI enrichment is not supported for MIME type "%s".', $mime),
                    'errors.ai_unsupported_type',
                );
            }

            [$preparedPath, $preparedMime] = $this->downscaleTempImageIfNeeded($tempFile, $filename, $mime, $profile);
            $locale = $this->resolveLocale($profile, $localeOverride);
            $context = [
                'filename' => $filename,
                'profile' => $profile->name,
                'model' => is_string($profile->ai['model'] ?? null) ? $profile->ai['model'] : null,
                'max_tags' => max(1, min(20, (int) ($profile->ai['max_tags'] ?? 6))),
            ];

            return $this->enricher->enrich($preparedPath, $preparedMime, $locale, $context);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Downscales large raster images in-place on the temporary copy before sending to a Vision LLM,
     * drastically reducing bandwidth and token cost while degrading gracefully without GD/Imagick.
     *
     * @return array{0: string, 1: string}
     */
    private function downscaleTempImageIfNeeded(
        string $tempFile,
        string $filename,
        string $mime,
        ProfileConfig $profile,
    ): array {
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return [$tempFile, $mime];
        }

        $maxDim = max(256, min(2048, (int) ($profile->ai['max_image_dimension'] ?? 1024)));
        $tempProfile = $profile->withImageOptimization([
            'enabled' => true,
            'auto_orient' => true,
            'strip_exif' => true,
            'max_width' => $maxDim,
            'max_height' => $maxDim,
            'convert_to_webp' => false,
            'quality' => 80,
        ]);

        try {
            $opt = $this->imageOptimizer->optimizeIfEnabled($tempFile, $filename, $mime, $tempProfile);

            return [$opt['path'], $opt['mime']];
        } catch (Throwable) {
            return [$tempFile, $mime];
        }
    }

    private function detectMime(string $tempFile, string $filename): string
    {
        $mimeTypes = new MimeTypes();
        $guessed = $mimeTypes->guessMimeType($tempFile);
        if ($guessed !== null && $guessed !== '') {
            return strtolower($guessed);
        }

        $ext = PathNormalizer::extensionUtf8($filename);

        return strtolower($mimeTypes->getMimeTypes($ext)[0] ?? 'application/octet-stream');
    }

    private function resolveLocale(ProfileConfig $profile, ?string $localeOverride): string
    {
        foreach ([$localeOverride, $profile->ai['locale'] ?? null, $profile->locale] as $candidate) {
            if (is_string($candidate) && MessageTranslator::isValidLocaleString($candidate)) {
                return trim($candidate);
            }
        }

        return 'en';
    }

    private function assertEnrichGranted(ProfileConfig $profile, string $cleanPath, ?string $volumeName): void
    {
        if (($profile->metadata['enabled'] ?? true) !== true) {
            throw new AccessDeniedException('Metadata editing is disabled for this profile.', 'errors.access_denied');
        }

        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volumeName);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::AI_ENRICH, $cleanPath, $volumeName);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::EDIT_METADATA, $cleanPath, $volumeName);
    }
}
