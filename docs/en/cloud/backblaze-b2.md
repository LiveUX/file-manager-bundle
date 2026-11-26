# Backblaze B2 Integration Guide

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose Backblaze B2?**  
> Backblaze B2 is ideal when you need to store hundreds of gigabytes of videos, print-ready PDFs, or high-resolution archives at the lowest possible storage cost (especially when paired with free egress through Bandwidth Alliance partners like Cloudflare or Bunny CDN).

The `.backblazeb2.com` endpoint is detected automatically, and the `backblaze_b2` SVG icon is displayed in the sidebar.

---

## Step 1: Install Required Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Step 2: Configure Bucket, Application Key, and CORS in Backblaze

1. Go to Backblaze B2 → **Buckets** → **Create a Bucket** (e.g., `app-media`).
   - Once created, note the **Endpoint** shown on the bucket card (e.g., `s3.eu-central-003.backblazeb2.com`).
   - The **Region** is the middle segment of the endpoint: `eu-central-003`.
2. Go to **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** select only the bucket you created
   - **Type of Access:** `Read and Write`
   - Copy the generated **`keyID`** and **`applicationKey`** values.
3. *(Optional — for the Image Editor)* Under Bucket → **CORS Rules**, either choose **Share everything in this bucket with every origin** or add a custom S3 CORS rule for your domain:
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://your-site.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## Step 3: Configure `.env.local` and `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=app-media
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
            icon: 'backblaze_b2'      # Optional — automatically detected from the endpoint
            group: 'network'
            driver: 'AwsS3'
            # For public buckets, use the Friendly URL or your CDN domain (set to null if private):
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

> **Note:** Because a custom endpoint is configured, `FlysystemStorageFactory` automatically enables AWS SDK v3.337+ checksum compatibility (`when_required`), `disable_acl: true`, and `retain_visibility: false` for Backblaze B2.

---

## Step 4: Verify Live Connectivity

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Previous: Cloudflare R2](cloudflare-r2.md) | [07 — Cloud Storage Index](../07-cloud-storage-and-dynamic-config.md) | [Next: MinIO / RustFS →](minio-rustfs.md)
