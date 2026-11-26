# SFTP, Azure Blob & Benutzerdefinierte Flysystem-Services (`FlysystemService`)

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du diesen Ansatz wählen?**  
> Verwende `driver: 'FlysystemService'`, wann immer du ein Nicht-S3-Backend – wie einen **SFTP-/FTP-Server**, **Azure Blob Storage** oder **WebDAV** – anbinden oder ein bestehendes Flysystem-Dateisystem wiederverwenden möchtest, das bereits über `league/flysystem-bundle` in deinem Projekt konfiguriert ist.

---

## 1. Einen SFTP-Server anbinden (`league/flysystem-sftp-v3`)

### Schritt 1: SFTP-Adapter installieren

```bash
composer require league/flysystem-sftp-v3
```

### Schritt 2: Verbindungsvariablen in `.env.local` eintragen

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Schritt 3: Symfony-Service definieren (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Auf null belassen und $privateKey setzen, wenn per SSH-Schlüssel authentifiziert wird
            $privateKey: null                 # z. B. '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Hinweis: public: true ist NICHT erforderlich! FlysystemServiceLocatorPass registriert diesen Service automatisch in einem sicheren Locator.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Schritt 4: Volume in `liveux_file_manager.yaml` registrieren

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP-Archivserver'
            icon: 'sftp'              # Integriertes SFTP-SVG-Icon
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Dateien werden sicher über DownloadController + RBAC-Voter gestreamt
            cache_ttl: 30             # Kurze Cache-TTL, da sich SFTP-Inhalte extern ändern können
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Verwendest du einen dynamischen `ConfigProviderInterface`?** Wenn du die Service-ID dynamisch aus einer Datenbank zurückgibst, statt sie in YAML zu deklarieren, versehe deinen Flysystem-Service einfach mit dem Tag `tags: ['liveux_file_manager.storage']` (oder `flysystem.storage`) – auch hier ist `public: true` nicht erforderlich.

---

## 2. Bestehende `league/flysystem-bundle`-Services wiederverwenden (Azure Blob, WebDAV usw.)

Wenn dein Projekt bereits `league/flysystem-bundle` einsetzt (beispielsweise `azure.storage` oder `webdav.storage`), verweise über `driver_options.service` einfach auf die vorhandene Service-ID:

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
                service: 'azure.storage'  # Jeder Service, der League\Flysystem\FilesystemOperator implementiert
```

---

## 3. Extern gelöschte Ordner & `remember_last_dir`

Angenommen, ein Benutzer hat zuletzt im Verzeichnis `/projects/2026/draft` auf einem SFTP-Server oder in einem Cloud-Bucket gearbeitet, und jemand löscht diesen Ordner extern über einen FTP-/SFTP-Client:
- Beim Neuladen der Seite stürzt der Dateimanager nicht ab, sondern wechselt automatisch auf das nächstgelegene vorhandene übergeordnete Verzeichnis zurück (`/projects/2026` oder das Stammverzeichnis des Volumes).
- Ein Klick auf die Schaltfläche **Aktualisieren (`↻`)** in der Symbolleiste umgeht über `?refresh=1` stets den Cache und ruft eine Live-Auflistung direkt vom SFTP-Server oder Cloud-Speicher ab.

---

## 4. Live-Verbindung prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Zurück: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Cloud-Speicher-Übersicht](../07-cloud-storage-and-dynamic-config.md)
