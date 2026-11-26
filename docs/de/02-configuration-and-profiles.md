# 02 — Konfiguration & Profile (Vollständige Parameterreferenz & Rezepte)

[← Zurück: 01 — Voraussetzungen & Installation](01-requirements-and-installation.md) | [Dokumentationsübersicht](README.md) | [Weiter: 03 — Verwendungsmodi & JS-API →](03-usage-modes-and-js-api.md)

Dieser Leitfaden erklärt **jede Option** in `config/packages/liveux_file_manager.yaml`, ihren Standardwert, wann sich eine Anpassung empfiehlt, sowie **5 sofort einsetzbare Produktionsrezepte** zum direkten Übernehmen in Ihr Projekt.

---

## Das Grundprinzip in 30 Sekunden: `defaults`, `volumes` und `profiles`

Die Konfigurationsdatei folgt einer klaren **3-Schichten-Struktur**:

1. **`defaults` (Globale Standardwerte):** Gemeinsame Basiswerte, die von jedem Profil geerbt werden (Theme, Locale, Farbschema, Cache, Metadaten-Treiber, Audit-Logging, KI-Standardwerte). Sofern sie nicht in einem Profil überschrieben werden, gelten sie überall.
2. **`volumes` (Speicherlaufwerke / Buckets):** Legen fest, **wo Dateien physisch gespeichert werden** (lokales Verzeichnis, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage oder ein benutzerdefinierter Flysystem-Service).
3. **`profiles` (Verwendungsprofile):** Definieren **Regeln, Oberflächenlayout und Zugriffsrechte** für eine bestimmte Ansicht oder einen Anwendungsfall. Beispielsweise:
   - `product_catalog` erlaubt ausschließlich quadratische `1:1`-Produktbilder und konvertiert sie automatisch in WebP;
   - `customer_docs` verbindet sich mit einem privaten Supabase-S3-Bucket, akzeptiert nur PDFs und isoliert jeden Benutzer in seinem eigenen Ordner;
   - `editor_picker` öffnet eine schlanke Modal-Auswahl für TinyMCE mit Doppelklick-Übernahme.

---

## 1. `defaults` — Globale Standardwerte

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = übernimmt die aktive Symfony-Locale (Fallback auf 'en')
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

| Parameter | Standardwert | Aufgabe | Wann anpassen |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Standard-Stammverzeichnis für Kurzschreibweise-Profile, die `path` direkt ohne `volumes` definieren. | Wenn lokale Dateien in einem anderen Ordner wie `public/media` liegen sollen. |
| `theme` | `'bootstrap5'` | Standard-UI-Theme für alle Profile (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` oder ein eigener Theme-Name). | Zur Anpassung an das Designsystem Ihrer Anwendung (z. B. `'shadcn'` oder `'tailwind'` in Tailwind-Projekten). |
| `locale` | `null` | Bei `null` wird automatisch die aktive Symfony-Request-/Translator-Locale für alle **12 integrierten Oberflächensprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) übernommen, BCP-47- & POSIX-Formate (`de_DE.UTF-8`, `zh-Hans-CN`) werden normalisiert, RTL (`dir="rtl"`) wird für Arabisch und weitere RTL-Schriften automatisch aktiviert und unbekannte Locales fallen auf `'en'` zurück. | Explizit setzen (z. B. `'de'`, `'en'`, `'fr'`), um alle Benutzer auf eine feste Oberflächensprache festzulegen. |
| `color_scheme` | `'auto'` | Farbmodus: `'auto'` (erkennt `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (immer hell) oder `'dark'` (immer dunkel). | Wenn ein rein helles oder rein dunkles Admin-Theme erzwungen werden soll. |
| `z_index` | `1050` | Basis-Stapelkontext (`--liveux-fm-z-base`) für Modals, OffCanvas-Panels, Lightbox und Kontextmenüs (`>= 1`). | Z. B. auf `10050` erhöhen, wenn Ihre Admin-Navigationsleiste `z-index: 9999` verwendet. |
| `auto_inject_dialog_host` | `true` | Fügt den für `window.LiveUXFileManager.open()` erforderlichen `<dialog>`-Host auf vollständigen HTML-Seiten automatisch vor `</body>` ein (keine Änderungen an `base.html.twig` nötig). | Auf `false` setzen, wenn Sie `@LiveUXFileManager/components/DialogHost.html.twig` gezielt nur in bestimmten Layouts manuell einbinden möchten. |
| `dialog_host_profile` | `'default'` | Standardprofil des automatisch eingefügten `DialogHost`, falls kein Profil übergeben wird. | Wenn Ihr Hauptprofil nicht `default` heißt (z. B. `admin_media`). |
| `cache.enabled` | `true` | Serverseitiger PSR-6-Zwischenspeicher (Cache) für Verzeichnislisten und Ordnerbäume. | Während der lokalen Fehlersuche auf `false` setzen (oder pro Profil/Volume deaktivieren). |
| `cache.pool` | `'cache.app'` | Symfony-PSR-6-Cache-Pool (Redis, Filesystem, APCu usw.). Nutzt automatisch `<pool>.taggable`, sofern verfügbar, für gezielte Tag-Invalidierung. | Um Dateimanager-Cache-Einträge in einem dedizierten Redis-Pool zu isolieren. |
| `cache.ttl` | `3600` | Cache-Lebensdauer in Sekunden (Standard: 1 Stunde). Änderungen (Hochladen, Löschen, Verschieben, Umbenennen) invalidieren betroffene Verzeichnisse automatisch. | Auf `60`–`300` senken, wenn Dateien häufig extern über FTP oder Cloud-Dashboards geändert werden. |
| `metadata.enabled` | `true` | Speichert Ordnerfarben/-symbole/-Pins sowie Datei-SEO-Metadaten (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Auf `false` setzen für minimalistische Dateiauswahlen ohne redaktionelle Metadaten. |
| `metadata.driver` | `'json_file'` | Metadaten-Backend: `'json_file'` (lokale Datei für Einzelserver-Apps), `'doctrine'` (integrierter DBAL-Speicher für Multi-Server-/Load-Balanced-Apps unter MySQL, MariaDB, PostgreSQL oder SQLite) oder `'none'`. | Auf `'doctrine'` umstellen, sobald mehrere App-Instanzen parallel laufen (Kubernetes, Load Balancer, Docker Swarm) — siehe [Kapitel 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Speicherpfad für den Treiber `json_file`. Im selben Verzeichnis wird für MIME-eingeschränkte Cloud-Buckets eine begleitende Papierkorb-Sidecar-Datei (`trash-meta.json` bzw. `<name>.trash-meta.json`) abgelegt. | Bei Bedarf auf ein gemeinsames NFS-/Volume-Verzeichnis legen. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | DBAL-Verbindungsname, EntityManager, Tabellenname (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) und automatische Tabellenerstellung bei `driver: 'doctrine'`. | In der Produktion `auto_create_table: false` setzen, wenn das Schema über Doctrine Migrations verwaltet wird (siehe [Kapitel 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Schreibt strukturierte PSR-3-Audit-Einträge (`user`, `ip`, `path`, `volume`) für alle 9 Datei-/Ordner-Änderungen (Hochladen, Löschen, Verschieben, Kopieren, Umbenennen, Klonen, Ordner erstellen, Papierkorb wiederherstellen, Papierkorb endgültig leeren). | In Compliance-relevanten Umgebungen aktivieren (`true`), um nachvollziehen zu können: *„Wer hat wann was gelöscht?“*. |
| `audit_log.level` | `'info'` | PSR-3-Loglevel für Audit-Einträge (`debug`, `info`, `notice`, `warning`). | An Ihre Monolog-Routing- und Filterregeln anpassen. |
| `ai.default_model` | `'gpt-4o-mini'` | Standard-Vision-/Textmodell, wenn das optionale `symfony/ai-bundle` installiert ist (siehe [Kapitel 09](09-symfony-ai-integration.md)). | Auf Gemini, Claude oder ein lokales Ollama-Modell umstellen. |
| `ai.platform_service` | `null` | Benutzerdefinierte Symfony-AI-`PlatformInterface`-Service-ID (`null` nutzt die automatisch registrierte Standardplattform). | Wenn Ihre Anwendung mehrere Symfony-AI-Plattformen definiert. |

---

## 2. `volumes` — Speicher-Volumes (Lokal & Cloud)

Ein einzelnes Profil kann mehrere Volumes gleichzeitig einbinden (z. B. lokale Festplatte und Supabase/R2/S3). Benutzer können Dateien per Drag-and-Drop direkt aus einem lokalen Ordner in einen Cloud-Bucket verschieben.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Lokaler Speicher'
            icon: 'disk'              # Optional (wird sonst automatisch aus Treiber/Endpunkt erkannt)
            group: 'local'            # Optional (null -> 'local' bei LocalFileSystem, 'network' bei S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # Optionale Volume-spezifische TTL (0 = deaktiviert)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Wird ebenfalls automatisch anhand des Endpunkts erkannt
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Externe Änderungen im Cloud-Dashboard erscheinen innerhalb von 60 Sekunden
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parameter | Standardwert | Aufgabe | Wann anpassen |
| :--- | :--- | :--- | :--- |
| `label` | Volume-Schlüssel | Anzeigename in der Seitenleiste, in den Breadcrumbs und im Volume-Umschalter. | Für eine verständliche Bezeichnung (z. B. `'Produktmedien CDN'`). |
| `icon` | `null` *(auto)* | SVG-Symbol in der Seitenleiste. Bei `null` automatisch aus `driver` und `endpoint` ermittelt (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Für ein eigenes `<dir>/ui/my_icon.svg` aus Ihrem Icon-Set. |
| `group` | `null` *(auto)* | Abschnittsgruppe in der Seitenleiste. Bei `null` wird `LocalFileSystem` als `'local'` und `AwsS3` / `FlysystemService` als `'network'` gruppiert. | Überschreiben, wenn z. B. ein NFS-Mount unter `'network'` oder ein Cloud-Laufwerk unter `'local'` erscheinen soll. |
| `driver` | `'LocalFileSystem'` | Speicher-Backend: lokales Dateisystem (`LocalFileSystem`), S3-kompatibler Objektspeicher (`AwsS3`) oder ein bestehender Flysystem-Service (`FlysystemService`). | `'AwsS3'` für S3/R2/Supabase/B2/MinIO/GCS oder `'FlysystemService'` für SFTP/Azure/Dropbox verwenden. |
| `path` | `null` | Physischer Verzeichnispfad für `LocalFileSystem` (unterstützt `{user_id}`, `{Y}`, `{m}`, `{d}`). | Für ein eigenes Stammverzeichnis oder benutzerspezifische Isolierung (`.../users/{user_id}`). |
| `url_prefix` | `null` | Öffentliches URL-Präfix für den Browserzugriff auf öffentliche Dateien (`/uploads` oder `https://cdn.example.com`). Wird bei `private: true` ignoriert. | Konfigurieren, wenn Assets über ein CDN oder einen öffentlichen S3-/R2-/Supabase-Bucket ausgeliefert werden. |
| `private` | `false` | Bei `true` werden Dateien niemals über direkte öffentliche URLs bereitgestellt — alle Downloads, Vorschauen und Vorschaubilder laufen über Symfony-Voter-Prüfungen (`DownloadController`). | **Immer `true` setzen** für Rechnungen, Ausweise, Verträge oder private Kundendokumente. |
| `cache_ttl` | `null` *(geerbt)* | Cache-TTL (in Sekunden) speziell für dieses Volume. `0` deaktiviert den Cache für dieses Volume; `null` erbt von Profil/Defaults. | Lokalen Speicher z. B. `3600` s cachen, während ein Cloud-Bucket auf `60` s oder `0` begrenzt bleibt. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(temporäres STS-Session-Token)*, `version` *(Standard `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(automatisch `true` bei benutzerdefinierten Endpunkten; Standard `false` auf regulärem AWS S3 — bei Bucket owner enforced explizit auf `true` setzen)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Siehe [Kapitel 07](07-cloud-storage-and-dynamic-config.md) für anbieterspezifische Anleitungen. |

---

## 3. `profiles` — Parameterreferenz für Profile

### 3.1. Basisspeicher, Filterung & Kontingent (Quota)
| Parameter | Standardwert | Aufgabe | Wann anpassen |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Überschreibt die Sprache für Oberfläche und Fehlermeldungen in diesem Profil (`'de'`, `'en'`, `'tr'`, `'fr'`, `'ar'` usw.; `null` erbt `defaults.locale` → Symfony-Request-Locale → `'en'`). | Wenn verschiedene Bereiche derselben Anwendung unterschiedliche Sprachen nutzen. |
| `volumes` | `[]` | Geordnete Liste der in diesem Profil zugänglichen Volume-Schlüssel (z. B. `['local_uploads', 'supabase_s3']`). Das erste Volume wird standardmäßig geöffnet. | Um ein oder mehrere lokale/Cloud-Volumes einzubinden. |
| `ignore_dot_files` | `true` | Blendet Dotfiles (`.env`, `.htaccess`, `.DS_Store`, `.trash`) in Auflistungen aus und blockiert direkten Zugriff darauf. | Aus Sicherheitsgründen auf `true` belassen. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Blendet Dateien oder Ordner, die auf Regex- oder Wildcard-Muster passen, aus und blockiert den Direktzugriff. | Um interne Ordner wie `/^cache$/i` oder `/^backups$/i` zu verbergen. |
| `sort_by` | `'name'` | Standardsortierspalte: `'name'`, `'date'`, `'size'` oder `'type'`. | `'date'` + `sort_direction: 'desc'` nutzen, damit neueste Uploads ganz oben stehen. |
| `sort_direction` | `'asc'` | Sortierreihenfolge: `'asc'` (A→Z / kleinste zuerst) oder `'desc'` (Z→A / neueste zuerst). | `'desc'` eignet sich ideal für Mediengalerien und Redaktionsbibliotheken. |
| `folders_first` | `true` | Listet Ordner immer oberhalb von Dateien auf. | Auf `false` setzen für eine flache alphabetische oder chronologische Sortierung. |
| `storage_quota` | `null` | Maximal zulässige Gesamtspeichernutzung für das Profil (z. B. `'500M'`, `'2G'`). Bei Erreichen werden Uploads serverseitig abgelehnt und in der Seitenleiste erscheint ein Auslastungsbalken. | Unverzichtbar für Multi-Tenant-SaaS oder Kontingente pro Benutzer (`{user_id}`). |
| `upload_subfolder` | `null` | Dynamische Unterordner-Vorlage, in der hochgeladene Dateien automatisch unterhalb des aktuellen Verzeichnisses abgelegt werden (z. B. `'{Y}/{m}'`). | Um Uploads automatisch in Datumsordnern wie `2026/11` zu organisieren. |

### 3.2. Hochladen, Chunked Uploads (`chunk_upload`) & Duplikaterkennung (`duplicate_detection`)
| Parameter | Standardwert | Aufgabe | Wann anpassen |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(alle sicheren)* | Positivliste erlaubter MIME-Typen (z. B. `['image/jpeg', 'image/png', 'image/webp']`). Wenn leer, sind alle sicheren Typen außerhalb von `upload_deny` zulässig. | Bei reinen Bild- oder PDF-Formularen stets auf konkrete MIME-Typen einschränken. |
| `upload_deny` | **47 gefährliche MIME-Typen** | Strikt blockierte MIME-Typen (PHP, HTML, Nicht-SVG-XML, JS, Shell, EXE, Java, CGI usw. — serverseitig per Magic Bytes geprüft). | Bei strengeren Unternehmensrichtlinien um weitere Typen ergänzen. |
| `upload_max_size` | `'10M'` | Maximale **Gesamtgröße** einer einzelnen hochgeladenen Datei (z. B. `'200M'`, `'1G'`). Mit aktiviertem Chunked Upload werden auch Dateien weit oberhalb des PHP-Limits von `2M` zuverlässig bis zu dieser Grenze hochgeladen. | Auf `'200M'`–`'500M'` für Video-/Katalog-PDF-Profile erhöhen oder für Avatare auf `'2M'` begrenzen. |
| `overwrite_policy` | `'rename'` | Verhalten bei Namenskonflikten mit bereits vorhandenen Dateien: `'rename'` (`photo-1.jpg`), `'overwrite'` oder `'reject'`. | Auf `'rename'` belassen, um versehentlichen Datenverlust zu vermeiden. |
| `chunk_upload.enabled` | `true` | Teilt große Uploads in kleine Blöcke (Chunks) mit Pausieren / Fortsetzen / Bestätigtem Abbruch und automatischer Wiederholung bei kurzzeitigen Netzwerkfehlern. | Nur selten deaktivieren — höchstens wenn ausschließlich winzige Dateien (< 1 MB) hochgeladen werden. |
| `chunk_upload.chunk_size` | `'2M'` | Ziel-Chunk-Größe. Wird automatisch auf **80 %** der PHP-Werte `upload_max_filesize` und `post_max_size` begrenzt (z. B. 1,60 MB bei einem 2M-PHP-Limit). | Auf Servern mit hohen PHP-Limits (z. B. 32M+) auf `'5M'` erhöhen für schnellere Groß-Uploads. |
| `chunk_upload.parallel_uploads` | `3` | Maximale Anzahl gleichzeitiger Datei-Uploads (`1`–`10`). | `2` für ressourcenschwache Server oder `4`–`5` bei schnellen Cloud-/CDN-Anbindungen. |
| `chunk_upload.max_retries` | `3` | Wiederholungsversuche mit exponentiellem Backoff pro Chunk bei Netzwerkabbrüchen (`0`–`10`). | `3`–`5` bewähren sich bei mobilen oder instabilen Verbindungen. |
| `duplicate_detection.enabled` | `false` | Erkennt doppelte Datei-Uploads anhand von SHA-256-Inhalts-Hashes. | Aktivieren (`true`), um zu verhindern, dass Redakteure dasselbe Produktfoto mehrfach hochladen. |
| `duplicate_detection.policy` | `'warn'` | Reaktion bei erkanntem Duplikat: `'warn'` (hochladen & warnen), `'reject'` (Upload blockieren) oder `'reuse'` (vorhandene Datei ohne doppelte Speicherung zurückgeben). | `'reuse'` spart im E-Commerce und CMS erheblich Speicherplatz. |
| `duplicate_detection.max_scan_files` | `5000` | Obergrenze der für die Duplikaterkennung indizierten Dateien (`100`–`50000`), um CPU-/I/O-Lastspitzen bei riesigen Archiven zu vermeiden. | Für große Medienbibliotheken auf `10000`–`25000` erhöhen. |

### 3.3. Automatische Dateinamen-Normalisierung (`naming`)
| Parameter | Standardwert | Aufgabe | Beispiel (`"Produkt Foto 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Transliteriert Umlaute/Unicode-Zeichen und Leerzeichen in saubere, URL-freundliche ASCII-Slugs. | `produkt-foto-2026.jpg` |
| `naming.slugger_locale` | `null` | Transliterierungs-Locale (`null` erbt die Profil-Locale; z. B. wandelt `'de'` `ä/ö/ü/ß` korrekt um, `'tr'` wandelt `İ/ı/ş/ğ/ç/ö/ü` um). | Auf Ihre Inhaltssprache setzen (z. B. `'de'`, `'tr'`, `'fr'`). |
| `naming.transformer` | `'none'` | Schreibweisen-/Benennungs-Transformation: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` oder `'folder'` (benennt die Datei nach ihrem übergeordneten Ordner!). | `'snake'` → `produkt_foto_2026.jpg`, `'folder'` → `thermobecher-1.jpg` |
| `naming.prepend` / `append` | `''` | Stellt statischen Text oder dynamische Platzhalter (`{Y}`, `{m}`, `{d}`, `{uniqid}`) voran oder hängt sie an. | `prepend: '{Y}{m}_'` → `202611_produkt.jpg` |
| `naming.apply_on_rename` | `true` | Wendet `slugger`- und `transformer`-Regeln auch beim Umbenennen in der Oberfläche an. | Auf `true` belassen, um dauerhaft saubere Dateinamen sicherzustellen. |
| `naming.apply_affixes_on_rename` | `false` | Verhindert, dass `prepend`-/`append`-Präfixe bei jedem Umbenennen doppelt angefügt werden. | Auf `false` belassen. |
| `naming.allow_extension_change` | `false` | Verhindert, dass Benutzer beim Umbenennen die Dateiendung ändern (z. B. `.jpg` → `.php` oder `.png`). | Aus Sicherheitsgründen auf `false` belassen. |

### 3.4. Bildoptimierung (`image_optimization`) & Bild-Constraints (`image_constraints`)
- **`image_optimization`:** Korrigiert beim Hochladen serverseitig automatisch die Ausrichtung, entfernt EXIF-Metadaten, skaliert große Fotos herunter und komprimiert sie.
- **`image_constraints`:** Lehnt nicht konforme Bilder **sowohl in 0 ms im Browser als auch auf dem Server** ab (z. B. unscharfe niedrig aufgelöste Uploads oder nicht quadratische Produktfotos).

| Parameter | Standardwert | Aufgabe | Wann verwenden |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Aktiviert die Bildoptimierung beim Hochladen (Imagick → GD → stilles No-Op, falls keiner verfügbar). | Bei jedem Profil aktivieren (`true`), in dem Benutzer unbearbeitete Smartphone-Fotos hochladen. |
| `image_optimization.auto_orient` | `true` | Liest EXIF-Ausrichtungs-Tags aus und dreht quer gespeicherte Handyfotos automatisch richtig. | Für mobile Uploads auf `true` belassen. |
| `image_optimization.strip_exif` | `true` | Entfernt GPS-Koordinaten und Kamera-EXIF-Daten (DSGVO-Datenschutz + kleinere Dateien). | Für alle öffentlich zugänglichen Bilder auf `true` belassen. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Skaliert Bilder oberhalb dieser Grenzen proportional herunter (`>= 100`). | `1920`×`1920` ist ein idealer Richtwert für Web-Grafiken. |
| `image_optimization.convert_to_webp` | `false` | Konvertiert hochgeladene JPEG-/PNG-Dateien automatisch in WebP (30–60 % kleinere Dateien). | Für moderne Web-Performance-Profile aktivieren (`true`). |
| `image_optimization.quality` | `85` | Kompressionsqualität der Ausgabe (`10`–`100`). | `80`–`85` bietet das beste Verhältnis aus Dateigröße und Bildqualität. |
| `image_constraints.min_width` / `min_height` | `null` | Erforderliche Mindestabmessungen in Pixeln. | Verhindert unscharfe Uploads (z. B. Blog-Titelbilder mit mindestens `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Maximal zulässige Abmessungen in Pixeln (wird bei aktivierter `image_optimization` *nach* der Skalierung geprüft). | Um strikte Banner- oder Druckvorlagen-Abmessungen durchzusetzen. |
| `image_constraints.aspect_ratio` | `null` | Erforderliches Seitenverhältnis: `'1:1'` (quadratisch), `'16:9'` (Breitbild-Banner), `'4:3'`, `'3:4'`, `'1.91:1'` usw. | Quadratische E-Commerce-Produktkarten (`'1:1'`) oder Video-/Artikel-Header (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Zulässige Abweichung vom Zielverhältnis (`0.0`–`0.5`; `0.05` = 5 % Toleranz, sodass sowohl `1920×1080` als auch `1280×722` akzeptiert werden). | `0.05` passt für nahezu jeden Anwendungsfall. |

### 3.5. Vorschaubilder (`thumbnail`), Papierkorb (`trash`), ZIP (`zip`) & Signierte Freigabelinks (`share`)
| Parameter | Standardwert | Aufgabe |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Erzeugt zwischengespeicherte Vorschaubilder für Bilder: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` oder `'none'`. Cloud-Bilder werden einmalig abgerufen und lokal gecacht. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Verhalten, wenn weder GD noch Imagick installiert ist: `'fallback_original'` (Originalbild anzeigen), `'icon'` (Dateisymbol anzeigen) oder `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Thumbnail-Abmessungen (`>= 32`), Ausgabeformat (`webp`, `jpeg`, `png`) und Einpassungsmodus (`contain` = vollständiges Bild sichtbar, `cover` = zentriert zugeschnitten). Bei `ui.item_size: 'xl'` empfiehlt sich `480`×`360`. |
| `thumbnail.lazy_load` | `true` | Ergänzt natives `loading="lazy"` an Rasterkarten-Bildern für schnelleres initiales Rendern in großen Ordnern. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Lokales Verzeichnis, in dem generierte Vorschaubilder zwischengespeichert werden. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Verschiebt gelöschte Dateien zunächst in einen Papierkorb statt sie sofort unwiderruflich zu entfernen. Nutzt bei MIME-eingeschränkten Cloud-Buckets automatisch eine lokale `trash-meta.json`-Sidecar-Datei. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Aktiviert Mehrfach-Datei-/Ordner-Downloads als `.zip` mit einem Klick (erfordert PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | 7-stufig gegen Zip-Slip und Zip-Bomben abgesichertes Entpacken von Archiven. Bei `allow_extract: false` immer blockiert; beim Aktivieren wird `permissions.extract_zip` automatisch auf `true` gesetzt (kann bei Bedarf auf `'ROLE_ADMIN'` oder `false` eingeschränkt werden). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Erzeugt zeitlich begrenzte, über Symfonys `UriSigner` HMAC-signierte Freigabe-URLs. Das Setzen auf `enabled: false` macht alle zuvor ausgestellten Freigabelinks sofort ungültig. |

### 3.6. Benutzeroberfläche (`ui`) — Layout, Größen & Toolbar-Optionen
| Parameter | Standardwert | Aufgabe | Wann anpassen |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Profilspezifisches Theme (z. B. `'bootstrap5'` im Admin-Bereich, `'shadcn'` im Kundenportal). | Wenn verschiedene Bereiche einer App unterschiedliche visuelle Themes nutzen. |
| `ui.color_scheme` | `null` *(defaults)* | Profilspezifisches Farbschema (`auto`, `light`, `dark`). | `'dark'` für einen rein dunklen Arbeitsbereich festlegen. |
| `ui.icon_set` | `'classic'` | Symbolstil für Dateien/Ordner: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` oder ein eigener SVG-Verzeichnispfad (`'%kernel.project_dir%/assets/fm-icons'`). | `'windows11'` oder `'macos'` für ein Desktop-Gefühl oder eigene Marken-SVGs. |
| `ui.z_index` | `null` *(defaults)* | Profilspezifische Überschreibung des Overlay-`z-index`. | Bei Einbettung auf Seiten mit Editoren oder Drawern mit sehr hohem `z-index`. |
| `ui.mobile_dialog_mode` | `'auto'` | Dialogdarstellung auf kleinen Bildschirmen (`<= 767.98px`): `'auto'` (Modals & seitliche OffCanvas öffnen im Vollbild, obere/untere OffCanvas als abgerundete native Sheets), `'fullscreen'` (alle Vollbild) oder `'sheet'` (alle als natives Bottom-Sheet). | `'sheet'` oder `'fullscreen'` konsistent für alle mobilen Picker erzwingen. |
| `ui.default_view` | `'grid'` | Startansicht: `'grid'` (Karten mit Vorschaubildern) oder `'list'` (sortierbare Tabelle). | `'list'` für PDF-/Dokumentenarchive und `'grid'` für visuelle Galerien. |
| `ui.item_size` | `'md'` | Breite der Rasterkarten und Thumbnail-Skalierung in der Liste: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) oder ein exakter Pixelwert (`96`–`320`). Wechselt bei `<= 132px` automatisch auf kompakte Abstände. | `'lg'` / `'xl'` für visuelle Bildprüfung und `'sm'` / `'xs'` für kompakte Auswahldialoge. |
| `ui.card_aspect_ratio` | `'4/3'` | Seitenverhältnis des Vorschaurahmens in Karten: `'1/1'` (quadratisch), `'4/3'` (Standard), `'16/9'` (Breitbild/Video) oder `'3/4'` (Hochformat-Poster/Kataloge). | An das Hauptformat Ihrer Assets anpassen (`'1/1'` für Produkte, `'16/9'` für Videos/Banner). |
| `ui.folder_style` | `'tile'` | Ordnerdarstellung in der Rasteransicht: `'tile'` (horizontale Ordner-Kacheln im Google-Drive-Stil oberhalb der Dateien) oder `'card'` (vollwertige Ordnerkarten im Windows-/Finder-Stil passend zu Dateikarten). | Auf `'card'` wechseln für visuell präsentere Ordner. |
| `ui.show_size_slider` | `true` | Zeigt im Einstellungsmenü den Live-Schieberegler für die Kartengröße (`96px`–`320px`) sowie den Umschalter für den Ordnerstil. | Auf `false` setzen, wenn eine feste Layout-Dichte vorgegeben werden soll. |
| `ui.volume_order` | `'local_first'` | Sortierung der Volumes in Seitenleiste und Umschalter: `'local_first'`, `'network_first'` oder `'configured'` (exakte Reihenfolge aus YAML `volumes: [...]` ohne Gruppentrennlinien). | `'network_first'` oder `'configured'` für Cloud-First-Workflows. |
| `ui.show_folder_tree` | `true` | Blendet die linke Ordnerbaum-Seitenleiste ein oder aus. | Auf `false` setzen für schlichte Ein-Ordner-Auswahlen in TinyMCE oder für Avatare. |
| `ui.tree_lines` | `true` | Zeichnet Hierarchie-Verbindungslinien im Ordnerbaum. | Auf `false` setzen für eine ruhigere, minimalistische Seitenleiste. |
| `ui.show_folder_item_count` | `true` | Zeigt Elementanzahl-Badges (`12`) neben Ordnern an. Berücksichtigt Zugriffsrechte, sodass gesperrte Unterordner niemals in Zählwerten erscheinen. | Auf `true` belassen für schnellen Überblick über Ordnerinhalte. |
| `ui.folder_count_mode` | `'recursive_files'` | Zählweise für Ordner-Badges: `'recursive_files'` (alle Dateien inkl. Unterordner), `'files'` (nur direkte Dateien) oder `'items'` (direkte Dateien + Unterordner). | `'files'` wählen, wenn nur direkte Dateien gezählt werden sollen. |
| `ui.inspector_mode` | `'on_select'` | Rechtes Detail-/Vorschaupanel: `'on_select'` (öffnet bei Auswahl eines Elements), `'always'` oder `'hidden'`. | In schmalen Modal-Pickern auf `'hidden'` setzen. |
| `ui.folder_open_on` | `'dblclick'` | Geste zum Öffnen von Ordnern: `'dblclick'` (einfacher Klick markiert, Doppelklick öffnet — Desktop-Konvention) oder `'click'` (einfacher Klick öffnet sofort — Web-/Mobile-Konvention). | `'click'` für schnelles Navigieren per Einzelklick in Admin-Oberflächen. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Standardanzahl von Elementen pro Seite (`6`–`200`) und wählbare Seitengrößen. | Für umfangreiche Kataloge auf `48` und `[24, 48, 96, 150]` erhöhen. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Zeigt den Speicherkontingent-Balken in der Seitenleiste sowie das anklickbare Modal für die detaillierte Speichernutzung. (Die serverseitige Prüfung von `storage_quota` bleibt auch ausgeblendet aktiv.) | `show_quota: false` setzen, wenn Kontingentanzeigen in der Oberfläche verborgen bleiben sollen. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Steuert die Inline-PDF-Lightbox und den HTML5-Videoplayer (sowohl in der Oberfläche als auch serverseitig im `DownloadController` durchgesetzt). | Auf `false` setzen, um statt einer Browser-Vorschau einen direkten Download zu erzwingen. |
| `ui.remember_last_dir` | `true` | Merkt sich das zuletzt besuchte **Volume + Verzeichnis** im `localStorage` (fällt automatisch auf Elternordner/Wurzel zurück, falls der Ordner gelöscht wurde). | Auf `false` setzen, wenn jede Sitzung im Stammverzeichnis beginnen soll. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Zeigt vor dem Löschen oder Drag-and-Drop-Verschieben einen Bestätigungsdialog an. | `confirm_move: false` für schnelleres Umsortieren. |
| `ui.double_click_to_select` | `true` | Im Modal-/OffCanvas-Auswahlmodus wählt ein Doppelklick auf eine Datei diese sofort aus und schließt den Dialog. | Auf `false` setzen, wenn Doppelklick statt Auswahl eine Vorschau öffnen soll. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Aktiviert Rechtsklick-Kontextmenüs sowie die Schnellnavigationsleiste `[ ← \| → \| ↑ \| ↻ ]` beim Rechtsklick auf leere Flächen. | Auf `false` setzen für stark eingeschränkte Endbenutzer-Ansichten. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Aktiviert das Verschieben per Drag-and-Drop zwischen Ordnern und das Hochladen durch Hineinziehen von Dateien/Ordnern aus dem Betriebssystem. | Für schreibgeschützte Asset-Bibliotheken auf `false` setzen. |
| `ui.keyboard_shortcuts` | `true` | Aktiviert Tastenkombinationen (`Strg+A`, `Strg+C/X/V`, `F2`, `Entf`, `Leertaste`, `/`, `R`, `F`, `?` usw.). | Deaktivieren (`false`), falls Ihre umgebende Seite kollidierende globale Shortcuts registriert. |
| `ui.show_*`-Flags | *(meist `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Einzelne Toolbar-Elemente gezielt ein- oder ausblenden, um die Oberfläche zu straffen. *(Hinweis: Die Einträge „Über“ und „GitHub“ im Einstellungsmenü sind feste Herkunftshinweise und werden nicht über YAML abgeschaltet.)* |

### 3.7. Profilspezifischer Cache (`cache`), Sicherheit (`security`), Zugriffsrechte (`permissions`), KI & Usage Guard

#### Cache (`cache`)
| Parameter | Standardwert | Beschreibung |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Aktiviert oder deaktiviert das Caching für dieses Profil. Wenn ein Profil `cache.enabled: false` setzt, schaltet `volume.cache_ttl` den Cache nicht wieder ein. |
| `cache.ttl` | `null` *(defaults)* | TTL-Rangfolge: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Sicherheit (`security`)
| Parameter | Standardwert | Beschreibung |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Aktiviert die RBAC-Prüfung über `FileManagerVoter` und eigene Voter. In der Produktion immer auf `true` belassen. |
| `security.csrf_protection` | `true` | Verlangt bei jeder `POST`-Anfrage einen gültigen `X-LiveUX-FM-CSRF`-Header. |
| `security.sanitize_svg` | `true` | Bereinigt hochgeladene und bearbeitete SVGs anhand einer strengen Positivliste mit 55 erlaubten Tags (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Aktiviert den Tab **„Von URL importieren“** im Upload-Dialog (durch `RemoteUrlImporter` gegen SSRF und DNS-Rebinding geschützt). |
| `security.audit_log` | `null` *(defaults)* | Überschreibt das PSR-3-Audit-Logging für dieses Profil (`true` / `false` / `null`). |

#### 17 granulare Zugriffsrechte (`permissions`)
> [!WARNING]
> **Wichtig: Was bedeutet `true` unter `permissions`?**  
> Der Wert **`true`** bedeutet **NICHT** *„öffentlicher / anonymer Zugriff“*! `FileManagerVoter` gewährt `true` **ausschließlich authentifizierten Benutzern (`$token->getUser() !== null`)** und weist anonyme Anfragen ab.  
> Außerdem fungiert **`permissions.view`** als Hauptschalter: Fehlt einem Benutzer `view`, werden sämtliche anderen Aktionen (`upload`, `delete`, `download` usw.) automatisch verweigert.

Jede Berechtigung akzeptiert eines von 4 Formaten:
- `true` → Jeder angemeldete Benutzer
- `false` → Niemand (Aktion vollständig deaktiviert)
- `'ROLE_ADMIN'` → Nur Benutzer mit dieser Rolle
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Benutzer mit mindestens einer dieser Rollen

| Berechtigungsschlüssel | Standardwert | Gesteuerte Aktionen |
| :--- | :--- | :--- |
| `view` | `true` | Verzeichnisauflistung, Ordnerbaum, Vorschauen sowie **Voraussetzung für jede weitere Berechtigung**. |
| `upload` | `true` | Standard-/Chunked-Uploads, Speichern aus dem Bildeditor und Remote-URL-Importe. |
| `create_folder` | `true` | Erstellen neuer Ordner. |
| `rename` | `true` | Umbenennen von Dateien und Ordnern. |
| `clone` | `true` | Duplizieren einer Datei oder eines Ordners. |
| `move` | `true` | Verschieben von Elementen per Drag-and-Drop oder Ausschneiden/Einfügen. |
| `copy` | `true` | Kopieren von Elementen per Kopieren/Einfügen oder über den Dialog „Verschieben/Kopieren“. |
| `delete` | `true` | Löschen von Dateien/Ordnern (in den Papierkorb verschieben oder endgültig löschen). |
| `trash_restore` | `true` | Wiederherstellen von Elementen aus dem Papierkorb. |
| `trash_purge` | `true` | Endgültiges Entfernen von Elementen aus dem Papierkorb oder Leeren des gesamten Papierkorbs. |
| `download` | `true` | Herunterladen einzelner Dateien, Streamen von PDFs/Videos und Kopieren von Datei-URLs. |
| `download_zip` | `true` | Herunterladen mehrerer ausgewählter Elemente als `.zip`-Archiv. |
| `extract_zip` | `null` *(auto)* | Bei `zip.allow_extract: false` erzwungen auf `false`; wird bei `zip.allow_extract: true` automatisch zu `true` (bei Bedarf auf `'ROLE_ADMIN'` einschränken). |
| `share` | `true` | Erstellen zeitlich begrenzter, HMAC-signierter Freigabelinks. |
| `clear_cache` | `true` | Leeren des Verzeichnis-Caches über die Einstellungen sowie Erzwingen von `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Bearbeiten von `alt`/`title`/`caption`/`tags`/Fokuspunkt für Dateien sowie Ordnerfarbe/-symbol/-Pinning. |
| `ai_enrich` | `true` | Aufrufen von **✨ Mit KI vorschlagen** (erfordert `view` + `edit_metadata` + `ai_enrich`). |

#### KI-Anreicherung (`ai`) & Asset Usage Guard (`usage_guard`)
| Parameter | Standardwert | Beschreibung |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Aktiviert KI-Metadaten-Vorschläge; `locale` steuert die Ausgabesprache (z. B. `'de'`, `'en'`, `'tr'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Maximale Anzahl vorgeschlagener Tags (`1`–`20`) und Skalierungslimit vor dem Versand an die KI (`256`–`2048` px, spart Vision-Tokens). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Verhindert, dass Dateien aus `private: true`-Volumes an externe KI-APIs gesendet werden; optionale automatische Anreicherung nach dem Hochladen (siehe [Kapitel 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Warnt vor oder blockiert **`delete`, `rename`, `move` und `overwrite`**, solange ein Asset noch in Ihrer Datenbank referenziert wird (siehe [Kapitel 10](10-asset-usage-guard.md)). |

---

## 4. 5 Produktionsreife Profil-Rezepte (Copy & Paste)

Hier finden Sie die 5 häufigsten Praxiskonfigurationen zum direkten Übernehmen in Ihr Projekt:

### Rezept 1: E-Commerce-Produktkatalog (Quadratische Karten + WebP + Duplikat-Wiederverwendung)
> **Ziel:** Nur scharfe quadratische `1:1`-Produktfotos akzeptieren; Smartphone-Uploads automatisch skalieren und nach WebP konvertieren; bei Duplikaten die vorhandene Datei wiederverwenden; versehentliches Löschen von Assets aktiver Produkte verhindern.

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
                slugger_locale: 'de'
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
                item_size: 'lg'          # 204px große Produktkarten
                card_aspect_ratio: '1/1' # Quadratisches Produktformat
                folder_style: 'card'     # Vollwertige Kategorie-Ordnerkarten
                show_folder_item_count: true
```

### Rezept 2: Blog- / News-Titelbilder (`16:9`-Banner + KI-Alt-Texte)
> **Ziel:** Nur hochauflösende `16:9`-Breitbild-Titelbilder (mindestens `1200×630` px) akzeptieren, Uploads automatisch in `2026/11`-Ordnern ablegen und SEO-Alt-Texte sowie Tags per KI generieren.

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
            sort_direction: 'desc'    # Neueste Titelbilder zuerst
            ai:
                enabled: true
                locale: 'de'
                max_tags: 6
            ui:
                item_size: 'xl'           # 260px breite Titelbild-Vorschauen
                card_aspect_ratio: '16/9' # Breitbild-Banner-Seitenverhältnis
```

### Rezept 3: Kundendokumente & PDF-Tresor (Privates Supabase/S3 + Benutzerisolierung)
> **Ziel:** Jeden Kunden auf seine eigenen PDF-Rechnungen in einem privaten S3-kompatiblen Bucket isolieren, direkte externe URLs blockieren, Cloud-Listen alle 60 Sekunden aktualisieren und jede Aktion im Audit-Log protokollieren.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Kunden-PDF-Tresor'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Kein unauthentifizierter Direkt-URL-Zugriff
            cache_ttl: 60          # Externe Cloud-Änderungen erscheinen innerhalb von 60 s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Jeder Benutzer sieht nur seinen eigenen Ordner
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
                default_view: 'list'     # Sortierbare Tabelle, ideal für PDFs
                item_size: 'sm'          # Kompakte Zeilen
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Rezept 4: Schneller Rich-Text-Editor-Picker (TinyMCE / CKEditor / Tiptap)
> **Ziel:** Ein schlanker, ablenkungsfreier Modal-Picker, bei dem ein Doppelklick auf ein Bild dieses sofort in den Editor einfügt.

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

### Rezept 5: Schreibgeschützte Partner- / Presse-Bibliothek
> **Ziel:** Partner dürfen Logos und Kataloge durchsuchen, einzelne Dateien oder ZIP-Pakete herunterladen und signierte Freigabelinks erstellen — während Uploads, Bearbeitungen und Löschungen komplett unterbunden sind.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 Tage
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

## 5. Dynamische Pfad-Platzhalter (`DynamicPathResolver`)

Sie können dynamische Platzhalter im lokalen `path`, in `url_prefix`, im Cloud-`driver_options.prefix` sowie in `upload_subfolder` eines Profils verwenden:

| Platzhalter | Beschreibung | Beispielausgabe |
| :--- | :--- | :--- |
| `{user_id}` | Kollisionsfreie, dateisystemsichere Kennung des angemeldeten Symfony-Benutzers (`guest`, wenn nicht angemeldet). | `42` oder `alice_a--h-9f1c2e4b...` |
| `{Y}` | 4-stelliges Jahr | `2026` |
| `{m}` | 2-stelliger Monat | `11` |
| `{d}` | 2-stelliger Tag | `25` |

```yaml
volumes:
    user_space:
        label: 'Mein persönlicher Bereich'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Kollisionsfreie Mandanten-Isolierung mit `{user_id}`:**  
> `DynamicPathResolver` erzeugt **disjunkte, mit SHA-256-Suffix versehene `<slug>--h-<16-hex>`**-Verzeichnisschlüssel für E-Mail-Adressen (`alice.a@example.com` vs. `alice_a@example.com`), Groß-/Kleinschreibungsvarianten (`Alice` vs. `alice`), Unicode-Benutzernamen (Arabisch, Chinesisch, Türkisch, Deutsch), reservierte Wörter (`guest`, `user`, Kennungen mit `--h-`-Endung) und Windows-Gerätenamen (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Zwei verschiedene Benutzer können niemals im selben Ordner landen.
>
> **CLI-Verhalten:** Konsolenbefehle (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup` usw.) überspringen Volumes mit `{user_id}` automatisch, sodass niemals ein unerwünschter `guest/`-Ordner angelegt wird.

---

## 6. Vollständige YAML-Schemareferenz (Alle aktiven Optionen)

Das folgende YAML-Snippet dokumentiert **jeden aktiven Konfigurationsschlüssel** aus `Configuration.php` inklusive Standardwert und Inline-Kommentar. Deklarieren Sie in Ihrem eigenen Projekt nur die Schlüssel, die Sie tatsächlich anpassen möchten — alle übrigen erben automatisch ihren Standardwert.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) GLOBALE STANDARDWERTE — Von allen Profilen geerbt, sofern nicht überschrieben
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Standard-Stammverzeichnis für Ein-Laufwerk-Kurzprofile
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = Symfony-Request-/Translator-Locale (12 integrierte Sprachen, Fallback 'en') | 'de' | 'en' | 'tr' | ...
        z_index: 1050                                      # Basis-z-index für Overlays, Popovers und Modals (>= 1)
        auto_inject_dialog_host: true                      # Modal-/OffCanvas-DialogHost automatisch in vollständige HTML-Antworten einfügen
        dialog_host_profile: 'default'                     # Standardprofil für den automatisch eingefügten DialogHost

        audit_log:
            enabled: false                                 # Alle 9 Datei-/Ordner-Änderungen in einen PSR-3-Kanal protokollieren
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Standard-KI-Modell, sofern nicht pro Profil überschrieben
            platform_service: null                         # Eigene Symfony-AI-PlatformInterface-Service-ID (null = automatisch)

        cache:
            enabled: true                                  # Globaler Schalter für den PSR-6-Verzeichnis-/Baum-Cache
            pool: 'cache.app'                              # Service-ID des Symfony-PSR-6-Cache-Pools
            ttl: 3600                                      # Globale Cache-TTL in Sekunden (0 = deaktiviert)

        metadata:
            enabled: true                                  # Ordnerfarben/-symbole/-Pins & Datei-alt/title/caption/tags/focalPoint
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Speicherdatei für den Treiber json_file
            connection: null                               # Optionale DBAL-Verbindung für den doctrine-Treiber (null = Verbindung des entity_manager)
            entity_manager: 'default'                      # EntityManager- / Verbindungsname für den doctrine-Treiber
            table: 'liveux_fm_metadata'                    # DBAL-Tabellenname für den doctrine-Treiber (siehe Kapitel 13)
            auto_create_table: true                        # Metadaten-Tabelle bei Fehlen automatisch erstellen (bei Migrationen auf false setzen)

    # =========================================================================
    # 2) SPEICHER-VOLUMES — Lokale & Cloud-Dateisystem-Definitionen
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Lokaler Speicher'                      # Anzeigename in Seitenleiste & Breadcrumbs
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Physischer Pfad (unterstützt {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Öffentliches Browser-URL-Präfix (bei private: true auf null belassen)
            private: false                                 # true = alle Dateien über RBAC-geschützten DownloadController streamen
            cache_ttl: null                                # Volume-spezifische Cache-TTL in Sekunden (null = erben, 0 = deaktiviert)
            driver_options: []                             # Leer bei LocalFileSystem

        cloud_s3_example:
            label: 'Cloud S3 / R2 / Supabase'
            icon: 'supabase'                               # Entspricht resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # Öffentliche Bucket-/CDN-URL (bei private: true auf null setzen)
            private: false
            cache_ttl: 60                                  # Externe Cloud-Änderungen alle 60 Sekunden aktualisieren
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Bucket-Name
                region: '%env(AWS_S3_REGION)%'             # Region ('auto' bei Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # Benutzerdefinierte S3-Endpunkt-URL für Nicht-AWS-Anbieter
                prefix: 'media/{Y}'                        # Optionales Wurzelpräfix im Bucket (unterstützt dynamische Platzhalter)
                use_path_style_endpoint: true              # true für Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Optionales temporäres AWS-STS-Session-Token
                version: 'latest'                          # AWS-S3-API-Version (Standard: 'latest')
                ca_bundle: null                            # Optionaler Pfad zu einer eigenen CA-Bundle-.pem-Datei (behebt Windows cURL error 60)
                disable_acl: true                          # Automatisch true bei benutzerdefinierten Endpunkten; bei AWS S3 Bucket owner enforced auf true setzen
                retain_visibility: false                   # Automatisch false bei benutzerdefinierten Endpunkten (vermeidet x-amz-acl)
                request_checksum_calculation: 'when_required'  # Kompatibilität für AWS SDK >= 3.337 (automatisch bei benutzerdefinierten Endpunkten)
                response_checksum_validation: 'when_required'  # Kompatibilität für AWS SDK >= 3.337 (automatisch bei benutzerdefinierten Endpunkten)

        custom_flysystem_example:
            label: 'SFTP-Archiv'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # Service-ID eines League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) PROFILE — Unabhängige Regelwerke pro Anwendungsfall
    # =========================================================================
    profiles:
        full_reference:
            # --- Eingebundene Volumes, Locale & Kurzschreibweise für Ein-Laufwerk-Profile ---
            locale: null                                   # null = erbt defaults.locale | 'de' | 'en' | 'tr' | 'fr' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Wenn leer, wird aus driver/path unten ein implizites Volume erzeugt
            driver: 'LocalFileSystem'                      # Kurzschreibweise-Treiber, nur aktiv wenn volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Kurzschreibweise-Pfad, nur aktiv wenn volumes: []
            url_prefix: '/uploads'                         # Kurzschreibweise-URL-Präfix, nur aktiv wenn volumes: []
            private: false                                 # Kurzschreibweise-Datenschutzflag, nur aktiv wenn volumes: []
            driver_options: []                             # Kurzschreibweise-Treiberoptionen, nur aktiv wenn volumes: []

            # --- Auflistung, Ausblenden & Standardsortierung ---
            ignore_dot_files: true                         # Dotfiles (.env, .htaccess, .git) ausblenden und blockieren
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Regex-/Wildcard-Muster für Ausblendung & Zugriffssperre
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Ordner immer vor Dateien auflisten

            # --- Uploads, Kontingent & Konfliktbehandlung ---
            upload_subfolder: null                         # z. B. '{Y}/{m}' — Uploads in dynamischen Unterordnern ablegen
            upload_allow: []                               # Leer = alle sicheren Typen; nicht leer = strikte MIME-Positivliste
            upload_deny:                                   # Immer blockierte gefährliche MIME-Typen (standardmäßig 47, wenn weggelassen)
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
            upload_max_size: '10M'                         # Maximale Gesamtgröße einer einzelnen Datei (z. B. '50M', '2G')
            storage_quota: null                            # Gesamtspeicherkontingent des Profils (z. B. '500M', '5G'; null = unbegrenzt)
            overwrite_policy: 'rename'                     # rename (-1 anhängen) | overwrite | reject

            # --- Chunked Uploads (Umgehen von PHP upload_max_filesize) ---
            chunk_upload:
                enabled: true                              # Große Dateien in fortsetzbare Blöcke aufteilen
                chunk_size: '2M'                           # Ziel-Chunk-Größe (automatisch auf 80 % der PHP-Limits begrenzt)
                parallel_uploads: 3                        # Max. gleichzeitige Uploads (1..10)
                max_retries: 3                             # Automatische Wiederholungen pro Chunk bei Netzwerkfehlern (0..10)

            # --- Duplikaterkennung (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Doppelte Dateiinhalte beim Hochladen erkennen
                policy: 'warn'                             # warn | reject | reuse (vorhandene Datei zurückgeben)
                max_scan_files: 5000                       # Max. gehashte Dateien für die Duplikat-Indizierung (100..50000)

            # --- Automatische Dateinamen-Normalisierung ---
            naming:
                slugger: true                              # Unicode-Dateinamen in saubere ASCII-Slugs transliterieren
                slugger_locale: null                       # null = erbt Profil-Locale (z. B. 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Statisches/dynamisches Präfix (z. B. 'produkt-')
                append: ''                                 # Statisches/dynamisches Suffix vor der Endung (z. B. '-{Y}{m}{d}')
                apply_on_rename: true                      # slugger/transformer auch beim Umbenennen anwenden
                apply_affixes_on_rename: false             # prepend/append beim Umbenennen erneut anwenden
                allow_extension_change: false              # Benutzern erlauben, beim Umbenennen die Endung (.jpg -> .php) zu ändern

            # --- Bildoptimierung beim Hochladen ---
            image_optimization:
                enabled: false                             # Beim Hochladen automatisch drehen, EXIF entfernen, skalieren & komprimieren
                auto_orient: true                          # Smartphone-EXIF-Ausrichtung korrigieren
                strip_exif: true                           # GPS- & Kamera-EXIF-Daten aus Datenschutz- und Größengründen entfernen
                max_width: 2400                            # Proportionale Maximalbreite in px (>= 100)
                max_height: 2400                           # Proportionale Maximalhöhe in px (>= 100)
                convert_to_webp: false                     # Hochgeladene JPEG-/PNG-Dateien automatisch in WebP konvertieren
                quality: 85                                # Ausgabequalität (10..100)

            # --- Bildauflösungs- & Seitenverhältnis-Regeln ---
            image_constraints:
                min_width: null                            # Mindestbreite in px (z. B. 800)
                min_height: null                           # Mindesthöhe in px (z. B. 600)
                max_width: null                            # Maximalbreite in px (wird nach image_optimization geprüft)
                max_height: null                           # Maximalhöhe in px
                aspect_ratio: null                         # Erforderliches Seitenverhältnis (z. B. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Zulässige Abweichung (0.0..0.5; 0.05 = 5 %)

            # --- Thumbnail-Engine ---
            thumbnail:
                enabled: true                              # Zwischengespeicherte Karten-Vorschaubilder generieren
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Thumbnail-Breite in px (>= 32)
                height: 240                                # Thumbnail-Höhe in px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Kompressionsqualität (10..100)
                fit: 'contain'                             # contain (vollständig einpassen) | cover (zuschneiden & füllen)
                lazy_load: true                            # loading="lazy" an Karten-Vorschaubildern setzen
                cache_dir: '%kernel.project_dir%/public/media-cache' # Lokales Cache-Verzeichnis für erzeugte Thumbnails

            # --- Papierkorb (Soft Delete) ---
            trash:
                enabled: true                              # Gelöschte Elemente in den Papierkorb statt dauerhaft entfernen
                directory: '.trash'                        # Versteckter Papierkorb-Ordnername
                retention_days: 30                         # Aufbewahrungsdauer in Tagen für file-manager:trash:purge
                max_size: '500M'                           # Maximale Gesamtgröße des Papierkorbs

            # --- ZIP-Download & sicheres Entpacken ---
            zip:
                enabled: true                              # Mehrfach-Datei-/Ordner-ZIP-Downloads aktivieren
                max_files: 100                             # Max. Dateien pro ZIP-Download
                max_total_size: '250M'                     # Max. Gesamtgröße pro ZIP-Download
                allow_extract: false                       # Entpacken aktivieren (standardmäßig aus; 7-stufig geschützt)
                max_extract_files: 200                     # Max. entpackte Dateien pro Archiv (1..2000)
                max_extract_size: '100M'                   # Max. entpackte Gesamtgröße (Schutz gegen Zip-Bomben)
                max_compression_ratio: 100                 # Max. zulässiges Kompressionsverhältnis (1..1000 — Schutz gegen Zip-Bomben)

            # --- Zeitlich begrenzte signierte Freigabelinks (HMAC) ---
            share:
                enabled: true                              # Erstellen signierter Freigabe-URLs erlauben
                default_ttl: 86400                         # Standard-Gültigkeit in Sekunden (86400 = 24 Stunden)
                max_ttl: 2592000                           # Maximal wählbare Gültigkeit in Sekunden (2592000 = 30 Tage)
                allow_inline_preview: true                 # Inline-Browservorschau für sichere MIME-Typen zulassen

            # --- Optionale Symfony-AI-Anreicherung ---
            ai:
                enabled: false                             # „✨ Mit KI vorschlagen“ im Metadaten-Dialog anzeigen
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = Profil-/Request-Locale (z. B. 'de' => deutsche Alt-Texte/Tags)
                max_tags: 6                                # Maximale Anzahl vorgeschlagener Tags (1..20)
                max_image_dimension: 1024                  # Skalierungslimit vor dem Versand an die KI (256..2048 px)
                block_private_volumes: true                # true = KI-Aufrufe für private: true-Volumes blockieren (Standard: true)
                auto_enrich_on_upload: false               # KI-Anreicherung nach dem Hochladen automatisch ausführen

            # --- Asset Usage Guard (Delete / Rename / Move / Overwrite) ---
            usage_guard:
                enabled: false                             # Datenbankreferenzen vor Änderungen an Assets prüfen
                policy: 'warn'                             # warn (Referenzen anzeigen + Bestätigung zulassen) | block (Aktion verbieten)
                enforce_server_side: true                  # Auch bei direkten API-Aufrufen serverseitig erzwingen
                max_references: 20                         # Max. angezeigte Referenzen im Dialog (1..200)

            # --- Profilspezifisches Caching ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | Sekunden (0 = deaktiviert)

            # --- Sicherheitsoptionen ---
            security:
                voter_enabled: true                        # FileManagerVoter-RBAC-Prüfungen aktivieren
                csrf_protection: true                      # X-LiveUX-FM-CSRF bei POST-Anfragen verlangen
                sanitize_svg: true                         # Hochgeladene/bearbeitete SVGs bereinigen
                allow_remote_url_import: false             # Tab „Von URL importieren“ aktivieren (SSRF-geschützt)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 granulare RBAC-Berechtigungen (true = angemeldeter Benutzer | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Hauptberechtigung für Auflistung und alle weiteren Aktionen
                upload: true                               # Datei-Uploads, Speichern im Bildeditor und URL-Importe
                create_folder: true                        # Neue Ordner erstellen
                rename: true                               # Dateien und Ordner umbenennen
                clone: true                                # Dateien/Ordner duplizieren
                move: true                                 # Elemente verschieben (inkl. Drag-and-Drop & Ausschneiden/Einfügen)
                copy: true                                 # Elemente kopieren (inkl. Kopieren/Einfügen)
                delete: true                               # Elemente löschen (Papierkorb oder endgültig löschen)
                trash_restore: true                        # Elemente aus dem Papierkorb wiederherstellen
                trash_purge: true                          # Elemente endgültig löschen / Papierkorb leeren
                download: true                             # Einzeldatei-Download & URL kopieren
                download_zip: true                         # Mehrfach-ZIP-Download
                extract_zip: false                         # ZIP-Archive entpacken (erfordert zusätzlich zip.allow_extract: true)
                share: true                                # Signierte Freigabelinks erstellen
                clear_cache: true                          # Cache über Einstellungen leeren & ?refresh=1 erzwingen
                edit_metadata: true                        # Datei-Alt/Tags/Fokuspunkt & Ordnerfarbe/-symbol/-Pin bearbeiten
                ai_enrich: true                            # KI-Metadaten-Anreicherung ausführen

            # --- Benutzeroberfläche (UI): Flags & Größen ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <eigenes Verzeichnis>
                z_index: null                              # null = defaults.z_index | Ganzzahl (>= 1)
                mobile_dialog_mode: 'auto'                 # Dialogverhalten mobil (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px kompakt)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (kompakte Kachel) | card (vollwertige Ordnerkarte)
                show_size_slider: true                     # Live-Größenregler & Ordnerstil-Umschalter in den Einstellungen anzeigen
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Linke Ordnerbaum-Seitenleiste anzeigen
                tree_lines: true                           # Hierarchie-Verbindungslinien im Ordnerbaum anzeigen
                show_folder_item_count: true               # Elementanzahl-Badges an Ordnern anzeigen
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Kontingent-Anzeige in Seitenleiste & Kopfzeile anzeigen
                show_quota_details: true                   # Anklickbares Modal für detaillierte Speichernutzung aktivieren
                per_page: 24                               # Standardanzahl Elemente pro Seite (6..200)
                per_page_options: [24, 48, 96]             # Auswahlwerte im Seitengrößen-Dropdown
                show_perf_badge: false                     # CACHE HIT / DISK-Laufzeit-Badge in der Toolbar anzeigen
                show_nav_buttons: true                     # Hauptschalter für Zurück / Vorwärts / Hoch / Aktualisieren
                show_history_buttons: true                 # Zurück- / Vorwärts-Verlaufsschaltflächen anzeigen
                show_up_button: true                       # Schaltfläche „Eine Ebene höher“ anzeigen
                show_refresh_button: true                  # Schaltfläche „Aktualisieren“ (?refresh=1) anzeigen
                show_breadcrumbs: true                     # Anklickbare/Drop-fähige Breadcrumb-Leiste anzeigen
                show_search: true                          # Suchfeld & Tiefensuche-Umschalter anzeigen
                show_type_filter: true                     # Typfilter (Bilder / Videos / Dokumente / Ordner) anzeigen
                show_view_toggle: true                     # Raster-/Listen-Umschalter anzeigen
                show_pagination: true                      # Untere Seitennavigation anzeigen
                show_per_page_selector: true               # Dropdown für Elemente pro Seite anzeigen
                show_sort: true                            # Sortier-Dropdown & Richtungsumschalter anzeigen
                show_fullscreen_button: true               # Vollbild-Schaltfläche anzeigen
                show_settings_button: true                 # Einstellungen-Dropdown (⚙) anzeigen
                show_footer: true                          # Untere Statusleiste anzeigen
                show_context_nav: true                     # Schnellnavigationsleiste im Kontextmenü auf freier Fläche anzeigen
                allow_dropzone_upload: true                # Hochladen per Drag-and-Drop aus dem Betriebssystem erlauben
                remember_last_dir: true                    # Letztes Volume & Verzeichnis merken (heilt sich bei Löschung selbst)
                confirm_delete: true                       # Bestätigungsdialog vor dem Löschen anzeigen
                confirm_move: true                         # Bestätigungsdialog vor dem Verschieben/Kopieren anzeigen
                double_click_to_select: true               # Doppelklick wählt Datei aus & schließt Modal-/OffCanvas-Picker
                allow_context_menu: true                   # Rechtsklick-Kontextmenü aktivieren
                allow_pdf_preview: true                    # Inline-PDF-Lightbox (false erzwingt Download)
                allow_video_preview: true                  # Inline-Videoplayer (false erzwingt Download)
                keyboard_shortcuts: true                   # Fokusgebundene Tastenkombinationen aktivieren
                allow_drag_move: true                      # Verschieben von Elementen durch Ziehen in Ordner erlauben
```

---

[← Zurück: 01 — Voraussetzungen & Installation](01-requirements-and-installation.md) | [Dokumentationsübersicht](README.md) | [Weiter: 03 — Verwendungsmodi & JS-API →](03-usage-modes-and-js-api.md)
