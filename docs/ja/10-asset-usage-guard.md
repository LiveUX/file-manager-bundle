# 10 — アセット使用状況ガード（Asset Usage Guard）

[← 前へ: 09 — Symfony AI 連携](09-symfony-ai-integration.md) | [目次](README.md) | [次へ: 11 — コントリビューション＆PR ガイド →](11-contributing-and-pr-guide.md)

---

## 1. どんな課題を解決するのか？（実務シナリオ）

EC サイトや CMS の管理画面で、編集者が `uploads/products/matte-black-thermos.webp`（あるいは `uploads/products` フォルダー全体）を **削除・名前変更・移動・上書き** しようとした場面を想像してみてください。

- **使用状況ガードなしの場合:** ファイルはそのまま即座に削除・移動されます。もし 3 つの商品カードと 1 つのトップページバナーがその画像を参照していた場合、公開サイト上に **リンク切れ（`404`）画像** が発生してしまいます。
- **使用状況ガードを有効にした場合:** ファイルマネージャーはアプリケーションに対して *「このファイルまたはフォルダーは現在データベース内のどこかから参照されていますか？」* と問い合わせます。
  - 参照が見つかった場合、確認モーダルに **使用中のすべての商品・バナー・ページ一覧** が表示され、クリック可能な **「レコードを開く ↗」** リンクも併せて提示されます。
  - **`policy: 'warn'`** の場合は、黄色の警告バナーと **「強制削除する」** ボタンが表示されます。
  - **`policy: 'block'`** かつ **`enforce_server_side: true`**（デフォルト）の場合は、赤色のブロック通知が表示されて「削除」ボタンが **非表示** になり、さらにサーバー側でも **以下 4 つの破壊的操作すべて** が拒否されます：
    1. **削除（`delete`）** → `errors.delete_blocked_in_use`
    2. **名前変更（`rename`）** → `errors.rename_blocked_in_use`
    3. **移動（`move`）** → `errors.move_blocked_in_use`
    4. **上書き（`overwrite`）** — アップロード（`overwrite_policy: 'overwrite'`）、画像エディター、ZIP 展開を含む → `errors.overwrite_blocked_in_use`

> [!NOTE]
> **なぜ小さな PHP クラスを 1 つ実装する必要があるのですか？**
> LiveUX FileManagerBundle は、各アプリケーション固有のデータベーステーブル（`Product`、`BlogPost`、`Slider`、`Category` 等）やカラム名をあらかじめ知ることができません。その代わりに、シンプルな単一メソッドのインターフェース（`AssetUsageCheckerInterface`）を提供しています。アプリケーション側でテーブルを検索して参照一覧を返すだけで、UI モーダルの表示、URL サニタイズ、重複排除、フォルダープレフィックス判定、および 4 操作すべてのサーバー側ガードはバンドルが自動で処理します。

---

## 2. 動作フロー（ステップバイステップ）

```text
[編集者が「削除」をクリック]
        │
        ▼
[1. ブラウザーが POST /_liveux-file-manager/api/action  { action: "checkUsage", paths: [...] } を送信]
        │
        ▼
[2. AssetUsageGuardService -> アプリ内の全 AssetUsageCheckerInterface サービスを呼び出し]
        │
        ├── 参照なし ──► 「この項目へのアクティブな参照は見つかりませんでした。」 -> 通常の削除確認
        │
        └── 参照あり ──► モーダルに一致レコードと「レコードを開く ↗」リンクを表示：
                           • policy: 'warn'  -> 黄色警告 + 「強制削除する」ボタン
                           • policy: 'block' -> 赤色ブロック（削除ボタン非表示）
                                                + サーバー側で delete / rename / move / overwrite を拒否！
```

---

## 3. コピー＆ペーストで動く完全な実装例

Symfony + Doctrine アプリケーション（MySQL、MariaDB、PostgreSQL、SQLite すべてに対応）に **使用状況ガード** を 5 分以内で導入する 4 ステップです。

### ステップ 1: プロファイルで `usage_guard` を有効化する

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'ローカルストレージ'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            usage_guard:
                enabled: true             # このプロファイルで有効化（デフォルト: false）
                policy: 'block'           # 'warn'（警告＋強制削除を許可） | 'block'（厳格にブロック）
                enforce_server_side: true # true = サーバー側でも delete / rename / move / overwrite を強制保護（デフォルト: true）
                max_references: 20        # モーダルに表示する最大参照件数（1..200）
```

### ステップ 2: Doctrine エンティティ例（`Product`）

`Product` エンティティが単一のカバー画像（`?string`）と JSON ギャラリー配列（`list<string>`）を保持している例です：

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

    // 例: "/uploads/products/matte-black-thermos.webp"
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> 例: ["/uploads/products/thermos-1.webp", "/uploads/products/thermos-2.webp"] */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCoverImage(): ?string { return $this->coverImage; }
    /** @return list<string> */
    public function getGalleryImages(): array { return $this->galleryImages; }
}
```

### ステップ 3: DB 非依存のリポジトリクエリ（`ProductRepository`）

以下のクエリは標準的で移植性の高い **`ESCAPE '!'`** 構文を使用しているため、MySQL、MariaDB、PostgreSQL、SQLite のいずれでも同一に動作します：

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
     * 単一ファイル用: カバー画像または JSON ギャラリー内で指定 URL を参照している商品を検索します。
     *
     * @return list<Product>
     */
    public function findByAssetUrl(string $publicUrl, int $limit = 25): array
    {
        // ダブルクォートで囲む（"/uploads/products/a.webp"）ことで JSON 内の部分一致による誤検知を防止
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
     * フォルダー用: 指定フォルダープレフィックス配下のファイルを参照している商品を検索します。
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

### ステップ 4: 使用状況チェッカーを実装する（`ProductAssetUsageChecker`）

**`services.yaml` への手動登録は不要です！** `AssetUsageCheckerInterface` には `#[AutoconfigureTag('liveux_file_manager.usage_checker')]` が付与されているため、Symfony が実装クラスを自動的に検出・登録します。

> [!IMPORTANT]
> **スペースや非 ASCII 文字を含むファイル名について:**
> `DirectoryLister::buildPublicUrl()` は各パスセグメントを `rawurlencode()` でエンコードします（例: `products/summer catalog.pdf` → `/uploads/products/summer%20catalog.pdf`）。保存済み URL と正確に一致させるため、以下の `buildPublicUrl()` ヘルパーを使用してください（フォームで `return_type: 'path'` を指定して相対パスを保存している場合は、代わりに `ltrim($path, '/')` で照合します）。

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
        // 任意: 特定のプロファイルのみに限定する場合
        // if ($profile->name !== 'default') { return []; }

        $publicUrl = self::buildPublicUrl($volume->urlPrefix, $path);

        $products = $isFolder
            ? $this->productRepository->findByAssetFolderPrefix($publicUrl)
            : $this->productRepository->findByAssetUrl($publicUrl);

        $references = [];
        foreach ($products as $product) {
            $id = $product->getId();
            $references[] = new AssetUsageReference(
                label: sprintf('%s (#%d)', $product->getName(), (int) $id), // 例: "マットブラックサーモス (#42)"
                type: '商品',                                               // バッジ表示ラベル
                editUrl: $id !== null
                    ? $this->urlGenerator->generate('admin_product_edit', ['id' => $id]) // クリック可能な「レコードを開く ↗」
                    : null,
                entityId: $id !== null ? (string) $id : null,               // 重複排除用の識別子
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

### ステップ 5（任意）: 他のテーブル向けにチェッカーを追加する

バナー、ブログ記事、カテゴリーなど、テーブルごとに責務を分けた `AssetUsageCheckerInterface` 実装クラスを追加するだけで、バンドルがすべての結果を自動的に集約・重複排除して単一のモーダルに表示します：

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
                type: 'バナー',
                editUrl: $this->urlGenerator->generate('admin_banner_edit', ['id' => $banner->getId()]),
                entityId: (string) $banner->getId(),
            );
        }

        return $refs;
    }
}
```

---

## 4. 動作確認手順

1. キャッシュをクリアします：
   ```bash
   php bin/console cache:clear
   ```
2. チェッカーサービスがタグ付け・自動検出されていることを確認します：
   ```bash
   php bin/console debug:container --tag=liveux_file_manager.usage_checker
   ```
   出力に `App\FileManager\ProductAssetUsageChecker` が表示されれば正常です。
3. ファイルマネージャーで選択した画像 URL を、ある商品の `cover_image` カラムへ保存します（例: `/uploads/products/matte-black-thermos.webp`）。
4. ファイルマネージャー上でその画像（または親の `products` フォルダー）を右クリックし、**削除** をクリックします：
   - モーダルが開き *「使用状況を確認中…」* と表示されたあと、**「マットブラックサーモス (#42) [商品] — レコードを開く ↗」** が一覧表示されます。
   - `policy: 'block'` の場合、削除ボタンは非表示となり、そのアセットに対する `rename`・`move`・`overwrite` 操作もサーバー側で拒否されます。

---

## 5. `warn` と `block` の比較とセキュリティ上の補足

| ポリシー | 削除モーダルの挙動 | サーバー側強制保護（`enforce_server_side: true`） |
| :--- | :--- | :--- |
| **`warn`** *（デフォルト）* | 該当する参照一覧とともに黄色の警告バナーを表示し、実行ボタンが **「強制削除する」** に変わります。 | 編集者が確認して続行した場合、削除・名前変更・移動・上書きを許可します。 |
| **`block`** | 該当する参照一覧と **「レコードを開く ↗」** リンクを赤色のブロックバナーで表示し、削除ボタンを **非表示** にします。 | 多層防御: API が直接呼び出された場合でも **`delete`**・**`rename`**・**`move`**・**`overwrite`** をサーバー側で拒否します。 |

- **リンクのサニタイズ（`editUrl`）:** `editUrl` は PHP（`AssetUsageGuardService::sanitizeEditUrl()`）と JavaScript（`isSafeUrl()`）の双方で検証され、ルート相対パス（`/admin/...`）または `http(s)://...` URL のみを許可します（`javascript:`、`data:`、プロトコル相対 URL `//evil.com` は拒否されます）。
- **権限と件数上限（`checkUsage`）:** `checkUsage` エンドポイントは CSRF 保護されており、対象パスおよびすべての親フォルダーに対して **`FILE_MANAGER_VIEW`** と **`FILE_MANAGER_DELETE`** の両方を検証します。また、1 リクエストあたり **200 件**（`MAX_BULK_DELETE_ITEMS`）の上限を設け、隠しパス・ゴミ箱パスへのアクセスを遮断します（`assertAccessiblePath`、`assertNotTrashAncestor`）。
- **削除確認モーダル無効時（`ui.confirm_delete: false`）の動作:** `usage_guard.enabled: true` であればバックグラウンドで使用状況チェックが実行され、アクティブな参照が検出された場合は自動的に警告／ブロックモーダルが開きます。

---

[← 前へ: 09 — Symfony AI 連携](09-symfony-ai-integration.md) | [目次](README.md) | [次へ: 11 — コントリビューション＆PR ガイド →](11-contributing-and-pr-guide.md)
