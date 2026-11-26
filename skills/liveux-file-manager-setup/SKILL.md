---
name: liveux-file-manager-setup
description: >-
  Installs and configures LiveUX FileManagerBundle in Symfony 6.4 LTS/7.2+/8+ apps:
  routing, AssetMapper or Webpack Encore wiring, multi-volume storage (Local,
  AWS S3, Cloudflare R2, MinIO), dynamic per-user paths, storage quotas,
  image optimization, duplicate detection, and UI flags. Use when setting up,
  configuring, or troubleshooting liveux_file_manager.yaml profiles and volumes.
license: MIT
compatibility: PHP >= 8.3, Symfony ^6.4|^7.2|^8.0, symfony/ux-twig-component, symfony/stimulus-bundle
metadata:
  author: liveux
  version: "0.1.0"
---

# LiveUX FileManagerBundle — Setup, Profiles & Volumes

Follow this workflow when installing `liveux/file-manager-bundle` or configuring profiles, volumes, storage quotas, and upload pipelines.

## 1. Installation & Routes

1. Require the bundle (plus optional Flysystem S3 adapter if using cloud volumes):
   ```bash
   composer require liveux/file-manager-bundle
   # Optional for AWS S3 / Cloudflare R2 / MinIO:
   composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
   ```
2. Mount bundle routes in `config/routes/liveux_file_manager.yaml`:
   ```yaml
   liveux_file_manager:
       resource: '@FileManagerBundle/config/routes.php'
   ```
3. CSS & JS are loaded **zero-config** via Symfony UX `autoimport` in `assets/controllers.json` (`dialog` eagerly auto-imports pre-minified `assets/dist/styles/core.css` and `file_manager_api.js` for both AssetMapper and Webpack Encore — no manual imports needed in `assets/app.js`).
   - `DialogHost` is auto-injected before `</body>` on full HTML responses (`defaults.auto_inject_dialog_host: true`). Do NOT manually duplicate `{% include '@LiveUXFileManager/components/DialogHost.html.twig' %}` unless you disable auto-injection.

## 2. Configuring Volumes & Profiles

Create `config/packages/liveux_file_manager.yaml`. Always verify exact YAML key names against [references/config-schema.md](references/config-schema.md) before editing.

### Multi-Volume Example (Local + Cloud S3/R2 + Per-User Dynamic Path)

```yaml
liveux_file_manager:
    defaults:
        theme: 'bootstrap5'          # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: 'en'                 # 12 built-in: en | tr | fr | de | es | ar (RTL) | it | pt | nl | pl | ja | zh_CN
        color_scheme: 'auto'         # auto | light | dark
        z_index: 1050
        auto_inject_dialog_host: true

    volumes:
        uploads:
            label: 'Public Uploads'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
        private_docs:
            label: 'Private Documents'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/var/private-uploads'
            private: true            # Streamed through DownloadController with private, no-store headers
        s3_media:
            label: 'Cloud Media (R2/S3/B2)'
            group: 'network'
            driver: 'AwsS3'          # Or 'FlysystemService' with driver_options.service pointing to a Flysystem service
            url_prefix: '%env(CDN_BASE_URL)%'
            driver_options:
                bucket: '%env(S3_BUCKET)%'
                region: 'auto'
                endpoint: '%env(S3_ENDPOINT)%'
                key: '%env(S3_KEY)%'
                secret: '%env(S3_SECRET)%'
                prefix: 'media'
                use_path_style_endpoint: true

    profiles:
        default:
            volumes: ['uploads']
            upload_max_size: '25M'
            storage_quota: '2G'      # Enforced server-side even if ui.show_quota is false
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 2400
                max_height: 2400
                convert_to_webp: false
                quality: 85
            duplicate_detection:
                enabled: true
                policy: 'warn'       # warn | reject | reuse
            ui:
                show_quota: true
                show_quota_details: true
                show_folder_item_count: true
                folder_count_mode: 'recursive_files' # recursive_files | files | items

        user_space:
            path: '%kernel.project_dir%/var/private-uploads/users/{user_id}'
            private: true
            upload_subfolder: '{Y}/{m}'
            storage_quota: '250M'
            ui:
                show_quota: true
                show_quota_details: false  # Show passive bar without clickable breakdown modal
```

## 3. Rendering Inline Browser

```twig
<twig:LiveUX:FileManager:Browser profile="default" />
```

## 4. Common Pitfalls (Do NOT Do These)

- **Wrong YAML keys:** Use `thumbnail:` (singular, NOT `thumbnails:`), `security.voter_enabled` (NOT `security.voter`), `security.csrf_protection` (NOT `security.csrf`), and top-level `ignore_dot_files` (NOT under `security:`).
- **AssetMapper dev cache trap:** If `public/assets/` already exists in the host project, changes to bundle CSS/JS will not appear in the browser until you run `php bin/console asset-map:compile` (or delete `public/assets/`).
- **Private files exposed in `public/`:** Never place `private: true` volumes inside `public/`; always store them under `var/` so direct web server URLs cannot bypass Symfony Voter checks.
- **Hidden quota still enforces limits:** Setting `ui.show_quota: false` hides UI bars and omits quota data from `/api/list`, while `storage_quota` continues blocking over-quota uploads on the server.

## 5. Verification

Run after configuring:
```bash
php bin/console file-manager:doctor
php bin/console lint:yaml config/packages/liveux_file_manager.yaml
php bin/console debug:router | grep liveux_file_manager
```

For the full verified YAML schema and all options, read [references/config-schema.md](references/config-schema.md).
