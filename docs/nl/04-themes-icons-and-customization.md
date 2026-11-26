# 04 — Thema's, Dark Mode, Map-aanpassingen, Iconensets & Maatwerkdesign

[← Vorige: 03 — Gebruiksmodi & JS API](03-usage-modes-and-js-api.md) | [Overzicht](README.md) | [Volgende: 05 — FormType & Editor-integraties →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle is gebouwd met **100% nul afhankelijkheden van CSS-frameworks**. Of je project nu **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** of **helemaal geen CSS-framework** gebruikt: alles werkt direct uit de doos zonder extra buildstappen (geen Tailwind `@source`- of purge-configuratie nodig).

> **In één oogopslag: wat leer je in deze gids?**  
> 1. **9 ingebouwde thema's & Dark Mode:** Laat de bestandsbeheerder met één YAML-optie naadloos aansluiten op het ontwerp en kleurenschema van je applicatie.  
> 2. **Mapkleuren & mapiconen (badges):** Personaliseer mappen vanuit de UI of programmatisch via PHP/DB met 18+ kleuren en 14 ingebouwde badges (plus een onbeperkt aantal eigen SVG-badges).  
> 3. **Je eigen maatwerk-iconenset maken:** Stapsgewijze handleiding voor het samenstellen van een eigen iconenpakket voor 9 bestandsiconen, 49 UI-iconen en onbeperkte eigen mapbadges.  
> 4. **Een eigen thema bouwen (3 niveaus van vrijheid):** Huisstijl toepassen met pure CSS-variabelen, gedeeltelijke Twig-overrides via `{% extends %}` of een 100% headless lay-out vanaf nul.

---

## 1. 9 ingebouwde thema's (Welk thema kies je?)

Thema's kunnen worden ingesteld via `defaults.theme`, `profiles.<name>.ui.theme`, de `theme`-prop van het TwigComponent of de `theme`-optie van `FileManagerPickerType`. **Alle 9 thema's zijn getest op WCAG 2.1 AA-contrastnormen in zowel de lichte als de donkere modus.**

| Themasleutel | Meest geschikt voor | Ontwerpsamenvatting |
| :--- | :--- | :--- |
| `bootstrap5` *(standaard)* | Bootstrap 5 en klassieke Symfony-adminpanels | Klassiek blauw (`#0d6efd`), `10px / 8px / 6px` afgeronde hoeken, strakke neutrale randen |
| `tailwind` | Moderne webapps gebouwd met Tailwind CSS v3/v4 | Indigo-accent (`#6366f1`), zachte `14px / 12px / 8px` afrondingen, Slate-palet |
| `shadcn` | Minimalistische, monochrome zwart-wit SaaS-dashboards | Monochrome Zinc-oppervlakken (`#18181b` / `#fafafa` in dark mode), strakke `8px / 6px` hoeken |
| `flowbite` | Adminpanels met Flowbite 4 + Tailwind CSS | Flowbite-blauw (`#1a56db`) & Gray-50/800/900-oppervlakken, `8px` bedieningselementen |
| `daisyui` | Projecten gebouwd met DaisyUI 5 | **Erft automatisch** `--color-primary`, `--color-base-100/200/300` en `--radius-box` zodra DaisyUI op de pagina is geladen |
| `tabler` | Tabler.io of zakelijke EasyAdmin-backends | Zakelijk slate-canvas, `#066fd1` blauw accent, scherpe `4px / 6px` hoeken |
| `preline` | Preline UI-applicaties | Puur neutrale oppervlakken (`#fafafa` / `#171717`) met helderblauw (`#2563eb`) accent |
| `material` | Google Material Design 3-applicaties | Tonale paarse oppervlakken (`#6750a4` / `#f3edf7`) en volledig afgeronde pilknoppen |
| `unstyled` | De bestandsbeheerder volledig stylen met je eigen CSS | Erft het lettertype en transparante oppervlakken van de host-app, met behoud van dekkend `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text`-contrast voor popovers en lightboxes |

| Dark Mode (`bootstrap5` + Dark) | DaisyUI 5 Theme Bridge (`daisyui`) |
| :---: | :---: |
| ![Bestandsbeheerder in Dark Mode](../images/browser-dark.png) | ![DaisyUI 5-thema showcase](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Beveiligingsregel (YAML vs. runtime `theme`):**  
> - In `liveux_file_manager.yaml` kun je elk van de 9 ingebouwde thema's instellen, een eigen CSS-themanaam (bijv. `'corporate'`, passend bij `/^[a-zA-Z0-9_-]{1,40}$/`), of een eigen Twig-templatepad (bijv. `'file_manager/custom.html.twig'` of `'@App/file_manager'`).  
> - Uit beveiligingsoverwegingen accepteren dynamische `theme`-waarden uit de browser, URL-queryparameters of `window.LiveUXFileManager.open({ theme: ... })` **uitsluitend de 9 ingebouwde themasleutels** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Dark Mode, RTL & Verminderde beweging

Alle 9 thema's (inclusief `unstyled`) ondersteunen **zowel automatische als handmatige Dark Mode**:

### A) Automatische host-detectie (`color_scheme: 'auto'`)
Zodra een van de volgende kenmerken actief is op je pagina (`<html>` of `<body>`), schakelen de bestandsbeheerder, de modale `<dialog>` en `FileManagerPickerType`-velden direct over naar de donkere modus:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- `.dark`-klasse *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / eigen thema's)*

### B) Standaard- of profielspecifiek kleurenschema via YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (volg host) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Start alleen dit profiel in dark mode
```

### C) Voorkeur van de eindgebruiker (Instellingenmenu: `Auto / Licht / Donker`)
Gebruikers kunnen met één klik wisselen tussen **Auto / ☀ Licht / 🌙 Donker** via de popover **⚙ Instellingen** in de bovenste werkbalk. Hun keuze wordt bewaard in de `localStorage` van de browser (`liveux_fm_color_scheme`).

### D) Globale RTL-ondersteuning & `prefers-reduced-motion`
- `dir="rtl"` wordt automatisch toegepast voor 15 rechts-naar-links-talen, waaronder Arabisch (`ar`), Perzisch (`fa`), Hebreeuws (`he`) en Urdu (`ur`). Alle marges en paddings in `core.css` gebruiken logische CSS-eigenschappen (`inset-inline`, `padding-inline`, `border-inline-end`).
- Animaties worden automatisch tot een minimum beperkt voor gebruikers die *"Verminder beweging"* (`prefers-reduced-motion: reduce`) hebben ingeschakeld in hun besturingssysteem.

---

## 3. Mapkleuren & mapiconen (badges) toewijzen (Stap voor stap)

![Modal voor mapkleurenpalet en badge-aanpassing](../images/folder-customize.png)

Je kunt aan elke map een eigen **kleur**, **badge-icoon** en **vastgezette status (⭐ Vastzetten)** toekennen. Deze functies zijn actief wanneer `defaults.metadata.enabled: true` is ingesteld en de gebruiker over de permissie `permissions.edit_metadata` beschikt.

### Methode 1: Vanuit de UI (Voor eindgebruikers)
1. **Klik met de rechtermuisknop** op een map en kies **Aanpassen** (of druk op **`I`** terwijl een map geselecteerd is, of klik op **Aanpassen** in het Inspector-paneel aan de rechterkant).
2. In de modal **Aanpassen** kun je drie opties instellen met directe live preview:
   - **Kleurenpalet met 18 voorinstellingen:** Blauw (`#2563eb`), Hemelsblauw (`#0284c7`), Cyaan (`#0891b2`), Groenblauw (`#0d9488`), Smaragd (`#059669`), Groen (`#16a34a`), Limoen (`#65a30d`), Amber (`#d97706`), Oranje (`#ea580c`), Rood (`#dc2626`), Rozerood (`#e11d48`), Roze (`#db2777`), Fuchsia (`#c026d3`), Paars (`#9333ea`), Violet (`#7c3aed`), Indigo (`#4f46e5`), Leigrijs (`#475569`), Antraciet (`#1e293b`).
   - **Eigen kleurkiezer + HEX-invoer:** Kies elke gewenste huisstijlkleur via de browser-kleurkiezer of typ rechtstreeks een `#RRGGBB`-hexcode in. Klik op **Standaard herstellen** om aangepaste styling te wissen.
   - **Raster met mapiconen (badges):** Toont alle **14 ingebouwde badges** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`) samen met eventuele eigen SVG-bestanden uit de map `badges/` van je iconenset.
3. Klik op **Opslaan** — de map wordt direct bijgewerkt in zowel de Raster-/Lijstweergave als de mappenstructuur aan de linkerkant.

### Methode 2: Programmatisch vanuit PHP (bijv. bij het aanmaken van een nieuwe klantenmap)
Met `FolderMetadataStorageInterface` en `PathNormalizer::buildCanonicalKey()` kun je kleuren, badges en vastgezette mappen rechtstreeks vanuit PHP-code toewijzen (exact dezelfde code werkt probleemloos met zowel de `json_file`- als de `doctrine`-driver):

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

        // Bouw de canonieke sleutel op (bijv. "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Groenblauw (#RRGGBB)
            icon: 'lock',       // Een van de 14 ingebouwde badges of eigen badges/invoice.svg -> 'invoice'
            pinned: true,       // Toevoegen aan ⭐ Vastgezette mappen in de zijbalk
        ));
    }
}
```

### Methode 3: Rechtstreeks in `metadata.json` (bij gebruik van `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Iconensets & je eigen iconenset vanaf nul maken

**Elk icoon** in de bundle — bestandstypen, mappen, bovenste werkbalk, contextmenu, clouddisk-indicatoren, inspector-paneel en mapbadges — wordt geleverd vanuit één centrale `IconSetRegistry`. De 6 ingebouwde iconensets worden 24 uur lang gecachet via PSR-6 (`liveux_fm_iconset_*`).

### 6 ingebouwde iconensets (`profiles.<name>.ui.icon_set`)
| Iconenset | Visuele stijl | Standaard mapkleur (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(standaard)* | Strakke, vlakke vectoriconen | `#2563eb` |
| `windows11` | Warmgele tweekleurige mappen in Windows 11-stijl en moderne documenticonen | `#f59e0b` |
| `macos` | Zachtblauwe mappen geïnspireerd op macOS Finder | `#3b82f6` |
| `linux` | Heldere open-source GNOME / Adwaita-uitstraling | `#3584e4` |
| `minimal` | Minimalistische dunne lijniconen (outline) | `#475569` |
| `vscode` | Scherpe, op ontwikkelaars gerichte iconen in VS Code-stijl | `#dcb67a` |

### Stap voor stap: je eigen maatwerk-iconenset maken

#### Stap 1: Maak een iconenmap aan in je project
Maak bijvoorbeeld `assets/fm-icons/` aan. **Je hoeft geen iconen te kopiëren die je niet wilt aanpassen** — elk ontbrekend icoon valt automatisch terug op de ingebouwde `classic` / `_ui` / `_folder_badges`-sets!

```text
assets/fm-icons/
├── meta.json               # Standaard mapkleur: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Optioneel) Eigen hoofd-mapicoon
├── pdf.svg                 # (Optioneel) Overschrijf alleen het PDF-icoon
├── ui/                     # (Optioneel) Overschrijf of voeg werkbalk-, contextmenu- en volume-iconen toe
│   ├── upload.svg          # Overschrijf het Upload-icoon
│   ├── new_folder.svg      # Overschrijf het Nieuwe map-icoon
│   └── company_cloud.svg   # Voeg een eigen volume-icoon toe -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Optioneel) Overschrijf bestaande mapbadges of voeg NIEUWE toe
    ├── lock.svg            # Overschrijf de ingebouwde "lock"-badge
    └── invoice.svg         # Voeg een NIEUWE badge toe -> verschijnt automatisch in de modal Map aanpassen!
```

#### Stap 2: Maak `meta.json` en een `folder.svg` met dynamische kleur

> [!TIP]
> **Gouden regel (`currentColor`):** Gebruik in je eigen `folder.svg` altijd **`fill="currentColor"`** (of `stroke="currentColor"` voor lijniconen) in plaats van vaste hexcodes, zodat de kleur automatisch meeverandert zodra een gebruiker een map een andere kleur geeft!

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
<!-- assets/fm-icons/badges/invoice.svg (Nieuwe eigen mapbadge) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Stap 3: Verwijs in je YAML-profiel naar deze map
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Bedrijfsarchief'
            icon: 'company_cloud' # Gebruikt assets/fm-icons/ui/company_cloud.svg!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Beveiliging & limieten voor eigen iconen (`IconSetRegistry`)
- Elk `.svg`-bestand mag maximaal **256 KB** (`262.144` bytes) groot zijn en wordt bij het laden automatisch opgeschoond via **`SvgSanitizer`** (verwijdert `<script>`, `on*`-handlers, enz.).
- Zowel `ui/` als `badges/` scannen naast de ingebouwde sleutels maximaal **200 eigen `.svg`-bestanden**.
- Eigen bestandsnamen (zonder `.svg`) moeten voldoen aan **`/^[a-zA-Z0-9_-]{1,32}$/`** (ASCII-letters, cijfers, `-` en `_`, maximaal 32 tekens).

#### Volledig overzicht van overschrijfbare icon-sleutels
- **9 bestands- & mapiconen (hoofdmap `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 UI-iconen (`<dir>/ui/<key>.svg`):**
  - *Navigatie & lay-out:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Bestands- & mapacties:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Opslag & cloudproviders:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 ingebouwde mapbadges (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(plus alle eigen `*.svg`-bestanden die je toevoegt!)*

---

## 5. Je eigen maatwerkthema bouwen (3 niveaus van vrijheid)

`Browser.html.twig` verwerkt de `theme`-waarde van je profiel in deze **exacte prioriteitsvolgorde**:
1. Eindigt op `.html.twig` → Rendert je Twig-template direct (`{% include theme %}`).
2. Begint met `@` → Rendert `layout.html.twig` binnen die Twig-namespace (`{% include theme ~ '/layout.html.twig' %}`).
3. Komt overeen met een van de 9 ingebouwde thema's → Rendert het ingebouwde thema van de bundle.
4. Elke andere korte naam (bijv. `'corporate'`, passend bij `/^[a-zA-Z0-9_-]{1,40}$/`) → Omsluit de standaard basislay-out met `<div data-liveux-theme="corporate">`.

### Niveau 1: Huisstijlthema met pure CSS-variabelen (Snelst — nul templates!)

#### Volledig overzicht van CSS Custom Properties (`--liveux-fm-*`)
| Categorie | CSS-variabelen |
| :--- | :--- |
| **Typografie** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Oppervlakken & randen** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Tekstkleuren** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Accentkleuren (huisstijl)** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Semantische statuskleuren (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Hoekradii & schaduwen** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Kant-en-klaar eigen huisstijlthema (`assets/styles/app.css`)
```css
/* 1) Huisstijlpalet voor de lichte modus */
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

/* 2) Dark Mode-tegenhanger (werkt voor zowel Browser als FormType Picker, auto & handmatige dark mode) */
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

### Niveau 2: Gedeeltelijke overrides met Twig `{% extends %}` (Aanbevolen!)

De basislay-out is opgebouwd rond **10 `{% block %}`-slots** en **7 modulaire partials (`parts/`)**. Zo pas je bijvoorbeeld alleen de bovenste balk of voettekst aan zonder het volledige template te hoeven dupliceren:

| Bloknaam | Bereik | Standaard partial |
| :--- | :--- | :--- |
| `fm_shell` | Buitenste shell-wrapper (`.liveux-fm-shell`) | Omsluit alle geneste blokken |
| `fm_topbar` | Bovenste commandobalk (navigatie, broodkruimelpad, zoeken, sorteren, acties) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Instellingenmenu (kleurmodus, itemgrootte, mapstijl, cache, sneltoetsen) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Statusmeldingsbalk + uploadwachtrij | Inline markup |
| `fm_body` | Hoofdmiddengedeelte (zijbalk + hoofdgebied + inspector-wrapper) | `.liveux-fm-body` |
| `fm_sidebar` | Mappenboom links + vastgezette mappen + quotumbalk + prullenbak | `parts/_sidebar.html.twig` |
| `fm_main` | Bestandsraster/-lijst + zwevende selectie-/klembordbalk + dropzone + paginering | `parts/_main.html.twig` |
| `fm_inspector` | Preview- en metadatapaneel aan de rechterkant | `parts/_inspector.html.twig` |
| `fm_footer` | Voettekstbalk voor Modal / OffCanvas | `parts/_footer.html.twig` |
| `fm_overlays` | Contextmenu, modal, lightbox en templates voor sneltoetsen/over | `parts/_overlays.html.twig` |

#### Voorbeeld — Eigen headerbalk in huisstijl + aangepaste selectie-voettekst
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Bedrijfsmediabibliotheek</span>
        <span>Profiel: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Annuleren</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Selectie bevestigen</button>
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
                # 1) Direct .html.twig-templatepad:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) Of een Twig-namespacemap (laadt automatisch layout.html.twig daarbinnen):
                # theme: '@App/file_manager'
```

---

### Niveau 3: 100% headless lay-out vanaf nul (Vereiste Stimulus-targets)

Wil je een volledig eigen HTML-structuur opbouwen zonder `{% extends %}`? Koppel dan simpelweg deze `data-{{ ctrl }}-target`-attributen met het voorvoegsel `ctrl = 'liveux--file-manager-bundle--browser'`:

| Target (`data-...-target`) | Vereist? | Doel |
| :--- | :---: | :--- |
| `shell` | Aanbevolen | Schaling van itemgrootte (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Verplicht** | Hoofdcanvas waarin bestands-/mapkaarten en lijstregels worden gerenderd |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Verplicht** | Dialoogcontainer voor Nieuwe map, Uploaden, Verwijderen, Aanpassen, Quotum en Metadata |
| `breadcrumbs` | Aanbevolen | Klikbaar broodkruimelpad |
| `confirmSelectionBtn`, `footerStatus` | Aanbevolen (Modal/OffCanvas) | Bevestigknop voor selectie (automatisch `disabled` als er niets geselecteerd is) en statusweergave |
| `statusBanner`, `uploadQueue` | Aanbevolen | Fout-/succesmeldingen en voortgangswachtrij voor chunked uploads |
| `tree` + `sidebar` + `pinnedFolders` | Optioneel | Mappenboom links en vastgezette mappen |
| `contextMenu` | Optioneel | Container voor het rechtermuisknop-contextmenu |
| `inspector` | Optioneel | Preview- en metadatapaneel aan de rechterkant |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Optioneel | Universele media-preview lightbox |

---

## 6. 7 Twig-helperfuncties voor maatwerktemplates

| Twig-functie | Doel | Voorbeeldgebruik |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Rendert een XSS-veilige inline SVG (geen `\|raw` nodig). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Genereert een veilige thumbnail-URL voor een bestand. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Genereert een veilige download- of inline preview-URL. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Downloaden</a>` |
| `liveux_fm_trans(key, params, locale)` | Vertaalt een bundle-berichtsleutel met een gegarandeerde `'en'`-fallback. | `{{ liveux_fm_trans('ui.upload', {}, 'nl') }}` |
| `liveux_fm_bcp47_locale(locale)` | Zet PHP/ICU-localecodes zoals `nl_NL.UTF-8` om in geldige HTML `lang="..."` BCP-47-tags (`nl-NL`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Retourneert `true` als de opgegeven locale rechts-naar-links is (Arabisch, Perzisch, Hebreeuws, enz.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Normaliseert Form-/EasyAdmin-waarden veilig naar renderbare afbeelding-/bestandsdescriptors (blokkeert `javascript:`/`data:`-URL's). | EasyAdmin Index-/Detail-weergaven en maatwerktemplates |

---

[← Vorige: 03 — Gebruiksmodi & JS API](03-usage-modes-and-js-api.md) | [Overzicht](README.md) | [Volgende: 05 — FormType & Editor-integraties →](05-formtype-and-editor-integrations.md)
