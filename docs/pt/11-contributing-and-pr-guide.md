# 11 — Guia de Contribuição e Pull Request (PR) (Passo a Passo para Iniciantes)

[← Anterior: 10 — Proteção de Uso de Ativos](10-asset-usage-guard.md) | [Índice da Documentação](README.md) | [Próximo: 12 — Habilidades de Agentes de IA →](12-ai-agent-skills-guide.md)

Este guia foi escrito do zero tanto para desenvolvedores que **nunca abriram um Pull Request (PR) em um projeto open-source antes** quanto para mantenedores que desejam configurar um **portão de qualidade automatizado no padrão Symfony** em seu próprio repositório. Cada conceito e comando é explicado passo a passo.

---

## 1. Visão Geral: Como Funcionam os Pull Requests em Projetos Open-Source

Em grandes projetos open-source como Symfony, Laravel ou React, contribuidores externos não podem fazer push diretamente para a base de código principal (branch `main`). Em vez disso, toda alteração passa por um **portão de segurança em 4 etapas**:

1. **Fork & Branch (Obtenha Sua Própria Cópia):** Copie o repositório para a sua própria conta do GitHub (*Fork*) e crie uma branch de trabalho dedicada (*Branch*).
2. **Código & Verificações Locais (`composer qa`):** Implemente sua alteração, escreva testes e execute a suíte completa de qualidade automatizada na sua máquina.
3. **Abra um Pull Request (PR):** Envie uma solicitação pedindo aos mantenedores que revisem e façam o merge da sua correção de bug ou nova funcionalidade no repositório principal. O GitHub exibe automaticamente uma **tabela de resumo e checklist no estilo Symfony** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Verificações Automatizadas de CI (GitHub Actions) + Code Review:**
   - No instante em que um PR é aberto ou atualizado, o GitHub Actions (`.github/workflows/ci.yml`) inicia runners na nuvem automaticamente.
   - Ele testa o código em **PHP 8.3 e 8.4**, **Symfony 7.2, 7.4 e 8.0** e **Node 20 e 22**, além de exigir conformidade com padrões de código (**PHP-CS-Fixer**), segurança estática de tipos (**PHPStan Nível 8**), integridade das traduções (**TranslationParityTest**), precisão do esquema YAML na documentação (**FullConfigReferenceDocTest**), integridade das Agent Skills (**SkillsPackageIntegrityTest**) e segurança de XSS/CSP/frontend (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **Se apenas uma única verificação falhar, o GitHub bloqueia o botão de Merge.**

---

## 2. A Infraestrutura de Qualidade Já Está Configurada?

**Sim — 100% configurada e pronta para uso.** O bundle inclui uma cadeia completa de ferramentas de qualidade modelada diretamente segundo os padrões oficiais do Symfony UX:

| Arquivo | Finalidade (Em Termos Simples) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Define a suíte de scripts `composer qa` em um único comando e as dependências de desenvolvimento standalone (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Executa todos os testes unitários e de segurança em PHP (**57 arquivos de teste, 360 testes, mais de 3.500 asserções**) de forma independente, sem exigir uma aplicação hospedeira. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Configura a análise estática **PHPStan Nível 8** para detectar incompatibilidades ocultas de tipo, argumentos inválidos e chamadas a métodos indefinidos antes do tempo de execução. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Aplica o estilo de código PSR-12 + Symfony em todos os arquivos PHP (`declare(strict_types=1)`, comparações estritas `===`, imports `use` em ordem alfabética). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Executa **6 jobs de CI em paralelo** automaticamente a cada Pull Request novo ou atualizado. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Pré-preenche cada novo PR com uma tabela de resumo no estilo Symfony (`Bug fix?`, `BC breaks?`, `Tests pass?`) e um checklist. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Fornece formulários estruturados para relatos de bugs (`bug_report.yml`) e solicitações de funcionalidades (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Diretrizes internacionais de contribuição vinculadas automaticamente pelo GitHub em toda tela de novo PR e Issue. |
| [`SECURITY.md`](../../SECURITY.md) | Política de divulgação de segurança explicando como reportar vulnerabilidades de forma privada em vez de abrir uma issue pública. |

### 7 Regras Críticas Aplicadas Automaticamente

Mesmo que um revisor humano deixe passar um dos problemas abaixo, **a suíte de testes automatizados detecta o erro e reprova o PR imediatamente**:

1. **Traduções Ausentes (`TranslationParityTest`):** Se uma nova string de interface for adicionada em inglês, mas estiver ausente em qualquer um dos **12 idiomas de interface integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), o teste **FALHA**.
2. **Ausência de `strict_types` (`TranslationParityTest`):** Se apenas um único arquivo PHP dentro de `src/` omitir `declare(strict_types=1);`, o teste **FALHA**.
3. **Violações de Segurança XSS / CSP / Frontend (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Adicionar manipuladores inline como `onclick="..."` ou o filtro `|raw` no Twig, ou quebrar a sanitização de URLs `javascript:` / DOM no JavaScript, faz o teste **FALHAR**.
4. **Desalinhamento do Esquema YAML na Documentação (`FullConfigReferenceDocTest`):** Todos os blocos YAML `liveux_file_manager:` na referência de [Configuração e Perfis](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) são validados contra o esquema real em `Configuration.php`.
5. **Integridade das Agent Skills (`SkillsPackageIntegrityTest`):** Valida o frontmatter, os limites de linhas/tamanho e os links de referência em `AGENTS.md` e em todos os 6 arquivos `skills/*/SKILL.md`.
6. **Estilo de Código e Tipos Estáticos (`PHP-CS-Fixer` + `PHPStan Nível 8`):** Qualquer violação de estilo PSR-12/Symfony ou erro de tipo no Nível 8 bloqueia o CI.
7. **Sincronização dos Arquivos Minificados em `assets/dist/` (`npm --prefix assets run build:check`):** Se você editar `assets/controllers/*.js` ou `assets/styles/core.css` e esquecer de recompilar `assets/dist/`, o CI **FALHA**.

---

## 2.5. Arquitetura de Minificação de JS e CSS (`assets/dist/`) com `esbuild`

> [!NOTE]
> **Por que isso existe?**
> - Usuários de **Webpack Encore** obtêm minificação automática de JS/CSS ao executar `npm run build`.
> - O **Symfony AssetMapper** (`asset-map:compile`), por outro lado, serve os arquivos como estão, sem minificá-los. No formato fonte original, `browser_controller.js` tem `518 KB` e `core.css` tem `132 KB`.
> - Para garantir carregamentos rápidos para usuários do AssetMapper sem exigir Node.js nos projetos hospedeiros, seguimos exatamente o padrão **Fonte → Distribuição (`assets/dist/`)** usado pelos pacotes oficiais do Symfony UX (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### Como Funciona

1. **Arquivos-Fonte Legíveis:** Todo o desenvolvimento e todos os testes de segurança são executados sobre arquivos-fonte bem formatados e comentados:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` e `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` e `assets/images/liveux-logo.webp`
2. **Saída Minificada para Produção (`assets/dist/`):** O script [`assets/build.mjs`](../../assets/build.mjs) usa **`esbuild`** para minificar cada arquivo JS como um módulo ESM independente (mantendo `@hotwired/stimulus` externo e preservando imports relativos) e compila `core.css` para `assets/dist/`.
3. **Resolução Automática (Zero Mudanças Incompatíveis):**
   - O método `prependAssetMapper()` em [`FileManagerBundle.php`](../../src/FileManagerBundle.php) mapeia automaticamente o namespace `@liveux/file-manager-bundle` para `assets/dist/` sempre que `assets/dist` existir.
   - As entradas `"exports"` e `"symfony.controllers.*.main"` em [`assets/package.json`](../../assets/package.json) garantem que o Webpack Encore também consuma diretamente os arquivos de `dist/`.
   - Como `assets/dist/` é versionado no Git, **os usuários finais nunca precisam de Node.js ou npm**. O arquivo `.gitattributes` exclui apenas as ferramentas de build (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) dos pacotes do Packagist, mantendo `assets/package.json` e `assets/dist/` incluídos.

### Economia de Tamanho Medida

| Arquivo | Fonte Original | Minificado (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 adaptadores de editor | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **TOTAL (`-45.1%` minificado)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Comandos para Desenvolvedores (Sempre Que Editar JS ou CSS)

```bash
# 1. Instale o esbuild uma única vez (somente na primeira vez):
npm --prefix assets install

# 2. Recompile assets/dist/ após editar qualquer arquivo JS ou CSS (~50 ms):
npm --prefix assets run build
# ou pelo atalho do Composer:
composer assets:build

# 3. Verifique se assets/dist/ está 100% sincronizado com os arquivos-fonte (mesma verificação executada no CI):
composer assets:check
```

---

## 3. Guia Passo a Passo para Enviar um Pull Request

> [!TIP]
> **Mesmo que você nunca tenha enviado um PR antes**, siga estes 6 passos em ordem para abrir um Pull Request limpo e aprovado no CI.

### Passo 1: Faça Fork, Clone e Instale as Dependências

1. Acesse [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) no GitHub e clique em **Fork** no canto superior direito para criar uma cópia na sua própria conta do GitHub.
2. Clone o seu fork localmente, adicione o repositório principal como `upstream` e instale as ferramentas de desenvolvimento PHP e Node:

```bash
git clone https://github.com/<SEU-USUARIO>/file-manager-bundle.git
cd file-manager-bundle

# Acompanhe o repositório principal como upstream (excelente hábito para manter seu fork atualizado)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Instale as dependências PHP de testes e análise estática
composer install

# Instale o minificador de JS/CSS (esbuild) se você planeja editar arquivos JS/CSS
npm --prefix assets install
```

### Passo 2: Crie uma Nova Branch a Partir de uma `main` Atualizada

Nunca faça commits diretamente na `main`. Sincronize a `main` primeiro e crie uma branch descritiva para o seu trabalho:
- Correção de bug: `fix/folder-counter-cache`
- Nova funcionalidade: `feat/new-icon-set`
- Documentação: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Passo 3: Escreva o Código, Adicione Testes e Recompile `dist/` se Necessário

- **Ao corrigir um bug:** Comece escrevendo um teste de regressão focado que reproduza o bug (falhando antes da correção e passando depois).
- **Ao adicionar uma funcionalidade:**
  - Adicione testes unitários em PHP em `tests/Unit/...`.
  - Para alterações em UI/JS, adicione cobertura em `tests/Js/dom_security_test.mjs` ou `tests/Js/frontend_audit_test.mjs`.
  - **Sempre que alterar JS ou CSS, execute `composer assets:build`** (caso contrário o CI falhará em `build:check`).
  - Sempre que adicionar uma nova chave de tradução de interface, adicione-a a **todos os 12 catálogos de idioma integrados**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Passo 4: Execute Todo o Portão de Qualidade Localmente de Uma Só Vez

Execute exatamente as mesmas verificações que o CI roda antes de abrir seu PR:

```bash
composer qa
composer assets:check
```

Esses comandos verificam, em ordem:
1. `composer validate --strict` — Valida o `composer.json`.
2. `composer cs:check` — Verifica os padrões de código PSR-12/Symfony *(se houver apontamentos, execute `composer cs:fix` para formatar automaticamente)*.
3. `composer stan` — Executa a análise estática **PHPStan Nível 8**.
4. `composer test` — Executa a suíte completa do PHPUnit de testes unitários, segurança, paridade de traduções e esquema YAML.
5. `composer test:js:syntax` — Executa a validação de sintaxe `node --check` em todos os arquivos JS fonte e de `dist/`.
6. `composer test:js` — Executa as suítes Node.js de segurança DOM e auditoria de frontend (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Confirma que `assets/dist/` corresponde byte a byte aos arquivos-fonte atuais.

> [!TIP]
> **Tudo verde?** Você está pronto para fazer o commit e abrir o seu Pull Request.

### Passo 5: Crie um Commit Limpo e Envie para o Seu Fork

```bash
git status            # Verifique se apenas os arquivos pretendidos foram modificados
git diff --stat       # Revise um resumo rápido das suas alterações
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Use prefixos claros de Conventional Commits: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Passo 6: Abra o Pull Request no GitHub e Acompanhe o CI Ficar Verde

1. Acesse o seu fork no GitHub (`https://github.com/<SEU-USUARIO>/file-manager-bundle`) e clique no banner **"Compare & pull request"**.
2. Confirme os alvos: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<SEU-USUARIO>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Preencha a tabela do **template de PR** carregada automaticamente (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) e marque os itens do checklist (`[x]`). Se sua alteração impactar a interface, anexe uma captura de tela ou GIF.
4. Clique em **"Create pull request"**.
5. Aguarde todas as verificações do GitHub Actions (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) na parte inferior da página do PR exibirem a **marca verde (✓)**.
6. Se um revisor solicitar ajustes, edite os arquivos na mesma branch e execute `git commit && git push` — o GitHub atualiza o PR e executa o CI novamente de forma automática.

---

## 4. Para Mantenedores: Aplicando Proteção de Branch e Rulesets no GitHub

Assim que os arquivos de workflow estiverem no repositório, o CI é executado automaticamente — mas para garantir que **ninguém (nem mesmo um mantenedor por acidente) possa clicar em Merge enquanto houver verificações falhando**, configure um **Ruleset** no GitHub em 2 minutos:

1. Abra o repositório do bundle no GitHub e acesse **Settings**.
2. Na barra lateral esquerda, selecione **Rules → Rulesets** (ou **Branches → Add branch protection rule** na visualização clássica).
3. Clique em **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Ative as 4 proteções a seguir:
   - [x] **Restrict deletions** (impede a exclusão da `main`)
   - [x] **Block force pushes** (protege o histórico de commits)
   - [x] **Require a pull request before merging** (bloqueia pushes diretos na `main`; todas as alterações chegam via PR)
     - *Required approvals:* `1` (recomendado para equipes; mantenedores individuais podem deixar em `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(configuração mais importante)*
     - [x] *Require branches to be up to date before merging*
     - Após a conclusão da primeira execução do CI, use **Add checks** para exigir todos os **6 jobs**:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Clique em **Create** na parte inferior da página.

---

## 5. Checklist para Publicar o Bundle como um Repositório Open-Source Independente

Para publicar o bundle como `LiveUX/file-manager-bundle` no GitHub e no Packagist:

1. Crie um novo repositório **Public** vazio chamado `file-manager-bundle` na organização `LiveUX` do GitHub.
2. Envie o conteúdo de `bundles/liveux-file-manager-bundle/` como commit inicial (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Verifique na aba **GitHub Actions** se todos os 6 jobs de CI passam com marcas verdes.
4. Ative as configurações de **Ruleset** descritas na **Seção 4** acima.
5. Crie a tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) e cadastre `https://github.com/LiveUX/file-manager-bundle` no [Packagist.org](https://packagist.org).
6. Agora qualquer pessoa pode instalar o bundle com um único comando: `composer require liveux/file-manager-bundle`.

---

[← Anterior: 10 — Proteção de Uso de Ativos](10-asset-usage-guard.md) | [Índice da Documentação](README.md) | [Próximo: 12 — Habilidades de Agentes de IA →](12-ai-agent-skills-guide.md)
