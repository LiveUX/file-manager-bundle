# 06 — Güvenlik ve Yetkilendirme (RBAC & Savunma Katmanları)

[← Önceki: 05 — FormType & Editör](05-formtype-ve-editor-entegrasyonu.md) | [Ana İndeks](README.md) | [Sonraki: 07 — Özel Depolama (S3/R2) →](07-depolama-s3-ve-dinamik-yapilandirma.md)

Dosya yöneticileri web uygulamalarının en kritik saldırı yüzeyidir. LiveUX FileManagerBundle, **savunma derinliği (defense-in-depth)** prensibiyle çok katmanlı güvenlik korumalarını varsayılan olarak aktif sunar.

> **En Basit Haliyle: Güvenliği 3 Adımda Nasıl Kapatırım?**  
> 1. **Rota koruması (`security.yaml`):** Giriş yapmamış kişilerin dosya yöneticisi URL'lerine ulaşmasını engelleyin.  
> 2. **Rol izinleri (`permissions`):** Kimin sadece görebileceğini (`view`), kimin yükleyip (`upload`) kimin silebileceğini (`delete`) profilde açıkça yazın.  
> 3. **Çoklu Firewall varsa `prefix` ekleyin:** Projenizde admin paneli (`/panel`) ve site üyeleri (`/`) için iki ayrı firewall varsa, dosya yöneticisi rotalarını `config/routes/liveux_file_manager.yaml` içinde `prefix: /panel` altına alın ki admin oturumu doğru tanınsın ve normal site üyeleri admin dosyalarına ulaşamasın.

---

## 1. Symfony `security.yaml` ile Rota Koruması (İlk Adım)

Canlı (production) ortamda `/_liveux-file-manager` altındaki tüm uç noktaları Symfony güvenlik duvarı ile korumaya alın:

```yaml
# config/packages/security.yaml (Tek firewall'lı projeler)
security:
    access_control:
        # İmzalı paylaşım linklerini kullanıyorsanız dış alıcılar için açık bırakın:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Admin paneli (`/panel`) ve normal site üyeleri (`/`) için 2 ayrı firewall'ınız varsa:**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /panel
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/panel/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/panel/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Profil Bazlı Yetkilendirme (`permissions` & `FileManagerVoter`)

Her profilde **17 ayrı işlem** için bağımsız kural tanımlayabilirsiniz.

### Yetki Değerleri Ne Anlama Gelir?
- **`true`:** **Yalnızca giriş yapmış (`$token->getUser() !== null`) kullanıcılar!** *(Anonim ziyaretçilere ASLA izin vermez.)*
- **`false`:** Hiç kimse (işlem tamamen kapalı).
- **`'ROLE_ADMIN'`:** Yalnızca bu role sahip kullanıcılar.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Bu rollerden herhangi birine sahip kullanıcılar.

### 4 Altın Yetkilendirme Kuralı (Arka Planda Otomatik Çalışır)
1. **`permissions.view` Temel Kapıdır:** Bir kullanıcının `view` yetkisi yoksa `upload`, `delete`, `download` veya başka hiçbir işleme izin verilmez.
2. **Fail-Closed (`FileManagerAuthorizer`):** `security.voter_enabled: true` iken projede `AuthorizationCheckerInterface` (yani `symfony/security-bundle`) yoksa sistem *"izin ver"* değil, **güvenli biçimde reddeder (`AccessDeniedException`)**.
3. **Ata Klasör (Ancestor) Zinciri Kontrolü (`isPathAndAncestorsGranted`):** Bir kullanıcı `gizli/2026/fatura.pdf` dosyasına erişmek istediğinde özel Voter'larınız yalnızca `gizli/2026/fatura.pdf` için değil, **`gizli`** ve **`gizli/2026`** üst klasörleri için de otomatik denetlenir — böylece gizli bir klasörün içindeki dosya URL tahmin edilerek aşılamaz.
4. **Çift Kilitli ZIP Çıkarma (`zip.allow_extract`):** `zip.allow_extract: false` olduğu sürece `permissions.extract_zip` ne olursa olsun arşiv çıkarma sunucuda reddedilir.

### 17 Voter Yetkisi Tablosu

| YAML Anahtarı | Voter Sabiti (`FileManagerPermissions::*`) | Koruduğu İşlem |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Dizin listeleme, klasör ağacı, thumbnail, inline önizleme, dialog ve **diğer tüm yetkilerin ön şartı** |
| `upload` | `FILE_MANAGER_UPLOAD` | Dosya yükleme, parçalı yükleme, URL import, görsel düzenleyici kaydetme |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Yeni klasör oluşturma |
| `rename` | `FILE_MANAGER_RENAME` | Dosya / klasör yeniden adlandırma |
| `clone` | `FILE_MANAGER_CLONE` | Dosya / klasör klonlama |
| `move` | `FILE_MANAGER_MOVE` | Taşıma (sürükle-bırak & Taşı modalı — hem kaynak hem hedef yol denetlenir) |
| `copy` | `FILE_MANAGER_COPY` | Kopyalama (hem kaynak hem hedef yol denetlenir) |
| `delete` | `FILE_MANAGER_DELETE` | Silme (çöpe atma / kalıcı silme) ve `checkUsage` kullanım kontrolü |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Çöp kutusundan geri yükleme |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Çöp kutusundan kalıcı silme / boşaltma |
| `download` | `FILE_MANAGER_DOWNLOAD` | Tekil dosya indirme, PDF/video akışı & URL kopyalama |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Çoklu ZIP arşivi indirme (seçilen her yol için ayrı ayrı denetlenir) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Güvenli ZIP arşivi çıkarma (`zip.allow_extract: true` da gerekir) |
| `share` | `FILE_MANAGER_SHARE` | Süreli & HMAC imzalı paylaşım linki üretme (`share.enabled: true` da gerekir) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Dizin / profil / thumbnail önbelleği temizleme, `?refresh=1` ve `?fallback=1` önbellek atlama |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Klasör rengi/ikonu/sabitleme ve dosya alt/başlık/etiket/odak noktası güncelleme |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | Yapay zeka ile otomatik metadata önerme (`view` + `edit_metadata` + `ai.enabled: true` birlikte gerekir) |

### Örnek Kurumsal Profil Yapılandırması

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

> **Otomatik Arayüz Uyumlu:** Kullanıcının yetkisi olmayan butonlar (ör. `delete: 'ROLE_ADMIN'` iken `ROLE_EDITOR` için Sil butonu) hem arayüzde otomatik gizlenir hem de doğrudan API'ye istek atılsa dahi sunucuda `HTTP 403 Forbidden` döner.

---

## 3. Özel Symfony Voter Yazma (Klasör / Kullanıcı Bazlı İnce Kural)

YAML rollerinin ötesinde, örneğin *"kullanıcı `locked/` klasörünü silemesin veya `locked/` klasörüne dosya taşıyamasın"* gibi iş kuralları için kendi Voter'ınızı yazabilirsiniz.

> [!WARNING]
> **Yeni Başlayanların En Sık Düştüğü Tuzak: `affirmative` vs `unanimous`!**  
> Symfony'nin varsayılan `access_decision_manager.strategy` ayarı **`affirmative`** (tek bir Voter'ın `true` demesi yeterli) şeklindedir. Bu durumda bundle'ın yerleşik `FileManagerVoter` sınıfı `ROLE_ADMIN` kullanıcısı için `true` dediğinde, sizin özel Voter'ınızın döndürdüğü **`false` (red) kararı EZİLİR** ve kullanıcı yine de silme yapabilir!  
>  
> Özel Voter'larınızın **kısıtlama (deny)** kurallarının gerçekten geçerli olması için `config/packages/security.yaml` dosyanızda stratejiyi **`unanimous`** (tüm oy veren Voter'lar onaylamalı, tek bir `false` işlemi durdurur) yapın:
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(Bundle, projenizde harici özel bir Voter tanımlandığını `DetectExternalVotersPass` ile otomatik tespit eder ve performans/gizlilik için klasör ağacı + dosya sayacı filtrelemelerini her öğede Voter üzerinden geçirir.)*

### `FileManagerSubject` Özellikleri

Özel Voter'ınızın `voteOnAttribute()` metoduna gelen `$subject` nesnesi şu 4 salt-okunur özelliği taşır:

| Özellik | Tip | Açıklama | Örnek |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Aktif profil yapılandırması (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | İşlem yapılan kaynak dosya/klasörün volume köküne göreli yolu | `'locked/sozlesme.pdf'` |
| `$subject->volume` | `?string` | İşlem yapılan volume adı | `'local_uploads'` |
| `$subject->targetPath` | `?string` | `MOVE`, `COPY`, `RENAME` veya `CLONE` işlemlerinde **hedef yol** (diğer işlemlerde `null`) | `'locked/yeni-ad.pdf'` |

### Örnek Özel Voter (`locked/` Klasörünü Silme ve Hedef Taşımaya Kapatma)

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
        // 1) "locked" klasörünün kendisi veya altındaki dosyalar silinemez / taşınamaz / ad değiştirilemez
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Başka klasörden "locked" klasörünün içine taşıma / ad değiştirme de engellensin
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

## 4. Yerleşik 14 Savunma Katmanı (Hacker Perspektifiyle Sertleştirilmiş)

| # | Saldırı Vektörü | Bundle Koruması |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Tüm yazma/silme/yükleme/ZIP/görsel düzenleme `POST` istekleri herhangi bir girdi işlenmeden **önce** `X-LiveUX-FM-CSRF` token başlığını doğrular (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode & OS Hileleri** | `PathNormalizer`: `../`, `..\`, null byte `\0`, kontrol karakterleri, `C:\`, `phar://`/`php://` stream wrapper'ları, sondaki nokta/boşluk, sıfır genişlikli/bidi Unicode karakterleri, CJK nokta (`。`), NTFS ADS (`dosya.txt:stream`), Windows cihaz adları (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), maks. **32 derinlik**, **255 byte/segment**, **1024 UTF-8 karakter / 3072 byte** toplam yol sınırı. |
| 3 | **Çalıştırılabilir, Polyglot & Çift Uzantılı Dosyalar** | `FileSecurityGuard`: **69 engelli uzantı** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config` vb.), **47 engelli MIME türü**, çift uzantılar (`shell.php.jpg`) ve **magic-bytes polyglot** doğrulaması; yeniden adlandırmada (`allow_extension_change: true` olsa bile!) ve indirmelerde mutlak engellenir. |
| 4 | **Gizli & Çöp Klasörlerine Doğrudan Erişim** | `.trash`, `.env`, `.git`, `.htaccess` ve `exclude_patterns` altındaki yollara tüm uç noktalardan doğrudan erişim reddedilir; `ZipDownloadController` seçilen **her bir yol için ayrı ayrı** `DOWNLOAD_ZIP` Voter kontrolü yapar. |
| 5 | **SVG XSS, XXE & `<use>` DoS Bombaları** | `SvgSanitizer`: `<!DOCTYPE`/`<!ENTITY` reddi, **katı 55-etiketli izin listesi (allowlist)**, maks. **5 MB** dosya boyutu, tam CSS sözdizimi süzgeci (`@import`, `expression()`, `url(javascript:)` engelli), `<script>`/`<foreignObject>`/`on*`/`javascript:` temizliği ve `<use>` genişleme DoS sınırları (maks. `100` `<use>`, derinlik `4`, `1000` genişleyen düğüm, `5000` analiz adımı, `8` yinelenen ID). |
| 6 | **SSRF & DNS Rebinding (URL İçe Aktarma)** | `RemoteUrlImporter` varsayılan kapalıdır (`allow_remote_url_import: false`). Açıldığında `inet_pton()` ile loopback, özel ağlar, `0.0.0.0`, `100.64.0.0/10` CGNAT, bulut metadata (`169.254.169.254`), IPv4-mapped IPv6 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6, UTS-46 IDNA / tam-genişlik / sekizlik / onaltılık IP hileleri engellenir ve **`CURLOPT_RESOLVE` IP sabitleme** ile DNS rebinding saldırıları önlenir. |
| 7 | **Görsel Düzenleyici Polyglot RCE** | `ImageEditorController`: `overwrite` yalnızca mevcut güvenli raster (`jpg/jpeg/png/webp`) dosyalara izin verir; `upload_max_size`, profil `upload_allow`/`upload_deny`, Usage Guard ve gerçek `getimagesizefromstring()` doğrulaması zorunludur. |
| 8 | **Piksel Bombası (Decompression Bomb) & ImageTragick** | `GdThumbnailDriver` ve `ImagickThumbnailDriver`, görseli belleğe açmadan önce `getimagesizefromstring()` ile maks. `8192×8192` ve `40 MP` sınırını denetler; Imagick bellek/süre/thread limitleri zorlanır. |
| 9 | **Symlink Kaçışı & Kuşatma** | `ConfinedLocalFilesystemAdapter`: Yerel disklerde her okuma, yazma, taşıma, kopyalama ve silmede her yol segmenti için `is_link()` + `realpath()` kök sınırı kontrolü yapılır; symlink içeren alt ağaçların özyinelemeli silinmesi/taşınması reddedilir ve listelemede `SKIP_LINKS` uygulanır. |
| 10 | **Çok-Kiracılı (`{user_id}`) Çakışma Koruması** | `DynamicPathResolver`: E-posta, büyük/küçük harf, Unicode, rezerve adlar (`guest`, `user`) ve Windows cihaz adları için ayrık SHA-256 `<slug>--h-<16-hex>` klasör izolasyonu sağlar; iki kullanıcının aynı klasöre düşmesi imkansızdır. |
| 11 | **İndirme, Thumbnail & CDN Cache İzolasyonu** | `DownloadController`, `ThumbnailController`, `ZipDownloadController`: `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Content-Security-Policy: default-src 'none'; sandbox` başlıkları; özel/Voter/oturum/`{user_id}` bağlamlarında `Cache-Control: private` + `Vary: Cookie, Authorization`; `?refresh=1` ve `?fallback=1` önbellek atlama için `CLEAR_CACHE` zorunluluğu; HTML/JS/SVG/XML için zorunlu `attachment`. |
| 12 | **FormType POST & Twig Origin Koruması** | `FileManagerPickerType` sunucu tarafında kurcalanmış POST verilerini (XSS protokolleri, `../`, `.trash/`, 256 KB üstü JSON, >500 öğe, bozuk UTF-8, `max_files` aşımı, HPP dizileri) reddeder; `FileManagerTwigExtension` ve frontend `isSafeUrl()` harici/tehlikeli URL origin sahteciliğini engeller. |
| 13 | **Keyfi Tema/İkon Dizini & DOM XSS** | Runtime `dialog?theme=` yalnızca 9 dahili temayı, `dialog?iconSet=` yalnızca 6 dahili ikon setini kabul eder; `sanitizeHexColor()` renk enjeksiyonunu önler; chunk geçici dizinleri kullanıcı kimliğiyle izole edilir (`0750`). |
| 14 | **DoS, Log Enjeksiyonu & Cache İzolasyonu** | Toplu silme maks. 200 öğe, chunk maks. 2000 parça, özyinelemeli klasör kopyalama maks. 5000 öğe; `AuditLogListener` log-enjeksiyon (`\r`/`\n`/ANSI/kontrol karakterleri) temizliği yapar; `DirectoryCacheManager` asla ana uygulamanın `cache.app` havuzunu komple silmez (`clear()` çağrılmaz). |

---

## 5. Web Sunucusu Sertleştirme (Apache & Nginx)

Ekstra savunma katmanı olarak herkese açık `public/uploads` klasöründe PHP/script çalıştırmayı web sunucusu seviyesinde mutlaka kapatın:

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

## 6. İmzalı Paylaşım Linkleri, Denetim Günlüğü (Audit Log) ve Kullanım Koruması

- **İmzalı & Süreli Paylaşım (`ShareController`):** `share.enabled: true` iken Symfony `UriSigner` ile HMAC imzalı ve `expires` parametreli geçici URL'ler üretilir. Profilde `share.enabled: false` yapıldığı anda mevcut tüm linkler anında `403` ile reddedilir, süresi dolanlar `410 Gone` döner; tüm yanıtlar `Cache-Control: private, no-store` ve CSP sertleştirmesi taşır.
- **Dosya Kullanım Koruması (`AssetUsageCheckerInterface`):** Veritabanında aktif kullanılan dosyaların silinmesini, adının değiştirilmesini, taşınmasını veya üzerine yazılmasını önler (Bkz. [10 — Kullanım Koruması](10-kullanim-korumasi-usage-guard.md)).

---

## 7. Denetim Günlüğü (Audit Loglama) — Adım Adım Üretim Örneği

`AuditLogListener`, dosya yöneticisinde yapılan tüm değişiklikleri (yükleme, silme, taşıma, kopyalama, ad değiştirme, klonlama, klasör oluşturma/silme, çöp geri yükleme/kalıcı silme) **log-injection temizliği** (`\r`/`\n`/ANSI/BiDi kontrol karakterleri) uygulanmış yapısal PSR-3 bağlamıyla kaydeder.

### Adım 1: Bundle'da Audit Log'u Etkinleştirin (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Profil bazında açıp kapatabilirsiniz
        public_temp:
            security:
                audit_log: false # Geçici/herkese açık profilde kapatılabilir
```

### Adım 2: Monolog ile Ayrı Dosyaya (JSON Formatında) Yönlendirin (`config/packages/monolog.yaml`)

`AuditLogListener`, Symfony'nin varsayılan `logger` servisini kullanır. Tüm denetim kayıtlarını kendi ayrı döner log dosyanıza (`var/log/file_manager_audit.log`) ayırmak için `services.yaml` + `monolog.yaml` ikilisini kullanın:

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 90 günlük yasal saklama / rotasyon
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

### Üretilen Örnek JSON Log Kaydı

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
  "datetime": "2026-11-26T09:15:42.128412+03:00"
}
```

### Kaydedilen 9 Denetim Olayı (`action` & Ek Bağlam)

| Symfony Olayı | `action` Anahtarı | Ek Bağlam (`context`) Alanları |
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

### Adım 3 (İsteğe Bağlı): Doctrine Veritabanı Audit Tablosuna Kaydetme

Admin panelinizde aranabilir/filtrelenebilir bir **Veritabanı Denetim Geçmişi** (Audit Trail) göstermek isterseniz aynı Symfony olaylarını dinleyen bir `EventSubscriber` yazmanız yeterlidir:

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
> Yüksek trafikli sistemlerde veritabanı yazımının dosya yükleme/taşıma hızını etkilememesi için `DatabaseFileAuditSubscriber` içinde doğrudan `flush()` çağırmak yerine Symfony **Messenger** ile asenkron bir `RecordFileAuditMessage` kuyruğa atabilirsiniz.

---

[← Önceki: 05 — FormType & Editör](05-formtype-ve-editor-entegrasyonu.md) | [Ana İndeks](README.md) | [Sonraki: 07 — Özel Depolama (S3/R2) →](07-depolama-s3-ve-dinamik-yapilandirma.md)
