# 07 — Cloud Storage (S3 / R2 / MinIO / Supabase) e Configurazione Dinamica da Pannello Admin

[← Precedente: 06 — Sicurezza e Autorizzazione](06-security-and-authorization.md) | [Indice Principale](README.md) | [Successivo: 08 — Eventi, Comandi, Cache e Risoluzione dei Problemi →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle è costruito su **League Flysystem v3**. Puoi combinare dischi locali e cloud storage fianco a fianco nello stesso profilo (**Multi-Volume**) e persino trascinare i file direttamente dallo storage locale verso S3, R2 o Supabase.

> [!TIP]
> **Due Modi per Gestire le Credenziali di Cloud Storage**
> 1. **Tramite `.env` + YAML (Orientato agli sviluppatori, configurazione più rapida):** salva le chiavi in `.env.local` e dichiara i volumi in `liveux_file_manager.yaml` (Sezione 1).
> 2. **Tramite Pannello Admin + Database (Pronto per Clienti / SaaS):** crea una schermata *"Impostazioni Cloud Storage"* nel tuo pannello di amministrazione dove gli admin inseriscono le credenziali AWS S3 / R2 / Supabase, fanno clic su **"Testa connessione"** e salvano: i nuovi volumi cloud compaiono immediatamente nel file manager senza accesso SSH né modifiche ai file `.env` (Sezione 3).

---

## Guide Passo-Passo per Provider Cloud

Vai direttamente alla guida dedicata al tuo provider di storage:

| Provider | Quando Sceglierlo | Driver | Chiave Icona | Guida |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Usi già Supabase e vuoi una configurazione rapida con un piano gratuito generoso | `AwsS3` | `supabase` *(auto)* | [Guida Supabase Storage →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Scala enterprise, ruoli IAM e integrazione con CloudFront CDN | `AwsS3` | `aws_s3` *(auto)* | [Guida Amazon AWS S3 →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Zero costi di egress**, download ad alto traffico di immagini e file | `AwsS3` | `cloudflare_r2` *(auto)* | [Guida Cloudflare R2 →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Costo per GB ultra-basso, grandi archivi video o backup | `AwsS3` | `backblaze_b2` *(auto)* | [Guida Backblaze B2 →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | S3 self-hosted sul tuo server o su Docker (GDPR / residenza dei dati) | `AwsS3` | `minio` *(auto)* | [Guida MinIO e RustFS →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Applicazioni eseguite su Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(auto)* | [Guida Google Cloud Storage →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Custom** | Server SFTP esistente, Azure Blob o qualsiasi adapter Flysystem personalizzato | `FlysystemService` | `sftp` / `cloud` | [Guida SFTP e Flysystem Personalizzato →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Dopo aver configurato i volumi, verifica dal vivo tutte le connessioni cloud con un solo comando:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. Configurazione Cloud Storage Compatibile S3 (`.env` + YAML in 3 Passaggi)

### Passaggio 1: Installa i Pacchetti S3

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Passaggio 2: Aggiungi le Credenziali in `.env.local`

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

### Passaggio 3: Dichiara i Volumi in `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disco Locale'
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
                prefix: 'media/{Y}'       # I segnaposto dinamici {Y}/{m}/{d}/{user_id} funzionano anche nei prefissi S3!
                disable_acl: true         # Consigliato esplicitamente per bucket AWS S3 standard con "Bucket owner enforced"

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

### Riferimento Completo delle `driver_options` per `AwsS3`

| Opzione | Predefinito | Descrizione |
| :--- | :--- | :--- |
| `bucket` | *(Obbligatoria)* | Nome del bucket S3 di destinazione. |
| `region` | `'us-east-1'` | Codice regione (es. `'eu-central-1'`, oppure `'auto'` per Cloudflare R2). |
| `endpoint` | `null` | URL endpoint personalizzato per provider compatibili S3 non-AWS (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | Credenziali di accesso S3 (lascia vuoto per usare automaticamente i ruoli IAM di EC2/ECS). |
| `token` | `null` | Token di sessione temporaneo AWS STS (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | Versione API dell'SDK AWS S3. |
| `prefix` | `''` | Sottocartella radice all'interno del bucket (supporta `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Imposta a `true` per Supabase, MinIO, R2, B2 e provider simili. |
| `acl` | `null` | Header ACL S3 personalizzato se necessario (es. `'public-read'`). |
| `disable_acl` | **`true` quando è impostato un `endpoint` personalizzato; `false` su AWS S3 nativo** | Se `true`, omette l'header `x-amz-acl`. Imposta esplicitamente `disable_acl: true` sui bucket AWS S3 nativi con *Bucket owner enforced* attivo. |
| `retain_visibility` | `false` quando è impostato un `endpoint` personalizzato | Controlla se tentare letture/scritture ACL durante le operazioni di spostamento o copia. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` quando è impostato un `endpoint` personalizzato | Configurati automaticamente per la compatibilità con AWS SDK `>= 3.337` su R2, B2, Supabase e MinIO. |
| `ca_bundle` | `null` | Percorso a un bundle `.pem` di certificati CA SSL personalizzato (risolve `cURL error 60` su Windows). |

### Note Architetturali

- **Confinamento Symlink su Disco Locale (`ConfinedLocalFilesystemAdapter`):** ogni lettura, scrittura, spostamento ed eliminazione valida ciascun segmento del percorso con `is_link()` + `realpath()`; l'elenco delle directory applica `SKIP_LINKS`.
- **Bucket Cloud con Restrizioni MIME e Cestino:** se un bucket cloud rifiuta la scrittura di file sidecar `.meta.json`, `TrashManager` esegue automaticamente il fallback salvando i metadati del cestino localmente (`trash-meta.json`) nella directory configurata in `defaults.metadata.file`.
- **Miniature, Video e PDF:** le miniature vengono recuperate una sola volta dal cloud storage e messe in cache localmente in `public/media-cache`. Immagini e video pubblici (`private: false`) vengono caricati direttamente dall'URL della CDN; i PDF vengono serviti in streaming tramite `DownloadController?inline=1` con una CSP rigorosa `script-src 'none'` (oppure download forzato come `attachment` quando `allow_pdf_preview: false`).
- **CORS:** per usare l'Image Editor Canvas integrato con immagini ospitate in cloud, consenti le richieste `GET` e `HEAD` dal tuo dominio nella policy CORS del bucket.
- **Worker a Lunga Durata:** `FlysystemStorageFactory` implementa `ResetInterface` e mantiene limitata la cache degli adapter (riduzione 64 → 32) tra una richiesta e l'altra.

---

## 2. Riutilizzare un Servizio Flysystem Esistente (`FlysystemService`)

Se la tua applicazione definisce già un servizio `League\Flysystem\FilesystemOperator` (tramite `league/flysystem-bundle` o configurazione personalizzata per Azure Blob, SFTP, FTP, WebDAV, ecc.), fai riferimento direttamente al suo ID di servizio:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Archivio SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # ID del servizio League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **Nessun `public: true` Necessario!** `FlysystemServiceLocatorPass` registra automaticamente ogni servizio referenziato tramite `driver_options.service` — così come i servizi con tag `liveux_file_manager.storage` o `flysystem.storage` — in un `ServiceLocator` privato.

---

## 3. Gestire le Impostazioni Cloud da Pannello Admin (Basato su Database) — Guida Completa

Se vuoi permettere a clienti o amministratori del sito di **configurare i propri bucket AWS S3, Cloudflare R2, Supabase, MinIO o Backblaze B2 direttamente dal pannello admin**, segui questi **5 passaggi**.

### Come Funziona (Visione d'Insieme)

```text
[Form Pannello Admin] ──► Secret cifrato (Sodium) ──► [Database: cloud_storage_setting]
         │                                                            │
         ▼                                                            ▼
[Pulsante "Testa connessione"]                            [DatabaseCloudConfigProvider]
  (FlysystemStorageFactory)                               (priority: 10 > YAML priority: 0)
         │                                                            │
         ▼                                                            ▼
[Invalida Cache (DirectoryCacheManager)]                  [Visibile Subito nel File Manager!]
```

### Passaggio 1: Crea l'Entity Doctrine (`CloudStorageSetting`)

> [!WARNING]
> **Regola d'Oro per la Sicurezza:** **NON** salvare **MAI** una `Secret Access Key` S3 in chiaro nel database. Salva sempre un payload cifrato (`encryptedSecret`) come mostrato di seguito.

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

    // Chiave univoca del volume — es. 'panel_r2', 'panel_supabase' (solo caratteri alfanumerici e underscore)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Etichetta visualizzata nella barra laterale — es. 'Media Cloudflare R2'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Chiave provider: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Testo cifrato Sodium codificato in Base64 (mai in chiaro!)
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

    // Profilo FileManager a cui viene associato questo volume (es. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Getter e setter standard...
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

### Passaggio 2: Crea un Servizio di Cifratura per i Secret (`ext-sodium` Integrato in PHP 8.3)

Utilizza la cifratura autenticata nativa di PHP (`sodium_crypto_secretbox`) senza installare alcun pacchetto aggiuntivo:

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
        // Suggerimento: definisci una CLOUD_ENCRYPTION_KEY dedicata in .env (separata da APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Deriva una chiave di cifratura fissa a 32 byte
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
            throw new \RuntimeException('Impossibile decodificare la chiave cloud storage cifrata.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('La chiave cloud storage cifrata non è valida o è corrotta.');
        }

        return $plain;
    }
}
```

### Passaggio 3: Implementa un `ConfigProviderInterface` Dinamico (`DatabaseCloudConfigProvider`)

Registrato con **`priority: 10`**, questo provider viene eseguito prima del provider YAML (`priority: 0`). Carica il profilo YAML di base da `$this->yamlProvider` e aggiunge i volumi cloud attivi configurati nel database, così **i tuoi dischi locali YAML restano intatti mentre i nuovi dischi cloud salvati appaiono subito nella barra laterale**:

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

        // Unisce i volumi cloud definiti nel database ai volumi YAML esistenti:
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

### Passaggio 4: Controller Admin (Salvataggio + "Testa connessione" Live + Invalidazione Cache)

Quando un amministratore salva le impostazioni cloud dal pannello admin, avvengono tre operazioni fondamentali:

1. Se è stato inserito un nuovo secret, viene cifrato tramite `SecretBoxEncryptor::encrypt()` (il secret esistente **non** viene mai restituito nell'HTML!).
2. Facendo clic sul pulsante opzionale **"Testa connessione"**, viene chiamato `FlysystemStorageFactory::createFilesystem()` ed eseguito un controllo dal vivo `listContents('', false)` sul bucket per verificare credenziali ed endpoint prima di salvare.
3. Dopo il salvataggio, vengono chiamati `DirectoryCacheManager::invalidateProfile()` e `FlysystemStorageFactory::reset()` affinché il nuovo volume diventi attivo immediatamente.

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
            // plainSecret ha mapped: false nel form; lascia vuoto per mantenere il secret cifrato esistente
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Se è stato premuto "Testa connessione", verifica dal vivo prima di salvare:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Legge il primo elemento per verificare chiave, bucket ed endpoint dal vivo:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Connessione cloud riuscita! Ora puoi salvare queste impostazioni.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Connessione non riuscita: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Svuota cache e pool degli adapter -> attivo immediatamente nel file manager!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Impostazioni cloud storage salvate e attivate.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Passaggio 5: Checklist di Sicurezza per le Impostazioni Cloud Gestite da Admin

- **Controllo Ruoli Rigoroso:** limita questo controller con `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **Protezione SSRF sugli Endpoint:** valida il campo `endpoint` con Symfony Validator (`#[Assert\Url]`) in modo da accettare solo URL `https://` e bloccare indirizzi IP interni di metadati o loopback (`169.254.169.254`, `127.0.0.1`, ecc.), salvo autorizzazione esplicita per un MinIO locale.
- **Mascheramento del Secret:** configura `plainSecret` nel form con `mapped => false`, `required => false` e `type="password"` in modo che lasciarlo vuoto preservi il secret cifrato già salvato.
- **Backup della Master Key:** effettua il backup di `CLOUD_ENCRYPTION_KEY` da `.env.local` in un secrets vault sicuro: ruotarla o perderla renderebbe illeggibili i secret precedentemente cifrati nel database.

---

## 4. Archiviazione dei Metadati per Deploy Multi-Server (Doctrine DBAL / ORM)

Per impostazione predefinita, i colori delle cartelle, le icone, gli elementi fissati e i metadati SEO dei file sono salvati in `var/file-manager/metadata.json` (`driver: 'json_file'`). Se esegui più server applicativi dietro un load balancer (repliche Docker/Kubernetes), passa al backend integrato `defaults.metadata.driver: 'doctrine'` — consulta **[13 — Guida all'Integrazione Doctrine ORM & DBAL](13-doctrine-integration.md)** per la configurazione completa, le migrazioni PostgreSQL/MySQL e gli esempi di Entity.

---

[← Precedente: 06 — Sicurezza e Autorizzazione](06-security-and-authorization.md) | [Indice Principale](README.md) | [Successivo: 08 — Eventi, Comandi, Cache e Risoluzione dei Problemi →](08-events-commands-cache-and-troubleshooting.md)
