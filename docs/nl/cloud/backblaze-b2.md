# Integratiegids voor Backblaze B2

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor Backblaze B2?**  
> Backblaze B2 is ideaal wanneer je honderden gigabytes aan video's, drukklare pdf's of archieven in hoge resolutie wilt opslaan tegen zo laag mogelijke opslagkosten — zeker in combinatie met gratis egress via Bandwidth Alliance-partners zoals Cloudflare of Bunny CDN.

Het `.backblazeb2.com`-endpoint wordt automatisch herkend en het `backblaze_b2` SVG-icoon verschijnt in de zijbalk.

---

## Stap 1: Vereiste packages installeren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Stap 2: Bucket, Application Key en CORS instellen in Backblaze

1. Ga naar Backblaze B2 → **Buckets** → **Create a Bucket** (bijv. `app-media`).
   - Noteer na het aanmaken het **Endpoint** op de bucketkaart (bijv. `s3.eu-central-003.backblazeb2.com`).
   - De **Region** is het middelste deel van het endpoint: `eu-central-003`.
2. Ga naar **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** selecteer alleen de zojuist aangemaakte bucket
   - **Type of Access:** `Read and Write`
   - Kopieer de gegenereerde waarden **`keyID`** en **`applicationKey`**.
3. *(Optioneel — voor de afbeeldingseditor)* Kies onder Bucket → **CORS Rules** voor **Share everything in this bucket with every origin**, of voeg een eigen S3 CORS-regel toe voor jouw domein:
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

## Stap 3: `.env.local` en `liveux_file_manager.yaml` configureren

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
            icon: 'backblaze_b2'      # Optioneel — wordt automatisch herkend aan het endpoint
            group: 'network'
            driver: 'AwsS3'
            # Gebruik voor publieke buckets de Friendly URL of je CDN-domein (zet op null indien privé):
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

> **Opmerking:** Omdat er een eigen endpoint is ingesteld, schakelt `FlysystemStorageFactory` voor Backblaze B2 automatisch de AWS SDK v3.337+ checksum-compatibiliteit (`when_required`), `disable_acl: true` en `retain_visibility: false` in.

---

## Stap 4: Live-verbinding controleren

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Vorige: Cloudflare R2](cloudflare-r2.md) | [07 — Overzicht Cloudopslag](../07-cloud-storage-and-dynamic-config.md) | [Volgende: MinIO / RustFS →](minio-rustfs.md)
