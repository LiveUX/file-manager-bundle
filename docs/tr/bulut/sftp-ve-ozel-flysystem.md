# SFTP, Azure Blob & Özel Flysystem Servisleri (`FlysystemService`)

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **Bu Yöntemi Ne Zaman Seçmelisiniz?**  
> S3 protokolü dışında bir **SFTP/FTP sunucusuna**, **Azure Blob Storage**'a, **WebDAV**'a veya projenizde `league/flysystem-bundle` ile zaten tanımlı mevcut bir Flysystem diskine bağlanmak istiyorsanız `driver: 'FlysystemService'` kullanın.

---

## 1. SFTP Sunucusu Bağlama (`league/flysystem-sftp-v3`)

### Adım 1: SFTP Adaptörünü Kurun

```bash
composer require league/flysystem-sftp-v3
```

### Adım 2: `.env.local` Bilgilerini Tanımlayın

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=gizli-sifre
SFTP_ROOT=/var/www/shared-files
```

### Adım 3: Symfony Servisini Tanımlayın (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # SSH key kullanıyorsanız null bırakıp $privateKey verin
            $privateKey: null                 # Örn: '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Not: public: true GEREKMEZ! FlysystemServiceLocatorPass bu servisi otomatik güvenli locator'a ekler.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Adım 4: `liveux_file_manager.yaml` İçine Volume Olarak Ekleyin

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP Arşiv Sunucusu'
            icon: 'sftp'              # Yerleşik SFTP SVG ikonu
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Dosyalar DownloadController + RBAC Voter üzerinden akar
            cache_ttl: 30             # SFTP dışarıdan değişebildiği için kısa cache süresi
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Dinamik `ConfigProviderInterface` Kullanıyorsanız:** Servis ID'sini YAML'da yazmak yerine veritabanından dinamik döndürüyorsanız, ilgili Flysystem servisine `tags: ['liveux_file_manager.storage']` (veya `flysystem.storage`) etiketini eklemeniz yeterlidir — yine `public: true` gerekmez.

---

## 2. Mevcut `league/flysystem-bundle` Servislerini Kullanma (Azure Blob, WebDAV vb.)

Projenizde zaten `league/flysystem-bundle` kuruluysa (ör. `azure.storage` veya `webdav.storage`), tanımlı servis ID'sini doğrudan `driver_options.service` alanına yazmanız yeterlidir:

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
                service: 'azure.storage'  # League\Flysystem\FilesystemOperator implement eden herhangi bir servis
```

---

## 3. Dışarıdan Silinen Klasörler & `remember_last_dir`

SFTP veya harici bulut depolamada kullanıcı en son `/projeler/2026/taslak` klasöründe çalıştıysa ve siz bu klasörü FTP/SFTP istemcisinden dışarıdan sildiyseniz:
- Kullanıcı sayfayı tekrar açtığında dosya yöneticisi hata vermez; otomatik olarak en yakın mevcut üst klasöre (`/projeler/2026` veya kök dizine) düşer.
- Araç çubuğundaki **Yenile (`↻`)** butonu her zaman `?refresh=1` ile önbelleği atlayıp canlı SFTP/bulut listesini çeker.

---

## 4. Canlı Doğrulama

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Önceki: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Depolama İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md)
