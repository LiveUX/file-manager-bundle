# 05 — FormType (`FileManagerPickerType`) e integraciones con editores

[← Anterior: 04 — Temas, iconos y personalización](04-themes-icons-and-customization.md) | [Índice](README.md) | [Siguiente: 06 — Seguridad y autorización →](06-security-and-authorization.md)

`FileManagerPickerType` se integra de forma nativa con el componente Symfony Form. Admite tanto **archivos individuales (`?string`)** como **galerías multiarchivo (`list<string>` / JSON)**, incluye **9 temas**, **5 variantes de diseño listas para usar**, **reordenación accesible de galerías mediante arrastrar y soltar, teclado o pantalla táctil** y soporte completo para **plantillas Twig personalizadas (`template`)**.

> **De un vistazo: ¿qué cubre esta guía?**  
> Cuando creas un formulario de producto, entrada de blog o perfil de usuario, en lugar de recurrir a un `<input type="file">` básico, `FileManagerPickerType` te ofrece un campo de Symfony Form listo para usar que **abre el gestor de archivos para seleccionar recursos existentes o subir nuevos**.  
> También aprenderás a integrar **`CollectionType` / `LiveCollectionType`**, **7 editores WYSIWYG y de bloques populares** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote y Editor.js) y **EasyAdmin 4/5** en unas pocas líneas de código.

> [!NOTE]
> El tema de formularios (`@LiveUXFileManager/form/picker.html.twig`) y el contenedor `DialogHost` se **registran e inyectan automáticamente** por el bundle; no necesitas tocar `twig.yaml` ni `base.html.twig`.

---

## 1. Todas las opciones de `FileManagerPickerType`

| Opción | Tipo | Por defecto | Descripción |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Perfil del gestor de archivos que se abrirá. |
| `locale` | `?string` | `null` | `null` hereda el idioma del perfil; pasa cualquiera de los 12 idiomas integrados (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`) para sobrescribirlo por campo. |
| `volume` | `?string` | `null` | Nombre del volumen inicial (`null` usa el primer volumen del perfil). |
| `multiple` | `bool` | `false` | `false` = archivo único (`?string`), `true` = galería multiarchivo (`list<string>`). |
| `max_files` | `?int` | `null` | Número máximo de archivos permitidos cuando `multiple: true` (`null` = ilimitado; también se valida estrictamente en el servidor). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Estilo de presentación del diálogo. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Borde del panel deslizable cuando `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` hereda `ui.mobile_dialog_mode` del perfil; sobrescribe el diseño del diálogo en móviles (`<= 767px`) por campo. |
| `return_type` | `'url'\|'path'` | `'url'` | Define si se guarda la URL completa (`/uploads/a.jpg`) o la ruta relativa (`a.jpg`) en la base de datos *(consulta la guía de decisión más abajo)*. |
| `preview` | `bool` | `true` | Controla las miniaturas de vista previa de imágenes/archivos en **todas las variantes** (único y múltiple). Cuando es `false`, solo se muestra el nombre o la ruta del archivo. |
| `button_label` | `?string` | `null` | Etiqueta personalizada del botón selector (p. ej., `'Elegir imagen'`, `'Cambiar logotipo'`). |
| `placeholder` | `?string` | `null` | Texto de marcador de posición mostrado cuando no hay ningún archivo seleccionado. |
| `theme` | `?string` | `null` | `null` hereda el tema del perfil. Acepta cualquiera de los 9 temas integrados o el nombre de tu tema CSS personalizado (p. ej., `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` hereda el esquema de color del perfil o el valor por defecto (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Variante de diseño integrada: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Ruta a una **plantilla Twig 100 % personalizada** que sustituye a las variantes integradas (p. ej., `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Opción estándar de Symfony Form: cuando es `true`, el campo pasa a solo lectura y se bloquean los controles de abrir, limpiar y reordenar. |

### ¿Debería elegir `return_type: 'url'` o `'path'`?
- **`'url'` (por defecto — la opción más sencilla):** Guarda `/uploads/products/thermos.webp` o `https://cdn.example.com/thermos.webp` en la base de datos. En Twig puedes renderizarlo directamente con `<img src="{{ product.coverImage }}">` sin lógica adicional.
- **`'path'` (flexible / ideal para migrar de CDN):** Guarda únicamente la ruta relativa `products/thermos.webp`. Si en el futuro cambias de disco o de dominio CDN, no tendrás que migrar registros de base de datos: basta con renderizar mediante `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` o anteponer la URL base de tu CDN.

### Seguridad integrada y reordenación accesible de galerías
- **Endurecimiento POST en el servidor:** Incluso si alguien manipula el `<input>` oculto en las herramientas de desarrollo del navegador para inyectar `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, un JSON malformado superior a 256 KB, más de 500 elementos, UTF-8 inválido, valores que superen `max_files` o arrays de contaminación de parámetros HTTP (HPP), `FileManagerPickerType` **lo intercepta y rechaza automáticamente en el servidor**.
- **Reordenación accesible de galerías (`multiple: true`):** Los usuarios pueden reordenar los elementos de una galería de **3 formas**:
  1. **Arrastrar y soltar** en escritorio,
  2. **Teclas de flecha `←` / `→`** con una tarjeta enfocada (la dirección se invierte automáticamente en idiomas RTL como el árabe),
  3. **Botones `◀` / `▶`** en cada tarjeta para dispositivos móviles y táctiles.

---

## 2. 5 variantes de diseño integradas (`variant`)

`variant` y `theme` son completamente independientes. Cada variante funciona tanto en modo **único** como **múltiple** en todos los temas:

| Variante | Modo único (`multiple: false`) | Modo múltiple (`multiple: true`) | Mejor caso de uso |
| :--- | :--- | :--- | :--- |
| `compact` *(por defecto)* | Miniatura cuadrada de 60×60 + campo + fila Elegir/Limpiar | Tarjetas de galería en fila + recuadro punteado `+` para añadir | Campos generales de formulario, galerías de producto |
| `card` | Tarjeta de portada 16:10 + nombre de archivo + acciones inferiores | Cuadrícula de tarjetas | Portadas de blog/artículos, banners principales, imágenes destacadas |
| `dropzone` | Recuadro grande punteado clicable + vista previa en vivo | Caja superior de adición + tarjetas de galería debajo | Zonas destacadas de subida de medios o archivos |
| `avatar` | Vista previa circular `82×82` de perfil/logotipo + botones laterales | Avatares circulares `72×72` + botón circular `+` | Fotos de perfil de usuario, logotipos de marca |
| `input` | Grupo de entrada minimalista de una sola línea sin miniaturas | Lista de etiquetas/chips eliminables + `+ Añadir` | Formularios compactos, PDFs, documentos, contratos |

![Las 5 variantes de diseño integradas de FileManagerPickerType y galería multiarchivo](../images/form-pickers.png)

---

## 3. Ejemplo completo de entidad + FormType

### Entidad de Doctrine (`src/Entity/Product.php`)
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

### Clase Symfony Form (`src/Form/ProductType.php`)
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
                'label' => 'Imagen de portada del producto',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Elegir portada',
                'placeholder' => 'Ninguna imagen de portada seleccionada…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galería del producto',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Añadir imágenes',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Crear tu propia interfaz para FormType (3 enfoques)

Nunca quedas atado a las 5 variantes integradas ni a los 9 temas: puedes diseñar **interfaces 100 % personalizadas con HTML / Bootstrap / Tailwind / Alpine** y cableado completo con Stimulus.

### Enfoque 1: Opción `template` por campo (el más rápido y flexible)

Pasa una ruta `'template'` en la definición de tu formulario. El bundle adjunta automáticamente el controlador contenedor de Stimulus (`liveux--file-manager-bundle--picker`) y delega todo el marcado interno a tu archivo Twig:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Elegir banner',
        'placeholder' => 'Todavía no se ha seleccionado ninguna portada',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Añadir a la galería',
    ]);
```

#### Ejemplo A — Plantilla personalizada para selector único (`templates/forms/custom_single_picker.html.twig`)
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

#### Ejemplo B — Plantilla personalizada para galería multiarchivo (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Imágenes seleccionadas: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js renderiza los elementos seleccionados como tarjetas reordenables dentro de este contenedor #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Referencia para plantillas FormType personalizadas

| Atributo / Evento | Descripción |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Requerido** — El elemento `<input>` que se envía al servidor junto con el formulario. |
| `data-{{ ctrl }}-target="singlePreview"` | Contenedor donde se renderiza automáticamente la vista previa de la imagen/archivo en modo único. |
| `data-{{ ctrl }}-target="fileLabel"` | Elemento de texto que se actualiza con el nombre o la ruta del archivo seleccionado. |
| `data-{{ ctrl }}-target="clearBtn"` | Botón de limpiar; se establece automáticamente en `disabled` cuando está vacío o cuando `isDisabled` es `true`. |
| `data-{{ ctrl }}-target="galleryGrid"` | Contenedor que gestiona las tarjetas de archivos seleccionados y su reordenación en modo múltiple. |
| `data-{{ ctrl }}-target="countLabel"` | Contador que muestra la cantidad actual de archivos seleccionados en modo múltiple. |
| `data-action="click->{{ ctrl }}#open"` | Abre el diálogo Modal / OffCanvas del gestor de archivos. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Limpia la selección única actual. |
| **Evento JS:** `liveux:file-manager:picker-changed` | Se dispara cada vez que se seleccionan, limpian, eliminan o reordenan elementos, con `event.detail` conteniendo `{ values, value, multiple }`. |

**Variables Twig pasadas automáticamente a tu plantilla personalizada:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Enfoque 2: Sobrescritura global con `form_themes` de Symfony

Para personalizar el aspecto de **todos** los campos `FileManagerPickerType` en todo tu proyecto desde un único lugar, sobrescribe el bloque `liveux_file_manager_picker_widget` en tu propio tema de formularios:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Tu marcado HTML y CSS personalizado global #}
{% endblock %}
```

---

### Enfoque 3: Clase FormType hija reutilizable

En lugar de repetir las mismas opciones `profile`, `theme`, `template` y `mode` en varios formularios, crea una subclase FormType dedicada:

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
            'button_label' => 'Elegir portada de producto',
            'placeholder' => 'Ninguna imagen de portada seleccionada…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Ahora úsala en cualquier formulario con una sola línea:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. `CollectionType` de Symfony y `LiveCollectionType` de Symfony UX

![Demostración en vivo de Symfony CollectionType y LiveCollectionType](../images/demo-collections.gif)

`FileManagerPickerType` funciona sin fricción tanto con el clásico **`CollectionType`** de Symfony (adición/eliminación dinámica de filas con `data-prototype`) como con **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** El controlador Stimulus `picker` se conecta automáticamente en cuanto se inserta una fila nueva en el DOM, sin inicialización manual en JavaScript.
- **`LiveCollectionType`:** Incluso cuando Idiomorph transforma el DOM al añadir o eliminar filas, `picker_controller.js` (`render:finished` + `MutationObserver`) vuelve a renderizar automáticamente todas las vistas previas individuales y tarjetas de galería multiarchivo a partir del valor más reciente del `<input>`.
- **Tipos de entrada compuestos frente a escalares directos:**
  - Al usar un **subformulario compuesto** como `entry_type: SlideItemType::class`, `LiveCollectionTrait::addCollectionItem()` envía `[]` para la fila nueva y el subformulario lo procesa sin problemas, sin código adicional.
  - Al usar directamente un `entry_type: FileManagerPickerType::class` de archivo único (`multiple: false`), el listener `PRE_SUBMIT` integrado en el selector rechaza arrays como parte de la protección contra HPP. Añadir el listener `PRE_SUBMIT` de 4 líneas que se muestra abajo normaliza las filas vacías recién creadas de `[]` a `''`.

### A. Ejemplo estándar con `CollectionType` de Symfony

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
            ->add('title', TextType::class, ['label' => 'Título'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Imagen de portada',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Adjuntos',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Dentro del FormType padre:
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
">+ Añadir fila</button>
```

### B. Ejemplo con `LiveCollectionType` de Symfony UX (`LiveCollectionTrait`)

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
            // 1) Subformulario compuesto — no necesita listener PRE_SUBMIT extra:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) FileManagerPickerType directo de archivo único (lista escalar):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Solo necesario para entry_type escalar directo (multiple: false):
        // LiveCollectionTrait::addCollectionItem() añade [] para filas nuevas, así que lo normalizamos a ''.
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
        // Guardar tus datos...
    }
}
```

> [!NOTE]
> Asegúrate de que tu plantilla de `LiveCollectionType` llame a `{{ form_end(form) }}` (o incluya `{{ form_row(form._token) }}` si pasas `render_rest: false`) para que se envíe el token CSRF.

#### Plantilla Twig del LiveComponent (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Guardar (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Después, renderiza el componente en cualquier plantilla Twig con una sola etiqueta:
```twig
<twig:SliderFormComponent />
```

---

## 6. Integración con editores de texto enriquecido y bloques — 7 adaptadores integrados (paso a paso)

> **Resumen rápido para principiantes:**  
> Cuando los redactores escriben artículos de blog o descripciones de producto, necesitan una forma fluida de insertar imágenes o PDFs directamente en el contenido. LiveUX FileManagerBundle incluye **7 adaptadores JavaScript listos para usar** para los editores de código abierto más populares: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (`TextEditorField` de EasyAdmin)**, **Summernote** y **Editor.js**. Todos los adaptadores:
> - Abren el gestor de archivos en la capa superior nativa `<dialog>` (*Top Layer*) del navegador (**cero conflictos de `z-index`** ni problemas de *focus-trap* detrás de las ventanas emergentes del editor),
> - Transfieren automáticamente los metadatos SEO (`alt` / `title` / `caption`) de las imágenes seleccionadas,
> - Validan cada URL devuelta frente a vectores XSS como `javascript:` y recorridos de ruta (`../`).

Los siete adaptadores aceptan las mismas opciones comunes:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Una línea en `importmap.php` para usuarios de AssetMapper:**  
> En proyectos con **Webpack Encore**, `import ... from '@liveux/file-manager-bundle/js/integrations/...'` funciona directamente.  
> Si utilizas **Symfony AssetMapper**, registra **únicamente los adaptadores que uses** en `importmap.php` para que `<script type="importmap">` en el navegador reconozca el especificador (estos archivos ya viven dentro del bundle; no se descarga nada de Internet):
> ```php
> // importmap.php (añade solo el/los adaptadores de editor que realmente utilices)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. Integración con TinyMCE 5 / 6 / 7 / 8 (`createTinyMceFilePicker`)

![Integración en vivo de TinyMCE + LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 y 8 comparten exactamente la misma firma de `file_picker_callback` y están **verificados mediante pruebas en navegador real**. El adaptador conecta directamente los botones de explorar integrados en los diálogos **Imagen (`image`)**, **Multimedia (`media`)** y **Enlace (`link`)** de TinyMCE con LiveUX FileManagerBundle.

> **Nota sobre versiones:** Mantén `license_key: 'gpl'` cuando utilices TinyMCE **7 u 8**. Si usas TinyMCE **5 o 6**, simplemente omite esa línea; todo lo demás es idéntico.

#### Paso 1: Carga TinyMCE en tu página (código abierto — sin clave de API)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (desde CDN o tu propio directorio public/) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Paso 2: Conecta `createTinyMceFilePicker` mediante un controlador Stimulus
Crea `assets/controllers/tinymce_editor_controller.js` (funciona igual tanto en **AssetMapper** como en **Webpack Encore**):

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
            license_key: 'gpl', // Modo de licencia abierta gratuita GPL
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Tu perfil en liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'es',
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

#### ¿Qué ocurre?
Cuando un usuario pulsa el botón **Imagen** de la barra de TinyMCE y hace clic en el icono de explorar junto a **Origen**:
1. LiveUX FileManagerBundle se abre por encima del modal de TinyMCE, ya filtrado para imágenes (`filetype: 'image'`).
2. En cuanto el usuario selecciona una imagen, se rellenan automáticamente tanto la **URL de origen** como la **descripción alternativa (`alt`)**.

---

### B. Integración con CKEditor 5 (`openFileManagerForCKEditor5`)

![Integración en vivo de CKEditor 5 + LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Funciona con las compilaciones Classic, Inline, Balloon y Decoupled de CKEditor 5 para insertar **una o varias** imágenes (`insertImage`) y enlaces a documentos/PDF con un solo botón.

#### Paso 1: Añade CKEditor 5 y un botón para insertar medios
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Insertar medios desde el gestor de archivos
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Paso 2: Conecta `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // Panel deslizable lateral para explorar sin tapar el editor
            position: 'end',
            multiple: true,    // Permite insertar varias fotos a la vez
            locale: 'es',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### ¿Qué ocurre?
- Las **imágenes** se insertan de forma nativa mediante el comando `insertImage` de CKEditor 5 como bloques `<figure class="image">`.
- Los **documentos (PDF, ZIP, etc.)** se insertan como enlaces clicables `<a href="...">` etiquetados con su nombre de archivo.

---

### C. Integración con Tiptap / ProseMirror (`insertFromFileManager`)

![Integración en vivo de Tiptap 2 + LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Si usas el editor moderno *headless* **Tiptap 2**, se integra con `@tiptap/extension-image` y `@tiptap/extension-link` con una sola llamada a función.

#### Paso 1: Instala los paquetes
- **Con Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **Con AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Paso 2: Plantilla Twig (barra de herramientas + lienzo del editor + input oculto)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Insertar medios (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Paso 3: Escribe el controlador Stimulus con `insertFromFileManager`
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
            content: this.hiddenInputTarget.value || '<p>Empieza a escribir tu contenido...</p>',
            onUpdate: ({ editor }) => {
                // Mantiene sincronizado el input oculto con el HTML más reciente al enviar el formulario
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'es',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### ¿Qué ocurre?
- Las imágenes se insertan mediante `setImage({ src, alt, title })`, conservando los atributos SEO `alt` y `title`.
- Los documentos (PDF, ZIP, etc.) se insertan automáticamente como enlaces clicables (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Integración con Quill 1.x / 2.x (`createQuillImageHandler` y `openFileManagerForQuill`)

![Integración en vivo de Quill 2 + LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Conecta el botón nativo **Imagen** de la barra de Quill con LiveUX FileManagerBundle en una línea, aplicando atributos SEO `alt` a las imágenes e insertando enlaces clicables para documentos.

#### Paso 1: Incluye el CSS/JS de Quill y el contenedor del editor
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Paso 2: Escribe el controlador Stimulus con `createQuillImageHandler`
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
                        // Al pulsar el botón nativo de Imagen de Quill se abre LiveUX FileManagerBundle
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'es',
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

### E. Integración con Trix Editor — `TextEditorField` de EasyAdmin y Turbo (`openFileManagerForTrix`)

![Integración en vivo de Trix + LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Inserta adjuntos de imagen seguros (`Trix.Attachment`) y enlaces de documentos en `<trix-editor>`, el editor de texto enriquecido predeterminado que usa **`TextEditorField` de EasyAdmin** y las aplicaciones con Symfony Turbo/Hotwire.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Insertar desde el gestor de archivos</button>
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
            locale: 'es',
        });
    }
}
```

---

### F. Integración con Summernote (`openFileManagerForSummernote`)

![Integración en vivo de Summernote + LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Inserta nodos DOM `<img>` saneados (con `alt` y `title` SEO) y enlaces `<a>` en Summernote, muy habitual en paneles de administración basados en Bootstrap.

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
            locale: 'es',
        });
    }
}
```

---

### G. Integración con Editor.js (editor JSON basado en bloques) (`openFileManagerForEditorJs`)

![Integración en vivo de Editor.js + LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Inserta bloques estructurados `image` (`@editorjs/image`) o `simpleImage` (`@editorjs/simple-image`) y enlaces a documentos en **Editor.js**, ideal para CMS *headless* y editores de bloques estilo Notion.

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
            imageBlockType: 'simpleImage', // Déjalo como 'image' si utilizas @editorjs/image
            locale: 'es',
        });
    }
}
```

---

### H. Integración con GrapesJS (Constructor visual de páginas y correos) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Integración en vivo de GrapesJS + LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

Se integra de forma fluida con **GrapesJS (0.20+ / 0.21+ / 0.22+)** de dos maneras:
1. **`createGrapesJsAssetManagerConfig()`:** Reemplaza el modal integrado de Asset Manager de GrapesJS por LiveUX FileManagerBundle (se abre automáticamente al hacer doble clic en cualquier imagen del lienzo).
2. **`openFileManagerForGrapesJs()`:** Inserta uno o varios componentes de imagen/enlace directamente en el lienzo desde un botón personalizado.

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
            // 1) Reemplaza el Asset Manager integrado por LiveUX FileManager:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'es',
            }),
        });
    }

    // 2) O inserta imágenes/enlaces directamente en el lienzo desde un botón personalizado:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'es',
        });
    }
}
```

---

### I. Integración con Meta Lexical (`openFileManagerForLexical`)

![Integración en vivo de Meta Lexical + LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

Se integra con el motor de texto enriquecido extensible **Lexical** de Meta (`lexical` 0.15+ / 0.20+ / 0.30+) mediante despacho de comandos (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) o un callback personalizado `onInsert`, con validación estricta de URL contra XSS y metadatos `altText` para SEO.

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
            nodes: [/* ImageNode personalizado */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Inserta tu ImageNode / DecoratorNode personalizado en el estado de Lexical
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
            locale: 'es',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. Integración con EasyAdmin 4 / 5 — paso a paso (`FileManagerField`)

![Demostración en vivo de EasyAdmin 5 + LiveUX FileManagerField](../images/demo-easyadmin.gif)

El campo integrado `FileManagerField` no requiere dependencias adicionales:
- En las páginas **Index** y **Detail**, renderiza automáticamente miniaturas e insignias para los archivos seleccionados (con validación de seguridad de URL garantizada por `liveux_fm_describe_field_item()`).
- En las páginas **New** y **Edit**, se renderiza directamente como `FileManagerPickerType`.

### Paso 1: Carga los assets de tu app en `DashboardController` de EasyAdmin (¡importante!)

Como EasyAdmin utiliza su propia plantilla base independiente (no extiende tu `base.html.twig`), registra el punto de entrada `app` de tus assets dentro de `DashboardController::configureAssets()`:

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
        // Si utilizas AssetMapper:
        return Assets::new()->addAssetMapperEntry('app');

        // Si utilizas Webpack Encore, sustituye la línea anterior por:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Paso 2: Usa `FileManagerField` en tu `CrudController`

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
        yield TextField::new('title', 'Nombre del producto');

        yield FileManagerField::new('coverImage', 'Imagen de portada')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Elegir portada');

        yield FileManagerField::new('galleryImages', 'Galería del producto')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Todos los métodos fluidos de `FileManagerField` (16 métodos)

| Método | Opción correspondiente | Ejemplo |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('es')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Cambiar logotipo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Todavía sin seleccionar')` |

---

[← Anterior: 04 — Temas, iconos y personalización](04-themes-icons-and-customization.md) | [Índice](README.md) | [Siguiente: 06 — Seguridad y autorización →](06-security-and-authorization.md)
