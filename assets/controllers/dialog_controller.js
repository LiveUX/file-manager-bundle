import { Controller } from '@hotwired/stimulus';
import { ensureFileManagerGlobal } from '../js/file_manager_api.js';

const ALLOWED_MODES = new Set(['modal', 'offcanvas']);
const ALLOWED_POSITIONS = new Set(['start', 'end', 'top', 'bottom']);
const ALLOWED_MOBILE_MODES = new Set(['auto', 'fullscreen', 'sheet']);
const DANGEROUS_TAGS = 'script,iframe,object,embed,base,meta,link,style,applet,frame,frameset,foreignobject,animate,animatemotion,animatetransform,animatecolor,set,feimage,handler,listener';

/**
 * Native HTML5 <dialog> Top Layer host controller (Lazy-Loaded).
 * Zero overhead on page load — fetches the browser UI only on first open().
 */
export default class extends Controller {
    static targets = ['dialog', 'body'];
    static values = {
        frameUrl: String,
        profile: { type: String, default: 'default' },
        errorMessage: { type: String, default: 'Failed to load File Manager.' },
        closeLabel: { type: String, default: 'Close' },
        loadingLabel: { type: String, default: 'Loading…' },
        zIndex: { type: Number, default: 1050 },
    };

    connect() {
        ensureFileManagerGlobal();
        this.pendingResolve = null;
        this.loadedKey = null;
        this.fetchController = null;
        this.isDisconnected = false;
        this.backdropMouseDown = false;
        this.previousActiveElement = null;
        this.initialDialogDir = this.hasDialogTarget !== false && this.dialogTarget
            ? (this.dialogTarget.getAttribute?.('dir') || this.dialogTarget.dir || '')
            : '';
        this.initialDialogLang = this.hasDialogTarget !== false && this.dialogTarget
            ? (this.dialogTarget.getAttribute?.('lang') || this.dialogTarget.lang || '')
            : '';
        const safeZ = Math.max(1, Number(this.zIndexValue) || 1050);
        if (this.element?.style && typeof this.element.style.setProperty === 'function') {
            this.element.style.setProperty('--liveux-fm-z-base', String(safeZ));
        }
        this.boundOpen = this.handleGlobalOpen.bind(this);
        this.boundSelected = this.handleSelected.bind(this);
        window.addEventListener('liveux:file-manager:open', this.boundOpen);
        this.element.addEventListener('liveux:file-manager:selected', this.boundSelected);
    }

    disconnect() {
        this.isDisconnected = true;
        if (this.fetchController) {
            this.fetchController.abort();
            this.fetchController = null;
        }
        if (this.pendingResolve) {
            this.pendingResolve([]);
            this.pendingResolve = null;
        }
        window.removeEventListener('liveux:file-manager:open', this.boundOpen);
        this.element.removeEventListener('liveux:file-manager:selected', this.boundSelected);
    }

    async handleGlobalOpen(event) {
        if (event?.defaultPrevented) {
            return;
        }
        if (typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        if (this.pendingResolve) {
            this.pendingResolve([]);
            this.pendingResolve = null;
        }

        const detail = event.detail || {};
        this.pendingResolve = typeof detail.resolve === 'function' ? detail.resolve : null;

        const rawMode = String(detail.mode || 'modal');
        const rawPos = String(detail.position || 'end');
        const rawMobileMode = String(detail.mobileMode || '').trim().toLowerCase();
        const mode = ALLOWED_MODES.has(rawMode) ? rawMode : 'modal';
        const position = ALLOWED_POSITIONS.has(rawPos) ? rawPos : 'end';
        const explicitMobileMode = ALLOWED_MOBILE_MODES.has(rawMobileMode) ? rawMobileMode : '';
        const profile = String(detail.profile || this.profileValue || 'default');
        const volume = String(detail.volume || '');
        const path = String(detail.path || '');
        const multiple = Boolean(detail.multiple);
        const iconSet = String(detail.iconSet || '');
        const locale = String(detail.locale || '');
        const theme = String(detail.theme || '');
        const type = this.resolveTypeFilter(detail.type, detail.filetype);

        const dialog = this.dialogTarget;
        if (!dialog.open && typeof document !== 'undefined' && document.activeElement && !dialog.contains?.(document.activeElement)) {
            this.previousActiveElement = document.activeElement;
        }
        dialog.removeAttribute('data-fullscreen');
        dialog.setAttribute('data-mode', mode);
        dialog.setAttribute('data-position', position);
        dialog.setAttribute('data-mobile-mode', explicitMobileMode || 'auto');
        this.applyDialogLocale(locale);

        if (!dialog.open) {
            dialog.showModal();
        }

        if (!this.isSafeSameOriginPath(this.frameUrlValue)) {
            this.renderError(this.errorMessageValue);
            return;
        }

        const cacheKey = `${profile}|${volume}|${path}|${mode}|${multiple ? '1' : '0'}|${iconSet}|${locale}|${theme}|${type}`;
        if (this.loadedKey === cacheKey) {
            const innerBrowser = this.bodyTarget?.querySelector?.('[data-controller~="liveux--file-manager-bundle--browser"]');
            if (innerBrowser) {
                if (!explicitMobileMode) {
                    const profileMobileMode = String(innerBrowser.getAttribute?.('data-mobile-dialog-mode') || '').trim().toLowerCase();
                    if (ALLOWED_MOBILE_MODES.has(profileMobileMode)) {
                        dialog.setAttribute('data-mobile-mode', profileMobileMode);
                    }
                }
                if (!locale) {
                    const innerDir = innerBrowser.getAttribute?.('dir') || innerBrowser.dir || '';
                    const innerLang = innerBrowser.getAttribute?.('lang') || innerBrowser.lang || '';
                    if (innerDir === 'rtl' || innerDir === 'ltr') {
                        dialog.dir = innerDir;
                        dialog.setAttribute?.('dir', innerDir);
                    }
                    if (innerLang) {
                        dialog.lang = innerLang;
                        dialog.setAttribute?.('lang', innerLang);
                    }
                }
            }
            innerBrowser?.dispatchEvent?.(new CustomEvent('liveux:file-manager:dialog-opened', {
                detail: {
                    profile,
                    volume,
                    explicitVolume: volume !== '',
                    path,
                    type,
                    multiple,
                    mode,
                    locale,
                },
            }));
            this.focusDialogInitial();
            return;
        }

        if (this.fetchController) {
            this.fetchController.abort();
        }
        const controller = new AbortController();
        this.fetchController = controller;
        // Clear any previously loaded profile color-scheme override before rendering loading/error UI.
        dialog.removeAttribute?.('data-fm-color-scheme');
        // Body is replaced by the spinner: a reopen with the same key must reload, not trust the stale key.
        this.loadedKey = null;

        this.renderLoading();
        const params = new URLSearchParams({
            profile,
            mode,
            multiple: multiple ? '1' : '0',
            volume,
            path,
            iconSet,
            locale,
            theme,
            type,
        });

        try {
            const sep = this.frameUrlValue.includes('?') ? '&' : '?';
            const res = await fetch(`${this.frameUrlValue}${sep}${params.toString()}`, {
                signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (this.isDisconnected || controller.signal.aborted || this.fetchController !== controller) {
                return;
            }
            if (!res.ok) {
                const bodyText = (await res.text().catch(() => '')).trim();
                if (this.isDisconnected || controller.signal.aborted || this.fetchController !== controller) {
                    return;
                }
                const safeServerMsg = bodyText && !bodyText.startsWith('<') && bodyText.length < 300
                    ? bodyText
                    : this.errorMessageValue;
                throw new Error(safeServerMsg);
            }
            const contentType = String(res.headers.get('content-type') || '').toLowerCase();
            if (res.headers.get('x-liveux-fm-dialog') !== '1' || !contentType.includes('text/html')) {
                throw new Error(this.errorMessageValue);
            }
            const sanitizedFragment = this.sanitizeHtmlFragment(await res.text());
            if (this.isDisconnected || controller.signal.aborted || this.fetchController !== controller || this.hasBodyTarget === false || !this.bodyTarget) {
                return;
            }
            const browserRoot = sanitizedFragment.querySelector?.('[data-controller~="liveux--file-manager-bundle--browser"]');
            if (browserRoot && browserRoot.dataset) {
                browserRoot.dataset.explicitVolume = volume !== '' ? '1' : '0';
                browserRoot.dataset.requestedVolume = volume;
                browserRoot.dataset.requestedPath = path;
                browserRoot.dataset.requestedType = type;
            }
            if (browserRoot) {
                if (!explicitMobileMode) {
                    const profileMobileMode = String(browserRoot.getAttribute?.('data-mobile-dialog-mode') || '').trim().toLowerCase();
                    if (ALLOWED_MOBILE_MODES.has(profileMobileMode)) {
                        dialog.setAttribute('data-mobile-mode', profileMobileMode);
                    }
                }
                if (!locale) {
                    const innerDir = browserRoot.getAttribute?.('dir') || browserRoot.dir || '';
                    const innerLang = browserRoot.getAttribute?.('lang') || browserRoot.lang || '';
                    if (innerDir === 'rtl' || innerDir === 'ltr') {
                        dialog.dir = innerDir;
                        dialog.setAttribute?.('dir', innerDir);
                    }
                    if (innerLang) {
                        dialog.lang = innerLang;
                        dialog.setAttribute?.('lang', innerLang);
                    }
                }
            }
            this.bodyTarget.replaceChildren(sanitizedFragment);
            this.loadedKey = cacheKey;
            this.focusDialogInitial();
        } catch (err) {
            if (err?.name === 'AbortError' || controller.signal.aborted || this.fetchController !== controller || this.isDisconnected || this.hasBodyTarget === false || !this.bodyTarget) {
                return;
            }
            this.renderError(err?.message || this.errorMessageValue);
        } finally {
            if (this.fetchController === controller) {
                this.fetchController = null;
            }
        }
    }

    open(options = {}) {
        return this.handleGlobalOpen({ detail: options });
    }

    resolveBcp47Locale(rawLocale) {
        const cleaned = String(rawLocale || '')
            .trim()
            .split(/[.#@]/)[0]
            .replace(/_/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
        if (!cleaned) {
            return '';
        }
        try {
            if (typeof Intl !== 'undefined' && typeof Intl.getCanonicalLocales === 'function') {
                const canonical = Intl.getCanonicalLocales(cleaned);
                return Array.isArray(canonical) && canonical.length > 0 ? canonical[0] : '';
            }
        } catch (_) {
            return '';
        }
        return '';
    }

    isRtlLocale(rawLocale) {
        const cleaned = String(rawLocale || '')
            .trim()
            .split(/[.#@]/)[0]
            .replace(/_/g, '-');
        if (!cleaned) {
            return false;
        }
        const parts = cleaned.split('-').filter(Boolean);
        if (parts.length === 0) {
            return false;
        }
        const lang = parts[0].toLowerCase();
        const rtlScripts = new Set(['arab', 'hebr', 'thaa', 'syrc', 'nkoo', 'adlm', 'rohg', 'mand', 'mend', 'samr']);
        const ltrScripts = new Set([
            'latn', 'cyrl', 'deva', 'guru', 'hans', 'hant', 'grek', 'armn', 'geor',
            'ethi', 'thai', 'laoo', 'khmr', 'mymr', 'hang', 'jpan', 'kana', 'hira', 'kore',
            'taml', 'telu', 'knda', 'mlym', 'sinh', 'beng', 'gujr', 'orya', 'tibt',
        ]);
        for (let i = 1; i < parts.length; i += 1) {
            const sub = parts[i].toLowerCase();
            if (sub.length === 4) {
                if (rtlScripts.has(sub)) {
                    return true;
                }
                if (ltrScripts.has(sub)) {
                    return false;
                }
            }
        }
        const rtlLangs = new Set(['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'khw', 'ks', 'ps', 'sd', 'syr', 'ug', 'ur', 'yi']);
        return rtlLangs.has(lang);
    }

    applyDialogLocale(rawLocale) {
        const dialog = this.dialogTarget;
        if (!dialog) {
            return;
        }
        const trimmed = String(rawLocale || '').trim();
        if (trimmed) {
            const dir = this.isRtlLocale(trimmed) ? 'rtl' : 'ltr';
            const bcp = this.resolveBcp47Locale(trimmed);
            dialog.dir = dir;
            dialog.setAttribute?.('dir', dir);
            if (bcp) {
                dialog.lang = bcp;
                dialog.setAttribute?.('lang', bcp);
            }
            return;
        }
        const docEl = typeof document !== 'undefined' ? document.documentElement : null;
        const fallbackRawDir = String(this.initialDialogDir || docEl?.getAttribute?.('dir') || docEl?.dir || 'ltr').toLowerCase();
        const dir = fallbackRawDir === 'rtl' ? 'rtl' : 'ltr';
        const fallbackRawLang = this.initialDialogLang || docEl?.getAttribute?.('lang') || docEl?.lang || '';
        const bcp = this.resolveBcp47Locale(fallbackRawLang);
        dialog.dir = dir;
        dialog.setAttribute?.('dir', dir);
        if (bcp) {
            dialog.lang = bcp;
            dialog.setAttribute?.('lang', bcp);
        }
    }

    resolveTypeFilter(rawType, rawFiletype) {
        const direct = String(rawType || '').trim().toLowerCase();
        if (['all', 'folder', 'image', 'doc', 'video'].includes(direct)) {
            return direct;
        }
        const ft = String(rawFiletype || '').trim().toLowerCase();
        if (ft === 'image') {
            return 'image';
        }
        if (ft === 'media') {
            return 'video';
        }
        return 'all';
    }

    createFallbackCloseButton() {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'liveux-fm-dialog-fallback-close';
        const label = this.closeLabelValue || 'Close';
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
        btn.textContent = '✕';
        if (typeof btn.addEventListener === 'function') {
            btn.addEventListener('click', () => this.close());
        }
        return btn;
    }

    renderLoading() {
        if (this.hasBodyTarget === false || !this.bodyTarget) {
            return;
        }
        const loadingLabel = this.loadingLabelValue || 'Loading…';
        if (typeof document !== 'undefined' && typeof document.createElement === 'function' && typeof this.bodyTarget.appendChild === 'function') {
            this.bodyTarget.innerHTML = '';
            const wrap = document.createElement('div');
            wrap.className = 'liveux-fm-dialog-loading';
            const closeBtn = this.createFallbackCloseButton();
            const spinner = document.createElement('span');
            spinner.className = 'liveux-fm-spinner is-lg';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-label', loadingLabel);
            wrap.appendChild(closeBtn);
            wrap.appendChild(spinner);
            this.bodyTarget.appendChild(wrap);
            return;
        }
        this.bodyTarget.innerHTML = '<div class="liveux-fm-dialog-loading"><span class="liveux-fm-spinner is-lg" role="status" aria-label="Loading…"></span></div>';
    }

    renderError(message) {
        if (this.hasBodyTarget === false || !this.bodyTarget) {
            return;
        }
        const errCard = document.createElement('div');
        errCard.className = 'liveux-fm-dialog-error';
        errCard.textContent = message || this.errorMessageValue;
        const closeBtn = this.createFallbackCloseButton();
        if (typeof errCard.appendChild === 'function') {
            errCard.appendChild(closeBtn);
        }
        this.bodyTarget.replaceChildren(errCard);
        if (typeof closeBtn.focus === 'function') {
            try {
                closeBtn.focus({ preventScroll: true });
            } catch (_) {
                closeBtn.focus();
            }
        }
    }

    isSafeSameOriginPath(url) {
        const controlOrBidiRe = /[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/;
        const raw = String(url ?? '');
        if (controlOrBidiRe.test(raw)) {
            return false;
        }
        const s = raw.trim();
        if (!s || /(?:%0[0-9a-f]|%1[0-9a-f]|%7f|%25(?:0[0-9a-f]|1[0-9a-f]|7f))/i.test(s)) {
            return false;
        }
        if (!/^\/(?![\/\\\s]|%2[fF]|%5[cC]|%25(?:2[fF]|5[cC]))/.test(s)) {
            return false;
        }
        const decodeTriplets = (str) => str.replace(/(?:%[0-9a-fA-F]{2})+/g, (seq) => {
            try {
                return decodeURIComponent(seq);
            } catch (_) {
                return seq;
            }
        });
        const once = decodeTriplets(s);
        const twice = decodeTriplets(once);
        if (controlOrBidiRe.test(once) || controlOrBidiRe.test(twice)) {
            return false;
        }
        return true;
    }

    hasUnsafeCssValue(rawCss) {
        const raw = String(rawCss ?? '');
        if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(raw)) {
            return true;
        }
        // Reject raw CSS if an unquoted url(...) contains /* or */ (W3C CSS comment-lexer desynchronization)
        if (/url\s*\([^)]*(?:\/\*|\*\/)/i.test(raw)) {
            return true;
        }
        const hasDangerousTokens = (css) => {
            const compact = String(css || '').replace(/[\u0000-\u0020]+/g, '');
            if (/@import|javascript:|vbscript:|expression\(|-moz-binding|behavior:|data:text\/html|data:image\/svg|(?:-webkit-)?image-set\(|(?:-webkit-)?cross-fade\(|(?:-moz-)?element\(|data:/i.test(compact)) {
                return true;
            }
            return /(?:url|src|image)\((?!(["']?)#[a-z0-9_.:-]+\1\))/i.test(compact);
        };
        const decodeCssEscapes = (s) => String(s || '')
            .replace(/\\(?:\r\n|[\r\n\f])/g, '')
            .replace(/\\([0-9a-fA-F]{1,6})\s?|\\([^\r\n\f0-9a-fA-F])/g, (_, hex, ch) => {
                if (hex) {
                    const cp = parseInt(hex, 16);
                    return cp > 0 && cp <= 0x10FFFF ? String.fromCodePoint(cp) : '\uFFFD';
                }
                return ch || '';
            });
        // W3C CSS Syntax §4.3.5: unescaped newlines terminate strings (bad-string token), never span lines.
        const stripCssCommentsPreservingStrings = (s) => String(s || '').replace(
            /"[^"\\\r\n\f]*(?:\\.[^"\\\r\n\f]*)*"|'[^'\\\r\n\f]*(?:\\.[^'\\\r\n\f]*)*'|\\.|\/\*[\s\S]*?\*\//g,
            (m) => (m.startsWith('/*') ? ' ' : m),
        );
        const cssStringRe = /"[^"\\\r\n\f]*(?:\\.[^"\\\r\n\f]*)*"|'[^'\\\r\n\f]*(?:\\.[^'\\\r\n\f]*)*'/g;

        const rawDecoded = decodeCssEscapes(raw);
        const withoutComments = stripCssCommentsPreservingStrings(raw);
        const withoutStrings = withoutComments.replace(cssStringRe, '');
        if (
            withoutStrings.includes('/*')
            || withoutStrings.includes('*/')
            || withoutStrings.includes('"')
            || withoutStrings.includes("'")
        ) {
            return true;
        }
        const rawDecodedWithoutComments = stripCssCommentsPreservingStrings(rawDecoded);
        const rawDecodedWithoutStrings = rawDecodedWithoutComments.replace(cssStringRe, '');
        if (rawDecodedWithoutStrings.includes('/*') || rawDecodedWithoutStrings.includes('*/')) {
            return true;
        }
        const decoded = decodeCssEscapes(withoutComments);
        const decodedAgain = decodeCssEscapes(decoded);
        if (decoded.replace(cssStringRe, '').includes('/*')) {
            return true;
        }
        return hasDangerousTokens(raw)
            || hasDangerousTokens(rawDecoded)
            || hasDangerousTokens(withoutComments)
            || hasDangerousTokens(decoded)
            || hasDangerousTokens(decodedAgain)
            || hasDangerousTokens(rawDecodedWithoutComments);
    }

    sanitizeHtmlFragment(html) {
        const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
        doc.body.querySelectorAll(DANGEROUS_TAGS).forEach((el) => el.remove());

        const svgPresentationAttrs = new Set([
            'style', 'filter', 'fill', 'stroke', 'clip-path', 'mask', 'cursor',
            'marker', 'marker-start', 'marker-mid', 'marker-end', 'background',
        ]);

        const scrubNodeTree = (root) => {
            root.querySelectorAll('*').forEach((el) => {
                for (const attr of Array.from(el.attributes)) {
                    const name = attr.name.toLowerCase();
                    const localName = String(attr.localName || name.split(':').pop() || '').toLowerCase();
                    const val = attr.value.trim();
                    const compactVal = val.replace(/[\u0000-\u0020]+/g, '');
                    if (name.startsWith('on') || localName.startsWith('on') || name === 'srcdoc' || localName === 'srcdoc' || name === 'xml:base' || localName === 'base') {
                        el.removeAttribute(attr.name);
                        continue;
                    }
                    if (
                        (svgPresentationAttrs.has(name) || svgPresentationAttrs.has(localName))
                        && this.hasUnsafeCssValue(attr.value)
                    ) {
                        el.removeAttribute(attr.name);
                        continue;
                    }
                    // NOTE: data-action is intentionally preserved. The fragment comes from our own same-origin,
                    // header-verified endpoint and Stimulus actions only invoke methods of registered controllers
                    // (no arbitrary code), so stripping it would break the browser UI. Unsafe URL/js values are still scrubbed below.
                    if (/javascript:|vbscript:|data:text\/html/i.test(compactVal) && (name === 'data-action' || name === 'formaction' || name === 'action' || name === 'href' || name === 'src' || name === 'xlink:href' || localName === 'href' || localName === 'src')) {
                        el.removeAttribute(attr.name);
                        continue;
                    }
                    if (
                        (name === 'href' || name === 'src' || name === 'xlink:href' || name === 'formaction' || name === 'action' || name === 'poster' || name === 'srcset' || name === 'imagesrcset' || localName === 'href' || localName === 'src')
                        && /^(javascript|vbscript|data):/i.test(compactVal)
                    ) {
                        el.removeAttribute(attr.name);
                        continue;
                    }
                    if ((name === 'srcset' || name === 'imagesrcset') && /(?:javascript|vbscript|data:text\/html|data:image\/svg):?/i.test(compactVal)) {
                        el.removeAttribute(attr.name);
                        continue;
                    }
                    if (
                        (el.localName === 'use' || el.localName === 'image')
                        && (name === 'href' || name === 'xlink:href' || localName === 'href')
                        && !/^#[a-zA-Z0-9_.:-]+$/.test(val)
                    ) {
                        el.removeAttribute(attr.name);
                    }
                }
                if (el.tagName === 'TEMPLATE' && el.content) {
                    el.content.querySelectorAll(DANGEROUS_TAGS).forEach((n) => n.remove());
                    scrubNodeTree(el.content);
                }
            });
        };

        scrubNodeTree(doc.body);
        const frag = document.createDocumentFragment();
        while (doc.body.firstChild) {
            frag.appendChild(doc.body.firstChild);
        }
        return frag;
    }

    handleSelected(event) {
        const files = event.detail?.files || [];
        const resolve = this.pendingResolve;
        this.pendingResolve = null;
        this.close();
        if (resolve) {
            resolve(files);
        }
    }

    onBackdropMouseDown(event) {
        this.backdropMouseDown = event.target === this.dialogTarget;
    }

    backdropClick(event) {
        const startedOnBackdrop = this.backdropMouseDown !== false;
        this.backdropMouseDown = false;
        if (startedOnBackdrop && event.target === this.dialogTarget) {
            this.close();
        }
    }

    onDialogCancel(event) {
        const innerBrowser = this.bodyTarget?.querySelector?.('[data-controller~="liveux--file-manager-bundle--browser"]');
        if (innerBrowser && typeof innerBrowser.dispatchEvent === 'function') {
            const cancelEvt = new CustomEvent('liveux:file-manager:dialog-cancel', { cancelable: true });
            const allowed = innerBrowser.dispatchEvent(cancelEvt);
            if (!allowed) {
                event.preventDefault();
            }
        }
    }

    focusDialogInitial() {
        const focusable = this.bodyTarget?.querySelector?.(
            'button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
        );
        if (focusable && typeof focusable.focus === 'function') {
            try {
                focusable.focus({ preventScroll: true });
            } catch (_) {
                focusable.focus();
            }
            return;
        }
        if (this.hasDialogTarget !== false && this.dialogTarget && typeof this.dialogTarget.focus === 'function') {
            if (!this.dialogTarget.hasAttribute?.('tabindex')) {
                this.dialogTarget.setAttribute?.('tabindex', '-1');
            }
            this.dialogTarget.focus();
        }
    }

    onDialogClose(event = null) {
        // Native HTMLDialogElement dispatches 'close' asynchronously after dialog.close().
        // If caller code immediately reopened the dialog before the queued task ran, ignore the stale close event.
        if (event?.type === 'close' && this.hasDialogTarget !== false && this.dialogTarget && this.dialogTarget.open) {
            return;
        }
        if (this.fetchController) {
            this.fetchController.abort();
            this.fetchController = null;
            this.loadedKey = null;
        }
        if (this.pendingResolve) {
            this.pendingResolve([]);
            this.pendingResolve = null;
        }
        const prev = this.previousActiveElement;
        this.previousActiveElement = null;
        if (prev && prev.isConnected !== false && typeof prev.focus === 'function') {
            try {
                prev.focus({ preventScroll: true });
            } catch (_) {
                try { prev.focus(); } catch (__) { }
            }
        }
    }

    close() {
        if (this.hasDialogTarget !== false && this.dialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        this.onDialogClose();
    }
}
