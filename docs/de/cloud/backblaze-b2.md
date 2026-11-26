# Integrationsleitfaden: Backblaze B2

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du Backblaze B2 wählen?**  
> Backblaze B2 ist ideal, wenn du hunderte Gigabyte an Videos, druckfertigen PDFs oder hochauflösenden Archiven zu minimalen Speicherkosten ablegen möchtest – besonders in Kombination mit kostenlosem Egress über Partner der Bandwidth Alliance wie Cloudflare oder Bunny CDN.

Der `.backblazeb2.com`-Endpunkt wird automatisch erkannt, und in der Seitenleiste erscheint das `backblaze_b2`-SVG-Icon.

---

## Schritt 1: Erforderliche Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Schritt 2: Bucket, Application Key und CORS in Backblaze einrichten

1. Navigiere zu Backblaze B2 → **Buckets** → **Create a Bucket** (z. B. `app-media`).
   - Notiere dir nach dem Erstellen den **Endpoint** auf der Bucket-Karte (z. B. `s3.eu-central-003.backblazeb2.com`).
   - Die **Region** entspricht dem mittleren Segment des Endpunkts: `eu-central-003`.
2. Öffne **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** wähle ausschließlich den zuvor angelegten Bucket aus
   - **Type of Access:** `Read and Write`
   - Kopiere die generierten Werte **`keyID`** und **`applicationKey`**.
3. *(Optional — für den Bildeditor)* Wähle unter Bucket → **CORS Rules** entweder **Share everything in this bucket with every origin** oder füge eine benutzerdefinierte S3-CORS-Regel für deine Domain hinzu:
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

## Schritt 3: `.env.local` und `liveux_file_manager.yaml` konfigurieren

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
            icon: 'backblaze_b2'      # Optional — wird automatisch anhand des Endpunkts erkannt
            group: 'network'
            driver: 'AwsS3'
            # Für öffentliche Buckets die Friendly URL oder deine CDN-Domain angeben (bei privaten Buckets auf null setzen):
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

> **Hinweis:** Da ein benutzerdefinierter Endpunkt konfiguriert ist, aktiviert `FlysystemStorageFactory` für Backblaze B2 automatisch die Prüfsummen-Kompatibilität ab AWS SDK v3.337+ (`when_required`), `disable_acl: true` sowie `retain_visibility: false`.

---

## Schritt 4: Live-Verbindung prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Zurück: Cloudflare R2](cloudflare-r2.md) | [07 — Cloud-Speicher-Übersicht](../07-cloud-storage-and-dynamic-config.md) | [Weiter: MinIO / RustFS →](minio-rustfs.md)
