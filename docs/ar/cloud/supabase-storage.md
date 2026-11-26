# دليل التكامل مع Supabase Storage (S3)

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار Supabase Storage؟**  
> إذا كان لديك بالفعل مشروع على Supabase — أو كنت تبحث عن باقة مجانية سخية لإدارة ملفات الـ CDN العامة وحاويات المستندات الخاصة من لوحة تحكم واحدة — فإن Supabase Storage يُعدّ الخيار العملي الأفضل بين خدمات التخزين المتوافقة مع بروتوكول S3.

نظرًا لأن **Supabase Storage** يدعم البروتوكول المتوافق مع S3، فإنه يعمل مباشرة مع حزمة LiveUX FileManagerBundle ضمن إعداد هجين يجمع بين **التخزين المحلي + سحابة Supabase** دون الحاجة لكتابة سطر واحد من الشيفرة المخصصة. تكتشف الحزمة تلقائيًا مزوّد `supabase` من رابط نقطة النهاية (Endpoint) وتعرض أيقونة Supabase بصيغة SVG في الشريط الجانبي.

---

## الخطوة 1: تثبيت الحزم المطلوبة

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## الخطوة 2: إنشاء مفاتيح وصول S3 من لوحة تحكم Supabase

> [!WARNING]
> **هام:** مفاتيح واجهة REST/Auth في Supabase — وهي `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) و `SUPABASE_SECRET_KEY` (`sb_secret_...`) — **لا** تعمل مع بروتوكول S3. يجب استخدام بيانات الاعتماد المكوّنة من 32 حرفًا و64 حرفًا والتي يتم إنشاؤها ضمن قسم **S3 Access Keys** أدناه.

1. افتح مشروعك في لوحة تحكم Supabase ← **Storage** ← **New bucket** وأنشئ حاوية تخزين جديدة (مثل `media` أو `prints`).
   - فعّل خيار **Public bucket** للصور والأصول العامة التي ستُعرض عبر الـ CDN.
   - اترك خيار **Public bucket** غير مفعّل (حاوية خاصة) لمستندات العملاء أو الفواتير.
2. انتقل إلى **Project Settings** ← **Storage** (أو **Storage** ← **Settings**).
3. انسخ القيمتين التاليتين من بطاقة **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** مثل `eu-central-1` أو `eu-west-1`
4. ضمن قسم **S3 Access Keys**، انقر على **New access key**، وأدخل وصفًا للمفتاح، ثم انسخ القيم المُنشأة:
   - **Access Key ID** (مكوّن من 32 حرفًا)
   - **Secret Access Key** (مكوّن من 64 حرفًا — يظهر مرة واحدة فقط!)

---

## الخطوة 3: إضافة متغيرات البيئة إلى `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## الخطوة 4: إعداد ملف `liveux_file_manager.yaml`

### السيناريو أ — حاوية عامة (Public Bucket)

يتم تقديم الصور ومقاطع الفيديو والملفات العامة مباشرة عبر رابط Supabase CDN (مما يخفف العبء عن خادم PHP لديك)، بينما تُولَّد الصور المصغّرة مرة واحدة على خادمك وتُقدَّم من ذاكرة التخزين المؤقت المحلية (`public/media-cache`):

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'التخزين المحلي'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # اختياري — يتم اكتشافه تلقائيًا من نقطة النهاية
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # تخزين مؤقت لمدة 60 ثانية للمحتوى السحابي القابل للتغيير خارجيًا
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # أو 'network_first' / 'configured'
```

### السيناريو ب — حاوية خاصة مع عزل لكل مستخدم

للفواتير أو ملفات PDF السرية الخاصة بالعملاء والتي لا يُسمح بالوصول إليها إلا للمستخدمين المصادَق عليهم والمصرَّح لهم:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'خزنة مستندات Supabase'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # لا يوجد رابط عام لأن هذه الحاوية خاصة
            private: true             # بثّ آمن للملفات عبر DownloadController و Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # يعزل كل مستخدم داخل مجلده الفرعي دون أي تعارض
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## الخطوة 5: التحقق من الاتصال المباشر بأمر واحد

```bash
php bin/console file-manager:doctor --check-remote
```

مثال للمخرجات عند نجاح الاتصال:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **ملاحظة:** يتم تخطي وحدات التخزين الديناميكية المخصّصة لكل مستخدم والتي تستخدم عناصر نائبة مثل `prefix: 'users/{user_id}'` (كما في السيناريو ب) بأمان مع حالة `INFO` عند تشغيل `file-manager:doctor --check-remote`، وذلك لعدم وجود جلسة مستخدم نشطة في بيئة سطر الأوامر (CLI).

---

## نصائح خاصة بـ Supabase واستكشاف الأخطاء وإصلاحها

### 1. حذف الملفات داخل حاوية مقيّدة بأنواع MIME (`InvalidMimeType: application/json`)
إذا قمت بضبط خيار **Allowed MIME types** في حاوية Supabase للسماح بأنواع محددة فقط مثل `application/pdf` أو `image/*`، فسيُرجِع Supabase خطأ `InvalidMimeType` عندما تحاول ميزة سلة المحذوفات كتابة ملف `.trash/<id>/.meta.json`.
- **الآلية الاحتياطية التلقائية (Automatic Fallback):** يكتشف `TrashManager` هذا السلوك تلقائيًا — حيث يُنقَل الملف نفسه إلى `.trash/<id>/...` داخل الحاوية السحابية، بينما تُكتَب البيانات الوصفية `.meta.json` في ملف احتياطي محلي على خادمك (`trash-meta.json` أو `<name>.trash-meta.json` داخل مجلد `defaults.metadata.file`). وبذلك تستمر عمليتا الاستعادة والحذف النهائي في العمل بسلاسة تامة.

### 2. أخطاء Checksum و ACL في AWS SDK v3.337+
بالنسبة لوحدات تخزين S3 المُعدَّة بنقطة نهاية مخصّصة (`endpoint`) مثل Supabase، يطبّق `FlysystemStorageFactory` تلقائيًا الإعدادات التالية:
- `request_checksum_calculation: 'when_required'` و `response_checksum_validation: 'when_required'`
- `retain_visibility: false` وإزالة ترويسات `x-amz-acl` (`disable_acl: true`)

لا يلزم إجراء أي إعداد إضافي.

### 3. خطأ شهادة SSL (`cURL error 60`) على Windows / WAMP
إذا واجهت الخطأ `cURL error 60: unable to get local issuer certificate` في بيئة التطوير المحلية لديك:
- نزّل ملف [cacert.pem](https://curl.se/ca/cacert.pem) ثم أشر إليه في `php.ini` (في ملفَّي `php.ini` الخاصَّين بـ Apache و CLI):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- أو بدلاً من ذلك، حدّده لوحدة تخزين معيّنة فقط عبر `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. بقاء الملفات المحذوفة من لوحة تحكم Supabase ظاهرة في القائمة
- انقر على زر **تحديث (`↻`)** في شريط الأدوات (أو اضغط المفتاح `R`) لتجاوز ذاكرة التخزين المؤقت فورًا عبر `?refresh=1`.
- إذا كانت ملفات الحاوية تتغير باستمرار من خارج التطبيق، فاضبط `cache_ttl: 30` (أو `cache_ttl: 0` لتعطيل التخزين المؤقت) على وحدة التخزين.

---

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [التالي: دليل AWS S3 →](aws-s3.md)
