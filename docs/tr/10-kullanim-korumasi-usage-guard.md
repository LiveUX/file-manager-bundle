# 10 — Dosya Kullanım Koruması (Usage Guard)

[← Önceki: 09 — Symfony AI Entegrasyonu](09-symfony-ai-entegrasyonu.md) | [Ana İndeks](README.md) | [Sonraki: 11 — Katkı & PR Rehberi →](11-katki-ve-pr-rehberi.md)

---

## 1. Bu Özellik Ne İşe Yarar? (Gerçek Hayat Senaryosu)

Bir e-ticaret veya CMS panelinde editörünüz `uploads/urunler/termos-siyah.webp` dosyasını (veya tüm `uploads/urunler` klasörünü) **silmek, yeniden adlandırmak, başka klasöre taşımak veya üzerine yeni dosya yazmak** istiyor.

- **Usage Guard kapalıysa:** Dosya hemen silinir/taşınır; ama o dosya 3 ürünün kapak görselinde ve 1 ana sayfa banner'ında kullanılıyorsa web sitenizde **kırık görseller (`404`)** oluşur!
- **Usage Guard açıksa:** Dosya yöneticisi sizin projenize sorar: *"Bu dosya/klasör veritabanında bir yerde kullanılıyor mu?"*
  - Kullanılıyorsa silme penceresinde **hangi ürün / banner / sayfada kullanıldığını** ve yanında tıklanabilir **"Kaydı Aç ↗"** linkini listeler.
  - **`policy: 'warn'`** seçtiyseniz sarı uyarı gösterir ve **"Yine de Sil"** butonu verir.
  - **`policy: 'block'`** ve **`enforce_server_side: true`** (varsayılan) seçtiyseniz kırmızı engel kutusu gösterir, Sil butonunu **gizler** ve sunucu tarafında **4 kritik işlemi birden** reddeder:
    1. **Silme (`delete`)** → `errors.delete_blocked_in_use`
    2. **Yeniden Adlandırma (`rename`)** → `errors.rename_blocked_in_use`
    3. **Taşıma (`move`)** → `errors.move_blocked_in_use`
    4. **Üzerine Yazma (`overwrite`)** — yükleme (`overwrite_policy: 'overwrite'`), Görsel Düzenleyici ve ZIP çıkarma dahil → `errors.overwrite_blocked_in_use`

> **Neden siz bir PHP sınıfı yazıyorsunuz?**  
> Çünkü LiveUX FileManagerBundle sizin veritabanı tablolarınızı (`Product`, `BlogPost`, `Slider`, `Category` vb.) ve sütun adlarınızı bilemez. Bundle size tek metodlu çok basit bir sözleşme (`AssetUsageCheckerInterface`) verir; siz kendi tablonuzda o dosya yolunu arayıp listeyi döndürürsünüz — arayüz, güvenlik, dedup, klasör kontrolü ve 4 işlemlik sunucu engellemesi tamamen bundle tarafından otomatik yapılır.

---

## 2. Adım Adım Çalışma Akışı

```text
[Editör "Sil"e tıklar]
        │
        ▼
[1. Tarayıcı POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> Projenizdeki tüm AssetUsageCheckerInterface sınıflarını çağırır]
        │
        ├── Kullanım YOKSA ──► "Hiçbir kayıtla ilişkili değil ✓" -> Normal onay
        │
        └── Kullanım VARSA ──► Modalda kayıt listesi + "Kaydı Aç ↗" linkleri:
                                 • policy: 'warn'  -> Sarı uyarı + "Yine de Sil"
                                 • policy: 'block' -> Kırmızı engel (Sil butonu gizlenir)
                                                      + Sunucuda delete / rename / move / overwrite reddedilir!
```

---

## 3. Tam Çalışan Uçtan Uca Örnek (Kopyala-Yapıştır)

Aşağıdaki 4 adım, sıfırdan bir Symfony + Doctrine projesinde (MySQL, MariaDB, PostgreSQL ve SQLite uyumlu!) **Usage Guard**'ı 5 dakikada ayağa kaldırmak için gereken her şeyi eksiksiz gösterir.

### Adım 1: Profilde `usage_guard` Ayarını Açın

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Yerel Depolama'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true           # Özelliği bu profilde aktif et (varsayılan: false)
                policy: 'block'         # 'warn' (uyar + devam et) | 'block' (tamamen engelle)
                enforce_server_side: true # true = delete, rename, move ve overwrite işlemlerini sunucuda da engelle (varsayılan: true)
                max_references: 20      # Modalda en fazla kaç kayıt listelensin (1..200)
```

### Adım 2: Örnek Doctrine Entity (`Product`)

Veritabanınızda tekli kapak görseli (`?string`) ve çoklu galeri (`json` dizi) tuttuğunuzu varsayalım:

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

    // Örn: "/uploads/urunler/termos-siyah.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> Örn: ["/uploads/urunler/termos-1.webp", "/uploads/urunler/termos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Adım 3: Tüm Veritabanlarıyla Uyumlu Repository Sorguları (`ProductRepository`)

Aşağıdaki sorgular taşınabilir **`ESCAPE '!'`** söz dizimini kullanır; MySQL, MariaDB, PostgreSQL ve SQLite üzerinde aynı şekilde çalışır:

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
     * Tek bir dosya için: kapak görseli veya JSON galeride geçen ürünleri bulur.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // JSON içinde çift tırnakla ("/uploads/urunler/a.webp") aramak yanlış eşleşmeyi önler
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
     * Bir klasör için: o klasör altındaki herhangi bir dosyayı kullanan ürünleri bulur.
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

### Adım 4: Checker Sınıfını Yazın (`ProductAssetUsageChecker`)

**Hiçbir `services.yaml` kaydı gerekmez!** `AssetUsageCheckerInterface` üzerindeki `#[AutoconfigureTag('liveux_file_manager.usage_checker')]` sayesinde Symfony bu sınıfı otomatik bulup bağlar.

> **Önemli (Boşluklu ve Çok-Dilli Dosya Adları):**  
> `DirectoryLister::buildPublicUrl()`, public URL üretirken her yol segmentini `rawurlencode()` ile kodlar (ör. `urunler/yaz kataloğu.pdf` → `/uploads/urunler/yaz%20katalo%C4%9Fu.pdf`). Veritabanındaki URL'lerle birebir eşleşmesi için aşağıdaki `buildPublicUrl()` yardımcı metodunu kullanın (`return_type: 'path'` kullanıyorsanız doğrudan `ltrim($path, '/')` ile eşleştirin).

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
        // İsterseniz sadece belirli profillerde çalıştırın:
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // Örn: "Mat Siyah Termos (#42)"
                type: 'Ürün',                                               // Rozet etiketi
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('panel_urun_duzenle', ['id' => $id]) // Tıklanabilir "Kaydı Aç ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Tekilleştirme (dedup) ID'si
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

### Adım 5 (Opsiyonel): Birden Fazla Tablo İçin İkinci Checker Ekleyin

Banner, Blog veya Kategori tablolarınız için aynı arayüzü implement eden ayrı küçük sınıflar açabilirsiniz — hepsi otomatik toplanır ve tek modalda birleştirilir:

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
                editUrl: $this->urlGenerator->generate('panel_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. Kurulumun Çalıştığını Nasıl Test Edersiniz?

1. `php bin/console cache:clear` çalıştırın.
2. Symfony servisinizin gerçekten etiketlendiğini tek komutla doğrulayın:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Çıktıda `App\FileManager\ProductAssetUsageChecker` sınıfını görmelisiniz.
3. Bir ürünün `cover_image` alanına dosya yöneticisinden seçtiğiniz bir görselin URL'ini kaydedin (ör. `/uploads/urunler/termos-siyah.webp`).
4. Dosya yöneticisinde o görsele (veya içinde bulunduğu `urunler` klasörüne) sağ tıklayıp **Sil** deyin:
   - Modal açılır, *"Kullanım yerleri kontrol ediliyor…"* göstergesinin ardından **"Mat Siyah Termos (#42) [Ürün] — Kaydı Aç ↗"** satırı listelenir.
   - `policy: 'block'` seçiliyse Sil butonu gizlenir; ayrıca o dosyanın adını değiştirmeye (`rename`), başka klasöre taşımaya (`move`) veya üzerine yeni dosya yazmaya (`overwrite`) çalışsanız bile sunucu işlemi reddeder.

---

## 5. `warn` vs `block` Karşılaştırması & Güvenlik Notları

| Politika | Silme Modalı Davranışı | Sunucu Koruması (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(varsayılan)* | Sarı uyarı kutusuyla kullanım listesini gösterir; buton **"Yine de Sil"** olur. | Editör onaylarsa silme/ad değiştirme/taşıma/üzerine yazmaya izin verir. |
| **`block`** | Kırmızı engel kutusuyla kullanım listesini ve **"Kaydı Aç ↗"** linklerini gösterir; Sil butonu **gizlenir**. | Çift katmanlı koruma: API doğrudan çağrılsa bile **`delete`**, **`rename`**, **`move`** ve **`overwrite`** işlemlerini reddeder. |

- **Güvenlik (`editUrl`):** `editUrl` hem PHP (`AssetUsageGuardService::sanitizeEditUrl()`) hem JavaScript (`isSafeUrl()`) tarafında doğrulanır; yalnızca `/panel/...` veya `http(s)://...` adreslerine izin verilir (`javascript:`, `data:`, `//evil.com` elenir).
- **Yetki & Sınırlar (`checkUsage`):** `checkUsage` aksiyonu CSRF korumalıdır, her öğe ve tüm üst klasörleri için hem **`FILE_MANAGER_VIEW`** hem **`FILE_MANAGER_DELETE`** yetkisini doğrular, tek istekte en fazla **200 öğe** kabul eder (`MAX_BULK_DELETE_ITEMS`) ve gizli/çöp yollarına (`assertAccessiblePath`, `assertNotTrashAncestor`) izin vermez.
- **Onay penceresi kapalıysa (`ui.confirm_delete: false`):** `usage_guard.enabled: true` ise arka planda yine de kontrol yapılır; yalnızca aktif kullanım tespit edilirse uyarı/engel penceresi açılır.

---

[← Önceki: 09 — Symfony AI Entegrasyonu](09-symfony-ai-entegrasyonu.md) | [Ana İndeks](README.md) | [Sonraki: 11 — Katkı & PR Rehberi →](11-katki-ve-pr-rehberi.md)
