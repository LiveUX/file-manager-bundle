# 13 — Doctrine ORM & DBAL Entegrasyon Rehberi

[← Önceki: 12 — Yapay Zeka Agent Skills](12-yapay-zeka-skills-rehberi.md) | [Ana İndeks](README.md)

**LiveUX FileManagerBundle**, çekirdeğinde hiçbir zorunlu Doctrine bağımlılığı taşımaz; ancak Doctrine ORM / DBAL kullanan Symfony projelerinde **4 farklı noktada** birinci sınıf entegrasyon sunar:

1. **Klasör ve dosya metadatasını veritabanında saklama** (`defaults.metadata.driver: 'doctrine'` — yerleşik sıfır-kod DBAL deposu)
2. **Doctrine Entity alanlarını `FileManagerPickerType` ile bağlama** (Tekli sütun, JSON galeri, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Kendi özel Doctrine ORM Entity'nizle metadata deposu yazma** (`FolderMetadataStorageInterface`)
4. **Dosya yeniden adlandırıldığında / taşındığında DB yollarını otomatik güncelleme** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Yerleşik Doctrine Metadata Deposu (`driver: 'doctrine'`)

### En Basit Haliyle: Bu Ne İşe Yarar?

Dosya yöneticisinde bir klasöre **renk** (ör. mavi), **ikon**, **sabitleme (pin)** verdiğinizde veya bir görsele **alt metin, başlık, açıklama, etiket (`#kampanya`) ve odak noktası** eklediğinizde, bu bilgiler dosyaların kendi içine yazılmaz — ayrı bir yerde saklanır.

Bundle size bu bilgileri saklamak için **2 hazır seçenek** sunar:
- **Seçenek 1 — `driver: 'json_file'` (Varsayılan, Tek Sunucu):** Bilgiler sunucudaki `var/file-manager/metadata.json` dosyasında tutulur. Küçük ve tek sunuculu projeler için kurulum gerektirmez.
- **Seçenek 2 — `driver: 'doctrine'` (Veritabanı — `DoctrineDbalMetadataStorage`):** Bilgiler **MySQL, MariaDB, PostgreSQL veya SQLite** veritabanınızda bir tabloda tutulur. **Hiçbir Entity, Repository veya ekstra kod yazmanıza gerek yoktur.**

**Ne zaman `driver: 'doctrine'` seçmelisiniz?**
- Uygulamanız **birden fazla sunucuda (Load Balancer / Docker Swarm / Kubernetes)** çalışıyorsa (yerel JSON dosyası sunucular arasında paylaşılmaz, veritabanı ise ortaktır).
- Tüm verilerin tek bir veritabanı yedeğinde toplanmasını istiyorsanız.
- Binlerce dosya/klasör metadatasını yüksek performansla ve eşzamanlı yazma çakışması olmadan yönetmek istiyorsanız.

### Yerleşik DBAL Mimarisinin Öne Çıkan Özellikleri
- **Sıfır N+1 (`preload()`):** Dizin veya ağaç yüklenirken anahtarlar 400'erlik gruplarla toplu `SELECT` edilir; sınırlı bellek içi önbellek (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) ve `ResetInterface` sayesinde FrankenPHP / Messenger worker'larında bellek sızıntısı olmaz.
- **Atomik Yerel UPSERT:** Eşzamanlı kaydetmelerde PostgreSQL ve SQLite'ta `ON CONFLICT (key_hash) DO UPDATE`, MySQL/MariaDB'de `ON DUPLICATE KEY UPDATE` kullanır — yarış koşulu (race condition) oluşmaz.
- **Toplu İşlem (`saveMany()`):** Klasör kopyalama gibi işlemlerde 400'lük `DELETE` + 100'lük çoklu `INSERT` parçaları tek veritabanı transaction'ında yürütülür.
- **PostgreSQL `text_pattern_ops` B-Tree İndeksi:** PostgreSQL'de glibc UTF-8 `LC_COLLATE` altında bile prefix sorgularının tam indeks kullanabilmesi için `LEFT(canonical_key, 191) text_pattern_ops` ifadesi ve `~>=~` / `~<=~` aralık süzgeci otomatik uygulanır.
- **Katı Tablo Adı Doğrulaması:** `defaults.metadata.table` yalnızca `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/` biçiminde güvenli SQL tanımlayıcılarını kabul eder.

### Tüm Özelleştirme Seçenekleri

| Ayar (`defaults.metadata.*`) | Varsayılan | Ne İşe Yarar? |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | `'doctrine'` yapıldığında `DoctrineDbalMetadataStorage` devreye girer (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Veritabanında kullanılacak tablo adını dilediğiniz gibi değiştirebilirsiniz (ör. `'medya_metadata'`). |
| `connection` | `null` | `config/packages/doctrine.yaml` içinde birden fazla DBAL bağlantınız varsa (ör. `'media'`) doğrudan o bağlantıyı seçer. |
| `entity_manager` | `'default'` | `connection` boşsa ilgili Entity Manager'ın bağlantısını kullanır. |
| `auto_create_table` | `true` | `true` iken tablo yoksa ilk istekte otomatik oluşturur. Canlı (production) ortamda tabloyu **Doctrine Migration** ile yönetmek istiyorsanız `false` yapın. |

### Adım 1: `liveux_file_manager.yaml` İçinde `driver: 'doctrine'` Seçin

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # json_file yerine yerleşik DoctrineDbalMetadataStorage kullanılır
            connection: null                # İsteğe bağlı: özel DBAL bağlantı adı (ör. 'media')
            entity_manager: 'default'       # connection boşsa kullanılan Entity Manager adı
            table: 'liveux_fm_metadata'     # Tablo adını istediğiniz gibi özelleştirebilirsiniz
            auto_create_table: true         # Canlıda migration kullanacaksanız false yapın
```

### Adım 2 (Canlı Ortam Önerisi): MySQL / MariaDB / PostgreSQL / SQLite Uyumlu Migration

Canlı ortamda `auto_create_table: false` yapıp tablonun kontrolünü Doctrine Migrations'a vermek isterseniz aşağıdaki platform-uyumlu migration dosyasını kullanın (PostgreSQL'de `text_pattern_ops` fonksiyonel indeksini, MySQL/MariaDB/SQLite'ta `lengths: [191]` indeksini otomatik oluşturur):

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

> **Neden `key_hash CHAR(64)` Primary Key?** MySQL `utf8mb4` InnoDB indeksleri uzun yollarda 191 karakter sınırına takılabilir. SHA-256 hash birincil anahtarı sayesinde 4096 karaktere kadar derin klasör yolları ve Türkçe/CJK/Arapça dosya adları MySQL, MariaDB, PostgreSQL ve SQLite'ta sıfır sorunla çalışır.

### Adım 3 (Örnek): Özel Tablo Adı ve Ayrı Veritabanı Bağlantısı

Medya kayıtlarını ana veritabanı yerine `media` bağlantısındaki `app_dosya_meta` tablosunda tutmak isterseniz yalnızca 3 satır değiştirmeniz yeterlidir:

```yaml
liveux_file_manager:
    defaults:
        metadata:
            driver: 'doctrine'
            connection: 'media'
            table: 'app_dosya_meta'
            auto_create_table: false
```

---

## 2. Doctrine Entity'lerinde Dosya Alanlarını Modelleme

### A. Tekli Görsel & Çoklu JSON Galeri (En Yaygın Kullanım)

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

    // FileManagerPickerType (multiple: true) -> list<string> (JSON dizi)
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

### B. İlişkisel `OneToMany` + `CollectionType` / `LiveCollectionType`

Her satırın kendi başlığı, sıralaması ve görseli varsa (ör. `Slider` → `SliderItem`):

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

Form tarafında `SliderItemType` içinde `FileManagerPickerType::class` kullanıp ana formda `CollectionType::class` veya `LiveCollectionType::class` (`by_reference => false`) bağlamanız yeterlidir. *(Tam form & LiveComponent örnekleri için bkz. [05 — FormType & LiveCollectionType](05-formtype-ve-editor-entegrasyonu.md).)*

### `return_type: 'url'` mı `'path'` mı Seçmelisiniz?

| Seçenek | Veritabanına Ne Kaydedilir? | Ne Zaman Tercih Edilir? |
| :--- | :--- | :--- |
| **`'url'`** *(varsayılan)* | `/uploads/urunler/termos.webp` veya `https://cdn.site.com/termos.webp` | Şablonlarda doğrudan `<img src="{{ product.coverImage }}">` yazmak istediğinizde |
| **`'path'`** | `urunler/termos.webp` *(yalnızca göreli yol)* | İleride yerel diskten S3/R2/CDN'e geçtiğinizde DB kayıtlarını değiştirmeden sadece `url_prefix` değiştirmek istediğinizde |

---

### C. A'dan Z'ye Tam Çalışan Örnek: Form → Controller → Veritabanına Kayıt → Önyüzde Gösterme

Hiç bilmeyen bir geliştiricinin tek bakışta tüm yaşam döngüsünü görebilmesi için: **kullanıcı formdan kapak + çoklu galeri seçer → Controller Doctrine ile veritabanına kaydeder → ürün detay sayfası görselleri dosya yöneticisinde girilen SEO `alt` ve odak noktası (`focalPoint`) ile birlikte gösterir.**

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
            ->add('name', TextType::class, ['label' => 'Ürün Adı'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Kapak Görseli',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // DB'ye "/uploads/urunler/termos.webp" yazar
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Ürün Galerisi (Sürükleyerek Sıralanabilir)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // DB'ye JSON array yazar: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controller — Kaydetme ve SEO Metadata (`alt` / `focalPoint`) ile Okuma (`src/Controller/ProductController.php`)
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
    #[Route('/admin/urun/yeni', name: 'admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // FileManagerPickerType veriyi otomatik doğrular ve Entity'ye yazar:
            //   $product->getCoverImage()    => "/uploads/urunler/termos.webp"
            //   $product->getGalleryImages() => ["/uploads/urunler/1.webp", "/uploads/urunler/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Ürün ve görseller veritabanına kaydedildi.');

            return $this->redirectToRoute('product_show', ['id' => $product->getId()]);
        }

        return $this->render('admin/product/form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/urun/{id}', name: 'product_show', methods: ['GET'])]
    public function show(
        Product $product,
        ConfigProviderInterface $configProvider,
        VolumeRegistry $volumeRegistry,
        PathNormalizer $pathNormalizer,
        FolderMetadataStorageInterface $metadataStorage,
    ): Response {
        // Opsiyonel: Kapak görselinin dosya yöneticisinde girilen alt metnini ve odak noktasını oku
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

#### 3) Admin Form Şablonu (`templates/admin/product/form.html.twig`)
```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Yeni Ürün</h1>

    {# Form teması ve DialogHost bundle tarafından otomatik bağlanır #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Kaydet</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Müşteri Önyüzü Şablonu (`templates/product/show.html.twig`)
```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Kapak görseli — varsa dosya yöneticisindeki odak noktası (focalPoint) ve SEO alt metniyle #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Çoklu galeri görselleri (kullanıcının sürükleyip belirlediği sırada!) #}
    {% if product.galleryImages is not empty %}
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 16px;">
            {% for imageUrl in product.galleryImages %}
                <img src="{{ imageUrl }}" alt="{{ product.name }}" loading="lazy" style="width: 100%; border-radius: 8px;">
            {% endfor %}
        </div>
    {% endif %}
{% endblock %}
```

> **Bulut Depolama Ayarlarını da Doctrine Veritabanında Saklamak İsterseniz:**  
> Yönetici panelinden şifreli AWS S3 / Cloudflare R2 / Supabase ayarları girip dinamik volume oluşturmanın adım adım tam örneği için **[07 — Bölüm 3: Bulut Ayarlarını Admin Panelinden Yönetme](07-depolama-s3-ve-dinamik-yapilandirma.md#3-bulut-ayarlarini-env-yerine-admin-panelinden-veritabanindan-yonetme--adim-adim-tam-rehber)** rehberine bakın.

---

## 3. Tam Özel Doctrine ORM Entity ile Metadata Saklama (İleri Düzey)

Yerleşik `DoctrineDbalMetadataStorage` yerine metadata kayıtlarını kendi ORM Entity'nizde (ör. ek `updatedBy` sütunuyla) tutmak isterseniz `FolderMetadataStorageInterface` ve `ResetInterface` arayüzlerini birlikte implemente edip servis alias'ını değiştirin:

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
        // FrankenPHP / RoadRunner / Messenger worker'larında bellek sızıntısını ve bayat veriyi önler
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
     * Toplu metadata kaydı (MetadataSyncListener::copyFilteredFolderMetadata tarafından N+1 önlemek için kullanılır).
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

            // Eski satırları ve hedefteki olası bayat alt kayıtları atomik olarak temizle:
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
     * Kök volume anahtarlarını ('local::') alt klasörlerden ('local::urunler') doğru ayırır.
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

## 4. Dosya Yeniden Adlandırılınca / Taşınınca Veritabanını Otomatik Güncelleme

Bir editör dosya yöneticisinde `urunler/eski.webp` dosyasını (veya bir klasörü) yeniden adlandırdığında ya da taşıdığında, veritabanındaki hem **`Product.coverImage`** sütununun hem de JSON **`Product.galleryImages`** dizisinin kırılmaması için `ItemRenamedEvent` ve `ItemMovedEvent` olaylarını dinleyebilirsiniz:

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

## 5. Kullanım Koruması (`Usage Guard`) ile Birlikte Kullanım

Doctrine tablolarınızda kullanılan dosyaların yanlışlıkla silinmesini, yeniden adlandırılmasını, taşınmasını veya üzerine yazılmasını önlemek için `AssetUsageCheckerInterface` ile doğrudan `ProductRepository` bağlantısı kurabilirsiniz — adım adım tam örnek için **[10 — Dosya Kullanım Koruması (Usage Guard)](10-kullanim-korumasi-usage-guard.md)** rehberine bakın.

---

[← Önceki: 12 — Yapay Zeka Agent Skills](12-yapay-zeka-skills-rehberi.md) | [Ana İndeks](README.md)
