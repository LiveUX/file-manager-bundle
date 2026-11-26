# 01 — Requisiti, dipendenze e installazione

[← Indice della documentazione](README.md) | [Avanti: 02 — Configurazione e profili →](02-configuration-and-profiles.md)

Questa guida ti accompagna passo dopo passo nell'installazione di LiveUX FileManagerBundle da zero, spiegando **ogni passaggio e il motivo per cui serve**, anche se è il tuo primissimo pacchetto Symfony UX.

> [!NOTE]
> **In parole semplici: che cosa fa questo bundle?**  
> Aggiunge un gestore file completo (simile a Google Drive o Esplora file) alla tua applicazione Symfony—che sia un pannello di amministrazione, un e-commerce o un portale clienti. Gli utenti possono **caricare file, organizzare cartelle, ritagliare immagini, visualizzare l'anteprima di PDF e video e selezionare file direttamente dai form** con un solo clic.  
> Non richiede **alcun framework CSS** (Bootstrap, Tailwind, ecc.) né **jQuery**, e con Symfony AssetMapper è operativo in 5 minuti senza Node.js o npm.

---

## 1. Requisiti di sistema

Prima di iniziare l'installazione, verifica che la tua macchina locale o il server soddisfino questi requisiti minimi:

| Componente | Versione minima | Perché è necessario | Comando di verifica |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` o superiore | Sfrutta le funzionalità di PHP 8.3/8.4 (classi `readonly`, costanti di classe tipizzate). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` o `8.x` | Pienamente testato e supportato su Symfony 6.4 LTS, 7.2, 7.3, 7.4 e 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Installazione dei pacchetti e autoloading PSR-4. | `composer --version` |
| **Browser** | Browser moderno aggiornato | Top Layer nativo HTML5 `<dialog>`, ES Modules e variabili CSS (Chrome, Edge, Firefox, Safari). | — |

---

## 2. Estensioni PHP

Le estensioni PHP forniscono a PHP funzionalità native aggiuntive, come l'ispezione dei file, il parsing XML e l'elaborazione delle immagini.

### Estensioni PHP obbligatorie
Sono attive di default in quasi tutte le installazioni PHP moderne:

| Estensione | Cosa fa | Esempio pratico |
| :--- | :--- | :--- |
| `ext-fileinfo` | Ispeziona il **contenuto reale del file (magic bytes)**, non solo l'estensione del nome file. | Se un attaccante rinomina `shell.php` in `photo.jpg` e prova a caricarlo, `ext-fileinfo` lo intercetta e lo rifiuta immediatamente. |
| `ext-dom` e `ext-libxml` | Analizza in modo sicuro l'albero XML dei file `.svg` caricati (`SvgSanitizer`). | Rimuove tag `<script>` malevoli o payload XXE nascosti dentro un logo SVG preservando la grafica pulita. |

*(Nota: `ext-json` è integrata nel core di PHP dalla versione 8.0 e non richiede un'installazione separata.)*

### Estensioni PHP opzionali (consigliate)
Se qualcuna di queste estensioni non è presente, il gestore file **continua a funzionare** attivando automaticamente un fallback sicuro:

| Estensione | Cosa fa | Comportamento di fallback se assente |
| :--- | :--- | :--- |
| `ext-gd` **oppure** `ext-imagick` | Genera miniature WebP/JPEG veloci ed esegue l'ottimizzazione automatica delle immagini in fase di caricamento. | Mostra l'immagine originale o l'icona del file in base a `thumbnail.on_missing_driver`. |
| `ext-exif` | Legge i metadati EXIF di orientamento degli smartphone quando si usa `ext-gd`, ruotando automaticamente le foto storte (`auto_orient: true`). | Le foto vengono salvate nell'orientamento originale (non necessaria se è installata `ext-imagick`). |
| `ext-zip` (`ZipArchive`) | Abilita il download multi-file in `.zip` **e** l'estrazione sicura degli archivi (`zip.allow_extract: true`). | I pulsanti **Scarica ZIP** ed **Estrai archivio** vengono nascosti automaticamente. |

Controlla quali estensioni sono attive sulla tua macchina con un solo comando:

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Pacchetti Composer obbligatori e opzionali

Quando esegui `composer require liveux/file-manager-bundle`, **tutti i pacchetti obbligatori vengono installati automaticamente**. Sapere a cosa serve ciascuna dipendenza rende il debug molto più semplice:

### Pacchetti obbligatori (installati automaticamente)
| Pacchetto | Vincolo di versione | Scopo |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Astrazione unificata del filesystem per disco locale, AWS S3, Cloudflare R2, Supabase, MinIO e SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Renderizza il gestore file tramite `{{ component('LiveUX:FileManager:Browser') }}` con SSR a 0ms. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Gestisce lato client cache delle cartelle, drag-and-drop, menu contestuali, Lightbox e selettori per form. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Runtime core di Symfony, routing e pool di cache (`cache.app`). |
| `symfony/config` e `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Validazione dello schema `liveux_file_manager.yaml` e cablaggio dei servizi nel container. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Emette 9 eventi di dominio (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent`, ecc.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Download protetti, streaming video HTTP 206 (`Range`), ETag e header di cache HTTP. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Template Twig, helper Twig per icone e traduzioni e tema automatico per il FormType. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Campo form `FileManagerPickerType` per file singolo e galleria multi-file. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Rilevamento server-side del tipo MIME dei file caricati. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Traslittera nomi file multilingua (turco, francese, arabo, cirillico, ecc.) in slug SEO puliti. |
| `symfony/security-core` e `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | RBAC tramite Voter a 17 permessi e protezione `X-LiveUX-FM-CSRF`. |
| `symfony/cache-contracts` e `symfony/translation-contracts` | `^3.5` | Contratti per la cache delle cartelle e l'interfaccia multilingua. |

### Pacchetti opzionali (da installare solo se necessari)
| Pacchetto | Comando di installazione | Quando serve |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Distribuzione JS e CSS senza build, senza Node.js né npm *(consigliato)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Permessi basati sui ruoli (`ROLE_USER` / `ROLE_ADMIN`) e isolamento per utente `{user_id}`. |
| `symfony/translation` | `composer require symfony/translation` | Si integra con il Translator di Symfony per tutte le **12 lingue UI integrate** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) o per i locale personalizzati dell'app. |
| `symfony/console` e `symfony/yaml` | `composer require symfony/console symfony/yaml` | Comandi CLI (`file-manager:doctor`, `thumbs:warmup`, `trash:purge`, ecc.) e file di configurazione YAML. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Collegamento ad AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO o Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Ambienti multi-server / con load balancer usando `defaults.metadata.driver: 'doctrine'` (vedi [Capitolo 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Campo `FileManagerField` integrato per dashboard EasyAdmin 4/5 (vedi [Capitolo 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | Generazione automatica di testo alternativo, didascalie e tag tramite **✨ Suggerisci con AI** (vedi [Capitolo 09](09-symfony-ai-integration.md)). |

---

## 4. Installazione passo dopo passo

Segui questi 7 passaggi in ordine per avere subito un gestore file operativo.

### Passaggio 1: Installa il bundle

**Installazione standard (da Packagist):**
```bash
composer require liveux/file-manager-bundle
```

**Repository di percorso locale (se sviluppi in `bundles/liveux-file-manager-bundle`):**  
Aggiungi una voce `repositories` nel `composer.json` principale del progetto:

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

Quindi richiedi il pacchetto locale:
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Passaggio 2: Verifica `config/bundles.php`

Normalmente Symfony Flex registra il bundle automaticamente. In caso contrario, assicurati che queste voci siano presenti in `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Se usi autenticazione/ruoli
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **registra automaticamente** all'avvio il proprio namespace Twig (`@LiveUXFileManager`), il percorso AssetMapper (`@liveux/file-manager-bundle`) e il tema FormType (`@LiveUXFileManager/form/picker.html.twig`). Non devi modificare `twig.yaml` né `asset_mapper.yaml`.

---

### Passaggio 3: Importa le rotte

Crea `config/routes/liveux_file_manager.yaml` per attivare gli endpoint AJAX API, download, miniature e caricamento del bundle:

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Questa singola importazione registra **12 endpoint protetti**:

| Metodo e percorso | Scopo |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Carica l'HTML del gestore file all'interno di una finestra Modale o OffCanvas. |
| `GET /_liveux-file-manager/api/list` | Restituisce l'elenco JSON di file e sottocartelle di una directory. |
| `GET /_liveux-file-manager/api/tree` | Recupera in una sola richiesta l'intero albero delle cartelle per la barra laterale. |
| `GET /_liveux-file-manager/api/trash` | Elenca gli elementi eliminati in modo reversibile presenti nel Cestino. |
| `GET /_liveux-file-manager/api/preview-text` | Restituisce un'anteprima sicura (limitata a 64 KB) di file di testo/codice `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, ecc. |
| `POST /_liveux-file-manager/api/action` | Esegue le operazioni di modifica: nuova cartella, sposta, copia, rinomina, elimina, ripristina/svuota Cestino, colori/icone cartelle, metadati, arricchimento AI, importazione da URL remoto ed estrazione ZIP. |
| `POST /_liveux-file-manager/upload` | Gestisce sia i caricamenti standard sia quelli a blocchi (chunked). |
| `POST /_liveux-file-manager/image-edit` | Salva le immagini ritagliate, ridimensionate o ruotate dall'editor immagini Canvas integrato. |
| `GET /_liveux-file-manager/download` | Serve download sicuri, anteprime inline di PDF/video (con supporto `Range`) e volumi con `private: true`. |
| `GET /_liveux-file-manager/share` | Serve link di condivisione temporanei con firma HMAC. |
| `POST /_liveux-file-manager/zip` | Invia in streaming i file e le cartelle selezionati come singolo archivio `.zip`. |
| `GET /_liveux-file-manager/thumb` | Genera e serve miniature WebP/JPEG/PNG con ETag e header di cache HTTP sensibili al contesto. |

---

### Passaggio 4: Crea `config/packages/liveux_file_manager.yaml`

Crea `config/packages/liveux_file_manager.yaml`. La configurazione iniziale qui sotto elenca esplicitamente **tutti i 17 permessi granulari**:

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = eredita automaticamente il locale attivo di Symfony (fallback sempre su 'en')
                            # Oppure fissa un locale specifico come 'it', 'en', 'tr', 'fr', 'de', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'I miei file'
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
                extract_zip: false        # L'estrazione ZIP è disabilitata di default (richiede anche zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Nota critica di sicurezza (`permissions`):**  
> Qualsiasi chiave **omessa** sotto `permissions` (tranne `extract_zip`) assume come valore predefinito `true`. In questo contesto, `true` significa **"qualsiasi utente autenticato"**, NON visitatori anonimi. Se vuoi che solo `ROLE_ADMIN` possa svuotare definitivamente il Cestino (`trash_purge`) o creare link di condivisione (`share`), imposta esplicitamente quelle chiavi su `'ROLE_ADMIN'`.

> [!TIP]
> **Stai testando in locale senza aver ancora configurato l'autenticazione?**  
> Durante la prototipazione locale senza un sistema di login, puoi impostare temporaneamente `profiles.default.security.voter_enabled: false`. **Non lasciare MAI `voter_enabled: false` in produzione**—consulta [06 — Sicurezza e autorizzazioni](06-security-and-authorization.md).

> [!NOTE]
> **12 lingue UI integrate e RTL automatico (`defaults.locale: null`):**  
> Lasciando `locale: null`, il gestore file segue automaticamente il locale attivo della richiesta Symfony su tutte le **12 lingue UI integrate** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), abilita automaticamente il layout **RTL (`dir="rtl"`)** per l'arabo e altri locale RTL (persiano, ebraico, urdu, ecc.), normalizza i locale BCP-47 e POSIX (`de_DE.UTF-8`, `zh-Hans-CN`) ed esegue un fallback pulito sull'**inglese (`en`)** per i locale non supportati.

---

### Passaggio 5: Collega CSS e JavaScript (Zero configurazione!)

**Non sai se il tuo progetto usa AssetMapper o Webpack Encore?**  
Controlla la directory principale del progetto:
- Se è presente `importmap.php` → segui l'**Opzione A (AssetMapper)**.
- Se è presente `webpack.config.js` → segui l'**Opzione B (Webpack Encore)**.

> [!TIP]
> **Supporto `autoimport` a zero configurazione:**  
> LiveUX FileManagerBundle dichiara **`autoimport`** nel proprio `assets/package.json` per `@liveux/file-manager-bundle/styles/core.css` e per il controller eager `dialog_controller.js` (che carica `file_manager_api.js`). Una volta abilitato `assets/controllers.json`, **non serve nemmeno una riga di `import` manuale in `assets/app.js`!**

#### Opzione A: Symfony AssetMapper (consigliato — senza Node.js / npm)

1. Verifica che i controller del bundle siano abilitati in `assets/controllers.json` (Symfony Flex li aggiunge automaticamente; altrimenti inseriscili tu):
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

2. I progetti Symfony 6.4 / 7.2+ / 8.x chiamano già `startStimulusApp()` dentro `assets/bootstrap.js` o `assets/stimulus_bootstrap.js`, che legge automaticamente `controllers.json`:
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) oppure assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Assicurati che il tuo layout base (`templates/base.html.twig`) includa `importmap('app')`:
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Opzionale — per il deploy in produzione)* Compila gli asset statici:
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Asset di produzione pre-minificati (`assets/dist/`):**  
> Poiché AssetMapper non minifica JS/CSS di default, LiveUX FileManagerBundle include moduli JavaScript pre-minificati e `core.css` generati con `esbuild` dentro `assets/dist/` (`747 KB → 410 KB` minificati; `~93 KB` con Gzip, oppure **`~77 KB`** con Brotli). `FileManagerBundle` mappa automaticamente `assets/dist/` in AssetMapper, quindi **né la tua workstation né il server di produzione hanno bisogno di Node.js o npm**.  
> Su Symfony 7.3+ / 8.x puoi anche abilitare `precompress: true` in `config/packages/asset_mapper.yaml` per generare automaticamente i file `.br` e `.gz` durante `asset-map:compile`.

#### Opzione B: Webpack Encore

1. Nei progetti Webpack Encore (inclusi Symfony 8 + StimulusBundle 3.x), verifica che `@symfony/stimulus-bridge` sia installato:
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Verifica i 3 controller Stimulus e il blocco `autoimport` in `assets/controllers.json`:
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

3. Verifica che `startStimulusApp` sia collegato in `assets/bootstrap.js` (`assets/app.js` lo importa già—non servono import manuali di CSS/JS):
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Compila gli asset:
```bash
npm run build
# Oppure in modalità watch durante lo sviluppo: npm run watch
```

---

### Passaggio 6: `DialogHost` (Iniezione automatica — nessun markup aggiuntivo!)

Per aprire finestre Modali, pannelli OffCanvas, `FileManagerPickerType` o i selettori di TinyMCE/CKEditor/Tiptap da qualsiasi pagina occorre un contenitore `<dialog>` nel DOM.

Il subscriber `AutoInjectDialogHostSubscriber` del bundle inserisce automaticamente questo contenitore subito prima di `</body>` nelle risposte HTML complete (`defaults.auto_inject_dialog_host: true`), evitando qualsiasi modifica a `base.html.twig`.

- **Rilevamento intelligente:** Non tocca mai risposte JSON, AJAX, Turbo Frame, redirect o download binari e salta l'iniezione se un `DialogHost` manuale è già presente.
- **Impatto 0ms sul caricamento pagina:** Non interroga directory né alberi di cartelle al caricamento iniziale della pagina; i dati vengono caricati solo quando l'utente apre la finestra.
- **Preferisci posizionarlo manualmente solo su pagine specifiche?** Imposta `defaults.auto_inject_dialog_host: false` e includi questo snippet subito prima di `</body>` nei layout desiderati:

```twig
{# Opzionale — necessario solo quando auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Passaggio 7: Verifica l'installazione (`file-manager:doctor`)

Svuota la cache ed esegui il comando di diagnostica integrato:

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Se hai configurato volumi cloud (S3, R2, Supabase, MinIO), aggiungi `--check-remote` per testare la connettività in tempo reale:
```bash
php bin/console file-manager:doctor --check-remote
```

Esempio di output corretto (9 controlli di ambiente + tabella di stato profili/volumi):
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
> **Utenti Windows (`cURL error 60`):** Se `cURL CA Bundle` mostra un avviso, fai puntare `curl.cainfo` e `openssl.cafile` in `php.ini` a un file `cacert.pem` aggiornato, oppure configura `driver_options.ca_bundle` sul volume S3.
>
> **Permessi su Linux:** Assicurati che `public/uploads`, `public/media-cache` e `var/file-manager` siano scrivibili dall'utente del web server (`www-data`, `nginx`, ecc.).
>
> **Usi un assistente AI per il codice (Antigravity, Claude Code, Cursor, Copilot)?**  
> Insegna al tuo assistente tutte le convenzioni e ricette del bundle con un solo comando (vedi [Capitolo 12](12-ai-agent-skills-guide.md)):
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Crea la tua prima pagina funzionante

Creiamo un semplice Controller Symfony e un template Twig per verificare il funzionamento completo end-to-end.

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

### 2) Template Twig (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Libreria media</h1>

    {# Opzione 1: Gestore file inline #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Opzione 2: Apri una finestra Modale da qualsiasi pulsante e raccogli i file selezionati #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Seleziona file in Modale</button>
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
                return; // L'utente ha chiuso la finestra senza selezionare nulla
            }

            document.getElementById('selection-result').textContent =
                'Selezionati: ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Apri `/admin/media` nel browser. Congratulazioni: il tuo gestore file è attivo!

---

## 6. Prossimi passi

- Configura profili personalizzati, limiti di caricamento e regole per le immagini → **[02 — Configurazione e profili](02-configuration-and-profiles.md)**
- Aggiungi un selettore di immagini o gallerie ai Symfony Form → **[05 — FormType e integrazioni editor](05-formtype-and-editor-integrations.md)**
- Rafforza RBAC e sicurezza prima di andare in produzione → **[06 — Sicurezza e autorizzazioni](06-security-and-authorization.md)**

---

[← Indice della documentazione](README.md) | [Avanti: 02 — Configurazione e profili →](02-configuration-and-profiles.md)
