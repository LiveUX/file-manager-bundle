/**
 * GrapesJS (Visual Page & Email Builder) adapter for LiveUX FileManagerBundle.
 *
 * Works with GrapesJS 0.20+ / 0.21+ / 0.22+ using either the native custom Asset Manager
 * bridge (`createGrapesJsAssetManagerConfig`) or programmatic insertion (`openFileManagerForGrapesJs`).
 *
 * Usage 1 — Native Custom Asset Manager (recommended):
 *   import { createGrapesJsAssetManagerConfig } from '@liveux/file-manager-bundle/js/integrations/grapesjs_adapter.js';
 *
 *   const editor = grapesjs.init({
 *       container: '#gjs',
 *       assetManager: createGrapesJsAssetManagerConfig({
 *           profile: 'default',
 *           multiple: false,
 *       }),
 *   });
 *
 * Usage 2 — Custom Toolbar Button / Programmatic Insertion:
 *   import { openFileManagerForGrapesJs } from '@liveux/file-manager-bundle/js/integrations/grapesjs_adapter.js';
 *
 *   button.addEventListener('click', () => {
 *       openFileManagerForGrapesJs(editor, { profile: 'default', multiple: true });
 *   });
 */
import { isImageUrl, isSafeUrl, openFromAdapter } from './_url_guard.js';

export function createGrapesJsAssetManagerConfig(options = {}) {
    return {
        custom: {
            async open(props = {}) {
                const { select, close, types } = props;
                const typeFilter = options.type
                    ?? (Array.isArray(types) && types.includes('image') && types.length === 1 ? 'image' : 'all');

                try {
                    const files = await openFromAdapter({
                        ...options,
                        type: typeFilter,
                    });

                    if (Array.isArray(files) && files.length > 0) {
                        const first = files[0];
                        const rawUrl = first?.url || first?.path || '';
                        if (isSafeUrl(rawUrl) && typeof select === 'function') {
                            const url = String(rawUrl).trim();
                            const alt = String(first.meta?.alt || first.meta?.title || first.name || '');
                            select({
                                src: url,
                                name: String(first.name || url),
                                alt,
                            }, true);
                        }
                    }
                } finally {
                    if (typeof close === 'function') {
                        close();
                    }
                }
            },
            close() {
                // DialogHost manages its own native <dialog> lifecycle.
            },
        },
    };
}

export async function openFileManagerForGrapesJs(editor, options = {}) {
    if (!editor || typeof editor.getSelected !== 'function') {
        console.error('[LiveUX FileManager] Invalid GrapesJS editor instance.');
        return [];
    }

    const files = await openFromAdapter(options);
    if (!Array.isArray(files) || files.length === 0) {
        return [];
    }

    const effectiveFiles = options.multiple ? files : files.slice(0, 1);
    const inserted = [];
    const selected = editor.getSelected();

    for (const file of effectiveFiles) {
        const rawUrl = file?.url || file?.path || '';
        if (!isSafeUrl(rawUrl)) {
            continue;
        }
        const url = String(rawUrl).trim();
        const alt = String(file.meta?.alt || file.meta?.title || file.name || '');
        const title = String(file.meta?.title || file.name || url);
        const isImg = isImageUrl(url) || Boolean(file.isImage);

        if (isImg) {
            if (editor.AssetManager && typeof editor.AssetManager.add === 'function') {
                editor.AssetManager.add({ src: url, name: title });
            }

            if (effectiveFiles.length === 1 && selected && typeof selected.is === 'function' && selected.is('image')) {
                if (typeof selected.set === 'function') {
                    selected.set('src', url);
                }
                if (alt && typeof selected.addAttributes === 'function') {
                    selected.addAttributes({ alt });
                }
            } else if (typeof editor.addComponents === 'function') {
                editor.addComponents({
                    type: 'image',
                    attributes: {
                        src: url,
                        alt,
                        style: 'max-width:100%;height:auto;border-radius:10px;',
                    },
                });
            }
            inserted.push(file);
        } else if (typeof editor.addComponents === 'function') {
            editor.addComponents({
                type: 'link',
                content: title,
                attributes: {
                    href: url,
                    rel: 'noopener noreferrer',
                },
            });
            inserted.push(file);
        }
    }

    return inserted;
}
