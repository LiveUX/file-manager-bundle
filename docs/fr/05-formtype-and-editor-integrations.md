# 05 — FormType (`FileManagerPickerType`) & intégrations d'éditeurs

[← Précédent : 04 — Thèmes, icônes & personnalisation](04-themes-icons-and-customization.md) | [Index](README.md) | [Suivant : 06 — Sécurité & autorisations →](06-security-and-authorization.md)

`FileManagerPickerType` s'intègre nativement au composant Form de Symfony. Il prend en charge aussi bien la sélection d'un **fichier unique (`?string`)** que les **galeries multi-fichiers (`list<string>` / JSON)**, propose **9 thèmes**, **5 variantes de mise en page prêtes à l'emploi**, la **réorganisation accessible des galeries par glisser-déposer / clavier / tactile**, ainsi que la prise en charge complète de **templates Twig personnalisés (`template`)**.

> **En un coup d'œil : ce que couvre ce guide**  
> Lorsque vous créez un formulaire de produit, d'article de blog ou de profil utilisateur, au lieu d'un simple `<input type="file">`, `FileManagerPickerType` met à votre disposition un champ Symfony Form prêt à l'emploi qui **ouvre le gestionnaire de fichiers pour sélectionner des médias existants ou en envoyer de nouveaux**.  
> Vous apprendrez également à intégrer **`CollectionType` / `LiveCollectionType`**, **7 éditeurs WYSIWYG et par blocs populaires** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js) ainsi qu'**EasyAdmin 4/5** en quelques lignes de code.

> [!NOTE]
> Le thème de formulaire (`@LiveUXFileManager/form/picker.html.twig`) et le conteneur `DialogHost` sont **enregistrés et injectés automatiquement** par le bundle — vous n'avez rien à modifier dans `twig.yaml` ni dans `base.html.twig`.

---

## 1. Toutes les options de `FileManagerPickerType`

| Option | Type | Par défaut | Description |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Profil du gestionnaire de fichiers à ouvrir. |
| `locale` | `?string` | `null` | `null` hérite de la locale du profil ; passez l'une des 12 langues d'interface intégrées (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`) pour surcharger champ par champ. |
| `volume` | `?string` | `null` | Nom du volume initial (`null` utilise le premier volume du profil). |
| `multiple` | `bool` | `false` | `false` = fichier unique (`?string`), `true` = galerie multi-fichiers (`list<string>`). |
| `max_files` | `?int` | `null` | Nombre maximal de fichiers autorisés lorsque `multiple: true` (`null` = illimité ; également appliqué strictement côté serveur). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Style d'affichage de la boîte de dialogue. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Bord d'ouverture du tiroir lorsque `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` hérite de `ui.mobile_dialog_mode` du profil ; surcharge la mise en page sur mobile (`<= 767px`) champ par champ. |
| `return_type` | `'url'\|'path'` | `'url'` | Indique s'il faut stocker une URL complète (`/uploads/a.jpg`) ou un chemin relatif (`a.jpg`) en base de données *(voir l'aide au choix ci-dessous)*. |
| `preview` | `bool` | `true` | Active ou désactive les miniatures d'aperçu d'images/fichiers pour **toutes les variantes** (simple et multiple). À `false`, seul le nom/chemin du fichier est affiché. |
| `button_label` | `?string` | `null` | Libellé personnalisé du bouton d'ouverture (par ex. `'Choisir une image'`, `'Changer le logo'`). |
| `placeholder` | `?string` | `null` | Texte indicatif affiché lorsqu'aucun fichier n'est sélectionné. |
| `theme` | `?string` | `null` | `null` hérite du thème du profil. Accepte n'importe lequel des 9 thèmes intégrés ou votre nom de thème CSS personnalisé (par ex. `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` hérite du schéma de couleurs du profil/par défaut (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Variante de mise en page intégrée : `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Chemin d'un **template Twig 100 % personnalisé** remplaçant les variantes intégrées (par ex. `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Option standard Symfony Form : à `true`, le champ passe entièrement en lecture seule et les actions d'ouverture, d'effacement et de réorganisation sont verrouillées. |

### Choisir `return_type: 'url'` ou `'path'` ?
- **`'url'` (par défaut — le plus simple) :** Enregistre `/uploads/products/thermos.webp` ou `https://cdn.example.com/thermos.webp` en base de données. Dans Twig, affichez l'image directement avec `<img src="{{ product.coverImage }}">` sans logique supplémentaire.
- **`'path'` (flexible / idéal en cas de migration CDN) :** Enregistre uniquement le chemin relatif `products/thermos.webp`. Si vous changez ultérieurement de disque de stockage ou de domaine CDN, vous n'avez aucune donnée à migrer en base : il suffit d'utiliser `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` ou de préfixer l'URL de base de votre CDN.

### Sécurité intégrée & réorganisation accessible des galeries
- **Durcissement POST côté serveur :** Même si quelqu'un altère le champ `<input>` caché dans les outils de développement du navigateur pour injecter `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, un JSON malformé dépassant 256 Ko, plus de 500 éléments, de l'UTF-8 invalide, plus d'éléments que `max_files` ou des tableaux de pollution de paramètres HTTP (HPP), `FileManagerPickerType` **l'intercepte et le rejette automatiquement côté serveur**.
- **Réorganisation accessible des galeries (`multiple: true`) :** Les utilisateurs peuvent réordonner les éléments d'une galerie de **3 façons** :
  1. **Glisser-déposer** sur ordinateur,
  2. **Touches fléchées `←` / `→`** lorsqu'une carte a le focus (le sens s'inverse automatiquement dans les langues RTL comme l'arabe),
  3. **Boutons `◀` / `▶`** présents sur chaque carte pour les appareils mobiles et tactiles.

---

## 2. Les 5 variantes de mise en page intégrées (`variant`)

Les options `variant` et `theme` sont totalement indépendantes. Chaque variante fonctionne en mode **unique** comme en mode **multiple** avec tous les thèmes :

| Variante | Mode unique (`multiple: false`) | Mode multiple (`multiple: true`) | Cas d'usage idéal |
| :--- | :--- | :--- | :--- |
| `compact` *(par défaut)* | Miniature carrée 60×60 + champ + ligne Choisir/Effacer | Cartes de galerie côte à côte + tuile `+` en pointillés | Champs de formulaire généraux, galeries de produits |
| `card` | Carte d'aperçu 16:10 + nom du fichier + boutons en bas | Grille de cartes | Images de couverture d'articles, bannières, visuels à la une |
| `dropzone` | Grande zone cliquable en pointillés + aperçu en direct | Grande zone d'ajout en haut + cartes de galerie en dessous | Zones d'upload de médias ou de fichiers mises en avant |
| `avatar` | Aperçu circulaire `82×82` de profil/logo + boutons latéraux | Vignettes circulaires `72×72` + tuile circulaire `+` | Photos de profil utilisateur, logos de marque |
| `input` | Groupe de champ minimaliste sur une ligne sans miniature | Liste de puces/tags supprimables + `+ Ajouter` | Formulaires compacts, PDF, documents, contrats |

![Les 5 variantes de mise en page intégrées de FileManagerPickerType et galerie multi-fichiers](../images/form-pickers.png)

---

## 3. Exemple complet Entité + FormType

### Entité Doctrine (`src/Entity/Product.php`)
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

### Classe de formulaire Symfony (`src/Form/ProductType.php`)
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
                'label' => 'Image de couverture du produit',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Choisir la couverture',
                'placeholder' => 'Aucune image de couverture sélectionnée…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galerie produit',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Ajouter des images',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Concevoir votre propre interface FormType (3 approches)

Vous n'êtes jamais limité aux 5 variantes intégrées ni aux 9 thèmes : vous pouvez créer des designs **100 % sur mesure en HTML / Bootstrap / Tailwind / Alpine** tout en profitant du câblage Stimulus complet.

### Approche 1 : Option `template` par champ (la plus rapide et la plus souple)

Indiquez un chemin `'template'` dans la définition du champ. Le bundle attache automatiquement le contrôleur Stimulus englobant (`liveux--file-manager-bundle--picker`) et délègue tout le balisage interne à votre fichier Twig :

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Choisir une bannière',
        'placeholder' => 'Aucune image de couverture sélectionnée pour le moment',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Ajouter à la galerie',
    ]);
```

#### Exemple A — Template personnalisé en mode unique (`templates/forms/custom_single_picker.html.twig`)
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

#### Exemple B — Template personnalisé pour galerie multi-fichiers (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Images sélectionnées : <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js génère les éléments sélectionnés sous forme de cartes réorganisables dans ce conteneur #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Référence pour les templates FormType personnalisés

| Attribut / Événement | Description |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Requis** — L'élément `<input>` envoyé au serveur lors de la soumission du formulaire. |
| `data-{{ ctrl }}-target="singlePreview"` | Conteneur dans lequel l'aperçu de l'image ou du fichier sélectionné est généré automatiquement en mode unique. |
| `data-{{ ctrl }}-target="fileLabel"` | Élément texte mis à jour avec le nom ou le chemin du fichier sélectionné. |
| `data-{{ ctrl }}-target="clearBtn"` | Bouton d'effacement ; passe automatiquement en `disabled` lorsque le champ est vide ou lorsque `isDisabled` vaut `true`. |
| `data-{{ ctrl }}-target="galleryGrid"` | Conteneur gérant les cartes de fichiers sélectionnés et leur réorganisation en mode multiple. |
| `data-{{ ctrl }}-target="countLabel"` | Compteur affichant le nombre actuel de fichiers sélectionnés en mode multiple. |
| `data-action="click->{{ ctrl }}#open"` | Ouvre la boîte de dialogue Modal / OffCanvas du gestionnaire de fichiers. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Efface la sélection courante en mode unique. |
| **Événement JS :** `liveux:file-manager:picker-changed` | Déclenché dès qu'un élément est sélectionné, effacé, retiré ou réordonné, avec `event.detail` contenant `{ values, value, multiple }`. |

**Variables Twig transmises automatiquement à votre template personnalisé :**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Approche 2 : Surcharge globale via `form_themes` de Symfony

Pour personnaliser l'apparence de **tous** les champs `FileManagerPickerType` de votre application en un seul endroit, surchargez le bloc `liveux_file_manager_picker_widget` dans votre propre thème de formulaire :

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Votre balisage HTML & CSS personnalisé global #}
{% endblock %}
```

---

### Approche 3 : Sous-classe FormType personnalisée et réutilisable

Plutôt que de répéter les mêmes options `profile`, `theme`, `template` et `mode` dans plusieurs formulaires, créez une classe FormType dédiée :

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
            'button_label' => 'Choisir la couverture produit',
            'placeholder' => 'Aucune image de couverture sélectionnée…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Utilisez-le ensuite dans n'importe quel formulaire en une seule ligne :
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. `CollectionType` de Symfony & `LiveCollectionType` de Symfony UX

![Démo en direct de CollectionType et LiveCollectionType Symfony](../images/demo-collections.gif)

`FileManagerPickerType` fonctionne nativement aussi bien avec le **`CollectionType`** classique de Symfony (ajout/suppression dynamique de lignes via `data-prototype`) qu'avec **`symfony/ux-live-component` (`LiveCollectionType`)** :

- **`CollectionType` :** Le contrôleur Stimulus `picker` se connecte automatiquement dès qu'une nouvelle ligne est insérée dans le DOM — aucune initialisation JavaScript manuelle n'est requise.
- **`LiveCollectionType` :** Même lorsque Idiomorph met à jour le DOM lors d'un ajout ou d'une suppression de ligne, `picker_controller.js` (`render:finished` + `MutationObserver`) régénère automatiquement chaque aperçu unique et chaque carte de galerie multi-fichiers à partir de la dernière valeur de `<input>`.
- **Sous-formulaire composé vs. type d'entrée scalaire direct :**
  - Avec un **sous-formulaire composé** tel que `entry_type: SlideItemType::class`, `LiveCollectionTrait::addCollectionItem()` soumet `[]` pour la nouvelle ligne et le sous-formulaire le gère sans problème — aucun code supplémentaire n'est nécessaire.
  - Avec un `entry_type: FileManagerPickerType::class` direct en mode fichier unique (`multiple: false`), l'écouteur `PRE_SUBMIT` intégré du sélecteur rejette les tableaux dans le cadre de la protection HPP. L'ajout de l'écouteur `PRE_SUBMIT` de 4 lignes sur la collection ci-dessous normalise les nouvelles lignes vides de `[]` en `''`.

### A. Exemple standard avec `CollectionType` de Symfony

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
            ->add('title', TextType::class, ['label' => 'Titre'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Image de couverture',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Pièces jointes',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Dans le FormType parent :
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
">+ Ajouter une ligne</button>
```

### B. Exemple avec `LiveCollectionType` de Symfony UX (`LiveCollectionTrait`)

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
            // 1) Sous-formulaire composé — aucun écouteur PRE_SUBMIT supplémentaire requis :
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) FileManagerPickerType direct en mode fichier unique (liste scalaire) :
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Uniquement requis pour un entry_type scalaire direct (multiple: false) :
        // LiveCollectionTrait::addCollectionItem() ajoute [] pour les nouvelles lignes, on le normalise donc en ''.
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
        // Enregistrez vos données...
    }
}
```

> [!NOTE]
> Assurez-vous que votre template `LiveCollectionType` appelle bien `{{ form_end(form) }}` (ou inclut `{{ form_row(form._token) }}` si vous passez `render_rest: false`) afin que le jeton CSRF soit soumis.

#### Template Twig du LiveComponent (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Enregistrer (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Affichez ensuite le composant n'importe où dans Twig avec une seule balise :
```twig
<twig:SliderFormComponent />
```

---

## 6. Intégrations d'éditeurs de texte riche et par blocs — 7 adaptateurs intégrés (étape par étape)

> **Aperçu rapide pour bien démarrer :**  
> Lorsque les rédacteurs rédigent des articles de blog ou des fiches produit, ils ont besoin d'insérer facilement des images ou des PDF directement dans le contenu. LiveUX FileManagerBundle embarque **7 adaptateurs JavaScript prêts à l'emploi** pour les éditeurs open source les plus répandus : **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (`TextEditorField` d'EasyAdmin)**, **Summernote** et **Editor.js**. Chaque adaptateur :
> - Ouvre le gestionnaire de fichiers dans la couche supérieure native `<dialog>` (Top Layer) du navigateur (**zéro conflit de `z-index`** ni bug de piège de focus derrière les fenêtres des éditeurs),
> - Reprend automatiquement les métadonnées SEO (`alt` / `title` / `caption`) des images sélectionnées,
> - Valide chaque URL retournée contre les vecteurs XSS `javascript:` et les traversées de chemin (`../`).

Les sept adaptateurs acceptent les mêmes options communes :  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Une seule ligne dans `importmap.php` pour les utilisateurs d'AssetMapper :**  
> Dans les projets **Webpack Encore**, `import ... from '@liveux/file-manager-bundle/js/integrations/...'` fonctionne directement.  
> Si vous utilisez **Symfony AssetMapper**, déclarez **uniquement le ou les adaptateurs que vous utilisez** dans `importmap.php` afin que la balise `<script type="importmap">` du navigateur reconnaisse le chemin d'import (ces fichiers sont déjà présents dans le bundle — rien n'est téléchargé depuis Internet) :
> ```php
> // importmap.php (ajoutez uniquement le ou les adaptateurs d'éditeur réellement utilisés)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. Intégration TinyMCE 5 / 6 / 7 / 8 (`createTinyMceFilePicker`)

![Intégration en direct de TinyMCE + LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 et 8 partagent tous exactement la même signature `file_picker_callback` et sont **validés par des tests navigateur réels**. L'adaptateur connecte directement les boutons parcourir natifs des dialogues **Image (`image`)**, **Média (`media`)** et **Lien (`link`)** de TinyMCE à LiveUX FileManagerBundle.

> **Note de version :** Conservez `license_key: 'gpl'` avec TinyMCE **7 ou 8**. Si vous utilisez TinyMCE **5 ou 6**, supprimez simplement cette ligne — tout le reste est identique.

#### Étape 1 : Chargez TinyMCE sur votre page (open source — aucune clé API requise)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (depuis un CDN ou votre propre dossier public/) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Étape 2 : Connectez `createTinyMceFilePicker` via un contrôleur Stimulus
Créez `assets/controllers/tinymce_editor_controller.js` (fonctionne à l'identique avec **AssetMapper** et **Webpack Encore**) :

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
            license_key: 'gpl', // Mode licence libre GPL open source
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Votre profil dans liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'fr',
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

#### Que se passe-t-il ?
Lorsqu'un utilisateur clique sur le bouton **Image** de TinyMCE puis sur l'icône Parcourir à côté du champ **Source** :
1. LiveUX FileManagerBundle s'ouvre au-dessus de la modale TinyMCE, pré-filtré sur les images (`filetype: 'image'`).
2. Dès que l'utilisateur choisit une image, les champs **Source (URL)** et **Description alternative (`alt`)** sont remplis automatiquement !

---

### B. Intégration CKEditor 5 (`openFileManagerForCKEditor5`)

![Intégration en direct de CKEditor 5 + LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Compatible avec les builds Classic, Inline, Balloon et Decoupled de CKEditor 5 pour insérer **une ou plusieurs** images (`insertImage`) et des liens vers des documents/PDF à l'aide d'un seul bouton.

#### Étape 1 : Ajoutez CKEditor 5 et un bouton d'insertion de média
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Insérer un média depuis le gestionnaire de fichiers
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Étape 2 : Connectez `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // Tiroir latéral pour parcourir vos fichiers sans masquer l'éditeur
            position: 'end',
            multiple: true,    // Insère plusieurs photos en une seule fois
            locale: 'fr',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### Que se passe-t-il ?
- Les **images** sont insérées nativement via la commande `insertImage` de CKEditor 5 sous forme de blocs `<figure class="image">`.
- Les **documents (PDF, ZIP, etc.)** sont insérés sous forme de liens cliquables `<a href="...">` portant le nom du fichier.

---

### C. Intégration Tiptap / ProseMirror (`insertFromFileManager`)

![Intégration en direct de Tiptap 2 + LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Si vous utilisez l'éditeur headless moderne **Tiptap 2**, l'intégration avec `@tiptap/extension-image` et `@tiptap/extension-link` se fait en un seul appel de fonction.

#### Étape 1 : Installez les paquets
- **Avec Webpack Encore / npm :**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **Avec AssetMapper :**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Étape 2 : Template Twig (barre d'outils + zone d'édition + champ caché)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>G</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Insérer un média (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Étape 3 : Créez le contrôleur Stimulus `insertFromFileManager`
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
            content: this.hiddenInputTarget.value || '<p>Commencez à rédiger votre contenu...</p>',
            onUpdate: ({ editor }) => {
                // Maintient le champ caché synchronisé avec le dernier HTML pour la soumission du formulaire
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'fr',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### Que se passe-t-il ?
- Les images sont insérées via `setImage({ src, alt, title })`, en conservant les attributs SEO `alt` et `title`.
- Les documents (PDF, ZIP, etc.) sont automatiquement insérés en tant que liens cliquables (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Intégration Quill 1.x / 2.x (`createQuillImageHandler` & `openFileManagerForQuill`)

![Intégration en direct de Quill 2 + LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Relie le bouton **Image** natif de la barre d'outils Quill à LiveUX FileManagerBundle en une ligne — avec attribution automatique du texte `alt` SEO sur les images et création de liens cliquables pour les documents.

#### Étape 1 : Incluez les fichiers CSS/JS de Quill et le conteneur de l'éditeur
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Étape 2 : Créez le contrôleur Stimulus `createQuillImageHandler`
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
                        // Cliquer sur le bouton Image natif de Quill ouvre LiveUX FileManagerBundle !
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'fr',
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

### E. Intégration de l'éditeur Trix — `TextEditorField` d'EasyAdmin & Turbo (`openFileManagerForTrix`)

![Intégration en direct de Trix + LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Insère des pièces jointes image sécurisées (`Trix.Attachment`) et des liens de documents dans `<trix-editor>` — l'éditeur riche par défaut du **`TextEditorField` intégré d'EasyAdmin** et des applications Symfony Turbo/Hotwire.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Insérer depuis le gestionnaire de fichiers</button>
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
            locale: 'fr',
        });
    }
}
```

---

### F. Intégration Summernote (`openFileManagerForSummernote`)

![Intégration en direct de Summernote + LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Insère des nœuds DOM `<img>` assainis (avec attributs SEO `alt` & `title`) et des liens `<a>` dans Summernote, très répandu dans les back-offices basés sur Bootstrap.

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
            locale: 'fr',
        });
    }
}
```

---

### G. Intégration Editor.js (éditeur JSON par blocs) (`openFileManagerForEditorJs`)

![Intégration en direct d'Editor.js + LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Insère des blocs structurés `image` (`@editorjs/image`) ou `simpleImage` (`@editorjs/simple-image`) ainsi que des liens vers des documents dans **Editor.js**, idéal pour les CMS headless et les éditeurs par blocs façon Notion.

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
            imageBlockType: 'simpleImage', // Laissez 'image' si vous utilisez @editorjs/image
            locale: 'fr',
        });
    }
}
```

---

### H. Intégration GrapesJS (constructeur visuel de pages et d'e-mails) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Intégration en direct GrapesJS + LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

S'intègre de manière transparente avec **GrapesJS (0.20+ / 0.21+ / 0.22+)** de deux façons :
1. **`createGrapesJsAssetManagerConfig()` :** Remplace la modale Asset Manager intégrée de GrapesJS par LiveUX FileManagerBundle (s'ouvre automatiquement lors d'un double-clic sur une image du canevas).
2. **`openFileManagerForGrapesJs()` :** Insère un ou plusieurs composants image/lien directement sur le canevas depuis un bouton personnalisé.

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
            // 1) Remplacer l'Asset Manager intégré par LiveUX FileManager :
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'fr',
            }),
        });
    }

    // 2) Ou insérer des images/liens directement sur le canevas depuis un bouton personnalisé :
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'fr',
        });
    }
}
```

---

### I. Intégration Meta Lexical (`openFileManagerForLexical`)

![Intégration en direct Meta Lexical + LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

S'intègre au moteur de texte riche extensible **Lexical** de Meta (`lexical` 0.15+ / 0.20+ / 0.30+) via l'envoi de commandes (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) ou un callback personnalisé `onInsert`, avec validation stricte des URL contre les failles XSS et métadonnées SEO `altText`.

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
            nodes: [/* ImageNode personnalisé */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // Insérez votre ImageNode / DecoratorNode personnalisé dans l'état Lexical
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
            locale: 'fr',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. Intégration EasyAdmin 4 / 5 — étape par étape (`FileManagerField`)

![Démo en direct d'EasyAdmin 5 + LiveUX FileManagerField](../images/demo-easyadmin.gif)

Le champ intégré `FileManagerField` ne nécessite aucune dépendance supplémentaire :
- Sur les pages **Index** et **Detail**, il affiche automatiquement les miniatures et badges des fichiers sélectionnés (avec vérification de sécurité des URL via `liveux_fm_describe_field_item()`).
- Sur les pages **New** et **Edit**, il s'affiche directement sous forme de `FileManagerPickerType`.

### Étape 1 : Chargez les assets de votre application dans le `DashboardController` d'EasyAdmin (important !)

Comme EasyAdmin utilise son propre layout autonome (il n'étend pas votre `base.html.twig`), déclarez votre point d'entrée d'assets `app` dans `DashboardController::configureAssets()` :

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
        // Si vous utilisez AssetMapper :
        return Assets::new()->addAssetMapperEntry('app');

        // Si vous utilisez Webpack Encore, remplacez la ligne ci-dessus par :
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Étape 2 : Utilisez `FileManagerField` dans votre `CrudController`

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
        yield TextField::new('title', 'Nom du produit');

        yield FileManagerField::new('coverImage', 'Image de couverture')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Choisir la couverture');

        yield FileManagerField::new('galleryImages', 'Galerie produit')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Toutes les méthodes fluides de `FileManagerField` (16 méthodes)

| Méthode | Option correspondante | Exemple |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('fr')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Changer le logo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Aucune sélection')` |

---

[← Précédent : 04 — Thèmes, icônes & personnalisation](04-themes-icons-and-customization.md) | [Index](README.md) | [Suivant : 06 — Sécurité & autorisations →](06-security-and-authorization.md)
