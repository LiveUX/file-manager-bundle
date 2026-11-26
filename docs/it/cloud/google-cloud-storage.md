# Guida all'integrazione con Google Cloud Storage (GCS)

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere Google Cloud Storage?**  
> GCS è la scelta naturale se la tua applicazione gira già su Google Cloud (GKE, Cloud Run, Compute Engine) e vuoi connetterti direttamente tramite il suo livello di interoperabilità S3 (HMAC) senza aggiungere un ulteriore SDK proprietario.

**Google Cloud Storage (GCS)** funziona direttamente con il driver `AwsS3` tramite il suo livello nativo di compatibilità S3 (*XML API / Interoperability HMAC*) — anche se, se preferisci, puoi comunque integrare `league/flysystem-google-cloud-storage` tramite `FlysystemService`. L'endpoint `storage.googleapis.com` viene rilevato automaticamente e nella sidebar viene mostrata l'icona SVG `gcs`.

---

## Passaggio 1: Installare i pacchetti richiesti

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passaggio 2: Creare una chiave HMAC nella Google Cloud Console

1. Apri la Google Cloud Console → **Cloud Storage** → **Buckets** e crea un nuovo bucket (es. `my-symfony-media`).
2. Vai in **Cloud Storage** → **Settings** → **Interoperability**.
3. Sotto **Service account HMAC**, fai clic su **Create a key for a service account** (assicurati che il service account disponga del ruolo `Storage Object Admin`).
4. Copia la **Access key** (`GOOG1E...`) e il **Secret** generati.
5. *(Opzionale — per l'Editor Immagini)* Applica una policy CORS al bucket tramite un file `cors.json`:
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

## Passaggio 3: Configurare `.env.local` e `liveux_file_manager.yaml`

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
            icon: 'gcs'               # Opzionale — rilevata automaticamente dall'endpoint
            group: 'network'
            driver: 'AwsS3'
            # Per bucket pubblici: https://storage.googleapis.com/<bucket> (imposta null se privato)
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

> **Compatibilità automatica con GCS:** Poiché è configurato `endpoint: 'https://storage.googleapis.com'`, `FlysystemStorageFactory` applica automaticamente `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` e `retain_visibility: false`.

---

## Passaggio 4: Verificare la connettività live

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Precedente: MinIO / RustFS](minio-rustfs.md) | [07 — Indice Cloud Storage](../07-cloud-storage-and-dynamic-config.md) | [Successivo: SFTP e servizi Flysystem personalizzati →](sftp-and-custom-flysystem.md)
