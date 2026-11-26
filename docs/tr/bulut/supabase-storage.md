# Supabase Storage (S3) Entegrasyon Rehberi

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **Supabase Storage'ı Ne Zaman Seçmelisiniz?**  
> Halihazırda bir Supabase projeniz varsa, ücretsiz başlangıç kotası ve tek panelde hem public CDN hem private evrak bucket'ı yönetmek istiyorsanız en pratik S3 uyumlu çözümdür.

**Supabase Storage**, S3 uyumlu protokolü desteklediği için LiveUX FileManagerBundle ile sıfır özel kod yazmadan **Yerel Depolama + Supabase Bulut** hibrit yapısında çalışır. Paket, endpoint adresinden `supabase` sağlayıcısını otomatik algılar ve sol menüde Supabase SVG ikonunu gösterir.

---

## Adım 1: Gerekli Paketleri Kurun

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Adım 2: Supabase Panelinden S3 Anahtarlarını Alın

> [!WARNING]
> **Önemli Uyarı:** Supabase'in REST/Auth API anahtarları olan `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) veya `SUPABASE_SECRET_KEY` (`sb_secret_...`) S3 protokolünde **çalışmaz**. Aşağıdaki **S3 Access Keys** bölümünden üretilen 32 ve 64 karakterlik anahtarları kullanmalısınız.

1. Supabase Dashboard'da projenizi açın → **Storage** → **New bucket** ile bir bucket oluşturun (ör. `medya` veya `baski`).
   - Herkese açık CDN görselleri için **Public bucket** kutusunu işaretleyin.
   - Müşteri evrakları/faturalar için **Public bucket** kapalı kalsın (private).
2. **Project Settings** → **Storage** (veya Storage → **Settings**) bölümüne gidin.
3. **S3 Connection** kartından şu iki bilgiyi kopyalayın:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** ör. `eu-central-1` veya `eu-west-1`
4. **S3 Access Keys** bölümünden **New access key** butonuna tıklayıp bir açıklama verin; üretilen:
   - **Access Key ID** (32 karakter)
   - **Secret Access Key** (64 karakter — yalnızca bir kez gösterilir!)
   değerlerini kopyalayın.

---

## Adım 3: `.env.local` Değişkenlerini Ekleyin

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=baski
SUPABASE_S3_KEY=buraya_32_karakterlik_access_key_id
SUPABASE_S3_SECRET=buraya_64_karakterlik_secret_access_key
```

---

## Adım 4: `liveux_file_manager.yaml` Yapılandırması

### Senaryo A — Herkese Açık (Public) Bucket

Görsel, video ve genel dosyalar doğrudan Supabase CDN URL'i üzerinden açılır (PHP sunucusunu yormaz); thumbnail'lar sunucuda bir kez üretilip yerel önbellekten (`public/media-cache`) servis edilir:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Yerel Depolama'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Opsiyonel — endpoint'ten otomatik algılanır
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # Dışarıdan değişebilen bulut içeriği için 60 sn cache
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # veya 'network_first' / 'configured'
```

### Senaryo B — Gizli (Private) Bucket & Kullanıcı İzolasyonu

Müşteri faturaları veya yalnızca giriş yapmış yetkili kullanıcıların görebileceği PDF'ler için:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Supabase Evrak Kasası'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Private olduğu için dış URL yok
            private: true             # DownloadController + Voter ile güvenli akış
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Her kullanıcı çakışmasız kendi alt klasörüne izole edilir
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## Adım 5: Canlı Bağlantıyı Tek Komutla Doğrulayın

```bash
php bin/console file-manager:doctor --check-remote
```

Başarılı örnek çıktı:
```text
 default | supabase_public | network | AwsS3 | OK | baski — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Not:** Senaryo B gibi `prefix: 'users/{user_id}'` kullanan dinamik kullanıcı diskleri, CLI ortamında kullanıcı oturumu olmadığı için `file-manager:doctor --check-remote` tarafından `INFO` durumuyla güvenli şekilde atlanır.

---

## Supabase'e Özel İpuçları ve Sorun Giderme

### 1. MIME Kısıtlı Bucket'ta Silme (`InvalidMimeType: application/json`)
Supabase bucket ayarında **Allowed MIME types** olarak yalnızca `application/pdf` veya `image/*` tanımladıysanız, dosya silerken çöp kutusu `.trash/<id>/.meta.json` yazarken Supabase `InvalidMimeType` döner.
- **Otomatik Çözüm:** `TrashManager` bunu otomatik algılar; dosya buluttaki `.trash/<id>/...` içine taşınır, `.meta.json` üst verisi ise sunucudaki yerel yedek dosyaya (`defaults.metadata.file` klasöründeki `trash-meta.json` veya `<ad>.trash-meta.json`) yazılır. Geri yükleme ve kalıcı silme sorunsuz çalışır.

### 2. AWS SDK v3.337+ Checksum ve ACL Hataları
`FlysystemStorageFactory`, Supabase gibi özel `endpoint` tanımlı S3 hacimlerinde otomatik olarak:
- `request_checksum_calculation: 'when_required'` & `response_checksum_validation: 'when_required'`
- `retain_visibility: false` ve `x-amz-acl` başlık temizliği (`disable_acl: true`)
uygular. Ek ayar yapmanız gerekmez.

### 3. Windows / WAMP Ortamında `cURL error 60` SSL Hatası
Yerel geliştirme bilgisayarınızda `cURL error 60: unable to get local issuer certificate` alırsanız:
- [cacert.pem](https://curl.se/ca/cacert.pem) dosyasını indirip `php.ini` (hem Apache hem CLI `php.ini`) içine ekleyin:
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Veya yalnızca ilgili volume içinde `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` verin.

### 4. Supabase Panelinden Dosya Sildim Ama Listede Hâlâ Görünüyor
- Araç çubuğundaki **Yenile (`↻`)** butonuna (veya `R` tuşuna) basarak `?refresh=1` ile önbelleği anında tazeleyebilirsiniz.
- Bulutta sık dış değişiklik oluyorsa volume üzerinde `cache_ttl: 30` (veya önbelleksiz için `cache_ttl: 0`) tanımlayın.

---

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Sonraki: AWS S3 Rehberi →](aws-s3.md)
