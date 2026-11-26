# 02 — Configuração e Perfis (Referência Completa de Parâmetros e Receitas)

[← Anterior: 01 — Requisitos e Instalação](01-requirements-and-installation.md) | [Índice da Documentação](README.md) | [Próximo: 03 — Modos de Uso e API JS →](03-usage-modes-and-js-api.md)

Este guia explica **todas as opções** de `config/packages/liveux_file_manager.yaml`, seus valores padrão, quando personalizá-las e **5 receitas prontas para produção** que você pode copiar e colar diretamente no seu projeto.

---

## Modelo Mental em 30 Segundos: `defaults`, `volumes` e `profiles`

Pense no arquivo de configuração em **3 camadas bem organizadas**:

1. **`defaults` (Padrões Globais):** Linha de base compartilhada herdada por todos os perfis (tema, locale, modo de cor, cache, driver de metadados, log de auditoria, padrões de IA). Salvo se sobrescritos em um perfil específico, esses valores valem em todo lugar.
2. **`volumes` (Discos / Buckets de Armazenamento):** Define **onde os arquivos residem fisicamente** (diretório local, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage ou um serviço Flysystem personalizado).
3. **`profiles` (Perfis de Uso):** Define as **regras, o layout da interface e as permissões** para uma tela ou fluxo específico. Por exemplo:
   - `product_catalog` aceita apenas fotos de produto quadradas `1:1` e as converte automaticamente para WebP;
   - `customer_docs` conecta-se a um bucket privado do Supabase S3, aceita apenas PDFs e isola cada usuário em sua própria pasta;
   - `editor_picker` abre um seletor modal enxuto para o TinyMCE com seleção por clique duplo.

---

## 1. `defaults` — Padrões Globais

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = herda o locale ativo do Symfony (fallback para 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parâmetro | Padrão | O Que Faz | Quando Alterar |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Diretório raiz padrão para perfis simplificados que definem `path` inline sem `volumes`. | Quando armazenar arquivos locais em outra pasta, como `public/media`. |
| `theme` | `'bootstrap5'` | Tema visual padrão para todos os perfis (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` ou o nome do seu tema personalizado). | Para combinar com o design system da aplicação (ex.: `'shadcn'` ou `'tailwind'` em projetos Tailwind). |
| `locale` | `null` | Quando `null`, herda automaticamente o locale ativo da requisição/Translator do Symfony em todos os **12 idiomas integrados** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), normaliza formatos BCP-47 e POSIX (`pt_BR.UTF-8`, `de_DE.UTF-8`, `zh-Hans-CN`), ativa RTL (`dir="rtl"`) automaticamente para árabe e outras escritas RTL e faz fallback para `'en'` em locales desconhecidos. | Defina explicitamente (ex.: `'pt'`, `'en'`, `'de'`) para fixar todos os usuários em um único idioma de interface. |
| `color_scheme` | `'auto'` | Modo de cor: `'auto'` (detecta `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (sempre claro) ou `'dark'` (sempre escuro). | Ao forçar um painel administrativo somente claro ou somente escuro. |
| `z_index` | `1050` | Contexto de empilhamento base (`--liveux-fm-z-base`) para modais, gavetas OffCanvas, Lightbox e menus de contexto (`>= 1`). | Aumente, por exemplo, para `10050` se a barra de navegação do seu painel usar `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | Adiciona automaticamente o host `<dialog>` exigido por `window.LiveUXFileManager.open()` antes de `</body>` em páginas HTML completas (dispensa editar `base.html.twig`). | Defina como `false` se preferir incluir `@LiveUXFileManager/components/DialogHost.html.twig` manualmente apenas em layouts específicos. |
| `dialog_host_profile` | `'default'` | Perfil padrão usado pelo `DialogHost` injetado automaticamente quando nenhum perfil é informado. | Quando seu perfil principal não se chama `default` (ex.: `admin_media`). |
| `cache.enabled` | `true` | Cache PSR-6 no servidor para listagens de diretórios e árvores de pastas. | Defina como `false` durante depuração local (ou desative por perfil/volume). |
| `cache.pool` | `'cache.app'` | Pool de cache PSR-6 do Symfony (Redis, Filesystem, APCu, etc.). Usa automaticamente `<pool>.taggable` quando disponível para invalidação cirúrgica por tags. | Para isolar entradas de cache do gerenciador de arquivos em um pool Redis dedicado. |
| `cache.ttl` | `3600` | Tempo de vida do cache em segundos (padrão: 1 hora). Alterações (enviar, excluir, mover, renomear) invalidam automaticamente os diretórios afetados. | Reduza para `60`–`300` quando arquivos forem alterados com frequência externamente via FTP ou painéis na nuvem. |
| `metadata.enabled` | `true` | Armazena cores/ícones/fixações de pastas e metadados de SEO dos arquivos (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Defina como `false` em seletores minimalistas que não precisam de metadados editoriais. |
| `metadata.driver` | `'json_file'` | Backend de metadados: `'json_file'` (arquivo local para apps em servidor único), `'doctrine'` (armazenamento DBAL integrado para apps multi-servidor / com balanceador em MySQL, MariaDB, PostgreSQL ou SQLite) ou `'none'`. | Mude para `'doctrine'` sempre que executar múltiplas réplicas da aplicação (Kubernetes, balanceador de carga, Docker Swarm) — veja o [Capítulo 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Caminho de armazenamento do driver `json_file`. Um arquivo auxiliar da lixeira (`trash-meta.json` ou `<nome>.trash-meta.json`) é gravado no mesmo diretório para buckets na nuvem com restrição de MIME. | Mova para um caminho NFS/volume compartilhado se necessário. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | Nome da conexão DBAL, EntityManager, nome da tabela (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) e flag de criação automática da tabela quando `driver: 'doctrine'`. | Defina `auto_create_table: false` em produção quando gerenciar o esquema via Doctrine Migrations (veja o [Capítulo 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Grava registros de auditoria PSR-3 estruturados (`user`, `ip`, `path`, `volume`) para todas as 9 mutações de arquivos/pastas (enviar, excluir, mover, copiar, renomear, duplicar, criar pasta, restaurar da lixeira, esvaziar/expurgar lixeira). | Ative (`true`) em ambientes sujeitos a conformidade onde seja preciso rastrear *"quem excluiu o quê e quando?"*. |
| `audit_log.level` | `'info'` | Nível de log PSR-3 para entradas de auditoria (`debug`, `info`, `notice`, `warning`). | Ajuste conforme suas regras de roteamento e filtragem no Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | Modelo de visão/texto padrão usado quando o pacote opcional `symfony/ai-bundle` está instalado (veja o [Capítulo 09](09-symfony-ai-integration.md)). | Troque para Gemini, Claude ou um modelo local do Ollama. |
| `ai.platform_service` | `null` | ID de serviço `PlatformInterface` personalizado do Symfony AI (`null` usa a plataforma registrada automaticamente). | Quando sua aplicação definir múltiplas plataformas Symfony AI. |

---

## 2. `volumes` — Volumes de Armazenamento (Local e Nuvem)

Um único perfil pode montar múltiplos volumes simultaneamente (por exemplo, Disco Local e Supabase/R2/S3). Os usuários podem arrastar e soltar arquivos diretamente de uma pasta local para um bucket na nuvem.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disco local'
            icon: 'disk'              # Opcional (detectado automaticamente pelo driver/endpoint se omitido)
            group: 'local'            # Opcional (null -> 'local' para LocalFileSystem, 'network' para S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # TTL opcional por volume (0 = desativado)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Também detectado automaticamente a partir do endpoint
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Edições externas no painel da nuvem aparecem em até 60 segundos
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parâmetro | Padrão | O Que Faz | Quando Alterar |
| :--- | :--- | :--- | :--- |
| `label` | Chave do volume | Nome exibido na barra lateral, na trilha de navegação e no seletor de volume. | Defina um título amigável (ex.: `'CDN de Mídia de Produtos'`). |
| `icon` | `null` *(auto)* | Ícone SVG na barra lateral. Quando `null`, é detectado automaticamente por `driver` e `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Aponte para um `<dir>/ui/meu_icone.svg` personalizado no seu conjunto de ícones. |
| `group` | `null` *(auto)* | Seção da barra lateral. Quando `null`, `LocalFileSystem` assume `'local'` e `AwsS3` / `FlysystemService` assumem `'network'`. | Sobrescreva se quiser agrupar uma montagem NFS em `'network'` ou um disco na nuvem em `'local'`. |
| `driver` | `'LocalFileSystem'` | Backend de armazenamento: sistema de arquivos local (`LocalFileSystem`), armazenamento de objetos compatível com S3 (`AwsS3`) ou um serviço Flysystem existente (`FlysystemService`). | Use `'AwsS3'` para S3/R2/Supabase/B2/MinIO/GCS, ou `'FlysystemService'` para SFTP/Azure/Dropbox. |
| `path` | `null` | Caminho físico do diretório para `LocalFileSystem` (suporta `{user_id}`, `{Y}`, `{m}`, `{d}`). | Defina uma raiz personalizada ou caminho de isolamento por usuário (`.../users/{user_id}`). |
| `url_prefix` | `null` | Prefixo de URL pública usado pelos navegadores para acessar arquivos públicos (`/uploads` ou `https://cdn.example.com`). Ignorado quando `private: true`. | Configure ao entregar ativos por meio de uma CDN ou bucket público S3/R2/Supabase. |
| `private` | `false` | Quando `true`, os arquivos nunca são expostos por URLs públicas diretas — todos os downloads, pré-visualizações e miniaturas passam pelas verificações do Voter do Symfony (`DownloadController`). | **Sempre defina `true`** para notas fiscais, documentos de identidade, contratos ou arquivos privados de clientes. |
| `cache_ttl` | `null` *(herdado)* | TTL de cache (em segundos) exclusivo deste volume. `0` desativa o cache para o volume; `null` herda do perfil/padrões. | Mantenha o disco local em cache por `3600`s enquanto limita um bucket na nuvem a `60`s ou `0`. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(token temporário de sessão STS)*, `version` *(padrão `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(automático `true` para endpoints personalizados; padrão `false` no AWS S3 padrão — defina `true` explicitamente para Bucket owner enforced)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Consulte o [Capítulo 07](07-cloud-storage-and-dynamic-config.md) para guias específicos por provedor. |

---

## 3. `profiles` — Dicionário de Parâmetros de Perfil

### 3.1. Armazenamento Principal, Filtragem e Cota
| Parâmetro | Padrão | O Que Faz | Quando Alterar |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Sobrescreve o idioma da interface e das mensagens de erro para este perfil (`'pt'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, etc.; `null` herda `defaults.locale` → locale da requisição no Symfony → `'en'`). | Quando painéis diferentes na mesma aplicação atendem idiomas distintos. |
| `volumes` | `[]` | Lista ordenada de chaves de volume acessíveis neste perfil (ex.: `['local_uploads', 'supabase_s3']`). O primeiro volume abre por padrão. | Para anexar um ou mais volumes locais/na nuvem. |
| `ignore_dot_files` | `true` | Oculta arquivos iniciados por ponto (`.env`, `.htaccess`, `.DS_Store`, `.trash`) das listagens e bloqueia o acesso direto a eles. | Mantenha `true` por segurança. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Oculta e bloqueia acesso direto a arquivos ou pastas que correspondam a expressões regulares ou curingas. | Oculte pastas internas como `/^cache$/i` ou `/^backups$/i`. |
| `sort_by` | `'name'` | Coluna de ordenação padrão: `'name'`, `'date'`, `'size'` ou `'type'`. | Use `'date'` + `sort_direction: 'desc'` para destacar os envios mais recentes primeiro. |
| `sort_direction` | `'asc'` | Direção de ordenação: `'asc'` (A→Z / menores primeiro) ou `'desc'` (Z→A / mais recentes primeiro). | `'desc'` funciona muito bem para galerias de mídia e bibliotecas editoriais. |
| `folders_first` | `true` | Lista pastas sempre acima dos arquivos. | Defina `false` para ordenação alfabética ou cronológica plana. |
| `storage_quota` | `null` | Uso máximo total de disco permitido para o perfil (ex.: `'500M'`, `'2G'`). Ao atingir o limite, novos envios são rejeitados no servidor e uma barra de uso aparece na barra lateral. | Essencial para SaaS multi-tenant ou cotas por usuário (`{user_id}`). |
| `upload_subfolder` | `null` | Modelo dinâmico de subpasta onde os arquivos enviados são colocados automaticamente sob o diretório atual (ex.: `'{Y}/{m}'`). | Para organizar envios automaticamente em pastas por data, como `2026/11`. |

### 3.2. Envios, Envio em Partes (`chunk_upload`) e Detecção de Duplicidade (`duplicate_detection`)
| Parâmetro | Padrão | O Que Faz | Quando Alterar |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(todos seguros)* | Lista de permissão de tipos MIME aceitos (ex.: `['image/jpeg', 'image/png', 'image/webp']`). Quando vazia, todos os tipos seguros que não estejam em `upload_deny` são aceitos. | Sempre restrinja a tipos MIME específicos em formulários só de imagem ou só de PDF. |
| `upload_deny` | **47 tipos MIME perigosos** | Tipos MIME estritamente bloqueados (PHP, HTML, XML não-SVG, JS, Shell, EXE, Java, CGI, etc. — validados no servidor via magic bytes). | Adicione tipos MIME extras bloqueados se sua política exigir. |
| `upload_max_size` | `'10M'` | Tamanho máximo **montado** de um único arquivo enviado (ex.: `'200M'`, `'1G'`). Com o envio em partes ativado, arquivos muito maiores do que o limite de `2M` do PHP são enviados sem problemas até esse teto. | Aumente para `'200M'`–`'500M'` em perfis de vídeos/PDFs de catálogo, ou reduza para `'2M'` em avatares. |
| `overwrite_policy` | `'rename'` | Comportamento em colisões quando já existe um arquivo com o mesmo nome: `'rename'` (`foto-1.jpg`), `'overwrite'` ou `'reject'`. | Mantenha `'rename'` para evitar perda acidental de dados. |
| `chunk_upload.enabled` | `true` | Divide envios grandes em partes menores com Pausar / Retomar / Cancelamento confirmado e nova tentativa automática em erros transitórios de rede. | Raramente desativado — apenas se você enviar estritamente arquivos minúsculos (< 1 MB). |
| `chunk_upload.chunk_size` | `'2M'` | Tamanho alvo de cada parte. Ajustado automaticamente para no máximo **80%** de `upload_max_filesize` e `post_max_size` do PHP (ex.: 1,60 MB sob limite PHP de 2M). | Aumente para `'5M'` em servidores com limites PHP altos (ex.: 32M+) para envios grandes mais rápidos. |
| `chunk_upload.parallel_uploads` | `3` | Número máximo de envios simultâneos (`1`–`10`). | Use `2` em servidores com poucos recursos ou `4`–`5` em conexões rápidas de nuvem/CDN. |
| `chunk_upload.max_retries` | `3` | Novas tentativas com backoff exponencial por parte em caso de queda de rede (`0`–`10`). | `3`–`5` funciona bem para redes móveis ou instáveis. |
| `duplicate_detection.enabled` | `false` | Detecta envio de arquivos duplicados via hash de conteúdo SHA-256. | Ative (`true`) para impedir que editores enviem a mesma foto de produto repetidas vezes. |
| `duplicate_detection.policy` | `'warn'` | Ação ao detectar duplicidade: `'warn'` (envia e avisa), `'reject'` (bloqueia o envio) ou `'reuse'` (retorna o arquivo existente sem duplicar espaço em disco). | `'reuse'` gera economia significativa de armazenamento em lojas de e-commerce e CMS. |
| `duplicate_detection.max_scan_files` | `5000` | Limite superior de arquivos indexados para detecção de duplicidade (`100`–`50000`) para evitar picos de CPU/E/S em acervos enormes. | Aumente para `10000`–`25000` em bibliotecas de mídia grandes. |

### 3.3. Normalização Automática de Nomes de Arquivo (`naming`)
| Parâmetro | Padrão | O Que Faz | Exemplo (`"Foto Produto 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Translitera caracteres acentuados/Unicode e espaços em slugs ASCII limpos e amigáveis para URL. | `foto-produto-2026.jpg` |
| `naming.slugger_locale` | `null` | Locale de transliteração (`null` herda o locale do perfil; ex.: `'pt'` trata acentos do português, `'de'` mapeia `ä/ö/ü/ß`, `'tr'` mapeia `İ/ı/ş/ğ/ç/ö/ü`). | Defina para o idioma do seu conteúdo (ex.: `'pt'`, `'de'`, `'tr'`, `'fr'`). |
| `naming.transformer` | `'none'` | Transformação de caixa/nomenclatura: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` ou `'folder'` (nomeia o arquivo com o nome da pasta pai!). | `'snake'` → `foto_produto_2026.jpg`, `'folder'` → `garrafas-1.jpg` |
| `naming.prepend` / `append` | `''` | Adiciona prefixo ou sufixo estático ou com tokens dinâmicos (`{Y}`, `{m}`, `{d}`, `{uniqid}`). | `prepend: '{Y}{m}_'` → `202611_produto.jpg` |
| `naming.apply_on_rename` | `true` | Também aplica as regras de `slugger` e `transformer` quando um usuário renomeia um arquivo na interface. | Mantenha `true` para garantir nomes padronizados mesmo após renomeações. |
| `naming.apply_affixes_on_rename` | `false` | Evita que tokens de `prepend`/`append` sejam duplicados toda vez que o arquivo for renomeado. | Mantenha `false`. |
| `naming.allow_extension_change` | `false` | Impede que usuários alterem a extensão (ex.: `.jpg` → `.php` ou `.png`) ao renomear. | Mantenha `false` por segurança. |

### 3.4. Otimização de Imagens (`image_optimization`) e Regras de Imagem (`image_constraints`)
- **`image_optimization`:** Corrige orientação automaticamente, remove metadados EXIF, redimensiona e comprime fotos grandes no servidor durante o envio.
- **`image_constraints`:** Rejeita imagens fora do padrão **em 0 ms no navegador e também no servidor** (por exemplo, bloqueando envios desfocados de baixa resolução ou fotos de produto não quadradas).

| Parâmetro | Padrão | O Que Faz | Quando Usar |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Ativa a otimização de imagens no envio (Imagick → GD → sem efeito silencioso se nenhum estiver disponível). | Ative (`true`) em qualquer perfil onde usuários enviem fotos direto do celular. |
| `image_optimization.auto_orient` | `true` | Lê as tags de orientação EXIF e gira automaticamente fotos de smartphone tiradas deitadas. | Mantenha `true` para envios via dispositivos móveis. |
| `image_optimization.strip_exif` | `true` | Remove coordenadas GPS e metadados EXIF da câmera (privacidade LGPD/GDPR + arquivos menores). | Mantenha `true` em todas as imagens voltadas ao público. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Reduz proporcionalmente imagens que excedam esses limites mantendo a proporção (`>= 100`). | `1920`×`1920` é um excelente equilíbrio para imagens web. |
| `image_optimization.convert_to_webp` | `false` | Converte automaticamente arquivos JPEG/PNG enviados para WebP (arquivos 30–60% menores). | Ative (`true`) em perfis voltados a desempenho web moderno. |
| `image_optimization.quality` | `85` | Qualidade de compressão de saída (`10`–`100`). | `80`–`85` oferece o melhor equilíbrio entre tamanho e fidelidade visual. |
| `image_constraints.min_width` / `min_height` | `null` | Dimensões mínimas exigidas em pixels. | Evite envios desfocados (ex.: capas de blog com no mínimo `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Dimensões máximas permitidas em pixels (verificadas *após* o redimensionamento de `image_optimization`, quando ativo). | Impõe dimensões exatas para banners ou templates de impressão. |
| `image_constraints.aspect_ratio` | `null` | Proporção obrigatória: `'1:1'` (quadrada), `'16:9'` (banner widescreen), `'4:3'`, `'3:4'`, `'1.91:1'`, etc. | Cartões quadrados de produtos em e-commerce (`'1:1'`) ou cabeçalhos de vídeos/artigos (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Desvio permitido em relação à proporção alvo (`0.0`–`0.5`; `0.05` = 5% de tolerância, permitindo que tanto `1920×1080` quanto `1280×722` passem). | `0.05` funciona bem na grande maioria dos cenários. |

### 3.5. Miniaturas (`thumbnail`), Lixeira (`trash`), ZIP (`zip`) e Links de Compartilhamento Assinados (`share`)
| Parâmetro | Padrão | O Que Faz |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Gera miniaturas em cache para imagens: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` ou `'none'`. Imagens na nuvem são baixadas uma única vez e armazenadas em cache local. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Comportamento quando nem GD nem Imagick estão instalados: `'fallback_original'` (mostra a imagem original), `'icon'` (mostra o ícone do arquivo) ou `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Dimensões da miniatura (`>= 32`), formato de saída (`webp`, `jpeg`, `png`) e modo de ajuste (`contain` = imagem inteira visível, `cover` = preenchimento com recorte central). Considere `480`×`360` ao usar `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | Adiciona `loading="lazy"` nativo às imagens dos cartões na grade para renderização inicial mais rápida em pastas grandes. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Diretório local onde as miniaturas geradas ficam em cache. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Move arquivos excluídos para a lixeira em vez de destruí-los imediatamente. Usa automaticamente o sidecar local `trash-meta.json` em buckets na nuvem com restrição de MIME. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Habilita downloads `.zip` multi-arquivo/pasta em um clique (exige PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | Extração de arquivos compactados protegida em 7 camadas contra Zip-Slip e Zip-Bomb. Sempre bloqueada enquanto `allow_extract: false`; ativá-la define automaticamente `permissions.extract_zip` como `true` (você pode restringir para `'ROLE_ADMIN'` ou `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Gera URLs de compartilhamento temporárias assinadas com HMAC via `UriSigner` do Symfony. Definir `enabled: false` invalida imediatamente todos os links emitidos anteriormente. |

### 3.6. Interface do Usuário (`ui`) — Layout, Dimensionamento e Flags da Barra de Ferramentas
| Parâmetro | Padrão | O Que Faz | Quando Alterar |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Tema específico do perfil (ex.: `'bootstrap5'` no admin, `'shadcn'` no portal do cliente). | Quando diferentes áreas da aplicação utilizam temas visuais distintos. |
| `ui.color_scheme` | `null` *(defaults)* | Modo de cor específico do perfil (`auto`, `light`, `dark`). | Fixe `'dark'` em uma área de trabalho somente escura. |
| `ui.icon_set` | `'classic'` | Estilo dos ícones de arquivos/pastas: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` ou caminho para diretório SVG personalizado (`'%kernel.project_dir%/assets/fm-icons'`). | Escolha `'windows11'` ou `'macos'` para um visual de desktop, ou aponte para SVGs da sua marca. |
| `ui.z_index` | `null` *(defaults)* | Sobrescrita do z-index das sobreposições específica para este perfil. | Quando embutido em uma página com editores ou gavetas de `z-index` elevado. |
| `ui.mobile_dialog_mode` | `'auto'` | Apresentação do diálogo em telas pequenas (`<= 767.98px`): `'auto'` (modais e offcanvas laterais abrem em tela cheia; offcanvas superior/inferior viram folhas nativas arredondadas), `'fullscreen'` (todos em tela cheia) ou `'sheet'` (todos abrem como folha inferior nativa). | Force `'sheet'` ou `'fullscreen'` em todos os seletores móveis. |
| `ui.default_view` | `'grid'` | Layout inicial: `'grid'` (Grade com cartões de miniaturas) ou `'list'` (Lista em tabela ordenável). | Use `'list'` para arquivos de PDFs/documentos e `'grid'` para galerias visuais. |
| `ui.item_size` | `'md'` | Largura dos cartões na Grade e escala das miniaturas na Lista: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) ou um inteiro exato em pixels (`96`–`320`). Alterna automaticamente para espaçamento compacto em `<= 132px`. | Use `'lg'` / `'xl'` para revisão visual e `'sm'` / `'xs'` em seletores compactos. |
| `ui.card_aspect_ratio` | `'4/3'` | Proporção do quadro de pré-visualização do cartão: `'1/1'` (quadrado), `'4/3'` (padrão), `'16/9'` (widescreen/vídeo) ou `'3/4'` (pôsteres/catálogos verticais). | Combine com o formato principal dos seus ativos (`'1/1'` para produtos, `'16/9'` para vídeos/banners). |
| `ui.folder_style` | `'tile'` | Estilo de pasta na visualização em Grade: `'tile'` (Compacto — blocos horizontais no estilo Google Drive acima dos arquivos) ou `'card'` (Cartão — cartões de pasta em tamanho integral no estilo Windows/Finder). | Mude para `'card'` para dar maior destaque visual às pastas. |
| `ui.show_size_slider` | `true` | Exibe o controle deslizante de tamanho dos itens em tempo real (`96px`–`320px`) e a alternância de estilo de pasta no menu Configurações. | Defina `false` para impor uma densidade de layout fixa. |
| `ui.volume_order` | `'local_first'` | Ordem dos volumes na barra lateral e no seletor: `'local_first'`, `'network_first'` ou `'configured'` (ordem exata de `volumes: [...]` no YAML sem divisores de grupo). | Use `'network_first'` ou `'configured'` em fluxos centrados na nuvem. |
| `ui.show_folder_tree` | `true` | Exibe ou oculta a barra lateral esquerda com a árvore de pastas. | Defina `false` em seletores simples de pasta única para TinyMCE ou avatares. |
| `ui.tree_lines` | `true` | Renderiza linhas conectoras de hierarquia dentro da árvore de pastas. | Defina `false` para uma barra lateral mais limpa e minimalista. |
| `ui.show_folder_item_count` | `true` | Exibe contadores de itens (`12`) ao lado das pastas. Respeita a autorização para nunca vazar a existência de subpastas restritas. | Mantenha `true` para visibilidade rápida do conteúdo das pastas. |
| `ui.folder_count_mode` | `'recursive_files'` | Como os contadores de pasta são calculados: `'recursive_files'` (todos os arquivos incluindo subpastas), `'files'` (apenas arquivos diretos) ou `'items'` (arquivos diretos + subpastas). | Escolha `'files'` para contagens diretas apenas. |
| `ui.inspector_mode` | `'on_select'` | Painel direito de pré-visualização e detalhes: `'on_select'` (abre ao selecionar um item), `'always'` ou `'hidden'`. | Defina `'hidden'` em seletores modais estreitos. |
| `ui.folder_open_on` | `'dblclick'` | Gesto para abrir pastas: `'dblclick'` (clique simples seleciona, clique duplo abre — padrão desktop) ou `'click'` (clique simples abre imediatamente — padrão web/mobile). | Use `'click'` para navegação rápida com um clique em painéis administrativos. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Itens por página padrão (`6`–`200`) e opções disponíveis para o usuário. | Aumente para `48` e `[24, 48, 96, 150]` em catálogos volumosos. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Exibe a barra de cota de armazenamento na barra lateral e o modal clicável de Uso de armazenamento e cota. (A aplicação de `storage_quota` no servidor continua ativa mesmo se oculta.) | Defina `show_quota: false` se não quiser expor medidores de cota na interface. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Controla o Lightbox de PDF inline e a reprodução de vídeo HTML5 (aplicado na interface e no servidor em `DownloadController`). | Defina `false` para forçar download direto em vez de pré-visualização inline no navegador. |
| `ui.remember_last_dir` | `true` | Salva o último **volume + diretório** visitado pelo usuário em `localStorage` (recupera automaticamente para a pasta pai/raiz se a pasta tiver sido excluída). | Defina `false` se cada sessão precisar começar sempre no diretório raiz. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Solicita confirmação antes de excluir ou mover itens por arrastar e soltar. | Defina `confirm_move: false` para fluxos mais ágeis de reorganização. |
| `ui.double_click_to_select` | `true` | No modo seletor Modal/OffCanvas, clicar duas vezes em um arquivo seleciona-o imediatamente e fecha o diálogo. | Defina `false` se o clique duplo dever abrir a pré-visualização em vez de selecionar. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Ativa menus de contexto com botão direito e a faixa rápida de navegação `[ ← \| → \| ↑ \| ↻ ]` ao clicar com o botão direito no espaço vazio. | Defina `false` para telas finais mais restritas. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Ativa movimentação entre pastas por arrastar e soltar e envio arrastando arquivos/pastas do sistema operacional. | Defina `false` em bibliotecas de ativos somente leitura. |
| `ui.keyboard_shortcuts` | `true` | Ativa atalhos de teclado (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Delete`, `Espaço`, `/`, `R`, `F`, `?`, etc.). | Desative (`false`) se a página hospedeira tiver atalhos globais conflitantes. |
| Flags `ui.show_*` | *(em sua maioria `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Ative ou desative controles individuais da barra de ferramentas para simplificar a interface. *(Nota: os itens "Sobre" e "GitHub" dentro de Configurações são itens integrados de atribuição e não são desativados via YAML.)* |

### 3.7. Cache por Perfil (`cache`), Segurança (`security`), Permissões (`permissions`), IA e Proteção de Uso

#### Cache (`cache`)
| Parâmetro | Padrão | Descrição |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Ativa ou desativa o cache para este perfil. Quando um perfil define `cache.enabled: false`, `volume.cache_ttl` não reativa o cache. |
| `cache.ttl` | `null` *(defaults)* | Ordem de precedência de TTL: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Segurança (`security`)
| Parâmetro | Padrão | Descrição |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Ativa a verificação RBAC via `FileManagerVoter` e quaisquer Voters personalizados. Mantenha sempre `true` em produção. |
| `security.csrf_protection` | `true` | Exige um cabeçalho `X-LiveUX-FM-CSRF` válido em todas as solicitações `POST`. |
| `security.sanitize_svg` | `true` | Sanitiza SVGs enviados e editados contra uma lista estrita de 55 tags permitidas (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Ativa a aba **"De uma URL"** no modal de envio (protegida contra SSRF e DNS rebinding pelo `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Sobrescreve o log de auditoria PSR-3 para este perfil (`true` / `false` / `null`). |

#### 17 Permissões Granulares (`permissions`)
> [!WARNING]
> **Importante: o que significa `true` em `permissions`?**  
> Definir uma permissão como **`true`** **NÃO** significa *"acesso público / anônimo"*! O `FileManagerVoter` concede `true` **apenas a usuários autenticados (`$token->getUser() !== null`)** e nega solicitações anônimas.  
> Além disso, **`permissions.view`** é o portão principal: se o usuário não tiver `view`, todas as demais ações (`upload`, `delete`, `download`, etc.) são negadas automaticamente.

Cada permissão aceita um de 4 formatos:
- `true` → Qualquer usuário autenticado
- `false` → Ninguém (ação completamente desativada)
- `'ROLE_ADMIN'` → Apenas usuários com esse papel
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Usuários com qualquer um desses papéis

| Chave de Permissão | Padrão | Ações Controladas |
| :--- | :--- | :--- |
| `view` | `true` | Listagem de diretórios, árvore de pastas, pré-visualizações e **pré-requisito para todas as outras permissões**. |
| `upload` | `true` | Envios padrão/em partes, salvamento pelo editor de imagens e importação de URLs remotas. |
| `create_folder` | `true` | Criação de novas pastas. |
| `rename` | `true` | Renomeação de arquivos e pastas. |
| `clone` | `true` | Duplicação de um arquivo ou pasta. |
| `move` | `true` | Movimentação de itens via arrastar e soltar ou Recortar/Colar. |
| `copy` | `true` | Cópia de itens via Copiar/Colar ou diálogo Mover/Copiar. |
| `delete` | `true` | Exclusão de arquivos/pastas (enviar para a lixeira ou excluir permanentemente). |
| `trash_restore` | `true` | Restauração de itens da lixeira. |
| `trash_purge` | `true` | Exclusão permanente de itens da lixeira ou esvaziamento da lixeira. |
| `download` | `true` | Download de arquivos individuais, streaming de PDFs/vídeos e cópia de links de arquivos. |
| `download_zip` | `true` | Download de vários itens selecionados como um arquivo `.zip`. |
| `extract_zip` | `null` *(auto)* | Forçada como `false` enquanto `zip.allow_extract: false`; torna-se automaticamente `true` quando `zip.allow_extract: true` (restrinja com `'ROLE_ADMIN'` se desejar). |
| `share` | `true` | Geração de links de compartilhamento temporários assinados com HMAC. |
| `clear_cache` | `true` | Limpeza do cache de diretórios em Configurações e atualização forçada `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Edição de `alt`/`title`/`caption`/`tags`/ponto focal de arquivos e cor/ícone/fixação de pastas. |
| `ai_enrich` | `true` | Chamada a **✨ Sugerir com IA** (exige `view` + `edit_metadata` + `ai_enrich`). |

#### Enriquecimento com IA (`ai`) e Proteção de Uso de Ativos (`usage_guard`)
| Parâmetro | Padrão | Descrição |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Ativa sugestões de metadados por IA; definir `locale` controla o idioma de saída (ex.: `'pt'`, `'en'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Número máximo de etiquetas sugeridas (`1`–`20`) e limite de redimensionamento antes de enviar à IA (`256`–`2048` px, economizando tokens de visão). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Impede que arquivos em volumes `private: true` sejam enviados a APIs externas de IA; enriquecimento automático opcional após o envio (veja o [Capítulo 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Avisa ou bloqueia operações de **`delete`, `rename`, `move` e `overwrite`** quando um ativo está referenciado no banco de dados (veja o [Capítulo 10](10-asset-usage-guard.md)). |

---

## 4. 5 Receitas de Perfil Prontas para Produção (Copiar e Colar)

Aqui estão as 5 configurações mais comuns em projetos reais, prontas para usar:

### Receita 1: Catálogo de Produtos de E-Commerce (Cartões Quadrados + WebP + Reutilização de Duplicados)
> **Objetivo:** Aceitar apenas fotos de produto quadradas `1:1` em boa resolução; redimensionar e converter automaticamente envios de celular para WebP; reutilizar arquivos existentes em envios duplicados; bloquear exclusão acidental de ativos vinculados a produtos ativos.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'pt'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # Cartões grandes de 204px para produtos
                card_aspect_ratio: '1/1' # Proporção quadrada de vitrine
                folder_style: 'card'     # Pastas de categoria em cartão completo
                show_folder_item_count: true
```

### Receita 2: Imagens de Capa de Blog / Notícias (Banners `16:9` + Texto Alternativo por IA)
> **Objetivo:** Aceitar apenas capas widescreen `16:9` de alta resolução (mínimo `1200×630`px), organizar os envios automaticamente em pastas `2026/11` e gerar texto alternativo de SEO e etiquetas com IA.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Capas mais recentes primeiro
            ai:
                enabled: true
                locale: 'pt'
                max_tags: 6
            ui:
                item_size: 'xl'           # Pré-visualizações amplas de 260px
                card_aspect_ratio: '16/9' # Proporção widescreen de banner
```

### Receita 3: Cofre de Documentos e PDFs de Clientes (Supabase/S3 Privado + Isolamento por Usuário)
> **Objetivo:** Isolar cada cliente em suas próprias faturas PDF em um bucket privado compatível com S3, bloquear URLs externas diretas, atualizar listagens da nuvem a cada 60 segundos e registrar auditoria de todas as ações.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Cofre de PDFs do Cliente'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Sem acesso direto por URL não autenticada
            cache_ttl: 60          # Edições externas na nuvem aparecem em até 60s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Cada usuário vê apenas a própria pasta
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Tabela ordenável ideal para PDFs
                item_size: 'sm'          # Linhas compactas
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Receita 4: Seletor Rápido para Editor Rich-Text (TinyMCE / CKEditor / Tiptap)
> **Objetivo:** Um seletor modal leve e sem distrações onde clicar duas vezes em uma imagem a insere imediatamente no editor.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Receita 5: Biblioteca de Parceiros / Kit de Imprensa Somente Leitura
> **Objetivo:** Permitir que parceiros naveguem por logotipos e catálogos, baixem arquivos individuais ou ZIPs em lote e gerem links de compartilhamento assinados — impedindo qualquer envio, edição ou exclusão.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 dias
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Placeholders Dinâmicos de Caminho (`DynamicPathResolver`)

Você pode usar placeholders dinâmicos em `path` local, `url_prefix`, `driver_options.prefix` na nuvem e `upload_subfolder` do perfil:

| Placeholder | Descrição | Exemplo de Saída |
| :--- | :--- | :--- |
| `{user_id}` | Identificador seguro para sistema de arquivos e sem colisões do usuário Symfony autenticado (`guest` se não autenticado). | `42` ou `alice_a--h-9f1c2e4b...` |
| `{Y}` | Ano com 4 dígitos | `2026` |
| `{m}` | Mês com 2 dígitos | `11` |
| `{d}` | Dia com 2 dígitos | `25` |

```yaml
volumes:
    user_space:
        label: 'Meu espaço pessoal'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Isolamento Multi-Tenant `{user_id}` Sem Colisões:**  
> O `DynamicPathResolver` gera chaves de diretório **disjuntas com sufixo SHA-256 `<slug>--h-<16-hex>`** para e-mails (`alice.a@example.com` vs `alice_a@example.com`), variações de maiúsculas/minúsculas (`Alice` vs `alice`), nomes de usuário Unicode (árabe, chinês, turco, acentuados), palavras reservadas (`guest`, `user`, identificadores terminados em `--h-`) e nomes de dispositivos do Windows (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Dois usuários distintos nunca podem colidir na mesma pasta.
>
> **Comportamento no CLI:** Comandos de console (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup`, etc.) ignoram automaticamente volumes que contêm `{user_id}` para nunca criar uma pasta `guest/` indesejada.

---

## 6. Referência Completa do Esquema YAML (Todas as Opções Ativas)

O trecho YAML abaixo documenta **todas as chaves de configuração ativas** definidas em `Configuration.php`, com seus valores padrão e comentários inline. No seu próprio projeto, declare apenas as chaves que deseja personalizar — todo o restante herda o valor padrão automaticamente.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) PADRÕES GLOBAIS — Herdados por todos os perfis salvo se sobrescritos
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Diretório raiz padrão para perfis simplificados de disco único
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = locale da requisição/Translator do Symfony (12 idiomas integrados, fallback 'en') | 'pt' | 'en' | 'tr' | 'fr' | ...
        z_index: 1050                                      # z-index base para sobreposições, popovers e modais (>= 1)
        auto_inject_dialog_host: true                      # Injeta automaticamente o DialogHost Modal/OffCanvas em respostas HTML completas
        dialog_host_profile: 'default'                     # Perfil padrão para o DialogHost injetado automaticamente

        audit_log:
            enabled: false                                 # Registra todas as 9 mutações de arquivos/pastas em um canal PSR-3
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Modelo de IA padrão quando não sobrescrito no perfil
            platform_service: null                         # ID de serviço PlatformInterface personalizado do Symfony AI (null = automático)

        cache:
            enabled: true                                  # Chave global de cache PSR-6 de diretórios/árvore
            pool: 'cache.app'                              # ID do serviço de pool de cache PSR-6 do Symfony
            ttl: 3600                                      # TTL global de cache em segundos (0 = desativado)

        metadata:
            enabled: true                                  # Cores/ícones/fixações de pastas e alt/title/caption/tags/focalPoint de arquivos
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Arquivo de armazenamento do driver json_file
            connection: null                               # Nome opcional da conexão DBAL para o driver doctrine (null = conexão do entity_manager)
            entity_manager: 'default'                      # Nome do EntityManager / conexão para o driver doctrine
            table: 'liveux_fm_metadata'                    # Nome da tabela DBAL para o driver doctrine (veja o Capítulo 13)
            auto_create_table: true                        # Cria automaticamente a tabela de metadados se ausente (defina false ao usar migrations)

    # =========================================================================
    # 2) VOLUMES DE ARMAZENAMENTO — Definições de Sistemas de Arquivos Locais e na Nuvem
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Armazenamento local'                   # Nome exibido na barra lateral e na trilha de navegação
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Caminho físico (suporta {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Prefixo de URL pública do navegador (deixe null quando private: true)
            private: false                                 # true = transmite todos os arquivos pelo DownloadController protegido por RBAC
            cache_ttl: null                                # TTL de cache por volume em segundos (null = herda, 0 = desativado)
            driver_options: []                             # Vazio para LocalFileSystem

        cloud_s3_example:
            label: 'Nuvem S3 / R2 / Supabase'
            icon: 'supabase'                               # Corresponde a resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # URL pública do bucket/CDN (null quando private: true)
            private: false
            cache_ttl: 60                                  # Atualiza alterações externas na nuvem a cada 60 segundos
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Nome do bucket
                region: '%env(AWS_S3_REGION)%'             # Região ('auto' para Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # URL de endpoint S3 personalizado para provedores não-AWS
                prefix: 'media/{Y}'                        # Prefixo raiz opcional dentro do bucket (suporta placeholders dinâmicos)
                use_path_style_endpoint: true              # true para Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Token temporário opcional de sessão AWS STS
                version: 'latest'                          # Versão da API AWS S3 (padrão: 'latest')
                ca_bundle: null                            # Caminho opcional de arquivo .pem CA personalizado (corrige cURL error 60 no Windows)
                disable_acl: true                          # Automático true em endpoints personalizados; defina true para AWS S3 Bucket owner enforced
                retain_visibility: false                   # Automático false em endpoints personalizados (evita envio de x-amz-acl)
                request_checksum_calculation: 'when_required'  # Compatibilidade AWS SDK >= 3.337 (automático em endpoints personalizados)
                response_checksum_validation: 'when_required'  # Compatibilidade AWS SDK >= 3.337 (automático em endpoints personalizados)

        custom_flysystem_example:
            label: 'Arquivo SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # ID do serviço League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) PERFIS — Conjuntos de Regras Independentes por Caso de Uso
    # =========================================================================
    profiles:
        full_reference:
            # --- Volumes Montados, Locale e Opções Simplificadas de Disco Único ---
            locale: null                                   # null = herda defaults.locale | 'pt' | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Quando vazio, um volume implícito é criado a partir de driver/path abaixo
            driver: 'LocalFileSystem'                      # Driver simplificado usado apenas quando volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Caminho simplificado usado apenas quando volumes: []
            url_prefix: '/uploads'                         # Prefixo de URL simplificado usado apenas quando volumes: []
            private: false                                 # Flag de privacidade simplificada usada apenas quando volumes: []
            driver_options: []                             # Opções de driver simplificadas usadas apenas quando volumes: []

            # --- Listagem, Ocultação e Ordenação Padrão ---
            ignore_dot_files: true                         # Oculta e bloqueia arquivos iniciados por ponto (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Padrões regex/curinga ocultados das listagens e bloqueados no acesso direto
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Lista pastas sempre antes dos arquivos

            # --- Envios, Cota e Tratamento de Colisões ---
            upload_subfolder: null                         # ex.: '{Y}/{m}' — coloca envios em subpastas dinâmicas
            upload_allow: []                               # Vazio = todos os tipos seguros; preenchido = lista estrita de tipos MIME permitidos
            upload_deny:                                   # Tipos MIME perigosos sempre bloqueados (47 por padrão se omitido)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Tamanho máximo montado de um único arquivo (ex.: '50M', '2G')
            storage_quota: null                            # Cota total de disco do perfil (ex.: '500M', '5G'; null = ilimitado)
            overwrite_policy: 'rename'                     # rename (anexa -1) | overwrite | reject

            # --- Envio em Partes (Contorna upload_max_filesize do PHP) ---
            chunk_upload:
                enabled: true                              # Divide arquivos grandes em partes retomáveis
                chunk_size: '2M'                           # Tamanho alvo da parte (limitado automaticamente a 80% dos limites do PHP)
                parallel_uploads: 3                        # Máximo de envios simultâneos (1..10)
                max_retries: 3                             # Novas tentativas automáticas por parte em erros de rede (0..10)

            # --- Detecção de Duplicidade (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Detecta conteúdo duplicado no envio
                policy: 'warn'                             # warn | reject | reuse (retorna o arquivo existente)
                max_scan_files: 5000                       # Máximo de arquivos com hash calculado para indexação de duplicidade (100..50000)

            # --- Normalização Automática de Nomes de Arquivo ---
            naming:
                slugger: true                              # Translitera nomes Unicode em slugs ASCII limpos
                slugger_locale: null                       # null = herda o locale do perfil (ex.: 'pt', 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Prefixo estático/dinâmico (ex.: 'produto-')
                append: ''                                 # Sufixo estático/dinâmico antes da extensão (ex.: '-{Y}{m}{d}')
                apply_on_rename: true                      # Aplica regras de slugger/transformer ao renomear
                apply_affixes_on_rename: false             # Reaplica afixos prepend/append ao renomear
                allow_extension_change: false              # Permite que usuários alterem a extensão (.jpg -> .php) ao renomear

            # --- Otimização de Imagens no Envio ---
            image_optimization:
                enabled: false                             # Corrige orientação, remove EXIF, redimensiona e comprime no envio
                auto_orient: true                          # Corrige orientação EXIF de smartphones
                strip_exif: true                           # Remove dados EXIF de GPS e câmera para privacidade e economia de tamanho
                max_width: 2400                            # Largura máxima proporcional em px (>= 100)
                max_height: 2400                           # Altura máxima proporcional em px (>= 100)
                convert_to_webp: false                     # Converte automaticamente envios JPEG/PNG para WebP
                quality: 85                                # Qualidade de saída (10..100)

            # --- Regras de Resolução e Proporção de Imagem ---
            image_constraints:
                min_width: null                            # Largura mínima em px (ex.: 800)
                min_height: null                           # Altura mínima em px (ex.: 600)
                max_width: null                            # Largura máxima em px (verificada após image_optimization)
                max_height: null                           # Altura máxima em px
                aspect_ratio: null                         # Proporção exigida (ex.: '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Desvio de proporção permitido (0.0..0.5; 0.05 = 5%)

            # --- Motor de Miniaturas ---
            thumbnail:
                enabled: true                              # Gera pré-visualizações em cache para os cartões de imagem
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Largura da miniatura em px (>= 32)
                height: 240                                # Altura da miniatura em px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Qualidade de compressão (10..100)
                fit: 'contain'                             # contain (caber por inteiro) | cover (recortar para preencher)
                lazy_load: true                            # Adiciona loading="lazy" às miniaturas dos cartões
                cache_dir: '%kernel.project_dir%/public/media-cache' # Diretório local de cache para miniaturas geradas

            # --- Lixeira (Exclusão Temporária) ---
            trash:
                enabled: true                              # Move itens excluídos para a lixeira em vez de excluir permanentemente
                directory: '.trash'                        # Nome do diretório oculto da lixeira
                retention_days: 30                         # Período de retenção em dias para file-manager:trash:purge
                max_size: '500M'                           # Tamanho máximo total da lixeira

            # --- Download ZIP e Extração Segura de Arquivos Compactados ---
            zip:
                enabled: true                              # Habilita downloads ZIP de múltiplos arquivos/pastas
                max_files: 100                             # Máximo de arquivos em um único download ZIP
                max_total_size: '250M'                     # Tamanho máximo total para um único download ZIP
                allow_extract: false                       # Habilita descompactação (desativada por padrão; proteção em 7 camadas)
                max_extract_files: 200                     # Máximo de arquivos extraídos por arquivo compactado (1..2000)
                max_extract_size: '100M'                   # Tamanho total máximo extraído (proteção contra Zip-Bomb)
                max_compression_ratio: 100                 # Taxa máxima de compressão permitida (1..1000 — proteção contra Zip-Bomb)

            # --- Links de Compartilhamento Assinados com Prazo (HMAC) ---
            share:
                enabled: true                              # Permite gerar URLs de compartilhamento assinadas
                default_ttl: 86400                         # Validade padrão em segundos (86400 = 24 horas)
                max_ttl: 2592000                           # Validade máxima selecionável em segundos (2592000 = 30 dias)
                allow_inline_preview: true                 # Permite pré-visualização inline no navegador para tipos MIME seguros

            # --- Enriquecimento Opcional com Symfony AI ---
            ai:
                enabled: false                             # Exibe "✨ Sugerir com IA" no modal de metadados
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = locale do perfil/requisição (ex.: 'pt' => texto alternativo/etiquetas em português)
                max_tags: 6                                # Máximo de etiquetas sugeridas (1..20)
                max_image_dimension: 1024                  # Limite de redimensionamento antes do envio à IA (256..2048 px)
                block_private_volumes: true                # true = bloqueia chamadas de IA para volumes private: true (padrão: true)
                auto_enrich_on_upload: false               # Executa enriquecimento com IA automaticamente após envios

            # --- Proteção de Uso de Ativos (Excluir / Renomear / Mover / Sobrescrever) ---
            usage_guard:
                enabled: false                             # Verifica referências no banco de dados antes de alterar ativos
                policy: 'warn'                             # warn (mostra referências + permite prosseguir) | block (impede a ação)
                enforce_server_side: true                  # Também aplica a proteção em chamadas diretas à API
                max_references: 20                         # Máximo de referências listadas no modal (1..200)

            # --- Cache por Perfil ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | segundos (0 = desativado)

            # --- Opções de Segurança ---
            security:
                voter_enabled: true                        # Ativa verificações RBAC do FileManagerVoter
                csrf_protection: true                      # Exige X-LiveUX-FM-CSRF em solicitações POST
                sanitize_svg: true                         # Sanitiza SVGs enviados/editados
                allow_remote_url_import: false             # Ativa a aba "De uma URL" (protegida contra SSRF)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 Permissões RBAC Granulares (true = usuário autenticado | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Permissão principal exigida para listagem e todas as demais ações
                upload: true                               # Envio de arquivos, salvamento do editor de imagens e importação de URLs
                create_folder: true                        # Criar novas pastas
                rename: true                               # Renomear arquivos e pastas
                clone: true                                # Duplicar arquivos/pastas
                move: true                                 # Mover itens (incluindo arrastar e soltar e recortar/colar)
                copy: true                                 # Copiar itens (incluindo copiar/colar)
                delete: true                               # Excluir itens (lixeira ou exclusão permanente)
                trash_restore: true                        # Restaurar itens da lixeira
                trash_purge: true                          # Excluir permanentemente itens / esvaziar lixeira
                download: true                             # Download de arquivo único e copiar link
                download_zip: true                         # Download ZIP de múltiplos arquivos
                extract_zip: false                         # Descompactar arquivos ZIP (também exige zip.allow_extract: true)
                share: true                                # Criar links de compartilhamento assinados
                clear_cache: true                          # Limpar cache em Configurações e forçar ?refresh=1
                edit_metadata: true                        # Editar alt/etiquetas/ponto focal de arquivos e cor/ícone/fixação de pastas
                ai_enrich: true                            # Executar enriquecimento de metadados por IA

            # --- Flags e Dimensionamento da Interface (UI) ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <diretório personalizado>
                z_index: null                              # null = defaults.z_index | inteiro (>= 1)
                mobile_dialog_mode: 'auto'                 # Comportamento do diálogo no celular (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px compacto)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (linha compacta) | card (cartão de pasta completo)
                show_size_slider: true                     # Mostra controle deslizante de tamanho e alternância de estilo de pasta em Configurações
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Mostra a árvore de pastas na barra lateral esquerda
                tree_lines: true                           # Mostra linhas conectoras de hierarquia na árvore de pastas
                show_folder_item_count: true               # Mostra contadores de itens nas pastas
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Mostra medidor de cota na barra lateral e selo de cota no cabeçalho
                show_quota_details: true                   # Habilita o modal clicável de Uso de armazenamento e cota
                per_page: 24                               # Itens por página padrão (6..200)
                per_page_options: [24, 48, 96]             # Opções do seletor de itens por página
                show_perf_badge: false                     # Mostra selo de desempenho CACHE / DISCO na barra de ferramentas
                show_nav_buttons: true                     # Chave geral do grupo Voltar / Avançar / Pasta superior / Atualizar
                show_history_buttons: true                 # Mostra botões de histórico Voltar / Avançar
                show_up_button: true                       # Mostra botão Pasta superior
                show_refresh_button: true                  # Mostra botão Atualizar (?refresh=1)
                show_breadcrumbs: true                     # Mostra trilha de navegação clicável/receptora de arraste
                show_search: true                          # Mostra campo de pesquisa e alternância de pesquisa profunda
                show_type_filter: true                     # Mostra filtro por tipo (Imagens / Vídeos / Arquivos / Pastas)
                show_view_toggle: true                     # Mostra alternância Grade / Lista
                show_pagination: true                      # Mostra barra inferior de paginação
                show_per_page_selector: true               # Mostra seletor de itens por página
                show_sort: true                            # Mostra seletor de ordenação e alternância de direção
                show_fullscreen_button: true               # Mostra botão de tela cheia
                show_settings_button: true                 # Mostra menu de Configurações (⚙)
                show_footer: true                          # Mostra barra de status inferior
                show_context_nav: true                     # Mostra faixa de navegação rápida no menu de contexto da área vazia
                allow_dropzone_upload: true                # Permite arrastar arquivos/pastas do sistema operacional para a listagem
                remember_last_dir: true                    # Lembra o último volume e pasta (recupera automaticamente se excluídos)
                confirm_delete: true                       # Exibe modal de confirmação antes de excluir
                confirm_move: true                         # Exibe modal de confirmação antes de mover/copiar
                double_click_to_select: true               # Clique duplo seleciona o arquivo e fecha o seletor Modal/OffCanvas
                allow_context_menu: true                   # Ativa menu de contexto com botão direito
                allow_pdf_preview: true                    # Lightbox inline de PDF (false força download)
                allow_video_preview: true                  # Player inline de vídeo (false força download)
                keyboard_shortcuts: true                   # Ativa atalhos de teclado com escopo
                allow_drag_move: true                      # Permite arrastar itens para dentro de pastas para movê-los
```

---

[← Anterior: 01 — Requisitos e Instalação](01-requirements-and-installation.md) | [Índice da Documentação](README.md) | [Próximo: 03 — Modos de Uso e API JS →](03-usage-modes-and-js-api.md)
