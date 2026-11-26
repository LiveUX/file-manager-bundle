# Google Cloud Storage (GCS) Integration Guide

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose Google Cloud Storage?**  
> GCS is the natural fit if your application already runs on Google Cloud (GKE, Cloud Run, Compute Engine) and you want to connect directly through its S3 interoperability layer (HMAC) without pulling in an additional vendor SDK.

**Google Cloud Storage (GCS)** works directly with the `AwsS3` driver via its built-in S3 compatibility layer (*XML API / Interoperability HMAC*)—though you can also wire up `league/flysystem-google-cloud-storage` via `FlysystemService` if preferred. The `storage.googleapis.com` endpoint is detected automatically, and the `gcs` SVG icon is displayed in the sidebar.

---

## Step 1: Install Required Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Step 2: Create an HMAC Key in the Google Cloud Console

1. Open Google Cloud Console → **Cloud Storage** → **Buckets** and create a new bucket (e.g., `my-symfony-media`).
2. Navigate to **Cloud Storage** → **Settings** → **Interoperability**.
3. Under **Service account HMAC**, click **Create a key for a service account** (make sure the service account has the `Storage Object Admin` role).
4. Copy the generated **Access key** (`GOOG1E...`) and **Secret**.
5. *(Optional — for the Image Editor)* Apply a bucket CORS policy using a `cors.json` file:
   ```json
   [
       {
           "origin": ["https://your-site.com"],
           "method": ["GET", "HEAD"],
           "responseHeader": ["Content-Type", "ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```
   ```bash
   gcloud storage buckets update gs://my-symfony-media --cors-file=cors.json
   ```

---

## Step 3: Configure `.env.local` and `liveux_file_manager.yaml`

```dotenv
# .env.local
GCS_BUCKET=my-symfony-media
GCS_HMAC_KEY=GOOG1E...
GCS_HMAC_SECRET=...
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        gcs_media:
            label: 'Google Cloud Storage'
            icon: 'gcs'               # Optional — automatically detected from the endpoint
            group: 'network'
            driver: 'AwsS3'
            # For public buckets: https://storage.googleapis.com/<bucket> (set to null if private)
            url_prefix: 'https://storage.googleapis.com/%env(GCS_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(GCS_BUCKET)%'
                region: 'auto'
                endpoint: 'https://storage.googleapis.com'
                use_path_style_endpoint: true
                key: '%env(GCS_HMAC_KEY)%'
                secret: '%env(GCS_HMAC_SECRET)%'

    profiles:
        default:
            volumes: ['gcs_media']
```

> **Automatic GCS Compatibility:** Because `endpoint: 'https://storage.googleapis.com'` is configured, `FlysystemStorageFactory` automatically applies `request_checksum_calculation: 'when_required'`, `response_checksum_validation: 'when_required'`, `disable_acl: true`, and `retain_visibility: false`.

---

## Step 4: Verify Live Connectivity

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Previous: MinIO / RustFS](minio-rustfs.md) | [07 — Cloud Storage Index](../07-cloud-storage-and-dynamic-config.md) | [Next: SFTP & Custom Flysystem Services →](sftp-and-custom-flysystem.md)
