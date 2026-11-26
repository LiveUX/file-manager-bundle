# 10 — Asset Usage Guard

[← Previous: 09 — Symfony AI Integration](09-symfony-ai-integration.md) | [Main Index](README.md) | [Next: 11 — Contributing & PR Guide →](11-contributing-and-pr-guide.md)

---

## 1. What Problem Does It Solve? (Real-World Scenario)

Imagine an editor in your e-commerce or CMS admin panel tries to **delete, rename, move, or overwrite** `uploads/products/matte-black-thermos.webp` (or the entire `uploads/products` folder).

- **Without Usage Guard:** The file is deleted or moved immediately—and if 3 product cards and 1 homepage banner reference it, your live site now has **broken `404` images**.
- **With Usage Guard enabled:** The file manager asks your application: *"Is this file or folder currently referenced anywhere in the database?"*
  - If references exist, the confirmation modal lists **every product, banner, or page using it**, complete with a clickable **"Open record ↗"** link.
  - With **`policy: 'warn'`**, it displays a yellow warning banner and offers a **"Delete Anyway"** button.
  - With **`policy: 'block'`** and **`enforce_server_side: true`** (the default), it displays a red blocking notice, **hides** the Delete button, and rejects **all 4 destructive operations** on the server:
    1. **Delete (`delete`)** → `errors.delete_blocked_in_use`
    2. **Rename (`rename`)** → `errors.rename_blocked_in_use`
    3. **Move (`move`)** → `errors.move_blocked_in_use`
    4. **Overwrite (`overwrite`)** — including uploads (`overwrite_policy: 'overwrite'`), the Image Editor, and ZIP extraction → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Why do you implement one small PHP class?**
> LiveUX FileManagerBundle cannot know your application's database tables (`Product`, `BlogPost`, `Slider`, `Category`, etc.) or column names. Instead, it gives you a clean single-method contract (`AssetUsageCheckerInterface`). You query your tables and return matching references—and the bundle automatically handles the UI modal, URL sanitization, deduplication, folder-prefix checks, and 4-operation server-side enforcement.

---

## 2. How It Works Step by Step

```text
[Editor clicks "Delete"]
        │
        ▼
[1. Browser sends POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> invokes every AssetUsageCheckerInterface service in your app]
        │
        ├── NO usages found ──► "Not linked to any records ✓" -> Normal confirmation
        │
        └── Usages FOUND   ──► Modal lists matching records + "Open record ↗" links:
                                 • policy: 'warn'  -> Yellow warning + "Delete Anyway"
                                 • policy: 'block' -> Red blocker (Delete button hidden)
                                                      + Server rejects delete / rename / move / overwrite!
```

---

## 3. Complete End-to-End Example (Copy & Paste Ready)

Follow these 4 steps to get **Usage Guard** running in a Symfony + Doctrine application (compatible with MySQL, MariaDB, PostgreSQL, and SQLite) in under 5 minutes.

### Step 1: Enable `usage_guard` on Your Profile

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Local Storage'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Enable for this profile (default: false)
                policy: 'block'           # 'warn' (warn + allow override) | 'block' (strictly prevent)
                enforce_server_side: true # true = also enforce delete, rename, move & overwrite server-side (default: true)
                max_references: 20        # Max references shown in the modal (1..200)
```

### Step 2: Example Doctrine Entity (`Product`)

Suppose your `Product` entity stores a single cover image (`?string`) and a JSON gallery array (`list<string>`):

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

    // e.g. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> e.g. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Step 3: Portable Repository Queries (`ProductRepository`)

These queries use portable **`ESCAPE '!'`** syntax so they work identically across MySQL, MariaDB, PostgreSQL, and SQLite:

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
     * For a single file: finds products referencing the URL as cover image or inside the JSON gallery.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Wrapping in double quotes ("/uploads/products/a.webp") prevents partial-match false positives in JSON
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
     * For a folder: finds products referencing any file inside the folder prefix.
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

### Step 4: Implement Your Usage Checker (`ProductAssetUsageChecker`)

**No `services.yaml` entry is needed!** Because `AssetUsageCheckerInterface` carries `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`, Symfony discovers and registers your checker automatically.

> [!IMPORTANT]
> **Filenames with Spaces or Non-ASCII Characters:**
> `DirectoryLister::buildPublicUrl()` encodes each path segment using `rawurlencode()` (e.g., `products/summer catalog.pdf` → `/uploads/products/summer%20catalog.pdf`). Use the `buildPublicUrl()` helper below so your queries match stored URLs exactly (if your forms store relative paths via `return_type: 'path'`, match against `ltrim($path, '/')` instead).

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
        // Optional: limit this checker to specific profiles
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // e.g. "Matte Black Thermos (#42)"
                type: 'Product',                                            // Badge label
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Clickable "Open record ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Used for deduplication
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

### Step 5 (Optional): Add More Checkers for Other Tables

Create additional focused classes implementing `AssetUsageCheckerInterface` for banners, blog posts, or categories—the bundle aggregates and deduplicates all of them into a single modal automatically:

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
                type: 'Banner',
                editUrl: $this->urlGenerator->generate('admin_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. Verifying Your Setup

1. Clear the cache:
   ```bash
   php bin/console cache:clear
   ```
2. Confirm your checker service is tagged and discovered:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   You should see `App\FileManager\ProductAssetUsageChecker` in the output.
3. Save an image URL picked from the file manager into a product's `cover_image` column (e.g., `/uploads/products/matte-black-thermos.webp`).
4. Right-click that image (or its parent `products` folder) in the file manager and click **Delete**:
   - The modal opens, shows *"Checking usages…"*, and then lists **"Matte Black Thermos (#42) [Product] — Open record ↗"**.
   - With `policy: 'block'`, the Delete button is hidden, and any attempt to `rename`, `move`, or `overwrite` that asset is also rejected server-side.

---

## 5. `warn` vs `block` Comparison & Security Notes

| Policy | Delete Modal Behavior | Server-Side Enforcement (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(default)* | Shows a yellow warning banner with matching references; action button reads **"Delete Anyway"**. | Permits delete, rename, move, and overwrite once the editor confirms. |
| **`block`** | Shows a red blocking banner with matching references and **"Open record ↗"** links; the Delete button is **hidden**. | Defense in depth: rejects **`delete`**, **`rename`**, **`move`**, and **`overwrite`** even if the API is called directly. |

- **Link Sanitization (`editUrl`):** `editUrl` is validated on both PHP (`AssetUsageGuardService::sanitizeEditUrl()`) and JavaScript (`isSafeUrl()`), allowing only root-relative paths (`/admin/...`) or `http(s)://...` URLs (`javascript:`, `data:`, and protocol-relative `//evil.com` URLs are rejected).
- **Permissions & Rate Bounds (`checkUsage`):** The `checkUsage` endpoint is CSRF-protected, enforces both **`FILE_MANAGER_VIEW`** and **`FILE_MANAGER_DELETE`** across every target path and all ancestor folders, caps requests at **200 items** (`MAX_BULK_DELETE_ITEMS`), and blocks hidden/trash paths (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **When delete confirmation is disabled (`ui.confirm_delete: false`):** If `usage_guard.enabled: true`, the usage check still runs in the background and automatically opens the warning/blocking modal whenever active references are detected.

---

[← Previous: 09 — Symfony AI Integration](09-symfony-ai-integration.md) | [Main Index](README.md) | [Next: 11 — Contributing & PR Guide →](11-contributing-and-pr-guide.md)
