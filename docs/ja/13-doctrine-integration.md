# 13 — Doctrine ORM & DBAL 連携ガイド

[← 前へ: 12 — AI エージェントスキル](12-ai-agent-skills-guide.md) | [ドキュメント目次](README.md)

**LiveUX FileManagerBundle** のコアには Doctrine への必須依存がありませんが、Doctrine ORM または DBAL を採用している Symfony アプリケーション向けに、以下の **4 つの主要領域**で本格的な連携機能を提供しています。

1. **フォルダ・ファイルのメタデータをデータベースに保存する**（`defaults.metadata.driver: 'doctrine'` — コード不要の組み込み DBAL ストレージ）
2. **Doctrine エンティティのフィールドを `FileManagerPickerType` に紐づける**（単一カラム、JSON ギャラリー、`OneToMany` + `CollectionType` / `LiveCollectionType`）
3. **独自の Doctrine ORM エンティティでカスタムメタデータストレージを実装する**（`FolderMetadataStorageInterface`）
4. **ファイル・フォルダのリネームや移動時にデータベース上のパスを自動更新する**（`ItemRenamedEvent` / `ItemMovedEvent`）

---

## 1. 組み込みの Doctrine メタデータストレージ（`driver: 'doctrine'`）

### わかりやすく言うと何をする機能ですか？

フォルダに **カラー**（例：青）・**アイコン**・**ピン留め状態** を設定したり、画像に **alt テキスト・タイトル・説明文・タグ（`#campaign`）・フォーカルポイント** を付与したりすると、そのメタデータはファイル本体ではなく別領域に保存されます。

本バンドルには **2 つの組み込みストレージドライバー**が用意されています。
- **選択肢 1 — `driver: 'json_file'`（デフォルト・単一サーバー向け）：** ローカルディスク上の `var/file-manager/metadata.json` に保存します。小規模な単一サーバー構成なら設定不要ですぐに使えます。
- **選択肢 2 — `driver: 'doctrine'`（データベース保存 — `DoctrineDbalMetadataStorage`）：** **MySQL、MariaDB、PostgreSQL、SQLite** データベース内のテーブルに保存します。**エンティティ・リポジトリ・独自コードは一切不要です。**

**どのようなときに `driver: 'doctrine'` を選ぶべきですか？**
- アプリケーションが **複数サーバー（ロードバランサー / Docker Swarm / Kubernetes）** で稼働しており、ローカル JSON ファイルは共有できないがデータベースは一元管理されている場合。
- アプリケーションのすべての状態を単一のデータベースバックアップに含めたい場合。
- 数千件以上のファイル・フォルダに対して、同時書き込み競合のない高スループットなメタデータ管理を行いたい場合。

### 組み込み DBAL ドライバーの設計上の特長

- **N+1 クエリゼロ（`preload()`）：** ディレクトリ一覧やフォルダツリーを取得する際、キーを 400 件ずつのチャンクに分けて `SELECT` で一括取得します。上限付きインメモリキャッシュ（`MAX_ITEM_CACHE_ENTRIES = 16384` → `8192`）と `ResetInterface` の組み合わせにより、長期稼働する FrankenPHP や Messenger ワーカーでもメモリリークを防止します。
- **アトミックなネイティブ UPSERT：** 同時保存時は PostgreSQL / SQLite で `ON CONFLICT (key_hash) DO UPDATE`、MySQL / MariaDB で `ON DUPLICATE KEY UPDATE` を使用し、競合状態（レースコンディション）を排除します。
- **バッチ書き込み（`saveMany()`）：** フォルダコピーなどの操作では、単一のデータベーストランザクション内でチャンク分割された `DELETE`（400 行）＋ 複数行 `INSERT`（100 行）を実行します。
- **PostgreSQL `text_pattern_ops` B-Tree インデックス：** `LEFT(canonical_key, 191) text_pattern_ops` 式インデックスを自動作成し、`~>=~` / `~<=~` の範囲フィルタを適用することで、glibc UTF-8 `LC_COLLATE` 環境下でも前方一致クエリで確実にインデックスが効きます。
- **厳格なテーブル名バリデーション：** `defaults.metadata.table` は `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/` に一致する安全な SQL 識別子のみを受け付けます。

### 設定オプション一覧

| オプション（`defaults.metadata.*`） | デフォルト値 | 説明 |
| :--- | :--- | :--- |
| `driver` | `'json_file'` | `'doctrine'` を指定すると `DoctrineDbalMetadataStorage` が有効になります（`'json_file'`、`'doctrine'`、`'none'`）。 |
| `table` | `'liveux_fm_metadata'` | データベースのテーブル名を自由にカスタマイズできます（例：`'media_metadata'`）。 |
| `connection` | `null` | `config/packages/doctrine.yaml` に複数の DBAL 接続（例：`'media'`）を定義している場合、その接続を直接指定します。 |
| `entity_manager` | `'default'` | `connection` が `null` のときに使用する DBAL 接続元の Entity Manager 名です。 |
| `auto_create_table` | `true` | 初回リクエスト時にテーブルが存在しなければ自動作成します。本番環境で **Doctrine Migrations** を使ってスキーマ管理する場合は `false` にしてください。 |

### ステップ 1：`liveux_file_manager.yaml` で `driver: 'doctrine'` を有効化する

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        metadata:
            enabled: true
            driver: 'doctrine'              # json_file の代わりに組み込みの DoctrineDbalMetadataStorage を使用
            connection: null                # 任意：専用の DBAL 接続名（例：'media'）
            entity_manager: 'default'       # connection が null のときに使用する Entity Manager
            table: 'liveux_fm_metadata'     # テーブル名は自由に変更可能
            auto_create_table: true         # 本番環境で Doctrine Migrations を使う場合は false に設定
```

### ステップ 2（本番環境推奨）：クロスプラットフォーム対応マイグレーション（MySQL / MariaDB / PostgreSQL / SQLite）

本番環境で Doctrine Migrations によって明示的にテーブルを管理する場合は、`auto_create_table: false` を設定したうえで、次のプラットフォーム対応マイグレーションを使用します（PostgreSQL では `text_pattern_ops` 関数インデックス、MySQL / MariaDB / SQLite では `lengths: [191]` プレフィックスインデックスを作成します）。

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
        return 'Creates liveux_fm_metadata table with platform-optimized prefix index.';
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
> **なぜ主キーに `key_hash CHAR(64)` を使うのですか？** MySQL の `utf8mb4` InnoDB では、長いパスに対してインデックスキーの 191 文字制限に引っかかることがあります。SHA-256 ハッシュを主キーにすることで、日本語・中国語・韓国語（CJK）、トルコ語、アラビア語のファイル名を含む最大 4,096 文字の深いパスでも、MySQL・MariaDB・PostgreSQL・SQLite すべてで問題なく動作します。

### ステップ 3（例）：カスタムテーブル名と専用データベース接続を使う

メディア用メタデータを別の DBAL 接続 `media` 上の `app_file_meta` テーブルに保存したい場合、変更するのはわずか 3 行だけです。

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

## 2. Doctrine エンティティでのファイルフィールド設計

### A. 単一カバー画像と複数画像の JSON ギャラリー（最も一般的な構成）

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

    // FileManagerPickerType (multiple: true) -> list<string>（JSON 配列）
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

### B. リレーショナルな `OneToMany` + `CollectionType` / `LiveCollectionType`

各アイテムが独自のタイトル・並び順・画像を持つ場合（例：`Slider` → `SliderItem`）：

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

フォーム側では、`SliderItemType` 内に `FileManagerPickerType::class` を追加し、親フォームへ `CollectionType::class` または `LiveCollectionType::class`（`by_reference => false`）で組み込みます。*（フォームおよび LiveComponent の完全な実装例は [05 — FormType とエディタ連携](05-formtype-and-editor-integrations.md) をご覧ください。）*

### `return_type: 'url'` と `'path'` のどちらを選ぶべきか？

| オプション | データベースに保存される値 | おすすめのユースケース |
| :--- | :--- | :--- |
| **`'url'`** *（デフォルト）* | `/uploads/products/thermos.webp` または `https://cdn.example.com/thermos.webp` | テンプレートでプレフィックスを付けずに `<img src="{{ product.coverImage }}">` をそのまま出力したい場合。 |
| **`'path'`** | `products/thermos.webp` *（相対パスのみ）* | 将来的にローカル保存から S3 / R2 / CDN へ移行する際、データベースのレコードを書き換えずに `url_prefix` だけを変更したい場合。 |

---

### C. エンドツーエンドの実装例：フォーム → コントローラー → データベース → フロント画面表示

一連のライフサイクル全体をまとめて紹介します。**ユーザーがフォームでカバー画像と並び替え可能なギャラリーを選択 → コントローラーが Doctrine 経由で保存 → 商品詳細ページでファイルマネージャーに保存された SEO 用 `alt` テキストとフォーカルポイント（`focalPoint`）付きで画像を表示**する流れです。

#### 1) Symfony フォーム（`src/Form/ProductType.php`）

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
            ->add('name', TextType::class, ['label' => '商品名'])
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'カバー画像',
                'profile' => 'default',
                'variant' => 'card',
                'multiple' => false,
                'return_type' => 'url', // DB に "/uploads/products/thermos.webp" を保存
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => '商品ギャラリー（ドラッグで並び替え可能）',
                'profile' => 'default',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'return_type' => 'url', // JSON 配列を保存: ["/uploads/a.webp", "/uploads/b.webp"]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

#### 2) コントローラー — 保存と SEO メタデータ（`alt` / `focalPoint`）の取得（`src/Controller/ProductController.php`）

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
            // FileManagerPickerType が値を検証し、エンティティへ自動的にマッピングします:
            //   $product->getCoverImage()    => "/uploads/products/thermos.webp"
            //   $product->getGalleryImages() => ["/uploads/products/1.webp", "/uploads/products/2.webp"]
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', '商品と画像をデータベースに保存しました。');

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
        // 任意：ファイルマネージャーに保存されたカバー画像の SEO 用 alt テキストとフォーカルポイントを取得
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

#### 3) 管理画面フォームテンプレート（`templates/admin/product/form.html.twig`）

```twig
{# templates/admin/product/form.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>新規商品</h1>

    {# フォームテーマと DialogHost はバンドルによって自動で組み込まれます #}
    {{ form_start(form) }}
        {{ form_row(form.name) }}
        {{ form_row(form.coverImage) }}
        {{ form_row(form.galleryImages) }}
        <button type="submit">保存</button>
    {{ form_end(form) }}
{% endblock %}
```

#### 4) フロント商品詳細テンプレート（`templates/product/show.html.twig`）

```twig
{# templates/product/show.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>{{ product.name }}</h1>

    {# カバー画像 — ファイルマネージャーで設定されたフォーカルポイント（focalPoint）と SEO 用 alt テキストを利用 #}
    {% if product.coverImage %}
        {% set fx = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.x * 100)|round(1) : 50 %}
        {% set fy = coverMeta and coverMeta.focalPoint ? (coverMeta.focalPoint.y * 100)|round(1) : 50 %}
        <img src="{{ product.coverImage }}"
             alt="{{ coverMeta.alt|default(product.name) }}"
             style="width: 100%; max-height: 420px; object-fit: cover; object-position: {{ fx }}% {{ fy }}%;">
    {% endif %}

    {# 複数画像ギャラリー（ユーザーがドラッグ＆ドロップで並べ替えた順序そのままに表示されます） #}
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
> **クラウドストレージの認証情報も Doctrine データベースに保存したいですか？**
> AWS S3、Cloudflare R2、Supabase の設定を暗号化してデータベースへ保存し、管理画面から管理する完全な手順については **[07 — クラウドストレージと動的設定](07-cloud-storage-and-dynamic-config.md)**（セクション 3）を参照してください。

---

## 3. 独自の Doctrine ORM エンティティによるカスタムメタデータストレージ（応用）

組み込みの `DoctrineDbalMetadataStorage` ではなく独自の ORM エンティティにメタデータを保存したい場合（たとえば `updatedBy` カラムを追加したい場合など）は、`FolderMetadataStorageInterface` と `ResetInterface` の両方を実装し、サービスインターフェースのエイリアスを設定します。

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
        // FrankenPHP / RoadRunner / Messenger ワーカーでのメモリリークと古いキャッシュ残存を防止
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
     * メタデータの一括永続化（MetadataSyncListener::copyFilteredFolderMetadata で N+1 書き込みを防止するために使用）。
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

            // 古いレコードと移動先に残っている不要な子エントリをアトミックに削除:
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
     * ボリュームルートキー（'local::'）とネストされたフォルダキー（'local::products'）を区別します。
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

## 4. リネーム・移動時にデータベース参照を自動更新する

編集者がファイルマネージャー内でファイル（例：`products/old.webp`）やフォルダ全体をリネームまたは移動したとき、`ItemRenamedEvent` と `ItemMovedEvent` を購読することで、**`Product.coverImage`** と JSON 配列 **`Product.galleryImages`** の両方を自動で同期できます。

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

## 5. アセット使用状況ガードとの併用

Doctrine テーブルから参照されているファイルが誤って削除・リネーム・移動・上書きされるのを防ぐには、`AssetUsageCheckerInterface` を `ProductRepository` に直接接続します。完全な実装手順は **[10 — アセット使用状況ガード](10-asset-usage-guard.md)** をご覧ください。

---

[← 前へ: 12 — AI エージェントスキル](12-ai-agent-skills-guide.md) | [ドキュメント目次](README.md)
