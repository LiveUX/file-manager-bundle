# 08 — Eventos, comandos CLI, caché, traducciones y resolución de problemas (FAQ)

[← Anterior: 07 — Almacenamiento en la nube y configuración dinámica](07-cloud-storage-and-dynamic-config.md) | [Índice principal](README.md) | [Siguiente: 09 — Integración con Symfony AI →](09-symfony-ai-integration.md)

> [!TIP]
> **De un vistazo: ¿para qué sirven los eventos y los comandos CLI?**
> - **Eventos:** notifican automáticamente a tu aplicación Symfony cada vez que un usuario sube, elimina, renombra o mueve un archivo —por ejemplo: *"cuando se renombra un archivo, actualiza automáticamente la ruta de la imagen del producto en la base de datos"*.
> - **Comandos CLI (`php bin/console file-manager:...`):** herramientas de terminal integradas para ejecutar comprobaciones de estado (`doctor`), invalidar cachés (`cache:clear`), pregenerar miniaturas (`thumbs:warmup`) o purgar la papelera caducada (`trash:purge`).

---

## 1. Eventos de Symfony (9 eventos de dominio)

Todos los eventos del gestor de archivos heredan de la clase base común **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Escúchalos para mantener sincronizada tu base de datos, registrar auditorías, purgar cachés de CDN o despachar tareas en segundo plano.

| Clase de evento (`LiveUX\FileManagerBundle\Event\*`) | Cuándo se dispara | Propiedades |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Al subir un archivo, importarlo desde URL, guardarlo desde el editor de imágenes o extraerlo de un ZIP | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Al eliminar un archivo (enviado a la papelera o borrado permanentemente) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Al crear una carpeta (incluso durante la extracción de un archivo ZIP) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Al eliminar una carpeta | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Al renombrar un archivo o carpeta | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Al mover o copiar un elemento (incluidas transferencias entre volúmenes) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Al duplicar o clonar un elemento | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Al restaurar un elemento desde la papelera | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Al purgar permanentemente uno o más elementos de la papelera | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Ejemplo de listener de eventos (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('Nuevo activo multimedia subido.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Comandos de consola (CLI) (10 comandos integrados)

| Comando | Opciones / Alias | Descripción |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Comprueba la versión de PHP, `upload_max_filesize`, `post_max_size`, cURL CA Bundle, GD/Imagick, EXIF, WebP, `ext-zip` y cada configuración de perfil/volumen. Añade `--check-remote` para ejecutar pruebas de conectividad en vivo contra S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Limpia las cachés de directorios y cuotas de todos los perfiles o de un perfil/volumen/ruta concretos (`--volume`/`--path` requieren el argumento `profile`; nunca borra claves ajenas de `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Alias: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Pregenera miniaturas de imágenes con antelación para que los editores no esperen en la primera carga. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Limpia las miniaturas en caché. `--orphans` elimina únicamente las miniaturas cuyo archivo original ya no existe (no puede combinarse con `--volume`/`--path`, y protege automáticamente los directorios compartidos con `{user_id}` / `{Y}/{m}/{d}`). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Optimiza retroactivamente imágenes existentes (límite de seguridad/memoria: `25 MB` máximo por imagen). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Detecta archivos duplicados mediante hashes SHA-256 del contenido e informa del espacio recuperable en disco. |
| `file-manager:stats [profile]` | `--json` | Muestra uso de almacenamiento, recuento de archivos/carpetas, consumo de cuota, miniaturas y estadísticas de la papelera. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(por defecto `50`)*, `--dry-run` | Genera en lote textos `alt`, títulos, leyendas y etiquetas faltantes mediante IA. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = purgar todo)*, `--dry-run` | Elimina permanentemente los elementos caducados de la papelera. Si varios perfiles comparten un directorio físico, respeta automáticamente el valor de `retention_days` más largo. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Instala las 6 AI Agent Skills del bundle en `.agents/skills`, `.claude/skills`, `.cursor/skills` o `.github/skills`. |

> [!NOTE]
> **Protección de marcadores dinámicos `{user_id}` y `{Y}/{m}/{d}` en comandos CLI**
> Como en modo CLI no existe una sesión de usuario autenticada, todos los comandos de escaneo y limpieza (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **omiten automáticamente** los volúmenes que contienen `{user_id}` (evitando la creación accidental de una carpeta vacía `guest/`). En volúmenes con marcadores de fecha `{Y}/{m}/{d}`, solo se escanea el periodo de fecha actual y se muestra un aviso informativo.

### Ejemplo de Cron para limpieza automática de la papelera

```cron
# Purga los elementos caducados de la papelera cada noche a las 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Rendimiento, arquitectura de caché y Symfony Profiler

- **TTL de caché en 3 niveles y actualización manual (`↻`):** el TTL de listado de directorios se resuelve en este orden: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` desactiva la caché para ese volumen o perfil). Al pulsar **Actualizar (`↻`)** en la barra de herramientas o la tecla `R` se envía `?refresh=1` *(saltarse la caché requiere el permiso `CLEAR_CACHE`)*.
- **Pool de caché dedicado y huella de perfil:** apunta `defaults.cache.pool` a un pool dedicado de Redis/APCu (si existe `<pool>.taggable`, se utiliza automáticamente). Sin caché etiquetable, `DirectoryCacheManager` emplea claves de contador de generación de 30 días y nunca invoca `$cachePool->clear()` sobre la caché principal de tu aplicación. Cuando dos perfiles comparten la misma carpeta física con reglas distintas (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), sus cachés permanecen estrictamente aisladas mediante `buildProfilePolicyFingerprint()`.
- **Sincronización por clave canónica:** si dos perfiles apuntan al mismo directorio físico, subir o modificar un archivo en uno de ellos invalida automáticamente la caché del otro perfil.
- **Recuperación automática de `remember_last_dir`:** si el último directorio visitado por el usuario fue eliminado externamente (por FTP o desde la consola de S3), el gestor de archivos sube de forma segura a la carpeta padre existente más cercana o a la raíz en lugar de fallar.
- **HTTP 304 y caché de miniaturas sensible al contexto (`ThumbnailController`):**
  - **Contexto privado / autenticado** (volumen `private: true`, volumen dinámico con `{user_id}`, perfil protegido por Voter, cabecera `Authorization` o presencia de cookie de sesión): envía `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` para evitar filtraciones en CDN compartidas.
  - **Contexto público:**
    - Con el parámetro de invalidación `?v=<version>` → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 días).
    - Sin `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 hora + `ETag` / `304 Not Modified`).
    - Cuando `cache.enabled: false` en el perfil → `Cache-Control: public, no-cache, must-revalidate`.
  - Además, todas las respuestas de miniaturas incluyen `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` y `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** en entorno `dev`, el panel de profiler **FileManager** muestra el perfil activo, el volumen, el driver de miniaturas y las estadísticas de aciertos/fallos de caché.

---

## 4. Internacionalización global (`493` claves), BCP-47 / POSIX / RTL e idiomas personalizados

LiveUX FileManagerBundle incluye de serie **12 idiomas de interfaz integrados** —**inglés (`en` — respaldo garantizado), turco (`tr`), francés (`fr`), alemán (`de`), español (`es`), árabe (`ar`), italiano (`it`), portugués (`pt`), neerlandés (`nl`), polaco (`pl`), japonés (`ja`) y chino simplificado (`zh_CN`)**— con paridad completa en las **493 claves de traducción**.

- **Soporte BCP-47 y POSIX `.UTF-8`:** `MessageTranslator` resuelve automáticamente cadenas regionales y POSIX como `tr_TR.UTF-8`, `de_DE@euro` y `zh-Hans-CN` (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` genera códigos BCP-47 válidos para los atributos HTML `lang`.
- **Detección automática de escritura de derecha a izquierda (RTL):** se detectan automáticamente **15 idiomas y sistemas de escritura RTL** —incluyendo árabe (`ar`), persa (`fa`), hebreo (`he`), urdu (`ur`), pastún (`ps`) y kurdo central (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa`, etc.)— cambiando el diseño del componente a `dir="rtl"`.
- **Lista blanca de catálogos en tiempo de compilación:** `FileManagerBundle` solo acepta locales que correspondan a catálogos de traducción reales, evitando el crecimiento descontrolado de la caché u OPcache (DoS) por parámetros de locale falsificados.
- **Añadir un nuevo idioma (p. ej., sueco `sv`) o sobrescrituras parciales:**
  Solo tienes que crear `translations/LiveUXFileManagerBundle.sv.yaml` en tu proyecto (o sobrescribir claves concretas en un idioma existente como `LiveUXFileManagerBundle.es.yaml`). Cualquier clave omitida recurre automáticamente al inglés (`en`), por lo que los usuarios nunca verán claves sin traducir como `ui.upload`. Puedes copiar el archivo `translations/LiveUXFileManagerBundle.en.yaml` del bundle como plantilla completa de partida.

---

## 5. Preguntas frecuentes y resolución de problemas (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Confirma que `assets/app.js` incluye `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- Comprueba que `defaults.auto_inject_dialog_host: true` esté activado (si lo desactivaste, incluye manualmente `DialogHost.html.twig` en `base.html.twig`).
- Ejecuta `php bin/console cache:clear` y haz una recarga forzada en el navegador (`Ctrl+Shift+R`).

### 2. Todas las solicitudes POST devuelven `403 Invalid or missing CSRF token`
- Asegúrate de tener instalado `symfony/security-csrf` y de que tanto `framework.csrf_protection: true` como `framework.session.enabled: true` estén activos.
- Si envías peticiones `fetch()` personalizadas directamente, incluye la cabecera `X-LiveUX-FM-CSRF`.

### 3. Recibo `403 Access denied`
- Revisa el bloque `permissions` de tu perfil en `liveux_file_manager.yaml`. Recuerda: incluso establecer un permiso en `true` requiere un usuario autenticado, y `permissions.view` es obligatorio para todas las demás acciones.
- Si implementaste un Symfony Voter personalizado, verifica `access_decision_manager.strategy` (p. ej., `unanimous` frente a `affirmative`) en `config/packages/security.yaml`.

### 4. Fallan las subidas de archivos grandes (`upload_max_filesize`)
- Con `chunk_upload.enabled: true`, el tamaño de fragmento se ajusta automáticamente al `80%` de los límites `upload_max_filesize`/`post_max_size` de PHP (p. ej., fragmentos de `1.60 MB` con un límite PHP de `2M`, hasta 2.000 fragmentos).
- El tamaño total máximo permitido por archivo se controla mediante `upload_max_size` en `liveux_file_manager.yaml` (p. ej., establece `upload_max_size: '100M'` para videos de 100 MB).

### 5. Descargar un archivo rompe la página (Symfony UX Turbo Drive)
- Todos los enlaces de descarga integrados ya incluyen `data-turbo="false" download`. Si sobrescribes plantillas Twig, asegúrate de que tus etiquetas `<a>` de descarga personalizadas conserven `data-turbo="false"`.

### 6. No se generan miniaturas; solo se sirven las imágenes originales
- Ejecuta `php bin/console file-manager:doctor` y verifica que la fila `Thumbnail Driver` muestre `gd` o `imagick` como activo.
- Comprueba que `thumbnail.cache_dir` (`public/media-cache`) tenga permisos de escritura para el usuario del servidor web.

### 7. `cURL error 60: SSL certificate problem` en Windows al conectar con S3 / Supabase
- Descarga un paquete `cacert.pem` actualizado y configura `curl.cainfo` y `openssl.cafile` en `php.ini`, o especifica `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` en el volumen S3.

### 8. Los cambios de frontend no se reflejan en el navegador
- **Desarrollo:** ejecuta `php bin/console cache:clear` y haz una recarga forzada (`Ctrl+Shift+R`).
- **Producción (AssetMapper):** ejecuta `php bin/console asset-map:compile`.

---

[← Anterior: 07 — Almacenamiento en la nube y configuración dinámica](07-cloud-storage-and-dynamic-config.md) | [Índice principal](README.md) | [Siguiente: 09 — Integración con Symfony AI →](09-symfony-ai-integration.md)
