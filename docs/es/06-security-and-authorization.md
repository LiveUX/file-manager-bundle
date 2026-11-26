# 06 — Seguridad y autorización (RBAC y defensa en profundidad)

[← Anterior: 05 — FormType e integraciones con editores](05-formtype-and-editor-integrations.md) | [Índice](README.md) | [Siguiente: 07 — Almacenamiento en la nube y configuración dinámica →](07-cloud-storage-and-dynamic-config.md)

Los gestores de archivos son una de las superficies de ataque de mayor riesgo en cualquier aplicación web. LiveUX FileManagerBundle está diseñado siguiendo una arquitectura de **defensa en profundidad** con múltiples capas de protección activas por defecto.

> **De un vistazo: cómo blindar la seguridad en 3 pasos**  
> 1. **Protege las rutas (`security.yaml`):** Impide que visitantes sin autenticar accedan a los endpoints del gestor de archivos.  
> 2. **Define permisos por rol (`permissions`):** Declara explícitamente en cada perfil quién puede ver (`view`), subir (`upload`) y eliminar (`delete`).  
> 3. **Añade un `prefix` de ruta en aplicaciones con múltiples firewalls:** Si tu app usa firewalls separados para el panel de administración (`/admin`) y los usuarios públicos (`/`), monta las rutas del bundle bajo `prefix: /admin` en `config/routes/liveux_file_manager.yaml` para resolver correctamente la sesión administrativa y evitar que usuarios normales accedan a archivos de administración.

---

## 1. Protección de rutas en `security.yaml` (primer paso)

En producción, protege siempre todos los endpoints bajo `/_liveux-file-manager` detrás del firewall de Symfony:

```yaml
# config/packages/security.yaml (aplicaciones con un único firewall)
security:
    access_control:
        # Deja esta ruta pública únicamente si usas enlaces compartidos firmados para destinatarios externos:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Si tienes dos firewalls separados —por ejemplo, una zona de administración (`/admin`) y miembros del sitio (`/`):**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Autorización por perfil (`permissions` y `FileManagerVoter`)

Cada perfil permite definir reglas de acceso independientes para **17 operaciones distintas**.

### ¿Qué significa cada valor de permiso?
- **`true`:** **Solo usuarios autenticados (`$token->getUser() !== null`)**. *(NUNCA concede acceso a visitantes anónimos).*
- **`false`:** Nadie (la operación queda totalmente deshabilitada).
- **`'ROLE_ADMIN'`:** Solo usuarios que posean ese rol específico.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Usuarios que posean cualquiera de los roles listados.

### Las 4 reglas de oro de autorización (aplicadas automáticamente)
1. **`permissions.view` es la llave maestra:** Si un usuario carece del permiso `view`, se deniegan automáticamente `upload`, `delete`, `download` y cualquier otra operación.
2. **Cierre seguro (*Fail-Closed* en `FileManagerAuthorizer`):** Cuando `security.voter_enabled: true` está configurado pero no está instalado `AuthorizationCheckerInterface` (es decir, `symfony/security-bundle`), el bundle **deniega el acceso de forma segura (`AccessDeniedException`)** en lugar de quedar abierto.
3. **Verificación de toda la cadena de carpetas ancestro (`isPathAndAncestorsGranted`):** Cuando un usuario accede a `confidential/2026/invoice.pdf`, tus Voters personalizados se evalúan automáticamente no solo para `confidential/2026/invoice.pdf`, sino también para sus carpetas padre **`confidential`** y **`confidential/2026`**, evitando que adivinar una URL permita eludir carpetas restringidas.
4. **Doble bloqueo en la extracción ZIP (`zip.allow_extract`):** Mientras `zip.allow_extract: false` esté activo, la descompresión de archivos ZIP se rechaza en el servidor con independencia de `permissions.extract_zip`.

### Referencia completa de los 17 permisos del Voter

| Clave YAML | Constante del Voter (`FileManagerPermissions::*`) | Operaciones protegidas |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Listado de directorios, árbol de carpetas, miniaturas, vistas previas en línea, diálogos y **prerrequisito para el resto de permisos** |
| `upload` | `FILE_MANAGER_UPLOAD` | Subida de archivos, subidas por fragmentos, importación desde URL remota y guardado del editor de imágenes |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Creación de nuevas carpetas |
| `rename` | `FILE_MANAGER_RENAME` | Renombrado de archivos y carpetas |
| `clone` | `FILE_MANAGER_CLONE` | Clonación/duplicado de archivos y carpetas |
| `move` | `FILE_MANAGER_MOVE` | Mover elementos (arrastrar y soltar y modal Mover; se comprueban tanto la ruta de origen como la de destino) |
| `copy` | `FILE_MANAGER_COPY` | Copiar elementos (se comprueban tanto la ruta de origen como la de destino) |
| `delete` | `FILE_MANAGER_DELETE` | Eliminación de elementos (papelera o borrado permanente) y consultas `checkUsage` |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Restauración de elementos desde la papelera |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Borrado permanente de elementos o vaciado de la papelera |
| `download` | `FILE_MANAGER_DOWNLOAD` | Descarga de un solo archivo, transmisión de PDF/vídeo y copiado de URLs |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Descarga de archivos ZIP con múltiples elementos (se verifica individualmente cada ruta seleccionada) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Extracción segura de ZIP (requiere además `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Generación de enlaces compartidos temporales firmados con HMAC (requiere además `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Limpieza de caché de directorios/perfil/miniaturas y omisión de caché con `?refresh=1` y `?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Actualización de colores/iconos/fijado de carpetas y texto alternativo, títulos, etiquetas y puntos focales de archivos |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | Sugerencias de metadatos con IA (requiere `view` + `edit_metadata` + `ai.enabled: true`) |

### Ejemplo de configuración de perfil corporativo

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **Permisos reflejados en la interfaz:** Los botones de acción para los que un usuario no está autorizado (por ejemplo, el botón Eliminar para un `ROLE_EDITOR` cuando `delete: 'ROLE_ADMIN'`) se ocultan automáticamente en la interfaz **y** se bloquean estrictamente con `HTTP 403 Forbidden` si se invocan directamente por API.

---

## 3. Escribir un Symfony Voter personalizado (reglas granulares por carpeta y usuario)

Cuando los roles en YAML no bastan —por ejemplo, *"impedir que los usuarios eliminen la carpeta `locked/` o muevan archivos hacia `locked/`"*— escribe tu propio Symfony Voter.

> [!WARNING]
> **El error más común al empezar: `affirmative` frente a `unanimous`**  
> La estrategia por defecto de `access_decision_manager.strategy` en Symfony es **`affirmative`** (basta con que un solo Voter devuelva `true` para conceder acceso). Eso significa que si el `FileManagerVoter` integrado devuelve `true` para un usuario `ROLE_ADMIN`, el voto **`false` (denegar)** de tu Voter personalizado **SE IGNORA** y el usuario podrá borrar el archivo igualmente.  
>  
> Para garantizar que las reglas de **denegación** de tu Voter personalizado se cumplan de verdad, establece la estrategia en **`unanimous`** dentro de `config/packages/security.yaml` (todos los Voters participantes deben aprobar; un único `false` bloquea la acción):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(El bundle detecta automáticamente los Voters personalizados de la aplicación mediante `DetectExternalVotersPass` y aplica el filtrado por elemento en el árbol de carpetas y los contadores de archivos para mantener tanto la privacidad como la precisión).*

### Propiedades de `FileManagerSubject`

El `$subject` que recibe el método `voteOnAttribute()` de tu Voter personalizado expone 4 propiedades de solo lectura:

| Propiedad | Tipo | Descripción | Ejemplo |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Configuración del perfil activo (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Ruta relativa del archivo o carpeta de origen desde la raíz del volumen | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Nombre del volumen activo | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Ruta de destino** en operaciones `MOVE`, `COPY`, `RENAME` o `CLONE` (`null` en el resto) | `'locked/new-name.pdf'` |

### Ejemplo de Voter personalizado (proteger la carpeta `locked/` contra borrados, renombrados y traslados)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) Bloquear la eliminación, traslado o renombrado de "locked" o cualquier elemento dentro de ella
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Bloquear también mover o renombrar archivos HACIA la carpeta "locked"
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 capas integradas de defensa en profundidad (blindadas frente a ataques reales)

| # | Vector de ataque | Protección del bundle |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Toda petición `POST` de escritura, borrado, subida, ZIP y editor de imágenes valida la cabecera `X-LiveUX-FM-CSRF` **antes** de procesar cualquier dato de entrada (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode y trucos del SO** | `PathNormalizer` bloquea `../`, `..\`, bytes nulos (`\0`), caracteres de control, `C:\`, envoltorios de flujo `phar://`/`php://`, puntos o espacios finales, caracteres Unicode de ancho cero o bidireccionales, puntos CJK (`。`), flujos alternativos NTFS (`file.txt:stream`) y nombres de dispositivo de Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), imponiendo un máximo de **32 niveles de profundidad**, **255 bytes por segmento** y **1024 caracteres UTF-8 / 3072 bytes** de longitud total de ruta. |
| 3 | **Archivos ejecutables, políglotas y doble extensión** | `FileSecurityGuard` aplica **69 extensiones bloqueadas** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, etc.), **47 tipos MIME bloqueados**, comprobaciones de doble extensión (`shell.php.jpg`) y validación de **políglotas por *magic bytes***, de forma estricta en subidas, renombrados (incluso con `allow_extension_change: true`) y descargas. |
| 4 | **Acceso directo a carpetas ocultas y papelera** | Las rutas bajo `.trash`, `.env`, `.git`, `.htaccess` y `exclude_patterns` se rechazan en todos los endpoints; `ZipDownloadController` realiza una comprobación individual del Voter `DOWNLOAD_ZIP` en **cada ruta seleccionada**. |
| 5 | **XSS en SVG, XXE y bombas DoS de `<use>`** | `SvgSanitizer` rechaza `<!DOCTYPE`/`<!ENTITY`, impone una **lista blanca estricta de 55 etiquetas**, limita el tamaño SVG a **5 MB**, filtra sintaxis CSS (`@import`, `expression()`, `url(javascript:)`), elimina `<script>`/`<foreignObject>`/`on*`/`javascript:` y acota ataques DoS de expansión `<use>` (máximo `100` `<use>`, profundidad `4`, `1000` nodos expandidos, `5000` pasos de análisis y `8` IDs duplicados). |
| 6 | **SSRF y DNS Rebinding (importación por URL remota)** | `RemoteUrlImporter` está deshabilitado por defecto (`allow_remote_url_import: false`). Cuando se activa, valida IPs mediante `inet_pton()` para bloquear *loopback*, rangos privados, `0.0.0.0`, CGNAT `100.64.0.0/10`, metadatos de nube (`169.254.169.254`), IPv6 mapeado a IPv4 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 y evasiones IDNA UTS-46 / ancho completo / octal / hexadecimal, mientras que el **fijado de IP con `CURLOPT_RESOLVE`** elimina los ataques de *DNS rebinding*. |
| 7 | **RCE por archivos políglotas en el editor de imágenes** | `ImageEditorController` restringe `overwrite` a archivos raster seguros ya existentes (`jpg/jpeg/png/webp`), y aplica `upload_max_size`, las reglas `upload_allow`/`upload_deny` del perfil, comprobaciones del protector de uso y validación real con `getimagesizefromstring()`. |
| 8 | **Bombas de descompresión (píxeles) e ImageTragick** | `GdThumbnailDriver` e `ImagickThumbnailDriver` inspeccionan dimensiones mediante `getimagesizefromstring()` antes de decodificar en memoria (imponiendo un máximo de `8192×8192` y `40 MP`), y aplican límites estrictos de memoria, tiempo e hilos en Imagick. |
| 9 | **Escape mediante enlaces simbólicos (*symlinks*) y confinamiento** | `ConfinedLocalFilesystemAdapter` verifica `is_link()` + confinamiento de raíz con `realpath()` en cada segmento de ruta para toda lectura, escritura, traslado, copia y eliminación local; los borrados o traslados recursivos de subárboles con enlaces simbólicos se rechazan, y los listados de directorio imponen `SKIP_LINKS`. |
| 10 | **Prevención de colisiones multiinquilino (`{user_id}`)** | `DynamicPathResolver` aísla los directorios de usuario con sufijos SHA-256 únicos `<slug>--h-<16-hex>` para correos electrónicos, mezcla de mayúsculas/minúsculas, Unicode, nombres reservados (`guest`, `user`) y nombres de dispositivo de Windows, haciendo imposible cualquier colisión entre dos usuarios. |
| 11 | **Aislamiento de caché en descargas, miniaturas y CDN** | `DownloadController`, `ThumbnailController` y `ZipDownloadController` emiten `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` y `Content-Security-Policy: default-src 'none'; sandbox`; aplican `Cache-Control: private` + `Vary: Cookie, Authorization` en contextos privados, con Voter, de sesión o `{user_id}`; exigen `CLEAR_CACHE` para `?refresh=1` y `?fallback=1`; y fuerzan `Content-Disposition: attachment` para HTML/JS/SVG/XML. |
| 12 | **Protección POST en FormType y origen en Twig** | `FileManagerPickerType` rechaza cargas POST manipuladas en el servidor (protocolos XSS, `../`, `.trash/`, JSON > 256 KB, > 500 elementos, UTF-8 inválido, exceso de `max_files`, arrays HPP); `FileManagerTwigExtension` y la función `isSafeUrl()` del frontend impiden la suplantación de orígenes externos o peligrosos. |
| 13 | **Directorios arbitrarios de temas/iconos y DOM XSS** | En tiempo de ejecución, `dialog?theme=` solo acepta los 9 temas integrados y `dialog?iconSet=` solo los 6 conjuntos de iconos integrados; `sanitizeHexColor()` impide la inyección de color; los directorios temporales de fragmentos están aislados por usuario (`0750`). |
| 14 | **DoS, inyección de logs y aislamiento de caché** | El borrado masivo está limitado a 200 elementos, las subidas por fragmentos a 2000 fragmentos y las copias recursivas de carpetas a 5000 elementos; `AuditLogListener` limpia cargas de inyección de logs (`\r`/`\n`/ANSI/caracteres de control); `DirectoryCacheManager` nunca vacía el pool `cache.app` de la aplicación anfitriona (jamás invoca `clear()`). |

---

## 5. Endurecimiento del servidor web (Apache y Nginx)

Como capa adicional de defensa, deshabilita siempre la ejecución de PHP y scripts dentro de directorios públicos de subida como `public/uploads` directamente en el servidor web:

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. Enlaces compartidos firmados y protector de uso de activos

- **Enlaces compartidos firmados y con caducidad (`ShareController`):** Cuando `share.enabled: true`, las URLs temporales se firman con `UriSigner` de Symfony (HMAC + marca temporal `expires`). Cambiar `share.enabled: false` en un perfil revoca de inmediato todos los enlaces existentes con `403 Forbidden`, los enlaces caducados devuelven `410 Gone` y todas las respuestas incluyen `Cache-Control: private, no-store` más endurecimiento estricto de CSP.
- **Protector de uso de activos (`AssetUsageCheckerInterface`):** Impide borrar, renombrar, mover o sobrescribir archivos referenciados activamente en tu base de datos (consulta [10 — Protector de uso de activos](10-asset-usage-guard.md)).

---

## 7. Registro de auditoría — Configuración de producción y ejemplos

`AuditLogListener` registra los 9 eventos de mutación de archivos y carpetas con contexto PSR-3 estructurado (`action`, `profile`, `volume`, `path`, `user`, `ip`, además de metadatos específicos de cada evento) y sanea automáticamente caracteres de control, CRLF y BiDi para prevenir la inyección de logs.

### Paso 1: Activar el registro de auditoría (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Activar o sobrescribir por perfil
        public_temp:
            security:
                audit_log: false
```

### Paso 2: Dirigir las entradas de auditoría a un log JSON rotativo dedicado (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # Retención y rotación de 90 días
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### Ejemplo de salida JSON estructurada

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### Los 9 eventos de ciclo de vida auditados

| Evento de Symfony | Clave `action` | Campos de contexto adicionales |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### Paso 3 (opcional): Persistir los registros de auditoría en una tabla de base de datos Doctrine

Para mostrar un historial de auditoría consultable dentro de tu panel de administración, suscríbete a los mismos eventos de Symfony:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> En aplicaciones de alto tráfico, despacha un mensaje asíncrono con Symfony **Messenger** en lugar de llamar a `$this->em->flush()` de forma síncrona para que las operaciones de archivos no queden bloqueadas.

---

[← Anterior: 05 — FormType e integraciones con editores](05-formtype-and-editor-integrations.md) | [Índice](README.md) | [Siguiente: 07 — Almacenamiento en la nube y configuración dinámica →](07-cloud-storage-and-dynamic-config.md)
