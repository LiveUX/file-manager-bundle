# 01 — Voraussetzungen, Abhängigkeiten & Installation

[← Dokumentationsübersicht](README.md) | [Weiter: 02 — Konfiguration & Profile →](02-configuration-and-profiles.md)

Dieser Leitfaden führt Sie von Grund auf durch die Installation des LiveUX FileManagerBundle — und erklärt **jeden Schritt sowie dessen Hintergrund**, auch wenn dies Ihr allererstes Symfony-UX-Paket ist.

> [!NOTE]
> **Kurz erklärt: Was leistet dieses Bundle?**  
> Es erweitert Ihre Symfony-Anwendung — ob Admin-Panel, Online-Shop oder Kundenportal — um einen vollwertigen Dateimanager (ähnlich Google Drive oder dem Windows-Explorer). Benutzer können **Dateien hochladen, Ordner organisieren, Bilder zuschneiden, PDFs und Videos in der Vorschau ansehen sowie Dateien direkt aus Formularen auswählen** — mit einem einzigen Klick.  
> Es benötigt **weder ein CSS-Framework** (Bootstrap, Tailwind usw.) **noch jQuery** und ist mit Symfony AssetMapper in 5 Minuten ohne Node.js oder npm einsatzbereit.

---

## 1. Systemvoraussetzungen

Prüfen Sie vor der Installation, ob Ihr lokaler Entwicklungsrechner oder Ihr Server diese Mindestanforderungen erfüllt:

| Komponente | Mindestversion | Wofür benötigt | Prüfbefehl |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` oder höher | Nutzt PHP-8.3/8.4-Funktionen (`readonly`-Klassen, typisierte Klassenkonstanten). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` oder `8.x` | Vollständig getestet und unterstützt unter Symfony 6.4 LTS, 7.2, 7.3, 7.4 und 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Paketinstallation und PSR-4-Autoloading. | `composer --version` |
| **Browser** | Moderner Evergreen-Browser | Natives HTML5-`<dialog>`-Top-Layer, ES-Module und CSS Custom Properties (Chrome, Edge, Firefox, Safari). | — |

---

## 2. PHP-Erweiterungen

PHP-Erweiterungen stellen native Zusatzfunktionen wie Dateiinhaltsprüfung, XML-Parsing und Bildverarbeitung bereit.

### Erforderliche PHP-Erweiterungen
In nahezu jeder modernen PHP-Installation standardmäßig aktiviert:

| Erweiterung | Aufgabe | Praxisbeispiel |
| :--- | :--- | :--- |
| `ext-fileinfo` | Prüft den **tatsächlichen Dateiinhalt (Magic Bytes)** und nicht nur die Dateiendung. | Wenn ein Angreifer `shell.php` in `photo.jpg` umbenennt und hochlädt, erkennt und blockiert `ext-fileinfo` dies sofort. |
| `ext-dom` & `ext-libxml` | Parst hochgeladene `.svg`-XML-Strukturen sicher (`SvgSanitizer`). | Entfernt bösartige `<script>`-Tags oder XXE-Payloads in einem hochgeladenen SVG-Logo, während die saubere Grafik erhalten bleibt. |

*(Hinweis: `ext-json` ist seit PHP 8.0 fest im PHP-Kern integriert und muss nicht separat installiert werden.)*

### Optionale (empfohlene) PHP-Erweiterungen
Fehlt eine dieser Erweiterungen, **funktioniert der Dateimanager weiterhin** und greift automatisch auf sinnvolle Fallbacks zurück:

| Erweiterung | Aufgabe | Fallback-Verhalten bei Fehlen |
| :--- | :--- | :--- |
| `ext-gd` **oder** `ext-imagick` | Erzeugt schnelle WebP-/JPEG-Vorschaubilder (Thumbnails) und führt beim Hochladen eine automatische Bildoptimierung durch. | Zeigt je nach `thumbnail.on_missing_driver` das Originalbild oder das Dateisymbol an. |
| `ext-exif` | Liest bei Verwendung von `ext-gd` die Smartphone-EXIF-Ausrichtung aus und dreht verdrehte Fotos automatisch richtig (`auto_orient: true`). | Fotos werden in ihrer ursprünglichen Ausrichtung gespeichert (bei installiertem `ext-imagick` nicht erforderlich). |
| `ext-zip` (`ZipArchive`) | Ermöglicht Mehrfach-`.zip`-Downloads **und** sicheres Entpacken von Archiven (`zip.allow_extract: true`). | Die Schaltflächen **Als ZIP herunterladen** und **ZIP entpacken** werden automatisch ausgeblendet. |

Prüfen Sie mit einem einzigen Befehl, welche Erweiterungen auf Ihrem System aktiv sind:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Erforderliche & optionale Composer-Pakete

Wenn Sie `composer require liveux/file-manager-bundle` ausführen, werden **alle erforderlichen Pakete automatisch installiert**. Zu wissen, wofür jede Abhängigkeit zuständig ist, erleichtert die spätere Fehlersuche erheblich:

### Erforderliche Pakete (automatisch installiert)
| Paket | Versionsbedingung | Zweck |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Einheitliche Dateisystem-Abstraktion für lokale Festplatten, AWS S3, Cloudflare R2, Supabase, MinIO und SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Rendert den Dateimanager über `{{ component('LiveUX:FileManager:Browser') }}` mit 0-ms-SSR. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Steuert clientseitiges Verzeichnis-Caching, Drag-and-Drop, Kontextmenüs, Lightbox und Formular-Auswahlfelder. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Symfony-Basislaufzeit, Routing und Cache-Pools (`cache.app`). |
| `symfony/config` & `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Schemavalidierung für `liveux_file_manager.yaml` und Verdrahtung im Service-Container. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Sendet 9 Domänen-Events (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent` usw.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Sichere Dateidownloads, HTTP-206-Videostreaming (`Range`), ETags und HTTP-Cache-Header. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Twig-Templates, Icon-/Übersetzungs-Twig-Helper und automatisches FormType-Theming. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Formularfeld `FileManagerPickerType` für Einzeldateien und Mehrfach-Bildergalerien. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Serverseitige MIME-Typ-Erkennung für hochgeladene Dateien. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Transliteriert mehrsprachige Dateinamen (Deutsch, Türkisch, Französisch, Arabisch, Kyrillisch usw.) in saubere SEO-Slugs. |
| `symfony/security-core` & `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | Voter-RBAC mit 17 Berechtigungen und `X-LiveUX-FM-CSRF`-Schutz. |
| `symfony/cache-contracts` & `symfony/translation-contracts` | `^3.5` | Verträge für Verzeichnis-Caching und mehrsprachige Benutzeroberflächen. |

### Optionale Pakete (nur bei Bedarf installieren)
| Paket | Installationsbefehl | Wann Sie es benötigen |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Auslieferung von JS und CSS ohne Build-Schritt, Node.js oder npm *(Empfohlen)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Rollenbasierte Zugriffsrechte (`ROLE_USER` / `ROLE_ADMIN`) und Benutzerisolierung über `{user_id}`. |
| `symfony/translation` | `composer require symfony/translation` | Anbindung an den Symfony-Translator für alle **12 integrierten Oberflächensprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) oder eigene App-Locales. |
| `symfony/console` & `symfony/yaml` | `composer require symfony/console symfony/yaml` | CLI-Befehle (`file-manager:doctor`, `thumbs:warmup`, `trash:purge` usw.) und YAML-Konfigurationsdateien. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Anbindung von AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO oder Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Multi-Server-/Load-Balanced-Umgebungen mit `defaults.metadata.driver: 'doctrine'` (siehe [Kapitel 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Integriertes `FileManagerField` für EasyAdmin-4/5-Dashboards (siehe [Kapitel 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ Mit KI vorschlagen** für automatische Alt-Texte, Bildunterschriften und Tags (siehe [Kapitel 09](09-symfony-ai-integration.md)). |

---

## 4. Schritt-für-Schritt-Installation

Folgen Sie diesen 7 Schritten der Reihe nach, um einen einsatzbereiten Dateimanager einzurichten.

### Schritt 1: Bundle installieren

**Standardinstallation (über Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Lokales Path-Repository (bei Entwicklung innerhalb von `bundles/liveux-file-manager-bundle`):**  
Fügen Sie Ihrer `composer.json` im Projektverzeichnis einen `repositories`-Eintrag hinzu:

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

Installieren Sie anschließend das lokale Paket:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Schritt 2: `config/bundles.php` prüfen

Symfony Flex registriert das Bundle normalerweise automatisch. Prüfen Sie andernfalls, ob folgende Einträge in `config/bundles.php` vorhanden sind:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Bei Nutzung von Authentifizierung/Rollen
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **registriert beim Booten automatisch** seinen Twig-Namespace (`@LiveUXFileManager`), seinen AssetMapper-Pfad (`@liveux/file-manager-bundle`) und sein FormType-Theme (`@LiveUXFileManager/form/picker.html.twig`). Sie müssen weder `twig.yaml` noch `asset_mapper.yaml` bearbeiten.

---

### Schritt 3: Routen importieren

Erstellen Sie die Datei `config/routes/liveux_file_manager.yaml`, damit die AJAX-API-, Download-, Thumbnail- und Upload-Endpunkte des Bundles aktiv sind:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Dieser einzige Import registriert **12 abgesicherte Endpunkte**:

| Methode & Pfad | Zweck |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Lädt das HTML des Dateimanagers in einem Modal- oder OffCanvas-Dialog. |
| `GET /_liveux-file-manager/api/list` | Liefert eine JSON-Auflistung aller Dateien und Unterordner eines Verzeichnisses. |
| `GET /_liveux-file-manager/api/tree` | Ruft den vollständigen Ordnerbaum für die Seitenleiste in einer Anfrage ab. |
| `GET /_liveux-file-manager/api/trash` | Listet vorläufig gelöschte Elemente im Papierkorb auf. |
| `GET /_liveux-file-manager/api/preview-text` | Liefert eine sichere Text-/Code-Vorschau (auf 64 KB begrenzt) für `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql` usw. |
| `POST /_liveux-file-manager/api/action` | Führt Änderungen aus: Ordner erstellen, Verschieben, Kopieren, Umbenennen, Löschen, Papierkorb wiederherstellen/leeren, Ordnerfarben/-symbole, Metadaten, KI-Anreicherung, URL-Import und ZIP-Entpacken. |
| `POST /_liveux-file-manager/upload` | Verarbeitet Standard-Uploads sowie gestückelte (Chunked) Datei-Uploads. |
| `POST /_liveux-file-manager/image-edit` | Speichert zugeschnittene, skalierte oder gedrehte Bilder aus dem integrierten Canvas-Bildeditor. |
| `GET /_liveux-file-manager/download` | Liefert sichere Downloads, Inline-PDF-/Videovorschauen (mit `Range`-Unterstützung) sowie Dateien aus `private: true`-Volumes aus. |
| `GET /_liveux-file-manager/share` | Liefert zeitlich begrenzte, HMAC-signierte Freigabelinks aus. |
| `POST /_liveux-file-manager/zip` | Streamt ausgewählte Dateien und Ordner als einzelnes `.zip`-Archiv. |
| `GET /_liveux-file-manager/thumb` | Generiert und liefert WebP-/JPEG-/PNG-Vorschaubilder mit ETag und kontextbewussten HTTP-Cache-Headern aus. |

---

### Schritt 4: `config/packages/liveux_file_manager.yaml` erstellen

Erstellen Sie die Datei `config/packages/liveux_file_manager.yaml`. Die folgende Startkonfiguration listet **alle 17 granularen Berechtigungen** explizit auf:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = übernimmt automatisch die aktive Symfony-Locale (Fallback immer auf 'en')
                            # Oder eine feste Sprache setzen, z. B. 'de', 'en', 'tr', 'fr', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'Meine Dateien'
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
                extract_zip: false        # ZIP-Entpacken ist standardmäßig deaktiviert (erfordert außerdem zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Wichtiger Sicherheitshinweis (`permissions`):**  
> Jeder Schlüssel, den Sie unter `permissions` **weglassen** (mit Ausnahme von `extract_zip`), hat standardmäßig den Wert `true`. Dabei bedeutet `true` **„jeder angemeldete Benutzer“** — NICHT anonyme Besucher. Wenn nur `ROLE_ADMIN` den Papierkorb endgültig leeren (`trash_purge`) oder Freigabelinks erstellen (`share`) darf, setzen Sie diese Schlüssel explizit auf `'ROLE_ADMIN'`.

> [!TIP]
> **Lokal testen, bevor eine Anmeldung eingerichtet ist?**  
> Während der lokalen Prototyp-Entwicklung ohne Login-System können Sie vorübergehend `profiles.default.security.voter_enabled: false` setzen. **Lassen Sie `voter_enabled: false` NIEMALS in der Produktion aktiv** — siehe [06 — Sicherheit & Autorisierung](06-security-and-authorization.md).

> [!NOTE]
> **12 integrierte Oberflächensprachen & automatisches RTL (`defaults.locale: null`):**  
> Mit `locale: null` übernimmt der Dateimanager automatisch die aktive Symfony-Request-Locale für alle **12 integrierten Oberflächensprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), aktiviert automatisch **RTL (`dir="rtl"`)** für Arabisch und weitere RTL-Sprachen (Persisch, Hebräisch, Urdu usw.), normalisiert BCP-47- sowie POSIX-Locales (`de_DE.UTF-8`, `zh-Hans-CN`) und fällt bei nicht unterstützten Locales sauber auf **Englisch (`en`)** zurück.

---

### Schritt 5: CSS & JavaScript verbinden (Zero Configuration!)

**Sie sind unsicher, ob Ihr Projekt AssetMapper oder Webpack Encore nutzt?**  
Prüfen Sie Ihr Projektverzeichnis:
- Wenn `importmap.php` vorhanden ist → folgen Sie **Option A (AssetMapper)**.
- Wenn `webpack.config.js` vorhanden ist → folgen Sie **Option B (Webpack Encore)**.

> [!TIP]
> **Zero-Configuration-`autoimport`-Unterstützung:**  
> LiveUX FileManagerBundle deklariert in seiner `assets/package.json` **`autoimport`** für `@liveux/file-manager-bundle/styles/core.css` und den eager geladenen `dialog_controller.js` (der `file_manager_api.js` lädt). Sobald `assets/controllers.json` aktiviert ist, **benötigen Sie keine einzige manuelle `import`-Anweisung in `assets/app.js`!**

#### Option A: Symfony AssetMapper (Empfohlen — kein Node.js / npm erforderlich)

1. Prüfen Sie, ob die Controller des Bundles in `assets/controllers.json` aktiviert sind (Symfony Flex trägt dies automatisch ein; andernfalls manuell ergänzen):
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

2. Symfony-6.4-/7.2+-/8.x-Projekte rufen bereits `startStimulusApp()` in `assets/bootstrap.js` oder `assets/stimulus_bootstrap.js` auf, wodurch `controllers.json` automatisch eingelesen wird:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) oder assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Stellen Sie sicher, dass Ihr Basis-Layout (`templates/base.html.twig`) `importmap('app')` einbindet:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Optional — beim Deployment in die Produktion)* Statische Assets kompilieren:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Vorab minimierte Produktions-Assets (`assets/dist/`):**  
> Da AssetMapper JS/CSS standardmäßig nicht minimiert, liefert LiveUX FileManagerBundle unter `assets/dist/` bereits mit `esbuild` minimierte JavaScript-Module sowie `core.css` mit (`747 KB → 410 KB` minimiert; `~93 KB` mit Gzip bzw. **`~77 KB`** mit Brotli). `FileManagerBundle` bindet `assets/dist/` automatisch in AssetMapper ein, sodass **weder Ihr Arbeitsplatz noch Ihr Produktionsserver Node.js oder npm benötigt**.  
> Unter Symfony 7.3+ / 8.x können Sie zudem `precompress: true` in `config/packages/asset_mapper.yaml` aktivieren, um während `asset-map:compile` automatisch `.br`- und `.gz`-Dateien erzeugen zu lassen.

#### Option B: Webpack Encore

1. Stellen Sie bei Webpack-Encore-Projekten (einschließlich Symfony 8 + StimulusBundle 3.x) sicher, dass `@symfony/stimulus-bridge` installiert ist:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Prüfen Sie die 3 Stimulus-Controller und den `autoimport`-Block in `assets/controllers.json`:
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

3. Prüfen Sie, ob `startStimulusApp` in `assets/bootstrap.js` eingebunden ist (`assets/app.js` importiert diese Datei — manuelle CSS-/JS-Imports sind nicht erforderlich):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Assets erstellen:
```bash
npm run build
# Oder während der Entwicklung dauerhaft beobachten: npm run watch
```

---

### Schritt 6: `DialogHost` (Automatische Einbindung — kein zusätzliches Markup nötig!)

Um Modals, OffCanvas-Panels, `FileManagerPickerType` oder TinyMCE-/CKEditor-/Tiptap-Auswahldialoge von beliebigen Seiten aus zu öffnen, wird ein `<dialog>`-Container im DOM benötigt.

Der `AutoInjectDialogHostSubscriber` des Bundles fügt diesen Host bei vollständigen HTML-Antworten automatisch direkt vor `</body>` ein (`defaults.auto_inject_dialog_host: true`) — Sie müssen `base.html.twig` also nicht bearbeiten.

- **Intelligente Erkennung:** Greift niemals in JSON-, AJAX-, Turbo-Frame-, Redirect- oder binäre Download-Antworten ein und überspringt die Einfügung, falls bereits ein manueller `DialogHost` vorhanden ist.
- **0 ms Overhead beim Seitenaufruf:** Fragt beim initialen Laden der Seite weder Verzeichnisse noch Ordnerbäume ab; Daten werden erst geladen, wenn ein Benutzer den Dialog tatsächlich öffnet.
- **Manuelle Einbindung auf ausgewählten Seiten bevorzugt?** Setzen Sie `defaults.auto_inject_dialog_host: false` und fügen Sie das folgende Snippet direkt vor `</body>` in den gewünschten Layouts ein:

```twig
{# Optional — nur erforderlich, wenn auto_inject_dialog_host: false gesetzt ist #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Schritt 7: Installation prüfen (`file-manager:doctor`)

Leeren Sie den Cache und führen Sie den integrierten Diagnosebefehl aus:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Falls Sie Cloud-Volumes (S3, R2, Supabase, MinIO) konfiguriert haben, ergänzen Sie `--check-remote`, um die Live-Verbindung zu testen:
```bash
php bin/console file-manager:doctor --check-remote
```

Beispiel für eine fehlerfreie Ausgabe (9 Umgebungsprüfungen + Statusübersicht der Profile und Volumes):
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
> **Windows-Benutzer (`cURL error 60`):** Wenn `cURL CA Bundle` eine Warnung meldet, verweisen Sie `curl.cainfo` und `openssl.cafile` in Ihrer `php.ini` auf eine aktuelle `cacert.pem` oder konfigurieren Sie `driver_options.ca_bundle` direkt an Ihrem S3-Volume.
>
> **Linux-Dateirechte:** Stellen Sie sicher, dass `public/uploads`, `public/media-cache` und `var/file-manager` für den Webserver-Benutzer (`www-data`, `nginx` usw.) beschreibbar sind.
>
> **Sie nutzen einen KI-Programmierassistenten (Antigravity, Claude Code, Cursor, Copilot)?**  
> Bringen Sie Ihrem Assistenten alle Bundle-Konventionen und Rezepte mit einem einzigen Befehl bei (siehe [Kapitel 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Ihre erste funktionierende Seite erstellen

Erstellen wir einen einfachen Symfony-Controller und ein Twig-Template, um die gesamte Integration durchgängig zu testen.

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

### 2) Twig-Template (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Mediathek</h1>

    {# Option 1: Inline-Dateimanager #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Option 2: Modal per Schaltfläche öffnen und ausgewählte Dateien übernehmen #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Dateien im Modal auswählen</button>
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
                return; // Benutzer hat den Dialog ohne Auswahl geschlossen
            }

            document.getElementById('selection-result').textContent =
                'Ausgewählt: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Öffnen Sie `/admin/media` in Ihrem Browser. Herzlichen Glückwunsch — Ihr Dateimanager ist einsatzbereit!

---

## 6. Wie geht es weiter?

- Eigene Profile, Upload-Limits und Bildregeln konfigurieren → **[02 — Konfiguration & Profile](02-configuration-and-profiles.md)**
- Bild- oder Galerie-Auswahl in Symfony-Formulare integrieren → **[05 — FormType & Editor-Integrationen](05-formtype-and-editor-integrations.md)**
- RBAC und Sicherheit vor dem Produktivgang härten → **[06 — Sicherheit & Autorisierung](06-security-and-authorization.md)**

---

[← Dokumentationsübersicht](README.md) | [Weiter: 02 — Konfiguration & Profile →](02-configuration-and-profiles.md)
