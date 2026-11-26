# 03 — Tryby użycia i JavaScript API

[← Poprzedni: 02 — Konfiguracja i profile](02-configuration-and-profiles.md) | [Indeks dokumentacji](README.md) | [Dalej: 04 — Motywy, zestawy ikon i personalizacja →](04-themes-icons-and-customization.md)

LiveUX FileManagerBundle możesz osadzić **bezpośrednio na stronie (`inline`)**, otworzyć jako wyśrodkowane okno **Modal**, wysunąć z dowolnej krawędzi jako 4-kierunkowy panel **OffCanvas** albo wywołać programowo z dowolnego miejsca za pomocą **JavaScript Promise API**.

> [!TIP]
> **Który tryb wybrać?**  
> - **Inline:** Dedykowane strony typu *„Biblioteka mediów”*, gdzie menedżer plików wypełnia główny obszar roboczy.  
> - **Modal:** Wyśrodkowane okno wywoływane przyciskiem *„Wybierz obraz”* podczas edycji produktu lub artykułu — zamyka się natychmiast po wyborze zasobu.  
> - **OffCanvas:** Nowoczesny panel wysuwany z dowolnej krawędzi (`start`, `end`, `top`, `bottom`), pozostawiający formularz widoczny w tle.  
> - **JavaScript API (`window.LiveUXFileManager.open()`):** Uruchamianie selektora z dowolnego własnego przycisku HTML, komponentu React/Vue/Alpine lub czystego JS bez formularzy Symfony.

---

## 1. Tryb Inline — TwigComponent

![Widok osadzonego menedżera plików (inline)](../images/browser-light.png)

Osadzenie pełnego menedżera plików w trybie `inline` wymaga jednego wywołania Twig. Umieść go w kontenerze o jawnej wysokości (np. `680px` lub `calc(100vh - 140px)`):

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Lub przy użyciu składni znaczników komponentów Symfony UX `<twig:...>`:

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="pl"
    />
</div>
```

### Wszystkie właściwości (props) komponentu `Browser`

| Właściwość | Typ | Domyślnie | Opis |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Klucz profilu zdefiniowany w `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Początkowy klucz wolumenu (`null` otwiera pierwszy wolumen profilu). |
| `path` | `string` | `''` | Początkowy podkatalog (np. `'products/2026'`). |
| `multiple` | `bool` | `false` | Włącza tryb wyboru wielu plików. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` lub `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Początkowy filtr typu: `'all'`, `'folder'`, `'image'`, `'doc'` lub `'video'`. |
| `theme` | `?string` | `null` | Nadpisanie motywu w czasie wykonania (akceptuje 9 wbudowanych motywów: `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Własne motywy CSS/Twig konfiguruje się w YAML. |
| `iconSet` | `?string` | `null` | Nadpisanie zestawu ikon w czasie wykonania (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Własne katalogi SVG konfiguruje się w YAML. |
| `locale` | `?string` | `null` | Nadpisanie języka w czasie wykonania dla wszystkich **12 wbudowanych języków interfejsu** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) lub dowolnego kodu BCP-47 / POSIX (np. `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal i OffCanvas — Programowe JavaScript Promise API

`DialogHost` jest automatycznie wstrzykiwany do każdej pełnej odpowiedzi HTML (`defaults.auto_inject_dialog_host: true`). Możesz wywoływać `window.LiveUXFileManager.open()` z `async/await` z dowolnej strony.

### Wszystkie opcje `open(options)`

| Opcja | Typ | Domyślnie | Opis |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Nazwa profilu do otwarcia. |
| `volume` | `?string` | `null` | Klucz początkowego wolumenu pamięci. |
| `path` | `string` | `''` | Początkowa ścieżka folderu (np. `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Wyśrodkowane okno modalne lub wysuwany panel OffCanvas. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Krawędź, z której wysuwa się panel przy `mode: 'offcanvas'` (`start` = lewa, `end` = prawa; automatycznie odwracane w językach RTL). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(z profilu)* | Nadpisuje sposób wyświetlania na ekranach mobilnych (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = pojedynczy plik, `true` = wiele plików. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Otwiera menedżer wstępnie przefiltrowany do wybranej kategorii (`filetype` to alias zgodności dla TinyMCE; `'media'` jest automatycznie mapowane na `'video'`). |
| `theme` | `?string` | `null` | Nadpisanie w czasie wykonania dowolnym z 9 wbudowanych motywów (własne ścieżki szablonów Twig są ze względów bezpieczeństwa ograniczone do konfiguracji YAML po stronie serwera). |
| `iconSet` | `?string` | `null` | Nadpisanie w czasie wykonania dowolnym z 6 wbudowanych zestawów ikon. |
| `locale` | `?string` | `null` | Nadpisanie języka w czasie wykonania (`'pl'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, `'ja'`, `'zh_CN'` itd.). |

### Przykład z praktyki: ukryte pole `<input>` + podgląd obrazu na żywo (bez Symfony Form)

Wklej poniższy fragment do dowolnej strony HTML lub Twig — zadziała od razu:

```html
<!-- 1. Ukryte pole + podgląd na żywo + przycisk uruchamiający -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Podgląd" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Wybierz obraz okładki</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Otwórz wstępnie przefiltrowane do obrazów
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // Użytkownik nacisnął ESC lub zamknął okno bez wyboru pliku
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

### Szybkie wywołania dla różnych trybów

```html
<!-- Wyśrodkowane okno Modal -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Otwórz Modal</button>

<!-- Panel wysuwany z prawej krawędzi (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Panel prawy</button>

<!-- Dolny arkusz (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Panel dolny</button>
```

> [!NOTE]
> **Zachowanie przy anulowaniu:** Jeśli użytkownik zamknie okno bez wyboru pliku (`Esc`, przycisk zamknięcia lub kliknięcie tła), obietnica (`Promise`) **nie zostaje odrzucona (reject)** — czysto zwraca pustą tablicę (`[]`).
>
> **Responsywność mobilna (`mobileMode` / `ui.mobile_dialog_mode`):** Na ekranach o szerokości `<= 767.98px` okno dostosowuje się automatycznie: w trybie `'auto'` okna modalne i panele boczne (`start`/`end`) otwierają się na pełnym ekranie od krawędzi do krawędzi (`100dvh`), natomiast panele `bottom`/`top` otwierają się jako zaokrąglone natywne arkusze z uchwytem przeciągania. Lewy panel drzewa folderów startuje zwinięty i wysuwa się po stuknięciu.

| Panel OffCanvas na desktopie (`position: 'end'`) | Mobilny dolny arkusz (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Widok panelu OffCanvas na desktopie](../images/offcanvas-desktop.png) | ![Widok mobilnego dolnego arkusza](../images/mobile-sheet.png) |

### Struktura zwracanego obiektu pliku

Wywołanie `window.LiveUXFileManager.open()` zwraca tablicę obiektów o następującej strukturze:

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
            "alt": "Matowy czarny kubek termiczny ze stali nierdzewnej",
            "title": "Kubek termiczny Pro",
            "caption": "Butelka termiczna próżniowa 500 ml",
            "tags": ["kubek", "termos"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Uwaga: w wolumenach z `private: true` pole `url` wskazuje na chroniony przez RBAC endpoint `/_liveux-file-manager/download?...&inline=1`.)*

### Niestandardowe zdarzenia DOM

Oprócz oczekiwania na `window.LiveUXFileManager.open()`, możesz nasłuchiwać zdarzeń cyklu życia okna bezpośrednio na obiekcie `window`:

| Zdarzenie DOM (`window.addEventListener`) | Kiedy jest emitowane | Zawartość `event.detail` |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Gdy wywołano żądanie otwarcia okna (most między `file_manager_api.js` a `dialog_controller.js`). | Opcje `open()` + `requestId` |
| `liveux:file-manager:dialog-opened` | Po otwarciu okna i zamontowaniu jego zawartości. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Gdy użytkownik zatwierdzi wybór (lub anuluje okno). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Liczba wybranych elementów:', event.detail.items.length);
});
```

---

## 3. Wbudowane funkcje dla użytkownika końcowego

- **Kompaktowe menu kontekstowe:**
  - *Na pliku lub folderze:* jednowierszowy pasek szybkich akcji SVG w stylu Windows 11 (`Wytnij`, `Kopiuj`, `Zmień nazwę`, `Pobierz`/`Przypnij do Szybkiego dostępu`, `Usuń`), a poniżej pogrupowane akcje jednego kliknięcia (Podgląd, Edytuj obraz, Informacje i tagi / SEO i punkt ogniskowy, Rozpakuj archiwum, Kopiuj link, Utwórz link udostępniania, Przenieś do folderu…, Duplikuj, Kolor folderu i ikona).
  - *Na pustym obszarze:* górny pasek szybkiej nawigacji (`← → ↑ ↻`), Nowy folder, Prześlij tutaj, Wklej tutaj, Zaznacz wszystko, Sortuj według oraz Elementów na stronę. *(Pełny ekran, Skróty klawiszowe oraz O wtyczce znajdują się w menu **⚙ Ustawienia** na pasku narzędzi.)*
  - *Urządzenia mobilne / dotykowe:* otwiera się jako przyjazny dla dotyku dolny arkusz po stuknięciu przycisku `⋯` na każdej karcie.
- **Uniwersalna przeglądarka podglądu (Lightbox v2) i prawy panel szczegółów:**
  - **Obrazy:** nawigacja po galerii (`←`/`→`), sterowanie powiększeniem (`-` / `100%` / `+`) oraz wskaźnik punktu ogniskowego.
  - **Wideo (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`):** wbudowany odtwarzacz `<video controls>` w panelu szczegółów + pełnoekranowy Lightbox + przewijanie `HTTP 206 Partial Content` dla wolumenów prywatnych (przełączane per profil przez `ui.allow_video_preview`).
  - **PDF (`.pdf`):** izolowana w sandboxie ramka `<iframe>` z podglądem PDF wewnątrz Lightboxa (przełączane przez `ui.allow_pdf_preview`).
  - **Audio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) oraz Tekst/Kod (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql` itd. — limit 64 KB).**
  - W trybie `inline` **podwójne kliknięcie** dowolnego pliku z obsługą podglądu (lub naciśnięcie `Spacja`) natychmiast otwiera Lightbox.
- **Inteligentna kolejka przesyłania (Wstrzymaj / Wznów / potwierdzone Anuluj):**
  - Przesyłaj pojedyncze pliki **lub całe hierarchie folderów** (`webkitGetAsEntry`) przez przeciągnij i upuść, albo wklejaj obrazy bezpośrednio ze schowka za pomocą `Ctrl+V`.
  - Przesyłanie w częściach pokazuje bieżący licznik paczek (`2/5 • 40%`), przyciski jednego kliknięcia **Wstrzymaj (`⏸`) / Wznów (`▶`)**, chronione potwierdzeniem **Anuluj (`✕`)**, zbiorcze akcje **Wstrzymaj wszystkie / Anuluj wszystkie** oraz automatyczne sprzątanie niekompletnych plików tymczasowych na serwerze po anulowaniu.
- **Wielokrotne zaznaczanie (w tym lasso), Schowek i głębokie wyszukiwanie:**
  - Kliknij i przeciągnij po pustym obszarze, aby narysować **prostokąt zaznaczenia (lasso / marquee)** wokół wielu plików i folderów (przytrzymaj `Ctrl`/`Cmd`, aby dodać do bieżącego zaznaczenia).
  - Zaznaczanie zakresu `Shift+Klik`, przełączanie pojedynczych elementów `Ctrl+Klik`, `Ctrl+A` (Zaznacz wszystko) oraz `Ctrl+C`/`Ctrl+X`/`Ctrl+V` pomiędzy folderami, a nawet między różnymi wolumenami.
  - **Sortowalne nagłówki kolumn w widoku Lista:** klikaj nagłówki **Nazwa**, **Typ**, **Rozmiar** lub **Data**, aby przełączać sortowanie rosnące/malejące.
  - **Suwak rozmiaru elementów na żywo i karty folderów:** płynnie dostosowuj rozmiar kart (`96 px – 320 px`) oraz przełączaj styl folderów między Kompaktowy (`tile`) i Karta (`card`) w menu Ustawienia (`⚙`).
  - Przełącz przycisk głębokiego wyszukiwania w polu wyszukiwania, aby przeszukiwać rekurencyjnie wszystkie podfoldery — w tym `#tagi` oraz tekst alternatywny `alt`.
  - **⭐ Przypięte foldery Szybkiego dostępu** w panelu bocznym, klikalne okno **Wykorzystanie miejsca i limity** oraz przełącznik motywu kolorystycznego **Automatyczny / Jasny / Ciemny**.
- **Walidacja rozdzielczości i proporcji obrazu w 0 ms (`image_constraints`):** Gdy skonfigurowano wymagania wymiarów lub proporcji (`1:1`, `16:9` itd.), niespełniające ich obrazy są odrzucane natychmiast w przeglądarce jeszcze przed przesłaniem (oraz ponownie weryfikowane po stronie serwera).
- **Metadane redakcyjne, punkt ogniskowy i podpisane linki udostępniania:**
  - Edytuj `alt`, `title`, `caption`, `#tagi`, klikalny selektor **Punktu ogniskowego** `[x, y]` oraz opcjonalną funkcję **✨ Zasugeruj z AI**.
  - Generuj czasowe linki udostępniania podpisane HMAC (`15 minut`, `1 godzina`, `24 godziny`, `7 dni`, `30 dni`).
- **Wbudowany edytor obrazów Canvas:** kadrowanie dowolne lub ze stałymi proporcjami (`1:1`, `4:3`, `16:9`), skalowanie w pikselach (`Maks. szer.` / `Maks. wys.`), wybór formatu wyjściowego (`JPEG`/`PNG`/`WebP`) i jakości, obrót o 90°, odbicie poziome/pionowe oraz zapis jako kopię (`Zapisz jako kopię`) lub nadpisanie (`Nadpisz`).

| Metadane pliku i punkt ogniskowy (`I`) | Wbudowany edytor obrazów Canvas (`E`) |
| :---: | :---: |
| ![Metadane pliku i punkt ogniskowy](../images/file-metadata.png) | ![Wbudowany edytor obrazów Canvas](../images/image-editor.png) |

- **Globalne i18n, RTL i dostępność (WCAG 2.1 AA):**
  - **12 wbudowanych języków interfejsu i automatyczne RTL (`dir="rtl"`):** obsługuje `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` oraz `zh_CN`. Język arabski (`ar`), perski (`fa`), hebrajski (`he`), urdu (`ur`) i pozostałe języki RTL automatycznie odwracają układ, ścieżki nawigacji, panele boczne i kierunek wysuwania OffCanvas przy użyciu logicznych właściwości CSS (`inset-inline`, `margin-inline`).
  - **Bezpieczeństwo CJK / IME:** zapobiega przedwczesnemu wyzwalaniu `Enter` i skrótów podczas wpisywania znaków chińskich, japońskich lub koreańskich (`event.isComposing`).
  - **Pełna dostępność klawiaturowa i dla czytników ekranu:** pułapka fokusu (`Tab`/`Shift+Tab`) w oknach modalnych i Lightboxie, nawigacja strzałkami w menu kontekstowych, semantyka `role="tree"` / `aria-expanded` / `aria-current` oraz automatyczne przywracanie fokusu po zamknięciu okna.
- **Skróty klawiszowe (pełna referencja):**
  - Wszystkie skróty działają wyłącznie wtedy, gdy fokus znajduje się w menedżerze plików, poza polami tekstowymi (`<input>`, `<textarea>`, `<select>`), i ściśle respektują **uprawnienia Votera** użytkownika (`ui.keyboard_shortcuts: true`).
  - Na systemie macOS klawisz **`Cmd (⌘)`** działa zamiennie z `Ctrl`.
  - **Obsługa układów międzynarodowych i ochrona IME:** wykorzystuje fizyczne kody klawiszy (`event.code` `Key*`) z zapasowym mapowaniem znaków, dzięki czemu skróty działają poprawnie także na układach innych niż QWERTY i niełacińskich, pozostając nieaktywne podczas kompozycji IME (`isComposing`).

#### A. Operacje na plikach i folderach
| Skrót | Akcja | Warunek / uwaga |
| :--- | :--- | :--- |
| `N` | Utwórz nowy folder | Poza Koszem (uprawnienie `create_folder`) |
| `U` | Otwórz okno przesyłania plików | Poza Koszem (uprawnienie `upload`) |
| `F2` | Zmień nazwę elementu | Zaznaczony pojedynczy plik lub folder (uprawnienie `rename`) |
| `M` | Otwórz okno Przenieś / Kopiuj | Zaznaczony 1 lub więcej elementów (uprawnienie `move` lub `copy`) |
| `D` | Duplikuj plik | Zaznaczony **pojedynczy plik** (uprawnienie `clone`) |
| `I` | **Folder:** Kolor i ikona / **Plik:** Informacje, SEO i punkt ogniskowy | Zaznaczony pojedynczy element (uprawnienie `edit_metadata`) |
| `E` | Otwórz wbudowany edytor obrazów Canvas | Zaznaczony pojedynczy obraz rastrowy (`.jpg`, `.jpeg`, `.png`, `.webp`) (uprawnienie `upload`) |
| `L` | Kopiuj link do pliku do schowka | Zaznaczony pojedynczy plik (uprawnienie `download`) |
| `S` | Otwórz okno czasowego linku udostępniania HMAC | Zaznaczony pojedynczy plik (uprawnienie `share` + `share.enabled: true`) |
| `Delete` | Usuń zaznaczone elementy (do Kosza lub trwale) | Zaznaczony 1 lub więcej elementów (uprawnienie `delete`) |

#### B. Nawigacja i podgląd
| Skrót | Akcja | Warunek / uwaga |
| :--- | :--- | :--- |
| `/` lub `Ctrl + F` | Przejdź do pola wyszukiwania | Gdy `show_search: true` (`Ctrl + F` działa również wewnątrz pól formularza) |
| `←` `→` `↑` `↓` | Poruszaj się między kartami w siatce lub wierszami listy | — |
| `Enter` | **Folder:** otwórz • **Pojedynczy plik:** otwórz Lightbox w trybie Inline albo zatwierdź wybór w trybie Modal/OffCanvas | — |
| `Spacja` | Podgląd zaznaczonego pliku w Lightboxie | Zaznaczony pojedynczy plik |
| `Backspace` lub `Alt + ↑` | Przejdź do folderu nadrzędnego | Wewnątrz podfolderu |
| `Alt + ←` / `Alt + →` | Przejdź Wstecz / Dalej w historii | Automatycznie odwracane w językach **RTL** (arabski, perski, hebrajski) |
| `R` | Odśwież bieżący katalog z serwera (`?refresh=1`) | Gdy `show_refresh_button: true` |

#### C. Zaznaczanie, Schowek i zamykanie warstw
| Skrót | Akcja | Warunek / uwaga |
| :--- | :--- | :--- |
| `Ctrl + A` | Zaznacz wszystkie widoczne elementy | Poza Koszem |
| `Ctrl + C` / `Ctrl + X` | Kopiuj / Wytnij zaznaczone elementy do Schowka | Gdy żaden tekst nie jest zaznaczony (uprawnienie `copy` / `move`) |
| `Ctrl + V` | Wklej pliki/foldery ze Schowka do bieżącego katalogu **lub** prześlij obraz/plik bezpośrednio ze schowka systemu operacyjnego | Działa także przy otwartym oknie przesyłania |
| `Shift + Klik` / `Ctrl + Klik` | Zaznaczanie zakresu / przełączanie pojedynczych elementów | Możesz też przeciągnąć po pustym obszarze, aby zaznaczyć lassem (marquee) |
| `Esc` | **4-etapowe inteligentne zamykanie:** 1) Zamknij otwarte menu/okno modalne/lightbox/ustawienia/panel mobilny → 2) Wyczyść zaznaczenie → 3) Wyczyść Schowek → 4) Wyjdź z pełnego ekranu w trybie inline | Jedno naciśnięcie zamyka tylko najwyższą warstwę |
| `Tab` / `Shift + Tab` | Przełączaj się między interaktywnymi elementami w otwartym oknie modalnym lub Lightboxie (pułapka fokusu) | — |

#### D. Widok i pomoc
| Skrót | Akcja |
| :--- | :--- |
| `V` | Przełącz między widokiem Siatka a Lista |
| `B` | Pokaż/ukryj lewy panel drzewa folderów |
| `F` | Przełącz tryb pełnoekranowy |
| `T` | Otwórz/zamknij widok Kosza (gdy `trash.enabled: true`) |
| `?` lub arabskie `؟` | Otwórz okno pomocy ze skrótami klawiszowymi |

#### E. Wewnątrz podglądu Lightbox
| Skrót | Akcja |
| :--- | :--- |
| `←` / `→` | Poprzedni / Następny obraz *(automatycznie odwracane w językach RTL)* |
| `+`, `=` lub `Numpad +` | Powiększ |
| `-` lub `Numpad -` | Pomniejsz |
| `0` lub `Numpad 0` | Resetuj powiększenie do `100%` |
| `Esc` | Zamknij Lightbox |

#### F. Wewnątrz otwartego menu kontekstowego
| Skrót | Akcja |
| :--- | :--- |
| `↑` / `↓` | Przesuń w górę / w dół po pozycjach menu |
| `Home` / `End` | Przejdź do pierwszej / ostatniej pozycji menu |
| `Enter` / `Spacja` | Wykonaj zaznaczoną akcję menu |
| `Tab` / `Esc` | Zamknij menu kontekstowe |

---

[← Poprzedni: 02 — Konfiguracja i profile](02-configuration-and-profiles.md) | [Indeks dokumentacji](README.md) | [Dalej: 04 — Motywy, zestawy ikon i personalizacja →](04-themes-icons-and-customization.md)
