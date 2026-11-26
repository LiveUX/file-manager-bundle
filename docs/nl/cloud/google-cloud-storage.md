# Integratiegids voor Google Cloud Storage (GCS)

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor Google Cloud Storage?**  
> GCS is de meest voor de hand liggende keuze als je applicatie al op Google Cloud draait (GKE, Cloud Run, Compute Engine) en je rechtstreeks wilt verbinden via de S3-interoperabiliteitslaag (HMAC), zonder een extra vendor-SDK te hoeven installeren.

**Google Cloud Storage (GCS)** werkt direct samen met de `AwsS3`-driver via de ingebouwde S3-compatibiliteitslaag (*XML API / Interoperability HMAC*) — al kun je desgewenst ook `league/flysystem-google-cloud-storage` koppelen via `FlysystemService`. Het `storage.googleapis.com`-endpoint wordt automatisch herkend en het `gcs` SVG-icoon verschijnt in de zijbalk.

---

## Stap 1: Vereiste packages installeren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Stap 2: Een HMAC-sleutel aanmaken in de Google Cloud Console

1. Open de Google Cloud Console → **Cloud Storage** → **Buckets** en maak een nieuwe bucket aan (bijv. `my-symfony-media`).
2. Ga naar **Cloud Storage** → **Settings** → **Interoperability**.
3. Klik onder **Service account HMAC** op **Create a key for a service account** (zorg dat het serviceaccount de rol `Storage Object Admin` heeft).
4. Kopieer de gegenereerde **Access key** (`GOOG1E...`) en **Secret**.
5. *(Optioneel — voor de afbeeldingseditor)* Pas een CORS-policy toe op de bucket met behulp van een `cors.json`-bestand:
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

## Stap 3: `.env.local` en `liveux_file_manager.yaml` configureren

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
            icon: 'gcs'               # Optioneel — wordt automatisch herkend aan het endpoint
            group: 'network'
            driver: 'AwsS3'
            # Voor publieke buckets: https://storage.googleapis.com/<bucket> (zet op null indien privé)
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

> **Automatische GCS-compatibiliteit:** Omdat `endpoint: 'https://storage.googleapis.com'` is geconfigureerd, past `FlysystemStorageFactory` automatisch `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` en `retain_visibility: false` toe.

---

## Stap 4: Live-verbinding controleren

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Vorige: MinIO / RustFS](minio-rustfs.md) | [07 — Overzicht Cloudopslag](../07-cloud-storage-and-dynamic-config.md) | [Volgende: SFTP & Aangepaste Flysystem-services →](sftp-and-custom-flysystem.md)
