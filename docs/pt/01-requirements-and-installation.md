# 01 — Requisitos, Dependências e Instalação

[← Índice da Documentação](README.md) | [Próximo: 02 — Configuração e Perfis →](02-configuration-and-profiles.md)

Este guia conduz você pela instalação do LiveUX FileManagerBundle do zero — explicando **cada etapa e por que ela é importante**, mesmo que este seja o seu primeiro pacote Symfony UX.

> [!NOTE]
> **Em português claro: o que este bundle faz?**  
> Ele adiciona um gerenciador de arquivos completo (semelhante ao Google Drive ou ao Explorador de Arquivos do Windows) à sua aplicação Symfony — seja um painel administrativo, uma loja de e-commerce ou um portal de clientes. Os usuários podem **enviar arquivos, organizar pastas, recortar imagens, pré-visualizar PDFs e vídeos e selecionar arquivos diretamente em formulários** com um único clique.  
> Ele não exige **nenhum framework CSS** (Bootstrap, Tailwind, etc.) nem **jQuery**, e com o Symfony AssetMapper funciona em 5 minutos sem Node.js ou npm.

---

## 1. Requisitos do Sistema

Antes de instalar, verifique se sua máquina local ou servidor atende a estes requisitos mínimos:

| Componente | Versão Mínima | Por Que É Necessário | Comando de Verificação |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` ou superior | Usa recursos do PHP 8.3/8.4 (classes `readonly`, constantes de classe tipadas). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` ou `8.x` | Totalmente testado e suportado no Symfony 6.4 LTS, 7.2, 7.3, 7.4 e 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Instalação de pacotes e autoloading PSR-4. | `composer --version` |
| **Navegador** | Navegador moderno atualizado | Top Layer HTML5 `<dialog>` nativo, ES Modules e propriedades customizadas CSS (Chrome, Edge, Firefox, Safari). | — |

---

## 2. Extensões PHP

As extensões PHP fornecem recursos nativos extras ao PHP, como inspeção de conteúdo de arquivos, análise de XML e processamento de imagens.

### Extensões PHP Obrigatórias
Habilitadas por padrão em quase todas as instalações modernas do PHP:

| Extensão | O Que Faz | Exemplo Prático |
| :--- | :--- | :--- |
| `ext-fileinfo` | Inspeciona o **conteúdo real do arquivo (magic bytes)**, não apenas a extensão do nome. | Se um invasor renomear `shell.php` para `photo.jpg` e tentar enviá-lo, a `ext-fileinfo` detecta e rejeita o arquivo imediatamente. |
| `ext-dom` e `ext-libxml` | Analisa com segurança árvores XML de arquivos `.svg` enviados (`SvgSanitizer`). | Remove tags `<script>` maliciosas ou payloads XXE ocultos dentro de um logotipo SVG enviado, preservando o gráfico limpo. |

*(Nota: a `ext-json` faz parte do núcleo do PHP desde o PHP 8.0 e não precisa de instalação separada.)*

### Extensões PHP Opcionais (Recomendadas)
Se alguma delas estiver ausente, o gerenciador de arquivos **continua funcionando** e aplica automaticamente um fallback seguro:

| Extensão | O Que Faz | Comportamento de Fallback Quando Ausente |
| :--- | :--- | :--- |
| `ext-gd` **ou** `ext-imagick` | Gera miniaturas WebP/JPEG rápidas e executa otimização automática de imagens no momento do envio. | Passa a exibir a imagem original ou o ícone do arquivo conforme `thumbnail.on_missing_driver`. |
| `ext-exif` | Lê metadados de orientação EXIF de smartphones ao usar `ext-gd`, girando automaticamente fotos deitadas (`auto_orient: true`). | As fotos são salvas na orientação original (não é necessária se `ext-imagick` estiver instalada). |
| `ext-zip` (`ZipArchive`) | Habilita downloads multi-arquivo em `.zip` **e** extração segura de arquivos compactados (`zip.allow_extract: true`). | Os botões **Baixar ZIP** e **Descompactar arquivo** são ocultados automaticamente. |

Verifique quais extensões estão ativas na sua máquina com um único comando:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Pacotes Composer Obrigatórios e Opcionais

Ao executar `composer require liveux/file-manager-bundle`, **todos os pacotes obrigatórios são instalados automaticamente**. Saber do que cada dependência cuida facilita muito a depuração:

### Pacotes Obrigatórios (Instalados Automaticamente)
| Pacote | Restrição de Versão | Finalidade |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Abstração unificada de sistemas de arquivos para discos locais, AWS S3, Cloudflare R2, Supabase, MinIO e SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Renderiza o gerenciador de arquivos via `{{ component('LiveUX:FileManager:Browser') }}` com SSR de 0 ms. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Alimenta o cache de diretórios no cliente, arrastar e soltar, menus de contexto, Lightbox e seletores de formulário. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Runtime principal do Symfony, roteamento e pools de cache (`cache.app`). |
| `symfony/config` e `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Validação de esquema de `liveux_file_manager.yaml` e injeção de serviços no container. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Dispara 9 eventos de domínio (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent`, etc.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Downloads seguros, streaming de vídeo HTTP 206 (`Range`), ETags e cabeçalhos de cache HTTP. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Templates Twig, helpers Twig de ícones/tradução e tema automático para FormType. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Campo de formulário `FileManagerPickerType` para seleção única ou galeria multi-arquivo. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Detecção de tipo MIME no servidor para arquivos enviados. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Translitera nomes de arquivos multilíngues (português, turco, francês, árabe, cirílico, etc.) em slugs limpos para SEO. |
| `symfony/security-core` e `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | RBAC de Voter com 17 permissões e proteção `X-LiveUX-FM-CSRF`. |
| `symfony/cache-contracts` e `symfony/translation-contracts` | `^3.5` | Contratos de cache de diretórios e interface multilíngue. |

### Pacotes Opcionais (Instale Apenas Quando Necessário)
| Pacote | Comando de Instalação | Quando Você Precisa Dele |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Entrega de JS e CSS sem etapa de build, dispensando Node.js e npm *(Recomendado)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Permissões baseadas em papéis (`ROLE_USER` / `ROLE_ADMIN`) e isolamento por usuário com `{user_id}`. |
| `symfony/translation` | `composer require symfony/translation` | Integra-se ao Translator do Symfony em todos os **12 idiomas de interface integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) ou locales personalizados da aplicação. |
| `symfony/console` e `symfony/yaml` | `composer require symfony/console symfony/yaml` | Comandos CLI (`file-manager:doctor`, `thumbs:warmup`, `trash:purge`, etc.) e arquivos de configuração YAML. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Conexão com AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO ou Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Implantações multi-servidor / com balanceamento de carga usando `defaults.metadata.driver: 'doctrine'` (veja o [Capítulo 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | `FileManagerField` integrado para painéis EasyAdmin 4/5 (veja o [Capítulo 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | Geração automática de texto alternativo, legenda e etiquetas com **✨ Sugerir com IA** (veja o [Capítulo 09](09-symfony-ai-integration.md)). |

---

## 4. Instalação Passo a Passo

Siga estas 7 etapas na ordem para colocar o gerenciador de arquivos no ar.

### Passo 1: Instale o Bundle

**Instalação padrão (via Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Repositório local por caminho (se estiver desenvolvendo dentro de `bundles/liveux-file-manager-bundle`):**  
Adicione uma entrada `repositories` ao seu `composer.json` raiz:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Em seguida, instale o pacote local:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Passo 2: Verifique `config/bundles.php`

Normalmente o Symfony Flex registra o bundle automaticamente. Caso contrário, confirme se estas entradas existem em `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Se estiver usando autenticação/papéis
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> O `FileManagerBundle` **registra automaticamente** seu namespace Twig (`@LiveUXFileManager`), o caminho do AssetMapper (`@liveux/file-manager-bundle`) e o tema do FormType (`@LiveUXFileManager/form/picker.html.twig`) na inicialização. Você não precisa editar `twig.yaml` nem `asset_mapper.yaml`.

---

### Passo 3: Importe as Rotas

Crie `config/routes/liveux_file_manager.yaml` para ativar os endpoints AJAX da API, download, miniaturas e envio do bundle:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Essa única importação registra **12 endpoints protegidos**:

| Método e Caminho | Finalidade |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Carrega o HTML do gerenciador de arquivos dentro de um diálogo Modal ou OffCanvas. |
| `GET /_liveux-file-manager/api/list` | Retorna uma listagem JSON dos arquivos e subpastas de um diretório. |
| `GET /_liveux-file-manager/api/tree` | Obtém a árvore completa de pastas para a barra lateral em uma única solicitação. |
| `GET /_liveux-file-manager/api/trash` | Lista os itens excluídos temporariamente na lixeira. |
| `GET /_liveux-file-manager/api/preview-text` | Retorna uma pré-visualização segura (limitada a 64 KB) de texto/código para `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, etc. |
| `POST /_liveux-file-manager/api/action` | Executa alterações: criar pasta, mover, copiar, renomear, excluir, restaurar/esvaziar lixeira, cores/ícones de pasta, metadados, enriquecimento com IA, importação de URL remota e extração de ZIP. |
| `POST /_liveux-file-manager/upload` | Processa envios de arquivos padrão e em partes (chunked). |
| `POST /_liveux-file-manager/image-edit` | Salva imagens recortadas, redimensionadas ou giradas a partir do Editor de Imagens Canvas integrado. |
| `GET /_liveux-file-manager/download` | Entrega downloads seguros, pré-visualizações inline de PDF/vídeo (com suporte a `Range`) e volumes com `private: true`. |
| `GET /_liveux-file-manager/share` | Entrega links de compartilhamento temporários assinados com HMAC. |
| `POST /_liveux-file-manager/zip` | Transmite os arquivos e pastas selecionados como um único arquivo `.zip`. |
| `GET /_liveux-file-manager/thumb` | Gera e entrega miniaturas WebP/JPEG/PNG com ETag e cabeçalhos de cache HTTP sensíveis ao contexto. |

---

### Passo 4: Crie `config/packages/liveux_file_manager.yaml`

Crie `config/packages/liveux_file_manager.yaml`. A configuração inicial abaixo lista explicitamente **todas as 17 permissões granulares**:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = herda automaticamente o locale ativo do Symfony (com fallback garantido para 'en')
                            # Ou fixe um locale específico como 'pt', 'en', 'tr', 'fr', 'de', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'Meus arquivos'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # A extração de ZIP vem desativada por padrão (também exige zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Nota Crítica de Segurança (`permissions`):**  
> Qualquer chave que você **omitir** em `permissions` (exceto `extract_zip`) assume `true` por padrão. Aqui, `true` significa **"qualquer usuário autenticado"** — NÃO visitantes anônimos. Se apenas `ROLE_ADMIN` puder excluir permanentemente da lixeira (`trash_purge`) ou criar links de compartilhamento (`share`), defina essas chaves explicitamente como `'ROLE_ADMIN'`.

> [!TIP]
> **Testando localmente ainda sem autenticação?**  
> Enquanto prototipa localmente sem um sistema de login, você pode definir temporariamente `profiles.default.security.voter_enabled: false`. **NUNCA deixe `voter_enabled: false` em produção** — consulte [06 — Segurança e Autorização](06-security-and-authorization.md).

> [!NOTE]
> **12 Idiomas de Interface Integrados e RTL Automático (`defaults.locale: null`):**  
> Deixar `locale: null` faz com que o gerenciador de arquivos acompanhe automaticamente o locale ativo da requisição no Symfony em todos os **12 idiomas integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), habilite automaticamente **RTL (`dir="rtl"`)** para árabe e outros idiomas RTL (persa, hebraico, urdu, etc.), normalize locales BCP-47 e POSIX (`pt_BR.UTF-8`, `de_DE.UTF-8`, `zh-Hans-CN`) e faça fallback seguro para **inglês (`en`)** em idiomas não suportados.

---

### Passo 5: Conecte CSS e JavaScript (Zero Configuração!)

**Não sabe se o seu projeto usa AssetMapper ou Webpack Encore?**  
Olhe na raiz do projeto:
- Se existir `importmap.php` → siga a **Opção A (AssetMapper)**.
- Se existir `webpack.config.js` → siga a **Opção B (Webpack Encore)**.

> [!TIP]
> **Suporte a `autoimport` sem Configuração Manual:**  
> O LiveUX FileManagerBundle declara **`autoimport`** no seu `assets/package.json` para `@liveux/file-manager-bundle/styles/core.css` e o `dialog_controller.js` carregado de forma eager (que carrega `file_manager_api.js`). Assim que `assets/controllers.json` estiver habilitado, **você não precisa de nenhuma instrução `import` manual em `assets/app.js`!**

#### Opção A: Symfony AssetMapper (Recomendado — Sem Node.js / npm)

1. Confirme se os controladores do bundle estão habilitados em `assets/controllers.json` (o Symfony Flex adiciona isso automaticamente; caso contrário, adicione):
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Projetos Symfony 6.4 / 7.2+ / 8.x já chamam `startStimulusApp()` dentro de `assets/bootstrap.js` ou `assets/stimulus_bootstrap.js`, que lê `controllers.json` automaticamente:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) ou assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Certifique-se de que seu layout base (`templates/base.html.twig`) inclui `importmap('app')`:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Opcional — ao publicar em produção)* Compile os ativos estáticos:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Ativos de Produção Pré-Minificados (`assets/dist/`):**  
> Como o AssetMapper não minifica JS/CSS por padrão, o LiveUX FileManagerBundle inclui módulos JavaScript e `core.css` pré-minificados com `esbuild` em `assets/dist/` (`747 KB → 410 KB` minificados; `~93 KB` em Gzip ou **`~77 KB`** em Brotli). O `FileManagerBundle` mapeia automaticamente `assets/dist/` no AssetMapper, portanto **nem sua máquina de desenvolvimento nem o servidor de produção precisam de Node.js ou npm**.  
> No Symfony 7.3+ / 8.x, você também pode habilitar `precompress: true` em `config/packages/asset_mapper.yaml` para gerar arquivos `.br` e `.gz` automaticamente durante `asset-map:compile`.

#### Opção B: Webpack Encore

1. Em projetos Webpack Encore (incluindo Symfony 8 + StimulusBundle 3.x), certifique-se de que `@symfony/stimulus-bridge` esteja instalado:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Verifique os 3 controladores Stimulus e o bloco `autoimport` em `assets/controllers.json`:
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. Verifique se `startStimulusApp` está configurado em `assets/bootstrap.js` (`assets/app.js` o importa — não é necessário nenhum import manual de CSS/JS):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Compile seus ativos:
```bash
npm run build
# Ou observe alterações continuamente durante o desenvolvimento: npm run watch
```

---

### Passo 6: `DialogHost` (Injeção Automática — Sem Marcação Extra!)

Abrir modais, gavetas OffCanvas, `FileManagerPickerType` ou seletores do TinyMCE/CKEditor/Tiptap a partir de qualquer página exige um contêiner `<dialog>` no DOM.

O `AutoInjectDialogHostSubscriber` do bundle injeta automaticamente esse host logo antes de `</body>` em respostas HTML completas (`defaults.auto_inject_dialog_host: true`) — portanto você não precisa editar `base.html.twig`.

- **Detecção Inteligente:** Nunca altera respostas JSON, AJAX, Turbo Frame, redirecionamentos ou downloads binários, e ignora a injeção se um `DialogHost` manual já estiver presente.
- **0 ms de Sobrecarga no Carregamento da Página:** Não consulta diretórios nem árvores de pastas no carregamento inicial; os dados só são buscados quando o usuário abre o diálogo.
- **Prefere Posicionamento Manual em Páginas Específicas?** Defina `defaults.auto_inject_dialog_host: false` e inclua o trecho logo antes de `</body>` nos layouts desejados:

```twig
{# Opcional — necessário apenas quando auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Passo 7: Verifique sua Instalação (`file-manager:doctor`)

Limpe o cache e execute o comando de diagnóstico integrado:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Se você configurou volumes na nuvem (S3, R2, Supabase, MinIO), adicione `--check-remote` para testar a conectividade em tempo real:
```bash
php bin/console file-manager:doctor --check-remote
```

Exemplo de saída saudável (9 verificações de ambiente + tabela de status de perfis/volumes):
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Usuários Windows (`cURL error 60`):** Se `cURL CA Bundle` exibir um aviso, aponte `curl.cainfo` e `openssl.cafile` no `php.ini` para um arquivo `cacert.pem` atualizado, ou configure `driver_options.ca_bundle` no seu volume S3.
>
> **Permissões de Arquivo no Linux:** Garanta que `public/uploads`, `public/media-cache` e `var/file-manager` tenham permissão de escrita para o usuário do servidor web (`www-data`, `nginx`, etc.).
>
> **Usando um Assistente de Código com IA (Antigravity, Claude Code, Cursor, Copilot)?**  
> Ensine ao seu assistente todas as convenções e receitas do bundle com um único comando (veja o [Capítulo 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Crie sua Primeira Página Funcional

Vamos criar um Controller Symfony simples e um template Twig para validar tudo de ponta a ponta.

### 1) Controller (`src/Controller/MediaController.php`)
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Template Twig (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Biblioteca de Mídia</h1>

    {# Opção 1: Gerenciador de arquivos inline #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Opção 2: Abrir um Modal a partir de qualquer botão e capturar os arquivos selecionados #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Escolher arquivos no Modal</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // O usuário fechou o diálogo sem selecionar nada
            }

            document.getElementById('selection-result').textContent =
                'Selecionados: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Abra `/admin/media` no navegador. Parabéns — seu gerenciador de arquivos está no ar!

---

## 6. Próximos Passos

- Configure perfis personalizados, limites de envio e regras de imagem → **[02 — Configuração e Perfis](02-configuration-and-profiles.md)**
- Adicione um seletor de imagem ou galeria aos Symfony Forms → **[05 — FormType e Integrações com Editores](05-formtype-and-editor-integrations.md)**
- Endureça o RBAC e a segurança antes de ir para produção → **[06 — Segurança e Autorização](06-security-and-authorization.md)**

---

[← Índice da Documentação](README.md) | [Próximo: 02 — Configuração e Perfis →](02-configuration-and-profiles.md)
