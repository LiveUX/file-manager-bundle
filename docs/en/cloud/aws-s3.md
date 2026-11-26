# Amazon AWS S3 Integration Guide

[← 07 — Cloud Storage & Dynamic Configuration](../07-cloud-storage-and-dynamic-config.md) | [Main Index](../README.md)

> **When Should You Choose Amazon AWS S3?**  
> AWS S3 is the industry-standard choice when your application already runs on AWS (EC2/ECS/CloudFront) and you need keyless IAM Role authentication along with global high durability (99.999999999%).

LiveUX FileManagerBundle connects directly to your AWS S3 buckets using the `AwsS3` driver and automatically displays the `aws_s3` SVG icon in the sidebar.

---

## Step 1: Install Required Packages

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Step 2: Create a Least-Privilege IAM Policy

Create a dedicated IAM user (or EC2/ECS Role) in AWS IAM for this application and attach the following policy scoped strictly to your bucket:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ListBucket",
            "Effect": "Allow",
            "Action": ["s3:ListBucket"],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME"
        },
        {
            "Sid": "ManageObjects",
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
        }
    ]
}
```

> [!IMPORTANT]
> **Object Ownership / ACL Setting (`disable_acl: true`):**  
> Modern AWS S3 buckets disable ACLs by default (*Bucket owner enforced*). The `disable_acl` option is only set to `true` automatically when a custom `endpoint` (R2, Supabase, MinIO, etc.) is configured; **for native AWS S3, it defaults to `false`**.  
> To avoid `AccessControlListNotSupported` errors, explicitly add **`disable_acl: true`** (and optionally `retain_visibility: false`) under `driver_options` as shown in the example below.

---

## Step 3: Configure Bucket CORS (For the Image Editor)

To allow the built-in Canvas Image Editor to crop and edit images hosted on S3 directly in the browser, add your domain under Bucket → **Permissions** → **CORS**:

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedOrigins": ["https://your-site.com"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

---

## Step 4: Configure `.env.local` and `liveux_file_manager.yaml`

```dotenv
# .env.local
AWS_S3_BUCKET=my-media-bucket
AWS_S3_REGION=eu-central-1
AWS_S3_KEY=AKIAIOSFODNN7EXAMPLE
AWS_S3_SECRET=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        aws_cdn:
            label: 'AWS S3 (CloudFront CDN)'
            icon: 'aws_s3'            # Optional — automatically detected from the AwsS3 driver
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # CloudFront or S3 public URL
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Dynamic path placeholders are supported
                disable_acl: true     # Recommended for "Bucket owner enforced" S3 buckets
                retain_visibility: false

        aws_private_docs:
            label: 'AWS Private Archive'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access enabled; files stream via Voter + DownloadController
            cache_ttl: 60
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'private/{user_id}'
                disable_acl: true
                retain_visibility: false

    profiles:
        default:
            volumes: ['aws_cdn', 'aws_private_docs']
```

> **EC2 / ECS IAM Role or AWS STS Temporary Credentials:**
> - **IAM Role:** If you use an EC2 Instance Profile or ECS Task Role, you can omit `key` and `secret` completely—the AWS SDK automatically resolves credentials from the environment.
> - **AWS STS Temporary Credentials:** If you use temporary session tokens, add `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Step 5: Verify Live Connectivity

```bash
php bin/console file-manager:doctor --check-remote
```

> **Note:** User-scoped volumes containing placeholders like `prefix: 'private/{user_id}'` (such as `aws_private_docs`) are safely skipped with an `INFO` status by `file-manager:doctor --check-remote`, since there is no user session in the CLI environment.

---

[← Previous: Supabase Storage](supabase-storage.md) | [07 — Cloud Storage Index](../07-cloud-storage-and-dynamic-config.md) | [Next: Cloudflare R2 →](cloudflare-r2.md)
