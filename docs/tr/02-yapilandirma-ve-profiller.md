# 02 — Yapılandırma ve Profiller (Tam Parametre Rehberi & Hazır Reçeteler)

[← Önceki: 01 — Gereksinimler ve Kurulum](01-gereksinimler-ve-kurulum.md) | [Ana İndeks](README.md) | [Sonraki: 03 — Kullanım Modları & JS API →](03-kullanim-modlari-ve-js-api.md)

Bu rehber, `config/packages/liveux_file_manager.yaml` dosyasındaki **her bir parametrenin ne işe yaradığını**, varsayılan değerinin ne olduğunu, hangi senaryoda değiştirilmesi gerektiğini ve doğrudan projenize kopyalayabileceğiniz **5 gerçek dünya profil reçetesini** adım adım anlatır.

---

## 30 Saniyede Temel Mantık: `defaults`, `volumes` ve `profiles`

Yapılandırma dosyasını bir şirket binası gibi düşünebilirsiniz; **3 ana bölümden** oluşur:

1. **`defaults` (Genel Kurallar):** Tüm profillerin ortak mirasıdır (varsayılan tema, dil, koyu mod, cache, metadata deposu, denetim günlüğü). Bir profilde özellikle değiştirmediğiniz sürece buradaki değerler geçerlidir.
2. **`volumes` (Depolama Diskleri / Havuzlar):** Dosyaların **fiziksel olarak nerede** saklandığını söyler (sunucunuzdaki yerel klasör, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage veya özel Flysystem servisi).
3. **`profiles` (Kullanım Profilleri):** Kullanıcıya açılan pencerenin **kurallarını ve yetkilerini** belirler. Örneğin:
   - `product_catalog` profili yalnızca `1:1` kare ürün fotoğraflarına izin verip otomatik WebP yapabilir,
   - `customer_docs` profili gizli Supabase S3 üzerinde yalnızca PDF kabul edip her kullanıcıyı kendi klasörüne kilitleyebilir,
   - `editor_picker` profili TinyMCE için sade ve tek tıkla seçim yapan küçük bir modal açabilir.

---

## 1. `defaults` — Genel Varsayılanlar

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = Symfony aktif dili (çeviri yoksa 'en' diline düşer)
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parametre | Varsayılan | Ne İşe Yarar? | Ne Zaman Değiştirilir? |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | `volumes` tanımlamadan doğrudan profil içi `path` kullanan basit kurulumların varsayılan kök dizini. | Dosyaları `public/media` gibi başka bir klasörde tutuyorsanız. |
| `theme` | `'bootstrap5'` | Tüm profillerin varsayılan görsel teması (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` veya kendi özel tema adınız). | Projenizin tasarımına uydurmak için (ör. Tailwind projesinde `'shadcn'` veya `'tailwind'`). |
| `locale` | `null` | `null` iken Symfony'nin geçerli istek/çeviri dilini otomatik miras alır (ör. `tr`, `en`, `fr`, `de_DE.UTF-8`, `zh-Hans-CN`, `ar`). Eksik anahtarlar veya çevirisi henüz olmayan diller her zaman garantili olarak `'en'` diline düşer; Arapça/Farsça/İbranice/Urduca dillerinde RTL (`dir="rtl"`) otomatik açılır. | Tüm kullanıcıları tek sabit bir dile kilitlemek isterseniz (`'tr'`, `'en'`, `'fr'`). |
| `color_scheme` | `'auto'` | Renk modu: `'auto'` (sitenin `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]` durumunu otomatik algılar), `'light'` (hep açık), `'dark'` (hep koyu). | Yalnızca koyu veya yalnızca açık tasarım zorlamak isterseniz. |
| `z_index` | `1050` | Modal, OffCanvas, Lightbox ve sağ-tık menüsünün temel katman (`--liveux-fm-z-base`) yüksekliği (`>= 1`). | Admin temanızın üst menüsü (`z-index: 9999`) dosya yöneticisinin üstünde kalıyorsa `10050` gibi daha yüksek değer verin. |
| `auto_inject_dialog_host` | `true` | `window.LiveUXFileManager.open()` için gereken `<dialog>` taşıyıcısını tam HTML sayfaların `</body>` sonuna otomatik ekler (`base.html.twig` düzenlemeniz gerekmez). | Sadece belirli sayfalarda elle `{% include '@LiveUXFileManager/components/DialogHost.html.twig' %}` yapmak isterseniz `false` yapın. |
| `dialog_host_profile` | `'default'` | Otomatik eklenen `DialogHost` bileşeninde profil belirtilmediğinde açılacak varsayılan profil. | Ana profilinizin adı `default` değilse (ör. `admin_media`). |
| `cache.enabled` | `true` | Dizin listesi ve klasör ağacı için sunucu tarafı PSR-6 önbelleği. | Geliştirme sırasında cache istemiyorsanız `false` (veya profil/volume bazında kapatabilirsiniz). |
| `cache.pool` | `'cache.app'` | Kullanılacak Symfony cache havuzu (Redis, Filesystem, APCu vb.). Etiketli temizleme için `<pool>.taggable` varsa otomatik kullanılır. | Dosya yöneticisi cache'ini ayrı bir Redis havuzunda tutmak isterseniz. |
| `cache.ttl` | `3600` | Dizin önbelleğinin saniye cinsinden yaşam süresi (varsayılan 1 saat). Yükleme/silme/taşıma yapıldığında ilgili dizin otomatik temizlenir. | Dışarıdan (FTP/S3 panel) sık dosya değişen sistemlerde `60`–`300` yapabilirsiniz. |
| `metadata.enabled` | `true` | Klasör renk/ikon/sabitleme ve dosya SEO (`alt`, `title`, `caption`, `tags`, `focalPoint`) verilerini saklar. | Sade bir dosya seçicide hiç metadata istemiyorsanız `false`. |
| `metadata.driver` | `'json_file'` | Metadata deposu: `'json_file'` (tek sunucu için dosya tabanlı), `'doctrine'` (çok sunuculu / load-balanced ortamlar için yerleşik DBAL deposu — MySQL/MariaDB/PostgreSQL/SQLite), `'none'` (kapalı). | Birden fazla uygulama sunucunuz (load balancer, Kubernetes, Docker replicas) varsa yerleşik `'doctrine'` seçin (Bkz. [Bölüm 13](13-doctrine-entegrasyonu.md)). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | `json_file` sürücüsünün veri dosyası. MIME-kısıtlı bulut çöp kutusu için yedek dosya (`trash-meta.json` veya `<ad>.trash-meta.json`) da aynı klasörde otomatik tutulur. | Paylaşımlı bir NFS/volume klasörüne taşımak isterseniz. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | `driver: 'doctrine'` seçildiğinde kullanılacak DBAL bağlantısı, EntityManager, tablo adı (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) ve otomatik tablo oluşturma bayrağı. | Canlı ortamda şemayı Doctrine Migration ile yönetiyorsanız `auto_create_table: false` yapın (Bkz. [Bölüm 13](13-doctrine-entegrasyonu.md)). |
| `audit_log.enabled` | `false` | 9 dosya/klasör olayını (yükleme, silme, taşıma, kopyalama, ad değiştirme, klonlama, klasör oluşturma, çöp geri yükleme ve kalıcı silme) PSR-3 log kanalına yapısal (`user`, `ip`, `path`, `volume`) olarak yazar. | *"Bu dosyayı kim, ne zaman sildi?"* sorusuna cevap gereken kurumsal sistemlerde `true`. |
| `audit_log.level` | `'info'` | Audit log seviyesi (`debug`, `info`, `notice`, `warning`). | Monolog filtreleme politikanıza göre. |
| `ai.default_model` | `'gpt-4o-mini'` | Opsiyonel `symfony/ai-bundle` kuruluysa kullanılacak varsayılan görsel analiz modeli (Bkz. [Bölüm 09](09-symfony-ai-entegrasyonu.md)). | Gemini, Claude veya yerel Ollama modeli kullanmak için. |
| `ai.platform_service` | `null` | Özel bir Symfony AI `PlatformInterface` servis ID'si (`null` ise varsayılan servis otomatik alınır). | Projede birden fazla AI platformu tanımlıysa. |

---

## 2. `volumes` — Depolama Birimleri (Yerel & Bulut)

Bir profil birden fazla volume'ü aynı anda kullanabilir (örneğin hem Yerel Disk hem Supabase/R2/S3). Kullanıcılar dosyaları yerel diskten doğrudan bulut klasörüne sürükleyip bırakabilir.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Yerel Disk'
            icon: 'disk'              # Opsiyonel (boş bırakılırsa driver/endpoint'ten otomatik algılanır)
            group: 'local'            # Opsiyonel (null ise LocalFileSystem -> 'local', S3/Flysystem -> 'network')
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # Opsiyonel — bu volume'e özel cache süresi (0 = kapalı)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Endpoint'ten otomatik de algılanır
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Bulut panelinden yapılan dış değişimler 60 sn içinde yansısın
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parametre | Varsayılan | Ne İşe Yarar? | Ne Zaman Değiştirilir? |
| :--- | :--- | :--- | :--- |
| `label` | Volume anahtarı | Sol menüde, breadcrumb'da ve açılır listede görünen başlık. | Kullanıcı dostu isim vermek için (örn. `'Ürün Medya CDN'`). |
| `icon` | `null` *(otomatik)* | Sol dizin listesindeki SVG ikonu. `null` bırakılırsa `driver` ve `endpoint`'ten otomatik algılanır (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Kendi özel ikon setinizdeki `<dir>/ui/benim_ikon.svg` ikonunu atamak isterseniz. |
| `group` | `null` *(otomatik)* | Sol ağaçta hangi başlık altında gruplanacağı. `null` ise `LocalFileSystem` otomatik `'local'`, `AwsS3` ve `FlysystemService` otomatik `'network'` olur. | Bir ağı yerel grupta veya yerel NFS bağını ağ grubunda göstermek isterseniz. |
| `driver` | `'LocalFileSystem'` | Depolama sürücüsü: yerel disk (`LocalFileSystem`), S3 uyumlu bulut (`AwsS3`) veya mevcut Flysystem servisi (`FlysystemService`). | Bulut bağlarken `'AwsS3'`, özel SFTP/Azure/Dropbox servisi bağlarken `'FlysystemService'`. |
| `path` | `null` | `LocalFileSystem` için sunucudaki fiziksel dizin yolu (`{user_id}`, `{Y}`, `{m}`, `{d}` destekler). | Özel klasör veya kullanıcı bazlı izolasyon (`.../users/{user_id}`) için. |
| `url_prefix` | `null` | Tarayıcının herkese açık dosyaları açacağı URL öneki (`/uploads` veya `https://cdn.site.com`). `private: true` ise yoksayılır. | CDN veya Supabase/R2/S3 public bucket URL'i bağlarken. |
| `private` | `false` | `true` yapıldığında dosyalar dış URL'e açılmaz; tüm indirme, önizleme ve thumbnail'ler Symfony Voter yetki kontrolüyle (`DownloadController`) servis edilir. | Fatura, kimlik, sözleşme, VIP içerik gibi gizli dosyalarda **mutlaka `true`**. |
| `cache_ttl` | `null` *(miras)* | Sadece bu volume için önbellek süresi (saniye). `0` = bu volume'de önbellek kapalı. `null` = profil/default ayarını miras alır. | Aynı profilde yerel disk `3600` sn kalırken bulut diskini `60` sn veya `0` yapmak için. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(STS geçici oturum anahtarı)*, `version` *(varsayılan `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(özel endpoint varsa otomatik `true`, standart AWS S3'te varsayılan `false` — Bucket owner enforced için elle `true` verin)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Ayrıntılı bulut kurulumu için [Bölüm 07](07-depolama-s3-ve-dinamik-yapilandirma.md). |

---

## 3. `profiles` — Profil Parametreleri Sözlüğü

### 3.1. Temel Depolama, Filtreleme & Kota
| Parametre | Varsayılan | Ne İşe Yarar? | Ne Zaman Değiştirilir? |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Bu profile özel arayüz ve hata mesajı dili (`'tr'`, `'en'`, `'fr'` vb.; `null` ise `defaults.locale` → Symfony request dili → `'en'`). | Aynı projede farklı dilli panellerde ayrı profil kullanırken. |
| `volumes` | `[]` | Bu profilde erişilebilecek volume anahtarlarının listesi (ör. `['local_uploads', 'supabase_s3']`). İlk eleman başlangıç diskidir. | Çoklu disk veya bulut bağlamak için. |
| `ignore_dot_files` | `true` | `.env`, `.htaccess`, `.DS_Store`, `.trash` gibi nokta ile başlayan gizli dosyaları hem listeden hem doğrudan erişimden engeller. | Güvenlik için `true` bırakın. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Regex veya wildcard kalıplarına uyan dosya/klasörleri gizler ve doğrudan erişimi engeller. | Sistem klasörlerini (`/^cache$/i`, `/^backups$/i`) kullanıcıdan saklamak için. |
| `sort_by` | `'name'` | Varsayılan sıralama alanı: `'name'` (ad), `'date'` (tarih), `'size'` (boyut), `'type'` (uzantı/tür). | En son yüklenenlerin en üstte çıkması için `'date'` + `sort_direction: 'desc'`. |
| `sort_direction` | `'asc'` | Sıralama yönü: `'asc'` (A→Z / küçükten büyüğe) veya `'desc'` (Z→A / en yeni en üstte). | Galeri ve haber arşivlerinde `'desc'` çok pratiktir. |
| `folders_first` | `true` | Klasörleri her zaman dosyaların üstünde tutar. | Karışık alfabetik/tarih sıralama isterseniz `false`. |
| `storage_quota` | `null` | Profilin toplam disk kullanım üst sınırı (ör. `'500M'`, `'2G'`). Dolduğunda yeni yükleme sunucuda reddedilir ve sol menüde doluluk çubuğu görünür. | Multi-tenant SaaS veya kullanıcı bazlı (`{user_id}`) alan sınırı koymak için. |
| `upload_subfolder` | `null` | Yüklenen dosyaların mevcut dizin altında otomatik yönlendirileceği alt klasör şablonu (ör. `'{Y}/{m}'`). | Tüm yüklemelerin otomatik `2026/11` şeklinde tarih klasörlerine ayrılması için. |

### 3.2. Yükleme, Parçalı Yükleme (`chunk_upload`) & Yinelenen Dosya (`duplicate_detection`)
| Parametre | Varsayılan | Ne İşe Yarar? | Ne Zaman Değiştirilir? |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(tümü)* | İzin verilen MIME türleri (ör. `['image/jpeg', 'image/png', 'image/webp']`). Boşsa `upload_deny` dışındaki güvenli türlere izin verilir. | Sadece görsel veya sadece PDF kabul eden formlarda mutlaka doldurun. |
| `upload_deny` | **47 tehlikeli MIME türü** | Kesinlikle engellenen MIME türleri (PHP, HTML, SVG dışındaki XML, JS, Shell, EXE, Java, CGI vb. — sunucuda magic-bytes ile doğrulanır). | Ek dosya türlerini yasaklamak isterseniz. |
| `upload_max_size` | `'10M'` | Tek bir dosyanın izin verilen **toplam** boyutu (ör. `'200M'`, `'1G'`). Parçalı yükleme açıksa PHP'nin `2M` limitini aşan dosyalar bu sınıra kadar güvenle yüklenir. | Video/katalog PDF profillerinde `'200M'`–`'500M'`, avatar profilinde `'2M'` yapın. |
| `overwrite_policy` | `'rename'` | Aynı isimde dosya varsa davranış: `'rename'` (`foto-1.jpg` yapar), `'overwrite'` (üzerine yazar), `'reject'` (hata verir). | Veri kaybını önlemek için `'rename'` en güvenlisidir. |
| `chunk_upload.enabled` | `true` | Büyük dosyaları küçük parçalara bölüp yükler (Duraklat / Devam Et / İptal Onayı + ağ kopmasında otomatik yeniden deneme). | Yalnızca çok küçük dosyalar (< 1 MB) için isterseniz kapatabilirsiniz. |
| `chunk_upload.chunk_size` | `'2M'` | Her parçanın boyutu. Sunucudaki PHP `upload_max_filesize` ve `post_max_size` değerlerinin **%80'ine** otomatik kırpılır (ör. PHP 2M ise parça 1.60 MB olur). | Sunucu limitiniz yüksekse (ör. 32M) `'5M'` yapmak büyük dosya yüklemesini hızlandırır. |
| `chunk_upload.parallel_uploads` | `3` | Aynı anda kaç dosyanın paralel yükleneceği (`1`–`10`). | Zayıf sunucularda `2`, güçlü bulut/CDN bağlantısında `4`–`5`. |
| `chunk_upload.max_retries` | `3` | Parça yüklemesi ağ hatasıyla düşerse artan bekleme süresiyle (exponential backoff) kaç kez tekrar deneneceği (`0`–`10`). | Mobil/kararsız bağlantılar için `3`–`5` idealdir. |
| `duplicate_detection.enabled` | `false` | SHA-256 içerik özetiyle aynı dosyanın tekrar yüklenmesini tespit eder. | Aynı ürün görselinin defalarca yüklenmesini önlemek için `true`. |
| `duplicate_detection.policy` | `'warn'` | Yinelenen dosya bulunduğunda: `'warn'` (yükler + uyarır), `'reject'` (yüklemeyi reddeder), `'reuse'` (tekrar kaydetmeden mevcut dosyayı döndürür — disk tasarrufu!). | E-ticaret ve CMS'de `'reuse'` harika disk tasarrufu sağlar. |
| `duplicate_detection.max_scan_files` | `5000` | Yinelenen dosya hash indeksinde taranacak en fazla dosya sayısı (`100`–`50000`). Aşırı büyük arşivlerde CPU/I/O taşmasını önler. | Çok büyük medya kütüphanelerinde `10000`–`25000` yapılabilir. |

### 3.3. Otomatik İsimlendirme (`naming`)
| Parametre | Varsayılan | Ne İşe Yarar? | Örnek (`"Ürün Fotoğrafı 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Türkçe/çok dilli özel karakterleri ve boşlukları URL-dostu ASCII slug'a çevirir. | `urun-fotografi-2026.jpg` |
| `naming.slugger_locale` | `null` | Slug dönüştürmede kullanılacak dil (`null` ise profil dilini alır; `'tr'` iken `İ/ı/ş/ğ/ç/ö/ü`, `'de'` iken `ä/ö/ü/ß` dile uygun çevrilir). | Türkçe projelerde `'tr'` verin. |
| `naming.transformer` | `'none'` | Büyük/küçük harf kuralı: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'`, `'folder'` (bulunduğu klasörün adını dosya adı yapar!). | `'snake'` → `urun_fotografi_2026.jpg`, `'folder'` → `termoslar-1.jpg` |
| `naming.prepend` / `append` | `''` | Dosya adının başına veya sonuna otomatik metin/tarih/kimlik ekler (`{Y}`, `{m}`, `{d}`, `{uniqid}`). | `prepend: '{Y}{m}_'` → `202611_urun.jpg` |
| `naming.apply_on_rename` | `true` | Kullanıcı arayüzden "Ad Değiştir" yaptığında da `slugger`/`transformer` kurallarını uygular. | Kullanıcının boşluklu/özel karakterli dosya adı girmesini istemiyorsanız `true`. |
| `naming.apply_affixes_on_rename` | `false` | Ad değiştirirken `prepend`/`append` eklerinin tekrar yapıştırılmasını engeller. | `false` kalması önerilir. |
| `naming.allow_extension_change` | `false` | Ad değiştirirken `.jpg` → `.php` veya `.png` gibi uzantı değişimini engeller. | Güvenlik için `false` bırakın. |

### 3.4. Görsel Optimizasyon (`image_optimization`) & Çözünürlük/Oran Kısıtları (`image_constraints`)
- **`image_optimization`:** Yüklenen büyük fotoğrafları sunucuda otomatik düzeltir, EXIF temizler ve küçültür.
- **`image_constraints`:** İstenen standartlara uymayan görselleri **hem tarayıcıda 0ms'de hem sunucuda** reddeder (ör. küçük/bulanık veya kare olmayan ürün fotoğrafını engellemek için).

| Parametre | Varsayılan | Ne İşe Yarar? | Ne Zaman Kullanılır? |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Yükleme anında otomatik görsel optimizasyonunu açar (Imagick → GD → yoksa dokunmadan geçer). | Kullanıcıların büyük telefon fotoğrafı yüklediği tüm profillerde `true`! |
| `image_optimization.auto_orient` | `true` | Cep telefonu fotoğraflarının EXIF yön bilgisini okuyup yan duran görselleri otomatik düzeltir. | Mobil yüklemeler için `true`. |
| `image_optimization.strip_exif` | `true` | Fotoğraftaki GPS konum ve kamera bilgisini (EXIF) temizler (KVKK/GDPR gizlilik + boyut kazancı). | Halka açık tüm görsellerde `true`. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Bundan büyük görselleri oranını bozmadan bu piksel sınırına küçültür (`>= 100`). | Web görselleri için `1920`×`1920` idealdir. |
| `image_optimization.convert_to_webp` | `false` | JPEG/PNG görselleri otomatik WebP formatına çevirir (%30–60 boyut kazancı). | Modern web performans profillerinde `true`. |
| `image_optimization.quality` | `85` | Sıkıştırma kalitesi (`10`–`100`). | `80`–`85` gözle fark edilmeyen en iyi denge. |
| `image_constraints.min_width` / `min_height` | `null` | Görselin sahip olması gereken **en düşük** genişlik/yükseklik (piksel). | Bulanık küçük görsel yüklenmesini engellemek için (ör. kapak görseli `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Görselin aşamayacağı **en yüksek** çözünürlük (piksel). (`image_optimization` açıksa küçültme sonrası kontrol edilir.) | Kesin piksel sınırı olan baskı/banner şablonlarında. |
| `image_constraints.aspect_ratio` | `null` | Zorunlu en-boy oranı: `'1:1'` (kare), `'16:9'` (geniş banner), `'4:3'`, `'3:4'`, `'1.91:1'` vb. | E-ticaret kare ürün kartları (`'1:1'`) veya YouTube/haber kapakları (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Oran sapma payı (`0.0`–`0.5`; `0.05` = %5 tolerans, ör. `1920×1080` ile `1280×722` ikisi de geçer). | Genellikle `0.05` idealdir. |

### 3.5. Thumbnail, Çöp Kutusu (`trash`), ZIP (`zip`) & İmzalı Paylaşım (`share`)
| Parametre | Varsayılan | Ne İşe Yarar? |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Görseller için küçük önizleme önbelleği: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'`, `'none'`. Bulut görselleri 1 kez okunup yerele önbelleklenir. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Sunucuda GD ve Imagick yoksa ne yapılacağı: `'fallback_original'` (orijinal görseli göster), `'icon'` (dosya ikonu göster), `'exception'` (hata fırlat). |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Üretilen thumbnail boyutları (`>= 32`), çıktı formatı (`webp`, `jpeg`, `png`) ve sığdırma modu (`contain` = tam görünüm, `cover` = kırparak doldur). `ui.item_size: 'xl'` için `480`×`360` yapabilirsiniz. |
| `thumbnail.lazy_load` | `true` | Kart görsellerine tarayıcı seviyesinde `loading="lazy"` ekler (çok görselli klasörlerde açılışı hızlandırır). |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Üretilen küçük resimlerin tutulduğu yerel önbellek klasörü. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Silinen dosyaları hemen yok etmek yerine çöp kutusuna taşır. MIME-kısıtlı bulut bucket'larında üst veriyi otomatik olarak yerel `trash-meta.json`'a yedekler. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Çoklu seçilen dosyaları tek tıkla ZIP olarak indirme (PHP `ext-zip` gerekir). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | 7 katmanlı Zip-Slip & Zip-Bomb korumalı arşiv çıkarma. `allow_extract: false` iken çıkarma her zaman engellenir; `true` yapıldığında `permissions.extract_zip` varsayılanı da otomatik `true` olur (ister rol ister `false` ile daraltabilirsiniz). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Symfony `UriSigner` ile HMAC imzalı, süreli paylaşım linki üretir. `enabled: false` yapıldığı anda önceden üretilmiş tüm linkler anında geçersizleşir. |

### 3.6. Arayüz (`ui`) Ayarları — Görünüm, Ebatlar & Araç Çubuğu
| Parametre | Varsayılan | Ne İşe Yarar? | Ne Zaman Değiştirilir? |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Bu profile özel tema (ör. admin panelde `'bootstrap5'`, müşteri portalında `'shadcn'`). | Aynı projede farklı panellerde farklı tasarım gerekiyorsa. |
| `ui.color_scheme` | `null` *(defaults)* | Profile özel renk şeması (`auto`, `light`, `dark`). | Belirli bir sayfa hep koyu temadaysa `'dark'`. |
| `ui.icon_set` | `'classic'` | Dosya/klasör ikon stili: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` veya özel SVG klasör yolu (`'%kernel.project_dir%/assets/fm-icons'`). | Masaüstü görünümü için `'windows11'` veya `'macos'`, özel kurumsal ikonlar için klasör yolu. |
| `ui.z_index` | `null` *(defaults)* | Profile özel modal/overlay katman önceliği. | Bu profilin açıldığı sayfada yüksek `z-index`'li bir editör/sidebar varsa. |
| `ui.mobile_dialog_mode` | `'auto'` | Mobilde (`<= 767.98px`) Modal ve OffCanvas açılış davranışı: `'auto'` (modal & yan offcanvas tam ekran, alt/üst offcanvas yuvarlatılmış native sheet), `'fullscreen'` (tümü tam ekran), `'sheet'` (tümü alttan açılan native bottom sheet). | Mobilde tüm seçicileri hep alt çekmece (`'sheet'`) veya hep tam ekran (`'fullscreen'`) açmak isterseniz. |
| `ui.default_view` | `'grid'` | Başlangıç görünümü: `'grid'` (kart ızgarası) veya `'list'` (tıklanabilir sütun başlıklı tablo). | PDF/evrak ağırlıklı arşivlerde `'list'`, görsel galerilerde `'grid'`. |
| `ui.item_size` | `'md'` | Grid kart boyutu ve liste önizleme ölçeği: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) veya özel piksel (`96`–`320`). `132px` ve altında boşluklar otomatik kompaktlaşır. | Ürün fotoğrafı/tasarım seçerken `'lg'` veya `'xl'`; sade seçicilerde `'sm'` / `'xs'`. |
| `ui.card_aspect_ratio` | `'4/3'` | Dosya/klasör kartının önizleme çerçeve oranı: `'1/1'` (kare), `'4/3'` (standart), `'16/9'` (geniş banner/video), `'3/4'` (dikey afiş/katalog). | E-ticaret kare fotoğraflarda `'1/1'`, video/banner kütüphanesinde `'16/9'`. |
| `ui.folder_style` | `'tile'` | Grid görünümünde klasörlerin stili: `'tile'` (Google Drive tarzı üstte kompakt yatay şeritler) veya `'card'` (Windows/Finder tarzı dosyalarla aynı boyutta büyük klasör kartları). | Klasörleri görsel olarak daha belirgin göstermek için `'card'`. |
| `ui.show_size_slider` | `true` | Ayarlar menüsünde kullanıcının kart boyutunu anlık büyütüp küçültebileceği kaydırıcıyı (`96px`–`320px`) gösterir. | Sabit standart görünüm zorlamak isterseniz `false`. |
| `ui.volume_order` | `'local_first'` | Sol ağaç ve üst seçici sıralaması: `'local_first'` (Yerel üstte), `'network_first'` (Bulut üstte), `'configured'` (grup ayırıcısı olmadan `volumes: [...]` dizisindeki birebir sıra). | Bulut-öncelikli projelerde `'network_first'` veya `'configured'`. |
| `ui.show_folder_tree` | `true` | Sol klasör ağacı panelini açıp kapatır. | Tek düz klasörlü basit TinyMCE/avatar seçicilerinde `false`. |
| `ui.tree_lines` | `true` | Sol klasör ağacında hiyerarşi bağlantı çizgileri. | Daha minimalist görünüm için `false`. |
| `ui.show_folder_item_count` | `true` | Klasörlerin yanında içerdiği öğe sayısını rozet olarak gösterir (`12`). Yetkilendirme açıkken kullanıcının erişemediği alt klasörleri sızdırmadan yalnızca görünür alt ağaçtan hesaplanır. | Klasör doluluğunu hızlı görmek için `true`. |
| `ui.folder_count_mode` | `'recursive_files'` | Sayaç hesaplama yöntemi: `'recursive_files'` (alt klasörler dahil tüm dosyalar), `'files'` (sadece doğrudan içindeki dosyalar), `'items'` (dosya + alt klasör). | Hızlı doğrudan sayı isterseniz `'files'`. |
| `ui.inspector_mode` | `'on_select'` | Sağ bilgi/önizleme paneli: `'on_select'` (öğe seçilince açılır), `'always'` (hep açık), `'hidden'` (tamamen gizli). | Dar modal seçicilerde `'hidden'`. |
| `ui.folder_open_on` | `'dblclick'` | Klasöre giriş hareketi: `'dblclick'` (tek tık seçer, çift tık açar — masaüstü alışkanlığı) veya `'click'` (tek tıkla hemen açar — web/mobil). | Tek tıkla hızlı gezinme isteyen admin panellerinde `'click'`. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Sayfa başına gösterilen öğe sayısı (`6`–`200`) ve kullanıcının seçebileceği limit seçenekleri. | Yoğun kataloglarda `per_page: 48`, `[24, 48, 96, 150]`. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Sol menüdeki disk doluluk çubuğunu ve tıklandığında açılan Ayrıntılı Disk Kullanımı modalını gösterir. (`false` yapılsa bile sunucudaki `storage_quota` limiti çalışmaya devam eder.) | Kotayı kullanıcıya göstermek istemiyorsanız `show_quota: false`. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | PDF Lightbox önizlemesini ve Video oynatıcıyı (hem arayüzde hem sunucu `DownloadController` seviyesinde) açıp kapatır. | PDF/video tarayıcıda açılmayıp doğrudan indirilsin istiyorsanız `false`. |
| `ui.remember_last_dir` | `true` | Kullanıcının son çalıştığı **volume + klasörü** `localStorage`'da hatırlar (silinmiş klasörlerde kendini otomatik üst klasöre/köke onarır). | Her açılışta mutlaka kök dizinden başlanmasını isterseniz `false`. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Silme ve sürükle-bırak taşıma öncesi onay penceresi gösterir. | Hızlı düzenleme akışında `confirm_move: false` yapabilirsiniz. |
| `ui.double_click_to_select` | `true` | Modal/OffCanvas seçici modunda dosyaya çift tıklayınca doğrudan seçip pencereyi kapatır. | Çift tıklamanın seçim yerine önizleme açması için `false`. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Sağ-tık menüsü ve boş alana sağ tıklayınca üstte çıkan `[ ← \| → \| ↑ \| ↻ ]` hızlı navigasyon çubuğu. | Sadeleştirilmiş kullanıcı ekranlarında `false`. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Dosyaları sürükleyip klasöre taşıma ve bilgisayardan dosya/klasör sürükleyip yükleme. | Salt okunur kütüphanelerde `false`. |
| `ui.keyboard_shortcuts` | `true` | Klavye kısayolları (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Delete`, `Space`, `/`, `R`, `F`, `?` vb.). | Ana sayfanızda kendi klavye kısayollarınızla çakışma olmaması için gerekirse `false`. |
| `ui.show_*` bayrakları | *(çoğu `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Arayüzü sadeleştirmek için istemediğiniz araç çubuğu elemanlarını tek tek gizleyebilirsiniz. *(Not: Ayarlar menüsündeki "Hakkında" ve "GitHub" bağlantıları yerleşik lisans/atıf alanlarıdır ve YAML ile kapatılamaz.)* |

### 3.7. Profil Bazlı Önbellek (`cache`), Güvenlik (`security`), Yetkiler (`permissions`), AI & Kullanım Koruması

#### Önbellek (`cache`)
| Parametre | Varsayılan | Açıklama |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Profil bazında önbelleği açar/kapatır. Profil `cache.enabled: false` iken `volume.cache_ttl` tek başına önbelleği zorla açmaz. |
| `cache.ttl` | `null` *(defaults)* | TTL öncelik sırası: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Güvenlik (`security`)
| Parametre | Varsayılan | Açıklama |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | `FileManagerVoter` ve özel Voter'lar üzerinden RBAC kontrolünü etkinleştirir. Canlıda daima `true` olmalıdır. |
| `security.csrf_protection` | `true` | Tüm `POST` isteklerinde `X-LiveUX-FM-CSRF` başlığını zorunlu kılar. |
| `security.sanitize_svg` | `true` | Yüklenen ve düzenlenen SVG'leri katı 55-etiketli izin listesiyle (`SvgSanitizer`) temizler. |
| `security.allow_remote_url_import` | `false` | Yükle modalında **"URL'den İçe Aktar"** sekmesini açar (SSRF & DNS rebinding korumalı `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Bu profildeki işlemleri PSR-3 audit günlüğüne yazar (`true` / `false` / `null`). |

#### 17 Granüler Yetki (`permissions`)
> [!WARNING]
> **Önemli (`true` değeri ne anlama gelir?):**  
> `permissions` altında bir yetkiye **`true`** vermek *"herkese (anonim ziyaretçilere) açık"* anlamına **GELMEZ**! `FileManagerVoter`, `true` değerinde **yalnızca giriş yapmış (`$token->getUser() !== null`) kullanıcıya** izin verir; giriş yapmamış anonim istekleri reddeder.  
> Ayrıca **`permissions.view`** temel kapıdır: bir kullanıcının `view` yetkisi yoksa `upload`, `delete` veya `download` gibi diğer tüm işlemler de otomatik reddedilir.

Her yetki şu 4 değerden birini alabilir:
- `true` → Giriş yapmış herhangi bir kullanıcı
- `false` → Hiç kimse (özellik tamamen kapalı)
- `'ROLE_ADMIN'` → Yalnızca bu role sahip kullanıcılar
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Bu rollerden herhangi birine sahip kullanıcılar

| Yetki Anahtarı | Varsayılan | Kontrol Ettiği İşlem |
| :--- | :--- | :--- |
| `view` | `true` | Listeleme, klasör ağacı, önizleme ve **tüm diğer yetkiler için temel ön şart**. |
| `upload` | `true` | Dosya yükleme, parçalı yükleme, görsel düzenleyiciden kaydetme ve URL'den içe aktarma. |
| `create_folder` | `true` | Yeni klasör oluşturma. |
| `rename` | `true` | Dosya ve klasör adı değiştirme. |
| `clone` | `true` | Dosya veya klasör çoğaltma. |
| `move` | `true` | Sürükle-bırak ve Kes/Yapıştır ile taşıma. |
| `copy` | `true` | Kopyala/Yapıştır işlemi. |
| `delete` | `true` | Dosya/klasör silme (çöp kutusuna taşıma veya kalıcı silme). |
| `trash_restore` | `true` | Çöp kutusundan geri yükleme. |
| `trash_purge` | `true` | Çöp kutusundan kalıcı silme veya tüm çöpü boşaltma. |
| `download` | `true` | Tekil dosya indirme, PDF/video akışı ve URL kopyalama. |
| `download_zip` | `true` | Çoklu seçimi `.zip` olarak indirme. |
| `extract_zip` | `null` *(otomatik)* | `zip.allow_extract: false` iken her zaman `false`'a zorlanır; `zip.allow_extract: true` yapıldığında otomatik `true` olur (isterseniz `'ROLE_ADMIN'` ile daraltın). |
| `share` | `true` | Süreli & HMAC imzalı paylaşım linki üretme. |
| `clear_cache` | `true` | Ayarlardan önbellek temizleme ve `?refresh=1` / `?fallback=1` önbellek yenileme. |
| `edit_metadata` | `true` | Dosya `alt`/`title`/`caption`/`tags`/odak noktası ve klasör rengi/ikonu/sabitleme. |
| `ai_enrich` | `true` | **✨ AI ile Öner** çağrısı (`view` + `edit_metadata` + `ai_enrich` birlikte gerekir). |

#### Yapay Zeka (`ai`) & Kullanım Koruması (`usage_guard`)
| Parametre | Varsayılan | Açıklama |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | AI metadata zenginleştirmeyi açar; `locale` ayarlanırsa AI çıktı dilini (ör. `'tr'`, `'en'`, `'de'`) belirler. |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Önerilecek maksimum etiket sayısı (`1`–`20`) ve token tasarrufu için küçültme piksel sınırı (`256`–`2048`). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Gizli (`private: true`) disklerdeki dosyaların dış AI API'sine gönderilmesini engeller; yükleme sonrası otomatik zenginleştirme (Bkz. [Bölüm 09](09-symfony-ai-entegrasyonu.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Veritabanında kullanılan dosyaların **silinmesini, yeniden adlandırılmasını, taşınmasını ve üzerine yazılmasını** engeller veya uyarır (Bkz. [Bölüm 10](10-kullanim-korumasi-usage-guard.md)). |

---

## 4. 5 Hazır Gerçek Dünya Profil Reçetesi (Kopyala-Yapıştır)

Hangi ayarı ne amaçla kullanacağınızı tek bakışta görmek için en yaygın 5 proje senaryosu:

### Reçete 1: E-Ticaret Ürün Kataloğu (Kare Kartlar + WebP + Duplicate Tasarrufu)
> **Amaç:** Editörler yalnızca net, kare (`1:1`) ürün fotoğrafı yüklesin; büyük telefon fotoğrafları otomatik küçülüp WebP olsun; aynı görsel tekrar yüklenirse disk dolmasın; aktif ürünlerdeki görseller yanlışlıkla silinemesin.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'tr'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # 204px büyük ürün kartları
                card_aspect_ratio: '1/1' # Kare vitrin görünümü
                folder_style: 'card'     # Kategoriler büyük klasör kartı
                show_folder_item_count: true
```

### Reçete 2: Blog / Haber Kapak Görseli (`16:9` Banner + AI Otomatik Alt Metin)
> **Amaç:** Sadece yatay `16:9` (en az `1200×630`px) yüksek kaliteli kapak görselleri kabul edilsin, her yükleme `2026/11` alt klasörüne düşsün ve AI otomatik SEO `alt`/etiket önersin.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # En yeni görseller en üstte
            ai:
                enabled: true
                locale: 'tr'
                max_tags: 6
            ui:
                item_size: 'xl'           # 260px geniş kapak önizleme
                card_aspect_ratio: '16/9' # Geniş banner oranı
```

### Reçete 3: Müşteri Evrak & PDF Kasası (Gizli Supabase/S3 + Kullanıcı İzolasyonu)
> **Amaç:** Her müşteri/bayi yalnızca kendi PDF faturalarını görsün, dış URL'den kimse erişemesin, bulut önbelleği 60 saniye olsun ve tüm silme/yükleme hareketleri denetim günlüğüne yazılsın.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Müşteri PDF Kasası'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Yetki kontrolsüz dış erişim yok!
            cache_ttl: 60          # Bulut değişimleri en geç 60 sn içinde yansır
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Her kullanıcı sadece kendi klasörünü görür
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # PDF evraklar için tıklanabilir sütunlu tablo
                item_size: 'sm'          # Kompakt liste satırları
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Reçete 4: TinyMCE / CKEditor / Tiptap Hızlı Görsel Seçici
> **Amaç:** Metin editöründen açılan sade, hızlı, dikkat dağıtmayan modal seçici — çift tıklayınca görseli doğrudan editöre gömsün.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Reçete 5: Salt Okunur Bayi / Basın Kiti Kütüphanesi
> **Amaç:** Bayiler yalnızca katalogları/logoları inceleyip tek tek veya toplu ZIP indirsin, imzalı paylaşım linki alabilsin; hiçbir dosyayı değiştiremesin veya silemesin.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 gün
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Dinamik Yol Yer Tutucuları (`DynamicPathResolver`)

Yerel `path`, `url_prefix`, bulut `driver_options.prefix` ve profil `upload_subfolder` alanlarında şu değişkenleri kullanabilirsiniz:

| Değişken | Açıklama | Örnek Çıktı |
| :--- | :--- | :--- |
| `{user_id}` | Giriş yapan Symfony kullanıcısının çakışmasız, dosya sistemi güvenli benzersiz kimliği (giriş yoksa `guest`). | `42` veya `ali_a--h-9f1c2e4b...` |
| `{Y}` | 4 haneli yıl | `2026` |
| `{m}` | 2 haneli ay | `11` |
| `{d}` | 2 haneli gün | `25` |

```yaml
volumes:
    user_space:
        label: 'Kişisel Alanım'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> **Çok-Kiracılı (Multi-Tenant) Çakışmasız `{user_id}` Güvenliği:**  
> `DynamicPathResolver`, e-posta (`ali.a@firma.com` vs `ali_a@firma.com`), büyük/küçük harf (`Alice` vs `alice`), Unicode kullanıcı adları (Arapça, Çince, Türkçe), rezerve isimler (`guest`, `user`, `--h-` sonekli adlar) ve Windows cihaz adları (`con`, `nul`, `com1`..`9`, `lpt1`..`9`) için **ayrık (disjoint) SHA-256 tabanlı `<slug>--h-<16-hex>`** kimlikleri üretir. Böylece iki farklı kullanıcının aynı klasöre düşmesi (tenant collision) matematiksel olarak imkansızdır.
>
> **CLI Hatırlatması:** `{user_id}` içeren diskler konsol komutlarında (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup` vb.) sahte `guest/` klasörü oluşturmamak için otomatik olarak atlanır.

---

## 6. Tam Yapılandırma Referansı (Tüm Aktif Parametreler Tek Örnekte)

Aşağıdaki YAML bloğu, `Configuration.php` içindeki **tüm aktif parametreleri** varsayılan/örnek değerleri ve ne işe yaradıklarını açıklayan satır içi yorumlarla tek bir dosyada gösterir. Projenizde yalnızca değiştirmek istediğiniz satırları yazmanız yeterlidir; yazmadığınız her satır otomatik varsayılan değerini alır.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) GLOBAL VARSAYILANLAR (defaults) — Tüm profillerin miras aldığı ayarlar
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Tek-diskli kısa profillerde varsayılan kök dizin
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <özel>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = Symfony request/translator dili (yoksa garantili 'en') | 'tr' | 'en' | 'fr' vb.
        z_index: 1050                                      # Overlay/popover/modal temel z-index değeri (>= 1)
        auto_inject_dialog_host: true                      # Modal/OffCanvas taşıyıcısını tam HTML sayfalara otomatik ekle
        dialog_host_profile: 'default'                     # Otomatik eklenen DialogHost'un varsayılan profili

        audit_log:
            enabled: false                                 # 9 dosya/klasör olayını PSR-3 log kanalına yaz
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Profilde model belirtilmezse kullanılacak AI modeli
            platform_service: null                         # Özel Symfony AI PlatformInterface servis ID'si (null = otomatik)

        cache:
            enabled: true                                  # Global dizin/ağaç önbelleğini aç/kapat
            pool: 'cache.app'                              # Kullanılacak PSR-6 Symfony cache havuzu
            ttl: 3600                                      # Global önbellek süresi (saniye; 0 = önbelleksiz)

        metadata:
            enabled: true                                  # Klasör rengi/ikonu/sabitleme ve dosya alt/etiket/odak noktası
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # json_file sürücüsünün kayıt dosyası
            connection: null                               # doctrine sürücüsünde özel DBAL bağlantı adı (null = entity_manager bağlantısı)
            entity_manager: 'default'                      # doctrine sürücüsünde kullanılacak bağlantı/EntityManager adı
            table: 'liveux_fm_metadata'                    # doctrine sürücüsünün DBAL tablo adı (bkz. Bölüm 13)
            auto_create_table: true                        # doctrine sürücüsünde tablo yoksa otomatik oluştur (migration için false)

    # =========================================================================
    # 2) DEPOLAMA DİSKLERİ / HACİMLER (volumes) — Yerel & Bulut Disk Tanımları
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Yerel Depolama'                        # Sol menü ve yol çubuğundaki görünen ad
            icon: null                                     # null = otomatik | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = otomatik (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Yerel disk yolu ({user_id}, {Y}, {m}, {d} destekler)
            url_prefix: '/uploads'                         # Tarayıcının dosyaya erişeceği public URL öneki (private ise null)
            private: false                                 # true = dosyalar yalnızca yetki kontrollü DownloadController ile akar
            cache_ttl: null                                # Bu diske özel cache süresi (sn); null = profilden miras, 0 = kapalı
            driver_options: []                             # Yerel disk için boş bırakılır

        cloud_s3_example:
            label: 'Bulut S3 / R2 / Supabase'
            icon: 'supabase'                               # İkon setindeki resources/icons/_ui/supabase.svg ikonu
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # Public bucket/CDN URL'i (private: true ise null bırakın)
            private: false
            cache_ttl: 60                                  # Dışarıdan değişebilen bulut bucket için 60 sn önbellek
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Bucket adı
                region: '%env(AWS_S3_REGION)%'             # Bölge (Cloudflare R2 için 'auto')
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # AWS dışı sağlayıcılar için S3 endpoint URL'i
                prefix: 'media/{Y}'                        # Opsiyonel bucket içi kök alt dizin (dinamik yer tutucu destekler)
                use_path_style_endpoint: true              # Supabase / MinIO / R2 / B2 için true
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Opsiyonel: AWS STS geçici oturum anahtarı
                version: 'latest'                          # AWS S3 API sürümü (varsayılan: 'latest')
                ca_bundle: null                            # Opsiyonel: özel CA sertifika .pem yolu (Windows cURL 60 çözümü)
                disable_acl: true                          # Özel endpoint'te otomatik true; standart AWS S3 Bucket owner enforced için elle true verin
                retain_visibility: false                   # Özel endpoint'te otomatik false (x-amz-acl göndermeyi engeller)
                request_checksum_calculation: 'when_required'  # AWS SDK >= 3.337 uyumu (özel endpoint'te otomatik)
                response_checksum_validation: 'when_required'  # AWS SDK >= 3.337 uyumu (özel endpoint'te otomatik)

        custom_flysystem_example:
            label: 'SFTP Arşiv'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # League\Flysystem\FilesystemOperator servis ID'si

    # =========================================================================
    # 3) PROFİLLER (profiles) — Her Kullanım Senaryosu İçin Bağımsız Kural Seti
    # =========================================================================
    profiles:
        full_reference:
            # --- Bağlı Diskler, Dil & Kısa Tek-Disk Alanları ---
            locale: null                                   # null = defaults.locale miras alır | 'tr' | 'en' | 'fr' vb.
            volumes: ['local_uploads', 'cloud_s3_example'] # Boş bırakılırsa alttaki driver/path ile örtük tek disk oluşur
            driver: 'LocalFileSystem'                      # Yalnızca volumes: [] iken kullanılan kısa yol sürücüsü
            path: '%kernel.project_dir%/public/uploads'    # Yalnızca volumes: [] iken kullanılan kısa yol dizini
            url_prefix: '/uploads'                         # Yalnızca volumes: [] iken kullanılan kısa yol URL öneki
            private: false                                 # Yalnızca volumes: [] iken kullanılan gizlilik bayrağı
            driver_options: []                             # Yalnızca volumes: [] iken kullanılan sürücü ayarları

            # --- Listeleme, Gizleme ve Varsayılan Sıralama ---
            ignore_dot_files: true                         # Nokta ile başlayan (.env, .htaccess, .git) gizli dosyaları gizle/engelle
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Regex veya isim kalıplarıyla listeden ve erişimden gizle
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Klasörleri daima dosyalardan önce listele

            # --- Yükleme, Kota ve Çakışma Kuralları ---
            upload_subfolder: null                         # Örn: '{Y}/{m}' — tüm yüklemeleri dinamik alt klasöre yönlendir
            upload_allow: []                               # Boş = yasaklılar hariç her şey; dolu = yalnızca bu MIME tipleri
            upload_deny:                                   # Her koşulda reddedilecek tehlikeli MIME tipleri (varsayılan 47 tür; belirtilmezse şemadan miras alınır)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Tek dosyanın birleştirilmiş toplam boyut limiti (ör. '50M', '2G')
            storage_quota: null                            # Profil toplam disk kotası (ör. '500M', '5G'; null = limitsiz)
            overwrite_policy: 'rename'                     # rename (otomatik -1 ekle) | overwrite (üzerine yaz) | reject (reddet)

            # --- Parçalı Yükleme (PHP upload_max_filesize Sınırını Aşma) ---
            chunk_upload:
                enabled: true                              # Büyük dosyaları parçalara bölerek yükle
                chunk_size: '2M'                           # Hedef parça boyutu (PHP limitinin %80'ine otomatik kısıtlanır)
                parallel_uploads: 3                        # Aynı anda yüklenecek maksimum dosya sayısı (1..10)
                max_retries: 3                             # Ağ kopmasında parça başına otomatik tekrar deneme (0..10)

            # --- Yinelenen Dosya Tespiti (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Aynı içeriğin tekrar yüklenmesini algıla
                policy: 'warn'                             # warn (uyar) | reject (reddet) | reuse (mevcut dosyayı döndür)
                max_scan_files: 5000                       # Hash indeksi tarama üst sınırı (100..50000)

            # --- Otomatik Dosya Adı Normalleştirme ---
            naming:
                slugger: true                              # Türkçe/Unicode karakterleri güvenli ASCII slug'a çevir
                slugger_locale: null                       # null = profil dilini miras al (ör. 'tr' => ş->s, ğ->g)
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Dosya adının başına eklenecek sabit/şablon önek (ör. 'urun-')
                append: ''                                 # Uzantıdan önce eklenecek sonek (ör. '-{Y}{m}{d}')
                apply_on_rename: true                      # Yeniden adlandırmada slugger/transformer kurallarını uygula
                apply_affixes_on_rename: false             # Yeniden adlandırmada prepend/append eklerini tekrar uygula
                allow_extension_change: false              # Kullanıcının dosya uzantısını (.jpg -> .php) değiştirmesine izin ver

            # --- Otomatik Görsel Optimizasyonu (Yükleme Anında) ---
            image_optimization:
                enabled: false                             # Görselleri yüklerken otomatik döndür, küçült ve sıkıştır
                auto_orient: true                          # Telefon EXIF yönünü düzelt (yan dönük fotoğrafı engelle)
                strip_exif: true                           # GPS / kamera EXIF verilerini gizlilik ve boyut için temizle
                max_width: 2400                            # Aşılırsa orantılı küçültülecek azami genişlik (px, >= 100)
                max_height: 2400                           # Aşılırsa orantılı küçültülecek azami yükseklik (px, >= 100)
                convert_to_webp: false                     # JPEG/PNG görselleri otomatik WebP'ye dönüştür
                quality: 85                                # Çıktı sıkıştırma kalitesi (10..100)

            # --- Görsel Çözünürlük & En-Boy Oranı Kuralları ---
            image_constraints:
                min_width: null                            # Minimum genişlik (px; ör. 800)
                min_height: null                           # Minimum yükseklik (px; ör. 600)
                max_width: null                            # Maksimum genişlik (px; image_optimization sonrası kontrol edilir)
                max_height: null                           # Maksimum yükseklik (px)
                aspect_ratio: null                         # Zorunlu oran (ör. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # İzin verilen oran sapma payı (0.0..0.5; 0.05 = %5)

            # --- Küçük Resim (Thumbnail) Motoru ---
            thumbnail:
                enabled: true                              # Görsel kartları için optimize önizleme üret
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Thumbnail genişliği (px, >= 32)
                height: 240                                # Thumbnail yüksekliği (px, >= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Sıkıştırma kalitesi (10..100)
                fit: 'contain'                             # contain (tamamını göster) | cover (kırparak doldur)
                lazy_load: true                            # Tarayıcıda loading="lazy" uygula
                cache_dir: '%kernel.project_dir%/public/media-cache' # Üretilen thumbnail'ların yerel önbellek dizini

            # --- Çöp Kutusu (Soft-Delete) ---
            trash:
                enabled: true                              # Silinenleri önce çöp kutusuna taşı (false = kalıcı sil)
                directory: '.trash'                        # Çöp kutusu gizli klasör adı
                retention_days: 30                         # file-manager:trash:purge için saklama süresi (gün)
                max_size: '500M'                           # Çöp kutusu toplam boyut üst sınırı

            # --- ZIP İndirme & Arşiv Çıkarma ---
            zip:
                enabled: true                              # Çoklu dosya/klasör ZIP indirmeyi aç
                max_files: 100                             # Tek ZIP arşivinde en fazla dosya sayısı
                max_total_size: '250M'                     # Tek ZIP arşivinin toplam boyut limiti
                allow_extract: false                       # Arşiv çıkarma (Unzip) izni — varsayılan kapalı (7 katman korumalı)
                max_extract_files: 200                     # Çıkarılabilecek maks. dosya sayısı (1..2000)
                max_extract_size: '100M'                   # Çıkarılabilecek toplam boyut (Zip-Bomb koruması)
                max_compression_ratio: 100                 # Sıkıştırma oranı üst limiti (1..1000 — Zip-Bomb koruması)

            # --- İmzalı & Süreli Paylaşım Linkleri (HMAC) ---
            share:
                enabled: true                              # Süreli imzalı paylaşım linki üretimine izin ver
                default_ttl: 86400                         # Varsayılan geçerlilik süresi (sn; 86400 = 24 saat)
                max_ttl: 2592000                           # Seçilebilecek azami süre (sn; 2592000 = 30 gün)
                allow_inline_preview: true                 # Güvenli dosya türlerinde tarayıcıda satır içi önizlemeye izin ver

            # --- Opsiyonel Symfony AI Zenginleştirme ---
            ai:
                enabled: false                             # Metadata modalında "✨ AI ile Öner" butonunu aç
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = profil/istek dili (ör. 'tr' => Türkçe alt metin/etiket üret)
                max_tags: 6                                # Önerilecek maks. etiket sayısı (1..20)
                max_image_dimension: 1024                  # AI'a gönderilmeden önce küçültme limiti (256..2048; token tasarrufu)
                block_private_volumes: true                # true = private: true disklerde AI çağrısını engelle (varsayılan: true)
                auto_enrich_on_upload: false               # Yükleme sonrası otomatik AI zenginleştirme

            # --- Silme/Taşıma/Üzerine Yazma Kullanım Koruması (Usage Guard) ---
            usage_guard:
                enabled: false                             # Silme/ad değiştirme/taşıma/üzerine yazma öncesi DB referanslarını kontrol et
                policy: 'warn'                             # warn (uyar + devam et) | block (işlemi engelle)
                enforce_server_side: true                  # Doğrudan API çağrılarında da sunucu tarafında zorla
                max_references: 20                         # Modalda listelenecek maks. kayıt referansı (1..200)

            # --- Profil Bazlı Önbellek ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | saniye (0 = kapalı)

            # --- Güvenlik Ayarları ---
            security:
                voter_enabled: true                        # FileManagerVoter RBAC kontrolünü aç/kapat
                csrf_protection: true                      # POST isteklerinde X-LiveUX-FM-CSRF zorunluluğu
                sanitize_svg: true                         # Yüklenen SVG'lerden zararlı kod/script temizle
                allow_remote_url_import: false             # "URL'den Aktar" sekmesini aç (SSRF korumalı)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 Granüler RBAC Yetkisi (true = giriş yapmış kullanıcı | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Dosya/klasör listeleme ve tüm işlemlerin temel kapısı
                upload: true                               # Yeni dosya yükleme ve URL import
                create_folder: true                        # Yeni klasör oluşturma
                rename: true                               # Yeniden adlandırma
                clone: true                                # Dosya/klasör çoğaltma
                move: true                                 # Taşıma (sürükle-bırak ve kes-yapıştır dahil)
                copy: true                                 # Kopyalama (kopyala-yapıştır dahil)
                delete: true                               # Silme (çöpe taşıma veya kalıcı silme)
                trash_restore: true                        # Çöp kutusundan geri yükleme
                trash_purge: true                          # Çöp kutusundan kalıcı silme / çöpü boşaltma
                download: true                             # Tekil dosya indirme ve URL kopyalama
                download_zip: true                         # Çoklu ZIP indirme
                extract_zip: false                         # ZIP arşiv çıkarma (ayrıca zip.allow_extract: true gerekir)
                share: true                                # İmzalı paylaşım linki oluşturma
                clear_cache: true                          # Ayarlardan önbellek temizleme ve ?refresh=1
                edit_metadata: true                        # Dosya alt/etiket/odak noktası ve klasör rengi/ikonu/sabitleme
                ai_enrich: true                            # AI ile otomatik metadata önerme

            # --- Kullanıcı Arayüzü (UI) Bayrakları ve Ebatları ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <özel dizin>
                z_index: null                              # null = defaults.z_index | tam sayı (>= 1)
                mobile_dialog_mode: 'auto'                 # Mobilde (<= 767px) dialog davranışı: auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px otomatik kompakt)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (kompakt şerit) | card (büyük klasör kartı)
                show_size_slider: true                     # Ayarlarda canlı boyut kaydırıcısı ve klasör stili seçiciyi göster
                folder_open_on: 'dblclick'                 # dblclick (çift tıkla aç) | click (tek tıkla aç)
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Sol klasör ağacı panelini göster
                tree_lines: true                           # Klasör ağacında hiyerarşi çizgilerini göster
                show_folder_item_count: true               # Klasörlerde öğe sayısı rozetini göster
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Sol menü kota çubuğu ve üst kota hapını göster
                show_quota_details: true                   # Tıklanabilir ayrıntılı disk kullanımı modalını aç
                per_page: 24                               # Sayfa başına varsayılan öğe sayısı (6..200)
                per_page_options: [24, 48, 96]             # Sayfa boyutu açılır menü seçenekleri
                show_perf_badge: false                     # Üst çubukta CACHE HIT / DISK süre rozetini göster
                show_nav_buttons: true                     # Geri / İleri / Üst / Yenile grubunun ana anahtarı
                show_history_buttons: true                 # Geri / İleri geçmiş butonlarını göster
                show_up_button: true                       # Üst klasör butonunu göster
                show_refresh_button: true                  # Yenile (?refresh=1) butonunu göster
                show_breadcrumbs: true                     # Tıklanabilir/sürükle-bırak yol çubuğunu göster
                show_search: true                          # Arama kutusu ve derin arama butonunu göster
                show_type_filter: true                     # Tür filtresi (Görsel / Video / Dosya / Klasör) göster
                show_view_toggle: true                     # Grid / Liste geçiş butonlarını göster
                show_pagination: true                      # Alt sayfalama çubuğunu göster
                show_per_page_selector: true               # Sayfa başına öğe seçicisini göster
                show_sort: true                            # Sıralama seçicisi ve yön butonunu göster
                show_fullscreen_button: true               # Tam ekran butonunu göster
                show_settings_button: true                 # Ayarlar (⚙) açılır menüsünü göster
                show_footer: true                          # Alt durum çubuğunu göster
                show_context_nav: true                     # Boş alan sağ tık menüsünde hızlı gezinme barını göster
                allow_dropzone_upload: true                # İçerik alanına bilgisayardan sürükle-bırak yükleme
                remember_last_dir: true                    # Son çalışılan disk ve klasörü hatırla (silindiyse otomatik iyileşir)
                confirm_delete: true                       # Silmeden önce onay penceresi göster
                confirm_move: true                         # Taşımadan/kopyalamadan önce onay penceresi göster
                double_click_to_select: true               # Modal/OffCanvas modunda çift tıklamayla dosyayı seçip kapat
                allow_context_menu: true                   # Sağ tık bağlam menüsünü aç
                allow_pdf_preview: true                    # PDF Lightbox önizlemesi (false = daima indirmeye zorlar)
                allow_video_preview: true                  # Video Inspector/Lightbox oynatıcısı (false = indirmeye zorlar)
                keyboard_shortcuts: true                   # Odak içi klavye kısayollarını aç
                allow_drag_move: true                      # Dosya/klasörleri sürükleyip başka klasöre taşımayı aç
```

---

[← Önceki: 01 — Gereksinimler ve Kurulum](01-gereksinimler-ve-kurulum.md) | [Ana İndeks](README.md) | [Sonraki: 03 — Kullanım Modları & JS API →](03-kullanim-modlari-ve-js-api.md)
