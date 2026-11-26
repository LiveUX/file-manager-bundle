# 09 — Integración con Symfony AI y enriquecimiento inteligente de activos

[← Anterior: 08 — Eventos, comandos y resolución de problemas](08-events-commands-cache-and-troubleshooting.md) | [Índice principal](README.md) | [Siguiente: 10 — Protección de activos en uso →](10-asset-usage-guard.md)

> [!TIP]
> **En términos sencillos: ¿qué hace esta funcionalidad?**
> Cuando un editor sube la fotografía de un producto, ya no tiene que redactar manualmente *"el texto alternativo, título, leyenda y etiquetas de búsqueda"* para cada archivo. Al hacer clic en **"✨ Sugerir con IA"** en el panel de detalles, el modelo elegido (OpenAI, Gemini, Claude u Ollama local) analiza la imagen y rellena metadatos optimizados para SEO y `#etiquetas` en tu idioma de destino en ~2 segundos; además, nada se guarda hasta que el editor lo revisa y aprueba.
> *(Por defecto, los volúmenes privados nunca se envían a proveedores externos de IA: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** se integra con el ecosistema oficial **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) con **cero dependencias obligatorias**:

- Si `symfony/ai-bundle` **no está instalado**, el bundle funciona sin errores ni sobrecarga de memoria y oculta automáticamente los botones de IA en la interfaz.
- Si `symfony/ai-bundle` **está instalado**, `FileManagerBundle` detecta y conecta automáticamente el puente integrado `SymfonyAiAssetEnricher`.
- Las funciones de IA permanecen **desactivadas a menos que se habiliten explícitamente por perfil (`ai.enabled: true`)**, evitando cargos inesperados en APIs externas.

---

## 1. Capacidades principales

| Capacidad | Descripción |
| :--- | :--- |
| **Texto `alt` automático para SEO y WCAG** | Analiza imágenes y genera texto `alt` apto para lectores de pantalla y buscadores, un `title` conciso y una `caption` en cualquier idioma (`es`, `en`, `tr`, `fr`, `de`, `ja`, `ar`, etc.). |
| **Autoetiquetado inteligente (`tags`)** | Extrae palabras clave descriptivas de imágenes o archivos de texto (p. ej., `["botella térmica", "negro mate", "regalo corporativo"]`) que funcionan al instante con la búsqueda por `#etiqueta` en el gestor de archivos. |
| **Resúmenes de texto y documentos** | Lee los primeros 8 KB de archivos `.txt`, `.md`, `.csv`, `.json` y `.yaml`/`.yml` para sugerir títulos, descripciones y etiquetas de búsqueda. |
| **Optimización automática de costes de tokens** | Las fotos grandes de 10–15 MB nunca se envían en bruto a las APIs de Visión; primero se redimensiona una copia temporal a `max_image_dimension` (por defecto `1024px`), reduciendo el consumo de tokens en ~80%. |
| **Independencia de proveedor** | Cambia entre OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI u **Ollama 100% local y gratuito** modificando una sola línea de YAML. |
| **3 flujos de trabajo** | Botón **"✨ Sugerir con IA"** aprobado por el editor en la interfaz, enriquecimiento masivo por CLI (`file-manager:ai:enrich`) o procesamiento automático en segundo plano con `symfony/messenger`. |

---

## 2. Instalación (elige tu proveedor)

### Opción A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

```bash
composer require symfony/ai-bundle symfony/ai-open-ai-platform
```

```dotenv
# .env.local
OPENAI_API_KEY="sk-..."
```

```yaml
# config/packages/ai.yaml
ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
```

### Opción B — Google Gemini (`gemini-2.5-flash`)

```bash
composer require symfony/ai-bundle symfony/ai-gemini-platform
```

```dotenv
# .env.local
GEMINI_API_KEY="AIza..."
```

```yaml
# config/packages/ai.yaml
ai:
    platform:
        gemini:
            api_key: '%env(GEMINI_API_KEY)%'
```

### Opción C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

```bash
composer require symfony/ai-bundle symfony/ai-anthropic-platform
```

```dotenv
# .env.local
ANTHROPIC_API_KEY="sk-ant-..."
```

```yaml
# config/packages/ai.yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
```

### Opción D — Ollama local y 100% privado (`qwen2.5vl` / `llava`)

Ideal cuando los documentos confidenciales de la empresa no deben salir nunca de tu infraestructura:

```bash
ollama pull qwen2.5vl
composer require symfony/ai-bundle symfony/ai-ollama-platform
```

```yaml
# config/packages/ai.yaml
ai:
    platform:
        ollama:
            host_url: 'http://127.0.0.1:11434'
```

---

## 3. Configuración de LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'es'
        ai:
            default_model: 'gpt-4o-mini'
            # Opcional: apunta a un servicio de plataforma específico si tienes varias plataformas de Symfony AI configuradas
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Por defecto: false (protección de costes)
                model: 'gpt-4o-mini'              # p. ej. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'es'                      # null hereda el idioma activo de la petición o del perfil
                max_tags: 6                       # Límite de etiquetas (1..20)
                max_image_dimension: 1024         # Techo de redimensionado antes de enviar a Vision API (256..2048 px)
                block_private_volumes: true       # Bloquea llamadas externas de IA en volúmenes con private: true
                auto_enrich_on_upload: false      # true se ejecuta síncronamente al subir; déjalo en false si usas Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Desactivado por completo para contratos confidenciales
```

### Orden de resolución del idioma de salida de la IA (`ai.locale`)

Cuando un editor hace clic en **✨ Sugerir con IA**, el idioma de la respuesta se resuelve en este orden exacto (se admiten locales BCP-47, ICU y POSIX `.UTF-8`):

1. Valor explícito `aiLocale` enviado en el cuerpo de la solicitud
2. Ajuste del perfil `profiles.<name>.ai.locale` *(tiene prioridad sobre el idioma de la interfaz cuando está definido)*
3. Idioma activo de la petición HTTP / interfaz
4. Ajuste del perfil `profiles.<name>.locale`
5. `'en'` *(respaldo garantizado)*

---

## 4. Uso de la IA en la interfaz (en un clic y con aprobación del editor)

1. Haz clic derecho sobre una imagen o archivo de texto en el gestor de archivos → **🏷️ SEO y punto focal** (o abre el panel derecho de **Vista previa y detalles**).
2. El botón **✨ Sugerir con IA** se muestra —y se valida estrictamente en el servidor— solo cuando se cumplen **las 5 condiciones**:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - Existe un servicio `AssetEnricherInterface` activo registrado
   - El usuario posee los tres permisos —**`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`**— sobre el archivo y todas sus carpetas padre
   - Si el volumen es `private: true`, se ha configurado explícitamente `block_private_volumes: false`
3. Pulsar el botón **no sobrescribe** de inmediato los metadatos existentes: el `alt`, `title`, `caption` y las `tags` sugeridas por la IA rellenan los campos del formulario para que el editor pueda revisarlos, ajustarlos y pulsar **Guardar**.

---

## 5. Enriquecimiento masivo de archivos existentes por CLI

Rellena textos `alt` y etiquetas faltantes en miles de activos existentes con controles estrictos de coste:

```bash
# 1. Previsualiza qué archivos se enriquecerían sin llamar a la API de IA (simulación gratuita):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Enriquece los primeros 50 archivos sin metadatos en español:
php bin/console file-manager:ai:enrich products --limit=50 --locale=es

# 3. Limita el alcance a un volumen y subcarpeta concretos:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Fuerza la regeneración de metadatos existentes (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **Salvaguardas de seguridad y coste en CLI (`file-manager:ai:enrich`):**
> - `--limit` toma **`50`** por defecto para que nunca envíes por accidente todo un archivo histórico a una API de pago en una sola ejecución.
> - Sin `--force`, solo se procesan archivos con **metadatos editoriales incompletos** (si `alt` está relleno **y** al menos uno de `tags`/`title`/`caption` tiene valor, el archivo se omite).
> - La precarga de metadatos (`preload()`) se ejecuta en lotes de **250 elementos**; los archivos mayores de **20 MB**, los volúmenes dinámicos con `{user_id}` y los volúmenes `private: true` (cuando `block_private_volumes: true`) se omiten automáticamente.

---

## 6. Enriquecimiento asíncrono automático al subir archivos (`symfony/messenger`)

> [!IMPORTANT]
> Establecer `ai.auto_enrich_on_upload: true` hace que el `MetadataSyncListener` integrado invoque al proveedor de IA de forma **síncrona** durante la solicitud HTTP de subida. Para evitar ralentizar las subidas, deja `ai.auto_enrich_on_upload: false` y procesa el enriquecimiento de manera asíncrona con Symfony Messenger.

### Paso 1 — Mensaje de Messenger

```php
<?php
// src/Message/EnrichUploadedAssetMessage.php

declare(strict_types=1);

namespace App\Message;

final readonly class EnrichUploadedAssetMessage
{
    public function __construct(
        public string $profileName,
        public string $volumeName,
        public string $path,
    ) {
    }
}
```

### Paso 2 — Listener de `FileUploadedEvent`

```php
<?php
// src/EventListener/DispatchAssetEnrichmentListener.php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\EnrichUploadedAssetMessage;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class DispatchAssetEnrichmentListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        if (($event->profile->ai['enabled'] ?? false) !== true) {
            return;
        }
        // Evita procesamiento duplicado si auto_enrich_on_upload síncrono está activado:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Evita saturar la cola de IA al descomprimir un ZIP con 200 archivos:
        if ($event->fromArchiveExtraction) {
            return;
        }

        $this->messageBus->dispatch(new EnrichUploadedAssetMessage(
            profileName: $event->profile->name,
            volumeName: $event->volume->name,
            path: $event->path,
        ));
    }
}
```

### Paso 3 — Handler del worker de Messenger

```php
<?php
// src/MessageHandler/EnrichUploadedAssetHandler.php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnrichUploadedAssetMessage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EnrichUploadedAssetHandler
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private AssetEnrichmentService $enrichmentService,
    ) {
    }

    public function __invoke(EnrichUploadedAssetMessage $message): void
    {
        $profile = $this->configProvider->getProfile($message->profileName);
        if (!$this->enrichmentService->isAvailableForProfile($profile)) {
            return;
        }

        $this->enrichmentService->enrichAndSave(
            profile: $profile,
            path: $message->path,
            volumeName: $message->volumeName,
            onlyMissing: true,
            skipVoter: true, // Los workers en segundo plano no tienen sesión HTTP de usuario activa
        );
    }
}
```

> [!WARNING]
> **Consideraciones sobre workers:**
> 1. Como los workers de Messenger se ejecutan sin sesión de usuario autenticada, los volúmenes dinámicos `{user_id}` se resuelven como `guest`; no despaches enriquecimiento asíncrono para volúmenes por usuario con `{user_id}`.
> 2. Incluso con `skipVoter: true`, la salvaguarda `block_private_volumes: true` permanece activa y lanza `AccessDeniedException` en volúmenes `private: true`.

---

## 7. Crear un proveedor de IA personalizado (`AssetEnricherInterface`)

Puedes integrar tu propio microservicio en Python, AWS Rekognition, Azure AI Vision o cualquier API HTTP propia sin necesidad de instalar `symfony/ai-bundle`:

```php
<?php
// src/AI/InternalVisionEnricher.php

declare(strict_types=1);

namespace App\AI;

use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(AssetEnricherInterface::class)]
final readonly class InternalVisionEnricher implements AssetEnricherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $visionEndpoint,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function enrich(
        string $localFilePath,
        string $mimeType,
        string $locale = 'es',
        array $context = [],
    ): AssetEnrichmentResult {
        $response = $this->httpClient->request('POST', $this->visionEndpoint, [
            'body' => [
                'locale' => $locale,
                'max_tags' => $context['max_tags'] ?? 6,
                'file' => fopen($localFilePath, 'rb'),
            ],
        ])->toArray();

        return new AssetEnrichmentResult(
            alt: $response['alt'] ?? null,
            title: $response['title'] ?? null,
            caption: $response['caption'] ?? null,
            tags: $response['tags'] ?? [],
            provider: 'internal-vision',
            model: 'v1',
        );
    }
}
```

Con `#[AsAlias(AssetEnricherInterface::class)]`, tanto el botón **✨ Sugerir con IA** de la interfaz como el comando CLI `file-manager:ai:enrich` utilizan automáticamente tu servicio personalizado.

---

## 8. Seguridad, inyección de prompts y protección frente a rechazos del LLM

1. **Archivos privados (`private: true`):** mantén `block_private_volumes: true` al usar modelos LLM en la nube, o cambia a una instancia autoalojada de **Ollama**.
2. **Autorización:** restringe el acceso a APIs de pago mediante `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (se comprueban conjuntamente `VIEW` + `EDIT_METADATA` + `AI_ENRICH`).
3. **Control de costes:** mantén `max_image_dimension: 1024` (o `768`) y previsualiza siempre las ejecuciones CLI con `--dry-run` y `--limit`.
4. **Inyección de prompts y filtrado multilingüe de rechazos:**
   - Antes de incluirlos en cualquier prompt, los nombres de archivo se limpian de caracteres de control, anulaciones bidireccionales y comillas, y se limitan a **120 caracteres**.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` detecta mensajes de rechazo del modelo en **11 idiomas** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) —como *"Lo siento, no puedo analizar esta imagen..."*— evitando que un texto de rechazo se guarde jamás como metadato `alt`.
   - Todo el texto devuelto pasa por la sanitización de `AssetEnrichmentResult` y `FolderMetadata::sanitizeTags()`, y siempre se escapa en HTML dentro del DOM.

---

[← Anterior: 08 — Eventos, comandos y resolución de problemas](08-events-commands-cache-and-troubleshooting.md) | [Índice principal](README.md) | [Siguiente: 10 — Protección de activos en uso →](10-asset-usage-guard.md)
