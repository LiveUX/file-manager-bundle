# Integrationsleitfaden: Amazon AWS S3

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du Amazon AWS S3 wählen?**  
> AWS S3 ist der Industriestandard, wenn deine Anwendung bereits auf AWS (EC2/ECS/CloudFront) läuft und du schlüssellose Authentifizierung über IAM-Rollen sowie höchste globale Haltbarkeit (99,999999999 %) benötigst.

Das LiveUX FileManagerBundle verbindet sich über den Treiber `AwsS3` direkt mit deinen AWS-S3-Buckets und zeigt in der Seitenleiste automatisch das `aws_s3`-SVG-Icon an.

---

## Schritt 1: Erforderliche Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Schritt 2: IAM-Richtlinie nach dem Least-Privilege-Prinzip erstellen

Erstelle in AWS IAM einen dedizierten IAM-Benutzer (oder eine EC2-/ECS-Rolle) für diese Anwendung und weise folgende Richtlinie zu, die strikt auf deinen Bucket beschränkt ist:

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
> **Object Ownership / ACL-Einstellung (`disable_acl: true`):**  
> Moderne AWS-S3-Buckets deaktivieren ACLs standardmäßig (*Bucket owner enforced*). Die Option `disable_acl` wird nur dann automatisch auf `true` gesetzt, wenn ein benutzerdefinierter `endpoint` (R2, Supabase, MinIO usw.) konfiguriert ist; **für natives AWS S3 lautet der Standardwert `false`**.  
> Um `AccessControlListNotSupported`-Fehler zu vermeiden, füge unter `driver_options` explizit **`disable_acl: true`** (und optional `retain_visibility: false`) hinzu, wie im Beispiel unten gezeigt.

---

## Schritt 3: Bucket-CORS konfigurieren (für den Bildeditor)

Damit der integrierte Canvas-Bildeditor Bilder, die auf S3 gehostet werden, direkt im Browser zuschneiden und bearbeiten kann, hinterlege deine Domain unter Bucket → **Permissions** → **CORS**:

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

## Schritt 4: `.env.local` und `liveux_file_manager.yaml` konfigurieren

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
            icon: 'aws_s3'            # Optional — wird automatisch anhand des AwsS3-Treibers erkannt
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://cdn.your-site.com'  # CloudFront- oder öffentliche S3-URL
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'
                region: '%env(AWS_S3_REGION)%'
                key: '%env(AWS_S3_KEY)%'
                secret: '%env(AWS_S3_SECRET)%'
                prefix: 'media/{Y}'   # Dynamische Pfad-Platzhalter werden unterstützt
                disable_acl: true     # Empfohlen für S3-Buckets mit "Bucket owner enforced"
                retain_visibility: false

        aws_private_docs:
            label: 'AWS Privates Archiv'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null
            private: true             # Block Public Access aktiv; Dateien werden über Voter + DownloadController gestreamt
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

> **EC2-/ECS-IAM-Rolle oder temporäre AWS-STS-Zugangsdaten:**
> - **IAM-Rolle:** Wenn du ein EC2 Instance Profile oder eine ECS Task Role nutzt, kannst du `key` und `secret` vollständig weglassen – das AWS SDK bezieht die Zugangsdaten automatisch aus der Umgebung.
> - **Temporäre AWS-STS-Zugangsdaten:** Bei Verwendung temporärer Sitzungs-Tokens ergänze einfach `driver_options.token: '%env(AWS_SESSION_TOKEN)%'`.

---

## Schritt 5: Live-Verbindung prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

> **Hinweis:** Benutzerbezogene Volumes mit Platzhaltern wie `prefix: 'private/{user_id}'` (z. B. `aws_private_docs`) werden von `file-manager:doctor --check-remote` sicher mit dem Status `INFO` übersprungen, da in der CLI-Umgebung keine Benutzersitzung vorhanden ist.

---

[← Zurück: Supabase Storage](supabase-storage.md) | [07 — Cloud-Speicher-Übersicht](../07-cloud-storage-and-dynamic-config.md) | [Weiter: Cloudflare R2 →](cloudflare-r2.md)
