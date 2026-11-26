# Przewodnik integracji z Cloudflare R2

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać Cloudflare R2?**  
> Jeśli obsługujesz duży ruch pobierania obrazów lub wideo i zależy Ci na **zerowych opłatach za transfer wychodzący (egress)** w połączeniu z wbudowaną szybkością Cloudflare CDN, Cloudflare R2 jest jednym z najbardziej opłacalnych rozwiązań kompatybilnych z S3 dla projektów bogatych w multimedia.

LiveUX FileManagerBundle automatycznie wykrywa Cloudflare R2 na podstawie endpointu `.r2.cloudflarestorage.com` i wyświetla ikonę SVG `cloudflare_r2` na pasku bocznym.

---

## Krok 1: Zainstaluj wymagane pakiety

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Krok 2: Utwórz bucket i token API w panelu Cloudflare

1. Przejdź do Cloudflare Dashboard → **R2 Object Storage** → **Create bucket** i utwórz bucket (np. `cdn-assets`).
2. Dla dostępu publicznego podepnij własną domenę w sekcji Bucket → **Settings** → **Public access** (np. `assets.your-site.com`). W przypadku prywatnego bucketa na dokumenty pozostaw dostęp publiczny wyłączony.
3. Na stronie głównej R2 otwórz **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** wybierz wyłącznie docelowy bucket
   - Skopiuj wygenerowane wartości **Access Key ID**, **Secret Access Key** oraz **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Opcjonalnie — dla Edytora obrazów)* Dodaj regułę CORS w sekcji Bucket → **Settings** → **CORS Policy**:
   ```json
   [
       {
           "AllowedOrigins": ["https://your-site.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## Krok 3: Skonfiguruj `.env.local` oraz `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Scenariusz A — Publiczny bucket CDN oraz Scenariusz B — Prywatny skarbiec dokumentów

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Opcjonalnie — wykrywane automatycznie na podstawie endpointu
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Dla Cloudflare R2 zawsze używaj wartości 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'Prywatne archiwum R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Brak publicznego URL, ponieważ wolumen jest prywatny
            private: true             # Autoryzowane strumieniowanie przez Voter + DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                prefix: 'private-docs'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['cloudflare_r2_cdn', 'cloudflare_r2_private']
```

> **Zgodność z sumami kontrolnymi i ACL w AWS SDK v3.337+:** W `aws/aws-sdk-php >= 3.337` nowe domyślne nagłówki sum kontrolnych CRC32 mogą wywoływać błędy `XAmzContentSHA256Mismatch` lub `NotImplemented` w Cloudflare R2. Ilekroć `FlysystemStorageFactory` wykryje niestandardowy `endpoint`, **automatycznie ustawia** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` oraz `retain_visibility: false` — dodatkowa konfiguracja nie jest potrzebna.

---

## Krok 4: Zweryfikuj połączenie na żywo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Poprzedni: AWS S3](aws-s3.md) | [07 — Indeks magazynów w chmurze](../07-cloud-storage-and-dynamic-config.md) | [Następny: Backblaze B2 →](backblaze-b2.md)
