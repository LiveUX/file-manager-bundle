# 09 — Symfony AI-integratie & Slimme Asset-verrijking

[← Vorige: 08 — Events, Commando's & Probleemoplossing](08-events-commands-cache-and-troubleshooting.md) | [Hoofdoverzicht](README.md) | [Volgende: 10 — Asset Usage Guard →](10-asset-usage-guard.md)

> [!TIP]
> **In gewone taal: wat doet deze functie?**
> Wanneer een redacteur een productfoto uploadt, hoeft die niet langer handmatig *"alt-tekst, titel, bijschrift en zoektags"* voor elk bestand te typen. Door op **"✨ Voorstellen met AI"** te klikken in het detailpaneel analyseert het gekozen model (OpenAI, Gemini, Claude of lokale Ollama) de afbeelding en vult in ~2 seconden SEO-vriendelijke metadata en `#tags` in jouw doeltaal in — en er wordt niets opgeslagen voordat de redacteur het controleert en goedkeurt.
> *(Standaard worden privévolumes nooit naar externe AI-providers verstuurd: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** integreert met het officiële **[Symfony AI](https://github.com/symfony/ai)**-ecosysteem (`symfony/ai-bundle` + `symfony/ai-platform`) met **nul verplichte afhankelijkheden**:

- Is `symfony/ai-bundle` **niet geïnstalleerd**, dan draait de bundle foutloos zonder extra geheugengebruik en worden AI-knoppen automatisch verborgen in de UI.
- Is `symfony/ai-bundle` **wel geïnstalleerd**, dan detecteert en koppelt `FileManagerBundle` automatisch de ingebouwde `SymfonyAiAssetEnricher`-bridge.
- AI-functies blijven **uitgeschakeld tenzij expliciet geactiveerd per profiel (`ai.enabled: true`)** — zo kom je nooit voor onverwachte API-kosten te staan.

---

## 1. Belangrijkste mogelijkheden

| Mogelijkheid | Beschrijving |
| :--- | :--- |
| **Geautomatiseerde SEO- & WCAG-`alt`-tekst** | Analyseert afbeeldingen en genereert schermlezer- en SEO-geschikte `alt`-tekst, een beknopte `title` en een `caption` in elke taal (`nl`, `en`, `tr`, `fr`, `de`, `ja`, `ar`, enz.). |
| **Slimme auto-tagging (`tags`)** | Haalt beschrijvende trefwoorden uit afbeeldingen of tekstbestanden (bijv. `["thermosfles", "matzwart", "relatiegeschenk"]`) die direct werken met `#tag`-zoeken in het bestandsbeheer. |
| **Samenvattingen van tekst & documenten** | Leest de eerste 8 KB van `.txt`-, `.md`-, `.csv`-, `.json`- en `.yaml`/`.yml`-bestanden om titels, beschrijvingen en zoektags voor te stellen. |
| **Automatische optimalisatie van tokenkosten** | Grote foto's van 10–15 MB worden nooit onbewerkt naar Vision API's gestuurd; een tijdelijke kopie wordt eerst verkleind naar `max_image_dimension` (standaard `1024px`), wat ~80% op tokenkosten bespaart. |
| **Provideronafhankelijk** | Schakel tussen OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI of **100% lokale, gratis Ollama** door één regel YAML aan te passen. |
| **3 workflows** | Door de redacteur goedgekeurde **"✨ Voorstellen met AI"** in de UI, batchverrijking van archieven via CLI (`file-manager:ai:enrich`) of automatische achtergrondverwerking met `symfony/messenger`. |

---

## 2. Installatie (Kies je provider)

### Optie A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

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

### Optie B — Google Gemini (`gemini-2.5-flash`)

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

### Optie C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

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

### Optie D — Lokale, 100% privé Ollama (`qwen2.5vl` / `llava`)

Ideaal wanneer vertrouwelijke bedrijfsdocumenten je eigen infrastructuur nooit mogen verlaten:

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

## 3. Configuratie van LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'nl'
        ai:
            default_model: 'gpt-4o-mini'
            # Optioneel: richt je op een specifieke platformservice als er meerdere Symfony AI-platforms zijn geconfigureerd
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Standaard: false (kostenbescherming)
                model: 'gpt-4o-mini'              # bijv. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'nl'                      # null neemt de actieve request-/profiellocale over
                max_tags: 6                       # Maximaal aantal tags (1..20)
                max_image_dimension: 1024         # Maximale afmeting vóór verzending naar Vision API (256..2048 px)
                block_private_volumes: true       # Blokkeert externe AI-aanroepen op volumes met private: true
                auto_enrich_on_upload: false      # true draait synchroon tijdens het uploaden; houd op false bij gebruik van Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Volledig uitgeschakeld voor gevoelige contracten
```

### Bepalingsvolgorde voor de AI-uitvoertaal (`ai.locale`)

Wanneer een redacteur op **✨ Voorstellen met AI** klikt, wordt de antwoordtaal in exact deze volgorde bepaald (BCP-47-, ICU- en POSIX `.UTF-8`-locales worden ondersteund):

1. Expliciete `aiLocale` meegestuurd in de requestbody
2. Profielinstelling `profiles.<name>.ai.locale` *(overschrijft de UI-locale wanneer ingesteld!)*
3. Actieve HTTP-request- / UI-locale
4. Profielinstelling `profiles.<name>.locale`
5. `'en'` *(gegarandeerde fallback)*

---

## 4. AI gebruiken in de UI (Eén klik, goedkeuring door redacteur)

1. Klik met de rechtermuisknop op een afbeelding of tekstbestand in het bestandsbeheer → **🏷️ SEO & focuspunt** / **Info & tags** (of open het rechterpaneel **Voorbeeld & details**).
2. De knop **✨ Voorstellen met AI** wordt alleen getoond — en server-side strikt afgedwongen — wanneer aan **alle 5 voorwaarden** is voldaan:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - Er is een actieve `AssetEnricherInterface`-service geregistreerd
   - De gebruiker beschikt over alle drie de machtigingen — **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** — op het bestand en alle bovenliggende mappen
   - Als het volume `private: true` heeft, is `block_private_volumes: false` expliciet ingesteld
3. Klikken op de knop **overschrijft bestaande metadata niet** direct; de door AI voorgestelde `alt`, `title`, `caption` en `tags` vullen de formuliervelden in zodat de redacteur ze kan controleren, aanpassen en op **Opslaan** kan klikken.

---

## 5. Bestaande archieven in batch verrijken via CLI

Vul ontbrekende `alt`-teksten en tags aan voor duizenden bestaande assets met strikte kostenbewaking:

```bash
# 1. Bekijk welke bestanden zouden worden verrijkt zonder de AI-API aan te roepen (gratis proefrun):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Verrijk de eerste 50 bestanden met ontbrekende metadata in het Nederlands:
php bin/console file-manager:ai:enrich products --limit=50 --locale=nl

# 3. Beperk tot een specifiek volume en een specifieke submap:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Forceer het opnieuw genereren van bestaande metadata (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **Veiligheids- & kostenbegrenzingen in de CLI (`file-manager:ai:enrich`):**
> - `--limit` staat standaard op **`50`**, zodat je nooit per ongeluk in één keer een heel archief naar een betaalde API stuurt.
> - Zonder `--force` worden alleen bestanden met **ontbrekende redactionele metadata** verwerkt (als `alt` gevuld is **én** minstens één van `tags`/`title`/`caption` gevuld is, wordt het bestand overgeslagen).
> - Vooraf laden van metadata (`preload()`) gebeurt in batches van **250 items**; bestanden groter dan **20 MB**, dynamische `{user_id}`-volumes en `private: true`-volumes (bij `block_private_volumes: true`) worden automatisch overgeslagen.

---

## 6. Automatische asynchrone verrijking bij upload (`symfony/messenger`)

> [!IMPORTANT]
> Wanneer je `ai.auto_enrich_on_upload: true` instelt, roept de ingebouwde `MetadataSyncListener` de AI-provider **synchroon** aan tijdens het upload-HTTP-verzoek. Laat `ai.auto_enrich_on_upload: false` staan en verwerk de verrijking asynchroon via Symfony Messenger om uploads niet te vertragen.

### Stap 1 — Messenger Message

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

### Stap 2 — `FileUploadedEvent`-listener

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
        // Voorkom dubbele verwerking wanneer synchrone auto_enrich_on_upload is ingeschakeld:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Voorkom dat de AI-wachtrij overstroomt bij het uitpakken van een ZIP-archief met 200 bestanden:
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

### Stap 3 — Messenger Worker Handler

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
            skipVoter: true, // Achtergrondworkers hebben geen actieve HTTP-gebruikerssessie
        );
    }
}
```

> [!WARNING]
> **Aandachtspunten voor workers:**
> 1. Omdat Messenger-workers zonder ingelogde gebruikerssessie draaien, worden dynamische `{user_id}`-volumes opgelost naar `guest` — stuur daarom geen asynchrone verrijkingstaken voor gebruikersgebonden `{user_id}`-volumes.
> 2. Zelfs met `skipVoter: true` blijft de beveiliging `block_private_volumes: true` actief en gooit deze een `AccessDeniedException` bij `private: true`-volumes.

---

## 7. Een eigen AI-provider schrijven (`AssetEnricherInterface`)

Je kunt je eigen Python-microservice, AWS Rekognition, Azure AI Vision of een andere HTTP-API koppelen zonder `symfony/ai-bundle` te hoeven installeren:

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

Dankzij `#[AsAlias(AssetEnricherInterface::class)]` gebruiken zowel de knop **✨ Voorstellen met AI** in de UI als het CLI-commando `file-manager:ai:enrich` automatisch jouw eigen service.

---

## 8. Beveiliging, Prompt Injection & Filtering van LLM-weigeringen

1. **Privébestanden (`private: true`):** Houd `block_private_volumes: true` ingeschakeld bij cloud-LLM's, of stap over op een zelfgehoste **Ollama**-instantie.
2. **Autorisatie:** Beperk toegang tot betaalde API's via `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` worden gezamenlijk gecontroleerd).
3. **Kostenbeheersing:** Houd `max_image_dimension: 1024` (of `768`) aan, en test CLI-runs altijd eerst met `--dry-run` en `--limit`.
4. **Bescherming tegen prompt injection & meertalige weigeringsfiltering:**
   - Bestandsnamen worden ontdaan van stuurtekens, bidirectionele overrides en aanhalingstekens — en afgekapt op **120 tekens** — voordat ze in een prompt terechtkomen.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` herkent weigeringsmeldingen van modellen in **11 talen** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) — zoals *"I'm sorry, I cannot analyze this image..."* — en voorkomt dat dergelijke tekst ooit als `alt`-metadata wordt opgeslagen.
   - Alle teruggegeven tekst wordt geschoond door `AssetEnrichmentResult` en `FolderMetadata::sanitizeTags()`, en altijd HTML-geëscaped in de DOM.

---

[← Vorige: 08 — Events, Commando's & Probleemoplossing](08-events-commands-cache-and-troubleshooting.md) | [Hoofdoverzicht](README.md) | [Volgende: 10 — Asset Usage Guard →](10-asset-usage-guard.md)
