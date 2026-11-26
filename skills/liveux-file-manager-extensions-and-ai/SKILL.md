---
name: liveux-file-manager-extensions-and-ai
description: >-
  Extends LiveUX FileManagerBundle with dynamic database ConfigProviderInterface,
  custom FolderMetadataStorageInterface, Symfony Event Subscribers for the 9
  file lifecycle events, optional Symfony AI / custom AssetEnricherInterface
  auto-tagging, and profile-scoped CLI maintenance commands. Use when adding
  custom providers, listeners, AI metadata enrichment, or maintenance jobs.
license: MIT
compatibility: PHP >= 8.3, Symfony ^6.4|^7.2|^8.0
metadata:
  author: liveux
  version: "0.1.0"
---

# LiveUX FileManagerBundle — Extensions, Events, CLI & Symfony AI

Use this guide to hook into bundle lifecycle events, load profiles/metadata from Doctrine/Redis, enable AI auto-tagging, or run maintenance CLI commands.

## 1. Dynamic Profiles & Volumes (`ConfigProviderInterface`)

Register a custom `ConfigProviderInterface` tagged with `liveux_file_manager.config_provider` and a positive `priority` (built-in `YamlConfigProvider` has priority `0`, chained via `ChainConfigProvider`):

```yaml
# config/services.yaml
App\FileManager\DatabaseConfigProvider:
    tags:
        - { name: 'liveux_file_manager.config_provider', priority: 10 }
```

## 2. Custom Metadata Storage (`FolderMetadataStorageInterface`)

By default, folder colors/icons/pins and editorial file metadata (`alt`, `title`, `caption`, `tags`, `focalPoint`) persist in `var/file-manager/metadata.json`. Replace it with Doctrine/Redis by aliasing the interface:

```yaml
# config/services.yaml
LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface: '@App\FileManager\DoctrineMetadataStorage'
```

## 3. Subscribing to Lifecycle Events

Listen to any of the 9 typed domain events extending `AbstractFileManagerEvent` (all expose `$event->profile`, `$event->volume`, `$event->path`, `$event->canonicalPath`):

```php
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class OnMediaUploaded
{
    #[AsEventListener(event: FileUploadedEvent::class)]
    public function __invoke(FileUploadedEvent $event): void
    {
        // Trigger async Messenger job, CDN purge, or search indexing
    }
}
```

## 4. AI Metadata Enrichment (`AssetEnricherInterface` & Symfony AI)

### Option A — Automatic Bridge with `symfony/ai-bundle`
1. `composer require symfony/ai-bundle` and configure your provider (OpenAI, Gemini, Anthropic Claude, Mistral, VertexAI, Ollama).
2. Enable per-profile:
   ```yaml
   liveux_file_manager:
       profiles:
           default:
               ai:
                   enabled: true
                   model: 'gpt-4o-mini'     # Optional platform model override
                   max_tags: 6
                   max_image_dimension: 1024 # ~80% token savings before sending to Vision LLM
                   block_private_volumes: true
               permissions:
                   ai_enrich: 'ROLE_EDITOR'
   ```
3. Users get **✨ Suggest with AI** inside the File Metadata modal, or batch-enrich via `php bin/console file-manager:ai:enrich default --limit=50 --dry-run`.

### Option B — Custom Enricher (Zero `symfony/ai-bundle` Required)
Implement `LiveUX\FileManagerBundle\AI\AssetEnricherInterface` and alias it in `config/services.yaml`.

## 5. Maintenance CLI Commands

| Command | Purpose |
| :--- | :--- |
| `file-manager:doctor` | Checks PHP ini limits, Imagick/GD/EXIF/WebP/ZIP drivers, and profiles |
| `file-manager:thumbs:warmup [profile]` | Pre-generates thumbnails (`--volume`, `--path`, `--force`, `--limit`, `--dry-run`) |
| `file-manager:thumbs:clear [profile]` | Safely clears thumbnails for a profile/volume/path (`--orphans`, `--dry-run`) |
| `file-manager:images:optimize [profile]` | Retroactively optimizes existing raster images (`--force`, `--allow-webp-rename`, `--dry-run`) |
| `file-manager:duplicates [profile]` | Finds SHA-256 duplicate files (`--volume`, `--json`) |
| `file-manager:ai:enrich [profile]` | Batch AI alt-text/caption/tag enrichment (`--force`, `--limit`, `--dry-run`) |
| `file-manager:stats [profile]` | Disk usage, quota, thumbnail cache & trash report (`--json`) |
| `file-manager:trash:purge [profile]` | Purges expired `.trash` entries (`--days=0`, `--dry-run`) |
| `file-manager:cache:clear [profile]` | Clears bundle directory/tree/quota caches without wiping host `cache.app` |
| `file-manager:skills:install` | Installs bundle AI Agent Skills into `.agents/skills/`, `.claude/skills/`, etc. |

See full interface method signatures and event payload properties in [references/contracts-and-events.md](references/contracts-and-events.md).

## 6. Verification

```bash
php bin/console file-manager:doctor
php bin/console file-manager:stats --help
php bin/console debug:event-dispatcher LiveUX\\FileManagerBundle\\Event\\FileUploadedEvent
```
