# 03 — Modos de uso y API de JavaScript

[← Anterior: 02 — Configuración y perfiles](02-configuration-and-profiles.md) | [Índice de documentación](README.md) | [Siguiente: 04 — Temas, iconos y personalización →](04-themes-icons-and-customization.md)

Puedes incrustar LiveUX FileManagerBundle **en línea (`inline`)** dentro de cualquier página, abrirlo en un **Modal** centrado, desplegarlo como un panel **OffCanvas** en 4 direcciones o invocarlo programáticamente desde cualquier lugar con la **API Promise de JavaScript**.

> [!TIP]
> **¿Qué modo debo elegir?**  
> - **En línea (`inline`):** Páginas dedicadas de *«Biblioteca multimedia»* donde el gestor de archivos ocupa el área principal de contenido.  
> - **Modal:** Diálogo centrado lanzado desde un botón *«Elegir imagen»* mientras editas un producto o artículo; se cierra en cuanto el usuario elige un activo.  
> - **OffCanvas:** Panel deslizable moderno desde cualquier borde (`start`, `end`, `top`, `bottom`) que mantiene tu formulario visible en segundo plano.  
> - **API de JavaScript (`window.LiveUXFileManager.open()`):** Lanza el selector desde cualquier botón HTML personalizado, componente React/Vue/Alpine o flujo en JavaScript puro sin formularios Symfony.

---

## 1. Modo en línea — TwigComponent

![Vista del gestor de archivos en línea](../images/browser-light.png)

Incrustar un gestor de archivos completo en línea requiere una única llamada Twig. Envuélvelo en un contenedor con una altura explícita (como `680px` o `calc(100vh - 140px)`):

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

O utilizando la sintaxis de etiqueta de componente `<twig:...>` de Symfony UX:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="es"
    />
</div>
```

### Todas las propiedades del componente `Browser`

| Propiedad | Tipo | Por defecto | Descripción |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Clave del perfil definida en `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Clave del volumen inicial (`null` abre el primer volumen del perfil). |
| `path` | `string` | `''` | Subdirectorio inicial (p. ej., `'products/2026'`). |
| `multiple` | `bool` | `false` | Activa el modo de selección múltiple de archivos. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` o `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Filtro inicial por tipo: `'all'`, `'folder'`, `'image'`, `'doc'` o `'video'`. |
| `theme` | `?string` | `null` | Sobrescritura de tema en tiempo de ejecución (acepta los 9 temas integrados: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Los temas CSS/Twig personalizados se configuran en YAML. |
| `iconSet` | `?string` | `null` | Sobrescritura de conjunto de iconos en tiempo de ejecución (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Los directorios SVG personalizados se configuran en YAML. |
| `locale` | `?string` | `null` | Sobrescritura de idioma en tiempo de ejecución entre los **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) o cualquier locale BCP-47 / POSIX (p. ej., `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal y OffCanvas — API Promise programática de JavaScript

`DialogHost` se inyecta automáticamente en cada respuesta HTML completa (`defaults.auto_inject_dialog_host: true`). Puedes invocar `window.LiveUXFileManager.open()` con `async/await` desde cualquier página.

### Todas las opciones de `open(options)`

| Opción | Tipo | Por defecto | Descripción |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Nombre del perfil a abrir. |
| `volume` | `?string` | `null` | Clave del volumen de almacenamiento inicial. |
| `path` | `string` | `''` | Ruta inicial de carpeta (p. ej., `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Diálogo modal centrado o panel deslizable OffCanvas. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Borde desde el que se desliza cuando `mode: 'offcanvas'` (`start` = izquierda, `end` = derecha; se invierte automáticamente en idiomas RTL). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(perfil)* | Sobrescribe el diseño en pantallas móviles (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = archivo único, `true` = múltiples archivos. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Abre prefiltrado a una categoría concreta de archivos (`filetype` es un alias de compatibilidad con TinyMCE; `'media'` se asigna automáticamente a `'video'`). |
| `theme` | `?string` | `null` | Sobrescritura en tiempo de ejecución de cualquiera de los 9 temas integrados (las rutas de plantillas Twig personalizadas están restringidas al YAML del servidor por seguridad). |
| `iconSet` | `?string` | `null` | Sobrescritura en tiempo de ejecución de cualquiera de los 6 conjuntos de iconos integrados. |
| `locale` | `?string` | `null` | Sobrescritura de idioma en tiempo de ejecución (`'es'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, `'ja'`, `'zh_CN'`, etc.). |

### Ejemplo práctico: `<input>` oculto + vista previa en vivo de imagen (sin Symfony Form)

Pega este fragmento en cualquier página HTML o Twig y funcionará de inmediato:

```html
<!-- 1. Campo oculto + vista previa en vivo + botón disparador -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Vista previa" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Elegir imagen de portada</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Abrir prefiltrado solo para imágenes
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // El usuario pulsó Esc o cerró el diálogo sin seleccionar nada
    if (selected.length === 0) {
        return;
    }

    const file = selected[0];
    document.getElementById('cover-url').value = file.url;

    const img = document.getElementById('cover-preview');
    if (file.isImage) {
        img.src = file.url;
        img.alt = file.meta?.alt || file.name;
        img.style.display = 'block';
    }
});
</script>
```

### Fragmentos rápidos por modo

```html
<!-- Modal centrado -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Abrir Modal</button>

<!-- Panel deslizable derecho (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Panel derecho</button>

<!-- Panel deslizable inferior (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Panel inferior</button>
```

> [!NOTE]
> **Comportamiento al cancelar:** Si el usuario cierra el diálogo sin elegir ningún archivo (`Esc`, botón cerrar o clic en el fondo), la Promise **no se rechaza**, sino que se resuelve limpiamente con un array vacío (`[]`).
>
> **Adaptabilidad móvil (`mobileMode` / `ui.mobile_dialog_mode`):** En pantallas `<= 767.98px`, el diálogo se adapta automáticamente: en modo `'auto'`, los modales y paneles laterales (`start`/`end`) se abren de borde a borde en pantalla completa (`100dvh`), mientras que los paneles `bottom`/`top` se muestran como hojas nativas redondeadas con asa de arrastre. El árbol de carpetas izquierdo comienza plegado en móviles y se despliega al tocarlo.

| Panel OffCanvas de escritorio (`position: 'end'`) | Hoja inferior móvil (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Vista OffCanvas de escritorio](../images/offcanvas-desktop.png) | ![Vista de hoja móvil](../images/mobile-sheet.png) |

### Estructura del objeto de archivo devuelto

`window.LiveUXFileManager.open()` se resuelve con un array de objetos con la siguiente estructura:

```json
[
    {
        "kind": "file",
        "path": "products/matte-black-tumbler.webp",
        "url": "/uploads/products/matte-black-tumbler.webp",
        "name": "matte-black-tumbler.webp",
        "extension": "webp",
        "isImage": true,
        "meta": {
            "alt": "Termo térmico de acero inoxidable negro mate",
            "title": "Termo Pro",
            "caption": "Botella térmica al vacío de 500 ml",
            "tags": ["termo", "bebidas"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Nota: En volúmenes `private: true`, `url` apunta al endpoint protegido por RBAC `/_liveux-file-manager/download?...&inline=1`.)*

### Eventos DOM personalizados

Además de esperar el resultado de `window.LiveUXFileManager.open()`, puedes suscribirte a los eventos del ciclo de vida del diálogo directamente en `window`:

| Evento DOM (`window.addEventListener`) | Cuándo se dispara | Carga útil `event.detail` |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Cuando se despacha una solicitud para abrir un diálogo (puente entre `file_manager_api.js` y `dialog_controller.js`). | Opciones de `open()` + `requestId` |
| `liveux:file-manager:dialog-opened` | Cuando el diálogo se ha abierto y su contenido está montado. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Cuando el usuario confirma una selección (o cancela el diálogo). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Cantidad de elementos seleccionados:', event.detail.items.length);
});
```

---

## 3. Funciones integradas para usuarios finales

- **Menús contextuales compactos:**
  - *Sobre un archivo o carpeta:* Barra de acciones rápidas SVG de una sola fila al estilo Windows 11 (`Cortar`, `Copiar`, `Renombrar`, `Descargar`/`Fijar en Acceso rápido`, `Eliminar`) seguida de acciones agrupadas de un clic (Vista previa, Editar imagen, Información y etiquetas / SEO y punto focal, Descomprimir archivo, Copiar enlace, Crear enlace compartido, Mover a carpeta…, Duplicar, Color de carpeta).
  - *Sobre espacio vacío:* Barra superior de navegación rápida (`← → ↑ ↻`), Nueva carpeta, Subir aquí, Pegar aquí, Seleccionar todo, Ordenar por y Elementos por página. *(Pantalla completa, Atajos de teclado y Acerca de viven dentro del menú **⚙ Configuración** de la barra de herramientas.)*
  - *Móvil / táctil:* Se abre como una hoja inferior optimizada para tacto desde el botón `⋯` de cada tarjeta.
- **Visor universal (Lightbox v2) y panel derecho (Vista previa y detalles):**
  - **Imágenes:** Navegación de galería (`←`/`→`), controles de zoom (`-` / `100%` / `+`) e indicador de punto focal.
  - **Video (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** Reproductor `<video controls>` en vivo en Vista previa y detalles + Lightbox en pantalla completa + búsqueda en streaming `HTTP 206 Partial Content` en volúmenes privados (activable/desactivable por perfil mediante `ui.allow_video_preview`).
  - **PDF (`.pdf`):** Visor PDF en `<iframe>` aislado dentro del Lightbox (activable/desactivable mediante `ui.allow_pdf_preview`).
  - **Audio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) y texto/código (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql`, etc. — límite de 64 KB).**
  - En modo `inline`, hacer **doble clic** en cualquier archivo previsualizable (o pulsar `Espacio`) abre el Lightbox al instante.
- **Cola inteligente de subidas (Pausar / Reanudar / Cancelación confirmada):**
  - Sube archivos **o jerarquías enteras de carpetas** (`webkitGetAsEntry`) mediante arrastrar y soltar, o pega imágenes directamente desde el portapapeles con `Ctrl+V`.
  - Las subidas por fragmentos muestran contadores en vivo (`2/5 • 40%`), botones de un clic **Pausar (`⏸`) / Reanudar (`▶`)**, **Cancelar (`✕`)** con confirmación, acciones por lotes **Pausar todo / Cancelar todo** y limpieza automática en el servidor de fragmentos temporales parciales al cancelar.
- **Selección múltiple (con lazo de selección), portapapeles y búsqueda profunda:**
  - Haz clic y arrastra en un espacio vacío para dibujar un **rectángulo de selección (lazo / marquee)** alrededor de varios archivos y carpetas (mantén pulsado `Ctrl`/`Cmd` para sumar a la selección actual).
  - Selección por rango con `Shift+Clic`, alternado individual con `Ctrl+Clic`, seleccionar todo con `Ctrl+A` y `Ctrl+C`/`Ctrl+X`/`Ctrl+V` entre carpetas e incluso entre volúmenes.
  - **Cabeceras de columna ordenables en Lista:** Haz clic en **Nombre**, **Tipo**, **Tamaño** o **Fecha** en la vista Lista para alternar el orden ascendente o descendente.
  - **Deslizador en vivo de tamaño de tarjetas y tarjetas de carpeta:** Ajusta el tamaño de las tarjetas en tiempo real (`96 px – 320 px`) y alterna entre carpetas compactas (`tile`) y tarjetas completas (`card`) desde el menú Configuración (`⚙`).
  - Activa el botón de búsqueda profunda en la barra de búsqueda para buscar recursivamente en todas las subcarpetas, incluyendo `#etiquetas` y metadatos `alt`.
  - Carpetas fijadas en **⭐ Acceso rápido** en la barra lateral, modal clicable de **Uso de almacenamiento y cuota** y selector de modo de color **Automático / Claro / Oscuro**.
- **Validación en 0 ms de resolución y relación de aspecto (`image_constraints`):** Cuando hay reglas de dimensiones o relación de aspecto (`1:1`, `16:9`, etc.), las imágenes no conformes se rechazan inmediatamente en el navegador antes de subirlas (y se vuelven a validar en el servidor).
- **Metadatos editoriales, punto focal y enlaces compartidos firmados:**
  - Edita `alt`, `title`, `caption`, `#tags`, selector clicable de **Punto focal** `[x, y]` y botón opcional **✨ Sugerir con IA**.
  - Genera enlaces compartidos temporales firmados con HMAC (`15 minutos`, `1 hora`, `24 horas`, `7 días`, `30 días`).
- **Editor de imágenes Canvas integrado:** Recorte libre o con proporción bloqueada (`1:1`, `4:3`, `16:9`), redimensionado en píxeles (`Ancho máx.`/`Alto máx.`), control de formato de salida (`JPEG`/`PNG`/`WebP`) y calidad, rotación de 90°, volteo horizontal/vertical y Guardar como copia / Sobrescribir.

| Metadatos del archivo y punto focal (`I`) | Editor de imágenes Canvas integrado (`E`) |
| :---: | :---: |
| ![Metadatos del archivo y punto focal](../images/file-metadata.png) | ![Editor de imágenes Canvas integrado](../images/image-editor.png) |

- **Internacionalización global, RTL y accesibilidad (WCAG 2.1 AA):**
  - **12 idiomas de interfaz integrados y RTL automático (`dir="rtl"`):** Compatible con `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` y `zh_CN`. Árabe (`ar`), persa (`fa`), hebreo (`he`), urdu (`ur`) y otros idiomas RTL invierten automáticamente diseños, rutas de navegación, barras laterales y bordes OffCanvas usando CSS lógico (`inset-inline`, `margin-inline`).
  - **Seguridad CJK / IME:** Impide que `Enter` o los atajos se disparen prematuramente mientras se compone texto en chino, japonés o coreano (`event.isComposing`).
  - **Accesibilidad completa por teclado y lectores de pantalla:** Trampa de foco (`Tab`/`Shift+Tab`) en modales y Lightbox, navegación con flechas en menús contextuales, semántica `role="tree"` / `aria-expanded` / `aria-current` y restauración automática del foco al cerrar.
- **Atajos de teclado (referencia completa):**
  - Todos los atajos se activan únicamente cuando el gestor de archivos tiene el foco, fuera de campos de texto (`<input>`, `<textarea>`, `<select>`), y respetan estrictamente los **permisos del Voter** del usuario (`ui.keyboard_shortcuts: true`).
  - En macOS, **`Cmd (⌘)`** funciona igual que `Ctrl`.
  - **Protección para teclados internacionales e IME:** Utiliza códigos físicos de tecla (`event.code` `Key*`) con respaldo de caracteres para que los atajos funcionen en teclados no QWERTY y no latinos, manteniéndose en silencio durante la composición IME CJK (`isComposing`).

#### A. Operaciones con archivos y carpetas
| Atajo | Acción | Condición / Nota |
| :--- | :--- | :--- |
| `N` | Crear nueva carpeta | Fuera de la papelera (permiso `create_folder`) |
| `U` | Abrir diálogo de subida | Fuera de la papelera (permiso `upload`) |
| `F2` | Renombrar elemento | Un solo archivo o carpeta seleccionado (permiso `rename`) |
| `M` | Abrir diálogo Mover / Copiar | 1 o más elementos seleccionados (permiso `move` o `copy`) |
| `D` | Duplicar archivo | **Un solo archivo** seleccionado (permiso `clone`) |
| `I` | **Carpeta:** Color de carpeta / **Archivo:** Información, SEO y punto focal | Un solo elemento seleccionado (permiso `edit_metadata`) |
| `E` | Abrir editor de imágenes Canvas integrado | Una sola imagen rasterizada (`.jpg`, `.jpeg`, `.png`, `.webp`) seleccionada (permiso `upload`) |
| `L` | Copiar enlace del archivo al portapapeles | Un solo archivo seleccionado (permiso `download`) |
| `S` | Abrir modal de enlace compartido firmado HMAC | Un solo archivo seleccionado (permiso `share` + `share.enabled: true`) |
| `Supr` (`Delete`) | Eliminar elementos seleccionados (papelera o permanente) | 1 o más elementos seleccionados (permiso `delete`) |

#### B. Navegación y vista previa
| Atajo | Acción | Condición / Nota |
| :--- | :--- | :--- |
| `/` o `Ctrl + F` | Enfocar búsqueda | Cuando `show_search: true` (`Ctrl + F` funciona también dentro de campos de entrada) |
| `←` `→` `↑` `↓` | Desplazarse entre tarjetas de cuadrícula o filas de lista | — |
| `Enter` | **Carpeta:** abrir • **Archivo único:** abrir Lightbox en modo en línea o confirmar selección en modo Modal/OffCanvas | — |
| `Espacio` (`Space`) | Previsualizar archivo seleccionado en Lightbox | Un solo archivo seleccionado |
| `Retroceso` (`Backspace`) o `Alt + ↑` | Subir a la carpeta superior | Dentro de una subcarpeta |
| `Alt + ←` / `Alt + →` | Ir atrás / adelante en el historial | Se invierte automáticamente en idiomas **RTL** (árabe, persa, hebreo) |
| `R` | Actualizar el directorio actual desde el servidor (`?refresh=1`) | Cuando `show_refresh_button: true` |

#### C. Selección, portapapeles y cierre por capas
| Atajo | Acción | Condición / Nota |
| :--- | :--- | :--- |
| `Ctrl + A` | Seleccionar todos los elementos visibles | Fuera de la papelera |
| `Ctrl + C` / `Ctrl + X` | Copiar / Cortar elementos seleccionados al portapapeles | Cuando no hay texto subrayado (permiso `copy` / `move`) |
| `Ctrl + V` | Pegar archivos/carpetas del portapapeles en el directorio actual **o** subir una imagen/archivo directamente desde el portapapeles del SO | También funciona con el modal de subida abierto |
| `Shift + Clic` / `Ctrl + Clic` | Selección por rango / alternar elementos individuales | También puedes arrastrar en un espacio vacío para selección en lazo (marquee) |
| `Esc` | **Cierre inteligente en 4 niveles:** 1) Cerrar menú/modal/lightbox/configuración/panel móvil abierto → 2) Limpiar selección → 3) Limpiar portapapeles → 4) Salir de pantalla completa en línea | Cierra solo la capa superior en cada pulsación |
| `Tab` / `Shift + Tab` | Recorrer los controles interactivos dentro de un modal abierto o Lightbox (trampa de foco) | — |

#### D. Vista y ayuda
| Atajo | Acción |
| :--- | :--- |
| `V` | Alternar entre vista Cuadrícula y Lista |
| `B` | Mostrar u ocultar la barra lateral del árbol de carpetas |
| `F` | Alternar modo pantalla completa |
| `T` | Abrir o cerrar la Papelera (cuando `trash.enabled: true`) |
| `?` o `؟` árabe | Abrir modal de ayuda de atajos de teclado |

#### E. Dentro de la vista previa Lightbox
| Atajo | Acción |
| :--- | :--- |
| `←` / `→` | Imagen anterior / siguiente *(invertido automáticamente en idiomas RTL)* |
| `+`, `=` o `Numpad +` | Acercar |
| `-` o `Numpad -` | Alejar |
| `0` o `Numpad 0` | Restablecer zoom al `100%` |
| `Esc` | Cerrar Lightbox |

#### F. Dentro de un menú contextual abierto
| Atajo | Acción |
| :--- | :--- |
| `↑` / `↓` | Subir / bajar por las opciones del menú |
| `Inicio` (`Home`) / `Fin` (`End`) | Saltar a la primera / última opción del menú |
| `Enter` / `Espacio` | Ejecutar la acción enfocada del menú |
| `Tab` / `Esc` | Cerrar el menú contextual |

---

[← Anterior: 02 — Configuración y perfiles](02-configuration-and-profiles.md) | [Índice de documentación](README.md) | [Siguiente: 04 — Temas, iconos y personalización →](04-themes-icons-and-customization.md)
