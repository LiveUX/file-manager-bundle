---
name: liveux-file-manager-forms-and-editors
description: >-
  Integrates LiveUX FileManagerBundle with Symfony Forms (FileManagerPickerType),
  custom FormType Twig layouts, EasyAdmin FileManagerField, programmatic
  Modal/OffCanvas JavaScript API (window.LiveUXFileManager.open), and WYSIWYG
  editors (TinyMCE, CKEditor 5, Tiptap, Quill, Trix, Summernote, Editor.js,
  GrapesJS, Meta Lexical). Use when building media/gallery form fields,
  CollectionType/LiveCollectionType repeaters, custom pickers, or editor integrations.
license: MIT
compatibility: PHP >= 8.3, Symfony ^6.4|^7.2|^8.0, symfony/form, symfony/stimulus-bundle
metadata:
  author: liveux
  version: "0.1.0"
---

# LiveUX FileManagerBundle — Forms, Pickers & Editor Integrations

Use this guide whenever adding file/image/gallery fields to Symfony forms, creating custom FormType designs, or connecting WYSIWYG editors and EasyAdmin.

## 1. Symfony FormType (`FileManagerPickerType`)

- `multiple: false` binds to `?string` (URL or relative path).
- `multiple: true` binds to `list<string>` (automatically JSON-encoded via built-in `CallbackTransformer`, with HTML5 drag-to-reorder).

```php
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;

// Single image (dropzone card)
$builder->add('coverImage', FileManagerPickerType::class, [
    'label' => 'Cover Image',
    'profile' => 'default',
    'variant' => 'dropzone',       // compact | card | dropzone | avatar | input
    'mode' => 'modal',             // modal | offcanvas
    'return_type' => 'url',        // url | path
    'required' => false,
]);

// Multi-file gallery (drag-to-reorder)
$builder->add('gallery', FileManagerPickerType::class, [
    'label' => 'Product Gallery',
    'profile' => 'catalog',
    'multiple' => true,
    'max_files' => 8,
    'variant' => 'card',
    'mode' => 'offcanvas',
    'position' => 'end',           // start | end | top | bottom
]);
```

## 2. Custom FormType Template (`template` Option)

Pass `'template' => 'form/my_custom_picker.html.twig'` on the field to take 100% control of HTML/CSS while reusing `picker_controller.js`:

1. `picker.html.twig` already renders the outer `data-controller="liveux--file-manager-bundle--picker"` wrapper and Stimulus values around `{% include template %}`, so do NOT duplicate that outer wrapper inside your custom template.
2. Keep the bound hidden/text `<input>` with `data-liveux--file-manager-bundle--picker-target="input"`.
3. Trigger selection with `data-action="liveux--file-manager-bundle--picker#open"`.
4. Listen to `liveux:file-manager:picker-changed` (`event.detail`: `{ value, values, multiple }`) to sync any custom preview UI without fighting built-in markup.

See complete template boilerplate and all Stimulus targets in [references/picker-and-js-api.md](references/picker-and-js-api.md).

## 3. EasyAdmin 4/5 Field (`FileManagerField`)

Zero hard dependency — register your app entrypoint inside `DashboardController::configureAssets()` (`addAssetMapperEntry('app')` or `addWebpackEncoreEntry('app')`), then instantiate `FileManagerField` directly inside any `CrudController::configureFields()`:

```php
use LiveUX\FileManagerBundle\Bridge\EasyAdmin\FileManagerField;

yield FileManagerField::new('coverImage', 'Cover Image')
    ->setProfile('blog_images')
    ->setVariant('dropzone');

yield FileManagerField::new('gallery', 'Gallery')
    ->setMultiple(true)
    ->setMaxFiles(10)
    ->setVariant('card');
```

## 4. Programmatic JavaScript API (`window.LiveUXFileManager.open`)

```js
const files = await window.LiveUXFileManager.open({
    profile: 'default',
    volume: null,
    path: 'catalog',
    multiple: true,
    mode: 'offcanvas',       // modal | offcanvas
    position: 'end',         // start | end | top | bottom
    theme: null,             // built-in theme override or null
});
// files: [{ kind, path, url, name, meta: { alt, title, caption, tags, focalPoint } }]
```

## 5. WYSIWYG, Block & Page Builder Adapters (9 Ready Editors)

All 9 adapters validate URLs against `javascript:` / `data:` / protocol-relative / traversal vectors (AssetMapper apps should register any used adapter path in `importmap.php` using the logical `@liveux/file-manager-bundle/...` path; Webpack Encore resolves them automatically via `package.json` `exports`):

- **TinyMCE 5/6/7/8:** `import { createTinyMceFilePicker } from '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js';`
- **CKEditor 5:** `import { openFileManagerForCKEditor5 } from '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js';`
- **Tiptap 2:** `import { insertFromFileManager } from '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js';`
- **Quill 1/2:** `import { createQuillImageHandler, openFileManagerForQuill } from '@liveux/file-manager-bundle/js/integrations/quill_adapter.js';`
- **Trix (EasyAdmin `TextEditorField`):** `import { openFileManagerForTrix } from '@liveux/file-manager-bundle/js/integrations/trix_adapter.js';`
- **Summernote:** `import { openFileManagerForSummernote } from '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js';`
- **Editor.js:** `import { openFileManagerForEditorJs } from '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js';`
- **GrapesJS:** `import { createGrapesJsAssetManagerConfig, openFileManagerForGrapesJs } from '@liveux/file-manager-bundle/js/integrations/grapesjs_adapter.js';`
- **Meta Lexical:** `import { openFileManagerForLexical } from '@liveux/file-manager-bundle/js/integrations/lexical_adapter.js';`

Full adapter snippets live in [references/picker-and-js-api.md](references/picker-and-js-api.md).

## 6. Common Pitfalls

- Do NOT initialize `json_encode()` manually on a `multiple: true` entity property — `FileManagerPickerType` expects a PHP `array<int, string>` (e.g. Doctrine `Types::JSON`).
- When using a custom `template`, always render `{{ form_widget(form) }}` or keep `name="{{ full_name }}"` on the `input` target so Symfony form submission binds properly.
- Ensure `assets/js/file_manager_api.js` is imported once in your entrypoint so `window.LiveUXFileManager.open()` is defined before pickers or editors click it.

## 7. Verification

```bash
php bin/console lint:twig templates/
php bin/console asset-map:compile   # If public/assets/ exists
```
