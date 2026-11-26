# Guide d'intégration Cloudflare R2

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir Cloudflare R2 ?**  
> Si vous servez un important trafic de téléchargement d'images ou de vidéos et souhaitez bénéficier de **frais de trafic sortant (egress) nuls** associés à la rapidité native du CDN Cloudflare, Cloudflare R2 est l'une des solutions compatibles S3 les plus économiques pour les projets riches en médias.

LiveUX FileManagerBundle détecte automatiquement Cloudflare R2 à partir du endpoint `.r2.cloudflarestorage.com` et affiche l'icône SVG `cloudflare_r2` dans la barre latérale.

---

## Étape 1 : Installer les paquets requis

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Étape 2 : Créer un bucket et un jeton API dans le tableau de bord Cloudflare

1. Accédez au tableau de bord Cloudflare → **R2 Object Storage** → **Create bucket** et créez un bucket (par exemple `cdn-assets`).
2. Pour un accès public, connectez votre domaine personnalisé dans Bucket → **Settings** → **Public access** (par exemple `assets.your-site.com`). Pour un bucket de documents privés, laissez l'accès public désactivé.
3. Depuis la page d'aperçu R2, ouvrez **Manage R2 API Tokens** → **Create API token** :
   - **Permissions :** `Object Read & Write`
   - **Specify bucket(s) :** sélectionnez uniquement le bucket cible
   - Copiez les valeurs générées **Access Key ID**, **Secret Access Key** ainsi que le **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Optionnel — pour l'éditeur d'images)* Ajoutez une règle CORS dans Bucket → **Settings** → **CORS Policy** :
   ```json
   [
       {
           "AllowedOrigins": ["https://your-site.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## Étape 3 : Configurer `.env.local` et `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Scénario A — Bucket CDN public & Scénario B — Coffre-fort documentaire privé

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Optionnel — détecté automatiquement depuis le endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Toujours utiliser 'auto' pour Cloudflare R2
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'Archives privées R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Aucune URL publique puisque ce volume est privé
            private: true             # Streaming autorisé via Voter + DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                prefix: 'private-docs'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['cloudflare_r2_cdn', 'cloudflare_r2_private']
```

> **Compatibilité checksum et ACL d'AWS SDK v3.337+ :** Avec `aws/aws-sdk-php >= 3.337`, les nouveaux en-têtes CRC32 par défaut peuvent déclencher des erreurs `XAmzContentSHA256Mismatch` ou `NotImplemented` sur Cloudflare R2. Dès que `FlysystemStorageFactory` détecte un `endpoint` personnalisé, il **applique automatiquement** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` et `retain_visibility: false` — aucune configuration supplémentaire n'est requise.

---

## Étape 4 : Vérifier la connectivité en direct

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Précédent : AWS S3](aws-s3.md) | [07 — Index du stockage cloud](../07-cloud-storage-and-dynamic-config.md) | [Suivant : Backblaze B2 →](backblaze-b2.md)
