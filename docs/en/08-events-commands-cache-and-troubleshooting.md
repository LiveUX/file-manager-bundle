# 08 — Events, CLI Commands, Cache, Translations & Troubleshooting (FAQ)

[← Previous: 07 — Cloud Storage & Dynamic Config](07-cloud-storage-and-dynamic-config.md) | [Main Index](README.md) | [Next: 09 — Symfony AI Integration →](09-symfony-ai-integration.md)

> [!TIP]
> **At a Glance: What Are Events and CLI Commands For?**
> - **Events:** Notify your Symfony application automatically whenever a user uploads, deletes, renames, or moves a file—for example, *"when a file is renamed, update the product image path in the database automatically."*
> - **CLI Commands (`php bin/console file-manager:...`):** Built-in terminal tools to run health checks (`doctor`), invalidate caches (`cache:clear`), pre-generate thumbnails (`thumbs:warmup`), or purge expired trash (`trash:purge`).

---

## 1. Symfony Events (9 Domain Events)

Every file manager event extends the common **`AbstractFileManagerEvent`** base class (`$profile`, `$volume`, `$path`, `$canonicalPath`). Listen to these events to keep your database in sync, record audit logs, purge CDN caches, or dispatch background jobs.

| Event Class (`LiveUX\FileManagerBundle\Event\*`) | Dispatched When | Properties |
| :--- | :--- | :--- |
| `FileUploadedEvent` | A file is uploaded, imported from URL, saved by the Image Editor, or extracted from a ZIP archive | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | A file is deleted (moved to trash or permanently deleted) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | A folder is created (including during ZIP extraction) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | A folder is deleted | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | A file or folder is renamed | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | An item is moved or copied (including cross-volume transfers) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | An item is duplicated/cloned | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | An item is restored from the trash | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | One or more items are permanently purged from the trash | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Example Event Listener (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('New media asset uploaded.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Console (CLI) Commands (10 Built-In Commands)

| Command | Flags / Aliases | Description |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Checks PHP version, `upload_max_filesize`, `post_max_size`, cURL CA Bundle, GD/Imagick, EXIF, WebP, `ext-zip`, and every profile/volume configuration. Add `--check-remote` to run live S3/R2/Supabase/MinIO connectivity tests (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Clears directory and quota caches for all profiles or a specific profile/volume/path (`--volume`/`--path` require the `profile` argument; never wipes unrelated keys in `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Aliases: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Pre-generates image thumbnails ahead of time so editors never wait on first load. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Clears cached thumbnails. `--orphans` removes only thumbnails whose source file no longer exists (cannot be combined with `--volume`/`--path`, and automatically protects `{user_id}` / `{Y}/{m}/{d}` shared directories). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Retroactively optimizes existing images (safety/memory cap: `25 MB` max per image). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Detects duplicate files using SHA-256 content hashes and reports reclaimable disk space. |
| `file-manager:stats [profile]` | `--json` | Reports storage usage, file/folder counts, quota utilization, thumbnails, and trash statistics. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(default `50`)*, `--dry-run` | Batch-generates missing `alt` text, titles, captions, and tags using AI. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = purge all)*, `--dry-run` | Permanently deletes expired trash items. When multiple profiles share a physical directory, automatically honors the longest `retention_days` value. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Installs the bundle's 6 AI Agent Skills into `.agents/skills`, `.claude/skills`, `.cursor/skills`, or `.github/skills`. |

> [!NOTE]
> **Dynamic `{user_id}` and `{Y}/{m}/{d}` Protection in CLI Commands**
> Because there is no authenticated user session in CLI mode, all scanning and cleanup commands (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **automatically skip** volumes containing `{user_id}` (preventing accidental creation of an empty `guest/` folder). For volumes with `{Y}/{m}/{d}` date placeholders, only the current date period is scanned and an informational notice is displayed.

### Cron Example for Automatic Trash Cleanup

```cron
# Purge expired trash items every night at 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Performance, Cache Architecture & Symfony Profiler

- **3-Tier Cache TTL & Manual Refresh (`↻`):** Directory listing TTL resolves in order: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` disables caching for that volume or profile). Clicking **Refresh (`↻`)** in the toolbar or pressing `R` sends `?refresh=1` *(bypassing the cache requires the `CLEAR_CACHE` permission)*.
- **Dedicated Cache Pool & Profile Fingerprinting:** Point `defaults.cache.pool` to a dedicated Redis/APCu pool (if `<pool>.taggable` exists, it is used automatically). Without taggable cache, `DirectoryCacheManager` uses 30-day generation counter keys and never calls `$cachePool->clear()` on your main application cache. When two profiles share the same physical folder with different rules (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), their caches stay strictly isolated via `buildProfilePolicyFingerprint()`.
- **Canonical Key Synchronization:** If two profiles point to the same physical directory, uploading or modifying a file in one automatically invalidates the other profile's cache as well.
- **Self-Healing `remember_last_dir`:** If the user's last visited directory was deleted externally (via FTP or an S3 console), the file manager gracefully walks up to the nearest existing parent folder or root instead of failing.
- **HTTP 304 & Context-Aware Thumbnail Caching (`ThumbnailController`):**
  - **Private / Authenticated Context** (`private: true` volume, `{user_id}` dynamic volume, Voter-protected profile, `Authorization` header, or session cookie present): sends `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` to prevent shared CDN leakage.
  - **Public Context:**
    - With `?v=<version>` cache-buster parameter → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 days).
    - Without `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 hour + `ETag` / `304 Not Modified`).
    - When `cache.enabled: false` on the profile → `Cache-Control: public, no-cache, must-revalidate`.
  - Every thumbnail response also sets `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** In the `dev` environment, the **FileManager** profiler panel displays the active profile, volume, thumbnail driver, and cache hit/miss stats.

---

## 4. Global Localization (`493` Keys), BCP-47 / POSIX / RTL & Custom Languages

LiveUX FileManagerBundle ships out of the box with **12 built-in UI languages**—**English (`en` — guaranteed fallback), Turkish (`tr`), French (`fr`), German (`de`), Spanish (`es`), Arabic (`ar`), Italian (`it`), Portuguese (`pt`), Dutch (`nl`), Polish (`pl`), Japanese (`ja`), and Simplified Chinese (`zh_CN`)**—with full parity across all **493 translation keys**.

- **BCP-47 & POSIX `.UTF-8` Support:** `MessageTranslator` automatically resolves regional and POSIX locale strings such as `tr_TR.UTF-8`, `de_DE@euro`, and `zh-Hans-CN` (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` outputs valid BCP-47 codes for HTML `lang` attributes.
- **Automatic Right-to-Left (RTL) Detection:** **15 RTL languages and scripts**—including Arabic (`ar`), Persian (`fa`), Hebrew (`he`), Urdu (`ur`), Pashto (`ps`), and Central Kurdish (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa`, etc.)—are detected automatically, switching the component layout to `dir="rtl"`.
- **Compile-Time Catalogue Allowlist:** `FileManagerBundle` only accepts locales that correspond to real translation catalogues, preventing cache/OPcache bloat (DoS) from arbitrary forged locale parameters.
- **Adding a New Language (e.g., Swedish `sv`) or Partial Overrides:**
  Simply create `translations/LiveUXFileManagerBundle.sv.yaml` in your project (or override specific keys in an existing language like `LiveUXFileManagerBundle.en.yaml`). Any omitted key automatically falls back to English (`en`)—users will never see raw translation keys like `ui.upload`. Copy the bundle's `translations/LiveUXFileManagerBundle.en.yaml` as a complete starting template.

---

## 5. Frequently Asked Questions & Troubleshooting (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Confirm `assets/app.js` includes `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- Verify `defaults.auto_inject_dialog_host: true` is enabled (if you disabled it, manually include `DialogHost.html.twig` in `base.html.twig`).
- Run `php bin/console cache:clear` and perform a hard browser refresh (`Ctrl+Shift+R`).

### 2. Every POST request returns `403 Invalid or missing CSRF token`
- Ensure `symfony/security-csrf` is installed and both `framework.csrf_protection: true` and `framework.session.enabled: true` are active.
- If you issue custom `fetch()` requests directly, include the `X-LiveUX-FM-CSRF` header.

### 3. I get `403 Access denied`
- Check the `permissions` block for your profile in `liveux_file_manager.yaml`. Remember: even setting a permission to `true` requires an authenticated user, and `permissions.view` is required for all other actions.
- If you implemented a custom Symfony Voter, check `access_decision_manager.strategy` (e.g. `unanimous` vs `affirmative`) in `config/packages/security.yaml`.

### 4. Large file uploads fail (`upload_max_filesize`)
- With `chunk_upload.enabled: true`, chunk size is automatically clamped to `80%` of PHP's `upload_max_filesize`/`post_max_size` (e.g. `1.60 MB` chunks on a `2M` PHP limit, up to 2,000 chunks).
- Total allowed file size is controlled by `upload_max_size` in `liveux_file_manager.yaml` (e.g. set `upload_max_size: '100M'` for 100 MB videos).

### 5. Downloading a file breaks the page (Symfony UX Turbo Drive)
- All built-in download links already include `data-turbo="false" download`. If you override Twig templates, make sure your custom download `<a>` tags include `data-turbo="false"`.

### 6. Thumbnails aren't generated; only original images are served
- Run `php bin/console file-manager:doctor` and verify that the `Thumbnail Driver` row shows `gd` or `imagick` as active.
- Check that `thumbnail.cache_dir` (`public/media-cache`) is writable by your web server user.

### 7. `cURL error 60: SSL certificate problem` on Windows when connecting to S3 / Supabase
- Download an up-to-date `cacert.pem` bundle and configure `curl.cainfo` and `openssl.cafile` in `php.ini`, or specify `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` on the S3 volume.

### 8. Frontend changes aren't reflected in the browser
- **Development:** Run `php bin/console cache:clear` and hard-refresh (`Ctrl+Shift+R`).
- **Production (AssetMapper):** Run `php bin/console asset-map:compile`.

---

[← Previous: 07 — Cloud Storage & Dynamic Config](07-cloud-storage-and-dynamic-config.md) | [Main Index](README.md) | [Next: 09 — Symfony AI Integration →](09-symfony-ai-integration.md)
