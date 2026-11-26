# 07 — Pamięć w chmurze (S3 / R2 / MinIO / Supabase) i dynamiczna konfiguracja z panelu administratora

[← Poprzedni: 06 — Bezpieczeństwo i autoryzacja](06-security-and-authorization.md) | [Spis treści](README.md) | [Następny: 08 — Zdarzenia, polecenia CLI, pamięć podręczna i rozwiązywanie problemów →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle jest zbudowany w oparciu o **League Flysystem v3**. W ramach jednego profilu możesz łączyć dyski lokalne z pamięcią w chmurze (**Multi-Volume**), a nawet przeciągać i upuszczać pliki bezpośrednio z dysku lokalnego do S3, R2 lub Supabase.

> [!TIP]
> **Dwa sposoby zarządzania danymi dostępowymi do chmury**
> 1. **Przez `.env` + YAML (podejście developerskie, najszybsza konfiguracja):** przechowujesz klucze w `.env.local` i deklarujesz wolumeny w `liveux_file_manager.yaml` (Sekcja 1).
> 2. **Przez panel administratora + bazę danych (gotowe pod klientów / SaaS):** tworzysz w panelu administratora ekran *„Ustawienia pamięci w chmurze”*, na którym administratorzy wpisują dane dostępowe AWS S3 / R2 / Supabase, klikają **„Testuj połączenie”** i zapisują konfigurację — nowe wolumeny w chmurze pojawiają się w menedżerze plików natychmiast, bez dostępu SSH i edycji plików `.env` (Sekcja 3).

---

## Przewodniki krok po kroku dla dostawców chmury

Przejdź bezpośrednio do dedykowanego poradnika konfiguracji dla swojego dostawcy:

| Dostawca | Kiedy warto wybrać | Sterownik | Klucz ikony | Przewodnik |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Korzystasz już z Supabase, zależy Ci na szybkiej konfiguracji i hojnym darmowym planie | `AwsS3` | `supabase` *(auto)* | [Przewodnik Supabase Storage →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Skala enterprise, role IAM oraz integracja z CloudFront CDN | `AwsS3` | `aws_s3` *(auto)* | [Przewodnik Amazon AWS S3 →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Zerowe opłaty za transfer wychodzący (egress)**, duży ruch obrazów i pobierania plików | `AwsS3` | `cloudflare_r2` *(auto)* | [Przewodnik Cloudflare R2 →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Bardzo niski koszt przechowywania za GB, duże archiwa wideo i kopie zapasowe | `AwsS3` | `backblaze_b2` *(auto)* | [Przewodnik Backblaze B2 →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | Własny serwer S3 (self-hosted) lub Docker (RODO / suwerenność danych) | `AwsS3` | `minio` *(auto)* | [Przewodnik MinIO i RustFS →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Aplikacje działające w Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(auto)* | [Przewodnik Google Cloud Storage →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / własny adapter** | Istniejący serwer SFTP, Azure Blob lub dowolny niestandardowy adapter Flysystem | `FlysystemService` | `sftp` / `cloud` | [Przewodnik SFTP i własny Flysystem →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Po skonfigurowaniu wolumenów przetestuj na żywo wszystkie połączenia z chmurą jednym poleceniem:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. Konfiguracja pamięci zgodnej z S3 (`.env` + YAML w 3 krokach)

### Krok 1: Zainstaluj pakiety S3

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Krok 2: Dodaj dane dostępowe do `.env.local`

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

### Krok 3: Zadeklaruj wolumeny w `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Dysk lokalny'
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
                prefix: 'media/{Y}'       # Dynamiczne placeholdery {Y}/{m}/{d}/{user_id} działają również w prefiksach S3!
                disable_acl: true         # Wyraźnie zalecane dla standardowych bucketów AWS S3 z opcją „Bucket owner enforced”

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

### Pełna referencja opcji `driver_options` dla sterownika `AwsS3`

| Opcja | Wartość domyślna | Opis |
| :--- | :--- | :--- |
| `bucket` | *(Wymagana)* | Nazwa docelowego bucketa S3. |
| `region` | `'us-east-1'` | Kod regionu (np. `'eu-central-1'` lub `'auto'` dla Cloudflare R2). |
| `endpoint` | `null` | Niestandardowy adres URL punktu końcowego dla dostawców zgodnych z S3 spoza AWS (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | Klucze dostępowe S3 (pozostaw puste, aby automatycznie korzystać z ról IAM na EC2/ECS). |
| `token` | `null` | Tymczasowy token sesyjny AWS STS (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | Wersja API AWS S3 SDK. |
| `prefix` | `''` | Główny podfolder wewnątrz bucketa (obsługuje `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Ustaw `true` dla Supabase, MinIO, R2, B2 i podobnych dostawców. |
| `acl` | `null` | Niestandardowy nagłówek ACL dla S3, jeśli jest wymagany (np. `'public-read'`). |
| `disable_acl` | **`true`, gdy ustawiono własny `endpoint`; `false` dla natywnego AWS S3** | Gdy `true`, pomija nagłówek `x-amz-acl`. Dla natywnych bucketów AWS S3 z włączonym *Bucket owner enforced* ustaw jawnie `disable_acl: true`. |
| `retain_visibility` | `false`, gdy ustawiono własny `endpoint` | Określa, czy podczas operacji przenoszenia/kopiowania podejmowane są próby odczytu/zapisu ACL. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'`, gdy ustawiono własny `endpoint` | Automatycznie konfigurowane dla zgodności z AWS SDK `>= 3.337` w R2, B2, Supabase oraz MinIO. |
| `ca_bundle` | `null` | Ścieżka do własnego pliku certyfikatów SSL CA `.pem` (rozwiązuje błąd `cURL error 60` w systemie Windows). |

### Uwagi architektoniczne

- **Izolacja dowiązań symbolicznych na dysku lokalnym (`ConfinedLocalFilesystemAdapter`):** każdy odczyt, zapis, przeniesienie i usunięcie waliduje każdy segment ścieżki za pomocą `is_link()` + `realpath()`; listowanie katalogów wymusza `SKIP_LINKS`.
- **Buckety chmurowe z ograniczeniami MIME a Kosz:** jeśli bucket w chmurze odrzuca zapis plików pomocniczych `.meta.json`, `TrashManager` automatycznie przełącza się na lokalne zapisywanie metadanych Kosza (`trash-meta.json`) w katalogu `defaults.metadata.file`.
- **Miniatury, wideo i pliki PDF:** miniatury są pobierane z chmury raz i zapisywane w lokalnej pamięci podręcznej `public/media-cache`. Publiczne (`private: false`) obrazy i wideo ładują się bezpośrednio z adresu CDN; pliki PDF są strumieniowane przez `DownloadController?inline=1` ze ścisłym nagłówkiem CSP `script-src 'none'` (lub wymuszonym `attachment`, gdy `allow_pdf_preview: false`).
- **CORS:** aby korzystać z wbudowanego edytora obrazów (Canvas Image Editor) dla grafik przechowywanych w chmurze, zezwól w polityce CORS bucketa na żądania `GET` i `HEAD` ze swojej domeny.
- **Długo działające workery (long-running workers):** `FlysystemStorageFactory` implementuje `ResetInterface` i utrzymuje ograniczony rozmiar pamięci podręcznej adapterów (przycinanie 64 → 32) pomiędzy żądaniami.

---

## 2. Ponowne użycie istniejącej usługi Flysystem (`FlysystemService`)

Jeśli Twoja aplikacja definiuje już usługę `League\Flysystem\FilesystemOperator` (przez `league/flysystem-bundle` lub własną konfigurację dla Azure Blob, SFTP, FTP, WebDAV itp.), odwołaj się bezpośrednio do jej identyfikatora usługi:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Archiwum SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # ID usługi League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **Nie trzeba ustawiać `public: true`!** `FlysystemServiceLocatorPass` automatycznie rejestruje każdą usługę wskazaną w `driver_options.service` — a także usługi oznaczone tagiem `liveux_file_manager.storage` lub `flysystem.storage` — w prywatnym kontenerze `ServiceLocator`.

---

## 3. Zarządzanie ustawieniami chmury z panelu administratora (w oparciu o bazę danych) — pełny poradnik

Jeśli chcesz, aby klienci lub administratorzy serwisu mogli **konfigurować własne buckety AWS S3, Cloudflare R2, Supabase, MinIO czy Backblaze B2 bezpośrednio z panelu administratora**, wykonaj poniższe **5 kroków**.

### Jak to działa (schemat ogólny)

```text
[Formularz panelu admina] ──► Szyfrowanie sekretu (Sodium) ──► [Baza danych: cloud_storage_setting]
            │                                                                  │
            ▼                                                                  ▼
[Przycisk „Testuj połączenie”]                                 [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                      (priority: 10 > YAML priority: 0)
            │                                                                  │
            ▼                                                                  ▼
[Unieważnienie cache (DirectoryCacheManager)]                  [Widoczne od razu w Menedżerze plików!]
```

### Krok 1: Utwórz encję bazodanową (`CloudStorageSetting`)

> [!WARNING]
> **Złota zasada bezpieczeństwa:** **NIGDY** nie zapisuj klucza `Secret Access Key` dla S3 w bazie danych otwartym tekstem. Zawsze przechowuj zaszyfrowaną wartość (`encryptedSecret`), tak jak pokazano poniżej.

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

    // Unikalny klucz wolumenu — np. 'panel_r2', 'panel_supabase' (tylko znaki alfanumeryczne i podkreślenie)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Etykieta wyświetlana w panelu bocznym — np. 'Media Cloudflare R2'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Klucz dostawcy: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Szyfrogram Sodium zakodowany w Base64 (nigdy otwarty tekst!)
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

    // Nazwa profilu FileManager, do którego podpinany jest ten wolumen (np. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Standardowe gettery i settery...
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

### Krok 2: Utwórz usługę szyfrowania sekretów (wbudowane w PHP 8.3 `ext-sodium`)

Wykorzystaj natywne szyfrowanie uwierzytelnione PHP (`sodium_crypto_secretbox`) bez instalowania dodatkowych pakietów:

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
        // Wskazówka: zdefiniuj w .env dedykowany klucz CLOUD_ENCRYPTION_KEY (oddzielny od APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Wyprowadź stały 32-bajtowy klucz szyfrowania
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
            throw new \RuntimeException('Nie udało się zdekodować zaszyfrowanego klucza pamięci w chmurze.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('Zaszyfrowany klucz pamięci w chmurze jest nieprawidłowy lub uszkodzony.');
        }

        return $plain;
    }
}
```

### Krok 3: Zaimplementuj dynamiczny `ConfigProviderInterface` (`DatabaseCloudConfigProvider`)

Zarejestrowany z **`priority: 10`**, ten provider uruchamia się przed providerem YAML (`priority: 0`). Pobiera bazowy profil YAML z `$this->yamlProvider` i dołącza aktywne wolumeny chmurowe skonfigurowane w bazie danych — dzięki czemu **lokalne dyski z YAML pozostają nietknięte, a nowo zapisane dyski w chmurze od razu pojawiają się w panelu bocznym**:

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

        // Połącz wolumeny chmurowe z bazy danych z istniejącymi wolumenami YAML:
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

### Krok 4: Kontroler administratora (zapis + „Testuj połączenie” na żywo + unieważnienie pamięci podręcznej)

Gdy administrator zapisuje ustawienia chmury w panelu, zachodzą trzy kluczowe kroki:

1. Jeśli podano nowy sekret, jest on szyfrowany przez `SecretBoxEncryptor::encrypt()` (dotychczasowy sekret **nigdy** nie jest zwracany z powrotem do HTML!).
2. Kliknięcie opcjonalnego przycisku **„Testuj połączenie”** wywołuje `FlysystemStorageFactory::createFilesystem()` i wykonuje test na żywo `listContents('', false)` na buckecie, weryfikując dane dostępowe i punkt końcowy jeszcze przed zapisem.
3. Po zapisaniu wywoływane są `DirectoryCacheManager::invalidateProfile()` oraz `FlysystemStorageFactory::reset()`, dzięki czemu nowy wolumen działa natychmiast.

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
            // Pole plainSecret ma w formularzu mapped: false; pozostawienie pustego zachowuje istniejący zaszyfrowany sekret
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Jeśli kliknięto „Testuj połączenie”, sprawdź konfigurację na żywo przed zapisem:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Odczytaj pierwszy wpis, aby zweryfikować klucz, bucket i endpoint na żywo:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Połączenie z chmurą powiodło się! Możesz teraz zapisać ustawienia.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Połączenie nie powiodło się: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Wyczyść pamięci podręczne i pulę adapterów -> zmiana jest natychmiast aktywna w menedżerze plików!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Ustawienia pamięci w chmurze zostały zapisane i aktywowane.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Krok 5: Lista kontrolna bezpieczeństwa dla ustawień chmury w panelu administratora

- **Ścisła kontrola uprawnień:** ogranicz dostęp do tego kontrolera za pomocą `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **Ochrona przed SSRF dla pola `endpoint`:** waliduj pole `endpoint` za pomocą Symfony Validator (`#[Assert\Url]`), tak aby akceptowane były wyłącznie adresy `https://`, a wewnętrzne adresy metadanych/loopback (`169.254.169.254`, `127.0.0.1` itd.) były blokowane (chyba że świadomie zezwalasz na lokalne MinIO).
- **Maskowanie sekretu:** skonfiguruj `plainSecret` w formularzu z opcjami `mapped => false`, `required => false` oraz `type="password"`, dzięki czemu pozostawienie pustego pola zachowa dotychczasowy zaszyfrowany sekret.
- **Kopia zapasowa klucza głównego:** przechowuj kopię `CLOUD_ENCRYPTION_KEY` z `.env.local` w bezpiecznym menedżerze sekretów (vault) — jego utrata lub rotacja bez re-szyfrowania uniemożliwi odczyt wcześniej zapisanych kluczy z bazy danych.

---

## 4. Przechowywanie metadanych w wdrożeniach wieloserwerowych (Doctrine DBAL / ORM)

Domyślnie kolory folderów, ikony, przypięte elementy oraz metadane SEO plików są zapisywane w pliku `var/file-manager/metadata.json` (`driver: 'json_file'`). Jeśli uruchamiasz wiele serwerów aplikacji za load balancerem (repliki Docker/Kubernetes), przełącz się na wbudowany backend `defaults.metadata.driver: 'doctrine'` — zobacz **[13 — Przewodnik integracji z Doctrine ORM i DBAL](13-doctrine-integration.md)**, gdzie znajdziesz pełną konfigurację, migracje dla PostgreSQL/MySQL oraz przykładowe encje.

---

[← Poprzedni: 06 — Bezpieczeństwo i autoryzacja](06-security-and-authorization.md) | [Spis treści](README.md) | [Następny: 08 — Zdarzenia, polecenia CLI, pamięć podręczna i rozwiązywanie problemów →](08-events-commands-cache-and-troubleshooting.md)
