<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/LiveUX/file-manager-bundle/main/docs/images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/LiveUX/file-manager-bundle/main/docs/images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Zero-Dependency File Manager & DAM for Symfony UX" src="https://raw.githubusercontent.com/LiveUX/file-manager-bundle/main/docs/images/banner-light.png" width="100%">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/LiveUX/file-manager-bundle/actions/workflows/ci.yml"><img src="https://github.com/LiveUX/file-manager-bundle/actions/workflows/ci.yml/badge.svg" alt="CI Status"></a>
  <a href="https://packagist.org/packages/liveux/file-manager-bundle"><img src="https://img.shields.io/packagist/v/liveux/file-manager-bundle?style=flat-square&color=0EA5E9" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/liveux/file-manager-bundle"><img src="https://img.shields.io/packagist/dt/liveux/file-manager-bundle?style=flat-square&color=6366F1" alt="Total Downloads"></a>
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony-6.4%20LTS%20%7C%207.x%20%7C%208.x-000000?style=flat-square&logo=symfony" alt="Symfony 6.4 LTS | 7.x | 8.x"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+"></a>
  <a href="https://github.com/LiveUX/file-manager-bundle/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-MIT-10B981?style=flat-square" alt="MIT License"></a>
</p>

# LiveUX FileManagerBundle

Modern, high-performance **Symfony UX File Manager & Digital Asset Manager (DAM)** for **Symfony 6.4 LTS, 7.2+ and 8.x** (PHP 8.3+) — with **zero external CSS framework dependencies**, **9 built-in themes**, **6 SVG icon sets**, **12 native languages (including Arabic RTL)**, **EasyAdmin 4/5**, and **9 built-in WYSIWYG, block & page-builder adapters**.

**Documentation (12 Languages):**
[English](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/en/README.md) · [Türkçe](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/tr/README.md) · [Français](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/fr/README.md) · [Deutsch](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/de/README.md) · [Español](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/es/README.md) · [العربية](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/ar/README.md) · [Italiano](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/it/README.md) · [Português](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/pt/README.md) · [Nederlands](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/nl/README.md) · [Polski](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/pl/README.md) · [日本語](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/ja/README.md) · [简体中文](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/zh_CN/README.md) · [Changelog](https://github.com/LiveUX/file-manager-bundle/blob/main/CHANGELOG.md)

---

## Interactive Preview & Full HD Video Walkthrough

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
  <img src="https://raw.githubusercontent.com/LiveUX/file-manager-bundle/main/docs/images/demo-showcase.gif" alt="LiveUX FileManagerBundle Interactive Demo" width="100%">
</p>

> **Full 14-Chapter 1080p Walkthrough:** [Watch on YouTube](https://www.youtube.com/watch?v=H2CAf0RiDd8)

---

## Feature Matrix

| Category | Capabilities |
| :--- | :--- |
| **Themes & Dark Mode** | **9 built-in zero-dependency themes**: `bootstrap5`, `tailwind`, `shadcn`, `flowbite` (Flowbite 4), `daisyui` (DaisyUI 5), `tabler`, `preline`, `material`, and `unstyled` + `auto \| light \| dark` Dark Mode |
| **3-Tier SVG Icon System** | **6 MIT SVG icon sets** (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`) + 49 overridable UI icons + 14+ folder badge icons |
| **12 Languages + Native RTL** | `en`, `tr`, `fr`, `de`, `es`, `ar` (full RTL layout & keyboard inversion), `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` |
| **9 Editors + EasyAdmin 4/5** | **TinyMCE 5/6/7/8, CKEditor 5, Tiptap 2, Quill 2, Trix, Summernote, Editor.js, GrapesJS, Meta Lexical** + native EasyAdmin `FileManagerField` |
| **5 FormType Picker Variants** | `FileManagerPickerType` (`compact`, `card`, `dropzone`, `avatar`, `input`) — single or reorderable multi-file gallery + `CollectionType` & `LiveCollectionType` |
| **Multi-Volume Cloud Storage** | Powered by **League Flysystem v3**: Local, **AWS S3, Cloudflare R2, Supabase Storage, Google Cloud Storage (GCS), MinIO, Backblaze B2, Azure Blob, SFTP** |
| **Full DAM & Media Toolkit** | Drag & Drop + `Ctrl+V` paste chunked uploads, Remote URL Import (SSRF-safe), **Inline Canvas Image Editor** (Crop/Rotate/Flip), PDF/Video/Audio/Code preview, Focal Point, Trash/Recycle Bin, ZIP archive download & safe unzip, Duplicate Detection, Storage Quota |
| **Hybrid 0ms Architecture** | SSR TwigComponent + Stimulus controller + Compact JSON (`compact=1`) + Smart Hybrid Pagination (`<= 500` items 0ms client-side, `> 500` server-side) + Native HTML5 `<dialog>` Top-Layer Modal & OffCanvas (`start \| end \| top \| bottom`) |
| **Enterprise Security** | Symfony `FileManagerVoter` RBAC, CSRF (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `ConfinedLocalFilesystemAdapter` symlink confinement, `SvgSanitizer`, `Content-Security-Policy: sandbox`, `X-Content-Type-Options: nosniff`, signed expiring Share Links, Audit Logging |
| **AI & Agent Skills** | Optional **Symfony AI** vision/text auto-tagging & alt-text enrichment + **6 bundled AI Agent Skills** (`SKILL.md` / `AGENTS.md`) for Antigravity, Claude Code, Cursor, GitHub Copilot, Codex & Symfony AI Mate |

---

## Quick Start

### 1. Install via Composer
```bash
composer require liveux/file-manager-bundle
```

### 2. Import Routes (`config/routes/liveux_file_manager.yaml`)
```yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

### 3. Configure a Volume & Profile (`config/packages/liveux_file_manager.yaml`)
```yaml
liveux_file_manager:
    defaults:
        theme: tailwind # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: en
    volumes:
        uploads:
            label: 'Media Library'
            driver: LocalFileSystem
            path: '%kernel.project_dir%/public/uploads'
            public_url: '/uploads'
    profiles:
        default:
            volumes: [uploads]
```

### 4. Render Anywhere in Twig or Symfony Forms
```twig
{# Inline Full File Manager Browser #}
<twig:LiveUX:FileManager:Browser profile="default" height="680px" />
```

```php
// Inside any Symfony FormType
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;

$builder->add('coverImage', FileManagerPickerType::class, [
    'profile' => 'default',
    'variant' => 'card', // compact | card | dropzone | avatar | input
]);
```

See the complete [Installation Guide](https://github.com/LiveUX/file-manager-bundle/blob/main/docs/en/01-requirements-and-installation.md) for AssetMapper, Webpack Encore, Cloud S3/R2, RBAC Voter, and Editor integrations.

---

## Demo Applications (Symfony 6.4 LTS, 7.4 LTS & 8.1)

Explore **4 ready-to-run showcase applications** in [LiveUX/file-manager-demo](https://github.com/LiveUX/file-manager-demo):

| Demo App | Symfony Version | Asset Pipeline | EasyAdmin |
| :--- | :--- | :--- | :--- |
| `symfony64/` | **Symfony 6.4 LTS** | AssetMapper (Zero-Build) | EasyAdmin 4 |
| `symfony7/` | **Symfony 7.4 LTS** | AssetMapper (Zero-Build) | EasyAdmin 4 |
| `assetmapper/` | **Symfony 8.1** | AssetMapper (Zero-Build) | EasyAdmin 5 |
| `encore/` | **Symfony 8.1** | Webpack Encore | EasyAdmin 5 |

```bash
git clone https://github.com/LiveUX/file-manager-demo.git
cd file-manager-demo/symfony7   # or symfony64 | assetmapper | encore
composer install
php bin/console doctrine:schema:create
php -S 127.0.0.1:8094 -t public
```

---

## AI Agent Skills

Install the 6 bundled Agent Skills (`setup`, `forms-and-editors`, `theming-and-icons`, `security-and-rbac`, `extensions-and-ai`, `contributing`) into your coding assistant:
```bash
php bin/console file-manager:skills:install              # .agents/skills/ (Antigravity, Codex, Windsurf)
php bin/console file-manager:skills:install --target=all # .agents, .claude, .cursor, .github
```
Also auto-discovered by `symfony/ai-mate` (`extra.ai-mate.skills`) and `npx skills add LiveUX/file-manager-bundle`.

---

## Organization & License

- **Organization:** [LiveUX on GitHub](https://github.com/LiveUX) · [Repository](https://github.com/LiveUX/file-manager-bundle) · [Demo Apps](https://github.com/LiveUX/file-manager-demo)
- **License:** MIT License. See [LICENSE](https://github.com/LiveUX/file-manager-bundle/blob/main/LICENSE).
