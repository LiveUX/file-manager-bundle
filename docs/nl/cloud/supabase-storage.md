# Integratiegids voor Supabase Storage (S3)

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Hoofdindex](../README.md)

> **Wanneer kies je voor Supabase Storage?**  
> Als je al een Supabase-project gebruikt — of een royale gratis tier zoekt om zowel publieke CDN-assets als privé-documentbuckets vanuit één dashboard te beheren — is Supabase Storage de meest praktische S3-compatibele keuze.

Omdat **Supabase Storage** het S3-compatibele protocol ondersteunt, werkt het out of the box met LiveUX FileManagerBundle in een hybride **Lokale opslag + Supabase Cloud**-opstelling, zonder dat je een enkele regel eigen code hoeft te schrijven. De bundle herkent de `supabase`-provider automatisch op basis van de endpoint-URL en toont het Supabase SVG-icoon in de zijbalk.

---

## Stap 1: Vereiste packages installeren

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Stap 2: S3 Access Keys aanmaken in het Supabase Dashboard

> [!WARNING]
> **Belangrijk:** De REST/Auth API-sleutels van Supabase — `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) en `SUPABASE_SECRET_KEY` (`sb_secret_...`) — werken **niet** met het S3-protocol. Je moet de sleutels van 32 en 64 tekens gebruiken die hieronder bij **S3 Access Keys** worden gegenereerd.

1. Open je project in het Supabase Dashboard → **Storage** → **New bucket** en maak een bucket aan (bijv. `media` of `prints`).
   - Vink **Public bucket** aan voor publiek toegankelijke CDN-afbeeldingen en assets.
   - Laat **Public bucket** uitgevinkt (privé) voor klantdocumenten of facturen.
2. Ga naar **Project Settings** → **Storage** (of **Storage** → **Settings**).
3. Kopieer deze twee waarden uit de kaart **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** bijv. `eu-central-1` of `eu-west-1`
4. Klik onder **S3 Access Keys** op **New access key**, geef een omschrijving op en kopieer de gegenereerde waarden:
   - **Access Key ID** (32 tekens)
   - **Secret Access Key** (64 tekens — wordt slechts één keer getoond!)

---

## Stap 3: Omgevingsvariabelen toevoegen aan `.env.local`

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

## Stap 4: `liveux_file_manager.yaml` configureren

### Scenario A — Publieke bucket

Afbeeldingen, video's en publieke bestanden worden rechtstreeks via de Supabase CDN-URL uitgeserveerd (waardoor je PHP-server wordt ontlast), terwijl thumbnails eenmalig op je server worden gegenereerd en vanuit de lokale cache (`public/media-cache`) worden geserveerd:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokale opslag'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Optioneel — wordt automatisch herkend aan het endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # 60s cache voor cloudinhoud die extern kan wijzigen
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
                volume_order: 'local_first' # of 'network_first' / 'configured'
```

### Scenario B — Privé-bucket met isolatie per gebruiker

Voor klantfacturen of vertrouwelijke pdf's die uitsluitend toegankelijk mogen zijn voor ingelogde en geautoriseerde gebruikers:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Supabase-documentkluis'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Geen publieke URL omdat deze bucket privé is
            private: true             # Veilig streamen via DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Isoleert elke gebruiker in een eigen submap zonder conflicten
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

## Stap 5: Live-verbinding controleren met één commando

```bash
php bin/console file-manager:doctor --check-remote
```

Voorbeelduitvoer bij succes:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Opmerking:** Dynamische volumes per gebruiker met placeholders zoals `prefix: 'users/{user_id}'` (zoals in Scenario B) worden door `file-manager:doctor --check-remote` veilig overgeslagen met de status `INFO`, omdat er in de CLI-omgeving geen actieve gebruikerssessie aanwezig is.

---

## Supabase-specifieke tips & probleemoplossing

### 1. Bestanden verwijderen in een bucket met MIME-beperking (`InvalidMimeType: application/json`)
Als je in je Supabase-bucket bij **Allowed MIME types** alleen specifieke typen zoals `application/pdf` of `image/*` hebt toegestaan, geeft Supabase een `InvalidMimeType`-fout terug zodra de prullenbakfunctie `.trash/<id>/.meta.json` probeert weg te schrijven.
- **Automatische fallback:** `TrashManager` detecteert dit automatisch: het bestand zelf wordt verplaatst naar `.trash/<id>/...` in de cloudbucket, terwijl de `.meta.json`-metadata wordt opgeslagen in een lokaal fallback-bestand op je server (`trash-meta.json` of `<name>.trash-meta.json` in de map `defaults.metadata.file`). Herstellen en definitief verwijderen blijven probleemloos werken.

### 2. Checksum- en ACL-fouten vanaf AWS SDK v3.337+
Voor S3-volumes met een eigen `endpoint` zoals Supabase past `FlysystemStorageFactory` automatisch het volgende toe:
- `request_checksum_calculation: 'when_required'` en `response_checksum_validation: 'when_required'`
- `retain_visibility: false` en het strippen van `x-amz-acl`-headers (`disable_acl: true`)

Hiervoor is geen extra configuratie nodig.

### 3. SSL-certificaatfout `cURL error 60` op Windows / WAMP
Krijg je op je lokale ontwikkelmachine de foutmelding `cURL error 60: unable to get local issuer certificate`?
- Download [cacert.pem](https://curl.se/ca/cacert.pem) en verwijs ernaar in `php.ini` (zowel in de `php.ini` van Apache als die van de CLI):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- Als alternatief kun je het certificaat uitsluitend voor een specifiek volume instellen via `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. Bestanden die in het Supabase Dashboard zijn verwijderd blijven zichtbaar in de lijst
- Klik op de knop **Vernieuwen (`↻`)** in de werkbalk (of druk op `R`) om de cache direct te omzeilen via `?refresh=1`.
- Worden bestanden in de bucket regelmatig extern gewijzigd? Stel dan op het volume `cache_ttl: 30` in (of `cache_ttl: 0` om caching uit te schakelen).

---

[← 07 — Cloudopslag & Dynamische Configuratie](../07-cloud-storage-and-dynamic-config.md) | [Volgende: AWS S3-gids →](aws-s3.md)
