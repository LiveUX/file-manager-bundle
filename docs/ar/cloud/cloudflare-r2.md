# دليل التكامل مع Cloudflare R2

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار Cloudflare R2؟**  
> إذا كان تطبيقك يخدم حركة تحميل كثيفة للصور أو مقاطع الفيديو وترغب في **عدم دفع أي رسوم على نقل البيانات الصادرة (Zero Egress Fees)** مع الاستفادة من سرعة شبكة Cloudflare CDN المدمجة، فإن Cloudflare R2 يُعدّ من أكثر الحلول المتوافقة مع S3 توفيرًا للتكلفة في المشاريع الغنية بالوسائط.

تكتشف حزمة LiveUX FileManagerBundle خدمة Cloudflare R2 تلقائيًا من نقطة النهاية `.r2.cloudflarestorage.com` وتعرض أيقونة `cloudflare_r2` بصيغة SVG في الشريط الجانبي.

---

## الخطوة 1: تثبيت الحزم المطلوبة

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## الخطوة 2: إنشاء حاوية ورمز API في لوحة تحكم Cloudflare

1. انتقل إلى لوحة تحكم Cloudflare ← **R2 Object Storage** ← **Create bucket** وأنشئ حاوية جديدة (مثل `cdn-assets`).
2. للوصول العام، اربط نطاقك المخصّص ضمن الحاوية ← **Settings** ← **Public access** (مثل `assets.your-site.com`). أما لحاوية المستندات الخاصة فاترك الوصول العام معطّلاً.
3. من الصفحة الرئيسية لـ R2، افتح **Manage R2 API Tokens** ← **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** اختر الحاوية المستهدفة فقط
   - انسخ قيم **Access Key ID** و **Secret Access Key** و **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(اختياري — لمحرر الصور)* أضف قاعدة CORS ضمن الحاوية ← **Settings** ← **CORS Policy**:
   ```json
   [
       {
           "AllowedOrigins": ["https://your-site.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## الخطوة 3: إعداد `.env.local` و `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### السيناريو أ — حاوية CDN عامة والسيناريو ب — خزنة مستندات خاصة

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # اختياري — يتم اكتشافه تلقائيًا من نقطة النهاية
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # استخدم 'auto' دائمًا مع Cloudflare R2
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'أرشيف R2 الخاص'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # لا يوجد رابط عام لأن وحدة التخزين هذه خاصة
            private: true             # بثّ مصرَّح به عبر Voter و DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                prefix: 'private-docs'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['cloudflare_r2_cdn', 'cloudflare_r2_private']
```

> **توافق Checksum و ACL في AWS SDK v3.337+:** في الإصدارات `aws/aws-sdk-php >= 3.337`، قد تتسبب ترويسات التحقق الافتراضية الجديدة من نوع CRC32 في حدوث أخطاء `XAmzContentSHA256Mismatch` أو `NotImplemented` مع Cloudflare R2. عندما يكتشف `FlysystemStorageFactory` وجود `endpoint` مخصّص، فإنه **يطبّق تلقائيًا** `request_checksum_calculation: 'when_required'` و `response_checksum_validation: 'when_required'` و `disable_acl: true` و `retain_visibility: false` دون الحاجة إلى أي إعداد إضافي.

---

## الخطوة 4: التحقق من الاتصال المباشر

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← السابق: AWS S3](aws-s3.md) | [07 — فهرس التخزين السحابي](../07-cloud-storage-and-dynamic-config.md) | [التالي: Backblaze B2 →](backblaze-b2.md)
