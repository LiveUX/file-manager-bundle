# 04 — Temas, modo oscuro, personalización de carpetas, conjuntos de iconos y diseño a medida

[← Anterior: 03 — Modos de uso y API de JS](03-usage-modes-and-js-api.md) | [Índice](README.md) | [Siguiente: 05 — FormType e integraciones con editores →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle está diseñado con **0 % de dependencias de frameworks CSS**. Tanto si tu proyecto usa **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** como si **no usa ningún framework CSS**, funciona desde el primer momento sin pasos extra de compilación (no requiere configuración de `@source` ni *purge* en Tailwind).

> **De un vistazo: qué aprenderás en esta guía**  
> 1. **9 temas integrados y modo oscuro:** Adapta el diseño visual y el esquema de color de tu aplicación con una sola opción YAML.  
> 2. **Colores e iconos (insignias) de carpetas:** Personaliza carpetas desde la interfaz o de forma programática en PHP/BD con más de 18 colores y 14 insignias integradas (además de insignias SVG personalizadas ilimitadas).  
> 3. **Crear tu propio conjunto de iconos:** Instrucciones paso a paso para crear tu paquete de iconos con 9 iconos de archivo, 49 iconos de interfaz e insignias de carpeta personalizadas ilimitadas.  
> 4. **Crear un tema personalizado (3 niveles de libertad):** Temas de marca con variables CSS puras, sobrescritura parcial en Twig con `{% extends %}`, o un diseño 100 % *headless* desde cero.

---

## 1. Los 9 temas integrados (¿cuál deberías elegir?)

Los temas se pueden configurar mediante `defaults.theme`, `profiles.<name>.ui.theme`, la propiedad `theme` del TwigComponent o la opción `theme` de `FileManagerPickerType`. **Los 9 temas cumplen los estándares de contraste WCAG 2.1 AA tanto en modo claro como en modo oscuro.**

| Clave de tema | Ideal para | Resumen de diseño |
| :--- | :--- | :--- |
| `bootstrap5` *(por defecto)* | Bootstrap 5 y paneles de administración clásicos de Symfony | Azul clásico (`#0d6efd`), radios `10px / 8px / 6px`, bordes neutros limpios |
| `tailwind` | Aplicaciones web modernas construidas con Tailwind CSS v3/v4 | Acento índigo (`#6366f1`), radios suaves `14px / 12px / 8px`, paleta Slate |
| `shadcn` | Dashboards SaaS minimalistas en monocromo blanco y negro | Superficies monocromas Zinc (`#18181b` / `#fafafa` en modo oscuro), bordes definidos `8px / 6px` |
| `flowbite` | Paneles de administración que usan Flowbite 4 + Tailwind CSS | Azul Flowbite (`#1a56db`) y superficies Gray-50/800/900, controles de `8px` |
| `daisyui` | Proyectos creados con DaisyUI 5 | **Hereda automáticamente** `--color-primary`, `--color-base-100/200/300` y `--radius-box` cuando DaisyUI está cargado en la página |
| `tabler` | Tabler.io o backends corporativos con EasyAdmin | Lienzo corporativo Slate, acento azul `#066fd1`, esquinas nítidas `4px / 6px` |
| `preline` | Aplicaciones con Preline UI | Superficies neutras puras (`#fafafa` / `#171717`) con acento azul vibrante (`#2563eb`) |
| `material` | Aplicaciones basadas en Google Material Design 3 | Superficies tonales púrpura (`#6750a4` / `#f3edf7`) y botones redondeados tipo píldora |
| `unstyled` | Estilizar el gestor de archivos completamente con tu propio CSS | Hereda la tipografía y superficies transparentes de la app anfitriona manteniendo contraste opaco en `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` para *popovers* y *lightboxes* |

| Modo oscuro (`bootstrap5` + Dark) | Puente de tema DaisyUI 5 (`daisyui`) |
| :---: | :---: |
| ![Gestor de archivos en modo oscuro](../images/browser-dark.png) | ![Demostración del tema DaisyUI 5](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Regla de seguridad (`theme` en YAML frente a tiempo de ejecución):**  
> - En `liveux_file_manager.yaml`, puedes configurar cualquiera de los 9 temas integrados, el nombre de tu propio tema CSS personalizado (por ejemplo, `'corporate'`, que cumpla `/^[a-zA-Z0-9_-]{1,40}$/`) o la ruta de una plantilla Twig personalizada (por ejemplo, `'file_manager/custom.html.twig'` o `'@App/file_manager'`).  
> - Por motivos de seguridad, los valores dinámicos de `theme` procedentes del navegador, parámetros de consulta en la URL o `window.LiveUXFileManager.open({ theme: ... })` **solo aceptan las 9 claves de tema integradas** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Modo oscuro, RTL y movimiento reducido

Los 9 temas (incluido `unstyled`) admiten **modo oscuro tanto automático como manual**:

### A) Detección automática de la página anfitriona (`color_scheme: 'auto'`)
Siempre que tu página (`<html>` o `<body>`) tenga activo cualquiera de los siguientes atributos o clases, el gestor de archivos, el modal `<dialog>` y los campos `FileManagerPickerType` cambian de inmediato a modo oscuro:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- Clase `.dark` *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / temas personalizados)*

### B) Esquema de color por defecto o por perfil mediante YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (seguir a la app) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Iniciar solo este perfil en modo oscuro
```

### C) Preferencia del usuario final (menú de ajustes: `Auto / Light / Dark`)
Los usuarios pueden alternar entre **Auto / ☀ Claro / 🌙 Oscuro** con un solo clic desde el menú emergente **⚙ Ajustes** de la barra superior. Su preferencia se guarda en `localStorage` del navegador (`liveux_fm_color_scheme`).

### D) RTL global y `prefers-reduced-motion`
- `dir="rtl"` se aplica automáticamente en 15 idiomas de derecha a izquierda, incluidos árabe (`ar`), persa (`fa`), hebreo (`he`) y urdu (`ur`). Todos los espaciados de `core.css` usan propiedades lógicas CSS (`inset-inline`, `padding-inline`, `border-inline-end`).
- Las animaciones se minimizan automáticamente para los usuarios que tienen activada la opción *"Reducir movimiento"* (`prefers-reduced-motion: reduce`) en su sistema operativo.

---

## 3. Colores e iconos (insignias) de carpetas (paso a paso)

![Modal de paleta de colores e insignias de carpeta](../images/folder-customize.png)

Puedes asignar un **color** personalizado, un **icono de insignia** y el estado de **fijada (⭐ Fijar)** a cualquier carpeta. Estas funciones están activas cuando `defaults.metadata.enabled: true` y el usuario dispone del permiso `permissions.edit_metadata`.

### Método 1: Desde la interfaz (para usuarios finales)
1. Haz **clic derecho** en cualquier carpeta y selecciona **Personalizar** (o pulsa **`I`** con la carpeta seleccionada, o haz clic en **Personalizar** en el panel lateral derecho de Inspección).
2. En el modal **Personalizar**, configura tres opciones con vista previa en vivo:
   - **Paleta predefinida de 18 colores:** Azul (`#2563eb`), Cielo (`#0284c7`), Cian (`#0891b2`), Verde azulado (`#0d9488`), Esmeralda (`#059669`), Verde (`#16a34a`), Lima (`#65a30d`), Ámbar (`#d97706`), Naranja (`#ea580c`), Rojo (`#dc2626`), Rosa intenso (`#e11d48`), Rosa (`#db2777`), Fucsia (`#c026d3`), Púrpura (`#9333ea`), Violeta (`#7c3aed`), Índigo (`#4f46e5`), Pizarra (`#475569`), Carbón (`#1e293b`).
   - **Selector de color personalizado + entrada HEX:** Elige cualquier color de marca mediante el selector nativo o escribe directamente un código hexadecimal `#RRGGBB`. Haz clic en **Restablecer valor por defecto** para borrar el estilo personalizado.
   - **Cuadrícula de iconos (insignias) de carpeta:** Muestra las **14 insignias integradas** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`), además de cualquier archivo SVG personalizado que añadas al directorio `badges/` de tu conjunto de iconos.
3. Haz clic en **Guardar**: la carpeta se actualiza al instante tanto en la vista de Cuadrícula/Lista como en el árbol de carpetas izquierdo.

### Método 2: De forma programática desde PHP (por ejemplo, al crear una carpeta de cliente)
Con `FolderMetadataStorageInterface` y `PathNormalizer::buildCanonicalKey()`, puedes asignar colores, insignias y carpetas fijadas directamente desde código PHP (el mismo código funciona indistintamente con los drivers `json_file` y `doctrine`):

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // Construir la clave canónica (p. ej., "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Verde azulado (#RRGGBB)
            icon: 'lock',       // Una de las 14 insignias integradas o badges/invoice.svg -> 'invoice'
            pinned: true,       // Añadir a ⭐ Carpetas fijadas en la barra lateral
        ));
    }
}
```

### Método 3: Directamente en `metadata.json` (cuando usas `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Conjuntos de iconos y cómo crear el tuyo desde cero

**Todos los iconos** del bundle —tipos de archivo, carpetas, barra superior, menú contextual, indicadores de almacenamiento en la nube, panel inspector e insignias de carpeta— se sirven desde un único `IconSetRegistry` unificado. Los 6 conjuntos de iconos integrados se cachean en PSR-6 durante 24 horas (`liveux_fm_iconset_*`).

### 6 conjuntos de iconos integrados (`profiles.<name>.ui.icon_set`)
| Conjunto de iconos | Estilo visual | Color de carpeta por defecto (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(por defecto)* | Iconos vectoriales planos y nítidos | `#2563eb` |
| `windows11` | Carpetas amarillo cálido de doble tono estilo Windows 11 e iconos modernos de documentos | `#f59e0b` |
| `macos` | Carpetas azul suave inspiradas en Finder de macOS | `#3b82f6` |
| `linux` | Estética limpia de código abierto estilo GNOME / Adwaita | `#3584e4` |
| `minimal` | Iconos minimalistas de trazo fino (*outline*) | `#475569` |
| `vscode` | Iconos precisos estilo VS Code orientados a desarrolladores | `#dcb67a` |

### Paso a paso: crea tu propio conjunto de iconos personalizado

#### Paso 1: Crea un directorio de iconos en tu proyecto
Por ejemplo, crea `assets/fm-icons/`. **No necesitas copiar los iconos que no quieras cambiar**: cualquier icono que falte recurre automáticamente a los conjuntos integrados `classic` / `_ui` / `_folder_badges`.

```text
assets/fm-icons/
├── meta.json               # Color de carpeta por defecto: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Opcional) Icono principal de carpeta personalizado
├── pdf.svg                 # (Opcional) Sobrescribe únicamente el icono PDF
├── ui/                     # (Opcional) Sobrescribe o añade iconos de barra, menú contextual y volúmenes
│   ├── upload.svg          # Sobrescribe el icono de Subir
│   ├── new_folder.svg      # Sobrescribe el icono de Nueva carpeta
│   └── company_cloud.svg   # Añade un icono de volumen nuevo -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Opcional) Sobrescribe insignias existentes o añade NUEVAS
    ├── lock.svg            # Sobrescribe la insignia integrada "lock"
    └── invoice.svg         # Añade una insignia NUEVA -> aparece automáticamente en el modal Personalizar carpeta
```

#### Paso 2: Crea `meta.json` y un `folder.svg` con color dinámico

> [!TIP]
> **Regla de oro (`currentColor`):** Para que tu `folder.svg` personalizado se adapte automáticamente cada vez que un usuario cambie el color de una carpeta, usa **`fill="currentColor"`** (o `stroke="currentColor"` en iconos de contorno) en lugar de colores hexadecimales fijos.

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/invoice.svg (Nueva insignia personalizada de carpeta) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Paso 3: Apunta tu perfil YAML al directorio
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Archivo corporativo'
            icon: 'company_cloud' # ¡Usa assets/fm-icons/ui/company_cloud.svg!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Seguridad y límites de iconos personalizados (`IconSetRegistry`)
- Cada archivo `.svg` puede pesar como máximo **256 KB** (`262,144` bytes) y se limpia automáticamente mediante **`SvgSanitizer`** al cargarse (eliminando `<script>`, manejadores `on*`, etc.).
- Tanto `ui/` como `badges/` escanean hasta **200 archivos `.svg` personalizados** además de las claves integradas.
- Los nombres de archivo personalizados (sin `.svg`) deben coincidir con **`/^[a-zA-Z0-9_-]{1,32}$/`** (letras ASCII, dígitos, `-` y `_`, máximo 32 caracteres).

#### Referencia completa de claves de iconos sobrescribibles
- **9 iconos de archivos y carpetas (raíz `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 iconos de interfaz (`<dir>/ui/<key>.svg`):**
  - *Navegación y diseño:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Acciones sobre archivos y carpetas:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Almacenamiento y proveedores en la nube:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 insignias de carpeta integradas (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(¡más cualquier archivo `*.svg` personalizado que añadas!)*

---

## 5. Crear tu propio tema personalizado (3 niveles de libertad)

`Browser.html.twig` resuelve el valor `theme` configurado en tu perfil siguiendo este **orden exacto de prioridad**:
1. Termina en `.html.twig` → Renderiza tu plantilla Twig directamente (`{% include theme %}`).
2. Empieza por `@` → Renderiza `layout.html.twig` dentro de ese espacio de nombres Twig (`{% include theme ~ '/layout.html.twig' %}`).
3. Coincide con uno de los 9 temas integrados → Renderiza el tema incluido en el bundle.
4. Cualquier otro identificador corto (p. ej., `'corporate'`, que cumpla `/^[a-zA-Z0-9_-]{1,40}$/`) → Envuelve el diseño base predeterminado con `<div data-liveux-theme="corporate">`.

### Nivel 1: Tema de marca con variables CSS puras (el más rápido — ¡sin plantillas!)

#### Referencia completa de propiedades personalizadas CSS (`--liveux-fm-*`)
| Categoría | Variables CSS |
| :--- | :--- |
| **Tipografía** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Superficies y bordes** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Colores de texto** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Colores de acento de marca** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Colores semánticos de estado (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Radios y sombras** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Tema corporativo listo para copiar y pegar (`assets/styles/app.css`)
```css
/* 1) Paleta de marca en modo claro */
[data-liveux-theme="corporate"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) Equivalente en modo oscuro (cubre Browser y FormType Picker, modo oscuro automático y manual) */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"],
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'corporate'
```

---

### Nivel 2: Sobrescrituras parciales en Twig con `{% extends %}` (recomendado)

El diseño base está organizado en **10 bloques `{% block %}`** y **7 parciales modulares (`parts/`)**. Así puedes personalizar solo la barra superior o el pie sin duplicar toda la plantilla:

| Nombre del bloque | Alcance | Parcial por defecto |
| :--- | :--- | :--- |
| `fm_shell` | Contenedor exterior principal (`.liveux-fm-shell`) | Envuelve todos los bloques anidados |
| `fm_topbar` | Barra de comandos superior (navegación, migas de pan, búsqueda, ordenación, acciones) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Desplegable de ajustes (modo de color, tamaño de elementos, estilo de carpetas, caché, atajos) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Banner de notificaciones de estado + cola de subidas | Marcado en línea |
| `fm_body` | Área central principal (barra lateral + principal + contenedor del inspector) | `.liveux-fm-body` |
| `fm_sidebar` | Árbol izquierdo de carpetas + carpetas fijadas + barra de cuota + papelera | `parts/_sidebar.html.twig` |
| `fm_main` | Cuadrícula/lista de archivos + barra flotante de selección/portapapeles + zona de arrastre + paginación | `parts/_main.html.twig` |
| `fm_inspector` | Panel derecho de vista previa y metadatos | `parts/_inspector.html.twig` |
| `fm_footer` | Barra inferior en modo Modal / OffCanvas | `parts/_footer.html.twig` |
| `fm_overlays` | Menú contextual, modales, lightbox y plantillas de atajos/acerca de | `parts/_overlays.html.twig` |

#### Ejemplo — Franja superior corporativa + pie de selección personalizado
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Centro corporativo de activos digitales</span>
        <span>Perfil: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Cancelar</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Confirmar selección</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) Ruta directa a una plantilla .html.twig:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) O un directorio de espacio de nombres Twig (carga automáticamente layout.html.twig dentro):
                # theme: '@App/file_manager'
```

---

### Nivel 3: Diseño 100 % *headless* desde cero (targets de Stimulus requeridos)

Si quieres construir tu propia estructura HTML completamente desde cero sin `{% extends %}`, solo tienes que enlazar estos atributos `data-{{ ctrl }}-target` usando el prefijo `ctrl = 'liveux--file-manager-bundle--browser'`:

| Target (`data-...-target`) | ¿Requerido? | Propósito |
| :--- | :---: | :--- |
| `shell` | Recomendado | Escala de tamaño de elementos (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Requerido** | Lienzo principal donde se renderizan las tarjetas y filas de archivos/carpetas |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Requerido** | Contenedor de diálogos para Nueva carpeta, Subir, Eliminar, Personalizar, Cuota y Metadatos |
| `breadcrumbs` | Recomendado | Ruta clicable de migas de pan |
| `confirmSelectionBtn`, `footerStatus` | Recomendado (Modal/OffCanvas) | Botón de confirmar selección (`disabled` automáticamente cuando no hay nada seleccionado) y texto de estado |
| `statusBanner`, `uploadQueue` | Recomendado | Avisos de error/éxito y cola de progreso para subidas por fragmentos |
| `tree` + `sidebar` + `pinnedFolders` | Opcional | Árbol de carpetas izquierdo y carpetas fijadas |
| `contextMenu` | Opcional | Contenedor del menú contextual al hacer clic derecho |
| `inspector` | Opcional | Panel derecho de vista previa y metadatos |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Opcional | Lightbox universal de vista previa multimedia |

---

## 6. 7 funciones auxiliares de Twig para plantillas personalizadas

| Función Twig | Propósito | Ejemplo de uso |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Renderiza un SVG en línea seguro contra XSS (sin necesidad de `\|raw`). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Genera una URL segura de miniatura para un archivo. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Genera una URL segura de descarga o vista previa en línea. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Descargar</a>` |
| `liveux_fm_trans(key, params, locale)` | Traduce una clave de mensaje del bundle con respaldo garantizado en `'en'`. | `{{ liveux_fm_trans('ui.upload', {}, 'es') }}` |
| `liveux_fm_bcp47_locale(locale)` | Convierte códigos de configuración regional PHP/ICU como `es_ES.UTF-8` en etiquetas HTML válidas `lang="..."` BCP-47 (`es-ES`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Devuelve `true` si el idioma indicado se escribe de derecha a izquierda (árabe, persa, hebreo, etc.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Normaliza de forma segura valores de formularios/EasyAdmin a descriptores renderizables de imagen/archivo (bloqueando URLs `javascript:`/`data:`). | Vistas Index/Detail de EasyAdmin y plantillas personalizadas |

---

[← Anterior: 03 — Modos de uso y API de JS](03-usage-modes-and-js-api.md) | [Índice](README.md) | [Siguiente: 05 — FormType e integraciones con editores →](05-formtype-and-editor-integrations.md)
