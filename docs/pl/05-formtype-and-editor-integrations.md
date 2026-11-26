# 05 — FormType (`FileManagerPickerType`) i integracje z edytorami

[← Poprzedni: 04 — Motywy, ikony i personalizacja](04-themes-icons-and-customization.md) | [Spis treści](README.md) | [Następny: 06 — Bezpieczeństwo i autoryzacja →](06-security-and-authorization.md)

`FileManagerPickerType` integruje się natywnie z komponentem Symfony Form. Obsługuje zarówno **pojedyncze pliki (`?string`)**, jak i **galerie wielu plików (`list<string>` / JSON)**, oferuje **9 motywów**, **5 gotowych wariantów układu**, **dostępne sortowanie galerii metodą przeciągnij i upuść / z klawiatury / dotykiem** oraz pełne wsparcie dla **własnych szablonów Twig (`template`)**.

> **W skrócie: Co obejmuje ten przewodnik?**  
> Kiedy tworzysz formularz produktu, wpisu na blogu lub profilu użytkownika, zamiast zwykłego `<input type="file">`, `FileManagerPickerType` daje Ci gotowe pole Symfony Form, które **otwiera menedżer plików, pozwalając wybrać istniejące zasoby lub wysłać nowe**.  
> Dowiesz się również, jak w kilku linijkach kodu zintegrować **CollectionType / LiveCollectionType**, **7 popularnych edytorów WYSIWYG i blokowych** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js) oraz **EasyAdmin 4/5**.

> [!NOTE]
> Motyw formularza (`@LiveUXFileManager/form/picker.html.twig`) oraz kontener `DialogHost` są **automatycznie rejestrowane i wstrzykiwane** przez pakiet — nie musisz ręcznie edytować `twig.yaml` ani `base.html.twig`.

---

## 1. Wszystkie opcje `FileManagerPickerType`

| Opcja | Typ | Domyślnie | Opis |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Profil menedżera plików, który ma zostać otwarty. |
| `locale` | `?string` | `null` | `null` dziedziczy język profilu; podaj dowolny z 12 wbudowanych języków interfejsu (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`), aby nadpisać go dla konkretnego pola. |
| `volume` | `?string` | `null` | Nazwa początkowego wolumenu (`null` używa pierwszego wolumenu z profilu). |
| `multiple` | `bool` | `false` | `false` = pojedynczy plik (`?string`), `true` = galeria wielu plików (`list<string>`). |
| `max_files` | `?int` | `null` | Maksymalna liczba dozwolonych plików przy `multiple: true` (`null` = bez limitu; egzekwowane również po stronie serwera). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Styl prezentacji okna dialogowego. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Krawędź wysuwania panelu, gdy `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` dziedziczy ustawienie `ui.mobile_dialog_mode` profilu; pozwala nadpisać mobilny (`<= 767px`) układ okna dla pojedynczego pola. |
| `return_type` | `'url'\|'path'` | `'url'` | Określa, czy w bazie danych ma być zapisywany pełny adres URL (`/uploads/a.jpg`), czy ścieżka względna (`a.jpg`) *(zobacz poradnik wyboru poniżej)*. |
| `preview` | `bool` | `true` | Steruje wyświetlaniem miniatur podglądu obrazów/plików we **wszystkich wariantach** (pojedynczym i wielokrotnym). Gdy `false`, wyświetlana jest wyłącznie nazwa/ścieżka pliku. |
| `button_label` | `?string` | `null` | Własna etykieta przycisku wyboru (np. `'Wybierz obraz'`, `'Zmień logo'`). |
| `placeholder` | `?string` | `null` | Tekst zastępczy wyświetlany, gdy nie wybrano żadnego pliku. |
| `theme` | `?string` | `null` | `null` dziedziczy motyw profilu. Akceptuje dowolny z 9 wbudowanych motywów lub nazwę własnego motywu CSS (np. `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` dziedziczy schemat kolorów profilu/domyślny (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Wbudowany wariant układu: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Ścieżka do **w 100% własnego szablonu Twig**, który zastępuje wbudowane warianty (np. `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Standardowa opcja Symfony Form: gdy `true`, pole staje się w pełni tylko do odczytu, a przyciski otwierania, czyszczenia i zmiany kolejności są zablokowane. |

### Co wybrać: `return_type: 'url'` czy `'path'`?
- **`'url'` (domyślne — najprostsze):** Zapisuje w bazie danych `/uploads/products/thermos.webp` lub `https://cdn.example.com/thermos.webp`. W Twig wyświetlasz obraz bezpośrednio przez `<img src="{{ product.coverImage }}">` bez żadnej dodatkowej logiki.
- **`'path'` (elastyczne / ułatwia migrację CDN):** Zapisuje wyłącznie ścieżkę względną `products/thermos.webp`. Jeśli w przyszłości zmienisz dysk lub domenę CDN, nie musisz migrować rekordów w bazie — wystarczy wyświetlać plik przez `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` lub dokleić bazowy adres CDN.

### Wbudowane zabezpieczenia i dostępne sortowanie galerii
- **Utwardzanie żądań POST po stronie serwera:** Nawet jeśli ktoś zmodyfikuje ukryte pole `<input>` w narzędziach deweloperskich przeglądarki, próbując wstrzyknąć `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, uszkodzony JSON powyżej 256 KB, ponad 500 elementów, niepoprawny UTF-8, liczbę plików przekraczającą `max_files` czy tablice HTTP Parameter Pollution (HPP), `FileManagerPickerType` **automatycznie to przechwyci i odrzuci po stronie serwera**.
- **Dostępna zmiana kolejności w galerii (`multiple: true`):** Użytkownicy mogą zmieniać kolejność elementów galerii na **3 sposoby**:
  1. **Przeciągnij i upuść (Drag & Drop)** na komputerach stacjonarnych,
  2. **Strzałki `←` / `→`** po zaznaczeniu karty z klawiatury (kierunek automatycznie odwraca się w językach RTL, takich jak arabski),
  3. **Przyciski `◀` / `▶`** na każdej karcie dla urządzeń mobilnych i dotykowych.

---

## 2. 5 wbudowanych wariantów układu (`variant`)

Opcje `variant` oraz `theme` są całkowicie niezależne. Każdy wariant działa zarówno w trybie **pojedynczym**, jak i **wielokrotnym**, we wszystkich motywach:

| Wariant | Tryb pojedynczy (`multiple: false`) | Tryb wielokrotny (`multiple: true`) | Najlepsze zastosowanie |
| :--- | :--- | :--- | :--- |
| `compact` *(domyślny)* | Kwadratowa miniatura 60×60 + pole tekstowe + przyciski Wybierz/Wyczyść | Karty galerii obok siebie + przerywany kafelek dodawania `+` | Ogólne pola formularzy, galerie produktów |
| `card` | Karta podglądu okładki 16:10 + nazwa pliku + akcje na dole | Siatka kart | Okładki artykułów/bloga, banery hero, zdjęcia główne |
| `dropzone` | Duże klikalne pole z przerywaną ramką + podgląd na żywo | Duże pole dodawania na górze + karty galerii poniżej | Wyeksponowane strefy wyboru multimediów/plików |
| `avatar` | Okrągły podgląd profilu/logo `82×82` + przyciski z boku | Okrągłe miniatury `72×72` + okrągły kafelek `+` | Zdjęcia profilowe użytkowników, logotypy marek |
| `input` | Minimalistyczna jednoliniowa grupa input bez miniatur | Lista usuwalnych tagów/chipów + przycisk `+ Dodaj` | Kompaktowe formularze, pliki PDF, dokumenty, umowy |

![5 wbudowanych wariantów układu FileManagerPickerType i galeria wielu plików](../images/form-pickers.png)

---

## 3. Kompletny przykład: encja Doctrine + FormType

### Encja Doctrine (`src/Entity/Product.php`)
```php
<?php
// src/Entity/Product.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Product
{
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }

    public function setCoverImage(?string $coverImage): self
    {
        $this->coverImage = $coverImage;

        return $this;
    }

    /** @return list<string> */
    public function getGalleryImages(): array
    {
        return $this->galleryImages;
    }

    /** @param list<string> $galleryImages */
    public function setGalleryImages(array $galleryImages): self
    {
        $this->galleryImages = $galleryImages;

        return $this;
    }
}
```

### Klasa formularza Symfony (`src/Form/ProductType.php`)
```php
<?php
// src/Form/ProductType.php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Product;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('coverImage', FileManagerPickerType::class, [
                'label' => 'Zdjęcie główne produktu',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Wybierz okładkę',
                'placeholder' => 'Nie wybrano zdjęcia głównego…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galeria produktu',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Dodaj zdjęcia',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Tworzenie własnego interfejsu FormType (3 podejścia)

Nie jesteś ograniczony do 5 wbudowanych wariantów ani 9 motywów — możesz tworzyć **w 100% własne widoki w czystym HTML / Bootstrap / Tailwind / Alpine** z pełnym wsparciem kontrolera Stimulus.

### Podejście 1: Opcja `template` dla konkretnego pola (najszybsze i najbardziej elastyczne)

Przekaż ścieżkę `'template'` w definicji formularza. Pakiet automatycznie podepnie zewnętrzny kontroler Stimulus (`liveux--file-manager-bundle--picker`) i przekaże renderowanie całego wnętrza do Twojego pliku Twig:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Wybierz baner',
        'placeholder' => 'Nie wybrano jeszcze grafiki okładki',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Dodaj do galerii',
    ]);
```

#### Przykład A — własny szablon pojedynczego wyboru (`templates/forms/custom_single_picker.html.twig`)
```twig
{# templates/forms/custom_single_picker.html.twig #}
<input type="hidden"
       id="{{ id }}"
       name="{{ full_name }}"
       value="{{ value }}"
       data-{{ ctrl }}-target="input"
       {{ isDisabled ? 'disabled' : '' }}
       {% for k, v in attr %}{% if not (k|lower starts with 'on') and v is not same as(false) %}{{ k|e('html_attr') }}{% if v is not same as(true) %}="{{ v }}"{% endif %} {% endif %}{% endfor %} />

<div class="my-custom-banner-card">
    <div class="my-banner-preview"
         data-{{ ctrl }}-target="singlePreview"
         data-action="click->{{ ctrl }}#open"
         style="height: 180px; cursor: pointer;">
    </div>

    <div class="my-banner-bar">
        <span data-{{ ctrl }}-target="fileLabel">{{ value ?: emptyPlaceholder }}</span>
        <div>
            <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">{{ btnLabel }}</button>
            <button type="button" {{ isDisabled ? 'disabled' : '' }} data-{{ ctrl }}-target="clearBtn" data-action="click->{{ ctrl }}#clearSingle">{{ clearLabel }}</button>
        </div>
    </div>
</div>
```

#### Przykład B — własny szablon galerii wielu plików (`templates/forms/custom_gallery_picker.html.twig`)
```twig
{# templates/forms/custom_gallery_picker.html.twig #}
<input type="hidden"
       id="{{ id }}"
       name="{{ full_name }}"
       value="{{ value|default('[]') }}"
       data-{{ ctrl }}-target="input"
       {{ isDisabled ? 'disabled' : '' }}
       {% for k, v in attr %}{% if not (k|lower starts with 'on') and v is not same as(false) %}{{ k|e('html_attr') }}{% if v is not same as(true) %}="{{ v }}"{% endif %} {% endif %}{% endfor %} />

<div class="my-gallery-header">
    <span>Wybrane obrazy: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js renderuje wybrane elementy jako karty z możliwością zmiany kolejności wewnątrz tego kontenera #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Dokumentacja własnego szablonu FormType

| Atrybut / Zdarzenie | Opis |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Wymagane** — element `<input>` przesyłany na serwer razem z formularzem. |
| `data-{{ ctrl }}-target="singlePreview"` | Kontener, w którym w trybie pojedynczym automatycznie renderowany jest podgląd wybranego obrazu/pliku. |
| `data-{{ ctrl }}-target="fileLabel"` | Element tekstowy aktualizowany nazwą/ścieżką wybranego pliku. |
| `data-{{ ctrl }}-target="clearBtn"` | Przycisk czyszczenia; automatycznie ustawiany na `disabled`, gdy pole jest puste lub `isDisabled` ma wartość `true`. |
| `data-{{ ctrl }}-target="galleryGrid"` | Kontener zarządzający kartami wybranych plików i zmianą ich kolejności w trybie wielokrotnym. |
| `data-{{ ctrl }}-target="countLabel"` | Licznik wyświetlający bieżącą liczbę wybranych plików w trybie wielokrotnym. |
| `data-action="click->{{ ctrl }}#open"` | Otwiera okno Modal / OffCanvas menedżera plików. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Czyści aktualny pojedynczy wybór. |
| **Zdarzenie JS:** `liveux:file-manager:picker-changed` | Wywoływane przy każdym wyborze, wyczyszczeniu, usunięciu lub zmianie kolejności elementów; `event.detail` zawiera `{ values, value, multiple }`. |

**Zmienne Twig przekazywane automatycznie do Twojego szablonu:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Podejście 2: Globalne nadpisanie przez Symfony `form_themes`

Aby dostosować wygląd **wszystkich** pól `FileManagerPickerType` w całym projekcie w jednym miejscu, nadpisz blok `liveux_file_manager_picker_widget` we własnym motywie formularza:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Twój globalny, własny kod HTML i CSS #}
{% endblock %}
```

---

### Podejście 3: Wielokrotnego użytku własny typ formularza (dziedziczenie)

Zamiast powtarzać te same opcje `profile`, `theme`, `template` i `mode` w wielu formularzach, utwórz dedykowaną klasę pochodną FormType:

```php
<?php

declare(strict_types=1);

namespace App\Form\Type;

use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductCoverImageType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'profile' => 'product_catalog',
            'theme' => 'corporate',
            'variant' => 'card',
            'mode' => 'offcanvas',
            'position' => 'end',
            'template' => 'forms/custom_single_picker.html.twig',
            'button_label' => 'Wybierz okładkę produktu',
            'placeholder' => 'Nie wybrano zdjęcia głównego…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Teraz możesz używać go w dowolnym formularzu w jednej linijce:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` i Symfony UX `LiveCollectionType`

![Demo na żywo: Symfony CollectionType i LiveCollectionType](../images/demo-collections.gif)

`FileManagerPickerType` bezproblemowo współpracuje zarówno z klasycznym Symfony **`CollectionType`** (dynamiczne dodawanie/usuwanie wierszy przez `data-prototype`), jak i z **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** Kontroler Stimulus `picker` automatycznie podłącza się w chwili wstawienia nowego wiersza do DOM — bez ręcznej inicjalizacji w JavaScript.
- **`LiveCollectionType`:** Nawet gdy Idiomorph przekształca (morphuje) DOM podczas dodawania lub usuwania wierszy, `picker_controller.js` (`render:finished` + `MutationObserver`) automatycznie przerenderowuje wszystkie podglądy pojedyncze oraz karty galerii wielu plików na podstawie aktualnej wartości `<input>`.
- **Typy złożone (Compound) a bezpośrednie pola skalarne:**
  - Gdy używasz **złożonego podformularza**, takiego jak `entry_type: SlideItemType::class`, `LiveCollectionTrait::addCollectionItem()` przesyła `[]` dla nowego wiersza i podformularz obsługuje to bez problemu — bez żadnego dodatkowego kodu.
  - Gdy używasz bezpośrednio pojedynczego (`multiple: false`) `entry_type: FileManagerPickerType::class`, wbudowany listener `PRE_SUBMIT` pickera odrzuca tablice w ramach ochrony przed HPP. Dodanie poniższego 4-linijkowego listenera `PRE_SUBMIT` na kolekcji normalizuje nowo dodane puste wiersze z `[]` na `''`.

### A. Przykład standardowego Symfony `CollectionType`

```php
// src/Form/SlideItemType.php
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class SlideItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Tytuł'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Zdjęcie okładkowe',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Załączniki',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Wewnątrz nadrzędnego FormType:
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

$builder->add('slides', CollectionType::class, [
    'entry_type' => SlideItemType::class,
    'allow_add' => true,
    'allow_delete' => true,
    'prototype' => true,
    'by_reference' => false,
]);
```

```twig
<div id="slides-holder"
     data-index="{{ form.slides|length }}"
     data-prototype="{{ form_widget(form.slides.vars.prototype)|e('html_attr') }}">
    {% for slide in form.slides %}
        <div class="slide-row">{{ form_widget(slide) }}</div>
    {% endfor %}
</div>
<button type="button" onclick="
    const h = document.getElementById('slides-holder');
    const i = Number(h.dataset.index || 0);
    h.insertAdjacentHTML('beforeend', '<div class=slide-row>' + h.dataset.prototype.replace(/__name__/g, i) + '</div>');
    h.dataset.index = i + 1;
">+ Dodaj wiersz</button>
```

### B. Przykład Symfony UX `LiveCollectionType` (`LiveCollectionTrait`)

```php
// src/Form/SliderLiveFormType.php
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

final class SliderLiveFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // 1) Złożony podformularz — nie wymaga dodatkowego listenera PRE_SUBMIT:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) Bezpośredni pojedynczy FileManagerPickerType (lista skalarna):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Potrzebne wyłącznie dla bezpośredniego skalarnego (multiple: false) entry_type:
        // LiveCollectionTrait::addCollectionItem() dodaje [] dla nowych wierszy, więc normalizujemy je do ''.
        $builder->get('logos')->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $e): void {
            $rows = $e->getData();
            if (is_array($rows)) {
                $e->setData(array_map(static fn (mixed $v): mixed => $v === [] ? '' : $v, $rows));
            }
        });
    }
}
```

```php
// src/Twig/Components/SliderFormComponent.php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

#[AsLiveComponent]
final class SliderFormComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(SliderLiveFormType::class);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();
        $data = $this->getForm()->getData();
        // Zapisz dane...
    }
}
```

> [!NOTE]
> Upewnij się, że Twój szablon `LiveCollectionType` wywołuje `{{ form_end(form) }}` (albo zawiera `{{ form_row(form._token) }}`, jeśli przekazujesz `render_rest: false`), aby token CSRF został poprawnie wysłany.

#### Szablon Twig komponentu LiveComponent (`templates/components/SliderFormComponent.html.twig`)
```twig
<div {{ attributes }}>
    {{ form_start(form, { attr: { 'data-action': 'live#action:prevent', 'data-live-action-param': 'save' } }) }}
        {% for slideForm in form.slides %}
            <div class="slide-card">
                {{ form_row(slideForm.title) }}
                {{ form_row(slideForm.image) }}
                {{ form_row(slideForm.gallery) }}
                {{ form_widget(slideForm.vars.button_delete) }}
            </div>
        {% endfor %}

        {{ form_widget(form.slides.vars.button_add) }}
        <button type="submit">Zapisz (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Następnie wyrenderuj komponent w dowolnym szablonie Twig jednym tagiem:
```twig
<twig:SliderFormComponent />
```

---

## 6. Integracje z edytorami tekstu i blokowymi — 7 wbudowanych adapterów (krok po kroku)

> **Szybkie wprowadzenie dla początkujących:**  
> Gdy redaktorzy piszą artykuły na bloga lub opisy produktów, potrzebują wygodnego sposobu na wstawianie obrazów i plików PDF prosto do treści. LiveUX FileManagerBundle zawiera **7 gotowych adapterów JavaScript** dla najpopularniejszych edytorów open-source: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (EasyAdmin `TextEditorField`)**, **Summernote** oraz **Editor.js**. Każdy adapter:
> - Otwiera menedżer plików w natywnej warstwie Top Layer przeglądarki przez `<dialog>` (**zero konfliktów `z-index`** i problemów z focus-trapem za oknami edytora),
> - Automatycznie przenosi metadane SEO (`alt` / `title` / `caption`) dla wybranych obrazów,
> - Waliduje każdy zwracany URL pod kątem wektorów XSS `javascript:` oraz path-traversal (`../`).

Wszystkie siedem adapterów przyjmuje ten sam wspólny zestaw opcji:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Jednolinijkowy wpis w `importmap.php` dla użytkowników AssetMapper:**  
> W projektach z **Webpack Encore** import `import ... from '@liveux/file-manager-bundle/js/integrations/...'` działa od razu.  
> Jeśli korzystasz z **Symfony AssetMapper**, zarejestruj w `importmap.php` **tylko te adaptery, których używasz**, aby przeglądarkowy `<script type="importmap">` rozpoznał ścieżkę (te pliki znajdują się już wewnątrz pakietu — nic nie jest pobierane z internetu):
> ```php
> // importmap.php (dodaj tylko adaptery edytorów, których faktycznie używasz)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. Integracja z TinyMCE 5 / 6 / 7 / 8 (`createTinyMceFilePicker`)

![Integracja na żywo: TinyMCE + LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 oraz 8 współdzielą dokładnie tę samą sygnaturę `file_picker_callback` i zostały **zweryfikowane testami w prawdziwej przeglądarce**. Adapter podpina wbudowane przyciski przeglądania w oknach **Obraz (`image`)**, **Media (`media`)** oraz **Link (`link`)** w TinyMCE bezpośrednio do LiveUX FileManagerBundle.

> **Uwaga dotycząca wersji:** Zachowaj `license_key: 'gpl'` w TinyMCE **7 lub 8**. Jeśli używasz TinyMCE **5 lub 6**, po prostu pomiń tę linię — reszta pozostaje identyczna.

#### Krok 1: Załaduj TinyMCE na stronie (Open Source — bez klucza API)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (z CDN lub własnego katalogu public/) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Krok 2: Podepnij `createTinyMceFilePicker` przez kontroler Stimulus
Utwórz `assets/controllers/tinymce_editor_controller.js` (działa identycznie w **AssetMapper** i **Webpack Encore**):

```javascript
// assets/controllers/tinymce_editor_controller.js
import { Controller } from '@hotwired/stimulus';
import { createTinyMceFilePicker } from '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js';

export default class extends Controller {
    static targets = ['textarea'];

    connect() {
        if (!window.tinymce) {
            return;
        }

        window.tinymce.init({
            target: this.textareaTarget,
            license_key: 'gpl', // Darmowy tryb licencji open-source GPL
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Twój profil z liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'pl',
            }),
        });
    }

    disconnect() {
        if (window.tinymce) {
            const inst = window.tinymce.get(this.textareaTarget.id);
            inst?.remove();
        }
    }
}
```

#### Jak to działa?
Gdy użytkownik kliknie przycisk **Obraz** na pasku TinyMCE, a następnie ikonę przeglądania obok pola **Źródło (Source)**:
1. LiveUX FileManagerBundle otwiera się nad oknem modalnym TinyMCE, wstępnie przefiltrowany do obrazów (`filetype: 'image'`).
2. Po wybraniu obrazu automatycznie uzupełniane są zarówno pole **URL źródłowy**, jak i **Opis alternatywny (`alt`)**!

---

### B. Integracja z CKEditor 5 (`openFileManagerForCKEditor5`)

![Integracja na żywo: CKEditor 5 + LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Współpracuje z kompilacjami CKEditor 5 Classic, Inline, Balloon i Decoupled, umożliwiając wstawianie **pojedynczych lub wielu** obrazów (`insertImage`) oraz linków do dokumentów/PDF jednym przyciskiem.

#### Krok 1: Dodaj CKEditor 5 i przycisk wstawiania mediów
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Wstaw media z menedżera plików
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Krok 2: Podepnij `openFileManagerForCKEditor5`
```javascript
// assets/controllers/ckeditor_editor_controller.js
import { Controller } from '@hotwired/stimulus';
import { openFileManagerForCKEditor5 } from '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js';

export default class extends Controller {
    static targets = ['textarea'];

    async connect() {
        if (!window.ClassicEditor) {
            return;
        }
        this.editor = await window.ClassicEditor.create(this.textareaTarget);
    }

    async insertMedia() {
        if (!this.editor) {
            return;
        }

        await openFileManagerForCKEditor5(this.editor, {
            profile: 'default',
            mode: 'offcanvas', // Wysuwany panel boczny — przeglądasz pliki bez zasłaniania edytora
            position: 'end',
            multiple: true,    // Wstawianie wielu zdjęć naraz
            locale: 'pl',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### Jak to działa?
- **Obrazy** są wstawiane natywnie poleceniem `insertImage` CKEditora 5 jako bloki `<figure class="image">`.
- **Dokumenty (PDF, ZIP itp.)** są wstawiane jako klikalne linki `<a href="...">` z nazwą pliku jako etykietą.

---

### C. Integracja z Tiptap / ProseMirror (`insertFromFileManager`)

![Integracja na żywo: Tiptap 2 + LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Jeśli korzystasz z nowoczesnego edytora headless **Tiptap 2**, integracja z rozszerzeniami `@tiptap/extension-image` oraz `@tiptap/extension-link` wymaga jednego wywołania funkcji.

#### Krok 1: Zainstaluj pakiety
- **W Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **W AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Krok 2: Szablon Twig (pasek narzędzi + obszar edytora + ukryte pole)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Wstaw media (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Krok 3: Napisz kontroler Stimulus z `insertFromFileManager`
```javascript
// assets/controllers/tiptap_editor_controller.js
import { Controller } from '@hotwired/stimulus';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import { insertFromFileManager } from '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js';

export default class extends Controller {
    static targets = ['canvas', 'hiddenInput'];

    connect() {
        this.editor = new Editor({
            element: this.canvasTarget,
            extensions: [
                StarterKit,
                Image.configure({ inline: false }),
                Link.configure({ openOnClick: false }),
            ],
            content: this.hiddenInputTarget.value || '<p>Zacznij pisać treść...</p>',
            onUpdate: ({ editor }) => {
                // Synchronizuj ukryte pole z aktualnym kodem HTML przed wysłaniem formularza
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'pl',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### Jak to działa?
- Obrazy są wstawiane przez `setImage({ src, alt, title })` z zachowaniem atrybutów SEO `alt` oraz `title`.
- Dokumenty (PDF, ZIP itp.) są automatycznie wstawiane jako klikalne linki (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Integracja z Quill 1.x / 2.x (`createQuillImageHandler` i `openFileManagerForQuill`)

![Integracja na żywo: Quill 2 + LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Podpina natywny przycisk **Obraz** na pasku narzędzi Quill do LiveUX FileManagerBundle w jednej linii — dodając atrybuty SEO `alt` do obrazów i wstawiając klikalne linki dla dokumentów.

#### Krok 1: Dołącz CSS/JS Quill oraz kontener edytora
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Krok 2: Napisz kontroler Stimulus z `createQuillImageHandler`
```javascript
// assets/controllers/quill_editor_controller.js
import { Controller } from '@hotwired/stimulus';
import { createQuillImageHandler } from '@liveux/file-manager-bundle/js/integrations/quill_adapter.js';

export default class extends Controller {
    static targets = ['canvas', 'hiddenInput'];

    connect() {
        this.quill = new window.Quill(this.canvasTarget, {
            theme: 'snow',
            modules: {
                toolbar: {
                    container: [[{ header: [1, 2, false] }], ['bold', 'italic'], ['link', 'image']],
                    handlers: {
                        // Kliknięcie wbudowanego przycisku Obraz w Quill otwiera LiveUX FileManagerBundle!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'pl',
                        }),
                    },
                },
            },
        });
        this.quill.on('text-change', () => {
            this.hiddenInputTarget.value = this.quill.root.innerHTML;
        });
    }
}
```

---

### E. Integracja z edytorem Trix — EasyAdmin `TextEditorField` i Turbo (`openFileManagerForTrix`)

![Integracja na żywo: Trix + LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Wstawia bezpieczne załączniki graficzne (`Trix.Attachment`) oraz linki do dokumentów w `<trix-editor>` — domyślnym edytorze WYSIWYG używanym przez **wbudowane pole `TextEditorField` w EasyAdmin** oraz aplikacje Symfony Turbo/Hotwire.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Wstaw z menedżera plików</button>
    <input id="article_body" type="hidden" name="article[content]" value="{{ article.content|default('') }}">
    <trix-editor input="article_body" data-trix-editor-target="editor"></trix-editor>
</div>
```

```javascript
// assets/controllers/trix_editor_controller.js
import { Controller } from '@hotwired/stimulus';
import { openFileManagerForTrix } from '@liveux/file-manager-bundle/js/integrations/trix_adapter.js';

export default class extends Controller {
    static targets = ['editor'];

    async insertMedia() {
        await openFileManagerForTrix(this.editorTarget, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'pl',
        });
    }
}
```

---

### F. Integracja z Summernote (`openFileManagerForSummernote`)

![Integracja na żywo: Summernote + LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Wstawia oczyszczone węzły DOM `<img>` (z atrybutami SEO `alt` i `title`) oraz linki `<a>` do Summernote, szeroko wykorzystywanego w panelach administracyjnych opartych na Bootstrapie.

```javascript
// assets/controllers/summernote_editor_controller.js
import { Controller } from '@hotwired/stimulus';
import { openFileManagerForSummernote } from '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js';

export default class extends Controller {
    static targets = ['textarea'];

    connect() {
        this.$el = window.jQuery(this.textareaTarget);
        this.$el.summernote({ height: 320 });
    }

    async insertMedia() {
        await openFileManagerForSummernote(this.$el, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'pl',
        });
    }
}
```

---

### G. Integracja z Editor.js — edytor blokowy JSON (`openFileManagerForEditorJs`)

![Integracja na żywo: Editor.js + LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Wstawia ustrukturyzowane bloki `image` (`@editorjs/image`) lub `simpleImage` (`@editorjs/simple-image`) oraz linki do dokumentów w **Editor.js** — idealnym rozwiązaniu dla headless CMS i edytorów blokowych w stylu Notion.

```javascript
// assets/controllers/editorjs_controller.js
import { Controller } from '@hotwired/stimulus';
import { openFileManagerForEditorJs } from '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js';

export default class extends Controller {
    static targets = ['holder'];

    connect() {
        this.editor = new window.EditorJS({
            holder: this.holderTarget,
            tools: {
                header: window.Header,
                simpleImage: window.SimpleImage,
            },
        });
    }

    async insertMedia() {
        await openFileManagerForEditorJs(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            imageBlockType: 'simpleImage', // Zostaw 'image', jeśli używasz @editorjs/image
            locale: 'pl',
        });
    }
}
```

---

### H. Integracja z GrapesJS (wizualny kreator stron i e-maili) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Integracja na żywo: GrapesJS + LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

Bezproblemowo integruje się z **GrapesJS (0.20+ / 0.21+ / 0.22+)** na dwa sposoby:
1. **`createGrapesJsAssetManagerConfig()`:** Zastępuje wbudowany modal Asset Managera w GrapesJS oknem LiveUX FileManagerBundle (otwiera się automatycznie po dwukrotnym kliknięciu dowolnego obrazu na płótnie).
2. **`openFileManagerForGrapesJs()`:** Wstawia pojedyncze lub wielokrotne komponenty obrazów/linków bezpośrednio na płótno z poziomu własnego przycisku.

```javascript
// assets/controllers/grapesjs_controller.js
import { Controller } from '@hotwired/stimulus';
import {
    createGrapesJsAssetManagerConfig,
    openFileManagerForGrapesJs,
} from '@liveux/file-manager-bundle/js/integrations/grapesjs_adapter.js';

export default class extends Controller {
    static targets = ['canvas'];

    connect() {
        this.editor = window.grapesjs.init({
            container: this.canvasTarget,
            height: '520px',
            storageManager: false,
            // 1) Zastąp wbudowany Asset Manager menedżerem LiveUX FileManager:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'pl',
            }),
        });
    }

    // 2) Lub wstawiaj obrazy/linki bezpośrednio na płótno z własnego przycisku:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'pl',
        });
    }
}
```

---

### I. Integracja z Meta Lexical (`openFileManagerForLexical`)

![Integracja na żywo: Meta Lexical + LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

Integruje się z rozszerzalnym silnikiem rich-text **Lexical** od Meta (`lexical` 0.15+ / 0.20+ / 0.30+) poprzez wysyłanie poleceń (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) lub własny callback `onInsert` ze ścisłą walidacją URL przeciw XSS oraz metadanymi SEO `altText`.

```javascript
// assets/controllers/lexical_controller.js
import { Controller } from '@hotwired/stimulus';
import { createEditor, createCommand, COMMAND_PRIORITY_EDITOR } from 'lexical';
import { openFileManagerForLexical } from '@liveux/file-manager-bundle/js/integrations/lexical_adapter.js';

export const INSERT_IMAGE_COMMAND = createCommand('INSERT_IMAGE_COMMAND');

export default class extends Controller {
    static targets = ['editable'];

    connect() {
        this.editor = createEditor({
            namespace: 'AppLexicalEditor',
            nodes: [/* Własny węzeł ImageNode */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Wstaw swój własny ImageNode / DecoratorNode do stanu Lexical
                this.editor.update(() => {
                    // $insertNodes([$createImageNode({ src, altText })]);
                });
                return true;
            },
            COMMAND_PRIORITY_EDITOR,
        );
    }

    async insertMedia() {
        await openFileManagerForLexical(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'pl',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. Integracja z EasyAdmin 4 / 5 — krok po kroku (`FileManagerField`)

![Demo na żywo: EasyAdmin 5 + LiveUX FileManagerField](../images/demo-easyadmin.gif)

Wbudowane pole `FileManagerField` nie wymaga żadnych dodatkowych zależności:
- Na stronach **Index** oraz **Detail** automatycznie renderuje miniatury i etykiety wybranych plików (z walidacją bezpieczeństwa URL przez `liveux_fm_describe_field_item()`).
- Na stronach **New** oraz **Edit** renderuje się bezpośrednio jako `FileManagerPickerType`.

### Krok 1: Załaduj zasoby aplikacji w `DashboardController` EasyAdmina (ważne!)

Ponieważ EasyAdmin korzysta z własnego, niezależnego szablonu bazowego (nie dziedziczy z Twojego `base.html.twig`), zarejestruj swój punkt wejścia zasobów `app` w metodzie `DashboardController::configureAssets()`:

```php
<?php
// src/Controller/Admin/DashboardController.php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function configureAssets(): Assets
    {
        // Jeśli używasz AssetMapper:
        return Assets::new()->addAssetMapperEntry('app');

        // Jeśli używasz Webpack Encore, zastąp powyższą linię następującą:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Krok 2: Użyj `FileManagerField` w swoim `CrudController`

```php
<?php
// src/Controller/Admin/ProductCrudController.php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use LiveUX\FileManagerBundle\Bridge\EasyAdmin\FileManagerField;

final class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Nazwa produktu');

        yield FileManagerField::new('coverImage', 'Zdjęcie główne')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Wybierz okładkę');

        yield FileManagerField::new('galleryImages', 'Galeria produktu')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Wszystkie metody płynnego interfejsu `FileManagerField` (16 metod)

| Metoda | Odpowiadająca opcja | Przykład |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('pl')` |
| `setVolume(?string $volume)` | `volume` | `->setVolume('supabase_s3')` |
| `setMultiple(bool $multiple = true)` | `multiple` | `->setMultiple(true)` |
| `setMaxFiles(?int $maxFiles)` | `max_files` | `->setMaxFiles(8)` |
| `setMode(string $mode)` | `mode` | `->setMode('offcanvas')` |
| `setPosition(string $position)` | `position` | `->setPosition('end')` |
| `setMobileMode(?string $mobileMode)` | `mobile_mode` | `->setMobileMode('fullscreen')` |
| `setReturnType(string $returnType)` | `return_type` | `->setReturnType('path')` |
| `setPreview(bool $preview = true)` | `preview` | `->setPreview(false)` |
| `setTheme(?string $theme)` | `theme` | `->setTheme('tabler')` |
| `setColorScheme(?string $colorScheme)` | `color_scheme` | `->setColorScheme('dark')` |
| `setVariant(string $variant)` | `variant` | `->setVariant('avatar')` |
| `setCustomTemplate(?string $template)` | `template` | `->setCustomTemplate('forms/custom.html.twig')` |
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Zmień logo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Jeszcze nie wybrano')` |

---

[← Poprzedni: 04 — Motywy, ikony i personalizacja](04-themes-icons-and-customization.md) | [Spis treści](README.md) | [Następny: 06 — Bezpieczeństwo i autoryzacja →](06-security-and-authorization.md)
