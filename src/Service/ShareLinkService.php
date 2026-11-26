<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Generates and cryptographically verifies HMAC-signed, time-limited temporary share URLs
 * using Symfony's built-in UriSigner (zero extra packages).
 * Works seamlessly on both public and private (private: true / S3 / local) storage volumes.
 */
final readonly class ShareLinkService
{
    private const int MIN_TTL_SECONDS = 60;

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private UrlGeneratorInterface $urlGenerator,
        private ?UriSigner $uriSigner = null,
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private DynamicPathResolver $pathResolver = new DynamicPathResolver(),
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function isAvailableForProfile(ProfileConfig $profile): bool
    {
        if ($this->uriSigner === null) {
            return false;
        }

        return ($profile->share['enabled'] ?? true) === true;
    }

    /**
     * @return array{url: string, expiresAt: int, ttlSeconds: int}
     */
    public function createSignedUrl(
        ProfileConfig $profile,
        string $path,
        ?string $volumeName = null,
        ?int $ttlSeconds = null,
        bool $inline = false,
    ): array {
        if (!$this->isAvailableForProfile($profile) || $this->uriSigner === null) {
            throw new FileManagerException('Signed share links are disabled or UriSigner is unavailable.', 'errors.share_disabled');
        }

        $cleanPath = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));

        $now = new \DateTimeImmutable();
        $rawVolume = $this->volumeRegistry->getRawVolumeForProfile($profile, $volumeName);
        $usesUserToken = self::volumeUsesUserIdToken($rawVolume);
        $usesDateToken = self::volumeUsesDateTokens($rawVolume);
        $resolvedUserId = $usesUserToken ? $this->pathResolver->getCurrentUserId() : null;
        $volume = ($usesUserToken || $usesDateToken)
            ? $this->volumeRegistry->getVolumeForProfileWithContext($profile, $rawVolume->name, $usesDateToken ? $now : null, $resolvedUserId)
            : $this->volumeRegistry->getVolumeForProfile($profile, $rawVolume->name);
        $this->assertShareGranted($profile, $cleanPath, $volume->name);

        if ($cleanPath === '') {
            throw new FileManagerException('File not found.', 'errors.file_not_found');
        }

        $filesystem = ($usesUserToken || $usesDateToken)
            ? $this->volumeRegistry->getFilesystemWithContext($profile, $volume->name, $usesDateToken ? $now : null, $resolvedUserId)
            : $this->volumeRegistry->getFilesystem($profile, $volume->name);
        if (!$filesystem->fileExists($cleanPath)) {
            throw new FileManagerException('File not found.', 'errors.file_not_found');
        }

        $defaultTtl = max(self::MIN_TTL_SECONDS, (int) ($profile->share['default_ttl'] ?? 86400));
        $maxTtl = max($defaultTtl, (int) ($profile->share['max_ttl'] ?? 2592000));
        $requestedTtl = $ttlSeconds ?? $defaultTtl;
        $effectiveTtl = min($maxTtl, max(self::MIN_TTL_SECONDS, $requestedTtl));
        $expiresAt = $now->getTimestamp() + $effectiveTtl;

        $allowInline = ($profile->share['allow_inline_preview'] ?? true) === true;
        $effectiveInline = ($inline && $allowInline) ? 1 : 0;

        $params = [
            'profile' => $profile->name,
            'volume' => $volume->name,
            'path' => $cleanPath,
            'inline' => $effectiveInline,
            'v' => $this->computeFileVersionFingerprint($filesystem, $cleanPath),
        ];

        // Cryptographically bind resolved dynamic {user_id} and {Y}/{m}/{d} context into the signed URL
        // using the exact same snapshot ($now) so midnight rollovers cannot break share links.
        if ($usesUserToken) {
            $params['u'] = $resolvedUserId;
        }
        if ($usesDateToken) {
            $params['d'] = $now->format('Y-m-d');
        }

        $supportsNativeExpiration = method_exists($this->uriSigner, 'verify');
        if (!$supportsNativeExpiration) {
            $params['_expiration'] = $expiresAt;
        }

        $unsignedUrl = $this->urlGenerator->generate(
            'liveux_file_manager_share',
            $params,
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $signedUrl = $supportsNativeExpiration
            ? $this->uriSigner->sign($unsignedUrl, $expiresAt)
            : $this->uriSigner->sign($unsignedUrl);

        return [
            'url' => $signedUrl,
            'expiresAt' => $expiresAt,
            'ttlSeconds' => $effectiveTtl,
        ];
    }

    /**
     * Verifies the HMAC signature AND expiration timestamp of an incoming share request.
     * Supports both Symfony 7.1+/8.x UriSigner::verify() and Symfony 6.4 LTS UriSigner::checkRequest().
     *
     * @throws FileManagerException on expired, tampered, or unsigned link
     */
    public function assertValidSignedRequest(Request $request): void
    {
        if ($this->uriSigner === null) {
            throw new FileManagerException('Signed share links are unavailable.', 'errors.share_disabled');
        }

        // Require an explicit expiration parameter so infinite leaked URLs are impossible
        if (!$request->query->has('_expiration') || !$request->query->has('_hash')) {
            throw new AccessDeniedException('Invalid or missing share link signature.', 'errors.share_link_invalid');
        }

        if (method_exists($this->uriSigner, 'verify')) {
            try {
                $this->uriSigner->verify($request);
            } catch (ExpiredSignedUriException $e) {
                throw new FileManagerException('This share link has expired.', 'errors.share_link_expired', [], 410, $e);
            } catch (SignedUriException $e) {
                throw new AccessDeniedException('Invalid share link signature.', 'errors.share_link_invalid', [], 403, $e);
            }

            return;
        }

        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedException('Invalid share link signature.', 'errors.share_link_invalid');
        }

        $rawExpiration = $request->query->get('_expiration');
        if (!is_string($rawExpiration) || !ctype_digit($rawExpiration) || (int) $rawExpiration < time()) {
            throw new FileManagerException('This share link has expired.', 'errors.share_link_expired', [], 410);
        }
    }

    public function computeFileVersionFingerprint(FilesystemOperator $filesystem, string $cleanPath): string
    {
        try {
            $size = $filesystem->fileSize($cleanPath);
        } catch (\Throwable) {
            $size = -1;
        }
        try {
            $mtime = $filesystem->lastModified($cleanPath);
        } catch (\Throwable) {
            $mtime = -1;
        }

        return substr(hash('sha256', $size . ':' . $mtime), 0, 16);
    }

    public static function volumeUsesUserIdToken(VolumeConfig $rawVolume): bool
    {
        $prefix = is_string($rawVolume->driverOptions['prefix'] ?? null) ? $rawVolume->driverOptions['prefix'] : '';

        return ($rawVolume->path !== null && DynamicPathResolver::containsUserIdToken($rawVolume->path))
            || ($rawVolume->urlPrefix !== null && DynamicPathResolver::containsUserIdToken($rawVolume->urlPrefix))
            || ($prefix !== '' && DynamicPathResolver::containsUserIdToken($prefix));
    }

    public static function volumeUsesDateTokens(VolumeConfig $rawVolume): bool
    {
        $prefix = is_string($rawVolume->driverOptions['prefix'] ?? null) ? $rawVolume->driverOptions['prefix'] : '';

        return ($rawVolume->path !== null && DynamicPathResolver::containsDateTokens($rawVolume->path))
            || ($rawVolume->urlPrefix !== null && DynamicPathResolver::containsDateTokens($rawVolume->urlPrefix))
            || ($prefix !== '' && DynamicPathResolver::containsDateTokens($prefix));
    }

    private function assertShareGranted(ProfileConfig $profile, string $cleanPath, ?string $volumeName): void
    {
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volumeName);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::SHARE, $cleanPath, $volumeName);
    }
}
