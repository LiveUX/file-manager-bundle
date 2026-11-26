# 13 — Guide d'intégration Doctrine ORM & DBAL

[← Précédent : 12 — Agent Skills IA](12-ai-agent-skills-guide.md) | [Index de la documentation](README.md)

**LiveUX FileManagerBundle** n'impose aucune dépendance obligatoire à Doctrine dans son cœur, tout en offrant une intégration de premier ordre pour les applications Symfony utilisant Doctrine ORM ou DBAL dans **4 domaines clés** :

1. **Stocker les métadonnées des dossiers et fichiers en base de données** (`defaults.metadata.driver: 'doctrine'` — stockage DBAL intégré sans aucun code à écrire)
2. **Lier des champs d'entités Doctrine à `FileManagerPickerType`** (colonne simple, galerie JSON, relation `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Écrire un backend de métadonnées personnalisé avec votre propre entité Doctrine ORM** (`FolderMetadataStorageInterface`)
4. **Mettre à jour automatiquement les chemins en base lors du renommage ou du déplacement de fichiers/dossiers** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Stockage de métadonnées Doctrine intégré (`driver: 'doctrine'`)

### En termes simples : à quoi cela sert-il ?

Lorsque vous attribuez une **couleur** (par ex. bleu), une **icône** ou un **état épinglé** à un dossier — ou lorsque vous ajoutez un **texte alternatif (`alt`), un titre, une description, des tags (`#campagne`) ou un point focal** à une image — ces métadonnées ne sont pas écrites à l'intérieur des fichiers eux-mêmes. Elles sont stockées à part.

Le bundle propose **2 pilotes de stockage prêts à l'emploi** :
- **Option 1 — `driver: 'json_file'` (par défaut, serveur unique) :** stocke les métadonnées dans `var/file-manager/metadata.json` sur le disque local. Zéro configuration requise pour les petites applications mono-serveur.
- **Option 2 — `driver: 'doctrine'` (base de données — `DoctrineDbalMetadataStorage`) :** stocke les métadonnées dans une table de votre base **MySQL, MariaDB, PostgreSQL ou SQLite**. **Aucune entité, aucun Repository ni aucun code personnalisé ne sont nécessaires.**

**Quand choisir `driver: 'doctrine'` ?**
- Votre application tourne sur **plusieurs serveurs (Load Balancer / Docker Swarm / Kubernetes)** où un fichier JSON local ne peut pas être partagé, alors que la base de données est centralisée.
- Vous souhaitez que tout l'état applicatif soit inclus dans une seule sauvegarde de base de données.
- Vous avez besoin d'une gestion à haut débit des métadonnées sur des milliers de fichiers et dossiers, sans conflits d'écritures concurrentes.

### Points forts architecturaux du pilote DBAL intégré

- **Zéro requête N+1 (`preload()`) :** lors du listage de répertoires ou d'arborescences de dossiers, les clés sont préchargées par lots de 400 avec des requêtes `SELECT`. Un cache en mémoire borné (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) couplé à `ResetInterface` évite toute fuite mémoire dans les workers persistants FrankenPHP ou Messenger.
- **UPSERT natif atomique :** les sauvegardes concurrentes utilisent `ON CONFLICT (key_hash) DO UPDATE` sous PostgreSQL/SQLite et `ON DUPLICATE KEY UPDATE` sous MySQL/MariaDB — éliminant les conditions de concurrence (*race conditions*).
- **Écritures par lots (`saveMany()`) :** les opérations comme la copie de dossiers exécutent des `DELETE` par paquets (400 lignes) + des `INSERT` multi-lignes (100 lignes) au sein d'une transaction unique.
- **Index B-Tree PostgreSQL `text_pattern_ops` :** crée automatiquement un index fonctionnel `LEFT(canonical_key, 191) text_pattern_ops` et applique des filtres d'intervalle `~>=~` / `~<=~` afin que les recherches par préfixe restent entièrement indexées, même avec une collation glibc UTF-8 `LC_COLLATE`.
- **Validation stricte du nom de table :** `defaults.metadata.table` n'accepte que des identifiants SQL sûrs correspondant à `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Toutes les options de configuration

| Option (`defaults.metadata.*`) | Valeur par défaut | Description |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Définissez `'doctrine'` pour activer `DoctrineDbalMetadataStorage` (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Personnalisez le nom de la table selon vos conventions (ex. `'media_metadata'`). |
| `connection` | `null` | Si vous configurez plusieurs connexions DBAL dans `config/packages/doctrine.yaml` (ex. `'media'`), cible directement cette connexion. |
| `entity_manager` | `'default'` | Entity Manager dont la connexion DBAL est utilisée lorsque `connection` vaut `null`. |
| `auto_create_table` | `true` | Crée automatiquement la table à la première requête si elle est absente. Passez à `false` en production si vous gérez le schéma avec **Doctrine Migrations**. |

### Étape 1 : Activer `driver: 'doctrine'` dans `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Utilise DoctrineDbalMetadataStorage intégré à la place de json_file
            connection: null                # Optionnel : nom de connexion DBAL dédiée (ex. 'media')
            entity_manager: 'default'       # Entity Manager utilisé quand connection vaut null
            table: 'liveux_fm_metadata'     # Nom de table librement personnalisable
            auto_create_table: true         # Passez à false en production avec Doctrine Migrations
```

### Étape 2 (recommandée en production) : Migration multi-SGBD (MySQL / MariaDB / PostgreSQL / SQLite)

Pour gérer la table explicitement via Doctrine Migrations en production, définissez `auto_create_table: false` et utilisez cette migration adaptative selon le SGBD (elle crée l'index fonctionnel `text_pattern_ops` sous PostgreSQL et l'index de préfixe `lengths: [191]` sous MySQL/MariaDB/SQLite) :

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
        return 'Crée la table liveux_fm_metadata avec un index de préfixe optimisé selon le SGBD.';
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
> **Pourquoi utiliser `key_hash CHAR(64)` comme clé primaire ?** Les clés d'index InnoDB `utf8mb4` sous MySQL peuvent atteindre la limite des 191 caractères sur les chemins longs. Utiliser un hachage SHA-256 comme clé primaire permet aux chemins profondément imbriqués jusqu'à 4 096 caractères — y compris avec des noms de fichiers turcs, CJK ou arabes — de fonctionner sans accroc sous MySQL, MariaDB, PostgreSQL et SQLite.

### Étape 3 (exemple) : Nom de table personnalisé & connexion dédiée

Pour stocker les métadonnées médias dans une table `app_file_meta` sur une connexion DBAL séparée nommée `media`, seules 3 lignes suffisent :

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

## 2. Modéliser les champs fichiers dans vos entités Doctrine

### A. Image de couverture simple & galerie JSON multi-images (cas le plus courant)

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

    // FileManagerPickerType (multiple: true) -> list<string> (tableau JSON)
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

### B. Relation `OneToMany` + `CollectionType` / `LiveCollectionType`

Lorsque chaque élément possède son propre titre, ordre d'affichage et image (par ex. `Slider` → `SliderItem`) :

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

Côté formulaire, ajoutez `FileManagerPickerType::class` dans `SliderItemType` et attachez-le au formulaire parent via `CollectionType::class` ou `LiveCollectionType::class` (`by_reference => false`). *(Pour des exemples complets de formulaires et de LiveComponents, consultez [05 — FormType & intégrations éditeurs](05-formtype-and-editor-integrations.md).)*

### Faut-il choisir `return_type: 'url'` ou `'path'` ?

| Option | Valeur stockée en base | Idéal lorsque… |
| :--- | :--- | :--- |
| **`'url'`** *(par défaut)* | `/uploads/products/thermos.webp` ou `https://cdn.example.com/thermos.webp` | Vous souhaitez afficher directement `<img src="{{ product.coverImage }}">` dans vos templates sans préfixage. |
| **`'path'`** | `products/thermos.webp` *(chemin relatif uniquement)* | Vous prévoyez de migrer plus tard du stockage local vers S3/R2/CDN en modifiant uniquement `url_prefix`, sans toucher aux enregistrements en base. |

---

### C. Exemple complet de bout en bout : Formulaire → Contrôleur → Base de données → Rendu front-office

Voici le cycle de vie complet réuni au même endroit : **l'utilisateur sélectionne une image de couverture et une galerie réordonnable dans un formulaire → le contrôleur les persiste avec Doctrine → la page détail produit affiche les images accompagnées du texte `alt` SEO et du point focal (`focalPoint`) enregistrés dans le gestionnaire de fichiers.**

#### 1) Formulaire Symfony (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Nom du produit'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Image de couverture',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Stocke "/uploads/products/thermos.webp" en base
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galerie produit (glisser-déposer pour réordonner)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Stocke un tableau JSON : ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Contrôleur — Persistance & lecture des métadonnées SEO (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // FileManagerPickerType valide et mappe automatiquement les valeurs sur l'entité :
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Le produit et ses images ont été enregistrés en base de données.');

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
        // Optionnel : récupère le texte alt SEO et le point focal de l'image de couverture depuis le gestionnaire
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

#### 3) Template du formulaire d'administration (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Nouveau produit</h1>

    {# Le thème de formulaire et DialogHost sont branchés automatiquement par le bundle #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Enregistrer</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Template de la fiche produit front-office (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Image de couverture — utilise le point focal (focalPoint) et le texte alt SEO du gestionnaire si définis #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galerie multi-images (rendue dans l'ordre exact défini par glisser-déposer par l'utilisateur) #}
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
> **Vous souhaitez aussi stocker vos identifiants Cloud Storage dans votre base Doctrine ?**
> Consultez **[07 — Stockage Cloud & configuration dynamique](07-cloud-storage-and-dynamic-config.md)** (Section 3) pour un guide complet sur le stockage chiffré des paramètres AWS S3, Cloudflare R2 ou Supabase en base de données et leur gestion depuis un panneau d'administration.

---

## 3. Stockage de métadonnées personnalisé avec votre propre entité Doctrine ORM (avancé)

Si vous souhaitez stocker les métadonnées dans votre propre entité ORM plutôt que via `DoctrineDbalMetadataStorage` (par exemple pour ajouter une colonne `updatedBy`), implémentez à la fois `FolderMetadataStorageInterface` et `ResetInterface`, puis créez un alias pour l'interface de service :

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
        // Évite les fuites mémoire et les données obsolètes dans les workers FrankenPHP / RoadRunner / Messenger
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
     * Persistance par lots des métadonnées (utilisée par MetadataSyncListener::copyFilteredFolderMetadata pour éviter les écritures N+1).
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

            // Supprime atomiquement les anciennes lignes et d'éventuelles entrées enfants obsolètes à destination :
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
     * Distingue les clés racine de volume ('local::') des clés de dossiers imbriqués ('local::products').
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

## 4. Mettre à jour automatiquement les références en base lors d'un renommage ou déplacement

Lorsqu'un éditeur renomme ou déplace un fichier (ex. `products/old.webp`) ou tout un dossier dans le gestionnaire de fichiers, vous pouvez écouter `ItemRenamedEvent` et `ItemMovedEvent` afin de maintenir automatiquement synchronisés aussi bien **`Product.coverImage`** que le tableau JSON **`Product.galleryImages`** :

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

## 5. Combiner avec Asset Usage Guard

Pour empêcher que des fichiers référencés dans vos tables Doctrine ne soient supprimés, renommés, déplacés ou écrasés par erreur, branchez directement `AssetUsageCheckerInterface` sur votre `ProductRepository` — consultez **[10 — Protection des fichiers utilisés (Asset Usage Guard)](10-asset-usage-guard.md)** pour une implémentation complète étape par étape.

---

[← Précédent : 12 — Agent Skills IA](12-ai-agent-skills-guide.md) | [Index de la documentation](README.md)
