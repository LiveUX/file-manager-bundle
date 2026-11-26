# Guía de integración con Supabase Storage (S3)

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Índice principal](../README.md)

> **¿Cuándo deberías elegir Supabase Storage?**  
> Si ya tienes un proyecto en Supabase —o buscas un plan gratuito generoso para gestionar tanto recursos públicos vía CDN como buckets de documentos privados desde un único panel— Supabase Storage es la opción compatible con S3 más práctica.

Como **Supabase Storage** admite el protocolo compatible con S3, funciona desde el primer momento con LiveUX FileManagerBundle en una configuración híbrida de **Almacenamiento local + Supabase Cloud** sin tener que escribir una sola línea de código personalizado. El bundle detecta automáticamente el proveedor `supabase` a partir de la URL del endpoint y muestra el icono SVG de Supabase en la barra lateral.

---

## Paso 1: Instalar los paquetes necesarios

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Paso 2: Generar las claves de acceso S3 en el panel de Supabase

> [!WARNING]
> **Importante:** Las claves de API REST/Auth de Supabase —`SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) y `SUPABASE_SECRET_KEY` (`sb_secret_...`)— **no** funcionan con el protocolo S3. Debes usar las credenciales de 32 y 64 caracteres generadas en **S3 Access Keys**, tal como se indica a continuación.

1. Abre tu proyecto en el panel de Supabase → **Storage** → **New bucket** y crea un bucket (por ejemplo, `media` o `prints`).
   - Marca **Public bucket** para imágenes y recursos públicos servidos mediante CDN.
   - Deja **Public bucket** desmarcado (privado) para documentos de clientes o facturas.
2. Ve a **Project Settings** → **Storage** (o **Storage** → **Settings**).
3. Copia estos dos valores de la tarjeta **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** por ejemplo, `eu-central-1` o `eu-west-1`
4. En **S3 Access Keys**, haz clic en **New access key**, introduce una descripción y copia los valores generados:
   - **Access Key ID** (32 caracteres)
   - **Secret Access Key** (64 caracteres — ¡solo se muestra una vez!)

---

## Paso 3: Añadir las variables de entorno a `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## Paso 4: Configurar `liveux_file_manager.yaml`

### Escenario A — Bucket público

Las imágenes, los vídeos y los archivos públicos se sirven directamente a través de la URL CDN de Supabase (liberando de carga a tu servidor PHP), mientras que las miniaturas se generan una sola vez en tu servidor y se sirven desde la caché local (`public/media-cache`):

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Almacenamiento local'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Opcional: se detecta automáticamente a partir del endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # Caché de 60 s para contenido en la nube que puede cambiar externamente
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
                volume_order: 'local_first' # o 'network_first' / 'configured'
```

### Escenario B — Bucket privado con aislamiento por usuario

Para facturas de clientes o PDF confidenciales accesibles únicamente para usuarios autenticados y autorizados:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Bóveda de documentos Supabase'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Sin URL pública, ya que este bucket es privado
            private: true             # Streaming seguro mediante DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Aísla a cada usuario en su propia subcarpeta sin colisiones
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

## Paso 5: Verificar la conectividad en vivo con un solo comando

```bash
php bin/console file-manager:doctor --check-remote
```

Ejemplo de salida correcta:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Nota:** Los volúmenes dinámicos por usuario que utilizan marcadores de posición como `prefix: 'users/{user_id}'` (como en el Escenario B) se omiten de forma segura con estado `INFO` al ejecutar `file-manager:doctor --check-remote`, ya que en el entorno CLI no existe una sesión de usuario activa.

---

## Consejos específicos para Supabase y solución de problemas

### 1. Eliminar archivos en un bucket con restricción de tipos MIME (`InvalidMimeType: application/json`)
Si configuraste **Allowed MIME types** en tu bucket de Supabase para permitir únicamente tipos como `application/pdf` o `image/*`, Supabase devolverá `InvalidMimeType` cuando la papelera intente escribir `.trash/<id>/.meta.json`.
- **Respaldo automático (fallback):** `TrashManager` lo detecta automáticamente: el archivo en sí se mueve a `.trash/<id>/...` en el bucket en la nube, mientras que los metadatos `.meta.json` se guardan en un archivo de respaldo local en tu servidor (`trash-meta.json` o `<name>.trash-meta.json` dentro del directorio `defaults.metadata.file`). La restauración y la eliminación definitiva siguen funcionando sin problemas.

### 2. Errores de checksum y ACL en AWS SDK v3.337+
En los volúmenes S3 configurados con un `endpoint` personalizado como Supabase, `FlysystemStorageFactory` aplica automáticamente:
- `request_checksum_calculation: 'when_required'` y `response_checksum_validation: 'when_required'`
- `retain_visibility: false` y eliminación de las cabeceras `x-amz-acl` (`disable_acl: true`)

No se requiere ninguna configuración adicional.

### 3. Error de certificado SSL `cURL error 60` en Windows / WAMP
Si te encuentras con `cURL error 60: unable to get local issuer certificate` en tu máquina de desarrollo local:
- Descarga [cacert.pem](https://curl.se/ca/cacert.pem) y referéncialo en `php.ini` (tanto en el `php.ini` de Apache como en el de CLI):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Como alternativa, configúralo solo para un volumen concreto mediante `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. Los archivos eliminados desde el panel de Supabase siguen apareciendo en el listado
- Haz clic en el botón **Actualizar (`↻`)** de la barra de herramientas (o pulsa `R`) para omitir la caché inmediatamente mediante `?refresh=1`.
- Si los archivos del bucket se modifican externamente con frecuencia, ajusta `cache_ttl: 30` (o `cache_ttl: 0` para desactivar la caché) en el volumen.

---

[← 07 — Almacenamiento en la nube y configuración dinámica](../07-cloud-storage-and-dynamic-config.md) | [Siguiente: Guía de AWS S3 →](aws-s3.md)
