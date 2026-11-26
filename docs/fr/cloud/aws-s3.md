# Guide d'intégration Amazon AWS S3

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir Amazon AWS S3 ?**  
> AWS S3 constitue le choix de référence lorsque votre application s'exécute déjà sur AWS (EC2/ECS/CloudFront) et que vous avez besoin d'une authentification sans clé via IAM Role ainsi que d'une haute durabilité mondiale (99,999999999 %).

LiveUX FileManagerBundle se connecte directement à vos buckets AWS S3 grâce au pilote `AwsS3` et affiche automatiquement l'icône SVG `aws_s3` dans la barre latérale.

---

## Étape 1 : Installer les paquets requis

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Étape 2 : Créer une politique IAM à moindre privilège

Créez un utilisateur IAM dédié (ou un rôle EC2/ECS) dans AWS IAM pour cette application et associez-lui la politique suivante, strictement limitée à votre bucket :

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
> **Propriété des objets / Réglage ACL (`disable_acl: true`) :**  
> Les buckets AWS S3 récents désactivent les ACL par défaut (*Bucket owner enforced*). L'option `disable_acl` n'est activée automatiquement à `true` que lorsqu'un `endpoint` personnalisé (R2, Supabase, MinIO, etc.) est configuré ; **pour AWS S3 natif, sa valeur par défaut est `false`**.  
> Pour éviter les erreurs `AccessControlListNotSupported`, ajoutez explicitement **`disable_acl: true`** (et éventuellement `retain_visibility: false`) dans `driver_options`, comme illustré dans l'exemple ci-dessous.

---

## Étape 3 : Configurer le CORS du bucket (pour l'éditeur d'images)

Pour permettre à l'éditeur d'images Canvas intégré de recadrer et modifier directement dans le navigateur les images hébergées sur S3, ajoutez votre domaine dans Bucket → **Permissions** → **CORS** :

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

## Étape 4 : Configurer `.env.local` et `liveux_file_manager.yaml`

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
            icon: 'aws_s3'            # Optionnel — détecté automatiquement depuis le pilote AwsS3
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # URL publique CloudFront ou S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Les variables dynamiques de chemin sont prises en charge
                disable_acl: true     # Recommandé pour les buckets S3 « Bucket owner enforced »
                retain_visibility: false

        aws_private_docs:
            label: 'Archives privées AWS'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access activé ; fichiers servis via Voter + DownloadController
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

> **IAM Role EC2 / ECS ou identifiants temporaires AWS STS :**
> - **IAM Role :** Si vous utilisez un EC2 Instance Profile ou un ECS Task Role, vous pouvez omettre complètement `key` et `secret` — le SDK AWS résout automatiquement les identifiants depuis l'environnement.
> - **Identifiants temporaires AWS STS :** Si vous utilisez des jetons de session temporaires, ajoutez `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Étape 5 : Vérifier la connectivité en direct

```bash
php bin/console file-manager:doctor --check-remote
```

> **Remarque :** Les volumes cloisonnés par utilisateur contenant des variables de substitution comme `prefix: 'private/{user_id}'` (tels que `aws_private_docs`) sont ignorés en toute sécurité avec un statut `INFO` par `file-manager:doctor --check-remote`, car aucune session utilisateur n'est active en environnement CLI.

---

[← Précédent : Supabase Storage](supabase-storage.md) | [07 — Index du stockage cloud](../07-cloud-storage-and-dynamic-config.md) | [Suivant : Cloudflare R2 →](cloudflare-r2.md)
