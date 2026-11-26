# MinIO & RustFS (Self-Hosted / Docker) Integration Guide

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose MinIO / RustFS?**  
> Self-hosted S3 storage is ideal for on-premise enterprise servers with strict data-residency or compliance requirements (GDPR, HIPAA, etc.) where files must never leave your infrastructure, or for testing real S3 behavior locally for free with Docker.

---

## Step 1: Install Required Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Step 2: Quick Docker Compose Setup (Development / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # S3 API Endpoint
            - "9001:9001"   # Web Management Console
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # CORS origin for the built-in Canvas Image Editor on public buckets:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Start the container and open `http://localhost:9001` to create a bucket named `uploads` (set **Access Policy** to `public` for public read access, or keep it `private` for restricted archives).

---

## Step 3: Configure `.env.local` and `liveux_file_manager.yaml`

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
            icon: 'minio'             # Auto-detected if the endpoint contains 'minio' or ':9000'; set explicitly for custom RustFS ports
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Set to null if private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Standard default for MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Required for self-hosted S3 servers
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # If using a self-signed HTTPS certificate:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Tip (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** You can connect to any S3-compatible service using this exact `AwsS3` template—just update `endpoint`, `region`, and `use_path_style_endpoint`. Because a custom endpoint is set, `FlysystemStorageFactory` automatically applies `when_required` checksum validation and `disable_acl: true`.

---

## Step 4: Verify Live Connectivity

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Previous: Backblaze B2](backblaze-b2.md) | [07 — Cloud Storage Index](../07-cloud-storage-and-dynamic-config.md) | [Next: Google Cloud Storage (GCS) →](google-cloud-storage.md)
