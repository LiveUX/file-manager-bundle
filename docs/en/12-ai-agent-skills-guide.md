# 12 — AI Agent Skills Guide (`SKILL.md` & `AGENTS.md`)

[← Previous: 11 — Contributing & PR Guide](11-contributing-and-pr-guide.md) | [Documentation Index](README.md) | [Next: 13 — Doctrine Integration →](13-doctrine-integration.md)

> [!TIP]
> **In plain terms: what are Agent Skills (`SKILL.md`) for?**
> If you use an AI coding assistant such as **Antigravity, Cursor, Claude Code, GitHub Copilot, or OpenAI Codex**, run `php bin/console file-manager:skills:install` once in your project. It teaches your assistant the bundle's exact YAML configuration keys, security invariants, and idiomatic examples — so it generates working code on the first try instead of hallucinating invalid options.

**LiveUX FileManagerBundle** ships with a built-in skill package following the open **Agent Skills (`SKILL.md`)** specification so modern AI coding assistants (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf**, and **Symfony AI Mate**) can integrate the bundle accurately, securely, and with zero guesswork.

---

## 1. The 6 Specialized Skills Included in the Bundle

Skills are organized using **progressive disclosure**: your AI assistant loads only the skill relevant to your current task, keeping context usage lean and focused:

| Skill Name (`skills/<name>/`) | When It Activates | Reference File |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Installation, routing, AssetMapper/Encore, profiles & volumes (Local/S3/R2/MinIO/Supabase), quotas (`storage_quota`, `show_quota`), image optimization, duplicate detection | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (single/gallery), 5 built-in variants, custom FormType Twig templates (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, JS API, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 built-in themes, Dark Mode (`color_scheme`), CSS-only or 10-block custom Twig themes, 3-layer SVG icon system (49 UI icons + 14 folder badges) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | `security.yaml` firewall, 17 Voter permissions, custom folder-level Voters (`unanimous`), Asset Usage Guard (`AssetUsageCheckerInterface` — delete/rename/move/overwrite), signed share links, Audit Log, Apache/Nginx hardening | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | Dynamic DB `ConfigProviderInterface`, custom `FolderMetadataStorageInterface`, 9 Symfony Event listeners, Symfony AI (`AssetEnricherInterface`), and all CLI commands | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Bundle development rules for PHP 8.3 / Symfony 7.2–8.0 compatibility, security invariants, translation parity, and the `composer qa` PR gate | `AGENTS.md` (package root) |

---

## 2. Three Ways to Install the Skills in Your Project

### Method A — Built-In Symfony Console Command (Recommended, Zero Extra Dependencies)

```bash
# For Antigravity, Codex, OpenCode, and Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# For Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# For Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# For GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Install into all supported AI tools with a single command
php bin/console file-manager:skills:install --target=all
```

### All Command Options

| Option / Shortcut | Description | Example |
| :--- | :--- | :--- |
| `-t` / `--target` | Target AI tool (`agents`, `claude`, `cursor`, `copilot`, `all`). Default: `agents`. | `-t all` |
| `--dir` | Custom target directory path. **Security rule:** Must strictly be a subdirectory inside `%kernel.project_dir%` (`..`, NUL bytes, the project root itself, and symlinked parent directories are rejected). | `--dir=.custom/skills` |
| `--only` | Install only selected skills (comma-separated short or full names). | `--only=setup,security` |
| `-f` / `--force` | Overwrite existing skill directories with the latest version. | `--force` |
| `--symlink` | Create symbolic links instead of copying files (automatically falls back to copying on Windows if symlink privileges are unavailable). | `--symlink` |
| `--dry-run` | Preview which skills would be installed and where, without writing anything to disk. | `--dry-run` |

### Method B — Automatic Discovery with Symfony AI Mate (`symfony/ai-mate`)

The bundle's `composer.json` declares `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

If `symfony/ai-mate` is installed in your project, a single command discovers and syncs all 6 skills into your workspace:

```bash
vendor/bin/mate discover
```

### Method C — Open Agent Skills CLI (`npx skills`)

Install directly from GitHub into any project:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Root-Level `AGENTS.md`

The `AGENTS.md` file at the bundle root provides always-active rules for AI agents contributing directly to the bundle itself:
- Zero CSS framework dependency and optional bridge rules
- Strict PHP typing (`declare(strict_types=1)`), Symfony 7.2 / 8.0 compatible test stubs
- Ban on Twig `|raw`, mandatory `isSafeUrl()` / `escapeHtml()` in JavaScript
- CSRF-first validation, private/Voter cache isolation, and 100% translation parity across all **12 built-in UI languages** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Automated CI Verification

On every PR and CI run, `SkillsPackageIntegrityTest` automatically verifies:
1. `AGENTS.md` exists, stays within the **`<= 24,000 bytes`** context budget, and does not accidentally contain YAML frontmatter.
2. `composer.json` correctly defines `extra.ai-mate.skills`.
3. Every directory under `skills/` contains a `SKILL.md` with valid YAML frontmatter where `name` matches the directory name exactly, `license: MIT` is declared, `description` is at least `40` characters, and each file stays within `<= 500` lines.
4. Every relative `references/*.md` link inside `SKILL.md` points to an existing file on disk.

---

[← Previous: 11 — Contributing & PR Guide](11-contributing-and-pr-guide.md) | [Documentation Index](README.md) | [Next: 13 — Doctrine Integration →](13-doctrine-integration.md)
