# 03 — Modos de Uso e API JavaScript

[← Anterior: 02 — Configuração e Perfis](02-configuration-and-profiles.md) | [Índice da Documentação](README.md) | [Próximo: 04 — Temas, Conjuntos de Ícones e Personalização →](04-themes-icons-and-customization.md)

Você pode incorporar o LiveUX FileManagerBundle **inline** em qualquer página, abri-lo em um **Modal** centralizado, exibi-lo como uma gaveta **OffCanvas** em 4 direções ou acioná-lo programaticamente de qualquer lugar usando a **API JavaScript baseada em Promises**.

> [!TIP]
> **Qual modo devo escolher?**  
> - **Inline:** Páginas dedicadas de *"Biblioteca de Mídia"* onde o gerenciador de arquivos ocupa a área principal de conteúdo.  
> - **Modal:** Diálogo centralizado acionado por um botão *"Escolher imagem"* durante a edição de um produto ou artigo — fecha assim que o usuário seleciona um ativo.  
> - **OffCanvas:** Painel deslizante moderno a partir de qualquer borda (`start`, `end`, `top`, `bottom`) que mantém seu formulário visível ao fundo.  
> - **API JavaScript (`window.LiveUXFileManager.open()`):** Acione o seletor a partir de qualquer botão HTML personalizado, componente React/Vue/Alpine ou fluxo JS puro sem Symfony Forms.

---

## 1. Modo Inline — TwigComponent

![Visualização inline do Gerenciador de arquivos](../images/browser-light.png)

Incorporar um gerenciador de arquivos inline completo exige apenas uma chamada Twig. Envolva-o em um contêiner com altura explícita (como `680px` ou `calc(100vh - 140px)`):

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Ou usando a sintaxe de tag de componente `<twig:...>` do Symfony UX:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="pt"
    />
</div>
```

### Todas as Props do Componente `Browser`

| Prop | Tipo | Padrão | Descrição |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Chave do perfil definida em `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Chave do volume inicial (`null` abre o primeiro volume do perfil). |
| `path` | `string` | `''` | Subdiretório inicial (ex.: `'products/2026'`). |
| `multiple` | `bool` | `false` | Ativa o modo de seleção múltipla de arquivos. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` ou `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Filtro inicial por tipo: `'all'`, `'folder'`, `'image'`, `'doc'` ou `'video'`. |
| `theme` | `?string` | `null` | Sobrescrita de tema em tempo de execução (aceita os 9 temas integrados: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Temas CSS/Twig personalizados são configurados em YAML. |
| `iconSet` | `?string` | `null` | Sobrescrita do conjunto de ícones em tempo de execução (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Diretórios SVG personalizados são configurados em YAML. |
| `locale` | `?string` | `null` | Sobrescrita de idioma em tempo de execução entre todos os **12 idiomas integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) ou qualquer locale BCP-47 / POSIX (ex.: `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal e OffCanvas — API JavaScript Programática com Promises

O `DialogHost` é injetado automaticamente em todas as respostas HTML completas (`defaults.auto_inject_dialog_host: true`). Você pode chamar `window.LiveUXFileManager.open()` com `async/await` em qualquer página.

### Todas as Opções de `open(options)`

| Opção | Tipo | Padrão | Descrição |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Nome do perfil a abrir. |
| `volume` | `?string` | `null` | Chave do volume de armazenamento inicial. |
| `path` | `string` | `''` | Caminho da pasta inicial (ex.: `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Diálogo modal centralizado ou gaveta deslizante OffCanvas. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Borda de onde o painel desliza quando `mode: 'offcanvas'` (`start` = esquerda, `end` = direita; espelhado automaticamente em locales RTL). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(perfil)* | Sobrescreve o layout em telas móveis (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = arquivo único, `true` = múltiplos arquivos. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Abre pré-filtrado para uma categoria específica de arquivo (`filetype` é um alias de compatibilidade com o TinyMCE; `'media'` é mapeado automaticamente para `'video'`). |
| `theme` | `?string` | `null` | Sobrescrita em tempo de execução para qualquer um dos 9 temas integrados (caminhos de templates Twig personalizados ficam restritos ao YAML no servidor por segurança). |
| `iconSet` | `?string` | `null` | Sobrescrita em tempo de execução para qualquer um dos 6 conjuntos de ícones integrados. |
| `locale` | `?string` | `null` | Sobrescrita de idioma em tempo de execução (`'pt'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, `'ja'`, `'zh_CN'`, etc.). |

### Exemplo Prático: `<input>` Oculto + Pré-visualização de Imagem ao Vivo (Sem Symfony Form)

Cole este trecho em qualquer página HTML ou Twig e ele funcionará imediatamente:

```html
<!-- 1. Campo oculto + pré-visualização ao vivo + botão de disparo -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Pré-visualização" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Escolher imagem de capa</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Abre pré-filtrado para imagens
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // O usuário pressionou ESC ou fechou o diálogo sem selecionar nada
    if (selected.length === 0) {
        return;
    }

    const file = selected[0];
    document.getElementById('cover-url').value = file.url;

    const img = document.getElementById('cover-preview');
    if (file.isImage) {
        img.src = file.url;
        img.alt = file.meta?.alt || file.name;
        img.style.display = 'block';
    }
});
</script>
```

### Trechos Rápidos de Modos

```html
<!-- Modal centralizado -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Abrir Modal</button>

<!-- Gaveta lateral direita (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Gaveta Direita</button>

<!-- Gaveta inferior (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Gaveta Inferior</button>
```

> [!NOTE]
> **Comportamento ao Cancelar:** Se o usuário fechar o diálogo sem escolher nenhum arquivo (`Esc`, botão fechar ou clique no fundo), a Promise **não é rejeitada** — ela resolve de forma limpa para um array vazio (`[]`).
>
> **Responsividade Móvel (`mobileMode` / `ui.mobile_dialog_mode`):** Em viewports `<= 767.98px`, o diálogo se adapta automaticamente: no modo `'auto'`, modais e gavetas laterais (`start`/`end`) abrem em tela cheia de borda a borda (`100dvh`), enquanto gavetas `bottom`/`top` abrem como folhas nativas arredondadas com alça de arraste. A árvore de pastas esquerda começa recolhida em dispositivos móveis e desliza ao tocar.

| Painel OffCanvas no Desktop (`position: 'end'`) | Folha Inferior no Celular (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Visualização OffCanvas no Desktop](../images/offcanvas-desktop.png) | ![Visualização em Folha Inferior no Celular](../images/mobile-sheet.png) |

### Formato do Objeto de Arquivo Retornado

`window.LiveUXFileManager.open()` resolve para um array de objetos com o seguinte formato:

```json
[
    {
        "kind": "file",
        "path": "products/matte-black-tumbler.webp",
        "url": "/uploads/products/matte-black-tumbler.webp",
        "name": "matte-black-tumbler.webp",
        "extension": "webp",
        "isImage": true,
        "meta": {
            "alt": "Copo térmico preto fosco em aço inoxidável",
            "title": "Tumbler Pro",
            "caption": "Garrafa térmica a vácuo de 500 ml",
            "tags": ["garrafa", "termica"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Nota: em volumes com `private: true`, `url` aponta para o endpoint `/_liveux-file-manager/download?...&inline=1` protegido por RBAC.)*

### Eventos DOM Personalizados

Além de aguardar `window.LiveUXFileManager.open()`, você pode assinar os eventos de ciclo de vida do diálogo diretamente em `window`:

| Evento DOM (`window.addEventListener`) | Quando É Disparado | Payload de `event.detail` |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Quando uma solicitação de abertura de diálogo é despachada (ponte entre `file_manager_api.js` e `dialog_controller.js`). | opções de `open()` + `requestId` |
| `liveux:file-manager:dialog-opened` | Assim que o diálogo foi aberto e seu conteúdo foi montado. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Quando o usuário confirma uma seleção (ou cancela o diálogo). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Quantidade de itens selecionados:', event.detail.items.length);
});
```

---

## 3. Recursos Integrados para o Usuário Final

- **Menus de Contexto Compactos:**
  - *Em um arquivo ou pasta:* Barra de ações rápidas em SVG em linha única no estilo Windows 11 (`Recortar`, `Copiar`, `Renomear`, `Baixar`/`Fixar no Acesso rápido`, `Excluir`) seguida por ações agrupadas em um clique (Pré-visualizar, Editar imagem, Informações e etiquetas / SEO e ponto focal, Descompactar arquivo, Copiar link, Criar link de compartilhamento, Mover/Copiar, Duplicar, Cor da pasta e ícone).
  - *No espaço vazio:* Faixa superior de navegação rápida (`← → ↑ ↻`), Nova pasta, Enviar aqui, Colar aqui, Selecionar tudo, Ordenar por e Itens por página. *(Tela cheia, Atalhos de teclado e Sobre ficam dentro do menu **⚙ Configurações** na barra de ferramentas.)*
  - *Celular / Toque:* Abre como folha inferior amigável ao toque pelo botão `⋯` em cada cartão.
- **Visualizador Universal (Lightbox v2) e Painel de Pré-visualização e Detalhes:**
  - **Imagens:** Navegação de galeria (`←`/`→`), controles de zoom (`-` / `100%` / `+`) e indicador de ponto focal.
  - **Vídeo (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** Player `<video controls>` ao vivo no painel de detalhes + Lightbox em tela cheia + busca com `HTTP 206 Partial Content` em volumes privados (configurável por perfil via `ui.allow_video_preview`).
  - **PDF (`.pdf`):** Visualizador de PDF em `<iframe>` com sandbox dentro do Lightbox (configurável via `ui.allow_pdf_preview`).
  - **Áudio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) e Texto/Código (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql`, etc. — limite de 64 KB).**
  - No modo `inline`, **clicar duas vezes** em qualquer arquivo pré-visualizável (ou pressionar `Espaço`) abre o Lightbox imediatamente.
- **Fila Inteligente de Envio (Pausar / Retomar / Cancelar com Confirmação):**
  - Envie arquivos **ou hierarquias inteiras de pastas** (`webkitGetAsEntry`) arrastando e soltando, ou cole imagens direto da área de transferência com `Ctrl+V`.
  - Envios em partes exibem contadores ao vivo (`2/5 • 40%`), **Pausar (`⏸`) / Retomar (`▶`)** em um clique, **Cancelar (`✕`)** com confirmação, ações em lote **Pausar tudo / Cancelar tudo** e limpeza automática no servidor das partes temporárias incompletas ao cancelar.
- **Seleção Múltipla (Incluindo Laço de Seleção), Área de Transferência e Pesquisa Profunda:**
  - Clique e arraste em um espaço vazio para desenhar um **retângulo de seleção (Marquee / Laço)** ao redor de vários arquivos e pastas (segure `Ctrl`/`Cmd` para adicionar à seleção atual).
  - Seleção de intervalo com `Shift+Clique`, alternância com `Ctrl+Clique`, `Ctrl+A` para selecionar tudo e `Ctrl+C`/`Ctrl+X`/`Ctrl+V` entre pastas e até entre volumes.
  - **Cabeçalhos de Coluna Ordenáveis na Lista:** Clique nos cabeçalhos **Nome**, **Tipo**, **Tamanho** ou **Data** na visualização em Lista para alternar entre ordenação crescente e decrescente.
  - **Controle Deslizante de Tamanho dos Itens e Cartões de Pasta:** Ajuste o tamanho dos cartões ao vivo (`96 px – 320 px`) e alterne entre pastas compactas (`tile`) e cartões completos (`card`) no menu Configurações (`⚙`).
  - Ative o botão de Pesquisa Profunda na barra de pesquisa para buscar recursivamente em todas as subpastas, incluindo `#etiquetas` e metadados `alt`.
  - **⭐ Acesso rápido** com pastas fixadas na barra lateral, modal clicável de **Uso de armazenamento e cota** e alternador de modo de cor **Automático / Claro / Escuro**.
- **Validação de Resolução e Proporção de Imagem em 0 ms (`image_constraints`):** Quando regras de dimensão ou proporção (`1:1`, `16:9`, etc.) estão configuradas, imagens fora do padrão são rejeitadas instantaneamente no navegador antes do envio (e revalidadas no servidor).
- **Metadados Editoriais, Ponto Focal e Links de Compartilhamento Assinados:**
  - Edite `alt`, `title`, `caption`, `#tags`, seletor clicável de **Ponto focal** `[x, y]` e o botão opcional **✨ Sugerir com IA**.
  - Gere links de compartilhamento assinados com HMAC e validade temporária (`15 minutos`, `1 hora`, `24 horas`, `7 dias`, `30 dias`).
- **Editor de Imagens Canvas Integrado:** Recorte livre ou com proporção travada (`1:1`, `4:3`, `16:9`), redimensionamento em pixels (`Larg. máx.`/`Alt. máx.`), controle de formato de saída (`JPEG`/`PNG`/`WebP`) e qualidade, rotação de 90°, inversão horizontal/vertical e Salvar como cópia / Sobrescrever.

| Metadados do Arquivo e Ponto Focal (`I`) | Editor de Imagens Canvas Integrado (`E`) |
| :---: | :---: |
| ![Metadados do arquivo e ponto focal](../images/file-metadata.png) | ![Editor de imagens Canvas integrado](../images/image-editor.png) |

- **Internacionalização Global (i18n), RTL e Acessibilidade (WCAG 2.1 AA):**
  - **12 Idiomas de Interface Integrados e RTL Automático (`dir="rtl"`):** Suporta `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` e `zh_CN`. Árabe (`ar`), persa (`fa`), hebraico (`he`), urdu (`ur`) e outros locales RTL invertem automaticamente layouts, trilhas de navegação, barras laterais e bordas do OffCanvas usando CSS lógico (`inset-inline`, `margin-inline`).
  - **Segurança CJK / IME:** Impede que `Enter` ou atalhos disparem prematuramente durante a composição de texto em chinês, japonês ou coreano (`event.isComposing`).
  - **Acessibilidade Completa para Teclado e Leitores de Tela:** Aprisionamento de foco (`Tab`/`Shift+Tab`) em modais e no Lightbox, navegação por setas em menus de contexto, semântica `role="tree"` / `aria-expanded` / `aria-current` e restauração automática de foco ao fechar.
- **Atalhos de Teclado (Referência Completa):**
  - Todos os atalhos são ativados apenas quando o gerenciador de arquivos está em foco, fora de campos de texto (`<input>`, `<textarea>`, `<select>`), e respeitam rigorosamente as **permissões do Voter** do usuário (`ui.keyboard_shortcuts: true`).
  - No macOS, **`Cmd (⌘)`** funciona perfeitamente junto com `Ctrl`.
  - **Proteção para Teclados Internacionais e IME:** Utiliza códigos físicos de tecla (`event.code` `Key*`) com mapeamento de caracteres de fallback para que os atalhos funcionem em layouts não-QWERTY e não-latinos, mantendo-se silenciosos durante a composição IME CJK (`isComposing`).

#### A. Operações de Arquivos e Pastas
| Atalho | Ação | Condição / Observação |
| :--- | :--- | :--- |
| `N` | Criar nova pasta | Fora da Lixeira (permissão `create_folder`) |
| `U` | Abrir diálogo de envio | Fora da Lixeira (permissão `upload`) |
| `F2` | Renomear item | Um único arquivo ou pasta selecionado (permissão `rename`) |
| `M` | Abrir diálogo Mover / Copiar | 1 ou mais itens selecionados (permissão `move` ou `copy`) |
| `D` | Duplicar arquivo | **Um único arquivo** selecionado (permissão `clone`) |
| `I` | **Pasta:** Cor e Ícone / **Arquivo:** Metadados e Ponto focal | Um único item selecionado (permissão `edit_metadata`) |
| `E` | Abrir Editor de Imagens Canvas integrado | Uma única imagem raster (`.jpg`, `.jpeg`, `.png`, `.webp`) selecionada (permissão `upload`) |
| `L` | Copiar link do arquivo para a área de transferência | Um único arquivo selecionado (permissão `download`) |
| `S` | Abrir modal de Link de compartilhamento assinado (HMAC) | Um único arquivo selecionado (permissão `share` + `share.enabled: true`) |
| `Delete` | Excluir itens selecionados (lixeira ou exclusão permanente) | 1 ou mais itens selecionados (permissão `delete`) |

#### B. Navegação e Pré-visualização
| Atalho | Ação | Condição / Observação |
| :--- | :--- | :--- |
| `/` ou `Ctrl + F` | Focar campo de pesquisa | Quando `show_search: true` (`Ctrl + F` também funciona dentro de inputs) |
| `←` `→` `↑` `↓` | Navegar entre cartões da grade ou linhas da lista | — |
| `Enter` | **Pasta:** abrir • **Arquivo único:** abrir Lightbox no modo Inline, ou confirmar seleção no modo Modal/OffCanvas | — |
| `Espaço` | Pré-visualizar arquivo selecionado no Lightbox | Um único arquivo selecionado |
| `Backspace` ou `Alt + ↑` | Subir para a pasta superior | Dentro de uma subpasta |
| `Alt + ←` / `Alt + →` | Voltar / Avançar no histórico | Espelhado automaticamente em locales **RTL** (árabe, persa, hebraico) |
| `R` | Atualizar diretório atual a partir do servidor (`?refresh=1`) | Quando `show_refresh_button: true` |

#### C. Seleção, Área de Transferência e Fechamento de Camadas
| Atalho | Ação | Condição / Observação |
| :--- | :--- | :--- |
| `Ctrl + A` | Selecionar todos os itens visíveis | Fora da Lixeira |
| `Ctrl + C` / `Ctrl + X` | Copiar / Recortar itens selecionados para a área de transferência | Quando nenhum texto estiver destacado (permissão `copy` / `move`) |
| `Ctrl + V` | Colar arquivos/pastas da área de transferência no diretório atual **ou** enviar uma imagem/arquivo diretamente da área de transferência do sistema operacional | Também funciona com o modal de envio aberto |
| `Shift + Clique` / `Ctrl + Clique` | Seleção de intervalo / alternar itens individuais | Você também pode arrastar no espaço vazio para seleção em laço (Marquee) |
| `Esc` | **Fechamento inteligente em 4 estágios:** 1) Fechar menu/modal/lightbox/configurações/gaveta móvel aberta → 2) Limpar seleção → 3) Limpar área de transferência → 4) Sair da tela cheia inline | Fecha apenas a camada mais superior por toque |
| `Tab` / `Shift + Tab` | Percorrer os controles interativos dentro de um modal ou Lightbox aberto (aprisionamento de foco) | — |

#### D. Visualização e Ajuda
| Atalho | Ação |
| :--- | :--- |
| `V` | Alternar entre visualização Grade e Lista |
| `B` | Alternar árvore de pastas na barra lateral esquerda |
| `F` | Alternar modo tela cheia |
| `T` | Alternar visualização da Lixeira (quando `trash.enabled: true`) |
| `?` ou `؟` árabe | Abrir modal de ajuda de atalhos de teclado |

#### E. Dentro da Pré-visualização Lightbox
| Atalho | Ação |
| :--- | :--- |
| `←` / `→` | Imagem anterior / próxima *(espelhado automaticamente em locales RTL)* |
| `+`, `=` ou `Numpad +` | Aumentar zoom |
| `-` ou `Numpad -` | Diminuir zoom |
| `0` ou `Numpad 0` | Redefinir zoom para `100%` |
| `Esc` | Fechar Lightbox |

#### F. Dentro de um Menu de Contexto Aberto
| Atalho | Ação |
| :--- | :--- |
| `↑` / `↓` | Mover para cima / baixo entre os itens do menu |
| `Home` / `End` | Ir para o primeiro / último item do menu |
| `Enter` / `Espaço` | Executar a ação do menu em foco |
| `Tab` / `Esc` | Fechar o menu de contexto |

---

[← Anterior: 02 — Configuração e Perfis](02-configuration-and-profiles.md) | [Índice da Documentação](README.md) | [Próximo: 04 — Temas, Conjuntos de Ícones e Personalização →](04-themes-icons-and-customization.md)
