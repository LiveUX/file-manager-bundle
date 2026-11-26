# 07 — Almacenamiento en la nube (S3 / R2 / MinIO / Supabase) y configuración dinámica desde el panel de administración

[← Anterior: 06 — Seguridad y autorización](06-security-and-authorization.md) | [Índice principal](README.md) | [Siguiente: 08 — Eventos, comandos, caché y resolución de problemas →](08-events-commands-cache-and-troubleshooting.md)

LiveUX FileManagerBundle está construido sobre **League Flysystem v3**. Puedes combinar discos locales y almacenamiento en la nube lado a lado dentro de un mismo perfil (**Multi-Volume**), e incluso arrastrar y soltar archivos directamente desde el disco local hacia S3, R2 o Supabase.

> [!TIP]
> **Dos formas de gestionar las credenciales de almacenamiento en la nube**
> 1. **Mediante `.env` + YAML (enfoque orientado a desarrolladores, configuración más rápida):** guarda las claves en `.env.local` y declara los volúmenes en `liveux_file_manager.yaml` (Sección 1).
> 2. **Mediante tu panel de administración + base de datos (listo para clientes / SaaS):** crea una pantalla de *"Configuración de almacenamiento en la nube"* en tu panel de administración donde los administradores puedan introducir sus credenciales de AWS S3 / R2 / Supabase, hacer clic en **"Probar conexión"** y guardar; los nuevos volúmenes en la nube aparecerán de inmediato en el gestor de archivos sin necesidad de acceso SSH ni de editar archivos `.env` (Sección 3).

---

## Guías paso a paso por proveedor de nube

Ve directamente a la guía de configuración dedicada para tu proveedor de almacenamiento:

| Proveedor | Cuándo elegirlo | Driver | Clave de icono | Guía |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Ya utilizas Supabase y buscas configuración rápida y un plan gratuito generoso | `AwsS3` | `supabase` *(automático)* | [Guía de Supabase Storage →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Escala empresarial, roles IAM e integración con CloudFront CDN | `AwsS3` | `aws_s3` *(automático)* | [Guía de Amazon AWS S3 →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Sin costes de transferencia saliente (egress)**, descargas de imágenes/archivos con alto tráfico | `AwsS3` | `cloudflare_r2` *(automático)* | [Guía de Cloudflare R2 →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Coste ultrabajo por GB almacenado, grandes archivos de video o copias de seguridad | `AwsS3` | `backblaze_b2` *(automático)* | [Guía de Backblaze B2 →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | S3 autoalojado en tu propio servidor o Docker (RGPD / soberanía de datos) | `AwsS3` | `minio` *(automático)* | [Guía de MinIO y RustFS →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Aplicaciones desplegadas en Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(automático)* | [Guía de Google Cloud Storage →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Personalizado** | Servidor SFTP existente, Azure Blob o cualquier adaptador personalizado de Flysystem | `FlysystemService` | `sftp` / `cloud` | [Guía de SFTP y Flysystem personalizado →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Después de configurar tus volúmenes, comprueba en vivo cada conexión en la nube con un solo comando:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. Configuración de almacenamiento en la nube compatible con S3 (`.env` + YAML en 3 pasos)

### Paso 1: Instala los paquetes de S3

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Paso 2: Añade las credenciales a `.env.local`

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

### Paso 3: Declara los volúmenes en `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disco local'
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
                prefix: 'media/{Y}'       # ¡Los marcadores dinámicos {Y}/{m}/{d}/{user_id} también funcionan en prefijos S3!
                disable_acl: true         # Explícitamente recomendado para buckets estándar de AWS S3 con "Bucket owner enforced"

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

### Referencia completa de `driver_options` para `AwsS3`

| Opción | Valor por defecto | Descripción |
| :--- | :--- | :--- |
| `bucket` | *(Obligatorio)* | Nombre del bucket S3 de destino. |
| `region` | `'us-east-1'` | Código de región (p. ej., `'eu-central-1'`, o `'auto'` para Cloudflare R2). |
| `endpoint` | `null` | URL de endpoint personalizada para proveedores compatibles con S3 distintos de AWS (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | Credenciales de acceso S3 (déjalas vacías para usar automáticamente roles IAM de EC2/ECS). |
| `token` | `null` | Token temporal de sesión de AWS STS (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | Versión de la API del SDK de AWS S3. |
| `prefix` | `''` | Subcarpeta raíz dentro del bucket (admite `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Establécelo en `true` para Supabase, MinIO, R2, B2 y proveedores similares. |
| `acl` | `null` | Cabecera ACL personalizada de S3 si fuera necesaria (p. ej., `'public-read'`). |
| `disable_acl` | **`true` cuando se define un `endpoint` personalizado; `false` en AWS S3 nativo** | Si es `true`, omite la cabecera `x-amz-acl`. Configura explícitamente `disable_acl: true` en buckets nativos de AWS S3 con *Bucket owner enforced* activado. |
| `retain_visibility` | `false` cuando se define un `endpoint` personalizado | Controla si se intentan leer/escribir ACL durante operaciones de mover o copiar. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` cuando se define un `endpoint` personalizado | Se configuran automáticamente para garantizar compatibilidad con AWS SDK `>= 3.337` en R2, B2, Supabase y MinIO. |
| `ca_bundle` | `null` | Ruta a un archivo `.pem` personalizado con certificados CA de SSL (soluciona `cURL error 60` en Windows). |

### Notas de arquitectura

- **Confinamiento de enlaces simbólicos en disco local (`ConfinedLocalFilesystemAdapter`):** cada lectura, escritura, movimiento y eliminación valida cada segmento de la ruta con `is_link()` + `realpath()`; los listados de directorios aplican `SKIP_LINKS`.
- **Buckets en la nube con restricción de tipos MIME y papelera:** si un bucket en la nube rechaza la escritura de archivos auxiliares `.meta.json`, `TrashManager` recurre automáticamente a guardar los metadatos de la papelera en local (`trash-meta.json`) dentro del directorio definido en `defaults.metadata.file`.
- **Miniaturas, video y PDF:** las miniaturas se obtienen una sola vez desde la nube y se almacenan en caché localmente en `public/media-cache`. Las imágenes y videos públicos (`private: false`) se cargan directamente desde su URL de CDN; los PDF se transmiten mediante `DownloadController?inline=1` con una política CSP estricta `script-src 'none'` (o descarga forzada como `attachment` cuando `allow_pdf_preview: false`).
- **CORS:** para utilizar el editor de imágenes integrado basado en Canvas con imágenes alojadas en la nube, permite solicitudes `GET` y `HEAD` desde tu dominio en la política CORS del bucket.
- **Workers de larga duración:** `FlysystemStorageFactory` implementa `ResetInterface` y mantiene acotada su caché de adaptadores (reduciendo de 64 → 32) entre solicitudes.

---

## 2. Reutilizar un servicio Flysystem existente (`FlysystemService`)

Si tu aplicación ya define un servicio `League\Flysystem\FilesystemOperator` (mediante `league/flysystem-bundle` o configuración personalizada para Azure Blob, SFTP, FTP, WebDAV, etc.), referencia directamente su identificador de servicio:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Archivo SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # ID del servicio League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **No es necesario usar `public: true`.** `FlysystemServiceLocatorPass` registra automáticamente cada servicio referenciado mediante `driver_options.service` —así como los servicios etiquetados con `liveux_file_manager.storage` o `flysystem.storage`— dentro de un `ServiceLocator` privado.

---

## 3. Gestionar la configuración de nube desde un panel de administración (basado en base de datos) — Guía completa

Si deseas que tus clientes o administradores del sitio puedan **configurar sus propios buckets de AWS S3, Cloudflare R2, Supabase, MinIO o Backblaze B2 directamente desde tu panel de administración**, sigue estos **5 pasos**.

### Cómo funciona (visión general)

```text
[Formulario del panel admin] ──► Secreto cifrado (Sodium) ──► [Base de datos: cloud_storage_setting]
        │                                                                    │
        ▼                                                                    ▼
[Botón "Probar conexión"]                                     [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                    (priority: 10 > YAML priority: 0)
        │                                                                    │
        ▼                                                                    ▼
[Invalidar caché (DirectoryCacheManager)]                     [¡Aparece de inmediato en el Gestor de archivos!]
```

### Paso 1: Crea la entidad de base de datos (`CloudStorageSetting`)

> [!WARNING]
> **Regla de oro de seguridad:** **NUNCA** guardes una `Secret Access Key` de S3 en texto plano en la base de datos. Almacena siempre una carga cifrada (`encryptedSecret`) como se muestra a continuación.

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

    // Clave única del volumen — p. ej. 'panel_r2', 'panel_supabase' (solo caracteres alfanuméricos y guion bajo)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Etiqueta visible en la barra lateral — p. ej. 'Cloudflare R2 Media'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Clave de proveedor: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Texto cifrado con Sodium codificado en Base64 (¡nunca en texto plano!)
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

    // Perfil de FileManager al que se asocia este volumen (p. ej. 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Getters y setters estándar...
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

### Paso 2: Crea un servicio de cifrado de secretos (`ext-sodium` integrado en PHP 8.3)

Utiliza el cifrado autenticado nativo de PHP (`sodium_crypto_secretbox`) sin instalar paquetes adicionales:

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
        // Consejo: define una clave dedicada CLOUD_ENCRYPTION_KEY en .env (independiente de APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Deriva una clave de cifrado fija de 32 bytes
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
            throw new \RuntimeException('No se pudo decodificar la clave cifrada de almacenamiento en la nube.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('La clave cifrada de almacenamiento en la nube es inválida o está dañada.');
        }

        return $plain;
    }
}
```

### Paso 3: Implementa un `ConfigProviderInterface` dinámico (`DatabaseCloudConfigProvider`)

Al registrarse con **`priority: 10`**, este proveedor se ejecuta antes que el proveedor YAML (`priority: 0`). Carga el perfil YAML base desde `$this->yamlProvider` y añade los volúmenes en la nube activos almacenados en base de datos, de modo que **tus discos locales en YAML permanecen intactos mientras los nuevos discos en la nube aparecen al instante en la barra lateral**:

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

        // Combina los volúmenes en la nube de la base de datos con los volúmenes YAML existentes:
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

### Paso 4: Controlador de administración (guardar + "Probar conexión" en vivo + invalidación de caché)

Cuando un administrador guarda la configuración de almacenamiento en la nube desde tu panel de administración, ocurren tres acciones esenciales:

1. Si se introdujo un nuevo secreto, se cifra mediante `SecretBoxEncryptor::encrypt()` (el secreto existente **nunca** se devuelve ni se imprime en el HTML).
2. Al hacer clic en el botón opcional **"Probar conexión"**, se invoca `FlysystemStorageFactory::createFilesystem()` y se ejecuta una comprobación real `listContents('', false)` contra el bucket para validar credenciales y endpoint antes de guardar.
3. Después de guardar, se ejecutan `DirectoryCacheManager::invalidateProfile()` y `FlysystemStorageFactory::reset()` para que el nuevo volumen quede activo al instante.

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
            // plainSecret tiene mapped: false en el formulario; dejarlo vacío conserva el secreto cifrado actual
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Si se pulsó "Probar conexión", verifica en vivo antes de guardar:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Lee la primera entrada para comprobar clave, bucket y endpoint en tiempo real:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', '¡Conexión en la nube exitosa! Ya puedes guardar esta configuración.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Error de conexión: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Limpia cachés y pool de adaptadores -> ¡activo de inmediato en el gestor de archivos!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'La configuración de almacenamiento en la nube se ha guardado y activado.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Paso 5: Lista de verificación de seguridad para configuraciones gestionadas desde el panel

- **Control estricto de roles:** restringe este controlador con `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **Protección SSRF en endpoints:** valida el campo `endpoint` con Symfony Validator (`#[Assert\Url]`) para aceptar únicamente URLs `https://` y bloquear IPs internas de metadatos o loopback (`169.254.169.254`, `127.0.0.1`, etc.), salvo que habilites explícitamente un servidor MinIO local.
- **Ocultación de secretos:** configura `plainSecret` en el formulario con `mapped => false`, `required => false` y `type="password"` para que dejar el campo vacío conserve el secreto cifrado almacenado.
- **Copia de seguridad de la clave maestra:** guarda una copia segura de `CLOUD_ENCRYPTION_KEY` de `.env.local` en un gestor de secretos; rotarla o perderla hará ilegibles los secretos cifrados previamente en la base de datos.

---

## 4. Almacenamiento de metadatos en despliegues multiservidor (Doctrine DBAL / ORM)

Por defecto, los colores de carpeta, iconos, elementos fijados y metadatos SEO de archivos se guardan en `var/file-manager/metadata.json` (`driver: 'json_file'`). Si ejecutas varios servidores de aplicación detrás de un balanceador de carga (réplicas en Docker/Kubernetes), cambia al backend integrado `defaults.metadata.driver: 'doctrine'`; consulta la **[13 — Guía de integración con Doctrine ORM y DBAL](13-doctrine-integration.md)** para ver la configuración completa, las migraciones para PostgreSQL/MySQL y ejemplos de entidades.

---

[← Anterior: 06 — Seguridad y autorización](06-security-and-authorization.md) | [Índice principal](README.md) | [Siguiente: 08 — Eventos, comandos, caché y resolución de problemas →](08-events-commands-cache-and-troubleshooting.md)
