# 12 — Gids voor AI Agent Skills (`SKILL.md` & `AGENTS.md`)

[← Vorige: 11 — Bijdragen & PR-gids](11-contributing-and-pr-guide.md) | [Documentatie-overzicht](README.md) | [Volgende: 13 — Doctrine-integratie →](13-doctrine-integration.md)

> [!TIP]
> **In gewone taal: waar dienen Agent Skills (`SKILL.md`) voor?**
> Gebruik je een AI-codeerassistent zoals **Antigravity, Cursor, Claude Code, GitHub Copilot of OpenAI Codex**, voer dan eenmalig `php bin/console file-manager:skills:install` uit in je project. Daarmee leert je assistent de exacte YAML-configuratiesleutels, beveiligingsregels en idiomatische voorbeelden van de bundle — zodat hij meteen werkende code genereert in plaats van ongeldige opties te verzinnen.

**LiveUX FileManagerBundle** bevat een ingebouwd skillpakket volgens de open **Agent Skills (`SKILL.md`)**-specificatie, zodat moderne AI-codeerassistenten (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** en **Symfony AI Mate**) de bundle nauwkeurig, veilig en zonder giswerk kunnen integreren.

---

## 1. De 6 gespecialiseerde skills in de bundle

Skills zijn opgebouwd volgens **progressive disclosure**: je AI-assistent laadt alleen de skill die relevant is voor je huidige taak, waardoor het contextverbruik slank en gefocust blijft:

| Skillnaam (`skills/<name>/`) | Wanneer deze actief wordt | Referentiebestand |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Installatie, routing, AssetMapper/Encore, profielen & volumes (Local/S3/R2/MinIO/Supabase), quota's (`storage_quota`, `show_quota`), beeldoptimalisatie, duplicaatdetectie | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (enkel/galerij), 5 ingebouwde varianten, eigen FormType Twig-templates (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, JS API, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 ingebouwde thema's, Dark Mode (`color_scheme`), CSS-only of eigen Twig-thema's met 10 blokken, 3-laags SVG-icoonsysteem (49 UI-iconen + 14 mapbadges) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | `security.yaml`-firewall, 17 Voter-rechten, eigen Voters op mapniveau (`unanimous`), Asset Usage Guard (`AssetUsageCheckerInterface` — verwijderen/hernoemen/verplaatsen/overschrijven), ondertekende deellinks, Audit Log, Apache/Nginx-hardening | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | Dynamische DB `ConfigProviderInterface`, eigen `FolderMetadataStorageInterface`, 9 Symfony Event-listeners, Symfony AI (`AssetEnricherInterface`) en alle CLI-commando's | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Ontwikkelregels voor de bundle rond PHP 8.3 / Symfony 7.2–8.0-compatibiliteit, beveiligingsinvarianten, vertaalpariteit en de `composer qa` PR-poort | `AGENTS.md` (package-root) |

---

## 2. Drie manieren om de skills in je project te installeren

### Methode A — Ingebouwd Symfony Console-commando (aanbevolen, geen extra dependencies)

```bash
# Voor Antigravity, Codex, OpenCode en Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Voor Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Voor Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Voor GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Installeer in alle ondersteunde AI-tools met één commando
php bin/console file-manager:skills:install --target=all
```

### Alle commando-opties

| Optie / Sneltoets | Beschrijving | Voorbeeld |
| :--- | :--- | :--- |
| `-t` / `--target` | Doel-AI-tool (`agents`, `claude`, `cursor`, `copilot`, `all`). Standaard: `agents`. | `-t all` |
| `--dir` | Aangepast doelpad. **Beveiligingsregel:** Moet strikt een submap binnen `%kernel.project_dir%` zijn (`..`, NUL-bytes, de projectroot zelf en bovenliggende symlinks worden geweigerd). | `--dir=.custom/skills` |
| `--only` | Installeer alleen geselecteerde skills (komma-gescheiden korte of volledige namen). | `--only=setup,security` |
| `-f` / `--force` | Overschrijf bestaande skillmappen met de nieuwste versie. | `--force` |
| `--symlink` | Maak symbolische links in plaats van bestanden te kopiëren (valt op Windows automatisch terug op kopiëren als symlink-rechten ontbreken). | `--symlink` |
| `--dry-run` | Bekijk vooraf welke skills waar geïnstalleerd zouden worden, zonder iets naar schijf te schrijven. | `--dry-run` |

### Methode B — Automatische detectie met Symfony AI Mate (`symfony/ai-mate`)

De `composer.json` van de bundle declareert `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Als `symfony/ai-mate` in je project is geïnstalleerd, ontdekt en synchroniseert één commando alle 6 skills naar je workspace:

```bash
vendor/bin/mate discover
```

### Methode C — Open Agent Skills CLI (`npx skills`)

Installeer rechtstreeks vanaf GitHub in elk project:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. `AGENTS.md` op rootniveau

Het bestand `AGENTS.md` in de root van de bundle bevat altijd-actieve regels voor AI-agents die rechtstreeks bijdragen aan de bundle zelf:
- Geen CSS-frameworkdependency en regels voor optionele bridges
- Strikte PHP-typering (`declare(strict_types=1)`), teststubs die compatibel zijn met Symfony 7.2 / 8.0
- Verbod op Twig `|raw`, verplicht gebruik van `isSafeUrl()` / `escapeHtml()` in JavaScript
- CSRF-first validatie, cache-isolatie voor privébestanden/Voters en 100% vertaalpariteit over alle **12 ingebouwde UI-talen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Geautomatiseerde CI-verificatie

Bij elke PR en CI-run controleert `SkillsPackageIntegrityTest` automatisch:
1. Of `AGENTS.md` bestaat, binnen het contextbudget van **`<= 24.000 bytes`** blijft en niet per ongeluk YAML-frontmatter bevat.
2. Of `composer.json` `extra.ai-mate.skills` correct definieert.
3. Of elke map onder `skills/` een `SKILL.md` bevat met geldige YAML-frontmatter waarin `name` exact overeenkomt met de mapnaam, `license: MIT` is gedeclareerd, `description` minimaal `40` tekens telt en elk bestand binnen `<= 500` regels blijft.
4. Of elke relatieve `references/*.md`-link binnen `SKILL.md` verwijst naar een bestaand bestand op schijf.

---

[← Vorige: 11 — Bijdragen & PR-gids](11-contributing-and-pr-guide.md) | [Documentatie-overzicht](README.md) | [Volgende: 13 — Doctrine-integratie →](13-doctrine-integration.md)
