# Guia de Integração com MinIO e RustFS (Self-Hosted / Docker)

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher MinIO / RustFS?**  
> O armazenamento S3 self-hosted é ideal para servidores corporativos on-premise com requisitos rigorosos de residência de dados ou conformidade (LGPD, GDPR, HIPAA etc.) nos quais os arquivos nunca podem sair da sua infraestrutura, ou para testar o comportamento real de S3 localmente sem custos usando Docker.

---

## Passo 1: Instalar os Pacotes Necessários

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passo 2: Configuração Rápida com Docker Compose (Desenvolvimento / Self-Hosted)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # Endpoint da API S3
            - "9001:9001"   # Console Web de Gerenciamento
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # Origem CORS para o Editor de Imagens em Canvas integrado em buckets públicos:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Inicie o contêiner e abra `http://localhost:9001` para criar um bucket chamado `uploads` (defina **Access Policy** como `public` para leitura pública ou mantenha como `private` para acervos restritos).

---

## Passo 3: Configurar `.env.local` e `liveux_file_manager.yaml`

```dotenv
# .env.local
MINIO_ENDPOINT=http://localhost:9000
MINIO_BUCKET=uploads
MINIO_KEY=minioadmin
MINIO_SECRET=minioadmin-change-me
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        minio_storage:
            label: 'MinIO Storage'
            icon: 'minio'             # Detectado automaticamente se o endpoint contiver 'minio' ou ':9000'; defina explicitamente para portas customizadas do RustFS
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Defina como null se private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Padrão convencional para MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Obrigatório para servidores S3 self-hosted
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Caso utilize um certificado HTTPS autoassinado:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Dica (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Você pode se conectar a qualquer serviço compatível com S3 usando exatamente este modelo `AwsS3` — basta atualizar `endpoint`, `region` e `use_path_style_endpoint`. Como um endpoint personalizado está definido, o `FlysystemStorageFactory` aplica automaticamente a validação de checksum `when_required` e `disable_acl: true`.

---

## Passo 4: Verificar a Conectividade em Tempo Real

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: Backblaze B2](backblaze-b2.md) | [07 — Índice de Armazenamento em Nuvem](../07-cloud-storage-and-dynamic-config.md) | [Próximo: Google Cloud Storage (GCS) →](google-cloud-storage.md)
