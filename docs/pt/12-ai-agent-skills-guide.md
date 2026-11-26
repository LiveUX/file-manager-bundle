# 12 — Guia de Habilidades para Agentes de IA (`SKILL.md` e `AGENTS.md`)

[← Anterior: 11 — Guia de Contribuição e PR](11-contributing-and-pr-guide.md) | [Índice da Documentação](README.md) | [Próximo: 13 — Integração com Doctrine →](13-doctrine-integration.md)

> [!TIP]
> **Em termos simples: para que servem as Agent Skills (`SKILL.md`)?**
> Se você utiliza um assistente de código com IA como **Antigravity, Cursor, Claude Code, GitHub Copilot ou OpenAI Codex**, execute `php bin/console file-manager:skills:install` uma única vez no seu projeto. Isso ensina ao assistente as chaves exatas de configuração YAML, os invariantes de segurança e os exemplos idiomáticos do bundle — fazendo com que ele gere código funcional logo na primeira tentativa, em vez de inventar opções inválidas.

O **LiveUX FileManagerBundle** inclui um pacote integrado de habilidades seguindo a especificação aberta **Agent Skills (`SKILL.md`)**, para que assistentes modernos de programação com IA (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** e **Symfony AI Mate**) integrem o bundle com precisão, segurança e sem suposições.

---

## 1. As 6 Habilidades Especializadas Incluídas no Bundle

As habilidades são organizadas usando **revelação progressiva (progressive disclosure)**: seu assistente de IA carrega apenas a habilidade relevante para a tarefa atual, mantendo o uso de contexto enxuto e focado:

| Nome da Habilidade (`skills/<name>/`) | Quando É Ativada | Arquivo de Referência |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Instalação, roteamento, AssetMapper/Encore, perfis e volumes (Local/S3/R2/MinIO/Supabase), cotas (`storage_quota`, `show_quota`), otimização de imagens e detecção de duplicatas | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (único/galeria), 5 variantes integradas, templates Twig customizados de FormType (`liveux:file-manager:picker-changed`), `FileManagerField` no EasyAdmin, JS API, TinyMCE, CKEditor 5 e Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 temas integrados, Modo Escuro (`color_scheme`), temas somente CSS ou temas Twig customizados com 10 blocos, sistema de ícones SVG em 3 camadas (49 ícones de UI + 14 selos de pasta) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | Firewall em `security.yaml`, 17 permissões de Voter, Voters customizados por pasta (`unanimous`), Proteção de Uso de Ativos (`AssetUsageCheckerInterface` — excluir/renomear/mover/sobrescrever), links de compartilhamento assinados, Audit Log e endurecimento de Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | `ConfigProviderInterface` dinâmico via banco de dados, `FolderMetadataStorageInterface` customizado, 9 listeners de Eventos Symfony, Symfony AI (`AssetEnricherInterface`) e todos os comandos CLI | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Regras de desenvolvimento do bundle para compatibilidade com PHP 8.3 / Symfony 7.2–8.0, invariantes de segurança, paridade de traduções e o portão de PR `composer qa` | `AGENTS.md` (raiz do pacote) |

---

## 2. Três Formas de Instalar as Habilidades no Seu Projeto

### Método A — Comando Integrado do Symfony Console (Recomendado, Zero Dependências Extras)

```bash
# Para Antigravity, Codex, OpenCode e Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Para Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Para Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Para GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Instalar em todas as ferramentas de IA suportadas com um único comando
php bin/console file-manager:skills:install --target=all
```

### Todas as Opções do Comando

| Opção / Atalho | Descrição | Exemplo |
| :--- | :--- | :--- |
| `-t` / `--target` | Ferramenta de IA de destino (`agents`, `claude`, `cursor`, `copilot`, `all`). Padrão: `agents`. | `-t all` |
| `--dir` | Caminho de diretório de destino personalizado. **Regra de segurança:** Deve ser estritamente um subdiretório dentro de `%kernel.project_dir%` (`..`, bytes NUL, a própria raiz do projeto e diretórios pai com symlink são rejeitados). | `--dir=.custom/skills` |
| `--only` | Instala apenas as habilidades selecionadas (nomes curtos ou completos separados por vírgula). | `--only=setup,security` |
| `-f` / `--force` | Sobrescreve diretórios de habilidades existentes com a versão mais recente. | `--force` |
| `--symlink` | Cria links simbólicos em vez de copiar arquivos (faz fallback automático para cópia no Windows caso privilégios de symlink não estejam disponíveis). | `--symlink` |
| `--dry-run` | Pré-visualiza quais habilidades seriam instaladas e em qual local, sem gravar nada no disco. | `--dry-run` |

### Método B — Descoberta Automática com Symfony AI Mate (`symfony/ai-mate`)

O `composer.json` do bundle declara `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Se `symfony/ai-mate` estiver instalado no seu projeto, um único comando descobre e sincroniza todas as 6 habilidades no seu workspace:

```bash
vendor/bin/mate discover
```

### Método C — CLI Aberta de Agent Skills (`npx skills`)

Instale diretamente a partir do GitHub em qualquer projeto:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Arquivo `AGENTS.md` na Raiz do Pacote

O arquivo `AGENTS.md` na raiz do bundle fornece regras sempre ativas para agentes de IA que contribuem diretamente com o próprio bundle:
- Zero dependência de frameworks CSS e regras para bridges opcionais
- Tipagem estrita em PHP (`declare(strict_types=1)`) e stubs de teste compatíveis com Symfony 7.2 / 8.0
- Proibição do filtro `|raw` no Twig e uso obrigatório de `isSafeUrl()` / `escapeHtml()` em JavaScript
- Validação de CSRF em primeiro lugar, isolamento de cache privado/por Voter e 100% de paridade de traduções em todos os **12 idiomas de interface integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Verificação Automatizada no CI

Em cada PR e execução de CI, o `SkillsPackageIntegrityTest` verifica automaticamente:
1. Se `AGENTS.md` existe, permanece dentro do orçamento de contexto de **`<= 24,000 bytes`** e não contém frontmatter YAML acidentalmente.
2. Se `composer.json` define corretamente `extra.ai-mate.skills`.
3. Se cada diretório em `skills/` contém um `SKILL.md` com frontmatter YAML válido onde `name` corresponde exatamente ao nome do diretório, `license: MIT` está declarado, `description` possui pelo menos `40` caracteres e cada arquivo permanece dentro de `<= 500` linhas.
4. Se cada link relativo `references/*.md` dentro de `SKILL.md` aponta para um arquivo existente em disco.

---

[← Anterior: 11 — Guia de Contribuição e PR](11-contributing-and-pr-guide.md) | [Índice da Documentação](README.md) | [Próximo: 13 — Integração com Doctrine →](13-doctrine-integration.md)
