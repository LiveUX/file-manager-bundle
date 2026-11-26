# SFTP, Azure Blob & Aangepaste Flysystem-services (`FlysystemService`)

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor deze aanpak?**  
> Gebruik `driver: 'FlysystemService'` zodra je wilt verbinden met een niet-S3-backend — zoals een **SFTP/FTP-server**, **Azure Blob Storage** of **WebDAV** — of wanneer je een bestaand Flysystem-bestandssysteem wilt hergebruiken dat al via `league/flysystem-bundle` in je project is geconfigureerd.

---

## 1. Een SFTP-server koppelen (`league/flysystem-sftp-v3`)

### Stap 1: De SFTP-adapter installeren

```bash
composer require league/flysystem-sftp-v3
```

### Stap 2: Verbindingsvariabelen toevoegen aan `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Stap 3: De Symfony-service definiëren (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Laat op null staan en stel $privateKey in als je met een SSH-sleutel authenticeert
            $privateKey: null                 # bijv. '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Let op: public: true is NIET nodig! FlysystemServiceLocatorPass registreert deze service automatisch in een veilige locator.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Stap 4: Het volume registreren in `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP-archiefserver'
            icon: 'sftp'              # Ingebouwd SFTP SVG-icoon
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Bestanden streamen veilig via DownloadController + RBAC Voter
            cache_ttl: 30             # Korte cache-TTL omdat SFTP-inhoud extern kan wijzigen
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Gebruik je een dynamische `ConfigProviderInterface`?** Als je de service-ID dynamisch uit een database ophaalt in plaats van deze in YAML te declareren, tag je Flysystem-service dan eenvoudig met `tags: ['liveux_file_manager.storage']` (of `flysystem.storage`) — ook hierbij is `public: true` niet vereist.

---

## 2. Bestaande `league/flysystem-bundle`-services hergebruiken (Azure Blob, WebDAV, enz.)

Gebruikt je project al `league/flysystem-bundle` (bijvoorbeeld `azure.storage` of `webdav.storage`)? Verwijs `driver_options.service` dan simpelweg naar de bestaande service-ID:

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
                service: 'azure.storage'  # Elke service die League\Flysystem\FilesystemOperator implementeert
```

---

## 3. Extern verwijderde mappen & `remember_last_dir`

Stel dat een gebruiker het laatst werkte in `/projects/2026/draft` op een SFTP-server of cloudbucket, en iemand die map extern verwijdert via een FTP/SFTP-client:
- Wanneer de gebruiker de pagina herlaadt, crasht de bestandsbeheerder niet — er wordt automatisch teruggevallen op de dichtstbijzijnde bestaande bovenliggende map (`/projects/2026`, of de root van het volume).
- Een klik op de knop **Vernieuwen (`↻`)** in de werkbalk omzeilt de cache altijd via `?refresh=1`, zodat er rechtstreeks een actuele lijst van SFTP of cloudopslag wordt opgehaald.

---

## 4. Live-verbinding controleren

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Vorige: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Overzicht Cloudopslag](../07-cloud-storage-and-dynamic-config.md)
