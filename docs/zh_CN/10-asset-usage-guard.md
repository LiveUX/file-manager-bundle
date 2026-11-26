# 10 — 资产引用保护（Asset Usage Guard）

[← 上一篇：09 — Symfony AI 集成](09-symfony-ai-integration.md) | [文档首页](README.md) | [下一篇：11 — 贡献与 PR 指南 →](11-contributing-and-pr-guide.md)

---

## 1. 它解决什么问题？（真实业务场景）

试想一下：电商或 CMS 后台的编辑人员试图**删除、重命名、移动或覆盖** `uploads/products/matte-black-thermos.webp`（甚至整个 `uploads/products` 文件夹）。

- **未启用引用保护时：** 文件会被立刻删除或移走——如果此时有 3 个商品卡片和 1 个首页横幅正在引用它，线上页面就会立刻出现 **`404` 裂图**。
- **启用引用保护后：** 文件管理器会先向你的应用发起询问：*“这个文件或文件夹目前在数据库中有没有被引用？”*
  - 如果存在引用，确认弹窗会清晰列出**所有正在使用它的商品、横幅或文章**，并附上可点击的 **“打开记录 ↗”** 跳转链接。
  - 配置为 **`policy: 'warn'`** 时，弹窗展示黄色警告横幅，并提供 **“仍然删除”** 按钮由编辑确认放行。
  - 配置为 **`policy: 'block'`** 且 **`enforce_server_side: true`**（默认开启）时，弹窗展示红色拦截提示、**隐藏**删除按钮，并在服务端拒绝**全部 4 类破坏性操作**：
    1. **删除（`delete`）** → `errors.delete_blocked_in_use`
    2. **重命名（`rename`）** → `errors.rename_blocked_in_use`
    3. **移动（`move`）** → `errors.move_blocked_in_use`
    4. **覆盖（`overwrite`）** — 包含普通上传覆盖（`overwrite_policy: 'overwrite'`）、图片编辑器覆盖保存以及 ZIP 解压覆盖 → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **为什么需要自己写一个小型 PHP 类？**
> LiveUX FileManagerBundle 无法预先知道你业务系统中的数据库表结构（`Product`、`BlogPost`、`Slider`、`Category` 等）或字段命名。因此，Bundle 提供了一个极简的单方法契约（`AssetUsageCheckerInterface`）：你只需查询业务表并返回命中的引用记录，其余的弹窗交互、URL 安全净化、结果去重、文件夹前缀匹配以及 4 类操作的服务端强制拦截都会由 Bundle 全自动完成。

---

## 2. 逐步工作原理

```text
[编辑人员点击“删除”]
        │
        ▼
[1. 浏览器发送 POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> 依次调用应用中所有的 AssetUsageCheckerInterface 服务]
        │
        ├── 未发现引用 ──► “未发现此项目的活跃引用 ✓” -> 显示常规确认框
        │
        └── 检测到引用 ──► 弹窗列出关联记录与“打开记录 ↗”链接：
                             • policy: 'warn'  -> 黄色警告 + “仍然删除”按钮
                             • policy: 'block' -> 红色拦截提示（隐藏删除按钮）
                                                  + 服务端同步拦截 delete / rename / move / overwrite！
```

---

## 3. 完整端到端示例（开箱即用）

按照以下 4 个步骤，不到 5 分钟即可在 Symfony + Doctrine 应用（同时兼容 MySQL、MariaDB、PostgreSQL 与 SQLite）中跑通 **资产引用保护**。

### 第 1 步：在配置文件中启用 `usage_guard`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: '本地存储'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # 为此配置文件启用引用保护（默认：false）
                policy: 'block'           # 'warn'（警告并允许强制继续） | 'block'（严格拦截）
                enforce_server_side: true # true = 服务端同步强制拦截删除、重命名、移动和覆盖（默认：true）
                max_references: 20        # 弹窗中最多展示的引用条数（1..200）
```

### 第 2 步：示例 Doctrine 实体（`Product`）

假设你的 `Product` 实体包含一张封面图（`?string`）和一个 JSON 图集数组（`list<string>`）：

```php
<?php
// src/Entity/Product.php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_cover_image', columns: ['cover_image'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name = '';

    // 例如 "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> 例如 ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### 第 3 步：跨数据库兼容的 Repository 查询（`ProductRepository`）

下方查询采用了标准可移植的 **`ESCAPE '!'`** 语法，在 MySQL、MariaDB、PostgreSQL 与 SQLite 上表现完全一致：

```php
<?php
// src/Repository/ProductRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * 单文件检查：查找封面图或 JSON 图集中引用了该 URL 的商品。
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // 用双引号包裹（"/uploads/products/a.webp"）可防止 JSON 字符串中的前缀部分误匹配
        $jsonNeedle = '%' . self::escapeLike('"' . $publicUrl . '"') . '%';

        return $this->createQueryBuilder('p')
            ->andWhere("p.coverImage = :exact OR p.galleryImages LIKE :jsonNeedle ESCAPE '!'")
            ->setParameter('exact', $publicUrl)
            ->setParameter('jsonNeedle', $jsonNeedle)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * 文件夹检查：查找引用了该文件夹前缀下任意文件的商品。
     *
     * @return list<Product>
     */
    public function findByAssetFolderPrefix(string $folderUrlPrefix, int $limit = 25): array
    {
        $prefix = rtrim($folderUrlPrefix, '/') . '/';
        $coverLike = self::escapeLike($prefix) . '%';
        $jsonLike = '%' . self::escapeLike('"' . $prefix) . '%';

        return $this->createQueryBuilder('p')
            ->andWhere("p.coverImage LIKE :coverLike ESCAPE '!' OR p.galleryImages LIKE :jsonLike ESCAPE '!'")
            ->setParameter('coverLike', $coverLike)
            ->setParameter('jsonLike', $jsonLike)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
```

### 第 4 步：实现引用检查器（`ProductAssetUsageChecker`）

**无需在 `services.yaml` 中手动注册！** 由于 `AssetUsageCheckerInterface` 已标注 `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`，Symfony 会自动扫描并注册你的检查器类。

> [!IMPORTANT]
> **包含空格或非 ASCII 字符（如中文）的文件名：**
> `DirectoryLister::buildPublicUrl()` 会对每个路径段执行 `rawurlencode()` 编码（例如 `products/summer catalog.pdf` → `/uploads/products/summer%20catalog.pdf`）。请使用下方的 `buildPublicUrl()` 辅助方法以确保与数据库中存储的 URL 精确匹配（如果你的表单通过 `return_type: 'path'` 保存的是相对路径，则直接匹配 `ltrim($path, '/')` 即可）。

```php
<?php
// src/FileManager/ProductAssetUsageChecker.php

declare(strict_types=1);

namespace App\FileManager;

use App\Repository\ProductRepository;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ProductAssetUsageChecker implements AssetUsageCheckerInterface
{
    public function __construct(
        private ProductRepository $productRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function findUsages(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        bool $isFolder = false,
    ): array {
        // 可选：仅针对特定配置文件生效
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // 例如 "磨砂黑保温杯 (#42)"
                type: '商品',                                                // 徽章标签
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // 可点击的“打开记录 ↗”链接
                    : null,
                entityId: $id !== null ? (string) $id : null,               // 用于结果去重
            );
        }

        return $references;
    }

    private static function buildPublicUrl(?string $urlPrefix, string $relativePath): string
    {
        $encoded = implode('/', array_map(
            'rawurlencode',
            array_values(array_filter(explode('/', trim($relativePath, '/')), static fn (string $s): bool => $s !== '')),
        ));

        $prefix = rtrim((string) ($urlPrefix ?? ''), '/');

        return $prefix !== '' ? $prefix . '/' . $encoded : $encoded;
    }
}
```

### 第 5 步（可选）：为其他业务表添加更多检查器

你可以为横幅（Banner）、文章（BlogPost）或分类（Category）分别创建职责单一的 `AssetUsageCheckerInterface` 实现类——Bundle 会自动聚合所有检查器的结果并去重后统一展示在一个弹窗中：

```php
<?php
// src/FileManager/BannerAssetUsageChecker.php

declare(strict_types=1);

namespace App\FileManager;

use App\Repository\BannerRepository;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use LiveUX\FileManagerBundle\Usage\AssetUsageReference;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BannerAssetUsageChecker implements AssetUsageCheckerInterface
{
    public function __construct(
        private BannerRepository $bannerRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function findUsages(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        bool $isFolder = false,
    ): array {
        $encoded = implode('/', array_map(
            'rawurlencode',
            array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== '')),
        ));
        $prefix = rtrim((string) ($volume->urlPrefix ?? ''), '/');
        $publicUrl = $prefix !== '' ? $prefix . '/' . $encoded : $encoded;

        $banners = $this->bannerRepository->findUsingImage($publicUrl, $isFolder);

        $refs = [];
        foreach ($banners as $banner) {
            $refs[] = new AssetUsageReference(
                label: $banner->getTitle(),
                type: '横幅',
                editUrl: $this->urlGenerator->generate('admin_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. 验证配置是否生效

1. 清除缓存：
   ```bash
   php bin/console cache:clear
   ```
2. 确认你的检查器服务已被自动打标并发现：
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   输出列表中应当可以看到 `App\FileManager\ProductAssetUsageChecker`。
3. 将文件管理器中选中的某张图片 URL 保存到某个商品的 `cover_image` 字段（例如 `/uploads/products/matte-black-thermos.webp`）。
4. 回到文件管理器，右键点击该图片（或其所在的 `products` 父文件夹）并点击 **删除**：
   - 弹窗打开后先显示 *“正在检查引用情况…”*，随后列出 **“磨砂黑保温杯 (#42) [商品] — 打开记录 ↗”**。
   - 在 `policy: 'block'` 策略下，删除按钮会被隐藏，且任何对该资产发起的 `rename`、`move` 或 `overwrite` 请求同样会被服务端拦截。

---

## 5. `warn` 与 `block` 策略对比及安全说明

| 策略 | 删除确认弹窗行为 | 服务端强制拦截（`enforce_server_side: true`） |
| :--- | :--- | :--- |
| **`warn`** *（默认）* | 显示黄色警告横幅并列出命中引用；操作按钮显示为 **“仍然删除”**。 | 编辑确认后允许执行删除、重命名、移动和覆盖。 |
| **`block`** | 显示红色拦截横幅、命中引用及 **“打开记录 ↗”** 链接；**隐藏**删除按钮。 | 纵深防御：即使绕过前端直接调用 API，服务端仍严格拒绝 **`delete`**、**`rename`**、**`move`** 和 **`overwrite`**。 |

- **链接安全净化（`editUrl`）：** `editUrl` 会在 PHP 端（`AssetUsageGuardService::sanitizeEditUrl()`）与前端 JS（`isSafeUrl()`）双重校验，仅放行站内根相对路径（`/admin/...`）或 `http(s)://...` 链接（自动拒绝 `javascript:`、`data:` 及协议相对链接 `//evil.com`）。
- **权限与批量上限校验（`checkUsage`）：** `checkUsage` 接口受 CSRF 保护，会对所有目标路径及其全部父级文件夹同时校验 **`FILE_MANAGER_VIEW`** 和 **`FILE_MANAGER_DELETE`** 权限，单次请求上限为 **200 项**（`MAX_BULK_DELETE_ITEMS`），并阻止访问隐藏文件或回收站路径（`assertAccessiblePath`、`assertNotTrashAncestor`）。
- **关闭删除确认弹窗时（`ui.confirm_delete: false`）：** 只要 `usage_guard.enabled: true`，系统仍会在后台执行引用检查，并在检测到活跃引用时自动弹出警告或拦截弹窗。

---

[← 上一篇：09 — Symfony AI 集成](09-symfony-ai-integration.md) | [文档首页](README.md) | [下一篇：11 — 贡献与 PR 指南 →](11-contributing-and-pr-guide.md)
