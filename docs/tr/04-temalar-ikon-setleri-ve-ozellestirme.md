# 04 — Temalar, Koyu Mod, Klasör Özelleştirme, İkon Setleri ve Özel Tasarım

[← Önceki: 03 — Kullanım Modları & JS API](03-kullanim-modlari-ve-js-api.md) | [Ana İndeks](README.md) | [Sonraki: 05 — FormType ve Editör Entegrasyonu →](05-formtype-ve-editor-entegrasyonu.md)

LiveUX FileManagerBundle **%100 sıfır CSS framework bağımlılığı** ile tasarlanmıştır. Projenizde **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** veya **hiç CSS framework'ü olmasa bile** ek bir yapılandırma (Tailwind `@source` / purge ayarı vb.) gerektirmeden çalışır.

> **En Basit Haliyle: Bu Bölümde Neler Öğreneceksiniz?**  
> 1. **9 Hazır Tema & Koyu Mod:** Tek satır YAML ile projenizin tasarımına ve dark mode durumuna uyum sağlama.  
> 2. **Klasör Renklendirme & Klasör İkonu (Rozet) Atama:** Klasörleri arayüzden veya PHP/DB üzerinden 18+ renge boyama ve 14 yerleşik + kendi özel SVG rozetlerinizi atama.  
> 3. **Kendi Özel İkon Setinizi Oluşturma:** 9 dosya ikonu + 49 arayüz (UI) ikonu + sınırsız klasör rozetiyle kendi ikon paketinizi adım adım yapma.  
> 4. **Kendi Özel Temanızı Oluşturma (3 Seviye):** Sadece CSS değişkenleriyle marka teması, Twig `{% extends %}` ile kısmi düzenleme veya %100 Headless sıfırdan yerleşim.

---

## 1. 9 Hazır Tema (Hangi Temayı Seçmelisiniz?)

Tema; `defaults.theme`, `profiles.<ad>.ui.theme`, TwigComponent `theme` parametresi veya `FileManagerPickerType` `theme` seçeneği ile belirlenir. **Tüm 9 tema hem açık hem koyu modda WCAG 2.1 AA kontrast standartlarına göre doğrulanmıştır.**

| Tema Anahtarı | Hangi Projede Seçmelisiniz? | Tasarım Özeti |
| :--- | :--- | :--- |
| `bootstrap5` *(varsayılan)* | Bootstrap 5 / klasik Symfony admin panelleri | Klasik mavi (`#0d6efd`), `10px / 8px / 6px` köşeler, temiz nötr kenarlıklar |
| `tailwind` | Tailwind CSS v3/v4 kullanan modern web uygulamaları | Indigo (`#6366f1`) aksan, yumuşak `14px / 12px / 8px` köşeler, Slate palet |
| `shadcn` | Minimalist, monokrom siyah/beyaz tasarım seven SaaS panelleri | Monokrom Zinc yüzeyler (`#18181b` / koyu modda `#fafafa`), keskin `8px / 6px` kenarlar |
| `flowbite` | Flowbite 4 + Tailwind CSS kullanan yönetim panelleri | Flowbite mavi (`#1a56db`) & Gray-50/800/900 yüzeyler, `8px` kontroller |
| `daisyui` | DaisyUI 5 kullanan projeler | Sayfada DaisyUI yüklüyse `--color-primary`, `--color-base-100/200/300`, `--radius-box` değişkenlerini **otomatik devralır** |
| `tabler` | Tabler.io veya EasyAdmin kurumsal panelleri | Kurumsal slate zemin, `#066fd1` mavi ve keskin `4px / 6px` köşeler |
| `preline` | Preline UI tabanlı uygulamalar | Saf nötr yüzeyler (`#fafafa` / `#171717`) ve parlak mavi (`#2563eb`) aksan |
| `material` | Google Material Design 3 kullanan projeler | Tonal mor yüzeyler (`#6750a4` / `#f3edf7`) ve tam yuvarlak pill butonlar |
| `unstyled` | Tasarımı sıfırdan kendi CSS'inizle giydirmek istediğinizde | Host uygulamanın fontunu ve şeffaf yüzeylerini devralır; açılır menü/lightbox'ta opak `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` kontrastı korur |

| Koyu Mod (`bootstrap5` + Dark) | DaisyUI 5 Tema Köprüsü (`daisyui`) |
| :---: | :---: |
| ![Koyu Mod Dosya Yöneticisi](../images/browser-dark.png) | ![DaisyUI 5 Tema Örneği](../images/showcase-daisyui.png) |

> **Önemli Güvenlik Kuralı (YAML vs Runtime `theme`):**  
> - `liveux_file_manager.yaml` içinde 9 dahili temayı, kendi özel CSS tema adınızı (`'kurumsal'`, `/^[a-zA-Z0-9_-]{1,40}$/`) veya özel Twig şablon yolunuzu (`'file_manager/ozel.html.twig'` veya `'@App/file_manager'`) tanımlayabilirsiniz.  
> - Tarayıcıdan / URL'den / `window.LiveUXFileManager.open({ theme: ... })` üzerinden gelen dinamik değerler güvenlik nedeniyle **yalnızca 9 dahili tema adını** kabul eder (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Koyu Tema (Dark Mode), RTL & Hareket Azaltma

Tüm 9 tema (ve `unstyled` dahil) **hem otomatik hem manuel Koyu Mod** destekler:

### A) Otomatik Host Algılama (`color_scheme: 'auto'`)
Sayfanızda (`<html>` veya `<body>` üzerinde) aşağıdakilerden biri aktifse dosya yöneticisi, modal `<dialog>` ve `FileManagerPickerType` form alanları anında koyu temaya geçer:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- `.dark` sınıfı *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / Özel tema)*

### B) YAML Üzerinden Varsayılan / Profil Bazlı Zorunlu Renk Modu
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (host'u izle) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Yalnızca bu profili koyu başlat
```

### C) Kullanıcı Tercihi (Ayarlar Menüsü `Oto / Açık / Koyu`)
Üst araç çubuğundaki **⚙ Ayarlar** menüsündeki **Renk Modu** anahtarıyla kullanıcı tek tıkla **Oto / ☀ Açık / 🌙 Koyu** arasında geçiş yapabilir; seçim tarayıcı `localStorage` (`liveux_fm_color_scheme`) içinde saklanır.

### D) Global RTL ve `prefers-reduced-motion`
- Arapça (`ar`), Farsça (`fa`), İbranice (`he`), Urduca (`ur`) dahil 15 RTL dilde `dir="rtl"` otomatik eklenir; tüm `core.css` boşlukları mantıksal CSS özellikleriyle (`inset-inline`, `padding-inline`, `border-inline-end`) yazılmıştır.
- İşletim sisteminde *"Hareketi Azalt"* (`prefers-reduced-motion: reduce`) açık olan kullanıcılarda animasyonlar otomatik sadeleştirilir.

---

## 3. Klasör Renklendirme ve Klasör İkonu (Rozet) Atama (Adım Adım)

![Klasör Renk Paleti ve Rozet Özelleştirme Penceresi](../images/folder-customize.png)

Her klasöre özel **renk**, **rozet ikonu** ve **sabitleme (⭐ Pin)** atayabilirsiniz. Bu özellikler `defaults.metadata.enabled: true` ve `permissions.edit_metadata` yetkisi açıkken çalışır.

### Yöntem 1: Arayüzden (Son Kullanıcı İçin)
1. Herhangi bir klasöre **sağ tıklayın → Özelleştir** (veya klasör seçiliyken **`I`** tuşuna basın, ya da sağ Inspector panelindeki **Özelleştir** butonuna tıklayın).
2. Açılan **Özelleştir** penceresinde canlı önizleme üzerinde şu 3 ayarı yapabilirsiniz:
   - **18 Hazır Renk Paleti:** Mavi (`#2563eb`), Gök (`#0284c7`), Camgöbeği (`#0891b2`), Turkuaz (`#0d9488`), Zümrüt (`#059669`), Yeşil (`#16a34a`), Limon (`#65a30d`), Kehribar (`#d97706`), Turuncu (`#ea580c`), Kırmızı (`#dc2626`), Gül (`#e11d48`), Pembe (`#db2777`), Fuşya (`#c026d3`), Mor (`#9333ea`), Menekşe (`#7c3aed`), İndigo (`#4f46e5`), Slate (`#475569`), Antrasit (`#1e293b`).
   - **Özel Renk Seçici + HEX Kodu:** Tarayıcının renk seçicisiyle veya doğrudan `#RRGGBB` kodu yazarak istediğiniz marka rengini verebilirsiniz. **Varsayılana Sıfırla** butonu rengi ve ikonu temizler.
   - **Klasör İkonu (Rozet) Izgarası:** **14 yerleşik rozet** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`) ve özel ikon setinizin `badges/` klasörüne eklediğiniz tüm özel SVG'ler burada otomatik listelenir.
3. **Kaydet** dediğinizde klasör hem Grid/Liste alanında hem sol klasör ağacında anında güncellenir.

### Yöntem 2: PHP Kodundan Programatik Olarak Atama (Örn. Yeni Müşteri Klasörü Oluştuğunda)
`FolderMetadataStorageInterface` ve `PathNormalizer::buildCanonicalKey()` kullanarak bir klasöre koddan otomatik renk, rozet ve sabitleme atayabilirsiniz (hem `json_file` hem `doctrine` sürücüsünde aynı kod çalışır!):

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // Canonical anahtar üret (ör. "local::faturalar/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Turkuaz (#RRGGBB)
            icon: 'lock',       // 14 yerleşik rozetten biri veya özel badges/fatura.svg -> 'fatura'
            pinned: true,       // Sol menüde ⭐ Sabitlenmiş klasörlere ekle
        ));
    }
}
```

### Yöntem 3: Doğrudan `metadata.json` (`driver: 'json_file'` Kullanıyorsanız)
```json
{
    "local::urunler":  { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::gorseller":{ "color": "#0d9488", "icon": "image", "pinned": false },
    "local::faturalar":{ "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. İkon Setleri ve Sıfırdan Kendi İkon Setinizi Oluşturma

Bundle'daki **tüm ikonlar** — dosya türleri, klasörler, üst araç çubuğu, sağ tık menüsü, bulut disk simgeleri, sağ inspector ve klasör rozetleri — tek bir merkezi `IconSetRegistry` sisteminden gelir. Dahili 6 set PSR-6 önbelleğinde 24 saat saklanır (`liveux_fm_iconset_*`).

### 6 Hazır İkon Seti (`profiles.<ad>.ui.icon_set`)
| İkon Seti | Görünüm Stili | Varsayılan Klasör Rengi (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(varsayılan)* | Düz, temiz vektör ikonlar | `#2563eb` |
| `windows11` | Windows 11 tarzı çift katmanlı sıcak sarı klasörler ve modern belge ikonları | `#f59e0b` |
| `macos` | macOS Finder tarzı yumuşak mavi klasörler | `#3b82f6` |
| `linux` | GNOME / Adwaita tarzı sade açık kaynak ikonları | `#3584e4` |
| `minimal` | İnce çizgili (outline / stroke) minimalist ikonlar | `#475569` |
| `vscode` | VS Code / geliştirici odaklı keskin ikonlar | `#dcb67a` |

### Adım Adım Kendi Özel İkon Setinizi Oluşturma

#### Adım 1: Proje İçinde Bir İkon Klasörü Oluşturun
Örneğin `assets/fm-icons/` klasörünü açın. **Değiştirmek istemediğiniz ikonları kopyalamanız gerekmez** — klasörde bulunmayan tüm ikonlar otomatik olarak yerleşik `classic` / `_ui` / `_folder_badges` setlerinden tamamlanır!

```text
assets/fm-icons/
├── meta.json               # Varsayılan klasör rengi: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Opsiyonel) Özel klasör ana ikonu
├── pdf.svg                 # (Opsiyonel) Sadece PDF ikonunu değiştirmek isterseniz
├── ui/                     # (Opsiyonel) Üst bar, sağ tık ve disk ikonlarını ezmek veya eklemek için
│   ├── upload.svg          # Yükle ikonunu değiştir
│   ├── new_folder.svg      # Yeni Klasör ikonunu değiştir
│   └── sirket_bulut.svg    # Yeni özel volume ikonu ekle -> volumes.<ad>.icon: 'sirket_bulut'
└── badges/                 # (Opsiyonel) Klasör rozetlerini ezmek veya YENİ rozet eklemek için
    ├── lock.svg            # Mevcut "lock" rozetini değiştir
    └── fatura.svg          # YENİ rozet ekle -> Klasör Özelleştir modalında otomatik görünür!
```

#### Adım 2: `meta.json` ve Dinamik Renk Destekli `folder.svg` Yazın

> **Altın Kural (`currentColor`):** Kullanıcı bir klasörün rengini değiştirdiğinde kendi `folder.svg` ikonunuzun da otomatik o renge boyanması için SVG içinde sabit hex renk yerine **`fill="currentColor"`** (veya çizgili ikonlarda `stroke="currentColor"`) kullanın!

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/fatura.svg (Yeni özel klasör rozeti) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Adım 3: YAML Profilinde Klasör Yolunu Tanımlayın
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sirket_disk:
            label: 'Şirket Arşivi'
            icon: 'sirket_bulut' # assets/fm-icons/ui/sirket_bulut.svg dosyasını kullanır!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['sirket_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Özel İkon Güvenlik & Sınır Kuralları (`IconSetRegistry`)
- Her `.svg` dosyası en fazla **256 KB** (`262.144 byte`) olabilir ve yüklenirken otomatik olarak **`SvgSanitizer`** filtresinden geçirilir (`<script>`, `on*` olayları vb. temizlenir).
- `ui/` ve `badges/` klasörlerinin her birinde yerleşik anahtarlara ek olarak en fazla **200 özel `.svg`** dosyası taranır.
- Özel dosya adları (`.svg` uzantısı hariç) **`/^[a-zA-Z0-9_-]{1,32}$/`** biçiminde olmalıdır (yalnızca ASCII harf, rakam, `-`, `_`, maks. 32 karakter).

#### Tüm Ezilebilir İkon Anahtarları Referansı
- **9 Dosya & Klasör İkonu (kök `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 Arayüz (UI) İkonu (`<dir>/ui/<key>.svg`):**
  - *Gezinme & Düzen:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Dosya & Klasör İşlemleri:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Disk & Bulut Sağlayıcıları:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 Yerleşik Klasör Rozeti (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(+ eklediğiniz tüm özel `*.svg` dosyaları!)*

---

## 5. Kendi Özel Temanızı Oluşturma (3 Özgürlük Seviyesi)

`Browser.html.twig` profilinizdeki `theme` değerini şu **kesin öncelik sırasıyla** çözer:
1. `.html.twig` ile bitiyorsa → Doğrudan sizin Twig dosyanızı render eder (`{% include theme %}`).
2. `@` ile başlıyorsa → O Twig namespace'i içindeki `layout.html.twig` dosyasını render eder (`{% include theme ~ '/layout.html.twig' %}`).
3. 9 dahili temadan biriyse → Bundle'ın kendi temasını render eder.
4. Başka bir kısa adsa (ör. `'kurumsal'`, `/^[a-zA-Z0-9_-]{1,40}$/`) → Otomatik `<div data-liveux-theme="kurumsal">` ile temel yerleşimi sarar!

### Seviye 1: Sadece CSS Değişkenleriyle Marka Teması (En Hızlı — Şablon Yazmadan!)

#### Tüm Özelleştirilebilir CSS Değişkenleri (`--liveux-fm-*`)
| Kategori | CSS Değişkenleri |
| :--- | :--- |
| **Yazı Tipi** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Yüzeyler & Kenarlıklar** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Metin Renkleri** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Marka Aksan Renkleri** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Durum Renkleri (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Köşe & Gölgeler** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Kopyala-Yapıştır Özel Marka Teması (`assets/styles/app.css`)
```css
/* 1) Açık Mod Marka Renkleri */
[data-liveux-theme="kurumsal"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) Koyu Mod Karşılığı (Hem Browser hem FormType Picker, hem otomatik hem manuel dark mode) */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="kurumsal"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="kurumsal"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="kurumsal"],
[data-bs-theme="dark"] [data-liveux-theme="kurumsal"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="kurumsal"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="kurumsal"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="kurumsal"],
[data-fm-color-scheme="dark"] [data-liveux-theme="kurumsal"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'kurumsal'
```

---

### Seviye 2: Twig `{% extends %}` ile Sadece İstediğiniz Bölümleri Değiştirme (Önerilen!)

Dosya yöneticisinin şablon iskeleti **10 adet `{% block %}`** ve **7 modüler parça dosyadan (`parts/`)** oluşur. Tüm şablonu kopyalamadan yalnızca üst barı veya alt barı özelleştirebilirsiniz:

| Block Adı | Kapsadığı Bölüm | Varsayılan Parça Dosyası |
| :--- | :--- | :--- |
| `fm_shell` | En dış kabuk (`.liveux-fm-shell`) | Tüm alt blokları içerir |
| `fm_topbar` | Üst komut çubuğu (nav, breadcrumb, arama, sıralama, aksiyonlar) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Ayarlar açılır menüsü (renk modu, öğe boyutu, klasör stili, cache, kısayollar) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Bildirim bandı + yükleme kuyruğu | Inline markup |
| `fm_body` | Orta ana alan (sidebar + main + inspector kapsayıcı) | `.liveux-fm-body` |
| `fm_sidebar` | Sol klasör ağacı + sabitlenmiş klasörler + kota çubuğu + çöp kutusu | `parts/_sidebar.html.twig` |
| `fm_main` | Dosya ızgarası/listesi + yüzen seçim/pano barı + dropzone + sayfalama | `parts/_main.html.twig` |
| `fm_inspector` | Sağ önizleme & metadata inceleme paneli | `parts/_inspector.html.twig` |
| `fm_footer` | Modal / OffCanvas alt çubuğu | `parts/_footer.html.twig` |
| `fm_overlays` | Sağ tık menüsü, modal, lightbox ve kısayollar/hakkında şablonları | `parts/_overlays.html.twig` |

#### Örnek — Kurumsal Başlık Şeridi + Özel Alt Seçim Çubuğu
```twig
{# templates/file_manager/kurumsal_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Şirket Dijital Varlık Merkezi</span>
        <span>Profil: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Vazgeç</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Seçimi Onayla</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) Doğrudan .html.twig yolu:
                theme: 'file_manager/kurumsal_layout.html.twig'
                # 2) Veya Twig namespace klasörü (içindeki layout.html.twig yüklenir):
                # theme: '@App/file_manager'
```

---

### Seviye 3: %100 Sıfırdan Headless Yerleşim (Zorunlu Stimulus Target Listesi)

`{% extends %}` kullanmadan tamamen kendi HTML tasarımınızı yazmak isterseniz `ctrl = 'liveux--file-manager-bundle--browser'` önekiyle şu `data-{{ ctrl }}-target` özniteliklerini eklemeniz yeterlidir:

| Target (`data-...-target`) | Zorunlu mu? | Görevi |
| :--- | :---: | :--- |
| `shell` | Önerilen | Öğe boyutu ölçeği (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Zorunlu** | Dosya/klasör kartlarının ve listesinin çizildiği ana alan |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Zorunlu** | Yeni klasör, yükleme, silme, özelleştir, kota ve metadata modalları |
| `breadcrumbs` | Önerilen | Tıklanabilir konum gezinme yolu |
| `confirmSelectionBtn`, `footerStatus` | Modal/OffCanvas için Önerilen | Seçim onay butonu (seçim yokken otomatik `disabled`) ve durum yazısı |
| `statusBanner`, `uploadQueue` | Önerilen | Hata/başarı bildirimleri ve parçalı yükleme ilerleme kuyruğu |
| `tree` + `sidebar` + `pinnedFolders` | Opsiyonel | Sol klasör ağacı ve sabitlenmiş klasörler |
| `contextMenu` | Opsiyonel | Sağ tık bağlam menüsü kabı |
| `inspector` | Opsiyonel | Sağ önizleme ve metadata paneli |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Opsiyonel | Universal önizleme penceresi |

---

## 6. Özel Şablonlarda Kullanabileceğiniz 7 Twig Yardımcı Fonksiyonu

| Twig Fonksiyonu | Ne İşe Yarar? | Örnek Kullanım |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | XSS-güvenli inline SVG basar (`\|raw` gerekmez). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Dosya için güvenli thumbnail URL'i üretir. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Güvenli indirme / satır içi önizleme URL'i üretir. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'fatura.pdf', false) }}">İndir</a>` |
| `liveux_fm_trans(key, params, locale)` | Bundle çeviri anahtarını garantili `'en'` fallback ile çevirir. | `{{ liveux_fm_trans('ui.upload', {}, 'tr') }}` |
| `liveux_fm_bcp47_locale(locale)` | `tr_TR.UTF-8` gibi locale kodlarını HTML `lang="..."` için geçerli BCP-47 (`tr-TR`) biçimine çevirir. | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Verilen dil sağdan sola (Arapça, Farsça, İbranice vb.) ise `true` döner. | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Form/EasyAdmin değerlerini güvenli görsel/dosya listesine dönüştürür (`javascript:`/`data:` URL'lerini engeller). | EasyAdmin Index/Detail ve özel şablonlarda |

---

[← Önceki: 03 — Kullanım Modları & JS API](03-kullanim-modlari-ve-js-api.md) | [Ana İndeks](README.md) | [Sonraki: 05 — FormType ve Editör Entegrasyonu →](05-formtype-ve-editor-entegrasyonu.md)
