# SFTP・Azure Blob・カスタム Flysystem サービス連携（`FlysystemService`）

[← 07 — クラウドストレージと動的設定](../07-cloud-storage-and-dynamic-config.md) | [メイン目次](../README.md)

> **このアプローチを選ぶべきケースは？**  
> **SFTP / FTP サーバー**、**Azure Blob Storage**、**WebDAV** などの非 S3 バックエンドへ接続したい場合や、`league/flysystem-bundle` 経由ですでにプロジェクトに設定済みの Flysystem ファイルシステムを再利用したい場合は、`driver: 'FlysystemService'` を使用します。

---

## 1. SFTP サーバーへの接続（`league/flysystem-sftp-v3`）

### ステップ 1: SFTP アダプターをインストールする

```bash
composer require league/flysystem-sftp-v3
```

### ステップ 2: `.env.local` に接続用の環境変数を追加する

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### ステップ 3: Symfony サービスを定義する（`config/services.yaml`）

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # SSH 鍵認証を使う場合は null にして $privateKey を指定
            $privateKey: null                 # 例: '%kernel.project_dir%/config/ssh/id_ed25519'
            $passphrase: null
            $port: '%env(int:SFTP_PORT)%'
            $useAgent: false
            $timeout: 10
            $maxTries: 3

    app.sftp.adapter:
        class: League\Flysystem\PhpseclibV3\SftpAdapter
        arguments:
            $connectionProvider: '@app.sftp.connection_provider'
            $root: '%env(SFTP_ROOT)%'

    app.sftp.storage:
        class: League\Flysystem\Filesystem
        # 補足: public: true は不要です。FlysystemServiceLocatorPass がこのサービスを安全なロケーターへ自動登録します。
        arguments:
            $adapter: '@app.sftp.adapter'
```

### ステップ 4: `liveux_file_manager.yaml` にボリュームを登録する

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP アーカイブサーバー'
            icon: 'sftp'              # 組み込み SFTP SVG アイコン
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # DownloadController + RBAC Voter 経由で安全にストリーミング配信
            cache_ttl: 30             # SFTP 内のファイルは外部から変更される可能性があるため短めの TTL を設定
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **動的な `ConfigProviderInterface` を使用する場合:** YAML で宣言する代わりにデータベースからサービス ID を動的に返す場合は、対象の Flysystem サービスに `tags: ['liveux_file_manager.storage']`（または `flysystem.storage`）タグを付与してください。この場合も `public: true` は不要です。

---

## 2. 既存の `league/flysystem-bundle` サービスを再利用する（Azure Blob、WebDAV など）

プロジェクトですでに `league/flysystem-bundle`（例: `azure.storage` や `webdav.storage`）を使用している場合は、`driver_options.service` に既存のサービス ID を指定するだけで連携できます。

```yaml
liveux_file_manager:
    volumes:
        azure_blobs:
            label: 'Azure Blob Storage'
            icon: 'cloud'
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: 'https://myaccount.blob.core.windows.net/media'
            private: false
            cache_ttl: 120
            driver_options:
                service: 'azure.storage'  # League\Flysystem\FilesystemOperator を実装する任意のサービス
```

---

## 3. 外部から削除されたフォルダーと `remember_last_dir`

たとえば、ユーザーが前回 SFTP サーバーやクラウドバケット上の `/projects/2026/draft` で作業していて、その後に別のユーザーが FTP / SFTP クライアントからそのフォルダーを削除したとします。
- ページを再読み込みしてもファイルマネージャーはクラッシュせず、存在する最も近い親ディレクトリ（`/projects/2026`、またはボリュームのルート）へ自動的にフォールバックします。
- ツールバーの **更新（`↻`）** ボタンをクリックすると、`?refresh=1` により常にキャッシュをバイパスして SFTP またはクラウドストレージから最新の一覧を取得します。

---

## 4. 接続テストを実行する

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← 前へ: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — クラウドストレージ目次](../07-cloud-storage-and-dynamic-config.md)
