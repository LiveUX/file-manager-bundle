import { Controller } from '@hotwired/stimulus';
import { openFileManager, ensureFileManagerGlobal } from '../js/file_manager_api.js';

const IMAGE_REGEX = /\.(jpe?g|png|gif|webp|avif|svg|bmp|ico)(\?.*)?$/i;

/**
 * Stimulus controller for FileManagerPickerType (Single & Multiple Gallery)
 * supporting all 9 visual themes (including unstyled) and 5 visual variants (compact, card, dropzone, avatar, input).
 */
export default class extends Controller {
    static targets = ['input', 'singlePreview', 'fileLabel', 'clearBtn', 'galleryGrid', 'countLabel', 'dropzone'];
    static values = {
        profile: { type: String, default: 'default' },
        locale: { type: String, default: '' },
        volume: { type: String, default: '' },
        theme: { type: String, default: 'bootstrap5' },
        variant: { type: String, default: 'compact' },
        multiple: { type: Boolean, default: false },
        maxFiles: { type: Number, default: 0 },
        mode: { type: String, default: 'modal' },
        position: { type: String, default: 'end' },
        mobileMode: { type: String, default: '' },
        returnType: { type: String, default: 'url' },
        preview: { type: Boolean, default: true },
        previewUrlPrefix: { type: String, default: '' },
        thumbUrl: { type: String, default: '' },
        fileLabel: { type: String, default: 'FILE' },
        placeholder: { type: String, default: 'No file selected…' },
        buttonLabel: { type: String, default: 'Select' },
        removeLabel: { type: String, default: 'Remove' },
        movePrevLabel: { type: String, default: 'Previous' },
        moveNextLabel: { type: String, default: 'Next' },
    };

    connect() {
        ensureFileManagerGlobal();
        this.isDisconnected = false;
        this.isRendering = false;
        this.syncScheduled = false;
        this.lastSyncedSignature = null;
        this.liveRoot = null;
        this.liveComponent = null;
        this.boundSyncView = () => this.scheduleSync();
        this.boundLiveConnect = (e) => {
            if (this.liveRoot && e?.target && e.target !== this.liveRoot) {
                return;
            }
            const comp = e?.detail?.component || this.liveRoot?.__component;
            this.bindLiveComponentHook(comp);
            this.scheduleSync();
        };

        this.syncView(true);
        this.attachLiveAndMorphObservers();
    }

    disconnect() {
        this.isDisconnected = true;
        if (this.mutationObserver) {
            this.mutationObserver.disconnect();
            this.mutationObserver = null;
        }
        if (this.liveRoot && this.boundLiveConnect) {
            this.liveRoot.removeEventListener('live:connect', this.boundLiveConnect);
        }
        if (this.liveComponent && typeof this.liveComponent.off === 'function' && this.boundSyncView) {
            try {
                this.liveComponent.off('render:finished', this.boundSyncView);
            } catch (_) { }
        }
        this.liveRoot = null;
        this.liveComponent = null;
    }

    inputTargetConnected(el) {
        if (this.mutationObserver && el) {
            this.mutationObserver.observe(el, {
                attributes: true,
                attributeFilter: ['value', 'disabled'],
            });
        }
        this.scheduleSync();
    }

    galleryGridTargetConnected(el) {
        if (this.mutationObserver && el) {
            this.mutationObserver.observe(el, { childList: true });
        }
        this.scheduleSync();
    }

    singlePreviewTargetConnected(el) {
        if (this.mutationObserver && el) {
            this.mutationObserver.observe(el, { childList: true });
        }
        this.scheduleSync();
    }

    attachLiveAndMorphObservers() {
        if (!this.element || typeof this.element.closest !== 'function') {
            return;
        }

        // 1. Symfony UX LiveComponent / LiveCollectionType hook (Idiomorph re-renders)
        const liveRoot = this.element.closest('[data-controller~="live"]');
        if (liveRoot) {
            this.liveRoot = liveRoot;
            if (liveRoot.__component) {
                this.bindLiveComponentHook(liveRoot.__component);
            }
            liveRoot.addEventListener('live:connect', this.boundLiveConnect);
        }

        // 2. Lightweight MutationObserver fallback ONLY when inside a morphing container or input value/disabled changes
        if (typeof MutationObserver === 'function') {
            this.mutationObserver = new MutationObserver(() => {
                if (this.isRendering || !this.needsDomSync()) {
                    return;
                }
                this.scheduleSync();
            });
            if (this.hasInputTarget) {
                this.mutationObserver.observe(this.inputTarget, {
                    attributes: true,
                    attributeFilter: ['value', 'disabled'],
                });
            }
            if (this.hasGalleryGridTarget) {
                this.mutationObserver.observe(this.galleryGridTarget, { childList: true });
            }
            if (this.hasSinglePreviewTarget) {
                this.mutationObserver.observe(this.singlePreviewTarget, { childList: true });
            }
        }
    }

    bindLiveComponentHook(component) {
        if (!component || component === this.liveComponent || typeof component.on !== 'function') {
            return;
        }
        if (this.liveComponent && typeof this.liveComponent.off === 'function' && this.boundSyncView) {
            try {
                this.liveComponent.off('render:finished', this.boundSyncView);
            } catch (_) { }
        }
        this.liveComponent = component;
        component.on('render:finished', this.boundSyncView);
    }

    isDisabled() {
        return Boolean(
            this.hasInputTarget && (
                this.inputTarget.disabled
                || (typeof this.inputTarget.matches === 'function' && this.inputTarget.matches(':disabled'))
            ),
        );
    }

    onDropzoneKeydown(event) {
        if (event.isComposing || event.keyCode === 229) {
            return;
        }
        if (event.target !== event.currentTarget) {
            // Enter/Space on inner Select/Clear buttons must keep their native behaviour.
            return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.open();
        }
    }

    needsDomSync() {
        if (!this.hasInputTarget) {
            return false;
        }
        const currentVal = String(this.inputTarget.value ?? '');
        const sig = `${this.isDisabled() ? '1' : '0'}|${currentVal}`;
        if (sig !== this.lastSyncedSignature) {
            return true;
        }
        if (!this.multipleValue && this.hasClearBtnTarget && this.clearBtnTarget.disabled !== (this.isDisabled() || currentVal.trim() === '')) {
            return true;
        }
        if (this.multipleValue && this.hasGalleryGridTarget) {
            return this.galleryGridTarget.dataset?.fmSynced !== sig;
        }
        if (!this.multipleValue && this.hasSinglePreviewTarget) {
            return this.singlePreviewTarget.dataset?.fmSynced !== sig;
        }
        return false;
    }

    scheduleSync() {
        if (this.syncScheduled || this.isDisconnected) {
            return;
        }
        this.syncScheduled = true;
        queueMicrotask(() => {
            this.syncScheduled = false;
            if (!this.isDisconnected && this.hasInputTarget && this.needsDomSync()) {
                this.syncView(false);
            }
        });
    }

    syncView(force = false) {
        if (!this.hasInputTarget) {
            return;
        }
        const currentVal = String(this.inputTarget.value ?? '');
        const disabled = this.isDisabled();
        const sig = `${disabled ? '1' : '0'}|${currentVal}`;
        if (!force && !this.needsDomSync()) {
            return;
        }
        this.isRendering = true;
        try {
            if (this.element?.classList) {
                this.element.classList.toggle('is-disabled', disabled);
            }
            if (this.multipleValue) {
                this.renderGallery();
                if (this.hasGalleryGridTarget && this.galleryGridTarget.dataset) {
                    this.galleryGridTarget.dataset.fmSynced = sig;
                }
            } else {
                this.renderSinglePreview();
                if (this.hasSinglePreviewTarget && this.singlePreviewTarget.dataset) {
                    this.singlePreviewTarget.dataset.fmSynced = sig;
                }
            }
            this.lastSyncedSignature = sig;
        } finally {
            // Drain mutation records caused by our own innerHTML write BEFORE re-enabling observer!
            if (this.mutationObserver && typeof this.mutationObserver.takeRecords === 'function') {
                this.mutationObserver.takeRecords();
            }
            this.isRendering = false;
        }
    }

    notifyInputUpdated() {
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async open() {
        if (this.isDisabled()) {
            return;
        }
        if (this.multipleValue && this.maxFilesValue > 0 && this.readGalleryValues().length >= this.maxFilesValue) {
            return;
        }
        const openFn = (typeof window !== 'undefined' && typeof window.LiveUXFileManager?.open === 'function')
            ? window.LiveUXFileManager.open
            : openFileManager;

        const files = await openFn({
            profile: this.profileValue,
            locale: this.localeValue || '',
            volume: this.volumeValue || null,
            theme: this.themeValue || '',
            mode: this.modeValue,
            position: this.positionValue,
            mobileMode: this.mobileModeValue || '',
            multiple: this.multipleValue,
        });

        if (this.isDisconnected || this.isDisabled() || !this.hasInputTarget || !Array.isArray(files) || files.length === 0) {
            return;
        }

        const pickedValues = files
            .map((f) => (this.returnTypeValue === 'path' ? f.path : (f.url || f.path)))
            .filter((val) => {
                if (typeof val !== 'string' || val.trim() === '') {
                    return false;
                }
                if (this.returnTypeValue === 'path') {
                    return this.isSafeRelativePath(val);
                }
                if (/^data:/i.test(val.trim())) {
                    return false;
                }
                return this.isSafeUrl(val);
            })
            .map((val) => val.trim());

        if (pickedValues.length === 0) {
            return;
        }

        if (this.multipleValue) {
            const current = this.readGalleryValues();
            const merged = [...current];
            pickedValues.forEach((val) => {
                if (!merged.includes(val)) {
                    merged.push(val);
                }
            });
            const capped = this.maxFilesValue > 0 ? merged.slice(0, this.maxFilesValue) : merged;
            this.writeGalleryValues(capped);
            this.syncView();
        } else {
            this.inputTarget.value = pickedValues[0];
            this.notifyInputUpdated();
            this.syncView();
            this.dispatchChanged([pickedValues[0]]);
        }
    }

    clearSingle() {
        if (this.isDisabled()) {
            return;
        }
        this.inputTarget.value = '';
        this.notifyInputUpdated();
        this.syncView();
        this.dispatchChanged([]);
    }

    removeGalleryItem(event) {
        if (this.isDisabled()) {
            return;
        }
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        const list = this.readGalleryValues();
        if (!Number.isNaN(idx) && idx >= 0 && idx < list.length) {
            list.splice(idx, 1);
            this.writeGalleryValues(list);
            this.syncView();
        }
    }

    safeDecodeUriComponent(str) {
        try {
            return decodeURIComponent(str);
        } catch (_) {
            return str;
        }
    }

    decodeQueryParam(rawParam) {
        const normalized = String(rawParam || '').replace(/\+/g, '%20');
        try {
            return decodeURIComponent(normalized);
        } catch (_) {
            return normalized
                .replace(/%2f/gi, '/')
                .replace(/%5c/gi, '/')
                .split('/')
                .map((part) => this.safeDecodeUriComponent(part))
                .join('/');
        }
    }

    isUrlValue(raw) {
        return /^https?:\/\//i.test(raw)
            || /^\/(?![\/\\])/.test(raw)
            || /^[a-z][a-z0-9+.-]*:/i.test(raw)
            || /[?&]path=/i.test(raw);
    }

    extractFilename(val) {
        const raw = String(val || '').trim();
        if (!raw) {
            return '';
        }
        if (!this.isUrlValue(raw)) {
            const noQuery = raw.replace(/[?#].*$/, '');
            const parts = noQuery.split(/[\\/]/).filter(Boolean);
            return parts.length > 0 ? parts[parts.length - 1] : noQuery;
        }

        const stripControls = (name) => String(name ?? '')
            .replace(/[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]+/g, '')
            .trim();

        const pathParamMatch = raw.match(/[?&]path=([^&#]*)/i);
        if (pathParamMatch && pathParamMatch[1]) {
            const decodedPath = this.decodeQueryParam(pathParamMatch[1]);
            const parts = decodedPath.split(/[\\/]/).filter(Boolean);
            if (parts.length > 0) {
                return stripControls(parts[parts.length - 1]);
            }
        }

        try {
            const parsed = new URL(raw, 'http://localhost');
            const queryPath = parsed.searchParams.get('path');
            if (queryPath) {
                const seg = queryPath.split(/[\\/]/).filter(Boolean).pop();
                if (seg) {
                    return stripControls(seg);
                }
            }
            const seg = (parsed.pathname || '').split('/').filter(Boolean).pop();
            if (seg) {
                return stripControls(this.safeDecodeUriComponent(seg));
            }
        } catch (_) {
            const noQuery = raw.split(/[?#]/)[0];
            const seg = noQuery.split('/').filter(Boolean).pop();
            if (seg) {
                return stripControls(this.safeDecodeUriComponent(seg));
            }
        }
        return stripControls(raw);
    }

    extractExtension(val) {
        const fallback = this.fileLabelValue || 'FILE';
        const name = this.extractFilename(val);
        const dot = name.lastIndexOf('.');
        if (dot <= 0 || dot === name.length - 1) {
            return fallback;
        }
        const rawExt = name.slice(dot + 1).toUpperCase();
        if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
            try {
                const segs = Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(rawExt), (s) => s.segment);
                return segs.slice(0, 5).join('') || fallback;
            } catch (_) { }
        }
        return Array.from(rawExt).slice(0, 5).join('') || fallback;
    }

    isImageValue(val) {
        const raw = String(val || '').trim();
        if (!raw) {
            return false;
        }
        if (/^data:image\/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=]+$/i.test(raw)) {
            return true;
        }
        const name = this.extractFilename(raw);
        if (IMAGE_REGEX.test(name)) {
            return true;
        }
        if (this.isUrlValue(raw)) {
            return IMAGE_REGEX.test(raw.split('#')[0]);
        }
        return false;
    }

    renderSinglePreview() {
        const val = (this.inputTarget.value || '').trim();
        const filename = val ? this.extractFilename(val) : '';
        const disabled = this.isDisabled();

        if (this.hasClearBtnTarget) {
            this.clearBtnTarget.disabled = disabled || val === '';
        }
        if (this.element && typeof this.element.querySelectorAll === 'function') {
            this.element.querySelectorAll('button[data-action*="picker#open"]').forEach((btn) => {
                btn.disabled = disabled;
            });
        }
        if (this.hasDropzoneTarget && this.dropzoneTarget.getAttribute?.('role') === 'button') {
            this.dropzoneTarget.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            this.dropzoneTarget.setAttribute('tabindex', disabled ? '-1' : '0');
        }
        if (this.hasInputTarget && this.inputTarget.type !== 'hidden') {
            if (val) {
                this.inputTarget.setAttribute('dir', 'ltr');
            } else {
                this.inputTarget.removeAttribute('dir');
            }
        }
        if (this.hasFileLabelTarget) {
            this.fileLabelTarget.textContent = filename || this.placeholderValue;
            this.fileLabelTarget.title = val || '';
            if (val) {
                this.fileLabelTarget.setAttribute('dir', 'ltr');
            } else {
                this.fileLabelTarget.removeAttribute('dir');
            }
        }
        if (this.hasSinglePreviewTarget && this.singlePreviewTarget.getAttribute?.('role') === 'button') {
            this.singlePreviewTarget.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            this.singlePreviewTarget.setAttribute('tabindex', disabled ? '-1' : '0');
        }
        if (!this.hasSinglePreviewTarget) {
            return;
        }

        const variant = this.variantValue;
        if (!val) {
            if (variant === 'avatar') {
                this.singlePreviewTarget.innerHTML = '<span class="liveux-fm-muted" style="font-size:1.5rem;">👤</span>';
            } else if (variant === 'dropzone') {
                this.singlePreviewTarget.innerHTML = '<svg width="28" height="28" style="color:var(--liveux-fm-accent);" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/></svg>';
            } else {
                this.singlePreviewTarget.innerHTML = '<span class="liveux-fm-muted liveux-fm-text-sm">🖼️</span>';
            }
            return;
        }

        if (this.previewValue !== false && this.isImageValue(val) && this.previewSrc(val) !== '') {
            const fit = variant === 'avatar' ? 'cover' : 'contain';
            const style = variant === 'dropzone'
                ? 'max-height:132px;max-width:100%;object-fit:contain;border-radius:6px;display:block;margin:0 auto;'
                : `width:100%;height:100%;object-fit:${fit};`;
            this.singlePreviewTarget.innerHTML = `<img src="${this.escapeAttr(this.previewSrc(val))}" alt="${this.escapeAttr(filename)}" style="${style}">`;
        } else {
            const ext = this.extractExtension(val);
            this.singlePreviewTarget.innerHTML = `<span class="liveux-fm-badge is-neutral liveux-fm-mono" style="font-size:0.68rem;">${this.escapeHtml(ext)}</span>`;
        }
    }

    renderGallery() {
        if (!this.hasGalleryGridTarget) {
            return;
        }
        const items = this.readGalleryValues();
        const variant = this.variantValue;
        const disabled = this.isDisabled();
        const canAddMore = !disabled && (this.maxFilesValue <= 0 || items.length < this.maxFilesValue);

        if (this.hasCountLabelTarget) {
            this.countLabelTarget.textContent = this.formatCount(items.length);
        }
        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.toggle('liveux-fm-hidden', !disabled && !canAddMore);
            this.dropzoneTarget.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            this.dropzoneTarget.setAttribute('tabindex', disabled ? '-1' : '0');
        }

        const isRtl = this.isRtl();
        const prevGlyph = isRtl ? '▶' : '◀';
        const nextGlyph = isRtl ? '◀' : '▶';
        const prevLabel = this.escapeAttr(this.movePrevLabelValue || 'Previous');
        const nextLabel = this.escapeAttr(this.moveNextLabelValue || 'Next');
        const buildReorderControls = (idx) => {
            if (disabled || items.length <= 1) {
                return '';
            }
            const prevDis = idx <= 0 ? ' disabled' : '';
            const nextDis = idx >= items.length - 1 ? ' disabled' : '';
            return `<span class="liveux-fm-picker-reorder-bar">
                <button type="button" class="liveux-fm-picker-reorder-btn" data-index="${idx}" data-delta="-1" data-action="click->liveux--file-manager-bundle--picker#moveGalleryItem" title="${prevLabel}" aria-label="${prevLabel}"${prevDis}>${prevGlyph}</button>
                <button type="button" class="liveux-fm-picker-reorder-btn" data-index="${idx}" data-delta="1" data-action="click->liveux--file-manager-bundle--picker#moveGalleryItem" title="${nextLabel}" aria-label="${nextLabel}"${nextDis}>${nextGlyph}</button>
            </span>`;
        };

        if (variant === 'input') {
            const chips = items.map((val, idx) => {
                const filename = this.extractFilename(val);
                const removeBtn = disabled
                    ? ''
                    : `<button type="button" data-index="${idx}" data-action="click->liveux--file-manager-bundle--picker#removeGalleryItem" title="${this.escapeAttr(this.removeLabelValue)}" aria-label="${this.escapeAttr(this.removeLabelValue)}">✕</button>`;
                return `<span class="liveux-fm-picker-chip" tabindex="${disabled ? '-1' : '0'}" draggable="${disabled ? 'false' : 'true'}" data-reorder-index="${idx}" style="cursor: ${disabled ? 'default' : 'grab'};" title="${this.escapeAttr(val)}">
                    ${buildReorderControls(idx)}
                    <span class="liveux-fm-truncate">${this.escapeHtml(filename)}</span>
                    ${removeBtn}
                </span>`;
            }).join('');
            const addChip = canAddMore
                ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-primary liveux-fm-btn-xs" data-action="click->liveux--file-manager-bundle--picker#open">+ ${this.escapeHtml(this.buttonLabelValue)}</button>`
                : '';
            this.galleryGridTarget.innerHTML = chips + addChip;
            this.bindGalleryReorder();
            return;
        }

        if (variant === 'avatar') {
            const avatars = items.map((val, idx) => {
                const filename = this.extractFilename(val);
                const media = this.previewValue !== false && this.isImageValue(val) && this.previewSrc(val) !== ''
                    ? `<img src="${this.escapeAttr(this.previewSrc(val))}" alt="${this.escapeAttr(filename)}" draggable="false">`
                    : `<span class="liveux-fm-badge is-neutral liveux-fm-mono">${this.escapeHtml(this.extractExtension(val))}</span>`;
                const removeBtn = disabled
                    ? ''
                    : `<button type="button" class="liveux-fm-picker-remove" style="border-radius:999px;" data-index="${idx}" data-action="click->liveux--file-manager-bundle--picker#removeGalleryItem" title="${this.escapeAttr(this.removeLabelValue)}" aria-label="${this.escapeAttr(this.removeLabelValue)}">✕</button>`;
                return `<div class="liveux-fm-picker-avatar-chip" tabindex="${disabled ? '-1' : '0'}" draggable="${disabled ? 'false' : 'true'}" data-reorder-index="${idx}" style="cursor: ${disabled ? 'default' : 'grab'};" title="${this.escapeAttr(filename)}">
                    <div class="liveux-fm-picker-avatar">${media}</div>
                    ${buildReorderControls(idx)}
                    ${removeBtn}
                </div>`;
            }).join('');
            const addCircle = canAddMore
                ? `<button type="button" class="liveux-fm-picker-avatar-add" title="${this.escapeAttr(this.buttonLabelValue)}" aria-label="${this.escapeAttr(this.buttonLabelValue)}" data-action="click->liveux--file-manager-bundle--picker#open">+</button>`
                : '';
            this.galleryGridTarget.innerHTML = avatars + addCircle;
            this.bindGalleryReorder();
            return;
        }

        const cards = items.map((val, idx) => {
            const filename = this.extractFilename(val);
            const media = this.previewValue !== false && this.isImageValue(val) && this.previewSrc(val) !== ''
                ? `<img src="${this.escapeAttr(this.previewSrc(val))}" alt="${this.escapeAttr(filename)}" draggable="false" style="width:100%;height:100%;object-fit:cover;">`
                : `<span class="liveux-fm-badge is-neutral liveux-fm-mono">${this.escapeHtml(this.extractExtension(val))}</span>`;
            const removeBtn = disabled
                ? ''
                : `<button type="button"
                        class="liveux-fm-picker-remove"
                        title="${this.escapeAttr(this.removeLabelValue)}"
                        aria-label="${this.escapeAttr(this.removeLabelValue)}"
                        data-index="${idx}"
                        data-action="click->liveux--file-manager-bundle--picker#removeGalleryItem">✕</button>`;

            return `<div class="liveux-fm-picker-card" tabindex="${disabled ? '-1' : '0'}" draggable="${disabled ? 'false' : 'true'}" data-reorder-index="${idx}" style="cursor: ${disabled ? 'default' : 'grab'};">
                ${buildReorderControls(idx)}
                ${removeBtn}
                <div class="liveux-fm-stage is-checker">${media}</div>
                <div class="liveux-fm-truncate" style="margin-top:4px;padding:0 2px;font-size:0.68rem;" title="${this.escapeAttr(filename)}">${this.escapeHtml(filename)}</div>
            </div>`;
        }).join('');

        const addBtn = (canAddMore && variant !== 'dropzone')
            ? `<button type="button"
                       class="liveux-fm-picker-add"
                       data-action="click->liveux--file-manager-bundle--picker#open">
                   <span style="font-size: 1.3rem; line-height: 1;">+</span>
                   <span>${this.escapeHtml(this.buttonLabelValue)}</span>
               </button>`
            : '';

        this.galleryGridTarget.innerHTML = cards + addBtn;
        this.bindGalleryReorder();
    }

    isRtl() {
        const dirAttr = (this.element?.getAttribute?.('dir') || this.element?.dir || '').toLowerCase();
        if (dirAttr === 'rtl') {
            return true;
        }
        if (dirAttr === 'ltr') {
            return false;
        }
        const docDir = (typeof document !== 'undefined' ? (document.documentElement?.getAttribute?.('dir') || document.documentElement?.dir || '') : '').toLowerCase();
        return docDir === 'rtl';
    }

    moveGalleryItem(event) {
        if (this.isDisabled()) {
            return;
        }
        event?.preventDefault?.();
        event?.stopPropagation?.();
        const btn = event?.currentTarget;
        const fromIndex = parseInt(btn?.dataset?.index ?? '', 10);
        const delta = parseInt(btn?.dataset?.delta ?? '', 10);
        if (Number.isNaN(fromIndex) || Number.isNaN(delta) || delta === 0) {
            return;
        }
        this.reorderGalleryItem(fromIndex, fromIndex + delta, true);
    }

    reorderGalleryItem(fromIndex, toIndex, restoreFocus = false) {
        if (this.isDisabled() || Number.isNaN(fromIndex) || Number.isNaN(toIndex) || fromIndex === toIndex) {
            return;
        }
        const list = this.readGalleryValues();
        if (fromIndex < 0 || fromIndex >= list.length || toIndex < 0 || toIndex >= list.length) {
            return;
        }
        const [moved] = list.splice(fromIndex, 1);
        list.splice(toIndex, 0, moved);
        this.writeGalleryValues(list);
        this.syncView();
        if (restoreFocus && this.hasGalleryGridTarget) {
            const target = this.galleryGridTarget.querySelector?.(`[data-reorder-index="${toIndex}"]`);
            target?.focus?.({ preventScroll: true });
        }
    }

    bindGalleryReorder() {
        if (!this.hasGalleryGridTarget || this.isDisabled()) {
            return;
        }
        let dragFromIndex = null;
        const nodes = this.galleryGridTarget.querySelectorAll('[data-reorder-index]');
        nodes.forEach((node) => {
            node.addEventListener('keydown', (e) => {
                if (e.target !== node) {
                    return;
                }
                const curIdx = parseInt(node.dataset.reorderIndex, 10);
                if (Number.isNaN(curIdx)) {
                    return;
                }
                const rtl = this.isRtl();
                let delta = 0;
                if (e.key === 'ArrowLeft') {
                    delta = rtl ? 1 : -1;
                } else if (e.key === 'ArrowRight') {
                    delta = rtl ? -1 : 1;
                }
                if (delta !== 0) {
                    e.preventDefault();
                    this.reorderGalleryItem(curIdx, curIdx + delta, true);
                }
            });
            node.addEventListener('dragstart', (e) => {
                dragFromIndex = parseInt(node.dataset.reorderIndex, 10);
                node.style.opacity = '0.45';
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', String(dragFromIndex));
                }
            });
            node.addEventListener('dragover', (e) => {
                if (dragFromIndex === null) {
                    return;
                }
                e.preventDefault();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                }
                node.classList.add('is-drag-over');
            });
            node.addEventListener('dragleave', (e) => {
                if (e.relatedTarget && node.contains(e.relatedTarget)) {
                    return;
                }
                node.classList.remove('is-drag-over');
            });
            node.addEventListener('drop', (e) => {
                e.preventDefault();
                node.classList.remove('is-drag-over');
                const toIndex = parseInt(node.dataset.reorderIndex, 10);
                if (dragFromIndex === null || Number.isNaN(dragFromIndex) || Number.isNaN(toIndex) || dragFromIndex === toIndex) {
                    return;
                }
                const from = dragFromIndex;
                dragFromIndex = null;
                this.reorderGalleryItem(from, toIndex);
            });
            node.addEventListener('dragend', () => {
                dragFromIndex = null;
                node.style.opacity = '';
                this.galleryGridTarget.querySelectorAll('.is-drag-over').forEach((el) => el.classList.remove('is-drag-over'));
            });
        });
    }

    readGalleryValues() {
        try {
            const parsed = JSON.parse(this.inputTarget.value || '[]');
            return Array.isArray(parsed) ? parsed.filter((v) => typeof v === 'string' && v !== '') : [];
        } catch (_) {
            return [];
        }
    }

    writeGalleryValues(values) {
        this.inputTarget.value = JSON.stringify(values);
        this.notifyInputUpdated();
        this.dispatchChanged(values);
    }

    dispatchChanged(values) {
        if (!this.element || typeof this.element.dispatchEvent !== 'function') {
            return;
        }
        const safeValues = Array.isArray(values) ? values : [];
        this.element.dispatchEvent(new CustomEvent('liveux:file-manager:picker-changed', {
            bubbles: true,
            detail: {
                values: safeValues,
                value: this.multipleValue ? safeValues : (safeValues[0] || ''),
                multiple: this.multipleValue,
            },
        }));
    }

    escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    formatCount(num) {
        const n = Number(num) || 0;
        const rawLoc = String(this.localeValue || '').trim().split(/[.#@]/)[0].replace(/_/g, '-');
        if (!rawLoc || typeof Intl === 'undefined' || typeof Intl.NumberFormat !== 'function') {
            return String(n);
        }
        try {
            return new Intl.NumberFormat(rawLoc).format(n);
        } catch (_) {
            return String(n);
        }
    }

    isSafeRelativePath(val) {
        if (typeof val !== 'string') {
            return false;
        }
        if (/[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/.test(val)) {
            return false;
        }
        const s = val.trim();
        if (
            !s
            || s.startsWith('/')
            || s.startsWith('\\')
            || /[\uFF0F\u2215\u2044\uFF3C?#]/.test(s)
            || /(?:%0[0-9a-f]|%1[0-9a-f]|%7f|%2f|%5c|%2e|%25(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c|2e))/i.test(s)
            || /^[a-z]:/i.test(s)
            || /^[a-z][a-z0-9+.-]*:/i.test(s)
        ) {
            return false;
        }
        const segments = s.replace(/\\/g, '/').split('/');
        if (segments.length === 0 || segments.some((seg) => seg === '')) {
            return false;
        }
        for (const seg of segments) {
            if (seg !== seg.trim()) {
                return false;
            }
            const baseTail = seg.replace(/[\uFE00-\uFE0F\u{E0100}-\u{E01EF}]+$/u, '');
            if (
                !baseTail
                || baseTail !== baseTail.trim()
                || /^[\u200C\u200D]|[\u200C\u200D]$/.test(seg)
                || /^[\uFE00-\uFE0F\u{E0100}-\u{E01EF}]/u.test(seg)
                || /[\u00AD\u034F\u115F\u1160\u17B4\u17B5\u180B-\u180F\u2061-\u2064\u206A-\u206F\u2800\u3164\uFFA0\uFFF9-\uFFFB]|[\u{E0000}-\u{E007F}]/u.test(seg)
                || /[\u200C\u200D\uFE00-\uFE0F\u{E0100}-\u{E01EF}]$/u.test(baseTail)
            ) {
                return false;
            }
            const folded = seg.normalize('NFKC').replace(/[\uFF0E\u3002\uFF61\uFE52\u2024]/g, '.');
            const skeleton = folded.replace(/[\u200C\u200D\uFE00-\uFE0F\u{E0100}-\u{E01EF}]/gu, '');
            const lastDot = folded.lastIndexOf('.');
            if (lastDot >= 0 && /[\u200C\u200D\uFE00-\uFE0F\u{E0100}-\u{E01EF}]/u.test(folded.slice(lastDot + 1))) {
                return false;
            }
            if (
                !skeleton
                || skeleton === '.'
                || skeleton === '..'
                || skeleton.endsWith('.')
                || skeleton.toLowerCase() === '.trash'
                || skeleton.startsWith('.')
                || /^(?:con|prn|aux|nul|com[1-9]|lpt[1-9]|conin\$|conout\$)(?:\.|$)/i.test(skeleton)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Builds a safe URL for <img src>. Real URLs pass through isSafeUrl; relative storage paths
     * (return_type=path, e.g. "products/a.png") are combined with previewUrlPrefixValue (e.g. "/uploads"
     * or an S3 CDN URL) when available, falling back to a root-relative path. Returns '' if unsafe.
     */
    previewSrc(val) {
        const raw = String(val ?? '');
        if (this.returnTypeValue !== 'path' && this.isSafeUrl(raw)) {
            const trimmed = raw.trim();
            if (this.extractExtension(raw) === 'SVG') {
                const qIdx = trimmed.indexOf('?');
                if (qIdx > 0) {
                    const basePart = trimmed.slice(0, qIdx);
                    const rest = trimmed.slice(qIdx + 1);
                    const queryOnly = rest.split('#')[0];
                    const hasPathQueryParam = queryOnly.split('&').some((pair) => {
                        if (!pair) {
                            return false;
                        }
                        const eq = pair.indexOf('=');
                        if (eq <= 0) {
                            return false;
                        }
                        const k = pair.slice(0, eq);
                        const v = pair.slice(eq + 1);
                        if (!v) {
                            return false;
                        }
                        let dk = k;
                        try {
                            dk = decodeURIComponent(k.replace(/\+/g, '%20'));
                        } catch (_) {
                            return false;
                        }
                        return k.trimStart().toLowerCase() === 'path' || dk.trimStart().toLowerCase() === 'path';
                    });
                    if (hasPathQueryParam) {
                        const isRootRelative = basePart.startsWith('/');
                        let basePathname = basePart;
                        let isSameOrigin = false;
                        const hasBrowserOrigin = typeof window !== 'undefined' && Boolean(window.location?.origin);
                        if (!isRootRelative && /^https?:\/\//i.test(basePart)) {
                            try {
                                const parsed = new URL(basePart);
                                basePathname = parsed.pathname || '';
                                isSameOrigin = hasBrowserOrigin && parsed.origin === window.location.origin;
                            } catch (_) {
                                isSameOrigin = false;
                            }
                        }
                        const configuredThumbBase = String(this.thumbUrlValue || '').split(/[?#]/)[0];
                        const expectedDownloadBase = configuredThumbBase.endsWith('/thumb')
                            ? `${configuredThumbBase.slice(0, -6)}/download`
                            : '';
                        const matchesConfigured = expectedDownloadBase !== '' && (
                            (expectedDownloadBase.startsWith('/') && (
                                (isRootRelative && basePart === expectedDownloadBase)
                                || (isSameOrigin && basePathname === expectedDownloadBase)
                            ))
                            || (/^https?:\/\//i.test(expectedDownloadBase) && basePart === expectedDownloadBase)
                        );
                        const matchesDefault = (basePathname.endsWith('/_liveux-file-manager/download') && (isRootRelative || !hasBrowserOrigin || isSameOrigin))
                            || (basePathname === '/download' && (isRootRelative || isSameOrigin));
                        if (matchesConfigured || matchesDefault) {
                            return `${basePart.slice(0, -9)}/thumb?${rest}`;
                        }
                    }
                }
            }
            return trimmed;
        }
        if (!this.isSafeRelativePath(raw)) {
            return '';
        }
        const s = raw.trim();
        const rawSegments = s.replace(/\\/g, '/').split('/').filter((seg) => seg !== '');
        const cleanRel = rawSegments.join('/');
        const encodedRel = rawSegments.map((seg) => encodeURIComponent(seg)).join('/');
        const basePrefix = String(this.previewUrlPrefixValue || '').trim().replace(/\/+$/, '');
        const rawThumbUrl = String(this.thumbUrlValue || '').trim();
        if ((basePrefix === '' || this.extractExtension(cleanRel) === 'SVG') && rawThumbUrl && this.isSafeUrl(rawThumbUrl)) {
            const sep = rawThumbUrl.includes('?') ? '&' : '?';
            const prof = encodeURIComponent(this.profileValue || 'default');
            const volPart = this.volumeValue ? `&volume=${encodeURIComponent(this.volumeValue)}` : '';
            const thumbCandidate = `${rawThumbUrl}${sep}profile=${prof}${volPart}&path=${encodeURIComponent(cleanRel)}`;
            return this.isSafeUrl(thumbCandidate) ? thumbCandidate : '';
        }
        let candidate = `/${encodedRel}`;
        if (basePrefix && this.isSafeUrl(basePrefix)) {
            const strippedPrefix = basePrefix.replace(/^\/+/, '');
            if (basePrefix.startsWith('/') && (cleanRel === strippedPrefix || cleanRel.startsWith(`${strippedPrefix}/`))) {
                candidate = `/${encodedRel}`;
            } else {
                candidate = `${basePrefix}/${encodedRel}`;
            }
        }
        return this.isSafeUrl(candidate) ? candidate : '';
    }

    hasDangerousUrlSegments(rawUrl) {
        const noHash = rawUrl.split('#')[0];
        const qIdx = noHash.indexOf('?');
        let rawPathPart = qIdx >= 0 ? noHash.slice(0, qIdx) : noHash;
        const rawQuery = qIdx >= 0 ? noHash.slice(qIdx + 1) : '';

        // Backslashes before ?/# can be coerced into authority/path separators by WHATWG URL parsers.
        if (rawPathPart.includes('\\')) {
            return true;
        }

        if (/^https?:\/\//i.test(rawPathPart)) {
            const afterProto = rawPathPart.replace(/^https?:\/\//i, '');
            const slashIdx = afterProto.indexOf('/');
            rawPathPart = slashIdx >= 0 ? afterProto.slice(slashIdx) : '';
        }

        const fillerRe = /[\u00AD\u034F\u115F\u1160\u17B4\u17B5\u180B-\u180F\u200B-\u200F\u2060-\u2064\u2066-\u206F\u2800\u3164\uFE00-\uFE0F\uFEFF\uFFA0\uFFF9-\uFFFB]|[\u{E0000}-\u{E007F}\u{E0100}-\u{E01EF}]/gu;
        const decodeValidTriplets = (s) => s.replace(/(?:%[0-9a-fA-F]{2})+/g, (seq) => {
            try {
                return decodeURIComponent(seq);
            } catch (_) {
                return seq;
            }
        });
        const controlOrBidiRe = /[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/;
        const checkPath = (inputPath) => {
            let once;
            try {
                once = decodeURIComponent(inputPath);
            } catch (_) {
                return true;
            }
            const passes = [inputPath, once];
            const twice = decodeValidTriplets(once);
            if (twice !== once) {
                passes.push(twice);
            }
            for (const cand of passes) {
                if (controlOrBidiRe.test(cand) || /[\uFF0F\u2215\u2044\uFF3C]/.test(cand)) {
                    return true;
                }
                for (const seg of cand.replace(/\\/g, '/').split('/')) {
                    if (!seg) {
                        continue;
                    }
                    const norm = seg.replace(fillerRe, '').trim().normalize('NFKC').replace(/[\uFF0E\u3002\uFF61\uFE52\u2024]/g, '.').toLowerCase();
                    if (norm === '' || norm === '.' || norm === '..' || norm === '.trash' || norm.startsWith('.')) {
                        return true;
                    }
                }
            }
            return false;
        };

        if (rawPathPart && checkPath(rawPathPart)) {
            return true;
        }
        if (rawQuery) {
            let pathCount = 0;
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
                    pathCount += 1;
                    if (pathCount > 1) {
                        return true;
                    }
                    const normVal = rawVal.replace(/\+/g, '%20');
                    if (normVal && checkPath(normVal)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    isSafeUrl(url) {
        const raw = String(url ?? '');
        if (/[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/.test(raw)) {
            return false;
        }
        const s = raw.trim();
        if (!s) {
            return false;
        }
        if (/^data:image\/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=]+$/i.test(s)) {
            return true;
        }
        if (/(?:%0[0-9a-f]|%1[0-9a-f]|%7f|%25(?:0[0-9a-f]|1[0-9a-f]|7f))/i.test(s)) {
            return false;
        }
        if (this.hasDangerousUrlSegments(s)) {
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

    escapeAttr(str) {
        return this.escapeHtml(str);
    }
}
