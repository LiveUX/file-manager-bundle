# 03 — Gebruiksmodi & JavaScript API

[← Vorige: 02 — Configuratie & profielen](02-configuration-and-profiles.md) | [Documentatie-overzicht](README.md) | [Volgende: 04 — Thema's, pictogramsets & maatwerk →](04-themes-icons-and-customization.md)

Je kunt LiveUX FileManagerBundle **inline** in elke pagina inbedden, openen in een gecentreerde **Modal**, laten inschuiven als 4-richtingen **OffCanvas**-paneel of programmatisch aanroepen vanaf elke plek met de **JavaScript Promise API**.

> [!TIP]
> **Welke modus past het best?**  
> - **Inline:** Speciale *"Mediabibliotheek"*-pagina's waar het bestandsbeheer het volledige hoofdgedeelte vult.  
> - **Modal:** Gecentreerd dialoogvenster geopend via een knop *"Afbeelding kiezen"* tijdens het bewerken van een product of artikel — sluit zodra de gebruiker een bestand kiest.  
> - **OffCanvas:** Modern inschuifpaneel vanaf elke rand (`start`, `end`, `top`, `bottom`) dat je formulier op de achtergrond zichtbaar houdt.  
> - **JavaScript API (`window.LiveUXFileManager.open()`):** Open de kiezer vanaf elke eigen HTML-knop, React/Vue/Alpine-component of vanilla JS-workflow zonder Symfony Forms.

---

## 1. Inline-modus — TwigComponent

![Inline weergave van het bestandsbeheer](../images/browser-light.png)

Een volledig inline bestandsbeheer inbedden kost één Twig-aanroep. Wikkel de component in een container met een expliciete hoogte (zoals `680px` of `calc(100vh - 140px)`):

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Of met de `<twig:...>`-componenttags van Symfony UX:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="nl"
    />
</div>
```

### Alle `Browser`-componentprops

| Prop | Type | Standaard | Beschrijving |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Profielsleutel gedefinieerd in `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Initiële volumesleutel (`null` opent het eerste volume van het profiel). |
| `path` | `string` | `''` | Initiële submap (bijv. `'products/2026'`). |
| `multiple` | `bool` | `false` | Schakelt meervoudige bestandsselectie in. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` of `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Initieel typefilter: `'all'`, `'folder'`, `'image'`, `'doc'` of `'video'`. |
| `theme` | `?string` | `null` | Runtime thema-overschrijving (accepteert de 9 ingebouwde thema's: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Eigen CSS-/Twig-thema's worden in YAML geconfigureerd. |
| `iconSet` | `?string` | `null` | Runtime pictogramset-overschrijving (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Eigen SVG-mappen worden in YAML geconfigureerd. |
| `locale` | `?string` | `null` | Runtime locale-overschrijving uit alle **12 ingebouwde UI-talen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) of elke BCP-47-/POSIX-locale (bijv. `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal & OffCanvas — Programmatische JavaScript Promise API

`DialogHost` wordt automatisch geïnjecteerd in elke volledige HTML-response (`defaults.auto_inject_dialog_host: true`). Je kunt `window.LiveUXFileManager.open()` met `async/await` vanaf elke pagina aanroepen.

### Alle `open(options)`-opties

| Optie | Type | Standaard | Beschrijving |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Naam van het profiel om te openen. |
| `volume` | `?string` | `null` | Initiële opslagvolumesleutel. |
| `path` | `string` | `''` | Initieel mappad (bijv. `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Gecentreerd modaal dialoogvenster of inschuivend OffCanvas-paneel. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Rand vanwaar het paneel inschuift bij `mode: 'offcanvas'` (`start` = links, `end` = rechts; wordt automatisch gespiegeld in RTL-locales). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(profiel)* | Overschrijft de weergave op mobiele schermen (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = enkel bestand, `true` = meerdere bestanden. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Opent vooraf gefilterd op een specifieke bestandscategorie (`filetype` is een TinyMCE-compatibiliteitsalias; `'media'` wordt automatisch gekoppeld aan `'video'`). |
| `theme` | `?string` | `null` | Runtime overschrijving voor een van de 9 ingebouwde thema's (eigen Twig-templatepaden blijven om veiligheidsredenen beperkt tot server-side YAML). |
| `iconSet` | `?string` | `null` | Runtime overschrijving voor een van de 6 ingebouwde pictogramsets. |
| `locale` | `?string` | `null` | Runtime locale-overschrijving (`'nl'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, `'ja'`, `'zh_CN'`, enz.). |

### Praktijkvoorbeeld: verborgen `<input>` + live afbeeldingsvoorbeeld (zonder Symfony Form)

Plak dit fragment in een willekeurige HTML- of Twig-pagina en het werkt direct:

```html
<!-- 1. Verborgen veld + live voorbeeld + triggerknop -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Voorbeeld" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Omslagafbeelding kiezen</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Direct vooraf gefilterd op afbeeldingen openen
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // Gebruiker drukte op Esc of sloot het venster zonder iets te selecteren
    if (selected.length === 0) {
        return;
    }

    const file = selected[0];
    document.getElementById('cover-url').value = file.url;

    const img = document.getElementById('cover-preview');
    if (file.isImage) {
        img.src = file.url;
        img.alt = file.meta?.alt || file.name;
        img.style.display = 'block';
    }
});
</script>
```

### Snelle modusfragmenten

```html
<!-- Gecentreerde Modal -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Modal openen</button>

<!-- Rechts inschuivend paneel (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Rechterpaneel</button>

<!-- Onderlangs inschuivende sheet (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Onderpaneel</button>
```

> [!NOTE]
> **Gedrag bij annuleren:** Als de gebruiker het dialoogvenster sluit zonder een bestand te kiezen (`Esc`, sluitknop of klik op de achtergrond), **reject de Promise niet** — deze resolvet netjes naar een lege array (`[]`).
>
> **Mobiele responsiviteit (`mobileMode` / `ui.mobile_dialog_mode`):** Op viewports van `<= 767.98px` past het dialoogvenster zich automatisch aan: in `'auto'`-modus openen modals en zijpanelen (`start`/`end`) schermvullend (`100dvh`), terwijl `bottom`/`top`-panelen openen als afgeronde native sheets met sleepgreep. De linker mappenstructuur start ingeklapt op mobiel en schuift uit na een tik.

| Desktop OffCanvas-paneel (`position: 'end'`) | Mobiele bottom sheet (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Desktop OffCanvas-weergave](../images/offcanvas-desktop.png) | ![Mobiele sheet-weergave](../images/mobile-sheet.png) |

### Structuur van geretourneerde bestandsobjecten

`window.LiveUXFileManager.open()` resolvet naar een array van objecten met de volgende structuur:

```json
[
    {
        "kind": "file",
        "path": "products/matte-black-tumbler.webp",
        "url": "/uploads/products/matte-black-tumbler.webp",
        "name": "matte-black-tumbler.webp",
        "extension": "webp",
        "isImage": true,
        "meta": {
            "alt": "Matzwarte geïsoleerde roestvrijstalen thermosbeker",
            "title": "Tumbler Pro",
            "caption": "500 ml vacuümgeïsoleerde drinkfles",
            "tags": ["thermosbeker", "drinkwaren"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Opmerking: op `private: true`-volumes verwijst `url` naar het RBAC-beveiligde `/_liveux-file-manager/download?...&inline=1`-endpoint.)*

### Custom DOM-events

Naast `await window.LiveUXFileManager.open()` kun je ook rechtstreeks op `window` luisteren naar levenscyclusevents van het dialoogvenster:

| DOM-event (`window.addEventListener`) | Wanneer gevuurd | `event.detail`-payload |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Wanneer een openverzoek voor het dialoogvenster wordt verzonden (brug tussen `file_manager_api.js` en `dialog_controller.js`). | `open()`-opties + `requestId` |
| `liveux:file-manager:dialog-opened` | Zodra het dialoogvenster is geopend en de inhoud is gemount. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Wanneer de gebruiker een selectie bevestigt (of het venster annuleert). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Aantal geselecteerde items:', event.detail.items.length);
});
```

---

## 3. Ingebouwde eindgebruikersfuncties

- **Compacte contextmenu's:**
  - *Op een bestand of map:* Eenrijige SVG-snelactiebalk in Windows 11-stijl (`Knippen`, `Kopiëren`, `Naam wijzigen`, `Downloaden`/`Vastmaken aan Snelle toegang`, `Verwijderen`) gevolgd door gegroepeerde één-klik-acties (Voorbeeld, Afbeelding bewerken, Info & tags / SEO & focuspunt, Archief uitpakken, Link kopiëren, Deellink maken, Verplaatsen naar map…, Dupliceren, Mapkleur & mappictogram).
  - *Op lege ruimte:* Snelle navigatiebalk bovenaan (`← → ↑ ↻`), Nieuwe map, Hier uploaden, Hier plakken, Alles selecteren, Sorteren op en Items per pagina. *(Volledig scherm, Sneltoetsen en Over vind je in het menu **⚙ Instellingen** in de werkbalk.)*
  - *Mobiel / Touch:* Opent als een aanraakvriendelijke bottom sheet via de knop `⋯` op elke kaart.
- **Universele voorbeeldweergave (Lightbox v2) & rechterpaneel Voorbeeld & details:**
  - **Afbeeldingen:** Galerijnavigatie (`←`/`→`), zoomknoppen (`-` / `100%` / `+`) en focuspuntindicator.
  - **Video (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** Live `<video controls>`-speler in Voorbeeld & details + schermvullende Lightbox + `HTTP 206 Partial Content`-spoelen op privévolumes (per profiel schakelbaar via `ui.allow_video_preview`).
  - **PDF (`.pdf`):** Gesandboxte `<iframe>` PDF-viewer in de Lightbox (schakelbaar via `ui.allow_pdf_preview`).
  - **Audio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) & Tekst/Code (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql`, enz. — limiet van 64 KB).**
  - In `inline`-modus opent **dubbelklikken** op een ondersteund bestand (of drukken op `Spatie`) direct de Lightbox.
- **Slimme uploadwachtrij (Pauzeren / Hervatten / Bevestigd annuleren):**
  - Upload losse bestanden **of complete mapstructuren** (`webkitGetAsEntry`) via drag-and-drop, of plak afbeeldingen direct vanaf het klembord met `Ctrl+V`.
  - Uploads in delen tonen live deeltellers (`2/5 • 40%`), één-klik **Pauzeren (`⏸`) / Hervatten (`▶`)**, met bevestiging beveiligd **Annuleren (`✕`)**, batchacties **Alles pauzeren / Alles annuleren** en automatische server-side opschoning van tijdelijke onvolledige delen bij annulering.
- **Meervoudige selectie (incl. lassoselectie), klembord & diep zoeken:**
  - Klik en sleep op lege ruimte om een **selectiekader (marquee/lasso)** rond meerdere bestanden en mappen te trekken (houd `Ctrl`/`Cmd` ingedrukt om aan de huidige selectie toe te voegen).
  - `Shift+Klik` voor bereikselectie, `Ctrl+Klik` om items aan/uit te zetten, `Ctrl+A` voor alles selecteren en `Ctrl+C`/`Ctrl+X`/`Ctrl+V` tussen mappen en zelfs tussen verschillende volumes.
  - **Sorteerbare kolomkoppen in lijstweergave:** Klik op de koppen **Naam**, **Type**, **Grootte** of **Datum** in de Lijst-weergave om oplopend/aflopend te sorteren.
  - **Live schuifregelaar voor kaartgrootte & mapkaarten:** Pas de itemgrootte live aan (`96 px – 320 px`) en wissel tussen compacte maptegels (`tile`) en volwaardige mapkaarten (`card`) vanuit het menu Instellingen (`⚙`).
  - Schakel de knop voor diep zoeken in de zoekbalk in om recursief in alle submappen te zoeken, inclusief `#tags` en `alt`-metadata.
  - **⭐ Snelle toegang** voor vastgemaakte mappen in de zijbalk, klikbaar venster **Opslaggebruik & quotumdetails** en kleurmodusschakelaar **Automatisch / Licht / Donker**.
- **0 ms validatie van afbeeldingsresolutie & beeldverhouding (`image_constraints`):** Wanneer resolutie- of verhoudingsregels (`1:1`, `16:9`, enz.) zijn geconfigureerd, worden niet-conforme afbeeldingen direct in de browser geweigerd vóór het uploaden (en server-side opnieuw gecontroleerd).
- **Redactionele metadata, focuspunt & ondertekende deellinks:**
  - Bewerk `alt`, `title`, `caption`, `#tags`, klikbare `[x, y]`-**Focuspunt**-kiezer en optioneel **✨ Voorstellen met AI**.
  - Genereer tijdelijke HMAC-ondertekende deellinks (`15 minuten`, `1 uur`, `24 uur`, `7 dagen`, `30 dagen`).
- **Ingebouwde Canvas-afbeeldingseditor:** Vrij bijsnijden of op vaste verhouding (`1:1`, `4:3`, `16:9`), verkleinen in pixels (`Max. B`/`Max. H`), uitvoerindeling (`JPEG`/`PNG`/`WebP`) & kwaliteit, 90° draaien, horizontaal/verticaal spiegelen en Opslaan als kopie / Overschrijven.

| Bestandsmetadata & focuspunt (`I`) | Ingebouwde Canvas-afbeeldingseditor (`E`) |
| :---: | :---: |
| ![Bestandsmetadata en focuspunt](../images/file-metadata.png) | ![Ingebouwde Canvas-afbeeldingseditor](../images/image-editor.png) |

- **Wereldwijde i18n, RTL & toegankelijkheid (WCAG 2.1 AA):**
  - **12 ingebouwde UI-talen & automatische RTL (`dir="rtl"`):** Ondersteunt `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` en `zh_CN`. Arabisch (`ar`), Perzisch (`fa`), Hebreeuws (`he`), Urdu (`ur`) en andere RTL-locales spiegelen layouts, kruimelpaden, zijbalken en OffCanvas-randen automatisch met logische CSS (`inset-inline`, `margin-inline`).
  - **CJK / IME-veiligheid:** Voorkomt dat `Enter` of sneltoetsen te vroeg afvuren tijdens het samenstellen van Chinese, Japanse of Koreaanse tekst (`event.isComposing`).
  - **Volledige toetsenbord- & schermlezertoegankelijkheid:** Focus-trapping (`Tab`/`Shift+Tab`) in modals en Lightbox, pijltjestoetsnavigatie in contextmenu's, `role="tree"` / `aria-expanded` / `aria-current`-semantiek en automatisch focusherstel bij het sluiten.
- **Sneltoetsen (volledig overzicht):**
  - Alle sneltoetsen werken alleen wanneer het bestandsbeheer focus heeft, buiten tekstvelden (`<input>`, `<textarea>`, `<select>`), en respecteren strikt de **Voter-rechten** van de gebruiker (`ui.keyboard_shortcuts: true`).
  - Op macOS werkt **`Cmd (⌘)`** naadloos naast `Ctrl`.
  - **Internationale toetsenbord- & IME-bescherming:** Gebruikt fysieke toetscodes (`event.code` `Key*`) met fallback-tekenmapping zodat sneltoetsen werken op niet-QWERTY- en niet-Latijnse toetsenbordindelingen, terwijl ze stil blijven tijdens CJK IME-compositie (`isComposing`).

#### A. Bestanden & mappen
| Sneltoets | Actie | Voorwaarde / opmerking |
| :--- | :--- | :--- |
| `N` | Nieuwe map aanmaken | Buiten de Prullenbak (`create_folder`-recht) |
| `U` | Uploadvenster openen | Buiten de Prullenbak (`upload`-recht) |
| `F2` | Naam van item wijzigen | Eén bestand of map geselecteerd (`rename`-recht) |
| `M` | Venster Verplaatsen / Kopiëren openen | 1 of meer items geselecteerd (`move`- of `copy`-recht) |
| `D` | Bestand dupliceren | **Eén bestand** geselecteerd (`clone`-recht) |
| `I` | **Map:** Mapkleur & pictogram / **Bestand:** Info / SEO & focuspunt | Eén item geselecteerd (`edit_metadata`-recht) |
| `E` | Ingebouwde Canvas-afbeeldingseditor openen | Eén rasterafbeelding (`.jpg`, `.jpeg`, `.png`, `.webp`) geselecteerd (`upload`-recht) |
| `L` | Bestandslink kopiëren naar klembord | Eén bestand geselecteerd (`download`-recht) |
| `S` | Venster voor tijdelijke HMAC-deellink openen | Eén bestand geselecteerd (`share`-recht + `share.enabled: true`) |
| `Delete` | Geselecteerde items verwijderen (prullenbak of definitief) | 1 of meer items geselecteerd (`delete`-recht) |

#### B. Navigatie & voorbeeldweergave
| Sneltoets | Actie | Voorwaarde / opmerking |
| :--- | :--- | :--- |
| `/` of `Ctrl + F` | Zoekveld focussen | Wanneer `show_search: true` (`Ctrl + F` werkt ook vanuit invoervelden) |
| `←` `→` `↑` `↓` | Navigeren tussen rasterkaarten of lijstregels | — |
| `Enter` | **Map:** openen • **Enkel bestand:** Lightbox openen in Inline-modus, of selectie bevestigen in Modal-/OffCanvas-modus | — |
| `Spatie` | Voorbeeld van geselecteerd bestand in Lightbox | Eén bestand geselecteerd |
| `Backspace` of `Alt + ↑` | Naar bovenliggende map | In een submap |
| `Alt + ←` / `Alt + →` | Terug / Vooruit navigeren in geschiedenis | Automatisch gespiegeld in **RTL**-locales (Arabisch, Perzisch, Hebreeuws) |
| `R` | Huidige map vernieuwen vanaf de server (`?refresh=1`) | Wanneer `show_refresh_button: true` |

#### C. Selectie, klembord & lagen sluiten
| Sneltoets | Actie | Voorwaarde / opmerking |
| :--- | :--- | :--- |
| `Ctrl + A` | Alle zichtbare items selecteren | Buiten de Prullenbak |
| `Ctrl + C` / `Ctrl + X` | Geselecteerde items kopiëren / knippen naar klembord | Wanneer er geen tekst is geselecteerd (`copy`- / `move`-recht) |
| `Ctrl + V` | Bestanden/mappen van klembord in huidige map plakken **of** afbeelding/bestand direct vanaf OS-klembord uploaden | Werkt ook wanneer het uploadvenster geopend is |
| `Shift + Klik` / `Ctrl + Klik` | Bereikselectie / individuele items aan- of uitzetten | Je kunt ook op lege ruimte slepen voor lassoselectie (marquee) |
| `Esc` | **Slim sluiten in 4 stappen:** 1) Open menu/modal/lightbox/instellingen/mobiele zijbalk sluiten → 2) Selectie wissen → 3) Klembord wissen → 4) Inline volledig scherm verlaten | Sluit per toetsdruk alleen de bovenste laag |
| `Tab` / `Shift + Tab` | Schakelen tussen interactieve bedieningselementen in een geopende modal of Lightbox (focus trap) | — |

#### D. Weergave & hulp
| Sneltoets | Actie |
| :--- | :--- |
| `V` | Wisselen tussen Raster- en Lijstweergave |
| `B` | Linker zijbalk met mappenstructuur tonen/verbergen |
| `F` | Volledig scherm in-/uitschakelen |
| `T` | Prullenbak openen/sluiten (wanneer `trash.enabled: true`) |
| `?` of Arabisch `؟` | Sneltoetsenoverzicht openen |

#### E. In de Lightbox-voorbeeldweergave
| Sneltoets | Actie |
| :--- | :--- |
| `←` / `→` | Vorige / Volgende afbeelding *(automatisch gespiegeld in RTL-locales)* |
| `+`, `=` of `Numpad +` | Inzoomen |
| `-` of `Numpad -` | Uitzoomen |
| `0` of `Numpad 0` | Zoom herstellen naar `100%` |
| `Esc` | Lightbox sluiten |

#### F. In een geopend contextmenu
| Sneltoets | Actie |
| :--- | :--- |
| `↑` / `↓` | Omhoog / omlaag bewegen door menu-items |
| `Home` / `End` | Springen naar eerste / laatste menu-item |
| `Enter` / `Spatie` | Gefocuste menu-actie uitvoeren |
| `Tab` / `Esc` | Contextmenu sluiten |

---

[← Vorige: 02 — Configuratie & profielen](02-configuration-and-profiles.md) | [Documentatie-overzicht](README.md) | [Volgende: 04 — Thema's, pictogramsets & maatwerk →](04-themes-icons-and-customization.md)
