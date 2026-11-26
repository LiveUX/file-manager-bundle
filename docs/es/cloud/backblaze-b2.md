# Guía de integración con Backblaze B2

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir Backblaze B2?**  
> Backblaze B2 es ideal cuando necesitas almacenar cientos de gigabytes de vídeos, PDF listos para impresión o archivos en alta resolución al menor coste de almacenamiento posible (especialmente si se combina con transferencia saliente gratuita a través de socios de la Bandwidth Alliance como Cloudflare o Bunny CDN).

El endpoint `.backblazeb2.com` se detecta automáticamente y el icono SVG `backblaze_b2` se muestra en la barra lateral.

---

## Paso 1: Instalar los paquetes necesarios

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Paso 2: Configurar el bucket, la Application Key y CORS en Backblaze

1. Ve a Backblaze B2 → **Buckets** → **Create a Bucket** (por ejemplo, `app-media`).
   - Una vez creado, anota el **Endpoint** que aparece en la tarjeta del bucket (por ejemplo, `s3.eu-central-003.backblazeb2.com`).
   - La **Región** corresponde al segmento central del endpoint: `eu-central-003`.
2. Ve a **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** selecciona únicamente el bucket que acabas de crear
   - **Type of Access:** `Read and Write`
   - Copia los valores generados **`keyID`** y **`applicationKey`**.
3. *(Opcional — para el editor de imágenes)* En Bucket → **CORS Rules**, elige **Share everything in this bucket with every origin** o añade una regla CORS S3 personalizada para tu dominio:
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

## Paso 3: Configurar `.env.local` y `liveux_file_manager.yaml`

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
            icon: 'backblaze_b2'      # Opcional: se detecta automáticamente a partir del endpoint
            group: 'network'
            driver: 'AwsS3'
            # Para buckets públicos, utiliza la Friendly URL o tu dominio CDN (establécelo en null si es privado):
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

> **Nota:** Al configurarse un endpoint personalizado, `FlysystemStorageFactory` activa automáticamente la compatibilidad de checksum de AWS SDK v3.337+ (`when_required`), `disable_acl: true` y `retain_visibility: false` para Backblaze B2.

---

## Paso 4: Verificar la conectividad en vivo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: Cloudflare R2](cloudflare-r2.md) | [07 — Índice de almacenamiento en la nube](../07-cloud-storage-and-dynamic-config.md) | [Siguiente: MinIO / RustFS →](minio-rustfs.md)
