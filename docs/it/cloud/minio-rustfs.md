# Guida all'integrazione con MinIO e RustFS (Self-Hosted / Docker)

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere MinIO / RustFS?**  
> Uno storage S3 self-hosted è ideale per server aziendali on-premise con severi requisiti di residenza dei dati o conformità normativa (GDPR, HIPAA, ecc.) in cui i file non devono mai lasciare la tua infrastruttura, oppure per testare gratuitamente in locale un vero ambiente S3 tramite Docker.

---

## Passaggio 1: Installare i pacchetti richiesti

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passaggio 2: Configurazione rapida con Docker Compose (Sviluppo / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # Endpoint API S3
            - "9001:9001"   # Console web di gestione
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # Origini CORS per il Canvas Image Editor integrato sui bucket pubblici:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Avvia il container e apri `http://localhost:9001` per creare un bucket chiamato `uploads` (imposta **Access Policy** su `public` per l'accesso pubblico in lettura, oppure lascialo `private` per archivi riservati).

---

## Passaggio 3: Configurare `.env.local` e `liveux_file_manager.yaml`

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
            icon: 'minio'             # Rilevata automaticamente se l'endpoint contiene 'minio' o ':9000'; impostala esplicitamente per porte RustFS personalizzate
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Imposta null se private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Valore predefinito standard per MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Richiesto per server S3 self-hosted
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Se utilizzi un certificato HTTPS autofirmato:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Suggerimento (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Puoi collegarti a qualsiasi servizio S3-compatibile usando esattamente questo template `AwsS3`: ti basta aggiornare `endpoint`, `region` e `use_path_style_endpoint`. Poiché è configurato un endpoint personalizzato, `FlysystemStorageFactory` applica automaticamente la validazione checksum `when_required` e `disable_acl: true`.

---

## Passaggio 4: Verificare la connettività live

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Precedente: Backblaze B2](backblaze-b2.md) | [07 — Indice Cloud Storage](../07-cloud-storage-and-dynamic-config.md) | [Successivo: Google Cloud Storage (GCS) →](google-cloud-storage.md)
