# 07 — التخزين السحابي (S3 / R2 / MinIO / Supabase) والإعداد الديناميكي من لوحة التحكم

[→ السابق: 06 — الأمان والصلاحيات](06-security-and-authorization.md) | [الفهرس الرئيسي](README.md) | [التالي: 08 — الأحداث، الأوامر، التخزين المؤقت واستكشاف الأخطاء ←](08-events-commands-cache-and-troubleshooting.md)

تعتمد حزمة LiveUX FileManagerBundle على **League Flysystem v3**. يمكنك الجمع بين الأقراص المحلية والتخزين السحابي جنبًا إلى جنب ضمن ملف التعريف نفسه (**متعدد وحدات التخزين / Multi-Volume**)، بل وسحب الملفات وإفلاتها مباشرةً من التخزين المحلي إلى S3 أو R2 أو Supabase.

> [!TIP]
> **طريقتان لإدارة بيانات اعتماد التخزين السحابي**
> 1. **عبر `.env` + YAML (مخصّصة للمطورين، وأسرع في الإعداد):** احفظ المفاتيح في `.env.local` وعرّف وحدات التخزين في `liveux_file_manager.yaml` (القسم 1).
> 2. **عبر لوحة التحكم + قاعدة البيانات (جاهزة للعملاء وتطبيقات SaaS):** أنشئ شاشة *«إعدادات التخزين السحابي»* في لوحة التحكم ليدخل المشرفون بيانات اعتماد AWS S3 / R2 / Supabase، وينقروا على **«اختبار الاتصال»**، ثم يحفظوا الإعدادات — فتظهر وحدات التخزين السحابية الجديدة فورًا في مدير الملفات دون الحاجة إلى الوصول عبر SSH أو تعديل ملفات `.env` (القسم 3).

---

## أدلة مزوّدي التخزين السحابي خطوة بخطوة

انتقل مباشرةً إلى دليل الإعداد المخصّص لمزوّد التخزين الذي تستخدمه:

| المزوّد | متى تختاره؟ | المشغّل (Driver) | مفتاح الأيقونة | الدليل |
| :--- | :--- | :--- | :--- | :--- |
| **Supabase Storage** | إذا كنت تستخدم Supabase بالفعل وتريد إعدادًا سريعًا وباقة مجانية سخية | `AwsS3` | `supabase` *(تلقائي)* | [دليل Supabase Storage ←](cloud/supabase-storage.md) |
| **Amazon AWS S3** | للتطبيقات المؤسسية الكبرى، وأدوار IAM، والتكامل مع CloudFront CDN | `AwsS3` | `aws_s3` *(تلقائي)* | [دليل Amazon AWS S3 ←](cloud/aws-s3.md) |
| **Cloudflare R2** | **بدون رسوم نقل بيانات صادرة (Zero egress fees)**، ومثالي لتحميل الصور والملفات عالية الزيارات | `AwsS3` | `cloudflare_r2` *(تلقائي)* | [دليل Cloudflare R2 ←](cloud/cloudflare-r2.md) |
| **Backblaze B2** | تكلفة تخزين منخفضة جدًا لكل جيجابايت، مثالي لأرشيفات الفيديو والنسخ الاحتياطية الضخمة | `AwsS3` | `backblaze_b2` *(تلقائي)* | [دليل Backblaze B2 ←](cloud/backblaze-b2.md) |
| **MinIO / RustFS** | تخزين S3 مستضاف ذاتيًا على خادمك الخاص أو عبر Docker (لالتزامات GDPR وإقامة البيانات) | `AwsS3` | `minio` *(تلقائي)* | [دليل MinIO و RustFS ←](cloud/minio-rustfs.md) |
| **Google Cloud Storage** | للتطبيقات العاملة على منصة Google Cloud Platform (GCP) | `AwsS3` | `gcs` *(تلقائي)* | [دليل Google Cloud Storage ←](cloud/google-cloud-storage.md) |
| **SFTP / Azure / مخصّص** | خادم SFTP قائم، أو Azure Blob، أو أي محوّل Flysystem مخصّص | `FlysystemService` | `sftp` / `cloud` | [دليل SFTP و Flysystem المخصّص ←](cloud/sftp-and-custom-flysystem.md) |

> [!NOTE]
> بعد تهيئة وحدات التخزين، اختبر جميع اتصالات السحابة بشكل حي ومباشر بأمر واحد:
> ```bash
> php bin/console file-manager:doctor --check-remote
> ```

---

## 1. إعداد التخزين السحابي المتوافق مع S3 (عبر `.env` + YAML في 3 خطوات)

### الخطوة 1: تثبيت حزم S3

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

### الخطوة 2: إضافة بيانات الاعتماد إلى `.env.local`

```dotenv
# .env.local
AWS_S3_KEY=AKIA...
AWS_S3_SECRET=...
AWS_S3_REGION=eu-central-1
AWS_S3_BUCKET=my-media-bucket

R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_KEY=...
R2_SECRET=...
R2_BUCKET=cdn-assets
```

### الخطوة 3: تعريف وحدات التخزين في `liveux_file_manager.yaml`

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'القرص المحلي'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        aws_s3_cdn:
            label: 'AWS S3 CDN'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.example.com'
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'       # تعمل العناصر النائبة الديناميكية {Y}/{m}/{d}/{user_id} داخل بادئات S3 أيضًا!
                disable_acl: true         # موصى به صراحةً لحاويات AWS S3 القياسية بنمط "Bucket owner enforced"

        cloudflare_r2:
            label: 'Cloudflare R2'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.example.com'
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'aws_s3_cdn', 'cloudflare_r2']
```

### المرجع الكامل لخيارات `driver_options` في `AwsS3`

| الخيار | القيمة الافتراضية | الوصف |
| :--- | :--- | :--- |
| `bucket` | *(إلزامي)* | اسم حاوية S3 المستهدفة. |
| `region` | `'us-east-1'` | رمز المنطقة (مثل `'eu-central-1'`، أو `'auto'` لـ Cloudflare R2). |
| `endpoint` | `null` | عنوان نقطة نهاية مخصّص لمزوّدي التخزين المتوافقين مع S3 من غير AWS (مثل R2 و Supabase و B2 و MinIO و GCS). |
| `key` / `secret` | `null` | بيانات اعتماد الوصول إلى S3 (اتركها فارغة لاستخدام أدوار EC2/ECS IAM تلقائيًا). |
| `token` | `null` | رمز جلسة AWS STS مؤقت (`AWS_SESSION_TOKEN`). |
| `version` | `'latest'` | إصدار واجهة برمجة تطبيقات AWS S3 SDK. |
| `prefix` | `''` | المجلد الفرعي الجذري داخل الحاوية (يدعم `{user_id}` و `{Y}` و `{m}` و `{d}`). |
| `use_path_style_endpoint` | `false` | اضبطه على `true` مع Supabase و MinIO و R2 و B2 والمزوّدين المشابهين. |
| `acl` | `null` | ترويسة S3 ACL مخصّصة عند الحاجة (مثل `'public-read'`). |
| `disable_acl` | **`true` عند تعيين `endpoint` مخصّص؛ و`false` مع AWS S3 الأصلي** | عند تعيينه على `true`، يُحذف الترويس `x-amz-acl`. اضبط `disable_acl: true` صراحةً على حاويات AWS S3 الأصلية التي تفعّل خيار *Bucket owner enforced*. |
| `retain_visibility` | `false` عند تعيين `endpoint` مخصّص | يتحكم فيما إذا كان يُحاوَل قراءة/كتابة ACL أثناء عمليات النقل والنسخ. |
| `request_checksum_calculation` / `response_checksum_validation` | `'when_required'` عند تعيين `endpoint` مخصّص | يُضبط تلقائيًا لضمان التوافق مع `AWS SDK >= 3.337` على R2 و B2 و Supabase و MinIO. |
| `ca_bundle` | `null` | مسار ملف شهادة SSL CA مخصّص بصيغة `.pem` (يحل خطأ `cURL error 60` على نظام Windows). |

### ملاحظات معمارية

- **عزل الروابط الرمزية على القرص المحلي (`ConfinedLocalFilesystemAdapter`):** تتحقق كل عملية قراءة أو كتابة أو نقل أو حذف من كل جزء في المسار عبر `is_link()` + `realpath()`؛ كما تفرض قوائم المجلدات الخيار `SKIP_LINKS`.
- **حاويات السحابة المقيّدة بأنواع MIME وسلة المهملات:** إذا رفضت حاوية سحابية كتابة الملفات الجانبية `.meta.json`، ينتقل `TrashManager` تلقائيًا إلى حفظ البيانات الوصفية لسلة المهملات محليًا (`trash-meta.json`) داخل مجلد `defaults.metadata.file`.
- **الصور المصغّرة والفيديو وملفات PDF:** تُجلب الصور المصغّرة مرة واحدة من التخزين السحابي وتُخزّن مؤقتًا محليًا في `public/media-cache`. تُحمّل الصور ومقاطع الفيديو العامة (`private: false`) مباشرةً من رابط CDN الخاص بها؛ أما ملفات PDF فتُبثّ عبر `DownloadController?inline=1` مع سياسة أمان محتوى صارمة `script-src 'none'` (أو فرض التنزيل كـ `attachment` عندما يكون `allow_pdf_preview: false`).
- **سياسة CORS:** لاستخدام محرّر الصور المدمج (Canvas Image Editor) مع الصور المستضافة سحابيًا، اسمح بطلبات `GET` و `HEAD` من نطاق موقعك في سياسة CORS الخاصة بالحاوية.
- **العمليات طويلة الأمد (Long-Running Workers):** ينفّذ `FlysystemStorageFactory` الواجهة `ResetInterface` ويحافظ على محدودية ذاكرة التخزين المؤقت للمحوّلات (بتقليصها من 64 ← 32) عبر الطلبات.

---

## 2. إعادة استخدام خدمة Flysystem قائمة (`FlysystemService`)

إذا كان تطبيقك يعرّف بالفعل خدمة `League\Flysystem\FilesystemOperator` (عبر `league/flysystem-bundle` أو ربط مخصّص لـ Azure Blob أو SFTP أو FTP أو WebDAV وغيرها)، يمكنك الإشارة إلى معرّف الخدمة مباشرةً:

```yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'أرشيف SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            private: true
            driver_options:
                service: 'app.sftp.storage' # معرّف خدمة League\Flysystem\FilesystemOperator
```

> [!NOTE]
> **لا حاجة لـ `public: true`!** يقوم `FlysystemServiceLocatorPass` تلقائيًا بتسجيل كل خدمة مُشار إليها عبر `driver_options.service` — وكذلك الخدمات الموسومة بـ `liveux_file_manager.storage` أو `flysystem.storage` — داخل `ServiceLocator` خاص.

---

## 3. إدارة إعدادات السحابة من لوحة التحكم (عبر قاعدة البيانات) — الدليل الكامل

إذا أردت تمكين العملاء أو مشرفي الموقع من **إعداد حاويات AWS S3 أو Cloudflare R2 أو Supabase أو MinIO أو Backblaze B2 الخاصة بهم مباشرةً من لوحة التحكم**، فاتّبع هذه **الخطوات الخمس**.

### آلية العمل (الصورة الكاملة)

```text
[نموذج لوحة التحكم] ──► تشفير المفتاح السري (Sodium) ──► [قاعدة البيانات: cloud_storage_setting]
        │                                                            │
        ▼                                                            ▼
[زر "اختبار الاتصال"]                                    [DatabaseCloudConfigProvider]
 (FlysystemStorageFactory)                               (priority: 10 > YAML priority: 0)
        │                                                            │
        ▼                                                            ▼
[إبطال التخزين المؤقت (DirectoryCacheManager)]           [تظهر فورًا في مدير الملفات!]
```

### الخطوة 1: إنشاء كيان قاعدة البيانات (`CloudStorageSetting`)

> [!WARNING]
> **القاعدة الأمنية الذهبية:** **لا تقم أبدًا** بتخزين مفتاح الوصول السري `Secret Access Key` الخاص بـ S3 كنص عادي في قاعدة البيانات. احفظ دائمًا نصًا مشفّرًا (`encryptedSecret`) كما هو موضّح أدناه.

```php
<?php
// src/Entity/CloudStorageSetting.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cloud_storage_setting')]
class CloudStorageSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // مفتاح فريد لوحدة التخزين — مثل 'panel_r2' أو 'panel_supabase' (أحرف إنجليزية وأرقام وشرطة سفلية فقط)
    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    // الاسم المعروض في الشريط الجانبي — مثل 'وسائط Cloudflare R2'
    #[ORM\Column(length: 120)]
    private string $label = '';

    // مفتاح المزوّد: 'aws_s3' | 'cloudflare_r2' | 'supabase' | 'backblaze_b2' | 'minio' | 'gcs'
    #[ORM\Column(length: 32)]
    private string $provider = 'aws_s3';

    #[ORM\Column(length: 120)]
    private string $bucket = '';

    #[ORM\Column(length: 64)]
    private string $region = 'eu-central-1';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $endpoint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $prefix = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $urlPrefix = null;

    #[ORM\Column(length: 255)]
    private string $accessKey = '';

    // نص مشفّر بـ Sodium ومُرمّز بـ Base64 (ليس نصًا عاديًا أبدًا!)
    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedSecret = '';

    #[ORM\Column]
    private bool $private = false;

    #[ORM\Column]
    private bool $usePathStyleEndpoint = true;

    #[ORM\Column]
    private bool $disableAcl = true;

    #[ORM\Column]
    private int $cacheTtl = 60;

    #[ORM\Column]
    private bool $enabled = true;

    // ملف تعريف FileManager الذي ترتبط به وحدة التخزين (مثل 'default')
    #[ORM\Column(length: 64)]
    private string $profileName = 'default';

    // دوال الجلب والتعيين القياسية (Getters & Setters)...
    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }
    public function getBucket(): string { return $this->bucket; }
    public function setBucket(string $bucket): self { $this->bucket = $bucket; return $this; }
    public function getRegion(): string { return $this->region; }
    public function setRegion(string $region): self { $this->region = $region; return $this; }
    public function getEndpoint(): ?string { return $this->endpoint; }
    public function setEndpoint(?string $endpoint): self { $this->endpoint = $endpoint; return $this; }
    public function getPrefix(): ?string { return $this->prefix; }
    public function setPrefix(?string $prefix): self { $this->prefix = $prefix; return $this; }
    public function getUrlPrefix(): ?string { return $this->urlPrefix; }
    public function setUrlPrefix(?string $urlPrefix): self { $this->urlPrefix = $urlPrefix; return $this; }
    public function getAccessKey(): string { return $this->accessKey; }
    public function setAccessKey(string $accessKey): self { $this->accessKey = $accessKey; return $this; }
    public function getEncryptedSecret(): string { return $this->encryptedSecret; }
    public function setEncryptedSecret(string $encryptedSecret): self { $this->encryptedSecret = $encryptedSecret; return $this; }
    public function isPrivate(): bool { return $this->private; }
    public function setPrivate(bool $private): self { $this->private = $private; return $this; }
    public function isUsePathStyleEndpoint(): bool { return $this->usePathStyleEndpoint; }
    public function setUsePathStyleEndpoint(bool $usePathStyleEndpoint): self { $this->usePathStyleEndpoint = $usePathStyleEndpoint; return $this; }
    public function isDisableAcl(): bool { return $this->disableAcl; }
    public function setDisableAcl(bool $disableAcl): self { $this->disableAcl = $disableAcl; return $this; }
    public function getCacheTtl(): int { return $this->cacheTtl; }
    public function setCacheTtl(int $cacheTtl): self { $this->cacheTtl = $cacheTtl; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getProfileName(): string { return $this->profileName; }
    public function setProfileName(string $profileName): self { $this->profileName = $profileName; return $this; }
}
```

### الخطوة 2: بناء خدمة تشفير المفاتيح السرية (باستخدام `ext-sodium` المدمج في PHP 8.3)

استفد من التشفير المصادق عليه المدمج في PHP (`sodium_crypto_secretbox`) دون تثبيت أي حزم إضافية:

```php
<?php
// src/Security/SecretBoxEncryptor.php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SecretBoxEncryptor
{
    private string $key;

    public function __construct(
        // نصيحة: عرّف مفتاحًا مستقلًا باسم CLOUD_ENCRYPTION_KEY في .env (منفصلًا عن APP_SECRET)
        #[Autowire('%env(CLOUD_ENCRYPTION_KEY)%')]
        string $rawMasterKey,
    ) {
        // اشتقاق مفتاح تشفير ثابت بطول 32 بايت
        $this->key = hash('sha256', $rawMasterKey, true);
    }

    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainText, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('تعذّر فك ترميز مفتاح التخزين السحابي المشفّر.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (!is_string($plain)) {
            throw new \RuntimeException('مفتاح التخزين السحابي المشفّر غير صالح أو تالف.');
        }

        return $plain;
    }
}
```

### الخطوة 3: تنفيذ مزوّد إعدادات ديناميكي `ConfigProviderInterface` (`DatabaseCloudConfigProvider`)

عند تسجيله بأولوية **`priority: 10`**، يعمل هذا المزوّد قبل مزوّد YAML (`priority: 0`). يقوم بتحميل ملف تعريف YAML الأساسي من `$this->yamlProvider` ثم يلحق به وحدات التخزين السحابية النشطة المخزّنة في قاعدة البيانات — وبذلك **تبقى أقراص YAML المحلية كما هي بينما تظهر الأقراص السحابية المحفوظة حديثًا في الشريط الجانبي فورًا**:

```php
<?php
// src/FileManager/DatabaseCloudConfigProvider.php

declare(strict_types=1);

namespace App\FileManager;

use App\Entity\CloudStorageSetting;
use App\Security\SecretBoxEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Service\ResetInterface;

#[AutoconfigureTag('liveux_file_manager.config_provider', ['priority' => 10])]
final class DatabaseCloudConfigProvider implements ConfigProviderInterface, ResetInterface
{
    /** @var array<string, list<CloudStorageSetting>>|null */
    private ?array $settingsByProfile = null;

    public function __construct(
        private readonly YamlConfigProvider $yamlProvider,
        private readonly EntityManagerInterface $em,
        private readonly SecretBoxEncryptor $encryptor,
    ) {
    }

    public function reset(): void
    {
        $this->settingsByProfile = null;
    }

    public function hasProfile(string $name): bool
    {
        return $this->yamlProvider->hasProfile($name);
    }

    public function getProfile(string $name): ProfileConfig
    {
        $base = $this->yamlProvider->getProfile($name);
        $dbSettings = $this->loadSettingsByProfile()[$name] ?? [];
        if ($dbSettings === []) {
            return $base;
        }

        // دمج وحدات التخزين السحابية المعرّفة في قاعدة البيانات بجانب وحدات YAML الحالية:
        $mergedVolumes = $base->volumes;
        foreach ($dbSettings as $setting) {
            if (!in_array($setting->getCode(), $mergedVolumes, true)) {
                $mergedVolumes[] = $setting->getCode();
            }
        }

        return new ProfileConfig(
            name: $base->name,
            volumes: $mergedVolumes,
            driver: $base->driver,
            path: $base->path,
            urlPrefix: $base->urlPrefix,
            private: $base->private,
            driverOptions: $base->driverOptions,
            ignoreDotFiles: $base->ignoreDotFiles,
            excludePatterns: $base->excludePatterns,
            sortBy: $base->sortBy,
            sortDirection: $base->sortDirection,
            foldersFirst: $base->foldersFirst,
            uploadSubfolder: $base->uploadSubfolder,
            uploadAllow: $base->uploadAllow,
            uploadDeny: $base->uploadDeny,
            uploadMaxSize: $base->uploadMaxSize,
            storageQuota: $base->storageQuota,
            overwritePolicy: $base->overwritePolicy,
            chunkUpload: $base->chunkUpload,
            duplicateDetection: $base->duplicateDetection,
            naming: $base->naming,
            imageOptimization: $base->imageOptimization,
            imageConstraints: $base->imageConstraints,
            thumbnail: $base->thumbnail,
            trash: $base->trash,
            zip: $base->zip,
            share: $base->share,
            ai: $base->ai,
            usageGuard: $base->usageGuard,
            cache: $base->cache,
            security: $base->security,
            permissions: $base->permissions,
            ui: $base->ui,
            metadata: $base->metadata,
            locale: $base->locale,
        );
    }

    public function getProfileNames(): array
    {
        return $this->yamlProvider->getProfileNames();
    }

    public function hasVolume(string $name): bool
    {
        return $this->findSettingByCode($name) !== null || $this->yamlProvider->hasVolume($name);
    }

    public function getVolume(string $name): VolumeConfig
    {
        $setting = $this->findSettingByCode($name);
        if ($setting instanceof CloudStorageSetting) {
            return $this->toVolumeConfig($setting);
        }

        return $this->yamlProvider->getVolume($name);
    }

    public function getVolumes(): array
    {
        $volumes = $this->yamlProvider->getVolumes();
        foreach ($this->loadSettingsByProfile() as $list) {
            foreach ($list as $setting) {
                $volumes[$setting->getCode()] = $this->toVolumeConfig($setting);
            }
        }

        return $volumes;
    }

    public function toVolumeConfig(CloudStorageSetting $setting): VolumeConfig
    {
        $options = [
            'bucket' => $setting->getBucket(),
            'region' => $setting->getRegion(),
            'key' => $setting->getAccessKey(),
            'secret' => $this->encryptor->decrypt($setting->getEncryptedSecret()),
            'use_path_style_endpoint' => $setting->isUsePathStyleEndpoint(),
            'disable_acl' => $setting->isDisableAcl(),
            'retain_visibility' => false,
        ];

        if ($setting->getEndpoint() !== null && $setting->getEndpoint() !== '') {
            $options['endpoint'] = $setting->getEndpoint();
        }
        if ($setting->getPrefix() !== null && $setting->getPrefix() !== '') {
            $options['prefix'] = $setting->getPrefix();
        }

        return new VolumeConfig(
            name: $setting->getCode(),
            label: $setting->getLabel(),
            group: 'network',
            driver: 'AwsS3',
            path: null,
            urlPrefix: $setting->isPrivate() ? null : $setting->getUrlPrefix(),
            private: $setting->isPrivate(),
            driverOptions: $options,
            icon: $setting->getProvider(),
            cacheTtl: $setting->getCacheTtl(),
        );
    }

    /** @return array<string, list<CloudStorageSetting>> */
    private function loadSettingsByProfile(): array
    {
        if ($this->settingsByProfile !== null) {
            return $this->settingsByProfile;
        }

        $grouped = [];
        /** @var list<CloudStorageSetting> $rows */
        $rows = $this->em->getRepository(CloudStorageSetting::class)->findBy(['enabled' => true]);
        foreach ($rows as $row) {
            $grouped[$row->getProfileName()][] = $row;
        }

        return $this->settingsByProfile = $grouped;
    }

    private function findSettingByCode(string $code): ?CloudStorageSetting
    {
        foreach ($this->loadSettingsByProfile() as $list) {
            foreach ($list as $setting) {
                if ($setting->getCode() === $code) {
                    return $setting;
                }
            }
        }

        return null;
    }
}
```

### الخطوة 4: وحدة تحكم المشرف (الحفظ + زر «اختبار الاتصال» المباشر + إبطال التخزين المؤقت)

عندما يحفظ المشرف إعدادات السحابة من لوحة التحكم، تجري ثلاث خطوات أساسية:

1. إذا أُدخل مفتاح سري جديد، يُشفَّر عبر `SecretBoxEncryptor::encrypt()` (ولا يُعاد عرض المفتاح السري الحالي في HTML **أبدًا**!).
2. عند النقر على زر **«اختبار الاتصال»** الاختياري، يُستدعى `FlysystemStorageFactory::createFilesystem()` ويُجرى فحص حي عبر `listContents('', false)` على الحاوية للتحقق من بيانات الاعتماد ونقطة النهاية قبل الحفظ.
3. بعد الحفظ، يُستدعى كل من `DirectoryCacheManager::invalidateProfile()` و`FlysystemStorageFactory::reset()` لتفعيل وحدة التخزين الجديدة فورًا.

```php
<?php
// src/Controller/Admin/CloudStorageSettingController.php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CloudStorageSetting;
use App\FileManager\DatabaseCloudConfigProvider;
use App\Form\Admin\CloudStorageSettingType;
use App\Security\SecretBoxEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Cache\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/cloud-storage', name: 'admin_cloud_storage_')]
final class CloudStorageSettingController extends AbstractController
{
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        CloudStorageSetting $setting,
        Request $request,
        EntityManagerInterface $em,
        SecretBoxEncryptor $encryptor,
        DatabaseCloudConfigProvider $configProvider,
        FlysystemStorageFactory $storageFactory,
        DirectoryCacheManager $cacheManager,
    ): Response {
        $form = $this->createForm(CloudStorageSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // الحقل plainSecret مضبوط على mapped: false في النموذج؛ تركه فارغًا يحافظ على المفتاح المشفّر الحالي
            $plainSecret = (string) $form->get('plainSecret')->getData();
            if ($plainSecret !== '') {
                $setting->setEncryptedSecret($encryptor->encrypt($plainSecret));
            }

            // إذا نُقر على "اختبار الاتصال"، نتحقق من الاتصال مباشرةً قبل الحفظ:
            if ($request->request->has('test_connection')) {
                try {
                    $volume = $configProvider->toVolumeConfig($setting);
                    $fs = $storageFactory->createFilesystem($volume);
                    // قراءة أول عنصر للتحقق المباشر من المفتاح والحاوية ونقطة النهاية:
                    foreach ($fs->listContents('', false) as $ignored) {
                        break;
                    }
                    $this->addFlash('success', 'نجح الاتصال بالسحابة! يمكنك الآن حفظ هذه الإعدادات.');
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'فشل الاتصال: ' . $e->getMessage());
                }

                return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
            }

            $em->flush();

            // مسح الذاكرة المؤقتة ومجمع المحوّلات -> تفعيل فوري في مدير الملفات!
            $configProvider->reset();
            $storageFactory->reset();
            $cacheManager->invalidateProfile($setting->getProfileName());

            $this->addFlash('success', 'تم حفظ إعدادات التخزين السحابي وتفعيلها بنجاح.');

            return $this->redirectToRoute('admin_cloud_storage_edit', ['id' => $setting->getId()]);
        }

        return $this->render('admin/cloud_storage/edit.html.twig', ['form' => $form]);
    }
}
```

### الخطوة 5: قائمة التحقق الأمني لإعدادات السحابة المُدارة من لوحة التحكم

- **تقييد صارم للصلاحيات:** احصر الوصول إلى وحدة التحكم هذه باستخدام `#[IsGranted('ROLE_SUPER_ADMIN')]`.
- **الحماية من هجمات SSRF في نقطة النهاية (`endpoint`):** تحقّق من صحة حقل `endpoint` باستخدام Symfony Validator (`#[Assert\Url]`) بحيث تُقبل عناوين `https://` فقط، وتُحظر عناوين IP الخاصة بالبيانات الوصفية الداخلية أو الاسترجاع المحلي (`169.254.169.254` و `127.0.0.1` وغيرها) إلا إذا كنت تسمح صراحةً بخادم MinIO محلي.
- **إخفاء المفتاح السري:** اضبط الحقل `plainSecret` في النموذج بخيارات `mapped => false` و `required => false` و `type="password"` بحيث يؤدي تركه فارغًا إلى الإبقاء على المفتاح المشفّر المخزّن مسبقًا.
- **النسخ الاحتياطي للمفتاح الرئيسي:** احتفظ بنسخة احتياطية آمنة من `CLOUD_ENCRYPTION_KEY` الموجود في `.env.local` داخل خزنة أسرار (Secrets Vault) — لأن تدويره أو فقدانه يجعل جميع الأسرار المشفّرة سابقًا في قاعدة البيانات غير قابلة للقراءة.

---

## 4. تخزين البيانات الوصفية للنشر متعدد الخوادم (Doctrine DBAL / ORM)

بشكل افتراضي، تُخزَّن ألوان المجلدات والأيقونات والعناصر المثبّتة وبيانات SEO الوصفية للملفات في `var/file-manager/metadata.json` (`driver: 'json_file'`). إذا كنت تشغّل عدة خوادم تطبيق خلف موازن أحمال (نسخ Docker/Kubernetes)، فانتقل إلى الواجهة الخلفية المدمجة `defaults.metadata.driver: 'doctrine'` — راجع **[13 — دليل التكامل مع Doctrine ORM و DBAL](13-doctrine-integration.md)** للحصول على الإعداد الكامل، وملفات الترحيل (Migrations) لـ PostgreSQL/MySQL، وأمثلة الكيانات.

---

[→ السابق: 06 — الأمان والصلاحيات](06-security-and-authorization.md) | [الفهرس الرئيسي](README.md) | [التالي: 08 — الأحداث، الأوامر، التخزين المؤقت واستكشاف الأخطاء ←](08-events-commands-cache-and-troubleshooting.md)
