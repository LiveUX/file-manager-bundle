# 07 — Armazenamento em Nuvem (S3 / R2 / MinIO / Supabase) e Configuração Dinâmica no Painel

[← Anterior: 06 — Segurança e Autorização](06-security-and-authorization.md) | [Índice Principal](README.md) | [Próximo: 08 — Eventos, Comandos, Cache e Solução de Problemas →](08-events-commands-cache-and-troubleshooting.md)

O LiveUX FileManagerBundle é construído sobre o **League Flysystem v3**. Você pode combinar discos locais e armazenamento em nuvem lado a lado no mesmo perfil (**Multi-Volume**) e até arrastar e soltar arquivos diretamente do armazenamento local para o S3, R2 ou Supabase.

> [!TIP]
> **Duas formas de gerenciar credenciais de armazenamento em nuvem**
> 1. **Via `.env` + YAML (voltado a desenvolvedores, configuração mais rápida):** Armazene as chaves em `.env.local` e declare os volumes em `liveux_file_manager.yaml` (Seção 1).
> 2. **Via Painel Administrativo + Banco de Dados (pronto para clientes / SaaS):** Crie uma tela de *"Configurações de Armazenamento em Nuvem"* no seu painel administrativo onde os administradores inserem credenciais AWS S3 / R2 / Supabase, clicam em **"Testar Conexão"** e salvam — os novos volumes em nuvem aparecem imediatamente no gerenciador de arquivos sem acesso SSH nem edição de arquivos `.env` (Seção 3).

---

## Guias Passo a Passo por Provedor de Nuvem

Vá direto para o guia de configuração dedicado ao seu provedor de armazenamento:

| Provedor | Quando escolher | Driver | Chave de ícone | Guia |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | Você já usa Supabase e quer configuração rápida com plano gratuito generoso | `AwsS3` | `supabase` *(auto)* | [Guia Supabase Storage →](cloud/supabase-storage.md) |
| **Amazon AWS S3** | Escala corporativa, IAM Roles e integração com CDN CloudFront | `AwsS3` | `aws_s3` *(auto)* | [Guia Amazon AWS S3 →](cloud/aws-s3.md) |
| **Cloudflare R2** | **Zero custos de egress**, downloads de imagens/arquivos com alto tráfego | `AwsS3` | `cloudflare_r2` *(auto)* | [Guia Cloudflare R2 →](cloud/cloudflare-r2.md) |
| **Backblaze B2** | Custo baixíssimo por GB armazenado, grandes acervos de vídeos/backups | `AwsS3` | `backblaze_b2` *(auto)* | [Guia Backblaze B2 →](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | S3 auto-hospedado no seu próprio servidor ou Docker (LGPD / GDPR / residência de dados) | `AwsS3` | `minio` *(auto)* | [Guia MinIO e RustFS →](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | Aplicações executando no Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(auto)* | [Guia Google Cloud Storage →](cloud/google-cloud-storage.md) |
| **SFTP / Azure / Personalizado** | Servidor SFTP existente, Azure Blob ou qualquer adaptador Flysystem personalizado | `FlysystemService` | `sftp` / `cloud` | [Guia SFTP e Flysystem Personalizado →](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> Após configurar seus volumes, teste todas as conexões em nuvem em tempo real com um único comando:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. Configuração de Armazenamento Compatível com S3 (`.env` + YAML em 3 Passos)

### Passo 1: Instale os pacotes S3

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### Passo 2: Adicione as credenciais ao `.env.local`

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

### Passo 3: Declare os volumes em `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disco Local'
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
                prefix: 'media/{Y}'       # Placeholders dinâmicos {Y}/{m}/{d}/{user_id} também funcionam em prefixos S3!
                disable_acl: true         # Recomendado explicitamente para buckets AWS S3 padrão com "Bucket owner enforced"

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

### Referência Completa de `driver_options` para `AwsS3`

| Opção | Padrão | Descrição |
| :--- | :--- | :--- |
| `bucket` | *(Obrigatório)* | Nome do bucket S3 de destino. |
| `region` | `'us-east-1'` | Código da região (ex.: `'eu-central-1'`, ou `'auto'` para Cloudflare R2). |
| `endpoint` | `null` | URL de endpoint personalizada para provedores compatíveis com S3 fora da AWS (R2, Supabase, B2, MinIO, GCS). |
| `key` / `secret` | `null` | Credenciais de acesso S3 (deixe em branco para usar automaticamente IAM Roles do EC2/ECS). |
| `token` | `null` | Token temporário de sessão AWS STS (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | Versão da API do AWS S3 SDK. |
| `prefix` | `''` | Subpasta raiz dentro do bucket (suporta `{user_id}`, `{Y}`, `{m}`, `{d}`). |
| `use_path_style_endpoint` | `false` | Defina como `true` para Supabase, MinIO, R2, B2 e provedores similares. |
| `acl` | `null` | Cabeçalho ACL S3 personalizado, se necessário (ex.: `'public-read'`). |
| `disable_acl` | **`true` quando um `endpoint` personalizado é definido; `false` no AWS S3 nativo** | Quando `true`, omite o cabeçalho `x-amz-acl`. Defina `disable_acl: true` explicitamente em buckets AWS S3 nativos com *Bucket owner enforced* ativado. |
| `retain_visibility` | `false` quando um `endpoint` personalizado é definido | Controla se leituras/gravações de ACL são tentadas durante operações de movimentação/cópia. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` quando um `endpoint` personalizado é definido | Configurado automaticamente para compatibilidade com AWS SDK `>= 3.337` no R2, B2, Supabase e MinIO. |
| `ca_bundle` | `null` | Caminho para um pacote `.pem` personalizado de certificados CA SSL (resolve o erro `cURL error 60` no Windows). |

### Notas de Arquitetura

- **Confinamento de Symlinks em Disco Local (`ConfinedLocalFilesystemAdapter`):** Toda leitura, escrita, movimentação e exclusão valida cada segmento do caminho com `is_link()` + `realpath()`; listagens de diretório aplicam `SKIP_LINKS`.
- **Buckets em Nuvem com Restrição de MIME e Lixeira:** Se um bucket em nuvem rejeitar a gravação de arquivos auxiliares `.meta.json`, o `TrashManager` automaticamente recorre ao armazenamento local dos metadados da lixeira (`trash-meta.json`) dentro do diretório configurado em `defaults.metadata.file`.
- **Miniaturas, Vídeos e PDFs:** As miniaturas são baixadas uma única vez da nuvem e armazenadas em cache localmente em `public/media-cache`. Imagens e vídeos públicos (`private: false`) carregam diretamente de sua URL de CDN; PDFs são transmitidos via `DownloadController?inline=1` com uma política CSP rígida `script-src 'none'` (ou `attachment` forçado quando `allow_pdf_preview: false`).
- **CORS:** Para usar o Editor de Imagens Canvas integrado com imagens hospedadas na nuvem, libere requisições `GET` e `HEAD` do seu domínio na política CORS do bucket.
- **Workers de Longa Duração:** `FlysystemStorageFactory` implementa `ResetInterface` e mantém o cache de adaptadores limitado (reduzindo de 64 → 32) entre requisições.

---

## 2. Reutilizando um Serviço Flysystem Existente (`FlysystemService`)

Se sua aplicação já define um serviço `League\Flysystem\FilesystemOperator` (via `league/flysystem-bundle` ou configuração própria para Azure Blob, SFTP, FTP, WebDAV etc.), referencie diretamente o ID desse serviço:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Arquivo SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # ID do serviço League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **Não é necessário usar `public: true`!** O `FlysystemServiceLocatorPass` registra automaticamente todos os serviços referenciados em `driver_options.service` — bem como serviços marcados com `liveux_file_manager.storage` ou `flysystem.storage` — dentro de um `ServiceLocator` privado.

---

## 3. Gerenciando Configurações de Nuvem pelo Painel Administrativo (Baseado em Banco de Dados) — Guia Completo

Se você quer que clientes ou administradores do site **configurem seus próprios buckets AWS S3, Cloudflare R2, Supabase, MinIO ou Backblaze B2 diretamente pelo painel administrativo**, siga estes **5 passos**.

### Como funciona (Visão Geral)

```text
[Formulário do Painel] ──► Segredo criptografado (Sodium) ──► [Banco: cloud_storage_setting]
        │                                                               │
        ▼                                                               ▼
[Botão "Testar Conexão"]                                   [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                                 (priority: 10 > YAML priority: 0)
        │                                                               │
        ▼                                                               ▼
[Invalidar Cache (DirectoryCacheManager)]                  [Aparece imediatamente no Gerenciador!]
```

### Passo 1: Crie a entidade do banco de dados (`CloudStorageSetting`)

> [!WARNING]
> **Regra de Ouro de Segurança:** **NUNCA** armazene uma `Secret Access Key` S3 em texto puro no banco de dados. Sempre armazene um payload criptografado (`encryptedSecret`), como mostrado abaixo.

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

    // Chave única do volume — ex.: 'panel_r2', 'panel_supabase' (apenas alfanuméricos + underscore)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // Rótulo exibido na barra lateral — ex.: 'Mídia Cloudflare R2'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // Chave do provedor: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
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

    // Texto cifrado Sodium em Base64 (nunca em texto puro!)
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

    // Perfil do FileManager ao qual este volume é anexado (ex.: 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // Getters e setters padrão...
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

### Passo 2: Crie um serviço de criptografia de segredos (`ext-sodium` nativo do PHP 8.3)

Use a criptografia autenticada nativa do PHP (`sodium_crypto_secretbox`) sem precisar instalar nenhum pacote extra:

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
        // Dica: defina uma variável dedicada CLOUD_ENCRYPTION_KEY no .env (separada de APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // Deriva uma chave de criptografia fixa de 32 bytes
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
            throw new \RuntimeException('Falha ao decodificar a chave criptografada de armazenamento em nuvem.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('A chave criptografada de armazenamento em nuvem é inválida ou está corrompida.');
        }

        return $plain;
    }
}
```

### Passo 3: Implemente um `ConfigProviderInterface` dinâmico (`DatabaseCloudConfigProvider`)

Registrado com **`priority: 10`**, este provedor executa antes do provedor YAML (`priority: 0`). Ele carrega o perfil base do YAML a partir de `$this->yamlProvider` e acrescenta os volumes em nuvem ativos configurados no banco — assim, **seus discos locais em YAML permanecem intactos enquanto os novos discos em nuvem salvos aparecem imediatamente na barra lateral**:

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

        // Mescla volumes em nuvem definidos no banco junto aos volumes YAML existentes:
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

### Passo 4: Controller Administrativo (Salvar + "Testar Conexão" ao Vivo + Invalidação de Cache)

Quando um administrador salva configurações de nuvem pelo painel administrativo, três ações críticas acontecem:

1. Se um novo segredo foi informado, ele é criptografado via `SecretBoxEncryptor::encrypt()` (o segredo existente **nunca** é reenviado no HTML!).
2. Clicar no botão opcional **"Testar Conexão"** chama `FlysystemStorageFactory::createFilesystem()` e executa uma verificação ao vivo com `listContents('', false)` no bucket para validar credenciais e endpoint antes de persistir.
3. Após salvar, `DirectoryCacheManager::invalidateProfile()` e `FlysystemStorageFactory::reset()` são chamados para que o novo volume entre em vigor imediatamente.

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
            // plainSecret tem mapped: false no formulário; deixe vazio para manter o segredo criptografado atual
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // Se "Testar Conexão" foi clicado, valida ao vivo antes de salvar:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // Lê a primeira entrada para validar chave, bucket e endpoint ao vivo:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'Conexão com a nuvem bem-sucedida! Você já pode salvar estas configurações.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Falha na conexão: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // Limpa caches e pool de adaptadores -> ativo imediatamente no gerenciador de arquivos!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'Configurações de armazenamento em nuvem salvas e ativadas.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### Passo 5: Checklist de Segurança para Configurações de Nuvem Gerenciadas no Painel

- **Proteção Estrita de Papel:** Restrinja este controller a `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **Proteção SSRF no Endpoint:** Valide o campo `endpoint` com o Symfony Validator (`#[Assert\Url]`) para aceitar apenas URLs `https://` e bloquear IPs internos de metadados/loopback (`169.254.169.254`, `127.0.0.1` etc.), exceto se você permitir explicitamente um MinIO local.
- **Mascaramento do Segredo:** Configure `plainSecret` no formulário com `mapped => false`, `required => false` e `type="password"` para que deixá-lo em branco preserve o segredo criptografado já armazenado.
- **Backup da Chave Mestra:** Faça backup de `CLOUD_ENCRYPTION_KEY` do `.env.local` em um cofre de segredos seguro — rotacioná-la ou perdê-la torna ilegíveis os segredos previamente criptografados no banco de dados.

---

## 4. Armazenamento de Metadados para Implantações Multi-Servidor (Doctrine DBAL / ORM)

Por padrão, cores de pastas, ícones, itens fixados e metadados SEO de arquivos são salvos em `var/file-manager/metadata.json` (`driver: 'json_file'`). Se você executa múltiplos servidores de aplicação atrás de um load balancer (réplicas Docker/Kubernetes), alterne para o backend integrado `defaults.metadata.driver: 'doctrine'` — consulte **[13 — Guia de Integração Doctrine ORM e DBAL](13-doctrine-integration.md)** para a configuração completa, migrations PostgreSQL/MySQL e exemplos de Entity.

---

[← Anterior: 06 — Segurança e Autorização](06-security-and-authorization.md) | [Índice Principal](README.md) | [Próximo: 08 — Eventos, Comandos, Cache e Solução de Problemas →](08-events-commands-cache-and-troubleshooting.md)
