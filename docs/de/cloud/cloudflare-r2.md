# Integrationsleitfaden: Cloudflare R2

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du Cloudflare R2 wählen?**  
> Wenn du hohes Download-Aufkommen bei Bildern oder Videos bedienst und **null Egress-Gebühren (Datenausgangskosten)** in Kombination mit integrierter Cloudflare-CDN-Geschwindigkeit suchst, zählt Cloudflare R2 zu den wirtschaftlichsten S3-kompatiblen Lösungen für medienintensive Projekte.

Das LiveUX FileManagerBundle erkennt Cloudflare R2 automatisch anhand des `.r2.cloudflarestorage.com`-Endpunkts und zeigt das `cloudflare_r2`-SVG-Icon in der Seitenleiste an.

---

## Schritt 1: Erforderliche Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Schritt 2: Bucket und API-Token im Cloudflare-Dashboard erstellen

1. Öffne im Cloudflare-Dashboard **R2 Object Storage** → **Create bucket** und lege einen Bucket an (z. B. `cdn-assets`).
2. Verbinde für öffentlichen Zugriff deine eigene Domain unter Bucket → **Settings** → **Public access** (z. B. `assets.your-site.com`). Für einen privaten Dokumenten-Bucket lasse den öffentlichen Zugriff deaktiviert.
3. Öffne auf der R2-Übersichtsseite **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** wähle ausschließlich den Ziel-Bucket aus
   - Kopiere die generierten Werte **Access Key ID**, **Secret Access Key** sowie den **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Optional — für den Bildeditor)* Hinterlege unter Bucket → **Settings** → **CORS Policy** eine CORS-Regel:
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

## Schritt 3: `.env.local` und `liveux_file_manager.yaml` konfigurieren

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Szenario A — Öffentlicher CDN-Bucket & Szenario B — Privater Dokumententresor

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Optional — wird automatisch anhand des Endpunkts erkannt
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Für Cloudflare R2 immer 'auto' verwenden
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'R2 Privates Archiv'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Keine öffentliche URL, da dieses Volume privat ist
            private: true             # Autorisiertes Streaming über Voter + DownloadController
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

> **Prüfsummen- und ACL-Kompatibilität ab AWS SDK v3.337+:** In `aws/aws-sdk-php >= 3.337` können die neuen standardmäßigen CRC32-Prüfsummen-Header auf Cloudflare R2 Fehler wie `XAmzContentSHA256Mismatch` oder `NotImplemented` auslösen. Sobald `FlysystemStorageFactory` einen benutzerdefinierten `endpoint` erkennt, wendet es **automatisch** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` und `retain_visibility: false` an – zusätzliche Konfiguration ist nicht nötig.

---

## Schritt 4: Live-Verbindung prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Zurück: AWS S3](aws-s3.md) | [07 — Cloud-Speicher-Übersicht](../07-cloud-storage-and-dynamic-config.md) | [Weiter: Backblaze B2 →](backblaze-b2.md)
