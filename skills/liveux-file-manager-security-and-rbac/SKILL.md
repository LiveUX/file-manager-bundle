---
name: liveux-file-manager-security-and-rbac
description: >-
  Configures production security for LiveUX FileManagerBundle: Symfony firewall
  access_control, 17 granular RBAC permissions, custom per-folder Voters on
  FileManagerSubject, Pre-Delete Usage Guard (AssetUsageCheckerInterface),
  HMAC-signed expiring Share Links, Audit Logging, and Apache/Nginx upload
  hardening. Use when securing endpoints, restricting permissions, or preventing
  accidental deletion of referenced assets.
license: MIT
compatibility: PHP >= 8.3, Symfony ^6.4|^7.2|^8.0, symfony/security-bundle
metadata:
  author: liveux
  version: "0.1.0"
---

# LiveUX FileManagerBundle — Security, RBAC & Usage Guard

Follow this security checklist whenever deploying to production, restricting user actions, or preventing deletion of files in use.

## 1. Symfony Firewall Protection (`security.yaml`)

Always gate `/_liveux-file-manager` behind Symfony Security (except `/_liveux-file-manager/share` if you want anonymous visitors to open HMAC-signed share links):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

## Security Warning: Permission Defaults & Anonymous Access

> [!WARNING]
> Every `permissions.*` key defaults to `true` (except `extract_zip`: `false`), and `security.voter_enabled` defaults to `true`. A boolean `true` rule grants the action to **authenticated users only**; anonymous visitors are denied. Boolean `true` is therefore NOT a substitute for a firewall: always also protect the routes with `access_control` (section 1) and set explicit roles per permission for production.
>
> - `voter_enabled: true` + Symfony Security installed: `FileManagerAuthorizer` delegates to `FileManagerVoter`. `false` denies, role string/array rules are decided through the access decision manager, and a boolean `true` applies only to authenticated users.
> - `voter_enabled: false` (or Symfony Security absent): RBAC is skipped for every endpoint. Only an explicit boolean `true` grants; `false` and role rules fail closed. Use this for local development only.
> - `usage_guard.max_references` defaults to `20` (range 1–200).

## 2. Profile RBAC Permissions (`FileManagerVoter`)

Every profile supports **17 permissions** (values: `bool`, `'ROLE_X'`, or `['ROLE_A', 'ROLE_B']`). Denied actions are automatically hidden in the UI AND rejected with `HTTP 403` on the server:

```yaml
liveux_file_manager:
    profiles:
        editors:
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

## 3. Custom Folder-Level Symfony Voter

Write a Voter matching `FileManagerSubject` (`$subject->profile`, `$subject->path`, `$subject->volume`). Both single operations and multi-file ZIP downloads check every individual path against Voters:

```php
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class LockedFolderVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [FileManagerPermissions::DELETE, FileManagerPermissions::MOVE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->path !== 'locked' && !str_starts_with($subject->path, 'locked/');
    }
}
```

*(Note: to let a custom Voter veto even when `FileManagerVoter` grants a role, use `access_decision_manager.strategy: unanimous` or deny via custom Voter priority.)*

## 4. Pre-Delete Usage Guard (`AssetUsageCheckerInterface`)

Prevent editors from deleting images still attached to products/banners/posts:

1. Enable in profile config:
   ```yaml
   usage_guard:
       enabled: true
       policy: 'block'        # 'warn' (shows warning + "Delete Anyway") | 'block' (hard server + UI block)
       max_references: 25
   ```
2. Implement `AssetUsageCheckerInterface` (autoconfigured via `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`):
   ```php
   use LiveUX\FileManagerBundle\Config\ProfileConfig;
   use LiveUX\FileManagerBundle\Config\VolumeConfig;
   use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
   use LiveUX\FileManagerBundle\Usage\AssetUsageReference;

   final readonly class ProductUsageChecker implements AssetUsageCheckerInterface
   {
       public function findUsages(ProfileConfig $profile, VolumeConfig $volume, string $path, bool $isFolder = false): array
       {
           // Query Doctrine/CMS for references matching $path (or prefix when $isFolder === true)
           return [
               new AssetUsageReference(
                   label: 'Thermos Pro #42',
                   type: 'Product',
                   editUrl: '/admin/products/42/edit',
                   entityId: '42',
               ),
           ];
       }
   }
   ```

## 5. Share Links & Audit Log

- **Share Links:** Set `share: { enabled: true, default_ttl: 86400, max_ttl: 2592000, allow_inline_preview: true }` + `permissions.share`. Revoke all profile links instantly by setting `share.enabled: false`.
- **Audit Log:** Set `defaults.audit_log: { enabled: true, level: 'info' }` (or per-profile `security.audit_log: true|false`) to log 9 structured PSR-3 events (`action`, `profile`, `volume`, `path`, `user`, `ip`) with log-injection scrubbing. Route to a dedicated rotating JSON file via `monolog.channels: ['file_manager_audit']` + binding `$logger: '@monolog.logger.file_manager_audit'` on `AuditLogListener`, or subscribe to `LiveUX\FileManagerBundle\Event\*` to persist to a Doctrine table.

See all 17 permission constants and Apache/Nginx upload directory hardening rules in [references/permissions-and-security-checklist.md](references/permissions-and-security-checklist.md).

## 6. Verification

```bash
php bin/console debug:container --tag=liveux_file_manager.usage_checker
php bin/console debug:firewall
php bin/console file-manager:doctor
```
