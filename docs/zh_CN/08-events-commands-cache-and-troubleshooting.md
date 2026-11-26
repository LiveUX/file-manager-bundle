# 08 — 事件、CLI 命令、缓存、多语言与常见问题（FAQ）

[← 上一篇：07 — 云存储与动态配置](07-cloud-storage-and-dynamic-config.md) | [文档首页](README.md) | [下一篇：09 — Symfony AI 集成 →](09-symfony-ai-integration.md)

> [!TIP]
> **快速了解：事件与 CLI 命令有什么用？**
> - **事件（Events）：** 当用户上传、删除、重命名或移动文件时自动通知你的 Symfony 应用——例如：*“当某个文件被重命名时，自动同步更新数据库中的商品图片路径。”*
> - **CLI 命令（`php bin/console file-manager:...`）：** 内置的命令行运维工具，可用于运行环境体检（`doctor`）、清除缓存（`cache:clear`）、预热缩略图（`thumbs:warmup`）或定期清理过期回收站（`trash:purge`）。

---

## 1. Symfony 领域事件（9 个内置事件）

文件管理器的所有事件都继承自统一的抽象基类 **`AbstractFileManagerEvent`**（包含 `$profile`、`$volume`、`$path`、`$canonicalPath`）。你可以监听这些事件来保持数据库同步、记录审计日志、刷新 CDN 缓存或派发异步后台任务。

| 事件类（`LiveUX\FileManagerBundle\Event\*`） | 触发时机 | 属性 |
| :--- | :--- | :--- |
| `FileUploadedEvent` | 上传文件、从 URL 导入、通过图片编辑器保存或从 ZIP 压缩包解压文件时 | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | 删除文件时（移至回收站或永久删除） | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | 创建文件夹时（包括 ZIP 解压过程中自动创建的目录） | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | 删除文件夹时 | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | 重命名文件或文件夹时 | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | 移动或复制项目时（包括跨存储卷传输） | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | 创建文件或文件夹副本（克隆）时 | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | 从回收站还原项目时 | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | 从回收站永久删除一个或多个项目时 | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### 事件监听器示例（`#[AsEventListener]`）

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('New media asset uploaded.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. 控制台（CLI）命令（10 个内置命令）

| 命令 | 参数 / 别名 | 说明 |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | 检查 PHP 版本、`upload_max_filesize`、`post_max_size`、cURL CA 证书包、GD/Imagick、EXIF、WebP、`ext-zip` 以及所有配置文件与存储卷配置。加上 `--check-remote` 可对 S3/R2/Supabase/MinIO 执行实时连通性检测（`ListObjectsV2`）。 |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | 清除所有配置或指定配置/存储卷/路径的目录与配额缓存（使用 `--volume`/`--path` 时必须传入 `profile` 参数；绝不会清空 `cache.app` 中的其他无关缓存键）。 |
| `file-manager:thumbs:warmup [profile]` | 别名：`thumbs:generate`、`thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | 预先生成图片缩略图，避免编辑人员首次打开目录时等待。 |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | 清除已缓存的缩略图。`--orphans` 仅清理源文件已不存在的孤立缩略图（不可与 `--volume`/`--path` 同时使用，且自动保护 `{user_id}` / `{Y}/{m}/{d}` 共享目录）。 |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | 对已有图片进行批量压缩优化（单图安全/内存上限为 `25 MB`）。 |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | 基于 SHA-256 内容哈希扫描重复文件，并统计可释放的磁盘空间。 |
| `file-manager:stats [profile]` | `--json` | 输出存储用量、文件/文件夹总数、配额使用率、缩略图及回收站统计信息。 |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *（默认 `50`）*, `--dry-run` | 使用 AI 批量补全缺失的 `alt` 替代文本、标题、说明与标签。 |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *（`0` = 清空全部）*, `--dry-run` | 永久清除超过保留期限的回收站条目。当多个配置文件共享同一物理目录时，自动遵守最长的 `retention_days` 保留天数。 |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | 将本 Bundle 内置的 6 个 AI Agent Skills 安装到 `.agents/skills`、`.claude/skills`、`.cursor/skills` 或 `.github/skills`。 |

> [!NOTE]
> **CLI 命令对动态 `{user_id}` 与 `{Y}/{m}/{d}` 路径的自动保护**
> 由于命令行模式下没有登录用户会话，所有扫描与清理命令（`doctor`、`duplicates`、`ai:enrich`、`images:optimize`、`stats`、`thumbs:clear`、`thumbs:warmup`、`trash:purge`）都会**自动跳过**包含 `{user_id}` 的存储卷（避免意外生成空的 `guest/` 目录）。对于包含 `{Y}/{m}/{d}` 日期占位符的存储卷，命令仅扫描当前日期区间并输出提示信息。

### 自动清理回收站的 Cron 定时任务示例

```cron
# 每天凌晨 03:00 自动清除过期回收站条目
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. 性能、缓存架构与 Symfony Profiler

- **三级缓存 TTL 与手动刷新（`↻`）：** 目录列表缓存时长按 **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** 优先级解析（设为 `0` 表示对该存储卷或配置禁用缓存）。点击工具栏中的 **刷新（`↻`）** 或按快捷键 `R` 会附加 `?refresh=1` *（跳过缓存需要拥有 `CLEAR_CACHE` 权限）*。
- **专用缓存池与配置策略指纹：** 可将 `defaults.cache.pool` 指向专用的 Redis/APCu 缓存池（若存在 `<pool>.taggable` 服务，会自动启用标签缓存）。在非标签缓存模式下，`DirectoryCacheManager` 采用 30 天有效期的代数计数器键，绝不会对你的主应用缓存调用 `$cachePool->clear()`。当两个配置文件共享同一物理目录但规则不同（如 `ignore_dot_files`、`exclude_patterns`、`trash.directory`、`locale`、`max_scan_files`）时，系统通过 `buildProfilePolicyFingerprint()` 保持双方缓存严格隔离。
- **规范路径键（Canonical Key）同步失效：** 如果两个配置文件指向同一个物理目录，在其中一个配置中上传或修改文件时，会自动同步失效另一个配置的缓存。
- **自愈式 `remember_last_dir`：** 若用户上次访问的目录已在外部（例如通过 FTP 或 S3 控制台）被删除，文件管理器会自动向上回退至最近存在的父目录或根目录，不会报错中断。
- **HTTP 304 与上下文感知缩略图缓存（`ThumbnailController`）：**
  - **私有 / 认证上下文**（`private: true` 存储卷、`{user_id}` 动态存储卷、受 Voter 保护的配置、携带 `Authorization` 请求头或存在 Session Cookie）：返回 `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization`，彻底防止共享 CDN 缓存泄漏。
  - **公开上下文：**
    - 带有 `?v=<version>` 版本号参数 → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable`（30 天强缓存）。
    - 不带 `?v=` 参数 → `Cache-Control: public, max-age=3600, must-revalidate`（1 小时缓存 + `ETag` / `304 Not Modified`）。
    - 当配置显式设置 `cache.enabled: false` → `Cache-Control: public, no-cache, must-revalidate`。
  - 所有缩略图响应均附带 `Content-Security-Policy: default-src 'none'; sandbox`、`X-Content-Type-Options: nosniff` 以及 `Referrer-Policy: no-referrer` 安全响应头。
- **Symfony Web Debug Toolbar：** 在 `dev` 环境下，调试工具栏中的 **FileManager** 面板会实时展示当前激活的配置文件、存储卷、缩略图驱动以及缓存命中/未命中统计。

---

## 4. 国际化多语言（`493` 个词条）、BCP-47 / POSIX / RTL 与自定义语言

LiveUX FileManagerBundle 开箱内置 **12 种界面语言**——**英语（`en` — 兜底语言）、土耳其语（`tr`）、法语（`fr`）、德语（`de`）、西班牙语（`es`）、阿拉伯语（`ar`）、意大利语（`it`）、葡萄牙语（`pt`）、荷兰语（`nl`）、波兰语（`pl`）、日语（`ja`）和简体中文（`zh_CN`）**——全部完整覆盖 **493 个翻译键**。

- **支持 BCP-47 与 POSIX `.UTF-8` 语言标识：** `MessageTranslator` 会自动解析各类区域及 POSIX 语言格式，例如 `tr_TR.UTF-8`、`de_DE@euro`、`zh-Hans-CN`（回退链路：`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`）。`toBcp47()` 会输出符合规范的 BCP-47 代码用于 HTML `lang` 属性。
- **自动从右到左（RTL）排版检测：** 自动识别 **15 种 RTL 语言与文字体系**——包括阿拉伯语（`ar`）、波斯语（`fa`）、希伯来语（`he`）、乌尔都语（`ur`）、普什图语（`ps`）与中库尔德语（`ckb`）（以及 `Arab`、`Hebr`、`Syrc`、`Thaa` 等脚本代码），自动为组件切换 `dir="rtl"` 布局。
- **编译期语言目录白名单：** `FileManagerBundle` 仅接受实际存在翻译目录的语言代码，防止攻击者伪造任意 locale 参数导致缓存或 OPcache 膨胀（DoS）。
- **新增自定义语言（如瑞典语 `sv`）或局部覆写：**
  只需在你的项目中创建 `translations/LiveUXFileManagerBundle.sv.yaml`（或在现有语言如 `LiveUXFileManagerBundle.zh_CN.yaml` 中仅覆写需要调整的键）。任何未提供的键都会自动回退到英语（`en`），用户绝不会看到类似 `ui.upload` 的原始键名。你可以复制 Bundle 自带的 `translations/LiveUXFileManagerBundle.en.yaml` 作为完整模板。

---

## 5. 常见问题与故障排查（FAQ）

### 1. `window.LiveUXFileManager is not initialized / undefined`
- 检查 `assets/app.js` 中是否已引入 `import '@liveux/file-manager-bundle/js/file_manager_api.js';`。
- 确认 `defaults.auto_inject_dialog_host: true` 已开启（若手动禁用了该选项，请确保在 `base.html.twig` 中手动引入了 `DialogHost.html.twig`）。
- 运行 `php bin/console cache:clear` 并在浏览器中强制刷新（`Ctrl+Shift+R`）。

### 2. 所有 POST 请求都返回 `403 Invalid or missing CSRF token`
- 确认已安装 `symfony/security-csrf`，且 `framework.csrf_protection: true` 与 `framework.session.enabled: true` 均已启用。
- 如果你手动编写自定义 `fetch()` 请求调用接口，请务必附带 `X-LiveUX-FM-CSRF` 请求头。

### 3. 接口返回 `403 Access denied`
- 检查 `liveux_file_manager.yaml` 中对应配置文件的 `permissions` 区块。注意：即使将某项权限设为 `true`，也仍然要求用户处于已登录状态，且所有其他操作都必须具备 `permissions.view` 权限。
- 如果你实现了自定义 Symfony Voter，请检查 `config/packages/security.yaml` 中的 `access_decision_manager.strategy`（如 `unanimous` 与 `affirmative` 策略差异）。

### 4. 大文件上传失败（`upload_max_filesize`）
- 开启 `chunk_upload.enabled: true` 后，分片大小会自动限制在 PHP `upload_max_filesize`/`post_max_size` 的 `80%` 以内（例如在 `2M` 的 PHP 限制下自动按 `1.60 MB` 分片，最多支持 2,000 个分片）。
- 允许上传的文件总大小由 `liveux_file_manager.yaml` 中的 `upload_max_size` 控制（例如上传 100 MB 视频时设为 `upload_max_size: '100M'`）。

### 5. 下载文件导致页面异常（Symfony UX Turbo Drive）
- Bundle 内置的所有下载链接都已加上 `data-turbo="false" download`。如果你覆写了 Twig 模板，请确保自定义的下载 `<a>` 标签同样带有 `data-turbo="false"`。

### 6. 未生成缩略图，始终加载原图
- 运行 `php bin/console file-manager:doctor`，确认 `Thumbnail Driver` 一行显示 `gd` 或 `imagick` 已就绪。
- 检查 `thumbnail.cache_dir`（默认 `public/media-cache`）对 Web 服务器运行用户是否具有可写权限。

### 7. Windows 下连接 S3 / Supabase 报错 `cURL error 60: SSL certificate problem`
- 下载最新的 `cacert.pem` 证书包并在 `php.ini` 中配置 `curl.cainfo` 与 `openssl.cafile`，或者直接在该 S3 存储卷上配置 `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`。

### 8. 前端静态资源修改未在浏览器中生效
- **开发环境：** 运行 `php bin/console cache:clear` 并执行浏览器硬刷新（`Ctrl+Shift+R`）。
- **生产环境（AssetMapper）：** 运行 `php bin/console asset-map:compile`。

---

[← 上一篇：07 — 云存储与动态配置](07-cloud-storage-and-dynamic-config.md) | [文档首页](README.md) | [下一篇：09 — Symfony AI 集成 →](09-symfony-ai-integration.md)
