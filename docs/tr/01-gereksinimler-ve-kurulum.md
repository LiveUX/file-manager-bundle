# 01 — Gereksinimler, Bağımlılıklar ve Sıfırdan Kurulum

[← Ana İndeks](README.md) | [Sonraki: 02 — Yapılandırma ve Profiller →](02-yapilandirma-ve-profiller.md)

Bu rehber, daha önce hiç Symfony UX paketi kurmamış bir geliştiricinin bile sıfırdan çalışan bir dosya yöneticisi kurabilmesi için **her adımı tek tek, neden yaptığımızı açıklayarak** anlatır.

> **En Basit Haliyle: Bu Paket Ne İşe Yarar?**  
> Web uygulamanıza (örneğin bir yönetim paneline, e-ticaret sitesine veya müşteri portalına) kullanıcıların **dosya yükleyebileceği, klasör açabileceği, görsel kırpabileceği, PDF/video önizleyebileceği ve formlardan tek tıkla dosya seçebileceği** hazır bir dosya yöneticisi (Google Drive / Windows Gezgini benzeri ekran) ekler.  
> Hiçbir CSS framework'üne (Bootstrap, Tailwind vb.) veya jQuery'ye bağımlı değildir; AssetMapper ile Node.js/npm derlemesi bile gerektirmeden 5 dakikada çalışır.

---

## 1. Sistem Gereksinimleri

Kuruluma başlamadan önce bilgisayarınızda veya sunucunuzda aşağıdakilerin kurulu olduğundan emin olun:

| Bileşen | Minimum Sürüm | Neden Gerekli? | Kontrol Komutu |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` veya üzeri | Bundle, PHP 8.3/8.4 özelliklerini (`readonly` sınıflar, tipli sabitler) kullanır. | `php -v` |
| **Symfony** | `7.2+` veya `8.0+` | Symfony 7.2, 7.3, 7.4 ve 8.x sürümleri tam desteklenir. | `php bin/console --version` |
| **Composer** | `2.5+` | Paket kurulumu ve PHP sınıflarının otomatik yüklenmesi (autoload) için. | `composer --version` |
| **Tarayıcı** | Modern tarayıcılar | Native HTML5 `<dialog>` Top Layer, ES Modules ve CSS değişkenleri için (Chrome, Edge, Firefox, Safari). | — |

---

## 2. PHP Eklentileri (Extensions)

PHP eklentileri, PHP'nin dosya türü inceleme veya resim işleme gibi ek yetenekleridir.

### Zorunlu PHP Eklentileri
Modern PHP kurulumlarının neredeyse tamamında varsayılan olarak açıktır:

| Eklenti | Ne İşe Yarar? | Gerçek Hayat Örneği |
| :--- | :--- | :--- |
| `ext-fileinfo` | Yüklenen dosyanın sadece uzantısına değil **gerçek dosya içeriğine (magic bytes)** bakar. | Bir saldırgan `virus.php` dosyasının adını `foto.jpg` yapıp yüklemeye çalışırsa anında yakalayıp reddeder. |
| `ext-dom` & `ext-libxml` | Yüklenen `.svg` dosyalarının XML yapısını güvenli şekilde ayrıştırır (`SvgSanitizer`). | Bir logonun içine gizlenmiş `<script>` veya XXE zararlı kodlarını temizler; logoyu güvenle saklar. |

*(Not: `json` eklentisi PHP 8.0'dan beri PHP çekirdeğinin ayrılmaz parçasıdır; ayrıca kurmanıza gerek yoktur.)*

### Opsiyonel (Önerilen) PHP Eklentileri
Bu eklentiler yoksa dosya yöneticisi **çalışmaya devam eder**, yalnızca ilgili özellik otomatik olarak yedek (fallback) davranışa geçer:

| Eklenti | Ne İşe Yarar? | Kurulu Değilse Ne Olur? |
| :--- | :--- | :--- |
| `ext-gd` **veya** `ext-imagick` | Görseller için hızlı WebP/JPEG küçük önizlemeleri (thumbnail) ve yükleme anında otomatik optimizasyon üretir. | `thumbnail.on_missing_driver` ayarına göre orijinal görsel veya dosya ikonu gösterilir. |
| `ext-exif` | `ext-gd` kullanırken cep telefonu fotoğraflarının EXIF yön bilgisini okur ve yan dönmüş fotoğrafları otomatik düzeltir (`auto_orient: true`). | Fotoğraflar dönmeden orijinal yönüyle kaydedilir (`ext-imagick` varsa `ext-exif` gerekmez). |
| `ext-zip` (`ZipArchive`) | Birden fazla dosyayı tek tıkla `.zip` olarak indirmeyi **ve** güvenli arşiv çıkarmayı (`zip.allow_extract: true`) sağlar. | Arayüzde **ZIP İndir** ve **ZIP Çıkar** butonları otomatik gizlenir. |

Sunucunuzda hangi eklentilerin açık olduğunu tek komutla görebilirsiniz:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Zorunlu ve Opsiyonel Composer Paketleri

`composer require liveux/file-manager-bundle` çalıştırdığınızda **zorunlu paketlerin tamamı Composer tarafından otomatik kurulur**; tek tek elle kurmanız gerekmez. Yine de her paketin projede ne iş yaptığını bilmeniz hata ayıklamayı çok kolaylaştırır:

### Zorunlu Paketler (Otomatik Kurulur)
| Paket | Sürüm | Ne İşe Yarar? |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Yerel disk, AWS S3, Cloudflare R2, Supabase, MinIO veya SFTP fark etmeksizin tek API ile dosya yönetir. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Dosya yöneticisini `{{ component('LiveUX:FileManager:Browser') }}` şeklinde 0ms sunucu render (SSR) ile çizer. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Tarayıcı içi bellek önbelleği, sürükle-bırak, sağ tık menüsü, lightbox ve picker etkileşimlerini yönetir. |
| `symfony/framework-bundle` | `^7.2\|^8.0` | Symfony çekirdeği, routing ve önbellek havuzları (`cache.app`). |
| `symfony/config` & `symfony/dependency-injection` | `^7.2\|^8.0` | `liveux_file_manager.yaml` şema doğrulaması ve servis kayıtları. |
| `symfony/event-dispatcher` | `^7.2\|^8.0` | `FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent` gibi 9 domain olayını yayınlar. |
| `symfony/http-foundation` | `^7.2\|^8.0` | Güvenli dosya indirme, HTTP 206 video stream (`Range`), ETag ve HTTP önbellek başlıkları. |
| `symfony/twig-bundle` | `^7.2\|^8.0` | Twig şablonları, ikon/çeviri Twig yardımcıları ve otomatik FormType teması. |
| `symfony/form` | `^7.2\|^8.0` | Tekli dosya ve çoklu galeri seçimi yapan `FileManagerPickerType` form alanı. |
| `symfony/mime` | `^7.2\|^8.0` | Yüklenen dosyaların gerçek MIME türünü tespit eder. |
| `symfony/string` | `^7.2\|^8.0` | Çok dilli (Türkçe, Fransızca, Arapça, Kiril vb.) dosya adlarını temiz SEO slug'larına dönüştürür. |
| `symfony/security-core` & `symfony/security-csrf` | `^7.2\|^8.0` | 17 yetkili Voter RBAC ve `X-LiveUX-FM-CSRF` koruma katmanı. |
| `symfony/cache-contracts` & `symfony/translation-contracts` | `^3.0` | Dizin önbelleği ve çoklu dil arayüz sözleşmeleri. |

### Opsiyonel Paketler (Yalnızca İlgili Özelliği Kullanacaksanız Kurun)
| Paket | Kurulum Komutu | Ne Zaman Gerekli? |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Node.js / npm build aracı kullanmadan JS ve CSS'i anında yayınlamak için *(Önerilen)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | `ROLE_USER` / `ROLE_ADMIN` tabanlı rol yetkileri ve `{user_id}` kişisel klasörleri için. |
| `symfony/translation` | `composer require symfony/translation` | Symfony çeviri servisiyle entegre çalışmak veya projeye yeni diller (Almanca, Arapça vb.) eklemek için. |
| `symfony/console` & `symfony/yaml` | `composer require symfony/console symfony/yaml` | `file-manager:doctor`, `thumbs:warmup`, `trash:purge` gibi CLI komutları ve YAML ayar dosyası için. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO veya Google Cloud Storage kullanacaksanız. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Çok sunuculu (load-balanced) projelerde `defaults.metadata.driver: 'doctrine'` DBAL deposu için (Bkz. [Bölüm 13](13-doctrine-entegrasyonu.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | EasyAdmin 4/5 panellerinde hazır `FileManagerField` alanını kullanmak için (Bkz. [Bölüm 05](05-formtype-ve-editor-entegrasyonu.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ AI ile Öner** özelliğiyle görsellerden otomatik `alt` metin ve etiket üretmek için (Bkz. [Bölüm 09](09-symfony-ai-entegrasyonu.md)). |

---

## 4. Adım Adım Sıfırdan Kurulum

Aşağıdaki 7 adımı sırasıyla uyguladığınızda çalışan bir dosya yöneticiniz hazır olacak.

### Adım 1: Paketi Projenize Ekleyin

**Normal kurulum (Packagist üzerinden):**
```bash
composer require liveux/file-manager-bundle
```

**Eğer paketi projenizin içindeki yerel bir klasörden (`bundles/liveux-file-manager-bundle`) geliştiriyorsanız:**  
Proje kökündeki `composer.json` dosyasına şu `repositories` bloğunu ekleyin:

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

Ardından paketi yerel symlink ile kurun:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Adım 2: `config/bundles.php` Dosyasını Kontrol Edin

Symfony Flex çoğu zaman bu adımı kendisi yapar. Yapmadıysa `config/bundles.php` dosyasında aşağıdaki bundle'ların tanımlı olduğundan emin olun:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Rol/login kullanıyorsanız
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> **Bilgi:** `FileManagerBundle` açılışta kendi Twig namespace'ini (`@LiveUXFileManager`), AssetMapper yolunu (`@liveux/file-manager-bundle`) ve FormType temasını (`@LiveUXFileManager/form/picker.html.twig`) **otomatik kaydeder**. `twig.yaml` veya `asset_mapper.yaml` dosyasına manuel satır eklemeniz gerekmez.

---

### Adım 3: Route (Rota) Dosyasını Oluşturun

Dosya yöneticisinin AJAX API'leri, indirme, thumbnail ve yükleme uç noktalarının çalışması için `config/routes/liveux_file_manager.yaml` dosyasını oluşturun:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Bu tek satır şu **12 güvenli uç noktayı** otomatik kaydeder:

| HTTP & Yol | Ne İşe Yarar? |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Modal / OffCanvas açıldığında dosya yöneticisi HTML içeriğini getirir. |
| `GET /_liveux-file-manager/api/list` | Bir klasördeki dosya ve alt klasörleri JSON olarak listeler. |
| `GET /_liveux-file-manager/api/tree` | Sol menüdeki tam klasör ağacını tek seferde getirir. |
| `GET /_liveux-file-manager/api/trash` | Çöp kutusundaki silinmiş öğeleri listeler. |
| `GET /_liveux-file-manager/api/preview-text` | `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql` gibi metin dosyalarının güvenli (64 KB sınırlı) önizlemesini döner. |
| `POST /_liveux-file-manager/api/action` | Klasör oluşturma, taşıma, kopyalama, ad değiştirme, silme, çöp geri yükleme, renk/ikon, metadata, AI öneri, URL import ve ZIP çıkarma aksiyonlarını çalıştırır. |
| `POST /_liveux-file-manager/upload` | Parçalı (chunked) ve standart dosya yüklemelerini kabul eder. |
| `POST /_liveux-file-manager/image-edit` | Dahili Canvas Görsel Düzenleyici'nin kırptığı/döndürdüğü görseli kaydeder. |
| `GET /_liveux-file-manager/download` | Güvenli dosya indirme, PDF/Video önizleme (`Range` destekli) ve `private: true` disk sunumu. |
| `GET /_liveux-file-manager/share` | HMAC imzalı ve süreli paylaşım bağlantılarını sunar. |
| `POST /_liveux-file-manager/zip` | Seçilen birden fazla dosya/klasörü tek `.zip` arşivi olarak indirir. |
| `GET /_liveux-file-manager/thumb` | ETag & akıllı HTTP önbellek başlıklı WebP/JPEG/PNG küçük resim (thumbnail) sunar. |

---

### Adım 4: Yapılandırma Dosyasını (`liveux_file_manager.yaml`) Oluşturun

`config/packages/liveux_file_manager.yaml` dosyasını oluşturun. Aşağıdaki başlangıç örneğinde **tüm 17 yetkinin tamamı** açıkça tanımlanmıştır:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = Symfony'nin aktif dilini otomatik devralır (çeviri yoksa garantili olarak 'en' diline düşer)
                            # İsterseniz 'tr', 'en', 'fr' veya 'zh_Hans_CN' gibi sabit bir dil verebilirsiniz

    volumes:
        local_uploads:
            label: 'Dosyalarım'
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
                extract_zip: false        # ZIP çıkarma varsayılan olarak kapalıdır (ayrıca zip.allow_extract: true gerekir)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Çok Önemli Güvenlik Notu (`permissions`):**  
> `permissions` altında **yazmadığınız** bir anahtar (`extract_zip` hariç) varsayılan olarak `true` kalır. `true` değeri *"anonim herkes"* değil, **"giriş yapmış herhangi bir kullanıcı"** anlamına gelir. Eğer yalnızca `ROLE_ADMIN` kullanıcılarının çöpü kalıcı silebilmesini (`trash_purge`) veya paylaşım linki (`share`) oluşturabilmesini istiyorsanız, o anahtarı mutlaka `'ROLE_ADMIN'` olarak açıkça yazın.

> [!TIP]
> **Henüz giriş (login) sisteminiz yoksa geliştirme ortamında hızlı deneme:**  
> Yerel makinenizde login ekranı olmadan denemek isterseniz geçici olarak `profiles.default.security.voter_enabled: false` yapabilirsiniz. **Canlı (production) ortamda `voter_enabled: false` ASLA bırakılmamalıdır** — bkz. [06 — Güvenlik ve Yetkilendirme](06-guvenlik-ve-yetkilendirme.md).

> **Çok Dilli ve Global Projeler İçin `defaults.locale: null`:**  
> `locale: null` bıraktığınızda dosya yöneticisi Symfony'nin geçerli istek dilini (`tr`, `en`, `fr`, `ar`, `de_DE.UTF-8`, `zh-Hans-CN` vb.) otomatik algılar, Arapça/Farsça/İbranice/Urduca gibi sağdan sola yazılan dillerde arayüzü otomatik **RTL (`dir="rtl"`)** düzenine geçirir ve çevirisi henüz olmayan dillerde hatasız olarak **İngilizce (`en`)** diline düşer.

---

### Adım 5: CSS ve JavaScript Dosyalarını Bağlayın (Sıfır Konfigürasyon!)

**Projemde AssetMapper mı yoksa Webpack Encore mu var, nasıl anlarım?**  
Projenizin ana dizinine bakın:
- `importmap.php` dosyanız varsa → **Yöntem A (AssetMapper)** kullanıyorsunuz.
- `webpack.config.js` dosyanız varsa → **Yöntem B (Webpack Encore)** kullanıyorsunuz.

> [!TIP]
> **Sıfır Konfigürasyon (`autoimport`) Desteği:**  
> `LiveUX FileManagerBundle`'ın kendi `assets/package.json` tanımı içinde **`autoimport`** ile `@liveux/file-manager-bundle/styles/core.css` ve eager `dialog_controller.js` (içinden `file_manager_api.js` otomatik yüklenir) yerleşik olarak tanımlıdır. Symfony Flex veya `assets/controllers.json` aktif olduğunda **`assets/app.js` dosyasına tek bir manuel `import` satırı bile yazmanız gerekmez!**

#### Yöntem A: Symfony AssetMapper (Önerilen — Node.js / npm Gerekmez)

1. `assets/controllers.json` dosyasında paketin controller'larının etkin olduğunu doğrulayın (Symfony Flex bunu kurulumda otomatik ekler; yoksa aşağıdakini ekleyin):
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

2. Symfony 7.2+ / 8.x projelerinde `assets/bootstrap.js` veya `assets/stimulus_bootstrap.js` içinde `startStimulusApp()` çağrısı zaten mevcuttur ve `controllers.json`'ı otomatik okur:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) veya assets/bootstrap.js (Symfony 7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Ana şablonunuzda (`templates/base.html.twig`) `importmap('app')` çağrısının bulunduğundan emin olun:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Opsiyonel — Canlıya alırken)* Statik dosyaları derleyin:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Otomatik Sıkıştırılmış (Minified) Üretim Varlıkları (`assets/dist/`):**  
> AssetMapper varsayılan olarak JS/CSS minify yapmadığı için `LiveUX FileManagerBundle`, tüm JavaScript modüllerini ve `core.css` dosyasını `esbuild` ile önceden küçültülmüş halde (`assets/dist/`) dağıtır (`747 KB → 410 KB` minify; sunucu Gzip ile `~93 KB`, Brotli ile toplam **`~77 KB`**). `FileManagerBundle` otomatik olarak `assets/dist/` dizinini AssetMapper'a bağladığı için bilgisayarınızda veya sunucunuzda **Node.js / npm kurulu olmasına gerek kalmaz**.  
> Symfony 7.3+ / 8.x kullanıyorsanız `config/packages/asset_mapper.yaml` içinde `precompress: true` açarak `asset-map:compile` sırasında otomatik `.br` ve `.gz` dosyaları da üretebilirsiniz.

#### Yöntem B: Webpack Encore

1. Symfony 8 / StimulusBundle 3.x + Webpack Encore projelerinde `@symfony/stimulus-bridge` paketinin yüklü olduğundan emin olun:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. `assets/controllers.json` dosyasında paketin 3 Stimulus controller'ı ve `autoimport` bloğunun etkin olduğunu doğrulayın:
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

3. `assets/bootstrap.js` içinde `startStimulusApp` bağlantısının bulunduğunu doğrulayın (`assets/app.js` bu dosyayı import eder; ayrıca manuel CSS/JS importu gerekmez):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Asset'leri derleyin:
```bash
npm run build
# Geliştirme sırasında otomatik takip için: npm run watch
```

---

### Adım 6: `DialogHost` (Otomatik Enjeksiyon — Ekstra Kod Gerekmez!)

Modal, OffCanvas, `FileManagerPickerType` ve TinyMCE/CKEditor/Tiptap pencerelerinin herhangi bir sayfadan tek satırla açılabilmesi için HTML içinde bir `<dialog>` taşıyıcısı gerekir.

Bundle içindeki `AutoInjectDialogHostSubscriber`, bu taşıyıcıyı tüm tam HTML sayfalarının `</body>` kapanış etiketinden hemen önce **otomatik olarak** ekler (`defaults.auto_inject_dialog_host: true`). Yani `base.html.twig` dosyanıza dokunmanız gerekmez.

- **Akıllı Koruma:** JSON, AJAX, Turbo Frame, yönlendirme ve dosya indirme yanıtlarına asla dokunmaz; sayfada zaten manuel eklenmiş bir `DialogHost` varsa ikinci kez eklemez.
- **0ms Açılış Maliyeti:** Sayfa ilk yüklenirken dosya listesi veya klasör ağacı sorgulamaz; yalnızca kullanıcı pencereyi açtığında yüklenir.
- **Sadece Belirli Sayfalarda Manuel Eklemek İsterseniz:** `defaults.auto_inject_dialog_host: false` yapın ve istediğiniz layout'un `</body>` etiketinin üstüne şunu ekleyin:

```twig
{# Opsiyonel — yalnızca auto_inject_dialog_host: false yaptığınızda gerekir #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Adım 7: Kurulumu Doğrulayın (`file-manager:doctor`)

Önbelleği temizleyin ve yerleşik sistem tanılama komutunu çalıştırın:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Bulut (S3/R2/Supabase/MinIO) bağlantınız da varsa canlı bağlantı testi için `--check-remote` ekleyin:
```bash
php bin/console file-manager:doctor --check-remote
```

Örnek sağlıklı çıktı (9 sistem kontrolü + profil/disk tablosu):
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

> **Windows Kullanıcıları İçin İpucu (`cURL error 60`):** `cURL CA Bundle` satırı uyarı veriyorsa `php.ini` dosyanızda `curl.cainfo` ve `openssl.cafile` ayarlarına güncel `cacert.pem` dosyasının yolunu verin veya S3 `driver_options.ca_bundle` parametresini tanımlayın.
>
> **Linux Sunucu İzinleri:** `public/uploads`, `public/media-cache` ve `var/file-manager` klasörlerinin web sunucusu kullanıcısı (`www-data`, `nginx` vb.) tarafından yazılabilir olduğundan emin olun.
>
> **Yapay Zeka Kod Asistanı Kullanıyorsanız (Antigravity, Claude Code, Cursor, Copilot):**  
> Tek komutla AI asistanınıza bundle'ın tüm kurallarını ve örneklerini öğretebilirsiniz (Bkz. [Bölüm 12](12-yapay-zeka-skills-rehberi.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. İlk Çalışan Sayfanızı Oluşturun

Kurulumun çalıştığını görmek için basit bir Symfony Controller ve Twig şablonu oluşturalım.

### 1) Controller (`src/Controller/MediaController.php`)
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
    #[Route('/admin/medya', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Twig Şablonu (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Medya Kütüphanesi</h1>

    {# 1. Yöntem: Sayfa içi (Inline) Dosya Yöneticisi #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# 2. Yöntem: Herhangi bir butondan Modal açıp seçilen dosyayı yakalama #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="dosyaSec()">Modal'dan Dosya Seç</button>
        <p id="secim-sonucu"></p>
    </div>

    <script>
        async function dosyaSec() {
            const dosyalar = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (dosyalar.length === 0) {
                return; // Kullanıcı pencereyi seçim yapmadan kapattı
            }

            document.getElementById('secim-sonucu').textContent =
                'Seçilenler: ' + dosyalar.map((d) => d.url).join(', ');
        }
    </script>
{% endblock %}
```

Tarayıcınızda `/admin/medya` adresini açın. Tebrikler — dosya yöneticiniz artık çalışıyor!

---

## 6. Sırada Ne Var?

- Kendi profilinizi, boyut limitlerinizi ve görsel kurallarınızı tanımlamak için → **[02 — Yapılandırma ve Profiller](02-yapilandirma-ve-profiller.md)**
- Symfony Form'a görsel/galeri seçici eklemek için → **[05 — FormType ve Editör Entegrasyonu](05-formtype-ve-editor-entegrasyonu.md)**
- Canlıya çıkmadan önce güvenliği kapatmak için → **[06 — Güvenlik ve Yetkilendirme](06-guvenlik-ve-yetkilendirme.md)**

---

[← Ana İndeks](README.md) | [Sonraki: 02 — Yapılandırma ve Profiller →](02-yapilandirma-ve-profiller.md)
