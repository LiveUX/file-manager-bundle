# 01 — المتطلبات والاعتماديات والتثبيت

[← فهرس التوثيق](README.md) | [التالي: 02 — التهيئة وملفات التعريف →](02-configuration-and-profiles.md)

يأخذك هذا الدليل خطوة بخطوة لتثبيت حزمة LiveUX FileManagerBundle من الصفر — مع شرح **كل خطوة وسبب أهميتها**، حتى لو كانت هذه أول حزمة Symfony UX تستخدمها.

> [!NOTE]
> **ببساطة: ماذا تفعل هذه الحزمة؟**  
> تضيف إلى تطبيق Symfony لديك مدير ملفات متكامل المزايا (شبيه بـ Google Drive أو مستكشف Windows) — سواء كان تطبيقك لوحة تحكم أو متجراً إلكترونياً أو بوابة عملاء. يتيح للمستخدمين **رفع الملفات وتنظيم المجلدات وقصّ الصور ومعاينة ملفات PDF والفيديو واختيار الملفات مباشرة داخل النماذج** بنقرة واحدة.  
> لا تتطلب الحزمة **أي إطار CSS** (مثل Bootstrap أو Tailwind) ولا **jQuery**، ومع Symfony AssetMapper تعمل خلال 5 دقائق دون الحاجة إلى Node.js أو npm.

---

## 1. متطلبات النظام

قبل التثبيت، تأكّد من استيفاء جهازك المحلي أو الخادم للحد الأدنى التالي:

| المكوّن | الحد الأدنى للإصدار | سبب الاحتياج | أمر التحقق |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` أو أحدث | تعتمد الحزمة على مزايا PHP 8.3/8.4 (مثل أصناف `readonly` وثوابت الأصناف المحدّدة النوع). | `php -v` |
| **Symfony** | `6.4 LTS` أو `7.2+` أو `8.x` | مختبرة ومدعومة بالكامل على Symfony 6.4 LTS و7.2 و7.3 و7.4 و8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | تثبيت الحزم والتحميل التلقائي وفق معيار PSR-4. | `composer --version` |
| **المتصفح** | متصفح حديث دائم التحديث | دعم `<dialog>` في الطبقة العليا الأصلية وES Modules ومتغيرات CSS المخصّصة (Chrome وEdge وFirefox وSafari). | — |

---

## 2. إضافات PHP

تمنح إضافات PHP اللغة قدرات أصلية إضافية مثل فحص محتوى الملفات وتحليل XML ومعالجة الصور.

### إضافات PHP الإجبارية
مفعّلة افتراضياً في معظم تثبيتات PHP الحديثة:

| الإضافة | وظيفتها | مثال من الواقع |
| :--- | :--- | :--- |
| `ext-fileinfo` | تفحص **المحتوى الفعلي للملف (Magic Bytes)** ولا تكتفي بامتداد الاسم. | إذا غيّر مهاجم اسم `shell.php` إلى `photo.jpg` وحاول رفعه، تكتشفه `ext-fileinfo` وترفضه فوراً. |
| `ext-dom` و`ext-libxml` | تحلّل شجرة XML لملفات `.svg` المرفوعة بأمان عبر `SvgSanitizer`. | تزيل وسوم `<script>` الخبيثة أو هجمات XXE المخفية داخل شعار SVG مرفوع مع الحفاظ على الرسم النظيف. |

*(ملاحظة: إضافة `ext-json` مدمجة في نواة PHP منذ PHP 8.0 ولا تحتاج إلى تثبيت منفصل.)*

### إضافات PHP الاختيارية (الموصى بها)
في حال غياب أي منها **يستمر مدير الملفات بالعمل** وينتقل تلقائياً إلى سلوك بديل آمن:

| الإضافة | وظيفتها | السلوك البديل عند غيابها |
| :--- | :--- | :--- |
| `ext-gd` **أو** `ext-imagick` | توليد صور مصغرة سريعة بصيغ WebP/JPEG وتشغيل التحسين التلقائي للصور أثناء الرفع. | يعرض الصورة الأصلية أو أيقونة نوع الملف وفق خيار `thumbnail.on_missing_driver`. |
| `ext-exif` | قراءة بيانات اتجاه EXIF من صور الهواتف الذكية عند استخدام `ext-gd` لتدوير الصور المائلة تلقائياً (`auto_orient: true`). | تُحفظ الصور باتجاهها الأصلي (غير مطلوبة إذا كانت `ext-imagick` مثبّتة). |
| `ext-zip` (`ZipArchive`) | تفعيل تنزيل ملفات متعدّدة بصيغة `.zip` **وكذلك** استخراج الأرشيفات بأمان (`zip.allow_extract: true`). | يتم إخفاء زرّي **تنزيل بصيغة ZIP** و**استخراج الأرشيف** تلقائياً. |

يمكنك فحص الإضافات النشطة على جهازك بأمر واحد:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. حزم Composer الإجبارية والاختيارية

عند تشغيل `composer require liveux/file-manager-bundle` **تُثبّت جميع الحزم الإجبارية تلقائياً**. ومع ذلك، تساعدك معرفة دور كل اعتمادية على تسريع التشخيص عند الحاجة:

### الحزم الإجبارية (تُثبّت تلقائياً)
| الحزمة | قيد الإصدار | الوظيفة |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | طبقة تجريد موحّدة لأنظمة الملفات عبر القرص المحلي وAWS S3 وCloudflare R2 وSupabase وMinIO وSFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | تصيير مدير الملفات عبر `{{ component('LiveUX:FileManager:Browser') }}` بزمن استجابة SSR فوري (0ms). |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | تشغيل الذاكرة المؤقتة للمجلدات في المتصفح والسحب والإفلات وقوائم السياق والعارض المنبثق ومنتقي النماذج. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | نواة Symfony والتوجيه وتجمّعات التخزين المؤقت (`cache.app`). |
| `symfony/config` و`symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | التحقق من مخطط `liveux_file_manager.yaml` وربط الخدمات في حاوية الاعتماديات. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | إطلاق 9 أحداث نطاق (`FileUploadedEvent` و`FileDeletedEvent` و`ItemRenamedEvent` وغيرها). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | التنزيلات الآمنة وبث الفيديو بتقنية HTTP 206 (`Range`) وETags وترويسات التخزين المؤقت لـ HTTP. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | قوالب Twig والدوال المساعدة للأيقونات والترجمة وسمة FormType التلقائية. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | حقل النماذج `FileManagerPickerType` لاختيار ملف مفرد أو معرض ملفات متعدد. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | اكتشاف نوع MIME للملفات المرفوعة على جانب الخادم. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | تحويل أسماء الملفات متعددة اللغات (العربية والتركية والفرنسية والسيريلية وغيرها) إلى سلاسل SEO نظيفة. |
| `symfony/security-core` و`symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | صلاحيات RBAC بـ 17 صلاحية عبر Voter وحماية `X-LiveUX-FM-CSRF`. |
| `symfony/cache-contracts` و`symfony/translation-contracts` | `^3.5` | عقود التخزين المؤقت للمجلدات والواجهة متعددة اللغات. |

### الحزم الاختيارية (ثبّتها عند الحاجة فقط)
| الحزمة | أمر التثبيت | متى تحتاجها |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | تقديم ملفات JS وCSS دون خطوة بناء ودون Node.js أو npm *(موصى به)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | صلاحيات مبنية على الأدوار (`ROLE_USER` / `ROLE_ADMIN`) وعزل المستخدمين عبر `{user_id}`. |
| `symfony/translation` | `composer require symfony/translation` | التكامل مع مترجم Symfony عبر **جميع لغات الواجهة الـ 12 المدمجة** (`en` و`tr` و`fr` و`de` و`es` و`ar` و`it` و`pt` و`nl` و`pl` و`ja` و`zh_CN`) أو لغات تطبيق مخصّصة. |
| `symfony/console` و`symfony/yaml` | `composer require symfony/console symfony/yaml` | الأوامر الطرفية (`file-manager:doctor` و`thumbs:warmup` و`trash:purge` وغيرها) وملفات تهيئة YAML. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | الربط مع AWS S3 أو Supabase Storage أو Cloudflare R2 أو Backblaze B2 أو MinIO أو Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | النشر على عدة خوادم / موازنات أحمال باستخدام `defaults.metadata.driver: 'doctrine'` (راجع [الفصل 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | استخدام الحقل المدمج `FileManagerField` في لوحات EasyAdmin 4/5 (راجع [الفصل 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | ميزة **✨ اقتراح بالذكاء الاصطناعي** لتوليد النص البديل والوصف والوسوم تلقائياً (راجع [الفصل 09](09-symfony-ai-integration.md)). |

---

## 4. التثبيت خطوة بخطوة

اتبع هذه الخطوات السبع بالترتيب للحصول على مدير ملفات جاهز للعمل.

### الخطوة 1: تثبيت الحزمة

**التثبيت القياسي (عبر Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**مستودع محلي بمسار (إذا كنت تطوّر داخل `bundles/liveux-file-manager-bundle`):**  
أضف قسم `repositories` إلى ملف `composer.json` في جذر المشروع:

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

ثم ثبّت الحزمة المحلية:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### الخطوة 2: التحقق من `config/bundles.php`

يقوم Symfony Flex عادةً بتسجيل الحزمة تلقائياً. وإن لم يحدث، فتأكّد من وجود الإدخالات التالية في `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // عند استخدام المصادقة والأدوار
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> تسجّل `FileManagerBundle` **تلقائياً** مساحة أسماء Twig (`@LiveUXFileManager`) ومسار AssetMapper (`@liveux/file-manager-bundle`) وسمة FormType (`@LiveUXFileManager/form/picker.html.twig`) عند الإقلاع. لست بحاجة إلى تعديل `twig.yaml` أو `asset_mapper.yaml`.

---

### الخطوة 3: استيراد المسارات

أنشئ ملف `config/routes/liveux_file_manager.yaml` لتفعيل نقاط نهاية AJAX والتنزيل والصور المصغرة والرفع الخاصة بالحزمة:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

يسجّل هذا الاستيراد الوحيد **12 نقطة نهاية مؤمّنة**:

| الطريقة والمسار | الوظيفة |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | تحميل شيفرة HTML لمدير الملفات داخل نافذة Modal أو لوحة OffCanvas. |
| `GET /_liveux-file-manager/api/list` | إرجاع قائمة JSON بالملفات والمجلدات الفرعية داخل مجلد معيّن. |
| `GET /_liveux-file-manager/api/tree` | جلب شجرة المجلدات الكاملة للشريط الجانبي في طلب واحد. |
| `GET /_liveux-file-manager/api/trash` | عرض العناصر المحذوفة مبدئياً داخل سلة المهملات. |
| `GET /_liveux-file-manager/api/preview-text` | إرجاع معاينة نصية/برمجية آمنة (بحد أقصى 64 كيلوبايت) لملفات `.txt` و`.md` و`.json` و`.yaml` و`.csv` و`.sql` وغيرها. |
| `POST /_liveux-file-manager/api/action` | تنفيذ عمليات التعديل: إنشاء مجلد، نقل، نسخ، إعادة تسمية، حذف، استعادة/حذف نهائي من سلة المهملات، ألوان وأيقونات المجلدات، البيانات الوصفية، الإثراء بالذكاء الاصطناعي، استيراد من رابط، واستخراج ZIP. |
| `POST /_liveux-file-manager/upload` | معالجة الرفع القياسي والرفع المجزّأ للملفات. |
| `POST /_liveux-file-manager/image-edit` | حفظ الصور المقصوصة أو المعدّلة الأبعاد أو المدوّرة من محرر الصور المدمج (Canvas). |
| `GET /_liveux-file-manager/download` | تقديم التنزيلات الآمنة ومعاينات PDF/الفيديو المباشرة (مع دعم `Range`) وملفات وحدات التخزين ذات `private: true`. |
| `GET /_liveux-file-manager/share` | تقديم روابط المشاركة الموقّعة بـ HMAC والمحدّدة بمدة زمنية. |
| `POST /_liveux-file-manager/zip` | بث الملفات والمجلدات المحددة كأرشيف `.zip` واحد. |
| `GET /_liveux-file-manager/thumb` | توليد وتقديم صور مصغرة بصيغ WebP/JPEG/PNG مع ETag وترويسات تخزين مؤقت مدركة للسياق. |

---

### الخطوة 4: إنشاء `config/packages/liveux_file_manager.yaml`

أنشئ ملف `config/packages/liveux_file_manager.yaml`. يعرض إعداد البداية أدناه **جميع الصلاحيات الـ 17 التفصيلية** بصورة صريحة:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = يرث لغة Symfony النشطة تلقائياً (مع الرجوع الدائم إلى 'en')
                            # أو ثبّت لغة محدّدة مثل 'ar' أو 'en' أو 'tr' أو 'fr' أو 'de' أو 'es' أو 'ja' أو 'zh_CN'

    volumes:
        local_uploads:
            label: 'ملفاتي'
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
                extract_zip: false        # استخراج ZIP معطّل افتراضياً (ويتطلب أيضاً zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **ملاحظة أمنية بالغة الأهمية (`permissions`):**  
> أي مفتاح **تغفل عن ذكره** تحت `permissions` (باستثناء `extract_zip`) يأخذ القيمة الافتراضية `true`. وهنا تعني `true` **"أي مستخدم مسجّل الدخول (مصادق عليه)"** — وليس الزوّار المجهولين. وإذا كنت تريد حصر الإفراغ النهائي لسلة المهملات (`trash_purge`) أو إنشاء روابط المشاركة (`share`) على `ROLE_ADMIN` فقط، فاضبط هذه المفاتيح صراحةً على `'ROLE_ADMIN'`.

> [!TIP]
> **تختبر محلياً دون نظام تسجيل دخول بعد؟**  
> أثناء التجربة المحلية دون مصادقة، يمكنك مؤقتاً تعيين `profiles.default.security.voter_enabled: false`. **لا تترك أبداً `voter_enabled: false` في بيئة الإنتاج** — راجع [06 — الأمان والصلاحيات](06-security-and-authorization.md).

> [!NOTE]
> **12 لغة واجهة مدمجة ودعم RTL تلقائي (`defaults.locale: null`):**  
> ترك `locale: null` يجعل مدير الملفات يتبع لغة طلب Symfony النشطة تلقائياً عبر **12 لغة واجهة مدمجة** (`en` و`tr` و`fr` و`de` و`es` و`ar` و`it` و`pt` و`nl` و`pl` و`ja` و`zh_CN`)، ويُفعّل تلقائياً **اتجاه RTL (`dir="rtl"`)** للعربية واللغات الأخرى المكتوبة من اليمين إلى اليسار (الفارسية والعبرية والأردية وغيرها)، ويطبّع صيغ BCP-47 وPOSIX (`de_DE.UTF-8` و`zh-Hans-CN`)، ويرجع بسلاسة إلى **الإنجليزية (`en`)** للغات غير المدعومة.

---

### الخطوة 5: ربط ملفات CSS وJavaScript (دون تهيئة يدوية!)

**غير متأكّد إن كان مشروعك يستخدم AssetMapper أم Webpack Encore؟**  
تفحّص جذر مشروعك:
- إذا وجدت `importmap.php` ← اتبع **الخيار أ (AssetMapper)**.
- إذا وجدت `webpack.config.js` ← اتبع **الخيار ب (Webpack Encore)**.

> [!TIP]
> **دعم `autoimport` بلا تهيئة يدوية:**  
> تعلن حزمة LiveUX FileManagerBundle عن **`autoimport`** داخل `assets/package.json` لكل من `@liveux/file-manager-bundle/styles/core.css` ووحدة التحكم الفورية `dialog_controller.js` (التي تحمّل `file_manager_api.js`). بمجرد تفعيل `assets/controllers.json`، **لن تحتاج إلى كتابة سطر `import` يدوي واحد داخل `assets/app.js`!**

#### الخيار أ: Symfony AssetMapper (موصى به — دون الحاجة إلى Node.js أو npm)

1. تأكّد من تفعيل وحدات تحكم الحزمة في `assets/controllers.json` (يضيفها Symfony Flex تلقائياً؛ وإن لم توجد فأضفها):
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

2. تستدعي مشاريع Symfony 6.4 / 7.2+ / 8.x الدالة `startStimulusApp()` مسبقاً داخل `assets/bootstrap.js` أو `assets/stimulus_bootstrap.js`، وهي تقرأ `controllers.json` تلقائياً:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) أو assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. تأكّد من أن القالب الأساسي (`templates/base.html.twig`) يتضمّن `importmap('app')`:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(اختياري — عند النشر للإنتاج)* ترجمة الأصول الثابتة:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **أصول إنتاج مضغوطة مسبقاً (`assets/dist/`):**  
> نظراً لأن AssetMapper لا يضغط ملفات JS/CSS افتراضياً، توفّر LiveUX FileManagerBundle وحدات JavaScript وملف `core.css` مضغوطة مسبقاً بواسطة `esbuild` ضمن `assets/dist/` (`747 KB ← 410 KB` بعد التصغير؛ نحو `~93 KB` عبر Gzip أو **`~77 KB`** عبر Brotli). وتربط `FileManagerBundle` مجلد `assets/dist/` تلقائياً في AssetMapper، لذلك **لا يحتاج جهاز التطوير ولا خادم الإنتاج إلى Node.js أو npm**.  
> في Symfony 7.3+ / 8.x، يمكنك أيضاً تفعيل `precompress: true` في `config/packages/asset_mapper.yaml` لتوليد ملفات `.br` و`.gz` تلقائياً أثناء `asset-map:compile`.

#### الخيار ب: Webpack Encore

1. لمشاريع Webpack Encore (بما فيها Symfony 8 + StimulusBundle 3.x)، تأكّد من تثبيت `@symfony/stimulus-bridge`:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. تحقّق من وحدات تحكم Stimulus الثلاث وقسم `autoimport` في `assets/controllers.json`:
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

3. تحقّق من ربط `startStimulusApp` داخل `assets/bootstrap.js` (حيث يستورده `assets/app.js` دون الحاجة لأي استيراد يدوي لـ CSS/JS):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. ابنِ الأصول:
```bash
npm run build
# أو للمراقبة المستمرة أثناء التطوير: npm run watch
```

---

### الخطوة 6: حاوية `DialogHost` (حقن تلقائي — دون أي كود إضافي!)

يتطلب فتح النوافذ المنبثقة (Modal) أو ألواح OffCanvas أو حقل `FileManagerPickerType` أو منتقي محررات TinyMCE/CKEditor/Tiptap من أي صفحة وجود حاوية `<dialog>` في شجرة DOM.

يقوم المستمع `AutoInjectDialogHostSubscriber` المدمج في الحزمة بحقن هذه الحاوية تلقائياً قبل `</body>` مباشرة في استجابات HTML الكاملة (`defaults.auto_inject_dialog_host: true`) — فلا داعي لتعديل `base.html.twig`.

- **اكتشاف ذكي:** لا يمسّ إطلاقاً استجابات JSON أو AJAX أو Turbo Frame أو عمليات إعادة التوجيه أو التنزيلات الثنائية، ويتخطى الحقن تلقائياً إذا كان `DialogHost` مضافاً يدوياً بالفعل.
- **صفر عبء عند تحميل الصفحة:** لا يستعلم عن المجلدات أو الشجرة عند فتح الصفحة، بل تُحمّل البيانات فقط حين يفتح المستخدم النافذة.
- **تفضّل الوضع اليدوي في صفحات محدّدة؟** اضبط `defaults.auto_inject_dialog_host: false` ثم أضف المقطع التالي قبل `</body>` في القوالب التي تختارها:

```twig
{# اختياري — يُستخدم فقط عند تعيين auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### الخطوة 7: التحقق من التثبيت (`file-manager:doctor`)

امسح الذاكرة المؤقتة وشغّل أمر التشخيص المدمج:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

إذا كنت قد هيّأت وحدات تخزين سحابية (S3 أو R2 أو Supabase أو MinIO)، فأضف الخيار `--check-remote` لاختبار الاتصال المباشر:
```bash
php bin/console file-manager:doctor --check-remote
```

مثال لمخرجات سليمة (9 فحوصات بيئية + جدول حالة ملفات التعريف ووحدات التخزين):
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
> **لمستخدمي Windows (`cURL error 60`):** إذا ظهر تحذير عند `cURL CA Bundle`، وجّه `curl.cainfo` و`openssl.cafile` في ملف `php.ini` إلى ملف `cacert.pem` محدث، أو اضبط `driver_options.ca_bundle` في وحدة تخزين S3.
>
> **صلاحيات الملفات في Linux:** تأكّد من قابلية الكتابة لمستخدم خادم الويب (`www-data` أو `nginx` وغيرهما) على المجلدات `public/uploads` و`public/media-cache` و`var/file-manager`.
>
> **تستخدم مساعد برمجة بالذكاء الاصطناعي (Antigravity أو Claude Code أو Cursor أو Copilot)؟**  
> عرّف مساعدك بجميع اصطلاحات الحزمة وصفاتها الجاهزة بأمر واحد (راجع [الفصل 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. بناء أول صفحة عاملة

لننشئ متحكّماً (Controller) بسيطاً في Symfony وقالب Twig للتحقق من عمل كل شيء من البداية إلى النهاية.

### 1) المتحكّم (`src/Controller/MediaController.php`)
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

### 2) قالب Twig (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>مكتبة الوسائط</h1>

    {# الخيار 1: مدير ملفات مضمّن داخل الصفحة #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# الخيار 2: فتح نافذة منبثقة من أي زر والتقاط الملفات المختارة #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">اختيار ملفات عبر النافذة المنبثقة</button>
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
                return; // أغلق المستخدم النافذة دون اختيار أي ملف
            }

            document.getElementById('selection-result').textContent =
                'المختار: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

افتح الرابط `/admin/media` في متصفحك. تهانينا — مدير الملفات يعمل الآن بنجاح!

---

## 6. الخطوة التالية

- تهيئة ملفات التعريف المخصّصة وحدود الرفع وشروط الصور ← **[02 — التهيئة وملفات التعريف](02-configuration-and-profiles.md)**
- إضافة منتقي صور أو معرض إلى Symfony Forms ← **[05 — تكامل FormType ومحررات النصوص](05-formtype-and-editor-integrations.md)**
- إحكام صلاحيات RBAC والأمان قبل الإطلاق للإنتاج ← **[06 — الأمان والصلاحيات](06-security-and-authorization.md)**

---

[← فهرس التوثيق](README.md) | [التالي: 02 — التهيئة وملفات التعريف →](02-configuration-and-profiles.md)
