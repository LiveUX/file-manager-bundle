# Cloudflare R2 集成指南

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择 Cloudflare R2？**  
> 如果您的项目存在大量图片或视频下载流量，并且希望在享受 **零出站流量费（Zero Egress Fees）** 的同时获得内置的 Cloudflare CDN 加速体验，Cloudflare R2 是媒体密集型项目极具性价比的 S3 兼容存储方案。

LiveUX FileManagerBundle 会根据 `.r2.cloudflarestorage.com` 端点自动识别 Cloudflare R2，并在侧边栏中显示 `cloudflare_r2` SVG 图标。

---

## 第 1 步：安装所需扩展包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## 第 2 步：在 Cloudflare 控制台创建存储桶与 API Token

1. 前往 Cloudflare 控制台 → **R2 Object Storage** → **Create bucket**，创建一个存储桶（例如 `cdn-assets`）。
2. 如需公开访问，请在存储桶的 **Settings** → **Public access** 下绑定自定义域名（例如 `assets.your-site.com`）。如果是私有文档存储桶，请保持公开访问为禁用状态。
3. 在 R2 概览页面打开 **Manage R2 API Tokens** → **Create API token**：
   - **Permissions：** 选择 `Object Read & Write`
   - **Specify bucket(s)：** 仅勾选目标存储桶
   - 复制生成的 **Access Key ID**、**Secret Access Key** 以及 **S3 API Endpoint**（`https://<account-id>.r2.cloudflarestorage.com`）。
4. *（可选——用于图片编辑器）* 在存储桶的 **Settings** → **CORS Policy** 下添加 CORS 规则：
   ```json
   [
       {
           "AllowedOrigins": ["https://your-site.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## 第 3 步：配置 `.env.local` 与 `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### 场景 A — 公开 CDN 存储桶 & 场景 B — 私有文档保险库

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # 可选——会根据端点 URL 自动识别
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Cloudflare R2 始终使用 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'R2 私有归档'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # 私有存储卷不提供公开 URL
            private: true             # 通过 Voter + DownloadController 授权流式传输
            cache_ttl: 60
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                prefix: 'private-docs'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['cloudflare_r2_cdn', 'cloudflare_r2_private']
```

> **AWS SDK v3.337+ 校验和与 ACL 兼容性：** 在 `aws/aws-sdk-php >= 3.337` 中，新增的默认 CRC32 校验和请求头可能会在 Cloudflare R2 上触发 `XAmzContentSHA256Mismatch` 或 `NotImplemented` 错误。只要 `FlysystemStorageFactory` 检测到自定义 `endpoint`，便会**自动应用** `request_checksum_calculation: 'when_required'`、`response_checksum_validation: 'when_required'`、`disable_acl: true` 和 `retain_visibility: false`，无需任何额外配置。

---

## 第 4 步：验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 上一篇：AWS S3](aws-s3.md) | [07 — 云存储索引](../07-cloud-storage-and-dynamic-config.md) | [下一篇：Backblaze B2 →](backblaze-b2.md)
