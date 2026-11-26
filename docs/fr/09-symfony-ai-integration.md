# 09 — Intégration Symfony AI & Enrichissement intelligent des médias

[← Précédent : 08 — Événements, Commandes & Dépannage](08-events-commands-cache-and-troubleshooting.md) | [Index principal](README.md) | [Suivant : 10 — Protection contre la suppression de médias utilisés →](10-asset-usage-guard.md)

> [!TIP]
> **En termes simples : à quoi sert cette fonctionnalité ?**
> Lorsqu'un éditeur téléverse une photo de produit, il n'a plus besoin de rédiger manuellement le *texte alternatif, le titre, la légende et les étiquettes de recherche* pour chaque fichier. Un clic sur **« ✨ Suggérer avec l'IA »** dans le panneau d'inspection permet à votre modèle choisi (OpenAI, Gemini, Claude ou Ollama en local) d'analyser l'image et de remplir des métadonnées SEO ainsi que des `#étiquettes` dans la langue cible en ~2 secondes — et rien n'est enregistré tant que l'éditeur n'a pas relu et validé.
> *(Par défaut, les volumes privés ne sont jamais envoyés à des fournisseurs d'IA externes : `block_private_volumes: true`.)*

**LiveUX FileManagerBundle** s'intègre à l'écosystème officiel **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) **sans aucune dépendance obligatoire** :

- Si `symfony/ai-bundle` **n'est pas installé**, le bundle fonctionne sans erreur ni surcoût mémoire, et les boutons IA sont automatiquement masqués dans l'interface.
- Si `symfony/ai-bundle` **est installé**, `FileManagerBundle` détecte et câble automatiquement le pont intégré `SymfonyAiAssetEnricher`.
- Les fonctionnalités IA restent **désactivées tant que vous ne les activez pas explicitement par profil (`ai.enabled: true`)** — aucun risque de facture API imprévue.

---

## 1. Fonctionnalités clés

| Fonctionnalité | Description |
| :--- | :--- |
| **Textes `alt` SEO & WCAG automatisés** | Analyse les images et génère un texte `alt` prêt pour les lecteurs d'écran et le SEO, un `title` concis et une `caption` dans n'importe quelle langue (`fr`, `en`, `tr`, `de`, `ja`, `ar`, etc.). |
| **Étiquetage intelligent (`tags`)** | Extrait des mots-clés descriptifs depuis les images ou fichiers texte (ex. `["gourde isotherme", "noir mat", "cadeau d'entreprise"]`) immédiatement exploitables avec la recherche `#tag` du gestionnaire. |
| **Résumés de fichiers texte & documents** | Lit les 8 premiers Ko des fichiers `.txt`, `.md`, `.csv`, `.json` et `.yaml`/`.yml` pour suggérer des titres, descriptions et étiquettes de recherche. |
| **Optimisation automatique du coût en jetons** | Les photos volumineuses de 10–15 Mo ne sont jamais envoyées brutes aux API Vision ; une copie temporaire est d'abord redimensionnée à `max_image_dimension` (par défaut `1024px`), réduisant le coût en jetons d'environ 80 %. |
| **Indépendance vis-à-vis du fournisseur** | Basculez entre OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI ou **Ollama 100 % local et gratuit** en modifiant une seule ligne YAML. |
| **3 modes d'utilisation** | Action **« ✨ Suggérer avec l'IA »** validée par l'éditeur dans l'interface, enrichissement par lot d'archives existantes en CLI (`file-manager:ai:enrich`), ou traitement asynchrone en arrière-plan via `symfony/messenger`. |

---

## 2. Installation (choisissez votre fournisseur)

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

### Option D — Ollama local et 100 % privé (`qwen2.5vl` / `llava`)

Idéal lorsque des documents confidentiels d'entreprise ne doivent jamais quitter votre infrastructure :

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

## 3. Configuration de LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'fr'
        ai:
            default_model: 'gpt-4o-mini'
            # Optionnel : cibler un service plateforme précis si plusieurs plateformes Symfony AI sont configurées
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Par défaut : false (maîtrise des coûts)
                model: 'gpt-4o-mini'              # ex. 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'fr'                      # null hérite de la locale de la requête ou du profil actif
                max_tags: 6                       # Limite d'étiquettes (1..20)
                max_image_dimension: 1024         # Plafond de redimensionnement avant envoi à l'API Vision (256..2048 px)
                block_private_volumes: true       # Bloque les appels IA externes sur les volumes private: true
                auto_enrich_on_upload: false      # true exécute l'appel de façon synchrone au téléversement ; laissez false avec Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Totalement désactivé pour les contrats sensibles
```

### Ordre de résolution de la langue de sortie IA (`ai.locale`)

Lorsqu'un éditeur clique sur **✨ Suggérer avec l'IA**, la langue de la réponse est résolue dans cet ordre précis (les locales BCP-47, ICU et POSIX `.UTF-8` sont prises en charge) :

1. Paramètre explicite `aiLocale` transmis dans le corps de la requête
2. Option du profil `profiles.<name>.ai.locale` *(prioritaire sur la locale de l'interface lorsqu'elle est définie !)*
3. Locale active de la requête HTTP / de l'interface
4. Option du profil `profiles.<name>.locale`
5. `'en'` *(valeur de repli garantie)*

---

## 4. Utiliser l'IA dans l'interface (en un clic, avec validation éditoriale)

1. Faites un clic droit sur une image ou un fichier texte dans le gestionnaire → **🏷️ SEO & Point focal** (ou **Infos & Étiquettes**, ou ouvrez le panneau d'inspection à droite).
2. Le bouton **✨ Suggérer avec l'IA** n'est affiché — et strictement autorisé côté serveur — que lorsque **toutes ces 5 conditions** sont réunies :
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - Un service actif implémentant `AssetEnricherInterface` est enregistré
   - L'utilisateur possède simultanément les trois permissions — **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** — sur le fichier ainsi que sur tous ses dossiers parents
   - Si le volume est `private: true`, l'option `block_private_volumes: false` est explicitement configurée
3. Cliquer sur le bouton **n'écrase pas immédiatement** les métadonnées existantes : les champs `alt`, `title`, `caption` et `tags` suggérés par l'IA pré-remplissent le formulaire afin que l'éditeur puisse relire, ajuster puis cliquer sur **Enregistrer**.

---

## 5. Enrichir des archives existantes par lot via la CLI

Complétez les textes `alt` et étiquettes manquants sur des milliers de médias existants avec un contrôle strict des coûts :

```bash
# 1. Prévisualiser les fichiers qui seraient enrichis sans appeler l'API IA (simulation gratuite) :
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Enrichir en français les 50 premiers fichiers sans métadonnées :
php bin/console file-manager:ai:enrich products --limit=50 --locale=fr

# 3. Cibler un volume et un sous-dossier spécifiques :
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Forcer la régénération des métadonnées existantes (--force) :
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **Garde-fous de sécurité et de coûts en CLI (`file-manager:ai:enrich`) :**
> - `--limit` vaut **`50`** par défaut afin d'éviter d'envoyer accidentellement toute une médiathèque à une API payante en une seule exécution.
> - Sans `--force`, seuls les fichiers aux **métadonnées éditoriales incomplètes** sont traités (si `alt` est renseigné **et** qu'au moins un champ parmi `tags`/`title`/`caption` est rempli, le fichier est ignoré).
> - Le préchargement des métadonnées (`preload()`) s'effectue par lots de **250 éléments** ; les fichiers de plus de **20 Mo**, les volumes dynamiques `{user_id}` et les volumes `private: true` (lorsque `block_private_volumes: true`) sont automatiquement ignorés.

---

## 6. Enrichissement asynchrone automatique au téléversement (`symfony/messenger`)

> [!IMPORTANT]
> Définir `ai.auto_enrich_on_upload: true` fait que le `MetadataSyncListener` intégré appelle le fournisseur d'IA **de manière synchrone** pendant la requête HTTP de téléversement. Pour ne pas ralentir les téléversements, laissez `ai.auto_enrich_on_upload: false` et traitez l'enrichissement de façon asynchrone via Symfony Messenger.

### Étape 1 — Message Messenger

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

### Étape 2 — Écouteur sur `FileUploadedEvent`

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
        // Évite un double traitement si l'enrichissement synchrone auto_enrich_on_upload est actif :
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Évite de saturer la file IA lors de l'extraction d'une archive ZIP de 200 fichiers :
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

### Étape 3 — Handler du worker Messenger

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
            skipVoter: true, // Les workers en arrière-plan n'ont pas de session utilisateur HTTP active
        );
    }
}
```

> [!WARNING]
> **Points d'attention concernant les workers :**
> 1. Comme les workers Messenger s'exécutent sans session utilisateur authentifiée, les volumes dynamiques `{user_id}` se résolvent en `guest` — ne distribuez pas d'enrichissement asynchrone pour des volumes par utilisateur `{user_id}`.
> 2. Même avec `skipVoter: true`, la protection `block_private_volumes: true` reste active et lève une `AccessDeniedException` sur les volumes `private: true`.

---

## 7. Écrire un fournisseur IA personnalisé (`AssetEnricherInterface`)

Vous pouvez brancher votre propre microservice Python, AWS Rekognition, Azure AI Vision ou n'importe quelle API HTTP personnalisée sans installer `symfony/ai-bundle` :

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
        string $locale = 'fr',
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

Grâce à `#[AsAlias(AssetEnricherInterface::class)]`, le bouton **✨ Suggérer avec l'IA** de l'interface comme la commande CLI `file-manager:ai:enrich` utilisent automatiquement votre service personnalisé.

---

## 8. Sécurité, injection de prompt & filtrage des refus LLM

1. **Fichiers privés (`private: true`) :** conservez `block_private_volumes: true` lorsque vous utilisez des LLM cloud, ou basculez vers une instance **Ollama** auto-hébergée.
2. **Autorisations :** limitez l'accès aux API payantes via `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` sont toutes vérifiées conjointement).
3. **Maîtrise des coûts :** gardez `max_image_dimension: 1024` (ou `768`) et prévisualisez toujours vos exécutions CLI avec `--dry-run` et `--limit`.
4. **Protection contre l'injection de prompt & filtrage multilingue des refus :**
   - Les noms de fichiers sont débarrassés des caractères de contrôle, marqueurs bidirectionnels et guillemets — et tronqués à **120 caractères** — avant d'entrer dans un prompt.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` détecte les messages de refus des modèles dans **11 langues** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) — tels que *« Je suis désolé, je ne peux pas analyser cette image... »* — empêchant qu'un texte de refus ne soit enregistré comme métadonnée `alt`.
   - Tous les textes retournés sont assainis par `AssetEnrichmentResult` et `FolderMetadata::sanitizeTags()`, puis systématiquement échappés en HTML dans le DOM.

---

[← Précédent : 08 — Événements, Commandes & Dépannage](08-events-commands-cache-and-troubleshooting.md) | [Index principal](README.md) | [Suivant : 10 — Protection contre la suppression de médias utilisés →](10-asset-usage-guard.md)
