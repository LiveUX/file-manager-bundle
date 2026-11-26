# Backblaze B2 集成指南

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择 Backblaze B2？**  
> 如果您需要以极低的存储成本存放数百 GB 的视频、印刷级 PDF 或高分辨率归档文件（尤其是搭配 Cloudflare 或 Bunny CDN 等 Bandwidth Alliance 合作伙伴实现免费出站流量时），Backblaze B2 是理想之选。

本 Bundle 会自动识别 `.backblazeb2.com` 端点，并在侧边栏中显示 `backblaze_b2` SVG 图标。

---

## 第 1 步：安装所需扩展包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## 第 2 步：在 Backblaze 控制台配置存储桶、应用密钥与 CORS

1. 前往 Backblaze B2 → **Buckets** → **Create a Bucket**（例如 `app-media`）。
   - 创建完成后，记下存储桶卡片上显示的 **Endpoint**（例如 `s3.eu-central-003.backblazeb2.com`）。
   - **Region** 即为端点中间的区段：`eu-central-003`。
2. 前往 **Application Keys** → **Add a New Application Key**：
   - **Allow access to Bucket(s)：** 仅选择刚创建的存储桶
   - **Type of Access：** 选择 `Read and Write`
   - 复制生成的 **`keyID`** 和 **`applicationKey`**。
3. *（可选——用于图片编辑器）* 在存储桶的 **CORS Rules** 下，您可以选择 **Share everything in this bucket with every origin**，或为您的域名添加自定义 S3 CORS 规则：
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://your-site.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## 第 3 步：配置 `.env.local` 与 `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=app-media
B2_REGION=eu-central-003
B2_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
B2_KEY_ID=003xxxxxxxxxxxxxxxxxxxxxx
B2_APP_KEY=K003yyyyyyyyyyyyyyyyyyyyyyyyy
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        backblaze_b2:
            label: 'Backblaze B2'
            icon: 'backblaze_b2'      # 可选——会根据端点 URL 自动识别
            group: 'network'
            driver: 'AwsS3'
            # 公开存储桶请填写 Friendly URL 或 CDN 域名（私有存储桶请设为 null）：
            url_prefix: 'https://f003.backblazeb2.com/file/%env(B2_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(B2_BUCKET)%'
                region: '%env(B2_REGION)%'
                endpoint: '%env(B2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(B2_KEY_ID)%'
                secret: '%env(B2_APP_KEY)%'

    profiles:
        default:
            volumes: ['backblaze_b2']
```

> **提示：** 由于配置了自定义端点，`FlysystemStorageFactory` 会自动为 Backblaze B2 启用 AWS SDK v3.337+ 校验和兼容模式（`when_required`）、`disable_acl: true` 和 `retain_visibility: false`。

---

## 第 4 步：验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 上一篇：Cloudflare R2](cloudflare-r2.md) | [07 — 云存储索引](../07-cloud-storage-and-dynamic-config.md) | [下一篇：MinIO / RustFS →](minio-rustfs.md)
