# Przewodnik integracji z Backblaze B2

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać Backblaze B2?**  
> Backblaze B2 sprawdzi się idealnie, gdy potrzebujesz przechowywać setki gigabajtów wideo, plików PDF do druku lub archiwów w wysokiej rozdzielczości przy możliwie najniższym koszcie składowania (zwłaszcza w połączeniu z darmowym transferem wychodzącym za pośrednictwem partnerów Bandwidth Alliance, takich jak Cloudflare czy Bunny CDN).

Endpoint `.backblazeb2.com` jest wykrywany automatycznie, a na pasku bocznym pojawia się ikona SVG `backblaze_b2`.

---

## Krok 1: Zainstaluj wymagane pakiety

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Krok 2: Skonfiguruj bucket, Application Key i CORS w Backblaze

1. Przejdź do Backblaze B2 → **Buckets** → **Create a Bucket** (np. `app-media`).
   - Po utworzeniu zapisz **Endpoint** wyświetlony na karcie bucketa (np. `s3.eu-central-003.backblazeb2.com`).
   - **Region** to środkowy segment adresu endpointu: `eu-central-003`.
2. Przejdź do **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** wybierz wyłącznie utworzony bucket
   - **Type of Access:** `Read and Write`
   - Skopiuj wygenerowane wartości **`keyID`** oraz **`applicationKey`**.
3. *(Opcjonalnie — dla Edytora obrazów)* W sekcji Bucket → **CORS Rules** wybierz **Share everything in this bucket with every origin** albo dodaj własną regułę S3 CORS dla swojej domeny:
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://your-site.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## Krok 3: Skonfiguruj `.env.local` oraz `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=app-media
B2_REGION=eu-central-003
B2_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
B2_KEY_ID=003xxxxxxxxxxxxxxxxxxxxxx
B2_APP_KEY=K003yyyyyyyyyyyyyyyyyyyyyyyyy
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        backblaze_b2:
            label: 'Backblaze B2'
            icon: 'backblaze_b2'      # Opcjonalnie — wykrywane automatycznie na podstawie endpointu
            group: 'network'
            driver: 'AwsS3'
            # Dla publicznych bucketów użyj Friendly URL lub własnej domeny CDN (ustaw null, jeśli prywatny):
            url_prefix: 'https://f003.backblazeb2.com/file/%env(B2_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(B2_BUCKET)%'
                region: '%env(B2_REGION)%'
                endpoint: '%env(B2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(B2_KEY_ID)%'
                secret: '%env(B2_APP_KEY)%'

    profiles:
        default:
            volumes: ['backblaze_b2']
```

> **Uwaga:** Ponieważ skonfigurowany jest niestandardowy endpoint, `FlysystemStorageFactory` automatycznie włącza dla Backblaze B2 zgodność z sumami kontrolnymi AWS SDK v3.337+ (`when_required`), `disable_acl: true` oraz `retain_visibility: false`.

---

## Krok 4: Zweryfikuj połączenie na żywo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Poprzedni: Cloudflare R2](cloudflare-r2.md) | [07 — Indeks magazynów w chmurze](../07-cloud-storage-and-dynamic-config.md) | [Następny: MinIO / RustFS →](minio-rustfs.md)
