# Przewodnik integracji z Google Cloud Storage (GCS)

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać Google Cloud Storage?**  
> GCS to naturalny wybór, jeśli Twoja aplikacja działa już w Google Cloud (GKE, Cloud Run, Compute Engine) i chcesz łączyć się bezpośrednio przez warstwę interoperacyjności S3 (HMAC) bez dodawania kolejnego SDK konkretnego dostawcy.

**Google Cloud Storage (GCS)** współpracuje bezpośrednio ze sterownikiem `AwsS3` poprzez wbudowaną warstwę zgodności z S3 (*XML API / Interoperability HMAC*) — choć jeśli wolisz, możesz też podpiąć `league/flysystem-google-cloud-storage` za pomocą `FlysystemService`. Endpoint `storage.googleapis.com` jest wykrywany automatycznie, a na pasku bocznym wyświetlana jest ikona SVG `gcs`.

---

## Krok 1: Zainstaluj wymagane pakiety

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Krok 2: Utwórz klucz HMAC w konsoli Google Cloud

1. Otwórz Google Cloud Console → **Cloud Storage** → **Buckets** i utwórz nowy bucket (np. `my-symfony-media`).
2. Przejdź do **Cloud Storage** → **Settings** → **Interoperability**.
3. W sekcji **Service account HMAC** kliknij **Create a key for a service account** (upewnij się, że konto usługi ma przypisaną rolę `Storage Object Admin`).
4. Skopiuj wygenerowane wartości **Access key** (`GOOG1E...`) oraz **Secret**.
5. *(Opcjonalnie — dla Edytora obrazów)* Zastosuj politykę CORS dla bucketa za pomocą pliku `cors.json`:
   ```json
   [
       {
           "origin": ["https://your-site.com"],
           "method": ["GET", "HEAD"],
           "responseHeader": ["Content-Type", "ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```
   ```bash
   gcloud storage buckets update gs://my-symfony-media --cors-file=cors.json
   ```

---

## Krok 3: Skonfiguruj `.env.local` oraz `liveux_file_manager.yaml`

```dotenv
# .env.local
GCS_BUCKET=my-symfony-media
GCS_HMAC_KEY=GOOG1E...
GCS_HMAC_SECRET=...
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        gcs_media:
            label: 'Google Cloud Storage'
            icon: 'gcs'               # Opcjonalnie — wykrywane automatycznie na podstawie endpointu
            group: 'network'
            driver: 'AwsS3'
            # Dla publicznych bucketów: https://storage.googleapis.com/<bucket> (ustaw null, jeśli prywatny)
            url_prefix: 'https://storage.googleapis.com/%env(GCS_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(GCS_BUCKET)%'
                region: 'auto'
                endpoint: 'https://storage.googleapis.com'
                use_path_style_endpoint: true
                key: '%env(GCS_HMAC_KEY)%'
                secret: '%env(GCS_HMAC_SECRET)%'

    profiles:
        default:
            volumes: ['gcs_media']
```

> **Automatyczna zgodność z GCS:** Ponieważ skonfigurowano `endpoint: 'https://storage.googleapis.com'`, `FlysystemStorageFactory` automatycznie stosuje `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` oraz `retain_visibility: false`.

---

## Krok 4: Zweryfikuj połączenie na żywo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Poprzedni: MinIO / RustFS](minio-rustfs.md) | [07 — Indeks magazynów w chmurze](../07-cloud-storage-and-dynamic-config.md) | [Następny: SFTP i niestandardowe usługi Flysystem →](sftp-and-custom-flysystem.md)
