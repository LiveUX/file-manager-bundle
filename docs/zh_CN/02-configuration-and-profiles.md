# 02 — 配置与 Profile 方案（完整参数参考与实战配方）

[← 上一章：01 — 环境要求与安装](01-requirements-and-installation.md) | [文档目录索引](README.md) | [下一章：03 — 使用模式与 JavaScript API →](03-usage-modes-and-js-api.md)

本指南全面解析 `config/packages/liveux_file_manager.yaml` 中的**每一个配置选项**、其默认值与使用场景，并提供 **5 套可直接复制使用的生产级实战配方**，助你快速落地项目配置。

---

## 30 秒理解配置架构：`defaults`、`volumes` 与 `profiles`

将整个配置文件理解为**清晰的三层结构**：

1. **`defaults`（全局默认值）：** 所有 Profile 共同继承的基础配置（主题、区域语言、颜色模式、缓存、元数据驱动、审计日志、AI 默认设置）。除非某个 Profile 单独覆写，否则这些值全局生效。
2. **`volumes`（存储卷 / 存储桶）：** 定义**文件的物理存储位置**（本地目录、AWS S3、Supabase Storage、Cloudflare R2、Backblaze B2、MinIO、Google Cloud Storage 或自定义 Flysystem 服务）。
3. **`profiles`（使用场景 Profile）：** 为特定页面或业务流程定义独立的**规则、UI 布局与权限**。例如：
   - `product_catalog` 仅允许上传 `1:1` 正方形商品主图，并自动转换为 WebP；
   - `customer_docs` 连接私有 Supabase S3 存储桶，仅接受 PDF，并将每位客户隔离在各自的专属目录中；
   - `editor_picker` 为 TinyMCE 提供精简弹窗选择器，支持双击直接选中插入。

---

## 1. `defaults` — 全局默认值

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = 继承 Symfony 当前激活的区域设置（兜底回退至 'en'）
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| 参数 | 默认值 | 作用说明 | 何时调整 |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | 在未配置 `volumes`、直接在 Profile 中内联写 `path` 的简写模式下的默认根目录。 | 需要将本地文件存放在其他目录（如 `public/media`）时。 |
| `theme` | `'bootstrap5'` | 所有 Profile 的默认 UI 主题（`bootstrap5`、`tailwind`、`shadcn`、`flowbite`、`daisyui`、`tabler`、`preline`、`material`、`unstyled` 或自定义主题名）。 | 匹配应用自身的设计体系（例如 Tailwind 项目使用 `'shadcn'` 或 `'tailwind'`）。 |
| `locale` | `null` | 为 `null` 时自动继承 Symfony 当前请求/翻译器的区域设置，支持全部 **12 种内置界面语言**（`en`、`tr`、`fr`、`de`、`es`、`ar`、`it`、`pt`、`nl`、`pl`、`ja`、`zh_CN`），自动规范化 BCP-47 与 POSIX 格式（如 `de_DE.UTF-8`、`zh-Hans-CN`），为阿拉伯语等从右到左文字自动启用 RTL（`dir="rtl"`），未知语言回退至 `'en'`。 | 需要强制所有用户统一使用单一界面语言（如 `'zh_CN'`、`'en'`、`'de'`）时。 |
| `color_scheme` | `'auto'` | 颜色模式：`'auto'`（自动检测 `.dark`、`[data-bs-theme="dark"]`、`[data-theme="dark"]`）、`'light'`（始终浅色）或 `'dark'`（始终深色）。 | 需要锁定后台为纯浅色或纯深色主题时。 |
| `z_index` | `1050` | 模态框、OffCanvas 抽屉、灯箱（Lightbox）与右键上下文菜单的基础层叠上下文（`--liveux-fm-z-base`，要求 `>= 1`）。 | 当后台导航栏使用了极高 `z-index`（如 `9999`）时，可调高至 `10050`。 |
| `auto_inject_dialog_host` | `true` | 在完整 HTML 响应的 `</body>` 前自动注入 `window.LiveUXFileManager.open()` 所需的 `<dialog>` 宿主容器（无需修改 `base.html.twig`）。 | 如果希望仅在指定布局模板中手动引入 `@LiveUXFileManager/components/DialogHost.html.twig`，设为 `false`。 |
| `dialog_host_profile` | `'default'` | 当调用方未指定 Profile 时，自动注入的 `DialogHost` 默认使用的 Profile 名称。 | 当你的主 Profile 不叫 `default`（例如叫 `admin_media`）时。 |
| `cache.enabled` | `true` | 为目录列表和文件夹树启用服务端 PSR-6 缓存。 | 本地调试时可设为 `false`（也可按 Profile 或存储卷单独关闭）。 |
| `cache.pool` | `'cache.app'` | Symfony PSR-6 缓存池（Redis、Filesystem、APCu 等）。若存在 `<pool>.taggable` 会自动启用以实现精准标签失效。 | 需要将文件管理器缓存隔离到独立 Redis 缓存池时。 |
| `cache.ttl` | `3600` | 缓存有效时长（单位：秒，默认 1 小时）。通过界面执行的写操作（上传、删除、移动、重命名）会自动失效受影响目录。 | 若文件经常通过 FTP 或云服务商控制台在外部直接改动，建议调低至 `60`–`300`。 |
| `metadata.enabled` | `true` | 存储文件夹颜色/图标/置顶状态以及文件 SEO 元数据（`alt`、`title`、`caption`、`tags`、`focalPoint`）。 | 仅需最简文件选择、不需要编辑元数据时设为 `false`。 |
| `metadata.driver` | `'json_file'` | 元数据存储驱动：`'json_file'`（单机本地文件）、`'doctrine'`（内置 DBAL 数据库存储，适用于多实例 / 负载均衡环境，支持 MySQL、MariaDB、PostgreSQL、SQLite）或 `'none'`。 | 只要应用运行多个副本（Kubernetes、负载均衡、Docker Swarm），务必切换为 `'doctrine'`——参见[第 13 章](13-doctrine-integration.md)。 |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | `json_file` 驱动的数据文件路径。同目录下还会生成回收站伴生文件（`trash-meta.json` 或 `<name>.trash-meta.json`），以兼容限制 MIME 类型的云存储桶。 | 需要移动到共享 NFS/挂载卷路径时。 |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | 当 `driver: 'doctrine'` 时的 DBAL 连接名、EntityManager 名称、表名（须符合 `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`）以及是否自动建表。 | 生产环境使用 Doctrine Migrations 管理表结构时，请将 `auto_create_table` 设为 `false`（参见[第 13 章](13-doctrine-integration.md)）。 |
| `audit_log.enabled` | `false` | 将全部 9 种文件/文件夹写操作（上传、删除、移动、复制、重命名、创建副本、新建文件夹、还原回收站、清空回收站）以结构化 PSR-3 日志记录（含 `user`、`ip`、`path`、`volume`）。 | 需要满足审计合规要求、追踪*“谁在何时删除了什么文件”*时设为 `true`。 |
| `audit_log.level` | `'info'` | 审计日志的 PSR-3 日志级别（`debug`、`info`、`notice`、`warning`）。 | 按需适配你的 Monolog 通道分流与过滤规则。 |
| `ai.default_model` | `'gpt-4o-mini'` | 安装可选包 `symfony/ai-bundle` 后默认使用的视觉/文本模型（参见[第 09 章](09-symfony-ai-integration.md)）。 | 需要切换为 Gemini、Claude 或本地 Ollama 模型时。 |
| `ai.platform_service` | `null` | 自定义 Symfony AI `PlatformInterface` 服务 ID（`null` 表示使用默认自动注册的平台）。 | 当应用内配置了多个 Symfony AI 平台时。 |

---

## 2. `volumes` — 存储卷（本地与云端）

单个 Profile 可以同时挂载多个存储卷（例如同时挂载本地磁盘和 Supabase/R2/S3）。用户甚至可以直接将文件从本地文件夹拖放到云存储桶中。

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: '本地磁盘'
            icon: 'disk'              # 可选（留空时根据 driver/endpoint 自动识别）
            group: 'local'            # 可选（null 时 LocalFileSystem -> 'local'，S3/Flysystem -> 'network'）
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # 可选的单卷缓存 TTL（0 = 禁用缓存）

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # 也会根据 endpoint 自动识别
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # 云端控制台的外部修改将在 60 秒内同步显示
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| 参数 | 默认值 | 作用说明 | 何时调整 |
| :--- | :--- | :--- | :--- |
| `label` | 存储卷键名 | 在左侧边栏、面包屑导航和存储卷切换器中展示的友好名称。 | 设置易读的展示名称（例如 `'商品媒体 CDN'`）。 |
| `icon` | `null` *（自动）* | 侧边栏 SVG 图标。为 `null` 时自动根据 `driver` 和 `endpoint` 推断（`disk`、`aws_s3`、`cloudflare_r2`、`supabase`、`backblaze_b2`、`minio`、`gcs`、`sftp`、`cloud`）。 | 需要指向自定义图标集中的 `<dir>/ui/my_icon.svg` 时。 |
| `group` | `null` *（自动）* | 侧边栏分组。为 `null` 时 `LocalFileSystem` 默认归入 `'local'`，`AwsS3` / `FlysystemService` 默认归入 `'network'`。 | 例如将 NFS 挂载归入 `'network'`，或将某个云盘归入 `'local'` 时。 |
| `driver` | `'LocalFileSystem'` | 存储后端驱动：本地文件系统（`LocalFileSystem`）、S3 兼容对象存储（`AwsS3`）或已注册的 Flysystem 服务（`FlysystemService`）。 | 对接 S3/R2/Supabase/B2/MinIO/GCS 时用 `'AwsS3'`；对接 SFTP/Azure/Dropbox 时用 `'FlysystemService'`。 |
| `path` | `null` | `LocalFileSystem` 的物理目录路径（支持 `{user_id}`、`{Y}`、`{m}`、`{d}` 占位符）。 | 指定自定义根目录或按用户隔离路径（如 `.../users/{user_id}`）。 |
| `url_prefix` | `null` | 浏览器访问公开文件时使用的公共 URL 前缀（如 `/uploads` 或 `https://cdn.example.com`）。当 `private: true` 时该项会被忽略。 | 通过 CDN 或公开 S3/R2/Supabase 存储桶直接对外提供资源时。 |
| `private` | `false` | 设为 `true` 时，绝不暴露直接公开 URL——所有下载、在线预览与缩略图均经由 Symfony Voter 权限校验后通过 `DownloadController` 流式输出。 | 存放发票、证件、合同或私有客户文件时，**务必设为 `true`**。 |
| `cache_ttl` | `null` *（继承）* | 仅作用于当前存储卷的缓存 TTL（秒）。`0` 表示禁用此卷缓存；`null` 表示继承 Profile/全局配置。 | 保持本地磁盘缓存 `3600` 秒的同时，将云存储桶限制为 `60` 秒或 `0`。 |
| `driver_options` | `[]` | **AwsS3：** `bucket`、`region`、`endpoint`、`key`、`secret`、`token` *（临时 STS 会话令牌）*、`version` *（默认 `'latest'`）*、`prefix`、`use_path_style_endpoint`、`acl`、`disable_acl` *（自定义 endpoint 自动为 `true`；标准 AWS S3 默认为 `false`——若启用 Bucket owner enforced 请显式设为 `true`）*、`retain_visibility`、`request_checksum_calculation`、`response_checksum_validation`、`ca_bundle`。**FlysystemService：** `service`。 | 各云服务商详细配置请参阅[第 07 章](07-cloud-storage-and-dynamic-config.md)。 |

---

## 3. `profiles` — Profile 参数字典

### 3.1. 基础存储、过滤与配额
| 参数 | 默认值 | 作用说明 | 何时调整 |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *（继承 defaults）* | 覆写当前 Profile 的界面与错误提示语言（`'zh_CN'`、`'en'`、`'tr'`、`'fr'`、`'de'`、`'ar'` 等；`null` 依次回退 `defaults.locale` → Symfony 请求区域设置 → `'en'`）。 | 同一个应用内不同后台模块需要显示不同语言时。 |
| `volumes` | `[]` | 当前 Profile 可访问的存储卷键名列表（按顺序展示，如 `['local_uploads', 'supabase_s3']`）。默认打开第一个存储卷。 | 挂载一个或多个本地/云端存储卷。 |
| `ignore_dot_files` | `true` | 在列表中隐藏以点号开头的文件/目录（`.env`、`.htaccess`、`.DS_Store`、`.trash`），并阻止对其直接访问。 | 出于安全考虑，建议始终保持 `true`。 |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | 隐藏并阻止直接访问匹配正则表达式或通配符规则的文件或文件夹。 | 需要屏蔽内部目录（如 `/^cache$/i` 或 `/^backups$/i`）时。 |
| `sort_by` | `'name'` | 默认排序字段：`'name'`、`'date'`、`'size'` 或 `'type'`。 | 使用 `'date'` + `sort_direction: 'desc'` 可让最新上传的文件排在最前。 |
| `sort_direction` | `'asc'` | 排序方向：`'asc'`（升序 A→Z / 由小到大）或 `'desc'`（降序 Z→A / 最新优先）。 | 媒体库和内容编辑后台推荐使用 `'desc'`。 |
| `folders_first` | `true` | 始终将文件夹排在文件之前。 | 若希望按纯字母或纯时间平铺排序，设为 `false`。 |
| `storage_quota` | `null` | 当前 Profile 允许使用的最大总存储空间（如 `'500M'`、`'2G'`）。达到上限后服务端将拒绝上传，侧边栏也会显示存储用量进度条。 | 多租户 SaaS 或按用户（`{user_id}`）分配配额的必备选项。 |
| `upload_subfolder` | `null` | 上传文件时在当前目录下自动归档的动态子文件夹模板（如 `'{Y}/{m}'`）。 | 自动按年月（如 `2026/11`）整理上传文件。 |

### 3.2. 上传、分片上传（`chunk_upload`）与重复文件检测（`duplicate_detection`）
| 参数 | 默认值 | 作用说明 | 何时调整 |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *（允许所有安全类型）* | 允许上传的 MIME 类型白名单（如 `['image/jpeg', 'image/png', 'image/webp']`）。留空时允许除 `upload_deny` 之外的所有安全类型。 | 仅限图片或仅限 PDF 的表单场景务必显式配置白名单。 |
| `upload_deny` | **47 种高危 MIME 类型** | 严格禁止上传的 MIME 类型（PHP、HTML、非 SVG 的 XML、JS、Shell、EXE、Java、CGI 等——服务端通过魔数强校验）。 | 如业务安全策略有额外要求，可追加禁止类型。 |
| `upload_max_size` | `'10M'` | 单个上传文件**合并后的最大体积**（如 `'200M'`、`'1G'`）。开启分片上传后，即使 PHP 限制为 `2M`，也可平稳上传远超限制的大文件。 | 视频/PDF 画册类 Profile 可调至 `'200M'`–`'500M'`；头像类可限制为 `'2M'`。 |
| `overwrite_policy` | `'rename'` | 同名文件冲突时的处理策略：`'rename'`（自动重命名为 `photo-1.jpg`）、`'overwrite'`（覆盖）或 `'reject'`（拒绝）。 | 建议保持 `'rename'`，防止误覆盖已有资产。 |
| `chunk_upload.enabled` | `true` | 将大文件切分为小分片上传，支持暂停 / 继续 / 二次确认取消，并在网络波动时自动重试。 | 几乎无需关闭，仅在严格只传极小文件（< 1 MB）时考虑关闭。 |
| `chunk_upload.chunk_size` | `'2M'` | 目标分片大小。会自动钳制为 PHP `upload_max_filesize` 与 `post_max_size` 的 **80%**（例如 PHP 为 2M 时自动调整为 1.60 MB）。 | 当服务器 PHP 限制较高（如 32M+）时，调大至 `'5M'` 可提升大文件上传速度。 |
| `chunk_upload.parallel_uploads` | `3` | 最大并发上传文件数（`1`–`10`）。 | 低配服务器建议设为 `2`，高速云存储/CDN 环境可设为 `4`–`5`。 |
| `chunk_upload.max_retries` | `3` | 单个分片遇到网络中断时的指数退避重试次数（`0`–`10`）。 | 移动端或弱网环境建议设置为 `3`–`5`。 |
| `duplicate_detection.enabled` | `false` | 基于 SHA-256 内容哈希检测重复文件上传。 | 设为 `true` 以防止编辑反复上传同一张商品照片。 |
| `duplicate_detection.policy` | `'warn'` | 检测到重复文件时的动作：`'warn'`（允许上传并提示）、`'reject'`（拦截上传）或 `'reuse'`（直接复用现有文件，不重复占用存储）。 | 电商与 CMS 站点推荐使用 `'reuse'`，可大幅节省存储空间。 |
| `duplicate_detection.max_scan_files` | `5000` | 重复检测索引最多扫描的文件数量上限（`100`–`50000`），防止超大型媒体库出现 CPU/I/O 抖动。 | 大型媒体库可调高至 `10000`–`25000`。 |

### 3.3. 自动文件名规范化（`naming`）
| 参数 | 默认值 | 作用说明 | 示例（原文件名 `"Product Photo 2026.JPG"`） |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | 将重音符号、Unicode 字符与空格音译转换为干净的 URL 友好 ASCII Slug。 | `product-photo-2026.jpg` |
| `naming.slugger_locale` | `null` | 音译语言环境（`null` 继承 Profile 语言；例如 `'de'` 转换 `ä/ö/ü/ß`，`'tr'` 转换 `İ/ı/ş/ğ/ç/ö/ü`）。 | 设为内容对应的主要语言（如 `'zh_CN'`、`'de'`、`'tr'`、`'fr'`）。 |
| `naming.transformer` | `'none'` | 大小写/命名风格转换：`'none'`、`'lowercase'`、`'uppercase'`、`'camel'`、`'snake'`、`'cameltitle'`、`'title'`、`'titleall'` 或 `'folder'`（以所在父文件夹名自动命名文件！）。 | `'snake'` → `product_photo_2026.jpg`，`'folder'` → `tumblers-1.jpg` |
| `naming.prepend` / `append` | `''` | 在文件名前或扩展名前追加静态文本或动态占位符（`{Y}`、`{m}`、`{d}`、`{uniqid}`）。 | `prepend: '{Y}{m}_'` → `202611_product.jpg` |
| `naming.apply_on_rename` | `true` | 用户在界面中重命名文件时同样应用 `slugger` 与 `transformer` 规则。 | 保持 `true`，确保存量重命名后文件名依然规范。 |
| `naming.apply_affixes_on_rename` | `false` | 防止每次重命名时重复叠加 `prepend`/`append` 前后缀。 | 建议保持 `false`。 |
| `naming.allow_extension_change` | `false` | 禁止用户在重命名时修改文件扩展名（如 `.jpg` → `.php` 或 `.png`）。 | 出于安全考虑，保持 `false`。 |

### 3.4. 上传时图片优化（`image_optimization`）与图片限制（`image_constraints`）
- **`image_optimization`：** 在上传至服务端时自动矫正方向、清除 EXIF 元数据、等比缩放并压缩大尺寸照片。
- **`image_constraints`：** **在浏览器端 0ms 瞬时校验并在服务端二次复核**，拒绝不符合要求的图片（例如拦截模糊的低分辨率图片或非正方形商品图）。

| 参数 | 默认值 | 作用说明 | 适用场景 |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | 开启上传时自动图片优化（按 Imagick → GD 顺序选择驱动；两者均缺失时静默跳过）。 | 任何允许用户上传手机原图的 Profile 都建议开启（`true`）。 |
| `image_optimization.auto_orient` | `true` | 读取 EXIF 方向标签，自动扶正手机拍摄的横竖倒置照片。 | 移动端上传场景务必保持 `true`。 |
| `image_optimization.strip_exif` | `true` | 清除 GPS 定位坐标与相机 EXIF 元数据（保护隐私合规并减小体积）。 | 所有对外公开的图片均建议保持 `true`。 |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | 对超过上限的图片按原宽高比等比缩小（最小值 `>= 100`）。 | Web 常规图片推荐设置为 `1920`×`1920`。 |
| `image_optimization.convert_to_webp` | `false` | 自动将上传的 JPEG/PNG 图片转换为 WebP（体积通常减少 30%–60%）。 | 注重前端性能的现代站点建议设为 `true`。 |
| `image_optimization.quality` | `85` | 输出压缩质量（`10`–`100`）。 | `80`–`85` 能在画质与体积间取得最佳平衡。 |
| `image_constraints.min_width` / `min_height` | `null` | 允许上传的最小像素尺寸。 | 防止上传模糊小图（如博客封面至少 `1200`×`630`）。 |
| `image_constraints.max_width` / `max_height` | `null` | 允许上传的最大像素尺寸（若开启了 `image_optimization`，将在缩放*之后*执行校验）。 | 严格限定 Banner 或印刷模板尺寸。 |
| `image_constraints.aspect_ratio` | `null` | 要求的宽高比：`'1:1'`（正方形）、`'16:9'`（宽屏横幅）、`'4:3'`、`'3:4'`、`'1.91:1'` 等。 | 电商正方形商品卡片（`'1:1'`）或视频/文章头图（`'16:9'`）。 |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | 宽高比允许误差范围（`0.0`–`0.5`；`0.05` 表示允许 5% 容差，因此 `1920×1080` 与 `1280×722` 均可顺利通过）。 | `0.05` 适用于绝大多数业务场景。 |

### 3.5. 缩略图、回收站（`trash`）、ZIP（`zip`）与签名分享链接（`share`）
| 参数 | 默认值 | 作用说明 |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | 为图片生成本地缓存缩略图：`'auto'`（优先 Imagick，其次 GD）、`'imagick'`、`'gd'`、`'liip_imagine'` 或 `'none'`。云存储图片仅需拉取一次即可缓存在本地。 |
| `thumbnail.on_missing_driver` | `'fallback_original'` | 当服务器既无 GD 也无 Imagick 时的行为：`'fallback_original'`（显示原图）、`'icon'`（显示文件图标）或 `'exception'`（抛出异常）。 |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | 缩略图尺寸（`>= 32`）、输出格式（`webp`、`jpeg`、`png`）与适配方式（`contain` = 完整显示原图，`cover` = 居中裁剪填充）。使用 `ui.item_size: 'xl'` 时建议调大至 `480`×`360`。 |
| `thumbnail.lazy_load` | `true` | 为网格卡片缩略图添加原生 `loading="lazy"`，提升大目录首屏渲染速度。 |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | 缩略图本地缓存存放目录。 |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | 删除文件时先移入回收站（软删除）而非立即销毁。在限制 MIME 类型的云存储桶上会自动降级为本地 `trash-meta.json` 伴生文件。 |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | 启用多文件/文件夹一键打包下载 `.zip`（需启用 PHP `ext-zip` 扩展）。 |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | 具备 7 层防 Zip-Slip 与 ZIP 炸弹保护的安全解压功能。当 `allow_extract: false` 时始终禁用；开启后 `permissions.extract_zip` 会自动默认为 `true`（可按需收敛为 `'ROLE_ADMIN'` 或 `false`）。 |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | 基于 Symfony `UriSigner` 生成带有效期的 HMAC 签名分享链接。将 `enabled` 设为 `false` 会立即令此前所有已签发的分享链接失效。 |

### 3.6. 用户界面（`ui`）— 布局、尺寸与工具栏开关
| 参数 | 默认值 | 作用说明 | 何时调整 |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *（继承 defaults）* | Profile 专属主题（例如后台使用 `'bootstrap5'`，客户门户使用 `'shadcn'`）。 | 当同一应用的不同页面采用不同视觉风格时。 |
| `ui.color_scheme` | `null` *（继承 defaults）* | Profile 专属颜色模式（`auto`、`light`、`dark`）。 | 锁定某个工作区为纯深色（`'dark'`）时。 |
| `ui.icon_set` | `'classic'` | 文件/文件夹图标集：`classic`、`windows11`、`macos`、`linux`、`minimal`、`vscode`，或自定义 SVG 图标目录路径（`'%kernel.project_dir%/assets/fm-icons'`）。 | 选择 `'windows11'` 或 `'macos'` 获得桌面级观感，或接入品牌定制图标。 |
| `ui.z_index` | `null` *（继承 defaults）* | Profile 专属遮罩层 z-index 覆写值。 | 嵌入到具有高 `z-index` 编辑器或抽屉的页面时。 |
| `ui.mobile_dialog_mode` | `'auto'` | 移动端小视口（`<= 767.98px`）下的弹窗呈现形式：`'auto'`（模态框与左右抽屉转为全屏，上下抽屉转为圆角原生面板）、`'fullscreen'`（全部全屏）或 `'sheet'`（全部以原生底部抽屉打开）。 | 统一强制移动端选择器使用 `'sheet'` 或 `'fullscreen'` 时。 |
| `ui.default_view` | `'grid'` | 默认视图模式：`'grid'`（缩略图网格）或 `'list'`（可排序列表）。 | PDF/文档档案库推荐 `'list'`，视觉图库推荐 `'grid'`。 |
| `ui.item_size` | `'md'` | 网格卡片宽度与列表缩略图尺寸：`'xs'`（`108px`）、`'sm'`（`132px`）、`'md'`（`158px`）、`'lg'`（`204px`）、`'xl'`（`260px`）或精确像素整数（`96`–`320`）。`<= 132px` 时自动切换为紧凑内边距。 | 视觉审图推荐 `'lg'` / `'xl'`，紧凑弹窗选择器推荐 `'sm'` / `'xs'`。 |
| `ui.card_aspect_ratio` | `'4/3'` | 卡片预览框宽高比：`'1/1'`（正方形）、`'4/3'`（标准）、`'16/9'`（宽屏/视频）或 `'3/4'`（竖版海报/画册）。 | 贴合主要素材比例（商品图用 `'1/1'`，视频/横幅用 `'16/9'`）。 |
| `ui.folder_style` | `'tile'` | 网格视图下的文件夹样式：`'tile'`（Google Drive 风格的紧凑横向磁贴，置顶于文件上方）或 `'card'`（Windows/Finder 风格的全尺寸文件夹卡片，与文件卡片等大）。 | 需要文件夹在视觉上更醒目时切换为 `'card'`。 |
| `ui.show_size_slider` | `true` | 在设置菜单中显示实时项目大小滑块（`96px`–`320px`）与文件夹样式切换器。 | 希望锁定布局密度不让用户调整时设为 `false`。 |
| `ui.volume_order` | `'local_first'` | 侧边栏与切换器中的存储卷排序：`'local_first'`（本地优先）、`'network_first'`（云端优先）或 `'configured'`（严格按 YAML `volumes: [...]` 声明顺序，不显示分组分隔线）。 | 云存储为主的工作流推荐使用 `'network_first'` 或 `'configured'`。 |
| `ui.show_folder_tree` | `true` | 是否显示左侧文件夹树边栏。 | 精简单目录 TinyMCE 或头像选择器可设为 `false`。 |
| `ui.tree_lines` | `true` | 在文件夹树中渲染层级连接线。 | 追求极简侧边栏风格时可设为 `false`。 |
| `ui.show_folder_item_count` | `true` | 在文件夹旁显示包含条目数徽章（如 `12`）。严格遵循权限校验，绝不会通过数量泄露无权访问的子目录。 | 建议保持 `true`，便于快速掌握目录内容规模。 |
| `ui.folder_count_mode` | `'recursive_files'` | 文件夹徽章计数方式：`'recursive_files'`（递归统计所有子目录文件）、`'files'`（仅统计当前层级直接文件）或 `'items'`（直接文件 + 直接子文件夹）。 | 仅关注直接文件数时选择 `'files'`。 |
| `ui.inspector_mode` | `'on_select'` | 右侧预览与详情面板（Inspector）：`'on_select'`（选中项目时展开）、`'always'`（常驻显示）或 `'hidden'`（隐藏）。 | 窄弹窗选择器中推荐设为 `'hidden'`。 |
| `ui.folder_open_on` | `'dblclick'` | 打开文件夹的交互手势：`'dblclick'`（单击选中、双击进入——桌面端习惯）或 `'click'`（单击直接进入——Web/移动端习惯）。 | 希望后台用户单击即快速浏览目录时选择 `'click'`。 |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | 默认每页显示条目数（`6`–`200`）以及可供用户切换的分页选项。 | 大规模目录库可调高为 `48` 与 `[24, 48, 96, 150]`。 |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | 显示侧边栏存储空间进度条与可点击的“存储用量与配额详情”弹窗。（即使在 UI 中隐藏，服务端的 `storage_quota` 强校验依然生效。） | 不希望在界面展示配额条时将 `show_quota` 设为 `false`。 |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | 控制 PDF 灯箱内联预览与 HTML5 视频播放（UI 与服务端 `DownloadController` 双重生效）。 | 设为 `false` 可强制浏览器直接下载而非内联预览。 |
| `ui.remember_last_dir` | `true` | 在 `localStorage` 中记住用户上次访问的**存储卷 + 目录**（若该目录已被删除，会自动回退至父目录或根目录）。 | 如果希望每次打开都固定从根目录开始，设为 `false`。 |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | 删除或拖放移动文件前弹出二次确认对话框。 | 高频整理文件场景可将 `confirm_move` 设为 `false` 以提速。 |
| `ui.double_click_to_select` | `true` | 在 Modal/OffCanvas 选择器模式下，双击文件即可直接确认选中并关闭弹窗。 | 若希望双击文件只打开预览而非选中，设为 `false`。 |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | 启用右键上下文菜单，以及右键空白区域时的 `[ ← \| → \| ↑ \| ↻ ]` 快捷导航栏。 | 面向受限终端用户的封闭页面可设为 `false`。 |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | 允许拖放项目至文件夹进行移动，以及从操作系统拖入文件/文件夹进行上传。 | 只读素材库建议设为 `false`。 |
| `ui.keyboard_shortcuts` | `true` | 启用键盘快捷键（`Ctrl+A`、`Ctrl+C/X/V`、`F2`、`Delete`、`Space`、`/`、`R`、`F`、`?` 等）。 | 若宿主页面绑定了冲突的全局快捷键，可设为 `false`。 |
| `ui.show_*` 开关 | *（除 `show_perf_badge: false` 外大多为 `true`）* | `show_perf_badge`、`show_nav_buttons`、`show_history_buttons`、`show_up_button`、`show_refresh_button`、`show_breadcrumbs`、`show_search`、`show_type_filter`、`show_view_toggle`、`show_pagination`、`show_per_page_selector`、`show_sort`、`show_fullscreen_button`、`show_settings_button`、`show_footer`、`show_context_nav`。 | 按需开关单个工具栏控件以精简界面。*（注：设置菜单中的“关于”与“GitHub”为内置归属条目，不可通过 YAML 关闭。）* |

### 3.7. 单 Profile 缓存（`cache`）、安全（`security`）、权限（`permissions`）、AI 与引用保护

#### 缓存（`cache`）
| 参数 | 默认值 | 说明 |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *（继承 defaults）* | 启用或禁用当前 Profile 的缓存。若 Profile 将 `cache.enabled` 设为 `false`，即便存储卷配置了 `volume.cache_ttl` 也不会重新强制开启缓存。 |
| `cache.ttl` | `null` *（继承 defaults）* | TTL 生效优先级：**`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**。 |

#### 安全选项（`security`）
| 参数 | 默认值 | 说明 |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | 启用 `FileManagerVoter` 及自定义 Voter 的 RBAC 权限校验。生产环境务必始终保持 `true`。 |
| `security.csrf_protection` | `true` | 要求所有 `POST` 请求必须携带有效的 `X-LiveUX-FM-CSRF` 请求头。 |
| `security.sanitize_svg` | `true` | 使用严格的 55 标签白名单（`SvgSanitizer`）净化上传与编辑后的 SVG 文件。 |
| `security.allow_remote_url_import` | `false` | 在上传弹窗中开启**“从 URL 导入”**选项卡（内置 `RemoteUrlImporter` 防 SSRF 与 DNS 重绑定攻击）。 |
| `security.audit_log` | `null` *（继承 defaults）* | 为当前 Profile 单独覆写 PSR-3 审计日志开关（`true` / `false` / `null`）。 |

#### 17 项细粒度权限（`permissions`）
> [!WARNING]
> **重要：`permissions` 下的 `true` 代表什么？**  
> 将某项权限设为 **`true`** **绝不**代表*“公开 / 匿名访问”*！`FileManagerVoter` 仅对**已登录认证的用户（`$token->getUser() !== null`）**放行 `true`，所有匿名请求一律拒绝。  
> 此外，**`permissions.view`** 是全局总闸：只要用户缺少 `view` 权限，其他所有操作（`upload`、`delete`、`download` 等）都会自动被拒绝。

每项权限支持 4 种配置格式：
- `true` → 任意已认证登录用户
- `false` → 任何人不可用（彻底禁用该功能）
- `'ROLE_ADMIN'` → 仅拥有指定角色的用户
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → 拥有列表中任意一个角色的用户

| 权限键 | 默认值 | 受控操作 |
| :--- | :--- | :--- |
| `view` | `true` | 目录列表、文件夹树、在线预览，以及**所有其他权限的前置条件**。 |
| `upload` | `true` | 普通/分片上传、保存图片编辑器结果、远程 URL 导入。 |
| `create_folder` | `true` | 新建文件夹。 |
| `rename` | `true` | 重命名文件与文件夹。 |
| `clone` | `true` | 创建文件或文件夹副本。 |
| `move` | `true` | 通过拖放或剪切/粘贴移动项目。 |
| `copy` | `true` | 通过复制/粘贴或“移动/复制到文件夹”弹窗复制项目。 |
| `delete` | `true` | 删除文件/文件夹（移至回收站或永久删除）。 |
| `trash_restore` | `true` | 从回收站还原项目。 |
| `trash_purge` | `true` | 从回收站永久删除单项或清空回收站。 |
| `download` | `true` | 下载单个文件、流式播放 PDF/视频及复制文件链接。 |
| `download_zip` | `true` | 将选中的多个项目打包下载为 `.zip` 压缩包。 |
| `extract_zip` | `null` *（自动）* | 当 `zip.allow_extract: false` 时强制为 `false`；开启 `zip.allow_extract: true` 后自动变为 `true`（可按需收紧为 `'ROLE_ADMIN'`）。 |
| `share` | `true` | 生成带有效期的 HMAC 签名分享链接。 |
| `clear_cache` | `true` | 在设置菜单中清除目录/配置/缩略图缓存以及强制触发 `?refresh=1` / `?fallback=1`。 |
| `edit_metadata` | `true` | 编辑文件的 `alt`/`title`/`caption`/`tags`/焦点位置，以及自定义文件夹颜色/图标/固定到快速访问。 |
| `ai_enrich` | `true` | 调用 **✨ AI 智能建议**（需同时满足 `view` + `edit_metadata` + `ai_enrich`）。 |

#### AI 智能增强（`ai`）与资源引用保护（`usage_guard`）
| 参数 | 默认值 | 说明 |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | 启用 AI 元数据建议；设置 `locale` 可控制 AI 生成结果的语言（如 `'zh_CN'`、`'en'`、`'de'`）。 |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | 最多生成的标签数量（`1`–`20`）以及发送给 AI 前的图片等比缩放上限（`256`–`2048` px，节省视觉模型 Token 消耗）。 |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | 默认禁止将 `private: true` 私有存储卷上的文件发送至外部 AI API；可选在上传后自动触发 AI 增强（参见[第 09 章](09-symfony-ai-integration.md)）。 |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | 在执行 **`delete`、`rename`、`move` 与 `overwrite`** 操作时，若资源在数据库中仍被引用，则发出警告或拦截操作（参见[第 10 章](10-asset-usage-guard.md)）。 |

---

## 4. 5 套生产级实战配方（开箱即用）

以下 5 套配置覆盖了最常见的真实业务场景，可直接复制粘贴到项目中使用：

### 配方 1：电商商品图库（正方形卡片 + WebP 自动转换 + 重复复用）
> **目标：** 仅接收清晰的 `1:1` 正方形商品主图；自动缩放手机原图并转为 WebP；遇到重复上传直接复用已有文件；严禁误删仍在架商品引用的图片。

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'en'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # 204px 大号商品卡片
                card_aspect_ratio: '1/1' # 正方形展示比例
                folder_style: 'card'     # 全尺寸分类文件夹卡片
                show_folder_item_count: true
```

### 配方 2：博客 / 资讯封面图（`16:9` 宽屏横幅 + AI 自动生成 Alt 文本）
> **目标：** 仅接收高清 `16:9` 宽屏封面图（至少 `1200×630`px），按 `2026/11` 年月目录自动归档上传，并通过 AI 自动生成 SEO 替代文本与标签。

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # 最新封面优先展示
            ai:
                enabled: true
                locale: 'zh_CN'
                max_tags: 6
            ui:
                item_size: 'xl'           # 260px 宽屏封面大图预览
                card_aspect_ratio: '16/9' # 宽屏横幅宽高比
```

### 配方 3：客户文档与 PDF 保险库（私有 Supabase/S3 + 按用户隔离）
> **目标：** 在私有 S3 兼容存储桶中将每位客户隔离到各自的 PDF 发票目录，禁止外部直接访问 URL，每 60 秒同步云端外部变更，并记录全部审计日志。

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: '客户 PDF 保险库'
            group: 'network'
            driver: 'AwsS3'
            private: true          # 禁止未经认证的直接 URL 访问
            cache_ttl: 60          # 云端外部修改在 60 秒内生效
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # 每位用户仅能看到自己的专属目录
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # 适合 PDF 文档的可排序列表视图
                item_size: 'sm'          # 紧凑列表行
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### 配方 4：轻量级富文本编辑器选择器（TinyMCE / CKEditor / Tiptap）
> **目标：** 轻快、无干扰的弹窗图片选择器，双击图片即可立刻插入编辑器。

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### 配方 5：只读合作伙伴 / 媒体资料库（Press Kit）
> **目标：** 允许合作伙伴浏览品牌 Logo 与画册、下载单文件或打包下载 ZIP、生成签名分享链接，同时严格禁止任何上传、编辑与删除操作。

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 天
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. 动态路径占位符（`DynamicPathResolver`）

你可以在本地存储卷的 `path`、`url_prefix`、云存储卷的 `driver_options.prefix` 以及 Profile 的 `upload_subfolder` 中使用动态占位符：

| 占位符 | 说明 | 输出示例 |
| :--- | :--- | :--- |
| `{user_id}` | 当前登录 Symfony 用户的防冲突、文件系统安全标识符（未登录时为 `guest`）。 | `42` 或 `alice_a--h-9f1c2e4b...` |
| `{Y}` | 4 位年份 | `2026` |
| `{m}` | 2 位月份 | `11` |
| `{d}` | 2 位日期 | `25` |

```yaml
volumes:
    user_space:
        label: '我的个人空间'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **防冲突多租户 `{user_id}` 隔离：**  
> `DynamicPathResolver` 会为邮箱地址（如 `alice.a@example.com` 与 `alice_a@example.com`）、大小写变体（`Alice` 与 `alice`）、Unicode 用户名（中文、阿拉伯语、土耳其语等）、保留字（`guest`、`user` 及以 `--h-` 结尾的标识）以及 Windows 设备名（`con`、`nul`、`com1`..`9`、`lpt1`..`9`）生成**互不相交的 SHA-256 后缀目录键 `<slug>--h-<16-hex>`**。任意两个不同用户绝不会碰撞到同一个文件夹。
>
> **CLI 命令行为：** 控制台命令（`doctor --check-remote`、`stats`、`trash:purge`、`thumbs:warmup` 等）会自动跳过路径中包含 `{user_id}` 的存储卷，避免意外创建多余的 `guest/` 目录。

---

## 6. 完整 YAML Schema 参考（全部有效配置项）

下面的 YAML 清单完整列出了 `Configuration.php` 中定义的**每一个有效配置键**、其默认值与行内注释。实际项目中只需声明你需要修改的键，其余均会自动继承默认值。

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) 全局默认值（DEFAULTS）— 除非被单个 Profile 覆写，否则全局继承
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # 简写单盘 Profile 的默认根目录
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <自定义>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = Symfony 请求/翻译器区域设置（内置 12 种语言，兜底回退 'en'）| 'zh_CN' | 'en' | 'tr' | 'fr' | ...
        z_index: 1050                                      # 弹窗、浮层与菜单的基础 z-index（>= 1）
        auto_inject_dialog_host: true                      # 自动向完整 HTML 响应注入 Modal/OffCanvas 所需的 DialogHost
        dialog_host_profile: 'default'                     # 自动注入的 DialogHost 默认使用的 Profile

        audit_log:
            enabled: false                                 # 将全部 9 种文件/文件夹写操作写入 PSR-3 审计日志通道
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Profile 未单独覆写时使用的默认 AI 模型
            platform_service: null                         # 自定义 Symfony AI PlatformInterface 服务 ID（null = 自动）

        cache:
            enabled: true                                  # 全局目录/文件夹树 PSR-6 缓存总开关
            pool: 'cache.app'                              # Symfony PSR-6 缓存池服务 ID
            ttl: 3600                                      # 全局缓存 TTL（秒，0 = 禁用）

        metadata:
            enabled: true                                  # 文件夹颜色/图标/置顶与文件 alt/title/caption/tags/focalPoint
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # json_file 驱动的数据文件路径
            connection: null                               # doctrine 驱动的可选 DBAL 连接名（null = 使用 entity_manager 连接）
            entity_manager: 'default'                      # doctrine 驱动的 EntityManager / 连接名
            table: 'liveux_fm_metadata'                    # doctrine 驱动的 DBAL 数据表名（参见第 13 章）
            auto_create_table: true                        # 表不存在时自动建表（生产环境使用迁移时设为 false）

    # =========================================================================
    # 2) 存储卷（VOLUMES）— 本地与云端文件系统定义
    # =========================================================================
    volumes:
        local_uploads:
            label: '本地存储'                              # 侧边栏与面包屑中展示的名称
            icon: null                                     # null = 自动 | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = 自动（LocalFileSystem->local，AwsS3/Flysystem->network）| local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # 物理路径（支持 {user_id}、{Y}、{m}、{d}）
            url_prefix: '/uploads'                         # 浏览器公共访问 URL 前缀（private: true 时留 null）
            private: false                                 # true = 所有文件经 RBAC 校验由 DownloadController 流式输出
            cache_ttl: null                                # 单卷缓存 TTL（秒；null = 继承，0 = 禁用）
            driver_options: []                             # LocalFileSystem 留空即可

        cloud_s3_example:
            label: '云存储 S3 / R2 / Supabase'
            icon: 'supabase'                               # 对应 resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # 公开存储桶/CDN URL（private: true 时留 null）
            private: false
            cache_ttl: 60                                  # 每 60 秒刷新一次云端外部变更
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # 存储桶名称
                region: '%env(AWS_S3_REGION)%'             # 区域（Cloudflare R2 填 'auto'）
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # 非 AWS 服务商的自定义 S3 Endpoint URL
                prefix: 'media/{Y}'                        # 可选的桶内根前缀（支持动态占位符）
                use_path_style_endpoint: true              # Supabase / MinIO / R2 / B2 设为 true
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # 可选的 AWS STS 临时会话令牌
                version: 'latest'                          # AWS S3 API 版本（默认 'latest'）
                ca_bundle: null                            # 可选的自定义 CA 证书 .pem 路径（解决 Windows cURL error 60）
                disable_acl: true                          # 自定义 Endpoint 自动为 true；AWS S3 启用 Bucket owner enforced 时请显式设为 true
                retain_visibility: false                   # 自定义 Endpoint 自动为 false（避免发送 x-amz-acl 头）
                request_checksum_calculation: 'when_required'  # 兼容 AWS SDK >= 3.337（自定义 Endpoint 自动生效）
                response_checksum_validation: 'when_required'  # 兼容 AWS SDK >= 3.337（自定义 Endpoint 自动生效）

        custom_flysystem_example:
            label: 'SFTP 归档存储'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # League\Flysystem\FilesystemOperator 服务 ID

    # =========================================================================
    # 3) Profile 方案（PROFILES）— 按业务场景独立配置规则集
    # =========================================================================
    profiles:
        full_reference:
            # --- 挂载存储卷、语言与单盘简写选项 ---
            locale: null                                   # null = 继承 defaults.locale | 'zh_CN' | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # 留空时根据下方 driver/path 自动创建隐式存储卷
            driver: 'LocalFileSystem'                      # 简写驱动（仅当 volumes: [] 时生效）
            path: '%kernel.project_dir%/public/uploads'    # 简写路径（仅当 volumes: [] 时生效）
            url_prefix: '/uploads'                         # 简写 URL 前缀（仅当 volumes: [] 时生效）
            private: false                                 # 简写私有标记（仅当 volumes: [] 时生效）
            driver_options: []                             # 简写驱动选项（仅当 volumes: [] 时生效）

            # --- 列表展示、隐藏规则与默认排序 ---
            ignore_dot_files: true                         # 隐藏并阻止访问点文件（.env、.htaccess、.git）
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # 从列表隐藏并阻止直接访问的正则/通配符模式
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # 始终将文件夹排在文件前面

            # --- 上传、配额与同名冲突处理 ---
            upload_subfolder: null                         # 例如 '{Y}/{m}' — 自动上传到动态子文件夹
            upload_allow: []                               # 留空 = 允许所有安全类型；非空 = 严格 MIME 白名单
            upload_deny:                                   # 始终拒绝的危险 MIME 类型（省略时默认内置 47 种）
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # 单个文件合并后的最大体积（如 '50M'、'2G'）
            storage_quota: null                            # Profile 总磁盘配额（如 '500M'、'5G'；null = 无限制）
            overwrite_policy: 'rename'                     # rename（追加 -1）| overwrite | reject

            # --- 分片上传（突破 PHP upload_max_filesize 限制）---
            chunk_upload:
                enabled: true                              # 将大文件切分为可暂停/续传的小分片
                chunk_size: '2M'                           # 目标分片大小（自动钳制为 PHP 限制的 80%）
                parallel_uploads: 3                        # 最大并发上传数（1..10）
                max_retries: 3                             # 网络出错时单分片自动重试次数（0..10）

            # --- 重复文件检测（SHA-256）---
            duplicate_detection:
                enabled: false                             # 上传时检测内容完全相同的重复文件
                policy: 'warn'                             # warn | reject | reuse（复用已有文件）
                max_scan_files: 5000                       # 参与重复哈希索引的最大文件数（100..50000）

            # --- 自动文件名规范化 ---
            naming:
                slugger: true                              # 将 Unicode 文件名音译转换为干净的 ASCII Slug
                slugger_locale: null                       # null = 继承 Profile 语言（如 'zh_CN'、'de'、'tr'、'fr'）
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # 静态/动态前缀（如 'product-'）
                append: ''                                 # 扩展名前的静态/动态后缀（如 '-{Y}{m}{d}'）
                apply_on_rename: true                      # 重命名时同样应用 slugger/transformer 规则
                apply_affixes_on_rename: false             # 重命名时是否再次叠加 prepend/append 前后缀
                allow_extension_change: false              # 是否允许用户在重命名时更改扩展名（如 .jpg -> .php）

            # --- 上传时图片优化 ---
            image_optimization:
                enabled: false                             # 上传时自动扶正、清 EXIF、缩放与压缩
                auto_orient: true                          # 自动修正手机照片的 EXIF 旋转方向
                strip_exif: true                           # 剥离 GPS 与相机 EXIF 信息以保护隐私并减小体积
                max_width: 2400                            # 等比缩放最大宽度 px（>= 100）
                max_height: 2400                           # 等比缩放最大高度 px（>= 100）
                convert_to_webp: false                     # 自动将上传的 JPEG/PNG 转为 WebP
                quality: 85                                # 输出质量（10..100）

            # --- 图片分辨率与宽高比约束 ---
            image_constraints:
                min_width: null                            # 最小宽度 px（如 800）
                min_height: null                           # 最小高度 px（如 600）
                max_width: null                            # 最大宽度 px（在 image_optimization 缩放之后校验）
                max_height: null                           # 最大高度 px
                aspect_ratio: null                         # 要求宽高比（如 '1:1'、'16:9'、'4:3'、'3:4'）
                aspect_ratio_tolerance: 0.05               # 允许的宽高比误差（0.0..0.5；0.05 = 5%）

            # --- 缩略图引擎 ---
            thumbnail:
                enabled: true                              # 为图片卡片生成带缓存的预览缩略图
                driver: 'auto'                             # auto（Imagick->GD）| imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # 缩略图宽度 px（>= 32）
                height: 240                                # 缩略图高度 px（>= 32）
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # 压缩质量（10..100）
                fit: 'contain'                             # contain（完整适配）| cover（裁剪铺满）
                lazy_load: true                            # 为卡片缩略图添加 loading="lazy"
                cache_dir: '%kernel.project_dir%/public/media-cache' # 缩略图本地缓存目录

            # --- 回收站（软删除）---
            trash:
                enabled: true                              # 删除时移至回收站而非直接永久删除
                directory: '.trash'                        # 隐藏回收站目录名
                retention_days: 30                         # 供 file-manager:trash:purge 命令使用的保留天数
                max_size: '500M'                           # 回收站最大总容量

            # --- ZIP 打包下载与安全解压 ---
            zip:
                enabled: true                              # 启用多文件/文件夹打包下载 ZIP
                max_files: 100                             # 单次 ZIP 下载允许的最大文件数
                max_total_size: '250M'                     # 单次 ZIP 下载允许的最大总大小
                allow_extract: false                       # 启用在线解压 ZIP（默认关闭；具备 7 层安全防护）
                max_extract_files: 200                     # 单个压缩包最多解压文件数（1..2000）
                max_extract_size: '100M'                   # 解压后总大小上限（防 ZIP 炸弹）
                max_compression_ratio: 100                 # 允许的最大压缩比（1..1000 — 防 ZIP 炸弹）

            # --- 带有效期的签名分享链接（HMAC）---
            share:
                enabled: true                              # 允许生成 HMAC 签名分享链接
                default_ttl: 86400                         # 默认有效期（秒，86400 = 24 小时）
                max_ttl: 2592000                           # 可选最长有效期（秒，2592000 = 30 天）
                allow_inline_preview: true                 # 允许安全 MIME 类型在浏览器中内联预览

            # --- 可选 Symfony AI 智能增强 ---
            ai:
                enabled: false                             # 在元数据弹窗中显示“✨ AI 智能建议”
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = Profile/请求语言（如 'zh_CN' => 生成中文替代文本/标签）
                max_tags: 6                                # 建议标签数量上限（1..20）
                max_image_dimension: 1024                  # 发送给 AI 前的图片最大边长缩放限制（256..2048 px）
                block_private_volumes: true                # true = 禁止对 private: true 私有存储卷调用 AI（默认 true）
                auto_enrich_on_upload: false               # 上传完成后自动执行 AI 元数据增强

            # --- 资源引用保护（删除 / 重命名 / 移动 / 覆盖）---
            usage_guard:
                enabled: false                             # 变更资源前检查数据库引用情况
                policy: 'warn'                             # warn（展示引用并允许继续）| block（拦截操作）
                enforce_server_side: true                  # 在直接 API 调用层面同样强制校验
                max_references: 20                         # 弹窗中最多展示的引用条目数（1..200）

            # --- 单 Profile 缓存配置 ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | 秒数（0 = 禁用）

            # --- 安全选项 ---
            security:
                voter_enabled: true                        # 启用 FileManagerVoter RBAC 权限检查
                csrf_protection: true                      # 要求 POST 请求携带 X-LiveUX-FM-CSRF 请求头
                sanitize_svg: true                         # 净化上传/编辑后的 SVG 文件
                allow_remote_url_import: false             # 启用“从 URL 导入”选项卡（内置 SSRF 防护）
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 项细粒度 RBAC 权限（true = 已认证用户 | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']）---
            permissions:
                view: true                                 # 总权限：列表浏览以及所有其他操作的前提
                upload: true                               # 文件上传、图片编辑器保存与远程 URL 导入
                create_folder: true                        # 新建文件夹
                rename: true                               # 重命名文件与文件夹
                clone: true                                # 创建文件/文件夹副本
                move: true                                 # 移动项目（含拖放与剪切/粘贴）
                copy: true                                 # 复制项目（含复制/粘贴）
                delete: true                               # 删除项目（移至回收站或永久删除）
                trash_restore: true                        # 从回收站还原项目
                trash_purge: true                          # 永久删除回收站项目 / 清空回收站
                download: true                             # 单文件下载与复制链接
                download_zip: true                         # 多文件打包下载 ZIP
                extract_zip: false                         # 解压 ZIP 压缩包（同时需要 zip.allow_extract: true）
                share: true                                # 生成签名分享链接
                clear_cache: true                          # 从设置菜单清除缓存及强制 ?refresh=1
                edit_metadata: true                        # 编辑文件 alt/tags/焦点与文件夹颜色/图标/置顶
                ai_enrich: true                            # 调用 AI 元数据增强

            # --- 用户界面（UI）开关与尺寸配置 ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <自定义目录>
                z_index: null                              # null = defaults.z_index | 整数（>= 1）
                mobile_dialog_mode: 'auto'                 # 移动端（<= 767px）弹窗行为：auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px（<=132px 自动紧凑）
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile（紧凑磁贴）| card（全尺寸文件夹卡片）
                show_size_slider: true                     # 在设置菜单中显示实时大小滑块与文件夹样式切换
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # 显示左侧文件夹树边栏
                tree_lines: true                           # 在文件夹树中显示层级连接线
                show_folder_item_count: true               # 在文件夹上显示包含条目数徽章
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # 显示侧边栏存储配额条与顶部配额胶囊
                show_quota_details: true                   # 允许点击打开“存储用量与配额详情”弹窗
                per_page: 24                               # 默认每页条数（6..200）
                per_page_options: [24, 48, 96]             # 每页显示数量下拉选项
                show_perf_badge: false                     # 在工具栏显示 缓存命中 / 磁盘 耗时徽章
                show_nav_buttons: true                     # 后退 / 前进 / 上级 / 刷新 导航按钮组总开关
                show_history_buttons: true                 # 显示后退 / 前进历史按钮
                show_up_button: true                       # 显示返回上级文件夹按钮
                show_refresh_button: true                  # 显示刷新（?refresh=1）按钮
                show_breadcrumbs: true                     # 显示可点击/可拖放的面包屑导航栏
                show_search: true                          # 显示搜索框与深度搜索开关
                show_type_filter: true                     # 显示类型筛选（图片 / 视频 / 文件 / 文件夹）
                show_view_toggle: true                     # 显示网格 / 列表视图切换器
                show_pagination: true                      # 显示底部分页栏
                show_per_page_selector: true               # 显示每页显示数量下拉框
                show_sort: true                            # 显示排序下拉框与升降序切换
                show_fullscreen_button: true               # 显示全屏切换按钮
                show_settings_button: true                 # 显示设置（⚙）下拉菜单
                show_footer: true                          # 显示底部状态栏
                show_context_nav: true                     # 在空白处右键菜单顶部显示快捷导航栏
                allow_dropzone_upload: true                # 允许从系统拖入文件/文件夹到列表区域上传
                remember_last_dir: true                    # 记住上次访问的存储卷与目录（若已删除则自动自愈回退）
                confirm_delete: true                       # 删除前弹出确认对话框
                confirm_move: true                         # 移动/复制前弹出确认对话框
                double_click_to_select: true               # 在 Modal/OffCanvas 选择器中双击即选中并关闭弹窗
                allow_context_menu: true                   # 启用右键上下文菜单
                allow_pdf_preview: true                    # 启用内联 PDF 灯箱预览（false 强制下载）
                allow_video_preview: true                  # 启用内联视频播放器（false 强制下载）
                keyboard_shortcuts: true                   # 启用作用域内的键盘快捷键
                allow_drag_move: true                      # 允许将项目拖放到文件夹中进行移动
```

---

[← 上一章：01 — 环境要求与安装](01-requirements-and-installation.md) | [文档目录索引](README.md) | [下一章：03 — 使用模式与 JavaScript API →](03-usage-modes-and-js-api.md)
