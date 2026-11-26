# Przewodnik integracji z MinIO i RustFS (Self-Hosted / Docker)

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać MinIO / RustFS?**  
> Magazyn S3 typu self-hosted to idealne rozwiązanie dla korporacyjnych serwerów on-premise o rygorystycznych wymaganiach dotyczących rezydencji danych lub zgodności z regulacjami (RODO/GDPR, HIPAA itp.), gdzie pliki nie mogą opuścić Twojej infrastruktury, a także do darmowego lokalnego testowania prawdziwego zachowania S3 za pomocą Dockera.

---

## Krok 1: Zainstaluj wymagane pakiety

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Krok 2: Szybka konfiguracja Docker Compose (środowisko lokalne / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # Endpoint S3 API
            - "9001:9001"   # Webowa konsola zarządzania
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # Źródła CORS dla wbudowanego edytora obrazów Canvas w publicznych bucketach:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Uruchom kontener i otwórz `http://localhost:9001`, aby utworzyć bucket o nazwie `uploads` (ustaw **Access Policy** na `public` dla publicznego odczytu lub pozostaw `private` dla zastrzeżonych archiwów).

---

## Krok 3: Skonfiguruj `.env.local` oraz `liveux_file_manager.yaml`

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
            label: 'Magazyn MinIO'
            icon: 'minio'             # Wykrywane automatycznie, jeśli endpoint zawiera 'minio' lub ':9000'; ustaw jawnie dla niestandardowych portów RustFS
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Ustaw null, jeśli private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Standardowa wartość domyślna dla MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Wymagane dla serwerów S3 typu self-hosted
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Jeśli używasz certyfikatu HTTPS z podpisem własnym (self-signed):
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Wskazówka (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Z dowolną usługą kompatybilną z S3 połączysz się za pomocą dokładnie tego samego szablonu `AwsS3` — wystarczy zaktualizować `endpoint`, `region` oraz `use_path_style_endpoint`. Ponieważ ustawiony jest niestandardowy endpoint, `FlysystemStorageFactory` automatycznie stosuje weryfikację sum kontrolnych `when_required` oraz `disable_acl: true`.

---

## Krok 4: Zweryfikuj połączenie na żywo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Poprzedni: Backblaze B2](backblaze-b2.md) | [07 — Indeks magazynów w chmurze](../07-cloud-storage-and-dynamic-config.md) | [Następny: Google Cloud Storage (GCS) →](google-cloud-storage.md)
