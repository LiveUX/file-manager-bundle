<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Symfony UX 文件管理器与数字资产管理器" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — 简体中文文档

**语言：** [English](../en/README.md) · [Türkçe](../tr/README.md) · [Français](../fr/README.md) · [Deutsch](../de/README.md) · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · [日本語](../ja/README.md) · **简体中文** · [主 README](../../README.md)

**LiveUX FileManagerBundle** 是一款面向 Symfony 6.4 LTS、7.2+ 与 8.x（PHP 8.3+）打造的现代化、高性能、企业级 **Symfony UX 文件管理器与数字资产管理器（DAM）**。它具备**零 CSS 框架依赖**、14 层内置安全防护以及完善的多语言国际化能力（内置 12 种界面语言、支持 BCP-47 / POSIX 区域设置识别及原生 RTL 布局）。

---

## 实时预览与界面截图

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.shields.io/badge/YouTube-Watch_Full_14--Chapter_1080p_Showcase_(03:52)-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="Watch Full 14-Chapter 1080p Showcase on YouTube">
  </a>
</p>

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.youtube.com/vi/H2CAf0RiDd8/maxresdefault.jpg" alt="LiveUX FileManagerBundle — Full 14-Chapter 1080p YouTube Showcase" width="100%">
  </a>
</p>

<p align="center">
  <img src="../images/demo-showcase.gif" alt="LiveUX FileManagerBundle 交互式演示" width="100%">
</p>

> **全高清（1080p，约 3 分钟）10 章节功能演示视频**（适用于 YouTube 及版本发布）：[YouTube (1080p)](https://www.youtube.com/watch?v=H2CAf0RiDd8) · [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · 精简预览版：[`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| 浅色模式（`Light`） | 深色模式（`Dark`） |
| :---: | :---: |
| ![浅色模式文件管理器](../images/browser-light.png) | ![深色模式文件管理器](../images/browser-dark.png) |

| 文件夹颜色与图标自定义 | 关于对话框（`LiveUX`） |
| :---: | :---: |
| ![文件夹颜色与图标徽章自定义](../images/folder-customize.png) | ![关于弹窗](../images/about-modal.png) |

### 富文本编辑器、EasyAdmin 与动态表单集成演示（GIF）

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![TinyMCE 集成演示](../images/demo-editor-tinymce.gif) | ![CKEditor 5 集成演示](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2（ProseMirror） | Quill 2 |
| :---: | :---: |
| ![Tiptap 2 集成演示](../images/demo-editor-tiptap.gif) | ![Quill 2 集成演示](../images/demo-editor-quill.gif) |

| Trix（EasyAdmin `TextEditorField`） | Summernote |
| :---: | :---: |
| ![Trix 集成演示](../images/demo-editor-trix.gif) | ![Summernote 集成演示](../images/demo-editor-summernote.gif) |

| Editor.js（块级编辑器） | GrapesJS（可视化页面构建器） |
| :---: | :---: |
| ![Editor.js 集成演示](../images/demo-editor-editorjs.gif) | ![GrapesJS 集成演示](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5（`FileManagerField`） |
| :---: | :---: |
| ![Meta Lexical 集成演示](../images/demo-editor-lexical.gif) | ![EasyAdmin 5 集成演示](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` 与 `LiveCollectionType` |
| :---: |
| ![CollectionType 与 LiveCollectionType 集成演示](../images/demo-collections.gif) |

---

## 2 分钟快速上手路线图

不确定从哪里开始？直接按你的目标跳转到对应章节：

- **首次安装接入：** [01 — 环境要求与安装](01-requirements-and-installation.md) → [02 — 配置与 Profile 方案](02-configuration-and-profiles.md)
- **在 Symfony 表单中添加单图或图库选择器：** [05 — FormType 与编辑器集成](05-formtype-and-editor-integrations.md)
- **配置生产环境安全策略与 RBAC 权限控制：** [06 — 安全与权限控制（RBAC）](06-security-and-authorization.md)
- **接入 Supabase、AWS S3 或 Cloudflare R2 云存储：** [07 — 云存储与动态配置](07-cloud-storage-and-dynamic-config.md)
- **使用 Doctrine ORM / DBAL 存储元数据：** [13 — Doctrine ORM 与 DBAL 集成](13-doctrine-integration.md)
- **防止误删或误移动数据库中仍在引用的资源：** [10 — 资源引用保护（Asset Usage Guard）](10-asset-usage-guard.md)

---

## 文档目录索引

| 章节 | 指南 | 内容概览 |
| :---: | :--- | :--- |
| **01** | [环境要求、依赖与安装](01-requirements-and-installation.md) | PHP 8.3+ / Symfony 6.4 LTS、7.2+ 与 8.x 环境要求、PHP 扩展（含 `ext-exif`、`ext-zip`）、Composer 依赖、AssetMapper 与 Webpack Encore 配置、`file-manager:doctor` 诊断命令以及你的第一个可运行页面 |
| **02** | [配置与 Profile 方案（YAML 完整参考）](02-configuration-and-profiles.md) | `defaults`、`volumes`、`profiles`、防冲突动态路径（`{user_id}`、`{Y}/{m}`）、上传与分片限制、自动命名、图片优化与约束、缩略图、回收站、ZIP、5 套生产级开箱即用配方以及完整 YAML Schema 参考 |
| **03** | [使用模式与 JavaScript API](03-usage-modes-and-js-api.md) | 内联 `TwigComponent`、`<dialog>` 模态框与四向 OffCanvas 抽屉、完整的 `window.LiveUXFileManager.open()` 参考、自定义 DOM 事件、RTL/IME/WCAG 2.1 AA 无障碍支持、右键上下文菜单、内置图片编辑器与键盘快捷键 |
| **04** | [主题、图标集与自定义样式](04-themes-icons-and-customization.md) | 9 套符合 WCAG 2.1 AA 标准的内置主题（Bootstrap 5、Tailwind、Shadcn、Flowbite 4、DaisyUI 5、Tabler、Preline、Material、Unstyled）、自动/手动深色模式、6 套 SVG 图标集（49 个 UI 图标 + 14 个徽章图标）、Twig 辅助函数以及 3 个层级的样式自定义方案 |
| **05** | [FormType 与编辑器集成](05-formtype-and-editor-integrations.md) | `FileManagerPickerType`（单图与支持无障碍排序的多图图库 + POST 安全校验）、5 种内置外观变体（`compact`、`card`、`dropzone`、`avatar`、`input`）、`CollectionType` / `LiveCollectionType`、自定义模板、TinyMCE、CKEditor 5、Tiptap 以及包含 16 个方法的 EasyAdmin `FileManagerField` |
| **06** | [安全与权限控制（RBAC）](06-security-and-authorization.md) | 17 项细粒度 Voter 权限、自定义 `unanimous` Voter、`security.yaml` 防火墙规则、14 层纵深防御（CSRF、路径遍历、69 种扩展名 / 47 种 MIME 类型、55 标签白名单 `SvgSanitizer`、SSRF 防 DNS 重绑定、`ConfinedLocalFilesystemAdapter` 符号链接隔离、HMAC 签名分享链接、审计日志）以及 Apache/Nginx 安全加固 |
| **07** | [云存储（S3/R2/MinIO/Supabase）与动态配置](07-cloud-storage-and-dynamic-config.md) | 本地与云端多存储卷架构、各云服务商接入指南（[Supabase](cloud/supabase-storage.md)、[AWS S3](cloud/aws-s3.md)、[Cloudflare R2](cloud/cloudflare-r2.md)、[Backblaze B2](cloud/backblaze-b2.md)、[MinIO/RustFS](cloud/minio-rustfs.md)、[Google Cloud Storage](cloud/google-cloud-storage.md)、[SFTP 与自定义 Flysystem](cloud/sftp-and-custom-flysystem.md)）、`FlysystemServiceLocatorPass`、`ConfigProviderInterface` 与自定义元数据存储 |
| **08** | [事件、CLI 命令、缓存与故障排查](08-events-commands-cache-and-troubleshooting.md) | 9 个 Symfony 领域事件、10 个控制台命令（`doctor`、`stats`、`duplicates`、`ai:enrich`、`skills:install` 等）、3 层缓存与上下文感知 HTTP 304/CDN 隔离、12 种语言全局 BCP-47/POSIX/RTL 翻译系统（493 个翻译键）及常见问题解答 |
| **09** | [Symfony AI 集成与智能元数据增强](09-symfony-ai-integration.md) | 零必选依赖的 `AssetEnricherInterface`、`symfony/ai-bundle` 桥接器（支持 OpenAI、Gemini、Claude、Ollama）、**✨ AI 智能建议**、批量处理命令 `file-manager:ai:enrich`、Messenger 异步队列示例以及 11 种语言的 LLM 拒答与提示词注入过滤 |
| **10** | [资源引用保护（Asset Usage Guard）](10-asset-usage-guard.md) | 可选启用的 `AssetUsageCheckerInterface` 契约、`warn` / `block` 策略、覆盖 **`delete`、`rename`、`move` 与 `overwrite`** 操作的双层服务端防护（`enforce_server_side`），以及 MySQL、PostgreSQL 与 SQLite 的 Doctrine 查询示例 |
| **11** | [贡献与 Pull Request 指南](11-contributing-and-pr-guide.md) | 新手友好的开源 PR 协作流程、`composer qa` 质量门禁（PHPStan Level 8、360+ PHPUnit 测试、37+ JS 测试）、6 任务 GitHub Actions CI 以及 GitHub 分支保护配置 |
| **12** | [AI 编程助手技能指南（`SKILL.md` 与 `AGENTS.md`）](12-ai-agent-skills-guide.md) | 面向 Antigravity、Claude Code、Cursor、GitHub Copilot、Codex 与 Symfony AI Mate 的 6 套模块化 `SKILL.md` 技能包、一键安装命令 `file-manager:skills:install` 以及 CI 校验 |
| **13** | [Doctrine ORM 与 DBAL 集成](13-doctrine-integration.md) | 内置 `metadata.driver: 'doctrine'`（`DoctrineDbalMetadataStorage` — 原子化 UPSERT、PostgreSQL `text_pattern_ops` 索引、批量 `saveMany()`）、跨数据库迁移、Entity + `FileManagerPickerType`、支持 `ResetInterface` 的自定义 ORM 存储以及基于 `ItemRenamedEvent` 的数据库同步 |
| **14** | [安全审计、逻辑评审与变更报告](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(仅土耳其语)* | 逐行安全审计（第 1–18 轮）、防冲突多租户 `{user_id}` 隔离、DI/Voter/解析器/SVG `<use>` 步数预算与 CDN/源站伪造加固、符号链接隔离、PostgreSQL `text_pattern_ops` 与原子 UPSERT、WCAG 2.1 AA 9 主题对比度/焦点审计、移动端弹窗与国际化审计日志 |

---

## 核心架构亮点

- **0ms 混合极速体验：** 高效 SSR `TwigComponent` + 浏览器内存缓存（`dirCache`）+ 单次请求拉取完整文件夹树。筛选、搜索、排序、分页、网格/列表切换以及展开/折叠目录均可瞬时响应，无需额外的 AJAX 往返请求。
- **零 CSS 框架依赖且符合 WCAG 2.1 AA 标准：** 整套 UI 均采用带作用域的 `.liveux-fm-*` 类名与 CSS 自定义属性（`--liveux-fm-*`）。无论是与 **Bootstrap 5、Tailwind CSS、Shadcn/UI、Flowbite 4、DaisyUI 5、Tabler、Preline、Material** 搭配使用，还是**完全不使用任何 CSS 框架**，都不会产生样式冲突，也无需配置额外的 Tailwind purge 规则。
- **原生 HTML5 `<dialog>` Top Layer 与移动端优先体验：** 模态框（Modal）和四向 OffCanvas 抽屉（`start`、`end`、`top`、`bottom`）直接渲染在浏览器顶层（Top Layer），在移动端会自动适配为全屏视图（`100dvh`）或带拖动手柄的原生底部抽屉（Bottom Sheet）。
- **14 层企业级纵深安全：** 开箱即用的 Symfony `Voter` RBAC 权限校验、CSRF 防护（`X-LiveUX-FM-CSRF`）、`PathNormalizer`、`FileSecurityGuard`、55 标签白名单 `SvgSanitizer`、防 DNS 重绑定的 `RemoteUrlImporter`、`ConfinedLocalFilesystemAdapter` 符号链接隔离、解压炸弹/像素炸弹防护以及 `CSP: sandbox` + `nosniff` 响应头。
- **全球化多语言架构：** 内置 **12 种界面语言**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`，每种语言完整同步 493 个翻译键），支持 BCP-47 与 POSIX `.UTF-8` 区域设置规范化、兜底回退至 `'en'`、为阿拉伯语等 15 种从右到左书写语言自动启用 RTL（`dir="rtl"`），并内置中日韩（CJK）输入法（IME）组合输入安全防护。
- **面向多实例与云原生部署：** 内置 `DoctrineDbalMetadataStorage`（支持 PostgreSQL `text_pattern_ops` 以及 MySQL/MariaDB/SQLite 原子化 UPSERT）、Flysystem v3（S3、R2、Supabase、B2、MinIO、GCS、SFTP），并实现 `ResetInterface`，原生兼容 FrankenPHP 与 Symfony Messenger 常驻 Worker。

---

## 5 分钟快速接入清单

1. **安装 Bundle：** `composer require liveux/file-manager-bundle`
2. **引入路由：** `config/routes/liveux_file_manager.yaml`
3. **配置 Profile：** `config/packages/liveux_file_manager.yaml`
4. **接入 CSS 与 JS（零配置）：** Symfony UX 的 `autoimport` 会通过 `assets/controllers.json` 自动加载 `core.css`、`file_manager_api.js` 以及全部 3 个 Stimulus 控制器，同时支持 AssetMapper 与 Webpack Encore。
5. **弹窗宿主容器（自动注入）：** `AutoInjectDialogHostSubscriber` 会在完整 HTML 页面响应的 `</body>` 前自动注入 `DialogHost`（`defaults.auto_inject_dialog_host: true`），无需手动修改 `base.html.twig`。
6. **运行环境自检：** `php bin/console file-manager:doctor`

> 第一次接触 Symfony UX？建议从 **[01 — 环境要求、依赖与安装](01-requirements-and-installation.md)** 开始按步骤实践。

---

**组织：** [LiveUX on GitHub](https://github.com/LiveUX) · [代码仓库](https://github.com/LiveUX/file-manager-bundle)  
**许可证：** MIT
