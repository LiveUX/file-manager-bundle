# 06 — セキュリティと認可（RBAC と多層防御）

[← 前へ: 05 — FormType とエディタ連携](05-formtype-and-editor-integrations.md) | [目次](README.md) | [次へ: 07 — クラウドストレージと動的設定 →](07-cloud-storage-and-dynamic-config.md)

ファイルマネージャーは、Web アプリケーションの中でも特に攻撃対象になりやすい高リスク領域の 1 つです。LiveUX FileManagerBundle は**多層防御（Defense-in-Depth）**アーキテクチャに基づいて設計されており、デフォルトで複数のセキュリティ保護レイヤーが有効になっています。

> **ひと目でわかる: セキュリティを固める 3 ステップ**  
> 1. **ルートを保護する（`security.yaml`）:** 未認証の訪問者がファイルマネージャーのエンドポイントにアクセスできないようブロックします。  
> 2. **ロール権限を定義する（`permissions`）:** 閲覧（`view`）・アップロード（`upload`）・削除（`delete`）を誰に許可するかをプロファイルごとに明示的に宣言します。  
> 3. **複数ファイアウォール環境ではルート `prefix` を追加する:** 管理画面（`/admin`）と一般ユーザー（`/`）でファイアウォールを分けている場合は、`config/routes/liveux_file_manager.yaml` で `prefix: /admin` を指定してください。これにより管理者セッションが正しく解決され、一般会員が管理用ファイルへアクセスするのを防げます。

---

## 1. `security.yaml` でのルート保護（最初のステップ）

本番環境では、必ず `/_liveux-file-manager` 配下のすべてのエンドポイントを Symfony のファイアウォール配下に配置してください。

```yaml
# config/packages/security.yaml（単一ファイアウォール構成の場合）
security:
    access_control:
        # 外部向けの署名付き共有リンクを利用する場合のみ PUBLIC_ACCESS にします:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**管理画面（`/admin`）と一般サイト会員（`/`）のように 2 つのファイアウォールを分けている場合:**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. プロファイルベースの認可（`permissions` と `FileManagerVoter`）

各プロファイルでは、**17 種類の操作**ごとに独立したアクセス制御ルールを設定できます。

### 権限設定値の意味
- **`true`:** **認証済みユーザーのみ（`$token->getUser() !== null`）！** *(匿名ユーザーには決して許可されません。)*
- **`false`:** 誰も実行不可（操作を完全に無効化）。
- **`'ROLE_ADMIN'`:** 指定したロールを持つユーザーのみ許可。
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** 列挙されたロールのいずれかを持つユーザーを許可。

### 自動適用される 4 つの認可の黄金ルール
1. **`permissions.view` がマスターゲート:** ユーザーが `view` 権限を持たない場合、`upload`・`delete`・`download` をはじめとするすべての操作が自動的に拒否されます。
2. **フェイルクローズ設計（`FileManagerAuthorizer`）:** `security.voter_enabled: true` が設定されているにもかかわらず `AuthorizationCheckerInterface`（すなわち `symfony/security-bundle`）がインストールされていない場合、誤って許可するのではなく**安全にアクセスを拒否（`AccessDeniedException`）**します。
3. **親ディレクトリチェーン検証（`isPathAndAncestorsGranted`）:** ユーザーが `confidential/2026/invoice.pdf` へアクセスすると、カスタム Voter は `confidential/2026/invoice.pdf` 本体だけでなく、親フォルダである **`confidential`** と **`confidential/2026`** に対しても自動的に評価されます。これにより、URL の直接指定によるアクセス制限付き親フォルダの迂回を防ぎます。
4. **ZIP 展開の二重ロック（`zip.allow_extract`）:** `zip.allow_extract: false` である限り、`permissions.extract_zip` の設定にかかわらずサーバー側でアーカイブ展開が拒否されます。

### 17 種類の権限 Voter 完全リファレンス

| YAML キー | Voter 定数（`FileManagerPermissions::*`） | 保護対象の操作 |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | ディレクトリ一覧、フォルダツリー、サムネイル、インラインプレビュー、ダイアログ、および**その他すべての権限の前提条件** |
| `upload` | `FILE_MANAGER_UPLOAD` | ファイルアップロード、チャンクアップロード、リモート URL インポート、画像エディタからの保存 |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | 新規フォルダの作成 |
| `rename` | `FILE_MANAGER_RENAME` | ファイル・フォルダの名前変更 |
| `clone` | `FILE_MANAGER_CLONE` | ファイル・フォルダの複製 |
| `move` | `FILE_MANAGER_MOVE` | アイテムの移動（ドラッグ＆ドロップおよび移動モーダル。移動元・移動先の両パスを検証） |
| `copy` | `FILE_MANAGER_COPY` | アイテムのコピー（コピー元・コピー先の両パスを検証） |
| `delete` | `FILE_MANAGER_DELETE` | アイテムの削除（ゴミ箱へのソフト削除 / 完全削除）および `checkUsage` 参照チェック |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | ゴミ箱からのアイテム復元 |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | アイテムの完全削除またはゴミ箱を空にする操作 |
| `download` | `FILE_MANAGER_DOWNLOAD` | 単一ファイルのダウンロード、PDF / 動画ストリーミング、URL コピー |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | 複数アイテムの ZIP アーカイブダウンロード（選択されたすべてのパスを個別に検証） |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | 安全な ZIP 展開（別途 `zip.allow_extract: true` が必要） |
| `share` | `FILE_MANAGER_SHARE` | 有効期限付き HMAC 署名共有リンクの発行（別途 `share.enabled: true` が必要） |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | ディレクトリ・プロファイル・サムネイルキャッシュのクリア、および `?refresh=1` / `?fallback=1` によるキャッシュバイパス |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | フォルダの色・アイコン・ピン留めの更新、およびファイルの alt テキスト・タイトル・タグ・焦点位置の編集 |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | AI によるメタデータ提案（`view` + `edit_metadata` + `ai.enabled: true` が必要） |

### エンタープライズ向けプロファイル設定例

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **権限に連動する UI:** ユーザーに権限がないアクションボタン（例: `delete: 'ROLE_ADMIN'` のときの `ROLE_EDITOR` に対する削除ボタン）は UI 上で自動的に非表示になり、API を直接呼び出した場合も **`HTTP 403 Forbidden`** で厳格に拒否されます。

---

## 3. カスタム Symfony Voter の作成（フォルダ単位・ユーザー単位の細粒度ルール）

YAML のロール設定だけでは表現できない要件 — たとえば「*`locked/` フォルダの削除や、`locked/` へのファイル移動を禁止したい*」といった場合は、独自の Symfony Voter を作成します。

> [!WARNING]
> **初心者が最も陥りやすい落とし穴: `affirmative` vs `unanimous`!**  
> Symfony のデフォルトの `access_decision_manager.strategy` は **`affirmative`**（いずれか 1 つの Voter が `true` を返せば許可）です。そのため、バンドル組み込みの `FileManagerVoter` が `ROLE_ADMIN` ユーザーに対して `true` を返すと、カスタム Voter が返した **`false`（拒否）票が上書き**され、削除できてしまいます。  
>  
> カスタム Voter の**拒否（deny）**ルールを確実に効かせるには、`config/packages/security.yaml` で戦略を **`unanimous`**（投票したすべての Voter が承認した場合のみ許可、1 つでも `false` ならブロック）に設定してください。
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(バンドルは `DetectExternalVotersPass` を通じてアプリ側のカスタム Voter を自動検出し、フォルダツリーやファイル件数カウントにもアイテム単位の Voter フィルタリングを適用してプライバシーと正確性を保ちます。)*

### `FileManagerSubject` のプロパティ

カスタム Voter の `voteOnAttribute()` に渡される `$subject` には、4 つの readonly プロパティが公開されています。

| プロパティ | 型 | 説明 | 例 |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | アクティブなプロファイル設定（`$subject->profile->name`） | `'default'` |
| `$subject->path` | `string` | ボリュームルートからの対象ファイル / フォルダの相対パス | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | アクティブなボリューム名 | `'local_uploads'` |
| `$subject->targetPath` | `?string` | `MOVE`・`COPY`・`RENAME`・`CLONE` 操作時の**移動先 / 変更先パス**（その他の操作では `null`） | `'locked/new-name.pdf'` |

### カスタム Voter の実装例（`locked/` フォルダへの削除・名前変更・移動を禁止）

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) "locked" フォルダ本体および配下の削除・移動・名前変更をブロック
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) "locked" フォルダの中への移動・名前変更もブロック
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 組み込みの 14 層・多層防御（実践的な攻撃への対策）

| # | 攻撃ベクター | バンドルの防御機構 |
| :---: | :--- | :--- |
| 1 | **CSRF（クロスサイトリクエストフォージェリ）** | 書き込み・削除・アップロード・ZIP・画像エディタのすべての `POST` リクエストで、入力処理の**前**に `X-LiveUX-FM-CSRF` ヘッダを検証します（`CsrfRequestValidator`）。 |
| 2 | **パストラバーサル、Unicode・OS 固有の回避手法** | `PathNormalizer` が `../`、`..\`、NULL バイト（`\0`）、制御文字、`C:\`、`phar://` / `php://` ストリームラッパー、末尾のドットや空白、ゼロ幅・双方向（bidi）Unicode 文字、日本語句点（`。`）、NTFS 代替データストリーム（`file.txt:stream`）、Windows デバイス名（`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`）をブロックし、最大 **32 階層**、**255 バイト/セグメント**、パス全体で **1024 UTF-8 文字 / 3072 バイト**の上限を強制します。 |
| 3 | **実行可能ファイル・ポリグロット・二重拡張子** | `FileSecurityGuard` が **69 種類の禁止拡張子**（`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config` など）、**47 種類の禁止 MIME タイプ**、二重拡張子チェック（`shell.php.jpg`）、**マジックバイトによるポリグロット検証**を、アップロード・名前変更（`allow_extension_change: true` 時も含む）・ダウンロードすべてで厳格に適用します。 |
| 4 | **隠しファイルおよびゴミ箱フォルダへの直接アクセス** | `.trash`、`.env`、`.git`、`.htaccess` および `exclude_patterns` 配下のパスは全エンドポイントで拒否されます。さらに `ZipDownloadController` は選択された**すべてのパスに対して個別に** `DOWNLOAD_ZIP` Voter チェックを実行します。 |
| 5 | **SVG XSS、XXE、`<use>` DoS ボム** | `SvgSanitizer` が `<!DOCTYPE>` / `<!ENTITY>` を拒否し、**厳格な 55 タグの許可リスト**を適用。SVG サイズ上限を **5 MB** に制限し、CSS 構文（`@import`, `expression()`, `url(javascript:)`）をフィルタリング、`<script>` / `<foreignObject>` / `on*` / `javascript:` を除去し、`<use>` 展開 DoS を上限管理します（最大 `100` 個の `<use>`、深さ `4`、展開ノード `1000`、解析ステップ `5000`、重複 ID `8`）。 |
| 6 | **SSRF と DNS リバインディング（リモート URL インポート）** | `RemoteUrlImporter` はデフォルトで無効（`allow_remote_url_import: false`）です。有効化時も `inet_pton()` で IP を検証し、ループバック、プライベートレンジ、`0.0.0.0`、`100.64.0.0/10` CGNAT、クラウドメタデータ（`169.254.169.254`）、IPv4 射影 IPv6（`::ffff:127.0.0.1`）、NAT64（`64:ff9b::`）、SIIT/ORCHID/SRv6、さらに UTS-46 IDNA / 全角 / 8 進・16 進 IP バイパスを遮断し、**`CURLOPT_RESOLVE` による IP ピン留め**で DNS リバインディング攻撃を排除します。 |
| 7 | **画像エディタのポリグロット RCE** | `ImageEditorController` は `overwrite` を既存の安全なラスター画像（`jpg/jpeg/png/webp`）のみに制限し、`upload_max_size`、プロファイルの `upload_allow` / `upload_deny`、アセット使用ガードチェック、および `getimagesizefromstring()` による厳密な画像検証を適用します。 |
| 8 | **展開ボム（ピクセルボム）と ImageTragick** | `GdThumbnailDriver` と `ImagickThumbnailDriver` はメモリへデコードする前に `getimagesizefromstring()` で寸法を検査（最大 `8192×8192`・`40 MP` を強制）し、Imagick に対して厳格なメモリ・実行時間・スレッド制限を適用します。 |
| 9 | **シンボリックリンク脱出とルート閉じ込め** | `ConfinedLocalFilesystemAdapter` はローカルの読み込み・書き込み・移動・コピー・削除ごとに全パスセグメントで `is_link()` + `realpath()` によるルート閉じ込めを検証します。シンボリックリンクを含むサブツリーの再帰削除 / 移動は拒否され、ディレクトリ一覧では `SKIP_LINKS` が強制されます。 |
| 10 | **マルチテナント（`{user_id}`）の衝突防止** | `DynamicPathResolver` はメールアドレス、大文字小文字混在、Unicode、予約名（`guest`, `user`）、Windows デバイス名などに対して一意な SHA-256 サフィックス `<slug>--h-<16-hex>` を付与してユーザーディレクトリを分離し、異なるユーザー間でのディレクトリ衝突を構造的に不可能にします。 |
| 11 | **ダウンロード・サムネイル・CDN キャッシュ分離** | `DownloadController`、`ThumbnailController`、`ZipDownloadController` は `X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer`、`Content-Security-Policy: default-src 'none'; sandbox` を送出します。プライベート / Voter / セッション / `{user_id}` コンテキストでは `Cache-Control: private` + `Vary: Cookie, Authorization` を強制し、`?refresh=1` および `?fallback=1` には `CLEAR_CACHE` 権限を要求、HTML / JS / SVG / XML には `Content-Disposition: attachment` を強制します。 |
| 12 | **FormType POST 改ざんと Twig オリジン保護** | `FileManagerPickerType` はサーバー側で改ざんされた POST ペイロード（XSS プロトコル、`../`、`.trash/`、256 KB 超の JSON、500 件超のアイテム、不正 UTF-8、`max_files` 超過、HPP 配列）を拒否します。`FileManagerTwigExtension` とフロントエンドの `isSafeUrl()` が外部・危険な URL オリジンのなりすましを防止します。 |
| 13 | **任意テーマ・アイコンディレクトリ指定と DOM XSS** | 実行時の `dialog?theme=` は 9 種類の組み込みテーマのみ、`dialog?iconSet=` は 6 種類の組み込みアイコンセットのみを許可します。`sanitizeHexColor()` がカラーコードインジェクションを防止し、チャンク一時保存ディレクトリはユーザー単位で分離（`0750`）されます。 |
| 14 | **DoS・ログインジェクション・キャッシュ分離** | 一括削除は最大 200 件、チャンクアップロードは最大 2000 チャンク、フォルダの再帰コピーは最大 5000 アイテムに制限されます。`AuditLogListener` はログインジェクション文字列（`\r` / `\n` / ANSI / 制御文字）を除去し、`DirectoryCacheManager` はホストアプリの `cache.app` プール全体を消去しません（`clear()` を呼び出しません）。 |

---

## 5. Web サーバー側のハードニング（Apache と Nginx）

追加の防御層として、`public/uploads` のような公開アップロードディレクトリ内では、Web サーバー側でも必ず PHP やスクリプトの実行を無効化してください。

### Apache（`public/uploads/.htaccess`）
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx（`nginx.conf`）
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. 署名付き共有リンクとアセット使用ガード

- **有効期限付き署名共有リンク（`ShareController`）:** `share.enabled: true` に設定すると、Symfony の `UriSigner`（HMAC + `expires` タイムスタンプ）で署名された一時 URL を発行できます。プロファイルで `share.enabled: false` に切り替えると既存のリンクはすべて即座に `403 Forbidden` で無効化され、期限切れリンクは `410 Gone` を返します。また、すべてのレスポンスに `Cache-Control: private, no-store` と厳格な CSP が付与されます。
- **アセット使用ガード（`AssetUsageCheckerInterface`）:** データベース上で参照されている使用中ファイルの削除・名前変更・移動・上書きを防止します（詳細は [10 — アセット使用ガード](10-asset-usage-guard.md) を参照してください）。

---

## 7. 監査ログ — 本番環境向けセットアップと実装例

`AuditLogListener` は、ファイル・フォルダに関する 9 種類の変更イベントすべてを構造化された PSR-3 コンテキスト（`action`、`profile`、`volume`、`path`、`user`、`ip` およびイベント固有のメタデータ）とともに記録します。さらに、制御文字・CRLF・BiDi 文字を自動で無害化し、ログインジェクションを防止します。

### ステップ 1: 監査ログを有効化する（`config/packages/liveux_file_manager.yaml`）

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # プロファイル単位で有効化または上書き
        public_temp:
            security:
                audit_log: false
```

### ステップ 2: 専用のローテーション JSON ログへ出力する（`monolog.yaml` + `services.yaml`）

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 90 日間の保持とローテーション
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### 構造化 JSON ログの出力例

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### 監査対象の 9 種類のライフサイクルイベント

| Symfony イベント | `action` キー | 追加コンテキストフィールド |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### ステップ 3（任意）: 監査ログを Doctrine データベーステーブルに永続化する

管理画面内で検索可能な監査ログを提供したい場合は、同じ Symfony イベントを購読するサブスクライバーを作成します。

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> 高トラフィックなアプリケーションでは、`$this->em->flush()` を同期的に呼び出す代わりに Symfony **Messenger** の非同期メッセージをディスパッチすることで、ファイル操作をノンブロッキングに保つことができます。

---

[← 前へ: 05 — FormType とエディタ連携](05-formtype-and-editor-integrations.md) | [目次](README.md) | [次へ: 07 — クラウドストレージと動的設定 →](07-cloud-storage-and-dynamic-config.md)
