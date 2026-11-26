# Guide d'intégration Backblaze B2

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir Backblaze B2 ?**  
> Backblaze B2 est idéal lorsque vous devez stocker des centaines de gigaoctets de vidéos, de PDF prêts pour l'impression ou d'archives haute résolution au coût de stockage le plus bas possible (en particulier associé au trafic sortant gratuit via les partenaires de la Bandwidth Alliance comme Cloudflare ou Bunny CDN).

Le endpoint `.backblazeb2.com` est détecté automatiquement et l'icône SVG `backblaze_b2` s'affiche dans la barre latérale.

---

## Étape 1 : Installer les paquets requis

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Étape 2 : Configurer le bucket, l'Application Key et le CORS dans Backblaze

1. Accédez à Backblaze B2 → **Buckets** → **Create a Bucket** (par exemple `app-media`).
   - Une fois le bucket créé, notez le **Endpoint** indiqué sur la carte du bucket (par exemple `s3.eu-central-003.backblazeb2.com`).
   - La **Region** correspond au segment central du endpoint : `eu-central-003`.
2. Rendez-vous dans **Application Keys** → **Add a New Application Key** :
   - **Allow access to Bucket(s) :** sélectionnez uniquement le bucket que vous venez de créer
   - **Type of Access :** `Read and Write`
   - Copiez les valeurs **`keyID`** et **`applicationKey`** générées.
3. *(Optionnel — pour l'éditeur d'images)* Dans Bucket → **CORS Rules**, choisissez soit **Share everything in this bucket with every origin**, soit ajoutez une règle CORS S3 personnalisée pour votre domaine :
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

## Étape 3 : Configurer `.env.local` et `liveux_file_manager.yaml`

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
            icon: 'backblaze_b2'      # Optionnel — détecté automatiquement depuis le endpoint
            group: 'network'
            driver: 'AwsS3'
            # Pour les buckets publics, utilisez la Friendly URL ou votre domaine CDN (mettez null s'il est privé) :
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

> **Remarque :** Comme un endpoint personnalisé est configuré, `FlysystemStorageFactory` active automatiquement la compatibilité checksum d'AWS SDK v3.337+ (`when_required`), `disable_acl: true` et `retain_visibility: false` pour Backblaze B2.

---

## Étape 4 : Vérifier la connectivité en direct

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Précédent : Cloudflare R2](cloudflare-r2.md) | [07 — Index du stockage cloud](../07-cloud-storage-and-dynamic-config.md) | [Suivant : MinIO / RustFS →](minio-rustfs.md)
