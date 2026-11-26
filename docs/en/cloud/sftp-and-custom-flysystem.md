# SFTP, Azure Blob & Custom Flysystem Services (`FlysystemService`)

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose This Approach?**  
> Use `driver: 'FlysystemService'` whenever you need to connect to a non-S3 backend—such as an **SFTP/FTP server**, **Azure Blob Storage**, or **WebDAV**—or reuse an existing Flysystem filesystem already configured in your project via `league/flysystem-bundle`.

---

## 1. Connecting an SFTP Server (`league/flysystem-sftp-v3`)

### Step 1: Install the SFTP Adapter

```bash
composer require league/flysystem-sftp-v3
```

### Step 2: Add Connection Variables to `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Step 3: Define the Symfony Service (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Leave null and set $privateKey if authenticating with an SSH key
            $privateKey: null                 # e.g., '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Note: public: true is NOT required! FlysystemServiceLocatorPass registers this service in a secure locator automatically.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Step 4: Register the Volume in `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP Archive Server'
            icon: 'sftp'              # Built-in SFTP SVG icon
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Files stream securely via DownloadController + RBAC Voter
            cache_ttl: 30             # Short cache TTL since SFTP contents may change externally
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Using a Dynamic `ConfigProviderInterface`?** If you return the service ID dynamically from a database instead of declaring it in YAML, simply tag your Flysystem service with `tags: ['liveux_file_manager.storage']` (or `flysystem.storage`)—again, `public: true` is not required.

---

## 2. Reusing Existing `league/flysystem-bundle` Services (Azure Blob, WebDAV, etc.)

If your project already uses `league/flysystem-bundle` (for example, `azure.storage` or `webdav.storage`), simply point `driver_options.service` to the existing service ID:

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
                service: 'azure.storage'  # Any service implementing League\Flysystem\FilesystemOperator
```

---

## 3. Externally Deleted Folders & `remember_last_dir`

Suppose a user was last working inside `/projects/2026/draft` on an SFTP server or cloud bucket, and someone deletes that folder externally via an FTP/SFTP client:
- When the user reloads the page, the file manager does not crash—it automatically falls back to the nearest existing parent directory (`/projects/2026`, or the volume root).
- Clicking the **Refresh (`↻`)** button in the toolbar always bypasses the cache via `?refresh=1` to fetch a live listing directly from SFTP or cloud storage.

---

## 4. Verify Live Connectivity

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Previous: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Cloud Storage Index](../07-cloud-storage-and-dynamic-config.md)
