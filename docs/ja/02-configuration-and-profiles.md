# 02 — 設定とプロファイル（全パラメーターリファレンス＆レシピ集）

[← 前へ: 01 — 要件・依存関係とインストール](01-requirements-and-installation.md) | [ドキュメント一覧](README.md) | [次へ: 03 — 利用モードと JavaScript API →](03-usage-modes-and-js-api.md)

このガイドでは、`config/packages/liveux_file_manager.yaml` の **全オプション**、デフォルト値、変更すべきタイミング、そしてそのままコピー＆ペーストして本番で使える **5 つの実践レシピ** を解説します。

---

## 30 秒でわかる全体構造: `defaults`、`volumes`、`profiles`

設定ファイルは **3 つの明確なレイヤー** に分かれています。

1. **`defaults`（グローバルデフォルト）:** すべてのプロファイルに継承される共通のベース設定（テーマ、ロケール、カラーモード、キャッシュ、メタデータドライバー、監査ログ、AI デフォルトなど）。個別プロファイルで上書きしない限り、ここでの値が全体に適用されます。
2. **`volumes`（ストレージディスク / バケット）:** **ファイルを物理的にどこへ保存するか** を定義します（ローカルディレクトリ、AWS S3、Supabase Storage、Cloudflare R2、Backblaze B2、MinIO、Google Cloud Storage、またはカスタム Flysystem サービス）。
3. **`profiles`（用途別プロファイル）:** 特定の画面やワークフローごとの **ルール、UI レイアウト、権限** を定義します。たとえば以下のように使い分けられます。
   - `product_catalog` は `1:1` の正方形の商品画像のみを受け入れ、自動的に WebP へ変換する。
   - `customer_docs` は非公開の Supabase S3 バケットに接続し、PDF のみを許可し、ユーザーごとに専用フォルダーへ分離する。
   - `editor_picker` は TinyMCE 向けにダブルクリックで即選択できる軽量なモーダルピッカーを開く。

---

## 1. `defaults` — グローバルデフォルト

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = Symfony のアクティブなロケールを継承（フォールバックは 'en'）
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| パラメーター | デフォルト | 機能・役割 | 変更すべきケース |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | `volumes` を使わずインラインで `path` を指定する省略記法プロファイルのデフォルトルートディレクトリ。 | ローカルファイルを `public/media` など別のフォルダーに保存したい場合。 |
| `theme` | `'bootstrap5'` | 全プロファイルのデフォルト UI テーマ（`bootstrap5`、`tailwind`、`shadcn`、`flowbite`、`daisyui`、`tabler`、`preline`、`material`、`unstyled`、またはカスタムテーマ名）。 | アプリのデザインシステムに合わせたい場合（例: Tailwind プロジェクトでは `'shadcn'` や `'tailwind'`）。 |
| `locale` | `null` | `null` の場合、Symfony のリクエスト / Translator のアクティブなロケールを自動継承し、**12 言語の組み込み UI 翻訳**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）を自動適用します。BCP-47 や POSIX 形式（`de_DE.UTF-8`、`zh-Hans-CN`）も正規化し、アラビア語などの RTL 言語では自動的に `dir="rtl"` を有効化、未対応ロケールは `'en'` へフォールバックします。 | すべてのユーザーを特定の UI 言語に固定したい場合（例: `'ja'`、`'en'`、`'de'`）。 |
| `color_scheme` | `'auto'` | カラーモード: `'auto'`（`.dark`、`[data-bs-theme="dark"]`、`[data-theme="dark"]` を自動検出）、`'light'`（常にライト）、`'dark'`（常にダーク）。 | ライト専用またはダーク専用の管理画面テーマに統一したい場合。 |
| `z_index` | `1050` | モーダル、オフキャンバスドロワー、Lightbox、コンテキストメニューの基準スタッキングコンテキスト（`--liveux-fm-z-base`、`>= 1`）。 | 管理画面のナビゲーションバーが `z-index: 9999` など高い値を使用している場合に `10050` などへ引き上げます。 |
| `auto_inject_dialog_host` | `true` | `window.LiveUXFileManager.open()` に必要な `<dialog>` ホストをフル HTML ページの `</body>` 直前に自動挿入します（`base.html.twig` の編集不要）。 | 特定のレイアウトにのみ `@LiveUXFileManager/components/DialogHost.html.twig` を手動配置したい場合に `false` にします。 |
| `dialog_host_profile` | `'default'` | プロファイル未指定時に自動挿入された `DialogHost` が使用するデフォルトプロファイル。 | メインのプロファイル名が `default` 以外（例: `admin_media`）の場合。 |
| `cache.enabled` | `true` | ディレクトリ一覧とフォルダーツリー用のサーバーサイド PSR-6 キャッシュ。 | ローカルデバッグ中に無効化したい場合に `false`（プロファイル・ボリューム単位でも無効化可能）。 |
| `cache.pool` | `'cache.app'` | Symfony PSR-6 キャッシュプール（Redis、Filesystem、APCu など）。`<pool>.taggable` が利用可能な場合は自動的にタグベースのピンポイント無効化を使用します。 | ファイルマネージャーのキャッシュを専用の Redis プールに分離したい場合。 |
| `cache.ttl` | `3600` | キャッシュの有効期間（秒、デフォルトは 1 時間）。アップロード・削除・移動・名前変更などの変更操作時には、対象ディレクトリのみ自動で無効化されます。 | FTP やクラウド管理画面など外部からのファイル変更が多い場合は `60`〜`300` 秒に短縮します。 |
| `metadata.enabled` | `true` | フォルダーの色・アイコン・ピン留め状態、およびファイルの SEO メタデータ（`alt`、`title`、`caption`、`tags`、`focalPoint`）を保存します。 | 編集メタデータが不要な最小限のファイルピッカーでは `false` に設定します。 |
| `metadata.driver` | `'json_file'` | メタデータの保存バックエンド: `'json_file'`（単一サーバー向けローカルファイル）、`'doctrine'`（MySQL・MariaDB・PostgreSQL・SQLite 対応のマルチサーバー / ロードバランサー向け組み込み DBAL ストレージ）、または `'none'`。 | Kubernetes、ロードバランサー、Docker Swarm など複数レプリカ構成では `'doctrine'` に切り替えます（[第 13 章](13-doctrine-integration.md) を参照）。 |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | `json_file` ドライバーの保存先パス。MIME 制限のあるクラウドバケット向けのゴミ箱サイドカーファイル（`trash-meta.json` または `<name>.trash-meta.json`）も同じディレクトリに保存されます。 | 共有 NFS や別ボリュームへ移動したい場合。 |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | `driver: 'doctrine'` 利用時の DBAL 接続名、EntityManager、テーブル名（`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`）、およびテーブル自動作成フラグ。 | 本番環境で Doctrine Migrations によりスキーマ管理する場合は `auto_create_table: false` に設定します（[第 13 章](13-doctrine-integration.md) を参照）。 |
| `audit_log.enabled` | `false` | 全 9 種類のファイル / フォルダー変更操作（アップロード、削除、移動、コピー、名前変更、複製、フォルダー作成、ゴミ箱からの復元、完全削除）の構造化 PSR-3 監査ログ（`user`、`ip`、`path`、`volume`）を記録します。 | コンプライアンス要件があり「誰が・いつ・何を削除したか」を追跡したい場合に `true` にします。 |
| `audit_log.level` | `'info'` | 監査ログの PSR-3 ログレベル（`debug`、`info`、`notice`、`warning`）。 | Monolog のルーティング・フィルタリング設定に合わせて調整します。 |
| `ai.default_model` | `'gpt-4o-mini'` | オプションの `symfony/ai-bundle` 導入時に使用されるデフォルトのビジョン / テキストモデル（[第 09 章](09-symfony-ai-integration.md) を参照）。 | Gemini、Claude、またはローカルの Ollama モデルへ切り替えたい場合。 |
| `ai.platform_service` | `null` | カスタム Symfony AI `PlatformInterface` サービス ID（`null` の場合は自動登録されたデフォルトプラットフォームを使用）。 | アプリ内で複数の Symfony AI プラットフォームを定義している場合。 |

---

## 2. `volumes` — ストレージボリューム（ローカル＆クラウド）

1 つのプロファイルに複数のボリュームを同時にマウントできます（たとえばローカルディスクと Supabase / R2 / S3 の併用）。ユーザーはローカルフォルダーからクラウドバケットへ直接ドラッグ＆ドロップでファイルを移動・コピーできます。

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'ローカルディスク'
            icon: 'disk'              # オプション（省略時は driver / endpoint から自動判定）
            group: 'local'            # オプション（null の場合、LocalFileSystem は 'local'、S3 / Flysystem は 'network'）
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # オプションのボリューム別 TTL（0 = 無効）

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # endpoint からも自動判定されます
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # クラウド管理画面での外部変更を 60 秒以内に反映
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| パラメーター | デフォルト | 機能・役割 | 変更すべきケース |
| :--- | :--- | :--- | :--- |
| `label` | ボリュームキー名 | サイドバー、パンくずリスト、ボリューム切替に表示される名称。 | 分かりやすい表示名にしたい場合（例: `'商品メディア CDN'`）。 |
| `icon` | `null` *（自動）* | サイドバーの SVG アイコン。`null` の場合、`driver` と `endpoint` から自動判定されます（`disk`、`aws_s3`、`cloudflare_r2`、`supabase`、`backblaze_b2`、`minio`、`gcs`、`sftp`、`cloud`）。 | カスタムアイコンセットの `<dir>/ui/my_icon.svg` を指定したい場合。 |
| `group` | `null` *（自動）* | サイドバーのセクション分類。`null` の場合、`LocalFileSystem` は `'local'`、`AwsS3` / `FlysystemService` は `'network'` になります。 | NFS マウントを `'network'` に分類したり、クラウドディスクを `'local'` 側に表示したい場合。 |
| `driver` | `'LocalFileSystem'` | ストレージバックエンド: ローカルファイルシステム（`LocalFileSystem`）、S3 互換オブジェクトストレージ（`AwsS3`）、既存の Flysystem サービス（`FlysystemService`）。 | S3 / R2 / Supabase / B2 / MinIO / GCS は `'AwsS3'`、SFTP / Azure / Dropbox は `'FlysystemService'` を指定します。 |
| `path` | `null` | `LocalFileSystem` 用の物理ディレクトリパス（`{user_id}`、`{Y}`、`{m}`、`{d}` に対応）。 | カスタムルートやユーザーごとの分離パス（`.../users/{user_id}`）を設定したい場合。 |
| `url_prefix` | `null` | ブラウザーが公開ファイルへアクセスする際の公開 URL プレフィックス（`/uploads` や `https://cdn.example.com`）。`private: true` の場合は無視されます。 | CDN や公開 S3 / R2 / Supabase バケットから配信する場合。 |
| `private` | `false` | `true` にするとファイルは直接の公開 URL で露出されず、すべてのダウンロード・プレビュー・サムネイルが Symfony Voter の認可チェック経由（`DownloadController`）でストリーム配信されます。 | 請求書、本人確認書類、契約書、非公開の顧客ファイルでは **必ず `true`** にしてください。 |
| `cache_ttl` | `null` *（継承）* | このボリューム専用のキャッシュ TTL（秒）。`0` でこのボリュームのキャッシュを無効化、`null` でプロファイル / defaults から継承します。 | ローカルディスクは `3600` 秒キャッシュしつつ、クラウドバケットは `60` 秒や `0` に抑えたい場合。 |
| `driver_options` | `[]` | **AwsS3:** `bucket`、`region`、`endpoint`、`key`、`secret`、`token` *（一時 STS セッショントークン）*、`version` *（デフォルト `'latest'`）*、`prefix`、`use_path_style_endpoint`、`acl`、`disable_acl` *（カスタムエンドポイントでは自動で `true`、標準 AWS S3 ではデフォルト `false` — Bucket owner enforced バケットでは明示的に `true` を指定）*、`retain_visibility`、`request_checksum_calculation`、`response_checksum_validation`、`ca_bundle`。**FlysystemService:** `service`。 | 各プロバイダー別の詳細設定は [第 07 章](07-cloud-storage-and-dynamic-config.md) を参照してください。 |

---

## 3. `profiles` — プロファイルパラメーター辞典

### 3.1. 基本ストレージ・フィルタリング・容量上限（クォータ）
| パラメーター | デフォルト | 機能・役割 | 変更すべきケース |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *（defaults 継承）* | このプロファイルの UI およびエラーメッセージのロケールを上書きします（`'ja'`、`'en'`、`'tr'`、`'fr'`、`'de'`、`'ar'` など。`null` の場合は `defaults.locale` → Symfony リクエストロケール → `'en'` の順で決定）。 | 同じアプリ内で管理画面と顧客画面の言語を分けたい場合。 |
| `volumes` | `[]` | このプロファイルからアクセス可能なボリュームキーのリスト（例: `['local_uploads', 'supabase_s3']`）。先頭のボリュームが初期表示されます。 | 1 つ以上のローカル / クラウドボリュームを割り当てる場合。 |
| `ignore_dot_files` | `true` | ドットファイル（`.env`、`.htaccess`、`.DS_Store`、`.trash`）を一覧から隠し、直接アクセスもブロックします。 | セキュリティ上 `true` のまま維持してください。 |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | 正規表現またはワイルドカードに一致するファイル・フォルダーを一覧から隠し、直接アクセスも禁止します。 | `/^cache$/i` や `/^backups$/i` といった内部フォルダーを隠したい場合。 |
| `sort_by` | `'name'` | デフォルトの並び替え基準: `'name'`、`'date'`、`'size'`、または `'type'`。 | 最新のアップロードを先頭に表示したい場合は `'date'` + `sort_direction: 'desc'` を指定します。 |
| `sort_direction` | `'asc'` | 並び順: `'asc'`（昇順 A→Z / サイズ小）または `'desc'`（降順 Z→A / 新着順）。 | メディアギャラリーや記事用ライブラリでは `'desc'` が便利です。 |
| `folders_first` | `true` | フォルダーを常にファイルより上に表示します。 | 完全にフラットな名前順・日付順にしたい場合は `false`。 |
| `storage_quota` | `null` | プロファイル全体で許可するディスク使用量の上限（例: `'500M'`、`'2G'`）。上限に達するとサーバー側でアップロードが拒否され、サイドバーに使用量バーが表示されます。 | マルチテナント SaaS やユーザー単位（`{user_id}`）の容量制限に必須です。 |
| `upload_subfolder` | `null` | アップロードされたファイルを現在のディレクトリ配下の動的サブフォルダーへ自動振り分けするテンプレート（例: `'{Y}/{m}'`）。 | アップロードファイルを `2026/11` のような年月フォルダーへ自動整理したい場合。 |

### 3.2. アップロード・分割アップロード（`chunk_upload`）・重複検出（`duplicate_detection`）
| パラメーター | デフォルト | 機能・役割 | 変更すべきケース |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *（安全な全形式）* | 許可する MIME タイプのホワイトリスト（例: `['image/jpeg', 'image/png', 'image/webp']`）。空の場合は `upload_deny` に該当しない安全な形式がすべて許可されます。 | 画像専用フォームや PDF 専用フォームでは必ず特定の MIME タイプに限定してください。 |
| `upload_deny` | **47 種の危険な MIME タイプ** | 厳格にブロックする MIME タイプ（PHP、HTML、SVG 以外の XML、JS、Shell、EXE、Java、CGI など。マジックバイトによりサーバー側で検証）。 | ポリシー上さらに禁止したい MIME タイプを追加する場合。 |
| `upload_max_size` | `'10M'` | 単一ファイルの **結合後の最大サイズ**（例: `'200M'`、`'1G'`）。分割アップロードが有効なら、PHP の `2M` 制限を大きく超えるファイルもこの上限までスムーズにアップロードできます。 | 動画やカタログ PDF 用プロファイルでは `'200M'`〜`'500M'` に引き上げ、アバター用では `'2M'` に下げます。 |
| `overwrite_policy` | `'rename'` | 同名ファイルが存在する場合の挙動: `'rename'`（`photo-1.jpg` に連番付与）、`'overwrite'`（上書き）、または `'reject'`（拒否）。 | 誤ったデータ上書きを防ぐため `'rename'` の維持を推奨します。 |
| `chunk_upload.enabled` | `true` | 大容量ファイルを小さなチャンクへ分割してアップロードします。一時停止 / 再開 / 確認付きキャンセル、一時的なネットワーク障害時の自動リトライに対応しています。 | 1 MB 未満の極小ファイルしか扱わない特殊なケース以外では有効のままにします。 |
| `chunk_upload.chunk_size` | `'2M'` | 目標チャンクサイズ。PHP の `upload_max_filesize` および `post_max_size` の **80%** に自動クランプされます（PHP 上限 2M の場合は 1.60 MB）。 | PHP 上限が高いサーバー（32M+ など）で大ファイル転送を高速化したい場合は `'5M'` に引き上げます。 |
| `chunk_upload.parallel_uploads` | `3` | 同時並行アップロード数の上限（`1`〜`10`）。 | リソースが限られるサーバーでは `2`、高速なクラウド / CDN 回線では `4`〜`5` に設定します。 |
| `chunk_upload.max_retries` | `3` | ネットワーク切断時のチャンク単位の指数バックオフ再試行回数（`0`〜`10`）。 | モバイル環境や不安定な回線では `3`〜`5` が適しています。 |
| `duplicate_detection.enabled` | `false` | SHA-256 コンテンツハッシュにより重複ファイルのアップロードを検出します。 | 担当者が同じ商品写真を何度もアップロードしてしまうのを防ぎたい場合に `true` にします。 |
| `duplicate_detection.policy` | `'warn'` | 重複検出時のアクション: `'warn'`（アップロードして警告表示）、`'reject'`（アップロードを拒否）、または `'reuse'`（重複保存せず既存ファイルを再利用して返す）。 | EC サイトや CMS では `'reuse'` にすることでストレージ容量を大きく節約できます。 |
| `duplicate_detection.max_scan_files` | `5000` | 重複検出のためにハッシュ化するファイル数の上限（`100`〜`50000`）。大規模アーカイブでの CPU / I/O スパイクを防ぎます。 | 大規模メディアライブラリでは `10000`〜`25000` に引き上げます。 |

### 3.3. 自動ファイル名正規化（`naming`）
| パラメーター | デフォルト | 機能・役割 | 変換例（`"Product Photo 2026.JPG"`） |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | アクセント付き文字・Unicode 文字・スペースをクリーンな URL フレンドリーな ASCII スラッグへ変換します。 | `product-photo-2026.jpg` |
| `naming.slugger_locale` | `null` | 音訳（トランスリテレーション）に使うロケール（`null` の場合はプロファイルのロケールを継承。例: `'de'` は `ä/ö/ü/ß`、`'tr'` は `İ/ı/ş/ğ/ç/ö/ü` を変換）。 | コンテンツの言語（`'ja'`、`'de'`、`'tr'`、`'fr'` など）に合わせて設定します。 |
| `naming.transformer` | `'none'` | 大文字・小文字 / 命名規則の変換: `'none'`、`'lowercase'`、`'uppercase'`、`'camel'`、`'snake'`、`'cameltitle'`、`'title'`、`'titleall'`、または `'folder'`（親フォルダー名をファイル名に採用！）。 | `'snake'` → `product_photo_2026.jpg`、`'folder'` → `tumblers-1.jpg` |
| `naming.prepend` / `append` | `''` | 固定テキストや動的トークン（`{Y}`、`{m}`、`{d}`、`{uniqid}`）を先頭または末尾へ付与します。 | `prepend: '{Y}{m}_'` → `202611_product.jpg` |
| `naming.apply_on_rename` | `true` | UI からユーザーがファイル名を変更した際にも `slugger` と `transformer` のルールを適用します。 | リネーム後もクリーンなファイル名を維持するため `true` を推奨します。 |
| `naming.apply_affixes_on_rename` | `false` | 名前変更のたびに `prepend` / `append` のトークンが二重付与されるのを防ぎます。 | `false` のままにします。 |
| `naming.allow_extension_change` | `false` | 名前変更時にユーザーが拡張子を変更すること（例: `.jpg` → `.php` や `.png`）を禁止します。 | セキュリティのため `false` のままにします。 |

### 3.4. 画像最適化（`image_optimization`）と画像制約（`image_constraints`）
- **`image_optimization`:** アップロード時にサーバー側で回転補正、EXIF 削除、リサイズ、圧縮を自動実行します。
- **`image_constraints`:** ルールを満たさない画像を **ブラウザー上で 0ms で即時拒否し、サーバー側でも再検証** します（例: 解像度の低いぼやけた画像や、正方形でない商品写真のブロック）。

| パラメーター | デフォルト | 機能・役割 | 活用シーン |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | アップロード時の画像最適化を有効化します（Imagick → GD の順で使用、両方ない場合はエラーを出さずスキップ）。 | ユーザーがスマートフォンで撮影した生写真をアップロードするプロファイルでは `true` にします。 |
| `image_optimization.auto_orient` | `true` | EXIF の回転タグを読み取り、横向きのスマホ写真を自動で正しい向きに回転します。 | モバイルからのアップロードでは `true` を維持します。 |
| `image_optimization.strip_exif` | `true` | GPS 位置情報やカメラの EXIF メタデータを削除します（プライバシー保護＋ファイルサイズ削減）。 | 公開サイト向けの画像では常に `true` を推奨します。 |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | アスペクト比を保ったまま、このサイズを超える画像を縮小します（`>= 100`）。 | Web 表示用画像では `1920`×`1920` がバランスの良い目安です。 |
| `image_optimization.convert_to_webp` | `false` | アップロードされた JPEG / PNG を自動で WebP へ変換します（ファイルサイズ 30〜60% 削減）。 | パフォーマンス重視のモダンな Web プロファイルでは `true` にします。 |
| `image_optimization.quality` | `85` | 出力圧縮品質（`10`〜`100`）。 | `80`〜`85` が画質とファイル容量の最適バランスです。 |
| `image_constraints.min_width` / `min_height` | `null` | 必須の最小解像度（ピクセル）。 | 粗い画像のアップロードを防止したい場合（例: ブログのアイキャッチ画像は最低 `1200`×`630`）。 |
| `image_constraints.max_width` / `max_height` | `null` | 許容する最大解像度（ピクセル。`image_optimization` 有効時は縮小 *後* にチェックされます）。 | バナーや印刷テンプレートなど厳密なサイズ規定がある場合。 |
| `image_constraints.aspect_ratio` | `null` | 必須のアスペクト比: `'1:1'`（正方形）、`'16:9'`（ワイドバナー）、`'4:3'`、`'3:4'`、`'1.91:1'` など。 | 正方形の商品カード（`'1:1'`）や動画・記事ヘッダー（`'16:9'`）。 |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | 目標比率からの許容誤差（`0.0`〜`0.5`、`0.05` = 5% 許容のため `1920×1080` も `1280×722` も通過）。 | ほとんどのケースで `0.05` が最適です。 |

### 3.5. サムネイル・ゴミ箱（`trash`）・ZIP（`zip`）・署名付き共有リンク（`share`）
| パラメーター | デフォルト | 機能・役割 |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | 画像のキャッシュ済みサムネイルを生成します: `'auto'`（Imagick → GD）、`'imagick'`、`'gd'`、`'liip_imagine'`、または `'none'`。クラウド上の画像も初回のみ取得してローカルにキャッシュします。 |
| `thumbnail.on_missing_driver` | `'fallback_original'` | GD も Imagick も利用できない場合の動作: `'fallback_original'`（元画像を表示）、`'icon'`（ファイルアイコンを表示）、または `'exception'`（例外を送出）。 |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | サムネイル解像度（`>= 32`）、出力形式（`webp`、`jpeg`、`png`）、フィット方法（`contain` = 全体表示、`cover` = 中央トリミング）。`ui.item_size: 'xl'` を使う場合は `480`×`360` 程度にすると鮮明です。 |
| `thumbnail.lazy_load` | `true` | グリッドカードの画像にネイティブの `loading="lazy"` を付与し、大量のファイルがあるフォルダーの初期描画を高速化します。 |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | 生成されたサムネイルをキャッシュするローカルディレクトリ。 |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | ファイルを即時削除せずゴミ箱フォルダーへ論理削除します。MIME 制限のあるクラウドバケットでは、自動的にローカルの `trash-meta.json` サイドカーへフォールバックします。 |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | 複数ファイル・フォルダーのワンクリック `.zip` ダウンロードを有効化します（PHP `ext-zip` が必要）。 |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | 7 層の Zip-Slip / Zip-Bomb 防御を備えた安全なアーカイブ展開。`allow_extract: false` の間は常にブロックされ、有効化すると `permissions.extract_zip` が自動的に `true` になります（必要に応じて `'ROLE_ADMIN'` や `false` で制限可能）。 |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Symfony の `UriSigner` を使用して有効期限付きの HMAC 署名共有 URL を生成します。`enabled: false` にすると、発行済みの共有リンクも即座に無効化されます。 |

### 3.6. ユーザーインターフェース（`ui`）— レイアウト・サイズ・ツールバー制御
| パラメーター | デフォルト | 機能・役割 | 変更すべきケース |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *（defaults 継承）* | プロファイル固有のテーマ（例: 管理画面は `'bootstrap5'`、顧客ポータルは `'shadcn'`）。 | アプリ内のセクションごとに見た目を切り替えたい場合。 |
| `ui.color_scheme` | `null` *（defaults 継承）* | プロファイル固有のカラーモード（`auto`、`light`、`dark`）。 | ダーク専用のワークスペースで `'dark'` に固定したい場合。 |
| `ui.icon_set` | `'classic'` | ファイル / フォルダーアイコンのスタイル: `classic`、`windows11`、`macos`、`linux`、`minimal`、`vscode`、またはカスタム SVG ディレクトリパス（`'%kernel.project_dir%/assets/fm-icons'`）。 | デスクトップ風の操作感には `'windows11'` や `'macos'`、独自ブランド SVG を適用したい場合はディレクトリパスを指定します。 |
| `ui.z_index` | `null` *（defaults 継承）* | プロファイル固有のオーバーレイ `z-index` 上書き。 | 高い `z-index` を持つエディターやドロワーと同じページに配置する場合。 |
| `ui.mobile_dialog_mode` | `'auto'` | モバイル画面（`<= 767.98px`）でのダイアログ表示形式: `'auto'`（モーダルと左右オフキャンバスは全画面、上下オフキャンバスは角丸ネイティブシート）、`'fullscreen'`（すべて全画面）、`'sheet'`（すべてネイティブボトムシート）。 | すべてのモバイルピッカーを `'sheet'` または `'fullscreen'` に統一したい場合。 |
| `ui.default_view` | `'grid'` | 初期表示レイアウト: `'grid'`（サムネイルカード）または `'list'`（並び替え可能なテーブル）。 | PDF / ドキュメント管理には `'list'`、ビジュアルギャラリーには `'grid'` が最適です。 |
| `ui.item_size` | `'md'` | グリッドカード幅とリストサムネイルサイズ: `'xs'`（`108px`）、`'sm'`（`132px`）、`'md'`（`158px`）、`'lg'`（`204px`）、`'xl'`（`260px`）、またはピクセル整数値（`96`〜`320`）。`<= 132px` では自動的にコンパクト余白へ切り替わります。 | ビジュアル確認には `'lg'` / `'xl'`、省スペースなピッカーには `'sm'` / `'xs'` を使用します。 |
| `ui.card_aspect_ratio` | `'4/3'` | カードプレビュー枠のアスペクト比: `'1/1'`（正方形）、`'4/3'`（標準）、`'16/9'`（ワイド / 動画）、`'3/4'`（縦長ポスター / カタログ）。 | 主に扱う素材の形状に合わせます（商品は `'1/1'`、動画・バナーは `'16/9'`）。 |
| `ui.folder_style` | `'tile'` | グリッド表示時のフォルダー表示スタイル: `'tile'`（Google ドライブ風の横長コンパクトタイルをファイルの上に配置）または `'card'`（エクスプローラー / Finder 風のファイルと同じ大きさのカード）。 | フォルダーをより大きく視覚的に目立たせたい場合は `'card'` に変更します。 |
| `ui.show_size_slider` | `true` | 設定メニュー内にリアルタイムの表示サイズスライダー（`96px`〜`320px`）とフォルダー表示スタイル切替を表示します。 | レイアウト密度を固定したい場合は `false` にします。 |
| `ui.volume_order` | `'local_first'` | サイドバーおよび切替メニューでのボリューム並び順: `'local_first'`、`'network_first'`、または `'configured'`（グループ区切りなしで YAML の `volumes: [...]` の記述順通りに表示）。 | クラウド中心のワークフローでは `'network_first'` や `'configured'` を使用します。 |
| `ui.show_folder_tree` | `true` | 左側のフォルダーツリーサイドバーの表示 / 非表示を切り替えます。 | 単一フォルダーの TinyMCE やアバターピッカーでは `false` にするとすっきりします。 |
| `ui.tree_lines` | `true` | フォルダーツリー内に階層ガイドラインを描画します。 | ミニマルなサイドバーにしたい場合は `false`。 |
| `ui.show_folder_item_count` | `true` | フォルダーの横に項目数バッジ（`12`）を表示します。認可ルールを尊重するため、閲覧権限のないサブフォルダーの件数が漏洩することはありません。 | フォルダーの中身をひと目で把握できるよう `true` を推奨します。 |
| `ui.folder_count_mode` | `'recursive_files'` | フォルダーバッジの集計方法: `'recursive_files'`（サブフォルダーを含む全ファイル数）、`'files'`（直下のファイル数のみ）、`'items'`（直下のファイル＋サブフォルダー数）。 | 直下ファイル数のみを表示したい場合は `'files'` を指定します。 |
| `ui.inspector_mode` | `'on_select'` | 右側の「プレビューと詳細」パネルの表示モード: `'on_select'`（項目選択時に表示）、`'always'`（常時表示）、`'hidden'`（非表示）。 | 横幅の狭いモーダルピッカーでは `'hidden'` にします。 |
| `ui.folder_open_on` | `'dblclick'` | フォルダーを開く操作: `'dblclick'`（シングルクリックで選択、ダブルクリックで開く — デスクトップ標準）または `'click'`（シングルクリックで即座に開く — Web / モバイル標準）。 | 管理画面で素早くクリック移動したい場合は `'click'` を使用します。 |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | 1 ページあたりのデフォルト表示件数（`6`〜`200`）と、ユーザーが選択できる件数候補。 | 大規模カタログでは `48` および `[24, 48, 96, 150]` に増やします。 |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | サイドバーのストレージ容量バーおよびクリックで開く「ストレージ使用状況と容量上限」詳細モーダルの表示制御。（非表示にしてもサーバー側の `storage_quota` 制限は有効のままです。） | UI に容量メーターを表示したくない場合は `show_quota: false` にします。 |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | インライン PDF Lightbox および HTML5 動画再生の可否を制御します（UI だけでなく `DownloadController` 側のサーバーでも強制されます）。 | ブラウザー内プレビューを無効化し直接ダウンロードを強制したい場合は `false` にします。 |
| `ui.remember_last_dir` | `true` | ユーザーが最後に開いていた **ボリューム＋ディレクトリ** を `localStorage` に記憶します（該当フォルダーが削除済みの場合は親フォルダーやルートへ自動復帰）。 | 毎回ルートディレクトリから開始したい場合は `false` にします。 |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | 削除時やドラッグ＆ドロップ移動時に確認モーダルを表示します。 | 素早く整理したい場合は `confirm_move: false` にできます。 |
| `ui.double_click_to_select` | `true` | モーダル / オフキャンバスのピッカーモードで、ファイルをダブルクリックすると即座に選択確定してダイアログを閉じます。 | ダブルクリックをプレビュー表示にしたい場合は `false` にします。 |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | 右クリックコンテキストメニュー、および空白部分の右クリック時に表示される `[ ← \| → \| ↑ \| ↻ ]` クイックナビゲーションバーを有効化します。 | 機能を制限したエンドユーザー向け画面では `false` にします。 |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | フォルダー間でのドラッグ＆ドロップ移動、および OS からのファイル / フォルダーのドラッグ＆ドロップアップロードを有効化します。 | 読み取り専用のアセットライブラリでは `false` にします。 |
| `ui.keyboard_shortcuts` | `true` | キーボードショートカット（`Ctrl+A`、`Ctrl+C/X/V`、`F2`、`Delete`、`Space`、`/`、`R`、`F`、`?` など）を有効化します。 | 親ページ側のグローバルショートカットと競合する場合は `false` にします。 |
| `ui.show_*` フラグ | *（大半が `true`、`show_perf_badge: false`）* | `show_perf_badge`、`show_nav_buttons`、`show_history_buttons`、`show_up_button`、`show_refresh_button`、`show_breadcrumbs`、`show_search`、`show_type_filter`、`show_view_toggle`、`show_pagination`、`show_per_page_selector`、`show_sort`、`show_fullscreen_button`、`show_settings_button`、`show_footer`、`show_context_nav`。 | ツールバーの各ボタンを個別にオン / オフして画面を簡素化できます。*（注: 設定メニュー内の「バージョン情報」と「GitHub」は組み込みのクレジット項目のため YAML では無効化できません。）* |

### 3.7. プロファイル別キャッシュ（`cache`）・セキュリティ（`security`）・権限（`permissions`）・AI・使用状況ガード

#### キャッシュ（`cache`）
| パラメーター | デフォルト | 説明 |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *（defaults 継承）* | このプロファイルのキャッシュを有効 / 無効にします。プロファイルで `cache.enabled: false` を設定した場合、`volume.cache_ttl` が設定されていてもキャッシュは強制有効化されません。 |
| `cache.ttl` | `null` *（defaults 継承）* | TTL の優先順位: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**。 |

#### セキュリティ（`security`）
| パラメーター | デフォルト | 説明 |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | `FileManagerVoter` およびカスタム Voter による RBAC チェックを有効にします。本番環境では必ず `true` を維持してください。 |
| `security.csrf_protection` | `true` | すべての `POST` リクエストで有効な `X-LiveUX-FM-CSRF` ヘッダーを必須にします。 |
| `security.sanitize_svg` | `true` | アップロード・編集された SVG を厳格な 55 タグ許可リスト（`SvgSanitizer`）でサニタイズします。 |
| `security.allow_remote_url_import` | `false` | アップロードモーダルに **「URL から取得」** タブを表示します（`RemoteUrlImporter` による SSRF / DNS リバインディング対策済み）。 |
| `security.audit_log` | `null` *（defaults 継承）* | このプロファイルの PSR-3 監査ログを上書きします（`true` / `false` / `null`）。 |

#### 17 種類の詳細権限（`permissions`）
> [!WARNING]
> **重要: `permissions` 配下での `true` の意味**  
> 権限に **`true`** を設定しても **「公開 / 匿名アクセス許可」という意味ではありません**。`FileManagerVoter` は **`true` をログイン済みユーザー（`$token->getUser() !== null`）にのみ許可** し、匿名リクエストは拒否します。  
> また、**`permissions.view`** はマスターゲートとなっており、ユーザーが `view` を持たない場合は他のすべての操作（`upload`、`delete`、`download` など）も自動的に拒否されます。

各権限には次の 4 形式のいずれかを指定できます。
- `true` → すべての認証済みユーザー
- `false` → 全員禁止（操作を完全に無効化）
- `'ROLE_ADMIN'` → 指定ロールを持つユーザーのみ
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → 列挙したいずれかのロールを持つユーザー

| 権限キー | デフォルト | 制御対象となる操作 |
| :--- | :--- | :--- |
| `view` | `true` | ディレクトリ一覧、フォルダーツリー、プレビュー、および **他の全権限の前提条件**。 |
| `upload` | `true` | 通常 / 分割アップロード、画像エディターからの保存、URL からのインポート。 |
| `create_folder` | `true` | 新しいフォルダーの作成。 |
| `rename` | `true` | ファイルおよびフォルダーの名前変更。 |
| `clone` | `true` | ファイルまたはフォルダーの複製。 |
| `move` | `true` | ドラッグ＆ドロップまたは切り取り / 貼り付けによる移動。 |
| `copy` | `true` | コピー / 貼り付けまたは移動 / コピーモーダルによるコピー。 |
| `delete` | `true` | ファイル / フォルダーの削除（ゴミ箱への移動または完全削除）。 |
| `trash_restore` | `true` | ゴミ箱からの項目復元。 |
| `trash_purge` | `true` | ゴミ箱からの完全削除および「ゴミ箱を空にする」。 |
| `download` | `true` | 単一ファイルのダウンロード、PDF / 動画ストリーミング、ファイルリンクのコピー。 |
| `download_zip` | `true` | 複数選択項目の `.zip` 一括ダウンロード。 |
| `extract_zip` | `null` *（自動）* | `zip.allow_extract: false` の間は強制 `false`。`zip.allow_extract: true` にすると自動的に `true` になります（必要に応じて `'ROLE_ADMIN'` 等で制限可能）。 |
| `share` | `true` | 有効期限付き HMAC 署名共有リンクの生成。 |
| `clear_cache` | `true` | 設定メニューからのキャッシュクリアおよび `?refresh=1` / `?fallback=1` の強制実行。 |
| `edit_metadata` | `true` | ファイルの `alt` / `title` / `caption` / `tags` / フォーカルポイント編集、およびフォルダーの色・アイコン・ピン留め。 |
| `ai_enrich` | `true` | **✨ AI で自動提案** の実行（`view` + `edit_metadata` + `ai_enrich` が必要）。 |

#### AI メタデータ補完（`ai`）＆アセット使用状況ガード（`usage_guard`）
| パラメーター | デフォルト | 説明 |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | AI メタデータ提案を有効化します。`locale` を指定すると出力言語を制御できます（例: `'ja'`、`'en'`、`'de'`）。 |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | 提案するタグ数の上限（`1`〜`20`）、および AI へ送信する前の画像縮小上限（`256`〜`2048` px、ビジョントークン消費を節約）。 |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | `private: true` ボリュームのファイルが外部 AI API へ送信されるのを防止します。アップロード後の自動補完も設定可能です（[第 09 章](09-symfony-ai-integration.md) を参照）。 |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | データベースから参照されているアセットに対する **`delete`・`rename`・`move`・`overwrite`** 操作を警告またはブロックします（[第 10 章](10-asset-usage-guard.md) を参照）。 |

---

## 4. 本番ですぐ使える 5 つのプロファイルレシピ（コピペ対応）

実務で最もよく使われる 5 つの構成パターンをそのまま導入できる形でまとめました。

### レシピ 1: EC サイト商品カタログ（正方形カード + WebP 自動変換 + 重複再利用）
> **目的:** 鮮明な `1:1` 正方形の商品写真のみを受け入れ、スマホ撮影写真を自動でリサイズ＆ WebP 変換し、重複アップロード時は既存ファイルを再利用し、公開中商品に紐づく画像の誤削除をブロックする。

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'ja'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # 204px の大きめ商品カード
                card_aspect_ratio: '1/1' # 正方形ショーケース比率
                folder_style: 'card'     # フルサイズのカテゴリーフォルダーカード
                show_folder_item_count: true
```

### レシピ 2: ブログ / ニュースのアイキャッチ画像（`16:9` バナー + AI 代替テキスト）
> **目的:** `16:9` の高解像度ワイドカバー画像（最小 `1200×630`px）のみを許可し、アップロードを自動的に `2026/11` フォルダーへ整理し、AI で SEO 代替テキストとタグを生成する。

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # 最新のカバー画像を先頭に表示
            ai:
                enabled: true
                locale: 'ja'
                max_tags: 6
            ui:
                item_size: 'xl'           # 260px のワイドカバープレビュー
                card_aspect_ratio: '16/9' # ワイドバナーのアスペクト比
```

### レシピ 3: 顧客ドキュメント＆ PDF 保管庫（非公開 Supabase / S3 + ユーザー別分離）
> **目的:** 各顧客の PDF 請求書を非公開の S3 互換バケット上でユーザーごとに分離し、直接の外部 URL アクセスを遮断し、クラウド一覧を 60 秒ごとに更新し、すべての操作を監査ログに記録する。

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: '顧客 PDF 保管庫'
            group: 'network'
            driver: 'AwsS3'
            private: true          # 未認証の直接 URL アクセスを禁止
            cache_ttl: 60          # クラウド側の外部変更を 60 秒以内に反映
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # 各ユーザーは自分のフォルダーのみ閲覧可能
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # PDF 管理に適した並び替え可能なリスト表示
                item_size: 'sm'          # コンパクトな行表示
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### レシピ 4: 高速リッチテキストエディター用ピッカー（TinyMCE / CKEditor / Tiptap）
> **目的:** 画像をダブルクリックすると即座にエディターへ挿入される、軽量で集中しやすいモーダルピッカー。

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### レシピ 5: 読み取り専用のパートナー / プレスキットライブラリ
> **目的:** パートナー企業がロゴやカタログを閲覧し、単体ダウンロード・ZIP 一括ダウンロード・署名付き共有リンク生成のみを行えるようにし、アップロード・編集・削除をすべて禁止する。

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 日間
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. 動的パスプレースホルダー（`DynamicPathResolver`）

ローカルの `path`、`url_prefix`、クラウドの `driver_options.prefix`、およびプロファイルの `upload_subfolder` で動的プレースホルダーを利用できます。

| プレースホルダー | 説明 | 出力例 |
| :--- | :--- | :--- |
| `{user_id}` | 認証済み Symfony ユーザー向けの、衝突しないファイルシステム安全な識別子（未認証時は `guest`）。 | `42` または `alice_a--h-9f1c2e4b...` |
| `{Y}` | 4 桁の西暦 | `2026` |
| `{m}` | 2 桁の月 | `11` |
| `{d}` | 2 桁の日 | `25` |

```yaml
volumes:
    user_space:
        label: 'マイパーソナルスペース'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **衝突しないマルチテナント `{user_id}` 分離:**  
> `DynamicPathResolver` は、メールアドレス（`alice.a@example.com` と `alice_a@example.com`）、大文字・小文字違い（`Alice` と `alice`）、Unicode ユーザー名（日本語・アラビア語・中国語・トルコ語）、予約語（`guest`、`user`、`--h-` で終わる識別子）、および Windows デバイス予約名（`con`、`nul`、`com1`..`9`、`lpt1`..`9`）に対して、**SHA-256 サフィックス付きの重複しない `<slug>--h-<16-hex>`** ディレクトリキーを生成します。異なる 2 人のユーザーが同じフォルダーへ衝突することは決してありません。
>
> **CLI コマンドでの挙動:** コンソールコマンド（`doctor --check-remote`、`stats`、`trash:purge`、`thumbs:warmup` など）は、`{user_id}` を含むボリュームを自動的にスキップするため、意図しない `guest/` フォルダーが作られる心配はありません。

---

## 6. 完全な YAML スキーマリファレンス（全有効オプション）

以下の YAML は、`Configuration.php` で定義されている **すべての有効な設定キー** をデフォルト値および解説コメント付きで網羅したリファレンスです。実際のプロジェクトではカスタマイズしたいキーだけを記述してください。省略した項目は自動的にデフォルト値が適用されます。

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) GLOBAL DEFAULTS — 上書きされない限り全プロファイルへ継承
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # 省略記法の単一ディスクプロファイル用デフォルトルート
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = Symfony リクエスト / Translator ロケール（12 言語内蔵、フォールバック 'en'）| 'ja' | 'en' | 'tr' | ...
        z_index: 1050                                      # オーバーレイ・ポップオーバー・モーダルの基準 z-index（>= 1）
        auto_inject_dialog_host: true                      # フル HTML レスポンスへモーダル / オフキャンバス用 DialogHost を自動挿入
        dialog_host_profile: 'default'                     # 自動挿入される DialogHost のデフォルトプロファイル

        audit_log:
            enabled: false                                 # 全 9 種のファイル / フォルダー変更操作を PSR-3 チャンネルへ記録
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # プロファイルで未指定時のデフォルト AI モデル
            platform_service: null                         # カスタム Symfony AI PlatformInterface サービス ID（null = 自動）

        cache:
            enabled: true                                  # グローバルなディレクトリ / ツリー PSR-6 キャッシュ切替
            pool: 'cache.app'                              # Symfony PSR-6 キャッシュプールサービス ID
            ttl: 3600                                      # グローバルキャッシュ TTL（秒、0 = 無効）

        metadata:
            enabled: true                                  # フォルダー色・アイコン・ピン留め、ファイルの alt / title / caption / tags / focalPoint
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # json_file ドライバーの保存ファイル
            connection: null                               # doctrine ドライバー用の任意 DBAL 接続名（null = entity_manager の接続を使用）
            entity_manager: 'default'                      # doctrine ドライバー用の EntityManager / 接続名
            table: 'liveux_fm_metadata'                    # doctrine ドライバー用の DBAL テーブル名（第 13 章を参照）
            auto_create_table: true                        # テーブル未作成時に自動作成（マイグレーション管理時は false を推奨）

    # =========================================================================
    # 2) STORAGE VOLUMES — ローカル＆クラウドファイルシステム定義
    # =========================================================================
    volumes:
        local_uploads:
            label: 'ローカルストレージ'                      # サイドバー＆パンくずリストの表示名
            icon: null                                     # null = 自動 | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = 自動（LocalFileSystem->local、AwsS3/Flysystem->network）| local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # 物理パス（{user_id}、{Y}、{m}、{d} に対応）
            url_prefix: '/uploads'                         # ブラウザー向け公開 URL プレフィックス（private: true の場合は null）
            private: false                                 # true = すべてのファイルを RBAC 保護の DownloadController 経由で配信
            cache_ttl: null                                # ボリューム別キャッシュ TTL（秒、null = 継承、0 = 無効）
            driver_options: []                             # LocalFileSystem では空配列

        cloud_s3_example:
            label: 'クラウド S3 / R2 / Supabase'
            icon: 'supabase'                               # resources/icons/_ui/supabase.svg に対応
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # 公開バケット / CDN の URL（private: true の場合は null）
            private: false
            cache_ttl: 60                                  # クラウド上の外部変更を 60 秒ごとに反映
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # バケット名
                region: '%env(AWS_S3_REGION)%'             # リージョン（Cloudflare R2 は 'auto'）
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # AWS 以外向けのカスタム S3 エンドポイント URL
                prefix: 'media/{Y}'                        # バケット内の任意ルートプレフィックス（動的プレースホルダー対応）
                use_path_style_endpoint: true              # Supabase / MinIO / R2 / B2 では true
                key: '%env(AWS_S3_KEY)%'                   # S3 アクセスキー ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 シークレットアクセスキー
                token: null                                # 任意の AWS STS 一時セッショントークン
                version: 'latest'                          # AWS S3 API バージョン（デフォルト: 'latest'）
                ca_bundle: null                            # 任意のカスタム CA バンドル .pem パス（Windows の cURL error 60 対策）
                disable_acl: true                          # カスタムエンドポイントでは自動 true、AWS S3 の Bucket owner enforced では明示的に true
                retain_visibility: false                   # カスタムエンドポイントでは自動 false（x-amz-acl 送信を回避）
                request_checksum_calculation: 'when_required'  # AWS SDK >= 3.337 互換設定（カスタムエンドポイントでは自動適用）
                response_checksum_validation: 'when_required'  # AWS SDK >= 3.337 互換設定（カスタムエンドポイントでは自動適用）

        custom_flysystem_example:
            label: 'SFTP アーカイブ'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # League\Flysystem\FilesystemOperator のサービス ID

    # =========================================================================
    # 3) PROFILES — 用途別の独立したルールセット
    # =========================================================================
    profiles:
        full_reference:
            # --- マウントボリューム・ロケール・単一ディスク省略記法 ---
            locale: null                                   # null = defaults.locale を継承 | 'ja' | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # 空の場合、下の driver / path から暗黙のボリュームを作成
            driver: 'LocalFileSystem'                      # volumes: [] の場合のみ使われる省略記法ドライバー
            path: '%kernel.project_dir%/public/uploads'    # volumes: [] の場合のみ使われる省略記法パス
            url_prefix: '/uploads'                         # volumes: [] の場合のみ使われる省略記法 URL プレフィックス
            private: false                                 # volumes: [] の場合のみ使われる省略記法非公開フラグ
            driver_options: []                             # volumes: [] の場合のみ使われる省略記法ドライバーオプション

            # --- 一覧表示・除外ルール・デフォルト並び順 ---
            ignore_dot_files: true                         # ドットファイル（.env、.htaccess、.git）を非表示かつブロック
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # 一覧非表示＆直接アクセス禁止とする正規表現 / ワイルドカード
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # フォルダーを常にファイルより先に表示

            # --- アップロード・容量上限・ファイル名衝突ポリシー ---
            upload_subfolder: null                         # 例: '{Y}/{m}' — アップロード先を動的サブフォルダーへ自動振り分け
            upload_allow: []                               # 空 = 安全な全形式を許可、指定あり = 厳格な MIME ホワイトリスト
            upload_deny:                                   # 常にブロックする危険な MIME タイプ（省略時はデフォルト 47 種）
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # 単一ファイルの結合後最大サイズ（例: '50M'、'2G'）
            storage_quota: null                            # プロファイル全体のディスク容量上限（例: '500M'、'5G'、null = 無制限）
            overwrite_policy: 'rename'                     # rename（-1 を付与）| overwrite | reject

            # --- 分割アップロード（PHP の upload_max_filesize 制限を回避） ---
            chunk_upload:
                enabled: true                              # 大容量ファイルを再開可能なチャンクへ分割
                chunk_size: '2M'                           # 目標チャンクサイズ（PHP 上限の 80% に自動クランプ）
                parallel_uploads: 3                        # 最大同時アップロード数（1..10）
                max_retries: 3                             # ネットワークエラー時のチャンク単位の自動再試行回数（0..10）

            # --- 重複検出（SHA-256） ---
            duplicate_detection:
                enabled: false                             # アップロード時に同一内容のファイルを検出
                policy: 'warn'                             # warn | reject | reuse（既存ファイルを再利用）
                max_scan_files: 5000                       # 重複インデックス用にハッシュ化する最大ファイル数（100..50000）

            # --- 自動ファイル名正規化 ---
            naming:
                slugger: true                              # Unicode ファイル名をクリーンな ASCII スラッグへ変換
                slugger_locale: null                       # null = プロファイルロケールを継承（例: 'ja'、'de'、'tr'、'fr'）
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # 固定 / 動的プレフィックス（例: 'product-'）
                append: ''                                 # 拡張子前の固定 / 動的サフィックス（例: '-{Y}{m}{d}'）
                apply_on_rename: true                      # 名前変更時にも slugger / transformer ルールを適用
                apply_affixes_on_rename: false             # 名前変更時に prepend / append を再適用するか
                allow_extension_change: false              # 名前変更時に拡張子変更（.jpg -> .php 等）を許可するか

            # --- アップロード時の画像最適化 ---
            image_optimization:
                enabled: false                             # アップロード時の自動回転・EXIF 削除・リサイズ・圧縮
                auto_orient: true                          # スマートフォン写真の EXIF 回転情報を自動補正
                strip_exif: true                           # プライバシー保護と軽量化のため GPS / カメラ EXIF を削除
                max_width: 2400                            # アスペクト比維持の最大幅 px（>= 100）
                max_height: 2400                           # アスペクト比維持の最大高さ px（>= 100）
                convert_to_webp: false                     # JPEG / PNG を自動で WebP へ変換
                quality: 85                                # 出力画質（10..100）

            # --- 画像解像度＆アスペクト比制約 ---
            image_constraints:
                min_width: null                            # 最小幅 px（例: 800）
                min_height: null                           # 最小高さ px（例: 600）
                max_width: null                            # 最大幅 px（image_optimization 適用後に検証）
                max_height: null                           # 最大高さ px
                aspect_ratio: null                         # 必須アスペクト比（例: '1:1'、'16:9'、'4:3'、'3:4'）
                aspect_ratio_tolerance: 0.05               # 許容誤差（0.0..0.5、0.05 = 5%）

            # --- サムネイルエンジン ---
            thumbnail:
                enabled: true                              # キャッシュ済みの画像カードプレビューを生成
                driver: 'auto'                             # auto（Imagick->GD）| imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # サムネイル幅 px（>= 32）
                height: 240                                # サムネイル高さ px（>= 32）
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # 圧縮品質（10..100）
                fit: 'contain'                             # contain（全体収容）| cover（切り抜きフィット）
                lazy_load: true                            # カードサムネイルに loading="lazy" を付与
                cache_dir: '%kernel.project_dir%/public/media-cache' # 生成サムネイルのローカルキャッシュ先

            # --- ゴミ箱（論理削除） ---
            trash:
                enabled: true                              # 削除時に完全削除せずゴミ箱へ移動
                directory: '.trash'                        # 隠しゴミ箱ディレクトリ名
                retention_days: 30                         # file-manager:trash:purge での保持日数
                max_size: '500M'                           # ゴミ箱の合計サイズ上限

            # --- ZIP ダウンロード＆安全なアーカイブ展開 ---
            zip:
                enabled: true                              # 複数ファイル / フォルダーの ZIP ダウンロードを有効化
                max_files: 100                             # 1 回の ZIP ダウンロードに含められる最大ファイル数
                max_total_size: '250M'                     # 1 回の ZIP ダウンロードの最大合計サイズ
                allow_extract: false                       # アーカイブ展開を有効化（デフォルト無効、7 層保護）
                max_extract_files: 200                     # 1 アーカイブあたりの最大展開ファイル数（1..2000）
                max_extract_size: '100M'                   # 展開後の最大合計サイズ（ZIP ボム対策）
                max_compression_ratio: 100                 # 許容する最大圧縮率（1..1000 — ZIP ボム対策）

            # --- 有効期限付き署名共有リンク（HMAC） ---
            share:
                enabled: true                              # 署名付き共有 URL の生成を許可
                default_ttl: 86400                         # デフォルト有効期限（秒、86400 = 24 時間）
                max_ttl: 2592000                           # 選択可能な最大有効期限（秒、2592000 = 30 日）
                allow_inline_preview: true                 # 安全な MIME タイプでのブラウザー内プレビューを許可

            # --- オプションの Symfony AI メタデータ補完 ---
            ai:
                enabled: false                             # メタデータモーダルに「✨ AI で自動提案」を表示
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = プロファイル / リクエストロケール（例: 'ja' で日本語の代替テキスト・タグ生成）
                max_tags: 6                                # 最大提案タグ数（1..20）
                max_image_dimension: 1024                  # AI 送信前の縮小上限（256..2048 px）
                block_private_volumes: true                # true = private: true ボリュームからの AI 呼び出しをブロック（デフォルト: true）
                auto_enrich_on_upload: false               # アップロード完了後に AI 補完を自動実行

            # --- アセット使用状況ガード（削除 / 名前変更 / 移動 / 上書き） ---
            usage_guard:
                enabled: false                             # アセット変更前に DB 参照をチェック
                policy: 'warn'                             # warn（参照一覧を表示し強制実行を許可）| block（操作を禁止）
                enforce_server_side: true                  # 直接 API 呼び出しに対してもサーバー側で強制
                max_references: 20                         # モーダルに表示する最大参照件数（1..200）

            # --- プロファイル別キャッシュ ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | 秒数（0 = 無効）

            # --- セキュリティオプション ---
            security:
                voter_enabled: true                        # FileManagerVoter による RBAC チェックを有効化
                csrf_protection: true                      # POST リクエストで X-LiveUX-FM-CSRF ヘッダーを必須化
                sanitize_svg: true                         # アップロード / 編集された SVG をサニタイズ
                allow_remote_url_import: false             # 「URL から取得」タブを有効化（SSRF 対策済み）
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 種類の詳細 RBAC 権限（true = 認証済みユーザー | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']） ---
            permissions:
                view: true                                 # 一覧表示およびその他すべての操作に必須のマスター権限
                upload: true                               # アップロード、画像エディター保存、URL インポート
                create_folder: true                        # 新しいフォルダーの作成
                rename: true                               # ファイル / フォルダーの名前変更
                clone: true                                # ファイル / フォルダーの複製
                move: true                                 # 項目の移動（ドラッグ＆ドロップ、切り取り / 貼り付けを含む）
                copy: true                                 # 項目のコピー（コピー / 貼り付けを含む）
                delete: true                               # 項目の削除（ゴミ箱移動または完全削除）
                trash_restore: true                        # ゴミ箱からの復元
                trash_purge: true                          # ゴミ箱からの完全削除 / ゴミ箱を空にする
                download: true                             # 単一ファイルダウンロード＆リンクコピー
                download_zip: true                         # 複数ファイルの ZIP ダウンロード
                extract_zip: false                         # ZIP アーカイブの展開（zip.allow_extract: true も必要）
                share: true                                # 署名付き共有リンクの作成
                clear_cache: true                          # 設定からのキャッシュクリア＆ ?refresh=1 強制更新
                edit_metadata: true                        # ファイルの alt / タグ / フォーカルポイント、フォルダー色 / アイコン / ピン留めの編集
                ai_enrich: true                            # AI メタデータ補完の実行

            # --- ユーザーインターフェース（UI）フラグ＆サイズ設定 ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <カスタムディレクトリ>
                z_index: null                              # null = defaults.z_index | 整数（>= 1）
                mobile_dialog_mode: 'auto'                 # モバイル（<= 767px）でのダイアログ挙動: auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px（<=132px はコンパクト）
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile（コンパクト行）| card（フルサイズフォルダーカード）
                show_size_slider: true                     # 設定内に表示サイズスライダー＆フォルダー表示スタイル切替を表示
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # 左フォルダーツリーサイドバーを表示
                tree_lines: true                           # フォルダーツリーの階層ガイドラインを表示
                show_folder_item_count: true               # フォルダーの項目数バッジを表示
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # サイドバーの容量メーター＆ヘッダーの容量ピルを表示
                show_quota_details: true                   # クリック可能な「ストレージ使用状況と容量上限」モーダルを有効化
                per_page: 24                               # 1 ページあたりのデフォルト表示件数（6..200）
                per_page_options: [24, 48, 96]             # 表示件数ドロップダウンの選択肢
                show_perf_badge: false                     # ツールバーにキャッシュヒット / ディスク処理時間バッジを表示
                show_nav_buttons: true                     # 戻る / 進む / 上へ / 更新ボタン群のマスター切替
                show_history_buttons: true                 # 履歴の戻る / 進むボタンを表示
                show_up_button: true                       # 上のフォルダーへ移動ボタンを表示
                show_refresh_button: true                  # 最新の情報に更新（?refresh=1）ボタンを表示
                show_breadcrumbs: true                     # クリック / ドロップ対応パンくずリストを表示
                show_search: true                          # 検索欄＆詳細検索（サブフォルダー含む）切替を表示
                show_type_filter: true                     # 種類フィルター（画像 / 動画 / ファイル / フォルダー）を表示
                show_view_toggle: true                     # グリッド / リスト表示切替を表示
                show_pagination: true                      # 下部ページネーションバーを表示
                show_per_page_selector: true               # 1 ページあたりの表示件数ドロップダウンを表示
                show_sort: true                            # 並び替えドロップダウン＆昇順 / 降順切替を表示
                show_fullscreen_button: true               # 全画面表示切替ボタンを表示
                show_settings_button: true                 # 設定（⚙）ドロップダウンを表示
                show_footer: true                          # 下部ステータスバーを表示
                show_context_nav: true                     # 空白部分の右クリックメニューにクイックナビゲーションを表示
                allow_dropzone_upload: true                # OS からファイル一覧へのドラッグ＆ドロップアップロードを許可
                remember_last_dir: true                    # 最後に開いたボリュームとフォルダーを記憶（削除時は自動復帰）
                confirm_delete: true                       # 削除前に確認モーダルを表示
                confirm_move: true                         # 移動 / コピー前に確認モーダルを表示
                double_click_to_select: true               # モーダル / オフキャンバスでダブルクリックにより選択確定して閉じる
                allow_context_menu: true                   # 右クリックコンテキストメニューを有効化
                allow_pdf_preview: true                    # インライン PDF Lightbox（false で強制ダウンロード）
                allow_video_preview: true                  # インライン動画プレーヤー（false で強制ダウンロード）
                keyboard_shortcuts: true                   # スコープ付きキーボードショートカットを有効化
                allow_drag_move: true                      # フォルダーへのドラッグ＆ドロップ移動を有効化
```

---

[← 前へ: 01 — 要件・依存関係とインストール](01-requirements-and-installation.md) | [ドキュメント一覧](README.md) | [次へ: 03 — 利用モードと JavaScript API →](03-usage-modes-and-js-api.md)
