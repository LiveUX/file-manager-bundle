# Integrationsleitfaden: MinIO & RustFS (Self-Hosted / Docker)

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du MinIO / RustFS wählen?**  
> Selbst gehosteter S3-Speicher eignet sich perfekt für On-Premise-Unternehmensserver mit strengen Anforderungen an Datensouveränität oder Compliance (DSGVO, HIPAA usw.), bei denen Dateien deine eigene Infrastruktur nicht verlassen dürfen – oder um echtes S3-Verhalten lokal und kostenlos mit Docker zu testen.

---

## Schritt 1: Erforderliche Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Schritt 2: Schnelles Docker-Compose-Setup (Entwicklung / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # S3-API-Endpunkt
            - "9001:9001"   # Web-Verwaltungskonsole
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # CORS-Origin für den integrierten Canvas-Bildeditor bei öffentlichen Buckets:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Starte den Container und öffne `http://localhost:9001`, um einen Bucket namens `uploads` anzulegen (stelle die **Access Policy** auf `public` für öffentlichen Lesezugriff oder belasse sie auf `private` für geschützte Archive).

---

## Schritt 3: `.env.local` und `liveux_file_manager.yaml` konfigurieren

```dotenv
# .env.local
MINIO_ENDPOINT=http://localhost:9000
MINIO_BUCKET=uploads
MINIO_KEY=minioadmin
MINIO_SECRET=minioadmin-change-me
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        minio_storage:
            label: 'MinIO Storage'
            icon: 'minio'             # Automatisch erkannt, wenn der Endpunkt 'minio' oder ':9000' enthält; bei eigenen RustFS-Ports explizit angeben
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Auf null setzen, wenn private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Standardwert für MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Erforderlich für selbst gehostete S3-Server
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Falls ein selbstsigniertes HTTPS-Zertifikat verwendet wird:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Tipp (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Mit genau dieser `AwsS3`-Vorlage kannst du jeden beliebigen S3-kompatiblen Dienst anbinden – passe lediglich `endpoint`, `region` und `use_path_style_endpoint` an. Da ein benutzerdefinierter Endpunkt gesetzt ist, wendet `FlysystemStorageFactory` automatisch die `when_required`-Prüfsummenvalidierung sowie `disable_acl: true` an.

---

## Schritt 4: Live-Verbindung prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Zurück: Backblaze B2](backblaze-b2.md) | [07 — Cloud-Speicher-Übersicht](../07-cloud-storage-and-dynamic-config.md) | [Weiter: Google Cloud Storage (GCS) →](google-cloud-storage.md)
