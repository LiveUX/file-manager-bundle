# 01 — Requisitos, dependencias e instalación

[← Índice de documentación](README.md) | [Siguiente: 02 — Configuración y perfiles →](02-configuration-and-profiles.md)

Esta guía te acompaña en la instalación de LiveUX FileManagerBundle desde cero, explicando **cada paso y por qué importa**, incluso si es tu primer paquete de Symfony UX.

> [!NOTE]
> **En pocas palabras: ¿Qué hace este bundle?**  
> Añade un gestor de archivos completo (similar a Google Drive o al Explorador de Windows) a tu aplicación Symfony, ya sea un panel de administración, una tienda en línea o un portal de clientes. Los usuarios pueden **subir archivos, organizar carpetas, recortar imágenes, previsualizar PDF y videos, y elegir archivos directamente desde formularios** con un solo clic.  
> No requiere **ningún framework CSS** (Bootstrap, Tailwind, etc.) ni **jQuery**, y con Symfony AssetMapper funciona en 5 minutos sin Node.js ni npm.

---

## 1. Requisitos del sistema

Antes de instalar, comprueba que tu equipo local o servidor cumple estos requisitos mínimos:

| Componente | Versión mínima | Por qué es necesario | Comando de comprobación |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` o superior | Utiliza características de PHP 8.3/8.4 (clases `readonly`, constantes de clase tipadas). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` o `8.x` | Probado y soportado por completo en Symfony 6.4 LTS, 7.2, 7.3, 7.4 y 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Instalación de paquetes y carga automática PSR-4. | `composer --version` |
| **Navegador** | Navegador moderno actualizado | Capa superior nativa HTML5 `<dialog>`, módulos ES y propiedades personalizadas CSS (Chrome, Edge, Firefox, Safari). | — |

---

## 2. Extensiones de PHP

Las extensiones dotan a PHP de capacidades nativas adicionales, como inspección de archivos, análisis XML y procesamiento de imágenes.

### Extensiones de PHP obligatorias
Están activadas por defecto en casi todas las instalaciones modernas de PHP:

| Extensión | Qué hace | Ejemplo práctico |
| :--- | :--- | :--- |
| `ext-fileinfo` | Inspecciona el **contenido real del archivo (magic bytes)**, no solo su extensión. | Si un atacante renombra `shell.php` a `foto.jpg` e intenta subirlo, `ext-fileinfo` lo detecta y lo rechaza al instante. |
| `ext-dom` y `ext-libxml` | Analiza de forma segura el árbol XML de los archivos `.svg` subidos (`SvgSanitizer`). | Elimina etiquetas `<script>` maliciosas o cargas XXE ocultas dentro de un logotipo SVG conservando el gráfico limpio. |

*(Nota: `ext-json` viene integrada en el núcleo de PHP desde PHP 8.0 y no necesita instalación aparte.)*

### Extensiones de PHP opcionales (recomendadas)
Si falta alguna de ellas, el gestor de archivos **sigue funcionando** y aplica alternativas seguras automáticamente:

| Extensión | Qué hace | Comportamiento alternativo si falta |
| :--- | :--- | :--- |
| `ext-gd` **o** `ext-imagick` | Genera miniaturas WebP/JPEG rápidas y ejecuta la optimización automática de imágenes al subirlas. | Muestra la imagen original o un icono de archivo según `thumbnail.on_missing_driver`. |
| `ext-exif` | Lee los metadatos de orientación EXIF de teléfonos al usar `ext-gd`, rotando automáticamente fotos tomadas de lado (`auto_orient: true`). | Las fotos se guardan con su orientación original (no es necesaria si `ext-imagick` está instalada). |
| `ext-zip` (`ZipArchive`) | Habilita la descarga de múltiples archivos en `.zip` **y** la extracción segura de archivos comprimidos (`zip.allow_extract: true`). | Los botones **Descargar ZIP** y **Descomprimir archivo** se ocultan automáticamente. |

Comprueba qué extensiones están activas en tu equipo con un solo comando:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Paquetes Composer obligatorios y opcionales

Cuando ejecutas `composer require liveux/file-manager-bundle`, **todos los paquetes obligatorios se instalan automáticamente**. Conocer qué hace cada dependencia facilita mucho la depuración:

### Paquetes obligatorios (se instalan automáticamente)
| Paquete | Restricción de versión | Propósito |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Abstracción unificada de sistemas de archivos para disco local, AWS S3, Cloudflare R2, Supabase, MinIO y SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Renderiza el gestor de archivos mediante `{{ component('LiveUX:FileManager:Browser') }}` con SSR de 0 ms. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Impulsa la caché de directorios en el cliente, arrastrar y soltar, menús contextuales, Lightbox y selectores de formulario. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Núcleo de Symfony, enrutamiento y pools de caché (`cache.app`). |
| `symfony/config` y `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Validación del esquema de `liveux_file_manager.yaml` y cableado del contenedor de servicios. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Despacha 9 eventos de dominio (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent`, etc.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Descargas seguras de archivos, streaming de video HTTP 206 (`Range`), ETags y cabeceras HTTP de caché. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Plantillas Twig, helpers de iconos/traducción y tema automático para FormType. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Campo de formulario `FileManagerPickerType` para archivo único y galería múltiple. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Detección del tipo MIME en el servidor para archivos subidos. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Translitera nombres de archivo multilingües (español, turco, francés, árabe, cirílico, etc.) a slugs SEO limpios. |
| `symfony/security-core` y `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | RBAC con Voter de 17 permisos y protección `X-LiveUX-FM-CSRF`. |
| `symfony/cache-contracts` y `symfony/translation-contracts` | `^3.5` | Contratos de caché de directorios e interfaz multilingüe. |

### Paquetes opcionales (instálalos solo cuando los necesites)
| Paquete | Comando de instalación | Cuándo lo necesitas |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Entrega de JS y CSS sin compilación ni Node.js/npm *(Recomendado)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Permisos basados en roles (`ROLE_USER` / `ROLE_ADMIN`) y aislamiento por usuario `{user_id}`. |
| `symfony/translation` | `composer require symfony/translation` | Integración con el traductor de Symfony en los **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) o locales personalizados. |
| `symfony/console` y `symfony/yaml` | `composer require symfony/console symfony/yaml` | Comandos CLI (`file-manager:doctor`, `thumbs:warmup`, `trash:purge`, etc.) y archivos de configuración YAML. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Conexión con AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO o Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Despliegues multiservidor o con balanceador de carga mediante `defaults.metadata.driver: 'doctrine'` (ver [Capítulo 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Campo `FileManagerField` integrado para paneles de EasyAdmin 4/5 (ver [Capítulo 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ Sugerir con IA**: generación automática de texto alternativo, descripciones y etiquetas (ver [Capítulo 09](09-symfony-ai-integration.md)). |

---

## 4. Instalación paso a paso

Sigue estos 7 pasos en orden para poner en marcha tu gestor de archivos.

### Paso 1: Instala el bundle

**Instalación estándar (desde Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Repositorio de ruta local (si estás desarrollando dentro de `bundles/liveux-file-manager-bundle`):**  
Añade una entrada `repositories` en el `composer.json` raíz de tu proyecto:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Luego instala el paquete local:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Paso 2: Verifica `config/bundles.php`

Normalmente Symfony Flex registra el bundle de forma automática. Si no es así, asegúrate de que estas entradas figuren en `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Si usas autenticación/roles
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **registra automáticamente** su espacio de nombres Twig (`@LiveUXFileManager`), su ruta en AssetMapper (`@liveux/file-manager-bundle`) y su tema de FormType (`@LiveUXFileManager/form/picker.html.twig`) al arrancar. No necesitas editar `twig.yaml` ni `asset_mapper.yaml`.

---

### Paso 3: Importa las rutas

Crea `config/routes/liveux_file_manager.yaml` para activar los endpoints de API AJAX, descarga, miniaturas y subidas del bundle:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Esta única importación registra **12 endpoints protegidos**:

| Método y ruta | Propósito |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Carga el HTML del gestor de archivos dentro de un diálogo Modal u OffCanvas. |
| `GET /_liveux-file-manager/api/list` | Devuelve el listado JSON de archivos y subcarpetas de un directorio. |
| `GET /_liveux-file-manager/api/tree` | Obtiene todo el árbol de carpetas para la barra lateral en una sola solicitud. |
| `GET /_liveux-file-manager/api/trash` | Lista los elementos eliminados temporalmente en la papelera. |
| `GET /_liveux-file-manager/api/preview-text` | Devuelve una vista previa segura (limitada a 64 KB) de texto/código para `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, etc. |
| `POST /_liveux-file-manager/api/action` | Ejecuta mutaciones: crear carpeta, mover, copiar, renombrar, eliminar, restaurar/vaciar papelera, color/icono de carpeta, metadatos, enriquecimiento con IA, importación desde URL remota y extracción de ZIP. |
| `POST /_liveux-file-manager/upload` | Gestiona tanto subidas estándar como subidas por fragmentos. |
| `POST /_liveux-file-manager/image-edit` | Guarda imágenes recortadas, redimensionadas o rotadas desde el editor de imágenes Canvas integrado. |
| `GET /_liveux-file-manager/download` | Sirve descargas seguras, vistas previas en línea de PDF/video (con soporte `Range`) y volúmenes `private: true`. |
| `GET /_liveux-file-manager/share` | Sirve enlaces compartidos temporales firmados con HMAC. |
| `POST /_liveux-file-manager/zip` | Transmite en streaming los archivos y carpetas seleccionados como un único archivo `.zip`. |
| `GET /_liveux-file-manager/thumb` | Genera y sirve miniaturas WebP/JPEG/PNG con ETag y cabeceras HTTP de caché sensibles al contexto. |

---

### Paso 4: Crea `config/packages/liveux_file_manager.yaml`

Crea el archivo `config/packages/liveux_file_manager.yaml`. La configuración inicial siguiente enumera explícitamente **los 17 permisos granulares**:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = hereda automáticamente el locale activo de Symfony (siempre respalda en 'en')
                            # O fija un idioma concreto como 'es', 'en', 'tr', 'fr', 'de', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'Mis archivos'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # La extracción ZIP está desactivada por defecto (requiere también zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Nota crítica de seguridad (`permissions`):**  
> Cualquier clave que **omitas** dentro de `permissions` (salvo `extract_zip`) toma `true` por defecto. Aquí, `true` significa **«cualquier usuario autenticado»**, NO visitantes anónimos. Si solo `ROLE_ADMIN` debe poder vaciar la papelera permanentemente (`trash_purge`) o crear enlaces compartidos (`share`), establece esas claves explícitamente en `'ROLE_ADMIN'`.

> [!TIP]
> **¿Estás probando en local sin autenticación todavía?**  
> Mientras haces prototipos en local sin sistema de inicio de sesión, puedes configurar temporalmente `profiles.default.security.voter_enabled: false`. **NUNCA dejes `voter_enabled: false` en producción** — consulta [06 — Seguridad y autorización](06-security-and-authorization.md).

> [!NOTE]
> **12 idiomas de interfaz integrados y RTL automático (`defaults.locale: null`):**  
> Al dejar `locale: null`, el gestor de archivos sigue automáticamente el locale activo de la petición Symfony en los **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), activa **RTL (`dir="rtl"`)** automáticamente para árabe y otros idiomas RTL (persa, hebreo, urdu, etc.), normaliza locales BCP-47 y POSIX (`es_ES.UTF-8`, `de_DE.UTF-8`, `zh-Hans-CN`) y recurre de forma limpia al **inglés (`en`)** para locales no soportados.

---

### Paso 5: Conecta CSS y JavaScript (¡cero configuración!)

**¿No sabes si tu proyecto usa AssetMapper o Webpack Encore?**  
Revisa la raíz del proyecto:
- Si ves `importmap.php` → sigue la **Opción A (AssetMapper)**.
- Si ves `webpack.config.js` → sigue la **Opción B (Webpack Encore)**.

> [!TIP]
> **Soporte `autoimport` sin configuración:**  
> LiveUX FileManagerBundle declara **`autoimport`** en su `assets/package.json` para `@liveux/file-manager-bundle/styles/core.css` y el controlador eager `dialog_controller.js` (que carga `file_manager_api.js`). Una vez habilitado en `assets/controllers.json`, **no necesitas ni una sola sentencia `import` manual en `assets/app.js`**.

#### Opción A: Symfony AssetMapper (Recomendado — Sin Node.js ni npm)

1. Confirma que los controladores del bundle estén habilitados en `assets/controllers.json` (Symfony Flex lo añade automáticamente; en caso contrario, agrégalo):
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Los proyectos Symfony 6.4 / 7.2+ / 8.x ya llaman a `startStimulusApp()` en `assets/bootstrap.js` o `assets/stimulus_bootstrap.js`, que lee `controllers.json` automáticamente:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) o assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Asegúrate de que tu plantilla base (`templates/base.html.twig`) incluya `importmap('app')`:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Opcional — al desplegar a producción)* Compila los activos estáticos:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Activos preminificados para producción (`assets/dist/`):**  
> Como AssetMapper no minifica JS/CSS por defecto, LiveUX FileManagerBundle distribuye módulos JavaScript y `core.css` preminificados con `esbuild` en `assets/dist/` (`747 KB → 410 KB` minificados; `~93 KB` con Gzip o **`~77 KB`** con Brotli). `FileManagerBundle` mapea automáticamente `assets/dist/` en AssetMapper, por lo que **ni tu equipo ni tu servidor de producción necesitan Node.js ni npm**.  
> En Symfony 7.3+ / 8.x, también puedes activar `precompress: true` en `config/packages/asset_mapper.yaml` para generar archivos `.br` y `.gz` automáticamente durante `asset-map:compile`.

#### Opción B: Webpack Encore

1. En proyectos con Webpack Encore (incluido Symfony 8 + StimulusBundle 3.x), asegúrate de tener instalado `@symfony/stimulus-bridge`:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Verifica los 3 controladores Stimulus y el bloque `autoimport` en `assets/controllers.json`:
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. Verifica que `startStimulusApp` esté conectado en `assets/bootstrap.js` (`assets/app.js` lo importa; no hacen falta importaciones manuales de CSS/JS):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Compila tus activos:
```bash
npm run build
# O compila continuamente durante el desarrollo: npm run watch
```

---

### Paso 6: `DialogHost` (Inyección automática — ¡Sin marcado adicional!)

Para abrir modales, paneles OffCanvas, `FileManagerPickerType` o selectores de TinyMCE/CKEditor/Tiptap desde cualquier página hace falta un contenedor `<dialog>` en el DOM.

El suscriptor `AutoInjectDialogHostSubscriber` del bundle inyecta automáticamente este contenedor justo antes de `</body>` en las respuestas HTML completas (`defaults.auto_inject_dialog_host: true`), por lo que no necesitas tocar `base.html.twig`.

- **Detección inteligente:** Nunca modifica respuestas JSON, AJAX, Turbo Frame, redirecciones ni descargas binarias, y omite la inyección si ya existe un `DialogHost` manual.
- **0 ms de sobrecarga al cargar la página:** No consulta directorios ni árboles de carpetas en la carga inicial; los datos solo se cargan cuando el usuario abre el diálogo.
- **¿Prefieres colocarlo manualmente solo en ciertas páginas?** Configura `defaults.auto_inject_dialog_host: false` e incluye el fragmento justo antes de `</body>` en las plantillas que desees:

```twig
{# Opcional — solo necesario cuando auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Paso 7: Verifica tu instalación (`file-manager:doctor`)

Limpia la caché y ejecuta el comando de diagnóstico integrado:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Si has configurado volúmenes en la nube (S3, R2, Supabase, MinIO), añade `--check-remote` para probar la conectividad real:
```bash
php bin/console file-manager:doctor --check-remote
```

Ejemplo de salida saludable (9 comprobaciones del entorno + tabla de estado de perfiles y volúmenes):
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Usuarios de Windows (`cURL error 60`):** Si `cURL CA Bundle` muestra una advertencia, apunta `curl.cainfo` y `openssl.cafile` en `php.ini` a un archivo `cacert.pem` actualizado, o configura `driver_options.ca_bundle` en tu volumen S3.
>
> **Permisos de archivos en Linux:** Asegúrate de que `public/uploads`, `public/media-cache` y `var/file-manager` tengan permisos de escritura para el usuario del servidor web (`www-data`, `nginx`, etc.).
>
> **¿Usas un asistente de código IA (Antigravity, Claude Code, Cursor, Copilot)?**  
> Enseña a tu asistente todas las convenciones y recetas del bundle con un solo comando (ver [Capítulo 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Crea tu primera página funcional

Vamos a crear un controlador Symfony y una plantilla Twig sencillos para verificar todo de extremo a extremo.

### 1) Controlador (`src/Controller/MediaController.php`)
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Plantilla Twig (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Biblioteca multimedia</h1>

    {# Opción 1: Gestor de archivos en línea #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Opción 2: Abrir un Modal desde cualquier botón y capturar los archivos seleccionados #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Seleccionar archivos en Modal</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // El usuario cerró el diálogo sin seleccionar nada
            }

            document.getElementById('selection-result').textContent =
                'Seleccionados: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Abre `/admin/media` en tu navegador. ¡Enhorabuena, tu gestor de archivos ya está en marcha!

---

## 6. ¿Qué sigue?

- Configura perfiles personalizados, límites de subida y reglas de imágenes → **[02 — Configuración y perfiles](02-configuration-and-profiles.md)**
- Añade un selector de imágenes o galerías a formularios Symfony → **[05 — Integraciones de FormType y editores](05-formtype-and-editor-integrations.md)**
- Endurece RBAC y la seguridad antes de publicar en producción → **[06 — Seguridad y autorización](06-security-and-authorization.md)**

---

[← Índice de documentación](README.md) | [Siguiente: 02 — Configuración y perfiles →](02-configuration-and-profiles.md)
