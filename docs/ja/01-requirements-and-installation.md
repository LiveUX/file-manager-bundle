# 01 — 要件・依存関係とインストール

[← ドキュメント一覧](README.md) | [次へ: 02 — 設定とプロファイル →](02-configuration-and-profiles.md)

このガイドでは、LiveUX FileManagerBundle をゼロからインストールする手順を、**各ステップの目的と理由を含めて**わかりやすく解説します。Symfony UX パッケージの導入が初めての方でも迷わず進められます。

> [!NOTE]
> **ひとことで言うと、このバンドルは何をしてくれるのですか？**  
> Symfony アプリケーション（管理画面、EC サイト、顧客ポータルなど）に、Google ドライブやエクスプローラーのような高機能ファイルマネージャーを組み込めます。ユーザーはワンクリックで **ファイルのアップロード、フォルダー整理、画像の切り抜き、PDF や動画のプレビュー、フォームからのファイル選択** を行えます。  
> **CSS フレームワーク**（Bootstrap や Tailwind など）も **jQuery** も不要で、Symfony AssetMapper を使えば Node.js や npm すら使わずに 5 分で動かせるようになります。

---

## 1. システム要件

インストール前に、ローカル開発環境またはサーバーが以下の最小要件を満たしていることを確認してください。

| コンポーネント | 最小バージョン | 必要な理由 | 確認コマンド |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` 以上 | PHP 8.3 / 8.4 の機能（`readonly` クラス、型付きクラス定数など）を利用しています。 | `php -v` |
| **Symfony** | `6.4 LTS`、`7.2+`、または `8.x` | Symfony 6.4 LTS、7.2、7.3、7.4、および 8.x でテスト・サポート済みです。 | `php bin/console --version` |
| **Composer** | `2.5+` | パッケージ管理と PSR-4 オートローディングに使用します。 | `composer --version` |
| **ブラウザー** | モダンなエバーグリーンブラウザー | ネイティブ HTML5 `<dialog>` Top Layer、ES Modules、CSS カスタムプロパティに対応したブラウザー（Chrome、Edge、Firefox、Safari）。 | — |

---

## 2. PHP 拡張機能

PHP 拡張機能は、ファイル内容の検査、XML 解析、画像処理といったネイティブ機能を PHP に提供します。

### 必須の PHP 拡張機能
近年の PHP 環境では、ほとんどの場合デフォルトで有効になっています。

| 拡張機能 | 役割 | 具体的な活用例 |
| :--- | :--- | :--- |
| `ext-fileinfo` | 拡張子だけでなく、**ファイルの実データ（マジックバイト）** を検査します。 | 攻撃者が `shell.php` を `photo.jpg` にリネームしてアップロードしようとしても、`ext-fileinfo` が即座に検知して拒否します。 |
| `ext-dom` & `ext-libxml` | アップロードされた `.svg` の XML ツリーを安全に解析します（`SvgSanitizer`）。 | SVG ロゴに紛れ込んだ悪意のある `<script>` タグや XXE ペイロードを除去しつつ、クリーンなベクター画像だけを保持します。 |

*(補足: `ext-json` は PHP 8.0 以降コアに組み込まれているため、個別のインストールは不要です。)*

### オプション（推奨）の PHP 拡張機能
これらが未導入でもファイルマネージャー自体は **問題なく動作** し、自動的に適切なフォールバックへ切り替わります。

| 拡張機能 | 役割 | 未導入時のフォールバック動作 |
| :--- | :--- | :--- |
| `ext-gd` **または** `ext-imagick` | 高速な WebP / JPEG サムネイルの生成と、アップロード時の自動画像最適化を実行します。 | `thumbnail.on_missing_driver` の設定に従い、元画像の表示またはファイルアイコン表示へフォールバックします。 |
| `ext-exif` | `ext-gd` 利用時にスマートフォンの EXIF 回転情報を読み取り、横向き写真を自動で正しい向きに補正します（`auto_orient: true`）。 | 写真は元の向きのまま保存されます（`ext-imagick` が入っている場合は不要です）。 |
| `ext-zip`（`ZipArchive`） | 複数ファイルの `.zip` 一括ダウンロード、**および** 安全なアーカイブ展開（`zip.allow_extract: true`）を有効にします。 | **ZIP でダウンロード** および **アーカイブを展開** ボタンが自動的に非表示になります。 |

お使いの環境で有効な拡張機能は、次のコマンド 1 行で確認できます。

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. 必須およびオプションの Composer パッケージ

`composer require liveux/file-manager-bundle` を実行すると、**必須パッケージはすべて自動的にインストールされます**。各パッケージの役割を把握しておくと、トラブル時の切り分けがスムーズになります。

### 必須パッケージ（自動インストール）
| パッケージ | バージョン制約 | 用途 |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | ローカルディスク、AWS S3、Cloudflare R2、Supabase、MinIO、SFTP を統一的に扱うファイルシステム抽象化層。 |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | `{{ component('LiveUX:FileManager:Browser') }}` による 0ms SSR レンダリング。 |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | クライアント側のディレクトリキャッシュ、ドラッグ＆ドロップ、コンテキストメニュー、Lightbox、フォームピッカーを制御。 |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Symfony のコアランタイム、ルーティング、キャッシュプール（`cache.app`）。 |
| `symfony/config` & `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | `liveux_file_manager.yaml` のスキーマ検証とサービスコンテナのワイヤリング。 |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | 9 種類のドメインイベント（`FileUploadedEvent`、`FileDeletedEvent`、`ItemRenamedEvent` など）をディスパッチ。 |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | 安全なファイルダウンロード、HTTP 206 動画ストリーミング（`Range`）、ETag、HTTP キャッシュヘッダー。 |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Twig テンプレート、アイコン・翻訳用 Twig ヘルパー、FormType テーマの自動適用。 |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | 単一ファイルおよび複数ファイルギャラリー用の `FileManagerPickerType` フォームフィールド。 |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | アップロードされたファイルのサーバーサイド MIME タイプ検出。 |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | 多言語のファイル名（日本語、トルコ語、フランス語、アラビア語、キリル文字など）をクリーンな SEO スラッグへ変換。 |
| `symfony/security-core` & `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | 17 権限の Voter RBAC と `X-LiveUX-FM-CSRF` 保護。 |
| `symfony/cache-contracts` & `symfony/translation-contracts` | `^3.5` | ディレクトリキャッシュと多言語 UI 翻訳のコントラクト。 |

### オプションパッケージ（必要なときのみ導入）
| パッケージ | インストールコマンド | 必要になるケース |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Node.js / npm なしのビルド不要な JS・CSS 配信 *（推奨）*。 |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | ロールベース権限（`ROLE_USER` / `ROLE_ADMIN`）やユーザー単位の `{user_id}` 分離を使う場合。 |
| `symfony/translation` | `composer require symfony/translation` | **12 言語の組み込み UI 翻訳**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）やアプリ独自ロケールを Symfony Translator と統合する場合。 |
| `symfony/console` & `symfony/yaml` | `composer require symfony/console symfony/yaml` | CLI コマンド（`file-manager:doctor`、`thumbs:warmup`、`trash:purge` など）や YAML 設定ファイルを使う場合。 |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | AWS S3、Supabase Storage、Cloudflare R2、Backblaze B2、MinIO、Google Cloud Storage へ接続する場合。 |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | 複数サーバー / ロードバランサー環境で `defaults.metadata.driver: 'doctrine'` を使用する場合（[第 13 章](13-doctrine-integration.md) を参照）。 |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | EasyAdmin 4/5 管理画面で組み込みの `FileManagerField` を使用する場合（[第 05 章](05-formtype-and-editor-integrations.md) を参照）。 |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ AI で自動提案** による代替テキスト・キャプション・タグの自動生成を使う場合（[第 09 章](09-symfony-ai-integration.md) を参照）。 |

---

## 4. ステップバイステップ・インストール手順

以下の 7 ステップを順番に進めると、動作するファイルマネージャーが完成します。

### ステップ 1: バンドルをインストールする

**標準インストール（Packagist 経由）:**
```bash
composer require liveux/file-manager-bundle
```

**ローカルパスリポジトリ（`bundles/liveux-file-manager-bundle` で開発している場合）:**  
プロジェクトルートの `composer.json` に `repositories` エントリを追加します。

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

続いて、ローカルパッケージを require します。
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### ステップ 2: `config/bundles.php` を確認する

通常は Symfony Flex が自動的に登録します。登録されていない場合は、`config/bundles.php` に次のエントリが含まれていることを確認してください。

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // 認証やロールを使う場合
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` は起動時に自身の Twig 名前空間（`@LiveUXFileManager`）、AssetMapper パス（`@liveux/file-manager-bundle`）、および FormType テーマ（`@LiveUXFileManager/form/picker.html.twig`）を **自動的に登録** します。`twig.yaml` や `asset_mapper.yaml` を手動で編集する必要はありません。

---

### ステップ 3: ルートをインポートする

バンドルの AJAX API、ダウンロード、サムネイル、アップロード用エンドポイントを有効にするため、`config/routes/liveux_file_manager.yaml` を作成します。

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

この 1 つのインポートで、**12 個の保護されたエンドポイント** が登録されます。

| メソッド＆パス | 用途 |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | モーダルまたはオフキャンバス内にファイルマネージャーの HTML を読み込みます。 |
| `GET /_liveux-file-manager/api/list` | 指定ディレクトリ内のファイルおよびサブフォルダー一覧を JSON で返します。 |
| `GET /_liveux-file-manager/api/tree` | サイドバー用の全フォルダーツリーを 1 回のリクエストで取得します。 |
| `GET /_liveux-file-manager/api/trash` | ゴミ箱に論理削除された項目一覧を取得します。 |
| `GET /_liveux-file-manager/api/preview-text` | `.txt`、`.md`、`.json`、`.yaml`、`.csv`、`.sql` などの安全なテキスト / コードプレビュー（上限 64 KB）を返します。 |
| `POST /_liveux-file-manager/api/action` | フォルダー作成、移動、コピー、名前変更、削除、ゴミ箱からの復元・完全削除、フォルダーの色・アイコン設定、メタデータ編集、AI 補完、URL インポート、ZIP 展開などの変更操作を実行します。 |
| `POST /_liveux-file-manager/upload` | 通常アップロードおよび分割アップロード（チャンク）を処理します。 |
| `POST /_liveux-file-manager/image-edit` | 組み込み Canvas 画像エディターで切り抜き・リサイズ・回転した画像を保存します。 |
| `GET /_liveux-file-manager/download` | 安全なファイルダウンロード、PDF / 動画のインラインプレビュー（`Range` 対応）、および `private: true` ボリュームの配信を行います。 |
| `GET /_liveux-file-manager/share` | 有効期限付き HMAC 署名共有リンクのファイルを配信します。 |
| `POST /_liveux-file-manager/zip` | 選択された複数のファイルやフォルダーを 1 つの `.zip` アーカイブとしてストリーム配信します。 |
| `GET /_liveux-file-manager/thumb` | ETag とコンテキスト認識 HTTP キャッシュヘッダー付きで WebP / JPEG / PNG サムネイルを生成・配信します。 |

---

### ステップ 4: `config/packages/liveux_file_manager.yaml` を作成する

`config/packages/liveux_file_manager.yaml` を作成します。以下のスターター設定では、**全 17 種類の詳細権限** を明示的に記載しています。

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = Symfony のアクティブなロケールを自動継承（フォールバックは常に 'en'）
                            # あるいは 'ja', 'en', 'tr', 'fr', 'de', 'es', 'ar', 'zh_CN' などを明示指定

    volumes:
        local_uploads:
            label: 'マイファイル'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # ZIP 展開はデフォルト無効（有効化には zip.allow_extract: true も必要）
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **セキュリティ上の重要な注意（`permissions`）:**  
> `permissions` 配下で **省略したキー**（`extract_zip` を除く）はデフォルトで `true` になります。ここでの `true` は **「ログイン済みの認証ユーザー」** を意味し、匿名訪問者（未ログイン）は許可されません。ゴミ箱の完全削除（`trash_purge`）や共有リンク作成（`share`）を `ROLE_ADMIN` のみに限定したい場合は、該当キーへ明示的に `'ROLE_ADMIN'` を設定してください。

> [!TIP]
> **認証機能を作る前にローカルで試したい場合**  
> ログイン画面をまだ用意していないローカル検証中に限り、`profiles.default.security.voter_enabled: false` を一時的に設定できます。**本番環境では絶対に `voter_enabled: false` のままにしないでください**。詳細は [06 — セキュリティと認可](06-security-and-authorization.md) を参照してください。

> [!NOTE]
> **12 言語の組み込み UI 翻訳と自動 RTL（`defaults.locale: null`）:**  
> `locale: null` のままにしておくと、Symfony のリクエストロケールに追従して **12 言語の組み込み UI**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）を自動切替します。アラビア語などの RTL ロケール（ペルシア語・ヘブライ語・ウルドゥー語など）では **RTL（`dir="rtl"`）** が自動的に有効になり、BCP-47 や POSIX 形式（`de_DE.UTF-8`、`zh-Hans-CN`）も正規化され、未対応ロケールは **英語（`en`）** にフォールバックします。

---

### ステップ 5: CSS と JavaScript を接続する（設定不要！）

**プロジェクトが AssetMapper と Webpack Encore のどちらを使っているかわからない場合**  
プロジェクトルートを確認してください。
- `importmap.php` がある場合 → **方式 A（AssetMapper）** を進めてください。
- `webpack.config.js` がある場合 → **方式 B（Webpack Encore）** を進めてください。

> [!TIP]
> **ゼロコンフィグの `autoimport` サポート:**  
> LiveUX FileManagerBundle は自身の `assets/package.json` で `@liveux/file-manager-bundle/styles/core.css` と eager 読み込みの `dialog_controller.js`（内部で `file_manager_api.js` を読み込みます）に **`autoimport`** を宣言しています。`assets/controllers.json` が有効になっていれば、**`assets/app.js` に手動で `import` を 1 行も書く必要はありません！**

#### 方式 A: Symfony AssetMapper（推奨 — Node.js / npm 不要）

1. `assets/controllers.json` でバンドルのコントローラーが有効になっていることを確認します（Symfony Flex が自動追加しますが、未登録の場合は手動で追記します）。
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Symfony 6.4 / 7.2+ / 8.x のプロジェクトでは、`assets/bootstrap.js` または `assets/stimulus_bootstrap.js` 内で `startStimulusApp()` が既に呼ばれており、`controllers.json` が自動的に読み込まれます。
```javascript
// assets/stimulus_bootstrap.js（Symfony 8.x）または assets/bootstrap.js（Symfony 6.4 / 7.x）
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. ベースレイアウト（`templates/base.html.twig`）に `importmap('app')` が含まれていることを確認します。
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *（オプション — 本番デプロイ時）* 静的アセットをコンパイルします。
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **事前ミニファイ済みの本番アセット（`assets/dist/`）:**  
> AssetMapper はデフォルトで JS / CSS のミニファイを行わないため、LiveUX FileManagerBundle では `esbuild` で事前ミニファイ済みの JavaScript モジュールと `core.css` を `assets/dist/` に同梱しています（`747 KB → 410 KB` に縮小、Gzip 転送時 `約 93 KB`、Brotli 転送時は **`約 77 KB`**）。`FileManagerBundle` は `assets/dist/` を AssetMapper へ自動マッピングするため、**開発マシンにも本番サーバーにも Node.js / npm は不要です**。  
> Symfony 7.3+ / 8.x では、`config/packages/asset_mapper.yaml` で `precompress: true` を有効にすることで、`asset-map:compile` 実行時に `.br` および `.gz` ファイルを自動生成できます。

#### 方式 B: Webpack Encore

1. Webpack Encore プロジェクト（Symfony 8 + StimulusBundle 3.x を含む）では、`@symfony/stimulus-bridge` がインストールされていることを確認します。
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. `assets/controllers.json` に 3 つの Stimulus コントローラーと `autoimport` ブロックが設定されていることを確認します。
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. `assets/bootstrap.js` で `startStimulusApp` が正しく接続されていることを確認します（`assets/app.js` から読み込まれるため、手動の CSS / JS インポートは不要です）。
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. アセットをビルドします。
```bash
npm run build
# 開発中にファイル変更を監視する場合: npm run watch
```

---

### ステップ 6: `DialogHost`（自動挿入 — マークアップ追加不要！）

任意のページからモーダル、オフキャンバスドロワー、`FileManagerPickerType`、あるいは TinyMCE / CKEditor / Tiptap ピッカーを開くには、DOM 内に `<dialog>` コンテナが必要です。

バンドルの `AutoInjectDialogHostSubscriber` は、フル HTML レスポンスの `</body>` 直前にこのコンテナを自動挿入します（`defaults.auto_inject_dialog_host: true`）。そのため `base.html.twig` を編集する必要はありません。

- **スマート判定:** JSON、AJAX、Turbo Frame、リダイレクト、バイナリダウンロードのレスポンスには一切手を加えず、手動で配置済みの `DialogHost` がある場合も二重挿入を回避します。
- **初期表示オーバーヘッド 0ms:** ページ読み込み時にディレクトリやフォルダーツリーへの問い合わせは行いません。ユーザーがダイアログを開いた瞬間にのみデータを取得します。
- **特定のページにだけ手動配置したい場合:** `defaults.auto_inject_dialog_host: false` を設定し、必要なレイアウトの `</body>` 直前に次のスニペットを追加してください。

```twig
{# オプション — auto_inject_dialog_host: false にした場合のみ必要 #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### ステップ 7: インストール状態を診断する（`file-manager:doctor`）

キャッシュをクリアし、組み込みの診断コマンドを実行します。

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

クラウドボリューム（S3、R2、Supabase、MinIO）を設定している場合は、`--check-remote` を付けて実際の接続テストも実行できます。
```bash
php bin/console file-manager:doctor --check-remote
```

正常時の出力例（9 項目の環境チェック + プロファイル / ボリューム一覧表）:
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Windows ユーザーの方へ（`cURL error 60`）:** `cURL CA Bundle` に警告が出る場合は、`php.ini` の `curl.cainfo` と `openssl.cafile` に最新の `cacert.pem` を指定するか、S3 ボリュームの `driver_options.ca_bundle` を設定してください。
>
> **Linux のファイル権限:** `public/uploads`、`public/media-cache`、`var/file-manager` に対して、Web サーバー実行ユーザー（`www-data`、`nginx` など）の書き込み権限があることを確認してください。
>
> **AI コーディングアシスタント（Antigravity、Claude Code、Cursor、Copilot）をお使いですか？**  
> コマンド 1 つで、このバンドルの設計規約やレシピをすべてアシスタントに学習させることができます（[第 12 章](12-ai-agent-skills-guide.md) を参照）。
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. 最初の動作ページを作成する

シンプルな Symfony コントローラーと Twig テンプレートを作成して、エンドツーエンドで動作を確認しましょう。

### 1) コントローラー（`src/Controller/MediaController.php`）
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Twig テンプレート（`templates/admin/media.html.twig`）
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>メディアライブラリ</h1>

    {# 方法 1: インライン表示のファイルマネージャー #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# 方法 2: 任意のボタンからモーダルを開き、選択されたファイルを受け取る #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">モーダルでファイルを選択</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // ユーザーが選択せずにダイアログを閉じた場合
            }

            document.getElementById('selection-result').textContent =
                '選択済み: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

ブラウザーで `/admin/media` を開いてください。ファイルマネージャーが表示されればセットアップ完了です！

---

## 6. 次のステップ

- カスタムプロファイル、アップロード制限、画像ルールを設定する → **[02 — 設定とプロファイル](02-configuration-and-profiles.md)**
- Symfony フォームに画像ピッカーやギャラリーを追加する → **[05 — FormType とエディター連携](05-formtype-and-editor-integrations.md)**
- 本番公開前に RBAC とセキュリティを強化する → **[06 — セキュリティと認可](06-security-and-authorization.md)**

---

[← ドキュメント一覧](README.md) | [次へ: 02 — 設定とプロファイル →](02-configuration-and-profiles.md)
