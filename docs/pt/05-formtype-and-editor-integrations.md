# 05 — FormType (`FileManagerPickerType`) e Integrações com Editores

[← Anterior: 04 — Temas, Ícones e Personalização](04-themes-icons-and-customization.md) | [Índice](README.md) | [Próximo: 06 — Segurança e Autorização →](06-security-and-authorization.md)

O `FileManagerPickerType` integra-se nativamente ao componente Symfony Form. Ele oferece suporte tanto para **arquivo único (`?string`)** quanto para **galerias de múltiplos arquivos (`list<string>` / JSON)**, inclui **9 temas**, **5 variantes de layout prontas para uso**, **reordenação acessível de galeria por arrastar e soltar / teclado / toque** e suporte completo a **templates Twig personalizados (`template`)**.

> **Visão Geral: O Que Este Guia Aborda?**  
> Ao criar um formulário de produto, artigo de blog ou perfil de usuário, em vez de usar um `<input type="file">` básico, o `FileManagerPickerType` fornece um campo Symfony Form pronto que **abre o gerenciador de arquivos para o usuário selecionar arquivos existentes ou enviar novos**.  
> Você também vai aprender a integrar **CollectionType / LiveCollectionType**, **7 editores WYSIWYG e de blocos populares** (TinyMCE, CKEditor 5, Tiptap, Quill 2, Trix, Summernote, Editor.js) e o **EasyAdmin 4/5** com poucas linhas de código.

> [!NOTE]
> O tema de formulário (`@LiveUXFileManager/form/picker.html.twig`) e o contêiner `DialogHost` são **registrados e injetados automaticamente** pelo bundle — você não precisa alterar `twig.yaml` nem `base.html.twig`.

---

## 1. Todas as Opções do `FileManagerPickerType`

| Opção | Tipo | Padrão | Descrição |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Perfil do gerenciador de arquivos que será aberto. |
| `locale` | `?string` | `null` | `null` herda o idioma do perfil; passe qualquer um dos 12 idiomas integrados da interface (`'en'`, `'tr'`, `'fr'`, `'de'`, `'es'`, `'ar'`, `'it'`, `'pt'`, `'nl'`, `'pl'`, `'ja'`, `'zh_CN'`) para sobrescrever por campo. |
| `volume` | `?string` | `null` | Nome do volume inicial (`null` usa o primeiro volume do perfil). |
| `multiple` | `bool` | `false` | `false` = arquivo único (`?string`), `true` = galeria de múltiplos arquivos (`list<string>`). |
| `max_files` | `?int` | `null` | Número máximo de arquivos permitidos quando `multiple: true` (`null` = ilimitado; também validado rigorosamente no servidor). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Estilo de apresentação do diálogo. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Posição da gaveta quando `mode: 'offcanvas'`. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` herda `ui.mobile_dialog_mode` do perfil; sobrescreve o layout do diálogo em telas móveis (`<= 767px`) por campo. |
| `return_type` | `'url'\|'path'` | `'url'` | Define se será salva no banco a URL completa (`/uploads/a.jpg`) ou o caminho relativo (`a.jpg`) *(veja o guia de decisão abaixo)*. |
| `preview` | `bool` | `true` | Controla as miniaturas de pré-visualização de imagens/arquivos em **todas as variantes** (único e múltiplo). Quando `false`, apenas o nome/caminho do arquivo é exibido. |
| `button_label` | `?string` | `null` | Rótulo personalizado para o botão seletor (ex.: `'Escolher Imagem'`, `'Alterar Logo'`). |
| `placeholder` | `?string` | `null` | Texto exibido quando nenhum arquivo está selecionado. |
| `theme` | `?string` | `null` | `null` herda o tema do perfil. Aceita qualquer um dos 9 temas integrados ou o nome do seu tema CSS personalizado (ex.: `'corporate'`). |
| `color_scheme` | `?string` | `null` | `null` herda o esquema de cores do perfil/padrão (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Variante de layout integrada: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Caminho para um **template Twig 100% personalizado** que substitui as variantes integradas (ex.: `'forms/custom_picker.html.twig'`). |
| `disabled` | `bool` | `false` | Opção padrão do Symfony Form: quando `true`, o campo fica totalmente somente leitura e os controles de abrir/limpar/reordenar são bloqueados. |

### Devo Escolher `return_type: 'url'` ou `'path'`?
- **`'url'` (Padrão — Mais Simples):** Salva `/uploads/products/thermos.webp` ou `https://cdn.example.com/thermos.webp` no banco de dados. No Twig, basta renderizar diretamente com `<img src="{{ product.coverImage }}">` sem lógica adicional.
- **`'path'` (Flexível / Ideal para Migração de CDN):** Salva apenas o caminho relativo `products/thermos.webp`. Se você trocar de disco de armazenamento ou domínio de CDN no futuro, não precisará migrar registros do banco — basta renderizar via `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` ou prefixar com a URL base da sua CDN.

### Segurança Integrada e Reordenação Acessível de Galeria
- **Proteção de POST no Lado do Servidor:** Mesmo que alguém adultere o `<input>` oculto no DevTools do navegador para injetar `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, JSON malformado acima de 256 KB, mais de 500 itens, UTF-8 inválido, valores excedendo `max_files` ou arrays de HTTP Parameter Pollution (HPP), o `FileManagerPickerType` **intercepta e rejeita tudo automaticamente no servidor**.
- **Reordenação Acessível da Galeria (`multiple: true`):** Os usuários podem reordenar os itens da galeria de **3 formas**:
  1. **Arrastar e Soltar (Drag & Drop)** no desktop,
  2. **Teclas de Seta `←` / `→`** quando um card está em foco (a direção inverte automaticamente em idiomas RTL como árabe),
  3. **Botões `◀` / `▶`** em cada card para dispositivos móveis e telas sensíveis ao toque.

---

## 2. 5 Variantes de Layout Integradas (`variant`)

`variant` e `theme` são totalmente independentes. Cada variante funciona nos modos **único** e **múltiplo** em todos os temas:

| Variante | Modo Único (`multiple: false`) | Modo Múltiplo (`multiple: true`) | Melhor Caso de Uso |
| :--- | :--- | :--- | :--- |
| `compact` *(padrão)* | Miniatura quadrada 60×60 + campo + linha Escolher/Limpar | Cards de galeria lado a lado + bloco tracejado `+` para adicionar | Campos de formulário em geral, galerias de produtos |
| `card` | Card de capa 16:10 + nome do arquivo + ações na base | Grade de cards | Capas de blog/artigo, banners principais, imagens em destaque |
| `dropzone` | Caixa tracejada clicável ampla + pré-visualização ao vivo | Caixa grande de adição no topo + cards da galeria abaixo | Áreas de destaque para seleção/envio de mídia e arquivos |
| `avatar` | Pré-visualização circular `82×82` de perfil/logo + botões laterais | Avatares circulares `72×72` + botão circular `+` | Fotos de perfil de usuário, logotipos de marcas |
| `input` | Grupo de input minimalista em linha única sem miniaturas | Lista de chips/etiquetas removíveis + `+ Adicionar` | Formulários compactos, PDFs, documentos, contratos |

![5 Variantes de Layout Integradas do FileManagerPickerType e Galeria de Múltiplos Arquivos](../images/form-pickers.png)

---

## 3. Exemplo Completo de Entidade + FormType

### Entidade Doctrine (`src/Entity/Product.php`)
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

### Classe Symfony Form (`src/Form/ProductType.php`)
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
                'label' => 'Imagem de Capa do Produto',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Escolher Capa',
                'placeholder' => 'Nenhuma imagem de capa selecionada…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Galeria do Produto',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Adicionar Imagens',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Criando Sua Própria Interface de FormType (3 Abordagens)

Você nunca fica preso às 5 variantes integradas ou aos 9 temas — pode criar **designs 100% personalizados com HTML / Bootstrap / Tailwind / Alpine** mantendo toda a integração com Stimulus.

### Abordagem 1: Opção `template` por Campo (Mais Rápida e Flexível)

Informe um caminho em `'template'` na definição do campo. O bundle conecta automaticamente o controlador Stimulus envolvente (`liveux--file-manager-bundle--picker`) e delega toda a marcação interna ao seu arquivo Twig:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => 'Escolher Banner',
        'placeholder' => 'Nenhuma imagem de capa selecionada ainda',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => 'Adicionar à Galeria',
    ]);
```

#### Exemplo A — Template Personalizado para Seletor Único (`templates/forms/custom_single_picker.html.twig`)
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

#### Exemplo B — Template Personalizado para Galeria de Múltiplos Arquivos (`templates/forms/custom_gallery_picker.html.twig`)
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
    <span>Imagens Selecionadas: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# O picker_controller.js renderiza os itens selecionados como cards reordenáveis dentro deste contêiner #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Referência para Templates Personalizados de FormType

| Atributo / Evento | Descrição |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Obrigatório** — O elemento `<input>` enviado ao servidor com o formulário. |
| `data-{{ ctrl }}-target="singlePreview"` | Contêiner onde a pré-visualização da imagem/arquivo selecionado é renderizada automaticamente no modo único. |
| `data-{{ ctrl }}-target="fileLabel"` | Elemento de texto atualizado com o nome/caminho do arquivo selecionado. |
| `data-{{ ctrl }}-target="clearBtn"` | Botão de limpar; definido automaticamente como `disabled` quando vazio ou quando `isDisabled` for `true`. |
| `data-{{ ctrl }}-target="galleryGrid"` | Contêiner que gerencia os cards dos arquivos selecionados e a reordenação no modo múltiplo. |
| `data-{{ ctrl }}-target="countLabel"` | Elemento contador que exibe a quantidade atual de arquivos selecionados no modo múltiplo. |
| `data-action="click->{{ ctrl }}#open"` | Abre o diálogo Modal / OffCanvas do gerenciador de arquivos. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Limpa a seleção única atual. |
| **Evento JS:** `liveux:file-manager:picker-changed` | Disparado sempre que itens são selecionados, limpos, removidos ou reordenados, com `event.detail` contendo `{ values, value, multiple }`. |

**Variáveis Twig Passadas Automaticamente para o Seu Template Personalizado:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Abordagem 2: Sobrescrita Global via `form_themes` do Symfony

Para personalizar a aparência de **todos** os campos `FileManagerPickerType` em todo o projeto em um único lugar, sobrescreva o bloco `liveux_file_manager_picker_widget` no seu próprio tema de formulário:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Sua marcação HTML e CSS personalizada globalmente #}
{% endblock %}
```

---

### Abordagem 3: Classe FormType Filha Reutilizável

Em vez de repetir as mesmas opções `profile`, `theme`, `template` e `mode` em vários formulários, crie uma subclasse FormType dedicada:

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
            'button_label' => 'Escolher Capa do Produto',
            'placeholder' => 'Nenhuma imagem de capa selecionada…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Agora utilize-o em qualquer formulário com uma única linha:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. `CollectionType` do Symfony e `LiveCollectionType` do Symfony UX

![Demonstração ao vivo de CollectionType e LiveCollectionType do Symfony](../images/demo-collections.gif)

O `FileManagerPickerType` funciona perfeitamente tanto com o **`CollectionType`** clássico do Symfony (adição/remoção dinâmica de linhas via `data-prototype`) quanto com o **`symfony/ux-live-component` (`LiveCollectionType`)**:

- **`CollectionType`:** O controlador Stimulus `picker` conecta-se automaticamente assim que uma nova linha é inserida no DOM — sem necessidade de inicialização manual em JavaScript.
- **`LiveCollectionType`:** Mesmo quando o Idiomorph atualiza o DOM durante a adição ou remoção de linhas, o `picker_controller.js` (`render:finished` + `MutationObserver`) renderiza novamente cada pré-visualização única e cada card de galeria a partir do valor mais recente do `<input>`.
- **Subformulário Composto vs. Tipo de Entrada Escalar Direto:**
  - Ao usar um **subformulário composto** como `entry_type: SlideItemType::class`, o `LiveCollectionTrait::addCollectionItem()` envia `[]` para a nova linha e o subformulário trata isso normalmente — sem código extra.
  - Ao usar diretamente um `entry_type: FileManagerPickerType::class` de arquivo único (`multiple: false`), o listener `PRE_SUBMIT` integrado do picker rejeita payloads em formato de array como parte da proteção contra HPP. Adicionar o listener `PRE_SUBMIT` de 4 linhas na coleção, conforme mostrado abaixo, normaliza linhas recém-adicionadas vazias de `[]` para `''`.

### A. Exemplo Padrão com `CollectionType` do Symfony

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
                'label' => 'Imagem de Capa',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Anexos',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Dentro do FormType pai:
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
">+ Adicionar Linha</button>
```

### B. Exemplo com `LiveCollectionType` do Symfony UX (`LiveCollectionTrait`)

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
            // 1) Subformulário composto — não precisa de listener PRE_SUBMIT extra:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) FileManagerPickerType de arquivo único direto (lista escalar):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Necessário apenas para entry_type escalar direto (multiple: false):
        // LiveCollectionTrait::addCollectionItem() adiciona [] para novas linhas, então normalizamos para ''.
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
        // Persista os seus dados...
    }
}
```

> [!NOTE]
> Certifique-se de que o seu template com `LiveCollectionType` chame `{{ form_end(form) }}` (ou inclua `{{ form_row(form._token) }}` se você passar `render_rest: false`) para que o token CSRF seja enviado corretamente.

#### Template Twig do LiveComponent (`templates/components/SliderFormComponent.html.twig`)
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
        <button type="submit">Salvar (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Depois, renderize o componente em qualquer lugar no Twig com uma única tag:
```twig
<twig:SliderFormComponent />
```

---

## 6. Integrações com Editores Rich Text e de Blocos — 7 Adaptadores Integrados (Passo a Passo)

> **Resumo Rápido para Iniciantes:**  
> Sempre que editores escrevem artigos de blog ou descrições de produtos, precisam de uma forma simples de inserir imagens ou PDFs diretamente no conteúdo. O LiveUX FileManagerBundle já vem com **7 adaptadores JavaScript prontos** para os editores open-source mais populares: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (`TextEditorField` do EasyAdmin)**, **Summernote** e **Editor.js**. Todo adaptador:
> - Abre o gerenciador de arquivos na Top Layer nativa de `<dialog>` do navegador (**zero conflitos de `z-index`** ou bugs de captura de foco atrás de popups do editor),
> - Transfere automaticamente os metadados de SEO (`alt` / `title` / `caption`) das imagens selecionadas,
> - Valida todas as URLs retornadas contra vetores XSS de `javascript:` e path traversal (`../`).

Todos os sete adaptadores aceitam as mesmas opções em comum:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **Linha Única no `importmap.php` para Usuários de AssetMapper:**  
> Em projetos com **Webpack Encore**, `import ... from '@liveux/file-manager-bundle/js/integrations/...'` funciona diretamente.  
> Se você utiliza **Symfony AssetMapper**, registre **apenas o(s) adaptador(es) que você usa** em `importmap.php` para que o `<script type="importmap">` do navegador reconheça o especificador (esses arquivos já residem dentro do bundle — nada é baixado da internet):
> ```php
> // importmap.php (adicione apenas os adaptadores de editor que você realmente usa)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. Integração com TinyMCE 5 / 6 / 7 / 8 (`createTinyMceFilePicker`)

![Integração ao vivo do TinyMCE + LiveUX FileManagerBundle](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 e 8 compartilham exatamente a mesma assinatura `file_picker_callback` e são **validados com testes reais em navegador**. O adaptador conecta os botões nativos de busca do TinyMCE dentro dos diálogos de **Imagem (`image`)**, **Mídia (`media`)** e **Link (`link`)** diretamente ao LiveUX FileManagerBundle.

> **Nota de Versão:** Mantenha `license_key: 'gpl'` ao usar o TinyMCE **7 ou 8**. Se estiver no TinyMCE **5 ou 6**, basta omitir essa linha — todo o restante é idêntico.

#### Passo 1: Carregue o TinyMCE na Sua Página (Open Source — Sem Chave de API)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (via CDN ou do seu próprio diretório public/) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Passo 2: Conecte `createTinyMceFilePicker` por Meio de um Controlador Stimulus
Crie `assets/controllers/tinymce_editor_controller.js` (funciona de forma idêntica tanto no **AssetMapper** quanto no **Webpack Encore**):

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
            license_key: 'gpl', // Modo gratuito de licença open-source GPL
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // Seu perfil em liveux_file_manager.yaml
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'pt',
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

#### O Que Acontece?
Quando o usuário clica no botão **Imagem** da barra do TinyMCE e clica no ícone de busca ao lado de **Source**:
1. O LiveUX FileManagerBundle abre acima do modal do TinyMCE, já pré-filtrado para imagens (`filetype: 'image'`).
2. Assim que o usuário seleciona uma imagem, tanto a **URL de origem (Source)** quanto a **Descrição alternativa (`alt`)** são preenchidas automaticamente!

---

### B. Integração com CKEditor 5 (`openFileManagerForCKEditor5`)

![Integração ao vivo do CKEditor 5 + LiveUX FileManagerBundle](../images/demo-editor-ckeditor5.gif)

Funciona com as builds Classic, Inline, Balloon e Decoupled do CKEditor 5 para inserir **uma ou várias** imagens (`insertImage`) e links de documentos/PDFs com um único botão.

#### Passo 1: Adicione o CKEditor 5 e um Botão para Inserir Mídia
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Inserir Mídia do Gerenciador de Arquivos
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Passo 2: Conecte `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // Gaveta lateral para navegar sem cobrir o editor
            position: 'end',
            multiple: true,    // Insere várias fotos de uma vez
            locale: 'pt',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### O Que Acontece?
- **Imagens** são inseridas nativamente via comando `insertImage` do CKEditor 5 como blocos `<figure class="image">`.
- **Documentos (PDF, ZIP, etc.)** são inseridos como links clicáveis `<a href="...">` com o nome do arquivo como texto.

---

### C. Integração com Tiptap / ProseMirror (`insertFromFileManager`)

![Integração ao vivo do Tiptap 2 + LiveUX FileManagerBundle](../images/demo-editor-tiptap.gif)

Se você usa o editor headless moderno **Tiptap 2**, ele se integra com `@tiptap/extension-image` e `@tiptap/extension-link` em uma única chamada de função.

#### Passo 1: Instale os Pacotes
- **Com Webpack Encore / npm:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **Com AssetMapper:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Passo 2: Template Twig (Barra de Ferramentas + Área do Editor + Input Oculto)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Inserir Mídia (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Passo 3: Escreva o Controlador Stimulus com `insertFromFileManager`
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
            content: this.hiddenInputTarget.value || '<p>Comece a escrever seu conteúdo...</p>',
            onUpdate: ({ editor }) => {
                // Mantém o input oculto sincronizado com o HTML mais recente para envio do formulário
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'pt',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### O Que Acontece?
- Imagens são inseridas via `setImage({ src, alt, title })`, preservando os atributos de SEO `alt` e `title`.
- Documentos (PDF, ZIP, etc.) são inseridos automaticamente como links clicáveis (`marks: [{ type: 'link', attrs: { href } }]`).

---

### D. Integração com Quill 1.x / 2.x (`createQuillImageHandler` e `openFileManagerForQuill`)

![Integração ao vivo do Quill 2 + LiveUX FileManagerBundle](../images/demo-editor-quill.gif)

Conecta o botão nativo **Imagem** da barra do Quill ao LiveUX FileManagerBundle em uma linha — aplicando atributos `alt` de SEO às imagens e inserindo links clicáveis para documentos.

#### Passo 1: Inclua o CSS/JS do Quill e o Contêiner do Editor
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Passo 2: Escreva o Controlador Stimulus com `createQuillImageHandler`
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
                        // Clicar no botão nativo de Imagem do Quill abre o LiveUX FileManagerBundle!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'pt',
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

### E. Integração com Editor Trix — `TextEditorField` do EasyAdmin e Turbo (`openFileManagerForTrix`)

![Integração ao vivo do Trix + LiveUX FileManagerBundle](../images/demo-editor-trix.gif)

Insere anexos de imagem seguros (`Trix.Attachment`) e links de documentos em `<trix-editor>` — o editor rich text padrão usado pelo **`TextEditorField` integrado do EasyAdmin** e por aplicações Symfony com Turbo/Hotwire.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Inserir do Gerenciador de Arquivos</button>
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
            locale: 'pt',
        });
    }
}
```

---

### F. Integração com Summernote (`openFileManagerForSummernote`)

![Integração ao vivo do Summernote + LiveUX FileManagerBundle](../images/demo-editor-summernote.gif)

Insere nós DOM `<img>` higienizados (com `alt` e `title` de SEO) e links `<a>` no Summernote, amplamente utilizado em painéis administrativos baseados em Bootstrap.

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
            locale: 'pt',
        });
    }
}
```

---

### G. Integração com Editor.js (Editor JSON Baseado em Blocos) (`openFileManagerForEditorJs`)

![Integração ao vivo do Editor.js + LiveUX FileManagerBundle](../images/demo-editor-editorjs.gif)

Insere blocos estruturados `image` (`@editorjs/image`) ou `simpleImage` (`@editorjs/simple-image`) e links de documentos no **Editor.js**, ideal para CMS headless e editores em blocos no estilo Notion.

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
            imageBlockType: 'simpleImage', // Deixe como 'image' se estiver usando @editorjs/image
            locale: 'pt',
        });
    }
}
```

---

### H. Integração com GrapesJS (Construtor Visual de Páginas e E-mails) (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![Integração ao vivo do GrapesJS + LiveUX FileManagerBundle](../images/demo-editor-grapesjs.gif)

Integra-se perfeitamente ao **GrapesJS (0.20+ / 0.21+ / 0.22+)** de duas formas:
1. **`createGrapesJsAssetManagerConfig()`:** Substitui o modal nativo de Asset Manager do GrapesJS pelo LiveUX FileManagerBundle (abre automaticamente ao dar duplo clique em qualquer imagem no canvas).
2. **`openFileManagerForGrapesJs()`:** Insere um ou vários componentes de imagem/link diretamente no canvas a partir de um botão personalizado.

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
            // 1) Substitua o Asset Manager nativo pelo LiveUX FileManager:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'pt',
            }),
        });
    }

    // 2) Ou insira imagens/links diretamente no canvas a partir de um botão personalizado:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'pt',
        });
    }
}
```

---

### I. Integração com Meta Lexical (`openFileManagerForLexical`)

![Integração ao vivo do Meta Lexical + LiveUX FileManagerBundle](../images/demo-editor-lexical.gif)

Integra-se ao motor extensível de texto rico **Lexical** da Meta (`lexical` 0.15+ / 0.20+ / 0.30+) via disparo de comandos (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) ou por meio de um callback `onInsert` personalizado, com validação rigorosa de URLs contra XSS e metadados de SEO `altText`.

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
                // Insira seu ImageNode / DecoratorNode personalizado no estado do Lexical
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
            locale: 'pt',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. Integração com EasyAdmin 4 / 5 — Passo a Passo (`FileManagerField`)

![Demonstração ao vivo do EasyAdmin 5 + LiveUX FileManagerField](../images/demo-easyadmin.gif)

O `FileManagerField` integrado não exige dependências extras:
- Nas páginas **Index** e **Detail**, ele renderiza automaticamente miniaturas e badges para os arquivos selecionados (com validação de segurança de URL garantida por `liveux_fm_describe_field_item()`).
- Nas páginas **New** e **Edit**, ele renderiza diretamente como `FileManagerPickerType`.

### Passo 1: Carregue os Assets da Sua Aplicação no `DashboardController` do EasyAdmin (Importante!)

Como o EasyAdmin usa seu próprio layout base independente (ele não estende o seu `base.html.twig`), registre o entrypoint de assets `app` em `DashboardController::configureAssets()`:

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
        // Se estiver usando AssetMapper:
        return Assets::new()->addAssetMapperEntry('app');

        // Se estiver usando Webpack Encore, substitua a linha acima por:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Passo 2: Use `FileManagerField` no Seu `CrudController`

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
        yield TextField::new('title', 'Nome do Produto');

        yield FileManagerField::new('coverImage', 'Imagem de Capa')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Escolher Capa');

        yield FileManagerField::new('galleryImages', 'Galeria do Produto')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Todos os Métodos Fluentes do `FileManagerField` (16 Métodos)

| Método | Opção Correspondente | Exemplo |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('pt')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Alterar Logo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Ainda não selecionado')` |

---

[← Anterior: 04 — Temas, Ícones e Personalização](04-themes-icons-and-customization.md) | [Índice](README.md) | [Próximo: 06 — Segurança e Autorização →](06-security-and-authorization.md)
