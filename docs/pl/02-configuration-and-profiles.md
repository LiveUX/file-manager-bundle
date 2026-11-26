# 02 — Konfiguracja i profile (pełna referencja parametrów i gotowe przepisy)

[← Poprzedni: 01 — Wymagania i instalacja](01-requirements-and-installation.md) | [Indeks dokumentacji](README.md) | [Dalej: 03 — Tryby użycia i JS API →](03-usage-modes-and-js-api.md)

Ten przewodnik opisuje **każdą opcję** w pliku `config/packages/liveux_file_manager.yaml`, jej wartość domyślną, sytuacje, w których warto ją zmienić, oraz **5 gotowych do skopiowania przepisów produkcyjnych**, które możesz od razu wkleić do swojego projektu.

---

## Model mentalny w 30 sekund: `defaults`, `volumes` i `profiles`

Pomyśl o pliku konfiguracyjnym jako o **3 przejrzystych warstwach**:

1. **`defaults` (Globalne ustawienia domyślne):** Wspólna baza dziedziczona przez każdy profil (motyw, język, schemat kolorów, cache, sterownik metadanych, logi audytowe, ustawienia AI). Jeśli profil nie nadpisze danej opcji, obowiązują wartości z tej sekcji.
2. **`volumes` (Wolumeny pamięci / dyski i buckety):** Określają, **gdzie pliki fizycznie są przechowywane** (katalog lokalny, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage lub własna usługa Flysystem).
3. **`profiles` (Profile użycia):** Definiują **reguły, układ interfejsu i uprawnienia** dla konkretnego ekranu lub procesu. Przykładowo:
   - `product_catalog` akceptuje wyłącznie kwadratowe zdjęcia produktów `1:1` i automatycznie konwertuje je do WebP;
   - `customer_docs` łączy się z prywatnym bucketem Supabase S3, przyjmuje tylko pliki PDF i izoluje każdego użytkownika we własnym folderze;
   - `editor_picker` otwiera uproszczony selektor modalny dla TinyMCE z wyborem pliku po dwukrotnym kliknięciu.

---

## 1. `defaults` — Globalne ustawienia domyślne

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = dziedziczy aktywny język Symfony (fallback do 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parametr | Domyślnie | Do czego służy | Kiedy warto zmienić |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Domyślny katalog główny dla uproszczonych profili definiujących `path` bezpośrednio bez sekcji `volumes`. | Gdy przechowujesz lokalne pliki w innym katalogu, np. `public/media`. |
| `theme` | `'bootstrap5'` | Domyślny motyw interfejsu dla wszystkich profili (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` lub nazwa własnego motywu). | Aby dopasować wygląd do design systemu aplikacji (np. `'shadcn'` lub `'tailwind'` w projektach Tailwind). |
| `locale` | `null` | Gdy ustawione na `null`, automatycznie dziedziczy aktywny język żądania/tłumacza Symfony spośród wszystkich **12 wbudowanych języków interfejsu** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), normalizuje formaty BCP-47 i POSIX (`de_DE.UTF-8`, `zh-Hans-CN`), automatycznie włącza RTL (`dir="rtl"`) dla arabskiego i innych języków RTL oraz wraca do `'en'` dla nieznanych języków. | Ustaw jawnie (np. `'pl'`, `'en'`, `'de'`), aby przypiąć wszystkich użytkowników do jednego języka UI. |
| `color_scheme` | `'auto'` | Motyw kolorystyczny: `'auto'` (wykrywa `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (zawsze jasny) lub `'dark'` (zawsze ciemny). | Gdy chcesz wymusić wyłącznie jasny lub wyłącznie ciemny motyw panelu. |
| `z_index` | `1050` | Bazowy poziom nakładania (`--liveux-fm-z-base`) dla okien modalnych, paneli OffCanvas, Lightboxa i menu kontekstowych (`>= 1`). | Zwiększ np. do `10050`, jeśli pasek nawigacji w Twoim panelu ma `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | Automatycznie wstawia host `<dialog>` wymagany przez `window.LiveUXFileManager.open()` przed `</body>` na pełnych stronach HTML (bez edycji `base.html.twig`). | Ustaw `false`, jeśli wolisz ręcznie dołączać `@LiveUXFileManager/components/DialogHost.html.twig` tylko w wybranych layoutach. |
| `dialog_host_profile` | `'default'` | Domyślny profil używany przez automatycznie wstrzyknięty `DialogHost`, gdy nie podano nazwy profilu. | Gdy Twój główny profil nie nazywa się `default` (np. `admin_media`). |
| `cache.enabled` | `true` | Serwerowa pamięć podręczna PSR-6 dla list katalogów i drzewa folderów. | Ustaw `false` podczas lokalnego debugowania (lub wyłącz dla konkretnego profilu/wolumenu). |
| `cache.pool` | `'cache.app'` | Pula cache PSR-6 Symfony (Redis, Filesystem, APCu itd.). Automatycznie wykorzystuje `<pool>.taggable`, jeśli jest dostępna, do precyzyjnego unieważniania po tagach. | Aby odizolować wpisy cache menedżera plików w dedykowanej puli Redis. |
| `cache.ttl` | `3600` | Czas życia pamięci podręcznej w sekundach (domyślnie 1 godzina). Operacje modyfikujące (przesyłanie, usuwanie, przenoszenie, zmiana nazwy) automatycznie unieważniają zmienione katalogi. | Obniż do `60`–`300`, jeśli pliki są często modyfikowane z zewnątrz przez FTP lub panel chmurowy. |
| `metadata.enabled` | `true` | Przechowuje kolory/ikony/przypięcia folderów oraz metadane SEO plików (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Ustaw `false` w prostych selektorach plików niewymagających metadanych redakcyjnych. |
| `metadata.driver` | `'json_file'` | Backend metadanych: `'json_file'` (plik lokalny dla aplikacji jednoserwerowych), `'doctrine'` (wbudowany magazyn DBAL dla wdrożeń wieloserwerowych / za load balancerem na MySQL, MariaDB, PostgreSQL lub SQLite) albo `'none'`. | Przełącz na `'doctrine'` zawsze, gdy uruchamiasz wiele replik aplikacji (Kubernetes, load balancer, Docker Swarm) — zobacz [Rozdział 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Ścieżka pliku dla sterownika `json_file`. W tym samym katalogu przechowywany jest plik pomocniczy Kosza (`trash-meta.json` lub `<name>.trash-meta.json`) dla bucketów chmurowych z ograniczeniami MIME. | Przenieś na współdzielony wolumen / NFS w razie potrzeby. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | Nazwa połączenia DBAL, EntityManager, nazwa tabeli (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) oraz flaga automatycznego tworzenia tabeli przy `driver: 'doctrine'`. | Ustaw `auto_create_table: false` na produkcji, jeśli zarządzasz schematem przez Doctrine Migrations (zobacz [Rozdział 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Zapisuje ustrukturyzowane wpisy audytowe PSR-3 (`user`, `ip`, `path`, `volume`) dla wszystkich 9 operacji modyfikujących pliki i foldery (przesyłanie, usuwanie, przenoszenie, kopiowanie, zmiana nazwy, duplikowanie, tworzenie folderu, przywracanie i opróżnianie Kosza). | Włącz (`true`) w środowiskach wymagających zgodności i rozliczalności (*„kto, co i kiedy usunął?”*). |
| `audit_log.level` | `'info'` | Poziom logowania PSR-3 dla wpisów audytowych (`debug`, `info`, `notice`, `warning`). | Dopasuj do reguł routingu i filtrowania w Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | Domyślny model wizyjny/tekstowy używany, gdy zainstalowano opcjonalny pakiet `symfony/ai-bundle` (zobacz [Rozdział 09](09-symfony-ai-integration.md)). | Zmień na Gemini, Claude lub lokalny model Ollama. |
| `ai.platform_service` | `null` | Własny identyfikator usługi `PlatformInterface` Symfony AI (`null` używa domyślnej automatycznie zarejestrowanej platformy). | Gdy aplikacja definiuje wiele platform Symfony AI. |

---

## 2. `volumes` — Wolumeny pamięci (lokalne i chmurowe)

Jeden profil może montować wiele wolumenów jednocześnie (np. dysk lokalny oraz Supabase/R2/S3). Użytkownicy mogą przeciągać i upuszczać pliki bezpośrednio z folderu lokalnego do bucketa w chmurze.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Dysk lokalny'
            icon: 'disk'              # Opcjonalne (wykrywane automatycznie po sterowniku/endpoincie, gdy pominięto)
            group: 'local'            # Opcjonalne (null -> 'local' dla LocalFileSystem, 'network' dla S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # Opcjonalny TTL per wolumen (0 = wyłączony)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Wykrywane automatycznie także na podstawie endpointu
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Zewnętrzne zmiany w panelu chmurowym pojawią się w ciągu 60 s
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parametr | Domyślnie | Do czego służy | Kiedy warto zmienić |
| :--- | :--- | :--- | :--- |
| `label` | Klucz wolumenu | Nazwa wyświetlana w panelu bocznym, ścieżce nawigacji i przełączniku wolumenów. | Ustaw przyjazną nazwę (np. `'Media produktowe CDN'`). |
| `icon` | `null` *(auto)* | Ikona SVG w panelu bocznym. Gdy `null`, wykrywana automatycznie z `driver` i `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Wskaż własną ikonę `<dir>/ui/my_icon.svg` ze swojego zestawu ikon. |
| `group` | `null` *(auto)* | Grupa w panelu bocznym. Gdy `null`, `LocalFileSystem` trafia do `'local'`, a `AwsS3` / `FlysystemService` do `'network'`. | Nadpisz, jeśli np. udział NFS ma być w grupie `'network'` lub dysk chmurowy w `'local'`. |
| `driver` | `'LocalFileSystem'` | Backend przechowywania: lokalny system plików (`LocalFileSystem`), pamięć obiektowa zgodna z S3 (`AwsS3`) lub istniejąca usługa Flysystem (`FlysystemService`). | Użyj `'AwsS3'` dla S3/R2/Supabase/B2/MinIO/GCS albo `'FlysystemService'` dla SFTP/Azure/Dropbox. |
| `path` | `null` | Fizyczna ścieżka katalogu dla `LocalFileSystem` (obsługuje `{user_id}`, `{Y}`, `{m}`, `{d}`). | Ustaw niestandardowy katalog główny lub ścieżkę izolacji per użytkownik (`.../users/{user_id}`). |
| `url_prefix` | `null` | Publiczny prefiks URL używany przez przeglądarki do odczytu publicznych plików (`/uploads` lub `https://cdn.example.com`). Ignorowany przy `private: true`. | Skonfiguruj przy serwowaniu zasobów przez CDN albo publiczny bucket S3/R2/Supabase. |
| `private` | `false` | Gdy `true`, pliki nigdy nie są udostępniane pod bezpośrednim publicznym URL — każde pobranie, podgląd i miniatura przechodzi przez weryfikację Votera Symfony (`DownloadController`). | **Zawsze ustawiaj `true`** dla faktur, dokumentów tożsamości, umów i prywatnych plików klientów. |
| `cache_ttl` | `null` *(dziedziczony)* | Czas TTL pamięci podręcznej (w sekundach) wyłącznie dla tego wolumenu. `0` wyłącza cache dla tego wolumenu; `null` dziedziczy z profilu/defaults. | Utrzymaj cache `3600` s dla dysku lokalnego, ograniczając bucket chmurowy do `60` s lub `0`. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(tymczasowy token sesyjny STS)*, `version` *(domyślnie `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(automatycznie `true` dla własnych endpointów; domyślnie `false` dla standardowego AWS S3 — ustaw jawnie `true` dla „Bucket owner enforced”)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Zobacz przewodniki dla poszczególnych dostawców w [Rozdziale 07](07-cloud-storage-and-dynamic-config.md). |

---

## 3. `profiles` — Słownik parametrów profilu

### 3.1. Podstawowa pamięć, filtrowanie i limity miejsca
| Parametr | Domyślnie | Do czego służy | Kiedy warto zmienić |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Nadpisuje język interfejsu i komunikatów błędów dla tego profilu (`'pl'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'` itd.; `null` dziedziczy `defaults.locale` → język żądania Symfony → `'en'`). | Gdy różne panele w tej samej aplikacji działają w różnych językach. |
| `volumes` | `[]` | Uporządkowana lista kluczy wolumenów dostępnych w tym profilu (np. `['local_uploads', 'supabase_s3']`). Pierwszy wolumen otwiera się domyślnie. | Aby podpiąć jeden lub więcej wolumenów lokalnych/chmurowych. |
| `ignore_dot_files` | `true` | Ukrywa pliki zaczynające się od kropki (`.env`, `.htaccess`, `.DS_Store`, `.trash`) na listach i blokuje bezpośredni dostęp do nich. | Zostaw `true` ze względów bezpieczeństwa. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Ukrywa i blokuje bezpośredni dostęp do plików i folderów pasujących do wyrażeń regularnych lub wzorców wieloznacznych. | Aby ukryć wewnętrzne foldery, np. `/^cache$/i` lub `/^backups$/i`. |
| `sort_by` | `'name'` | Domyślna kolumna sortowania: `'name'`, `'date'`, `'size'` lub `'type'`. | Użyj `'date'` + `sort_direction: 'desc'`, aby pokazywać najnowsze pliki na górze. |
| `sort_direction` | `'asc'` | Kierunek sortowania: `'asc'` (A→Z / od najmniejszych) lub `'desc'` (Z→A / od najnowszych). | `'desc'` świetnie sprawdza się w galeriach mediów i bibliotekach redakcyjnych. |
| `folders_first` | `true` | Zawsze wyświetla foldery przed plikami. | Ustaw `false` dla płaskiego sortowania alfabetycznego lub chronologicznego. |
| `storage_quota` | `null` | Maksymalne łączne zużycie miejsca dla profilu (np. `'500M'`, `'2G'`). Po osiągnięciu limitu serwer odrzuca kolejne przesyłania, a w panelu bocznym pojawia się pasek wykorzystania miejsca. | Niezbędne w aplikacjach multi-tenant SaaS i przy limitach per użytkownik (`{user_id}`). |
| `upload_subfolder` | `null` | Szablon dynamicznego podfolderu, do którego automatycznie trafiają przesyłane pliki w obrębie bieżącego katalogu (np. `'{Y}/{m}'`). | Aby automatycznie porządkować pliki w katalogach według daty, np. `2026/11`. |

### 3.2. Przesyłanie, przesyłanie w częściach (`chunk_upload`) i wykrywanie duplikatów (`duplicate_detection`)
| Parametr | Domyślnie | Do czego służy | Kiedy warto zmienić |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(wszystkie bezpieczne)* | Biała lista dozwolonych typów MIME (np. `['image/jpeg', 'image/png', 'image/webp']`). Pusta oznacza dopuszczenie wszystkich bezpiecznych typów spoza `upload_deny`. | Zawsze zawężaj do konkretnych typów MIME w formularzach akceptujących tylko obrazy lub tylko PDF. |
| `upload_deny` | **47 niebezpiecznych typów MIME** | Ściśle zablokowane typy MIME (PHP, HTML, XML inny niż SVG, JS, Shell, EXE, Java, CGI itd. — weryfikowane po stronie serwera przez magic bytes). | Dodaj kolejne blokowane typy MIME, jeśli wymaga tego polityka organizacji. |
| `upload_max_size` | `'10M'` | Maksymalny **docelowy** rozmiar pojedynczego przesyłanego pliku (np. `'200M'`, `'1G'`). Przy włączonym `chunk_upload` pliki znacznie większe niż limit PHP `2M` przesyłają się bez przeszkód aż do tej granicy. | Zwiększ do `'200M'`–`'500M'` dla wideo i katalogów PDF albo obniż do `'2M'` dla awatarów. |
| `overwrite_policy` | `'rename'` | Zachowanie przy kolizji nazwy pliku: `'rename'` (`photo-1.jpg`), `'overwrite'` lub `'reject'`. | Zostaw `'rename'`, aby zapobiec przypadkowej utracie danych. |
| `chunk_upload.enabled` | `true` | Dzieli duże pliki na mniejsze części z obsługą Wstrzymaj / Wznów / potwierdzonego Anuluj oraz automatycznym ponawianiem przy chwilowych błędach sieci. | Rzadko wyłączane — wyłącznie gdy przesyłane są tylko małe pliki (< 1 MB). |
| `chunk_upload.chunk_size` | `'2M'` | Docelowy rozmiar części. Automatycznie ograniczany do **80%** limitów PHP `upload_max_filesize` i `post_max_size` (np. 1,60 MB przy limicie PHP 2M). | Zwiększ do `'5M'` na serwerach z wysokimi limitami PHP (np. 32M+) dla szybszego przesyłania dużych plików. |
| `chunk_upload.parallel_uploads` | `3` | Maksymalna liczba równolegle przesyłanych plików (`1`–`10`). | Ustaw `2` na serwerach o mniejszych zasobach lub `4`–`5` przy szybkich łączach chmurowych/CDN. |
| `chunk_upload.max_retries` | `3` | Liczba ponowień z wykładniczym opóźnieniem na każdą część w razie zerwania połączenia (`0`–`10`). | `3`–`5` sprawdza się dobrze na łączach mobilnych i niestabilnych sieciach. |
| `duplicate_detection.enabled` | `false` | Wykrywa duplikaty podczas przesyłania na podstawie skrótu zawartości SHA-256. | Włącz (`true`), aby redaktorzy nie wgrywali wielokrotnie tego samego zdjęcia produktu. |
| `duplicate_detection.policy` | `'warn'` | Akcja po wykryciu duplikatu: `'warn'` (prześlij i ostrzeż), `'reject'` (zablokuj przesłanie) lub `'reuse'` (zwróć istniejący plik bez duplikowania miejsca na dysku). | `'reuse'` przynosi realne oszczędności miejsca w sklepach e-commerce i serwisach CMS. |
| `duplicate_detection.max_scan_files` | `5000` | Górny limit plików indeksowanych przy wykrywaniu duplikatów (`100`–`50000`), chroniący przed skokami CPU/I/O na ogromnych archiwach. | Zwiększ do `10000`–`25000` w rozbudowanych bibliotekach mediów. |

### 3.3. Automatyczna normalizacja nazw plików (`naming`)
| Parametr | Domyślnie | Do czego służy | Przykład (`"Zdjęcie Produktu 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Transliteruje znaki diakrytyczne/Unicode i spacje do czystych slugów ASCII przyjaznych dla URL. | `zdjecie-produktu-2026.jpg` |
| `naming.slugger_locale` | `null` | Język transliteracji (`null` dziedziczy język profilu; np. `'pl'` mapuje `ą/ć/ę/ł/ń/ó/ś/ź/ż`, `'de'` mapuje `ä/ö/ü/ß`, `'tr'` mapuje `İ/ı/ş/ğ/ç/ö/ü`). | Ustaw na język treści (np. `'pl'`, `'de'`, `'tr'`, `'fr'`). |
| `naming.transformer` | `'none'` | Transformacja wielkości liter/stylu: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` lub `'folder'` (nazywa plik według folderu nadrzędnego!). | `'snake'` → `zdjecie_produktu_2026.jpg`, `'folder'` → `kubki-1.jpg` |
| `naming.prepend` / `append` | `''` | Dodaje przedrostek lub przyrostek — stały tekst lub tokeny dynamiczne (`{Y}`, `{m}`, `{d}`, `{uniqid}`). | `prepend: '{Y}{m}_'` → `202611_produkt.jpg` |
| `naming.apply_on_rename` | `true` | Stosuje reguły `slugger` i `transformer` również podczas zmiany nazwy pliku w interfejsie. | Zostaw `true`, aby zachować czyste nazwy po edycji. |
| `naming.apply_affixes_on_rename` | `false` | Zapobiega wielokrotnemu doklejaniu tokenów `prepend`/`append` przy każdej zmianie nazwy. | Zostaw `false`. |
| `naming.allow_extension_change` | `false` | Blokuje zmianę rozszerzenia pliku (np. `.jpg` → `.php` lub `.png`) podczas zmiany nazwy. | Zostaw `false` ze względów bezpieczeństwa. |

### 3.4. Optymalizacja obrazów (`image_optimization`) i ograniczenia (`image_constraints`)
- **`image_optimization`:** Automatycznie koryguje orientację, usuwa metadane EXIF, skaluje i kompresuje duże zdjęcia po stronie serwera podczas przesyłania.
- **`image_constraints`:** Odrzuca niespełniające wymagań obrazy **zarówno w 0 ms w przeglądarce, jak i po stronie serwera** (np. blokując rozmazane zdjęcia o niskiej rozdzielczości lub niekwadratowe zdjęcia produktów).

| Parametr | Domyślnie | Do czego służy | Kiedy warto użyć |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Włącza optymalizację obrazów podczas przesyłania (Imagick → GD → ciche pominięcie, jeśli brak sterownika). | Włącz (`true`) w każdym profilu, gdzie użytkownicy wgrywają surowe zdjęcia ze smartfonów. |
| `image_optimization.auto_orient` | `true` | Odczytuje tagi orientacji EXIF i automatycznie obraca zdjęcia zapisane bokiem. | Zostaw `true` dla zdjęć z telefonów. |
| `image_optimization.strip_exif` | `true` | Usuwa współrzędne GPS i metadane aparatu EXIF (prywatność RODO + mniejszy rozmiar pliku). | Zostaw `true` dla wszystkich obrazów publicznych. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Zmniejsza obrazy przekraczające te wymiary z zachowaniem proporcji (`>= 100`). | `1920`×`1920` to optymalny punkt dla grafik webowych. |
| `image_optimization.convert_to_webp` | `false` | Automatycznie konwertuje przesyłane pliki JPEG/PNG do formatu WebP (pliki mniejsze o 30–60%). | Włącz (`true`) w profilach zoptymalizowanych pod wydajność WWW. |
| `image_optimization.quality` | `85` | Jakość kompresji wyjściowej (`10`–`100`). | `80`–`85` daje najlepszy stosunek rozmiaru do jakości. |
| `image_constraints.min_width` / `min_height` | `null` | Minimalne wymagane wymiary w pikselach. | Zapobiega wgrywaniu nieostrych zdjęć (np. okładki blogowe co najmniej `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Maksymalne dozwolone wymiary w pikselach (sprawdzane *po* przeskalowaniu `image_optimization`, jeśli optymalizacja jest włączona). | Wymusza ścisłe wymiary banerów lub szablonów druku. |
| `image_constraints.aspect_ratio` | `null` | Wymagane proporcje: `'1:1'` (kwadrat), `'16:9'` (szeroki baner), `'4:3'`, `'3:4'`, `'1.91:1'` itd. | Kwadratowe karty produktów w e-commerce (`'1:1'`) lub nagłówki artykułów/wideo (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Dopuszczalne odchylenie od zadanych proporcji (`0.0`–`0.5`; `0.05` = 5% tolerancji, więc przejdą zarówno `1920×1080`, jak i `1280×722`). | `0.05` sprawdza się w niemal każdym scenariuszu. |

### 3.5. Miniatury, Kosz (`trash`), ZIP (`zip`) i podpisane linki udostępniania (`share`)
| Parametr | Domyślnie | Do czego służy |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Generuje buforowane miniatury obrazów: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` lub `'none'`. Obrazy z chmury są pobierane raz i zapisywane lokalnie w cache. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Zachowanie w przypadku braku GD i Imagick: `'fallback_original'` (wyświetl oryginał), `'icon'` (wyświetl ikonę pliku) lub `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Wymiary miniatury (`>= 32`), format wyjściowy (`webp`, `jpeg`, `png`) i tryb dopasowania (`contain` = widoczny cały obraz, `cover` = wypełnienie z kadrowaniem ze środka). Przy `ui.item_size: 'xl'` rozważ `480`×`360`. |
| `thumbnail.lazy_load` | `true` | Dodaje natywny atrybut `loading="lazy"` do miniatur na kartach w siatce, przyspieszając ładowanie dużych folderów. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Lokalny katalog pamięci podręcznej dla wygenerowanych miniatur. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Przenosi usuwane pliki do Kosza zamiast trwałego usuwania (soft delete). Automatycznie przełącza się na lokalny plik pomocniczy `trash-meta.json` w bucketach chmurowych z ograniczeniami MIME. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Włącza pobieranie wielu plików/folderów jednym kliknięciem jako `.zip` (wymaga rozszerzenia PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | Rozpakowywanie archiwów z 7-warstwową ochroną przed Zip-Slip i Zip-Bomb. Zawsze zablokowane, gdy `allow_extract: false`; włączenie automatycznie ustawia `permissions.extract_zip` na `true` (możesz zawęzić do `'ROLE_ADMIN'` lub `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Generuje czasowe linki udostępniania podpisane HMAC za pomocą `UriSigner` z Symfony. Ustawienie `enabled: false` natychmiast unieważnia wszystkie wcześniej wydane linki. |

### 3.6. Interfejs użytkownika (`ui`) — układ, skalowanie i flagi paska narzędzi
| Parametr | Domyślnie | Do czego służy | Kiedy warto zmienić |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Motyw przypisany do konkretnego profilu (np. `'bootstrap5'` w panelu admina, `'shadcn'` w portalu klienta). | Gdy różne moduły aplikacji korzystają z różnych motywów wizualnych. |
| `ui.color_scheme` | `null` *(defaults)* | Motyw kolorystyczny profilu (`auto`, `light`, `dark`). | Aby wymusić `'dark'` w wyłącznie ciemnej przestrzeni roboczej. |
| `ui.icon_set` | `'classic'` | Zestaw ikon plików i folderów: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` lub ścieżka do własnego katalogu SVG (`'%kernel.project_dir%/assets/fm-icons'`). | Wybierz `'windows11'` lub `'macos'` dla klasycznego desktopowego stylu albo wskaż własne ikony marki. |
| `ui.z_index` | `null` *(defaults)* | Nadpisanie `z-index` nakładek wyłącznie dla tego profilu. | Gdy komponent jest osadzony na stronie z edytorami lub panelami o wysokim `z-index`. |
| `ui.mobile_dialog_mode` | `'auto'` | Sposób wyświetlania okien dialogowych na małych ekranach (`<= 767.98px`): `'auto'` (modale i boczne panele OffCanvas przechodzą w pełny ekran, a górne/dolne panele stają się zaokrąglonymi natywnymi arkuszami), `'fullscreen'` (wszystkie na pełny ekran) lub `'sheet'` (wszystkie otwierają się jako natywny dolny arkusz). | Aby wymusić `'sheet'` lub `'fullscreen'` dla wszystkich selektorów mobilnych. |
| `ui.default_view` | `'grid'` | Początkowy widok: `'grid'` (Siatka — karty z miniaturami) lub `'list'` (Lista — sortowalna tabela). | Użyj `'list'` dla archiwów PDF i dokumentów, a `'grid'` dla galerii wizualnych. |
| `ui.item_size` | `'md'` | Szerokość kart w siatce i skala miniatur na liście: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) albo dokładna liczba pikseli (`96`–`320`). Przy `<= 132px` automatycznie przełącza na kompaktowe odstępy. | Użyj `'lg'` / `'xl'` do przeglądu materiałów wizualnych, a `'sm'` / `'xs'` w kompaktowych selektorach. |
| `ui.card_aspect_ratio` | `'4/3'` | Proporcje ramki podglądu na karcie: `'1/1'` (kwadrat), `'4/3'` (standard), `'16/9'` (panorama/wideo) lub `'3/4'` (pionowe plakaty/katalogi). | Dopasuj do głównego kształtu zasobów (`'1/1'` dla produktów, `'16/9'` dla wideo i banerów). |
| `ui.folder_style` | `'tile'` | Styl folderów w widoku Siatka: `'tile'` (Kompaktowy — poziome kafelki w stylu Dysku Google nad plikami) lub `'card'` (Karta — pełnowymiarowe karty folderów w stylu Windows/Finder). | Przełącz na `'card'`, jeśli foldery mają być bardziej wyeksponowane wizualnie. |
| `ui.show_size_slider` | `true` | Wyświetla suwak rozmiaru elementów (`96px`–`320px`) oraz przełącznik stylu folderów w menu Ustawienia. | Ustaw `false`, aby zablokować gęstość układu. |
| `ui.volume_order` | `'local_first'` | Kolejność wolumenów w panelu bocznym i przełączniku: `'local_first'`, `'network_first'` lub `'configured'` (dokładna kolejność z YAML `volumes: [...]` bez separatorów grup). | Użyj `'network_first'` lub `'configured'` w projektach opartych głównie na chmurze. |
| `ui.show_folder_tree` | `true` | Włącza lub ukrywa lewy panel drzewa folderów. | Ustaw `false` w prostych, jednofolderowych selektorach TinyMCE lub awatarów. |
| `ui.tree_lines` | `true` | Wyświetla linie łączące hierarchię w drzewie folderów. | Ustaw `false` dla bardziej minimalistycznego panelu bocznego. |
| `ui.show_folder_item_count` | `true` | Wyświetla liczniki zawartości (`12`) przy folderach. Respektuje autoryzację, więc chronione podfoldery nigdy nie wyciekają w licznikach. | Zostaw `true` dla szybkiej orientacji w zawartości folderów. |
| `ui.folder_count_mode` | `'recursive_files'` | Sposób zliczania odznak folderów: `'recursive_files'` (wszystkie pliki wraz z podfolderami), `'files'` (tylko bezpośrednie pliki) lub `'items'` (bezpośrednie pliki + podfoldery). | Wybierz `'files'`, jeśli potrzebujesz wyłącznie liczby plików w danym katalogu. |
| `ui.inspector_mode` | `'on_select'` | Prawy panel szczegółów i podglądu: `'on_select'` (otwiera się po zaznaczeniu elementu), `'always'` lub `'hidden'`. | Ustaw `'hidden'` w wąskich oknach modalnych. |
| `ui.folder_open_on` | `'dblclick'` | Gest otwierania folderu: `'dblclick'` (pojedyncze kliknięcie zaznacza, podwójne otwiera — konwencja desktopowa) lub `'click'` (pojedyncze kliknięcie od razu otwiera — konwencja webowa/mobilna). | Użyj `'click'` do szybkiego przeglądania w panelach administracyjnych. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Domyślna liczba elementów na stronę (`6`–`200`) oraz opcje w liście wyboru. | Zwiększ do `48` i `[24, 48, 96, 150]` dla rozbudowanych katalogów. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Wyświetla pasek miejsca na dysku w panelu bocznym oraz klikalne okno „Wykorzystanie miejsca i limity”. (Egzekwowanie `storage_quota` po stronie serwera pozostaje aktywne nawet po ukryciu wskaźnika.) | Ustaw `show_quota: false`, jeśli nie chcesz pokazywać limitów w interfejsie. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Steruje wbudowanym podglądem PDF w Lightboxie i odtwarzaczem wideo HTML5 (egzekwowane zarówno w UI, jak i po stronie serwera w `DownloadController`). | Ustaw `false`, aby wymusić bezpośrednie pobieranie zamiast podglądu w przeglądarce. |
| `ui.remember_last_dir` | `true` | Zapamiętuje ostatnio odwiedzony **wolumen i katalog** użytkownika w `localStorage` (automatycznie cofa się do folderu nadrzędnego/głównego, jeśli katalog został usunięty). | Ustaw `false`, jeśli każda sesja powinna zaczynać od katalogu głównego. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Wymaga potwierdzenia przed usunięciem lub przeniesieniem elementów przez drag-and-drop. | Ustaw `confirm_move: false`, aby przyspieszyć reorganizację plików. |
| `ui.double_click_to_select` | `true` | W trybie selektora Modal/OffCanvas podwójne kliknięcie pliku natychmiast go wybiera i zamyka okno. | Ustaw `false`, jeśli podwójne kliknięcie ma otwierać podgląd zamiast wybierać plik. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Włącza menu kontekstowe pod prawym przyciskiem myszy oraz pasek szybkiej nawigacji `[ ← \| → \| ↑ \| ↻ ]` po kliknięciu PPM w puste pole. | Ustaw `false` na maksymalnie uproszczonych ekranach dla użytkowników końcowych. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Włącza przenoszenie metodą przeciągnij i upuść między folderami oraz upuszczanie plików/folderów z systemu operacyjnego w celu przesłania. | Ustaw `false` w bibliotekach zasobów tylko do odczytu. |
| `ui.keyboard_shortcuts` | `true` | Włącza skróty klawiszowe (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Delete`, `Spacja`, `/`, `R`, `F`, `?` itd.). | Wyłącz (`false`), jeśli strona nadrzędna definiuje kolidujące globalne skróty. |
| Flagi `ui.show_*` | *(w większości `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Włączaj i ukrywaj poszczególne kontrolki paska narzędzi, aby odchudzić interfejs. *(Uwaga: wpisy „O wtyczce” i „GitHub” w Ustawieniach są wbudowanymi elementami atrybucji i nie podlegają wyłączeniu z poziomu YAML.)* |

### 3.7. Pamięć podręczna profilu (`cache`), bezpieczeństwo (`security`), uprawnienia (`permissions`), AI i Usage Guard

#### Pamięć podręczna (`cache`)
| Parametr | Domyślnie | Opis |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Włącza lub wyłącza cache dla tego profilu. Jeśli profil ustawi `cache.enabled: false`, parametr `volume.cache_ttl` nie wymusi ponownego włączenia cache. |
| `cache.ttl` | `null` *(defaults)* | Kolejność pierwszeństwa TTL: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Bezpieczeństwo (`security`)
| Parametr | Domyślnie | Opis |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Włącza kontrolę uprawnień RBAC przez `FileManagerVoter` oraz własne Votery. Na produkcji zawsze zostawiaj `true`. |
| `security.csrf_protection` | `true` | Wymaga poprawnego nagłówka `X-LiveUX-FM-CSRF` dla każdego żądania `POST`. |
| `security.sanitize_svg` | `true` | Sanityzuje przesyłane i edytowane pliki SVG według ścisłej białej listy 55 tagów (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Włącza zakładkę **„Z adresu URL”** w oknie przesyłania (z ochroną przed SSRF i DNS rebindingiem w `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Nadpisuje logowanie audytowe PSR-3 dla tego profilu (`true` / `false` / `null`). |

#### 17 szczegółowych uprawnień (`permissions`)
> [!WARNING]
> **Ważne: co oznacza `true` w sekcji `permissions`?**  
> Ustawienie uprawnienia na **`true`** NIE oznacza *„dostępu publicznego / anonimowego”*! `FileManagerVoter` przyznaje uprawnienie `true` **wyłącznie zalogowanym użytkownikom (`$token->getUser() !== null`)** i odrzuca żądania anonimowe.  
> Ponadto **`permissions.view`** jest uprawnieniem nadrzędnym: jeśli użytkownik nie ma `view`, każda inna akcja (`upload`, `delete`, `download` itd.) jest automatycznie blokowana.

Każde uprawnienie przyjmuje jeden z 4 formatów:
- `true` → Każdy zalogowany użytkownik
- `false` → Nikt (akcja całkowicie wyłączona)
- `'ROLE_ADMIN'` → Wyłącznie użytkownicy z podaną rolą
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Użytkownicy posiadający którąkolwiek z wymienionych ról

| Klucz uprawnienia | Domyślnie | Kontrolowane akcje |
| :--- | :--- | :--- |
| `view` | `true` | Listowanie katalogów, drzewo folderów, podglądy oraz **warunek wstępny dla każdego innego uprawnienia**. |
| `upload` | `true` | Standardowe/częściowe przesyłanie plików, zapisywanie z edytora obrazów oraz import ze zdalnych URL. |
| `create_folder` | `true` | Tworzenie nowych folderów. |
| `rename` | `true` | Zmiana nazwy plików i folderów. |
| `clone` | `true` | Duplikowanie pliku lub folderu. |
| `move` | `true` | Przenoszenie elementów przez drag-and-drop lub Wytnij/Wklej. |
| `copy` | `true` | Kopiowanie elementów przez Kopiuj/Wklej lub okno przenoszenia/kopiowania. |
| `delete` | `true` | Usuwanie plików i folderów (przenoszenie do Kosza lub trwałe usunięcie). |
| `trash_restore` | `true` | Przywracanie elementów z Kosza. |
| `trash_purge` | `true` | Trwałe usuwanie elementów z Kosza i opróżnianie Kosza. |
| `download` | `true` | Pobieranie pojedynczych plików, strumieniowanie PDF/wideo oraz kopiowanie linków do plików. |
| `download_zip` | `true` | Pobieranie wielu zaznaczonych elementów jako archiwum `.zip`. |
| `extract_zip` | `null` *(auto)* | Wymuszone na `false`, dopóki `zip.allow_extract: false`; automatycznie przechodzi na `true` po włączeniu `zip.allow_extract: true` (w razie potrzeby zawęź do `'ROLE_ADMIN'`). |
| `share` | `true` | Generowanie czasowych linków udostępniania podpisanych HMAC. |
| `clear_cache` | `true` | Czyszczenie pamięci podręcznej w Ustawieniach oraz wymuszanie `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Edycja `alt`/`title`/`caption`/`tags`/punktu ogniskowego pliku oraz koloru/ikony/przypięcia folderu. |
| `ai_enrich` | `true` | Wywoływanie funkcji **✨ Zasugeruj z AI** (wymaga `view` + `edit_metadata` + `ai_enrich`). |

#### Wzbogacanie AI (`ai`) i ochrona użycia zasobów (`usage_guard`)
| Parametr | Domyślnie | Opis |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Włącza sugestie metadanych AI; ustawienie `locale` określa język wyjściowy (np. `'pl'`, `'en'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Maksymalna liczba sugerowanych tagów (`1`–`20`) oraz limit przeskalowania obrazu przed wysłaniem do AI (`256`–`2048` px, oszczędność tokenów wizyjnych). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Blokuje wysyłanie plików z wolumenów `private: true` do zewnętrznych API AI; opcjonalne automatyczne wzbogacanie po przesłaniu (zobacz [Rozdział 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Ostrzega lub blokuje operacje **`delete`, `rename`, `move` i `overwrite`**, gdy zasób ma aktywne odwołania w bazie danych (zobacz [Rozdział 10](10-asset-usage-guard.md)). |

---

## 4. 5 gotowych przepisów produkcyjnych (skopiuj i wklej)

Oto 5 najczęstszych konfiguracji ze świata produkcyjnego gotowych do bezpośredniego wdrożenia:

### Przepis 1: Katalog produktów e-commerce (kwadratowe karty + WebP + ponowne użycie duplikatów)
> **Cel:** Akceptuj wyłącznie wyraźne kwadratowe zdjęcia produktów `1:1`; automatycznie skaluj i konwertuj zdjęcia ze smartfonów do WebP; ponownie wykorzystuj istniejące pliki przy duplikatach; blokuj przypadkowe usunięcie grafik przypisanych do aktywnych produktów.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'pl'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # Duże karty produktów 204px
                card_aspect_ratio: '1/1' # Kwadratowe proporcje podglądu
                folder_style: 'card'     # Pełnowymiarowe karty kategorii
                show_folder_item_count: true
```

### Przepis 2: Okładki bloga / aktualności (banery `16:9` + tekst alternatywny AI)
> **Cel:** Przyjmuj wyłącznie panoramiczne okładki `16:9` w wysokiej rozdzielczości (co najmniej `1200×630` px), automatycznie układaj przesyłane pliki w folderach `2026/11` i generuj teksty alternatywne SEO oraz tagi przy pomocy AI.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Najnowsze okładki na górze
            ai:
                enabled: true
                locale: 'pl'
                max_tags: 6
            ui:
                item_size: 'xl'           # Szerokie podglądy okładek 260px
                card_aspect_ratio: '16/9' # Panoramiczne proporcje banerów
```

### Przepis 3: Skarbiec dokumentów i PDF klientów (prywatny Supabase/S3 + izolacja per użytkownik)
> **Cel:** Odizoluj każdego klienta w jego własnym folderze faktur PDF na prywatnym buckecie zgodnym z S3, zablokuj bezpośrednie zewnętrzne adresy URL, odświeżaj listy z chmury co 60 sekund i loguj każdą operację do audytu.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Dokumenty PDF klientów'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Brak bezpośredniego dostępu przez publiczny URL
            cache_ttl: 60          # Zewnętrzne zmiany w chmurze widoczne w ciągu 60 s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Każdy użytkownik widzi wyłącznie własny folder
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Sortowalna tabela idealna dla plików PDF
                item_size: 'sm'          # Kompaktowe wiersze
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Przepis 4: Szybki selektor dla edytora WYSIWYG (TinyMCE / CKEditor / Tiptap)
> **Cel:** Lekki, minimalistyczny selektor modalny, w którym dwukrotne kliknięcie obrazu natychmiast wstawia go do edytora.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Przepis 5: Biblioteka dla partnerów / Press Kit tylko do odczytu
> **Cel:** Pozwól partnerom przeglądać logotypy i katalogi, pobierać pojedyncze pliki lub paczki ZIP oraz generować podpisane linki udostępniania — przy całkowitym zablokowaniu przesyłania, edycji i usuwania.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 dni
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Dynamiczne symbole zastępcze ścieżek (`DynamicPathResolver`)

Dynamicznych tokenów można używać w lokalnym `path`, `url_prefix`, chmurowym `driver_options.prefix` oraz w opcji profilu `upload_subfolder`:

| Symbol | Opis | Przykładowy wynik |
| :--- | :--- | :--- |
| `{user_id}` | Bezkolizyjny, bezpieczny dla systemu plików identyfikator zalogowanego użytkownika Symfony (`guest` dla niezalogowanego). | `42` lub `alice_a--h-9f1c2e4b...` |
| `{Y}` | 4-cyfrowy rok | `2026` |
| `{m}` | 2-cyfrowy miesiąc | `11` |
| `{d}` | 2-cyfrowy dzień | `25` |

```yaml
volumes:
    user_space:
        label: 'Moja przestrzeń prywatna'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Bezkolizyjna izolacja multi-tenant `{user_id}`:**  
> `DynamicPathResolver` generuje **rozłączne klucze katalogów z sufiksem SHA-256 `<slug>--h-<16-hex>`** dla adresów e-mail (`alice.a@example.com` vs `alice_a@example.com`), wariantów wielkości liter (`Alice` vs `alice`), nazw użytkowników Unicode (polskich, arabskich, chińskich, tureckich), słów zastrzeżonych (`guest`, `user`, identyfikatorów zakończonych na `--h-`) oraz nazw urządzeń Windows (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Dwóch różnych użytkowników nigdy nie trafi do tego samego katalogu.
>
> **Zachowanie w CLI:** Polecenia konsolowe (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup` itd.) automatycznie pomijają wolumeny zawierające `{user_id}`, aby nie tworzyć przypadkowego katalogu `guest/`.

---

## 6. Kompletny schemat referencyjny YAML (każda aktywna opcja)

Poniższy plik YAML dokumentuje **każdy aktywny klucz konfiguracyjny** zdefiniowany w `Configuration.php` wraz z wartością domyślną i komentarzem. We własnym projekcie deklaruj wyłącznie te klucze, które chcesz dostosować — pozostałe odziedziczą wartości domyślne automatycznie.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) GLOBALNE USTAWIENIA DOMYŚLNE — Dziedziczone przez wszystkie profile
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Domyślny katalog główny dla uproszczonych profili jednodyskowych
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <własny>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = język żądania/tłumacza Symfony (12 wbudowanych języków, fallback 'en') | 'pl' | 'en' | 'de' | ...
        z_index: 1050                                      # Bazowy z-index dla nakładek, popoverów i okien modalnych (>= 1)
        auto_inject_dialog_host: true                      # Automatycznie wstrzykuj DialogHost dla Modal/OffCanvas w pełnych odpowiedziach HTML
        dialog_host_profile: 'default'                     # Domyślny profil dla automatycznie wstrzykniętego DialogHost

        audit_log:
            enabled: false                                 # Loguj wszystkie 9 operacji modyfikujących pliki/foldery do kanału PSR-3
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Domyślny model AI, gdy nie nadpisano go w profilu
            platform_service: null                         # Własny identyfikator usługi PlatformInterface Symfony AI (null = auto)

        cache:
            enabled: true                                  # Globalny przełącznik pamięci podręcznej PSR-6 katalogów i drzewa
            pool: 'cache.app'                              # Identyfikator puli cache PSR-6 Symfony
            ttl: 3600                                      # Globalny czas TTL cache w sekundach (0 = wyłączony)

        metadata:
            enabled: true                                  # Kolory/ikony/przypięcia folderów oraz alt/title/caption/tags/focalPoint plików
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Plik danych dla sterownika json_file
            connection: null                               # Opcjonalna nazwa połączenia DBAL dla sterownika doctrine (null = połączenie entity_manager)
            entity_manager: 'default'                      # Nazwa EntityManagera / połączenia dla sterownika doctrine
            table: 'liveux_fm_metadata'                    # Nazwa tabeli DBAL dla sterownika doctrine (zobacz Rozdział 13)
            auto_create_table: true                        # Automatycznie utwórz tabelę metadanych, jeśli nie istnieje (ustaw false przy migracjach)

    # =========================================================================
    # 2) WOLUMENY PAMIĘCI — Definicje dysków lokalnych i chmurowych
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Pamięć lokalna'                        # Nazwa wyświetlana w panelu bocznym i ścieżce nawigacji
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Fizyczna ścieżka (obsługuje {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Publiczny prefiks URL w przeglądarce (zostaw null dla private: true)
            private: false                                 # true = serwuj wszystkie pliki przez chroniony RBAC DownloadController
            cache_ttl: null                                # TTL pamięci podręcznej w sekundach dla wolumenu (null = dziedzicz, 0 = wyłączony)
            driver_options: []                             # Puste dla LocalFileSystem

        cloud_s3_example:
            label: 'Chmura S3 / R2 / Supabase'
            icon: 'supabase'                               # Odpowiada resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # Publiczny URL bucketa/CDN (null dla private: true)
            private: false
            cache_ttl: 60                                  # Odświeżaj zewnętrzne zmiany w chmurze co 60 sekund
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Nazwa bucketa
                region: '%env(AWS_S3_REGION)%'             # Region ('auto' dla Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # Własny endpoint S3 dla dostawców innych niż AWS
                prefix: 'media/{Y}'                        # Opcjonalny prefiks główny w buckecie (obsługuje tokeny dynamiczne)
                use_path_style_endpoint: true              # true dla Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Opcjonalny tymczasowy token sesyjny AWS STS
                version: 'latest'                          # Wersja API AWS S3 (domyślnie: 'latest')
                ca_bundle: null                            # Opcjonalna ścieżka do pliku .pem CA (naprawia cURL error 60 na Windows)
                disable_acl: true                          # Automatycznie true dla własnych endpointów; ustaw true dla AWS S3 Bucket owner enforced
                retain_visibility: false                   # Automatycznie false dla własnych endpointów (unika wysyłania nagłówka x-amz-acl)
                request_checksum_calculation: 'when_required'  # Zgodność z AWS SDK >= 3.337 (auto dla własnych endpointów)
                response_checksum_validation: 'when_required'  # Zgodność z AWS SDK >= 3.337 (auto dla własnych endpointów)

        custom_flysystem_example:
            label: 'Archiwum SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # Identyfikator usługi League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) PROFILE — Niezależne zestawy reguł dla różnych scenariuszy użycia
    # =========================================================================
    profiles:
        full_reference:
            # --- Podpięte wolumeny, język i skrócone opcje jednodyskowe ---
            locale: null                                   # null = dziedzicz defaults.locale | 'pl' | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Gdy puste, tworzony jest niejawny wolumen z opcji driver/path poniżej
            driver: 'LocalFileSystem'                      # Skrócony sterownik używany tylko gdy volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Skrócona ścieżka używana tylko gdy volumes: []
            url_prefix: '/uploads'                         # Skrócony prefiks URL używany tylko gdy volumes: []
            private: false                                 # Skrócona flaga prywatności używana tylko gdy volumes: []
            driver_options: []                             # Skrócone opcje sterownika używane tylko gdy volumes: []

            # --- Listowanie, ukrywanie i domyślne sortowanie ---
            ignore_dot_files: true                         # Ukrywaj i blokuj pliki z kropką (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Wzorce regex/wildcard ukryte na listach i zablokowane dla bezpośredniego dostępu
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Zawsze wyświetlaj foldery przed plikami

            # --- Przesyłanie, limit miejsca i kolizje nazw ---
            upload_subfolder: null                         # np. '{Y}/{m}' — umieszczaj przesyłane pliki w dynamicznych podfolderach
            upload_allow: []                               # Puste = wszystkie bezpieczne typy; niepuste = ścisła biała lista MIME
            upload_deny:                                   # Zawsze blokowane niebezpieczne typy MIME (domyślnie 47 po pominięciu)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Maksymalny docelowy rozmiar pojedynczego pliku (np. '50M', '2G')
            storage_quota: null                            # Całkowity limit miejsca profilu (np. '500M', '5G'; null = bez limitu)
            overwrite_policy: 'rename'                     # rename (dodaje -1) | overwrite | reject

            # --- Przesyłanie w częściach (omija limit PHP upload_max_filesize) ---
            chunk_upload:
                enabled: true                              # Dziel duże pliki na wznawiane części
                chunk_size: '2M'                           # Docelowy rozmiar części (automatycznie ograniczany do 80% limitów PHP)
                parallel_uploads: 3                        # Maks. liczba równoległych przesyłań (1..10)
                max_retries: 3                             # Automatyczne ponowienia części przy błędach sieci (0..10)

            # --- Wykrywanie duplikatów (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Wykrywaj zduplikowaną zawartość podczas przesyłania
                policy: 'warn'                             # warn | reject | reuse (zwróć istniejący plik)
                max_scan_files: 5000                       # Maks. liczba plików hashowanych w indeksie duplikatów (100..50000)

            # --- Automatyczna normalizacja nazw plików ---
            naming:
                slugger: true                              # Transliteruj nazwy Unicode do czystych slugów ASCII
                slugger_locale: null                       # null = dziedzicz język profilu (np. 'pl', 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Stały/dynamiczny przedrostek (np. 'produkt-')
                append: ''                                 # Stały/dynamiczny przyrostek przed rozszerzeniem (np. '-{Y}{m}{d}')
                apply_on_rename: true                      # Stosuj reguły sluggera/transformera przy zmianie nazwy
                apply_affixes_on_rename: false             # Ponownie doklejaj prepend/append przy zmianie nazwy
                allow_extension_change: false              # Zezwalaj na zmianę rozszerzenia (.jpg -> .php) przy zmianie nazwy

            # --- Optymalizacja obrazów podczas przesyłania ---
            image_optimization:
                enabled: false                             # Auto-orientacja, usuwanie EXIF, skalowanie i kompresja przy przesyłaniu
                auto_orient: true                          # Naprawiaj orientację EXIF zdjęć ze smartfonów
                strip_exif: true                           # Usuwaj dane GPS i EXIF aparatu dla prywatności i mniejszego rozmiaru
                max_width: 2400                            # Maks. szerokość proporcjonalna w px (>= 100)
                max_height: 2400                           # Maks. wysokość proporcjonalna w px (>= 100)
                convert_to_webp: false                     # Automatycznie konwertuj przesyłane JPEG/PNG do WebP
                quality: 85                                # Jakość wyjściowa (10..100)

            # --- Ograniczenia rozdzielczości i proporcji obrazu ---
            image_constraints:
                min_width: null                            # Minimalna szerokość w px (np. 800)
                min_height: null                           # Minimalna wysokość w px (np. 600)
                max_width: null                            # Maksymalna szerokość w px (sprawdzana po image_optimization)
                max_height: null                           # Maksymalna wysokość w px
                aspect_ratio: null                         # Wymagane proporcje (np. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Dopuszczalne odchylenie proporcji (0.0..0.5; 0.05 = 5%)

            # --- Silnik miniatur ---
            thumbnail:
                enabled: true                              # Generuj buforowane podglądy kart dla obrazów
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Szerokość miniatury w px (>= 32)
                height: 240                                # Wysokość miniatury w px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Jakość kompresji (10..100)
                fit: 'contain'                             # contain (dopasuj wewnątrz) | cover (przytnij, aby wypełnić)
                lazy_load: true                            # Dodaj loading="lazy" do miniatur na kartach
                cache_dir: '%kernel.project_dir%/public/media-cache' # Lokalny katalog cache dla wygenerowanych miniatur

            # --- Kosz (miękkie usuwanie / soft delete) ---
            trash:
                enabled: true                              # Przenoś usuwane elementy do Kosza zamiast usuwać trwale
                directory: '.trash'                        # Nazwa ukrytego katalogu Kosza
                retention_days: 30                         # Czas przechowywania w dniach dla polecenia file-manager:trash:purge
                max_size: '500M'                           # Maksymalny łączny rozmiar Kosza

            # --- Pobieranie ZIP i bezpieczne rozpakowywanie archiwów ---
            zip:
                enabled: true                              # Włącz pobieranie wielu plików/folderów jako ZIP
                max_files: 100                             # Maks. liczba plików w jednym archiwum ZIP
                max_total_size: '250M'                     # Maks. łączny rozmiar jednego archiwum ZIP
                allow_extract: false                       # Włącz rozpakowywanie ZIP (domyślnie wyłączone; 7 warstw ochrony)
                max_extract_files: 200                     # Maks. liczba plików rozpakowywanych z archiwum (1..2000)
                max_extract_size: '100M'                   # Maks. łączny rozmiar po rozpakowaniu (ochrona przed bombą ZIP)
                max_compression_ratio: 100                 # Maks. dozwolony stopień kompresji (1..1000 — ochrona przed bombą ZIP)

            # --- Czasowe podpisane linki udostępniania (HMAC) ---
            share:
                enabled: true                              # Zezwalaj na generowanie podpisanych linków udostępniania
                default_ttl: 86400                         # Domyślny czas ważności w sekundach (86400 = 24 godziny)
                max_ttl: 2592000                           # Maksymalny wybieralny czas ważności w sekundach (2592000 = 30 dni)
                allow_inline_preview: true                 # Zezwalaj na podgląd w przeglądarce dla bezpiecznych typów MIME

            # --- Opcjonalne wzbogacanie Symfony AI ---
            ai:
                enabled: false                             # Pokazuj przycisk „✨ Zasugeruj z AI” w oknie metadanych
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = język profilu/żądania (np. 'pl' => polskie teksty alt i tagi)
                max_tags: 6                                # Maksymalna liczba sugerowanych tagów (1..20)
                max_image_dimension: 1024                  # Limit przeskalowania przed wysłaniem do AI (256..2048 px)
                block_private_volumes: true                # true = blokuj wywołania AI dla wolumenów private: true (domyślnie: true)
                auto_enrich_on_upload: false               # Automatycznie uruchamiaj wzbogacanie AI po przesłaniu

            # --- Ochrona użycia zasobów (Delete / Rename / Move / Overwrite) ---
            usage_guard:
                enabled: false                             # Sprawdzaj odwołania w bazie danych przed modyfikacją zasobów
                policy: 'warn'                             # warn (pokaż odwołania + pozwól wymusić) | block (zablokuj operację)
                enforce_server_side: true                  # Egzekwuj również dla bezpośrednich żądań API
                max_references: 20                         # Maks. liczba odwołań wyświetlanych w oknie modalnym (1..200)

            # --- Pamięć podręczna profilu ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | sekundy (0 = wyłączony)

            # --- Opcje bezpieczeństwa ---
            security:
                voter_enabled: true                        # Włącz weryfikację RBAC przez FileManagerVoter
                csrf_protection: true                      # Wymagaj nagłówka X-LiveUX-FM-CSRF w żądaniach POST
                sanitize_svg: true                         # Sanityzuj przesyłane i edytowane pliki SVG
                allow_remote_url_import: false             # Włącz zakładkę „Z adresu URL” (z ochroną SSRF)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 szczegółowych uprawnień RBAC (true = zalogowany użytkownik | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Uprawnienie nadrzędne wymagane do przeglądania i wszystkich pozostałych akcji
                upload: true                               # Przesyłanie plików, zapis z edytora obrazów i import z URL
                create_folder: true                        # Tworzenie nowych folderów
                rename: true                               # Zmiana nazwy plików i folderów
                clone: true                                # Duplikowanie plików i folderów
                move: true                                 # Przenoszenie elementów (w tym drag-and-drop oraz wytnij/wklej)
                copy: true                                 # Kopiowanie elementów (w tym kopiuj/wklej)
                delete: true                               # Usuwanie elementów (Kosz lub trwałe usunięcie)
                trash_restore: true                        # Przywracanie elementów z Kosza
                trash_purge: true                          # Trwałe usuwanie elementów / opróżnianie Kosza
                download: true                             # Pobieranie pojedynczego pliku i kopiowanie linku
                download_zip: true                         # Pobieranie wielu plików jako ZIP
                extract_zip: false                         # Rozpakowywanie archiwów ZIP (wymaga także zip.allow_extract: true)
                share: true                                # Tworzenie podpisanych linków udostępniania
                clear_cache: true                          # Czyszczenie cache w Ustawieniach oraz wymuszanie ?refresh=1
                edit_metadata: true                        # Edycja alt/tagów/punktu ogniskowego pliku oraz koloru/ikony/przypięcia folderu
                ai_enrich: true                            # Uruchamianie wzbogacania metadanych AI

            # --- Flagi i skalowanie interfejsu użytkownika (UI) ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <własny katalog>
                z_index: null                              # null = defaults.z_index | liczba całkowita (>= 1)
                mobile_dialog_mode: 'auto'                 # Zachowanie okien dialogowych na mobile (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px kompaktowy)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (kompaktowy wiersz) | card (pełnowymiarowa karta folderu)
                show_size_slider: true                     # Pokazuj suwak rozmiaru elementów i przełącznik stylu folderów w Ustawieniach
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Pokazuj lewy panel drzewa folderów
                tree_lines: true                           # Pokazuj linie łączące hierarchię w drzewie folderów
                show_folder_item_count: true               # Pokazuj odznaki liczby elementów przy folderach
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Pokazuj pasek limitu w panelu bocznym i wskaźnik w nagłówku
                show_quota_details: true                   # Włącz klikalne okno szczegółowego wykorzystania miejsca
                per_page: 24                               # Domyślna liczba elementów na stronę (6..200)
                per_page_options: [24, 48, 96]             # Opcje listy wyboru rozmiaru strony
                show_perf_badge: false                     # Pokazuj znacznik wydajności CACHE HIT / DYSK na pasku narzędzi
                show_nav_buttons: true                     # Główny przełącznik grupy Wstecz / Dalej / Folder nadrzędny / Odśwież
                show_history_buttons: true                 # Pokazuj przyciski historii Wstecz / Dalej
                show_up_button: true                       # Pokazuj przycisk Folder nadrzędny
                show_refresh_button: true                  # Pokazuj przycisk Odśwież (?refresh=1)
                show_breadcrumbs: true                     # Pokazuj klikalny/upuszczalny pasek ścieżki nawigacji
                show_search: true                          # Pokazuj pole wyszukiwania i przełącznik głębokiego wyszukiwania
                show_type_filter: true                     # Pokazuj filtr typu (Obrazy / Wideo / Pliki / Foldery)
                show_view_toggle: true                     # Pokazuj przełącznik Siatka / Lista
                show_pagination: true                      # Pokazuj dolny pasek paginacji
                show_per_page_selector: true               # Pokazuj listę wyboru liczby elementów na stronę
                show_sort: true                            # Pokazuj menu sortowania i przełącznik kierunku
                show_fullscreen_button: true               # Pokazuj przycisk trybu pełnoekranowego
                show_settings_button: true                 # Pokazuj menu Ustawienia (⚙)
                show_footer: true                          # Pokazuj dolny pasek stanu
                show_context_nav: true                     # Pokazuj pasek szybkiej nawigacji w menu kontekstowym pustego pola
                allow_dropzone_upload: true                # Zezwalaj na przeciąganie plików/folderów z systemu na listę plików
                remember_last_dir: true                    # Zapamiętuj ostatni wolumen i folder (z automatycznym powrotem po usunięciu)
                confirm_delete: true                       # Wyświetlaj okno potwierdzenia przed usunięciem
                confirm_move: true                         # Wyświetlaj okno potwierdzenia przed przenoszeniem/kopiowaniem
                double_click_to_select: true               # Podwójne kliknięcie wybiera plik i zamyka selektor Modal/OffCanvas
                allow_context_menu: true                   # Włącz menu kontekstowe pod prawym przyciskiem myszy
                allow_pdf_preview: true                    # Podgląd PDF w Lightboxie (false wymusza pobieranie)
                allow_video_preview: true                  # Wbudowany odtwarzacz wideo (false wymusza pobieranie)
                keyboard_shortcuts: true                   # Włącz lokalne skróty klawiszowe
                allow_drag_move: true                      # Zezwalaj na przeciąganie elementów na foldery w celu przeniesienia
```

---

[← Poprzedni: 01 — Wymagania i instalacja](01-requirements-and-installation.md) | [Indeks dokumentacji](README.md) | [Dalej: 03 — Tryby użycia i JS API →](03-usage-modes-and-js-api.md)
