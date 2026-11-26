# 09 — Integracja z Symfony AI i inteligentne wzbogacanie zasobów

[← Poprzedni: 08 — Zdarzenia, polecenia i rozwiązywanie problemów](08-events-commands-cache-and-troubleshooting.md) | [Spis treści](README.md) | [Następny: 10 — Ochrona używanych zasobów (Asset Usage Guard) →](10-asset-usage-guard.md)

> [!TIP]
> **Po ludzku: co dokładnie daje ta funkcja?**
> Gdy redaktor przesyła zdjęcie produktu, nie musi już ręcznie wpisywać *„tekstu alternatywnego (alt), tytułu, opisu i tagów wyszukiwania”* dla każdego pliku. Kliknięcie **„✨ Zasugeruj z AI”** w panelu szczegółów sprawia, że wybrany model (OpenAI, Gemini, Claude lub lokalna Ollama) analizuje obraz i w ~2 sekundy wypełnia metadane pod SEO oraz `#tagi` w wybranym języku — przy czym nic nie jest zapisywane, dopóki redaktor nie sprawdzi i nie zatwierdzi sugestii.
> *(Domyślnie wolumeny prywatne nigdy nie są wysyłane do zewnętrznych dostawców AI: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** integruje się z oficjalnym ekosystemem **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) przy **zerowych obowiązkowych zależnościach**:

- Jeśli `symfony/ai-bundle` **nie jest zainstalowany**, pakiet działa bez żadnych błędów i bez narzutu pamięciowego, a przyciski AI są automatycznie ukryte w interfejsie.
- Jeśli `symfony/ai-bundle` **jest zainstalowany**, `FileManagerBundle` automatycznie wykrywa i rejestruje wbudowany mostek `SymfonyAiAssetEnricher`.
- Funkcje AI pozostają **wyłączone, dopóki nie włączysz ich jawnie na wybranym profilu (`ai.enabled: true`)** — dzięki czemu nie zaskoczą Cię niespodziewane rachunki za API.

---

## 1. Najważniejsze możliwości

| Możliwość | Opis |
| :--- | :--- |
| **Automatyczne teksty `alt` pod SEO i WCAG** | Analizuje obrazy i generuje gotowy dla czytników ekranu oraz wyszukiwarek tekst `alt`, zwięzły `title` i `caption` w dowolnym języku (`pl`, `en`, `tr`, `fr`, `de`, `ja`, `ar` itd.). |
| **Inteligentne auto-tagowanie (`tags`)** | Wyodrębnia opisowe słowa kluczowe z obrazów lub plików tekstowych (np. `["termos próżniowy", "czarny mat", "gadżet firmowy"]`), które natychmiast działają z wyszukiwaniem `#tag` w menedżerze plików. |
| **Podsumowania dokumentów i plików tekstowych** | Odczytuje pierwsze 8 KB plików `.txt`, `.md`, `.csv`, `.json` oraz `.yaml`/`.yml`, aby zasugerować tytuły, opisy i tagi wyszukiwania. |
| **Automatyczna optymalizacja kosztów tokenów** | Duże zdjęcia 10–15 MB nigdy nie trafiają w surowej postaci do Vision API; tymczasowa kopia jest najpierw skalowana do `max_image_dimension` (domyślnie `1024px`), co zmniejsza koszt tokenów o ~80%. |
| **Niezależność od dostawcy** | Przełączaj się między OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI lub **w 100% lokalną, darmową Ollamą**, zmieniając jedną linijkę w YAML. |
| **3 tryby pracy** | Zatwierdzane przez redaktora **„✨ Zasugeruj z AI”** w UI, wsadowe wzbogacanie archiwum przez CLI (`file-manager:ai:enrich`) lub automatyczne przetwarzanie w tle z `symfony/messenger`. |

---

## 2. Instalacja (wybierz swojego dostawcę)

### Opcja A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

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

### Opcja B — Google Gemini (`gemini-2.5-flash`)

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

### Opcja C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

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

### Opcja D — Lokalna, w 100% prywatna Ollama (`qwen2.5vl` / `llava`)

Idealna tam, gdzie poufne dokumenty firmowe nie mogą opuścić Twojej infrastruktury:

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

## 3. Konfiguracja LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'pl'
        ai:
            default_model: 'gpt-4o-mini'
            # Opcjonalnie: wskaż konkretną usługę platformy, jeśli skonfigurowano wiele platform Symfony AI
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Domyślnie: false (ochrona przed kosztami)
                model: 'gpt-4o-mini'              # np. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'pl'                      # null dziedziczy aktywny język żądania/profilu
                max_tags: 6                       # Limit tagów (1..20)
                max_image_dimension: 1024         # Maksymalny wymiar po przeskalowaniu przed wysłaniem do Vision API (256..2048 px)
                block_private_volumes: true       # Blokuje zewnętrzne wywołania AI na wolumenach private: true
                auto_enrich_on_upload: false      # true uruchamia synchronicznie podczas przesyłania; pozostaw false przy użyciu Messengera
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Całkowicie wyłączone dla poufnych umów
```

### Kolejność wyznaczania języka odpowiedzi AI (`ai.locale`)

Gdy redaktor klika **✨ Zasugeruj z AI**, język odpowiedzi ustalany jest dokładnie w tej kolejności (obsługiwane są kody BCP-47, ICU oraz POSIX `.UTF-8`):

1. Jawny parametr `aiLocale` przekazany w treści żądania
2. Ustawienie profilu `profiles.<name>.ai.locale` *(jeśli ustawione, nadpisuje język interfejsu!)*
3. Aktywny język żądania HTTP / interfejsu użytkownika
4. Ustawienie profilu `profiles.<name>.locale`
5. `'en'` *(gwarantowany język zapasowy)*

---

## 4. Korzystanie z AI w interfejsie (jedno kliknięcie, akceptacja redaktora)

1. Kliknij prawym przyciskiem obraz lub plik tekstowy w menedżerze plików → **🏷️ SEO i punkt ogniskowy** (lub **Informacje i tagi** / otwórz prawy panel **Podgląd i szczegóły**).
2. Przycisk **✨ Zasugeruj z AI** jest wyświetlany — i rygorystycznie weryfikowany po stronie serwera — tylko wtedy, gdy spełnione są **wszystkie 5 warunków**:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - Zarejestrowana jest aktywna usługa `AssetEnricherInterface`
   - Użytkownik posiada wszystkie trzy uprawnienia — **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** — dla danego pliku oraz wszystkich jego folderów nadrzędnych
   - Jeśli wolumen ma ustawione `private: true`, jawnie skonfigurowano `block_private_volumes: false`
3. Kliknięcie przycisku **nie nadpisuje** natychmiast istniejących metadanych; zasugerowane przez AI pola `alt`, `title`, `caption` i `tags` są wpisywane do formularza, aby redaktor mógł je sprawdzić, poprawić i kliknąć **Zapisz**.

---

## 5. Wsadowe wzbogacanie istniejących archiwów przez CLI

Uzupełnij brakujące teksty `alt` i tagi w tysiącach istniejących zasobów z pełną kontrolą kosztów:

```bash
# 1. Podgląd plików, które zostałyby wzbogacone, bez wywoływania API AI (darmowy dry run):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Wzbogać pierwsze 50 plików z brakującymi metadanymi po polsku:
php bin/console file-manager:ai:enrich products --limit=50 --locale=pl

# 3. Ogranicz zakres do konkretnego wolumenu i podfolderu:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Wymuś ponowne wygenerowanie istniejących metadanych (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **Zabezpieczenia i limity kosztów CLI (`file-manager:ai:enrich`):**
> - `--limit` ma domyślną wartość **`50`**, aby nie wysłać przypadkiem całego archiwum do płatnego API w jednym uruchomieniu.
> - Bez flagi `--force` przetwarzane są wyłącznie pliki z **brakującymi metadanymi redakcyjnymi** (jeśli `alt` jest uzupełniony **oraz** uzupełnione jest co najmniej jedno z pól `tags`/`title`/`caption`, plik jest pomijany).
> - Wstępne ładowanie metadanych (`preload()`) odbywa się w partiach po **250 elementów**; pliki większe niż **20 MB**, dynamiczne wolumeny `{user_id}` oraz wolumeny `private: true` (przy `block_private_volumes: true`) są automatycznie pomijane.

---

## 6. Automatyczne asynchroniczne wzbogacanie przy przesyłaniu (`symfony/messenger`)

> [!IMPORTANT]
> Ustawienie `ai.auto_enrich_on_upload: true` powoduje, że wbudowany `MetadataSyncListener` wywołuje dostawcę AI **synchronicznie** podczas żądania HTTP przesyłającego plik. Aby nie spowalniać uploadu, pozostaw `ai.auto_enrich_on_upload: false` i realizuj wzbogacanie asynchronicznie przez Symfony Messenger.

### Krok 1 — Wiadomość Messengera

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

### Krok 2 — Listener zdarzenia `FileUploadedEvent`

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
        // Zapobiegaj podwójnemu przetwarzaniu, jeśli włączono synchroniczne auto_enrich_on_upload:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Unikaj zalewania kolejki AI przy rozpakowywaniu archiwum ZIP z 200 plikami:
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

### Krok 3 — Handler workera Messengera

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
            skipVoter: true, // Workery w tle nie mają aktywnej sesji użytkownika HTTP
        );
    }
}
```

> [!WARNING]
> **Ograniczenia workerów:**
> 1. Ponieważ workery Messengera działają bez uwierzytelnionej sesji użytkownika, dynamiczne wolumeny `{user_id}` rozwiązują się jako `guest` — nie zlecaj asynchronicznego wzbogacania dla per-użytkownikowych wolumenów `{user_id}`.
> 2. Nawet z opcją `skipVoter: true` zabezpieczenie `block_private_volumes: true` pozostaje aktywne i rzuca wyjątek `AccessDeniedException` dla wolumenów `private: true`.

---

## 7. Tworzenie własnego dostawcy AI (`AssetEnricherInterface`)

Możesz podłączyć własny mikroserwis w Pythonie, AWS Rekognition, Azure AI Vision lub dowolne niestandardowe API HTTP bez instalowania `symfony/ai-bundle`:

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
        string $locale = 'pl',
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

Dzięki atrybutowi `#[AsAlias(AssetEnricherInterface::class)]` zarówno przycisk **✨ Zasugeruj z AI** w interfejsie, jak i polecenie CLI `file-manager:ai:enrich` automatycznie korzystają z Twojej własnej usługi.

---

## 8. Bezpieczeństwo, ochrona przed prompt injection i filtrowanie odmów LLM

1. **Pliki prywatne (`private: true`):** zachowaj `block_private_volumes: true` podczas korzystania z chmurowych modeli LLM lub przełącz się na własną instancję **Ollama**.
2. **Autoryzacja:** ogranicz dostęp do płatnego API przez `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (uprawnienia `VIEW` + `EDIT_METADATA` + `AI_ENRICH` są weryfikowane łącznie).
3. **Kontrola kosztów:** utrzymuj `max_image_dimension: 1024` (lub `768`), a uruchomienia CLI zawsze sprawdzaj wcześniej za pomocą `--dry-run` i `--limit`.
4. **Ochrona przed prompt injection i wielojęzyczne filtrowanie odmów:**
   - Nazwy plików przed umieszczeniem w prompcie są oczyszczane ze znaków sterujących, nadpisań kierunku tekstu (bidirectional overrides) oraz cudzysłowów, a ich długość jest ograniczana do **120 znaków**.
   - Metoda `SymfonyAiAssetEnricher::parseJsonResponse()` wykrywa komunikaty odmowy modelu w **11 językach** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) — np. *„Przepraszam, nie mogę przeanalizować tego obrazu…”* — zapobiegając zapisaniu tekstu odmowy jako metadanych `alt`.
   - Cały zwrócony tekst jest oczyszczany przez `AssetEnrichmentResult` oraz `FolderMetadata::sanitizeTags()`, a w drzewie DOM zawsze podlega escapowaniu HTML.

---

[← Poprzedni: 08 — Zdarzenia, polecenia i rozwiązywanie problemów](08-events-commands-cache-and-troubleshooting.md) | [Spis treści](README.md) | [Następny: 10 — Ochrona używanych zasobów (Asset Usage Guard) →](10-asset-usage-guard.md)
