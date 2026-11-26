# SFTP, Azure Blob et services Flysystem personnalisés (`FlysystemService`)

[← 07 — Stockage cloud et configuration dynamique](../07-cloud-storage-and-dynamic-config.md) | [Sommaire principal](../README.md)

> **Quand choisir cette approche ?**  
> Utilisez `driver: 'FlysystemService'` dès que vous devez vous connecter à un backend non-S3 — comme un **serveur SFTP/FTP**, **Azure Blob Storage** ou **WebDAV** — ou réutiliser un système de fichiers Flysystem déjà configuré dans votre projet via `league/flysystem-bundle`.

---

## 1. Connecter un serveur SFTP (`league/flysystem-sftp-v3`)

### Étape 1 : Installer l'adaptateur SFTP

```bash
composer require league/flysystem-sftp-v3
```

### Étape 2 : Ajouter les variables de connexion dans `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Étape 3 : Définir le service Symfony (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Laissez null et renseignez $privateKey pour une authentification par clé SSH
            $privateKey: null                 # ex. : '%kernel.project_dir%/config/ssh/id_ed25519'
            $passphrase: null
            $port: '%env(int:SFTP_PORT)%'
            $useAgent: false
            $timeout: 10
            $maxTries: 3

    app.sftp.adapter:
        class: League\Flysystem\PhpseclibV3\SftpAdapter
        arguments:
            $connectionProvider: '@app.sftp.connection_provider'
            $root: '%env(SFTP_ROOT)%'

    app.sftp.storage:
        class: League\Flysystem\Filesystem
        # Remarque : public: true N'EST PAS requis ! FlysystemServiceLocatorPass enregistre automatiquement ce service dans un locator sécurisé.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Étape 4 : Enregistrer le volume dans `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Serveur d''archives SFTP'
            icon: 'sftp'              # Icône SVG SFTP intégrée
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Fichiers servis en streaming sécurisé via DownloadController + RBAC Voter
            cache_ttl: 30             # Durée de cache courte car le contenu SFTP peut être modifié en externe
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Vous utilisez un `ConfigProviderInterface` dynamique ?** Si vous retournez l'identifiant du service dynamiquement depuis une base de données au lieu de le déclarer en YAML, il vous suffit de tagger votre service Flysystem avec `tags: ['liveux_file_manager.storage']` (ou `flysystem.storage`) — là encore, `public: true` n'est pas nécessaire.

---

## 2. Réutiliser des services `league/flysystem-bundle` existants (Azure Blob, WebDAV, etc.)

Si votre projet utilise déjà `league/flysystem-bundle` (par exemple `azure.storage` ou `webdav.storage`), faites simplement pointer `driver_options.service` vers l'identifiant du service existant :

```yaml
liveux_file_manager:
    volumes:
        azure_blobs:
            label: 'Azure Blob Storage'
            icon: 'cloud'
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: 'https://myaccount.blob.core.windows.net/media'
            private: false
            cache_ttl: 120
            driver_options:
                service: 'azure.storage'  # Tout service implémentant League\Flysystem\FilesystemOperator
```

---

## 3. Dossiers supprimés en externe et `remember_last_dir`

Supposons qu'un utilisateur travaillait récemment dans `/projects/2026/draft` sur un serveur SFTP ou un bucket cloud, et que quelqu'un supprime ce dossier en externe via un client FTP/SFTP :
- Lorsque l'utilisateur recharge la page, le gestionnaire de fichiers ne plante pas : il revient automatiquement au répertoire parent existant le plus proche (`/projects/2026`, ou la racine du volume).
- Cliquer sur le bouton **Actualiser (`↻`)** dans la barre d'outils contourne toujours le cache via `?refresh=1` afin de récupérer une liste à jour directement depuis le serveur SFTP ou le stockage cloud.

---

## 4. Vérifier la connectivité en direct

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Précédent : Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Index du stockage cloud](../07-cloud-storage-and-dynamic-config.md)
