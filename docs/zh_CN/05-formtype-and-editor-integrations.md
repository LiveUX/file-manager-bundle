# 05 — FormType（`FileManagerPickerType`）与编辑器集成

[← 上一篇：04 — 主题、图标与深度定制](04-themes-icons-and-customization.md) | [目录](README.md) | [下一篇：06 — 安全与权限控制 →](06-security-and-authorization.md)

`FileManagerPickerType` 与 Symfony Form 组件原生无缝集成。它同时支持**单文件（`?string`）**与**多文件图库（`list<string>` / JSON）**，内置 **9 套主题**、**5 种开箱即用的布局变体**、**无障碍拖拽 / 键盘 / 触屏图库排序**，以及完整的**自定义 Twig 模板（`template`）**支持。

> **本指南内容概览**  
> 在构建商品、博客文章或用户资料表单时，无需再使用简陋的 `<input type="file">`，`FileManagerPickerType` 直接为你提供现成的 Symfony 表单字段，让用户可以**打开文件管理器挑选已有资产或上传新文件**。  
> 此外，你还将学到如何仅用几行代码快速集成 **CollectionType / LiveCollectionType**、**7 款主流所见即所得与块级编辑器**（TinyMCE、CKEditor 5、Tiptap、Quill 2、Trix、Summernote、Editor.js）以及 **EasyAdmin 4/5**。

> [!NOTE]
> 表单主题（`@LiveUXFileManager/form/picker.html.twig`）与 `DialogHost` 容器均由 Bundle **自动注册并注入** —— 你无需手动修改 `twig.yaml` 或 `base.html.twig`。

---

## 1. `FileManagerPickerType` 全部选项

| 选项 | 类型 | 默认值 | 说明 |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | 要打开的文件管理器配置档（Profile）。 |
| `locale` | `?string` | `null` | `null` 表示继承配置档语言；可传入 12 种内置界面语言中的任意一种（`'en'`、`'tr'`、`'fr'`、`'de'`、`'es'`、`'ar'`、`'it'`、`'pt'`、`'nl'`、`'pl'`、`'ja'`、`'zh_CN'`）按字段单独覆盖。 |
| `volume` | `?string` | `null` | 初始存储卷名称（`null` 时使用配置档中的第一个存储卷）。 |
| `multiple` | `bool` | `false` | `false` = 单文件（`?string`），`true` = 多文件图库（`list<string>`）。 |
| `max_files` | `?int` | `null` | `multiple: true` 时允许选择的最大文件数量（`null` 表示不限；同时在服务端严格校验）。 |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | 弹窗展示模式（模态框或侧边抽屉）。 |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | 当 `mode: 'offcanvas'` 时抽屉滑出的方位。 |
| `mobile_mode` | `null\|'auto'\|'fullscreen'\|'sheet'` | `null` | `null` 继承配置档的 `ui.mobile_dialog_mode`；按字段覆盖移动端（`<= 767px`）弹窗布局。 |
| `return_type` | `'url'\|'path'` | `'url'` | 决定在数据库中存储完整 URL（`/uploads/a.jpg`）还是相对路径（`a.jpg`）*（详见下方选型指南）*。 |
| `preview` | `bool` | `true` | 控制**所有布局变体**（单选与多选）中的图片/文件预览缩略图显示。设为 `false` 时仅展示文件名/路径。 |
| `button_label` | `?string` | `null` | 选择器按钮的自定义文案（例如 `'选择图片'`、`'更换 Logo'`）。 |
| `placeholder` | `?string` | `null` | 未选择文件时显示的占位提示文本。 |
| `theme` | `?string` | `null` | `null` 继承配置档主题。支持 9 套内置主题或你的自定义 CSS 主题名（例如 `'corporate'`）。 |
| `color_scheme` | `?string` | `null` | `null` 继承配置档/默认配色方案（`'auto'`、`'light'`、`'dark'`）。 |
| `variant` | `string` | `'compact'` | 内置布局变体：`'compact'`、`'card'`、`'dropzone'`、`'avatar'`、`'input'`。 |
| `template` | `?string` | `null` | 指向 **100% 自定义 Twig 模板**的路径，用于替换内置布局变体（例如 `'forms/custom_picker.html.twig'`）。 |
| `disabled` | `bool` | `false` | 标准 Symfony 表单选项：设为 `true` 时字段完全只读，打开、清除和排序控件均会被锁定。 |

### 该选 `return_type: 'url'` 还是 `'path'`？
- **`'url'`（默认 —— 最省心）：** 直接在数据库中存储 `/uploads/products/thermos.webp` 或 `https://cdn.example.com/thermos.webp`。在 Twig 中只需 `<img src="{{ product.coverImage }}">` 即可渲染，零额外逻辑。
- **`'path'`（更灵活 / 便于迁移 CDN）：** 仅存储相对路径 `products/thermos.webp`。如果日后更换存储盘或 CDN 域名，无需迁移数据库记录 —— 只需通过 `{{ liveux_fm_thumb_url('products', 'local_uploads', product.coverImage) }}` 渲染或拼接 CDN 基础域名即可。

### 内置安全加固与无障碍图库排序
- **服务端 POST 安全加固：** 即使有人在浏览器开发者工具中篡改隐藏 `<input>`，试图注入 `javascript:alert(1)`、`../../etc/passwd`、`.trash/...`、超过 256 KB 的畸形 JSON、超过 500 条记录、非法 UTF-8、超出 `max_files` 的数量或 HTTP 参数污染（HPP）数组，`FileManagerPickerType` 都会在**服务端自动拦截并拒绝**。
- **无障碍图库排序（`multiple: true`）：** 用户可通过 **3 种方式**调整图库项顺序：
  1. 桌面端**拖拽排序（Drag & Drop）**，
  2. 聚焦卡片后按 **`←` / `→` 方向键**（在阿拉伯语等 RTL 语言中方向自动反转），
  3. 每张卡片上的 **`◀` / `▶` 按钮**，专为手机与触屏设备优化。

---

## 2. 5 种内置布局变体（`variant`）

`variant` 与 `theme` 完全解耦。每种变体在所有主题下均同时支持**单选**与**多选**模式：

| 布局变体 | 单选模式（`multiple: false`） | 多选模式（`multiple: true`） | 最佳适用场景 |
| :--- | :--- | :--- | :--- |
| `compact` *（默认）* | 60×60 方形缩略图 + 输入框 + 选择/清除按钮行 | 横向图库卡片 + 虚线 `+` 添加磁贴 | 通用表单字段、商品相册 |
| `card` | 16:10 封面预览卡片 + 文件名 + 底部操作栏 | 卡片网格 | 博客/文章封面、首屏横幅、精选大图 |
| `dropzone` | 大尺寸可点击虚线框 + 实时预览 | 顶部大尺寸添加框 + 下方图库卡片 | 醒目的媒体/文件上传区域 |
| `avatar` | 圆形 `82×82` 头像/Logo 预览 + 侧边按钮 | 圆形 `72×72` 头像胶囊 + 圆形 `+` 磁贴 | 用户头像、品牌 Logo |
| `input` | 极简无缩略图的单行输入组 | 可移除标签/胶囊列表 + `+ 添加` | 紧凑型表单、PDF、文档、合同附件 |

![FileManagerPickerType 5 种内置布局变体与多文件图库](../images/form-pickers.png)

---

## 3. 完整 Entity + FormType 示例

### Doctrine 实体（`src/Entity/Product.php`）
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

### Symfony 表单类（`src/Form/ProductType.php`）
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
                'label' => '商品封面图',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'card',
                'mode' => 'offcanvas',
                'position' => 'end',
                'button_label' => '选择封面',
                'placeholder' => '尚未选择封面图片…',
            ])
            ->add('galleryImages', FileManagerPickerType::class, [
                'label' => '商品图库',
                'profile' => 'products',
                'theme' => 'flowbite',
                'variant' => 'dropzone',
                'multiple' => true,
                'max_files' => 8,
                'mode' => 'modal',
                'button_label' => '添加图片',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
```

---

## 4. 打造自定义 FormType UI（3 种方式）

你绝不会被局限于 5 种内置变体或 9 套主题 —— 借助完整的 Stimulus 绑定，你可以打造 **100% 自定义的 HTML / Bootstrap / Tailwind / Alpine 界面**。

### 方式 1：按字段指定 `template` 选项（最快且最灵活）

在表单定义中传入 `'template'` 路径即可。Bundle 会自动挂载外层 Stimulus 控制器（`liveux--file-manager-bundle--picker`），并将内部所有 HTML 渲染全权交由你的 Twig 文件：

```php
$builder
    ->add('heroBanner', FileManagerPickerType::class, [
        'profile' => 'default',
        'theme' => 'unstyled',
        'template' => 'forms/custom_single_picker.html.twig',
        'button_label' => '选择横幅',
        'placeholder' => '暂未选择封面图片',
    ])
    ->add('productGallery', FileManagerPickerType::class, [
        'profile' => 'default',
        'multiple' => true,
        'max_files' => 8,
        'theme' => 'unstyled',
        'template' => 'forms/custom_gallery_picker.html.twig',
        'button_label' => '添加到图库',
    ]);
```

#### 示例 A —— 自定义单选选择器模板（`templates/forms/custom_single_picker.html.twig`）
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

#### 示例 B —— 自定义多文件图库模板（`templates/forms/custom_gallery_picker.html.twig`）
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
    <span>已选图片：<strong data-{{ ctrl }}-target="countLabel">0</strong>{% if max_files %} / {{ max_files }}{% endif %}</span>
    <button type="button" {{ isDisabled ? 'disabled' : '' }} data-action="click->{{ ctrl }}#open">+ {{ btnLabel }}</button>
</div>

{# picker_controller.js 会在此容器内将所选文件渲染为支持排序的卡片 #}
<div class="my-gallery-grid" data-{{ ctrl }}-target="galleryGrid"></div>
```

#### 自定义 FormType 模板属性与事件参考

| 属性 / 事件 | 说明 |
| :--- | :--- |
| `data-{{ ctrl }}-target="input"` | **必需** —— 随表单提交到服务端的 `<input>` 元素。 |
| `data-{{ ctrl }}-target="singlePreview"` | 单选模式下自动渲染选中图片/文件预览的容器。 |
| `data-{{ ctrl }}-target="fileLabel"` | 自动更新为已选文件名/路径的文本元素。 |
| `data-{{ ctrl }}-target="clearBtn"` | 清除按钮；当内容为空或 `isDisabled` 为 `true` 时自动设为 `disabled`。 |
| `data-{{ ctrl }}-target="galleryGrid"` | 多选模式下管理已选文件卡片与拖拽/按键排序的容器。 |
| `data-{{ ctrl }}-target="countLabel"` | 多选模式下显示当前已选文件数量的计数器元素。 |
| `data-action="click->{{ ctrl }}#open"` | 打开文件管理器 Modal / OffCanvas 弹窗。 |
| `data-action="click->{{ ctrl }}#clearSingle"` | 清除当前单选结果。 |
| **JS 事件：** `liveux:file-manager:picker-changed` | 每当选择、清除、移除或重新排序项目时触发，`event.detail` 包含 `{ values, value, multiple }`。 |

**自动传入自定义模板的 Twig 变量：**  
`id`、`full_name`、`value`、`attr`、`ctrl`、`isDisabled`、`btnLabel`、`emptyPlaceholder`、`clearLabel`、`removeLabel`、`profile`、`volume`、`multiple`、`max_files`、`mode`、`position`、`mobile_mode`、`return_type`、`preview`、`button_label`、`placeholder`、`theme`、`resolvedTheme`、`color_scheme`、`variant`、`resolvedVariant`、`locale`、`fmLocale`、`bcp47_locale`、`is_rtl`、`thumb_url`、`preview_url_prefix`。

---

### 方式 2：全局 Symfony `form_themes` 覆写

如果想在一个地方统一自定义整个项目中**所有** `FileManagerPickerType` 字段的外观，只需在自定义表单主题中覆写 `liveux_file_manager_picker_widget` 区块：

```yaml
# config/packages/twig.yaml
twig:
    form_themes:
        - 'form/my_custom_file_manager_theme.html.twig'
```

```twig
{# templates/form/my_custom_file_manager_theme.html.twig #}
{% block liveux_file_manager_picker_widget %}
    {# 你的全局自定义 HTML 与 CSS 结构 #}
{% endblock %}
```

---

### 方式 3：可复用的自定义子 FormType 类

为了避免在多个表单中重复编写相同的 `profile`、`theme`、`template` 和 `mode` 配置，可以创建一个专属的子 FormType 类：

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
            'button_label' => '选择商品封面',
            'placeholder' => '尚未选择封面图片…',
        ]);
    }

    public function getParent(): string
    {
        return FileManagerPickerType::class;
    }
}
```

之后在任意表单中只需一行代码即可调用：
```php
$builder->add('coverImage', ProductCoverImageType::class);
```

---

## 5. Symfony `CollectionType` 与 Symfony UX `LiveCollectionType`

![Symfony CollectionType 与 LiveCollectionType 实时演示](../images/demo-collections.gif)

`FileManagerPickerType` 与经典 Symfony **`CollectionType`**（基于 `data-prototype` 动态增删行）以及 **`symfony/ux-live-component`（`LiveCollectionType`）** 均可开箱即用：

- **`CollectionType`：** 新行一经插入 DOM，Stimulus 的 `picker` 控制器便会自动连接 —— 无需任何手动 JavaScript 初始化。
- **`LiveCollectionType`：** 即便 Idiomorph 在增删行期间对 DOM 进行局部比对更新（Morph），`picker_controller.js`（结合 `render:finished` + `MutationObserver`）也会根据最新的 `<input>` 值自动重绘所有单选预览与多文件图库卡片。
- **复合子表单 vs. 直接标量条目类型：**
  - 当使用如 `entry_type: SlideItemType::class` 这样的**复合子表单**时，`LiveCollectionTrait::addCollectionItem()` 会为新行提交 `[]`，子表单能平滑处理 —— 无需额外代码。
  - 当直接使用单文件（`multiple: false`）的 `entry_type: FileManagerPickerType::class` 时，选择器内置的 `PRE_SUBMIT` 监听器出于 HPP 防护会拒绝数组载荷。只需添加下方所示的 4 行集合级 `PRE_SUBMIT` 监听器，将新增空行的 `[]` 规范化为 `''` 即可。

### A. 标准 Symfony `CollectionType` 示例

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
            ->add('title', TextType::class, ['label' => '标题'])
            ->add('image', FileManagerPickerType::class, [
                'label' => '封面图',
                'profile' => 'default',
                'variant' => 'compact',
            ])
            ->add('gallery', FileManagerPickerType::class, [
                'label' => '附件列表',
                'profile' => 'default',
                'multiple' => true,
                'variant' => 'input',
            ]);
    }
}
```

```php
// 在父级 FormType 中：
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
">+ 添加一行</button>
```

### B. Symfony UX `LiveCollectionType` 示例（`LiveCollectionTrait`）

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
            // 1) 复合子表单 —— 无需额外的 PRE_SUBMIT 监听器：
            ->add('slides', LiveCollectionType::class, [
                'entry_type' => SlideItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            // 2) 直接使用单文件 FileManagerPickerType（标量列表）：
            ->add('logos', LiveCollectionType::class, [
                'entry_type' => FileManagerPickerType::class,
                'entry_options' => ['label' => false, 'variant' => 'input'],
                'allow_add' => true,
                'allow_delete' => true,
            ]);

        // 仅当 entry_type 为直接单文件（multiple: false）标量字段时需要：
        // LiveCollectionTrait::addCollectionItem() 会为新行追加 []，这里将其规范化为 ''。
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
        // 在此持久化你的数据...
    }
}
```

> [!NOTE]
> 请确保你的 `LiveCollectionType` 模板调用了 `{{ form_end(form) }}`（如果传入了 `render_rest: false`，则需显式包含 `{{ form_row(form._token) }}`），以便正确提交 CSRF 令牌。

#### LiveComponent Twig 模板（`templates/components/SliderFormComponent.html.twig`）
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
        <button type="submit">保存（AJAX）</button>
        {{ form_rest(form) }}
    {{ form_end(form) }}
</div>
```

随后在任意 Twig 模板中使用单个标签即可渲染该组件：
```twig
<twig:SliderFormComponent />
```

---

## 6. 富文本与块级编辑器集成 —— 7 个内置适配器（分步指南）

> **新手快速入门：**  
> 当运营或编辑人员撰写博客文章或商品详情时，往往需要将图片或 PDF 顺滑地插入正文中。LiveUX FileManagerBundle 为最受欢迎的开源编辑器内置了 **7 个现成的 JavaScript 适配器**：**TinyMCE（5/6/7/8）**、**CKEditor 5**、**Tiptap 2**、**Quill 2**、**Trix（EasyAdmin `TextEditorField`）**、**Summernote** 与 **Editor.js**。每个适配器均具备以下特性：
> - 在浏览器原生 `<dialog>` 顶层（Top Layer）中打开文件管理器（**彻底消除 `z-index` 层级冲突**或编辑器弹窗背后的焦点陷阱问题），
> - 自动携带所选图片的 SEO 元数据（`alt` / `title` / `caption`），
> - 对返回的每个 URL 严格校验，拦截 `javascript:` 与路径穿越（`../`）等 XSS 攻击向量。

全部 7 个适配器均接受相同的通用选项：  
`profile`、`volume`、`path`、`mode`（`'modal'`|`'offcanvas'`）、`position`（`'start'`|`'end'`|`'top'`|`'bottom'`）、`mobileMode`（`'auto'`|`'fullscreen'`|`'sheet'`）、`locale`、`theme`、`iconSet`、`multiple`、`type`。

> [!IMPORTANT]
> **AssetMapper 用户必看（一行 `importmap.php` 配置）：**  
> 在 **Webpack Encore** 项目中，`import ... from '@liveux/file-manager-bundle/js/integrations/...'` 可直接工作。  
> 如果你使用的是 **Symfony AssetMapper**，只需在 `importmap.php` 中注册**你实际用到的适配器**，让浏览器的 `<script type="importmap">` 识别该模块路径即可（这些文件已内置在 Bundle 中，不会从互联网下载任何内容）：
> ```php
> // importmap.php（仅添加你实际使用的编辑器适配器）
> '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'    => ['path' => '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'  => ['path' => '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'     => ['path' => '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'      => ['path' => '@liveux/file-manager-bundle/js/integrations/quill_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'       => ['path' => '@liveux/file-manager-bundle/js/integrations/trix_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js' => ['path' => '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js'],
> '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'   => ['path' => '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js'],
> ```

---

### A. TinyMCE 5 / 6 / 7 / 8 集成（`createTinyMceFilePicker`）

![TinyMCE + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-tinymce.gif)

TinyMCE 5、6、7 和 8 共享完全一致的 `file_picker_callback` 签名，且已通过**真实浏览器端到端测试验证**。该适配器会将 TinyMCE 内置的**图片（`image`）**、**媒体（`media`）**与**链接（`link`）**弹窗中的浏览按钮直接接入 LiveUX FileManagerBundle。

> **版本提示：** 使用 TinyMCE **7 或 8** 时请保留 `license_key: 'gpl'`；如果你使用的是 TinyMCE **5 或 6**，删除该行即可，其余代码完全相同。

#### 第 1 步：在页面中加载 TinyMCE（开源免费 —— 无需 API Key）
```twig
{# templates/article/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {# TinyMCE 7（来自 CDN 或你自己的 public/ 目录） #}
    <script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>

    <div data-controller="tinymce-editor">
        <textarea name="article[content]" data-tinymce-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### 第 2 步：通过 Stimulus 控制器接入 `createTinyMceFilePicker`
创建 `assets/controllers/tinymce_editor_controller.js`（在 **AssetMapper** 与 **Webpack Encore** 中写法完全一致）：

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
            license_key: 'gpl', // 免费开源 GPL 许可模式
            height: 420,
            plugins: 'image link media lists table code',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | image media link | code',
            file_picker_types: 'file image media',
            file_picker_callback: createTinyMceFilePicker({
                profile: 'default',     // 对应 liveux_file_manager.yaml 中的配置档
                mode: 'modal',          // 'modal' | 'offcanvas'
                position: 'end',
                mobileMode: 'auto',
                locale: 'zh_CN',
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

#### 实际效果
当用户点击 TinyMCE 工具栏中的**图片**按钮，再点击**地址（Source）**旁边的浏览图标时：
1. LiveUX FileManagerBundle 会自动在 TinyMCE 弹窗上方打开，并预先筛选为图片类型（`filetype: 'image'`）。
2. 用户选定图片后，**图片地址（Source URL）**与**替代描述（`alt`）**字段都会自动填充！

---

### B. CKEditor 5 集成（`openFileManagerForCKEditor5`）

![CKEditor 5 + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-ckeditor5.gif)

支持 CKEditor 5 的 Classic、Inline、Balloon 与 Decoupled 构建版本，一键插入**单张或多张**图片（`insertImage`）以及文档/PDF 链接。

#### 第 1 步：引入 CKEditor 5 并添加“插入媒体”按钮
```twig
{# templates/article/ckeditor.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@41.4.2/build/ckeditor.js"></script>

    <div data-controller="ckeditor-editor">
        <div style="margin-bottom: 10px;">
            <button type="button" data-action="ckeditor-editor#insertMedia">
                从文件管理器插入媒体
            </button>
        </div>

        <textarea name="article[content]" data-ckeditor-editor-target="textarea">
            {{ article.content|default('') }}
        </textarea>
    </div>
{% endblock %}
```

#### 第 2 步：接入 `openFileManagerForCKEditor5`
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
            mode: 'offcanvas', // 侧边滑出抽屉，浏览文件时不会完全遮挡编辑器
            position: 'end',
            multiple: true,    // 支持一次插入多张图片
            locale: 'zh_CN',
        });
    }

    disconnect() {
        this.editor?.destroy().catch(() => {});
    }
}
```

#### 实际效果
- **图片**会通过 CKEditor 5 原生的 `insertImage` 命令以 `<figure class="image">` 区块插入。
- **文档（PDF、ZIP 等）**会自动以带文件名的可点击 `<a href="...">` 链接形式插入。

---

### C. Tiptap / ProseMirror 集成（`insertFromFileManager`）

![Tiptap 2 + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-tiptap.gif)

如果你使用现代无头编辑器 **Tiptap 2**，只需一次函数调用即可与 `@tiptap/extension-image` 和 `@tiptap/extension-link` 完美对接。

#### 第 1 步：安装依赖包
- **使用 Webpack Encore / npm：**
  ```bash
  npm install @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```
- **使用 AssetMapper：**
  ```bash
  php bin/console importmap:require @tiptap/core @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-link
  ```

#### 第 2 步：Twig 模板（工具栏 + 编辑器画布 + 隐藏输入框）
```twig
{# templates/article/tiptap.html.twig #}
<div data-controller="tiptap-editor">
    <div class="tiptap-toolbar">
        <button type="button" data-action="tiptap-editor#toggleBold"><strong>B</strong></button>
        <button type="button" data-action="tiptap-editor#toggleItalic"><em>I</em></button>
        <button type="button" data-action="tiptap-editor#insertMedia">🖼 插入媒体（LiveUX）</button>
    </div>

    <div data-tiptap-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-tiptap-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### 第 3 步：编写 `insertFromFileManager` Stimulus 控制器
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
            content: this.hiddenInputTarget.value || '<p>开始撰写内容...</p>',
            onUpdate: ({ editor }) => {
                // 保持隐藏输入框与最新 HTML 同步，便于表单提交
                this.hiddenInputTarget.value = editor.getHTML();
            },
        });
    }

    async insertMedia() {
        await insertFromFileManager(this.editor, {
            profile: 'default',
            mode: 'modal',
            multiple: true,
            locale: 'zh_CN',
        });
    }

    toggleBold() { this.editor?.chain().focus().toggleBold().run(); }
    toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); }

    disconnect() {
        this.editor?.destroy();
    }
}
```

#### 实际效果
- 图片通过 `setImage({ src, alt, title })` 插入，完整保留 SEO 的 `alt` 与 `title` 属性。
- 文档（PDF、ZIP 等）自动插入为可点击链接（`marks: [{ type: 'link', attrs: { href } }]`）。

---

### D. Quill 1.x / 2.x 集成（`createQuillImageHandler` 与 `openFileManagerForQuill`）

![Quill 2 + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-quill.gif)

一行代码即可将 Quill 工具栏原生的**图片**按钮接入 LiveUX FileManagerBundle —— 自动为图片附加 SEO `alt` 属性，并为文档插入可点击链接。

#### 第 1 步：引入 Quill CSS/JS 与编辑器容器
```twig
{# templates/article/quill.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<div data-controller="quill-editor">
    <div data-quill-editor-target="canvas"></div>
    <input type="hidden" name="article[content]" data-quill-editor-target="hiddenInput" value="{{ article.content|default('') }}">
</div>
```

#### 第 2 步：编写 `createQuillImageHandler` Stimulus 控制器
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
                        // 点击 Quill 内置的图片按钮即可打开 LiveUX FileManagerBundle！
                        image: createQuillImageHandler(() => this.quill, {
                            profile: 'default',
                            mode: 'offcanvas',
                            position: 'end',
                            locale: 'zh_CN',
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

### E. Trix 编辑器集成 —— EasyAdmin `TextEditorField` 与 Turbo（`openFileManagerForTrix`）

![Trix + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-trix.gif)

向 `<trix-editor>` 插入安全的图片附件（`Trix.Attachment`）和文档链接 —— `<trix-editor>` 正是 **EasyAdmin 内置 `TextEditorField`** 以及 Symfony Turbo/Hotwire 应用的默认富文本编辑器。

```twig
{# templates/article/trix.html.twig #}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.12/dist/trix.umd.min.js"></script>

<div data-controller="trix-editor">
    <button type="button" data-action="trix-editor#insertMedia">从文件管理器插入</button>
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
            locale: 'zh_CN',
        });
    }
}
```

---

### F. Summernote 集成（`openFileManagerForSummernote`）

![Summernote + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-summernote.gif)

向广泛应用于 Bootstrap 后台面板的 Summernote 编辑器中插入经过安全清洗的 DOM `<img>` 节点（带 SEO `alt` 与 `title`）以及 `<a>` 链接。

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
            locale: 'zh_CN',
        });
    }
}
```

---

### G. Editor.js（块级 JSON 编辑器）集成（`openFileManagerForEditorJs`）

![Editor.js + LiveUX FileManagerBundle 实时集成演示](../images/demo-editor-editorjs.gif)

向 **Editor.js** 插入结构化的 `image`（`@editorjs/image`）或 `simpleImage`（`@editorjs/simple-image`）内容块以及文档链接，非常适合无头 CMS 与 Notion 风格的块级编辑器。

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
            imageBlockType: 'simpleImage', // 如果使用 @editorjs/image，保持默认的 'image' 即可
            locale: 'zh_CN',
        });
    }
}
```

---

### H. GrapesJS（可视化页面与邮件构建器）集成（`createGrapesJsAssetManagerConfig` / `openFileManagerForGrapesJs`）

![GrapesJS + LiveUX FileManagerBundle 实时集成](../images/demo-editor-grapesjs.gif)

通过两种方式与 **GrapesJS（0.20+ / 0.21+ / 0.22+）** 无缝集成：
1. **`createGrapesJsAssetManagerConfig()`：** 使用 LiveUX FileManagerBundle 替换 GrapesJS 内置的 Asset Manager 弹窗（双击画布上的任意图片即可自动打开）。
2. **`openFileManagerForGrapesJs()`：** 通过自定义按钮将单个或多个图片/链接组件直接插入画布。

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
            // 1) 使用 LiveUX FileManager 替换内置的 Asset Manager：
            assetManager: createGrapesJsAssetManagerConfig({
                profile: 'default',
                mode: 'modal',
                locale: 'zh_CN',
            }),
        });
    }

    // 2) 或者通过自定义按钮将图片/链接直接插入画布：
    async insertMedia() {
        await openFileManagerForGrapesJs(this.editor, {
            profile: 'default',
            mode: 'offcanvas',
            position: 'end',
            multiple: true,
            locale: 'zh_CN',
        });
    }
}
```

---

### I. Meta Lexical 集成（`openFileManagerForLexical`）

![Meta Lexical + LiveUX FileManagerBundle 实时集成](../images/demo-editor-lexical.gif)

通过命令分发（`INSERT_IMAGE_COMMAND` / `TOGGLE_LINK_COMMAND`）或自定义 `onInsert` 回调与 Meta 的可扩展富文本引擎 **Lexical**（`lexical` 0.15+ / 0.20+ / 0.30+）集成，并内置严格的 XSS URL 校验与 SEO `altText` 元数据支持。

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
            nodes: [/* 自定义 ImageNode */],
            onError: (err) => console.error(err),
        });
        this.editor.setRootElement(this.editableTarget);

        this.editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            ({ src, altText }) => {
                // 将自定义的 ImageNode / DecoratorNode 插入 Lexical 状态中
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
            locale: 'zh_CN',
            insertImageCommand: INSERT_IMAGE_COMMAND,
        });
    }
}
```

---

## 7. EasyAdmin 4 / 5 集成 —— 分步指南（`FileManagerField`）

![EasyAdmin 5 + LiveUX FileManagerField 实时演示](../images/demo-easyadmin.gif)

内置的 `FileManagerField` 无需任何额外依赖：
- 在 **列表页（Index）** 和 **详情页（Detail）**，它会自动渲染所选文件的缩略图和徽章（URL 安全性由 `liveux_fm_describe_field_item()` 强制保障）。
- 在 **新建页（New）** 和 **编辑页（Edit）**，它直接渲染为 `FileManagerPickerType`。

### 第 1 步：在 EasyAdmin 的 `DashboardController` 中加载应用资源（重要！）

由于 EasyAdmin 使用独立的基础布局（不会继承你的 `base.html.twig`），请在 `DashboardController::configureAssets()` 中注册你的 `app` 资源入口：

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
        // 如果使用 AssetMapper：
        return Assets::new()->addAssetMapperEntry('app');

        // 如果使用 Webpack Encore，请将上一行替换为：
        // return Assets::new()->addWebpackEncoreEntry('app');
    }
}
```

### 第 2 步：在 `CrudController` 中使用 `FileManagerField`

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
        yield TextField::new('title', '商品名称');

        yield FileManagerField::new('coverImage', '封面图片')
            ->setProfile('products')
            ->setVolume('local_uploads')
            ->setColorScheme('auto')
            ->setVariant('dropzone')
            ->setMode('offcanvas')
            ->setPosition('end')
            ->setMobileMode('sheet')
            ->setButtonLabel('选择封面');

        yield FileManagerField::new('galleryImages', '商品图库')
            ->setProfile('products')
            ->setMultiple(true)
            ->setMaxFiles(10)
            ->setReturnType('url')
            ->setVariant('card');
    }
}
```

### `FileManagerField` 全部流式方法（共 16 个方法）

| 方法 | 对应选项 | 示例 |
| :--- | :--- | :--- |
| `setProfile(string $profile)` | `profile` | `->setProfile('products')` |
| `setLocale(?string $locale)` | `locale` | `->setLocale('zh_CN')` |
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
| `setButtonLabel(?string $label)` | `button_label` | `->setButtonLabel('更换 Logo')` |
| `setPlaceholder(?string $placeholder)` | `placeholder` | `->setPlaceholder('尚未选择')` |

---

[← 上一篇：04 — 主题、图标与深度定制](04-themes-icons-and-customization.md) | [目录](README.md) | [下一篇：06 — 安全与权限控制 →](06-security-and-authorization.md)
