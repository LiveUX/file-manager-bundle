# 01 — Wymagania, zależności i instalacja

[← Indeks dokumentacji](README.md) | [Dalej: 02 — Konfiguracja i profile →](02-configuration-and-profiles.md)

Ten przewodnik przeprowadzi Cię przez instalację LiveUX FileManagerBundle od zera — wyjaśniając **każdy krok i jego cel**, nawet jeśli to Twoja pierwsza paczka z ekosystemu Symfony UX.

> [!NOTE]
> **Mówiąc wprost: do czego służy ten bundle?**  
> Dodaje pełnoprawny menedżer plików (podobny do Dysku Google lub Eksploratora Windows) do Twojej aplikacji Symfony — niezależnie od tego, czy to panel administracyjny, sklep e-commerce czy portal klienta. Użytkownicy mogą **przesyłać pliki, porządkować foldery, kadrować obrazy, podglądać pliki PDF i wideo oraz wybierać zasoby bezpośrednio w formularzach** jednym kliknięciem.  
> Nie wymaga **żadnego frameworka CSS** (Bootstrap, Tailwind itp.) ani **jQuery**, a dzięki Symfony AssetMapper działa w 5 minut bez Node.js i npm.

---

## 1. Wymagania systemowe

Przed instalacją sprawdź, czy Twoje środowisko lokalne lub serwer spełnia poniższe wymagania minimalne:

| Komponent | Minimalna wersja | Dlaczego jest wymagany | Polecenie sprawdzające |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` lub nowsza | Wykorzystuje funkcje PHP 8.3/8.4 (klasy `readonly`, typowane stałe klas). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` lub `8.x` | W pełni przetestowany i wspierany na Symfony 6.4 LTS, 7.2, 7.3, 7.4 oraz 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Instalacja pakietów i autoloading PSR-4. | `composer --version` |
| **Przeglądarka** | Nowoczesna przeglądarka evergreen | Natywna warstwa Top Layer HTML5 `<dialog>`, moduły ES oraz zmienne CSS (Chrome, Edge, Firefox, Safari). | — |

---

## 2. Rozszerzenia PHP

Rozszerzenia PHP zapewniają dodatkowe natywne możliwości, takie jak analiza zawartości plików, parsowanie XML czy przetwarzanie obrazów.

### Wymagane rozszerzenia PHP
Domyślnie włączone w niemal każdej współczesnej instalacji PHP:

| Rozszerzenie | Do czego służy | Przykład z praktyki |
| :--- | :--- | :--- |
| `ext-fileinfo` | Sprawdza **rzeczywistą zawartość pliku (magic bytes)**, a nie tylko jego rozszerzenie. | Jeśli atakujący zmieni nazwę `shell.php` na `photo.jpg` i spróbuje go przesłać, `ext-fileinfo` natychmiast to wykryje i odrzuci plik. |
| `ext-dom` i `ext-libxml` | Bezpiecznie parsuje drzewo XML przesyłanych plików `.svg` (`SvgSanitizer`). | Usuwa złośliwe tagi `<script>` lub ładunki XXE ukryte w przesłanym logo SVG, zachowując samą czystą grafikę. |

*(Uwaga: rozszerzenie `ext-json` jest wbudowane w rdzeń PHP od wersji PHP 8.0 i nie wymaga osobnej instalacji.)*

### Opcjonalne (zalecane) rozszerzenia PHP
Jeśli któregoś z nich zabraknie, menedżer plików **nadal będzie działał** i automatycznie przełączy się na bezpieczny tryb awaryjny:

| Rozszerzenie | Do czego służy | Zachowanie awaryjne w przypadku braku |
| :--- | :--- | :--- |
| `ext-gd` **lub** `ext-imagick` | Generuje szybkie miniatury WebP/JPEG oraz wykonuje automatyczną optymalizację obrazów podczas przesyłania. | Wyświetla oryginalny obraz lub ikonę pliku zgodnie z ustawieniem `thumbnail.on_missing_driver`. |
| `ext-exif` | Odczytuje metadane orientacji EXIF ze smartfonów podczas korzystania z `ext-gd`, automatycznie obracając zdjęcia zapisane bokiem (`auto_orient: true`). | Zdjęcia zapisywane są w oryginalnej orientacji (nie jest wymagane, jeśli zainstalowano `ext-imagick`). |
| `ext-zip` (`ZipArchive`) | Umożliwia pobieranie wielu plików jako `.zip` **oraz** bezpieczne rozpakowywanie archiwów (`zip.allow_extract: true`). | Przyciski **Pobierz jako ZIP** oraz **Rozpakuj archiwum** są automatycznie ukrywane. |

Sprawdź aktywne rozszerzenia w swoim środowisku jednym poleceniem:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Wymagane i opcjonalne pakiety Composer

Po uruchomieniu `composer require liveux/file-manager-bundle` **wszystkie wymagane pakiety zostaną zainstalowane automatycznie**. Wiedza o roli poszczególnych zależności ułatwia późniejsze debugowanie:

### Wymagane pakiety (instalowane automatycznie)
| Pakiet | Zakres wersji | Przeznaczenie |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Ujednolicona abstrakcja systemu plików dla dysków lokalnych, AWS S3, Cloudflare R2, Supabase, MinIO i SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Renderuje menedżer plików przez `{{ component('LiveUX:FileManager:Browser') }}` z SSR 0 ms. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Obsługuje pamięć podręczną katalogów w przeglądarce, drag-and-drop, menu kontekstowe, Lightbox i selektory formularzy. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Rdzeń uruchomieniowy Symfony, routing i pule cache (`cache.app`). |
| `symfony/config` i `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Walidacja schematu `liveux_file_manager.yaml` oraz rejestracja usług w kontenerze DI. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Emituje 9 zdarzeń domenowych (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent` itd.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Bezpieczne pobieranie plików, strumieniowanie wideo HTTP 206 (`Range`), ETag i nagłówki cache HTTP. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Szablony Twig, helpery ikon i tłumaczeń oraz automatyczny motyw formularza FormType. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Pole formularza `FileManagerPickerType` dla pojedynczych plików i galerii wieloplikowych. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Wykrywanie typu MIME przesyłanych plików po stronie serwera. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Transliteracja wielojęzycznych nazw plików (polskich, tureckich, francuskich, arabskich, cyrylicy itd.) do czystych slugów SEO. |
| `symfony/security-core` i `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | 17 uprawnień RBAC w Voterze oraz ochrona `X-LiveUX-FM-CSRF`. |
| `symfony/cache-contracts` i `symfony/translation-contracts` | `^3.5` | Kontrakty pamięci podręcznej katalogów oraz wielojęzycznego interfejsu. |

### Opcjonalne pakiety (instaluj tylko w razie potrzeby)
| Pakiet | Polecenie instalacji | Kiedy warto zainstalować |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Dostarczanie JS i CSS bez procesu budowania, bez Node.js i npm *(Zalecane)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Uprawnienia oparte na rolach (`ROLE_USER` / `ROLE_ADMIN`) oraz izolacja katalogów per użytkownik (`{user_id}`). |
| `symfony/translation` | `composer require symfony/translation` | Integracja z tłumaczem Symfony dla wszystkich **12 wbudowanych języków interfejsu** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) lub własnych ustawień regionalnych aplikacji. |
| `symfony/console` i `symfony/yaml` | `composer require symfony/console symfony/yaml` | Polecenia CLI (`file-manager:doctor`, `thumbs:warmup`, `trash:purge` itd.) oraz pliki konfiguracyjne YAML. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Podłączanie AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO lub Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Wdrożenia wieloserwerowe / za load balancerem z `defaults.metadata.driver: 'doctrine'` (zobacz [Rozdział 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Wbudowane pole `FileManagerField` dla paneli EasyAdmin 4/5 (zobacz [Rozdział 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | Funkcja **✨ Zasugeruj z AI** — automatyczne generowanie tekstu alternatywnego, opisu i tagów (zobacz [Rozdział 09](09-symfony-ai-integration.md)). |

---

## 4. Instalacja krok po kroku

Wykonaj kolejno poniższe 7 kroków, aby uruchomić działający menedżer plików.

### Krok 1: Zainstaluj bundle

**Standardowa instalacja (z Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Lokalne repozytorium ścieżkowe (jeśli rozwijasz paczkę w `bundles/liveux-file-manager-bundle`):**  
Dodaj sekcję `repositories` w głównym pliku `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Następnie dodaj lokalną paczkę:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Krok 2: Sprawdź `config/bundles.php`

Symfony Flex zazwyczaj rejestruje bundle automatycznie. Jeśli tak się nie stało, upewnij się, że w pliku `config/bundles.php` znajdują się następujące wpisy:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Jeśli używasz uwierzytelniania/ról
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **automatycznie rejestruje** swoją przestrzeń nazw Twig (`@LiveUXFileManager`), ścieżkę AssetMapper (`@liveux/file-manager-bundle`) oraz motyw FormType (`@LiveUXFileManager/form/picker.html.twig`) podczas startu aplikacji. Nie musisz edytować `twig.yaml` ani `asset_mapper.yaml`.

---

### Krok 3: Zaimportuj trasy (routing)

Utwórz plik `config/routes/liveux_file_manager.yaml`, aby aktywować endpointy AJAX API, pobierania, miniatur i przesyłania plików:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Ten jeden import rejestruje **12 zabezpieczonych endpointów**:

| Metoda i ścieżka | Przeznaczenie |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Ładuje kod HTML menedżera plików wewnątrz okna Modal lub panelu OffCanvas. |
| `GET /_liveux-file-manager/api/list` | Zwraca listę plików i podfolderów w katalogu w formacie JSON. |
| `GET /_liveux-file-manager/api/tree` | Pobiera pełne drzewo folderów dla panelu bocznego w jednym żądaniu. |
| `GET /_liveux-file-manager/api/trash` | Zwraca listę elementów przeniesionych do Kosza (soft delete). |
| `GET /_liveux-file-manager/api/preview-text` | Zwraca bezpieczny (ograniczony do 64 KB) podgląd tekstu/kodu dla `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql` itd. |
| `POST /_liveux-file-manager/api/action` | Wykonuje operacje modyfikujące: tworzenie folderu, przenoszenie, kopiowanie, zmianę nazwy, usuwanie, przywracanie/opróżnianie Kosza, kolory/ikony folderów, metadane, wzbogacanie AI, import ze zdalnego URL oraz rozpakowywanie ZIP. |
| `POST /_liveux-file-manager/upload` | Obsługuje przesyłanie plików — zarówno standardowe, jak i w częściach (chunked upload). |
| `POST /_liveux-file-manager/image-edit` | Zapisuje wykadrowane, przeskalowane lub obrócone obrazy z wbudowanego edytora Canvas. |
| `GET /_liveux-file-manager/download` | Obsługuje bezpieczne pobieranie, podgląd inline PDF/wideo (z obsługą `Range`) oraz wolumeny `private: true`. |
| `GET /_liveux-file-manager/share` | Udostępnia czasowe linki podpisane kryptograficznie HMAC. |
| `POST /_liveux-file-manager/zip` | Strumieniuje zaznaczone pliki i foldery jako pojedyncze archiwum `.zip`. |
| `GET /_liveux-file-manager/thumb` | Generuje i serwuje miniatury WebP/JPEG/PNG wraz z nagłówkami ETag i kontekstowego cache HTTP. |

---

### Krok 4: Utwórz `config/packages/liveux_file_manager.yaml`

Utwórz plik `config/packages/liveux_file_manager.yaml`. Poniższa konfiguracja startowa jawnie wymienia **wszystkie 17 szczegółowych uprawnień**:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = automatycznie dziedziczy aktywny język Symfony (zawsze z fallbackiem do 'en')
                            # Lub wymuś konkretny język, np. 'pl', 'en', 'de', 'fr', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'Moje pliki'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # Rozpakowywanie ZIP jest domyślnie wyłączone (wymaga także zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Kluczowa uwaga dotycząca bezpieczeństwa (`permissions`):**  
> Każdy klucz, który **pominiesz** w sekcji `permissions` (poza `extract_zip`), domyślnie przyjmuje wartość `true`. Wartość `true` oznacza tutaj **„każdy zalogowany użytkownik”** — a NIE anonimowy gość. Jeśli trwałe usuwanie z Kosza (`trash_purge`) lub tworzenie linków udostępniania (`share`) ma być dostępne tylko dla `ROLE_ADMIN`, ustaw te klucze jawnie na `'ROLE_ADMIN'`.

> [!TIP]
> **Testujesz lokalnie bez systemu logowania?**  
> Podczas lokalnego prototypowania bez uwierzytelniania możesz tymczasowo ustawić `profiles.default.security.voter_enabled: false`. **NIGDY nie zostawiaj `voter_enabled: false` na produkcji** — zobacz [06 — Bezpieczeństwo i autoryzacja](06-security-and-authorization.md).

> [!NOTE]
> **12 wbudowanych języków interfejsu i automatyczne RTL (`defaults.locale: null`):**  
> Pozostawienie `locale: null` sprawia, że menedżer plików automatycznie dopasowuje się do aktywnego języka żądania Symfony spośród **12 wbudowanych języków UI** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), automatycznie włącza **RTL (`dir="rtl"`)** dla arabskiego i innych języków RTL (perskiego, hebrajskiego, urdu itp.), normalizuje formaty BCP-47 i POSIX (`de_DE.UTF-8`, `zh-Hans-CN`) oraz przełącza się na **angielski (`en`)** dla nieobsługiwanych języków.

---

### Krok 5: Podłącz CSS i JavaScript (bez konfiguracji!)

**Nie wiesz, czy Twój projekt używa AssetMapper czy Webpack Encore?**  
Sprawdź katalog główny projektu:
- Jeśli widzisz `importmap.php` → wybierz **Opcję A (AssetMapper)**.
- Jeśli widzisz `webpack.config.js` → wybierz **Opcję B (Webpack Encore)**.

> [!TIP]
> **Bezkonfiguracyjne wsparcie `autoimport`:**  
> LiveUX FileManagerBundle deklaruje **`autoimport`** w swoim pliku `assets/package.json` dla `@liveux/file-manager-bundle/styles/core.css` oraz kontrolera `dialog_controller.js` ładowanego w trybie `eager` (który importuje `file_manager_api.js`). Po włączeniu wpisów w `assets/controllers.json` **nie musisz dopisywać ani jednego ręcznego `import` w `assets/app.js`!**

#### Opcja A: Symfony AssetMapper (Zalecana — bez Node.js i npm)

1. Upewnij się, że kontrolery pakietu są włączone w pliku `assets/controllers.json` (Symfony Flex dodaje je automatycznie; w przeciwnym razie dodaj poniższy blok):
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Projekty Symfony 6.4 / 7.2+ / 8.x wywołują już `startStimulusApp()` w pliku `assets/bootstrap.js` lub `assets/stimulus_bootstrap.js`, co automatycznie odczytuje `controllers.json`:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) lub assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Upewnij się, że Twój szablon bazowy (`templates/base.html.twig`) wywołuje `importmap('app')`:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Opcjonalnie — przed wdrożeniem na produkcję)* Skompiluj zasoby statyczne:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Wstępnie zminifikowane zasoby produkcyjne (`assets/dist/`):**  
> Ponieważ AssetMapper domyślnie nie minifikuje plików JS/CSS, LiveUX FileManagerBundle dostarcza gotowe, zminifikowane przy użyciu `esbuild` moduły JavaScript oraz `core.css` w katalogu `assets/dist/` (`747 KB → 410 KB` po minifikacji; `~93 KB` przez Gzip lub **`~77 KB`** przez Brotli). `FileManagerBundle` automatycznie mapuje `assets/dist/` do AssetMappera, więc **ani stacja robocza, ani serwer produkcyjny nie potrzebują Node.js ani npm**.  
> W Symfony 7.3+ / 8.x możesz dodatkowo włączyć `precompress: true` w `config/packages/asset_mapper.yaml`, aby podczas `asset-map:compile` automatycznie generować pliki `.br` i `.gz`.

#### Opcja B: Webpack Encore

1. W projektach korzystających z Webpack Encore (w tym Symfony 8 + StimulusBundle 3.x) upewnij się, że zainstalowano `@symfony/stimulus-bridge`:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Zweryfikuj 3 kontrolery Stimulus oraz sekcję `autoimport` w pliku `assets/controllers.json`:
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. Sprawdź, czy `startStimulusApp` jest skonfigurowane w `assets/bootstrap.js` (`assets/app.js` importuje ten plik — ręczne importy CSS/JS nie są potrzebne):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Zbuduj zasoby:
```bash
npm run build
# Lub uruchom nasłuchiwanie zmian podczas pracy: npm run watch
```

---

### Krok 6: `DialogHost` (automatyczne wstrzykiwanie — bez dodatkowego kodu HTML!)

Otwieranie okien modalnych, paneli OffCanvas, pól `FileManagerPickerType` czy selektorów TinyMCE/CKEditor/Tiptap z dowolnej strony wymaga kontenera `<dialog>` w drzewie DOM.

Subskrybent `AutoInjectDialogHostSubscriber` automatycznie wstrzykuje ten host tuż przed znacznikiem `</body>` w pełnych odpowiedziach HTML (`defaults.auto_inject_dialog_host: true`) — nie musisz więc modyfikować `base.html.twig`.

- **Inteligentne wykrywanie:** Nigdy nie ingeruje w odpowiedzi JSON, AJAX, Turbo Frame, przekierowania ani binarne pobierania plików, a także pomija wstrzyknięcie, jeśli ręczny `DialogHost` już istnieje w dokumencie.
- **0 ms narzutu przy ładowaniu strony:** Podczas renderowania strony nie wykonuje zapytań o zawartość katalogów ani drzewa folderów; dane są pobierane dopiero w momencie otwarcia okna dialogowego.
- **Wolisz ręczne osadzanie tylko na wybranych stronach?** Ustaw `defaults.auto_inject_dialog_host: false` i dodaj poniższy fragment tuż przed `</body>` w wybranych szablonach:

```twig
{# Opcjonalne — wymagane tylko gdy auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Krok 7: Zweryfikuj instalację (`file-manager:doctor`)

Wyczyść pamięć podręczną i uruchom wbudowane narzędzie diagnostyczne:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Jeśli skonfigurowałeś wolumeny chmurowe (S3, R2, Supabase, MinIO), dodaj flagę `--check-remote`, aby przetestować realne połączenie:
```bash
php bin/console file-manager:doctor --check-remote
```

Przykładowy wynik poprawnej konfiguracji (9 testów środowiska + tabela statusu profili i wolumenów):
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Użytkownicy Windows (`cURL error 60`):** Jeśli test `cURL CA Bundle` zgłasza ostrzeżenie, wskaż w `php.ini` aktualny plik `cacert.pem` dla `curl.cainfo` i `openssl.cafile` lub skonfiguruj `driver_options.ca_bundle` na wolumenie S3.
>
> **Uprawnienia plików w systemie Linux:** Upewnij się, że katalogi `public/uploads`, `public/media-cache` oraz `var/file-manager` mają uprawnienia zapisu dla użytkownika serwera WWW (`www-data`, `nginx` itp.).
>
> **Korzystasz z asystenta AI do kodowania (Antigravity, Claude Code, Cursor, Copilot)?**  
> Naucz asystenta wszystkich konwencji i gotowych wzorców tego pakietu jednym poleceniem (zobacz [Rozdział 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Zbuduj pierwszą działającą stronę

Stwórzmy prosty kontroler Symfony oraz szablon Twig, aby przetestować cały przepływ od początku do końca.

### 1) Kontroler (`src/Controller/MediaController.php`)
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Szablon Twig (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Biblioteka mediów</h1>

    {# Opcja 1: Menedżer plików osadzony bezpośrednio na stronie (inline) #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Opcja 2: Otwórz okno Modal z dowolnego przycisku i odbierz wybrane pliki #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Wybierz pliki w oknie Modal</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // Użytkownik zamknął okno bez wyboru pliku
            }

            document.getElementById('selection-result').textContent =
                'Wybrano: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Otwórz `/admin/media` w przeglądarce. Gratulacje — Twój menedżer plików już działa!

---

## 6. Co dalej?

- Skonfiguruj własne profile, limity przesyłania i reguły obrazów → **[02 — Konfiguracja i profile](02-configuration-and-profiles.md)**
- Dodaj pole wyboru obrazu lub galerii do formularzy Symfony → **[05 — FormType i integracje z edytorami](05-formtype-and-editor-integrations.md)**
- Wzmocnij uprawnienia RBAC i zabezpieczenia przed wdrożeniem na produkcję → **[06 — Bezpieczeństwo i autoryzacja](06-security-and-authorization.md)**

---

[← Indeks dokumentacji](README.md) | [Dalej: 02 — Konfiguracja i profile →](02-configuration-and-profiles.md)
