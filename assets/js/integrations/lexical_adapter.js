/**
 * Meta Lexical (Extensible Rich-Text Editor) adapter for LiveUX FileManagerBundle.
 *
 * Works with `lexical` 0.15+ / 0.20+ / 0.30+ (vanilla JS, Stimulus, React, or Symfony UX).
 *
 * Usage 1 — With a custom Lexical Command (recommended, e.g. INSERT_IMAGE_COMMAND):
 *   import { openFileManagerForLexical } from '@liveux/file-manager-bundle/js/integrations/lexical_adapter.js';
 *
 *   button.addEventListener('click', () => {
 *       openFileManagerForLexical(lexicalEditor, {
 *           profile: 'default',
 *           multiple: true,
 *           insertImageCommand: INSERT_IMAGE_COMMAND,
 *           insertLinkCommand: TOGGLE_LINK_COMMAND, // optional
 *       });
 *   });
 *
 * Usage 2 — With a custom callback (`onInsert`) for custom DecoratorNode / ImageNode:
 *   openFileManagerForLexical(lexicalEditor, {
 *       profile: 'default',
 *       onInsert: ({ url, alt, title, isImage, editor }) => { ... },
 *   });
 */
import { isImageUrl, isSafeUrl, openFromAdapter } from './_url_guard.js';

export async function openFileManagerForLexical(editor, options = {}) {
    if (!editor || typeof editor.dispatchCommand !== 'function') {
        console.error('[LiveUX FileManager] Invalid Lexical editor instance.');
        return [];
    }

    const files = await openFromAdapter(options);
    if (!Array.isArray(files) || files.length === 0) {
        return [];
    }

    const effectiveFiles = options.multiple ? files : files.slice(0, 1);
    const inserted = [];

    for (const file of effectiveFiles) {
        const rawUrl = file?.url || file?.path || '';
        if (!isSafeUrl(rawUrl)) {
            continue;
        }
        const url = String(rawUrl).trim();
        const alt = String(file.meta?.alt || file.meta?.title || file.name || '');
        const title = String(file.meta?.title || file.name || url);
        const isImg = isImageUrl(url) || Boolean(file.isImage);

        if (typeof options.onInsert === 'function') {
            options.onInsert({ file, url, alt, title, isImage: isImg, editor });
            inserted.push(file);
            continue;
        }

        if (isImg && options.insertImageCommand) {
            editor.dispatchCommand(options.insertImageCommand, {
                src: url,
                altText: alt,
                title,
            });
            inserted.push(file);
            continue;
        }

        if (!isImg && options.insertLinkCommand) {
            editor.dispatchCommand(options.insertLinkCommand, {
                url,
                title,
                rel: 'noopener noreferrer',
            });
            inserted.push(file);
            continue;
        }

        if (typeof editor.update === 'function' && typeof options.createNode === 'function') {
            editor.update(() => {
                const node = options.createNode({ file, url, alt, title, isImage: isImg });
                if (node && typeof options.insertNodes === 'function') {
                    options.insertNodes([node]);
                }
            });
            inserted.push(file);
        }
    }

    return inserted;
}
