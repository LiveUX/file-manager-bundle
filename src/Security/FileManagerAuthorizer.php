<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Centralized fail-closed authorization gate for FileManager operations.
 *
 * - When `security.voter_enabled` is true and Symfony Security is installed, delegates
 *   to `AuthorizationCheckerInterface` (supporting both FileManagerVoter and custom voters).
 * - When Symfony Security is absent or `voter_enabled` is false, explicit `permissions.<key>: false`
 *   rules ALWAYS deny, and role-based rules (string/array) ALWAYS fail closed instead of failing open.
 */
final readonly class FileManagerAuthorizer
{
    public function __construct(
        private ?AuthorizationCheckerInterface $authChecker = null,
    ) {
    }

    public function isGranted(
        ProfileConfig $profile,
        string $attribute,
        string $path = '',
        ?string $volumeName = null,
        ?string $targetPath = null,
    ): bool {
        if ($attribute === FileManagerPermissions::EXTRACT_ZIP && ($profile->zip['allow_extract'] ?? false) !== true) {
            return false;
        }

        if ($profile->isVoterEnabled() && $this->authChecker !== null) {
            $subject = new FileManagerSubject($profile, $path, $volumeName, $targetPath);

            return $this->authChecker->isGranted($attribute, $subject);
        }

        $configKey = FileManagerPermissions::toConfigKey($attribute);
        if ($configKey === null) {
            return false;
        }

        if ($configKey !== 'view' && $profile->getPermissionRule('view') !== true) {
            return false;
        }

        // Only explicit boolean `true` grants access without a security voter; `false` and role rules fail closed.
        return $profile->getPermissionRule($configKey) === true;
    }

    /**
     * Evaluates authorization on the target path AND every intermediate ancestor directory so
     * folder-level custom voters denying an ancestor (e.g. `projects/secret`) cannot be bypassed
     * via direct descendant file paths (especially on object stores omitting directory markers).
     */
    public function isPathAndAncestorsGranted(
        ProfileConfig $profile,
        string $attribute,
        string $path = '',
        ?string $volumeName = null,
        ?string $targetPath = null,
    ): bool {
        if ($path === '' || !$profile->isVoterEnabled() || $this->authChecker === null) {
            return $this->isGranted($profile, $attribute, $path, $volumeName, $targetPath);
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return $this->isGranted($profile, $attribute, '', $volumeName, $targetPath);
        }

        $prefix = '';
        $lastIdx = count($segments) - 1;
        foreach ($segments as $idx => $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $checkTarget = $idx === $lastIdx ? $targetPath : null;
            if ($idx < $lastIdx && $attribute !== FileManagerPermissions::VIEW) {
                if (!$this->isGranted($profile, FileManagerPermissions::VIEW, $prefix, $volumeName)) {
                    return false;
                }
            }
            if (!$this->isGranted($profile, $attribute, $prefix, $volumeName, $checkTarget)) {
                return false;
            }
        }

        return true;
    }

    public function assertPathAndAncestorsGranted(
        ProfileConfig $profile,
        string $attribute,
        string $path = '',
        ?string $volumeName = null,
        ?string $targetPath = null,
    ): void {
        if (!$this->isPathAndAncestorsGranted($profile, $attribute, $path, $volumeName, $targetPath)) {
            throw new AccessDeniedException(sprintf('Access denied for "%s".', $attribute), 'errors.access_denied');
        }
    }

    public function assertGranted(
        ProfileConfig $profile,
        string $attribute,
        string $path = '',
        ?string $volumeName = null,
        ?string $targetPath = null,
    ): void {
        if (!$this->isGranted($profile, $attribute, $path, $volumeName, $targetPath)) {
            throw new AccessDeniedException(sprintf('Access denied for "%s".', $attribute), 'errors.access_denied');
        }
    }
}
