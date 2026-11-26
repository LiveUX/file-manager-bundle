# دليل التكامل مع Amazon AWS S3

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار Amazon AWS S3؟**  
> يُعدّ AWS S3 المعيار الصناعي الأنسب عندما يعمل تطبيقك بالفعل على AWS (مثل EC2 أو ECS أو CloudFront) وتحتاج إلى مصادقة بدون مفاتيح ثابتة عبر أدوار IAM (IAM Role) مع موثوقية عالية ومستوى استدامة عالمي يصل إلى (99.999999999%).

تتصل حزمة LiveUX FileManagerBundle مباشرة بحاويات AWS S3 باستخدام مُشغّل `AwsS3` وتعرض تلقائيًا أيقونة `aws_s3` بصيغة SVG في الشريط الجانبي.

---

## الخطوة 1: تثبيت الحزم المطلوبة

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## الخطوة 2: إنشاء سياسة IAM بأقل صلاحيات ممكنة

أنشئ مستخدم IAM مخصّصًا (أو دور EC2/ECS Role) في AWS IAM لهذا التطبيق، وأرفق به السياسة التالية المحصورة بدقة في حاوية التخزين الخاصة بك:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ListBucket",
            "Effect": "Allow",
            "Action": ["s3:ListBucket"],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME"
        },
        {
            "Sid": "ManageObjects",
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
        }
    ]
}
```

> [!IMPORTANT]
> **إعداد ملكية الكائنات و ACL (`disable_acl: true`):**  
> تقوم حاويات AWS S3 الحديثة بتعطيل قوائم التحكم بالوصول (ACLs) افتراضيًا (*Bucket owner enforced*). ولا يتم تعيين الخيار `disable_acl` إلى `true` تلقائيًا إلا عند تحديد `endpoint` مخصّص (مثل R2 و Supabase و MinIO وغيرها)؛ **أما مع AWS S3 الأصلي فتكون قيمته الافتراضية `false`**.  
> لتفادي أخطاء `AccessControlListNotSupported`، أضف صراحةً **`disable_acl: true`** (واختياريًا `retain_visibility: false`) ضمن `driver_options` كما هو موضح في المثال أدناه.

---

## الخطوة 3: إعداد سياسة CORS للحاوية (لمحرر الصور)

لتمكين محرر الصور المدمج (Canvas Image Editor) من قص وتعديل الصور المستضافة على S3 مباشرة في المتصفح، أضف نطاق موقعك ضمن الحاوية ← **Permissions** ← **CORS**:

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": ["https://your-site.com"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

---

## الخطوة 4: إعداد `.env.local` و `liveux_file_manager.yaml`

```dotenv
# .env.local
AWS_S3_BUCKET=my-media-bucket
AWS_S3_REGION=eu-central-1
AWS_S3_KEY=AKIAIOSFODNN7EXAMPLE
AWS_S3_SECRET=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        aws_cdn:
            label: 'AWS S3 (CloudFront CDN)'
            icon: 'aws_s3'            # اختياري — يتم اكتشافه تلقائيًا من مُشغّل AwsS3
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # رابط CloudFront أو الرابط العام لحاوية S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # يدعم العناصر النائبة الديناميكية للمسار
                disable_acl: true     # مُوصى به لحاويات S3 بوضع "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'أرشيف AWS الخاص'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # حظر الوصول العام مفعّل؛ تُبثّ الملفات عبر Voter و DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'private/{user_id}'
                disable_acl: true
                retain_visibility: false

    profiles:
        default:
            volumes: ['aws_cdn', 'aws_private_docs']
```

> **أدوار EC2 / ECS IAM أو بيانات الاعتماد المؤقتة عبر AWS STS:**
> - **IAM Role:** إذا كنت تعتمد على EC2 Instance Profile أو ECS Task Role، يمكنك حذف `key` و `secret` تمامًا — حيث تستخرج مكتبة AWS SDK بيانات الاعتماد تلقائيًا من البيئة.
> - **بيانات الاعتماد المؤقتة عبر AWS STS:** إذا كنت تستخدم رموز جلسة مؤقتة، فأضف `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## الخطوة 5: التحقق من الاتصال المباشر

```bash
php bin/console file-manager:doctor --check-remote
```

> **ملاحظة:** يتم تخطي وحدات التخزين المرتبطة بالمستخدم والتي تحتوي على عناصر نائبة مثل `prefix: 'private/{user_id}'` (مثل `aws_private_docs`) بأمان مع حالة `INFO` بواسطة `file-manager:doctor --check-remote`، نظرًا لعدم وجود جلسة مستخدم في بيئة سطر الأوامر (CLI).

---

[← السابق: Supabase Storage](supabase-storage.md) | [07 — فهرس التخزين السحابي](../07-cloud-storage-and-dynamic-config.md) | [التالي: Cloudflare R2 →](cloudflare-r2.md)
