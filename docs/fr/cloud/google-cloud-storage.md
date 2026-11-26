# Guide d'intégration Google Cloud Storage (GCS)

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir Google Cloud Storage ?**  
> GCS s'impose naturellement si votre application s'exécute déjà sur Google Cloud (GKE, Cloud Run, Compute Engine) et que vous souhaitez vous y connecter directement via sa couche d'interopérabilité S3 (HMAC) sans installer de SDK fournisseur supplémentaire.

**Google Cloud Storage (GCS)** fonctionne directement avec le pilote `AwsS3` grâce à sa couche de compatibilité S3 intégrée (*XML API / Interoperability HMAC*) — bien que vous puissiez également utiliser `league/flysystem-google-cloud-storage` via `FlysystemService` si vous le préférez. Le endpoint `storage.googleapis.com` est détecté automatiquement et l'icône SVG `gcs` s'affiche dans la barre latérale.

---

## Étape 1 : Installer les paquets requis

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Étape 2 : Créer une clé HMAC dans la console Google Cloud

1. Ouvrez Google Cloud Console → **Cloud Storage** → **Buckets** et créez un nouveau bucket (par exemple `my-symfony-media`).
2. Accédez à **Cloud Storage** → **Settings** → **Interoperability**.
3. Dans **Service account HMAC**, cliquez sur **Create a key for a service account** (assurez-vous que le compte de service dispose du rôle `Storage Object Admin`).
4. Copiez la clé **Access key** (`GOOG1E...`) et le **Secret** générés.
5. *(Optionnel — pour l'éditeur d'images)* Appliquez une politique CORS au bucket à l'aide d'un fichier `cors.json` :
   ```json
   [
       {
           "origin": ["https://your-site.com"],
           "method": ["GET", "HEAD"],
           "responseHeader": ["Content-Type", "ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```
   ```bash
   gcloud storage buckets update gs://my-symfony-media --cors-file=cors.json
   ```

---

## Étape 3 : Configurer `.env.local` et `liveux_file_manager.yaml`

```dotenv
# .env.local
GCS_BUCKET=my-symfony-media
GCS_HMAC_KEY=GOOG1E...
GCS_HMAC_SECRET=...
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        gcs_media:
            label: 'Google Cloud Storage'
            icon: 'gcs'               # Optionnel — détecté automatiquement depuis le endpoint
            group: 'network'
            driver: 'AwsS3'
            # Pour les buckets publics : https://storage.googleapis.com/<bucket> (mettez null s'il est privé)
            url_prefix: 'https://storage.googleapis.com/%env(GCS_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(GCS_BUCKET)%'
                region: 'auto'
                endpoint: 'https://storage.googleapis.com'
                use_path_style_endpoint: true
                key: '%env(GCS_HMAC_KEY)%'
                secret: '%env(GCS_HMAC_SECRET)%'

    profiles:
        default:
            volumes: ['gcs_media']
```

> **Compatibilité automatique avec GCS :** Comme `endpoint: 'https://storage.googleapis.com'` est configuré, `FlysystemStorageFactory` applique automatiquement `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` et `retain_visibility: false`.

---

## Étape 4 : Vérifier la connectivité en direct

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Précédent : MinIO / RustFS](minio-rustfs.md) | [07 — Index du stockage cloud](../07-cloud-storage-and-dynamic-config.md) | [Suivant : SFTP et services Flysystem personnalisés →](sftp-and-custom-flysystem.md)
