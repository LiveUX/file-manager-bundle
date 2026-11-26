# Contributing to LiveUX FileManagerBundle

Thank you for your interest in contributing to **LiveUX FileManagerBundle**!
This project follows **Symfony UX** engineering, security, and quality standards so that every Pull Request is safe, maintainable, and backward-compatible.

> **Türkçe rehber mi arıyorsunuz?** Hiç açık kaynak PR tecrübesi olmayanlar için adım adım Türkçe rehber: [docs/tr/11-katki-ve-pr-rehberi.md](docs/tr/11-katki-ve-pr-rehberi.md).

---

## Core Architecture Principles

Before writing code, please keep these 5 non-negotiable bundle principles in mind:

1. **Zero CSS Framework Lock-In:** All UI styles live in `assets/styles/core.css` using isolated `.liveux-fm-*` classes and CSS custom properties. Never rely on Bootstrap or Tailwind utility classes inside bundle templates, and verify both light and dark modes.
2. **Zero Mandatory Vendor Lock-In:** Optional integrations (Symfony AI, AWS S3, EasyAdmin, Doctrine usage checkers) must be strictly **opt-in** via PHP interfaces (`AssetEnricherInterface`, `AssetUsageCheckerInterface`, `ConfigProviderInterface`, `FolderMetadataStorageInterface`).
3. **Strict CSP & XSS Defense-in-Depth:**
   - Never use inline event handlers (`onclick=`, `onerror=`, etc.) in Twig or JS.
   - Never use Twig's `|raw` filter in bundle templates.
   - Always escape dynamic strings with `this.escapeHtml()` / `this.escapeAttr()` in Stimulus and validate URLs with `this.isSafeUrl()`.
   - Every `target="_blank"` link must include `rel="noopener noreferrer"`.
4. **Strict PHP 8.3+ Typing:** Every PHP file must begin with `declare(strict_types=1);`, use strict comparisons (`===`, `!==`), wrap control structures in braces, and keep methods small and modular.
5. **100% Translation Parity:** Every new UI text key must be added to **all** translation catalogs (`translations/LiveUXFileManagerBundle.{en,tr,fr}.yaml`). Our automated `TranslationParityTest` enforces this on every PR.

---

## Step-by-Step Pull Request Workflow

### 1. Fork & Clone
```bash
git clone https://github.com/<your-username>/file-manager-bundle.git
cd liveux-file-manager-bundle
composer install
```

### 2. Create a Descriptive Branch
Never commit directly to `main`. Use a clear prefix:
- `fix/...` for bug fixes (e.g., `fix/folder-count-stale-cache`)
- `feat/...` for new features (e.g., `feat/usage-guard-checker`)
- `docs/...` for documentation updates

```bash
git checkout -b feat/my-improvement
```

### 3. Implement Your Changes & Write Tests
- **Bug fix?** Write a regression test that fails before your fix and passes after.
- **New PHP feature?** Add unit tests under `tests/Unit/...`.
- **UI / Stimulus / HTML change?** Add or update assertions in `tests/Js/dom_security_test.mjs`.

### 4. Run the Full Local QA Suite (Required Before Opening a PR)
Run a single command to execute every check that GitHub Actions CI will run:

```bash
composer qa
```

This runs:
| Check | Command | What it verifies |
| :--- | :--- | :--- |
| Composer validation | `composer validate --strict` | Valid package metadata & schema |
| Coding standards | `composer cs:check` | PSR-12 + Symfony style (`composer cs:fix` auto-fixes!) |
| Static analysis | `composer stan` | PHPStan Level 6 type safety |
| PHP test suite | `composer test` | PHPUnit (security, storage, services, i18n parity, strict_types) |
| JS syntax check | `composer test:js:syntax` | Valid ES module syntax across all Stimulus controllers & adapters |
| JS DOM security | `composer test:js` | Node.js XSS, URL safety & modal DOM tests |

> **Note:** If you are developing inside a host Symfony app (monorepo/path repository), run PHPUnit via `php vendor/bin/phpunit bundles/liveux-file-manager-bundle/tests` and `node --test bundles/liveux-file-manager-bundle/tests/Js/dom_security_test.mjs`, then recompile assets with `php bin/console asset-map:compile` if `public/assets/` exists.

### 5. Commit & Open a Pull Request
Use clear, focused commits (Conventional Commits style recommended):
```bash
git add -A
git commit -m "feat: add pre-delete AssetUsageCheckerInterface guard"
git push origin feat/my-improvement
```

Then open a Pull Request on GitHub targeting `main` and fill out the **Symfony-style PR table & checklist**.
All CI checks (`PHP 8.3/8.4`, `Symfony 7.2/8.0`, `Node 20/22`, `PHPStan`, `PHP-CS-Fixer`) must pass before a maintainer reviews and merges your PR.

---

## Need Help?
If you are unsure about an architectural decision before investing time in a large PR, please open a **Feature Request** issue first so we can agree on the design together!
