# 03 — Modalità d'uso e API JavaScript

[← Precedente: 02 — Configurazione e profili](02-configuration-and-profiles.md) | [Indice della documentazione](README.md) | [Avanti: 04 — Temi, set di icone e personalizzazione →](04-themes-icons-and-customization.md)

Puoi incorporare LiveUX FileManagerBundle **inline** dentro qualsiasi pagina, aprirlo in una finestra **Modale** centrata, farlo scorrere come pannello **OffCanvas** in 4 direzioni oppure invocarlo via codice da qualunque punto tramite la **JavaScript Promise API**.

> [!TIP]
> **Quale modalità scegliere?**  
> - **Inline:** Pagine dedicate alla *"Libreria media"* in cui il gestore file occupa l'area principale dei contenuti.  
> - **Modale:** Finestra di dialogo centrata aperta da un pulsante *"Scegli immagine"* mentre modifichi un prodotto o un articolo—si chiude appena l'utente seleziona un asset.  
> - **OffCanvas:** Pannello scorrevole moderno da qualsiasi bordo (`start`, `end`, `top`, `bottom`) che lascia visibile il form sullo sfondo.  
> - **API JavaScript (`window.LiveUXFileManager.open()`):** Apri il selettore da qualsiasi pulsante HTML personalizzato, componente React/Vue/Alpine o flusso vanilla JS senza Symfony Forms.

---

## 1. Modalità Inline — TwigComponent

![Vista inline del Gestore file](../images/browser-light.png)

Per incorporare un gestore file completo inline basta una singola chiamata Twig. Avvolgilo in un contenitore con un'altezza esplicita (come `680px` o `calc(100vh - 140px)`):

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Oppure con la sintassi a tag `<twig:...>` di Symfony UX:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="it"
    />
</div>
```

### Tutte le prop del componente `Browser`

| Prop | Tipo | Predefinito | Descrizione |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Chiave del profilo definita in `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Chiave del volume iniziale (`null` apre il primo volume del profilo). |
| `path` | `string` | `''` | Sottocartella iniziale (es. `'products/2026'`). |
| `multiple` | `bool` | `false` | Abilita la selezione multipla di file. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` o `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Filtro per tipo iniziale: `'all'`, `'folder'`, `'image'`, `'doc'` o `'video'`. |
| `theme` | `?string` | `null` | Override del tema a runtime (accetta i 9 temi integrati: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). I temi CSS/Twig personalizzati si configurano in YAML. |
| `iconSet` | `?string` | `null` | Override del set di icone a runtime (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Le directory SVG personalizzate si configurano in YAML. |
| `locale` | `?string` | `null` | Override del locale a runtime tra tutte le **12 lingue UI integrate** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) o qualsiasi locale BCP-47 / POSIX (es. `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modale e OffCanvas — API JavaScript Promise programmatica

`DialogHost` viene iniettato automaticamente in ogni risposta HTML completa (`defaults.auto_inject_dialog_host: true`). Puoi invocare `window.LiveUXFileManager.open()` con `async/await` da qualsiasi pagina.

### Tutte le opzioni di `open(options)`

| Opzione | Tipo | Predefinito | Descrizione |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Nome del profilo da aprire. |
| `volume` | `?string` | `null` | Chiave del volume di archiviazione iniziale. |
| `path` | `string` | `''` | Percorso cartella iniziale (es. `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Finestra modale centrata o pannello scorrevole OffCanvas. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Lato da cui scorre il pannello quando `mode: 'offcanvas'` (`start` = sinistra, `end` = destra; invertito automaticamente nei locale RTL). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(profilo)* | Sovrascrive il layout su schermi mobile (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = file singolo, `true` = selezione multipla. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Apre il gestore pre-filtrato su una categoria di file (`filetype` è un alias di compatibilità per TinyMCE; `'media'` viene mappato automaticamente su `'video'`). |
| `theme` | `?string` | `null` | Override a runtime per uno qualsiasi dei 9 temi integrati (i percorsi di template Twig personalizzati sono consentiti solo lato server in YAML per sicurezza). |
| `iconSet` | `?string` | `null` | Override a runtime per uno qualsiasi dei 6 set di icone integrati. |
| `locale` | `?string` | `null` | Override del locale a runtime (`'it'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, `'ja'`, `'zh_CN'`, ecc.). |

### Esempio pratico: `<input>` nascosto + anteprima immagine live (senza Symfony Form)

Inserisci questo snippet in qualsiasi pagina HTML o Twig e funzionerà all'istante:

```html
<!-- 1. Campo nascosto + anteprima live + pulsante di apertura -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Anteprima" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Scegli immagine di copertina</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Apri pre-filtrato sulle immagini
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // L'utente ha premuto Esc o chiuso la finestra senza selezionare nulla
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

### Snippet rapidi per le modalità

```html
<!-- Finestra Modale centrata -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Apri Modale</button>

<!-- Pannello scorrevole destro (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Pannello destro</button>

<!-- Pannello inferiore (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Pannello inferiore</button>
```

> [!NOTE]
> **Comportamento in caso di annullamento:** Se l'utente chiude la finestra senza scegliere alcun file (`Esc`, pulsante Chiudi o clic sullo sfondo), la Promise **non viene rifiutata**: si risolve pulitamente con un array vuoto (`[]`).
>
> **Adattamento mobile (`mobileMode` / `ui.mobile_dialog_mode`):** Su schermi `<= 767.98px`, la finestra si adatta automaticamente: in modalità `'auto'`, modali e pannelli laterali (`start`/`end`) si aprono a schermo intero (`100dvh`), mentre i pannelli `bottom`/`top` diventano sheet nativi con angoli arrotondati e maniglia di trascinamento. L'albero delle cartelle a sinistra parte compresso su mobile e scorre fuori al tocco.

| Pannello OffCanvas desktop (`position: 'end'`) | Bottom Sheet mobile (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Vista OffCanvas desktop](../images/offcanvas-desktop.png) | ![Vista Bottom Sheet mobile](../images/mobile-sheet.png) |

### Struttura dell'oggetto file restituito

`window.LiveUXFileManager.open()` si risolve con un array di oggetti aventi questa struttura:

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
            "alt": "Borraccia termica in acciaio inox nero opaco",
            "title": "Tumbler Pro",
            "caption": "Bottiglia termica sottovuoto da 500 ml",
            "tags": ["borraccia", "accessori"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Nota: sui volumi con `private: true`, `url` punta all'endpoint protetto da RBAC `/_liveux-file-manager/download?...&inline=1`.)*

### Eventi DOM personalizzati

Oltre ad attendere `window.LiveUXFileManager.open()`, puoi ascoltare gli eventi del ciclo di vita della finestra direttamente su `window`:

| Evento DOM (`window.addEventListener`) | Quando viene emesso | Payload `event.detail` |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Quando viene inviata una richiesta di apertura finestra (ponte tra `file_manager_api.js` e `dialog_controller.js`). | Opzioni di `open()` + `requestId` |
| `liveux:file-manager:dialog-opened` | Appena la finestra è aperta e il contenuto è montato. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Quando l'utente conferma la selezione (o chiude la finestra). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Numero elementi selezionati:', event.detail.items.length);
});
```

---

## 3. Funzionalità integrate per gli utenti finali

- **Menu contestuali compatti:**
  - *Su un file o una cartella:* Barra rapida SVG su una riga in stile Windows 11 (`Taglia`, `Copia`, `Rinomina`, `Scarica`/`Aggiungi ad Accesso rapido`, `Elimina`) seguita da azioni raggruppate con un clic (Anteprima, Modifica immagine, Metadati file e punto focale, Estrai archivio, Copia link, Crea link di condivisione, Sposta/Copia, Duplica, Colore e icona cartella).
  - *Su un'area vuota:* Striscia rapida di navigazione in alto (`← → ↑ ↻`), Nuova cartella, Carica qui, Incolla qui, Seleziona tutto, Ordina per ed Elementi per pagina. *(Schermo intero, Scorciatoie da tastiera e Informazioni si trovano nel menu **⚙ Impostazioni** nella barra degli strumenti.)*
  - *Mobile / Touch:* Si apre come bottom sheet ottimizzato per il tocco tramite il pulsante `⋯` su ciascuna scheda.
- **Visualizzatore universale (Lightbox v2) e pannello Anteprima e dettagli:**
  - **Immagini:** Navigazione galleria (`←`/`→`), controlli zoom (`-` / `100%` / `+`) e indicatore del punto focale.
  - **Video (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** Player `<video controls>` live nel pannello dettagli + Lightbox a schermo intero + seeking `HTTP 206 Partial Content` sui volumi privati (disattivabile per profilo tramite `ui.allow_video_preview`).
  - **PDF (`.pdf`):** Visualizzatore PDF in `<iframe>` sandbox dentro il Lightbox (disattivabile tramite `ui.allow_pdf_preview`).
  - **Audio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) e Testo/Codice (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql`, ecc. — limite 64 KB).**
  - In modalità `inline`, il **doppio clic** su qualsiasi file con anteprima (o la pressione di `Spazio`) apre subito il Lightbox.
- **Coda di caricamento intelligente (Metti in pausa / Riprendi / Annulla con conferma):**
  - Carica file **o intere gerarchie di cartelle** (`webkitGetAsEntry`) tramite drag-and-drop, oppure incolla immagini direttamente dagli Appunti con `Ctrl+V`.
  - I caricamenti a blocchi mostrano contatori live (`2/5 • 40%`), **Metti in pausa (`⏸`) / Riprendi (`▶`)** con un clic, **Annulla (`✕`)** con conferma, azioni globali **Metti tutto in pausa / Annulla tutto** e pulizia automatica lato server dei blocchi temporanei in caso di annullamento.
- **Selezione multipla (incluso Lazo / Marquee), Appunti e ricerca approfondita:**
  - Fai clic e trascina su un'area vuota per disegnare un **rettangolo di selezione Marquee / Lazo** attorno a più file e cartelle (tieni premuto `Ctrl`/`Cmd` per aggiungere alla selezione corrente).
  - Selezione di intervalli con `Shift+Clic`, selezione singola con `Ctrl+Clic`, `Ctrl+A` per selezionare tutto e `Ctrl+C`/`Ctrl+X`/`Ctrl+V` tra cartelle e persino tra volumi diversi.
  - **Intestazioni di colonna ordinabili nella vista Elenco:** Fai clic su **Nome**, **Tipo**, **Dimensione** o **Data** per invertire l'ordinamento crescente/decrescente.
  - **Cursore live dimensione elementi e schede cartella:** Regola dal vivo la dimensione delle schede (`96 px – 320 px`) e passa tra lo stile cartelle Compatto (`tile`) e Scheda (`card`) dal menu Impostazioni (`⚙`).
  - Attiva il pulsante di ricerca approfondita nella barra di ricerca per cercare ricorsivamente in tutte le sottocartelle, inclusi `#tag` e metadati `alt`.
  - Cartelle fissate in **⭐ Accesso rapido** nella barra laterale, finestra cliccabile **Utilizzo spazio e dettagli quota** e selettore tema **Automatico / Chiaro / Scuro**.
- **Validazione risoluzione e proporzioni immagini a 0ms (`image_constraints`):** Quando sono configurati vincoli di dimensione o proporzioni (`1:1`, `16:9`, ecc.), le immagini non conformi vengono rifiutate istantaneamente nel browser prima del caricamento (e riverificate lato server).
- **Metadati editoriali, punto focale e link di condivisione firmati:**
  - Modifica `alt`, `title`, `caption`, `#tag`, selettore cliccabile `[x, y]` per il **Punto focale** e pulsante opzionale **✨ Suggerisci con AI**.
  - Genera link di condivisione temporanei con firma HMAC (`15 minuti`, `1 ora`, `24 ore`, `7 giorni`, `30 giorni`).
- **Editor immagini Canvas integrato:** Ritaglio libero o con proporzioni bloccate (`1:1`, `4:3`, `16:9`), ridimensionamento in pixel (`Larg. max`/`Alt. max`), controllo formato di output (`JPEG`/`PNG`/`WebP`) e qualità, rotazione di 90°, riflessione orizzontale/verticale e **Salva come copia** / **Sovrascrivi**.

| Metadati file e punto focale (`I`) | Editor immagini Canvas integrato (`E`) |
| :---: | :---: |
| ![Metadati file e punto focale](../images/file-metadata.png) | ![Editor immagini Canvas integrato](../images/image-editor.png) |

- **Internazionalizzazione globale, RTL e accessibilità (WCAG 2.1 AA):**
  - **12 lingue UI integrate e RTL automatico (`dir="rtl"`):** Supporta `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` e `zh_CN`. Arabo (`ar`), persiano (`fa`), ebraico (`he`), urdu (`ur`) e altri locale RTL ribaltano automaticamente layout, percorso di navigazione, barra laterale e bordi OffCanvas usando proprietà CSS logiche (`inset-inline`, `margin-inline`).
  - **Sicurezza CJK / IME:** Impedisce che `Invio` o le scorciatoie si attivino prematuramente durante la composizione di testo cinese, giapponese o coreano (`event.isComposing`).
  - **Piena accessibilità da tastiera e screen reader:** Focus trapping (`Tab`/`Shift+Tab`) in modali e Lightbox, navigazione con frecce nei menu contestuali, semantica `role="tree"` / `aria-expanded` / `aria-current` e ripristino automatico del focus alla chiusura.
- **Scorciatoie da tastiera (Riferimento completo):**
  - Tutte le scorciatoie si attivano solo quando il gestore file ha il focus, al di fuori dei campi di testo (`<input>`, `<textarea>`, `<select>`), e rispettano rigorosamente i **permessi Voter** dell'utente (`ui.keyboard_shortcuts: true`).
  - Su macOS, **`Cmd (⌘)`** funziona in modo trasparente al posto di `Ctrl`.
  - **Protezione tastiere internazionali e IME:** Usa codici fisici dei tasti (`event.code` `Key*`) con fallback sui caratteri affinché le scorciatoie funzionino anche su layout non-QWERTY e non latini, restando inattive durante la composizione IME CJK (`isComposing`).

#### A. Operazioni su file e cartelle
| Scorciatoia | Azione | Condizione / Nota |
| :--- | :--- | :--- |
| `N` | Crea nuova cartella | Fuori dal Cestino (permesso `create_folder`) |
| `U` | Apri finestra di caricamento | Fuori dal Cestino (permesso `upload`) |
| `F2` | Rinomina elemento | Singolo file o cartella selezionato (permesso `rename`) |
| `M` | Apri finestra Sposta / Copia | 1 o più elementi selezionati (permesso `move` o `copy`) |
| `D` | Duplica file | **Singolo file** selezionato (permesso `clone`) |
| `I` | **Cartella:** Colore e icona / **File:** Info, SEO e punto focale | Singolo elemento selezionato (permesso `edit_metadata`) |
| `E` | Apri l'editor immagini Canvas integrato | Singola immagine raster (`.jpg`, `.jpeg`, `.png`, `.webp`) selezionata (permesso `upload`) |
| `L` | Copia link del file negli Appunti | Singolo file selezionato (permesso `download`) |
| `S` | Apri finestra Link di condivisione firmato HMAC | Singolo file selezionato (permesso `share` + `share.enabled: true`) |
| `Canc` (`Delete`) | Elimina gli elementi selezionati (Cestino o definitivo) | 1 o più elementi selezionati (permesso `delete`) |

#### B. Navigazione e anteprima
| Scorciatoia | Azione | Condizione / Nota |
| :--- | :--- | :--- |
| `/` oppure `Ctrl + F` | Sposta il focus sulla ricerca | Quando `show_search: true` (`Ctrl + F` funziona anche dentro i campi input) |
| `←` `→` `↑` `↓` | Spostati tra le schede della Griglia o le righe dell'Elenco | — |
| `Invio` | **Cartella:** apri • **Singolo file:** apri Lightbox in modalità Inline, oppure conferma selezione in modalità Modale/OffCanvas | — |
| `Spazio` | Anteprima del file selezionato nel Lightbox | Singolo file selezionato |
| `Backspace` oppure `Alt + ↑` | Vai alla cartella superiore | Dentro una sottocartella |
| `Alt + ←` / `Alt + →` | Torna indietro / Vai avanti nella cronologia | Invertito automaticamente nei locale **RTL** (arabo, persiano, ebraico) |
| `R` | Aggiorna la cartella corrente dal server (`?refresh=1`) | Quando `show_refresh_button: true` |

#### C. Selezione, Appunti e chiusura livelli
| Scorciatoia | Azione | Condizione / Nota |
| :--- | :--- | :--- |
| `Ctrl + A` | Seleziona tutti gli elementi visibili | Fuori dal Cestino |
| `Ctrl + C` / `Ctrl + X` | Copia / Taglia gli elementi selezionati negli Appunti | Quando non c'è testo evidenziato (permesso `copy` / `move`) |
| `Ctrl + V` | Incolla file/cartelle dagli Appunti nella cartella corrente **oppure** carica un'immagine/file direttamente dagli Appunti del sistema operativo | Funziona anche mentre la finestra Carica file è aperta |
| `Shift + Clic` / `Ctrl + Clic` | Selezione intervallo / aggiungi o rimuovi singoli elementi | Puoi anche trascinare su un'area vuota per la selezione a lazo (Marquee) |
| `Esc` | **Chiusura intelligente a 4 stadi:** 1) Chiude menu/modale/lightbox/impostazioni/pannello mobile aperti → 2) Annulla selezione → 3) Svuota Appunti → 4) Esce da schermo intero inline | Chiude solo il livello più alto a ogni pressione |
| `Tab` / `Shift + Tab` | Scorre tra i controlli interattivi all'interno di una modale o del Lightbox (focus trap) | — |

#### D. Visualizzazione e guida
| Scorciatoia | Azione |
| :--- | :--- |
| `V` | Alterna vista Griglia / Elenco |
| `B` | Mostra/nascondi la barra laterale dell'albero delle cartelle |
| `F` | Attiva/disattiva schermo intero |
| `T` | Apri/chiudi il Cestino (quando `trash.enabled: true`) |
| `?` oppure `؟` arabo | Apri la finestra delle scorciatoie da tastiera |

#### E. Dentro l'anteprima Lightbox
| Scorciatoia | Azione |
| :--- | :--- |
| `←` / `→` | Immagine precedente / successiva *(invertito automaticamente nei locale RTL)* |
| `+`, `=` oppure `Tastierino +` | Ingrandisci |
| `-` oppure `Tastierino -` | Riduci |
| `0` oppure `Tastierino 0` | Reimposta zoom a `100%` |
| `Esc` | Chiudi Lightbox |

#### F. Dentro un menu contestuale aperto
| Scorciatoia | Azione |
| :--- | :--- |
| `↑` / `↓` | Scorri su / giù tra le voci del menu |
| `Home` / `Fine` (`End`) | Salta alla prima / ultima voce del menu |
| `Invio` / `Spazio` | Esegui l'azione evidenziata |
| `Tab` / `Esc` | Chiudi il menu contestuale |

---

[← Precedente: 02 — Configurazione e profili](02-configuration-and-profiles.md) | [Indice della documentazione](README.md) | [Avanti: 04 — Temi, set di icone e personalizzazione →](04-themes-icons-and-customization.md)
