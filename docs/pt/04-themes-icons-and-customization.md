# 04 — Temas, Modo Escuro, Personalização de Pastas, Conjuntos de Ícones e Design Personalizado

[← Anterior: 03 — Modos de Uso e API JS](03-usage-modes-and-js-api.md) | [Índice](README.md) | [Próximo: 05 — FormType e Integrações com Editores →](05-formtype-and-editor-integrations.md)

O LiveUX FileManagerBundle foi projetado com **100% zero dependências de frameworks CSS**. Quer seu projeto use **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** ou **nenhum framework CSS**, ele funciona imediatamente sem etapas extras de build (sem necessidade de configurar `@source` ou purge no Tailwind).

> **Visão Geral: O Que Você Vai Aprender Neste Guia**  
> 1. **9 Temas Integrados e Modo Escuro:** Combine o design visual e o esquema de cores da sua aplicação com uma única opção YAML.  
> 2. **Cores e Ícones (Badges) de Pastas:** Personalize pastas pela interface ou programaticamente via PHP/banco de dados usando mais de 18 cores e 14 badges integrados (além de badges SVG personalizados ilimitados).  
> 3. **Criando Seu Próprio Conjunto de Ícones:** Instruções passo a passo para montar seu pacote de ícones cobrindo 9 ícones de arquivo, 49 ícones de interface e badges de pasta personalizados ilimitados.  
> 4. **Criando um Tema Personalizado (3 Níveis de Liberdade):** Identidade visual da marca com variáveis CSS puras, sobrescrita parcial de Twig via `{% extends %}` ou um layout 100% headless do zero.

---

## 1. 9 Temas Integrados (Qual Escolher?)

Os temas podem ser configurados por meio de `defaults.theme`, `profiles.<name>.ui.theme`, da prop `theme` do TwigComponent ou da opção `theme` do `FileManagerPickerType`. **Todos os 9 temas são verificados conforme os padrões de contraste WCAG 2.1 AA tanto no modo claro quanto no modo escuro.**

| Chave do Tema | Ideal Para | Resumo do Design |
| :--- | :--- | :--- |
| `bootstrap5` *(padrão)* | Bootstrap 5 e painéis administrativos clássicos do Symfony | Azul clássico (`#0d6efd`), raios `10px / 8px / 6px`, bordas neutras e limpas |
| `tailwind` | Aplicações web modernas construídas com Tailwind CSS v3/v4 | Destaque índigo (`#6366f1`), raios suaves `14px / 12px / 8px`, paleta Slate |
| `shadcn` | Dashboards SaaS minimalistas e monocromáticos em preto e branco | Superfícies monocromáticas Zinc (`#18181b` / `#fafafa` no modo escuro), bordas precisas `8px / 6px` |
| `flowbite` | Painéis administrativos com Flowbite 4 + Tailwind CSS | Azul Flowbite (`#1a56db`) e superfícies Gray-50/800/900, controles de `8px` |
| `daisyui` | Projetos construídos com DaisyUI 5 | **Herda automaticamente** `--color-primary`, `--color-base-100/200/300` e `--radius-box` sempre que o DaisyUI estiver carregado na página |
| `tabler` | Tabler.io ou backends corporativos com EasyAdmin | Base slate corporativa, destaque azul `#066fd1`, cantos nítidos `4px / 6px` |
| `preline` | Aplicações com Preline UI | Superfícies puramente neutras (`#fafafa` / `#171717`) com destaque azul vibrante (`#2563eb`) |
| `material` | Aplicações Google Material Design 3 | Superfícies tonais em roxo (`#6750a4` / `#f3edf7`) e botões em formato pílula totalmente arredondados |
| `unstyled` | Estilizar o gerenciador de arquivos inteiramente com seu próprio CSS | Herda a fonte da aplicação hospedeira e superfícies transparentes, mantendo contraste opaco em `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` para popovers e lightboxes |

| Modo Escuro (`bootstrap5` + Dark) | Integração com Tema DaisyUI 5 (`daisyui`) |
| :---: | :---: |
| ![Gerenciador de arquivos no modo escuro](../images/browser-dark.png) | ![Demonstração do tema DaisyUI 5](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Regra de Segurança (YAML vs. `theme` em Tempo de Execução):**  
> - Em `liveux_file_manager.yaml`, você pode configurar qualquer um dos 9 temas integrados, o nome do seu próprio tema CSS personalizado (por exemplo, `'corporate'`, respeitando `/^[a-zA-Z0-9_-]{1,40}$/`) ou o caminho de um template Twig personalizado (por exemplo, `'file_manager/custom.html.twig'` ou `'@App/file_manager'`).  
> - Por motivos de segurança, valores dinâmicos de `theme` vindos do navegador, de parâmetros de query na URL ou de `window.LiveUXFileManager.open({ theme: ... })` **aceitam apenas as 9 chaves de temas integrados** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Modo Escuro, RTL e Movimento Reduzido

Todos os 9 temas (incluindo `unstyled`) suportam **Modo Escuro automático e manual**:

### A) Detecção Automática da Aplicação Hospedeira (`color_scheme: 'auto'`)
Sempre que a sua página (`<html>` ou `<body>`) tiver qualquer um dos atributos ou classes abaixo ativos, o gerenciador de arquivos, o modal `<dialog>` e os campos `FileManagerPickerType` alternam imediatamente para o modo escuro:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- Classe `.dark` *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / temas personalizados)*

### B) Esquema de Cores Padrão ou por Perfil via YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (segue a página) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Inicia apenas este perfil no modo escuro
```

### C) Preferência do Usuário Final (Menu Configurações: `Auto / Claro / Escuro`)
Os usuários podem alternar entre **Auto / ☀ Claro / 🌙 Escuro** com um único clique no popover **⚙ Configurações** da barra superior. A preferência é persistida no `localStorage` do navegador (`liveux_fm_color_scheme`).

### D) Suporte Global a RTL e `prefers-reduced-motion`
- `dir="rtl"` é aplicado automaticamente para 15 idiomas da direita para a esquerda, incluindo árabe (`ar`), persa (`fa`), hebraico (`he`) e urdu (`ur`). Todos os espaçamentos em `core.css` utilizam propriedades lógicas de CSS (`inset-inline`, `padding-inline`, `border-inline-end`).
- As animações são minimizadas automaticamente para usuários com *"Reduzir movimento"* (`prefers-reduced-motion: reduce`) ativado no sistema operacional.

---

## 3. Cores de Pastas e Atribuição de Ícones/Badges (Passo a Passo)

![Modal de personalização de paleta de cores e ícones de pasta](../images/folder-customize.png)

Você pode atribuir uma **cor** personalizada, um **ícone (badge)** e o status de **fixada (⭐ Fixar no Acesso rápido)** a qualquer pasta. Esses recursos ficam ativos quando `defaults.metadata.enabled: true` e o usuário possui a permissão `permissions.edit_metadata`.

### Método 1: Pela Interface (Para Usuários Finais)
1. **Clique com o botão direito** em qualquer pasta e selecione **Cor da pasta** (ou pressione **`I`** com a pasta selecionada, ou clique em **Cor da pasta** no painel Inspetor à direita).
2. No modal de personalização, configure três opções com pré-visualização em tempo real:
   - **Paleta de 18 Cores Predefinidas:** Azul (`#2563eb`), Celeste (`#0284c7`), Ciano (`#0891b2`), Verde-azulado (`#0d9488`), Esmeralda (`#059669`), Verde (`#16a34a`), Lima (`#65a30d`), Âmbar (`#d97706`), Laranja (`#ea580c`), Vermelho (`#dc2626`), Rosa-rubro (`#e11d48`), Rosa (`#db2777`), Fúcsia (`#c026d3`), Roxo (`#9333ea`), Violeta (`#7c3aed`), Índigo (`#4f46e5`), Ardósia (`#475569`), Carvão (`#1e293b`).
   - **Seletor de Cor Personalizado + Campo HEX:** Escolha qualquer cor da sua marca pelo seletor nativo ou digite diretamente um código hexadecimal `#RRGGBB`. Clique em **Padrão** para limpar o estilo personalizado.
   - **Grade de Ícones (Badges) de Pasta:** Exibe todos os **14 badges integrados** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`), além de quaisquer arquivos SVG personalizados adicionados ao diretório `badges/` do seu conjunto de ícones.
3. Clique em **Salvar** — a pasta é atualizada instantaneamente na visualização em Grade/Lista e na árvore de pastas à esquerda.

### Método 2: Programaticamente via PHP (ex.: ao Criar uma Nova Pasta de Cliente)
Usando `FolderMetadataStorageInterface` e `PathNormalizer::buildCanonicalKey()`, você pode atribuir cores, badges e fixações diretamente no código PHP (o mesmo código funciona de forma transparente tanto com o driver `json_file` quanto com `doctrine`):

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // Monta a chave canônica (ex.: "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Verde-azulado (#RRGGBB)
            icon: 'lock',       // Um dos 14 badges integrados ou custom badges/invoice.svg -> 'invoice'
            pinned: true,       // Adiciona à seção ⭐ Acesso rápido na barra lateral
        ));
    }
}
```

### Método 3: Diretamente em `metadata.json` (Ao Usar `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Conjuntos de Ícones e Como Criar o Seu do Zero

**Todos os ícones** do bundle — tipos de arquivo, pastas, barra superior, menu de contexto, indicadores de discos na nuvem, painel inspetor e badges de pasta — são servidos por um único `IconSetRegistry` unificado. Os 6 conjuntos de ícones integrados são armazenados em cache PSR-6 por 24 horas (`liveux_fm_iconset_*`).

### 6 Conjuntos de Ícones Integrados (`profiles.<name>.ui.icon_set`)
| Conjunto de Ícones | Estilo Visual | Cor Padrão da Pasta (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(padrão)* | Ícones vetoriais planos e nítidos | `#2563eb` |
| `windows11` | Pastas amarelas quentes em dois tons no estilo Windows 11 e ícones modernos de documentos | `#f59e0b` |
| `macos` | Pastas azul-suave inspiradas no Finder do macOS | `#3b82f6` |
| `linux` | Estética limpa de código aberto estilo GNOME / Adwaita | `#3584e4` |
| `minimal` | Ícones minimalistas de traço fino (contorno) | `#475569` |
| `vscode` | Ícones precisos voltados para desenvolvedores no estilo VS Code | `#dcb67a` |

### Passo a Passo: Criando Seu Próprio Conjunto de Ícones Personalizado

#### Passo 1: Crie um Diretório de Ícones no Seu Projeto
Por exemplo, crie `assets/fm-icons/`. **Você não precisa copiar os ícones que não deseja alterar** — qualquer ícone ausente usa automaticamente como fallback os conjuntos integrados `classic` / `_ui` / `_folder_badges`!

```text
assets/fm-icons/
├── meta.json               # Cor padrão da pasta: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Opcional) Ícone principal de pasta personalizado
├── pdf.svg                 # (Opcional) Sobrescreve apenas o ícone de PDF
├── ui/                     # (Opcional) Sobrescreve ou adiciona ícones da barra superior, menu de contexto e volumes
│   ├── upload.svg          # Sobrescreve o ícone de Enviar
│   ├── new_folder.svg      # Sobrescreve o ícone de Nova pasta
│   └── company_cloud.svg   # Adiciona um novo ícone de volume -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Opcional) Sobrescreve badges de pasta existentes ou adiciona NOVOS
    ├── lock.svg            # Sobrescreve o badge integrado "lock"
    └── invoice.svg         # Adiciona um NOVO badge -> aparece automaticamente no modal de personalização de pasta!
```

#### Passo 2: Crie `meta.json` e um `folder.svg` com Cor Dinâmica

> [!TIP]
> **Regra de Ouro (`currentColor`):** Para garantir que seu `folder.svg` personalizado se adapte automaticamente sempre que o usuário mudar a cor de uma pasta, use **`fill="currentColor"`** (ou `stroke="currentColor"` para ícones de contorno) em vez de cores hexadecimais fixas!

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/invoice.svg (Novo badge de pasta personalizado) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Passo 3: Aponte Seu Perfil YAML para o Diretório
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Arquivo Corporativo'
            icon: 'company_cloud' # Usa assets/fm-icons/ui/company_cloud.svg!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Segurança e Limites de Ícones Personalizados (`IconSetRegistry`)
- Cada arquivo `.svg` pode ter no máximo **256 KB** (`262,144` bytes) e é higienizado automaticamente pelo **`SvgSanitizer`** ao carregar (removendo `<script>`, handlers `on*`, etc.).
- Tanto `ui/` quanto `badges/` escaneiam até **200 arquivos `.svg` personalizados**, além das chaves integradas.
- Os nomes de arquivos personalizados (sem `.svg`) devem corresponder a **`/^[a-zA-Z0-9_-]{1,32}$/`** (letras ASCII, dígitos, `-` e `_`, no máximo 32 caracteres).

#### Referência Completa das Chaves de Ícones Sobrescrevíveis
- **9 Ícones de Arquivo e Pasta (raiz `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 Ícones de Interface (`<dir>/ui/<key>.svg`):**
  - *Navegação e Layout:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Ações de Arquivo e Pasta:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Armazenamento e Provedores de Nuvem:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 Badges de Pasta Integrados (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(além de quaisquer arquivos `*.svg` personalizados que você adicionar!)*

---

## 5. Criando Seu Próprio Tema Personalizado (3 Níveis de Liberdade)

O `Browser.html.twig` resolve o valor de `theme` configurado no seu perfil nesta **ordem exata de prioridade**:
1. Termina com `.html.twig` → Renderiza seu template Twig diretamente (`{% include theme %}`).
2. Começa com `@` → Renderiza `layout.html.twig` dentro desse namespace Twig (`{% include theme ~ '/layout.html.twig' %}`).
3. Corresponde a um dos 9 temas integrados → Renderiza o tema integrado do bundle.
4. Qualquer outro identificador curto (por exemplo, `'corporate'`, respeitando `/^[a-zA-Z0-9_-]{1,40}$/`) → Envolve o layout base padrão com `<div data-liveux-theme="corporate">`.

### Nível 1: Tema da Marca com Variáveis CSS Puras (Mais Rápido — Zero Templates!)

#### Referência Completa de Propriedades Personalizadas CSS (`--liveux-fm-*`)
| Categoria | Variáveis CSS |
| :--- | :--- |
| **Tipografia** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Superfícies e Bordas** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Cores de Texto** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Cores de Destaque da Marca** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Cores Semânticas de Status (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Raios e Sombras** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Tema Personalizado da Marca Pronto para Copiar (`assets/styles/app.css`)
```css
/* 1) Paleta da Marca no Modo Claro */
[data-liveux-theme="corporate"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) Contraparte no Modo Escuro (Cobre Browser e FormType Picker, modo escuro automático e manual) */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"],
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'corporate'
```

---

### Nível 2: Sobrescritas Parciais com Twig `{% extends %}` (Recomendado!)

O layout base é estruturado em **10 blocos `{% block %}`** e **7 parciais modulares (`parts/`)**. Você pode personalizar apenas a barra superior ou o rodapé sem duplicar o template inteiro:

| Nome do Bloco | Escopo | Parcial Padrão |
| :--- | :--- | :--- |
| `fm_shell` | Invólucro externo principal (`.liveux-fm-shell`) | Envolve todos os blocos aninhados |
| `fm_topbar` | Barra de comandos superior (navegação, breadcrumbs, busca, ordenação, ações) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Menu de configurações (modo de cor, tamanho dos itens, estilo das pastas, cache, atalhos) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Banner de notificações de status + fila de uploads | Marcação inline |
| `fm_body` | Área central principal (contêiner da barra lateral + área principal + inspetor) | `.liveux-fm-body` |
| `fm_sidebar` | Árvore de pastas à esquerda + pastas fixadas + barra de cota + lixeira | `parts/_sidebar.html.twig` |
| `fm_main` | Grade/lista de arquivos + barra flutuante de seleção/área de transferência + dropzone + paginação | `parts/_main.html.twig` |
| `fm_inspector` | Painel lateral direito de pré-visualização e metadados | `parts/_inspector.html.twig` |
| `fm_footer` | Barra de rodapé para Modal / OffCanvas | `parts/_footer.html.twig` |
| `fm_overlays` | Menu de contexto, modal, lightbox e templates de atalhos/sobre | `parts/_overlays.html.twig` |

#### Exemplo — Faixa de Cabeçalho da Marca + Rodapé de Seleção Personalizado
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Central Corporativa de Ativos Digitais</span>
        <span>Perfil: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Cancelar</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Confirmar Seleção</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) Caminho direto para o template .html.twig:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) Ou um diretório de namespace Twig (carrega automaticamente layout.html.twig dentro dele):
                # theme: '@App/file_manager'
```

---

### Nível 3: Layout 100% Headless do Zero (Targets Stimulus Obrigatórios)

Se você quiser construir sua própria estrutura HTML totalmente do zero sem `{% extends %}`, basta conectar estes atributos `data-{{ ctrl }}-target` usando o prefixo `ctrl = 'liveux--file-manager-bundle--browser'`:

| Target (`data-...-target`) | Obrigatório? | Finalidade |
| :--- | :---: | :--- |
| `shell` | Recomendado | Escala de tamanho dos itens (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Obrigatório** | Área principal onde os cards e linhas de arquivos/pastas são renderizados |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Obrigatório** | Contêiner dos diálogos de Nova Pasta, Envio, Exclusão, Personalização, Cota e Metadados |
| `breadcrumbs` | Recomendado | Trilha de navegação clicável do caminho atual |
| `confirmSelectionBtn`, `footerStatus` | Recomendado (Modal/OffCanvas) | Botão de confirmar seleção (automaticamente `disabled` quando nada está selecionado) e indicador de status |
| `statusBanner`, `uploadQueue` | Recomendado | Avisos de erro/sucesso e fila de progresso de uploads em partes (chunked) |
| `tree` + `sidebar` + `pinnedFolders` | Opcional | Árvore de pastas à esquerda e pastas fixadas |
| `contextMenu` | Opcional | Contêiner do menu de contexto (clique direito) |
| `inspector` | Opcional | Painel direito de pré-visualização e metadados |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Opcional | Lightbox universal de pré-visualização de mídia |

---

## 6. 7 Funções Auxiliares Twig para Templates Personalizados

| Função Twig | Finalidade | Exemplo de Uso |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Renderiza um SVG inline protegido contra XSS (não precisa de `\|raw`). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Gera uma URL segura de miniatura para um arquivo. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Gera uma URL segura de download ou pré-visualização inline. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Baixar</a>` |
| `liveux_fm_trans(key, params, locale)` | Traduz uma chave de mensagem do bundle com fallback garantido para `'en'`. | `{{ liveux_fm_trans('ui.upload', {}, 'pt') }}` |
| `liveux_fm_bcp47_locale(locale)` | Converte códigos de locale PHP/ICU como `pt_BR.UTF-8` em tags BCP-47 válidas para `lang="..."` em HTML (`pt-BR`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Retorna `true` se o idioma informado for escrito da direita para a esquerda (árabe, persa, hebraico, etc.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Normaliza com segurança valores de Formulários/EasyAdmin em descritores renderizáveis de imagem/arquivo (bloqueando URLs `javascript:`/`data:`). | Telas Index/Detail do EasyAdmin e templates personalizados |

---

[← Anterior: 03 — Modos de Uso e API JS](03-usage-modes-and-js-api.md) | [Índice](README.md) | [Próximo: 05 — FormType e Integrações com Editores →](05-formtype-and-editor-integrations.md)
