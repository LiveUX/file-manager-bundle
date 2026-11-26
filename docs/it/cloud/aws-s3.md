# Guida all'integrazione con Amazon AWS S3

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere Amazon AWS S3?**  
> AWS S3 è lo standard di riferimento quando la tua applicazione gira già su AWS (EC2/ECS/CloudFront) e hai bisogno di autenticazione senza chiavi tramite IAM Role e di un'elevatissima durabilità globale (99,999999999%).

LiveUX FileManagerBundle si collega direttamente ai tuoi bucket AWS S3 utilizzando il driver `AwsS3` e mostra automaticamente l'icona SVG `aws_s3` nella sidebar.

---

## Passaggio 1: Installare i pacchetti richiesti

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passaggio 2: Creare una policy IAM con principio del privilegio minimo

Crea un utente IAM dedicato (o un ruolo EC2/ECS) in AWS IAM per questa applicazione e associa la seguente policy limitata esclusivamente al tuo bucket:

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
> **Impostazione Object Ownership / ACL (`disable_acl: true`):**  
> I bucket AWS S3 moderni disabilitano le ACL per impostazione predefinita (*Bucket owner enforced*). L'opzione `disable_acl` viene impostata automaticamente a `true` solo quando è configurato un `endpoint` personalizzato (R2, Supabase, MinIO, ecc.); **per AWS S3 nativo, il valore predefinito è `false`**.  
> Per evitare errori `AccessControlListNotSupported`, aggiungi esplicitamente **`disable_acl: true`** (e facoltativamente `retain_visibility: false`) sotto `driver_options`, come mostrato nell'esempio seguente.

---

## Passaggio 3: Configurare CORS sul bucket (per l'Editor Immagini)

Per consentire al Canvas Image Editor integrato di ritagliare e modificare direttamente nel browser le immagini ospitate su S3, aggiungi il tuo dominio in Bucket → **Permissions** → **CORS**:

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

## Passaggio 4: Configurare `.env.local` e `liveux_file_manager.yaml`

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
            icon: 'aws_s3'            # Opzionale — rilevata automaticamente dal driver AwsS3
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # URL pubblico CloudFront o S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # I segnaposto dinamici nei percorsi sono supportati
                disable_acl: true     # Consigliato per bucket S3 con "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'Archivio privato AWS'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access attivo; i file vengono serviti in streaming tramite Voter + DownloadController
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

> **IAM Role EC2 / ECS o credenziali temporanee AWS STS:**
> - **IAM Role:** Se utilizzi un EC2 Instance Profile o un ECS Task Role, puoi omettere completamente `key` e `secret`: l'AWS SDK risolve automaticamente le credenziali dall'ambiente.
> - **Credenziali temporanee AWS STS:** Se utilizzi token di sessione temporanei, aggiungi `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Passaggio 5: Verificare la connettività live

```bash
php bin/console file-manager:doctor --check-remote
```

> **Nota:** I volumi con ambito utente che contengono segnaposto come `prefix: 'private/{user_id}'` (come `aws_private_docs`) vengono saltati in sicurezza con stato `INFO` da `file-manager:doctor --check-remote`, poiché nell'ambiente CLI non è presente alcuna sessione utente.

---

[← Precedente: Supabase Storage](supabase-storage.md) | [07 — Indice Cloud Storage](../07-cloud-storage-and-dynamic-config.md) | [Successivo: Cloudflare R2 →](cloudflare-r2.md)
