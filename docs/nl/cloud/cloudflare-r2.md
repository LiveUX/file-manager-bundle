# Integratiegids voor Cloudflare R2

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor Cloudflare R2?**  
> Verwerk je veel downloadverkeer voor afbeeldingen of video's en wil je **geen egress-kosten** in combinatie met de ingebouwde snelheid van het Cloudflare CDN? Dan is Cloudflare R2 een van de meest kostenefficiënte S3-compatibele oplossingen voor mediarijke projecten.

LiveUX FileManagerBundle herkent Cloudflare R2 automatisch op basis van het `.r2.cloudflarestorage.com`-endpoint en toont het `cloudflare_r2` SVG-icoon in de zijbalk.

---

## Stap 1: Vereiste packages installeren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Stap 2: Een bucket en API-token aanmaken in het Cloudflare Dashboard

1. Ga in het Cloudflare Dashboard naar **R2 Object Storage** → **Create bucket** en maak een bucket aan (bijv. `cdn-assets`).
2. Koppel voor publieke toegang een eigen domein onder Bucket → **Settings** → **Public access** (bijv. `assets.your-site.com`). Laat publieke toegang uitgeschakeld voor een privé-documentbucket.
3. Open op de overzichtspagina van R2 **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** selecteer uitsluitend de gewenste bucket
   - Kopieer de gegenereerde **Access Key ID**, **Secret Access Key** en het **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Optioneel — voor de afbeeldingseditor)* Voeg een CORS-regel toe onder Bucket → **Settings** → **CORS Policy**:
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

## Stap 3: `.env.local` en `liveux_file_manager.yaml` configureren

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Scenario A — Publieke CDN-bucket & Scenario B — Privé-documentkluis

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Optioneel — wordt automatisch herkend aan het endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Gebruik voor Cloudflare R2 altijd 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'R2 Privéarchief'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Geen publieke URL omdat dit volume privé is
            private: true             # Geautoriseerd streamen via Voter + DownloadController
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

> **Compatibiliteit met checksums & ACL's vanaf AWS SDK v3.337+:** In `aws/aws-sdk-php >= 3.337` kunnen de nieuwe standaard CRC32-checksumheaders bij Cloudflare R2 leiden tot `XAmzContentSHA256Mismatch`- of `NotImplemented`-fouten. Zodra `FlysystemStorageFactory` een eigen `endpoint` detecteert, past het **automatisch** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` en `retain_visibility: false` toe — zonder extra configuratie.

---

## Stap 4: Live-verbinding controleren

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Vorige: AWS S3](aws-s3.md) | [07 — Overzicht Cloudopslag](../07-cloud-storage-and-dynamic-config.md) | [Volgende: Backblaze B2 →](backblaze-b2.md)
