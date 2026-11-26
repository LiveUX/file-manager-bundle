# 07 — 云存储（S3 / R2 / MinIO / Supabase）与后台动态配置

[← 上一篇：06 — 安全与权限控制](06-security-and-authorization.md) | [文档首页](README.md) | [下一篇：08 — 事件、CLI 命令、缓存与常见问题 →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle 基于 **League Flysystem v3** 构建。你可以在同一个配置文件（Profile）中同时挂载本地磁盘和云端存储（**多存储卷 / Multi-Volume**），甚至可以直接将文件从本地存储拖放到 S3、R2 或 Supabase 中。

> [!TIP]
> **管理云存储凭据的两种方式**
> 1. **通过 `.env` + YAML（面向开发者，上手最快）：** 将密钥保存在 `.env.local` 中，并在 `liveux_file_manager.yaml` 中声明存储卷（第 1 节）。
> 2. **通过管理后台 + 数据库（适用于客户自管 / SaaS 场景）：** 在管理后台构建一个“云存储设置”页面，管理员输入 AWS S3 / R2 / Supabase 凭据，点击 **“测试连接”** 并保存——无需 SSH 登录服务器或修改 `.env` 文件，新的云存储卷会立即出现在文件管理器中（第 3 节）。

---

## 各云存储服务商分步配置指南

直接跳转到你所使用的存储服务商专属配置指南：

| 服务商 | 适用场景 | 驱动（Driver） | 图标键（Icon Key） | 指南 |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | 已在使用 Supabase，希望快速接入并享受慷慨的免费额度 | `AwsS3` | `supabase` *（自动）* | [Supabase Storage 指南 →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | 企业级规模、IAM 角色授权以及 CloudFront CDN 集成 | `AwsS3` | `aws_s3` *（自动）* | [Amazon AWS S3 指南 →](cloud/aws-s3.md) |
| **Cloudflare R2** | **零流量出站费用**，适合高并发图片与文件下载 | `AwsS3` | `cloudflare_r2` *（自动）* | [Cloudflare R2 指南 →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | 极低的每 GB 存储单价，适合大型视频与备份归档 | `AwsS3` | `backblaze_b2` *（自动）* | [Backblaze B2 指南 →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | 在自有服务器或 Docker 中自托管 S3（满足 GDPR / 数据驻留合规要求） | `AwsS3` | `minio` *（自动）* | [MinIO 与 RustFS 指南 →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | 运行在 Google Cloud Platform（GCP）上的应用 | `AwsS3` | `gcs` *（自动）* | [Google Cloud Storage 指南 →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / 自定义** | 已有 SFTP 服务器、Azure Blob 或任意自定义 Flysystem 适配器 | `FlysystemService` | `sftp` / `cloud` | [SFTP 与自定义 Flysystem 指南 →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> 配置完存储卷后，只需一条命令即可对所有云端连接执行实时连通性检测：
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. S3 兼容云存储配置（3 步完成 `.env` + YAML 配置）

### 第 1 步：安装 S3 依赖包

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### 第 2 步：在 `.env.local` 中添加凭据

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

### 第 3 步：在 `liveux_file_manager.yaml` 中声明存储卷

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: '本地磁盘'
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
                prefix: 'media/{Y}'       # S3 前缀同样支持 {Y}/{m}/{d}/{user_id} 动态占位符！
                disable_acl: true         # 对于标准 AWS S3 开启 "Bucket owner enforced" 的存储桶，强烈建议显式设为 true

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

### `AwsS3` `driver_options` 完整参考

| 选项 | 默认值 | 说明 |
| :--- | :--- | :--- |
| `bucket` | *（必填）* | 目标 S3 存储桶名称。 |
| `region` | `'us-east-1'` | 区域代码（例如 `'eu-central-1'`，Cloudflare R2 填 `'auto'`）。 |
| `endpoint` | `null` | 非 AWS 的 S3 兼容服务商（R2、Supabase、B2、MinIO、GCS）的自定义端点 URL。 |
| `key` / `secret` | `null` | S3 访问密钥（留空时自动使用 EC2/ECS 的 IAM 角色）。 |
| `token` | `null` | 临时 AWS STS 会话令牌（`AWS_SESSION_TOKEN`）。 |
| `version` | `'latest'` | AWS S3 SDK API 版本。 |
| `prefix` | `''` | 存储桶内的根子目录前缀（支持 `{user_id}`、`{Y}`、`{m}`、`{d}`）。 |
| `use_path_style_endpoint` | `false` | 使用 Supabase、MinIO、R2、B2 等服务商时请设为 `true`。 |
| `acl` | `null` | 如有需要，可指定自定义 S3 ACL 请求头（例如 `'public-read'`）。 |
| `disable_acl` | **设置自定义 `endpoint` 时默认为 `true`；原生 AWS S3 默认为 `false`** | 设为 `true` 时不发送 `x-amz-acl` 请求头。若原生 AWS S3 存储桶启用了 *Bucket owner enforced*，请显式配置 `disable_acl: true`。 |
| `retain_visibility` | 设置自定义 `endpoint` 时默认为 `false` | 控制移动/复制操作期间是否尝试读取或写入对象 ACL。 |
| `request_checksum_calculation` / `response_checksum_validation` | 设置自定义 `endpoint` 时默认为 `'when_required'` | 自动配置以兼容 AWS SDK `>= 3.337` 在 R2、B2、Supabase 和 MinIO 上的校验行为。 |
| `ca_bundle` | `null` | 自定义 SSL CA 证书 `.pem` 文件路径（用于解决 Windows 下的 `cURL error 60`）。 |

### 架构说明

- **本地磁盘符号链接隔离（`ConfinedLocalFilesystemAdapter`）：** 每次读取、写入、移动和删除都会通过 `is_link()` + `realpath()` 校验每一级路径段；目录列表扫描强制启用 `SKIP_LINKS`。
- **限制 MIME 类型的云存储桶与回收站：** 若云存储桶拒绝写入 `.meta.json` 伴随文件，`TrashManager` 会自动回退，将回收站元数据本地存储在 `defaults.metadata.file` 所在目录的 `trash-meta.json` 中。
- **缩略图、视频与 PDF：** 缩略图仅从云存储拉取一次，随后缓存在本地 `public/media-cache` 中。公开（`private: false`）图片和视频直接通过 CDN URL 加载；PDF 通过 `DownloadController?inline=1` 流式输出并附带严格的 `script-src 'none'` CSP 策略（当 `allow_pdf_preview: false` 时强制以 `attachment` 下载）。
- **CORS 跨域配置：** 若要在内置 Canvas 图片编辑器中编辑云端图片，请在存储桶的 CORS 策略中允许来自你应用域名的 `GET` 和 `HEAD` 请求。
- **常驻内存 Worker 进程：** `FlysystemStorageFactory` 实现了 `ResetInterface`，并在跨请求场景下对适配器缓存实施上限裁剪（64 → 32），防止内存泄漏。

---

## 2. 复用已有的 Flysystem 服务（`FlysystemService`）

如果你的应用已经定义了 `League\Flysystem\FilesystemOperator` 服务（例如通过 `league/flysystem-bundle` 或自定义装配的 Azure Blob、SFTP、FTP、WebDAV 等适配器），可以直接引用其服务 ID：

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP 归档'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # League\Flysystem\FilesystemOperator 服务 ID
```

> [!NOTE]
> **无需声明 `public: true`！** `FlysystemServiceLocatorPass` 会自动将通过 `driver_options.service` 引用的所有服务（以及带有 `liveux_file_manager.storage` 或 `flysystem.storage` 标签的服务）注册到一个私有的 `ServiceLocator` 中。

---

## 3. 在管理后台动态管理云存储配置（数据库驱动）— 完整指南

如果你希望客户或站点管理员**直接在管理后台中配置自己的 AWS S3、Cloudflare R2、Supabase、MinIO 或 Backblaze B2 存储桶**，请按照以下 **5 个步骤**实现。

### 整体工作流程

```text
[管理后台表单] ──► 密钥加密（Sodium） ──► [数据库：cloud_storage_setting]
      │                                                 │
      ▼                                                 ▼
[“测试连接”按钮]                             [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                  (priority: 10 > YAML priority: 0)
      │                                                 │
      ▼                                                 ▼
[失效缓存（DirectoryCacheManager）]          [立即出现在文件管理器中！]
```

### 第 1 步：创建数据库实体（`CloudStorageSetting`）

> [!WARNING]
> **核心安全铁律：** **绝对不要**在数据库中以明文存储 S3 的 `Secret Access Key`。请务必像下方示例那样存储加密后的密文（`encryptedSecret`）。

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

    // 唯一存储卷标识 — 例如 'panel_r2'、'panel_supabase'（仅限字母、数字和下划线）
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // 侧边栏中显示的名称 — 例如 'Cloudflare R2 媒体库'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // 服务商标识：'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Base64 编码的 Sodium 密文（切勿明文存储！）
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

    // 该存储卷挂载到的 FileManager 配置文件名（例如 'default'）
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // 标准 getter 与 setter...
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

### 第 2 步：构建密钥加密服务（使用 PHP 8.3 内置 `ext-sodium`）

无需安装任何第三方依赖，直接使用 PHP 原生认证加密（`sodium_crypto_secretbox`）：

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
        // 提示：在 .env 中定义专用的 CLOUD_ENCRYPTION_KEY（与 APP_SECRET 分离）
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // 派生固定 32 字节加密密钥
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
            throw new \RuntimeException('Failed to decode encrypted cloud storage key.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('Encrypted cloud storage key is invalid or corrupted.');
        }

        return $plain;
    }
}
```

### 第 3 步：实现动态 `ConfigProviderInterface`（`DatabaseCloudConfigProvider`）

通过 **`priority: 10`** 注册，使该提供者优先于默认的 YAML 提供者（`priority: 0`）执行。它会先从 `$this->yamlProvider` 加载基础 YAML 配置，再将数据库中已启用的云存储卷追加进去——这样**你原有的 YAML 本地磁盘会完好保留，而后台新保存的云端磁盘会立即显示在侧边栏中**：

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

        // 将数据库定义的云存储卷与现有 YAML 存储卷合并：
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

### 第 4 步：管理后台控制器（保存 + 实时“测试连接” + 缓存失效）

当管理员在后台保存云存储设置时，会执行以下三项关键操作：

1. 若输入了新密钥，通过 `SecretBoxEncryptor::encrypt()` 加密存储（已有密钥**绝不会**回显到 HTML 表单中！）。
2. 点击可选的 **“测试连接”** 按钮时，调用 `FlysystemStorageFactory::createFilesystem()` 并执行一次实时的 `listContents('', false)` 请求，在落库前验证凭据与端点是否可用。
3. 保存成功后，调用 `DirectoryCacheManager::invalidateProfile()` 与 `FlysystemStorageFactory::reset()`，让新存储卷立即生效。

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
            // plainSecret 在表单中配置为 mapped: false；留空则保留当前已加密的密钥
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // 如果点击了“测试连接”，则在保存前进行实时连通性校验：
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // 读取首条目录项以实时验证密钥、存储桶与端点配置：
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', '云存储连接成功！现在可以保存该配置。');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', '连接失败：' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // 清除缓存与适配器连接池 -> 文件管理器中立即生效！
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', '云存储设置已保存并立即生效。');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### 第 5 步：后台云存储管理安全检查清单

- **严格的角色限制：** 务必为该控制器加上 `#[IsGranted('ROLE_SUPER_ADMIN')]`。
- **端点 SSRF 防护：** 使用 Symfony Validator（`#[Assert\Url]`）校验 `endpoint` 字段，仅允许 `https://` 协议，并拦截内网元数据或回环地址（如 `169.254.169.254`、`127.0.0.1` 等，除非明确需要连接本地 MinIO）。
- **密钥隐藏与保护：** 在表单中将 `plainSecret` 配置为 `mapped => false`、`required => false` 且 `type="password"`，确保留空时不覆盖数据库中已加密的密钥。
- **主密钥备份：** 请将 `.env.local` 中的 `CLOUD_ENCRYPTION_KEY` 妥善备份至安全的密钥管理库（Secrets Vault）——一旦轮换或丢失，此前加密入库的密钥将无法解密。

---

## 4. 多服务器部署的元数据存储（Doctrine DBAL / ORM）

默认情况下，文件夹颜色、图标、固定项以及文件 SEO 元数据存储在 `var/file-manager/metadata.json` 中（`driver: 'json_file'`）。如果你的应用在负载均衡后运行多个节点（例如 Docker / Kubernetes 多副本部署），请切换为内置的 `defaults.metadata.driver: 'doctrine'` 后端——完整配置、PostgreSQL/MySQL 迁移及实体示例请参阅 **[13 — Doctrine ORM 与 DBAL 集成指南](13-doctrine-integration.md)**。

---

[← 上一篇：06 — 安全与权限控制](06-security-and-authorization.md) | [文档首页](README.md) | [下一篇：08 — 事件、CLI 命令、缓存与常见问题 →](08-events-commands-cache-and-troubleshooting.md)
