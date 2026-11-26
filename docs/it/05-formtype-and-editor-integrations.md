# 05 — FormType (`FileManagerPickerType`) e Integrazioni Editor

[← Precedente: 04 — Temi, Icone e Personalizzazione](04-themes-icons-and-customization.md) | [Indice](README.md) | [Successivo: 06 — Sicurezza e Autorizzazioni →](06-security-and-authorization.md)

`FileManagerPickerType` si integra nativamente con il componente Symfony Form. Supporta sia **singoli file (`?string`)** sia **gallerie multi-file (`list<string>` / JSON)**, include **9 temi**, **5 varianti di layout pronte all'uso**, **riordinamento accessibile della galleria via drag-and-drop / tastiera / touch** e supporto completo per **template Twig personalizzati (`template`)**.

> **In sintesi: cosa copre questa guida?**  
> Quando realizzi un form per un prodotto, un articolo di blog o un profilo utente, invece di usare un semplice `<input type="file">`, `FileManagerPickerType` ti offre un campo Symfony Form pronto all'uso che **apre il file manager permettendo agli utenti di scegliere risorse esistenti o caricarne di nuove**.  
> Imparerai inoltre come integrare **CollectionType / LiveCollectionType**, **7 editor WYSIWYG e a blocchi tra i più diffusi** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js) ed **EasyAdmin 4/5** con pochissime righe di codice.

> [!NOTE]
> Il form theme (`@LiveUXFileManager/form/picker.html.twig`) e il contenitore `DialogHost` vengono **registrati e iniettati automaticamente** dal bundle — non devi modificare né `twig.yaml` né `base.html.twig`.

---

## 1. Tutte le opzioni di `FileManagerPickerType`

| Opzione | Tipo | Predefinito | Descrizione |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Profilo del file manager da aprire. |
| `locale` | `?string` | `null` | `null` eredita la lingua del profilo; passa una qualsiasi delle 12 lingue UI integrate (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`) per sovrascriverla sul singolo campo. |
| `volume` | `?string` | `null` | Nome del volume iniziale (`null` utilizza il primo volume del profilo). |
| `multiple` | `bool` | `false` | `false` = file singolo (`?string`), `true` = galleria multi-file (`list<string>`). |
| `max_files` | `?int` | `null` | Numero massimo di file consentiti quando `multiple: true` (`null` = illimitato; applicato rigorosamente anche lato server). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Stile di visualizzazione del dialog. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Lato di apertura del pannello laterale quando `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` eredita `ui.mobile_dialog_mode` del profilo; sovrascrive il layout del dialog su mobile (`<= 767px`) per il singolo campo. |
| `return_type` | `'url'\|'path'` | `'url'` | Definisce se salvare nel database un URL completo (`/uploads/a.jpg`) o un percorso relativo (`a.jpg`) *(vedi la guida alla scelta qui sotto)*. |
| `preview` | `bool` | `true` | Controlla le miniature di anteprima di immagini/file in **tutte le varianti** (singole e multiple). Se `false`, viene mostrato solo il nome/percorso del file. |
| `button_label` | `?string` | `null` | Etichetta personalizzata per il pulsante di selezione (es. `'Scegli immagine'`, `'Cambia logo'`). |
| `placeholder` | `?string` | `null` | Testo segnaposto mostrato quando nessun file è selezionato. |
| `theme` | `?string` | `null` | `null` eredita il tema del profilo. Accetta uno qualsiasi dei 9 temi integrati oppure il nome del tuo tema CSS personalizzato (es. `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` eredita lo schema colori del profilo/predefinito (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Variante di layout integrata: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Percorso di un **template Twig personalizzato al 100%** che sostituisce le varianti integrate (es. `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Opzione standard di Symfony Form: se `true`, il campo diventa di sola lettura e i controlli di apertura/rimozione/riordinamento vengono bloccati. |

### Meglio scegliere `return_type: 'url'` o `'path'`?
- **`'url'` (Predefinito — Il più semplice):** salva `/uploads/products/thermos.webp` oppure `https://cdn.example.com/thermos.webp` nel database. In Twig puoi renderizzarlo direttamente con `<img src="{{ product.coverImage }}">` senza logica aggiuntiva.
- **`'path'` (Flessibile / Ideale per migrazioni CDN):** salva solo il percorso relativo `products/thermos.webp`. Se in futuro cambi disco di storage o dominio CDN, non dovrai migrare i record del database — ti basterà renderizzare tramite `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` o anteporre il tuo URL base CDN.

### Sicurezza integrata e riordinamento accessibile della galleria
- **Protezione POST lato server:** anche se qualcuno manomette l'`<input>` nascosto dai DevTools del browser per iniettare `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, JSON malformato oltre 256 KB, più di 500 elementi, UTF-8 non valido, valori che superano `max_files` o array HTTP Parameter Pollution (HPP), `FileManagerPickerType` **intercetta e rifiuta automaticamente il payload sul server**.
- **Riordinamento accessibile della galleria (`multiple: true`):** gli utenti possono riordinare gli elementi della galleria in **3 modi**:
  1. **Drag & Drop** su desktop,
  2. **Tasti freccia `←` / `→`** quando una card ha il focus (la direzione si inverte automaticamente nelle lingue RTL come l'arabo),
  3. **Pulsanti `◀` / `▶`** su ogni card per dispositivi mobile e touch.

---

## 2. Le 5 varianti di layout integrate (`variant`)

`variant` e `theme` sono completamente indipendenti. Ogni variante funziona sia in modalità **singola** che **multipla** con tutti i temi:

| Variante | Modalità singola (`multiple: false`) | Modalità multipla (`multiple: true`) | Caso d'uso ideale |
| :--- | :--- | :--- | :--- |
| `compact` *(predefinita)* | Miniatura quadrata 60×60 + input + riga Scegli/Cancella | Card della galleria affiancate + riquadro tratteggiato `+` | Campi form generici, gallerie prodotti |
| `card` | Card di anteprima copertina 16:10 + nome file + azioni in basso | Griglia di card | Copertine di blog/articoli, hero banner, immagini in evidenza |
| `dropzone` | Ampio riquadro tratteggiato cliccabile + anteprima live | Ampio box di aggiunta in alto + card della galleria sotto | Aree media/upload di grande visibilità |
| `avatar` | Anteprima circolare `82×82` profilo/logo + pulsanti laterali | Chip avatar circolari `72×72` + riquadro circolare `+` | Foto profilo utente, loghi aziendali |
| `input` | Gruppo input minimalista su una sola riga senza miniature | Elenco di chip/tag rimovibili + `+ Aggiungi` | Form compatti, PDF, documenti, contratti |

![Le 5 varianti di layout integrate e la galleria multi-file di FileManagerPickerType](../images/form-pickers.png)

---

## 3. Esempio completo Entity + FormType

### Entity Doctrine (`src/Entity/Product.php`)
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

### Classe Form Symfony (`src/Form/ProductType.php`)
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
                'label' => 'Immagine di copertina prodotto',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Scegli copertina',
                'placeholder' => 'Nessuna immagine di copertina selezionata…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galleria prodotto',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Aggiungi immagini',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Creare una UI personalizzata per FormType (3 approcci)

Non sei mai vincolato alle 5 varianti integrate o ai 9 temi: puoi realizzare **design personalizzati al 100% in HTML / Bootstrap / Tailwind / Alpine** con collegamento Stimulus completo.

### Approccio 1: Opzione `template` sul singolo campo (il più rapido e flessibile)

Passa il percorso `'template'` nella definizione del form. Il bundle collega automaticamente il controller Stimulus contenitore (`liveux--file-manager-bundle--picker`) e delega tutto il markup interno al tuo file Twig:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Scegli banner',
        'placeholder' => 'Nessuna immagine di copertina ancora selezionata',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Aggiungi alla galleria',
    ]);
```

#### Esempio A — Template personalizzato per selezione singola (`templates/forms/custom_single_picker.html.twig`)
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

#### Esempio B — Template personalizzato per galleria multi-file (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Immagini selezionate: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js renderizza gli elementi selezionati come card riordinabili dentro questo contenitore #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Riferimento per i template personalizzati di FormType

| Attributo / Evento | Descrizione |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Obbligatorio** — L'elemento `<input>` inviato al server con il form. |
| `data-{{ ctrl }}-target="singlePreview"` | Contenitore in cui viene renderizzata automaticamente l'anteprima dell'immagine/file in modalità singola. |
| `data-{{ ctrl }}-target="fileLabel"` | Elemento di testo aggiornato con il nome/percorso del file selezionato. |
| `data-{{ ctrl }}-target="clearBtn"` | Pulsante di rimozione; impostato automaticamente su `disabled` quando è vuoto o quando `isDisabled` è `true`. |
| `data-{{ ctrl }}-target="galleryGrid"` | Contenitore che gestisce le card dei file selezionati e il riordinamento in modalità multipla. |
| `data-{{ ctrl }}-target="countLabel"` | Contatore che mostra il numero attuale di file selezionati in modalità multipla. |
| `data-action="click->{{ ctrl }}#open"` | Apre la finestra Modal / OffCanvas del file manager. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Cancella la selezione singola corrente. |
| **Evento JS:** `liveux:file-manager:picker-changed` | Emesso ogni volta che elementi vengono selezionati, azzerati, rimossi o riordinati, con `event.detail` contenente `{ values, value, multiple }`. |

**Variabili Twig passate automaticamente al tuo template personalizzato:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Approccio 2: Override globale tramite `form_themes` di Symfony

Per personalizzare l'aspetto di **tutti** i campi `FileManagerPickerType` dell'intero progetto in un unico punto, sovrascrivi il blocco `liveux_file_manager_picker_widget` nel tuo form theme:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Il tuo markup HTML e CSS personalizzato globale #}
{% endblock %}
```

---

### Approccio 3: Classe FormType figlia riutilizzabile

Invece di ripetere le stesse opzioni `profile`, `theme`, `template` e `mode` in più form, crea una sottoclasse dedicata del FormType:

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
            'button_label' => 'Scegli copertina prodotto',
            'placeholder' => 'Nessuna immagine di copertina selezionata…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Ora utilizzalo in qualsiasi form con una sola riga:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` e Symfony UX `LiveCollectionType`

![Demo live di Symfony CollectionType e LiveCollectionType](../images/demo-collections.gif)

`FileManagerPickerType` funziona senza problemi sia con il classico **`CollectionType`** di Symfony (aggiunta/rimozione dinamica di righe tramite `data-prototype`) sia con **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** il controller Stimulus `picker` si collega automaticamente non appena una nuova riga viene inserita nel DOM — nessuna inizializzazione JavaScript manuale richiesta.
- **`LiveCollectionType`:** anche quando Idiomorph esegue il morphing del DOM durante aggiunte o rimozioni di righe, `picker_controller.js` (`render:finished` + `MutationObserver`) ri-renderizza automaticamente ogni anteprima singola e ogni card della galleria multi-file a partire dall'ultimo valore dell'`<input>`.
- **Tipi di voce composti (Compound) vs. scalari diretti:**
  - Quando utilizzi un **sotto-form composto** come `entry_type: SlideItemType::class`, `LiveCollectionTrait::addCollectionItem()` invia `[]` per la nuova riga e il sotto-form lo gestisce senza problemi — non serve alcun codice extra.
  - Quando utilizzi direttamente un `entry_type: FileManagerPickerType::class` a file singolo (`multiple: false`), il listener `PRE_SUBMIT` integrato nel picker rifiuta i payload di tipo array come misura di protezione HPP. Aggiungendo il listener `PRE_SUBMIT` di 4 righe mostrato sotto sulla collection, le nuove righe vuote vengono normalizzate da `[]` a `''`.

### A. Esempio con `CollectionType` standard di Symfony

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
            ->add('title', TextType::class, ['label' => 'Titolo'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Immagine di copertina',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Allegati',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// All'interno del FormType padre:
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
">+ Aggiungi riga</button>
```

### B. Esempio con Symfony UX `LiveCollectionType` (`LiveCollectionTrait`)

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
            // 1) Sotto-form composto — non serve alcun listener PRE_SUBMIT aggiuntivo:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) FileManagerPickerType diretto a file singolo (lista scalare):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Necessario solo per entry_type scalare diretto (multiple: false):
        // LiveCollectionTrait::addCollectionItem() aggiunge [] per le nuove righe, quindi lo normalizziamo a ''.
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
        // Salva i tuoi dati...
    }
}
```

> [!NOTE]
> Assicurati che il tuo template `LiveCollectionType` chiami `{{ form_end(form) }}` (oppure includa `{{ form_row(form._token) }}` se passi `render_rest: false`) in modo che il token CSRF venga inviato.

#### Template Twig del LiveComponent (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Salva (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Infine renderizza il componente ovunque in Twig con un solo tag:
```twig
<twig:SliderFormComponent />
```

---

## 6. Integrazioni con Editor Rich Text e a Blocchi — 7 Adapter Integrati (Passo dopo Passo)

> **Panoramica rapida per chi inizia:**  
> Quando i redattori scrivono articoli di blog o descrizioni prodotto, hanno bisogno di un modo immediato per inserire immagini o PDF direttamente nel testo. LiveUX FileManagerBundle include **7 adapter JavaScript pronti all'uso** per i più diffusi editor open-source: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (`TextEditorField` di EasyAdmin)**, **Summernote** ed **Editor.js**. Ogni adapter:
> - Apre il file manager sul Top Layer nativo `<dialog>` del browser (**zero conflitti di `z-index`** o bug di focus-trap dietro i popup dell'editor),
> - Trasferisce automaticamente i metadati SEO (`alt` / `title` / `caption`) per le immagini selezionate,
> - Valida ogni URL restituito contro vettori XSS `javascript:` e path-traversal (`../`).

Tutti e sette gli adapter accettano le stesse opzioni comuni:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Riga singola in `importmap.php` per chi usa AssetMapper:**  
> Nei progetti **Webpack Encore**, `import ... from '@liveux/file-manager-bundle/js/integrations/...'` funziona direttamente.  
> Se usi **Symfony AssetMapper**, registra in `importmap.php` **solo gli adapter che utilizzi effettivamente**, così lo `<script type="importmap">` del browser riconoscerà il percorso (questi file sono già presenti dentro il bundle — non viene scaricato nulla da internet):
> ```php
> // importmap.php (aggiungi solo gli adapter editor che utilizzi davvero)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. Integrazione TinyMCE 5 / 6 / 7 / 8 (`createTinyMceFilePicker`)

![Integrazione live TinyMCE + LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 e 8 condividono esattamente la stessa firma `file_picker_callback` e sono **verificati con test live su browser**. L'adapter collega i pulsanti sfoglia integrati nelle finestre **Immagine (`image`)**, **Media (`media`)** e **Link (`link`)** di TinyMCE direttamente a LiveUX FileManagerBundle.

> **Nota sulla versione:** mantieni `license_key: 'gpl'` se usi TinyMCE **7 o 8**. Se usi TinyMCE **5 o 6**, rimuovi semplicemente quella riga — tutto il resto è identico.

#### Passo 1: Carica TinyMCE nella pagina (Open Source — nessuna chiave API necessaria)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (da CDN o dalla tua directory public/) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Passo 2: Collega `createTinyMceFilePicker` tramite un controller Stimulus
Crea `assets/controllers/tinymce_editor_controller.js` (funziona allo stesso modo sia con **AssetMapper** che con **Webpack Encore**):

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
            license_key: 'gpl', // Modalità open-source gratuita GPL
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Il tuo profilo in liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'it',
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

#### Cosa succede?
Quando un utente clicca sul pulsante **Immagine** della toolbar di TinyMCE e poi sull'icona sfoglia accanto a **Origine (Source)**:
1. LiveUX FileManagerBundle si apre sopra la modale di TinyMCE, già pre-filtrato sulle immagini (`filetype: 'image'`).
2. Una volta scelta l'immagine, vengono compilati automaticamente sia l'**URL sorgente** sia la **descrizione alternativa (`alt`)**!

---

### B. Integrazione CKEditor 5 (`openFileManagerForCKEditor5`)

![Integrazione live CKEditor 5 + LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Funziona con le build Classic, Inline, Balloon e Decoupled di CKEditor 5 per inserire **singole o multiple** immagini (`insertImage`) e link a documenti/PDF con un solo pulsante.

#### Passo 1: Aggiungi CKEditor 5 e un pulsante Inserisci Media
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Inserisci media dal File Manager
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Passo 2: Collega `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // Pannello laterale scorrevole per sfogliare senza coprire l'editor
            position: 'end',
            multiple: true,    // Inserisci più foto contemporaneamente
            locale: 'it',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### Cosa succede?
- Le **immagini** vengono inserite nativamente tramite il comando `insertImage` di CKEditor 5 come blocchi `<figure class="image">`.
- I **documenti (PDF, ZIP, ecc.)** vengono inseriti come link cliccabili `<a href="...">` con il nome del file come etichetta.

---

### C. Integrazione Tiptap / ProseMirror (`insertFromFileManager`)

![Integrazione live Tiptap 2 + LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Se utilizzi il moderno editor headless **Tiptap 2**, si integra con `@tiptap/extension-image` e `@tiptap/extension-link` in una sola chiamata di funzione.

#### Passo 1: Installa i pacchetti
- **Con Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **Con AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Passo 2: Template Twig (Toolbar + Canvas editor + Input nascosto)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>G</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>C</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Inserisci media (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Passo 3: Scrivi il controller Stimulus `insertFromFileManager`
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
            content: this.hiddenInputTarget.value || '<p>Inizia a scrivere il contenuto...</p>',
            onUpdate: ({ editor }) => {
                // Mantiene sincronizzato l'input nascosto con l'HTML aggiornato per l'invio del form
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'it',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### Cosa succede?
- Le immagini vengono inserite tramite `setImage({ src, alt, title })`, preservando gli attributi SEO `alt` e `title`.
- I documenti (PDF, ZIP, ecc.) vengono inseriti automaticamente come link cliccabili (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Integrazione Quill 1.x / 2.x (`createQuillImageHandler` e `openFileManagerForQuill`)

![Integrazione live Quill 2 + LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Collega il pulsante nativo **Immagine** della toolbar di Quill a LiveUX FileManagerBundle in una sola riga — applicando gli attributi SEO `alt` alle immagini e inserendo link cliccabili per i documenti.

#### Passo 1: Includi CSS/JS di Quill e il contenitore dell'editor
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Passo 2: Scrivi il controller Stimulus `createQuillImageHandler`
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
                        // Cliccando sul pulsante Immagine nativo di Quill si apre LiveUX FileManagerBundle!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'it',
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

### E. Integrazione Trix Editor — EasyAdmin `TextEditorField` e Turbo (`openFileManagerForTrix`)

![Integrazione live Trix + LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Inserisce allegati immagine sicuri (`Trix.Attachment`) e link a documenti dentro `<trix-editor>` — l'editor rich text predefinito utilizzato dal **`TextEditorField` integrato in EasyAdmin** e dalle applicazioni Symfony Turbo/Hotwire.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Inserisci dal File Manager</button>
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
            locale: 'it',
        });
    }
}
```

---

### F. Integrazione Summernote (`openFileManagerForSummernote`)

![Integrazione live Summernote + LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Inserisce nodi DOM `<img>` sanitizzati (con `alt` e `title` SEO) e link `<a>` in Summernote, molto diffuso nei pannelli admin basati su Bootstrap.

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
            locale: 'it',
        });
    }
}
```

---

### G. Integrazione Editor.js (Editor JSON a blocchi) (`openFileManagerForEditorJs`)

![Integrazione live Editor.js + LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Inserisce blocchi strutturati `image` (`@editorjs/image`) o `simpleImage` (`@editorjs/simple-image`) e link a documenti in **Editor.js**, ideale per CMS headless ed editor a blocchi in stile Notion.

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
            imageBlockType: 'simpleImage', // Lascia 'image' se usi @editorjs/image
            locale: 'it',
        });
    }
}
```

---

### H. Integrazione GrapesJS (Page & Email Builder visuale) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Integrazione live GrapesJS + LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

Si integra perfettamente con **GrapesJS (0.20+ / 0.21+ / 0.22+)** in due modi:
1. **`createGrapesJsAssetManagerConfig()`:** Sostituisce la modale Asset Manager nativa di GrapesJS con LiveUX FileManagerBundle (si apre automaticamente facendo doppio clic su qualsiasi immagine nel canvas).
2. **`openFileManagerForGrapesJs()`:** Inserisce uno o più componenti immagine/link direttamente nel canvas tramite un pulsante personalizzato.

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
            // 1) Sostituisci l'Asset Manager nativo con LiveUX FileManager:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'it',
            }),
        });
    }

    // 2) Oppure inserisci immagini/link direttamente nel canvas da un pulsante personalizzato:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'it',
        });
    }
}
```

---

### I. Integrazione Meta Lexical (`openFileManagerForLexical`)

![Integrazione live Meta Lexical + LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

Si integra con il motore rich-text estensibile **Lexical** di Meta (`lexical` 0.15+ / 0.20+ / 0.30+) tramite dispatch di comandi (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) oppure tramite una callback `onInsert` personalizzata, con validazione rigorosa degli URL contro XSS e metadati SEO `altText`.

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
            nodes: [/* ImageNode personalizzato */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Inserisci il tuo ImageNode / DecoratorNode personalizzato nello stato di Lexical
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
            locale: 'it',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. Integrazione EasyAdmin 4 / 5 — Passo dopo Passo (`FileManagerField`)

![Demo live EasyAdmin 5 + LiveUX FileManagerField](../images/demo-easyadmin.gif)

Il campo integrato `FileManagerField` non richiede alcuna dipendenza aggiuntiva:
- Nelle pagine **Index** e **Detail**, renderizza automaticamente miniature e badge per i file selezionati (con sicurezza degli URL garantita da `liveux_fm_describe_field_item()`).
- Nelle pagine **New** ed **Edit**, viene renderizzato direttamente come `FileManagerPickerType`.

### Passo 1: Carica gli asset della tua app nel `DashboardController` di EasyAdmin (Importante!)

Poiché EasyAdmin utilizza un proprio layout di base indipendente (non estende il tuo `base.html.twig`), registra l'entrypoint `app` all'interno di `DashboardController::configureAssets()`:

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
        // Se utilizzi AssetMapper:
        return Assets::new()->addAssetMapperEntry('app');

        // Se utilizzi Webpack Encore, sostituisci la riga sopra con:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Passo 2: Usa `FileManagerField` nel tuo `CrudController`

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
        yield TextField::new('title', 'Nome prodotto');

        yield FileManagerField::new('coverImage', 'Immagine di copertina')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Scegli copertina');

        yield FileManagerField::new('galleryImages', 'Galleria prodotto')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Tutti i metodi fluent di `FileManagerField` (16 metodi)

| Metodo | Opzione corrispondente | Esempio |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('it')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Cambia logo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Non ancora selezionato')` |

---

[← Precedente: 04 — Temi, Icone e Personalizzazione](04-themes-icons-and-customization.md) | [Indice](README.md) | [Successivo: 06 — Sicurezza e Autorizzazioni →](06-security-and-authorization.md)
