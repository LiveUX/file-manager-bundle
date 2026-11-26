# 05 — FormType (`FileManagerPickerType`) & Editor Integrations

[← Previous: 04 — Themes, Icons & Customization](04-themes-icons-and-customization.md) | [Index](README.md) | [Next: 06 — Security & Authorization →](06-security-and-authorization.md)

`FileManagerPickerType` integrates natively with the Symfony Form component. It supports both **single files (`?string`)** and **multi-file galleries (`list<string>` / JSON)**, comes with **9 themes**, **5 ready-to-use layout variants**, **accessible drag-and-drop / keyboard / touch gallery reordering**, and full **custom Twig template (`template`)** support.

> **At a Glance: What Does This Guide Cover?**  
> When building a product, blog post, or user profile form, instead of using a basic `<input type="file">`, `FileManagerPickerType` gives you a ready-made Symfony Form field that **opens the file manager so users can pick existing assets or upload new ones**.  
> You'll also learn how to integrate **CollectionType / LiveCollectionType**, **7 popular WYSIWYG & block editors** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js), and **EasyAdmin 4/5** with just a few lines of code.

> [!NOTE]
> The form theme (`@LiveUXFileManager/form/picker.html.twig`) and the `DialogHost` container are **automatically registered and injected** by the bundle — you don't need to touch `twig.yaml` or `base.html.twig`.

---

## 1. All `FileManagerPickerType` Options

| Option | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | File manager profile to open. |
| `locale` | `?string` | `null` | `null` inherits the profile locale; pass any of the 12 built-in UI languages (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`) to override per field. |
| `volume` | `?string` | `null` | Initial volume name (`null` uses the profile's first volume). |
| `multiple` | `bool` | `false` | `false` = single file (`?string`), `true` = multi-file gallery (`list<string>`). |
| `max_files` | `?int` | `null` | Maximum number of files allowed when `multiple: true` (`null` = unlimited; also strictly enforced server-side). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Dialog presentation style. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Drawer edge when `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` inherits the profile's `ui.mobile_dialog_mode`; overrides the mobile (`<= 767px`) dialog layout per field. |
| `return_type` | `'url'\|'path'` | `'url'` | Whether to store a full URL (`/uploads/a.jpg`) or a relative path (`a.jpg`) in your database *(see the decision guide below)*. |
| `preview` | `bool` | `true` | Controls image/file preview thumbnails across **all variants** (single and multiple). When `false`, only the filename/path is displayed. |
| `button_label` | `?string` | `null` | Custom label for the picker button (e.g., `'Choose Image'`, `'Change Logo'`). |
| `placeholder` | `?string` | `null` | Placeholder text shown when no file is selected. |
| `theme` | `?string` | `null` | `null` inherits the profile theme. Accepts any of the 9 built-in themes or your custom CSS theme name (e.g., `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` inherits the profile/default color scheme (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Built-in layout variant: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Path to a **100% custom Twig template** that replaces the built-in variants (e.g., `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Standard Symfony Form option: when `true`, the field becomes completely read-only and open/clear/reorder controls are locked. |

### Should I Choose `return_type: 'url'` or `'path'`?
- **`'url'` (Default — Simplest):** Stores `/uploads/products/thermos.webp` or `https://cdn.example.com/thermos.webp` in the database. In Twig, render it directly with `<img src="{{ product.coverImage }}">` with zero extra logic.
- **`'path'` (Flexible / CDN-Migration Friendly):** Stores only the relative path `products/thermos.webp`. If you ever switch storage disks or CDN domains later, you don't have to migrate database records — simply render via `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` or prepend your CDN base URL.

### Built-In Security & Accessible Gallery Reordering
- **Server-Side POST Hardening:** Even if someone tampers with the hidden `<input>` in browser DevTools to inject `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, malformed JSON over 256 KB, more than 500 items, invalid UTF-8, values exceeding `max_files`, or HTTP Parameter Pollution (HPP) arrays, `FileManagerPickerType` **automatically intercepts and rejects it on the server**.
- **Accessible Gallery Reordering (`multiple: true`):** Users can reorder gallery items in **3 ways**:
  1. **Drag & Drop** on desktop,
  2. **`←` / `→` Arrow Keys** when a card is focused (direction automatically reverses in RTL locales like Arabic),
  3. **`◀` / `▶` Buttons** on each card for mobile and touch devices.

---

## 2. 5 Built-In Layout Variants (`variant`)

`variant` and `theme` are completely independent. Every variant works in both **single** and **multiple** modes across all themes:

| Variant | Single Mode (`multiple: false`) | Multiple Mode (`multiple: true`) | Best Use Case |
| :--- | :--- | :--- | :--- |
| `compact` *(default)* | 60×60 square thumbnail + input + Choose/Clear row | Side-by-side gallery cards + dashed `+` add tile | General form fields, product galleries |
| `card` | 16:10 cover preview card + filename + bottom actions | Card grid | Blog/article covers, hero banners, featured images |
| `dropzone` | Large clickable dashed box + live preview | Large add box on top + gallery cards below | Prominent media/file upload areas |
| `avatar` | Circular `82×82` profile/logo preview + side buttons | Circular `72×72` avatar chips + circular `+` tile | User profile photos, brand logos |
| `input` | Minimal single-line input group without thumbnails | Removable chip/tag list + `+ Add` | Compact forms, PDFs, documents, contracts |

![FileManagerPickerType 5 Built-In Layout Variants and Multi-File Gallery](../images/form-pickers.png)

---

## 3. Complete Entity + FormType Example

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

### Symfony Form Class (`src/Form/ProductType.php`)
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
                'label' => 'Product Cover Image',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Choose Cover',
                'placeholder' => 'No cover image selected…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Product Gallery',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Add Images',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Building Your Own Custom FormType UI (3 Approaches)

You are never locked into the 5 built-in variants or 9 themes — you can craft **100% custom HTML / Bootstrap / Tailwind / Alpine designs** with full Stimulus wiring.

### Approach 1: Per-Field `template` Option (Fastest & Most Flexible)

Pass a `'template'` path in your form definition. The bundle automatically attaches the wrapper Stimulus controller (`liveux--file-manager-bundle--picker`) and delegates all inner markup to your Twig file:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Choose Banner',
        'placeholder' => 'No cover image selected yet',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Add to Gallery',
    ]);
```

#### Example A — Custom Single Picker Template (`templates/forms/custom_single_picker.html.twig`)
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

#### Example B — Custom Multi-File Gallery Template (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Selected Images: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js renders selected items as reorderable cards inside this container #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Custom FormType Template Reference

| Attribute / Event | Description |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Required** — The `<input>` element submitted to the server with the form. |
| `data-{{ ctrl }}-target="singlePreview"` | Container where the selected image/file preview is automatically rendered in single mode. |
| `data-{{ ctrl }}-target="fileLabel"` | Text element updated with the selected filename/path. |
| `data-{{ ctrl }}-target="clearBtn"` | Clear button; automatically set to `disabled` when empty or when `isDisabled` is `true`. |
| `data-{{ ctrl }}-target="galleryGrid"` | Container managing selected file cards and reordering in multiple mode. |
| `data-{{ ctrl }}-target="countLabel"` | Counter element displaying the current number of selected files in multiple mode. |
| `data-action="click->{{ ctrl }}#open"` | Opens the file manager Modal / OffCanvas dialog. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Clears the current single selection. |
| **JS Event:** `liveux:file-manager:picker-changed` | Dispatched whenever items are selected, cleared, removed, or reordered, with `event.detail` containing `{ values, value, multiple }`. |

**Twig Variables Automatically Passed to Your Custom Template:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Approach 2: Application-Wide Symfony `form_themes` Override

To customize the appearance of **all** `FileManagerPickerType` fields across your entire project in one place, override the `liveux_file_manager_picker_widget` block in your own form theme:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Your global custom HTML & CSS markup #}
{% endblock %}
```

---

### Approach 3: Reusable Custom Child FormType Class

Instead of repeating the same `profile`, `theme`, `template`, and `mode` options across multiple forms, create a dedicated FormType subclass:

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
            'button_label' => 'Choose Product Cover',
            'placeholder' => 'No cover image selected…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Now use it in any form with a single line:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` & Symfony UX `LiveCollectionType`

![Symfony CollectionType and LiveCollectionType Live Demo](../images/demo-collections.gif)

`FileManagerPickerType` works seamlessly with both classic Symfony **`CollectionType`** (dynamic `data-prototype` row addition/removal) and **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** The Stimulus `picker` controller automatically connects as soon as a new row is inserted into the DOM — no manual JavaScript initialization required.
- **`LiveCollectionType`:** Even when Idiomorph morphs the DOM during row additions or deletions, `picker_controller.js` (`render:finished` + `MutationObserver`) automatically re-renders every single preview and multi-file gallery card from the latest `<input>` value.
- **Compound vs. Direct Scalar Entry Types:**
  - When using a **compound sub-form** such as `entry_type: SlideItemType::class`, `LiveCollectionTrait::addCollectionItem()` submits `[]` for the new row and the sub-form handles it smoothly — no extra code needed.
  - When using a direct single-file (`multiple: false`) `entry_type: FileManagerPickerType::class`, the picker's built-in `PRE_SUBMIT` listener rejects array payloads as part of HPP protection. Adding the 4-line collection `PRE_SUBMIT` listener shown below normalizes newly added empty rows from `[]` to `''`.

### A. Standard Symfony `CollectionType` Example

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
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Cover Image',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Attachments',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Inside the parent FormType:
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
">+ Add Row</button>
```

### B. Symfony UX `LiveCollectionType` Example (`LiveCollectionTrait`)

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
            // 1) Compound sub-form — no extra PRE_SUBMIT listener needed:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) Direct single-file FileManagerPickerType (scalar list):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Only needed for direct scalar (multiple: false) entry_type:
        // LiveCollectionTrait::addCollectionItem() appends [] for new rows, so normalize it to ''.
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
        // Persist your data...
    }
}
```

> [!NOTE]
> Make sure your `LiveCollectionType` template calls `{{ form_end(form) }}` (or includes `{{ form_row(form._token) }}` if you pass `render_rest: false`) so the CSRF token is submitted.

#### LiveComponent Twig Template (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Save (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Then render the component anywhere in Twig with a single tag:
```twig
<twig:SliderFormComponent />
```

---

## 6. Rich Text & Block Editor Integrations — 7 Built-In Adapters (Step-by-Step)

> **Quick Overview for Beginners:**  
> Whenever editors write blog posts or product descriptions, they need a seamless way to insert images or PDFs right into the content. LiveUX FileManagerBundle ships with **7 ready-made JavaScript adapters** for the most popular open-source editors: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (EasyAdmin `TextEditorField`)**, **Summernote**, and **Editor.js**. Every adapter:
> - Opens the file manager on the browser's native `<dialog>` Top Layer (**zero `z-index` conflicts** or focus-trap bugs behind editor popups),
> - Automatically carries over SEO metadata (`alt` / `title` / `caption`) for selected images,
> - Validates every returned URL against `javascript:` and path-traversal (`../`) XSS vectors.

All seven adapters accept the same common options:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **One-Line `importmap.php` Entry for AssetMapper Users:**  
> In **Webpack Encore** projects, `import ... from '@liveux/file-manager-bundle/js/integrations/...'` works directly.  
> If you use **Symfony AssetMapper**, register **only the adapter(s) you use** in `importmap.php` so the browser's `<script type="importmap">` recognizes the specifier (these files already live inside the bundle — nothing is downloaded from the internet):
> ```php
> // importmap.php (add only the editor adapter(s) you actually use)
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

![TinyMCE + LiveUX FileManagerBundle Live Integration](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7, and 8 all share the exact same `file_picker_callback` signature and are **verified with live browser tests**. The adapter hooks TinyMCE's built-in browse buttons inside its **Image (`image`)**, **Media (`media`)**, and **Link (`link`)** dialogs directly to LiveUX FileManagerBundle.

> **Version Note:** Keep `license_key: 'gpl'` when running TinyMCE **7 or 8**. If you are on TinyMCE **5 or 6**, simply omit that line — everything else is identical.

#### Step 1: Load TinyMCE on Your Page (Open Source — No API Key Needed)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (from CDN or your own public/ directory) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Step 2: Connect `createTinyMceFilePicker` via a Stimulus Controller
Create `assets/controllers/tinymce_editor_controller.js` (works identically in both **AssetMapper** and **Webpack Encore**):

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
            license_key: 'gpl', // Free open-source GPL license mode
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Your liveux_file_manager.yaml profile
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'en',
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

#### What Happens?
When a user clicks TinyMCE's **Image** toolbar button and clicks the browse icon next to **Source**:
1. LiveUX FileManagerBundle opens above TinyMCE's modal, pre-filtered to images (`filetype: 'image'`).
2. Once the user picks an image, both the **Source URL** and **Alternative description (`alt`)** fields are automatically populated!

---

### B. CKEditor 5 Integration (`openFileManagerForCKEditor5`)

![CKEditor 5 + LiveUX FileManagerBundle Live Integration](../images/demo-editor-ckeditor5.gif)

Works with CKEditor 5 Classic, Inline, Balloon, and Decoupled builds to insert **single or multiple** images (`insertImage`) and document/PDF links with a single button.

#### Step 1: Add CKEditor 5 and an Insert Media Button
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Insert Media from File Manager
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Step 2: Wire `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // Slide-over drawer so you can browse without covering the editor
            position: 'end',
            multiple: true,    // Insert multiple photos at once
            locale: 'en',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### What Happens?
- **Images** are inserted natively via CKEditor 5's `insertImage` command as `<figure class="image">` blocks.
- **Documents (PDF, ZIP, etc.)** are inserted as clickable `<a href="...">` links labeled with their filename.

---

### C. Tiptap / ProseMirror Integration (`insertFromFileManager`)

![Tiptap 2 + LiveUX FileManagerBundle Live Integration](../images/demo-editor-tiptap.gif)

If you use the modern headless editor **Tiptap 2**, it integrates with `@tiptap/extension-image` and `@tiptap/extension-link` in a single function call.

#### Step 1: Install Packages
- **With Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **With AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Step 2: Twig Template (Toolbar + Editor Canvas + Hidden Input)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Insert Media (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Step 3: Write the `insertFromFileManager` Stimulus Controller
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
            content: this.hiddenInputTarget.value || '<p>Start writing your content...</p>',
            onUpdate: ({ editor }) => {
                // Keep the hidden input synced with the latest HTML for form submission
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'en',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### What Happens?
- Images are inserted via `setImage({ src, alt, title })`, preserving SEO `alt` and `title` attributes.
- Documents (PDF, ZIP, etc.) are automatically inserted as clickable links (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Quill 1.x / 2.x Integration (`createQuillImageHandler` & `openFileManagerForQuill`)

![Quill 2 + LiveUX FileManagerBundle Live Integration](../images/demo-editor-quill.gif)

Connects Quill's native toolbar **Image** button to LiveUX FileManagerBundle in one line — applying SEO `alt` attributes to images and inserting clickable links for documents.

#### Step 1: Include Quill CSS/JS and the Editor Container
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Step 2: Write the `createQuillImageHandler` Stimulus Controller
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
                        // Clicking Quill's built-in Image button opens LiveUX FileManagerBundle!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'en',
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

### E. Trix Editor Integration — EasyAdmin `TextEditorField` & Turbo (`openFileManagerForTrix`)

![Trix + LiveUX FileManagerBundle Live Integration](../images/demo-editor-trix.gif)

Inserts safe image attachments (`Trix.Attachment`) and document links into `<trix-editor>` — the default rich text editor used by **EasyAdmin's built-in `TextEditorField`** and Symfony Turbo/Hotwire applications.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Insert from File Manager</button>
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
            locale: 'en',
        });
    }
}
```

---

### F. Summernote Integration (`openFileManagerForSummernote`)

![Summernote + LiveUX FileManagerBundle Live Integration](../images/demo-editor-summernote.gif)

Inserts sanitized DOM `<img>` nodes (with SEO `alt` & `title`) and `<a>` links into Summernote, widely used in Bootstrap-based admin panels.

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
            locale: 'en',
        });
    }
}
```

---

### G. Editor.js (Block-Based JSON Editor) Integration (`openFileManagerForEditorJs`)

![Editor.js + LiveUX FileManagerBundle Live Integration](../images/demo-editor-editorjs.gif)

Inserts structured `image` (`@editorjs/image`) or `simpleImage` (`@editorjs/simple-image`) blocks and document links into **Editor.js**, ideal for headless CMS and Notion-style block editors.

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
            imageBlockType: 'simpleImage', // Leave as 'image' if using @editorjs/image
            locale: 'en',
        });
    }
}
```

---

### H. GrapesJS (Visual Page & Email Builder) Integration (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![GrapesJS + LiveUX FileManagerBundle Live Integration](../images/demo-editor-grapesjs.gif)

Integrates seamlessly with **GrapesJS (0.20+ / 0.21+ / 0.22+)** in two ways:
1. **`createGrapesJsAssetManagerConfig()`:** Replaces GrapesJS's built-in Asset Manager modal with LiveUX FileManagerBundle (opens automatically when double-clicking any image on the canvas).
2. **`openFileManagerForGrapesJs()`:** Inserts single or multiple image/link components directly onto the canvas from a custom button.

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
            // 1) Replace the built-in Asset Manager with LiveUX FileManager:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'en',
            }),
        });
    }

    // 2) Or insert images/links directly onto the canvas from a custom button:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'en',
        });
    }
}
```

---

### I. Meta Lexical Integration (`openFileManagerForLexical`)

![Meta Lexical + LiveUX FileManagerBundle Live Integration](../images/demo-editor-lexical.gif)

Integrates with Meta's extensible **Lexical** rich-text engine (`lexical` 0.15+ / 0.20+ / 0.30+) via command dispatch (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) or a custom `onInsert` callback with strict XSS URL validation and SEO `altText` metadata.

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
            nodes: [/* Custom ImageNode */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Insert your custom ImageNode / DecoratorNode into Lexical state
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
            locale: 'en',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. EasyAdmin 4 / 5 Integration — Step-by-Step (`FileManagerField`)

![EasyAdmin 5 + LiveUX FileManagerField Live Demo](../images/demo-easyadmin.gif)

The built-in `FileManagerField` requires zero extra dependencies:
- On **Index** and **Detail** pages, it automatically renders thumbnails and badges for selected files (with URL safety enforced by `liveux_fm_describe_field_item()`).
- On **New** and **Edit** pages, it renders directly as `FileManagerPickerType`.

### Step 1: Load Your App Assets in EasyAdmin's `DashboardController` (Important!)

Because EasyAdmin uses its own standalone base layout (it does not extend your `base.html.twig`), register your `app` asset entrypoint inside `DashboardController::configureAssets()`:

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
        // If using AssetMapper:
        return Assets::new()->addAssetMapperEntry('app');

        // If using Webpack Encore, replace the line above with:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Step 2: Use `FileManagerField` in Your `CrudController`

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
        yield TextField::new('title', 'Product Name');

        yield FileManagerField::new('coverImage', 'Cover Image')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Choose Cover');

        yield FileManagerField::new('galleryImages', 'Product Gallery')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### All `FileManagerField` Fluent Methods (16 Methods)

| Method | Corresponding Option | Example |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('en')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Change Logo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Not selected yet')` |

---

[← Previous: 04 — Themes, Icons & Customization](04-themes-icons-and-customization.md) | [Index](README.md) | [Next: 06 — Security & Authorization →](06-security-and-authorization.md)
