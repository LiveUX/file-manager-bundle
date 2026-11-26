# 11 — Contributing & Pull Request (PR) Guide (Step-by-Step for Beginners)

[← Previous: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Documentation Index](README.md) | [Next: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)

This guide is written from the ground up for developers who have **never opened a Pull Request (PR) to an open-source project before**, as well as maintainers who want to set up a **Symfony-style automated PR quality gate** in their own repository. Every concept and command is explained step by step.

---

## 1. The Big Picture: How Open-Source Pull Requests Work

In major open-source projects like Symfony, Laravel, or React, external contributors cannot push directly to the main codebase (`main` branch). Instead, every change passes through a **4-stage safety gate**:

1. **Fork & Branch (Get Your Own Copy):** Copy the repository to your own GitHub account (*Fork*) and create a dedicated working branch (*Branch*).
2. **Code & Local Checks (`composer qa`):** Implement your change, write tests, and run the full automated quality suite on your machine.
3. **Open a Pull Request (PR):** Submit a request asking maintainers to review and merge your bug fix or feature into the main repository. GitHub automatically presents a **Symfony-style PR summary table and checklist** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Automated CI Checks (GitHub Actions) + Code Review:**
   - The moment a PR is opened or updated, GitHub Actions (`.github/workflows/ci.yml`) spins up cloud runners automatically.
   - It tests the code across **PHP 8.3 & 8.4**, **Symfony 7.2, 7.4 & 8.0**, and **Node 20 & 22**, and enforces coding standards (**PHP-CS-Fixer**), static type safety (**PHPStan Level 8**), translation completeness (**TranslationParityTest**), YAML documentation schema accuracy (**FullConfigReferenceDocTest**), Agent Skills integrity (**SkillsPackageIntegrityTest**), and XSS/CSP/frontend security (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **If even a single check fails, GitHub blocks the Merge button.**

---

## 2. Is the Quality Infrastructure Already Set Up?

**Yes — 100% configured and ready to use.** The bundle ships with a complete quality toolchain modeled directly after official Symfony UX standards:

| File | Purpose (In Plain Terms) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Defines the one-command `composer qa` script suite and standalone dev dependencies (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Runs all PHP unit and security tests (**57 test files, 360 tests, 3,500+ assertions**) standalone without requiring a host application. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Configures **PHPStan Level 8** static analysis to catch hidden type mismatches, invalid arguments, and undefined method calls before runtime. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Enforces PSR-12 + Symfony coding style across all PHP files (`declare(strict_types=1)`, strict `===`, alphabetically sorted `use` imports). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Runs **6 parallel CI jobs** automatically on every new or updated Pull Request. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Pre-populates every new PR with a Symfony-style summary table (`Bug fix?`, `BC breaks?`, `Tests pass?`) and checklist. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Provides structured forms for bug reports (`bug_report.yml`) and feature requests (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | International contributor guidelines automatically linked by GitHub on every new PR and Issue screen. |
| [`SECURITY.md`](../../SECURITY.md) | Security disclosure policy explaining how to report vulnerabilities privately instead of opening a public issue. |

### 7 Critical Rules Enforced Automatically

Even if a human reviewer misses one of the issues below, **the automated test suite catches it and fails the PR immediately**:

1. **Missing Translations (`TranslationParityTest`):** If a new UI string is added to English but missing from any of the **12 built-in UI languages** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), the test **FAILS**.
2. **Missing `strict_types` (`TranslationParityTest`):** If even a single PHP file under `src/` omits `declare(strict_types=1);`, the test **FAILS**.
3. **XSS / CSP / Frontend Security Violations (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Adding inline handlers like `onclick="..."` or `|raw` in Twig, or breaking `javascript:` URL / DOM sanitization in JS, causes the test to **FAIL**.
4. **Documentation YAML Schema Drift (`FullConfigReferenceDocTest`):** All `liveux_file_manager:` YAML blocks in the [Configuration & Profiles](02-configuration-and-profiles.md) reference (`docs/tr/02-yapilandirma-ve-profiller.md`) are validated against the real `Configuration.php` schema.
5. **Agent Skills Integrity (`SkillsPackageIntegrityTest`):** Validates frontmatter, line/size budgets, and reference links across `AGENTS.md` and all 6 `skills/*/SKILL.md` files.
6. **Code Style & Static Types (`PHP-CS-Fixer` + `PHPStan Level 8`):** Any PSR-12/Symfony style violation or Level 8 type error blocks CI.
7. **Minified `assets/dist/` Synchronization (`npm --prefix assets run build:check`):** If you edit `assets/controllers/*.js` or `assets/styles/core.css` and forget to rebuild `assets/dist/`, CI **FAILS**.

---

## 2.5. JS & CSS Minification (`assets/dist/`) Architecture (`esbuild`)

> [!NOTE]
> **Why does this exist?**
> - **Webpack Encore** users get automatic JS/CSS minification when running `npm run build`.
> - **Symfony AssetMapper** (`asset-map:compile`), however, serves files as-is without minifying them. In raw source form, `browser_controller.js` is `518 KB` and `core.css` is `132 KB`.
> - To ensure fast page loads for AssetMapper users without requiring Node.js in host projects, we follow the exact **Source → Distribution (`assets/dist/`)** pattern used by official Symfony UX packages (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### How It Works

1. **Readable Source Files:** All development and security tests run against cleanly formatted, commented source files:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` and `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` and `assets/images/liveux-logo.webp`
2. **Minified Production Output (`assets/dist/`):** The [`assets/build.mjs`](../../assets/build.mjs) script uses **`esbuild`** to minify each JS file as a standalone ESM module (keeping `@hotwired/stimulus` external and preserving relative imports) and compiles `core.css` into `assets/dist/`.
3. **Automatic Resolution (Zero Breaking Changes):**
   - `prependAssetMapper()` in [`FileManagerBundle.php`](../../src/FileManagerBundle.php) automatically maps the `@liveux/file-manager-bundle` namespace to `assets/dist/` whenever `assets/dist` exists.
   - The `"exports"` and `"symfony.controllers.*.main"` entries in [`assets/package.json`](../../assets/package.json) ensure Webpack Encore consumes the `dist/` files directly as well.
   - Because `assets/dist/` is committed to Git, **end users never need Node.js or npm**. `.gitattributes` excludes only build tooling (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) from Packagist archives while keeping `assets/package.json` and `assets/dist/` included.

### Measured Size Savings

| File | Raw Source | Minified (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 editor adapters | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **TOTAL (`-45.1%` minified)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Developer Commands (Whenever You Edit JS or CSS)

```bash
# 1. Install esbuild once (first time only):
npm --prefix assets install

# 2. Rebuild assets/dist/ after editing any JS or CSS file (~50 ms):
npm --prefix assets run build
# or via the Composer shortcut:
composer assets:build

# 3. Verify that assets/dist/ is 100% in sync with source files (same check run by CI):
composer assets:check
```

---

## 3. Step-by-Step Guide to Submitting a Pull Request

> [!TIP]
> **Even if you have never submitted a PR before**, follow these 6 steps in order to open a clean, CI-passing Pull Request.

### Step 1: Fork, Clone, and Install Dependencies

1. Open [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) on GitHub and click **Fork** in the top-right corner to create a copy under your own GitHub account.
2. Clone your fork locally, add the main repository as `upstream`, and install the PHP and Node dev tools:

```bash
git clone https://github.com/<YOUR-USERNAME>/file-manager-bundle.git
cd file-manager-bundle

# Track the main repository as upstream (great habit for staying up to date)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Install PHP test & static analysis dependencies
composer install

# Install the JS/CSS minifier (esbuild) if you plan to edit JS/CSS files
npm --prefix assets install
```

### Step 2: Create a New Branch from an Up-to-Date `main`

Never commit directly to `main`. Sync `main` first, then create a descriptive branch for your work:
- Bug fix: `fix/folder-counter-cache`
- New feature: `feat/new-icon-set`
- Documentation: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Step 3: Write Code, Add Tests, and Rebuild `dist/` if Needed

- **Fixing a bug:** Start by writing a focused regression test that reproduces the bug (red before the fix, green after).
- **Adding a feature:**
  - Add PHP unit tests under `tests/Unit/...`.
  - For UI/JS changes, add coverage to `tests/Js/dom_security_test.mjs` or `tests/Js/frontend_audit_test.mjs`.
  - **Whenever you touch JS or CSS, run `composer assets:build`** (otherwise CI will fail at `build:check`).
  - Whenever you add a new UI translation key, add it to **all 12 built-in UI language catalogs**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Step 4: Run Every Quality Gate Locally in One Shot

Run the exact same checks CI executes before opening your PR:

```bash
composer qa
composer assets:check
```

These commands verify, in order:
1. `composer validate --strict` — Validates `composer.json`.
2. `composer cs:check` — Checks PSR-12/Symfony coding standards *(if issues are reported, run `composer cs:fix` to auto-format)*.
3. `composer stan` — Runs **PHPStan Level 8** static analysis.
4. `composer test` — Runs the full PHPUnit unit, security, translation parity, and schema test suite.
5. `composer test:js:syntax` — Runs `node --check` syntax validation across all source and `dist/` JS files.
6. `composer test:js` — Runs Node.js DOM security and frontend audit suites (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Confirms `assets/dist/` matches the current source files byte for byte.

> [!TIP]
> **All green?** You are ready to commit and open your Pull Request.

### Step 5: Create a Clean Commit and Push to Your Fork

```bash
git status            # Verify only intended files are modified
git diff --stat       # Review a quick summary of your changes
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Use clear Conventional Commit prefixes: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Step 6: Open the Pull Request on GitHub and Watch CI Turn Green

1. Visit your fork on GitHub (`https://github.com/<YOUR-USERNAME>/file-manager-bundle`) and click the **"Compare & pull request"** banner.
2. Confirm the targets: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<YOUR-USERNAME>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Fill out the auto-populated **PR template** table (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) and check off the checklist items (`[x]`). If your change affects the UI, attach a screenshot or GIF.
4. Click **"Create pull request"**.
5. Wait for all GitHub Actions checks (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) at the bottom of the PR page to display a **green checkmark (✓)**.
6. If a reviewer requests changes, edit the files on the same branch and run `git commit && git push` — GitHub updates the PR and re-runs CI automatically.

---

## 4. For Maintainers: Enforcing Branch Protection & Rulesets on GitHub

Once the workflow files are in the repository, CI runs automatically — but to guarantee **nobody (not even a maintainer by accident) can click Merge while checks are red**, configure a GitHub **Ruleset** in 2 minutes:

1. Open your bundle repository on GitHub and go to **Settings**.
2. In the left sidebar, select **Rules → Rulesets** (or **Branches → Add branch protection rule** in the classic view).
3. Click **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Enable the following 4 protections:
   - [x] **Restrict deletions** (prevents deleting `main`)
   - [x] **Block force pushes** (protects commit history)
   - [x] **Require a pull request before merging** (blocks direct pushes to `main`; all changes arrive via PR)
     - *Required approvals:* `1` (recommended for teams; solo maintainers can leave this at `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(most important setting)*
     - [x] *Require branches to be up to date before merging*
     - After the first CI run completes, use **Add checks** to require all **6 jobs**:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Click **Create** at the bottom of the page.

---

## 5. Checklist for Publishing the Bundle as a Standalone Open-Source Repository

To publish the bundle as `LiveUX/file-manager-bundle` on GitHub and Packagist:

1. Create a new empty **Public** repository named `file-manager-bundle` under the `LiveUX` GitHub organization.
2. Push the contents of `bundles/liveux-file-manager-bundle/` as the initial commit (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Verify in the **GitHub Actions** tab that all 6 CI jobs pass with green checkmarks.
4. Enable the **Ruleset** settings from **Section 4** above.
5. Tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) and submit `https://github.com/LiveUX/file-manager-bundle` on [Packagist.org](https://packagist.org).
6. Anyone can now install the bundle with a single command: `composer require liveux/file-manager-bundle`.

---

[← Previous: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Documentation Index](README.md) | [Next: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)
