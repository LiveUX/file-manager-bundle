# 07 — クラウドストレージ（S3 / R2 / MinIO / Supabase）と管理画面からの動的設定

[← 前へ: 06 — セキュリティと認可](06-security-and-authorization.md) | [目次](README.md) | [次へ: 08 — イベント・コマンド・キャッシュ・トラブルシューティング →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle は **League Flysystem v3** をベースに構築されています。同一プロファイル内でローカルディスクとクラウドストレージを並べて運用でき（**マルチボリューム**）、ローカルストレージから S3・R2・Supabase へファイルを直接ドラッグ＆ドロップで移動・コピーすることも可能です。

> [!TIP]
> **クラウドストレージ認証情報の 2 つの管理方法**
> 1. **`.env` + YAML で管理する（開発者向け・最速セットアップ）:** `.env.local` に認証キーを記述し、`liveux_file_manager.yaml` でボリュームを宣言します（セクション 1）。
> 2. **管理画面 + データベースで管理する（クライアント / SaaS 向け）:** 管理画面に「クラウドストレージ設定」画面を用意し、管理者が AWS S3 / R2 / Supabase の認証情報を入力して **「接続テスト」** を実行・保存できるようにします。SSH アクセスや `.env` の編集なしで、新しいクラウドボリュームが即座にファイルマネージャーへ反映されます（セクション 3）。

---

## クラウドプロバイダー別セットアップガイド

ご利用のストレージプロバイダーに対応する個別ガイドへ直接移動できます：

| プロバイダー | 選定の目安 | ドライバー | アイコンキー | ガイド |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | すでに Supabase を利用しており、手軽な導入と充実した無料枠を活用したい場合 | `AwsS3` | `supabase` *（自動）* | [Supabase Storage ガイド →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | エンタープライズ規模、IAM ロール運用、CloudFront CDN との連携 | `AwsS3` | `aws_s3` *（自動）* | [Amazon AWS S3 ガイド →](cloud/aws-s3.md) |
| **Cloudflare R2** | **転送料（エグレス料金）無料**、画像・ファイル配信トラフィックが多い場合 | `AwsS3` | `cloudflare_r2` *（自動）* | [Cloudflare R2 ガイド →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | GB 単価を極限まで抑えたい場合、大容量動画やバックアップアーカイブ向け | `AwsS3` | `backblaze_b2` *（自動）* | [Backblaze B2 ガイド →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | 自社サーバーや Docker 上のセルフホスト S3（GDPR / データレジデンシー要件） | `AwsS3` | `minio` *（自動）* | [MinIO & RustFS ガイド →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Google Cloud Platform（GCP）上で稼働するアプリケーション | `AwsS3` | `gcs` *（自動）* | [Google Cloud Storage ガイド →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / カスタム** | 既存の SFTP サーバー、Azure Blob、または任意のカスタム Flysystem アダプター | `FlysystemService` | `sftp` / `cloud` | [SFTP & カスタム Flysystem ガイド →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> ボリュームの設定完了後、次の 1 コマンドですべてのクラウド接続をライブ検証できます：
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. S3 互換クラウドストレージの設定（`.env` + YAML の 3 ステップ）

### ステップ 1: S3 パッケージをインストールする

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### ステップ 2: `.env.local` に認証情報を追加する

```dotenv
# .env.local
AWS_S3_KEY=AKIA...
AWS_S3_SECRET=...
AWS_S3_REGION=eu-central-1
AWS_S3_BUCKET=my-media-bucket

R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_KEY=...
R2_SECRET=...
R2_BUCKET=cdn-assets
```

### ステップ 3: `liveux_file_manager.yaml` でボリュームを宣言する

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'ローカルストレージ'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        aws_s3_cdn:
            label: 'AWS S3 CDN'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.example.com'
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'       # S3 の prefix でも {Y}/{m}/{d}/{user_id} の動的プレースホルダーが利用可能です
                disable_acl: true         # 標準 AWS S3 の「バケット所有者の強制（Bucket owner enforced）」環境では明示設定を推奨

        cloudflare_r2:
            label: 'Cloudflare R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.example.com'
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'aws_s3_cdn', 'cloudflare_r2']
```

### `AwsS3` の `driver_options` 完全リファレンス

| オプション | デフォルト | 説明 |
| :--- | :--- | :--- |
| `bucket` | *（必須）* | 接続先となる S3 バケット名。 |
| `region` | `'us-east-1'` | リージョンコード（例: `'ap-northeast-1'`、`'eu-central-1'`、Cloudflare R2 の場合は `'auto'`）。 |
| `endpoint` | `null` | AWS 以外の S3 互換プロバイダー（R2、Supabase、B2、MinIO、GCS など）用のカスタムエンドポイント URL。 |
| `key` / `secret` | `null` | S3 アクセス認証情報（空にすると EC2 / ECS の IAM ロールを自動的に使用します）。 |
| `token` | `null` | 一時的な AWS STS セッショントークン（`AWS_SESSION_TOKEN`）。 |
| `version` | `'latest'` | AWS S3 SDK の API バージョン。 |
| `prefix` | `''` | バケット内のルートサブフォルダー（`{user_id}`、`{Y}`、`{m}`、`{d}` に対応）。 |
| `use_path_style_endpoint` | `false` | Supabase、MinIO、R2、B2 などのプロバイダーでは `true` に設定します。 |
| `acl` | `null` | 必要に応じて指定するカスタム S3 ACL ヘッダー（例: `'public-read'`）。 |
| `disable_acl` | **カスタム `endpoint` 指定時は `true`、ネイティブ AWS S3 では `false`** | `true` の場合、`x-amz-acl` ヘッダーを送信しません。ネイティブ AWS S3 で *Bucket owner enforced* を有効にしている場合は明示的に `disable_acl: true` を指定してください。 |
| `retain_visibility` | カスタム `endpoint` 指定時は `false` | 移動・コピー時に ACL の読み書きを試みるかどうかを制御します。 |
| `request_checksum_calculation` / `response_checksum_validation` | カスタム `endpoint` 指定時は `'when_required'` | R2、B2、Supabase、MinIO で AWS SDK `>= 3.337` と互換性を保つために自動設定されます。 |
| `ca_bundle` | `null` | カスタム SSL CA 証明書 `.pem` バンドルへのパス（Windows での `cURL error 60` を解消します）。 |

### アーキテクチャ上の補足事項

- **ローカルディスクのシンボリックリンク封じ込め（`ConfinedLocalFilesystemAdapter`）:** すべての読み取り・書き込み・移動・削除操作において、パスセグメントごとに `is_link()` と `realpath()` で検証し、ディレクトリ一覧取得時には `SKIP_LINKS` を強制します。
- **MIME 制限付きクラウドバケットとゴミ箱:** クラウドバケット側で `.meta.json` サイドカーファイルの書き込みが拒否された場合、`TrashManager` は自動的にローカルの `defaults.metadata.file` ディレクトリ内（`trash-meta.json`）へゴミ箱メタデータをフォールバック保存します。
- **サムネイル・動画・PDF:** サムネイルはクラウドストレージから一度取得されたあと、ローカルの `public/media-cache` にキャッシュされます。公開（`private: false`）の画像と動画は CDN URL から直接読み込まれ、PDF は厳格な `script-src 'none'` CSP 付きで `DownloadController?inline=1` を経由して配信されます（`allow_pdf_preview: false` の場合は強制的に `attachment` ダウンロード）。
- **CORS:** クラウド上に保存された画像に対して組み込みの Canvas 画像エディターを使用するには、バケットの CORS ポリシーで自ドメインからの `GET` および `HEAD` リクエストを許可してください。
- **常駐ワーカー（Long-Running Workers）:** `FlysystemStorageFactory` は `ResetInterface` を実装しており、リクエスト間でアダプターキャッシュの上限を管理します（64 → 32 に自動プルーニング）。

---

## 2. 既存の Flysystem サービスを再利用する（`FlysystemService`）

アプリケーション側ですでに `League\Flysystem\FilesystemOperator` サービス（`league/flysystem-bundle` や Azure Blob・SFTP・FTP・WebDAV などの独自定義）を用意している場合は、そのサービス ID を直接参照できます：

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP アーカイブ'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # League\Flysystem\FilesystemOperator のサービス ID
```

> [!NOTE]
> **`public: true` は不要です！** `FlysystemServiceLocatorPass` が、`driver_options.service` で参照されたすべてのサービス（および `liveux_file_manager.storage` または `flysystem.storage` タグ付きのサービス）をプライベートな `ServiceLocator` に自動登録します。

---

## 3. 管理画面からクラウド設定を管理する（データベース駆動）— 完全ガイド

顧客やサイト管理者が **管理画面から直接 AWS S3、Cloudflare R2、Supabase、MinIO、Backblaze B2 のバケットを設定できるようにしたい** 場合は、以下の **5 ステップ** に沿って実装します。

### 仕組みの全体像

```text
[管理画面フォーム] ──► シークレットを暗号化（Sodium） ──► [データベース: cloud_storage_setting]
        │                                                            │
        ▼                                                            ▼
[「接続テスト」ボタン]                                    [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                (priority: 10 > YAML priority: 0)
        │                                                            │
        ▼                                                            ▼
[キャッシュ無効化（DirectoryCacheManager）]               [ファイルマネージャーに即座に表示！]
```

### ステップ 1: データベースエンティティを作成する（`CloudStorageSetting`）

> [!WARNING]
> **セキュリティの鉄則:** S3 の `Secret Access Key` をデータベースに平文で保存することは **絶対にしないでください**。以下のように、必ず暗号化済みの文字列（`encryptedSecret`）として保存してください。

```php
<?php
// src/Entity/CloudStorageSetting.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cloud_storage_setting')]
class CloudStorageSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 一意なボリュームキー（例: 'panel_r2'、'panel_supabase' — 英数字とアンダースコアのみ）
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // サイドバーに表示するラベル（例: 'Cloudflare R2 メディア'）
    #[ORM\Column(length: 120)]
    private string $label = '';

    // プロバイダーキー: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
    #[ORM\Column(length: 32)]
    private string $provider = 'aws_s3';

    #[ORM\Column(length: 120)]
    private string $bucket = '';

    #[ORM\Column(length: 64)]
    private string $region = 'eu-central-1';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $endpoint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $prefix = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $urlPrefix = null;

    #[ORM\Column(length: 255)]
    private string $accessKey = '';

    // Base64 エンコードされた Sodium 暗号文（平文は厳禁！）
    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedSecret = '';

    #[ORM\Column]
    private bool $private = false;

    #[ORM\Column]
    private bool $usePathStyleEndpoint = true;

    #[ORM\Column]
    private bool $disableAcl = true;

    #[ORM\Column]
    private int $cacheTtl = 60;

    #[ORM\Column]
    private bool $enabled = true;

    // このボリュームを割り当てる FileManager プロファイル名（例: 'default'）
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // 標準的な getter / setter ...
    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }
    public function getBucket(): string { return $this->bucket; }
    public function setBucket(string $bucket): self { $this->bucket = $bucket; return $this; }
    public function getRegion(): string { return $this->region; }
    public function setRegion(string $region): self { $this->region = $region; return $this; }
    public function getEndpoint(): ?string { return $this->endpoint; }
    public function setEndpoint(?string $endpoint): self { $this->endpoint = $endpoint; return $this; }
    public function getPrefix(): ?string { return $this->prefix; }
    public function setPrefix(?string $prefix): self { $this->prefix = $prefix; return $this; }
    public function getUrlPrefix(): ?string { return $this->urlPrefix; }
    public function setUrlPrefix(?string $urlPrefix): self { $this->urlPrefix = $urlPrefix; return $this; }
    public function getAccessKey(): string { return $this->accessKey; }
    public function setAccessKey(string $accessKey): self { $this->accessKey = $accessKey; return $this; }
    public function getEncryptedSecret(): string { return $this->encryptedSecret; }
    public function setEncryptedSecret(string $encryptedSecret): self { $this->encryptedSecret = $encryptedSecret; return $this; }
    public function isPrivate(): bool { return $this->private; }
    public function setPrivate(bool $private): self { $this->private = $private; return $this; }
    public function isUsePathStyleEndpoint(): bool { return $this->usePathStyleEndpoint; }
    public function setUsePathStyleEndpoint(bool $usePathStyleEndpoint): self { $this->usePathStyleEndpoint = $usePathStyleEndpoint; return $this; }
    public function isDisableAcl(): bool { return $this->disableAcl; }
    public function setDisableAcl(bool $disableAcl): self { $this->disableAcl = $disableAcl; return $this; }
    public function getCacheTtl(): int { return $this->cacheTtl; }
    public function setCacheTtl(int $cacheTtl): self { $this->cacheTtl = $cacheTtl; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getProfileName(): string { return $this->profileName; }
    public function setProfileName(string $profileName): self { $this->profileName = $profileName; return $this; }
}
```

### ステップ 2: シークレット暗号化サービスを構築する（PHP 8.3 標準 `ext-sodium`）

追加パッケージをインストールすることなく、PHP 標準の認証付き暗号化（`sodium_crypto_secretbox`）を利用できます：

```php
<?php
// src/Security/SecretBoxEncryptor.php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SecretBoxEncryptor
{
    private string $key;

    public function __construct(
        // ヒント: APP_SECRET とは別に専用の CLOUD_ENCRYPTION_KEY を .env に定義することを推奨します
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // 固定長の 32 バイト暗号鍵を導出
        $this->key = hash('sha256', $rawMasterKey, true);
    }

    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainText, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('暗号化されたクラウドストレージキーのデコードに失敗しました。');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('暗号化されたクラウドストレージキーが無効または破損しています。');
        }

        return $plain;
    }
}
```

### ステップ 3: 動的 `ConfigProviderInterface` を実装する（`DatabaseCloudConfigProvider`）

**`priority: 10`** で登録されたこのプロバイダーは、標準の YAML プロバイダー（`priority: 0`）よりも優先して実行されます。`$this->yamlProvider` からベースの YAML プロファイルを読み込み、データベースで有効化されているクラウドボリュームを追加して返すため、**YAML で定義したローカルディスクを維持したまま、保存直後のクラウドディスクをサイドバーへ即座に表示** できます：

```php
<?php
// src/FileManager/DatabaseCloudConfigProvider.php

declare(strict_types=1);

namespace App\FileManager;

use App\Entity\CloudStorageSetting;
use App\Security\SecretBoxEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Service\ResetInterface;

#[AutoconfigureTag('liveux_file_manager.config_provider', ['priority' => 10])]
final class DatabaseCloudConfigProvider implements ConfigProviderInterface, ResetInterface
{
    /** @var array<string, list<CloudStorageSetting>>|null */
    private ?array $settingsByProfile = null;

    public function __construct(
        private readonly YamlConfigProvider $yamlProvider,
        private readonly EntityManagerInterface $em,
        private readonly SecretBoxEncryptor $encryptor,
    ) {
    }

    public function reset(): void
    {
        $this->settingsByProfile = null;
    }

    public function hasProfile(string $name): bool
    {
        return $this->yamlProvider->hasProfile($name);
    }

    public function getProfile(string $name): ProfileConfig
    {
        $base = $this->yamlProvider->getProfile($name);
        $dbSettings = $this->loadSettingsByProfile()[$name] ?? [];
        if ($dbSettings === []) {
            return $base;
        }

        // 既存の YAML ボリュームにデータベース定義のクラウドボリュームをマージ
        $mergedVolumes = $base->volumes;
        foreach ($dbSettings as $setting) {
            if (!in_array($setting->getCode(), $mergedVolumes, true)) {
                $mergedVolumes[] = $setting->getCode();
            }
        }

        return new ProfileConfig(
            name: $base->name,
            volumes: $mergedVolumes,
            driver: $base->driver,
            path: $base->path,
            urlPrefix: $base->urlPrefix,
            private: $base->private,
            driverOptions: $base->driverOptions,
            ignoreDotFiles: $base->ignoreDotFiles,
            excludePatterns: $base->excludePatterns,
            sortBy: $base->sortBy,
            sortDirection: $base->sortDirection,
            foldersFirst: $base->foldersFirst,
            uploadSubfolder: $base->uploadSubfolder,
            uploadAllow: $base->uploadAllow,
            uploadDeny: $base->uploadDeny,
            uploadMaxSize: $base->uploadMaxSize,
            storageQuota: $base->storageQuota,
            overwritePolicy: $base->overwritePolicy,
            chunkUpload: $base->chunkUpload,
            duplicateDetection: $base->duplicateDetection,
            naming: $base->naming,
            imageOptimization: $base->imageOptimization,
            imageConstraints: $base->imageConstraints,
            thumbnail: $base->thumbnail,
            trash: $base->trash,
            zip: $base->zip,
            share: $base->share,
            ai: $base->ai,
            usageGuard: $base->usageGuard,
            cache: $base->cache,
            security: $base->security,
            permissions: $base->permissions,
            ui: $base->ui,
            metadata: $base->metadata,
            locale: $base->locale,
        );
    }

    public function getProfileNames(): array
    {
        return $this->yamlProvider->getProfileNames();
    }

    public function hasVolume(string $name): bool
    {
        return $this->findSettingByCode($name) !== null || $this->yamlProvider->hasVolume($name);
    }

    public function getVolume(string $name): VolumeConfig
    {
        $setting = $this->findSettingByCode($name);
        if ($setting instanceof CloudStorageSetting) {
            return $this->toVolumeConfig($setting);
        }

        return $this->yamlProvider->getVolume($name);
    }

    public function getVolumes(): array
    {
        $volumes = $this->yamlProvider->getVolumes();
        foreach ($this->loadSettingsByProfile() as $list) {
            foreach ($list as $setting) {
                $volumes[$setting->getCode()] = $this->toVolumeConfig($setting);
            }
        }

        return $volumes;
    }

    public function toVolumeConfig(CloudStorageSetting $setting): VolumeConfig
    {
        $options = [
            'bucket' => $setting->getBucket(),
            'region' => $setting->getRegion(),
            'key' => $setting->getAccessKey(),
            'secret' => $this->encryptor->decrypt($setting->getEncryptedSecret()),
            'use_path_style_endpoint' => $setting->isUsePathStyleEndpoint(),
            'disable_acl' => $setting->isDisableAcl(),
            'retain_visibility' => false,
        ];

        if ($setting->getEndpoint() !== null && $setting->getEndpoint() !== '') {
            $options['endpoint'] = $setting->getEndpoint();
        }
        if ($setting->getPrefix() !== null && $setting->getPrefix() !== '') {
            $options['prefix'] = $setting->getPrefix();
        }

        return new VolumeConfig(
            name: $setting->getCode(),
            label: $setting->getLabel(),
            group: 'network',
            driver: 'AwsS3',
            path: null,
            urlPrefix: $setting->isPrivate() ? null : $setting->getUrlPrefix(),
            private: $setting->isPrivate(),
            driverOptions: $options,
            icon: $setting->getProvider(),
            cacheTtl: $setting->getCacheTtl(),
        );
    }

    /** @return array<string, list<CloudStorageSetting>> */
    private function loadSettingsByProfile(): array
    {
        if ($this->settingsByProfile !== null) {
            return $this->settingsByProfile;
        }

        $grouped = [];
        /** @var list<CloudStorageSetting> $rows */
        $rows = $this->em->getRepository(CloudStorageSetting::class)->findBy(['enabled' => true]);
        foreach ($rows as $row) {
            $grouped[$row->getProfileName()][] = $row;
        }

        return $this->settingsByProfile = $grouped;
    }

    private function findSettingByCode(string $code): ?CloudStorageSetting
    {
        foreach ($this->loadSettingsByProfile() as $list) {
            foreach ($list as $setting) {
                if ($setting->getCode() === $code) {
                    return $setting;
                }
            }
        }

        return null;
    }
}
```

### ステップ 4: 管理画面コントローラー（保存 + ライブ「接続テスト」+ キャッシュ無効化）

管理者が管理画面からクラウド設定を保存する際、次の 3 つの重要な処理を実行します：

1. 新しいシークレットが入力された場合、`SecretBoxEncryptor::encrypt()` で暗号化して保存します（既存のシークレットを HTML に再出力することは **絶対にしません**）。
2. 任意の **「接続テスト」** ボタンが押された場合、`FlysystemStorageFactory::createFilesystem()` を呼び出してバケットに対して `listContents('', false)` をライブ実行し、保存前に認証情報とエンドポイントを検証します。
3. 保存後、`DirectoryCacheManager::invalidateProfile()` と `FlysystemStorageFactory::reset()` を呼び出し、新しいボリュームを即座に有効化します。

```php
<?php
// src/Controller/Admin/CloudStorageSettingController.php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CloudStorageSetting;
use App\FileManager\DatabaseCloudConfigProvider;
use App\Form\Admin\CloudStorageSettingType;
use App\Security\SecretBoxEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Cache\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/cloud-storage', name: 'admin_cloud_storage_')]
final class CloudStorageSettingController extends AbstractController
{
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        CloudStorageSetting $setting,
        Request $request,
        EntityManagerInterface $em,
        SecretBoxEncryptor $encryptor,
        DatabaseCloudConfigProvider $configProvider,
        FlysystemStorageFactory $storageFactory,
        DirectoryCacheManager $cacheManager,
    ): Response {
        $form = $this->createForm(CloudStorageSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // フォーム側で plainSecret に mapped: false を設定し、未入力時は既存の暗号化シークレットを維持
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // 「接続テスト」ボタンが押された場合、保存前にライブ接続を確認
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // 先頭 1 件の読み取りを試みて、キー・バケット・エンドポイントをライブ検証
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'クラウドストレージへの接続に成功しました！このまま設定を保存できます。');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', '接続に失敗しました: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // キャッシュとアダプタープールをクリア -> ファイルマネージャーへ即座に反映！
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'クラウドストレージ設定を保存し、有効化しました。');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### ステップ 5: 管理画面クラウド設定のセキュリティチェックリスト

- **厳格なロール制御:** このコントローラーには必ず `#[IsGranted('ROLE_SUPER_ADMIN')]` を付与してください。
- **エンドポイントの SSRF 対策:** `endpoint` フィールドを Symfony Validator（`#[Assert\Url]`）で検証し、`https://` URL のみを許可するとともに、内部メタデータ IP やループバック IP（`169.254.169.254`、`127.0.0.1` など）をブロックしてください（ローカル MinIO を明示的に許可する場合を除く）。
- **シークレットのマスキング:** フォームの `plainSecret` フィールドには `mapped => false`、`required => false`、`type="password"` を設定し、空欄のまま送信された場合は保存済みの暗号化シークレットが保持されるようにします。
- **マスターキーのバックアップ:** `.env.local` に設定した `CLOUD_ENCRYPTION_KEY` は安全なシークレット管理基盤に必ずバックアップしてください。キーを変更または紛失すると、データベース内の既存暗号化シークレットが復号できなくなります。

---

## 4. マルチサーバー構成でのメタデータ永続化（Doctrine DBAL / ORM）

デフォルトでは、フォルダーの色・アイコン・ピン留め項目・ファイルの SEO メタデータは `var/file-manager/metadata.json`（`driver: 'json_file'`）に保存されます。ロードバランサー配下で複数のアプリケーションサーバー（Docker / Kubernetes の複数レプリカなど）を稼働させる場合は、組み込みの `defaults.metadata.driver: 'doctrine'` バックエンドへ切り替えてください。詳細な手順・PostgreSQL / MySQL マイグレーション・エンティティ実装例については **[13 — Doctrine ORM & DBAL 連携ガイド](13-doctrine-integration.md)** を参照してください。

---

[← 前へ: 06 — セキュリティと認可](06-security-and-authorization.md) | [目次](README.md) | [次へ: 08 — イベント・コマンド・キャッシュ・トラブルシューティング →](08-events-commands-cache-and-troubleshooting.md)
