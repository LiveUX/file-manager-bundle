# Backblaze B2 Entegrasyon Rehberi

[← 07 — Depolama ve Bulut İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Ana İndeks](../README.md)

> **Backblaze B2'yi Ne Zaman Seçmelisiniz?**  
> Yüzlerce GB video, baskı PDF'i veya yüksek çözünürlüklü arşiv dosyasını en düşük depolama maliyetiyle (ve Cloudflare/Bunny CDN üzerinden ücretsiz bant genişliği anlaşmasıyla) saklamak istediğinizde idealdir.

`.backblazeb2.com` endpoint'i otomatik algılanır ve sol menüde `backblaze_b2` SVG ikonu gösterilir.

---

## Adım 1: Gerekli Paketleri Kurun

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Adım 2: Backblaze Panelinden Bucket, Application Key ve CORS Ayarlayın

1. Backblaze B2 → **Buckets** → **Create a Bucket** (ör. `promosyon-medya`).
   - Oluşturulduktan sonra bucket kartındaki **Endpoint** değerini not alın (ör. `s3.eu-central-003.backblazeb2.com`).
   - **Region** değeri endpoint'in ortasındaki kısımdır: `eu-central-003`.
2. **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** yalnızca oluşturduğunuz bucket
   - **Type of Access:** `Read and Write`
   - Üretilen **`keyID`** ve **`applicationKey`** değerlerini kopyalayın.
3. *(Opsiyonel — Görsel Düzenleyici için)* Bucket → **CORS Rules** → **Share everything in this bucket with every origin** veya kendi alan adınız için özel S3 CORS kuralı ekleyin:
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://siteniz.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## Adım 3: `.env.local` ve `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=promosyon-medya
B2_REGION=eu-central-003
B2_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
B2_KEY_ID=003xxxxxxxxxxxxxxxxxxxxxx
B2_APP_KEY=K003yyyyyyyyyyyyyyyyyyyyyyyyy
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        backblaze_b2:
            label: 'Backblaze B2'
            icon: 'backblaze_b2'      # Opsiyonel — endpoint'ten otomatik algılanır
            group: 'network'
            driver: 'AwsS3'
            # Public bucket kullanıyorsanız Friendly URL veya CDN alan adınız (private ise null):
            url_prefix: 'https://f003.backblazeb2.com/file/%env(B2_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(B2_BUCKET)%'
                region: '%env(B2_REGION)%'
                endpoint: '%env(B2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(B2_KEY_ID)%'
                secret: '%env(B2_APP_KEY)%'

    profiles:
        default:
            volumes: ['backblaze_b2']
```

> **Not:** `FlysystemStorageFactory`, özel endpoint nedeniyle AWS SDK v3.337+ checksum (`when_required`), `disable_acl: true` ve `retain_visibility: false` ayarlarını Backblaze B2 için otomatik etkinleştirir.

---

## Adım 4: Canlı Doğrulama

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Önceki: Cloudflare R2](cloudflare-r2.md) | [07 — Depolama İndeksi](../07-depolama-s3-ve-dinamik-yapilandirma.md) | [Sonraki: MinIO / RustFS →](minio-rustfs.md)
