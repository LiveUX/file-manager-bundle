# Guía de integración con Google Cloud Storage (GCS)

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir Google Cloud Storage?**  
> GCS es la elección natural si tu aplicación ya se ejecuta en Google Cloud (GKE, Cloud Run, Compute Engine) y deseas conectarte directamente mediante su capa de interoperabilidad S3 (HMAC) sin añadir un SDK adicional del proveedor.

**Google Cloud Storage (GCS)** funciona directamente con el driver `AwsS3` a través de su capa integrada de compatibilidad con S3 (*XML API / Interoperability HMAC*), aunque también puedes integrar `league/flysystem-google-cloud-storage` mediante `FlysystemService` si lo prefieres. El endpoint `storage.googleapis.com` se detecta automáticamente y el icono SVG `gcs` se muestra en la barra lateral.

---

## Paso 1: Instalar los paquetes necesarios

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Paso 2: Crear una clave HMAC en Google Cloud Console

1. Abre Google Cloud Console → **Cloud Storage** → **Buckets** y crea un bucket nuevo (por ejemplo, `my-symfony-media`).
2. Dirígete a **Cloud Storage** → **Settings** → **Interoperability**.
3. En **Service account HMAC**, haz clic en **Create a key for a service account** (asegúrate de que la cuenta de servicio tenga el rol `Storage Object Admin`).
4. Copia la **Access key** (`GOOG1E...`) y el **Secret** generados.
5. *(Opcional — para el editor de imágenes)* Aplica una política CORS al bucket utilizando un archivo `cors.json`:
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

## Paso 3: Configurar `.env.local` y `liveux_file_manager.yaml`

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
            icon: 'gcs'               # Opcional: se detecta automáticamente a partir del endpoint
            group: 'network'
            driver: 'AwsS3'
            # Para buckets públicos: https://storage.googleapis.com/<bucket> (establécelo en null si es privado)
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

> **Compatibilidad automática con GCS:** Como está configurado `endpoint: 'https://storage.googleapis.com'`, `FlysystemStorageFactory` aplica automáticamente `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` y `retain_visibility: false`.

---

## Paso 4: Verificar la conectividad en vivo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: MinIO / RustFS](minio-rustfs.md) | [07 — Índice de almacenamiento en la nube](../07-cloud-storage-and-dynamic-config.md) | [Siguiente: SFTP y servicios Flysystem personalizados →](sftp-and-custom-flysystem.md)
