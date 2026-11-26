# 07 — Cloud Storage (S3 / R2 / MinIO / Supabase) & Dynamic Admin Configuration

[← Previous: 06 — Security & Authorization](06-security-and-authorization.md) | [Main Index](README.md) | [Next: 08 — Events, Commands, Cache & Troubleshooting →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle is built on **League Flysystem v3**. You can combine local disks and cloud storage side by side in the same profile (**Multi-Volume**), and even drag and drop files directly from local storage into S3, R2, or Supabase.

> [!TIP]
> **Two Ways to Manage Cloud Storage Credentials**
> 1. **Via `.env` + YAML (Developer-first, fastest setup):** Store keys in `.env.local` and declare volumes in `liveux_file_manager.yaml` (Section 1).
> 2. **Via Your Admin Panel + Database (Client / SaaS-ready):** Build a *"Cloud Storage Settings"* screen in your admin panel where admins enter AWS S3 / R2 / Supabase credentials, click **"Test Connection"**, and save—new cloud volumes appear immediately in the file manager without SSH access or editing `.env` files (Section 3).

---

## Step-by-Step Cloud Provider Guides

Jump directly to the dedicated setup guide for your storage provider:

| Provider | When to Choose It | Driver | Icon Key | Guide |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | You already use Supabase, want fast setup & a generous free tier | `AwsS3` | `supabase` *(auto)* | [Supabase Storage Guide →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Enterprise scale, IAM roles, and CloudFront CDN integration | `AwsS3` | `aws_s3` *(auto)* | [Amazon AWS S3 Guide →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Zero egress fees**, high-traffic image/file downloads | `AwsS3` | `cloudflare_r2` *(auto)* | [Cloudflare R2 Guide →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Ultra-low per-GB storage cost, large video/backup archives | `AwsS3` | `backblaze_b2` *(auto)* | [Backblaze B2 Guide →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | Self-hosted S3 on your own server or Docker (GDPR / data residency) | `AwsS3` | `minio` *(auto)* | [MinIO & RustFS Guide →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Applications running on Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(auto)* | [Google Cloud Storage Guide →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Custom** | Existing SFTP server, Azure Blob, or any custom Flysystem adapter | `FlysystemService` | `sftp` / `cloud` | [SFTP & Custom Flysystem Guide →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> After configuring your volumes, test every cloud connection live with a single command:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. S3-Compatible Cloud Storage Setup (`.env` + YAML in 3 Steps)

### Step 1: Install the S3 Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Step 2: Add Credentials to `.env.local`

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

### Step 3: Declare Volumes in `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Local Disk'
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
                prefix: 'media/{Y}'       # Dynamic {Y}/{m}/{d}/{user_id} placeholders work in S3 prefixes too!
                disable_acl: true         # Explicitly recommended for standard AWS S3 "Bucket owner enforced" buckets

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

### Complete `AwsS3` `driver_options` Reference

| Option | Default | Description |
| :--- | :--- | :--- |
| `bucket` | *(Required)* | Target S3 bucket name. |
| `region` | `'us-east-1'` | Region code (e.g., `'eu-central-1'`, or `'auto'` for Cloudflare R2). |
| `endpoint` | `null` | Custom endpoint URL for non-AWS S3-compatible providers (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | S3 access credentials (leave empty to automatically use EC2/ECS IAM Roles). |
| `token` | `null` | Temporary AWS STS session token (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | AWS S3 SDK API version. |
| `prefix` | `''` | Root subfolder inside the bucket (supports `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Set to `true` for Supabase, MinIO, R2, B2, and similar providers. |
| `acl` | `null` | Custom S3 ACL header if needed (e.g., `'public-read'`). |
| `disable_acl` | **`true` when a custom `endpoint` is set; `false` on native AWS S3** | When `true`, omits the `x-amz-acl` header. Set `disable_acl: true` explicitly on native AWS S3 buckets with *Bucket owner enforced* enabled. |
| `retain_visibility` | `false` when a custom `endpoint` is set | Controls whether ACL reads/writes are attempted during move/copy operations. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` when a custom `endpoint` is set | Automatically configured for compatibility with AWS SDK `>= 3.337` on R2, B2, Supabase, and MinIO. |
| `ca_bundle` | `null` | Path to a custom SSL CA certificate `.pem` bundle (fixes Windows `cURL error 60`). |

### Architecture Notes

- **Local Disk Symlink Confinement (`ConfinedLocalFilesystemAdapter`):** Every read, write, move, and delete validates each path segment with `is_link()` + `realpath()`; directory listings enforce `SKIP_LINKS`.
- **MIME-Restricted Cloud Buckets & Trash:** If a cloud bucket rejects writing `.meta.json` sidecar files, `TrashManager` automatically falls back to storing trash metadata locally (`trash-meta.json`) inside the `defaults.metadata.file` directory.
- **Thumbnails, Video & PDFs:** Thumbnails are fetched once from cloud storage and cached locally in `public/media-cache`. Public (`private: false`) images and videos load directly from their CDN URL; PDFs stream through `DownloadController?inline=1` with a strict `script-src 'none'` CSP (or forced `attachment` when `allow_pdf_preview: false`).
- **CORS:** To use the built-in Canvas Image Editor with cloud-hosted images, allow `GET` and `HEAD` requests from your domain in your bucket's CORS policy.
- **Long-Running Workers:** `FlysystemStorageFactory` implements `ResetInterface` and keeps its adapter cache bounded (pruning 64 → 32) across requests.

---

## 2. Reusing an Existing Flysystem Service (`FlysystemService`)

If your application already defines a `League\Flysystem\FilesystemOperator` service (via `league/flysystem-bundle` or custom wiring for Azure Blob, SFTP, FTP, WebDAV, etc.), reference its service ID directly:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP Archive'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # League\Flysystem\FilesystemOperator service ID
```

> [!NOTE]
> **No `public: true` Required!** `FlysystemServiceLocatorPass` automatically registers every service referenced via `driver_options.service`—as well as services tagged with `liveux_file_manager.storage` or `flysystem.storage`—into a private `ServiceLocator`.

---

## 3. Managing Cloud Settings from an Admin Panel (Database-Driven) — Full Guide

If you want customers or site administrators to **configure their own AWS S3, Cloudflare R2, Supabase, MinIO, or Backblaze B2 buckets right from your admin panel**, follow these **5 steps**.

### How It Works (Big Picture)

```text
[Admin Panel Form] ──► Secret encrypted (Sodium) ──► [Database: cloud_storage_setting]
        │                                                            │
        ▼                                                            ▼
["Test Connection" Button]                                [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                (priority: 10 > YAML priority: 0)
        │                                                            │
        ▼                                                            ▼
[Invalidate Cache (DirectoryCacheManager)]                [Appears Immediately in File Manager!]
```

### Step 1: Create the Database Entity (`CloudStorageSetting`)

> [!WARNING]
> **Golden Security Rule:** **NEVER** store an S3 `Secret Access Key` in plain text in your database. Always store an encrypted payload (`encryptedSecret`) as shown below.

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

    // Unique volume key — e.g. 'panel_r2', 'panel_supabase' (alphanumeric + underscore only)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Display label shown in the sidebar — e.g. 'Cloudflare R2 Media'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Provider key: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Base64-encoded Sodium ciphertext (never plain text!)
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

    // FileManager profile this volume attaches to (e.g. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Standard getters and setters...
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

### Step 2: Build a Secret Encryption Service (Built-In PHP 8.3 `ext-sodium`)

Use PHP's native authenticated encryption (`sodium_crypto_secretbox`) without installing any extra packages:

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
        // Tip: Define a dedicated CLOUD_ENCRYPTION_KEY in .env (separate from APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Derive a fixed 32-byte encryption key
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

### Step 3: Implement a Dynamic `ConfigProviderInterface` (`DatabaseCloudConfigProvider`)

Registered with **`priority: 10`**, this provider runs ahead of the YAML provider (`priority: 0`). It loads the base YAML profile from `$this->yamlProvider` and appends active database-configured cloud volumes—so **your local YAML disks stay intact while newly saved cloud disks show up in the sidebar immediately**:

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

        // Merge database-defined cloud volumes alongside existing YAML volumes:
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

### Step 4: Admin Controller (Save + Live "Test Connection" + Cache Invalidation)

When an administrator saves cloud settings from your admin panel, three critical actions happen:

1. If a new secret was entered, it is encrypted via `SecretBoxEncryptor::encrypt()` (the existing secret is **never** echoed back into HTML!).
2. Clicking the optional **"Test Connection"** button calls `FlysystemStorageFactory::createFilesystem()` and runs a live `listContents('', false)` check against the bucket to verify credentials and endpoint before persisting.
3. After saving, `DirectoryCacheManager::invalidateProfile()` and `FlysystemStorageFactory::reset()` are called so the new volume takes effect immediately.

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
            // plainSecret is mapped: false in the form; leave empty to keep the existing encrypted secret
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // If "Test Connection" was clicked, verify live before saving:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Read the first entry to verify key, bucket, and endpoint live:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Cloud connection succeeded! You can now save these settings.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Connection failed: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Clear caches and adapter pool -> immediately active in the file manager!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Cloud storage settings have been saved and activated.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Step 5: Security Checklist for Admin-Managed Cloud Settings

- **Strict Role Guard:** Restrict this controller to `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **Endpoint SSRF Protection:** Validate the `endpoint` field with Symfony Validator (`#[Assert\Url]`) so only `https://` URLs are accepted and internal metadata/loopback IPs (`169.254.169.254`, `127.0.0.1`, etc.) are blocked (unless explicitly allowing local MinIO).
- **Secret Masking:** Configure `plainSecret` in the form with `mapped => false`, `required => false`, and `type="password"` so leaving it blank preserves the stored encrypted secret.
- **Master Key Backup:** Back up `CLOUD_ENCRYPTION_KEY` from `.env.local` in a secure secrets vault—rotating or losing it makes previously encrypted database secrets unreadable.

---

## 4. Metadata Storage for Multi-Server Deployments (Doctrine DBAL / ORM)

By default, folder colors, icons, pinned items, and file SEO metadata are stored in `var/file-manager/metadata.json` (`driver: 'json_file'`). If you run multiple application servers behind a load balancer (Docker/Kubernetes replicas), switch to the built-in `defaults.metadata.driver: 'doctrine'` backend—see **[13 — Doctrine ORM & DBAL Integration Guide](13-doctrine-integration.md)** for full setup, PostgreSQL/MySQL migrations, and Entity examples.

---

[← Previous: 06 — Security & Authorization](06-security-and-authorization.md) | [Main Index](README.md) | [Next: 08 — Events, Commands, Cache & Troubleshooting →](08-events-commands-cache-and-troubleshooting.md)
