# MinIO 与 RustFS（自托管 / Docker）集成指南

[← 07 — 云存储与动态配置](../07-cloud-storage-and-dynamic-config.md) | [主目录](../README.md)

> **何时应选择 MinIO / RustFS？**  
> 如果您的企业本地服务器有严格的数据驻留或合规要求（如 GDPR、HIPAA 等），不允许文件离开自有基础设施，或者您希望借助 Docker 在本地免费测试真实的 S3 交互行为，自托管 S3 存储是最佳方案。

---

## 第 1 步：安装所需扩展包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## 第 2 步：使用 Docker Compose 快速搭建（本地开发 / 自托管）

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # S3 API 端点
            - "9001:9001"   # Web 管理控制台
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # 公开存储桶上供内置 Canvas 图片编辑器使用的 CORS 允许源：
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

启动容器后，访问 `http://localhost:9001` 并创建名为 `uploads` 的存储桶（如需公开读取可将 **Access Policy** 设为 `public`，用于受限归档则保持 `private`）。

---

## 第 3 步：配置 `.env.local` 与 `liveux_file_manager.yaml`

```dotenv
# .env.local
MINIO_ENDPOINT=http://localhost:9000
MINIO_BUCKET=uploads
MINIO_KEY=minioadmin
MINIO_SECRET=minioadmin-change-me
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        minio_storage:
            label: 'MinIO 存储'
            icon: 'minio'             # 当端点包含 'minio' 或 ':9000' 时自动识别；使用自定义 RustFS 端口时建议显式指定
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # 若 private: true，请设为 null
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # MinIO/RustFS 的标准默认区域
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # 自托管 S3 服务必须开启路径风格端点
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # 如果使用自签名 HTTPS 证书：
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **提示（RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi）：** 您可以使用完全相同的 `AwsS3` 配置模板连接任意 S3 兼容服务，只需相应调整 `endpoint`、`region` 和 `use_path_style_endpoint` 即可。由于配置了自定义端点，`FlysystemStorageFactory` 会自动启用 `when_required` 校验和验证与 `disable_acl: true`。

---

## 第 4 步：验证实时连通性

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 上一篇：Backblaze B2](backblaze-b2.md) | [07 — 云存储索引](../07-cloud-storage-and-dynamic-config.md) | [下一篇：Google Cloud Storage (GCS) →](google-cloud-storage.md)
