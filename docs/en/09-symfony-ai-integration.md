# 09 — Symfony AI Integration & Smart Asset Enrichment

[← Previous: 08 — Events, Commands & Troubleshooting](08-events-commands-cache-and-troubleshooting.md) | [Main Index](README.md) | [Next: 10 — Asset Usage Guard →](10-asset-usage-guard.md)

> [!TIP]
> **In Plain English: What Does This Feature Do?**
> When an editor uploads a product photo, they no longer have to manually write *"alt text, title, caption, and search tags"* for every file. Clicking **"✨ Suggest with AI"** in the Inspector panel lets your chosen model (OpenAI, Gemini, Claude, or local Ollama) inspect the image and fill in SEO-friendly metadata and `#tags` in your target language in ~2 seconds—and nothing is saved until the editor reviews and approves it.
> *(By default, private volumes are never sent to external AI providers: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** integrates with the official **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) ecosystem with **zero mandatory dependencies**:

- If `symfony/ai-bundle` is **not installed**, the bundle runs with zero errors and zero memory overhead, and AI buttons are automatically hidden in the UI.
- If `symfony/ai-bundle` **is installed**, `FileManagerBundle` automatically detects and wires the built-in `SymfonyAiAssetEnricher` bridge.
- AI features remain **disabled unless explicitly opted in per profile (`ai.enabled: true`)**—so you never get surprise API bills.

---

## 1. Key Capabilities

| Capability | Description |
| :--- | :--- |
| **Automated SEO & WCAG `alt` Text** | Analyzes images and generates screen-reader and SEO-ready `alt` text, a concise `title`, and a `caption` in any language (`en`, `tr`, `fr`, `de`, `ja`, `ar`, etc.). |
| **Smart Auto-Tagging (`tags`)** | Extracts descriptive keywords from images or text files (e.g., `["insulated bottle", "matte black", "corporate gift"]`) that immediately work with `#tag` search in the file manager. |
| **Text & Document Summaries** | Reads the first 8 KB of `.txt`, `.md`, `.csv`, `.json`, and `.yaml`/`.yml` files to suggest titles, descriptions, and search tags. |
| **Automatic Token Cost Optimization** | Large 10–15 MB photos are never sent raw to Vision APIs; a temporary copy is resized to `max_image_dimension` (default `1024px`) first, cutting token costs by ~80%. |
| **Provider Independence** | Switch between OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI, or **100% local, free Ollama** by changing a single YAML line. |
| **3 Workflows** | Editor-approved **"✨ Suggest with AI"** in the UI, batch archive enrichment via CLI (`file-manager:ai:enrich`), or automatic background processing with `symfony/messenger`. |

---

## 2. Installation (Pick Your Provider)

### Option A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

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

### Option B — Google Gemini (`gemini-2.5-flash`)

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

### Option C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

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

### Option D — Local, 100% Private Ollama (`qwen2.5vl` / `llava`)

Ideal when confidential company documents must never leave your infrastructure:

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

## 3. LiveUX FileManagerBundle Configuration

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'en'
        ai:
            default_model: 'gpt-4o-mini'
            # Optional: target a specific platform service if multiple Symfony AI platforms are configured
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Default: false (cost protection)
                model: 'gpt-4o-mini'              # e.g. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'en'                      # null inherits the active request/profile locale
                max_tags: 6                       # Tag limit (1..20)
                max_image_dimension: 1024         # Resize ceiling before sending to Vision API (256..2048 px)
                block_private_volumes: true       # Blocks external AI calls on private: true volumes
                auto_enrich_on_upload: false      # true runs synchronously on upload; keep false when using Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Completely disabled for sensitive contracts
```

### AI Output Locale Resolution Order (`ai.locale`)

When an editor clicks **✨ Suggest with AI**, the response language is resolved in this exact order (BCP-47, ICU, and POSIX `.UTF-8` locales are supported):

1. Explicit `aiLocale` sent in the request body
2. Profile setting `profiles.<name>.ai.locale` *(overrides UI locale when set!)*
3. Active HTTP request / UI locale
4. Profile setting `profiles.<name>.locale`
5. `'en'` *(guaranteed fallback)*

---

## 4. Using AI in the UI (One-Click, Editor-Approved)

1. Right-click an image or text file in the file manager → **🏷️ Metadata & Focal Point** (or open the right-hand Inspector panel).
2. The **✨ Suggest with AI** button is rendered—and strictly enforced server-side—only when **all 5 conditions** are met:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - An active `AssetEnricherInterface` service is registered
   - The user holds all three permissions—**`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`**—on the file and all of its ancestor folders
   - If the volume is `private: true`, `block_private_volumes: false` is explicitly configured
3. Clicking the button **does not overwrite** existing metadata immediately; the AI-suggested `alt`, `title`, `caption`, and `tags` populate the form fields so the editor can review, tweak, and click **Save**.

---

## 5. Batch-Enriching Existing Archives via CLI

Fill in missing `alt` text and tags across thousands of existing assets with strict cost controls:

```bash
# 1. Preview which files would be enriched without calling the AI API (free dry run):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Enrich the first 50 files missing metadata in English:
php bin/console file-manager:ai:enrich products --limit=50 --locale=en

# 3. Scope to a specific volume and subfolder:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Force regeneration of existing metadata (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **CLI Safety & Cost Guardrails (`file-manager:ai:enrich`):**
> - `--limit` defaults to **`50`** so you never accidentally send an entire archive to a paid API in one run.
> - Without `--force`, only files with **missing editorial metadata** are processed (if `alt` is populated **and** at least one of `tags`/`title`/`caption` is populated, the file is skipped).
> - Metadata preloading (`preload()`) runs in batches of **250 items**; files larger than **20 MB**, `{user_id}` dynamic volumes, and `private: true` volumes (when `block_private_volumes: true`) are automatically skipped.

---

## 6. Automatic Async Enrichment on Upload (`symfony/messenger`)

> [!IMPORTANT]
> Setting `ai.auto_enrich_on_upload: true` causes the built-in `MetadataSyncListener` to call the AI provider **synchronously** during the upload HTTP request. To avoid slowing down uploads, leave `ai.auto_enrich_on_upload: false` and process enrichment asynchronously via Symfony Messenger.

### Step 1 — Messenger Message

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

### Step 2 — `FileUploadedEvent` Listener

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
        // Prevent duplicate processing if synchronous auto_enrich_on_upload is enabled:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Avoid flooding the AI queue when extracting a 200-file ZIP archive:
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

### Step 3 — Messenger Worker Handler

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
            skipVoter: true, // Background workers have no active HTTP user session
        );
    }
}
```

> [!WARNING]
> **Worker Caveats:**
> 1. Because Messenger workers run without an authenticated user session, `{user_id}` dynamic volumes resolve to `guest`—do not dispatch async enrichment for per-user `{user_id}` volumes.
> 2. Even with `skipVoter: true`, the `block_private_volumes: true` safeguard remains active and throws `AccessDeniedException` on `private: true` volumes.

---

## 7. Writing a Custom AI Provider (`AssetEnricherInterface`)

You can integrate your own Python microservice, AWS Rekognition, Azure AI Vision, or any custom HTTP API without installing `symfony/ai-bundle`:

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
        string $locale = 'en',
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

With `#[AsAlias(AssetEnricherInterface::class)]`, both the UI **✨ Suggest with AI** button and the `file-manager:ai:enrich` CLI command automatically use your custom service.

---

## 8. Security, Prompt Injection & LLM Refusal Protection

1. **Private Files (`private: true`):** Keep `block_private_volumes: true` when using cloud LLMs, or switch to a self-hosted **Ollama** instance.
2. **Authorization:** Restrict paid API access via `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` are all verified together).
3. **Cost Control:** Keep `max_image_dimension: 1024` (or `768`), and always preview CLI runs with `--dry-run` and `--limit`.
4. **Prompt Injection & Multilingual Refusal Filtering:**
   - Filenames are stripped of control characters, bidirectional overrides, and quotes—and capped at **120 characters**—before entering any prompt.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` detects model refusal messages across **11 languages** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO)—such as *"I'm sorry, I cannot analyze this image..."*—preventing refusal text from ever being saved as `alt` metadata.
   - All returned text is sanitized by `AssetEnrichmentResult` and `FolderMetadata::sanitizeTags()`, and always HTML-escaped in the DOM.

---

[← Previous: 08 — Events, Commands & Troubleshooting](08-events-commands-cache-and-troubleshooting.md) | [Main Index](README.md) | [Next: 10 — Asset Usage Guard →](10-asset-usage-guard.md)
