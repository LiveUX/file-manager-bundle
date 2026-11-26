# Theme Tokens, Modular Twig Blocks & SVG Icon Keys Reference

## 10 Named Twig Blocks (`@LiveUXFileManager/base/layout.html.twig`)

1. `fm_shell` — Outer `.liveux-fm-shell` wrapper (`data-size-tier`, `data-folder-style`, `data-sidebar-open`)
2. `fm_topbar` — Includes `base/parts/_topbar.html.twig`
3. `fm_settings_popover` — Includes `base/parts/_settings_popover.html.twig`
4. `fm_banners` — Dynamic `statusBanner` and `uploadQueue` containers
5. `fm_body` — Split body container wrapping sidebar + main + inspector
6. `fm_sidebar` — Includes `base/parts/_sidebar.html.twig` (tree, pinned folders, quota bar, trash button)
7. `fm_main` — Includes `base/parts/_main.html.twig` (file grid/list, dropzone, clipboard/bulk floating bars, pagination)
8. `fm_inspector` — Includes `base/parts/_inspector.html.twig` (right preview & metadata panel)
9. `fm_footer` — Includes `base/parts/_footer.html.twig` (modal/offcanvas action buttons)
10. `fm_overlays` — Includes `base/parts/_overlays.html.twig` (context menu, modal dialog, lightbox, shortcuts/about)

## Core CSS Custom Properties (`assets/styles/core.css`)

Scope overrides to `[data-liveux-theme="<name>"]` (and `[data-fm-color-scheme="dark"] [data-liveux-theme="<name>"]`, `.dark [data-liveux-theme="<name>"]`, `[data-bs-theme="dark"] [data-liveux-theme="<name>"]`, `.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="<name>"]` for dark mode):

- **Surfaces & Borders:** `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover`
- **Typography:** `--liveux-fm-font`, `--liveux-fm-mono`, `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`
- **Brand & State:** `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring`, `--liveux-fm-success`, `--liveux-fm-success-soft`, `--liveux-fm-success-border`, `--liveux-fm-warning`, `--liveux-fm-warning-soft`, `--liveux-fm-warning-border`, `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`
- **Geometry & Layering:** `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base`

## Unified 3-Tier SVG Icon Keys

### Tier 1 — File & Folder Icons (`<icon_dir>/<key>.svg`)
`folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`

### Tier 2 — 49 UI & Volume Provider Icons (`<icon_dir>/ui/<key>.svg`)
`archive`, `aws_s3`, `back`, `backblaze_b2`, `close`, `cloud`, `cloud_upload`, `cloudflare_r2`, `color`, `copy`, `cut`, `disk`, `download`, `duplicate`, `edit_image`, `exit_fullscreen`, `forward`, `fullscreen`, `gcs`, `grid`, `link`, `list`, `menu`, `minio`, `moon`, `more`, `move`, `new_folder`, `open`, `paste`, `preview`, `quota`, `refresh`, `rename`, `search`, `search_deep`, `select_all`, `settings`, `sftp`, `share`, `sort`, `sparkles`, `star`, `sun`, `supabase`, `tag`, `trash`, `up`, `upload`

*(Any extra `<name>.svg` added inside `<icon_dir>/ui/` can also be referenced directly via `volumes.<name>.icon: '<name>'`!)*

### Tier 3 — 14 Built-in Folder Badge Icons (`<icon_dir>/badges/<key>.svg`)
`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`

*(Any extra `<name>.svg` added inside `<icon_dir>/badges/` automatically appears in the Customize Folder icon picker!)*

## Twig Helper Signature

```twig
{{ liveux_fm_icon(key, iconSet, size, cssClass, group) }}
{# Example: #}
{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, 'liveux-fm-shrink-0', 'ui') }}
{{ liveux_fm_icon('bag', this.resolvedIconSet, 12, '', 'badges') }}
```
