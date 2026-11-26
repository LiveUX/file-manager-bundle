# 11 — دليل المساهمة وطلبات الدمج (PR) (خطوة بخطوة للمبتدئين)

[← السابق: 10 — حارس استخدام الأصول](10-asset-usage-guard.md) | [فهرس التوثيق](README.md) | [التالي: 12 — مهارات وكلاء الذكاء الاصطناعي →](12-ai-agent-skills-guide.md)

كُتب هذا الدليل من الصفر للمطورين الذين **لم يسبق لهم فتح طلب دمج (Pull Request - PR) في مشروع مفتوح المصدر من قبل**، وكذلك لمشرفي المشاريع الراغبين في إعداد **بوابة جودة آلية لطلبات الدمج على غرار معايير Symfony** في مستودعاتهم الخاصة. كل مفهوم وأمر مشروح هنا خطوة بخطوة.

---

## 1. الصورة الكاملة: كيف تعمل طلبات الدمج في المشاريع مفتوحة المصدر؟

في المشاريع مفتوحة المصدر الكبرى مثل Symfony وLaravel وReact، لا يمكن للمساهمين الخارجيين الدفع (Push) مباشرة إلى الفرع الرئيسي (`main`). بدلاً من ذلك، يمر كل تعديل عبر **بوابة أمان من 4 مراحل**:

1. **الاستنساخ والتفريع (Fork & Branch — الحصول على نسختك الخاصة):** انسخ المستودع إلى حسابك على GitHub (*Fork*) وأنشئ فرع عمل مخصصاً (*Branch*).
2. **كتابة الشيفرة والفحوصات المحلية (`composer qa`):** نفّذ تعديلك، اكتب الاختبارات، وشغّل حزمة فحوصات الجودة الآلية الكاملة على جهازك.
3. **فتح طلب دمج (Pull Request - PR):** قدّم طلباً لمشرفي المشروع لمراجعة إصلاحك أو ميزتك الجديدة ودمجها في المستودع الرئيسي. يعرض GitHub تلقائياً **جدول ملخص وقائمة تحقق على طراز Symfony** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **فحوصات التكامل المستمر الآلية (GitHub Actions) + مراجعة الشيفرة (Code Review):**
   - فور فتح طلب الدمج أو تحديثه، يُشغّل GitHub Actions (`.github/workflows/ci.yml`) خوادم اختبار سحابية تلقائياً.
   - تختبر هذه البيئة الشيفرة عبر **PHP 8.3 و8.4**، و**Symfony 7.2 و7.4 و8.0**، و**Node 20 و22**، كما تفرض معايير تنسيق الشيفرة (**PHP-CS-Fixer**)، وسلامة الأنواع الساكنة (**PHPStan Level 8**)، واكتمال الترجمات (**TranslationParityTest**)، ودقة مخطط YAML في التوثيق (**FullConfigReferenceDocTest**)، وسلامة حزمة مهارات الوكلاء (**SkillsPackageIntegrityTest**)، وأمن الواجهة الأمامية وXSS/CSP (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **إذا فشل فحص واحد فقط، يعطّل GitHub زر الدمج (Merge) تلقائياً.**

---

## 2. هل البنية التحتية للجودة مُعدة وجاهزة بالفعل؟

**نعم — مُعدة بنسبة 100% وجاهزة للاستخدام الفوري.** تأتي الحزمة مزودة بسلسلة أدوات جودة متكاملة مصممة مباشرة وفق معايير Symfony UX الرسمية:

| الملف | الغرض (ببساطة) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | يعرّف حزمة سكربتات `composer qa` بأمر واحد واعتماديات التطوير المستقلة (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | يشغّل جميع اختبارات الوحدات والأمان بلغة PHP (**57 ملف اختبار، 360 اختباراً، وأكثر من 3,500 تحقق**) بشكل مستقل دون الحاجة إلى تطبيق مضيف. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | يضبط التحليل الساكن **PHPStan Level 8** لاكتشاف تعارضات الأنواع المخفية، والمعاملات غير الصالحة، واستدعاءات الدوال غير المعرّفة قبل وقت التشغيل. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | يفرض أسلوب كتابة PSR-12 + Symfony عبر جميع ملفات PHP (`declare(strict_types=1)`، والمقارنات الصارمة `===`، وترتيب جُمل `use` أبجدياً). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | يشغّل **6 مهام CI متوازية** تلقائياً عند كل طلب دمج جديد أو محدّث. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | يملأ كل طلب دمج جديد مسبقاً بجدول ملخص على نمط Symfony (`Bug fix?`، `BC breaks?`، `Tests pass?`) وقائمة تحقق. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | يوفر نماذج منظّمة للإبلاغ عن الأخطاء (`bug_report.yml`) واقتراح الميزات (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | إرشادات المساهمة الدولية التي يربطها GitHub تلقائياً في شاشة كل طلب دمج أو بلاغ مشكلة جديد. |
| [`SECURITY.md`](../../SECURITY.md) | سياسة الإفصاح الأمني التي توضح كيفية الإبلاغ عن الثغرات بشكل خاص بدلاً من فتح بلاغ عام. |

### 7 قواعد حاسمة تُفرض تلقائياً

حتى لو غفل المراجع البشري عن إحدى المشكلات التالية، فإن **حزمة الاختبارات الآلية تلتقطها وتُفشل طلب الدمج فوراً**:

1. **الترجمات المفقودة (`TranslationParityTest`):** إذا أُضيف نص واجهة جديد إلى الإنجليزية ونقص من أيٍ من **لغات الواجهة الـ 12 المدمجة** (`en`، `tr`، `fr`، `de`، `es`، `ar`، `it`، `pt`، `nl`، `pl`، `ja`، `zh_CN`)، فإن الاختبار **يفشل**.
2. **غياب `strict_types` (`TranslationParityTest`):** إذا خلا ملف PHP واحد فقط تحت `src/` من تصريح `declare(strict_types=1);`، فإن الاختبار **يفشل**.
3. **مخالفات أمان XSS / CSP / الواجهة الأمامية (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** إضافة معالجات مضمّنة مثل `onclick="..."` أو فلتر `|raw` في قوالب Twig، أو كسر تعقيم روابط `javascript:` / عناصر DOM في JavaScript يؤدي إلى **فشل** الاختبار.
4. **انحراف مخطط YAML في التوثيق (`FullConfigReferenceDocTest`):** يتم التحقق من جميع كتل YAML الخاصة بـ `liveux_file_manager:` في مرجع [الإعدادات والملفات التعريفية](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) مقابل مخطط `Configuration.php` الفعلي.
5. **سلامة مهارات الوكلاء (`SkillsPackageIntegrityTest`):** تتحقق من الترويسة الأمامية (frontmatter)، وميزانيات الأسطر والحجم، والروابط المرجعية في `AGENTS.md` وجميع ملفات `skills/*/SKILL.md` الستة.
6. **أسلوب الشيفرة والأنواع الساكنة (`PHP-CS-Fixer` + `PHPStan Level 8`):** أي مخالفة لمعيار PSR-12/Symfony أو خطأ نوع في المستوى 8 يوقف خط أنابيب CI.
7. **مزامنة الملفات المضغوطة في `assets/dist/` (`npm --prefix assets run build:check`):** إذا عدّلت `assets/controllers/*.js` أو `assets/styles/core.css` ونسيت إعادة بناء `assets/dist/`، فإن CI **يفشل**.

---

## 2.5. بنية ضغط ملفات JS و CSS (`assets/dist/`) باستخدام `esbuild`

> [!NOTE]
> **لماذا توجد هذه البنية؟**
> - يحصل مستخدمو **Webpack Encore** على ضغط تلقائي لملفات JS/CSS عند تشغيل `npm run build`.
> - أما **Symfony AssetMapper** (`asset-map:compile`)، فيقدم الملفات كما هي دون ضغط. وفي صورتها المصدرية الخام، يبلغ حجم `browser_controller.js` نحو `518 KB` و`core.css` نحو `132 KB`.
> - ولضمان سرعة تحميل الصفحات لمستخدمي AssetMapper دون إلزام المشاريع المضيفة بتثبيت Node.js، نتبع تماماً نمط **المصدر ← التوزيع (`assets/dist/`)** المعتمد في حزم Symfony UX الرسمية (`ux-live-component`، `ux-chartjs`، `ux-autocomplete`).

### آلية العمل

1. **ملفات مصدرية مقروءة:** تُجرى جميع عمليات التطوير والاختبارات الأمنية على ملفات مصدرية منسّقة ومشروحة بوضوح:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` و`assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` و`assets/images/liveux-logo.webp`
2. **مخرجات إنتاج مضغوطة (`assets/dist/`):** يستخدم السكربت [`assets/build.mjs`](../../assets/build.mjs) أداة **`esbuild`** لضغط كل ملف JS كوحدة ESM مستقلة (مع إبقاء `@hotwired/stimulus` خارجياً والحفاظ على الاستيرادات النسبية) وتجميع `core.css` داخل `assets/dist/`.
3. **حل تلقائي (دون أي تغييرات كاسرة للتوافق):**
   - تربط الدالة `prependAssetMapper()` في [`FileManagerBundle.php`](../../src/FileManagerBundle.php) نطاق `@liveux/file-manager-bundle` تلقائياً بالمجلد `assets/dist/` متى كان `assets/dist` موجوداً.
   - تضمن مدخلات `"exports"` و`"symfony.controllers.*.main"` في [`assets/package.json`](../../assets/package.json) استهلاك Webpack Encore لملفات `dist/` مباشرة أيضاً.
   - نظراً لأن `assets/dist/` مُضمن في مستودع Git، **لا يحتاج المستخدم النهائي أبداً إلى Node.js أو npm**. ويستبعد ملف `.gitattributes` أدوات البناء فقط (`assets/node_modules`، `assets/build.mjs`، `assets/package-lock.json`) من أرشيفات Packagist مع إبقاء `assets/package.json` و`assets/dist/` مضمّنين.

### توفير الحجم المُقاس فعلياً

| الملف | المصدر الخام | المضغوط (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 محولات محررات | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **الإجمالي (`-45.1%` بعد الضغط)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### أوامر المطور (في كل مرة تعدّل فيها JS أو CSS)

```bash
# 1. تثبيت esbuild مرة واحدة فقط (في أول مرة):
npm --prefix assets install

# 2. إعادة بناء assets/dist/ بعد تعديل أي ملف JS أو CSS (~50 مللي ثانية):
npm --prefix assets run build
# أو عبر اختصار Composer:
composer assets:build

# 3. التحقق من أن assets/dist/ متطابق 100% مع الملفات المصدرية (نفس الفحص الذي يشغّله CI):
composer assets:check
```

---

## 3. دليل خطوة بخطوة لتقديم طلب دمج (Pull Request)

> [!TIP]
> **حتى لو لم يسبق لك تقديم طلب دمج من قبل**، اتّبع هذه الخطوات الست بالترتيب لفتح طلب دمج نظيف يجتاز جميع فحوصات CI.

### الخطوة 1: الاستنساخ (Fork) والجلب (Clone) وتثبيت الاعتماديات

1. افتح [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) على GitHub وانقر على **Fork** في الزاوية العلوية لإنشاء نسخة تحت حسابك على GitHub.
2. اجلب نسختك محلياً، وأضف المستودع الرئيسي كـ `upstream`، ثم ثبّت أدوات تطوير PHP وNode:

```bash
git clone https://github.com/<YOUR-USERNAME>/file-manager-bundle.git
cd file-manager-bundle

# ربط المستودع الرئيسي باسم upstream (عادة ممتازة للبقاء محدّثاً دائماً)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# تثبيت اعتماديات اختبارات PHP والتحليل الساكن
composer install

# تثبيت أداة ضغط JS/CSS (esbuild) إذا كنت تخطط لتعديل ملفات JS/CSS
npm --prefix assets install
```

### الخطوة 2: إنشاء فرع جديد من نسخة محدّثة من `main`

لا تقم أبداً بعمل commit مباشرة على `main`. زامن `main` أولاً، ثم أنشئ فرعاً باسم وصفي واضح لمهمتك:
- إصلاح خطأ: `fix/folder-counter-cache`
- ميزة جديدة: `feat/new-icon-set`
- توثيق: `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### الخطوة 3: كتابة الشيفرة وإضافة الاختبارات وإعادة بناء `dist/` عند الحاجة

- **عند إصلاح خطأ:** ابدأ بكتابة اختبار تراجعي (regression test) مركّز يعيد إنتاج الخطأ (يفشل قبل الإصلاح، وينجح بعده).
- **عند إضافة ميزة:**
  - أضف اختبارات وحدة PHP تحت `tests/Unit/...`.
  - لتعديلات الواجهة أو JS، أضف تغطية اختبارية في `tests/Js/dom_security_test.mjs` أو `tests/Js/frontend_audit_test.mjs`.
  - **كلما عدّلت أي ملف JS أو CSS، شغّل `composer assets:build`** (وإلا سيفشل CI عند `build:check`).
  - كلما أضفت مفتاح ترجمة جديداً للواجهة، أضفه إلى **جميع كتالوجات اللغات الـ 12 المدمجة**: `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### الخطوة 4: تشغيل جميع بوابات الجودة محلياً دفعة واحدة

شغّل نفس الفحوصات التي ينفذها CI تماماً قبل فتح طلب الدمج:

```bash
composer qa
composer assets:check
```

تتحقق هذه الأوامر بالترتيب من:
1. `composer validate --strict` — التحقق من صحة `composer.json`.
2. `composer cs:check` — التحقق من معايير تنسيق PSR-12/Symfony *(إذا ظهرت ملاحظات، شغّل `composer cs:fix` للتنسيق التلقائي)*.
3. `composer stan` — تشغيل التحليل الساكن **PHPStan Level 8**.
4. `composer test` — تشغيل حزمة اختبارات PHPUnit الكاملة للوحدات والأمان وتكافؤ الترجمات والمخططات.
5. `composer test:js:syntax` — تشغيل التحقق النحوي `node --check` عبر جميع ملفات JS المصدرية وملفات `dist/`.
6. `composer test:js` — تشغيل حزم Node.js لأمان DOM وتدقيق الواجهة الأمامية (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — التأكد من تطابق `assets/dist/` بايتاً ببايت مع الملفات المصدرية الحالية.

> [!TIP]
> **كل الفحوصات خضراء؟** أنت الآن جاهز لعمل commit وفتح طلب الدمج.

### الخطوة 5: إنشاء Commit نظيف والدفع إلى نسختك (Fork)

```bash
git status            # التحقق من تعديل الملفات المقصودة فقط
git diff --stat       # مراجعة ملخص سريع لتغييراتك
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(استخدم بادئات Conventional Commits الواضحة: `feat:`، `fix:`، `docs:`، `test:`، `refactor:`، `perf:`.)*

### الخطوة 6: فتح طلب الدمج على GitHub ومراقبة تحوّل CI إلى الأخضر

1. زُر نسختك على GitHub (`https://github.com/<YOUR-USERNAME>/file-manager-bundle`) وانقر على شريط **"Compare & pull request"**.
2. تأكد من المستودعين المستهدفين: **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<YOUR-USERNAME>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. املأ جدول **قالب طلب الدمج** المُضاف تلقائياً (`Bug fix? yes`، `BC breaks? no`، `Tests pass? yes`) وحدد عناصر قائمة التحقق (`[x]`). وإذا كان تعديلك يؤثر في الواجهة، أرفق لقطة شاشة أو ملف GIF.
4. انقر على **"Create pull request"**.
5. انتظر حتى تعرض جميع فحوصات GitHub Actions (`PHP 8.3/8.4`، `Node 20/22`، `PHPStan & CS-Fixer`) أسفل صفحة طلب الدمج **علامة صح خضراء (✓)**.
6. إذا طلب أحد المراجعين تعديلات، عدّل الملفات على نفس الفرع ونفّذ `git commit && git push` — سيحدّث GitHub طلب الدمج ويعيد تشغيل CI تلقائياً.

---

## 4. لمشرفي المشاريع: فرض حماية الفروع وقواعد Rulesets على GitHub

بمجرد وجود ملفات سير العمل (workflows) في المستودع، يعمل CI تلقائياً — ولكن لضمان **ألا يتمكن أي شخص (حتى المشرف عن طريق الخطأ) من النقر على Merge بينما الفحوصات حمراء**، اضبط **Ruleset** على GitHub خلال دقيقتين:

1. افتح مستودع الحزمة على GitHub وانتقل إلى **Settings**.
2. من الشريط الجانبي، اختر **Rules → Rulesets** (أو **Branches → Add branch protection rule** في العرض الكلاسيكي).
3. انقر على **New branch ruleset**:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. فعّل الحمايات الأربع التالية:
   - [x] **Restrict deletions** (يمنع حذف الفرع `main`)
   - [x] **Block force pushes** (يحمي سجل الـ commits)
   - [x] **Require a pull request before merging** (يمنع الدفع المباشر إلى `main`؛ جميع التغييرات تمر عبر PR)
     - *Required approvals:* `1` (موصى به للفِرق؛ يمكن للمشرف المنفرد تركه `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(الإعداد الأهم)*
     - [x] *Require branches to be up to date before merging*
     - بعد اكتمال أول تشغيل لـ CI، استخدم **Add checks** لإلزام جميع **المهام الست**:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. انقر على **Create** أسفل الصفحة.

---

## 5. قائمة التحقق لنشر الحزمة كمستودع مفتوح المصدر مستقل

لنشر الحزمة باسم `LiveUX/file-manager-bundle` على GitHub وPackagist:

1. أنشئ مستودعاً عاماً (**Public**) فارغاً جديداً باسم `file-manager-bundle` تحت منظمة `LiveUX` على GitHub.
2. ادفع محتويات `bundles/liveux-file-manager-bundle/` كأول commit (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. تحقق من تبويب **GitHub Actions** أن جميع مهام CI الست نجحت بعلامات صح خضراء.
4. فعّل إعدادات **Ruleset** الموضحة في **القسم 4** أعلاه.
5. أنشئ وسم الإصدار `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) ثم سجّل الرابط `https://github.com/LiveUX/file-manager-bundle` في [Packagist.org](https://packagist.org).
6. سيتمكن أي مطور بعد ذلك من تثبيت الحزمة بأمر واحد: `composer require liveux/file-manager-bundle`.

---

[← السابق: 10 — حارس استخدام الأصول](10-asset-usage-guard.md) | [فهرس التوثيق](README.md) | [التالي: 12 — مهارات وكلاء الذكاء الاصطناعي →](12-ai-agent-skills-guide.md)
