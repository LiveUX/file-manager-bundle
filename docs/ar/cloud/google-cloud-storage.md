# دليل التكامل مع Google Cloud Storage (GCS)

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار Google Cloud Storage؟**  
> يُعدّ GCS الخيار الطبيعي إذا كان تطبيقك يعمل بالفعل على Google Cloud (مثل GKE أو Cloud Run أو Compute Engine) وترغب في الاتصال مباشرة عبر طبقة التوافق مع S3 (مفاتيح HMAC) دون إضافة حزمة SDK إضافية من المزوّد.

يعمل **Google Cloud Storage (GCS)** مباشرة مع مُشغّل `AwsS3` عبر طبقة التوافق المدمجة مع S3 (*XML API / Interoperability HMAC*) — كما يمكنك أيضًا ربط `league/flysystem-google-cloud-storage` عبر `FlysystemService` إذا كنت تفضّل ذلك. يتم اكتشاف نقطة النهاية `storage.googleapis.com` تلقائيًا، وتُعرَض أيقونة `gcs` بصيغة SVG في الشريط الجانبي.

---

## الخطوة 1: تثبيت الحزم المطلوبة

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## الخطوة 2: إنشاء مفتاح HMAC في Google Cloud Console

1. افتح Google Cloud Console ← **Cloud Storage** ← **Buckets** وأنشئ حاوية جديدة (مثل `my-symfony-media`).
2. انتقل إلى **Cloud Storage** ← **Settings** ← **Interoperability**.
3. ضمن قسم **Service account HMAC**، انقر على **Create a key for a service account** (تأكّد من أن حساب الخدمة يمتلك دور `Storage Object Admin`).
4. انسخ قيمتَي **Access key** (`GOOG1E...`) و **Secret** المُنشأتَين.
5. *(اختياري — لمحرر الصور)* طبّق سياسة CORS على الحاوية باستخدام ملف `cors.json`:
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

## الخطوة 3: إعداد `.env.local` و `liveux_file_manager.yaml`

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
            icon: 'gcs'               # اختياري — يتم اكتشافه تلقائيًا من نقطة النهاية
            group: 'network'
            driver: 'AwsS3'
            # للحاويات العامة: https://storage.googleapis.com/<bucket> (اضبطه على null إذا كانت خاصة)
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

> **التوافق التلقائي مع GCS:** نظرًا لإعداد `endpoint: 'https://storage.googleapis.com'`، يطبّق `FlysystemStorageFactory` تلقائيًا `request_checksum_calculation: 'when_required'` و `response_checksum_validation: 'when_required'` و `disable_acl: true` و `retain_visibility: false`.

---

## الخطوة 4: التحقق من الاتصال المباشر

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← السابق: MinIO / RustFS](minio-rustfs.md) | [07 — فهرس التخزين السحابي](../07-cloud-storage-and-dynamic-config.md) | [التالي: خوادم SFTP وخدمات Flysystem المخصصة →](sftp-and-custom-flysystem.md)
