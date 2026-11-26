# Cloudflare R2 Integration Guide

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose Cloudflare R2?**  
> If you serve heavy image or video download traffic and want **zero egress fees** paired with built-in Cloudflare CDN speed, Cloudflare R2 is one of the most cost-effective S3-compatible solutions for media-heavy projects.

LiveUX FileManagerBundle automatically detects Cloudflare R2 from the `.r2.cloudflarestorage.com` endpoint and displays the `cloudflare_r2` SVG icon in the sidebar.

---

## Step 1: Install Required Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Step 2: Create a Bucket and API Token in the Cloudflare Dashboard

1. Go to Cloudflare Dashboard → **R2 Object Storage** → **Create bucket** and create a bucket (e.g., `cdn-assets`).
2. For public access, connect your custom domain under Bucket → **Settings** → **Public access** (e.g., `assets.your-site.com`). For a private document bucket, leave public access disabled.
3. From the R2 overview page, open **Manage R2 API Tokens** → **Create API token**:
   - **Permissions:** `Object Read & Write`
   - **Specify bucket(s):** select only the target bucket
   - Copy the generated **Access Key ID**, **Secret Access Key**, and the **S3 API Endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
4. *(Optional — for the Image Editor)* Add a CORS rule under Bucket → **Settings** → **CORS Policy**:
   ```json
   [
       {
           "AllowedOrigins": ["https://your-site.com"],
           "AllowedMethods": ["GET", "HEAD"],
           "AllowedHeaders": ["*"],
           "MaxAgeSeconds": 3600
       }
   ]
   ```

---

## Step 3: Configure `.env.local` and `liveux_file_manager.yaml`

```dotenv
# .env.local
R2_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
R2_BUCKET=cdn-assets
R2_KEY=your_r2_access_key_id
R2_SECRET=your_r2_secret_access_key
```

### Scenario A — Public CDN Bucket & Scenario B — Private Document Vault

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        cloudflare_r2_cdn:
            label: 'Cloudflare R2 CDN'
            icon: 'cloudflare_r2'     # Optional — automatically detected from the endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://assets.your-site.com'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(R2_BUCKET)%'
                region: 'auto'        # Always use 'auto' for Cloudflare R2
                endpoint: '%env(R2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(R2_KEY)%'
                secret: '%env(R2_SECRET)%'

        cloudflare_r2_private:
            label: 'R2 Private Archive'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # No public URL since this volume is private
            private: true             # Authorized streaming via Voter + DownloadController
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

> **AWS SDK v3.337+ Checksum & ACL Compatibility:** In `aws/aws-sdk-php >= 3.337`, the new default CRC32 checksum headers can trigger `XAmzContentSHA256Mismatch` or `NotImplemented` errors on Cloudflare R2. Whenever `FlysystemStorageFactory` detects a custom `endpoint`, it **automatically applies** `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true`, and `retain_visibility: false`—no extra configuration needed.

---

## Step 4: Verify Live Connectivity

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Previous: AWS S3](aws-s3.md) | [07 — Cloud Storage Index](../07-cloud-storage-and-dynamic-config.md) | [Next: Backblaze B2 →](backblaze-b2.md)
