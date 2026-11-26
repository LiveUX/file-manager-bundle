# Guía de integración con Cloudflare R2

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir Cloudflare R2?**  
> Si sirves un alto volumen de descargas de imágenes o vídeos y quieres **cero costes de transferencia saliente (egress)** junto con la velocidad integrada de Cloudflare CDN, Cloudflare R2 es una de las soluciones compatibles con S3 más rentables para proyectos con gran carga multimedia.

LiveUX FileManagerBundle detecta automáticamente Cloudflare R2 a partir del endpoint `.r2.cloudflarestorage.com` y muestra el icono SVG `cloudflare_r2` en la barra lateral.

---

## Paso 1: Instalar los paquetes necesarios

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Paso 2: Crear un bucket y un token de API en el panel de Cloudflare

1. Ve al panel de Cloudflare → **R2 Object Storage** → **Create bucket** y crea un bucket (por ejemplo, `cdn-assets`).
2. Para acceso público, conecta tu dominio personalizado en Bucket → **Settings** → **Public access** (por ejemplo, `assets.your-site.com`). Para un bucket de documentos privados, deja el acceso público desactivado.
3. Desde la página general de R2, abre **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** selecciona únicamente el bucket de destino
   - Copia los valores generados de **Access Key ID**, **Secret Access Key** y el **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Opcional — para el editor de imágenes)* Añade una regla CORS en Bucket → **Settings** → **CORS Policy**:
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

## Paso 3: Configurar `.env.local` y `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Escenario A — Bucket CDN público y Escenario B — Bóveda de documentos privada

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Opcional: se detecta automáticamente a partir del endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Utiliza siempre 'auto' para Cloudflare R2
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'Archivo privado R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Sin URL pública, ya que este volumen es privado
            private: true             # Streaming autorizado mediante Voter + DownloadController
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

> **Compatibilidad de checksum y ACL en AWS SDK v3.337+:** En `aws/aws-sdk-php >= 3.337`, las nuevas cabeceras CRC32 predeterminadas pueden provocar errores `XAmzContentSHA256Mismatch` o `NotImplemented` en Cloudflare R2. Cada vez que `FlysystemStorageFactory` detecta un `endpoint` personalizado, **aplica automáticamente** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` y `retain_visibility: false`, sin necesidad de configuración adicional.

---

## Paso 4: Verificar la conectividad en vivo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: AWS S3](aws-s3.md) | [07 — Índice de almacenamiento en la nube](../07-cloud-storage-and-dynamic-config.md) | [Siguiente: Backblaze B2 →](backblaze-b2.md)
