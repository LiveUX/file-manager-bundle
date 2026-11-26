# 08 — Events, CLI-commando's, Cache, Vertalingen & Probleemoplossing (FAQ)

[← Vorige: 07 — Cloudopslag & Dynamische Configuratie](07-cloud-storage-and-dynamic-config.md) | [Hoofdoverzicht](README.md) | [Volgende: 09 — Symfony AI-integratie →](09-symfony-ai-integration.md)

> [!TIP]
> **In één oogopslag: waar zijn events en CLI-commando's voor?**
> - **Events:** Informeren je Symfony-applicatie automatisch zodra een gebruiker een bestand uploadt, verwijdert, hernoemt of verplaatst — bijvoorbeeld: *"wanneer een bestand wordt hernoemd, werk dan automatisch het pad van de productafbeelding bij in de database."*
> - **CLI-commando's (`php bin/console file-manager:...`):** Ingebouwde terminaltools om statuscontroles uit te voeren (`doctor`), caches te wissen (`cache:clear`), miniaturen vooraf te genereren (`thumbs:warmup`) of verlopen prullenbakitems op te ruimen (`trash:purge`).

---

## 1. Symfony Events (9 domein-events)

Elk event van het bestandsbeheer erft over van de gemeenschappelijke basisklasse **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Luister naar deze events om je database synchroon te houden, auditlogs vast te leggen, CDN-caches te legen of achtergrondtaken te starten.

| Event-klasse (`LiveUX\FileManagerBundle\Event\*`) | Wordt verstuurd wanneer | Properties |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Een bestand wordt geüpload, geïmporteerd via URL, opgeslagen vanuit de afbeeldingseditor of uitgepakt uit een ZIP-archief | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Een bestand wordt verwijderd (verplaatst naar de prullenbak of definitief verwijderd) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Een map wordt aangemaakt (ook tijdens het uitpakken van een ZIP-archief) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Een map wordt verwijderd | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Een bestand of map wordt hernoemd | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Een item wordt verplaatst of gekopieerd (inclusief overdracht tussen volumes) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Een item wordt gedupliceerd/gekloond | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Een item wordt hersteld uit de prullenbak | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Een of meer items definitief uit de prullenbak worden verwijderd | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Voorbeeld van een Event Listener (`#[AsEventListener]`)

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
        $this->logger->info('Nieuw mediabestand geüpload.', [
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

## 2. Console- (CLI-)commando's (10 ingebouwde commando's)

| Commando | Opties / Aliassen | Beschrijving |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Controleert PHP-versie, `upload_max_filesize`, `post_max_size`, cURL CA Bundle, GD/Imagick, EXIF, WebP, `ext-zip` en elke profiel-/volumeconfiguratie. Voeg `--check-remote` toe om live verbindingstests uit te voeren tegen S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Wist map- en quotumcaches voor alle profielen of een specifiek profiel/volume/pad (`--volume`/`--path` vereisen het argument `profile`; wist nooit ongerelateerde sleutels in `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Aliassen: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Genereert afbeeldingsminiaturen vooraf zodat redacteuren nooit hoeven te wachten bij het eerste bezoek. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Wist gecachete miniaturen. `--orphans` verwijdert alleen miniaturen waarvan het bronbestand niet meer bestaat (kan niet worden gecombineerd met `--volume`/`--path`, en beschermt automatisch gedeelde `{user_id}`- / `{Y}/{m}/{d}`-mappen). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Optimaliseert bestaande afbeeldingen met terugwerkende kracht (veiligheids-/geheugenlimiet: max. `25 MB` per afbeelding). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Detecteert dubbele bestanden op basis van SHA-256-inhoudshashes en rapporteert terug te winnen schijfruimte. |
| `file-manager:stats [profile]` | `--json` | Rapporteert opslaggebruik, aantallen bestanden/mappen, quotumbenutting, miniaturen en prullenbakstatistieken. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(standaard `50`)*, `--dry-run` | Genereert in batches ontbrekende `alt`-teksten, titels, bijschriften en tags met AI. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = alles verwijderen)*, `--dry-run` | Verwijdert verlopen prullenbakitems definitief. Wanneer meerdere profielen een fysieke map delen, wordt automatisch de langste `retention_days`-waarde gerespecteerd. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Installeert de 6 AI Agent Skills van de bundle in `.agents/skills`, `.claude/skills`, `.cursor/skills` of `.github/skills`. |

> [!NOTE]
> **Bescherming van dynamische `{user_id}`- en `{Y}/{m}/{d}`-paden in CLI-commando's**
> Omdat er in CLI-modus geen ingelogde gebruikerssessie bestaat, slaan alle scan- en opschooncommando's (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) volumes met `{user_id}` **automatisch over** (waardoor er niet per ongeluk een lege map `guest/` wordt aangemaakt). Voor volumes met `{Y}/{m}/{d}`-datumplaceholders wordt alleen de huidige datumperiode gescand en wordt een informatieve melding getoond.

### Cron-voorbeeld voor automatisch legen van de prullenbak

```cron
# Verwijder verlopen prullenbakitems elke nacht om 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Prestaties, Cache-architectuur & Symfony Profiler

- **Cache-TTL in 3 lagen & Handmatig vernieuwen (`↻`):** De TTL voor mapoverzichten wordt in deze volgorde bepaald: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` schakelt caching uit voor dat volume of profiel). Klikken op **Vernieuwen (`↻`)** in de werkbalk of drukken op `R` verstuurt `?refresh=1` *(om de cache te omzeilen is de machtiging `CLEAR_CACHE` vereist)*.
- **Eigen cachepool & Profielfingerprinting:** Verwijs `defaults.cache.pool` naar een aparte Redis- of APCu-pool (als `<pool>.taggable` bestaat, wordt die automatisch gebruikt). Zonder taggable cache gebruikt `DirectoryCacheManager` generatietellers met een geldigheid van 30 dagen en roept nooit `$cachePool->clear()` aan op je algemene applicatiecache. Wanneer twee profielen dezelfde fysieke map delen maar verschillende regels hebben (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), blijven hun caches strikt gescheiden via `buildProfilePolicyFingerprint()`.
- **Kanonieke sleutelsynchronisatie:** Als twee profielen naar dezelfde fysieke map verwijzen, invalideert het uploaden of wijzigen van een bestand in het ene profiel automatisch ook de cache van het andere profiel.
- **Zelfherstellend `remember_last_dir`:** Als de laatst bezochte map van een gebruiker extern is verwijderd (via FTP of een S3-console), klimt het bestandsbeheer automatisch op naar de dichtstbijzijnde bestaande bovenliggende map of hoofdmap in plaats van een foutmelding te geven.
- **HTTP 304 & Contextbewuste miniatuurcaching (`ThumbnailController`):**
  - **Privé- / geauthenticeerde context** (`private: true`-volume, dynamisch `{user_id}`-volume, met Voter beveiligd profiel, aanwezige `Authorization`-header of sessiecookie): verstuurt `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` om lekken via gedeelde CDN's te voorkomen.
  - **Openbare context:**
    - Met `?v=<version>` cache-busterparameter → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 dagen).
    - Zonder `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 uur + `ETag` / `304 Not Modified`).
    - Wanneer `cache.enabled: false` op het profiel → `Cache-Control: public, no-cache, must-revalidate`.
  - Elke miniatuurrespons zet bovendien `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` en `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** In de `dev`-omgeving toont het **FileManager**-profilerpaneel het actieve profiel, volume, de miniatuurdriver en cache hit/miss-statistieken.

---

## 4. Internationale lokalisatie (`493` sleutels), BCP-47 / POSIX / RTL & Eigen talen

LiveUX FileManagerBundle wordt standaard geleverd met **12 ingebouwde UI-talen** — **Engels (`en` — gegarandeerde fallback), Turks (`tr`), Frans (`fr`), Duits (`de`), Spaans (`es`), Arabisch (`ar`), Italiaans (`it`), Portugees (`pt`), Nederlands (`nl`), Pools (`pl`), Japans (`ja`) en Vereenvoudigd Chinees (`zh_CN`)** — met volledige dekking over alle **493 vertaalsleutels**.

- **Ondersteuning voor BCP-47 & POSIX `.UTF-8`:** `MessageTranslator` lost regionale en POSIX-localestrings zoals `tr_TR.UTF-8`, `de_DE@euro` en `zh-Hans-CN` automatisch op (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` geeft geldige BCP-47-codes terug voor HTML `lang`-attributen.
- **Automatische detectie van rechts-naar-links (RTL):** **15 RTL-talen en -schriften** — waaronder Arabisch (`ar`), Perzisch (`fa`), Hebreeuws (`he`), Urdu (`ur`), Pasjtoe (`ps`) en Centraal-Koerdisch (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa`, enz.) — worden automatisch gedetecteerd, waarna de componentlay-out overschakelt op `dir="rtl"`.
- **Allowlist voor vertaalcatalogi tijdens compilatie:** `FileManagerBundle` accepteert uitsluitend locales waarvoor een echte vertaalcatalogus bestaat, zodat willekeurig verzonnen locale-parameters geen cache-/OPcache-vervuiling (DoS) kunnen veroorzaken.
- **Een nieuwe taal toevoegen (bijv. Zweeds `sv`) of gedeeltelijke overschrijvingen:**
  Maak eenvoudig `translations/LiveUXFileManagerBundle.sv.yaml` aan in je project (of overschrijf specifieke sleutels in een bestaande taal zoals `LiveUXFileManagerBundle.nl.yaml`). Elke weggelaten sleutel valt automatisch terug op het Engels (`en`) — gebruikers krijgen nooit ruwe vertaalsleutels zoals `ui.upload` te zien. Kopieer `translations/LiveUXFileManagerBundle.en.yaml` uit de bundle als volledig startsjabloon.

---

## 5. Veelgestelde vragen & Probleemoplossing (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Controleer of `assets/app.js` de regel `import '@liveux/file-manager-bundle/js/file_manager_api.js';` bevat.
- Controleer of `defaults.auto_inject_dialog_host: true` is ingeschakeld (als je dit hebt uitgezet, neem dan handmatig `DialogHost.html.twig` op in `base.html.twig`).
- Voer `php bin/console cache:clear` uit en doe een harde verversing in de browser (`Ctrl+Shift+R`).

### 2. Elk POST-verzoek geeft `403 Invalid or missing CSRF token`
- Zorg ervoor dat `symfony/security-csrf` is geïnstalleerd en dat zowel `framework.csrf_protection: true` als `framework.session.enabled: true` actief is.
- Als je zelf `fetch()`-verzoeken verstuurt, stuur dan de header `X-LiveUX-FM-CSRF` mee.

### 3. Ik krijg `403 Access denied`
- Controleer het blok `permissions` van je profiel in `liveux_file_manager.yaml`. Let op: zelfs wanneer een machtiging op `true` staat, is een ingelogde gebruiker vereist, en `permissions.view` is verplicht voor alle andere acties.
- Heb je een eigen Symfony Voter geïmplementeerd, controleer dan `access_decision_manager.strategy` (bijv. `unanimous` vs. `affirmative`) in `config/packages/security.yaml`.

### 4. Uploads van grote bestanden mislukken (`upload_max_filesize`)
- Met `chunk_upload.enabled: true` wordt de chunkgrootte automatisch begrensd op `80%` van PHP's `upload_max_filesize`/`post_max_size` (bijv. chunks van `1.60 MB` bij een PHP-limiet van `2M`, tot 2.000 chunks).
- De totale toegestane bestandsgrootte wordt geregeld via `upload_max_size` in `liveux_file_manager.yaml` (stel bijv. `upload_max_size: '100M'` in voor video's van 100 MB).

### 5. Een bestand downloaden breekt de pagina (Symfony UX Turbo Drive)
- Alle ingebouwde downloadlinks bevatten al `data-turbo="false" download`. Als je Twig-templates overschrijft, zorg er dan voor dat je eigen `<a>`-downloadlinks ook `data-turbo="false"` hebben.

### 6. Er worden geen miniaturen gegenereerd; alleen originele afbeeldingen worden geladen
- Voer `php bin/console file-manager:doctor` uit en controleer of de rij `Thumbnail Driver` aangeeft dat `gd` of `imagick` actief is.
- Controleer of `thumbnail.cache_dir` (`public/media-cache`) schrijfbaar is voor de webservergebruiker.

### 7. `cURL error 60: SSL certificate problem` op Windows bij verbinding met S3 / Supabase
- Download een actueel `cacert.pem`-bestand en configureer `curl.cainfo` en `openssl.cafile` in `php.ini`, of stel `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` in op het S3-volume.

### 8. Frontendwijzigingen zijn niet zichtbaar in de browser
- **Ontwikkeling:** Voer `php bin/console cache:clear` uit en vernieuw hard (`Ctrl+Shift+R`).
- **Productie (AssetMapper):** Voer `php bin/console asset-map:compile` uit.

---

[← Vorige: 07 — Cloudopslag & Dynamische Configuratie](07-cloud-storage-and-dynamic-config.md) | [Hoofdoverzicht](README.md) | [Volgende: 09 — Symfony AI-integratie →](09-symfony-ai-integration.md)
