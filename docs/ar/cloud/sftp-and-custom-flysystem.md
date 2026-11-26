# خوادم SFTP و Azure Blob وخدمات Flysystem المخصصة (`FlysystemService`)

[← 07 — التخزين السحابي والإعداد الديناميكي](../07-cloud-storage-and-dynamic-config.md) | [الفهرس الرئيسي](../README.md)

> **متى يُفضَّل اختيار هذا النهج؟**  
> استخدم `driver: 'FlysystemService'` متى احتجت إلى الاتصال بخدمة تخزين لا تعتمد على S3 — مثل **خادم SFTP/FTP** أو **Azure Blob Storage** أو **WebDAV** — أو لإعادة استخدام نظام ملفات Flysystem مُعدّ مسبقًا في مشروعك عبر `league/flysystem-bundle`.

---

## 1. الاتصال بخادم SFTP (`league/flysystem-sftp-v3`)

### الخطوة 1: تثبيت محوّل SFTP

```bash
composer require league/flysystem-sftp-v3
```

### الخطوة 2: إضافة متغيرات الاتصال إلى `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### الخطوة 3: تعريف خدمة Symfony (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # اتركه null وحدّد $privateKey عند المصادقة بمفتاح SSH
            $privateKey: null                 # مثال: '%kernel.project_dir%/config/ssh/id_ed25519'
            $passphrase: null
            $port: '%env(int:SFTP_PORT)%'
            $useAgent: false
            $timeout: 10
            $maxTries: 3

    app.sftp.adapter:
        class: League\Flysystem\PhpseclibV3\SftpAdapter
        arguments:
            $connectionProvider: '@app.sftp.connection_provider'
            $root: '%env(SFTP_ROOT)%'

    app.sftp.storage:
        class: League\Flysystem\Filesystem
        # ملاحظة: لا حاجة إلى public: true! يقوم FlysystemServiceLocatorPass بتسجيل هذه الخدمة في Service Locator آمن تلقائيًا.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### الخطوة 4: تسجيل وحدة التخزين في `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'خادم أرشيف SFTP'
            icon: 'sftp'              # أيقونة SFTP مدمجة بصيغة SVG
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # تُبثّ الملفات بأمان عبر DownloadController و RBAC Voter
            cache_ttl: 30             # مدة تخزين مؤقت قصيرة لأن محتوى SFTP قد يتغير خارجيًا
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **هل تستخدم مزوّد إعدادات ديناميكي (`ConfigProviderInterface`)؟** إذا كنت تُعيد معرّف الخدمة ديناميكيًا من قاعدة البيانات بدلاً من التصريح به في YAML، فما عليك سوى إضافة الوسم `tags: ['liveux_file_manager.storage']` (أو `flysystem.storage`) إلى خدمة Flysystem لديك — وهنا أيضًا لا حاجة إلى `public: true`.

---

## 2. إعادة استخدام خدمات `league/flysystem-bundle` الحالية (Azure Blob و WebDAV وغيرها)

إذا كان مشروعك يستخدم بالفعل `league/flysystem-bundle` (مثل `azure.storage` أو `webdav.storage`)، فكل ما عليك هو توجيه `driver_options.service` إلى معرّف الخدمة الحالي:

```yaml
liveux_file_manager:
    volumes:
        azure_blobs:
            label: 'Azure Blob Storage'
            icon: 'cloud'
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: 'https://myaccount.blob.core.windows.net/media'
            private: false
            cache_ttl: 120
            driver_options:
                service: 'azure.storage'  # أي خدمة تُنفّذ الواجهة League\Flysystem\FilesystemOperator
```

---

## 3. المجلدات المحذوفة خارجيًا وخيار `remember_last_dir`

افترض أن مستخدمًا كان يعمل آخر مرة داخل المجلد `/projects/2026/draft` على خادم SFTP أو حاوية سحابية، ثم قام شخص آخر بحذف ذلك المجلد خارجيًا عبر عميل FTP/SFTP:
- عند إعادة تحميل الصفحة، لن يتعطّل مدير الملفات — بل يعود تلقائيًا إلى أقرب مجلد أب موجود (`/projects/2026`، أو جذر وحدة التخزين).
- النقر على زر **تحديث (`↻`)** في شريط الأدوات يتجاوز دائمًا ذاكرة التخزين المؤقت عبر `?refresh=1` لجلب قائمة حيّة مباشرة من SFTP أو التخزين السحابي.

---

## 4. التحقق من الاتصال المباشر

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← السابق: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — فهرس التخزين السحابي](../07-cloud-storage-and-dynamic-config.md)
