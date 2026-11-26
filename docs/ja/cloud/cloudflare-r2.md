# Cloudflare R2 連携ガイド

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **Cloudflare R2 を選ぶべきケースは？**  
> 画像や動画のダウンロードトラフィックが多く、**エグレス（転送料）無料**と組み込みの Cloudflare CDN の高速配信を両立したい場合、Cloudflare R2 はメディア中心のプロジェクトにとって最もコスト効率の高い S3 互換ソリューションの 1 つです。

LiveUX FileManagerBundle は `.r2.cloudflarestorage.com` エンドポイントから Cloudflare R2 を自動検出し、サイドバーに `cloudflare_r2` の SVG アイコンを表示します。

---

## ステップ 1: 必要なパッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## ステップ 2: Cloudflare ダッシュボードでバケットと API トークンを作成する

1. Cloudflare ダッシュボードで **R2 Object Storage** → **Create bucket** を開き、バケットを作成します（例: `cdn-assets`）。
2. 公開アクセスが必要な場合は、バケットの **Settings** → **Public access** からカスタムドメインを接続します（例: `assets.your-site.com`）。非公開ドキュメント用バケットの場合は公開アクセスを無効のままにしてください。
3. R2 の概要ページから **Manage R2 API Tokens** → **Create API token** を開きます。
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** 対象のバケットのみを選択
   - 生成された **Access Key ID**、**Secret Access Key**、および **S3 API Endpoint**（`https://<account-id>.r2.cloudflarestorage.com`）をコピーします。
4. *（任意 — 画像エディター用）* バケットの **Settings** → **CORS Policy** に CORS ルールを追加します。
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

## ステップ 3: `.env.local` と `liveux_file_manager.yaml` を設定する

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### シナリオ A — 公開 CDN バケット & シナリオ B — 非公開ドキュメント保管庫

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # 任意 — エンドポイントから自動検出されます
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Cloudflare R2 では常に 'auto' を指定します
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'R2 プライベートアーカイブ'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # 非公開ボリュームのため公開 URL は設定しません
            private: true             # Voter + DownloadController 経由で認可付きストリーミング配信
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

> **AWS SDK v3.337 以降のチェックサムと ACL 互換性:** `aws/aws-sdk-php >= 3.337` では、新しいデフォルトの CRC32 チェックサムヘッダーにより Cloudflare R2 で `XAmzContentSHA256Mismatch` や `NotImplemented` エラーが発生することがあります。`FlysystemStorageFactory` はカスタム `endpoint` を検出すると、`request_checksum_calculation: 'when_required'`、`response_checksum_validation: 'when_required'`、`disable_acl: true`、`retain_visibility: false` を**自動適用**するため、追加設定は不要です。

---

## ステップ 4: 接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 前へ: AWS S3](aws-s3.md) | [07 — クラウドストレージ目次](../07-cloud-storage-and-dynamic-config.md) | [次へ: Backblaze B2 →](backblaze-b2.md)
