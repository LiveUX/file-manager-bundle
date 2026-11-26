# Integrationsleitfaden: Google Cloud Storage (GCS)

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du Google Cloud Storage wählen?**  
> GCS ist die naheliegende Wahl, wenn deine Anwendung bereits in der Google Cloud (GKE, Cloud Run, Compute Engine) läuft und du dich direkt über die S3-Interoperabilitätsschicht (HMAC) verbinden möchtest, ohne ein zusätzliches Anbieter-SDK installieren zu müssen.

**Google Cloud Storage (GCS)** arbeitet über seine integrierte S3-Kompatibilitätsschicht (*XML API / Interoperability HMAC*) direkt mit dem `AwsS3`-Treiber zusammen – alternativ kannst du bei Bedarf auch `league/flysystem-google-cloud-storage` über `FlysystemService` einbinden. Der Endpunkt `storage.googleapis.com` wird automatisch erkannt, und in der Seitenleiste wird das `gcs`-SVG-Icon angezeigt.

---

## Schritt 1: Erforderliche Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Schritt 2: HMAC-Schlüssel in der Google Cloud Console erstellen

1. Öffne die Google Cloud Console → **Cloud Storage** → **Buckets** und erstelle einen neuen Bucket (z. B. `my-symfony-media`).
2. Navigiere zu **Cloud Storage** → **Settings** → **Interoperability**.
3. Klicke unter **Service account HMAC** auf **Create a key for a service account** (stelle sicher, dass das Dienstkonto die Rolle `Storage Object Admin` besitzt).
4. Kopiere den generierten **Access key** (`GOOG1E...`) sowie das **Secret**.
5. *(Optional — für den Bildeditor)* Wende über eine Datei `cors.json` eine CORS-Richtlinie auf den Bucket an:
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

## Schritt 3: `.env.local` und `liveux_file_manager.yaml` konfigurieren

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
            icon: 'gcs'               # Optional — wird automatisch anhand des Endpunkts erkannt
            group: 'network'
            driver: 'AwsS3'
            # Für öffentliche Buckets: https://storage.googleapis.com/<bucket> (bei privaten Buckets auf null setzen)
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

> **Automatische GCS-Kompatibilität:** Da `endpoint: 'https://storage.googleapis.com'` konfiguriert ist, setzt `FlysystemStorageFactory` automatisch `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` und `retain_visibility: false`.

---

## Schritt 4: Live-Verbindung prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Zurück: MinIO / RustFS](minio-rustfs.md) | [07 — Cloud-Speicher-Übersicht](../07-cloud-storage-and-dynamic-config.md) | [Weiter: SFTP & Benutzerdefinierte Flysystem-Services →](sftp-and-custom-flysystem.md)
