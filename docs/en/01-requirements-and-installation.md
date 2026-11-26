# 01 — Requirements, Dependencies & Installation

[← Documentation Index](README.md) | [Next: 02 — Configuration & Profiles →](02-configuration-and-profiles.md)

This guide walks you through installing LiveUX FileManagerBundle from scratch—explaining **every step and why it matters**, even if this is your very first Symfony UX package.

> [!NOTE]
> **In plain English: What does this bundle do?**  
> It adds a full-featured file manager (similar to Google Drive or Windows Explorer) to your Symfony application—whether that's an admin panel, e-commerce store, or customer portal. Users can **upload files, organize folders, crop images, preview PDFs and videos, and pick files directly from forms** with a single click.  
> It requires **no CSS framework** (Bootstrap, Tailwind, etc.) and **no jQuery**, and with Symfony AssetMapper it works in 5 minutes without Node.js or npm.

---

## 1. System Requirements

Before installing, verify that your local machine or server meets these minimums:

| Component | Minimum Version | Why It Is Needed | Check Command |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` or higher | Uses PHP 8.3/8.4 features (`readonly` classes, typed class constants). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+`, or `8.x` | Fully tested and supported on Symfony 6.4 LTS, 7.2, 7.3, 7.4, and 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Package installation and PSR-4 autoloading. | `composer --version` |
| **Browser** | Modern evergreen browser | Native HTML5 `<dialog>` Top Layer, ES Modules, and CSS custom properties (Chrome, Edge, Firefox, Safari). | — |

---

## 2. PHP Extensions

PHP extensions give PHP extra native capabilities such as file inspection, XML parsing, and image processing.

### Required PHP Extensions
Enabled by default in almost every modern PHP installation:

| Extension | What It Does | Real-World Example |
| :--- | :--- | :--- |
| `ext-fileinfo` | Inspects the **actual file content (magic bytes)**, not just the file extension. | If an attacker renames `shell.php` to `photo.jpg` and tries to upload it, `ext-fileinfo` catches and rejects it immediately. |
| `ext-dom` & `ext-libxml` | Safely parses uploaded `.svg` XML trees (`SvgSanitizer`). | Strips malicious `<script>` tags or XXE payloads hidden inside an uploaded SVG logo while preserving the clean graphic. |

*(Note: `ext-json` has been built into PHP core since PHP 8.0 and does not need separate installation.)*

### Optional (Recommended) PHP Extensions
If any of these are missing, the file manager **continues to work** and automatically falls back gracefully:

| Extension | What It Does | Fallback Behavior When Missing |
| :--- | :--- | :--- |
| `ext-gd` **or** `ext-imagick` | Generates fast WebP/JPEG thumbnails and runs automatic upload-time image optimization. | Falls back to showing the original image or file icon according to `thumbnail.on_missing_driver`. |
| `ext-exif` | Reads smartphone EXIF orientation metadata when using `ext-gd`, automatically rotating sideways photos (`auto_orient: true`). | Photos are saved in their original orientation (not required if `ext-imagick` is installed). |
| `ext-zip` (`ZipArchive`) | Enables multi-file `.zip` downloads **and** safe archive extraction (`zip.allow_extract: true`). | The **Download ZIP** and **Extract ZIP** buttons are hidden automatically. |

Check which extensions are active on your machine with a single command:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Required & Optional Composer Packages

When you run `composer require liveux/file-manager-bundle`, **all required packages are installed automatically**. Knowing what each dependency handles makes debugging much easier:

### Required Packages (Installed Automatically)
| Package | Version Constraint | Purpose |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Unified filesystem abstraction across local disks, AWS S3, Cloudflare R2, Supabase, MinIO, and SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Renders the file manager via `{{ component('LiveUX:FileManager:Browser') }}` with 0ms SSR. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Powers client-side directory caching, drag-and-drop, context menus, Lightbox, and form pickers. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Core Symfony runtime, routing, and cache pools (`cache.app`). |
| `symfony/config` & `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | `liveux_file_manager.yaml` schema validation and service container wiring. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Dispatches 9 domain events (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent`, etc.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Secure file downloads, HTTP 206 video streaming (`Range`), ETags, and HTTP cache headers. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Twig templates, icon/translation Twig helpers, and automatic FormType theming. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Single-file and multi-file gallery `FileManagerPickerType` form field. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Server-side MIME type detection for uploaded files. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Transliterates multilingual filenames (Turkish, French, Arabic, Cyrillic, etc.) into clean SEO slugs. |
| `symfony/security-core` & `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | 17-permission Voter RBAC and `X-LiveUX-FM-CSRF` protection. |
| `symfony/cache-contracts` & `symfony/translation-contracts` | `^3.5` | Directory caching and multilingual UI contracts. |

### Optional Packages (Install Only When Needed)
| Package | Install Command | When You Need It |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Zero-build JS and CSS delivery without Node.js or npm *(Recommended)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Role-based permissions (`ROLE_USER` / `ROLE_ADMIN`) and per-user `{user_id}` isolation. |
| `symfony/translation` | `composer require symfony/translation` | Integrates with Symfony's Translator across all **12 built-in UI languages** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) or custom app locales. |
| `symfony/console` & `symfony/yaml` | `composer require symfony/console symfony/yaml` | CLI commands (`file-manager:doctor`, `thumbs:warmup`, `trash:purge`, etc.) and YAML configuration files. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Connecting AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, or Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Multi-server / load-balanced deployments using `defaults.metadata.driver: 'doctrine'` (see [Chapter 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Built-in `FileManagerField` for EasyAdmin 4/5 dashboards (see [Chapter 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ Suggest with AI** automatic alt-text, caption, and tag generation (see [Chapter 09](09-symfony-ai-integration.md)). |

---

## 4. Step-by-Step Installation

Follow these 7 steps in order to get a working file manager up and running.

### Step 1: Install the Bundle

**Standard installation (via Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Local path repository (if developing inside `bundles/liveux-file-manager-bundle`):**  
Add a `repositories` entry to your root `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require the local package:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Step 2: Verify `config/bundles.php`

Symfony Flex normally registers the bundle automatically. If not, confirm these entries exist in `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // If using authentication/roles
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **automatically registers** its Twig namespace (`@LiveUXFileManager`), AssetMapper path (`@liveux/file-manager-bundle`), and FormType theme (`@LiveUXFileManager/form/picker.html.twig`) at boot time. You do not need to edit `twig.yaml` or `asset_mapper.yaml`.

---

### Step 3: Import the Routes

Create `config/routes/liveux_file_manager.yaml` so the bundle's AJAX API, download, thumbnail, and upload endpoints are active:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

This single import registers **12 secured endpoints**:

| Method & Path | Purpose |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Loads the file manager HTML inside a Modal or OffCanvas dialog. |
| `GET /_liveux-file-manager/api/list` | Returns a JSON listing of files and subfolders for a directory. |
| `GET /_liveux-file-manager/api/tree` | Fetches the complete folder tree for the sidebar in one request. |
| `GET /_liveux-file-manager/api/trash` | Lists soft-deleted items in the trash bin. |
| `GET /_liveux-file-manager/api/preview-text` | Returns a safe (64 KB capped) text/code preview for `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, etc. |
| `POST /_liveux-file-manager/api/action` | Executes mutations: create folder, move, copy, rename, delete, restore/purge trash, folder colors/icons, metadata, AI enrichment, remote URL import, and ZIP extraction. |
| `POST /_liveux-file-manager/upload` | Handles both standard and chunked file uploads. |
| `POST /_liveux-file-manager/image-edit` | Saves cropped, resized, or rotated images from the built-in Canvas Image Editor. |
| `GET /_liveux-file-manager/download` | Serves secure downloads, PDF/video inline previews (with `Range` support), and `private: true` volumes. |
| `GET /_liveux-file-manager/share` | Serves time-limited, HMAC-signed share links. |
| `POST /_liveux-file-manager/zip` | Streams selected files and folders as a single `.zip` archive. |
| `GET /_liveux-file-manager/thumb` | Generates and serves WebP/JPEG/PNG thumbnails with ETag and context-aware HTTP cache headers. |

---

### Step 4: Create `config/packages/liveux_file_manager.yaml`

Create `config/packages/liveux_file_manager.yaml`. The starter config below explicitly lists **all 17 granular permissions**:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = automatically inherits Symfony's active locale (always falls back to 'en')
                            # Or pin a specific locale such as 'en', 'tr', 'fr', 'de', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'My Files'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # ZIP extraction is disabled by default (also requires zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Critical Security Note (`permissions`):**  
> Any key you **omit** under `permissions` (except `extract_zip`) defaults to `true`. Here, `true` means **"any authenticated user"**—NOT anonymous visitors. If only `ROLE_ADMIN` should permanently purge trash (`trash_purge`) or create share links (`share`), set those keys explicitly to `'ROLE_ADMIN'`.

> [!TIP]
> **Testing locally without authentication yet?**  
> While prototyping locally without a login system, you can temporarily set `profiles.default.security.voter_enabled: false`. **NEVER leave `voter_enabled: false` in production**—see [06 — Security & Authorization](06-security-and-authorization.md).

> [!NOTE]
> **12 Built-In UI Languages & Automatic RTL (`defaults.locale: null`):**  
> Leaving `locale: null` lets the file manager automatically follow Symfony's active request locale across all **12 built-in UI languages** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), automatically enables **RTL (`dir="rtl"`)** for Arabic and other RTL locales (Persian, Hebrew, Urdu, etc.), normalizes BCP-47 and POSIX locales (`de_DE.UTF-8`, `zh-Hans-CN`), and cleanly falls back to **English (`en`)** for unsupported locales.

---

### Step 5: Connect CSS & JavaScript (Zero Configuration!)

**Not sure whether your project uses AssetMapper or Webpack Encore?**  
Check your project root:
- If you see `importmap.php` → follow **Option A (AssetMapper)**.
- If you see `webpack.config.js` → follow **Option B (Webpack Encore)**.

> [!TIP]
> **Zero-Configuration `autoimport` Support:**  
> LiveUX FileManagerBundle declares **`autoimport`** in its `assets/package.json` for `@liveux/file-manager-bundle/styles/core.css` and the eager `dialog_controller.js` (which loads `file_manager_api.js`). Once `assets/controllers.json` is enabled, **you don't need a single manual `import` statement in `assets/app.js`!**

#### Option A: Symfony AssetMapper (Recommended — No Node.js / npm Required)

1. Confirm that the bundle's controllers are enabled in `assets/controllers.json` (Symfony Flex adds this automatically; otherwise add it):
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Symfony 6.4 / 7.2+ / 8.x projects already call `startStimulusApp()` inside `assets/bootstrap.js` or `assets/stimulus_bootstrap.js`, which reads `controllers.json` automatically:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) or assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Make sure your base layout (`templates/base.html.twig`) includes `importmap('app')`:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Optional — when deploying to production)* Compile static assets:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Pre-Minified Production Assets (`assets/dist/`):**  
> Because AssetMapper does not minify JS/CSS by default, LiveUX FileManagerBundle ships pre-minified JavaScript modules and `core.css` built with `esbuild` under `assets/dist/` (`747 KB → 410 KB` minified; `~93 KB` over Gzip, or **`~77 KB`** over Brotli). `FileManagerBundle` automatically maps `assets/dist/` into AssetMapper, so **neither your workstation nor your production server needs Node.js or npm**.  
> On Symfony 7.3+ / 8.x, you can also enable `precompress: true` in `config/packages/asset_mapper.yaml` to generate `.br` and `.gz` files automatically during `asset-map:compile`.

#### Option B: Webpack Encore

1. For Webpack Encore projects (including Symfony 8 + StimulusBundle 3.x), make sure `@symfony/stimulus-bridge` is installed:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Verify the 3 Stimulus controllers and `autoimport` block in `assets/controllers.json`:
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. Verify `startStimulusApp` is wired in `assets/bootstrap.js` (`assets/app.js` imports it—no manual CSS/JS imports needed):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Build your assets:
```bash
npm run build
# Or watch continuously during development: npm run watch
```

---

### Step 6: `DialogHost` (Automatic Injection — No Extra Markup Required!)

Opening modals, OffCanvas drawers, `FileManagerPickerType`, or TinyMCE/CKEditor/Tiptap pickers from any page requires a `<dialog>` container in the DOM.

The bundle's `AutoInjectDialogHostSubscriber` automatically injects this host right before `</body>` on full HTML responses (`defaults.auto_inject_dialog_host: true`)—so you don't need to edit `base.html.twig`.

- **Smart Detection:** Never touches JSON, AJAX, Turbo Frame, redirect, or binary download responses, and skips injection if a manual `DialogHost` is already present.
- **0ms Page-Load Overhead:** Does not query directories or folder trees on initial page load; data is only loaded when a user opens the dialog.
- **Prefer Manual Placement on Specific Pages?** Set `defaults.auto_inject_dialog_host: false` and include the snippet just before `</body>` in the layouts you choose:

```twig
{# Optional — only needed when auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Step 7: Verify Your Installation (`file-manager:doctor`)

Clear the cache and run the built-in diagnostics command:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

If you have configured cloud volumes (S3, R2, Supabase, MinIO), add `--check-remote` to test live connectivity:
```bash
php bin/console file-manager:doctor --check-remote
```

Example healthy output (9 environment checks + profile/volume status table):
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Windows Users (`cURL error 60`):** If `cURL CA Bundle` shows a warning, point `curl.cainfo` and `openssl.cafile` in `php.ini` to an up-to-date `cacert.pem`, or configure `driver_options.ca_bundle` on your S3 volume.
>
> **Linux File Permissions:** Ensure `public/uploads`, `public/media-cache`, and `var/file-manager` are writable by your web server user (`www-data`, `nginx`, etc.).
>
> **Using an AI Coding Assistant (Antigravity, Claude Code, Cursor, Copilot)?**  
> Teach your assistant all bundle conventions and recipes in a single command (see [Chapter 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Build Your First Working Page

Let's create a simple Symfony Controller and Twig template to verify everything end to end.

### 1) Controller (`src/Controller/MediaController.php`)
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Twig Template (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Media Library</h1>

    {# Option 1: Inline File Manager #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Option 2: Open a Modal from any button and capture the selected files #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Pick Files in Modal</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // User closed the dialog without selecting anything
            }

            document.getElementById('selection-result').textContent =
                'Selected: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Open `/admin/media` in your browser. Congratulations—your file manager is live!

---

## 6. What's Next?

- Configure custom profiles, upload limits, and image rules → **[02 — Configuration & Profiles](02-configuration-and-profiles.md)**
- Add an image or gallery picker to Symfony Forms → **[05 — FormType & Editor Integrations](05-formtype-and-editor-integrations.md)**
- Harden RBAC and security before going to production → **[06 — Security & Authorization](06-security-and-authorization.md)**

---

[← Documentation Index](README.md) | [Next: 02 — Configuration & Profiles →](02-configuration-and-profiles.md)
