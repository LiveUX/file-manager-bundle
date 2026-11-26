# 11 — Guía de contribución y Pull Requests (PR) (paso a paso para principiantes)

[← Anterior: 10 — Protección de activos en uso (Asset Usage Guard)](10-asset-usage-guard.md) | [Índice de documentación](README.md) | [Siguiente: 12 — Habilidades para agentes de IA →](12-ai-agent-skills-guide.md)

Esta guía está pensada desde cero para desarrolladores que **nunca antes han abierto un Pull Request (PR) en un proyecto de código abierto**, así como para mantenedores que quieran configurar una **puerta de calidad automatizada para PR al estilo de Symfony** en su propio repositorio. Cada concepto y cada comando se explican paso a paso.

---

## 1. Visión general: cómo funcionan los Pull Requests en código abierto

En proyectos consolidados de código abierto como Symfony, Laravel o React, los colaboradores externos no pueden hacer `push` directamente sobre la rama principal (`main`). En su lugar, todo cambio atraviesa un **proceso de validación en 4 etapas**:

1. **Fork y rama (obtén tu propia copia):** Copia el repositorio en tu cuenta de GitHub (*Fork*) y crea una rama de trabajo dedicada (*Branch*).
2. **Código y comprobaciones locales (`composer qa`):** Implementa tu cambio, escribe pruebas y ejecuta toda la suite automatizada de calidad en tu máquina.
3. **Abrir un Pull Request (PR):** Envía una solicitud para que los mantenedores revisen e integren tu corrección o funcionalidad en el repositorio principal. GitHub muestra automáticamente una **tabla resumen y lista de comprobación al estilo de Symfony** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Comprobaciones automáticas de CI (GitHub Actions) + revisión de código:**
   - En cuanto se abre o actualiza un PR, GitHub Actions (`.github/workflows/ci.yml`) lanza ejecutores en la nube de forma automática.
   - Se prueba el código en **PHP 8.3 y 8.4**, **Symfony 7.2, 7.4 y 8.0** y **Node 20 y 22**, y se verifican el estilo de código (**PHP-CS-Fixer**), la seguridad de tipos estática (**PHPStan Level 8**), la paridad de traducciones (**TranslationParityTest**), la fidelidad del esquema YAML de la documentación (**FullConfigReferenceDocTest**), la integridad de las Agent Skills (**SkillsPackageIntegrityTest**) y la seguridad XSS/CSP/frontend (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **Si falla aunque sea una sola comprobación, GitHub bloquea el botón Merge.**

---

## 2. ¿Está ya configurada la infraestructura de calidad?

**Sí, está configurada al 100 % y lista para usar.** El bundle incluye una cadena completa de herramientas de calidad modelada directamente a partir de los estándares oficiales de Symfony UX:

| Archivo | Propósito (en términos claros) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Define la suite de scripts en un solo comando `composer qa` y las dependencias de desarrollo independientes (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Ejecuta todas las pruebas unitarias y de seguridad en PHP (**57 archivos de test, 360 tests, más de 3.500 aserciones**) de forma autónoma sin necesitar una aplicación anfitriona. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Configura el análisis estático **PHPStan Level 8** para detectar incoherencias de tipos ocultas, argumentos inválidos y llamadas a métodos inexistentes antes de llegar a ejecución. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Aplica el estándar de código PSR-12 + Symfony en todos los archivos PHP (`declare(strict_types=1)`, comparaciones estrictas `===`, sentencias `use` ordenadas alfabéticamente). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Ejecuta **6 trabajos de CI en paralelo** automáticamente en cada Pull Request nuevo o actualizado. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Rellena automáticamente cada nuevo PR con una tabla resumen al estilo de Symfony (`Bug fix?`, `BC breaks?`, `Tests pass?`) y una lista de comprobación. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Proporciona formularios estructurados para informes de errores (`bug_report.yml`) y solicitudes de nuevas funcionalidades (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Guía internacional para colaboradores que GitHub enlaza automáticamente al abrir un PR o un Issue. |
| [`SECURITY.md`](../../SECURITY.md) | Política de divulgación de seguridad que explica cómo reportar vulnerabilidades en privado en lugar de abrir un Issue público. |

### 7 reglas críticas verificadas automáticamente

Incluso si un revisor humano pasa por alto alguno de estos problemas, **la suite de pruebas automatizadas lo detecta y hace fallar el PR de inmediato**:

1. **Traducciones faltantes (`TranslationParityTest`):** Si se añade una nueva cadena de interfaz en inglés pero falta en alguno de los **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), el test **FALLA**.
2. **Falta de `strict_types` (`TranslationParityTest`):** Si aunque sea un solo archivo PHP dentro de `src/` omite `declare(strict_types=1);`, el test **FALLA**.
3. **Infracciones de seguridad XSS / CSP / Frontend (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Añadir manejadores en línea como `onclick="..."` o el filtro `|raw` en Twig, o romper el saneamiento de URLs `javascript:` / DOM en JS, hace que el test **FALLE**.
4. **Desajuste en el esquema YAML de la documentación (`FullConfigReferenceDocTest`):** Todos los bloques YAML `liveux_file_manager:` de la referencia [Configuración y perfiles](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) se validan contra el esquema real de `Configuration.php`.
5. **Integridad de Agent Skills (`SkillsPackageIntegrityTest`):** Valida el frontmatter, los límites de líneas/tamaño y los enlaces de referencia en `AGENTS.md` y en los 6 archivos `skills/*/SKILL.md`.
6. **Estilo de código y tipos estáticos (`PHP-CS-Fixer` + `PHPStan Level 8`):** Cualquier infracción de estilo PSR-12/Symfony o error de tipos de nivel 8 bloquea la CI.
7. **Sincronización de `assets/dist/` minificados (`npm --prefix assets run build:check`):** Si modificas `assets/controllers/*.js` o `assets/styles/core.css` y olvidas regenerar `assets/dist/`, la CI **FALLA**.

---

## 2.5. Arquitectura de minificación de JS y CSS (`assets/dist/`) con `esbuild`

> [!NOTE]
> **¿Por qué existe esto?**
> - Quienes usan **Webpack Encore** obtienen minificación automática de JS/CSS al ejecutar `npm run build`.
> - Sin embargo, **Symfony AssetMapper** (`asset-map:compile`) sirve los archivos tal cual sin minificarlos. En su versión fuente sin comprimir, `browser_controller.js` pesa `518 KB` y `core.css` pesa `132 KB`.
> - Para garantizar cargas rápidas a los usuarios de AssetMapper sin exigir Node.js en los proyectos anfitriones, seguimos exactamente el patrón **Source → Distribution (`assets/dist/`)** empleado por los paquetes oficiales de Symfony UX (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### Cómo funciona

1. **Archivos fuente legibles:** Todo el desarrollo y las pruebas de seguridad se ejecutan contra archivos fuente formateados y comentados con claridad:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` y `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` y `assets/images/liveux-logo.webp`
2. **Salida minificada para producción (`assets/dist/`):** El script [`assets/build.mjs`](../../assets/build.mjs) utiliza **`esbuild`** para minificar cada archivo JS como módulo ESM independiente (manteniendo `@hotwired/stimulus` como externo y conservando las importaciones relativas) y compila `core.css` dentro de `assets/dist/`.
3. **Resolución automática (sin cambios incompatibles):**
   - `prependAssetMapper()` en [`FileManagerBundle.php`](../../src/FileManagerBundle.php) mapea automáticamente el namespace `@liveux/file-manager-bundle` a `assets/dist/` siempre que `assets/dist` exista.
   - Las entradas `"exports"` y `"symfony.controllers.*.main"` en [`assets/package.json`](../../assets/package.json) garantizan que Webpack Encore también consuma directamente los archivos de `dist/`.
   - Como `assets/dist/` se incluye en Git, **los usuarios finales nunca necesitan Node.js ni npm**. `.gitattributes` excluye únicamente las herramientas de compilación (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) de los archivos distribuidos en Packagist, manteniendo `assets/package.json` y `assets/dist/`.

### Ahorro de tamaño medido

| Archivo | Fuente sin minificar | Minificado (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 adaptadores de editor | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **TOTAL (`-45.1%` minificado)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Comandos de desarrollo (cada vez que edites JS o CSS)

```bash
# 1. Instala esbuild una sola vez (solo la primera vez):
npm --prefix assets install

# 2. Regenera assets/dist/ tras editar cualquier archivo JS o CSS (~50 ms):
npm --prefix assets run build
# o mediante el atajo de Composer:
composer assets:build

# 3. Comprueba que assets/dist/ está sincronizado al 100 % con los archivos fuente (misma comprobación que en CI):
composer assets:check
```

---

## 3. Guía paso a paso para enviar un Pull Request

> [!TIP]
> **Aunque nunca hayas enviado un PR antes**, sigue estos 6 pasos en orden para abrir un Pull Request limpio y con toda la CI en verde.

### Paso 1: Haz fork, clona e instala las dependencias

1. Abre [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) en GitHub y haz clic en **Fork** en la esquina superior derecha para crear una copia en tu propia cuenta de GitHub.
2. Clona tu fork en local, añade el repositorio principal como `upstream` e instala las herramientas de desarrollo de PHP y Node:

```bash
git clone https://github.com/<TU-USUARIO>/file-manager-bundle.git
cd file-manager-bundle

# Registra el repositorio principal como upstream (buena práctica para mantenerte al día)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Instala las dependencias de tests y análisis estático de PHP
composer install

# Instala el minificador de JS/CSS (esbuild) si vas a editar archivos JS o CSS
npm --prefix assets install
```

### Paso 2: Crea una rama nueva a partir de `main` actualizado

Nunca hagas commits directamente sobre `main`. Sincroniza primero `main` y luego crea una rama descriptiva para tu trabajo:
- Corrección de error: `fix/folder-counter-cache`
- Nueva funcionalidad: `feat/new-icon-set`
- Documentación: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Paso 3: Escribe el código, añade tests y regenera `dist/` si hace falta

- **Al corregir un error:** Empieza escribiendo un test de regresión específico que reproduzca el fallo (en rojo antes de la corrección, en verde después).
- **Al añadir una funcionalidad:**
  - Añade tests unitarios PHP en `tests/Unit/...`.
  - Para cambios de interfaz/JS, añade cobertura en `tests/Js/dom_security_test.mjs` o `tests/Js/frontend_audit_test.mjs`.
  - **Cada vez que modifiques JS o CSS, ejecuta `composer assets:build`** (de lo contrario la CI fallará en `build:check`).
  - Cada vez que añadas una nueva clave de traducción de interfaz, agrégala en **los 12 catálogos de idioma integrados**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Paso 4: Ejecuta todas las comprobaciones de calidad en local de una sola vez

Ejecuta exactamente las mismas comprobaciones que corre la CI antes de abrir tu PR:

```bash
composer qa
composer assets:check
```

Estos comandos verifican, en orden:
1. `composer validate --strict` — Valida `composer.json`.
2. `composer cs:check` — Comprueba los estándares de código PSR-12/Symfony *(si aparecen avisos, ejecuta `composer cs:fix` para formatear automáticamente)*.
3. `composer stan` — Ejecuta el análisis estático **PHPStan Level 8**.
4. `composer test` — Ejecuta la suite completa de PHPUnit (tests unitarios, de seguridad, paridad de traducciones y validación de esquema).
5. `composer test:js:syntax` — Ejecuta la validación sintáctica `node --check` sobre todos los archivos JS fuente y de `dist/`.
6. `composer test:js` — Ejecuta las suites Node.js de seguridad DOM y auditoría frontend (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Confirma que `assets/dist/` coincide byte a byte con los archivos fuente actuales.

> [!TIP]
> **¿Todo en verde?** Ya estás listo para hacer commit y abrir tu Pull Request.

### Paso 5: Crea un commit limpio y súbelo a tu fork

```bash
git status            # Comprueba que solo se han modificado los archivos previstos
git diff --stat       # Revisa un resumen rápido de tus cambios
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Usa prefijos claros de Conventional Commits: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Paso 6: Abre el Pull Request en GitHub y comprueba que la CI pase en verde

1. Abre tu fork en GitHub (`https://github.com/<TU-USUARIO>/file-manager-bundle`) y haz clic en el botón **"Compare & pull request"**.
2. Confirma las ramas de destino y origen: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<TU-USUARIO>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Completa la tabla de la **plantilla de PR** precargada (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) y marca las casillas de la lista de comprobación (`[x]`). Si tu cambio afecta a la interfaz, adjunta una captura de pantalla o un GIF.
4. Haz clic en **"Create pull request"**.
5. Espera a que todas las comprobaciones de GitHub Actions (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) en la parte inferior del PR muestren una **marca de verificación verde (✓)**.
6. Si un revisor solicita cambios, edita los archivos en la misma rama y ejecuta `git commit && git push`: GitHub actualizará el PR y volverá a lanzar la CI automáticamente.

---

## 4. Para mantenedores: proteger ramas y configurar Rulesets en GitHub

Una vez que los archivos de workflow están en el repositorio, la CI se ejecuta de forma automática; sin embargo, para garantizar que **nadie (ni siquiera un mantenedor por accidente) pueda pulsar Merge mientras haya comprobaciones en rojo**, configura un **Ruleset** en GitHub en 2 minutos:

1. Abre el repositorio del bundle en GitHub y entra en **Settings**.
2. En la barra lateral izquierda, selecciona **Rules → Rulesets** (o **Branches → Add branch protection rule** en la vista clásica).
3. Haz clic en **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Activa estas 4 protecciones:
   - [x] **Restrict deletions** (impide eliminar `main`)
   - [x] **Block force pushes** (protege el historial de commits)
   - [x] **Require a pull request before merging** (bloquea los push directos a `main`; todos los cambios entran vía PR)
     - *Required approvals:* `1` (recomendado para equipos; si trabajas en solitario puedes dejarlo en `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(ajuste más importante)*
     - [x] *Require branches to be up to date before merging*
     - Tras completarse la primera ejecución de CI, usa **Add checks** para exigir los **6 trabajos**:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Haz clic en **Create** al final de la página.

---

## 5. Lista de comprobación para publicar el bundle como repositorio open-source independiente

Para publicar el bundle como `LiveUX/file-manager-bundle` en GitHub y Packagist:

1. Crea un repositorio **Public** vacío llamado `file-manager-bundle` dentro de la organización `LiveUX` en GitHub.
2. Sube el contenido de `bundles/liveux-file-manager-bundle/` como commit inicial (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Comprueba en la pestaña **GitHub Actions** que los 6 trabajos de CI pasan con marca verde.
4. Activa la configuración del **Ruleset** de la **Sección 4** anterior.
5. Crea la etiqueta `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) y registra `https://github.com/LiveUX/file-manager-bundle` en [Packagist.org](https://packagist.org).
6. A partir de ese momento, cualquiera podrá instalar el bundle con un único comando: `composer require liveux/file-manager-bundle`.

---

[← Anterior: 10 — Protección de activos en uso (Asset Usage Guard)](10-asset-usage-guard.md) | [Índice de documentación](README.md) | [Siguiente: 12 — Habilidades para agentes de IA →](12-ai-agent-skills-guide.md)
