/**
 * Global Promise API for opening LiveUX FileManager in Modal or OffCanvas mode.
 * Works from TinyMCE, CKEditor, FormType pickers, or any custom JS code.
 *
 * Example:
 *   const files = await window.LiveUXFileManager.open({
 *       profile: 'products',
 *       mode: 'offcanvas', // 'modal' | 'offcanvas'
 *       position: 'end',   // 'start' | 'end' | 'top' | 'bottom'
 *       multiple: false,
 *   });
 */
export function openFileManager(options = {}) {
    const opts = options && typeof options === 'object' ? options : {};
    return new Promise((resolve, reject) => {
        const event = new CustomEvent('liveux:file-manager:open', {
            cancelable: true,
            detail: {
                profile: opts.profile || 'default',
                volume: opts.volume || null,
                path: opts.path || '',
                mode: opts.mode || 'modal',
                position: opts.position || 'end',
                mobileMode: opts.mobileMode || '',
                multiple: Boolean(opts.multiple),
                iconSet: opts.iconSet || '',
                locale: opts.locale || '',
                theme: opts.theme || '',
                type: opts.type || '',
                filetype: opts.filetype || '',
                resolve,
                reject,
            },
        });
        const unhandled = window.dispatchEvent(event);
        if (unhandled) {
            console.warn('[LiveUX FileManager] No DialogHost controller is mounted on this page.');
            resolve([]);
        }
    });
}

export function ensureFileManagerGlobal() {
    if (typeof window !== 'undefined' && (!window.LiveUXFileManager || typeof window.LiveUXFileManager.open !== 'function')) {
        window.LiveUXFileManager = Object.freeze({
            open: openFileManager,
        });
    }
}

if (typeof window !== 'undefined') {
    window.LiveUXFileManager = Object.freeze({
        open: openFileManager,
    });
}
