# 07 — Özel Depolama (S3 / R2 / MinIO / Supabase) ve Admin Panelinden Dinamik Yapılandırma

[← Önceki: 06 — Güvenlik ve Yetkilendirme](06-guvenlik-ve-yetkilendirme.md) | [Ana İndeks](README.md) | [Sonraki: 08 — Event'ler, Komutlar, Cache ve SSS →](08-olaylar-komutlar-cache-ve-sorun-giderme.md)

LiveUX FileManagerBundle, **League Flysystem v3** üzerine kuruludur. Aynı profil içinde hem yerel diski hem bulut depolamayı (**Çoklu Volume**) birlikte kullanabilir, dosyaları yerel diskten doğrudan S3/R2/Supabase'e sürükleyip taşıyabilirsiniz.

> **En Basit Haliyle: Bulut Ayarlarını Nereden Yönetebilirim? (2 Yol)**  
> 1. **`.env` + YAML ile (Geliştirici odaklı, en hızlı):** Anahtarları `.env.local` dosyasına yazıp `liveux_file_manager.yaml` içinde tanımlarsınız (Bölüm 1).  
> 2. **Admin Panelinden + Veritabanından (Müşteri / SaaS odaklı):** Admin paneline bir *"Bulut Depolama Ayarları"* sayfası koyarsınız; yönetici AWS S3 / R2 / Supabase bilgilerini formdan girer, **"Bağlantıyı Test Et"** butonuna basar ve kaydeder — sunucuya SSH yapmadan, `.env` dosyasına dokunmadan yeni bulut diski anında dosya yöneticisinde görünür (Bölüm 3)!

---

## Adım Adım Bulut Sağlayıcı Rehberleri

Kullandığınız servis için sıfırdan adım adım kurulum rehberine doğrudan geçebilirsiniz:

| Sağlayıcı | Ne Zaman Seçmelisiniz? | Sürücü | İkon Anahtarı | Rehber |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Supabase projeniz varsa, hızlı kurulum & ücretsiz başlangıç | `AwsS3` | `supabase` *(otomatik)* | [Supabase Storage Rehberi →](bulut/supabase-storage.md) |
| **Amazon AWS S3** | Kurumsal ölçek, IAM/CloudFront entegrasyonu | `AwsS3` | `aws_s3` *(otomatik)* | [Amazon AWS S3 Rehberi →](bulut/aws-s3.md) |
| **Cloudflare R2** | **Sıfır egress (trafik) ücreti**, yüksek indirme/görsel trafiği | `AwsS3` | `cloudflare_r2` *(otomatik)* | [Cloudflare R2 Rehberi →](bulut/cloudflare-r2.md) |
| **Backblaze B2** | Çok düşük GB depolama maliyeti, büyük video/yedek arşivleri | `AwsS3` | `backblaze_b2` *(otomatik)* | [Backblaze B2 Rehberi →](bulut/backblaze-b2.md) |
| **MinIO / RustFS** | Kendi sunucunuzda / Docker'da yerel S3 (KVKK / self-hosted) | `AwsS3` | `minio` *(otomatik)* | [MinIO & RustFS Rehberi →](bulut/minio-rustfs.md) |
| **Google Cloud Storage** | Google Cloud (GCP) altyapısında çalışan projeler | `AwsS3` | `gcs` *(otomatik)* | [Google Cloud Storage Rehberi →](bulut/google-cloud-storage.md) |
| **SFTP / Azure / Özel** | Mevcut SFTP sunucusu, Azure Blob veya özel Flysystem adaptörü | `FlysystemService` | `sftp` / `cloud` | [SFTP & Özel Flysystem Rehberi →](bulut/sftp-ve-ozel-flysystem.md) |

> Kurulum sonrası tüm bulut bağlantılarınızı tek komutla canlı test edebilirsiniz:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. S3 Uyumlu Bulut Depolama Kurulumu (`.env` + YAML — 3 Adım)

### Adım 1: Gerekli S3 Paketlerini Kurun
```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Adım 2: Erişim Anahtarlarını `.env.local` Dosyasına Ekleyin
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

### Adım 3: `liveux_file_manager.yaml` İçinde Volume Olarak Ekleyin

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Yerel Disk'
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
                prefix: 'media/{Y}'       # Dinamik {Y}/{m}/{d}/{user_id} S3 prefix'te de çalışır!
                disable_acl: true         # Standart AWS S3 "Bucket owner enforced" için açıkça true önerilir

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

### Tüm `AwsS3` `driver_options` Parametreleri

| Parametre | Varsayılan | Ne İşe Yarar? |
| :--- | :--- | :--- |
| `bucket` | *(Zorunlu)* | S3 bucket adı. |
| `region` | `'us-east-1'` | Bölge kodu (ör. `'eu-central-1'`, Cloudflare R2 için `'auto'`). |
| `endpoint` | `null` | AWS dışı S3 uyumlu sağlayıcıların (R2, Supabase, B2, MinIO, GCS) endpoint URL'i. |
| `key` / `secret` | `null` | S3 erişim anahtarları (boş bırakılırsa EC2/ECS IAM Role otomatik kullanılır). |
| `token` | `null` | AWS STS geçici oturum anahtarı (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | AWS S3 SDK API sürümü. |
| `prefix` | `''` | Bucket içindeki kök alt klasör (`{user_id}`, `{Y}`, `{m}`, `{d}` destekler). |
| `use_path_style_endpoint` | `false` | Supabase, MinIO, R2, B2 gibi sağlayıcılarda `true` yapın. |
| `acl` | `null` | Özel bir S3 ACL göndermek isterseniz (ör. `'public-read'`). |
| `disable_acl` | **Özel `endpoint` varsa `true`, native AWS S3'te `false`** | `true` yapıldığında `x-amz-acl` başlığı gönderilmez. Native AWS S3 *Bucket owner enforced* bucket'larda açıkça `disable_acl: true` yazın. |
| `retain_visibility` | Özel `endpoint` varsa `false` | Taşıma/kopyalama sırasında ACL okuma/yazma denemesini kontrol eder. |
| `request_checksum_calculation` / `response_checksum_validation` | Özel `endpoint` varsa `'when_required'` | AWS SDK `>= 3.337` sürümlerinde R2 / B2 / Supabase / MinIO uyumu için otomatik ayarlanır. |
| `ca_bundle` | `null` | Özel SSL CA sertifika `.pem` dosyası yolu (Windows `cURL error 60` çözümü). |

### Mimari İpuçları
- **Yerel Disk Symlink Kuşatması (`ConfinedLocalFilesystemAdapter`):** Her okuma/yazma/taşıma/silmede her yol segmenti `is_link()` + `realpath()` ile doğrulanır; listelemede `SKIP_LINKS` uygulanır.
- **MIME Kısıtlı Bulut Bucket'ları & Çöp Kutusu:** Bulut `.meta.json` yazımını reddederse `TrashManager` üst veriyi otomatik olarak `defaults.metadata.file` klasöründeki yerel yedek dosyaya (`trash-meta.json`) yazar.
- **Thumbnail / Video / PDF:** Thumbnail'lar buluttan 1 kez okunup `public/media-cache` içine önbelleklenir; `private: false` görsel/videolar doğrudan CDN URL'inden açılır; PDF'ler `DownloadController?inline=1` üzerinden katı `script-src 'none'` CSP ile akar (`allow_pdf_preview: false` iken zorunlu `attachment`).
- **CORS:** Canvas Görsel Düzenleyici için bulut bucket CORS ayarında alan adınıza `GET` ve `HEAD` izni verin.
- **Long-Running Workers:** `FlysystemStorageFactory`, `ResetInterface` uygular ve adaptör önbelleğini (64 → 32) istekler arasında temiz tutar.

---

## 2. Mevcut Bir Flysystem Servisini Bağlama (`FlysystemService`)

Projenizde zaten `league/flysystem-bundle` veya kendi `League\Flysystem\FilesystemOperator` servisiniz (Azure Blob, SFTP, FTP, WebDAV vb.) varsa servis ID'sini doğrudan verebilirsiniz:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP Arşiv'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # League\Flysystem\FilesystemOperator servis ID'si
```

> **`public: true` Gerekmez!** `FlysystemServiceLocatorPass` YAML'daki tüm `driver_options.service` servislerini ve `liveux_file_manager.storage` / `flysystem.storage` etiketli servisleri otomatik olarak güvenli `ServiceLocator`'a kaydeder.

---

## 3. Bulut Ayarlarını `.env` Yerine Admin Panelinden (Veritabanından) Yönetme — Adım Adım Tam Rehber

Müşterilerinize veya site yöneticilerinize **kendi AWS S3 / Cloudflare R2 / Supabase / MinIO / Backblaze B2 bilgilerini doğrudan Admin Paneli'nden girebilecekleri** bir ayar ekranı sunmak istiyorsanız, aşağıdaki **5 adımı** izlemeniz yeterlidir.

### Nasıl Çalışır? (Büyük Resim)
```text
[Admin Paneli Formu] ──► Secret şifrelenir (Sodium) ──► [Veritabanı: cloud_storage_setting]
        │                                                            │
        ▼                                                            ▼
["Bağlantıyı Test Et" Butonu]                             [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                (priority: 10 > YAML priority: 0)
        │                                                            │
        ▼                                                            ▼
[Cache Temizle (DirectoryCacheManager)]                   [Dosya Yöneticisinde Anında Görünür!]
```

### Adım 1: Veritabanı Entity'sini Oluşturun (`CloudStorageSetting`)

> [!WARNING]
> **Altın Güvenlik Kuralı:** S3 `Secret Access Key` bilgisini veritabanında **ASLA düz metin (plain text)** saklamayın! Aşağıdaki gibi yalnızca şifrelenmiş (`encryptedSecret`) olarak kaydedin.

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

    // Benzersiz volume anahtarı — ör: 'panel_r2', 'panel_supabase' (sadece harf/rakam/_)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Sol menüde görünecek başlık — ör: 'Cloudflare R2 Medya'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Sağlayıcı: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Sodium ile şifrelenmiş Base64 secret (asla düz metin değil!)
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

    // Hangi FileManager profiline ekleneceği (ör: 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Standart getter/setter metotları...
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

### Adım 2: Secret Anahtar Şifreleme Servisi (PHP 8.3 Yerleşik `ext-sodium`)

Ekstra paket kurmadan PHP'nin modern `sodium_crypto_secretbox` şifrelemesini kullanın:

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
        // Öneri: .env içinde ayrı bir CLOUD_ENCRYPTION_KEY tanımlayın (APP_SECRET'tan bağımsız olsun)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // 32 byte sabit anahtar türet
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
            throw new \RuntimeException('Şifreli bulut anahtarı çözülemedi.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('Şifreli bulut anahtarı geçersiz veya bozulmuş.');
        }

        return $plain;
    }
}
```

### Adım 3: Dinamik `ConfigProviderInterface` Yazın (`DatabaseCloudConfigProvider`)

Bu servis **`priority: 10`** ile YAML yapılandırmasından (`priority: 0`) önce çalışır: mevcut YAML profilini (`$this->yamlProvider`) alır, üzerine veritabanındaki aktif bulut disklerini otomatik ekler! Böylece **hem yerel diskiniz YAML'da kalmaya devam eder hem de admin panelinden eklediğiniz bulut diskleri anında sol menüye gelir**:

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

        // YAML'daki mevcut disklerin yanına admin panelinden eklenen bulut disklerini ekle:
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

### Adım 4: Admin Paneli Controller'ı (Kaydetme + Canlı "Bağlantıyı Test Et" + Cache Temizleme)

Yönetici panelden bulut ayarını güncellediğinde 3 kritik şey yapılır:
1. Secret alanı boş bırakılmadıysa `SecretBoxEncryptor::encrypt()` ile şifrelenir (düzenleme ekranında mevcut secret **asla** HTML'e basılmaz!).
2. İsteğe bağlı **"Bağlantıyı Test Et"** aksiyonu `FlysystemStorageFactory::createFilesystem()` ile gerçek bucket'a `listContents('', false)` çağrısı atıp bağlantının çalıştığını anında doğrular.
3. Kayıt sonrası `DirectoryCacheManager::invalidateProfile()` ve `FlysystemStorageFactory::reset()` çağrılarak yeni ayarların 0 saniyede devreye girmesi sağlanır.

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
            // plainSecret formda mapped: false tanımlanır; boşsa mevcut şifreli anahtar korunur
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // "Bağlantıyı Test Et" butonuna basıldıysa kaydetmeden önce canlı test yap:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // İlk sayfayı okuyarak anahtar, bucket ve endpoint'i canlı doğrula:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Bulut bağlantısı başarılı! Ayarları kaydedebilirsiniz.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Bağlantı başarısız: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Önbelleği ve adaptör havuzunu temizle -> dosya yöneticisinde anında aktif olur!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Bulut depolama ayarları kaydedildi ve etkinleştirildi.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Adım 5: Admin Panelinden Bulut Ayarlama Güvenlik Kontrol Listesi
- **Rol Koruması:** Bu ekranı yalnızca `#[IsGranted('ROLE_SUPER_ADMIN')]` yetkisine açın.
- **Endpoint SSRF Koruması:** Form doğrulamasında `endpoint` alanının yalnızca `https://` ile başladığını (yerel MinIO hariç iç IP `169.254.169.254`, `127.0.0.1` girilemeyeceğini) Symfony Validator (`#[Assert\Url]`) ile kontrol edin.
- **Secret Maskeleme:** Düzenleme formunda `plainSecret` alanını `mapped => false`, `required => false` ve `type="password"` olarak bırakın; kullanıcı yeni bir anahtar yazmazsa mevcut şifreli secret korunur.
- **Master Key Yedeklemesi:** `.env.local` içindeki `CLOUD_ENCRYPTION_KEY` değerini güvenli kasada yedekleyin; değişirse veritabanındaki eski şifreli anahtarlar çözülemez.

---

## 4. Çok Sunuculu Ortamlar İçin Metadata Deposu (Doctrine DBAL / ORM)

Klasör renkleri, ikonları, sabitleme ve dosya SEO metadatası varsayılan olarak `var/file-manager/metadata.json` dosyasında tutulur (`driver: 'json_file'`). Birden fazla uygulama sunucunuz (load balancer, Docker/Kubernetes replicas) varsa yerleşik `defaults.metadata.driver: 'doctrine'` deposunu kullanın — tam kurulum, PostgreSQL/MySQL Migration ve Entity kayıt örnekleri için **[13 — Doctrine ORM & DBAL Entegrasyon Rehberi](13-doctrine-entegrasyonu.md)** bölümüne geçin.

---

[← Önceki: 06 — Güvenlik ve Yetkilendirme](06-guvenlik-ve-yetkilendirme.md) | [Ana İndeks](README.md) | [Sonraki: 08 — Event'ler, Komutlar, Cache ve SSS →](08-olaylar-komutlar-cache-ve-sorun-giderme.md)
