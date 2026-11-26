# 09 — Symfony-AI-Integration & Intelligente Asset-Anreicherung

[← Zurück: 08 — Events, Befehle & Fehlerbehebung](08-events-commands-cache-and-troubleshooting.md) | [Übersicht](README.md) | [Weiter: 10 — Asset Usage Guard →](10-asset-usage-guard.md)

> [!TIP]
> **Kurz erklärt: Was leistet diese Funktion?**
> Lädt ein Redakteur ein Produktfoto hoch, muss er *„Alternativtext, Titel, Bildunterschrift und Such-Tags“* nicht mehr für jede Datei von Hand schreiben. Ein Klick auf **„✨ Mit KI vorschlagen“** im Inspector-Panel lässt Ihr gewähltes Modell (OpenAI, Gemini, Claude oder lokales Ollama) das Bild analysieren und füllt innerhalb von ca. 2 Sekunden SEO-freundliche Metadaten und `#tags` in Ihrer Zielsprache vor – gespeichert wird erst nach Prüfung und Freigabe durch den Redakteur.
> *(Standardmäßig werden private Volumes niemals an externe KI-Anbieter gesendet: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** integriert sich mit **null Pflichtabhängigkeiten** in das offizielle **[Symfony AI](https://github.com/symfony/ai)**-Ökosystem (`symfony/ai-bundle` + `symfony/ai-platform`):

- Ist `symfony/ai-bundle` **nicht installiert**, läuft das Bundle völlig fehlerfrei und ohne Speicher-Overhead; KI-Schaltflächen werden in der Oberfläche automatisch ausgeblendet.
- Ist `symfony/ai-bundle` **installiert**, erkennt `FileManagerBundle` dies automatisch und verdrahtet die integrierte Bridge `SymfonyAiAssetEnricher`.
- KI-Funktionen bleiben **standardmäßig deaktiviert, bis sie pro Profil explizit eingeschaltet werden (`ai.enabled: true`)** – so entstehen niemals unerwartete API-Kosten.

---

## 1. Kernfunktionen

| Funktion | Beschreibung |
| :--- | :--- |
| **Automatisierter SEO- & WCAG-`alt`-Text** | Analysiert Bilder und erzeugt barrierefreie, SEO-taugliche `alt`-Texte, prägnante `title`-Einträge und eine `caption` in jeder gewünschten Sprache (`de`, `en`, `tr`, `fr`, `ja`, `ar` usw.). |
| **Intelligentes Auto-Tagging (`tags`)** | Extrahiert aussagekräftige Schlagwörter aus Bildern oder Textdateien (z. B. `["Isolierflasche", "mattschwarz", "Werbegeschenk"]`), die sofort mit der `#tag`-Suche im Dateimanager funktionieren. |
| **Text- & Dokument-Zusammenfassungen** | Liest die ersten 8 KB von `.txt`-, `.md`-, `.csv`-, `.json`- und `.yaml`/`.yml`-Dateien, um Titel, Beschreibungen und Such-Tags vorzuschlagen. |
| **Automatische Token-Kostenoptimierung** | Große 10–15-MB-Fotos werden niemals unbearbeitet an Vision-APIs gesendet; eine temporäre Kopie wird zuvor auf `max_image_dimension` (Standard `1024px`) verkleinert, was die Token-Kosten um ca. 80 % senkt. |
| **Anbieterunabhängigkeit** | Wechseln Sie mit einer einzigen YAML-Zeile zwischen OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI oder **100 % lokalem, kostenlosem Ollama**. |
| **3 Workflows** | Vom Redakteur freigegebenes **„✨ Mit KI vorschlagen“** in der UI, Stapelverarbeitung bestehender Archive per CLI (`file-manager:ai:enrich`) oder automatische Hintergrundverarbeitung mit `symfony/messenger`. |

---

## 2. Installation (Anbieter auswählen)

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

### Option D — Lokales, 100 % privates Ollama (`qwen2.5vl` / `llava`)

Ideal, wenn vertrauliche Unternehmensdokumente Ihre eigene Infrastruktur niemals verlassen dürfen:

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

## 3. Konfiguration von LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'de'
        ai:
            default_model: 'gpt-4o-mini'
            # Optional: gezielten Platform-Service wählen, wenn mehrere Symfony-AI-Plattformen konfiguriert sind
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Standard: false (Kostenschutz)
                model: 'gpt-4o-mini'              # z. B. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'de'                      # null übernimmt die aktive Request-/Profil-Locale
                max_tags: 6                       # Tag-Limit (1..20)
                max_image_dimension: 1024         # Maximale Kantenlänge vor Versand an die Vision-API (256..2048 px)
                block_private_volumes: true       # Blockiert externe KI-Aufrufe für Volumes mit private: true
                auto_enrich_on_upload: false      # true läuft synchron beim Upload; bei Nutzung von Messenger auf false belassen
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Für sensible Verträge vollständig deaktiviert
```

### Auflösungsreihenfolge der KI-Ausgabesprache (`ai.locale`)

Klickt ein Redakteur auf **✨ Mit KI vorschlagen**, wird die Zielsprache exakt in dieser Reihenfolge ermittelt (BCP-47-, ICU- und POSIX-`.UTF-8`-Locales werden unterstützt):

1. Explizit im Request-Body gesendetes `aiLocale`
2. Profil-Einstellung `profiles.<name>.ai.locale` *(überschreibt die UI-Sprache, sobald gesetzt!)*
3. Aktive HTTP-Request- / UI-Locale
4. Profil-Einstellung `profiles.<name>.locale`
5. `'en'` *(garantierter Fallback)*

---

## 4. KI in der Oberfläche nutzen (Ein Klick, Freigabe durch Redakteur)

1. Rechtsklick auf ein Bild oder eine Textdatei im Dateimanager → **🏷️ SEO & Fokuspunkt** bzw. **Infos & Tags** (oder das rechte Inspector-Panel öffnen).
2. Die Schaltfläche **✨ Mit KI vorschlagen** wird nur angezeigt – und serverseitig strikt erzwungen –, wenn **alle 5 Bedingungen** erfüllt sind:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - Ein aktiver `AssetEnricherInterface`-Service ist registriert
   - Der Benutzer besitzt alle drei Berechtigungen – **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** – für die Datei und alle übergeordneten Ordner
   - Bei einem Volume mit `private: true` ist explizit `block_private_volumes: false` konfiguriert
3. Ein Klick auf die Schaltfläche **überschreibt vorhandene Metadaten nicht sofort**; die von der KI vorgeschlagenen Felder `alt`, `title`, `caption` und `tags` werden zunächst in das Formular übernommen, sodass der Redakteur sie prüfen, anpassen und auf **Speichern** klicken kann.

---

## 5. Bestehende Archive per CLI im Stapel anreichern

Ergänzen Sie fehlende `alt`-Texte und Tags für Tausende vorhandene Dateien unter strenger Kostenkontrolle:

```bash
# 1. Vorschau, welche Dateien angereichert würden – ohne KI-API-Aufruf (kostenloser Probelauf):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Die ersten 50 Dateien mit fehlenden Metadaten auf Deutsch anreichern:
php bin/console file-manager:ai:enrich products --limit=50 --locale=de

# 3. Auf ein bestimmtes Volume und einen Unterordner eingrenzen:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/katalog --limit=100

# 4. Neu-Generierung bereits vorhandener Metadaten erzwingen (--force):
php bin/console file-manager:ai:enrich products --path=kampagne --force --limit=20
```

> [!NOTE]
> **Sicherheits- & Kostenschutz-Leitplanken im CLI (`file-manager:ai:enrich`):**
> - `--limit` steht standardmäßig auf **`50`**, damit nicht versehentlich ein gesamtes Archiv in einem Durchlauf an eine kostenpflichtige API gesendet wird.
> - Ohne `--force` werden nur Dateien mit **fehlenden redaktionellen Metadaten** verarbeitet (sind `alt` **und** mindestens eines der Felder `tags`/`title`/`caption` bereits befüllt, wird die Datei übersprungen).
> - Das Vorabladen der Metadaten (`preload()`) erfolgt in Blöcken zu je **250 Einträgen**; Dateien über **20 MB**, dynamische `{user_id}`-Volumes sowie `private: true`-Volumes (bei `block_private_volumes: true`) werden automatisch übersprungen.

---

## 6. Automatische asynchrone Anreicherung beim Upload (`symfony/messenger`)

> [!IMPORTANT]
> Bei `ai.auto_enrich_on_upload: true` ruft der integrierte `MetadataSyncListener` den KI-Anbieter **synchron** während des Upload-HTTP-Requests auf. Um Uploads nicht auszubremsen, belassen Sie `ai.auto_enrich_on_upload: false` und verarbeiten Sie die Anreicherung asynchron über Symfony Messenger.

### Schritt 1 — Messenger-Message

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

### Schritt 2 — `FileUploadedEvent`-Listener

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
        // Doppelte Verarbeitung verhindern, falls synchrones auto_enrich_on_upload aktiv ist:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // KI-Queue nicht überfluten, wenn ein ZIP-Archiv mit 200 Dateien entpackt wird:
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

### Schritt 3 — Messenger-Worker-Handler

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
            skipVoter: true, // Hintergrund-Worker haben keine aktive HTTP-Benutzersitzung
        );
    }
}
```

> [!WARNING]
> **Hinweise für Worker-Prozesse:**
> 1. Da Messenger-Worker ohne authentifizierte Benutzersitzung laufen, werden dynamische `{user_id}`-Volumes als `guest` aufgelöst – starten Sie daher keine asynchrone Anreicherung für benutzerbezogene `{user_id}`-Volumes.
> 2. Selbst bei `skipVoter: true` bleibt der Schutz `block_private_volumes: true` aktiv und wirft bei `private: true`-Volumes eine `AccessDeniedException`.

---

## 7. Einen eigenen KI-Anbieter schreiben (`AssetEnricherInterface`)

Sie können Ihren eigenen Python-Microservice, AWS Rekognition, Azure AI Vision oder jede beliebige HTTP-API anbinden, ohne `symfony/ai-bundle` zu installieren:

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
        string $locale = 'de',
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

Durch `#[AsAlias(AssetEnricherInterface::class)]` nutzen sowohl die UI-Schaltfläche **✨ Mit KI vorschlagen** als auch der CLI-Befehl `file-manager:ai:enrich` automatisch Ihren eigenen Service.

---

## 8. Sicherheit, Prompt-Injection- & LLM-Ablehnungs-Schutz

1. **Private Dateien (`private: true`):** Belassen Sie `block_private_volumes: true` beim Einsatz von Cloud-LLMs oder wechseln Sie auf eine selbst gehostete **Ollama**-Instanz.
2. **Berechtigungen:** Beschränken Sie den Zugriff auf kostenpflichtige APIs über `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` werden stets gemeinsam geprüft).
3. **Kostenkontrolle:** Belassen Sie `max_image_dimension: 1024` (oder `768`) und prüfen Sie CLI-Läufe immer vorab mit `--dry-run` und `--limit`.
4. **Prompt-Injection- & mehrsprachige Ablehnungsfilterung:**
   - Dateinamen werden vor dem Einfügen in Prompts von Steuerzeichen, bidirektionalen Override-Zeichen und Anführungszeichen bereinigt sowie auf **120 Zeichen** begrenzt.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` erkennt typische Ablehnungsantworten von Modellen in **11 Sprachen** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) – etwa *„Es tut mir leid, ich kann dieses Bild nicht analysieren…“* – und verhindert, dass solche Ablehnungstexte jemals als `alt`-Metadaten gespeichert werden.
   - Alle zurückgegebenen Texte werden durch `AssetEnrichmentResult` und `FolderMetadata::sanitizeTags()` bereinigt und im DOM grundsätzlich HTML-escaped ausgegeben.

---

[← Zurück: 08 — Events, Befehle & Fehlerbehebung](08-events-commands-cache-and-troubleshooting.md) | [Übersicht](README.md) | [Weiter: 10 — Asset Usage Guard →](10-asset-usage-guard.md)
