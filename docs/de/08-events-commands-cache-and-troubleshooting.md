# 08 — Events, CLI-Befehle, Cache, Übersetzungen & Fehlerbehebung (FAQ)

[← Zurück: 07 — Cloud-Speicher & Dynamische Konfiguration](07-cloud-storage-and-dynamic-config.md) | [Übersicht](README.md) | [Weiter: 09 — Symfony-AI-Integration →](09-symfony-ai-integration.md)

> [!TIP]
> **Auf einen Blick: Wofür dienen Events und CLI-Befehle?**
> - **Events:** Benachrichtigen Ihre Symfony-Anwendung automatisch, sobald ein Benutzer eine Datei hochlädt, löscht, umbenennt oder verschiebt – zum Beispiel: *„Wenn eine Datei umbenannt wird, aktualisiere automatisch den Produktbild-Pfad in der Datenbank.“*
> - **CLI-Befehle (`php bin/console file-manager:...`):** Integrierte Terminal-Werkzeuge für Systemdiagnosen (`doctor`), Cache-Invalidierung (`cache:clear`), Vorab-Erstellung von Vorschaubildern (`thumbs:warmup`) oder das Bereinigen abgelaufener Papierkorb-Einträge (`trash:purge`).

---

## 1. Symfony-Events (9 Domain-Events)

Jedes Dateimanager-Event erbt von der gemeinsamen Basisklasse **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Registrieren Sie Listener auf diese Events, um Ihre Datenbank synchron zu halten, Audit-Logs zu schreiben, CDN-Caches zu leeren oder Hintergrundjobs anzustoßen.

| Event-Klasse (`LiveUX\FileManagerBundle\Event\*`) | Ausgelöst bei | Eigenschaften |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Hochladen einer Datei, URL-Import, Speichern im Bildeditor oder Entpacken aus einem ZIP-Archiv | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Löschen einer Datei (in den Papierkorb oder endgültig) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Erstellen eines Ordners (einschließlich beim ZIP-Entpacken) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Löschen eines Ordners | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Umbenennen einer Datei oder eines Ordners | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Verschieben oder Kopieren eines Elements (inkl. Volume-übergreifender Transfers) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Duplizieren/Klonen eines Elements | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Wiederherstellen eines Elements aus dem Papierkorb | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Endgültiges Entfernen eines oder mehrerer Elemente aus dem Papierkorb | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Beispiel für einen Event-Listener (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('Neues Medien-Asset hochgeladen.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Konsolenbefehle (CLI) (10 integrierte Befehle)

| Befehl | Optionen / Aliase | Beschreibung |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Prüft PHP-Version, `upload_max_filesize`, `post_max_size`, cURL-CA-Bundle, GD/Imagick, EXIF, WebP, `ext-zip` sowie jede Profil- und Volume-Konfiguration. Mit `--check-remote` werden zusätzlich Live-Verbindungstests für S3/R2/Supabase/MinIO ausgeführt (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Leert Verzeichnis- und Kontingent-Caches für alle Profile oder ein bestimmtes Profil/Volume/Pfad (`--volume`/`--path` erfordern das Argument `profile`; löscht niemals fremde Einträge in `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Aliase: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Erzeugt Vorschaubilder vorab, damit Redakteure beim ersten Laden nicht warten müssen. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Leert zwischengespeicherte Vorschaubilder. `--orphans` entfernt nur verwaiste Vorschaubilder, deren Quelldatei nicht mehr existiert (nicht kombinierbar mit `--volume`/`--path`; schützt automatisch geteilte `{user_id}`- / `{Y}/{m}/{d}`-Verzeichnisse). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Optimiert bestehende Bilder nachträglich (Sicherheits-/Speicherlimit: max. `25 MB` pro Bild). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Erkennt doppelte Dateien per SHA-256-Inhalts-Hash und zeigt einsparbaren Speicherplatz an. |
| `file-manager:stats [profile]` | `--json` | Zeigt Speichernutzung, Datei-/Ordneranzahl, Kontingentauslastung, Vorschaubilder und Papierkorb-Statistiken an. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(Standard `50`)*, `--dry-run` | Erzeugt fehlende `alt`-Texte, Titel, Bildunterschriften und Tags im Stapelbetrieb per KI. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = alle löschen)*, `--dry-run` | Löscht abgelaufene Papierkorb-Einträge endgültig. Teilen sich mehrere Profile ein physisches Verzeichnis, gilt automatisch der längste `retention_days`-Wert. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Installiert die 6 AI-Agent-Skills des Bundles in `.agents/skills`, `.claude/skills`, `.cursor/skills` oder `.github/skills`. |

> [!NOTE]
> **Schutz dynamischer `{user_id}`- und `{Y}/{m}/{d}`-Pfade in CLI-Befehlen**
> Da im CLI-Modus keine authentifizierte Benutzersitzung existiert, **überspringen** alle Scan- und Bereinigungsbefehle (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) Volumes mit `{user_id}` automatisch (und verhindern so das versehentliche Anlegen eines leeren `guest/`-Ordners). Bei Volumes mit `{Y}/{m}/{d}`-Datumsplatzhaltern wird nur der aktuelle Zeitraum gescannt und ein entsprechender Hinweis ausgegeben.

### Cron-Beispiel für die automatische Papierkorb-Bereinigung

```cron
# Abgelaufene Papierkorb-Einträge jede Nacht um 03:00 Uhr endgültig löschen
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Performance, Cache-Architektur & Symfony Profiler

- **3-stufige Cache-TTL & manuelles Aktualisieren (`↻`):** Die TTL für Verzeichnisauflistungen wird in dieser Reihenfolge aufgelöst: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` deaktiviert den Cache für das jeweilige Volume oder Profil). Ein Klick auf **Aktualisieren (`↻`)** in der Werkzeugleiste oder Drücken von `R` sendet `?refresh=1` *(das Umgehen des Caches erfordert die Berechtigung `CLEAR_CACHE`)*.
- **Eigener Cache-Pool & Profil-Fingerprinting:** Verweisen Sie `defaults.cache.pool` auf einen dedizierten Redis-/APCu-Pool (existiert `<pool>.taggable`, wird dieser automatisch genutzt). Ohne taggable Cache verwendet `DirectoryCacheManager` 30 Tage gültige Generationszähler-Keys und ruft niemals `$cachePool->clear()` auf dem Haupt-Cache Ihrer Anwendung auf. Teilen sich zwei Profile denselben physischen Ordner mit unterschiedlichen Regeln (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), bleiben ihre Caches über `buildProfilePolicyFingerprint()` strikt voneinander isoliert.
- **Kanonische Key-Synchronisation:** Zeigen zwei Profile auf dasselbe physische Verzeichnis, invalidiert das Hochladen oder Ändern einer Datei in einem Profil automatisch auch den Cache des anderen Profils.
- **Selbstheilendes `remember_last_dir`:** Wurde das zuletzt besuchte Verzeichnis eines Benutzers extern gelöscht (z. B. per FTP oder in einer S3-Konsole), wechselt der Dateimanager fehlertolerant zum nächstgelegenen existierenden übergeordneten Ordner oder ins Stammverzeichnis, statt einen Fehler anzuzeigen.
- **HTTP 304 & kontextbewusstes Vorschaubild-Caching (`ThumbnailController`):**
  - **Privater / authentifizierter Kontext** (`private: true`-Volume, dynamisches `{user_id}`-Volume, Voter-geschütztes Profil, `Authorization`-Header oder Session-Cookie vorhanden): sendet `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization`, um ein Datenleck über geteilte CDNs zu verhindern.
  - **Öffentlicher Kontext:**
    - Mit Cache-Buster-Parameter `?v=<version>` → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 Tage).
    - Ohne `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 Stunde + `ETag` / `304 Not Modified`).
    - Wenn im Profil `cache.enabled: false` gesetzt ist → `Cache-Control: public, no-cache, must-revalidate`.
  - Jede Vorschaubild-Antwort setzt zusätzlich `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` und `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** In der `dev`-Umgebung zeigt das **FileManager**-Profiler-Panel das aktive Profil, das Volume, den Vorschaubild-Treiber sowie Cache-Hit/Miss-Statistiken an.

---

## 4. Globale Lokalisierung (`493` Schlüssel), BCP-47 / POSIX / RTL & eigene Sprachen

LiveUX FileManagerBundle wird standardmäßig mit **12 integrierten UI-Sprachen** ausgeliefert – **Englisch (`en` – garantierter Fallback), Türkisch (`tr`), Französisch (`fr`), Deutsch (`de`), Spanisch (`es`), Arabisch (`ar`), Italienisch (`it`), Portugiesisch (`pt`), Niederländisch (`nl`), Polnisch (`pl`), Japanisch (`ja`) und Chinesisch vereinfacht (`zh_CN`)** – mit vollständiger Abdeckung aller **493 Übersetzungsschlüssel**.

- **BCP-47- & POSIX-`.UTF-8`-Unterstützung:** `MessageTranslator` löst regionale und POSIX-Locale-Strings wie `tr_TR.UTF-8`, `de_DE@euro` und `zh-Hans-CN` automatisch auf (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` gibt gültige BCP-47-Codes für HTML-`lang`-Attribute aus.
- **Automatische Right-to-Left-(RTL-)Erkennung:** **15 RTL-Sprachen und -Schriften** – darunter Arabisch (`ar`), Persisch (`fa`), Hebräisch (`he`), Urdu (`ur`), Paschtu (`ps`) und Zentralkurdisch (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa` usw.) – werden automatisch erkannt und schalten das Komponenten-Layout auf `dir="rtl"` um.
- **Compile-Time-Allowlist für Kataloge:** `FileManagerBundle` akzeptiert ausschließlich Locales, für die ein echter Übersetzungskatalog existiert, und verhindert so Cache-/OPcache-Aufblähung (DoS) durch beliebige manipulierte Locale-Parameter.
- **Neue Sprache hinzufügen (z. B. Schwedisch `sv`) oder gezielte Überschreibungen:**
  Legen Sie einfach `translations/LiveUXFileManagerBundle.sv.yaml` in Ihrem Projekt an (oder überschreiben Sie einzelne Schlüssel in einer bestehenden Sprache wie `LiveUXFileManagerBundle.de.yaml`). Jeder ausgelassene Schlüssel fällt automatisch auf Englisch (`en`) zurück – Benutzer sehen niemals rohe Schlüssel wie `ui.upload`. Kopieren Sie die Datei `translations/LiveUXFileManagerBundle.en.yaml` des Bundles als vollständige Vorlage.

---

## 5. Häufig gestellte Fragen & Fehlerbehebung (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Prüfen Sie, ob `assets/app.js` den Import `import '@liveux/file-manager-bundle/js/file_manager_api.js';` enthält.
- Stellen Sie sicher, dass `defaults.auto_inject_dialog_host: true` aktiviert ist (falls Sie diese Option deaktiviert haben, binden Sie `DialogHost.html.twig` manuell in `base.html.twig` ein).
- Führen Sie `php bin/console cache:clear` aus und laden Sie den Browser ohne Cache neu (`Strg+Umschalt+R`).

### 2. Jede POST-Anfrage liefert `403 Invalid or missing CSRF token`
- Stellen Sie sicher, dass `symfony/security-csrf` installiert ist und sowohl `framework.csrf_protection: true` als auch `framework.session.enabled: true` aktiv sind.
- Wenn Sie eigene `fetch()`-Anfragen direkt absetzen, senden Sie den Header `X-LiveUX-FM-CSRF` mit.

### 3. Ich erhalte `403 Access denied`
- Prüfen Sie den Block `permissions` Ihres Profils in `liveux_file_manager.yaml`. Denken Sie daran: Selbst wenn eine Berechtigung auf `true` steht, ist ein authentifizierter Benutzer erforderlich, und `permissions.view` wird für alle weiteren Aktionen vorausgesetzt.
- Wenn Sie einen eigenen Symfony Voter implementiert haben, prüfen Sie `access_decision_manager.strategy` (z. B. `unanimous` vs. `affirmative`) in `config/packages/security.yaml`.

### 4. Uploads großer Dateien schlagen fehl (`upload_max_filesize`)
- Bei aktiviertem `chunk_upload.enabled: true` wird die Chunk-Größe automatisch auf `80%` der PHP-Werte `upload_max_filesize`/`post_max_size` begrenzt (z. B. `1.60 MB`-Chunks bei einem `2M`-PHP-Limit, bis zu 2.000 Chunks).
- Die maximal erlaubte Gesamtdateigröße wird über `upload_max_size` in `liveux_file_manager.yaml` gesteuert (z. B. `upload_max_size: '100M'` für 100-MB-Videos).

### 5. Das Herunterladen einer Datei zerstört die Seite (Symfony UX Turbo Drive)
- Alle integrierten Download-Links enthalten bereits `data-turbo="false" download`. Wenn Sie Twig-Templates überschreiben, achten Sie darauf, dass Ihre eigenen Download-`<a>`-Tags ebenfalls `data-turbo="false"` tragen.

### 6. Es werden keine Vorschaubilder erzeugt; es werden nur Originalbilder ausgeliefert
- Führen Sie `php bin/console file-manager:doctor` aus und prüfen Sie, ob in der Zeile `Thumbnail Driver` der Treiber `gd` oder `imagick` als aktiv angezeigt wird.
- Prüfen Sie, ob `thumbnail.cache_dir` (`public/media-cache`) für den Webserver-Benutzer beschreibbar ist.

### 7. `cURL error 60: SSL certificate problem` unter Windows bei Verbindung zu S3 / Supabase
- Laden Sie ein aktuelles `cacert.pem`-Bundle herunter und konfigurieren Sie `curl.cainfo` und `openssl.cafile` in `php.ini`, oder geben Sie am S3-Volume `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` an.

### 8. Frontend-Änderungen werden im Browser nicht übernommen
- **Entwicklung:** `php bin/console cache:clear` ausführen und Seite hart neu laden (`Strg+Umschalt+R`).
- **Produktion (AssetMapper):** `php bin/console asset-map:compile` ausführen.

---

[← Zurück: 07 — Cloud-Speicher & Dynamische Konfiguration](07-cloud-storage-and-dynamic-config.md) | [Übersicht](README.md) | [Weiter: 09 — Symfony-AI-Integration →](09-symfony-ai-integration.md)
