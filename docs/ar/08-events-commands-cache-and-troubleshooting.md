# 08 — الأحداث، أوامر سطر الأوامر (CLI)، التخزين المؤقت، الترجمات واستكشاف الأخطاء (الأسئلة الشائعة)

[→ السابق: 07 — التخزين السحابي والإعداد الديناميكي](07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](README.md) | [التالي: 09 — التكامل مع Symfony AI ←](09-symfony-ai-integration.md)

> [!TIP]
> **نظرة سريعة: ما فائدة الأحداث وأوامر سطر الأوامر (CLI)؟**
> - **الأحداث (Events):** تُبلّغ تطبيق Symfony الخاص بك تلقائيًا كلما رفع مستخدم ملفًا أو حذفه أو أعاد تسميته أو نقله — على سبيل المثال: *«عند إعادة تسمية ملف، حدّث مسار صورة المنتج في قاعدة البيانات تلقائيًا».*
> - **أوامر سطر الأوامر (`php bin/console file-manager:...`):** أدوات طرفية مدمجة لإجراء فحوصات الجاهزية (`doctor`)، وإبطال ذاكرة التخزين المؤقت (`cache:clear`)، وتوليد الصور المصغّرة مسبقًا (`thumbs:warmup`)، أو تفريغ عناصر سلة المهملات منتهية الصلاحية (`trash:purge`).

---

## 1. أحداث Symfony (9 أحداث نطاق)

يرث كل حدث في مدير الملفات من الصنف الأساسي المشترك **`AbstractFileManagerEvent`** (`$profile`، `$volume`، `$path`، `$canonicalPath`). استمع إلى هذه الأحداث للحفاظ على تزامن قاعدة البيانات، أو تسجيل سجلات التدقيق (Audit Logs)، أو مسح ذاكرة CDN، أو إرسال مهام في الخلفية.

| صنف الحدث (`LiveUX\FileManagerBundle\Event\*`) | متى يُطلق؟ | الخصائص |
| :--- | :--- | :--- |
| `FileUploadedEvent` | عند رفع ملف، أو استيراده من رابط URL، أو حفظه عبر محرّر الصور، أو استخراجه من أرشيف ZIP | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | عند حذف ملف (نقله إلى سلة المهملات أو حذفه نهائيًا) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | عند إنشاء مجلد (بما في ذلك أثناء فك ضغط ZIP) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | عند حذف مجلد | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | عند إعادة تسمية ملف أو مجلد | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | عند نقل عنصر أو نسخه (بما في ذلك النقل بين وحدات تخزين مختلفة) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | عند تكرار/استنساخ عنصر | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | عند استعادة عنصر من سلة المهملات | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | عند إزالة عنصر أو أكثر نهائيًا من سلة المهملات | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### مثال على مستمع أحداث (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('تم رفع أصل وسائط جديد.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. أوامر سطر الأوامر (CLI) (10 أوامر مدمجة)

| الأمر | الخيارات / الأسماء البديلة | الوصف |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | يفحص إصدار PHP، و`upload_max_filesize`، و`post_max_size`، وحزمة شهادات cURL CA، وGD/Imagick، وEXIF، وWebP، و`ext-zip`، وإعدادات كل ملف تعريف ووحدة تخزين. أضف `--check-remote` لإجراء اختبارات اتصال حية مع S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | يمسح ذاكرة التخزين المؤقت للمجلدات والحصص (Quotas) لجميع ملفات التعريف أو لملف تعريف/وحدة تخزين/مسار محدد (يتطلب الخياران `--volume`/`--path` تمرير معامل `profile`؛ ولا يمسح أبدًا المفاتيح غير المرتبطة في `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | الأسماء البديلة: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | يولّد الصور المصغّرة مسبقًا حتى لا ينتظر المحرّرون عند أول تحميل. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | يمسح الصور المصغّرة المخزّنة مؤقتًا. يزيل الخيار `--orphans` الصور المصغّرة التي لم يعد ملفها الأصلي موجودًا فقط (لا يمكن دمجه مع `--volume`/`--path`، ويحمي تلقائيًا المجلدات المشتركة لـ `{user_id}` / `{Y}/{m}/{d}`). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | يحسّن الصور الموجودة مسبقًا بأثر رجعي (بحد أقصى للأمان والذاكرة يبلغ `25 MB` لكل صورة). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | يكتشف الملفات المكررة باستخدام بصمات المحتوى SHA-256 ويعرض المساحة القابلة للاستعادة على القرص. |
| `file-manager:stats [profile]` | `--json` | يعرض تقريرًا عن استخدام التخزين، وعدد الملفات والمجلدات، ونسبة استهلاك الحصة، وإحصاءات الصور المصغّرة وسلة المهملات. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(الافتراضي `50`)*, `--dry-run` | يولّد بشكل دفعي نصوص `alt` والعناوين والأوصاف والوسوم المفقودة باستخدام الذكاء الاصطناعي. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = تفريغ الكل)*, `--dry-run` | يحذف نهائيًا عناصر سلة المهملات منتهية الصلاحية. وعندما تتشارك عدة ملفات تعريف مجلدًا فعليًا واحدًا، يلتزم تلقائيًا بأطول قيمة لـ `retention_days`. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | يثبّت مهارات وكلاء الذكاء الاصطناعي الست الخاصة بالحزمة داخل `.agents/skills` أو `.claude/skills` أو `.cursor/skills` أو `.github/skills`. |

> [!NOTE]
> **حماية العناصر الديناميكية `{user_id}` و `{Y}/{m}/{d}` في أوامر CLI**
> نظرًا لعدم وجود جلسة مستخدم مصادق عليه في وضع سطر الأوامر، تقوم جميع أوامر الفحص والتنظيف (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **بتخطّي وحدات التخزين المحتوية على `{user_id}` تلقائيًا** (لمنع إنشاء مجلد `guest/` فارغ عن طريق الخطأ). أما وحدات التخزين التي تستخدم عناصر التاريخ النائبة `{Y}/{m}/{d}`، فيُفحص فيها نطاق التاريخ الحالي فقط مع عرض تنبيه معلوماتي.

### مثال Cron للتنظيف التلقائي لسلة المهملات

```cron
# تفريغ عناصر سلة المهملات منتهية الصلاحية كل ليلة الساعة 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. الأداء، ومعمارية التخزين المؤقت، وملف تعريف الأداء (Symfony Profiler)

- **مدة صلاحية (TTL) من 3 مستويات والتحديث اليدوي (`↻`):** تُحسم مدة التخزين المؤقت لقوائم المجلدات بالترتيب التالي: **`volumes.<name>.cache_ttl` ← `profiles.<name>.cache.ttl` ← `defaults.cache.ttl`** (القيمة `0` تعطّل التخزين المؤقت لتلك الوحدة أو ملف التعريف). يؤدي النقر على **تحديث (`↻`)** في شريط الأدوات أو الضغط على `R` إلى إرسال `?refresh=1` *(يتطلب تجاوز الذاكرة المؤقتة صلاحية `CLEAR_CACHE`)*.
- **مجمع تخزين مؤقت مخصّص وبصمة سياسة ملف التعريف:** وجّه `defaults.cache.pool` إلى مجمع Redis/APCu مخصّص (إذا وُجد `<pool>.taggable` فسيُستخدم تلقائيًا). وبدون التخزين المؤقت القابل للوسم (Taggable)، يعتمد `DirectoryCacheManager` على مفاتيح عدّادات جيلية لمدة 30 يومًا ولا يستدعي أبدًا `$cachePool->clear()` على ذاكرة التطبيق الرئيسية. وعندما يتشارك ملفا تعريف المجلد الفعلي نفسه بقواعد مختلفة (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`)، تبقى ذاكرتهما المؤقتة معزولة تمامًا عبر `buildProfilePolicyFingerprint()`.
- **مزامنة المفاتيح القياسية (Canonical Keys):** إذا أشار ملفا تعريف إلى المجلد الفعلي نفسه، فإن رفع ملف أو تعديله في أحدهما يبطل تلقائيًا الذاكرة المؤقتة للآخر أيضًا.
- **التعافي الذاتي لخيار `remember_last_dir`:** إذا حُذف آخر مجلد زاره المستخدم من خارج التطبيق (عبر FTP أو لوحة S3)، يتراجع مدير الملفات بسلاسة إلى أقرب مجلد أب موجود أو إلى الجذر بدلًا من عرض خطأ.
- **استجابة HTTP 304 والتخزين المؤقت للصور المصغّرة وفق السياق (`ThumbnailController`):**
  - **السياق الخاص / المصادق عليه** (وحدة تخزين `private: true`، أو وحدة ديناميكية `{user_id}`، أو ملف تعريف محمي بـ Voter، أو وجود ترويسة `Authorization` أو ملف تعريف ارتباط للجلسة): يُرسل `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` لمنع تسرب الصور في شبكات CDN المشتركة.
  - **السياق العام:**
    - مع معامل كسر التخزين المؤقت `?v=<version>` ← `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 يومًا).
    - بدون `?v=` ← `Cache-Control: public, max-age=3600, must-revalidate` (ساعة واحدة + `ETag` / `304 Not Modified`).
    - عندما يكون `cache.enabled: false` في ملف التعريف ← `Cache-Control: public, no-cache, must-revalidate`.
  - تُضيف كل استجابة صورة مصغّرة أيضًا الترويسات الأمنية: `Content-Security-Policy: default-src 'none'; sandbox`، و`X-Content-Type-Options: nosniff`، و`Referrer-Policy: no-referrer`.
- **شريط التصحيح (Symfony Web Debug Toolbar):** في بيئة `dev`، تعرض لوحة **FileManager** داخل الـ Profiler ملف التعريف النشط، ووحدة التخزين، ومشغّل الصور المصغّرة، وإحصاءات إصابة/فقدان الذاكرة المؤقتة (Cache Hit/Miss).

---

## 4. التعريب العالمي (`493` مفتاحًا)، ودعم BCP-47 / POSIX / RTL، واللغات المخصّصة

تأتي حزمة LiveUX FileManagerBundle جاهزة بـ **12 لغة واجهة مدمجة** — **الإنجليزية (`en` — لغة احتياطية مضمونة)، والتركية (`tr`)، والفرنسية (`fr`)، والألمانية (`de`)، والإسبانية (`es`)، والعربية (`ar`)، والإيطالية (`it`)، والبرتغالية (`pt`)، والهولندية (`nl`)، والبولندية (`pl`)، واليابانية (`ja`)، والصينية المبسّطة (`zh_CN`)** — بتكافؤ كامل عبر جميع **مفاتيح الترجمة البالغ عددها 493 مفتاحًا**.

- **دعم BCP-47 و POSIX `.UTF-8`:** يتعامل `MessageTranslator` تلقائيًا مع رموز اللغات الإقليمية وصيغ POSIX مثل `tr_TR.UTF-8` و `de_DE@euro` و `zh-Hans-CN` (`zh_Hans_CN` ← `zh_Hans` ← `zh_CN` ← `zh` ← `en`). وتُخرج `toBcp47()` رموز BCP-47 صالحة لسمات HTML `lang`.
- **الكشف التلقائي عن الاتجاه من اليمين إلى اليسار (RTL):** يتم التعرف تلقائيًا على **15 لغة ونظام كتابة RTL** — بما في ذلك العربية (`ar`)، والفارسية (`fa`)، والعبرية (`he`)، والأردية (`ur`)، والبشتوية (`ps`)، والكردية الوسطى (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa` وغيرها) — مما يحوّل تخطيط المكوّن تلقائيًا إلى `dir="rtl"`.
- **القائمة المسموح بها لكتالوجات الترجمة وقت التجميع:** يقبل `FileManagerBundle` فقط رموز اللغات التي تطابق كتالوجات ترجمة حقيقية، ما يمنع تضخّم الذاكرة المؤقتة أو OPcache (هجمات DoS) عبر معاملات لغات مزوّرة عشوائية.
- **إضافة لغة جديدة (مثل السويدية `sv`) أو تخصيص جزئي:**
  ما عليك سوى إنشاء الملف `translations/LiveUXFileManagerBundle.sv.yaml` في مشروعك (أو تجاوز مفاتيح محددة في لغة قائمة مثل `LiveUXFileManagerBundle.ar.yaml`). أي مفتاح غير مذكور يرجع تلقائيًا إلى الإنجليزية (`en`) — فلن يرى المستخدمون أبدًا مفاتيح ترجمة خام مثل `ui.upload`. انسخ ملف الحزمة `translations/LiveUXFileManagerBundle.en.yaml` كقالب بداية كامل.

---

## 5. الأسئلة الشائعة واستكشاف الأخطاء وإصلاحها (FAQ)

### 1. خطأ `window.LiveUXFileManager is not initialized / undefined`
- تأكّد من أن ملف `assets/app.js` يتضمن `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- تحقّق من تفعيل `defaults.auto_inject_dialog_host: true` (وإذا كنت قد عطّلته، فضمّن `DialogHost.html.twig` يدويًا في `base.html.twig`).
- شغّل `php bin/console cache:clear` ثم أعد تحميل المتصفح بقوة (`Ctrl+Shift+R`).

### 2. كل طلب POST يُعيد الخطأ `403 Invalid or missing CSRF token`
- تأكّد من تثبيت `symfony/security-csrf` وتفعيل كل من `framework.csrf_protection: true` و`framework.session.enabled: true`.
- إذا كنت ترسل طلبات `fetch()` مخصّصة مباشرةً، فأضف الترويسة `X-LiveUX-FM-CSRF`.

### 3. يظهر لي الخطأ `403 Access denied`
- راجع قسم `permissions` لملف التعريف في `liveux_file_manager.yaml`. تذكّر: حتى عند ضبط الصلاحية على `true` يظل المستخدم المصادق عليه مطلوبًا، كما أن صلاحية `permissions.view` مطلوبة لبقية الإجراءات كافة.
- إذا نفّذت Symfony Voter مخصّصًا، فتحقّق من `access_decision_manager.strategy` (مثل `unanimous` مقابل `affirmative`) في `config/packages/security.yaml`.

### 4. يفشل رفع الملفات الكبيرة (`upload_max_filesize`)
- مع تفعيل `chunk_upload.enabled: true`، يُضبط حجم الجزء (Chunk) تلقائيًا عند `80%` من حد `upload_max_filesize`/`post_max_size` في PHP (مثل أجزاء بحجم `1.60 MB` عند حد PHP قدره `2M`، وحتى 2,000 جزء).
- يتحكم خيار `upload_max_size` في `liveux_file_manager.yaml` بإجمالي الحجم المسموح به للملف (مثل ضبط `upload_max_size: '100M'` لمقاطع فيديو بحجم 100 ميجابايت).

### 5. تنزيل الملف يعطّل الصفحة (Symfony UX Turbo Drive)
- تتضمن جميع روابط التنزيل المدمجة بالفعل السمتين `data-turbo="false" download`. وإذا قمت بتجاوز قوالب Twig، فتأكّد من إضافة `data-turbo="false"` إلى وسوم `<a>` المخصّصة للتنزيل.

### 6. لا يتم توليد الصور المصغّرة وتُعرض الصور الأصلية فقط
- شغّل `php bin/console file-manager:doctor` وتحقّق من أن سطر `Thumbnail Driver` يُظهر أن `gd` أو `imagick` نشط.
- تأكّد من أن مجلد `thumbnail.cache_dir` (`public/media-cache`) قابل للكتابة من قِبل مستخدم خادم الويب.

### 7. خطأ `cURL error 60: SSL certificate problem` على Windows عند الاتصال بـ S3 / Supabase
- نزّل حزمة `cacert.pem` محدثة واضبط `curl.cainfo` و`openssl.cafile` في `php.ini`، أو حدّد `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` في وحدة تخزين S3.

### 8. تغييرات الواجهة الأمامية لا تظهر في المتصفح
- **في بيئة التطوير:** شغّل `php bin/console cache:clear` ثم أعد التحميل بقوة (`Ctrl+Shift+R`).
- **في بيئة الإنتاج (AssetMapper):** شغّل `php bin/console asset-map:compile`.

---

[→ السابق: 07 — التخزين السحابي والإعداد الديناميكي](07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](README.md) | [التالي: 09 — التكامل مع Symfony AI ←](09-symfony-ai-integration.md)
