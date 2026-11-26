# 11 — 贡献与 Pull Request (PR) 指南（新手分步教程）

[← 上一篇：10 — 资源使用保护](10-asset-usage-guard.md) | [文档索引](README.md) | [下一篇：12 — AI Agent Skills →](12-ai-agent-skills-guide.md)

本指南从零开始，既面向**从未向开源项目提交过 Pull Request (PR)** 的开发者，也面向希望在自己仓库中搭建 **Symfony 风格自动化 PR 质量门禁**的维护者。文中将逐步讲解每一个核心概念与命令。

---

## 1. 全局概览：开源项目的 Pull Request 是如何运作的

在 Symfony、Laravel、React 等大型开源项目中，外部贡献者无法直接向主代码库（`main` 分支）推送代码。相反，每一项变更都会经过一套**四阶段安全门禁**：

1. **Fork 并创建分支（获取你自己的副本）：** 将仓库复制到你自己的 GitHub 账号下（*Fork*），并创建一个专用的工作分支（*Branch*）。
2. **编写代码并执行本地检查（`composer qa`）：** 实现变更、编写测试，并在本机运行完整的自动化质量检查套件。
3. **提交 Pull Request (PR)：** 发起合并请求，请维护者审查并将你的 Bug 修复或新功能合入主仓库。GitHub 会自动展示 **Symfony 风格的 PR 摘要表格与检查清单**（`.github/PULL_REQUEST_TEMPLATE.md`）。
4. **自动化 CI 检查（GitHub Actions）+ 代码审查：**
   - 一旦创建或更新 PR，GitHub Actions（`.github/workflows/ci.yml`）便会自动启动云端运行器。
   - 它会在 **PHP 8.3 & 8.4**、**Symfony 7.2、7.4 & 8.0** 以及 **Node 20 & 22** 环境中运行测试，并强制校验代码规范（**PHP-CS-Fixer**）、静态类型安全（**PHPStan Level 8**）、翻译完整性（**TranslationParityTest**）、YAML 文档 Schema 准确性（**FullConfigReferenceDocTest**）、Agent Skills 完整性（**SkillsPackageIntegrityTest**）以及 XSS/CSP/前端安全性（**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**）。
   - **只要有任意一项检查未通过，GitHub 就会锁定 Merge 按钮。**

---

## 2. 质量保障基础设施是否已经配置就绪？

**是的——已 100% 配置完毕，开箱即用。** 本 Bundle 严格参照 Symfony UX 官方标准，内置了一套完整的质量工具链：

| 文件 | 用途（通俗说明） |
| :--- | :--- |
| [`composer.json`](../../composer.json) | 定义一键式 `composer qa` 脚本套件以及独立的开发依赖（`require-dev`）。 |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | 无需宿主应用即可独立运行全部 PHP 单元测试与安全测试（**57 个测试文件、360 个测试用例、3,500+ 项断言**）。 |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | 配置 **PHPStan Level 8** 静态分析，在运行前捕获隐藏的类型不匹配、非法参数及未定义方法调用。 |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | 在所有 PHP 文件中强制执行 PSR-12 + Symfony 代码风格（`declare(strict_types=1)`、严格比较 `===`、`use` 导入按字母排序）。 |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | 在每次新建或更新 Pull Request 时自动运行 **6 个并行 CI 任务**。 |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | 为每个新建 PR 自动填充 Symfony 风格的摘要表格（`Bug fix?`、`BC breaks?`、`Tests pass?`）及自查清单。 |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | 为缺陷报告（`bug_report.yml`）与功能建议（`feature_request.yml`）提供结构化表单。 |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | 国际化的贡献者指南，GitHub 会在每次新建 PR 与 Issue 页面自动关联展示。 |
| [`SECURITY.md`](../../SECURITY.md) | 安全漏洞披露政策，说明如何私下报告安全漏洞，而不是公开提交 Issue。 |

### 自动强制执行的 7 条核心规则

即使人工代码审查漏掉了以下任意问题，**自动化测试套件也会立即捕获并让 PR 失败**：

1. **缺失翻译（`TranslationParityTest`）：** 如果在英文中新增了 UI 文案，但在 **12 种内置 UI 语言**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）中的任意一种中缺失，测试将**失败（FAIL）**。
2. **缺失 `strict_types`（`TranslationParityTest`）：** 只要 `src/` 目录下有任意一个 PHP 文件遗漏了 `declare(strict_types=1);`，测试即**失败（FAIL）**。
3. **违反 XSS / CSP / 前端安全规范（`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`）：** 在 Twig 中引入类似 `onclick="..."` 的内联事件处理器或 `|raw` 过滤器，或者破坏 JS 中的 `javascript:` URL / DOM 净化逻辑，都会导致测试**失败（FAIL）**。
4. **文档 YAML Schema 漂移（`FullConfigReferenceDocTest`）：** [配置与配置文件](02-configuration-and-profiles.md)参考文档（`docs/tr/02-yapilandirma-ve-profiller.md`）中的所有 `liveux_file_manager:` YAML 代码块都会对照真实的 `Configuration.php` Schema 进行校验。
5. **Agent Skills 完整性（`SkillsPackageIntegrityTest`）：** 校验 `AGENTS.md` 及全部 6 个 `skills/*/SKILL.md` 文件的 Frontmatter、行数/体积预算以及引用链接。
6. **代码风格与静态类型（`PHP-CS-Fixer` + `PHPStan Level 8`）：** 任何 PSR-12/Symfony 风格违规或 Level 8 类型错误都会阻断 CI。
7. **压缩产物 `assets/dist/` 同步（`npm --prefix assets run build:check`）：** 如果你修改了 `assets/controllers/*.js` 或 `assets/styles/core.css` 却忘记重新构建 `assets/dist/`，CI 将**失败（FAIL）**。

---

## 2.5. JS 与 CSS 压缩（`assets/dist/`）架构（`esbuild`）

> [!NOTE]
> **为什么需要这套架构？**
> - **Webpack Encore** 用户在运行 `npm run build` 时会自动获得 JS/CSS 压缩。
> - 但 **Symfony AssetMapper**（`asset-map:compile`）会原样分发文件而不做压缩。未压缩的源码中，`browser_controller.js` 体积为 `518 KB`，`core.css` 为 `132 KB`。
> - 为了在不要求宿主项目安装 Node.js 的前提下，让 AssetMapper 用户同样享有极速页面加载体验，我们采用了与官方 Symfony UX 包（`ux-live-component`、`ux-chartjs`、`ux-autocomplete`）完全一致的 **源码 → 分发产物（`assets/dist/`）** 模式。

### 工作原理

1. **易读的源文件：** 所有开发工作与安全测试均针对格式清晰、注释完备的源文件运行：
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` 及 `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` 与 `assets/images/liveux-logo.webp`
2. **压缩后的生产产物（`assets/dist/`）：** [`assets/build.mjs`](../../assets/build.mjs) 脚本使用 **`esbuild`** 将每个 JS 文件压缩为独立的 ESM 模块（保持 `@hotwired/stimulus` 为外部依赖并保留相对导入路径），并将 `core.css` 编译输出至 `assets/dist/`。
3. **自动解析（零破坏性变更）：**
   - 只要 `assets/dist` 目录存在，[`FileManagerBundle.php`](../../src/FileManagerBundle.php) 中的 `prependAssetMapper()` 就会自动将 `@liveux/file-manager-bundle` 命名空间映射到 `assets/dist/`。
   - [`assets/package.json`](../../assets/package.json) 中的 `"exports"` 与 `"symfony.controllers.*.main"` 配置项也确保 Webpack Encore 直接消费 `dist/` 下的产物文件。
   - 由于 `assets/dist/` 已提交到 Git 仓库，**最终用户完全不需要安装 Node.js 或 npm**。`.gitattributes` 仅在 Packagist 分发包中排除构建工具文件（`assets/node_modules`、`assets/build.mjs`、`assets/package-lock.json`），同时完整保留 `assets/package.json` 和 `assets/dist/`。

### 实测体积缩减

| 文件 | 原始源码 | 压缩后（`assets/dist/`） | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 个编辑器适配器 | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **总计（压缩减少 `-45.1%`）** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### 开发者命令（每次修改 JS 或 CSS 后执行）

```bash
# 1. 首次安装 esbuild（仅需执行一次）：
npm --prefix assets install

# 2. 修改任意 JS 或 CSS 文件后重新构建 assets/dist/（耗时约 50 ms）：
npm --prefix assets run build
# 或使用 Composer 快捷命令：
composer assets:build

# 3. 验证 assets/dist/ 与源码 100% 同步（与 CI 执行的检查完全一致）：
composer assets:check
```

---

## 3. 提交 Pull Request 分步指南

> [!TIP]
> **即使你从未提交过 PR**，按顺序完成以下 6 个步骤，也能轻松提交一个整洁且一次通过 CI 的 Pull Request。

### 第 1 步：Fork、克隆仓库并安装依赖

1. 在 GitHub 上打开 [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle)，点击右上角的 **Fork**，在你的 GitHub 账号下创建一份副本。
2. 将你的 Fork 克隆到本地，添加主仓库作为 `upstream`，并安装 PHP 与 Node 开发工具：

```bash
git clone https://github.com/<YOUR-USERNAME>/file-manager-bundle.git
cd file-manager-bundle

# 将主仓库添加为 upstream（便于随时同步最新代码的好习惯）
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# 安装 PHP 测试与静态分析依赖
composer install

# 如果计划修改 JS/CSS 文件，安装前端压缩工具（esbuild）
npm --prefix assets install
```

### 第 2 步：基于最新的 `main` 创建新分支

切勿直接向 `main` 分支提交代码。先同步 `main`，再为你的工作创建一个语义清晰的分支：
- Bug 修复：`fix/folder-counter-cache`
- 新功能：`feat/new-icon-set`
- 文档改进：`docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### 第 3 步：编写代码、补充测试，并在需要时重新构建 `dist/`

- **修复 Bug：** 先编写一个能稳定复现该 Bug 的回归测试（修复前报错飘红，修复后通过变绿）。
- **新增功能：**
  - 在 `tests/Unit/...` 下补充 PHP 单元测试。
  - 若涉及 UI/JS 变更，请在 `tests/Js/dom_security_test.mjs` 或 `tests/Js/frontend_audit_test.mjs` 中增加测试覆盖。
  - **只要修改了 JS 或 CSS，请务必运行 `composer assets:build`**（否则 CI 会在 `build:check` 阶段失败）。
  - 每当新增 UI 翻译键时，请同步添加到**全部 12 种内置 UI 语言文件**中：`translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`。

### 第 4 步：在本地一键运行全部质量门禁

在打开 PR 之前，先在本地运行与 CI 完全一致的检查：

```bash
composer qa
composer assets:check
```

上述命令会按顺序验证：
1. `composer validate --strict` — 校验 `composer.json` 格式与约束。
2. `composer cs:check` — 检查 PSR-12/Symfony 代码规范*（若报告格式问题，运行 `composer cs:fix` 即可自动修复）*。
3. `composer stan` — 运行 **PHPStan Level 8** 静态分析。
4. `composer test` — 运行完整的 PHPUnit 单元测试、安全测试、翻译一致性测试与 Schema 测试套件。
5. `composer test:js:syntax` — 对所有源文件及 `dist/` 下的 JS 文件执行 `node --check` 语法检查。
6. `composer test:js` — 运行 Node.js DOM 安全与前端审计测试套件（`dom_security_test.mjs` + `frontend_audit_test.mjs`）。
7. `composer assets:check` — 确认 `assets/dist/` 与当前源码逐字节一致。

> [!TIP]
> **全部变绿通过了吗？** 现在你已经可以提交代码并创建 Pull Request 了。

### 第 5 步：创建整洁的提交并推送到你的 Fork

```bash
git status            # 确认仅修改了预期内的文件
git diff --stat       # 快速预览变更摘要
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*（建议使用清晰的 Conventional Commits 前缀：`feat:`、`fix:`、`docs:`、`test:`、`refactor:`、`perf:`。）*

### 第 6 步：在 GitHub 上打开 Pull Request 并等待 CI 全部变绿

1. 访问你在 GitHub 上的 Fork（`https://github.com/<YOUR-USERNAME>/file-manager-bundle`），点击顶部的 **"Compare & pull request"** 横幅按钮。
2. 确认合并目标：**Base repository:** `LiveUX/file-manager-bundle`（`base: main`）← **Head repository:** `<YOUR-USERNAME>/file-manager-bundle`（`compare: fix/folder-counter-cache`）。
3. 填写自动生成的 **PR 模板**表格（`Bug fix? yes`、`BC breaks? no`、`Tests pass? yes`）并勾选清单项（`[x]`）。如果变更涉及 UI，请附上截图或 GIF 动图。
4. 点击 **"Create pull request"**。
5. 等待 PR 页面底部的全部 GitHub Actions 检查（`PHP 8.3/8.4`、`Node 20/22`、`PHPStan & CS-Fixer`）显示**绿色对勾（✓）**。
6. 如果审查者提出了修改意见，直接在同一个分支上修改文件并运行 `git commit && git push` —— GitHub 会自动更新 PR 并重新触发 CI。

---

## 4. 面向维护者：在 GitHub 上启用分支保护与 Rulesets

Workflow 文件入库后，CI 会自动运行——但为了确保**任何人（包括维护者误操作）都无法在检查未通过时点击 Merge**，只需 2 分钟即可在 GitHub 配置一套 **Ruleset**：

1. 在 GitHub 上打开 Bundle 仓库，进入 **Settings**。
2. 在左侧边栏选择 **Rules → Rulesets**（经典界面下为 **Branches → Add branch protection rule**）。
3. 点击 **New branch ruleset**：
   - **Ruleset Name：** `Protect main (Symfony Quality Gate)`
   - **Enforcement status：** `Active`
   - **Target branches：** `Add target` → `Include default branch`（`main`）
4. 开启以下 4 项保护规则：
   - [x] **Restrict deletions**（防止误删 `main` 分支）
   - [x] **Block force pushes**（保护提交历史不被强推覆盖）
   - [x] **Require a pull request before merging**（禁止直接向 `main` 推送，所有变更必须通过 PR）
     - *Required approvals：* `1`（团队协作推荐设为 1；个人维护者可保持为 `0`）
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *（最关键的设置）*
     - [x] *Require branches to be up to date before merging*
     - 在首次 CI 运行完成后，点击 **Add checks**，将全部 **6 个任务**设为必选：
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. 点击页面底部的 **Create** 保存。

---

## 5. 将本 Bundle 发布为独立开源仓库的检查清单

若要将本 Bundle 以 `LiveUX/file-manager-bundle` 的名义发布至 GitHub 与 Packagist：

1. 在 `LiveUX` GitHub 组织下新建一个名为 `file-manager-bundle` 的空 **Public** 仓库。
2. 将 `bundles/liveux-file-manager-bundle/` 中的内容作为初始提交推送上去（`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`）。
3. 在 **GitHub Actions** 标签页确认全部 6 个 CI 任务均显示绿色对勾通过。
4. 按上文 **第 4 节** 的步骤启用 **Ruleset** 分支保护配置。
5. 打上 `v0.1.0` 标签（`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`），并在 [Packagist.org](https://packagist.org) 提交 `https://github.com/LiveUX/file-manager-bundle`。
6. 至此，任何开发者都可以通过一条命令安装本 Bundle：`composer require liveux/file-manager-bundle`。

---

[← 上一篇：10 — 资源使用保护](10-asset-usage-guard.md) | [文档索引](README.md) | [下一篇：12 — AI Agent Skills →](12-ai-agent-skills-guide.md)
