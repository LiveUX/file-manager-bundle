<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="docs/images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Zero-Dependency File Manager & DAM for Symfony UX" src="docs/images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle

Modern, high-performance **Symfony UX File Manager & Digital Asset Manager (DAM)** for **Symfony 6.4 LTS, 7.2+ and 8.x** (PHP 8.3+) with zero external CSS framework dependencies and 9 built-in WYSIWYG, block & page-builder adapters (**TinyMCE 5/6/7/8, CKEditor 5, Tiptap 2, Quill 2, Trix, Summernote, Editor.js, GrapesJS, Meta Lexical**).

**Documentation (12 Languages):** [English](docs/en/README.md) · [Türkçe](docs/tr/README.md) · [Français](docs/fr/README.md) · [Deutsch](docs/de/README.md) · [Español](docs/es/README.md) · [العربية](docs/ar/README.md) · [Italiano](docs/it/README.md) · [Português](docs/pt/README.md) · [Nederlands](docs/nl/README.md) · [Polski](docs/pl/README.md) · [日本語](docs/ja/README.md) · [简体中文](docs/zh_CN/README.md) · [Changelog](CHANGELOG.md)

## Interactive Preview

<p align="center">
  <img src="docs/images/demo-showcase.gif" alt="LiveUX FileManagerBundle Interactive Demo" width="100%">
</p>

> Full 10-chapter Full HD (1080p) walkthrough video for YouTube / Releases: [`docs/images/demo-youtube-showcase.mp4`](docs/images/demo-youtube-showcase.mp4) · Short 20s preview: [`docs/images/demo-showcase.mp4`](docs/images/demo-showcase.mp4)

## Highlights
- **Hybrid 0ms Speed:** Fast SSR TwigComponent + in-memory Stimulus cache + full folder tree loaded once + lightweight JSON API.
- **Native HTML5 `<dialog>` Top Layer:** Opens Modal & OffCanvas (`start|end|top|bottom`) above any modal without z-index or focus clashes.
- **9 Built-in Zero-Dependency Themes & Dark Mode:** `bootstrap5`, `tailwind`, `shadcn`, `flowbite` (Flowbite 4), `daisyui` (DaisyUI 5 variable bridge), `tabler`, `preline`, `material`, and `unstyled` + explicit/automatic Dark Mode (`auto | light | dark`).
- **3-Tier Unified SVG Icon System:** 6 MIT SVG icon sets (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`) + 49 overridable UI icons + 14+ folder badge icons via custom directories.
- **Built-in AI Agent Skills (`SKILL.md` & `AGENTS.md`):** 6 modular progressive-disclosure skills compatible with Antigravity, Claude Code, Cursor, GitHub Copilot, Codex, and Symfony AI Mate (`php bin/console file-manager:skills:install`).
- **5 FormType Picker Variants (`FileManagerPickerType`):** `compact`, `card`, `dropzone`, `avatar`, and `input` — single or multi-file gallery with per-field `theme` selection.
- **Enterprise Security:** Symfony `FileManagerVoter` RBAC, CSRF token validation (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `SvgSanitizer`, SSRF-safe `RemoteUrlImporter`, chunk upload bounds, `nosniff` + `CSP: sandbox` headers.
- **Full DAM Toolkit:** Chunked upload (Drag&Drop + `Ctrl+V` paste + optional Remote URL Import), inline Canvas Image Editor (Crop/Rotate/Flip), Trash/Recycle Bin, ZIP downloads, Folder Colors, Resizable Deep Tree, Fullscreen (`F`), Scoped Keyboard Shortcuts (`?`), `FileManagerPickerType` (Single & Multiple Gallery), TinyMCE Adapter, Web Debug Toolbar DataCollector, and CLI commands (`file-manager:doctor`, `cache:clear`, `thumbs:clear`, `trash:purge`).
- **Multilingual — 12 Built-in Languages + Native RTL:** `en`, `tr`, `fr`, `de`, `es`, `ar` (RTL), `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` — default `en`, configurable via YAML or request locale.

## Quick Install
```bash
composer require liveux/file-manager-bundle
```
See the [Installation Guide](docs/en/01-requirements-and-installation.md) for AssetMapper, Webpack Encore, routes, and configuration.

## Demo Applications (Symfony 6.4 LTS, 7.4 LTS & 8.1)
Explore 4 ready-to-run showcase applications (**Symfony 6.4 LTS**, **Symfony 7.4 LTS**, **Symfony 8.1 AssetMapper**, and **Symfony 8.1 Webpack Encore**) with all 9 themes, 6 icon sets, 12 languages (including Arabic RTL), 9 WYSIWYG/page-builder editors, `CollectionType`/`LiveCollectionType`, and EasyAdmin 4/5:
- **Demo Repository:** [LiveUX/file-manager-demo](https://github.com/LiveUX/file-manager-demo)

```bash
git clone https://github.com/LiveUX/file-manager-demo.git
cd file-manager-demo/symfony7   # or symfony64 | assetmapper | encore
composer install
php bin/console doctrine:schema:create
php -S 127.0.0.1:8094 -t public
```

## AI Agent Skills
Install the 6 bundled Agent Skills (`setup`, `forms-and-editors`, `theming-and-icons`, `security-and-rbac`, `extensions-and-ai`, `contributing`) into your coding assistant:
```bash
php bin/console file-manager:skills:install              # .agents/skills/ (Antigravity, Codex, Windsurf)
php bin/console file-manager:skills:install --target=all # .agents, .claude, .cursor, .github
```
Also auto-discovered by `symfony/ai-mate` (`extra.ai-mate.skills`) and `npx skills add LiveUX/file-manager-bundle`. See the [AI Agent Skills Guide](docs/en/12-ai-agent-skills-guide.md) ([Türkçe](docs/tr/12-yapay-zeka-skills-rehberi.md)).

## Organization
- **Organization:** [LiveUX on GitHub](https://github.com/LiveUX) · [Repository](https://github.com/LiveUX/file-manager-bundle)

## License
MIT License. See [LICENSE](LICENSE).
