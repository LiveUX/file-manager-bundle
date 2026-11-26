<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Menedżer plików i DAM dla Symfony UX" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — Dokumentacja po polsku

**Język:** [English](../en/README.md) · [Türkçe](../tr/README.md) · [Français](../fr/README.md) · [Deutsch](../de/README.md) · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · **Polski** · [日本語](../ja/README.md) · [简体中文](../zh_CN/README.md) · [Główne README](../../README.md)

**LiveUX FileManagerBundle** to nowoczesny, wydajny **Menedżer plików i system Digital Asset Manager (DAM) dla Symfony UX** klasy korporacyjnej, zaprojektowany dla Symfony 6.4 LTS, 7.2+ oraz 8.x (PHP 8.3+). Działa przy **zerowych zależnościach od frameworków CSS**, oferuje 14 warstw wbudowanych zabezpieczeń oraz pełne wsparcie wielojęzyczne (12 wbudowanych języków interfejsu, wykrywanie ustawień regionalnych BCP-47 / POSIX i natywną obsługę RTL).

---

## Podgląd na żywo i zrzuty ekranu

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.shields.io/badge/YouTube-Watch_Full_14--Chapter_1080p_Showcase_(03:52)-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="Watch Full 14-Chapter 1080p Showcase on YouTube">
  </a>
</p>

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.youtube.com/vi/H2CAf0RiDd8/maxresdefault.jpg" alt="LiveUX FileManagerBundle — Full 14-Chapter 1080p YouTube Showcase" width="100%">
  </a>
</p>

<p align="center">
  <img src="../images/demo-showcase.gif" alt="Interaktywne demo LiveUX FileManagerBundle" width="100%">
</p>

> **Film prezentacyjny Full HD (1080p, ~3 min, 10 rozdziałów)** dla YouTube i wydań: [YouTube (1080p)](https://www.youtube.com/watch?v=H2CAf0RiDd8) · [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · Krótki podgląd: [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| Tryb jasny (`Light`) | Tryb ciemny (`Dark`) |
| :---: | :---: |
| ![Menedżer plików w trybie jasnym](../images/browser-light.png) | ![Menedżer plików w trybie ciemnym](../images/browser-dark.png) |

| Personalizacja koloru i ikony folderu | Okno „O wtyczce” (`LiveUX`) |
| :---: | :---: |
| ![Personalizacja koloru i ikony folderu](../images/folder-customize.png) | ![Okno modalne O wtyczce](../images/about-modal.png) |

### Edytory WYSIWYG, EasyAdmin i dynamiczne formularze (GIF)

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![Integracja z TinyMCE](../images/demo-editor-tinymce.gif) | ![Integracja z CKEditor 5](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2 (ProseMirror) | Quill 2 |
| :---: | :---: |
| ![Integracja z Tiptap 2](../images/demo-editor-tiptap.gif) | ![Integracja z Quill 2](../images/demo-editor-quill.gif) |

| Trix (EasyAdmin `TextEditorField`) | Summernote |
| :---: | :---: |
| ![Integracja z Trix](../images/demo-editor-trix.gif) | ![Integracja z Summernote](../images/demo-editor-summernote.gif) |

| Editor.js (edytor blokowy) | GrapesJS (wizualny kreator stron) |
| :---: | :---: |
| ![Integracja z Editor.js](../images/demo-editor-editorjs.gif) | ![Integracja z GrapesJS](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5 (`FileManagerField`) |
| :---: | :---: |
| ![Integracja z Meta Lexical](../images/demo-editor-lexical.gif) | ![Integracja z EasyAdmin 5](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` i `LiveCollectionType` |
| :---: |
| ![Integracja z CollectionType i LiveCollectionType](../images/demo-collections.gif) |

---

## Ścieżka szybkiego startu (2 minuty)

Nie wiesz, od czego zacząć? Przejdź od razu do przewodnika odpowiadającego Twojemu celowi:

- **Pierwsza instalacja:** [01 — Wymagania i instalacja](01-requirements-and-installation.md) → [02 — Konfiguracja i profile](02-configuration-and-profiles.md)
- **Dodanie pola wyboru obrazu lub galerii do formularza Symfony:** [05 — FormType i integracje z edytorami](05-formtype-and-editor-integrations.md)
- **Konfiguracja bezpieczeństwa i uprawnień RBAC na produkcję:** [06 — Bezpieczeństwo i autoryzacja](06-security-and-authorization.md)
- **Podłączenie Supabase, AWS S3 lub Cloudflare R2:** [07 — Pamięć w chmurze i dynamiczna konfiguracja](07-cloud-storage-and-dynamic-config.md)
- **Przechowywanie metadanych w Doctrine ORM / DBAL:** [13 — Integracja z Doctrine](13-doctrine-integration.md)
- **Ochrona przed usunięciem lub przeniesieniem zasobów używanych w bazie danych:** [10 — Ochrona użycia zasobów (Asset Usage Guard)](10-asset-usage-guard.md)

---

## Spis treści (Indeks dokumentacji)

| Rozdział | Przewodnik | Zakres |
| :---: | :--- | :--- |
| **01** | [Wymagania, zależności i instalacja](01-requirements-and-installation.md) | Wymagania PHP 8.3+ / Symfony 6.4 LTS, 7.2+ i 8.x, rozszerzenia PHP (w tym `ext-exif`, `ext-zip`), zależności Composer, konfiguracja AssetMapper i Webpack Encore, `file-manager:doctor` oraz pierwsza działająca strona |
| **02** | [Konfiguracja i profile (referencja YAML)](02-configuration-and-profiles.md) | `defaults`, `volumes`, `profiles`, bezkolizyjne ścieżki dynamiczne (`{user_id}`, `{Y}/{m}`), limity przesyłania i chunkingu, automatyczne nazewnictwo, optymalizacja i ograniczenia obrazów, miniatury, Kosz, ZIP, 5 gotowych przepisów produkcyjnych oraz kompletny schemat YAML |
| **03** | [Tryby użycia i JavaScript API](03-usage-modes-and-js-api.md) | Osadzany `TwigComponent`, Modal `<dialog>` i 4-kierunkowy panel OffCanvas, pełna referencja `window.LiveUXFileManager.open()`, niestandardowe zdarzenia DOM, dostępność RTL/IME/WCAG 2.1 AA, menu kontekstowe, wbudowany edytor obrazów i skróty klawiszowe |
| **04** | [Motywy, zestawy ikon i personalizacja](04-themes-icons-and-customization.md) | 9 wbudowanych motywów zgodnych z WCAG 2.1 AA (Bootstrap 5, Tailwind, Shadcn, Flowbite 4, DaisyUI 5, Tabler, Preline, Material, Unstyled), automatyczny/ręczny tryb ciemny, 6 zestawów ikon SVG (49 ikon UI + 14 ikon odznak), helpery Twig i 3 poziomy własnego stylowania |
| **05** | [FormType i integracje z edytorami](05-formtype-and-editor-integrations.md) | `FileManagerPickerType` (pojedynczy wybór i dostępna galeria z sortowaniem + weryfikacja POST), 5 wbudowanych wariantów (`compact`, `card`, `dropzone`, `avatar`, `input`), `CollectionType` / `LiveCollectionType`, własne szablony, TinyMCE, CKEditor 5, Tiptap oraz 16-metodowe pole EasyAdmin `FileManagerField` |
| **06** | [Bezpieczeństwo i autoryzacja (RBAC)](06-security-and-authorization.md) | 17 uprawnień Votera, własne Votery `unanimous`, reguły firewalla w `security.yaml`, 14 warstw ochrony (CSRF, path traversal, 69 rozszerzeń / 47 typów MIME, 55-tagowy `SvgSanitizer`, ochrona przed DNS-rebindingiem SSRF, izolacja dowiązań symbolicznych `ConfinedLocalFilesystemAdapter`, podpisane linki HMAC, logi audytowe) oraz zabezpieczenie Apache/Nginx |
| **07** | [Pamięć w chmurze (S3/R2/MinIO/Supabase) i dynamiczna konfiguracja](07-cloud-storage-and-dynamic-config.md) | Wielowolumenowa architektura lokalna i chmurowa, przewodniki dostawców ([Supabase](cloud/supabase-storage.md), [AWS S3](cloud/aws-s3.md), [Cloudflare R2](cloud/cloudflare-r2.md), [Backblaze B2](cloud/backblaze-b2.md), [MinIO/RustFS](cloud/minio-rustfs.md), [Google Cloud Storage](cloud/google-cloud-storage.md), [SFTP i własny Flysystem](cloud/sftp-and-custom-flysystem.md)), `FlysystemServiceLocatorPass`, `ConfigProviderInterface` i własny magazyn metadanych |
| **08** | [Zdarzenia, polecenia CLI, cache i rozwiązywanie problemów](08-events-commands-cache-and-troubleshooting.md) | 9 zdarzeń domenowych Symfony, 10 poleceń konsolowych (`doctor`, `stats`, `duplicates`, `ai:enrich`, `skills:install` itd.), 3-warstwowy cache i kontekstowa izolacja HTTP 304/CDN, 12-językowy system tłumaczeń BCP-47/POSIX/RTL (493 klucze) oraz FAQ |
| **09** | [Integracja z Symfony AI i inteligentne wzbogacanie](09-symfony-ai-integration.md) | Kontrakt `AssetEnricherInterface` bez wymaganych zależności, mostek `symfony/ai-bundle` (OpenAI, Gemini, Claude, Ollama), **✨ Zasugeruj z AI**, wsadowe polecenie `file-manager:ai:enrich`, asynchroniczne przykłady dla Messenger oraz 11-językowe filtrowanie odmów LLM i prompt injection |
| **10** | [Ochrona użycia zasobów (Asset Usage Guard)](10-asset-usage-guard.md) | Opcjonalny kontrakt `AssetUsageCheckerInterface`, polityki `warn` / `block`, dwuwarstwowa ochrona po stronie serwera (`enforce_server_side`) dla operacji **`delete`, `rename`, `move` i `overwrite`** oraz przykłady Doctrine dla MySQL, PostgreSQL i SQLite |
| **11** | [Współpraca i przewodnik Pull Request](11-contributing-and-pr-guide.md) | Przyjazny dla początkujących proces open-source PR, bramka jakości `composer qa` (PHPStan poziom 8, 360+ testów PHPUnit, 37+ testów JS), 6-zadaniowe CI GitHub Actions oraz konfiguracja GitHub Branch Protection |
| **12** | [Przewodnik AI Agent Skills (`SKILL.md` i `AGENTS.md`)](12-ai-agent-skills-guide.md) | 6 modularnych pakietów `SKILL.md` dla Antigravity, Claude Code, Cursor, GitHub Copilot, Codex i Symfony AI Mate, instalacja jednym poleceniem `file-manager:skills:install` oraz walidacja CI |
| **13** | [Integracja z Doctrine ORM i DBAL](13-doctrine-integration.md) | Wbudowany sterownik `metadata.driver: 'doctrine'` (`DoctrineDbalMetadataStorage` — atomowy UPSERT, indeks PostgreSQL `text_pattern_ops`, wsadowe `saveMany()`), wieloplatformowe migracje, encje + `FileManagerPickerType`, własny magazyn ORM obsługujący `ResetInterface` oraz synchronizacja bazy przez `ItemRenamedEvent` |
| **14** | [Audyt bezpieczeństwa, przegląd logiki i raport zmian](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(tylko po turecku)* | Szczegółowy audyt bezpieczeństwa (rundy 1–18), rozłączna izolacja multi-tenant `{user_id}`, wzmocnienie DI/Votera/Parsera/budżetu kroków `<use>` w SVG oraz ochrony CDN/spoofingu pochodzenia, izolacja symlinków, indeks PostgreSQL `text_pattern_ops` i atomowy UPSERT, audyt kontrastu/fokusu WCAG 2.1 AA dla 9 motywów, mobilne okna dialogowe oraz dziennik audytu i18n |

---

## Najważniejsze cechy architektury

- **Hybrydowa szybkość 0 ms:** Błyskawiczny `TwigComponent` po stronie serwera (SSR) + pamięć podręczna w przeglądarce (`dirCache`) + pełne drzewo folderów pobierane jednym żądaniem. Filtrowanie, wyszukiwanie, sortowanie, paginacja, przełączanie widoków Siatka/Lista oraz rozwijanie i zwijanie folderów odbywają się natychmiast, bez zbędnych zapytań AJAX.
- **Zero zależności od frameworków CSS i zgodność z WCAG 2.1 AA:** Cały interfejs korzysta z izolowanych klas `.liveux-fm-*` i zmiennych CSS (`--liveux-fm-*`). Działa bezproblemowo obok **Bootstrap 5, Tailwind CSS, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** lub **bez żadnego frameworka CSS** — bez kolizji stylów i bez dodatkowej konfiguracji purge w Tailwind.
- **Natywny Top Layer HTML5 `<dialog>` i ergonomia Mobile-First:** Okna Modal i panele OffCanvas (`start`, `end`, `top`, `bottom`) renderują się w warstwie Top Layer przeglądarki, a na urządzeniach mobilnych automatycznie przełączają się w pełny ekran (`100dvh`) lub natywny dolny arkusz (bottom sheet) z uchwytem przeciągania.
- **14 warstw bezpieczeństwa klasy korporacyjnej:** RBAC oparty na `Voter` w Symfony, ochrona CSRF (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `FileSecurityGuard`, 55-tagowy `SvgSanitizer`, odporny na DNS rebinding `RemoteUrlImporter`, izolacja dowiązań symbolicznych `ConfinedLocalFilesystemAdapter`, ochrona przed bombami dekompresyjnymi i pikselowymi oraz domyślne nagłówki `CSP: sandbox` + `nosniff`.
- **Globalna architektura wielojęzyczna:** **12 wbudowanych języków interfejsu** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` — po 493 zsynchronizowane klucze każdy), normalizacja ustawień regionalnych BCP-47 i POSIX `.UTF-8`, gwarantowany fallback do `'en'`, automatyczne RTL (`dir="rtl"`) dla języka arabskiego i 14 innych języków RTL oraz bezpieczna obsługa kompozycji IME dla CJK.
- **Gotowość na środowiska wieloserwerowe i chmurę:** Wbudowany `DoctrineDbalMetadataStorage` (PostgreSQL `text_pattern_ops`, atomowy UPSERT dla MySQL/MariaDB/SQLite), Flysystem v3 (S3, R2, Supabase, B2, MinIO, GCS, SFTP) oraz zgodność z `ResetInterface` dla FrankenPHP i workerów Symfony Messenger.

---

## Lista kontrolna szybkiego startu (5 minut)

1. **Zainstaluj paczkę:** `composer require liveux/file-manager-bundle`
2. **Zaimportuj trasy:** `config/routes/liveux_file_manager.yaml`
3. **Zdefiniuj profile:** `config/packages/liveux_file_manager.yaml`
4. **CSS i JS (bez konfiguracji):** mechanizm `autoimport` w Symfony UX automatycznie ładuje `core.css`, `file_manager_api.js` oraz wszystkie 3 kontrolery Stimulus poprzez `assets/controllers.json` zarówno w AssetMapper, jak i w Webpack Encore.
5. **Host okien dialogowych (automatycznie):** `AutoInjectDialogHostSubscriber` automatycznie wstrzykuje `DialogHost` tuż przed `</body>` na pełnych stronach HTML (`defaults.auto_inject_dialog_host: true`) — bez edycji `base.html.twig`.
6. **Zweryfikuj instalację:** `php bin/console file-manager:doctor`

> Dopiero zaczynasz z Symfony UX? Przejdź krok po kroku przez rozdział **[01 — Wymagania, zależności i instalacja](01-requirements-and-installation.md)**.

---

**Organizacja:** [LiveUX na GitHubie](https://github.com/LiveUX) · [Repozytorium](https://github.com/LiveUX/file-manager-bundle)  
**Licencja:** MIT
