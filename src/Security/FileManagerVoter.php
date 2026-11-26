<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, FileManagerSubject>
 */
final class FileManagerVoter extends Voter
{
    public function __construct(
        private readonly ?AccessDecisionManagerInterface $accessDecisionManager = null,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject && FileManagerPermissions::supports($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $configKey = FileManagerPermissions::toConfigKey($attribute);
        if ($configKey === null) {
            return false;
        }

        // Baseline gate: anyone denied `view` cannot perform any other operation (download, zip, share, rename, trash, etc.) on that profile.
        if ($configKey !== 'view' && !$this->evaluateRule($subject->profile->getPermissionRule('view'), $token)) {
            return false;
        }

        // Hard feature gate: `extract_zip` can never be granted when `zip.allow_extract` is disabled,
        // even if `permissions.extract_zip` holds a role string preserved across multi-file config merges.
        if ($configKey === 'extract_zip' && ($subject->profile->zip['allow_extract'] ?? false) !== true) {
            return false;
        }

        return $this->evaluateRule($subject->profile->getPermissionRule($configKey), $token);
    }

    private function evaluateRule(mixed $rule, TokenInterface $token): bool
    {
        // Boolean `true` grants access ONLY to authenticated users; anonymous visitors are always denied.
        if (is_bool($rule)) {
            return $rule && $token->getUser() !== null;
        }

        if ($this->accessDecisionManager === null) {
            return false;
        }

        if (is_string($rule) && $rule !== '') {
            return $this->accessDecisionManager->decide($token, [$rule]);
        }

        if (is_array($rule)) {
            return $this->decideRoles($rule, $token);
        }

        return false;
    }

    /**
     * @param array<mixed> $roles
     */
    private function decideRoles(array $roles, TokenInterface $token): bool
    {
        if ($roles === [] || $this->accessDecisionManager === null) {
            return false;
        }

        foreach ($roles as $role) {
            if (is_string($role) && $role !== '' && $this->accessDecisionManager->decide($token, [$role])) {
                return true;
            }
        }

        return false;
    }
}
