# 10 — Proteção de Uso de Ativos (Asset Usage Guard)

[← Anterior: 09 — Integração Symfony AI](09-symfony-ai-integration.md) | [Índice Principal](README.md) | [Próximo: 11 — Guia de Contribuição e PR →](11-contributing-and-pr-guide.md)

---

## 1. Que Problema Ele Resolve? (Cenário Real)

Imagine que um editor no painel administrativo do seu e-commerce ou CMS tente **excluir, renomear, mover ou sobrescrever** `uploads/products/matte-black-thermos.webp` (ou toda a pasta `uploads/products`).

- **Sem o Usage Guard:** O arquivo é excluído ou movido imediatamente — e se 3 cards de produto e 1 banner da página inicial apontam para ele, seu site em produção passa a exibir **imagens quebradas com erro `404`**.
- **Com o Usage Guard ativado:** O gerenciador de arquivos pergunta à sua aplicação: *"Este arquivo ou pasta está referenciado em algum lugar do banco de dados?"*
  - Se houver referências, o modal de confirmação lista **todos os produtos, banners ou páginas que o utilizam**, acompanhados de um link clicável **"Abrir registro ↗"**.
  - Com **`policy: 'warn'`**, exibe um banner amarelo de aviso e oferece o botão **"Excluir mesmo assim"**.
  - Com **`policy: 'block'`** e **`enforce_server_side: true`** (o padrão), exibe um aviso vermelho de bloqueio, **oculta** o botão Excluir e rejeita **todas as 4 operações destrutivas** no servidor:
    1. **Excluir (`delete`)** → `errors.delete_blocked_in_use`
    2. **Renomear (`rename`)** → `errors.rename_blocked_in_use`
    3. **Mover (`move`)** → `errors.move_blocked_in_use`
    4. **Sobrescrever (`overwrite`)** — incluindo uploads (`overwrite_policy: 'overwrite'`), Editor de Imagens e extração de ZIP → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **Por que você implementa uma pequena classe PHP?**
> O LiveUX FileManagerBundle não tem como conhecer as tabelas do banco de dados da sua aplicação (`Product`, `BlogPost`, `Slider`, `Category` etc.) nem os nomes de suas colunas. Em vez disso, ele oferece um contrato limpo de método único (`AssetUsageCheckerInterface`). Você consulta suas tabelas e retorna as referências encontradas — e o bundle cuida automaticamente do modal da interface, sanitização de URLs, deduplicação, verificação por prefixo de pasta e bloqueio no servidor para as 4 operações.

---

## 2. Como Funciona Passo a Passo

```text
[Editor clica em "Excluir"]
        │
        ▼
[1. Navegador envia POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] }]
        │
        ▼
[2. AssetUsageGuardService -> chama todos os serviços AssetUsageCheckerInterface da aplicação]
        │
        ├── NENHUM uso encontrado ──► "Nenhuma referência ativa encontrada ✓" -> Confirmação normal
        │
        └── Usos ENCONTRADOS      ──► Modal lista registros + links "Abrir registro ↗":
                                        • policy: 'warn'  -> Aviso amarelo + "Excluir mesmo assim"
                                        • policy: 'block' -> Bloqueio vermelho (botão Excluir oculto)
                                                             + Servidor rejeita delete / rename / move / overwrite!
```

---

## 3. Exemplo Completo de Ponta a Ponta (Pronto para Copiar e Colar)

Siga estes 4 passos para colocar o **Usage Guard** funcionando em uma aplicação Symfony + Doctrine (compatível com MySQL, MariaDB, PostgreSQL e SQLite) em menos de 5 minutos.

### Passo 1: Ative `usage_guard` no seu perfil

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Armazenamento Local'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # Ativa neste perfil (padrão: false)
                policy: 'block'           # 'warn' (avisa + permite prosseguir) | 'block' (impede estritamente)
                enforce_server_side: true # true = também bloqueia delete, rename, move e overwrite no servidor (padrão: true)
                max_references: 20        # Máximo de referências exibidas no modal (1..200)
```

### Passo 2: Entidade Doctrine de exemplo (`Product`)

Suponha que sua entidade `Product` armazene uma imagem de capa (`?string`) e um array JSON de galeria (`list<string>`):

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

    // ex.: "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> ex.: ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### Passo 3: Consultas portáveis no Repository (`ProductRepository`)

Estas consultas utilizam a sintaxe portável **`ESCAPE '!'`**, funcionando de forma idêntica em MySQL, MariaDB, PostgreSQL e SQLite:

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
     * Para um único arquivo: encontra produtos que referenciam a URL na capa ou dentro da galeria JSON.
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // Envolver em aspas duplas ("/uploads/products/a.webp") evita falsos positivos por correspondência parcial em JSON
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
     * Para uma pasta: encontra produtos que referenciam qualquer arquivo dentro do prefixo da pasta.
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

### Passo 4: Implemente seu verificador de uso (`ProductAssetUsageChecker`)

**Nenhuma configuração em `services.yaml` é necessária!** Como `AssetUsageCheckerInterface` possui `#[AutoconfigureTag('liveux_file_manager.usage_checker')]`, o Symfony descobre e registra seu verificador automaticamente.

> [!IMPORTANT]
> **Nomes de arquivos com espaços ou caracteres não-ASCII:**
> O `DirectoryLister::buildPublicUrl()` codifica cada segmento do caminho com `rawurlencode()` (ex.: `products/summer catalog.pdf` → `/uploads/products/summer%20catalog.pdf`). Use o helper `buildPublicUrl()` abaixo para que suas consultas correspondam exatamente às URLs salvas (se seus formulários armazenam caminhos relativos via `return_type: 'path'`, compare com `ltrim($path, '/')`).

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
        // Opcional: restrinja este verificador a perfis específicos
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // ex.: "Garrafa Térmica Preto Fosco (#42)"
                type: 'Produto',                                            // Rótulo da badge
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // Link clicável "Abrir registro ↗"
                    : null,
                entityId: $id !== null ? (string) $id : null,               // Usado para deduplicação
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

### Passo 5 (Opcional): Adicione mais verificadores para outras tabelas

Crie classes dedicadas adicionais implementando `AssetUsageCheckerInterface` para banners, posts de blog ou categorias — o bundle agrega e deduplica todos os resultados em um único modal automaticamente:

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

## 4. Verificando sua Configuração

1. Limpe o cache:
   ```bash
   php bin/console cache:clear
   ```
2. Confirme que seu serviço verificador foi marcado com tag e descoberto:
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   Você deverá ver `App\FileManager\ProductAssetUsageChecker` na saída.
3. Salve uma URL de imagem selecionada no gerenciador de arquivos na coluna `cover_image` de um produto (ex.: `/uploads/products/matte-black-thermos.webp`).
4. Clique com o botão direito nessa imagem (ou na pasta pai `products`) no gerenciador de arquivos e clique em **Excluir**:
   - O modal abre, exibe *"Verificando usos ativos…"*, e então lista **"Garrafa Térmica Preto Fosco (#42) [Produto] — Abrir registro ↗"**.
   - Com `policy: 'block'`, o botão Excluir fica oculto e qualquer tentativa de `rename`, `move` ou `overwrite` nesse ativo também é rejeitada no servidor.

---

## 5. Comparação entre `warn` e `block` e Notas de Segurança

| Política | Comportamento no Modal de Exclusão | Aplicação no Servidor (`enforce_server_side: true`) |
| :--- | :--- | :--- |
| **`warn`** *(padrão)* | Exibe um banner amarelo de aviso com as referências encontradas; o botão de ação exibe **"Excluir mesmo assim"**. | Permite `delete`, `rename`, `move` e `overwrite` assim que o editor confirmar. |
| **`block`** | Exibe um banner vermelho de bloqueio com as referências encontradas e links **"Abrir registro ↗"**; o botão Excluir fica **oculto**. | Defesa em profundidade: rejeita **`delete`**, **`rename`**, **`move`** e **`overwrite`** mesmo que a API seja chamada diretamente. |

- **Sanitização de Links (`editUrl`):** O campo `editUrl` é validado tanto em PHP (`AssetUsageGuardService::sanitizeEditUrl()`) quanto em JavaScript (`isSafeUrl()`), aceitando apenas caminhos relativos à raiz (`/admin/...`) ou URLs `http(s)://...` (URLs `javascript:`, `data:` e protocol-relative `//evil.com` são rejeitadas).
- **Permissões e Limites (`checkUsage`):** O endpoint `checkUsage` é protegido contra CSRF, exige tanto **`FILE_MANAGER_VIEW`** quanto **`FILE_MANAGER_DELETE`** em todos os caminhos de destino e pastas ancestrais, limita solicitações a **200 itens** (`MAX_BULK_DELETE_ITEMS`) e bloqueia caminhos ocultos/da lixeira (`assertAccessiblePath`, `assertNotTrashAncestor`).
- **Quando a confirmação de exclusão está desativada (`ui.confirm_delete: false`):** Se `usage_guard.enabled: true`, a verificação de uso ainda roda em segundo plano e abre automaticamente o modal de aviso/bloqueio sempre que referências ativas forem detectadas.

---

[← Anterior: 09 — Integração Symfony AI](09-symfony-ai-integration.md) | [Índice Principal](README.md) | [Próximo: 11 — Guia de Contribuição e PR →](11-contributing-and-pr-guide.md)
