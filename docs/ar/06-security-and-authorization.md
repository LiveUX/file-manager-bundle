# 06 — الأمان والتفويض والصلاحيات (RBAC والدفاع المتعمّق)

[← السابق: 05 — حقل النموذج (`FormType`) وتكامل المحررات](05-formtype-and-editor-integrations.md) | [الفهرس](README.md) | [التالي: 07 — التخزين السحابي والإعداد الديناميكي →](07-cloud-storage-and-dynamic-config.md)

تُعد مديريات الملفات من أكثر الأسطح حساسيةً وعرضةً للهجمات في أي تطبيق ويب. لذلك بُنيت حزمة LiveUX FileManagerBundle وفق معمارية **الدفاع المتعمّق (Defense-in-Depth)** مع طبقات حماية أمنية متعددة مفعّلة افتراضيًا.

> **نظرة سريعة: كيف تُحكم أمان مدير الملفات في 3 خطوات؟**  
> 1. **حماية المسارات (`security.yaml`):** امنع الزوار غير المصادق عليهم من الوصول إلى نقاط نهاية مدير الملفات.  
> 2. **تعريف صلاحيات الأدوار (`permissions`):** حدّد صراحةً في كل ملف تعريف من يملك صلاحية العرض (`view`) والرفع (`upload`) والحذف (`delete`).  
> 3. **إضافة بادئة مسار `prefix` في التطبيقات متعددة جدران الحماية (Multi-Firewall):** إذا كان تطبيقك يعتمد على جدران حماية منفصلة للوحة الإدارة (`/admin`) والمستخدمين العاديين (`/`), فاربط مسارات الحزمة تحت `prefix: /admin` في `config/routes/liveux_file_manager.yaml` حتى تُحل جلسة المدير بشكل صحيح ولا يتمكن الأعضاء العاديون من الوصول إلى ملفات الإدارة.

---

## 1. حماية المسارات في `security.yaml` (الخطوة الأولى)

في بيئة الإنتاج، احمِ دائمًا جميع نقاط النهاية تحت `/_liveux-file-manager` خلف جدار الحماية الخاص بـ Symfony:

```yaml
# config/packages/security.yaml (تطبيقات بجدار حماية واحد)
security:
    access_control:
        # اتركه عامًا فقط إذا كنت تستخدم روابط المشاركة الموقّعة لأطراف خارجية:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**إذا كان لديك جدارا حماية منفصلان — مثل منطقة الإدارة (`/admin`) وأعضاء الموقع العاديين (`/`):**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. التفويض المبني على ملفات التعريف (`permissions` و`FileManagerVoter`)

يتيح لك كل ملف تعريف ضبط قواعد وصول مستقلة لـ **17 عملية مختلفة**.

### ماذا تعني قيم الصلاحيات؟
- **`true`:** **المستخدمون المصادق عليهم فقط (`$token->getUser() !== null`)!** *(لا يمنح الوصول للزوار المجهولين أبدًا.)*
- **`false`:** لا أحد (العملية معطّلة بالكامل).
- **`'ROLE_ADMIN'`:** المستخدمون الذين يحملون هذا الدور فقط.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** المستخدمون الذين يحملون أيًّا من الأدوار المذكورة.

### القواعد الذهبية الـ 4 للتفويض (تُطبَّق تلقائيًا)
1. **`permissions.view` هي البوابة الرئيسية:** إذا لم يمتلك المستخدم صلاحية `view`، تُرفض تلقائيًا عمليات `upload` و`delete` و`download` وجميع العمليات الأخرى.
2. **الرفض الآمن عند الفشل (`FileManagerAuthorizer` — Fail-Closed):** عند تفعيل `security.voter_enabled: true` دون تثبيت `AuthorizationCheckerInterface` (أي `symfony/security-bundle`)، **ترفض الحزمة الوصول بأمان (`AccessDeniedException`)** بدلًا من السماح بالوصول تلقائيًا.
3. **فحص سلسلة المجلدات الأب (`isPathAndAncestorsGranted`):** عندما يصل المستخدم إلى `confidential/2026/invoice.pdf`، لا تُقيَّم مُصوّتاتك المخصصة (Voters) على `confidential/2026/invoice.pdf` فحسب، بل أيضًا على المجلدين الأبوين **`confidential`** و**`confidential/2026`** — مما يمنع تجاوز قيود المجلدات الأب عن طريق تخمين الروابط.
4. **القفل المزدوج لفك ضغط ZIP (`zip.allow_extract`):** ما دام `zip.allow_extract: false`، يظل استخراج الأرشيف مرفوضًا على الخادم بغض النظر عن قيمة `permissions.extract_zip`.

### المرجع الكامل لصلاحيات `Voter` الـ 17

| مفتاح YAML | ثابت المُصوّت (`FileManagerPermissions::*`) | العمليات المحمية |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | سرد المجلدات، شجرة المجلدات، الصور المصغّرة، المعاينة المباشرة، نوافذ الحوار، و**شرط أساسي لجميع الصلاحيات الأخرى** |
| `upload` | `FILE_MANAGER_UPLOAD` | رفع الملفات، الرفع المجزّأ، استيراد الروابط البعيدة، وحفظ تعديلات محرر الصور |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | إنشاء مجلدات جديدة |
| `rename` | `FILE_MANAGER_RENAME` | إعادة تسمية الملفات والمجلدات |
| `clone` | `FILE_MANAGER_CLONE` | استنساخ/تكرار الملفات والمجلدات |
| `move` | `FILE_MANAGER_MOVE` | نقل العناصر (السحب والإفلات ونافذة النقل — يُفحص مسار المصدر ومسار الوجهة معًا) |
| `copy` | `FILE_MANAGER_COPY` | نسخ العناصر (يُفحص مسار المصدر ومسار الوجهة معًا) |
| `delete` | `FILE_MANAGER_DELETE` | حذف العناصر (سلة المحذوفات المؤقتة / الحذف النهائي) واستعلامات `checkUsage` |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | استعادة العناصر من سلة المحذوفات |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | الحذف النهائي للعناصر أو إفراغ سلة المحذوفات |
| `download` | `FILE_MANAGER_DOWNLOAD` | تنزيل ملف مفرد، بث ملفات PDF/الفيديو، ونسخ الروابط |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | تنزيل عدة عناصر كأرشيف ZIP (يُفحص كل مسار محدد على حدة) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | فك ضغط ZIP الآمن (يتطلب أيضًا `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | إنشاء روابط مشاركة مؤقتة وموقّعة بـ HMAC (يتطلب أيضًا `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | مسح ذاكرة التخزين المؤقت للمجلدات وملف التعريف والصور المصغّرة، بالإضافة إلى تجاوز الكاش عبر `?refresh=1` و`?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | تحديث ألوان المجلدات وشاراتها وتثبيتها، والنص البديل `alt` والعناوين والوسوم ونقاط التركيز للملفات |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | اقتراحات البيانات الوصفية بالذكاء الاصطناعي (تتطلب `view` + `edit_metadata` + `ai.enabled: true`) |

### مثال لإعداد ملف تعريف مؤسسي

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **واجهة مستخدم واعية بالصلاحيات:** تُخفى تلقائيًا أزرار الإجراءات التي لا يملك المستخدم صلاحيتها (مثل إخفاء زر الحذف عن `ROLE_EDITOR` عند ضبط `delete: 'ROLE_ADMIN'`)، **كما تُفرض صارمًا** برمز الاستجابة `HTTP 403 Forbidden` في حال استدعائها مباشرةً عبر واجهة API.

---

## 3. كتابة مُصوّت Symfony مخصص (`Voter`) لقواعد دقيقة على مستوى المجلد والمستخدم

عندما لا تكفي أدوار YAML وحدها — مثل *"منع المستخدمين من حذف المجلد `locked/` أو نقل ملفات إلى داخله"* — يمكنك كتابة مُصوّت Symfony مخصص.

> [!WARNING]
> **الخطأ الأكثر شيوعًا لدى المبتدئين: `affirmative` مقابل `unanimous`!**  
> الإستراتيجية الافتراضية في Symfony لـ `access_decision_manager.strategy` هي **`affirmative`** (يكفي مُصوّت واحد يعيد `true` لمنح الوصول). وهذا يعني أنه إذا أعاد `FileManagerVoter` المدمج القيمة `true` لمستخدم يملك `ROLE_ADMIN`، فسيتم **تجاهل تصويت الرفض `false`** الصادر عن مُصوّتك المخصص، وسيبقى المستخدم قادرًا على حذف الملف!  
>  
> لضمان تطبيق قواعد **الرفض** في مُصوّتك المخصص فعليًا، اضبط الإستراتيجية على **`unanimous`** في `config/packages/security.yaml` (يجب موافقة جميع المصوّتين المشاركين؛ وتصويت `false` واحد يمنع الإجراء):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(تكتشف الحزمة تلقائيًا المُصوّتات المخصصة في تطبيقك عبر `DetectExternalVotersPass` وتُجري تصفية Voter لكل عنصر في شجرة المجلدات وعدادات الملفات للحفاظ على الخصوصية والدقة معًا.)*

### خصائص `FileManagerSubject`

يكشف الكائن `$subject` المُمرَّر إلى الدالة `voteOnAttribute()` في مُصوّتك المخصص 4 خصائص للقراءة فقط (`readonly`):

| الخاصية | النوع | الوصف | مثال |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | إعدادات ملف التعريف النشط (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | المسار النسبي للملف أو المجلد المصدر بدءًا من جذر وحدة التخزين | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | اسم وحدة التخزين النشطة | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **مسار الوجهة** في عمليات `MOVE` أو `COPY` أو `RENAME` أو `CLONE` (`null` لباقي العمليات) | `'locked/new-name.pdf'` |

### مثال لمُصوّت مخصص (قفل المجلد `locked/` ضد الحذف وإعادة التسمية والنقل)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) منع حذف أو نقل أو إعادة تسمية المجلد "locked" أو أي عنصر داخله
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) منع نقل أو إعادة تسمية الملفات إلى داخل المجلد "locked" أيضًا
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 طبقة مدمجة للدفاع المتعمّق (محصّنة ضد الهجمات الواقعية)

| # | متجه الهجوم | حماية الحزمة |
| :---: | :--- | :--- |
| 1 | **هجمات تزوير الطلبات عبر المواقع (CSRF)** | يتحقق كل طلب `POST` للكتابة أو الحذف أو الرفع أو ZIP أو محرر الصور من ترويسة `X-LiveUX-FM-CSRF` **قبل** معالجة أي مدخلات (`CsrfRequestValidator`). |
| 2 | **اجتياز المسار وحيل Unicode وأنظمة التشغيل** | يحظر `PathNormalizer` أنماط `../` و`..\` وبايتات الصفر (`\0`) وأحرف التحكم و`C:\` ومغلّفات التدفق `phar://`/`php://` والنقاط والمسافات اللاحقة وأحرف Unicode عديمة العرض أو ثنائية الاتجاه والنقطة الصينية/اليابانية (`。`) وتدفقات NTFS البديلة (`file.txt:stream`) وأسماء أجهزة Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`)، ويفرض حدًا أقصى قدره **32 مستوى عمق**، و**255 بايت لكل مقطع**، و**1024 حرف UTF-8 / 3072 بايت** لطول المسار الإجمالي. |
| 3 | **الملفات التنفيذية والملفات متعددة الصيغ (Polyglot) والامتداد المزدوج** | يفرض `FileSecurityGuard` حظر **69 امتدادًا** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, إلخ) و**47 نوع MIME محظورًا**، وفحص الامتداد المزدوج (`shell.php.jpg`)، والتحقق من **تواقيع البايتات السحرية (Magic-Bytes Polyglot)** — ويُطبّق بصرامة عند الرفع وإعادة التسمية (حتى عند تفعيل `allow_extension_change: true`) والتنزيل. |
| 4 | **الوصول المباشر إلى المجلدات المخفية وسلة المحذوفات** | تُرفض المسارات الواقعة تحت `.trash` و`.env` و`.git` و`.htaccess` و`exclude_patterns` في جميع نقاط النهاية؛ ويُجري `ZipDownloadController` فحص `DOWNLOAD_ZIP` منفصلًا عبر `Voter` على **كل مسار محدد**. |
| 5 | **ثغرات SVG XSS وXXE وقنابل حجب الخدمة `<use>` DoS** | يرفض `SvgSanitizer` تعليمات `<!DOCTYPE`/`<!ENTITY`، ويفرض **قائمة بيضاء صارمة من 55 وسمًا**، ويحدد حجم ملف SVG بـ **5 ميجابايت** كحد أقصى، ويصفّي أنماط CSS (`@import`, `expression()`, `url(javascript:)`)، ويزيل `<script>` و`<foreignObject>` و`on*` و`javascript:`، ويقيّد تمدد `<use>` لمنع DoS (بحد أقصى `100` عنصر `<use>` وعمق `4` و`1000` عقدة ممتدة و`5000` خطوة تحليل و`8` معرفات مكررة). |
| 6 | **هجمات SSRF وإعادة ربط DNS (استيراد الروابط البعيدة)** | يكون `RemoteUrlImporter` معطّلًا افتراضيًا (`allow_remote_url_import: false`). وعند تفعيله، يتحقق من عناوين IP عبر `inet_pton()` لحظر عناوين loopback والنطاقات الخاصة و`0.0.0.0` و`100.64.0.0/10` (CGNAT) وبيانات السحابة الوصفية (`169.254.169.254`) وعناوين IPv4-mapped IPv6 (`::ffff:127.0.0.1`) وNAT64 (`64:ff9b::`) وSIIT/ORCHID/SRv6 وتجاوزات UTS-46 IDNA وعناوين IP كاملة العرض والثمانية والسداسية، بينما يقضي **تثبيت IP عبر `CURLOPT_RESOLVE`** على هجمات DNS Rebinding. |
| 7 | **تنفيذ الشفرات عن بُعد عبر صور Polyglot في محرر الصور** | يقيّد `ImageEditorController` خيار `overwrite` على ملفات الصور النقطية الآمنة الموجودة مسبقًا (`jpg/jpeg/png/webp`) فقط، ويفرض حدود `upload_max_size` وقواعد `upload_allow`/`upload_deny` في ملف التعريف، وفحوصات Usage Guard، والتحقق الحقيقي عبر `getimagesizefromstring()`. |
| 8 | **قنابل فك الضغط (Pixel Bombs) وثغرات ImageTragick** | يفحص كلٌّ من `GdThumbnailDriver` و`ImagickThumbnailDriver` أبعاد الصورة عبر `getimagesizefromstring()` قبل فك ترميزها في الذاكرة (بحد أقصى `8192×8192` و`40 MP`)، ويطبّق حدودًا صارمة للذاكرة والوقت والخيوط في Imagick. |
| 9 | **الهروب عبر الروابط الرمزية (Symlink Escape) والتقييد بالجذر** | يتحقق `ConfinedLocalFilesystemAdapter` من `is_link()` + `realpath()` لتأكيد التقييد داخل المجلد الجذري في كل مقطع مسار لكل عملية قراءة أو كتابة أو نقل أو نسخ أو حذف محلية؛ وتُرفض عمليات الحذف أو النقل المتكررة للأشجار الفرعية التي تحتوي على روابط رمزية، وتفرض قوائم المجلدات `SKIP_LINKS`. |
| 10 | **منع تصادم مسارات تعدد المستأجرين (`{user_id}`)** | يعزل `DynamicPathResolver` مجلدات المستخدمين بلواحق SHA-256 فريدة `<slug>--h-<16-hex>` لعناوين البريد الإلكتروني وحالات الأحرف المختلطة وأحرف Unicode والأسماء المحجوزة (`guest`, `user`) وأسماء أجهزة Windows — مما يجعل تصادم المجلدات بين مستخدمين أمرًا مستحيلًا. |
| 11 | **عزل ذاكرة التخزين المؤقت للتنزيلات والصور المصغّرة وCDN** | ترسل وحدات التحكم `DownloadController` و`ThumbnailController` و`ZipDownloadController` الترويسات `X-Content-Type-Options: nosniff` و`Referrer-Policy: no-referrer` و`Content-Security-Policy: default-src 'none'; sandbox`؛ وتفرض `Cache-Control: private` + `Vary: Cookie, Authorization` في سياقات الخصوصية/Voter/الجلسة/`{user_id}`؛ وتشترط صلاحية `CLEAR_CACHE` لاستخدام `?refresh=1` و`?fallback=1`؛ وتفرض `Content-Disposition: attachment` لملفات HTML/JS/SVG/XML. |
| 12 | **حماية طلبات POST في FormType ومصدر الروابط في Twig** | يرفض `FileManagerPickerType` حمولات POST التي جرى التلاعب بها من جهة الخادم (بروتوكولات XSS، `../`، `.trash/`، JSON أكبر من 256 كيلوبايت، أكثر من 500 عنصر، UTF-8 غير صالح، تجاوز `max_files`، مصفوفات HPP)؛ كما يمنع `FileManagerTwigExtension` ودالة الواجهة `isSafeUrl()` انتحال مصادر الروابط الخارجية أو الخطرة. |
| 13 | **حماية السمات/الأيقونات المخصصة ومنع DOM XSS** | يقبل المعامل `dialog?theme=` في وقت التشغيل السمات الـ 9 المدمجة فقط، ويقبل `dialog?iconSet=` حزم الأيقونات الـ 6 المدمجة فقط؛ وتمنع الدالة `sanitizeHexColor()` حقن قيم الألوان؛ وتُعزَل مجلدات تخزين الأجزاء المؤقتة للرفع لكل مستخدم بصلاحية `0750`. |
| 14 | **الوقاية من حجب الخدمة DoS وحقن السجلات وعزل الكاش** | يُحدّد الحذف الجماعي بـ 200 عنصر كحد أقصى، والرفع المجزّأ بـ 2000 جزء، ونسخ المجلدات المتكرر بـ 5000 عنصر؛ وينقّي `AuditLogListener` حمولات حقن السجلات (`\r`/`\n`/ANSI/أحرف التحكم)؛ ولا يمسح `DirectoryCacheManager` مطلقًا مخزن `cache.app` للتطبيق المستضيف (لا يستدعي `clear()` أبدًا). |

---

## 5. تحصين خادم الويب (Apache وNginx)

كطبقة دفاع إضافية، عطّل دائمًا تنفيذ ملفات PHP والسكربتات داخل مجلدات الرفع المكشوفة للعامة مثل `public/uploads` على مستوى خادم الويب:

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. روابط المشاركة الموقّعة وحارس استخدام الأصول

- **روابط المشاركة المؤقتة والموقّعة (`ShareController`):** عند تفعيل `share.enabled: true`، تُوقَّع الروابط المؤقتة باستخدام `UriSigner` في Symfony (توقيع HMAC + طابع زمني للانتهاء `expires`). يؤدي ضبط `share.enabled: false` على ملف التعريف إلى إبطال جميع الروابط القائمة فورًا برمز `403 Forbidden`، وتعيد الروابط المنتهية الصلاحية `410 Gone`، وتحمل جميع الاستجابات ترويسة `Cache-Control: private, no-store` مع تشديد CSP.
- **حارس استخدام الأصول (`AssetUsageCheckerInterface`):** يمنع حذف أو إعادة تسمية أو نقل أو استبدال الملفات المرتبطة فعليًا بسجلات في قاعدة البيانات (راجع [10 — حارس استخدام الأصول](10-asset-usage-guard.md)).

---

## 7. سجل التدقيق — إعداد الإنتاج والأمثلة

يسجّل `AuditLogListener` جميع أحداث تعديل الملفات والمجلدات التسعة ضمن سياق PSR-3 منظّم (`action`، `profile`، `volume`، `path`، `user`، `ip`، إضافةً إلى البيانات الوصفية الخاصة بكل حدث) وينقّي تلقائيًا محارف التحكم وCRLF وBiDi لمنع حقن السجلات.

### الخطوة 1: تفعيل سجل التدقيق (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # تفعيل أو تجاوز الإعداد لكل ملف تعريف
        public_temp:
            security:
                audit_log: false
```

### الخطوة 2: توجيه إدخالات التدقيق إلى ملف سجل JSON دوري مخصّص (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # الاحتفاظ والتدوير لمدة 90 يومًا
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### مثال على مخرجات JSON المنظّمة

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### جميع أحداث دورة الحياة التسعة الخاضعة للتدقيق

| حدث Symfony | مفتاح `action` | حقول السياق الإضافية |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### الخطوة 3 (اختيارية): حفظ سجلات التدقيق في جدول قاعدة بيانات Doctrine

لعرض سجل تدقيق قابل للبحث داخل لوحة الإدارة، اشترك في أحداث Symfony نفسها:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> في التطبيقات ذات الحركة العالية، أرسل رسالة غير متزامنة عبر Symfony **Messenger** بدلاً من استدعاء `$this->em->flush()` مباشرةً حتى تبقى عمليات الملفات غير معطّلة.

---

[← السابق: 05 — حقل النموذج (`FormType`) وتكامل المحررات](05-formtype-and-editor-integrations.md) | [الفهرس](README.md) | [التالي: 07 — التخزين السحابي والإعداد الديناميكي →](07-cloud-storage-and-dynamic-config.md)
