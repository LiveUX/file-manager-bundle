# 12 — AI エージェントスキルガイド（`SKILL.md` & `AGENTS.md`）

[← 前へ: 11 — コントリビュートと PR ガイド](11-contributing-and-pr-guide.md) | [ドキュメント目次](README.md) | [次へ: 13 — Doctrine 連携 →](13-doctrine-integration.md)

> [!TIP]
> **簡単に言うと、Agent Skills（`SKILL.md`）とは何ですか？**
> **Antigravity、Cursor、Claude Code、GitHub Copilot、OpenAI Codex** といった AI コーディングアシスタントを使っている場合、プロジェクトで `php bin/console file-manager:skills:install` を一度実行するだけで導入できます。バンドルの正確な YAML 設定キー、セキュリティ不変条件、推奨される実装パターンをアシスタントへ学習させられるため、存在しないオプションを捏造（ハルシネーション）することなく、最初から正しく動作するコードを生成できるようになります。

**LiveUX FileManagerBundle** には、オープン仕様の **Agent Skills（`SKILL.md`）** に準拠したスキルパッケージが組み込まれています。これにより、主要な AI コーディングアシスタント（**Antigravity、Claude Code、Cursor、GitHub Copilot、OpenAI Codex、Windsurf**、および **Symfony AI Mate**）が推測に頼らず、正確かつ安全にバンドルを統合できます。

---

## 1. バンドルに同梱されている 6 つの専門スキル

スキルは **段階的開示（Progressive Disclosure）** に基づいて設計されており、AI アシスタントは現在のタスクに関係するスキルだけを読み込むため、コンテキスト消費を抑えながら高精度な支援を受けられます。

| スキル名（`skills/<name>/`） | 有効化されるタイミング | リファレンスファイル |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | インストール、ルーティング、AssetMapper/Encore、プロファイルとボリューム（Local/S3/R2/MinIO/Supabase）、クォータ（`storage_quota`、`show_quota`）、画像最適化、重複ファイル検出 | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType`（単一 / ギャラリー）、5 種の組み込みバリアント、カスタム FormType Twig テンプレート（`liveux:file-manager:picker-changed`）、EasyAdmin `FileManagerField`、JS API、TinyMCE、CKEditor 5、Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 種の組み込みテーマ、ダークモード（`color_scheme`）、CSS のみまたは 10 ブロック構成のカスタム Twig テーマ、3 層 SVG アイコンシステム（49 UI アイコン + 14 フォルダバッジ） | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | `security.yaml` ファイアウォール、17 種の Voter 権限、フォルダ単位のカスタム Voter（`unanimous`）、アセット使用状況ガード（`AssetUsageCheckerInterface` — 削除 / リネーム / 移動 / 上書き）、署名付き共有リンク、監査ログ、Apache/Nginx 強化設定 | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | 動的 DB `ConfigProviderInterface`、カスタム `FolderMetadataStorageInterface`、9 種の Symfony イベントリスナー、Symfony AI（`AssetEnricherInterface`）、および全 CLI コマンド | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | PHP 8.3 / Symfony 7.2–8.0 互換性、セキュリティ不変条件、翻訳整合性、`composer qa` PR ゲートなどのバンドル開発ルール | `AGENTS.md`（パッケージルート） |

---

## 2. プロジェクトへの 3 つのインストール方法

### 方法 A — 組み込みの Symfony コンソールコマンド（推奨・追加依存なし）

```bash
# Antigravity、Codex、OpenCode、Windsurf 向け（.agents/skills/）
php bin/console file-manager:skills:install

# Claude Code 向け（.claude/skills/）
php bin/console file-manager:skills:install -t claude

# Cursor 向け（.cursor/skills/）
php bin/console file-manager:skills:install -t cursor

# GitHub Copilot 向け（.github/skills/）
php bin/console file-manager:skills:install -t copilot

# 対応するすべての AI ツールへ 1 コマンドで一括インストール
php bin/console file-manager:skills:install --target=all
```

### コマンドオプション一覧

| オプション / ショートカット | 説明 | 使用例 |
| :--- | :--- | :--- |
| `-t` / `--target` | 対象 AI ツール（`agents`、`claude`、`cursor`、`copilot`、`all`）。デフォルト：`agents`。 | `-t all` |
| `--dir` | 任意の出力先ディレクトリパス。**セキュリティルール：** `%kernel.project_dir%` 配下のサブディレクトリのみ厳格に許可されます（`..`、NUL バイト、プロジェクトルート自体、シンボリックリンクを含む親ディレクトリは拒否されます）。 | `--dir=.custom/skills` |
| `--only` | 指定したスキルのみをインストールします（短縮名またはフルネームをカンマ区切りで指定）。 | `--only=setup,security` |
| `-f` / `--force` | 既存のスキルディレクトリを最新版で上書きします。 | `--force` |
| `--symlink` | ファイルコピーの代わりにシンボリックリンクを作成します（Windows でシンボリックリンク権限がない場合は自動的にコピーへフォールバックします）。 | `--symlink` |
| `--dry-run` | ディスクへ一切書き込まず、どのスキルがどこにインストールされるかを事前確認します。 | `--dry-run` |

### 方法 B — Symfony AI Mate（`symfony/ai-mate`）による自動検出

本バンドルの `composer.json` には `extra.ai-mate.skills` が宣言されています。

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

プロジェクトに `symfony/ai-mate` を導入している場合は、次の 1 コマンドで全 6 スキルが自動検出されワークスペースへ同期されます。

```bash
vendor/bin/mate discover
```

### 方法 C — Open Agent Skills CLI（`npx skills`）

GitHub から任意のプロジェクトへ直接インストールできます。

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. パッケージルートの `AGENTS.md`

バンドルのルートディレクトリにある `AGENTS.md` は、本バンドル自体へコントリビュートする AI エージェント向けに常時適用されるルールを定義しています。
- CSS フレームワーク非依存の原則とオプショナルブリッジのルール
- 厳密な PHP 型宣言（`declare(strict_types=1)`）および Symfony 7.2 / 8.0 互換テストスタブ
- Twig `|raw` の禁止、JavaScript での `isSafeUrl()` / `escapeHtml()` の必須化
- CSRF 優先検証、private / Voter キャッシュの分離、および **12 の組み込み UI 言語**（`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`）すべてにおける 100% 翻訳整合性

---

## 4. CI による自動検証

すべての PR および CI 実行時に、`SkillsPackageIntegrityTest` が以下を自動検証します。
1. `AGENTS.md` が存在し、コンテキスト上限 **`<= 24,000 bytes`** 以内に収まっており、誤って YAML フロントマターを含んでいないこと。
2. `composer.json` に `extra.ai-mate.skills` が正しく定義されていること。
3. `skills/` 配下の各ディレクトリに有効な YAML フロントマター付き `SKILL.md` が存在し、`name` がディレクトリ名と完全一致し、`license: MIT` が宣言され、`description` が `40` 文字以上で、各ファイルが `<= 500` 行以内であること。
4. `SKILL.md` 内のすべての相対リンク `references/*.md` が、実在するファイルを指していること。

---

[← 前へ: 11 — コントリビュートと PR ガイド](11-contributing-and-pr-guide.md) | [ドキュメント目次](README.md) | [次へ: 13 — Doctrine 連携 →](13-doctrine-integration.md)
