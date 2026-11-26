# FileManagerPickerType, Custom Templates & JS API Reference

## `FileManagerPickerType` Options

| Option | Default | Description |
| :--- | :--- | :--- |
| `profile` | `'default'` | Configured file manager profile |
| `locale` | `null` (`?string`) | Optional locale override (inherits profile/default locale when `null`) |
| `volume` | `null` | Initial volume name (or profile default) |
| `multiple` | `false` | `false` → `?string`, `true` → `list<string>` via `CallbackTransformer` |
| `max_files` | `null` | Max items when `multiple: true` (`null` / `<= 0` = unlimited) |
| `mode` | `'modal'` | `'modal'` or `'offcanvas'` |
| `position` | `'end'` | OffCanvas side: `'start'`, `'end'`, `'top'`, `'bottom'` |
| `return_type` | `'url'` | `'url'` (public/download URL) or `'path'` (relative volume path) |
| `preview` | `true` | Show image/file thumbnails |
| `variant` | `'compact'` | `'compact'`, `'card'`, `'dropzone'`, `'avatar'`, `'input'` |
| `theme` | `null` | Inherits profile theme, or pass `'unstyled'` / built-in / custom CSS theme |
| `color_scheme` | `null` (`?string`) | Inherits profile `ui.color_scheme`, or override (`'auto'`, `'light'`, `'dark'`) |
| `template` | `null` | Custom Twig template path replacing built-in picker markup |
| `button_label` | `null` | Custom select button label |
| `placeholder` | `null` | Placeholder text when empty |

## Custom FormType Twig Boilerplate (`template` Option)

Note: `picker.html.twig` already renders the outer `<div data-controller="liveux--file-manager-bundle--picker" ...>` wrapper around `{% include template %}`. Place only the inner targets and triggers in your custom template:

```twig
{% set ctrl = 'liveux--file-manager-bundle--picker' %}
<input type="hidden"
       id="{{ id }}"
       name="{{ full_name }}"
       value="{{ value }}"
       data-{{ ctrl }}-target="input">

<button type="button" data-action="click->{{ ctrl }}#open">
    {{ button_label|default('Select File') }}
</button>
<button type="button" data-{{ ctrl }}-target="clearBtn" data-action="click->{{ ctrl }}#clearSingle">
    Clear
</button>
<span data-{{ ctrl }}-target="fileLabel"></span>
<div data-{{ ctrl }}-target="singlePreview"></div>
{# For multiple: true #}
<div data-{{ ctrl }}-target="galleryGrid"></div>
<span data-{{ ctrl }}-target="countLabel"></span>
```

### `liveux:file-manager:picker-changed` CustomEvent
Dispatched on the picker controller root element (`bubbles: true`) on every selection, clear, removal, or gallery drag-reorder:

```js
document.addEventListener('liveux:file-manager:picker-changed', (event) => {
    const { value, values, multiple } = event.detail;
    // Update external preview cards, Alpine/Vue/React state, or LiveComponent props
});
```

## WYSIWYG Adapter Snippets

### TinyMCE 6/7/8
```js
import { createTinyMceFilePicker } from '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js';

tinymce.init({
    selector: 'textarea.wysiwyg',
    file_picker_types: 'file image media',
    file_picker_callback: createTinyMceFilePicker({
        profile: 'default',
        mode: 'modal',
    }),
});
```

### CKEditor 5
```js
import { openFileManagerForCKEditor5 } from '@liveux/file-manager-bundle/js/integrations/ckeditor5_adapter.js';

document.querySelector('#ck-media-btn')?.addEventListener('click', () => {
    openFileManagerForCKEditor5(editorInstance, {
        profile: 'default',
        multiple: true,
    });
});
```

### Tiptap (ProseMirror)
```js
import { insertFromFileManager } from '@liveux/file-manager-bundle/js/integrations/tiptap_adapter.js';

document.querySelector('#tiptap-media-btn')?.addEventListener('click', () => {
    insertFromFileManager(tiptapEditor, {
        profile: 'blog_images',
        multiple: true,
    });
});
```

### Quill 1/2
```js
import { createQuillImageHandler, openFileManagerForQuill } from '@liveux/file-manager-bundle/js/integrations/quill_adapter.js';

const quill = new Quill('#editor', {
    theme: 'snow',
    modules: {
        toolbar: {
            container: [['bold', 'italic'], ['link', 'image']],
            handlers: { image: createQuillImageHandler(() => quill, { profile: 'default' }) },
        },
    },
});
```

### Trix (EasyAdmin `TextEditorField` & Turbo)
```js
import { openFileManagerForTrix } from '@liveux/file-manager-bundle/js/integrations/trix_adapter.js';

document.querySelector('#trix-media-btn')?.addEventListener('click', () => {
    openFileManagerForTrix(document.querySelector('trix-editor'), { profile: 'default', multiple: true });
});
```

### Summernote
```js
import { openFileManagerForSummernote } from '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js';

document.querySelector('#sn-media-btn')?.addEventListener('click', () => {
    openFileManagerForSummernote(window.jQuery('#summernote'), { profile: 'default', multiple: true });
});
```

### Editor.js (Block JSON)
```js
import { openFileManagerForEditorJs } from '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js';

document.querySelector('#ejs-media-btn')?.addEventListener('click', () => {
    openFileManagerForEditorJs(editorJsInstance, { profile: 'default', imageBlockType: 'simpleImage', multiple: true });
});
```

### GrapesJS (Visual Page & Email Builder)
```js
import { createGrapesJsAssetManagerConfig, openFileManagerForGrapesJs } from '@liveux/file-manager-bundle/js/integrations/grapesjs_adapter.js';

const gjs = grapesjs.init({
    container: '#gjs',
    assetManager: createGrapesJsAssetManagerConfig({ profile: 'default', mode: 'modal' }),
});
```

### Meta Lexical
```js
import { openFileManagerForLexical } from '@liveux/file-manager-bundle/js/integrations/lexical_adapter.js';

document.querySelector('#lexical-media-btn')?.addEventListener('click', () => {
    openFileManagerForLexical(lexicalEditor, {
        profile: 'default',
        multiple: true,
        insertImageCommand: INSERT_IMAGE_COMMAND,
    });
});
```
