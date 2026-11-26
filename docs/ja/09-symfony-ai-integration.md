# 09 — Symfony AI 連携とスマートアセットメタデータ補完

[← 前へ: 08 — イベント・コマンド・トラブルシューティング](08-events-commands-cache-and-troubleshooting.md) | [目次](README.md) | [次へ: 10 — アセット使用状況ガード →](10-asset-usage-guard.md)

> [!TIP]
> **わかりやすく言うと、何ができる機能ですか？**
> 編集者が商品写真をアップロードするたびに、1 枚ずつ手作業で「代替テキスト・タイトル・キャプション・検索タグ」を入力する必要はもうありません。インスペクター（「プレビューと詳細」パネル）で **「✨ AI で自動提案」** をクリックすると、選択したモデル（OpenAI、Gemini、Claude、またはローカル Ollama）が画像を解析し、指定言語で SEO に最適化されたメタデータと `#タグ` を約 2 秒で自動入力します。提案内容は編集者が確認・承認するまで保存されません。
> *（デフォルトでは `block_private_volumes: true` のため、プライベートボリュームのファイルが外部 AI プロバイダーへ送信されることはありません。）*

**LiveUX FileManagerBundle** は、公式の **[Symfony AI](https://github.com/symfony/ai)**（`symfony/ai-bundle` + `symfony/ai-platform`）エコシステムと **必須依存ゼロ** で連携します：

- `symfony/ai-bundle` が **未インストールの場合**、バンドルはエラーもメモリオーバーヘッドもなく動作し、UI 上の AI ボタンは自動的に非表示になります。
- `symfony/ai-bundle` が **インストールされている場合**、`FileManagerBundle` は組み込みの `SymfonyAiAssetEnricher` ブリッジを自動的に検出して接続します。
- AI 機能は **プロファイルごとに明示的に有効化（`ai.enabled: true`）しない限り無効のまま** となるため、意図しない API 課金が発生することはありません。

---

## 1. 主な機能

| 機能 | 説明 |
| :--- | :--- |
| **SEO・WCAG 対応の `alt` テキスト自動生成** | 画像を解析し、スクリーンリーダーや SEO に適した代替テキスト（`alt`）、簡潔な `title`、`caption` を任意の言語（`ja`、`en`、`tr`、`fr`、`de`、`ar` 等）で生成します。 |
| **スマート自動タグ付け（`tags`）** | 画像やテキストファイルから検索に役立つキーワード（例: `["保温ボトル", "マットブラック", "法人ギフト"]`）を抽出し、ファイルマネージャーの `#タグ` 検索で即座にヒットするようにします。 |
| **テキスト・ドキュメント要約** | `.txt`、`.md`、`.csv`、`.json`、`.yaml`/`.yml` ファイルの先頭 8 KB を読み取り、タイトル・説明・検索タグを提案します。 |
| **トークンコストの自動最適化** | 10〜15 MB の高解像度写真をそのまま Vision API に送ることはありません。送信前に一時コピーを `max_image_dimension`（デフォルト `1024px`）へ自動リサイズし、トークン消費量を約 80% 削減します。 |
| **プロバイダー非依存設計** | YAML を 1 行書き換えるだけで、OpenAI、Google Gemini、Anthropic Claude、Mistral、VertexAI、または **完全ローカル＆無料の Ollama** を自在に切り替えられます。 |
| **3 つのワークフロー** | UI 上で編集者が確認しながら実行する **「✨ AI で自動提案」**、既存アーカイブを一括処理する CLI（`file-manager:ai:enrich`）、`symfony/messenger` による非同期バックグラウンド処理の 3 通りに対応しています。 |

---

## 2. インストール（利用するプロバイダーを選択）

### 選択肢 A — OpenAI（`gpt-4o-mini` / `gpt-4.1-mini`）

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

### 選択肢 B — Google Gemini（`gemini-2.5-flash`）

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

### 選択肢 C — Anthropic Claude（`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`）

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

### 選択肢 D — ローカル・完全プライベートな Ollama（`qwen2.5vl` / `llava`）

社外秘のドキュメントや画像を自社インフラの外へ一切出せない環境に最適です：

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

## 3. LiveUX FileManagerBundle の設定

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'ja'
        ai:
            default_model: 'gpt-4o-mini'
            # 任意: 複数の Symfony AI プラットフォームを設定している場合、特定のサービスを指定可能
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # デフォルト: false（課金防止）
                model: 'gpt-4o-mini'              # 例: 'gemini-2.5-flash'、'qwen2.5vl'
                locale: 'ja'                      # null の場合は現在のリクエスト / プロファイルのロケールを継承
                max_tags: 6                       # タグ上限数（1..20）
                max_image_dimension: 1024         # Vision API 送信前のリサイズ上限（256..2048 px）
                block_private_volumes: true       # private: true のボリュームでは外部 AI 呼び出しを遮断
                auto_enrich_on_upload: false      # true にするとアップロード時に同期実行。Messenger 利用時は false を維持
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # 機密契約書向けプロファイルでは完全無効化
```

### AI 出力言語の解決順序（`ai.locale`）

編集者が **✨ AI で自動提案** をクリックした際、生成言語は次の優先順位で決定されます（BCP-47、ICU、POSIX `.UTF-8` 形式に対応）：

1. リクエストボディで明示指定された `aiLocale`
2. プロファイル設定 `profiles.<name>.ai.locale` *（設定されている場合は UI ロケールより優先されます）*
3. 現在の HTTP リクエスト / UI ロケール
4. プロファイル設定 `profiles.<name>.locale`
5. `'en'` *（保証フォールバック）*

---

## 4. UI からの利用手順（ワンクリック・編集者承認制）

1. ファイルマネージャー上で画像またはテキストファイルを右クリック → **🏷️ SEO とフォーカルポイント**（テキストファイルの場合は **詳細情報とタグ**）を選択するか、右側のインスペクター（「プレビューと詳細」）パネルを開きます。
2. **✨ AI で自動提案** ボタンは、次の **5 条件すべて** を満たす場合のみ表示され、サーバー側でも同じ条件が厳格に検証されます：
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - 有効な `AssetEnricherInterface` サービスが登録されている
   - 対象ファイルおよびすべての親フォルダーに対して **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** の 3 権限すべてを保有している
   - `private: true` のボリュームである場合、`block_private_volumes: false` が明示的に設定されている
3. ボタンをクリックしても既存のメタデータが即座に上書きされることはありません。AI が提案した `alt`、`title`、`caption`、`tags` がフォーム欄に仮入力されるため、編集者が内容を確認・調整してから **保存** をクリックします。

---

## 5. CLI による既存アーカイブの一括メタデータ補完

コスト保護機能を備えた CLI コマンドで、数千件の既存アセットに対して不足している `alt` テキストやタグを一括生成できます：

```bash
# 1. AI API を呼び出さずに対象ファイルを事前確認（無料のドライラン）
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. メタデータが未設定の先頭 50 件を日本語で補完
php bin/console file-manager:ai:enrich products --limit=50 --locale=ja

# 3. 特定のボリュームおよびサブフォルダーに絞り込んで実行
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. 既存のメタデータも強制的に再生成（--force）
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **CLI の安全・コスト保護ガードレール（`file-manager:ai:enrich`）:**
> - 誤ってアーカイブ全体を有料 API へ一括送信しないよう、`--limit` のデフォルト値は **`50`** に設定されています。
> - `--force` を指定しない限り、**編集用メタデータが不足しているファイルのみ** が処理されます（`alt` が入力済み **かつ** `tags` / `title` / `caption` のいずれか 1 つ以上が入力済みの場合はスキップされます）。
> - メタデータの事前読み込み（`preload()`）は **250 件ずつのバッチ** で実行され、**20 MB 超** のファイル、`{user_id}` 動的ボリューム、および `private: true` ボリューム（`block_private_volumes: true` の場合）は自動的にスキップされます。

---

## 6. アップロード時の自動非同期補完（`symfony/messenger`）

> [!IMPORTANT]
> `ai.auto_enrich_on_upload: true` を設定すると、組み込みの `MetadataSyncListener` がアップロード HTTP リクエスト内で AI プロバイダーを **同期的に** 呼び出します。アップロード応答の遅延を避けるため、本番環境では `ai.auto_enrich_on_upload: false` のままにし、Symfony Messenger による非同期処理を推奨します。

### ステップ 1 — Messenger メッセージクラス

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

### ステップ 2 — `FileUploadedEvent` リスナー

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
        // 同期的な auto_enrich_on_upload が有効な場合の二重実行を防止
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // 200 ファイル入りの ZIP 展開時などに AI キューが溢れるのを防止
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

### ステップ 3 — Messenger ワーカーハンドラー

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
            skipVoter: true, // バックグラウンドワーカーにはアクティブな HTTP ユーザーセッションがないため指定
        );
    }
}
```

> [!WARNING]
> **ワーカー実行時の注意点:**
> 1. Messenger ワーカーは認証済みユーザーセッションなしで実行されるため、`{user_id}` 動的ボリュームは `guest` に解決されます。ユーザーごとの `{user_id}` ボリュームに対して非同期補完をディスパッチしないでください。
> 2. `skipVoter: true` を指定しても、`block_private_volumes: true` の保護機構は引き続き有効であり、`private: true` ボリュームに対しては `AccessDeniedException` をスローします。

---

## 7. カスタム AI プロバイダーの実装（`AssetEnricherInterface`）

`symfony/ai-bundle` を導入せず、独自の Python マイクロサービス、AWS Rekognition、Azure AI Vision、その他任意の HTTP API と連携させることも可能です：

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
        string $locale = 'ja',
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

`#[AsAlias(AssetEnricherInterface::class)]` を付与するだけで、UI の **✨ AI で自動提案** ボタンと `file-manager:ai:enrich` CLI コマンドの両方が自動的にカスタムサービスを利用します。

---

## 8. セキュリティ・プロンプトインジェクション対策・LLM 拒否応答フィルター

1. **プライベートファイル（`private: true`）:** クラウド LLM を使用する場合は `block_private_volumes: true` を維持するか、セルフホストの **Ollama** インスタンスへ切り替えてください。
2. **認可制御:** 有料 API の利用を `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` で制限してください（`VIEW` + `EDIT_METADATA` + `AI_ENRICH` の 3 権限が同時に検証されます）。
3. **コスト管理:** `max_image_dimension: 1024`（または `768`）を維持し、CLI のバッチ実行前には必ず `--dry-run` と `--limit` で対象件数を確認してください。
4. **プロンプトインジェクション＆多言語拒否応答フィルタリング:**
   - ファイル名はプロンプトへ組み込まれる前に制御文字・双方向オーバーライド文字・引用符が除去され、**120 文字** 以内に切り詰められます。
   - `SymfonyAiAssetEnricher::parseJsonResponse()` は **11 言語**（EN、TR、FR、DE、ES、AR、FA、RU、JA、ZH、KO）にわたるモデルの拒否応答（例：「申し訳ありませんが、この画像を解析できません…」など）を検出し、拒否メッセージが誤って `alt` メタデータとして保存されるのを防ぎます。
   - 返却されたすべてのテキストは `AssetEnrichmentResult` と `FolderMetadata::sanitizeTags()` によってサニタイズされ、DOM 出力時にも必ず HTML エスケープされます。

---

[← 前へ: 08 — イベント・コマンド・トラブルシューティング](08-events-commands-cache-and-troubleshooting.md) | [目次](README.md) | [次へ: 10 — アセット使用状況ガード →](10-asset-usage-guard.md)
