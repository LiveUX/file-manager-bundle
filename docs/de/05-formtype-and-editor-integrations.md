# 05 — FormType (`FileManagerPickerType`) & Editor-Integrationen

[← Zurück: 04 — Themes, Icons & Anpassung](04-themes-icons-and-customization.md) | [Übersicht](README.md) | [Weiter: 06 — Sicherheit & Autorisierung →](06-security-and-authorization.md)

`FileManagerPickerType` integriert sich nativ in die Symfony-Form-Komponente. Der Feldtyp unterstützt sowohl **Einzeldateien (`?string`)** als auch **Mehrfach-Galerien (`list<string>` / JSON)**, bietet **9 Themes**, **5 sofort einsatzbereite Layout-Varianten**, **barrierefreies Sortieren von Galerien per Drag-and-Drop, Tastatur und Touch** sowie vollständige Unterstützung für **benutzerdefinierte Twig-Templates (`template`)**.

> **Auf einen Blick: Was behandelt dieser Leitfaden?**  
> Wenn Sie ein Produkt-, Blogbeitrags- oder Benutzerprofil-Formular erstellen, erhalten Sie mit `FileManagerPickerType` statt eines einfachen `<input type="file">` ein fertiges Symfony-Formularfeld, das **den Dateimanager öffnet, sodass Benutzer bestehende Medien auswählen oder neue Dateien hochladen können**.  
> Außerdem erfahren Sie, wie Sie **CollectionType / LiveCollectionType**, **7 beliebte WYSIWYG- & Block-Editoren** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js) sowie **EasyAdmin 4/5** mit nur wenigen Zeilen Code integrieren.

> [!NOTE]
> Das Form-Theme (`@LiveUXFileManager/form/picker.html.twig`) und der `DialogHost`-Container werden vom Bundle **automatisch registriert und eingebunden** – Sie müssen weder `twig.yaml` noch `base.html.twig` anfassen.

---

## 1. Alle Optionen von `FileManagerPickerType`

| Option | Typ | Standard | Beschreibung |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Dateimanager-Profil, das geöffnet werden soll. |
| `locale` | `?string` | `null` | `null` übernimmt die Sprache des Profils; übergeben Sie eine der 12 integrierten UI-Sprachen (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`), um die Sprache pro Feld zu überschreiben. |
| `volume` | `?string` | `null` | Name des Start-Volumes (`null` verwendet das erste Volume des Profils). |
| `multiple` | `bool` | `false` | `false` = Einzeldatei (`?string`), `true` = Mehrfach-Galerie (`list<string>`). |
| `max_files` | `?int` | `null` | Maximal zulässige Anzahl an Dateien bei `multiple: true` (`null` = unbegrenzt; wird auch serverseitig strikt durchgesetzt). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Darstellungsart des Dialogs. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Einblendeseite bei `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` übernimmt `ui.mobile_dialog_mode` des Profils; überschreibt das mobile Dialog-Layout (`<= 767px`) pro Feld. |
| `return_type` | `'url'\|'path'` | `'url'` | Legt fest, ob eine vollständige URL (`/uploads/a.jpg`) oder ein relativer Pfad (`a.jpg`) in Ihrer Datenbank gespeichert wird *(siehe Entscheidungshilfe unten)*. |
| `preview` | `bool` | `true` | Steuert Bild-/Dateivorschau-Thumbnails in **allen Varianten** (Einzel- und Mehrfachauswahl). Bei `false` wird nur der Dateiname/Pfad angezeigt. |
| `button_label` | `?string` | `null` | Eigene Beschriftung für die Auswahl-Schaltfläche (z. B. `'Bild auswählen'`, `'Logo ändern'`). |
| `placeholder` | `?string` | `null` | Platzhaltertext, wenn noch keine Datei ausgewählt ist. |
| `theme` | `?string` | `null` | `null` übernimmt das Profil-Theme. Akzeptiert jedes der 9 integrierten Themes oder Ihren eigenen CSS-Theme-Namen (z. B. `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` übernimmt das Profil-/Standard-Farbschema (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Integrierte Layout-Variante: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Pfad zu einem **zu 100 % eigenen Twig-Template**, das die integrierten Varianten ersetzt (z. B. `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Standard-Option von Symfony Form: Bei `true` ist das Feld vollständig schreibgeschützt und alle Öffnen-/Löschen-/Sortier-Steuerelemente sind gesperrt. |

### Sollte ich `return_type: 'url'` oder `'path'` wählen?
- **`'url'` (Standard – Am einfachsten):** Speichert `/uploads/products/thermos.webp` oder `https://cdn.example.com/thermos.webp` in der Datenbank. In Twig rendern Sie das Bild ohne Zusatzlogik direkt mit `<img src="{{ product.coverImage }}">`.
- **`'path'` (Flexibel / Ideal für CDN-Migrationen):** Speichert nur den relativen Pfad `products/thermos.webp`. Wenn Sie später den Speicherort oder die CDN-Domain wechseln, müssen Sie keine Datenbankeinträge migrieren – rendern Sie einfach über `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` oder stellen Sie Ihre CDN-Basis-URL voran.

### Integrierte Sicherheit & barrierefreie Galerie-Sortierung
- **Serverseitige POST-Härtung:** Selbst wenn jemand das versteckte `<input>`-Feld in den Browser-DevTools manipuliert, um `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, fehlerhaftes JSON über 256 KB, mehr als 500 Einträge, ungültiges UTF-8, Werte über `max_files` oder HTTP-Parameter-Pollution-(HPP-)Arrays einzuschleusen, **fängt `FileManagerPickerType` dies serverseitig automatisch ab und weist die Eingabe zurück**.
- **Barrierefreie Galerie-Sortierung (`multiple: true`):** Benutzer können Galerie-Elemente auf **3 Arten** neu anordnen:
  1. **Drag & Drop** am Desktop,
  2. **Pfeiltasten `←` / `→`**, wenn eine Karte den Fokus hat (die Richtung kehrt sich in RTL-Sprachen wie Arabisch automatisch um),
  3. **Schaltflächen `◀` / `▶`** auf jeder Karte für Mobil- und Touch-Geräte.

---

## 2. 5 integrierte Layout-Varianten (`variant`)

`variant` und `theme` sind vollständig unabhängig voneinander. Jede Variante funktioniert sowohl im **Einzel-** als auch im **Mehrfachmodus** in allen Themes:

| Variante | Einzelmodus (`multiple: false`) | Mehrfachmodus (`multiple: true`) | Idealer Einsatzbereich |
| :--- | :--- | :--- | :--- |
| `compact` *(Standard)* | Quadratisches 60×60-Thumbnail + Eingabefeld + Auswählen/Leeren-Zeile | Nebeneinander liegende Galerie-Karten + gestrichelte `+`-Kachel | Allgemeine Formularfelder, Produktgalerien |
| `card` | 16:10-Titelbild-Vorschaukarte + Dateiname + Aktionsleiste unten | Kartenraster | Blog-/Artikel-Titelbilder, Hero-Banner, Beitragsbilder |
| `dropzone` | Großes klickbares gestricheltes Feld + Live-Vorschau | Großes Hinzufügen-Feld oben + Galerie-Karten darunter | Prominente Medien-/Datei-Upload-Bereiche |
| `avatar` | Kreisförmige `82×82`-Profil-/Logo-Vorschau + Schaltflächen daneben | Kreisförmige `72×72`-Avatar-Chips + runde `+`-Kachel | Benutzerprofilbilder, Markenlogos |
| `input` | Minimale einzeilige Eingabegruppe ohne Thumbnails | Entfernbare Chip-/Tag-Liste + `+ Hinzufügen` | Kompakte Formulare, PDFs, Dokumente, Verträge |

![FileManagerPickerType: 5 integrierte Layout-Varianten und Mehrfach-Galerie](../images/form-pickers.png)

---

## 3. Vollständiges Entity- + FormType-Beispiel

### Doctrine-Entity (`src/Entity/Product.php`)
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

### Symfony-Formularklasse (`src/Form/ProductType.php`)
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
                'label' => 'Produkt-Titelbild',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Titelbild wählen',
                'placeholder' => 'Kein Titelbild ausgewählt…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Produktgalerie',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Bilder hinzufügen',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Eigene FormType-Oberfläche erstellen (3 Ansätze)

Sie sind niemals auf die 5 integrierten Varianten oder 9 Themes beschränkt – Sie können **zu 100 % eigene HTML-, Bootstrap-, Tailwind- oder Alpine-Designs** mit vollständiger Stimulus-Anbindung umsetzen.

### Ansatz 1: Feldbezogene Option `template` (Am schnellsten & flexibelsten)

Übergeben Sie in Ihrer Formulardefinition einen `'template'`-Pfad. Das Bundle bindet automatisch den äußeren Stimulus-Controller (`liveux--file-manager-bundle--picker`) ein und delegiert das gesamte innere Markup an Ihre Twig-Datei:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Banner auswählen',
        'placeholder' => 'Noch kein Titelbild ausgewählt',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Zur Galerie hinzufügen',
    ]);
```

#### Beispiel A — Eigenes Einzel-Picker-Template (`templates/forms/custom_single_picker.html.twig`)
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

#### Beispiel B — Eigenes Mehrfach-Galerie-Template (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Ausgewählte Bilder: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js rendert ausgewählte Elemente als sortierbare Karten in diesem Container #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Referenz für benutzerdefinierte FormType-Templates

| Attribut / Event | Beschreibung |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Erforderlich** – Das `<input>`-Element, das mit dem Formular an den Server gesendet wird. |
| `data-{{ ctrl }}-target="singlePreview"` | Container, in dem im Einzelmodus automatisch die ausgewählte Bild-/Dateivorschau gerendert wird. |
| `data-{{ ctrl }}-target="fileLabel"` | Textelement, das automatisch mit dem ausgewählten Dateinamen/Pfad aktualisiert wird. |
| `data-{{ ctrl }}-target="clearBtn"` | Schaltfläche zum Leeren; wird automatisch auf `disabled` gesetzt, wenn das Feld leer oder `isDisabled` gleich `true` ist. |
| `data-{{ ctrl }}-target="galleryGrid"` | Container, der im Mehrfachmodus die ausgewählten Dateikarten und deren Sortierung verwaltet. |
| `data-{{ ctrl }}-target="countLabel"` | Zählerelement, das im Mehrfachmodus die aktuelle Anzahl ausgewählter Dateien anzeigt. |
| `data-action="click->{{ ctrl }}#open"` | Öffnet den Modal- / OffCanvas-Dialog des Dateimanagers. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Leert die aktuelle Einzelauswahl. |
| **JS-Event:** `liveux:file-manager:picker-changed` | Wird immer ausgelöst, wenn Elemente ausgewählt, geleert, entfernt oder neu sortiert werden; `event.detail` enthält `{ values, value, multiple }`. |

**Twig-Variablen, die automatisch an Ihr eigenes Template übergeben werden:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Ansatz 2: Projektweite Überschreibung über Symfony `form_themes`

Um das Erscheinungsbild **aller** `FileManagerPickerType`-Felder im gesamten Projekt zentral anzupassen, überschreiben Sie den Block `liveux_file_manager_picker_widget` in Ihrem eigenen Form-Theme:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Ihr projektweites eigenes HTML- & CSS-Markup #}
{% endblock %}
```

---

### Ansatz 3: Wiederverwendbare eigene Child-FormType-Klasse

Statt dieselben Optionen für `profile`, `theme`, `template` und `mode` in mehreren Formularen zu wiederholen, erstellen Sie eine eigene FormType-Unterklasse:

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
            'button_label' => 'Produkt-Titelbild wählen',
            'placeholder' => 'Kein Titelbild ausgewählt…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Nun nutzen Sie das Feld in jedem Formular mit einer einzigen Zeile:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` & Symfony UX `LiveCollectionType`

![Live-Demo von Symfony CollectionType und LiveCollectionType](../images/demo-collections.gif)

`FileManagerPickerType` funktioniert reibungslos sowohl mit dem klassischen Symfony **`CollectionType`** (dynamisches Hinzufügen/Entfernen von Zeilen per `data-prototype`) als auch mit **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** Der Stimulus-Controller `picker` verbindet sich automatisch, sobald eine neue Zeile in das DOM eingefügt wird – eine manuelle JavaScript-Initialisierung ist nicht erforderlich.
- **`LiveCollectionType`:** Selbst wenn Idiomorph das DOM beim Hinzufügen oder Löschen von Zeilen aktualisiert (Morphing), rendert `picker_controller.js` (`render:finished` + `MutationObserver`) automatisch jede Einzelvorschau und jede Mehrfach-Galeriekarte auf Basis des aktuellen `<input>`-Werts neu.
- **Zusammengesetzte vs. direkte skalare Entry-Typen:**
  - Bei einem **zusammengesetzten Unterformular** wie `entry_type: SlideItemType::class` sendet `LiveCollectionTrait::addCollectionItem()` für die neue Zeile `[]`, was das Unterformular problemlos verarbeitet – kein zusätzlicher Code erforderlich.
  - Bei direkter Verwendung eines Einzeldatei-`FileManagerPickerType` (`multiple: false`) als `entry_type: FileManagerPickerType::class` weist der integrierte `PRE_SUBMIT`-Listener des Pickers Array-Payloads im Rahmen des HPP-Schutzes ab. Der unten gezeigte vierzeilige `PRE_SUBMIT`-Listener auf der Collection normalisiert neu hinzugefügte leere Zeilen sauber von `[]` auf `''`.

### A. Beispiel mit klassischem Symfony `CollectionType`

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
            ->add('title', TextType::class, ['label' => 'Titel'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Titelbild',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Anhänge',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Im übergeordneten FormType:
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
">+ Zeile hinzufügen</button>
```

### B. Beispiel mit Symfony UX `LiveCollectionType` (`LiveCollectionTrait`)

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
            // 1) Zusammengesetztes Unterformular – kein zusätzlicher PRE_SUBMIT-Listener nötig:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) Direkter Einzeldatei-FileManagerPickerType (skalare Liste):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Nur bei direktem skalarem entry_type (multiple: false) erforderlich:
        // LiveCollectionTrait::addCollectionItem() hängt für neue Zeilen [] an – wir normalisieren das zu ''.
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
        // Daten speichern...
    }
}
```

> [!NOTE]
> Achten Sie darauf, dass Ihr `LiveCollectionType`-Template `{{ form_end(form) }}` aufruft (oder `{{ form_row(form._token) }}` einbindet, falls Sie `render_rest: false` verwenden), damit das CSRF-Token mitgesendet wird.

#### Twig-Template der LiveComponent (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Speichern (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Anschließend binden Sie die Komponente überall in Twig mit einem einzigen Tag ein:
```twig
<twig:SliderFormComponent />
```

---

## 6. Rich-Text- & Block-Editor-Integrationen — 7 integrierte Adapter (Schritt für Schritt)

> **Kurzer Überblick für Einsteiger:**  
> Wenn Redakteure Blogbeiträge oder Produktbeschreibungen verfassen, benötigen sie einen nahtlosen Weg, Bilder oder PDFs direkt in den Inhalt einzufügen. LiveUX FileManagerBundle liefert **7 fertige JavaScript-Adapter** für die beliebtesten Open-Source-Editoren mit: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (EasyAdmin `TextEditorField`)**, **Summernote** und **Editor.js**. Jeder Adapter:
> - öffnet den Dateimanager im nativen `<dialog>`-Top-Layer des Browsers (**keine `z-index`-Konflikte** oder Focus-Trap-Probleme hinter Editor-Popups),
> - übernimmt bei ausgewählten Bildern automatisch die SEO-Metadaten (`alt` / `title` / `caption`),
> - validiert jede zurückgegebene URL gegen `javascript:`- und Path-Traversal-(`../`-)XSS-Vektoren.

Alle sieben Adapter akzeptieren dieselben gemeinsamen Optionen:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Einzeiliger Eintrag in `importmap.php` für AssetMapper-Nutzer:**  
> In **Webpack-Encore**-Projekten funktioniert `import ... from '@liveux/file-manager-bundle/js/integrations/...'` direkt.  
> Wenn Sie **Symfony AssetMapper** nutzen, registrieren Sie in `importmap.php` **nur den bzw. die Adapter, die Sie tatsächlich verwenden**, damit der Browser-`<script type="importmap">`-Block den Pfad auflösen kann (diese Dateien liegen bereits im Bundle – es wird nichts aus dem Internet heruntergeladen):
> ```php
> // importmap.php (nur die tatsächlich genutzten Editor-Adapter eintragen)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. TinyMCE 5 / 6 / 7 / 8 Integration (`createTinyMceFilePicker`)

![Live-Integration von TinyMCE + LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 und 8 teilen exakt dieselbe `file_picker_callback`-Signatur und sind **durch Live-Browsertests verifiziert**. Der Adapter verbindet die integrierten Durchsuchen-Schaltflächen in den TinyMCE-Dialogen **Bild (`image`)**, **Medien (`media`)** und **Link (`link`)** direkt mit LiveUX FileManagerBundle.

> **Versionshinweis:** Behalten Sie `license_key: 'gpl'` bei, wenn Sie TinyMCE **7 oder 8** verwenden. Bei TinyMCE **5 oder 6** lassen Sie diese Zeile einfach weg – alles andere bleibt identisch.

#### Schritt 1: TinyMCE auf Ihrer Seite laden (Open Source – kein API-Schlüssel erforderlich)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (über CDN oder Ihr eigenes public/-Verzeichnis) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Schritt 2: `createTinyMceFilePicker` über einen Stimulus-Controller anbinden
Erstellen Sie `assets/controllers/tinymce_editor_controller.js` (funktioniert identisch mit **AssetMapper** und **Webpack Encore**):

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
            license_key: 'gpl', // Kostenloser Open-Source-GPL-Lizenzmodus
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Ihr Profil aus liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'de',
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

#### Was passiert dabei?
Wenn ein Benutzer in der TinyMCE-Symbolleiste auf **Bild** und neben **Quelle** auf das Durchsuchen-Symbol klickt:
1. LiveUX FileManagerBundle öffnet sich über dem TinyMCE-Modal und ist bereits auf Bilder vorgefiltert (`filetype: 'image'`).
2. Sobald der Benutzer ein Bild auswählt, werden sowohl die **Quell-URL** als auch die **Alternativbeschreibung (`alt`)** automatisch ausgefüllt!

---

### B. CKEditor-5-Integration (`openFileManagerForCKEditor5`)

![Live-Integration von CKEditor 5 + LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Funktioniert mit CKEditor 5 Classic-, Inline-, Balloon- und Decoupled-Builds zum Einfügen **einzelner oder mehrerer** Bilder (`insertImage`) sowie Dokument-/PDF-Links über eine einzige Schaltfläche.

#### Schritt 1: CKEditor 5 und eine Schaltfläche zum Einfügen von Medien ergänzen
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Medien aus Dateimanager einfügen
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Schritt 2: `openFileManagerForCKEditor5` verdrahten
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
            mode: 'offcanvas', // Seitlicher Drawer, damit Sie Dateien durchsuchen können, ohne den Editor zu verdecken
            position: 'end',
            multiple: true,    // Mehrere Fotos gleichzeitig einfügen
            locale: 'de',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### Was passiert dabei?
- **Bilder** werden nativ über den CKEditor-5-Befehl `insertImage` als `<figure class="image">`-Blöcke eingefügt.
- **Dokumente (PDF, ZIP usw.)** werden als anklickbare `<a href="...">`-Links mit ihrem Dateinamen als Linktext eingefügt.

---

### C. Tiptap- / ProseMirror-Integration (`insertFromFileManager`)

![Live-Integration von Tiptap 2 + LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Wenn Sie den modernen Headless-Editor **Tiptap 2** verwenden, lässt er sich mit einem einzigen Funktionsaufruf an `@tiptap/extension-image` und `@tiptap/extension-link` anbinden.

#### Schritt 1: Pakete installieren
- **Mit Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **Mit AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Schritt 2: Twig-Template (Symbolleiste + Editor-Fläche + verstecktes Eingabefeld)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Medien einfügen (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Schritt 3: Den Stimulus-Controller mit `insertFromFileManager` schreiben
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
            content: this.hiddenInputTarget.value || '<p>Beginnen Sie mit dem Schreiben Ihres Inhalts...</p>',
            onUpdate: ({ editor }) => {
                // Hält das versteckte Eingabefeld für den Formularversand mit dem aktuellen HTML synchron
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'de',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### Was passiert dabei?
- Bilder werden über `setImage({ src, alt, title })` eingefügt und behalten ihre SEO-Attribute `alt` und `title`.
- Dokumente (PDF, ZIP usw.) werden automatisch als anklickbare Links (`marks: [{ type: 'link', attrs: { href } }]`) eingefügt.

---

### D. Quill 1.x / 2.x Integration (`createQuillImageHandler` & `openFileManagerForQuill`)

![Live-Integration von Quill 2 + LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Verbindet die native **Bild**-Schaltfläche der Quill-Symbolleiste mit einer einzigen Zeile mit LiveUX FileManagerBundle – versieht Bilder mit SEO-`alt`-Attributen und fügt für Dokumente anklickbare Links ein.

#### Schritt 1: Quill-CSS/-JS und den Editor-Container einbinden
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Schritt 2: Den Stimulus-Controller mit `createQuillImageHandler` schreiben
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
                        // Ein Klick auf Quills integrierte Bild-Schaltfläche öffnet LiveUX FileManagerBundle!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'de',
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

### E. Trix-Editor-Integration — EasyAdmin `TextEditorField` & Turbo (`openFileManagerForTrix`)

![Live-Integration von Trix + LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Fügt sichere Bildanhänge (`Trix.Attachment`) und Dokument-Links in `<trix-editor>` ein – den Standard-Rich-Text-Editor von **EasyAdmins integriertem `TextEditorField`** sowie von Symfony-Turbo-/Hotwire-Anwendungen.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Aus Dateimanager einfügen</button>
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
            locale: 'de',
        });
    }
}
```

---

### F. Summernote-Integration (`openFileManagerForSummernote`)

![Live-Integration von Summernote + LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Fügt bereinigte DOM-`<img>`-Knoten (mit SEO-`alt` & `title`) und `<a>`-Links in Summernote ein, das in Bootstrap-basierten Admin-Panels weit verbreitet ist.

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
            locale: 'de',
        });
    }
}
```

---

### G. Editor.js-Integration (Blockbasierter JSON-Editor) (`openFileManagerForEditorJs`)

![Live-Integration von Editor.js + LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Fügt strukturierte `image`- (`@editorjs/image`) oder `simpleImage`-Blöcke (`@editorjs/simple-image`) sowie Dokument-Links in **Editor.js** ein – ideal für Headless-CMS-Lösungen und Block-Editoren im Notion-Stil.

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
            imageBlockType: 'simpleImage', // Auf 'image' belassen, wenn Sie @editorjs/image verwenden
            locale: 'de',
        });
    }
}
```

---

### H. GrapesJS-Integration (Visueller Page- & E-Mail-Builder) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Live-Integration von GrapesJS + LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

Integriert sich nahtlos in **GrapesJS (0.20+ / 0.21+ / 0.22+)** auf zwei Arten:
1. **`createGrapesJsAssetManagerConfig()`:** Ersetzt das integrierte Asset-Manager-Modal von GrapesJS durch LiveUX FileManagerBundle (öffnet sich automatisch beim Doppelklick auf ein Bild auf der Zeichenfläche).
2. **`openFileManagerForGrapesJs()`:** Fügt einzelne oder mehrere Bild-/Link-Komponenten über einen eigenen Button direkt auf der Zeichenfläche ein.

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
            // 1) Den integrierten Asset Manager durch LiveUX FileManager ersetzen:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'de',
            }),
        });
    }

    // 2) Oder Bilder/Links über einen eigenen Button direkt auf der Zeichenfläche einfügen:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'de',
        });
    }
}
```

---

### I. Meta-Lexical-Integration (`openFileManagerForLexical`)

![Live-Integration von Meta Lexical + LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

Integriert sich in Metas erweiterbare **Lexical**-Rich-Text-Engine (`lexical` 0.15+ / 0.20+ / 0.30+) per Command-Dispatch (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) oder über einen eigenen `onInsert`-Callback mit strikter XSS-URL-Validierung und SEO-`altText`-Metadaten.

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
            nodes: [/* Eigener ImageNode */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Eigenen ImageNode / DecoratorNode in den Lexical-State einfügen
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
            locale: 'de',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. EasyAdmin 4 / 5 Integration — Schritt für Schritt (`FileManagerField`)

![Live-Demo von EasyAdmin 5 + LiveUX FileManagerField](../images/demo-easyadmin.gif)

Das integrierte `FileManagerField` benötigt keinerlei zusätzliche Abhängigkeiten:
- Auf **Index**- und **Detail**-Seiten rendert es automatisch Thumbnails und Badges für die ausgewählten Dateien (URL-Sicherheit wird durch `liveux_fm_describe_field_item()` durchgesetzt).
- Auf **New**- und **Edit**-Seiten wird es direkt als `FileManagerPickerType` gerendert.

### Schritt 1: Ihre App-Assets im `DashboardController` von EasyAdmin laden (Wichtig!)

Da EasyAdmin ein eigenes eigenständiges Basislayout verwendet (es erbt nicht von Ihrer `base.html.twig`), registrieren Sie Ihren `app`-Asset-Entrypoint in `DashboardController::configureAssets()`:

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
        // Bei Verwendung von AssetMapper:
        return Assets::new()->addAssetMapperEntry('app');

        // Bei Verwendung von Webpack Encore ersetzen Sie die obige Zeile durch:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Schritt 2: `FileManagerField` in Ihrem `CrudController` verwenden

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
        yield TextField::new('title', 'Produktname');

        yield FileManagerField::new('coverImage', 'Titelbild')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Titelbild wählen');

        yield FileManagerField::new('galleryImages', 'Produktgalerie')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Alle Fluent-Methoden von `FileManagerField` (16 Methoden)

| Methode | Entsprechende Option | Beispiel |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('de')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Logo ändern')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Noch nichts ausgewählt')` |

---

[← Zurück: 04 — Themes, Icons & Anpassung](04-themes-icons-and-customization.md) | [Übersicht](README.md) | [Weiter: 06 — Sicherheit & Autorisierung →](06-security-and-authorization.md)
