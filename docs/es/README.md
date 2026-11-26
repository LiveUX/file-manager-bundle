<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Gestor de archivos y DAM para Symfony UX" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — Documentación en español

**Idioma:** [English](../en/README.md) · [Türkçe](../tr/README.md) · [Français](../fr/README.md) · [Deutsch](../de/README.md) · **Español** · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · [日本語](../ja/README.md) · [简体中文](../zh_CN/README.md) · [README principal](../../README.md)

**LiveUX FileManagerBundle** es un **gestor de archivos y gestor de activos digitales (DAM) para Symfony UX** moderno, de alto rendimiento y de nivel empresarial, diseñado para Symfony 6.4 LTS, 7.2+ y 8.x (PHP 8.3+). Incluye **cero dependencias de frameworks CSS**, 14 capas de seguridad integradas y soporte multilingüe global (12 idiomas de interfaz integrados, detección de locales BCP-47 / POSIX y RTL nativo).

---

## Vista previa en vivo y capturas de pantalla

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.shields.io/badge/YouTube-Watch_Full_14--Chapter_1080p_Showcase_(03:52)-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="Watch Full 14-Chapter 1080p Showcase on YouTube">
  </a>
</p>

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.youtube.com/vi/H2CAf0RiDd8/maxresdefault.jpg" alt="LiveUX FileManagerBundle — Full 14-Chapter 1080p YouTube Showcase" width="100%">
  </a>
</p>

<p align="center">
  <img src="../images/demo-showcase.gif" alt="Demostración interactiva de LiveUX FileManagerBundle" width="100%">
</p>

> **Video demostrativo Full HD (1080p, ~3 min) de 10 capítulos** para YouTube y lanzamientos: [YouTube (1080p)](https://www.youtube.com/watch?v=H2CAf0RiDd8) · [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · Vista previa corta: [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| Modo claro (`Light`) | Modo oscuro (`Dark`) |
| :---: | :---: |
| ![Gestor de archivos en modo claro](../images/browser-light.png) | ![Gestor de archivos en modo oscuro](../images/browser-dark.png) |

| Personalización de color e icono de carpeta | Diálogo «Acerca de» (`LiveUX`) |
| :---: | :---: |
| ![Personalización de color e insignia de carpeta](../images/folder-customize.png) | ![Modal Acerca de](../images/about-modal.png) |

### Demostraciones de editores de texto enriquecido, EasyAdmin y formularios dinámicos (GIF)

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![Integración con TinyMCE](../images/demo-editor-tinymce.gif) | ![Integración con CKEditor 5](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2 (ProseMirror) | Quill 2 |
| :---: | :---: |
| ![Integración con Tiptap 2](../images/demo-editor-tiptap.gif) | ![Integración con Quill 2](../images/demo-editor-quill.gif) |

| Trix (`TextEditorField` de EasyAdmin) | Summernote |
| :---: | :---: |
| ![Integración con Trix](../images/demo-editor-trix.gif) | ![Integración con Summernote](../images/demo-editor-summernote.gif) |

| Editor.js (Editor por bloques) | GrapesJS (Constructor visual de páginas) |
| :---: | :---: |
| ![Integración con Editor.js](../images/demo-editor-editorjs.gif) | ![Integración con GrapesJS](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5 (`FileManagerField`) |
| :---: | :---: |
| ![Integración con Meta Lexical](../images/demo-editor-lexical.gif) | ![Integración con EasyAdmin 5](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` y `LiveCollectionType` |
| :---: |
| ![Integración con CollectionType y LiveCollectionType](../images/demo-collections.gif) |

---

## Hoja de ruta rápida (2 minutos)

¿No sabes por dónde empezar? Ve directamente a la guía que coincida con tu objetivo:

- **Instalación por primera vez:** [01 — Requisitos e instalación](01-requirements-and-installation.md) → [02 — Configuración y perfiles](02-configuration-and-profiles.md)
- **Agregar un selector de imágenes o galerías a un formulario Symfony:** [05 — Integraciones de FormType y editores](05-formtype-and-editor-integrations.md)
- **Reforzar la seguridad y RBAC para producción:** [06 — Seguridad y autorización](06-security-and-authorization.md)
- **Conectar Supabase, AWS S3 o Cloudflare R2:** [07 — Almacenamiento en la nube y configuración dinámica](07-cloud-storage-and-dynamic-config.md)
- **Usar almacenamiento de metadatos con Doctrine ORM / DBAL:** [13 — Integración con Doctrine](13-doctrine-integration.md)
- **Evitar eliminar o mover activos referenciados en tu base de datos:** [10 — Protección de uso de activos](10-asset-usage-guard.md)

---

## Tabla de contenidos (Índice de documentación)

| Capítulo | Guía | Resumen |
| :---: | :--- | :--- |
| **01** | [Requisitos, dependencias e instalación](01-requirements-and-installation.md) | Requisitos de PHP 8.3+ / Symfony 6.4 LTS, 7.2+ y 8.x, extensiones PHP (incluidas `ext-exif` y `ext-zip`), dependencias Composer, configuración con AssetMapper y Webpack Encore, `file-manager:doctor` y tu primera página funcional |
| **02** | [Configuración y perfiles (Referencia YAML)](02-configuration-and-profiles.md) | `defaults`, `volumes`, `profiles`, rutas dinámicas sin colisiones (`{user_id}`, `{Y}/{m}`), límites de subida y fragmentos, normalización automática de nombres, optimización y restricciones de imágenes, miniaturas, papelera, ZIP, 5 recetas listas para producción y referencia completa del esquema YAML |
| **03** | [Modos de uso y API de JavaScript](03-usage-modes-and-js-api.md) | `TwigComponent` en línea, modal `<dialog>` y OffCanvas en 4 direcciones, referencia completa de `window.LiveUXFileManager.open()`, eventos DOM personalizados, accesibilidad RTL/IME/WCAG 2.1 AA, menús contextuales, editor de imágenes integrado y atajos de teclado |
| **04** | [Temas, conjuntos de iconos y personalización](04-themes-icons-and-customization.md) | 9 temas integrados conformes a WCAG 2.1 AA (Bootstrap 5, Tailwind, Shadcn, Flowbite 4, DaisyUI 5, Tabler, Preline, Material, Unstyled), modo oscuro automático/manual, 6 conjuntos de iconos SVG (49 iconos de interfaz + 14 de insignia), helpers Twig y 3 niveles de personalización visual |
| **05** | [Integraciones de FormType y editores](05-formtype-and-editor-integrations.md) | `FileManagerPickerType` (selección única y ordenación accesible de galerías múltiples + protección POST), 5 variantes integradas (`compact`, `card`, `dropzone`, `avatar`, `input`), `CollectionType` / `LiveCollectionType`, plantillas personalizadas, TinyMCE, CKEditor 5, Tiptap y `FileManagerField` de EasyAdmin con 16 métodos |
| **06** | [Seguridad y autorización (RBAC)](06-security-and-authorization.md) | 17 permisos de Voter, Voters personalizados `unanimous`, reglas de firewall en `security.yaml`, 14 capas de defensa (CSRF, path traversal, 69 extensiones / 47 tipos MIME, `SvgSanitizer` de 55 etiquetas, protección SSRF contra DNS rebinding, confinamiento de symlinks con `ConfinedLocalFilesystemAdapter`, enlaces compartidos HMAC, registro de auditoría) y endurecimiento de Apache/Nginx |
| **07** | [Almacenamiento en la nube (S3/R2/MinIO/Supabase) y configuración dinámica](07-cloud-storage-and-dynamic-config.md) | Arquitectura multivolumen local/nube, guías por proveedor ([Supabase](cloud/supabase-storage.md), [AWS S3](cloud/aws-s3.md), [Cloudflare R2](cloud/cloudflare-r2.md), [Backblaze B2](cloud/backblaze-b2.md), [MinIO/RustFS](cloud/minio-rustfs.md), [Google Cloud Storage](cloud/google-cloud-storage.md), [SFTP y Flysystem personalizado](cloud/sftp-and-custom-flysystem.md)), `FlysystemServiceLocatorPass`, `ConfigProviderInterface` y almacenamiento personalizado de metadatos |
| **08** | [Eventos, comandos CLI, caché y solución de problemas](08-events-commands-cache-and-troubleshooting.md) | 9 eventos de dominio Symfony, 10 comandos de consola (`doctor`, `stats`, `duplicates`, `ai:enrich`, `skills:install`, etc.), caché de 3 capas y aislamiento HTTP 304/CDN sensible al contexto, sistema global de traducción en 12 idiomas BCP-47/POSIX/RTL (493 claves) y preguntas frecuentes |
| **09** | [Integración con Symfony AI y enriquecimiento inteligente](09-symfony-ai-integration.md) | `AssetEnricherInterface` sin dependencias obligatorias, puente con `symfony/ai-bundle` (OpenAI, Gemini, Claude, Ollama), **✨ Sugerir con IA**, CLI por lotes `file-manager:ai:enrich`, ejemplos asíncronos con Messenger y filtrado de rechazos e inyecciones de prompts en 11 idiomas |
| **10** | [Protección de uso de activos](10-asset-usage-guard.md) | Contrato opcional `AssetUsageCheckerInterface`, políticas `warn` / `block`, protección del lado del servidor en dos capas (`enforce_server_side`) para **`delete`, `rename`, `move` y `overwrite`**, y ejemplos Doctrine para MySQL, PostgreSQL y SQLite |
| **11** | [Guía de contribución y Pull Requests](11-contributing-and-pr-guide.md) | Flujo de PR de código abierto accesible para principiantes, puerta de calidad `composer qa` (PHPStan Nivel 8, 360+ pruebas PHPUnit, 37+ pruebas JS), CI de 6 trabajos en GitHub Actions y configuración de Branch Protection en GitHub |
| **12** | [Guía de habilidades para agentes IA (`SKILL.md` y `AGENTS.md`)](12-ai-agent-skills-guide.md) | 6 paquetes modulares `SKILL.md` para Antigravity, Claude Code, Cursor, GitHub Copilot, Codex y Symfony AI Mate, instalación en un comando `file-manager:skills:install` y validación CI |
| **13** | [Integración con Doctrine ORM y DBAL](13-doctrine-integration.md) | Driver integrado `metadata.driver: 'doctrine'` (`DoctrineDbalMetadataStorage`: UPSERT atómico, índice PostgreSQL `text_pattern_ops`, `saveMany()` por lotes), migraciones multiplataforma, Entity + `FileManagerPickerType`, almacenamiento ORM personalizado compatible con `ResetInterface` y sincronización en BD con `ItemRenamedEvent` |
| **14** | [Auditoría de seguridad, revisión lógica e informe de cambios](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(solo en turco)* | Auditoría de seguridad línea por línea (rondas 1–18), aislamiento multiinquilino disjunto `{user_id}`, endurecimiento de DI/Voter/Parser/SVG `<use>` con presupuesto de pasos y protección CDN/suplantación de origen, confinamiento de symlinks, PostgreSQL `text_pattern_ops` y UPSERT atómico, auditoría WCAG 2.1 AA de contraste/enfoque en 9 temas, diálogos móviles y registro de auditoría i18n |

---

## Aspectos destacados de la arquitectura

- **Velocidad híbrida de 0 ms:** `TwigComponent` SSR rápido + caché en memoria del navegador (`dirCache`) + árbol de carpetas completo en una sola solicitud. El filtrado, la búsqueda, la ordenación, la paginación, el cambio entre Cuadrícula y Lista y el despliegue o repliegue de carpetas ocurren al instante sin viajes AJAX adicionales.
- **Cero dependencias de frameworks CSS y WCAG 2.1 AA:** Toda la interfaz usa clases con ámbito `.liveux-fm-*` y propiedades personalizadas CSS (`--liveux-fm-*`). Convive limpiamente con **Bootstrap 5, Tailwind CSS, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** o **sin ningún framework CSS**, sin colisiones de estilos ni configuración extra de purga en Tailwind.
- **Capa superior nativa HTML5 `<dialog>` y ergonomía mobile-first:** Los diálogos Modal y OffCanvas (`start`, `end`, `top`, `bottom`) se renderizan en la capa superior (Top Layer) del navegador y en móviles se adaptan automáticamente a pantalla completa (`100dvh`) o a una hoja inferior nativa con asa de arrastre.
- **Seguridad empresarial en 14 capas:** RBAC con `Voter` de Symfony, protección CSRF (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `FileSecurityGuard`, `SvgSanitizer` de 55 etiquetas, `RemoteUrlImporter` protegido contra DNS rebinding, confinamiento de enlaces simbólicos con `ConfinedLocalFilesystemAdapter`, protección contra bombas de descompresión/píxeles y cabeceras `CSP: sandbox` + `nosniff` activadas de serie.
- **Arquitectura multilingüe global:** **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` con 493 claves sincronizadas cada uno), normalización de locales BCP-47 y POSIX `.UTF-8`, respaldo garantizado a `'en'`, RTL automático (`dir="rtl"`) para árabe y otros 14 idiomas RTL, y seguridad para composición IME CJK.
- **Listo para múltiples servidores y la nube:** `DoctrineDbalMetadataStorage` integrado (PostgreSQL `text_pattern_ops`, UPSERT atómico en MySQL/MariaDB/SQLite), Flysystem v3 (S3, R2, Supabase, B2, MinIO, GCS, SFTP) y compatibilidad con `ResetInterface` para FrankenPHP y workers de Symfony Messenger.

---

## Lista de verificación de inicio rápido (5 minutos)

1. **Instala el bundle:** `composer require liveux/file-manager-bundle`
2. **Importa las rutas:** `config/routes/liveux_file_manager.yaml`
3. **Define los perfiles:** `config/packages/liveux_file_manager.yaml`
4. **CSS y JS (cero configuración):** `autoimport` de Symfony UX carga automáticamente `core.css`, `file_manager_api.js` y los 3 controladores Stimulus mediante `assets/controllers.json`, tanto en AssetMapper como en Webpack Encore.
5. **Contenedor de diálogos (automático):** `AutoInjectDialogHostSubscriber` inyecta automáticamente `DialogHost` justo antes de `</body>` en páginas HTML completas (`defaults.auto_inject_dialog_host: true`), sin necesidad de editar `base.html.twig`.
6. **Verifica tu configuración:** `php bin/console file-manager:doctor`

> ¿Es tu primera vez con Symfony UX? Empieza paso a paso con **[01 — Requisitos, dependencias e instalación](01-requirements-and-installation.md)**.

---

**Organización:** [LiveUX en GitHub](https://github.com/LiveUX) · [Repositorio](https://github.com/LiveUX/file-manager-bundle)  
**Licencia:** MIT
