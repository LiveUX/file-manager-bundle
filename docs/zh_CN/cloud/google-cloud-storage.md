# Google Cloud Storage (GCS) 集成指南

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择 Google Cloud Storage？**  
> 如果您的应用已经运行在 Google Cloud（GKE、Cloud Run、Compute Engine）上，并且希望在不引入额外厂商 SDK 的前提下，直接通过其 S3 互操作层（HMAC）进行接入，GCS 是顺理成章的选择。

**Google Cloud Storage (GCS)** 可以通过内置的 S3 兼容层（*XML API / Interoperability HMAC*）直接配合 `AwsS3` 驱动使用——当然，如有需要，您也可以通过 `FlysystemService` 接入 `league/flysystem-google-cloud-storage`。本 Bundle 会自动识别 `storage.googleapis.com` 端点，并在侧边栏中显示 `gcs` SVG 图标。

---

## 第 1 步：安装所需扩展包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## 第 2 步：在 Google Cloud 控制台创建 HMAC 密钥

1. 打开 Google Cloud 控制台 → **Cloud Storage** → **Buckets**，创建一个新存储桶（例如 `my-symfony-media`）。
2. 进入 **Cloud Storage** → **Settings** → **Interoperability**。
3. 在 **Service account HMAC** 区域点击 **Create a key for a service account**（请确保该服务账号拥有 `Storage Object Admin` 角色）。
4. 复制生成的 **Access key**（`GOOG1E...`）和 **Secret**。
5. *（可选——用于图片编辑器）* 使用 `cors.json` 文件为存储桶应用 CORS 策略：
   ```json
   [
       {
           "origin": ["https://your-site.com"],
           "method": ["GET", "HEAD"],
           "responseHeader": ["Content-Type", "ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```
   ```bash
   gcloud storage buckets update gs://my-symfony-media --cors-file=cors.json
   ```

---

## 第 3 步：配置 `.env.local` 与 `liveux_file_manager.yaml`

```dotenv
# .env.local
GCS_BUCKET=my-symfony-media
GCS_HMAC_KEY=GOOG1E...
GCS_HMAC_SECRET=...
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        gcs_media:
            label: 'Google Cloud Storage'
            icon: 'gcs'               # 可选——会根据端点 URL 自动识别
            group: 'network'
            driver: 'AwsS3'
            # 公开存储桶格式为：https://storage.googleapis.com/<bucket>（私有存储桶请设为 null）
            url_prefix: 'https://storage.googleapis.com/%env(GCS_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(GCS_BUCKET)%'
                region: 'auto'
                endpoint: 'https://storage.googleapis.com'
                use_path_style_endpoint: true
                key: '%env(GCS_HMAC_KEY)%'
                secret: '%env(GCS_HMAC_SECRET)%'

    profiles:
        default:
            volumes: ['gcs_media']
```

> **GCS 自动兼容处理：** 由于配置了 `endpoint: 'https://storage.googleapis.com'`，`FlysystemStorageFactory` 会自动启用 `request_checksum_calculation: 'when_required'`、`response_checksum_validation: 'when_required'`、`disable_acl: true` 和 `retain_visibility: false`。

---

## 第 4 步：验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 上一篇：MinIO / RustFS](minio-rustfs.md) | [07 — 云存储索引](../07-cloud-storage-and-dynamic-config.md) | [下一篇：SFTP 与自定义 Flysystem 服务 →](sftp-and-custom-flysystem.md)
