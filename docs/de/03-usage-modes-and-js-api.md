# 03 — Verwendungsmodi & JavaScript-API

[← Zurück: 02 — Konfiguration & Profile](02-configuration-and-profiles.md) | [Dokumentationsübersicht](README.md) | [Weiter: 04 — Themes, Icon-Sets & Anpassung →](04-themes-icons-and-customization.md)

Sie können das LiveUX FileManagerBundle direkt **inline** in jede Seite einbetten, in einem zentrierten **Modal** öffnen, als 4-Wege-**OffCanvas**-Panel einblenden oder von überall programmatisch über die **JavaScript-Promise-API** aufrufen.

> [!TIP]
> **Welchen Modus sollte ich wählen?**  
> - **Inline:** Eigene *„Mediathek“*-Seiten, auf denen der Dateimanager den Hauptinhaltsbereich ausfüllt.  
> - **Modal:** Zentrierter Dialog, der beim Bearbeiten eines Produkts oder Artikels über eine Schaltfläche *„Bild auswählen“* geöffnet wird — und sich sofort nach der Auswahl schließt.  
> - **OffCanvas:** Modernes Slide-Over-Panel von jeder Bildschirmkante (`start`, `end`, `top`, `bottom`), bei dem Ihr Formular im Hintergrund sichtbar bleibt.  
> - **JavaScript-API (`window.LiveUXFileManager.open()`):** Öffnet die Dateiauswahl aus jeder beliebigen HTML-Schaltfläche, React-/Vue-/Alpine-Komponente oder Vanilla-JS-Logik — ganz ohne Symfony Forms.

---

## 1. Inline-Modus — TwigComponent

![Inline-Ansicht des Dateimanagers](../images/browser-light.png)

Die Einbettung eines vollständigen Inline-Dateimanagers erfordert nur einen einzigen Twig-Aufruf. Umschließen Sie ihn mit einem Container mit definierter Höhe (z. B. `680px` oder `calc(100vh - 140px)`):

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Oder in der `<twig:...>`-Komponenten-Syntax von Symfony UX:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="de"
    />
</div>
```

### Alle Props der `Browser`-Komponente

| Prop | Typ | Standardwert | Beschreibung |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Profilschlüssel aus `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Start-Volume-Schlüssel (`null` öffnet das erste Volume des Profils). |
| `path` | `string` | `''` | Start-Unterverzeichnis (z. B. `'products/2026'`). |
| `multiple` | `bool` | `false` | Aktiviert die Mehrfachauswahl von Dateien. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` oder `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Initialer Typfilter: `'all'`, `'folder'`, `'image'`, `'doc'` oder `'video'`. |
| `theme` | `?string` | `null` | Laufzeit-Überschreibung des Themes (akzeptiert die 9 integrierten Themes: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Eigene CSS-/Twig-Themes werden in YAML konfiguriert. |
| `iconSet` | `?string` | `null` | Laufzeit-Überschreibung des Icon-Sets (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Eigene SVG-Verzeichnisse werden in YAML konfiguriert. |
| `locale` | `?string` | `null` | Laufzeit-Überschreibung der Sprache für alle **12 integrierten Oberflächensprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) oder beliebige BCP-47-/POSIX-Locales (z. B. `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal & OffCanvas — Programmatische JavaScript-Promise-API

`DialogHost` wird automatisch in jede vollständige HTML-Antwort eingefügt (`defaults.auto_inject_dialog_host: true`). Sie können `window.LiveUXFileManager.open()` von jeder Seite aus direkt mit `async/await` aufrufen.

### Alle Optionen von `open(options)`

| Option | Typ | Standardwert | Beschreibung |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Name des zu öffnenden Profils. |
| `volume` | `?string` | `null` | Schlüssel des Start-Volumes. |
| `path` | `string` | `''` | Start-Ordnerpfad (z. B. `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Zentrierter Modal-Dialog oder seitliches OffCanvas-Panel. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Einblendkante bei `mode: 'offcanvas'` (`start` = links, `end` = rechts; wird in RTL-Locales automatisch gespiegelt). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(Profil)* | Überschreibt die Darstellung auf mobilen Viewports (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = Einzeldatei, `true` = mehrere Dateien. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Öffnet bereits vorgefiltert auf eine Dateikategorie (`filetype` ist ein Kompatibilitäts-Alias für TinyMCE; `'media'` wird automatisch auf `'video'` abgebildet). |
| `theme` | `?string` | `null` | Laufzeit-Überschreibung für eines der 9 integrierten Themes (eigene Twig-Template-Pfade sind aus Sicherheitsgründen auf serverseitiges YAML beschränkt). |
| `iconSet` | `?string` | `null` | Laufzeit-Überschreibung für eines der 6 integrierten Icon-Sets. |
| `locale` | `?string` | `null` | Laufzeit-Überschreibung der Oberflächensprache (`'de'`, `'en'`, `'tr'`, `'fr'`, `'ar'`, `'ja'`, `'zh_CN'` usw.). |

### Praxisbeispiel: Verstecktes `<input>` + Live-Bildvorschau (ohne Symfony Form)

Fügen Sie dieses Snippet in eine beliebige HTML- oder Twig-Seite ein — es funktioniert sofort:

```html
<!-- 1. Verstecktes Feld + Live-Vorschau + Auslöser-Schaltfläche -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Vorschau" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Titelbild auswählen</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Direkt auf Bilder vorgefiltert öffnen
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // Benutzer hat ESC gedrückt oder den Dialog ohne Auswahl geschlossen
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

### Kurzbeispiele für die Modi

```html
<!-- Zentriertes Modal -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Modal öffnen</button>

<!-- Rechtes Slide-Over-Panel (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Rechtes Panel</button>

<!-- Bottom-Sheet-Panel (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Unteres Panel</button>
```

> [!NOTE]
> **Verhalten bei Abbruch:** Schließt der Benutzer den Dialog ohne Dateiauswahl (`ESC`, Schließen-Button oder Klick auf den Hintergrund), wird das Promise **nicht abgelehnt (reject)**, sondern sauber mit einem leeren Array (`[]`) aufgelöst.
>
> **Mobile Darstellung (`mobileMode` / `ui.mobile_dialog_mode`):** Auf Viewports `<= 767.98px` passt sich der Dialog automatisch an: Im Modus `'auto'` öffnen sich Modals und seitliche Panels (`start`/`end`) randlos im Vollbild (`100dvh`), während `bottom`-/`top`-Panels als abgerundete native Sheets mit Ziehgriff erscheinen. Der linke Ordnerbaum ist mobil zunächst eingeklappt und gleitet per Tipp herein.

| Desktop-OffCanvas-Panel (`position: 'end'`) | Mobiles Bottom-Sheet (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Desktop-OffCanvas-Ansicht](../images/offcanvas-desktop.png) | ![Mobile Sheet-Ansicht](../images/mobile-sheet.png) |

### Struktur der zurückgegebenen Dateiobjekte

`window.LiveUXFileManager.open()` liefert ein Array von Objekten in folgender Struktur zurück:

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
            "alt": "Mattschwarzer isolierter Edelstahl-Thermobecher",
            "title": "Thermobecher Pro",
            "caption": "500 ml vakuumisolierte Trinkflasche",
            "tags": ["thermobecher", "trinkgefaesse"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Hinweis: Auf `private: true`-Volumes verweist `url` auf den RBAC-geschützten Endpunkt `/_liveux-file-manager/download?...&inline=1`.)*

### Benutzerdefinierte DOM-Events

Zusätzlich zum `await` auf `window.LiveUXFileManager.open()` können Sie Dialog-Lebenszyklus-Events direkt auf `window` abonnieren:

| DOM-Event (`window.addEventListener`) | Auslösezeitpunkt | `event.detail`-Nutzdaten |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Wenn eine Anfrage zum Öffnen des Dialogs ausgelöst wird (Brücke zwischen `file_manager_api.js` und `dialog_controller.js`). | `open()`-Optionen + `requestId` |
| `liveux:file-manager:dialog-opened` | Sobald der Dialog geöffnet und sein Inhalt eingehängt wurde. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Wenn der Benutzer eine Auswahl bestätigt (oder den Dialog abbricht). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Anzahl ausgewählter Elemente:', event.detail.items.length);
});
```

---

## 3. Integrierte Endbenutzer-Funktionen

- **Kompakte Kontextmenüs:**
  - *Auf einer Datei oder einem Ordner:* Einzeilige SVG-Schnellaktionsleiste im Windows-11-Stil (`Ausschneiden`, `Kopieren`, `Umbenennen`, `Herunterladen`/`Anheften`, `Löschen`), gefolgt von gruppierten Ein-Klick-Aktionen (Vorschau, Bild bearbeiten, Dateimetadaten & Fokuspunkt, ZIP entpacken, URL kopieren, Freigabelink erstellen, Verschieben/Kopieren, Klonen, Ordnerfarbe & -symbol).
  - *Auf freier Fläche:* Obere Schnellnavigationsleiste (`← → ↑ ↻`), Neuer Ordner, Hier hochladen, Hier einfügen, Alle auswählen, Sortieren und Elemente pro Seite. *(Vollbild, Tastenkombinationen und Über befinden sich im **⚙ Einstellungen**-Menü der Toolbar.)*
  - *Mobil / Touch:* Öffnet sich als touch-optimiertes Bottom-Sheet über die Schaltfläche `⋯` an jeder Karte.
- **Universelle Vorschau (Lightbox v2) & Rechter Inspektor:**
  - **Bilder:** Galerienavigation (`←`/`→`), Zoom-Steuerung (`-` / `100%` / `+`) und Fokuspunkt-Anzeige.
  - **Video (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** Live-`<video controls>`-Player im Inspektor + Vollbild-Lightbox + `HTTP 206 Partial Content`-Spulen auf privaten Volumes (pro Profil über `ui.allow_video_preview` schaltbar).
  - **PDF (`.pdf`):** Eingebetteter PDF-Viewer in einem isolierten `<iframe>` innerhalb der Lightbox (über `ui.allow_pdf_preview` schaltbar).
  - **Audio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) & Text/Code (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql` usw. — begrenzt auf 64 KB).**
  - Im Modus `inline` öffnet ein **Doppelklick** auf eine vorschaufähige Datei (oder die `Leertaste`) sofort die Lightbox.
- **Intelligente Upload-Warteschlange (Pausieren / Fortsetzen / Bestätigter Abbruch):**
  - Laden Sie einzelne Dateien **oder ganze Ordnerstrukturen** (`webkitGetAsEntry`) per Drag-and-Drop hoch oder fügen Sie Bilder direkt aus der Zwischenablage mit `Strg+V` ein.
  - Chunked Uploads zeigen Live-Chunk-Zähler (`2/5 • 40%`), Ein-Klick-**Pausieren (`⏸`) / Fortsetzen (`▶`)**, abgesichertes **Abbrechen (`✕`)**, Sammelaktionen **Alle pausieren / Alle abbrechen** sowie automatische serverseitige Bereinigung unvollständiger temporärer Chunks bei Abbruch.
- **Mehrfachauswahl (inkl. Lasso-Rahmen), Zwischenablage & Tiefensuche:**
  - Ziehen Sie auf einer freien Fläche mit gedrückter Maustaste ein **Auswahlrechteck (Marquee / Lasso)** über mehrere Dateien und Ordner (halten Sie `Strg`/`Cmd` gedrückt, um zur bestehenden Auswahl hinzuzufügen).
  - Bereichsauswahl mit `Umschalt+Klick`, Umschalten einzelner Elemente mit `Strg+Klick`, Alles auswählen mit `Strg+A` sowie `Strg+C`/`Strg+X`/`Strg+V` über Ordner- und sogar Volume-Grenzen hinweg.
  - **Sortierbare Spaltenköpfe in der Listenansicht:** Klicken Sie auf **Name**, **Typ**, **Größe** oder **Datum**, um zwischen auf- und absteigender Sortierung zu wechseln.
  - **Live-Kartengrößen-Regler & Ordnerkarten:** Passen Sie die Kartengröße live an (`96 px – 320 px`) und wechseln Sie im Einstellungsmenü (`⚙`) zwischen kompakten Ordner-Kacheln (`tile`) und vollwertigen Ordnerkarten (`card`).
  - Aktivieren Sie die Tiefensuche im Suchfeld, um rekursiv über alle Unterordner hinweg zu suchen — einschließlich `#tags` und `alt`-Metadaten.
  - **⭐ Angehefteter Schnellzugriff** für Ordner in der Seitenleiste, anklickbares Modal für **Speicherkontingent & detaillierte Speicherbelegung** sowie Umschalter für **Auto / Hell / Dunkel**.
- **0-ms-Prüfung von Bildauflösung & Seitenverhältnis (`image_constraints`):** Sind Abmessungs- oder Seitenverhältnisregeln (`1:1`, `16:9` usw.) konfiguriert, werden nicht konforme Bilder noch vor dem Hochladen unmittelbar im Browser abgelehnt (und serverseitig erneut geprüft).
- **Redaktionelle Metadaten, Fokuspunkt & Signierte Freigabelinks:**
  - Bearbeiten Sie `alt`, `title`, `caption`, `#tags`, den anklickbaren `[x, y]`-**Fokuspunkt**-Wähler sowie optional **✨ Mit KI vorschlagen**.
  - Erzeugen Sie zeitlich begrenzte, HMAC-signierte Freigabelinks (`15 Min.`, `1 Stunde`, `24 Stunden`, `7 Tage`, `30 Tage`).
- **Integrierter Canvas-Bildeditor:** Freies oder seitenverhältnisgebundenes Zuschneiden (`1:1`, `4:3`, `16:9`), pixelgenaues Skalieren (`Max W`/`Max H`), Wahl von Ausgabeformat (`JPEG`/`PNG`/`WebP`) & Qualität, 90°-Drehung, horizontales/vertikales Spiegeln sowie Speichern als Kopie oder Überschreiben.

| Dateimetadaten & Fokuspunkt (`I`) | Integrierter Canvas-Bildeditor (`E`) |
| :---: | :---: |
| ![Dateimetadaten und Fokuspunkt](../images/file-metadata.png) | ![Integrierter Canvas-Bildeditor](../images/image-editor.png) |

- **Globale i18n, RTL & Barrierefreiheit (WCAG 2.1 AA):**
  - **12 integrierte Oberflächensprachen & automatisches RTL (`dir="rtl"`):** Unterstützt `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` und `zh_CN`. Arabisch (`ar`), Persisch (`fa`), Hebräisch (`he`), Urdu (`ur`) und weitere RTL-Locales spiegeln Layouts, Breadcrumbs, Seitenleisten und OffCanvas-Kanten automatisch über logisches CSS (`inset-inline`, `margin-inline`).
  - **CJK-/IME-Sicherheit:** Verhindert das vorzeitige Auslösen von `Enter` oder Tastenkombinationen während der Zusammensetzung chinesischer, japanischer oder koreanischer Zeichen (`event.isComposing`).
  - **Vollständige Tastatur- & Screenreader-Bedienbarkeit:** Fokusfalle (`Tab`/`Umschalt+Tab`) in Modals und Lightbox, Pfeiltasten-Navigation in Kontextmenüs, `role="tree"` / `aria-expanded` / `aria-current`-Semantik sowie automatische Wiederherstellung des Fokus beim Schließen.
- **Tastenkombinationen (Vollständige Übersicht):**
  - Alle Shortcuts sind nur aktiv, wenn der Dateimanager fokussiert ist, außerhalb von Texteingabefeldern (`<input>`, `<textarea>`, `<select>`) liegen und strikt die **Voter-Zugriffsrechte** des Benutzers respektieren (`ui.keyboard_shortcuts: true`).
  - Unter macOS funktioniert **`Cmd (⌘)`** nahtlos parallel zu `Strg` (`Ctrl`).
  - **Schutz für internationale Tastaturen & IME:** Nutzt physische Tastencodes (`event.code` `Key*`) mit Fallback-Zeichenzuordnung, sodass Shortcuts auch auf Nicht-QWERTY- (z. B. deutschem QWERTZ) und nicht-lateinischen Tastaturlayouts zuverlässig funktionieren und während einer CJK-IME-Eingabe (`isComposing`) stumm bleiben.

#### A. Datei- & Ordneraktionen
| Tastenkombination | Aktion | Bedingung / Hinweis |
| :--- | :--- | :--- |
| `N` | Neuen Ordner erstellen | Außerhalb des Papierkorbs (`create_folder`-Berechtigung) |
| `U` | Upload-Dialog öffnen | Außerhalb des Papierkorbs (`upload`-Berechtigung) |
| `F2` | Element umbenennen | Einzelne Datei oder einzelner Ordner markiert (`rename`-Berechtigung) |
| `M` | Dialog „Verschieben / Kopieren“ öffnen | 1 oder mehr Elemente markiert (`move`- oder `copy`-Berechtigung) |
| `D` | Datei klonen (duplizieren) | **Einzelne Datei** markiert (`clone`-Berechtigung) |
| `I` | **Ordner:** Farbe & Symbol / **Datei:** Metadaten & Fokuspunkt | Einzelnes Element markiert (`edit_metadata`-Berechtigung) |
| `E` | Integrierten Canvas-Bildeditor öffnen | Einzelnes Rasterbild (`.jpg`, `.jpeg`, `.png`, `.webp`) markiert (`upload`-Berechtigung) |
| `L` | Datei-URL in die Zwischenablage kopieren | Einzelne Datei markiert (`download`-Berechtigung) |
| `S` | Modal für zeitlich begrenzten HMAC-Freigabelink öffnen | Einzelne Datei markiert (`share`-Berechtigung + `share.enabled: true`) |
| `Entf` (`Delete`) | Markierte Elemente löschen (Papierkorb oder dauerhaft) | 1 oder mehr Elemente markiert (`delete`-Berechtigung) |

#### B. Navigation & Vorschau
| Tastenkombination | Aktion | Bedingung / Hinweis |
| :--- | :--- | :--- |
| `/` oder `Strg + F` | Suchfeld fokussieren | Wenn `show_search: true` (`Strg + F` funktioniert auch innerhalb von Eingabefeldern) |
| `←` `→` `↑` `↓` | Zwischen Rasterkarten oder Listenzeilen navigieren | — |
| `Enter` | **Ordner:** öffnen • **Einzelne Datei:** Lightbox im Inline-Modus öffnen oder Auswahl im Modal-/OffCanvas-Modus bestätigen | — |
| `Leertaste` (`Space`) | Markierte Datei in der Lightbox anzeigen | Einzelne Datei markiert |
| `Rücktaste` (`Backspace`) oder `Alt + ↑` | Eine Verzeichnisebene nach oben wechseln | Innerhalb eines Unterordners |
| `Alt + ←` / `Alt + →` | Im Verlauf zurück- / vorwärts navigieren | In **RTL**-Locales (Arabisch, Persisch, Hebräisch) automatisch gespiegelt |
| `R` | Aktuelles Verzeichnis vom Server neu laden (`?refresh=1`) | Wenn `show_refresh_button: true` |

#### C. Auswahl, Zwischenablage & Schließen von Ebenen
| Tastenkombination | Aktion | Bedingung / Hinweis |
| :--- | :--- | :--- |
| `Strg + A` | Alle sichtbaren Elemente auswählen | Außerhalb des Papierkorbs |
| `Strg + C` / `Strg + X` | Markierte Elemente in die Zwischenablage kopieren / ausschneiden | Wenn kein Text markiert ist (`copy`- / `move`-Berechtigung) |
| `Strg + V` | Dateien/Ordner aus der Zwischenablage im aktuellen Verzeichnis einfügen **oder** ein Bild/eine Datei direkt aus der OS-Zwischenablage hochladen | Funktioniert auch bei geöffnetem Upload-Dialog |
| `Umschalt + Klick` / `Strg + Klick` | Bereichsauswahl / einzelne Elemente an- oder abwählen | Auf freier Fläche auch per Marquee-Lasso-Ziehen möglich |
| `Esc` | **4-stufiges intelligentes Schließen:** 1) Geöffnetes Menü/Modal/Lightbox/Einstellungen/mobiles Panel schließen → 2) Auswahl aufheben → 3) Zwischenablage leeren → 4) Inline-Vollbild beenden | Schließt pro Tastendruck immer nur die oberste Ebene |
| `Tab` / `Umschalt + Tab` | Durch interaktive Elemente innerhalb eines geöffneten Modals oder der Lightbox wechseln (Fokusfalle) | — |

#### D. Ansicht & Hilfe
| Tastenkombination | Aktion |
| :--- | :--- |
| `V` | Zwischen Raster- und Listenansicht wechseln |
| `B` | Linke Ordnerbaum-Seitenleiste ein-/ausblenden |
| `F` | Vollbildmodus umschalten |
| `T` | Papierkorb-Ansicht umschalten (wenn `trash.enabled: true`) |
| `?` oder arabisches `؟` | Hilfedialog für Tastenkombinationen öffnen |

#### E. Innerhalb der Lightbox-Vorschau
| Tastenkombination | Aktion |
| :--- | :--- |
| `←` / `→` | Vorheriges / nächstes Bild *(in RTL-Locales automatisch gespiegelt)* |
| `+`, `=` oder `Numpad +` | Hineinzoomen |
| `-` oder `Numpad -` | Herauszoomen |
| `0` oder `Numpad 0` | Zoom auf `100%` zurücksetzen |
| `Esc` | Lightbox schließen |

#### F. Innerhalb eines geöffneten Kontextmenüs
| Tastenkombination | Aktion |
| :--- | :--- |
| `↑` / `↓` | Durch die Menüpunkte nach oben / unten bewegen |
| `Pos1` (`Home`) / `Ende` (`End`) | Zum ersten / letzten Menüpunkt springen |
| `Enter` / `Leertaste` (`Space`) | Fokussierte Menüaktion ausführen |
| `Tab` / `Esc` | Kontextmenü schließen |

---

[← Zurück: 02 — Konfiguration & Profile](02-configuration-and-profiles.md) | [Dokumentationsübersicht](README.md) | [Weiter: 04 — Themes, Icon-Sets & Anpassung →](04-themes-icons-and-customization.md)
