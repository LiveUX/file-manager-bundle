# 06 — Segurança e Autorização (RBAC e Defesa em Profundidade)

[← Anterior: 05 — FormType e Integrações com Editores](05-formtype-and-editor-integrations.md) | [Índice](README.md) | [Próximo: 07 — Armazenamento em Nuvem e Configuração Dinâmica →](07-cloud-storage-and-dynamic-config.md)

Gerenciadores de arquivos representam uma das superfícies de ataque de maior risco em qualquer aplicação web. O LiveUX FileManagerBundle foi projetado em torno de uma arquitetura de **defesa em profundidade (defense-in-depth)**, com proteções de segurança em múltiplas camadas habilitadas por padrão.

> **Visão Geral: Como Blindar a Segurança em 3 Passos**  
> 1. **Proteja as Rotas (`security.yaml`):** Bloqueie o acesso de visitantes não autenticados aos endpoints do gerenciador de arquivos.  
> 2. **Defina Permissões por Papel (`permissions`):** Declare explicitamente em cada perfil quem pode visualizar (`view`), enviar (`upload`) e excluir (`delete`).  
> 3. **Adicione um `prefix` de Rota em Apps com Múltiplos Firewalls:** Se sua aplicação usa firewalls separados para o painel administrativo (`/admin`) e usuários públicos (`/`), monte as rotas do bundle sob `prefix: /admin` em `config/routes/liveux_file_manager.yaml` para que a sessão administrativa seja resolvida corretamente e membros comuns não consigam acessar arquivos administrativos.

---

## 1. Protegendo as Rotas no `security.yaml` (Primeiro Passo)

Em produção, sempre proteja todos os endpoints em `/_liveux-file-manager` atrás do firewall do Symfony:

```yaml
# config/packages/security.yaml (Aplicações com um único firewall)
security:
    access_control:
        # Deixe público apenas se você usa links de compartilhamento assinados para destinatários externos:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Se você tem dois firewalls separados — por exemplo, uma área administrativa (`/admin`) e membros comuns do site (`/`):**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Autorização Baseada em Perfil (`permissions` e `FileManagerVoter`)

Cada perfil permite definir regras de acesso independentes para **17 operações distintas**.

### O Que Significam os Valores de Permissão?
- **`true`:** **Apenas usuários autenticados (`$token->getUser() !== null`)!** *(NUNCA concede acesso a visitantes anônimos.)*
- **`false`:** Ninguém (a operação fica completamente desabilitada).
- **`'ROLE_ADMIN'`:** Apenas usuários que possuem esse papel específico.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Usuários que possuem qualquer um dos papéis listados.

### 4 Regras de Ouro da Autorização (Aplicadas Automaticamente)
1. **`permissions.view` É o Portão Principal:** Se um usuário não tiver a permissão `view`, `upload`, `delete`, `download` e todas as demais operações são negadas automaticamente.
2. **Fail-Closed (`FileManagerAuthorizer`):** Quando `security.voter_enabled: true` está configurado, mas `AuthorizationCheckerInterface` (ou seja, `symfony/security-bundle`) não está instalado, o bundle **nega o acesso com segurança (`AccessDeniedException`)** em vez de permitir o acesso por engano.
3. **Verificação da Cadeia de Diretórios Ancestrais (`isPathAndAncestorsGranted`):** Quando um usuário acessa `confidential/2026/invoice.pdf`, seus Voters personalizados são avaliados automaticamente não apenas para `confidential/2026/invoice.pdf`, mas também para as pastas pai **`confidential`** e **`confidential/2026`** — impedindo que a adivinhação de URLs burle diretórios pai restritos.
4. **Dupla Trava para Extração de ZIP (`zip.allow_extract`):** Enquanto `zip.allow_extract: false`, a extração de arquivos compactados é negada no servidor independentemente de `permissions.extract_zip`.

### Referência Completa dos 17 Atributos de Permissão do Voter

| Chave YAML | Constante do Voter (`FileManagerPermissions::*`) | Operações Protegidas |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Listagem de diretórios, árvore de pastas, miniaturas, pré-visualizações inline, diálogos e **pré-requisito para todas as outras permissões** |
| `upload` | `FILE_MANAGER_UPLOAD` | Envio de arquivos, uploads em partes (chunked), importação via URL remota, salvamentos do editor de imagens |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Criação de novas pastas |
| `rename` | `FILE_MANAGER_RENAME` | Renomeação de arquivos e pastas |
| `clone` | `FILE_MANAGER_CLONE` | Clonagem/duplicação de arquivos e pastas |
| `move` | `FILE_MANAGER_MOVE` | Movimentação de itens (arrastar e soltar e modal Mover — tanto a origem quanto o destino são verificados) |
| `copy` | `FILE_MANAGER_COPY` | Cópia de itens (tanto a origem quanto o destino são verificados) |
| `delete` | `FILE_MANAGER_DELETE` | Exclusão de itens (lixeira / exclusão permanente) e verificações `checkUsage` |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Restauração de itens da lixeira |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Exclusão permanente de itens ou esvaziamento da lixeira |
| `download` | `FILE_MANAGER_DOWNLOAD` | Download de arquivo único, streaming de PDF/vídeo e cópia de URLs |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Download em pacote ZIP de múltiplos itens (verificado individualmente para cada caminho selecionado) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Extração segura de ZIP (também exige `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Geração de links de compartilhamento temporários assinados por HMAC (também exige `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Limpeza de caches de diretório/perfil/miniaturas, além de bypasses `?refresh=1` e `?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Atualização de cores/ícones/fixações de pastas e texto alternativo, títulos, etiquetas e pontos focais de arquivos |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | Sugestões de metadados com IA (exige `view` + `edit_metadata` + `ai.enabled: true`) |

### Exemplo de Configuração Corporativa de Perfil

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **Interface Consciente de Permissões:** Os botões de ação para os quais o usuário não tem autorização (por exemplo, o botão Excluir para um `ROLE_EDITOR` quando `delete: 'ROLE_ADMIN'`) são ocultados automaticamente na interface **e** bloqueados rigorosamente com `HTTP 403 Forbidden` caso sejam chamados diretamente via API.

---

## 3. Escrevendo um Symfony Voter Personalizado (Regras Granulares por Pasta e Usuário)

Quando papéis em YAML não forem suficientes — por exemplo, *"impedir que usuários excluam a pasta `locked/` ou movam arquivos para dentro de `locked/`"* — escreva o seu próprio Symfony Voter.

> [!WARNING]
> **Armadilha Mais Comum para Iniciantes: `affirmative` vs. `unanimous`!**  
> A estratégia padrão de `access_decision_manager.strategy` no Symfony é **`affirmative`** (um único Voter retornando `true` concede acesso). Isso significa que, se o `FileManagerVoter` integrado do bundle retornar `true` para um usuário `ROLE_ADMIN`, o voto **`false` (negar)** do seu Voter personalizado será **SOBRESCRITO**, e o usuário ainda conseguirá excluir o arquivo!  
>  
> Para garantir que as regras de **negação** do seu Voter personalizado sejam realmente aplicadas, defina a estratégia como **`unanimous`** em `config/packages/security.yaml` (todos os Voters que votarem precisam aprovar; um único `false` bloqueia a ação):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(O bundle detecta automaticamente Voters personalizados da aplicação via `DetectExternalVotersPass` e executa filtragem por item na árvore de pastas e nos contadores de arquivos para manter tanto a privacidade quanto a precisão.)*

### Propriedades de `FileManagerSubject`

O `$subject` passado para o método `voteOnAttribute()` do seu Voter personalizado expõe 4 propriedades readonly:

| Propriedade | Tipo | Descrição | Exemplo |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Configuração do perfil ativo (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Caminho relativo do arquivo/pasta de origem a partir da raiz do volume | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Nome do volume ativo | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Caminho de destino** para operações `MOVE`, `COPY`, `RENAME` ou `CLONE` (`null` nas demais) | `'locked/new-name.pdf'` |

### Exemplo de Voter Personalizado (Bloqueando Exclusões, Renomeações e Movimentações na Pasta `locked/`)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) Bloqueia excluir, mover ou renomear "locked" ou qualquer item dentro dela
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Também bloqueia mover ou renomear arquivos PARA DENTRO da pasta "locked"
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 Camadas Integradas de Defesa em Profundidade (Protegido Contra Ataques Reais)

| # | Vetor de Ataque | Proteção do Bundle |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Toda requisição `POST` de escrita, exclusão, upload, ZIP e editor de imagens valida o cabeçalho `X-LiveUX-FM-CSRF` **antes** de processar qualquer entrada (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode e Truques de SO** | O `PathNormalizer` bloqueia `../`, `..\`, bytes nulos (`\0`), caracteres de controle, `C:\`, stream wrappers `phar://`/`php://`, pontos/espaços no final, caracteres Unicode de largura zero/bidi, pontos finais CJK (`。`), NTFS Alternate Data Streams (`file.txt:stream`) e nomes de dispositivos Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), impondo no máximo **32 níveis de profundidade**, **255 bytes/segmento** e **1024 caracteres UTF-8 / 3072 bytes** de comprimento total de caminho. |
| 3 | **Arquivos Executáveis, Poliglotas e Extensão Dupla** | O `FileSecurityGuard` aplica **69 extensões bloqueadas** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, etc.), **47 tipos MIME bloqueados**, verificação de extensão dupla (`shell.php.jpg`) e validação de **poliglotas por magic bytes** — aplicada rigorosamente em uploads, renomeações (mesmo com `allow_extension_change: true`) e downloads. |
| 4 | **Acesso Direto a Pastas Ocultas e Lixeira** | Caminhos em `.trash`, `.env`, `.git`, `.htaccess` e `exclude_patterns` são rejeitados em todos os endpoints; o `ZipDownloadController` executa uma checagem individual do Voter `DOWNLOAD_ZIP` para **cada caminho selecionado**. |
| 5 | **SVG XSS, XXE e Bombas DoS de `<use>`** | O `SvgSanitizer` rejeita `<!DOCTYPE`/`<!ENTITY`, impõe uma **lista estrita de 55 tags permitidas**, limita o tamanho do SVG a **5 MB**, filtra sintaxe CSS (`@import`, `expression()`, `url(javascript:)`), remove `<script>`/`<foreignObject>`/`on*`/`javascript:` e limita ataques DoS por expansão de `<use>` (máx. `100` `<use>`, profundidade `4`, `1000` nós expandidos, `5000` passos de análise, `8` IDs duplicados). |
| 6 | **SSRF e DNS Rebinding (Importação por URL Remota)** | O `RemoteUrlImporter` vem desabilitado por padrão (`allow_remote_url_import: false`). Quando ativado, valida IPs via `inet_pton()` para bloquear loopback, faixas privadas, `0.0.0.0`, CGNAT `100.64.0.0/10`, metadados de nuvem (`169.254.169.254`), IPv6 mapeado para IPv4 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 e bypasses de IP via UTS-46 IDNA / largura total / octal / hexadecimal, enquanto o **fixamento de IP via `CURLOPT_RESOLVE`** elimina ataques de DNS rebinding. |
| 7 | **RCE Poliglota no Editor de Imagens** | O `ImageEditorController` restringe `overwrite` a arquivos raster seguros já existentes (`jpg/jpeg/png/webp`) e impõe `upload_max_size`, `upload_allow`/`upload_deny` do perfil, checagens do Asset Usage Guard e validação real via `getimagesizefromstring()`. |
| 8 | **Bombas de Descompressão (Pixels) e ImageTragick** | `GdThumbnailDriver` e `ImagickThumbnailDriver` inspecionam as dimensões via `getimagesizefromstring()` antes de decodificar em memória (impondo no máximo `8192×8192` e `40 MP`) e aplicam limites estritos de memória, tempo e threads no Imagick. |
| 9 | **Fuga por Symlink e Confinamento** | O `ConfinedLocalFilesystemAdapter` verifica `is_link()` + confinamento na raiz via `realpath()` em cada segmento de caminho para toda leitura, escrita, movimentação, cópia e exclusão local; exclusões/movimentações recursivas de subárvores contendo symlinks são rejeitadas e listagens de diretórios impõem `SKIP_LINKS`. |
| 10 | **Prevenção de Colisão Multi-Tenant (`{user_id}`)** | O `DynamicPathResolver` isola diretórios de usuários com sufixos SHA-256 distintos `<slug>--h-<16-hex>` para e-mails, maiúsculas/minúsculas mistas, Unicode, nomes reservados (`guest`, `user`) e nomes de dispositivos Windows — tornando impossíveis colisões de diretório entre dois usuários. |
| 11 | **Isolamento de Cache em Downloads, Miniaturas e CDN** | `DownloadController`, `ThumbnailController` e `ZipDownloadController` emitem `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` e `Content-Security-Policy: default-src 'none'; sandbox`; aplicam `Cache-Control: private` + `Vary: Cookie, Authorization` em contextos privados/Voter/sessão/`{user_id}`; exigem `CLEAR_CACHE` para `?refresh=1` e `?fallback=1`; e forçam `Content-Disposition: attachment` para HTML/JS/SVG/XML. |
| 12 | **Proteção de POST no FormType e Origem no Twig** | O `FileManagerPickerType` rejeita payloads POST adulterados no servidor (protocolos XSS, `../`, `.trash/`, JSON > 256 KB, > 500 itens, UTF-8 inválido, excesso de `max_files`, arrays HPP); `FileManagerTwigExtension` e `isSafeUrl()` no frontend impedem falsificação de origem com URLs externas/perigosas. |
| 13 | **Diretórios Arbitrários de Tema/Ícone e DOM XSS** | Em tempo de execução, `dialog?theme=` aceita apenas os 9 temas integrados e `dialog?iconSet=` aceita apenas os 6 conjuntos de ícones integrados; `sanitizeHexColor()` impede injeção de cores; diretórios temporários de chunks são isolados por usuário (`0750`). |
| 14 | **DoS, Injeção de Logs e Isolamento de Cache** | A exclusão em lote é limitada a 200 itens, uploads em partes a 2000 chunks e cópias recursivas de pastas a 5000 itens; o `AuditLogListener` remove payloads de injeção de log (`\r`/`\n`/ANSI/caracteres de controle); o `DirectoryCacheManager` nunca limpa o pool `cache.app` da aplicação hospedeira (nunca chama `clear()`). |

---

## 5. Blindagem do Servidor Web (Apache e Nginx)

Como camada extra de defesa, sempre desabilite a execução de PHP e scripts dentro de diretórios públicos de upload como `public/uploads` no nível do servidor web:

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. Links de Compartilhamento Assinados e Asset Usage Guard

- **Links de Compartilhamento Assinados com Expiração (`ShareController`):** Quando `share.enabled: true`, URLs temporárias são assinadas com o `UriSigner` do Symfony (HMAC + timestamp `expires`). Definir `share.enabled: false` em um perfil revoga imediatamente todos os links existentes com `403 Forbidden`, links expirados retornam `410 Gone` e todas as respostas incluem `Cache-Control: private, no-store` além de CSP rigoroso.
- **Asset Usage Guard (`AssetUsageCheckerInterface`):** Impede excluir, renomear, mover ou sobrescrever arquivos que estejam ativamente referenciados no seu banco de dados (veja [10 — Asset Usage Guard](10-asset-usage-guard.md)).

---

## 7. Log de Auditoria — Configuração em Produção e Exemplos

O `AuditLogListener` registra todos os 9 eventos de modificação de arquivos/pastas com contexto PSR-3 estruturado (`action`, `profile`, `volume`, `path`, `user`, `ip`, além de metadados específicos do evento) e remove automaticamente caracteres de controle/CRLF/BiDi para prevenir injeção de log.

### Passo 1: Habilite o Log de Auditoria (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Habilite ou sobrescreva por perfil
        public_temp:
            security:
                audit_log: false
```

### Passo 2: Direcione os Registros de Auditoria para um Log JSON Dedicado com Rotação (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # Retenção e rotação de 90 dias
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### Exemplo de Saída JSON Estruturada

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### Todos os 9 Eventos Auditados do Ciclo de Vida

| Evento Symfony | Chave `action` | Campos de Contexto Extras |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### Passo 3 (Opcional): Persistindo Logs de Auditoria em uma Tabela Doctrine

Para expor uma trilha de auditoria pesquisável no seu painel administrativo, inscreva-se nos mesmos eventos Symfony:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> Em aplicações de alto tráfego, despache uma mensagem assíncrona do Symfony **Messenger** em vez de chamar `$this->em->flush()` diretamente, mantendo as operações de arquivo não bloqueantes.

---

[← Anterior: 05 — FormType e Integrações com Editores](05-formtype-and-editor-integrations.md) | [Índice](README.md) | [Próximo: 07 — Armazenamento em Nuvem e Configuração Dinâmica →](07-cloud-storage-and-dynamic-config.md)
