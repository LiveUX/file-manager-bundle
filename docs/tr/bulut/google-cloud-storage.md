# Google Cloud Storage (GCS) Entegrasyon Rehberi

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **Google Cloud Storage'ı Ne Zaman Seçmelisiniz?**  
> Uygulamanız Google Cloud (GKE, Cloud Run, Compute Engine) üzerinde çalışıyorsa ve ek bir SDK bağımlılığı eklemeden S3 uyumluluk katmanıyla (HMAC) doğrudan bağlanmak istiyorsanız idealdir.

**Google Cloud Storage (GCS)**, yerleşik S3 uyumluluk katmanı (*XML API / Interoperability HMAC*) sayesinde doğrudan `AwsS3` sürücüsüyle çalışır (isterseniz `league/flysystem-google-cloud-storage` ile `FlysystemService` üzerinden de bağlayabilirsiniz). `storage.googleapis.com` endpoint'i otomatik algılanır ve `gcs` SVG ikonu gösterilir.

---

## Adım 1: Gerekli Paketleri Kurun

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Adım 2: Google Cloud Console'dan HMAC Anahtarı Oluşturun

1. Google Cloud Console → **Cloud Storage** → **Buckets** → yeni bucket oluşturun (ör. `my-symfony-media`).
2. **Cloud Storage** → **Settings** → **Interoperability** sekmesine geçin.
3. **Service account HMAC** bölümünden **Create a key for a service account** seçin (ilgili servis hesabında `Storage Object Admin` rolü olmalıdır).
4. Oluşan **Access key** (`GOOG1E...`) ve **Secret** değerlerini kopyalayın.
5. *(Opsiyonel — Görsel Düzenleyici için)* `cors.json` dosyası ile bucket CORS politikası ekleyin:
   ```json
   [
       {
           "origin": ["https://siteniz.com"],
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

## Adım 3: `.env.local` ve `liveux_file_manager.yaml`

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
            icon: 'gcs'               # Opsiyonel — endpoint'ten otomatik algılanır
            group: 'network'
            driver: 'AwsS3'
            # Public bucket ise: https://storage.googleapis.com/<bucket> (private ise null)
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

> **Otomatik GCS Uyumluluğu:** `endpoint: 'https://storage.googleapis.com'` tanımlı olduğu için `FlysystemStorageFactory` otomatik olarak `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` ve `retain_visibility: false` ayarlarını uygular.

---

## Adım 4: Canlı Doğrulama

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Önceki: MinIO / RustFS](minio-rustfs.md) | [07 — Depolama İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Sonraki: SFTP & Özel Flysystem Servisleri →](sftp-ve-ozel-flysystem.md)
