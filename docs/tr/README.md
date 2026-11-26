<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Symfony UX Dosya Yöneticisi ve DAM" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — Türkçe Dökümantasyon

**Dil / Language:** [English](../en/README.md) · **Türkçe** · [Français](../fr/README.md) · [Deutsch](../de/README.md) · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · [日本語](../ja/README.md) · [简体中文](../zh_CN/README.md) · [Ana README](../../README.md)

**LiveUX FileManagerBundle**, Symfony 6.4 LTS, Symfony 7.2+ ve Symfony 8+ projeleri için geliştirilmiş; **sıfır CSS framework bağımlılığına** sahip, yüksek performanslı, kurumsal güvenlikli, **12 yerleşik UI dilli** (`en`, `tr`, `fr`, `de`, `es`, `ar` RTL, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) ve modern bir **Symfony UX Dosya Yöneticisi & Dijital Varlık Yönetimi (DAM)** paketidir.

---

## Canlı Önizleme & Ekran Görüntüleri

<p align="center">
  <img src="../images/demo-showcase.gif" alt="LiveUX FileManagerBundle İnteraktif Demo" width="100%">
</p>

> YouTube ve Release paylaşımları için **10 bölümlü Full HD (1080p, ~3 dk) tam tanıtım videosu:** [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · Kısa önizleme: [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| Açık Mod (`Light`) | Koyu Mod (`Dark`) |
| :---: | :---: |
| ![Açık Mod Dosya Yöneticisi](../images/browser-light.png) | ![Koyu Mod Dosya Yöneticisi](../images/browser-dark.png) |

| Klasör Renk & İkon Özelleştirme | Hakkında Penceresi (`LiveUX`) |
| :---: | :---: |
| ![Klasör Renklendirme ve Rozet Seçimi](../images/folder-customize.png) | ![Hakkında Modalı](../images/about-modal.png) |

### Editör, EasyAdmin & Dinamik Form Entegrasyon Demoları (GIF)

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![TinyMCE Entegrasyonu](../images/demo-editor-tinymce.gif) | ![CKEditor 5 Entegrasyonu](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2 (ProseMirror) | Quill 2 |
| :---: | :---: |
| ![Tiptap 2 Entegrasyonu](../images/demo-editor-tiptap.gif) | ![Quill 2 Entegrasyonu](../images/demo-editor-quill.gif) |

| Trix (EasyAdmin `TextEditorField`) | Summernote |
| :---: | :---: |
| ![Trix Entegrasyonu](../images/demo-editor-trix.gif) | ![Summernote Entegrasyonu](../images/demo-editor-summernote.gif) |

| Editor.js (Blok Editörü) | GrapesJS (Görsel Sayfa Oluşturucu) |
| :---: | :---: |
| ![Editor.js Entegrasyonu](../images/demo-editor-editorjs.gif) | ![GrapesJS Entegrasyonu](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5 (`FileManagerField`) |
| :---: | :---: |
| ![Meta Lexical Entegrasyonu](../images/demo-editor-lexical.gif) | ![EasyAdmin 5 Entegrasyonu](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` & `LiveCollectionType` |
| :---: |
| ![CollectionType ve LiveCollectionType](../images/demo-collections.gif) |

---

## Yeni Başlayanlar İçin 2 Dakikalık Yol Haritası

*"Nereden başlamalıyım?"* diyorsanız ihtiyacınıza göre doğrudan ilgili bölüme geçin:

- **İlk kez kuruyorum:** [01 — Kurulum](01-gereksinimler-ve-kurulum.md) → [02 — Yapılandırma](02-yapilandirma-ve-profiller.md)
- **Formuma görsel/galeri seçici ekleyeceğim:** [05 — FormType & Editörler](05-formtype-ve-editor-entegrasyonu.md)
- **Admin paneli güvenliğini kapatacağım:** [06 — Güvenlik ve Yetkilendirme](06-guvenlik-ve-yetkilendirme.md)
- **Supabase / AWS S3 / Cloudflare R2 bağlayacağım:** [07 — Bulut Depolama](07-depolama-s3-ve-dinamik-yapilandirma.md)
- **Veritabanı (Doctrine) ile kullanacağım:** [13 — Doctrine Entegrasyonu](13-doctrine-entegrasyonu.md)
- **Veritabanında kullanılan dosyanın silinmesini/taşınmasını engelleyeceğim:** [10 — Dosya Kullanım Koruması (Usage Guard)](10-kullanim-korumasi-usage-guard.md)

---

## İçindekiler (Dökümantasyon İndeksi)

| Bölüm | Başlık | İçerik Özeti |
| :---: | :--- | :--- |
| **01** | [Gereksinimler, Bağımlılıklar ve Kurulum](01-gereksinimler-ve-kurulum.md) | PHP 8.3+/Symfony 7.2–8.x gereksinimleri, PHP eklentileri (`ext-exif`, `ext-zip` dahil), Composer bağımlılıkları, AssetMapper & Encore kurulumu, `file-manager:doctor` ve ilk çalışan sayfa |
| **02** | [Yapılandırma ve Profiller (YAML Referansı)](02-yapilandirma-ve-profiller.md) | `defaults`, `volumes`, `profiles`, çakışmasız dinamik yollar (`{user_id}`, `{Y}/{m}`), dosya boyut/chunk limitleri, otomatik isimlendirme, görsel optimizasyon/kısıtlar, thumbnail, çöp kutusu, ZIP, 5 hazır reçete ve tam YAML şema referansı |
| **03** | [Kullanım Modları ve JavaScript API](03-kullanim-modlari-ve-js-api.md) | Inline `TwigComponent`, `<dialog>` Modal & 4 yönlü OffCanvas, `window.LiveUXFileManager.open()` tam parametre/dönüş referansı, özel DOM olayları, RTL/IME/WCAG 2.1 AA, sağ tık menüsü, görsel düzenleyici ve klavye kısayolları |
| **04** | [Temalar, İkon Setleri ve Özelleştirme](04-temalar-ikon-setleri-ve-ozellestirme.md) | 9 WCAG 2.1 AA hazır tema (Bootstrap 5, Tailwind, Shadcn, Flowbite 4, DaisyUI 5, Tabler, Preline, Material, Unstyled), otomatik/manuel koyu tema, 6 SVG ikon seti (49 UI + 14 rozet), Twig yardımcıları ve 3 seviyede özel tasarım |
| **05** | [FormType ve Editör Entegrasyonu](05-formtype-ve-editor-entegrasyonu.md) | `FileManagerPickerType` (tekli & çoklu erişilebilir galeri sıralama + POST güvenlik koruması), 5 hazır görünüm (`compact`, `card`, `dropzone`, `avatar`, `input`), `CollectionType` / `LiveCollectionType`, özel şablonlar, TinyMCE, CKEditor 5, Tiptap ve 16 metodlu EasyAdmin `FileManagerField` |
| **06** | [Güvenlik ve Yetkilendirme (RBAC)](06-guvenlik-ve-yetkilendirme.md) | 17 Voter yetkisi, `unanimous` özel Voter yazma, `security.yaml` firewall koruması, 14 savunma katmanı (CSRF, Path Traversal, 69 uzantı/47 MIME, 55-etiket `SvgSanitizer`, SSRF DNS rebinding koruması, `ConfinedLocalFilesystemAdapter` symlink kuşatması, HMAC Paylaşım, Audit Log) ve Apache/Nginx kuralları |
| **07** | [Özel Depolama (S3/R2/MinIO/Supabase) ve Dinamik Yapılandırma](07-depolama-s3-ve-dinamik-yapilandirma.md) | Çoklu disk/bulut mimarisi, sağlayıcı rehberleri ([Supabase](bulut/supabase-storage.md), [AWS S3](bulut/aws-s3.md), [Cloudflare R2](bulut/cloudflare-r2.md), [Backblaze B2](bulut/backblaze-b2.md), [MinIO/RustFS](bulut/minio-rustfs.md), [GCS](bulut/google-cloud-storage.md), [SFTP/Flysystem](bulut/sftp-ve-ozel-flysystem.md)), `FlysystemServiceLocatorPass`, `ConfigProviderInterface` ve özel metadata deposu |
| **08** | [Event'ler, CLI Komutları, Cache ve Sorun Giderme](08-olaylar-komutlar-cache-ve-sorun-giderme.md) | 9 Symfony Event'i, 10 konsol komutu (`doctor`, `stats`, `duplicates`, `ai:enrich`, `skills:install` vb.), 3 katmanlı önbellek & bağlam duyarlı HTTP 304/CDN izolasyonu, 493 anahtarlı global BCP-47/POSIX/RTL çeviri sistemi ve SSS |
| **09** | [Symfony AI Entegrasyonu & Akıllı Zenginleştirme](09-symfony-ai-entegrasyonu.md) | Sıfır zorunlu bağımlılıklı `AssetEnricherInterface`, `symfony/ai-bundle` köprüsü (OpenAI, Gemini, Claude, Ollama), **✨ AI ile Öner**, toplu `file-manager:ai:enrich` CLI, Messenger asenkron örnekleri ve 11-dilli LLM red / prompt-injection koruması |
| **10** | [Dosya Kullanım Koruması (Usage Guard)](10-kullanim-korumasi-usage-guard.md) | Opt-in `AssetUsageCheckerInterface` sözleşmesi, `warn` / `block` politikaları, **`delete`, `rename`, `move` ve `overwrite`** için çift katmanlı sunucu koruması (`enforce_server_side`) ve MySQL/PostgreSQL/SQLite uyumlu Doctrine örnekleri |
| **11** | [Katkı & Pull Request (PR) Rehberi](11-katki-ve-pr-rehberi.md) | Hiç bilmeyenler için adım adım açık kaynak PR akışı, `composer qa` kalite kapısı (PHPStan Level 8, 360+ PHPUnit, 37+ JS testi), 6 görevli GitHub Actions CI ve GitHub Branch Protection kurulumu |
| **12** | [Yapay Zeka Agent Skills Rehberi (`SKILL.md` & `AGENTS.md`)](12-yapay-zeka-skills-rehberi.md) | Antigravity, Claude Code, Cursor, GitHub Copilot, Codex ve Symfony AI Mate için 6 modüler `SKILL.md` beceri paketi, `file-manager:skills:install` CLI kurulumu ve CI doğrulaması |
| **13** | [Doctrine ORM & DBAL Entegrasyon Rehberi](13-doctrine-entegrasyonu.md) | Yerleşik `metadata.driver: 'doctrine'` (`DoctrineDbalMetadataStorage` — atomik UPSERT, PostgreSQL `text_pattern_ops` indeksi, toplu `saveMany()`), platform-uyumlu Migration, Entity + `FileManagerPickerType`, `ResetInterface` destekli özel ORM deposu ve `ItemRenamedEvent` DB senkronizasyonu |
| **14** | [Güvenlik Denetimi, Mantık Taraması ve Değişiklik Raporu](14-guvenlik-denetim-ve-degisiklik-raporu.md) *(Yalnızca Türkçe)* | 1–18. tur satır-satır güvenlik (hacker perspektifi), ayrık çok-kiracılı `{user_id}` izolasyonu, DI/Voter/Parser/SVG `<use>` adım bütçesi/CDN & origin spoofing sertleştirmesi, symlink kuşatması, PostgreSQL `text_pattern_ops` & atomik UPSERT, WCAG 2.1 AA 9-tema kontrast/odak yönetimi, mobil modal/offcanvas ve global çok-dilli denetim kaydı |

---

## Öne Çıkan Mimari Özellikler

- **0ms Hibrit Hız:** Hızlı SSR `TwigComponent` + tarayıcı içi bellek önbelleği (`dirCache`) + tek seferde yüklenen tam klasör ağacı. Filtreleme, arama, sıralama, sayfalama, Grid/Liste geçişi ve klasör ağacı açma/kapama ek AJAX isteği atmadan anında çalışır.
- **Sıfır CSS Framework Bağımlılığı & WCAG 2.1 AA:** Tüm arayüz izole `.liveux-fm-*` sınıfları ve CSS değişkenleriyle yazılmıştır. Projenizde **Bootstrap 5, Tailwind CSS, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** veya **hiç CSS framework'ü olmasa bile** çakışma olmadan ve ek purge ayarı gerektirmeden çalışır.
- **Native HTML5 `<dialog>` Top Layer & Mobil Uyumluluk:** Modal ve OffCanvas (`start`, `end`, `top`, `bottom`) pencereleri tarayıcının en üst katmanında açılır; mobilde tam ekran (`100dvh`) veya tutamaçlı native bottom-sheet düzenine otomatik geçer.
- **Kurumsal 14 Katmanlı Güvenlik:** Symfony `Voter` RBAC, CSRF doğrulama (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `FileSecurityGuard`, 55-etiketli `SvgSanitizer`, DNS-rebinding korumalı `RemoteUrlImporter`, `ConfinedLocalFilesystemAdapter` symlink kuşatması, piksel bombası koruması ve `CSP: sandbox` + `nosniff` başlıkları kutudan çıktığı gibi aktiftir.
- **Global Çok-Dilli Mimari:** 493 eşit çeviri anahtarı (`en` / `tr` / `fr`), BCP-47 ve POSIX `.UTF-8` locale desteği, garantili `'en'` fallback, 15 dilde otomatik RTL (`dir="rtl"`) ve CJK IME koruması.
- **Çok Sunuculu Hazır Altyapı:** Yerleşik `DoctrineDbalMetadataStorage` (PostgreSQL `text_pattern_ops`, MySQL/MariaDB/SQLite atomik UPSERT), Flysystem v3 (S3/R2/Supabase/B2/MinIO/GCS/SFTP) ve FrankenPHP/Messenger `ResetInterface` uyumu.

---

## 5 Dakikada Hızlı Başlangıç Kontrol Listesi

1. **Paketi kurun:** `composer require liveux/file-manager-bundle`
2. **Route'ları ekleyin:** `config/routes/liveux_file_manager.yaml`
3. **Profilleri tanımlayın:** `config/packages/liveux_file_manager.yaml`
4. **CSS & JS (Sıfır Konfigürasyon):** Symfony UX `autoimport` sayesinde `assets/controllers.json` üzerinden `core.css`, `file_manager_api.js` ve 3 Stimulus controller hem AssetMapper hem Webpack Encore'da otomatik yüklenir.
5. **Dialog taşıyıcı (Otomatik):** `AutoInjectDialogHostSubscriber` sayesinde `DialogHost` tüm tam HTML sayfaların `</body>` etiketinden hemen önce otomatik eklenir (`defaults.auto_inject_dialog_host: true`) — `base.html.twig` düzenlemeniz gerekmez.
6. **Sistemi doğrulayın:** `php bin/console file-manager:doctor`

> Hiç Symfony UX kurulumu yapmadıysanız doğrudan **[01 — Gereksinimler, Bağımlılıklar ve Kurulum](01-gereksinimler-ve-kurulum.md)** bölümünden adım adım başlayın.

---

**Organizasyon:** [LiveUX GitHub](https://github.com/LiveUX) · [Depo](https://github.com/LiveUX/file-manager-bundle)  
**Lisans:** MIT
