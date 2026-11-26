# 13 — Doctrine ORM 与 DBAL 集成指南

[← 上一篇：12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [文档索引](README.md)

**LiveUX FileManagerBundle** 的核心对 Doctrine 零强制依赖，但为使用 Doctrine ORM 或 DBAL 的 Symfony 应用提供了覆盖 **4 大核心场景**的一流集成能力：

1. **将文件夹与文件元数据存入数据库**（`defaults.metadata.driver: 'doctrine'` —— 内置零代码 DBAL 存储驱动）
2. **将 Doctrine Entity 字段绑定到 `FileManagerPickerType`**（单列字段、JSON 图库、`OneToMany` + `CollectionType` / `LiveCollectionType`）
3. **使用自定义 Doctrine ORM Entity 编写元数据存储后端**（`FolderMetadataStorageInterface`）
4. **当文件或文件夹被重命名或移动时，自动更新数据库中的引用路径**（`ItemRenamedEvent` / `ItemMovedEvent`）

---

## 1. 内置 Doctrine 元数据存储（`driver: 'doctrine'`）

### 通俗来说：这项功能的作用是什么？

当你为文件夹设置**颜色**（例如蓝色）、**图标**或**置顶状态**，或者为图片添加 **Alt 文本、标题、描述、标签（`#campaign`）或焦点坐标（Focal Point）** 时，这些元数据并不会写入文件本身，而是单独存储。

本 Bundle 内置 **2 种开箱即用的存储驱动**：
- **方案 1 — `driver: 'json_file'`（默认，适合单机部署）：** 将元数据保存在本地磁盘的 `var/file-manager/metadata.json` 中。适合小型单服务器应用，零配置即可使用。
- **方案 2 — `driver: 'doctrine'`（数据库 —— `DoctrineDbalMetadataStorage`）：** 将元数据存储在你的 **MySQL、MariaDB、PostgreSQL 或 SQLite** 数据库表中。**无需创建 Entity、Repository 或编写任何自定义代码。**

**什么时候应该选择 `driver: 'doctrine'`？**
- 你的应用部署在**多台服务器（负载均衡 / Docker Swarm / Kubernetes）**上，本地 JSON 文件无法跨节点共享，而数据库是集中式的。
- 你希望所有应用状态都纳入统一的数据库备份。
- 你需要在数千个文件与文件夹上实现高吞吐元数据管理，且彻底消除并发写入冲突。

### 内置 DBAL 驱动的架构亮点

- **零 N+1 查询（`preload()`）：** 列举目录或文件夹树时，以每批 400 条的粒度通过 `SELECT` 批量预加载键值。配合有界内存缓存（`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`）与 `ResetInterface`，可彻底避免 FrankenPHP 或 Messenger 常驻 Worker 中的内存泄漏。
- **原生原子 UPSERT：** 并发写入在 PostgreSQL/SQLite 上使用 `ON CONFLICT (key_hash) DO UPDATE`，在 MySQL/MariaDB 上使用 `ON DUPLICATE KEY UPDATE`，完全消除竞态条件。
- **批量写入（`saveMany()`）：** 复制文件夹等批量操作会在单个数据库事务内执行分块 `DELETE`（每批 400 行）+ 多行 `INSERT`（每批 100 行）。
- **PostgreSQL `text_pattern_ops` B-Tree 索引：** 自动创建 `LEFT(canonical_key, 191) text_pattern_ops` 表达式索引，并采用 `~>=~` / `~<=~` 范围过滤条件，即使在 glibc UTF-8 `LC_COLLATE` 排序规则下，前缀查询也能完整命中索引。
- **严格的表名校验：** `defaults.metadata.table` 仅接受符合 `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/` 的安全 SQL 标识符。

### 全部配置选项

| 配置项（`defaults.metadata.*`） | 默认值 | 说明 |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | 设为 `'doctrine'` 以启用 `DoctrineDbalMetadataStorage`（可选：`'json_file'`、`'doctrine'`、`'none'`）。 |
| `table` | `'liveux_fm_metadata'` | 可按需自定义数据库表名（例如 `'media_metadata'`）。 |
| `connection` | `null` | 如果在 `config/packages/doctrine.yaml` 中配置了多个 DBAL 连接（例如 `'media'`），可直接指定该连接。 |
| `entity_manager` | `'default'` | 当 `connection` 为 `null` 时，从该 Entity Manager 获取对应的 DBAL 连接。 |
| `auto_create_table` | `true` | 若数据表不存在，则在首次请求时自动创建。在生产环境若通过 **Doctrine Migrations** 管理表结构，建议设为 `false`。 |

### 第 1 步：在 `liveux_file_manager.yaml` 中启用 `driver: 'doctrine'`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # 使用内置 DoctrineDbalMetadataStorage 替代 json_file
            connection: null                # 可选：指定自定义 DBAL 连接名（例如 'media'）
            entity_manager: 'default'       # 当 connection 为 null 时使用的 Entity Manager
            table: 'liveux_fm_metadata'     # 可自由自定义数据表名称
            auto_create_table: true         # 生产环境使用 Doctrine Migrations 时请设为 false
```

### 第 2 步（生产环境推荐）：跨数据库平台迁移脚本（MySQL / MariaDB / PostgreSQL / SQLite）

如需在生产环境中通过 Doctrine Migrations 显式管理表结构，请将 `auto_create_table: false` 并使用以下按数据库平台自动适配的迁移类（在 PostgreSQL 上创建 `text_pattern_ops` 函数索引，在 MySQL/MariaDB/SQLite 上创建 `lengths: [191]` 前缀索引）：

```php
<?php
// migrations/Version20261125000001.php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '创建 liveux_fm_metadata 表及针对各数据库平台优化的前缀索引。';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('liveux_fm_metadata');
        $table->addColumn('key_hash', 'string', ['length' => 64, 'fixed' => true]);
        $table->addColumn('canonical_key', 'string', ['length' => 4096]);
        $table->addColumn('payload', 'text');
        $table->addColumn('updated_at', 'datetime');
        $table->setPrimaryKey(['key_hash']);

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $table->addIndex(['canonical_key'], 'idx_liveux_fm_metadata_ckey', [], ['lengths' => [191]]);
        }
    }

    public function postUp(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->connection->executeStatement(
                'CREATE INDEX IF NOT EXISTS "idx_liveux_fm_metadata_ckey" ON "liveux_fm_metadata" (LEFT(canonical_key, 191) text_pattern_ops)'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('liveux_fm_metadata');
    }
}
```

```bash
php bin/console doctrine:migrations:migrate
```

> [!NOTE]
> **为什么使用 `key_hash CHAR(64)` 作为主键？** MySQL `utf8mb4` 的 InnoDB 索引键在处理长路径时容易触发 191 字符限制。采用 SHA-256 哈希作为主键，可让长达 4,096 字符的深层嵌套路径（包括中文、日韩文、土耳其文及阿拉伯文文件名）在 MySQL、MariaDB、PostgreSQL 和 SQLite 上均无缝运行。

### 第 3 步（示例）：自定义表名与独立数据库连接

若要将媒体元数据存储在独立 `media` DBAL 连接的 `app_file_meta` 表中，只需修改 3 行配置：

```yaml
liveux_file_manager:
    defaults:
        metadata:
            driver: 'doctrine'
            connection: 'media'
            table: 'app_file_meta'
            auto_create_table: false
```

---

## 2. 在 Doctrine Entity 中建模文件字段

### A. 单张封面图 + 多图 JSON 图库（最常见）

```php
<?php
// src/Entity/Product.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // FileManagerPickerType (multiple: false) -> ?string
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    // FileManagerPickerType (multiple: true) -> list<string> (JSON 数组)
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }

    public function getCoverImage(): ?string { return $this->coverImage; }
    public function setCoverImage(?string $coverImage): self { $this->coverImage = $coverImage; return $this; }

    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
    /** @param list<string> $galleryImages */
    public function setGalleryImages(array $galleryImages): self { $this->galleryImages = array_values($galleryImages); return $this; }
}
```

### B. 关系型 `OneToMany` + `CollectionType` / `LiveCollectionType`

当每个条目都需要携带各自的标题、排序与图片时（例如 `Slider` → `SliderItem`）：

```php
<?php
// src/Entity/Slider.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Slider
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, SliderItem> */
    #[ORM\OneToMany(targetEntity: SliderItem::class, mappedBy: 'slider', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    /** @return Collection<int, SliderItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(SliderItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setSlider($this);
        }

        return $this;
    }

    public function removeItem(SliderItem $item): self
    {
        if ($this->items->removeElement($item) && $item->getSlider() === $this) {
            $item->setSlider(null);
        }

        return $this;
    }
}
```

在表单侧，只需在 `SliderItemType` 中添加 `FileManagerPickerType::class`，再通过 `CollectionType::class` 或 `LiveCollectionType::class`（设置 `by_reference => false`）挂载到父表单中。*（完整的 Form 与 LiveComponent 示例请参阅 [05 — FormType 与编辑器集成](05-formtype-and-editor-integrations.md)。）*

### 应该选择 `return_type: 'url'` 还是 `'path'`？

| 选项 | 数据库中存储的值 | 最佳适用场景 |
| :--- | :--- | :--- |
| **`'url'`** *（默认）* | `/uploads/products/thermos.webp` 或 `https://cdn.example.com/thermos.webp` | 希望在模板中直接渲染 `<img src="{{ product.coverImage }}">`，无需拼接前缀。 |
| **`'path'`** | `products/thermos.webp` *（仅相对路径）* | 计划日后从本地存储迁移至 S3/R2/CDN，届时只需修改 `url_prefix` 即可，无需改动数据库记录。 |

---

### C. 端到端完整示例：表单 → 控制器 → 数据库 → 前台展示

下面一站式演示完整生命周期：**用户在表单中选择封面图与可拖拽排序的图库 → 控制器通过 Doctrine 持久化入库 → 商品详情页渲染图片，同时自动读取文件管理器中保存的 SEO `alt` 文本与焦点坐标（`focalPoint`）。**

#### 1) Symfony 表单（`src/Form/ProductType.php`）

```php
<?php
// src/Form/ProductType.php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Product;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => '商品名称'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => '封面图',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // 在数据库中存储 "/uploads/products/thermos.webp"
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => '商品图库（拖拽排序）',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // 在数据库中存储 JSON 数组：["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) 控制器 — 持久化与读取 SEO 元数据（`alt` / `focalPoint`）（`src/Controller/ProductController.php`）

```php
<?php
// src/Controller/ProductController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Form\ProductType;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    #[Route('/admin/products/new', name: 'admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // FileManagerPickerType 会自动校验并将值映射至 Entity：
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', '商品及图片已保存至数据库。');

            return $this->redirectToRoute('product_show', ['id' => $product->getId()]);
        }

        return $this->render('admin/product/form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/products/{id}', name: 'product_show', methods: ['GET'])]
    public function show(
        Product $product,
        ConfigProviderInterface $configProvider,
        VolumeRegistry $volumeRegistry,
        PathNormalizer $pathNormalizer,
        FolderMetadataStorageInterface $metadataStorage,
    ): Response {
        // 可选：读取在文件管理器中为封面图保存的 SEO alt 文本与焦点坐标
        $coverMeta = null;
        if ($product->getCoverImage() !== null && str_starts_with($product->getCoverImage(), '/uploads/')) {
            $profile = $configProvider->getProfile('default');
            $volume = $volumeRegistry->getVolumeForProfile($profile, 'local_uploads');
            $relativePath = rawurldecode(substr($product->getCoverImage(), strlen('/uploads/')));
            $canonicalKey = $pathNormalizer->buildCanonicalKey($volume, $relativePath, $profile->basePath);
            $coverMeta = $metadataStorage->get($canonicalKey);
        }

        return $this->render('product/show.html.twig', [
            'product' => $product,
            'coverMeta' => $coverMeta,
        ]);
    }
}
```

#### 3) 后台表单模板（`templates/admin/product/form.html.twig`）

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>新建商品</h1>

    {# 表单主题与 DialogHost 已由 Bundle 自动挂载 #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">保存</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) 前台商品详情模板（`templates/product/show.html.twig`）

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# 封面图 —— 若在文件管理器中设置了焦点坐标（focalPoint）与 SEO alt 文本，则自动应用 #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# 多图图库（严格按照用户拖拽排列的顺序渲染！） #}
    {% if product.galleryImages is not empty %}
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 16px;">
            {% for imageUrl in product.galleryImages %}
                <img src="{{ imageUrl }}" alt="{{ product.name }}" loading="lazy" style="width: 100%; border-radius: 8px;">
            {% endfor %}
        </div>
    {% endif %}
{% endblock %}
```

> [!TIP]
> **也想将云存储凭证存入 Doctrine 数据库？**
> 请参阅 **[07 — 云存储与动态配置](07-cloud-storage-and-dynamic-config.md)**（第 3 节），获取将加密的 AWS S3、Cloudflare R2 或 Supabase 配置存入数据库并在管理后台动态维护的完整指南。

---

## 3. 使用自定义 Doctrine ORM Entity 实现元数据存储（进阶）

如果你希望使用自己的 ORM Entity 替代内置的 `DoctrineDbalMetadataStorage`（例如需要额外附加 `updatedBy` 字段），只需同时实现 `FolderMetadataStorageInterface` 与 `ResetInterface`，并在服务配置中为接口设置别名即可：

```php
<?php
// src/Entity/FileManagerMeta.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_fm_meta')]
class FileManagerMeta
{
    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $keyHash;

    #[ORM\Column(length: 4096)]
    private string $canonicalKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** @param array<string, mixed> $payload */
    public function __construct(string $canonicalKey, array $payload)
    {
        $this->keyHash = hash('sha256', $canonicalKey);
        $this->canonicalKey = $canonicalKey;
        $this->payload = $payload;
    }

    public function getCanonicalKey(): string { return $this->canonicalKey; }
    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }
    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): void { $this->payload = $payload; }
}
```

```php
<?php
// src/FileManager/DoctrineOrmMetadataStorage.php

declare(strict_types=1);

namespace App\FileManager;

use App\Entity\FileManagerMeta;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Contracts\Service\ResetInterface;

final class DoctrineOrmMetadataStorage implements FolderMetadataStorageInterface, ResetInterface
{
    /** @var array<string, FolderMetadata> */
    private array $itemCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function reset(): void
    {
        // 防止在 FrankenPHP / RoadRunner / Messenger 常驻 Worker 中出现内存泄漏与脏缓存
        $this->itemCache = [];
    }

    public function get(string $canonicalKey): FolderMetadata
    {
        $canonicalKey = UnicodeHelper::toNfc($canonicalKey);
        if (array_key_exists($canonicalKey, $this->itemCache)) {
            return $this->itemCache[$canonicalKey];
        }

        $entity = $this->em->find(FileManagerMeta::class, hash('sha256', $canonicalKey));

        return $this->itemCache[$canonicalKey] = $entity instanceof FileManagerMeta
            ? FolderMetadata::fromArray($entity->getPayload())
            : new FolderMetadata();
    }

    /**
     * @param list<string> $canonicalKeys
     */
    public function preload(array $canonicalKeys): void
    {
        $missingByHash = [];
        foreach ($canonicalKeys as $rawKey) {
            $key = UnicodeHelper::toNfc($rawKey);
            if ($key !== '' && !array_key_exists($key, $this->itemCache)) {
                $missingByHash[hash('sha256', $key)] = $key;
            }
        }

        foreach (array_chunk($missingByHash, 400, true) as $chunk) {
            $entities = $this->em->createQueryBuilder()
                ->select('m')
                ->from(FileManagerMeta::class, 'm')
                ->where('m.keyHash IN (:hashes)')
                ->setParameter('hashes', array_keys($chunk))
                ->getQuery()
                ->getResult();

            $found = [];
            foreach ($entities as $entity) {
                if (!$entity instanceof FileManagerMeta) {
                    continue;
                }
                $key = UnicodeHelper::toNfc($entity->getCanonicalKey());
                $this->itemCache[$key] = FolderMetadata::fromArray($entity->getPayload());
                $found[hash('sha256', $key)] = true;
            }
            foreach ($chunk as $hash => $key) {
                if (!isset($found[$hash])) {
                    $this->itemCache[$key] = new FolderMetadata();
                }
            }
        }
    }

    /**
     * @return array<string, FolderMetadata>
     */
    public function getByPrefix(string $canonicalPrefix): array
    {
        $canonicalPrefix = UnicodeHelper::toNfc($canonicalPrefix);
        $childPrefix = self::childPrefix($canonicalPrefix);
        $likePrefix = self::escapeLike($childPrefix) . '%';

        $rows = $this->em->createQueryBuilder()
            ->select('m')
            ->from(FileManagerMeta::class, 'm')
            ->where("m.keyHash = :hash OR m.canonicalKey LIKE :prefix ESCAPE '!'")
            ->setParameter('hash', hash('sha256', $canonicalPrefix))
            ->setParameter('prefix', $likePrefix)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            if (!$row instanceof FileManagerMeta) {
                continue;
            }
            $key = UnicodeHelper::toNfc($row->getCanonicalKey());
            if ($key === $canonicalPrefix || str_starts_with($key, $childPrefix)) {
                $result[$key] = FolderMetadata::fromArray($row->getPayload());
            }
        }

        return $result;
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->em->getRepository(FileManagerMeta::class)->findAll() as $entity) {
            $result[UnicodeHelper::toNfc($entity->getCanonicalKey())] = FolderMetadata::fromArray($entity->getPayload());
        }

        return $result;
    }

    public function save(string $canonicalKey, FolderMetadata $metadata): void
    {
        $this->saveMany([$canonicalKey => $metadata]);
    }

    /**
     * 批量持久化元数据（由 MetadataSyncListener::copyFilteredFolderMetadata 调用，避免 N+1 写入）。
     *
     * @param array<string, FolderMetadata> $entries
     */
    public function saveMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($entries): void {
            foreach ($entries as $rawKey => $metadata) {
                $canonicalKey = UnicodeHelper::toNfc($rawKey);
                $entity = $this->em->find(FileManagerMeta::class, hash('sha256', $canonicalKey));

                if ($metadata->isEmpty()) {
                    if ($entity instanceof FileManagerMeta) {
                        $this->em->remove($entity);
                    }
                    unset($this->itemCache[$canonicalKey]);
                    continue;
                }

                if ($entity instanceof FileManagerMeta) {
                    $entity->setPayload($metadata->toArray());
                } else {
                    $this->em->persist(new FileManagerMeta($canonicalKey, $metadata->toArray()));
                }
                $this->itemCache[$canonicalKey] = $metadata;
            }
            $this->em->flush();
        });
    }

    public function delete(string $canonicalKey): void
    {
        $canonicalKey = UnicodeHelper::toNfc($canonicalKey);
        $childPrefix = self::childPrefix($canonicalKey);

        $this->em->createQueryBuilder()
            ->delete(FileManagerMeta::class, 'm')
            ->where("m.keyHash = :hash OR m.canonicalKey LIKE :prefix ESCAPE '!'")
            ->setParameter('hash', hash('sha256', $canonicalKey))
            ->setParameter('prefix', self::escapeLike($childPrefix) . '%')
            ->getQuery()
            ->execute();

        foreach (array_keys($this->itemCache) as $cachedKey) {
            if ($cachedKey === $canonicalKey || str_starts_with($cachedKey, $childPrefix)) {
                unset($this->itemCache[$cachedKey]);
            }
        }
    }

    public function relocatePrefix(string $oldCanonicalPrefix, string $newCanonicalPrefix): void
    {
        $oldCanonicalPrefix = UnicodeHelper::toNfc($oldCanonicalPrefix);
        $newCanonicalPrefix = UnicodeHelper::toNfc($newCanonicalPrefix);
        if ($oldCanonicalPrefix === $newCanonicalPrefix) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($oldCanonicalPrefix, $newCanonicalPrefix): void {
            $oldChildPrefix = self::childPrefix($oldCanonicalPrefix);
            /** @var list<FileManagerMeta> $rows */
            $rows = $this->em->createQueryBuilder()
                ->select('m')
                ->from(FileManagerMeta::class, 'm')
                ->where("m.keyHash = :hash OR m.canonicalKey LIKE :prefix ESCAPE '!'")
                ->setParameter('hash', hash('sha256', $oldCanonicalPrefix))
                ->setParameter('prefix', self::escapeLike($oldChildPrefix) . '%')
                ->getQuery()
                ->getResult();

            $relocated = [];
            foreach ($rows as $row) {
                $oldKey = UnicodeHelper::toNfc($row->getCanonicalKey());
                if ($oldKey !== $oldCanonicalPrefix && !str_starts_with($oldKey, $oldChildPrefix)) {
                    continue;
                }
                $newKey = self::remapKey($oldKey, $oldCanonicalPrefix, $newCanonicalPrefix);
                $relocated[$newKey] = $row->getPayload();
                $this->em->remove($row);
                unset($this->itemCache[$oldKey]);
            }

            // 原子化清除旧记录以及目标路径下可能残留的旧子项：
            $this->em->flush();
            $this->delete($newCanonicalPrefix);

            foreach ($relocated as $newKey => $payload) {
                $this->em->persist(new FileManagerMeta($newKey, $payload));
                $this->itemCache[$newKey] = FolderMetadata::fromArray($payload);
            }
            $this->em->flush();
        });
    }

    public function copyPrefix(string $sourceCanonicalPrefix, string $targetCanonicalPrefix): void
    {
        $sourceCanonicalPrefix = UnicodeHelper::toNfc($sourceCanonicalPrefix);
        $targetCanonicalPrefix = UnicodeHelper::toNfc($targetCanonicalPrefix);
        if ($sourceCanonicalPrefix === $targetCanonicalPrefix) {
            return;
        }

        $entries = [];
        foreach ($this->getByPrefix($sourceCanonicalPrefix) as $oldKey => $meta) {
            $targetKey = self::remapKey($oldKey, $sourceCanonicalPrefix, $targetCanonicalPrefix);
            $entries[$targetKey] = $meta;
        }
        $this->saveMany($entries);
    }

    /**
     * 区分存储卷根键（'local::'）与嵌套文件夹键（'local::products'）。
     */
    private static function childPrefix(string $canonicalPrefix): string
    {
        return str_ends_with($canonicalPrefix, '::') ? $canonicalPrefix : $canonicalPrefix . '/';
    }

    private static function remapKey(string $oldKey, string $oldPrefix, string $newPrefix): string
    {
        if ($oldKey === $oldPrefix) {
            return $newPrefix;
        }
        $suffix = ltrim(substr($oldKey, strlen($oldPrefix)), '/');

        return str_ends_with($newPrefix, '::') ? $newPrefix . $suffix : $newPrefix . '/' . $suffix;
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface:
        alias: App\FileManager\DoctrineOrmMetadataStorage
```

---

## 4. 重命名或移动时自动更新数据库引用

当编辑人员在文件管理器中重命名或移动某个文件（例如 `products/old.webp`）甚至整个文件夹时，你可以监听 `ItemRenamedEvent` 与 `ItemMovedEvent`，让 **`Product.coverImage`** 与 JSON 数组 **`Product.galleryImages`** 自动保持同步：

```php
<?php
// src/EventListener/ProductImageRenameSyncListener.php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class ProductImageRenameSyncListener
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: ItemRenamedEvent::class)]
    public function onRenamed(ItemRenamedEvent $event): void
    {
        $this->syncPath($event->volume, $event->oldPath, $event->volume, $event->newPath, $event->isFolder);
    }

    #[AsEventListener(event: ItemMovedEvent::class)]
    public function onMoved(ItemMovedEvent $event): void
    {
        if ($event->isCopy) {
            return;
        }

        $this->syncPath($event->sourceVolume, $event->sourcePath, $event->targetVolume, $event->targetPath, $event->isFolder);
    }

    private function syncPath(
        VolumeConfig $sourceVolume,
        string $oldPath,
        VolumeConfig $targetVolume,
        string $newPath,
        bool $isFolder,
    ): void {
        if ($sourceVolume->private || $targetVolume->private) {
            return;
        }

        $oldUrl = $this->buildPublicUrl($sourceVolume, $oldPath);
        $newUrl = $this->buildPublicUrl($targetVolume, $newPath);
        if ($oldUrl === null || $newUrl === null || $oldUrl === $newUrl) {
            return;
        }

        $needle = $isFolder
            ? strtr($oldUrl . '/', ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'
            : '%' . strtr('"' . $oldUrl . '"', ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');

        if ($isFolder) {
            $qb->where("p.coverImage LIKE :prefix ESCAPE '!' OR p.galleryImages LIKE :jsonPrefix ESCAPE '!'")
                ->setParameter('prefix', $needle)
                ->setParameter('jsonPrefix', '%' . strtr('"' . $oldUrl . '/', ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        } else {
            $qb->where("p.coverImage = :oldUrl OR p.galleryImages LIKE :jsonNeedle ESCAPE '!'")
                ->setParameter('oldUrl', $oldUrl)
                ->setParameter('jsonNeedle', $needle);
        }

        /** @var list<Product> $products */
        $products = $qb->getQuery()->getResult();
        $folderSlash = $oldUrl . '/';

        foreach ($products as $product) {
            $cover = $product->getCoverImage();
            if ($cover !== null) {
                $updatedCover = $this->replaceUrl($cover, $oldUrl, $newUrl, $folderSlash, $isFolder);
                if ($updatedCover !== $cover) {
                    $product->setCoverImage($updatedCover);
                }
            }

            $gallery = $product->getGalleryImages();
            $updatedGallery = array_map(
                fn (string $item): string => $this->replaceUrl($item, $oldUrl, $newUrl, $folderSlash, $isFolder),
                $gallery,
            );
            if ($updatedGallery !== $gallery) {
                $product->setGalleryImages($updatedGallery);
            }
        }

        $this->em->flush();
    }

    private function replaceUrl(string $current, string $oldUrl, string $newUrl, string $folderSlash, bool $isFolder): string
    {
        if (!$isFolder) {
            return $current === $oldUrl ? $newUrl : $current;
        }

        return str_starts_with($current, $folderSlash)
            ? $newUrl . substr($current, strlen($oldUrl))
            : $current;
    }

    private function buildPublicUrl(VolumeConfig $volume, string $relativePath): ?string
    {
        if ($volume->urlPrefix === null || $volume->urlPrefix === '') {
            return null;
        }

        $trimmed = ltrim($relativePath, '/');
        if ($trimmed === '') {
            return rtrim($volume->urlPrefix, '/');
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $trimmed)));

        return rtrim($volume->urlPrefix, '/') . '/' . $encodedPath;
    }
}
```

---

## 5. 结合资源使用保护（Asset Usage Guard）使用

为防止已被 Doctrine 数据表引用的文件被误删、重命名、移动或覆盖，可将 `AssetUsageCheckerInterface` 直接对接到你的 `ProductRepository` —— 完整分步实现请参阅 **[10 — 资源使用保护](10-asset-usage-guard.md)**。

---

[← 上一篇：12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [文档索引](README.md)
