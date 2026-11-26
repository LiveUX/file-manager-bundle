# 07 — Stockage Cloud (S3 / R2 / MinIO / Supabase) & Configuration dynamique depuis l'administration

[← Précédent : 06 — Sécurité & Autorisations](06-security-and-authorization.md) | [Index principal](README.md) | [Suivant : 08 — Événements, Commandes, Cache & Dépannage →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle s'appuie sur **League Flysystem v3**. Vous pouvez combiner des disques locaux et des espaces de stockage cloud côte à côte au sein d'un même profil (**Multi-Volume**), et même glisser-déposer des fichiers directement depuis un disque local vers S3, R2 ou Supabase.

> [!TIP]
> **Deux façons de gérer vos identifiants de stockage cloud**
> 1. **Via `.env` + YAML (approche développeur, mise en place la plus rapide) :** stockez vos clés dans `.env.local` et déclarez vos volumes dans `liveux_file_manager.yaml` (Section 1).
> 2. **Via votre panneau d'administration + base de données (prêt pour vos clients / SaaS) :** créez un écran *« Paramètres de stockage cloud »* dans votre back-office où les administrateurs saisissent leurs identifiants AWS S3 / R2 / Supabase, cliquent sur **« Tester la connexion »** et enregistrent — les nouveaux volumes cloud apparaissent immédiatement dans le gestionnaire de fichiers sans accès SSH ni modification des fichiers `.env` (Section 3).

---

## Guides pas à pas par fournisseur cloud

Accédez directement au guide dédié à votre fournisseur de stockage :

| Fournisseur | Quand le choisir | Pilote (Driver) | Clé d'icône | Guide |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Vous utilisez déjà Supabase et souhaitez une mise en place rapide avec une offre gratuite généreuse | `AwsS3` | `supabase` *(auto)* | [Guide Supabase Storage →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Passage à l'échelle entreprise, rôles IAM et intégration CDN CloudFront | `AwsS3` | `aws_s3` *(auto)* | [Guide Amazon AWS S3 →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Zéro frais de bande passante sortante (egress)**, fort trafic sur les images et téléchargements | `AwsS3` | `cloudflare_r2` *(auto)* | [Guide Cloudflare R2 →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Coût au Go très bas, volumineuses archives vidéo ou sauvegardes | `AwsS3` | `backblaze_b2` *(auto)* | [Guide Backblaze B2 →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | S3 auto-hébergé sur votre propre serveur ou Docker (RGPD / souveraineté des données) | `AwsS3` | `minio` *(auto)* | [Guide MinIO & RustFS →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Applications hébergées sur Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(auto)* | [Guide Google Cloud Storage →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Personnalisé** | Serveur SFTP existant, Azure Blob ou tout adaptateur Flysystem personnalisé | `FlysystemService` | `sftp` / `cloud` | [Guide SFTP & Flysystem personnalisé →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Une fois vos volumes configurés, testez en direct l'ensemble de vos connexions cloud en une seule commande :
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. Configuration d'un stockage compatible S3 (`.env` + YAML en 3 étapes)

### Étape 1 : Installer les paquets S3

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Étape 2 : Ajouter les identifiants dans `.env.local`

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

### Étape 3 : Déclarer les volumes dans `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disque local'
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
                prefix: 'media/{Y}'       # Les variables dynamiques {Y}/{m}/{d}/{user_id} fonctionnent aussi dans les préfixes S3 !
                disable_acl: true         # Explicitement recommandé pour les buckets AWS S3 standard en mode "Bucket owner enforced"

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

### Référence complète des `driver_options` pour `AwsS3`

| Option | Valeur par défaut | Description |
| :--- | :--- | :--- |
| `bucket` | *(Obligatoire)* | Nom du bucket S3 cible. |
| `region` | `'us-east-1'` | Code de région (ex. `'eu-central-1'`, ou `'auto'` pour Cloudflare R2). |
| `endpoint` | `null` | URL de point de terminaison (endpoint) personnalisé pour les fournisseurs compatibles S3 hors AWS (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | Clés d'accès S3 (laissez vide pour utiliser automatiquement les rôles IAM EC2/ECS). |
| `token` | `null` | Jeton de session temporaire AWS STS (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | Version d'API du SDK AWS S3. |
| `prefix` | `''` | Sous-dossier racine à l'intérieur du bucket (prend en charge `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Définissez à `true` pour Supabase, MinIO, R2, B2 et les fournisseurs similaires. |
| `acl` | `null` | En-tête ACL S3 personnalisé si nécessaire (ex. `'public-read'`). |
| `disable_acl` | **`true` lorsqu'un `endpoint` personnalisé est défini ; `false` sur AWS S3 natif** | À `true`, omet l'en-tête `x-amz-acl`. Définissez explicitement `disable_acl: true` sur les buckets AWS S3 natifs configurés en mode *Bucket owner enforced*. |
| `retain_visibility` | `false` lorsqu'un `endpoint` personnalisé est défini | Détermine si la lecture/écriture des ACL est tentée lors des opérations de déplacement ou de copie. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` lorsqu'un `endpoint` personnalisé est défini | Configuré automatiquement pour assurer la compatibilité avec AWS SDK `>= 3.337` sur R2, B2, Supabase et MinIO. |
| `ca_bundle` | `null` | Chemin vers un fichier `.pem` de certificats CA SSL personnalisé (corrige l'erreur `cURL error 60` sous Windows). |

### Notes d'architecture

- **Confinement des liens symboliques sur disque local (`ConfinedLocalFilesystemAdapter`) :** chaque lecture, écriture, déplacement et suppression valide chaque segment du chemin avec `is_link()` + `realpath()` ; le parcours de répertoire impose `SKIP_LINKS`.
- **Buckets cloud avec restriction MIME & Corbeille :** si un bucket cloud refuse l'écriture des fichiers annexes `.meta.json`, `TrashManager` bascule automatiquement vers un stockage local des métadonnées de corbeille (`trash-meta.json`) dans le répertoire défini par `defaults.metadata.file`.
- **Miniatures, vidéos et PDF :** les miniatures sont récupérées une seule fois depuis le stockage cloud puis mises en cache localement dans `public/media-cache`. Les images et vidéos publiques (`private: false`) se chargent directement depuis leur URL CDN ; les PDF sont diffusés via `DownloadController?inline=1` avec une politique CSP stricte `script-src 'none'` (ou en téléchargement forcé `attachment` lorsque `allow_pdf_preview: false`).
- **CORS :** pour utiliser l'éditeur d'images Canvas intégré avec des images hébergées sur le cloud, autorisez les requêtes `GET` et `HEAD` depuis votre domaine dans la politique CORS de votre bucket.
- **Workers longue durée :** `FlysystemStorageFactory` implémente `ResetInterface` et maintient son cache d'adaptateurs borné (élagage 64 → 32) entre les requêtes.

---

## 2. Réutiliser un service Flysystem existant (`FlysystemService`)

Si votre application définit déjà un service `League\Flysystem\FilesystemOperator` (via `league/flysystem-bundle` ou une configuration personnalisée pour Azure Blob, SFTP, FTP, WebDAV, etc.), référencez directement son identifiant de service :

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Archive SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # Identifiant du service League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **Aucun `public: true` requis !** `FlysystemServiceLocatorPass` enregistre automatiquement tout service référencé via `driver_options.service` — ainsi que les services portant le tag `liveux_file_manager.storage` ou `flysystem.storage` — dans un `ServiceLocator` privé.

---

## 3. Gérer les paramètres cloud depuis un panneau d'administration (base de données) — Guide complet

Si vous souhaitez permettre à vos clients ou aux administrateurs du site de **configurer leurs propres buckets AWS S3, Cloudflare R2, Supabase, MinIO ou Backblaze B2 directement depuis le back-office**, suivez ces **5 étapes**.

### Vue d'ensemble du fonctionnement

```text
[Formulaire Admin] ──► Clé secrète chiffrée (Sodium) ──► [Base de données : cloud_storage_setting]
        │                                                                  │
        ▼                                                                  ▼
[Bouton « Tester la connexion »]                              [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                    (priority: 10 > YAML priority: 0)
        │                                                                  │
        ▼                                                                  ▼
[Invalidation du cache (DirectoryCacheManager)]               [Visible immédiatement dans le gestionnaire !]
```

### Étape 1 : Créer l'entité Doctrine (`CloudStorageSetting`)

> [!WARNING]
> **Règle d'or de sécurité :** ne stockez **JAMAIS** une `Secret Access Key` S3 en clair dans votre base de données. Enregistrez toujours une valeur chiffrée (`encryptedSecret`) comme ci-dessous.

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

    // Clé unique du volume — ex. 'panel_r2', 'panel_supabase' (alphanumérique + underscore uniquement)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Libellé affiché dans l'arborescence — ex. 'Médias Cloudflare R2'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Clé du fournisseur : 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Texte chiffré Sodium encodé en Base64 (jamais en clair !)
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

    // Profil FileManager auquel ce volume est rattaché (ex. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Accesseurs (getters et setters) standard...
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

### Étape 2 : Créer un service de chiffrement des secrets (`ext-sodium` natif en PHP 8.3)

Utilisez le chiffrement authentifié natif de PHP (`sodium_crypto_secretbox`) sans installer de dépendance supplémentaire :

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
        // Astuce : définissez une clé dédiée CLOUD_ENCRYPTION_KEY dans .env (distincte de APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Dérive une clé de chiffrement fixe de 32 octets
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
            throw new \RuntimeException('Impossible de décoder la clé chiffrée de stockage cloud.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('La clé chiffrée de stockage cloud est invalide ou corrompue.');
        }

        return $plain;
    }
}
```

### Étape 3 : Implémenter un `ConfigProviderInterface` dynamique (`DatabaseCloudConfigProvider`)

Enregistré avec **`priority: 10`**, ce fournisseur s'exécute avant le fournisseur YAML (`priority: 0`). Il charge le profil YAML de base depuis `$this->yamlProvider` et y ajoute les volumes cloud actifs configurés en base de données — ainsi **vos disques locaux YAML restent intacts tandis que les nouveaux disques cloud enregistrés apparaissent immédiatement dans l'arborescence** :

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

        // Fusionne les volumes cloud définis en base avec les volumes YAML existants :
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

### Étape 4 : Contrôleur d'administration (Enregistrement + « Tester la connexion » en direct + Invalidation du cache)

Lorsqu'un administrateur enregistre des paramètres cloud depuis le back-office, trois actions clés interviennent :

1. Si une nouvelle clé secrète est saisie, elle est chiffrée via `SecretBoxEncryptor::encrypt()` (la clé existante n'est **jamais** renvoyée dans le HTML !).
2. Un clic sur le bouton optionnel **« Tester la connexion »** appelle `FlysystemStorageFactory::createFilesystem()` et exécute un appel `listContents('', false)` en direct sur le bucket afin de vérifier les identifiants et l'endpoint avant l'enregistrement.
3. Après la sauvegarde, `DirectoryCacheManager::invalidateProfile()` et `FlysystemStorageFactory::reset()` sont appelés pour que le nouveau volume soit actif immédiatement.

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
            // plainSecret utilise mapped: false dans le formulaire ; laissez vide pour conserver le secret chiffré existant
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Si « Tester la connexion » a été cliqué, vérifier en direct avant d'enregistrer :
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Lit la première entrée pour vérifier en direct la clé, le bucket et l'endpoint :
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Connexion au stockage cloud réussie ! Vous pouvez maintenant enregistrer ces paramètres.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Échec de la connexion : ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Vide les caches et le pool d'adaptateurs -> actif immédiatement dans le gestionnaire !
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Les paramètres de stockage cloud ont été enregistrés et activés.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Étape 5 : Liste de contrôle sécurité pour les paramètres cloud gérés depuis l'administration

- **Contrôle de rôle strict :** restreignez ce contrôleur avec `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **Protection SSRF sur l'endpoint :** validez le champ `endpoint` avec Symfony Validator (`#[Assert\Url]`) afin de n'accepter que des URL `https://` et de bloquer les adresses internes de métadonnées ou de boucle locale (`169.254.169.254`, `127.0.0.1`, etc.), sauf autorisation explicite pour un MinIO local.
- **Masquage du secret :** configurez `plainSecret` dans le formulaire avec `mapped => false`, `required => false` et `type="password"` afin que laisser le champ vide conserve la clé chiffrée déjà enregistrée.
- **Sauvegarde de la clé maître :** sauvegardez `CLOUD_ENCRYPTION_KEY` (issue de `.env.local`) dans un coffre-fort de secrets sécurisé — sa perte ou sa rotation rendrait illisibles les secrets précédemment chiffrés en base.

---

## 4. Stockage des métadonnées pour les déploiements multi-serveurs (Doctrine DBAL / ORM)

Par défaut, les couleurs de dossiers, icônes, éléments épinglés et métadonnées SEO des fichiers sont enregistrés dans `var/file-manager/metadata.json` (`driver: 'json_file'`). Si vous exécutez plusieurs serveurs applicatifs derrière un répartiteur de charge (réplicas Docker/Kubernetes), passez au backend intégré `defaults.metadata.driver: 'doctrine'` — consultez le **[13 — Guide d'intégration Doctrine ORM & DBAL](13-doctrine-integration.md)** pour la configuration complète, les migrations PostgreSQL/MySQL et des exemples d'entités.

---

[← Précédent : 06 — Sécurité & Autorisations](06-security-and-authorization.md) | [Index principal](README.md) | [Suivant : 08 — Événements, Commandes, Cache & Dépannage →](08-events-commands-cache-and-troubleshooting.md)
