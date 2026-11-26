# 13 — Guia de Integração com Doctrine ORM e DBAL

[← Anterior: 12 — Habilidades de Agentes de IA](12-ai-agent-skills-guide.md) | [Índice da Documentação](README.md)

O **LiveUX FileManagerBundle** não exige nenhuma dependência obrigatória do Doctrine em seu núcleo, mas oferece integração de primeira classe para aplicações Symfony que utilizam Doctrine ORM ou DBAL em **4 áreas principais**:

1. **Armazenar metadados de pastas e arquivos no banco de dados** (`defaults.metadata.driver: 'doctrine'` — armazenamento DBAL integrado sem precisar escrever código)
2. **Vincular campos de Entidades Doctrine ao `FileManagerPickerType`** (coluna simples, galeria JSON, `OneToMany` + `CollectionType` / `LiveCollectionType`)
3. **Criar um backend de metadados personalizado com sua própria Entidade Doctrine ORM** (`FolderMetadataStorageInterface`)
4. **Atualizar automaticamente os caminhos no banco de dados quando arquivos ou pastas forem renomeados ou movidos** (`ItemRenamedEvent` / `ItemMovedEvent`)

---

## 1. Armazenamento de Metadados Integrado com Doctrine (`driver: 'doctrine'`)

### Em Termos Simples: O Que Isso Faz?

Quando você atribui uma **cor** (por exemplo, azul), um **ícone** ou um **estado fixado (pinned)** a uma pasta — ou adiciona **texto alternativo (`alt`), título, descrição, tags (`#campanha`) ou um ponto focal** a uma imagem — esses metadados não são gravados dentro dos próprios arquivos. Eles são armazenados separadamente.

O bundle oferece **2 drivers de armazenamento prontos para uso**:
- **Opção 1 — `driver: 'json_file'` (Padrão, Servidor Único):** Armazena os metadados em `var/file-manager/metadata.json` no disco local. Nenhuma configuração necessária para aplicações pequenas em servidor único.
- **Opção 2 — `driver: 'doctrine'` (Banco de Dados — `DoctrineDbalMetadataStorage`):** Armazena os metadados em uma tabela dentro do seu banco de dados **MySQL, MariaDB, PostgreSQL ou SQLite**. **Não requer nenhuma Entidade, Repository ou código customizado.**

**Quando escolher `driver: 'doctrine'`?**
- Sua aplicação roda em **múltiplos servidores (Load Balancer / Docker Swarm / Kubernetes)**, onde um arquivo JSON local não pode ser compartilhado, enquanto o banco de dados é centralizado.
- Você deseja que todo o estado da aplicação faça parte de um único backup do banco de dados.
- Você precisa de gerenciamento de metadados de alta taxa de transferência para milhares de arquivos e pastas sem conflitos de escrita simultânea.

### Destaques Arquiteturais do Driver DBAL Integrado

- **Zero Consultas N+1 (`preload()`):** Ao listar diretórios ou árvores de pastas, as chaves são buscadas em lote com consultas `SELECT` em blocos de 400. Um cache em memória limitado (`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`) combinado com `ResetInterface` evita vazamentos de memória em workers de longa duração do FrankenPHP ou Messenger.
- **UPSERT Nativo Atômico:** Gravações simultâneas usam `ON CONFLICT (key_hash) DO UPDATE` no PostgreSQL/SQLite e `ON DUPLICATE KEY UPDATE` no MySQL/MariaDB — eliminando condições de corrida (*race conditions*).
- **Escritas em Lote (`saveMany()`):** Operações como cópia de pastas executam `DELETE` em blocos (400 linhas) + `INSERT` multilinha (100 linhas) dentro de uma única transação de banco de dados.
- **Índice B-Tree `text_pattern_ops` no PostgreSQL:** Cria automaticamente um índice de expressão `LEFT(canonical_key, 191) text_pattern_ops` e aplica filtros de intervalo `~>=~` / `~<=~` para que consultas por prefixo permaneçam totalmente indexadas mesmo sob `LC_COLLATE` UTF-8 da glibc.
- **Validação Estrita de Nome de Tabela:** `defaults.metadata.table` aceita apenas identificadores SQL seguros que correspondam a `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`.

### Todas as Opções de Configuração

| Opção (`defaults.metadata.*`) | Padrão | Descrição |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | Defina como `'doctrine'` para ativar `DoctrineDbalMetadataStorage` (`'json_file'`, `'doctrine'`, `'none'`). |
| `table` | `'liveux_fm_metadata'` | Personalize o nome da tabela do banco de dados conforme necessário (ex.: `'media_metadata'`). |
| `connection` | `null` | Se você configura múltiplas conexões DBAL em `config/packages/doctrine.yaml` (ex.: `'media'`), aponta diretamente para essa conexão. |
| `entity_manager` | `'default'` | Entity Manager cuja conexão DBAL é utilizada quando `connection` é `null`. |
| `auto_create_table` | `true` | Cria a tabela automaticamente na primeira requisição caso não exista. Defina como `false` em produção se você gerencia o esquema com **Doctrine Migrations**. |

### Passo 1: Ative `driver: 'doctrine'` em `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # Usa o DoctrineDbalMetadataStorage integrado em vez de json_file
            connection: null                # Opcional: nome de conexão DBAL personalizada (ex.: 'media')
            entity_manager: 'default'       # Entity Manager usado quando connection é null
            table: 'liveux_fm_metadata'     # Personalize o nome da tabela livremente
            auto_create_table: true         # Defina como false em produção ao usar Doctrine Migrations
```

### Passo 2 (Recomendado para Produção): Migration Multiplataforma (MySQL / MariaDB / PostgreSQL / SQLite)

Para gerenciar a tabela explicitamente via Doctrine Migrations em produção, defina `auto_create_table: false` e utilize esta migration adaptada à plataforma (cria o índice funcional `text_pattern_ops` no PostgreSQL e o índice de prefixo `lengths: [191]` no MySQL/MariaDB/SQLite):

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
        return 'Cria a tabela liveux_fm_metadata com índice de prefixo otimizado por plataforma.';
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
> **Por que usar `key_hash CHAR(64)` como Chave Primária?** Chaves de índice InnoDB `utf8mb4` no MySQL podem atingir o limite de 191 caracteres em caminhos longos. Usar um hash SHA-256 como chave primária permite que caminhos profundamente aninhados de até 4.096 caracteres — incluindo nomes de arquivos acentuados, turcos, CJK e árabes — funcionem perfeitamente em MySQL, MariaDB, PostgreSQL e SQLite.

### Passo 3 (Exemplo): Nome de Tabela Personalizado e Conexão de Banco Dedicada

Para armazenar metadados de mídia em uma tabela `app_file_meta` em uma conexão DBAL separada chamada `media`, basta alterar apenas 3 linhas:

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

## 2. Modelando Campos de Arquivo em Entidades Doctrine

### A. Imagem de Capa Única e Galeria JSON com Múltiplas Imagens (Mais Comum)

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

### B. Relacionamento `OneToMany` + `CollectionType` / `LiveCollectionType`

Quando cada item possui seu próprio título, ordem de exibição e imagem (por exemplo, `Slider` → `SliderItem`):

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

No lado do formulário, adicione `FileManagerPickerType::class` dentro de `SliderItemType` e associe-o ao formulário pai com `CollectionType::class` ou `LiveCollectionType::class` (`by_reference => false`). *(Para exemplos completos de Form e LiveComponent, consulte [05 — Integrações com FormType e Editores](05-formtype-and-editor-integrations.md).)*

### Você Deve Escolher `return_type: 'url'` ou `'path'`?

| Opção | Armazenado no Banco de Dados | Melhor Quando |
| :--- | :--- | :--- |
| **`'url'`** *(padrão)* | `/uploads/products/thermos.webp` ou `https://cdn.example.com/thermos.webp` | Você quer renderizar `<img src="{{ product.coverImage }}">` diretamente nos templates sem adicionar prefixo. |
| **`'path'`** | `products/thermos.webp` *(apenas caminho relativo)* | Você planeja migrar do armazenamento local para S3/R2/CDN no futuro alterando apenas `url_prefix`, sem precisar tocar nos registros do banco de dados. |

---

### C. Exemplo Completo de Ponta a Ponta: Formulário → Controller → Banco de Dados → Renderização na Loja

Veja o ciclo de vida completo em um só lugar: **um usuário seleciona uma imagem de capa e uma galeria reordenável em um formulário → o Controller persiste os dados via Doctrine → a página de detalhes do produto renderiza as imagens junto com o texto `alt` de SEO e o ponto focal (`focalPoint`) salvos no gerenciador de arquivos.**

#### 1) Formulário Symfony (`src/Form/ProductType.php`)

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
            ->add('name', TextType::class, ['label' => 'Nome do Produto'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Imagem de Capa',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // Salva "/uploads/products/thermos.webp" no banco
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galeria do Produto (Arraste para Reordenar)',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // Salva um array JSON: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) Controller — Persistindo e Lendo Metadados de SEO (`alt` / `focalPoint`) (`src/Controller/ProductController.php`)

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
            // O FileManagerPickerType valida e mapeia os valores na Entidade automaticamente:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Produto e imagens salvos no banco de dados.');

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
        // Opcional: busca o texto alt de SEO e o ponto focal da imagem de capa salvos no gerenciador de arquivos
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

#### 3) Template do Formulário Administrativo (`templates/admin/product/form.html.twig`)

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Novo Produto</h1>

    {# O tema de formulário e o DialogHost são configurados automaticamente pelo bundle #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">Salvar</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) Template da Página de Detalhes da Loja (`templates/product/show.html.twig`)

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# Imagem de capa — usando o ponto focal (focalPoint) e o texto alt de SEO do gerenciador de arquivos, se definidos #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# Galeria de múltiplas imagens (renderizada exatamente na ordem drag-and-drop escolhida pelo usuário!) #}
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
> **Quer armazenar também as credenciais de Armazenamento em Nuvem no seu banco Doctrine?**
> Consulte **[07 — Armazenamento em Nuvem e Configuração Dinâmica](07-cloud-storage-and-dynamic-config.md)** (Seção 3) para um passo a passo completo sobre como salvar configurações criptografadas da AWS S3, Cloudflare R2 ou Supabase no banco de dados e gerenciá-las a partir de um painel administrativo.

---

## 3. Armazenamento de Metadados Customizado com Sua Própria Entidade Doctrine ORM (Avançado)

Se você quiser armazenar os metadados na sua própria Entidade ORM em vez de usar o `DoctrineDbalMetadataStorage` integrado (por exemplo, para incluir uma coluna extra `updatedBy`), implemente tanto `FolderMetadataStorageInterface` quanto `ResetInterface` e crie um alias para a interface do serviço:

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
        // Evita vazamentos de memória e dados obsoletos em workers do FrankenPHP / RoadRunner / Messenger
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
     * Persistência de metadados em lote (usada por MetadataSyncListener::copyFilteredFolderMetadata para evitar escritas N+1).
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

            // Remove atomicamente os registros antigos e quaisquer entradas filhas obsoletas no destino:
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
     * Distingue chaves raiz de volume ('local::') de chaves de pastas aninhadas ('local::products').
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

## 4. Atualizando Automaticamente Referências no Banco ao Renomear ou Mover

Quando um editor renomeia ou move um arquivo (por exemplo, `products/old.webp`) ou uma pasta inteira dentro do gerenciador de arquivos, você pode escutar `ItemRenamedEvent` e `ItemMovedEvent` para manter tanto **`Product.coverImage`** quanto o array JSON **`Product.galleryImages`** sincronizados automaticamente:

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

## 5. Combinando com a Proteção de Uso de Ativos (Asset Usage Guard)

Para evitar que arquivos referenciados nas suas tabelas Doctrine sejam excluídos, renomeados, movidos ou sobrescritos acidentalmente, conecte `AssetUsageCheckerInterface` diretamente ao seu `ProductRepository` — consulte **[10 — Proteção de Uso de Ativos](10-asset-usage-guard.md)** para uma implementação completa passo a passo.

---

[← Anterior: 12 — Habilidades de Agentes de IA](12-ai-agent-skills-guide.md) | [Índice da Documentação](README.md)
