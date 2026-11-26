# 10 — Protection contre la suppression de médias utilisés (Asset Usage Guard)

[← Précédent : 09 — Intégration Symfony AI](09-symfony-ai-integration.md) | [Index principal](README.md) | [Suivant : 11 — Guide de contribution & PR →](11-contributing-and-pr-guide.md)

---

## 1. Quel problème cette fonctionnalité résout-elle ? (Cas concret)

Imaginez qu'un éditeur de votre back-office e-commerce ou CMS tente de **supprimer, renommer, déplacer ou écraser** `uploads/products/matte-black-thermos.webp` (ou tout le dossier `uploads/products`).

- **Sans Usage Guard :** le fichier est supprimé ou déplacé immédiatement — et si 3 fiches produits ainsi qu'une bannière de page d'accueil y font référence, votre site en production affiche désormais des **images cassées en erreur `404`**.
- **Avec Usage Guard activé :** le gestionnaire de fichiers interroge votre application : *« Ce fichier ou ce dossier est-il actuellement référencé quelque part en base de données ? »*
  - Si des références existent, la fenêtre modale de confirmation liste **chaque produit, bannière ou page qui l'utilise**, accompagné d'un lien cliquable **« Ouvrir ↗ »**.
  - Avec **`policy: 'warn'`**, elle affiche un bandeau d'avertissement jaune et propose un bouton **« Supprimer quand même »**.
  - Avec **`policy: 'block'`** et **`enforce_server_side: true`** (comportement par défaut), elle affiche un bandeau rouge bloquant, **masque** le bouton Supprimer et rejette côté serveur **les 4 opérations destructrices** :
    1. **Suppression (`delete`)** → `errors.delete_blocked_in_use`
    2. **Renommage (`rename`)** → `errors.rename_blocked_in_use`
    3. **Déplacement (`move`)** → `errors.move_blocked_in_use`
    4. **Écrasement (`overwrite`)** — incluant les téléversements (`overwrite_policy: 'overwrite'`), l'éditeur d'images et l'extraction ZIP → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Pourquoi devez-vous écrire une petite classe PHP ?**
> LiveUX FileManagerBundle ne peut pas connaître les tables de votre base de données (`Product`, `BlogPost`, `Slider`, `Category`, etc.) ni le nom de leurs colonnes. À la place, il vous fournit un contrat simple à une seule méthode (`AssetUsageCheckerInterface`). Vous interrogez vos tables et retournez les références trouvées — le bundle gère automatiquement la modale d'interface, la validation des URL, la déduplication, les vérifications de préfixe de dossier et le blocage côté serveur des 4 opérations.

---

## 2. Fonctionnement étape par étape

```text
[L'éditeur clique sur « Supprimer »]
        │
        ▼
[1. Le navigateur envoie POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> invoque chaque service AssetUsageCheckerInterface de votre application]
        │
        ├── AUCUNE utilisation ──► « Aucune référence active trouvée ✓ » -> Confirmation classique
        │
        └── Utilisations TROUVÉES ──► La modale liste les fiches liées + liens « Ouvrir ↗ » :
                                       • policy: 'warn'  -> Bandeau jaune + « Supprimer quand même »
                                       • policy: 'block' -> Bandeau rouge bloquant (bouton Supprimer masqué)
                                                            + Le serveur rejette delete / rename / move / overwrite !
```

---

## 3. Exemple complet de bout en bout (prêt à copier-coller)

Suivez ces 4 étapes pour mettre en place **Usage Guard** dans une application Symfony + Doctrine (compatible MySQL, MariaDB, PostgreSQL et SQLite) en moins de 5 minutes.

### Étape 1 : Activer `usage_guard` sur votre profil

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Stockage local'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Active la protection pour ce profil (par défaut : false)
                policy: 'block'           # 'warn' (avertit + autorise le forçage) | 'block' (bloque strictement)
                enforce_server_side: true # true = bloque aussi delete, rename, move & overwrite côté serveur (par défaut : true)
                max_references: 20        # Nombre maximal de références affichées dans la modale (1..200)
```

### Étape 2 : Exemple d'entité Doctrine (`Product`)

Supposons que votre entité `Product` stocke une image de couverture (`?string`) et un tableau JSON de galerie (`list<string>`) :

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

    // ex. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> ex. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Étape 3 : Requêtes de repository portables (`ProductRepository`)

Ces requêtes utilisent la syntaxe portable **`ESCAPE '!'`** afin de fonctionner de manière identique sur MySQL, MariaDB, PostgreSQL et SQLite :

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
     * Pour un fichier unique : recherche les produits référençant l'URL en couverture ou dans la galerie JSON.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Encadrer par des guillemets doubles ("/uploads/products/a.webp") évite les faux positifs par correspondance partielle dans le JSON
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
     * Pour un dossier : recherche les produits référençant tout fichier situé sous le préfixe de ce dossier.
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

### Étape 4 : Implémenter votre vérificateur d'utilisation (`ProductAssetUsageChecker`)

**Aucune entrée dans `services.yaml` n'est nécessaire !** Comme `AssetUsageCheckerInterface` porte l'attribut `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`, Symfony découvre et enregistre automatiquement votre service.

> [!IMPORTANT]
> **Noms de fichiers contenant des espaces ou des caractères non-ASCII :**
> `DirectoryLister::buildPublicUrl()` encode chaque segment de chemin avec `rawurlencode()` (ex. `products/catalogue été.pdf` → `/uploads/products/catalogue%20%C3%A9t%C3%A9.pdf`). Utilisez l'utilitaire `buildPublicUrl()` ci-dessous afin que vos requêtes correspondent exactement aux URL stockées (si vos formulaires enregistrent des chemins relatifs via `return_type: 'path'`, effectuez plutôt la comparaison sur `ltrim($path, '/')`).

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
        // Optionnel : limiter ce vérificateur à certains profils uniquement
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // ex. "Thermos Noir Mat (#42)"
                type: 'Produit',                                            // Libellé du badge
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Lien cliquable « Ouvrir ↗ »
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Utilisé pour la déduplication
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

### Étape 5 (Optionnelle) : Ajouter d'autres vérificateurs pour d'autres tables

Créez des classes dédiées supplémentaires implémentant `AssetUsageCheckerInterface` pour vos bannières, articles de blog ou catégories — le bundle agrège et déduplique automatiquement l'ensemble des résultats dans une seule modale :

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
                type: 'Bannière',
                editUrl: $this->urlGenerator->generate('admin_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. Vérifier votre configuration

1. Videz le cache :
   ```bash
   php bin/console cache:clear
   ```
2. Vérifiez que votre service est bien tagué et découvert :
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Vous devez voir `App\FileManager\ProductAssetUsageChecker` apparaître dans la sortie.
3. Enregistrez une URL d'image sélectionnée depuis le gestionnaire dans la colonne `cover_image` d'un produit (ex. `/uploads/products/matte-black-thermos.webp`).
4. Faites un clic droit sur cette image (ou sur son dossier parent `products`) dans le gestionnaire puis cliquez sur **Supprimer** :
   - La modale s'ouvre, affiche *« Vérification des utilisations actives… »*, puis liste **« Thermos Noir Mat (#42) [Produit] — Ouvrir ↗ »**.
   - Avec `policy: 'block'`, le bouton Supprimer est masqué et toute tentative de `rename`, `move` ou `overwrite` sur ce média est également rejetée côté serveur.

---

## 5. Comparatif `warn` vs `block` & Notes de sécurité

| Politique | Comportement de la modale de suppression | Application côté serveur (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(par défaut)* | Affiche un bandeau d'avertissement jaune listant les références trouvées ; le bouton d'action indique **« Supprimer quand même »**. | Autorise la suppression, le renommage, le déplacement et l'écrasement après confirmation de l'éditeur. |
| **`block`** | Affiche un bandeau rouge bloquant avec les références et les liens **« Ouvrir ↗ »** ; le bouton Supprimer est **masqué**. | Défense en profondeur : rejette **`delete`**, **`rename`**, **`move`** et **`overwrite`** même si l'API est appelée directement. |

- **Assainissement des liens (`editUrl`) :** `editUrl` est validé à la fois en PHP (`AssetUsageGuardService::sanitizeEditUrl()`) et en JavaScript (`isSafeUrl()`), n'autorisant que les chemins relatifs à la racine (`/admin/...`) ou les URL `http(s)://...` (les URL `javascript:`, `data:` et sans schéma `//evil.com` sont rejetées).
- **Permissions & bornes de requêtes (`checkUsage`) :** le point d'entrée `checkUsage` est protégé par CSRF, vérifie à la fois **`FILE_MANAGER_VIEW`** et **`FILE_MANAGER_DELETE`** sur chaque chemin cible ainsi que sur tous ses dossiers parents, plafonne les requêtes à **200 éléments** (`MAX_BULK_DELETE_ITEMS`), et bloque les chemins masqués ou situés dans la corbeille (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Lorsque la confirmation de suppression est désactivée (`ui.confirm_delete: false`) :** si `usage_guard.enabled: true`, la vérification d'utilisation s'exécute malgré tout en arrière-plan et ouvre automatiquement la modale d'avertissement ou de blocage dès que des références actives sont détectées.

---

[← Précédent : 09 — Intégration Symfony AI](09-symfony-ai-integration.md) | [Index principal](README.md) | [Suivant : 11 — Guide de contribution & PR →](11-contributing-and-pr-guide.md)
