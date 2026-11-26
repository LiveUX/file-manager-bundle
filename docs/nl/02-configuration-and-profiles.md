# 02 — Configuratie & profielen (Volledige parameterreferentie & recepten)

[← Vorige: 01 — Vereisten & installatie](01-requirements-and-installation.md) | [Documentatie-overzicht](README.md) | [Volgende: 03 — Gebruiksmodi & JS API →](03-usage-modes-and-js-api.md)

Deze gids legt **elke optie** in `config/packages/liveux_file_manager.yaml` uit, inclusief de standaardwaarde, wanneer je deze aanpast en **5 kant-en-klare productierecepten** die je direct in je project kunt kopiëren.

---

## Mentaal model in 30 seconden: `defaults`, `volumes` en `profiles`

Denk bij het configuratiebestand aan **3 heldere lagen**:

1. **`defaults` (Globale standaarden):** Gedeelde basisinstellingen die elk profiel overerft (thema, locale, kleurmodus, cache, metadata-driver, auditlogging, AI-standaarden). Tenzij overschreven op een specifiek profiel, gelden deze waarden overal.
2. **`volumes` (Opslagschijven / buckets):** Bepaalt **waar bestanden fysiek worden opgeslagen** (lokale map, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage of een eigen Flysystem-service).
3. **`profiles` (Gebruiksprofielen):** Bepaalt de **regels, UI-indeling en rechten** voor een specifiek scherm of proces. Bijvoorbeeld:
   - `product_catalog` staat alleen vierkante `1:1`-productfoto's toe en converteert ze automatisch naar WebP;
   - `customer_docs` koppelt aan een privé Supabase S3-bucket, accepteert alleen PDF's en isoleert elke gebruiker in een eigen map;
   - `editor_picker` opent een gestroomlijnde modale kiezer voor TinyMCE met selectie via dubbelklikken.

---

## 1. `defaults` — Globale standaarden

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = neemt de actieve Symfony-locale over (valt terug op 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parameter | Standaard | Wat het doet | Wanneer aanpassen |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Standaard hoofdmap voor verkorte profielen die inline `path` definiëren zonder `volumes`. | Wanneer je lokale bestanden in een andere map opslaat, zoals `public/media`. |
| `theme` | `'bootstrap5'` | Standaard UI-thema voor alle profielen (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` of je eigen themanaam). | Om aan te sluiten op het designsystem van je app (bijv. `'shadcn'` of `'tailwind'` in Tailwind-projecten). |
| `locale` | `null` | Bij `null` wordt automatisch de actieve Symfony request-/translator-locale overgenomen uit alle **12 ingebouwde UI-talen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), worden BCP-47- & POSIX-formaten genormaliseerd (`nl_NL.UTF-8`, `zh-Hans-CN`), wordt RTL (`dir="rtl"`) automatisch ingeschakeld voor Arabisch en andere RTL-schriften en valt het bij onbekende locales terug op `'en'`. | Stel expliciet in (bijv. `'nl'`, `'en'`, `'de'`) om alle gebruikers op één vaste UI-taal vast te zetten. |
| `color_scheme` | `'auto'` | Kleurmodus: `'auto'` (detecteert `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (altijd licht) of `'dark'` (altijd donker). | Wanneer je een uitsluitend licht of donker beheerthema wilt afdwingen. |
| `z_index` | `1050` | Basis-stackingcontext (`--liveux-fm-z-base`) voor modals, OffCanvas-panelen, Lightbox en contextmenu's (`>= 1`). | Verhoog naar bijv. `10050` als de navigatiebalk van je beheerlayout `z-index: 9999` gebruikt. |
| `auto_inject_dialog_host` | `true` | Voegt automatisch de `<dialog>`-host die vereist is voor `window.LiveUXFileManager.open()` in vóór `</body>` op volledige HTML-pagina's (geen aanpassingen in `base.html.twig` nodig). | Zet op `false` als je `@LiveUXFileManager/components/DialogHost.html.twig` liever handmatig opneemt in specifieke layouts. |
| `dialog_host_profile` | `'default'` | Standaardprofiel dat door de automatisch geïnjecteerde `DialogHost` wordt gebruikt wanneer geen profiel is meegegeven. | Wanneer je hoofdprofiel niet `default` heet (bijv. `admin_media`). |
| `cache.enabled` | `true` | Server-side PSR-6-cache voor mapoverzichten en mappenstructuren. | Zet op `false` tijdens lokaal debuggen (of schakel per profiel/volume uit). |
| `cache.pool` | `'cache.app'` | Symfony PSR-6-cachepool (Redis, Filesystem, APCu, enz.). Gebruikt automatisch `<pool>.taggable` indien beschikbaar voor gerichte tag-invalidatie. | Om cache-items van het bestandsbeheer te isoleren in een aparte Redis-pool. |
| `cache.ttl` | `3600` | Levensduur van de cache in seconden (standaard: 1 uur). Mutaties (uploaden, verwijderen, verplaatsen, naam wijzigen) invalideren betrokken mappen automatisch. | Verlaag naar `60`–`300` wanneer bestanden vaak extern worden gewijzigd via FTP of clouddashboards. |
| `metadata.enabled` | `true` | Slaat mapkleuren/-pictogrammen/vastgezette mappen en SEO-metadata van bestanden (`alt`, `title`, `caption`, `tags`, `focalPoint`) op. | Zet op `false` voor kale bestandskiezers die geen redactionele metadata nodig hebben. |
| `metadata.driver` | `'json_file'` | Metadata-backend: `'json_file'` (lokaal bestand voor single-server apps), `'doctrine'` (ingebouwde DBAL-opslag voor multi-server / load-balanced apps op MySQL, MariaDB, PostgreSQL of SQLite) of `'none'`. | Schakel over op `'doctrine'` zodra je meerdere app-replica's draait (Kubernetes, load balancer, Docker Swarm) — zie [Hoofdstuk 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Opslagpad voor de `json_file`-driver. Een bijbehorend prullenbak-sidecarbestand (`trash-meta.json` of `<naam>.trash-meta.json`) wordt in dezelfde map bewaard voor cloudbuckets met MIME-beperkingen. | Verplaats indien nodig naar een gedeeld NFS-/volumepad. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | DBAL-verbindingsnaam, EntityManager, tabelnaam (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) en vlag voor automatisch aanmaken van de tabel bij `driver: 'doctrine'`. | Zet `auto_create_table: false` in productie wanneer je het schema via Doctrine Migrations beheert (zie [Hoofdstuk 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Schrijft gestructureerde PSR-3-auditregels (`user`, `ip`, `path`, `volume`) voor alle 9 bestands-/mapmutaties (uploaden, verwijderen, verplaatsen, kopiëren, naam wijzigen, dupliceren, nieuwe map, herstellen uit prullenbak, definitief verwijderen). | Schakel in (`true`) in compliance-gevoelige omgevingen waar je moet kunnen herleiden *"wie heeft wat verwijderd, en wanneer?"*. |
| `audit_log.level` | `'info'` | PSR-3-logniveau voor auditregels (`debug`, `info`, `notice`, `warning`). | Pas aan op de routing-/filterregels van Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | Standaard vision-/tekstmodel wanneer het optionele pakket `symfony/ai-bundle` is geïnstalleerd (zie [Hoofdstuk 09](09-symfony-ai-integration.md)). | Schakel over naar Gemini, Claude of een lokaal Ollama-model. |
| `ai.platform_service` | `null` | Eigen Symfony AI `PlatformInterface`-service-ID (`null` gebruikt het standaard automatisch geregistreerde platform). | Wanneer je applicatie meerdere Symfony AI-platformen definieert. |

---

## 2. `volumes` — Opslagvolumes (Lokaal & Cloud)

Eén profiel kan meerdere volumes tegelijk aankoppelen (bijvoorbeeld zowel een lokale schijf als Supabase/R2/S3). Gebruikers kunnen bestanden direct van een lokale map naar een cloudbucket slepen.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokale schijf'
            icon: 'disk'              # Optioneel (wordt bij weglaten automatisch gedetecteerd via driver/endpoint)
            group: 'local'            # Optioneel (null -> 'local' voor LocalFileSystem, 'network' voor S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # Optionele TTL per volume (0 = uitgeschakeld)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Wordt ook automatisch gedetecteerd via het endpoint
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Externe wijzigingen via clouddashboard verschijnen binnen 60 seconden
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parameter | Standaard | Wat het doet | Wanneer aanpassen |
| :--- | :--- | :--- | :--- |
| `label` | Volumesleutel | Weergavenaam in de zijbalk, het kruimelpad en de volumekiezer. | Geef een duidelijke naam op (bijv. `'Productmedia CDN'`). |
| `icon` | `null` *(auto)* | SVG-pictogram in de zijbalk. Bij `null` automatisch gedetecteerd op basis van `driver` en `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Verwijs naar een eigen `<dir>/ui/my_icon.svg` in je pictogramset. |
| `group` | `null` *(auto)* | Sectiegroep in de zijbalk. Bij `null` krijgt `LocalFileSystem` standaard `'local'` en krijgen `AwsS3` / `FlysystemService` standaard `'network'`. | Overschrijf als je een NFS-mount onder `'network'` of een cloudschijf onder `'local'` wilt groeperen. |
| `driver` | `'LocalFileSystem'` | Opslagbackend: lokaal bestandssysteem (`LocalFileSystem`), S3-compatibele objectopslag (`AwsS3`) of een bestaande Flysystem-service (`FlysystemService`). | Gebruik `'AwsS3'` voor S3/R2/Supabase/B2/MinIO/GCS, of `'FlysystemService'` voor SFTP/Azure/Dropbox. |
| `path` | `null` | Fysiek mappad voor `LocalFileSystem` (ondersteunt `{user_id}`, `{Y}`, `{m}`, `{d}`). | Stel een eigen hoofdmap of per-gebruiker isolatiepad in (`.../users/{user_id}`). |
| `url_prefix` | `null` | Openbaar URL-voorvoegsel waarmee browsers publieke bestanden ophalen (`/uploads` of `https://cdn.example.com`). Wordt genegeerd bij `private: true`. | Configureer wanneer je assets serveert via een CDN of openbare S3/R2/Supabase-bucket. |
| `private` | `false` | Bij `true` worden bestanden nooit via directe publieke URL's aangeboden — alle downloads, voorbeelden en miniaturen lopen via Symfony Voter-controles (`DownloadController`). | **Zet altijd op `true`** voor facturen, identiteitsbewijzen, contracten of privé-klantbestanden. |
| `cache_ttl` | `null` *(overgeërfd)* | Cache-TTL (in seconden) specifiek voor dit volume. `0` schakelt caching uit voor dit volume; `null` erft van profiel/defaults. | Houd lokale schijf `3600`s gecachet terwijl je een cloudbucket op `60`s of `0` zet. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(tijdelijk STS-sessietoken)*, `version` *(standaard `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(automatisch `true` voor custom endpoints; standaard `false` op standaard AWS S3 — zet expliciet op `true` voor Bucket owner enforced)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Zie [Hoofdstuk 07](07-cloud-storage-and-dynamic-config.md) voor provider-specifieke gidsen. |

---

## 3. `profiles` — Parameteroverzicht voor profielen

### 3.1. Basisopslag, filtering & quotum
| Parameter | Standaard | Wat het doet | Wanneer aanpassen |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Overschrijft de taal voor UI en foutmeldingen op dit profiel (`'nl'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, enz.; `null` erft `defaults.locale` → Symfony request-locale → `'en'`). | Wanneer verschillende onderdelen van dezelfde app op andere talen gericht zijn. |
| `volumes` | `[]` | Geordende lijst van volumesleutels die in dit profiel toegankelijk zijn (bijv. `['local_uploads', 'supabase_s3']`). Het eerste volume wordt standaard geopend. | Om één of meer lokale of cloudvolumes te koppelen. |
| `ignore_dot_files` | `true` | Verbergt puntbestanden (`.env`, `.htaccess`, `.DS_Store`, `.trash`) in overzichten en blokkeert directe toegang ertoe. | Houd op `true` voor de veiligheid. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Verbergt en blokkeert directe toegang tot bestanden of mappen die overeenkomen met regex- of wildcardpatronen. | Verberg interne mappen zoals `/^cache$/i` of `/^backups$/i`. |
| `sort_by` | `'name'` | Standaard sorteerkolom: `'name'`, `'date'`, `'size'` of `'type'`. | Gebruik `'date'` + `sort_direction: 'desc'` om de nieuwste uploads bovenaan te tonen. |
| `sort_direction` | `'asc'` | Sorteervolgorde: `'asc'` (A→Z / kleinste eerst) of `'desc'` (Z→A / nieuwste eerst). | `'desc'` werkt prettig voor mediagalerijen en redactionele bibliotheken. |
| `folders_first` | `true` | Toont mappen altijd boven bestanden. | Zet op `false` voor een vlakke alfabetische of chronologische sortering. |
| `storage_quota` | `null` | Maximaal toegestaan totaal schijfgebruik voor het profiel (bijv. `'500M'`, `'2G'`). Zodra dit bereikt is, worden uploads server-side geweigerd en toont de zijbalk een gebruiksbalk. | Essentieel voor multi-tenant SaaS of quota per gebruiker (`{user_id}`). |
| `upload_subfolder` | `null` | Dynamisch submap-sjabloon waarin geüploade bestanden automatisch onder de huidige map worden geplaatst (bijv. `'{Y}/{m}'`). | Organiseer uploads automatisch in datummappen zoals `2026/11`. |

### 3.2. Uploads, uploaden in delen (`chunk_upload`) & duplicaatdetectie (`duplicate_detection`)
| Parameter | Standaard | Wat het doet | Wanneer aanpassen |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(alle veilige)* | Allowlist van toegestane MIME-typen (bijv. `['image/jpeg', 'image/png', 'image/webp']`). Wanneer leeg, zijn alle veilige typen die niet in `upload_deny` staan toegestaan. | Beperk altijd tot specifieke MIME-typen voor formulieren die alleen afbeeldingen of PDF's accepteren. |
| `upload_deny` | **47 gevaarlijke MIME-typen** | Strikt geblokkeerde MIME-typen (PHP, HTML, niet-SVG XML, JS, Shell, EXE, Java, CGI, enz. — server-side gevalideerd via magic bytes). | Voeg extra geblokkeerde MIME-typen toe als je beveiligingsbeleid dat vereist. |
| `upload_max_size` | `'10M'` | Maximale **samengevoegde** grootte van één geüpload bestand (bijv. `'200M'`, `'1G'`). Met chunked uploads worden bestanden die veel groter zijn dan PHP's `2M`-limiet moeiteloos geüpload tot deze grens. | Verhoog naar `'200M'`–`'500M'` voor video-/catalogus-PDF-profielen, of verlaag naar `'2M'` voor avatars. |
| `overwrite_policy` | `'rename'` | Gedrag bij een naambotsing: `'rename'` (`photo-1.jpg`), `'overwrite'` of `'reject'`. | Houd op `'rename'` om onbedoeld gegevensverlies te voorkomen. |
| `chunk_upload.enabled` | `true` | Splitst grote uploads op in kleine delen met Pauzeren / Hervatten / Bevestigd annuleren en automatisch opnieuw proberen bij tijdelijke netwerkfouten. | Zelden uitschakelen — alleen als je strikt kleine bestanden (< 1 MB) uploadt. |
| `chunk_upload.chunk_size` | `'2M'` | Gewenste deelgrootte. Wordt automatisch begrensd op **80%** van PHP's `upload_max_filesize` en `post_max_size` (bijv. 1,60 MB bij een PHP-limiet van 2M). | Verhoog naar `'5M'` op servers met ruime PHP-limieten (bijv. 32M+) voor snellere grote uploads. |
| `chunk_upload.parallel_uploads` | `3` | Maximaal aantal gelijktijdige bestandsuploads (`1`–`10`). | Gebruik `2` op lichtere servers, of `4`–`5` op snelle cloud-/CDN-verbindingen. |
| `chunk_upload.max_retries` | `3` | Aantal herpogingen met exponentiële backoff per uploaddeel bij netwerkonderbrekingen (`0`–`10`). | `3`–`5` werkt uitstekend bij mobiele of onstabiele verbindingen. |
| `duplicate_detection.enabled` | `false` | Detecteert dubbele uploads via SHA-256-inhoudshashing. | Schakel in (`true`) om te voorkomen dat redacteuren dezelfde productfoto keer op keer uploaden. |
| `duplicate_detection.policy` | `'warn'` | Actie bij duplicaatdetectie: `'warn'` (uploaden & waarschuwen), `'reject'` (upload blokkeren) of `'reuse'` (bestaand bestand hergebruiken zonder extra opslag in te nemen). | `'reuse'` levert flinke opslagbesparing op bij webshops en CMS-websites. |
| `duplicate_detection.max_scan_files` | `5000` | Bovengrens voor het aantal geïndexeerde bestanden bij duplicaatdetectie (`100`–`50000`) om CPU-/I/O-pieken op grote archieven te voorkomen. | Verhoog naar `10000`–`25000` voor grote mediabibliotheken. |

### 3.3. Automatische bestandsnaamnormalisatie (`naming`)
| Parameter | Standaard | Wat het doet | Voorbeeld (`"Product Foto 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Translitereert tekens met accenten/Unicode en spaties naar schone, URL-vriendelijke ASCII-slugs. | `product-foto-2026.jpg` |
| `naming.slugger_locale` | `null` | Transliteratielocale (`null` erft de profiellocale; bijv. `'de'` zet `ä/ö/ü/ß` om, `'tr'` zet `İ/ı/ş/ğ/ç/ö/ü` om). | Stel in op de taal van je content (bijv. `'nl'`, `'de'`, `'tr'`, `'fr'`). |
| `naming.transformer` | `'none'` | Hoofdletter-/naamtransformatie: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` of `'folder'` (geeft het bestand de naam van de bovenliggende map!). | `'snake'` → `product_foto_2026.jpg`, `'folder'` → `thermosbekers-1.jpg` |
| `naming.prepend` / `append` | `''` | Voegt vaste tekst of dynamische tokens (`{Y}`, `{m}`, `{d}`, `{uniqid}`) voor of achter de bestandsnaam toe. | `prepend: '{Y}{m}_'` → `202611_product.jpg` |
| `naming.apply_on_rename` | `true` | Past `slugger`- en `transformer`-regels ook toe wanneer een gebruiker een bestand in de UI hernoemt. | Houd op `true` voor consistent schone bestandsnamen na het hernoemen. |
| `naming.apply_affixes_on_rename` | `false` | Voorkomt dat `prepend`-/`append`-tokens bij elke naamswijziging opnieuw worden toegevoegd. | Houd op `false`. |
| `naming.allow_extension_change` | `false` | Blokkeert dat gebruikers bestandsextensies wijzigen (bijv. `.jpg` → `.php` of `.png`) tijdens het hernoemen. | Houd op `false` voor de veiligheid. |

### 3.4. Afbeeldingsoptimalisatie (`image_optimization`) & afbeeldingsregels (`image_constraints`)
- **`image_optimization`:** Corrigeert automatisch oriëntatie, verwijdert EXIF-metadata, verkleint en comprimeert grote foto's op de server tijdens het uploaden.
- **`image_constraints`:** Weigert niet-conforme afbeeldingen **zowel in 0 ms in de browser als op de server** (bijvoorbeeld om wazige foto's met te lage resolutie of niet-vierkante productfoto's te blokkeren).

| Parameter | Standaard | Wat het doet | Wanneer gebruiken |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Schakelt afbeeldingsoptimalisatie tijdens het uploaden in (Imagick → GD → stille no-op als geen van beide beschikbaar is). | Schakel in (`true`) op elk profiel waar gebruikers onbewerkte smartphonefoto's uploaden. |
| `image_optimization.auto_orient` | `true` | Leest EXIF-oriëntatietags en draait gekantelde telefoonfoto's automatisch recht. | Houd op `true` voor mobiele uploads. |
| `image_optimization.strip_exif` | `true` | Verwijdert GPS-coördinaten en camera-EXIF-gegevens (AVG/GDPR-privacy + kleinere bestanden). | Houd op `true` voor alle publiek zichtbare afbeeldingen. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Schaalverkleining voor afbeeldingen die deze grenzen overschrijden met behoud van beeldverhouding (`>= 100`). | `1920`×`1920` is een ideale balans voor webafbeeldingen. |
| `image_optimization.convert_to_webp` | `false` | Converteert geüploade JPEG/PNG-bestanden automatisch naar WebP (30–60% kleinere bestanden). | Schakel in (`true`) voor moderne, snelle webprofielen. |
| `image_optimization.quality` | `85` | Compressiekwaliteit van de uitvoer (`10`–`100`). | `80`–`85` biedt de beste verhouding tussen grootte en beeldkwaliteit. |
| `image_constraints.min_width` / `min_height` | `null` | Minimaal vereiste afmetingen in pixels. | Voorkom wazige uploads (bijv. blogcovers van minimaal `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Maximaal toegestane afmetingen in pixels (wordt gecontroleerd *ná* schaalverkleining door `image_optimization` indien ingeschakeld). | Dwing strikte banner- of drukwerkformaten af. |
| `image_constraints.aspect_ratio` | `null` | Vereiste beeldverhouding: `'1:1'` (vierkant), `'16:9'` (breedbeeldbanner), `'4:3'`, `'3:4'`, `'1.91:1'`, enz. | Vierkante webshop-productkaarten (`'1:1'`) of video-/artikelheaders (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Toegestane afwijking van de doelverhouding (`0.0`–`0.5`; `0.05` = 5% tolerantie, zodat zowel `1920×1080` als `1280×722` wordt geaccepteerd). | `0.05` voldoet in vrijwel alle situaties. |

### 3.5. Miniaturen, prullenbak (`trash`), ZIP (`zip`) & ondertekende deellinks (`share`)
| Parameter | Standaard | Wat het doet |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Genereert gecachete miniaturen voor afbeeldingen: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` of `'none'`. Cloudafbeeldingen worden eenmalig opgehaald en lokaal gecachet. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Gedrag wanneer noch GD noch Imagick is geïnstalleerd: `'fallback_original'` (originele afbeelding tonen), `'icon'` (bestandspictogram tonen) of `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Miniatuurafmetingen (`>= 32`), uitvoerindeling (`webp`, `jpeg`, `png`) en pasvorm (`contain` = volledige afbeelding zichtbaar, `cover` = gecentreerd vullend bijsnijden). Overweeg `480`×`360` bij `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | Voegt native `loading="lazy"` toe aan rasterkaarten voor snellere initiële weergave in grote mappen. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Lokale map waarin gegenereerde miniaturen worden gecachet. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Verplaatst verwijderde bestanden naar een prullenbakmap (soft delete) in plaats van ze direct te vernietigen. Valt automatisch terug op een lokale `trash-meta.json`-sidecar bij cloudbuckets met MIME-beperkingen. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Schakelt met één klik `.zip`-downloads van meerdere bestanden/mappen in (vereist PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | 7-laags tegen Zip-Slip & ZIP-bommen beveiligd archief uitpakken. Altijd geblokkeerd zolang `allow_extract: false`; inschakelen zet `permissions.extract_zip` automatisch op `true` (wat je kunt beperken tot `'ROLE_ADMIN'` of `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Genereert tijdelijke HMAC-ondertekende deel-URL's via Symfony's `UriSigner`. Instellen op `enabled: false` maakt alle eerder uitgegeven deellinks direct ongeldig. |

### 3.6. Gebruikersinterface (`ui`) — Indeling, formaten & werkbalkopties
| Parameter | Standaard | Wat het doet | Wanneer aanpassen |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Profielspecifiek thema (bijv. `'bootstrap5'` in het beheerpaneel, `'shadcn'` in het klantportaal). | Wanneer verschillende delen van een app verschillende visuele thema's gebruiken. |
| `ui.color_scheme` | `null` *(defaults)* | Profielspecifieke kleurmodus (`auto`, `light`, `dark`). | Zet `'dark'` vast op een donkere werkruimte. |
| `ui.icon_set` | `'classic'` | Stijl van bestands-/mappictogrammen: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` of een eigen pad naar SVG's (`'%kernel.project_dir%/assets/fm-icons'`). | Kies `'windows11'` of `'macos'` voor een desktopgevoel, of verwijs naar eigen huisstijl-SVG's. |
| `ui.z_index` | `null` *(defaults)* | Profielspecifieke overschrijving voor de z-index van overlays. | Wanneer ingebed op een pagina met editors of drawers met een hoge `z-index`. |
| `ui.mobile_dialog_mode` | `'auto'` | Weergave van dialoogvensters op kleine schermen (`<= 767.98px`): `'auto'` (modals & zij-offcanvas gaan naar volledig scherm, boven-/onder-offcanvas worden afgeronde native sheets), `'fullscreen'` (altijd volledig scherm) of `'sheet'` (altijd openen als native bottom sheet). | Forceer `'sheet'` of `'fullscreen'` voor alle mobiele kiezers. |
| `ui.default_view` | `'grid'` | Initiële weergave: `'grid'` (Raster met miniatuurkaarten) of `'list'` (sorteerbare Lijst-tabel). | Gebruik `'list'` voor PDF-/documentarchieven en `'grid'` voor visuele galerijen. |
| `ui.item_size` | `'md'` | Breedte van rasterkaarten en schaal van lijstminiaturen: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) of een exact pixelgetal (`96`–`320`). Schakelt bij `<= 132px` automatisch over op compacte padding. | Gebruik `'lg'` / `'xl'` voor visuele beoordeling en `'sm'` / `'xs'` voor compacte kiezers. |
| `ui.card_aspect_ratio` | `'4/3'` | Beeldverhouding van kaartvoorbeelden: `'1/1'` (vierkant), `'4/3'` (standaard), `'16/9'` (breedbeeld/video) of `'3/4'` (staande posters/catalogi). | Stem af op je voornaamste assetvorm (`'1/1'` voor producten, `'16/9'` voor video/banners). |
| `ui.folder_style` | `'tile'` | Mapstijl in rasterweergave: `'tile'` (Compact — horizontale maptegels in Google Drive-stijl boven bestanden) of `'card'` (Kaart — volwaardige mapkaarten in Windows/Finder-stijl op hetzelfde formaat als bestandskaarten). | Kies `'card'` voor visueel prominentere mappen. |
| `ui.show_size_slider` | `true` | Toont de live schuifregelaar voor Itemgrootte (`96px`–`320px`) en de Mapstijl-schakelaar in het menu Instellingen. | Zet op `false` om een vaste weergavedichtheid af te dwingen. |
| `ui.volume_order` | `'local_first'` | Volgorde van volumes in de zijbalk en volumekiezer: `'local_first'`, `'network_first'` of `'configured'` (exacte YAML-volgorde uit `volumes: [...]` zonder groepsscheidingen). | Gebruik `'network_first'` of `'configured'` voor cloud-first workflows. |
| `ui.show_folder_tree` | `true` | Toont of verbergt de zijbalk met de mappenstructuur aan de linkerkant. | Zet op `false` voor eenvoudige TinyMCE- of avatarkiezers met één map. |
| `ui.tree_lines` | `true` | Tekent verbindingslijnen in de mappenboom. | Zet op `false` voor een rustigere, minimalistische zijbalk. |
| `ui.show_folder_item_count` | `true` | Toont telbadges (`12`) naast mappen. Respecteert autorisatie zodat afgeschermde submappen nooit via tellers lekken. | Laat op `true` voor direct inzicht in de mapinhoud. |
| `ui.folder_count_mode` | `'recursive_files'` | Hoe maptellers worden berekend: `'recursive_files'` (alle bestanden inclusief submappen), `'files'` (alleen directe bestanden) of `'items'` (directe bestanden + submappen). | Kies `'files'` voor uitsluitend directe tellingen. |
| `ui.inspector_mode` | `'on_select'` | Rechterpaneel Voorbeeld & details: `'on_select'` (opent zodra een item wordt geselecteerd), `'always'` of `'hidden'`. | Zet op `'hidden'` in smalle modale kiezers. |
| `ui.folder_open_on` | `'dblclick'` | Interactie om mappen te openen: `'dblclick'` (enkelklik selecteert, dubbelklik opent — desktopconventie) of `'click'` (enkelklik opent direct — web-/mobiele conventie). | Gebruik `'click'` om razendsnel met één klik door beheerpanelen te bladeren. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Standaard aantal items per pagina (`6`–`200`) en keuzemogelijkheden voor de gebruiker. | Verhoog naar `48` en `[24, 48, 96, 150]` voor grote catalogi. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Toont de opslagquotumbalk in de zijbalk en het klikbare venster Opslaggebruik & quotumdetails. (Server-side afdwinging van `storage_quota` blijft altijd actief, ook als dit verborgen is.) | Zet `show_quota: false` als je geen quotummeters in de UI wilt tonen. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Regelt inline PDF Lightbox en HTML5-videoweergave (zowel in de UI als server-side in `DownloadController` afgedwongen). | Zet op `false` om directe download af te dwingen in plaats van inline browservoorbeeld. |
| `ui.remember_last_dir` | `true` | Onthoudt het laatst bezochte **volume + map** van de gebruiker in `localStorage` (valt automatisch terug op de bovenliggende map of hoofdmap als die map is verwijderd). | Zet op `false` als elke sessie in de hoofdmap moet beginnen. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Vraagt om bevestiging vóór het verwijderen of het verplaatsen via drag-and-drop. | Zet `confirm_move: false` voor snellere herordeningsworkflows. |
| `ui.double_click_to_select` | `true` | In Modal-/OffCanvas-kiezermodus selecteert dubbelklikken op een bestand dit direct en sluit het dialoogvenster. | Zet op `false` als dubbelklikken een voorbeeld moet openen in plaats van te selecteren. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Schakelt rechtermuisknop-contextmenu's in en de snelle navigatiebalk `[ ← \| → \| ↑ \| ↻ ]` bij rechtsklikken op lege ruimte. | Zet op `false` voor streng afgeschermde eindgebruikersschermen. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Schakelt verplaatsen via drag-and-drop tussen mappen en uploaden door bestanden/mappen vanuit het besturingssysteem te slepen in. | Zet op `false` voor alleen-lezen mediabibliotheken. |
| `ui.keyboard_shortcuts` | `true` | Schakelt sneltoetsen in (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Delete`, `Spatie`, `/`, `R`, `F`, `?`, enz.). | Schakel uit (`false`) als je omliggende pagina conflicterende globale sneltoetsen gebruikt. |
| `ui.show_*`-vlaggen | *(meestal `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Schakel individuele werkbalkonderdelen in of uit om de interface te vereenvoudigen. *(Let op: de vermeldingen "Over" en "GitHub" in Instellingen zijn vaste attributie-items en worden niet via YAML uitgeschakeld.)* |

### 3.7. Cache per profiel (`cache`), beveiliging (`security`), rechten (`permissions`), AI & Usage Guard

#### Cache (`cache`)
| Parameter | Standaard | Beschrijving |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Schakelt caching voor dit profiel in of uit. Wanneer een profiel `cache.enabled: false` instelt, forceert `volume.cache_ttl` caching niet opnieuw aan. |
| `cache.ttl` | `null` *(defaults)* | Voorrangsvolgorde voor TTL: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Beveiliging (`security`)
| Parameter | Standaard | Beschrijving |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Schakelt RBAC-afdwinging via `FileManagerVoter` en eventuele eigen Voters in. Houd in productie altijd op `true`. |
| `security.csrf_protection` | `true` | Vereist een geldige `X-LiveUX-FM-CSRF`-header bij elk `POST`-verzoek. |
| `security.sanitize_svg` | `true` | Schoont geüploade en bewerkte SVG's op aan de hand van een strikte allowlist van 55 tags (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Schakelt het tabblad **"Via URL"** (**Importeren via URL**) in het uploadvenster in (beveiligd tegen SSRF en DNS-rebinding via `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Overschrijft PSR-3-auditlogging voor dit profiel (`true` / `false` / `null`). |

#### 17 granulaire rechten (`permissions`)
> [!WARNING]
> **Belangrijk: wat betekent `true` onder `permissions`?**  
> Een recht op **`true`** zetten betekent **NIET** *"publieke / anonieme toegang"*! `FileManagerVoter` verleent `true` **alleen aan ingelogde gebruikers (`$token->getUser() !== null`)** en weigert anonieme verzoeken.  
> Daarnaast is **`permissions.view`** de hoofdtoegangspoort: als een gebruiker geen `view`-recht heeft, wordt elke andere actie (`upload`, `delete`, `download`, enz.) automatisch geweigerd.

Elk recht accepteert een van deze 4 vormen:
- `true` → Elke ingelogde gebruiker
- `false` → Niemand (actie volledig uitgeschakeld)
- `'ROLE_ADMIN'` → Alleen gebruikers met die rol
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Gebruikers met ten minste een van deze rollen

| Rechtsleutel | Standaard | Beheerde acties |
| :--- | :--- | :--- |
| `view` | `true` | Mapoverzicht, mappenstructuur, voorbeelden en **voorwaarde voor elk ander recht**. |
| `upload` | `true` | Standaard/chunked uploads, opslaan vanuit de afbeeldingseditor en importeren via URL. |
| `create_folder` | `true` | Nieuwe mappen aanmaken. |
| `rename` | `true` | Naam van bestanden en mappen wijzigen. |
| `clone` | `true` | Een bestand of map dupliceren. |
| `move` | `true` | Items verplaatsen via drag-and-drop of Knippen/Plakken. |
| `copy` | `true` | Items kopiëren via Kopiëren/Plakken of het dialoogvenster Verplaatsen/Kopiëren. |
| `delete` | `true` | Bestanden/mappen verwijderen (naar prullenbak verplaatsen of definitief verwijderen). |
| `trash_restore` | `true` | Items uit de prullenbak herstellen. |
| `trash_purge` | `true` | Items definitief verwijderen uit de prullenbak of de prullenbak legen. |
| `download` | `true` | Enkele bestanden downloaden, PDF's/video's streamen en bestandslinks kopiëren. |
| `download_zip` | `true` | Meerdere geselecteerde items downloaden als `.zip`-archief. |
| `extract_zip` | `null` *(auto)* | Geforceerd op `false` zolang `zip.allow_extract: false`; wordt automatisch `true` bij `zip.allow_extract: true` (desgewenst in te perken met `'ROLE_ADMIN'`). |
| `share` | `true` | Tijdelijke HMAC-ondertekende deellinks genereren. |
| `clear_cache` | `true` | Mapcache wissen vanuit Instellingen en `?refresh=1` / `?fallback=1` forceren. |
| `edit_metadata` | `true` | Bestandsmetadata (`alt`/`title`/`caption`/`tags`/focuspunt) en mapkleur/-pictogram/vastmaken bewerken. |
| `ai_enrich` | `true` | **✨ Voorstellen met AI** aanroepen (vereist `view` + `edit_metadata` + `ai_enrich`). |

#### AI-verrijking (`ai`) & Asset Usage Guard (`usage_guard`)
| Parameter | Standaard | Beschrijving |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Schakelt AI-metadatavoorstellen in; met `locale` bepaal je de uitvoertaal (bijv. `'nl'`, `'en'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Maximaal aantal voorgestelde tags (`1`–`20`) en maximale afmeting vóór verzending naar AI (`256`–`2048` px, bespaart vision-tokens). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Voorkomt dat bestanden op `private: true`-volumes naar externe AI-API's worden verstuurd; optionele automatische verrijking na uploaden (zie [Hoofdstuk 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Waarschuwt of blokkeert **`delete`, `rename`, `move` en `overwrite`** wanneer een bestand in je database wordt gebruikt (zie [Hoofdstuk 10](10-asset-usage-guard.md)). |

---

## 4. 5 kant-en-klare productierecepten (kopiëren & plakken)

Hieronder staan de 5 meest voorkomende praktijkconfiguraties die je direct in je project kunt overnemen:

### Recept 1: Webshop-productcatalogus (vierkante kaarten + WebP + duplicaat-hergebruik)
> **Doel:** Accepteer uitsluitend scherpe vierkante `1:1`-productfoto's; verklein smartphone-uploads automatisch en zet ze om naar WebP; hergebruik bestaande bestanden bij dubbele uploads; blokkeer per ongeluk verwijderen van afbeeldingen die aan actieve producten zijn gekoppeld.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'nl'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # 204px grote productkaarten
                card_aspect_ratio: '1/1' # Vierkante presentatieverhouding
                folder_style: 'card'     # Volwaardige categoriemapkaarten
                show_folder_item_count: true
```

### Recept 2: Blog- / nieuws-omslagafbeeldingen (`16:9`-banners + AI alt-tekst)
> **Doel:** Accepteer uitsluitend breedbeeld `16:9`-omslagafbeeldingen met hoge resolutie (minimaal `1200×630`px), sorteer uploads automatisch in `2026/11`-mappen en genereer SEO-alt-tekst en tags met AI.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Nieuwste omslagen eerst
            ai:
                enabled: true
                locale: 'nl'
                max_tags: 6
            ui:
                item_size: 'xl'           # 260px brede omslagvoorbeelden
                card_aspect_ratio: '16/9' # Breedbeeld bannerverhouding
```

### Recept 3: Klantdocument- & PDF-kluis (Privé Supabase/S3 + isolatie per gebruiker)
> **Doel:** Isoleer elke klant tot diens eigen PDF-facturen op een privé S3-compatibele bucket, blokkeer directe externe URL's, ververs cloudoverzichten elke 60 seconden en leg elke actie vast in de auditlog.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Klant PDF-kluis'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Geen ongeauthenticeerde directe URL-toegang
            cache_ttl: 60          # Externe cloudwijzigingen verschijnen binnen 60s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Elke gebruiker ziet alleen zijn eigen map
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Sorteerbare tabel, ideaal voor PDF's
                item_size: 'sm'          # Compacte rijen
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Recept 4: Snelle rich-texteditor-kiezer (TinyMCE / CKEditor / Tiptap)
> **Doel:** Een lichtgewicht, rustige modale kiezer waarbij dubbelklikken op een afbeelding deze direct in de editor invoegt.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Recept 5: Alleen-lezen partner- / perskitbibliotheek
> **Doel:** Laat partners door logo's en catalogi bladeren, losse bestanden of bulk-ZIP's downloaden en ondertekende deellinks aanmaken — terwijl uploaden, bewerken en verwijderen zijn geblokkeerd.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 dagen
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Dynamische pad-placeholders (`DynamicPathResolver`)

Je kunt dynamische placeholders gebruiken in lokale `path`-, `url_prefix`- en cloud-`driver_options.prefix`-instellingen én in profiel-`upload_subfolder`:

| Placeholder | Beschrijving | Voorbeelduitvoer |
| :--- | :--- | :--- |
| `{user_id}` | Botsingsvrije, bestandssysteemveilige identifier voor de ingelogde Symfony-gebruiker (`guest` indien niet ingelogd). | `42` of `alice_a--h-9f1c2e4b...` |
| `{Y}` | Jaartal in 4 cijfers | `2026` |
| `{m}` | Maand in 2 cijfers | `11` |
| `{d}` | Dag in 2 cijfers | `25` |

```yaml
volumes:
    user_space:
        label: 'Mijn persoonlijke ruimte'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Botsingsvrije multi-tenant `{user_id}`-isolatie:**  
> `DynamicPathResolver` genereert **disjuncte, van een SHA-256-suffix voorziene `<slug>--h-<16-hex>`**-mapsleutels voor e-mailadressen (`alice.a@example.com` vs `alice_a@example.com`), hoofdlettervarianten (`Alice` vs `alice`), Unicode-gebruikersnamen (Arabisch, Chinees, Turks), gereserveerde woorden (`guest`, `user`, identifiers eindigend op `--h-`) en Windows-apparaatnamen (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Twee verschillende gebruikers kunnen nooit op dezelfde map botsen.
>
> **CLI-gedrag:** Consolecommando's (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup`, enz.) slaan volumes met `{user_id}` automatisch over zodat ze nooit per ongeluk een losse `guest/`-map aanmaken.

---

## 6. Volledige YAML-schemareferentie (elke actieve optie)

Het onderstaande YAML-fragment documenteert **elke actieve configuratiesleutel** uit `Configuration.php` met de bijbehorende standaardwaarde en inline toelichting. In je eigen project hoef je alleen de sleutels op te nemen die je wilt aanpassen — al het andere erft automatisch de standaardwaarde.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) GLOBALE STANDAARDEN — Overgeërfd door alle profielen tenzij overschreven
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Standaard hoofdmap voor verkorte single-disk profielen
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = Symfony request-/translator-locale (12 ingebouwde talen, fallback 'en') | 'nl' | 'en' | 'tr' | ...
        z_index: 1050                                      # Basis z-index voor overlays, popovers en modals (>= 1)
        auto_inject_dialog_host: true                      # Modal/OffCanvas DialogHost automatisch injecteren in volledige HTML-responses
        dialog_host_profile: 'default'                     # Standaardprofiel voor de automatisch geïnjecteerde DialogHost

        audit_log:
            enabled: false                                 # Alle 9 bestands-/mapmutaties loggen naar een PSR-3-kanaal
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Standaard AI-model wanneer niet overschreven op een profiel
            platform_service: null                         # Eigen Symfony AI PlatformInterface service-ID (null = auto)

        cache:
            enabled: true                                  # Globale schakelaar voor map-/boom-PSR-6-cache
            pool: 'cache.app'                              # Symfony PSR-6 cachepool service-ID
            ttl: 3600                                      # Globale cache-TTL in seconden (0 = uitgeschakeld)

        metadata:
            enabled: true                                  # Mapkleuren/-pictogrammen/vastmaken & bestands-alt/title/caption/tags/focalPoint
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Opslagbestand voor json_file-driver
            connection: null                               # Optionele DBAL-verbindingsnaam voor doctrine-driver (null = verbinding van entity_manager)
            entity_manager: 'default'                      # EntityManager / verbindingsnaam voor doctrine-driver
            table: 'liveux_fm_metadata'                    # DBAL-tabelnaam voor doctrine-driver (zie Hoofdstuk 13)
            auto_create_table: true                        # Metadatatabel automatisch aanmaken indien afwezig (zet false bij gebruik van migraties)

    # =========================================================================
    # 2) OPSLAGVOLUMES — Lokale & cloud-bestandssysteemdefinities
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Lokale opslag'                         # Weergavenaam in zijbalk & kruimelpad
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Fysiek pad (ondersteunt {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Openbaar browser-URL-voorvoegsel (laat null bij private: true)
            private: false                                 # true = stream alle bestanden via RBAC-beveiligde DownloadController
            cache_ttl: null                                # Cache-TTL per volume in seconden (null = overerven, 0 = uitgeschakeld)
            driver_options: []                             # Leeg voor LocalFileSystem

        cloud_s3_example:
            label: 'Cloud S3 / R2 / Supabase'
            icon: 'supabase'                               # Komt overeen met resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # Openbare bucket-/CDN-URL (null bij private: true)
            private: false
            cache_ttl: 60                                  # Ververs externe cloudwijzigingen elke 60 seconden
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Bucketnaam
                region: '%env(AWS_S3_REGION)%'             # Regio ('auto' voor Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # Aangepaste S3-endpoint-URL voor niet-AWS-providers
                prefix: 'media/{Y}'                        # Optioneel root-voorvoegsel in de bucket (ondersteunt dynamische placeholders)
                use_path_style_endpoint: true              # true voor Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Optioneel tijdelijk AWS STS-sessietoken
                version: 'latest'                          # AWS S3 API-versie (standaard: 'latest')
                ca_bundle: null                            # Optioneel aangepast CA-bundle .pem-pad (verhelpt Windows cURL error 60)
                disable_acl: true                          # Automatisch true voor custom endpoints; zet true voor AWS S3 Bucket owner enforced
                retain_visibility: false                   # Automatisch false voor custom endpoints (voorkomt x-amz-acl)
                request_checksum_calculation: 'when_required'  # AWS SDK >= 3.337 compatibiliteit (auto voor custom endpoints)
                response_checksum_validation: 'when_required'  # AWS SDK >= 3.337 compatibiliteit (auto voor custom endpoints)

        custom_flysystem_example:
            label: 'SFTP-archief'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # League\Flysystem\FilesystemOperator service-ID

    # =========================================================================
    # 3) PROFIELEN — Onafhankelijke regelsets per toepassing
    # =========================================================================
    profiles:
        full_reference:
            # --- Gekoppelde volumes, locale & verkorte single-disk opties ---
            locale: null                                   # null = erft defaults.locale | 'nl' | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Indien leeg wordt een impliciet volume gemaakt uit driver/path hieronder
            driver: 'LocalFileSystem'                      # Verkorte driver, alleen gebruikt bij volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Verkort pad, alleen gebruikt bij volumes: []
            url_prefix: '/uploads'                         # Verkort URL-voorvoegsel, alleen gebruikt bij volumes: []
            private: false                                 # Verkorte privacyvlag, alleen gebruikt bij volumes: []
            driver_options: []                             # Verkorte driveropties, alleen gebruikt bij volumes: []

            # --- Overzicht, verbergen & standaardsortering ---
            ignore_dot_files: true                         # Verberg en blokkeer puntbestanden (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Regex-/wildcardpatronen verborgen uit overzichten & directe toegang
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Toon mappen altijd vóór bestanden

            # --- Uploads, quotum & naambotsingen ---
            upload_subfolder: null                         # bijv. '{Y}/{m}' — plaats uploads in dynamische submappen
            upload_allow: []                               # Leeg = alle veilige typen; niet-leeg = strikte MIME-allowlist
            upload_deny:                                   # Altijd geblokkeerde gevaarlijke MIME-typen (standaard 47 indien weggelaten)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Maximale samengevoegde grootte voor één bestand (bijv. '50M', '2G')
            storage_quota: null                            # Totaal schijfquotum voor het profiel (bijv. '500M', '5G'; null = onbeperkt)
            overwrite_policy: 'rename'                     # rename (voegt -1 toe) | overwrite | reject

            # --- Uploaden in delen (omzeil PHP upload_max_filesize) ---
            chunk_upload:
                enabled: true                              # Splits grote bestanden in hervatbare delen
                chunk_size: '2M'                           # Gewenste deelgrootte (automatisch begrensd op 80% van PHP-limieten)
                parallel_uploads: 3                        # Max. gelijktijdige uploads (1..10)
                max_retries: 3                             # Automatische herpogingen per deel bij netwerkfouten (0..10)

            # --- Duplicaatdetectie (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Detecteer dubbele inhoud bij uploaden
                policy: 'warn'                             # warn | reject | reuse (bestaand bestand hergebruiken)
                max_scan_files: 5000                       # Max. gehashte bestanden voor duplicaatindexering (100..50000)

            # --- Automatische bestandsnaamnormalisatie ---
            naming:
                slugger: true                              # Translitereer Unicode-bestandsnamen naar schone ASCII-slugs
                slugger_locale: null                       # null = erft profiellocale (bijv. 'nl', 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Statisch/dynamisch voorvoegsel (bijv. 'product-')
                append: ''                                 # Statisch/dynamisch achtervoegsel vóór extensie (bijv. '-{Y}{m}{d}')
                apply_on_rename: true                      # Pas slugger-/transformerregels toe bij naam wijzigen
                apply_affixes_on_rename: false             # Pas prepend-/append-affixen opnieuw toe bij naam wijzigen
                allow_extension_change: false              # Sta gebruikers toe extensies te wijzigen (.jpg -> .php) bij naam wijzigen

            # --- Afbeeldingsoptimalisatie tijdens uploaden ---
            image_optimization:
                enabled: false                             # Auto-oriëntatie, EXIF verwijderen, verkleinen en comprimeren bij upload
                auto_orient: true                          # Corrigeer EXIF-oriëntatie van smartphones
                strip_exif: true                           # Verwijder GPS- & camera-EXIF-gegevens voor privacy en bestandsbesparing
                max_width: 2400                            # Proportionele max. breedte in px (>= 100)
                max_height: 2400                           # Proportionele max. hoogte in px (>= 100)
                convert_to_webp: false                     # Converteer JPEG/PNG-uploads automatisch naar WebP
                quality: 85                                # Uitvoerkwaliteit (10..100)

            # --- Afbeeldingsregels voor resolutie & beeldverhouding ---
            image_constraints:
                min_width: null                            # Minimale breedte in px (bijv. 800)
                min_height: null                           # Minimale hoogte in px (bijv. 600)
                max_width: null                            # Maximale breedte in px (gecontroleerd ná image_optimization)
                max_height: null                           # Maximale hoogte in px
                aspect_ratio: null                         # Vereiste verhouding (bijv. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Toegestane afwijking van verhouding (0.0..0.5; 0.05 = 5%)

            # --- Miniatuur-engine ---
            thumbnail:
                enabled: true                              # Genereer gecachete kaartvoorbeelden voor afbeeldingen
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Miniatuurbreedte in px (>= 32)
                height: 240                                # Miniatuurhoogte in px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Compressiekwaliteit (10..100)
                fit: 'contain'                             # contain (passend binnen kader) | cover (bijsnijden tot vullend)
                lazy_load: true                            # Voeg loading="lazy" toe aan kaartminiaturen
                cache_dir: '%kernel.project_dir%/public/media-cache' # Lokale cachemap voor gegenereerde miniaturen

            # --- Prullenbak (Soft Delete) ---
            trash:
                enabled: true                              # Verplaats verwijderde items naar prullenbak i.p.v. definitief verwijderen
                directory: '.trash'                        # Naam van de verborgen prullenbakmap
                retention_days: 30                         # Bewaartermijn in dagen voor file-manager:trash:purge
                max_size: '500M'                           # Maximale totale grootte van de prullenbak

            # --- ZIP downloaden & veilig archief uitpakken ---
            zip:
                enabled: true                              # Schakel ZIP-downloads van meerdere bestanden/mappen in
                max_files: 100                             # Max. bestanden in één ZIP-download
                max_total_size: '250M'                     # Max. totale grootte voor één ZIP-download
                allow_extract: false                       # Schakel uitpakken in (standaard uit; 7-laags beveiligd)
                max_extract_files: 200                     # Max. uit te pakken bestanden per archief (1..2000)
                max_extract_size: '100M'                   # Max. totale uitgepakte grootte (ZIP-bombeveiliging)
                max_compression_ratio: 100                 # Max. toegestane compressieverhouding (1..1000 — ZIP-bombeveiliging)

            # --- Tijdelijke ondertekende deellinks (HMAC) ---
            share:
                enabled: true                              # Sta het genereren van ondertekende deel-URL's toe
                default_ttl: 86400                         # Standaard geldigheidsduur in seconden (86400 = 24 uur)
                max_ttl: 2592000                           # Maximaal kiesbare geldigheidsduur in seconden (2592000 = 30 dagen)
                allow_inline_preview: true                 # Sta directe browservoorbeeldweergave toe voor veilige MIME-typen

            # --- Optionele Symfony AI-verrijking ---
            ai:
                enabled: false                             # Toon "✨ Voorstellen met AI" in het metadatavenster
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = profiel-/request-locale (bijv. 'nl' => Nederlandse alt-tekst/tags)
                max_tags: 6                                # Maximaal aantal voorgestelde tags (1..20)
                max_image_dimension: 1024                  # Verkleiningslimiet vóór verzending naar AI (256..2048 px)
                block_private_volumes: true                # true = blokkeer AI-verzoeken voor private: true-volumes (standaard: true)
                auto_enrich_on_upload: false               # Voer AI-verrijking automatisch uit na uploaden

            # --- Asset Usage Guard (Verwijderen / Naam wijzigen / Verplaatsen / Overschrijven) ---
            usage_guard:
                enabled: false                             # Controleer databaseverwijzingen vóór het muteren van assets
                policy: 'warn'                             # warn (toon verwijzingen + overschrijfoptie) | block (verbied actie)
                enforce_server_side: true                  # Ook afdwingen bij directe API-aanroepen
                max_references: 20                         # Max. aantal getoonde verwijzingen in het venster (1..200)

            # --- Caching per profiel ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | seconden (0 = uitgeschakeld)

            # --- Beveiligingsopties ---
            security:
                voter_enabled: true                        # Schakel FileManagerVoter RBAC-controles in
                csrf_protection: true                      # Vereis X-LiveUX-FM-CSRF op POST-verzoeken
                sanitize_svg: true                         # Schoon geüploade/bewerkte SVG's op
                allow_remote_url_import: false             # Schakel tabblad "Via URL" in (SSRF-beveiligd)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 granulaire RBAC-rechten (true = ingelogde gebruiker | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Hoofdrechtdrempel vereist voor overzichten en alle andere acties
                upload: true                               # Bestandsuploads, opslaan uit afbeeldingseditor en URL-imports
                create_folder: true                        # Nieuwe mappen aanmaken
                rename: true                               # Naam van bestanden en mappen wijzigen
                clone: true                                # Bestanden/mappen dupliceren
                move: true                                 # Items verplaatsen (incl. drag-and-drop & knippen/plakken)
                copy: true                                 # Items kopiëren (incl. kopiëren/plakken)
                delete: true                               # Items verwijderen (prullenbak of definitief verwijderen)
                trash_restore: true                        # Items uit prullenbak herstellen
                trash_purge: true                          # Items definitief verwijderen / prullenbak legen
                download: true                             # Enkel bestand downloaden & link kopiëren
                download_zip: true                         # Meerdere bestanden downloaden als ZIP
                extract_zip: false                         # ZIP-archieven uitpakken (vereist ook zip.allow_extract: true)
                share: true                                # Ondertekende deellinks aanmaken
                clear_cache: true                          # Cache wissen vanuit Instellingen & ?refresh=1 forceren
                edit_metadata: true                        # Bestands-alt/tags/focuspunt & mapkleur/-pictogram/vastmaken bewerken
                ai_enrich: true                            # AI-metadataverrijking uitvoeren

            # --- Gebruikersinterface (UI) vlaggen & formaten ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <custom dir>
                z_index: null                              # null = defaults.z_index | geheel getal (>= 1)
                mobile_dialog_mode: 'auto'                 # Dialooggedrag op mobiel (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px compact)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (compacte tegel) | card (volwaardige mapkaart)
                show_size_slider: true                     # Toon live schuifregelaar voor Itemgrootte & Mapstijl in Instellingen
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Toon linker zijbalk met mappenstructuur
                tree_lines: true                           # Toon verbindingslijnen in mappenboom
                show_folder_item_count: true               # Toon telbadges op mappen
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Toon quotummeter in zijbalk & quotumpil in kopbalk
                show_quota_details: true                   # Schakel klikbaar venster Opslaggebruik & quotumdetails in
                per_page: 24                               # Standaard aantal items per pagina (6..200)
                per_page_options: [24, 48, 96]             # Opties in dropdown Items per pagina
                show_perf_badge: false                     # Toon CACHE HIT / SCHIJF timingbadge in werkbalk
                show_nav_buttons: true                     # Hoofdschakelaar voor groep Terug / Vooruit / Bovenliggende map / Vernieuwen
                show_history_buttons: true                 # Toon geschiedenis-knoppen Terug / Vooruit
                show_up_button: true                       # Toon knop Bovenliggende map
                show_refresh_button: true                  # Toon knop Vernieuwen (?refresh=1)
                show_breadcrumbs: true                     # Toon klikbare/sleepbare kruimelpadbalk
                show_search: true                          # Toon zoekveld & knop voor diep zoeken
                show_type_filter: true                     # Toon typefilter (Afbeeldingen / Video's / Bestanden / Mappen)
                show_view_toggle: true                     # Toon Raster-/Lijstschakelaar
                show_pagination: true                      # Toon paginering onderaan
                show_per_page_selector: true               # Toon dropdown Items per pagina
                show_sort: true                            # Toon sorteermenu & richtingsschakelaar
                show_fullscreen_button: true               # Toon knop Volledig scherm
                show_settings_button: true                 # Toon menu Instellingen (⚙)
                show_footer: true                          # Toon statusbalk onderaan
                show_context_nav: true                     # Toon snelle navigatiebalk in contextmenu van lege ruimte
                allow_dropzone_upload: true                # Sta slepen van bestanden/mappen vanuit het OS op het overzicht toe
                remember_last_dir: true                    # Onthoud laatste volume & map (herstelt automatisch als map verwijderd is)
                confirm_delete: true                       # Toon bevestigingsvenster vóór verwijderen
                confirm_move: true                         # Toon bevestigingsvenster vóór verplaatsen/kopiëren
                double_click_to_select: true               # Dubbelklik selecteert bestand & sluit Modal-/OffCanvas-kiezer
                allow_context_menu: true                   # Schakel rechtermuisknop-contextmenu in
                allow_pdf_preview: true                    # Inline PDF Lightbox (false forceert download)
                allow_video_preview: true                  # Inline videospeler (false forceert download)
                keyboard_shortcuts: true                   # Schakel gescopede sneltoetsen in
                allow_drag_move: true                      # Sta slepen van items naar mappen om ze te verplaatsen toe
```

---

[← Vorige: 01 — Vereisten & installatie](01-requirements-and-installation.md) | [Documentatie-overzicht](README.md) | [Volgende: 03 — Gebruiksmodi & JS API →](03-usage-modes-and-js-api.md)
