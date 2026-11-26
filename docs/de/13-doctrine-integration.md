# 13 — Doctrine ORM & DBAL Integrationsleitfaden

[← Zurück: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Dokumentationsübersicht](README.md)

**LiveUX FileManagerBundle** hat im Kern keinerlei Pflichtabhängigkeit zu Doctrine, bietet jedoch für Symfony-Anwendungen mit Doctrine ORM oder DBAL eine erstklassige Integration in **4 zentralen Bereichen**:

1. **Ordner- und Dateimetadaten in der Datenbank speichern** (`defaults.metadata.driver: 'doctrine'` — integrierter Zero-Code-DBAL-Speicher)
2. **Doctrine-Entity-Felder an `FileManagerPickerType` binden** (einzelne Spalte, JSON-Galerie, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Ein eigenes Metadaten-Backend mit deiner eigenen Doctrine-ORM-Entity schreiben** (`FolderMetadataStorageInterface`)
4. **Datenbankpfade beim Umbenennen oder Verschieben von Dateien und Ordnern automatisch aktualisieren** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Integrierter Doctrine-Metadatenspeicher (`driver: 'doctrine'`)

### Einfach erklärt: Was macht das?

Wenn du einem Ordner eine **Farbe** (z. B. Blau), ein **Icon** oder den Status **angepinnt** zuweist — oder einem Bild **Alt-Text, Titel, Beschreibung, Tags (`#kampagne`) oder einen Fokuspunkt** hinzufügst — werden diese Metadaten nicht direkt in die Dateien geschrieben, sondern separat gespeichert.

Das Bundle bietet dafür **2 sofort einsatzbereite Speicher-Treiber**:
- **Option 1 — `driver: 'json_file'` (Standard, Einzelserver):** Speichert Metadaten in `var/file-manager/metadata.json` auf der lokalen Festplatte. Für kleine Einzelserver-Anwendungen ohne zusätzlichen Einrichtungsaufwand geeignet.
- **Option 2 — `driver: 'doctrine'` (Datenbank — `DoctrineDbalMetadataStorage`):** Speichert Metadaten in einer Tabelle deiner **MySQL-, MariaDB-, PostgreSQL- oder SQLite**-Datenbank. **Es werden weder Entity noch Repository oder eigener Code benötigt.**

**Wann solltest du `driver: 'doctrine'` wählen?**
- Deine Anwendung läuft auf **mehreren Servern (Load Balancer / Docker Swarm / Kubernetes)**, wo eine lokale JSON-Datei nicht gemeinsam genutzt werden kann, die Datenbank hingegen zentral erreichbar ist.
- Alle Anwendungsdaten sollen gemeinsam in einem einzigen Datenbank-Backup enthalten sein.
- Du verwaltest tausende Dateien und Ordner mit hohem Durchsatz ohne Konflikte bei gleichzeitigen Schreibvorgängen.

### Architektur-Highlights des integrierten DBAL-Treibers

- **Keine N+1-Abfragen (`preload()`):** Beim Auflisten von Verzeichnissen oder Ordnerbäumen werden Schlüssel gebündelt über `SELECT`-Abfragen in Chunks zu je 400 Einträgen geladen. Ein begrenzter In-Memory-Cache (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) in Kombination mit `ResetInterface` verhindert Speicherlecks in langlebigen FrankenPHP- oder Messenger-Workern.
- **Atomares natives UPSERT:** Gleichzeitige Speicherungen nutzen `ON CONFLICT (key_hash) DO UPDATE` unter PostgreSQL/SQLite und `ON DUPLICATE KEY UPDATE` unter MySQL/MariaDB — Race Conditions sind damit ausgeschlossen.
- **Gebündelte Schreibvorgänge (`saveMany()`):** Aktionen wie das Kopieren von Ordnern führen gechunkte `DELETE`- (400 Zeilen) und Multi-Row-`INSERT`-Anweisungen (100 Zeilen) innerhalb einer einzigen Datenbanktransaktion aus.
- **PostgreSQL-`text_pattern_ops`-B-Tree-Index:** Legt automatisch einen Ausdrucksindex `LEFT(canonical_key, 191) text_pattern_ops` an und verwendet `~>=~` / `~<=~`-Bereichsfilter, damit Präfix-Abfragen auch unter glibc-UTF-8-`LC_COLLATE` vollständig indexiert bleiben.
- **Strikte Validierung des Tabellennamens:** `defaults.metadata.table` akzeptiert ausschließlich sichere SQL-Bezeichner gemäß `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Alle Konfigurationsoptionen

| Option (`defaults.metadata.*`) | Standard | Beschreibung |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Auf `'doctrine'` setzen, um `DoctrineDbalMetadataStorage` zu aktivieren (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Name der Datenbanktabelle, frei anpassbar (z. B. `'media_metadata'`). |
| `connection` | `null` | Wenn du mehrere DBAL-Verbindungen in `config/packages/doctrine.yaml` konfigurierst (z. B. `'media'`), wird direkt diese Verbindung genutzt. |
| `entity_manager` | `'default'` | Entity Manager, dessen DBAL-Verbindung verwendet wird, wenn `connection` auf `null` steht. |
| `auto_create_table` | `true` | Legt die Tabelle beim ersten Request automatisch an, falls sie fehlt. In Produktion auf `false` setzen, wenn du das Schema über **Doctrine Migrations** verwaltest. |

### Schritt 1: `driver: 'doctrine'` in `liveux_file_manager.yaml` aktivieren

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Nutzt das integrierte DoctrineDbalMetadataStorage statt json_file
            connection: null                # Optional: Name einer eigenen DBAL-Verbindung (z. B. 'media')
            entity_manager: 'default'       # Verwendeter Entity Manager, wenn connection null ist
            table: 'liveux_fm_metadata'     # Tabellenname nach Bedarf anpassbar
            auto_create_table: true         # In Produktion bei Nutzung von Doctrine Migrations auf false setzen
```

### Schritt 2 (empfohlen für Produktion): Plattformübergreifende Migration (MySQL / MariaDB / PostgreSQL / SQLite)

Um die Tabelle in Produktion explizit über Doctrine Migrations zu verwalten, setze `auto_create_table: false` und verwende diese plattformbewusste Migration (sie erstellt den funktionalen `text_pattern_ops`-Index unter PostgreSQL und den Präfix-Index `lengths: [191]` unter MySQL/MariaDB/SQLite):

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
        return 'Erstellt die Tabelle liveux_fm_metadata mit plattformoptimiertem Präfix-Index.';
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
> **Warum wird `key_hash CHAR(64)` als Primärschlüssel verwendet?** MySQL-`utf8mb4`-InnoDB-Indexschlüssel stoßen bei langen Pfaden an das Limit von 191 Zeichen. Durch einen SHA-256-Hash als Primärschlüssel funktionieren tief verschachtelte Pfade bis 4.096 Zeichen — einschließlich deutscher Umlaute sowie türkischer, CJK- und arabischer Dateinamen — reibungslos in MySQL, MariaDB, PostgreSQL und SQLite.

### Schritt 3 (Beispiel): Eigener Tabellenname & dedizierte Datenbankverbindung

Um Medienmetadaten in der Tabelle `app_file_meta` über eine separate DBAL-Verbindung `media` zu speichern, müssen lediglich 3 Zeilen angepasst werden:

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

## 2. Dateifelder in Doctrine-Entities modellieren

### A. Einzelnes Titelbild & JSON-Galerie mit mehreren Bildern (häufigster Anwendungsfall)

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

    // FileManagerPickerType (multiple: true) -> list<string> (JSON-Array)
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

### B. Relationale `OneToMany`-Verknüpfung + `CollectionType` / `LiveCollectionType`

Wenn jeder Eintrag einen eigenen Titel, eine eigene Reihenfolge und ein eigenes Bild besitzt (z. B. `Slider` → `SliderItem`):

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

Auf Formularseite fügst du `FileManagerPickerType::class` in `SliderItemType` hinzu und bindest ihn im übergeordneten Formular über `CollectionType::class` oder `LiveCollectionType::class` (`by_reference => false`) ein. *(Vollständige Formular- und LiveComponent-Beispiele findest du in [05 — FormType & Editor-Integrationen](05-formtype-and-editor-integrations.md).)*

### Solltest du `return_type: 'url'` oder `'path'` wählen?

| Option | In der Datenbank gespeichert | Wann sinnvoll |
| :--- | :--- | :--- |
| **`'url'`** *(Standard)* | `/uploads/products/thermos.webp` oder `https://cdn.example.com/thermos.webp` | Du möchtest `<img src="{{ product.coverImage }}">` direkt im Template ohne Präfix ausgeben. |
| **`'path'`** | `products/thermos.webp` *(nur relativer Pfad)* | Du planst später eine Migration von lokalem Speicher zu S3/R2/CDN, indem du nur `url_prefix` änderst — ganz ohne Anpassungen an den Datenbankeinträgen. |

---

### C. Durchgängiges Praxisbeispiel: Formular → Controller → Datenbank → Frontend-Ausgabe

Hier der komplette Ablauf an einem Stück: **Ein Benutzer wählt im Formular ein Titelbild sowie eine sortierbare Galerie aus → der Controller speichert beides via Doctrine → die Produktdetailseite rendert die Bilder inklusive SEO-`alt`-Text und Fokuspunkt (`focalPoint`) aus dem File Manager.**

#### 1) Symfony-Formular (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Produktname'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Titelbild',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Speichert "/uploads/products/thermos.webp" in der DB
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Produktgalerie (per Drag-and-Drop sortieren)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Speichert ein JSON-Array: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controller — Speichern & Auslesen von SEO-Metadaten (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType validiert und überträgt die Werte automatisch auf die Entity:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Produkt und Bilder wurden in der Datenbank gespeichert.');

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
        // Optional: SEO-Alt-Text und Fokuspunkt des Titelbilds aus dem File Manager abrufen
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

#### 3) Admin-Formular-Template (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Neues Produkt</h1>

    {# Form-Theme und DialogHost werden vom Bundle automatisch eingebunden #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Speichern</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Frontend-Detail-Template (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Titelbild — nutzt Fokuspunkt (focalPoint) und SEO-Alt-Text aus dem File Manager, sofern gepflegt #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galerie mit mehreren Bildern (exakt in der per Drag-and-Drop festgelegten Reihenfolge!) #}
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
> **Möchtest du auch Zugangsdaten für Cloud Storage in deiner Doctrine-Datenbank speichern?**
> In **[07 — Cloud Storage & Dynamische Konfiguration](07-cloud-storage-and-dynamic-config.md)** (Abschnitt 3) findest du eine vollständige Anleitung, wie du verschlüsselte Einstellungen für AWS S3, Cloudflare R2 oder Supabase in deiner Datenbank speicherst und über ein Admin-Panel verwaltest.

---

## 3. Eigener Metadatenspeicher mit eigener Doctrine-ORM-Entity (Fortgeschritten)

Wenn du Metadaten statt im integrierten `DoctrineDbalMetadataStorage` in deiner eigenen ORM-Entity speichern möchtest (etwa um eine zusätzliche Spalte `updatedBy` zu ergänzen), implementiere sowohl `FolderMetadataStorageInterface` als auch `ResetInterface` und erstelle einen Service-Alias für das Interface:

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
        // Verhindert Speicherlecks und veraltete Daten in FrankenPHP-, RoadRunner- oder Messenger-Workern
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
     * Gebündeltes Speichern von Metadaten (wird von MetadataSyncListener::copyFilteredFolderMetadata genutzt, um N+1-Schreibvorgänge zu vermeiden).
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

            // Alte Zeilen sowie eventuell veraltete Untereinträge am Zielort atomar entfernen:
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
     * Unterscheidet Root-Volume-Schlüssel ('local::') von verschachtelten Ordnerschlüsseln ('local::products').
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

## 4. Datenbankreferenzen beim Umbenennen oder Verschieben automatisch aktualisieren

Wenn eine Redakteurin oder ein Redakteur eine Datei (z. B. `products/old.webp`) oder einen gesamten Ordner im File Manager umbenennt oder verschiebt, kannst du auf `ItemRenamedEvent` und `ItemMovedEvent` lauschen, um sowohl **`Product.coverImage`** als auch das JSON-Array **`Product.galleryImages`** automatisch synchron zu halten:

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

## 5. Kombination mit dem Asset Usage Guard

Um zu verhindern, dass Dateien, die in deinen Doctrine-Tabellen referenziert werden, versehentlich gelöscht, umbenannt, verschoben oder überschrieben werden, binde `AssetUsageCheckerInterface` direkt an dein `ProductRepository` — eine vollständige Schritt-für-Schritt-Anleitung findest du in **[10 — Asset Usage Guard](10-asset-usage-guard.md)**.

---

[← Zurück: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Dokumentationsübersicht](README.md)
