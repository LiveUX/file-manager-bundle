# 12 — AI Agent Skills 指南（`SKILL.md` 与 `AGENTS.md`）

[← 上一篇：11 — 贡献与 PR 指南](11-contributing-and-pr-guide.md) | [文档索引](README.md) | [下一篇：13 — Doctrine 集成 →](13-doctrine-integration.md)

> [!TIP]
> **通俗来说：Agent Skills（`SKILL.md`）有什么用？**
> 如果你在开发中使用 **Antigravity、Cursor、Claude Code、GitHub Copilot 或 OpenAI Codex** 等 AI 编程助手，只需在项目中运行一次 `php bin/console file-manager:skills:install`。它会将本 Bundle 准确的 YAML 配置键、安全不变量与地道示例传授给你的 AI 助手——让它一次就生成可直接运行的代码，而不再“凭空捏造”不存在的配置选项。

**LiveUX FileManagerBundle** 内置了一套遵循开放 **Agent Skills（`SKILL.md`）** 规范的技能包，使现代 AI 编程助手（**Antigravity、Claude Code、Cursor、GitHub Copilot、OpenAI Codex、Windsurf** 以及 **Symfony AI Mate**）能够准确、安全、零猜测地集成该 Bundle。

---

## 1. Bundle 内置的 6 项专项技能

技能包采用**渐进式披露（Progressive Disclosure）**组织方式：AI 助手只会按需加载与当前任务相关的技能，从而保持上下文精简且聚焦：

| 技能名称（`skills/<name>/`） | 触发场景 | 参考文件 |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | 安装、路由、AssetMapper/Encore、Profile 与存储卷（Local/S3/R2/MinIO/Supabase）、配额（`storage_quota`、`show_quota`）、图片优化、重复文件检测 | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType`（单选/图库）、5 种内置变体、自定义 FormType Twig 模板（`liveux:file-manager:picker-changed`）、EasyAdmin `FileManagerField`、JS API、TinyMCE、CKEditor 5、Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 套内置主题、暗黑模式（`color_scheme`）、纯 CSS 或 10 个 Block 的自定义 Twig 主题、3 层 SVG 图标体系（49 个 UI 图标 + 14 个文件夹徽章） | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | `security.yaml` 防火墙、17 项 Voter 权限、自定义文件夹级 Voter（`unanimous`）、资源使用保护（`AssetUsageCheckerInterface` —— 删除/重命名/移动/覆盖）、签名分享链接、审计日志、Apache/Nginx 加固 | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | 动态数据库 `ConfigProviderInterface`、自定义 `FolderMetadataStorageInterface`、9 个 Symfony Event 监听器、Symfony AI（`AssetEnricherInterface`）以及全部 CLI 命令 | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Bundle 开发规范：PHP 8.3 / Symfony 7.2–8.0 兼容性、安全不变量、翻译一致性以及 `composer qa` PR 质量门禁 | `AGENTS.md`（包根目录） |

---

## 2. 将 Skills 安装到项目中的三种方式

### 方式 A — 内置 Symfony 控制台命令（推荐，零额外依赖）

```bash
# 适用于 Antigravity、Codex、OpenCode 与 Windsurf（.agents/skills/）
php bin/console file-manager:skills:install

# 适用于 Claude Code（.claude/skills/）
php bin/console file-manager:skills:install -t claude

# 适用于 Cursor（.cursor/skills/）
php bin/console file-manager:skills:install -t cursor

# 适用于 GitHub Copilot（.github/skills/）
php bin/console file-manager:skills:install -t copilot

# 一条命令安装到所有支持的 AI 工具目录
php bin/console file-manager:skills:install --target=all
```

### 全部命令选项

| 选项 / 简写 | 说明 | 示例 |
| :--- | :--- | :--- |
| `-t` / `--target` | 目标 AI 工具（`agents`、`claude`、`cursor`、`copilot`、`all`）。默认值：`agents`。 | `-t all` |
| `--dir` | 自定义目标目录路径。**安全规则：** 必须严格是 `%kernel.project_dir%` 内部的子目录（路径中包含 `..`、NUL 字节、指向项目根目录本身，或父目录为符号链接均会被拒绝）。 | `--dir=.custom/skills` |
| `--only` | 仅安装指定技能（支持逗号分隔的简短名或完整名）。 | `--only=setup,security` |
| `-f` / `--force` | 使用最新版本覆盖已存在的技能目录。 | `--force` |
| `--symlink` | 创建符号链接而非复制文件（在 Windows 上若无符号链接权限，会自动回退为文件复制）。 | `--symlink` |
| `--dry-run` | 预览将会安装哪些技能及其目标位置，不向磁盘写入任何内容。 | `--dry-run` |

### 方式 B — 通过 Symfony AI Mate（`symfony/ai-mate`）自动发现

本 Bundle 的 `composer.json` 已声明 `extra.ai-mate.skills`：

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

如果项目中已安装 `symfony/ai-mate`，只需运行一条命令即可自动发现并将全部 6 项技能同步到你的工作区：

```bash
vendor/bin/mate discover
```

### 方式 C — 开放 Agent Skills CLI（`npx skills`）

直接从 GitHub 安装到任意项目中：

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. 根目录下的 `AGENTS.md`

位于 Bundle 根目录的 `AGENTS.md` 文件为直接向本 Bundle 贡献代码的 AI Agent 提供始终生效的全局规则：
- 零 CSS 框架依赖及可选桥接器规范
- 严格的 PHP 类型声明（`declare(strict_types=1)`）与兼容 Symfony 7.2 / 8.0 的测试桩（Test Stubs）
- 严禁使用 Twig `|raw`，JavaScript 中强制使用 `isSafeUrl()` / `escapeHtml()`
- CSRF 优先校验、私有/Voter 缓存隔离，以及**全部 12 种内置 UI 语言**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）100% 翻译一致

---

## 4. 自动化 CI 校验

每次 PR 和 CI 运行时，`SkillsPackageIntegrityTest` 都会自动校验：
1. `AGENTS.md` 文件存在、体积不超过 **`<= 24,000 bytes`** 上下文预算，且未误包含 YAML Frontmatter。
2. `composer.json` 正确声明了 `extra.ai-mate.skills`。
3. `skills/` 下的每个目录都包含合法 YAML Frontmatter 的 `SKILL.md`，其中 `name` 与目录名完全一致、声明了 `license: MIT`、`description` 长度不少于 `40` 个字符，且每个文件不超过 `<= 500` 行。
4. `SKILL.md` 中的每一个相对 `references/*.md` 链接均指向磁盘上真实存在的文件。

---

[← 上一篇：11 — 贡献与 PR 指南](11-contributing-and-pr-guide.md) | [文档索引](README.md) | [下一篇：13 — Doctrine 集成 →](13-doctrine-integration.md)
