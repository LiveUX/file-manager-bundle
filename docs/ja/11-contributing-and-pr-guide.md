# 11 — コントリビュートとプルリクエスト（PR）ガイド（初心者向けステップバイステップ）

[← 前へ: 10 — アセット使用状況ガード](10-asset-usage-guard.md) | [ドキュメント目次](README.md) | [次へ: 12 — AI エージェントスキル →](12-ai-agent-skills-guide.md)

このガイドは、**オープンソースプロジェクトにプルリクエスト（PR）を送ったことがない方**に向けて基礎から分かりやすく解説するとともに、自身のリポジトリに **Symfony スタイルの自動 PR 品質ゲート**を構築したいメンテナー向けにも書かれています。すべての概念とコマンドを順を追って説明します。

---

## 1. 全体像：オープンソースのプルリクエストはどう動くのか

Symfony、Laravel、React といった主要なオープンソースプロジェクトでは、外部コントリビューターがメインのコードベース（`main` ブランチ）へ直接プッシュすることはできません。代わりに、すべての変更は次の **4 段階の安全ゲート**を通過します。

1. **Fork & Branch（自分用のコピーを作成する）：** リポジトリを自分の GitHub アカウントへコピー（*Fork*）し、作業専用のブランチ（*Branch*）を作成します。
2. **実装とローカルチェック（`composer qa`）：** 変更を実装し、テストを書いて、手元のマシンで自動品質チェック一式を実行します。
3. **プルリクエスト（PR）の作成：** バグ修正や新機能をレビューしてメインリポジトリへ取り込んでもらうようリクエストを送信します。GitHub では **Symfony スタイルの PR サマリー表とチェックリスト**（`.github/PULL_REQUEST_TEMPLATE.md`）が自動的に表示されます。
4. **自動 CI チェック（GitHub Actions）＋ コードレビュー：**
   - PR を作成または更新した瞬間に、GitHub Actions（`.github/workflows/ci.yml`）がクラウド上のランナーを自動で起動します。
   - **PHP 8.3 & 8.4**、**Symfony 7.2、7.4 & 8.0**、**Node 20 & 22** の環境でコードをテストし、コーディング規約（**PHP-CS-Fixer**）、静的型チェック（**PHPStan Level 8**）、翻訳キーの完全性（**TranslationParityTest**）、ドキュメント内 YAML スキーマの整合性（**FullConfigReferenceDocTest**）、Agent Skills パッケージの整合性（**SkillsPackageIntegrityTest**）、および XSS/CSP/フロントエンドセキュリティ（**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**）を検証します。
   - **チェックが 1 つでも失敗すると、GitHub 上で Merge ボタンがブロックされます。**

---

## 2. 品質チェックの仕組みはすでに用意されていますか？

**はい、100% 設定済みですぐに使えます。** 本バンドルには、公式の Symfony UX 基準に準拠した品質ツールチェーン一式が同梱されています。

| ファイル | 役割（わかりやすく言うと） |
| :--- | :--- |
| [`composer.json`](../../composer.json) | ワンコマンドで実行できる `composer qa` スクリプト一式と、単体実行用の開発依存関係（`require-dev`）を定義します。 |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | ホストアプリケーションなしで、PHP のユニットテストおよびセキュリティテスト全件（**57 テストファイル、360 テスト、3,500+ アサーション**）を単体実行します。 |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | **PHPStan Level 8** の静的解析を設定し、型の不一致・不正な引数・未定義メソッド呼び出しを実行前に検出します。 |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | すべての PHP ファイルに PSR-12 + Symfony コーディング規約（`declare(strict_types=1)`、厳密比較 `===`、`use` 文のアルファベット順ソート）を適用します。 |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | PR の作成・更新ごとに **6 つの並列 CI ジョブ**を自動実行します。 |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | 新規 PR 作成時に Symfony スタイルのサマリー表（`Bug fix?`、`BC breaks?`、`Tests pass?`）とチェックリストを自動入力します。 |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | バグ報告（`bug_report.yml`）と機能リクエスト（`feature_request.yml`）用の構造化フォームを提供します。 |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | 新規 PR や Issue 画面に GitHub から自動リンクされる国際コントリビューター向けガイドラインです。 |
| [`SECURITY.md`](../../SECURITY.md) | 脆弱性を公開 Issue ではなく非公開で報告するためのセキュリティ開示ポリシーです。 |

### 自動で強制される 7 つの重要ルール

人間のレビュアーがうっかり見落としても、以下の問題があれば **自動テストスイートが即座に検知して PR を失敗（FAIL）させます**。

1. **翻訳の欠落（`TranslationParityTest`）：** 英語に新しい UI 文言を追加したのに、**12 の組み込み UI 言語**（`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`）のいずれかでキーが抜けていると、テストは **失敗（FAIL）** します。
2. **`strict_types` の欠落（`TranslationParityTest`）：** `src/` 配下の PHP ファイルが 1 つでも `declare(strict_types=1);` を記述していないと、テストは **失敗（FAIL）** します。
3. **XSS / CSP / フロントエンドセキュリティ違反（`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`）：** Twig 内で `onclick="..."` のようなインラインイベントハンドラや `|raw` フィルタを使ったり、JS の `javascript:` URL / DOM サニタイズ処理を壊したりすると、テストは **失敗（FAIL）** します。
4. **ドキュメント YAML スキーマの乖離（`FullConfigReferenceDocTest`）：** [設定とプロファイル](02-configuration-and-profiles.md) リファレンス（`docs/tr/02-yapilandirma-ve-profiller.md`）に記載されたすべての `liveux_file_manager:` YAML ブロックが、実際の `Configuration.php` スキーマに対して検証されます。
5. **Agent Skills の整合性（`SkillsPackageIntegrityTest`）：** `AGENTS.md` および全 6 つの `skills/*/SKILL.md` ファイルについて、フロントマター、行数・サイズ上限、参照リンクを検証します。
6. **コードスタイルと静的型安全性（`PHP-CS-Fixer` + `PHPStan Level 8`）：** PSR-12 / Symfony スタイル違反や Level 8 の型エラーがあると CI がブロックされます。
7. **圧縮済み `assets/dist/` の同期（`npm --prefix assets run build:check`）：** `assets/controllers/*.js` や `assets/styles/core.css` を編集した後に `assets/dist/` を再ビルドし忘れると、CI は **失敗（FAIL）** します。

---

## 2.5. JS & CSS ミニファイ（`assets/dist/`）アーキテクチャ（`esbuild`）

> [!NOTE]
> **なぜこの仕組みが必要なのですか？**
> - **Webpack Encore** を利用している場合は、`npm run build` によって JS/CSS が自動的にミニファイ（圧縮）されます。
> - 一方、**Symfony AssetMapper**（`asset-map:compile`）はファイルをミニファイせずそのまま配信します。元のソースファイルのままでは、`browser_controller.js` が `518 KB`、`core.css` が `132 KB` になります。
> - ホストプロジェクトに Node.js を要求することなく AssetMapper 利用者にも高速なページ読み込みを提供するため、公式 Symfony UX パッケージ（`ux-live-component`、`ux-chartjs`、`ux-autocomplete`）とまったく同じ **Source → Distribution（`assets/dist/`）** パターンを採用しています。

### 仕組み

1. **読みやすいソースファイル：** 開発およびセキュリティテストはすべて、整形・コメント済みのソースファイルに対して実行されます。
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` および `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` および `assets/images/liveux-logo.webp`
2. **本番向けミニファイ出力（`assets/dist/`）：** [`assets/build.mjs`](../../assets/build.mjs) スクリプトが **`esbuild`** を使って各 JS ファイルを独立した ESM モジュールとしてミニファイし（`@hotwired/stimulus` は外部依存として保持し、相対インポートも維持）、`core.css` も `assets/dist/` へビルドします。
3. **自動解決（破壊的変更ゼロ）：**
   - [`FileManagerBundle.php`](../../src/FileManagerBundle.php) の `prependAssetMapper()` は、`assets/dist` が存在する場合に `@liveux/file-manager-bundle` 名前空間を自動的に `assets/dist/` へマッピングします。
   - [`assets/package.json`](../../assets/package.json) の `"exports"` と `"symfony.controllers.*.main"` により、Webpack Encore でも `dist/` ファイルが直接読み込まれます。
   - `assets/dist/` は Git にコミットされているため、**エンドユーザーが Node.js や npm を用意する必要は一切ありません**。`.gitattributes` ではビルドツール類（`assets/node_modules`、`assets/build.mjs`、`assets/package-lock.json`）のみを Packagist アーカイブから除外し、`assets/package.json` と `assets/dist/` はそのまま含めます。

### 実測サイズ削減効果

| ファイル | 元ソース | ミニファイ後（`assets/dist/`） | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + エディタアダプター 3 種 | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **合計（ミニファイで `-45.1%`）** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### 開発者向けコマンド（JS または CSS を編集したとき）

```bash
# 1. 初回のみ esbuild をインストールします:
npm --prefix assets install

# 2. JS または CSS を編集したら assets/dist/ を再ビルドします（約 50 ms）:
npm --prefix assets run build
# または Composer ショートカットを使用:
composer assets:build

# 3. assets/dist/ がソースファイルと 100% 同期しているか検証します（CI と同じチェック）:
composer assets:check
```

---

## 3. プルリクエストを送るためのステップバイステップ手順

> [!TIP]
> **これまでに PR を送ったことがない方でも**、以下の 6 ステップを順番どおりに進めるだけで、CI を通過するきれいなプルリクエストを作成できます。

### ステップ 1：Fork、Clone、依存関係のインストール

1. GitHub で [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) を開き、右上の **Fork** をクリックして自分の GitHub アカウントにコピーを作成します。
2. 自分のフォークをローカルにクローンし、メインリポジトリを `upstream` として登録したうえで、PHP と Node の開発ツールをインストールします。

```bash
git clone https://github.com/<YOUR-USERNAME>/file-manager-bundle.git
cd file-manager-bundle

# メインリポジトリを upstream として登録（最新状態に追従するための良い習慣です）
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# PHP のテスト・静的解析ツールをインストール
composer install

# JS/CSS を編集する場合はミニファイア（esbuild）をインストール
npm --prefix assets install
```

### ステップ 2：最新の `main` から新しいブランチを作成する

`main` に直接コミットしてはいけません。まず `main` を最新に同期し、作業内容が分かりやすいブランチを作成します。
- バグ修正：`fix/folder-counter-cache`
- 新機能：`feat/new-icon-set`
- ドキュメント：`docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### ステップ 3：実装、テストの追加、必要に応じた `dist/` の再ビルド

- **バグを修正する場合：** まずバグを再現するテストを書き（修正前は赤、修正後は緑になることを確認）、その後で修正を実装します。
- **新機能を追加する場合：**
  - `tests/Unit/...` 配下に PHP ユニットテストを追加します。
  - UI / JS の変更では、`tests/Js/dom_security_test.mjs` または `tests/Js/frontend_audit_test.mjs` にテストを追加します。
  - **JS または CSS を変更した場合は必ず `composer assets:build` を実行してください**（忘れると CI の `build:check` で失敗します）。
  - 新しい UI 翻訳キーを追加した場合は、**12 の組み込み UI 言語カタログすべて**（`translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`）にキーを追加してください。

### ステップ 4：ローカルですべての品質チェックを一括実行する

PR を作成する前に、CI とまったく同じチェックを手元で実行します。

```bash
composer qa
composer assets:check
```

これらのコマンドは、以下のチェックを順番に実行します。
1. `composer validate --strict` — `composer.json` の妥当性を検証します。
2. `composer cs:check` — PSR-12 / Symfony コーディング規約をチェックします*（違反があれば `composer cs:fix` で自動整形できます）*。
3. `composer stan` — **PHPStan Level 8** の静的解析を実行します。
4. `composer test` — PHPUnit のユニット・セキュリティ・翻訳整合性・スキーマテスト一式を実行します。
5. `composer test:js:syntax` — すべてのソースおよび `dist/` 配下の JS ファイルに対して `node --check` で構文チェックを実行します。
6. `composer test:js` — Node.js の DOM セキュリティテストおよびフロントエンド監査スイート（`dom_security_test.mjs` + `frontend_audit_test.mjs`）を実行します。
7. `composer assets:check` — `assets/dist/` が現在のソースファイルとバイト単位で一致していることを確認します。

> [!TIP]
> **すべてグリーンになりましたか？** コミットしてプルリクエストを作成する準備が整いました。

### ステップ 5：きれいなコミットを作成して自分のフォークへプッシュする

```bash
git status            # 意図したファイルだけが変更されているか確認
git diff --stat       # 変更の概要を確認
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*（Conventional Commits のプレフィックス `feat:`、`fix:`、`docs:`、`test:`、`refactor:`、`perf:` を使うと履歴が分かりやすくなります。）*

### ステップ 6：GitHub でプルリクエストを作成し、CI がグリーンになるのを確認する

1. GitHub 上の自分のフォーク（`https://github.com/<YOUR-USERNAME>/file-manager-bundle`）を開き、**"Compare & pull request"** バナーをクリックします。
2. マージ先とマージ元を確認します：**Base repository:** `LiveUX/file-manager-bundle`（`base: main`） ← **Head repository:** `<YOUR-USERNAME>/file-manager-bundle`（`compare: fix/folder-counter-cache`）。
3. 自動入力された **PR テンプレート**の表（`Bug fix? yes`、`BC breaks? no`、`Tests pass? yes`）を記入し、チェックリスト（`[x]`）を確認します。UI に影響する変更の場合は、スクリーンショットや GIF を添付してください。
4. **"Create pull request"** をクリックします。
5. PR ページ下部で、すべての GitHub Actions チェック（`PHP 8.3/8.4`、`Node 20/22`、`PHPStan & CS-Fixer`）に **緑色のチェックマーク（✓）** が付くまで待ちます。
6. レビュアーから修正依頼があった場合は、同じブランチでファイルを編集し `git commit && git push` を実行するだけで、GitHub が PR を更新し CI も自動で再実行されます。

---

## 4. メンテナー向け：GitHub でのブランチ保護と Rulesets の設定

ワークフローファイルがリポジトリに含まれていれば CI は自動で実行されますが、**チェックが赤い状態では誰も（メンテナーであっても誤って）Merge ボタンを押せないようにする**ため、2 分ほどで GitHub **Ruleset** を設定しておきましょう。

1. GitHub でバンドルのリポジトリを開き、**Settings** へ移動します。
2. 左サイドバーから **Rules → Rulesets**（クラシック表示の場合は **Branches → Add branch protection rule**）を選択します。
3. **New branch ruleset** をクリックします。
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch`（`main`）
4. 次の 4 つの保護ルールを有効にします。
   - [x] **Restrict deletions**（`main` の削除を防止）
   - [x] **Block force pushes**（コミット履歴を保護）
   - [x] **Require a pull request before merging**（`main` への直接プッシュを禁止し、すべての変更を PR 経由にする）
     - *Required approvals:* `1`（チーム運用で推奨。単独メンテナーの場合は `0` のままでも構いません）
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *（最も重要な設定）*
     - [x] *Require branches to be up to date before merging*
     - 初回の CI 実行完了後、**Add checks** から以下の **6 ジョブすべて**を必須チェックとして追加します。
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. ページ下部の **Create** をクリックします。

---

## 5. バンドルを独立したオープンソースリポジトリとして公開するためのチェックリスト

本バンドルを GitHub および Packagist に `LiveUX/file-manager-bundle` として公開する手順は次のとおりです。

1. GitHub の `LiveUX` オーガニゼーション配下に、空の **Public** リポジトリ `file-manager-bundle` を新規作成します。
2. `bundles/liveux-file-manager-bundle/` の内容を初回コミットとしてプッシュします（`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`）。
3. **GitHub Actions** タブで 6 つの CI ジョブすべてが緑色のチェックマークで通過することを確認します。
4. 上記 **セクション 4** の **Ruleset** 設定を有効にします。
5. `v0.1.0` タグを作成してプッシュし（`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`）、[Packagist.org](https://packagist.org) に `https://github.com/LiveUX/file-manager-bundle` を登録します。
6. これで誰でも `composer require liveux/file-manager-bundle` の 1 コマンドだけでバンドルをインストールできるようになります。

---

[← 前へ: 10 — アセット使用状況ガード](10-asset-usage-guard.md) | [ドキュメント目次](README.md) | [次へ: 12 — AI エージェントスキル →](12-ai-agent-skills-guide.md)
