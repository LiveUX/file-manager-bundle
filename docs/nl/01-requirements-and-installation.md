# 01 — Vereisten, afhankelijkheden & installatie

[← Documentatie-overzicht](README.md) | [Volgende: 02 — Configuratie & profielen →](02-configuration-and-profiles.md)

Deze gids neemt je stap voor stap mee door de installatie van LiveUX FileManagerBundle — met heldere uitleg bij **elke stap en het waarom erachter**, ook als dit je allereerste Symfony UX-pakket is.

> [!NOTE]
> **In gewone taal: wat doet deze bundle?**  
> De bundle voegt een volwaardig bestandsbeheer (vergelijkbaar met Google Drive of Windows Verkenner) toe aan je Symfony-applicatie — of dat nu een beheerpaneel, webshop of klantportaal is. Gebruikers kunnen met één klik **bestanden uploaden, mappen ordenen, afbeeldingen bijsnijden, voorbeelden van PDF's en video's bekijken en bestanden direct vanuit formulieren kiezen**.  
> Je hebt **geen CSS-framework** (Bootstrap, Tailwind, enz.) en **geen jQuery** nodig, en met Symfony AssetMapper ben je binnen 5 minuten live zonder Node.js of npm.

---

## 1. Systeemvereisten

Controleer vóór de installatie of je lokale machine of server aan deze minimumvereisten voldoet:

| Onderdeel | Minimale versie | Waarom nodig | Controlecommando |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` of hoger | Maakt gebruik van PHP 8.3/8.4-functies (`readonly`-klassen, getypeerde klasseconstanten). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` of `8.x` | Volledig getest en ondersteund op Symfony 6.4 LTS, 7.2, 7.3, 7.4 en 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Pakketinstallatie en PSR-4-autoloading. | `composer --version` |
| **Browser** | Moderne evergreen-browser | Native HTML5 `<dialog>` Top Layer, ES Modules en CSS custom properties (Chrome, Edge, Firefox, Safari). | — |

---

## 2. PHP-extensies

PHP-extensies bieden PHP extra native mogelijkheden zoals bestandsinspectie, XML-parsing en afbeeldingsverwerking.

### Vereiste PHP-extensies
Standaard ingeschakeld in vrijwel elke moderne PHP-installatie:

| Extensie | Wat het doet | Praktijkvoorbeeld |
| :--- | :--- | :--- |
| `ext-fileinfo` | Inspecteert de **werkelijke bestandsinhoud (magic bytes)**, niet alleen de extensie. | Als een aanvaller `shell.php` hernoemt naar `photo.jpg` en probeert te uploaden, onderschept en blokkeert `ext-fileinfo` dit direct. |
| `ext-dom` & `ext-libxml` | Parset geüploade `.svg` XML-structuren op een veilige manier (`SvgSanitizer`). | Verwijdert kwaadaardige `<script>`-tags of XXE-payloads uit een geüpload SVG-logo terwijl de schone vectorafbeelding behouden blijft. |

*(Opmerking: `ext-json` zit sinds PHP 8.0 ingebouwd in PHP-core en hoeft niet apart geïnstalleerd te worden.)*

### Optionele (aanbevolen) PHP-extensies
Als een van deze extensies ontbreekt, **blijft het bestandsbeheer gewoon werken** en valt het netjes terug op een alternatief:

| Extensie | Wat het doet | Fallback-gedrag bij ontbreken |
| :--- | :--- | :--- |
| `ext-gd` **of** `ext-imagick` | Genereert snelle WebP-/JPEG-miniaturen en voert automatische afbeeldingsoptimalisatie uit tijdens het uploaden. | Valt terug op de originele afbeelding of een bestandspictogram op basis van `thumbnail.on_missing_driver`. |
| `ext-exif` | Leest EXIF-oriëntatiemetadata van smartphones bij gebruik van `ext-gd`, zodat gekantelde foto's automatisch recht worden gezet (`auto_orient: true`). | Foto's worden opgeslagen in hun oorspronkelijke oriëntatie (niet nodig als `ext-imagick` is geïnstalleerd). |
| `ext-zip` (`ZipArchive`) | Maakt het downloaden van meerdere bestanden als `.zip` **én** het veilig uitpakken van archieven (`zip.allow_extract: true`) mogelijk. | De knoppen **Downloaden als ZIP** en **Archief uitpakken** worden automatisch verborgen. |

Controleer met één commando welke extensies op jouw systeem actief zijn:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Vereiste & optionele Composer-pakketten

Wanneer je `composer require liveux/file-manager-bundle` uitvoert, worden **alle vereiste pakketten automatisch geïnstalleerd**. Weten wat elke afhankelijkheid doet maakt debuggen veel eenvoudiger:

### Vereiste pakketten (automatisch geïnstalleerd)
| Pakket | Versiebeperking | Doel |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Uniforme bestandssysteemabstractie voor lokale schijven, AWS S3, Cloudflare R2, Supabase, MinIO en SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Rendert het bestandsbeheer via `{{ component('LiveUX:FileManager:Browser') }}` met 0 ms SSR. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Verzorgt client-side mapcaching, drag-and-drop, contextmenu's, Lightbox en formulierkiezers. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Kern-runtime van Symfony, routing en cachepools (`cache.app`). |
| `symfony/config` & `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Schemavalidatie van `liveux_file_manager.yaml` en servicecontainer-koppeling. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Verstuurt 9 domeinevents (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent`, enz.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Beveiligde bestandsdownloads, HTTP 206-videostreaming (`Range`), ETags en HTTP-cacheheaders. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Twig-templates, pictogram-/vertaalhelpers en automatische FormType-theming. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Formulierveld `FileManagerPickerType` voor enkelvoudige bestanden en meervoudige galerijen. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Server-side MIME-typedetectie voor geüploade bestanden. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Translitereert meertalige bestandsnamen (Nederlands, Turks, Frans, Arabisch, Cyrillisch, enz.) naar schone SEO-slugs. |
| `symfony/security-core` & `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | Voter-RBAC met 17 rechten en `X-LiveUX-FM-CSRF`-bescherming. |
| `symfony/cache-contracts` & `symfony/translation-contracts` | `^3.5` | Contracten voor mapcaching en meertalige UI. |

### Optionele pakketten (alleen installeren wanneer nodig)
| Pakket | Installatiecommando | Wanneer je het nodig hebt |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Zero-build levering van JS en CSS zonder Node.js of npm *(aanbevolen)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Rolgebaseerde rechten (`ROLE_USER` / `ROLE_ADMIN`) en `{user_id}`-isolatie per gebruiker. |
| `symfony/translation` | `composer require symfony/translation` | Koppelt met Symfony's Translator voor alle **12 ingebouwde UI-talen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) of eigen applicatietalen. |
| `symfony/console` & `symfony/yaml` | `composer require symfony/console symfony/yaml` | CLI-commando's (`file-manager:doctor`, `thumbs:warmup`, `trash:purge`, enz.) en YAML-configuratiebestanden. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Koppelen met AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO of Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Multi-server / load-balanced omgevingen met `defaults.metadata.driver: 'doctrine'` (zie [Hoofdstuk 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Ingebouwd `FileManagerField` voor EasyAdmin 4/5-dashboards (zie [Hoofdstuk 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ Voorstellen met AI** voor automatische alt-tekst, bijschriften en tags (zie [Hoofdstuk 09](09-symfony-ai-integration.md)). |

---

## 4. Stapsgewijze installatie

Volg deze 7 stappen op volgorde om een werkend bestandsbeheer op te zetten.

### Stap 1: Installeer de bundle

**Standaardinstallatie (via Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Lokale pad-repository (als je ontwikkelt binnen `bundles/liveux-file-manager-bundle`):**  
Voeg een `repositories`-vermelding toe aan je root `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Installeer daarna het lokale pakket:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Stap 2: Controleer `config/bundles.php`

Symfony Flex registreert de bundle meestal automatisch. Controleer voor de zekerheid of deze regels aanwezig zijn in `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Bij gebruik van authenticatie/rollen
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **registreert automatisch** zijn eigen Twig-namespace (`@LiveUXFileManager`), AssetMapper-pad (`@liveux/file-manager-bundle`) en FormType-thema (`@LiveUXFileManager/form/picker.html.twig`) tijdens het opstarten. Je hoeft `twig.yaml` of `asset_mapper.yaml` niet handmatig aan te passen.

---

### Stap 3: Importeer de routes

Maak `config/routes/liveux_file_manager.yaml` aan zodat de AJAX-API, download-, miniatuur- en upload-endpoints van de bundle actief worden:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Deze ene import registreert **12 beveiligde endpoints**:

| Methode & pad | Doel |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Laadt de HTML van het bestandsbeheer in een Modal- of OffCanvas-dialoogvenster. |
| `GET /_liveux-file-manager/api/list` | Retourneert een JSON-overzicht van bestanden en submappen voor een map. |
| `GET /_liveux-file-manager/api/tree` | Haalt de volledige mappenstructuur voor de zijbalk op in één verzoek. |
| `GET /_liveux-file-manager/api/trash` | Toont soft-deleted items in de prullenbak. |
| `GET /_liveux-file-manager/api/preview-text` | Retourneert een veilige tekst-/codepreview (begrensd op 64 KB) voor `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, enz. |
| `POST /_liveux-file-manager/api/action` | Voert mutaties uit: map aanmaken, verplaatsen, kopiëren, naam wijzigen, verwijderen, herstellen/definitief verwijderen uit prullenbak, mapkleuren/-pictogrammen, metadata, AI-verrijking, importeren via URL en archief uitpakken. |
| `POST /_liveux-file-manager/upload` | Verwerkt zowel standaard uploads als uploads in delen (chunked uploads). |
| `POST /_liveux-file-manager/image-edit` | Slaat bijgesneden, verkleinde of gedraaide afbeeldingen op vanuit de ingebouwde Canvas-afbeeldingseditor. |
| `GET /_liveux-file-manager/download` | Levert beveiligde downloads, inline PDF-/videovoorbeelden (met `Range`-ondersteuning) en `private: true`-volumes. |
| `GET /_liveux-file-manager/share` | Levert tijdelijke, HMAC-ondertekende deellinks. |
| `POST /_liveux-file-manager/zip` | Streamt geselecteerde bestanden en mappen als één `.zip`-archief. |
| `GET /_liveux-file-manager/thumb` | Genereert en levert WebP-/JPEG-/PNG-miniaturen met ETag en contextbewuste HTTP-cacheheaders. |

---

### Stap 4: Maak `config/packages/liveux_file_manager.yaml` aan

Maak `config/packages/liveux_file_manager.yaml` aan. De onderstaande startconfiguratie benoemt expliciet **alle 17 granulaire rechten**:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = neemt automatisch de actieve Symfony-locale over (valt altijd terug op 'en')
                            # Of zet een specifieke taal vast, bijv. 'nl', 'en', 'tr', 'fr', 'de', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'Mijn bestanden'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # ZIP uitpakken staat standaard uit (vereist ook zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Belangrijke beveiligingsopmerking (`permissions`):**  
> Elke sleutel die je onder `permissions` **weglaat** (behalve `extract_zip`) krijgt standaard de waarde `true`. Hier betekent `true` **"elke ingelogde gebruiker"** — NIET anonieme bezoekers. Als alleen `ROLE_ADMIN` de prullenbak definitief mag legen (`trash_purge`) of deellinks mag aanmaken (`share`), stel die sleutels dan expliciet in op `'ROLE_ADMIN'`.

> [!TIP]
> **Lokaal testen zonder inlogsysteem?**  
> Tijdens lokaal prototypen zonder authenticatie kun je tijdelijk `profiles.default.security.voter_enabled: false` instellen. **Laat `voter_enabled: false` NOOIT aanstaan in productie** — zie [06 — Beveiliging & autorisatie](06-security-and-authorization.md).

> [!NOTE]
> **12 ingebouwde UI-talen & automatische RTL (`defaults.locale: null`):**  
> Laat je `locale: null` staan, dan volgt het bestandsbeheer automatisch de actieve request-locale van Symfony over alle **12 ingebouwde UI-talen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), schakelt automatisch **RTL (`dir="rtl"`)** in voor Arabisch en andere RTL-talen (Perzisch, Hebreeuws, Urdu, enz.), normaliseert BCP-47- en POSIX-locales (`nl_NL.UTF-8`, `zh-Hans-CN`) en valt netjes terug op **Engels (`en`)** bij niet-ondersteunde locales.

---

### Stap 5: Koppel CSS & JavaScript (zero-config!)

**Weet je niet zeker of jouw project AssetMapper of Webpack Encore gebruikt?**  
Kijk in de hoofdmap van je project:
- Zie je `importmap.php` → volg **Optie A (AssetMapper)**.
- Zie je `webpack.config.js` → volg **Optie B (Webpack Encore)**.

> [!TIP]
> **Zero-config `autoimport`-ondersteuning:**  
> LiveUX FileManagerBundle declareert **`autoimport`** in zijn `assets/package.json` voor `@liveux/file-manager-bundle/styles/core.css` en de eager `dialog_controller.js` (die `file_manager_api.js` laadt). Zodra `assets/controllers.json` is ingeschakeld, **heb je geen enkele handmatige `import`-regel nodig in `assets/app.js`!**

#### Optie A: Symfony AssetMapper (aanbevolen — geen Node.js / npm nodig)

1. Controleer of de controllers van de bundle zijn ingeschakeld in `assets/controllers.json` (Symfony Flex voegt dit automatisch toe; zo niet, voeg het zelf toe):
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Symfony 6.4 / 7.2+ / 8.x-projecten roepen `startStimulusApp()` al aan in `assets/bootstrap.js` of `assets/stimulus_bootstrap.js`, waardoor `controllers.json` automatisch wordt ingelezen:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) of assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Zorg ervoor dat je basislayout (`templates/base.html.twig`) `importmap('app')` bevat:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Optioneel — bij deployment naar productie)* Compileer statische assets:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Vooraf geminificeerde productie-assets (`assets/dist/`):**  
> Omdat AssetMapper JS/CSS standaard niet minificeert, levert LiveUX FileManagerBundle vooraf met `esbuild` geminificeerde JavaScript-modules en `core.css` mee onder `assets/dist/` (`747 KB → 410 KB` geminificeerd; `~93 KB` via Gzip, of **`~77 KB`** via Brotli). `FileManagerBundle` koppelt `assets/dist/` automatisch aan AssetMapper, waardoor **noch je ontwikkelmachine noch je productieserver Node.js of npm nodig heeft**.  
> Op Symfony 7.3+ / 8.x kun je bovendien `precompress: true` inschakelen in `config/packages/asset_mapper.yaml` om tijdens `asset-map:compile` automatisch `.br`- en `.gz`-bestanden te genereren.

#### Optie B: Webpack Encore

1. Zorg er bij Webpack Encore-projecten (inclusief Symfony 8 + StimulusBundle 3.x) voor dat `@symfony/stimulus-bridge` is geïnstalleerd:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Controleer de 3 Stimulus-controllers en het `autoimport`-blok in `assets/controllers.json`:
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. Controleer of `startStimulusApp` is gekoppeld in `assets/bootstrap.js` (`assets/app.js` importeert dit — geen handmatige CSS/JS-imports nodig):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Bouw je assets:
```bash
npm run build
# Of continu meebouwen tijdens ontwikkeling: npm run watch
```

---

### Stap 6: `DialogHost` (automatische injectie — geen extra HTML nodig!)

Om vanaf elke pagina modals, OffCanvas-panelen, `FileManagerPickerType` of TinyMCE/CKEditor/Tiptap-kiezers te kunnen openen, is er een `<dialog>`-container nodig in de DOM.

De `AutoInjectDialogHostSubscriber` van de bundle injecteert deze host automatisch vlak voor `</body>` op volledige HTML-responses (`defaults.auto_inject_dialog_host: true`) — je hoeft `base.html.twig` dus niet aan te passen.

- **Slimme detectie:** Raakt nooit JSON-, AJAX-, Turbo Frame-, redirect- of binaire downloadresponses aan en slaat injectie over als er al handmatig een `DialogHost` aanwezig is.
- **0 ms overhead bij paginalading:** Haalt geen mappen of mappenstructuren op tijdens het initieel laden van de pagina; gegevens worden pas geladen wanneer een gebruiker het venster opent.
- **Liever handmatig plaatsen op specifieke pagina's?** Zet `defaults.auto_inject_dialog_host: false` en voeg de snippet vlak voor `</body>` toe in de gewenste layouts:

```twig
{# Optioneel — alleen nodig bij auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Stap 7: Controleer je installatie (`file-manager:doctor`)

Leeg de cache en voer het ingebouwde diagnosecommando uit:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Als je cloudvolumes hebt geconfigureerd (S3, R2, Supabase, MinIO), voeg dan `--check-remote` toe om live de verbinding te testen:
```bash
php bin/console file-manager:doctor --check-remote
```

Voorbeeld van gezonde uitvoer (9 omgevingscontroles + profiel-/volumestatustabel):
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Windows-gebruikers (`cURL error 60`):** Als `cURL CA Bundle` een waarschuwing geeft, verwijs dan `curl.cainfo` en `openssl.cafile` in `php.ini` naar een actuele `cacert.pem`, of configureer `driver_options.ca_bundle` op je S3-volume.
>
> **Linux-bestandsrechten:** Zorg dat `public/uploads`, `public/media-cache` en `var/file-manager` schrijfbaar zijn voor je webservergebruiker (`www-data`, `nginx`, enz.).
>
> **Gebruik je een AI-codeerassistent (Antigravity, Claude Code, Cursor, Copilot)?**  
> Leer je assistent met één commando alle conventies en recepten van deze bundle (zie [Hoofdstuk 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Bouw je eerste werkende pagina

Laten we een eenvoudige Symfony Controller en Twig-template maken om alles van begin tot eind te testen.

### 1) Controller (`src/Controller/MediaController.php`)
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Twig-template (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Mediabibliotheek</h1>

    {# Optie 1: Inline bestandsbeheer #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Optie 2: Open een Modal via een knop en vang de geselecteerde bestanden op #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Bestanden kiezen in Modal</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // Gebruiker heeft het venster gesloten zonder iets te selecteren
            }

            document.getElementById('selection-result').textContent =
                'Geselecteerd: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Open `/admin/media` in je browser. Gefeliciteerd — je bestandsbeheer is live!

---

## 6. Wat nu?

- Configureer eigen profielen, uploadlimieten en afbeeldingsregels → **[02 — Configuratie & profielen](02-configuration-and-profiles.md)**
- Voeg een afbeeldings- of galerijkiezer toe aan Symfony Forms → **[05 — FormType & editor-integraties](05-formtype-and-editor-integrations.md)**
- Scherper afstellen van RBAC en beveiliging vóór productie → **[06 — Beveiliging & autorisatie](06-security-and-authorization.md)**

---

[← Documentatie-overzicht](README.md) | [Volgende: 02 — Configuratie & profielen →](02-configuration-and-profiles.md)
