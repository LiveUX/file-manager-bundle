# Guia de Integração com Google Cloud Storage (GCS)

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher o Google Cloud Storage?**  
> O GCS é a escolha natural se sua aplicação já roda no Google Cloud (GKE, Cloud Run, Compute Engine) e você deseja conectar-se diretamente pela camada de interoperabilidade S3 (HMAC), sem adicionar outro SDK de provedor ao projeto.

O **Google Cloud Storage (GCS)** funciona diretamente com o driver `AwsS3` por meio de sua camada nativa de compatibilidade S3 (*XML API / Interoperability HMAC*) — embora você também possa integrar `league/flysystem-google-cloud-storage` via `FlysystemService`, se preferir. O endpoint `storage.googleapis.com` é detectado automaticamente e o ícone SVG `gcs` é exibido na barra lateral.

---

## Passo 1: Instalar os Pacotes Necessários

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passo 2: Criar uma Chave HMAC no Google Cloud Console

1. Abra o Google Cloud Console → **Cloud Storage** → **Buckets** e crie um novo bucket (por exemplo, `my-symfony-media`).
2. Navegue até **Cloud Storage** → **Settings** → **Interoperability**.
3. Em **Service account HMAC**, clique em **Create a key for a service account** (certifique-se de que a conta de serviço tenha o papel `Storage Object Admin`).
4. Copie os valores gerados de **Access key** (`GOOG1E...`) e **Secret**.
5. *(Opcional — para o Editor de Imagens)* Aplique uma política CORS ao bucket usando um arquivo `cors.json`:
   ```json
   [
       {
           "origin": ["https://your-site.com"],
           "method": ["GET", "HEAD"],
           "responseHeader": ["Content-Type", "ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```
   ```bash
   gcloud storage buckets update gs://my-symfony-media --cors-file=cors.json
   ```

---

## Passo 3: Configurar `.env.local` e `liveux_file_manager.yaml`

```dotenv
# .env.local
GCS_BUCKET=my-symfony-media
GCS_HMAC_KEY=GOOG1E...
GCS_HMAC_SECRET=...
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        gcs_media:
            label: 'Google Cloud Storage'
            icon: 'gcs'               # Opcional — detectado automaticamente pelo endpoint
            group: 'network'
            driver: 'AwsS3'
            # Para buckets públicos: https://storage.googleapis.com/<bucket> (defina como null se privado)
            url_prefix: 'https://storage.googleapis.com/%env(GCS_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(GCS_BUCKET)%'
                region: 'auto'
                endpoint: 'https://storage.googleapis.com'
                use_path_style_endpoint: true
                key: '%env(GCS_HMAC_KEY)%'
                secret: '%env(GCS_HMAC_SECRET)%'

    profiles:
        default:
            volumes: ['gcs_media']
```

> **Compatibilidade Automática com GCS:** Como `endpoint: 'https://storage.googleapis.com'` está configurado, o `FlysystemStorageFactory` aplica automaticamente `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` e `retain_visibility: false`.

---

## Passo 4: Verificar a Conectividade em Tempo Real

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: MinIO / RustFS](minio-rustfs.md) | [07 — Índice de Armazenamento em Nuvem](../07-cloud-storage-and-dynamic-config.md) | [Próximo: SFTP e Serviços Flysystem Personalizados →](sftp-and-custom-flysystem.md)
