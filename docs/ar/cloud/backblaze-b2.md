# دليل التكامل مع Backblaze B2

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار Backblaze B2؟**  
> يُعدّ Backblaze B2 خيارًا مثاليًا عندما تحتاج إلى تخزين مئات الجيجابايت من مقاطع الفيديو أو ملفات PDF الجاهزة للطباعة أو الأرشيفات عالية الدقة بأقل تكلفة تخزين ممكنة (خاصة عند دمجه مع نقل البيانات الصادر المجاني عبر شركاء Bandwidth Alliance مثل Cloudflare أو Bunny CDN).

يتم اكتشاف نقطة النهاية `.backblazeb2.com` تلقائيًا، وتُعرَض أيقونة `backblaze_b2` بصيغة SVG في الشريط الجانبي.

---

## الخطوة 1: تثبيت الحزم المطلوبة

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## الخطوة 2: إعداد الحاوية ومفتاح التطبيق و CORS في Backblaze

1. انتقل إلى Backblaze B2 ← **Buckets** ← **Create a Bucket** (مثل `app-media`).
   - بعد الإنشاء، دوّن قيمة **Endpoint** الظاهرة على بطاقة الحاوية (مثل `s3.eu-central-003.backblazeb2.com`).
   - قيمة **Region** هي الجزء الأوسط من نقطة النهاية: `eu-central-003`.
2. انتقل إلى **Application Keys** ← **Add a New Application Key**:
   - **Allow access to Bucket(s):** اختر الحاوية التي أنشأتها فقط
   - **Type of Access:** `Read and Write`
   - انسخ قيمتَي **`keyID`** و **`applicationKey`** المُنشأتَين.
3. *(اختياري — لمحرر الصور)* ضمن الحاوية ← **CORS Rules**، اختر إما **Share everything in this bucket with every origin** أو أضف قاعدة S3 CORS مخصّصة لنطاق موقعك:
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://your-site.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## الخطوة 3: إعداد `.env.local` و `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=app-media
B2_REGION=eu-central-003
B2_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
B2_KEY_ID=003xxxxxxxxxxxxxxxxxxxxxx
B2_APP_KEY=K003yyyyyyyyyyyyyyyyyyyyyyyyy
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        backblaze_b2:
            label: 'Backblaze B2'
            icon: 'backblaze_b2'      # اختياري — يتم اكتشافه تلقائيًا من نقطة النهاية
            group: 'network'
            driver: 'AwsS3'
            # للحاويات العامة، استخدم Friendly URL أو نطاق الـ CDN لديك (اضبطه على null إذا كانت خاصة):
            url_prefix: 'https://f003.backblazeb2.com/file/%env(B2_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(B2_BUCKET)%'
                region: '%env(B2_REGION)%'
                endpoint: '%env(B2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(B2_KEY_ID)%'
                secret: '%env(B2_APP_KEY)%'

    profiles:
        default:
            volumes: ['backblaze_b2']
```

> **ملاحظة:** نظرًا لإعداد نقطة نهاية مخصّصة، يقوم `FlysystemStorageFactory` تلقائيًا بتفعيل توافق Checksum في AWS SDK v3.337+ (`when_required`) إلى جانب `disable_acl: true` و `retain_visibility: false` لخدمة Backblaze B2.

---

## الخطوة 4: التحقق من الاتصال المباشر

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← السابق: Cloudflare R2](cloudflare-r2.md) | [07 — فهرس التخزين السحابي](../07-cloud-storage-and-dynamic-config.md) | [التالي: MinIO / RustFS →](minio-rustfs.md)
