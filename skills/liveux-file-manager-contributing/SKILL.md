---
name: liveux-file-manager-contributing
description: >-
  Guides contributors and maintainers modifying liveux/file-manager-bundle
  itself: codebase layout, PHP 8.3 & Symfony 6.4/7.2/8.0 compatibility,
  security invariants, translation parity, Stimulus DOM security testing,
  esbuild assets/dist minification, and the Symfony-style QA & Pull Request gate.
  Use when editing bundle internals, fixing bugs, adding features, or opening a PR.
license: MIT
compatibility: PHP >= 8.3, Symfony ^6.4|^7.2|^8.0, Node.js >= 20, PHPUnit, PHPStan, PHP-CS-Fixer
metadata:
  author: liveux
  version: "0.1.0"
---

# LiveUX FileManagerBundle — Contributor & PR Workflow

Follow this checklist whenever modifying the bundle's own source code (`src/`, `assets/`, `templates/`, `translations/`, `resources/`, `skills/`, or `tests/`).

## 1. Codebase Map

- `src/Controller/` — HTTP entrypoints (`ApiController`, `UploadController`, `ImageEditorController`, `DownloadController`, `ThumbnailController`, `ShareController`, `ZipDownloadController`).
- `src/Security/` — `PathNormalizer`, `FileSecurityGuard`, `SvgSanitizer`, `CsrfRequestValidator`, `FileManagerVoter`, `FileManagerPermissions`.
- `src/Service/` — Core business logic (`DirectoryLister`, `FileOperationsService`, `UploadManager`, `TrashManager`, `StorageQuotaChecker`, `ZipArchiveService`, `RemoteUrlImporter`, `AssetUsageGuardService`, `AssetEnrichmentService`).
- `templates/base/layout.html.twig` + `templates/base/parts/_*.html.twig` — Modular Twig layout with 10 named blocks.
- `assets/controllers/` — `browser_controller.js`, `dialog_controller.js`, `picker_controller.js`; `assets/styles/core.css`.
- `resources/icons/` — 6 file icon sets + `_ui/` (49 UI icons) + `_folder_badges/` (14 folder badges).
- `translations/` — 12 built-in catalogs (`LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`) with 100% key, non-empty value, and `%placeholder%` parity.

## 2. Hard Invariants (PR Blockers)

1. **Strict PHP & Clean Complexity:** Every `.php` file starts with `declare(strict_types=1);`. Use strict comparisons (`===`/`!==`), braces on every control structure, and small private helper methods.
2. **Symfony 6.4 LTS + 7.2 + 8.0 Compatibility:** Never write anonymous classes directly implementing `AuthorizationCheckerInterface` (Symfony 8 added a 3rd `?AccessDecision` param to `isGranted()`). Always use PHPUnit `$this->createStub(AuthorizationCheckerInterface::class)`.
3. **Zero XSS / Zero `|raw`:** Never add Twig `|raw`, JS `eval`/`new Function`/`insertAdjacentHTML`, or inline `on*=` attributes. Escape dynamic HTML with `escapeHtml()`/`escapeAttr()`, guard URLs with `isSafeUrl()`, and keep `rel="noopener noreferrer"` on `_blank` links.
4. **CSRF-First & Per-Path Authorization:** Validate `CsrfRequestValidator::assertValid()` before reading files/payloads on POST routes. Check Voter permissions per individual path for bulk/ZIP actions. Never emit `Cache-Control: public` on private or Voter-protected profiles.
5. **100% 12-Locale Translation & Placeholder Parity:** Any new UI string must be added to all 12 locale YAML files (preserving `%placeholder%` tokens verbatim) AND exposed in `BrowserComponent::getI18nJson()` if rendered in JS.
6. **Zero CSS Framework Dependency & `assets/dist/` Sync:** Keep styling inside `assets/styles/core.css` using `.liveux-fm-*` classes and CSS custom properties. Whenever editing any JS or CSS file under `assets/`, run `npm --prefix assets run build` (or `composer assets:build`) and verify with `composer assets:check` so `assets/dist/` stays in sync.

## 3. Required QA Gate Before Opening a PR

Run inside the bundle root (or from a host Symfony workspace pointing PHPUnit at `tests/`):

```bash
composer qa
```

Which executes:
1. `composer validate --strict`
2. `composer cs:check` (fix with `composer cs:fix`)
3. `composer stan` (PHPStan static analysis)
4. `composer test` (PHPUnit — includes `TranslationParityTest`, `SecurityAuditHardeningTest`, `SkillsPackageIntegrityTest`)
5. `composer test:js:syntax` (`node --check` on all JS controllers & adapters)
6. `composer test:js` (`node --test tests/Js/dom_security_test.mjs tests/Js/frontend_audit_test.mjs`)

If testing inside a host Symfony app with compiled `public/assets/`, also run:
```bash
php bin/console lint:yaml translations/
php bin/console lint:twig templates/
php bin/console cache:clear
php bin/console asset-map:compile
```

## 4. Pull Request Checklist

- Fill out `.github/PULL_REQUEST_TEMPLATE.md` completely (BC breaks, New feature, Security impact, Tests added).
- Keep PRs focused on one logical change; include regression tests (PHPUnit and/or `dom_security_test.mjs` / `frontend_audit_test.mjs`) for every bug fix or security change.
- Update `CHANGELOG.md` and affected documentation/skills when changing config keys, public interfaces, or behavior.
