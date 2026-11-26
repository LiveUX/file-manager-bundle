# 12 — Guía de habilidades para agentes de IA (`SKILL.md` y `AGENTS.md`)

[← Anterior: 11 — Guía de contribución y PR](11-contributing-and-pr-guide.md) | [Índice de documentación](README.md) | [Siguiente: 13 — Integración con Doctrine →](13-doctrine-integration.md)

> [!TIP]
> **En pocas palabras: ¿para qué sirven las Agent Skills (`SKILL.md`)?**
> Si utilizas un asistente de programación con IA como **Antigravity, Cursor, Claude Code, GitHub Copilot u OpenAI Codex**, ejecuta `php bin/console file-manager:skills:install` una sola vez en tu proyecto. Esto enseña a tu asistente las claves exactas de configuración YAML del bundle, sus invariantes de seguridad y ejemplos idiomáticos, para que genere código funcional a la primera en lugar de inventar opciones inexistentes.

**LiveUX FileManagerBundle** incluye de serie un paquete de habilidades que sigue la especificación abierta **Agent Skills (`SKILL.md`)**, permitiendo que los asistentes de desarrollo con IA modernos (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** y **Symfony AI Mate**) integren el bundle con precisión, seguridad y sin suposiciones.

---

## 1. Las 6 habilidades especializadas incluidas en el bundle

Las habilidades siguen un esquema de **divulgación progresiva** (*progressive disclosure*): tu asistente de IA carga únicamente la habilidad pertinente a la tarea actual, manteniendo el uso de contexto ligero y enfocado:

| Nombre de la habilidad (`skills/<name>/`) | Cuándo se activa | Archivo de referencia |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Instalación, enrutamiento, AssetMapper/Encore, perfiles y volúmenes (Local/S3/R2/MinIO/Supabase), cuotas (`storage_quota`, `show_quota`), optimización de imágenes y detección de duplicados | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (único/galería), 5 variantes integradas, plantillas Twig personalizadas para FormType (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, API JS, TinyMCE, CKEditor 5 y Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 temas integrados, modo oscuro (`color_scheme`), temas personalizados solo CSS o con 10 bloques Twig, sistema de iconos SVG en 3 capas (49 iconos de interfaz + 14 insignias de carpeta) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | Firewall de `security.yaml`, 17 permisos de Voter, Voters personalizados por carpeta (`unanimous`), Asset Usage Guard (`AssetUsageCheckerInterface` — eliminar/renombrar/mover/sobrescribir), enlaces compartidos firmados, Audit Log y endurecimiento de Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | `ConfigProviderInterface` dinámico en base de datos, `FolderMetadataStorageInterface` personalizado, 9 listeners de Symfony Events, Symfony AI (`AssetEnricherInterface`) y todos los comandos CLI | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Reglas de desarrollo del bundle para compatibilidad con PHP 8.3 / Symfony 7.2–8.0, invariantes de seguridad, paridad de traducciones y la puerta de calidad `composer qa` | `AGENTS.md` (raíz del paquete) |

---

## 2. Tres formas de instalar las habilidades en tu proyecto

### Método A — Comando integrado de Symfony Console (recomendado, sin dependencias adicionales)

```bash
# Para Antigravity, Codex, OpenCode y Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Para Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Para Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Para GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Instalar en todas las herramientas de IA soportadas con un solo comando
php bin/console file-manager:skills:install --target=all
```

### Todas las opciones del comando

| Opción / Atajo | Descripción | Ejemplo |
| :--- | :--- | :--- |
| `-t` / `--target` | Herramienta de IA destino (`agents`, `claude`, `cursor`, `copilot`, `all`). Valor por defecto: `agents`. | `-t all` |
| `--dir` | Ruta personalizada del directorio de destino. **Regla de seguridad:** debe ser estrictamente un subdirectorio dentro de `%kernel.project_dir%` (se rechazan `..`, bytes NUL, la propia raíz del proyecto y directorios padres con enlaces simbólicos). | `--dir=.custom/skills` |
| `--only` | Instala únicamente las habilidades seleccionadas (nombres cortos o completos separados por comas). | `--only=setup,security` |
| `-f` / `--force` | Sobrescribe los directorios de habilidades existentes con la última versión. | `--force` |
| `--symlink` | Crea enlaces simbólicos en lugar de copiar archivos (recurre automáticamente a la copia en Windows si faltan permisos para crear enlaces simbólicos). | `--symlink` |
| `--dry-run` | Muestra una vista previa de qué habilidades se instalarían y en qué ruta, sin escribir nada en disco. | `--dry-run` |

### Método B — Descubrimiento automático con Symfony AI Mate (`symfony/ai-mate`)

El archivo `composer.json` del bundle declara `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Si tienes `symfony/ai-mate` instalado en tu proyecto, un único comando descubre y sincroniza las 6 habilidades en tu espacio de trabajo:

```bash
vendor/bin/mate discover
```

### Método C — CLI abierta de Agent Skills (`npx skills`)

Instala directamente desde GitHub en cualquier proyecto:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Archivo `AGENTS.md` en la raíz del paquete

El archivo `AGENTS.md` ubicado en la raíz del bundle establece las reglas siempre activas para los agentes de IA que contribuyen directamente al propio bundle:
- Cero dependencias de frameworks CSS y reglas para bridges opcionales
- Tipado estricto en PHP (`declare(strict_types=1)`) y stubs de prueba compatibles con Symfony 7.2 / 8.0
- Prohibición del filtro `|raw` en Twig y uso obligatorio de `isSafeUrl()` / `escapeHtml()` en JavaScript
- Validación CSRF en primer lugar, aislamiento de caché privada/por Voter y paridad de traducciones al 100 % en los **12 idiomas de interfaz integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Verificación automática en CI

En cada PR y ejecución de CI, `SkillsPackageIntegrityTest` comprueba automáticamente que:
1. `AGENTS.md` existe, se mantiene dentro del presupuesto de contexto **`<= 24,000 bytes`** y no contiene frontmatter YAML por accidente.
2. `composer.json` define correctamente `extra.ai-mate.skills`.
3. Cada directorio bajo `skills/` contiene un `SKILL.md` con frontmatter YAML válido donde `name` coincide exactamente con el nombre del directorio, declara `license: MIT`, tiene una `description` de al menos `40` caracteres y no supera las `<= 500` líneas.
4. Todos los enlaces relativos `references/*.md` dentro de cada `SKILL.md` apuntan a un archivo existente en disco.

---

[← Anterior: 11 — Guía de contribución y PR](11-contributing-and-pr-guide.md) | [Índice de documentación](README.md) | [Siguiente: 13 — Integración con Doctrine →](13-doctrine-integration.md)
