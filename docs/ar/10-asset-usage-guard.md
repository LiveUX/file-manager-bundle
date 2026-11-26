# 10 — حماية استخدام الأصول (Asset Usage Guard)

[→ السابق: 09 — التكامل مع Symfony AI](09-symfony-ai-integration.md) | [الفهرس الرئيسي](README.md) | [التالي: 11 — دليل المساهمة وطلبات الدمج (PR) ←](11-contributing-and-pr-guide.md)

---

## 1. ما المشكلة التي تحلّها هذه الميزة؟ (سيناريو واقعي)

تخيّل أن محرّرًا في لوحة تحكم متجرك الإلكتروني أو نظام إدارة المحتوى يحاول **حذف، أو إعادة تسمية، أو نقل، أو استبدال** الملف `uploads/products/matte-black-thermos.webp` (أو مجلد `uploads/products` بالكامل).

- **بدون حماية استخدام الأصول (Usage Guard):** يُحذف الملف أو يُنقل فورًا — وإذا كانت 3 بطاقات منتجات ولافتة في الصفحة الرئيسية تشير إليه، فسيظهر على موقعك الحي **صور معطّلة بخطأ `404`**.
- **مع تفعيل حماية استخدام الأصول:** يسأل مدير الملفات تطبيقك: *«هل هذا الملف أو المجلد مستخدم حاليًا في أي مكان داخل قاعدة البيانات؟»*
  - إذا وُجدت مراجع، تعرض نافذة التأكيد **كل منتج أو لافتة أو صفحة تستخدمه**، مرفقةً برابط قابل للنقر **«فتح السجل ↗»**.
  - مع **`policy: 'warn'`**، يظهر شريط تحذير أصفر مع زر **«الحذف على أي حال»**.
  - مع **`policy: 'block'`** و **`enforce_server_side: true`** (الافتراضي)، يظهر إشعار حظر أحمر، ويُ**خفى** زر الحذف، ويَرفض الخادم **جميع العمليات التدميرية الأربع**:
    1. **الحذف (`delete`)** ← `errors.delete_blocked_in_use`
    2. **إعادة التسمية (`rename`)** ← `errors.rename_blocked_in_use`
    3. **النقل (`move`)** ← `errors.move_blocked_in_use`
    4. **الاستبدال (`overwrite`)** — ويشمل الرفع (`overwrite_policy: 'overwrite'`)، ومحرّر الصور، وفك ضغط ZIP ← `errors.overwrite_blocked_in_use`

> [!NOTE]
> **لماذا تكتب صنف PHP صغيرًا واحدًا؟**
> لا تستطيع حزمة LiveUX FileManagerBundle معرفة جداول قاعدة بيانات تطبيقك (`Product`, `BlogPost`, `Slider`, `Category` إلخ) أو أسماء أعمدتها. بدلًا من ذلك، توفّر لك عقدًا نظيفًا بدالة واحدة (`AssetUsageCheckerInterface`). كل ما عليك هو الاستعلام في جداولك وإرجاع المراجع المطابقة — وتتولى الحزمة تلقائيًا نافذة الواجهة، وتعقيم الروابط، وإزالة التكرارات، وفحص بادئات المجلدات، والفرض الأمني للعمليات الأربع على الخادم.

---

## 2. كيف تعمل الميزة خطوة بخطوة؟

```text
[ينقر المحرّر على "حذف"]
        │
        ▼
[1. يرسل المتصفح POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. يستدعي AssetUsageGuardService جميع خدمات AssetUsageCheckerInterface في تطبيقك]
        │
        ├── لم يُعثر على استخدامات ──► "غير مرتبط بأي سجلات ✓" -> تأكيد عادي
        │
        └── وُجدت استخدامات        ──► تعرض النافذة السجلات المطابقة + روابط "فتح السجل ↗":
                                        • policy: 'warn'  -> تحذير أصفر + زر "الحذف على أي حال"
                                        • policy: 'block' -> حظر أحمر (إخفاء زر الحذف)
                                                             + يرفض الخادم delete / rename / move / overwrite!
```

---

## 3. مثال متكامل من البداية إلى النهاية (جاهز للنسخ واللصق)

اتّبع هذه الخطوات الأربع لتشغيل **Usage Guard** في تطبيق Symfony + Doctrine (متوافق مع MySQL و MariaDB و PostgreSQL و SQLite) في أقل من 5 دقائق.

### الخطوة 1: تفعيل `usage_guard` في ملف التعريف

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'التخزين المحلي'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # تفعيل الميزة لملف التعريف هذا (الافتراضي: false)
                policy: 'block'           # 'warn' (تحذير + سماح بالتجاوز) | 'block' (منع صارم)
                enforce_server_side: true # true = فرض الحماية على الخادم لعمليات الحذف وإعادة التسمية والنقل والاستبدال (الافتراضي: true)
                max_references: 20        # الحد الأقصى للمراجع المعروضة في النافذة (1..200)
```

### الخطوة 2: مثال على كيان Doctrine (`Product`)

افترض أن كيان `Product` يحفظ صورة غلاف واحدة (`?string`) ومصفوفة معرض صور JSON (`list<string>`):

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

    // مثال: "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> مثال: ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### الخطوة 3: استعلامات مستودع محمولة عبر قواعد البيانات (`ProductRepository`)

تستخدم هذه الاستعلامات الصيغة القياسية المحمولة **`ESCAPE '!'`** لتعمل بالطريقة نفسها تمامًا عبر MySQL و MariaDB و PostgreSQL و SQLite:

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
     * لملف واحد: يعثر على المنتجات التي تشير إلى الرابط كصورة غلاف أو داخل معرض JSON.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // إحاطة الرابط بعلامتي تنصيص مزدوجتين ("/uploads/products/a.webp") تمنع التطابقات الجزئية الخاطئة في JSON
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
     * لمجلد: يعثر على المنتجات التي تشير إلى أي ملف داخل بادئة المجلد.
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

### الخطوة 4: تنفيذ فاحص الاستخدام الخاص بك (`ProductAssetUsageChecker`)

**لا حاجة لأي سطر في `services.yaml`!** لأن `AssetUsageCheckerInterface` تحمل السمة `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`، يكتشف Symfony فاحصك ويسجّله تلقائيًا.

> [!IMPORTANT]
> **أسماء الملفات التي تحتوي على مسافات أو أحرف غير لاتينية (مثل العربية):**
> ترمّز الدالة `DirectoryLister::buildPublicUrl()` كل مقطع في المسار باستخدام `rawurlencode()` (على سبيل المثال: `products/summer catalog.pdf` ← `/uploads/products/summer%20catalog.pdf`). استخدم الدالة المساعدة `buildPublicUrl()` أدناه لضمان مطابقة استعلاماتك للروابط المخزّنة بدقة (أما إذا كانت نماذجك تخزّن مسارات نسبية عبر `return_type: 'path'`، فطابق مقابل `ltrim($path, '/')` بدلًا من ذلك).

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
        // اختياري: قصر هذا الفاحص على ملفات تعريف محددة
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // مثال: "قارورة حرارية سوداء (#42)"
                type: 'منتج',                                               // تسمية الشارة (Badge)
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // رابط "فتح السجل ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // يُستخدم لإزالة التكرار
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

### الخطوة 5 (اختياري): إضافة المزيد من الفاحصات لجداول أخرى

أنشئ أصنافًا إضافية مركّزة تنفّذ `AssetUsageCheckerInterface` للافتات أو مقالات المدونة أو التصنيفات — وستجمع الحزمة كل النتائج وتزيل التكرارات تلقائيًا في نافذة واحدة:

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
                type: 'لافتة',
                editUrl: $this->urlGenerator->generate('admin_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. التحقق من صحة الإعداد

1. امسح ذاكرة التخزين المؤقت:
   ```bash
   php bin/console cache:clear
   ```
2. تأكّد من وسم خدمة الفاحص واكتشافها في حاوية الخدمات:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   يجب أن ترى `App\FileManager\ProductAssetUsageChecker` في المخرجات.
3. احفظ رابط صورة مُختارة من مدير الملفات في عمود `cover_image` لأحد المنتجات (مثل `/uploads/products/matte-black-thermos.webp`).
4. انقر بزر الفأرة الأيمن على تلك الصورة (أو على مجلدها الأب `products`) في مدير الملفات ثم انقر على **حذف**:
   - تُفتح النافذة وتعرض *«جارٍ فحص الاستخدامات…»* ثم تسرد **«قارورة حرارية سوداء (#42) [منتج] — فتح السجل ↗»**.
   - مع `policy: 'block'`، يُخفى زر الحذف، كما تُرفض أي محاولة لتنفيذ `rename` أو `move` أو `overwrite` على هذا الأصل من جهة الخادم أيضًا.

---

## 5. مقارنة `warn` مقابل `block` وملاحظات أمنية

| السياسة | سلوك نافذة الحذف | الفرض على الخادم (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(افتراضي)* | تعرض شريط تحذير أصفر مع المراجع المطابقة؛ ويحمل زر الإجراء النص **«الحذف على أي حال»**. | تسمح بالحذف وإعادة التسمية والنقل والاستبدال بمجرد تأكيد المحرّر. |
| **`block`** | تعرض شريط حظر أحمر مع المراجع المطابقة وروابط **«فتح السجل ↗»**؛ ويكون زر الحذف **مخفيًا**. | دفاع متعدد الطبقات: يرفض عمليات **`delete`** و **`rename`** و **`move`** و **`overwrite`** حتى لو تم استدعاء API مباشرةً. |

- **تعقيم الروابط (`editUrl`):** يُتحقق من صحة `editUrl` في كل من PHP (`AssetUsageGuardService::sanitizeEditUrl()`) وJavaScript (`isSafeUrl()`)، بحيث لا يُسمح إلا بالمسارات النسبية للجذر (`/admin/...`) أو عناوين `http(s)://...` (وتُرفض روابط `javascript:` و `data:` والروابط النسبية للبروتوكول مثل `//evil.com`).
- **الصلاحيات وحدود الطلبات (`checkUsage`):** نقطة النهاية `checkUsage` محمية بـ CSRF، وتفرض معًا صلاحيتي **`FILE_MANAGER_VIEW`** و **`FILE_MANAGER_DELETE`** عبر كل مسار مستهدف وجميع مجلداته الأبوية، وتقيّد الطلبات بحد أقصى **200 عنصر** (`MAX_BULK_DELETE_ITEMS`)، وتحظر المسارات المخفية ومسارات سلة المهملات (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **عند تعطيل تأكيد الحذف (`ui.confirm_delete: false`):** إذا كان `usage_guard.enabled: true`، يظل فحص الاستخدام يعمل في الخلفية وتُفتح نافذة التحذير/الحظر تلقائيًا كلما اكتُشفت مراجع نشطة.

---

[→ السابق: 09 — التكامل مع Symfony AI](09-symfony-ai-integration.md) | [الفهرس الرئيسي](README.md) | [التالي: 11 — دليل المساهمة وطلبات الدمج (PR) ←](11-contributing-and-pr-guide.md)
