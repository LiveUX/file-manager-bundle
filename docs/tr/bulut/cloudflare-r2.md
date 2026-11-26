# Cloudflare R2 Entegrasyon Rehberi

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **Cloudflare R2'yi Ne Zaman Seçmelisiniz?**  
> Yüksek görsel/video indirme trafiğiniz varsa ve **sıfır çıkış trafiği ücreti (zero egress fee)** + yerleşik Cloudflare CDN hızı istiyorsanız medya projeleri için en ekonomik S3 uyumlu çözümlerden biridir.

LiveUX FileManagerBundle, `.r2.cloudflarestorage.com` endpoint'inden Cloudflare R2'yi otomatik tanır ve sol menüde `cloudflare_r2` SVG ikonunu gösterir.

---

## Adım 1: Gerekli Paketleri Kurun

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Adım 2: Cloudflare Panelinden Bucket ve API Token Oluşturun

1. Cloudflare Dashboard → **R2 Object Storage** → **Create bucket** ile bucket oluşturun (ör. `cdn-assets`).
2. Herkese açık erişim için Bucket → **Settings** → **Public access** altından özel alan adınızı bağlayın (ör. `assets.siteniz.com`). Gizli evrak bucket'ı yapıyorsanız public erişimi kapalı bırakın.
3. R2 ana sayfasında **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** yalnızca ilgili bucket'ı seçin
   - Oluşan **Access Key ID**, **Secret Access Key** ve üstteki **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`) bilgilerini kopyalayın.
4. *(Opsiyonel — Görsel Düzenleyici için)* Bucket → **Settings** → **CORS Policy**:
   ```json
   [
       {
           "AllowedOrigins": ["https://siteniz.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## Adım 3: `.env.local` ve `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=buraya_r2_access_key_id
R2_SECRET=buraya_r2_secret_access_key
```

### Senaryo A — Public CDN Bucket + Senaryo B — Private Evrak Kasası

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Opsiyonel — endpoint'ten otomatik algılanır
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.siteniz.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # R2 için daima 'auto' kullanın
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'R2 Gizli Arşiv'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Private olduğu için dış URL yok
            private: true             # Voter + DownloadController ile yetki kontrollü akış
            cache_ttl: 60
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'
                endpoint: '%env(R2_ENDPOINT)%'
                prefix: 'private-docs'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

    profiles:
        default:
            volumes: ['cloudflare_r2_cdn', 'cloudflare_r2_private']
```

> **AWS SDK v3.337+ Checksum & ACL Uyumluluğu:** `aws/aws-sdk-php >= 3.337` sürümlerinde yeni varsayılan CRC32 checksum başlıkları Cloudflare R2 ile `XAmzContentSHA256Mismatch` / `NotImplemented` hatası üretebilir. `FlysystemStorageFactory`, özel `endpoint` algıladığında `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true` ve `retain_visibility: false` ayarlarını **otomatik uygular** — ek ayar gerekmez.

---

## Adım 4: Canlı Doğrulama

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Önceki: AWS S3](aws-s3.md) | [07 — Depolama İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Sonraki: Backblaze B2 →](backblaze-b2.md)
