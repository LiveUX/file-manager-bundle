# 11 — Guida ai contributi e alle Pull Request (PR) (Passo dopo passo per principianti)

[← Precedente: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Indice della documentazione](README.md) | [Successivo: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)

Questa guida è pensata da zero sia per gli sviluppatori che **non hanno mai aperto una Pull Request (PR) su un progetto open-source**, sia per i maintainer che desiderano configurare nel proprio repository un **quality gate automatico per le PR in stile Symfony**. Ogni concetto e ogni comando è spiegato passo dopo passo.

---

## 1. Il quadro generale: come funzionano le Pull Request open-source

Nei grandi progetti open-source come Symfony, Laravel o React, i collaboratori esterni non possono fare push diretto sul codice principale (branch `main`). Ogni modifica attraversa invece un **processo di sicurezza in 4 fasi**:

1. **Fork e Branch (ottieni la tua copia):** Copia il repository sul tuo account GitHub (*Fork*) e crea un branch di lavoro dedicato (*Branch*).
2. **Sviluppo e controlli locali (`composer qa`):** Implementa la modifica, scrivi i test ed esegui l'intera suite automatica di controllo qualità sulla tua macchina.
3. **Apertura della Pull Request (PR):** Invia una richiesta ai maintainer per revisionare e integrare (merge) la tua correzione di bug o nuova funzionalità nel repository principale. GitHub propone automaticamente una **tabella riepilogativa e una checklist in stile Symfony** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Controlli CI automatici (GitHub Actions) + Code Review:**
   - Nel momento stesso in cui una PR viene aperta o aggiornata, GitHub Actions (`.github/workflows/ci.yml`) avvia automaticamente i runner nel cloud.
   - Il codice viene testato su **PHP 8.3 e 8.4**, **Symfony 7.2, 7.4 e 8.0** e **Node 20 e 22**, verificando gli standard di codifica (**PHP-CS-Fixer**), la sicurezza statica dei tipi (**PHPStan Level 8**), la completezza delle traduzioni (**TranslationParityTest**), l'accuratezza dello schema YAML nella documentazione (**FullConfigReferenceDocTest**), l'integrità delle Agent Skills (**SkillsPackageIntegrityTest**) e la sicurezza XSS/CSP/frontend (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **Se anche un solo controllo fallisce, GitHub blocca il pulsante Merge.**

---

## 2. L'infrastruttura di qualità è già configurata?

**Sì — configurata al 100% e pronta all'uso.** Il bundle include una toolchain completa per il controllo qualità, modellata direttamente sugli standard ufficiali di Symfony UX:

| File | Scopo (in termini semplici) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Definisce la suite di script eseguibile con un solo comando `composer qa` e le dipendenze di sviluppo standalone (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Esegue tutti i test unitari e di sicurezza PHP (**57 file di test, 360 test, oltre 3.500 asserzioni**) in modo autonomo, senza richiedere un'applicazione host. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Configura l'analisi statica **PHPStan Level 8** per individuare incongruenze di tipo nascoste, argomenti non validi e chiamate a metodi non definiti prima del runtime. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Applica lo stile di codifica PSR-12 + Symfony a tutti i file PHP (`declare(strict_types=1)`, confronto stretto `===`, import `use` ordinati alfabeticamente). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Esegue automaticamente **6 job CI in parallelo** a ogni nuova Pull Request o aggiornamento. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Precompila ogni nuova PR con una tabella riepilogativa in stile Symfony (`Bug fix?`, `BC breaks?`, `Tests pass?`) e una checklist. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Fornisce moduli strutturati per le segnalazioni di bug (`bug_report.yml`) e le richieste di nuove funzionalità (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Linee guida internazionali per i collaboratori, collegate automaticamente da GitHub in ogni nuova schermata di PR e Issue. |
| [`SECURITY.md`](../../SECURITY.md) | Politica di segnalazione delle vulnerabilità che spiega come segnalare problemi di sicurezza in privato invece di aprire una issue pubblica. |

### 7 regole critiche applicate automaticamente

Anche se a un revisore umano dovesse sfuggire uno dei problemi seguenti, **la suite di test automatica lo intercetta e fa fallire immediatamente la PR**:

1. **Traduzioni mancanti (`TranslationParityTest`):** Se viene aggiunta una nuova stringa UI in inglese ma manca in una qualsiasi delle **12 lingue UI integrate** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), il test **FALLISCE**.
2. **`strict_types` mancante (`TranslationParityTest`):** Se anche un solo file PHP in `src/` omette `declare(strict_types=1);`, il test **FALLISCE**.
3. **Violazioni di sicurezza XSS / CSP / Frontend (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Aggiungere handler inline come `onclick="..."` o il filtro `|raw` in Twig, oppure compromettere la sanitizzazione degli URL `javascript:` o del DOM in JS, fa **FALLIRE** il test.
4. **Disallineamento dello schema YAML nella documentazione (`FullConfigReferenceDocTest`):** Tutti i blocchi YAML `liveux_file_manager:` nella guida [Configurazione e profili](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) vengono validati rispetto allo schema reale di `Configuration.php`.
5. **Integrità delle Agent Skills (`SkillsPackageIntegrityTest`):** Verifica il frontmatter, i limiti di righe/dimensione e i collegamenti ai file di riferimento in `AGENTS.md` e in tutti i 6 file `skills/*/SKILL.md`.
6. **Stile del codice e tipizzazione statica (`PHP-CS-Fixer` + `PHPStan Level 8`):** Qualsiasi violazione PSR-12/Symfony o errore di tipo al livello 8 blocca la CI.
7. **Sincronizzazione degli asset minificati `assets/dist/` (`npm --prefix assets run build:check`):** Se modifichi `assets/controllers/*.js` o `assets/styles/core.css` e dimentichi di rigenerare `assets/dist/`, la CI **FALLISCE**.

---

## 2.5. Architettura di minificazione JS e CSS (`assets/dist/`) con `esbuild`

> [!NOTE]
> **Perché esiste questo meccanismo?**
> - Chi utilizza **Webpack Encore** ottiene la minificazione automatica di JS/CSS eseguendo `npm run build`.
> - **Symfony AssetMapper** (`asset-map:compile`), invece, serve i file così come sono senza minificarli. In formato sorgente non minificato, `browser_controller.js` pesa `518 KB` e `core.css` pesa `132 KB`.
> - Per garantire caricamenti rapidi anche agli utenti di AssetMapper senza richiedere Node.js nei progetti host, adottiamo esattamente lo stesso pattern **Source → Distribution (`assets/dist/`)** usato dai pacchetti ufficiali Symfony UX (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### Come funziona

1. **File sorgente leggibili:** Tutto lo sviluppo e i test di sicurezza vengono eseguiti sui file sorgente formattati e commentati:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` e `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` e `assets/images/liveux-logo.webp`
2. **Output minificato per la produzione (`assets/dist/`):** Lo script [`assets/build.mjs`](../../assets/build.mjs) utilizza **`esbuild`** per minificare ciascun file JS come modulo ESM autonomo (mantenendo `@hotwired/stimulus` esterno e preservando gli import relativi) e compila `core.css` dentro `assets/dist/`.
3. **Risoluzione automatica (zero breaking change):**
   - Il metodo `prependAssetMapper()` in [`FileManagerBundle.php`](../../src/FileManagerBundle.php) mappa automaticamente il namespace `@liveux/file-manager-bundle` su `assets/dist/` ogni volta che `assets/dist` è presente.
   - Le voci `"exports"` e `"symfony.controllers.*.main"` in [`assets/package.json`](../../assets/package.json) garantiscono che anche Webpack Encore utilizzi direttamente i file in `dist/`.
   - Poiché `assets/dist/` è versionato in Git, **gli utenti finali non hanno mai bisogno di Node.js o npm**. `.gitattributes` esclude dagli archivi Packagist solo gli strumenti di build (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`), mantenendo inclusi `assets/package.json` e `assets/dist/`.

### Risparmio di dimensioni misurato

| File | Sorgente originale | Minificato (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 adapter editor | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **TOTALE (`-45.1%` minificato)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Comandi per gli sviluppatori (ogni volta che modifichi JS o CSS)

```bash
# 1. Installa esbuild una sola volta (solo la prima volta):
npm --prefix assets install

# 2. Rigenera assets/dist/ dopo aver modificato qualsiasi file JS o CSS (~50 ms):
npm --prefix assets run build
# oppure tramite lo shortcut di Composer:
composer assets:build

# 3. Verifica che assets/dist/ sia sincronizzato al 100% con i file sorgente (stesso controllo della CI):
composer assets:check
```

---

## 3. Guida passo dopo passo per inviare una Pull Request

> [!TIP]
> **Anche se non hai mai inviato una PR prima d'ora**, segui questi 6 passaggi in ordine per aprire una Pull Request pulita che superi tutti i controlli CI.

### Passo 1: Fai il fork, clona il repository e installa le dipendenze

1. Apri [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) su GitHub e clicca su **Fork** in alto a destra per creare una copia sul tuo account GitHub.
2. Clona il tuo fork in locale, aggiungi il repository principale come `upstream` e installa gli strumenti di sviluppo PHP e Node:

```bash
git clone https://github.com/<TUO-USERNAME>/file-manager-bundle.git
cd file-manager-bundle

# Traccia il repository principale come upstream (ottima abitudine per restare aggiornato)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Installa le dipendenze PHP per test e analisi statica
composer install

# Installa il minificatore JS/CSS (esbuild) se intendi modificare file JS/CSS
npm --prefix assets install
```

### Passo 2: Crea un nuovo branch a partire da `main` aggiornato

Non fare mai commit diretti su `main`. Sincronizza prima `main`, quindi crea un branch con un nome descrittivo per il tuo lavoro:
- Correzione di bug: `fix/folder-counter-cache`
- Nuova funzionalità: `feat/new-icon-set`
- Documentazione: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Passo 3: Scrivi il codice, aggiungi i test e rigenera `dist/` se necessario

- **Correzione di un bug:** Inizia scrivendo un test di regressione mirato che riproduca il bug (rosso prima della correzione, verde dopo).
- **Aggiunta di una funzionalità:**
  - Aggiungi test unitari PHP in `tests/Unit/...`.
  - Per modifiche a UI o JS, aggiungi copertura in `tests/Js/dom_security_test.mjs` o `tests/Js/frontend_audit_test.mjs`.
  - **Ogni volta che modifichi file JS o CSS, esegui `composer assets:build`** (altrimenti la CI fallirà su `build:check`).
  - Ogni volta che aggiungi una nuova chiave di traduzione UI, inseriscila in **tutti i 12 cataloghi di lingua UI integrati**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Passo 4: Esegui tutti i controlli di qualità in locale in un colpo solo

Prima di aprire la PR, esegui esattamente gli stessi controlli che lancia la CI:

```bash
composer qa
composer assets:check
```

Questi comandi verificano, nell'ordine:
1. `composer validate --strict` — Valida `composer.json`.
2. `composer cs:check` — Controlla gli standard di codifica PSR-12/Symfony *(se vengono segnalati problemi, esegui `composer cs:fix` per formattare automaticamente)*.
3. `composer stan` — Esegue l'analisi statica **PHPStan Level 8**.
4. `composer test` — Esegue l'intera suite PHPUnit di test unitari, di sicurezza, di parità delle traduzioni e di schema.
5. `composer test:js:syntax` — Esegue la validazione sintattica `node --check` su tutti i file JS sorgente e in `dist/`.
6. `composer test:js` — Esegue le suite Node.js di sicurezza DOM e audit frontend (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Conferma che `assets/dist/` corrisponda byte per byte ai file sorgente attuali.

> [!TIP]
> **Tutto verde?** Sei pronto per creare il commit e aprire la Pull Request.

### Passo 5: Crea un commit pulito e fai push sul tuo fork

```bash
git status            # Verifica che siano stati modificati solo i file previsti
git diff --stat       # Controlla un rapido riepilogo delle modifiche
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Usa prefissi Conventional Commits chiari: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Passo 6: Apri la Pull Request su GitHub e verifica che la CI diventi verde

1. Visita il tuo fork su GitHub (`https://github.com/<TUO-USERNAME>/file-manager-bundle`) e clicca sul banner **"Compare & pull request"**.
2. Controlla i branch di destinazione: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<TUO-USERNAME>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Compila la tabella del **template PR** precompilato (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) e spunta le voci della checklist (`[x]`). Se la tua modifica riguarda l'interfaccia utente, allega uno screenshot o una GIF.
4. Clicca su **"Create pull request"**.
5. Attendi che tutti i controlli GitHub Actions (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) in fondo alla pagina della PR mostrino una **spunta verde (✓)**.
6. Se un revisore richiede modifiche, aggiorna i file sullo stesso branch ed esegui `git commit && git push` — GitHub aggiornerà la PR e rilancerà la CI automaticamente.

---

## 4. Per i maintainer: configurare Branch Protection e Rulesets su GitHub

Una volta che i file di workflow sono presenti nel repository, la CI parte in automatico — ma per garantire che **nessuno (nemmeno un maintainer per errore) possa cliccare su Merge mentre i controlli sono rossi**, configura un **Ruleset** di GitHub in 2 minuti:

1. Apri il repository del bundle su GitHub e vai su **Settings**.
2. Nella barra laterale sinistra, seleziona **Rules → Rulesets** (oppure **Branches → Add branch protection rule** nella vista classica).
3. Clicca su **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Abilita le seguenti 4 protezioni:
   - [x] **Restrict deletions** (impedisce l'eliminazione di `main`)
   - [x] **Block force pushes** (protegge la cronologia dei commit)
   - [x] **Require a pull request before merging** (blocca i push diretti su `main`; ogni modifica passa tramite PR)
     - *Required approvals:* `1` (consigliato per i team; i maintainer singoli possono lasciarlo a `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(impostazione più importante)*
     - [x] *Require branches to be up to date before merging*
     - Al termine della prima esecuzione CI, usa **Add checks** per rendere obbligatori tutti i **6 job**:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Clicca su **Create** in fondo alla pagina.

---

## 5. Checklist per pubblicare il bundle come repository open-source standalone

Per pubblicare il bundle come `LiveUX/file-manager-bundle` su GitHub e Packagist:

1. Crea un nuovo repository **Public** vuoto chiamato `file-manager-bundle` nell'organizzazione GitHub `LiveUX`.
2. Fai push del contenuto di `bundles/liveux-file-manager-bundle/` come commit iniziale (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Verifica nella scheda **GitHub Actions** che tutti i 6 job CI vengano completati con spunta verde.
4. Attiva le impostazioni del **Ruleset** descritte nella **Sezione 4** qui sopra.
5. Crea il tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) e registra `https://github.com/LiveUX/file-manager-bundle` su [Packagist.org](https://packagist.org).
6. Da quel momento chiunque potrà installare il bundle con un solo comando: `composer require liveux/file-manager-bundle`.

---

[← Precedente: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Indice della documentazione](README.md) | [Successivo: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)
