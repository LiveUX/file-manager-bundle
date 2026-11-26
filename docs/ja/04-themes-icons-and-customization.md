# 04 — テーマ・ダークモード・フォルダカスタマイズ・アイコンセット・カスタムデザイン

[← 前へ: 03 — 利用モードと JS API](03-usage-modes-and-js-api.md) | [目次](README.md) | [次へ: 05 — FormType とエディタ連携 →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle は、**CSS フレームワークへの依存が一切ない（依存ゼロ）** 設計になっています。お使いのプロジェクトが **Bootstrap 5、Tailwind CSS v3/v4、Shadcn/UI、Flowbite 4、DaisyUI 5、Tabler、Preline、Material** のいずれを使用していても、あるいは **CSS フレームワークをまったく使用していなくても**、追加のビルド手順（Tailwind の `@source` や purge 設定など）なしでそのまま動作します。

> **このガイドで学べること**  
> 1. **9 種類の組み込みテーマとダークモード:** YAML オプション 1 つで、アプリのビジュアルデザインやカラースキームに合わせられます。  
> 2. **フォルダのカラー変更とフォルダアイコン（バッジ）:** UI 上から、または PHP / DB からプログラムで、18 色以上のカラーと 14 種類の組み込みバッジ（さらに無制限のカスタム SVG バッジ）を設定できます。  
> 3. **独自アイコンセットの作成:** 9 種類のファイルアイコン、49 種類の UI アイコン、無制限のカスタムフォルダバッジをカバーする独自アイコンパックの作成手順をステップごとに解説します。  
> 4. **カスタムテーマの構築（3 段階の自由度）:** 純粋な CSS カスタムプロパティによるブランドテーマ化、`{% extends %}` を使った部分的な Twig オーバーライド、完全ヘッドレスなゼロからのレイアウト構築まで対応しています。

---

## 1. 9 種類の組み込みテーマ（どれを選ぶべきか？）

テーマは `defaults.theme`、`profiles.<name>.ui.theme`、TwigComponent の `theme` プロパティ、または `FileManagerPickerType` の `theme` オプションで指定できます。**全 9 テーマはライトモード・ダークモードの両方で WCAG 2.1 AA のコントラスト基準を満たすことが検証されています。**

| テーマキー | 最適な用途 | デザイン概要 |
| :--- | :--- | :--- |
| `bootstrap5` *(デフォルト)* | Bootstrap 5 やクラシックな Symfony 管理画面 | クラシックブルー（`#0d6efd`）、`10px / 8px / 6px` の角丸、クリーンなニュートラルボーダー |
| `tailwind` | Tailwind CSS v3/v4 で構築されたモダンな Web アプリ | インディゴ（`#6366f1`）アクセント、滑らかな `14px / 12px / 8px` 角丸、Slate パレット |
| `shadcn` | ミニマルなモノクロームの SaaS ダッシュボード | モノクローム Zinc サーフェス（ダークモードでは `#18181b` / `#fafafa`）、シャープな `8px / 6px` エッジ |
| `flowbite` | Flowbite 4 + Tailwind CSS を採用した管理画面 | Flowbite ブルー（`#1a56db`）と Gray-50/800/900 サーフェス、`8px` コントロール |
| `daisyui` | DaisyUI 5 で構築されたプロジェクト | ページに DaisyUI が読み込まれている場合、`--color-primary`、`--color-base-100/200/300`、`--radius-box` を**自動的に継承** |
| `tabler` | Tabler.io やエンタープライズ向け EasyAdmin 管理画面 | エンタープライズ向け Slate キャンバス、`#066fd1` ブルーアクセント、シャープな `4px / 6px` 角 |
| `preline` | Preline UI アプリケーション | 純粋なニュートラルサーフェス（`#fafafa` / `#171717`）と鮮やかなブルー（`#2563eb`）アクセント |
| `material` | Google Material Design 3 アプリケーション | トーナルパープルサーフェス（`#6750a4` / `#f3edf7`）と完全なピル型ボタン |
| `unstyled` | 独自の CSS でファイルマネージャーを完全にスタイリングしたい場合 | ホストアプリのフォントと透過サーフェスを継承しつつ、ポップオーバーやライトボックス向けに不透明な `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` のコントラストを維持 |

| ダークモード（`bootstrap5` + Dark） | DaisyUI 5 テーマブリッジ（`daisyui`） |
| :---: | :---: |
| ![ダークモードのファイルマネージャー](../images/browser-dark.png) | ![DaisyUI 5 テーマのショーケース](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **セキュリティルール（YAML 設定 vs 実行時の `theme`）:**  
> - `liveux_file_manager.yaml` では、9 種類の組み込みテーマ、独自のカスタム CSS テーマ名（例: `'corporate'`、`/^[a-zA-Z0-9_-]{1,40}$/` に一致するもの）、またはカスタム Twig テンプレートパス（例: `'file_manager/custom.html.twig'` や `'@App/file_manager'`）を設定できます。  
> - セキュリティ上の理由により、ブラウザ、URL クエリパラメータ、または `window.LiveUXFileManager.open({ theme: ... })` から渡される動的な `theme` 値には、**9 種類の組み込みテーマキーのみ**が許可されます（`FileManagerThemes::sanitizeDynamic()`）。

---

## 2. ダークモード・RTL・モーション軽減（Reduced Motion）

全 9 テーマ（`unstyled` を含む）が**自動ダークモードと手動ダークモードの両方**に対応しています。

### A) ホスト側の自動検出（`color_scheme: 'auto'`）
ページの `<html>` または `<body>` に以下のいずれかが設定されていると、ファイルマネージャー本体、モーダル `<dialog>`、および `FileManagerPickerType` フィールドが即座にダークモードへ切り替わります。
- `data-bs-theme="dark"` *(Bootstrap 5)*
- `.dark` クラス *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / カスタムテーマ)*

### B) YAML によるデフォルト設定・プロファイル別設定
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto'（ホストに追従） | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # このプロファイルのみダークモードで起動
```

### C) エンドユーザー設定（設定メニュー: `Auto / Light / Dark`）
ユーザーは上部ツールバーの **⚙ 設定** ポップオーバーから、ワンクリックで **Auto / ☀ Light / 🌙 Dark** を切り替えられます。選択内容はブラウザの `localStorage`（`liveux_fm_color_scheme`）に保存されます。

### D) グローバル RTL 対応と `prefers-reduced-motion`
- アラビア語（`ar`）、ペルシア語（`fa`）、ヘブライ語（`he`）、ウルドゥー語（`ur`）など、15 の右から左へ書く（RTL）ロケールでは `dir="rtl"` が自動的に適用されます。`core.css` のすべての余白・配置には CSS 論理プロパティ（`inset-inline`、`padding-inline`、`border-inline-end`）が使用されています。
- OS 側で「視差効果を減らす」（`prefers-reduced-motion: reduce`）を有効にしているユーザーに対しては、アニメーションが自動的に最小限に抑えられます。

---

## 3. フォルダのカラー変更とフォルダアイコン（バッジ）設定（ステップバイステップ）

![フォルダのカラーパレットとバッジカスタマイズモーダル](../images/folder-customize.png)

任意のフォルダにカスタムの**カラー**、**バッジアイコン**、**ピン留め状態（⭐ ピン留め）**を割り当てることができます。これらの機能は `defaults.metadata.enabled: true` が有効で、かつユーザーが `permissions.edit_metadata` 権限を持っている場合に利用できます。

### 方法 1: UI から設定する（エンドユーザー向け）
1. 任意のフォルダを**右クリック**して **カスタマイズ（Customize）** を選択します（またはフォルダ選択中に **`I`** キーを押すか、右側のインスペクターパネルで **カスタマイズ** をクリックします）。
2. **カスタマイズ** モーダルで、ライブプレビューを確認しながら 3 つの項目を設定できます。
   - **18 色プリセットパレット:** Blue (`#2563eb`), Sky (`#0284c7`), Cyan (`#0891b2`), Teal (`#0d9488`), Emerald (`#059669`), Green (`#16a34a`), Lime (`#65a30d`), Amber (`#d97706`), Orange (`#ea580c`), Red (`#dc2626`), Rose (`#e11d48`), Pink (`#db2777`), Fuchsia (`#c026d3`), Purple (`#9333ea`), Violet (`#7c3aed`), Indigo (`#4f46e5`), Slate (`#475569`), Charcoal (`#1e293b`)。
   - **カスタムカラーピッカー + HEX 入力:** ブラウザ標準のカラーピッカーで任意のブランドカラーを選ぶか、`#RRGGBB` 形式の 16 進カラーコードを直接入力できます。カスタムスタイルを解除するには **デフォルトにリセット** をクリックします。
   - **フォルダアイコン（バッジ）グリッド:** **14 種類の組み込みバッジ**（`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`）に加え、アイコンセットの `badges/` ディレクトリに追加した任意のカスタム SVG ファイルが表示されます。
3. **保存** をクリックすると、グリッド / リスト表示と左側のフォルダツリーの両方に即座に反映されます。

### 方法 2: PHP からプログラムで設定する（例: 新規顧客フォルダの作成時）
`FolderMetadataStorageInterface` と `PathNormalizer::buildCanonicalKey()` を使えば、PHP コードから直接カラー・バッジ・ピン留めを設定できます（まったく同じコードが `json_file` ドライバと `doctrine` ドライバの両方でそのまま動作します）。

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // 正規キーを生成（例: "local::invoices/2026"）
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // ティール（#RRGGBB）
            icon: 'lock',       // 14 種類の組み込みバッジ、またはカスタム badges/invoice.svg -> 'invoice'
            pinned: true,       // サイドバーの ⭐ ピン留めフォルダに追加
        ));
    }
}
```

### 方法 3: `metadata.json` に直接記述する（`driver: 'json_file'` 使用時）
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. アイコンセットと独自アイコンセットのゼロからの作成

ファイル種別、フォルダ、上部ツールバー、コンテキストメニュー、クラウドディスク表示、インスペクターパネル、フォルダバッジなど、バンドル内の**すべてのアイコン**は単一の `IconSetRegistry` から統一的に配信されます。6 種類の組み込みアイコンセットは PSR-6 キャッシュに 24 時間キャッシュされます（`liveux_fm_iconset_*`）。

### 6 種類の組み込みアイコンセット（`profiles.<name>.ui.icon_set`）
| アイコンセット | ビジュアルスタイル | デフォルトフォルダ色（`meta.json`） |
| :--- | :--- | :--- |
| `classic` *(デフォルト)* | フラットでシャープなベクターアイコン | `#2563eb` |
| `windows11` | Windows 11 風のツートーン暖色イエローフォルダとモダンなドキュメントアイコン | `#f59e0b` |
| `macos` | macOS Finder 風の柔らかなブルーフォルダ | `#3b82f6` |
| `linux` | クリーンなオープンソース GNOME / Adwaita テイスト | `#3584e4` |
| `minimal` | 細線（アウトライン）のミニマルアイコン | `#475569` |
| `vscode` | シャープで開発者向けの VS Code 風アイコン | `#dcb67a` |

### ステップバイステップ: 独自のカスタムアイコンセットを作成する

#### ステップ 1: プロジェクト内にアイコン用ディレクトリを作成する
たとえば `assets/fm-icons/` を作成します。**変更しないアイコンをコピーする必要はありません。** 存在しないアイコンは自動的に組み込みの `classic` / `_ui` / `_folder_badges` セットへフォールバックされます。

```text
assets/fm-icons/
├── meta.json               # デフォルトフォルダ色: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # （任意）カスタムのメインフォルダアイコン
├── pdf.svg                 # （任意）PDF アイコンのみを上書き
├── ui/                     # （任意）ツールバー・コンテキストメニュー・ボリュームアイコンの上書きや追加
│   ├── upload.svg          # アップロードアイコンを上書き
│   ├── new_folder.svg      # 新規フォルダアイコンを上書き
│   └── company_cloud.svg   # カスタムボリュームアイコンを追加 -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # （任意）既存フォルダバッジの上書きや新規追加
    ├── lock.svg            # 組み込みの "lock" バッジを上書き
    └── invoice.svg         # 新しいバッジを追加 -> フォルダカスタマイズモーダルに自動表示！
```

#### ステップ 2: `meta.json` と動的に色が変わる `folder.svg` を作成する

> [!TIP]
> **黄金ルール（`currentColor`）:** ユーザーがフォルダの色を変更した際にカスタムの `folder.svg` が自動的に追従するように、固定の 16 進カラーコードではなく **`fill="currentColor"`**（アウトラインアイコンの場合は `stroke="currentColor"`）を使用してください。

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/invoice.svg（新規カスタムフォルダバッジ） -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### ステップ 3: YAML プロファイルからディレクトリを指定する
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: '社内アーカイブ'
            icon: 'company_cloud' # assets/fm-icons/ui/company_cloud.svg を使用！
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### カスタムアイコンのセキュリティと制限（`IconSetRegistry`）
- 各 `.svg` ファイルの最大サイズは **256 KB**（`262,144` バイト）で、読み込み時に **`SvgSanitizer`** によって自動的に無害化されます（`<script>` や `on*` ハンドラなどを除去）。
- `ui/` と `badges/` の両方で、組み込みキーに加えて最大 **200 個のカスタム `.svg`** ファイルがスキャンされます。
- カスタムファイル名（`.svg` を除く）は **`/^[a-zA-Z0-9_-]{1,32}$/`**（半角英数字、`-`、`_`、最大 32 文字）に一致する必要があります。

#### 上書き可能なアイコンキー完全リファレンス
- **9 種類のファイル・フォルダアイコン（ルート `<dir>/<key>.svg`）:**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 種類の UI アイコン（`<dir>/ui/<key>.svg`）:**
  - *ナビゲーション & レイアウト:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *ファイル・フォルダ操作:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *ストレージ & クラウドプロバイダー:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 種類の組み込みフォルダバッジ（`<dir>/badges/<key>.svg`）:**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(さらに独自に追加した `*.svg` ファイルすべて！)*

---

## 5. カスタムテーマの構築（3 段階の自由度）

`Browser.html.twig` はプロファイルに設定された `theme` 値を、次の**厳密な優先順位**で解決します。
1. `.html.twig` で終わる場合 → 指定された Twig テンプレートを直接レンダリングします（`{% include theme %}`）。
2. `@` で始まる場合 → その Twig 名前空間内の `layout.html.twig` をレンダリングします（`{% include theme ~ '/layout.html.twig' %}`）。
3. 9 種類の組み込みテーマのいずれかに一致する場合 → バンドル組み込みのテーマをレンダリングします。
4. その他の短い識別子（例: `'corporate'`、`/^[a-zA-Z0-9_-]{1,40}$/` に一致） → デフォルトのベースレイアウトを `<div data-liveux-theme="corporate">` でラップしてレンダリングします。

### レベル 1: 純粋な CSS 変数によるブランドテーマ（最速・テンプレート不要！）

#### CSS カスタムプロパティ完全リファレンス（`--liveux-fm-*`）
| カテゴリ | CSS 変数 |
| :--- | :--- |
| **タイポグラフィ** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **サーフェス & ボーダー** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **テキストカラー** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **ブランドアクセントカラー** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **セマンティックステータスカラー（WCAG AA）** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **角丸 & シャドウ** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### コピペで使えるカスタムブランドテーマ（`assets/styles/app.css`）
```css
/* 1) ライトモードのブランドパレット */
[data-liveux-theme="corporate"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) ダークモード対応（Browser と FormType Picker の両方、自動・手動ダークモードに対応） */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"],
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'corporate'
```

---

### レベル 2: Twig の `{% extends %}` による部分オーバーライド（推奨！）

ベースレイアウトは **10 個の `{% block %}` スロット**と **7 個のモジュラーパーシャル（`parts/`）** で構成されています。テンプレート全体を複製することなく、トップバーやフッターなど必要な箇所だけをカスタマイズできます。

| ブロック名 | 範囲 | デフォルトのパーシャル |
| :--- | :--- | :--- |
| `fm_shell` | 外側のシェルラッパー（`.liveux-fm-shell`） | 内側の全ブロックを包含 |
| `fm_topbar` | 上部コマンドバー（ナビゲーション、パンくずリスト、検索、並び替え、アクション） | `parts/_topbar.html.twig` |
| `fm_settings_popover` | 設定ドロップダウン（カラーモード、アイテムサイズ、フォルダスタイル、キャッシュ、ショートカット） | `parts/_settings_popover.html.twig` |
| `fm_banners` | ステータス通知バナー + アップロードキュー | インラインマークアップ |
| `fm_body` | 中央のメイン領域（サイドバー + メイン + インスペクターのラッパー） | `.liveux-fm-body` |
| `fm_sidebar` | 左側のフォルダツリー + ピン留めフォルダ + クォータバー + ゴミ箱 | `parts/_sidebar.html.twig` |
| `fm_main` | ファイルのグリッド / リスト + フローティング選択・クリップボードバー + ドロップゾーン + ページネーション | `parts/_main.html.twig` |
| `fm_inspector` | 右側のプレビュー & メタデータインスペクターパネル | `parts/_inspector.html.twig` |
| `fm_footer` | モーダル / オフキャンバスのフッターバー | `parts/_footer.html.twig` |
| `fm_overlays` | コンテキストメニュー、モーダル、ライトボックス、ショートカット / バージョン情報テンプレート | `parts/_overlays.html.twig` |

#### 例 — ブランドヘッダー帯 + カスタム選択フッターの追加
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 デジタルアセット管理ハブ</span>
        <span>プロファイル: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">キャンセル</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">選択を確定</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) .html.twig テンプレートパスを直接指定:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) または Twig 名前空間ディレクトリを指定（内部の layout.html.twig を自動読み込み）:
                # theme: '@App/file_manager'
```

---

### レベル 3: ゼロからの完全ヘッドレスレイアウト（必須 Stimulus ターゲット）

`{% extends %}` を使わずに独自の HTML 構造をゼロから組みたい場合は、プレフィックス `ctrl = 'liveux--file-manager-bundle--browser'` を使って以下の `data-{{ ctrl }}-target` 属性を配置するだけで連携できます。

| ターゲット（`data-...-target`） | 必須？ | 役割 |
| :--- | :---: | :--- |
| `shell` | 推奨 | アイテムサイズ倍率（`--liveux-fm-card-min`）、`data-size-tier`、`data-folder-style`、`data-sidebar-open` の管理 |
| `contentArea` | **必須** | ファイル / フォルダのカードやリスト行が描画されるメインキャンバス |
| `modalBackdrop`, `modalTitle`, `modalBody` | **必須** | 新規フォルダ・アップロード・削除・カスタマイズ・クォータ・メタデータなどの各種モーダル用コンテナ |
| `breadcrumbs` | 推奨 | クリック可能なパスのパンくずリスト |
| `confirmSelectionBtn`, `footerStatus` | 推奨（Modal/OffCanvas） | 選択確定ボタン（未選択時は自動で `disabled`）およびステータス表示 |
| `statusBanner`, `uploadQueue` | 推奨 | エラー・成功通知およびチャンクアップロードの進捗キュー |
| `tree` + `sidebar` + `pinnedFolders` | 任意 | 左側のフォルダツリーとピン留めフォルダ |
| `contextMenu` | 任意 | 右クリックのコンテキストメニュー用コンテナ |
| `inspector` | 任意 | 右側のプレビュー & メタデータパネル |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | 任意 | ユニバーサルメディアプレビューライトボックス |

---

## 6. カスタムテンプレート向け 7 つの Twig ヘルパー関数

| Twig 関数 | 用途 | 使用例 |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | XSS 対策済みのインライン SVG を出力します（`\|raw` 不要）。`group`: `'ui'`, `'icons'`, `'badges'`。 | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | ファイルの安全なサムネイル URL を生成します。 | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | 安全なダウンロード URL またはインラインプレビュー URL を生成します。 | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">ダウンロード</a>` |
| `liveux_fm_trans(key, params, locale)` | 確実な `'en'` フォールバック付きでバンドルの翻訳キーを翻訳します。 | `{{ liveux_fm_trans('ui.upload', {}, 'en') }}` |
| `liveux_fm_bcp47_locale(locale)` | `en_US.UTF-8` のような PHP/ICU ロケールコードを、有効な HTML `lang="..."` BCP-47 タグ（`en-US`）に変換します。 | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | 指定ロケールが右から左へ書く言語（アラビア語、ペルシア語、ヘブライ語など）の場合に `true` を返します。 | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Form / EasyAdmin の値を安全に正規化し、レンダリング可能な画像・ファイル記述子に変換します（`javascript:` / `data:` URL をブロック）。 | EasyAdmin の一覧・詳細画面やカスタムテンプレート |

---

[← 前へ: 03 — 利用モードと JS API](03-usage-modes-and-js-api.md) | [目次](README.md) | [次へ: 05 — FormType とエディタ連携 →](05-formtype-and-editor-integrations.md)
