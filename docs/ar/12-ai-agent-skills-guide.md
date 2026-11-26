# 12 — دليل مهارات وكلاء الذكاء الاصطناعي (`SKILL.md` و `AGENTS.md`)

[← السابق: 11 — دليل المساهمة وطلبات الدمج (PR)](11-contributing-and-pr-guide.md) | [فهرس التوثيق](README.md) | [التالي: 13 — التكامل مع Doctrine →](13-doctrine-integration.md)

> [!TIP]
> **ببساطة: ما فائدة مهارات الوكلاء (`SKILL.md`)؟**
> إذا كنت تستخدم مساعد برمجة بالذكاء الاصطناعي مثل **Antigravity أو Cursor أو Claude Code أو GitHub Copilot أو OpenAI Codex**، فشغّل الأمر `php bin/console file-manager:skills:install` مرة واحدة داخل مشروعك. سيُعلّم هذا الأمر مساعدك المفاتيح الدقيقة لإعدادات YAML الخاصة بالحزمة، وثوابت الأمان، والأمثلة القياسية السليمة — بحيث يولّد شيفرة تعمل من المحاولة الأولى بدلاً من اختلاق خيارات غير موجودة.

تأتي **LiveUX FileManagerBundle** مزودة بحزمة مهارات مدمجة تتبع المواصفة المفتوحة **Agent Skills (`SKILL.md`)** حتى تتمكن أدوات البرمجة الحديثة المدعومة بالذكاء الاصطناعي (**Antigravity، Claude Code، Cursor، GitHub Copilot، OpenAI Codex، Windsurf** و**Symfony AI Mate**) من دمج الحزمة بدقة وأمان ودون أي تخمين.

---

## 1. المهارات الست المتخصصة المضمّنة في الحزمة

تُنظم المهارات وفق مبدأ **الإفصاح التدريجي (Progressive Disclosure)**: إذ يحمّل مساعد الذكاء الاصطناعي المهارة ذات الصلة بمهمتك الحالية فقط، مما يحافظ على سياق العمل خفيفاً ومركزاً:

| اسم المهارة (`skills/<name>/`) | متى يتم تفعيلها | الملف المرجعي |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | التثبيت، التوجيه (routing)، AssetMapper/Encore، الملفات التعريفية ووحدات التخزين (Local/S3/R2/MinIO/Supabase)، الحصص التخزينية (`storage_quota`، `show_quota`)، تحسين الصور، واكتشاف التكرارات | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (ملف واحد/معرض صور)، الأنماط الخمسة المدمجة، قوالب Twig المخصصة لـ FormType (`liveux:file-manager:picker-changed`)، حقل EasyAdmin `FileManagerField`، واجهة JS API، وTinyMCE وCKEditor 5 وTiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | السمات الـ 9 المدمجة، الوضع الداكن (`color_scheme`)، سمات CSS فقط أو قوالب Twig المخصصة من 10 كتل، ونظام أيقونات SVG ذو الطبقات الثلاث (49 أيقونة واجهة + 14 شارة مجلد) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | جدار حماية `security.yaml`، صلاحيات Voter الـ 17، وحدات Voter مخصصة على مستوى المجلد (`unanimous`)، حارس استخدام الأصول (`AssetUsageCheckerInterface` — الحذف/إعادة التسمية/النقل/الاستبدال)، روابط المشاركة الموقّعة، سجل التدقيق (Audit Log)، وتحصين Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | مزود الإعدادات الديناميكي من قاعدة البيانات `ConfigProviderInterface`، تخزين البيانات الوصفية المخصص `FolderMetadataStorageInterface`، مستمعو أحداث Symfony التسعة، تكامل Symfony AI (`AssetEnricherInterface`)، وجميع أوامر CLI | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | قواعد تطوير الحزمة لتوافق PHP 8.3 / Symfony 7.2–8.0، وثوابت الأمان، وتكافؤ الترجمات، وبوابة جودة PR عبر `composer qa` | `AGENTS.md` (جذر الحزمة) |

---

## 2. ثلاث طرق لتثبيت المهارات في مشروعك

### الطريقة أ — أمر Symfony Console المدمج (موصى به، دون أي اعتماديات إضافية)

```bash
# لـ Antigravity و Codex و OpenCode و Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# لـ Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# لـ Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# لـ GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# التثبيت في جميع أدوات الذكاء الاصطناعي المدعومة بأمر واحد
php bin/console file-manager:skills:install --target=all
```

### جميع خيارات الأمر

| الخيار / الاختصار | الوصف | مثال |
| :--- | :--- | :--- |
| `-t` / `--target` | أداة الذكاء الاصطناعي المستهدفة (`agents`، `claude`، `cursor`، `copilot`، `all`). القيمة الافتراضية: `agents`. | `-t all` |
| `--dir` | مسار مجلد هدف مخصص. **قاعدة أمنية:** يجب أن يكون مجلداً فرعياً داخل `%kernel.project_dir%` حصراً (يتم رفض `..`، وبايتات NUL، ومجلد جذر المشروع نفسه، والمجلدات الأب المرتبطة بروابط رمزية). | `--dir=.custom/skills` |
| `--only` | تثبيت مهارات مختارة فقط (أسماء مختصرة أو كاملة مفصولة بفواصل). | `--only=setup,security` |
| `-f` / `--force` | استبدال مجلدات المهارات الموجودة بأحدث إصدار. | `--force` |
| `--symlink` | إنشاء روابط رمزية بدلاً من نسخ الملفات (يعود تلقائياً إلى النسخ على Windows إذا لم تتوفر صلاحيات الروابط الرمزية). | `--symlink` |
| `--dry-run` | معاينة المهارات التي سيتم تثبيتها ومواقعها دون كتابة أي شيء على القرص. | `--dry-run` |

### الطريقة ب — الاكتشاف التلقائي عبر Symfony AI Mate (`symfony/ai-mate`)

يصرّح ملف `composer.json` الخاص بالحزمة عن `extra.ai-mate.skills`:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

إذا كانت الحزمة `symfony/ai-mate` مثبتة في مشروعك، فإن أمراً واحداً يكتشف جميع المهارات الست ويزامنها مع مساحة عملك:

```bash
vendor/bin/mate discover
```

### الطريقة ج — أداة سطر الأوامر المفتوحة Open Agent Skills CLI (`npx skills`)

ثبّت المهارات مباشرة من GitHub داخل أي مشروع:

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. ملف `AGENTS.md` في جذر الحزمة

يوفر ملف `AGENTS.md` في جذر الحزمة قواعد نشطة دائماً لوكلاء الذكاء الاصطناعي المساهمين مباشرة في تطوير الحزمة نفسها:
- انعدام الاعتماد على أي إطار CSS وقواعد الجسور الاختيارية (optional bridges)
- التنميط الصارم في PHP (`declare(strict_types=1)`) وعناصر الاختبار البديلة (test stubs) المتوافقة مع Symfony 7.2 / 8.0
- حظر فلتر `|raw` في Twig، وإلزامية استخدام `isSafeUrl()` / `escapeHtml()` في JavaScript
- التحقق من CSRF أولاً، وعزل ذاكرة التخزين المؤقت الخاصة ووحدات Voter، وتكافؤ الترجمات بنسبة 100% عبر **لغات الواجهة الـ 12 المدمجة** (`en`، `tr`، `fr`، `de`، `es`، `ar`، `it`، `pt`، `nl`، `pl`، `ja`، `zh_CN`)

---

## 4. التحقق الآلي في CI

عند كل طلب دمج وتشغيل CI، يتحقق الاختبار `SkillsPackageIntegrityTest` تلقائياً مما يلي:
1. وجود `AGENTS.md` وبقائه ضمن ميزانية السياق **`<= 24,000 bytes`**، وعدم احتوائه عن طريق الخطأ على ترويسة YAML frontmatter.
2. تعريف `extra.ai-mate.skills` بشكل صحيح في `composer.json`.
3. احتواء كل مجلد تحت `skills/` على ملف `SKILL.md` بترويسة YAML frontmatter صالحة يطابق فيها الحقل `name` اسم المجلد تماماً، مع التصريح عن `license: MIT`، وأن لا يقل طول `description` عن `40` حرفاً، وألا يتجاوز كل ملف `500` سطر.
4. أن كل رابط نسبي `references/*.md` داخل `SKILL.md` يشير إلى ملف موجود فعلياً على القرص.

---

[← السابق: 11 — دليل المساهمة وطلبات الدمج (PR)](11-contributing-and-pr-guide.md) | [فهرس التوثيق](README.md) | [التالي: 13 — التكامل مع Doctrine →](13-doctrine-integration.md)
