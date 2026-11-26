# Supabase Storage (S3) Integration Guide

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose Supabase Storage?**  
> If you already have a Supabase project—or want a generous free tier to manage both public CDN assets and private document buckets from a single dashboard—Supabase Storage is the most practical S3-compatible choice.

Because **Supabase Storage** supports the S3-compatible protocol, it works out of the box with LiveUX FileManagerBundle in a hybrid **Local Storage + Supabase Cloud** setup without writing a single line of custom code. The bundle automatically detects the `supabase` provider from the endpoint URL and displays the Supabase SVG icon in the sidebar.

---

## Step 1: Install Required Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Step 2: Generate S3 Access Keys in the Supabase Dashboard

> [!WARNING]
> **Important:** Supabase's REST/Auth API keys—`SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) and `SUPABASE_SECRET_KEY` (`sb_secret_...`)—do **not** work with the S3 protocol. You must use the 32-character and 64-character credentials generated under **S3 Access Keys** below.

1. Open your project in the Supabase Dashboard → **Storage** → **New bucket** and create a bucket (e.g., `media` or `prints`).
   - Check **Public bucket** for publicly accessible CDN images and assets.
   - Leave **Public bucket** unchecked (private) for customer documents or invoices.
2. Go to **Project Settings** → **Storage** (or **Storage** → **Settings**).
3. Copy these two values from the **S3 Connection** card:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** e.g., `eu-central-1` or `eu-west-1`
4. Under **S3 Access Keys**, click **New access key**, provide a description, and copy the generated values:
   - **Access Key ID** (32 characters)
   - **Secret Access Key** (64 characters — shown only once!)

---

## Step 3: Add Environment Variables to `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## Step 4: Configure `liveux_file_manager.yaml`

### Scenario A — Public Bucket

Images, videos, and public files are served directly through the Supabase CDN URL (taking the load off your PHP server), while thumbnails are generated once on your server and served from the local cache (`public/media-cache`):

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Local Storage'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Optional — automatically detected from the endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # 60s cache for cloud content that may change externally
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
                volume_order: 'local_first' # or 'network_first' / 'configured'
```

### Scenario B — Private Bucket with Per-User Isolation

For customer invoices or confidential PDFs accessible only to authenticated, authorized users:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Supabase Document Vault'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # No public URL since this bucket is private
            private: true             # Secure streaming via DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Isolates each user in their own subfolder without collisions
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

## Step 5: Verify Live Connectivity in One Command

```bash
php bin/console file-manager:doctor --check-remote
```

Example output on success:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Note:** Dynamic per-user volumes using placeholders such as `prefix: 'users/{user_id}'` (as in Scenario B) are safely skipped with an `INFO` status by `file-manager:doctor --check-remote`, since there is no active user session in the CLI environment.

---

## Supabase-Specific Tips & Troubleshooting

### 1. Deleting Files in a MIME-Restricted Bucket (`InvalidMimeType: application/json`)
If you configured **Allowed MIME types** on your Supabase bucket to only permit types like `application/pdf` or `image/*`, Supabase will return `InvalidMimeType` when the trash feature tries to write `.trash/<id>/.meta.json`.
- **Automatic Fallback:** `TrashManager` detects this automatically—the file itself is moved into `.trash/<id>/...` in the cloud bucket, while the `.meta.json` metadata is written to a local fallback file on your server (`trash-meta.json` or `<name>.trash-meta.json` inside the `defaults.metadata.file` directory). Restoring and permanently deleting files continue to work seamlessly.

### 2. AWS SDK v3.337+ Checksum & ACL Errors
For S3 volumes configured with a custom `endpoint` like Supabase, `FlysystemStorageFactory` automatically applies:
- `request_checksum_calculation: 'when_required'` and `response_checksum_validation: 'when_required'`
- `retain_visibility: false` and stripping of `x-amz-acl` headers (`disable_acl: true`)

No additional configuration is required.

### 3. `cURL error 60` SSL Certificate Error on Windows / WAMP
If you encounter `cURL error 60: unable to get local issuer certificate` on your local development machine:
- Download [cacert.pem](https://curl.se/ca/cacert.pem) and reference it in `php.ini` (both Apache and CLI `php.ini` files):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Alternatively, configure it for a specific volume only via `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. Files Deleted in the Supabase Dashboard Still Appear in the Listing
- Click the **Refresh (`↻`)** button in the toolbar (or press `R`) to bypass the cache immediately via `?refresh=1`.
- If files in the bucket are frequently modified externally, set `cache_ttl: 30` (or `cache_ttl: 0` to disable caching) on the volume.

---

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Next: AWS S3 Guide →](aws-s3.md)
