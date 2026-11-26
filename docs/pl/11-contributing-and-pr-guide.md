# 11 — Współtworzenie i przewodnik po Pull Requestach (PR) (krok po kroku dla początkujących)

[← Poprzedni: 10 — Ochrona używanych zasobów](10-asset-usage-guard.md) | [Spis treści dokumentacji](README.md) | [Następny: 12 — Umiejętności agentów AI →](12-ai-agent-skills-guide.md)

Ten przewodnik został napisany od podstaw z myślą o programistach, którzy **nigdy wcześniej nie otwierali Pull Requesta (PR) w projekcie open-source**, a także o opiekunach repozytoriów (*maintainers*), którzy chcą skonfigurować u siebie **automatyczną bramkę jakości PR w stylu Symfony**. Każde pojęcie i każda komenda zostały wyjaśnione krok po kroku.

---

## 1. Szerszy obraz: jak działają Pull Requesty w projektach open-source

W dużych projektach open-source, takich jak Symfony, Laravel czy React, zewnętrzni współtwórcy nie mogą wysyłać zmian bezpośrednio na główną gałąź kodu (`main`). Zamiast tego każda zmiana przechodzi przez **4-etapową bramkę bezpieczeństwa**:

1. **Fork i gałąź (własna kopia kodu):** Kopiujesz repozytorium na własne konto GitHub (*Fork*) i tworzysz dedykowaną gałąź roboczą (*Branch*).
2. **Kod i lokalne testy (`composer qa`):** Wprowadzasz zmianę, dopisujesz testy i uruchamiasz na swoim komputerze pełny pakiet automatycznej kontroli jakości.
3. **Otwarcie Pull Requesta (PR):** Składasz prośbę do opiekunów projektu o przegląd kodu (*Code Review*) i scalenie (*merge*) poprawki lub nowej funkcji z głównym repozytorium. GitHub automatycznie wyświetla **tabelę podsumowania i listę kontrolną PR w stylu Symfony** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Automatyczne testy CI (GitHub Actions) + Code Review:**
   - W chwili otwarcia lub zaktualizowania PR GitHub Actions (`.github/workflows/ci.yml`) automatycznie uruchamia chmurowe środowiska testowe.
   - Kod jest testowany na **PHP 8.3 i 8.4**, **Symfony 7.2, 7.4 i 8.0** oraz **Node 20 i 22**, z egzekwowaniem standardów kodowania (**PHP-CS-Fixer**), statycznej kontroli typów (**PHPStan Level 8**), kompletności tłumaczeń (**TranslationParityTest**), zgodności dokumentacji YAML ze schematem (**FullConfigReferenceDocTest**), spójności pakietu Agent Skills (**SkillsPackageIntegrityTest**) oraz bezpieczeństwa XSS/CSP/frontendu (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **Jeśli choćby jeden test zakończy się błędem, GitHub blokuje przycisk Merge.**

---

## 2. Czy infrastruktura jakości jest już gotowa?

**Tak — jest w 100% skonfigurowana i gotowa do użycia.** Bundle zawiera kompletny zestaw narzędzi jakościowych wzorowany bezpośrednio na oficjalnych standardach Symfony UX:

| Plik | Przeznaczenie (w prostych słowach) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Definiuje uruchamiany jedną komendą zestaw skryptów `composer qa` oraz niezależne zależności deweloperskie (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Uruchamia wszystkie testy jednostkowe i bezpieczeństwa PHP (**57 plików testowych, 360 testów, ponad 3500 asercji**) samodzielnie, bez konieczności posiadania aplikacji hostującej. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Konfiguruje analizę statyczną **PHPStan Level 8**, wyłapującą ukryte niezgodności typów, nieprawidłowe argumenty i wywołania nieistniejących metod jeszcze przed uruchomieniem kodu. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Egzekwuje styl kodowania PSR-12 + Symfony we wszystkich plikach PHP (`declare(strict_types=1)`, ścisłe porównania `===`, alfabetycznie posortowane importy `use`). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Uruchamia automatycznie **6 równoległych zadań CI** dla każdego nowego lub zaktualizowanego Pull Requesta. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Wstępnie wypełnia każdy nowy PR tabelą podsumowania w stylu Symfony (`Bug fix?`, `BC breaks?`, `Tests pass?`) oraz listą kontrolną. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Zapewnia ustrukturyzowane formularze zgłoszeń błędów (`bug_report.yml`) i propozycji funkcji (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Międzynarodowe wytyczne dla współtwórców, automatycznie podlinkowane przez GitHub na każdym ekranie nowego PR i Issue. |
| [`SECURITY.md`](../../SECURITY.md) | Polityka zgłaszania luk bezpieczeństwa wyjaśniająca, jak zgłaszać podatności prywatnie zamiast otwierać publiczne Issue. |

### 7 kluczowych reguł egzekwowanych automatycznie

Nawet jeśli człowiek podczas Code Review przeoczy któryś z poniższych problemów, **automatyczny zestaw testów wykryje go i natychmiast oznaczy PR jako niezaliczony**:

1. **Brakujące tłumaczenia (`TranslationParityTest`):** Jeśli nowy tekst interfejsu zostanie dodany po angielsku, ale zabraknie go w którymkolwiek z **12 wbudowanych języków interfejsu** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), test **KOŃCZY SIĘ BŁĘDEM**.
2. **Brak `strict_types` (`TranslationParityTest`):** Jeśli choć jeden plik PHP w katalogu `src/` nie zawiera `declare(strict_types=1);`, test **KOŃCZY SIĘ BŁĘDEM**.
3. **Naruszenia bezpieczeństwa XSS / CSP / frontendu (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Dodanie inline'owych handlerów w stylu `onclick="..."` lub filtra `|raw` w Twigu albo zepsucie sanityzacji adresów `javascript:` / DOM w JS powoduje, że test **KOŃCZY SIĘ BŁĘDEM**.
4. **Rozjazd dokumentacji YAML ze schematem (`FullConfigReferenceDocTest`):** Wszystkie bloki YAML `liveux_file_manager:` w dokumentacji [Konfiguracja i profile](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) są walidowane względem rzeczywistego schematu `Configuration.php`.
5. **Spójność pakietu Agent Skills (`SkillsPackageIntegrityTest`):** Weryfikuje frontmatter, limity liczby linii i rozmiaru oraz linki referencyjne w `AGENTS.md` i we wszystkich 6 plikach `skills/*/SKILL.md`.
6. **Styl kodu i statyczne typy (`PHP-CS-Fixer` + `PHPStan Level 8`):** Każde odstępstwo od standardu PSR-12/Symfony lub błąd typów na poziomie Level 8 blokuje CI.
7. **Synchronizacja zminifikowanego katalogu `assets/dist/` (`npm --prefix assets run build:check`):** Jeśli zmodyfikujesz pliki `assets/controllers/*.js` lub `assets/styles/core.css` i zapomnisz przebudować `assets/dist/`, CI **KOŃCZY SIĘ BŁĘDEM**.

---

## 2.5. Architektura minifikacji JS i CSS (`assets/dist/`) oparta na `esbuild`

> [!NOTE]
> **Dlaczego to rozwiązanie istnieje?**
> - Użytkownicy **Webpack Encore** otrzymują automatyczną minifikację JS/CSS podczas uruchamiania `npm run build`.
> - Natomiast **Symfony AssetMapper** (`asset-map:compile`) serwuje pliki w niezmienionej postaci, bez minifikacji. W wersji źródłowej `browser_controller.js` waży `518 KB`, a `core.css` — `132 KB`.
> - Aby zapewnić użytkownikom AssetMappera szybkie ładowanie stron bez wymuszania instalacji Node.js w projekcie docelowym, stosujemy dokładnie ten sam wzorzec **Źródła → Dystrybucja (`assets/dist/`)**, z którego korzystają oficjalne pakiety Symfony UX (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### Jak to działa

1. **Czytelne pliki źródłowe:** Wszystkie prace deweloperskie i testy bezpieczeństwa uruchamiane są na przejrzyście sformatowanych, skomentowanych plikach źródłowych:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` oraz `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` oraz `assets/images/liveux-logo.webp`
2. **Zminifikowane pliki produkcyjne (`assets/dist/`):** Skrypt [`assets/build.mjs`](../../assets/build.mjs) używa narzędzia **`esbuild`** do minifikacji każdego pliku JS jako samodzielnego modułu ESM (pozostawiając `@hotwired/stimulus` jako zależność zewnętrzną i zachowując importy względne) oraz kompiluje `core.css` do katalogu `assets/dist/`.
3. **Automatyczne rozwiązywanie ścieżek (zero zmian łamiących wsteczną kompatybilność):**
   - Metoda `prependAssetMapper()` w [`FileManagerBundle.php`](../../src/FileManagerBundle.php) automatycznie mapuje przestrzeń nazw `@liveux/file-manager-bundle` na `assets/dist/`, gdy tylko katalog `assets/dist` istnieje.
   - Wpisy `"exports"` oraz `"symfony.controllers.*.main"` w [`assets/package.json`](../../assets/package.json) sprawiają, że Webpack Encore również korzysta bezpośrednio z plików w `dist/`.
   - Ponieważ katalog `assets/dist/` jest zatwierdzony w repozytorium Git, **użytkownicy końcowi nigdy nie potrzebują Node.js ani npm**. Plik `.gitattributes` wyklucza z paczek Packagist wyłącznie narzędzia budowania (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`), pozostawiając `assets/package.json` oraz `assets/dist/`.

### Zmierzone oszczędności rozmiaru

| Plik | Źródło (surowe) | Zminifikowany (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 adaptery edytorów | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **RAZEM (`-45.1%` po minifikacji)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Komendy deweloperskie (po każdej edycji JS lub CSS)

```bash
# 1. Jednorazowa instalacja esbuild (tylko za pierwszym razem):
npm --prefix assets install

# 2. Przebudowanie assets/dist/ po edycji dowolnego pliku JS lub CSS (~50 ms):
npm --prefix assets run build
# lub przez skrót Composera:
composer assets:build

# 3. Weryfikacja, czy assets/dist/ jest w 100% zsynchronizowany z plikami źródłowymi (ten sam test co na CI):
composer assets:check
```

---

## 3. Przewodnik krok po kroku: jak wysłać Pull Request

> [!TIP]
> **Nawet jeśli nigdy wcześniej nie wysyłałeś PR**, wykonaj kolejno poniższe 6 kroków, aby otworzyć czysty Pull Request, który bez problemu przejdzie testy CI.

### Krok 1: Fork, klonowanie i instalacja zależności

1. Otwórz repozytorium [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) na GitHubie i kliknij **Fork** w prawym górnym rogu, aby utworzyć kopię na własnym koncie GitHub.
2. Sklonuj swojego forka lokalnie, dodaj główne repozytorium jako `upstream` i zainstaluj narzędzia deweloperskie PHP oraz Node:

```bash
git clone https://github.com/<TWOJA-NAZWA-UZYTKOWNIKA>/file-manager-bundle.git
cd file-manager-bundle

# Śledź główne repozytorium jako upstream (dobry nawyk, by być na bieżąco ze zmianami)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Zainstaluj zależności PHP do testów i analizy statycznej
composer install

# Zainstaluj minifikator JS/CSS (esbuild), jeśli planujesz edytować pliki JS/CSS
npm --prefix assets install
```

### Krok 2: Utwórz nową gałąź na bazie aktualnego `main`

Nigdy nie commituj bezpośrednio na gałąź `main`. Najpierw zsynchronizuj `main`, a następnie utwórz opisową gałąź dla swojej zmiany:
- Poprawka błędu: `fix/folder-counter-cache`
- Nowa funkcja: `feat/new-icon-set`
- Dokumentacja: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Krok 3: Napisz kod, dodaj testy i przebuduj `dist/`, jeśli to potrzebne

- **Naprawa błędu:** Zacznij od napisania ukierunkowanego testu regresyjnego, który odtwarza błąd (czerwony przed poprawką, zielony po niej).
- **Dodawanie nowej funkcji:**
  - Dodaj testy jednostkowe PHP w `tests/Unit/...`.
  - Przy zmianach w UI/JS dopisz testy w `tests/Js/dom_security_test.mjs` lub `tests/Js/frontend_audit_test.mjs`.
  - **Po każdej zmianie w plikach JS lub CSS uruchom `composer assets:build`** (w przeciwnym razie CI zakończy się błędem na etapie `build:check`).
  - Po dodaniu nowego klucza tłumaczenia interfejsu dopisz go do **wszystkich 12 wbudowanych katalogów językowych**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Krok 4: Uruchom lokalnie całą bramkę jakości za jednym zamachem

Zanim otworzysz PR, uruchom dokładnie te same kontrole, które wykonuje CI:

```bash
composer qa
composer assets:check
```

Polecenia te sprawdzają kolejno:
1. `composer validate --strict` — waliduje plik `composer.json`.
2. `composer cs:check` — sprawdza zgodność ze standardem kodowania PSR-12/Symfony *(w razie zgłoszenia uwag uruchom `composer cs:fix`, aby automatycznie sformatować kod)*.
3. `composer stan` — uruchamia analizę statyczną **PHPStan Level 8**.
4. `composer test` — uruchamia pełny zestaw testów PHPUnit: jednostkowych, bezpieczeństwa, kompletności tłumaczeń i schematu konfiguracji.
5. `composer test:js:syntax` — uruchamia weryfikację składni `node --check` dla wszystkich plików JS w źródłach oraz w `dist/`.
6. `composer test:js` — uruchamia testy Node.js bezpieczeństwa DOM i audytu frontendu (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — potwierdza, że katalog `assets/dist/` odpowiada aktualnym plikom źródłowym co do bajtu.

> [!TIP]
> **Wszystko na zielono?** Możesz utworzyć commit i otworzyć Pull Request.

### Krok 5: Utwórz czysty commit i wyślij gałąź na swojego forka

```bash
git status            # Upewnij się, że zmodyfikowane są tylko zamierzone pliki
git diff --stat       # Przejrzyj krótkie podsumowanie wprowadzonych zmian
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Stosuj czytelne prefiksy w konwencji Conventional Commits: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Krok 6: Otwórz Pull Request na GitHubie i sprawdź, czy CI świeci na zielono

1. Wejdź na stronę swojego forka na GitHubie (`https://github.com/<TWOJA-NAZWA-UZYTKOWNIKA>/file-manager-bundle`) i kliknij baner **„Compare & pull request”**.
2. Potwierdź gałęzie docelową i źródłową: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<TWOJA-NAZWA-UZYTKOWNIKA>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Wypełnij automatycznie podstawioną tabelę **szablonu PR** (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) i odznacz pozycje na liście kontrolnej (`[x]`). Jeśli zmiana wpływa na interfejs, dołącz zrzut ekranu lub GIF.
4. Kliknij **„Create pull request”**.
5. Poczekaj, aż wszystkie kontrole GitHub Actions (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) u dołu strony PR wyświetlą **zielony znacznik (✓)**.
6. Jeśli recenzent poprosi o poprawki, wprowadź zmiany na tej samej gałęzi i uruchom `git commit && git push` — GitHub automatycznie zaktualizuje PR i ponownie odpali CI.

---

## 4. Dla opiekunów repozytorium: wymuszanie ochrony gałęzi i Rulesets na GitHubie

Gdy pliki workflow znajdą się już w repozytorium, CI uruchamia się automatycznie — ale aby mieć pewność, że **nikt (nawet opiekun przez przypadek) nie kliknie Merge przy czerwonych testach**, skonfiguruj **Ruleset** na GitHubie w 2 minuty:

1. Otwórz repozytorium bundla na GitHubie i przejdź do zakładki **Settings**.
2. W lewym menu wybierz **Rules → Rulesets** (lub **Branches → Add branch protection rule** w widoku klasycznym).
3. Kliknij **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Włącz następujące 4 zabezpieczenia:
   - [x] **Restrict deletions** (uniemożliwia usunięcie gałęzi `main`)
   - [x] **Block force pushes** (chroni historię commitów)
   - [x] **Require a pull request before merging** (blokuje bezpośrednie pushe na `main`; wszystkie zmiany muszą trafiać przez PR)
     - *Required approvals:* `1` (zalecane dla zespołów; samodzielni opiekunowie mogą zostawić `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(najważniejsze ustawienie)*
     - [x] *Require branches to be up to date before merging*
     - Po pierwszym uruchomieniu CI użyj **Add checks**, aby wymagać wszystkich **6 zadań**:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Kliknij **Create** u dołu strony.

---

## 5. Lista kontrolna publikacji bundla jako samodzielnego repozytorium open-source

Aby opublikować bundle jako `LiveUX/file-manager-bundle` na GitHubie i Packagist:

1. Utwórz nowe, puste repozytorium **Public** o nazwie `file-manager-bundle` w organizacji `LiveUX` na GitHubie.
2. Wypchnij zawartość katalogu `bundles/liveux-file-manager-bundle/` jako pierwszy commit (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Sprawdź w zakładce **GitHub Actions**, czy wszystkie 6 zadań CI przeszło z zielonym znacznikiem.
4. Włącz ustawienia **Ruleset** opisane powyżej w **Sekcji 4**.
5. Utwórz tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) i zgłoś repozytorium `https://github.com/LiveUX/file-manager-bundle` w serwisie [Packagist.org](https://packagist.org).
6. Od tej chwili każdy może zainstalować bundle jednym poleceniem: `composer require liveux/file-manager-bundle`.

---

[← Poprzedni: 10 — Ochrona używanych zasobów](10-asset-usage-guard.md) | [Spis treści dokumentacji](README.md) | [Następny: 12 — Umiejętności agentów AI →](12-ai-agent-skills-guide.md)
