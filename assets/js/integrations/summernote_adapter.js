/**
 * Summernote (Bootstrap 5 / Lite) adapter for LiveUX FileManagerBundle.
 *
 * Usage:
 *   import { openFileManagerForSummernote } from '@liveux/file-manager-bundle/js/integrations/summernote_adapter.js';
 *
 *   $('#summernote-btn').on('click', () => {
 *       openFileManagerForSummernote($('#summernote'), {
 *           profile: 'default',
 *           multiple: true,
 *       });
 *   });
 */
import { isImageUrl, isSafeUrl, openFromAdapter } from './_url_guard.js';

function resolveSummernoteInvoker(target) {
    if (target && typeof target.summernote === 'function') {
        return target;
    }
    if (typeof window !== 'undefined' && typeof window.jQuery === 'function' && target) {
        const jq = window.jQuery(target);
        if (jq && typeof jq.summernote === 'function') {
            return jq;
        }
    }
    return null;
}

export async function openFileManagerForSummernote(summernoteTarget, options = {}) {
    const jq = resolveSummernoteInvoker(summernoteTarget);
    if (!jq) {
        console.error('[LiveUX FileManager] Invalid Summernote instance or jQuery element.');
        return [];
    }

    const files = await openFromAdapter(options);
    if (!Array.isArray(files) || files.length === 0) {
        return [];
    }

    const effectiveFiles = options.multiple ? files : files.slice(0, 1);
    const inserted = [];
    const doc = typeof document !== 'undefined' ? document : null;

    for (const file of effectiveFiles) {
        const rawUrl = file.url || file.path || '';
        if (!isSafeUrl(rawUrl)) {
            continue;
        }
        const url = String(rawUrl).trim();
        const name = String(file.meta?.title || file.name || url);
        const alt = String(file.meta?.alt || file.name || '');

        if (isImageUrl(url) || file.isImage) {
            if (doc && typeof doc.createElement === 'function') {
                const img = doc.createElement('img');
                img.src = url;
                img.alt = alt;
                if (file.meta?.title) {
                    img.title = String(file.meta.title);
                }
                img.style.maxWidth = '360px';
                img.style.height = 'auto';
                img.style.borderRadius = '8px';
                jq.summernote('insertNode', img);
            } else {
                jq.summernote('insertImage', url, name);
            }
            inserted.push(file);
        } else {
            if (doc && typeof doc.createElement === 'function') {
                const a = doc.createElement('a');
                a.href = url;
                a.textContent = name;
                a.rel = 'noopener noreferrer';
                jq.summernote('insertNode', a);
            } else {
                jq.summernote('createLink', { text: name, url, isNewWindow: false });
            }
            inserted.push(file);
        }
    }

    return inserted;
}
