# 10 — Ochrona używanych zasobów (Asset Usage Guard)

[← Poprzedni: 09 — Integracja z Symfony AI](09-symfony-ai-integration.md) | [Spis treści](README.md) | [Następny: 11 — Przewodnik współtworzenia i Pull Requestów →](11-contributing-and-pr-guide.md)

---

## 1. Jaki problem rozwiązuje? (Scenariusz z życia)

Wyobraź sobie, że redaktor w panelu sklepu internetowego lub CMS próbuje **usunąć, zmienić nazwę, przenieść lub nadpisać** plik `uploads/products/matte-black-thermos.webp` (albo cały folder `uploads/products`).

- **Bez Usage Guard:** plik zostaje natychmiast usunięty lub przeniesiony — a jeśli odwołują się do niego 3 karty produktów i 1 baner na stronie głównej, na działającej stronie pojawiają się **uszkodzone obrazy `404`**.
- **Z włączonym Usage Guard:** menedżer plików pyta Twoją aplikację: *„Czy ten plik lub folder jest aktualnie używany w bazie danych?”*
  - Jeśli istnieją powiązania, okno potwierdzenia wyświetla **listę wszystkich produktów, banerów lub podstron korzystających z tego zasobu**, wraz z klikalnym linkiem **„Otwórz rekord ↗”**.
  - Przy **`policy: 'warn'`** wyświetlany jest żółty komunikat ostrzegawczy i przycisk **„Usuń mimo to”**.
  - Przy **`policy: 'block'`** oraz **`enforce_server_side: true`** (wartość domyślna) wyświetlany jest czerwony komunikat blokujący, przycisk Usuń zostaje **ukryty**, a serwer odrzuca **wszystkie 4 operacje destrukcyjne**:
    1. **Usunięcie (`delete`)** → `errors.delete_blocked_in_use`
    2. **Zmiana nazwy (`rename`)** → `errors.rename_blocked_in_use`
    3. **Przeniesienie (`move`)** → `errors.move_blocked_in_use`
    4. **Nadpisanie (`overwrite`)** — w tym przesyłanie plików (`overwrite_policy: 'overwrite'`), Edytor obrazów i rozpakowywanie archiwów ZIP → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Dlaczego implementujesz jedną małą klasę PHP?**
> LiveUX FileManagerBundle nie może znać tabel bazodanowych Twojej aplikacji (`Product`, `BlogPost`, `Slider`, `Category` itd.) ani nazw ich kolumn. Zamiast tego udostępnia czysty, jednometodowy kontrakt (`AssetUsageCheckerInterface`). Ty odpytujesz swoje tabele i zwracasz pasujące odwołania — a pakiet automatycznie zajmuje się oknem modalnym w UI, sanityzacją adresów URL, deduplikacją, sprawdzaniem prefiksów folderów i egzekwowaniem blokady po stronie serwera dla wszystkich 4 operacji.

---

## 2. Jak to działa krok po kroku

```text
[Redaktor klika „Usuń”]
        │
        ▼
[1. Przeglądarka wysyła POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> wywołuje każdą usługę AssetUsageCheckerInterface w Twojej aplikacji]
        │
        ├── BRAK użyć     ──► „Nie znaleziono aktywnych odwołań do tego elementu.” -> Zwykłe potwierdzenie
        │
        └── ZNALEZIONO    ──► Okno modalne wyświetla powiązane rekordy + linki „Otwórz rekord ↗”:
                                • policy: 'warn'  -> Żółte ostrzeżenie + „Usuń mimo to”
                                • policy: 'block' -> Czerwona blokada (przycisk Usuń ukryty)
                                                     + Serwer odrzuca delete / rename / move / overwrite!
```

---

## 3. Kompletny przykład end-to-end (gotowy do skopiowania)

Wykonaj te 4 kroki, aby uruchomić **Usage Guard** w aplikacji Symfony + Doctrine (zgodnej z MySQL, MariaDB, PostgreSQL i SQLite) w mniej niż 5 minut.

### Krok 1: Włącz `usage_guard` na swoim profilu

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Pamięć lokalna'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Włącz dla tego profilu (domyślnie: false)
                policy: 'block'           # 'warn' (ostrzegaj + pozwól kontynuować) | 'block' (twarda blokada)
                enforce_server_side: true # true = egzekwuj również delete, rename, move i overwrite po stronie serwera (domyślnie: true)
                max_references: 20        # Maks. liczba odwołań pokazywana w oknie modalnym (1..200)
```

### Krok 2: Przykładowa encja Doctrine (`Product`)

Załóżmy, że encja `Product` przechowuje pojedyncze zdjęcie główne (`?string`) oraz tablicę JSON galerii (`list<string>`):

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

    // np. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> np. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Krok 3: Przenośne zapytania w repozytorium (`ProductRepository`)

Poniższe zapytania używają przenośnej składni **`ESCAPE '!'`**, dzięki czemu działają identycznie w MySQL, MariaDB, PostgreSQL oraz SQLite:

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
     * Dla pojedynczego pliku: wyszukuje produkty odwołujące się do URL w zdjęciu głównym lub galerii JSON.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Otoczenie podwójnym cudzysłowem ("/uploads/products/a.webp") zapobiega fałszywym trafieniom częściowym w JSON
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
     * Dla folderu: wyszukuje produkty odwołujące się do dowolnego pliku w ramach prefiksu folderu.
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

### Krok 4: Zaimplementuj własny checker użycia (`ProductAssetUsageChecker`)

**Nie musisz dopisywać nic w `services.yaml`!** Ponieważ interfejs `AssetUsageCheckerInterface` posiada atrybut `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`, Symfony wykrywa i rejestruje Twój checker automatycznie.

> [!IMPORTANT]
> **Nazwy plików ze spacjami lub znakami spoza ASCII:**
> `DirectoryLister::buildPublicUrl()` koduje każdy segment ścieżki funkcją `rawurlencode()` (np. `products/katalog letni.pdf` → `/uploads/products/katalog%20letni.pdf`). Korzystaj z poniższej metody pomocniczej `buildPublicUrl()`, aby zapytania dokładnie pasowały do zapisanych adresów URL (jeśli Twoje formularze zapisują ścieżki względne z `return_type: 'path'`, porównuj zamiast tego z `ltrim($path, '/')`).

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
        // Opcjonalnie: ogranicz ten checker do wybranych profili
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // np. "Matowy czarny termos (#42)"
                type: 'Produkt',                                            // Etykieta odznaki (badge)
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Klikalny link „Otwórz rekord ↗”
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Używane do deduplikacji
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

### Krok 5 (opcjonalny): Dodaj kolejne checkery dla pozostałych tabel

Utwórz dodatkowe, wyspecjalizowane klasy implementujące `AssetUsageCheckerInterface` dla banerów, wpisów blogowych lub kategorii — pakiet automatycznie agreguje i deduplikuje wszystkie wyniki w jednym oknie modalnym:

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
                type: 'Baner',
                editUrl: $this->urlGenerator->generate('admin_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. Weryfikacja konfiguracji

1. Wyczyść pamięć podręczną:
   ```bash
   php bin/console cache:clear
   ```
2. Potwierdź, że usługa checkera została otagowana i wykryta:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Na liście wyników powinna pojawić się klasa `App\FileManager\ProductAssetUsageChecker`.
3. Zapisz adres URL obrazu wybranego z menedżera plików w kolumnie `cover_image` produktu (np. `/uploads/products/matte-black-thermos.webp`).
4. Kliknij ten obraz (lub jego folder nadrzędny `products`) prawym przyciskiem w menedżerze plików i wybierz **Usuń**:
   - Otwiera się okno modalne, wyświetla *„Sprawdzanie aktywnych użyć…”*, a następnie listę: **„Matowy czarny termos (#42) [Produkt] — Otwórz rekord ↗”**.
   - Przy `policy: 'block'` przycisk Usuń jest ukryty, a każda próba wykonania `rename`, `move` lub `overwrite` na tym zasobie zostanie również odrzucona po stronie serwera.

---

## 5. Porównanie `warn` vs `block` oraz uwagi bezpieczeństwa

| Polityka | Zachowanie okna usuwania | Egzekwowanie po stronie serwera (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(domyślna)* | Wyświetla żółty baner ostrzegawczy z listą pasujących odwołań; przycisk akcji ma etykietę **„Usuń mimo to”**. | Zezwala na usunięcie, zmianę nazwy, przeniesienie i nadpisanie po potwierdzeniu przez redaktora. |
| **`block`** | Wyświetla czerwony baner blokujący z listą pasujących odwołań i linkami **„Otwórz rekord ↗”**; przycisk Usuń jest **ukryty**. | Obrona wielowarstwowa (defense in depth): odrzuca **`delete`**, **`rename`**, **`move`** oraz **`overwrite`** nawet przy bezpośrednim wywołaniu API. |

- **Sanityzacja linków (`editUrl`):** wartość `editUrl` jest walidowana zarówno w PHP (`AssetUsageGuardService::sanitizeEditUrl()`), jak i w JavaScript (`isSafeUrl()`), dopuszczając wyłącznie ścieżki względne od katalogu głównego (`/admin/...`) lub adresy `http(s)://...` (adresy `javascript:`, `data:` oraz protocol-relative `//evil.com` są odrzucane).
- **Uprawnienia i limity żądań (`checkUsage`):** endpoint `checkUsage` jest chroniony tokenem CSRF, weryfikuje oba uprawnienia **`FILE_MANAGER_VIEW`** oraz **`FILE_MANAGER_DELETE`** dla każdej docelowej ścieżki i wszystkich jej folderów nadrzędnych, ogranicza żądania do **200 elementów** (`MAX_BULK_DELETE_ITEMS`) oraz blokuje ukryte ścieżki i ścieżki Kosza (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Gdy potwierdzenie usunięcia jest wyłączone (`ui.confirm_delete: false`):** jeśli włączono `usage_guard.enabled: true`, sprawdzenie użyć nadal uruchamia się w tle i automatycznie otwiera okno ostrzegawcze/blokujące zawsze wtedy, gdy zostaną wykryte aktywne odwołania.

---

[← Poprzedni: 09 — Integracja z Symfony AI](09-symfony-ai-integration.md) | [Spis treści](README.md) | [Następny: 11 — Przewodnik współtworzenia i Pull Requestów →](11-contributing-and-pr-guide.md)
