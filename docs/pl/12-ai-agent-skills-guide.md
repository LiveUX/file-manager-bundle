# 12 — Przewodnik po umiejętnościach agentów AI (`SKILL.md` i `AGENTS.md`)

[← Poprzedni: 11 — Współtworzenie i przewodnik po PR](11-contributing-and-pr-guide.md) | [Spis treści dokumentacji](README.md) | [Następny: 13 — Integracja z Doctrine →](13-doctrine-integration.md)

> [!TIP]
> **W prostych słowach: do czego służą Agent Skills (`SKILL.md`)?**
> Jeśli korzystasz z asystenta kodowania AI takiego jak **Antigravity, Cursor, Claude Code, GitHub Copilot czy OpenAI Codex**, uruchom raz w swoim projekcie polecenie `php bin/console file-manager:skills:install`. Nauczy ono Twojego asystenta dokładnych kluczy konfiguracji YAML tego bundla, założeń bezpieczeństwa oraz idiomatycznych wzorców — dzięki czemu wygeneruje działający kod za pierwszym razem, zamiast halucynować nieistniejące opcje.

**LiveUX FileManagerBundle** zawiera wbudowany pakiet umiejętności zgodny z otwartą specyfikacją **Agent Skills (`SKILL.md`)**, dzięki czemu nowoczesne asystenty kodowania AI (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** oraz **Symfony AI Mate**) mogą integrować ten bundle precyzyjnie, bezpiecznie i bez zgadywania.

---

## 1. 6 wyspecjalizowanych umiejętności zawartych w bundlu

Umiejętności zostały zorganizowane zgodnie z zasadą **stopniowego ujawniania kontekstu (*progressive disclosure*)**: asystent AI wczytuje wyłącznie umiejętność właściwą dla bieżącego zadania, utrzymując zużycie kontekstu na minimalnym, precyzyjnym poziomie:

| Nazwa umiejętności (`skills/<name>/`) | Kiedy się aktywuje | Plik referencyjny |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Instalacja, routing, AssetMapper/Encore, profile i wolumeny (Local/S3/R2/MinIO/Supabase), limity przestrzeni (`storage_quota`, `show_quota`), optymalizacja obrazów, wykrywanie duplikatów | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (pojedynczy plik/galeria), 5 wbudowanych wariantów, własne szablony Twig dla FormType (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, JS API, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 wbudowanych motywów, tryb ciemny (`color_scheme`), własne motywy czysto CSS lub 10-blokowe motywy Twig, 3-warstwowy system ikon SVG (49 ikon UI + 14 plakietek folderów) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | Firewall `security.yaml`, 17 uprawnień Votera, własne Votery na poziomie folderów (`unanimous`), ochrona używanych zasobów (`AssetUsageCheckerInterface` — usuwanie/zmiana nazwy/przenoszenie/nadpisywanie), podpisane linki udostępniania, dziennik audytu (Audit Log), zabezpieczanie Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | Dynamiczny bazodanowy `ConfigProviderInterface`, własny `FolderMetadataStorageInterface`, 9 nasłuchiwaczy zdarzeń Symfony Event, Symfony AI (`AssetEnricherInterface`) oraz wszystkie komendy CLI | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Reguły rozwoju bundla pod kątem kompatybilności z PHP 8.3 / Symfony 7.2–8.0, założeń bezpieczeństwa, spójności tłumaczeń oraz bramki PR `composer qa` | `AGENTS.md` (katalog główny pakietu) |

---

## 2. Trzy sposoby instalacji umiejętności w projekcie

### Metoda A — Wbudowana komenda Symfony Console (zalecana, zero dodatkowych zależności)

```bash
# Dla Antigravity, Codex, OpenCode oraz Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Dla Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Dla Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Dla GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Instalacja we wszystkich obsługiwanych narzędziach AI jednym poleceniem
php bin/console file-manager:skills:install --target=all
```

### Wszystkie opcje komendy

| Opcja / Skrót | Opis | Przykład |
| :--- | :--- | :--- |
| `-t` / `--target` | Docelowe narzędzie AI (`agents`, `claude`, `cursor`, `copilot`, `all`). Domyślnie: `agents`. | `-t all` |
| `--dir` | Własna ścieżka katalogu docelowego. **Reguła bezpieczeństwa:** musi być ściśle podkatalogiem wewnątrz `%kernel.project_dir%` (`..`, bajty NUL, sam katalog główny projektu oraz nadrzędne katalogi będące dowiązaniami symbolicznymi są odrzucane). | `--dir=.custom/skills` |
| `--only` | Instaluje tylko wybrane umiejętności (oddzielone przecinkami nazwy skrócone lub pełne). | `--only=setup,security` |
| `-f` / `--force` | Nadpisuje istniejące katalogi umiejętności najnowszą wersją. | `--force` |
| `--symlink` | Tworzy dowiązania symboliczne zamiast kopiować pliki (w systemie Windows automatycznie przełącza się na kopiowanie, jeśli brak uprawnień do tworzenia symlinków). | `--symlink` |
| `--dry-run` | Podgląd tego, które umiejętności i gdzie zostałyby zainstalowane, bez zapisywania czegokolwiek na dysk. | `--dry-run` |

### Metoda B — Automatyczne wykrywanie z Symfony AI Mate (`symfony/ai-mate`)

Plik `composer.json` bundla deklaruje sekcję `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Jeśli w projekcie zainstalowany jest pakiet `symfony/ai-mate`, jedno polecenie wykrywa i synchronizuje wszystkie 6 umiejętności z przestrzenią roboczą:

```bash
vendor/bin/mate discover
```

### Metoda C — Otwarte CLI Agent Skills (`npx skills`)

Instalacja bezpośrednio z GitHuba w dowolnym projekcie:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Plik `AGENTS.md` w katalogu głównym

Plik `AGENTS.md` umieszczony w katalogu głównym bundla zawiera zawsze aktywne zasady dla agentów AI współtworzących bezpośrednio sam bundle:
- Zero zależności od frameworków CSS oraz reguły opcjonalnych mostków (*bridges*)
- Ścisłe typowanie PHP (`declare(strict_types=1)`), zaślepki testowe (*stubs*) kompatybilne z Symfony 7.2 / 8.0
- Zakaz używania filtra Twig `|raw`, obowiązkowe stosowanie `isSafeUrl()` / `escapeHtml()` w JavaScript
- Walidacja CSRF w pierwszej kolejności, izolacja pamięci podręcznej dla zasobów prywatnych i chronionych Voterami oraz 100% kompletności tłumaczeń we wszystkich **12 wbudowanych językach interfejsu** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Automatyczna weryfikacja na CI

W każdym Pull Requeście i uruchomieniu CI test `SkillsPackageIntegrityTest` automatycznie sprawdza, czy:
1. Plik `AGENTS.md` istnieje, mieści się w limicie kontekstu **`<= 24,000 bytes`** i nie zawiera przypadkowego frontmattera YAML.
2. Plik `composer.json` poprawnie definiuje `extra.ai-mate.skills`.
3. Każdy katalog w `skills/` zawiera plik `SKILL.md` z poprawnym frontmatterem YAML, w którym pole `name` dokładnie odpowiada nazwie katalogu, zadeklarowano `license: MIT`, pole `description` ma co najmniej `40` znaków, a plik mieści się w limicie `<= 500` linii.
4. Każdy względny link `references/*.md` wewnątrz `SKILL.md` wskazuje na istniejący plik na dysku.

---

[← Poprzedni: 11 — Współtworzenie i przewodnik po PR](11-contributing-and-pr-guide.md) | [Spis treści dokumentacji](README.md) | [Następny: 13 — Integracja z Doctrine →](13-doctrine-integration.md)
