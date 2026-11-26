# Przewodnik integracji z Supabase Storage (S3)

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać Supabase Storage?**  
> Jeśli korzystasz już z projektu Supabase — lub szukasz hojnego planu darmowego, aby z jednego panelu zarządzać zarówno publicznymi zasobami CDN, jak i prywatnymi bucketami na dokumenty — Supabase Storage to najbardziej praktyczny wybór kompatybilny z S3.

Ponieważ **Supabase Storage** obsługuje protokół kompatybilny z S3, działa od razu z LiveUX FileManagerBundle w hybrydowej konfiguracji **Magazyn lokalny + Supabase Cloud** bez konieczności pisania ani jednej linijki własnego kodu. Bundle automatycznie rozpoznaje dostawcę `supabase` na podstawie adresu URL endpointu i wyświetla ikonę SVG Supabase na pasku bocznym.

---

## Krok 1: Zainstaluj wymagane pakiety

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Krok 2: Wygeneruj klucze dostępu S3 w panelu Supabase

> [!WARNING]
> **Ważne:** Klucze API REST/Auth z Supabase — `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) oraz `SUPABASE_SECRET_KEY` (`sb_secret_...`) — **nie** działają z protokołem S3. Musisz użyć 32-znakowego i 64-znakowego klucza wygenerowanego poniżej w sekcji **S3 Access Keys**.

1. Otwórz swój projekt w panelu Supabase (Supabase Dashboard) → **Storage** → **New bucket** i utwórz bucket (np. `media` lub `prints`).
   - Zaznacz opcję **Public bucket** dla publicznie dostępnych obrazów i zasobów CDN.
   - Pozostaw **Public bucket** odznaczone (bucket prywatny) w przypadku dokumentów klientów lub faktur.
2. Przejdź do **Project Settings** → **Storage** (lub **Storage** → **Settings**).
3. Skopiuj poniższe dwie wartości z karty **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** np. `eu-central-1` lub `eu-west-1`
4. W sekcji **S3 Access Keys** kliknij **New access key**, dodaj opis i skopiuj wygenerowane wartości:
   - **Access Key ID** (32 znaki)
   - **Secret Access Key** (64 znaki — wyświetlany jest tylko raz!)

---

## Krok 3: Dodaj zmienne środowiskowe do `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## Krok 4: Skonfiguruj `liveux_file_manager.yaml`

### Scenariusz A — Bucket publiczny

Obrazy, wideo i pliki publiczne są serwowane bezpośrednio przez URL CDN Supabase (co odciąża serwer PHP), natomiast miniatury są generowane jednorazowo na Twoim serwerze i serwowane z lokalnej pamięci podręcznej (`public/media-cache`):

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Magazyn lokalny'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Opcjonalnie — wykrywane automatycznie na podstawie endpointu
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # 60 s cache dla zasobów chmurowych mogących zmieniać się z zewnątrz
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # lub 'network_first' / 'configured'
```

### Scenariusz B — Bucket prywatny z izolacją per użytkownik

Dla faktur klientów lub poufnych plików PDF dostępnych wyłącznie dla uwierzytelnionych i uprawnionych użytkowników:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Skarbiec dokumentów Supabase'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Brak publicznego URL, ponieważ bucket jest prywatny
            private: true             # Bezpieczne strumieniowanie przez DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Izoluje każdego użytkownika we własnym podkatalogu bez kolizji
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## Krok 5: Zweryfikuj połączenie na żywo jednym poleceniem

```bash
php bin/console file-manager:doctor --check-remote
```

Przykładowy wynik w przypadku powodzenia:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Uwaga:** Dynamiczne wolumeny przypisane do użytkownika, wykorzystujące placeholdery takie jak `prefix: 'users/{user_id}'` (jak w Scenariuszu B), są bezpiecznie pomijane ze statusem `INFO` przez polecenie `file-manager:doctor --check-remote`, ponieważ w środowisku CLI nie istnieje aktywna sesja użytkownika.

---

## Wskazówki i rozwiązywanie problemów specyficznych dla Supabase

### 1. Usuwanie plików w buckecie z ograniczeniem typów MIME (`InvalidMimeType: application/json`)
Jeśli w ustawieniach **Allowed MIME types** swojego bucketa Supabase zezwolisz wyłącznie na wybrane typy, takie jak `application/pdf` lub `image/*`, Supabase zwróci błąd `InvalidMimeType`, gdy funkcja kosza spróbuje zapisać plik `.trash/<id>/.meta.json`.
- **Automatyczny fallback:** `TrashManager` wykrywa tę sytuację automatycznie — sam plik zostaje przeniesiony do `.trash/<id>/...` w buckecie chmurowym, natomiast metadane `.meta.json` są zapisywane w lokalnym pliku zapasowym na Twoim serwerze (`trash-meta.json` lub `<nazwa>.trash-meta.json` w katalogu `defaults.metadata.file`). Przywracanie oraz trwałe usuwanie plików działają dalej bez zakłóceń.

### 2. Błędy sum kontrolnych i ACL w AWS SDK v3.337+
Dla wolumenów S3 skonfigurowanych z niestandardowym `endpoint` (takich jak Supabase), `FlysystemStorageFactory` automatycznie stosuje:
- `request_checksum_calculation: 'when_required'` oraz `response_checksum_validation: 'when_required'`
- `retain_visibility: false` oraz usuwanie nagłówków `x-amz-acl` (`disable_acl: true`)

Nie jest wymagana żadna dodatkowa konfiguracja.

### 3. Błąd certyfikatu SSL `cURL error 60` w systemie Windows / WAMP
Jeśli na lokalnej maszynie deweloperskiej napotkasz błąd `cURL error 60: unable to get local issuer certificate`:
- Pobierz plik [cacert.pem](https://curl.se/ca/cacert.pem) i wskaż go w `php.ini` (zarówno w pliku `php.ini` dla Apache, jak i CLI):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Alternatywnie skonfiguruj go tylko dla konkretnego wolumenu za pomocą opcji `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. Pliki usunięte w panelu Supabase nadal pojawiają się na liście
- Kliknij przycisk **Odśwież (`↻`)** na pasku narzędzi (lub naciśnij `R`), aby natychmiast pominąć pamięć podręczną za pomocą `?refresh=1`.
- Jeśli pliki w buckecie są często modyfikowane z zewnątrz, ustaw dla wolumenu `cache_ttl: 30` (lub `cache_ttl: 0`, aby całkowicie wyłączyć cache).

---

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Następny: Przewodnik AWS S3 →](aws-s3.md)
