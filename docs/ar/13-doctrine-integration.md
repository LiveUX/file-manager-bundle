# 13 — دليل التكامل مع Doctrine ORM و DBAL

[← السابق: 12 — مهارات وكلاء الذكاء الاصطناعي](12-ai-agent-skills-guide.md) | [فهرس التوثيق](README.md)

لا تفرض **LiveUX FileManagerBundle** أي اعتماد إجباري على Doctrine في نواتها الأساسية، لكنها توفر في الوقت نفسه تكاملاً من الدرجة الأولى لتطبيقات Symfony التي تستخدم Doctrine ORM أو DBAL عبر **4 مجالات رئيسية**:

1. **تخزين البيانات الوصفية للمجلدات والملفات في قاعدة بياناتك** (`defaults.metadata.driver: 'doctrine'` — تخزين DBAL مدمج دون كتابة أي شيفرة)
2. **ربط حقول كيانات Doctrine (Entities) بحقل `FileManagerPickerType`** (عمود مفرد، معرض JSON، وعلاقة `OneToMany` مع `CollectionType` / `LiveCollectionType`)
3. **بناء محرك تخزين بيانات وصفية مخصص باستخدام كيان Doctrine ORM خاص بك** (`FolderMetadataStorageInterface`)
4. **تحديث مسارات قاعدة البيانات تلقائياً عند إعادة تسمية الملفات أو المجلدات أو نقلها** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. تخزين البيانات الوصفية المدمج عبر Doctrine (`driver: 'doctrine'`)

### ببساطة: ماذا يفعل هذا الخيار؟

عندما تمنح مجلداً **لوناً** (مثل الأزرق) أو **أيقونة** أو **حالة تثبيت** — أو تضيف **نصاً بديلاً (`alt`)، أو عنواناً، أو وصفاً، أو وسوماً (`#campaign`)، أو نقطة تركيز (`focal point`)** لصورة ما — فإن هذه البيانات الوصفية لا تُكتب داخل الملفات نفسها، بل تُخزّن بشكل منفصل.

توفر الحزمة **محرّكين جاهزين للاستخدام لتخزين البيانات الوصفية**:
- **الخيار 1 — `driver: 'json_file'` (افتراضي، خادم واحد):** يخزّن البيانات الوصفية في ملف `var/file-manager/metadata.json` على القرص المحلي. لا يتطلب أي إعداد للتطبيقات الصغيرة التي تعمل على خادم واحد.
- **الخيار 2 — `driver: 'doctrine'` (قاعدة بيانات — `DoctrineDbalMetadataStorage`):** يخزّن البيانات الوصفية في جدول داخل قاعدة بيانات **MySQL أو MariaDB أو PostgreSQL أو SQLite**. **لا يتطلب إنشاء أي Entity أو Repository أو كتابة شيفرة مخصصة.**

**متى ينبغي اختيار `driver: 'doctrine'`؟**
- عندما يعمل تطبيقك عبر **عدة خوادم (Load Balancer / Docker Swarm / Kubernetes)** حيث لا يمكن مشاركة ملف JSON محلي، بينما تبقى قاعدة البيانات مركزية.
- عندما تريد تضمين جميع حالات التطبيق ضمن نسخة احتياطية واحدة لقاعدة البيانات.
- عندما تحتاج إلى إدارة عالية الأداء للبيانات الوصفية عبر آلاف الملفات والمجلدات دون أي تعارضات كتابة متزامنة.

### أبرز المزايا المعمارية لمحرك DBAL المدمج

- **انعدام استعلامات N+1 (`preload()`):** عند سرد المجلدات أو شجرة المجلدات، تُجلب المفاتيح على دفعات عبر استعلامات `SELECT` بحجم 400 مفتاح في كل دفعة. كما تمنع ذاكرة تخزين مؤقت محدودة الحجم في الذاكرة (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) مع `ResetInterface` حدوث تسرب للذاكرة في عمال FrankenPHP أو Messenger طويلي التشغيل.
- **عملية UPSERT ذرّية أصلية (Atomic Native UPSERT):** تستخدم عمليات الحفظ المتزامنة `ON CONFLICT (key_hash) DO UPDATE` في PostgreSQL/SQLite و`ON DUPLICATE KEY UPDATE` في MySQL/MariaDB — مما يقضي على حالات السباق (race conditions).
- **الكتابة على دفعات (`saveMany()`):** تنفّذ عمليات مثل نسخ المجلدات استعلامات `DELETE` مقسّمة (400 صف) + `INSERT` متعدد الصفوف (100 صف) داخل معاملة قاعدة بيانات واحدة (transaction).
- **فهرس B-Tree من نوع `text_pattern_ops` في PostgreSQL:** يُنشئ تلقائياً فهرساً تعبيرياً `LEFT(canonical_key, 191) text_pattern_ops` ويطبّق مرشحات نطاق `~>=~` / `~<=~` حتى تبقى استعلامات البادئة (prefix queries) مفهرسة بالكامل حتى تحت إعدادات glibc UTF-8 `LC_COLLATE`.
- **تحقق صارم من اسم الجدول:** لا يقبل الخيار `defaults.metadata.table` إلا معرّفات SQL آمنة تطابق النمط `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### جميع خيارات الإعداد

| الخيار (`defaults.metadata.*`) | القيمة الافتراضية | الوصف |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | اضبطه على `'doctrine'` لتفعيل `DoctrineDbalMetadataStorage` (`'json_file'`، `'doctrine'`، `'none'`). |
| `table` | `'liveux_fm_metadata'` | خصّص اسم جدول قاعدة البيانات كما تشاء (مثل `'media_metadata'`). |
| `connection` | `null` | إذا كنت تضبط عدة اتصالات DBAL في `config/packages/doctrine.yaml` (مثل `'media'`)، يستهدف ذلك الاتصال مباشرة. |
| `entity_manager` | `'default'` | مدير الكيانات (Entity Manager) الذي يُستخدم اتصال DBAL الخاص به عندما يكون `connection` بقيمة `null`. |
| `auto_create_table` | `true` | يُنشئ الجدول تلقائياً عند أول طلب إذا لم يكن موجوداً. اضبطه على `false` في بيئة الإنتاج إذا كنت تدير المخطط عبر **Doctrine Migrations**. |

### الخطوة 1: تفعيل `driver: 'doctrine'` في `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # يستخدم DoctrineDbalMetadataStorage المدمج بدلاً من json_file
            connection: null                # اختياري: اسم اتصال DBAL مخصص (مثل 'media')
            entity_manager: 'default'       # مدير الكيانات المستخدم عندما يكون connection بقيمة null
            table: 'liveux_fm_metadata'     # يمكنك تخصيص اسم الجدول بحرية
            auto_create_table: true         # اضبطه على false في الإنتاج عند استخدام Doctrine Migrations
```

### الخطوة 2 (موصى بها للإنتاج): ترحيل متوافق مع جميع المنصات (MySQL / MariaDB / PostgreSQL / SQLite)

لإدارة الجدول صراحةً عبر Doctrine Migrations في بيئة الإنتاج، اضبط `auto_create_table: false` واستخدم هذا الترحيل الواعي بنوع قاعدة البيانات (يُنشئ فهرس `text_pattern_ops` الوظيفي على PostgreSQL وفهرس البادئة `lengths: [191]` على MySQL/MariaDB/SQLite):

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
> **لماذا نستخدم `key_hash CHAR(64)` كمفتاح أساسي (Primary Key)؟** قد تصطدم مفاتيح فهارس MySQL `utf8mb4` InnoDB بحد 191 حرفاً مع المسارات الطويلة. يتيح استخدام بصمة SHA-256 كمفتاح أساسي دعم المسارات المتداخلة بعمق حتى 4,096 حرفاً — بما فيها أسماء الملفات العربية والتركية والصينية/اليابانية/الكورية (CJK) — بسلاسة تامة عبر MySQL وMariaDB وPostgreSQL وSQLite.

### الخطوة 3 (مثال): اسم جدول مخصص واتصال قاعدة بيانات مستقل

لتخزين البيانات الوصفية للوسائط في جدول `app_file_meta` على اتصال DBAL مستقل باسم `media`، يكفي تعديل 3 أسطر فقط:

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

## 2. نمذجة حقول الملفات في كيانات Doctrine (Entities)

### أ. صورة غلاف واحدة ومعرض صور متعدد بتنسيق JSON (الأكثر شيوعاً)

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

    // FileManagerPickerType (multiple: true) -> list<string> (مصفوفة JSON)
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

### ب. علاقة `OneToMany` مع `CollectionType` / `LiveCollectionType`

عندما يحمل كل عنصر عنوانه وترتيبه وصورته الخاصة (مثل `Slider` ← `SliderItem`):

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

على مستوى النماذج، أضف `FileManagerPickerType::class` داخل `SliderItemType` واربطه بالنموذج الأب باستخدام `CollectionType::class` أو `LiveCollectionType::class` مع `by_reference => false`. *(لأمثلة كاملة حول النماذج وLiveComponent، راجع [05 — حقول النماذج وتكامل المحررات](05-formtype-and-editor-integrations.md).)*

### هل تختار `return_type: 'url'` أم `'path'`؟

| الخيار | القيمة المخزّنة في قاعدة البيانات | الأنسب عندما |
| :--- | :--- | :--- |
| **`'url'`** *(افتراضي)* | `/uploads/products/thermos.webp` أو `https://cdn.example.com/thermos.webp` | تريد عرض `<img src="{{ product.coverImage }}">` مباشرة في القوالب دون إضافة بادئة. |
| **`'path'`** | `products/thermos.webp` *(المسار النسبي فقط)* | تخطط للانتقال لاحقاً من التخزين المحلي إلى S3/R2/CDN بتغيير `url_prefix` فقط، دون المساس بسجلات قاعدة البيانات. |

---

### ج. مثال عملي متكامل من البداية إلى النهاية: النموذج ← المتحكم ← قاعدة البيانات ← عرض الواجهة

فيما يلي دورة الحياة الكاملة في مكان واحد: **يختار المستخدم صورة غلاف ومعرضاً قابلاً للترتيب في نموذج ← يحفظهما المتحكم (Controller) عبر Doctrine ← تعرض صفحة تفاصيل المنتج الصور مع النص البديل لمحركات البحث (`alt`) ونقطة التركيز (`focalPoint`) المحفوظين في مدير الملفات.**

#### 1) نموذج Symfony (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'اسم المنتج'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'صورة الغلاف',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // يحفظ "/uploads/products/thermos.webp" في قاعدة البيانات
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'معرض المنتج (اسحب لإعادة الترتيب)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // يحفظ مصفوفة JSON: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) المتحكم (Controller) — الحفظ وقراءة بيانات SEO الوصفية (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // يتحقق FileManagerPickerType من القيم ويربطها بالكيان (Entity) تلقائياً:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'تم حفظ المنتج والصور في قاعدة البيانات بنجاح.');

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
        // اختياري: جلب نص alt ونقطة التركيز (focalPoint) المحفوظين لصورة الغلاف في مدير الملفات
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

#### 3) قالب نموذج لوحة التحكم (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>منتج جديد</h1>

    {# يتم ربط سمة النموذج (Form theme) و DialogHost تلقائياً بواسطة الحزمة #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">حفظ</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) قالب عرض تفاصيل المنتج في الواجهة (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# صورة الغلاف — باستخدام نقطة التركيز (focalPoint) ونص alt لـ SEO من مدير الملفات إن وُجدا #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# معرض الصور المتعدد (يُعرض بالترتيب الدقيق الذي اختاره المستخدم عبر السحب والإفلات!) #}
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
> **هل تريد تخزين بيانات اعتماد التخزين السحابي في قاعدة بيانات Doctrine أيضاً؟**
> راجع **[07 — التخزين السحابي والإعدادات الديناميكية](07-cloud-storage-and-dynamic-config.md)** (القسم 3) للاطلاع على شرح كامل لتخزين إعدادات AWS S3 أو Cloudflare R2 أو Supabase مشفّرة في قاعدة بياناتك وإدارتها من لوحة التحكم.

---

## 3. تخزين بيانات وصفية مخصص باستخدام كيان Doctrine ORM خاص بك (متقدم)

إذا أردت تخزين البيانات الوصفية في كيان ORM خاص بك بدلاً من `DoctrineDbalMetadataStorage` المدمج (مثلاً لإضافة عمود `updatedBy`)، فطبّق الواجهتين `FolderMetadataStorageInterface` و`ResetInterface` ثم عرّف اسماً مستعاراً (alias) لواجهة الخدمة:

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
        // يمنع تسرب الذاكرة والبيانات القديمة في عمال FrankenPHP / RoadRunner / Messenger
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
     * حفظ البيانات الوصفية على دفعات (تستخدمه MetadataSyncListener::copyFilteredFolderMetadata لمنع كتابات N+1).
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

            // حذف الصفوف القديمة وأي مدخلات فرعية قديمة في الوجهة بشكل ذرّي:
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
     * يميّز بين مفاتيح جذر وحدة التخزين ('local::') ومفاتيح المجلدات الفرعية ('local::products').
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

## 4. تحديث مراجع قاعدة البيانات تلقائياً عند إعادة التسمية أو النقل

عندما يعيد أحد المحررين تسمية ملف (مثل `products/old.webp`) أو مجلد كامل أو ينقله داخل مدير الملفات، يمكنك الاستماع للحدثين `ItemRenamedEvent` و`ItemMovedEvent` للحفاظ على مزامنة كلٍ من **`Product.coverImage`** ومصفوفة JSON **`Product.galleryImages`** تلقائياً:

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

## 5. الجمع مع حارس استخدام الأصول (Asset Usage Guard)

لمنع حذف الملفات المُشار إليها في جداول Doctrine أو إعادة تسميتها أو نقلها أو استبدالها عن طريق الخطأ، اربط الواجهة `AssetUsageCheckerInterface` مباشرة مع `ProductRepository` — راجع **[10 — حارس استخدام الأصول](10-asset-usage-guard.md)** للحصول على تطبيق كامل خطوة بخطوة.

---

[← السابق: 12 — مهارات وكلاء الذكاء الاصطناعي](12-ai-agent-skills-guide.md) | [فهرس التوثيق](README.md)
