# 11 — Contributing- & Pull-Request-(PR)-Leitfaden (Schritt für Schritt für Einsteiger)

[← Zurück: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Dokumentationsübersicht](README.md) | [Weiter: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)

Dieser Leitfaden richtet sich von Grund auf an Entwicklerinnen und Entwickler, die **noch nie einen Pull Request (PR) in einem Open-Source-Projekt eröffnet haben**, sowie an Maintainer, die in ihrem eigenen Repository ein **automatisiertes PR-Qualitätstor nach Symfony-Standard** einrichten möchten. Jedes Konzept und jeder Befehl wird Schritt für Schritt erklärt.

---

## 1. Das große Ganze: Wie Open-Source-Pull-Requests funktionieren

In großen Open-Source-Projekten wie Symfony, Laravel oder React können externe Beitragende nicht direkt auf den Haupt-Branch (`main`) pushen. Stattdessen durchläuft jede Änderung ein **4-stufiges Sicherheitsnetz**:

1. **Fork & Branch (eigene Kopie anlegen):** Du kopierst das Repository in deinen eigenen GitHub-Account (*Fork*) und erstellst einen eigenen Arbeits-Branch (*Branch*).
2. **Code & lokale Prüfungen (`composer qa`):** Du setzt deine Änderung um, schreibst Tests und führst die gesamte automatisierte Qualitätssuite lokal auf deinem Rechner aus.
3. **Pull Request (PR) eröffnen:** Du stellst eine Anfrage an die Maintainer, deinen Bugfix oder dein Feature zu prüfen und in das Haupt-Repository zu übernehmen. GitHub zeigt dabei automatisch eine **PR-Übersichtstabelle und Checkliste im Symfony-Stil** (`.github/PULL_REQUEST_TEMPLATE.md`) an.
4. **Automatisierte CI-Prüfungen (GitHub Actions) + Code-Review:**
   - Sobald ein PR eröffnet oder aktualisiert wird, startet GitHub Actions (`.github/workflows/ci.yml`) automatisch Cloud-Runner.
   - Der Code wird unter **PHP 8.3 & 8.4**, **Symfony 7.2, 7.4 & 8.0** sowie **Node 20 & 22** getestet. Zusätzlich werden Coding-Standards (**PHP-CS-Fixer**), statische Typsicherheit (**PHPStan Level 8**), Vollständigkeit der Übersetzungen (**TranslationParityTest**), Korrektheit des YAML-Dokumentationsschemas (**FullConfigReferenceDocTest**), Integrität der Agent Skills (**SkillsPackageIntegrityTest**) sowie XSS-/CSP-/Frontend-Sicherheit (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**) geprüft.
   - **Schlägt auch nur eine einzige Prüfung fehl, sperrt GitHub die Merge-Schaltfläche.**

---

## 2. Ist die Qualitätsinfrastruktur bereits eingerichtet?

**Ja — zu 100 % vorkonfiguriert und sofort einsatzbereit.** Das Bundle bringt eine vollständige Qualitäts-Toolchain mit, die sich direkt an den offiziellen Symfony-UX-Standards orientiert:

| Datei | Zweck (einfach erklärt) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Definiert das All-in-one-Skript `composer qa` sowie eigenständige Entwicklungsabhängigkeiten (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Führt alle PHP-Unit- und Sicherheitstests (**57 Testdateien, 360 Tests, 3.500+ Assertions**) eigenständig ohne Host-Anwendung aus. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Konfiguriert die statische Analyse mit **PHPStan Level 8**, um versteckte Typkonflikte, ungültige Argumente und undefinierte Methodenaufrufe schon vor der Laufzeit zu erkennen. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Erzwingt PSR-12 + Symfony-Coding-Style in allen PHP-Dateien (`declare(strict_types=1)`, striktes `===`, alphabetisch sortierte `use`-Imports). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Führt bei jedem neuen oder aktualisierten Pull Request automatisch **6 parallele CI-Jobs** aus. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Befüllt jeden neuen PR automatisch mit einer Symfony-typischen Übersichtstabelle (`Bug fix?`, `BC breaks?`, `Tests pass?`) und Checkliste. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Stellt strukturierte Formulare für Fehlermeldungen (`bug_report.yml`) und Feature-Anfragen (`feature_request.yml`) bereit. |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Internationale Richtlinien für Beitragende, die GitHub automatisch auf jedem neuen PR- und Issue-Bildschirm verlinkt. |
| [`SECURITY.md`](../../SECURITY.md) | Sicherheitsrichtlinie, die beschreibt, wie Schwachstellen vertraulich statt über ein öffentliches Issue gemeldet werden. |

### 7 zentrale Regeln, die automatisch durchgesetzt werden

Selbst wenn ein menschlicher Reviewer eines der folgenden Probleme übersieht, **erkennt die automatisierte Testsuite es sofort und lässt den PR fehlschlagen**:

1. **Fehlende Übersetzungen (`TranslationParityTest`):** Wird ein neuer UI-Text im Englischen ergänzt, fehlt aber in einer der **12 integrierten UI-Sprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), **schlägt der Test fehl**.
2. **Fehlendes `strict_types` (`TranslationParityTest`):** Fehlt auch nur in einer einzigen PHP-Datei unter `src/` die Deklaration `declare(strict_types=1);`, **schlägt der Test fehl**.
3. **XSS-/CSP-/Frontend-Sicherheitsverstöße (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Inline-Handler wie `onclick="..."` oder `|raw` in Twig sowie defekte `javascript:`-URL- oder DOM-Sanitization in JS führen dazu, dass der Test **fehlschlägt**.
4. **Abweichungen im YAML-Dokumentationsschema (`FullConfigReferenceDocTest`):** Alle `liveux_file_manager:`-YAML-Blöcke in der Referenz [Konfiguration & Profile](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) werden gegen das echte Schema in `Configuration.php` validiert.
5. **Integrität der Agent Skills (`SkillsPackageIntegrityTest`):** Prüft Frontmatter, Zeilen-/Größenbudgets und Referenzlinks in `AGENTS.md` sowie allen 6 `skills/*/SKILL.md`-Dateien.
6. **Code-Style & statische Typen (`PHP-CS-Fixer` + `PHPStan Level 8`):** Jeder PSR-12-/Symfony-Stilverstoß oder Level-8-Typfehler blockiert die CI.
7. **Synchronität der minifizierten `assets/dist/`-Dateien (`npm --prefix assets run build:check`):** Wenn du `assets/controllers/*.js` oder `assets/styles/core.css` bearbeitest und vergisst, `assets/dist/` neu zu bauen, **schlägt die CI fehl**.

---

## 2.5. Architektur der JS- & CSS-Minifizierung (`assets/dist/`) mit `esbuild`

> [!NOTE]
> **Warum gibt es das?**
> - Nutzerinnen und Nutzer von **Webpack Encore** erhalten beim Ausführen von `npm run build` automatisch minifiziertes JS/CSS.
> - **Symfony AssetMapper** (`asset-map:compile`) liefert Dateien jedoch unverändert ohne Minifizierung aus. Im unkomprimierten Quellformat ist `browser_controller.js` `518 KB` und `core.css` `132 KB` groß.
> - Um auch mit AssetMapper schnelle Ladezeiten ohne Node.js im Host-Projekt zu erreichen, folgen wir exakt dem **Source → Distribution (`assets/dist/`)**-Muster der offiziellen Symfony-UX-Pakete (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### So funktioniert es

1. **Lesbare Quelldateien:** Die gesamte Entwicklung und alle Sicherheitstests laufen gegen sauber formatierte, kommentierte Quelldateien:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` sowie `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` und `assets/images/liveux-logo.webp`
2. **Minifizierte Produktionsausgabe (`assets/dist/`):** Das Skript [`assets/build.mjs`](../../assets/build.mjs) nutzt **`esbuild`**, um jede JS-Datei als eigenständiges ESM-Modul zu minifizieren (`@hotwired/stimulus` bleibt extern und relative Imports bleiben erhalten), und kompiliert `core.css` nach `assets/dist/`.
3. **Automatische Auflösung (keine Breaking Changes):**
   - `prependAssetMapper()` in [`FileManagerBundle.php`](../../src/FileManagerBundle.php) verknüpft den Namespace `@liveux/file-manager-bundle` automatisch mit `assets/dist/`, sobald `assets/dist` existiert.
   - Die Einträge `"exports"` und `"symfony.controllers.*.main"` in [`assets/package.json`](../../assets/package.json) stellen sicher, dass auch Webpack Encore direkt die Dateien aus `dist/` verwendet.
   - Da `assets/dist/` im Git-Repository eingecheckt ist, **benötigen Endanwender niemals Node.js oder npm**. `.gitattributes` schließt lediglich Build-Werkzeuge (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) aus Packagist-Archiven aus, während `assets/package.json` und `assets/dist/` enthalten bleiben.

### Gemessene Größenersparnis

| Datei | Roher Quellcode | Minifiziert (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 Editor-Adapter | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **GESAMT (`-45,1 %` minifiziert)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Entwicklerbefehle (bei jeder Änderung an JS oder CSS)

```bash
# 1. esbuild einmalig installieren (nur beim ersten Mal):
npm --prefix assets install

# 2. assets/dist/ nach jeder Änderung an JS- oder CSS-Dateien neu bauen (~50 ms):
npm --prefix assets run build
# oder über das Composer-Kürzel:
composer assets:build

# 3. Prüfen, ob assets/dist/ zu 100 % synchron zu den Quelldateien ist (identisch zur CI):
composer assets:check
```

---

## 3. Schritt-für-Schritt-Anleitung für deinen Pull Request

> [!TIP]
> **Auch wenn du noch nie einen PR eingereicht hast:** Folge einfach diesen 6 Schritten der Reihe nach, um einen sauberen Pull Request mit grüner CI zu erstellen.

### Schritt 1: Forken, Klonen und Abhängigkeiten installieren

1. Öffne [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) auf GitHub und klicke oben rechts auf **Fork**, um eine Kopie in deinem eigenen GitHub-Account anzulegen.
2. Klone deinen Fork lokal, füge das Haupt-Repository als `upstream` hinzu und installiere die PHP- und Node-Entwicklungswerkzeuge:

```bash
git clone https://github.com/<DEIN-BENUTZERNAME>/file-manager-bundle.git
cd file-manager-bundle

# Haupt-Repository als upstream hinterlegen (gute Praxis, um aktuell zu bleiben)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# PHP-Test- und Analyse-Abhängigkeiten installieren
composer install

# JS/CSS-Minifier (esbuild) installieren, falls du JS- oder CSS-Dateien bearbeitest
npm --prefix assets install
```

### Schritt 2: Neuen Branch vom aktuellen `main` abzweigen

Committe niemals direkt auf `main`. Synchronisiere zuerst `main` und erstelle dann einen sprechenden Branch für deine Änderung:
- Bugfix: `fix/folder-counter-cache`
- Neues Feature: `feat/new-icon-set`
- Dokumentation: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Schritt 3: Code schreiben, Tests ergänzen und ggf. `dist/` neu bauen

- **Bei einem Bugfix:** Schreibe zuerst einen gezielten Regressionstest, der den Fehler reproduziert (rot vor dem Fix, grün danach).
- **Bei einem neuen Feature:**
  - Ergänze PHP-Unit-Tests unter `tests/Unit/...`.
  - Ergänze bei UI-/JS-Änderungen entsprechende Tests in `tests/Js/dom_security_test.mjs` oder `tests/Js/frontend_audit_test.mjs`.
  - **Führe nach jeder Änderung an JS oder CSS `composer assets:build` aus** (sonst schlägt die CI bei `build:check` fehl).
  - Wenn du einen neuen UI-Übersetzungsschlüssel hinzufügst, ergänze ihn in **allen 12 integrierten UI-Sprachkatalogen**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Schritt 4: Alle Qualitätsprüfungen lokal in einem Durchlauf ausführen

Führe vor dem Eröffnen deines PR exakt dieselben Prüfungen aus, die auch in der CI laufen:

```bash
composer qa
composer assets:check
```

Diese Befehle prüfen der Reihe nach:
1. `composer validate --strict` — Validiert `composer.json`.
2. `composer cs:check` — Prüft PSR-12-/Symfony-Coding-Standards *(falls Abweichungen gemeldet werden, formatiere den Code automatisch mit `composer cs:fix`)*.
3. `composer stan` — Führt die statische Analyse mit **PHPStan Level 8** aus.
4. `composer test` — Startet die vollständige PHPUnit-Suite (Unit-, Sicherheits-, Übersetzungsparitäts- und Schematests).
5. `composer test:js:syntax` — Führt `node --check` zur Syntaxprüfung aller Quell- und `dist/`-JS-Dateien aus.
6. `composer test:js` — Führt die Node.js-Suites für DOM-Sicherheit und Frontend-Audit aus (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Stellt sicher, dass `assets/dist/` Byte für Byte den aktuellen Quelldateien entspricht.

> [!TIP]
> **Alles grün?** Dann kannst du jetzt committen und deinen Pull Request eröffnen.

### Schritt 5: Sauberen Commit erstellen und in deinen Fork pushen

```bash
git status            # Prüfen, dass nur die beabsichtigten Dateien geändert wurden
git diff --stat       # Kurze Übersicht deiner Änderungen ansehen
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Verwende klare Conventional-Commit-Präfixe: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Schritt 6: Pull Request auf GitHub eröffnen und grüne CI abwarten

1. Öffne deinen Fork auf GitHub (`https://github.com/<DEIN-BENUTZERNAME>/file-manager-bundle`) und klicke auf das Banner **„Compare & pull request“**.
2. Prüfe die Zielangaben: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<DEIN-BENUTZERNAME>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Fülle die automatisch vorausgefüllte **PR-Vorlage** aus (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) und hake die Punkte der Checkliste ab (`[x]`). Betrifft deine Änderung die Benutzeroberfläche, hänge bitte einen Screenshot oder ein GIF an.
4. Klicke auf **„Create pull request“**.
5. Warte, bis alle GitHub-Actions-Prüfungen (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) am Ende der PR-Seite ein **grünes Häkchen (✓)** anzeigen.
6. Wenn ein Reviewer Änderungen anfragt, bearbeite die Dateien im selben Branch und führe `git commit && git push` aus — GitHub aktualisiert den PR und startet die CI automatisch neu.

---

## 4. Für Maintainer: Branch-Schutz & Rulesets auf GitHub aktivieren

Sobald die Workflow-Dateien im Repository liegen, läuft die CI automatisch. Damit **niemand (auch kein Maintainer aus Versehen) auf Merge klicken kann, solange Checks rot sind**, richte in 2 Minuten ein GitHub-**Ruleset** ein:

1. Öffne das Bundle-Repository auf GitHub und wechsle zu **Settings**.
2. Wähle in der linken Seitenleiste **Rules → Rulesets** (oder in der klassischen Ansicht **Branches → Add branch protection rule**).
3. Klicke auf **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Aktiviere die folgenden 4 Schutzregeln:
   - [x] **Restrict deletions** (verhindert das Löschen von `main`)
   - [x] **Block force pushes** (schützt die Commit-Historie)
   - [x] **Require a pull request before merging** (blockiert direkte Pushes auf `main`; jede Änderung erfolgt per PR)
     - *Required approvals:* `1` (empfohlen für Teams; Solo-Maintainer können diesen Wert auf `0` lassen)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(wichtigste Einstellung)*
     - [x] *Require branches to be up to date before merging*
     - Füge nach dem ersten abgeschlossenen CI-Lauf über **Add checks** alle **6 Jobs** als Pflichtprüfungen hinzu:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Klicke unten auf der Seite auf **Create**.

---

## 5. Checkliste für die Veröffentlichung als eigenständiges Open-Source-Repository

So veröffentlichst du das Bundle als `LiveUX/file-manager-bundle` auf GitHub und Packagist:

1. Erstelle ein neues, leeres **Public**-Repository namens `file-manager-bundle` unter der GitHub-Organisation `LiveUX`.
2. Pushe den Inhalt von `bundles/liveux-file-manager-bundle/` als Initial-Commit (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Prüfe im Tab **GitHub Actions**, dass alle 6 CI-Jobs erfolgreich mit grünem Häkchen durchlaufen.
4. Aktiviere die **Ruleset**-Einstellungen aus **Abschnitt 4**.
5. Erstelle den Tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) und reiche `https://github.com/LiveUX/file-manager-bundle` auf [Packagist.org](https://packagist.org) ein.
6. Ab sofort kann jeder das Bundle mit einem einzigen Befehl installieren: `composer require liveux/file-manager-bundle`.

---

[← Zurück: 10 — Asset Usage Guard](10-asset-usage-guard.md) | [Dokumentationsübersicht](README.md) | [Weiter: 12 — AI Agent Skills →](12-ai-agent-skills-guide.md)
