# 10 — Protección de activos en uso (Asset Usage Guard)

[← Anterior: 09 — Integración con Symfony AI](09-symfony-ai-integration.md) | [Índice principal](README.md) | [Siguiente: 11 — Guía de contribución y PR →](11-contributing-and-pr-guide.md)

---

## 1. ¿Qué problema resuelve? (Caso real)

Imagina que un editor en tu tienda online o panel CMS intenta **eliminar, renombrar, mover o sobrescribir** `uploads/products/matte-black-thermos.webp` (o toda la carpeta `uploads/products`).

- **Sin Asset Usage Guard:** el archivo se elimina o mueve al instante y, si 3 fichas de producto y 1 banner de portada lo referencian, tu sitio en producción empieza a mostrar **imágenes rotas (`404`)**.
- **Con Asset Usage Guard activado:** el gestor de archivos consulta a tu aplicación: *"¿Este archivo o carpeta está referenciado actualmente en algún lugar de la base de datos?"*
  - Si existen referencias, el modal de confirmación enumera **cada producto, banner o página que lo utiliza**, acompañado de un enlace clicable **"Abrir registro ↗"**.
  - Con **`policy: 'warn'`**, muestra un aviso amarillo y ofrece el botón **"Eliminar de todos modos"**.
  - Con **`policy: 'block'`** y **`enforce_server_side: true`** (valor por defecto), muestra un bloqueo en rojo, **oculta** el botón de eliminar y rechaza **las 4 operaciones destructivas** en el servidor:
    1. **Eliminar (`delete`)** → `errors.delete_blocked_in_use`
    2. **Renombrar (`rename`)** → `errors.rename_blocked_in_use`
    3. **Mover (`move`)** → `errors.move_blocked_in_use`
    4. **Sobrescribir (`overwrite`)** — incluidas subidas (`overwrite_policy: 'overwrite'`), el editor de imágenes y la extracción de ZIP → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **¿Por qué necesitas implementar una pequeña clase PHP?**
> LiveUX FileManagerBundle no puede conocer las tablas de base de datos de tu aplicación (`Product`, `BlogPost`, `Slider`, `Category`, etc.) ni los nombres de sus columnas. En su lugar, te ofrece un contrato limpio de un solo método (`AssetUsageCheckerInterface`). Tú consultas tus tablas y devuelves las referencias coincidentes; el bundle se encarga automáticamente del modal en la interfaz, la sanitización de URLs, la deduplicación, las comprobaciones por prefijo de carpeta y la validación en el servidor para las 4 operaciones.

---

## 2. Cómo funciona paso a paso

```text
[El editor hace clic en "Eliminar"]
        │
        ▼
[1. El navegador envía POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> invoca todos los servicios AssetUsageCheckerInterface de tu app]
        │
        ├── SIN referencias encontradas ──► "No se encontraron referencias activas ✓" -> Confirmación normal
        │
        └── Referencias ENCONTRADAS     ──► El modal lista los registros + enlaces "Abrir registro ↗":
                                              • policy: 'warn'  -> Aviso amarillo + "Eliminar de todos modos"
                                              • policy: 'block' -> Bloqueo rojo (botón Eliminar oculto)
                                                                   + ¡El servidor rechaza delete / rename / move / overwrite!
```

---

## 3. Ejemplo completo de principio a fin (listo para copiar y pegar)

Sigue estos 4 pasos para poner en marcha **Asset Usage Guard** en una aplicación Symfony + Doctrine (compatible con MySQL, MariaDB, PostgreSQL y SQLite) en menos de 5 minutos.

### Paso 1: Activa `usage_guard` en tu perfil

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Almacenamiento local'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Activa la protección para este perfil (por defecto: false)
                policy: 'block'           # 'warn' (advertir y permitir continuar) | 'block' (bloquear estrictamente)
                enforce_server_side: true # true = aplica también en servidor para delete, rename, move y overwrite (por defecto: true)
                max_references: 20        # Máximo de referencias mostradas en el modal (1..200)
```

### Paso 2: Entidad Doctrine de ejemplo (`Product`)

Supongamos que tu entidad `Product` almacena una imagen de portada individual (`?string`) y un array JSON de galería (`list<string>`):

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

    // p. ej. "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> p. ej. ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Paso 3: Consultas portables en el repositorio (`ProductRepository`)

Estas consultas usan la sintaxis portable **`ESCAPE '!'`** para funcionar exactamente igual en MySQL, MariaDB, PostgreSQL y SQLite:

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
     * Para un archivo individual: busca productos que referencien la URL como portada o dentro de la galería JSON.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Envolver entre comillas dobles ("/uploads/products/a.webp") evita falsos positivos por coincidencia parcial en JSON
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
     * Para una carpeta: busca productos que referencien cualquier archivo dentro del prefijo de la carpeta.
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

### Paso 4: Implementa tu comprobador de uso (`ProductAssetUsageChecker`)

**No hace falta añadir nada en `services.yaml`.** Como `AssetUsageCheckerInterface` incluye `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`, Symfony descubre y registra tu comprobador automáticamente.

> [!IMPORTANT]
> **Nombres de archivo con espacios o caracteres no ASCII:**
> `DirectoryLister::buildPublicUrl()` codifica cada segmento de la ruta con `rawurlencode()` (p. ej., `products/summer catalog.pdf` → `/uploads/products/summer%20catalog.pdf`). Utiliza el método auxiliar `buildPublicUrl()` que se muestra abajo para que tus consultas coincidan exactamente con las URLs guardadas (si tus formularios guardan rutas relativas mediante `return_type: 'path'`, compáralas contra `ltrim($path, '/')`).

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
        // Opcional: limita este comprobador a perfiles concretos
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // p. ej. "Termo Negro Mate (#42)"
                type: 'Producto',                                           // Etiqueta de insignia
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Enlace clicable "Abrir registro ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Se utiliza para deduplicación
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

### Paso 5 (opcional): Añade más comprobadores para otras tablas

Crea clases adicionales enfocadas que implementen `AssetUsageCheckerInterface` para banners, artículos de blog o categorías; el bundle agrega y deduplica todas las referencias automáticamente dentro de un único modal:

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

## 4. Verificación de tu configuración

1. Limpia la caché:
   ```bash
   php bin/console cache:clear
   ```
2. Confirma que tu servicio comprobador está etiquetado y detectado:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Deberías ver `App\FileManager\ProductAssetUsageChecker` en la salida.
3. Guarda una URL de imagen seleccionada desde el gestor de archivos en la columna `cover_image` de un producto (p. ej., `/uploads/products/matte-black-thermos.webp`).
4. Haz clic derecho sobre esa imagen (o su carpeta padre `products`) en el gestor de archivos y pulsa **Eliminar**:
   - El modal se abre, muestra *"Comprobando usos activos…"* y a continuación lista **"Termo Negro Mate (#42) [Producto] — Abrir registro ↗"**.
   - Con `policy: 'block'`, el botón Eliminar queda oculto y cualquier intento de ejecutar `rename`, `move` u `overwrite` sobre ese activo también se rechaza en el servidor.

---

## 5. Comparativa `warn` vs `block` y notas de seguridad

| Política | Comportamiento del modal de eliminación | Aplicación en servidor (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(por defecto)* | Muestra un aviso amarillo con las referencias encontradas; el botón de acción indica **"Eliminar de todos modos"**. | Permite eliminar, renombrar, mover y sobrescribir una vez que el editor confirma. |
| **`block`** | Muestra un aviso de bloqueo rojo con las referencias encontradas y enlaces **"Abrir registro ↗"**; el botón Eliminar queda **oculto**. | Defensa en profundidad: rechaza **`delete`**, **`rename`**, **`move`** y **`overwrite`** aunque se invoque directamente la API. |

- **Sanitización de enlaces (`editUrl`):** `editUrl` se valida tanto en PHP (`AssetUsageGuardService::sanitizeEditUrl()`) como en JavaScript (`isSafeUrl()`), permitiendo únicamente rutas relativas a la raíz (`/admin/...`) o URLs `http(s)://...` (se rechazan `javascript:`, `data:` y URLs relativas al protocolo `//evil.com`).
- **Permisos y límites de solicitud (`checkUsage`):** el endpoint `checkUsage` está protegido contra CSRF, exige tanto **`FILE_MANAGER_VIEW`** como **`FILE_MANAGER_DELETE`** sobre cada ruta de destino y todas sus carpetas padre, limita las solicitudes a **200 elementos** (`MAX_BULK_DELETE_ITEMS`) y bloquea rutas ocultas o de la papelera (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Cuando la confirmación de borrado está desactivada (`ui.confirm_delete: false`):** si `usage_guard.enabled: true`, la comprobación de usos sigue ejecutándose en segundo plano y abre automáticamente el modal de advertencia o bloqueo siempre que se detecten referencias activas.

---

[← Anterior: 09 — Integración con Symfony AI](09-symfony-ai-integration.md) | [Índice principal](README.md) | [Siguiente: 11 — Guía de contribución y PR →](11-contributing-and-pr-guide.md)
