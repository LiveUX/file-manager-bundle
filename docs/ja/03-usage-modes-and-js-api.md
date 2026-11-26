# 03 — 利用モードと JavaScript API

[← 前へ: 02 — 設定とプロファイル](02-configuration-and-profiles.md) | [ドキュメント一覧](README.md) | [次へ: 04 — テーマ・アイコンセットとカスタマイズ →](04-themes-icons-and-customization.md)

LiveUX FileManagerBundle は、ページ内への **インライン** 埋め込み、画面中央の **モーダル** 表示、4 方向からスライドインする **オフキャンバス** ドロワー、さらに任意の場所からプログラムで呼び出せる **JavaScript Promise API** の 4 通りの利用モードに対応しています。

> [!TIP]
> **どのモードを選ぶべきですか？**  
> - **インライン（Inline）:** ファイルマネージャーがメインコンテンツ領域を占める専用の「メディアライブラリ」ページ向け。  
> - **モーダル（Modal）:** 商品や記事の編集画面で「画像を選択」ボタンから中央ダイアログを開き、選択完了と同時に閉じる用途向け。  
> - **オフキャンバス（OffCanvas）:** フォームを背後に見せたまま、画面端（`start`、`end`、`top`、`bottom`）からモダンにスライド表示したい場合向け。  
> - **JavaScript API（`window.LiveUXFileManager.open()`）:** Symfony フォームを使わず、任意の HTML ボタン、React / Vue / Alpine コンポーネント、または素の JavaScript からピッカーを起動したい場合向け。

---

## 1. インラインモード — TwigComponent

![インライン表示のファイルマネージャー](../images/browser-light.png)

フル機能のインラインファイルマネージャーは、Twig の呼び出し 1 つで埋め込めます。明示的な高さ（`680px` や `calc(100vh - 140px)` など）を指定したコンテナでラップしてください。

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Symfony UX の `<twig:...>` コンポーネントタグ構文も使用できます。

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="ja"
    />
</div>
```

### `Browser` コンポーネントの全プロパティ

| プロパティ | 型 | デフォルト | 説明 |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | `liveux_file_manager.yaml` で定義したプロファイルキー。 |
| `volume` | `?string` | `null` | 初期表示するボリュームキー（`null` の場合はプロファイルの先頭ボリュームを開きます）。 |
| `path` | `string` | `''` | 初期表示するサブディレクトリ（例: `'products/2026'`）。 |
| `multiple` | `bool` | `false` | 複数ファイル選択モードを有効にします。 |
| `mode` | `string` | `'inline'` | `'inline'`、`'modal'`、または `'offcanvas'`。 |
| `typeFilter` | `string` | `'all'` | 初期の種類フィルター: `'all'`、`'folder'`、`'image'`、`'doc'`、または `'video'`。 |
| `theme` | `?string` | `null` | 実行時のテーマ上書き（9 種の組み込みテーマ `bootstrap5`、`tailwind`、`shadcn`、`flowbite`、`daisyui`、`tabler`、`preline`、`material`、`unstyled` を指定可能）。カスタム CSS / Twig テーマは YAML で設定します。 |
| `iconSet` | `?string` | `null` | 実行時のアイコンセット上書き（`classic`、`windows11`、`macos`、`linux`、`minimal`、`vscode`）。カスタム SVG ディレクトリは YAML で設定します。 |
| `locale` | `?string` | `null` | 実行時のロケール上書き。**12 言語の組み込み UI 翻訳**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）または任意の BCP-47 / POSIX ロケール（例: `'ar'`、`'zh_Hans_CN'`）に対応します。 |

---

## 2. モーダル＆オフキャンバス — プログラム用 JavaScript Promise API

`DialogHost` はすべてのフル HTML レスポンスに自動挿入されます（`defaults.auto_inject_dialog_host: true`）。どのページからでも `async/await` で `window.LiveUXFileManager.open()` を呼び出せます。

### `open(options)` の全オプション

| オプション | 型 | デフォルト | 説明 |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | 開くプロファイル名。 |
| `volume` | `?string` | `null` | 初期ストレージボリュームキー。 |
| `path` | `string` | `''` | 初期フォルダーパス（例: `'banners'`）。 |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | 中央表示のモーダルダイアログ、またはスライドインのオフキャンバスドロワー。 |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | `mode: 'offcanvas'` 時にスライドしてくる方向（`start` = 左、`end` = 右。RTL ロケールでは自動的に左右反転されます）。 |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *（プロファイル継承）* | モバイル画面幅（`<= 767.98px`）でのレイアウトを上書きします。 |
| `multiple` | `bool` | `false` | `false` = 単一ファイル選択、`true` = 複数ファイル選択。 |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | 特定のファイル種別で絞り込んだ状態で開きます（`filetype` は TinyMCE 互換エイリアス。`'media'` は自動的に `'video'` へマッピングされます）。 |
| `theme` | `?string` | `null` | 9 種の組み込みテーマを実行時に上書きします（カスタム Twig テンプレートパスはセキュリティのためサーバー側 YAML のみで設定可能です）。 |
| `iconSet` | `?string` | `null` | 6 種の組み込みアイコンセットを実行時に上書きします。 |
| `locale` | `?string` | `null` | 実行時のロケール上書き（`'ja'`、`'en'`、`'tr'`、`'fr'`、`'de'`、`'ar'`、`'zh_CN'` など）。 |

### 実践例: hidden `<input>` + リアルタイム画像プレビュー（Symfony フォーム不要）

以下のスニペットを任意の HTML または Twig ページへ貼り付けるだけでそのまま動作します。

```html
<!-- 1. hidden フィールド + ライブプレビュー + 起動ボタン -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="プレビュー" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">カバー画像を選択</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // 画像のみに絞り込んだ状態で開く
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // ユーザーが ESC を押したか、選択せずにダイアログを閉じた場合
    if (selected.length === 0) {
        return;
    }

    const file = selected[0];
    document.getElementById('cover-url').value = file.url;

    const img = document.getElementById('cover-preview');
    if (file.isImage) {
        img.src = file.url;
        img.alt = file.meta?.alt || file.name;
        img.style.display = 'block';
    }
});
</script>
```

### クイックモード呼び出しスニペット

```html
<!-- 中央モーダル -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">モーダルを開く</button>

<!-- 右側スライドオーバー（OffCanvas） -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">右ドロワーを開く</button>

<!-- 下部ボトムシート（OffCanvas） -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">ボトムシートを開く</button>
```

> [!NOTE]
> **キャンセル時の挙動:** ユーザーがファイルを選ばずにダイアログを閉じた場合（`ESC`、閉じるボタン、または背景クリック）、Promise は **reject されず**、空配列（`[]`）でクリーンに resolve されます。
>
> **モバイルレスポンシブ対応（`mobileMode` / `ui.mobile_dialog_mode`）:** 画面幅 `<= 767.98px` ではダイアログが自動的に最適化されます。`'auto'` モードではモーダルと左右ドロワー（`start` / `end`）が端まで広がる全画面表示（`100dvh`）になり、`bottom` / `top` ドロワーはドラッグハンドル付きの角丸ネイティブシートとして開きます。モバイルでは左のフォルダーツリーが折りたたまれた状態で始まり、タップでスライド表示されます。

| デスクトップ OffCanvas パネル（`position: 'end'`） | モバイルボトムシート（`mobileMode: 'sheet'`） |
| :---: | :---: |
| ![デスクトップ OffCanvas 表示](../images/offcanvas-desktop.png) | ![モバイルボトムシート表示](../images/mobile-sheet.png) |

### 返却されるファイルオブジェクトの構造

`window.LiveUXFileManager.open()` は、次の構造を持つオブジェクトの配列へ resolve されます。

```json
[
    {
        "kind": "file",
        "path": "products/matte-black-tumbler.webp",
        "url": "/uploads/products/matte-black-tumbler.webp",
        "name": "matte-black-tumbler.webp",
        "extension": "webp",
        "isImage": true,
        "meta": {
            "alt": "マットブラックのステンレス真空断熱タンブラー",
            "title": "タンブラー Pro",
            "caption": "500 ml 真空断熱ボトル",
            "tags": ["tumbler", "drinkware"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(補足: `private: true` のボリュームでは、`url` は RBAC 保護された `/_liveux-file-manager/download?...&inline=1` エンドポイントを指します。)*

### カスタム DOM イベント

`window.LiveUXFileManager.open()` を `await` するだけでなく、`window` 上でダイアログのライフサイクルイベントを直接購読することもできます。

| DOM イベント（`window.addEventListener`） | 発火タイミング | `event.detail` ペイロード |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | ダイアログ起動リクエストがディスパッチされたとき（`file_manager_api.js` と `dialog_controller.js` 間のブリッジ）。 | `open()` のオプション + `requestId` |
| `liveux:file-manager:dialog-opened` | ダイアログが開き、コンテンツのマウントが完了したとき。 | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | ユーザーが選択を確定した（またはダイアログをキャンセルした）とき。 | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('選択された項目数:', event.detail.items.length);
});
```

---

## 3. 組み込みのエンドユーザー機能

- **コンパクトなコンテキストメニュー:**
  - *ファイルまたはフォルダー上:* Windows 11 風の 1 行 SVG クイックアクションバー（`切り取り`、`コピー`、`名前を変更`、`ダウンロード` / `クイックアクセスにピン留め`、`削除`）に続き、グループ化されたワンクリック操作（プレビュー、画像を編集、詳細情報とタグ / SEO とフォーカルポイント、アーカイブを展開、リンクをコピー、共有リンクを作成、フォルダーへ移動…、複製、フォルダーの色）が表示されます。
  - *空白部分:* 上部のクイックナビゲーションバー（`← → ↑ ↻`）、新しいフォルダー、ここにアップロード、ここに貼り付け、すべて選択、並び替え、1 ページあたりの表示件数。*（全画面表示、キーボードショートカット、バージョン情報はツールバーの **⚙ 設定** メニュー内にあります。）*
  - *モバイル / タッチ端末:* 各カードの `⋯` ボタンから、タッチ操作しやすいボトムシート形式で開きます。
- **ユニバーサルプレビューアー（Lightbox v2）＆右側インスペクター（プレビューと詳細）:**
  - **画像:** ギャラリー送り（`←` / `→`）、拡大・縮小コントロール（`-` / `100%` / `+`）、フォーカルポイント表示。
  - **動画（`.mp4`、`.webm`、`.ogv`、`.mov`、`.m4v`）:** インスペクター内の `<video controls>` プレーヤー + 全画面 Lightbox + 非公開ボリュームでの `HTTP 206 Partial Content` シーク再生（プロファイルの `ui.allow_video_preview` で切替可能）。
  - **PDF（`.pdf`）:** Lightbox 内のサンドボックス化 `<iframe>` PDF ビューアー（`ui.allow_pdf_preview` で切替可能）。
  - **音声（`.mp3`、`.wav`、`.ogg`、`.m4a`、`.flac`、`.aac`）＆テキスト / コード（`.txt`、`.md`、`.json`、`.yaml`、`.csv`、`.css`、`.sql` など — 上限 64 KB）。**
  - `inline` モードでは、プレビュー可能なファイルを **ダブルクリック**（または `Space` キー）すると即座に Lightbox が開きます。
- **スマートアップロードキュー（一時停止 / 再開 / 確認付きキャンセル）:**
  - ドラッグ＆ドロップでファイル単位、**またはフォルダー階層丸ごと**（`webkitGetAsEntry`）アップロードでき、クリップボードからの `Ctrl+V` 貼り付けアップロードにも対応しています。
  - 分割アップロード中はリアルタイムのチャンク進捗（`2/5 • 40%`）、ワンクリックの **一時停止（`⏸`） / 再開（`▶`）**、誤操作を防ぐ確認付きの **キャンセル（`✕`）**、一括の **すべて一時停止 / すべてキャンセル** が利用でき、キャンセル時にはサーバー側の一時チャンクファイルも自動削除されます。
- **複数選択（範囲選択ラッソ対応）・クリップボード・詳細検索:**
  - 空白部分をクリック＆ドラッグして、複数のファイルやフォルダーを囲む **マーキー / ラッソ範囲選択** が可能です（`Ctrl` / `Cmd` を押しながら追加選択も可）。
  - `Shift+クリック` による範囲選択、`Ctrl+クリック` による個別切替、`Ctrl+A` による全選択、さらにフォルダー間やボリューム間をまたぐ `Ctrl+C` / `Ctrl+X` / `Ctrl+V` に対応しています。
  - **リスト表示の列見出し並び替え:** リスト表示で **名前**・**種類**・**サイズ**・**日付** の列見出しをクリックすると昇順 / 降順を切り替えられます。
  - **リアルタイム表示サイズスライダー＆フォルダーカード:** 設定（`⚙`）メニューからカードサイズをリアルタイム調整（`96 px – 320 px`）したり、フォルダー表示スタイルをコンパクト（`tile`）とカード（`card`）で切り替えたりできます。
  - 検索欄の詳細検索トグルを有効にすると、`#タグ` や `alt` メタデータを含めて全サブフォルダーを再帰的に検索できます。
  - サイドバーの **⭐ クイックアクセス** ピン留めフォルダー、クリックで内訳を確認できる **ストレージ使用状況と容量上限** モーダル、および **自動 / ライト / ダーク** のカラーモード切替を備えています。
- **0ms の画像解像度・アスペクト比バリデーション（`image_constraints`）:** 解像度やアスペクト比ルール（`1:1`、`16:9` など）を設定しておくと、条件に合わない画像はアップロード送信前にブラウザー側で即座に拒否されます（サーバー側でも二重検証されます）。
- **編集メタデータ・フォーカルポイント・署名付き共有リンク:**
  - `alt`、`title`、`caption`、`#tags`、クリック操作の `[x, y]` **フォーカルポイント** 指定、およびオプションの **✨ AI で自動提案** に対応しています。
  - 有効期限付き HMAC 署名共有リンク（`15 分`、`1 時間`、`24 時間`、`7 日`、`30 日`）を生成できます。
- **組み込み Canvas 画像エディター:** フリーハンドまたは比率固定（`1:1`、`4:3`、`16:9`）の切り抜き、ピクセルリサイズ（`最大幅` / `最大高さ`）、出力形式（`JPEG` / `PNG` / `WebP`）と画質調整、90° 回転、左右 / 上下反転、および「コピーとして保存」 / 「上書き保存」に対応しています。

| ファイルメタデータとフォーカルポイント（`I`） | 組み込み Canvas 画像エディター（`E`） |
| :---: | :---: |
| ![ファイルメタデータとフォーカルポイント](../images/file-metadata.png) | ![組み込み Canvas 画像エディター](../images/image-editor.png) |

- **グローバル i18n・RTL・アクセシビリティ（WCAG 2.1 AA）:**
  - **12 言語の組み込み UI と自動 RTL（`dir="rtl"`）:** `en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN` に対応。アラビア語（`ar`）、ペルシア語（`fa`）、ヘブライ語（`he`）、ウルドゥー語（`ur`）などの RTL ロケールでは、論理 CSS（`inset-inline`、`margin-inline`）によりレイアウト、パンくずリスト、サイドバー、オフキャンバスの方向が自動反転します。
  - **CJK / IME 変換保護:** 日本語・中国語・韓国語の IME 入力変換中（`event.isComposing`）に `Enter` やショートカットキーが誤発火するのを防ぎます。
  - **完全なキーボード＆スクリーンリーダー対応:** モーダルと Lightbox 内のフォーカストラップ（`Tab` / `Shift+Tab`）、コンテキストメニューでの矢印キー移動、`role="tree"` / `aria-expanded` / `aria-current` セマンティクス、閉じた際の自動フォーカス復帰を実装しています。
- **キーボードショートカット（完全リファレンス）:**
  - すべてのショートカットは、ファイルマネージャーにフォーカスがあり、テキスト入力欄（`<input>`、`<textarea>`、`<select>`）の外側にいる場合のみ有効になり、ユーザーの **Voter 権限** を厳密に尊重します（`ui.keyboard_shortcuts: true`）。
  - macOS では `Ctrl` と同様に **`Cmd (⌘)`** がそのまま機能します。
  - **多言語キーボード＆ IME 保護:** 物理キーコード（`event.code` の `Key*`）と文字フォールバックを併用しているため、非 QWERTY 配列や日本語・非ラテン配列でも確実に動作し、IME 変換中（`isComposing`）は誤動作しません。

#### A. ファイルとフォルダー操作
| ショートカット | アクション | 条件 / 補足 |
| :--- | :--- | :--- |
| `N` | 新しいフォルダーを作成 | ゴミ箱以外（`create_folder` 権限） |
| `U` | アップロードダイアログを開く | ゴミ箱以外（`upload` 権限） |
| `F2` | 選択項目の名前を変更 | 単一のファイルまたはフォルダー選択時（`rename` 権限） |
| `M` | フォルダーへ移動 / コピーモーダルを開く | 1 件以上選択時（`move` または `copy` 権限） |
| `D` | 選択したファイルを複製 | **単一ファイル** 選択時（`clone` 権限） |
| `I` | **フォルダー:** 色とアイコン / **ファイル:** 詳細情報・SEO とフォーカルポイント | 単一項目選択時（`edit_metadata` 権限） |
| `E` | 組み込み Canvas 画像エディターを開く | 単一のラスター画像（`.jpg`、`.jpeg`、`.png`、`.webp`）選択時（`upload` 権限） |
| `L` | ファイルリンクをクリップボードへコピー | 単一ファイル選択時（`download` 権限） |
| `S` | 有効期限付き HMAC 共有リンクモーダルを開く | 単一ファイル選択時（`share` 権限 + `share.enabled: true`） |
| `Delete` | 選択項目を削除（ゴミ箱移動または完全削除） | 1 件以上選択時（`delete` 権限） |

#### B. ナビゲーションとプレビュー
| ショートカット | アクション | 条件 / 補足 |
| :--- | :--- | :--- |
| `/` または `Ctrl + F` | 検索欄にフォーカス | `show_search: true` 時（`Ctrl + F` は入力欄内でも有効） |
| `←` `→` `↑` `↓` | グリッドカード間またはリスト行間を移動 | — |
| `Enter` | **フォルダー:** 開く • **単一ファイル:** インラインモードでは Lightbox を開く、モーダル / オフキャンバスでは選択を確定 | — |
| `Space` | 選択したファイルを Lightbox でプレビュー | 単一ファイル選択時 |
| `Backspace` または `Alt + ↑` | 上のフォルダー（親ディレクトリ）へ移動 | サブフォルダー内 |
| `Alt + ←` / `Alt + →` | 履歴を前に戻る / 次に進む | **RTL** ロケール（アラビア語・ペルシア語・ヘブライ語）では自動で左右反転 |
| `R` | 現在のディレクトリをサーバーから最新化（`?refresh=1`） | `show_refresh_button: true` 時 |

#### C. 選択・クリップボード・レイヤーを閉じる
| ショートカット | アクション | 条件 / 補足 |
| :--- | :--- | :--- |
| `Ctrl + A` | 表示中のすべての項目を選択 | ゴミ箱以外 |
| `Ctrl + C` / `Ctrl + X` | 選択項目をクリップボードへコピー / 切り取り | テキスト選択がない状態（`copy` / `move` 権限） |
| `Ctrl + V` | クリップボードのファイル / フォルダーを現在ディレクトリへ貼り付け、**または** OS クリップボードから画像 / ファイルを直接アップロード | アップロードモーダル表示中にも動作 |
| `Shift + クリック` / `Ctrl + クリック` | 範囲選択 / 個別の選択切替 | 空白部分のドラッグによるマーキー（ラッソ）選択も可能 |
| `Esc` | **4 段階スマート解除:** 1) 開いているメニュー / モーダル / Lightbox / 設定 / モバイルドロワーを閉じる → 2) 選択を解除 → 3) クリップボードをクリア → 4) インライン全画面表示を終了 | 1 回押すごとに最前面のレイヤーのみを解除 |
| `Tab` / `Shift + Tab` | 開いているモーダルや Lightbox 内の操作要素間を循環移動（フォーカストラップ） | — |

#### D. 表示とヘルプ
| ショートカット | アクション |
| :--- | :--- |
| `V` | グリッド / リスト表示を切替 |
| `B` | 左フォルダーツリーサイドバーを表示 / 非表示 |
| `F` | 全画面表示を切替 |
| `T` | ゴミ箱の表示切替（`trash.enabled: true` 時） |
| `?` またはアラビア語 `؟` | キーボードショートカット一覧モーダルを開く |

#### E. Lightbox プレビュー内
| ショートカット | アクション |
| :--- | :--- |
| `←` / `→` | 前へ / 次へ *（RTL ロケールでは自動で左右反転）* |
| `+`、`=`、または `テンキー +` | 拡大 |
| `-` または `テンキー -` | 縮小 |
| `0` または `テンキー 0` | 倍率を `100%` にリセット |
| `Esc` | Lightbox を閉じる |

#### F. コンテキストメニュー表示中
| ショートカット | アクション |
| :--- | :--- |
| `↑` / `↓` | メニュー項目を上下に移動 |
| `Home` / `End` | 先頭 / 末尾のメニュー項目へジャンプ |
| `Enter` / `Space` | フォーカス中のメニュー操作を実行 |
| `Tab` / `Esc` | コンテキストメニューを閉じる |

---

[← 前へ: 02 — 設定とプロファイル](02-configuration-and-profiles.md) | [ドキュメント一覧](README.md) | [次へ: 04 — テーマ・アイコンセットとカスタマイズ →](04-themes-icons-and-customization.md)
