# SFTP、Azure Blob 与自定义 Flysystem 服务（`FlysystemService`）

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择此方案？**  
> 每当需要接入非 S3 存储后端（例如 **SFTP/FTP 服务器**、**Azure Blob Storage** 或 **WebDAV**），或者希望直接复用项目中已通过 `league/flysystem-bundle` 配置好的 Flysystem 文件系统时，请使用 `driver: 'FlysystemService'`。

---

## 1. 连接 SFTP 服务器（`league/flysystem-sftp-v3`）

### 第 1 步：安装 SFTP 适配器

```bash
composer require league/flysystem-sftp-v3
```

### 第 2 步：在 `.env.local` 中添加连接变量

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### 第 3 步：定义 Symfony 服务（`config/services.yaml`）

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # 若使用 SSH 密钥认证，请设为 null 并配置 $privateKey
            $privateKey: null                 # 例如：'%kernel.project_dir%/config/ssh/id_ed25519'
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
        # 注意：无需设置 public: true！FlysystemServiceLocatorPass 会自动将该服务注册到安全定位器中。
        arguments:
            $adapter: '@app.sftp.adapter'
```

### 第 4 步：在 `liveux_file_manager.yaml` 中注册存储卷

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP 归档服务器'
            icon: 'sftp'              # 内置 SFTP SVG 图标
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # 文件通过 DownloadController + RBAC Voter 安全流式传输
            cache_ttl: 30             # 由于 SFTP 目录内容可能被外部修改，建议使用较短的缓存时间
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **使用动态 `ConfigProviderInterface`？** 如果您是从数据库动态返回服务 ID 而不是在 YAML 中静态声明，只需为该 Flysystem 服务添加 `tags: ['liveux_file_manager.storage']`（或 `flysystem.storage`）标签即可——同样无需设置 `public: true`。

---

## 2. 复用现有的 `league/flysystem-bundle` 服务（Azure Blob、WebDAV 等）

如果您的项目已经在使用 `league/flysystem-bundle`（例如已定义 `azure.storage` 或 `webdav.storage`），只需将 `driver_options.service` 指向现有服务 ID 即可：

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
                service: 'azure.storage'  # 任意实现了 League\Flysystem\FilesystemOperator 的服务
```

---

## 3. 外部删除的目录与 `remember_last_dir`

假设某位用户上次正在 SFTP 服务器或云端存储桶的 `/projects/2026/draft` 目录中工作，之后有人通过 FTP/SFTP 客户端在外部删除了该文件夹：
- 当用户重新加载页面时，文件管理器不会崩溃，而是会自动回退到最近存在的上级目录（`/projects/2026` 或存储卷根目录）。
- 点击工具栏中的 **刷新（`↻`）** 按钮始终会通过 `?refresh=1` 绕过缓存，直接从 SFTP 或云存储拉取实时目录列表。

---

## 4. 验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 上一篇：Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — 云存储索引](../07-cloud-storage-and-dynamic-config.md)
