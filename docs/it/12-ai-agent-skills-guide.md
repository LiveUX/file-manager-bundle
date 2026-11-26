# 12 — Guida alle AI Agent Skills (`SKILL.md` e `AGENTS.md`)

[← Precedente: 11 — Guida ai contributi e alle PR](11-contributing-and-pr-guide.md) | [Indice della documentazione](README.md) | [Successivo: 13 — Integrazione con Doctrine →](13-doctrine-integration.md)

> [!TIP]
> **In termini semplici: a cosa servono le Agent Skills (`SKILL.md`)?**
> Se utilizzi un assistente di programmazione AI come **Antigravity, Cursor, Claude Code, GitHub Copilot o OpenAI Codex**, esegui una sola volta `php bin/console file-manager:skills:install` nel tuo progetto. Questo comando insegna al tuo assistente le chiavi di configurazione YAML esatte del bundle, gli invarianti di sicurezza e gli esempi idiomatici — così genera codice funzionante al primo tentativo invece di inventare opzioni inesistenti.

**LiveUX FileManagerBundle** include un pacchetto di skill integrato che segue la specifica aperta **Agent Skills (`SKILL.md`)**, permettendo ai moderni assistenti di programmazione AI (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** e **Symfony AI Mate**) di integrare il bundle in modo preciso, sicuro e senza tentativi alla cieca.

---

## 1. Le 6 skill specializzate incluse nel bundle

Le skill sono organizzate secondo il principio di **progressive disclosure**: l'assistente AI carica solo la skill pertinente all'attività in corso, mantenendo snello e mirato l'uso della finestra di contesto:

| Nome della skill (`skills/<name>/`) | Quando si attiva | File di riferimento |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Installazione, routing, AssetMapper/Encore, profili e volumi (Local/S3/R2/MinIO/Supabase), quote (`storage_quota`, `show_quota`), ottimizzazione immagini, rilevamento duplicati | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (singolo/galleria), 5 varianti integrate, template Twig personalizzati per il FormType (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, JS API, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 temi integrati, Dark Mode (`color_scheme`), temi personalizzati solo CSS o Twig a 10 blocchi, sistema di icone SVG a 3 livelli (49 icone UI + 14 badge cartella) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | Firewall `security.yaml`, 17 permessi Voter, Voter personalizzati a livello di cartella (`unanimous`), Asset Usage Guard (`AssetUsageCheckerInterface` — eliminazione/rinomina/spostamento/sovrascrittura), link di condivisione firmati, Audit Log, hardening Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | `ConfigProviderInterface` dinamico su DB, `FolderMetadataStorageInterface` personalizzato, 9 listener per eventi Symfony, Symfony AI (`AssetEnricherInterface`) e tutti i comandi CLI | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Regole di sviluppo del bundle per compatibilità PHP 8.3 / Symfony 7.2–8.0, invarianti di sicurezza, parità delle traduzioni e quality gate PR `composer qa` | `AGENTS.md` (root del pacchetto) |

---

## 2. Tre modi per installare le skill nel tuo progetto

### Metodo A — Comando Symfony Console integrato (consigliato, zero dipendenze aggiuntive)

```bash
# Per Antigravity, Codex, OpenCode e Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Per Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Per Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Per GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Installa in tutti gli strumenti AI supportati con un solo comando
php bin/console file-manager:skills:install --target=all
```

### Tutte le opzioni del comando

| Opzione / Scorciatoia | Descrizione | Esempio |
| :--- | :--- | :--- |
| `-t` / `--target` | Strumento AI di destinazione (`agents`, `claude`, `cursor`, `copilot`, `all`). Valore predefinito: `agents`. | `-t all` |
| `--dir` | Percorso di destinazione personalizzato. **Regola di sicurezza:** deve essere rigorosamente una sottodirectory interna a `%kernel.project_dir%` (`..`, byte NUL, la root del progetto stessa e directory padre che sono symlink vengono rifiutati). | `--dir=.custom/skills` |
| `--only` | Installa solo le skill selezionate (nomi brevi o completi separati da virgola). | `--only=setup,security` |
| `-f` / `--force` | Sovrascrive le directory di skill esistenti con la versione più recente. | `--force` |
| `--symlink` | Crea collegamenti simbolici invece di copiare i file (su Windows esegue automaticamente il fallback alla copia se mancano i privilegi per i symlink). | `--symlink` |
| `--dry-run` | Mostra in anteprima quali skill verrebbero installate e in quale percorso, senza scrivere nulla su disco. | `--dry-run` |

### Metodo B — Rilevamento automatico con Symfony AI Mate (`symfony/ai-mate`)

Il file `composer.json` del bundle dichiara `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Se `symfony/ai-mate` è installato nel tuo progetto, un solo comando rileva e sincronizza tutte e 6 le skill nel tuo workspace:

```bash
vendor/bin/mate discover
```

### Metodo C — Open Agent Skills CLI (`npx skills`)

Installa direttamente da GitHub in qualsiasi progetto:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Il file `AGENTS.md` nella root del pacchetto

Il file `AGENTS.md` nella root del bundle fornisce regole sempre attive per gli agenti AI che contribuiscono direttamente allo sviluppo del bundle stesso:
- Zero dipendenze da framework CSS e regole per i bridge opzionali
- Tipizzazione stretta PHP (`declare(strict_types=1)`), stub di test compatibili con Symfony 7.2 / 8.0
- Divieto del filtro Twig `|raw`, uso obbligatorio di `isSafeUrl()` / `escapeHtml()` in JavaScript
- Validazione CSRF prioritaria, isolamento della cache per volumi privati / Voter e parità delle traduzioni al 100% su tutte le **12 lingue UI integrate** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Verifica automatica in CI

A ogni PR ed esecuzione CI, `SkillsPackageIntegrityTest` verifica automaticamente:
1. Che `AGENTS.md` esista, rispetti il budget di contesto **`<= 24,000 bytes`** e non contenga accidentalmente un frontmatter YAML.
2. Che `composer.json` definisca correttamente `extra.ai-mate.skills`.
3. Che ogni directory dentro `skills/` contenga un file `SKILL.md` con frontmatter YAML valido in cui `name` corrisponda esattamente al nome della directory, sia dichiarato `license: MIT`, `description` abbia almeno `40` caratteri e ogni file resti entro `<= 500` righe.
4. Che ogni link relativo `references/*.md` all'interno di `SKILL.md` punti a un file realmente esistente su disco.

---

[← Precedente: 11 — Guida ai contributi e alle PR](11-contributing-and-pr-guide.md) | [Indice della documentazione](README.md) | [Successivo: 13 — Integrazione con Doctrine →](13-doctrine-integration.md)
