# 04 — Themes, Dark Mode, Folder Customization, Icon Sets & Custom Design

[← Previous: 03 — Usage Modes & JS API](03-usage-modes-and-js-api.md) | [Index](README.md) | [Next: 05 — FormType & Editor Integrations →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle is engineered with **100% zero CSS framework dependencies**. Whether your project uses **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material**, or **no CSS framework at all**, it works out of the box with no extra build steps (no Tailwind `@source` or purge configuration required).

> **At a Glance: What You'll Learn in This Guide**  
> 1. **9 Built-In Themes & Dark Mode:** Match your application's visual design and color scheme with a single YAML option.  
> 2. **Folder Coloring & Folder Icons (Badges):** Customize folders from the UI or programmatically in PHP/DB using 18+ colors and 14 built-in badges (plus unlimited custom SVG badges).  
> 3. **Creating Your Own Custom Icon Set:** Step-by-step instructions to build your own icon pack covering 9 file icons, 49 UI icons, and unlimited custom folder badges.  
> 4. **Building a Custom Theme (3 Levels of Freedom):** Brand theming with pure CSS custom properties, partial Twig overrides via `{% extends %}`, or a 100% headless layout from scratch.

---

## 1. 9 Built-In Themes (Which One Should You Choose?)

Themes can be configured via `defaults.theme`, `profiles.<name>.ui.theme`, the TwigComponent `theme` prop, or the `FileManagerPickerType` `theme` option. **All 9 themes are verified against WCAG 2.1 AA contrast standards in both light and dark modes.**

| Theme Key | Best Fit For | Design Summary |
| :--- | :--- | :--- |
| `bootstrap5` *(default)* | Bootstrap 5 and classic Symfony admin panels | Classic blue (`#0d6efd`), `10px / 8px / 6px` radii, clean neutral borders |
| `tailwind` | Modern web apps built with Tailwind CSS v3/v4 | Indigo (`#6366f1`) accent, smooth `14px / 12px / 8px` radii, Slate palette |
| `shadcn` | Minimalist, monochrome black-and-white SaaS dashboards | Monochrome Zinc surfaces (`#18181b` / `#fafafa` in dark mode), crisp `8px / 6px` edges |
| `flowbite` | Admin panels using Flowbite 4 + Tailwind CSS | Flowbite blue (`#1a56db`) & Gray-50/800/900 surfaces, `8px` controls |
| `daisyui` | Projects built with DaisyUI 5 | **Automatically inherits** `--color-primary`, `--color-base-100/200/300`, and `--radius-box` whenever DaisyUI is loaded on the page |
| `tabler` | Tabler.io or enterprise EasyAdmin backends | Enterprise slate canvas, `#066fd1` blue accent, sharp `4px / 6px` corners |
| `preline` | Preline UI applications | Pure neutral surfaces (`#fafafa` / `#171717`) with vibrant blue (`#2563eb`) accent |
| `material` | Google Material Design 3 applications | Tonal purple surfaces (`#6750a4` / `#f3edf7`) and fully rounded pill buttons |
| `unstyled` | Styling the file manager entirely with your own CSS | Inherits the host app's font and transparent surfaces while maintaining opaque `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` contrast for popovers and lightboxes |

| Dark Mode (`bootstrap5` + Dark) | DaisyUI 5 Theme Bridge (`daisyui`) |
| :---: | :---: |
| ![Dark Mode File Manager](../images/browser-dark.png) | ![DaisyUI 5 Theme Showcase](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Security Rule (YAML vs. Runtime `theme`):**  
> - In `liveux_file_manager.yaml`, you can configure any of the 9 built-in themes, your own custom CSS theme name (e.g., `'corporate'`, matching `/^[a-zA-Z0-9_-]{1,40}$/`), or a custom Twig template path (e.g., `'file_manager/custom.html.twig'` or `'@App/file_manager'`).  
> - For security reasons, dynamic `theme` values coming from the browser, URL query parameters, or `window.LiveUXFileManager.open({ theme: ... })` **only accept the 9 built-in theme keys** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Dark Mode, RTL & Reduced Motion

All 9 themes (including `unstyled`) support **both automatic and manual Dark Mode**:

### A) Automatic Host Detection (`color_scheme: 'auto'`)
Whenever your page (`<html>` or `<body>`) has any of the following active, the file manager, modal `<dialog>`, and `FileManagerPickerType` fields immediately switch to dark mode:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- `.dark` class *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / custom themes)*

### B) Default or Per-Profile Color Scheme via YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (follow host) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Start only this profile in dark mode
```

### C) End-User Preference (Settings Menu: `Auto / Light / Dark`)
Users can switch between **Auto / ☀ Light / 🌙 Dark** with a single click from the **⚙ Settings** popover in the top toolbar. Their preference is persisted in browser `localStorage` (`liveux_fm_color_scheme`).

### D) Global RTL & `prefers-reduced-motion`
- `dir="rtl"` is automatically applied for 15 right-to-left locales including Arabic (`ar`), Persian (`fa`), Hebrew (`he`), and Urdu (`ur`). All spacing in `core.css` uses CSS logical properties (`inset-inline`, `padding-inline`, `border-inline-end`).
- Animations are automatically minimized for users who have *"Reduce Motion"* (`prefers-reduced-motion: reduce`) enabled in their operating system.

---

## 3. Folder Coloring & Folder Icon (Badge) Assignment (Step-by-Step)

![Folder Color Palette and Badge Customization Modal](../images/folder-customize.png)

You can assign a custom **color**, **badge icon**, and **pinned status (⭐ Pin)** to any folder. These features are active when `defaults.metadata.enabled: true` and the user has the `permissions.edit_metadata` permission.

### Method 1: From the UI (For End Users)
1. **Right-click** any folder and select **Customize** (or press **`I`** while a folder is selected, or click **Customize** in the right-hand Inspector panel).
2. In the **Customize** modal, configure three options with a live preview:
   - **18-Color Preset Palette:** Blue (`#2563eb`), Sky (`#0284c7`), Cyan (`#0891b2`), Teal (`#0d9488`), Emerald (`#059669`), Green (`#16a34a`), Lime (`#65a30d`), Amber (`#d97706`), Orange (`#ea580c`), Red (`#dc2626`), Rose (`#e11d48`), Pink (`#db2777`), Fuchsia (`#c026d3`), Purple (`#9333ea`), Violet (`#7c3aed`), Indigo (`#4f46e5`), Slate (`#475569`), Charcoal (`#1e293b`).
   - **Custom Color Picker + HEX Input:** Pick any brand color via the native color picker or type a `#RRGGBB` hex code directly. Click **Reset to Default** to clear custom styling.
   - **Folder Icon (Badge) Grid:** Displays all **14 built-in badges** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`) along with any custom SVG files you add to your icon set's `badges/` directory.
3. Click **Save** — the folder updates instantly in both the Grid/List view and the left folder tree.

### Method 2: Programmatically from PHP (e.g., When Creating a New Customer Folder)
Using `FolderMetadataStorageInterface` and `PathNormalizer::buildCanonicalKey()`, you can assign colors, badges, and pins directly from PHP code (the exact same code works seamlessly with both the `json_file` and `doctrine` drivers):

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // Build canonical key (e.g., "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Teal (#RRGGBB)
            icon: 'lock',       // One of the 14 built-in badges or custom badges/invoice.svg -> 'invoice'
            pinned: true,       // Add to ⭐ Pinned Folders in the sidebar
        ));
    }
}
```

### Method 3: Directly in `metadata.json` (When Using `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Icon Sets & Building Your Own Icon Set from Scratch

**Every icon** in the bundle — file types, folders, top toolbar, context menu, cloud disk indicators, inspector panel, and folder badges — is served from a single unified `IconSetRegistry`. The 6 built-in icon sets are cached in PSR-6 for 24 hours (`liveux_fm_iconset_*`).

### 6 Built-In Icon Sets (`profiles.<name>.ui.icon_set`)
| Icon Set | Visual Style | Default Folder Color (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(default)* | Flat, crisp vector icons | `#2563eb` |
| `windows11` | Windows 11-style dual-tone warm yellow folders and modern document icons | `#f59e0b` |
| `macos` | macOS Finder-inspired soft blue folders | `#3b82f6` |
| `linux` | Clean open-source GNOME / Adwaita aesthetic | `#3584e4` |
| `minimal` | Thin-stroke (outline) minimalist icons | `#475569` |
| `vscode` | Sharp, developer-oriented VS Code-style icons | `#dcb67a` |

### Step-by-Step: Creating Your Own Custom Icon Set

#### Step 1: Create an Icon Directory in Your Project
For example, create `assets/fm-icons/`. **You don't need to copy icons you don't want to change** — any missing icon automatically falls back to the built-in `classic` / `_ui` / `_folder_badges` sets!

```text
assets/fm-icons/
├── meta.json               # Default folder color: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Optional) Custom main folder icon
├── pdf.svg                 # (Optional) Override only the PDF icon
├── ui/                     # (Optional) Override or add toolbar, context menu, and volume icons
│   ├── upload.svg          # Override the Upload icon
│   ├── new_folder.svg      # Override the New Folder icon
│   └── company_cloud.svg   # Add a new custom volume icon -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Optional) Override existing folder badges or add NEW ones
    ├── lock.svg            # Override the built-in "lock" badge
    └── invoice.svg         # Add a NEW badge -> automatically appears in the Customize Folder modal!
```

#### Step 2: Create `meta.json` and a Dynamic-Color `folder.svg`

> [!TIP]
> **Golden Rule (`currentColor`):** To ensure your custom `folder.svg` automatically adapts whenever a user changes a folder's color, use **`fill="currentColor"`** (or `stroke="currentColor"` for outline icons) instead of hardcoded hex colors!

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/invoice.svg (New custom folder badge) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Step 3: Point Your YAML Profile to the Directory
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Company Archive'
            icon: 'company_cloud' # Uses assets/fm-icons/ui/company_cloud.svg!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Custom Icon Security & Limits (`IconSetRegistry`)
- Each `.svg` file may be at most **256 KB** (`262,144` bytes) and is automatically cleaned through **`SvgSanitizer`** on load (stripping `<script>`, `on*` handlers, etc.).
- Both `ui/` and `badges/` scan up to **200 custom `.svg`** files in addition to the built-in keys.
- Custom filenames (excluding `.svg`) must match **`/^[a-zA-Z0-9_-]{1,32}$/`** (ASCII letters, digits, `-`, and `_`, max 32 characters).

#### Complete Reference of Overridable Icon Keys
- **9 File & Folder Icons (root `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 UI Icons (`<dir>/ui/<key>.svg`):**
  - *Navigation & Layout:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *File & Folder Actions:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Storage & Cloud Providers:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 Built-In Folder Badges (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(plus any custom `*.svg` files you add!)*

---

## 5. Building Your Own Custom Theme (3 Levels of Freedom)

`Browser.html.twig` resolves the `theme` value configured on your profile in this **exact priority order**:
1. Ends with `.html.twig` → Renders your Twig template directly (`{% include theme %}`).
2. Starts with `@` → Renders `layout.html.twig` inside that Twig namespace (`{% include theme ~ '/layout.html.twig' %}`).
3. Matches one of the 9 built-in themes → Renders the bundle's built-in theme.
4. Any other short identifier (e.g., `'corporate'`, matching `/^[a-zA-Z0-9_-]{1,40}$/`) → Wraps the default base layout with `<div data-liveux-theme="corporate">`.

### Level 1: Brand Theme with Pure CSS Variables (Fastest — Zero Templates!)

#### Complete CSS Custom Properties Reference (`--liveux-fm-*`)
| Category | CSS Variables |
| :--- | :--- |
| **Typography** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Surfaces & Borders** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Text Colors** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Brand Accent Colors** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Semantic Status Colors (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Radii & Shadows** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Copy-Paste Custom Brand Theme (`assets/styles/app.css`)
```css
/* 1) Light Mode Brand Palette */
[data-liveux-theme="corporate"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) Dark Mode Counterpart (Covers both Browser and FormType Picker, auto & manual dark mode) */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"],
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'corporate'
```

---

### Level 2: Partial Overrides with Twig `{% extends %}` (Recommended!)

The base layout is structured around **10 `{% block %}` slots** and **7 modular partials (`parts/`)**. You can customize just the top bar or footer without duplicating the entire template:

| Block Name | Scope | Default Partial |
| :--- | :--- | :--- |
| `fm_shell` | Outer shell wrapper (`.liveux-fm-shell`) | Wraps all nested blocks |
| `fm_topbar` | Top command bar (navigation, breadcrumbs, search, sort, actions) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Settings dropdown (color mode, item size, folder style, cache, shortcuts) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Status notification banner + upload queue | Inline markup |
| `fm_body` | Main center area (sidebar + main + inspector wrapper) | `.liveux-fm-body` |
| `fm_sidebar` | Left folder tree + pinned folders + quota bar + trash | `parts/_sidebar.html.twig` |
| `fm_main` | File grid/list + floating selection/clipboard bar + dropzone + pagination | `parts/_main.html.twig` |
| `fm_inspector` | Right-hand preview & metadata inspector panel | `parts/_inspector.html.twig` |
| `fm_footer` | Modal / OffCanvas footer bar | `parts/_footer.html.twig` |
| `fm_overlays` | Context menu, modal, lightbox, and shortcuts/about templates | `parts/_overlays.html.twig` |

#### Example — Branded Header Strip + Custom Selection Footer
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Company Digital Asset Hub</span>
        <span>Profile: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Cancel</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Confirm Selection</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) Direct .html.twig template path:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) Or a Twig namespace directory (automatically loads layout.html.twig inside it):
                # theme: '@App/file_manager'
```

---

### Level 3: 100% Headless Layout from Scratch (Required Stimulus Targets)

If you want to build your own HTML structure completely from scratch without `{% extends %}`, simply wire these `data-{{ ctrl }}-target` attributes using the prefix `ctrl = 'liveux--file-manager-bundle--browser'`:

| Target (`data-...-target`) | Required? | Purpose |
| :--- | :---: | :--- |
| `shell` | Recommended | Item size scale (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Required** | Main canvas where file/folder cards and list rows are rendered |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Required** | Dialog container for New Folder, Upload, Delete, Customize, Quota, and Metadata modals |
| `breadcrumbs` | Recommended | Clickable path breadcrumb trail |
| `confirmSelectionBtn`, `footerStatus` | Recommended (Modal/OffCanvas) | Selection confirm button (automatically `disabled` when nothing is selected) and status readout |
| `statusBanner`, `uploadQueue` | Recommended | Error/success notices and chunked upload progress queue |
| `tree` + `sidebar` + `pinnedFolders` | Optional | Left folder tree and pinned folders |
| `contextMenu` | Optional | Right-click context menu container |
| `inspector` | Optional | Right-hand preview & metadata panel |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Optional | Universal media preview lightbox |

---

## 6. 7 Twig Helper Functions for Custom Templates

| Twig Function | Purpose | Example Usage |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Renders an XSS-safe inline SVG (no `\|raw` needed). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Generates a secure thumbnail URL for a file. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Generates a secure download or inline preview URL. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Download</a>` |
| `liveux_fm_trans(key, params, locale)` | Translates a bundle message key with a guaranteed `'en'` fallback. | `{{ liveux_fm_trans('ui.upload', {}, 'en') }}` |
| `liveux_fm_bcp47_locale(locale)` | Converts PHP/ICU locale codes like `en_US.UTF-8` into valid HTML `lang="..."` BCP-47 tags (`en-US`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Returns `true` if the given locale is right-to-left (Arabic, Persian, Hebrew, etc.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Safely normalizes Form/EasyAdmin values into renderable image/file descriptors (blocking `javascript:`/`data:` URLs). | EasyAdmin Index/Detail views and custom templates |

---

[← Previous: 03 — Usage Modes & JS API](03-usage-modes-and-js-api.md) | [Index](README.md) | [Next: 05 — FormType & Editor Integrations →](05-formtype-and-editor-integrations.md)
