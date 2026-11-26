# 11 — Bijdragen & Pull Request (PR)-gids (Stap-voor-stap voor beginners)

[← Vorige: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Documentatie-overzicht](README.md) | [Volgende: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)

Deze gids is van de grond af geschreven voor ontwikkelaars die **nog nooit eerder een Pull Request (PR) bij een open-sourceproject hebben geopend**, én voor maintainers die een **geautomatiseerde PR-kwaliteitspoort in Symfony-stijl** willen inrichten in hun eigen repository. Elk concept en elk commando wordt stap voor stap uitgelegd.

---

## 1. Het grote geheel: hoe open-source Pull Requests werken

In grote open-sourceprojecten zoals Symfony, Laravel of React kunnen externe bijdragers niet rechtstreeks naar de hoofdcodebase (`main`-branch) pushen. In plaats daarvan doorloopt elke wijziging een **veiligheidspoort in 4 fasen**:

1. **Fork & Branch (je eigen kopie maken):** Kopieer de repository naar je eigen GitHub-account (*Fork*) en maak een aparte werkbranch (*Branch*) aan.
2. **Code & lokale controles (`composer qa`):** Implementeer je wijziging, schrijf tests en draai de volledige geautomatiseerde kwaliteitssuite op je eigen machine.
3. **Een Pull Request (PR) openen:** Dien een verzoek in waarmee je maintainers vraagt je bugfix of nieuwe functie te reviewen en te mergen naar de hoofdrepository. GitHub toont automatisch een **PR-samenvattingstabel en checklist in Symfony-stijl** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Geautomatiseerde CI-controles (GitHub Actions) + Code Review:**
   - Zodra een PR wordt geopend of bijgewerkt, start GitHub Actions (`.github/workflows/ci.yml`) automatisch cloudrunners op.
   - De code wordt getest op **PHP 8.3 & 8.4**, **Symfony 7.2, 7.4 & 8.0** en **Node 20 & 22**, en controleert op coderingsstandaarden (**PHP-CS-Fixer**), statische typeveiligheid (**PHPStan Level 8**), volledigheid van vertalingen (**TranslationParityTest**), nauwkeurigheid van het YAML-documentatieschema (**FullConfigReferenceDocTest**), integriteit van Agent Skills (**SkillsPackageIntegrityTest**) en XSS/CSP/frontend-beveiliging (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **Faalt er zelfs maar één controle, dan blokkeert GitHub de Merge-knop.**

---

## 2. Is de kwaliteitsinfrastructuur al ingericht?

**Ja — 100% geconfigureerd en klaar voor gebruik.** De bundle wordt geleverd met een complete kwaliteitstoolchain die direct is gemodelleerd naar de officiële Symfony UX-standaarden:

| Bestand | Doel (in gewone taal) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Definieert de één-commando `composer qa`-scriptsuite en losstaande dev-dependencies (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Draait alle PHP-unit- en beveiligingstests (**57 testbestanden, 360 tests, 3.500+ assertions**) volledig zelfstandig zonder hostapplicatie. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Configureert **PHPStan Level 8** statische analyse om verborgen typefouten, ongeldige argumenten en niet-bestaande methode-aanroepen vóór runtime op te sporen. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Dwingt de PSR-12 + Symfony-codestijl af in alle PHP-bestanden (`declare(strict_types=1)`, strikte `===`, alfabetisch gesorteerde `use`-imports). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Draait automatisch **6 parallelle CI-jobs** bij elke nieuwe of bijgewerkte Pull Request. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Vult elke nieuwe PR automatisch met een samenvattingstabel in Symfony-stijl (`Bug fix?`, `BC breaks?`, `Tests pass?`) en een checklist. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Biedt gestructureerde formulieren voor bugrapporten (`bug_report.yml`) en functieverzoeken (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Internationale richtlijnen voor bijdragers die GitHub automatisch linkt op elk nieuw PR- en Issue-scherm. |
| [`SECURITY.md`](../../SECURITY.md) | Beveiligingsbeleid dat uitlegt hoe kwetsbaarheden privé kunnen worden gemeld in plaats van via een openbaar issue. |

### 7 kritieke regels die automatisch worden afgedwongen

Zelfs als een menselijke reviewer een van onderstaande problemen over het hoofd ziet, **vangt de geautomatiseerde testsuite dit direct op en laat de PR falen**:

1. **Ontbrekende vertalingen (`TranslationParityTest`):** Als een nieuwe UI-tekst wordt toegevoegd aan het Engels maar ontbreekt in een van de **12 ingebouwde UI-talen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), dan **FAALT** de test.
2. **Ontbrekende `strict_types` (`TranslationParityTest`):** Als zelfs maar één PHP-bestand onder `src/` `declare(strict_types=1);` mist, dan **FAALT** de test.
3. **Overtredingen van XSS- / CSP- / frontend-beveiliging (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Het toevoegen van inline handlers zoals `onclick="..."` of `|raw` in Twig, of het breken van `javascript:`-URL- / DOM-sanitatie in JS, zorgt ervoor dat de test **FAALT**.
4. **Afwijking in het YAML-documentatieschema (`FullConfigReferenceDocTest`):** Alle `liveux_file_manager:` YAML-blokken in de referentie [Configuratie & Profielen](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) worden gevalideerd tegen het echte `Configuration.php`-schema.
5. **Integriteit van Agent Skills (`SkillsPackageIntegrityTest`):** Valideert frontmatter, regel-/groottelimieten en referentielinks in `AGENTS.md` en alle 6 `skills/*/SKILL.md`-bestanden.
6. **Codestijl & statische types (`PHP-CS-Fixer` + `PHPStan Level 8`):** Elke PSR-12/Symfony-stijlovertreding of Level 8-typefout blokkeert CI.
7. **Synchronisatie van geminificeerde `assets/dist/` (`npm --prefix assets run build:check`):** Als je `assets/controllers/*.js` of `assets/styles/core.css` aanpast en vergeet `assets/dist/` opnieuw te bouwen, dan **FAALT** CI.

---

## 2.5. Architectuur voor JS- & CSS-minificatie (`assets/dist/`) (`esbuild`)

> [!NOTE]
> **Waarom bestaat dit?**
> - **Webpack Encore**-gebruikers krijgen automatisch JS/CSS-minificatie bij het uitvoeren van `npm run build`.
> - **Symfony AssetMapper** (`asset-map:compile`) levert bestanden echter ongewijzigd uit zonder ze te minificeren. In ruwe bronvorm is `browser_controller.js` `518 KB` en `core.css` `132 KB`.
> - Om snelle laadtijden te garanderen voor AssetMapper-gebruikers zonder Node.js te vereisen in hostprojecten, volgen we exact hetzelfde **Source → Distribution (`assets/dist/`)**-patroon dat officiële Symfony UX-packages (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`) gebruiken.

### Hoe het werkt

1. **Leesbare bronbestanden:** Alle ontwikkeling en beveiligingstests draaien op netjes geformatteerde, becommentarieerde bronbestanden:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` en `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` en `assets/images/liveux-logo.webp`
2. **Geminificeerde productie-output (`assets/dist/`):** Het script [`assets/build.mjs`](../../assets/build.mjs) gebruikt **`esbuild`** om elk JS-bestand als een zelfstandige ESM-module te minificeren (waarbij `@hotwired/stimulus` extern blijft en relatieve imports behouden blijven) en compileert `core.css` naar `assets/dist/`.
3. **Automatische resolutie (geen breaking changes):**
   - `prependAssetMapper()` in [`FileManagerBundle.php`](../../src/FileManagerBundle.php) koppelt de namespace `@liveux/file-manager-bundle` automatisch aan `assets/dist/` zodra `assets/dist` bestaat.
   - De velden `"exports"` en `"symfony.controllers.*.main"` in [`assets/package.json`](../../assets/package.json) zorgen ervoor dat Webpack Encore eveneens rechtstreeks de `dist/`-bestanden gebruikt.
   - Omdat `assets/dist/` in Git is gecommit, **hebben eindgebruikers nooit Node.js of npm nodig**. `.gitattributes` sluit alleen de buildtooling (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) uit van Packagist-archieven, terwijl `assets/package.json` en `assets/dist/` behouden blijven.

### Gemeten groottebesparing

| Bestand | Ruwe broncode | Geminificeerd (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 editor-adapters | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **TOTAAL (`-45.1%` geminificeerd)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Ontwikkelaarcommando's (wanneer je JS of CSS aanpast)

```bash
# 1. Installeer esbuild eenmalig (alleen de eerste keer):
npm --prefix assets install

# 2. Bouw assets/dist/ opnieuw na het aanpassen van een JS- of CSS-bestand (~50 ms):
npm --prefix assets run build
# of via de Composer-shortcut:
composer assets:build

# 3. Controleer of assets/dist/ 100% synchroon loopt met de bronbestanden (dezelfde controle als in CI):
composer assets:check
```

---

## 3. Stap-voor-stap handleiding voor het indienen van een Pull Request

> [!TIP]
> **Ook als je nog nooit eerder een PR hebt ingediend**, volg je simpelweg deze 6 stappen in volgorde om een nette, door CI goedgekeurde Pull Request te openen.

### Stap 1: Fork, clone en installeer dependencies

1. Open [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) op GitHub en klik rechtsboven op **Fork** om een kopie onder je eigen GitHub-account aan te maken.
2. Clone je fork lokaal, voeg de hoofdrepository toe als `upstream` en installeer de PHP- en Node-ontwikkeltools:

```bash
git clone https://github.com/<JOUW-GEBRUIKERSNAAM>/file-manager-bundle.git
cd file-manager-bundle

# Volg de hoofdrepository als upstream (goede gewoonte om up-to-date te blijven)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Installeer dependencies voor PHP-tests en statische analyse
composer install

# Installeer de JS/CSS-minifier (esbuild) als je JS/CSS-bestanden wilt aanpassen
npm --prefix assets install
```

### Stap 2: Maak een nieuwe branch aan vanaf een up-to-date `main`

Commit nooit rechtstreeks naar `main`. Synchroniseer eerst `main` en maak vervolgens een duidelijke branch aan voor jouw wijziging:
- Bugfix: `fix/folder-counter-cache`
- Nieuwe functie: `feat/new-icon-set`
- Documentatie: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Stap 3: Schrijf code, voeg tests toe en bouw `dist/` opnieuw indien nodig

- **Een bug oplossen:** Begin met het schrijven van een gerichte regressietest die de bug reproduceert (rood vóór de fix, groen erna).
- **Een functie toevoegen:**
  - Voeg PHP-unittests toe onder `tests/Unit/...`.
  - Voeg bij UI-/JS-wijzigingen testdekking toe aan `tests/Js/dom_security_test.mjs` of `tests/Js/frontend_audit_test.mjs`.
  - **Voer altijd `composer assets:build` uit zodra je JS of CSS aanpast** (anders faalt CI op `build:check`).
  - Voeg elke nieuwe UI-vertaalsleutel toe aan **alle 12 ingebouwde UI-taalcatalogi**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Stap 4: Draai alle kwaliteitspoorten lokaal in één keer

Voer exact dezelfde controles uit als CI voordat je je PR opent:

```bash
composer qa
composer assets:check
```

Deze commando's controleren op volgorde:
1. `composer validate --strict` — Valideert `composer.json`.
2. `composer cs:check` — Controleert de PSR-12/Symfony-coderingsstandaarden *(worden er afwijkingen gemeld, draai dan `composer cs:fix` om automatisch te formatteren)*.
3. `composer stan` — Draait **PHPStan Level 8** statische analyse.
4. `composer test` — Draait de volledige PHPUnit-suite met unit-, beveiligings-, vertaalpariteits- en schematests.
5. `composer test:js:syntax` — Voert `node --check`-syntaxvalidatie uit op alle bron- en `dist/`-JS-bestanden.
6. `composer test:js` — Draait de Node.js DOM-beveiligings- en frontend-auditsuites (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Bevestigt dat `assets/dist/` byte voor byte overeenkomt met de huidige bronbestanden.

> [!TIP]
> **Alles groen?** Dan ben je klaar om te committen en je Pull Request te openen.

### Stap 5: Maak een nette commit en push naar je fork

```bash
git status            # Controleer of alleen de bedoelde bestanden zijn gewijzigd
git diff --stat       # Bekijk een kort overzicht van je wijzigingen
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Gebruik duidelijke Conventional Commit-voorvoegsels: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Stap 6: Open de Pull Request op GitHub en zie CI op groen springen

1. Ga naar je fork op GitHub (`https://github.com/<JOUW-GEBRUIKERSNAAM>/file-manager-bundle`) en klik op de banner **"Compare & pull request"**.
2. Controleer de doelen: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<JOUW-GEBRUIKERSNAAM>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Vul de automatisch geladen **PR-template**-tabel in (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) en vink de checklist-items af (`[x]`). Voeg bij UI-wijzigingen een screenshot of GIF toe.
4. Klik op **"Create pull request"**.
5. Wacht tot alle GitHub Actions-controles (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) onderaan de PR-pagina een **groen vinkje (✓)** tonen.
6. Vraagt een reviewer om aanpassingen? Pas de bestanden op dezelfde branch aan en voer `git commit && git push` uit — GitHub werkt de PR automatisch bij en start CI opnieuw.

---

## 4. Voor maintainers: Branch Protection & Rulesets afdwingen op GitHub

Zodra de workflowbestanden in de repository staan, draait CI automatisch — maar om te garanderen dat **niemand (ook een maintainer niet per ongeluk) op Merge kan klikken zolang er controles rood zijn**, configureer je in 2 minuten een GitHub **Ruleset**:

1. Open je bundlerepository op GitHub en ga naar **Settings**.
2. Kies in de linkerzijbalk **Rules → Rulesets** (of **Branches → Add branch protection rule** in de klassieke weergave).
3. Klik op **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Schakel de volgende 4 beveiligingen in:
   - [x] **Restrict deletions** (voorkomt het verwijderen van `main`)
   - [x] **Block force pushes** (beschermt de commithistorie)
   - [x] **Require a pull request before merging** (blokkeert directe pushes naar `main`; alle wijzigingen komen via een PR binnen)
     - *Required approvals:* `1` (aanbevolen voor teams; solo-maintainers kunnen dit op `0` laten staan)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(belangrijkste instelling)*
     - [x] *Require branches to be up to date before merging*
     - Voeg na de eerste voltooide CI-run via **Add checks** alle **6 jobs** als verplicht toe:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Klik onderaan de pagina op **Create**.

---

## 5. Checklist voor het publiceren van de bundle als losstaande open-sourcerepository

Om de bundle als `LiveUX/file-manager-bundle` op GitHub en Packagist te publiceren:

1. Maak een nieuwe lege **Public** repository aan met de naam `file-manager-bundle` onder de GitHub-organisatie `LiveUX`.
2. Push de inhoud van `bundles/liveux-file-manager-bundle/` als initiële commit (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Controleer op het tabblad **GitHub Actions** of alle 6 CI-jobs slagen met een groen vinkje.
4. Schakel de **Ruleset**-instellingen uit **Sectie 4** hierboven in.
5. Tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) en meld `https://github.com/LiveUX/file-manager-bundle` aan op [Packagist.org](https://packagist.org).
6. Iedereen kan de bundle nu met één commando installeren: `composer require liveux/file-manager-bundle`.

---

[← Vorige: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Documentatie-overzicht](README.md) | [Volgende: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)
