# 10 — Asset Usage Guard

[← Vorige: 09 — Symfony AI-integratie](09-symfony-ai-integration.md) | [Hoofdoverzicht](README.md) | [Volgende: 11 — Bijdragen & PR-richtlijnen →](11-contributing-and-pr-guide.md)

---

## 1. Welk probleem lost dit op? (Praktijkscenario)

Stel je voor dat een redacteur in het beheerpaneel van je webshop of CMS probeert om `uploads/products/matte-black-thermos.webp` (of de volledige map `uploads/products`) te **verwijderen, hernoemen, verplaatsen of overschrijven**.

- **Zonder Usage Guard:** Het bestand wordt direct verwijderd of verplaatst — en als 3 productkaarten en 1 homepage-banner ernaar verwijzen, krijgt je live website direct **kapotte `404`-afbeeldingen**.
- **Met Usage Guard ingeschakeld:** Het bestandsbeheer vraagt aan je applicatie: *"Wordt dit bestand of deze map momenteel ergens in de database gebruikt?"*
  - Als er verwijzingen bestaan, toont het bevestigingsvenster **elk product, elke banner of elke pagina waarin het bestand wordt gebruikt**, compleet met een klikbare link **"Item openen ↗"**.
  - Met **`policy: 'warn'`** verschijnt een gele waarschuwingsbalk met de knop **"Toch verwijderen"**.
  - Met **`policy: 'block'`** en **`enforce_server_side: true`** (de standaard) verschijnt een rode blokkeermelding, wordt de knop Verwijderen **verborgen**, en weigert de server **alle 4 destructieve acties**:
    1. **Verwijderen (`delete`)** → `errors.delete_blocked_in_use`
    2. **Naam wijzigen (`rename`)** → `errors.rename_blocked_in_use`
    3. **Verplaatsen (`move`)** → `errors.move_blocked_in_use`
    4. **Overschrijven (`overwrite`)** — inclusief uploads (`overwrite_policy: 'overwrite'`), de afbeeldingseditor en het uitpakken van ZIP-bestanden → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Waarom implementeer je zelf één kleine PHP-klasse?**
> LiveUX FileManagerBundle kan de databasetabellen (`Product`, `BlogPost`, `Slider`, `Category`, enz.) en kolomnamen van jouw applicatie niet kennen. In plaats daarvan biedt de bundle een heldere interface met één methode (`AssetUsageCheckerInterface`). Jij bevraagt je eigen tabellen en geeft de gevonden verwijzingen terug — de bundle verzorgt automatisch de UI-modal, URL-sanitatie, deduplicatie, mapprefix-controles en server-side handhaving voor alle 4 acties.

---

## 2. Hoe het stap voor stap werkt

```text
[Redacteur klikt op "Verwijderen"]
         │
         ▼
[1. Browser stuurt POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
         │
         ▼
[2. AssetUsageGuardService -> roept elke AssetUsageCheckerInterface-service in je app aan]
         │
         ├── GEEN gebruik gevonden ──► "Geen actieve verwijzingen gevonden ✓" -> Normale bevestiging
         │
         └── Gebruik GEVONDEN      ──► Modal toont gekoppelde items + links "Item openen ↗":
                                         • policy: 'warn'  -> Gele waarschuwing + "Toch verwijderen"
                                         • policy: 'block' -> Rode blokkering (knop Verwijderen verborgen)
                                                              + Server weigert delete / rename / move / overwrite!
```

---

## 3. Compleet end-to-end voorbeeld (Klaar om te kopiëren)

Volg deze 4 stappen om **Usage Guard** binnen 5 minuten werkend te krijgen in een Symfony + Doctrine-applicatie (compatibel met MySQL, MariaDB, PostgreSQL en SQLite).

### Stap 1: Schakel `usage_guard` in op je profiel

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokale opslag'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Inschakelen voor dit profiel (standaard: false)
                policy: 'block'           # 'warn' (waarschuwen + doorgaan toestaan) | 'block' (strikt blokkeren)
                enforce_server_side: true # true = handhaaf delete, rename, move & overwrite ook server-side (standaard: true)
                max_references: 20        # Max. aantal verwijzingen in de modal (1..200)
```

### Stap 2: Voorbeeld Doctrine-entity (`Product`)

Stel dat je `Product`-entity een enkele omslagafbeelding (`?string`) en een JSON-galerijarray (`list<string>`) opslaat:

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

    // bijv. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> bijv. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Stap 3: Portabele repository-queries (`ProductRepository`)

Deze queries gebruiken portabele **`ESCAPE '!'`**-syntaxis, zodat ze identiek werken in MySQL, MariaDB, PostgreSQL en SQLite:

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
     * Voor een enkel bestand: zoekt producten die naar de URL verwijzen als omslagafbeelding of in de JSON-galerij.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Omsluiten met dubbele aanhalingstekens ("/uploads/products/a.webp") voorkomt gedeeltelijke fout-positieven in JSON
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
     * Voor een map: zoekt producten die verwijzen naar een bestand binnen het mapprefix.
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

### Stap 4: Implementeer je Usage Checker (`ProductAssetUsageChecker`)

**Er is geen configuratie in `services.yaml` nodig!** Omdat `AssetUsageCheckerInterface` het attribuut `#[AutoconfigureTag('liveux_file_manager.usage_checker')]` draagt, ontdekt en registreert Symfony je checker automatisch.

> [!IMPORTANT]
> **Bestandsnamen met spaties of niet-ASCII-tekens:**
> `DirectoryLister::buildPublicUrl()` codeert elk padsegment met `rawurlencode()` (bijv. `products/zomer catalogus.pdf` → `/uploads/products/zomer%20catalogus.pdf`). Gebruik de helper `buildPublicUrl()` hieronder zodat je queries exact overeenkomen met opgeslagen URL's (als je formulieren relatieve paden opslaan via `return_type: 'path'`, vergelijk dan in plaats daarvan met `ltrim($path, '/')`).

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
        // Optioneel: beperk deze checker tot specifieke profielen
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // bijv. "Matzwarte Thermosfles (#42)"
                type: 'Product',                                            // Badgelabel
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Klikbare link "Item openen ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Gebruikt voor deduplicatie
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

### Stap 5 (Optioneel): Voeg extra checkers toe voor andere tabellen

Maak extra gerichte klassen aan die `AssetUsageCheckerInterface` implementeren voor banners, blogartikelen of categorieën — de bundle verzamelt en ontdubbelt ze automatisch in één overzichtelijke modal:

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

## 4. Je configuratie verifiëren

1. Wis de cache:
   ```bash
   php bin/console cache:clear
   ```
2. Controleer of je checker-service getagd en herkend is:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Je zou `App\FileManager\ProductAssetUsageChecker` in de uitvoer moeten zien.
3. Sla een afbeeldings-URL uit het bestandsbeheer op in de kolom `cover_image` van een product (bijv. `/uploads/products/matte-black-thermos.webp`).
4. Klik met de rechtermuisknop op die afbeelding (of de bovenliggende map `products`) in het bestandsbeheer en klik op **Verwijderen**:
   - De modal opent, toont *"Actief gebruik controleren…"* en vermeldt vervolgens **"Matzwarte Thermosfles (#42) [Product] — Item openen ↗"**.
   - Met `policy: 'block'` is de knop Verwijderen verborgen en wordt elke poging om die asset te hernoemen (`rename`), te verplaatsen (`move`) of te overschrijven (`overwrite`) ook server-side geweigerd.

---

## 5. Vergelijking `warn` vs. `block` & Beveiligingsnotities

| Beleid (`policy`) | Gedrag van verwijdermodal | Server-side handhaving (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(standaard)* | Toont een gele waarschuwingsbalk met gevonden verwijzingen; de actieknop toont **"Toch verwijderen"**. | Staat verwijderen, hernoemen, verplaatsen en overschrijven toe zodra de redacteur bevestigt. |
| **`block`** | Toont een rode blokkeerbalk met gevonden verwijzingen en links **"Item openen ↗"**; de knop Verwijderen is **verborgen**. | Defense in depth: weigert **`delete`**, **`rename`**, **`move`** en **`overwrite`**, zelfs als de API rechtstreeks wordt aangeroepen. |

- **Link-sanitatie (`editUrl`):** `editUrl` wordt zowel in PHP (`AssetUsageGuardService::sanitizeEditUrl()`) als in JavaScript (`isSafeUrl()`) gevalideerd; alleen root-relatieve paden (`/admin/...`) of `http(s)://...`-URL's zijn toegestaan (`javascript:`, `data:` en protocol-relatieve `//evil.com`-URL's worden geweigerd).
- **Machtigingen & limieten (`checkUsage`):** Het endpoint `checkUsage` is CSRF-beveiligd, dwingt zowel **`FILE_MANAGER_VIEW`** als **`FILE_MANAGER_DELETE`** af voor elk doelpad en alle bovenliggende mappen, beperkt verzoeken tot **200 items** (`MAX_BULK_DELETE_ITEMS`) en blokkeert verborgen paden en prullenbakpaden (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Wanneer verwijderbevestiging is uitgeschakeld (`ui.confirm_delete: false`):** Als `usage_guard.enabled: true` is ingesteld, draait de gebruikscontrole alsnog op de achtergrond en opent de waarschuwings- of blokkeermodal automatisch zodra er actieve verwijzingen worden gevonden.

---

[← Vorige: 09 — Symfony AI-integratie](09-symfony-ai-integration.md) | [Hoofdoverzicht](README.md) | [Volgende: 11 — Bijdragen & PR-richtlijnen →](11-contributing-and-pr-guide.md)
