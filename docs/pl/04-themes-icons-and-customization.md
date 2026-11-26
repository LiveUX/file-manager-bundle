# 04 — Motywy, tryb ciemny, personalizacja folderów, zestawy ikon i własny design

[← Poprzedni: 03 — Tryby użycia i JS API](03-usage-modes-and-js-api.md) | [Spis treści](README.md) | [Następny: 05 — FormType i integracje z edytorami →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle został zaprojektowany z **100% brakiem zależności od frameworków CSS**. Niezależnie od tego, czy Twój projekt korzysta z **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material**, czy **w ogóle nie używa żadnego frameworka CSS**, pakiet działa od razu po instalacji bez dodatkowych kroków budowania (nie wymaga konfiguracji `@source` ani purge w Tailwind).

> **W skrócie: Czego nauczysz się z tego przewodnika**  
> 1. **9 wbudowanych motywów i tryb ciemny (Dark Mode):** Dopasuj wygląd i kolorystykę do swojej aplikacji jedną opcją w YAML.  
> 2. **Kolorowanie folderów i ikony (odznaki) folderów:** Personalizuj foldery z poziomu interfejsu lub programistycznie w PHP/bazie danych, korzystając z ponad 18 kolorów i 14 wbudowanych odznak (oraz dowolnej liczby własnych odznak SVG).  
> 3. **Tworzenie własnego zestawu ikon:** Instrukcja krok po kroku budowy własnej paczki ikon obejmującej 9 ikon plików, 49 ikon interfejsu i nieograniczoną liczbę własnych odznak folderów.  
> 4. **Budowanie własnego motywu (3 poziomy swobody):** Motyw firmowy za pomocą samych zmiennych CSS, częściowe nadpisania Twig przez `{% extends %}` lub w 100% headless layout zbudowany od zera.

---

## 1. 9 wbudowanych motywów (który wybrać?)

Motywy można konfigurować przez `defaults.theme`, `profiles.<name>.ui.theme`, właściwość `theme` w TwigComponent albo opcję `theme` w `FileManagerPickerType`. **Wszystkie 9 motywów zostało zweryfikowanych pod kątem standardu kontrastu WCAG 2.1 AA zarówno w trybie jasnym, jak i ciemnym.**

| Klucz motywu | Najlepsze dopasowanie | Podsumowanie stylu |
| :--- | :--- | :--- |
| `bootstrap5` *(domyślny)* | Bootstrap 5 i klasyczne panele administracyjne Symfony | Klasyczny niebieski (`#0d6efd`), zaokrąglenia `10px / 8px / 6px`, czyste neutralne obramowania |
| `tailwind` | Nowoczesne aplikacje webowe oparte na Tailwind CSS v3/v4 | Akcent indygo (`#6366f1`), łagodne zaokrąglenia `14px / 12px / 8px`, paleta Slate |
| `shadcn` | Minimalistyczne, monochromatyczne panele SaaS | Monochromatyczne powierzchnie Zinc (`#18181b` / `#fafafa` w trybie ciemnym), wyraziste krawędzie `8px / 6px` |
| `flowbite` | Panele administracyjne wykorzystujące Flowbite 4 + Tailwind CSS | Niebieski Flowbite (`#1a56db`) i powierzchnie Gray-50/800/900, kontrolki `8px` |
| `daisyui` | Projekty oparte na DaisyUI 5 | **Automatycznie dziedziczy** `--color-primary`, `--color-base-100/200/300` oraz `--radius-box`, gdy DaisyUI jest załadowane na stronie |
| `tabler` | Tabler.io lub korporacyjne panele EasyAdmin | Korporacyjne tło slate, niebieski akcent `#066fd1`, ostre narożniki `4px / 6px` |
| `preline` | Aplikacje korzystające z Preline UI | Czyste neutralne powierzchnie (`#fafafa` / `#171717`) z żywym niebieskim akcentem (`#2563eb`) |
| `material` | Aplikacje w stylu Google Material Design 3 | Tonalne fioletowe powierzchnie (`#6750a4` / `#f3edf7`) i w pełni zaokrąglone przyciski typu pill |
| `unstyled` | Pełne ostylowanie menedżera plików własnym CSS | Dziedziczy czcionkę aplikacji i przezroczyste powierzchnie, zachowując nieprzezroczysty kontrast `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` dla okienek popover i lightbox |

| Tryb ciemny (`bootstrap5` + Dark) | Integracja z motywem DaisyUI 5 (`daisyui`) |
| :---: | :---: |
| ![Menedżer plików w trybie ciemnym](../images/browser-dark.png) | ![Prezentacja motywu DaisyUI 5](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Zasada bezpieczeństwa (YAML a dynamiczne `theme` w czasie działania):**  
> - W pliku `liveux_file_manager.yaml` możesz skonfigurować dowolny z 9 wbudowanych motywów, nazwę własnego motywu CSS (np. `'corporate'`, zgodną z wzorcem `/^[a-zA-Z0-9_-]{1,40}$/`) lub ścieżkę do własnego szablonu Twig (np. `'file_manager/custom.html.twig'` albo `'@App/file_manager'`).  
> - Ze względów bezpieczeństwa dynamiczne wartości `theme` pochodzące z przeglądarki, parametrów zapytania URL lub `window.LiveUXFileManager.open({ theme: ... })` **akceptują wyłącznie 9 wbudowanych kluczy motywów** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Tryb ciemny (Dark Mode), RTL i ograniczenie ruchu

Wszystkie 9 motywów (w tym `unstyled`) obsługują **zarówno automatyczny, jak i ręczny tryb ciemny**:

### A) Automatyczne wykrywanie motywu strony (`color_scheme: 'auto'`)
Gdy element `<html>` lub `<body>` na Twojej stronie ma ustawiony którykolwiek z poniższych atrybutów/klas, menedżer plików, modal `<dialog>` oraz pola `FileManagerPickerType` natychmiast przełączają się w tryb ciemny:
- `data-bs-theme="dark"` *(Bootstrap 5)*
- klasa `.dark` *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / motywy niestandardowe)*

### B) Domyślny lub profilowy schemat kolorów w YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (zgodnie ze stroną) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Uruchom tylko ten profil w trybie ciemnym
```

### C) Preferencja użytkownika końcowego (Menu ustawień: `Auto / Light / Dark`)
Użytkownicy mogą jednym kliknięciem przełączać się między **Auto / ☀ Jasny / 🌙 Ciemny** w wysuwanym menu **⚙ Ustawienia** na górnym pasku narzędzi. Wybór zapisywany jest w przeglądarce w `localStorage` (`liveux_fm_color_scheme`).

### D) Globalne wsparcie RTL i `prefers-reduced-motion`
- Atrybut `dir="rtl"` jest automatycznie ustawiany dla 15 języków pisanych od prawej do lewej, w tym arabskiego (`ar`), perskiego (`fa`), hebrajskiego (`he`) i urdu (`ur`). Wszystkie odstępy w `core.css` wykorzystują logiczne właściwości CSS (`inset-inline`, `padding-inline`, `border-inline-end`).
- Animacje są automatycznie ograniczane dla użytkowników, którzy mają włączoną opcję *„Ogranicz ruch”* (`prefers-reduced-motion: reduce`) w systemie operacyjnym.

---

## 3. Kolorowanie folderów i przypisywanie ikon (odznak) — krok po kroku

![Modal palety kolorów folderu i personalizacji odznak](../images/folder-customize.png)

Każdemu folderowi możesz przypisać własny **kolor**, **ikonę (odznakę)** oraz status **przypięcia (⭐ Przypnij)**. Funkcje te działają, gdy `defaults.metadata.enabled: true` oraz użytkownik posiada uprawnienie `permissions.edit_metadata`.

### Metoda 1: Z poziomu interfejsu (dla użytkowników końcowych)
1. **Kliknij prawym przyciskiem myszy** dowolny folder i wybierz **Personalizuj** (lub naciśnij **`I`** na zaznaczonym folderze, albo kliknij **Personalizuj** w prawym panelu Inspektora).
2. W oknie modalnym **Personalizuj** skonfiguruj trzy opcje z podglądem na żywo:
   - **Paleta 18 gotowych kolorów:** Blue (`#2563eb`), Sky (`#0284c7`), Cyan (`#0891b2`), Teal (`#0d9488`), Emerald (`#059669`), Green (`#16a34a`), Lime (`#65a30d`), Amber (`#d97706`), Orange (`#ea580c`), Red (`#dc2626`), Rose (`#e11d48`), Pink (`#db2777`), Fuchsia (`#c026d3`), Purple (`#9333ea`), Violet (`#7c3aed`), Indigo (`#4f46e5`), Slate (`#475569`), Charcoal (`#1e293b`).
   - **Własny próbnik kolorów + pole HEX:** Wybierz dowolny kolor firmowy za pomocą systemowego próbnika kolorów lub wpisz bezpośrednio kod `#RRGGBB`. Kliknij **Przywróć domyślne**, aby usunąć własne style.
   - **Siatka ikon (odznak) folderów:** Wyświetla wszystkie **14 wbudowanych odznak** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`) oraz wszystkie własne pliki SVG dodane do katalogu `badges/` w Twoim zestawie ikon.
3. Kliknij **Zapisz** — wygląd folderu zaktualizuje się natychmiast zarówno w widoku Siatki/Listy, jak i w lewym drzewie katalogów.

### Metoda 2: Programistycznie z poziomu PHP (np. podczas tworzenia folderu nowego klienta)
Korzystając z `FolderMetadataStorageInterface` oraz `PathNormalizer::buildCanonicalKey()`, możesz przypisywać kolory, odznaki i przypięcia bezpośrednio w kodzie PHP (ten sam kod działa identycznie zarówno ze sterownikiem `json_file`, jak i `doctrine`):

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

        // Zbuduj kanoniczny klucz (np. "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Morski/Teal (#RRGGBB)
            icon: 'lock',       // Jedna z 14 wbudowanych odznak lub własna badges/invoice.svg -> 'invoice'
            pinned: true,       // Dodaj do sekcji ⭐ Przypięte foldery w panelu bocznym
        ));
    }
}
```

### Metoda 3: Bezpośrednio w `metadata.json` (przy użyciu `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Zestawy ikon i budowanie własnego zestawu od zera

**Każda ikona** w pakiecie — typy plików, foldery, górny pasek narzędzi, menu kontekstowe, oznaczenia dysków chmurowych, panel inspektora i odznaki folderów — jest serwowana z jednego wspólnego rejestru `IconSetRegistry`. 6 wbudowanych zestawów ikon jest buforowanych w cache PSR-6 przez 24 godziny (`liveux_fm_iconset_*`).

### 6 wbudowanych zestawów ikon (`profiles.<name>.ui.icon_set`)
| Zestaw ikon | Styl wizualny | Domyślny kolor folderu (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(domyślny)* | Płaskie, wyraziste ikony wektorowe | `#2563eb` |
| `windows11` | Dwutonowe, ciepłe żółte foldery w stylu Windows 11 i nowoczesne ikony dokumentów | `#f59e0b` |
| `macos` | Miękkie niebieskie foldery inspirowane macOS Finder | `#3b82f6` |
| `linux` | Czysta, otwartoźródłowa estetyka GNOME / Adwaita | `#3584e4` |
| `minimal` | Minimalistyczne ikony konturowe (cienką kreską) | `#475569` |
| `vscode` | Ostre, developerskie ikony w stylu VS Code | `#dcb67a` |

### Krok po kroku: Tworzenie własnego zestawu ikon

#### Krok 1: Utwórz katalog na ikony w swoim projekcie
Utwórz np. `assets/fm-icons/`. **Nie musisz kopiować ikon, których nie chcesz zmieniać** — każda brakująca ikona automatycznie korzysta z domyślnego zestawu `classic` / `_ui` / `_folder_badges`!

```text
assets/fm-icons/
├── meta.json               # Domyślny kolor folderu: { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Opcjonalnie) Własna główna ikona folderu
├── pdf.svg                 # (Opcjonalnie) Nadpisanie samej ikony PDF
├── ui/                     # (Opcjonalnie) Nadpisanie lub dodanie ikon paska narzędzi, menu kontekstowego i wolumenów
│   ├── upload.svg          # Nadpisanie ikony Wyślij (Upload)
│   ├── new_folder.svg      # Nadpisanie ikony Nowy folder
│   └── company_cloud.svg   # Dodanie nowej własnej ikony wolumenu -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Opcjonalnie) Nadpisanie istniejących odznak folderów lub dodanie NOWYCH
    ├── lock.svg            # Nadpisanie wbudowanej odznaki "lock"
    └── invoice.svg         # Dodanie NOWEJ odznaki -> automatycznie pojawia się w modalu Personalizuj folder!
```

#### Krok 2: Utwórz `meta.json` oraz `folder.svg` z dynamicznym kolorem

> [!TIP]
> **Złota zasada (`currentColor`):** Aby Twoja własna ikona `folder.svg` automatycznie dostosowywała się za każdym razem, gdy użytkownik zmieni kolor folderu, używaj **`fill="currentColor"`** (lub `stroke="currentColor"` w ikonach konturowych) zamiast wpisanych na sztywno kodów hex!

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
<!-- assets/fm-icons/badges/invoice.svg (Nowa własna odznaka folderu) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Krok 3: Wskaż katalog w profilu YAML
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Archiwum firmowe'
            icon: 'company_cloud' # Używa pliku assets/fm-icons/ui/company_cloud.svg!
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Bezpieczeństwo i limity własnych ikon (`IconSetRegistry`)
- Każdy plik `.svg` może mieć rozmiar maksymalnie **256 KB** (`262 144` bajty) i podczas ładowania jest automatycznie oczyszczany przez **`SvgSanitizer`** (usuwanie tagów `<script>`, atrybutów `on*` itp.).
- Katalogi `ui/` oraz `badges/` skanują do **200 własnych plików `.svg`** oprócz kluczy wbudowanych.
- Nazwy własnych plików (bez rozszerzenia `.svg`) muszą spełniać wzorzec **`/^[a-zA-Z0-9_-]{1,32}$/`** (litery ASCII, cyfry, `-` oraz `_`, maks. 32 znaki).

#### Pełna lista kluczy ikon możliwych do nadpisania
- **9 ikon plików i folderów (katalog główny `<dir>/<key>.svg`):**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 ikon interfejsu (`<dir>/ui/<key>.svg`):**
  - *Nawigacja i układ:* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Akcje na plikach i folderach:* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Dyski i dostawcy chmurowi:* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 wbudowanych odznak folderów (`<dir>/badges/<key>.svg`):**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(plus dowolne własne dodane pliki `*.svg`!)*

---

## 5. Budowanie własnego motywu (3 poziomy swobody)

`Browser.html.twig` rozpoznaje wartość opcji `theme` w Twoim profilu w tej **dokładnej kolejności priorytetów**:
1. Kończy się na `.html.twig` → Renderuje bezpośrednio Twój szablon Twig (`{% include theme %}`).
2. Zaczyna się od `@` → Renderuje `layout.html.twig` wewnątrz wskazanej przestrzeni nazw Twig (`{% include theme ~ '/layout.html.twig' %}`).
3. Pasuje do jednego z 9 wbudowanych motywów → Renderuje wbudowany motyw pakietu.
4. Dowolny inny krótki identyfikator (np. `'corporate'`, pasujący do `/^[a-zA-Z0-9_-]{1,40}$/`) → Otacza domyślny layout bazowy elementem `<div data-liveux-theme="corporate">`.

### Poziom 1: Motyw firmowy za pomocą samych zmiennych CSS (najszybszy — zero szablonów!)

#### Pełna lista zmiennych CSS (`--liveux-fm-*`)
| Kategoria | Zmienne CSS |
| :--- | :--- |
| **Typografia** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Powierzchnie i obramowania** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Kolory tekstu** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Kolory akcentu marki** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Semantyczne kolory statusów (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Zaokrąglenia i cienie** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Gotowy do skopiowania własny motyw firmowy (`assets/styles/app.css`)
```css
/* 1) Paleta marki w trybie jasnym */
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

/* 2) Odpowiednik w trybie ciemnym (obejmuje zarówno Browser, jak i FormType Picker — w trybie auto i ręcznym) */
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

### Poziom 2: Częściowe nadpisania w Twig z `{% extends %}` (zalecane!)

Bazowy layout zbudowany jest w oparciu o **10 bloków `{% block %}`** oraz **7 modularnych szablonów częściowych (`parts/`)**. Możesz dostosować np. sam górny pasek lub stopkę bez kopiowania całego szablonu:

| Nazwa bloku | Zakres | Domyślny szablon częściowy |
| :--- | :--- | :--- |
| `fm_shell` | Zewnętrzny kontener główny (`.liveux-fm-shell`) | Otacza wszystkie zagnieżdżone bloki |
| `fm_topbar` | Górny pasek poleceń (nawigacja, ścieżka breadcrumbs, wyszukiwanie, sortowanie, akcje) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Rozwijane menu ustawień (tryb kolorów, rozmiar elementów, styl folderów, cache, skróty) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Pasek powiadomień statusu + kolejka wysyłania plików | Kod inline |
| `fm_body` | Główny obszar centralny (kontener: panel boczny + główny obszar + inspektor) | `.liveux-fm-body` |
| `fm_sidebar` | Lewe drzewo folderów + przypięte foldery + pasek limitu miejsca (quota) + kosz | `parts/_sidebar.html.twig` |
| `fm_main` | Siatka/lista plików + pływający pasek zaznaczenia/schowka + strefa dropzone + paginacja | `parts/_main.html.twig` |
| `fm_inspector` | Prawy panel podglądu i metadanych (Inspektor) | `parts/_inspector.html.twig` |
| `fm_footer` | Stopka trybu Modal / OffCanvas | `parts/_footer.html.twig` |
| `fm_overlays` | Menu kontekstowe, modal, lightbox oraz szablony skrótów / informacji o aplikacji | `parts/_overlays.html.twig` |

#### Przykład — firmowy pasek nagłówkowy + własna stopka wyboru
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Firmowe centrum zasobów cyfrowych</span>
        <span>Profil: <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Anuluj</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Zatwierdź wybór</button>
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
                # 1) Bezpośrednia ścieżka do szablonu .html.twig:
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) Lub przestrzeń nazw Twig (automatycznie wczyta layout.html.twig wewnątrz):
                # theme: '@App/file_manager'
```

---

### Poziom 3: W 100% headless layout od zera (wymagane targety Stimulus)

Jeśli chcesz zbudować własną strukturę HTML całkowicie od podstaw bez `{% extends %}`, wystarczy podpiąć poniższe atrybuty `data-{{ ctrl }}-target` z prefiksem `ctrl = 'liveux--file-manager-bundle--browser'`:

| Target (`data-...-target`) | Wymagany? | Przeznaczenie |
| :--- | :---: | :--- |
| `shell` | Zalecany | Skala rozmiaru elementów (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Wymagany** | Główny obszar, w którym renderowane są karty oraz wiersze plików i folderów |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Wymagany** | Kontener okien modalnych dla akcji Nowy folder, Wyślij, Usuń, Personalizuj, Limit miejsca i Metadane |
| `breadcrumbs` | Zalecany | Klikalna ścieżka nawigacji (breadcrumbs) |
| `confirmSelectionBtn`, `footerStatus` | Zalecany (Modal/OffCanvas) | Przycisk zatwierdzania wyboru (automatycznie `disabled`, gdy nic nie zaznaczono) oraz tekst statusu |
| `statusBanner`, `uploadQueue` | Zalecany | Komunikaty błędów/sukcesu i kolejka postępu wysyłania w częściach (chunked upload) |
| `tree` + `sidebar` + `pinnedFolders` | Opcjonalny | Lewe drzewo folderów i przypięte foldery |
| `contextMenu` | Opcjonalny | Kontener menu kontekstowego pod prawym przyciskiem myszy |
| `inspector` | Opcjonalny | Prawy panel podglądu i metadanych |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Opcjonalny | Uniwersalny podgląd multimediów w oknie lightbox |

---

## 6. 7 funkcji pomocniczych Twig do własnych szablonów

| Funkcja Twig | Przeznaczenie | Przykładowe użycie |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Renderuje bezpieczny pod kątem XSS kod SVG inline (bez potrzeby używania `\|raw`). `group`: `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Generuje bezpieczny adres URL miniatury pliku. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Generuje bezpieczny adres URL do pobrania lub podglądu inline. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Pobierz</a>` |
| `liveux_fm_trans(key, params, locale)` | Tłumaczy klucz wiadomości pakietu z gwarantowanym fallbackiem `'en'`. | `{{ liveux_fm_trans('ui.upload', {}, 'pl') }}` |
| `liveux_fm_bcp47_locale(locale)` | Konwertuje kody lokalizacji PHP/ICU, takie jak `en_US.UTF-8`, na poprawne tagi HTML `lang="..."` w standardzie BCP-47 (`en-US`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Zwraca `true`, jeśli podana lokalizacja jest pisana od prawej do lewej (arabski, perski, hebrajski itp.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Bezpiecznie normalizuje wartości z Form/EasyAdmin do gotowych opisów obrazów/plików (blokując adresy `javascript:`/`data:`). | Widoki Index/Detail w EasyAdmin oraz własne szablony |

---

[← Poprzedni: 03 — Tryby użycia i JS API](03-usage-modes-and-js-api.md) | [Spis treści](README.md) | [Następny: 05 — FormType i integracje z edytorami →](05-formtype-and-editor-integrations.md)
