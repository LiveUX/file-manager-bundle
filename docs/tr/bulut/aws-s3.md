# Amazon AWS S3 Entegrasyon Rehberi

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **Amazon AWS S3'ü Ne Zaman Seçmelisiniz?**  
> Uygulamanız zaten AWS (EC2/ECS/CloudFront) üzerinde çalışıyorsa, IAM Role ile anahtarsız kimlik doğrulama ve küresel yüksek dayanıklılık (99.999999999%) gerekiyorsa endüstri standardı tercihtir.

LiveUX FileManagerBundle, AWS S3 bucket'larınızı `AwsS3` sürücüsüyle doğrudan bağlar ve sol menüde `aws_s3` SVG ikonunu otomatik gösterir.

---

## Adım 1: Gerekli Paketleri Kurun

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Adım 2: En Az Yetkili IAM Politikası Oluşturun

AWS IAM üzerinde yalnızca bu uygulama için bir kullanıcı (veya EC2/ECS Role) oluşturun ve sadece ilgili bucket'a erişim veren şu politikayı atayın:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ListBucket",
            "Effect": "Allow",
            "Action": ["s3:ListBucket"],
            "Resource": "arn:aws:s3:::SIZIN-BUCKET-ADINIZ"
        },
        {
            "Sid": "ManageObjects",
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::SIZIN-BUCKET-ADINIZ/*"
        }
    ]
}
```

> [!IMPORTANT]
> **Object Ownership / ACL Ayarı (`disable_acl: true`):**  
> Modern AWS S3 bucket'larında ACL varsayılan olarak kapalıdır (*Bucket owner enforced*). `disable_acl` ayarı yalnızca özel `endpoint` (R2, Supabase, MinIO vb.) kullanıldığında otomatik `true` olur; **standart (native) AWS S3'te varsayılan `false`'tur**.  
> `AccessControlListNotSupported` hatası almamak için aşağıdaki örnekte olduğu gibi `driver_options` altına **`disable_acl: true`** (ve isteğe bağlı `retain_visibility: false`) satırını açıkça ekleyin.

---

## Adım 3: Bucket CORS Ayarı (Görsel Düzenleyici İçin)

Dahili Canvas Görsel Düzenleyici'nin S3 üzerindeki görselleri tarayıcıda kırpıp düzenleyebilmesi için Bucket → **Permissions** → **CORS** bölümüne alan adınızı ekleyin:

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": ["https://siteniz.com"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

---

## Adım 4: `.env.local` ve `liveux_file_manager.yaml`

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
            icon: 'aws_s3'            # Opsiyonel — AwsS3 sürücüsünden otomatik algılanır
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.siteniz.com'  # CloudFront veya S3 public URL
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Dinamik yol yer tutucuları desteklenir
                disable_acl: true     # "Bucket owner enforced" S3 bucket'ları için önerilir
                retain_visibility: false

        aws_private_docs:
            label: 'AWS Gizli Arşiv'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access açık; dosyalar Voter + DownloadController ile akar
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

> **EC2 / ECS IAM Role veya AWS STS Geçici Kimlik Bilgileri:**
> - **IAM Role:** EC2 Instance Profile veya ECS Task Role kullanıyorsanız `key` ve `secret` satırlarını tamamen kaldırabilirsiniz; AWS SDK kimliği ortamdan otomatik okur.
> - **AWS STS Geçici Anahtar:** Geçici oturum belirteci kullanıyorsanız `driver_options.token: '%env(AWS_SESSION_TOKEN)%'` parametresini ekleyebilirsiniz.

---

## Adım 5: Canlı Doğrulama

```bash
php bin/console file-manager:doctor --check-remote
```

> **Not:** `aws_private_docs` gibi `prefix: 'private/{user_id}'` içeren kullanıcıya özel diskler, CLI ortamında oturum olmadığı için `file-manager:doctor --check-remote` tarafından `INFO` durumuyla güvenli biçimde atlanır.

---

[← Önceki: Supabase Storage](supabase-storage.md) | [07 — Depolama İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Sonraki: Cloudflare R2 →](cloudflare-r2.md)
