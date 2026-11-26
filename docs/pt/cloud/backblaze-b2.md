# Guia de Integração com Backblaze B2

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher o Backblaze B2?**  
> O Backblaze B2 é ideal quando você precisa armazenar centenas de gigabytes de vídeos, PDFs prontos para impressão ou acervos em alta resolução com o menor custo de armazenamento possível (especialmente quando combinado com egress gratuito por meio de parceiros da Bandwidth Alliance, como Cloudflare ou Bunny CDN).

O endpoint `.backblazeb2.com` é detectado automaticamente, e o ícone SVG `backblaze_b2` é exibido na barra lateral.

---

## Passo 1: Instalar os Pacotes Necessários

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passo 2: Configurar Bucket, Application Key e CORS no Backblaze

1. Acesse Backblaze B2 → **Buckets** → **Create a Bucket** (por exemplo, `app-media`).
   - Após criar o bucket, anote o **Endpoint** exibido no cartão do bucket (ex.: `s3.eu-central-003.backblazeb2.com`).
   - A **Region** é o segmento central do endpoint: `eu-central-003`.
2. Acesse **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** selecione apenas o bucket que você criou
   - **Type of Access:** `Read and Write`
   - Copie os valores gerados de **`keyID`** e **`applicationKey`**.
3. *(Opcional — para o Editor de Imagens)* Em Bucket → **CORS Rules**, escolha **Share everything in this bucket with every origin** ou adicione uma regra S3 CORS personalizada para seu domínio:
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://your-site.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## Passo 3: Configurar `.env.local` e `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=app-media
B2_REGION=eu-central-003
B2_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
B2_KEY_ID=003xxxxxxxxxxxxxxxxxxxxxx
B2_APP_KEY=K003yyyyyyyyyyyyyyyyyyyyyyyyy
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        backblaze_b2:
            label: 'Backblaze B2'
            icon: 'backblaze_b2'      # Opcional — detectado automaticamente pelo endpoint
            group: 'network'
            driver: 'AwsS3'
            # Para buckets públicos, use a Friendly URL ou seu domínio CDN (defina como null se privado):
            url_prefix: 'https://f003.backblazeb2.com/file/%env(B2_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(B2_BUCKET)%'
                region: '%env(B2_REGION)%'
                endpoint: '%env(B2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(B2_KEY_ID)%'
                secret: '%env(B2_APP_KEY)%'

    profiles:
        default:
            volumes: ['backblaze_b2']
```

> **Nota:** Como há um endpoint personalizado configurado, o `FlysystemStorageFactory` ativa automaticamente a compatibilidade de checksum do AWS SDK v3.337+ (`when_required`), `disable_acl: true` e `retain_visibility: false` para o Backblaze B2.

---

## Passo 4: Verificar a Conectividade em Tempo Real

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: Cloudflare R2](cloudflare-r2.md) | [07 — Índice de Armazenamento em Nuvem](../07-cloud-storage-and-dynamic-config.md) | [Próximo: MinIO / RustFS →](minio-rustfs.md)
