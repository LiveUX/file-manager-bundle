# SFTP, Azure Blob i niestandardowe usługi Flysystem (`FlysystemService`)

[← 07 — Magazyn w chmurze i dynamiczna konfiguracja](../07-cloud-storage-and-dynamic-config.md) | [Spis treści](../README.md)

> **Kiedy warto wybrać to podejście?**  
> Użyj `driver: 'FlysystemService'` zawsze wtedy, gdy potrzebujesz połączyć się z backendem innym niż S3 — takim jak **serwer SFTP/FTP**, **Azure Blob Storage** lub **WebDAV** — albo ponownie wykorzystać istniejący system plików Flysystem skonfigurowany już w Twoim projekcie za pomocą `league/flysystem-bundle`.

---

## 1. Podłączanie serwera SFTP (`league/flysystem-sftp-v3`)

### Krok 1: Zainstaluj adapter SFTP

```bash
composer require league/flysystem-sftp-v3
```

### Krok 2: Dodaj zmienne połączenia do `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Krok 3: Zdefiniuj usługę Symfony (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Pozostaw null i ustaw $privateKey przy uwierzytelnianiu kluczem SSH
            $privateKey: null                 # np. '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Uwaga: public: true NIE jest wymagane! FlysystemServiceLocatorPass automatycznie rejestruje tę usługę w bezpiecznym lokatorze.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Krok 4: Zarejestruj wolumen w `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Serwer archiwum SFTP'
            icon: 'sftp'              # Wbudowana ikona SVG SFTP
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Pliki są bezpiecznie strumieniowane przez DownloadController + RBAC Voter
            cache_ttl: 30             # Krótki TTL pamięci podręcznej, ponieważ zawartość SFTP może zmieniać się z zewnątrz
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Korzystasz z dynamicznego `ConfigProviderInterface`?** Jeśli zwracasz identyfikator usługi dynamicznie z bazy danych zamiast deklarować go w YAML, po prostu otaguj swoją usługę Flysystem za pomocą `tags: ['liveux_file_manager.storage']` (lub `flysystem.storage`) — tutaj również `public: true` nie jest wymagane.

---

## 2. Ponowne wykorzystanie istniejących usług `league/flysystem-bundle` (Azure Blob, WebDAV itp.)

Jeśli Twój projekt korzysta już z `league/flysystem-bundle` (na przykład z `azure.storage` lub `webdav.storage`), wystarczy wskazać istniejący identyfikator usługi w `driver_options.service`:

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
                service: 'azure.storage'  # Dowolna usługa implementująca League\Flysystem\FilesystemOperator
```

---

## 3. Katalogi usunięte z zewnątrz a opcja `remember_last_dir`

Załóżmy, że użytkownik pracował ostatnio w katalogu `/projects/2026/draft` na serwerze SFTP lub w buckecie chmurowym, a ktoś usunął ten katalog z zewnątrz przez klienta FTP/SFTP:
- Po ponownym załadowaniu strony menedżer plików nie zgłasza błędu — automatycznie wraca do najbliższego istniejącego katalogu nadrzędnego (`/projects/2026` lub katalogu głównego wolumenu).
- Kliknięcie przycisku **Odśwież (`↻`)** na pasku narzędzi zawsze omija pamięć podręczną za pomocą `?refresh=1`, aby pobrać aktualną listę plików bezpośrednio z SFTP lub magazynu w chmurze.

---

## 4. Zweryfikuj połączenie na żywo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Poprzedni: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Indeks magazynów w chmurze](../07-cloud-storage-and-dynamic-config.md)
