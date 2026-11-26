# MinIO & RustFS (Self-Hosted / Docker) Entegrasyon Rehberi

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **MinIO / RustFS'yi Ne Zaman Seçmelisiniz?**  
> Dosyaların üçüncü parti buluta çıkmaması gereken KVKK/kurumsal sunucularda (on-premise) ya da yerel geliştirme bilgisayarınızda Docker ile gerçek S3 davranışını ücretsiz test etmek istediğinizde idealdir.

---

## Adım 1: Gerekli Paketleri Kurun

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Adım 2: Hızlı Docker Compose Kurulumu (Geliştirme / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # S3 API Endpoint
            - "9001:9001"   # Web Yönetim Paneli
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # Public bucket'ta Görsel Düzenleyici (Canvas) için CORS izni:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://siteniz.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Başlatın ve `http://localhost:9001` panelinden `uploads` adında bir bucket oluşturun (public okuma için Access Policy → `public`, gizli arşiv için `private` bırakın).

---

## Adım 3: `.env.local` ve `liveux_file_manager.yaml`

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
            label: 'MinIO Depolama'
            icon: 'minio'             # Endpoint 'minio' veya ':9000' içeriyorsa otomatik algılanır; özel RustFS portlarında elle verebilirsiniz
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # private: true ise null
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # MinIO/RustFS için standart değer
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Self-hosted S3 için zorunlu
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Kendi imzaladığınız (self-signed) HTTPS sertifikası kullanıyorsanız:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **İpucu (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Aynı `AwsS3` şablonunda yalnızca `endpoint`, `region` ve `use_path_style_endpoint` değerlerini güncelleyerek tüm S3 uyumlu servislere bağlanabilirsiniz. `FlysystemStorageFactory` özel endpoint nedeniyle `when_required` checksum ve `disable_acl: true` ayarlarını otomatik uygular.

---

## Adım 4: Canlı Doğrulama

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Önceki: Backblaze B2](backblaze-b2.md) | [07 — Depolama İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Sonraki: Google Cloud Storage (GCS) →](google-cloud-storage.md)
