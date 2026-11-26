# Guía de integración con Amazon AWS S3

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir Amazon AWS S3?**  
> AWS S3 es la opción estándar del sector cuando tu aplicación ya se ejecuta en AWS (EC2/ECS/CloudFront) y necesitas autenticación sin claves mediante roles de IAM junto con una alta durabilidad global (99,999999999 %).

LiveUX FileManagerBundle se conecta directamente a tus buckets de AWS S3 usando el driver `AwsS3` y muestra automáticamente el icono SVG `aws_s3` en la barra lateral.

---

## Paso 1: Instalar los paquetes necesarios

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Paso 2: Crear una política de IAM con privilegios mínimos

Crea un usuario de IAM dedicado (o un rol de EC2/ECS) en AWS IAM para esta aplicación y adjunta la siguiente política restringida estrictamente a tu bucket:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ListBucket",
            "Effect": "Allow",
            "Action": ["s3:ListBucket"],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME"
        },
        {
            "Sid": "ManageObjects",
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
        }
    ]
}
```

> [!IMPORTANT]
> **Propiedad de objetos / Configuración de ACL (`disable_acl: true`):**  
> Los buckets modernos de AWS S3 desactivan las ACL de forma predeterminada (*Bucket owner enforced*). La opción `disable_acl` solo se establece automáticamente en `true` cuando se configura un `endpoint` personalizado (R2, Supabase, MinIO, etc.); **para AWS S3 nativo, su valor predeterminado es `false`**.  
> Para evitar errores de tipo `AccessControlListNotSupported`, añade explícitamente **`disable_acl: true`** (y opcionalmente `retain_visibility: false`) dentro de `driver_options`, como se muestra en el ejemplo siguiente.

---

## Paso 3: Configurar CORS en el bucket (para el editor de imágenes)

Para permitir que el editor de imágenes integrado basado en Canvas recorte y edite directamente en el navegador imágenes alojadas en S3, añade tu dominio en Bucket → **Permissions** → **CORS**:

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": ["https://your-site.com"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

---

## Paso 4: Configurar `.env.local` y `liveux_file_manager.yaml`

```dotenv
# .env.local
AWS_S3_BUCKET=my-media-bucket
AWS_S3_REGION=eu-central-1
AWS_S3_KEY=AKIAIOSFODNN7EXAMPLE
AWS_S3_SECRET=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        aws_cdn:
            label: 'AWS S3 (CloudFront CDN)'
            icon: 'aws_s3'            # Opcional: se detecta automáticamente a partir del driver AwsS3
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # URL pública de CloudFront o S3
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Admite marcadores de posición dinámicos en la ruta
                disable_acl: true     # Recomendado para buckets S3 con "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'Archivo privado AWS'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access activado; streaming de archivos mediante Voter + DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'private/{user_id}'
                disable_acl: true
                retain_visibility: false

    profiles:
        default:
            volumes: ['aws_cdn', 'aws_private_docs']
```

> **Rol de IAM en EC2 / ECS o credenciales temporales de AWS STS:**
> - **Rol de IAM:** Si utilizas un perfil de instancia EC2 o un rol de tarea ECS, puedes omitir `key` y `secret` por completo: el AWS SDK resuelve automáticamente las credenciales desde el entorno.
> - **Credenciales temporales de AWS STS:** Si utilizas tokens de sesión temporales, añade `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Paso 5: Verificar la conectividad en vivo

```bash
php bin/console file-manager:doctor --check-remote
```

> **Nota:** Los volúmenes con ámbito por usuario que incluyen marcadores de posición como `prefix: 'private/{user_id}'` (por ejemplo, `aws_private_docs`) se omiten de forma segura con estado `INFO` al ejecutar `file-manager:doctor --check-remote`, ya que no existe una sesión de usuario en el entorno CLI.

---

[← Anterior: Supabase Storage](supabase-storage.md) | [07 — Índice de almacenamiento en la nube](../07-cloud-storage-and-dynamic-config.md) | [Siguiente: Cloudflare R2 →](cloudflare-r2.md)
