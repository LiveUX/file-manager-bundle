# Guide d'intégration Supabase Storage (S3)

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir Supabase Storage ?**  
> Si vous disposez déjà d'un projet Supabase — ou si vous recherchez une offre gratuite généreuse pour gérer à la fois des ressources CDN publiques et des buckets de documents privés depuis un seul tableau de bord — Supabase Storage est l'option compatible S3 la plus pratique.

Parce que **Supabase Storage** prend en charge le protocole compatible S3, il fonctionne immédiatement avec LiveUX FileManagerBundle dans une architecture hybride **Stockage local + Supabase Cloud**, sans écrire une seule ligne de code personnalisé. Le bundle détecte automatiquement le fournisseur `supabase` à partir de l'URL du endpoint et affiche l'icône SVG Supabase dans la barre latérale.

---

## Étape 1 : Installer les paquets requis

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Étape 2 : Générer les clés d'accès S3 dans le tableau de bord Supabase

> [!WARNING]
> **Important :** Les clés API REST/Auth de Supabase — `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) et `SUPABASE_SECRET_KEY` (`sb_secret_...`) — ne fonctionnent **pas** avec le protocole S3. Vous devez impérativement utiliser les identifiants de 32 et 64 caractères générés dans la section **S3 Access Keys** ci-dessous.

1. Ouvrez votre projet dans le tableau de bord Supabase → **Storage** → **New bucket** et créez un bucket (par exemple `media` ou `prints`).
   - Cochez **Public bucket** pour les images et ressources CDN accessibles publiquement.
   - Laissez **Public bucket** décoché (privé) pour les documents clients ou les factures.
2. Accédez à **Project Settings** → **Storage** (ou **Storage** → **Settings**).
3. Copiez ces deux valeurs depuis la carte **S3 Connection** :
   - **Endpoint :** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region :** par exemple `eu-central-1` ou `eu-west-1`
4. Dans **S3 Access Keys**, cliquez sur **New access key**, renseignez une description et copiez les valeurs générées :
   - **Access Key ID** (32 caractères)
   - **Secret Access Key** (64 caractères — affichée une seule fois !)

---

## Étape 3 : Ajouter les variables d'environnement dans `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## Étape 4 : Configurer `liveux_file_manager.yaml`

### Scénario A — Bucket public

Les images, vidéos et fichiers publics sont servis directement via l'URL du CDN Supabase (allégeant ainsi votre serveur PHP), tandis que les miniatures sont générées une seule fois sur votre serveur et servies depuis le cache local (`public/media-cache`) :

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Stockage local'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Optionnel — détecté automatiquement depuis le endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # Cache de 60 s pour les contenus cloud susceptibles d'être modifiés en externe
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # ou 'network_first' / 'configured'
```

### Scénario B — Bucket privé avec isolation par utilisateur

Pour les factures clients ou les PDF confidentiels accessibles uniquement aux utilisateurs authentifiés et autorisés :

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Coffre-fort documentaire Supabase'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Aucune URL publique puisque ce bucket est privé
            private: true             # Streaming sécurisé via DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Isole chaque utilisateur dans son propre sous-dossier sans collision
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## Étape 5 : Vérifier la connectivité en direct en une commande

```bash
php bin/console file-manager:doctor --check-remote
```

Exemple de sortie en cas de succès :
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Remarque :** Les volumes dynamiques par utilisateur utilisant des variables de substitution telles que `prefix: 'users/{user_id}'` (comme dans le Scénario B) sont ignorés en toute sécurité avec un statut `INFO` par `file-manager:doctor --check-remote`, car aucune session utilisateur n'est active en environnement CLI.

---

## Conseils et dépannage spécifiques à Supabase

### 1. Suppression de fichiers dans un bucket restreint par type MIME (`InvalidMimeType: application/json`)
Si vous avez configuré **Allowed MIME types** sur votre bucket Supabase pour n'autoriser que des types comme `application/pdf` ou `image/*`, Supabase renverra `InvalidMimeType` lorsque la corbeille tentera d'écrire `.trash/<id>/.meta.json`.
- **Repli automatique :** `TrashManager` détecte automatiquement cette situation : le fichier lui-même est déplacé dans `.trash/<id>/...` dans le bucket cloud, tandis que les métadonnées `.meta.json` sont enregistrées dans un fichier local de repli sur votre serveur (`trash-meta.json` ou `<name>.trash-meta.json` dans le répertoire `defaults.metadata.file`). La restauration et la suppression définitive continuent de fonctionner de manière totalement transparente.

### 2. Erreurs de somme de contrôle (checksum) et d'ACL avec AWS SDK v3.337+
Pour les volumes S3 configurés avec un `endpoint` personnalisé comme Supabase, `FlysystemStorageFactory` applique automatiquement :
- `request_checksum_calculation: 'when_required'` et `response_checksum_validation: 'when_required'`
- `retain_visibility: false` et la suppression des en-têtes `x-amz-acl` (`disable_acl: true`)

Aucune configuration supplémentaire n'est nécessaire.

### 3. Erreur de certificat SSL `cURL error 60` sous Windows / WAMP
Si vous rencontrez l'erreur `cURL error 60: unable to get local issuer certificate` sur votre poste de développement local :
- Téléchargez [cacert.pem](https://curl.se/ca/cacert.pem) et référencez-le dans `php.ini` (dans les deux fichiers `php.ini` d'Apache et de la CLI) :
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Vous pouvez également le configurer uniquement pour un volume précis via `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. Des fichiers supprimés dans le tableau de bord Supabase apparaissent toujours dans la liste
- Cliquez sur le bouton **Actualiser (`↻`)** dans la barre d'outils (ou appuyez sur `R`) pour contourner immédiatement le cache via `?refresh=1`.
- Si les fichiers du bucket sont fréquemment modifiés en externe, définissez `cache_ttl: 30` (ou `cache_ttl: 0` pour désactiver le cache) sur le volume.

---

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Suivant : Guide AWS S3 →](aws-s3.md)
