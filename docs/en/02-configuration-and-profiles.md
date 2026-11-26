# 02 — Configuration & Profiles (Full Parameter Reference & Recipes)

[← Previous: 01 — Requirements & Installation](01-requirements-and-installation.md) | [Documentation Index](README.md) | [Next: 03 — Usage Modes & JS API →](03-usage-modes-and-js-api.md)

This guide explains **every option** in `config/packages/liveux_file_manager.yaml`, its default value, when to customize it, and **5 copy-and-paste production recipes** you can drop straight into your project.

---

## Mental Model in 30 Seconds: `defaults`, `volumes`, and `profiles`

Think of the configuration file in **3 clean layers**:

1. **`defaults` (Global Defaults):** Shared baseline inherited by every profile (theme, locale, color scheme, cache, metadata driver, audit logging, AI defaults). Unless overridden on a specific profile, these values apply everywhere.
2. **`volumes` (Storage Disks / Buckets):** Defines **where files physically live** (local directory, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage, or a custom Flysystem service).
3. **`profiles` (Usage Profiles):** Defines the **rules, UI layout, and permissions** for a specific screen or workflow. For example:
   - `product_catalog` allows only `1:1` square product images and converts them to WebP automatically;
   - `customer_docs` connects to a private Supabase S3 bucket, accepts only PDFs, and isolates each user inside their own folder;
   - `editor_picker` opens a streamlined modal picker for TinyMCE with double-click selection.

---

## 1. `defaults` — Global Defaults

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = inherits Symfony's active locale (falls back to 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parameter | Default | What It Does | When to Change It |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Default root directory for shorthand profiles that define `path` inline without `volumes`. | When storing local files in another folder such as `public/media`. |
| `theme` | `'bootstrap5'` | Default UI theme for all profiles (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`, or your custom theme name). | To match your app's design system (e.g., `'shadcn'` or `'tailwind'` in Tailwind projects). |
| `locale` | `null` | When `null`, automatically inherits Symfony's active request/translator locale across all **12 built-in UI languages** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), normalizes BCP-47 & POSIX formats (`de_DE.UTF-8`, `zh-Hans-CN`), enables RTL (`dir="rtl"`) automatically for Arabic and other RTL scripts, and falls back to `'en'` for unknown locales. | Set explicitly (e.g., `'en'`, `'de'`, `'fr'`) to pin all users to a single UI language. |
| `color_scheme` | `'auto'` | Color mode: `'auto'` (detects `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (always light), or `'dark'` (always dark). | When forcing a light-only or dark-only admin theme. |
| `z_index` | `1050` | Base stacking context (`--liveux-fm-z-base`) for modals, OffCanvas drawers, Lightbox, and context menus (`>= 1`). | Raise to e.g. `10050` if your admin layout navbar uses `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | Automatically appends the `<dialog>` host required by `window.LiveUXFileManager.open()` before `</body>` on full HTML pages (no edits to `base.html.twig` needed). | Set to `false` if you prefer manually including `@LiveUXFileManager/components/DialogHost.html.twig` only on specific layouts. |
| `dialog_host_profile` | `'default'` | Default profile used by the auto-injected `DialogHost` when no profile is passed. | When your primary profile isn't named `default` (e.g., `admin_media`). |
| `cache.enabled` | `true` | Server-side PSR-6 cache for directory listings and folder trees. | Set to `false` during local debugging (or disable per profile/volume). |
| `cache.pool` | `'cache.app'` | Symfony PSR-6 cache pool (Redis, Filesystem, APCu, etc.). Automatically uses `<pool>.taggable` when available for surgical tag invalidation. | To isolate file manager cache entries in a dedicated Redis pool. |
| `cache.ttl` | `3600` | Cache lifetime in seconds (default: 1 hour). Mutations (upload, delete, move, rename) automatically invalidate affected directories. | Lower to `60`–`300` when files are frequently modified externally via FTP or cloud dashboards. |
| `metadata.enabled` | `true` | Stores folder colors/icons/pins and file SEO metadata (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Set to `false` for bare-bones file pickers that don't need editorial metadata. |
| `metadata.driver` | `'json_file'` | Metadata backend: `'json_file'` (local file for single-server apps), `'doctrine'` (built-in DBAL storage for multi-server / load-balanced apps on MySQL, MariaDB, PostgreSQL, or SQLite), or `'none'`. | Switch to `'doctrine'` whenever running multiple app replicas (Kubernetes, load balancer, Docker Swarm)—see [Chapter 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Storage path for the `json_file` driver. A companion trash sidecar file (`trash-meta.json` or `<name>.trash-meta.json`) is stored in the same directory for MIME-restricted cloud buckets. | Move to a shared NFS/volume path if needed. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | DBAL connection name, EntityManager, table name (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`), and auto-table creation flag when `driver: 'doctrine'`. | Set `auto_create_table: false` in production when managing schema via Doctrine Migrations (see [Chapter 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Writes structured PSR-3 audit records (`user`, `ip`, `path`, `volume`) for all 9 file/folder mutations (upload, delete, move, copy, rename, clone, create folder, trash restore, trash purge). | Enable (`true`) in compliance-sensitive environments where you need to trace *"who deleted what, and when?"*. |
| `audit_log.level` | `'info'` | PSR-3 log level for audit entries (`debug`, `info`, `notice`, `warning`). | Adjust to match your Monolog routing/filtering rules. |
| `ai.default_model` | `'gpt-4o-mini'` | Default vision/text model used when optional `symfony/ai-bundle` is installed (see [Chapter 09](09-symfony-ai-integration.md)). | Switch to Gemini, Claude, or a local Ollama model. |
| `ai.platform_service` | `null` | Custom Symfony AI `PlatformInterface` service ID (`null` uses the default auto-registered platform). | When your app defines multiple Symfony AI platforms. |

---

## 2. `volumes` — Storage Volumes (Local & Cloud)

A single profile can mount multiple volumes simultaneously (for example, both Local Disk and Supabase/R2/S3). Users can drag and drop files directly from a local folder into a cloud bucket.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Local Disk'
            icon: 'disk'              # Optional (auto-detected from driver/endpoint when omitted)
            group: 'local'            # Optional (null -> 'local' for LocalFileSystem, 'network' for S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # Optional per-volume TTL (0 = disabled)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Also auto-detected from the endpoint
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # External cloud dashboard edits appear within 60 seconds
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parameter | Default | What It Does | When to Change It |
| :--- | :--- | :--- | :--- |
| `label` | Volume key | Display name shown in the sidebar, breadcrumbs, and volume switcher. | Set a human-friendly title (e.g., `'Product Media CDN'`). |
| `icon` | `null` *(auto)* | Sidebar SVG icon. When `null`, auto-detected from `driver` and `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Point to a custom `<dir>/ui/my_icon.svg` in your icon set. |
| `group` | `null` *(auto)* | Sidebar section group. When `null`, `LocalFileSystem` defaults to `'local'` and `AwsS3` / `FlysystemService` default to `'network'`. | Override if you want an NFS mount grouped under `'network'` or a cloud disk under `'local'`. |
| `driver` | `'LocalFileSystem'` | Storage backend: local filesystem (`LocalFileSystem`), S3-compatible object storage (`AwsS3`), or an existing Flysystem service (`FlysystemService`). | Use `'AwsS3'` for S3/R2/Supabase/B2/MinIO/GCS, or `'FlysystemService'` for SFTP/Azure/Dropbox. |
| `path` | `null` | Physical directory path for `LocalFileSystem` (supports `{user_id}`, `{Y}`, `{m}`, `{d}`). | Set a custom root or per-user isolation path (`.../users/{user_id}`). |
| `url_prefix` | `null` | Public URL prefix used by browsers to access public files (`/uploads` or `https://cdn.example.com`). Ignored when `private: true`. | Configure when serving assets through a CDN or public S3/R2/Supabase bucket. |
| `private` | `false` | When `true`, files are never exposed via direct public URLs—all downloads, previews, and thumbnails stream through Symfony Voter checks (`DownloadController`). | **Always set `true`** for invoices, IDs, contracts, or private customer files. |
| `cache_ttl` | `null` *(inherited)* | Cache TTL (in seconds) scoped to this volume. `0` disables caching for this volume; `null` inherits from profile/defaults. | Keep local disk cached for `3600`s while capping a cloud bucket at `60`s or `0`. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(temporary STS session token)*, `version` *(default `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(auto `true` for custom endpoints; default `false` on standard AWS S3—set `true` explicitly for Bucket owner enforced)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | See [Chapter 07](07-cloud-storage-and-dynamic-config.md) for provider-specific guides. |

---

## 3. `profiles` — Profile Parameter Dictionary

### 3.1. Core Storage, Filtering & Quota
| Parameter | Default | What It Does | When to Change It |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Overrides UI and error-message locale for this profile (`'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, etc.; `null` inherits `defaults.locale` → Symfony request locale → `'en'`). | When different panels in the same app target different languages. |
| `volumes` | `[]` | Ordered list of volume keys accessible in this profile (e.g., `['local_uploads', 'supabase_s3']`). The first volume opens by default. | To attach one or more local/cloud volumes. |
| `ignore_dot_files` | `true` | Hides dotfiles (`.env`, `.htaccess`, `.DS_Store`, `.trash`) from listings and blocks direct access to them. | Keep `true` for security. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Hides and blocks direct access to files or folders matching regex or wildcard patterns. | Hide internal folders such as `/^cache$/i` or `/^backups$/i`. |
| `sort_by` | `'name'` | Default sort column: `'name'`, `'date'`, `'size'`, or `'type'`. | Use `'date'` + `sort_direction: 'desc'` to surface newest uploads first. |
| `sort_direction` | `'asc'` | Sort order: `'asc'` (A→Z / smallest first) or `'desc'` (Z→A / newest first). | `'desc'` works great for media galleries and editorial libraries. |
| `folders_first` | `true` | Always lists folders above files. | Set `false` for flat alphabetical or chronological sorting. |
| `storage_quota` | `null` | Maximum total disk usage allowed for the profile (e.g., `'500M'`, `'2G'`). Once reached, uploads are rejected server-side and a usage bar appears in the sidebar. | Essential for multi-tenant SaaS or per-user (`{user_id}`) quotas. |
| `upload_subfolder` | `null` | Dynamic subfolder template where uploaded files are automatically placed under the current directory (e.g., `'{Y}/{m}'`). | Automatically organize uploads into date folders like `2026/11`. |

### 3.2. Uploads, Chunked Uploads (`chunk_upload`) & Duplicate Detection (`duplicate_detection`)
| Parameter | Default | What It Does | When to Change It |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(all safe)* | Allowlist of accepted MIME types (e.g., `['image/jpeg', 'image/png', 'image/webp']`). When empty, all safe types not in `upload_deny` are allowed. | Always restrict to specific MIME types for image-only or PDF-only forms. |
| `upload_deny` | **47 dangerous MIME types** | Strictly blocked MIME types (PHP, HTML, non-SVG XML, JS, Shell, EXE, Java, CGI, etc.—validated server-side via magic bytes). | Add extra blocked MIME types if your policy requires it. |
| `upload_max_size` | `'10M'` | Maximum **assembled** size of a single uploaded file (e.g., `'200M'`, `'1G'`). With chunked upload enabled, files far larger than PHP's `2M` limit upload smoothly up to this cap. | Raise to `'200M'`–`'500M'` for video/catalog PDF profiles, or lower to `'2M'` for avatars. |
| `overwrite_policy` | `'rename'` | Collision behavior when a file with the same name exists: `'rename'` (`photo-1.jpg`), `'overwrite'`, or `'reject'`. | Keep `'rename'` to prevent accidental data loss. |
| `chunk_upload.enabled` | `true` | Splits large uploads into small chunks with Pause / Resume / Confirmed Cancel and automatic retry on transient network errors. | Rarely disabled—only if you strictly upload tiny files (< 1 MB). |
| `chunk_upload.chunk_size` | `'2M'` | Target chunk size. Automatically clamped to **80%** of PHP's `upload_max_filesize` and `post_max_size` (e.g., 1.60 MB on a 2M PHP limit). | Increase to `'5M'` on servers with high PHP limits (e.g., 32M+) for faster large uploads. |
| `chunk_upload.parallel_uploads` | `3` | Maximum concurrent file uploads (`1`–`10`). | Use `2` on low-resource servers, or `4`–`5` on fast cloud/CDN links. |
| `chunk_upload.max_retries` | `3` | Exponential-backoff retries per chunk when a network drop occurs (`0`–`10`). | `3`–`5` works well for mobile or flaky networks. |
| `duplicate_detection.enabled` | `false` | Detects duplicate file uploads via SHA-256 content hashing. | Enable (`true`) to stop editors from uploading the same product photo repeatedly. |
| `duplicate_detection.policy` | `'warn'` | Action on duplicate detection: `'warn'` (upload & warn), `'reject'` (block upload), or `'reuse'` (return the existing file without duplicating storage). | `'reuse'` delivers significant storage savings in e-commerce and CMS sites. |
| `duplicate_detection.max_scan_files` | `5000` | Upper bound on files indexed for duplicate detection (`100`–`50000`) to prevent CPU/I/O spikes on huge archives. | Raise to `10000`–`25000` for large media libraries. |

### 3.3. Automatic Filename Normalization (`naming`)
| Parameter | Default | What It Does | Example (`"Product Photo 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Transliterates accented/Unicode characters and spaces into clean URL-friendly ASCII slugs. | `product-photo-2026.jpg` |
| `naming.slugger_locale` | `null` | Transliteration locale (`null` inherits the profile locale; e.g., `'de'` maps `ä/ö/ü/ß`, `'tr'` maps `İ/ı/ş/ğ/ç/ö/ü`). | Set to your content language (e.g., `'de'`, `'tr'`, `'fr'`). |
| `naming.transformer` | `'none'` | Case/naming transform: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'`, or `'folder'` (names the file after its parent folder!). | `'snake'` → `product_photo_2026.jpg`, `'folder'` → `tumblers-1.jpg` |
| `naming.prepend` / `append` | `''` | Prepends or appends static text or dynamic tokens (`{Y}`, `{m}`, `{d}`, `{uniqid}`). | `prepend: '{Y}{m}_'` → `202611_product.jpg` |
| `naming.apply_on_rename` | `true` | Also applies `slugger` and `transformer` rules when a user renames a file in the UI. | Keep `true` to enforce clean filenames after renames. |
| `naming.apply_affixes_on_rename` | `false` | Prevents `prepend`/`append` tokens from being duplicated every time a file is renamed. | Keep `false`. |
| `naming.allow_extension_change` | `false` | Blocks users from changing extensions (e.g., `.jpg` → `.php` or `.png`) during rename. | Keep `false` for security. |

### 3.4. Image Optimization (`image_optimization`) & Constraints (`image_constraints`)
- **`image_optimization`:** Automatically fixes orientation, strips EXIF metadata, resizes, and compresses large photos on the server during upload.
- **`image_constraints`:** Rejects non-conforming images **both in 0ms in the browser and on the server** (for example, blocking blurry low-res uploads or non-square product photos).

| Parameter | Default | What It Does | When to Use It |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Enables upload-time image optimization (Imagick → GD → silent no-op if neither is available). | Enable (`true`) on any profile where users upload raw smartphone photos. |
| `image_optimization.auto_orient` | `true` | Reads EXIF orientation tags and rotates sideways phone photos automatically. | Keep `true` for mobile uploads. |
| `image_optimization.strip_exif` | `true` | Strips GPS coordinates and camera EXIF metadata (GDPR privacy + smaller file sizes). | Keep `true` for all public-facing images. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Downscales images exceeding these bounds while preserving aspect ratio (`>= 100`). | `1920`×`1920` is a sweet spot for web imagery. |
| `image_optimization.convert_to_webp` | `false` | Automatically converts uploaded JPEG/PNG files to WebP (30–60% smaller files). | Enable (`true`) for modern web performance profiles. |
| `image_optimization.quality` | `85` | Output compression quality (`10`–`100`). | `80`–`85` gives the best size-to-quality balance. |
| `image_constraints.min_width` / `min_height` | `null` | Minimum required dimensions in pixels. | Prevent blurry uploads (e.g., blog covers at least `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Maximum allowed dimensions in pixels (checked *after* `image_optimization` downscaling when optimization is enabled). | Enforce strict banner/print template dimensions. |
| `image_constraints.aspect_ratio` | `null` | Required aspect ratio: `'1:1'` (square), `'16:9'` (widescreen banner), `'4:3'`, `'3:4'`, `'1.91:1'`, etc. | Square e-commerce product cards (`'1:1'`) or video/article headers (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Allowed deviation from the target ratio (`0.0`–`0.5`; `0.05` = 5% tolerance, so both `1920×1080` and `1280×722` pass). | `0.05` works well for almost every case. |

### 3.5. Thumbnails, Trash (`trash`), ZIP (`zip`) & Signed Share Links (`share`)
| Parameter | Default | What It Does |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Generates cached thumbnails for images: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'`, or `'none'`. Cloud images are fetched once and cached locally. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Behavior when neither GD nor Imagick is installed: `'fallback_original'` (show original image), `'icon'` (show file icon), or `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Thumbnail dimensions (`>= 32`), output format (`webp`, `jpeg`, `png`), and fit mode (`contain` = full image visible, `cover` = center-cropped fill). Consider `480`×`360` when using `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | Adds native `loading="lazy"` to grid card images for faster initial rendering in large folders. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Local directory where generated thumbnails are cached. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Soft-deletes files into a trash folder instead of destroying them immediately. Automatically falls back to a local `trash-meta.json` sidecar on MIME-restricted cloud buckets. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Enables one-click multi-file/folder `.zip` downloads (requires PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | 7-layer Zip-Slip & Zip-Bomb protected archive extraction. Always blocked while `allow_extract: false`; enabling it automatically defaults `permissions.extract_zip` to `true` (which you can restrict to `'ROLE_ADMIN'` or `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Generates time-limited HMAC-signed share URLs via Symfony's `UriSigner`. Setting `enabled: false` immediately invalidates all previously issued share links. |

### 3.6. User Interface (`ui`) — Layout, Sizing & Toolbar Flags
| Parameter | Default | What It Does | When to Change It |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Profile-specific theme (e.g., `'bootstrap5'` in admin, `'shadcn'` in customer portal). | When different sections of an app use different visual themes. |
| `ui.color_scheme` | `null` *(defaults)* | Profile-specific color scheme (`auto`, `light`, `dark`). | Pin `'dark'` on a dark-only workspace. |
| `ui.icon_set` | `'classic'` | File/folder icon style: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`, or a custom SVG directory path (`'%kernel.project_dir%/assets/fm-icons'`). | Choose `'windows11'` or `'macos'` for a desktop feel, or point to branded SVGs. |
| `ui.z_index` | `null` *(defaults)* | Profile-specific overlay z-index override. | When embedded on a page with high-`z-index` editors or drawers. |
| `ui.mobile_dialog_mode` | `'auto'` | Dialog presentation on small viewports (`<= 767.98px`): `'auto'` (modals & side offcanvas go fullscreen, top/bottom offcanvas become rounded native sheets), `'fullscreen'` (all fullscreen), or `'sheet'` (all open as a native bottom sheet). | Force `'sheet'` or `'fullscreen'` across all mobile pickers. |
| `ui.default_view` | `'grid'` | Initial layout: `'grid'` (thumbnail cards) or `'list'` (sortable table). | Use `'list'` for PDF/document archives and `'grid'` for visual galleries. |
| `ui.item_size` | `'md'` | Grid card width and list thumbnail scale: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`), or an exact pixel integer (`96`–`320`). Automatically switches to compact padding at `<= 132px`. | Use `'lg'` / `'xl'` for visual proofing and `'sm'` / `'xs'` for compact pickers. |
| `ui.card_aspect_ratio` | `'4/3'` | Card preview frame ratio: `'1/1'` (square), `'4/3'` (standard), `'16/9'` (widescreen/video), or `'3/4'` (vertical posters/catalogs). | Match your primary asset shape (`'1/1'` for products, `'16/9'` for video/banners). |
| `ui.folder_style` | `'tile'` | Folder presentation in grid view: `'tile'` (Google Drive-style horizontal folder tiles above files) or `'card'` (Windows/Finder-style full-size folder cards matching file cards). | Switch to `'card'` for more visually prominent folders. |
| `ui.show_size_slider` | `true` | Shows the live item-size slider (`96px`–`320px`) and folder style toggle inside the Settings menu. | Set `false` to enforce a locked layout density. |
| `ui.volume_order` | `'local_first'` | Volume ordering in the sidebar and switcher: `'local_first'`, `'network_first'`, or `'configured'` (exact YAML `volumes: [...]` order without group dividers). | Use `'network_first'` or `'configured'` for cloud-first workflows. |
| `ui.show_folder_tree` | `true` | Toggles the left folder tree sidebar. | Set `false` for simple single-folder TinyMCE or avatar pickers. |
| `ui.tree_lines` | `true` | Renders hierarchy connector lines inside the folder tree. | Set `false` for a cleaner, minimalist sidebar. |
| `ui.show_folder_item_count` | `true` | Displays item count badges (`12`) next to folders. Respects authorization so restricted subfolders are never leaked in counts. | Keep `true` for quick folder visibility. |
| `ui.folder_count_mode` | `'recursive_files'` | How folder badges are counted: `'recursive_files'` (all files including subfolders), `'files'` (direct files only), or `'items'` (direct files + subfolders). | Choose `'files'` for direct counts only. |
| `ui.inspector_mode` | `'on_select'` | Right details/preview panel: `'on_select'` (opens when an item is selected), `'always'`, or `'hidden'`. | Set `'hidden'` inside narrow modal pickers. |
| `ui.folder_open_on` | `'dblclick'` | Folder navigation gesture: `'dblclick'` (single click selects, double click opens—desktop convention) or `'click'` (single click opens immediately—web/mobile convention). | Use `'click'` for fast single-click browsing in admin panels. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Default items per page (`6`–`200`) and user-selectable page size options. | Raise to `48` and `[24, 48, 96, 150]` for high-volume catalogs. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Displays the sidebar storage quota bar and the clickable Detailed Storage Usage modal. (Server-side `storage_quota` enforcement stays active even if hidden.) | Set `show_quota: false` if you don't want to expose quota meters in the UI. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Controls inline PDF Lightbox and HTML5 Video playback (enforced both in the UI and server-side in `DownloadController`). | Set `false` to force direct download instead of inline browser preview. |
| `ui.remember_last_dir` | `true` | Persists the user's last visited **volume + directory** in `localStorage` (automatically recovers to the parent/root if that folder was deleted). | Set `false` if every session should start at the root directory. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Prompts for confirmation before deleting or drag-and-drop moving items. | Set `confirm_move: false` for faster reorganization workflows. |
| `ui.double_click_to_select` | `true` | In Modal/OffCanvas picker mode, double-clicking a file immediately selects it and closes the dialog. | Set `false` if double-clicking should preview instead of selecting. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Enables right-click context menus and the `[ ← \| → \| ↑ \| ↻ ]` quick navigation strip when right-clicking empty space. | Set `false` for locked-down end-user screens. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Enables drag-and-drop moving between folders and dragging files/folders from the OS to upload. | Set `false` for read-only asset libraries. |
| `ui.keyboard_shortcuts` | `true` | Enables keyboard shortcuts (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Delete`, `Space`, `/`, `R`, `F`, `?`, etc.). | Disable (`false`) if your host page binds conflicting global shortcuts. |
| `ui.show_*` flags | *(mostly `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Toggle individual toolbar controls on or off to streamline the interface. *(Note: the "About" and "GitHub" entries inside Settings are built-in attribution items and are not disabled via YAML.)* |

### 3.7. Per-Profile Cache (`cache`), Security (`security`), Permissions (`permissions`), AI & Usage Guard

#### Cache (`cache`)
| Parameter | Default | Description |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Enables or disables caching for this profile. When a profile sets `cache.enabled: false`, `volume.cache_ttl` will not force caching back on. |
| `cache.ttl` | `null` *(defaults)* | TTL precedence order: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Security (`security`)
| Parameter | Default | Description |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Enables RBAC enforcement via `FileManagerVoter` and any custom Voters. Always keep `true` in production. |
| `security.csrf_protection` | `true` | Requires a valid `X-LiveUX-FM-CSRF` header on every `POST` request. |
| `security.sanitize_svg` | `true` | Sanitizes uploaded and edited SVGs against a strict 55-tag allowlist (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Enables the **"Import from URL"** tab in the upload modal (protected against SSRF and DNS rebinding by `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Overrides PSR-3 audit logging for this profile (`true` / `false` / `null`). |

#### 17 Granular Permissions (`permissions`)
> [!WARNING]
> **Important: What does `true` mean under `permissions`?**  
> Setting a permission to **`true`** does **NOT** mean *"public / anonymous access"*! `FileManagerVoter` grants `true` **only to authenticated users (`$token->getUser() !== null`)** and denies anonymous requests.  
> Additionally, **`permissions.view`** is the master gate: if a user lacks `view`, every other action (`upload`, `delete`, `download`, etc.) is automatically denied.

Every permission accepts one of 4 formats:
- `true` → Any authenticated user
- `false` → Nobody (action completely disabled)
- `'ROLE_ADMIN'` → Only users granted that role
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Users granted any of those roles

| Permission Key | Default | Controlled Actions |
| :--- | :--- | :--- |
| `view` | `true` | Directory listing, folder tree, previews, and **prerequisite for every other permission**. |
| `upload` | `true` | Standard/chunked uploads, saving from the image editor, and remote URL imports. |
| `create_folder` | `true` | Creating new folders. |
| `rename` | `true` | Renaming files and folders. |
| `clone` | `true` | Duplicating a file or folder. |
| `move` | `true` | Moving items via drag-and-drop or Cut/Paste. |
| `copy` | `true` | Copying items via Copy/Paste or the Move/Copy dialog. |
| `delete` | `true` | Deleting files/folders (move to trash or permanently delete). |
| `trash_restore` | `true` | Restoring items from the trash bin. |
| `trash_purge` | `true` | Permanently deleting items from trash or emptying the trash bin. |
| `download` | `true` | Downloading single files, streaming PDFs/videos, and copying file URLs. |
| `download_zip` | `true` | Downloading multiple selected items as a `.zip` archive. |
| `extract_zip` | `null` *(auto)* | Forced to `false` while `zip.allow_extract: false`; automatically becomes `true` when `zip.allow_extract: true` (narrow with `'ROLE_ADMIN'` if desired). |
| `share` | `true` | Generating time-limited HMAC-signed share links. |
| `clear_cache` | `true` | Clearing directory cache from Settings and forcing `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Editing file `alt`/`title`/`caption`/`tags`/focal point and folder color/icon/pinning. |
| `ai_enrich` | `true` | Invoking **✨ Suggest with AI** (requires `view` + `edit_metadata` + `ai_enrich`). |

#### AI Enrichment (`ai`) & Asset Usage Guard (`usage_guard`)
| Parameter | Default | Description |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Enables AI metadata suggestions; setting `locale` controls the output language (e.g., `'en'`, `'tr'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Maximum suggested tags (`1`–`20`) and image downscale limit before sending to AI (`256`–`2048` px, saving vision tokens). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Prevents files on `private: true` volumes from being sent to external AI APIs; optional auto-enrichment after upload (see [Chapter 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Warns or blocks **`delete`, `rename`, `move`, and `overwrite`** operations when an asset is referenced in your database (see [Chapter 10](10-asset-usage-guard.md)). |

---

## 4. 5 Production-Ready Profile Recipes (Copy & Paste)

Here are the 5 most common real-world configurations ready to drop into your project:

### Recipe 1: E-Commerce Product Catalog (Square Cards + WebP + Duplicate Reuse)
> **Goal:** Accept only crisp `1:1` square product photos; auto-resize and convert smartphone uploads to WebP; reuse existing files on duplicate uploads; block accidental deletion of assets attached to active products.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'en'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # 204px large product cards
                card_aspect_ratio: '1/1' # Square showcase ratio
                folder_style: 'card'     # Full-size category folder cards
                show_folder_item_count: true
```

### Recipe 2: Blog / News Cover Images (`16:9` Banners + AI Alt-Text)
> **Goal:** Accept only widescreen `16:9` high-res cover images (at least `1200×630`px), organize uploads automatically into `2026/11` folders, and generate SEO alt text and tags with AI.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Newest covers first
            ai:
                enabled: true
                locale: 'en'
                max_tags: 6
            ui:
                item_size: 'xl'           # 260px wide cover previews
                card_aspect_ratio: '16/9' # Widescreen banner aspect ratio
```

### Recipe 3: Customer Document & PDF Vault (Private Supabase/S3 + Per-User Isolation)
> **Goal:** Isolate every customer to their own PDF invoices on a private S3-compatible bucket, block direct external URLs, refresh cloud listings every 60 seconds, and audit-log every action.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Customer PDF Vault'
            group: 'network'
            driver: 'AwsS3'
            private: true          # No unauthenticated direct URL access
            cache_ttl: 60          # External cloud edits appear within 60s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Each user sees only their own folder
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Sortable table ideal for PDFs
                item_size: 'sm'          # Compact rows
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Recipe 4: Fast Rich-Text Editor Picker (TinyMCE / CKEditor / Tiptap)
> **Goal:** A lightweight, distraction-free modal picker where double-clicking an image immediately inserts it into the editor.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Recipe 5: Read-Only Partner / Press Kit Library
> **Goal:** Let partners browse logos and catalogs, download single files or bulk ZIPs, and generate signed share links—while preventing any uploads, edits, or deletions.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 days
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Dynamic Path Placeholders (`DynamicPathResolver`)

You can use dynamic placeholders inside local `path`, `url_prefix`, cloud `driver_options.prefix`, and profile `upload_subfolder`:

| Placeholder | Description | Example Output |
| :--- | :--- | :--- |
| `{user_id}` | Collision-free, filesystem-safe identifier for the authenticated Symfony user (`guest` if unauthenticated). | `42` or `alice_a--h-9f1c2e4b...` |
| `{Y}` | 4-digit year | `2026` |
| `{m}` | 2-digit month | `11` |
| `{d}` | 2-digit day | `25` |

```yaml
volumes:
    user_space:
        label: 'My Personal Space'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Collision-Free Multi-Tenant `{user_id}` Isolation:**  
> `DynamicPathResolver` generates **disjoint SHA-256-suffixed `<slug>--h-<16-hex>`** directory keys for emails (`alice.a@example.com` vs `alice_a@example.com`), case variants (`Alice` vs `alice`), Unicode usernames (Arabic, Chinese, Turkish), reserved words (`guest`, `user`, identifiers ending in `--h-`), and Windows device names (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Two distinct users can never collide on the same folder.
>
> **CLI Behavior:** Console commands (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup`, etc.) automatically skip volumes containing `{user_id}` so they never create a stray `guest/` folder.

---

## 6. Complete YAML Schema Reference (Every Active Option)

The YAML snippet below documents **every active configuration key** defined in `Configuration.php` alongside its default value and inline commentary. In your own project, only declare the keys you want to customize—everything else inherits its default automatically.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) GLOBAL DEFAULTS — Inherited by all profiles unless overridden
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Default root directory for shorthand single-disk profiles
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = Symfony request/translator locale (12 built-in languages, fallback 'en') | 'en' | 'tr' | 'fr' | ...
        z_index: 1050                                      # Base z-index for overlays, popovers, and modals (>= 1)
        auto_inject_dialog_host: true                      # Auto-inject Modal/OffCanvas DialogHost into full HTML responses
        dialog_host_profile: 'default'                     # Default profile for the auto-injected DialogHost

        audit_log:
            enabled: false                                 # Log all 9 file/folder mutations to a PSR-3 channel
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Default AI model when not overridden on a profile
            platform_service: null                         # Custom Symfony AI PlatformInterface service ID (null = auto)

        cache:
            enabled: true                                  # Global directory/tree PSR-6 cache switch
            pool: 'cache.app'                              # Symfony PSR-6 cache pool service ID
            ttl: 3600                                      # Global cache TTL in seconds (0 = disabled)

        metadata:
            enabled: true                                  # Folder colors/icons/pins & file alt/title/caption/tags/focalPoint
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Storage file for json_file driver
            connection: null                               # Optional DBAL connection name for doctrine driver (null = entity_manager connection)
            entity_manager: 'default'                      # EntityManager / connection name for doctrine driver
            table: 'liveux_fm_metadata'                    # DBAL table name for doctrine driver (see Chapter 13)
            auto_create_table: true                        # Auto-create metadata table if missing (set false when using migrations)

    # =========================================================================
    # 2) STORAGE VOLUMES — Local & Cloud Filesystem Definitions
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Local Storage'                         # Display name in sidebar & breadcrumbs
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Physical path (supports {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Public browser URL prefix (leave null when private: true)
            private: false                                 # true = stream all files through RBAC-protected DownloadController
            cache_ttl: null                                # Per-volume cache TTL in seconds (null = inherit, 0 = disabled)
            driver_options: []                             # Empty for LocalFileSystem

        cloud_s3_example:
            label: 'Cloud S3 / R2 / Supabase'
            icon: 'supabase'                               # Matches resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # Public bucket/CDN URL (null when private: true)
            private: false
            cache_ttl: 60                                  # Refresh external cloud changes every 60 seconds
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Bucket name
                region: '%env(AWS_S3_REGION)%'             # Region ('auto' for Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # Custom S3 endpoint URL for non-AWS providers
                prefix: 'media/{Y}'                        # Optional root prefix inside the bucket (supports dynamic placeholders)
                use_path_style_endpoint: true              # true for Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Optional AWS STS temporary session token
                version: 'latest'                          # AWS S3 API version (default: 'latest')
                ca_bundle: null                            # Optional custom CA bundle .pem path (fixes Windows cURL error 60)
                disable_acl: true                          # Auto true for custom endpoints; set true for AWS S3 Bucket owner enforced
                retain_visibility: false                   # Auto false for custom endpoints (avoids sending x-amz-acl)
                request_checksum_calculation: 'when_required'  # AWS SDK >= 3.337 compatibility (auto for custom endpoints)
                response_checksum_validation: 'when_required'  # AWS SDK >= 3.337 compatibility (auto for custom endpoints)

        custom_flysystem_example:
            label: 'SFTP Archive'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # League\Flysystem\FilesystemOperator service ID

    # =========================================================================
    # 3) PROFILES — Independent Rule Sets per Use Case
    # =========================================================================
    profiles:
        full_reference:
            # --- Mounted Volumes, Locale & Shorthand Single-Disk Options ---
            locale: null                                   # null = inherit defaults.locale | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # When empty, an implicit volume is created from driver/path below
            driver: 'LocalFileSystem'                      # Shorthand driver used only when volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Shorthand path used only when volumes: []
            url_prefix: '/uploads'                         # Shorthand URL prefix used only when volumes: []
            private: false                                 # Shorthand privacy flag used only when volumes: []
            driver_options: []                             # Shorthand driver options used only when volumes: []

            # --- Listing, Hiding & Default Sorting ---
            ignore_dot_files: true                         # Hide and block dotfiles (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Regex/wildcard patterns hidden from listings & direct access
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Always list folders before files

            # --- Uploads, Quota & Collision Handling ---
            upload_subfolder: null                         # e.g. '{Y}/{m}' — place uploads into dynamic subfolders
            upload_allow: []                               # Empty = all safe types; non-empty = strict MIME allowlist
            upload_deny:                                   # Always blocked dangerous MIME types (47 by default if omitted)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Maximum assembled size for a single file (e.g. '50M', '2G')
            storage_quota: null                            # Total profile disk quota (e.g. '500M', '5G'; null = unlimited)
            overwrite_policy: 'rename'                     # rename (append -1) | overwrite | reject

            # --- Chunked Uploads (Bypass PHP upload_max_filesize) ---
            chunk_upload:
                enabled: true                              # Split large files into resumable chunks
                chunk_size: '2M'                           # Target chunk size (auto-clamped to 80% of PHP limits)
                parallel_uploads: 3                        # Max concurrent uploads (1..10)
                max_retries: 3                             # Automatic retries per chunk on network errors (0..10)

            # --- Duplicate Detection (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Detect duplicate content on upload
                policy: 'warn'                             # warn | reject | reuse (return existing file)
                max_scan_files: 5000                       # Max files hashed for duplicate indexing (100..50000)

            # --- Automatic Filename Normalization ---
            naming:
                slugger: true                              # Transliterate Unicode filenames to clean ASCII slugs
                slugger_locale: null                       # null = inherit profile locale (e.g. 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Static/dynamic prefix (e.g. 'product-')
                append: ''                                 # Static/dynamic suffix before extension (e.g. '-{Y}{m}{d}')
                apply_on_rename: true                      # Apply slugger/transformer rules when renaming
                apply_affixes_on_rename: false             # Re-apply prepend/append affixes when renaming
                allow_extension_change: false              # Allow users to change file extensions (.jpg -> .php) on rename

            # --- Upload-Time Image Optimization ---
            image_optimization:
                enabled: false                             # Auto-orient, strip EXIF, resize, and compress on upload
                auto_orient: true                          # Fix smartphone EXIF orientation
                strip_exif: true                           # Strip GPS & camera EXIF data for privacy and size savings
                max_width: 2400                            # Proportional max width in px (>= 100)
                max_height: 2400                           # Proportional max height in px (>= 100)
                convert_to_webp: false                     # Auto-convert JPEG/PNG uploads to WebP
                quality: 85                                # Output quality (10..100)

            # --- Image Resolution & Aspect Ratio Constraints ---
            image_constraints:
                min_width: null                            # Minimum width in px (e.g. 800)
                min_height: null                           # Minimum height in px (e.g. 600)
                max_width: null                            # Maximum width in px (checked after image_optimization)
                max_height: null                           # Maximum height in px
                aspect_ratio: null                         # Required ratio (e.g. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Allowed ratio deviation (0.0..0.5; 0.05 = 5%)

            # --- Thumbnail Engine ---
            thumbnail:
                enabled: true                              # Generate cached image card previews
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Thumbnail width in px (>= 32)
                height: 240                                # Thumbnail height in px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Compression quality (10..100)
                fit: 'contain'                             # contain (fit inside) | cover (crop to fill)
                lazy_load: true                            # Add loading="lazy" to card thumbnails
                cache_dir: '%kernel.project_dir%/public/media-cache' # Local cache directory for generated thumbnails

            # --- Trash Bin (Soft Delete) ---
            trash:
                enabled: true                              # Move deleted items to trash instead of permanent delete
                directory: '.trash'                        # Hidden trash directory name
                retention_days: 30                         # Retention period in days for file-manager:trash:purge
                max_size: '500M'                           # Maximum total trash size

            # --- ZIP Download & Safe Archive Extraction ---
            zip:
                enabled: true                              # Enable multi-file/folder ZIP downloads
                max_files: 100                             # Max files in a single ZIP download
                max_total_size: '250M'                     # Max total size for a single ZIP download
                allow_extract: false                       # Enable Unzip (disabled by default; 7-layer protected)
                max_extract_files: 200                     # Max files extracted per archive (1..2000)
                max_extract_size: '100M'                   # Max total extracted size (Zip-Bomb guard)
                max_compression_ratio: 100                 # Max allowed compression ratio (1..1000 — Zip-Bomb guard)

            # --- Time-Limited Signed Share Links (HMAC) ---
            share:
                enabled: true                              # Allow generating signed share URLs
                default_ttl: 86400                         # Default expiry in seconds (86400 = 24 hours)
                max_ttl: 2592000                           # Maximum selectable expiry in seconds (2592000 = 30 days)
                allow_inline_preview: true                 # Allow inline browser preview for safe MIME types

            # --- Optional Symfony AI Enrichment ---
            ai:
                enabled: false                             # Show "✨ Suggest with AI" in the metadata modal
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = profile/request locale (e.g. 'en' => English alt text/tags)
                max_tags: 6                                # Maximum suggested tags (1..20)
                max_image_dimension: 1024                  # Downscale limit before sending to AI (256..2048 px)
                block_private_volumes: true                # true = block AI calls for private: true volumes (default: true)
                auto_enrich_on_upload: false               # Automatically run AI enrichment after uploads

            # --- Asset Usage Guard (Delete / Rename / Move / Overwrite) ---
            usage_guard:
                enabled: false                             # Check DB references before mutating assets
                policy: 'warn'                             # warn (show references + allow override) | block (forbid action)
                enforce_server_side: true                  # Also enforce on direct API calls
                max_references: 20                         # Max references listed in the modal (1..200)

            # --- Per-Profile Caching ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | seconds (0 = disabled)

            # --- Security Options ---
            security:
                voter_enabled: true                        # Enable FileManagerVoter RBAC checks
                csrf_protection: true                      # Require X-LiveUX-FM-CSRF on POST requests
                sanitize_svg: true                         # Sanitize uploaded/edited SVGs
                allow_remote_url_import: false             # Enable "Import from URL" tab (SSRF protected)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 Granular RBAC Permissions (true = authenticated user | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Master permission required for listing and all other actions
                upload: true                               # File uploads, image editor saves, and URL imports
                create_folder: true                        # Create new folders
                rename: true                               # Rename files and folders
                clone: true                                # Duplicate files/folders
                move: true                                 # Move items (including drag-and-drop & cut/paste)
                copy: true                                 # Copy items (including copy/paste)
                delete: true                               # Delete items (trash or permanent delete)
                trash_restore: true                        # Restore items from trash
                trash_purge: true                          # Permanently purge items / empty trash
                download: true                             # Single-file download & copy URL
                download_zip: true                         # Multi-file ZIP download
                extract_zip: false                         # Extract ZIP archives (also requires zip.allow_extract: true)
                share: true                                # Create signed share links
                clear_cache: true                          # Clear cache from Settings & force ?refresh=1
                edit_metadata: true                        # Edit file alt/tags/focal point & folder color/icon/pin
                ai_enrich: true                            # Run AI metadata enrichment

            # --- User Interface (UI) Flags & Sizing ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <custom dir>
                z_index: null                              # null = defaults.z_index | integer (>= 1)
                mobile_dialog_mode: 'auto'                 # Dialog behavior on mobile (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px compact)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (compact row) | card (full-size folder card)
                show_size_slider: true                     # Show live size slider & folder style toggle in Settings
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Show left folder tree sidebar
                tree_lines: true                           # Show hierarchy connector lines in folder tree
                show_folder_item_count: true               # Show item count badges on folders
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Show sidebar quota meter & header quota pill
                show_quota_details: true                   # Enable clickable Detailed Storage Usage modal
                per_page: 24                               # Default items per page (6..200)
                per_page_options: [24, 48, 96]             # Page size dropdown choices
                show_perf_badge: false                     # Show CACHE HIT / DISK timing badge in toolbar
                show_nav_buttons: true                     # Master toggle for Back / Forward / Up / Refresh group
                show_history_buttons: true                 # Show Back / Forward history buttons
                show_up_button: true                       # Show Up one level button
                show_refresh_button: true                  # Show Refresh (?refresh=1) button
                show_breadcrumbs: true                     # Show clickable/droppable breadcrumb bar
                show_search: true                          # Show search box & deep search toggle
                show_type_filter: true                     # Show type filter (Images / Videos / Docs / Folders)
                show_view_toggle: true                     # Show Grid / List switcher
                show_pagination: true                      # Show bottom pagination bar
                show_per_page_selector: true               # Show items-per-page dropdown
                show_sort: true                            # Show sort dropdown & direction toggle
                show_fullscreen_button: true               # Show fullscreen toggle button
                show_settings_button: true                 # Show Settings (⚙) dropdown
                show_footer: true                          # Show bottom status bar
                show_context_nav: true                     # Show quick navigation strip in empty-area context menu
                allow_dropzone_upload: true                # Allow dragging files/folders from OS onto the file list
                remember_last_dir: true                    # Remember last volume & folder (auto-heals if deleted)
                confirm_delete: true                       # Show confirmation modal before deleting
                confirm_move: true                         # Show confirmation modal before moving/copying
                double_click_to_select: true               # Double-click selects file & closes Modal/OffCanvas picker
                allow_context_menu: true                   # Enable right-click context menu
                allow_pdf_preview: true                    # Inline PDF Lightbox (false forces download)
                allow_video_preview: true                  # Inline Video player (false forces download)
                keyboard_shortcuts: true                   # Enable scoped keyboard shortcuts
                allow_drag_move: true                      # Enable dragging items into folders to move them
```

---

[← Previous: 01 — Requirements & Installation](01-requirements-and-installation.md) | [Documentation Index](README.md) | [Next: 03 — Usage Modes & JS API →](03-usage-modes-and-js-api.md)
