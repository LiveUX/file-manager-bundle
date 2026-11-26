# Guia de Integração com Cloudflare R2

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher o Cloudflare R2?**  
> Se você serve alto volume de downloads de imagens ou vídeos e deseja **custo zero de egress (tráfego de saída)** combinado com a velocidade nativa da CDN da Cloudflare, o Cloudflare R2 é uma das soluções compatíveis com S3 com melhor custo-benefício para projetos intensivos em mídia.

O LiveUX FileManagerBundle detecta automaticamente o Cloudflare R2 pelo endpoint `.r2.cloudflarestorage.com` e exibe o ícone SVG `cloudflare_r2` na barra lateral.

---

## Passo 1: Instalar os Pacotes Necessários

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passo 2: Criar um Bucket e um Token de API no Painel da Cloudflare

1. Acesse o painel da Cloudflare → **R2 Object Storage** → **Create bucket** e crie um bucket (por exemplo, `cdn-assets`).
2. Para acesso público, conecte seu domínio personalizado em Bucket → **Settings** → **Public access** (ex.: `assets.your-site.com`). Para um bucket privado de documentos, mantenha o acesso público desativado.
3. Na página de visão geral do R2, abra **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** selecione apenas o bucket de destino
   - Copie os valores gerados de **Access Key ID**, **Secret Access Key** e o **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Opcional — para o Editor de Imagens)* Adicione uma regra CORS em Bucket → **Settings** → **CORS Policy**:
   ```json
   [
       {
           "AllowedOrigins": ["https://your-site.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## Passo 3: Configurar `.env.local` e `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=seu_r2_access_key_id
R2_SECRET=sua_r2_secret_access_key
```

### Cenário A — Bucket Público de CDN e Cenário B — Cofre Privado de Documentos

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Opcional — detectado automaticamente pelo endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Sempre use 'auto' para Cloudflare R2
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'Arquivo Privado R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Sem URL pública, pois este volume é privado
            private: true             # Streaming autorizado via Voter + DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                prefix: 'private-docs'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['cloudflare_r2_cdn', 'cloudflare_r2_private']
```

> **Compatibilidade de Checksum e ACL no AWS SDK v3.337+:** Em `aws/aws-sdk-php >= 3.337`, os novos cabeçalhos padrão de checksum CRC32 podem causar erros `XAmzContentSHA256Mismatch` ou `NotImplemented` no Cloudflare R2. Sempre que o `FlysystemStorageFactory` detecta um `endpoint` personalizado, ele **aplica automaticamente** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` e `retain_visibility: false` — nenhuma configuração extra é necessária.

---

## Passo 4: Verificar a Conectividade em Tempo Real

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: AWS S3](aws-s3.md) | [07 — Índice de Armazenamento em Nuvem](../07-cloud-storage-and-dynamic-config.md) | [Próximo: Backblaze B2 →](backblaze-b2.md)
