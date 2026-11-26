# 09 — التكامل مع Symfony AI والإثراء الذكي للأصول

[→ السابق: 08 — الأحداث، الأوامر واستكشاف الأخطاء](08-events-commands-cache-and-troubleshooting.md) | [الفهرس الرئيسي](README.md) | [التالي: 10 — حماية استخدام الأصول ←](10-asset-usage-guard.md)

> [!TIP]
> **ببساطة: ماذا تفعل هذه الميزة؟**
> عندما يرفع المحرّر صورة منتج، لم يعد مضطرًا لكتابة *«النص البديل (alt)، والعنوان، والوصف، ووسوم البحث»* يدويًا لكل ملف. بالنقر على **«✨ اقتراح بالذكاء الاصطناعي»** في لوحة الفاحص (Inspector)، يحلّل النموذج الذي تختاره (OpenAI أو Gemini أو Claude أو Ollama المحلي) الصورة ويملأ البيانات الوصفية الصديقة لمحركات البحث ووسوم `#tags` باللغة المستهدفة خلال ثانيتين تقريبًا — ولا يُحفظ أي شيء إلا بعد أن يراجعه المحرّر ويوافق عليه.
> *(بشكل افتراضي، لا تُرسل وحدات التخزين الخاصة أبدًا إلى مزوّدي ذكاء اصطناعي خارجيين: `block_private_volumes: true`.)*

تتكامل **LiveUX FileManagerBundle** مع منظومة **[Symfony AI](https://github.com/symfony/ai)** الرسمية (`symfony/ai-bundle` + `symfony/ai-platform`) **بدون أي تبعيات إلزامية**:

- إذا **لم تكن `symfony/ai-bundle` مثبّتة**، تعمل الحزمة دون أي أخطاء ودون أي استهلاك إضافي للذاكرة، وتُخفى أزرار الذكاء الاصطناعي تلقائيًا في الواجهة.
- إذا **كانت `symfony/ai-bundle` مثبّتة**، يكتشف `FileManagerBundle` تلقائيًا جسر `SymfonyAiAssetEnricher` المدمج ويربطه.
- تبقى ميزات الذكاء الاصطناعي **معطّلة ما لم تُفعّلها صراحةً لكل ملف تعريف (`ai.enabled: true`)** — حتى لا تتفاجأ بأي فواتير API غير متوقعة.

---

## 1. القدرات الرئيسية

| القدرة | الوصف |
| :--- | :--- |
| **نصوص `alt` تلقائية متوافقة مع SEO و WCAG** | يحلّل الصور ويولّد نص `alt` جاهزًا لقارئات الشاشة ومحركات البحث، مع عنوان موجز `title` ووصف `caption` بأي لغة (`ar`, `en`, `tr`, `fr`, `de`, `ja` وغيرها). |
| **الوسم التلقائي الذكي (`tags`)** | يستخلص كلمات مفتاحية وصفية من الصور أو الملفات النصية (مثل `["قارورة حرارية", "أسود مطفي", "هدية شركات"]`) لتعمل فورًا مع البحث عبر `#tag` في مدير الملفات. |
| **تلخيص النصوص والمستندات** | يقرأ أول 8 كيلوبايت من ملفات `.txt` و `.md` و `.csv` و `.json` و `.yaml`/`.yml` لاقتراح العناوين والأوصاف ووسوم البحث. |
| **تحسين تلقائي لتكلفة التوكنات (Tokens)** | لا تُرسل الصور الضخمة (10–15 ميجابايت) بصيغتها الخام إلى واجهات الرؤية (Vision APIs)؛ بل تُصغَّر نسخة مؤقتة أولًا إلى `max_image_dimension` (الافتراضي `1024px`)، مما يخفض تكلفة التوكنات بنحو 80%. |
| **استقلالية المزوّد** | انتقل بين OpenAI أو Google Gemini أو Anthropic Claude أو Mistral أو VertexAI أو **Ollama المحلي المجاني 100%** بتعديل سطر YAML واحد فقط. |
| **3 مسارات عمل** | زر **«✨ اقتراح بالذكاء الاصطناعي»** بموافقة المحرّر في الواجهة، أو الإثراء الدفعي للأرشيف عبر سطر الأوامر (`file-manager:ai:enrich`)، أو المعالجة التلقائية في الخلفية عبر `symfony/messenger`. |

---

## 2. التثبيت (اختر مزوّدك)

### الخيار أ — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)

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

### الخيار ب — Google Gemini (`gemini-2.5-flash`)

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

### الخيار ج — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)

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

### الخيار د — Ollama محلي وخاص بنسبة 100% (`qwen2.5vl` / `llava`)

مثالي عندما يجب ألا تغادر مستندات الشركة السرية بنيتك التحتية أبدًا:

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

## 3. إعداد LiveUX FileManagerBundle

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'ar'
        ai:
            default_model: 'gpt-4o-mini'
            # اختياري: تحديد خدمة منصة معيّنة في حال إعداد عدة منصات Symfony AI
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # الافتراضي: false (لحماية التكاليف)
                model: 'gpt-4o-mini'              # مثل 'gemini-2.5-flash' أو 'qwen2.5vl'
                locale: 'ar'                      # القيمة null ترث لغة الطلب أو ملف التعريف النشط
                max_tags: 6                       # الحد الأقصى للوسوم (1..20)
                max_image_dimension: 1024         # سقف أبعاد الصورة قبل إرسالها إلى Vision API (256..2048 px)
                block_private_volumes: true       # يمنع استدعاءات الذكاء الاصطناعي الخارجية على الوحدات ذات private: true
                auto_enrich_on_upload: false      # true يعمل بشكل متزامن عند الرفع؛ اتركه false عند استخدام Messenger
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # معطّل بالكامل للعقود الحساسة
```

### ترتيب حسم لغة مخرجات الذكاء الاصطناعي (`ai.locale`)

عندما ينقر المحرّر على **✨ اقتراح بالذكاء الاصطناعي**، تُحدَّد لغة الاستجابة وفق هذا الترتيب الدقيق (مع دعم صيغ BCP-47 و ICU و POSIX `.UTF-8`):

1. قيمة `aiLocale` المرسلة صراحةً في جسم الطلب
2. إعداد ملف التعريف `profiles.<name>.ai.locale` *(يتجاوز لغة الواجهة عند ضبطه!)*
3. لغة طلب HTTP / الواجهة النشطة
4. إعداد ملف التعريف `profiles.<name>.locale`
5. `'en'` *(لغة احتياطية مضمونة)*

---

## 4. استخدام الذكاء الاصطناعي في الواجهة (بنقرة واحدة وبموافقة المحرّر)

1. انقر بزر الفأرة الأيمن على صورة أو ملف نصي في مدير الملفات ← **🏷️ البيانات الوصفية ونقطة التركيز** (أو افتح لوحة الفاحص الجانبية).
2. يظهر زر **✨ اقتراح بالذكاء الاصطناعي** — ويُفرض التحقق منه بصرامة على الخادم — فقط عند تحقق **الشروط الخمسة جميعها**:
   - `defaults.metadata.enabled: true`
   - `profiles.<name>.ai.enabled: true`
   - وجود خدمة `AssetEnricherInterface` نشطة ومسجّلة
   - امتلاك المستخدم الصلاحيات الثلاث معًا — **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** — على الملف وجميع المجلدات الأبوية له
   - إذا كانت وحدة التخزين `private: true`، يجب أن يكون `block_private_volumes: false` مضبوطًا صراحةً
3. النقر على الزر **لا يستبدل** البيانات الوصفية الحالية فورًا؛ بل يملأ حقول النموذج بقيم `alt` و `title` و `caption` و `tags` المقترحة ليراجعها المحرّر ويعدّلها ثم ينقر على **حفظ**.

---

## 5. الإثراء الدفعي للأرشيفات القائمة عبر سطر الأوامر (CLI)

املأ نصوص `alt` والوسوم المفقودة عبر آلاف الأصول الموجودة مع ضوابط صارمة للتكلفة:

```bash
# 1. معاينة الملفات التي سيتم إثراؤها دون استدعاء واجهة الذكاء الاصطناعي (تشغيل تجريبي مجاني):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. إثراء أول 50 ملفًا تفتقر إلى البيانات الوصفية باللغة العربية:
php bin/console file-manager:ai:enrich products --limit=50 --locale=ar

# 3. حصر العملية في وحدة تخزين ومجلد فرعي محددين:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/catalog --limit=100

# 4. فرض إعادة توليد البيانات الوصفية الموجودة (--force):
php bin/console file-manager:ai:enrich products --path=campaign --force --limit=20
```

> [!NOTE]
> **ضوابط الأمان والتكلفة في أمر CLI (`file-manager:ai:enrich`):**
> - القيمة الافتراضية لخيار `--limit` هي **`50`** حتى لا ترسل أرشيفًا كاملًا عن طريق الخطأ إلى API مدفوع في تشغيل واحد.
> - بدون `--force`، تُعالَج فقط الملفات التي **تنقصها بيانات وصفية تحريرية** (إذا كان `alt` ممتلئًا **وكان** أحد الحقول `tags`/`title`/`caption` ممتلئًا على الأقل، يتم تخطّي الملف).
> - يعمل التحميل المسبق للبيانات الوصفية (`preload()`) على دفعات من **250 عنصرًا**؛ وتُتخطّى تلقائيًا الملفات التي يتجاوز حجمها **20 ميجابايت**، ووحدات التخزين الديناميكية المحتوية على `{user_id}`، والوحدات الخاصة `private: true` (عندما يكون `block_private_volumes: true`).

---

## 6. الإثراء التلقائي غير المتزامن عند الرفع (`symfony/messenger`)

> [!IMPORTANT]
> يؤدي ضبط `ai.auto_enrich_on_upload: true` إلى جعل المستمع المدمج `MetadataSyncListener` يستدعي مزوّد الذكاء الاصطناعي **بشكل متزامن** أثناء طلب HTTP الخاص بالرفع. لتجنب إبطاء عمليات الرفع، اترك `ai.auto_enrich_on_upload: false` وعالج الإثراء بشكل غير متزامن عبر Symfony Messenger.

### الخطوة 1 — رسالة Messenger

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

### الخطوة 2 — مستمع الحدث `FileUploadedEvent`

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
        // منع المعالجة المكررة إذا كان الإثراء المتزامن auto_enrich_on_upload مفعّلًا:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // تجنب إغراق طابور الذكاء الاصطناعي عند فك ضغط أرشيف ZIP يضم 200 ملف:
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

### الخطوة 3 — معالج عامل Messenger (Worker Handler)

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
            skipVoter: true, // لا يملك عامل الخلفية جلسة مستخدم HTTP نشطة
        );
    }
}
```

> [!WARNING]
> **تنبيهات مهمّة لعمّال الخلفية (Workers):**
> 1. نظرًا لأن عمّال Messenger يعملون بدون جلسة مستخدم مصادق عليه، تتحول وحدات التخزين الديناميكية `{user_id}` إلى `guest` — لذا لا تُرسل مهام إثراء غير متزامنة لوحدات `{user_id}` الخاصة بكل مستخدم.
> 2. حتى مع `skipVoter: true`، تظل حماية `block_private_volumes: true` نشطة وتُطلق استثناء `AccessDeniedException` على الوحدات ذات `private: true`.

---

## 7. كتابة مزوّد ذكاء اصطناعي مخصّص (`AssetEnricherInterface`)

يمكنك ربط خدمتك المصغّرة بلغة Python أو AWS Rekognition أو Azure AI Vision أو أي واجهة HTTP مخصّصة دون الحاجة إلى تثبيت `symfony/ai-bundle`:

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
        string $locale = 'en',
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

بفضل السمة `#[AsAlias(AssetEnricherInterface::class)]`، سيستخدم كل من زر **✨ اقتراح بالذكاء الاصطناعي** في الواجهة وأمر `file-manager:ai:enrich` خدمتك المخصّصة تلقائيًا.

---

## 8. الأمان، وحقن الأوامر (Prompt Injection)، والحماية من ردود الرفض للنموذج

1. **الملفات الخاصة (`private: true`):** أبقِ `block_private_volumes: true` عند استخدام نماذج سحابية، أو انتقل إلى خادم **Ollama** مستضاف ذاتيًا.
2. **التفويض والصلاحيات:** قيّد الوصول إلى واجهات API المدفوعة عبر `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` (يُتحقق من `VIEW` + `EDIT_METADATA` + `AI_ENRICH` مجتمعةً).
3. **ضبط التكاليف:** أبقِ `max_image_dimension: 1024` (أو `768`)، وعاين دائمًا تشغيلات CLI باستخدام `--dry-run` و `--limit`.
4. **تصفية حقن الأوامر وردود الرفض متعددة اللغات:**
   - تُجرّد أسماء الملفات من أحرف التحكم، وتجاوزات الاتجاه الثنائي (Bidirectional Overrides)، وعلامات الاقتباس — وتُقيّد بحد أقصى **120 حرفًا** — قبل إدخالها في أي موجه (Prompt).
   - تكتشف الدالة `SymfonyAiAssetEnricher::parseJsonResponse()` رسائل رفض النموذج عبر **11 لغة** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) — مثل *«عذرًا، لا يمكنني تحليل هذه الصورة...»* — مما يمنع حفظ نص الرفض أبدًا كبيانات `alt` وصفية.
   - تُنقّى جميع النصوص المُعادة عبر `AssetEnrichmentResult` و `FolderMetadata::sanitizeTags()`، وتُهرَّب دائمًا بـ HTML Escape داخل الـ DOM.

---

[→ السابق: 08 — الأحداث، الأوامر واستكشاف الأخطاء](08-events-commands-cache-and-troubleshooting.md) | [الفهرس الرئيسي](README.md) | [التالي: 10 — حماية استخدام الأصول ←](10-asset-usage-guard.md)
