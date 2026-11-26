/**
 * Editor.js (Block JSON Editor) adapter for LiveUX FileManagerBundle.
 *
 * Works with @editorjs/editorjs + @editorjs/simple-image or @editorjs/image.
 *
 * Usage:
 *   import { openFileManagerForEditorJs } from '@liveux/file-manager-bundle/js/integrations/editorjs_adapter.js';
 *
 *   button.addEventListener('click', () => {
 *       openFileManagerForEditorJs(editorJsInstance, {
 *           profile: 'default',
 *           multiple: true,
 *           imageBlockType: 'image', // 'image' (@editorjs/image) | 'simpleImage' (@editorjs/simple-image)
 *       });
 *   });
 */
import { escapeHtml, isImageUrl, isSafeUrl, openFromAdapter } from './_url_guard.js';

export async function openFileManagerForEditorJs(editor, options = {}) {
    if (!editor || !editor.blocks || typeof editor.blocks.insert !== 'function') {
        console.error('[LiveUX FileManager] Invalid Editor.js instance.');
        return [];
    }

    const files = await openFromAdapter(options);
    if (!Array.isArray(files) || files.length === 0) {
        return [];
    }

    const effectiveFiles = options.multiple ? files : files.slice(0, 1);
    const imageBlockType = typeof options.imageBlockType === 'string' && options.imageBlockType !== ''
        ? options.imageBlockType
        : 'image';
    const inserted = [];

    for (const file of effectiveFiles) {
        const rawUrl = file.url || file.path || '';
        if (!isSafeUrl(rawUrl)) {
            continue;
        }
        const url = String(rawUrl).trim();
        const name = String(file.meta?.title || file.name || url);
        const caption = String(file.meta?.caption || file.meta?.alt || '');

        if (isImageUrl(url) || file.isImage) {
            const payload = imageBlockType === 'simpleImage'
                ? { url, caption, withBorder: false, withBackground: false, stretched: false }
                : { file: { url }, caption, withBorder: false, withBackground: false, stretched: false };
            editor.blocks.insert(imageBlockType, payload);
            inserted.push(file);
        } else {
            editor.blocks.insert('paragraph', {
                text: `<a href="${escapeHtml(url)}" rel="noopener noreferrer">${escapeHtml(name)}</a>`,
            });
            inserted.push(file);
        }
    }

    return inserted;
}
