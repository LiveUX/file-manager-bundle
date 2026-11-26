# 11 — Katkı & Pull Request (PR) Rehberi (Yeni Başlayanlar İçin Adım Adım)

[← Önceki: 10 — Kullanım Koruması (Usage Guard)](10-kullanim-korumasi-usage-guard.md) | [Ana İndeks](README.md) | [Sonraki: 12 — Yapay Zeka Agent Skills →](12-yapay-zeka-skills-rehberi.md)

Bu rehber, **daha önce hiç açık kaynak bir projeye Pull Request (PR) açmamış** ya da **kendi açık kaynak reposunda Symfony benzeri otomatik PR kalite kapısı kurmak isteyen** geliştiriciler için sıfırdan, tüm teknik kavramları açıklayarak hazırlanmıştır.

---

## 1. Büyük Resim: Açık Kaynak PR Sistemi Nasıl Çalışır?

Symfony, Laravel veya React gibi büyük projelerde dışarıdan gelen bir geliştirici doğrudan ana koda (`main` dalına) yazamaz. Bunun yerine **4 aşamalı güvenli bir gümrük kapısı** vardır:

1. **Fork & Branch (Kendi Kopyanı Al):** Projeyi kendi GitHub hesabına kopyalarsın (*Fork*) ve yeni bir çalışma dalı (*Branch*) açarsın.
2. **Kodlama & Yerel Test (`composer qa`):** Değişikliğini yapar, testini yazar ve kendi bilgisayarında tüm otomatik kontrolleri çalıştırırsın.
3. **Pull Request (PR) Açma:** *"Ben şu hatayı düzelttim / şu özelliği ekledim, ana projeye alır mısınız?"* diye bir istek (*Pull Request*) açarsın. GitHub bu sırada sana otomatik olarak **Symfony tarzı PR tablosu ve kontrol listesi** (`.github/PULL_REQUEST_TEMPLATE.md`) sunar.
4. **Otomatik Robot Kontrolü (GitHub Actions CI) + İnceleme:**
   - PR açıldığı anda GitHub'ın bulut sunucuları (`.github/workflows/ci.yml`) otomatik olarak uyanır.
   - Kodu **PHP 8.3 & 8.4**, **Symfony 7.2, 7.4 & 8.0**, **Node 20 & 22** üzerinde test eder; kod stilini (**PHP-CS-Fixer**), tip güvenliğini (**PHPStan Level 8**), çeviri eksiklerini (**TranslationParityTest**), YAML döküman şemasını (**FullConfigReferenceDocTest**), Agent Skills paketini (**SkillsPackageIntegrityTest**) ve XSS/CSP/frontend güvenliğini (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**) denetler.
   - **Tek bir kontrol bile kırmızı yanarsa GitHub "Merge" (Birleştir) butonunu kilitler!**

---

## 2. Altyapımız Buna Uygun mu? (Hazırlık Durumu)

**Evet — %100 uygun ve kurulu!** Paketin içinde Symfony UX standartlarıyla birebir örtüşen eksiksiz bir kalite altyapısı hazırdır:

| Dosya | Ne İşe Yarar? (Basit Anlatım) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Tek komutla tüm kontrolleri çalıştıran `composer qa` komut setini ve bağımsız test bağımlılıklarını (`require-dev`) tanımlar. |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | PHP birim ve güvenlik testlerini (**57 test dosyası, 360 test, 3.500+ doğrulama**) başka bir projeye ihtiyaç duymadan tek başına çalıştırır. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Kodu çalıştırmadan önce gizli tip hatalarını, yanlış parametreleri ve tanımsız metotları yakalayan **PHPStan Level 8** statik analiz yapılandırması. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Tüm PHP dosyalarının PSR-12 + Symfony stilinde (`declare(strict_types=1)`, `===`, alfabetik `use` sırası) olmasını denetler. |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Her PR açıldığında veya güncellendiğinde GitHub üzerinde otomatik çalışan **6 paralel CI görevi**. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | PR açan kişinin önüne otomatik çıkan Symfony tarzı özet tablosu (`Bug fix?`, `BC breaks?`, `Tests pass?`) ve kontrol listesi. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Hata bildirimi (`bug_report.yml`) ve özellik isteği (`feature_request.yml`) için düzenli form şablonları. |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | GitHub'ın her yeni PR ve Issue ekranında otomatik link verdiği uluslararası katkı kuralları. |
| [`SECURITY.md`](../../SECURITY.md) | Güvenlik açıklarının herkese açık issue yerine gizli olarak nasıl bildirileceğini anlatan politika. |

### Otomatik Olarak Yakalanan 7 Kritik Kural
Bir geliştirici PR gönderdiğinde aşağıdaki hatalar insan gözünden kaçsa bile **testlerimiz otomatik olarak yakalar ve PR'ı reddeder**:
1. **Çeviri Eksikliği (`TranslationParityTest`):** Yeni bir metin sadece İngilizce'ye eklenip Türkçe veya Fransızca'ya (`493/493/493` anahtar) eklenmezse test **FAIL** verir.
2. **`strict_types` Unutulması (`TranslationParityTest`):** `src/` altında `declare(strict_types=1);` eksik tek bir PHP dosyası varsa test **FAIL** verir.
3. **XSS / CSP / Frontend Güvenlik İhlali (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`):** Twig içine `onclick="..."` veya `|raw` yazılırsa ya da JS tarafında `javascript:` URL / DOM güvenliği bozulursa test **FAIL** verir.
4. **Dökümantasyon YAML Şema Uyumu (`FullConfigReferenceDocTest`):** `docs/tr/02-yapilandirma-ve-profiller.md` içindeki tüm `liveux_file_manager:` YAML blokları gerçek `Configuration.php` şemasına karşı test edilir.
5. **Agent Skills Bütünlüğü (`SkillsPackageIntegrityTest`):** `AGENTS.md` ve 6 `skills/*/SKILL.md` dosyasının frontmatter, boyut ve referans linkleri doğrulanır.
6. **Kod Stili ve Tip Hatası (`PHP-CS-Fixer` + `PHPStan Level 8`):** PSR-12/Symfony formatına uymayan veya tip uyumsuzluğu olan kod CI kapısından geçemez.
7. **Sıkıştırılmış `assets/dist/` Senkronizasyonu (`npm --prefix assets run build:check`):** Bir geliştirici `assets/controllers/*.js` veya `assets/styles/core.css` dosyasını düzenleyip `assets/dist/` derlemesini almayı unutursa CI **FAIL** verir.

---

## 2.5. JS & CSS Sıkıştırma (Minify / `assets/dist/`) Mimarisi (`esbuild`)

> **Bu Neden Var? (Hiç Bilmeyenler İçin Basit Anlatım):**  
> - **Webpack Encore** kullanıcıları `npm run build` çalıştırdığında JS/CSS dosyaları zaten otomatik sıkıştırılır.  
> - Ancak **Symfony AssetMapper** (`asset-map:compile`) dosyaları küçültmeden (minify yapmadan) olduğu gibi sunar. `browser_controller.js` kaynak haliyle `518 KB`, `core.css` ise `132 KB` boyutundadır.  
> - Bu yüzden resmi Symfony UX paketlerinin (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`) kullandığı **Kaynak → Dağıtım (`assets/dist/`)** mimarisini uyguluyoruz:

### Nasıl Çalışır?
1. **Okunabilir Kaynak Kodlar:** Tüm geliştirme ve güvenlik testleri her zaman yorumlu, okunabilir dosyalarda yapılır:
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` ve `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` ve `assets/images/liveux-logo.webp`
2. **Sıkıştırılmış Üretim Çıktısı (`assets/dist/`):** [`assets/build.mjs`](../../assets/build.mjs) betiği **`esbuild`** kullanarak her JS dosyasını bağımsız temiz ESM modülü olarak (`@hotwired/stimulus` harici tutularak, göreceli importlar korunarak) ve `core.css`'i `assets/dist/` klasörüne küçültür.
3. **Otomatik Yönlendirme (Sıfır Kırılma):**
   - [`FileManagerBundle.php`](../../src/FileManagerBundle.php) içindeki `prependAssetMapper()`, `assets/dist` mevcutsa `@liveux/file-manager-bundle` takma adını otomatik `assets/dist/` dizinine bağlar.
   - [`assets/package.json`](../../assets/package.json) içindeki `"exports"` ve `"symfony.controllers.*.main"` tanımları Webpack Encore'un da doğrudan `dist/` dosyalarını kullanmasını sağlar.
   - `assets/dist/` Git'e commit edildiği için son kullanıcının bilgisayarında **Node.js / npm gerekmez**. `.gitattributes` ise yalnızca geliştirme araçlarını (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) Packagist paketinden hariç tutar; `assets/package.json` ve `assets/dist/` pakete dahil edilir!

### Gerçek Boyut Kazancı
| Dosya | Ham Kaynak | Minified (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518.3 KB | **259.5 KB** | 60.9 KB | **49.2 KB** |
| `controllers/picker_controller.js` | 45.0 KB | **24.3 KB** | 7.0 KB | **6.2 KB** |
| `controllers/dialog_controller.js` | 27.5 KB | **13.9 KB** | 4.7 KB | **4.1 KB** |
| `js/file_manager_api.js` + 3 editör adaptörü | 24.5 KB | **11.0 KB** | 5.1 KB | **4.6 KB** |
| `styles/core.css` | 131.8 KB | **101.5 KB** | 15.3 KB | **12.9 KB** |
| **TOPLAM (`-45.1%` minify)** | **747.2 KB** | **410.2 KB** | **92.9 KB** | **76.9 KB** |

### Geliştirici Komutları (JS veya CSS Değiştirdiğinizde)
```bash
# 1. İlk seferde esbuild'i kurun (yalnızca bir kez gerekir):
npm --prefix assets install

# 2. JS veya CSS düzenledikten sonra assets/dist/ dosyalarını güncelleyin (~50 ms sürer):
npm --prefix assets run build
# veya Composer kısayolu ile:
composer assets:build

# 3. assets/dist/ dosyalarının kaynakla %100 güncel olduğunu doğrulayın (CI'ın çalıştırdığı kontrol):
composer assets:check
```

---

## 3. PR Göndermek İsteyen Geliştirici İçin Adım Adım Rehber

> **Hiç daha önce PR göndermediyseniz bile** aşağıdaki 6 adımı sırayla uygulayarak hatasız bir Pull Request açabilirsiniz.

### Adım 1: Projeyi Fork'la, İndir ve Bağımlılıkları Kur
1. GitHub'da [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) sayfasının sağ üstündeki **Fork** butonuna tıkla (kendi GitHub hesabında bir kopyası oluşur).
2. Kendi kopyanı bilgisayarına indir, ana depoyu `upstream` olarak ekle ve PHP + Node geliştirme araçlarını kur:
```bash
git clone https://github.com/<KULLANICI-ADIN>/file-manager-bundle.git
cd file-manager-bundle

# Ana repoyu takip etmek için upstream ekle (güncel kalmak için iyi alışkanlık)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# PHP test & analiz araçlarını kur
composer install

# JS/CSS minify (esbuild) aracını kur (JS/CSS düzenleyecekseniz)
npm --prefix assets install
```

### Adım 2: Güncel `main` Üzerinden Yeni Bir Dal (Branch) Oluştur
Asla doğrudan `main` üzerinde çalışma! Önce ana dalı güncelle, sonra yapacağın işe göre anlamlı bir dal aç:
- Hata düzeltmesi: `fix/klasor-sayaci-cache`
- Yeni özellik: `feat/yeni-ikon-seti`
- Dökümantasyon: `docs/kurulum-ornekleri`

```bash
git checkout main
git pull upstream main
git checkout -b fix/klasor-sayaci-cache
```

### Adım 3: Kodunu Yaz, Testini Ekle ve JS/CSS Değiştiyse `dist/` Derle
- **Bir hata düzelttiysen:** Önce o hatayı tekrarlayan küçük bir test yaz (hata varken kırmızı, düzeltince yeşil olsun).
- **Yeni özellik eklediysen:**
  - PHP servisleri için `tests/Unit/...` altına test ekle.
  - Arayüz/JS değişikliği yaptıysan `tests/Js/dom_security_test.mjs` veya `tests/Js/frontend_audit_test.mjs` içine test ekle.
  - **JS veya CSS değiştirdiysen mutlaka `composer assets:build` çalıştır!** (Aksi halde CI `build:check` aşamasında uyarır.)
  - Yeni arayüz metni eklediysen `translations/LiveUXFileManagerBundle.{en,tr,fr}.yaml` dosyalarının **üçüne birden** ekle.

### Adım 4: Tek Komutla Tüm Kalite Kapılarını Çalıştır!
PR açmadan önce kendi bilgisayarında CI robotunun yapacağı tüm kontrolleri önceden çalıştır:

```bash
composer qa
composer assets:check
```

Bu komutlar sırasıyla şunları doğrular:
1. `composer validate --strict` — `composer.json` geçerli mi?
2. `composer cs:check` — Kod stili PSR-12/Symfony'ye uygun mu? *(Hata çıkarsa `composer cs:fix` çalıştır, kendisi otomatik düzeltir!)*
3. `composer stan` — **PHPStan Level 8** tip analizi temiz mi?
4. `composer test` — Tüm PHPUnit birim, güvenlik, çeviri ve şema testleri geçiyor mu?
5. `composer test:js:syntax` — Tüm kaynak ve `dist/` JS dosyalarında sözdizimi (`node --check`) hatası var mı?
6. `composer test:js` — Node.js güvenlik ve arayüz testleri (`dom_security_test.mjs` + `frontend_audit_test.mjs`) geçiyor mu?
7. `composer assets:check` — `assets/dist/` sıkıştırılmış çıktıları kaynak dosyalarla birebir güncel mi?

> **Hepsi yeşil mi?** Artık güvenle commit atıp PR açabilirsin!

### Adım 5: Temiz Commit At ve Fork'una Gönder (Push)
```bash
git status            # Yalnızca ilgili dosyaların değiştiğini kontrol et
git diff --stat       # Değişiklik özetine göz at
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/klasor-sayaci-cache
```

*(Commit mesajında kısa ve anlaşılır önek kullanın: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Adım 6: GitHub Üzerinden Pull Request (PR) Aç ve CI Tiklerini Kontrol Et
1. Tarayıcıda kendi fork sayfana (`https://github.com/<KULLANICI-ADIN>/file-manager-bundle`) gir — üstte sarı kutuda çıkan **"Compare & pull request"** butonuna tıkla.
2. **Base repository:** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository:** `<KULLANICI-ADIN>/file-manager-bundle` (`compare: fix/klasor-sayaci-cache`) olduğundan emin ol.
3. Otomatik açılan **PR şablonundaki** tabloyu (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) ve kontrol kutularını `[x]` şeklinde doldur; görsel bir değişiklik yaptıysan ekran görüntüsü veya GIF ekle.
4. **"Create pull request"** butonuna tıkla.
5. PR sayfasının en altında GitHub Actions robotlarının (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) tamamının **yeşil tik (✓)** almasını bekle.
6. İnceleme (code review) sırasında değişiklik istenirse aynı dalda düzenleme yapıp tekrar `git commit && git push` yapman yeterlidir — PR otomatik güncellenir!

---

## 4. Proje Sahibi (Maintainer) İçin: GitHub'da PR Kurallarını Zorunlu Kılma

Yukarıdaki dosyalar repoya eklendiğinde CI robotları otomatik çalışır; ama **"Testler kırmızıysa kimse (sen bile yanlışlıkla) Merge butonuna basamasın"** demek için GitHub ayarlarından 2 dakikalık **Ruleset / Branch Protection** açman gerekir:

1. GitHub'da bundle repona gir → üst menüden **Settings** (Ayarlar) sekmesine tıkla.
2. Sol menüden **Rules → Rulesets** (veya klasik menüde **Branches → Add branch protection rule**) seç.
3. **New branch ruleset** butonuna tıkla:
   - **Ruleset Name:** `Protect main (Symfony Quality Gate)`
   - **Enforcement status:** `Active`
   - **Target branches:** `Add target` → `Include default branch` (`main`)
4. Aşağıdaki 4 kutucuğu işaretle:
   - [x] **Restrict deletions** (`main` dalı silinemez)
   - [x] **Block force pushes** (geçmiş ezilemez)
   - [x] **Require a pull request before merging** (doğrudan `main`'e push yasak, her şey PR ile gelir)
     - *Required approvals:* `1` (ekip varsa en az 1 onay; tek kişiysen `0` bırakabilirsin)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(EN ÖNEMLİ AYAR!)*
     - [x] *Require branches to be up to date before merging*
     - **Add checks** alanından ilk CI çalışmasından sonra şu **6 kontrolün tamamını** zorunlu olarak ekle:
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. En alttan **Create** butonuna bas!

---

## 5. Bundle'ı Ayrı Açık Kaynak GitHub Reposu Olarak Yayınlama Kontrol Listesi

Bundle'ı `LiveUX/file-manager-bundle` olarak GitHub + Packagist üzerinde yayınlamak için:

1. GitHub `LiveUX` organizasyonunda `file-manager-bundle` adında yeni, boş bir **Public** repo aç.
2. `bundles/liveux-file-manager-bundle/` klasörünün içeriğini o repoya ilk commit olarak gönder (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. GitHub Actions sekmesinden ilk CI koşusunun 6 yeşil tiki de aldığını doğrula.
4. Yukarıdaki **4. Bölüm (Ruleset)** ayarlarını aç.
5. `v0.1.0` sürüm etiketi (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) oluştur ve [Packagist.org](https://packagist.org) üzerine `https://github.com/LiveUX/file-manager-bundle` adresini kaydet.
6. Artık herkes tek komutla kurabilir: `composer require liveux/file-manager-bundle`!

---

[← Önceki: 10 — Kullanım Koruması (Usage Guard)](10-kullanim-korumasi-usage-guard.md) | [Ana İndeks](README.md) | [Sonraki: 12 — Yapay Zeka Agent Skills →](12-yapay-zeka-skills-rehberi.md)
