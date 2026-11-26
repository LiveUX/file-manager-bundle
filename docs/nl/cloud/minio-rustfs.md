# Integratiegids voor MinIO & RustFS (Self-Hosted / Docker)

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor MinIO / RustFS?**  
> Self-hosted S3-opslag is ideaal voor on-premise bedrijfsservers met strenge eisen rondom datasoevereiniteit of compliance (AVG/GDPR, HIPAA, enz.) waarbij bestanden je eigen infrastructuur niet mogen verlaten — of om lokaal met Docker gratis echt S3-gedrag te testen.

---

## Stap 1: Vereiste packages installeren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Stap 2: Snelle Docker Compose-opstelling (Ontwikkeling / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # S3 API Endpoint
            - "9001:9001"   # Webbeheerconsole
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # CORS-origin voor de ingebouwde Canvas-afbeeldingseditor bij publieke buckets:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Start de container en open `http://localhost:9001` om een bucket met de naam `uploads` aan te maken (zet **Access Policy** op `public` voor publieke leestoegang, of laat deze op `private` staan voor afgeschermde archieven).

---

## Stap 3: `.env.local` en `liveux_file_manager.yaml` configureren

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
            label: 'MinIO-opslag'
            icon: 'minio'             # Wordt automatisch herkend als het endpoint 'minio' of ':9000' bevat; stel expliciet in voor aangepaste RustFS-poorten
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Zet op null wanneer private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Standaardwaarde voor MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Vereist voor self-hosted S3-servers
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Bij gebruik van een self-signed HTTPS-certificaat:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Tip (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Met precies dezelfde `AwsS3`-configuratie kun je koppelen met elke S3-compatibele dienst — pas alleen `endpoint`, `region` en `use_path_style_endpoint` aan. Omdat er een eigen endpoint is ingesteld, past `FlysystemStorageFactory` automatisch `when_required`-checksumvalidatie en `disable_acl: true` toe.

---

## Stap 4: Live-verbinding controleren

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Vorige: Backblaze B2](backblaze-b2.md) | [07 — Overzicht Cloudopslag](../07-cloud-storage-and-dynamic-config.md) | [Volgende: Google Cloud Storage (GCS) →](google-cloud-storage.md)
