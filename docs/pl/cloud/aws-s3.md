# Przewodnik integracji z Amazon AWS S3

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać Amazon AWS S3?**  
> AWS S3 to branżowy standard, gdy Twoja aplikacja działa już w ekosystemie AWS (EC2/ECS/CloudFront) i potrzebujesz bezkluczowego uwierzytelniania za pomocą ról IAM wraz z globalną, niezwykle wysoką trwałością danych (99,999999999%).

LiveUX FileManagerBundle łączy się bezpośrednio z bucketami AWS S3 przy użyciu sterownika `AwsS3` i automatycznie wyświetla ikonę SVG `aws_s3` na pasku bocznym.

---

## Krok 1: Zainstaluj wymagane pakiety

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Krok 2: Utwórz politykę IAM o minimalnych uprawnieniach

Utwórz w AWS IAM dedykowanego użytkownika IAM (lub rolę EC2/ECS) dla swojej aplikacji i przypisz mu poniższą politykę ograniczoną ściśle do wybranego bucketa:

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
> **Ustawienie Object Ownership / ACL (`disable_acl: true`):**  
> Współczesne buckety AWS S3 domyślnie wyłączają listy ACL (*Bucket owner enforced*). Opcja `disable_acl` jest ustawiana na `true` automatycznie wyłącznie wtedy, gdy skonfigurowany jest niestandardowy `endpoint` (R2, Supabase, MinIO itp.); **dla natywnego AWS S3 jej wartość domyślna to `false`**.  
> Aby uniknąć błędów `AccessControlListNotSupported`, dodaj jawnie **`disable_acl: true`** (oraz opcjonalnie `retain_visibility: false`) w sekcji `driver_options`, jak pokazano w poniższym przykładzie.

---

## Krok 3: Skonfiguruj CORS dla bucketa (dla Edytora obrazów)

Aby wbudowany edytor obrazów oparty na Canvas mógł przycinać i edytować obrazy hostowane na S3 bezpośrednio w przeglądarce, dodaj swoją domenę w sekcji Bucket → **Permissions** → **CORS**:

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

## Krok 4: Skonfiguruj `.env.local` oraz `liveux_file_manager.yaml`

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
            icon: 'aws_s3'            # Opcjonalnie — wykrywane automatycznie na podstawie sterownika AwsS3
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # CloudFront lub publiczny URL S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Obsługiwane są dynamiczne placeholdery ścieżek
                disable_acl: true     # Zalecane dla bucketów S3 z ustawieniem "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'Prywatne archiwum AWS'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Włączona opcja Block Public Access; strumieniowanie przez Voter + DownloadController
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

> **Rola IAM w EC2 / ECS lub tymczasowe poświadczenia AWS STS:**
> - **Rola IAM:** Jeśli używasz EC2 Instance Profile lub ECS Task Role, możesz całkowicie pominąć `key` oraz `secret` — AWS SDK automatycznie pobierze poświadczenia ze środowiska.
> - **Tymczasowe poświadczenia AWS STS:** Jeśli korzystasz z tymczasowych tokenów sesji, dodaj `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Krok 5: Zweryfikuj połączenie na żywo

```bash
php bin/console file-manager:doctor --check-remote
```

> **Uwaga:** Wolumeny powiązane z użytkownikiem, zawierające placeholdery takie jak `prefix: 'private/{user_id}'` (np. `aws_private_docs`), są bezpiecznie pomijane ze statusem `INFO` przez polecenie `file-manager:doctor --check-remote`, ponieważ w środowisku CLI brakuje sesji użytkownika.

---

[← Poprzedni: Supabase Storage](supabase-storage.md) | [07 — Indeks magazynów w chmurze](../07-cloud-storage-and-dynamic-config.md) | [Następny: Cloudflare R2 →](cloudflare-r2.md)
