# 13 — Integracja z Doctrine ORM i DBAL

[← Poprzedni: 12 — Umiejętności agentów AI](12-ai-agent-skills-guide.md) | [Spis treści dokumentacji](README.md)

**LiveUX FileManagerBundle** nie wymaga Doctrine jako obowiązkowej zależności w swoim rdzeniu, ale zapewnia pełnoprawną, dopracowaną integrację dla aplikacji Symfony korzystających z Doctrine ORM lub DBAL w **4 kluczowych obszarach**:

1. **Przechowywanie metadanych folderów i plików w bazie danych** (`defaults.metadata.driver: 'doctrine'` — wbudowany magazyn DBAL niewymagający pisania kodu)
2. **Powiązanie pól encji Doctrine z `FileManagerPickerType`** (pojedyncza kolumna, galeria JSON, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Napisanie własnego backendu metadanych z użyciem własnej encji Doctrine ORM** (`FolderMetadataStorageInterface`)
4. **Automatyczna aktualizacja ścieżek w bazie danych po zmianie nazwy lub przeniesieniu plików i folderów** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Wbudowany magazyn metadanych Doctrine (`driver: 'doctrine'`)

### W prostych słowach: do czego to służy?

Kiedy przypisujesz folderowi **kolor** (np. niebieski), **ikonę** lub **przypięcie**, albo dodajesz do obrazu **tekst alternatywny (`alt`), tytuł, opis, tagi (`#kampania`) lub punkt skupienia (*focal point*)**, metadane te nie są zapisywane wewnątrz samych plików. Są przechowywane osobno.

Bundle udostępnia **2 gotowe sterowniki zapisu**:
- **Opcja 1 — `driver: 'json_file'` (domyślny, pojedynczy serwer):** Zapisuje metadane w pliku `var/file-manager/metadata.json` na lokalnym dysku. Zero konfiguracji dla mniejszych aplikacji działających na jednym serwerze.
- **Opcja 2 — `driver: 'doctrine'` (baza danych — `DoctrineDbalMetadataStorage`):** Przechowuje metadane w tabeli wewnątrz Twojej bazy **MySQL, MariaDB, PostgreSQL lub SQLite**. **Nie wymaga żadnej encji, repozytorium ani własnego kodu.**

**Kiedy warto wybrać `driver: 'doctrine'`?**
- Aplikacja działa na **wielu serwerach (Load Balancer / Docker Swarm / Kubernetes)**, gdzie lokalny plik JSON nie może być współdzielony, natomiast baza danych jest scentralizowana.
- Chcesz, aby cały stan aplikacji znajdował się w jednej kopii zapasowej bazy danych.
- Potrzebujesz wydajnego zarządzania metadanymi dla tysięcy plików i folderów bez konfliktów przy współbieżnym zapisie.

### Najważniejsze cechy architektoniczne wbudowanego sterownika DBAL

- **Zero zapytań N+1 (`preload()`):** Podczas listowania katalogów lub drzewa folderów klucze są pobierane zbiorczo zapytaniami `SELECT` w paczkach po 400 rekordów. Ograniczony cache w pamięci (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) połączony z `ResetInterface` zapobiega wyciekom pamięci w długodziałających workerach FrankenPHP i Messenger.
- **Atomowy, natywny UPSERT:** Współbieżne zapisy wykorzystują `ON CONFLICT (key_hash) DO UPDATE` w PostgreSQL/SQLite oraz `ON DUPLICATE KEY UPDATE` w MySQL/MariaDB — eliminując wyścigi (*race conditions*).
- **Zapisy wsadowe (`saveMany()`):** Operacje takie jak kopiowanie folderów wykonują porcjowane instrukcje `DELETE` (po 400 wierszy) + wielowierszowe `INSERT` (po 100 wierszy) w ramach jednej transakcji bazodanowej.
- **Indeks B-Tree `text_pattern_ops` w PostgreSQL:** Automatycznie tworzy indeks wyrażeniowy `LEFT(canonical_key, 191) text_pattern_ops` i stosuje filtry zakresowe `~>=~` / `~<=~`, dzięki czemu zapytania prefiksowe pozostają w pełni indeksowane nawet przy kolacji glibc UTF-8 `LC_COLLATE`.
- **Ścisła walidacja nazwy tabeli:** Opcja `defaults.metadata.table` akceptuje wyłącznie bezpieczne identyfikatory SQL zgodne z wyrażeniem `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Wszystkie opcje konfiguracji

| Opcja (`defaults.metadata.*`) | Wartość domyślna | Opis |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Ustaw `'doctrine'`, aby włączyć `DoctrineDbalMetadataStorage` (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Pozwala dostosować nazwę tabeli w bazie danych (np. `'media_metadata'`). |
| `connection` | `null` | Jeśli konfigurujesz wiele połączeń DBAL w `config/packages/doctrine.yaml` (np. `'media'`), wskazuje bezpośrednio wybrane połączenie. |
| `entity_manager` | `'default'` | Entity Manager, którego połączenie DBAL jest używane, gdy `connection` ma wartość `null`. |
| `auto_create_table` | `true` | Automatycznie tworzy tabelę przy pierwszym żądaniu, jeśli nie istnieje. Na produkcji ustaw `false`, jeśli zarządzasz schematem przez **Doctrine Migrations**. |

### Krok 1: Włącz `driver: 'doctrine'` w pliku `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Używa wbudowanego DoctrineDbalMetadataStorage zamiast json_file
            connection: null                # Opcjonalnie: nazwa własnego połączenia DBAL (np. 'media')
            entity_manager: 'default'       # Entity Manager używany, gdy connection ma wartość null
            table: 'liveux_fm_metadata'     # Nazwę tabeli można dowolnie dostosować
            auto_create_table: true         # Na produkcji ustaw false, jeśli używasz Doctrine Migrations
```

### Krok 2 (zalecane na produkcji): Wieloplatformowa migracja (MySQL / MariaDB / PostgreSQL / SQLite)

Aby zarządzać tabelą jawnie przez Doctrine Migrations na produkcji, ustaw `auto_create_table: false` i użyj poniższej migracji dopasowanej do silnika bazy danych (tworzy indeks funkcyjny `text_pattern_ops` na PostgreSQL oraz indeks prefiksowy `lengths: [191]` na MySQL/MariaDB/SQLite):

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
        return 'Tworzy tabelę liveux_fm_metadata z indeksem prefiksowym zoptymalizowanym pod silnik bazy danych.';
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
> **Dlaczego kluczem głównym jest `key_hash CHAR(64)`?** W MySQL z kodowaniem `utf8mb4` i silnikiem InnoDB klucze indeksów mogą natrafić na limit 191 znaków przy długich ścieżkach. Zastosowanie skrótu SHA-256 jako klucza głównego sprawia, że głęboko zagnieżdżone ścieżki o długości do 4096 znaków — w tym nazwy z polskimi, tureckimi, azjatyckimi (CJK) i arabskimi znakami — działają bezproblemowo na MySQL, MariaDB, PostgreSQL oraz SQLite.

### Krok 3 (przykład): Własna nazwa tabeli i dedykowane połączenie bazodanowe

Aby przechowywać metadane mediów w tabeli `app_file_meta` na osobnym połączeniu DBAL `media`, wystarczy zmienić zaledwie 3 wiersze:

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

## 2. Modelowanie pól plików w encjach Doctrine

### A. Pojedyncze zdjęcie okładkowe i wieloobrazkowa galeria JSON (najczęstszy przypadek)

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

    // FileManagerPickerType (multiple: true) -> list<string> (tablica JSON)
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

### B. Relacja `OneToMany` + `CollectionType` / `LiveCollectionType`

Gdy każdy element ma własny tytuł, kolejność sortowania i obraz (np. `Slider` → `SliderItem`):

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

Po stronie formularza dodaj `FileManagerPickerType::class` wewnątrz `SliderItemType`, a następnie podepnij go do formularza nadrzędnego za pomocą `CollectionType::class` lub `LiveCollectionType::class` (`by_reference => false`). *(Pełne przykłady formularzy i LiveComponent znajdziesz w rozdziale [05 — FormType i integracje z edytorami](05-formtype-and-editor-integrations.md).)*

### Wybrać `return_type: 'url'` czy `'path'`?

| Opcja | Zapis w bazie danych | Kiedy sprawdza się najlepiej |
| :--- | :--- | :--- |
| **`'url'`** *(domyślna)* | `/uploads/products/thermos.webp` lub `https://cdn.example.com/thermos.webp` | Gdy chcesz renderować `<img src="{{ product.coverImage }}">` bezpośrednio w szablonach bez doklejania prefiksu. |
| **`'path'`** | `products/thermos.webp` *(wyłącznie ścieżka względna)* | Gdy planujesz w przyszłości migrację z dysku lokalnego na S3/R2/CDN poprzez samą zmianę `url_prefix`, bez modyfikowania rekordów w bazie danych. |

---

### C. Kompletny działający przykład end-to-end: Formularz → Kontroler → Baza danych → Widok sklepu

Poniżej znajduje się pełny cykl życia w jednym miejscu: **użytkownik wybiera w formularzu zdjęcie okładkowe oraz sortowalną galerię → Kontroler zapisuje je przez Doctrine → strona szczegółów produktu renderuje obrazy wraz z tekstem SEO `alt` i punktem skupienia (`focalPoint`) zapisanymi w menedżerze plików.**

#### 1) Formularz Symfony (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Nazwa produktu'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Zdjęcie okładkowe',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Zapisuje w bazie "/uploads/products/thermos.webp"
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galeria produktu (przeciągnij, aby zmienić kolejność)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Zapisuje tablicę JSON: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Kontroler — zapis i odczyt metadanych SEO (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType automatycznie waliduje i mapuje wartości na encję:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Produkt oraz obrazy zostały zapisane w bazie danych.');

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
        // Opcjonalnie: pobierz tekst SEO alt i punkt skupienia zdjęcia okładkowego zapisane w menedżerze plików
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

#### 3) Szablon formularza w panelu administracyjnym (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Nowy produkt</h1>

    {# Motyw formularza i DialogHost są podpinane automatycznie przez bundle #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Zapisz</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Szablon karty produktu w sklepie (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Zdjęcie okładkowe — z użyciem punktu skupienia (focalPoint) i tekstu SEO alt z menedżera plików, jeśli zostały ustawione #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galeria wieloobrazkowa (renderowana dokładnie w kolejności ustalonej przez użytkownika metodą przeciągnij i upuść!) #}
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
> **Chcesz przechowywać w bazie Doctrine także dane dostępowe do chmury (Cloud Storage)?**
> Zobacz **[07 — Pamięć w chmurze i dynamiczna konfiguracja](07-cloud-storage-and-dynamic-config.md)** (Sekcja 3), gdzie znajdziesz kompletny opis przechowywania zaszyfrowanych ustawień AWS S3, Cloudflare R2 lub Supabase w bazie danych i zarządzania nimi z poziomu panelu administracyjnego.

---

## 3. Własny magazyn metadanych z użyciem własnej encji Doctrine ORM (zaawansowane)

Jeśli zamiast wbudowanego `DoctrineDbalMetadataStorage` wolisz przechowywać metadane we własnej encji ORM (na przykład po to, by dodać kolumnę `updatedBy`), zaimplementuj jednocześnie `FolderMetadataStorageInterface` oraz `ResetInterface` i zdefiniuj alias dla interfejsu serwisu:

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
        // Zapobiega wyciekom pamięci i nieaktualnym danym w workerach FrankenPHP / RoadRunner / Messenger
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
     * Zbiorczy zapis metadanych (używany przez MetadataSyncListener::copyFilteredFolderMetadata, aby uniknąć zapisów N+1).
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

            // Atomowo usuwa stare wiersze oraz ewentualne nieaktualne wpisy potomne w lokalizacji docelowej:
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
     * Rozróżnia klucze głównego katalogu wolumenu ('local::') od kluczy zagnieżdżonych folderów ('local::products').
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

## 4. Automatyczna aktualizacja referencji w bazie danych po zmianie nazwy lub przeniesieniu

Gdy redaktor zmieni nazwę lub przeniesie plik (np. `products/old.webp`) albo cały folder w menedżerze plików, możesz nasłuchiwać zdarzeń `ItemRenamedEvent` i `ItemMovedEvent`, aby automatycznie utrzymywać spójność zarówno kolumny **`Product.coverImage`**, jak i tablicy JSON **`Product.galleryImages`**:

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

## 5. Połączenie z ochroną używanych zasobów (Asset Usage Guard)

Aby uniemożliwić przypadkowe usunięcie, zmianę nazwy, przeniesienie lub nadpisanie plików używanych w tabelach Doctrine, połącz `AssetUsageCheckerInterface` bezpośrednio z `ProductRepository` — pełną implementację krok po kroku znajdziesz w rozdziale **[10 — Ochrona używanych zasobów](10-asset-usage-guard.md)**.

---

[← Poprzedni: 12 — Umiejętności agentów AI](12-ai-agent-skills-guide.md) | [Spis treści dokumentacji](README.md)
