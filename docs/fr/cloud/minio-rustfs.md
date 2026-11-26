# Guide d'intégration MinIO & RustFS (Auto-hébergé / Docker)

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir MinIO / RustFS ?**  
> Le stockage S3 auto-hébergé est idéal pour les serveurs d'entreprise sur site (*on-premise*) soumis à des exigences strictes de souveraineté ou de conformité des données (RGPD, HIPAA, etc.), où les fichiers ne doivent jamais quitter votre infrastructure, ou pour tester gratuitement un véritable comportement S3 en local avec Docker.

---

## Étape 1 : Installer les paquets requis

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Étape 2 : Configuration Docker Compose rapide (Développement / Auto-hébergé)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # Endpoint de l'API S3
            - "9001:9001"   # Console d'administration Web
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # Origine CORS pour l'éditeur d'images Canvas intégré sur les buckets publics :
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Démarrez le conteneur puis ouvrez `http://localhost:9001` pour créer un bucket nommé `uploads` (définissez **Access Policy** sur `public` pour un accès en lecture publique, ou laissez-le sur `private` pour des archives restreintes).

---

## Étape 3 : Configurer `.env.local` et `liveux_file_manager.yaml`

```dotenv
# .env.local
MINIO_ENDPOINT=http://localhost:9000
MINIO_BUCKET=uploads
MINIO_KEY=minioadmin
MINIO_SECRET=minioadmin-change-me
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        minio_storage:
            label: 'MinIO Storage'
            icon: 'minio'             # Détecté automatiquement si le endpoint contient 'minio' ou ':9000' ; à définir explicitement pour des ports RustFS personnalisés
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Mettez null si private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Valeur par défaut standard pour MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Requis pour les serveurs S3 auto-hébergés
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # En cas d'utilisation d'un certificat HTTPS auto-signé :
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Astuce (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi) :** Vous pouvez vous connecter à n'importe quel service compatible S3 à partir de ce même modèle `AwsS3` — il vous suffit d'adapter `endpoint`, `region` et `use_path_style_endpoint`. Comme un endpoint personnalisé est défini, `FlysystemStorageFactory` applique automatiquement la validation checksum `when_required` ainsi que `disable_acl: true`.

---

## Étape 4 : Vérifier la connectivité en direct

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Précédent : Backblaze B2](backblaze-b2.md) | [07 — Index du stockage cloud](../07-cloud-storage-and-dynamic-config.md) | [Suivant : Google Cloud Storage (GCS) →](google-cloud-storage.md)
