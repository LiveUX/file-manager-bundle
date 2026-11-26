# 09 — Integração Symfony AI e Enriquecimento Inteligente de Ativos

[← Anterior: 08 — Eventos, Comandos e Solução de Problemas](08-events-commands-cache-and-troubleshooting.md) | [Índice Principal](README.md) | [Próximo: 10 — Proteção de Uso de Ativos →](10-asset-usage-guard.md)

> [!TIP]
> **Em termos simples: o que este recurso faz?**
> Quando um editor envia a foto de um produto, ele não precisa mais escrever manualmente *"texto alternativo, título, legenda e etiquetas de pesquisa"* para cada arquivo. Ao clicar em **"✨ Sugerir com IA"** no painel Inspetor, o modelo escolhido (OpenAI, Gemini, Claude ou Ollama local) analisa a imagem e preenche metadados prontos para SEO e `#tags` no idioma desejado em cerca de 2 segundos — e nada é salvo até que o editor revise e aprove.
> *(Por padrão, volumes privados nunca são enviados a provedores de IA externos: `block_private_volumes: true`.)*

O **LiveUX FileManagerBundle** integra-se ao ecossistema oficial **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) com **zero dependências obrigatórias**:

- Se `symfony/ai-bundle` **não estiver instalado**, o bundle funciona sem erros e sem consumo extra de memória, e os botões de IA ficam ocultos automaticamente na interface.
- Se `symfony/ai-bundle` **estiver instalado**, o `FileManagerBundle` detecta e registra automaticamente a ponte integrada `SymfonyAiAssetEnricher`.
- Os recursos de IA permanecem **desativados até serem habilitados explicitamente por perfil (`ai.enabled: true`)** — garantindo que você nunca receba surpresas na fatura da API.

---

## 1. Principais Recursos

| Recurso | Descrição |
| :--- | :--- |
| **Texto `alt` Automatizado para SEO e WCAG** | Analisa imagens e gera texto `alt` pronto para leitores de tela e SEO, além de `title` conciso e `caption` em qualquer idioma (`en`, `pt`, `tr`, `fr`, `de`, `ja`, `ar` etc.). |
| **Etiquetagem Inteligente (`tags`)** | Extrai palavras-chave descritivas de imagens ou arquivos de texto (ex.: `["garrafa térmica", "preto fosco", "brinde corporativo"]`) que funcionam imediatamente com a pesquisa `#tag` no gerenciador de arquivos. |
| **Resumo de Textos e Documentos** | Lê os primeiros 8 KB de arquivos `.txt`, `.md`, `.csv`, `.json` e `.yaml`/`.yml` para sugerir títulos, descrições e etiquetas de pesquisa. |
| **Otimização Automática de Custos de Tokens** | Fotos grandes de 10–15 MB nunca são enviadas em tamanho original para APIs Vision; uma cópia temporária é redimensionada para `max_image_dimension` (padrão `1024px`) primeiro, reduzindo os custos de tokens em ~80%. |
| **Independência de Provedor** | Alterne entre OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI ou **Ollama 100% local e gratuito** mudando apenas uma linha no YAML. |
| **3 Fluxos de Trabalho** | **"✨ Sugerir com IA"** aprovado pelo editor na interface, enriquecimento em lote via CLI (`file-manager:ai:enrich`) ou processamento automático em segundo plano com `symfony/messenger`. |

---

## 2. Instalação (Escolha seu Provedor)

### Opção A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

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

### Opção B — Google Gemini (`gemini-2.5-flash`)

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

### Opção C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

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

### Opção D — Ollama Local e 100% Privado (`qwen2.5vl` / `llava`)

Ideal quando documentos confidenciais da empresa nunca podem sair da sua infraestrutura:

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

## 3. Configuração do LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'pt'
        ai:
            default_model: 'gpt-4o-mini'
            # Opcional: especifique um serviço de plataforma se múltiplas plataformas Symfony AI estiverem configuradas
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Padrão: false (proteção contra custos)
                model: 'gpt-4o-mini'              # ex.: 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'pt'                      # null herda o locale ativo da requisição/perfil
                max_tags: 6                       # Limite de etiquetas (1..20)
                max_image_dimension: 1024         # Teto de redimensionamento antes de enviar à API Vision (256..2048 px)
                block_private_volumes: true       # Bloqueia chamadas externas de IA em volumes private: true
                auto_enrich_on_upload: false      # true executa de forma síncrona no envio; mantenha false ao usar Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Totalmente desativado para contratos confidenciais
```

### Ordem de Resolução do Idioma de Saída da IA (`ai.locale`)

Quando um editor clica em **✨ Sugerir com IA**, o idioma da resposta é resolvido exatamente nesta ordem (locales BCP-47, ICU e POSIX `.UTF-8` são suportados):

1. Campo explícito `aiLocale` enviado no corpo da requisição
2. Configuração do perfil `profiles.<name>.ai.locale` *(sobrescreve o locale da interface quando definido!)*
3. Locale ativo da requisição HTTP / interface
4. Configuração do perfil `profiles.<name>.locale`
5. `'en'` *(fallback garantido)*

---

## 4. Usando IA na Interface (Um Clique, Aprovado pelo Editor)

1. Clique com o botão direito em uma imagem ou arquivo de texto no gerenciador de arquivos → **🏷️ SEO e ponto focal** / **Informações e etiquetas** (ou abra o painel Inspetor à direita).
2. O botão **✨ Sugerir com IA** só é renderizado — e validado rigorosamente no servidor — quando **todas as 5 condições** forem atendidas:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - Um serviço ativo de `AssetEnricherInterface` está registrado
   - O usuário possui todas as três permissões — **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** — no arquivo e em todas as pastas ancestrais
   - Se o volume for `private: true`, `block_private_volumes: false` está configurado explicitamente
3. Clicar no botão **não sobrescreve** imediatamente os metadados existentes; os campos `alt`, `title`, `caption` e `tags` sugeridos pela IA preenchem o formulário para que o editor revise, ajuste e clique em **Salvar**.

---

## 5. Enriquecimento em Lote de Acervos Existentes via CLI

Preencha textos `alt` e etiquetas ausentes em milhares de ativos existentes com controles rigorosos de custo:

```bash
# 1. Visualize quais arquivos seriam enriquecidos sem chamar a API de IA (simulação gratuita):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Enriqueça os primeiros 50 arquivos sem metadados em português:
php bin/console file-manager:ai:enrich products --limit=50 --locale=pt

# 3. Restrinja a um volume e subpasta específicos:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. Force a regeneração de metadados existentes (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **Travas de Segurança e Custo no CLI (`file-manager:ai:enrich`):**
> - `--limit` tem padrão **`50`** para que você nunca envie acidentalmente um acervo inteiro para uma API paga em uma única execução.
> - Sem `--force`, apenas arquivos com **metadados editoriais ausentes** são processados (se `alt` estiver preenchido **e** pelo menos um entre `tags`/`title`/`caption` estiver preenchido, o arquivo é ignorado).
> - O pré-carregamento de metadados (`preload()`) roda em lotes de **250 itens**; arquivos maiores que **20 MB**, volumes dinâmicos `{user_id}` e volumes `private: true` (quando `block_private_volumes: true`) são ignorados automaticamente.

---

## 6. Enriquecimento Assíncrono Automático no Envio (`symfony/messenger`)

> [!IMPORTANT]
> Definir `ai.auto_enrich_on_upload: true` faz o `MetadataSyncListener` integrado chamar o provedor de IA **de forma síncrona** durante a requisição HTTP de upload. Para não deixar os envios lentos, mantenha `ai.auto_enrich_on_upload: false` e processe o enriquecimento de forma assíncrona com o Symfony Messenger.

### Passo 1 — Mensagem do Messenger

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

### Passo 2 — Listener de `FileUploadedEvent`

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
        // Evita processamento duplicado caso o auto_enrich_on_upload síncrono esteja ativado:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // Evita sobrecarregar a fila de IA ao extrair um ZIP com 200 arquivos:
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

### Passo 3 — Handler do Worker do Messenger

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
            skipVoter: true, // Workers em segundo plano não possuem sessão HTTP de usuário ativa
        );
    }
}
```

> [!WARNING]
> **Ressalvas para Workers:**
> 1. Como workers do Messenger executam sem sessão de usuário autenticada, volumes dinâmicos `{user_id}` resolvem para `guest` — não dispare enriquecimento assíncrono para volumes `{user_id}` por usuário.
> 2. Mesmo com `skipVoter: true`, a salvaguarda `block_private_volumes: true` continua ativa e lança `AccessDeniedException` em volumes `private: true`.

---

## 7. Criando um Provedor de IA Personalizado (`AssetEnricherInterface`)

Você pode integrar seu próprio microsserviço Python, AWS Rekognition, Azure AI Vision ou qualquer API HTTP personalizada sem precisar instalar `symfony/ai-bundle`:

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

Com `#[AsAlias(AssetEnricherInterface::class)]`, tanto o botão **✨ Sugerir com IA** na interface quanto o comando CLI `file-manager:ai:enrich` passam a usar automaticamente o seu serviço personalizado.

---

## 8. Segurança, Prompt Injection e Proteção contra Recusas de LLM

1. **Arquivos Privados (`private: true`):** Mantenha `block_private_volumes: true` ao usar LLMs em nuvem, ou utilize uma instância própria do **Ollama**.
2. **Autorização:** Restrinja o acesso a APIs pagas via `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` são verificadas em conjunto).
3. **Controle de Custos:** Mantenha `max_image_dimension: 1024` (ou `768`) e sempre faça uma prévia das execuções CLI com `--dry-run` e `--limit`.
4. **Proteção contra Prompt Injection e Recusas Multilíngues:**
   - Nomes de arquivos têm caracteres de controle, overrides bidirecionais e aspas removidos — e são limitados a **120 caracteres** — antes de entrarem em qualquer prompt.
   - `SymfonyAiAssetEnricher::parseJsonResponse()` detecta mensagens de recusa do modelo em **11 idiomas** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) — como *"I'm sorry, I cannot analyze this image..."* — evitando que textos de recusa sejam salvos como metadados `alt`.
   - Todo texto retornado é sanitizado por `AssetEnrichmentResult` e `FolderMetadata::sanitizeTags()`, e sempre escapado em HTML no DOM.

---

[← Anterior: 08 — Eventos, Comandos e Solução de Problemas](08-events-commands-cache-and-troubleshooting.md) | [Índice Principal](README.md) | [Próximo: 10 — Proteção de Uso de Ativos →](10-asset-usage-guard.md)
