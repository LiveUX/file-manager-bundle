# Guía de integración con MinIO y RustFS (autoalojado / Docker)

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir MinIO / RustFS?**  
> El almacenamiento S3 autoalojado es ideal para servidores corporativos *on-premise* con requisitos estrictos de residencia de datos o cumplimiento normativo (RGPD/GDPR, HIPAA, etc.), donde los archivos nunca deben salir de tu infraestructura, o para probar el comportamiento real de S3 en local de forma gratuita con Docker.

---

## Paso 1: Instalar los paquetes necesarios

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Paso 2: Configuración rápida con Docker Compose (desarrollo / autoalojado)

```yaml
# compose.minio.yaml
services:
    minio:
        image: minio/minio:latest
        command: server /data --console-address ":9001"
        ports:
            - "9000:9000"   # Endpoint de la API S3
            - "9001:9001"   # Consola web de administración
        environment:
            MINIO_ROOT_USER: minioadmin
            MINIO_ROOT_PASSWORD: minioadmin-change-me
            # Origen CORS para el editor de imágenes integrado basado en Canvas en buckets públicos:
            MINIO_API_CORS_ALLOW_ORIGIN: "http://localhost:8000,https://your-site.com"
        volumes:
            - minio_data:/data

volumes:
    minio_data:
```

Inicia el contenedor y abre `http://localhost:9001` para crear un bucket llamado `uploads` (establece **Access Policy** en `public` para acceso de lectura público, o mantenlo en `private` para archivos restringidos).

---

## Paso 3: Configurar `.env.local` y `liveux_file_manager.yaml`

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
            label: 'Almacenamiento MinIO'
            icon: 'minio'             # Se detecta automáticamente si el endpoint contiene 'minio' o ':9000'; indícalo explícitamente para puertos personalizados de RustFS
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'http://localhost:9000/%env(MINIO_BUCKET)%' # Establécelo en null si private: true
            private: false
            cache_ttl: 30
            driver_options:
                bucket: '%env(MINIO_BUCKET)%'
                region: 'us-east-1'   # Valor predeterminado estándar para MinIO/RustFS
                endpoint: '%env(MINIO_ENDPOINT)%'
                use_path_style_endpoint: true   # Obligatorio para servidores S3 autoalojados
                key: '%env(MINIO_KEY)%'
                secret: '%env(MINIO_SECRET)%'
                # Si utilizas un certificado HTTPS autofirmado:
                # ca_bundle: '%kernel.project_dir%/config/certs/minio-ca.pem'

    profiles:
        default:
            volumes: ['minio_storage']
```

> **Consejo (RustFS / Ceph RGW / DigitalOcean Spaces / Wasabi):** Puedes conectarte a cualquier servicio compatible con S3 utilizando exactamente esta misma plantilla `AwsS3`; solo tienes que actualizar `endpoint`, `region` y `use_path_style_endpoint`. Al definir un endpoint personalizado, `FlysystemStorageFactory` aplica automáticamente la validación de checksum `when_required` y `disable_acl: true`.

---

## Paso 4: Verificar la conectividad en vivo

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: Backblaze B2](backblaze-b2.md) | [07 — Índice de almacenamiento en la nube](../07-cloud-storage-and-dynamic-config.md) | [Siguiente: Google Cloud Storage (GCS) →](google-cloud-storage.md)
