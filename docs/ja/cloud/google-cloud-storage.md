# Google Cloud Storage (GCS) 連携ガイド

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **Google Cloud Storage を選ぶべきケースは？**  
> アプリケーションがすでに Google Cloud（GKE、Cloud Run、Compute Engine）上で稼働しており、追加のベンダー SDK を導入せずに S3 相互運用性レイヤー（HMAC）経由で直接接続したい場合、GCS が自然な選択肢となります。

**Google Cloud Storage (GCS)** は、組み込みの S3 互換レイヤー（*XML API / 相互運用性 HMAC*）を通じて `AwsS3` ドライバーでそのまま動作します（必要に応じて `FlysystemService` 経由で `league/flysystem-google-cloud-storage` を組み込むことも可能です）。`storage.googleapis.com` エンドポイントは自動検出され、サイドバーに `gcs` の SVG アイコンが表示されます。

---

## ステップ 1: 必要なパッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## ステップ 2: Google Cloud コンソールで HMAC キーを作成する

1. Google Cloud コンソールで **Cloud Storage** → **Buckets** を開き、新しいバケットを作成します（例: `my-symfony-media`）。
2. **Cloud Storage** → **Settings** → **Interoperability** へ移動します。
3. **Service account HMAC** セクションで **Create a key for a service account** をクリックします（対象のサービスアカウントに `Storage Object Admin` ロールが付与されていることを確認してください）。
4. 生成された **Access key**（`GOOG1E...`）と **Secret** をコピーします。
5. *（任意 — 画像エディター用）* `cors.json` ファイルを使用してバケットに CORS ポリシーを適用します。
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

## ステップ 3: `.env.local` と `liveux_file_manager.yaml` を設定する

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
            icon: 'gcs'               # 任意 — エンドポイントから自動検出されます
            group: 'network'
            driver: 'AwsS3'
            # 公開バケットの場合: https://storage.googleapis.com/<bucket>（非公開の場合は null）
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

> **GCS 向け自動互換設定:** `endpoint: 'https://storage.googleapis.com'` が設定されているため、`FlysystemStorageFactory` は自動的に `request_checksum_calculation: 'when_required'`、`response_checksum_validation: 'when_required'`、`disable_acl: true`、`retain_visibility: false` を適用します。

---

## ステップ 4: 接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 前へ: MinIO / RustFS](minio-rustfs.md) | [07 — クラウドストレージ目次](../07-cloud-storage-and-dynamic-config.md) | [次へ: SFTP & カスタム Flysystem サービス →](sftp-and-custom-flysystem.md)
