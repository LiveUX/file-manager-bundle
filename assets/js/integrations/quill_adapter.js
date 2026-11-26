/**
 * Quill 1.x / 2.x adapter for LiveUX FileManagerBundle.
 *
 * Usage:
 *   import { createQuillImageHandler, openFileManagerForQuill } from '@liveux/file-manager-bundle/js/integrations/quill_adapter.js';
 *
 *   const quill = new Quill('#editor', {
 *       theme: 'snow',
 *       modules: {
 *           toolbar: {
 *               container: [['bold', 'italic'], ['link', 'image']],
 *               handlers: {
 *                   image() { openFileManagerForQuill(this.quill, { profile: 'default', type: 'image' }); }
 *               }
 *           }
 *       }
 *   });
 */
import { isImageUrl, isSafeUrl, openFromAdapter } from './_url_guard.js';

export async function openFileManagerForQuill(quill, options = {}) {
    if (!quill || typeof quill.insertEmbed !== 'function' || typeof quill.insertText !== 'function') {
        console.error('[LiveUX FileManager] Invalid Quill instance.');
        return [];
    }

    const files = await openFromAdapter(options);
    if (!Array.isArray(files) || files.length === 0) {
        return [];
    }

    const effectiveFiles = options.multiple ? files : files.slice(0, 1);
    const range = typeof quill.getSelection === 'function' ? quill.getSelection(true) : null;
    let index = range && typeof range.index === 'number'
        ? range.index
        : (typeof quill.getLength === 'function' ? Math.max(0, quill.getLength() - 1) : 0);

    const inserted = [];
    for (const file of effectiveFiles) {
        const rawUrl = file.url || file.path || '';
        if (!isSafeUrl(rawUrl)) {
            continue;
        }
        const url = String(rawUrl).trim();
        const name = String(file.meta?.title || file.name || url);

        if (isImageUrl(url) || file.isImage) {
            quill.insertEmbed(index, 'image', url, 'user');
            // Apply SEO alt attribute onto the newly inserted <img> when root DOM is available
            const alt = String(file.meta?.alt || file.name || '');
            if (alt && quill.root && typeof quill.root.querySelectorAll === 'function') {
                const imgs = quill.root.querySelectorAll('img');
                for (let i = imgs.length - 1; i >= 0; i -= 1) {
                    if (imgs[i].getAttribute('src') === url && !imgs[i].getAttribute('alt')) {
                        imgs[i].setAttribute('alt', alt);
                        break;
                    }
                }
            }
            index += 1;
            quill.insertText(index, '\n', 'user');
            index += 1;
            inserted.push(file);
        } else {
            quill.insertText(index, name, 'link', url, 'user');
            index += name.length;
            quill.insertText(index, ' ', 'user');
            index += 1;
            inserted.push(file);
        }
    }

    if (inserted.length > 0 && typeof quill.setSelection === 'function') {
        quill.setSelection(index, 0, 'silent');
    }

    return inserted;
}

export function createQuillImageHandler(quillOrGetter, options = {}) {
    return function liveuxQuillImageHandler() {
        const instance = typeof quillOrGetter === 'function'
            ? quillOrGetter()
            : (quillOrGetter || this?.quill);
        return openFileManagerForQuill(instance, { type: 'image', ...options });
    };
}
