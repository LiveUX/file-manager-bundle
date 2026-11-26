# 10 — Asset Usage Guard

[← Zurück: 09 — Symfony-AI-Integration](09-symfony-ai-integration.md) | [Übersicht](README.md) | [Weiter: 11 — Mitwirken & PR-Leitfaden →](11-contributing-and-pr-guide.md)

---

## 1. Welches Problem wird gelöst? (Praxisszenario)

Stellen Sie sich vor, ein Redakteur versucht im Admin-Panel Ihres Online-Shops oder CMS, die Datei `uploads/products/matte-black-thermos.webp` (oder gleich den gesamten Ordner `uploads/products`) zu **löschen, umzubenennen, zu verschieben oder zu überschreiben**.

- **Ohne Usage Guard:** Die Datei wird sofort gelöscht oder verschoben – verweisen jedoch 3 Produktkarten und 1 Startseiten-Banner darauf, zeigt Ihre Live-Website ab sofort **defekte `404`-Bilder**.
- **Mit aktiviertem Usage Guard:** Der Dateimanager fragt Ihre Anwendung: *„Wird diese Datei oder dieser Ordner aktuell irgendwo in der Datenbank referenziert?“*
  - Existieren Verweise, listet der Bestätigungsdialog **jedes Produkt, jedes Banner und jede Seite**, die das Asset verwenden – inklusive anklickbarem Link **„Eintrag öffnen ↗“**.
  - Mit **`policy: 'warn'`** erscheint ein gelbes Warnbanner mit der Schaltfläche **„Trotzdem löschen“**.
  - Mit **`policy: 'block'`** und **`enforce_server_side: true`** (Standard) erscheint ein roter Sperrhinweis, die Löschen-Schaltfläche wird **ausgeblendet** und der Server lehnt **alle 4 destruktiven Operationen** ab:
    1. **Löschen (`delete`)** → `errors.delete_blocked_in_use`
    2. **Umbenennen (`rename`)** → `errors.rename_blocked_in_use`
    3. **Verschieben (`move`)** → `errors.move_blocked_in_use`
    4. **Überschreiben (`overwrite`)** – einschließlich Uploads (`overwrite_policy: 'overwrite'`), Bildeditor und ZIP-Entpacken → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Warum implementieren Sie eine kleine PHP-Klasse?**
> LiveUX FileManagerBundle kann die Datenbanktabellen (`Product`, `BlogPost`, `Slider`, `Category` usw.) und Spaltennamen Ihrer Anwendung nicht kennen. Stattdessen stellt es einen schlanken Vertrag mit genau einer Methode bereit (`AssetUsageCheckerInterface`). Sie fragen Ihre Tabellen ab und geben passende Verweise zurück – das Bundle übernimmt automatisch den UI-Dialog, die URL-Bereinigung, die Deduplizierung, die Ordnerpräfix-Prüfung und die serverseitige Durchsetzung aller 4 Operationen.

---

## 2. Ablauf Schritt für Schritt

```text
[Redakteur klickt auf „Löschen“]
        │
        ▼
[1. Browser sendet POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> ruft jeden AssetUsageCheckerInterface-Service Ihrer App auf]
        │
        ├── KEINE Verweise ──► „Keine aktiven Verweise gefunden ✓“ -> Normale Bestätigung
        │
        └── Verweise GEFUNDEN ► Dialog listet betroffene Einträge + „Eintrag öffnen ↗“-Links:
                                  • policy: 'warn'  -> Gelbe Warnung + „Trotzdem löschen“
                                  • policy: 'block' -> Rote Sperre (Löschen-Button ausgeblendet)
                                                       + Server blockiert delete / rename / move / overwrite!
```

---

## 3. Vollständiges End-to-End-Beispiel (direkt kopierbar)

Mit diesen 4 Schritten richten Sie den **Usage Guard** in einer Symfony-+-Doctrine-Anwendung (kompatibel mit MySQL, MariaDB, PostgreSQL und SQLite) in weniger als 5 Minuten ein.

### Schritt 1: `usage_guard` im Profil aktivieren

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokaler Speicher'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Für dieses Profil aktivieren (Standard: false)
                policy: 'block'           # 'warn' (warnen + Bestätigung erlauben) | 'block' (strikt verhindern)
                enforce_server_side: true # true = auch delete, rename, move & overwrite serverseitig blockieren (Standard: true)
                max_references: 20        # Max. angezeigte Verweise im Dialog (1..200)
```

### Schritt 2: Beispiel-Doctrine-Entity (`Product`)

Angenommen, Ihre `Product`-Entity speichert ein einzelnes Titelbild (`?string`) sowie ein JSON-Galerie-Array (`list<string>`):

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

    // z. B. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> z. B. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Schritt 3: Portable Repository-Abfragen (`ProductRepository`)

Diese Abfragen nutzen die portable **`ESCAPE '!'`**-Syntax und funktionieren identisch unter MySQL, MariaDB, PostgreSQL und SQLite:

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
     * Für eine einzelne Datei: findet Produkte, die die URL als Titelbild oder in der JSON-Galerie referenzieren.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Das Einschließen in doppelte Anführungszeichen ("/uploads/products/a.webp") verhindert Fehlalarme durch Teilübereinstimmungen in JSON
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
     * Für einen Ordner: findet Produkte, die beliebige Dateien innerhalb des Ordnerpräfixes referenzieren.
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

### Schritt 4: Ihren Usage Checker implementieren (`ProductAssetUsageChecker`)

**Es ist kein Eintrag in `services.yaml` nötig!** Da `AssetUsageCheckerInterface` mit `#[AutoconfigureTag('liveux_file_manager.usage_checker')]` annotiert ist, erkennt und registriert Symfony Ihren Checker automatisch.

> [!IMPORTANT]
> **Dateinamen mit Leerzeichen oder Umlauten / Nicht-ASCII-Zeichen:**
> `DirectoryLister::buildPublicUrl()` kodiert jedes Pfadsegment per `rawurlencode()` (z. B. `products/sommer katalog.pdf` → `/uploads/products/sommer%20katalog.pdf`). Verwenden Sie die Hilfsmethode `buildPublicUrl()` unten, damit Ihre Abfragen exakt zu gespeicherten URLs passen (wenn Ihre Formulare über `return_type: 'path'` relative Pfade speichern, vergleichen Sie stattdessen gegen `ltrim($path, '/')`).

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
        // Optional: diesen Checker auf bestimmte Profile beschränken
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // z. B. "Mattschwarze Thermoskanne (#42)"
                type: 'Produkt',                                            // Badge-Beschriftung
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Anklickbarer Link „Eintrag öffnen ↗“
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Wird zur Deduplizierung verwendet
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

### Schritt 5 (Optional): Weitere Checker für andere Tabellen ergänzen

Erstellen Sie weitere spezialisierte Klassen, die `AssetUsageCheckerInterface` implementieren – z. B. für Banner, Blogbeiträge oder Kategorien. Das Bundle führt alle Ergebnisse automatisch zusammen und dedupliziert sie in einem einzigen Dialog:

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

## 4. Einrichtung überprüfen

1. Cache leeren:
   ```bash
   php bin/console cache:clear
   ```
2. Prüfen, ob Ihr Checker-Service getaggt und erkannt wurde:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   In der Ausgabe sollte `App\FileManager\ProductAssetUsageChecker` erscheinen.
3. Speichern Sie eine über den Dateimanager gewählte Bild-URL in der Spalte `cover_image` eines Produkts (z. B. `/uploads/products/matte-black-thermos.webp`).
4. Klicken Sie im Dateimanager mit der rechten Maustaste auf dieses Bild (oder auf den übergeordneten Ordner `products`) und wählen Sie **Löschen**:
   - Der Dialog öffnet sich, zeigt *„Aktive Verwendungen werden geprüft…“* und listet anschließend **„Mattschwarze Thermoskanne (#42) [Produkt] — Eintrag öffnen ↗“** auf.
   - Bei `policy: 'block'` ist die Löschen-Schaltfläche ausgeblendet, und jeder Versuch, das Asset per `rename`, `move` oder `overwrite` zu verändern, wird ebenfalls serverseitig abgewiesen.

---

## 5. Vergleich `warn` vs. `block` & Sicherheitshinweise

| Richtlinie (`policy`) | Verhalten des Löschdialogs | Serverseitige Durchsetzung (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(Standard)* | Zeigt ein gelbes Warnbanner mit den gefundenen Verweisen; der Aktionsbutton lautet **„Trotzdem löschen“**. | Erlaubt Löschen, Umbenennen, Verschieben und Überschreiben nach Bestätigung durch den Redakteur. |
| **`block`** | Zeigt ein rotes Sperrbanner mit den gefundenen Verweisen und **„Eintrag öffnen ↗“**-Links; die Löschen-Schaltfläche ist **ausgeblendet**. | Defense in Depth: Lehnt **`delete`**, **`rename`**, **`move`** und **`overwrite`** selbst bei direkten API-Aufrufen ab. |

- **Link-Bereinigung (`editUrl`):** `editUrl` wird sowohl in PHP (`AssetUsageGuardService::sanitizeEditUrl()`) als auch in JavaScript (`isSafeUrl()`) validiert; erlaubt sind ausschließlich root-relative Pfade (`/admin/...`) oder `http(s)://...`-URLs (`javascript:`, `data:` und protokollrelative `//evil.com`-URLs werden abgewiesen).
- **Berechtigungen & Mengenbegrenzung (`checkUsage`):** Der Endpunkt `checkUsage` ist CSRF-geschützt, erzwingt sowohl **`FILE_MANAGER_VIEW`** als auch **`FILE_MANAGER_DELETE`** für jeden Zielpfad und alle übergeordneten Ordner, begrenzt Anfragen auf **200 Elemente** (`MAX_BULK_DELETE_ITEMS`) und sperrt versteckte sowie Papierkorb-Pfade (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Wenn die Löschbestätigung deaktiviert ist (`ui.confirm_delete: false`):** Ist `usage_guard.enabled: true`, läuft die Verwendungsprüfung dennoch im Hintergrund und öffnet automatisch den Warn- bzw. Sperrdialog, sobald aktive Verweise gefunden werden.

---

[← Zurück: 09 — Symfony-AI-Integration](09-symfony-ai-integration.md) | [Übersicht](README.md) | [Weiter: 11 — Mitwirken & PR-Leitfaden →](11-contributing-and-pr-guide.md)
