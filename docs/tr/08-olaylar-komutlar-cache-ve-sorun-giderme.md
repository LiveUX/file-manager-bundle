# 08 — Event'ler, CLI Komutları, Cache, Çeviriler ve Sorun Giderme (SSS)

[← Önceki: 07 — Özel Depolama (S3/R2)](07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](README.md) | [Sonraki: 09 — Symfony AI Entegrasyonu →](09-symfony-ai-entegrasyonu.md)

> **En Basit Haliyle: Event'ler ve CLI Komutları Ne İşe Yarar?**  
> - **Event'ler (Olaylar):** Kullanıcı bir dosya yüklediğinde, sildiğinde veya adını değiştirdiğinde Symfony uygulamanıza otomatik haber verir — örneğin *"dosya yeniden adlandırılınca veritabanındaki ürün resmini de otomatik güncelle"* diyebilirsiniz.  
> - **CLI Komutları (`php bin/console file-manager:...`):** Terminalden sistem sağlığını kontrol etmek (`doctor`), önbelleği temizlemek (`cache:clear`), küçük resimleri önceden üretmek (`thumbs:warmup`) veya eski çöpleri boşaltmak (`trash:purge`) için hazır araçlardır.

---

## 1. Symfony Event'leri (9 Domain Olayı)

Tüm dosya yöneticisi olayları ortak **`AbstractFileManagerEvent`** taban sınıfından (`$profile`, `$volume`, `$path`, `$canonicalPath`) türer. Bu olayları dinleyerek veritabanı senkronizasyonu, audit loglama, CDN cache temizleme veya asenkron iş kuyruğu tetikleme yapabilirsiniz.

| Event Sınıfı (`LiveUX\FileManagerBundle\Event\*`) | Ne Zaman Tetiklenir? | Özellikler |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Yeni dosya yüklendiğinde, URL import'ta, görsel düzenleyici kaydettiğinde veya ZIP arşivinden çıkarıldığında | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Dosya silindiğinde (çöp kutusuna taşındığında veya kalıcı silindiğinde) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Yeni klasör oluşturulduğunda (veya ZIP çıkarılırken klasör oluştuğunda) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Klasör silindiğinde | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Dosya veya klasör yeniden adlandırıldığında | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Taşıma veya kopyalama yapıldığında (volume'lar arası dahil) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Öğe klonlandığında | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Çöp kutusundan geri yükleme yapıldığında | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Çöp kutusundan kalıcı silme yapıldığında | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Örnek Event Listener (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('Yeni medya yüklendi.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Konsol (CLI) Komutları (10 Hazır Komut)

| Komut | Bayraklar / Takma Adlar | Ne İşe Yarar? |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | PHP sürümü, `upload_max_filesize`, `post_max_size`, cURL CA Bundle, GD/Imagick, EXIF, WebP, `ext-zip` ve tüm profil/volume bağlantılarını denetler. `--check-remote` ile S3/R2/Supabase/MinIO canlı bağlantı testi (`ListObjectsV2`) yapar. |
| `file-manager:cache:clear [profil]` | `--volume`, `--path` | Tüm profillerin veya belirtilen profil/volume/klasörün dizin & kota önbelleğini temizler (`--volume`/`--path` için `profil` argümanı gerekir; ana uygulamanın `cache.app` verilerine asla dokunmaz). |
| `file-manager:thumbs:warmup [profil]` | Takma adlar: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Görsellerin küçük resimlerini (thumbnail) kullanıcı beklemeden önceden üretir. |
| `file-manager:thumbs:clear [profil]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Üretilmiş thumbnail önbelleğini temizler. `--orphans` yalnızca kaynağı silinmiş yetim thumbnail'ları siler (`--volume`/`--path` ile birlikte kullanılamaz ve `{user_id}`/`{Y}/{m}/{d}` paylaşımlı dizinleri otomatik korur). |
| `file-manager:images:optimize [profil]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Mevcut görselleri geriye dönük optimize eder (güvenlik/bellek sınırı: tek görsel maks. `25 MB`). |
| `file-manager:duplicates [profil]` | `--volume`, `--min-size`, `--json` | SHA-256 içerik özetiyle yinelenen dosyaları ve kazanılabilecek disk alanını raporlar. |
| `file-manager:stats [profil]` | `--json` | Depolama kullanımı, dosya/klasör sayısı, kota doluluğu, thumbnail ve çöp kutusu istatistik raporu. |
| `file-manager:ai:enrich [profil]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(varsayılan `50`)*, `--dry-run` | Eksik `alt` metin / başlık / etiketleri toplu olarak yapay zeka ile doldurur. |
| `file-manager:trash:purge [profil]` | `--volume`, `--days` *(`0` = tümünü sil)*, `--dry-run` | Süresi dolmuş çöp kutusu öğelerini kalıcı siler. Aynı fiziksel klasöre bakan birden fazla profil varsa en uzun `retention_days` değerini otomatik korur. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Paketin 6 Yapay Zeka Agent Skill'ini `.agents/skills`, `.claude/skills`, `.cursor/skills` veya `.github/skills` klasörüne kurar. |

> **CLI Komutlarında Dinamik `{user_id}` ve `{Y}/{m}/{d}` Koruması:**  
> Terminal (CLI) ortamında giriş yapmış bir kullanıcı oturumu olmadığı için tarama/temizleme yapan tüm CLI komutları (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) `{user_id}` içeren diskleri **otomatik atlar** (böylece yanlışlıkla boş bir `guest/` klasörü oluşturulmaz). `{Y}/{m}/{d}` tarih yer tutuculu disklerde ise yalnızca geçerli tarih dönemi taranır ve bu durum kullanıcıya bilgi mesajıyla bildirilir.

### Otomatik Çöp Temizleme İçin Cron Örneği
```cron
# Her gece 03:00'te saklama süresi dolmuş çöp kutusu dosyalarını temizle
0 3 * * * php /var/www/proje/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Performans, Cache Mimarisi ve Symfony Profiler

- **3 Katmanlı Önbellek Süresi (TTL) & Manuel Yenileme (`↻`):** Dizin listeleme TTL önceliği **`volumes.<ad>.cache_ttl` → `profiles.<ad>.cache.ttl` → `defaults.cache.ttl`** şeklindedir (`0` = o disk/profilde önbellek kapalı). Araç çubuğundaki **Yenile (`↻`)** butonu veya `R` tuşu `?refresh=1` gönderir *(önbellek atlama için `CLEAR_CACHE` yetkisi gerekir)*.
- **Özel Cache Havuzu & Profil Parmak İzi:** `defaults.cache.pool` ile ayrı bir Redis/APCu havuzu seçebilirsiniz (`<pool>.taggable` varsa otomatik kullanılır). Taggable cache yoksa `DirectoryCacheManager` 30 gün TTL'li jenerasyon sayaç anahtarları kullanır ve asla `$cachePool->clear()` çağırarak ana uygulamanın cache'ini silmez. Aynı klasöre bakan iki farklı profilin (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files` farklıysa) önbelleği `buildProfilePolicyFingerprint()` ile birbirinden izole edilir.
- **Canonical Key Senkronizasyonu:** Aynı fiziksel klasöre bakan iki profil varsa birinde dosya yüklendiğinde diğer profilin önbelleği de otomatik temizlenir.
- **Kendini İyileştiren `remember_last_dir`:** Son çalışılan klasör dışarıdan (FTP/S3 panel) silindiyse hata vermek yerine otomatik olarak en yakın mevcut üst klasöre veya köke geçiş yapar.
- **HTTP 304 & Bağlam Duyarlı Thumbnail Cache (`ThumbnailController`):**
  - **Özel / Güvenlikli Bağlam** (`private: true` volume, `{user_id}` dinamik volume, Voter aktif profil, `Authorization` başlığı veya oturum çerezi varsa): paylaşımlı CDN sızıntısını önlemek için `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization`.
  - **Herkese Açık (Public) Bağlam:**
    - `?v=<sürüm>` önbellek kırıcı parametre varsa → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 gün).
    - `?v=` yoksa → `Cache-Control: public, max-age=3600, must-revalidate` (1 saat + `ETag` / `304 Not Modified`).
    - Profilde `cache.enabled: false` ise → `Cache-Control: public, no-cache, must-revalidate`.
  - Tüm thumbnail yanıtları `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` ve `Referrer-Policy: no-referrer` başlıklarını içerir.
- **Symfony Web Debug Toolbar:** `dev` ortamında alt araç çubuğundaki **FileManager** sekmesi aktif profil, volume, thumbnail sürücüsü ve cache hit/miss durumunu gösterir.

---

## 4. Global Çoklu Dil (`493` Anahtar), BCP-47 / POSIX / RTL ve Yeni Dil Ekleme

Paket kutudan **İngilizce (`en` — garantili fallback)**, **Türkçe (`tr`)** ve **Fransızca (`fr`)** dillerinde birebir eşit **493 çeviri anahtarıyla** gelir.

- **BCP-47 ve POSIX `.UTF-8` Desteği:** `MessageTranslator`, `tr_TR.UTF-8`, `de_DE@euro`, `zh-Hans-CN` gibi tüm global dil kodlarını otomatik çözer (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). HTML `lang` özniteliği için `toBcp47()` otomatik geçerli BCP-47 üretir.
- **Otomatik Sağdan Sola (RTL) Algılama:** Arapça (`ar`), Farsça (`fa`), İbranice (`he`), Urduca (`ur`), Peştuca (`ps`), Kürtçe Sorani (`ckb`) dahil **15 RTL dil ve yazı sistemi (`Arab`, `Hebr`, `Syrc`, `Thaa` vb.)** otomatik algılanır ve bileşen `dir="rtl"` düzenine geçer.
- **Derleme Zamanı Katalog İzin Listesi:** `FileManagerBundle` yalnızca gerçekten mevcut çeviri kataloglarını kabul ederek sahte locale parametreleriyle OPcache/cache şişirme (DoS) saldırılarını önler.
- **Kendi Dilinizi (ör. Almanca `de`) veya Kısmi Özelleştirmenizi Ekleme:**  
  Projenizde `translations/LiveUXFileManagerBundle.de.yaml` dosyasını oluşturmanız yeterlidir. Yazmadığınız anahtarlar otomatik olarak İngilizce (`en`) gösterilir — kullanıcı asla ham anahtar (`ui.upload`) görmez. Tam şablon için paketteki `translations/LiveUXFileManagerBundle.en.yaml` dosyasını kopyalayabilirsiniz.

---

## 5. Sık Karşılaşılan Sorunlar ve Çözümleri (SSS)

### 1. `window.LiveUXFileManager is not initialized / undefined` hatası alıyorum
- `assets/app.js` içine `import '@liveux/file-manager-bundle/js/file_manager_api.js';` eklediğinizden emin olun.
- `defaults.auto_inject_dialog_host: true` aktif mi kontrol edin (kapattıysanız `base.html.twig` içinde `DialogHost.html.twig` include etmelisiniz).
- `php bin/console cache:clear` çalıştırıp tarayıcıda sert yenileme (`Ctrl+Shift+R`) yapın.

### 2. Tüm POST işlemlerinde `403 Invalid or missing CSRF token` hatası alıyorum
- `symfony/security-csrf` paketinin kurulu ve `framework.csrf_protection: true` + `framework.session.enabled: true` olduğundan emin olun.
- Kendi özel `fetch()` isteğinizi atıyorsanız `X-LiveUX-FM-CSRF` başlığını eklemeniz gerekir.

### 3. `403 Access denied` hatası alıyorum
- `liveux_file_manager.yaml` içinde profilin `permissions` kurallarını kontrol edin. Unutmayın: `true` değeri bile giriş yapmış kullanıcı gerektirir; ayrıca `permissions.view` olmadan diğer işlemler çalışmaz.
- Özel bir Voter yazdıysanız `security.yaml` içinde `access_decision_manager.strategy: unanimous` ayarını kontrol edin.

### 4. Büyük dosya yüklerken sunucu hata veriyor (`upload_max_filesize`)
- `chunk_upload.enabled: true` açıkken parça boyutu otomatik olarak PHP `upload_max_filesize`/`post_max_size` değerinin `%80`'ine kırpılır (ör. PHP 2M ise `1.60 MB` parçalar, maks. 2.000 parça).
- Toplam dosya limitini `liveux_file_manager.yaml` içindeki `upload_max_size` belirler (ör. 100 MB video için `upload_max_size: '100M'` yazın).

### 5. Dosya indirirken sayfa bozuluyor (Symfony UX Turbo Drive)
- Bundle'daki tüm indirme linklerinde `data-turbo="false" download` zaten eklenmiştir. Kendi özel Twig şablonunuzu yazarsanız indirme `<a>` etiketlerine `data-turbo="false"` eklemeyi unutmayın.

### 6. Thumbnail'lar oluşmuyor, sadece orijinal görsel geliyor
- `php bin/console file-manager:doctor` çalıştırıp `Thumbnail Driver` satırında `gd` veya `imagick`'in aktif olduğunu kontrol edin.
- `thumbnail.cache_dir` (`public/media-cache`) klasörünün yazılabilir olduğundan emin olun.

### 7. Windows'ta S3 / Supabase bağlanırken `cURL error 60: SSL certificate problem` alıyorum
- Güncel `cacert.pem` dosyasını indirip `php.ini` içinde `curl.cainfo` ve `openssl.cafile` olarak tanımlayın veya S3 volume altında `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` verin.

### 8. Değişiklikler tarayıcıda görünmüyor
- Geliştirmede: `php bin/console cache:clear` + tarayıcıda `Ctrl+Shift+R`.
- Canlıda (AssetMapper): `php bin/console asset-map:compile`.

---

[← Önceki: 07 — Özel Depolama (S3/R2)](07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](README.md) | [Sonraki: 09 — Symfony AI Entegrasyonu →](09-symfony-ai-entegrasyonu.md)
