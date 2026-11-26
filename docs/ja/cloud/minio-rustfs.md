# MinIO & RustFS（セルフホスト / Docker）連携ガイド

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **MinIO / RustFS を選ぶべきケースは？**  
> ファイルを自社インフラの外に出せない厳格なデータレジデンシーやコンプライアンス要件（GDPR、HIPAA など）があるオンプレミス環境、あるいは Docker を使ってローカル環境で本番同様の S3 挙動を無料でテストしたい場合にセルフホスト S3 ストレージは最適です。

---

## ステップ 1: 必要なパッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## ステップ 2: Docker Compose によるクイックセットアップ（開発 / セルフホスト向け）

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # S3 API エンドポイント
            - "9001:9001"   # Web 管理コンソール
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # 公開バケットで組み込み Canvas 画像エディターを使用するための CORS オリジン設定:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

コンテナーを起動して `http://localhost:9001` を開き、`uploads` という名前でバケットを作成します（公開読み取りを許可する場合は **Access Policy** を `public` に、アクセス制限されたアーカイブにする場合は `private` のままにします）。

---

## ステップ 3: `.env.local` と `liveux_file_manager.yaml` を設定する

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
            label: 'MinIO Storage'
            icon: 'minio'             # エンドポイントに 'minio' または ':9000' が含まれる場合は自動検出。カスタムポートの RustFS では明示的に指定
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # private: true の場合は null を指定
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # MinIO / RustFS の標準デフォルト値
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # セルフホスト S3 サーバーでは必須
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # 自己署名 HTTPS 証明書を使用している場合:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **ヒント（RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi）:** `endpoint`、`region`、`use_path_style_endpoint` を変更するだけで、この `AwsS3` テンプレートをそのまま任意の S3 互換サービスへ接続できます。カスタムエンドポイントが指定されているため、`FlysystemStorageFactory` は自動的に `when_required` チェックサム検証と `disable_acl: true` を適用します。

---

## ステップ 4: 接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 前へ: Backblaze B2](backblaze-b2.md) | [07 — クラウドストレージ目次](../07-cloud-storage-and-dynamic-config.md) | [次へ: Google Cloud Storage (GCS) →](google-cloud-storage.md)
