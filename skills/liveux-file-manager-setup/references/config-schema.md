# LiveUX FileManagerBundle — Configuration Schema Reference

Exact YAML keys from `LiveUX\FileManagerBundle\DependencyInjection\Configuration`.

## Top-Level `defaults`

| Key | Default | Values / Notes |
| :--- | :--- | :--- |
| `base_path` | `'%kernel.project_dir%/public/uploads'` | Default root path for local volumes |
| `theme` | `'bootstrap5'` | `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`, or custom theme name |
| `locale` | `'en'` | `'en'`, `'tr'`, `'fr'` |
| `color_scheme` | `'auto'` | `'auto'`, `'light'`, `'dark'` |
| `z_index` | `1050` | Integer `>= 1`; propagates to `--liveux-fm-z-base` |
| `auto_inject_dialog_host` | `true` | Injects `@LiveUXFileManager/components/DialogHost.html.twig` before `</body>` on full HTML responses |
| `dialog_host_profile` | `'default'` | Default profile for auto-injected dialog host |
| `metadata.file` / `metadata.table` | `'%kernel.project_dir%/var/file-manager/metadata.json'` / `'liveux_fm_metadata'` | Storage backend for `FolderMetadataStorageInterface` (`metadata.driver`: `json_file` \| `doctrine` [built-in DBAL table via `entity_manager`] \| `none`) |
| `cache.enabled` / `cache.pool` / `cache.ttl` | `true` / `'cache.app'` / `3600` | Global directory/tree cache settings |
| `audit_log.enabled` | `false` | Global PSR-3 audit logging toggle |
| `audit_log.level` | `'info'` | `'debug'`, `'info'`, `'notice'`, `'warning'` |
| `ai.default_model` / `ai.platform_service` | `'gpt-4o-mini'` / `null` | Default AI model and optional `symfony/ai-platform` service id |
| `metadata.enabled` / `metadata.connection` / `metadata.entity_manager` / `metadata.auto_create_table` | `true` / `null` / `'default'` / `true` | Doctrine driver connection / entity manager selection & auto-table creation |
| `metadata.colors` | `['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#64748b']` | **Deprecated (no-op for backward compatibility)** — folder colour palette |
| `metadata.icons` | `['folder','bag','image','video','doc','pen','star','lock']` | **Deprecated (no-op for backward compatibility)** — folder icon palette (use `<icon_dir>/badges/*.svg` instead) |
| `metadata.allow_custom_icon_upload` / `metadata.custom_icon_dir` | `false` / `'.fm-icons'` | **Deprecated (no-op for backward compatibility)** |

## `volumes.<name>`

| Key | Default | Notes |
| :--- | :--- | :--- |
| `label` | volume key | Display label in sidebar/breadcrumbs |
| `icon` | `null` | Optional UI SVG icon key (`disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, or custom `<icon_dir>/ui/<key>.svg`); auto-detected from `driver`/`endpoint` when omitted |
| `group` | `null` | `null`, `'local'` or `'network'` (grouped separately in sidebar) |
| `driver` | `'LocalFileSystem'` | `'LocalFileSystem'`, `'AwsS3'`, or `'FlysystemService'` |
| `path` | `null` | Local filesystem path (supports `{user_id}`, `{Y}`, `{m}`, `{d}`) |
| `url_prefix` | `null` | Public URL / CDN prefix (leave `null` for private volumes) |
| `private` | `false` | Forces streaming via `DownloadController` + `Cache-Control: private, no-store` |
| `cache_ttl` | `null` | Optional per-volume cache TTL in seconds (`null` inherits profile/defaults; `0` disables caching on this volume — ideal for cloud buckets edited externally) |
| `driver_options` | `[]` | For `FlysystemService`: `{ service: 'app.my_flysystem' }`; for `AwsS3`: `bucket`, `region`, `endpoint`, `key`, `secret`, `prefix`, `use_path_style_endpoint`, `request_checksum_calculation` (defaults to `when_required` when `endpoint` is set) |

## `profiles.<name>`

### Core & Upload Keys
- `locale`: `null` (`?string`, optional per-profile locale override; inherits `defaults.locale` when `null`)
- `volumes`: `list<string>` (default `[]` — falls back to inline `driver` / `path` / `url_prefix` / `private` / `driver_options` when `volumes` is empty)
- (There is no per-profile `base_path` key; use `defaults.base_path`, `volumes.<name>.path`, or single-volume `profiles.<name>.path`, all of which support `{user_id}`, `{Y}`, `{m}`, `{d}`.)
- `upload_subfolder`: `null` — optional dynamic upload target subdirectory (e.g. `'{Y}/{m}'`)
- `ignore_dot_files`: `true`
- `exclude_patterns`: `list<string>` default `['/^_thumbs$/i', '/^\.DS_Store$/i']` (regex patterns hidden from listing and direct access)
- `sort_by`: `'name'` (`name` | `size` | `date` | `type`), `sort_direction`: `'asc'` (`asc` | `desc`), `folders_first`: `true`
- `upload_allow`: `[]` (MIME allowlist, e.g. `['image/jpeg', 'image/png', 'image/webp']`)
- `upload_deny`: 44 default dangerous MIME types (`application/x-httpd-php`, `application/x-php`, `text/x-php`, `text/html`, `application/xhtml+xml`, `text/xml`, `application/xml`, `text/javascript`, `application/javascript`, `application/hta`, `image/svg`, `application/wasm`, etc.)
- `upload_max_size`: `'10M'` (total assembled file size limit; chunking splits files up to this limit to bypass lower PHP `upload_max_filesize`)
- `storage_quota`: `null` (e.g. `'500M'`, `'2G'`)
- `overwrite_policy`: `'rename'` (`rename` | `overwrite` | `reject`)

### Sub-Blocks
- `chunk_upload`: `enabled` (`true`), `chunk_size` (`'2M'`, auto-bounded to 80% of PHP `upload_max_filesize`/`post_max_size`), `parallel_uploads` (`3`), `max_retries` (`3`)
- `naming`: `slugger` (`true`), `slugger_locale` (`null`), `transformer` (`'none'` | `uppercase` | `lowercase` | `camel` | `snake` | `cameltitle` | `title` | `titleall` | `folder`), `prepend` (`''`), `append` (`''`), `apply_on_rename` (`true`), `apply_affixes_on_rename` (`false`), `allow_extension_change` (`false`)
- `image_optimization`: `enabled` (`false`), `auto_orient` (`true`), `strip_exif` (`true`), `max_width` (`2400`), `max_height` (`2400`), `convert_to_webp` (`false`), `quality` (`85`)
- `image_constraints`: `min_width` (`null`), `min_height` (`null`), `max_width` (`null`), `max_height` (`null`), `aspect_ratio` (`null`, e.g. `'1:1'`, `'16:9'`, `'4:3'`), `aspect_ratio_tolerance` (`0.05`) — enforced post-optimization on server and 0ms pre-flight in browser (only affects raster images; PDFs/other files pass unaffected)
- `duplicate_detection`: `enabled` (`false`), `policy` (`'warn'` | `'reject'` | `'reuse'`), `max_scan_files` (`5000`, 100–50000)
- `thumbnail`: `enabled` (`true`), `driver` (`'auto'` | `imagick` | `gd` | `liip_imagine` | `none`), `on_missing_driver` (`'fallback_original'` | `icon` | `exception`), `width` (`320`), `height` (`240`), `format` (`'webp'` | `jpeg` | `png`), `quality` (`80`), `fit` (`'contain'` | `cover`), `lazy_load` (`true`), `cache_dir` (`'%kernel.project_dir%/public/media-cache'`), `liip_filter` (`null` — **deprecated / no-op for backward compatibility**)
- `trash`: `enabled` (`true`), `directory` (`'.trash'`), `retention_days` (`30`), `max_size` (`'500M'`)
- `zip`: `enabled` (`true`), `fallback` (`'disable'` | `zipstream` — **deprecated / no-op for backward compatibility**), `max_files` (`100`), `max_total_size` (`'250M'`), `allow_extract` (`false`), `max_extract_files` (`200`, 1–2000), `max_extract_size` (`'100M'`), `max_compression_ratio` (`100`, 1–1000)
- `share`: `enabled` (`true`), `default_ttl` (`86400`), `max_ttl` (`2592000`), `allow_inline_preview` (`true`)
- `ai`: `enabled` (`false`), `model` (`null`), `max_tags` (`6`), `locale` (`null`), `max_image_dimension` (`1024`), `block_private_volumes` (`true`), `auto_enrich_on_upload` (`false`)
- `usage_guard`: `enabled` (`false`), `policy` (`'warn'` | `'block'`), `enforce_server_side` (`true`), `max_references` (`20`, 1–200)
- `cache`: `enabled` (`null` — inherits `defaults.cache.enabled`), `ttl` (`null` — inherits `defaults.cache.ttl`)
- `permissions`: each value is `true`/`false` or a role/expression rule; defaults: `view`, `upload`, `create_folder`, `rename`, `clone`, `move`, `copy`, `delete`, `trash_restore`, `trash_purge`, `download`, `download_zip`, `share`, `clear_cache`, `edit_metadata`, `ai_enrich` = `true`; `extract_zip` = `false`
- `security`: `voter_enabled` (`true`), `csrf_protection` (`true`), `sanitize_svg` (`true`), `allow_remote_url_import` (`false`), `audit_log` (`null` — inherits global)

### `profiles.<name>.ui` Flags

| Key | Default | Notes |
| :--- | :--- | :--- |
| `theme` | `null` | Inherits `defaults.theme` |
| `icon_set` | `'classic'` | `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`, or custom directory path |
| `color_scheme` | `null` | `null` (inherits defaults) \| `'auto'` \| `'light'` \| `'dark'` |
| `z_index` | `null` | Inherits `defaults.z_index` |
| `show_folder_tree` | `true` | Sidebar tree toggle |
| `tree_lines` | `true` | Indentation guide lines |
| `show_folder_item_count` | `true` | Numeric item count badge on folders |
| `folder_count_mode` | `'recursive_files'` | `recursive_files` \| `files` \| `items` |
| `show_quota` | `true` | Shows sidebar quota bar & topbar pill; `false` also skips `/api/list` quota calc |
| `show_quota_details` | `true` | Enables clickable breakdown modal, Settings shortcut, and `quotaDetails` API |
| `inspector_mode` | `'on_select'` | `on_select` \| `always` \| `hidden` |
| `volume_layout` | `'both'` | **Deprecated (no-op for backward compatibility)** — `grouped_tree` \| `switcher` \| `both` |
| `volume_order` | `'local_first'` | `local_first` \| `network_first` \| `configured` |
| `default_view` | `'grid'` | `grid` \| `list` |
| `item_size` | `'md'` | `'xs'` (108px) \| `'sm'` (132px) \| `'md'` (158px) \| `'lg'` (204px) \| `'xl'` (260px) or custom integer `96`–`320` px (`<= 132px` auto-tightens spacing) |
| `card_aspect_ratio` | `'4/3'` | `'1/1'` \| `'4/3'` \| `'16/9'` \| `'3/4'` (media card stage ratio in Grid) |
| `folder_style` | `'tile'` | `'tile'` (compact horizontal strip) \| `'card'` (large Finder/Drive-style folder cards) |
| `show_size_slider` | `true` | Shows live Item Size (`96–320 px`) slider in Settings popover |
| `folder_open_on` | `'dblclick'` | `dblclick` \| `click` |
| `pagination_mode` | `'pages'` | **Deprecated (no-op for backward compatibility)** — `pages` \| `loadmore` |
| `per_page` | `24` | `6`–`200` |
| `per_page_options` | `[24, 48, 96]` | Pagination dropdown values |
| `show_perf_badge` | `false` | Topbar `CACHE HIT / DISK` badge |
| `show_nav_buttons` | `true` | Master toggle for Back/Forward/Up/Refresh group |
| `show_history_buttons` | `true` | Back/Forward buttons |
| `show_up_button` | `true` | Parent folder button |
| `show_refresh_button` | `true` | Refresh button |
| `show_breadcrumbs` | `true` | Breadcrumb path navigation |
| `show_search` | `true` | Search input + deep search button |
| `show_type_filter` | `true` | File type dropdown |
| `show_view_toggle` | `true` | Grid/List toggle |
| `show_pagination` | `true` | Footer pagination bar |
| `show_per_page_selector` | `true` | Per-page selector |
| `show_sort` | `true` | Sort dropdown & direction |
| `show_fullscreen_button` | `true` | Fullscreen button |
| `show_settings_button` | `true` | Settings popover button |
| `show_footer` | `true` | Bottom bar |
| `show_context_nav` | `true` | Quick nav bar in empty-area context menu |
| `allow_dropzone_upload` | `true` | Content-area OS drag-and-drop upload |
| `remember_last_dir` | `true` | Persists last visited folder in `localStorage` |
| `confirm_delete` | `true` | Shows delete confirmation modal |
| `confirm_move` | `true` | Shows move confirmation modal |
| `double_click_to_select` | `true` | Double-click picks file in modal/offcanvas mode |
| `allow_context_menu` | `true` | Right-click context menu |
| `allow_pdf_preview` | `true` | Enables PDF Lightbox preview & inline PDF streaming (when `false`, forces `attachment` download) |
| `allow_video_preview` | `true` | Enables Video Inspector/Lightbox player & inline video streaming (when `false`, forces `attachment`) |
| `keyboard_shortcuts` | `true` | Scoped keyboard shortcuts |
| `allow_drag_move` | `true` | Internal drag-to-move |
