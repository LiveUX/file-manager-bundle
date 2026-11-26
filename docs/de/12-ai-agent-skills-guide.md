# 12 — AI Agent Skills Leitfaden (`SKILL.md` & `AGENTS.md`)

[← Zurück: 11 — Contributing- & PR-Leitfaden](11-contributing-and-pr-guide.md) | [Dokumentationsübersicht](README.md) | [Weiter: 13 — Doctrine-Integration →](13-doctrine-integration.md)

> [!TIP]
> **Kurz erklärt: Wofür sind Agent Skills (`SKILL.md`) gedacht?**
> Wenn du einen KI-Coding-Assistenten wie **Antigravity, Cursor, Claude Code, GitHub Copilot oder OpenAI Codex** nutzt, führe einmalig `php bin/console file-manager:skills:install` in deinem Projekt aus. Dadurch lernt dein Assistent exakt die YAML-Konfigurationsschlüssel, Sicherheitsinvarianten und idiomatischen Beispiele des Bundles — und erzeugt beim ersten Versuch funktionierenden Code, statt ungültige Optionen zu halluzinieren.

**LiveUX FileManagerBundle** enthält ein integriertes Skill-Paket nach der offenen Spezifikation **Agent Skills (`SKILL.md`)**, damit moderne KI-Coding-Assistenten (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** und **Symfony AI Mate**) das Bundle präzise, sicher und ohne Rätselraten integrieren können.

---

## 1. Die 6 spezialisierten Skills im Bundle

Die Skills sind nach dem Prinzip **Progressive Disclosure** aufgebaut: Dein KI-Assistent lädt jeweils nur den Skill, der zu deiner aktuellen Aufgabe passt — so bleibt der Kontext schlank und fokussiert:

| Skill-Name (`skills/<name>/`) | Wann er aktiviert wird | Referenzdatei |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Installation, Routing, AssetMapper/Encore, Profile & Volumes (Local/S3/R2/MinIO/Supabase), Kontingente (`storage_quota`, `show_quota`), Bildoptimierung, Duplikaterkennung | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (Einzelauswahl/Galerie), 5 integrierte Varianten, eigene FormType-Twig-Templates (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, JS-API, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 integrierte Themes, Dark Mode (`color_scheme`), reine CSS- oder 10-Block-Custom-Twig-Themes, 3-stufiges SVG-Icon-System (49 UI-Icons + 14 Ordner-Badges) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | `security.yaml`-Firewall, 17 Voter-Berechtigungen, eigene ordnerbasierte Voter (`unanimous`), Asset Usage Guard (`AssetUsageCheckerInterface` — Löschen/Umbenennen/Verschieben/Überschreiben), signierte Freigabelinks, Audit-Log, Apache-/Nginx-Härtung | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | Dynamischer DB-`ConfigProviderInterface`, eigener `FolderMetadataStorageInterface`, 9 Symfony-Event-Listener, Symfony AI (`AssetEnricherInterface`) sowie alle CLI-Befehle | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Entwicklungsregeln für das Bundle zur Kompatibilität mit PHP 8.3 / Symfony 7.2–8.0, Sicherheitsinvarianten, Übersetzungsparität und das `composer qa`-PR-Qualitätstor | `AGENTS.md` (Paketwurzel) |

---

## 2. Drei Wege, die Skills in deinem Projekt zu installieren

### Methode A — Integrierter Symfony-Console-Befehl (empfohlen, keine zusätzlichen Abhängigkeiten)

```bash
# Für Antigravity, Codex, OpenCode und Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Für Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Für Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Für GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# In allen unterstützten KI-Tools mit einem einzigen Befehl installieren
php bin/console file-manager:skills:install --target=all
```

### Alle Befehlsoptionen

| Option / Kurzform | Beschreibung | Beispiel |
| :--- | :--- | :--- |
| `-t` / `--target` | Ziel-KI-Tool (`agents`, `claude`, `cursor`, `copilot`, `all`). Standard: `agents`. | `-t all` |
| `--dir` | Benutzerdefiniertes Zielverzeichnis. **Sicherheitsregel:** Muss zwingend ein Unterverzeichnis innerhalb von `%kernel.project_dir%` sein (`..`, NUL-Bytes, das Projektstammverzeichnis selbst sowie über Symlinks verknüpfte Elternverzeichnisse werden abgelehnt). | `--dir=.custom/skills` |
| `--only` | Nur ausgewählte Skills installieren (kommagetrennte Kurz- oder Vollständignamen). | `--only=setup,security` |
| `-f` / `--force` | Bestehende Skill-Verzeichnisse mit der neuesten Version überschreiben. | `--force` |
| `--symlink` | Symbolische Links erstellen statt Dateien zu kopieren (fällt unter Windows automatisch auf Kopieren zurück, falls Symlink-Rechte fehlen). | `--symlink` |
| `--dry-run` | Vorschau anzeigen, welche Skills wohin installiert würden, ohne etwas auf die Festplatte zu schreiben. | `--dry-run` |

### Methode B — Automatische Erkennung mit Symfony AI Mate (`symfony/ai-mate`)

Die `composer.json` des Bundles deklariert `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Wenn `symfony/ai-mate` in deinem Projekt installiert ist, erkennt und synchronisiert ein einziger Befehl alle 6 Skills in deinen Workspace:

```bash
vendor/bin/mate discover
```

### Methode C — Open Agent Skills CLI (`npx skills`)

Direkt von GitHub in jedes beliebige Projekt installieren:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. `AGENTS.md` im Paketstammverzeichnis

Die Datei `AGENTS.md` im Stammverzeichnis des Bundles enthält dauerhaft aktive Regeln für KI-Agenten, die direkt am Bundle selbst mitentwickeln:
- Keine CSS-Framework-Abhängigkeit und Regeln für optionale Bridges
- Strikte PHP-Typisierung (`declare(strict_types=1)`), Symfony-7.2-/-8.0-kompatible Test-Stubs
- Verbot von Twig `|raw`, verpflichtendes `isSafeUrl()` / `escapeHtml()` in JavaScript
- CSRF-Validierung an erster Stelle, Cache-Isolation für private Volumes und Voter sowie 100 % Übersetzungsparität über alle **12 integrierten UI-Sprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Automatisierte CI-Überprüfung

Bei jedem PR und jedem CI-Lauf prüft `SkillsPackageIntegrityTest` automatisch:
1. `AGENTS.md` existiert, bleibt innerhalb des Kontextbudgets von **`<= 24.000 Bytes`** und enthält nicht versehentlich YAML-Frontmatter.
2. `composer.json` definiert `extra.ai-mate.skills` korrekt.
3. Jedes Verzeichnis unter `skills/` enthält eine `SKILL.md` mit gültigem YAML-Frontmatter, bei dem `name` exakt dem Verzeichnisnamen entspricht, `license: MIT` deklariert ist, `description` mindestens `40` Zeichen lang ist und jede Datei das Limit von `<= 500` Zeilen einhält.
4. Jeder relative Link auf `references/*.md` innerhalb von `SKILL.md` verweist auf eine tatsächlich vorhandene Datei.

---

[← Zurück: 11 — Contributing- & PR-Leitfaden](11-contributing-and-pr-guide.md) | [Dokumentationsübersicht](README.md) | [Weiter: 13 — Doctrine-Integration →](13-doctrine-integration.md)
