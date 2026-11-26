# 06 — Sicherheit & Autorisierung (RBAC & Defense-in-Depth)

[← Zurück: 05 — FormType- & Editor-Integrationen](05-formtype-and-editor-integrations.md) | [Übersicht](README.md) | [Weiter: 07 — Cloud-Speicher & Dynamische Konfiguration →](07-cloud-storage-and-dynamic-config.md)

Dateimanager gehören zu den risikoreichsten Angriffsflächen jeder Webanwendung. LiveUX FileManagerBundle setzt daher auf eine **Defense-in-Depth**-Architektur mit mehrstufigen Sicherheitsmechanismen, die standardmäßig aktiv sind.

> **Auf einen Blick: So sichern Sie den Dateimanager in 3 Schritten ab**  
> 1. **Routen absichern (`security.yaml`):** Verhindern Sie den Zugriff nicht authentifizierter Besucher auf die Dateimanager-Endpunkte.  
> 2. **Rollenberechtigungen festlegen (`permissions`):** Definieren Sie in jedem Profil explizit, wer anzeigen (`view`), hochladen (`upload`) und löschen (`delete`) darf.  
> 3. **Routen-`prefix` bei Multi-Firewall-Apps ergänzen:** Wenn Ihre Anwendung getrennte Firewalls für den Admin-Bereich (`/admin`) und reguläre Benutzer (`/`) nutzt, binden Sie die Bundle-Routen in `config/routes/liveux_file_manager.yaml` unter `prefix: /admin` ein, damit die Admin-Session korrekt aufgelöst wird und reguläre Mitglieder keinen Zugriff auf Admin-Dateien erhalten.

---

## 1. Routen in `security.yaml` schützen (Erster Schritt)

Schützen Sie in Produktivumgebungen stets alle Endpunkte unter `/_liveux-file-manager` hinter der Symfony-Firewall:

```yaml
# config/packages/security.yaml (Anwendungen mit einer einzelnen Firewall)
security:
    access_control:
        # Nur öffentlich belassen, wenn Sie signierte Freigabe-Links für externe Empfänger nutzen:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Wenn Sie zwei getrennte Firewalls einsetzen – z. B. einen Admin-Bereich (`/admin`) und reguläre Website-Mitglieder (`/`):**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Profilbasierte Autorisierung (`permissions` & `FileManagerVoter`)

In jedem Profil können Sie unabhängige Zugriffsregeln für **17 verschiedene Aktionen** definieren.

### Was bedeuten die Berechtigungswerte?
- **`true`:** **Nur authentifizierte Benutzer (`$token->getUser() !== null`)!** *(Gewährt NIEMALS Zugriff für anonyme Besucher.)*
- **`false`:** Niemand (die Aktion ist vollständig deaktiviert).
- **`'ROLE_ADMIN'`:** Nur Benutzer mit dieser konkreten Rolle.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Benutzer mit mindestens einer der aufgeführten Rollen.

### 4 goldene Autorisierungsregeln (automatisch durchgesetzt)
1. **`permissions.view` ist das Haupttor:** Fehlt einem Benutzer die `view`-Berechtigung, werden `upload`, `delete`, `download` sowie alle übrigen Aktionen automatisch verweigert.
2. **Fail-Closed (`FileManagerAuthorizer`):** Ist `security.voter_enabled: true` konfiguriert, aber `AuthorizationCheckerInterface` (also `symfony/security-bundle`) nicht installiert, **verweigert das Bundle sicher den Zugriff (`AccessDeniedException`)**, anstatt ungeschützt zu öffnen.
3. **Prüfung der übergeordneten Ordnerkette (`isPathAndAncestorsGranted`):** Greift ein Benutzer auf `confidential/2026/invoice.pdf` zu, werden Ihre benutzerdefinierten Voter automatisch nicht nur für `confidential/2026/invoice.pdf`, sondern auch für die Elternordner **`confidential`** und **`confidential/2026`** ausgewertet – so lässt sich ein gesperrter Überordner nicht durch Erraten von URLs umgehen.
4. **Doppelte Absicherung beim ZIP-Entpacken (`zip.allow_extract`):** Solange `zip.allow_extract: false` gesetzt ist, wird das Entpacken von Archiven serverseitig unabhängig von `permissions.extract_zip` abgelehnt.

### Vollständige Voter-Referenz aller 17 Berechtigungen

| YAML-Schlüssel | Voter-Konstante (`FileManagerPermissions::*`) | Geschützte Aktionen |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Verzeichnisauflistung, Ordnerbaum, Thumbnails, Inline-Vorschauen, Dialoge sowie **Voraussetzung für alle anderen Berechtigungen** |
| `upload` | `FILE_MANAGER_UPLOAD` | Datei-Uploads, Chunked-Uploads, Import per Remote-URL, Speichern aus dem Bildeditor |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Anlegen neuer Ordner |
| `rename` | `FILE_MANAGER_RENAME` | Umbenennen von Dateien und Ordnern |
| `clone` | `FILE_MANAGER_CLONE` | Klonen/Duplizieren von Dateien und Ordnern |
| `move` | `FILE_MANAGER_MOVE` | Verschieben von Elementen (Drag-and-Drop & Verschieben-Modal – Quell- und Zielpfad werden geprüft) |
| `copy` | `FILE_MANAGER_COPY` | Kopieren von Elementen (Quell- und Zielpfad werden geprüft) |
| `delete` | `FILE_MANAGER_DELETE` | Löschen von Elementen (Papierkorb / endgültiges Löschen) sowie `checkUsage`-Abfragen |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Wiederherstellen von Elementen aus dem Papierkorb |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Endgültiges Löschen einzelner Elemente oder Leeren des Papierkorbs |
| `download` | `FILE_MANAGER_DOWNLOAD` | Einzeldatei-Downloads, PDF-/Video-Streaming und Kopieren von URLs |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | ZIP-Archiv-Download mehrerer Elemente (wird für jeden ausgewählten Pfad einzeln geprüft) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Sicheres Entpacken von ZIP-Archiven (erfordert zusätzlich `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Erzeugen zeitlich begrenzter, HMAC-signierter Freigabe-Links (erfordert zusätzlich `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Leeren von Verzeichnis-, Profil- und Thumbnail-Caches sowie Cache-Bypässe über `?refresh=1` und `?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Ändern von Ordnerfarben/-Icons/-Pins sowie Datei-Alt-Texten, Titeln, Tags und Fokuspunkten |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | KI-gestützte Metadaten-Vorschläge (erfordert `view` + `edit_metadata` + `ai.enabled: true`) |

### Beispiel einer Enterprise-Profilkonfiguration

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **Berechtigungsbewusste Benutzeroberfläche:** Aktionsschaltflächen, für die ein Benutzer nicht berechtigt ist (etwa die Schaltfläche „Löschen“ für `ROLE_EDITOR`, wenn `delete: 'ROLE_ADMIN'` gesetzt ist), werden in der UI automatisch ausgeblendet **und** bei direktem API-Aufruf strikt mit `HTTP 403 Forbidden` abgewiesen.

---

## 3. Einen eigenen Symfony-Voter schreiben (Feingranulare Ordner- & Benutzerregeln)

Wenn YAML-Rollen nicht ausreichen – etwa für Regeln wie *„Benutzer dürfen den Ordner `locked/` weder löschen noch Dateien in `locked/` verschieben“* –, schreiben Sie einfach Ihren eigenen Symfony-Voter.

> [!WARNING]
> **Häufigster Stolperstein für Einsteiger: `affirmative` vs. `unanimous`!**  
> Symfonys Standardstrategie für `access_decision_manager.strategy` ist **`affirmative`** (sobald ein einzelner Voter `true` zurückgibt, wird der Zugriff gewährt). Gibt also der integrierte `FileManagerVoter` des Bundles für einen `ROLE_ADMIN`-Benutzer `true` zurück, wird das **`false` (Ablehnung)** Ihres eigenen Voters **ÜBERSCHRIEBEN**, und der Benutzer kann die Datei trotzdem löschen!  
>  
> Damit die **Verweigerungsregeln** Ihres eigenen Voters tatsächlich greifen, setzen Sie die Strategie in `config/packages/security.yaml` auf **`unanimous`** (jeder abstimmende Voter muss zustimmen; ein einziges `false` blockiert die Aktion):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(Das Bundle erkennt benutzerdefinierte Anwendungs-Voter automatisch über `DetectExternalVotersPass` und führt eine elementweise Voter-Filterung für den Ordnerbaum und die Dateizähler durch, um Datenschutz und Genauigkeit sicherzustellen.)*

### Eigenschaften von `FileManagerSubject`

Das `$subject`, das an die Methode `voteOnAttribute()` Ihres eigenen Voters übergeben wird, bietet 4 schreibgeschützte (`readonly`) Eigenschaften:

| Eigenschaft | Typ | Beschreibung | Beispiel |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Aktive Profilkonfiguration (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Relativer Pfad der Quell-Datei bzw. des Quell-Ordners ab Volume-Wurzel | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Name des aktiven Volumes | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Zielpfad** bei `MOVE`, `COPY`, `RENAME` oder `CLONE` (`null` bei allen anderen Aktionen) | `'locked/new-name.pdf'` |

### Beispiel für einen eigenen Voter (Ordner `locked/` gegen Löschen, Umbenennen & Verschieben sperren)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) Löschen, Verschieben oder Umbenennen von "locked" und allem darin verhindern
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Ebenso verhindern, dass Dateien IN den Ordner "locked" verschoben oder umbenannt werden
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 integrierte Defense-in-Depth-Schichten (Abgehärtet gegen reale Angriffe)

| # | Angriffsvektor | Schutzmechanismus im Bundle |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Jeder Schreib-, Lösch-, Upload-, ZIP- und Bildeditor-`POST`-Request validiert den Header `X-LiveUX-FM-CSRF`, **bevor** irgendeine Eingabe verarbeitet wird (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode- & OS-Tricks** | `PathNormalizer` blockiert `../`, `..\`, Null-Bytes (`\0`), Steuerzeichen, `C:\`, `phar://`-/`php://`-Stream-Wrapper, nachgestellte Punkte/Leerzeichen, Zero-Width-/Bidi-Unicode-Zeichen, CJK-Punkte (`。`), NTFS Alternate Data Streams (`file.txt:stream`) und Windows-Gerätenamen (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`) und erzwingt maximal **32 Ebenen Tiefe**, **255 Bytes pro Segment** sowie **1024 UTF-8-Zeichen / 3072 Bytes** Gesamtpfadlänge. |
| 3 | **Ausführbare, Polyglot- & Doppelendungs-Dateien** | `FileSecurityGuard` erzwingt **69 gesperrte Dateiendungen** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config` usw.), **47 gesperrte MIME-Typen**, Prüfungen auf doppelte Dateiendungen (`shell.php.jpg`) und eine **Magic-Bytes-Polyglot**-Validierung – strikt durchgesetzt bei Uploads, Umbenennungen (selbst bei `allow_extension_change: true`) und Downloads. |
| 4 | **Direktzugriff auf versteckte Ordner & Papierkorb** | Pfade unter `.trash`, `.env`, `.git`, `.htaccess` und `exclude_patterns` werden an allen Endpunkten abgewiesen; `ZipDownloadController` führt für **jeden ausgewählten Pfad** eine eigene `DOWNLOAD_ZIP`-Voter-Prüfung aus. |
| 5 | **SVG-XSS, XXE & `<use>`-DoS-Bomben** | `SvgSanitizer` weist `<!DOCTYPE`/`<!ENTITY` ab, erzwingt eine **strikte Allowlist aus 55 Tags**, begrenzt die SVG-Größe auf **5 MB**, filtert CSS-Syntax (`@import`, `expression()`, `url(javascript:)`), entfernt `<script>`/`<foreignObject>`/`on*`/`javascript:` und begrenzt `<use>`-Expansions-DoS (max. `100` `<use>`, Tiefe `4`, `1000` expandierte Knoten, `5000` Analyseschritte, `8` doppelte IDs). |
| 6 | **SSRF & DNS Rebinding (Remote-URL-Import)** | `RemoteUrlImporter` ist standardmäßig deaktiviert (`allow_remote_url_import: false`). Ist er aktiviert, prüft er IP-Adressen via `inet_pton()` gegen Loopback, private Bereiche, `0.0.0.0`, `100.64.0.0/10` CGNAT, Cloud-Metadaten (`169.254.169.254`), IPv4-mapped IPv6 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 sowie UTS-46-IDNA-/Full-Width-/Oktal-/Hex-IP-Umgehungen, während **`CURLOPT_RESOLVE`-IP-Pinning** DNS-Rebinding-Angriffe ausschließt. |
| 7 | **Bildeditor-Polyglot-RCE** | `ImageEditorController` beschränkt `overwrite` auf bestehende sichere Rastergrafiken (`jpg/jpeg/png/webp`) und erzwingt `upload_max_size`, profilbezogenes `upload_allow`/`upload_deny`, Usage-Guard-Prüfungen sowie eine echte Validierung mit `getimagesizefromstring()`. |
| 8 | **Dekompressions-(Pixel-)Bomben & ImageTragick** | `GdThumbnailDriver` und `ImagickThumbnailDriver` prüfen Abmessungen vor der Dekodierung in den Arbeitsspeicher mittels `getimagesizefromstring()` (max. `8192×8192` und `40 MP`) und wenden strenge Imagick-Limits für Speicher, Laufzeit und Threads an. |
| 9 | **Symlink-Ausbruch & Verzeichnis-Einschluss** | `ConfinedLocalFilesystemAdapter` verifiziert bei jedem lokalen Lesen, Schreiben, Verschieben, Kopieren und Löschen `is_link()` + `realpath()`-Root-Confinement auf jedem Pfadsegment; rekursives Löschen/Verschieben von Teilbäumen mit Symlinks wird abgewiesen, und Verzeichnisauflistungen erzwingen `SKIP_LINKS`. |
| 10 | **Mandanten-Kollisionsschutz (`{user_id}`)** | `DynamicPathResolver` isoliert Benutzerverzeichnisse mit eindeutigen SHA-256-Suffixen `<slug>--h-<16-hex>` bei E-Mail-Adressen, gemischter Groß-/Kleinschreibung, Unicode, reservierten Namen (`guest`, `user`) und Windows-Gerätenamen – Kollisionen zwischen zwei Benutzern sind ausgeschlossen. |
| 11 | **Download-, Thumbnail- & CDN-Cache-Isolation** | `DownloadController`, `ThumbnailController` und `ZipDownloadController` senden `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` und `Content-Security-Policy: default-src 'none'; sandbox`; erzwingen `Cache-Control: private` + `Vary: Cookie, Authorization` in privaten/Voter-/Session-/`{user_id}`-Kontexten; verlangen `CLEAR_CACHE` für `?refresh=1` und `?fallback=1`; und erzwingen `Content-Disposition: attachment` für HTML/JS/SVG/XML. |
| 12 | **FormType-POST- & Twig-Origin-Schutz** | `FileManagerPickerType` weist manipulierte serverseitige POST-Payloads ab (XSS-Protokolle, `../`, `.trash/`, JSON > 256 KB, > 500 Einträge, ungültiges UTF-8, Überschreitung von `max_files`, HPP-Arrays); `FileManagerTwigExtension` und die Frontend-Prüfung `isSafeUrl()` verhindern Origin-Spoofing über externe/gefährliche URLs. |
| 13 | **Schutz vor beliebigen Theme-/Icon-Pfaden & DOM-XSS** | Zur Laufzeit akzeptiert `dialog?theme=` ausschließlich die 9 integrierten Themes und `dialog?iconSet=` ausschließlich die 6 integrierten Icon-Sets; `sanitizeHexColor()` verhindert Farb-Injection; Chunk-Staging-Verzeichnisse sind pro Benutzer isoliert (`0750`). |
| 14 | **DoS-, Log-Injection- & Cache-Isolation** | Massenlöschungen sind auf 200 Elemente, Chunked-Uploads auf 2000 Chunks und rekursive Ordnerkopien auf 5000 Elemente begrenzt; `AuditLogListener` entfernt Log-Injection-Payloads (`\r`/`\n`/ANSI/Steuerzeichen); `DirectoryCacheManager` leert niemals den `cache.app`-Pool der Host-Anwendung (ruft niemals `clear()` auf). |

---

## 5. Webserver-Härtung (Apache & Nginx)

Als zusätzliche Schutzschicht sollten Sie die Ausführung von PHP und Skripten in öffentlich ausgelieferten Upload-Verzeichnissen wie `public/uploads` stets direkt auf Webserver-Ebene deaktivieren:

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. Signierte Freigabe-Links & Asset Usage Guard

- **Signierte, zeitlich begrenzte Freigabe-Links (`ShareController`):** Bei `share.enabled: true` werden temporäre URLs mit Symfonys `UriSigner` (HMAC + `expires`-Zeitstempel) signiert. Wird `share.enabled: false` in einem Profil gesetzt, werden alle bestehenden Links sofort mit `403 Forbidden` widerrufen; abgelaufene Links liefern `410 Gone`, und alle Antworten enthalten `Cache-Control: private, no-store` sowie eine strikte CSP-Härtung.
- **Asset Usage Guard (`AssetUsageCheckerInterface`):** Verhindert das Löschen, Umbenennen, Verschieben oder Überschreiben von Dateien, die noch aktiv in Ihrer Datenbank referenziert werden (siehe [10 — Asset Usage Guard](10-asset-usage-guard.md)).

---

## 7. Audit-Logging — Produktions-Setup & Beispiele

`AuditLogListener` protokolliert alle 9 Datei- und Ordner-Änderungs-Events mit strukturiertem PSR-3-Kontext (`action`, `profile`, `volume`, `path`, `user`, `ip` sowie event-spezifischen Metadaten) und bereinigt automatisch Steuer-, CRLF- und BiDi-Zeichen, um Log-Injection zu verhindern.

### Schritt 1: Audit-Logging aktivieren (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Pro Profil aktivieren oder überschreiben
        public_temp:
            security:
                audit_log: false
```

### Schritt 2: Audit-Einträge in ein dediziertes rotierendes JSON-Log leiten (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 90 Tage Aufbewahrung & Rotation
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### Beispiel einer strukturierten JSON-Ausgabe

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### Alle 9 protokollierten Lebenszyklus-Events

| Symfony-Event | `action`-Schlüssel | Zusätzliche Kontextfelder |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### Schritt 3 (Optional): Audit-Logs in einer Doctrine-Datenbanktabelle speichern

Um im Admin-Panel einen durchsuchbaren Audit-Verlauf anzuzeigen, abonnieren Sie dieselben Symfony-Events:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> Bei stark frequentierten Anwendungen empfiehlt es sich, statt eines direkten `$this->em->flush()`-Aufrufs eine asynchrone Symfony-**Messenger**-Nachricht zu dispatchen, damit Dateioperationen nicht blockieren.

---

[← Zurück: 05 — FormType- & Editor-Integrationen](05-formtype-and-editor-integrations.md) | [Übersicht](README.md) | [Weiter: 07 — Cloud-Speicher & Dynamische Konfiguration →](07-cloud-storage-and-dynamic-config.md)
