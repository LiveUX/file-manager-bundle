# دليل التكامل مع MinIO و RustFS (استضافة ذاتية / Docker)

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار MinIO / RustFS؟**  
> يُعدّ التخزين المتوافق مع S3 ذو الاستضافة الذاتية مثاليًا لخوادم المؤسسات الداخلية (On-Premise) التي تفرض متطلبات صارمة لإقامة البيانات أو الامتثال التنظيمي (مثل GDPR و HIPAA وغيرها) بحيث لا تغادر الملفات بنيتك التحتية أبدًا، أو لاختبار سلوك S3 الحقيقي محليًا ومجانًا باستخدام Docker.

---

## الخطوة 1: تثبيت الحزم المطلوبة

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## الخطوة 2: إعداد سريع باستخدام Docker Compose (للتطوير أو الاستضافة الذاتية)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # نقطة نهاية S3 API
            - "9001:9001"   # لوحة الإدارة عبر الويب
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # نطاقات CORS المسموح بها لمحرر الصور المدمج (Canvas Image Editor) على الحاويات العامة:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

شغّل الحاوية ثم افتح `http://localhost:9001` لإنشاء حاوية باسم `uploads` (اضبط **Access Policy** على `public` للقراءة العامة، أو أبقِها `private` للأرشيفات المقيدة).

---

## الخطوة 3: إعداد `.env.local` و `liveux_file_manager.yaml`

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
            label: 'تخزين MinIO'
            icon: 'minio'             # يُكتشف تلقائيًا إذا احتوت نقطة النهاية على 'minio' أو ':9000'؛ حدّده صراحةً لمنافذ RustFS المخصّصة
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # اضبطه على null إذا كان private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # القيمة الافتراضية القياسية لـ MinIO و RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # مطلوب لخوادم S3 ذات الاستضافة الذاتية
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # عند استخدام شهادة HTTPS موقَّعة ذاتيًا:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **نصيحة (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** يمكنك الاتصال بأي خدمة متوافقة مع S3 باستخدام قالب `AwsS3` نفسه تمامًا — ما عليك سوى تحديث `endpoint` و `region` و `use_path_style_endpoint`. ونظرًا لتحديد نقطة نهاية مخصّصة، يطبّق `FlysystemStorageFactory` تلقائيًا التحقق `when_required` للـ checksum وكذلك `disable_acl: true`.

---

## الخطوة 4: التحقق من الاتصال المباشر

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← السابق: Backblaze B2](backblaze-b2.md) | [07 — فهرس التخزين السحابي](../07-cloud-storage-and-dynamic-config.md) | [التالي: Google Cloud Storage (GCS) →](google-cloud-storage.md)
