# 02 — Configuración y perfiles (Referencia completa de parámetros y recetas)

[← Anterior: 01 — Requisitos e instalación](01-requirements-and-installation.md) | [Índice de documentación](README.md) | [Siguiente: 03 — Modos de uso y API JS →](03-usage-modes-and-js-api.md)

Esta guía explica **cada opción** de `config/packages/liveux_file_manager.yaml`, su valor por defecto, cuándo personalizarla y **5 recetas listas para producción** que puedes copiar y pegar directamente en tu proyecto.

---

## Modelo mental en 30 segundos: `defaults`, `volumes` y `profiles`

Piensa en el archivo de configuración en **3 capas limpias**:

1. **`defaults` (Valores globales por defecto):** Base compartida heredada por todos los perfiles (tema, idioma, modo de color, caché, driver de metadatos, registro de auditoría, valores por defecto de IA). A menos que se sobrescriban en un perfil específico, estos valores se aplican en todas partes.
2. **`volumes` (Discos / Buckets de almacenamiento):** Define **dónde viven físicamente los archivos** (directorio local, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage o un servicio Flysystem personalizado).
3. **`profiles` (Perfiles de uso):** Define las **reglas, el diseño de interfaz y los permisos** para una pantalla o flujo de trabajo concreto. Por ejemplo:
   - `product_catalog` solo acepta imágenes cuadradas `1:1` de productos y las convierte a WebP automáticamente;
   - `customer_docs` se conecta a un bucket privado S3 de Supabase, solo acepta PDF y aísla a cada usuario dentro de su propia carpeta;
   - `editor_picker` abre un selector modal minimalista para TinyMCE con selección mediante doble clic.

---

## 1. `defaults` — Valores globales por defecto

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = hereda el locale activo de Symfony (respalda en 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parámetro | Por defecto | Qué hace | Cuándo cambiarlo |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Directorio raíz por defecto para perfiles abreviados que definen `path` en línea sin `volumes`. | Cuando guardas archivos locales en otra carpeta, como `public/media`. |
| `theme` | `'bootstrap5'` | Tema de interfaz por defecto para todos los perfiles (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` o el nombre de tu tema personalizado). | Para adaptarlo al sistema de diseño de tu aplicación (p. ej., `'shadcn'` o `'tailwind'` en proyectos Tailwind). |
| `locale` | `null` | Cuando es `null`, hereda automáticamente el locale activo de la solicitud/traductor de Symfony en los **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), normaliza formatos BCP-47 y POSIX (`es_ES.UTF-8`, `de_DE.UTF-8`, `zh-Hans-CN`), activa RTL (`dir="rtl"`) automáticamente para árabe y otras escrituras RTL, y respalda en `'en'` para locales desconocidos. | Establécelo explícitamente (p. ej., `'es'`, `'en'`, `'fr'`) para fijar un único idioma para todos los usuarios. |
| `color_scheme` | `'auto'` | Modo de color: `'auto'` (detecta `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (siempre claro) o `'dark'` (siempre oscuro). | Cuando quieres forzar un panel de administración solo claro o solo oscuro. |
| `z_index` | `1050` | Contexto de apilamiento base (`--liveux-fm-z-base`) para modales, paneles OffCanvas, Lightbox y menús contextuales (`>= 1`). | Súbelo por ejemplo a `10050` si la barra de navegación de tu panel usa `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | Añade automáticamente antes de `</body>` el contenedor `<dialog>` necesario para `window.LiveUXFileManager.open()` en páginas HTML completas (sin editar `base.html.twig`). | Cámbialo a `false` si prefieres incluir manualmente `@LiveUXFileManager/components/DialogHost.html.twig` solo en diseños concretos. |
| `dialog_host_profile` | `'default'` | Perfil por defecto utilizado por el `DialogHost` inyectado automáticamente cuando no se pasa ningún perfil. | Cuando tu perfil principal no se llama `default` (p. ej., `admin_media`). |
| `cache.enabled` | `true` | Caché PSR-6 en el servidor para listados de directorios y árboles de carpetas. | Ponlo en `false` durante la depuración local (o desactívalo por perfil/volumen). |
| `cache.pool` | `'cache.app'` | Pool de caché PSR-6 de Symfony (Redis, Filesystem, APCu, etc.). Usa automáticamente `<pool>.taggable` cuando está disponible para invalidación precisa por etiquetas. | Para aislar las entradas de caché del gestor de archivos en un pool dedicado de Redis. |
| `cache.ttl` | `3600` | Tiempo de vida de la caché en segundos (por defecto: 1 hora). Las mutaciones (subir, eliminar, mover, renombrar) invalidan automáticamente los directorios afectados. | Redúcelo a `60`–`300` cuando los archivos se modifican con frecuencia externamente por FTP o consolas en la nube. |
| `metadata.enabled` | `true` | Guarda colores/iconos/elementos fijados de carpetas y metadatos SEO de archivos (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Ponlo en `false` para selectores de archivos mínimos que no necesiten metadatos editoriales. |
| `metadata.driver` | `'json_file'` | Backend de metadatos: `'json_file'` (archivo local para aplicaciones en un solo servidor), `'doctrine'` (almacenamiento DBAL integrado para aplicaciones multiservidor o con balanceador de carga en MySQL, MariaDB, PostgreSQL o SQLite) o `'none'`. | Cambia a `'doctrine'` siempre que ejecutes múltiples réplicas de tu aplicación (Kubernetes, balanceador de carga, Docker Swarm) — ver [Capítulo 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Ruta de almacenamiento para el driver `json_file`. En el mismo directorio se guarda un archivo auxiliar de papelera (`trash-meta.json` o `<nombre>.trash-meta.json`) para buckets en la nube con restricciones MIME. | Muévelo a una ruta NFS o volumen compartido si lo necesitas. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | Nombre de conexión DBAL, EntityManager, nombre de tabla (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) y creación automática de tabla cuando `driver: 'doctrine'`. | Establece `auto_create_table: false` en producción si gestionas el esquema mediante Doctrine Migrations (ver [Capítulo 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Escribe registros estructurados de auditoría PSR-3 (`user`, `ip`, `path`, `volume`) para las 9 mutaciones de archivos/carpetas (subir, eliminar, mover, copiar, renombrar, duplicar, crear carpeta, restaurar papelera y purgar papelera). | Actívalo (`true`) en entornos sujetos a cumplimiento donde necesites auditar *«¿quién eliminó qué y cuándo?»*. |
| `audit_log.level` | `'info'` | Nivel de log PSR-3 para entradas de auditoría (`debug`, `info`, `notice`, `warning`). | Ajústalo a tus reglas de enrutamiento o filtrado de Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | Modelo de visión/texto por defecto cuando está instalado el paquete opcional `symfony/ai-bundle` (ver [Capítulo 09](09-symfony-ai-integration.md)). | Cambia a Gemini, Claude o un modelo local de Ollama. |
| `ai.platform_service` | `null` | ID de servicio `PlatformInterface` personalizado de Symfony AI (`null` usa la plataforma registrada por defecto). | Cuando tu aplicación define múltiples plataformas Symfony AI. |

---

## 2. `volumes` — Volúmenes de almacenamiento (Local y Nube)

Un mismo perfil puede montar varios volúmenes simultáneamente (por ejemplo, disco local y Supabase/R2/S3). Los usuarios pueden arrastrar y soltar archivos directamente desde una carpeta local a un bucket en la nube.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disco local'
            icon: 'disk'              # Opcional (se detecta automáticamente según el driver/endpoint si se omite)
            group: 'local'            # Opcional (null -> 'local' para LocalFileSystem, 'network' para S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # TTL opcional por volumen (0 = desactivado)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # También se detecta automáticamente desde el endpoint
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Los cambios externos desde el panel cloud aparecen en 60 segundos
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parámetro | Por defecto | Qué hace | Cuándo cambiarlo |
| :--- | :--- | :--- | :--- |
| `label` | Clave del volumen | Nombre visible en la barra lateral, ruta de navegación y selector de volúmenes. | Asigna un título claro (p. ej., `'CDN de medios de productos'`). |
| `icon` | `null` *(auto)* | Icono SVG de la barra lateral. Cuando es `null`, se detecta automáticamente por `driver` y `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Apunta a un `<dir>/ui/mi_icono.svg` personalizado en tu conjunto de iconos. |
| `group` | `null` *(auto)* | Grupo de sección en la barra lateral. Cuando es `null`, `LocalFileSystem` usa `'local'` y `AwsS3` / `FlysystemService` usan `'network'`. | Sobrescríbelo si quieres agrupar un montaje NFS en `'network'` o un disco cloud en `'local'`. |
| `driver` | `'LocalFileSystem'` | Backend de almacenamiento: sistema de archivos local (`LocalFileSystem`), almacenamiento de objetos compatible con S3 (`AwsS3`) o un servicio Flysystem existente (`FlysystemService`). | Usa `'AwsS3'` para S3/R2/Supabase/B2/MinIO/GCS, o `'FlysystemService'` para SFTP/Azure/Dropbox. |
| `path` | `null` | Ruta física del directorio para `LocalFileSystem` (admite `{user_id}`, `{Y}`, `{m}`, `{d}`). | Define una raíz personalizada o aislamiento por usuario (`.../users/{user_id}`). |
| `url_prefix` | `null` | Prefijo de URL pública usado por el navegador para acceder a archivos públicos (`/uploads` o `https://cdn.ejemplo.com`). Se ignora cuando `private: true`. | Configúralo al servir activos mediante CDN o un bucket público S3/R2/Supabase. |
| `private` | `false` | Cuando es `true`, los archivos nunca se exponen mediante URLs públicas directas: todas las descargas, vistas previas y miniaturas pasan por comprobaciones del Voter de Symfony (`DownloadController`). | **Establécelo siempre en `true`** para facturas, documentos de identidad, contratos o archivos privados de clientes. |
| `cache_ttl` | `null` *(heredado)* | TTL de caché (en segundos) limitado a este volumen. `0` desactiva la caché para este volumen; `null` hereda del perfil/defaults. | Mantén el disco local cacheado `3600` s mientras limitas un bucket en la nube a `60` s o `0`. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(token de sesión temporal STS)*, `version` *(por defecto `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(automáticamente `true` en endpoints personalizados; `false` por defecto en AWS S3 estándar — configúralo explícitamente en `true` para Bucket owner enforced)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Consulta el [Capítulo 07](07-cloud-storage-and-dynamic-config.md) para guías específicas por proveedor. |

---

## 3. `profiles` — Diccionario de parámetros de perfil

### 3.1. Almacenamiento básico, filtrado y cuota
| Parámetro | Por defecto | Qué hace | Cuándo cambiarlo |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Sobrescribe el idioma de la interfaz y mensajes de error para este perfil (`'es'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, etc.; `null` hereda `defaults.locale` → locale de la petición Symfony → `'en'`). | Cuando distintos paneles de la misma aplicación se dirigen a diferentes idiomas. |
| `volumes` | `[]` | Lista ordenada de claves de volumen accesibles en este perfil (p. ej., `['local_uploads', 'supabase_s3']`). El primer volumen se abre por defecto. | Para asociar uno o varios volúmenes locales/nube. |
| `ignore_dot_files` | `true` | Oculta archivos ocultos con punto (`.env`, `.htaccess`, `.DS_Store`, `.trash`) en los listados y bloquea el acceso directo a ellos. | Mantenlo en `true` por seguridad. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Oculta y bloquea el acceso directo a archivos o carpetas que coincidan con expresiones regulares o comodines. | Oculta carpetas internas como `/^cache$/i` o `/^backups$/i`. |
| `sort_by` | `'name'` | Columna de ordenación por defecto: `'name'`, `'date'`, `'size'` o `'type'`. | Usa `'date'` + `sort_direction: 'desc'` para mostrar primero las subidas más recientes. |
| `sort_direction` | `'asc'` | Sentido de ordenación: `'asc'` (A→Z / más pequeño primero) o `'desc'` (Z→A / más reciente primero). | `'desc'` funciona muy bien en galerías multimedia y bibliotecas editoriales. |
| `folders_first` | `true` | Lista siempre las carpetas antes que los archivos. | Ponlo en `false` para una ordenación alfabética o cronológica plana. |
| `storage_quota` | `null` | Uso máximo total de disco permitido para el perfil (p. ej., `'500M'`, `'2G'`). Al alcanzarse, las subidas se rechazan en el servidor y aparece una barra de uso en la barra lateral. | Imprescindible en SaaS multiinquilino o cuotas por usuario (`{user_id}`). |
| `upload_subfolder` | `null` | Plantilla dinámica de subcarpeta donde se ubican automáticamente los archivos subidos bajo el directorio actual (p. ej., `'{Y}/{m}'`). | Organiza automáticamente las subidas en carpetas por fecha como `2026/11`. |

### 3.2. Subidas, subidas por fragmentos (`chunk_upload`) y detección de duplicados (`duplicate_detection`)
| Parámetro | Por defecto | Qué hace | Cuándo cambiarlo |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(todos los seguros)* | Lista blanca de tipos MIME aceptados (p. ej., `['image/jpeg', 'image/png', 'image/webp']`). Cuando está vacía, se permiten todos los tipos seguros que no figuren en `upload_deny`. | Restringe siempre a tipos MIME concretos en formularios solo de imágenes o solo de PDF. |
| `upload_deny` | **47 tipos MIME peligrosos** | Tipos MIME estrictamente bloqueados (PHP, HTML, XML no SVG, JS, Shell, EXE, Java, CGI, etc., validados en el servidor mediante magic bytes). | Añade tipos MIME adicionales bloqueados si tu política lo exige. |
| `upload_max_size` | `'10M'` | Tamaño máximo **ensamblado** de un único archivo subido (p. ej., `'200M'`, `'1G'`). Con la subida por fragmentos activada, archivos mucho mayores que el límite `2M` de PHP se suben sin problemas hasta este tope. | Súbelo a `'200M'`–`'500M'` para perfiles de video o catálogos PDF, o redúcelo a `'2M'` para avatares. |
| `overwrite_policy` | `'rename'` | Comportamiento ante colisión si ya existe un archivo con el mismo nombre: `'rename'` (`foto-1.jpg`), `'overwrite'` o `'reject'`. | Mantén `'rename'` para evitar pérdidas accidentales de datos. |
| `chunk_upload.enabled` | `true` | Divide las subidas grandes en pequeños fragmentos con Pausar / Reanudar / Cancelación confirmada y reintento automático ante cortes temporales de red. | Rara vez se desactiva; solo si estrictamente subes archivos diminutos (< 1 MB). |
| `chunk_upload.chunk_size` | `'2M'` | Tamaño objetivo del fragmento. Se limita automáticamente al **80%** de `upload_max_filesize` y `post_max_size` de PHP (p. ej., 1,60 MB en un límite PHP de 2M). | Súbelo a `'5M'` en servidores con límites PHP altos (p. ej., 32M+) para acelerar subidas grandes. |
| `chunk_upload.parallel_uploads` | `3` | Máximo de subidas simultáneas de archivos (`1`–`10`). | Usa `2` en servidores con pocos recursos, o `4`–`5` en conexiones cloud/CDN rápidas. |
| `chunk_upload.max_retries` | `3` | Reintentos con espera exponencial por fragmento ante un fallo de red (`0`–`10`). | `3`–`5` funciona bien en redes móviles o inestables. |
| `duplicate_detection.enabled` | `false` | Detecta archivos duplicados al subirlos mediante hash de contenido SHA-256. | Actívalo (`true`) para evitar que los editores suban repetidamente la misma foto de producto. |
| `duplicate_detection.policy` | `'warn'` | Acción al detectar un duplicado: `'warn'` (subir y avisar), `'reject'` (bloquear subida) o `'reuse'` (devolver el archivo existente sin duplicar espacio). | `'reuse'` ofrece un ahorro notable de almacenamiento en comercio electrónico y CMS. |
| `duplicate_detection.max_scan_files` | `5000` | Límite superior de archivos indexados para detección de duplicados (`100`–`50000`) para evitar picos de CPU/E/S en archivos enormes. | Súbelo a `10000`–`25000` en bibliotecas multimedia grandes. |

### 3.3. Normalización automática de nombres de archivo (`naming`)
| Parámetro | Por defecto | Qué hace | Ejemplo (`"Foto Producto 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Translitera caracteres acentuados/Unicode y espacios en slugs ASCII limpios aptos para URL. | `foto-producto-2026.jpg` |
| `naming.slugger_locale` | `null` | Idioma de transliteración (`null` hereda el idioma del perfil; p. ej., `'es'` mapea `ñ/á/é/í/ó/ú`, `'de'` mapea `ä/ö/ü/ß`, `'tr'` mapea `İ/ı/ş/ğ/ç/ö/ü`). | Configúralo al idioma de tu contenido (p. ej., `'es'`, `'de'`, `'fr'`). |
| `naming.transformer` | `'none'` | Transformación de mayúsculas/formato: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` o `'folder'` (nombra el archivo según su carpeta contenedora). | `'snake'` → `foto_producto_2026.jpg`, `'folder'` → `termos-1.jpg` |
| `naming.prepend` / `append` | `''` | Añade al principio o al final texto estático o tokens dinámicos (`{Y}`, `{m}`, `{d}`, `{uniqid}`). | `prepend: '{Y}{m}_'` → `202611_foto.jpg` |
| `naming.apply_on_rename` | `true` | Aplica también las reglas de `slugger` y `transformer` cuando un usuario renombra un archivo desde la interfaz. | Mantenlo en `true` para garantizar nombres limpios tras renombrar. |
| `naming.apply_affixes_on_rename` | `false` | Evita que los prefijos/sufijos `prepend`/`append` se dupliquen cada vez que se renombra un archivo. | Mantenlo en `false`. |
| `naming.allow_extension_change` | `false` | Impide a los usuarios cambiar la extensión (p. ej., `.jpg` → `.php` o `.png`) al renombrar. | Mantenlo en `false` por seguridad. |

### 3.4. Optimización de imágenes (`image_optimization`) y restricciones (`image_constraints`)
- **`image_optimization`:** Corrige automáticamente la orientación, elimina metadatos EXIF, redimensiona y comprime fotos grandes en el servidor durante la subida.
- **`image_constraints`:** Rechaza imágenes no conformes **tanto en 0 ms en el navegador como en el servidor** (por ejemplo, bloqueando fotos borrosas de baja resolución o fotos de producto no cuadradas).

| Parámetro | Por defecto | Qué hace | Cuándo usarlo |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Activa la optimización de imágenes al subir (Imagick → GD → sin efecto silencioso si ninguno está disponible). | Actívalo (`true`) en cualquier perfil donde los usuarios suban fotos sin procesar desde el móvil. |
| `image_optimization.auto_orient` | `true` | Lee las etiquetas de orientación EXIF y gira automáticamente fotos tomadas de lado en el teléfono. | Mantenlo en `true` para subidas desde móviles. |
| `image_optimization.strip_exif` | `true` | Elimina coordenadas GPS y metadatos EXIF de cámara (privacidad RGPD + archivos más ligeros). | Mantenlo en `true` para todas las imágenes públicas. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Reduce proporcionalmente imágenes que superen estos límites conservando su relación de aspecto (`>= 100`). | `1920`×`1920` es un punto ideal para imágenes web. |
| `image_optimization.convert_to_webp` | `false` | Convierte automáticamente archivos JPEG/PNG subidos a WebP (30–60% más ligeros). | Actívalo (`true`) en perfiles orientados al rendimiento web moderno. |
| `image_optimization.quality` | `85` | Calidad de compresión de salida (`10`–`100`). | `80`–`85` ofrece el mejor equilibrio entre peso y calidad. |
| `image_constraints.min_width` / `min_height` | `null` | Dimensiones mínimas obligatorias en píxeles. | Evita subidas borrosas (p. ej., portadas de blog de al menos `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Dimensiones máximas permitidas en píxeles (se comprueban *después* del escalado de `image_optimization` cuando está activado). | Exige dimensiones estrictas de banners o plantillas de impresión. |
| `image_constraints.aspect_ratio` | `null` | Relación de aspecto obligatoria: `'1:1'` (cuadrada), `'16:9'` (banner panorámico), `'4:3'`, `'3:4'`, `'1.91:1'`, etc. | Tarjetas cuadradas de producto (`'1:1'`) o cabeceras de video/artículo (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Desviación permitida respecto a la proporción objetivo (`0.0`–`0.5`; `0.05` = 5% de tolerancia, por lo que tanto `1920×1080` como `1280×722` pasan). | `0.05` funciona bien en casi todos los casos. |

### 3.5. Miniaturas, papelera (`trash`), ZIP (`zip`) y enlaces compartidos firmados (`share`)
| Parámetro | Por defecto | Qué hace |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Genera miniaturas en caché para imágenes: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` o `'none'`. Las imágenes en la nube se descargan una vez y se almacenan en caché local. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Comportamiento si no están instalados ni GD ni Imagick: `'fallback_original'` (mostrar imagen original), `'icon'` (mostrar icono de archivo) o `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Dimensiones de miniatura (`>= 32`), formato de salida (`webp`, `jpeg`, `png`) y ajuste (`contain` = imagen completa visible, `cover` = recorte centrado relleno). Considera `480`×`360` si usas `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | Añade `loading="lazy"` nativo a las imágenes de la cuadrícula para acelerar el renderizado inicial en carpetas grandes. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Directorio local donde se almacenan en caché las miniaturas generadas. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Envía los archivos eliminados a una papelera en lugar de destruirlos inmediatamente. Recurre automáticamente a un archivo auxiliar local `trash-meta.json` en buckets en la nube con restricciones MIME. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Habilita la descarga en un clic de varios archivos/carpetas en `.zip` (requiere la extensión PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | Extracción de archivos protegida en 7 capas contra Zip-Slip y bombas ZIP. Permanece bloqueada mientras `allow_extract: false`; al activarla, `permissions.extract_zip` pasa automáticamente a `true` (puedes restringirlo a `'ROLE_ADMIN'` o `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Genera URLs compartidas temporales firmadas con HMAC mediante `UriSigner` de Symfony. Establecer `enabled: false` invalida inmediatamente todos los enlaces emitidos anteriormente. |

### 3.6. Interfaz de usuario (`ui`) — Diseño, tamaños e interruptores de barra de herramientas
| Parámetro | Por defecto | Qué hace | Cuándo cambiarlo |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Tema específico del perfil (p. ej., `'bootstrap5'` en administración, `'shadcn'` en el portal de clientes). | Cuando distintas áreas de la aplicación usan temas visuales diferentes. |
| `ui.color_scheme` | `null` *(defaults)* | Modo de color específico del perfil (`auto`, `light`, `dark`). | Fija `'dark'` en un espacio de trabajo exclusivamente oscuro. |
| `ui.icon_set` | `'classic'` | Estilo de iconos de archivos/carpetas: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` o la ruta de un directorio SVG personalizado (`'%kernel.project_dir%/assets/fm-icons'`). | Elige `'windows11'` o `'macos'` para un aspecto de escritorio, o apunta a SVGs de marca. |
| `ui.z_index` | `null` *(defaults)* | Sobrescritura del z-index de superposición para este perfil. | Cuando se incrusta en una página con editores o paneles de `z-index` elevado. |
| `ui.mobile_dialog_mode` | `'auto'` | Presentación de diálogos en pantallas pequeñas (`<= 767.98px`): `'auto'` (modales y paneles laterales en pantalla completa, paneles superior/inferior como hojas nativas redondeadas), `'fullscreen'` (todos en pantalla completa) o `'sheet'` (todos como hoja inferior nativa). | Fuerza `'sheet'` o `'fullscreen'` en todos los selectores móviles. |
| `ui.default_view` | `'grid'` | Vista inicial: `'grid'` (Cuadrícula con miniaturas) o `'list'` (Lista en tabla ordenable). | Usa `'list'` para archivos PDF/documentos y `'grid'` para galerías visuales. |
| `ui.item_size` | `'md'` | Ancho de tarjetas en cuadrícula y escala de miniaturas en lista: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) o un entero exacto en píxeles (`96`–`320`). Cambia automáticamente a relleno compacto por debajo de `<= 132px`. | Usa `'lg'` / `'xl'` para revisión visual y `'sm'` / `'xs'` para selectores compactos. |
| `ui.card_aspect_ratio` | `'4/3'` | Proporción del marco de vista previa de la tarjeta: `'1/1'` (cuadrado), `'4/3'` (estándar), `'16/9'` (panorámico/video) o `'3/4'` (carteles/catálogos verticales). | Ajústalo a la forma principal de tus activos (`'1/1'` para productos, `'16/9'` para video/banners). |
| `ui.folder_style` | `'tile'` | Estilo de carpetas en vista cuadrícula: `'tile'` (Compacto: mosaicos horizontales al estilo Google Drive encima de los archivos) o `'card'` (Tarjeta: tarjetas de carpeta de tamaño completo al estilo Windows/Finder). | Cambia a `'card'` para carpetas más destacadas visualmente. |
| `ui.show_size_slider` | `true` | Muestra el deslizador en vivo de **Tamaño de elementos** (`96px`–`320px`) y el selector de **Estilo de carpetas** dentro del menú Configuración. | Ponlo en `false` para mantener una densidad de diseño fija. |
| `ui.volume_order` | `'local_first'` | Orden de volúmenes en la barra lateral y selector: `'local_first'`, `'network_first'` o `'configured'` (orden exacto de `volumes: [...]` en YAML sin divisores de grupo). | Usa `'network_first'` o `'configured'` para flujos centrados en la nube. |
| `ui.show_folder_tree` | `true` | Muestra u oculta la barra lateral izquierda del árbol de carpetas. | Ponlo en `false` en selectores sencillos de carpeta única para TinyMCE o avatares. |
| `ui.tree_lines` | `true` | Renderiza líneas de conexión de jerarquía dentro del árbol de carpetas. | Ponlo en `false` para una barra lateral más minimalista. |
| `ui.show_folder_item_count` | `true` | Muestra insignias con el número de elementos (`12`) junto a las carpetas. Respeta la autorización para no filtrar recuentos de subcarpetas restringidas. | Mantenlo en `true` para visibilidad rápida del contenido. |
| `ui.folder_count_mode` | `'recursive_files'` | Cómo se calculan las insignias de carpeta: `'recursive_files'` (todos los archivos incluidas subcarpetas), `'files'` (solo archivos directos) o `'items'` (archivos + subcarpetas directos). | Elige `'files'` si solo quieres recuentos directos. |
| `ui.inspector_mode` | `'on_select'` | Panel derecho de Vista previa y detalles: `'on_select'` (se abre al seleccionar un elemento), `'always'` o `'hidden'`. | Establécelo en `'hidden'` en selectores modales estrechos. |
| `ui.folder_open_on` | `'dblclick'` | Gesto de navegación de carpetas: `'dblclick'` (un clic selecciona, doble clic abre — convención de escritorio) o `'click'` (un clic abre inmediatamente — convención web/móvil). | Usa `'click'` para navegación rápida con un solo clic en paneles de administración. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Elementos por página por defecto (`6`–`200`) y opciones seleccionables por el usuario. | Súbelo a `48` y `[24, 48, 96, 150]` en catálogos de gran volumen. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Muestra la barra lateral de cuota de almacenamiento y el modal desplegable de **Uso de almacenamiento y cuota**. (La aplicación de `storage_quota` en el servidor sigue activa aunque se oculte.) | Pon `show_quota: false` si no deseas mostrar medidores de cuota en la interfaz. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Controla el visor PDF en Lightbox y la reproducción HTML5 de video (aplicado tanto en interfaz como en el servidor en `DownloadController`). | Ponlo en `false` para forzar la descarga directa en lugar de la vista previa en navegador. |
| `ui.remember_last_dir` | `true` | Guarda el último **volumen + directorio** visitado en `localStorage` (si la carpeta fue eliminada, vuelve automáticamente a la carpeta superior o raíz). | Ponlo en `false` si cada sesión debe comenzar en el directorio raíz. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Solicita confirmación antes de eliminar o mover elementos mediante arrastrar y soltar. | Pon `confirm_move: false` para flujos de reorganización más ágiles. |
| `ui.double_click_to_select` | `true` | En modo selector Modal/OffCanvas, hacer doble clic en un archivo lo selecciona de inmediato y cierra el diálogo. | Ponlo en `false` si prefieres que el doble clic abra la vista previa en vez de seleccionar. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Activa los menús contextuales con clic derecho y la barra rápida de navegación `[ ← \| → \| ↑ \| ↻ ]` al hacer clic derecho en un espacio vacío. | Ponlo en `false` en pantallas cerradas para usuarios finales. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Activa el movimiento de elementos arrastrándolos entre carpetas y la subida arrastrando archivos/carpetas desde el sistema operativo. | Ponlo en `false` para bibliotecas de activos de solo lectura. |
| `ui.keyboard_shortcuts` | `true` | Habilita atajos de teclado (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Supr`, `Espacio`, `/`, `R`, `F`, `?`, etc.). | Desactívalo (`false`) si tu página anfitriona utiliza atajos globales en conflicto. |
| Indicadores `ui.show_*` | *(en su mayoría `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Activa o desactiva controles individuales de la barra de herramientas para simplificar la interfaz. *(Nota: las entradas «Acerca de» y «GitHub» dentro de Configuración son elementos de atribución integrados y no se desactivan por YAML.)* |

### 3.7. Caché por perfil (`cache`), seguridad (`security`), permisos (`permissions`), IA y protección de uso

#### Caché (`cache`)
| Parámetro | Por defecto | Descripción |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Activa o desactiva la caché para este perfil. Cuando un perfil establece `cache.enabled: false`, `volume.cache_ttl` no vuelve a activar la caché. |
| `cache.ttl` | `null` *(defaults)* | Orden de precedencia del TTL: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Seguridad (`security`)
| Parámetro | Por defecto | Descripción |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Activa la comprobación RBAC mediante `FileManagerVoter` y cualquier Voter personalizado. Mantenlo siempre en `true` en producción. |
| `security.csrf_protection` | `true` | Exige una cabecera `X-LiveUX-FM-CSRF` válida en cada solicitud `POST`. |
| `security.sanitize_svg` | `true` | Sanea los archivos SVG subidos o editados usando una lista blanca estricta de 55 etiquetas (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Habilita la pestaña **«Desde URL»** en el modal de subida (protegida contra SSRF y DNS rebinding por `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Sobrescribe el registro de auditoría PSR-3 para este perfil (`true` / `false` / `null`). |

#### 17 permisos granulares (`permissions`)
> [!WARNING]
> **Importante: ¿Qué significa `true` bajo `permissions`?**  
> Establecer un permiso en **`true`** **NO** significa *«acceso público / anónimo»*. `FileManagerVoter` concede `true` **únicamente a usuarios autenticados (`$token->getUser() !== null`)** y deniega solicitudes anónimas.  
> Además, **`permissions.view`** es la puerta maestra: si un usuario no tiene `view`, todas las demás acciones (`upload`, `delete`, `download`, etc.) se deniegan automáticamente.

Cada permiso acepta uno de estos 4 formatos:
- `true` → Cualquier usuario autenticado
- `false` → Nadie (acción completamente desactivada)
- `'ROLE_ADMIN'` → Solo usuarios con ese rol
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Usuarios con cualquiera de esos roles

| Clave de permiso | Por defecto | Acciones controladas |
| :--- | :--- | :--- |
| `view` | `true` | Listado de directorios, árbol de carpetas, vistas previas y **requisito previo para cualquier otro permiso**. |
| `upload` | `true` | Subidas estándar/por fragmentos, guardado desde el editor de imágenes e importación desde URL remota. |
| `create_folder` | `true` | Crear nuevas carpetas. |
| `rename` | `true` | Renombrar archivos y carpetas. |
| `clone` | `true` | Duplicar un archivo o carpeta. |
| `move` | `true` | Mover elementos mediante arrastrar y soltar o Cortar/Pegar. |
| `copy` | `true` | Copiar elementos mediante Copiar/Pegar o el diálogo Mover/Copiar. |
| `delete` | `true` | Eliminar archivos/carpetas (mover a la papelera o eliminar permanentemente). |
| `trash_restore` | `true` | Restaurar elementos desde la papelera. |
| `trash_purge` | `true` | Eliminar permanentemente elementos de la papelera o vaciar la papelera. |
| `download` | `true` | Descargar archivos individuales, reproducir PDF/video en streaming y copiar enlaces de archivos. |
| `download_zip` | `true` | Descargar múltiples elementos seleccionados en un archivo `.zip`. |
| `extract_zip` | `null` *(auto)* | Forzado a `false` mientras `zip.allow_extract: false`; pasa automáticamente a `true` cuando `zip.allow_extract: true` (restríngelo con `'ROLE_ADMIN'` si lo deseas). |
| `share` | `true` | Generar enlaces compartidos temporales firmados con HMAC. |
| `clear_cache` | `true` | Limpiar la caché de directorios desde Configuración y forzar `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Editar `alt`/`title`/`caption`/`tags`/punto focal de archivos y color/icono/fijado de carpetas. |
| `ai_enrich` | `true` | Invocar **✨ Sugerir con IA** (requiere `view` + `edit_metadata` + `ai_enrich`). |

#### Enriquecimiento con IA (`ai`) y protección de uso de activos (`usage_guard`)
| Parámetro | Por defecto | Descripción |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Activa las sugerencias de metadatos con IA; configurar `locale` controla el idioma de salida (p. ej., `'es'`, `'en'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Máximo de etiquetas sugeridas (`1`–`20`) y límite de reducción de imagen antes de enviarla a la IA (`256`–`2048` px, ahorrando tokens de visión). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Evita que archivos en volúmenes `private: true` se envíen a APIs externas de IA; enriquecimiento automático opcional tras subir (ver [Capítulo 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Advierte o bloquea operaciones **`delete`, `rename`, `move` y `overwrite`** cuando un activo está referenciado en tu base de datos (ver [Capítulo 10](10-asset-usage-guard.md)). |

---

## 4. 5 recetas de perfiles listas para producción (copiar y pegar)

Aquí tienes las 5 configuraciones reales más habituales listas para usar en tu proyecto:

### Receta 1: Catálogo de productos e-commerce (Tarjetas cuadradas + WebP + Reutilización de duplicados)
> **Objetivo:** Aceptar solo fotos nítidas cuadradas `1:1`; redimensionar y convertir automáticamente fotos de móvil a WebP; reutilizar archivos existentes ante subidas duplicadas; bloquear la eliminación accidental de activos vinculados a productos activos.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'es'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # Tarjetas grandes de producto de 204px
                card_aspect_ratio: '1/1' # Relación cuadrada de escaparate
                folder_style: 'card'     # Tarjetas de carpeta de tamaño completo por categoría
                show_folder_item_count: true
```

### Receta 2: Imágenes de portada de blog / noticias (Banners `16:9` + Texto alternativo con IA)
> **Objetivo:** Aceptar solo portadas panorámicas `16:9` de alta resolución (al menos `1200×630` px), organizar automáticamente las subidas en carpetas `2026/11` y generar texto alternativo SEO y etiquetas con IA.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Portadas más recientes primero
            ai:
                enabled: true
                locale: 'es'
                max_tags: 6
            ui:
                item_size: 'xl'           # Vistas previas anchas de portada de 260px
                card_aspect_ratio: '16/9' # Relación de aspecto de banner panorámico
```

### Receta 3: Bóveda de documentos y PDF de clientes (Supabase/S3 privado + Aislamiento por usuario)
> **Objetivo:** Aislar a cada cliente en sus propias facturas PDF dentro de un bucket privado compatible con S3, bloquear URLs directas externas, actualizar listados de la nube cada 60 segundos y registrar cada acción en auditoría.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Bóveda PDF de clientes'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Sin acceso directo por URL sin autenticación
            cache_ttl: 60          # Los cambios externos en la nube aparecen en 60 s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Cada usuario ve solo su propia carpeta
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Tabla ordenable ideal para PDFs
                item_size: 'sm'          # Filas compactas
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Receta 4: Selector rápido para editores de texto enriquecido (TinyMCE / CKEditor / Tiptap)
> **Objetivo:** Un selector modal ligero y sin distracciones donde hacer doble clic sobre una imagen la inserta de inmediato en el editor.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Receta 5: Biblioteca de solo lectura para socios / dossier de prensa
> **Objetivo:** Permitir que socios naveguen por logotipos y catálogos, descarguen archivos sueltos o ZIPs masivos y creen enlaces compartidos firmados, impidiendo cualquier subida, edición o eliminación.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 días
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Marcadores dinámicos de ruta (`DynamicPathResolver`)

Puedes usar marcadores dinámicos dentro de `path` local, `url_prefix`, `driver_options.prefix` en la nube y `upload_subfolder` del perfil:

| Marcador | Descripción | Ejemplo de salida |
| :--- | :--- | :--- |
| `{user_id}` | Identificador sin colisiones y seguro para el sistema de archivos del usuario Symfony autenticado (`guest` si no está autenticado). | `42` o `alice_a--h-9f1c2e4b...` |
| `{Y}` | Año en 4 dígitos | `2026` |
| `{m}` | Mes en 2 dígitos | `11` |
| `{d}` | Día en 2 dígitos | `25` |

```yaml
volumes:
    user_space:
        label: 'Mi espacio personal'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Aislamiento multiinquilino `{user_id}` sin colisiones:**  
> `DynamicPathResolver` genera claves de directorio **disjuntas con sufijo SHA-256 `<slug>--h-<16-hex>`** para correos electrónicos (`alice.a@example.com` frente a `alice_a@example.com`), variantes de mayúsculas/minúsculas (`Alice` frente a `alice`), nombres Unicode (árabe, chino, turco), palabras reservadas (`guest`, `user`, identificadores terminados en `--h-`) y nombres de dispositivos de Windows (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Dos usuarios distintos nunca podrán colisionar en la misma carpeta.
>
> **Comportamiento en CLI:** Los comandos de consola (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup`, etc.) omiten automáticamente los volúmenes que contienen `{user_id}` para no crear nunca una carpeta residual `guest/`.

---

## 6. Referencia completa del esquema YAML (todas las opciones activas)

El siguiente fragmento YAML documenta **cada clave de configuración activa** definida en `Configuration.php` junto con su valor por defecto y comentarios explicativos. En tu propio proyecto, declara solo las claves que quieras personalizar; todo lo demás hereda su valor por defecto automáticamente.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) VALORES GLOBALES — Heredados por todos los perfiles salvo sobrescritura
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Directorio raíz por defecto para perfiles abreviados de un solo disco
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <personalizado>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = locale de solicitud/traductor Symfony (12 idiomas integrados, respaldo 'en') | 'es' | 'en' | 'tr' | ...
        z_index: 1050                                      # z-index base para superposiciones, menús emergentes y modales (>= 1)
        auto_inject_dialog_host: true                      # Inyectar automáticamente DialogHost de Modal/OffCanvas en respuestas HTML completas
        dialog_host_profile: 'default'                     # Perfil por defecto para el DialogHost inyectado automáticamente

        audit_log:
            enabled: false                                 # Registrar las 9 mutaciones de archivos/carpetas en un canal PSR-3
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Modelo IA por defecto cuando no se sobrescribe en el perfil
            platform_service: null                         # ID de servicio PlatformInterface personalizado de Symfony AI (null = auto)

        cache:
            enabled: true                                  # Interruptor global de caché PSR-6 de directorios/árbol
            pool: 'cache.app'                              # ID del pool de caché PSR-6 de Symfony
            ttl: 3600                                      # TTL global de caché en segundos (0 = desactivado)

        metadata:
            enabled: true                                  # Colores/iconos/fijados de carpetas y alt/title/caption/tags/focalPoint de archivos
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Archivo de almacenamiento para el driver json_file
            connection: null                               # Nombre opcional de conexión DBAL para el driver doctrine (null = conexión de entity_manager)
            entity_manager: 'default'                      # EntityManager / nombre de conexión para el driver doctrine
            table: 'liveux_fm_metadata'                    # Nombre de tabla DBAL para el driver doctrine (ver Capítulo 13)
            auto_create_table: true                        # Crear tabla de metadatos automáticamente si falta (false si usas migraciones)

    # =========================================================================
    # 2) VOLÚMENES DE ALMACENAMIENTO — Definiciones locales y en la nube
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Almacenamiento local'                  # Nombre mostrado en barra lateral y ruta de navegación
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Ruta física (admite {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Prefijo de URL pública del navegador (dejar en null cuando private: true)
            private: false                                 # true = transmitir todos los archivos mediante DownloadController protegido por RBAC
            cache_ttl: null                                # TTL de caché por volumen en segundos (null = heredar, 0 = desactivado)
            driver_options: []                             # Vacío para LocalFileSystem

        cloud_s3_example:
            label: 'Cloud S3 / R2 / Supabase'
            icon: 'supabase'                               # Coincide con resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # URL pública del bucket/CDN (null cuando private: true)
            private: false
            cache_ttl: 60                                  # Refrescar cambios externos en la nube cada 60 segundos
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Nombre del bucket
                region: '%env(AWS_S3_REGION)%'             # Región ('auto' para Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # URL de endpoint S3 personalizado para proveedores que no sean AWS
                prefix: 'media/{Y}'                        # Prefijo raíz opcional dentro del bucket (admite marcadores dinámicos)
                use_path_style_endpoint: true              # true para Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # Access Key ID de S3
                secret: '%env(AWS_S3_SECRET)%'             # Secret Access Key de S3
                token: null                                # Token opcional de sesión temporal AWS STS
                version: 'latest'                          # Versión de la API AWS S3 (por defecto: 'latest')
                ca_bundle: null                            # Ruta opcional a bundle CA .pem personalizado (soluciona cURL error 60 en Windows)
                disable_acl: true                          # Automáticamente true en endpoints personalizados; poner en true para AWS S3 Bucket owner enforced
                retain_visibility: false                   # Automáticamente false en endpoints personalizados (evita enviar x-amz-acl)
                request_checksum_calculation: 'when_required'  # Compatibilidad AWS SDK >= 3.337 (auto para endpoints personalizados)
                response_checksum_validation: 'when_required'  # Compatibilidad AWS SDK >= 3.337 (auto para endpoints personalizados)

        custom_flysystem_example:
            label: 'Archivo SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # ID de servicio League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) PERFILES — Conjuntos de reglas independientes por caso de uso
    # =========================================================================
    profiles:
        full_reference:
            # --- Volúmenes montados, idioma y opciones abreviadas de disco único ---
            locale: null                                   # null = heredar defaults.locale | 'es' | 'en' | 'tr' | 'fr' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Si está vacío, se crea un volumen implícito a partir de driver/path abajo
            driver: 'LocalFileSystem'                      # Driver abreviado usado solo cuando volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Ruta abreviada usada solo cuando volumes: []
            url_prefix: '/uploads'                         # Prefijo URL abreviado usado solo cuando volumes: []
            private: false                                 # Indicador privado abreviado usado solo cuando volumes: []
            driver_options: []                             # Opciones de driver abreviadas usadas solo cuando volumes: []

            # --- Listado, ocultación y ordenación por defecto ---
            ignore_dot_files: true                         # Ocultar y bloquear archivos con punto (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Patrones regex/comodín ocultos de listados y acceso directo
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Listar siempre carpetas antes que archivos

            # --- Subidas, cuota y manejo de colisiones ---
            upload_subfolder: null                         # p. ej. '{Y}/{m}' — ubicar subidas en subcarpetas dinámicas
            upload_allow: []                               # Vacío = todos los tipos seguros; no vacío = lista blanca MIME estricta
            upload_deny:                                   # Tipos MIME peligrosos siempre bloqueados (47 por defecto si se omite)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Tamaño máximo ensamblado para un archivo (p. ej. '50M', '2G')
            storage_quota: null                            # Cuota total de disco del perfil (p. ej. '500M', '5G'; null = ilimitado)
            overwrite_policy: 'rename'                     # rename (añade -1) | overwrite | reject

            # --- Subidas por fragmentos (supera upload_max_filesize de PHP) ---
            chunk_upload:
                enabled: true                              # Dividir archivos grandes en fragmentos reanudables
                chunk_size: '2M'                           # Tamaño objetivo de fragmento (limitado automáticamente al 80% de límites PHP)
                parallel_uploads: 3                        # Máximo de subidas concurrentes (1..10)
                max_retries: 3                             # Reintentos automáticos por fragmento ante fallos de red (0..10)

            # --- Detección de duplicados (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Detectar contenido duplicado al subir
                policy: 'warn'                             # warn | reject | reuse (devolver archivo existente)
                max_scan_files: 5000                       # Máximo de archivos indexados para duplicados (100..50000)

            # --- Normalización automática de nombres de archivo ---
            naming:
                slugger: true                              # Transliterar nombres Unicode a slugs ASCII limpios
                slugger_locale: null                       # null = heredar idioma del perfil (p. ej. 'es', 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Prefijo estático/dinámico (p. ej. 'producto-')
                append: ''                                 # Sufijo estático/dinámico antes de la extensión (p. ej. '-{Y}{m}{d}')
                apply_on_rename: true                      # Aplicar reglas de slugger/transformer al renombrar
                apply_affixes_on_rename: false             # Volver a aplicar prefijos/sufijos al renombrar
                allow_extension_change: false              # Permitir cambiar extensiones (.jpg -> .php) al renombrar

            # --- Optimización de imágenes al subir ---
            image_optimization:
                enabled: false                             # Auto-orientar, eliminar EXIF, redimensionar y comprimir al subir
                auto_orient: true                          # Corregir orientación EXIF de teléfonos
                strip_exif: true                           # Eliminar GPS y EXIF de cámara por privacidad y ahorro de espacio
                max_width: 2400                            # Ancho máximo proporcional en px (>= 100)
                max_height: 2400                           # Alto máximo proporcional en px (>= 100)
                convert_to_webp: false                     # Convertir automáticamente subidas JPEG/PNG a WebP
                quality: 85                                # Calidad de salida (10..100)

            # --- Restricciones de resolución y relación de aspecto de imágenes ---
            image_constraints:
                min_width: null                            # Ancho mínimo en px (p. ej. 800)
                min_height: null                           # Alto mínimo en px (p. ej. 600)
                max_width: null                            # Ancho máximo en px (comprobado tras image_optimization)
                max_height: null                           # Alto máximo en px
                aspect_ratio: null                         # Proporción requerida (p. ej. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Desviación permitida de proporción (0.0..0.5; 0.05 = 5%)

            # --- Motor de miniaturas ---
            thumbnail:
                enabled: true                              # Generar vistas previas en caché para tarjetas de imagen
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Ancho de miniatura en px (>= 32)
                height: 240                                # Alto de miniatura en px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Calidad de compresión (10..100)
                fit: 'contain'                             # contain (encajar dentro) | cover (recortar para rellenar)
                lazy_load: true                            # Añadir loading="lazy" a las miniaturas de las tarjetas
                cache_dir: '%kernel.project_dir%/public/media-cache' # Directorio local de caché para miniaturas generadas

            # --- Papelera (eliminación temporal) ---
            trash:
                enabled: true                              # Mover elementos eliminados a la papelera en vez de eliminar permanentemente
                directory: '.trash'                        # Nombre del directorio oculto de papelera
                retention_days: 30                         # Período de retención en días para file-manager:trash:purge
                max_size: '500M'                           # Tamaño total máximo de la papelera

            # --- Descarga ZIP y extracción segura de archivos ---
            zip:
                enabled: true                              # Activar descarga ZIP de varios archivos/carpetas
                max_files: 100                             # Máximo de archivos en una descarga ZIP
                max_total_size: '250M'                     # Tamaño total máximo de una descarga ZIP
                allow_extract: false                       # Activar descompresión (desactivado por defecto; protegido en 7 capas)
                max_extract_files: 200                     # Máximo de archivos extraídos por archivo comprimido (1..2000)
                max_extract_size: '100M'                   # Tamaño total máximo extraído (protección contra bombas ZIP)
                max_compression_ratio: 100                 # Relación máxima de compresión permitida (1..1000 — protección contra bombas ZIP)

            # --- Enlaces compartidos temporales firmados (HMAC) ---
            share:
                enabled: true                              # Permitir generar URLs compartidas firmadas
                default_ttl: 86400                         # Expiración por defecto en segundos (86400 = 24 horas)
                max_ttl: 2592000                           # Expiración máxima seleccionable en segundos (2592000 = 30 días)
                allow_inline_preview: true                 # Permitir vista previa en el navegador para tipos MIME seguros

            # --- Enriquecimiento opcional con Symfony AI ---
            ai:
                enabled: false                             # Mostrar «✨ Sugerir con IA» en el modal de metadatos
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = locale del perfil/petición (p. ej. 'es' => texto alt/etiquetas en español)
                max_tags: 6                                # Máximo de etiquetas sugeridas (1..20)
                max_image_dimension: 1024                  # Límite de reducción antes de enviar a la IA (256..2048 px)
                block_private_volumes: true                # true = bloquear llamadas IA en volúmenes private: true (por defecto: true)
                auto_enrich_on_upload: false               # Ejecutar enriquecimiento IA automáticamente tras subir

            # --- Protección de uso de activos (Delete / Rename / Move / Overwrite) ---
            usage_guard:
                enabled: false                             # Comprobar referencias en BD antes de mutar activos
                policy: 'warn'                             # warn (mostrar referencias + permitir continuar) | block (prohibir acción)
                enforce_server_side: true                  # Aplicar también en llamadas directas a la API
                max_references: 20                         # Máximo de referencias listadas en el modal (1..200)

            # --- Caché por perfil ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | segundos (0 = desactivado)

            # --- Opciones de seguridad ---
            security:
                voter_enabled: true                        # Activar comprobaciones RBAC de FileManagerVoter
                csrf_protection: true                      # Exigir X-LiveUX-FM-CSRF en solicitudes POST
                sanitize_svg: true                         # Sanear SVGs subidos/editados
                allow_remote_url_import: false             # Activar pestaña «Desde URL» (protegida contra SSRF)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 permisos granulares RBAC (true = usuario autenticado | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Permiso maestro obligatorio para listar y cualquier otra acción
                upload: true                               # Subidas de archivos, guardado del editor de imágenes e importación por URL
                create_folder: true                        # Crear nuevas carpetas
                rename: true                               # Renombrar archivos y carpetas
                clone: true                                # Duplicar archivos/carpetas
                move: true                                 # Mover elementos (incluidos arrastrar/soltar y cortar/pegar)
                copy: true                                 # Copiar elementos (incluido copiar/pegar)
                delete: true                               # Eliminar elementos (papelera o eliminación permanente)
                trash_restore: true                        # Restaurar elementos desde la papelera
                trash_purge: true                          # Purgar permanentemente elementos / vaciar papelera
                download: true                             # Descarga de archivo único y copiar enlace
                download_zip: true                         # Descarga ZIP de múltiples archivos
                extract_zip: false                         # Descomprimir archivos ZIP (requiere también zip.allow_extract: true)
                share: true                                # Crear enlaces compartidos firmados
                clear_cache: true                          # Limpiar caché desde Configuración y forzar ?refresh=1
                edit_metadata: true                        # Editar alt/etiquetas/punto focal de archivo y color/icono/fijado de carpeta
                ai_enrich: true                            # Ejecutar enriquecimiento de metadatos con IA

            # --- Interfaz de usuario (UI): indicadores y tamaños ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <dir personalizado>
                z_index: null                              # null = defaults.z_index | entero (>= 1)
                mobile_dialog_mode: 'auto'                 # Comportamiento del diálogo en móvil (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px compacto)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (fila compacta) | card (tarjeta de carpeta de tamaño completo)
                show_size_slider: true                     # Mostrar deslizador de tamaño en vivo y selector de estilo de carpetas en Configuración
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Mostrar barra lateral izquierda del árbol de carpetas
                tree_lines: true                           # Mostrar líneas de conexión de jerarquía en el árbol de carpetas
                show_folder_item_count: true               # Mostrar insignias de recuento de elementos en las carpetas
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Mostrar barra de cuota lateral e insignia de cuota en cabecera
                show_quota_details: true                   # Activar modal clicable de Uso de almacenamiento y cuota
                per_page: 24                               # Elementos por página por defecto (6..200)
                per_page_options: [24, 48, 96]             # Opciones del desplegable de elementos por página
                show_perf_badge: false                     # Mostrar insignia de rendimiento CACHÉ / DISCO en la barra de herramientas
                show_nav_buttons: true                     # Interruptor maestro del grupo Atrás / Adelante / Carpeta superior / Actualizar
                show_history_buttons: true                 # Mostrar botones de historial Atrás / Adelante
                show_up_button: true                       # Mostrar botón de subir un nivel (Carpeta superior)
                show_refresh_button: true                  # Mostrar botón Actualizar (?refresh=1)
                show_breadcrumbs: true                     # Mostrar ruta de navegación clicable y receptora de arrastre
                show_search: true                          # Mostrar cuadro de búsqueda e interruptor de búsqueda profunda
                show_type_filter: true                     # Mostrar filtro por tipo (Imágenes / Videos / Archivos / Carpetas)
                show_view_toggle: true                     # Mostrar alternador Cuadrícula / Lista
                show_pagination: true                      # Mostrar barra inferior de paginación
                show_per_page_selector: true               # Mostrar selector de elementos por página
                show_sort: true                            # Mostrar desplegable de ordenación y sentido
                show_fullscreen_button: true               # Mostrar botón de pantalla completa
                show_settings_button: true                 # Mostrar menú desplegable Configuración (⚙)
                show_footer: true                          # Mostrar barra de estado inferior
                show_context_nav: true                     # Mostrar barra rápida de navegación en el menú contextual de espacio vacío
                allow_dropzone_upload: true                # Permitir arrastrar archivos/carpetas desde el SO al listado de archivos
                remember_last_dir: true                    # Recordar último volumen y carpeta (se autorrepara si se elimina)
                confirm_delete: true                       # Mostrar modal de confirmación antes de eliminar
                confirm_move: true                         # Mostrar modal de confirmación antes de mover/copiar
                double_click_to_select: true               # Doble clic selecciona el archivo y cierra el selector Modal/OffCanvas
                allow_context_menu: true                   # Habilitar menú contextual con clic derecho
                allow_pdf_preview: true                    # Lightbox PDF en línea (false fuerza la descarga)
                allow_video_preview: true                  # Reproductor de video en línea (false fuerza la descarga)
                keyboard_shortcuts: true                   # Habilitar atajos de teclado acotados al componente
                allow_drag_move: true                      # Permitir arrastrar elementos a carpetas para moverlos
```

---

[← Anterior: 01 — Requisitos e instalación](01-requirements-and-installation.md) | [Índice de documentación](README.md) | [Siguiente: 03 — Modos de uso y API JS →](03-usage-modes-and-js-api.md)
