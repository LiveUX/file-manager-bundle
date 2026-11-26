# 05 — FormType (`FileManagerPickerType`) & Editor-integraties

[← Vorige: 04 — Thema's, Iconen & Maatwerk](04-themes-icons-and-customization.md) | [Overzicht](README.md) | [Volgende: 06 — Beveiliging & Autorisatie →](06-security-and-authorization.md)

`FileManagerPickerType` integreert naadloos met de Symfony Form-component. Het veld ondersteunt zowel **losse bestanden (`?string`)** als **galerijen met meerdere bestanden (`list<string>` / JSON)**, beschikt over **9 thema's**, **5 kant-en-klare lay-outvarianten**, **toegankelijk herschikken van galerijen via drag-and-drop, toetsenbord en touch**, en volledige ondersteuning voor **eigen Twig-templates (`template`)**.

> **In één oogopslag: wat behandelt deze gids?**  
> Wanneer je een product-, blog- of gebruikersprofielformulier bouwt, geeft `FileManagerPickerType` je in plaats van een kale `<input type="file">` een compleet Symfony-formulierveld dat **de bestandsbeheerder opent, zodat gebruikers bestaande bestanden kunnen kiezen of direct nieuwe kunnen uploaden**.  
> Daarnaast leer je hoe je met slechts een paar regels code integreert met **CollectionType / LiveCollectionType**, **7 populaire WYSIWYG- & block-editors** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js) en **EasyAdmin 4/5**.

> [!NOTE]
> Het formulierthema (`@LiveUXFileManager/form/picker.html.twig`) en de `DialogHost`-container worden **automatisch geregistreerd en geïnjecteerd** door de bundle — je hoeft niets aan `twig.yaml` of `base.html.twig` aan te passen.

---

## 1. Alle opties van `FileManagerPickerType`

| Optie | Type | Standaard | Beschrijving |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Het bestandsbeheerder-profiel dat wordt geopend. |
| `locale` | `?string` | `null` | `null` neemt de profieltaal over; geef een van de 12 ingebouwde UI-talen (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`) op om per veld te overschrijven. |
| `volume` | `?string` | `null` | Initiële volumenaam (`null` gebruikt het eerste volume van het profiel). |
| `multiple` | `bool` | `false` | `false` = één bestand (`?string`), `true` = galerij met meerdere bestanden (`list<string>`). |
| `max_files` | `?int` | `null` | Maximaal aantal bestanden bij `multiple: true` (`null` = onbeperkt; wordt ook strikt server-side afgedwongen). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Weergavestijl van het dialoogvenster. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Positie van het schuifpaneel wanneer `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` neemt `ui.mobile_dialog_mode` van het profiel over; overschrijft per veld de mobiele (`<= 767px`) dialoogweergave. |
| `return_type` | `'url'\|'path'` | `'url'` | Bepaalt of je een volledige URL (`/uploads/a.jpg`) of een relatief pad (`a.jpg`) in je database opslaat *(zie de keuzehulp hieronder)*. |
| `preview` | `bool` | `true` | Regelt de weergave van afbeeldings-/bestandsthumbnails bij **alle varianten** (enkelvoudig en meervoudig). Bij `false` wordt alleen de bestandsnaam/het pad getoond. |
| `button_label` | `?string` | `null` | Eigen label voor de kiesknop (bijv. `'Afbeelding kiezen'`, `'Logo wijzigen'`). |
| `placeholder` | `?string` | `null` | Plaatshoudertekst wanneer er nog geen bestand is geselecteerd. |
| `theme` | `?string` | `null` | `null` neemt het profielthema over. Accepteert elk van de 9 ingebouwde thema's of een eigen CSS-themanaam (bijv. `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` neemt het kleurenschema van het profiel/de standaardinstelling over (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Ingebouwde lay-outvariant: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Pad naar een **100% eigen Twig-template** dat de ingebouwde varianten vervangt (bijv. `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Standaard Symfony Form-optie: bij `true` wordt het veld volledig alleen-lezen en worden de knoppen voor openen, wissen en herschikken vergrendeld. |

### Kies ik `return_type: 'url'` of `'path'`?
- **`'url'` (Standaard — eenvoudigst):** Slaat `/uploads/products/thermos.webp` of `https://cdn.example.com/thermos.webp` op in de database. In Twig render je dit direct met `<img src="{{ product.coverImage }}">` zonder extra logica.
- **`'path'` (Flexibel / ideaal bij CDN-migraties):** Slaat alleen het relatieve pad `products/thermos.webp` op. Stap je later over naar een andere opslagschijf of een nieuw CDN-domein, dan hoef je geen databasegegevens te migreren — je rendert het eenvoudig via `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` of plakt je CDN-basis-URL ervoor.

### Ingebouwde beveiliging & toegankelijk herschikken van galerijen
- **Server-side POST-hardening:** Zelfs als iemand via de browser-DevTools met de verborgen `<input>` knoeit om `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, ongeldige JSON boven 256 KB, meer dan 500 items, ongeldige UTF-8, aantallen boven `max_files` of HTTP Parameter Pollution (HPP)-arrays mee te sturen, **onderschept en weigert `FileManagerPickerType` dit automatisch op de server**.
- **Toegankelijk herschikken van galerijen (`multiple: true`):** Gebruikers kunnen galerij-items op **3 manieren** herschikken:
  1. **Drag & Drop** op desktop,
  2. **`←` / `→`-pijltjestoetsen** wanneer een kaart de focus heeft (richting keert automatisch om in RTL-talen zoals Arabisch),
  3. **`◀` / `▶`-knoppen** op elke kaart voor mobiel en touchscreens.

---

## 2. 5 ingebouwde lay-outvarianten (`variant`)

`variant` en `theme` staan volledig los van elkaar. Elke variant werkt in zowel de **enkelvoudige** als de **meervoudige** modus en binnen alle thema's:

| Variant | Enkelvoudige modus (`multiple: false`) | Meervoudige modus (`multiple: true`) | Beste toepassing |
| :--- | :--- | :--- | :--- |
| `compact` *(standaard)* | Vierkante thumbnail van 60×60 + invoerveld + rij met Kies/Wis-knoppen | Galerijkaarten naast elkaar + gestippelde `+`-tegel | Algemene formuliervelden, productgalerijen |
| `card` | 16:10 omslag-previewkaart + bestandsnaam + acties onderaan | Kaartenraster | Blog-/artikelomslagen, hero-banners, uitgelichte afbeeldingen |
| `dropzone` | Groot klikbaar gestippeld vak + live preview | Groot toevoegvak bovenaan + galerijkaarten eronder | Opvallende media-/bestandsuploadzones |
| `avatar` | Ronde profiel-/logopreview van `82×82` + knoppen ernaast | Ronde avatar-chips van `72×72` + ronde `+`-tegel | Gebruikersprofielfoto's, merklogo's |
| `input` | Minimalistische invoergroep op één regel zonder thumbnails | Lijst met verwijderbare chips/tags + `+ Toevoegen` | Compacte formulieren, PDF's, documenten, contracten |

![5 ingebouwde lay-outvarianten en galerij met meerdere bestanden van FileManagerPickerType](../images/form-pickers.png)

---

## 3. Compleet Entity + FormType-voorbeeld

### Doctrine Entity (`src/Entity/Product.php`)
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

### Symfony Form-klasse (`src/Form/ProductType.php`)
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
                'label' => 'Productomslagafbeelding',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Omslag kiezen',
                'placeholder' => 'Geen omslagafbeelding geselecteerd…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Productgalerij',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Afbeeldingen toevoegen',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Je eigen maatwerk FormType-UI bouwen (3 benaderingen)

Je zit nooit vast aan de 5 ingebouwde varianten of 9 thema's — je kunt **100% eigen HTML-, Bootstrap-, Tailwind- of Alpine-ontwerpen** bouwen met volledige Stimulus-koppeling.

### Benadering 1: `template`-optie per veld (Snelst & meest flexibel)

Geef een `'template'`-pad mee in je formulierdefinitie. De bundle koppelt automatisch de omhullende Stimulus-controller (`liveux--file-manager-bundle--picker`) en laat de volledige binnenste markup over aan jouw Twig-bestand:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Banner kiezen',
        'placeholder' => 'Nog geen omslagafbeelding geselecteerd',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Toevoegen aan galerij',
    ]);
```

#### Voorbeeld A — Eigen template voor één bestand (`templates/forms/custom_single_picker.html.twig`)
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

#### Voorbeeld B — Eigen template voor een galerij met meerdere bestanden (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Geselecteerde afbeeldingen: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js rendert geselecteerde items als herschikbare kaarten binnen deze container #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Referentie voor eigen FormType-templates

| Attribuut / Event | Beschrijving |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Verplicht** — Het `<input>`-element dat bij het verzenden van het formulier naar de server wordt gestuurd. |
| `data-{{ ctrl }}-target="singlePreview"` | Container waarin in de enkelvoudige modus automatisch de preview van het geselecteerde bestand/de afbeelding wordt gerenderd. |
| `data-{{ ctrl }}-target="fileLabel"` | Tekstelement dat automatisch wordt bijgewerkt met de geselecteerde bestandsnaam/het pad. |
| `data-{{ ctrl }}-target="clearBtn"` | Wisknop; wordt automatisch op `disabled` gezet wanneer het veld leeg is of wanneer `isDisabled` `true` is. |
| `data-{{ ctrl }}-target="galleryGrid"` | Container die in de meervoudige modus de geselecteerde bestandskaarten en het herschikken beheert. |
| `data-{{ ctrl }}-target="countLabel"` | Tellerelement dat in de meervoudige modus het huidige aantal geselecteerde bestanden toont. |
| `data-action="click->{{ ctrl }}#open"` | Opent de Modal / OffCanvas van de bestandsbeheerder. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Wist de huidige enkelvoudige selectie. |
| **JS Event:** `liveux:file-manager:picker-changed` | Wordt afgevuurd telkens wanneer items worden geselecteerd, gewist, verwijderd of herschikt, met `{ values, value, multiple }` in `event.detail`. |

**Twig-variabelen die automatisch aan je eigen template worden doorgegeven:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Benadering 2: Applicatiebrede Symfony `form_themes`-override

Wil je de weergave van **alle** `FileManagerPickerType`-velden in je hele project op één centrale plek aanpassen? Overschrijf dan het blok `liveux_file_manager_picker_widget` in je eigen formulierthema:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Jouw globale eigen HTML- & CSS-markup #}
{% endblock %}
```

---

### Benadering 3: Herbruikbare eigen child FormType-klasse

In plaats van steeds dezelfde `profile`-, `theme`-, `template`- en `mode`-opties in meerdere formulieren te herhalen, kun je een eigen subklasse van `FileManagerPickerType` aanmaken:

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
            'button_label' => 'Productomslag kiezen',
            'placeholder' => 'Geen omslagafbeelding geselecteerd…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Daarna gebruik je dit veld met één enkele regel in elk formulier:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` & Symfony UX `LiveCollectionType`

![Live demo van Symfony CollectionType en LiveCollectionType](../images/demo-collections.gif)

`FileManagerPickerType` werkt probleemloos met zowel het klassieke Symfony **`CollectionType`** (dynamisch rijen toevoegen en verwijderen via `data-prototype`) als **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** De Stimulus `picker`-controller verbindt automatisch zodra een nieuwe rij aan de DOM wordt toegevoegd — geen handmatige JavaScript-initialisatie nodig.
- **`LiveCollectionType`:** Zelfs wanneer Idiomorph de DOM bijwerkt tijdens het toevoegen of verwijderen van rijen, rendert `picker_controller.js` (`render:finished` + `MutationObserver`) automatisch elke losse preview en galerijkaart opnieuw op basis van de nieuwste `<input>`-waarde.
- **Samengesteld subformulier vs. direct scalair `entry_type`:**
  - Gebruik je een **samengesteld subformulier** zoals `entry_type: SlideItemType::class`, dan verstuurt `LiveCollectionTrait::addCollectionItem()` `[]` voor de nieuwe rij en verwerkt het subformulier dit zonder problemen — je hebt geen extra code nodig.
  - Gebruik je direct een enkelvoudig (`multiple: false`) `entry_type: FileManagerPickerType::class`, dan weigert de ingebouwde `PRE_SUBMIT`-listener van de picker array-payloads als onderdeel van de HPP-bescherming. De 4-regelige `PRE_SUBMIT`-listener op de collectie hieronder normaliseert nieuw toegevoegde lege rijen van `[]` naar `''`.

### A. Voorbeeld met standaard Symfony `CollectionType`

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
                'label' => 'Omslagafbeelding',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Bijlagen',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Binnen het bovenliggende FormType:
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
">+ Rij toevoegen</button>
```

### B. Voorbeeld met Symfony UX `LiveCollectionType` (`LiveCollectionTrait`)

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
            // 1) Samengesteld subformulier — geen extra PRE_SUBMIT-listener nodig:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) Direct enkelvoudig FileManagerPickerType (scalaire lijst):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Alleen nodig bij direct scalair (multiple: false) entry_type:
        // LiveCollectionTrait::addCollectionItem() voegt [] toe voor nieuwe rijen, dus normaliseer naar ''.
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
        // Sla je gegevens op...
    }
}
```

> [!NOTE]
> Zorg ervoor dat je `LiveCollectionType`-template `{{ form_end(form) }}` aanroept (of `{{ form_row(form._token) }}` opneemt als je `render_rest: false` gebruikt), zodat de CSRF-token wordt meegestuurd.

#### LiveComponent Twig-template (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Opslaan (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Vervolgens render je de component overal in Twig met één enkele tag:
```twig
<twig:SliderFormComponent />
```

---

## 6. Rich Text- & Block-editorintegraties — 7 ingebouwde adapters (Stap voor stap)

> **Kort overzicht voor beginners:**  
> Wanneer redacteuren blogartikelen of productomschrijvingen schrijven, willen ze moeiteloos afbeeldingen of PDF's rechtstreeks in de tekst invoegen. LiveUX FileManagerBundle bevat **7 kant-en-klare JavaScript-adapters** voor de populairste open-source editors: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (EasyAdmin `TextEditorField`)**, **Summernote** en **Editor.js**. Elke adapter:
> - Opent de bestandsbeheerder op de native `<dialog>` Top Layer van de browser (**geen `z-index`-conflicten** of focus-trap-bugs achter editor-popups),
> - Neemt automatisch SEO-metadata (`alt` / `title` / `caption`) van geselecteerde afbeeldingen mee,
> - Controleert elke teruggegeven URL op `javascript:`- en path-traversal (`../`) XSS-aanvallen.

Alle zeven adapters accepteren dezelfde gemeenschappelijke opties:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Eén regel in `importmap.php` voor AssetMapper-gebruikers:**  
> In **Webpack Encore**-projecten werkt `import ... from '@liveux/file-manager-bundle/js/integrations/...'` direct.  
> Gebruik je **Symfony AssetMapper**, registreer dan **alleen de adapter(s) die je gebruikt** in `importmap.php` zodat de `<script type="importmap">` in de browser het pad herkent (deze bestanden zitten al in de bundle — er wordt niets van internet gedownload):
> ```php
> // importmap.php (voeg alleen de editor-adapter(s) toe die je daadwerkelijk gebruikt)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. TinyMCE 5 / 6 / 7 / 8-integratie (`createTinyMceFilePicker`)

![Live integratie van TinyMCE met LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 en 8 gebruiken allemaal exact dezelfde `file_picker_callback`-signatuur en zijn **geverifieerd met live browsertests**. De adapter koppelt de ingebouwde bladerknoppen in de dialogen voor **Afbeelding (`image`)**, **Media (`media`)** en **Link (`link`)** rechtstreeks aan LiveUX FileManagerBundle.

> **Versie-opmerking:** Behoud `license_key: 'gpl'` wanneer je TinyMCE **7 of 8** gebruikt. Werk je met TinyMCE **5 of 6**, laat die regel dan eenvoudig weg — al het overige is identiek.

#### Stap 1: Laad TinyMCE op je pagina (Open Source — geen API-sleutel nodig)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (via CDN of je eigen public/-map) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Stap 2: Koppel `createTinyMceFilePicker` via een Stimulus-controller
Maak `assets/controllers/tinymce_editor_controller.js` aan (werkt identiek in zowel **AssetMapper** als **Webpack Encore**):

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
            license_key: 'gpl', // Gratis open-source GPL-licentiemodus
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Jouw profiel uit liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'nl',
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

#### Wat gebeurt er?
Wanneer een gebruiker op de knop **Afbeelding** in de TinyMCE-werkbalk klikt en vervolgens op het bladericoon naast **Bron (Source)** klikt:
1. LiveUX FileManagerBundle opent boven de TinyMCE-modal, voorgefilterd op afbeeldingen (`filetype: 'image'`).
2. Zodra de gebruiker een afbeelding kiest, worden zowel de **Bron-URL** als de **Alternatieve beschrijving (`alt`)** automatisch ingevuld!

---

### B. CKEditor 5-integratie (`openFileManagerForCKEditor5`)

![Live integratie van CKEditor 5 met LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Werkt met de CKEditor 5 Classic-, Inline-, Balloon- en Decoupled-builds om met één knop **één of meerdere** afbeeldingen (`insertImage`) en document-/PDF-links in te voegen.

#### Stap 1: Voeg CKEditor 5 en een knop voor Media invoegen toe
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Media invoegen vanuit bestandsbeheerder
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Stap 2: Koppel `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // Schuifpaneel zodat je kunt bladeren zonder de editor te bedekken
            position: 'end',
            multiple: true,    // Meerdere foto's tegelijk invoegen
            locale: 'nl',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### Wat gebeurt er?
- **Afbeeldingen** worden native ingevoegd via het `insertImage`-commando van CKEditor 5 als `<figure class="image">`-blokken.
- **Documenten (PDF, ZIP, enz.)** worden ingevoegd als klikbare `<a href="...">`-links met de bestandsnaam als linktekst.

---

### C. Tiptap / ProseMirror-integratie (`insertFromFileManager`)

![Live integratie van Tiptap 2 met LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Gebruik je de moderne headless editor **Tiptap 2**, dan koppel je die met één functieaanroep aan `@tiptap/extension-image` en `@tiptap/extension-link`.

#### Stap 1: Installeer de pakketten
- **Met Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **Met AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Stap 2: Twig-template (Werkbalk + editorcanvas + verborgen input)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Media invoegen (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Stap 3: Schrijf de Stimulus-controller met `insertFromFileManager`
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
            content: this.hiddenInputTarget.value || '<p>Begin met het schrijven van je tekst...</p>',
            onUpdate: ({ editor }) => {
                // Houd het verborgen inputveld synchroon met de nieuwste HTML voor formulierverzending
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'nl',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### Wat gebeurt er?
- Afbeeldingen worden ingevoegd via `setImage({ src, alt, title })`, met behoud van SEO-`alt`- en `title`-attributen.
- Documenten (PDF, ZIP, enz.) worden automatisch ingevoegd als klikbare links (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Quill 1.x / 2.x-integratie (`createQuillImageHandler` & `openFileManagerForQuill`)

![Live integratie van Quill 2 met LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Koppelt de ingebouwde **Afbeelding**-knop in de Quill-werkbalk met één regel aan LiveUX FileManagerBundle — inclusief SEO-`alt`-attributen voor afbeeldingen en klikbare links voor documenten.

#### Stap 1: Voeg Quill CSS/JS en de editorcontainer toe
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Stap 2: Schrijf de Stimulus-controller met `createQuillImageHandler`
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
                        // Klikken op de ingebouwde Afbeelding-knop van Quill opent LiveUX FileManagerBundle!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'nl',
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

### E. Trix Editor-integratie — EasyAdmin `TextEditorField` & Turbo (`openFileManagerForTrix`)

![Live integratie van Trix met LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Voegt veilige afbeeldingsbijlagen (`Trix.Attachment`) en documentlinks in binnen `<trix-editor>` — de standaard rich-text-editor van **EasyAdmins ingebouwde `TextEditorField`** en Symfony Turbo/Hotwire-applicaties.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Invoegen vanuit bestandsbeheerder</button>
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
            locale: 'nl',
        });
    }
}
```

---

### F. Summernote-integratie (`openFileManagerForSummernote`)

![Live integratie van Summernote met LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Voegt opgeschoonde DOM `<img>`-nodes (met SEO-`alt` & `title`) en `<a>`-links in binnen Summernote, veelgebruikt in op Bootstrap gebaseerde adminpanels.

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
            locale: 'nl',
        });
    }
}
```

---

### G. Editor.js-integratie (Blokgebaseerde JSON-editor) (`openFileManagerForEditorJs`)

![Live integratie van Editor.js met LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Voegt gestructureerde `image`- (`@editorjs/image`) of `simpleImage`-blokken (`@editorjs/simple-image`) en documentlinks in binnen **Editor.js** — ideaal voor headless CMS-systemen en blokeditors in Notion-stijl.

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
            imageBlockType: 'simpleImage', // Laat op 'image' staan als je @editorjs/image gebruikt
            locale: 'nl',
        });
    }
}
```

---

### H. GrapesJS-integratie (Visuele Pagina- & E-mailbouwer) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Live-integratie van GrapesJS met LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

Integreert naadloos met **GrapesJS (0.20+ / 0.21+ / 0.22+)** op twee manieren:
1. **`createGrapesJsAssetManagerConfig()`:** Vervangt de ingebouwde Asset Manager-modal van GrapesJS door LiveUX FileManagerBundle (opent automatisch wanneer je op een afbeelding op het canvas dubbelklikt).
2. **`openFileManagerForGrapesJs()`:** Voegt enkele of meerdere afbeeldings-/linkcomponenten rechtstreeks op het canvas in via een eigen knop.

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
            // 1) Vervang de ingebouwde Asset Manager door LiveUX FileManager:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'nl',
            }),
        });
    }

    // 2) Of voeg afbeeldingen/links rechtstreeks op het canvas in via een eigen knop:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'nl',
        });
    }
}
```

---

### I. Meta Lexical-integratie (`openFileManagerForLexical`)

![Live-integratie van Meta Lexical met LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

Integreert met Meta's uitbreidbare **Lexical** rich-text-engine (`lexical` 0.15+ / 0.20+ / 0.30+) via command-dispatch (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) of een eigen `onInsert`-callback met strikte XSS-URL-validatie en SEO-`altText`-metadata.

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
            nodes: [/* Aangepaste ImageNode */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Voeg je eigen ImageNode / DecoratorNode toe aan de Lexical-state
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
            locale: 'nl',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. EasyAdmin 4 / 5-integratie — Stap voor stap (`FileManagerField`)

![Live demo van EasyAdmin 5 met LiveUX FileManagerField](../images/demo-easyadmin.gif)

Het ingebouwde `FileManagerField` vereist nul extra afhankelijkheden:
- Op **Index**- en **Detail**-pagina's toont het automatisch thumbnails en badges voor geselecteerde bestanden (waarbij URL-veiligheid wordt afgedwongen door `liveux_fm_describe_field_item()`).
- Op **New**- en **Edit**-pagina's rendert het rechtstreeks als `FileManagerPickerType`.

### Stap 1: Laad je app-assets in de `DashboardController` van EasyAdmin (Belangrijk!)

Omdat EasyAdmin een eigen zelfstandige basislay-out gebruikt (het erft niet van jouw `base.html.twig`), moet je je `app`-asset-entrypoint registreren in `DashboardController::configureAssets()`:

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
        // Als je AssetMapper gebruikt:
        return Assets::new()->addAssetMapperEntry('app');

        // Gebruik je Webpack Encore, vervang de regel hierboven dan door:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Stap 2: Gebruik `FileManagerField` in je `CrudController`

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
        yield TextField::new('title', 'Productnaam');

        yield FileManagerField::new('coverImage', 'Omslagafbeelding')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Omslag kiezen');

        yield FileManagerField::new('galleryImages', 'Productgalerij')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Alle fluent-methoden van `FileManagerField` (16 methoden)

| Methode | Bijbehorende optie | Voorbeeld |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('nl')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Logo wijzigen')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Nog niets geselecteerd')` |

---

[← Vorige: 04 — Thema's, Iconen & Maatwerk](04-themes-icons-and-customization.md) | [Overzicht](README.md) | [Volgende: 06 — Beveiliging & Autorisatie →](06-security-and-authorization.md)
