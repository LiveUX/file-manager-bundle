# 06 — Security & Authorization (RBAC & Defense-in-Depth)

[← Previous: 05 — FormType & Editor Integrations](05-formtype-and-editor-integrations.md) | [Index](README.md) | [Next: 07 — Cloud Storage & Dynamic Config →](07-cloud-storage-and-dynamic-config.md)

File managers are one of the highest-risk attack surfaces in any web application. LiveUX FileManagerBundle is designed around a **defense-in-depth** architecture with multi-layered security protections enabled by default.

> **At a Glance: How to Lock Down Security in 3 Steps**  
> 1. **Protect Routes (`security.yaml`):** Block unauthenticated visitors from reaching the file manager endpoints.  
> 2. **Define Role Permissions (`permissions`):** Explicitly declare in each profile who can view (`view`), upload (`upload`), and delete (`delete`).  
> 3. **Add a Route `prefix` for Multi-Firewall Apps:** If your app uses separate firewalls for an admin panel (`/admin`) and public users (`/`), mount the bundle routes under `prefix: /admin` in `config/routes/liveux_file_manager.yaml` so the admin session is properly resolved and regular members cannot reach admin files.

---

## 1. Protecting Routes in `security.yaml` (First Step)

In production, always protect all endpoints under `/_liveux-file-manager` behind Symfony's firewall:

```yaml
# config/packages/security.yaml (Single-firewall apps)
security:
    access_control:
        # Leave public only if you use signed share links for external recipients:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**If you have two separate firewalls — e.g., an admin area (`/admin`) and regular site members (`/`):**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Profile-Based Authorization (`permissions` & `FileManagerVoter`)

Every profile lets you define independent access rules for **17 distinct operations**.

### What Do Permission Values Mean?
- **`true`:** **Authenticated users only (`$token->getUser() !== null`)!** *(NEVER grants access to anonymous visitors.)*
- **`false`:** Nobody (operation is completely disabled).
- **`'ROLE_ADMIN'`:** Only users holding this specific role.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Users holding any of the listed roles.

### 4 Golden Authorization Rules (Enforced Automatically)
1. **`permissions.view` Is the Master Gate:** If a user lacks `view` permission, `upload`, `delete`, `download`, and every other operation are automatically denied.
2. **Fail-Closed (`FileManagerAuthorizer`):** When `security.voter_enabled: true` is configured but `AuthorizationCheckerInterface` (i.e., `symfony/security-bundle`) is not installed, the bundle **safely denies access (`AccessDeniedException`)** rather than failing open.
3. **Ancestor Directory Chain Checks (`isPathAndAncestorsGranted`):** When a user accesses `confidential/2026/invoice.pdf`, your custom Voters are automatically evaluated not just for `confidential/2026/invoice.pdf`, but also for its parent folders **`confidential`** and **`confidential/2026`** — preventing URL guessing from bypassing restricted parent directories.
4. **Dual-Lock ZIP Extraction (`zip.allow_extract`):** As long as `zip.allow_extract: false`, archive extraction is denied on the server regardless of `permissions.extract_zip`.

### Complete 17-Permission Voter Reference

| YAML Key | Voter Constant (`FileManagerPermissions::*`) | Protected Operations |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Directory listing, folder tree, thumbnails, inline previews, dialogs, and **prerequisite for all other permissions** |
| `upload` | `FILE_MANAGER_UPLOAD` | File uploads, chunked uploads, remote URL imports, image editor saves |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Creating new folders |
| `rename` | `FILE_MANAGER_RENAME` | Renaming files and folders |
| `clone` | `FILE_MANAGER_CLONE` | Cloning/duplicating files and folders |
| `move` | `FILE_MANAGER_MOVE` | Moving items (drag-and-drop & Move modal — both source and destination paths are checked) |
| `copy` | `FILE_MANAGER_COPY` | Copying items (both source and destination paths are checked) |
| `delete` | `FILE_MANAGER_DELETE` | Deleting items (soft trash / permanent delete) and `checkUsage` lookups |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Restoring items from the trash |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Permanently deleting items or emptying the trash |
| `download` | `FILE_MANAGER_DOWNLOAD` | Single-file downloads, PDF/video streaming, and copying URLs |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Multi-item ZIP archive downloads (checked individually for every selected path) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Safe ZIP extraction (also requires `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Generating expiring, HMAC-signed share links (also requires `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Clearing directory/profile/thumbnail caches, plus `?refresh=1` and `?fallback=1` cache bypasses |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Updating folder colors/icons/pins and file alt text, titles, tags, and focal points |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | AI-powered metadata suggestions (requires `view` + `edit_metadata` + `ai.enabled: true`) |

### Example Enterprise Profile Configuration

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
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

> [!TIP]
> **UI-Aware Permissions:** Action buttons that a user isn't authorized for (for example, the Delete button for a `ROLE_EDITOR` when `delete: 'ROLE_ADMIN'`) are automatically hidden in the UI **and** strictly enforced with `HTTP 403 Forbidden` if called directly via API.

---

## 3. Writing a Custom Symfony Voter (Fine-Grained Folder & User Rules)

When YAML roles aren't enough — for instance, *"prevent users from deleting the `locked/` folder or moving files into `locked/`"* — write your own Symfony Voter.

> [!WARNING]
> **Most Common Beginner Pitfall: `affirmative` vs. `unanimous`!**  
> Symfony's default `access_decision_manager.strategy` is **`affirmative`** (a single Voter returning `true` grants access). That means if the bundle's built-in `FileManagerVoter` returns `true` for a `ROLE_ADMIN` user, your custom Voter's **`false` (deny) vote is OVERRIDDEN**, and the user can still delete the file!  
>  
> To ensure your custom Voter's **deny** rules are actually enforced, set the strategy to **`unanimous`** in `config/packages/security.yaml` (every voting Voter must approve; a single `false` blocks the action):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(The bundle automatically detects custom application Voters via `DetectExternalVotersPass` and runs per-item Voter filtering on the folder tree and file counters to maintain both privacy and accuracy.)*

### `FileManagerSubject` Properties

The `$subject` passed to your custom Voter's `voteOnAttribute()` method exposes 4 readonly properties:

| Property | Type | Description | Example |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Active profile configuration (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Relative path of the source file/folder from the volume root | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Name of the active volume | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Destination path** for `MOVE`, `COPY`, `RENAME`, or `CLONE` operations (`null` for all others) | `'locked/new-name.pdf'` |

### Example Custom Voter (Locking the `locked/` Folder Against Deletes, Renames & Moves)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) Block deleting, moving, or renaming "locked" or anything inside it
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Also block moving or renaming files INTO the "locked" folder
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 Built-In Defense-in-Depth Layers (Hardened Against Real-World Attacks)

| # | Attack Vector | Bundle Protection |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Every write, delete, upload, ZIP, and image-editor `POST` request validates the `X-LiveUX-FM-CSRF` header **before** processing any input (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode & OS Tricks** | `PathNormalizer` blocks `../`, `..\`, null bytes (`\0`), control characters, `C:\`, `phar://`/`php://` stream wrappers, trailing dots/spaces, zero-width/bidi Unicode characters, CJK full stops (`。`), NTFS Alternate Data Streams (`file.txt:stream`), and Windows device names (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), enforcing max **32 depth**, **255 bytes/segment**, and **1024 UTF-8 chars / 3072 bytes** total path length. |
| 3 | **Executable, Polyglot & Double-Extension Files** | `FileSecurityGuard` enforces **69 blocked extensions** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, etc.), **47 blocked MIME types**, double-extension checks (`shell.php.jpg`), and **magic-bytes polyglot** validation — strictly enforced on uploads, renames (even when `allow_extension_change: true`), and downloads. |
| 4 | **Direct Access to Hidden & Trash Folders** | Paths under `.trash`, `.env`, `.git`, `.htaccess`, and `exclude_patterns` are rejected across all endpoints; `ZipDownloadController` performs an individual `DOWNLOAD_ZIP` Voter check on **every selected path**. |
| 5 | **SVG XSS, XXE & `<use>` DoS Bombs** | `SvgSanitizer` rejects `<!DOCTYPE`/`<!ENTITY`, enforces a **strict 55-tag allowlist**, caps SVG size at **5 MB**, filters CSS syntax (`@import`, `expression()`, `url(javascript:)`), strips `<script>`/`<foreignObject>`/`on*`/`javascript:`, and bounds `<use>` expansion DoS (max `100` `<use>`, depth `4`, `1000` expanded nodes, `5000` analysis steps, `8` duplicate IDs). |
| 6 | **SSRF & DNS Rebinding (Remote URL Import)** | `RemoteUrlImporter` is disabled by default (`allow_remote_url_import: false`). When enabled, it validates IPs via `inet_pton()` to block loopback, private ranges, `0.0.0.0`, `100.64.0.0/10` CGNAT, cloud metadata (`169.254.169.254`), IPv4-mapped IPv6 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6, and UTS-46 IDNA / full-width / octal / hex IP bypasses, while **`CURLOPT_RESOLVE` IP pinning** eliminates DNS rebinding attacks. |
| 7 | **Image Editor Polyglot RCE** | `ImageEditorController` restricts `overwrite` to existing safe raster files (`jpg/jpeg/png/webp`), and enforces `upload_max_size`, profile `upload_allow`/`upload_deny`, Usage Guard checks, and true `getimagesizefromstring()` validation. |
| 8 | **Decompression (Pixel) Bombs & ImageTragick** | `GdThumbnailDriver` and `ImagickThumbnailDriver` inspect dimensions via `getimagesizefromstring()` before decoding into memory (enforcing max `8192×8192` and `40 MP`), and apply strict Imagick memory, time, and thread limits. |
| 9 | **Symlink Escape & Confinement** | `ConfinedLocalFilesystemAdapter` verifies `is_link()` + `realpath()` root confinement on every path segment for every local read, write, move, copy, and delete; recursive deletes/moves of subtrees containing symlinks are rejected, and directory listings enforce `SKIP_LINKS`. |
| 10 | **Multi-Tenant (`{user_id}`) Collision Prevention** | `DynamicPathResolver` isolates user directories with distinct SHA-256 `<slug>--h-<16-hex>` suffixes for emails, mixed casing, Unicode, reserved names (`guest`, `user`), and Windows device names — making directory collisions between two users impossible. |
| 11 | **Download, Thumbnail & CDN Cache Isolation** | `DownloadController`, `ThumbnailController`, and `ZipDownloadController` emit `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, and `Content-Security-Policy: default-src 'none'; sandbox`; enforce `Cache-Control: private` + `Vary: Cookie, Authorization` in private/Voter/session/`{user_id}` contexts; require `CLEAR_CACHE` for `?refresh=1` and `?fallback=1`; and force `Content-Disposition: attachment` for HTML/JS/SVG/XML. |
| 12 | **FormType POST & Twig Origin Protection** | `FileManagerPickerType` rejects tampered server-side POST payloads (XSS protocols, `../`, `.trash/`, JSON > 256 KB, > 500 items, invalid UTF-8, `max_files` overflow, HPP arrays); `FileManagerTwigExtension` and frontend `isSafeUrl()` prevent external/dangerous URL origin spoofing. |
| 13 | **Arbitrary Theme/Icon Directory & DOM XSS** | Runtime `dialog?theme=` accepts only the 9 built-in themes and `dialog?iconSet=` accepts only the 6 built-in icon sets; `sanitizeHexColor()` prevents color injection; chunk staging directories are isolated per user (`0750`). |
| 14 | **DoS, Log Injection & Cache Isolation** | Bulk delete is capped at 200 items, chunked uploads at 2000 chunks, and recursive folder copies at 5000 items; `AuditLogListener` strips log-injection payloads (`\r`/`\n`/ANSI/control chars); `DirectoryCacheManager` never wipes the host app's `cache.app` pool (never calls `clear()`). |

---

## 5. Web Server Hardening (Apache & Nginx)

As an extra defense layer, always disable PHP and script execution inside publicly served upload directories like `public/uploads` at the web server level:

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. Signed Share Links & Asset Usage Guard

- **Signed, Expiring Share Links (`ShareController`):** When `share.enabled: true`, temporary URLs are signed with Symfony's `UriSigner` (HMAC + `expires` timestamp). Setting `share.enabled: false` on a profile immediately revokes all existing links with `403 Forbidden`, expired links return `410 Gone`, and all responses carry `Cache-Control: private, no-store` plus strict CSP hardening.
- **Asset Usage Guard (`AssetUsageCheckerInterface`):** Prevents deleting, renaming, moving, or overwriting files that are actively referenced in your database (see [10 — Asset Usage Guard](10-asset-usage-guard.md)).

---

## 7. Audit Logging — Production Setup & Examples

`AuditLogListener` records all 9 file/folder mutation events with structured PSR-3 context (`action`, `profile`, `volume`, `path`, `user`, `ip`, plus event-specific metadata) and automatically scrubs control/CRLF/BiDi characters to prevent log injection.

### Step 1: Enable Audit Logging (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Enable or override per profile
        public_temp:
            security:
                audit_log: false
```

### Step 2: Route Audit Entries to a Dedicated Rotating JSON Log (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 90-day retention & rotation
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### Sample Structured JSON Output

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### All 9 Audited Lifecycle Events

| Symfony Event | `action` Key | Extra Context Fields |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### Step 3 (Optional): Persisting Audit Logs to a Doctrine Database Table

To expose a searchable audit trail inside your admin panel, subscribe to the same Symfony events:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> On high-traffic applications, dispatch an async Symfony **Messenger** message instead of calling `$this->em->flush()` inline so file operations stay non-blocking.

---

[← Previous: 05 — FormType & Editor Integrations](05-formtype-and-editor-integrations.md) | [Index](README.md) | [Next: 07 — Cloud Storage & Dynamic Config →](07-cloud-storage-and-dynamic-config.md)
