# Backblaze B2 連携ガイド

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **Backblaze B2 を選ぶべきケースは？**  
> 数百 GB 規模の動画、印刷用 PDF、高解像度アーカイブを可能な限り低いストレージコストで保管したい場合（特に Cloudflare や Bunny CDN などの Bandwidth Alliance パートナーと組み合わせてエグレス無料を活用する場合）に Backblaze B2 は最適です。

`.backblazeb2.com` エンドポイントは自動検出され、サイドバーに `backblaze_b2` の SVG アイコンが表示されます。

---

## ステップ 1: 必要なパッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## ステップ 2: Backblaze でバケット・アプリケーションキー・CORS を設定する

1. Backblaze B2 で **Buckets** → **Create a Bucket** を開き、バケットを作成します（例: `app-media`）。
   - 作成後、バケットカードに表示される **Endpoint** を控えておきます（例: `s3.eu-central-003.backblazeb2.com`）。
   - **Region** はエンドポイント中央のセグメントである `eu-central-003` です。
2. **Application Keys** → **Add a New Application Key** を開きます。
   - **Allow access to Bucket(s):** 作成したバケットのみを選択
   - **Type of Access:** `Read and Write`
   - 生成された **`keyID`** と **`applicationKey`** をコピーします。
3. *（任意 — 画像エディター用）* バケットの **CORS Rules** で **Share everything in this bucket with every origin** を選択するか、自身のドメイン向けにカスタム S3 CORS ルールを追加します。
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

## ステップ 3: `.env.local` と `liveux_file_manager.yaml` を設定する

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
            icon: 'backblaze_b2'      # 任意 — エンドポイントから自動検出されます
            group: 'network'
            driver: 'AwsS3'
            # 公開バケットの場合は Friendly URL または CDN ドメインを指定（非公開の場合は null）:
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

> **補足:** カスタムエンドポイントが設定されているため、`FlysystemStorageFactory` は Backblaze B2 向けに AWS SDK v3.337 以降のチェックサム互換性（`when_required`）、`disable_acl: true`、`retain_visibility: false` を自動的に有効化します。

---

## ステップ 4: 接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 前へ: Cloudflare R2](cloudflare-r2.md) | [07 — クラウドストレージ目次](../07-cloud-storage-and-dynamic-config.md) | [次へ: MinIO / RustFS →](minio-rustfs.md)
