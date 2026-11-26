# 13 — Guida all'integrazione con Doctrine ORM e DBAL

[← Precedente: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Indice della documentazione](README.md)

**LiveUX FileManagerBundle** non ha alcuna dipendenza obbligatoria da Doctrine nel suo core, ma offre un'integrazione di prima classe per le applicazioni Symfony che usano Doctrine ORM o DBAL in **4 aree chiave**:

1. **Salvataggio dei metadati di cartelle e file nel database** (`defaults.metadata.driver: 'doctrine'` — storage DBAL integrato senza scrivere codice)
2. **Collegamento dei campi delle Entity Doctrine a `FileManagerPickerType`** (singola colonna, galleria JSON, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Scrittura di un backend di metadati personalizzato con la propria Entity Doctrine ORM** (`FolderMetadataStorageInterface`)
4. **Aggiornamento automatico dei percorsi nel database quando file o cartelle vengono rinominati o spostati** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Storage integrato dei metadati su Doctrine (`driver: 'doctrine'`)

### In termini semplici: cosa fa?

Quando assegni un **colore** (es. blu), un'**icona** o uno **stato fissato in alto** a una cartella — oppure aggiungi **testo alt, titolo, descrizione, tag (`#campagna`) o un punto focale** a un'immagine — questi metadati non vengono scritti all'interno dei file stessi, ma archiviati separatamente.

Il bundle mette a disposizione **2 driver di archiviazione pronti all'uso**:
- **Opzione 1 — `driver: 'json_file'` (predefinito, server singolo):** Salva i metadati in `var/file-manager/metadata.json` sul disco locale. Zero configurazione per piccole applicazioni su un singolo server.
- **Opzione 2 — `driver: 'doctrine'` (Database — `DoctrineDbalMetadataStorage`):** Salva i metadati in una tabella del tuo database **MySQL, MariaDB, PostgreSQL o SQLite**. **Non richiede Entity, Repository né codice personalizzato.**

**Quando conviene scegliere `driver: 'doctrine'`?**
- La tua applicazione gira su **più server (Load Balancer / Docker Swarm / Kubernetes)**, dove un file JSON locale non può essere condiviso mentre il database è centralizzato.
- Vuoi includere l'intero stato applicativo in un unico backup del database.
- Ti serve gestire metadati ad alto throughput su migliaia di file e cartelle senza conflitti di scrittura concorrente.

### Punti di forza architetturali del driver DBAL integrato

- **Zero query N+1 (`preload()`):** Quando si elencano directory o alberi di cartelle, le chiavi vengono recuperate in batch con query `SELECT` a blocchi di 400. Una cache in memoria con dimensione limitata (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) abbinata a `ResetInterface` previene memory leak nei worker persistenti di FrankenPHP o Messenger.
- **UPSERT nativo atomico:** I salvataggi concorrenti usano `ON CONFLICT (key_hash) DO UPDATE` su PostgreSQL/SQLite e `ON DUPLICATE KEY UPDATE` su MySQL/MariaDB — eliminando le race condition.
- **Scritture in batch (`saveMany()`):** Operazioni come la copia di cartelle eseguono `DELETE` a blocchi (400 righe) + `INSERT` multi-riga (100 righe) all'interno di un'unica transazione di database.
- **Indice B-Tree `text_pattern_ops` su PostgreSQL:** Crea automaticamente un indice su espressione `LEFT(canonical_key, 191) text_pattern_ops` e applica filtri di intervallo `~>=~` / `~<=~` così che le query per prefisso sfruttino sempre l'indice anche con `LC_COLLATE` UTF-8 di glibc.
- **Validazione rigorosa del nome tabella:** `defaults.metadata.table` accetta solo identificatori SQL sicuri conformi a `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Tutte le opzioni di configurazione

| Opzione (`defaults.metadata.*`) | Predefinito | Descrizione |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Imposta `'doctrine'` per abilitare `DoctrineDbalMetadataStorage` (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Personalizza liberamente il nome della tabella del database (es. `'media_metadata'`). |
| `connection` | `null` | Se configuri più connessioni DBAL in `config/packages/doctrine.yaml` (es. `'media'`), seleziona direttamente quella connessione. |
| `entity_manager` | `'default'` | Entity Manager la cui connessione DBAL viene usata quando `connection` è `null`. |
| `auto_create_table` | `true` | Crea automaticamente la tabella alla prima richiesta se mancante. Imposta `false` in produzione se gestisci lo schema con **Doctrine Migrations**. |

### Passo 1: Abilita `driver: 'doctrine'` in `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Usa il driver integrato DoctrineDbalMetadataStorage al posto di json_file
            connection: null                # Opzionale: nome di una connessione DBAL dedicata (es. 'media')
            entity_manager: 'default'       # Entity Manager usato quando connection è null
            table: 'liveux_fm_metadata'     # Nome della tabella personalizzabile liberamente
            auto_create_table: true         # Imposta false in produzione quando usi Doctrine Migrations
```

### Passo 2 (consigliato in produzione): Migration multipiattaforma (MySQL / MariaDB / PostgreSQL / SQLite)

Per gestire la tabella esplicitamente tramite Doctrine Migrations in produzione, imposta `auto_create_table: false` e usa questa migration consapevole della piattaforma (crea l'indice funzionale `text_pattern_ops` su PostgreSQL e l'indice di prefisso `lengths: [191]` su MySQL/MariaDB/SQLite):

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
        return 'Crea la tabella liveux_fm_metadata con indice di prefisso ottimizzato per la piattaforma.';
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
> **Perché usare `key_hash CHAR(64)` come chiave primaria?** Le chiavi di indice InnoDB `utf8mb4` di MySQL possono incorrere nel limite di 191 caratteri sui percorsi lunghi. L'uso di un hash SHA-256 come chiave primaria consente di gestire senza problemi percorsi annidati fino a 4.096 caratteri — inclusi nomi di file turchi, CJK e arabi — su MySQL, MariaDB, PostgreSQL e SQLite.

### Passo 3 (esempio): Nome tabella personalizzato e connessione database dedicata

Per salvare i metadati media in una tabella `app_file_meta` su una connessione DBAL separata `media`, bastano 3 righe:

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

## 2. Modellazione dei campi file nelle Entity Doctrine

### A. Singola immagine di copertina e galleria JSON multi-immagine (caso più comune)

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

    // FileManagerPickerType (multiple: true) -> list<string> (array JSON)
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

### B. Relazione `OneToMany` + `CollectionType` / `LiveCollectionType`

Quando ciascun elemento ha un proprio titolo, ordinamento e immagine (es. `Slider` → `SliderItem`):

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

Lato form, aggiungi `FileManagerPickerType::class` dentro `SliderItemType` e collegalo al form padre con `CollectionType::class` o `LiveCollectionType::class` (`by_reference => false`). *(Per gli esempi completi di Form e LiveComponent, consulta [05 — Integrazione FormType ed Editor](05-formtype-and-editor-integrations.md).)*

### Meglio scegliere `return_type: 'url'` oppure `'path'`?

| Opzione | Valore salvato nel database | Quando usarla |
| :--- | :--- | :--- |
| **`'url'`** *(predefinito)* | `/uploads/products/thermos.webp` o `https://cdn.example.com/thermos.webp` | Vuoi stampare `<img src="{{ product.coverImage }}">` direttamente nei template senza aggiungere prefissi. |
| **`'path'`** | `products/thermos.webp` *(solo percorso relativo)* | Prevedi di migrare in futuro dallo storage locale a S3/R2/CDN cambiando solo `url_prefix`, senza modificare i record nel database. |

---

### C. Esempio completo end-to-end: Form → Controller → Database → Rendering sul frontend

Ecco l'intero ciclo di vita in un unico punto: **un utente sceglie un'immagine di copertina e una galleria riordinabile in un form → il Controller li salva tramite Doctrine → la pagina di dettaglio del prodotto visualizza le immagini insieme al testo `alt` SEO e al punto focale (`focalPoint`) salvati nel file manager.**

#### 1) Form Symfony (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Nome del prodotto'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Immagine di copertina',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Salva "/uploads/products/thermos.webp" nel DB
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galleria prodotto (trascina per riordinare)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Salva un array JSON: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controller — Salvataggio e lettura dei metadati SEO (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType valida e mappa automaticamente i valori sull'Entity:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Prodotto e immagini salvati nel database.');

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
        // Opzionale: recupera il testo alt SEO e il punto focale della copertina salvati nel file manager
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

#### 3) Template del form di amministrazione (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Nuovo prodotto</h1>

    {# Il tema del form e DialogHost vengono collegati automaticamente dal bundle #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Salva</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Template della scheda prodotto (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Immagine di copertina — usa il punto focale (focalPoint) e il testo alt SEO del file manager se impostati #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galleria multi-immagine (renderizzata nell'esatto ordine drag-and-drop scelto dall'utente!) #}
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
> **Vuoi salvare anche le credenziali del Cloud Storage nel tuo database Doctrine?**
> Consulta **[07 — Cloud Storage e configurazione dinamica](07-cloud-storage-and-dynamic-config.md)** (Sezione 3) per una guida completa su come archiviare in modo cifrato le impostazioni di AWS S3, Cloudflare R2 o Supabase nel database e gestirle dal pannello di amministrazione.

---

## 3. Storage metadati personalizzato con la tua Entity Doctrine ORM (Avanzato)

Se desideri archiviare i metadati nella tua Entity ORM invece di usare il `DoctrineDbalMetadataStorage` integrato (ad esempio per aggiungere una colonna extra `updatedBy`), implementa sia `FolderMetadataStorageInterface` che `ResetInterface` e crea l'alias dell'interfaccia di servizio:

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
        // Previene memory leak e dati obsoleti nei worker FrankenPHP / RoadRunner / Messenger
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
     * Salvataggio in batch dei metadati (usato da MetadataSyncListener::copyFilteredFolderMetadata per evitare scritture N+1).
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

            // Rimuove atomicamente le vecchie righe ed eventuali voci figlie obsolete nella destinazione:
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
     * Distingue le chiavi root di volume ('local::') dalle chiavi di cartelle annidate ('local::products').
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

## 4. Aggiornamento automatico dei riferimenti nel database su rinomina o spostamento

Quando un redattore rinomina o sposta un file (es. `products/old.webp`) o un'intera cartella nel file manager, puoi ascoltare `ItemRenamedEvent` e `ItemMovedEvent` per mantenere sincronizzati automaticamente sia **`Product.coverImage`** sia l'array JSON **`Product.galleryImages`**:

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

## 5. Combinazione con Asset Usage Guard

Per impedire che i file referenziati nelle tabelle Doctrine vengano eliminati, rinominati, spostati o sovrascritti per errore, collega `AssetUsageCheckerInterface` direttamente al tuo `ProductRepository` — consulta **[10 — Asset Usage Guard](10-asset-usage-guard.md)** per un'implementazione completa passo dopo passo.

---

[← Precedente: 12 — AI Agent Skills](12-ai-agent-skills-guide.md) | [Indice della documentazione](README.md)
