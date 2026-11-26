# 13 — Gids voor Doctrine ORM- & DBAL-integratie

[← Vorige: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Documentatie-overzicht](README.md)

**LiveUX FileManagerBundle** heeft in de kern geen enkele verplichte Doctrine-dependency, maar biedt wel eersteklas integratie voor Symfony-applicaties die Doctrine ORM of DBAL gebruiken op **4 belangrijke gebieden**:

1. **Map- en bestandsmetadata opslaan in je database** (`defaults.metadata.driver: 'doctrine'` — ingebouwde zero-code DBAL-opslag)
2. **Doctrine Entity-velden koppelen aan `FileManagerPickerType`** (enkele kolom, JSON-galerij, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Een eigen metadata-backend schrijven met je eigen Doctrine ORM Entity** (`FolderMetadataStorageInterface`)
4. **Automatisch databasepaden bijwerken wanneer bestanden of mappen worden hernoemd of verplaatst** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Ingebouwde Doctrine-metadataopslag (`driver: 'doctrine'`)

### In gewone taal: wat doet dit?

Wanneer je een **kleur** (bijv. blauw), **icoon** of **vastgezette status (pinned)** toekent aan een map — of een **alt-tekst, titel, beschrijving, tags (`#campaign`) of focuspunt (focal point)** toevoegt aan een afbeelding — wordt die metadata niet in de bestanden zelf opgeslagen, maar apart bewaard.

De bundle biedt **2 kant-en-klare opslagdrivers**:
- **Optie 1 — `driver: 'json_file'` (Standaard, enkele server):** Slaat metadata lokaal op in `var/file-manager/metadata.json`. Geen configuratie nodig voor kleine applicaties op één server.
- **Optie 2 — `driver: 'doctrine'` (Database — `DoctrineDbalMetadataStorage`):** Slaat metadata op in een tabel in je **MySQL-, MariaDB-, PostgreSQL- of SQLite**-database. **Er is geen Entity, Repository of maatwerkcode nodig.**

**Wanneer kies je voor `driver: 'doctrine'`?**
- Je applicatie draait op **meerdere servers (Load Balancer / Docker Swarm / Kubernetes)** waar een lokaal JSON-bestand niet gedeeld kan worden, terwijl de database wel centraal staat.
- Je wilt dat alle applicatiestatus in één databaseback-up zit.
- Je hebt hoge doorvoer nodig voor metadata over duizenden bestanden en mappen zonder gelijktijdige schrijfconflicten.

### Architectuurkenmerken van de ingebouwde DBAL-driver

- **Geen N+1-queries (`preload()`):** Bij het opvragen van mappenlijsten of mapstructuren worden sleutels in batches opgehaald met `SELECT`-queries in blokken van 400. Een begrensde in-memory cache (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) in combinatie met `ResetInterface` voorkomt geheugenlekken in langlopende FrankenPHP- of Messenger-workers.
- **Atomaire native UPSERT:** Gelijktijdige schrijfacties gebruiken `ON CONFLICT (key_hash) DO UPDATE` op PostgreSQL/SQLite en `ON DUPLICATE KEY UPDATE` op MySQL/MariaDB — waarmee race conditions worden uitgesloten.
- **Gebatchte schrijfacties (`saveMany()`):** Bewerkingen zoals het kopiëren van mappen voeren opgesplitste `DELETE`- (400 rijen) en multi-row `INSERT`-statements (100 rijen) uit binnen één enkele databasetransactie.
- **PostgreSQL `text_pattern_ops` B-Tree-index:** Maakt automatisch een `LEFT(canonical_key, 191) text_pattern_ops`-expressie-index aan en gebruikt `~>=~` / `~<=~`-bereikfilters zodat prefixqueries volledig geïndexeerd blijven, zelfs onder glibc UTF-8 `LC_COLLATE`.
- **Strikte validatie van tabelnamen:** `defaults.metadata.table` accepteert uitsluitend veilige SQL-identifiers die voldoen aan `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Alle configuratie-opties

| Optie (`defaults.metadata.*`) | Standaard | Beschrijving |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Zet op `'doctrine'` om `DoctrineDbalMetadataStorage` in te schakelen (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Pas de naam van de databasetabel naar wens aan (bijv. `'media_metadata'`). |
| `connection` | `null` | Heb je meerdere DBAL-verbindingen geconfigureerd in `config/packages/doctrine.yaml` (bijv. `'media'`), dan richt dit zich rechtstreeks op die verbinding. |
| `entity_manager` | `'default'` | Entity Manager waarvan de DBAL-verbinding wordt gebruikt wanneer `connection` gelijk is aan `null`. |
| `auto_create_table` | `true` | Maakt de tabel automatisch aan bij het eerste request als deze nog ontbreekt. Zet dit op `false` in productie als je het schema beheert met **Doctrine Migrations**. |

### Stap 1: Schakel `driver: 'doctrine'` in via `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Gebruikt de ingebouwde DoctrineDbalMetadataStorage in plaats van json_file
            connection: null                # Optioneel: naam van een eigen DBAL-verbinding (bijv. 'media')
            entity_manager: 'default'       # Entity Manager die wordt gebruikt als connection null is
            table: 'liveux_fm_metadata'     # Pas de tabelnaam naar wens aan
            auto_create_table: true         # Zet op false in productie bij gebruik van Doctrine Migrations
```

### Stap 2 (aanbevolen voor productie): Cross-platform Migration (MySQL / MariaDB / PostgreSQL / SQLite)

Om de tabel in productie expliciet te beheren via Doctrine Migrations, zet je `auto_create_table: false` en gebruik je deze platformbewuste migratie (maakt op PostgreSQL de functionele `text_pattern_ops`-index aan en op MySQL/MariaDB/SQLite de `lengths: [191]`-prefixindex):

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
        return 'Maakt de tabel liveux_fm_metadata aan met platformgeoptimaliseerde prefixindex.';
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
> **Waarom `key_hash CHAR(64)` gebruiken als Primary Key?** MySQL `utf8mb4` InnoDB-indexsleutels kunnen bij lange paden tegen de limiet van 191 tekens aanlopen. Door een SHA-256-hash als primary key te gebruiken werken diep geneste paden tot 4.096 tekens — inclusief Turkse, CJK- en Arabische bestandsnamen — probleemloos op MySQL, MariaDB, PostgreSQL en SQLite.

### Stap 3 (voorbeeld): Eigen tabelnaam & aparte databaseverbinding

Om mediametadata op te slaan in een tabel `app_file_meta` op een aparte DBAL-verbinding `media`, hoef je maar 3 regels aan te passen:

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

## 2. Bestandsvelden modelleren in Doctrine Entities

### A. Enkele omslagafbeelding & JSON-galerij met meerdere afbeeldingen (meest voorkomend)

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

    // FileManagerPickerType (multiple: true) -> list<string> (JSON-array)
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

### B. Relationeel `OneToMany` + `CollectionType` / `LiveCollectionType`

Wanneer elk item een eigen titel, sorteervolgorde en afbeelding heeft (bijv. `Slider` → `SliderItem`):

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

Aan de formulierkant voeg je `FileManagerPickerType::class` toe in `SliderItemType` en koppel je die aan het bovenliggende formulier met `CollectionType::class` of `LiveCollectionType::class` (`by_reference => false`). *(Zie [05 — FormType- & Editor-integraties](05-formtype-and-editor-integrations.md) voor volledige Form- en LiveComponent-voorbeelden.)*

### Kies je voor `return_type: 'url'` of `'path'`?

| Optie | Opgeslagen in de database | Het best geschikt wanneer |
| :--- | :--- | :--- |
| **`'url'`** *(standaard)* | `/uploads/products/thermos.webp` of `https://cdn.example.com/thermos.webp` | Je rechtstreeks `<img src="{{ product.coverImage }}">` in templates wilt renderen zonder voorvoegsel toe te voegen. |
| **`'path'`** | `products/thermos.webp` *(alleen relatief pad)* | Je later wilt migreren van lokale opslag naar S3/R2/CDN door uitsluitend `url_prefix` te wijzigen, zonder databaserecords aan te passen. |

---

### C. Volledig werkend voorbeeld: Formulier → Controller → Database → Weergave op de storefront

Hier zie je de volledige levenscyclus op één plek: **een gebruiker kiest een omslagafbeelding en sorteerbare galerij in een formulier → de Controller slaat ze op via Doctrine → de productdetailpagina rendert de afbeeldingen inclusief de SEO `alt`-tekst en het focuspunt (`focalPoint`) uit de bestandsbeheerder.**

#### 1) Symfony-formulier (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Productnaam'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Omslagafbeelding',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Slaat "/uploads/products/thermos.webp" op in de DB
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Productgalerij (sleep om te herschikken)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Slaat een JSON-array op: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controller — Opslaan & uitlezen van SEO-metadata (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType valideert en koppelt de waarden automatisch aan de Entity:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Product en afbeeldingen opgeslagen in de database.');

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
        // Optioneel: haal de SEO alt-tekst en het focuspunt van de omslagafbeelding op uit de bestandsbeheerder
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

#### 3) Admin-formuliertemplate (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Nieuw product</h1>

    {# Form-thema en DialogHost worden automatisch door de bundle gekoppeld #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Opslaan</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Storefront-detailtemplate (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Omslagafbeelding — met het focuspunt (focalPoint) en de SEO alt-tekst uit de bestandsbeheerder indien ingesteld #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galerij met meerdere afbeeldingen (gerenderd in exact de sleepvolgorde die de gebruiker heeft gekozen!) #}
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
> **Wil je ook Cloud Storage-inloggegevens opslaan in je Doctrine-database?**
> Zie **[07 — Cloudopslag & Dynamische configuratie](07-cloud-storage-and-dynamic-config.md)** (Sectie 3) voor een complete uitleg over het versleuteld opslaan van AWS S3-, Cloudflare R2- of Supabase-instellingen in je database en het beheren ervan vanuit een adminpanel.

---

## 3. Eigen metadataopslag met je eigen Doctrine ORM Entity (gevorderd)

Wil je metadata opslaan in je eigen ORM Entity in plaats van de ingebouwde `DoctrineDbalMetadataStorage` (bijvoorbeeld om een extra kolom `updatedBy` toe te voegen), implementeer dan zowel `FolderMetadataStorageInterface` als `ResetInterface` en maak een service-alias aan voor de interface:

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
        // Voorkomt geheugenlekken en verouderde gegevens in FrankenPHP- / RoadRunner- / Messenger-workers
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
     * Batchgewijze opslag van metadata (gebruikt door MetadataSyncListener::copyFilteredFolderMetadata om N+1-schrijfacties te voorkomen).
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

            // Verwijder atomair oude rijen en eventuele verouderde onderliggende records op de doelbestemming:
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
     * Maakt onderscheid tussen root-volumesleutels ('local::') en geneste mapsleutels ('local::products').
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

## 4. Databasereferenties automatisch bijwerken bij hernoemen of verplaatsen

Wanneer een redacteur een bestand (bijv. `products/old.webp`) of een volledige map hernoemt of verplaatst in de bestandsbeheerder, kun je luisteren naar `ItemRenamedEvent` en `ItemMovedEvent` om zowel **`Product.coverImage`** als de JSON-array **`Product.galleryImages`** automatisch synchroon te houden:

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

## 5. Combineren met Asset Usage Guard

Om te voorkomen dat bestanden waarnaar wordt verwezen in je Doctrine-tabellen per ongeluk worden verwijderd, hernoemd, verplaatst of overschreven, koppel je `AssetUsageCheckerInterface` rechtstreeks aan je `ProductRepository` — zie **[10 — Asset Usage Guard](10-asset-usage-guard.md)** voor een complete stap-voor-stap implementatie.

---

[← Vorige: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Documentatie-overzicht](README.md)
