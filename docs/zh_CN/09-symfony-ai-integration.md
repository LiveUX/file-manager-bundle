# 09 — Symfony AI 集成与智能资产元数据增强

[← 上一篇：08 — 事件、CLI 命令与常见问题](08-events-commands-cache-and-troubleshooting.md) | [文档首页](README.md) | [下一篇：10 — 资产引用保护 →](10-asset-usage-guard.md)

> [!TIP]
> **通俗来说：这个功能解决什么问题？**
> 当编辑人员上传商品照片时，不再需要逐张手动编写“替代文本（alt）、标题、说明和搜索标签”。只需在 **预览与详情** 面板中点击 **“✨ AI 智能建议”**，你选择的模型（OpenAI、Gemini、Claude 或本地部署的 Ollama）就会在约 2 秒内识别图片内容，并按目标语言自动填好对 SEO 友好的元数据与 `#标签`——而且在编辑审核并点击保存前，系统不会覆盖任何现有数据。
> *（默认情况下，私有存储卷绝不会发送到外部 AI 服务商：`block_private_volumes: true`。）*

**LiveUX FileManagerBundle** 与官方 **[Symfony AI](https://github.com/symfony/ai)**（`symfony/ai-bundle` + `symfony/ai-platform`）生态无缝集成，且**无任何强制依赖**：

- 若**未安装** `symfony/ai-bundle`，本 Bundle 仍可零报错、零额外内存开销正常运行，界面上的 AI 按钮也会自动隐藏。
- 若**已安装** `symfony/ai-bundle`，`FileManagerBundle` 会自动发现并装配内置的 `SymfonyAiAssetEnricher` 桥接服务。
- AI 功能默认关闭，**仅在单个配置文件中显式启用（`ai.enabled: true`）后才会生效**——避免产生意外的 API 账单。

---

## 1. 核心能力

| 能力 | 说明 |
| :--- | :--- |
| **自动化 SEO 与 WCAG `alt` 文本** | 智能分析图片内容，生成符合屏幕阅读器与 SEO 规范的 `alt` 替代文本、简洁 `title` 标题以及 `caption` 说明，支持任意语言（`zh_CN`、`en`、`tr`、`fr`、`de`、`ja`、`ar` 等）。 |
| **智能自动打标（`tags`）** | 从图片或文本文件中提取高相关性关键词（例如 `["保温杯", "磨砂黑", "企业定制礼品"]`），保存后即可直接用于文件管理器的 `#标签` 检索。 |
| **文本与文档摘要** | 读取 `.txt`、`.md`、`.csv`、`.json` 及 `.yaml`/`.yml` 文件的前 8 KB 内容，自动建议标题、描述和搜索标签。 |
| **自动 Token 成本优化** | 绝不会直接将 10–15 MB 的原图发送给视觉 API；系统会先生成临时缩放副本至 `max_image_dimension`（默认 `1024px`），可节省约 80% 的 Token 消耗。 |
| **服务商无关架构** | 只需修改一行 YAML 配置，即可在 OpenAI、Google Gemini、Anthropic Claude、Mistral、VertexAI 或 **100% 本地免费的 Ollama** 之间自由切换。 |
| **三种工作流** | 界面一键 **“✨ AI 智能建议”**（人工审核后保存）、命令行批量处理存量资产（`file-manager:ai:enrich`），或配合 `symfony/messenger` 实现上传后后台异步增强。 |

---

## 2. 安装（按需选择 AI 服务商）

### 方案 A — OpenAI（`gpt-4o-mini` / `gpt-4.1-mini`）

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

### 方案 B — Google Gemini（`gemini-2.5-flash`）

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

### 方案 C — Anthropic Claude（`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`）

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

### 方案 D — 100% 私有化本地 Ollama（`qwen2.5vl` / `llava`）

非常适合对数据隐私有严格要求、机密企业文档不能离开内网的场景：

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

## 3. LiveUX FileManagerBundle 配置

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'zh_CN'
        ai:
            default_model: 'gpt-4o-mini'
            # 可选：如果项目中配置了多个 Symfony AI 平台，可指定具体平台服务 ID
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # 默认值：false（成本保护）
                model: 'gpt-4o-mini'              # 例如 'gemini-2.5-flash'、'qwen2.5vl'
                locale: 'zh_CN'                   # 设为 null 时自动继承当前请求或配置文件语言
                max_tags: 6                       # 标签数量上限（1..20）
                max_image_dimension: 1024         # 发送给视觉 API 前的最大缩放边长（256..2048 px）
                block_private_volumes: true       # 禁止对 private: true 存储卷发起外部 AI 调用
                auto_enrich_on_upload: false      # true 表示上传时同步执行；使用 Messenger 时请保持 false
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # 对敏感合同目录完全禁用 AI
```

### AI 输出语言解析顺序（`ai.locale`）

当编辑人员点击 **✨ AI 智能建议** 时，系统严格按以下优先级决定输出语言（支持 BCP-47、ICU 与 POSIX `.UTF-8` 格式）：

1. 请求体中显式传入的 `aiLocale`
2. 配置文件项 `profiles.<name>.ai.locale` *（设置后优先级高于界面语言！）*
3. 当前 HTTP 请求 / 界面语言
4. 配置文件项 `profiles.<name>.locale`
5. `'en'` *（最终兜底语言）*

---

## 4. 在界面中使用 AI（一键生成、人工审核后保存）

1. 在文件管理器中右键点击图片或文本文件 → **🏷️ SEO 与焦点** / **信息与标签**（或直接打开右侧 **预览与详情** 面板）。
2. 只有当以下 **5 个条件全部满足** 时，**✨ AI 智能建议** 按钮才会渲染，且服务端也会同步执行严格校验：
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - 容器中存在可用的 `AssetEnricherInterface` 服务
   - 当前用户对该文件及其所有父级文件夹同时拥有 **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** 三项权限
   - 若所属存储卷为 `private: true`，必须显式配置了 `block_private_volumes: false`
3. 点击按钮后**不会立即覆盖**已有元数据；AI 生成的 `alt`、`title`、`caption` 和 `tags` 会填入表单输入框中，供编辑人员审阅、微调后点击 **保存**。

---

## 5. 使用 CLI 批量增强存量资产库

在严格的成本护栏保护下，为成千上万份既有资产自动补全缺失的 `alt` 文本和标签：

```bash
# 1. 试运行（Dry Run）：预览哪些文件将被处理，不实际调用 AI API（零费用）：
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. 为前 50 个缺失元数据的文件生成简体中文元数据：
php bin/console file-manager:ai:enrich products --limit=50 --locale=zh_CN

# 3. 限定特定的存储卷与子目录：
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. 强制重新生成已有元数据（--force）：
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **CLI 安全与成本护栏（`file-manager:ai:enrich`）：**
> - `--limit` 默认为 **`50`**，防止误操作一次性将整个素材库提交给付费 API。
> - 未传 `--force` 时，仅处理**缺失编辑元数据**的文件（若 `alt` 非空**且** `tags`/`title`/`caption` 至少有一项非空，则自动跳过）。
> - 元数据预加载（`preload()`）以每批 **250 项** 分批执行；超过 **20 MB** 的文件、`{user_id}` 动态存储卷以及（当 `block_private_volumes: true` 时的）`private: true` 存储卷均会自动跳过。

---

## 6. 上传时后台自动异步增强（`symfony/messenger`）

> [!IMPORTANT]
> 将 `ai.auto_enrich_on_upload` 设为 `true` 会导致内置的 `MetadataSyncListener` 在上传 HTTP 请求中**同步**调用 AI 服务商。为避免拖慢上传响应速度，建议保持 `ai.auto_enrich_on_upload: false`，并通过 Symfony Messenger 异步处理。

### 第 1 步 — 定义 Messenger 消息

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

### 第 2 步 — 监听 `FileUploadedEvent` 并派发消息

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
        // 若已开启同步 auto_enrich_on_upload，避免重复触发：
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // 解压包含 200 个文件的 ZIP 压缩包时，避免瞬间灌满 AI 队列：
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

### 第 3 步 — 编写 Messenger Worker 处理器

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
            skipVoter: true, // 后台 Worker 进程没有活跃的 HTTP 用户会话
        );
    }
}
```

> [!WARNING]
> **后台 Worker 注意事项：**
> 1. 由于 Messenger Worker 运行时没有登录用户会话，`{user_id}` 动态存储卷会解析为 `guest`——请勿对按用户隔离的 `{user_id}` 存储卷派发异步增强任务。
> 2. 即使传入 `skipVoter: true`，`block_private_volumes: true` 安全护栏依然生效，遇到 `private: true` 存储卷时会抛出 `AccessDeniedException`。

---

## 7. 编写自定义 AI 服务提供者（`AssetEnricherInterface`）

无需安装 `symfony/ai-bundle`，你也可以直接对接自研 Python 视觉微服务、AWS Rekognition、Azure AI Vision 或任意自定义 HTTP 接口：

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
        string $locale = 'zh_CN',
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

加上 `#[AsAlias(AssetEnricherInterface::class)]` 后，界面中的 **✨ AI 智能建议** 按钮和 `file-manager:ai:enrich` CLI 命令都会自动切换为你的自定义服务。

---

## 8. 安全性、提示词注入与大模型拒答防护

1. **私有文件保护（`private: true`）：** 使用云端大模型时请保持 `block_private_volumes: true`，或改用内网自托管的 **Ollama** 实例。
2. **细粒度权限控制：** 通过 `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` 限制付费 API 的调用权限（服务端会同时校验 `VIEW` + `EDIT_METADATA` + `AI_ENRICH`）。
3. **成本控制：** 建议将 `max_image_dimension` 保持在 `1024`（或 `768`），运行 CLI 批量任务前务必先使用 `--dry-run` 与 `--limit` 预览。
4. **提示词注入与多语言拒答过滤：**
   - 文件名在拼入 Prompt 之前，会先剥离控制字符、双向文本控制符和引号，并截断至最多 **120 个字符**。
   - `SymfonyAiAssetEnricher::parseJsonResponse()` 内置覆盖 **11 种语言**（EN、TR、FR、DE、ES、AR、FA、RU、JA、ZH、KO）的模型拒答识别规则——例如 *“很抱歉，我无法分析这张图片……”*——杜绝将拒答文案误存为 `alt` 元数据。
   - 所有返回文本都会经 `AssetEnrichmentResult` 和 `FolderMetadata::sanitizeTags()` 清洗，并在前端 DOM 中严格执行 HTML 转义。

---

[← 上一篇：08 — 事件、CLI 命令与常见问题](08-events-commands-cache-and-troubleshooting.md) | [文档首页](README.md) | [下一篇：10 — 资产引用保护 →](10-asset-usage-guard.md)
