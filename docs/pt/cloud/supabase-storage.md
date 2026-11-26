# Guia de Integração com Supabase Storage (S3)

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher o Supabase Storage?**  
> Se você já possui um projeto no Supabase — ou quer um plano gratuito generoso para gerenciar tanto assets públicos de CDN quanto buckets privados de documentos em um único painel — o Supabase Storage é a escolha compatível com S3 mais prática.

Como o **Supabase Storage** oferece suporte ao protocolo compatível com S3, ele funciona imediatamente com o LiveUX FileManagerBundle em uma arquitetura híbrida de **Armazenamento Local + Supabase Cloud**, sem escrever uma única linha de código personalizado. O bundle detecta automaticamente o provedor `supabase` a partir da URL do endpoint e exibe o ícone SVG do Supabase na barra lateral.

---

## Passo 1: Instalar os Pacotes Necessários

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passo 2: Gerar Chaves de Acesso S3 no Painel do Supabase

> [!WARNING]
> **Importante:** As chaves de API REST/Auth do Supabase — `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) e `SUPABASE_SECRET_KEY` (`sb_secret_...`) — **não** funcionam com o protocolo S3. Você deve usar as credenciais de 32 e 64 caracteres geradas em **S3 Access Keys** abaixo.

1. Abra seu projeto no painel do Supabase → **Storage** → **New bucket** e crie um bucket (por exemplo, `media` ou `prints`).
   - Marque **Public bucket** para imagens e assets acessíveis publicamente via CDN.
   - Deixe **Public bucket** desmarcado (privado) para documentos de clientes ou faturas.
2. Acesse **Project Settings** → **Storage** (ou **Storage** → **Settings**).
3. Copie estes dois valores do cartão **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** ex.: `eu-central-1` ou `eu-west-1`
4. Em **S3 Access Keys**, clique em **New access key**, informe uma descrição e copie os valores gerados:
   - **Access Key ID** (32 caracteres)
   - **Secret Access Key** (64 caracteres — exibida apenas uma vez!)

---

## Passo 3: Adicionar Variáveis de Ambiente ao `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=seu_access_key_id_de_32_caracteres
SUPABASE_S3_SECRET=sua_secret_access_key_de_64_caracteres
```

---

## Passo 4: Configurar o `liveux_file_manager.yaml`

### Cenário A — Bucket Público

Imagens, vídeos e arquivos públicos são servidos diretamente pela URL da CDN do Supabase (aliviando a carga do seu servidor PHP), enquanto as miniaturas são geradas uma única vez no servidor e servidas a partir do cache local (`public/media-cache`):

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Armazenamento Local'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Opcional — detectado automaticamente pelo endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # Cache de 60s para conteúdo em nuvem que pode mudar externamente
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # ou 'network_first' / 'configured'
```

### Cenário B — Bucket Privado com Isolamento por Usuário

Para faturas de clientes ou PDFs confidenciais acessíveis apenas a usuários autenticados e autorizados:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Cofre de Documentos Supabase'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Sem URL pública, pois este bucket é privado
            private: true             # Streaming seguro via DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Isola cada usuário em sua própria subpasta sem colisões
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## Passo 5: Verificar a Conectividade em Tempo Real com Um Comando

```bash
php bin/console file-manager:doctor --check-remote
```

Exemplo de saída em caso de sucesso:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Nota:** Volumes dinâmicos por usuário que utilizam placeholders como `prefix: 'users/{user_id}'` (como no Cenário B) são ignorados com segurança com status `INFO` pelo comando `file-manager:doctor --check-remote`, pois não há sessão de usuário ativa no ambiente CLI.

---

## Dicas e Solução de Problemas Específicos do Supabase

### 1. Exclusão de Arquivos em Bucket com Restrição de MIME (`InvalidMimeType: application/json`)
Se você configurou **Allowed MIME types** no seu bucket do Supabase para permitir apenas tipos como `application/pdf` ou `image/*`, o Supabase retornará `InvalidMimeType` quando o recurso de lixeira tentar gravar `.trash/<id>/.meta.json`.
- **Fallback automático:** O `TrashManager` detecta isso automaticamente — o arquivo em si é movido para `.trash/<id>/...` no bucket em nuvem, enquanto os metadados `.meta.json` são gravados em um arquivo local de fallback no seu servidor (`trash-meta.json` ou `<name>.trash-meta.json` dentro do diretório `defaults.metadata.file`). A restauração e a exclusão permanente continuam funcionando perfeitamente.

### 2. Erros de Checksum e ACL no AWS SDK v3.337+
Para volumes S3 configurados com um `endpoint` personalizado como o Supabase, o `FlysystemStorageFactory` aplica automaticamente:
- `request_checksum_calculation: 'when_required'` e `response_checksum_validation: 'when_required'`
- `retain_visibility: false` e remoção dos cabeçalhos `x-amz-acl` (`disable_acl: true`)

Nenhuma configuração adicional é necessária.

### 3. Erro de Certificado SSL `cURL error 60` no Windows / WAMP
Se você encontrar `cURL error 60: unable to get local issuer certificate` no seu ambiente local de desenvolvimento:
- Baixe [cacert.pem](https://curl.se/ca/cacert.pem) e referencie-o no `php.ini` (tanto no `php.ini` do Apache quanto no do CLI):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Como alternativa, configure-o apenas para um volume específico via `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. Arquivos Excluídos no Painel do Supabase Continuam Aparecendo na Listagem
- Clique no botão **Atualizar (`↻`)** na barra de ferramentas (ou pressione `R`) para ignorar o cache imediatamente via `?refresh=1`.
- Se os arquivos no bucket forem modificados externamente com frequência, defina `cache_ttl: 30` (ou `cache_ttl: 0` para desativar o cache) no volume.

---

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Próximo: Guia AWS S3 →](aws-s3.md)
