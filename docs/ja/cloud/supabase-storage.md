# Supabase Storage (S3) 連携ガイド

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **Supabase Storage を選ぶべきケースは？**  
> すでに Supabase プロジェクトを利用している場合や、充実した無料枠を活用して公開 CDN アセットと非公開ドキュメント用バケットを 1 つのダッシュボードで管理したい場合、Supabase Storage は最も実用的な S3 互換の選択肢です。

**Supabase Storage** は S3 互換プロトコルをサポートしているため、カスタムコードを 1 行も書かずに、LiveUX FileManagerBundle で **ローカルストレージ + Supabase クラウド** のハイブリッド構成をすぐに利用できます。バンドルはエンドポイント URL から `supabase` プロバイダーを自動検出し、サイドバーに Supabase の SVG アイコンを表示します。

---

## ステップ 1: 必要なパッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## ステップ 2: Supabase ダッシュボードで S3 アクセスキーを作成する

> [!WARNING]
> **重要:** Supabase の REST / Auth API キーである `SUPABASE_PUBLISHABLE_KEY`（`sb_publishable_...`）や `SUPABASE_SECRET_KEY`（`sb_secret_...`）は S3 プロトコルでは**使用できません**。必ず下記の **S3 Access Keys** から発行される 32 文字および 64 文字の認証情報を使用してください。

1. Supabase ダッシュボードでプロジェクトを開き、**Storage** → **New bucket** からバケットを作成します（例: `media` または `prints`）。
   - 公開 CDN 画像やアセット用には **Public bucket** にチェックを入れます。
   - 顧客ドキュメントや請求書用には **Public bucket** のチェックを外したまま（非公開）にします。
2. **Project Settings** → **Storage**（または **Storage** → **Settings**）を開きます。
3. **S3 Connection** カードから次の 2 つの値をコピーします。
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** 例: `eu-central-1` または `eu-west-1`
4. **S3 Access Keys** セクションで **New access key** をクリックし、説明を入力して生成された値をコピーします。
   - **Access Key ID**（32 文字）
   - **Secret Access Key**（64 文字 — 作成時に一度しか表示されません）

---

## ステップ 3: `.env.local` に環境変数を追加する

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## ステップ 4: `liveux_file_manager.yaml` を設定する

### シナリオ A — 公開バケット

画像・動画・公開ファイルは Supabase CDN の URL から直接配信されるため PHP サーバーの負荷を抑えられます。一方、サムネイルはサーバー側で一度だけ生成され、ローカルキャッシュ（`public/media-cache`）から配信されます。

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'ローカルストレージ'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # 任意 — エンドポイントから自動検出されます
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # 外部から変更される可能性があるクラウドコンテンツ向けに 60 秒キャッシュ
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # または 'network_first' / 'configured'
```

### シナリオ B — ユーザー単位で分離された非公開バケット

認証済みかつ権限を持つユーザーのみがアクセスできる顧客請求書や機密 PDF 向けの設定です。

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Supabase ドキュメント保管庫'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # 非公開バケットのため公開 URL は設定しません
            private: true             # DownloadController + Voter 経由で安全にストリーミング配信
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # 各ユーザーを専用のサブフォルダに分離し、衝突を防止
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## ステップ 5: コマンド 1 つで接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

成功時の出力例:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **補足:** シナリオ B のように `prefix: 'users/{user_id}'` などのプレースホルダーを含む動的なユーザー別ボリュームは、CLI 環境にアクティブなユーザーセッションが存在しないため、`file-manager:doctor --check-remote` 実行時に `INFO` ステータスで安全にスキップされます。

---

## Supabase 固有のヒントとトラブルシューティング

### 1. MIME タイプ制限バケットでのファイル削除（`InvalidMimeType: application/json`）
Supabase バケットの **Allowed MIME types** で `application/pdf` や `image/*` のみを許可している場合、ゴミ箱機能が `.trash/<id>/.meta.json` を書き込もうとすると Supabase が `InvalidMimeType` を返します。
- **自動フォールバック:** `TrashManager` がこれを自動的に検知します。ファイル本体はクラウドバケット内の `.trash/<id>/...` へ移動され、`.meta.json` のメタデータはサーバー側のローカルフォールバックファイル（`defaults.metadata.file` ディレクトリ内の `trash-meta.json` または `<name>.trash-meta.json`）に書き込まれます。復元や完全削除も問題なく機能します。

### 2. AWS SDK v3.337 以降のチェックサムと ACL エラー
Supabase のようにカスタム `endpoint` が設定された S3 ボリュームでは、`FlysystemStorageFactory` が以下を自動的に適用します。
- `request_checksum_calculation: 'when_required'` および `response_checksum_validation: 'when_required'`
- `retain_visibility: false` および `x-amz-acl` ヘッダーの除去（`disable_acl: true`）

追加の設定は不要です。

### 3. Windows / WAMP 環境での `cURL error 60` SSL 証明書エラー
ローカル開発環境で `cURL error 60: unable to get local issuer certificate` が発生する場合は、以下を行ってください。
- [cacert.pem](https://curl.se/ca/cacert.pem) をダウンロードし、`php.ini`（Apache 用と CLI 用の両方の `php.ini`）にパスを指定します。
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- あるいは、特定のボリュームのみに適用したい場合は `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` を設定します。

### 4. Supabase ダッシュボードで削除したファイルが一覧に残り続ける場合
- ツールバーの **更新（`↻`）** ボタンをクリックする（または `R` キーを押す）と、`?refresh=1` によりキャッシュをバイパスして即時再読み込みされます。
- バケット内のファイルが外部から頻繁に変更される場合は、対象ボリュームの `cache_ttl: 30` に短縮するか、`cache_ttl: 0` でキャッシュを無効化してください。

---

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [次へ: AWS S3 ガイド →](aws-s3.md)
