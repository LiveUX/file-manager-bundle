# 05 — FormType (`FileManagerPickerType`) ve Editör Entegrasyonu

[← Önceki: 04 — Temalar & Özelleştirme](04-temalar-ikon-setleri-ve-ozellestirme.md) | [Ana İndeks](README.md) | [Sonraki: 06 — Güvenlik ve Yetkilendirme →](06-guvenlik-ve-yetkilendirme.md)

`FileManagerPickerType`, Symfony Form sistemine tam entegre çalışan; hem **tekli dosya (`?string`)** hem **çoklu galeri (`list<string>` / JSON)** destekleyen, **9 tema**, **5 hazır görünüm**, **erişilebilir sürükle-bırak/klavye/dokunmatik galeri sıralama** ve **özel şablon (`template`)** seçeneklerine sahip bir form alanıdır.

> **En Basit Haliyle: Bu Bölüm Ne İşe Yarar?**  
> Bir ürün, blog yazısı veya kullanıcı profili formunda kapak fotoğrafı ya da çoklu fotoğraf galerisi almak istediğinizde, standart `<input type="file">` yerine **dosya yöneticisini açıp mevcut veya yeni yüklenen görsellerden seçim yaptıran** hazır bir Symfony Form alanı (`FileManagerPickerType`) eklemenizi sağlar.  
> Ayrıca **TinyMCE, CKEditor 5, Tiptap** ve **EasyAdmin 4/5** ile tek fonksiyonla bağlanır.

> Form teması (`@LiveUXFileManager/form/picker.html.twig`) ve `DialogHost` taşıyıcısı bundle tarafından **otomatik kaydedilir / enjekte edilir**; `twig.yaml` veya `base.html.twig` dosyasında ekstra ayar yapmanız gerekmez.

---

## 1. Tüm `FileManagerPickerType` Seçenekleri

| Seçenek | Tip | Varsayılan | Ne İşe Yarar? |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Kullanılacak dosya yöneticisi profili. |
| `locale` | `?string` | `null` | `null` = profil dilini miras alır; `'tr'`, `'en'`, `'fr'` vb. ile alan bazlı dil geçersiz kılma. |
| `volume` | `?string` | `null` | Başlangıç volume adı (`null` = profilin ilk volume'ü). |
| `multiple` | `bool` | `false` | `false` = tekli (`?string`), `true` = çoklu galeri (`list<string>`). |
| `max_files` | `?int` | `null` | `multiple: true` iken en fazla kaç dosya seçilebileceği (`null` = sınırsız; sunucuda da zorunlu doğrulanır). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Pencere açılış biçimi. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | `mode: 'offcanvas'` iken çekmece yönü. |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` = profilin `ui.mobile_dialog_mode` ayarını miras alır; mobilde (`<= 767px`) pencere düzenini alan bazlı geçersiz kılar. |
| `return_type` | `'url'\|'path'` | `'url'` | Veritabanına tam URL mi (`/uploads/a.jpg`) yoksa göreli yol mu (`a.jpg`) kaydedileceği *(aşağıdaki karar rehberine bakın)*. |
| `preview` | `bool` | `true` | **Tüm varyantlarda** (tekli/çoklu) görsel önizleme kutularının gösterilip gösterilmeyeceği. `false` iken yalnızca dosya adı/yolu gösterilir. |
| `button_label` | `?string` | `null` | Seçim butonu metni (ör. `'Görsel Seç'`, `'Logo Değiştir'`). |
| `placeholder` | `?string` | `null` | Dosya seçilmediğinde görünen metin. |
| `theme` | `?string` | `null` | `null` = profil temasını miras alır. 9 hazır tema veya kendi özel CSS tema adınız (`'kurumsal'`). |
| `color_scheme` | `?string` | `null` | `null` = profil/varsayılan renk şemasını miras alır (`'auto'`, `'light'`, `'dark'`). |
| `variant` | `string` | `'compact'` | Yerleşim görünümü: `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'`. |
| `template` | `?string` | `null` | Hazır görünümlerden bağımsız, **%100 kendi Twig şablonunuzu** bağlamak için yol (ör. `'forms/ozel_secici.html.twig'`). |
| `disabled` | `bool` | `false` | Standart Symfony Form seçeneği: `true` yapıldığında seçici tamamen salt okunur olur, açma/temizleme/sıralama butonları kilitlenir. |

### `return_type: 'url'` mı, `'path'` mi Seçmeliyim?
- **`'url'` (Varsayılan — En Kolay):** Veritabanına `/uploads/urunler/termos.webp` veya `https://cdn.site.com/termos.webp` yazar. Twig'de doğrudan `<img src="{{ product.coverImage }}">` yazarak ekstra işlem yapmadan gösterirsiniz.
- **`'path'` (Esnek / CDN Değişikliğine Dayanıklı):** Veritabanına yalnızca `urunler/termos.webp` yazar. Yarın disk veya CDN adresiniz değişirse veritabanını güncellemeden Twig'de `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` veya kendi CDN önekinizle gösterebilirsiniz.

### Yerleşik Güvenlik & Erişilebilir Galeri Sıralaması
- **Sunucu Tarafı POST Koruması:** Kullanıcı tarayıcı DevTools'tan gizli `<input>` değerini değiştirip `javascript:alert(1)`, `../../etc/passwd`, `.trash/...`, 256 KB üstü bozuk JSON, 500'den fazla öğe, geçersiz UTF-8, `max_files` aşımı veya HTTP Parameter Pollution (HPP) dizisi gönderse bile `FileManagerPickerType` bunu **sunucu tarafında otomatik yakalayıp reddeder**.
- **Herkes İçin Erişilebilir Galeri Sıralaması (`multiple: true`):** Çoklu galeride kullanıcılar görsellerin sırasını **3 farklı yolla** değiştirebilir:
  1. Masaüstünde **Sürükle-Bırak (Drag & Drop)** ile,
  2. Klavyede kart odaktayken **`←` / `→` ok tuşlarıyla** (Arapça/RTL dillerde yön otomatik terslenir!),
  3. Mobil ve dokunmatik ekranlarda her kart üzerindeki **`◀` / `▶` butonlarıyla**.

---

## 2. 5 Hazır Görünüm Varyantı (`variant`)

`variant` ile `theme` birbirinden tamamen bağımsızdır. Her varyant hem **tekli** hem **çoklu** modda ve tüm temalarda çalışır:

| Varyant | Tekli Mod (`multiple: false`) | Çoklu Mod (`multiple: true`) | En İyi Kullanım Alanı |
| :--- | :--- | :--- | :--- |
| `compact` *(varsayılan)* | 60×60 kare önizleme + input + Seç/Temizle satırı | Yan yana galeri kartları + kesikli `+` ekleme kutusu | Genel form alanları, ürün galerisi |
| `card` | 16:10 kapak önizleme kartı + dosya adı + alt butonlar | Kart ızgarası | Blog/haber kapağı, banner, ana görsel |
| `dropzone` | Kesikli çerçeveli büyük tıklanabilir kutu + canlı önizleme | Üstte büyük ekleme kutusu + altta galeri kartları | Belirgin görsel/dosya yükleme alanları |
| `avatar` | Yuvarlak `82×82` profil/logo önizleme + yanda butonlar | Yuvarlak `72×72` avatar chipleri + yuvarlak `+` | Kullanıcı profil fotoğrafı, marka logosu |
| `input` | Önizlemesiz minimal tek satır input grubu | Kaldırılabilir etiket (chip) listesi + `+ Ekle` | Dar formlar, PDF / belge / sözleşme seçimi |

![FileManagerPickerType 5 Hazır Görünüm Varyantı ve Çoklu Galeri](../images/form-pickers.png)

---

## 3. Tam Çalışan Entity + FormType Örneği

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

### Symfony Form Sınıfı (`src/Form/ProductType.php`)
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
                'label' => 'Ürün Kapak Görseli',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => 'Kapak Seç',
                'placeholder' => 'Kapak görseli seçilmedi…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => 'Ürün Galerisi',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => 'Görsel Ekle',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. Geliştiricilerin Kendi Özel FormType Tasarımlarını Oluşturması (3 Yöntem)

Hazır 5 varyanta veya 9 temaya bağlı kalmadan **%100 kendi HTML / Bootstrap / Tailwind / Alpine tasarımınızı** oluşturabilirsiniz.

### Yöntem 1: Alan Bazlı `template` Seçeneği (En Hızlı & Esnek)

Form tanımında `'template'` yolunu verin; bundle sarmalayıcı Stimulus controller'ı (`liveux--file-manager-bundle--picker`) otomatik bağlar, iç HTML'i tamamen sizin şablonunuza bırakır:

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/ozel_tekli_picker.html.twig',
        'button_label' => 'Banner Seç',
        'placeholder' => 'Henüz kapak görseli seçilmedi',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/ozel_galeri_picker.html.twig',
        'button_label' => 'Galeriye Görsel Ekle',
    ]);
```

#### Örnek A — Özel Tekli Seçici Şablonu (`templates/forms/ozel_tekli_picker.html.twig`)
```twig
{# templates/forms/ozel_tekli_picker.html.twig #}
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

#### Örnek B — Özel Çoklu Galeri Şablonu (`templates/forms/ozel_galeri_picker.html.twig`)
```twig
{# templates/forms/ozel_galeri_picker.html.twig #}
<input type="hidden"
       id="{{ id }}"
       name="{{ full_name }}"
       value="{{ value|default('[]') }}"
       data-{{ ctrl }}-target="input"
       {{ isDisabled ? 'disabled' : '' }}
       {% for k, v in attr %}{% if not (k|lower starts with 'on') and v is not same as(false) %}{{ k|e('html_attr') }}{% if v is not same as(true) %}="{{ v }}"{% endif %} {% endif %}{% endfor %} />

<div class="my-gallery-header">
    <span>Seçili Görsel: <strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js seçilen görselleri sıralanabilir kartlar olarak buraya çizer #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### Özel FormType Şablonu Referans Tablosu

| Öznitelik / Olay | Açıklama |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **Zorunlu** — Form gönderildiğinde sunucuya gidecek `<input>` alanı. |
| `data-{{ ctrl }}-target="singlePreview"` | Tekli modda seçilen görselin/dosyanın otomatik çizileceği alan. |
| `data-{{ ctrl }}-target="fileLabel"` | Seçilen dosya adının yazılacağı metin etiketi. |
| `data-{{ ctrl }}-target="clearBtn"` | Boşken veya `isDisabled` iken otomatik `disabled` olan temizleme butonu. |
| `data-{{ ctrl }}-target="galleryGrid"` | Çoklu modda seçili dosya kartlarının + sıralamanın yönetildiği kap. |
| `data-{{ ctrl }}-target="countLabel"` | Çoklu modda toplam dosya sayısının yazıldığı sayaç. |
| `data-action="click->{{ ctrl }}#open"` | Dosya yöneticisi Modal / OffCanvas penceresini açar. |
| `data-action="click->{{ ctrl }}#clearSingle"` | Tekli seçimi temizler. |
| **JS Event:** `liveux:file-manager:picker-changed` | Seçim, temizleme, kaldırma veya sıralama değiştiğinde `event.detail` (`{ values, value, multiple }`) ile tetiklenir. |

**Şablonunuza Otomatik Aktarılan Tüm Twig Değişkenleri:**  
`id`, `full_name`, `value`, `attr`, `ctrl`, `isDisabled`, `btnLabel`, `emptyPlaceholder`, `clearLabel`, `removeLabel`, `profile`, `volume`, `multiple`, `max_files`, `mode`, `position`, `mobile_mode`, `return_type`, `preview`, `button_label`, `placeholder`, `theme`, `resolvedTheme`, `color_scheme`, `variant`, `resolvedVariant`, `locale`, `fmLocale`, `bcp47_locale`, `is_rtl`, `thumb_url`, `preview_url_prefix`.

---

### Yöntem 2: Proje Geneli Symfony `form_themes` Override

Projedeki **tüm** `FileManagerPickerType` alanlarının görünümünü tek merkezden değiştirmek için kendi form temanızda `liveux_file_manager_picker_widget` bloğunu ezin:

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# Kendi proje geneli HTML & CSS tasarımınız #}
{% endblock %}
```

---

### Yöntem 3: Kendi Yeniden Kullanılabilir Alt FormType Sınıfınız

Tekrar tekrar aynı `profile`, `theme`, `template`, `mode` ayarlarını yazmak yerine kendi projenize özel bir FormType türetebilirsiniz:

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
            'profile' => 'urun_katalog',
            'theme' => 'kurumsal',
            'variant' => 'card',
            'mode' => 'offcanvas',
            'position' => 'end',
            'template' => 'forms/ozel_tekli_picker.html.twig',
            'button_label' => 'Ürün Kapağı Seç',
            'placeholder' => 'Kapak görseli seçilmedi…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

Artık her formda tek satırla kullanabilirsiniz:
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` ve Symfony UX `LiveCollectionType` Kullanımı

![Symfony CollectionType ve LiveCollectionType Canlı Demo](../images/demo-collections.gif)

`FileManagerPickerType`, hem klasik Symfony **`CollectionType`** (dinamik `data-prototype` satır ekleme/silme) hem de **`symfony/ux-live-component` (`LiveCollectionType`)** ile tam uyumlu çalışır:

- **`CollectionType`:** Yeni satır DOM'a eklendiği anda Stimulus `picker` controller'ı otomatik bağlanır; ekstra JS başlatma kodu gerekmez.
- **`LiveCollectionType`:** Satır ekleme/silme sırasında Idiomorph DOM'u yeniden çizse bile `picker_controller.js` (`render:finished` + `MutationObserver`) tüm tekli önizlemeleri ve çoklu galeri kartlarını güncel `<input>` değerinden otomatik yeniden çizer.
- **Compound vs Doğrudan Scalar Farkı:**
  - `entry_type: SlideItemType::class` gibi bir **alt form (compound)** kullandığınızda `LiveCollectionTrait::addCollectionItem()` yeni satıra `[]` gönderir ve alt form bunu kendisi sorunsuz işler — ek kod gerekmez.
  - Doğrudan tekli (`multiple: false`) `entry_type: FileManagerPickerType::class` kullandığınızda ise `FileManagerPickerType`'ın dahili `PRE_SUBMIT` katmanı güvenlik (HPP koruması) nedeniyle dizi değerini reddeder; bu yüzden aşağıdaki 4 satırlık koleksiyon `PRE_SUBMIT` dinleyicisi yeni boş satırları `[]` → `''` olarak dönüştürür.

### A. Standart Symfony `CollectionType` Örneği

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
            ->add('title', TextType::class, ['label' => 'Başlık'])
            ->add('image', FileManagerPickerType::class, [
                'label' => 'Kapak Görseli',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => 'Ek Dosyalar',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// Ana Form içinde:
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
">+ Satır Ekle</button>
```

### B. Symfony UX `LiveCollectionType` Örneği (`LiveCollectionTrait`)

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
            // 1) Alt form (compound) ile — ek PRE_SUBMIT kodu gerekmez:
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) Doğrudan tekli FileManagerPickerType ile (scalar liste):
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // Yalnızca doğrudan scalar (multiple: false) entry_type için:
        // LiveCollectionTrait::addCollectionItem() yeni satırı [] olarak eklediği için '' olarak normalleştirir.
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
        // Kaydetme işleminiz...
    }
}
```

> **Not:** `LiveCollectionType` şablonunda `{{ form_end(form) }}` kullanın (veya `render_rest: false` kullanıyorsanız CSRF token'ı `{{ form_row(form._token) }}` ile ekleyin).

#### LiveComponent Twig Şablonu (`templates/components/SliderFormComponent.html.twig`)
```twig
<div {{ attributes }}>
    {{ form_start(form, { attr: { 'data-action': 'live#action:prevent', 'data-live-action-param': 'save' } }) }}
        {% for slideForm in form.slides %}
            <div class="slide-card">
                {{ form_row(slideForm.title) }}
                {{ form_row(slideForm.image) }}
                {{ form_row(slideForm.attachments) }}
                {{ form_widget(slideForm.vars.button_delete) }}
            </div>
        {% endfor %}

        {{ form_widget(form.slides.vars.button_add) }}
        <button type="submit">Kaydet (AJAX)</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

Ve herhangi bir Twig sayfanızda bu bileşeni tek satırla çağırın:
```twig
<twig:SliderFormComponent />
```

---

## 6. Zengin Metin & Blok Editörü Entegrasyonları — 7 Hazır Adaptör (Sıfırdan Adım Adım)

> **Hiç Bilmeyenler İçin Kısa Özet:**  
> Blog yazısı veya ürün açıklaması yazarken metnin ortasına resim/PDF eklemek istersiniz. LiveUX FileManagerBundle'ın içinde **7 popüler açık kaynak editör** için hazır JavaScript köprüsü (**adapter**) gelir: **TinyMCE (5/6/7/8)**, **CKEditor 5**, **Tiptap 2**, **Quill 2**, **Trix (EasyAdmin `TextEditorField`)**, **Summernote** ve **Editor.js**. Bu köprüler:
> - Dosya yöneticisini native `<dialog>` Top Layer üzerinde açar (editörün kendi popup'larının arkasında kalma / `z-index` veya odak tuzağı sorunu **asla** yaşanmaz),
> - Seçilen görselin `alt` / `title` / `caption` SEO bilgisini otomatik taşır,
> - Dönen tüm URL'leri `javascript:` / yol atlama (`../`) XSS saldırılarına karşı otomatik doğrular.

Yedi adaptörün tamamı şu ortak seçenekleri kabul eder:  
`profile`, `volume`, `path`, `mode` (`'modal'`|`'offcanvas'`), `position` (`'start'`|`'end'`|`'top'`|`'bottom'`), `mobileMode` (`'auto'`|`'fullscreen'`|`'sheet'`), `locale`, `theme`, `iconSet`, `multiple`, `type`.

> [!IMPORTANT]
> **AssetMapper Kullanıcıları İçin 1 Satırlık `importmap.php` Kaydı:**  
> **Webpack Encore** projelerinde `import ... from '@liveux/file-manager-bundle/js/integrations/...'` doğrudan çalışır.  
> **Symfony AssetMapper** kullanıyorsanız, tarayıcı `<script type="importmap">` tablosunun bu kısa yolu tanıması için **yalnızca kullandığınız editörün** satırını `importmap.php` dosyanıza ekleyin (dosyalar zaten bundle içindedir, internetten indirme yapmaz):
> ```php
> // importmap.php (yalnızca kullandığınız editörün satırını ekleyin)
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. TinyMCE 5 / 6 / 7 / 8 Entegrasyonu (`createTinyMceFilePicker`)

![TinyMCE + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-tinymce.gif)

TinyMCE 5, 6, 7 ve 8 sürümlerinin tamamında `file_picker_callback` imzası birebir aynıdır ve **canlı tarayıcı testleriyle doğrulanmıştır**. TinyMCE'nin kendi araç çubuğundaki **Görsel (`image`)**, **Video (`media`)** ve **Bağlantı (`link`)** pencerelerindeki gözat butonunu doğrudan LiveUX FileManagerBundle'a bağlar.

> **Sürüm Notu:** TinyMCE **7 ve 8** kullanıyorsanız `license_key: 'gpl'` satırını koruyun; TinyMCE **5 veya 6** kullanıyorsanız bu satırı silebilirsiniz (diğer tüm kod birebir aynıdır).

#### Adım 1: TinyMCE'yi Sayfanıza Dahil Edin (Açık Kaynak — API Anahtarı Gerekmez)
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7 (CDN veya kendi public/ klasörünüzden) #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Adım 2: Stimulus Controller ile `createTinyMceFilePicker` Köprüsünü Bağlayın
Hem **AssetMapper** hem **Webpack Encore** projelerinde `assets/controllers/tinymce_editor_controller.js` dosyasını oluşturun:

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
            license_key: 'gpl', // Açık kaynak ücretsiz lisans modu
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // liveux_file_manager.yaml profiliniz
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'tr',
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

#### Ne Olur?
Kullanıcı TinyMCE'de **Görsel** butonuna basıp **Kaynak (Source)** yanındaki gözat simgesine tıkladığında:
1. LiveUX FileManagerBundle yalnızca görselleri filtreleyerek (`filetype: 'image'`) TinyMCE modalının üstünde açılır.
2. Kullanıcı bir fotoğraf seçtiğinde **Kaynak URL** ve **Alternatif açıklama (`alt`)** alanları otomatik dolar!

---

### B. CKEditor 5 Entegrasyonu (`openFileManagerForCKEditor5`)

![CKEditor 5 + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-ckeditor5.gif)

CKEditor 5 Classic/Inline/Balloon/Decoupled sürümlerinde tek bir butonla **tekli veya çoklu** görsel (`insertImage`) ve belge/PDF bağlantısı eklemenizi sağlar.

#### Adım 1: CKEditor 5 Alanı + Medya Ekle Butonunu Ekleyin
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                Dosya Yöneticisinden Medya Ekle
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### Adım 2: `openFileManagerForCKEditor5` Köprüsünü Bağlayın
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
            mode: 'offcanvas', // Sağdan kayan çekmece ile editörü kapatmadan seçim yap!
            position: 'end',
            multiple: true,    // Birden fazla fotoğrafı tek seferde ekle
            locale: 'tr',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### Ne Olur?
- Seçilen öğeler **görselse** CKEditor 5'in `insertImage` komutuyla doğrudan `<figure class="image">` bloğu olarak eklenir.
- Seçilen öğeler **PDF / ZIP / belge** ise dosya adıyla tıklanabilir `<a href="...">` bağlantısı olarak eklenir.

---

### C. Tiptap / ProseMirror Entegrasyonu (`insertFromFileManager`)

![Tiptap 2 + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-tiptap.gif)

Modern headless editör **Tiptap 2** kullanıyorsanız `@tiptap/extension-image` ve `@tiptap/extension-link` eklentileriyle tek fonksiyon çağrısında entegre olur.

#### Adım 1: Paketleri Kurun
- **Webpack Encore / npm kullanıyorsanız:**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **AssetMapper kullanıyorsanız:**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### Adım 2: Twig Şablonu (Araç Çubuğu + Editör + Gizli Form Alanı)
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 Medya Ekle (LiveUX)</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Adım 3: `insertFromFileManager` Stimulus Controller'ını Yazın
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
            content: this.hiddenInputTarget.value || '<p>İçeriğinizi yazın...</p>',
            onUpdate: ({ editor }) => {
                // Form gönderildiğinde güncel HTML'i backend'e ilet
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'tr',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### Ne Olur?
- Görseller `setImage({ src, alt, title })` ile SEO `alt` ve `title` verileri korunarak eklenir.
- Belgeler (PDF, ZIP vb.) otomatik olarak tıklanabilir link (`marks: [{ type: 'link', attrs: { href } }]`) olarak metne eklenir.

---

### D. Quill 1.x / 2.x Entegrasyonu (`createQuillImageHandler` & `openFileManagerForQuill`)

![Quill 2 + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-quill.gif)

Quill'in kendi araç çubuğundaki **Resim** ikonunu tek satırla LiveUX FileManagerBundle'a yönlendirir; görsellere SEO `alt` niteliğini uygular, belge seçilirse tıklanabilir link ekler.

#### Adım 1: Quill CSS/JS ve Editör Alanını Ekleyin
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### Adım 2: `createQuillImageHandler` Stimulus Controller'ını Yazın
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
                        // Quill'in kendi Resim butonuna tıklandığında LiveUX açılır!
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'tr',
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

### E. Trix Editor Entegrasyonu — EasyAdmin `TextEditorField` & Turbo (`openFileManagerForTrix`)

![Trix + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-trix.gif)

**EasyAdmin'in yerleşik `TextEditorField` alanı** ve Symfony Turbo/Hotwire projelerinin varsayılan editörü olan `<trix-editor>` içine güvenli görsel eklentileri (`Trix.Attachment`) ve belge bağlantıları ekler.

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">Dosya Yöneticisinden Ekle</button>
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
            locale: 'tr',
        });
    }
}
```

---

### F. Summernote Entegrasyonu (`openFileManagerForSummernote`)

![Summernote + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-summernote.gif)

Bootstrap tabanlı admin panellerinde sık kullanılan Summernote içine güvenli DOM `<img>` (SEO `alt` & `title` ile) ve `<a>` düğümleri ekler.

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
            locale: 'tr',
        });
    }
}
```

---

### G. Editor.js (Blok Tabanlı JSON Editör) Entegrasyonu (`openFileManagerForEditorJs`)

![Editor.js + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-editorjs.gif)

Headless CMS ve Notion tarzı blok editörü **Editor.js** içine yapısal `image` (`@editorjs/image`) veya `simpleImage` (`@editorjs/simple-image`) blokları ve belge bağlantıları ekler.

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
            imageBlockType: 'simpleImage', // @editorjs/image kullanıyorsanız 'image' bırakın
            locale: 'tr',
        });
    }
}
```

---

### H. GrapesJS (Görsel Sayfa & E-Posta Oluşturucu) Entegrasyonu (`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`)

![GrapesJS + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-grapesjs.gif)

Görsel sürükle-bırak sayfa oluşturucu **GrapesJS (0.20+ / 0.21+ / 0.22+)** ile iki şekilde entegre olur:
1. **`createGrapesJsAssetManagerConfig()`:** GrapesJS'in yerleşik Asset Manager penceresini tamamen LiveUX FileManagerBundle ile değiştirir (tuvaldeki bir görsele çift tıklayınca otomatik açılır).
2. **`openFileManagerForGrapesJs()`:** Özel bir araç çubuğu butonundan tekli/çoklu görsel veya dosya bileşeni ekler.

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
            // 1) Yerleşik Asset Manager'ı LiveUX FileManager ile değiştirin:
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'tr',
            }),
        });
    }

    // 2) Veya özel butonla tuvale doğrudan görsel/link ekleyin:
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'tr',
        });
    }
}
```

---

### I. Meta Lexical Entegrasyonu (`openFileManagerForLexical`)

![Meta Lexical + LiveUX FileManagerBundle Canlı Entegrasyon](../images/demo-editor-lexical.gif)

Meta tarafından geliştirilen yüksek performanslı **Lexical** editörüne (`lexical` 0.15+ / 0.20+ / 0.30+) komut tabanlı (`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`) veya özel `onInsert` callback'i ile XSS korumalı görsel/bağlantı ekler.

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
                // Kendi ImageNode / DecoratorNode düğümünüzü Lexical state içine ekleyin
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
            locale: 'tr',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. EasyAdmin 4 / 5 Entegrasyonu — Sıfırdan Adım Adım (`FileManagerField`)

![EasyAdmin 5 + LiveUX FileManagerField Canlı Demo](../images/demo-easyadmin.gif)

Sıfır zorunlu bağımlılıkla çalışan yerleşik `FileManagerField`:
- **Index** (liste) ve **Detail** (detay) sayfalarında seçili görselleri otomatik küçük resim/rozet olarak gösterir (URL güvenliği `liveux_fm_describe_field_item()` ile doğrulanır).
- **New** ve **Edit** sayfalarında ise doğrudan `FileManagerPickerType` olarak çalışır.

### Adım 1: EasyAdmin `DashboardController` İçinde Uygulama Asset'ini Yükleyin (Çok Önemli!)

EasyAdmin kendi bağımsız HTML şablonunu kullandığı için (`base.html.twig` şablonunuzu miras almaz), `DashboardController::configureAssets()` içinde `app` giriş noktanızı EasyAdmin'e tanıtmanız gerekir:

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
        // AssetMapper kullanıyorsanız:
        return Assets::new()->addAssetMapperEntry('app');

        // Webpack Encore kullanıyorsanız yukarıdakini silip bunu yazın:
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### Adım 2: `CrudController` İçinde `FileManagerField` Kullanın

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
        yield TextField::new('title', 'Ürün Adı');

        yield FileManagerField::new('coverImage', 'Kapak Görseli')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('Kapak Seç');

        yield FileManagerField::new('galleryImages', 'Ürün Galerisi')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### Tüm `FileManagerField` Akıcı (Fluent) Metotları (16 Metot)

| Metot | Karşılık Geldiği Seçenek | Örnek |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('tr')` |
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
| `setCustomTemplate(?string $template)` | `template` | `->setCustomTemplate('forms/ozel.html.twig')` |
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('Logo Değiştir')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('Henüz seçilmedi')` |

---

[← Önceki: 04 — Temalar & Özelleştirme](04-temalar-ikon-setleri-ve-ozellestirme.md) | [Ana İndeks](README.md) | [Sonraki: 06 — Güvenlik ve Yetkilendirme →](06-guvenlik-ve-yetkilendirme.md)
