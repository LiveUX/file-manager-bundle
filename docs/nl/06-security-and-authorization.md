# 06 — Beveiliging & Autorisatie (RBAC & Defense-in-Depth)

[← Vorige: 05 — FormType & Editor-integraties](05-formtype-and-editor-integrations.md) | [Overzicht](README.md) | [Volgende: 07 — Cloudopslag & Dynamische Configuratie →](07-cloud-storage-and-dynamic-config.md)

Bestandsbeheerders vormen een van de meest risicovolle aanvalsoppervlakken in elke webapplicatie. LiveUX FileManagerBundle is ontworpen volgens een **defense-in-depth**-architectuur met meerlaagse beveiligingsmaatregelen die standaard actief zijn.

> **In één oogopslag: beveiliging dichttimmeren in 3 stappen**  
> 1. **Beveilig routes (`security.yaml`):** Blokkeer niet-ingelogde bezoekers van alle endpoints van de bestandsbeheerder.  
> 2. **Definieer rolpermissies (`permissions`):** Leg per profiel expliciet vast wie mag bekijken (`view`), uploaden (`upload`) en verwijderen (`delete`).  
> 3. **Voeg een route-`prefix` toe bij meerdere firewalls:** Gebruikt je applicatie aparte firewalls voor een beheerpaneel (`/admin`) en reguliere gebruikers (`/`), monteer de bundle-routes dan onder `prefix: /admin` in `config/routes/liveux_file_manager.yaml`, zodat de admin-sessie correct wordt herkend en gewone leden geen toegang hebben tot adminbestanden.

---

## 1. Routes beveiligen in `security.yaml` (Eerste stap)

Scherm in productie altijd alle endpoints onder `/_liveux-file-manager` af achter de Symfony-firewall:

```yaml
# config/packages/security.yaml (Applicaties met één firewall)
security:
    access_control:
        # Alleen publiek laten als je gesigneerde deellinks gebruikt voor externe ontvangers:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Als je twee aparte firewalls hebt — bijvoorbeeld een beheeromgeving (`/admin`) en reguliere leden (`/`):**

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

## 2. Profielgebaseerde autorisatie (`permissions` & `FileManagerVoter`)

Binnen elk profiel kun je afzonderlijke toegangsregels instellen voor **17 verschillende acties**.

### Wat betekenen de permissiewaarden?
- **`true`:** **Uitsluitend ingelogde gebruikers (`$token->getUser() !== null`)!** *(Geeft NOOIT toegang aan anonieme bezoekers.)*
- **`false`:** Niemand (de actie is volledig uitgeschakeld).
- **`'ROLE_ADMIN'`:** Alleen gebruikers met deze specifieke rol.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Gebruikers met minstens één van de genoemde rollen.

### 4 gouden autorisatieregels (automatisch afgedwongen)
1. **`permissions.view` is de hoofdpoort:** Heeft een gebruiker geen `view`-rechten, dan worden `upload`, `delete`, `download` en alle andere bewerkingen automatisch geweigerd.
2. **Fail-Closed (`FileManagerAuthorizer`):** Wanneer `security.voter_enabled: true` is ingesteld, maar `AuthorizationCheckerInterface` (d.w.z. `symfony/security-bundle`) niet is geïnstalleerd, **weigert de bundle veilig de toegang (`AccessDeniedException`)** in plaats van open te blijven staan.
3. **Controle van bovenliggende mappenketen (`isPathAndAncestorsGranted`):** Wanneer een gebruiker `confidential/2026/invoice.pdf` opvraagt, worden jouw eigen Voters automatisch geëvalueerd voor zowel `confidential/2026/invoice.pdf` als voor de bovenliggende mappen **`confidential`** en **`confidential/2026`** — zo voorkom je dat het raden van URL's afgeschermde bovenliggende mappen omzeilt.
4. **Dubbel slot op ZIP-uitpakken (`zip.allow_extract`):** Zolang `zip.allow_extract: false` staat, wordt het uitpakken van archieven op de server geweigerd, ongeacht de waarde van `permissions.extract_zip`.

### Volledig overzicht van de 17 Voter-permissies

| YAML-sleutel | Voter-constante (`FileManagerPermissions::*`) | Beschermde acties |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Mapoverzicht, mappenboom, thumbnails, inline previews, dialoogvensters en **voorwaarde voor alle overige permissies** |
| `upload` | `FILE_MANAGER_UPLOAD` | Bestandsuploads, chunked uploads, import via externe URL, opslaan vanuit de afbeeldingseditor |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Nieuwe mappen aanmaken |
| `rename` | `FILE_MANAGER_RENAME` | Bestanden en mappen hernoemen |
| `clone` | `FILE_MANAGER_CLONE` | Bestanden en mappen klonen/dupliceren |
| `move` | `FILE_MANAGER_MOVE` | Items verplaatsen (drag-and-drop & Verplaatsen-modal — zowel bron- als doelpad worden gecontroleerd) |
| `copy` | `FILE_MANAGER_COPY` | Items kopiëren (zowel bron- als doelpad worden gecontroleerd) |
| `delete` | `FILE_MANAGER_DELETE` | Items verwijderen (prullenbak / definitief verwijderen) en `checkUsage`-controles |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Items terugzetten vanuit de prullenbak |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Items definitief verwijderen of de prullenbak legen |
| `download` | `FILE_MANAGER_DOWNLOAD` | Losse bestanden downloaden, PDF-/videostreaming en URL's kopiëren |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Meerdere items als ZIP-archief downloaden (afzonderlijk gecontroleerd voor elk geselecteerd pad) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Veilig ZIP-bestanden uitpakken (vereist ook `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Tijdelijke, HMAC-gesigneerde deellinks aanmaken (vereist ook `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Map-, profiel- en thumbnailcaches legen, evenals `?refresh=1`- en `?fallback=1`-cache-omzeilingen |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Mapkleuren/-iconen/-pins en alt-tekst, titels, tags en focuspunten van bestanden bewerken |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | AI-gestuurde metadatasuggesties (vereist `view` + `edit_metadata` + `ai.enabled: true`) |

### Voorbeeld van een enterprise-profielconfiguratie

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
> **UI-bewuste permissies:** Actieknoppen waarvoor een gebruiker geen rechten heeft (bijvoorbeeld de knop Verwijderen voor een `ROLE_EDITOR` wanneer `delete: 'ROLE_ADMIN'`) worden automatisch verborgen in de UI **én** strikt geblokkeerd met `HTTP 403 Forbidden` bij directe API-aanroepen.

---

## 3. Een eigen Symfony Voter schrijven (Fijnmazige map- & gebruikersregels)

Wanneer YAML-rollen niet toereikend zijn — bijvoorbeeld *"voorkom dat gebruikers de map `locked/` verwijderen of bestanden naar `locked/` verplaatsen"* — schrijf je een eigen Symfony Voter.

> [!WARNING]
> **Meest voorkomende valkuil voor beginners: `affirmative` vs. `unanimous`!**  
> De standaard `access_decision_manager.strategy` van Symfony is **`affirmative`** (één Voter die `true` teruggeeft, volstaat voor toegang). Als de ingebouwde `FileManagerVoter` dus `true` teruggeeft voor een gebruiker met `ROLE_ADMIN`, wordt het **`false`-oordeel (weigeren) van jouw eigen Voter OVERSCHREVEN** en kan de gebruiker het bestand toch verwijderen!  
>  
> Om ervoor te zorgen dat de **weigerregels** van je eigen Voter daadwerkelijk worden afgedwongen, stel je de strategie in op **`unanimous`** in `config/packages/security.yaml` (elke stemmende Voter moet akkoord gaan; één enkele `false` blokkeert de actie):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(De bundle detecteert eigen applicatie-Voters automatisch via `DetectExternalVotersPass` en voert per item Voter-filtering uit op de mappenboom en bestandstellers, zodat zowel privacy als tellers kloppen.)*

### Eigenschappen van `FileManagerSubject`

Het `$subject`-object dat aan de methode `voteOnAttribute()` van je eigen Voter wordt meegegeven, heeft 4 readonly-eigenschappen:

| Eigenschap | Type | Beschrijving | Voorbeeld |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Configuratie van het actieve profiel (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Relatief pad van het bronbestand of de bronmap vanaf de root van het volume | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Naam van het actieve volume | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Doelpad** bij `MOVE`-, `COPY`-, `RENAME`- of `CLONE`-acties (`null` bij alle andere acties) | `'locked/new-name.pdf'` |

### Voorbeeld van een eigen Voter (De map `locked/` vergrendelen tegen verwijderen, hernoemen & verplaatsen)

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
        // 1) Blokkeer verwijderen, verplaatsen of hernoemen van "locked" en alles erin
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Blokkeer ook het verplaatsen of hernoemen van bestanden NAAR de map "locked"
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

## 4. 14 ingebouwde defense-in-depth-lagen (Gehard tegen echte aanvallen)

| # | Aanvalsvector | Bescherming door de bundle |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Elke schrijf-, verwijder-, upload-, ZIP- en afbeeldingseditor-`POST`-aanvraag valideert eerst de `X-LiveUX-FM-CSRF`-header **voordat** enige invoer wordt verwerkt (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode & OS-trucs** | `PathNormalizer` blokkeert `../`, `..\`, null-bytes (`\0`), besturingstekens, `C:\`, `phar://`/`php://`-streamwrappers, afsluitende punten/spaties, zero-width/bidi Unicode-tekens, CJK-punten (`。`), NTFS Alternate Data Streams (`file.txt:stream`) en Windows-apparaatnamen (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), met een limiet van max. **32 niveaus diep**, **255 bytes/segment** en **1024 UTF-8-tekens / 3072 bytes** totale padlengte. |
| 3 | **Uitvoerbare, polyglot- & dubbele-extensiebestanden** | `FileSecurityGuard` blokkeert **69 verboden extensies** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, enz.), **47 verboden MIME-types**, dubbele extensies (`shell.php.jpg`) en voert **magic-bytes polyglot**-validatie uit — strikt afgedwongen bij uploads, hernoemen (zelfs met `allow_extension_change: true`) en downloads. |
| 4 | **Directe toegang tot verborgen mappen & prullenbak** | Paden onder `.trash`, `.env`, `.git`, `.htaccess` en `exclude_patterns` worden op alle endpoints geweigerd; `ZipDownloadController` voert een individuele `DOWNLOAD_ZIP` Voter-controle uit voor **elk geselecteerd pad**. |
| 5 | **SVG XSS, XXE & `<use>` DoS-bommen** | `SvgSanitizer` weigert `<!DOCTYPE`/`<!ENTITY`, hanteert een **strikte allowlist van 55 tags**, begrenst SVG's op **5 MB**, filtert CSS-syntax (`@import`, `expression()`, `url(javascript:)`), verwijdert `<script>`/`<foreignObject>`/`on*`/`javascript:` en begrenst `<use>`-expansie tegen DoS (max. `100` `<use>`, diepte `4`, `1000` uitgepakte nodes, `5000` analysestappen, `8` dubbele ID's). |
| 6 | **SSRF & DNS Rebinding (Import via externe URL)** | `RemoteUrlImporter` staat standaard uit (`allow_remote_url_import: false`). Indien ingeschakeld valideert hij IP's via `inet_pton()` om loopback, private bereiken, `0.0.0.0`, `100.64.0.0/10` CGNAT, cloud-metadata (`169.254.169.254`), IPv4-mapped IPv6 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 en UTS-46 IDNA / full-width / octale / hexadecimale IP-omzeilingen te blokkeren, terwijl **`CURLOPT_RESOLVE` IP-pinning** DNS-rebinding-aanvallen uitsluit. |
| 7 | **Afbeeldingseditor Polyglot RCE** | `ImageEditorController` beperkt `overwrite` tot bestaande veilige rasterbestanden (`jpg/jpeg/png/webp`) en dwingt `upload_max_size`, profielregels `upload_allow`/`upload_deny`, Usage Guard-controles en echte `getimagesizefromstring()`-validatie af. |
| 8 | **Decompressie- (pixel)bommen & ImageTragick** | `GdThumbnailDriver` en `ImagickThumbnailDriver` controleren afmetingen via `getimagesizefromstring()` voordat ze in het geheugen decoderen (max. `8192×8192` en `40 MP`) en passen strikte Imagick-limieten toe voor geheugen, tijd en threads. |
| 9 | **Symlink-ontsnapping & padbegrenzing** | `ConfinedLocalFilesystemAdapter` verifieert bij elke lokale lees-, schrijf-, verplaats-, kopieer- en verwijderactie op elk padsegment `is_link()` + `realpath()` binnen de root; recursief verwijderen/verplaatsen van submappen met symlinks wordt geweigerd en mapoverzichten dwingen `SKIP_LINKS` af. |
| 10 | **Multi-tenant (`{user_id}`) botsingspreventie** | `DynamicPathResolver` isoleert gebruikersmappen met unieke SHA-256 `<slug>--h-<16-hex>`-achtervoegsels voor e-mailadressen, hoofd-/kleine letters, Unicode, gereserveerde namen (`guest`, `user`) en Windows-apparaatnamen — waardoor mapbotsingen tussen twee gebruikers onmogelijk zijn. |
| 11 | **Isolatie van download-, thumbnail- & CDN-cache** | `DownloadController`, `ThumbnailController` en `ZipDownloadController` sturen `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` en `Content-Security-Policy: default-src 'none'; sandbox` mee; dwingen `Cache-Control: private` + `Vary: Cookie, Authorization` af in private/Voter/sessie/`{user_id}`-contexten; vereisen `CLEAR_CACHE` voor `?refresh=1` en `?fallback=1`; en forceren `Content-Disposition: attachment` voor HTML/JS/SVG/XML. |
| 12 | **FormType POST- & Twig origin-bescherming** | `FileManagerPickerType` weigert gemanipuleerde server-side POST-payloads (XSS-protocollen, `../`, `.trash/`, JSON > 256 KB, > 500 items, ongeldige UTF-8, overschrijding van `max_files`, HPP-arrays); `FileManagerTwigExtension` en frontend `isSafeUrl()` voorkomen spoofing met externe of gevaarlijke URL-origins. |
| 13 | **Willekeurige thema-/iconenmappen & DOM XSS** | Runtime `dialog?theme=` accepteert alleen de 9 ingebouwde thema's en `dialog?iconSet=` alleen de 6 ingebouwde iconensets; `sanitizeHexColor()` voorkomt kleurinjectie; stagingmappen voor chunks zijn per gebruiker geïsoleerd (`0750`). |
| 14 | **DoS, log-injectie & cache-isolatie** | Bulkverwijdering is begrensd op 200 items, chunked uploads op 2000 chunks en recursief mappen kopiëren op 5000 items; `AuditLogListener` verwijdert log-injectiepayloads (`\r`/`\n`/ANSI/besturingstekens); `DirectoryCacheManager` wist nooit de `cache.app`-pool van de hostapplicatie (roept nooit `clear()` aan). |

---

## 5. Webserver-hardening (Apache & Nginx)

Schakel als extra verdedigingslaag op webserverniveau altijd de uitvoering van PHP en scripts uit in publiek bereikbare uploadmappen zoals `public/uploads`:

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

## 6. Gesigneerde deellinks & Asset Usage Guard

- **Gesigneerde, tijdelijke deellinks (`ShareController`):** Wanneer `share.enabled: true`, worden tijdelijke URL's ondertekend met Symfony's `UriSigner` (HMAC + `expires`-tijdstempel). Zodra je `share.enabled: false` instelt op een profiel, worden alle bestaande links direct ingetrokken met `403 Forbidden`, verlopen links geven `410 Gone` terug en alle antwoorden bevatten `Cache-Control: private, no-store` plus strikte CSP-hardening.
- **Asset Usage Guard (`AssetUsageCheckerInterface`):** Voorkomt dat bestanden die actief in je database worden gebruikt, per ongeluk worden verwijderd, hernoemd, verplaatst of overschreven (zie [10 — Asset Usage Guard](10-asset-usage-guard.md)).

---

## 7. Auditlogging — Productieconfiguratie & Voorbeelden

`AuditLogListener` registreert alle 9 mutatiegebeurtenissen van bestanden en mappen met gestructureerde PSR-3-context (`action`, `profile`, `volume`, `path`, `user`, `ip`, plus gebeurtenisspecifieke metadata) en schoont automatisch stuurtekens, CRLF- en BiDi-tekens op om log-injectie te voorkomen.

### Stap 1: Auditlogging inschakelen (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Inschakelen of per profiel overschrijven
        public_temp:
            security:
                audit_log: false
```

### Stap 2: Auditregels routeren naar een eigen roterend JSON-logbestand (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 90 dagen bewaartermijn & rotatie
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

### Voorbeeld van gestructureerde JSON-uitvoer

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

### Alle 9 gelogde levenscyclusgebeurtenissen

| Symfony Event | `action`-sleutel | Extra contextvelden |
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

### Stap 3 (Optioneel): Auditlogs opslaan in een Doctrine-databasetabel

Om een doorzoekbaar auditspoor in je beheerpaneel aan te bieden, kun je je abonneren op dezelfde Symfony-events:

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
> Verstuur bij drukbezochte applicaties een asynchroon Symfony **Messenger**-bericht in plaats van `$this->em->flush()` direct aan te roepen, zodat bestandsbewerkingen niet-blokkerend blijven.

---

[← Vorige: 05 — FormType & Editor-integraties](05-formtype-and-editor-integrations.md) | [Overzicht](README.md) | [Volgende: 07 — Cloudopslag & Dynamische Configuratie →](07-cloud-storage-and-dynamic-config.md)
