# Integrationsleitfaden: Supabase Storage (S3)

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Hauptübersicht](../README.md)

> **Wann solltest du Supabase Storage wählen?**  
> Wenn du bereits ein Supabase-Projekt betreibst – oder einen großzügigen kostenlosen Tarif suchst, um sowohl öffentliche CDN-Assets als auch private Dokumenten-Buckets über ein einziges Dashboard zu verwalten –, ist Supabase Storage die praktischste S3-kompatible Lösung.

Da **Supabase Storage** das S3-kompatible Protokoll unterstützt, funktioniert es out of the box mit dem LiveUX FileManagerBundle in einem hybriden Setup aus **lokalem Speicher + Supabase Cloud**, ohne dass du auch nur eine Zeile eigenen Code schreiben musst. Das Bundle erkennt den `supabase`-Anbieter automatisch anhand der Endpunkt-URL und zeigt das Supabase-SVG-Icon in der Seitenleiste an.

---

## Schritt 1: Erforderliche Pakete installieren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Schritt 2: S3 Access Keys im Supabase-Dashboard erstellen

> [!WARNING]
> **Wichtig:** Die REST-/Auth-API-Schlüssel von Supabase – `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) und `SUPABASE_SECRET_KEY` (`sb_secret_...`) – funktionieren **nicht** mit dem S3-Protokoll. Du musst zwingend die 32-stelligen bzw. 64-stelligen Zugangsdaten verwenden, die weiter unten unter **S3 Access Keys** erzeugt werden.

1. Öffne dein Projekt im Supabase-Dashboard → **Storage** → **New bucket** und lege einen Bucket an (z. B. `media` oder `prints`).
   - Aktiviere **Public bucket** für öffentlich zugängliche CDN-Bilder und Assets.
   - Lasse **Public bucket** deaktiviert (privat) für Kundendokumente oder Rechnungen.
2. Navigiere zu **Project Settings** → **Storage** (bzw. **Storage** → **Settings**).
3. Kopiere folgende zwei Werte aus der Karte **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** z. B. `eu-central-1` oder `eu-west-1`
4. Klicke unter **S3 Access Keys** auf **New access key**, vergib eine Beschreibung und kopiere die generierten Werte:
   - **Access Key ID** (32 Zeichen)
   - **Secret Access Key** (64 Zeichen – wird nur einmalig angezeigt!)

---

## Schritt 3: Umgebungsvariablen in `.env.local` eintragen

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

## Schritt 4: `liveux_file_manager.yaml` konfigurieren

### Szenario A — Öffentlicher Bucket (Public Bucket)

Bilder, Videos und öffentliche Dateien werden direkt über die Supabase-CDN-URL ausgeliefert (was deinen PHP-Server spürbar entlastet), während Vorschaubilder (Thumbnails) einmalig auf deinem Server generiert und aus dem lokalen Cache (`public/media-cache`) bereitgestellt werden:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokaler Speicher'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Optional — wird automatisch anhand des Endpunkts erkannt
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # 60 s Cache für Cloud-Inhalte, die sich extern ändern können
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
                volume_order: 'local_first' # oder 'network_first' / 'configured'
```

### Szenario B — Privater Bucket mit benutzerbezogener Isolation

Für Kundenrechnungen oder vertrauliche PDFs, auf die ausschließlich authentifizierte und berechtigte Benutzer zugreifen dürfen:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Supabase Dokumententresor'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Keine öffentliche URL, da dieser Bucket privat ist
            private: true             # Sicheres Streaming über DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Isoliert jeden Benutzer kollisionsfrei in einem eigenen Unterordner
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

## Schritt 5: Live-Verbindung mit einem Befehl prüfen

```bash
php bin/console file-manager:doctor --check-remote
```

Beispielausgabe bei erfolgreicher Verbindung:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Hinweis:** Dynamische benutzerbezogene Volumes mit Platzhaltern wie `prefix: 'users/{user_id}'` (wie in Szenario B) werden von `file-manager:doctor --check-remote` sicher mit dem Status `INFO` übersprungen, da in der CLI-Umgebung keine aktive Benutzersitzung existiert.

---

## Supabase-spezifische Tipps & Fehlerbehebung

### 1. Dateien in einem MIME-beschränkten Bucket löschen (`InvalidMimeType: application/json`)
Wenn du in deinem Supabase-Bucket unter **Allowed MIME types** nur bestimmte Typen wie `application/pdf` oder `image/*` zulässt, gibt Supabase den Fehler `InvalidMimeType` zurück, sobald der Papierkorb versucht, `.trash/<id>/.meta.json` zu schreiben.
- **Automatischer Fallback:** `TrashManager` erkennt dieses Verhalten automatisch – die Datei selbst wird im Cloud-Bucket nach `.trash/<id>/...` verschoben, während die `.meta.json`-Metadaten in eine lokale Fallback-Datei auf deinem Server geschrieben werden (`trash-meta.json` bzw. `<name>.trash-meta.json` im Verzeichnis `defaults.metadata.file`). Das Wiederherstellen und endgültige Löschen funktionieren dadurch nahtlos weiter.

### 2. Prüfsummen- und ACL-Fehler ab AWS SDK v3.337+
Für S3-Volumes mit einem benutzerdefinierten `endpoint` wie Supabase wendet `FlysystemStorageFactory` automatisch folgende Einstellungen an:
- `request_checksum_calculation: 'when_required'` und `response_checksum_validation: 'when_required'`
- `retain_visibility: false` sowie das Entfernen von `x-amz-acl`-Headern (`disable_acl: true`)

Es ist keine zusätzliche Konfiguration erforderlich.

### 3. SSL-Zertifikatsfehler `cURL error 60` unter Windows / WAMP
Falls auf deiner lokalen Entwicklungsumgebung der Fehler `cURL error 60: unable to get local issuer certificate` auftritt:
- Lade [cacert.pem](https://curl.se/ca/cacert.pem) herunter und referenziere die Datei in deiner `php.ini` (sowohl in der Apache- als auch in der CLI-`php.ini`):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Alternativ kannst du das Zertifikat gezielt für ein einzelnes Volume über `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` hinterlegen.

### 4. Im Supabase-Dashboard gelöschte Dateien erscheinen noch in der Liste
- Klicke auf die Schaltfläche **Aktualisieren (`↻`)** in der Symbolleiste (oder drücke `R`), um den Cache sofort über `?refresh=1` zu umgehen.
- Wenn Dateien im Bucket häufig extern bearbeitet oder gelöscht werden, setze `cache_ttl: 30` (oder `cache_ttl: 0`, um das Caching vollständig zu deaktivieren) direkt am Volume.

---

[← 07 — Cloud-Speicher & Dynamische Konfiguration](../07-cloud-storage-and-dynamic-config.md) | [Weiter: AWS-S3-Leitfaden →](aws-s3.md)
