# 02 — التهيئة وملفات التعريف (المرجع الكامل للمعاملات والوصفات)

[← السابق: 01 — المتطلبات والتثبيت](01-requirements-and-installation.md) | [فهرس التوثيق](README.md) | [التالي: 03 — أوضاع الاستخدام وواجهة JS API →](03-usage-modes-and-js-api.md)

يشرح هذا الدليل **كل خيار** في ملف `config/packages/liveux_file_manager.yaml`، وقيمته الافتراضية، ومتى ينبغي تخصيصه، بالإضافة إلى **5 وصفات جاهزة للإنتاج قابلة للنسخ واللصق** مباشرة في مشروعك.

---

## النموذج الذهني في 30 ثانية: `defaults` و`volumes` و`profiles`

تخيّل ملف التهيئة مقسّماً إلى **3 طبقات واضحة**:

1. **`defaults` (الإعدادات الافتراضية العامة):** خط أساس مشترك يرثه كل ملف تعريف (السمة، اللغة، وضع الألوان، التخزين المؤقت، محرّك البيانات الوصفية، سجل التدقيق، إعدادات الذكاء الاصطناعي). ما لم يُتجاوز في ملف تعريف محدد، تسري هذه القيم على الجميع.
2. **`volumes` (وحدات التخزين — أقراص / حاويات سحابية):** تحدّد **أين تُحفظ الملفات فعلياً** (مجلد محلي، AWS S3، Supabase Storage، Cloudflare R2، Backblaze B2، MinIO، Google Cloud Storage، أو خدمة Flysystem مخصّصة).
3. **`profiles` (ملفات التعريف):** تحدّد **القواعد وتخطيط الواجهة والصلاحيات** لشاشة معيّنة أو مسار عمل محدّد. على سبيل المثال:
   - يسمح `product_catalog` بصور منتجات مربعة `1:1` فقط ويحوّلها تلقائياً إلى WebP؛
   - يتصل `customer_docs` بحاوية Supabase S3 خاصة، ولا يقبل سوى ملفات PDF، ويعزل كل مستخدم داخل مجلده الخاص؛
   - يفتح `editor_picker` نافذة اختيار مبسّطة لمحرر TinyMCE تتيح الاختيار بنقرة مزدوجة.

---

## 1. `defaults` — الإعدادات الافتراضية العامة

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = يرث لغة Symfony النشطة (ويرجع إلى 'en')
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

| المعامل | القيمة الافتراضية | الوظيفة | متى تغيّره |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | المجلد الجذري الافتراضي لملفات التعريف المختصرة التي تعرّف `path` مباشرة دون `volumes`. | عند حفظ الملفات المحلية في مجلد آخر مثل `public/media`. |
| `theme` | `'bootstrap5'` | السمة الافتراضية للواجهة في جميع ملفات التعريف (`bootstrap5` و`tailwind` و`shadcn` و`flowbite` و`daisyui` و`tabler` و`preline` و`material` و`unstyled` أو اسم سمتك المخصّصة). | لمطابقة نظام التصميم في تطبيقك (مثل `'shadcn'` أو `'tailwind'` في مشاريع Tailwind). |
| `locale` | `null` | عند ضبطه على `null` يرث تلقائياً لغة طلب/مترجم Symfony عبر **12 لغة واجهة مدمجة** (`en` و`tr` و`fr` و`de` و`es` و`ar` و`it` و`pt` و`nl` و`pl` و`ja` و`zh_CN`)، ويطبّع صيغ BCP-47 وPOSIX (`de_DE.UTF-8` و`zh-Hans-CN`)، ويُفعّل RTL (`dir="rtl"`) تلقائياً للعربية ولغات RTL الأخرى، ويرجع إلى `'en'` للغات غير المعروفة. | اضبطه صراحةً (مثل `'ar'` أو `'en'` أو `'fr'`) لتثبيت جميع المستخدمين على لغة واجهة واحدة. |
| `color_scheme` | `'auto'` | وضع الألوان: `'auto'` (يكتشف `.dark` و`[data-bs-theme="dark"]` و`[data-theme="dark"]`)، أو `'light'` (فاتح دائماً)، أو `'dark'` (داكن دائماً). | عند فرض وضع فاتح فقط أو داكن فقط للوحة التحكم. |
| `z_index` | `1050` | مستوى التراص الأساسي (`--liveux-fm-z-base`) للنوافذ المنبثقة وألواح OffCanvas وLightbox وقوائم السياق (`>= 1`). | ارفعه مثلاً إلى `10050` إذا كان شريط التنقل في لوحة التحكم يستخدم `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | يحقن تلقائياً حاوية `<dialog>` اللازمة لدالة `window.LiveUXFileManager.open()` قبل `</body>` في صفحات HTML الكاملة (دون تعديل `base.html.twig`). | اضبطه على `false` إذا كنت تفضّل تضمين `@LiveUXFileManager/components/DialogHost.html.twig` يدوياً في قوالب محددة فقط. |
| `dialog_host_profile` | `'default'` | ملف التعريف الافتراضي الذي تستخدمه حاوية `DialogHost` المحقونة تلقائياً عند عدم تمرير ملف تعريف. | عندما لا يكون ملف تعريفك الرئيسي باسم `default` (مثل `admin_media`). |
| `cache.enabled` | `true` | ذاكرة تخزين مؤقت PSR-6 على جانب الخادم لقوائم المجلدات وشجرة المجلدات. | اضبطه على `false` أثناء التصحيح المحلي (أو عطّله لكل ملف تعريف/وحدة تخزين). |
| `cache.pool` | `'cache.app'` | تجمّع التخزين المؤقت PSR-6 في Symfony (مثل Redis أو Filesystem أو APCu). يستخدم تلقائياً `<pool>.taggable` عند توفره لإبطال الوسوم بدقة. | لعزل مدخلات ذاكرة مدير الملفات في تجمّع Redis مخصّص. |
| `cache.ttl` | `3600` | عمر الذاكرة المؤقتة بالثواني (الافتراضي: ساعة واحدة). تقوم عمليات التعديل (رفع، حذف، نقل، إعادة تسمية) بإبطال المجلدات المتأثرة تلقائياً. | خفّضه إلى `60`–`300` إذا كانت الملفات تُعدّل بكثرة من الخارج عبر FTP أو لوحات السحابة. |
| `metadata.enabled` | `true` | حفظ ألوان وأيقونات وتثبيت المجلدات والبيانات الوصفية لـ SEO للملفات (`alt` و`title` و`caption` و`tags` و`focalPoint`). | اضبطه على `false` لمنتقيات الملفات البسيطة التي لا تحتاج بيانات تحريرية. |
| `metadata.driver` | `'json_file'` | محرّك حفظ البيانات الوصفية: `'json_file'` (ملف محلي للتطبيقات على خادم واحد)، أو `'doctrine'` (تخزين DBAL مدمج للأنظمة متعددة الخوادم / موازنات الأحمال على MySQL وMariaDB وPostgreSQL وSQLite)، أو `'none'`. | انتقل إلى `'doctrine'` متى شغّلت عدة نسخ من التطبيق (Kubernetes أو Load Balancer أو Docker Swarm) — راجع [الفصل 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | مسار الحفظ لمحرّك `json_file`. كما يُحفظ ملف جانبي مرافق لسلة المهملات (`trash-meta.json` أو `<name>.trash-meta.json`) في نفس المجلد لحاويات السحابة المقيّدة بأنواع MIME. | انقله إلى مسار NFS/وحدة تخزين مشتركة عند الحاجة. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | اسم اتصال DBAL وEntityManager واسم الجدول (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) وخيار الإنشاء التلقائي للجدول عند استخدام `driver: 'doctrine'`. | اضبط `auto_create_table: false` في الإنتاج عند إدارة المخطط عبر Doctrine Migrations (راجع [الفصل 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | يكتب سجلات تدقيق مهيكلة وفق PSR-3 (`user` و`ip` و`path` و`volume`) لجميع عمليات تعديل الملفات/المجلدات التسع (رفع، حذف، نقل، نسخ، إعادة تسمية، تكرار، إنشاء مجلد، استعادة من السلة، حذف نهائي من السلة). | فعّله (`true`) في البيئات التي تتطلب الامتثال ومعرفة *"من حذف ماذا، ومتى؟"*. |
| `audit_log.level` | `'info'` | مستوى سجل PSR-3 لمدخلات التدقيق (`debug` أو `info` أو `notice` أو `warning`). | اضبطه بما يناسب قواعد التوجيه والتصفية في Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | نموذج الرؤية/النص الافتراضي عند تثبيت `symfony/ai-bundle` الاختياري (راجع [الفصل 09](09-symfony-ai-integration.md)). | بدّله إلى Gemini أو Claude أو نموذج Ollama محلي. |
| `ai.platform_service` | `null` | معرّف خدمة `PlatformInterface` مخصّص لـ Symfony AI (`null` يستخدم المنصة المسجلة تلقائياً). | عندما يعرّف تطبيقك أكثر من منصة Symfony AI. |

---

## 2. `volumes` — وحدات التخزين (محلي وسحابي)

يمكن لملف تعريف واحد ربط عدة وحدات تخزين في آنٍ واحد (مثلاً القرص المحلي إلى جانب Supabase أو R2 أو S3). كما يمكن للمستخدمين سحب الملفات وإفلاتها مباشرة من مجلد محلي إلى حاوية سحابية.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'القرص المحلي'
            icon: 'disk'              # اختياري (يُكتشف تلقائياً من driver/endpoint عند حذفه)
            group: 'local'            # اختياري (null -> 'local' لـ LocalFileSystem، و'network' لـ S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # عمر تخزين مؤقت اختياري لوحدة التخزين (0 = معطّل)

        supabase_s3:
            label: 'تخزين Supabase'
            icon: 'supabase'          # يُكتشف تلقائياً أيضاً من نقطة النهاية
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # تظهر تعديلات لوحة السحابة الخارجية خلال 60 ثانية
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| المعامل | القيمة الافتراضية | الوظيفة | متى تغيّره |
| :--- | :--- | :--- | :--- |
| `label` | مفتاح وحدة التخزين | اسم العرض الظاهر في الشريط الجانبي ومسار التنقل ومبدّل وحدات التخزين. | ضع عنواناً واضحاً للمستخدم (مثل `'وسائط المنتجات CDN'`). |
| `icon` | `null` *(تلقائي)* | أيقونة SVG في الشريط الجانبي. عند `null` تُكتشف تلقائياً من `driver` و`endpoint` (`disk` و`aws_s3` و`cloudflare_r2` و`supabase` و`backblaze_b2` و`minio` و`gcs` و`sftp` و`cloud`). | وجّهه إلى أيقونة مخصّصة `<dir>/ui/my_icon.svg` ضمن مجموعة أيقوناتك. |
| `group` | `null` *(تلقائي)* | مجموعة القسم في الشريط الجانبي. عند `null` يأخذ `LocalFileSystem` القيمة `'local'` ويأخذ `AwsS3` و`FlysystemService` القيمة `'network'`. | تجاوزه إذا أردت تصنيف وحدة NFS تحت `'network'` أو قرصاً سحابياً تحت `'local'`. |
| `driver` | `'LocalFileSystem'` | الواجهة الخلفية للتخزين: نظام ملفات محلي (`LocalFileSystem`)، أو تخزين كائنات متوافق مع S3 (`AwsS3`)، أو خدمة Flysystem جاهزة (`FlysystemService`). | استخدم `'AwsS3'` لـ S3/R2/Supabase/B2/MinIO/GCS، أو `'FlysystemService'` لـ SFTP/Azure/Dropbox. |
| `path` | `null` | المسار الفيزيائي للمجلد مع `LocalFileSystem` (يدعم `{user_id}` و`{Y}` و`{m}` و`{d}`). | لتحديد جذر مخصّص أو مسار عزل لكل مستخدم (`.../users/{user_id}`). |
| `url_prefix` | `null` | بادئة الرابط العام التي يستخدمها المتصفح للوصول إلى الملفات العامة (`/uploads` أو `https://cdn.example.com`). تُتجاهل عند `private: true`. | اضبطها عند تقديم الأصول عبر CDN أو حاوية S3/R2/Supabase عامة. |
| `private` | `false` | عند `true` لا تُكشف الملفات أبداً عبر روابط عامة مباشرة — بل تمر جميع التنزيلات والمعاينات والصور المصغرة عبر فحوصات Symfony Voter (`DownloadController`). | **اضبطه دائماً على `true`** للفواتير ووثائق الهوية والعقود وملفات العملاء الخاصة. |
| `cache_ttl` | `null` *(موروث)* | عمر الذاكرة المؤقتة (بالثواني) الخاص بهذه الوحدة. القيمة `0` تعطّل التخزين المؤقت لهذه الوحدة؛ و`null` ترث من ملف التعريف أو الافتراضيات. | أبقِ القرص المحلي مخزّناً مؤقتاً لمدة `3600` ثانية مع تقييد الحاوية السحابية بـ `60` ثانية أو `0`. |
| `driver_options` | `[]` | **AwsS3:** `bucket` و`region` و`endpoint` و`key` و`secret` و`token` *(رمز جلسة STS مؤقت)* و`version` *(الافتراضي `'latest'`)* و`prefix` و`use_path_style_endpoint` و`acl` و`disable_acl` *(تلقائياً `true` لنقاط النهاية المخصّصة؛ والافتراضي `false` على AWS S3 القياسي — اضبطه `true` صراحةً لوضع Bucket owner enforced)* و`retain_visibility` و`request_checksum_calculation` و`response_checksum_validation` و`ca_bundle`. **FlysystemService:** `service`. | راجع [الفصل 07](07-cloud-storage-and-dynamic-config.md) لأدلة المزوّدين التفصيلية. |

---

## 3. `profiles` — قاموس معاملات ملفات التعريف

### 3.1. التخزين الأساسي والتصفية وحصة التخزين
| المعامل | القيمة الافتراضية | الوظيفة | متى تغيّره |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(من defaults)* | يتجاوز لغة الواجهة ورسائل الخطأ لهذا الملف التعريفي (`'ar'` أو `'en'` أو `'tr'` أو `'fr'` أو `'de'` وغيرها؛ القيمة `null` ترث `defaults.locale` ← لغة طلب Symfony ← `'en'`). | عندما تستهدف لوحات مختلفة داخل التطبيق نفسه لغات مختلفة. |
| `volumes` | `[]` | قائمة مرتّبة بمفاتيح وحدات التخزين المتاحة في هذا الملف التعريفي (مثل `['local_uploads', 'supabase_s3']`). تُفتح الوحدة الأولى افتراضياً. | لربط وحدة تخزين محلية/سحابية واحدة أو أكثر. |
| `ignore_dot_files` | `true` | يخفي الملفات التي تبدأ بنقطة (`.env` و`.htaccess` و`.DS_Store` و`.trash`) من القوائم ويمنع الوصول المباشر إليها. | أبقِه `true` لدواعي الأمان. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | يخفي ويمنع الوصول المباشر إلى الملفات أو المجلدات المطابقة لأنماط regex أو wildcards. | لإخفاء مجلدات داخلية مثل `/^cache$/i` أو `/^backups$/i`. |
| `sort_by` | `'name'` | عمود الترتيب الافتراضي: `'name'` أو `'date'` أو `'size'` أو `'type'`. | استخدم `'date'` مع `sort_direction: 'desc'` لعرض أحدث الملفات المرفوعة أولاً. |
| `sort_direction` | `'asc'` | اتجاه الترتيب: `'asc'` (تصاعدي) أو `'desc'` (تنازلي / الأحدث أولاً). | يناسب `'desc'` معارض الوسائط والمكتبات التحريرية. |
| `folders_first` | `true` | يعرض المجلدات دائماً قبل الملفات. | اضبطه `false` لترتيب مسطّح أبجدي أو زمني. |
| `storage_quota` | `null` | الحد الأقصى لإجمالي المساحة المسموح بها لملف التعريف (مثل `'500M'` أو `'2G'`). عند بلوغه تُرفض عمليات الرفع على الخادم ويظهر شريط الاستهلاك في الشريط الجانبي. | ضروري لتطبيقات SaaS متعددة المستأجرين أو حصص المستخدمين (`{user_id}`). |
| `upload_subfolder` | `null` | قالب مجلد فرعي ديناميكي تُوضع فيه الملفات المرفوعة تلقائياً تحت المجلد الحالي (مثل `'{Y}/{m}'`). | لتنظيم المرفوعات تلقائياً في مجلدات تاريخية مثل `2026/11`. |

### 3.2. الرفع والرفع المجزّأ (`chunk_upload`) واكتشاف التكرار (`duplicate_detection`)
| المعامل | القيمة الافتراضية | الوظيفة | متى تغيّره |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(كل الأنواع الآمنة)* | قائمة سماح لأنواع MIME المقبولة (مثل `['image/jpeg', 'image/png', 'image/webp']`). عندما تكون فارغة تُسمح جميع الأنواع الآمنة غير المدرجة في `upload_deny`. | قيّدها دائماً بأنواع MIME محدّدة لنماذج الصور فقط أو PDF فقط. |
| `upload_deny` | **47 نوع MIME خطيراً** | أنواع MIME المحظورة بصرامة (PHP وHTML وXML غير SVG وJS وShell وEXE وJava وCGI وغيرها — يُتحقّق منها على الخادم عبر magic bytes). | أضف أنواع MIME محظورة إضافية إذا تطلّبت سياستك ذلك. |
| `upload_max_size` | `'10M'` | الحد الأقصى للحجم **المجمّع** للملف الواحد المرفوع (مثل `'200M'` أو `'1G'`). ومع تفعيل الرفع المجزّأ، تُرفع الملفات الأكبر بكثير من حد PHP البالغ `2M` بسلاسة حتى هذا السقف. | ارفعه إلى `'200M'`–`'500M'` لملفات الفيديو وكتالوجات PDF، أو خفّضه إلى `'2M'` للصور الشخصية. |
| `overwrite_policy` | `'rename'` | سلوك التصادم عند وجود ملف بالاسم نفسه: `'rename'` (`photo-1.jpg`)، أو `'overwrite'`، أو `'reject'`. | أبقِه `'rename'` لمنع فقدان البيانات بالخطأ. |
| `chunk_upload.enabled` | `true` | يقسّم الملفات الكبيرة إلى أجزاء صغيرة مع دعم الإيقاف المؤقت / الاستئناف / الإلغاء المؤكّد وإعادة المحاولة التلقائية عند انقطاع الشبكة العابر. | نادراً ما يُعطّل — فقط إذا كنت ترفع ملفات صغيرة جداً (< 1 م.ب). |
| `chunk_upload.chunk_size` | `'2M'` | حجم الجزء المستهدف. يُضبط تلقائياً بحيث لا يتجاوز **80%** من حدّي `upload_max_filesize` و`post_max_size` في PHP (مثلاً 1.60 م.ب عند حد PHP قدره 2M). | ارفعه إلى `'5M'` على الخوادم ذات حدود PHP المرتفعة (مثل 32M+) لتسريع رفع الملفات الضخمة. |
| `chunk_upload.parallel_uploads` | `3` | الحد الأقصى لعمليات الرفع المتزامنة (`1`–`10`). | استخدم `2` على الخوادم محدودة الموارد، أو `4`–`5` على روابط سحابية/CDN سريعة. |
| `chunk_upload.max_retries` | `3` | عدد محاولات إعادة الإرسال بتأخير متزايد لكل جزء عند انقطاع الشبكة (`0`–`10`). | القيمة `3`–`5` مناسبة لشبكات الجوال أو الاتصالات غير المستقرة. |
| `duplicate_detection.enabled` | `false` | يكتشف رفع الملفات المكررة عبر بصمة المحتوى SHA-256. | فعّله (`true`) لمنع المحررين من رفع صورة المنتج نفسها مراراً. |
| `duplicate_detection.policy` | `'warn'` | الإجراء عند اكتشاف تكرار: `'warn'` (رفع مع تنبيه)، أو `'reject'` (منع الرفع)، أو `'reuse'` (إعادة استخدام الملف الموجود دون تكرار التخزين). | يوفّر خيار `'reuse'` مساحة تخزين كبيرة في المتاجر الإلكترونية وأنظمة إدارة المحتوى. |
| `duplicate_detection.max_scan_files` | `5000` | الحد الأعلى لعدد الملفات المفهرسة لاكتشاف التكرار (`100`–`50000`) لتفادي ضغط المعالج والإدخال/الإخراج في الأرشيفات الضخمة. | ارفعه إلى `10000`–`25000` لمكتبات الوسائط الكبيرة. |

### 3.3. التطبيع التلقائي لأسماء الملفات (`naming`)
| المعامل | القيمة الافتراضية | الوظيفة | مثال (`"Product Photo 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | يحوّل الأحرف غير اللاتينية (Unicode) والمسافات إلى سلاسل ASCII نظيفة وملائمة للروابط. | `product-photo-2026.jpg` |
| `naming.slugger_locale` | `null` | لغة النقل الصوتي (`null` ترث لغة ملف التعريف؛ مثلاً `'de'` تحوّل `ä/ö/ü/ß`، و`'tr'` تحوّل `İ/ı/ş/ğ/ç/ö/ü`). | اضبطها على لغة المحتوى لديك (مثل `'ar'` أو `'en'` أو `'de'`). |
| `naming.transformer` | `'none'` | تحويل حالة الأحرف/التسمية: `'none'` أو `'lowercase'` أو `'uppercase'` أو `'camel'` أو `'snake'` أو `'cameltitle'` أو `'title'` أو `'titleall'` أو `'folder'` (يسمّي الملف باسم المجلد الأب!). | `'snake'` ← `product_photo_2026.jpg`، و`'folder'` ← `tumblers-1.jpg` |
| `naming.prepend` / `append` | `''` | يضيف نصاً ثابتاً أو رموزاً ديناميكية (`{Y}` و`{m}` و`{d}` و`{uniqid}`) في البداية أو النهاية. | `prepend: '{Y}{m}_'` ← `202611_product.jpg` |
| `naming.apply_on_rename` | `true` | يطبّق أيضاً قواعد `slugger` و`transformer` عند إعادة تسمية ملف من الواجهة. | أبقِه `true` لضمان بقاء الأسماء نظيفة بعد التعديل. |
| `naming.apply_affixes_on_rename` | `false` | يمنع تكرار بادئات ولواحق `prepend`/`append` في كل مرة يُعاد فيها تسمية الملف. | أبقِه `false`. |
| `naming.allow_extension_change` | `false` | يمنع المستخدمين من تغيير امتداد الملف (مثل `.jpg` ← `.php` أو `.png`) أثناء إعادة التسمية. | أبقِه `false` لدواعي الأمان. |

### 3.4. تحسين الصور (`image_optimization`) وشروط الصور (`image_constraints`)
- **`image_optimization`:** يصحّح اتجاه الصورة تلقائياً، ويزيل بيانات EXIF، ويضبط الأبعاد، ويضغط الصور الكبيرة على الخادم أثناء الرفع.
- **`image_constraints`:** يرفض الصور غير المطابقة **خلال 0ms في المتصفح وعلى الخادم معاً** (مثلاً منع رفع صور منخفضة الدقة أو صور منتجات غير مربعة).

| المعامل | القيمة الافتراضية | الوظيفة | متى تستخدمه |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | يُفعّل تحسين الصور وقت الرفع (Imagick ← GD ← تجاهل صامت إن لم يتوفّر أي منهما). | فعّله (`true`) في أي ملف تعريف يرفع فيه المستخدمون صوراً خاماً من الهواتف الذكية. |
| `image_optimization.auto_orient` | `true` | يقرأ وسوم اتجاه EXIF ويدوّر صور الهاتف المائلة تلقائياً. | أبقِه `true` لرفع الصور من الجوال. |
| `image_optimization.strip_exif` | `true` | يزيل إحداثيات GPS وبيانات الكاميرا EXIF (حماية خصوصية GDPR وتقليل حجم الملف). | أبقِه `true` لجميع الصور المنشورة للعامة. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | يصغّر الصور التي تتجاوز هذه الحدود مع الحفاظ على نسبة العرض إلى الارتفاع (`>= 100`). | `1920`×`1920` توازن مثالي لصور الويب. |
| `image_optimization.convert_to_webp` | `false` | يحوّل صور JPEG/PNG المرفوعة تلقائياً إلى WebP (حجم أصغر بنسبة 30–60%). | فعّله (`true`) لملفات التعريف التي تستهدف أعلى أداء على الويب. |
| `image_optimization.quality` | `85` | جودة ضغط المخرجات (`10`–`100`). | `80`–`85` تمنح أفضل توازن بين الحجم والجودة. |
| `image_constraints.min_width` / `min_height` | `null` | الحد الأدنى المطلوب للأبعاد بالبكسل. | لمنع الصور الضبابية (مثلاً أغلفة المقالات لا تقل عن `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | الحد الأقصى المسموح للأبعاد بالبكسل (يُفحص *بعد* تصغير `image_optimization` عند تفعيل التحسين). | لفرض أبعاد صارمة لقوالب الإعلانات أو الطباعة. |
| `image_constraints.aspect_ratio` | `null` | نسبة العرض إلى الارتفاع المطلوبة: `'1:1'` (مربع)، `'16:9'` (عريض)، `'4:3'`، `'3:4'`، `'1.91:1'`، وغيرها. | بطاقات منتجات مربعة (`'1:1'`) أو ترويسات فيديو/مقالات (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | هامش التسامح المسموح به عن النسبة المستهدفة (`0.0`–`0.5`؛ القيمة `0.05` = سماحية 5%، بحيث يمر كل من `1920×1080` و`1280×722`). | القيمة `0.05` مناسبة لمعظم الحالات تقريباً. |

### 3.5. الصور المصغرة وسلة المهملات (`trash`) وZIP (`zip`) وروابط المشاركة الموقّعة (`share`)
| المعامل | القيمة الافتراضية | الوظيفة |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | توليد صور مصغرة مخزّنة مؤقتاً: `'auto'` (Imagick ← GD) أو `'imagick'` أو `'gd'` أو `'liip_imagine'` أو `'none'`. تُجلب الصور السحابية مرة واحدة وتُخزّن مصغراتها محلياً. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | السلوك عند غياب GD وImagick معاً: `'fallback_original'` (عرض الصورة الأصلية)، أو `'icon'` (عرض أيقونة الملف)، أو `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | أبعاد الصورة المصغرة (`>= 32`) وصيغة الإخراج (`webp` أو `jpeg` أو `png`) ووضع الملاءمة (`contain` = عرض الصورة كاملة، `cover` = قص مركزي لملء الإطار). يُنصح بـ `480`×`360` عند استخدام `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | يضيف السمة الأصلية `loading="lazy"` لصور بطاقات الشبكة لتسريع العرض الأولي في المجلدات الكبيرة. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | المجلد المحلي الذي تُحفظ فيه الصور المصغرة المولّدة. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | الحذف المبدئي للملفات إلى مجلد سلة المهملات بدلاً من إتلافها فوراً. ينتقل تلقائياً إلى ملف جانبي محلي `trash-meta.json` في الحاويات السحابية المقيّدة بأنواع MIME. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | يُفعّل تنزيل عدة ملفات/مجلدات بنقرة واحدة كأرشيف `.zip` (يتطلب إضافة PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | استخراج أرشيفات محمي بـ 7 طبقات ضد Zip-Slip وقنابل ZIP. يظل محظوراً ما دام `allow_extract: false`؛ وعند تفعيله تصبح `permissions.extract_zip` تلقائياً `true` (ويمكنك حصرها بـ `'ROLE_ADMIN'` أو `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | يولّد روابط مشاركة موقّعة بتقنية HMAC ومحدّدة المدة عبر `UriSigner` في Symfony. تعطيل `enabled: false` يُبطل فوراً جميع روابط المشاركة الصادرة سابقاً. |

### 3.6. واجهة المستخدم (`ui`) — التخطيط والمقاسات وخيارات شريط الأدوات
| المعامل | القيمة الافتراضية | الوظيفة | متى تغيّره |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(من defaults)* | سمة خاصة بملف التعريف (مثلاً `'bootstrap5'` في الإدارة و`'shadcn'` في بوابة العملاء). | عندما تستخدم أقسام مختلفة من التطبيق سمات بصرية مختلفة. |
| `ui.color_scheme` | `null` *(من defaults)* | وضع الألوان الخاص بملف التعريف (`auto` أو `light` أو `dark`). | لتثبيت `'dark'` في مساحة عمل داكنة فقط. |
| `ui.icon_set` | `'classic'` | نمط أيقونات الملفات والمجلدات: `classic` أو `windows11` أو `macos` أو `linux` أو `minimal` أو `vscode` أو مسار مجلد SVG مخصّص (`'%kernel.project_dir%/assets/fm-icons'`). | اختر `'windows11'` أو `'macos'` لمظهر سطح مكتب مألوف، أو وجّهه إلى أيقونات SVG تحمل هويتك البصرية. |
| `ui.z_index` | `null` *(من defaults)* | تجاوز قيمة `z-index` للطبقات المنبثقة لهذا الملف التعريفي. | عند التضمين في صفحة تحتوي محررات أو أدراجاً ذات `z-index` مرتفع. |
| `ui.mobile_dialog_mode` | `'auto'` | طريقة عرض النوافذ على الشاشات الصغيرة (`<= 767.98px`): `'auto'` (تُفتح النوافذ المنبثقة والألواح الجانبية بملء الشاشة، بينما تُفتح الألواح العلوية والسفلية كألواح منسدلة مستديرة الحواف)، أو `'fullscreen'` (الكل ملء الشاشة)، أو `'sheet'` (الكل كلوح سفلي). | لفرض `'sheet'` أو `'fullscreen'` على جميع منتقيات الجوال. |
| `ui.default_view` | `'grid'` | التخطيط الابتدائي: `'grid'` (بطاقات مصغرة) أو `'list'` (جدول قابل للترتيب). | استخدم `'list'` لأرشيفات PDF والمستندات و`'grid'` لمعارض الصور. |
| `ui.item_size` | `'md'` | عرض بطاقات الشبكة وحجم المصغرة في القائمة: `'xs'` (`108px`) أو `'sm'` (`132px`) أو `'md'` (`158px`) أو `'lg'` (`204px`) أو `'xl'` (`260px`) أو عدد صحيح دقيق بالبكسل (`96`–`320`). يتحوّل تلقائياً إلى حشو مضغوط عند `<= 132px`. | استخدم `'lg'` / `'xl'` للمراجعة البصرية و`'sm'` / `'xs'` للمنتقيات المضغوطة. |
| `ui.card_aspect_ratio` | `'4/3'` | نسبة إطار المعاينة في البطاقة: `'1/1'` (مربع)، `'4/3'` (قياسي)، `'16/9'` (عريض/فيديو)، أو `'3/4'` (ملصقات وكتالوجات طولية). | طابِق شكل أصولك الأساسية (`'1/1'` للمنتجات و`'16/9'` للفيديو واللافتات). |
| `ui.folder_style` | `'tile'` | شكل المجلدات في عرض الشبكة: `'tile'` (بلاطات مجلدات أفقية مضغوطة بأسلوب Google Drive أعلى الملفات) أو `'card'` (بطاقات مجلدات كاملة الحجم بأسلوب Windows/Finder مطابقة لبطاقات الملفات). | بدّل إلى `'card'` لإبراز المجلدات بصرياً بشكل أكبر. |
| `ui.show_size_slider` | `true` | يُظهر منزلق حجم العناصر الحي (`96px`–`320px`) ومبدّل نمط المجلد داخل قائمة الإعدادات. | اضبطه `false` لتثبيت كثافة التخطيط ومنع تغييرها. |
| `ui.volume_order` | `'local_first'` | ترتيب وحدات التخزين في الشريط الجانبي والمبدّل: `'local_first'` أو `'network_first'` أو `'configured'` (نفس ترتيب `volumes: [...]` في YAML دون فواصل مجموعات). | استخدم `'network_first'` أو `'configured'` لمسارات العمل المعتمدة على السحابة أولاً. |
| `ui.show_folder_tree` | `true` | إظهار أو إخفاء الشريط الجانبي لشجرة المجلدات. | اضبطه `false` لمنتقيات TinyMCE أو الصور الشخصية البسيطة ذات المجلد الواحد. |
| `ui.tree_lines` | `true` | عرض خطوط الربط الهرمية داخل شجرة المجلدات. | اضبطه `false` لشريط جانبي أبسط وأكثر هدوءاً. |
| `ui.show_folder_item_count` | `true` | يعرض شارات عدد العناصر (`12`) بجانب المجلدات، مع احترام الصلاحيات بحيث لا تتسرب المجلدات الفرعية المحظورة في العدد. | أبقِه `true` لمعرفة محتوى المجلدات سريعاً. |
| `ui.folder_count_mode` | `'recursive_files'` | آلية احتساب شارات المجلد: `'recursive_files'` (كل الملفات بما فيها المجلدات الفرعية)، أو `'files'` (الملفات المباشرة فقط)، أو `'items'` (الملفات والمجلدات الفرعية المباشرة). | اختر `'files'` للعدّ المباشر فقط. |
| `ui.inspector_mode` | `'on_select'` | لوحة المعاينة والتفاصيل الجانبية: `'on_select'` (تُفتح عند تحديد عنصر)، أو `'always'`، أو `'hidden'`. | اضبطه `'hidden'` داخل نوافذ الاختيار الضيقة. |
| `ui.folder_open_on` | `'dblclick'` | إيماءة فتح المجلد: `'dblclick'` (نقرة واحدة تحدّد، ونقرة مزدوجة تفتح — أسلوب سطح المكتب) أو `'click'` (نقرة واحدة تفتح فوراً — أسلوب الويب/الجوال). | استخدم `'click'` لتصفح أسرع بنقرة واحدة في لوحات الإدارة. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | عدد العناصر الافتراضي في الصفحة (`6`–`200`) وخيارات قائمة حجم الصفحة للمستخدم. | ارفعه إلى `48` مع `[24, 48, 96, 150]` للكتالوجات الضخمة. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | عرض شريط حصة التخزين في الشريط الجانبي ونافذة تفاصيل استهلاك التخزين القابلة للنقر. (يظل فرض `storage_quota` على الخادم نشطاً حتى عند الإخفاء.) | اضبط `show_quota: false` إن لم ترغب بإظهار عدّادات الحصة في الواجهة. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | التحكم في معاينة PDF داخل العارض المنبثق وتشغيل فيديو HTML5 المباشر (يُفرض في الواجهة وعلى الخادم داخل `DownloadController`). | اضبطه `false` لفرض التنزيل المباشر بدلاً من المعاينة داخل المتصفح. |
| `ui.remember_last_dir` | `true` | يحفظ آخر **وحدة تخزين + مجلد** زاره المستخدم في `localStorage` (ويعود تلقائياً إلى المجلد الأب أو الجذر إذا حُذف ذلك المجلد). | اضبطه `false` إذا أردت أن تبدأ كل جلسة من الجذر. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | طلب تأكيد قبل حذف العناصر أو نقلها بالسحب والإفلات. | اضبط `confirm_move: false` لتسريع عمليات إعادة التنظيم. |
| `ui.double_click_to_select` | `true` | في وضع المنتقي (Modal/OffCanvas)، يؤدي النقر المزدوج على ملف إلى اختياره وإغلاق النافذة فوراً. | اضبطه `false` إذا كنت تريد أن يفتح النقر المزدوج المعاينة بدلاً من الاختيار. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | تفعيل قوائم السياق بزر الفأرة الأيمن وشريط التنقل السريع `[ ← \| → \| ↑ \| ↻ ]` عند النقر بالزر الأيمن على المساحة الفارغة. | اضبطه `false` للشاشات المقيّدة للمستخدم النهائي. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | تفعيل النقل بالسحب والإفلات بين المجلدات وسحب الملفات/المجلدات من نظام التشغيل للرفع. | اضبطه `false` لمكتبات الأصول للقراءة فقط. |
| `ui.keyboard_shortcuts` | `true` | تفعيل اختصارات لوحة المفاتيح (`Ctrl+A` و`Ctrl+C/X/V` و`F2` و`Delete` و`Space` و`/` و`R` و`F` و`?` وغيرها). | عطّله (`false`) إذا كانت الصفحة المستضيفة تربط اختصارات عامة متعارضة. |
| خيارات `ui.show_*` | *(معظمها `true`، و`show_perf_badge: false`)* | `show_perf_badge` و`show_nav_buttons` و`show_history_buttons` و`show_up_button` و`show_refresh_button` و`show_breadcrumbs` و`show_search` و`show_type_filter` و`show_view_toggle` و`show_pagination` و`show_per_page_selector` و`show_sort` و`show_fullscreen_button` و`show_settings_button` و`show_footer` و`show_context_nav`. | فعّل أو عطّل عناصر شريط الأدوات الفردية لتبسيط الواجهة. *(ملاحظة: عنصرا "حول" و"GitHub" داخل قائمة الإعدادات هما عنصرا إسناد مدمجان ولا يُعطّلان عبر YAML.)* |

### 3.7. التخزين المؤقت (`cache`) والأمان (`security`) والصلاحيات (`permissions`) والذكاء الاصطناعي وحارس الاستخدام

#### التخزين المؤقت (`cache`)
| المعامل | القيمة الافتراضية | الوصف |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(من defaults)* | تفعيل أو تعطيل التخزين المؤقت لهذا الملف التعريفي. وعند ضبط `cache.enabled: false` على ملف التعريف، لن يعيد `volume.cache_ttl` تفعيل التخزين المؤقت. |
| `cache.ttl` | `null` *(من defaults)* | ترتيب أولوية TTL: **`volume.cache_ttl` ← `profile.cache.ttl` ← `defaults.cache.ttl`**. |

#### الأمان (`security`)
| المعامل | القيمة الافتراضية | الوصف |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | يُفعّل فرض صلاحيات RBAC عبر `FileManagerVoter` وأي Voters مخصّصة. أبقِه دائماً `true` في الإنتاج. |
| `security.csrf_protection` | `true` | يشترط وجود ترويسة `X-LiveUX-FM-CSRF` صالحة في كل طلب `POST`. |
| `security.sanitize_svg` | `true` | يعقّم ملفات SVG المرفوعة والمعدّلة وفق قائمة سماح صارمة من 55 وسماً (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | يُفعّل تبويب **"من رابط URL"** في نافذة الرفع (محمي ضد SSRF وDNS rebinding عبر `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(من defaults)* | يتجاوز تفعيل سجل التدقيق PSR-3 لهذا الملف التعريفي (`true` / `false` / `null`). |

#### الصلاحيات الـ 17 التفصيلية (`permissions`)
> [!WARNING]
> **مهم: ماذا تعني القيمة `true` تحت `permissions`؟**  
> ضبط صلاحية على **`true`** لا يعني إطلاقاً *"وصول عام / للزوّار المجهولين"*! بل يمنح `FileManagerVoter` القيمة `true` **للمستخدمين المصادق عليهم فقط (`$token->getUser() !== null`)** ويرفض الطلبات المجهولة.  
> بالإضافة إلى ذلك، تُعدّ **`permissions.view`** البوابة الرئيسية: فإذا افتقر المستخدم إلى صلاحية `view` تُرفض تلقائياً جميع العمليات الأخرى (`upload` و`delete` و`download` وغيرها).

تقبل كل صلاحية إحدى 4 صيغ:
- `true` ← أي مستخدم مصادق عليه
- `false` ← لا أحد (الإجراء معطّل بالكامل)
- `'ROLE_ADMIN'` ← المستخدمون الذين يحملون هذا الدور فقط
- `['ROLE_EDITOR', 'ROLE_ADMIN']` ← المستخدمون الذين يحملون أياً من هذه الأدوار

| مفتاح الصلاحية | القيمة الافتراضية | الإجراءات الخاضعة لها |
| :--- | :--- | :--- |
| `view` | `true` | استعراض المجلدات وشجرة المجلدات والمعاينات و**شرط أساسي لجميع الصلاحيات الأخرى**. |
| `upload` | `true` | الرفع القياسي والمجزّأ، والحفظ من محرر الصور، والاستيراد من رابط بعيد. |
| `create_folder` | `true` | إنشاء مجلدات جديدة. |
| `rename` | `true` | إعادة تسمية الملفات والمجلدات. |
| `clone` | `true` | تكرار ملف أو مجلد. |
| `move` | `true` | نقل العناصر عبر السحب والإفلات أو القص واللصق. |
| `copy` | `true` | نسخ العناصر عبر النسخ واللصق أو نافذة النقل/النسخ. |
| `delete` | `true` | حذف الملفات/المجلدات (النقل إلى سلة المهملات أو الحذف النهائي). |
| `trash_restore` | `true` | استعادة العناصر من سلة المهملات. |
| `trash_purge` | `true` | الحذف النهائي للعناصر من سلة المهملات أو إفراغ السلة بالكامل. |
| `download` | `true` | تنزيل الملفات المفردة وبث PDF/الفيديو ونسخ روابط الملفات. |
| `download_zip` | `true` | تنزيل عدة عناصر محددة كأرشيف `.zip`. |
| `extract_zip` | `null` *(تلقائي)* | تُفرض `false` ما دام `zip.allow_extract: false`؛ وتصبح تلقائياً `true` عند ضبط `zip.allow_extract: true` (يمكنك تضييقها بـ `'ROLE_ADMIN'` عند الرغبة). |
| `share` | `true` | إنشاء روابط مشاركة موقّعة بـ HMAC ومحدّدة المدة. |
| `clear_cache` | `true` | مسح الذاكرة المؤقتة من الإعدادات وفرض `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | تعديل `alt`/`title`/`caption`/`tags`/نقطة التركيز للملفات وألوان/أيقونات/تثبيت المجلدات. |
| `ai_enrich` | `true` | استدعاء **✨ اقتراح بالذكاء الاصطناعي** (يتطلب `view` + `edit_metadata` + `ai_enrich`). |

#### الإثراء بالذكاء الاصطناعي (`ai`) وحارس استخدام الأصول (`usage_guard`)
| المعامل | القيمة الافتراضية | الوصف |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | تفعيل اقتراحات البيانات الوصفية بالذكاء الاصطناعي؛ ضبط `locale` يحدّد لغة المخرجات (مثل `'ar'` أو `'en'` أو `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | الحد الأقصى للوسوم المقترحة (`1`–`20`) وحد تصغير الصورة قبل إرسالها للذكاء الاصطناعي (`256`–`2048` بكسل لتوفير رموز الرؤية). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | يمنع إرسال ملفات وحدات التخزين ذات `private: true` إلى واجهات ذكاء اصطناعي خارجية؛ وإثراء تلقائي اختياري بعد الرفع (راجع [الفصل 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | ينبّه أو يمنع عمليات **`delete` و`rename` و`move` و`overwrite`** عندما يكون الأصل مرتبطاً بسجل في قاعدة بياناتك (راجع [الفصل 10](10-asset-usage-guard.md)). |

---

## 4. 5 وصفات جاهزة للإنتاج لملفات التعريف (انسخ والصق)

فيما يلي أكثر 5 تهيئات شيوعاً في المشاريع الحقيقية جاهزة للاستخدام المباشر:

### الوصفة 1: كتالوج منتجات لمتجر إلكتروني (بطاقات مربعة + WebP + إعادة استخدام المكرّر)
> **الهدف:** قبول صور منتجات مربعة `1:1` عالية الوضوح فقط؛ تصغير صور الهواتف وتحويلها تلقائياً إلى WebP؛ إعادة استخدام الملف الموجود عند رفع مكرر؛ ومنع حذف الأصول المرتبطة بمنتجات نشطة.

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
                slugger_locale: 'en'
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
                item_size: 'lg'          # بطاقات منتجات كبيرة بعرض 204px
                card_aspect_ratio: '1/1' # نسبة عرض مربعة للمنتجات
                folder_style: 'card'     # بطاقات مجلدات كاملة الحجم للتصنيفات
                show_folder_item_count: true
```

### الوصفة 2: صور أغلفة المدونات والأخبار (لافتات `16:9` + نص بديل بالذكاء الاصطناعي)
> **الهدف:** قبول صور أغلفة عريضة `16:9` عالية الدقة فقط (بحد أدنى `1200×630` بكسل)، وتنظيم المرفوعات تلقائياً في مجلدات `2026/11`، وتوليد النص البديل والوسوم لـ SEO بالذكاء الاصطناعي.

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
            sort_direction: 'desc'    # عرض أحدث الأغلفة أولاً
            ai:
                enabled: true
                locale: 'ar'
                max_tags: 6
            ui:
                item_size: 'xl'           # معاينات عريضة للأغلفة بعرض 260px
                card_aspect_ratio: '16/9' # نسبة عرض اللافتات العريضة
```

### الوصفة 3: خزنة مستندات وفواتير PDF للعملاء (Supabase/S3 خاص + عزل لكل مستخدم)
> **الهدف:** عزل كل عميل داخل مجلده الخاص لفواتير PDF على حاوية خاصة متوافقة مع S3، ومنع الروابط العامة المباشرة، وتحديث قوائم السحابة كل 60 ثانية، وتسجيل جميع العمليات في سجل التدقيق.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'خزنة فواتير العملاء'
            group: 'network'
            driver: 'AwsS3'
            private: true          # لا وصول مباشر بروابط عامة دون مصادقة
            cache_ttl: 60          # تظهر التعديلات السحابية الخارجية خلال 60 ثانية
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # يرى كل مستخدم مجلده الخاص فقط
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
                default_view: 'list'     # جدول قابل للترتيب مثالي لملفات PDF
                item_size: 'sm'          # صفوف مضغوطة
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### الوصفة 4: منتقي سريع لمحررات النصوص المنسّقة (TinyMCE / CKEditor / Tiptap)
> **الهدف:** منتقي خفيف وخالٍ من المشتّتات في نافذة منبثقة بحيث يدرج النقر المزدوج على الصورة داخل المحرر فوراً.

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

### الوصفة 5: مكتبة شركاء وملفات صحفية للقراءة فقط
> **الهدف:** إتاحة استعراض الشعارات والكتالوجات للشركاء وتنزيل الملفات المفردة أو أرشيفات ZIP وإنشاء روابط مشاركة موقّعة — مع منع أي رفع أو تعديل أو حذف.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 أيام
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

## 5. العناصر النائبة الديناميكية في المسارات (`DynamicPathResolver`)

يمكنك استخدام عناصر نائبة ديناميكية داخل `path` المحلي و`url_prefix` و`driver_options.prefix` السحابي و`upload_subfolder` في ملف التعريف:

| العنصر النائب | الوصف | مثال للمخرج |
| :--- | :--- | :--- |
| `{user_id}` | معرّف آمن لأنظمة الملفات وخالٍ من التعارض للمستخدم المصادق عليه في Symfony (`guest` إن لم يكن مصادقاً عليه). | `42` أو `alice_a--h-9f1c2e4b...` |
| `{Y}` | السنة بأربعة أرقام | `2026` |
| `{m}` | الشهر برقمين | `11` |
| `{d}` | اليوم برقمين | `25` |

```yaml
volumes:
    user_space:
        label: 'مساحتي الشخصية'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **عزل متعدد المستأجرين خالٍ من التعارض عبر `{user_id}`:**  
> يولّد `DynamicPathResolver` مفاتيح مجلدات **منفصلة تماماً لاحقتها SHA-256 بالصيغة `<slug>--h-<16-hex>`** لعناوين البريد الإلكتروني (`alice.a@example.com` مقابل `alice_a@example.com`)، وفروق حالة الأحرف (`Alice` مقابل `alice`)، وأسماء المستخدمين بترميز Unicode (العربية والصينية والتركية)، والكلمات المحجوزة (`guest` و`user` والمعرّفات المنتهية بـ `--h-`)، وأسماء أجهزة Windows المحجوزة (`con` و`nul` و`com1`..`9` و`lpt1`..`9`). يستحيل أن يتصادم مستخدمان مختلفان على المجلد نفسه.
>
> **سلوك أوامر CLI:** تتخطى الأوامر الطرفية (`doctor --check-remote` و`stats` و`trash:purge` و`thumbs:warmup` وغيرها) تلقائياً وحدات التخزين التي تحتوي `{user_id}` حتى لا تنشئ مجلد `guest/` عشوائياً.

---

## 6. المرجع الكامل لمخطط YAML (كل خيار نشط)

يوثّق مقطع YAML التالي **كل مفتاح تهيئة نشط** معرّف في `Configuration.php` مع قيمته الافتراضية وشرحه. في مشروعك الفعلي، اكتفِ بكتابة المفاتيح التي تريد تخصيصها فقط — وسيرث كل ما سواها قيمته الافتراضية تلقائياً.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) الإعدادات الافتراضية العامة — ترثها جميع ملفات التعريف ما لم تُتجاوز
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # المجلد الجذري الافتراضي لملفات التعريف المختصرة أحادية القرص
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = لغة طلب/مترجم Symfony (12 لغة مدمجة، الرجوع 'en') | 'ar' | 'en' | 'tr' | ...
        z_index: 1050                                      # قيمة z-index الأساسية للطبقات المنبثقة والنوافذ (>= 1)
        auto_inject_dialog_host: true                      # حقن تلقائي لحاوية Modal/OffCanvas DialogHost في استجابات HTML الكاملة
        dialog_host_profile: 'default'                     # ملف التعريف الافتراضي لحاوية DialogHost المحقونة تلقائياً

        audit_log:
            enabled: false                                 # تسجيل جميع عمليات التعديل التسع في قناة PSR-3
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # نموذج الذكاء الاصطناعي الافتراضي عند عدم تجاوزه في ملف التعريف
            platform_service: null                         # معرّف خدمة Symfony AI PlatformInterface مخصّص (null = تلقائي)

        cache:
            enabled: true                                  # مفتاح التخزين المؤقت العام PSR-6 للمجلدات والشجرة
            pool: 'cache.app'                              # معرّف خدمة تجمّع التخزين المؤقت PSR-6 في Symfony
            ttl: 3600                                      # عمر الذاكرة المؤقتة العام بالثواني (0 = معطّل)

        metadata:
            enabled: true                                  # ألوان/أيقونات/تثبيت المجلدات وalt/title/caption/tags/focalPoint للملفات
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # ملف التخزين لمحرّك json_file
            connection: null                               # اسم اتصال DBAL اختياري لمحرّك doctrine (null = اتصال entity_manager)
            entity_manager: 'default'                      # اسم EntityManager / الاتصال لمحرّك doctrine
            table: 'liveux_fm_metadata'                    # اسم جدول DBAL لمحرّك doctrine (راجع الفصل 13)
            auto_create_table: true                        # إنشاء جدول البيانات الوصفية تلقائياً عند غيابه (اضبطه false عند استخدام migrations)

    # =========================================================================
    # 2) وحدات التخزين — تعريفات أنظمة الملفات المحلية والسحابية
    # =========================================================================
    volumes:
        local_uploads:
            label: 'التخزين المحلي'                        # اسم العرض في الشريط الجانبي ومسار التنقل
            icon: null                                     # null = تلقائي | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = تلقائي (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # المسار الفيزيائي (يدعم {user_id} و{Y} و{m} و{d})
            url_prefix: '/uploads'                         # بادئة الرابط العام للمتصفح (اتركه null عند private: true)
            private: false                                 # true = بث جميع الملفات عبر DownloadController المحمي بـ RBAC
            cache_ttl: null                                # عمر التخزين المؤقت للوحدة بالثواني (null = وراثة، 0 = معطّل)
            driver_options: []                             # فارغ لـ LocalFileSystem

        cloud_s3_example:
            label: 'تخزين سحابي S3 / R2 / Supabase'
            icon: 'supabase'                               # يطابق resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # رابط الحاوية/CDN العام (null عند private: true)
            private: false
            cache_ttl: 60                                  # تحديث التغييرات السحابية الخارجية كل 60 ثانية
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # اسم الحاوية (Bucket)
                region: '%env(AWS_S3_REGION)%'             # المنطقة ('auto' لـ Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # رابط نقطة نهاية S3 مخصّص للمزوّدين غير AWS
                prefix: 'media/{Y}'                        # بادئة جذرية اختيارية داخل الحاوية (تدعم العناصر النائبة الديناميكية)
                use_path_style_endpoint: true              # true لـ Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # مفتاح الوصول S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # المفتاح السري S3 Secret Access Key
                token: null                                # رمز جلسة AWS STS مؤقت اختياري
                version: 'latest'                          # إصدار واجهة AWS S3 API (الافتراضي: 'latest')
                ca_bundle: null                            # مسار ملف شهادات CA بصيغة .pem اختياري (يحل خطأ cURL 60 على Windows)
                disable_acl: true                          # تلقائياً true لنقاط النهاية المخصّصة؛ اضبطه true لوضع Bucket owner enforced في AWS S3
                retain_visibility: false                   # تلقائياً false لنقاط النهاية المخصّصة (لتجنّب إرسال ترويسة x-amz-acl)
                request_checksum_calculation: 'when_required'  # توافق AWS SDK >= 3.337 (تلقائي لنقاط النهاية المخصّصة)
                response_checksum_validation: 'when_required'  # توافق AWS SDK >= 3.337 (تلقائي لنقاط النهاية المخصّصة)

        custom_flysystem_example:
            label: 'أرشيف SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # معرّف خدمة League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) ملفات التعريف — مجموعات قواعد مستقلة لكل حالة استخدام
    # =========================================================================
    profiles:
        full_reference:
            # --- وحدات التخزين المرتبطة واللغة وخيارات القرص الواحد المختصرة ---
            locale: null                                   # null = وراثة defaults.locale | 'ar' | 'en' | 'tr' | 'fr' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # عند تركها فارغة تُنشأ وحدة ضمنية من خيارات driver/path أدناه
            driver: 'LocalFileSystem'                      # محرّك مختصر يُستخدم فقط عندما تكون volumes: []
            path: '%kernel.project_dir%/public/uploads'    # مسار مختصر يُستخدم فقط عندما تكون volumes: []
            url_prefix: '/uploads'                         # بادئة رابط مختصرة تُستخدم فقط عندما تكون volumes: []
            private: false                                 # خيار خصوصية مختصر يُستخدم فقط عندما تكون volumes: []
            driver_options: []                             # خيارات محرّك مختصرة تُستخدم فقط عندما تكون volumes: []

            # --- الاستعراض والإخفاء والترتيب الافتراضي ---
            ignore_dot_files: true                         # إخفاء وحظر الملفات البادئة بنقطة (.env و.htaccess و.git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # أنماط regex/wildcard تُخفى من القوائم والوصول المباشر
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # عرض المجلدات دائماً قبل الملفات

            # --- الرفع والحصة ومعالجة التصادم ---
            upload_subfolder: null                         # مثل '{Y}/{m}' — وضع المرفوعات في مجلدات فرعية ديناميكية
            upload_allow: []                               # فارغ = جميع الأنواع الآمنة؛ غير فارغ = قائمة سماح MIME صارمة
            upload_deny:                                   # أنواع MIME خطيرة محظورة دائماً (47 نوعاً افتراضياً عند الحذف)
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
            upload_max_size: '10M'                         # الحد الأقصى للحجم المجمّع لملف واحد (مثل '50M' أو '2G')
            storage_quota: null                            # إجمالي حصة القرص لملف التعريف (مثل '500M' أو '5G'؛ null = غير محدود)
            overwrite_policy: 'rename'                     # rename (إضافة -1) | overwrite | reject

            # --- الرفع المجزّأ (لتجاوز حد PHP upload_max_filesize) ---
            chunk_upload:
                enabled: true                              # تقسيم الملفات الكبيرة إلى أجزاء قابلة للاستئناف
                chunk_size: '2M'                           # حجم الجزء المستهدف (يُقيّد تلقائياً بـ 80% من حدود PHP)
                parallel_uploads: 3                        # الحد الأقصى للرفع المتزامن (1..10)
                max_retries: 3                             # إعادة المحاولة التلقائية لكل جزء عند أخطاء الشبكة (0..10)

            # --- اكتشاف التكرار (SHA-256) ---
            duplicate_detection:
                enabled: false                             # اكتشاف المحتوى المكرّر عند الرفع
                policy: 'warn'                             # warn | reject | reuse (إرجاع الملف الموجود)
                max_scan_files: 5000                       # الحد الأقصى للملفات المفهرسة لاكتشاف التكرار (100..50000)

            # --- التطبيع التلقائي لأسماء الملفات ---
            naming:
                slugger: true                              # تحويل أسماء ملفات Unicode إلى سلاسل ASCII نظيفة
                slugger_locale: null                       # null = وراثة لغة ملف التعريف (مثل 'de' أو 'tr' أو 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # بادئة ثابتة/ديناميكية (مثل 'product-')
                append: ''                                 # لاحقة ثابتة/ديناميكية قبل الامتداد (مثل '-{Y}{m}{d}')
                apply_on_rename: true                      # تطبيق قواعد slugger/transformer عند إعادة التسمية
                apply_affixes_on_rename: false             # إعادة تطبيق prepend/append عند إعادة التسمية
                allow_extension_change: false              # السماح بتغيير امتداد الملف (.jpg -> .php) عند إعادة التسمية

            # --- تحسين الصور وقت الرفع ---
            image_optimization:
                enabled: false                             # تصحيح الاتجاه وإزالة EXIF وتغيير الحجم والضغط أثناء الرفع
                auto_orient: true                          # إصلاح اتجاه EXIF لصور الهواتف
                strip_exif: true                           # إزالة GPS وبيانات EXIF للكاميرا لحماية الخصوصية وتقليل الحجم
                max_width: 2400                            # أقصى عرض نسبي بالبكسل (>= 100)
                max_height: 2400                           # أقصى ارتفاع نسبي بالبكسل (>= 100)
                convert_to_webp: false                     # تحويل صور JPEG/PNG المرفوعة تلقائياً إلى WebP
                quality: 85                                # جودة الإخراج (10..100)

            # --- شروط دقة الصور ونسبة العرض إلى الارتفاع ---
            image_constraints:
                min_width: null                            # أدنى عرض بالبكسل (مثل 800)
                min_height: null                           # أدنى ارتفاع بالبكسل (مثل 600)
                max_width: null                            # أقصى عرض بالبكسل (يُفحص بعد image_optimization)
                max_height: null                           # أقصى ارتفاع بالبكسل
                aspect_ratio: null                         # النسبة المطلوبة (مثل '1:1' أو '16:9' أو '4:3' أو '3:4')
                aspect_ratio_tolerance: 0.05               # الانحراف المسموح عن النسبة (0.0..0.5؛ القيمة 0.05 = 5%)

            # --- محرّك الصور المصغرة ---
            thumbnail:
                enabled: true                              # توليد معاينات مصغرة مخزّنة مؤقتاً لبطاقات الصور
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # عرض الصورة المصغرة بالبكسل (>= 32)
                height: 240                                # ارتفاع الصورة المصغرة بالبكسل (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # جودة الضغط (10..100)
                fit: 'contain'                             # contain (احتواء كامل) | cover (قص لملء الإطار)
                lazy_load: true                            # إضافة loading="lazy" للصور المصغرة في البطاقات
                cache_dir: '%kernel.project_dir%/public/media-cache' # المجلد المحلي لتخزين الصور المصغرة المولّدة

            # --- سلة المهملات (الحذف المبدئي) ---
            trash:
                enabled: true                              # نقل العناصر المحذوفة إلى سلة المهملات بدلاً من الحذف النهائي
                directory: '.trash'                        # اسم مجلد سلة المهملات المخفي
                retention_days: 30                         # مدة الاحتفاظ بالأيام لأمر file-manager:trash:purge
                max_size: '500M'                           # الحد الأقصى لإجمالي حجم سلة المهملات

            # --- تنزيل ZIP والاستخراج الآمن للأرشيفات ---
            zip:
                enabled: true                              # تفعيل تنزيل عدة ملفات/مجلدات بصيغة ZIP
                max_files: 100                             # أقصى عدد ملفات في تنزيل ZIP واحد
                max_total_size: '250M'                     # أقصى حجم إجمالي لتنزيل ZIP واحد
                allow_extract: false                       # تفعيل فك الضغط (معطّل افتراضياً؛ محمي بـ 7 طبقات)
                max_extract_files: 200                     # أقصى عدد ملفات تُستخرج من أرشيف واحد (1..2000)
                max_extract_size: '100M'                   # أقصى حجم إجمالي للملفات المستخرجة (حماية ضد قنابل ZIP)
                max_compression_ratio: 100                 # أقصى نسبة ضغط مسموحة (1..1000 — حماية ضد قنابل ZIP)

            # --- روابط المشاركة الموقّعة محدّدة المدة (HMAC) ---
            share:
                enabled: true                              # السماح بإنشاء روابط مشاركة موقّعة
                default_ttl: 86400                         # مدة الصلاحية الافتراضية بالثواني (86400 = 24 ساعة)
                max_ttl: 2592000                           # أقصى مدة صلاحية قابلة للاختيار بالثواني (2592000 = 30 يوماً)
                allow_inline_preview: true                 # السماح بالمعاينة المباشرة في المتصفح لأنواع MIME الآمنة

            # --- الإثراء الاختياري بـ Symfony AI ---
            ai:
                enabled: false                             # إظهار "✨ اقتراح بالذكاء الاصطناعي" في نافذة البيانات الوصفية
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = لغة ملف التعريف/الطلب (مثلاً 'ar' => نص بديل ووسوم بالعربية)
                max_tags: 6                                # أقصى عدد وسوم مقترحة (1..20)
                max_image_dimension: 1024                  # حد تصغير الصورة قبل إرسالها للذكاء الاصطناعي (256..2048 بكسل)
                block_private_volumes: true                # true = منع استدعاءات الذكاء الاصطناعي لوحدات private: true (الافتراضي: true)
                auto_enrich_on_upload: false               # تشغيل الإثراء بالذكاء الاصطناعي تلقائياً بعد الرفع

            # --- حارس استخدام الأصول (حذف / إعادة تسمية / نقل / استبدال) ---
            usage_guard:
                enabled: false                             # فحص مراجع قاعدة البيانات قبل تعديل الأصول
                policy: 'warn'                             # warn (عرض المراجع + السماح بالتجاوز) | block (منع العملية)
                enforce_server_side: true                  # فرض الحماية أيضاً على طلبات API المباشرة
                max_references: 20                         # أقصى عدد مراجع تُعرض في النافذة (1..200)

            # --- التخزين المؤقت الخاص بملف التعريف ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | بالثواني (0 = معطّل)

            # --- خيارات الأمان ---
            security:
                voter_enabled: true                        # تفعيل فحوصات صلاحيات FileManagerVoter RBAC
                csrf_protection: true                      # اشتراط ترويسة X-LiveUX-FM-CSRF في طلبات POST
                sanitize_svg: true                         # تعقيم ملفات SVG المرفوعة والمعدّلة
                allow_remote_url_import: false             # تفعيل تبويب "من رابط URL" (محمي ضد SSRF)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- الصلاحيات الـ 17 التفصيلية (true = مستخدم مصادق عليه | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # الصلاحية الرئيسية المطلوبة للاستعراض وجميع الإجراءات الأخرى
                upload: true                               # رفع الملفات وحفظ محرر الصور والاستيراد من رابط
                create_folder: true                        # إنشاء مجلدات جديدة
                rename: true                               # إعادة تسمية الملفات والمجلدات
                clone: true                                # تكرار الملفات/المجلدات
                move: true                                 # نقل العناصر (بما فيها السحب والإفلات والقص/اللصق)
                copy: true                                 # نسخ العناصر (بما فيها النسخ/اللصق)
                delete: true                               # حذف العناصر (سلة المهملات أو الحذف النهائي)
                trash_restore: true                        # استعادة العناصر من سلة المهملات
                trash_purge: true                          # الحذف النهائي للعناصر / إفراغ سلة المهملات
                download: true                             # تنزيل ملف مفرد ونسخ الرابط
                download_zip: true                         # تنزيل ملفات متعددة بصيغة ZIP
                extract_zip: false                         # استخراج أرشيفات ZIP (يتطلب أيضاً zip.allow_extract: true)
                share: true                                # إنشاء روابط مشاركة موقّعة
                clear_cache: true                          # مسح الذاكرة المؤقتة من الإعدادات وفرض ?refresh=1
                edit_metadata: true                        # تعديل alt/وسوم/نقطة التركيز للملفات وألوان/أيقونات/تثبيت المجلدات
                ai_enrich: true                            # تشغيل الإثراء بالذكاء الاصطناعي للبيانات الوصفية

            # --- خيارات واجهة المستخدم والمقاسات ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <مجلد مخصّص>
                z_index: null                              # null = defaults.z_index | عدد صحيح (>= 1)
                mobile_dialog_mode: 'auto'                 # سلوك النوافذ على الجوال (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px مضغوط)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (صف مضغوط) | card (بطاقة مجلد كاملة الحجم)
                show_size_slider: true                     # إظهار منزلق الحجم الحي ومبدّل نمط المجلد في الإعدادات
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # إظهار الشريط الجانبي لشجرة المجلدات
                tree_lines: true                           # إظهار خطوط الربط الهرمية في شجرة المجلدات
                show_folder_item_count: true               # إظهار شارات عدد العناصر على المجلدات
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # إظهار عدّاد الحصة في الشريط الجانبي وشريط الرأس
                show_quota_details: true                   # تفعيل نافذة تفاصيل استهلاك التخزين القابلة للنقر
                per_page: 24                               # العدد الافتراضي للعناصر في الصفحة (6..200)
                per_page_options: [24, 48, 96]             # خيارات قائمة عدد العناصر في الصفحة
                show_perf_badge: false                     # إظهار شارة توقيت CACHE HIT / DISK في شريط الأدوات
                show_nav_buttons: true                     # المفتاح الرئيسي لمجموعة أزرار رجوع / تقدّم / المجلد الأعلى / تحديث
                show_history_buttons: true                 # إظهار زرّي الرجوع والتقدّم في السجل
                show_up_button: true                       # إظهار زر الصعود للمجلد الأعلى
                show_refresh_button: true                  # إظهار زر التحديث (?refresh=1)
                show_breadcrumbs: true                     # إظهار مسار التنقل القابل للنقر والإفلات
                show_search: true                          # إظهار مربع البحث وزر البحث الشامل
                show_type_filter: true                     # إظهار فلتر النوع (الصور / الفيديوهات / الملفات / المجلدات)
                show_view_toggle: true                     # إظهار مبدّل عرض الشبكة / القائمة
                show_pagination: true                      # إظهار شريط ترقيم الصفحات السفلي
                show_per_page_selector: true               # إظهار قائمة العناصر في الصفحة
                show_sort: true                            # إظهار قائمة الترتيب ومبدّل الاتجاه
                show_fullscreen_button: true               # إظهار زر التبديل لوضع ملء الشاشة
                show_settings_button: true                 # إظهار قائمة الإعدادات (⚙)
                show_footer: true                          # إظهار شريط الحالة السفلي
                show_context_nav: true                     # إظهار شريط التنقل السريع في قائمة السياق للمساحة الفارغة
                allow_dropzone_upload: true                # السماح بسحب الملفات/المجلدات من نظام التشغيل إلى قائمة الملفات
                remember_last_dir: true                    # تذكّر آخر وحدة تخزين ومجلد (يتعافى تلقائياً عند حذفه)
                confirm_delete: true                       # إظهار نافذة تأكيد قبل الحذف
                confirm_move: true                         # إظهار نافذة تأكيد قبل النقل/النسخ
                double_click_to_select: true               # النقر المزدوج يختار الملف ويغلق نافذة المنتقي Modal/OffCanvas
                allow_context_menu: true                   # تفعيل قائمة السياق بزر الفأرة الأيمن
                allow_pdf_preview: true                    # معاينة PDF داخل العارض المنبثق (false يفرض التنزيل)
                allow_video_preview: true                  # مشغّل الفيديو المدمج (false يفرض التنزيل)
                keyboard_shortcuts: true                   # تفعيل اختصارات لوحة المفاتيح المعزولة النطاق
                allow_drag_move: true                      # تفعيل سحب العناصر فوق المجلدات لنقلها
```

---

[← السابق: 01 — المتطلبات والتثبيت](01-requirements-and-installation.md) | [فهرس التوثيق](README.md) | [التالي: 03 — أوضاع الاستخدام وواجهة JS API →](03-usage-modes-and-js-api.md)
