# 04 — Temi, Dark Mode, Personalizzazione delle Cartelle, Set di Icone e Design Personalizzato

[← Precedente: 03 — Modalità d'uso e API JS](03-usage-modes-and-js-api.md) | [Indice](README.md) | [Successivo: 05 — FormType e Integrazioni Editor →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle è progettato con **zero dipendenze da framework CSS**. Che il tuo progetto utilizzi **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** o **nessun framework CSS**, funziona subito senza passaggi di build aggiuntivi (nessuna configurazione `@source` o purge di Tailwind richiesta).

> **In sintesi: cosa imparerai in questa guida**  
> 1. **9 temi integrati e Dark Mode:** adatta lo stile visivo e lo schema colori della tua applicazione con una sola opzione YAML.  
> 2. **Colorazione e icone (badge) delle cartelle:** personalizza le cartelle dall'interfaccia o via codice in PHP/DB usando oltre 18 colori e 14 badge integrati (oltre a infiniti badge SVG personalizzati).  
> 3. **Creare il tuo set di icone personalizzato:** istruzioni passo dopo passo per realizzare un pacchetto icone che copra 9 icone di file, 49 icone UI e badge personalizzati illimitati per le cartelle.  
> 4. **Costruire un tema personalizzato (3 livelli di libertà):** branding con pure variabili CSS, override parziali di Twig con `{% extends %}`, oppure un layout 100% headless da zero.

---

## 1. I 9 temi integrati (quale scegliere?)

I temi possono essere configurati tramite `defaults.theme`, `profiles.<name>.ui.theme`, la prop `theme` del TwigComponent o l'opzione `theme` di `FileManagerPickerType`. **Tutti i 9 temi sono verificati secondo gli standard di contrasto WCAG 2.1 AA sia in modalità chiara che scura.**

| Chiave tema | Ideale per | Riepilogo del design |
| :--- | :--- | :--- |
| `bootstrap5` *(predefinito)* | Bootstrap 5 e pannelli admin Symfony classici | Blu classico (`#0d6efd`), raggi `10px / 8px / 6px`, bordi neutri puliti |
| `tailwind` | App web moderne sviluppate con Tailwind CSS v3/v4 | Accento indaco (`#6366f1`), raggi morbidi `14px / 12px / 8px`, palette Slate |
| `shadcn` | Dashboard SaaS minimaliste in bianco e nero monocromatico | Superfici Zinc monocromatiche (`#18181b` / `#fafafa` in dark mode), bordi netti `8px / 6px` |
| `flowbite` | Pannelli admin con Flowbite 4 + Tailwind CSS | Blu Flowbite (`#1a56db`) e superfici Gray-50/800/900, controlli `8px` |
| `daisyui` | Progetti basati su DaisyUI 5 | **Eredita automaticamente** `--color-primary`, `--color-base-100/200/300` e `--radius-box` quando DaisyUI è caricato nella pagina |
| `tabler` | Backend enterprise Tabler.io o EasyAdmin | Canvas slate enterprise, accento blu `#066fd1`, angoli decisi `4px / 6px` |
| `preline` | Applicazioni Preline UI | Superfici neutre pure (`#fafafa` / `#171717`) con accento blu vivido (`#2563eb`) |
| `material` | Applicazioni Google Material Design 3 | Superfici tonali viola (`#6750a4` / `#f3edf7`) e pulsanti completamente arrotondati (pill) |
| `unstyled` | Stile completamente personalizzato con il tuo CSS | Eredita il font dell'app ospite e superfici trasparenti, mantenendo il contrasto opaco `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` per popover e lightbox |

| Dark Mode (`bootstrap5` + Dark) | Bridge Tema DaisyUI 5 (`daisyui`) |
| :---: | :---: |
| ![File Manager in Dark Mode](../images/browser-dark.png) | ![Anteprima tema DaisyUI 5](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Regola di sicurezza (`theme` in YAML vs. a runtime):**  
> - In `liveux_file_manager.yaml` puoi configurare uno qualsiasi dei 9 temi integrati, il nome del tuo tema CSS personalizzato (es. `'corporate'`, conforme a `/^[a-zA-Z0-9_-]{1,40}$/`), oppure il percorso di un template Twig personalizzato (es. `'file_manager/custom.html.twig'` o `'@App/file_manager'`).  
> - Per motivi di sicurezza, i valori dinamici di `theme` provenienti dal browser, dai parametri query dell'URL o da `window.LiveUXFileManager.open({ theme: ... })` **accettano solo le 9 chiavi dei temi integrati** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Dark Mode, RTL e Riduzione del Movimento

Tutti i 9 temi (incluso `unstyled`) supportano la **Dark Mode sia automatica che manuale**:

### A) Rilevamento automatico dall'app ospite (`color_scheme: 'auto'`)
Non appena la tua pagina (`<html>` o `<body>`) attiva una delle seguenti impostazioni, il file manager, la finestra modale `<dialog>` e i campi `FileManagerPickerType` passano immediatamente alla modalità scura:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- classe `.dark` *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / temi personalizzati)*

### B) Schema colori predefinito o per singolo profilo tramite YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (segue l'app ospite) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Avvia solo questo profilo in modalità scura
```

### C) Preferenza dell'utente finale (Menu Impostazioni: `Auto / Chiaro / Scuro`)
Gli utenti possono passare tra **Auto / ☀ Chiaro / 🌙 Scuro** con un clic dal popover **⚙ Impostazioni** nella barra superiore. La preferenza viene salvata nel `localStorage` del browser (`liveux_fm_color_scheme`).

### D) Supporto globale RTL e `prefers-reduced-motion`
- `dir="rtl"` viene applicato automaticamente per 15 lingue da destra a sinistra, tra cui arabo (`ar`), persiano (`fa`), ebraico (`he`) e urdu (`ur`). Tutte le spaziature in `core.css` usano proprietà logiche CSS (`inset-inline`, `padding-inline`, `border-inline-end`).
- Le animazioni vengono ridotte automaticamente per gli utenti che hanno attivato *"Riduci movimento"* (`prefers-reduced-motion: reduce`) nel proprio sistema operativo.

---

## 3. Colorazione delle Cartelle e Assegnazione di Icone (Badge) — Passo dopo Passo

![Finestra modale per la personalizzazione di colori e badge delle cartelle](../images/folder-customize.png)

Puoi assegnare a qualsiasi cartella un **colore**, un'**icona badge** e lo stato **in evidenza (⭐ Pin)**. Queste funzionalità sono attive quando `defaults.metadata.enabled: true` e l'utente dispone del permesso `permissions.edit_metadata`.

### Metodo 1: Dall'interfaccia utente (per gli utenti finali)
1. Fai **clic con il tasto destro** su una cartella e seleziona **Personalizza** (oppure premi **`I`** con la cartella selezionata, o clicca su **Personalizza** nel pannello Inspector a destra).
2. Nella modale **Personalizza** puoi configurare tre opzioni con anteprima dal vivo:
   - **Palette predefinita a 18 colori:** Blue (`#2563eb`), Sky (`#0284c7`), Cyan (`#0891b2`), Teal (`#0d9488`), Emerald (`#059669`), Green (`#16a34a`), Lime (`#65a30d`), Amber (`#d97706`), Orange (`#ea580c`), Red (`#dc2626`), Rose (`#e11d48`), Pink (`#db2777`), Fuchsia (`#c026d3`), Purple (`#9333ea`), Violet (`#7c3aed`), Indigo (`#4f46e5`), Slate (`#475569`), Charcoal (`#1e293b`).
   - **Selettore colore personalizzato + input HEX:** scegli qualsiasi colore del tuo brand dal selettore nativo oppure digita direttamente un codice esadecimale `#RRGGBB`. Clicca su **Ripristina predefiniti** per rimuovere lo stile personalizzato.
   - **Griglia di icone (Badge) per cartelle:** mostra tutti i **14 badge integrati** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`) insieme a eventuali file SVG personalizzati aggiunti nella directory `badges/` del tuo set di icone.
3. Clicca su **Salva** — la cartella si aggiorna istantaneamente sia nella vista Griglia/Lista sia nell'albero delle cartelle a sinistra.

### Metodo 2: Via codice da PHP (es. alla creazione di una nuova cartella cliente)
Utilizzando `FolderMetadataStorageInterface` e `PathNormalizer::buildCanonicalKey()`, puoi assegnare colori, badge e pin direttamente da PHP (lo stesso codice funziona in modo identico sia con il driver `json_file` che con `doctrine`):

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // Costruisce la chiave canonica (es. "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Verde acqua (#RRGGBB)
            icon: 'lock',       // Uno dei 14 badge integrati oppure badges/invoice.svg personalizzato -> 'invoice'
            pinned: true,       // Aggiunge alle ⭐ Cartelle fissate nella barra laterale
        ));
    }
}
```

### Metodo 3: Direttamente in `metadata.json` (con `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Set di Icone e Creazione del Proprio Set da Zero

**Ogni icona** del bundle — tipi di file, cartelle, barra degli strumenti, menu contestuale, indicatori dei dischi cloud, pannello Inspector e badge delle cartelle — viene servita da un unico registro centralizzato `IconSetRegistry`. I 6 set integrati sono memorizzati in cache PSR-6 per 24 ore (`liveux_fm_iconset_*`).

### I 6 set di icone integrati (`profiles.<name>.ui.icon_set`)
| Set di icone | Stile visivo | Colore predefinito cartelle (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(predefinito)* | Icone vettoriali flat e nitide | `#2563eb` |
| `windows11` | Cartelle giallo caldo bicolore in stile Windows 11 e icone documenti moderne | `#f59e0b` |
| `macos` | Cartelle blu tenue ispirate al Finder di macOS | `#3b82f6` |
| `linux` | Estetica open-source pulita in stile GNOME / Adwaita | `#3584e4` |
| `minimal` | Icone minimaliste a tratto sottile (outline) | `#475569` |
| `vscode` | Icone nitide orientate agli sviluppatori in stile VS Code | `#dcb67a` |

### Passo dopo passo: creare un set di icone personalizzato

#### Passo 1: Crea una directory per le icone nel tuo progetto
Ad esempio, crea `assets/fm-icons/`. **Non devi copiare le icone che non vuoi modificare** — ogni icona mancante ricade automaticamente sui set integrati `classic` / `_ui` / `_folder_badges`!

```text
assets/fm-icons/
├── meta.json               # Colore predefinito cartelle: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Opzionale) Icona principale cartella personalizzata
├── pdf.svg                 # (Opzionale) Sovrascrive solo l'icona PDF
├── ui/                     # (Opzionale) Sovrascrive o aggiunge icone di toolbar, menu contestuale e volumi
│   ├── upload.svg          # Sovrascrive l'icona Carica
│   ├── new_folder.svg      # Sovrascrive l'icona Nuova cartella
│   └── company_cloud.svg   # Aggiunge una nuova icona volume -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Opzionale) Sovrascrive i badge esistenti o ne aggiunge di NUOVI
    ├── lock.svg            # Sovrascrive il badge integrato "lock"
    └── invoice.svg         # Aggiunge un NUOVO badge -> appare automaticamente nella modale Personalizza cartella!
```

#### Passo 2: Crea `meta.json` e un `folder.svg` con colore dinamico

> [!TIP]
> **Regola d'oro (`currentColor`):** per far sì che il tuo `folder.svg` personalizzato si adatti automaticamente ogni volta che un utente cambia il colore di una cartella, usa **`fill="currentColor"`** (o `stroke="currentColor"` per icone outline) invece di codici esadecimali fissi!

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/invoice.svg (Nuovo badge cartella personalizzato) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Passo 3: Punta il profilo YAML alla directory
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Archivio Aziendale'
            icon: 'company_cloud' # Usa assets/fm-icons/ui/company_cloud.svg!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Sicurezza e limiti delle icone personalizzate (`IconSetRegistry`)
- Ogni file `.svg` può pesare al massimo **256 KB** (`262,144` byte) e viene ripulito automaticamente tramite **`SvgSanitizer`** al caricamento (rimuovendo `<script>`, attributi `on*`, ecc.).
- Sia `ui/` che `badges/` analizzano fino a **200 file `.svg` personalizzati** oltre alle chiavi integrate.
- I nomi dei file personalizzati (escluso `.svg`) devono rispettare **`/^[a-zA-Z0-9_-]{1,32}$/`** (lettere ASCII, cifre, `-` e `_`, massimo 32 caratteri).

#### Riferimento completo delle chiavi icona sovrascrivibili
- **9 icone di file e cartelle (radice `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 icone UI (`<dir>/ui/<key>.svg`):**
  - *Navigazione e layout:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Azioni su file e cartelle:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Storage e provider cloud:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 badge integrati per cartelle (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(oltre a qualsiasi file `*.svg` personalizzato aggiunto!)*

---

## 5. Costruire un Tema Personalizzato (3 Livelli di Libertà)

`Browser.html.twig` risolve il valore `theme` configurato sul tuo profilo in questo **preciso ordine di priorità**:
1. Termina con `.html.twig` → Renderizza direttamente il tuo template Twig (`{% include theme %}`).
2. Inizia con `@` → Renderizza `layout.html.twig` all'interno di quel namespace Twig (`{% include theme ~ '/layout.html.twig' %}`).
3. Corrisponde a uno dei 9 temi integrati → Renderizza il tema integrato del bundle.
4. Qualsiasi altro identificatore breve (es. `'corporate'`, conforme a `/^[a-zA-Z0-9_-]{1,40}$/`) → Avvolge il layout di base predefinito in `<div data-liveux-theme="corporate">`.

### Livello 1: Tema del brand con pure variabili CSS (il più rapido — zero template!)

#### Riferimento completo delle CSS Custom Properties (`--liveux-fm-*`)
| Categoria | Variabili CSS |
| :--- | :--- |
| **Tipografia** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Superfici e bordi** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Colori del testo** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Colori di accento del brand** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Colori semantici di stato (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Raggi e ombre** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Tema brand personalizzato pronto al copia-incolla (`assets/styles/app.css`)
```css
/* 1) Palette brand per modalità chiara (Light Mode) */
[data-liveux-theme="corporate"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) Controparte Dark Mode (copre sia il Browser che il Picker FormType, in modalità auto e manuale) */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"],
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'corporate'
```

---

### Livello 2: Override parziali con Twig `{% extends %}` (consigliato!)

Il layout di base è strutturato attorno a **10 slot `{% block %}`** e **7 partial modulari (`parts/`)**. Puoi personalizzare solo la barra superiore o il footer senza duplicare l'intero template:

| Nome blocco | Ambito | Partial predefinito |
| :--- | :--- | :--- |
| `fm_shell` | Contenitore esterno principale (`.liveux-fm-shell`) | Avvolge tutti i blocchi annidati |
| `fm_topbar` | Barra comandi superiore (navigazione, breadcrumb, ricerca, ordinamento, azioni) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Menu impostazioni (modalità colore, dimensione elementi, stile cartelle, cache, scorciatoie) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Banner di notifica stato + coda di caricamento | Markup inline |
| `fm_body` | Area centrale principale (contenitore sidebar + main + inspector) | `.liveux-fm-body` |
| `fm_sidebar` | Albero cartelle a sinistra + cartelle fissate + barra quota + cestino | `parts/_sidebar.html.twig` |
| `fm_main` | Griglia/lista file + barra flottante selezione/appunti + dropzone + paginazione | `parts/_main.html.twig` |
| `fm_inspector` | Pannello destro di anteprima e metadati | `parts/_inspector.html.twig` |
| `fm_footer` | Barra footer per Modal / OffCanvas | `parts/_footer.html.twig` |
| `fm_overlays` | Menu contestuale, modale, lightbox e template di scorciatoie/info | `parts/_overlays.html.twig` |

#### Esempio — Intestazione brandizzata + footer di selezione personalizzato
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Hub Risorse Digitali Aziendali</span>
        <span>Profilo: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Annulla</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Conferma selezione</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) Percorso diretto a un template .html.twig:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) Oppure una directory namespace Twig (carica automaticamente layout.html.twig al suo interno):
                # theme: '@App/file_manager'
```

---

### Livello 3: Layout 100% Headless da zero (Target Stimulus richiesti)

Se desideri costruire la tua struttura HTML completamente da zero senza `{% extends %}`, collega semplicemente questi attributi `data-{{ ctrl }}-target` usando il prefisso `ctrl = 'liveux--file-manager-bundle--browser'`:

| Target (`data-...-target`) | Obbligatorio? | Scopo |
| :--- | :---: | :--- |
| `shell` | Consigliato | Scala dimensione elementi (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Obbligatorio** | Area principale in cui vengono renderizzate le card e le righe della lista di file/cartelle |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Obbligatorio** | Contenitore dialog per le modali Nuova cartella, Carica, Elimina, Personalizza, Quota e Metadati |
| `breadcrumbs` | Consigliato | Percorso breadcrumb cliccabile |
| `confirmSelectionBtn`, `footerStatus` | Consigliato (Modal/OffCanvas) | Pulsante di conferma selezione (`disabled` automaticamente quando nulla è selezionato) e indicatore di stato |
| `statusBanner`, `uploadQueue` | Consigliato | Avvisi di errore/successo e coda di avanzamento dei caricamenti a blocchi (chunked) |
| `tree` + `sidebar` + `pinnedFolders` | Opzionale | Albero delle cartelle a sinistra e cartelle fissate |
| `contextMenu` | Opzionale | Contenitore del menu contestuale (tasto destro) |
| `inspector` | Opzionale | Pannello destro di anteprima e metadati |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Opzionale | Lightbox universale di anteprima multimediale |

---

## 6. Le 7 funzioni helper Twig per template personalizzati

| Funzione Twig | Scopo | Esempio d'uso |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Renderizza un SVG inline sicuro contro XSS (non serve `\|raw`). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Genera un URL sicuro per la miniatura di un file. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Genera un URL sicuro per il download o l'anteprima inline. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Scarica</a>` |
| `liveux_fm_trans(key, params, locale)` | Traduce una chiave di messaggio del bundle con fallback garantito in `'en'`. | `{{ liveux_fm_trans('ui.upload', {}, 'it') }}` |
| `liveux_fm_bcp47_locale(locale)` | Converte codici locale PHP/ICU come `it_IT.UTF-8` in tag HTML `lang="..."` BCP-47 validi (`it-IT`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Restituisce `true` se la lingua indicata si legge da destra a sinistra (arabo, persiano, ebraico, ecc.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Normalizza in sicurezza i valori di Form/EasyAdmin in descrittori di immagini/file pronti al rendering (bloccando URL `javascript:`/`data:`). | Viste Index/Detail di EasyAdmin e template personalizzati |

---

[← Precedente: 03 — Modalità d'uso e API JS](03-usage-modes-and-js-api.md) | [Indice](README.md) | [Successivo: 05 — FormType e Integrazioni Editor →](05-formtype-and-editor-integrations.md)
