# Guia de Integração com Amazon AWS S3

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher o Amazon AWS S3?**  
> O AWS S3 é o padrão de mercado quando sua aplicação já roda na AWS (EC2/ECS/CloudFront) e você precisa de autenticação sem chaves via IAM Role aliada a alta durabilidade global (99,999999999%).

O LiveUX FileManagerBundle conecta-se diretamente aos seus buckets AWS S3 usando o driver `AwsS3` e exibe automaticamente o ícone SVG `aws_s3` na barra lateral.

---

## Passo 1: Instalar os Pacotes Necessários

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passo 2: Criar uma Política IAM de Privilégio Mínimo

Crie um usuário IAM dedicado (ou uma Role de EC2/ECS) no AWS IAM para esta aplicação e anexe a política abaixo, restrita estritamente ao seu bucket:

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
> **Configuração de Object Ownership / ACL (`disable_acl: true`):**  
> Buckets modernos do AWS S3 desativam ACLs por padrão (*Bucket owner enforced*). A opção `disable_acl` só é definida automaticamente como `true` quando há um `endpoint` personalizado configurado (R2, Supabase, MinIO etc.); **para o AWS S3 nativo, o padrão é `false`**.  
> Para evitar erros de `AccessControlListNotSupported`, adicione explicitamente **`disable_acl: true`** (e opcionalmente `retain_visibility: false`) em `driver_options`, conforme mostrado no exemplo abaixo.

---

## Passo 3: Configurar CORS do Bucket (Para o Editor de Imagens)

Para permitir que o Editor de Imagens em Canvas integrado recorte e edite imagens hospedadas no S3 diretamente no navegador, adicione seu domínio em Bucket → **Permissions** → **CORS**:

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

## Passo 4: Configurar `.env.local` e `liveux_file_manager.yaml`

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
            icon: 'aws_s3'            # Opcional — detectado automaticamente pelo driver AwsS3
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # URL pública do CloudFront ou S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Placeholders dinâmicos de caminho são suportados
                disable_acl: true     # Recomendado para buckets S3 "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'Arquivo Privado AWS'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access ativado; streaming via Voter + DownloadController
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

> **IAM Role de EC2 / ECS ou Credenciais Temporárias do AWS STS:**
> - **IAM Role:** Se você usa um EC2 Instance Profile ou ECS Task Role, pode omitir completamente `key` e `secret` — o AWS SDK resolve as credenciais automaticamente a partir do ambiente.
> - **Credenciais Temporárias do AWS STS:** Se você usa tokens de sessão temporários, adicione `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Passo 5: Verificar a Conectividade em Tempo Real

```bash
php bin/console file-manager:doctor --check-remote
```

> **Nota:** Volumes com escopo por usuário contendo placeholders como `prefix: 'private/{user_id}'` (como `aws_private_docs`) são ignorados com segurança com status `INFO` pelo comando `file-manager:doctor --check-remote`, pois não existe sessão de usuário no ambiente CLI.

---

[← Anterior: Supabase Storage](supabase-storage.md) | [07 — Índice de Armazenamento em Nuvem](../07-cloud-storage-and-dynamic-config.md) | [Próximo: Cloudflare R2 →](cloudflare-r2.md)
