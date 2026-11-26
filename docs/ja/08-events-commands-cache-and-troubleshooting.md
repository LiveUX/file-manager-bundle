# 08 — イベント・CLI コマンド・キャッシュ・多言語対応・トラブルシューティング（FAQ）

[← 前へ: 07 — クラウドストレージと動的設定](07-cloud-storage-and-dynamic-config.md) | [目次](README.md) | [次へ: 09 — Symfony AI 連携 →](09-symfony-ai-integration.md)

> [!TIP]
> **ひと目でわかる：イベントと CLI コマンドの役割**
> - **イベント:** ユーザーがファイルをアップロード・削除・名前変更・移動したタイミングで、Symfony アプリケーションへ自動的に通知します（例：「ファイル名が変更されたら、データベース側の商品画像パスも自動更新する」）。
> - **CLI コマンド（`php bin/console file-manager:...`）:** 健全性チェック（`doctor`）、キャッシュクリア（`cache:clear`）、サムネイル事前生成（`thumbs:warmup`）、期限切れゴミ箱の完全削除（`trash:purge`）を実行できる組み込みターミナルツールです。

---

## 1. Symfony イベント（9 つのドメインイベント）

ファイルマネージャーの全イベントは共通の基底クラス **`AbstractFileManagerEvent`**（`$profile`、`$volume`、`$path`、`$canonicalPath`）を継承しています。これらのイベントをリッスンすることで、データベースとの同期、監査ログの記録、CDN キャッシュのパージ、バックグラウンドジョブのディスパッチなどを柔軟に実装できます。

| イベントクラス（`LiveUX\FileManagerBundle\Event\*`） | ディスパッチされるタイミング | プロパティ |
| :--- | :--- | :--- |
| `FileUploadedEvent` | ファイルのアップロード、URL インポート、画像エディターからの保存、または ZIP アーカイブ展開時 | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | ファイルが削除されたとき（ゴミ箱への移動または完全削除） | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | フォルダーが作成されたとき（ZIP 展開時の作成を含む） | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | フォルダーが削除されたとき | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | ファイルまたはフォルダーの名前が変更されたとき | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | 項目が移動またはコピーされたとき（ボリューム間転送を含む） | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | 項目が複製されたとき | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | ゴミ箱から項目が復元されたとき | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | ゴミ箱から 1 件以上の項目が完全に削除されたとき | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### イベントリスナーの実装例（`#[AsEventListener]`）

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('新しいメディアアセットがアップロードされました。', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. コンソール（CLI）コマンド（10 個の組み込みコマンド）

| コマンド | フラグ / エイリアス | 説明 |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | PHP バージョン、`upload_max_filesize`、`post_max_size`、cURL CA バンドル、GD / Imagick、EXIF、WebP、`ext-zip`、および全プロファイル・ボリューム設定を診断します。`--check-remote` を付けると S3 / R2 / Supabase / MinIO へのライブ接続テスト（`ListObjectsV2`）も実行します。 |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | 全プロファイルまたは指定したプロファイル・ボリューム・パスのディレクトリキャッシュおよび容量上限キャッシュをクリアします（`--volume` / `--path` を使用する場合は `profile` 引数が必須です。`cache.app` 内の無関係なキーは一切削除しません）。 |
| `file-manager:thumbs:warmup [profile]` | エイリアス: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | 画像サムネイルを事前に一括生成し、編集者の初回表示時の待ち時間をなくします。 |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | キャッシュ済みサムネイルを削除します。`--orphans` は元ファイルが存在しない孤立サムネイルのみを削除します（`--volume` / `--path` とは併用不可。`{user_id}` や `{Y}/{m}/{d}` 共有ディレクトリは自動的に保護されます）。 |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | 既存画像を遡って最適化します（安全・メモリ保護のため 1 枚あたり最大 `25 MB` 制限）。 |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | SHA-256 コンテンツハッシュで重複ファイルを検出し、削減可能なディスク容量をレポートします。 |
| `file-manager:stats [profile]` | `--json` | ストレージ使用量、ファイル・フォルダー数、容量上限の使用率、サムネイルおよびゴミ箱の統計情報を出力します。 |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *（デフォルト `50`）*, `--dry-run` | AI を使って不足している代替テキスト（`alt`）、タイトル、キャプション、タグを一括生成します。 |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *（`0` = 全件削除）*, `--dry-run` | 保持期限を過ぎたゴミ箱内の項目を完全に削除します。複数プロファイルが同じ物理ディレクトリを共有している場合、最も長い `retention_days` が自動的に優先されます。 |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | バンドル付属の 6 つの AI エージェントスキルを `.agents/skills`、`.claude/skills`、`.cursor/skills`、または `.github/skills` にインストールします。 |

> [!NOTE]
> **CLI コマンドにおける動的 `{user_id}` および `{Y}/{m}/{d}` の保護**
> CLI 実行時には認証済みユーザーセッションが存在しないため、スキャン・クリーンアップ系コマンド（`doctor`、`duplicates`、`ai:enrich`、`images:optimize`、`stats`、`thumbs:clear`、`thumbs:warmup`、`trash:purge`）は `{user_id}` を含むボリュームを **自動的にスキップ** します（空の `guest/` フォルダーが誤って作成されるのを防ぐため）。また、`{Y}/{m}/{d}` の日付プレースホルダーを含むボリュームでは現在の日付期間のみをスキャンし、情報メッセージを表示します。

### ゴミ箱の自動クリーンアップ用 Cron 設定例

```cron
# 毎日深夜 03:00 に期限切れのゴミ箱項目を完全削除
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. パフォーマンス・キャッシュ設計・Symfony Profiler

- **3 階層キャッシュ TTL と手動更新（`↻`）:** ディレクトリ一覧の TTL は **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** の順で解決されます（`0` を指定すると該当ボリュームまたはプロファイルのキャッシュを無効化します）。ツールバーの **最新の情報に更新（`↻`）** をクリックするか `R` キーを押すと `?refresh=1` が送信されます *（キャッシュのバイパスには `CLEAR_CACHE` 権限が必要です）*。
- **専用キャッシュプールとプロファイル指紋（Fingerprinting）:** `defaults.cache.pool` には専用の Redis / APCu プールを指定できます（`<pool>.taggable` が存在する場合は自動的に使用されます）。タグ付きキャッシュがない環境でも、`DirectoryCacheManager` は 30 日間保持される世代カウンターキーを利用し、アプリケーションのメインキャッシュに対して `$cachePool->clear()` を呼び出すことはありません。2 つのプロファイルが異なるルール（`ignore_dot_files`、`exclude_patterns`、`trash.directory`、`locale`、`max_scan_files`）で同一の物理フォルダーを共有する場合も、`buildProfilePolicyFingerprint()` によってキャッシュが厳密に分離されます。
- **正規化キー（Canonical Key）による同期:** 2 つのプロファイルが同じ物理ディレクトリを指している場合、片方でファイルをアップロード・変更するともう一方のプロファイルのキャッシュも自動的に無効化されます。
- **自己修復型 `remember_last_dir`:** ユーザーが最後に開いていたディレクトリが外部操作（FTP や S3 コンソールなど）で削除されていた場合でも、エラーで停止せず存在する最も近い親フォルダーまたはルートへ安全にフォールバックします。
- **HTTP 304 およびコンテキスト認識型サムネイルキャッシュ（`ThumbnailController`）:**
  - **プライベート / 認証コンテキスト**（`private: true` ボリューム、`{user_id}` 動的ボリューム、Voter 保護プロファイル、`Authorization` ヘッダーまたはセッション Cookie が存在する場合）：共有 CDN への情報漏洩を防ぐため `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` を返します。
  - **パブリックコンテキスト：**
    - `?v=<version>` キャッシュバスター付きの場合 → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable`（30 日間）。
    - `?v=` なしの場合 → `Cache-Control: public, max-age=3600, must-revalidate`（1 時間 + `ETag` / `304 Not Modified`）。
    - プロファイルで `cache.enabled: false` の場合 → `Cache-Control: public, no-cache, must-revalidate`。
  - すべてのサムネイルレスポンスには `Content-Security-Policy: default-src 'none'; sandbox`、`X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer` も付与されます。
- **Symfony Web Debug Toolbar:** `dev` 環境では **FileManager** プロファイラーパネルが表示され、現在のプロファイル、ボリューム、サムネイルドライバー、キャッシュのヒット／ミス統計を確認できます。

---

## 4. グローバル多言語対応（`493` キー）・BCP-47 / POSIX / RTL・カスタム言語

LiveUX FileManagerBundle には、**英語（`en` — 保証フォールバック）、トルコ語（`tr`）、フランス語（`fr`）、ドイツ語（`de`）、スペイン語（`es`）、アラビア語（`ar`）、イタリア語（`it`）、ポルトガル語（`pt`）、オランダ語（`nl`）、ポーランド語（`pl`）、日本語（`ja`）、簡体字中国語（`zh_CN`）の 12 言語** が標準搭載されており、**全 493 個の翻訳キー** で完全な整合性が保たれています。

- **BCP-47 および POSIX `.UTF-8` 対応:** `MessageTranslator` は `tr_TR.UTF-8`、`de_DE@euro`、`zh-Hans-CN` などの地域・POSIX ロケール文字列を自動解決します（`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`）。`toBcp47()` は HTML の `lang` 属性向けに有効な BCP-47 コードを出力します。
- **右横書き（RTL）の自動検出:** アラビア語（`ar`）、ペルシア語（`fa`）、ヘブライ語（`he`）、ウルドゥー語（`ur`）、パシュトー語（`ps`）、中央クルド語（`ckb`）など **15 の RTL 言語および文字体系**（`Arab`、`Hebr`、`Syrc`、`Thaa` 等）を自動的に検出し、コンポーネントのレイアウトを `dir="rtl"` に切り替えます。
- **コンパイル時のカタログ許可リスト:** `FileManagerBundle` は実在する翻訳カタログに対応するロケールのみを受け付けるため、任意の偽造ロケールパラメーターによるキャッシュ / OPcache の肥大化（DoS）を防止します。
- **新しい言語の追加（例：スウェーデン語 `sv`）または一部キーの上書き:**
  プロジェクト内に `translations/LiveUXFileManagerBundle.sv.yaml` を作成するだけです（既存言語の一部文言のみを変更したい場合は `LiveUXFileManagerBundle.ja.yaml` などで対象キーだけを上書きできます）。未定義のキーは自動的に英語（`en`）へフォールバックするため、`ui.upload` のような生の翻訳キーが画面に表示されることはありません。バンドル内の `translations/LiveUXFileManagerBundle.en.yaml` をテンプレートとしてコピーしてご利用ください。

---

## 5. よくある質問とトラブルシューティング（FAQ）

### 1. `window.LiveUXFileManager is not initialized / undefined` と表示される
- `assets/app.js` に `import '@liveux/file-manager-bundle/js/file_manager_api.js';` が記述されているか確認してください。
- `defaults.auto_inject_dialog_host: true` が有効になっているか確認してください（無効にしている場合は、`base.html.twig` に `DialogHost.html.twig` を手動で読み込む必要があります）。
- `php bin/console cache:clear` を実行し、ブラウザーをスーパーリロード（`Ctrl+Shift+R`）してください。

### 2. すべての POST リクエストが `403 Invalid or missing CSRF token` を返す
- `symfony/security-csrf` がインストールされており、`framework.csrf_protection: true` と `framework.session.enabled: true` の両方が有効になっているか確認してください。
- 独自の `fetch()` リクエストを直接送信している場合は、`X-LiveUX-FM-CSRF` ヘッダーを含めてください。

### 3. `403 Access denied` エラーになる
- `liveux_file_manager.yaml` 内の対象プロファイルの `permissions` 設定を確認してください。権限に `true` を指定した場合でも認証済みユーザーが必要であり、その他のすべての操作には `permissions.view` が必須です。
- カスタム Symfony Voter を実装している場合は、`config/packages/security.yaml` の `access_decision_manager.strategy`（`unanimous` と `affirmative` の違いなど）を確認してください。

### 4. 大容量ファイルのアップロードが失敗する（`upload_max_filesize`）
- `chunk_upload.enabled: true` を有効にしている場合、チャンクサイズは PHP の `upload_max_filesize` / `post_max_size` の `80%` に自動制限されます（例: PHP の上限が `2M` の場合は `1.60 MB` チャンク、最大 2,000 チャンク）。
- ファイル全体の許容サイズは `liveux_file_manager.yaml` の `upload_max_size` で制御します（100 MB の動画を許可する場合は `upload_max_size: '100M'` のように設定します）。

### 5. ファイルをダウンロードするとページ表示が崩れる（Symfony UX Turbo Drive）
- バンドル標準のダウンロードリンクにはすべて `data-turbo="false" download` が付与されています。Twig テンプレートをオーバーライドしている場合は、カスタムのダウンロード用 `<a>` タグにも `data-turbo="false"` を必ず追加してください。

### 6. サムネイルが生成されず、元画像がそのまま配信される
- `php bin/console file-manager:doctor` を実行し、`Thumbnail Driver` 行で `gd` または `imagick` が有効になっているか確認してください。
- `thumbnail.cache_dir`（`public/media-cache`）に対して Web サーバーユーザーからの書き込み権限があるか確認してください。

### 7. Windows 環境で S3 / Supabase 接続時に `cURL error 60: SSL certificate problem` が発生する
- 最新の `cacert.pem` バンドルをダウンロードし、`php.ini` の `curl.cainfo` と `openssl.cafile` に設定するか、S3 ボリューム設定で `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` を指定してください。

### 8. フロントエンドの変更がブラウザーに反映されない
- **開発環境:** `php bin/console cache:clear` を実行し、ブラウザーをスーパーリロード（`Ctrl+Shift+R`）してください。
- **本番環境（AssetMapper）:** `php bin/console asset-map:compile` を実行してください。

---

[← 前へ: 07 — クラウドストレージと動的設定](07-cloud-storage-and-dynamic-config.md) | [目次](README.md) | [次へ: 09 — Symfony AI 連携 →](09-symfony-ai-integration.md)
