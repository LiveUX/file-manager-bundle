/**
 * TinyMCE 6 / 7 / 8 file_picker_callback adapter for LiveUX FileManagerBundle.
 *
 * Usage:
 *   import { createTinyMceFilePicker } from '@liveux/file-manager-bundle/js/integrations/tinymce_adapter.js';
 *
 *   tinymce.init({
 *       selector: 'textarea.editor',
 *       file_picker_types: 'file image media',
 *       file_picker_callback: createTinyMceFilePicker({
 *           profile: 'default',
 *           mode: 'modal',
 *       }),
 *   });
 */
import { openFileManager } from '../file_manager_api.js';

const INVISIBLE_FILLER_RE = /[\u00AD\u034F\u115F\u1160\u17B4\u17B5\u180B-\u180F\u200B-\u200F\u2060-\u2064\u2066-\u206F\u2800\u3164\uFE00-\uFE0F\uFEFF\uFFA0\uFFF9-\uFFFB]|[\u{E0000}-\u{E007F}\u{E0100}-\u{E01EF}]/gu;

function decodeValidTriplets(str) {
    return str.replace(/(?:%[0-9a-fA-F]{2})+/g, (seq) => {
        try {
            return decodeURIComponent(seq);
        } catch (_) {
            return seq;
        }
    });
}

function decodeSafePasses(str) {
    let once;
    try {
        once = decodeURIComponent(str);
    } catch (_) {
        return null;
    }
    const out = [str, once];
    const twice = decodeValidTriplets(once);
    if (twice !== once) {
        out.push(twice);
    }
    return out;
}

const CONTROL_OR_BIDI_RE = /[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/;

function areSegmentsDangerous(rawPath) {
    const passes = decodeSafePasses(rawPath);
    if (passes === null) {
        return true;
    }
    for (const cand of passes) {
        if (CONTROL_OR_BIDI_RE.test(cand) || /[\uFF0F\u2215\u2044\uFF3C]/.test(cand)) {
            return true;
        }
        const segs = cand.replace(/\\/g, '/').split('/');
        for (const seg of segs) {
            if (!seg) {
                continue;
            }
            const norm = seg
                .replace(INVISIBLE_FILLER_RE, '')
                .trim()
                .normalize('NFKC')
                .replace(/[\uFF0E\u3002\uFF61\uFE52\u2024]/g, '.')
                .toLowerCase();
            if (norm === '' || norm === '.' || norm === '..' || norm === '.trash' || norm.startsWith('.')) {
                return true;
            }
        }
    }
    return false;
}

function hasDangerousPathSegments(rawUrl) {
    const noHash = rawUrl.split('#')[0];
    const qIdx = noHash.indexOf('?');
    let rawPathPart = qIdx >= 0 ? noHash.slice(0, qIdx) : noHash;
    const rawQuery = qIdx >= 0 ? noHash.slice(qIdx + 1) : '';

    if (rawPathPart.includes('\\')) {
        return true;
    }

    if (/^https?:\/\//i.test(rawPathPart)) {
        const afterProto = rawPathPart.replace(/^https?:\/\//i, '');
        const slashIdx = afterProto.indexOf('/');
        rawPathPart = slashIdx >= 0 ? afterProto.slice(slashIdx) : '';
    }
    if (rawPathPart && areSegmentsDangerous(rawPathPart)) {
        return true;
    }
    if (rawQuery) {
        let pathParamCount = 0;
        for (const pair of rawQuery.split('&')) {
            if (!pair) {
                continue;
            }
            const eq = pair.indexOf('=');
            const rawKey = eq >= 0 ? pair.slice(0, eq) : pair;
            const rawVal = eq >= 0 ? pair.slice(eq + 1) : '';
            let decodedKey = rawKey;
            try {
                decodedKey = decodeURIComponent(rawKey.replace(/\+/g, '%20'));
            } catch (_) {
                return true;
            }
            const normRawKey = rawKey.trimStart().toLowerCase();
            const normDecodedKey = decodedKey.trimStart().toLowerCase();
            if (normRawKey.startsWith('path[') || normDecodedKey.startsWith('path[')) {
                return true;
            }
            if (normRawKey === 'path' || normDecodedKey === 'path') {
                pathParamCount += 1;
                if (pathParamCount > 1) {
                    return true;
                }
                const plusNormalized = rawVal.replace(/\+/g, '%20');
                if (plusNormalized && areSegmentsDangerous(plusNormalized)) {
                    return true;
                }
            }
        }
    }
    return false;
}

function isSafeUrl(url) {
    const raw = String(url ?? '');
    if (/[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/.test(raw)) {
        return false;
    }
    const s = raw.trim();
    if (!s || /(?:%0[0-9a-f]|%1[0-9a-f]|%7f|%25(?:0[0-9a-f]|1[0-9a-f]|7f))/i.test(s) || hasDangerousPathSegments(s)) {
        return false;
    }
    if (/^\/(?![\/\\\s]|%2[fF]|%5[cC]|%25(?:2[fF]|5[cC]))/.test(s)) {
        return true;
    }
    if (/^https?:\/\//i.test(s)) {
        try {
            const parsed = new URL(s);
            return (parsed.protocol === 'http:' || parsed.protocol === 'https:')
                && !parsed.username
                && !parsed.password;
        } catch (_) {
            return false;
        }
    }
    return false;
}

export function createTinyMceFilePicker(options = {}) {
    return async function liveuxTinyMceFilePicker(callback, _value, meta) {
        const openFn = (typeof window !== 'undefined' && typeof window.LiveUXFileManager?.open === 'function')
            ? window.LiveUXFileManager.open
            : openFileManager;

        const files = await openFn({
            profile: options.profile || 'default',
            volume: options.volume || null,
            path: options.path || '',
            mode: options.mode || 'modal',
            position: options.position || 'end',
            mobileMode: options.mobileMode || '',
            locale: options.locale || '',
            theme: options.theme || '',
            iconSet: options.iconSet || '',
            multiple: false,
            filetype: meta?.filetype || 'file',
        });

        if (!Array.isArray(files) || files.length === 0) {
            return;
        }

        const picked = files[0];
        const rawUrl = picked.url || picked.path || '';
        if (!isSafeUrl(rawUrl)) {
            return;
        }
        const url = String(rawUrl).trim();

        if (meta?.filetype === 'image') {
            callback(url, { alt: picked.meta?.alt || picked.name || '', title: picked.meta?.title || '' });
        } else if (meta?.filetype === 'media') {
            callback(url, { source2: '', poster: '' });
        } else {
            callback(url, { text: picked.meta?.title || picked.name || url });
        }
    };
}
