# Supabase Storage (S3) 集成指南

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择 Supabase Storage？**  
> 如果您已经在使用 Supabase 项目，或者希望借助充裕的免费额度在同一个控制台中统一管理公开 CDN 资源与私有文档存储桶，Supabase Storage 是最实用的 S3 兼容方案。

由于 **Supabase Storage** 支持 S3 兼容协议，无需编写任何自定义代码，即可开箱即用地与 LiveUX FileManagerBundle 组成 **本地存储 + Supabase 云存储** 混合架构。本 Bundle 会根据端点 URL 自动识别 `supabase` 服务商，并在侧边栏中显示 Supabase 的 SVG 图标。

---

## 第 1 步：安装所需扩展包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## 第 2 步：在 Supabase 控制台生成 S3 Access Keys

> [!WARNING]
> **重要提示：** Supabase 的 REST/Auth API 密钥——`SUPABASE_PUBLISHABLE_KEY`（`sb_publishable_...`）和 `SUPABASE_SECRET_KEY`（`sb_secret_...`）——**无法**用于 S3 协议。您必须使用下方 **S3 Access Keys** 区域生成的 32 位和 64 位凭据。

1. 打开 Supabase 控制台中的项目 → **Storage** → **New bucket**，创建一个存储桶（例如 `media` 或 `prints`）。
   - 对于需要公开访问的 CDN 图片与静态资源，勾选 **Public bucket**。
   - 对于客户文档或发票等敏感文件，请取消勾选 **Public bucket**（保持私有）。
2. 前往 **Project Settings** → **Storage**（或 **Storage** → **Settings**）。
3. 从 **S3 Connection** 卡片中复制以下两项配置：
   - **Endpoint：** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region：** 例如 `eu-central-1` 或 `eu-west-1`
4. 在 **S3 Access Keys** 区域点击 **New access key**，填写描述信息并复制生成的值：
   - **Access Key ID**（32 个字符）
   - **Secret Access Key**（64 个字符——仅显示一次！）

---

## 第 3 步：在 `.env.local` 中添加环境变量

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

## 第 4 步：配置 `liveux_file_manager.yaml`

### 场景 A — 公开存储桶（Public Bucket）

图片、视频和公开文件将直接通过 Supabase CDN URL 分发（大幅减轻 PHP 服务器负载），同时缩略图会在您的服务器上生成一次并从本地缓存（`public/media-cache`）提供服务：

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: '本地存储'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # 可选——会根据端点 URL 自动识别
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # 针对可能被外部修改的云端内容设置 60 秒缓存
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
                volume_order: 'local_first' # 可选值：'network_first' 或 'configured'
```

### 场景 B — 按用户隔离的私有存储桶（Private Bucket）

适用于仅允许已认证且具备相应权限的用户访问的客户发票或机密 PDF 文档：

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Supabase 文档保险库'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # 私有存储桶不提供公开 URL
            private: true             # 通过 DownloadController + Voter 安全流式传输
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # 将每位用户隔离在各自的子目录中，避免文件冲突
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

## 第 5 步：一键验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

连接成功时的输出示例：
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **提示：** 对于使用 `prefix: 'users/{user_id}'` 等占位符的按用户动态存储卷（如场景 B），由于 CLI 环境中不存在活跃用户会话，`file-manager:doctor --check-remote` 会安全跳过并以 `INFO` 状态提示。

---

## Supabase 专属技巧与故障排查

### 1. 在限制 MIME 类型的存储桶中删除文件报错（`InvalidMimeType: application/json`）
如果您在 Supabase 存储桶中配置了 **Allowed MIME types**（例如仅允许 `application/pdf` 或 `image/*`），当回收站功能尝试写入 `.trash/<id>/.meta.json` 时，Supabase 会返回 `InvalidMimeType` 错误。
- **自动回退机制：** `TrashManager` 会自动检测此情况——文件本身仍会移动到云端存储桶的 `.trash/<id>/...` 目录中，而 `.meta.json` 元数据会写入您服务器本地的回退文件（位于 `defaults.metadata.file` 目录下的 `trash-meta.json` 或 `<name>.trash-meta.json`）。文件的还原与彻底删除功能均可无缝运行。

### 2. AWS SDK v3.337+ 校验和（Checksum）与 ACL 错误
针对配置了自定义 `endpoint`（如 Supabase）的 S3 存储卷，`FlysystemStorageFactory` 会自动启用以下兼容选项：
- `request_checksum_calculation: 'when_required'` 与 `response_checksum_validation: 'when_required'`
- `retain_visibility: false` 以及剥离 `x-amz-acl` 请求头（`disable_acl: true`）

无需任何额外配置。

### 3. Windows / WAMP 环境下的 `cURL error 60` SSL 证书错误
如果您在本地开发机上遇到 `cURL error 60: unable to get local issuer certificate`：
- 下载 [cacert.pem](https://curl.se/ca/cacert.pem) 并在 `php.ini`（需同时配置 Apache 和 CLI 使用的 `php.ini`）中指定路径：
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- 或者仅针对特定存储卷，通过 `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` 进行配置。

### 4. 在 Supabase 控制台删除的文件仍出现在文件列表中
- 点击工具栏中的 **刷新（`↻`）** 按钮（或按快捷键 `R`），即可通过 `?refresh=1` 立即跳过缓存拉取最新列表。
- 如果存储桶中的文件经常在外部被修改，建议在该存储卷上设置 `cache_ttl: 30`（或设为 `cache_ttl: 0` 以完全禁用缓存）。

---

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [下一篇：AWS S3 指南 →](aws-s3.md)
