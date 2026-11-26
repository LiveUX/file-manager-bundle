# 01 — 环境要求、依赖与安装

[← 文档目录索引](README.md) | [下一章：02 — 配置与 Profile 方案 →](02-configuration-and-profiles.md)

本指南将带你从零开始安装 LiveUX FileManagerBundle——**逐步讲解每个操作以及背后的原因**，即使这是你第一次使用 Symfony UX 生态扩展包，也能轻松上手。

> [!NOTE]
> **通俗来说：这个 Bundle 能做什么？**  
> 它能为你的 Symfony 应用（后台管理系统、电商平台或客户门户）直接集成一套类似 Google Drive 或 Windows 资源管理器的全功能文件管理器。用户只需轻点鼠标即可**上传文件、管理文件夹、裁剪图片、在线预览 PDF 和视频，并在表单中直接选择文件**。  
> 它**不依赖任何 CSS 框架**（Bootstrap、Tailwind 等），也**不需要 jQuery**；搭配 Symfony AssetMapper 使用时，无需 Node.js 或 npm，5 分钟即可完成集成。

---

## 1. 系统环境要求

在安装前，请先确认你的本地开发环境或服务器满足以下最低要求：

| 组件 | 最低版本 | 原因说明 | 检查命令 |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` 及以上 | 使用了 PHP 8.3/8.4 特性（`readonly` 类、带类型的类常量等）。 | `php -v` |
| **Symfony** | `6.4 LTS`、`7.2+` 或 `8.x` | 已在 Symfony 6.4 LTS、7.2、7.3、7.4 与 8.x 上完整测试并支持。 | `php bin/console --version` |
| **Composer** | `2.5+` | 用于安装依赖包及生成 PSR-4 自动加载映射。 | `composer --version` |
| **浏览器** | 现代主流常青浏览器 | 需要原生 HTML5 `<dialog>` Top Layer、ES Modules 与 CSS 自定义属性支持（Chrome、Edge、Firefox、Safari）。 | — |

---

## 2. PHP 扩展

PHP 扩展为 PHP 提供文件魔数检测、XML 解析和图像处理等底层原生能力。

### 必选 PHP 扩展
几乎所有现代 PHP 环境均默认启用了以下扩展：

| 扩展 | 作用说明 | 实际场景示例 |
| :--- | :--- | :--- |
| `ext-fileinfo` | 检测**文件的真实二进制内容（魔数 / Magic Bytes）**，而非仅依赖文件名后缀。 | 如果攻击者将 `shell.php` 改名为 `photo.jpg` 尝试上传，`ext-fileinfo` 会立即识别并拦截。 |
| `ext-dom` 与 `ext-libxml` | 安全解析上传的 `.svg` XML 文档树（`SvgSanitizer`）。 | 在保留正常矢量图形的前提下，自动剥离隐藏在 SVG Logo 中的恶意 `<script>` 标签或 XXE 攻击载荷。 |

*（注：`ext-json` 自 PHP 8.0 起已内置于 PHP 核心，无需单独安装。）*

### 可选（推荐）PHP 扩展
如果缺少以下任意扩展，文件管理器**依然可以正常运行**，并会自动优雅降级：

| 扩展 | 作用说明 | 缺失时的降级行为 |
| :--- | :--- | :--- |
| `ext-gd` **或** `ext-imagick` | 生成高性能 WebP/JPEG 缩略图，并在上传时执行自动图片优化。 | 根据 `thumbnail.on_missing_driver` 配置，回退为显示原图或文件图标。 |
| `ext-exif` | 使用 `ext-gd` 时读取手机照片的 EXIF 方向信息，自动扶正倒置或旋转的照片（`auto_orient: true`）。 | 照片按原始方向保存（若已安装 `ext-imagick` 则无需此扩展）。 |
| `ext-zip`（`ZipArchive`） | 启用多文件打包下载 `.zip` **以及**安全解压压缩包功能（`zip.allow_extract: true`）。 | 界面中的**打包下载 ZIP**与**解压压缩包**按钮会自动隐藏。 |

使用以下命令一键检查当前环境已启用的扩展：

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. 必选与可选 Composer 依赖包

运行 `composer require liveux/file-manager-bundle` 时，**所有必选依赖包都会自动安装**。了解各个依赖的职责有助于后续排查问题：

### 必选依赖包（自动安装）
| 扩展包 | 版本约束 | 用途 |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | 统一的文件系统抽象层，覆盖本地磁盘、AWS S3、Cloudflare R2、Supabase、MinIO 与 SFTP。 |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | 通过 `{{ component('LiveUX:FileManager:Browser') }}` 实现 0ms SSR 服务端渲染。 |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | 驱动客户端目录缓存、拖放上传、右键上下文菜单、灯箱预览（Lightbox）与表单选择器。 |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Symfony 核心运行时、路由系统及缓存池（`cache.app`）。 |
| `symfony/config` 与 `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | 校验 `liveux_file_manager.yaml` 配置结构并完成服务容器装配。 |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | 派发 9 个领域事件（`FileUploadedEvent`、`FileDeletedEvent`、`ItemRenamedEvent` 等）。 |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | 提供安全文件下载、HTTP 206 视频流式播放（`Range`）、ETag 与 HTTP 缓存响应头。 |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | 提供 Twig 模板、图标/翻译 Twig 辅助函数以及 FormType 主题自动注册。 |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | 提供单文件与多文件图库表单字段 `FileManagerPickerType`。 |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | 上传文件的服务端 MIME 类型识别。 |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | 将多语言文件名（中文、土耳其语、法语、阿拉伯语、西里尔字母等）音译为干净的 SEO 友好 Slug。 |
| `symfony/security-core` 与 `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | 提供 17 项权限的 Voter RBAC 访问控制与 `X-LiveUX-FM-CSRF` 令牌校验。 |
| `symfony/cache-contracts` 与 `symfony/translation-contracts` | `^3.5` | 目录缓存契约与多语言界面翻译契约。 |

### 可选依赖包（按需安装）
| 扩展包 | 安装命令 | 适用场景 |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | 无需 Node.js 或 npm，零构建直接交付 JS 与 CSS 资源*（推荐）*。 |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | 基于角色的权限控制（`ROLE_USER` / `ROLE_ADMIN`）与按用户目录隔离（`{user_id}`）。 |
| `symfony/translation` | `composer require symfony/translation` | 对接 Symfony Translator，支持全部 **12 种内置界面语言**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`）或自定义应用语言。 |
| `symfony/console` 与 `symfony/yaml` | `composer require symfony/console symfony/yaml` | 启用 CLI 命令（`file-manager:doctor`、`thumbs:warmup`、`trash:purge` 等）与 YAML 配置文件。 |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | 对接 AWS S3、Supabase Storage、Cloudflare R2、Backblaze B2、MinIO 或 Google Cloud Storage。 |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | 在多服务器 / 负载均衡架构下使用 `defaults.metadata.driver: 'doctrine'`（参见[第 13 章](13-doctrine-integration.md)）。 |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | 在 EasyAdmin 4/5 后台中使用内置的 `FileManagerField`（参见[第 05 章](05-formtype-and-editor-integrations.md)）。 |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | 启用 **✨ AI 智能建议** 自动生成替代文本、说明和标签（参见[第 09 章](09-symfony-ai-integration.md)）。 |

---

## 4. 分步安装指南

按顺序完成以下 7 个步骤，即可在项目中运行起完整的文件管理器。

### 第 1 步：安装 Bundle

**标准安装（通过 Packagist）：**
```bash
composer require liveux/file-manager-bundle
```

**本地路径仓库安装（如果在 `bundles/liveux-file-manager-bundle` 下进行本地开发）：**  
在项目根目录的 `composer.json` 中添加 `repositories` 配置：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

然后引入本地包：
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### 第 2 步：检查 `config/bundles.php`

Symfony Flex 通常会自动注册该 Bundle。如未自动添加，请确认 `config/bundles.php` 中包含以下条目：

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // 如需使用用户认证与角色权限
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` 在启动时会自动注册其 Twig 命名空间（`@LiveUXFileManager`）、AssetMapper 路径（`@liveux/file-manager-bundle`）以及 FormType 表单主题（`@LiveUXFileManager/form/picker.html.twig`）。你无需手动修改 `twig.yaml` 或 `asset_mapper.yaml`。

---

### 第 3 步：引入路由

创建 `config/routes/liveux_file_manager.yaml` 以启用 Bundle 的 AJAX API、下载、缩略图和上传接口：

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

这一行路由引入会自动注册 **12 个受安全保护的端点**：

| 请求方法与路径 | 用途 |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | 为模态框（Modal）或 OffCanvas 抽屉加载文件管理器 HTML。 |
| `GET /_liveux-file-manager/api/list` | 以 JSON 格式返回指定目录下的文件与子文件夹列表。 |
| `GET /_liveux-file-manager/api/tree` | 单次请求获取左侧边栏所需的完整文件夹树。 |
| `GET /_liveux-file-manager/api/trash` | 列出回收站中已软删除的项目。 |
| `GET /_liveux-file-manager/api/preview-text` | 为 `.txt`、`.md`、`.json`、`.yaml`、`.csv`、`.sql` 等文本/代码文件返回安全预览（上限 64 KB）。 |
| `POST /_liveux-file-manager/api/action` | 执行写操作：新建文件夹、移动、复制、重命名、删除、还原/清空回收站、文件夹颜色/图标、元数据、AI 智能建议、远程 URL 导入与 ZIP 解压。 |
| `POST /_liveux-file-manager/upload` | 处理普通文件上传与分片上传。 |
| `POST /_liveux-file-manager/image-edit` | 保存内置 Canvas 图片编辑器裁剪、缩放或旋转后的图片。 |
| `GET /_liveux-file-manager/download` | 提供安全文件下载、PDF/视频内联预览（支持 `Range` 拖动）以及 `private: true` 私有存储卷文件流式输出。 |
| `GET /_liveux-file-manager/share` | 访问带有效期且经 HMAC 签名的分享链接。 |
| `POST /_liveux-file-manager/zip` | 将选中的多个文件与文件夹流式打包为单个 `.zip` 压缩包下载。 |
| `GET /_liveux-file-manager/thumb` | 生成并输出 WebP/JPEG/PNG 缩略图，支持 ETag 与上下文感知的 HTTP 缓存头。 |

---

### 第 4 步：创建 `config/packages/liveux_file_manager.yaml`

创建 `config/packages/liveux_file_manager.yaml`。下面的基础配置显式列出了**全部 17 项细粒度权限**：

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = 自动继承 Symfony 当前激活的区域设置（始终兜底回退至 'en'）
                            # 也可固定为指定语言，例如 'zh_CN'、'en'、'tr'、'fr'、'de'、'es'、'ar'、'ja'

    volumes:
        local_uploads:
            label: '我的文件'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # 默认禁用 ZIP 解压（启用时还需配置 zip.allow_extract: true）
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **重要安全提示（`permissions`）：**  
> 在 `permissions` 下**未显式配置**的任何权限键（`extract_zip` 除外）均默认为 `true`。这里的 `true` 表示**“任意已登录认证的用户”**，而**不是**匿名访客。如果你希望只有 `ROLE_ADMIN` 才能永久清空回收站（`trash_purge`）或创建分享链接（`share`），请将这些权限键显式设置为 `'ROLE_ADMIN'`。

> [!TIP]
> **本地尚未搭建登录系统，想先快速试用？**  
> 在本地原型开发阶段，你可以临时将 `profiles.default.security.voter_enabled` 设为 `false`。**切勿在生产环境中保留 `voter_enabled: false`**——详见[06 — 安全与权限控制（RBAC）](06-security-and-authorization.md)。

> [!NOTE]
> **内置 12 种界面语言与自动 RTL（`defaults.locale: null`）：**  
> 保持 `locale: null` 时，文件管理器会自动跟随 Symfony 当前请求的区域设置，覆盖全部 **12 种内置界面语言**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`），为阿拉伯语及其他从右到左语言（波斯语、希伯来语、乌尔都语等）自动启用 **RTL（`dir="rtl"`）**，规范化 BCP-47 与 POSIX 格式（如 `de_DE.UTF-8`、`zh-Hans-CN`），并在遇到暂未支持的语言时自动回退至**英语（`en`）**。

---

### 第 5 步：接入 CSS 与 JavaScript（零配置！）

**不确定项目使用的是 AssetMapper 还是 Webpack Encore？**  
查看项目根目录即可判断：
- 如果存在 `importmap.php` → 按**方案 A（AssetMapper）**配置。
- 如果存在 `webpack.config.js` → 按**方案 B（Webpack Encore）**配置。

> [!TIP]
> **零配置 `autoimport` 支持：**  
> LiveUX FileManagerBundle 已在其 `assets/package.json` 中为 `@liveux/file-manager-bundle/styles/core.css` 和即时加载的 `dialog_controller.js`（会自动引入 `file_manager_api.js`）声明了 **`autoimport`**。只要在 `assets/controllers.json` 中启用这些控制器，**你无需在 `assets/app.js` 中手写任何一行 `import` 语句！**

#### 方案 A：Symfony AssetMapper（推荐 — 无需 Node.js / npm）

1. 确认 `assets/controllers.json` 中已启用 Bundle 的控制器（Symfony Flex 会自动写入；如未写入请手动添加）：
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Symfony 6.4 / 7.2+ / 8.x 项目默认已在 `assets/bootstrap.js` 或 `assets/stimulus_bootstrap.js` 中调用了 `startStimulusApp()`，它会自动读取 `controllers.json`：
```javascript
// assets/stimulus_bootstrap.js（Symfony 8.x）或 assets/bootstrap.js（Symfony 6.4/7.x）
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. 确认基础布局模板（`templates/base.html.twig`）已引入 `importmap('app')`：
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *（可选 — 生产环境部署时）* 编译静态资源：
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **预压缩的生产级前端资源（`assets/dist/`）：**  
> 由于 AssetMapper 默认不对 JS/CSS 执行代码压缩，LiveUX FileManagerBundle 已在 `assets/dist/` 中随包内置了通过 `esbuild` 预压缩的 JavaScript 模块与 `core.css`（`747 KB → 410 KB`；Gzip 传输约 `~93 KB`，Brotli 传输仅 **`~77 KB`**）。`FileManagerBundle` 会自动将 `assets/dist/` 映射到 AssetMapper，因此**无论是你的本地开发机还是生产服务器，都不需要安装 Node.js 或 npm**。  
> 在 Symfony 7.3+ / 8.x 上，你还可以在 `config/packages/asset_mapper.yaml` 中开启 `precompress: true`，在执行 `asset-map:compile` 时自动生成 `.br` 和 `.gz` 预压缩文件。

#### 方案 B：Webpack Encore

1. 对于 Webpack Encore 项目（包括 Symfony 8 + StimulusBundle 3.x），请确认已安装 `@symfony/stimulus-bridge`：
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. 确认 `assets/controllers.json` 中的 3 个 Stimulus 控制器和 `autoimport` 配置块：
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. 确认 `assets/bootstrap.js` 已配置 `startStimulusApp`（`assets/app.js` 会引入该文件，无需额外手动导入 CSS/JS）：
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. 构建前端资源：
```bash
npm run build
# 或在开发期间实时监听重新构建：npm run watch
```

---

### 第 6 步：`DialogHost`（自动注入 — 无需额外模板代码！）

在任意页面打开模态框（Modal）、OffCanvas 抽屉、`FileManagerPickerType` 或 TinyMCE/CKEditor/Tiptap 选择器时，DOM 中都需要一个 `<dialog>` 宿主容器。

Bundle 内置的 `AutoInjectDialogHostSubscriber` 会在所有完整 HTML 响应的 `</body>` 闭合标签前自动注入该容器（`defaults.auto_inject_dialog_host: true`）——因此你无需手动修改 `base.html.twig`。

- **智能识别：** 绝不干扰 JSON、AJAX、Turbo Frame、重定向或二进制下载响应；若页面中已存在手动引入的 `DialogHost`，也会自动跳过。
- **0ms 首屏开销：** 页面初始加载时不会查询任何目录或文件夹树，只有当用户真正打开弹窗时才会按需拉取数据。
- **希望只在特定页面手动放置？** 将 `defaults.auto_inject_dialog_host` 设为 `false`，并在需要的布局模板 `</body>` 前引入以下片段即可：

```twig
{# 可选 — 仅当 auto_inject_dialog_host: false 时需要 #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### 第 7 步：验证安装结果（`file-manager:doctor`）

清理缓存并运行内置系统诊断命令：

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

如果你配置了云端存储卷（S3、R2、Supabase、MinIO），可以追加 `--check-remote` 参数测试远程连通性：
```bash
php bin/console file-manager:doctor --check-remote
```

健康状态下的输出示例（包含 9 项环境检查 + Profile/存储卷状态表）：
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Windows 用户（`cURL error 60`）：** 如果 `cURL CA Bundle` 显示警告，请在 `php.ini` 中将 `curl.cainfo` 和 `openssl.cafile` 指向最新的 `cacert.pem`，或者在 S3 存储卷上配置 `driver_options.ca_bundle`。
>
> **Linux 目录权限：** 请确保 Web 服务器运行用户（如 `www-data`、`nginx` 等）对 `public/uploads`、`public/media-cache` 和 `var/file-manager` 拥有写权限。
>
> **正在使用 AI 编程助手（Antigravity、Claude Code、Cursor、Copilot）？**  
> 一条命令即可将本 Bundle 的所有规范与实战配方注入到你的 AI 助手中（参见[第 12 章](12-ai-agent-skills-guide.md)）：
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. 创建你的第一个可运行页面

下面创建一个简单的 Symfony Controller 和 Twig 模板，端到端验证整个流程。

### 1）控制器（`src/Controller/MediaController.php`）
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2）Twig 模板（`templates/admin/media.html.twig`）
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>媒体库</h1>

    {# 方式 1：内联模式文件管理器 #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# 方式 2：通过任意按钮打开模态框并获取用户选中的文件 #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">在模态框中选择文件</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // 用户未选择任何文件便关闭了弹窗
            }

            document.getElementById('selection-result').textContent =
                '已选择：' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

在浏览器中访问 `/admin/media`。恭喜——你的文件管理器已经成功运行！

---

## 6. 下一步阅读

- 自定义 Profile、上传限制与图片约束规则 → **[02 — 配置与 Profile 方案](02-configuration-and-profiles.md)**
- 在 Symfony 表单中集成单图或图库选择器 → **[05 — FormType 与编辑器集成](05-formtype-and-editor-integrations.md)**
- 上线前加固 RBAC 权限与纵深安全策略 → **[06 — 安全与权限控制（RBAC）](06-security-and-authorization.md)**

---

[← 文档目录索引](README.md) | [下一章：02 — 配置与 Profile 方案 →](02-configuration-and-profiles.md)
