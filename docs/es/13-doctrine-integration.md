# 13 — Guía de integración con Doctrine ORM y DBAL

[← Anterior: 12 — Habilidades para agentes de IA](12-ai-agent-skills-guide.md) | [Índice de documentación](README.md)

**LiveUX FileManagerBundle** no tiene ninguna dependencia obligatoria de Doctrine en su núcleo, pero ofrece integración de primera clase para aplicaciones Symfony que utilizan Doctrine ORM o DBAL en **4 áreas clave**:

1. **Almacenar los metadatos de carpetas y archivos en tu base de datos** (`defaults.metadata.driver: 'doctrine'` — almacenamiento DBAL integrado sin escribir código)
2. **Vincular campos de entidades Doctrine con `FileManagerPickerType`** (columna simple, galería JSON, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Implementar un backend de metadatos personalizado con tu propia entidad Doctrine ORM** (`FolderMetadataStorageInterface`)
4. **Actualizar automáticamente las rutas en base de datos al renombrar o mover archivos o carpetas** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Almacenamiento integrado de metadatos con Doctrine (`driver: 'doctrine'`)

### En términos claros: ¿qué hace esto?

Cuando asignas un **color** (por ejemplo, azul), un **icono** o un **estado fijado** a una carpeta —o añades **texto alternativo (`alt`), título, descripción, etiquetas (`#campaign`) o un punto focal** a una imagen—, esos metadatos no se incrustan dentro de los propios archivos, sino que se almacenan aparte.

El bundle incluye **2 drivers de almacenamiento listos para usar**:
- **Opción 1 — `driver: 'json_file'` (por defecto, servidor único):** Guarda los metadatos en `var/file-manager/metadata.json` en el disco local. Cero configuración para aplicaciones pequeñas desplegadas en un solo servidor.
- **Opción 2 — `driver: 'doctrine'` (base de datos — `DoctrineDbalMetadataStorage`):** Guarda los metadatos en una tabla de tu base de datos **MySQL, MariaDB, PostgreSQL o SQLite**. **No requiere crear ninguna entidad, repositorio ni código personalizado.**

**¿Cuándo conviene elegir `driver: 'doctrine'`?**
- Tu aplicación se ejecuta en **múltiples servidores (Load Balancer / Docker Swarm / Kubernetes)** donde un archivo JSON local no se puede compartir, mientras que la base de datos es centralizada.
- Quieres incluir todo el estado de la aplicación en una sola copia de seguridad de base de datos.
- Necesitas gestionar metadatos a gran escala sobre miles de archivos y carpetas sin conflictos por escrituras concurrentes.

### Aspectos clave de arquitectura del driver DBAL integrado

- **Cero consultas N+1 (`preload()`):** Al listar directorios o árboles de carpetas, las claves se cargan en lote mediante consultas `SELECT` en bloques de 400. Una caché en memoria acotada (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) combinada con `ResetInterface` evita fugas de memoria en workers de larga duración de FrankenPHP o Messenger.
- **UPSERT nativo atómico:** Los guardados concurrentes usan `ON CONFLICT (key_hash) DO UPDATE` en PostgreSQL/SQLite y `ON DUPLICATE KEY UPDATE` en MySQL/MariaDB, eliminando condiciones de carrera.
- **Escrituras en lote (`saveMany()`):** Operaciones como la copia de carpetas ejecutan sentencias `DELETE` por lotes (400 filas) + `INSERT` multifila (100 filas) dentro de una única transacción de base de datos.
- **Índice B-Tree `text_pattern_ops` en PostgreSQL:** Crea automáticamente un índice funcional `LEFT(canonical_key, 191) text_pattern_ops` y aplica filtros de rango `~>=~` / `~<=~` para que las consultas por prefijo sigan aprovechando el índice incluso con `LC_COLLATE` UTF-8 de glibc.
- **Validación estricta del nombre de tabla:** `defaults.metadata.table` solo admite identificadores SQL seguros que cumplan `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Todas las opciones de configuración

| Opción (`defaults.metadata.*`) | Valor por defecto | Descripción |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Establécelo en `'doctrine'` para activar `DoctrineDbalMetadataStorage` (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Personaliza el nombre de la tabla según necesites (por ejemplo, `'media_metadata'`). |
| `connection` | `null` | Si configuras varias conexiones DBAL en `config/packages/doctrine.yaml` (por ejemplo, `'media'`), apunta directamente a esa conexión. |
| `entity_manager` | `'default'` | Entity Manager cuya conexión DBAL se emplea cuando `connection` es `null`. |
| `auto_create_table` | `true` | Crea la tabla automáticamente en la primera petición si no existe. Ponlo en `false` en producción si gestionas el esquema con **Doctrine Migrations**. |

### Paso 1: Activa `driver: 'doctrine'` en `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Usa DoctrineDbalMetadataStorage integrado en lugar de json_file
            connection: null                # Opcional: nombre de conexión DBAL personalizada (p. ej., 'media')
            entity_manager: 'default'       # Entity Manager utilizado cuando connection es null
            table: 'liveux_fm_metadata'     # Personaliza el nombre de la tabla libremente
            auto_create_table: true         # Ponlo en false en producción si usas Doctrine Migrations
```

### Paso 2 (recomendado para producción): migración multiplataforma (MySQL / MariaDB / PostgreSQL / SQLite)

Para gestionar la tabla explícitamente mediante Doctrine Migrations en producción, establece `auto_create_table: false` y utiliza esta migración adaptada a cada motor (crea el índice funcional `text_pattern_ops` en PostgreSQL y el índice de prefijo `lengths: [191]` en MySQL/MariaDB/SQLite):

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
        return 'Crea la tabla liveux_fm_metadata con un índice de prefijo optimizado por plataforma.';
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
> **¿Por qué usar `key_hash CHAR(64)` como clave primaria?** Las claves de índice InnoDB con `utf8mb4` en MySQL pueden chocar con el límite de 191 caracteres en rutas largas. Usar un hash SHA-256 como clave primaria permite almacenar rutas profundamente anidadas de hasta 4.096 caracteres —incluyendo nombres en español, turco, CJK y árabe— sin incidencias en MySQL, MariaDB, PostgreSQL y SQLite.

### Paso 3 (ejemplo): nombre de tabla personalizado y conexión de base de datos dedicada

Para almacenar los metadatos multimedia en una tabla `app_file_meta` sobre una conexión DBAL independiente llamada `media`, solo hay que cambiar 3 líneas:

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

## 2. Modelado de campos de archivo en entidades Doctrine

### A. Imagen de portada única y galería JSON multiimagen (caso más habitual)

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

### B. Relación `OneToMany` + `CollectionType` / `LiveCollectionType`

Cuando cada elemento tiene su propio título, orden e imagen (por ejemplo, `Slider` → `SliderItem`):

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

En el formulario, añade `FileManagerPickerType::class` dentro de `SliderItemType` y conéctalo al formulario padre con `CollectionType::class` o `LiveCollectionType::class` (`by_reference => false`). *(Para ejemplos completos de formularios y LiveComponents, consulta [05 — FormType e integraciones con editores](05-formtype-and-editor-integrations.md).)*

### ¿Conviene elegir `return_type: 'url'` o `'path'`?

| Opción | Qué se guarda en base de datos | Cuándo es la mejor opción |
| :--- | :--- | :--- |
| **`'url'`** *(por defecto)* | `/uploads/products/thermos.webp` o `https://cdn.example.com/thermos.webp` | Quieres renderizar `<img src="{{ product.coverImage }}">` directamente en las plantillas sin añadir prefijos. |
| **`'path'`** | `products/thermos.webp` *(solo ruta relativa)* | Planeas migrar más adelante de almacenamiento local a S3/R2/CDN cambiando únicamente `url_prefix`, sin tocar los registros de la base de datos. |

---

### C. Ejemplo funcional completo de extremo a extremo: Formulario → Controlador → Base de datos → Vista pública

Aquí tienes el ciclo de vida completo en un mismo sitio: **un usuario selecciona una imagen de portada y una galería ordenable en un formulario → el controlador las persiste mediante Doctrine → la página de detalle del producto muestra las imágenes junto con el texto `alt` SEO y el punto focal (`focalPoint`) guardados en el gestor de archivos.**

#### 1) Formulario Symfony (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Nombre del producto'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Imagen de portada',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Guarda "/uploads/products/thermos.webp" en la BD
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galería del producto (arrastra para reordenar)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Guarda un array JSON: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controlador — Persistir y leer metadatos SEO (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType valida y asigna automáticamente los valores sobre la entidad:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'El producto y sus imágenes se han guardado en la base de datos.');

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
        // Opcional: recupera el texto alt SEO y el punto focal de la imagen de portada guardados en el gestor
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

#### 3) Plantilla del formulario de administración (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Nuevo producto</h1>

    {# El tema del formulario y DialogHost los conecta el bundle automáticamente #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Guardar</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Plantilla de detalle en la tienda (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Imagen de portada — usa el punto focal (focalPoint) y el texto alt SEO del gestor si están definidos #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galería multiimagen (renderizada en el orden exacto elegido mediante arrastrar y soltar) #}
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
> **¿Quieres guardar también las credenciales de almacenamiento en la nube en tu base de datos Doctrine?**
> Consulta **[07 — Almacenamiento en la nube y configuración dinámica](07-cloud-storage-and-dynamic-config.md)** (Sección 3) para ver una guía completa sobre cómo almacenar credenciales cifradas de AWS S3, Cloudflare R2 o Supabase en tu base de datos y administrarlas desde un panel de control.

---

## 3. Almacenamiento de metadatos personalizado con tu propia entidad Doctrine ORM (avanzado)

Si prefieres guardar los metadatos en tu propia entidad ORM en lugar de usar `DoctrineDbalMetadataStorage` integrado (por ejemplo, para añadir una columna extra `updatedBy`), implementa tanto `FolderMetadataStorageInterface` como `ResetInterface` y define un alias para la interfaz del servicio:

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
        // Evita fugas de memoria y datos obsoletos en workers de FrankenPHP / RoadRunner / Messenger
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
     * Persistencia de metadatos en lote (usada por MetadataSyncListener::copyFilteredFolderMetadata para evitar escrituras N+1).
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

            // Elimina de forma atómica las filas antiguas y cualquier entrada hija obsoleta en el destino:
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
     * Distingue claves de raíz de volumen ('local::') de claves de carpetas anidadas ('local::products').
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

## 4. Actualización automática de referencias en base de datos al renombrar o mover

Cuando un editor renombra o mueve un archivo (por ejemplo, `products/old.webp`) o una carpeta completa dentro del gestor de archivos, puedes escuchar `ItemRenamedEvent` e `ItemMovedEvent` para mantener sincronizados automáticamente tanto **`Product.coverImage`** como el array JSON **`Product.galleryImages`**:

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

## 5. Combinación con Asset Usage Guard

Para evitar que los archivos referenciados en tus tablas Doctrine sean eliminados, renombrados, movidos o sobrescritos por accidente, conecta `AssetUsageCheckerInterface` directamente con tu `ProductRepository`: consulta **[10 — Protección de activos en uso (Asset Usage Guard)](10-asset-usage-guard.md)** para ver una implementación completa paso a paso.

---

[← Anterior: 12 — Habilidades para agentes de IA](12-ai-agent-skills-guide.md) | [Índice de documentación](README.md)
