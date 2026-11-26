# 04 — 主题、深色模式、文件夹定制、图标集与自定义设计

[← 上一篇：03 — 使用模式与 JS API](03-usage-modes-and-js-api.md) | [目录](README.md) | [下一篇：05 — FormType 与编辑器集成 →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle 从设计之初就坚持 **100% 零 CSS 框架依赖**。无论你的项目使用 **Bootstrap 5、Tailwind CSS v3/v4、Shadcn/UI、Flowbite 4、DaisyUI 5、Tabler、Preline、Material**，还是**完全不使用任何 CSS 框架**，都可以开箱即用，无需任何额外的构建步骤（也无需配置 Tailwind `@source` 或 purge 规则）。

> **本指南内容概览**  
> 1. **9 套内置主题与深色模式：** 只需一行 YAML 配置，即可完美契合应用的视觉风格与配色方案。  
> 2. **文件夹配色与文件夹图标（徽章 / Badge）：** 支持在界面中直接设置，或通过 PHP / 数据库以编程方式定制，内置 18+ 种配色与 14 个徽章图标（还支持无限扩展自定义 SVG 徽章）。  
> 3. **打造专属自定义图标集：** 手把手教你构建覆盖 9 个文件图标、49 个 UI 图标以及无限个自定义文件夹徽章的专属图标包。  
> 4. **构建自定义主题（3 种自由度）：** 纯 CSS 自定义属性（变量）品牌换肤、基于 `{% extends %}` 的局部 Twig 覆写，或从零开始的 100% 无头（Headless）布局。

---

## 1. 9 套内置主题（如何选择？）

你可以通过 `defaults.theme`、`profiles.<name>.ui.theme`、TwigComponent 的 `theme` 属性或 `FileManagerPickerType` 的 `theme` 选项来指定主题。**全部 9 套主题在浅色与深色模式下均已通过 WCAG 2.1 AA 对比度标准验证。**

| 主题标识（Key） | 最适用场景 | 设计特点简介 |
| :--- | :--- | :--- |
| `bootstrap5` *（默认）* | Bootstrap 5 与经典 Symfony 后台管理面板 | 经典蓝（`#0d6efd`）、`10px / 8px / 6px` 圆角、清爽的中性边框 |
| `tailwind` | 基于 Tailwind CSS v3/v4 构建的现代 Web 应用 | 靛蓝（`#6366f1`）强调色、柔和的 `14px / 12px / 8px` 圆角、Slate 灰调色板 |
| `shadcn` | 极简黑白单色风格的 SaaS 控制台 | 单色 Zinc 表面（深色模式下为 `#18181b` / `#fafafa`）、利落的 `8px / 6px` 边缘 |
| `flowbite` | 使用 Flowbite 4 + Tailwind CSS 的后台面板 | Flowbite 蓝（`#1a56db`）与 Gray-50/800/900 表面、`8px` 控件圆角 |
| `daisyui` | 基于 DaisyUI 5 构建的项目 | 当页面加载了 DaisyUI 时，**自动继承** `--color-primary`、`--color-base-100/200/300` 与 `--radius-box` |
| `tabler` | Tabler.io 或企业级 EasyAdmin 后台 | 企业级 Slate 画布、`#066fd1` 蓝色强调、干练的 `4px / 6px` 圆角 |
| `preline` | Preline UI 应用 | 纯净的中性表面（`#fafafa` / `#171717`）搭配明快的蓝色（`#2563eb`）强调 |
| `material` | Google Material Design 3 应用 | 紫色色调表面（`#6750a4` / `#f3edf7`）与全圆角胶囊按钮 |
| `unstyled` | 完全使用自有 CSS 定制文件管理器样式 | 继承宿主应用的字体与透明背景，同时保留不透明的 `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` 以确保弹层与灯箱的对比度 |

| 深色模式（`bootstrap5` + Dark） | DaisyUI 5 主题桥接（`daisyui`） |
| :---: | :---: |
| ![深色模式文件管理器](../images/browser-dark.png) | ![DaisyUI 5 主题展示](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **安全规则（YAML 配置 vs. 运行时动态 `theme`）：**  
> - 在 `liveux_file_manager.yaml` 中，你可以配置任意 9 套内置主题、自定义 CSS 主题名称（例如 `'corporate'`，需匹配 `/^[a-zA-Z0-9_-]{1,40}$/`），或自定义 Twig 模板路径（例如 `'file_manager/custom.html.twig'` 或 `'@App/file_manager'`）。  
> - 出于安全考虑，来自浏览器、URL 查询参数或 `window.LiveUXFileManager.open({ theme: ... })` 的动态 `theme` 值**仅接受 9 个内置主题标识**（由 `FileManagerThemes::sanitizeDynamic()` 过滤）。

---

## 2. 深色模式、RTL 与减弱动态效果

全部 9 套主题（包括 `unstyled`）均同时支持**自动与手动深色模式**：

### A）自动跟随宿主页面（`color_scheme: 'auto'`）
只要你的页面（`<html>` 或 `<body>`）启用了以下任意一种标记，文件管理器、模态 `<dialog>` 与 `FileManagerPickerType` 表单字段都会立即切换为深色模式：
- `data-bs-theme="dark"` *（Bootstrap 5）*
- `.dark` 类名 *（Tailwind CSS / Shadcn / Flowbite / Preline）*
- `data-theme="dark"` *（DaisyUI / 自定义主题）*

### B）通过 YAML 设置全局默认或按配置档（Profile）指定配色模式
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto'（跟随宿主） | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # 仅让该配置档默认以深色模式启动
```

### C）终端用户偏好（设置菜单：`自动 / 浅色 / 深色`）
用户可以在顶部工具栏的 **⚙ 设置** 弹层中一键切换 **自动 / ☀ 浅色 / 🌙 深色**，其偏好会自动保存在浏览器的 `localStorage`（`liveux_fm_color_scheme`）中。

### D）全局 RTL 与 `prefers-reduced-motion`
- 针对阿拉伯语（`ar`）、波斯语（`fa`）、希伯来语（`he`）、乌尔都语（`ur`）等 15 种从右到左书写的语言，系统会自动应用 `dir="rtl"`。`core.css` 中的所有间距均采用 CSS 逻辑属性（`inset-inline`、`padding-inline`、`border-inline-end`）。
- 当用户在操作系统中开启了“减弱动态效果”（`prefers-reduced-motion: reduce`）时，界面动画会自动精简。

---

## 3. 文件夹配色与文件夹图标（徽章）设置（分步指南）

![文件夹调色板与徽章定制弹窗](../images/folder-customize.png)

你可以为任意文件夹指定自定义的**颜色**、**徽章图标（Badge）**以及**置顶状态（⭐ 置顶）**。当 `defaults.metadata.enabled: true` 且用户拥有 `permissions.edit_metadata` 权限时，这些功能即会自动生效。

### 方式 1：通过界面设置（面向终端用户）
1. **右键点击**任意文件夹并选择**自定义**（或在选中文件夹时按下快捷键 **`I`**，或点击右侧属性检查器面板中的**自定义**按钮）。
2. 在**自定义**弹窗中可实时预览并配置以下三项：
   - **18 色预设调色板：** Blue（`#2563eb`）、Sky（`#0284c7`）、Cyan（`#0891b2`）、Teal（`#0d9488`）、Emerald（`#059669`）、Green（`#16a34a`）、Lime（`#65a30d`）、Amber（`#d97706`）、Orange（`#ea580c`）、Red（`#dc2626`）、Rose（`#e11d48`）、Pink（`#db2777`）、Fuchsia（`#c026d3`）、Purple（`#9333ea`）、Violet（`#7c3aed`）、Indigo（`#4f46e5`）、Slate（`#475569`）、Charcoal（`#1e293b`）。
   - **自定义取色器 + HEX 输入框：** 通过浏览器原生取色器选择任意品牌色，或直接输入 `#RRGGBB` 十六进制色值。点击**恢复默认**即可清除自定义样式。
   - **文件夹图标（徽章）网格：** 展示全部 **14 个内置徽章**（`archive`、`bag`、`cart`、`code`、`doc`、`globe`、`heart`、`image`、`lock`、`pen`、`star`、`tag`、`users`、`video`），以及你在图标集 `badges/` 目录下添加的所有自定义 SVG 徽章。
3. 点击**保存** —— 网格/列表视图与左侧文件夹树会立即同步更新。

### 方式 2：通过 PHP 编程方式设置（例如在创建新客户文件夹时）
借助 `FolderMetadataStorageInterface` 与 `PathNormalizer::buildCanonicalKey()`，你可以直接从 PHP 代码为文件夹设置颜色、徽章和置顶状态（同一套代码在 `json_file` 与 `doctrine` 两种驱动下均可无缝运行）：

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

        // 生成规范化的路径键名（例如："local::invoices/2026"）
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // 青绿色（#RRGGBB）
            icon: 'lock',       // 14 个内置徽章之一，或自定义 badges/invoice.svg -> 'invoice'
            pinned: true,       // 添加到左侧边栏的 ⭐ 已置顶文件夹
        ));
    }
}
```

### 方式 3：直接编辑 `metadata.json`（使用 `driver: 'json_file'` 时）
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. 图标集与从零创建自定义图标集

Bundle 中的**每一个图标** —— 包括文件类型、文件夹、顶部工具栏、右键菜单、云盘标识、属性检查器面板以及文件夹徽章 —— 均由统一的 `IconSetRegistry` 提供。6 套内置图标集会被 PSR-6 缓存 24 小时（缓存键前缀 `liveux_fm_iconset_*`）。

### 6 套内置图标集（`profiles.<name>.ui.icon_set`）
| 图标集 | 视觉风格 | 默认文件夹颜色（`meta.json`） |
| :--- | :--- | :--- |
| `classic` *（默认）* | 扁平、清晰的矢量图标 | `#2563eb` |
| `windows11` | Windows 11 风格的双色调暖黄文件夹与现代文档图标 | `#f59e0b` |
| `macos` | 灵感源自 macOS Finder 的柔和蓝色文件夹 | `#3b82f6` |
| `linux` | 清爽的开源 GNOME / Adwaita 视觉美学 | `#3584e4` |
| `minimal` | 细线条（描边）极简风格图标 | `#475569` |
| `vscode` | 锐利、面向开发者的 VS Code 风格图标 | `#dcb67a` |

### 分步教程：创建你的自定义图标集

#### 第 1 步：在项目中创建图标目录
例如，创建 `assets/fm-icons/` 目录。**无需复制你不想修改的图标** —— 任何未提供的图标都会自动回退到内置的 `classic` / `_ui` / `_folder_badges` 图标集！

```text
assets/fm-icons/
├── meta.json               # 默认文件夹颜色：{ "defaultFolderColor": "#7c3aed" }
├── folder.svg              # （可选）自定义主文件夹图标
├── pdf.svg                 # （可选）仅替换 PDF 图标
├── ui/                     # （可选）替换或新增工具栏、右键菜单与存储卷图标
│   ├── upload.svg          # 替换上传图标
│   ├── new_folder.svg      # 替换新建文件夹图标
│   └── company_cloud.svg   # 新增自定义存储卷图标 -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # （可选）替换现有文件夹徽章或新增自定义徽章
    ├── lock.svg            # 替换内置的 "lock" 徽章
    └── invoice.svg         # 新增徽章 -> 自动出现在“自定义文件夹”弹窗中！
```

#### 第 2 步：创建 `meta.json` 与支持动态配色的 `folder.svg`

> [!TIP]
> **黄金法则（`currentColor`）：** 为了确保用户在更改文件夹颜色时，你的自定义 `folder.svg` 能够自动随之变色，请使用 **`fill="currentColor"`**（描边图标请使用 `stroke="currentColor"`），而不要写死十六进制颜色值！

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
<!-- assets/fm-icons/badges/invoice.svg（新增的自定义文件夹徽章） -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### 第 3 步：在 YAML 配置档中指向该目录
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: '企业归档库'
            icon: 'company_cloud' # 使用 assets/fm-icons/ui/company_cloud.svg！
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### 自定义图标的安全机制与限制（`IconSetRegistry`）
- 单个 `.svg` 文件体积上限为 **256 KB**（`262,144` 字节），并在加载时自动经过 **`SvgSanitizer`** 清洗（移除 `<script>`、`on*` 事件属性等危险内容）。
- 除内置键名外，`ui/` 与 `badges/` 目录各自最多可扫描 **200 个自定义 `.svg`** 文件。
- 自定义文件名（不含 `.svg` 扩展名）必须匹配 **`/^[a-zA-Z0-9_-]{1,32}$/`**（仅允许 ASCII 字母、数字、`-` 和 `_`，最长 32 个字符）。

#### 可覆写图标键名完整参考
- **9 个文件与文件夹图标（根目录 `<dir>/<key>.svg`）：**  
  `folder`、`file`、`pdf`、`excel`、`word`、`zip`、`video`、`audio`、`code`
- **49 个 UI 图标（`<dir>/ui/<key>.svg`）：**
  - *导航与布局：* `menu`、`back`、`forward`、`up`、`refresh`、`search`、`search_deep`、`grid`、`list`、`sort`、`fullscreen`、`exit_fullscreen`、`settings`、`close`、`more`、`sun`、`moon`
  - *文件与文件夹操作：* `new_folder`、`upload`、`cloud_upload`、`download`、`open`、`preview`、`edit_image`、`rename`、`move`、`duplicate`、`cut`、`copy`、`paste`、`trash`、`archive`、`select_all`、`link`、`share`、`color`、`tag`、`star`、`sparkles`、`quota`
  - *存储与云服务商：* `disk`、`cloud`、`aws_s3`、`cloudflare_r2`、`supabase`、`backblaze_b2`、`minio`、`gcs`、`sftp`
- **14 个内置文件夹徽章（`<dir>/badges/<key>.svg`）：**  
  `archive`、`bag`、`cart`、`code`、`doc`、`globe`、`heart`、`image`、`lock`、`pen`、`star`、`tag`、`users`、`video` *（外加你自行添加的任意自定义 `*.svg` 文件！）*

---

## 5. 构建自定义主题（3 种自由度）

`Browser.html.twig` 会严格按照以下**优先级顺序**解析配置档中的 `theme` 值：
1. 以 `.html.twig` 结尾 → 直接渲染你指定的 Twig 模板（`{% include theme %}`）。
2. 以 `@` 开头 → 渲染该 Twig 命名空间下的 `layout.html.twig`（`{% include theme ~ '/layout.html.twig' %}`）。
3. 匹配 9 套内置主题之一 → 渲染 Bundle 内置主题。
4. 任意其他简短标识符（例如 `'corporate'`，匹配 `/^[a-zA-Z0-9_-]{1,40}$/`）→ 使用默认基础布局，并包裹 `<div data-liveux-theme="corporate">`。

### 级别 1：纯 CSS 变量品牌换肤（最快 —— 零模板改动！）

#### CSS 自定义属性完整参考（`--liveux-fm-*`）
| 分类 | CSS 变量 |
| :--- | :--- |
| **字体排版** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **表面与边框** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **文本颜色** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **品牌强调色** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **语义状态色（WCAG AA）** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **圆角与阴影** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### 开箱即用的自定义品牌主题示例（`assets/styles/app.css`）
```css
/* 1) 浅色模式品牌配色 */
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

/* 2) 深色模式对应配色（同时覆盖文件管理器与 FormType 选择器，兼容自动与手动深色模式） */
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

### 级别 2：通过 Twig `{% extends %}` 进行局部覆写（推荐！）

基础布局围绕 **10 个 `{% block %}` 插槽**与 **7 个模块化局部模板（`parts/`）** 构建。无需复制整个模板，即可轻松定制顶部栏或底部栏等局部区域：

| 区块名称（Block） | 作用范围 | 默认局部模板 |
| :--- | :--- | :--- |
| `fm_shell` | 最外层壳体容器（`.liveux-fm-shell`） | 包裹所有嵌套区块 |
| `fm_topbar` | 顶部命令栏（导航、面包屑、搜索、排序、操作按钮） | `parts/_topbar.html.twig` |
| `fm_settings_popover` | 设置下拉菜单（配色模式、项目尺寸、文件夹样式、缓存、快捷键） | `parts/_settings_popover.html.twig` |
| `fm_banners` | 状态通知横幅 + 上传队列 | 内联结构 |
| `fm_body` | 主体中间区域（侧边栏 + 主区 + 属性检查器容器） | `.liveux-fm-body` |
| `fm_sidebar` | 左侧文件夹树 + 已置顶文件夹 + 配额条 + 回收站 | `parts/_sidebar.html.twig` |
| `fm_main` | 文件网格/列表 + 悬浮选中/剪贴板栏 + 拖拽上传区 + 分页 | `parts/_main.html.twig` |
| `fm_inspector` | 右侧预览与元数据属性检查器面板 | `parts/_inspector.html.twig` |
| `fm_footer` | Modal / OffCanvas 底部操作栏 | `parts/_footer.html.twig` |
| `fm_overlays` | 右键菜单、模态框、灯箱预览以及快捷键/关于弹窗模板 | `parts/_overlays.html.twig` |

#### 示例 —— 自定义品牌顶条 + 自定义选择底部栏
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 企业数字资产中心</span>
        <span>当前配置档：<strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">取消</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">确认选择</button>
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
                # 1) 直接指定 .html.twig 模板路径：
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) 或指定 Twig 命名空间目录（自动加载其中的 layout.html.twig）：
                # theme: '@App/file_manager'
```

---

### 级别 3：从零构建 100% 无头（Headless）布局（必需的 Stimulus Targets）

如果你想不使用 `{% extends %}` 而完全从零编写 HTML 结构，只需使用前缀 `ctrl = 'liveux--file-manager-bundle--browser'` 绑定以下 `data-{{ ctrl }}-target` 属性即可：

| Target（`data-...-target`） | 是否必需 | 用途 |
| :--- | :---: | :--- |
| `shell` | 推荐 | 控制项目缩放尺寸（`--liveux-fm-card-min`）、`data-size-tier`、`data-folder-style`、`data-sidebar-open` |
| `contentArea` | **必需** | 渲染文件/文件夹卡片与列表行的主画布 |
| `modalBackdrop`, `modalTitle`, `modalBody` | **必需** | 新建文件夹、上传、删除、自定义、配额与元数据弹窗的容器 |
| `breadcrumbs` | 推荐 | 可点击的路径面包屑导航 |
| `confirmSelectionBtn`, `footerStatus` | 推荐（Modal/OffCanvas） | 确认选择按钮（未选中任何项时自动设为 `disabled`）与状态提示文本 |
| `statusBanner`, `uploadQueue` | 推荐 | 错误/成功通知横幅与分片上传进度队列 |
| `tree` + `sidebar` + `pinnedFolders` | 可选 | 左侧文件夹树与已置顶文件夹 |
| `contextMenu` | 可选 | 右键上下文菜单容器 |
| `inspector` | 可选 | 右侧预览与元数据检查器面板 |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | 可选 | 通用媒体灯箱预览器 |

---

## 6. 供自定义模板使用的 7 个 Twig 辅助函数

| Twig 函数 | 用途 | 使用示例 |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | 渲染防 XSS 的内联 SVG（无需 `\|raw`）。`group` 可选 `'ui'`、`'icons'`、`'badges'`。 | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | 为文件生成安全的缩略图 URL。 | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | 生成安全的下载链接或内联预览 URL。 | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">下载</a>` |
| `liveux_fm_trans(key, params, locale)` | 翻译 Bundle 消息键，并保证自动回退至 `'en'`。 | `{{ liveux_fm_trans('ui.upload', {}, 'zh_CN') }}` |
| `liveux_fm_bcp47_locale(locale)` | 将 `en_US.UTF-8` 等 PHP/ICU 语言代码转换为合法的 HTML `lang="..."` BCP-47 标签（如 `en-US`）。 | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | 如果给定语言为从右到左书写（阿拉伯语、波斯语、希伯来语等），则返回 `true`。 | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | 安全地将 Form/EasyAdmin 字段值规范化为可渲染的图片/文件描述对象（拦截 `javascript:`/`data:` URL）。 | EasyAdmin 列表/详情页及自定义模板 |

---

[← 上一篇：03 — 使用模式与 JS API](03-usage-modes-and-js-api.md) | [目录](README.md) | [下一篇：05 — FormType 与编辑器集成 →](05-formtype-and-editor-integrations.md)
