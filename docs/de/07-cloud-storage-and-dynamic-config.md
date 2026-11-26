# 07 — Cloud-Speicher (S3 / R2 / MinIO / Supabase) & Dynamische Admin-Konfiguration

[← Zurück: 06 — Sicherheit & Berechtigungen](06-security-and-authorization.md) | [Übersicht](README.md) | [Weiter: 08 — Events, Befehle, Cache & Fehlerbehebung →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle basiert auf **League Flysystem v3**. Sie können lokale Laufwerke und Cloud-Speicher im selben Profil kombinieren (**Multi-Volume**) und Dateien sogar direkt per Drag-and-Drop vom lokalen Speicher nach S3, R2 oder Supabase verschieben.

> [!TIP]
> **Zwei Wege zur Verwaltung von Cloud-Zugangsdaten**
> 1. **Über `.env` + YAML (entwicklerorientiert, schnellster Einstieg):** Hinterlegen Sie Zugangsdaten in `.env.local` und definieren Sie Volumes in `liveux_file_manager.yaml` (Abschnitt 1).
> 2. **Über Ihr Admin-Panel + Datenbank (Kunden- / SaaS-tauglich):** Bauen Sie eine Seite *„Cloud-Speicher-Einstellungen“* in Ihrem Admin-Panel, auf der Administratoren AWS-S3-, R2- oder Supabase-Zugangsdaten eingeben, auf **„Verbindung testen“** klicken und speichern – neue Cloud-Volumes erscheinen sofort im Dateimanager, ganz ohne SSH-Zugriff oder Bearbeiten von `.env`-Dateien (Abschnitt 3).

---

## Schritt-für-Schritt-Anleitungen nach Cloud-Anbieter

Springen Sie direkt zur passenden Einrichtungsanleitung für Ihren Speicheranbieter:

| Anbieter | Wann geeignet? | Treiber | Icon-Schlüssel | Anleitung |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Sie nutzen bereits Supabase und wünschen schnelles Setup & großzügigen Free Tier | `AwsS3` | `supabase` *(auto)* | [Supabase-Storage-Anleitung →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Enterprise-Skalierung, IAM-Rollen und CloudFront-CDN-Integration | `AwsS3` | `aws_s3` *(auto)* | [Amazon-AWS-S3-Anleitung →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Keine Egress-Gebühren**, hoher Bild- und Datei-Traffic | `AwsS3` | `cloudflare_r2` *(auto)* | [Cloudflare-R2-Anleitung →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Sehr günstige Kosten pro GB, große Video- und Backup-Archive | `AwsS3` | `backblaze_b2` *(auto)* | [Backblaze-B2-Anleitung →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | Selbst gehostetes S3 auf eigenem Server oder Docker (DSGVO / Datenhoheit) | `AwsS3` | `minio` *(auto)* | [MinIO- & RustFS-Anleitung →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Anwendungen, die auf Google Cloud Platform (GCP) laufen | `AwsS3` | `gcs` *(auto)* | [Google-Cloud-Storage-Anleitung →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Custom** | Bestehender SFTP-Server, Azure Blob oder ein beliebiger Flysystem-Adapter | `FlysystemService` | `sftp` / `cloud` | [SFTP- & Custom-Flysystem-Anleitung →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Nach der Konfiguration Ihrer Volumes können Sie jede Cloud-Verbindung mit einem einzigen Befehl live prüfen:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. S3-kompatiblen Cloud-Speicher einrichten (`.env` + YAML in 3 Schritten)

### Schritt 1: S3-Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Schritt 2: Zugangsdaten in `.env.local` hinterlegen

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

### Schritt 3: Volumes in `liveux_file_manager.yaml` deklarieren

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokaler Speicher'
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
                prefix: 'media/{Y}'       # Dynamische Platzhalter {Y}/{m}/{d}/{user_id} funktionieren auch in S3-Präfixen!
                disable_acl: true         # Ausdrücklich empfohlen für Standard-AWS-S3-Buckets mit „Bucket owner enforced“

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

### Vollständige Referenz der `AwsS3`-`driver_options`

| Option | Standardwert | Beschreibung |
| :--- | :--- | :--- |
| `bucket` | *(Erforderlich)* | Name des Ziel-S3-Buckets. |
| `region` | `'us-east-1'` | Regionscode (z. B. `'eu-central-1'` oder `'auto'` für Cloudflare R2). |
| `endpoint` | `null` | Benutzerdefinierte Endpoint-URL für nicht-AWS-S3-kompatible Anbieter (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | S3-Zugangsdaten (leer lassen, um automatisch EC2-/ECS-IAM-Rollen zu nutzen). |
| `token` | `null` | Temporäres AWS-STS-Session-Token (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | API-Version des AWS S3 SDK. |
| `prefix` | `''` | Wurzel-Unterordner im Bucket (unterstützt `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Auf `true` setzen für Supabase, MinIO, R2, B2 und ähnliche Anbieter. |
| `acl` | `null` | Benutzerdefinierter S3-ACL-Header bei Bedarf (z. B. `'public-read'`). |
| `disable_acl` | **`true` bei gesetztem `endpoint`; `false` bei nativem AWS S3** | Wenn `true`, wird der `x-amz-acl`-Header weggelassen. Bei nativen AWS-S3-Buckets mit aktiviertem *Bucket owner enforced* explizit `disable_acl: true` setzen. |
| `retain_visibility` | `false` bei gesetztem `endpoint` | Steuert, ob beim Verschieben/Kopieren ACL-Lese-/Schreibzugriffe versucht werden. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` bei gesetztem `endpoint` | Automatisch für die Kompatibilität mit AWS SDK `>= 3.337` bei R2, B2, Supabase und MinIO konfiguriert. |
| `ca_bundle` | `null` | Pfad zu einem eigenen SSL-CA-Zertifikats-Bundle im `.pem`-Format (behebt Windows `cURL error 60`). |

### Architekturhinweise

- **Symlink-Absicherung auf lokalem Speicher (`ConfinedLocalFilesystemAdapter`):** Jeder Lese-, Schreib-, Verschiebe- und Löschvorgang prüft jedes Pfadsegment per `is_link()` + `realpath()`; Verzeichnisauflistungen erzwingen `SKIP_LINKS`.
- **MIME-beschränkte Cloud-Buckets & Papierkorb:** Lehnt ein Cloud-Bucket das Schreiben von `.meta.json`-Sidecar-Dateien ab, speichert `TrashManager` die Papierkorb-Metadaten automatisch lokal (`trash-meta.json`) im Verzeichnis von `defaults.metadata.file`.
- **Vorschaubilder, Video & PDFs:** Vorschaubilder werden einmalig aus dem Cloud-Speicher geladen und lokal in `public/media-cache` zwischengespeichert. Öffentliche (`private: false`) Bilder und Videos laden direkt über ihre CDN-URL; PDFs werden über `DownloadController?inline=1` mit strikter CSP `script-src 'none'` gestreamt (oder bei `allow_pdf_preview: false` als erzwungener `attachment`-Download).
- **CORS:** Damit der integrierte Canvas-Bildeditor mit Bildern aus der Cloud funktioniert, erlauben Sie in der CORS-Richtlinie Ihres Buckets `GET`- und `HEAD`-Anfragen von Ihrer Domain.
- **Langlebige Worker-Prozesse:** `FlysystemStorageFactory` implementiert `ResetInterface` und hält seinen Adapter-Cache über Requests hinweg begrenzt (Bereinigung 64 → 32).

---

## 2. Einen bestehenden Flysystem-Service wiederverwenden (`FlysystemService`)

Wenn Ihre Anwendung bereits einen `League\Flysystem\FilesystemOperator`-Service definiert (über `league/flysystem-bundle` oder eine eigene Konfiguration für Azure Blob, SFTP, FTP, WebDAV usw.), referenzieren Sie dessen Service-ID direkt:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'SFTP-Archiv'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # Service-ID eines League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **Kein `public: true` erforderlich!** `FlysystemServiceLocatorPass` registriert automatisch jeden per `driver_options.service` referenzierten Service – sowie Services mit dem Tag `liveux_file_manager.storage` oder `flysystem.storage` – in einem privaten `ServiceLocator`.

---

## 3. Cloud-Einstellungen im Admin-Panel verwalten (datenbankgestützt) — Vollständige Anleitung

Wenn Kunden oder Website-Administratoren **ihre eigenen AWS-S3-, Cloudflare-R2-, Supabase-, MinIO- oder Backblaze-B2-Buckets direkt im Admin-Panel konfigurieren** sollen, folgen Sie diesen **5 Schritten**.

### Funktionsweise (Gesamtüberblick)

```text
[Admin-Panel-Formular] ──► Secret verschlüsselt (Sodium) ──► [Datenbank: cloud_storage_setting]
        │                                                            │
        ▼                                                            ▼
[Button „Verbindung testen“]                              [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                (priority: 10 > YAML priority: 0)
        │                                                            │
        ▼                                                            ▼
[Cache invalidieren (DirectoryCacheManager)]              [Sofort im Dateimanager sichtbar!]
```

### Schritt 1: Datenbank-Entity anlegen (`CloudStorageSetting`)

> [!WARNING]
> **Goldene Sicherheitsregel:** Speichern Sie einen S3-`Secret Access Key` **NIEMALS** im Klartext in Ihrer Datenbank. Speichern Sie stattdessen stets eine verschlüsselte Nutzlast (`encryptedSecret`) wie im folgenden Beispiel.

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

    // Eindeutiger Volume-Schlüssel — z. B. 'panel_r2', 'panel_supabase' (nur alphanumerisch + Unterstrich)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Anzeigename in der Seitenleiste — z. B. 'Cloudflare R2 Medien'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Anbieter-Schlüssel: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Base64-kodierter Sodium-Geheimtext (niemals Klartext!)
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

    // FileManager-Profil, dem dieses Volume zugeordnet wird (z. B. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Standard-Getter und -Setter...
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

### Schritt 2: Verschlüsselungs-Service für Secrets erstellen (integriertes PHP 8.3 `ext-sodium`)

Nutzen Sie PHPs native authentifizierte Verschlüsselung (`sodium_crypto_secretbox`) ganz ohne zusätzliche Pakete:

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
        // Tipp: Definieren Sie einen eigenen CLOUD_ENCRYPTION_KEY in .env (getrennt von APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Festen 32-Byte-Schlüssel ableiten
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
            throw new \RuntimeException('Verschlüsselter Cloud-Speicher-Schlüssel konnte nicht dekodiert werden.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('Der verschlüsselte Cloud-Speicher-Schlüssel ist ungültig oder beschädigt.');
        }

        return $plain;
    }
}
```

### Schritt 3: Dynamischen `ConfigProviderInterface` implementieren (`DatabaseCloudConfigProvider`)

Mit **`priority: 10`** registriert, greift dieser Provider vor dem YAML-Provider (`priority: 0`). Er lädt das Basis-YAML-Profil über `$this->yamlProvider` und hängt aktive, in der Datenbank konfigurierte Cloud-Volumes an – so bleiben **Ihre lokalen YAML-Laufwerke erhalten, während neu gespeicherte Cloud-Volumes sofort in der Seitenleiste erscheinen**:

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

        // Datenbank-Cloud-Volumes mit den bestehenden YAML-Volumes zusammenführen:
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

### Schritt 4: Admin-Controller (Speichern + Live-„Verbindung testen“ + Cache-Invalidierung)

Beim Speichern von Cloud-Einstellungen im Admin-Panel passieren drei entscheidende Schritte:

1. Wurde ein neues Secret eingegeben, wird es über `SecretBoxEncryptor::encrypt()` verschlüsselt (das gespeicherte Secret wird **niemals** zurück ins HTML ausgegeben!).
2. Ein Klick auf den optionalen Button **„Verbindung testen“** ruft `FlysystemStorageFactory::createFilesystem()` auf und führt eine Live-Prüfung per `listContents('', false)` gegen den Bucket durch, um Zugangsdaten und Endpoint vor dem Speichern zu validieren.
3. Nach dem Speichern werden `DirectoryCacheManager::invalidateProfile()` und `FlysystemStorageFactory::reset()` aufgerufen, sodass das neue Volume sofort wirksam ist.

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
            // plainSecret hat im Formular mapped: false; leer lassen, um das bestehende verschlüsselte Secret beizubehalten
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Wenn „Verbindung testen“ geklickt wurde, vor dem Speichern live prüfen:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Ersten Eintrag lesen, um Schlüssel, Bucket und Endpoint live zu verifizieren:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Cloud-Verbindung erfolgreich! Sie können die Einstellungen jetzt speichern.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Caches und Adapter-Pool leeren -> sofort im Dateimanager aktiv!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Cloud-Speicher-Einstellungen wurden gespeichert und aktiviert.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Schritt 5: Sicherheits-Checkliste für Admin-verwaltete Cloud-Einstellungen

- **Strenge Rollenabsicherung:** Beschränken Sie diesen Controller auf `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **SSRF-Schutz für Endpoints:** Validieren Sie das Feld `endpoint` mit dem Symfony Validator (`#[Assert\Url]`), sodass nur `https://`-URLs akzeptiert und interne Metadaten-/Loopback-IPs (`169.254.169.254`, `127.0.0.1` usw.) blockiert werden (sofern kein lokales MinIO explizit zugelassen ist).
- **Maskierung des Secrets:** Konfigurieren Sie `plainSecret` im Formular mit `mapped => false`, `required => false` und `type="password"`, sodass ein leer gelassenes Feld das gespeicherte verschlüsselte Secret unverändert lässt.
- **Sicherung des Master-Schlüssels:** Sichern Sie `CLOUD_ENCRYPTION_KEY` aus `.env.local` in einem geschützten Secrets-Vault – bei Verlust oder Rotation sind zuvor verschlüsselte Datenbank-Secrets nicht mehr lesbar.

---

## 4. Metadaten-Speicherung für Multi-Server-Deployments (Doctrine DBAL / ORM)

Standardmäßig werden Ordnerfarben, Icons, angeheftete Einträge und Datei-SEO-Metadaten in `var/file-manager/metadata.json` (`driver: 'json_file'`) gespeichert. Wenn Sie mehrere Anwendungsserver hinter einem Load Balancer betreiben (Docker-/Kubernetes-Replikate), wechseln Sie auf das integrierte Backend `defaults.metadata.driver: 'doctrine'` – Details zur Einrichtung, PostgreSQL-/MySQL-Migrationen und Entity-Beispielen finden Sie in **[13 — Doctrine-ORM- & DBAL-Integration](13-doctrine-integration.md)**.

---

[← Zurück: 06 — Sicherheit & Berechtigungen](06-security-and-authorization.md) | [Übersicht](README.md) | [Weiter: 08 — Events, Befehle, Cache & Fehlerbehebung →](08-events-commands-cache-and-troubleshooting.md)
