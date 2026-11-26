# 10 — Asset Usage Guard

[← Precedente: 09 — Integrazione Symfony AI](09-symfony-ai-integration.md) | [Indice Principale](README.md) | [Successivo: 11 — Guida ai Contributi e alle PR →](11-contributing-and-pr-guide.md)

---

## 1. Quale Problema Risolve? (Scenario Reale)

Immagina che un editor nel pannello admin del tuo e-commerce o CMS provi a **eliminare, rinominare, spostare o sovrascrivere** `uploads/products/matte-black-thermos.webp` (o l'intera cartella `uploads/products`).

- **Senza Usage Guard:** il file viene eliminato o spostato immediatamente — e se 3 schede prodotto e 1 banner in homepage vi fanno riferimento, il tuo sito live si ritrova con **immagini rotte `404`**.
- **Con Usage Guard attivo:** il file manager chiede alla tua applicazione: *"Questo file o questa cartella è attualmente referenziato da qualche parte nel database?"*
  - Se esistono riferimenti, la modale di conferma elenca **ogni prodotto, banner o pagina che lo utilizza**, completo di link cliccabile **"Apri record ↗"**.
  - Con **`policy: 'warn'`**, mostra un banner di avviso giallo e offre il pulsante **"Elimina comunque"**.
  - Con **`policy: 'block'`** ed **`enforce_server_side: true`** (impostazione predefinita), mostra un avviso bloccante rosso, **nasconde** il pulsante Elimina e respinge **tutte e 4 le operazioni distruttive** lato server:
    1. **Eliminazione (`delete`)** → `errors.delete_blocked_in_use`
    2. **Rinomina (`rename`)** → `errors.rename_blocked_in_use`
    3. **Spostamento (`move`)** → `errors.move_blocked_in_use`
    4. **Sovrascrittura (`overwrite`)** — inclusi upload (`overwrite_policy: 'overwrite'`), Image Editor ed estrazione ZIP → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Perché occorre implementare una piccola classe PHP?**
> LiveUX FileManagerBundle non può conoscere le tabelle del database della tua applicazione (`Product`, `BlogPost`, `Slider`, `Category`, ecc.) né i nomi delle colonne. Ti mette invece a disposizione un contratto pulito con un solo metodo (`AssetUsageCheckerInterface`). Tu esegui le query sulle tue tabelle e restituisci i riferimenti trovati — il bundle si occupa automaticamente della modale UI, della sanitizzazione degli URL, della deduplicazione, dei controlli su prefisso di cartella e del blocco lato server sulle 4 operazioni.

---

## 2. Come Funziona Passo dopo Passo

```text
[L'editor fa clic su "Elimina"]
        │
        ▼
[1. Il browser invia POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> invoca ogni servizio AssetUsageCheckerInterface nella tua app]
        │
        ├── NESSUN utilizzo trovato ──► "Nessun riferimento attivo trovato ✓" -> Conferma normale
        │
        └── Utilizzi TROVATI        ──► La modale elenca i record trovati + link "Apri record ↗":
                                         • policy: 'warn'  -> Avviso giallo + "Elimina comunque"
                                         • policy: 'block' -> Blocco rosso (pulsante Elimina nascosto)
                                                              + Il server rifiuta delete / rename / move / overwrite!
```

---

## 3. Esempio Completo End-to-End (Pronto per Copia e Incolla)

Segui questi 4 passaggi per attivare **Usage Guard** in un'applicazione Symfony + Doctrine (compatibile con MySQL, MariaDB, PostgreSQL e SQLite) in meno di 5 minuti.

### Passaggio 1: Abilita `usage_guard` sul Tuo Profilo

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Storage Locale'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Abilita per questo profilo (predefinito: false)
                policy: 'block'           # 'warn' (avvisa + consente forzatura) | 'block' (impedisce rigorosamente)
                enforce_server_side: true # true = blocca anche delete, rename, move e overwrite lato server (predefinito: true)
                max_references: 20        # Numero massimo di riferimenti mostrati nella modale (1..200)
```

### Passaggio 2: Esempio di Entity Doctrine (`Product`)

Supponiamo che la tua entity `Product` salvi una singola immagine di copertina (`?string`) e un array JSON per la galleria (`list<string>`):

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

    // es. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> es. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Passaggio 3: Query Portabili nel Repository (`ProductRepository`)

Queste query usano la sintassi portabile **`ESCAPE '!'`** così da funzionare in modo identico su MySQL, MariaDB, PostgreSQL e SQLite:

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
     * Per un singolo file: trova i prodotti che referenziano l'URL come copertina o nella galleria JSON.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Racchiudere tra virgolette ("/uploads/products/a.webp") previene falsi positivi da corrispondenze parziali nel JSON
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
     * Per una cartella: trova i prodotti che referenziano qualsiasi file all'interno del prefisso della cartella.
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

### Passaggio 4: Implementa il Tuo Usage Checker (`ProductAssetUsageChecker`)

**Non è necessaria alcuna voce in `services.yaml`!** Poiché `AssetUsageCheckerInterface` dispone dell'attributo `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`, Symfony rileva e registra automaticamente il tuo checker.

> [!IMPORTANT]
> **Nomi File con Spazi o Caratteri Non-ASCII:**
> `DirectoryLister::buildPublicUrl()` codifica ogni segmento del percorso con `rawurlencode()` (es. `products/summer catalog.pdf` → `/uploads/products/summer%20catalog.pdf`). Usa il metodo helper `buildPublicUrl()` qui sotto affinché le tue query corrispondano esattamente agli URL salvati (se i tuoi form salvano percorsi relativi tramite `return_type: 'path'`, confronta invece con `ltrim($path, '/')`).

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
        // Opzionale: limita questo checker a profili specifici
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // es. "Borraccia Termica Nera (#42)"
                type: 'Prodotto',                                           // Etichetta del badge
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Link cliccabile "Apri record ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Usato per la deduplicazione
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

### Passaggio 5 (Opzionale): Aggiungi Altri Checker per Altre Tabelle

Crea altre classi dedicate che implementano `AssetUsageCheckerInterface` per banner, articoli del blog o categorie: il bundle aggrega e deduplica tutto automaticamente in un'unica modale:

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

## 4. Verificare la Configurazione

1. Svuota la cache:
   ```bash
   php bin/console cache:clear
   ```
2. Conferma che il tuo servizio checker sia taggato e rilevato:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Dovresti vedere `App\FileManager\ProductAssetUsageChecker` nell'output.
3. Salva l'URL di un'immagine scelta dal file manager nella colonna `cover_image` di un prodotto (es. `/uploads/products/matte-black-thermos.webp`).
4. Fai clic con il tasto destro su quell'immagine (o sulla cartella genitore `products`) nel file manager e scegli **Elimina**:
   - Si apre la modale, mostra *"Verifica utilizzi attivi…"* e poi elenca **"Borraccia Termica Nera (#42) [Prodotto] — Apri record ↗"**.
   - Con `policy: 'block'`, il pulsante Elimina è nascosto e qualsiasi tentativo di `rename`, `move` o `overwrite` su quell'asset viene respinto anche lato server.

---

## 5. Confronto tra `warn` e `block` e Note di Sicurezza

| Policy | Comportamento Modale di Eliminazione | Blocco Lato Server (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(predefinita)* | Mostra un banner di avviso giallo con i riferimenti trovati; il pulsante di azione riporta **"Elimina comunque"**. | Consente eliminazione, rinomina, spostamento e sovrascrittura dopo la conferma dell'editor. |
| **`block`** | Mostra un banner bloccante rosso con i riferimenti trovati e i link **"Apri record ↗"**; il pulsante Elimina è **nascosto**. | Difesa in profondità: rifiuta **`delete`**, **`rename`**, **`move`** e **`overwrite`** anche se l'API viene invocata direttamente. |

- **Sanitizzazione dei Link (`editUrl`):** `editUrl` viene validato sia in PHP (`AssetUsageGuardService::sanitizeEditUrl()`) sia in JavaScript (`isSafeUrl()`), consentendo solo percorsi relativi alla radice (`/admin/...`) o URL `http(s)://...` (gli schemi `javascript:`, `data:` e gli URL protocol-relative `//evil.com` vengono respinti).
- **Permessi e Limiti di Richiesta (`checkUsage`):** l'endpoint `checkUsage` è protetto da CSRF, richiede sia **`FILE_MANAGER_VIEW`** sia **`FILE_MANAGER_DELETE`** su ogni percorso di destinazione e su tutte le cartelle genitore, limita le richieste a **200 elementi** (`MAX_BULK_DELETE_ITEMS`) e blocca i percorsi nascosti o nel cestino (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Quando la conferma di eliminazione è disabilitata (`ui.confirm_delete: false`):** se `usage_guard.enabled: true`, la verifica degli utilizzi viene comunque eseguita in background e apre automaticamente la modale di avviso/blocco ogni volta che vengono rilevati riferimenti attivi.

---

[← Precedente: 09 — Integrazione Symfony AI](09-symfony-ai-integration.md) | [Indice Principale](README.md) | [Successivo: 11 — Guida ai Contributi e alle PR →](11-contributing-and-pr-guide.md)
