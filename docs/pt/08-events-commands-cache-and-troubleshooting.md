# 08 — Eventos, Comandos CLI, Cache, Traduções e Solução de Problemas (FAQ)

[← Anterior: 07 — Armazenamento em Nuvem e Configuração Dinâmica](07-cloud-storage-and-dynamic-config.md) | [Índice Principal](README.md) | [Próximo: 09 — Integração Symfony AI →](09-symfony-ai-integration.md)

> [!TIP]
> **Resumo Rápido: Para que servem Eventos e Comandos CLI?**
> - **Eventos:** Notificam automaticamente sua aplicação Symfony sempre que um usuário envia, exclui, renomeia ou move um arquivo — por exemplo: *"quando um arquivo for renomeado, atualizar automaticamente o caminho da imagem do produto no banco de dados."*
> - **Comandos CLI (`php bin/console file-manager:...`):** Ferramentas integradas de terminal para executar diagnósticos (`doctor`), invalidar caches (`cache:clear`), pré-gerar miniaturas (`thumbs:warmup`) ou limpar itens expirados da lixeira (`trash:purge`).

---

## 1. Eventos Symfony (9 Eventos de Domínio)

Todo evento do gerenciador de arquivos estende a classe base comum **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Escute esses eventos para manter seu banco de dados sincronizado, gravar logs de auditoria, limpar caches de CDN ou disparar jobs em segundo plano.

| Classe de Evento (`LiveUX\FileManagerBundle\Event\*`) | Disparado quando | Propriedades |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Um arquivo é enviado, importado via URL, salvo pelo Editor de Imagens ou extraído de um arquivo ZIP | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Um arquivo é excluído (movido para a lixeira ou excluído permanentemente) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Uma pasta é criada (inclusive durante a extração de um ZIP) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Uma pasta é excluída | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Um arquivo ou pasta é renomeado | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Um item é movido ou copiado (incluindo transferências entre volumes) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Um item é duplicado/clonado | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Um item é restaurado da lixeira | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Um ou mais itens são excluídos permanentemente da lixeira | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Exemplo de Event Listener (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('Novo ativo de mídia enviado.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Comandos de Console / CLI (10 Comandos Integrados)

| Comando | Opções / Aliases | Descrição |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Verifica a versão do PHP, `upload_max_filesize`, `post_max_size`, cURL CA Bundle, GD/Imagick, EXIF, WebP, `ext-zip` e a configuração de todos os perfis e volumes. Adicione `--check-remote` para executar testes ao vivo de conectividade S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Limpa caches de diretórios e de cota para todos os perfis ou para um perfil/volume/caminho específico (`--volume`/`--path` exigem o argumento `profile`; nunca limpa chaves não relacionadas em `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Aliases: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Pré-gera miniaturas de imagens com antecedência para que os editores nunca precisem aguardar no primeiro carregamento. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Limpa miniaturas em cache. `--orphans` remove apenas miniaturas cujo arquivo de origem não existe mais (não pode ser combinado com `--volume`/`--path` e protege automaticamente diretórios compartilhados com `{user_id}` / `{Y}/{m}/{d}`). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Otimiza retroativamente imagens já existentes (limite de segurança/memória: máximo de `25 MB` por imagem). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Detecta arquivos duplicados usando hashes de conteúdo SHA-256 e informa o espaço em disco recuperável. |
| `file-manager:stats [profile]` | `--json` | Informa uso de armazenamento, contagem de arquivos/pastas, utilização da cota, miniaturas e estatísticas da lixeira. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(padrão `50`)*, `--dry-run` | Gera em lote textos `alt`, títulos, legendas e etiquetas ausentes utilizando IA. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = purgar tudo)*, `--dry-run` | Exclui permanentemente itens expirados da lixeira. Quando múltiplos perfis compartilham um mesmo diretório físico, respeita automaticamente o maior valor de `retention_days`. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Instala as 6 AI Agent Skills do bundle em `.agents/skills`, `.claude/skills`, `.cursor/skills` ou `.github/skills`. |

> [!NOTE]
> **Proteção de Placeholders Dinâmicos `{user_id}` e `{Y}/{m}/{d}` em Comandos CLI**
> Como não existe sessão de usuário autenticada em modo CLI, todos os comandos de varredura e limpeza (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **ignoram automaticamente** volumes que contenham `{user_id}` (evitando a criação acidental de uma pasta `guest/` vazia). Para volumes com placeholders de data `{Y}/{m}/{d}`, apenas o período da data atual é verificado e um aviso informativo é exibido.

### Exemplo de Cron para Limpeza Automática da Lixeira

```cron
# Excluir permanentemente itens expirados da lixeira todas as noites às 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Desempenho, Arquitetura de Cache e Symfony Profiler

- **TTL de Cache em 3 Níveis e Atualização Manual (`↻`):** O TTL da listagem de diretórios é resolvido na ordem: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` desativa o cache para aquele volume ou perfil). Clicar em **Atualizar (`↻`)** na barra de ferramentas ou pressionar `R` envia `?refresh=1` *(ignorar o cache exige a permissão `CLEAR_CACHE`)*.
- **Pool de Cache Dedicado e Fingerprinting de Perfil:** Aponte `defaults.cache.pool` para um pool Redis/APCu dedicado (se `<pool>.taggable` existir, ele é utilizado automaticamente). Sem cache marcável por tags, o `DirectoryCacheManager` utiliza chaves de contador de geração de 30 dias e nunca chama `$cachePool->clear()` no cache principal da aplicação. Quando dois perfis compartilham a mesma pasta física com regras diferentes (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), seus caches permanecem estritamente isolados via `buildProfilePolicyFingerprint()`.
- **Sincronização por Chave Canônica:** Se dois perfis apontam para o mesmo diretório físico, enviar ou modificar um arquivo em um deles invalida automaticamente o cache do outro perfil também.
- **`remember_last_dir` com Autorrecuperação:** Se o último diretório visitado pelo usuário foi excluído externamente (via FTP ou console S3), o gerenciador de arquivos sobe automaticamente até a pasta pai existente mais próxima ou até a raiz em vez de falhar.
- **HTTP 304 e Cache de Miniaturas Sensível ao Contexto (`ThumbnailController`):**
  - **Contexto Privado / Autenticado** (volume `private: true`, volume dinâmico `{user_id}`, perfil protegido por Voter, cabeçalho `Authorization` ou cookie de sessão presente): envia `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` para evitar vazamento em CDNs compartilhadas.
  - **Contexto Público:**
    - Com parâmetro de cache-busting `?v=<version>` → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 dias).
    - Sem `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 hora + `ETag` / `304 Not Modified`).
    - Quando `cache.enabled: false` no perfil → `Cache-Control: public, no-cache, must-revalidate`.
  - Toda resposta de miniatura também define `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` e `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** No ambiente `dev`, o painel **FileManager** no profiler exibe o perfil ativo, o volume, o driver de miniaturas e as estatísticas de acertos/erros de cache.

---

## 4. Localização Global (`493` Chaves), BCP-47 / POSIX / RTL e Idiomas Personalizados

O LiveUX FileManagerBundle vem pronto de fábrica com **12 idiomas de interface integrados** — **Inglês (`en` — fallback garantido), Turco (`tr`), Francês (`fr`), Alemão (`de`), Espanhol (`es`), Árabe (`ar`), Italiano (`it`), Português (`pt`), Holandês (`nl`), Polonês (`pl`), Japonês (`ja`) e Chinês Simplificado (`zh_CN`)** — com paridade total em todas as **493 chaves de tradução**.

- **Suporte a BCP-47 e POSIX `.UTF-8`:** O `MessageTranslator` resolve automaticamente strings de locale regionais e POSIX como `tr_TR.UTF-8`, `de_DE@euro` e `zh-Hans-CN` (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` gera códigos BCP-47 válidos para atributos HTML `lang`.
- **Detecção Automática de Escrita da Direita para a Esquerda (RTL):** **15 idiomas e sistemas de escrita RTL** — incluindo Árabe (`ar`), Persa (`fa`), Hebraico (`he`), Urdu (`ur`), Pashto (`ps`) e Curdo Central (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa` etc.) — são detectados automaticamente, alternando o layout do componente para `dir="rtl"`.
- **Lista de Permissão de Catálogos em Tempo de Compilação:** O `FileManagerBundle` aceita apenas locales que correspondem a catálogos de tradução reais, prevenindo inchaço de cache/OPcache (DoS) causado por parâmetros de locale forjados.
- **Adicionando um Novo Idioma (ex.: Sueco `sv`) ou Sobrescritas Parciais:**
  Basta criar `translations/LiveUXFileManagerBundle.sv.yaml` no seu projeto (ou sobrescrever chaves específicas em um idioma existente, como `LiveUXFileManagerBundle.pt.yaml`). Qualquer chave omitida cai automaticamente no fallback em inglês (`en`) — os usuários nunca verão chaves cruas como `ui.upload`. Copie o arquivo `translations/LiveUXFileManagerBundle.en.yaml` do bundle como modelo completo inicial.

---

## 5. Perguntas Frequentes e Solução de Problemas (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Confirme se `assets/app.js` inclui `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- Verifique se `defaults.auto_inject_dialog_host: true` está ativado (se você o desativou, inclua `DialogHost.html.twig` manualmente em `base.html.twig`).
- Execute `php bin/console cache:clear` e faça um recarregamento forçado no navegador (`Ctrl+Shift+R`).

### 2. Toda requisição POST retorna `403 Invalid or missing CSRF token`
- Garanta que `symfony/security-csrf` esteja instalado e que tanto `framework.csrf_protection: true` quanto `framework.session.enabled: true` estejam ativos.
- Se você fizer requisições `fetch()` personalizadas diretamente, inclua o cabeçalho `X-LiveUX-FM-CSRF`.

### 3. Estou recebendo `403 Access denied`
- Verifique o bloco `permissions` do seu perfil em `liveux_file_manager.yaml`. Lembre-se: mesmo definir uma permissão como `true` exige um usuário autenticado, e `permissions.view` é obrigatório para todas as outras ações.
- Se você implementou um Symfony Voter personalizado, confira `access_decision_manager.strategy` (ex.: `unanimous` vs. `affirmative`) em `config/packages/security.yaml`.

### 4. O envio de arquivos grandes falha (`upload_max_filesize`)
- Com `chunk_upload.enabled: true`, o tamanho de cada parte é automaticamente limitado a `80%` de `upload_max_filesize`/`post_max_size` do PHP (ex.: partes de `1.60 MB` em um limite de `2M` do PHP, até 2.000 partes).
- O tamanho total permitido por arquivo é controlado por `upload_max_size` em `liveux_file_manager.yaml` (ex.: defina `upload_max_size: '100M'` para vídeos de 100 MB).

### 5. Baixar um arquivo quebra a página (Symfony UX Turbo Drive)
- Todos os links de download integrados já incluem `data-turbo="false" download`. Se você sobrescrever templates Twig, certifique-se de que suas tags `<a>` de download personalizadas incluam `data-turbo="false"`.

### 6. As miniaturas não são geradas; apenas imagens originais são servidas
- Execute `php bin/console file-manager:doctor` e confirme que a linha `Thumbnail Driver` mostra `gd` ou `imagick` ativo.
- Verifique se `thumbnail.cache_dir` (`public/media-cache`) tem permissão de escrita para o usuário do servidor web.

### 7. `cURL error 60: SSL certificate problem` no Windows ao conectar ao S3 / Supabase
- Baixe um pacote `cacert.pem` atualizado e configure `curl.cainfo` e `openssl.cafile` no `php.ini`, ou informe `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` no volume S3.

### 8. Alterações no frontend não aparecem no navegador
- **Desenvolvimento:** Execute `php bin/console cache:clear` e faça um recarregamento forçado (`Ctrl+Shift+R`).
- **Produção (AssetMapper):** Execute `php bin/console asset-map:compile`.

---

[← Anterior: 07 — Armazenamento em Nuvem e Configuração Dinâmica](07-cloud-storage-and-dynamic-config.md) | [Índice Principal](README.md) | [Próximo: 09 — Integração Symfony AI →](09-symfony-ai-integration.md)
