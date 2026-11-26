# SFTP, Azure Blob y servicios Flysystem personalizados (`FlysystemService`)

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir este enfoque?**  
> Utiliza `driver: 'FlysystemService'` siempre que necesites conectarte a un backend que no sea S3 —como un **servidor SFTP/FTP**, **Azure Blob Storage** o **WebDAV**— o reutilizar un sistema de archivos Flysystem ya configurado en tu proyecto mediante `league/flysystem-bundle`.

---

## 1. Conectar un servidor SFTP (`league/flysystem-sftp-v3`)

### Paso 1: Instalar el adaptador SFTP

```bash
composer require league/flysystem-sftp-v3
```

### Paso 2: Añadir las variables de conexión a `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Paso 3: Definir el servicio de Symfony (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Déjalo en null y configura $privateKey si te autenticas con una clave SSH
            $privateKey: null                 # Por ejemplo, '%kernel.project_dir%/config/ssh/id_ed25519'
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
        # Nota: ¡NO es necesario public: true! FlysystemServiceLocatorPass registra automáticamente este servicio en un locator seguro.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Paso 4: Registrar el volumen en `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Servidor de archivos SFTP'
            icon: 'sftp'              # Icono SVG de SFTP integrado
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Los archivos se transmiten de forma segura mediante DownloadController + Voter RBAC
            cache_ttl: 30             # TTL de caché corto, ya que el contenido del servidor SFTP puede cambiar externamente
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **¿Utilizas un `ConfigProviderInterface` dinámico?** Si devuelves el ID del servicio dinámicamente desde una base de datos en lugar de declararlo en YAML, basta con etiquetar tu servicio de Flysystem con `tags: ['liveux_file_manager.storage']` (o `flysystem.storage`); de nuevo, no es necesario `public: true`.

---

## 2. Reutilizar servicios existentes de `league/flysystem-bundle` (Azure Blob, WebDAV, etc.)

Si tu proyecto ya utiliza `league/flysystem-bundle` (por ejemplo, `azure.storage` o `webdav.storage`), simplemente apunta `driver_options.service` al ID del servicio existente:

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
                service: 'azure.storage'  # Cualquier servicio que implemente League\Flysystem\FilesystemOperator
```

---

## 3. Carpetas eliminadas externamente y `remember_last_dir`

Supongamos que un usuario estaba trabajando en `/projects/2026/draft` dentro de un servidor SFTP o un bucket en la nube, y alguien elimina esa carpeta externamente desde un cliente FTP/SFTP:
- Cuando el usuario recargue la página, el gestor de archivos no fallará: retrocederá automáticamente al directorio padre existente más cercano (`/projects/2026`, o la raíz del volumen).
- Al hacer clic en el botón **Actualizar (`↻`)** de la barra de herramientas siempre se omite la caché mediante `?refresh=1` para obtener un listado en vivo directamente desde SFTP o el almacenamiento en la nube.

---

## 4. Verificar la conectividad en vivo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Índice de almacenamiento en la nube](../07-cloud-storage-and-dynamic-config.md)
