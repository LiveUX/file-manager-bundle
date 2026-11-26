---
name: liveux-file-manager-theming-and-icons
description: >-
  Customizes LiveUX FileManagerBundle visual themes, Dark Mode, modular Twig
  layout blocks, CSS variables, z-index layering, and the 3-tier unified SVG
  icon system (file icons, 49 UI toolbar/action icons, and 14 folder badges).
  Use when creating a custom theme, overriding layout parts, tweaking dark mode,
  or replacing/adding SVG icons.
license: MIT
compatibility: PHP >= 8.3, Symfony ^6.4|^7.2|^8.0, Twig
metadata:
  author: liveux
  version: "0.1.0"
---

# LiveUX FileManagerBundle — Theming, Dark Mode & Icon Sets

Follow this guide to style, re-skin, or structurally override the File Manager UI without breaking Stimulus targets or CSP safety.

## 1. Choosing Built-in Themes & Dark Mode

Built-in themes: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`.

```yaml
liveux_file_manager:
    defaults:
        theme: 'shadcn'
        color_scheme: 'auto'   # auto (inherits [data-bs-theme="dark"], .dark, [data-theme="dark"]) | light | dark
        z_index: 1050          # Increase if host app uses higher fixed headers/modals
```

- Users can also switch **Auto / Light / Dark** per browser in the Settings popover (`[data-fm-color-scheme="dark"|"light"]`, persisted in `localStorage`).

## 2. Creating a Custom Theme (3 Levels)

### Level A — CSS-Only Custom Theme (Fastest, Zero Twig)
Set `theme: 'corporate'` in `liveux_file_manager.yaml` (no Twig file needed — falls back to base layout) and style `[data-liveux-theme="corporate"]`:

```css
[data-liveux-theme="corporate"] {
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-radius-shell: 12px;
    --liveux-fm-radius-card: 10px;
    --liveux-fm-radius-ctrl: 8px;
}
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"],
.dark [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"],
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"] {
    --liveux-fm-bg: #0f172a;
    --liveux-fm-surface-muted: #111c33;
    --liveux-fm-text: #f8fafc;
}
```

### Level B — Structural Layout Override via Custom `ui.theme` Template (Recommended for Structural Changes)
Because `FileManagerBundle::prependTwig()` registers `@LiveUXFileManager` via `twig.paths` (not Symfony's implicit bundle-override convention), placing templates under Symfony's bundle-override directory will **not** override `@LiveUXFileManager/*` and Symfony's `!LiveUXFileManager` self-parent syntax throws a `LoaderError`.

Instead, configure `ui.theme` to point to your own Twig layout template (or namespace directory ending in `layout.html.twig`) extending `'@LiveUXFileManager/base/layout.html.twig'`, and override **only** the blocks you need:

```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div class="my-custom-banner">Brand Media Vault</div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {# Custom footer while preserving Stimulus targets #}
    {{ parent() }}
{% endblock %}
```

```yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                theme: 'file_manager/corporate_layout.html.twig' # or '@App/file_manager' -> layout.html.twig
```

Available blocks: `fm_shell`, `fm_topbar`, `fm_settings_popover`, `fm_banners`, `fm_body`, `fm_sidebar`, `fm_main`, `fm_inspector`, `fm_footer`, `fm_overlays`.

### Level C — Modular Partial Customization inside a Custom `ui.theme` Layout
From your custom `ui.theme` layout template (which extends `@LiveUXFileManager/base/layout.html.twig`), replace any individual section block (`fm_topbar`, `fm_settings_popover`, `fm_sidebar`, `fm_main`, `fm_inspector`, `fm_footer`, `fm_overlays`) with your own `{% include %}` partial while keeping all required `data-{{ ctrl }}-target` attributes.

## 3. Customizing All Icons (3-Tier SVG Icon System)

Point `ui.icon_set` to any custom folder (e.g. `'%kernel.project_dir%/assets/fm-icons'`):

```text
assets/fm-icons/
├── folder.svg          # Tier 1: File/folder icons (folder, file, pdf, excel, word, zip, video, audio, code)
├── pdf.svg
├── ui/                 # Tier 2: Override any of the 49 UI icons (upload.svg, trash.svg, settings.svg, ...)
│   └── upload.svg
└── badges/             # Tier 3: Override or ADD new folder badge icons (shown in Customize Folder modal!)
    └── warehouse.svg
```

- Missing SVGs automatically fall back to the built-in set.
- Every SVG is sanitized by `SvgSanitizer` (server) and `sanitizeInlineSvg()` (browser).
- In custom Twig themes, always render icons via `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14) }}` — **never** with `|raw`.
- For bundle labels in custom Twig themes, prefer `{{ 'actions.upload'|liveux_fm_trans({}, loc) }}` over raw `|trans` so missing locales/keys always fall back cleanly to English (`en`) regardless of the host app's `framework.translator.fallbacks`.

See all CSS tokens, block names, 49 UI icon keys, and 14 built-in badge keys in [references/theme-tokens-and-blocks.md](references/theme-tokens-and-blocks.md).

## 4. Common Pitfalls

- **Never delete required `data-liveux--file-manager-bundle--browser-target="..."` attributes** when overriding Twig blocks — keep `{{ parent() }}` or preserve the target attributes used by `browser_controller.js`.
- **Dynamic `?theme=` URL query parameter** only accepts built-in themes (`FileManagerThemes::sanitizeDynamic()`). Custom themes must be configured in `liveux_file_manager.yaml` (never trusted from query strings).
- **`unstyled` theme contrast:** Always define `--liveux-fm-accent` and `--liveux-fm-accent-fg` as contrasting pairs (never `currentColor` as background).

## 5. Verification

```bash
php bin/console lint:twig templates/
php bin/console cache:clear
php bin/console asset-map:compile   # When editing CSS
```
