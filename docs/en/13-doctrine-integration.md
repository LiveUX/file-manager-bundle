# 13 — Doctrine ORM & DBAL Integration Guide

[← Previous: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Documentation Index](README.md)

**LiveUX FileManagerBundle** has zero mandatory Doctrine dependency in its core, yet provides first-class integration for Symfony applications using Doctrine ORM or DBAL across **4 key areas**:

1. **Storing folder and file metadata in your database** (`defaults.metadata.driver: 'doctrine'` — built-in zero-code DBAL storage)
2. **Binding Doctrine Entity fields to `FileManagerPickerType`** (single column, JSON gallery, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Writing a custom metadata backend with your own Doctrine ORM Entity** (`FolderMetadataStorageInterface`)
4. **Automatically updating database paths when files or folders are renamed or moved** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Built-In Doctrine Metadata Storage (`driver: 'doctrine'`)

### In Plain Terms: What Does This Do?

When you assign a **color** (e.g., blue), **icon**, or **pinned state** to a folder — or add **alt text, title, description, tags (`#campaign`), or a focal point** to an image — that metadata is not written inside the files themselves. It is stored separately.

The bundle provides **2 ready-to-use storage drivers**:
- **Option 1 — `driver: 'json_file'` (Default, Single Server):** Stores metadata in `var/file-manager/metadata.json` on the local disk. Zero setup required for small, single-server applications.
- **Option 2 — `driver: 'doctrine'` (Database — `DoctrineDbalMetadataStorage`):** Stores metadata in a table inside your **MySQL, MariaDB, PostgreSQL, or SQLite** database. **No Entity, Repository, or custom code is required.**

**When should you choose `driver: 'doctrine'`?**
- Your application runs across **multiple servers (Load Balancer / Docker Swarm / Kubernetes)** where a local JSON file cannot be shared, whereas the database is centralized.
- You want all application state included in a single database backup.
- You need high-throughput metadata management across thousands of files and folders with zero concurrent-write conflicts.

### Architectural Highlights of the Built-In DBAL Driver

- **Zero N+1 Queries (`preload()`):** When listing directories or folder trees, keys are batch-fetched with `SELECT` queries in chunks of 400. A bounded in-memory cache (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) paired with `ResetInterface` prevents memory leaks in long-running FrankenPHP or Messenger workers.
- **Atomic Native UPSERT:** Concurrent saves use `ON CONFLICT (key_hash) DO UPDATE` on PostgreSQL/SQLite and `ON DUPLICATE KEY UPDATE` on MySQL/MariaDB — eliminating race conditions.
- **Batched Writes (`saveMany()`):** Operations like folder copying run chunked `DELETE` (400 rows) + multi-row `INSERT` (100 rows) statements inside a single database transaction.
- **PostgreSQL `text_pattern_ops` B-Tree Index:** Automatically creates a `LEFT(canonical_key, 191) text_pattern_ops` expression index and applies `~>=~` / `~<=~` range filters so prefix queries stay fully indexed even under glibc UTF-8 `LC_COLLATE`.
- **Strict Table Name Validation:** `defaults.metadata.table` accepts only safe SQL identifiers matching `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### All Configuration Options

| Option (`defaults.metadata.*`) | Default | Description |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Set to `'doctrine'` to enable `DoctrineDbalMetadataStorage` (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Customize the database table name as needed (e.g., `'media_metadata'`). |
| `connection` | `null` | If you configure multiple DBAL connections in `config/packages/doctrine.yaml` (e.g., `'media'`), targets that connection directly. |
| `entity_manager` | `'default'` | Entity Manager whose DBAL connection is used when `connection` is `null`. |
| `auto_create_table` | `true` | Automatically creates the table on first request if missing. Set to `false` in production if you manage the schema with **Doctrine Migrations**. |

### Step 1: Enable `driver: 'doctrine'` in `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Uses built-in DoctrineDbalMetadataStorage instead of json_file
            connection: null                # Optional: custom DBAL connection name (e.g., 'media')
            entity_manager: 'default'       # Entity Manager used when connection is null
            table: 'liveux_fm_metadata'     # Customize the table name freely
            auto_create_table: true         # Set to false in production when using Doctrine Migrations
```

### Step 2 (Recommended for Production): Cross-Platform Migration (MySQL / MariaDB / PostgreSQL / SQLite)

To manage the table explicitly via Doctrine Migrations in production, set `auto_create_table: false` and use this platform-aware migration (creates the `text_pattern_ops` functional index on PostgreSQL and the `lengths: [191]` prefix index on MySQL/MariaDB/SQLite):

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
        return 'Creates liveux_fm_metadata table with platform-optimized prefix index.';
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
> **Why use `key_hash CHAR(64)` as the Primary Key?** MySQL `utf8mb4` InnoDB index keys can hit the 191-character limit on long paths. Using a SHA-256 hash as the primary key allows deeply nested paths up to 4,096 characters — including Turkish, CJK, and Arabic filenames — to work seamlessly across MySQL, MariaDB, PostgreSQL, and SQLite.

### Step 3 (Example): Custom Table Name & Dedicated Database Connection

To store media metadata in an `app_file_meta` table on a separate `media` DBAL connection, only 3 lines need to change:

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

## 2. Modeling File Fields in Doctrine Entities

### A. Single Cover Image & Multi-Image JSON Gallery (Most Common)

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

    // FileManagerPickerType (multiple: true) -> list<string> (JSON array)
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

### B. Relational `OneToMany` + `CollectionType` / `LiveCollectionType`

When each item carries its own title, sort order, and image (e.g., `Slider` → `SliderItem`):

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

On the form side, add `FileManagerPickerType::class` inside `SliderItemType` and attach it to the parent form with `CollectionType::class` or `LiveCollectionType::class` (`by_reference => false`). *(For complete Form and LiveComponent examples, see [05 — FormType & Editor Integrations](05-formtype-and-editor-integrations.md).)*

### Should You Choose `return_type: 'url'` or `'path'`?

| Option | Stored in the Database | Best Used When |
| :--- | :--- | :--- |
| **`'url'`** *(default)* | `/uploads/products/thermos.webp` or `https://cdn.example.com/thermos.webp` | You want to render `<img src="{{ product.coverImage }}">` directly in templates without prefixing. |
| **`'path'`** | `products/thermos.webp` *(relative path only)* | You plan to migrate from local storage to S3/R2/CDN later by changing only `url_prefix`, without touching database records. |

---

### C. End-to-End Working Example: Form → Controller → Database → Storefront Rendering

Here is the complete lifecycle in one place: **a user selects a cover image and sortable gallery in a form → the Controller persists them via Doctrine → the product detail page renders the images along with the SEO `alt` text and focal point (`focalPoint`) stored in the file manager.**

#### 1) Symfony Form (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Product Name'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Cover Image',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Stores "/uploads/products/thermos.webp" in the DB
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Product Gallery (Drag to Reorder)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Stores a JSON array: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controller — Persisting & Reading SEO Metadata (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType validates and maps values onto the Entity automatically:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Product and images saved to the database.');

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
        // Optional: fetch the cover image's SEO alt text and focal point saved in the file manager
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

#### 3) Admin Form Template (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>New Product</h1>

    {# Form theme and DialogHost are wired automatically by the bundle #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Save</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Storefront Detail Template (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Cover image — using the focal point (focalPoint) and SEO alt text from the file manager if set #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Multi-image gallery (rendered in the exact drag-and-drop order chosen by the user!) #}
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
> **Want to store Cloud Storage credentials in your Doctrine database too?**
> See **[07 — Cloud Storage & Dynamic Configuration](07-cloud-storage-and-dynamic-config.md)** (Section 3) for a complete walkthrough on storing encrypted AWS S3, Cloudflare R2, or Supabase settings in your database and managing them from an admin panel.

---

## 3. Custom Metadata Storage with Your Own Doctrine ORM Entity (Advanced)

If you want to store metadata in your own ORM Entity instead of the built-in `DoctrineDbalMetadataStorage` (for example, to attach an extra `updatedBy` column), implement both `FolderMetadataStorageInterface` and `ResetInterface` and alias the service interface:

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
        // Prevents memory leaks and stale data in FrankenPHP / RoadRunner / Messenger workers
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
     * Batch metadata persistence (used by MetadataSyncListener::copyFilteredFolderMetadata to prevent N+1 writes).
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

            // Atomically remove old rows and any stale child entries at the destination:
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
     * Distinguishes root volume keys ('local::') from nested folder keys ('local::products').
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

## 4. Automatically Updating Database References on Rename or Move

When an editor renames or moves a file (e.g., `products/old.webp`) or an entire folder inside the file manager, you can listen to `ItemRenamedEvent` and `ItemMovedEvent` to keep both **`Product.coverImage`** and the JSON **`Product.galleryImages`** array in sync automatically:

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

## 5. Combining with Asset Usage Guard

To prevent files referenced in your Doctrine tables from being accidentally deleted, renamed, moved, or overwritten, wire `AssetUsageCheckerInterface` directly to your `ProductRepository` — see **[10 — Asset Usage Guard](10-asset-usage-guard.md)** for a complete step-by-step implementation.

---

[← Previous: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Documentation Index](README.md)
