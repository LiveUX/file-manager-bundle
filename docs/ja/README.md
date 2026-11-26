<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Symfony UX ファイルマネージャー＆デジタルアセットマネージャー" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — 日本語ドキュメント

**言語:** [English](../en/README.md) · [Türkçe](../tr/README.md) · [Français](../fr/README.md) · [Deutsch](../de/README.md) · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · **日本語** · [简体中文](../zh_CN/README.md) · [メイン README](../../README.md)

**LiveUX FileManagerBundle** は、Symfony 6.4 LTS、7.2+、および 8.x（PHP 8.3+）向けに設計された、モダンかつ高性能なエンタープライズ向け **Symfony UX ファイルマネージャー＆デジタルアセットマネージャー（DAM）** です。**CSS フレームワークへの依存ゼロ**、標準搭載の 14 層セキュリティ防御、そしてグローバル多言語サポート（12 言語の組み込み UI 翻訳、BCP-47 / POSIX ロケール自動検出、ネイティブ RTL 対応）を備えています。

---

## ライブプレビュー＆スクリーンショット

<p align="center">
  <img src="../images/demo-showcase.gif" alt="LiveUX FileManagerBundle インタラクティブデモ" width="100%">
</p>

> **YouTube・リリース向けのフル HD（1080p、約 3 分）全 10 チャプター紹介動画:** [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · ショートプレビュー: [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| ライトモード（`Light`） | ダークモード（`Dark`） |
| :---: | :---: |
| ![ライトモードのファイルマネージャー](../images/browser-light.png) | ![ダークモードのファイルマネージャー](../images/browser-dark.png) |

| フォルダーの色とアイコンのカスタマイズ | バージョン情報ダイアログ（`LiveUX`） |
| :---: | :---: |
| ![フォルダーの色とバッジのカスタマイズ](../images/folder-customize.png) | ![バージョン情報モーダル](../images/about-modal.png) |

### リッチテキストエディター・EasyAdmin・動的フォーム連携デモ（GIF）

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![TinyMCE 連携デモ](../images/demo-editor-tinymce.gif) | ![CKEditor 5 連携デモ](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2（ProseMirror） | Quill 2 |
| :---: | :---: |
| ![Tiptap 2 連携デモ](../images/demo-editor-tiptap.gif) | ![Quill 2 連携デモ](../images/demo-editor-quill.gif) |

| Trix（EasyAdmin `TextEditorField`） | Summernote |
| :---: | :---: |
| ![Trix 連携デモ](../images/demo-editor-trix.gif) | ![Summernote 連携デモ](../images/demo-editor-summernote.gif) |

| Editor.js（ブロックエディター） | GrapesJS（ビジュアルページビルダー） |
| :---: | :---: |
| ![Editor.js 連携デモ](../images/demo-editor-editorjs.gif) | ![GrapesJS 連携デモ](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5（`FileManagerField`） |
| :---: | :---: |
| ![Meta Lexical 連携デモ](../images/demo-editor-lexical.gif) | ![EasyAdmin 5 連携デモ](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` & `LiveCollectionType` |
| :---: |
| ![CollectionType および LiveCollectionType 連携デモ](../images/demo-collections.gif) |

---

## 2 分でわかるクイックスタートガイド

どこから読み始めるべきか迷った場合は、目的に合ったガイドへ直接進んでください。

- **初めてインストールする場合:** [01 — 要件とインストール](01-requirements-and-installation.md) → [02 — 設定とプロファイル](02-configuration-and-profiles.md)
- **Symfony フォームに画像ピッカーやギャラリーを追加したい場合:** [05 — FormType とエディター連携](05-formtype-and-editor-integrations.md)
- **本番環境に向けてセキュリティと RBAC を強化したい場合:** [06 — セキュリティと認可](06-security-and-authorization.md)
- **Supabase、AWS S3、Cloudflare R2 と接続したい場合:** [07 — クラウドストレージと動的設定](07-cloud-storage-and-dynamic-config.md)
- **Doctrine ORM / DBAL でメタデータを保存したい場合:** [13 — Doctrine 連携](13-doctrine-integration.md)
- **データベースから参照されているアセットの削除・移動を防ぎたい場合:** [10 — アセット使用状況ガード](10-asset-usage-guard.md)

---

## 目次（ドキュメント一覧）

| 章 | ガイド | 概要 |
| :---: | :--- | :--- |
| **01** | [要件・依存関係とインストール](01-requirements-and-installation.md) | PHP 8.3+ / Symfony 6.4 LTS、7.2+、8.x の要件、PHP 拡張機能（`ext-exif`、`ext-zip` を含む）、Composer 依存関係、AssetMapper と Webpack Encore のセットアップ、`file-manager:doctor`、および最初の動作確認ページ |
| **02** | [設定とプロファイル（YAML リファレンス）](02-configuration-and-profiles.md) | `defaults`、`volumes`、`profiles`、衝突のない動的パス（`{user_id}`、`{Y}/{m}`）、アップロード・分割アップロード制限、自動リネーム、画像最適化と制約、サムネイル、ゴミ箱、ZIP、本番ですぐ使える 5 つのレシピ、完全な YAML スキーマリファレンス |
| **03** | [利用モードと JavaScript API](03-usage-modes-and-js-api.md) | インライン `TwigComponent`、`<dialog>` モーダル＆ 4 方向オフキャンバス、`window.LiveUXFileManager.open()` リファレンス、カスタム DOM イベント、RTL / IME / WCAG 2.1 AA アクセシビリティ、コンテキストメニュー、組み込み画像エディター、キーボードショートカット |
| **04** | [テーマ・アイコンセットとカスタマイズ](04-themes-icons-and-customization.md) | WCAG 2.1 AA 準拠の 9 種の組み込みテーマ（Bootstrap 5、Tailwind、Shadcn、Flowbite 4、DaisyUI 5、Tabler、Preline、Material、Unstyled）、自動・手動ダークモード、6 種の SVG アイコンセット（UI 49 種 + バッジ 14 種）、Twig ヘルパー、3 段階のカスタムスタイル設定 |
| **05** | [FormType とエディター連携](05-formtype-and-editor-integrations.md) | `FileManagerPickerType`（単一選択・アクセシブルな複数画像ギャラリー並び替え + POST セキュリティガード）、5 種の組み込みバリアント（`compact`、`card`、`dropzone`、`avatar`、`input`）、`CollectionType` / `LiveCollectionType`、カスタムテンプレート、TinyMCE、CKEditor 5、Tiptap、16 メソッド搭載の EasyAdmin `FileManagerField` |
| **06** | [セキュリティと認可（RBAC）](06-security-and-authorization.md) | 17 種類の Voter 権限、カスタム `unanimous` Voter、`security.yaml` ファイアウォール設定、14 層防御（CSRF、パストラバーサル、69 拡張子 / 47 MIME タイプ、55 タグ `SvgSanitizer`、SSRF DNS リバインディング対策、`ConfinedLocalFilesystemAdapter` シンボリックリンク閉じ込め、HMAC 共有リンク、監査ログ）、Apache / Nginx ハードニング |
| **07** | [クラウドストレージ（S3/R2/MinIO/Supabase）と動的設定](07-cloud-storage-and-dynamic-config.md) | ローカル・クラウド混在マルチボリューム構成、プロバイダー別ガイド（[Supabase](cloud/supabase-storage.md)、[AWS S3](cloud/aws-s3.md)、[Cloudflare R2](cloud/cloudflare-r2.md)、[Backblaze B2](cloud/backblaze-b2.md)、[MinIO/RustFS](cloud/minio-rustfs.md)、[Google Cloud Storage](cloud/google-cloud-storage.md)、[SFTP とカスタム Flysystem](cloud/sftp-and-custom-flysystem.md)）、`FlysystemServiceLocatorPass`、`ConfigProviderInterface`、カスタムメタデータストレージ |
| **08** | [イベント・CLI コマンド・キャッシュとトラブルシューティング](08-events-commands-cache-and-troubleshooting.md) | 9 種類の Symfony ドメインイベント、10 種類のコンソールコマンド（`doctor`、`stats`、`duplicates`、`ai:enrich`、`skills:install` など）、3 層キャッシュとコンテキスト認識 HTTP 304 / CDN 分離、12 言語グローバル BCP-47 / POSIX / RTL 翻訳システム（493 キー）、FAQ |
| **09** | [Symfony AI 連携とスマートメタデータ補完](09-symfony-ai-integration.md) | 必須依存ゼロの `AssetEnricherInterface`、`symfony/ai-bundle` ブリッジ（OpenAI、Gemini、Claude、Ollama）、**✨ AI で自動提案**、`file-manager:ai:enrich` 一括 CLI、非同期 Messenger 実装例、11 言語対応の LLM 拒否応答＆プロンプトインジェクションフィルタリング |
| **10** | [アセット使用状況ガード](10-asset-usage-guard.md) | オプトイン型の `AssetUsageCheckerInterface` 契約、`warn` / `block` ポリシー、**`delete`・`rename`・`move`・`overwrite`** を対象とした 2 層サーバーサイド保護（`enforce_server_side`）、MySQL・PostgreSQL・SQLite 向け Doctrine 実装例 |
| **11** | [コントリビューションとプルリクエストガイド](11-contributing-and-pr-guide.md) | 初心者向けのオープンソース PR ワークフロー、`composer qa` 品質ゲート（PHPStan Level 8、360+ PHPUnit テスト、37+ JS テスト）、6 ジョブ構成の GitHub Actions CI、GitHub ブランチ保護設定 |
| **12** | [AI エージェントスキルガイド（`SKILL.md` & `AGENTS.md`）](12-ai-agent-skills-guide.md) | Antigravity、Claude Code、Cursor、GitHub Copilot、Codex、Symfony AI Mate 向けの 6 つのモジュラー `SKILL.md` パック、ワンコマンドの `file-manager:skills:install`、CI バリデーション |
| **13** | [Doctrine ORM & DBAL 連携](13-doctrine-integration.md) | 組み込み `metadata.driver: 'doctrine'`（`DoctrineDbalMetadataStorage` — アトミック UPSERT、PostgreSQL `text_pattern_ops` インデックス、一括 `saveMany()`）、クロスプラットフォームマイグレーション、Entity + `FileManagerPickerType`、`ResetInterface` 対応カスタム ORM ストレージ、`ItemRenamedEvent` DB 同期 |
| **14** | [セキュリティ監査・ロジックレビュー・変更履歴レポート](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(トルコ語のみ)* | 行単位のセキュリティ監査（第 1〜18 ラウンド）、衝突のないマルチテナント `{user_id}` 分離、DI / Voter / Parser / SVG `<use>` ステップ予算・CDN / Origin 偽装対策、シンボリックリンク閉じ込め、PostgreSQL `text_pattern_ops` & アトミック UPSERT、WCAG 2.1 AA 全 9 テーマコントラスト・フォーカス監査、モバイルダイアログ、i18n 監査ログ |

---

## アーキテクチャの特長

- **0ms ハイブリッド高速レスポンス:** 高速な SSR `TwigComponent` + ブラウザー内メモリキャッシュ（`dirCache`）+ 単一リクエストでの全フォルダーツリー取得を組み合わせました。絞り込み、検索、並び替え、ページネーション、グリッド / リスト切替、フォルダーの展開・折りたたみはすべて追加の AJAX 通信なしで即座に反応します。
- **CSS フレームワーク非依存 ＆ WCAG 2.1 AA 準拠:** UI 全体がスコープ付きの `.liveux-fm-*` クラスと CSS カスタムプロパティ（`--liveux-fm-*`）で構成されています。**Bootstrap 5、Tailwind CSS、Shadcn/UI、Flowbite 4、DaisyUI 5、Tabler、Preline、Material**、あるいは **CSS フレームワークなし** の環境でも、スタイルの衝突や Tailwind の追加 purge 設定なしにそのまま動作します。
- **ネイティブ HTML5 `<dialog>` トップレイヤー ＆ モバイルファースト設計:** モーダルとオフキャンバス（`start`、`end`、`top`、`bottom`）はブラウザーの Top Layer に描画され、モバイル画面では全画面（`100dvh`）またはドラッグハンドル付きネイティブボトムシートへ自動最適化されます。
- **14 層のエンタープライズセキュリティ:** Symfony `Voter` による RBAC、CSRF 保護（`X-LiveUX-FM-CSRF`）、`PathNormalizer`、`FileSecurityGuard`、55 タグ制限の `SvgSanitizer`、DNS リバインディング耐性を備えた `RemoteUrlImporter`、`ConfinedLocalFilesystemAdapter` によるシンボリックリンク閉じ込め、展開ボム / ピクセルボム対策、`CSP: sandbox` + `nosniff` ヘッダーが初期状態で有効です。
- **グローバル多言語アーキテクチャ:** **12 言語の組み込み UI 翻訳**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`、各 493 キー同期済み）、BCP-47 および POSIX `.UTF-8` ロケールの正規化、確実な `'en'` フォールバック、アラビア語を含む 15 の RTL 言語に対する自動 `dir="rtl"` 適用、および CJK の IME 変換中保護を備えています。
- **マルチサーバー＆クラウド対応:** 組み込みの `DoctrineDbalMetadataStorage`（PostgreSQL `text_pattern_ops`、MySQL / MariaDB / SQLite アトミック UPSERT）、Flysystem v3（S3、R2、Supabase、B2、MinIO、GCS、SFTP）、および FrankenPHP や Symfony Messenger ワーカー向けの `ResetInterface` に対応しています。

---

## 5 分クイックスタートチェックリスト

1. **バンドルをインストール:** `composer require liveux/file-manager-bundle`
2. **ルートを読み込み:** `config/routes/liveux_file_manager.yaml`
3. **プロファイルを定義:** `config/packages/liveux_file_manager.yaml`
4. **CSS & JS（設定不要）:** Symfony UX の `autoimport` により、AssetMapper でも Webpack Encore でも `assets/controllers.json` 経由で `core.css`、`file_manager_api.js`、および 3 つの Stimulus コントローラーが自動的に読み込まれます。
5. **DialogHost（自動挿入）:** `AutoInjectDialogHostSubscriber` がフル HTML ページの `</body>` 直前に `DialogHost` を自動挿入します（`defaults.auto_inject_dialog_host: true`）。`base.html.twig` を編集する必要はありません。
6. **動作診断を実行:** `php bin/console file-manager:doctor`

> Symfony UX を初めて使う方は、ステップバイステップで学べる **[01 — 要件・依存関係とインストール](01-requirements-and-installation.md)** から始めてみてください。

---

**Organization:** [LiveUX on GitHub](https://github.com/LiveUX) · [Repository](https://github.com/LiveUX/file-manager-bundle)  
**License:** MIT
