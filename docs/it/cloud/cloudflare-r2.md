# Guida all'integrazione con Cloudflare R2

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere Cloudflare R2?**  
> Se gestisci un elevato traffico di download di immagini o video e desideri **zero costi di egress** combinati con la velocità integrata della CDN Cloudflare, Cloudflare R2 è una delle soluzioni S3-compatibili più convenienti per i progetti ricchi di contenuti multimediali.

LiveUX FileManagerBundle rileva automaticamente Cloudflare R2 dall'endpoint `.r2.cloudflarestorage.com` e mostra l'icona SVG `cloudflare_r2` nella sidebar.

---

## Passaggio 1: Installare i pacchetti richiesti

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passaggio 2: Creare un bucket e un API Token nella Cloudflare Dashboard

1. Vai su Cloudflare Dashboard → **R2 Object Storage** → **Create bucket** e crea un bucket (es. `cdn-assets`).
2. Per l'accesso pubblico, collega il tuo dominio personalizzato in Bucket → **Settings** → **Public access** (es. `assets.your-site.com`). Per un bucket privato di documenti, lascia l'accesso pubblico disabilitato.
3. Dalla pagina panoramica di R2, apri **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** seleziona solo il bucket di destinazione
   - Copia l'**Access Key ID**, la **Secret Access Key** e l'**S3 API Endpoint** generati (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Opzionale — per l'Editor Immagini)* Aggiungi una regola CORS in Bucket → **Settings** → **CORS Policy**:
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

## Passaggio 3: Configurare `.env.local` e `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Scenario A — Bucket CDN pubblico & Scenario B — Archivio documenti privato

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Opzionale — rilevata automaticamente dall'endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Usa sempre 'auto' per Cloudflare R2
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'Archivio privato R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Nessun URL pubblico perché questo volume è privato
            private: true             # Streaming autorizzato tramite Voter + DownloadController
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

> **Compatibilità Checksum e ACL in AWS SDK v3.337+:** In `aws/aws-sdk-php >= 3.337`, i nuovi header predefiniti di checksum CRC32 possono causare errori `XAmzContentSHA256Mismatch` o `NotImplemented` su Cloudflare R2. Ogni volta che `FlysystemStorageFactory` rileva un `endpoint` personalizzato, **applica automaticamente** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` e `retain_visibility: false`, senza richiedere alcuna configurazione aggiuntiva.

---

## Passaggio 4: Verificare la connettività live

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Precedente: AWS S3](aws-s3.md) | [07 — Indice Cloud Storage](../07-cloud-storage-and-dynamic-config.md) | [Successivo: Backblaze B2 →](backblaze-b2.md)
