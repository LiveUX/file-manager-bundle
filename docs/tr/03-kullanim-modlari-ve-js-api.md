# 03 — Kullanım Modları ve JavaScript API

[← Önceki: 02 — Yapılandırma](02-yapilandirma-ve-profiller.md) | [Ana İndeks](README.md) | [Sonraki: 04 — Temalar, İkon Setleri ve Özelleştirme →](04-temalar-ikon-setleri-ve-ozellestirme.md)

LiveUX FileManagerBundle'ı sayfa içinde (**Inline**), ortalanmış açılır pencerede (**Modal**), 4 yönlü yan/alt çekmecede (**OffCanvas**) veya **JavaScript Promise API** üzerinden dilediğiniz yerden çağırabilirsiniz.

> **En Basit Haliyle: Hangi Modu Ne Zaman Seçmeliyim?**  
> - **Inline (Sayfa İçi):** Ayrı bir *"Medya Kütüphanesi"* sayfanız varsa (ekranın tamamı dosya yöneticisi olsun).  
> - **Modal (Açılır Pencere):** Bir ürün/yazı düzenlerken *"Görsel Seç"* butonuna basınca ortada pencere açılsın, seçince kapansın istiyorsanız.  
> - **OffCanvas (Yan/Alt Çekmece):** Formu arkada görmeye devam ederken sağdan veya alttan kayarak açılan modern bir panel istiyorsanız.  
> - **JavaScript API (`window.LiveUXFileManager.open()`):** Symfony Form olmadan kendi HTML butonunuzdan, React/Vue/Alpine veya özel JS kodunuzdan dosya seçtirmek istiyorsanız.

---

## 1. Sayfa İçi (Inline) Kullanım — TwigComponent

![Sayfa İçi (Inline) Dosya Yöneticisi Görünümü](../images/browser-light.png)

Sayfanızın içinde sabit bir yönetim alanı göstermek için tek bir Twig çağrısı yeterlidir. Kapsayıcı `<div>`'e sabit bir yükseklik (ör. `680px` veya `calc(100vh - 140px)`) vermeniz önerilir:

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Veya Symfony UX `<twig:...>` bileşen sözdizimiyle:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="tr"
    />
</div>
```

### Tüm `Browser` Bileşen Parametreleri

| Parametre | Tip | Varsayılan | Ne İşe Yarar? |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | `liveux_file_manager.yaml` içindeki profil adı. |
| `volume` | `?string` | `null` | Başlangıçta açılacak volume adı (`null` = profilin ilk volume'ü). |
| `path` | `string` | `''` | Başlangıç alt klasörü (ör. `'urunler/2026'`). |
| `multiple` | `bool` | `false` | Çoklu seçim modu. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` veya `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Başlangıç tür filtresi: `'all'` (tümü), `'folder'` (klasörler), `'image'` (görseller), `'doc'` (belgeler), `'video'` (videolar). |
| `theme` | `?string` | `null` | Profil temasını anlık geçersiz kılma (yalnızca 9 dahili tema adı kabul edilir: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Özel CSS/Twig temaları YAML profilinde tanımlanır. |
| `iconSet` | `?string` | `null` | Dahili ikon setini anlık geçersiz kılma (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Özel SVG dizinleri YAML profilinde tanımlanır. |
| `locale` | `?string` | `null` | Arayüz dilini anlık geçersiz kılma (`'tr'`, `'en'`, `'fr'` veya BCP-47 / POSIX locale, ör. `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal & OffCanvas — Programatik JavaScript Promise API

`DialogHost` tüm tam HTML sayfalara otomatik enjekte edilir (`defaults.auto_inject_dialog_host: true`). Bu sayede herhangi bir sayfadan `window.LiveUXFileManager.open()` fonksiyonunu `async/await` ile çağırabilirsiniz.

### Tüm `open(options)` Parametreleri

| Parametre | Tip | Varsayılan | Ne İşe Yarar? |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Kullanılacak profil adı. |
| `volume` | `?string` | `null` | Başlangıç disk (volume) anahtarı. |
| `path` | `string` | `''` | Başlangıç klasörü (ör. `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Ortalanmış modal veya kayan çekmece. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | `mode: 'offcanvas'` iken pencerenin hangi kenardan açılacağı (`start` = sol, `end` = sağ; RTL dillerde mantıksal olarak tersine döner). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(profil)* | Dar ekranlarda (`<= 767.98px`) mobil düzeni geçersiz kılar. |
| `multiple` | `bool` | `false` | `false` = tek dosya, `true` = birden fazla dosya seçimi. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Pencereyi doğrudan belirli dosya türüne filtreli açar (`filetype` TinyMCE uyumluluk takma adıdır; `'media'` otomatik `'video'` olarak eşlenir). |
| `theme` | `?string` | `null` | Anlık dahili tema geçersiz kılma (9 dahili tema adı; özel Twig şablon yolları güvenlik nedeniyle yalnızca sunucu YAML'dan verilir). |
| `iconSet` | `?string` | `null` | Anlık dahili ikon seti geçersiz kılma (6 dahili set). |
| `locale` | `?string` | `null` | Anlık dil geçersiz kılma (`'tr'`, `'en'`, `'fr'` vb.). |

### Gerçek Hayat Örneği: Symfony Form Olmadan Gizli `<input>` + Canlı Önizleme

Aşağıdaki kodu herhangi bir HTML/Twig sayfasına yapıştırıp hemen çalıştırabilirsiniz:

```html
<!-- 1. Gizli alan + önizleme + buton -->
<div>
    <input type="hidden" id="kapak-url" name="cover_url" value="">
    <img id="kapak-onizleme" src="" alt="Önizleme" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="kapak-sec-btn">Kapak Görseli Seç</button>
</div>

<script>
document.getElementById('kapak-sec-btn').addEventListener('click', async () => {
    const secilenler = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Sadece görselleri filtreli aç
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // Kullanıcı ESC'ye bastı veya seçim yapmadan kapattı
    if (secilenler.length === 0) {
        return;
    }

    const dosya = secilenler[0];
    document.getElementById('kapak-url').value = dosya.url;

    const img = document.getElementById('kapak-onizleme');
    if (dosya.isImage) {
        img.src = dosya.url;
        img.alt = dosya.meta?.alt || dosya.name;
        img.style.display = 'block';
    }
});
</script>
```

### Hızlı Mod Örnekleri

```html
<!-- Ortalanmış Modal -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Modal Aç</button>

<!-- Sağdan Açılan Çekmece (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Sağ Panel</button>

<!-- Alttan Açılan Çekmece (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Alt Panel</button>
```

> **İptal Davranışı:** Kullanıcı seçim yapmadan pencereyi kapatırsa (`ESC`, çarpı butonu veya arka plana tıklama) Promise hata (rejection) fırlatmaz, boş dizi (`[]`) döndürür.
>
> **Mobil Uyumluluk (`mobileMode` / `ui.mobile_dialog_mode`):** Dar ekranlarda (`<= 767.98px`) pencere otomatik olarak mobil uygulama düzenine geçer: `'auto'` modunda `modal` ve yan `offcanvas` (`start`/`end`) kenarsız tam ekran (`100dvh`), alt/üst `offcanvas` (`bottom`/`top`) tutamaçlı yuvarlatılmış native sheet olarak açılır. Sol klasör ağacı mobilde kapalı başlar ve dokunmatik kayan çekmece olarak açılır.

| Masaüstü OffCanvas Paneli (`position: 'end'`) | Mobil Alt Çekmece (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Masaüstü OffCanvas Görünümü](../images/offcanvas-desktop.png) | ![Mobil Sheet Görünümü](../images/mobile-sheet.png) |

### Dönen Dosya Nesnesi Formatı

`window.LiveUXFileManager.open()` her seçilen dosya için şu alanları içeren nesnelerden oluşan bir dizi döndürür:

```json
[
    {
        "kind": "file",
        "path": "urunler/termos-siyah.webp",
        "url": "/uploads/urunler/termos-siyah.webp",
        "name": "termos-siyah.webp",
        "extension": "webp",
        "isImage": true,
        "meta": {
            "alt": "Mat siyah çelik termos",
            "title": "Termos Pro",
            "caption": "500 ml vakumlu termos",
            "tags": ["termos", "promosyon"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Not: `private: true` disklerde `url` alanı, oturum yetkisini kontrol eden `/_liveux-file-manager/download?...&inline=1` adresi olarak döner.)*

### Özel DOM Olayları (Event Listeners)

`window.LiveUXFileManager.open()` dışında uygulamanızda pencere yaşam döngüsünü dinlemek isterseniz `window` üzerinde şu DOM olaylarını yakalayabilirsiniz:

| DOM Olayı (`window.addEventListener`) | Ne Zaman Tetiklenir? | `event.detail` İçeriği |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Pencere açma isteği gönderildiğinde (`file_manager_api.js` ile `dialog_controller.js` arasındaki köprü). | `open()` seçenekleri + `requestId` |
| `liveux:file-manager:dialog-opened` | Dialog başarıyla açılıp içerik hazır olduğunda. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Kullanıcı dosyaları seçip onayladığında (veya iptal ettiğinde). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Seçilen öğe sayısı:', event.detail.items.length);
});
```

---

## 3. Yerleşik Son Kullanıcı Özellikleri

- **Kompakt Sağ Tık Bağlam Menüleri:**
  - *Dosya / Klasör üzerinde:* Üstte Windows 11 tarzı tek satır SVG Hızlı İşlem Barı (`Kes`, `Kopyala`, `Ad Değiştir`, `İndir`/`Sabitle`, `Sil`), altında tek tık gruplu aksiyonlar (Önizle, Görseli Düzenle, Dosya Metadatası & Odak Noktası, ZIP Çıkar, Bağlantıyı Kopyala, Paylaşım Linki Oluştur, Taşı/Kopyala, Klonla, Klasör Rengi & İkonu).
  - *Boş alanda:* Üstte hızlı navigasyon çubuğu (`← → ↑ ↻`), Yeni Klasör, Buraya Yükle, Buraya Yapıştır, Tümünü Seç, Sıralama ve Gösterim Limiti. *(Tam Ekran, Klavye Kısayolları ve Hakkında seçenekleri üst araç çubuğundaki **⚙ Ayarlar** menüsünde yer alır.)*
  - *Mobil / Dokunmatik:* Kartlardaki `⋯` butonuyla alttan açılan bottom-sheet olarak çalışır.
- **Universal Önizleyici (Lightbox v2) & Sağ Inspector:**
  - **Görseller:** Galeri geçişi (`←`/`→`), yakınlaştırma (`-` / `100%` / `+`), odak noktası göstergesi.
  - **Video (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** Sağ Inspector'da canlı `<video controls>` oynatıcı + tam ekran Lightbox + özel disklerde `HTTP 206 Partial Content` ileri/geri sarma desteği (`ui.allow_video_preview` ile profil bazlı açılıp kapatılabilir).
  - **PDF (`.pdf`):** Lightbox içinde sertleştirilmiş `<iframe>` PDF görüntüleyici (`ui.allow_pdf_preview` ile açılıp kapatılabilir).
  - **Ses (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) & Metin/Kod (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql` vb. — 64 KB limit).**
  - Sayfa içi (`inline`) modda önizlenebilir herhangi bir dosyaya **çift tıklayarak** (veya `Space` tuşuyla) Lightbox anında açılır.
- **Akıllı Yükleme Kuyruğu (Duraklat / Devam Et / Onaylı İptal):**
  - Bilgisayardan dosya **veya komple klasör** (`webkitGetAsEntry`) sürükleyip bırakarak veya `Ctrl+V` ile panodan görsel yapıştırarak yükleme.
  - Parçalı yüklemede canlı parça sayacı (`2/5 • 40%`), tek tıkla **Duraklat (`⏸`) / Devam Et (`▶`)**, onay pencereli **İptal (`✕`)**, çoklu dosyalarda **Tümünü Duraklat / Tümünü İptal Et** ve iptalde sunucudaki yarım geçici parçaların otomatik temizlenmesi.
- **Çoklu Seçim (Marquee Kement Dahil), Pano (Clipboard) & Derin Arama:**
  - Boş alanda fareyle sürükleyerek **dikdörtgen seçim çerçevesi (Marquee / Lasso)** ile birden fazla dosya ve klasörü tek hareketle seçme (`Ctrl`/`Cmd` basılı tutarak mevcut seçime ekleyebilirsiniz).
  - `Shift+Click` aralık seçimi, `Ctrl+Click` çoklu seçim, `Ctrl+A` tümünü seç, `Ctrl+C`/`Ctrl+X`/`Ctrl+V` ile klasörler ve volume'lar arası kes/kopyala/yapıştır.
  - **Tıklanabilir Liste Sütun Başlıkları:** Liste görünümünde **Ad**, **Tür**, **Boyut** ve **Tarih** sütunlarına tıklayarak artan/azalan sıralama yapabilirsiniz.
  - **Canlı Kart Boyutu Kaydırıcısı & Büyük Klasör Kartları:** Ayarlar (`⚙`) menüsünden **Öğe Boyutu** sürgüsüyle (`96 px – 320 px`) kart boyutunu anlık büyütüp küçültebilir, klasörleri yatay şerit (`tile`) veya büyük kart (`card`) yapabilirsiniz.
  - Arama kutusundaki derin arama ikonuyla tüm alt klasörlerde özyinelemeli (`recursive`) arama ve `#etiket` / `alt` metin araması.
  - Sol panelde **⭐ Sabitlenmiş Hızlı Erişim** klasörleri, tıklanabilir **Depolama Kotası & Ayrıntılı Disk Kullanımı** modalı ve **Oto / Açık / Koyu** tema anahtarı.
- **0ms Görsel Boyut & En-Boy Oranı Doğrulaması (`image_constraints`):** Minimum/maksimum piksel boyutu veya en-boy oranı (`1:1`, `16:9` vb.) tanımlıysa uygunsuz görseller sunucuya gönderilmeden tarayıcıda anında reddedilir (sunucuda da tekrar doğrulanır).
- **Editoryal Dosya Metadatası, Odak Noktası & İmzalı Paylaşım:**
  - Her dosya için `alt`, `title`, `caption`, `#tags`, görsel üzerinde tıklanabilir `[x, y]` **Odak Noktası (Focal Point)** ve opsiyonel **✨ AI ile Öner**.
  - Süreli & HMAC imzalı paylaşım linki üretme (`15 dk`, `1 saat`, `24 saat`, `7 gün`, `30 gün`).
- **Gelişmiş Canvas Görsel Düzenleyici:** Serbest veya oran kilitli (`1:1`, `4:3`, `16:9`) kırpma, piksel boyutlandırma (`Max W`/`Max H`), çıktı formatı (`JPEG`/`PNG`/`WebP`) & kalite ayarı, 90° döndürme, yatay/dikey çevirme ve Kopya Kaydet / Üzerine Yaz.

| Dosya Metadatası & Odak Noktası (`I`) | Dahili Canvas Görsel Düzenleyici (`E`) |
| :---: | :---: |
| ![Dosya Metadatası ve Odak Noktası](../images/file-metadata.png) | ![Dahili Canvas Görsel Düzenleyici](../images/image-editor.png) |
- **Global Çok-Dilli, RTL & Erişilebilirlik (WCAG 2.1 AA):**
  - **Otomatik RTL (`dir="rtl"`):** Arapça (`ar`), Farsça (`fa`), İbranice (`he`), Urduca (`ur`) gibi dillerde tüm yerleşim, breadcrumb, sidebar ve offcanvas yönleri mantıksal CSS (`inset-inline`, `margin-inline`) ile kusursuz tersine döner.
  - **CJK / IME Desteği:** Çince, Japonca, Korece giriş yöntemlerinde (IME `isComposing`) yazım tamamlanmadan `Enter` veya kısayollar yanlışlıkla tetiklenmez.
  - **Tam Klavye & Ekran Okuyucu Erişilebilirliği:** Modal ve Lightbox içinde odak tuzağı (`Tab`/`Shift+Tab` focus trap), bağlam menüsünde ok tuşlarıyla gezinme, `role="tree"` / `aria-expanded` / `aria-current` desteği ve kapanınca odağın eski öğeye dönmesi.
- **Klavye Kısayolları (Tam ve Güncel Liste):**
  - Tüm kısayollar yalnızca dosya yöneticisi odaktayken, metin giriş alanları (`<input>`, `<textarea>`, `<select>`) dışındayken ve kullanıcının ilgili **Voter yetkisine** göre çalışır (`ui.keyboard_shortcuts: true`).
  - macOS'ta `Ctrl` yerine **`Cmd (⌘)`** tuşu da çalışır.
  - **Çok-Dilli Klavye & IME Koruması:** Fiziksel tuş kodu (`event.code` `Key*`) ve Türkçe `ı`/`İ` haritalaması sayesinde farklı klavye düzenlerinde de çalışır; Çince/Japonca/Korece IME yazımı (`isComposing`) sürerken hiçbir kısayol tetiklenmez.

#### A. Dosya & Klasör İşlemleri
| Kısayol | Ne İşe Yarar? | Koşul / Not |
| :--- | :--- | :--- |
| `N` | Yeni klasör oluştur | Çöp kutusu dışındayken (`create_folder` yetkisi) |
| `U` | Dosya yükleme penceresini aç | Çöp kutusu dışındayken (`upload` yetkisi) |
| `F2` | Yeniden adlandır | Tek dosya veya klasör seçiliyken (`rename` yetkisi) |
| `M` | Taşı / Kopyala penceresini aç | 1 veya daha fazla öğe seçiliyken (`move` veya `copy` yetkisi) |
| `D` | Dosyayı klonla (çoğalt) | **Yalnızca tek dosya** seçiliyken (`clone` yetkisi) |
| `I` | **Klasör:** Renk & İkon Özelleştir / **Dosya:** Metadata & Odak Noktası | Tek öğe seçiliyken (`edit_metadata` yetkisi) |
| `E` | Dahili Canvas Görsel Düzenleyici'yi aç | Tek raster görsel (`.jpg`, `.jpeg`, `.png`, `.webp`) seçiliyken (`upload` yetkisi) |
| `L` | Dosyanın URL adresini panoya kopyala | Tek dosya seçiliyken (`download` yetkisi) |
| `S` | Süreli & HMAC imzalı paylaşım linki penceresini aç | Tek dosya seçiliyken (`share` yetkisi + `share.enabled: true`) |
| `Delete` | Seçili öğeleri sil (çöpe taşı veya kalıcı sil) | 1 veya daha fazla öğe seçiliyken (`delete` yetkisi) |

#### B. Gezinme & Önizleme
| Kısayol | Ne İşe Yarar? | Koşul / Not |
| :--- | :--- | :--- |
| `/` veya `Ctrl + F` | Arama kutusuna odaklan | `show_search: true` iken (`Ctrl + F` metin kutusu içindeyken de çalışır) |
| `←` `→` `↑` `↓` | Grid kartları veya liste satırları arasında gezin | — |
| `Enter` | **Klasörse:** içine gir • **Tek dosyaysa:** Inline modda Lightbox önizleme aç, Modal/OffCanvas modda seçimi onayla | — |
| `Space` | Seçili dosyayı Lightbox ile önizle | Tek dosya seçiliyken |
| `Backspace` veya `Alt + ↑` | Üst klasöre çık | Alt klasördeyken |
| `Alt + ←` / `Alt + →` | Gezinme geçmişinde Geri / İleri git | Arapça/Farsça/İbranice **RTL** dillerde yön otomatik terslenir |
| `R` | Mevcut klasörü sunucudan yenile (`?refresh=1`) | `show_refresh_button: true` iken |

#### C. Seçim, Pano & Katman Kapatma
| Kısayol | Ne İşe Yarar? | Koşul / Not |
| :--- | :--- | :--- |
| `Ctrl + A` | Ekrandaki tüm görünür öğeleri seç | Çöp kutusu dışındayken |
| `Ctrl + C` / `Ctrl + X` | Seçili öğeleri panoya kopyala / kes | Metin seçili değilken (`copy` / `move` yetkisi) |
| `Ctrl + V` | Panodaki dosya/klasörleri mevcut klasöre yapıştır **veya** bilgisayar panosundaki görseli/dosyayı doğrudan yükle | Yükle modalı açıkken de panodan görsel yapıştırabilirsiniz |
| `Shift + Tık` / `Ctrl + Tık` | Aralık seçimi / tekil çoklu seçim ekleme-çıkarma | Boş alanda fareyle sürükleyerek Marquee (kement) seçimi de yapılabilir |
| `Esc` | **4 aşamalı akıllı kapatma:** 1) Açık menü/modal/lightbox/ayarlar/mobil çekmeceyi kapat → 2) Seçimi temizle → 3) Panoyu temizle → 4) Inline tam ekrandan çık | Her basışta yalnızca en üstteki katmanı kapatır |
| `Tab` / `Shift + Tab` | Modal ve Lightbox açıkken odak tuzağı (focus trap) ile kontroller arası gezin | — |

#### D. Görünüm & Yardım
| Kısayol | Ne İşe Yarar? |
| :--- | :--- |
| `V` | Grid (ızgara) ve Liste görünümü arasında geçiş yap |
| `B` | Sol klasör ağacı panelini aç / gizle |
| `F` | Tam ekran modunu aç / kapat |
| `T` | Çöp Kutusu görünümünü aç / kapat (`trash.enabled: true` iken) |
| `?` veya Arapça `؟` | Klavye kısayolları yardım penceresini aç |

#### E. Lightbox Önizleme Açıkken
| Kısayol | Ne İşe Yarar? |
| :--- | :--- |
| `←` / `→` | Önceki / Sonraki görsele geç *(RTL dillerde yön otomatik terslenir)* |
| `+` veya `=` veya `Numpad +` | Görseli yakınlaştır |
| `-` veya `Numpad -` | Görseli uzaklaştır |
| `0` veya `Numpad 0` | Yakınlaştırmayı `%100` boyutuna sıfırla |
| `Esc` | Lightbox penceresini kapat |

#### F. Bağlam Menüsü (Sağ Tık) Açıkken
| Kısayol | Ne İşe Yarar? |
| :--- | :--- |
| `↑` / `↓` | Menü seçenekleri arasında yukarı / aşağı gezin |
| `Home` / `End` | İlk / Son menü öğesine atla |
| `Enter` / `Space` | Odaktaki menü işlemini çalıştır |
| `Tab` / `Esc` | Bağlam menüsünü kapat |

---

[← Önceki: 02 — Yapılandırma](02-yapilandirma-ve-profiller.md) | [Ana İndeks](README.md) | [Sonraki: 04 — Temalar, İkon Setleri ve Özelleştirme →](04-temalar-ikon-setleri-ve-ozellestirme.md)
