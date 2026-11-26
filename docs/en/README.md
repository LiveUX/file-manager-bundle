<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Symfony UX File Manager and DAM" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — English Documentation

**Language:** **English** · [Türkçe](../tr/README.md) · [Français](../fr/README.md) · [Deutsch](../de/README.md) · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · [日本語](../ja/README.md) · [简体中文](../zh_CN/README.md) · [Main README](../../README.md)

**LiveUX FileManagerBundle** is a modern, high-performance, enterprise-grade **Symfony UX File Manager & Digital Asset Manager (DAM)** built for Symfony 6.4 LTS, 7.2+, and 8.x (PHP 8.3+). It ships with **zero CSS framework dependencies**, 14 layers of built-in security, and global multilingual support (12 built-in UI languages, BCP-47 / POSIX locale detection, and native RTL).

---

## Live Preview & Screenshots

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.shields.io/badge/YouTube-Watch_Full_14--Chapter_1080p_Showcase_(03:52)-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="Watch Full 14-Chapter 1080p Showcase on YouTube">
  </a>
</p>

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.youtube.com/vi/H2CAf0RiDd8/maxresdefault.jpg" alt="LiveUX FileManagerBundle — Full 14-Chapter 1080p YouTube Showcase" width="100%">
  </a>
</p>

<p align="center">
  <img src="../images/demo-showcase.gif" alt="LiveUX FileManagerBundle Interactive Demo" width="100%">
</p>

> **Full HD (1080p, ~3 min) 10-chapter showcase video** for YouTube and releases: [YouTube (1080p)](https://www.youtube.com/watch?v=H2CAf0RiDd8) · [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · Short preview: [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| Light Mode (`Light`) | Dark Mode (`Dark`) |
| :---: | :---: |
| ![Light Mode File Manager](../images/browser-light.png) | ![Dark Mode File Manager](../images/browser-dark.png) |

| Folder Color & Icon Customization | About Dialog (`LiveUX`) |
| :---: | :---: |
| ![Folder Color and Badge Customization](../images/folder-customize.png) | ![About Modal](../images/about-modal.png) |

### Rich-Text Editors, EasyAdmin & Dynamic Form Demos (GIF)

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![TinyMCE Integration](../images/demo-editor-tinymce.gif) | ![CKEditor 5 Integration](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2 (ProseMirror) | Quill 2 |
| :---: | :---: |
| ![Tiptap 2 Integration](../images/demo-editor-tiptap.gif) | ![Quill 2 Integration](../images/demo-editor-quill.gif) |

| Trix (EasyAdmin `TextEditorField`) | Summernote |
| :---: | :---: |
| ![Trix Integration](../images/demo-editor-trix.gif) | ![Summernote Integration](../images/demo-editor-summernote.gif) |

| Editor.js (Block Editor) | GrapesJS (Visual Page Builder) |
| :---: | :---: |
| ![Editor.js Integration](../images/demo-editor-editorjs.gif) | ![GrapesJS Integration](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5 (`FileManagerField`) |
| :---: | :---: |
| ![Meta Lexical Integration](../images/demo-editor-lexical.gif) | ![EasyAdmin 5 Integration](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` & `LiveCollectionType` |
| :---: |
| ![CollectionType and LiveCollectionType Integration](../images/demo-collections.gif) |

---

## 2-Minute Quickstart Roadmap

Not sure where to begin? Jump straight to the guide that matches your goal:

- **Installing for the first time:** [01 — Requirements & Installation](01-requirements-and-installation.md) → [02 — Configuration & Profiles](02-configuration-and-profiles.md)
- **Adding an image or gallery picker to a Symfony Form:** [05 — FormType & Editor Integrations](05-formtype-and-editor-integrations.md)
- **Locking down security & RBAC for production:** [06 — Security & Authorization](06-security-and-authorization.md)
- **Connecting Supabase, AWS S3, or Cloudflare R2:** [07 — Cloud Storage & Dynamic Config](07-cloud-storage-and-dynamic-config.md)
- **Using Doctrine ORM / DBAL metadata storage:** [13 — Doctrine Integration](13-doctrine-integration.md)
- **Preventing deletion or moving of assets referenced in your database:** [10 — Asset Usage Guard](10-asset-usage-guard.md)

---

## Table of Contents (Documentation Index)

| Chapter | Guide | Summary |
| :---: | :--- | :--- |
| **01** | [Requirements, Dependencies & Installation](01-requirements-and-installation.md) | PHP 8.3+ / Symfony 6.4 LTS, 7.2+ & 8.x requirements, PHP extensions (including `ext-exif`, `ext-zip`), Composer dependencies, AssetMapper & Webpack Encore setup, `file-manager:doctor`, and your first working page |
| **02** | [Configuration & Profiles (YAML Reference)](02-configuration-and-profiles.md) | `defaults`, `volumes`, `profiles`, collision-free dynamic paths (`{user_id}`, `{Y}/{m}`), upload/chunk limits, auto-naming, image optimization & constraints, thumbnails, trash, ZIP, 5 production-ready recipes, and the complete YAML schema reference |
| **03** | [Usage Modes & JavaScript API](03-usage-modes-and-js-api.md) | Inline `TwigComponent`, `<dialog>` Modal & 4-way OffCanvas, full `window.LiveUXFileManager.open()` reference, custom DOM events, RTL/IME/WCAG 2.1 AA accessibility, context menus, built-in image editor, and keyboard shortcuts |
| **04** | [Themes, Icon Sets & Customization](04-themes-icons-and-customization.md) | 9 WCAG 2.1 AA built-in themes (Bootstrap 5, Tailwind, Shadcn, Flowbite 4, DaisyUI 5, Tabler, Preline, Material, Unstyled), automatic/manual dark mode, 6 SVG icon sets (49 UI + 14 badge icons), Twig helpers, and 3 levels of custom styling |
| **05** | [FormType & Editor Integrations](05-formtype-and-editor-integrations.md) | `FileManagerPickerType` (single & accessible multi-image gallery sorting + POST security guard), 5 built-in variants (`compact`, `card`, `dropzone`, `avatar`, `input`), `CollectionType` / `LiveCollectionType`, custom templates, TinyMCE, CKEditor 5, Tiptap, and the 16-method EasyAdmin `FileManagerField` |
| **06** | [Security & Authorization (RBAC)](06-security-and-authorization.md) | 17 Voter permissions, custom `unanimous` Voters, `security.yaml` firewall rules, 14 defense layers (CSRF, path traversal, 69 extensions / 47 MIME types, 55-tag `SvgSanitizer`, SSRF DNS-rebinding guard, `ConfinedLocalFilesystemAdapter` symlink confinement, HMAC share links, audit logging), and Apache/Nginx hardening |
| **07** | [Cloud Storage (S3/R2/MinIO/Supabase) & Dynamic Config](07-cloud-storage-and-dynamic-config.md) | Multi-volume local/cloud architecture, provider guides ([Supabase](cloud/supabase-storage.md), [AWS S3](cloud/aws-s3.md), [Cloudflare R2](cloud/cloudflare-r2.md), [Backblaze B2](cloud/backblaze-b2.md), [MinIO/RustFS](cloud/minio-rustfs.md), [Google Cloud Storage](cloud/google-cloud-storage.md), [SFTP & Custom Flysystem](cloud/sftp-and-custom-flysystem.md)), `FlysystemServiceLocatorPass`, `ConfigProviderInterface`, and custom metadata storage |
| **08** | [Events, CLI Commands, Caching & Troubleshooting](08-events-commands-cache-and-troubleshooting.md) | 9 Symfony domain events, 10 console commands (`doctor`, `stats`, `duplicates`, `ai:enrich`, `skills:install`, etc.), 3-layer caching & context-aware HTTP 304/CDN isolation, 12-language global BCP-47/POSIX/RTL translation system (493 keys), and FAQ |
| **09** | [Symfony AI Integration & Smart Enrichment](09-symfony-ai-integration.md) | Zero-required-dependency `AssetEnricherInterface`, `symfony/ai-bundle` bridge (OpenAI, Gemini, Claude, Ollama), **✨ Suggest with AI**, batch `file-manager:ai:enrich` CLI, async Messenger examples, and 11-language LLM refusal & prompt-injection filtering |
| **10** | [Asset Usage Guard](10-asset-usage-guard.md) | Opt-in `AssetUsageCheckerInterface` contract, `warn` / `block` policies, two-layer server-side protection (`enforce_server_side`) for **`delete`, `rename`, `move`, and `overwrite`**, and Doctrine examples for MySQL, PostgreSQL, and SQLite |
| **11** | [Contributing & Pull Request Guide](11-contributing-and-pr-guide.md) | Beginner-friendly open-source PR workflow, `composer qa` quality gate (PHPStan Level 8, 360+ PHPUnit tests, 37+ JS tests), 6-job GitHub Actions CI, and GitHub Branch Protection setup |
| **12** | [AI Agent Skills Guide (`SKILL.md` & `AGENTS.md`)](12-ai-agent-skills-guide.md) | 6 modular `SKILL.md` packs for Antigravity, Claude Code, Cursor, GitHub Copilot, Codex, and Symfony AI Mate, one-command `file-manager:skills:install`, and CI validation |
| **13** | [Doctrine ORM & DBAL Integration](13-doctrine-integration.md) | Built-in `metadata.driver: 'doctrine'` (`DoctrineDbalMetadataStorage` — atomic UPSERT, PostgreSQL `text_pattern_ops` index, batch `saveMany()`), cross-platform migrations, Entity + `FileManagerPickerType`, custom `ResetInterface`-aware ORM storage, and `ItemRenamedEvent` DB sync |
| **14** | [Security Audit, Logic Review & Changelog Report](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(Turkish only)* | Line-by-line security audit (rounds 1–18), disjoint multi-tenant `{user_id}` isolation, DI/Voter/Parser/SVG `<use>` step-budget & CDN/origin-spoofing hardening, symlink confinement, PostgreSQL `text_pattern_ops` & atomic UPSERT, WCAG 2.1 AA 9-theme contrast/focus audit, mobile dialogs, and i18n audit log |

---

## Architectural Highlights

- **0ms Hybrid Speed:** Fast SSR `TwigComponent` + in-browser memory cache (`dirCache`) + single-request full folder tree. Filtering, search, sorting, pagination, Grid/List switching, and expanding/collapsing folders happen instantaneously without extra AJAX round-trips.
- **Zero CSS Framework Dependencies & WCAG 2.1 AA:** The entire UI uses scoped `.liveux-fm-*` classes and CSS custom properties (`--liveux-fm-*`). It runs cleanly alongside **Bootstrap 5, Tailwind CSS, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material**, or **no CSS framework at all**—with zero style collisions and no extra Tailwind purge configuration.
- **Native HTML5 `<dialog>` Top Layer & Mobile-First Ergonomics:** Modal and OffCanvas (`start`, `end`, `top`, `bottom`) dialogs render in the browser's Top Layer and automatically adapt on mobile to full-screen (`100dvh`) or a native bottom sheet with a drag handle.
- **14-Layer Enterprise Security:** Symfony `Voter` RBAC, CSRF protection (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `FileSecurityGuard`, 55-tag `SvgSanitizer`, DNS-rebinding-safe `RemoteUrlImporter`, `ConfinedLocalFilesystemAdapter` symlink confinement, decompression/pixel-bomb guards, and `CSP: sandbox` + `nosniff` headers enabled out of the box.
- **Global Multilingual Architecture:** **12 built-in UI languages** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` with 493 synchronized keys each), BCP-47 and POSIX `.UTF-8` locale normalization, guaranteed `'en'` fallback, automatic RTL (`dir="rtl"`) for Arabic and 14 other RTL languages, and CJK IME composition safety.
- **Multi-Server & Cloud Ready:** Built-in `DoctrineDbalMetadataStorage` (PostgreSQL `text_pattern_ops`, atomic UPSERT on MySQL/MariaDB/SQLite), Flysystem v3 (S3, R2, Supabase, B2, MinIO, GCS, SFTP), and `ResetInterface` compatibility for FrankenPHP and Symfony Messenger workers.

---

## 5-Minute Quickstart Checklist

1. **Install the bundle:** `composer require liveux/file-manager-bundle`
2. **Import routes:** `config/routes/liveux_file_manager.yaml`
3. **Define profiles:** `config/packages/liveux_file_manager.yaml`
4. **CSS & JS (Zero Config):** Symfony UX `autoimport` automatically loads `core.css`, `file_manager_api.js`, and all 3 Stimulus controllers via `assets/controllers.json` for both AssetMapper and Webpack Encore.
5. **Dialog host (Automatic):** `AutoInjectDialogHostSubscriber` automatically injects `DialogHost` right before `</body>` on full HTML pages (`defaults.auto_inject_dialog_host: true`)—no edits to `base.html.twig` required.
6. **Verify your setup:** `php bin/console file-manager:doctor`

> New to Symfony UX? Start step by step with **[01 — Requirements, Dependencies & Installation](01-requirements-and-installation.md)**.

---

**Organization:** [LiveUX on GitHub](https://github.com/LiveUX) · [Repository](https://github.com/LiveUX/file-manager-bundle)  
**License:** MIT
