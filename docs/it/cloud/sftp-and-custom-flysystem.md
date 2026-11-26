# SFTP, Azure Blob e servizi Flysystem personalizzati (`FlysystemService`)

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere questo approccio?**  
> Usa `driver: 'FlysystemService'` ogni volta che devi collegarti a un backend non S3 — come un **server SFTP/FTP**, **Azure Blob Storage** o **WebDAV** — oppure riutilizzare un filesystem Flysystem già configurato nel progetto tramite `league/flysystem-bundle`.

---

## 1. Collegare un server SFTP (`league/flysystem-sftp-v3`)

### Passaggio 1: Installare l'adapter SFTP

```bash
composer require league/flysystem-sftp-v3
```

### Passaggio 2: Aggiungere le variabili di connessione in `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Passaggio 3: Definire il servizio Symfony (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Lascia null e imposta $privateKey se ti autentichi con una chiave SSH
            $privateKey: null                 # es. '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Nota: public: true NON è richiesto! FlysystemServiceLocatorPass registra automaticamente questo servizio in un locator sicuro.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Passaggio 4: Registrare il volume in `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Server archivio SFTP'
            icon: 'sftp'              # Icona SVG SFTP integrata
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # I file vengono serviti in streaming sicuro tramite DownloadController + Voter RBAC
            cache_ttl: 30             # TTL cache breve perché i contenuti SFTP possono cambiare dall'esterno
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Usi un `ConfigProviderInterface` dinamico?** Se restituisci dinamicamente l'ID del servizio da database invece di dichiararlo in YAML, applica semplicemente al servizio Flysystem il tag `tags: ['liveux_file_manager.storage']` (oppure `flysystem.storage`): anche in questo caso `public: true` non è necessario.

---

## 2. Riutilizzare servizi `league/flysystem-bundle` esistenti (Azure Blob, WebDAV, ecc.)

Se il tuo progetto utilizza già `league/flysystem-bundle` (ad esempio `azure.storage` o `webdav.storage`), punta semplicemente `driver_options.service` all'ID del servizio esistente:

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
                service: 'azure.storage'  # Qualsiasi servizio che implementa League\Flysystem\FilesystemOperator
```

---

## 3. Cartelle eliminate dall'esterno e `remember_last_dir`

Supponiamo che un utente stesse lavorando in `/projects/2026/draft` su un server SFTP o un bucket cloud e che qualcuno elimini quella cartella dall'esterno tramite un client FTP/SFTP:
- Quando l'utente ricarica la pagina, il file manager non va in errore: ripiega automaticamente sulla directory padre esistente più vicina (`/projects/2026`, oppure la root del volume).
- Facendo clic sul pulsante **Aggiorna (`↻`)** nella toolbar, la cache viene sempre bypassata tramite `?refresh=1` per recuperare un elenco aggiornato in tempo reale da SFTP o cloud storage.

---

## 4. Verificare la connettività live

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Precedente: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Indice Cloud Storage](../07-cloud-storage-and-dynamic-config.md)
