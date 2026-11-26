/**
 * Trix Editor adapter for LiveUX FileManagerBundle.
 * Works with standalone <trix-editor>, Hotwire/Turbo, and EasyAdmin's built-in TextEditorField!
 *
 * Usage:
 *   import { openFileManagerForTrix } from '@liveux/file-manager-bundle/js/integrations/trix_adapter.js';
 *
 *   button.addEventListener('click', () => {
 *       openFileManagerForTrix(document.querySelector('trix-editor'), {
 *           profile: 'default',
 *           multiple: true,
 *       });
 *   });
 */
import { escapeHtml, isImageUrl, isSafeUrl, openFromAdapter } from './_url_guard.js';

export async function openFileManagerForTrix(trixElement, options = {}) {
    const editor = trixElement?.editor;
    if (!editor || typeof editor.insertHTML !== 'function') {
        console.error('[LiveUX FileManager] Invalid <trix-editor> element.');
        return [];
    }

    const files = await openFromAdapter(options);
    if (!Array.isArray(files) || files.length === 0) {
        return [];
    }

    const effectiveFiles = options.multiple ? files : files.slice(0, 1);
    const inserted = [];
    const TrixAttachment = typeof window !== 'undefined' ? window.Trix?.Attachment : null;

    for (const file of effectiveFiles) {
        const rawUrl = file.url || file.path || '';
        if (!isSafeUrl(rawUrl)) {
            continue;
        }
        const url = String(rawUrl).trim();
        const name = String(file.meta?.title || file.name || url);
        const alt = String(file.meta?.alt || file.name || '');

        if (isImageUrl(url) || file.isImage) {
            if (typeof TrixAttachment === 'function' && typeof editor.insertAttachment === 'function') {
                const safeImgHtml = `<figure class="liveux-trix-image"><img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" style="max-width:360px;height:auto;border-radius:8px;"></figure>`;
                const attachment = new TrixAttachment({
                    content: safeImgHtml,
                    contentType: 'text/html',
                    filename: file.name || 'image',
                    url,
                });
                editor.insertAttachment(attachment);
            } else {
                editor.insertHTML(`<img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}">`);
            }
            inserted.push(file);
        } else {
            editor.insertHTML(`<a href="${escapeHtml(url)}" rel="noopener noreferrer">${escapeHtml(name)}</a> `);
            inserted.push(file);
        }
    }

    if (inserted.length > 0 && typeof trixElement.focus === 'function') {
        trixElement.focus();
    }

    return inserted;
}

export function bindTrixFileManager(trixElement, buttonElement, options = {}) {
    if (!buttonElement || typeof buttonElement.addEventListener !== 'function') {
        return () => {};
    }
    const onClick = (event) => {
        event.preventDefault();
        openFileManagerForTrix(trixElement, options);
    };
    buttonElement.addEventListener('click', onClick);
    return () => buttonElement.removeEventListener('click', onClick);
}
