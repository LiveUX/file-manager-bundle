# Amazon AWS S3 集成指南

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择 Amazon AWS S3？**  
> 如果您的应用已经部署在 AWS 生态（EC2/ECS/CloudFront）中，并且需要基于 IAM Role 的无密钥认证以及全球级高持久性（99.999999999%），AWS S3 是行业标准之选。

LiveUX FileManagerBundle 通过 `AwsS3` 驱动直接连接您的 AWS S3 存储桶，并在侧边栏中自动显示 `aws_s3` SVG 图标。

---

## 第 1 步：安装所需扩展包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## 第 2 步：创建最小权限 IAM 策略

在 AWS IAM 中为当前应用创建专用的 IAM 用户（或 EC2/ECS Role），并附加以下严格限定到目标存储桶的策略：

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ListBucket",
            "Effect": "Allow",
            "Action": ["s3:ListBucket"],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME"
        },
        {
            "Sid": "ManageObjects",
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
        }
    ]
}
```

> [!IMPORTANT]
> **对象所有权 / ACL 设置（`disable_acl: true`）：**  
> 现代 AWS S3 存储桶默认禁用 ACL（*Bucket owner enforced*，强制存储桶拥有者模式）。`disable_acl` 选项仅在配置了自定义 `endpoint`（如 R2、Supabase、MinIO 等）时才会自动设为 `true`；**对于原生 AWS S3，该选项默认为 `false`**。  
> 为避免出现 `AccessControlListNotSupported` 错误，请参照下方示例，在 `driver_options` 中显式添加 **`disable_acl: true`**（并可选择同时设置 `retain_visibility: false`）。

---

## 第 3 步：配置存储桶 CORS（用于图片编辑器）

为了让内置的 Canvas 图片编辑器能够在浏览器中直接裁剪和编辑托管在 S3 上的图片，请在存储桶的 **Permissions** → **CORS** 中添加您的站点域名：

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": ["https://your-site.com"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

---

## 第 4 步：配置 `.env.local` 与 `liveux_file_manager.yaml`

```dotenv
# .env.local
AWS_S3_BUCKET=my-media-bucket
AWS_S3_REGION=eu-central-1
AWS_S3_KEY=AKIAIOSFODNN7EXAMPLE
AWS_S3_SECRET=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        aws_cdn:
            label: 'AWS S3 (CloudFront CDN)'
            icon: 'aws_s3'            # 可选——会根据 AwsS3 驱动自动识别
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # CloudFront 或 S3 公开访问 URL
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # 支持动态路径占位符
                disable_acl: true     # 推荐用于 "Bucket owner enforced" 模式的 S3 存储桶
                retain_visibility: false

        aws_private_docs:
            label: 'AWS 私有归档'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # 已启用阻止公共访问（Block Public Access）；文件通过 Voter + DownloadController 流式传输
            cache_ttl: 60
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'private/{user_id}'
                disable_acl: true
                retain_visibility: false

    profiles:
        default:
            volumes: ['aws_cdn', 'aws_private_docs']
```

> **EC2 / ECS IAM Role 或 AWS STS 临时凭据：**
> - **IAM Role：** 如果使用 EC2 Instance Profile 或 ECS Task Role，可以完全省略 `key` 和 `secret`——AWS SDK 会自动从运行环境中解析凭据。
> - **AWS STS 临时凭据：** 如果使用临时会话令牌，请添加 `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`。

---

## 第 5 步：验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

> **提示：** 包含 `prefix: 'private/{user_id}'` 等占位符的用户隔离型存储卷（如 `aws_private_docs`），由于 CLI 环境中不存在用户会话，`file-manager:doctor --check-remote` 会安全跳过并以 `INFO` 状态提示。

---

[← 上一篇：Supabase Storage](supabase-storage.md) | [07 — 云存储索引](../07-cloud-storage-and-dynamic-config.md) | [下一篇：Cloudflare R2 →](cloudflare-r2.md)
