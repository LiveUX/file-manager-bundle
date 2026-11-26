# Integratiegids voor Amazon AWS S3

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor Amazon AWS S3?**  
> AWS S3 is de industriestandaard wanneer je applicatie al op AWS draait (EC2/ECS/CloudFront) en je sleutelloze authenticatie via IAM-rollen wilt combineren met wereldwijde hoge duurzaamheid (99,999999999%).

LiveUX FileManagerBundle verbindt rechtstreeks met je AWS S3-buckets via de `AwsS3`-driver en toont automatisch het `aws_s3` SVG-icoon in de zijbalk.

---

## Stap 1: Vereiste packages installeren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Stap 2: Een IAM-policy met minimale rechten aanmaken

Maak in AWS IAM een aparte IAM-gebruiker (of EC2/ECS-rol) voor deze applicatie aan en koppel de onderstaande policy die strikt beperkt is tot jouw bucket:

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
> **Object Ownership / ACL-instelling (`disable_acl: true`):**  
> Moderne AWS S3-buckets schakelen ACL's standaard uit (*Bucket owner enforced*). De optie `disable_acl` wordt alleen automatisch op `true` gezet wanneer er een eigen `endpoint` (zoals R2, Supabase, MinIO, enz.) is geconfigureerd; **voor native AWS S3 staat deze standaard op `false`**.  
> Om `AccessControlListNotSupported`-fouten te voorkomen, voeg je expliciet **`disable_acl: true`** (en optioneel `retain_visibility: false`) toe onder `driver_options`, zoals in het onderstaande voorbeeld.

---

## Stap 3: Bucket-CORS configureren (voor de afbeeldingseditor)

Om ervoor te zorgen dat de ingebouwde Canvas-afbeeldingseditor afbeeldingen op S3 rechtstreeks in de browser kan bijsnijden en bewerken, voeg je jouw domein toe onder Bucket → **Permissions** → **CORS**:

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

## Stap 4: `.env.local` en `liveux_file_manager.yaml` configureren

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
            icon: 'aws_s3'            # Optioneel — wordt automatisch herkend aan de AwsS3-driver
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # Publieke URL van CloudFront of S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Dynamische pad-placeholders worden ondersteund
                disable_acl: true     # Aanbevolen voor S3-buckets met "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'AWS Privéarchief'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access ingeschakeld; bestanden streamen via Voter + DownloadController
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

> **EC2 / ECS IAM-rol of tijdelijke AWS STS-inloggegevens:**
> - **IAM-rol:** Als je een EC2 Instance Profile of ECS Task Role gebruikt, kun je `key` en `secret` volledig weglaten — de AWS SDK haalt de credentials automatisch uit de omgeving op.
> - **Tijdelijke AWS STS-inloggegevens:** Gebruik je tijdelijke sessietokens? Voeg dan `driver_options.token: '%env(AWS_SESSION_TOKEN)%'` toe.

---

## Stap 5: Live-verbinding controleren

```bash
php bin/console file-manager:doctor --check-remote
```

> **Opmerking:** Gebruikersspecifieke volumes met placeholders zoals `prefix: 'private/{user_id}'` (zoals `aws_private_docs`) worden door `file-manager:doctor --check-remote` veilig overgeslagen met de status `INFO`, omdat er in de CLI-omgeving geen gebruikerssessie aanwezig is.

---

[← Vorige: Supabase Storage](supabase-storage.md) | [07 — Overzicht Cloudopslag](../07-cloud-storage-and-dynamic-config.md) | [Volgende: Cloudflare R2 →](cloudflare-r2.md)
