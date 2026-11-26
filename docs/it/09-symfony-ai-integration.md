# 09 — Integrazione Symfony AI e Arricchimento Intelligente degli Asset

[← Precedente: 08 — Eventi, Comandi e Risoluzione dei Problemi](08-events-commands-cache-and-troubleshooting.md) | [Indice Principale](README.md) | [Successivo: 10 — Asset Usage Guard →](10-asset-usage-guard.md)

> [!TIP]
> **In Parole Semplici: Cosa Fa Questa Funzionalità?**
> Quando un editor carica la foto di un prodotto, non deve più scrivere manualmente *"testo alt, titolo, didascalia e tag di ricerca"* per ogni singolo file. Facendo clic su **"✨ Suggerisci con AI"** nel pannello Inspector, il modello scelto (OpenAI, Gemini, Claude o Ollama in locale) analizza l'immagine e compila metadati SEO-friendly e `#tag` nella lingua di destinazione in ~2 secondi — e nulla viene salvato finché l'editor non controlla e approva il risultato.
> *(Per impostazione predefinita, i volumi privati non vengono mai inviati a provider AI esterni: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** si integra con l'ecosistema ufficiale **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) con **zero dipendenze obbligatorie**:

- Se `symfony/ai-bundle` **non è installato**, il bundle funziona senza alcun errore né consumo extra di memoria, e i pulsanti AI vengono nascosti automaticamente nell'interfaccia.
- Se `symfony/ai-bundle` **è installato**, `FileManagerBundle` rileva e collega automaticamente il bridge integrato `SymfonyAiAssetEnricher`.
- Le funzionalità AI restano **disattivate salvo attivazione esplicita per singolo profilo (`ai.enabled: true`)**, così eviti sorprese in fattura sulle API.

---

## 1. Funzionalità Principali

| Funzionalità | Descrizione |
| :--- | :--- |
| **Testo `alt` Automatico per SEO e WCAG** | Analizza le immagini e genera un testo `alt` pronto per screen reader e SEO, un `title` conciso e una `caption` in qualsiasi lingua (`it`, `en`, `tr`, `fr`, `de`, `ja`, `ar`, ecc.). |
| **Auto-Tagging Intelligente (`tags`)** | Estrae parole chiave descrittive da immagini o file di testo (es. `["borraccia termica", "nero opaco", "regalo aziendale"]`) subito utilizzabili con la ricerca `#tag` nel file manager. |
| **Sintesi di Testi e Documenti** | Legge i primi 8 KB di file `.txt`, `.md`, `.csv`, `.json` e `.yaml`/`.yml` per proporre titoli, descrizioni e tag di ricerca. |
| **Ottimizzazione Automatica del Costo Token** | Le foto pesanti da 10–15 MB non vengono mai inviate grezze alle Vision API: una copia temporanea viene ridimensionata prima a `max_image_dimension` (predefinito `1024px`), riducendo i costi token di circa l'80%. |
| **Indipendenza dal Provider** | Passa da OpenAI a Google Gemini, Anthropic Claude, Mistral, VertexAI o **Ollama locale gratuito al 100%** cambiando una sola riga YAML. |
| **3 Flussi di Lavoro** | **"✨ Suggerisci con AI"** nell'UI con approvazione dell'editor, arricchimento batch dell'archivio da CLI (`file-manager:ai:enrich`) o elaborazione automatica in background con `symfony/messenger`. |

---

## 2. Installazione (Scegli il Tuo Provider)

### Opzione A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

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

### Opzione B — Google Gemini (`gemini-2.5-flash`)

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

### Opzione C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

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

### Opzione D — Ollama Locale Privato al 100% (`qwen2.5vl` / `llava`)

Ideale quando documenti aziendali riservati non devono mai lasciare la tua infrastruttura:

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

## 3. Configurazione di LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'it'
        ai:
            default_model: 'gpt-4o-mini'
            # Opzionale: punta a un servizio piattaforma specifico se sono configurate più piattaforme Symfony AI
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Predefinito: false (protezione costi)
                model: 'gpt-4o-mini'              # es. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'it'                      # null eredita il locale attivo della richiesta/profilo
                max_tags: 6                       # Numero massimo di tag (1..20)
                max_image_dimension: 1024         # Limite ridimensionamento prima dell'invio alle Vision API (256..2048 px)
                block_private_volumes: true       # Blocca chiamate AI esterne sui volumi con private: true
                auto_enrich_on_upload: false      # true esegue in modo sincrono al caricamento; lascia false con Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Completamente disabilitato per contratti sensibili
```

### Ordine di Risoluzione della Lingua di Output AI (`ai.locale`)

Quando un editor fa clic su **✨ Suggerisci con AI**, la lingua della risposta viene determinata esattamente in questo ordine (sono supportati i locale BCP-47, ICU e POSIX `.UTF-8`):

1. `aiLocale` esplicito inviato nel corpo della richiesta
2. Impostazione del profilo `profiles.<name>.ai.locale` *(sovrascrive la lingua dell'UI se impostata!)*
3. Locale attivo della richiesta HTTP / UI
4. Impostazione del profilo `profiles.<name>.locale`
5. `'en'` *(fallback garantito)*

---

## 4. Usare l'AI nell'UI (Un Solo Clic, Approvato dall'Editor)

1. Fai clic con il tasto destro su un'immagine o un file di testo nel file manager → **🏷️ SEO e punto focale** / **Info e tag** (oppure apri il pannello Inspector a destra).
2. Il pulsante **✨ Suggerisci con AI** viene mostrato — e verificato rigorosamente lato server — solo quando sono soddisfatte **tutte e 5 le condizioni**:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - È registrato un servizio `AssetEnricherInterface` attivo
   - L'utente possiede tutti e tre i permessi — **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** — sul file e su tutte le sue cartelle genitore
   - Se il volume ha `private: true`, è configurato esplicitamente `block_private_volumes: false`
3. Fare clic sul pulsante **non sovrascrive** subito i metadati esistenti: i campi `alt`, `title`, `caption` e `tags` suggeriti dall'AI compilano il form in modo che l'editor possa rivederli, rifinirli e fare clic su **Salva**.

---

## 5. Arricchimento Batch di Archivi Esistenti via CLI

Compila i testi `alt` e i tag mancanti su migliaia di asset esistenti con controlli rigorosi sui costi:

```bash
# 1. Anteprima dei file che verrebbero arricchiti senza chiamare l'API AI (simulazione gratuita):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Arricchisci i primi 50 file senza metadati in italiano:
php bin/console file-manager:ai:enrich products --limit=50 --locale=it

# 3. Limita a un volume e a una sottocartella specifici:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Forza la rigenerazione dei metadati esistenti (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **Protezioni di Sicurezza e Costi da CLI (`file-manager:ai:enrich`):**
> - `--limit` ha come valore predefinito **`50`** per evitare di inviare per errore un intero archivio a un'API a pagamento in un'unica esecuzione.
> - Senza `--force`, vengono elaborati solo i file con **metadati editoriali mancanti** (se `alt` è compilato **e** almeno uno tra `tags`/`title`/`caption` è compilato, il file viene saltato).
> - Il precaricamento dei metadati (`preload()`) avviene in blocchi da **250 elementi**; i file più grandi di **20 MB**, i volumi dinamici `{user_id}` e i volumi `private: true` (quando `block_private_volumes: true`) vengono saltati automaticamente.

---

## 6. Arricchimento Asincrono Automatico al Caricamento (`symfony/messenger`)

> [!IMPORTANT]
> Impostare `ai.auto_enrich_on_upload: true` fa sì che il `MetadataSyncListener` integrato chiami il provider AI **in modo sincrono** durante la richiesta HTTP di upload. Per non rallentare i caricamenti, lascia `ai.auto_enrich_on_upload: false` ed esegui l'arricchimento in modo asincrono tramite Symfony Messenger.

### Passaggio 1 — Messaggio Messenger

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

### Passaggio 2 — Listener su `FileUploadedEvent`

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
        // Evita elaborazioni duplicate se auto_enrich_on_upload sincrono è attivo:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Evita di saturare la coda AI quando si estrae un archivio ZIP con 200 file:
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

### Passaggio 3 — Handler del Worker Messenger

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
            skipVoter: true, // I worker in background non hanno una sessione utente HTTP attiva
        );
    }
}
```

> [!WARNING]
> **Avvertenze per i Worker:**
> 1. Poiché i worker Messenger girano senza una sessione utente autenticata, i volumi dinamici `{user_id}` vengono risolti come `guest`: non inviare arricchimenti asincroni per volumi `{user_id}` per-utente.
> 2. Anche con `skipVoter: true`, la protezione `block_private_volumes: true` rimane attiva e solleva `AccessDeniedException` sui volumi `private: true`.

---

## 7. Scrivere un Provider AI Personalizzato (`AssetEnricherInterface`)

Puoi integrare un tuo microservizio Python, AWS Rekognition, Azure AI Vision o qualsiasi API HTTP personalizzata senza installare `symfony/ai-bundle`:

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
        string $locale = 'it',
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

Con `#[AsAlias(AssetEnricherInterface::class)]`, sia il pulsante **✨ Suggerisci con AI** nell'UI sia il comando CLI `file-manager:ai:enrich` utilizzeranno automaticamente il tuo servizio personalizzato.

---

## 8. Sicurezza, Prompt Injection e Protezione dai Rifiuti degli LLM

1. **File Privati (`private: true`):** mantieni `block_private_volumes: true` quando usi LLM in cloud, oppure passa a un'istanza **Ollama** self-hosted.
2. **Autorizzazione:** limita l'accesso alle API a pagamento con `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` vengono verificati sempre insieme).
3. **Controllo dei Costi:** mantieni `max_image_dimension: 1024` (o `768`) e testa sempre le esecuzioni CLI con `--dry-run` e `--limit`.
4. **Protezione da Prompt Injection e Filtraggio Multilingua dei Rifiuti:**
   - I nomi dei file vengono ripuliti da caratteri di controllo, override bidirezionali e virgolette — e troncati a **120 caratteri** — prima di entrare in qualsiasi prompt.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` rileva i messaggi di rifiuto dei modelli in **11 lingue** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) — come *"Mi dispiace, non posso analizzare questa immagine..."* — impedendo che un testo di rifiuto venga salvato come metadato `alt`.
   - Tutto il testo restituito viene sanitizzato da `AssetEnrichmentResult` e `FolderMetadata::sanitizeTags()`, e sempre sottoposto a HTML-escaping nel DOM.

---

[← Precedente: 08 — Eventi, Comandi e Risoluzione dei Problemi](08-events-commands-cache-and-troubleshooting.md) | [Indice Principale](README.md) | [Successivo: 10 — Asset Usage Guard →](10-asset-usage-guard.md)
