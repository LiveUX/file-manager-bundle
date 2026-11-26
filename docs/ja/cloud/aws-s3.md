# Amazon AWS S3 連携ガイド

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **Amazon AWS S3 を選ぶべきケースは？**  
> アプリケーションがすでに AWS（EC2 / ECS / CloudFront）上で稼働しており、アクセスキー不要の IAM ロール認証とグローバルな高耐久性（99.999999999%）が必要な場合、AWS S3 は業界標準の選択肢です。

LiveUX FileManagerBundle は `AwsS3` ドライバーを使って AWS S3 バケットへ直接接続し、サイドバーに `aws_s3` の SVG アイコンを自動表示します。

---

## ステップ 1: 必要なパッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## ステップ 2: 最小権限の IAM ポリシーを作成する

AWS IAM で本アプリケーション専用の IAM ユーザー（または EC2 / ECS ロール）を作成し、対象バケットのみにスコープを限定した以下のポリシーをアタッチします。

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
> **オブジェクト所有者 / ACL 設定（`disable_acl: true`）:**  
> 現在の AWS S3 バケットでは、デフォルトで ACL が無効化されています（*Bucket owner enforced*）。`disable_acl` オプションが自動で `true` になるのはカスタム `endpoint`（R2、Supabase、MinIO など）が指定されている場合のみであり、**ネイティブ AWS S3 ではデフォルトで `false`** となります。  
> `AccessControlListNotSupported` エラーを防ぐため、以下の設定例のように `driver_options` 配下に **`disable_acl: true`**（および必要に応じて `retain_visibility: false`）を明示的に追加してください。

---

## ステップ 3: バケットの CORS を設定する（画像エディター用）

組み込みの Canvas 画像エディターで S3 上の画像をブラウザーから直接トリミング・編集できるようにするため、バケットの **Permissions** → **CORS** に自身のドメインを追加します。

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

## ステップ 4: `.env.local` と `liveux_file_manager.yaml` を設定する

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
            icon: 'aws_s3'            # 任意 — AwsS3 ドライバーから自動検出されます
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # CloudFront または S3 公開 URL
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # 動的パスプレースホルダーをサポート
                disable_acl: true     # "Bucket owner enforced" 設定の S3 バケットで推奨
                retain_visibility: false

        aws_private_docs:
            label: 'AWS プライベートアーカイブ'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access を有効化。Voter + DownloadController 経由で配信
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

> **EC2 / ECS IAM ロールまたは AWS STS 一時認証情報を使用する場合:**
> - **IAM ロール:** EC2 インスタンスプロファイルまたは ECS タスクロールを使用している場合、`key` と `secret` は完全に省略できます。AWS SDK が環境から自動的に認証情報を解決します。
> - **AWS STS 一時認証情報:** 一時的なセッショントークンを使用する場合は、`driver_options.token: '%env(AWS_SESSION_TOKEN)%'` を追加してください。

---

## ステップ 5: 接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

> **補足:** `prefix: 'private/{user_id}'` のようなプレースホルダーを含むユーザー別ボリューム（`aws_private_docs` など）は、CLI 環境にユーザーセッションが存在しないため、`file-manager:doctor --check-remote` 実行時に `INFO` ステータスで安全にスキップされます。

---

[← 前へ: Supabase Storage](supabase-storage.md) | [07 — クラウドストレージ目次](../07-cloud-storage-and-dynamic-config.md) | [次へ: Cloudflare R2 →](cloudflare-r2.md)
