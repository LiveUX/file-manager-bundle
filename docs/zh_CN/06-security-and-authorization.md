# 06 — 安全与权限控制（RBAC 与纵深防御）

[← 上一篇：05 — FormType 与编辑器集成](05-formtype-and-editor-integrations.md) | [目录](README.md) | [下一篇：07 — 云存储与动态配置 →](07-cloud-storage-and-dynamic-config.md)

文件管理器是任何 Web 应用中风险最高的攻击面之一。LiveUX FileManagerBundle 采用**纵深防御（Defense-in-Depth）**架构设计，默认内置多层安全防护机制。

> **速览：3 步完成安全加固**  
> 1. **保护路由（`security.yaml`）：** 阻止未登录访客访问文件管理器接口端点。  
> 2. **定义角色权限（`permissions`）：** 在每个配置档（Profile）中明确声明谁可以查看（`view`）、上传（`upload`）和删除（`delete`）。  
> 3. **多防火墙应用添加路由前缀（`prefix`）：** 如果应用为后台管理区（`/admin`）和普通用户区（`/`）配置了独立防火墙，请在 `config/routes/liveux_file_manager.yaml` 中将 Bundle 路由挂载到 `prefix: /admin` 下，确保管理员会话正确解析，同时防止普通会员访问后台文件。

---

## 1. 在 `security.yaml` 中保护路由（第一步）

在生产环境中，务必将 `/_liveux-file-manager` 下的所有端点置于 Symfony 防火墙保护之下：

```yaml
# config/packages/security.yaml（单防火墙应用）
security:
    access_control:
        # 仅当你需要面向外部访客提供签名分享链接时，才放行此端点：
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**如果你有两个独立的防火墙 —— 例如后台管理区（`/admin`）和前台普通会员区（`/`）：**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. 基于配置档的权限控制（`permissions` 与 `FileManagerVoter`）

每个配置档都允许你针对 **17 项独立操作**分别配置访问规则。

### 权限取值含义说明
- **`true`：** **仅限已认证登录用户（`$token->getUser() !== null`）！** *（绝不会向匿名访客放行。）*
- **`false`：** 禁止所有人（彻底禁用该操作）。
- **`'ROLE_ADMIN'`：** 仅允许拥有该特定角色的用户。
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`：** 拥有列表中任意角色的用户均可访问。

### 4 条自动生效的权限黄金法则
1. **`permissions.view` 是总闸门：** 如果用户缺少 `view` 权限，则 `upload`、`delete`、`download` 以及其他所有操作都会被自动拒绝。
2. **默认失败关闭（Fail-Closed，`FileManagerAuthorizer`）：** 当配置了 `security.voter_enabled: true` 但项目未安装 `AuthorizationCheckerInterface`（即 `symfony/security-bundle`）时，Bundle 会**安全地拒绝访问（抛出 `AccessDeniedException`）**，绝不会默认放行。
3. **祖先目录链逐级校验（`isPathAndAncestorsGranted`）：** 当用户访问 `confidential/2026/invoice.pdf` 时，你的自定义 Voter 不仅会校验 `confidential/2026/invoice.pdf`，还会自动校验其上级父目录 **`confidential`** 和 **`confidential/2026`** —— 彻底杜绝通过猜测 URL 绕过受限父目录的漏洞。
4. **ZIP 解压双锁机制（`zip.allow_extract`）：** 只要 `zip.allow_extract: false`，无论 `permissions.extract_zip` 如何配置，服务端都会拒绝解压操作。

### 17 项 Voter 权限完整参考

| YAML 键名 | Voter 常量（`FileManagerPermissions::*`） | 受保护的操作 |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | 目录列表、文件夹树、缩略图、内联预览、弹窗界面，以及**其他所有权限的前置条件** |
| `upload` | `FILE_MANAGER_UPLOAD` | 文件上传、分片上传、远程 URL 导入、图片编辑器保存 |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | 创建新文件夹 |
| `rename` | `FILE_MANAGER_RENAME` | 重命名文件与文件夹 |
| `clone` | `FILE_MANAGER_CLONE` | 克隆/复制文件与文件夹副本 |
| `move` | `FILE_MANAGER_MOVE` | 移动项目（拖拽移动与“移动”弹窗 —— 同时校验源路径与目标路径） |
| `copy` | `FILE_MANAGER_COPY` | 复制项目（同时校验源路径与目标路径） |
| `delete` | `FILE_MANAGER_DELETE` | 删除项目（移入回收站 / 永久删除）与 `checkUsage` 引用检查 |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | 从回收站还原项目 |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | 永久删除项目或清空回收站 |
| `download` | `FILE_MANAGER_DOWNLOAD` | 单文件下载、PDF/视频流式传输以及复制 URL |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | 多选打包 ZIP 下载（对选中的每一条路径逐一鉴权） |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | 安全解压 ZIP（同时要求 `zip.allow_extract: true`） |
| `share` | `FILE_MANAGER_SHARE` | 生成带有效期的 HMAC 签名分享链接（同时要求 `share.enabled: true`） |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | 清除目录/配置档/缩略图缓存，以及 `?refresh=1` 和 `?fallback=1` 缓存绕过参数 |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | 更新文件夹颜色/图标/置顶状态，以及文件 Alt 文本、标题、标签与焦点坐标 |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | AI 辅助元数据建议（需同时具备 `view` + `edit_metadata` + `ai.enabled: true`） |

### 企业级配置档示例

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **界面感知的权限控制：** 用户无权执行的操作按钮（例如当 `delete: 'ROLE_ADMIN'` 时，`ROLE_EDITOR` 对应的删除按钮）会在界面中自动隐藏；同时若有人直接调用接口，服务端也会严格返回 `HTTP 403 Forbidden`。

---

## 3. 编写自定义 Symfony Voter（细粒度文件夹与用户规则）

当 YAML 角色配置不足以满足业务需求时 —— 例如*“禁止用户删除 `locked/` 文件夹，或禁止将文件移动到 `locked/` 中”* —— 你可以编写自己的 Symfony Voter。

> [!WARNING]
> **新手最常踩的坑：`affirmative` 与 `unanimous` 决策策略！**  
> Symfony 默认的 `access_decision_manager.strategy` 是 **`affirmative`**（只要任意一个 Voter 返回 `true` 就会放行）。这意味着如果 Bundle 内置的 `FileManagerVoter` 对 `ROLE_ADMIN` 用户投了赞成票（`true`），你自定义 Voter 返回的 **`false`（拒绝）票会被覆盖**，用户依然能够删除文件！  
>  
> 为确保你自定义 Voter 的**拒绝规则**真正生效，请在 `config/packages/security.yaml` 中将决策策略设置为 **`unanimous`**（所有参与投票的 Voter 必须一致同意；只要有一票 `false` 即拦截该操作）：
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *（Bundle 会通过 `DetectExternalVotersPass` 自动检测应用中的自定义 Voter，并在文件夹树与文件计数上执行逐项 Voter 过滤，同时兼顾数据隐私与统计准确性。）*

### `FileManagerSubject` 属性一览

传入自定义 Voter `voteOnAttribute()` 方法的 `$subject` 对象包含 4 个只读（`readonly`）属性：

| 属性 | 类型 | 说明 | 示例 |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | 当前激活的配置档对象（`$subject->profile->name`） | `'default'` |
| `$subject->path` | `string` | 源文件/文件夹相对于存储卷根目录的相对路径 | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | 当前存储卷名称 | `'local_uploads'` |
| `$subject->targetPath` | `?string` | `MOVE`、`COPY`、`RENAME` 或 `CLONE` 操作的**目标路径**（其他操作为 `null`） | `'locked/new-name.pdf'` |

### 自定义 Voter 示例（禁止删除、重命名或移动至 `locked/` 文件夹）

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) 禁止删除、移动或重命名 "locked" 文件夹及其内部任何内容
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) 同时禁止将文件移动或重命名到 "locked" 文件夹中
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 层内置纵深防御机制（针对真实世界攻击加固）

| # | 攻击向量 | Bundle 防护机制 |
| :---: | :--- | :--- |
| 1 | **CSRF（跨站请求伪造）** | 所有写入、删除、上传、ZIP 与图片编辑 `POST` 请求在解析任何输入**之前**，都会先校验 `X-LiveUX-FM-CSRF` 请求头（`CsrfRequestValidator`）。 |
| 2 | **路径穿越、Unicode 与操作系统绕过技巧** | `PathNormalizer` 拦截 `../`、`..\`、空字节（`\0`）、控制字符、`C:\`、`phar://`/`php://` 流封装器、末尾点号/空格、零宽/双向 Unicode 字符、CJK 句号（`。`）、NTFS 备用数据流（`file.txt:stream`）及 Windows 保留设备名（`CON`、`PRN`、`AUX`、`NUL`、`COM1-9`、`LPT1-9`、`CONIN$`、`CONOUT$`），并强制限制最大 **32 层目录深度**、单段最长 **255 字节**以及总路径上限 **1024 个 UTF-8 字符 / 3072 字节**。 |
| 3 | **可执行文件、多态文件（Polyglot）与双扩展名** | `FileSecurityGuard` 内置 **69 个禁止扩展名**（`.php*`、`.phtml`、`.phar`、`.html`、`.shtml`、`.cgi`、`.aspx`、`.exe`、`.sh`、`.htaccess`、`.user.ini`、`.env`、`.meta.json`、`web.config` 等）、**47 种禁止 MIME 类型**、双扩展名检测（`shell.php.jpg`）以及**文件魔数（Magic Bytes）多态校验** —— 在上传、重命名（即便开启 `allow_extension_change: true`）和下载环节均严格生效。 |
| 4 | **直接访问隐藏目录与回收站** | 所有接口端点均拒绝访问 `.trash`、`.env`、`.git`、`.htaccess` 以及命中 `exclude_patterns` 的路径；`ZipDownloadController` 会对**每一条选中的路径**单独执行 `DOWNLOAD_ZIP` Voter 校验。 |
| 5 | **SVG XSS、XXE 与 `<use>` DoS 炸弹** | `SvgSanitizer` 拒绝 `<!DOCTYPE`/`<!ENTITY`，采用**严格的 55 个标签白名单**，限制 SVG 最大 **5 MB**，过滤危险 CSS 语法（`@import`、`expression()`、`url(javascript:)`），移除 `<script>`/`<foreignObject>`/`on*`/`javascript:`，并限制 `<use>` 展开攻击（最多 `100` 个 `<use>`、嵌套深度 `4`、展开节点上限 `1000`、分析步数上限 `5000`、重复 ID 上限 `8`）。 |
| 6 | **SSRF 与 DNS 重绑定（远程 URL 导入）** | `RemoteUrlImporter` 默认关闭（`allow_remote_url_import: false`）。启用后通过 `inet_pton()` 校验 IP，拦截回环地址、内网私有网段、`0.0.0.0`、`100.64.0.0/10` CGNAT、云元数据服务（`169.254.169.254`）、IPv4 映射 IPv6（`::ffff:127.0.0.1`）、NAT64（`64:ff9b::`）、SIIT/ORCHID/SRv6 及 UTS-46 IDNA / 全角 / 八进制 / 十六进制 IP 绕过，并通过 **`CURLOPT_RESOLVE` IP 锁定**彻底消除 DNS 重绑定攻击。 |
| 7 | **图片编辑器多态文件 RCE** | `ImageEditorController` 限制 `overwrite` 仅可覆盖已存在的安全光栅图片（`jpg/jpeg/png/webp`），并强制校验 `upload_max_size`、配置档的 `upload_allow`/`upload_deny`、资产使用保护以及真实的 `getimagesizefromstring()` 图像校验。 |
| 8 | **解压（像素）炸弹与 ImageTragick** | `GdThumbnailDriver` 与 `ImagickThumbnailDriver` 在将图像解码入内存之前先通过 `getimagesizefromstring()` 检测尺寸（上限 `8192×8192` 且总像素不超过 `40 MP`），并对 Imagick 施加严格的内存、超时与线程数限制。 |
| 9 | **符号链接逃逸与根目录隔离** | `ConfinedLocalFilesystemAdapter` 在每次本地读、写、移动、复制和删除操作时，都会逐段验证 `is_link()` + `realpath()` 根目录边界；拒绝递归删除或移动包含符号链接的子树，并在目录遍历时强制启用 `SKIP_LINKS`。 |
| 10 | **多租户（`{user_id}`）目录冲突防护** | `DynamicPathResolver` 为邮箱、大小写混排、Unicode、保留名（`guest`、`user`）及 Windows 设备名生成带独立 SHA-256 哈希的 `<slug>--h-<16-hex>` 后缀，从根源上杜绝不同用户之间发生目录碰撞。 |
| 11 | **下载、缩略图与 CDN 缓存隔离** | `DownloadController`、`ThumbnailController` 与 `ZipDownloadController` 统一输出 `X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer` 与 `Content-Security-Policy: default-src 'none'; sandbox`；在私有/Voter/会话/`{user_id}` 上下文中强制返回 `Cache-Control: private` + `Vary: Cookie, Authorization`；要求 `?refresh=1` 和 `?fallback=1` 具备 `CLEAR_CACHE` 权限；对 HTML/JS/SVG/XML 强制下发 `Content-Disposition: attachment`。 |
| 12 | **FormType POST 与 Twig 来源保护** | `FileManagerPickerType` 在服务端拦截被篡改的 POST 载荷（XSS 协议、`../`、`.trash/`、大于 256 KB 的 JSON、超过 500 项、非法 UTF-8、超出 `max_files` 限制、HTTP 参数污染数组）；`FileManagerTwigExtension` 与前端 `isSafeUrl()` 共同防止外部/危险 URL 来源伪造。 |
| 13 | **任意主题/图标目录穿越与 DOM XSS** | 运行时 `dialog?theme=` 仅接受 9 套内置主题，`dialog?iconSet=` 仅接受 6 套内置图标集；`sanitizeHexColor()` 防止颜色值注入；分片暂存目录按用户隔离（权限 `0750`）。 |
| 14 | **DoS、日志注入与缓存池隔离** | 批量删除上限 200 项，分片上传上限 2000 片，递归文件夹复制上限 5000 项；`AuditLogListener` 自动清洗日志注入载荷（`\r`/`\n`/ANSI/控制字符）；`DirectoryCacheManager` 绝不会清空宿主应用的 `cache.app` 缓存池（绝不调用 `clear()`）。 |

---

## 5. Web 服务器层安全加固（Apache 与 Nginx）

作为额外防线，请务必在 Web 服务器层面禁止 `public/uploads` 等公开上传目录执行 PHP 或其他脚本：

### Apache（`public/uploads/.htaccess`）
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx（`nginx.conf`）
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. 签名分享链接与资产使用保护

- **带有效期的签名分享链接（`ShareController`）：** 当 `share.enabled: true` 时，系统会使用 Symfony 的 `UriSigner`（HMAC 签名 + `expires` 时间戳）生成临时链接。将配置档的 `share.enabled` 设为 `false` 会立即撤销所有既有链接并返回 `403 Forbidden`；过期链接返回 `410 Gone`；所有响应均携带 `Cache-Control: private, no-store` 与严格的 CSP 安全头。
- **资产使用保护（`AssetUsageCheckerInterface`）：** 防止删除、重命名、移动或覆盖数据库中仍在被引用的文件（详见 [10 — 资产使用保护](10-asset-usage-guard.md)）。

---

## 7. 审计日志 —— 生产环境配置与示例

`AuditLogListener` 会记录全部 9 类文件/文件夹变更事件，并附带结构化的 PSR-3 上下文（`action`、`profile`、`volume`、`path`、`user`、`ip` 以及各事件专属的元数据），同时自动清洗控制字符、CRLF 和 BiDi 字符以防止日志注入。

### 第 1 步：启用审计日志（`config/packages/liveux_file_manager.yaml`）

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # 按配置档启用或覆盖
        public_temp:
            security:
                audit_log: false
```

### 第 2 步：将审计日志路由到专用的按日轮转 JSON 日志文件（`monolog.yaml` + `services.yaml`）

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 保留 90 天并自动轮转
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### 结构化 JSON 输出示例

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### 全部 9 类受审计的生命周期事件

| Symfony 事件 | `action` 键名 | 额外上下文字段 |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### 第 3 步（可选）：将审计日志持久化到 Doctrine 数据库表

如果希望在后台管理面板中提供可检索的审计记录，可以订阅相同的 Symfony 事件：

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> 在高并发应用中，建议派发异步的 Symfony **Messenger** 消息，而不是直接同步调用 `$this->em->flush()`，从而保持文件操作非阻塞。

---

[← 上一篇：05 — FormType 与编辑器集成](05-formtype-and-editor-integrations.md) | [目录](README.md) | [下一篇：07 — 云存储与动态配置 →](07-cloud-storage-and-dynamic-config.md)
