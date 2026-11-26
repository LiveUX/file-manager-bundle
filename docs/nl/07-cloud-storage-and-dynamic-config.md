# 07 — Cloudopslag (S3 / R2 / MinIO / Supabase) & Dynamische Beheerconfiguratie

[← Vorige: 06 — Beveiliging & Autorisatie](06-security-and-authorization.md) | [Hoofdoverzicht](README.md) | [Volgende: 08 — Events, Commando's, Cache & Probleemoplossing →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle is gebouwd op **League Flysystem v3**. Je kunt lokale schijven en cloudopslag naast elkaar combineren binnen hetzelfde profiel (**Multi-Volume**), en bestanden zelfs rechtstreeks vanuit lokale opslag naar S3, R2 of Supabase slepen.

> [!TIP]
> **Twee manieren om cloudopslag-credentials te beheren**
> 1. **Via `.env` + YAML (voor ontwikkelaars, snelste setup):** Sla sleutels op in `.env.local` en declareer volumes in `liveux_file_manager.yaml` (Sectie 1).
> 2. **Via je beheerpaneel + database (geschikt voor klanten / SaaS):** Bouw een scherm *"Cloudopslag-instellingen"* in je beheerpaneel waar beheerders AWS S3-, R2- of Supabase-gegevens invoeren, op **"Verbinding testen"** klikken en opslaan — nieuwe cloudvolumes verschijnen direct in het bestandsbeheer zonder SSH-toegang of handmatige wijzigingen in `.env`-bestanden (Sectie 3).

---

## Stapsgewijze handleidingen per cloudprovider

Ga direct naar de specifieke installatiehandleiding voor jouw opslagprovider:

| Provider | Wanneer te kiezen | Driver | Pictogramsleutel | Handleiding |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Je gebruikt al Supabase en wilt een snelle setup met een royale gratis tier | `AwsS3` | `supabase` *(auto)* | [Supabase Storage Handleiding →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Enterprise-schaal, IAM-rollen en CloudFront CDN-integratie | `AwsS3` | `aws_s3` *(auto)* | [Amazon AWS S3 Handleiding →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Geen egresskosten**, veel verkeer voor afbeeldingen en bestandsdownloads | `AwsS3` | `cloudflare_r2` *(auto)* | [Cloudflare R2 Handleiding →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Zeer lage opslagkosten per GB, grote video- of back-uparchieven | `AwsS3` | `backblaze_b2` *(auto)* | [Backblaze B2 Handleiding →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | Zelfgehoste S3 op je eigen server of in Docker (AVG / dataresidentie) | `AwsS3` | `minio` *(auto)* | [MinIO & RustFS Handleiding →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Applicaties die draaien op Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(auto)* | [Google Cloud Storage Handleiding →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Aangepast** | Bestaande SFTP-server, Azure Blob of een eigen Flysystem-adapter | `FlysystemService` | `sftp` / `cloud` | [SFTP & Aangepaste Flysystem Handleiding →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Test na het configureren van je volumes alle cloudverbindingen live met één enkel commando:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. S3-compatibele cloudopslag instellen (`.env` + YAML in 3 stappen)

### Stap 1: Installeer de S3-packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Stap 2: Voeg credentials toe aan `.env.local`

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

### Stap 3: Declareer volumes in `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokale schijf'
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
                prefix: 'media/{Y}'       # Dynamische placeholders {Y}/{m}/{d}/{user_id} werken ook in S3-prefixes!
                disable_acl: true         # Uitdrukkelijk aanbevolen voor standaard AWS S3-buckets met "Bucket owner enforced"

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

### Volledige `AwsS3` `driver_options`-referentie

| Optie | Standaard | Beschrijving |
| :--- | :--- | :--- |
| `bucket` | *(Verplicht)* | Naam van de S3-doelbucket. |
| `region` | `'us-east-1'` | Regiocode (bijv. `'eu-central-1'`, of `'auto'` voor Cloudflare R2). |
| `endpoint` | `null` | Aangepaste endpoint-URL voor niet-AWS S3-compatibele providers (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | S3-toegangssleutels (laat leeg om automatisch EC2/ECS IAM-rollen te gebruiken). |
| `token` | `null` | Tijdelijk AWS STS-sessietoken (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | API-versie van de AWS S3 SDK. |
| `prefix` | `''` | Hoofdsubmap binnen de bucket (ondersteunt `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Stel in op `true` voor Supabase, MinIO, R2, B2 en vergelijkbare providers. |
| `acl` | `null` | Aangepaste S3 ACL-header indien nodig (bijv. `'public-read'`). |
| `disable_acl` | **`true` bij een aangepast `endpoint`; `false` op native AWS S3** | Laat de `x-amz-acl`-header weg wanneer `true`. Stel `disable_acl: true` expliciet in op native AWS S3-buckets met *Bucket owner enforced* ingeschakeld. |
| `retain_visibility` | `false` bij een aangepast `endpoint` | Bepaalt of ACL's worden gelezen of geschreven tijdens verplaats- en kopieeracties. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` bij een aangepast `endpoint` | Automatisch geconfigureerd voor compatibiliteit met AWS SDK `>= 3.337` op R2, B2, Supabase en MinIO. |
| `ca_bundle` | `null` | Pad naar een aangepaste SSL CA-certificaatbundel (`.pem`) — verhelpt `cURL error 60` op Windows. |

### Architectuurnotities

- **Symlink-isolatie op lokale schijven (`ConfinedLocalFilesystemAdapter`):** Elke lees-, schrijf-, verplaats- en verwijderactie valideert elk padsegment met `is_link()` + `realpath()`; mapoverzichten dwingen `SKIP_LINKS` af.
- **Cloudbuckets met MIME-beperkingen & Prullenbak:** Als een cloudbucket het schrijven van `.meta.json`-sidecarbestanden weigert, valt `TrashManager` automatisch terug op lokale opslag van prullenbakmetadata (`trash-meta.json`) in de map uit `defaults.metadata.file`.
- **Miniaturen, video's & PDF's:** Miniaturen worden eenmalig opgehaald uit de cloudopslag en lokaal gecachet in `public/media-cache`. Openbare (`private: false`) afbeeldingen en video's worden rechtstreeks vanaf hun CDN-URL geladen; PDF's worden gestreamd via `DownloadController?inline=1` met een strikte `script-src 'none'` CSP (of geforceerde `attachment` wanneer `allow_pdf_preview: false`).
- **CORS:** Om de ingebouwde Canvas-afbeeldingseditor te gebruiken met afbeeldingen uit de cloud, moet je `GET`- en `HEAD`-verzoeken vanaf jouw domein toestaan in het CORS-beleid van je bucket.
- **Langdraaiende workers:** `FlysystemStorageFactory` implementeert `ResetInterface` en houdt de adaptercache begrensd (opschoning van 64 → 32) tussen verzoeken door.

---

## 2. Een bestaande Flysystem-service hergebruiken (`FlysystemService`)

Als je applicatie al een `League\Flysystem\FilesystemOperator`-service definieert (via `league/flysystem-bundle` of een eigen configuratie voor Azure Blob, SFTP, FTP, WebDAV enz.), kun je direct naar de service-ID verwijzen:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP-archief'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # League\Flysystem\FilesystemOperator service-ID
```

> [!NOTE]
> **Geen `public: true` nodig!** `FlysystemServiceLocatorPass` registreert elke service waarnaar via `driver_options.service` wordt verwezen — evenals services met de tag `liveux_file_manager.storage` of `flysystem.storage` — automatisch in een privé-`ServiceLocator`.

---

## 3. Cloudinstellingen beheren vanuit een beheerpaneel (Databasegestuurd) — Volledige gids

Wil je klanten of sitebeheerders hun **eigen AWS S3-, Cloudflare R2-, Supabase-, MinIO- of Backblaze B2-buckets rechtstreeks vanuit het beheerpaneel laten configureren**, volg dan deze **5 stappen**.

### Hoe het werkt (Overzicht)

```text
[Beheerpaneel-formulier] ──► Secret versleuteld (Sodium) ──► [Database: cloud_storage_setting]
           │                                                                 │
           ▼                                                                 ▼
[Knop "Verbinding testen"]                                   [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                   (priority: 10 > YAML priority: 0)
           │                                                                 │
           ▼                                                                 ▼
[Cache invalideren (DirectoryCacheManager)]                  [Direct zichtbaar in Bestandsbeheer!]
```

### Stap 1: Maak de database-entity aan (`CloudStorageSetting`)

> [!WARNING]
> **Gouden beveiligingsregel:** Sla een S3 `Secret Access Key` **NOOIT** in platte tekst op in je database. Sla altijd een versleutelde waarde (`encryptedSecret`) op zoals hieronder weergegeven.

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

    // Unieke volumesleutel — bijv. 'panel_r2', 'panel_supabase' (alleen alfanumeriek + underscore)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Weergavenaam in de zijbalk — bijv. 'Cloudflare R2 Media'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Providersleutel: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Base64-gecodeerde Sodium-cijfertekst (nooit platte tekst!)
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

    // FileManager-profiel waaraan dit volume wordt gekoppeld (bijv. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Standaard getters en setters...
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

### Stap 2: Bouw een versleutelingsservice (Ingebouwde PHP 8.3 `ext-sodium`)

Gebruik de ingebouwde geauthenticeerde encryptie van PHP (`sodium_crypto_secretbox`) zonder extra packages te hoeven installeren:

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
        // Tip: Definieer een aparte CLOUD_ENCRYPTION_KEY in .env (los van APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Leid een vaste encryptiesleutel van 32 bytes af
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
            throw new \RuntimeException('Kan versleutelde cloudopslagsleutel niet decoderen.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('De versleutelde cloudopslagsleutel is ongeldig of beschadigd.');
        }

        return $plain;
    }
}
```

### Stap 3: Implementeer een dynamische `ConfigProviderInterface` (`DatabaseCloudConfigProvider`)

Geregistreerd met **`priority: 10`** draait deze provider vóór de YAML-provider (`priority: 0`). Hij laadt het basis-YAML-profiel vanuit `$this->yamlProvider` en voegt actieve cloudvolumes uit de database toe — zo **blijven je lokale YAML-schijven behouden terwijl nieuw opgeslagen cloudschijven direct in de zijbalk verschijnen**:

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

        // Voeg cloudvolumes uit de database samen met bestaande YAML-volumes:
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

### Stap 4: Beheercontroller (Opslaan + Live "Verbinding testen" + Cache-invalidatie)

Wanneer een beheerder cloudinstellingen opslaat vanuit het beheerpaneel, gebeuren er drie essentiële dingen:

1. Als er een nieuw secret is ingevuld, wordt dit versleuteld via `SecretBoxEncryptor::encrypt()` (het bestaande secret wordt **nooit** teruggestuurd in de HTML!).
2. Klikken op de optionele knop **"Verbinding testen"** roept `FlysystemStorageFactory::createFilesystem()` aan en voert live `listContents('', false)` uit op de bucket om credentials en endpoint te verifiëren vóór het opslaan.
3. Na het opslaan worden `DirectoryCacheManager::invalidateProfile()` en `FlysystemStorageFactory::reset()` aangeroepen zodat het nieuwe volume direct actief wordt.

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
            // plainSecret heeft mapped: false in het formulier; leeg laten behoudt het bestaande versleutelde secret
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Als op "Verbinding testen" is geklikt, controleer eerst live vóór het opslaan:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Lees het eerste item uit om sleutel, bucket en endpoint live te controleren:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Cloudverbinding geslaagd! Je kunt deze instellingen nu opslaan.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Verbinding mislukt: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Wis caches en adapterpool -> direct actief in het bestandsbeheer!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'De instellingen voor cloudopslag zijn opgeslagen en geactiveerd.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Stap 5: Beveiligingschecklist voor cloudinstellingen in het beheerpaneel

- **Strikte rolcontrole:** Beperk deze controller tot `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **SSRF-bescherming voor endpoints:** Valideer het veld `endpoint` met Symfony Validator (`#[Assert\Url]`) zodat alleen `https://`-URL's worden geaccepteerd en interne metadata-/loopback-IP's (`169.254.169.254`, `127.0.0.1`, enz.) worden geblokkeerd (tenzij je expliciet een lokale MinIO-instantie toestaat).
- **Secret-maskering:** Configureer `plainSecret` in het formulier met `mapped => false`, `required => false` en `type="password"`, zodat leeg laten het opgeslagen versleutelde secret intact laat.
- **Back-up van de hoofdsleutel:** Maak een veilige back-up van `CLOUD_ENCRYPTION_KEY` uit `.env.local` in een secrets vault — als deze sleutel wordt geroteerd of kwijtraakt, worden eerder versleutelde database-secrets onleesbaar.

---

## 4. Metadata-opslag voor multi-serveromgevingen (Doctrine DBAL / ORM)

Standaard worden mapkleuren, pictogrammen, vastgemaakte items en SEO-metadata van bestanden opgeslagen in `var/file-manager/metadata.json` (`driver: 'json_file'`). Draai je meerdere applicatieservers achter een loadbalancer (Docker-/Kubernetes-replica's), schakel dan over op de ingebouwde `defaults.metadata.driver: 'doctrine'`-backend — zie **[13 — Doctrine ORM & DBAL Integratiehandleiding](13-doctrine-integration.md)** voor de volledige configuratie, PostgreSQL-/MySQL-migraties en entity-voorbeelden.

---

[← Vorige: 06 — Beveiliging & Autorisatie](06-security-and-authorization.md) | [Hoofdoverzicht](README.md) | [Volgende: 08 — Events, Commando's, Cache & Probleemoplossing →](08-events-commands-cache-and-troubleshooting.md)
