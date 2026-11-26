import { Controller } from '@hotwired/stimulus';

const CTRL = 'liveux--file-manager-bundle--browser';

const TEXT_PREVIEW_EXTENSIONS = new Set([
    'txt', 'md', 'markdown', 'json', 'yaml', 'yml',
    'csv', 'css', 'scss', 'ts', 'sql', 'log', 'toml',
]);
const VIDEO_PREVIEW_EXTENSIONS = new Set(['mp4', 'webm', 'ogv', 'mov', 'm4v']);
const AUDIO_PREVIEW_EXTENSIONS = new Set(['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac']);
const PDF_PREVIEW_EXTENSIONS = new Set(['pdf']);

const FOLDER_COLORS = [
    { key: 'blue', value: '#2563eb' },
    { key: 'sky', value: '#0284c7' },
    { key: 'cyan', value: '#0891b2' },
    { key: 'teal', value: '#0d9488' },
    { key: 'emerald', value: '#059669' },
    { key: 'green', value: '#16a34a' },
    { key: 'lime', value: '#65a30d' },
    { key: 'amber', value: '#d97706' },
    { key: 'orange', value: '#ea580c' },
    { key: 'red', value: '#dc2626' },
    { key: 'rose', value: '#e11d48' },
    { key: 'pink', value: '#db2777' },
    { key: 'fuchsia', value: '#c026d3' },
    { key: 'purple', value: '#9333ea' },
    { key: 'violet', value: '#7c3aed' },
    { key: 'indigo', value: '#4f46e5' },
    { key: 'slate', value: '#475569' },
    { key: 'charcoal', value: '#1e293b' },
];

/**
 * High-performance In-Memory FileManager Controller:
 * - Full folder tree loaded once -> 0ms expand/collapse at any depth (ZERO AJAX)
 * - Per-folder client-side cache (dirCache) -> 0ms filter, search, sort, paginate, view, contain/cover, tree lines, thumbnails (ZERO AJAX)
 * - Bulletproof delegated Context Menu (right-click + "..." button) on cards, list rows & sidebar folders
 * - Sleek inline modals (New Folder, Rename, Clone, Folder Color, About) & Lightbox preview
 * - Chunked multi-file upload + OS Drag&Drop + Ctrl+V paste upload + Drag&Drop internal Move/Copy
 */
export default class extends Controller {
    static targets = [
        'shell', 'sidebar', 'treeTitleLabel', 'treeSearchWrap', 'pinnedFolders', 'tree',
        'quotaBox', 'quotaText', 'quotaBar', 'quotaPercent', 'quotaPill', 'quotaPillText', 'settingsQuotaBadge',
        'sidebarTrashBtn', 'inspector', 'footerStatus', 'confirmSelectionBtn',
        'searchInput', 'recursiveSearchBtn', 'fileInput', 'uploadQueue', 'contextMenu',
        'newFolderBtn', 'uploadBtn',
        'settingsPopover', 'settingsCachePill', 'folderStyleButtons', 'itemSizeSlider', 'itemSizeLabel', 'colorSchemeButtons', 'bulkBar', 'bulkCount',
        'clipboardBar', 'clipboardCount', 'clipboardLabel', 'clipboardPasteBtn',
        'contentArea', 'dropOverlay', 'dropOverlayPath', 'breadcrumbs', 'backBtn', 'forwardBtn', 'upBtn', 'perfBadge', 'spinner', 'statusBanner',
        'volumeSelect', 'typeFilters', 'viewButtons', 'trashBtn',
        'paginationBar', 'totalCount', 'pagerGroup',
        'perPageSelect', 'sortBySelect', 'sortDirBtn', 'fullscreenBtn',
        'lightbox', 'lightboxStage', 'lightboxCaption', 'lightboxCounter', 'lightboxControls',
        'modalBackdrop', 'modalTitle', 'modalBody', 'shortcutsTemplate', 'aboutTemplate',
    ];

    static values = {
        profile: String,
        locale: { type: String, default: 'en' },
        volume: String,
        volumeLabel: String,
        path: String,
        page: { type: Number, default: 1 },
        perPage: { type: Number, default: 24 },
        perPageOptions: { type: Array, default: [24, 48, 96] },
        folderOpenOn: { type: String, default: 'dblclick' },
        viewMode: { type: String, default: 'grid' },
        multiple: { type: Boolean, default: false },
        mode: { type: String, default: 'inline' },
        typeFilter: { type: String, default: 'all' },
        sortBy: { type: String, default: 'name' },
        sortDir: { type: String, default: 'asc' },
        thumbnailsEnabled: { type: Boolean, default: true },
        lazyLoad: { type: Boolean, default: true },
        thumbFit: { type: String, default: 'contain' },
        treeLines: { type: Boolean, default: true },
        showTree: { type: Boolean, default: true },
        inspectorMode: { type: String, default: 'on_select' },
        colorScheme: { type: String, default: 'auto' },
        itemSize: { type: Number, default: 158 },
        cardAspectRatio: { type: String, default: '4/3' },
        folderStyle: { type: String, default: 'tile' },
        imageConstraints: { type: Object, default: {} },
        listUrl: String,
        treeUrl: String,
        trashUrl: String,
        previewTextUrl: { type: String, default: '' },
        actionUrl: String,
        uploadUrl: String,
        imageEditUrl: { type: String, default: '' },
        zipUrl: String,
        csrfToken: { type: String, default: '' },
        uploadMaxSize: { type: Number, default: 0 },
        uploadMaxSizeLabel: { type: String, default: '' },
        chunkSize: { type: Number, default: 2097152 },
        chunkEnabled: { type: Boolean, default: true },
        parallelUploads: { type: Number, default: 3 },
        maxRetries: { type: Number, default: 3 },
        initialQuota: { type: Object, default: {} },
        uploadAllow: { type: Array, default: [] },
        uploadDeny: { type: Array, default: [] },
        rememberLastDir: { type: Boolean, default: true },
        doubleClickToSelect: { type: Boolean, default: true },
        keyboardShortcuts: { type: Boolean, default: true },
        confirmMove: { type: Boolean, default: true },
        initialItems: { type: Array, default: [] },
        initialServerPaginated: { type: Boolean, default: false },
        initialTotal: { type: Number, default: 0 },
        initialTree: { type: Array, default: [] },
        capabilities: { type: Object, default: {} },
        i18n: { type: Object, default: {} },
        iconSet: { type: Object, default: {} },
    };

    connect() {
        this.dirCache = new Map();
        this.dirMeta = new Map();
        this.serverSearchTimer = null;
        this.treeCache = new Map();
        this.expandedFolders = new Set();
        this.selectedMap = new Map();
        this.clipboard = null;
        this.lastSelectedPath = null;
        this.renderedPaths = [];
        this.currentDisplayedItems = [];
        this.uploadJobs = [];
        this.activeUploads = 0;
        this.needsPostUploadRefresh = false;
        this.dragging = null;
        this.dragInstanceToken = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
        this.externalDragDepth = 0;
        this.history = [{ volume: this.volumeValue, path: this.pathValue }];
        this.historyIndex = 0;
        this.trashMode = false;
        this.isFullscreen = false;
        this.searchQuery = '';
        this.rawSearchQuery = '';
        this.recursiveSearch = false;
        this.recursiveItems = null;
        this.recursiveTimer = null;
        this.treeSearchQuery = '';
        this._collator = null;
        this._collatorLocale = null;
        this._autoDirApplied = false;
        this.lastActionError = null;
        this.totalPages = 1;
        this.abortController = null;
        this.searchAbortController = null;
        this.navToken = 0;
        this.dirRequestToken = 0;
        this.searchRequestToken = 0;
        this.navRollbackBase = null;
        this.modalBackdropMouseDown = false;
        this.lightboxItems = [];
        this.lightboxIndex = 0;
        this.lightboxZoom = 1;
        this.lightboxAbortController = null;
        this.svgSanitizeCache = new Map();
        this.imageDimsCache = new Map();
        this._inspectorImageProbe = null;
        this._searchRenderRaf = null;
        this._treeRenderRaf = null;
        this._editorRenderRaf = null;
        this.isDisconnected = false;
        this.zipDownloading = false;
        this.isPasting = false;
        this.isExtractingZip = false;
        this.isTogglingPin = false;
        this.togglingPinPaths = new Set();

        this.explicitVolumeRequested = Boolean(
            this.element?.dataset?.explicitVolume === '1'
            || (this.element?.dataset?.requestedVolume && this.element.dataset.requestedVolume !== ''),
        );
        this.initialVolume = this.element?.dataset?.requestedVolume || this.volumeValue || '';
        this.initialPath = this.element?.dataset?.requestedPath ?? this.pathValue ?? '';
        this.initialTypeFilter = this.element?.dataset?.requestedType || this.typeFilterValue || 'all';

        // Seed in-memory caches from initial SSR payload (ZERO extra startup requests!)
        // When show_folder_tree is false, SSR intentionally omits initialTree ([]), so leave treeCache
        // unseeded so toggleSidebar() or openMoveModal() lazily fetches /api/tree on demand.
        const initialKey = this.cacheKey(this.volumeValue, this.pathValue);
        const initialItems = Array.isArray(this.initialItemsValue) ? this.initialItemsValue : [];
        this.setDirCache(initialKey, initialItems);
        if (this.initialServerPaginatedValue) {
            const perPage = Math.max(1, this.perPageValue || 24);
            const total = Math.max(initialItems.length, Number(this.initialTotalValue) || 0);
            this.dirMeta.set(initialKey, {
                serverPaginated: true,
                total,
                page: Math.max(1, this.pageValue || 1),
                perPage,
                totalPages: Math.max(1, Math.ceil(total / perPage)),
            });
        }
        const hasInitialTree = Array.isArray(this.initialTreeValue) && this.initialTreeValue.length > 0;
        if (hasInitialTree || (this.capabilitiesValue?.showFolderTree !== false && this.showTreeValue !== false)) {
            this.treeCache.set(this.volumeValue, this.normalizeTreeFolders(this.initialTreeValue));
        }

        this.boundKeydown = this.onKeydown.bind(this);
        this.boundPaste = this.onPaste.bind(this);
        this.boundOutsideClick = this.onOutsideClick.bind(this);
        this.boundContextMenu = this.onDelegatedContextMenu.bind(this);
        this.boundFullscreenChange = this.onNativeFullscreenChange.bind(this);
        this.boundPointerFocus = this.onRootPointerDown.bind(this);
        this.boundDialogCancel = this.onDialogCancel.bind(this);
        this.boundDialogOpened = this.onDialogOpened.bind(this);
        this.prevBodyOverflow = null;

        document.addEventListener('keydown', this.boundKeydown);
        document.addEventListener('paste', this.boundPaste);
        document.addEventListener('click', this.boundOutsideClick);
        document.addEventListener('fullscreenchange', this.boundFullscreenChange);
        this.element.addEventListener('contextmenu', this.boundContextMenu);
        this.element.addEventListener('pointerdown', this.boundPointerFocus);
        this.element.addEventListener('liveux:file-manager:dialog-cancel', this.boundDialogCancel);
        this.element.addEventListener('liveux:file-manager:dialog-opened', this.boundDialogOpened);

        // Restore persisted sidebar width
        if (this.hasSidebarTarget) {
            try {
                const savedW = parseInt(localStorage.getItem('liveux_fm_sidebar_width') || '', 10);
                if (!Number.isNaN(savedW) && savedW >= 175 && savedW <= 480) {
                    this.sidebarTarget.style.width = `${savedW}px`;
                }
            } catch (_) { }
        }

        // Restore persisted item size (96..320px), defaulting to profile ui.item_size
        this.activeItemSize = Math.max(96, Math.min(320, Number(this.itemSizeValue) || 158));
        if (this.capabilitiesValue?.showSizeSlider !== false) {
            try {
                const savedSize = parseInt(localStorage.getItem(`liveux_fm_item_size:${this.profileValue || 'default'}`) || '', 10);
                if (!Number.isNaN(savedSize) && savedSize >= 96 && savedSize <= 320) {
                    this.activeItemSize = savedSize;
                }
            } catch (_) { }
        }
        this.syncItemSizeUi();

        // Restore persisted folder display style (tile | card), defaulting to profile ui.folder_style
        this.activeFolderStyle = this.folderStyleValue === 'card' ? 'card' : 'tile';
        try {
            const savedFolderStyle = localStorage.getItem(`liveux_fm_folder_style:${this.profileValue || 'default'}`);
            if (savedFolderStyle === 'tile' || savedFolderStyle === 'card') {
                this.activeFolderStyle = savedFolderStyle;
            }
        } catch (_) { }
        this.syncFolderStyleUi();

        // Restore persisted color scheme (auto | light | dark), scoped per profile
        this.activeColorScheme = ['auto', 'light', 'dark'].includes(this.colorSchemeValue)
            ? this.colorSchemeValue
            : 'auto';
        try {
            const profileSchemeKey = `liveux_fm_color_scheme:${this.profileValue || 'default'}`;
            const savedScheme = localStorage.getItem(profileSchemeKey) || localStorage.getItem('liveux_fm_color_scheme');
            if (savedScheme === 'auto' || savedScheme === 'light' || savedScheme === 'dark') {
                this.activeColorScheme = savedScheme;
            }
        } catch (_) { }
        this.syncColorSchemeUi();

        if (this.hasSidebarTarget && this.isMobileViewport()) {
            this.showTreeValue = false;
            this.sidebarTarget.classList.add('liveux-fm-hidden');
        }

        this.mobileBreakpointMql = null;
        this.inspectorBreakpointMql = null;
        this.boundMobileBreakpointChange = (e) => {
            if (e?.matches) {
                this.closeMobileSidebar();
            }
        };
        this.boundInspectorBreakpointChange = (e) => {
            if (!this.isDisconnected && !e?.matches) {
                this.renderInspector();
            } else if (!this.isDisconnected && e?.matches && this.hasInspectorTarget) {
                this.cancelInspectorImageProbe();
                this.releaseMediaElements(this.inspectorTarget);
            }
        };
        if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
            const mobMql = window.matchMedia('(max-width: 767.98px)');
            if (mobMql && typeof mobMql.addEventListener === 'function') {
                mobMql.addEventListener('change', this.boundMobileBreakpointChange);
                this.mobileBreakpointMql = mobMql;
            }
            const inspMql = window.matchMedia('(max-width: 991.98px)');
            if (inspMql && typeof inspMql.addEventListener === 'function') {
                inspMql.addEventListener('change', this.boundInspectorBreakpointChange);
                this.inspectorBreakpointMql = inspMql;
            }
        }

        if (this.hasShellTarget) {
            this.shellTarget.dataset.sidebarOpen = this.hasSidebarTarget && !this.sidebarTarget.classList.contains('liveux-fm-hidden') ? 'true' : 'false';
        }

        // Initial instant render (avoid scrolling host viewport on mount)
        this.syncLocaleAttributes();
        this.syncSortDirButton();
        this.renderSidebarTree(false);
        this.renderQuota(this.initialQuotaValue);
        this.renderBreadcrumbs(this.buildBreadcrumbs(this.pathValue));
        this.syncNavButtons();
        this.applyClientView();

        // Restore last visited volume & directory when remember_last_dir is enabled and started at root
        if (this.rememberLastDirValue && this.profileValue && !this.pathValue) {
            this.restoreLastVisitedLocation();
        }
    }

    disconnect() {
        this.isDisconnected = true;
        this.needsPostUploadRefresh = false;
        clearTimeout(this.recursiveTimer);
        clearTimeout(this.serverSearchTimer);
        clearTimeout(this.statusTimer);
        this.cancelScheduledRender('_searchRenderRaf');
        this.cancelScheduledRender('_treeRenderRaf');
        this.cancelScheduledRender('_editorRenderRaf');
        this.runActiveDragCleanup();
        this.dragging = null;
        this.hideDropOverlay();
        if (this._editorImage) {
            this._editorImage.onload = null;
            this._editorImage.onerror = null;
            this._editorImage = null;
        }
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
        if (this.trashAbortController) {
            this.trashAbortController.abort();
            this.trashAbortController = null;
        }
        if (this.treeAbortController) {
            this.treeAbortController.abort();
            this.treeAbortController = null;
        }
        if (this.searchAbortController) {
            this.searchAbortController.abort();
            this.searchAbortController = null;
        }
        if (this.zipAbortController) {
            try { this.zipAbortController.abort(); } catch (_) { }
            this.zipAbortController = null;
        }
        this.cancelLightboxTextFetch();
        if (Array.isArray(this.uploadJobs)) {
            this.uploadJobs.forEach((job) => {
                job.cancelled = true;
                job.runToken = (job.runToken || 0) + 1;
                if (job.retryTimer) {
                    clearTimeout(job.retryTimer);
                    job.retryTimer = null;
                }
                if (typeof job.retryResolve === 'function') {
                    const res = job.retryResolve;
                    job.retryResolve = null;
                    try { res(); } catch (_) { }
                }
                if (job.abortController) {
                    try { job.abortController.abort(); } catch (_) { }
                    job.abortController = null;
                }
            });
            this.uploadJobs = [];
        }
        this.cancelInspectorImageProbe();
        this.imageDimsCache?.clear();
        this.svgSanitizeCache?.clear();
        this._intlCache?.clear?.();
        this._bcp47Cache?.clear?.();
        this.releaseMediaElements(this.element);
        document.removeEventListener('keydown', this.boundKeydown);
        document.removeEventListener('paste', this.boundPaste);
        document.removeEventListener('click', this.boundOutsideClick);
        document.removeEventListener('fullscreenchange', this.boundFullscreenChange);
        this.element.removeEventListener('contextmenu', this.boundContextMenu);
        this.element.removeEventListener('pointerdown', this.boundPointerFocus);
        this.element.removeEventListener('liveux:file-manager:dialog-cancel', this.boundDialogCancel);
        this.element.removeEventListener('liveux:file-manager:dialog-opened', this.boundDialogOpened);
        if (this.mobileBreakpointMql && typeof this.mobileBreakpointMql.removeEventListener === 'function') {
            this.mobileBreakpointMql.removeEventListener('change', this.boundMobileBreakpointChange);
            this.mobileBreakpointMql = null;
        }
        if (this.inspectorBreakpointMql && typeof this.inspectorBreakpointMql.removeEventListener === 'function') {
            this.inspectorBreakpointMql.removeEventListener('change', this.boundInspectorBreakpointChange);
            this.inspectorBreakpointMql = null;
        }
        if (this.prevBodyOverflow !== null && document.body) {
            document.body.style.overflow = this.prevBodyOverflow;
            this.prevBodyOverflow = null;
        }
    }

    releaseMediaElements(root) {
        if (!root || typeof root.querySelectorAll !== 'function') {
            return;
        }
        root.querySelectorAll('video, audio').forEach((media) => {
            try {
                media.pause?.();
                media.removeAttribute?.('src');
                media.querySelectorAll?.('source')?.forEach((s) => s.removeAttribute?.('src'));
                media.load?.();
            } catch (_) { }
        });
    }

    onDialogCancel(event) {
        if (this.isContextMenuOpen() || this.isLightboxOpen() || this.isModalOpen() || this.isSettingsPopoverOpen() || this.isMobileSidebarOpen()) {
            event.preventDefault();
            this.hideContextMenu();
            this.closeLightbox();
            this.closeModal();
            this.closeMobileSidebar();
            this.closeSettingsPopover();
        }
    }

    async onDialogOpened(event) {
        this.dragging = null;
        this.hideDropOverlay();
        this.hideContextMenu();
        this.closeLightbox();
        this.closeModal();
        if (this.isMobileViewport()) {
            this.closeMobileSidebar();
        }
        this.closeSettingsPopover();
        this.isFullscreen = false;
        this.syncFullscreenButton();
        this.clearSelection();

        this.cancelRecursiveSearch();
        this.recursiveSearch = false;
        if (this.hasRecursiveSearchBtnTarget) {
            this.recursiveSearchBtnTarget.classList.remove('is-active');
        }
        const hadSearch = Boolean(this.searchQuery || this.rawSearchQuery);
        this.searchQuery = '';
        this.rawSearchQuery = '';
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }

        const wasTrash = Boolean(this.trashMode);
        if (wasTrash) {
            this.trashMode = false;
            if (this.trashAbortController) {
                this.trashAbortController.abort();
                this.trashAbortController = null;
            }
            this.syncTrashUi();
        }

        const detail = event?.detail || {};
        if (typeof detail.locale === 'string' && detail.locale.trim() !== '' && detail.locale !== this.localeValue) {
            this.localeValue = detail.locale;
            this._collator = null;
            this._collatorLocale = null;
            this._intlCache?.clear?.();
            this.imageDimsCache?.clear?.();
        }
        this.syncLocaleAttributes();
        this.syncSortDirButton();
        if (typeof detail.explicitVolume === 'boolean') {
            this.explicitVolumeRequested = detail.explicitVolume;
        } else if (detail.volume) {
            this.explicitVolumeRequested = true;
        }

        const requestedType = detail.type || this.initialTypeFilter || 'all';
        const hadTypeChange = Boolean(this.typeFilterValue && this.typeFilterValue !== requestedType);
        if (this.typeFilterValue !== requestedType) {
            this.typeFilterValue = requestedType;
            this.syncTypeFilterControl(requestedType);
        }

        let expectedVolume = this.volumeValue;
        if (detail.volume) {
            expectedVolume = detail.volume;
        } else if (this.explicitVolumeRequested && this.initialVolume) {
            expectedVolume = this.initialVolume;
        } else if (!this.rememberLastDirValue && this.initialVolume) {
            expectedVolume = this.initialVolume;
        }

        let expectedPath = this.pathValue;
        if (typeof detail.path === 'string' && detail.path !== '') {
            expectedPath = detail.path;
        } else if (this.initialPath) {
            expectedPath = this.initialPath;
        } else if (expectedVolume !== this.volumeValue) {
            expectedPath = this.rememberLastDirValue ? this.getSavedDirForVolume(expectedVolume) : '';
        } else if (!this.rememberLastDirValue) {
            expectedPath = '';
        }

        if (expectedVolume !== this.volumeValue || expectedPath !== this.pathValue) {
            await this.navigateTo(expectedPath, expectedVolume, false, { silent: true, allowFallback: true });
            return;
        }

        if (wasTrash) {
            const key = this.cacheKey(this.volumeValue, this.pathValue);
            if (this.dirCache && !this.dirCache.has(key) && this.listUrlValue) {
                await this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true);
                return;
            }
            this.applyClientView();
            return;
        }

        if (hadSearch || hadTypeChange) {
            this.pageValue = 1;
            this.applyClientView();
        }
    }

    syncTypeFilterControl(filter) {
        if (!this.hasTypeFiltersTarget) {
            return;
        }
        if (this.typeFiltersTarget.tagName === 'SELECT') {
            this.typeFiltersTarget.value = filter;
        } else {
            this.typeFiltersTarget.querySelectorAll('button').forEach((btn) => {
                btn.classList.toggle('is-active', btn.dataset.filter === filter);
            });
        }
    }

    isSinglePickerMode() {
        return Boolean(this.modeValue && this.modeValue !== 'inline' && this.multipleValue === false);
    }

    syncTrashUi() {
        this.syncTrashButton();
        this.syncActiveTreeNode();
        this.syncClipboardUi();
        this.syncTrashToolbarState();
    }

    syncTrashToolbarState() {
        const inTrash = Boolean(this.trashMode);
        if (this.hasNewFolderBtnTarget) {
            this.newFolderBtnTarget.disabled = inTrash;
        }
        if (this.hasUploadBtnTarget) {
            this.uploadBtnTarget.disabled = inTrash;
        }
        if (this.hasSortBySelectTarget) {
            this.sortBySelectTarget.disabled = inTrash;
        }
        if (this.hasSortDirBtnTarget) {
            this.sortDirBtnTarget.disabled = inTrash;
        }
    }

    async exitTrashAndRestoreView() {
        this.trashMode = false;
        this.syncTrashUi();
        if (this.trashAbortController) {
            this.trashAbortController.abort();
            this.trashAbortController = null;
        }
        const key = this.cacheKey(this.volumeValue, this.pathValue);
        const promises = [];
        if (this.dirCache && !this.dirCache.has(key) && this.listUrlValue) {
            promises.push(this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true));
        }
        if (this.treeCache && !this.treeCache.has(this.volumeValue) && this.treeUrlValue) {
            promises.push(this.refreshFullTree(true));
        }
        if (promises.length > 0) {
            await Promise.all(promises);
            if (this.dirCache && this.dirCache.has(key)) {
                return;
            }
        }
        this.applyClientView();
    }

    registerDragCleanup(fn) {
        this.runActiveDragCleanup();
        this.activeDragCleanup = typeof fn === 'function' ? fn : null;
    }

    runActiveDragCleanup() {
        if (typeof this.activeDragCleanup === 'function') {
            const fn = this.activeDragCleanup;
            this.activeDragCleanup = null;
            try {
                fn();
            } catch (_) { }
        }
    }

    cacheKey(volume, path) {
        return `${volume || ''}::${path || ''}`;
    }

    setDirCache(key, items) {
        if (!this.dirCache) {
            this.dirCache = new Map();
        }
        if (this.dirCache.has(key)) {
            this.dirCache.delete(key);
        }
        while (this.dirCache.size >= 64) {
            const oldestKey = this.dirCache.keys().next().value;
            if (oldestKey === undefined) {
                break;
            }
            this.dirCache.delete(oldestKey);
        }
        this.dirCache.set(key, items);
    }

    t(key, params = {}) {
        const dict = this.i18nValue || {};
        const raw = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        const text = typeof raw === 'string' ? raw : String(key);
        const entries = Object.entries(params || {});
        if (entries.length === 0) {
            return text;
        }
        const tokenMap = Object.create(null);
        for (const [k, v] of entries) {
            const token = k.startsWith('%') && k.endsWith('%') ? k : `%${k}%`;
            tokenMap[token] = String(v ?? '');
        }
        return text.replace(/%[a-zA-Z0-9_]+%/g, (match) => (
            Object.prototype.hasOwnProperty.call(tokenMap, match) ? tokenMap[match] : match
        ));
    }

    bcp47Locale(rawLocale = this.localeValue) {
        const key = String(rawLocale ?? '');
        if (!this._bcp47Cache) {
            this._bcp47Cache = new Map();
        }
        if (this._bcp47Cache.has(key)) {
            return this._bcp47Cache.get(key);
        }
        const cleaned = key
            .trim()
            .split(/[.#@]/)[0]
            .replace(/_/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
        let resolved;
        if (cleaned && typeof Intl !== 'undefined' && typeof Intl.getCanonicalLocales === 'function') {
            try {
                const canonical = Intl.getCanonicalLocales(cleaned);
                resolved = Array.isArray(canonical) && canonical.length > 0 ? canonical[0] : undefined;
            } catch (_) {
                resolved = undefined;
            }
        }
        if (this._bcp47Cache.size >= 16) {
            this._bcp47Cache.clear();
        }
        this._bcp47Cache.set(key, resolved);
        return resolved;
    }

    isRtlLocale(rawLocale = this.localeValue) {
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

    isRtl() {
        const dirEl = this.element?.closest?.('[dir]');
        const explicitDir = dirEl?.getAttribute?.('dir') || dirEl?.dir || '';
        if (String(explicitDir).toLowerCase() === 'rtl') {
            if (dirEl === this.element && this._autoDirApplied && this.localeValue) {
                const parentDirEl = this.element?.parentElement?.closest?.('[dir]');
                const parentDir = parentDirEl?.getAttribute?.('dir') || parentDirEl?.dir || '';
                if (String(parentDir).toLowerCase() === 'rtl') {
                    return true;
                }
                return this.isRtlLocale(this.localeValue);
            }
            return true;
        }
        return this.isRtlLocale(this.localeValue);
    }

    syncLocaleAttributes() {
        if (!this.element) {
            return;
        }
        const dirVal = this.isRtl() ? 'rtl' : 'ltr';
        this.element.dir = dirVal;
        this.element.setAttribute?.('dir', dirVal);
        this._autoDirApplied = true;
        const bcp = this.bcp47Locale();
        if (bcp) {
            this.element.lang = bcp;
            this.element.setAttribute?.('lang', bcp);
        }
    }

    getCollator() {
        const rawLoc = String(this.localeValue || '');
        if (this._collator && this._collatorLocale === rawLoc) {
            return this._collator;
        }
        const bcp = this.bcp47Locale(rawLoc);
        try {
            this._collator = new Intl.Collator(bcp, { numeric: true, sensitivity: 'base' });
        } catch (_) {
            this._collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
        }
        this._collatorLocale = rawLoc;
        return this._collator;
    }

    foldSearch(val) {
        return String(val || '')
            .normalize('NFC')
            .replace(/[\uFF01-\uFF5E]/g, (ch) => String.fromCharCode(ch.charCodeAt(0) - 0xFEE0))
            .replace(/^[#＃]/, '#')
            .toLowerCase()
            .replace(/\u0307/g, '')
            .replace(/ı/g, 'i')
            .replace(/ß/g, 'ss')
            .replace(/ς/g, 'σ');
    }

    parseLocalizedInt(raw) {
        const normalized = String(raw ?? '')
            .replace(/[\uFF10-\uFF19]/g, (ch) => String(ch.charCodeAt(0) - 0xFF10))
            .replace(/[\u0660-\u0669]/g, (ch) => String(ch.charCodeAt(0) - 0x0660))
            .replace(/[\u06F0-\u06F9]/g, (ch) => String(ch.charCodeAt(0) - 0x06F0))
            .replace(/[\u0966-\u096F]/g, (ch) => String(ch.charCodeAt(0) - 0x0966))
            .replace(/[\u09E6-\u09EF]/g, (ch) => String(ch.charCodeAt(0) - 0x09E6))
            .replace(/[\u0E50-\u0E59]/g, (ch) => String(ch.charCodeAt(0) - 0x0E50))
            .replace(/[\s\u00A0\u202F\u066C,.'’]/g, '');

        return parseInt(normalized, 10);
    }

    isRecursiveQueryReady(query = this.searchQuery) {
        const clean = String(query || '').replace(/^[#＃]+/, '').trim();
        return clean.length >= 2 || /[^\u0000-\u007F]/.test(clean);
    }

    unitLabel(key, fallback) {
        const dict = this.i18nValue || {};
        if (Object.prototype.hasOwnProperty.call(dict, key) && typeof dict[key] === 'string' && dict[key].trim() !== '') {
            return dict[key];
        }
        return fallback;
    }

    getNumberFormatter(decimals = 0, useGrouping = true) {
        const bcp = this.bcp47Locale();
        const cacheKey = `num:${bcp || 'default'}:${decimals}:${useGrouping ? 1 : 0}`;
        if (!this._intlCache) {
            this._intlCache = new Map();
        }
        if (this._intlCache.has(cacheKey)) {
            return this._intlCache.get(cacheKey);
        }
        let fmt = null;
        try {
            fmt = decimals > 0
                ? new Intl.NumberFormat(bcp, { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping })
                : new Intl.NumberFormat(bcp, { useGrouping });
        } catch (_) {
            fmt = null;
        }
        if (this._intlCache.size >= 32) {
            this._intlCache.clear();
        }
        this._intlCache.set(cacheKey, fmt);
        return fmt;
    }

    getDateTimeFormatter(dateStyle = 'short', timeStyle = 'short', legacyNumeric = false) {
        const bcp = this.bcp47Locale();
        const cacheKey = `dt:${bcp || 'default'}:${legacyNumeric ? 'legacy' : `${dateStyle}:${timeStyle}`}`;
        if (!this._intlCache) {
            this._intlCache = new Map();
        }
        if (this._intlCache.has(cacheKey)) {
            return this._intlCache.get(cacheKey);
        }
        let fmt = null;
        try {
            fmt = legacyNumeric
                ? new Intl.DateTimeFormat(bcp, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
                : new Intl.DateTimeFormat(bcp, { dateStyle, timeStyle });
        } catch (_) {
            fmt = null;
        }
        if (this._intlCache.size >= 32) {
            this._intlCache.clear();
        }
        this._intlCache.set(cacheKey, fmt);
        return fmt;
    }

    formatNumber(num, useGrouping = true) {
        const val = Number(num);
        if (!Number.isFinite(val)) {
            return '0';
        }
        const fmt = this.getNumberFormatter(0, useGrouping);
        return fmt ? fmt.format(val) : String(val);
    }

    formatDateTime(timestamp, dateStyle = 'short', timeStyle = 'short') {
        const raw = Number(timestamp);
        if (!Number.isFinite(raw) || raw <= 0) {
            return '—';
        }
        const ms = raw > 1e12 ? raw : raw * 1000;
        const date = new Date(ms);
        if (Number.isNaN(date.getTime())) {
            return '—';
        }
        const fmt = this.getDateTimeFormatter(dateStyle, timeStyle, false);
        if (fmt) {
            try {
                return fmt.format(date);
            } catch (_) { }
        }
        const pad = (n) => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    syncSortDirButton() {
        if (!this.hasSortDirBtnTarget) {
            return;
        }
        const dir = (this.sortDir || this.sortDirValue) === 'desc' ? 'desc' : 'asc';
        const label = this.t(dir === 'desc' ? 'sort.desc' : 'sort.asc');
        this.sortDirBtnTarget.textContent = dir === 'asc' ? '↑' : '↓';
        this.sortDirBtnTarget.title = label;
        this.sortDirBtnTarget.setAttribute?.('aria-label', label);
    }

    stopPropagation(event) {
        event.stopPropagation();
    }

    // Backdrop close requires mousedown AND click to both target the backdrop itself
    // (prevents closing when a text selection drag started inside the card ends on the backdrop).
    onModalBackdropMouseDown(event) {
        this.modalBackdropMouseDown = Boolean(event) && event.target === event.currentTarget;
    }

    consumeBackdropGesture(event) {
        const startedOnBackdrop = this.modalBackdropMouseDown === true;
        this.modalBackdropMouseDown = false;
        return startedOnBackdrop && Boolean(event) && event.target === event.currentTarget;
    }

    closeModalFromBackdrop(event) {
        if (this.consumeBackdropGesture(event)) {
            this.closeModal();
        }
    }

    closeLightboxFromBackdrop(event) {
        if (this.consumeBackdropGesture(event)) {
            this.closeLightbox();
        }
    }

    // =========================================================================
    // 0ms Client-Side UI Toggles (ZERO HTTP Requests)
    // =========================================================================
    isMobileViewport() {
        return typeof window !== 'undefined'
            && typeof window.matchMedia === 'function'
            && window.matchMedia('(max-width: 767.98px)').matches;
    }

    isMobileSidebarOpen() {
        return Boolean(
            this.isMobileViewport()
            && this.hasSidebarTarget
            && this.showTreeValue
            && !this.sidebarTarget.classList.contains('liveux-fm-hidden'),
        );
    }

    closeMobileSidebar() {
        if (!this.hasSidebarTarget) {
            return;
        }
        this.showTreeValue = false;
        this.sidebarTarget.classList.add('liveux-fm-hidden');
        if (this.hasShellTarget) {
            this.shellTarget.dataset.sidebarOpen = 'false';
        }
    }

    toggleSidebar() {
        if (!this.hasSidebarTarget) {
            return;
        }
        this.showTreeValue = !this.showTreeValue;
        this.sidebarTarget.classList.toggle('liveux-fm-hidden', !this.showTreeValue);
        if (this.hasShellTarget) {
            this.shellTarget.dataset.sidebarOpen = this.showTreeValue ? 'true' : 'false';
        }
        if (this.showTreeValue) {
            this.renderSidebarTree(false);
            if (!this.treeCache.has(this.volumeValue)) {
                this.refreshFullTree(true);
            }
        }
    }

    onItemSizeInput(event) {
        const val = parseInt(event.currentTarget.value, 10);
        if (Number.isNaN(val)) {
            return;
        }
        this.activeItemSize = Math.max(96, Math.min(320, val));
        this.syncItemSizeUi();
        try {
            localStorage.setItem(`liveux_fm_item_size:${this.profileValue || 'default'}`, String(this.activeItemSize));
        } catch (_) { }
    }

    syncItemSizeUi() {
        const px = Math.max(96, Math.min(320, this.activeItemSize || 158));
        const listThumb = Math.max(22, Math.min(54, Math.round(px * 0.2)));
        if (this.hasShellTarget) {
            this.shellTarget.dataset.sizeTier = px <= 132 ? 'compact' : 'normal';
            this.shellTarget.style.setProperty('--liveux-fm-card-min', `${px}px`);
            this.shellTarget.style.setProperty('--liveux-fm-list-thumb', `${listThumb}px`);
            if (this.cardAspectRatioValue) {
                this.shellTarget.style.setProperty('--liveux-fm-card-aspect', this.cardAspectRatioValue);
            }
        }
        if (this.hasItemSizeSliderTarget) {
            this.itemSizeSliderTarget.value = String(px);
        }
        if (this.hasItemSizeLabelTarget) {
            this.itemSizeLabelTarget.textContent = `${px}px`;
        }
    }

    setFolderStyle(event) {
        const next = event.currentTarget.dataset.folderStyle === 'card' ? 'card' : 'tile';
        this.activeFolderStyle = next;
        this.syncFolderStyleUi();
        try {
            localStorage.setItem(`liveux_fm_folder_style:${this.profileValue || 'default'}`, next);
        } catch (_) { }
        if (!this.trashMode && this.viewModeValue !== 'list') {
            this.applyClientView();
        }
    }

    syncFolderStyleUi() {
        if (this.hasShellTarget) {
            this.shellTarget.dataset.folderStyle = this.activeFolderStyle;
        }
        if (this.hasFolderStyleButtonsTarget) {
            this.folderStyleButtonsTarget.querySelectorAll('button').forEach((btn) => {
                btn.classList.toggle('is-active', btn.dataset.folderStyle === this.activeFolderStyle);
            });
        }
    }

    setColorScheme(event) {
        const raw = event.currentTarget.dataset.scheme;
        const next = (raw === 'light' || raw === 'dark') ? raw : 'auto';
        this.activeColorScheme = next;
        this.syncColorSchemeUi();
        try {
            localStorage.setItem(`liveux_fm_color_scheme:${this.profileValue || 'default'}`, next);
        } catch (_) { }
    }

    syncColorSchemeUi() {
        const scheme = this.activeColorScheme || 'auto';
        if (this.element) {
            this.element.setAttribute('data-fm-color-scheme', scheme);
            const dialog = this.element.closest?.('dialog');
            if (dialog) {
                dialog.setAttribute('data-fm-color-scheme', scheme);
            }
        }
        if (this.hasColorSchemeButtonsTarget) {
            this.colorSchemeButtonsTarget.querySelectorAll('button').forEach((btn) => {
                btn.classList.toggle('is-active', btn.dataset.scheme === scheme);
            });
        }
    }

    cancelRecursiveSearch() {
        if (this.recursiveTimer) {
            clearTimeout(this.recursiveTimer);
            this.recursiveTimer = null;
        }
        if (this.searchAbortController) {
            this.searchAbortController.abort();
            this.searchAbortController = null;
        }
        this.searchRequestToken = (this.searchRequestToken || 0) + 1;
        this.recursiveItems = null;
    }

    clearFilters() {
        this.cancelRecursiveSearch();
        this.searchQuery = '';
        this.rawSearchQuery = '';
        this.typeFilterValue = 'all';
        this.recursiveSearch = false;
        this.pageValue = 1;
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }
        if (this.hasRecursiveSearchBtnTarget) {
            this.recursiveSearchBtnTarget.classList.remove('is-active');
        }
        if (this.hasTypeFiltersTarget) {
            if (this.typeFiltersTarget.tagName === 'SELECT') {
                this.typeFiltersTarget.value = 'all';
            } else {
                this.typeFiltersTarget.querySelectorAll('button').forEach((btn) => {
                    btn.classList.toggle('is-active', btn.dataset.filter === 'all');
                });
            }
        }
        this.applyClientView();
    }

    startSidebarResize(event) {
        if (!this.hasSidebarTarget) {
            return;
        }
        event.preventDefault();
        const resizer = event.currentTarget;
        const startX = event.clientX;
        const startWidth = this.sidebarTarget.getBoundingClientRect().width;
        resizer.classList.add('is-dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';

        const dirFactor = this.isRtl() ? -1 : 1;
        const onMove = (e) => {
            const nextWidth = Math.min(480, Math.max(175, Math.round(startWidth + (e.clientX - startX) * dirFactor)));
            this.sidebarTarget.style.width = `${nextWidth}px`;
        };

        const cleanup = () => {
            resizer.classList.remove('is-dragging');
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            window.removeEventListener('blur', onUp);
        };

        const onUp = () => {
            this.activeDragCleanup = null;
            cleanup();
            try {
                if (this.hasSidebarTarget) {
                    const finalW = Math.round(this.sidebarTarget.getBoundingClientRect().width);
                    localStorage.setItem('liveux_fm_sidebar_width', String(finalW));
                }
            } catch (_) { }
        };

        this.registerDragCleanup(cleanup);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        window.addEventListener('blur', onUp);
    }

    closeSettingsPopover() {
        if (this.hasSettingsPopoverTarget) {
            this.settingsPopoverTarget.classList.remove('is-open');
        }
        const trigger = this.element?.querySelector?.('[data-action*="#toggleSettings"]');
        trigger?.setAttribute?.('aria-expanded', 'false');
    }

    toggleSettings(event) {
        event.stopPropagation();
        this.hideContextMenu();
        if (this.hasSettingsPopoverTarget) {
            const isOpen = this.settingsPopoverTarget.classList.toggle('is-open');
            event.currentTarget?.setAttribute?.('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    toggleTreeLines(event) {
        this.treeLinesValue = Boolean(event.currentTarget.checked);
        if (this.hasTreeTarget) {
            this.treeTarget.setAttribute('data-tree-lines', this.treeLinesValue ? 'true' : 'false');
        }
    }

    toggleThumbnails(event) {
        this.thumbnailsEnabledValue = Boolean(event.currentTarget.checked);
        if (!this.trashMode) {
            this.applyClientView();
        }
    }

    setThumbFit(event) {
        const fit = event.currentTarget.dataset.fit || 'contain';
        this.thumbFitValue = fit;
        this.element.querySelectorAll('.liveux-fm-stage').forEach((stage) => {
            stage.setAttribute('data-fit', fit);
        });
    }

    setViewMode(event) {
        const view = event.currentTarget.dataset.view || 'grid';
        if (this.viewModeValue === view) {
            return;
        }
        this.viewModeValue = view;
        if (this.hasViewButtonsTarget) {
            this.viewButtonsTarget.querySelectorAll('button').forEach((btn) => {
                btn.classList.toggle('is-active', btn.dataset.view === view);
            });
        }
        if (!this.trashMode) {
            this.applyClientView();
        }
    }

    async setTypeFilter(event) {
        const filter = event.currentTarget.value || event.currentTarget.dataset.filter || 'all';
        const wasTrash = this.trashMode;
        this.typeFilterValue = filter;
        this.pageValue = 1;

        if (this.hasTypeFiltersTarget) {
            if (this.typeFiltersTarget.tagName === 'SELECT') {
                this.typeFiltersTarget.value = filter;
            } else {
                this.typeFiltersTarget.querySelectorAll('button').forEach((btn) => {
                    btn.classList.toggle('is-active', btn.dataset.filter === filter);
                });
            }
        }
        if (this.recursiveSearch && this.isRecursiveQueryReady()) {
            if (wasTrash) {
                this.trashMode = false;
                this.syncTrashButton();
                this.syncTrashToolbarState();
            }
            await this.fetchRecursiveSearch();
            return;
        }
        if (wasTrash) {
            await this.exitTrashAndRestoreView();
            return;
        }
        if (this.isServerPaginatedDirectory()) {
            await this.fetchServerPage(1);
            return;
        }
        this.applyClientView();
    }

    toggleRecursiveSearch() {
        this.recursiveSearch = !this.recursiveSearch;
        if (this.hasRecursiveSearchBtnTarget) {
            this.recursiveSearchBtnTarget.classList.toggle('is-active', this.recursiveSearch);
        }
        this.pageValue = 1;
        if (!this.recursiveSearch) {
            this.cancelRecursiveSearch();
            if (!this.trashMode) {
                this.applyClientView();
            }
            return;
        }
        if (this.trashMode) {
            this.trashMode = false;
            this.syncTrashUi();
        }
        if (this.isRecursiveQueryReady()) {
            this.fetchRecursiveSearch();
        }
    }

    cancelScheduledRender(rafProp) {
        const handle = this[rafProp];
        if (handle !== null && handle !== undefined) {
            if (typeof cancelAnimationFrame === 'function') {
                cancelAnimationFrame(handle);
            }
            this[rafProp] = null;
        }
    }

    scheduleCoalescedRender(rafProp, callback) {
        this.cancelScheduledRender(rafProp);
        if (typeof requestAnimationFrame === 'function') {
            this[rafProp] = requestAnimationFrame(() => {
                this[rafProp] = null;
                if (!this.isDisconnected) {
                    callback();
                }
            });
            return;
        }
        callback();
    }

    async onSearchInput(event) {
        if (event?.isComposing) {
            return;
        }
        const wasTrash = this.trashMode;
        const rawValue = String(event?.currentTarget?.value ?? '');
        this.rawSearchQuery = rawValue.trim().normalize('NFC').replace(/^＃/, '#');
        this.searchQuery = this.foldSearch(rawValue.trim());
        this.pageValue = 1;

        if (this.recursiveTimer) {
            clearTimeout(this.recursiveTimer);
            this.recursiveTimer = null;
        }
        if (this.recursiveSearch) {
            if (wasTrash) {
                this.trashMode = false;
                this.syncTrashButton();
                this.syncTrashToolbarState();
            }
            if (this.isRecursiveQueryReady()) {
                this.recursiveTimer = setTimeout(() => {
                    this.recursiveTimer = null;
                    if (!this.isDisconnected) {
                        this.fetchRecursiveSearch();
                    }
                }, 280);
            } else {
                this.cancelRecursiveSearch();
                if (wasTrash) {
                    await this.exitTrashAndRestoreView();
                } else {
                    this.scheduleCoalescedRender('_searchRenderRaf', () => this.applyClientView());
                }
            }
            return;
        }
        if (wasTrash) {
            await this.exitTrashAndRestoreView();
            return;
        }
        if (this.isServerPaginatedDirectory()) {
            if (this.serverSearchTimer) {
                clearTimeout(this.serverSearchTimer);
                this.serverSearchTimer = null;
            }
            this.serverSearchTimer = setTimeout(() => {
                this.serverSearchTimer = null;
                if (!this.isDisconnected) {
                    this.fetchServerPage(1);
                }
            }, 220);
            return;
        }
        this.scheduleCoalescedRender('_searchRenderRaf', () => this.applyClientView());
    }

    async fetchRecursiveSearch() {
        const effectiveQuery = this.rawSearchQuery || this.searchQuery;
        if (!this.listUrlValue || !this.isRecursiveQueryReady(effectiveQuery)) {
            return;
        }
        if (this.searchAbortController) {
            this.searchAbortController.abort();
        }
        const controller = new AbortController();
        this.searchAbortController = controller;
        this.searchRequestToken = (this.searchRequestToken || 0) + 1;
        const searchToken = this.searchRequestToken;
        const requestedVolume = this.volumeValue;
        const requestedPath = this.pathValue;
        this.setLoading(true);

        const params = new URLSearchParams({
            profile: this.profileValue,
            volume: requestedVolume || '',
            path: requestedPath || '',
            locale: this.localeValue || '',
            recursive: '1',
            search: effectiveQuery,
            type: this.typeFilterValue || 'all',
        });

        try {
            const res = await fetch(`${this.listUrlValue}?${params.toString()}`, {
                signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json().catch(() => ({}));
            if (
                this.isDisconnected
                || controller.signal.aborted
                || searchToken !== this.searchRequestToken
                || requestedVolume !== this.volumeValue
                || requestedPath !== this.pathValue
                || this.trashMode
            ) {
                return;
            }
            if (!res.ok || !data.ok) {
                this.showStatus(data.error || this.t('errors.load_dir'), 'danger');
                return;
            }
            this.recursiveItems = Array.isArray(data.items) ? data.items : [];
            this.updatePerfBadge(data.fromCache, data.durationMs);
            this.applyClientView();
        } catch (err) {
            if (!this.isDisconnected && err.name !== 'AbortError' && searchToken === this.searchRequestToken) {
                this.showStatus(this.t('errors.network'), 'danger');
            }
        } finally {
            if (this.searchAbortController === controller) {
                this.searchAbortController = null;
                if (!this.isDisconnected) {
                    this.setLoading(false);
                }
            }
        }
    }

    setPerPage(event) {
        this.perPageValue = parseInt(event.currentTarget.value, 10) || 24;
        this.pageValue = 1;
        if (this.isServerPaginatedDirectory()) {
            this.fetchServerPage(1);
            return;
        }
        this.applyClientView();
    }

    setSortBy(event) {
        this.sortByValue = event.currentTarget.value || 'name';
        this.pageValue = 1;
        if (this.isServerPaginatedDirectory()) {
            this.fetchServerPage(1);
            return;
        }
        this.applyClientView();
    }

    sortByColumn(event) {
        if (this.capabilitiesValue?.showSort === false) {
            return;
        }
        const col = event.currentTarget.dataset.sortCol;
        if (!col || !['name', 'type', 'size', 'date'].includes(col)) {
            return;
        }
        if (this.sortByValue === col) {
            this.sortDirValue = this.sortDirValue === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortByValue = col;
            this.sortDirValue = 'asc';
        }
        if (this.hasSortBySelectTarget) {
            this.sortBySelectTarget.value = this.sortByValue;
        }
        this.syncSortDirButton();
        this.pageValue = 1;
        if (this.isServerPaginatedDirectory()) {
            this.fetchServerPage(1);
            return;
        }
        this.applyClientView();
    }

    toggleSortDir() {
        this.sortDirValue = this.sortDirValue === 'asc' ? 'desc' : 'asc';
        this.syncSortDirButton();
        this.pageValue = 1;
        if (this.isServerPaginatedDirectory()) {
            this.fetchServerPage(1);
            return;
        }
        this.applyClientView();
    }

    prevPage() {
        if (this.pageValue > 1) {
            this.pageValue -= 1;
            this.applyClientView();
        }
    }

    nextPage() {
        if (this.pageValue < this.totalPages) {
            this.pageValue += 1;
            this.applyClientView();
        }
    }

    hasFixedContainingBlockAncestor(el) {
        if (typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
            return false;
        }
        let parent = el ? el.parentElement : null;
        while (parent && parent !== document.body && parent !== document.documentElement) {
            const cs = window.getComputedStyle(parent);
            if (
                (cs.transform && cs.transform !== 'none')
                || (cs.perspective && cs.perspective !== 'none')
                || (cs.filter && cs.filter !== 'none')
                || (cs.backdropFilter && cs.backdropFilter !== 'none')
                || (cs.contain && /(paint|layout|strict|content)/.test(cs.contain))
            ) {
                return true;
            }
            parent = parent.parentElement;
        }
        return false;
    }

    onNativeFullscreenChange() {
        if (!this.element || this.element.closest?.('dialog')) {
            return;
        }
        const nativeActive = document.fullscreenElement === this.element;
        if (!nativeActive && this.usingNativeFullscreen) {
            this.usingNativeFullscreen = false;
            this.isFullscreen = false;
            this.element.classList.remove('is-fullscreen');
            if (this.prevBodyOverflow !== null && document.body) {
                document.body.style.overflow = this.prevBodyOverflow;
                this.prevBodyOverflow = null;
            }
            this.syncFullscreenButton();
        }
    }

    toggleFullscreen() {
        const dialog = this.element?.closest?.('dialog') || null;
        const currentlyFullscreen = dialog
            ? dialog.hasAttribute('data-fullscreen')
            : Boolean(this.element?.classList.contains('is-fullscreen'));
        this.isFullscreen = !currentlyFullscreen;
        if (dialog) {
            if (this.isFullscreen) {
                dialog.setAttribute('data-fullscreen', 'true');
            } else {
                dialog.removeAttribute('data-fullscreen');
            }
        } else if (this.element) {
            this.element.classList.toggle('is-fullscreen', this.isFullscreen);
            if (typeof document !== 'undefined' && document.body) {
                if (this.isFullscreen) {
                    if (this.prevBodyOverflow === null) {
                        this.prevBodyOverflow = document.body.style.overflow || '';
                    }
                    document.body.style.overflow = 'hidden';
                } else if (this.prevBodyOverflow !== null) {
                    document.body.style.overflow = this.prevBodyOverflow;
                    this.prevBodyOverflow = null;
                }
            }

            if (this.isFullscreen && this.hasFixedContainingBlockAncestor(this.element) && typeof this.element.requestFullscreen === 'function') {
                this.usingNativeFullscreen = true;
                Promise.resolve(this.element.requestFullscreen()).catch(() => {
                    this.usingNativeFullscreen = false;
                });
            } else if (!this.isFullscreen && typeof document !== 'undefined' && document.fullscreenElement === this.element && typeof document.exitFullscreen === 'function') {
                this.usingNativeFullscreen = false;
                Promise.resolve(document.exitFullscreen()).catch(() => { });
            }
        }
        this.syncFullscreenButton();
    }

    syncFullscreenButton() {
        if (!this.hasFullscreenBtnTarget) {
            return;
        }
        const label = this.isFullscreen ? this.t('actions.exit_fullscreen') : this.t('actions.fullscreen');
        this.fullscreenBtnTarget.title = `${label} (F)`;
        this.fullscreenBtnTarget.innerHTML = this.uiIcon(this.isFullscreen ? 'exit_fullscreen' : 'fullscreen', 14);
    }

    closeDialog() {
        const dialog = this.element.closest('dialog');
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    // =========================================================================
    // Full In-Memory Sidebar Tree (0ms Expand / Collapse / Search, ZERO AJAX!)
    // =========================================================================
    normalizeTreeFolders(folders) {
        if (!Array.isArray(folders)) {
            return [];
        }
        return folders.map((f) => ({
            ...f,
            _foldedName: typeof f?._foldedName === 'string' ? f._foldedName : this.foldSearch(f?.name),
            _foldedPath: typeof f?._foldedPath === 'string' ? f._foldedPath : this.foldSearch(f?.path),
        }));
    }

    onTreeSearchInput(event) {
        if (event?.isComposing) {
            return;
        }
        this.treeSearchQuery = this.foldSearch(String(event?.currentTarget?.value ?? '').trim());
        this.scheduleCoalescedRender('_treeRenderRaf', () => this.renderSidebarTree());
    }

    expandAllTree() {
        const folders = this.treeCache.get(this.volumeValue) || [];
        folders.forEach((f) => this.expandedFolders.add(`${this.volumeValue}::${f.path}`));
        this.renderSidebarTree();
    }

    collapseAllTree() {
        this.expandedFolders.clear();
        this.renderSidebarTree();
    }

    renderSidebarTree(scrollIntoView = true) {
        this.cancelScheduledRender('_treeRenderRaf');
        const allFolders = this.treeCache.get(this.volumeValue) || [];
        this.renderPinnedFolders(allFolders);

        if (this.hasTreeSearchWrapTarget) {
            const showSearch = allFolders.length >= 8 || Boolean(this.treeSearchQuery);
            this.treeSearchWrapTarget.classList.toggle('liveux-fm-hidden', !showSearch);
            if (this.hasTreeTitleLabelTarget) {
                this.treeTitleLabelTarget.classList.toggle('liveux-fm-hidden', showSearch);
            }
        }

        if (!this.hasTreeTarget) {
            return;
        }
        const q = this.treeSearchQuery ? this.foldSearch(String(this.treeSearchQuery).trim()) : '';
        const folders = q
            ? allFolders.filter((f) => (
                (typeof f._foldedName === 'string' ? f._foldedName : this.foldSearch(f.name)).includes(q)
                || (typeof f._foldedPath === 'string' ? f._foldedPath : this.foldSearch(f.path)).includes(q)
            ))
            : allFolders;

        const byParent = new Map();
        folders.forEach((f) => {
            const p = q ? '' : (f.parent || '');
            if (!byParent.has(p)) {
                byParent.set(p, []);
            }
            byParent.get(p).push(f);
        });

        this.treeTarget.querySelectorAll('[data-volume-tree-container]').forEach((box) => {
            if (box.dataset.volumeTreeContainer === this.volumeValue) {
                box.innerHTML = this.buildTreeUl('', byParent);
            } else {
                box.innerHTML = '';
            }
        });
        this.syncActiveTreeNode(scrollIntoView);
    }

    onTreeRowKeydown(event) {
        if (event.isComposing || event.keyCode === 229) {
            return;
        }
        if (event.target?.closest?.('.liveux-fm-tree-chevron')) {
            return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.navigate(event);
        }
    }

    renderFolderCountHtml(count, variant = 'tile') {
        if (this.capabilitiesValue?.showFolderItemCount !== true || typeof count !== 'number' || !Number.isFinite(count) || count < 0) {
            return '';
        }
        const safeCount = Math.floor(count);
        const formatted = this.escapeHtml(this.formatNumber(safeCount));
        if (variant === 'text') {
            return formatted;
        }
        if (variant === 'tree') {
            return `<span class="liveux-fm-tree-count" dir="ltr">${formatted}</span>`;
        }
        if (variant === 'list') {
            return `<span class="liveux-fm-muted liveux-fm-mono liveux-fm-shrink-0" dir="ltr" style="font-size:0.72rem;">${formatted}</span>`;
        }
        return `<span class="liveux-fm-folder-count" dir="ltr">${formatted}</span>`;
    }

    renderPinnedFolders(allFolders) {
        if (!this.hasPinnedFoldersTarget) {
            return;
        }
        const pinned = allFolders.filter((f) => Boolean(f.pinned));
        if (pinned.length === 0) {
            this.pinnedFoldersTarget.classList.add('liveux-fm-hidden');
            this.pinnedFoldersTarget.innerHTML = '';
            return;
        }

        const safeVol = this.escapeAttr(this.volumeValue);
        const rows = pinned.map((f) => {
            const safePath = this.escapeAttr(f.path);
            const safeName = this.escapeHtml(f.name);
            const safeNameAttr = this.escapeAttr(f.name);
            const activeClass = !this.trashMode && this.pathValue === f.path ? 'is-active' : '';
            const folderSvg = this.renderFolderSvg(14, f.color, activeClass !== '', f.icon || null);
            return `<div class="liveux-fm-tree-row ${activeClass}"
                         role="treeitem"
                         tabindex="0"
                         title="/${safePath}"
                         data-tree-node="1"
                         data-ctx-kind="folder"
                         data-folder-path="${safePath}"
                         data-path="${safePath}"
                         data-name="${safeNameAttr}"
                         data-color="${this.escapeAttr(f.color || '')}"
                         data-icon="${this.escapeAttr(f.icon || '')}"
                         data-pinned="1"
                         data-volume="${safeVol}"
                         data-action="click->${CTRL}#navigate keydown->${CTRL}#onTreeRowKeydown dragover->${CTRL}#onFolderDragOver dragleave->${CTRL}#onFolderDragLeave drop->${CTRL}#onFolderDrop">
                ${folderSvg}
                <span class="liveux-fm-truncate liveux-fm-grow">${safeName}</span>
                ${this.renderFolderCountHtml(f.itemCount, 'tree')}
            </div>`;
        }).join('');

        this.pinnedFoldersTarget.classList.remove('liveux-fm-hidden');
        this.pinnedFoldersTarget.innerHTML = `
            <div class="liveux-fm-section-label" style="padding: 0 4px 4px;">⭐ ${this.escapeHtml(this.t('sidebar.pinned'))}</div>
            ${rows}
        `;
    }

    async toggleFolderPin(path) {
        const key = String(path ?? '');
        if (!key || this.isTogglingPin || (this.togglingPinPaths && this.togglingPinPaths.has(key))) {
            return;
        }
        if (!this.togglingPinPaths) {
            this.togglingPinPaths = new Set();
        }
        this.isTogglingPin = true;
        this.togglingPinPaths.add(key);
        const pinBtn = this.hasInspectorTarget ? this.inspectorTarget.querySelector?.('[data-insp-pin]') : null;
        if (pinBtn) {
            pinBtn.disabled = true;
        }
        try {
            if (await this.callAction('togglePin', { path: key })) {
                this.invalidateClientCaches();
                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
            }
        } finally {
            this.togglingPinPaths.delete(key);
            this.isTogglingPin = this.togglingPinPaths.size > 0;
            if (pinBtn && pinBtn.isConnected !== false) {
                pinBtn.disabled = false;
            }
        }
    }

    renderQuota(quota) {
        const showQuota = this.capabilitiesValue?.showQuota !== false;
        const enabled = showQuota && Boolean(quota && quota.enabled);
        const pct = Math.min(100, Math.max(0, Number(quota?.percent) || 0));
        const usedFmt = typeof quota?.usedBytes === 'number'
            ? this.formatBytes(quota.usedBytes)
            : (quota?.usedFormatted || '0 B');
        const maxFmt = typeof quota?.maxBytes === 'number' && quota.maxBytes > 0
            ? this.formatBytes(quota.maxBytes)
            : (quota?.maxFormatted || '');
        const labelText = `${usedFmt} / ${maxFmt}`;
        const isDanger = pct >= 90;
        const isWarning = !isDanger && pct >= 75;

        if (this.hasQuotaBoxTarget) {
            this.quotaBoxTarget.classList.toggle('liveux-fm-hidden', !enabled);
        }
        if (this.hasQuotaPillTarget) {
            this.quotaPillTarget.classList.toggle('liveux-fm-hidden', !enabled);
            this.quotaPillTarget.classList.toggle('is-danger', enabled && isDanger);
            this.quotaPillTarget.classList.toggle('is-warning', enabled && isWarning);
        }
        if (this.hasSettingsQuotaBadgeTarget) {
            this.settingsQuotaBadgeTarget.textContent = enabled ? `${this.formatNumber(pct)}%` : this.t('ui.stats');
        }
        if (!enabled) {
            return;
        }
        if (this.hasQuotaTextTarget) {
            this.quotaTextTarget.textContent = labelText;
        }
        if (this.hasQuotaPillTextTarget) {
            this.quotaPillTextTarget.textContent = labelText;
        }
        if (this.hasQuotaPercentTarget) {
            this.quotaPercentTarget.textContent = `${this.formatNumber(pct)}%`;
            this.quotaPercentTarget.classList.toggle('is-danger', isDanger);
            this.quotaPercentTarget.classList.toggle('is-warning', isWarning);
        }
        if (this.hasQuotaBarTarget) {
            this.quotaBarTarget.style.width = `${pct}%`;
            this.quotaBarTarget.setAttribute('aria-valuenow', String(pct));
            this.quotaBarTarget.classList.toggle('is-danger', isDanger);
            this.quotaBarTarget.classList.toggle('is-warning', isWarning);
        }
    }

    async openQuotaDetailsModal() {
        if (this.capabilitiesValue?.showQuota === false || this.capabilitiesValue?.showQuotaDetails === false) {
            return;
        }
        this.closeSettingsPopover();

        this.openModal(this.t('quota.details_title'), `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-usage-loading">
                    <span class="liveux-fm-spinner liveux-fm-spinner-xs" aria-hidden="true"></span>
                    <span>${this.escapeHtml(this.t('preview.loading'))}</span>
                </div>
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-fm-cancel>${this.escapeHtml(this.t('actions.close'))}</button>
            </div>
        `);
        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
        const loadingNode = this.modalBodyTarget.querySelector('.liveux-fm-usage-loading');

        const data = await this.callActionJson('quotaDetails', {}, { silent: true });
        if (this.isDisconnected || !this.isModalOpen() || !loadingNode || !this.modalBodyTarget.contains(loadingNode)) {
            // Modal was closed or replaced by another modal while loading.
            return;
        }

        if (!data) {
            this.modalBodyTarget.innerHTML = `
                <div class="liveux-fm-modal-body">
                    <p class="liveux-fm-muted liveux-fm-text-sm" style="margin: 0;">${this.escapeHtml(this.t('errors.load_dir'))}</p>
                </div>
                <div class="liveux-fm-modal-footer">
                    <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-fm-cancel>${this.escapeHtml(this.t('actions.close'))}</button>
                </div>`;
            this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
            return;
        }

        if (data.summary) {
            this.renderQuota(data.summary);
        }

        this.modalBodyTarget.innerHTML = this.renderQuotaDetailsHtml(data);
        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
        this.modalBodyTarget.querySelectorAll('[data-quota-nav]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetPath = btn.dataset.quotaNav || '';
                this.closeModal();
                this.navigateTo(targetPath);
            });
        });
        const firstFocusable = this.modalBodyTarget.querySelector('button:not([disabled]), [tabindex="0"]');
        firstFocusable?.focus?.({ preventScroll: true });
    }

    renderQuotaDetailsHtml(data) {
        const summary = data.summary || {};
        const counts = data.counts || {};
        const categories = Array.isArray(data.categories) ? data.categories : [];
        const topFolders = Array.isArray(data.topFolders) ? data.topFolders : [];
        const largestFiles = Array.isArray(data.largestFiles) ? data.largestFiles : [];
        const enabled = Boolean(summary.enabled);
        const pct = Math.min(100, Math.max(0, Number(summary.percent) || 0));
        const fmtBytesOr = (bytes, fallback = '0 B') => (typeof bytes === 'number' ? this.formatBytes(bytes) : (fallback || '0 B'));
        const usedSummaryFmt = fmtBytesOr(summary.usedBytes, summary.usedFormatted);
        const remSummaryFmt = fmtBytesOr(summary.remainingBytes, summary.remainingFormatted);
        const maxSummaryFmt = typeof summary.maxBytes === 'number' && summary.maxBytes > 0
            ? this.formatBytes(summary.maxBytes)
            : (summary.maxFormatted || '');
        const trashBytesFmt = fmtBytesOr(counts.trashBytes, counts.trashFormatted);

        const catColors = {
            image: '#2563eb',
            video: '#9333ea',
            doc: '#0d9488',
            archive: '#d97706',
            other: '#64748b',
            trash: '#dc2626',
        };
        const catLabels = {
            image: this.t('quota.cat_image'),
            video: this.t('quota.cat_video'),
            doc: this.t('quota.cat_doc'),
            archive: this.t('quota.cat_archive'),
            other: this.t('quota.cat_other'),
        };

        const segments = [];
        for (const cat of categories) {
            const p = Math.max(0, Math.min(100, Number(cat.percent) || 0));
            if (p > 0) {
                const color = (Object.hasOwn(catColors, cat.key) ? catColors[cat.key] : '#64748b');
                const lbl = (Object.hasOwn(catLabels, cat.key) ? catLabels[cat.key] : cat.key);
                const catFmt = fmtBytesOr(cat.bytes, cat.formatted);
                segments.push(`<span class="liveux-fm-quota-seg" style="width:${p}%;background:${color};" title="${this.escapeAttr(`${lbl}: ${catFmt} (${p}%)`)}"></span>`);
            }
        }
        const trashPct = Math.max(0, Math.min(100, Number(counts.trashPercent) || 0));
        if (trashPct > 0) {
            segments.push(`<span class="liveux-fm-quota-seg" style="width:${trashPct}%;background:${catColors.trash};" title="${this.escapeAttr(`${this.t('quota.trash_usage')}: ${trashBytesFmt}`)}"></span>`);
        }

        const legendHtml = categories.map((cat) => {
            const color = (Object.hasOwn(catColors, cat.key) ? catColors[cat.key] : '#64748b');
            const lbl = (Object.hasOwn(catLabels, cat.key) ? catLabels[cat.key] : cat.key);
            const catFmt = fmtBytesOr(cat.bytes, cat.formatted);
            return `<div class="liveux-fm-quota-cat">
                <span class="liveux-fm-quota-dot" style="background:${color};"></span>
                <span class="liveux-fm-truncate">${this.escapeHtml(lbl)}</span>
                <span class="liveux-fm-mono liveux-fm-muted">${this.escapeHtml(catFmt)}</span>
                <span class="liveux-fm-quota-cnt" dir="ltr">(${this.escapeHtml(this.formatNumber(Number(cat.count) || 0))})</span>
            </div>`;
        }).join('');

        const trashRow = (Number(counts.trashBytes) || 0) > 0
            ? `<div class="liveux-fm-quota-cat">
                <span class="liveux-fm-quota-dot" style="background:${catColors.trash};"></span>
                <span class="liveux-fm-truncate">${this.escapeHtml(this.t('quota.trash_usage'))}</span>
                <span class="liveux-fm-mono liveux-fm-muted">${this.escapeHtml(trashBytesFmt)}</span>
                <span class="liveux-fm-quota-cnt" dir="ltr">(${this.escapeHtml(this.formatNumber(Number(counts.trashFiles) || 0))})</span>
            </div>`
            : '';

        const foldersHtml = topFolders.length > 0
            ? topFolders.map((f) => {
                const label = f.path ? `/${f.path}` : this.t('quota.root_folder');
                const dirAttr = f.path ? ' dir="ltr"' : '';
                const fFmt = fmtBytesOr(f.bytes, f.formatted);
                return `<button type="button" class="liveux-fm-quota-row" data-quota-nav="${this.escapeAttr(f.path || '')}">
                    <span class="liveux-fm-truncate liveux-fm-strong"${dirAttr}>${this.escapeHtml(label)}</span>
                    <span class="liveux-fm-row-sm liveux-fm-shrink-0">
                        <span class="liveux-fm-muted liveux-fm-text-xs" dir="ltr">${this.escapeHtml(this.formatNumber(Number(f.fileCount) || 0))}</span>
                        <span class="liveux-fm-mono">${this.escapeHtml(fFmt)}</span>
                    </span>
                </button>`;
            }).join('')
            : `<div class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('quota.empty_storage'))}</div>`;

        const filesHtml = largestFiles.length > 0
            ? largestFiles.map((f) => `<button type="button" class="liveux-fm-quota-row" data-quota-nav="${this.escapeAttr(f.folder || '')}" title="${this.escapeAttr(f.path || '')}">
                    <span class="liveux-fm-truncate">${this.escapeHtml(f.name || '')}</span>
                    <span class="liveux-fm-mono liveux-fm-shrink-0">${this.escapeHtml(fmtBytesOr(f.bytes, f.formatted))}</span>
                </button>`).join('')
            : `<div class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('quota.empty_storage'))}</div>`;

        const limitDisplay = enabled ? `${remSummaryFmt} / ${maxSummaryFmt}` : this.t('quota.unlimited');
        const cappedHtml = data.capped === true
            ? `<div class="liveux-fm-muted liveux-fm-text-xs" style="margin-top:8px;">${this.escapeHtml(this.t('quota.capped_notice'))}</div>`
            : '';

        return `
            <div class="liveux-fm-modal-body liveux-fm-quota-modal-body">
                <div class="liveux-fm-quota-stats">
                    <div class="liveux-fm-quota-stat">
                        <span class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('quota.used'))}</span>
                        <strong class="liveux-fm-mono" dir="ltr">${this.escapeHtml(usedSummaryFmt)}${enabled ? ` (${this.escapeHtml(this.formatNumber(pct))}%)` : ''}</strong>
                    </div>
                    <div class="liveux-fm-quota-stat">
                        <span class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('quota.remaining'))}</span>
                        <strong class="liveux-fm-mono" dir="ltr">${this.escapeHtml(limitDisplay)}</strong>
                    </div>
                    <div class="liveux-fm-quota-stat">
                        <span class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('quota.files_folders'))}</span>
                        <strong class="liveux-fm-mono" dir="ltr">${this.escapeHtml(this.formatNumber(Number(counts.files) || 0))} / ${this.escapeHtml(this.formatNumber(Number(counts.folders) || 0))}</strong>
                    </div>
                </div>

                <div class="liveux-fm-quota-stacked" aria-label="${this.escapeAttr(this.t('quota.by_type'))}">${segments.join('')}</div>
                <div class="liveux-fm-quota-legend">${legendHtml}${trashRow}</div>

                <div class="liveux-fm-quota-cols">
                    <div class="liveux-fm-quota-col">
                        <div class="liveux-fm-section-label" style="margin-bottom:6px;">${this.escapeHtml(this.t('quota.top_folders'))}</div>
                        <div class="liveux-fm-quota-list">${foldersHtml}</div>
                    </div>
                    <div class="liveux-fm-quota-col">
                        <div class="liveux-fm-section-label" style="margin-bottom:6px;">${this.escapeHtml(this.t('quota.largest_files'))}</div>
                        <div class="liveux-fm-quota-list">${filesHtml}</div>
                    </div>
                </div>
                ${cappedHtml}
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-fm-cancel>${this.escapeHtml(this.t('actions.close'))}</button>
            </div>
        `;
    }

    buildTreeUl(parentPath, byParent, collator = null) {
        const rawList = byParent.get(parentPath) || [];
        if (rawList.length === 0) {
            return '';
        }
        const activeCollator = collator || this.getCollator();
        const list = [...rawList].sort((a, b) => activeCollator.compare(String(a.name || ''), String(b.name || '')));

        const rows = list.map((f) => {
            const hasChildren = (byParent.get(f.path) || []).length > 0;
            const isExpanded = this.expandedFolders.has(`${this.volumeValue}::${f.path}`);
            const safePath = this.escapeAttr(f.path);
            const safeVol = this.escapeAttr(this.volumeValue);
            const safeName = this.escapeHtml(f.name);
            const safeNameAttr = this.escapeAttr(f.name);
            const activeClass = !this.trashMode && this.pathValue === f.path ? 'is-active' : '';
            const folderSvg = this.renderFolderSvg(15, f.color, activeClass !== '', f.icon || null);

            const chevron = hasChildren
                ? `<button type="button" class="liveux-fm-tree-chevron" aria-expanded="${isExpanded ? 'true' : 'false'}" aria-label="${safeNameAttr}" data-folder-path="${safePath}" data-action="click->${CTRL}#toggleTreeBranch">${isExpanded ? '▾' : '▸'}</button>`
                : `<span style="width:18px;display:inline-block;flex-shrink:0;"></span>`;

            const childrenHtml = hasChildren && isExpanded ? this.buildTreeUl(f.path, byParent, activeCollator) : '';

            return `<li>
                <div class="liveux-fm-tree-row ${activeClass}"
                     role="treeitem"
                     aria-selected="${activeClass !== '' ? 'true' : 'false'}"
                     tabindex="0"
                     title="${safeNameAttr}"
                     data-tree-node="1"
                     data-ctx-kind="folder"
                     data-folder-path="${safePath}"
                     data-path="${safePath}"
                     data-name="${safeNameAttr}"
                     data-color="${this.escapeAttr(f.color || '')}"
                     data-icon="${this.escapeAttr(f.icon || '')}"
                     data-pinned="${f.pinned ? '1' : '0'}"
                     data-volume="${safeVol}"
                     data-action="click->${CTRL}#navigate keydown->${CTRL}#onTreeRowKeydown dragover->${CTRL}#onFolderDragOver dragleave->${CTRL}#onFolderDragLeave drop->${CTRL}#onFolderDrop">
                    ${chevron}
                    ${folderSvg}
                    <span class="liveux-fm-truncate liveux-fm-grow">${safeName}</span>
                    ${this.renderFolderCountHtml(f.itemCount, 'tree')}
                </div>
                ${childrenHtml}
            </li>`;
        }).join('');

        return `<ul class="liveux-fm-tree-children" role="group">${rows}</ul>`;
    }

    toggleTreeBranch(event) {
        event.stopPropagation();
        const folderPath = event.currentTarget.dataset.folderPath || '';
        const key = `${this.volumeValue}::${folderPath}`;
        if (this.expandedFolders.has(key)) {
            this.expandedFolders.delete(key);
        } else {
            this.expandedFolders.add(key);
        }
        // 0ms pure client-side re-render of the sidebar tree!
        this.renderSidebarTree();
        if (this.hasTreeTarget) {
            const chevrons = this.treeTarget.querySelectorAll('.liveux-fm-tree-chevron');
            for (const btn of chevrons) {
                if ((btn.dataset?.folderPath || '') === folderPath) {
                    btn.focus?.();
                    break;
                }
            }
        }
    }

    syncActiveTreeNode(scrollIntoView = true) {
        let activeRow = null;
        const roots = [];
        if (this.hasTreeTarget) {
            roots.push(this.treeTarget);
        }
        if (this.hasPinnedFoldersTarget) {
            roots.push(this.pinnedFoldersTarget);
        }
        roots.forEach((root) => {
            root.querySelectorAll('[data-tree-node]').forEach((row) => {
                const active = !this.trashMode && row.dataset.volume === this.volumeValue && (row.dataset.path || '') === this.pathValue;
                row.classList.toggle('is-active', active);
                row.setAttribute?.('aria-selected', active ? 'true' : 'false');
                if (active && root === this.treeTarget) {
                    activeRow = row;
                }
            });
        });
        if (activeRow && scrollIntoView) {
            activeRow.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    async refreshFullTree(force = false, serverRefresh = false, allowHidden = false) {
        const requestedVol = this.volumeValue;
        if (!allowHidden && (this.showTreeValue === false || this.hasSidebarTarget === false)) {
            if (force || serverRefresh) {
                this.treeCache.delete(requestedVol);
            }
            return;
        }
        if (!force && !serverRefresh && this.treeCache.has(requestedVol)) {
            this.renderSidebarTree();
            return;
        }
        if (!this.treeUrlValue) {
            return;
        }
        if (this.treeAbortController) {
            this.treeAbortController.abort();
        }
        const controller = new AbortController();
        this.treeAbortController = controller;
        try {
            const params = new URLSearchParams({
                profile: this.profileValue,
                volume: requestedVol,
                locale: this.localeValue || '',
                full: '1',
            });
            if (serverRefresh) {
                params.set('refresh', '1');
            }
            const res = await fetch(`${this.treeUrlValue}?${params.toString()}`, {
                signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json().catch(() => ({}));
            if (this.isDisconnected || controller.signal.aborted || this.treeAbortController !== controller) {
                return;
            }
            if (res.ok && data.ok) {
                this.treeCache.set(requestedVol, this.normalizeTreeFolders(data.folders));
                if (this.volumeValue === requestedVol) {
                    this.renderSidebarTree();
                }
            }
        } catch (_) {
        } finally {
            if (this.treeAbortController === controller) {
                this.treeAbortController = null;
            }
        }
    }

    // =========================================================================
    // Navigation with Client-Side dirCache (0ms on visited folders!)
    // =========================================================================
    async navigate(event) {
        const el = event.currentTarget;
        const targetPath = el.dataset.path ?? el.dataset.folderPath ?? '';
        const targetVolume = el.dataset.volume || this.volumeValue;
        if (this.isMobileSidebarOpen()) {
            this.closeMobileSidebar();
        }
        await this.navigateTo(targetPath, targetVolume);
    }

    async navigateTo(targetPath, targetVolume = this.volumeValue, forceReload = false, options = {}) {
        this.dragging = null;
        this.hideDropOverlay();
        const hadRecursiveState = this.recursiveItems !== null || Boolean(this.recursiveTimer) || Boolean(this.searchAbortController);
        this.cancelRecursiveSearch();
        this.navToken = (this.navToken || 0) + 1;
        const navToken = this.navToken;
        // Roll back to the last COMMITTED location, not to the optimistic state of a still in-flight navigation.
        if (!this.navRollbackBase) {
            this.navRollbackBase = {
                volume: this.volumeValue,
                volumeLabel: this.volumeLabelValue,
                path: this.pathValue,
                trashMode: this.trashMode,
                history: this.history.slice(),
                historyIndex: this.historyIndex,
            };
        }
        const base = this.navRollbackBase;
        const prevVolume = base.volume;
        const prevVolumeLabel = base.volumeLabel;
        const prevPath = base.path;
        const prevTrashMode = base.trashMode;
        const prevHistory = base.history.slice();
        const prevHistoryIndex = base.historyIndex;

        const volumeChanged = targetVolume !== this.volumeValue;
        this.trashMode = false;
        this.volumeValue = targetVolume;
        this.pathValue = targetPath;
        this.pageValue = 1;
        this.lastSelectedPath = null;
        if (hadRecursiveState) {
            this.searchQuery = '';
            this.rawSearchQuery = '';
            if (this.hasSearchInputTarget) {
                this.searchInputTarget.value = '';
            }
        }

        if (options.fromHistory && Number.isInteger(options.targetHistoryIndex)) {
            this.historyIndex = options.targetHistoryIndex;
        }
        const currentEntry = this.history[this.historyIndex];
        if (!options.fromHistory && (!currentEntry || currentEntry.volume !== targetVolume || currentEntry.path !== targetPath)) {
            this.history = this.history.slice(0, this.historyIndex + 1);
            this.history.push({ volume: targetVolume, path: targetPath });
            if (this.history.length > 50) {
                this.history.shift();
            }
            this.historyIndex = this.history.length - 1;
        }

        this.clearSelection();
        this.syncTrashButton();
        this.syncTrashToolbarState();
        this.syncNavButtons();

        // Auto-expand ancestor folders in the tree
        if (targetPath) {
            let acc = '';
            targetPath.split('/').filter(Boolean).forEach((seg) => {
                acc = acc ? `${acc}/${seg}` : seg;
                this.expandedFolders.add(`${targetVolume}::${acc}`);
            });
        }

        if (this.hasVolumeSelectTarget && (volumeChanged || this.volumeSelectTarget.value !== targetVolume)) {
            this.volumeSelectTarget.value = targetVolume;
            this.volumeLabelValue = this.volumeSelectTarget.options[this.volumeSelectTarget.selectedIndex]?.text || targetVolume;
        }

        const key = this.cacheKey(targetVolume, targetPath);
        if (!forceReload && this.dirCache.has(key)) {
            // 0ms instant navigation from memory! Invalidate any in-flight directory fetch first.
            this.dirRequestToken++;
            if (this.abortController) {
                this.abortController.abort();
                this.abortController = null;
                this.setLoading(false);
            }
            this.navRollbackBase = null;
            this.persistLastLocation(targetVolume, targetPath);
            this.renderBreadcrumbs(this.buildBreadcrumbs(targetPath));
            this.updatePerfBadge(true, 0.1);
            this.applyClientView();
            if (volumeChanged) {
                await this.refreshFullTree(false);
            } else {
                this.renderSidebarTree();
            }
            return true;
        }

        const loaded = await this.fetchDirectory(
            targetVolume,
            targetPath,
            Boolean(options.silent),
            Boolean(options.serverRefresh),
            Boolean(options.allowFallback),
        );
        if (!loaded) {
            // `null` = superseded / aborted request (never roll back over newer state); `false` = genuine failure.
            if (loaded === null || this.navToken !== navToken) {
                // A newer navigation started (or this fetch was aborted): propagate `null` so callers do not roll back.
                return null;
            }
            this.navRollbackBase = null;
            this.volumeValue = prevVolume;
            this.volumeLabelValue = prevVolumeLabel;
            this.pathValue = prevPath;
            this.trashMode = prevTrashMode;
            this.history = prevHistory;
            this.historyIndex = prevHistoryIndex;
            if (this.hasVolumeSelectTarget) {
                this.volumeSelectTarget.value = prevVolume;
            }
            if (prevTrashMode && typeof this.syncTrashUi === 'function') {
                this.syncTrashUi();
            } else {
                this.syncTrashButton();
                this.syncTrashToolbarState();
                this.renderBreadcrumbs(this.buildBreadcrumbs(prevPath));
            }
            this.syncNavButtons();
            this.renderSidebarTree();
            return false;
        }
        if (this.navToken === navToken) {
            this.navRollbackBase = null;
        }
        if (volumeChanged || this.lastFetchFallbackApplied) {
            await this.refreshFullTree(Boolean(this.lastFetchFallbackApplied), Boolean(this.lastFetchFallbackApplied));
        } else {
            this.renderSidebarTree();
        }
        return true;
    }

    getAvailableVolumeNames() {
        const set = new Set();
        if (this.volumeValue) {
            set.add(this.volumeValue);
        }
        this.element.querySelectorAll('[data-volume-group]').forEach((el) => {
            const v = (el.dataset.volumeGroup || '').trim();
            if (v) {
                set.add(v);
            }
        });
        if (this.hasVolumeSelectTarget) {
            Array.from(this.volumeSelectTarget.options).forEach((opt) => {
                const v = (opt.value || '').trim();
                if (v) {
                    set.add(v);
                }
            });
        }
        return set;
    }

    persistLastLocation(volume, path) {
        if (!this.rememberLastDirValue || !this.profileValue) {
            return;
        }
        const vol = volume || '';
        const dir = path || '';
        try {
            if (vol) {
                localStorage.setItem(`liveux_fm_last_dir:${this.profileValue}:${vol}`, dir);
            }
            if (!this.explicitVolumeRequested) {
                localStorage.setItem(`liveux_fm_last_vol:${this.profileValue}`, vol);
                localStorage.setItem(`liveux_fm_last_dir:${this.profileValue}`, dir);
            }
        } catch (_) { }
    }

    clearLastLocation(volume = null) {
        if (!this.profileValue) {
            return;
        }
        const vol = volume ?? this.volumeValue ?? '';
        try {
            if (vol) {
                localStorage.removeItem(`liveux_fm_last_dir:${this.profileValue}:${vol}`);
            }
            if (!this.explicitVolumeRequested) {
                localStorage.removeItem(`liveux_fm_last_vol:${this.profileValue}`);
                localStorage.removeItem(`liveux_fm_last_dir:${this.profileValue}`);
            }
        } catch (_) { }
    }

    getSavedDirForVolume(volume, savedGlobalVol = '') {
        if (!this.profileValue) {
            return '';
        }
        try {
            const scoped = localStorage.getItem(`liveux_fm_last_dir:${this.profileValue}:${volume || ''}`);
            if (scoped !== null) {
                const trimmedScoped = scoped.trim();
                return trimmedScoped.includes('..') ? '' : trimmedScoped;
            }
            const globalVol = savedGlobalVol || (localStorage.getItem(`liveux_fm_last_vol:${this.profileValue}`) || '').trim();
            if (globalVol && globalVol === volume) {
                const legacy = (localStorage.getItem(`liveux_fm_last_dir:${this.profileValue}`) || '').trim();
                return legacy.includes('..') ? '' : legacy;
            }
        } catch (_) { }
        return '';
    }

    async restoreLastVisitedLocation() {
        try {
            const savedVol = (localStorage.getItem(`liveux_fm_last_vol:${this.profileValue}`) || '').trim();
            const available = this.getAvailableVolumeNames();

            // When an explicit volume was requested on open (e.g. Picker field pinned to a volume),
            // never switch away from this.volumeValue to another savedVol.
            let targetVol = this.volumeValue;
            if (!this.explicitVolumeRequested && savedVol) {
                if (!available.has(savedVol)) {
                    this.clearLastLocation(savedVol);
                    return;
                }
                targetVol = savedVol;
            }

            const rawScopedDir = localStorage.getItem(`liveux_fm_last_dir:${this.profileValue}:${targetVol || ''}`);
            const rawLegacyDir = (!savedVol || savedVol === targetVol)
                ? localStorage.getItem(`liveux_fm_last_dir:${this.profileValue}`)
                : null;
            const savedDir = String(rawScopedDir !== null ? rawScopedDir : (rawLegacyDir || '')).trim();

            if (savedDir.includes('..')) {
                this.clearLastLocation(targetVol);
                return;
            }

            const expectedProfile = this.profileValue;
            if (targetVol !== this.volumeValue) {
                const initialVol = this.volumeValue;
                const initialLabel = this.volumeLabelValue;
                const rollbackInitial = () => {
                    this.volumeValue = initialVol;
                    this.volumeLabelValue = initialLabel;
                    this.pathValue = '';
                    if (this.hasVolumeSelectTarget) {
                        this.volumeSelectTarget.value = initialVol;
                    }
                    this.renderSidebarTree();
                    this.renderBreadcrumbs([]);
                    this.clearLastLocation(targetVol);
                };
                const tokenBeforeRestore = this.navToken;
                const ok = await this.navigateTo(savedDir, targetVol, false, { silent: true, allowFallback: true });
                if (ok === false && !this.isDisconnected && this.profileValue === expectedProfile && this.navToken === tokenBeforeRestore + 1) {
                    rollbackInitial();
                }
                return;
            }

            if (savedDir) {
                const tokenBeforeRestore = this.navToken;
                const ok = await this.navigateTo(savedDir, this.volumeValue, false, { silent: true, allowFallback: true });
                if (ok === false && !this.isDisconnected && this.profileValue === expectedProfile && this.navToken === tokenBeforeRestore + 1) {
                    this.clearLastLocation(this.volumeValue);
                }
            }
        } catch (_) { }
    }

    async switchVolume(event) {
                    const vol = event.currentTarget.value;
                    await this.navigateTo('', vol);
                }

                canGoBack() {
                    return this.historyIndex > 0;
                }

                canGoForward() {
                    return this.historyIndex < this.history.length - 1;
                }

                canGoUp() {
                    return !this.trashMode && Boolean(this.pathValue);
                }

                syncNavButtons() {
                    if (this.hasBackBtnTarget) {
                        this.backBtnTarget.disabled = !this.canGoBack();
                    }
                    if (this.hasForwardBtnTarget) {
                        this.forwardBtnTarget.disabled = !this.canGoForward();
                    }
                    if (this.hasUpBtnTarget) {
                        this.upBtnTarget.disabled = !this.canGoUp();
                    }
                }

    async goBack() {
                    if (!this.canGoBack()) {
                        return;
                    }
                    const nextIndex = this.historyIndex - 1;
                    const entry = this.history[nextIndex];
                    await this.navigateTo(entry.path, entry.volume, false, { fromHistory: true, targetHistoryIndex: nextIndex });
                }

    async goForward() {
                    if (!this.canGoForward()) {
                        return;
                    }
                    const nextIndex = this.historyIndex + 1;
                    const entry = this.history[nextIndex];
                    await this.navigateTo(entry.path, entry.volume, false, { fromHistory: true, targetHistoryIndex: nextIndex });
                }

    async goUp() {
                    if (!this.canGoUp()) {
                        return;
                    }
                    const parts = this.pathValue.split('/').filter(Boolean);
                    parts.pop();
                    await this.navigateTo(parts.join('/'));
                }

    async refreshCurrent() {
                    if (this.trashMode) {
                        await this.fetchTrash();
                        return;
                    }
                    this.dirCache.delete(this.cacheKey(this.volumeValue, this.pathValue));
                    const tasks = [
                        this.fetchDirectory(this.volumeValue, this.pathValue, false, true),
                        this.refreshFullTree(true, true),
                    ];
                    if (this.recursiveSearch && this.isRecursiveQueryReady()) {
                        this.recursiveItems = null;
                        tasks.push(this.fetchRecursiveSearch());
                    }
                    await Promise.all(tasks);
                }

    /**
     * @returns {Promise<boolean|null>} true = applied, false = genuine failure, null = superseded or aborted (stale)
     */
    async fetchDirectory(volume, path, silent = false, serverRefresh = false, allowFallback = false) {
                        if (this.isDisconnected) {
                            return null;
                        }
                        this.lastFetchFallbackApplied = false;
                        if (!this.listUrlValue) {
                            return false;
                        }
                        if (this.abortController) {
                            this.abortController.abort();
                        }
                        const controller = new AbortController();
                        this.abortController = controller;
                        const requestToken = ++this.dirRequestToken;
                        this.setLoading(true);

                        const params = new URLSearchParams({
                            profile: this.profileValue,
                            volume: volume || '',
                            path: path || '',
                            locale: this.localeValue || '',
                            all: '1',
                            compact: '1',
                            hybridLimit: '500',
                            perPage: String(Math.max(1, this.perPageValue || 24)),
                            sortBy: this.sortByValue || 'name',
                            sortDir: this.sortDirValue || 'asc',
                        });
                        if (serverRefresh) {
                            params.set('refresh', '1');
                        }
                        if (allowFallback) {
                            params.set('fallback', '1');
                        }

                        try {
                            const res = await fetch(`${this.listUrlValue}?${params.toString()}`, {
                                signal: controller.signal,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            });
                            const data = await res.json().catch(() => ({}));
                            if (this.isDisconnected || this.trashMode || requestToken !== this.dirRequestToken) {
                                // A newer navigation/request superseded this one or controller disconnected; never apply stale results.
                                return null;
                            }
                            if (!res.ok || !data.ok) {
                                if (!silent) {
                                    this.showStatus(data.error || this.t('errors.load_dir'), 'danger');
                                }
                                return false;
                            }

                            const requestedPathBeforeFallback = path || '';
                            this.lastFetchFallbackApplied = Boolean(data.fallbackApplied);
                            this.volumeValue = data.volume;
                            this.volumeLabelValue = data.volumeLabel || data.volume;
                            this.pathValue = data.path || '';
                            const resolvedItems = this.expandCompactItems(data.items || [], data.urlBases || null, this.volumeValue);
                            const activeKey = this.cacheKey(this.volumeValue, this.pathValue);
                            this.setDirCache(activeKey, resolvedItems);
                            if (this.dirMeta) {
                                this.dirMeta.set(activeKey, {
                                    serverPaginated: Boolean(data.serverPaginated),
                                    total: Number(data.total ?? resolvedItems.length),
                                    page: Number(data.page || 1),
                                    perPage: Number(data.perPage || this.perPageValue || 24),
                                    totalPages: Number(data.totalPages || 1),
                                });
                            }

                            if (this.lastFetchFallbackApplied) {
                                this.reconcileFallbackNavigationState(this.volumeValue, requestedPathBeforeFallback, this.pathValue);
                            }

                            this.persistLastLocation(this.volumeValue, this.pathValue);

                            if (data.quota) {
                                this.renderQuota(data.quota);
                            }
                            this.renderBreadcrumbs(data.breadcrumbs || this.buildBreadcrumbs(this.pathValue));
                            this.updatePerfBadge(data.fromCache, data.durationMs);
                            this.applyClientView();
                            if (this.lastFetchFallbackApplied) {
                                this.syncActiveTreeNode(false);
                            }
                            if (this.recursiveSearch && this.isRecursiveQueryReady() && this.recursiveItems === null) {
                                this.fetchRecursiveSearch();
                            }
                            return true;
                        } catch (err) {
                            if (this.isDisconnected || err.name === 'AbortError' || requestToken !== this.dirRequestToken) {
                                return null;
                            }
                            if (!silent) {
                                this.showStatus(this.t('errors.network'), 'danger');
                            }
                            return false;
                        } finally {
                            if (this.abortController === controller) {
                                this.setLoading(false);
                            }
                        }
                    }

                    isServerPaginatedDirectory() {
                        if (this.trashMode) {
                            return false;
                        }
                        if (this.recursiveSearch && this.isRecursiveQueryReady()) {
                            return false;
                        }
                        const meta = this.dirMeta?.get(this.cacheKey(this.volumeValue, this.pathValue));
                        return Boolean(meta?.serverPaginated);
                    }

                    expandCompactItems(items, urlBases, volume) {
                        if (!Array.isArray(items) || items.length === 0) {
                            return [];
                        }
                        const downloadBase = typeof urlBases?.download === 'string' && urlBases.download !== '' ? urlBases.download : null;
                        const thumbBase = typeof urlBases?.thumb === 'string' && urlBases.thumb !== '' ? urlBases.thumb : null;
                        const profile = this.profileValue || 'default';
                        const vol = volume || this.volumeValue || '';
                        const buildQuery = (extra) => {
                            const q = new URLSearchParams({ profile, volume: vol, ...extra });
                            return q.toString();
                        };

                        return items.map((item) => {
                            if (!item || typeof item !== 'object') {
                                return item;
                            }
                            if (item.kind === 'folder') {
                                return {
                                    kind: 'folder',
                                    name: item.name,
                                    path: item.path,
                                    color: item.color ?? null,
                                    icon: item.icon ?? null,
                                    pinned: Boolean(item.pinned),
                                    lastModified: item.lastModified ?? 0,
                                    itemCount: item.itemCount ?? null,
                                };
                            }

                            const isImage = Boolean(item.isImage);
                            const downloadUrl = item.downloadUrl
                                || (downloadBase ? `${downloadBase}?${buildQuery({ path: item.path })}` : '');
                            const inlineUrl = item.url
                                || (downloadBase ? `${downloadBase}?${buildQuery({ path: item.path, inline: '1' })}` : downloadUrl);
                            const thumbUrl = item.thumbUrl !== undefined
                                ? item.thumbUrl
                                : (isImage && thumbBase ? `${thumbBase}?${buildQuery({ path: item.path, v: String(item.lastModified ?? 0) })}` : null);

                            return {
                                kind: 'file',
                                name: item.name,
                                path: item.path,
                                size: Number(item.size || 0),
                                sizeFormatted: item.sizeFormatted || this.formatBytes(Number(item.size || 0)),
                                extension: item.extension || '',
                                category: item.category || 'other',
                                lastModified: item.lastModified ?? 0,
                                createdAt: item.createdAt ?? item.lastModified ?? null,
                                isImage,
                                url: inlineUrl,
                                downloadUrl,
                                thumbUrl,
                                meta: item.meta ?? null,
                            };
                        });
                    }

                    async fetchServerPage(targetPage = 1) {
                        if (this.isDisconnected || !this.listUrlValue) {
                            return null;
                        }
                        if (this.abortController) {
                            this.abortController.abort();
                        }
                        const controller = new AbortController();
                        this.abortController = controller;
                        const requestToken = ++this.dirRequestToken;
                        this.setLoading(true);

                        const requestedVolume = this.volumeValue || '';
                        const requestedPath = this.pathValue || '';
                        const params = new URLSearchParams({
                            profile: this.profileValue,
                            volume: requestedVolume,
                            path: requestedPath,
                            locale: this.localeValue || '',
                            page: String(Math.max(1, targetPage)),
                            perPage: String(Math.max(1, this.perPageValue || 24)),
                            sortBy: this.sortByValue || 'name',
                            sortDir: this.sortDirValue || 'asc',
                            type: this.typeFilterValue || 'all',
                            search: this.rawSearchQuery || this.searchQuery || '',
                            compact: '1',
                        });

                        try {
                            const res = await fetch(`${this.listUrlValue}?${params.toString()}`, {
                                signal: controller.signal,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            });
                            const data = await res.json().catch(() => ({}));
                            if (this.isDisconnected || this.trashMode || requestToken !== this.dirRequestToken) {
                                return null;
                            }
                            if (!res.ok || !data.ok) {
                                this.showStatus(data.error || this.t('errors.load_dir'), 'danger');
                                return false;
                            }
                            const resolvedItems = this.expandCompactItems(data.items || [], data.urlBases || null, data.volume || requestedVolume);
                            const activeKey = this.cacheKey(data.volume || requestedVolume, data.path ?? requestedPath);
                            this.setDirCache(activeKey, resolvedItems);
                            this.pageValue = Number(data.page || targetPage || 1);
                            if (this.dirMeta) {
                                this.dirMeta.set(activeKey, {
                                    serverPaginated: true,
                                    total: Number(data.total ?? resolvedItems.length),
                                    page: this.pageValue,
                                    perPage: Number(data.perPage || this.perPageValue || 24),
                                    totalPages: Number(data.totalPages || 1),
                                });
                            }
                            this.updatePerfBadge(data.fromCache, data.durationMs);
                            this.applyClientView();
                            return true;
                        } catch (err) {
                            if (this.isDisconnected || err.name === 'AbortError' || requestToken !== this.dirRequestToken) {
                                return null;
                            }
                            this.showStatus(this.t('errors.network'), 'danger');
                            return false;
                        } finally {
                            if (this.abortController === controller) {
                                this.setLoading(false);
                            }
                        }
                    }

                    getActiveItemList() {
                        if (this.recursiveSearch && this.isRecursiveQueryReady() && Array.isArray(this.recursiveItems)) {
                            return this.recursiveItems;
                        }
                        return this.dirCache.get(this.cacheKey(this.volumeValue, this.pathValue)) || [];
                    }

                    getItemSearchCache(item) {
                        if (!item || typeof item !== 'object') {
                            return null;
                        }
                        if (!this._itemSearchCache) {
                            this._itemSearchCache = new WeakMap();
                        }
                        let cached = this._itemSearchCache.get(item);
                        if (!cached) {
                            const meta = item.meta;
                            cached = {
                                name: this.foldSearch(item.name),
                                tags: Array.isArray(meta?.tags) ? meta.tags.map((tag) => this.foldSearch(tag)) : [],
                                fields: [meta?.alt, meta?.title, meta?.caption]
                                    .filter((field) => typeof field === 'string' && field !== '')
                                    .map((field) => this.foldSearch(field)),
                            };
                            this._itemSearchCache.set(item, cached);
                        }
                        return cached;
                    }

                    itemMatchesSearch(item, query, isFolded = false) {
                        const foldedQuery = isFolded ? String(query || '') : this.foldSearch(query);
                        const tagOnly = foldedQuery.startsWith('#');
                        const needle = tagOnly ? foldedQuery.slice(1).trim() : foldedQuery;
                        if (!needle) {
                            return true;
                        }
                        const cached = this.getItemSearchCache(item);
                        if (!cached) {
                            return false;
                        }
                        if (!tagOnly && cached.name.includes(needle)) {
                            return true;
                        }
                        if (cached.tags.some((tag) => tag.includes(needle))) {
                            return true;
                        }
                        if (tagOnly) {
                            return false;
                        }
                        return cached.fields.some((field) => field.includes(needle));
                    }

                    reconcileFallbackNavigationState(volume, deadPath, fallbackPath) {
                        const deadPrefix = deadPath ? `${volume}::${deadPath}` : '';
                        if (deadPrefix && this.expandedFolders instanceof Set) {
                            for (const key of Array.from(this.expandedFolders)) {
                                if (key === deadPrefix || key.startsWith(`${deadPrefix}/`)) {
                                    this.expandedFolders.delete(key);
                                }
                            }
                        }
                        if (Array.isArray(this.history) && this.history.length > 0) {
                            const nextHistory = [];
                            let nextIndex = this.historyIndex;
                            for (let i = 0; i < this.history.length; i += 1) {
                                let entry = this.history[i];
                                if (i === this.historyIndex) {
                                    entry = { volume, path: fallbackPath };
                                } else if (
                                    deadPath
                                    && entry.volume === volume
                                    && (entry.path === deadPath || entry.path.startsWith(`${deadPath}/`))
                                ) {
                                    if (i < this.historyIndex) {
                                        nextIndex -= 1;
                                    }
                                    continue;
                                }
                                const prev = nextHistory[nextHistory.length - 1];
                                if (prev && prev.volume === entry.volume && prev.path === entry.path) {
                                    if (i <= this.historyIndex) {
                                        nextIndex -= 1;
                                    }
                                    continue;
                                }
                                nextHistory.push(entry);
                            }
                            this.history = nextHistory.length > 0 ? nextHistory : [{ volume, path: fallbackPath }];
                            this.historyIndex = Math.min(Math.max(0, nextIndex), this.history.length - 1);
                        }
                        this.syncNavButtons();
                    }

                    // =========================================================================
                    // 0ms Client-Side Filter, Search, Sort, Paginate & Render
                    // =========================================================================
                    applyClientView() {
                        this.cancelScheduledRender('_searchRenderRaf');
                        this.dragging = null;
                        if (!this.hasContentAreaTarget || this.trashMode) {
                            return;
                        }
                        this.syncNavButtons();

                        const isRecursiveActive = this.recursiveSearch && this.isRecursiveQueryReady() && Array.isArray(this.recursiveItems);
                        const rawItems = this.getActiveItemList();
                        const serverMeta = !isRecursiveActive ? this.dirMeta?.get(this.cacheKey(this.volumeValue, this.pathValue)) : null;
                        const isServerPaginated = Boolean(serverMeta?.serverPaginated);
                        const query = this.searchQuery;
                        const typeFilter = this.typeFilterValue;

                        let filtered = isServerPaginated
                            ? [...rawItems]
                            : rawItems.filter((item) => {
                                if (!isRecursiveActive && query && !this.itemMatchesSearch(item, query, true)) {
                                    return false;
                                }
                                if (typeFilter === 'all') {
                                    return true;
                                }
                                if (typeFilter === 'folder') {
                                    return item.kind === 'folder';
                                }
                                return item.kind === 'file' && item.category === typeFilter;
                            });

                        if (this.selectedMap && this.selectedMap.size > 0) {
                            const visiblePaths = new Set(filtered.map((i) => i.path));
                            for (const selPath of Array.from(this.selectedMap.keys())) {
                                if (!visiblePaths.has(selPath)) {
                                    this.selectedMap.delete(selPath);
                                }
                            }
                            if (this.lastSelectedPath && !visiblePaths.has(this.lastSelectedPath)) {
                                this.lastSelectedPath = null;
                            }
                        }

                        if (!isServerPaginated) {
                            const dir = this.sortDirValue === 'desc' ? -1 : 1;
                            const sortBy = this.sortByValue;
                            const collator = this.getCollator();
                            filtered = [...filtered].sort((a, b) => {
                                const aFolder = a.kind === 'folder';
                                const bFolder = b.kind === 'folder';
                                if (this.capabilitiesValue?.foldersFirst !== false && aFolder !== bFolder) {
                                    return aFolder ? -1 : 1;
                                }
                                let cmp = 0;
                                if (sortBy === 'size') {
                                    cmp = (a.size || 0) - (b.size || 0);
                                } else if (sortBy === 'date') {
                                    cmp = (a.lastModified || 0) - (b.lastModified || 0);
                                } else if (sortBy === 'type') {
                                    cmp = collator.compare(String(a.extension || ''), String(b.extension || ''));
                                    if (cmp === 0) {
                                        cmp = collator.compare(String(a.name || ''), String(b.name || ''));
                                    }
                                } else {
                                    cmp = collator.compare(String(a.name || ''), String(b.name || ''));
                                }
                                return cmp * dir;
                            });
                        }

                        const perPage = Math.max(1, this.perPageValue);
                        const total = isServerPaginated ? Math.max(filtered.length, Number(serverMeta.total || 0)) : filtered.length;
                        this.totalPages = isServerPaginated
                            ? Math.max(1, Number(serverMeta.totalPages || Math.ceil(total / perPage)))
                            : Math.max(1, Math.ceil(total / perPage));
                        this.pageValue = Math.min(Math.max(1, this.pageValue), this.totalPages);
                        const pageItems = isServerPaginated
                            ? filtered
                            : filtered.slice((this.pageValue - 1) * perPage, (this.pageValue - 1) * perPage + perPage);

                        this.currentDisplayedItems = filtered;
                        this.renderedPaths = pageItems.map((item) => item.path);
                        this.updatePagination(total, this.pageValue, this.totalPages);

                        const prevActiveEl = typeof document !== 'undefined' ? document.activeElement : null;
                        const hadFocusInsideElement = Boolean(
                            prevActiveEl
                            && prevActiveEl !== document.body
                            && this.element?.contains?.(prevActiveEl),
                        );

                        if (pageItems.length === 0) {
                            this.contentAreaTarget.innerHTML = this.renderEmptyState();
                            this.syncSelectionUi();
                            if (hadFocusInsideElement) {
                                const nowActive = typeof document !== 'undefined' ? document.activeElement : null;
                                if (!nowActive || nowActive === document.body || !this.element?.contains?.(nowActive)) {
                                    this.restoreRootFocus();
                                }
                            }
                            return;
                        }

                        const bannerHtml = isRecursiveActive
                            ? `<div class="liveux-fm-muted liveux-fm-text-xs" style="margin-bottom: 10px;">🔎 ${this.escapeHtml(this.t('search.recursive_results', { count: total }))}</div>`
                            : '';

                        if (this.viewModeValue === 'list') {
                            this.contentAreaTarget.innerHTML = `${bannerHtml}<div class="liveux-fm-list">${this.renderListHeader()}${pageItems.map((item) => this.renderListItem(item, isRecursiveActive)).join('')}</div>`;
                        } else if (this.activeFolderStyle === 'card') {
                            this.contentAreaTarget.innerHTML = `${bannerHtml}<div class="liveux-fm-grid">${pageItems.map((item) => this.renderGridItem(item, isRecursiveActive)).join('')}</div>`;
                        } else {
                            const folders = pageItems.filter((item) => item.kind === 'folder');
                            const files = pageItems.filter((item) => item.kind !== 'folder');
                            const foldersHtml = folders.length > 0
                                ? `<div class="liveux-fm-folder-grid">${folders.map((item) => this.renderGridItem(item, isRecursiveActive)).join('')}</div>`
                                : '';
                            const filesHtml = files.length > 0
                                ? `<div class="liveux-fm-grid">${files.map((item) => this.renderGridItem(item, isRecursiveActive)).join('')}</div>`
                                : '';
                            this.contentAreaTarget.innerHTML = `${bannerHtml}${foldersHtml}${filesHtml}`;
                        }
                        this.syncSelectionUi();
                        if (hadFocusInsideElement) {
                            const nowActive = typeof document !== 'undefined' ? document.activeElement : null;
                            if (!nowActive || nowActive === document.body || !this.element?.contains?.(nowActive)) {
                                this.restoreRootFocus();
                            }
                        }
                    }

                    renderListHeader() {
                        const allowSort = this.capabilitiesValue?.showSort !== false;
                        const arrow = (col) => (allowSort && this.sortByValue === col ? (this.sortDirValue === 'asc' ? ' ↑' : ' ↓') : '');
                        const active = (col) => (allowSort && this.sortByValue === col ? ' is-active' : '');
                        const colEl = (col, cls, key) => allowSort
                            ? `<button type="button" class="liveux-fm-list-col-btn ${cls}${active(col)}" data-sort-col="${col}" data-action="click->${CTRL}#sortByColumn">${this.escapeHtml(this.t(key))}${arrow(col)}</button>`
                            : `<span class="${cls}">${this.escapeHtml(this.t(key))}</span>`;
                        return `<div class="liveux-fm-list-header">
                            <span class="liveux-fm-list-col-check"></span>
                            <span class="liveux-fm-list-col-thumb"></span>
                            ${colEl('name', 'liveux-fm-list-col-name', 'sort.field_name')}
                            ${colEl('type', 'liveux-fm-list-col-type', 'sort.field_type')}
                            ${colEl('size', 'liveux-fm-list-col-size', 'sort.field_size')}
                            ${colEl('date', 'liveux-fm-list-col-date', 'sort.field_date')}
                            <span class="liveux-fm-list-col-act"></span>
                        </div>`;
                    }

                    renderEmptyState() {
                        const hasFilter = Boolean(this.searchQuery) || (this.typeFilterValue && this.typeFilterValue !== 'all');
                        if (hasFilter) {
                            return `<div class="liveux-fm-empty-state">
                <div class="liveux-fm-empty-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                </div>
                <div class="liveux-fm-empty-title">${this.escapeHtml(this.t('empty.filtered'))}</div>
                <div class="liveux-fm-empty-actions">
                    <button type="button" class="liveux-fm-btn" data-action="${CTRL}#clearFilters">${this.escapeHtml(this.t('actions.clear_filters'))}</button>
                </div>
            </div>`;
                        }

                        const caps = this.capabilitiesValue || {};
                        const actionButtons = [];
                        if (caps.upload) {
                            actionButtons.push(`<button type="button" class="liveux-fm-btn liveux-fm-btn-primary" data-action="${CTRL}#openUploadModal">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                <span>${this.escapeHtml(this.t('actions.upload'))}</span>
            </button>`);
                        }
                        if (caps.createFolder) {
                            actionButtons.push(`<button type="button" class="liveux-fm-btn" data-action="${CTRL}#openNewFolderModal">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                <span>${this.escapeHtml(this.t('actions.new_folder'))}</span>
            </button>`);
                        }

                        return `<div class="liveux-fm-empty-state">
            <div class="liveux-fm-empty-icon">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
            </div>
            <div class="liveux-fm-empty-title">${this.escapeHtml(this.t('empty.folder'))}</div>
            ${caps.upload && caps.allowDropzoneUpload !== false ? `<div class="liveux-fm-empty-hint">${this.escapeHtml(this.t('empty.drop_hint'))}</div>` : ''}
            ${actionButtons.length > 0 ? `<div class="liveux-fm-empty-actions">${actionButtons.join('')}</div>` : ''}
        </div>`;
                    }

                    renderFolderSvg(size = 48, color = null, inverted = false, icon = null) {
                        const safeSize = Math.max(8, Math.min(256, parseInt(size, 10) || 48));
                        const defaultColor = this.sanitizeHexColor(this.iconSetValue?.defaultFolderColor) || '#2563eb';
                        const effectiveColor = inverted ? 'currentColor' : (this.sanitizeHexColor(color) || defaultColor);
                        const rawSvg = this.iconSetValue?.icons?.folder || '';
                        const cleanSvg = rawSvg ? this.sanitizeInlineSvg(rawSvg, safeSize) : '';

                        const baseMarkup = cleanSvg
                            || `<svg width="${safeSize}" height="${safeSize}" viewBox="0 0 24 24" fill="${this.escapeAttr(effectiveColor)}" class="liveux-fm-shrink-0"><path d="M19.5 21a3 3 0 0 0 3-3v-4.5a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3V18a3 3 0 0 0 3 3h15ZM1.5 10.146V6a3 3 0 0 1 3-3h5.379a2.25 2.25 0 0 1 1.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 0 1 3 3v1.146A4.483 4.483 0 0 0 19.5 9h-15a4.483 4.483 0 0 0-3 1.146Z"/></svg>`;

                        const badgeKey = typeof icon === 'string' ? icon.trim() : '';
                        const badges = this.iconSetValue?.badges;
                        const rawBadge = badgeKey !== '' && badges && Object.prototype.hasOwnProperty.call(badges, badgeKey)
                            ? badges[badgeKey]
                            : '';
                        const badgePx = Math.max(8, Math.round(safeSize * 0.44));
                        const cleanBadge = rawBadge ? this.sanitizeInlineSvg(rawBadge, badgePx) : '';
                        const badgeHtml = cleanBadge
                            ? `<span class="liveux-fm-folder-badge ${inverted ? 'is-inverted' : ''}" style="width:${badgePx + 4}px;height:${badgePx + 4}px;">${cleanBadge}</span>`
                            : '';

                        return `<span class="liveux-fm-folder-icon-wrap liveux-fm-shrink-0" style="width:${safeSize}px;height:${safeSize}px;color:${this.escapeAttr(effectiveColor)};">${baseMarkup}${badgeHtml}</span>`;
                    }

                    renderFileTypeSvg(ext, size = 46) {
                        const safeSize = Math.max(8, Math.min(256, parseInt(size, 10) || 46));
                        const e = String(ext || 'file').toLowerCase();
                        const typeMap = {
                            pdf: 'pdf',
                            xls: 'excel', xlsx: 'excel', csv: 'excel', ods: 'excel',
                            doc: 'word', docx: 'word', rtf: 'word', odt: 'word',
                            zip: 'zip', rar: 'zip', '7z': 'zip', tar: 'zip', gz: 'zip',
                            mp4: 'video', webm: 'video', ogv: 'video', mov: 'video', avi: 'video', mkv: 'video', m4v: 'video',
                            mp3: 'audio', wav: 'audio', ogg: 'audio', m4a: 'audio', flac: 'audio', aac: 'audio',
                            js: 'code', ts: 'code', php: 'code', html: 'code', css: 'code', json: 'code', xml: 'code', yaml: 'code', yml: 'code', sql: 'code', md: 'code',
                        };
                        const iconKey = Object.prototype.hasOwnProperty.call(typeMap, e) ? typeMap[e] : 'file';
                        const icons = this.iconSetValue?.icons;
                        const rawSvg = (icons && Object.prototype.hasOwnProperty.call(icons, iconKey) ? icons[iconKey] : null)
                            || (icons && Object.prototype.hasOwnProperty.call(icons, 'file') ? icons.file : '')
                            || '';
                        const cleanSvg = rawSvg ? this.sanitizeInlineSvg(rawSvg, safeSize) : '';

                        if (cleanSvg) {
                            return `<span class="liveux-fm-shrink-0" style="display:inline-flex;align-items:center;justify-content:center;width:${safeSize}px;height:${safeSize}px;">${cleanSvg}</span>`;
                        }

                        let badgeExt = Array.from(e).slice(0, 5).join('');
                        if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
                            try {
                                badgeExt = Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(e), (s) => s.segment).slice(0, 5).join('');
                            } catch (_) { }
                        }
                        return `<span class="liveux-fm-badge is-neutral liveux-fm-mono" style="text-transform:uppercase;">${this.escapeHtml(badgeExt)}</span>`;
                    }

                    supportsAlpha(ext) {
                        return ['png', 'webp', 'gif', 'svg', 'avif', 'ico'].includes(String(ext || '').toLowerCase());
                    }

                    isEditableRasterExtension(ext) {
                        return ['jpg', 'jpeg', 'png', 'webp'].includes(String(ext || '').toLowerCase());
                    }

                    resolveInlineImageUrl(item) {
                        if (!item) {
                            return '';
                        }
                        const isSvg = String(item.extension || '').toLowerCase() === 'svg';
                        if (isSvg) {
                            if (this.isSafeUrl(item.thumbUrl)) {
                                return item.thumbUrl;
                            }
                            return this.isSafeUrl(item.url) ? item.url : '';
                        }
                        if (this.isSafeUrl(item.url)) {
                            return item.url;
                        }
                        return this.isSafeUrl(item.thumbUrl) ? item.thumbUrl : '';
                    }

                    renderGridItem(item, showParentPath = false) {
                        const safePath = this.escapeAttr(item.path);
                        const safeName = this.escapeHtml(item.name);
                        const safeNameAttr = this.escapeAttr(item.name);
                        const allowDrag = this.capabilitiesValue?.allowDragMove !== false;
                        const allowCtx = this.capabilitiesValue?.allowContextMenu !== false;
                        const moreLabelAttr = this.escapeAttr(this.t('actions.more'));
                        const moreBtn = allowCtx
                            ? `<button type="button" class="liveux-fm-card-more" title="${moreLabelAttr}" aria-label="${moreLabelAttr}" data-action="click->${CTRL}#openContextFromButton"><svg width="14" height="14" fill="currentColor" viewBox="0 0 20 20"><path d="M6 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm6 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm6 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg></button>`
                            : '';

                        const parentDir = showParentPath && item.path.includes('/')
                            ? `/${item.path.slice(0, item.path.lastIndexOf('/'))}`
                            : '';
                        const parentBadge = parentDir
                            ? `<div class="liveux-fm-recursive-badge liveux-fm-truncate" dir="ltr" title="${this.escapeAttr(parentDir)}">${this.escapeHtml(parentDir)}</div>`
                            : '';

                        if (item.kind === 'folder') {
                            const safeColor = this.sanitizeHexColor(item.color);
                            const accentStripe = safeColor ? `border-inline-start: 3px solid ${safeColor};` : '';
                            const pinStar = item.pinned
                                ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="color:var(--liveux-fm-warning);flex-shrink:0;"><path d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>'
                                : '';
                            if (this.activeFolderStyle === 'card') {
                                return `<div class="liveux-fm-card liveux-fm-folder-card" draggable="${allowDrag ? 'true' : 'false'}"
                 data-ctx-kind="folder" data-fm-folder-card="1"
                 data-folder-path="${safePath}" data-path="${safePath}" data-name="${safeNameAttr}"
                 data-color="${this.escapeAttr(safeColor)}" data-icon="${this.escapeAttr(item.icon || '')}" data-pinned="${item.pinned ? '1' : '0'}" data-volume="${this.escapeAttr(this.volumeValue)}"
                 data-action="click->${CTRL}#onFolderClick dblclick->${CTRL}#onFolderDblClick dragstart->${CTRL}#onItemDragStart dragend->${CTRL}#onItemDragEnd dragover->${CTRL}#onFolderDragOver dragleave->${CTRL}#onFolderDragLeave drop->${CTRL}#onFolderDrop">
                ${moreBtn}
                <div class="liveux-fm-stage">
                    <div class="liveux-fm-folder-card-stage-inner">
                        ${this.renderFolderSvg(56, safeColor, false, item.icon || null)}
                    </div>
                </div>
                <div class="liveux-fm-name" title="${safeNameAttr}">${pinStar ? `${pinStar} ` : ''}${safeName}</div>
                ${parentBadge}
                <div class="liveux-fm-meta-row"><span>${this.renderFolderCountHtml(item.itemCount, 'list')}</span><span>${this.escapeHtml(this.t('empty.folder_label'))}</span></div>
            </div>`;
                            }
                            return `<div class="liveux-fm-folder-tile" style="${accentStripe}" draggable="${allowDrag ? 'true' : 'false'}"
                 data-ctx-kind="folder" data-fm-folder-card="1"
                 data-folder-path="${safePath}" data-path="${safePath}" data-name="${safeNameAttr}"
                 data-color="${this.escapeAttr(safeColor)}" data-icon="${this.escapeAttr(item.icon || '')}" data-pinned="${item.pinned ? '1' : '0'}" data-volume="${this.escapeAttr(this.volumeValue)}"
                 data-action="click->${CTRL}#onFolderClick dblclick->${CTRL}#onFolderDblClick dragstart->${CTRL}#onItemDragStart dragend->${CTRL}#onItemDragEnd dragover->${CTRL}#onFolderDragOver dragleave->${CTRL}#onFolderDragLeave drop->${CTRL}#onFolderDrop">
                ${this.renderFolderSvg(22, safeColor, false, item.icon || null)}
                <div class="liveux-fm-folder-tile-body">
                    <div class="liveux-fm-row-sm liveux-fm-minw-0">
                        <span class="liveux-fm-folder-tile-name" title="${safeNameAttr}">${safeName}</span>
                        ${pinStar}
                    </div>
                    ${parentBadge}
                </div>
                ${this.renderFolderCountHtml(item.itemCount, 'tile')}
                ${moreBtn}
            </div>`;
                        }

                        const safeUrl = this.escapeAttr(this.isSafeUrl(item.url) ? item.url : '');
                        const safeDlUrl = this.escapeAttr(this.isSafeUrl(item.downloadUrl) ? item.downloadUrl : '');
                        const ext = String(item.extension || this.t('ui.file_label')).toUpperCase();
                        const loadingAttr = this.lazyLoadValue === false ? 'eager' : 'lazy';
                        const showThumb = item.isImage && this.thumbnailsEnabledValue && this.isSafeUrl(item.thumbUrl);
                        const stageContent = showThumb
                            ? `<img src="${this.escapeAttr(item.thumbUrl)}" alt="${safeNameAttr}" loading="${loadingAttr}" decoding="async" draggable="false">`
                            : this.renderFileTypeSvg(item.extension);

                        const useChecker = showThumb && this.supportsAlpha(item.extension);
                        const sizeLabel = typeof item.size === 'number' ? this.formatBytes(item.size) : (item.sizeFormatted || '');

                        return `<div class="liveux-fm-card" draggable="${allowDrag ? 'true' : 'false'}"
             data-ctx-kind="file" data-fm-file-card="1"
             data-path="${safePath}" data-name="${safeNameAttr}" data-url="${safeUrl}"
             data-download-url="${safeDlUrl}" data-extension="${this.escapeAttr(item.extension || '')}" data-is-image="${item.isImage ? '1' : '0'}" data-volume="${this.escapeAttr(this.volumeValue)}"
             data-action="click->${CTRL}#selectFile dblclick->${CTRL}#doubleClickFile dragstart->${CTRL}#onItemDragStart dragend->${CTRL}#onItemDragEnd">
            <input type="checkbox" class="liveux-fm-checkbox liveux-fm-card-check"
                   aria-label="${safeNameAttr}"
                   data-path="${safePath}" data-name="${safeNameAttr}" data-url="${safeUrl}"
                   data-action="click->${CTRL}#toggleCheckbox">
            ${moreBtn}
            <div class="liveux-fm-stage ${useChecker ? 'is-checker' : ''}" data-fit="${this.escapeAttr(this.thumbFitValue)}">${stageContent}</div>
            <div class="liveux-fm-name" title="${safeNameAttr}">${safeName}</div>
            ${parentBadge}
            <div class="liveux-fm-meta-row"><span>${this.escapeHtml(sizeLabel)}</span><span class="liveux-fm-mono" style="text-transform:uppercase;">${this.escapeHtml(ext)}</span></div>
        </div>`;
                    }

                    renderListItem(item, showParentPath = false) {
                        const safePath = this.escapeAttr(item.path);
                        const safeName = this.escapeHtml(item.name);
                        const safeNameAttr = this.escapeAttr(item.name);
                        const allowDrag = this.capabilitiesValue?.allowDragMove !== false;
                        const allowCtx = this.capabilitiesValue?.allowContextMenu !== false;
                        const moreLabelAttr = this.escapeAttr(this.t('actions.more'));
                        const moreRowBtn = allowCtx
                            ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-ghost" title="${moreLabelAttr}" aria-label="${moreLabelAttr}" data-action="click->${CTRL}#openContextFromButton">⋯</button>`
                            : '';

                        const parentDir = showParentPath && item.path.includes('/')
                            ? `/${item.path.slice(0, item.path.lastIndexOf('/'))}`
                            : '';
                        const parentPill = parentDir
                            ? `<span class="liveux-fm-recursive-badge liveux-fm-shrink-0" dir="ltr" title="${this.escapeAttr(parentDir)}">${this.escapeHtml(parentDir)}</span>`
                            : '';

                        const dateLabel = item.lastModified ? this.formatDate(item.lastModified) : '—';

                        if (item.kind === 'folder') {
                            return `<div class="liveux-fm-list-row is-clickable" draggable="${allowDrag ? 'true' : 'false'}"
                 data-ctx-kind="folder" data-fm-folder-card="1"
                 data-folder-path="${safePath}" data-path="${safePath}" data-name="${safeNameAttr}"
                 data-color="${this.escapeAttr(item.color || '')}" data-icon="${this.escapeAttr(item.icon || '')}" data-pinned="${item.pinned ? '1' : '0'}" data-volume="${this.escapeAttr(this.volumeValue)}"
                 data-action="click->${CTRL}#onFolderClick dblclick->${CTRL}#onFolderDblClick dragstart->${CTRL}#onItemDragStart dragend->${CTRL}#onItemDragEnd dragover->${CTRL}#onFolderDragOver dragleave->${CTRL}#onFolderDragLeave drop->${CTRL}#onFolderDrop">
                <span class="liveux-fm-list-col-check"></span>
                <span class="liveux-fm-list-thumb-icon">${this.renderFolderSvg(22, item.color, false, item.icon || null)}</span>
                <span class="liveux-fm-strong liveux-fm-truncate liveux-fm-list-col-name">${item.pinned ? '⭐ ' : ''}${safeName}/ ${parentPill}</span>
                <span class="liveux-fm-muted liveux-fm-list-col-type" style="font-size:0.7rem;">${this.escapeHtml(this.t('empty.folder_label'))}</span>
                <span class="liveux-fm-muted liveux-fm-mono liveux-fm-list-col-size" dir="ltr" style="font-size:0.72rem;">${this.renderFolderCountHtml(item.itemCount, 'text') || '—'}</span>
                <span class="liveux-fm-muted liveux-fm-mono liveux-fm-list-col-date" style="font-size:0.7rem;">${this.escapeHtml(dateLabel)}</span>
                <span class="liveux-fm-list-col-act">${moreRowBtn}</span>
            </div>`;
                        }

                        const safeUrl = this.escapeAttr(this.isSafeUrl(item.url) ? item.url : '');
                        const safeDlUrl = this.escapeAttr(this.isSafeUrl(item.downloadUrl) ? item.downloadUrl : '');
                        const extLabel = String(item.extension || this.t('ui.file_label')).toUpperCase();
                        const loadingAttr = this.lazyLoadValue === false ? 'eager' : 'lazy';
                        const thumb = item.isImage && this.thumbnailsEnabledValue && this.isSafeUrl(item.thumbUrl)
                            ? `<img src="${this.escapeAttr(item.thumbUrl)}" alt="${safeNameAttr}" loading="${loadingAttr}" decoding="async" draggable="false" class="liveux-fm-list-thumb">`
                            : `<span class="liveux-fm-list-thumb-icon">${this.renderFileTypeSvg(item.extension, 22)}</span>`;
                        const sizeLabel = typeof item.size === 'number' ? this.formatBytes(item.size) : (item.sizeFormatted || '');

                        return `<div class="liveux-fm-list-row is-clickable" draggable="${allowDrag ? 'true' : 'false'}"
             data-ctx-kind="file" data-fm-file-card="1"
             data-path="${safePath}" data-name="${safeNameAttr}" data-url="${safeUrl}"
             data-download-url="${safeDlUrl}" data-extension="${this.escapeAttr(item.extension || '')}" data-is-image="${item.isImage ? '1' : '0'}" data-volume="${this.escapeAttr(this.volumeValue)}"
             data-action="click->${CTRL}#selectFile dblclick->${CTRL}#doubleClickFile dragstart->${CTRL}#onItemDragStart dragend->${CTRL}#onItemDragEnd">
            <input type="checkbox" class="liveux-fm-checkbox liveux-fm-list-col-check" aria-label="${safeNameAttr}" data-path="${safePath}" data-name="${safeNameAttr}" data-url="${safeUrl}" data-action="click->${CTRL}#toggleCheckbox">
            ${thumb}
            <span class="liveux-fm-truncate liveux-fm-list-col-name">${safeName} ${parentPill}</span>
            <span class="liveux-fm-muted liveux-fm-mono liveux-fm-list-col-type" style="font-size:0.68rem;">${this.escapeHtml(extLabel)}</span>
            <span class="liveux-fm-muted liveux-fm-mono liveux-fm-list-col-size" style="font-size:0.72rem;">${this.escapeHtml(sizeLabel)}</span>
            <span class="liveux-fm-muted liveux-fm-mono liveux-fm-list-col-date" style="font-size:0.7rem;">${this.escapeHtml(dateLabel)}</span>
            <span class="liveux-fm-list-col-act">${moreRowBtn}</span>
        </div>`;
                    }

                    buildBreadcrumbs(path) {
                        if (!path) {
                            return [];
                        }
                        const crumbs = [];
                        let acc = '';
                        path.split('/').filter(Boolean).forEach((seg) => {
                            acc = acc ? `${acc}/${seg}` : seg;
                            crumbs.push({ label: seg, path: acc });
                        });
                        return crumbs;
                    }

                    renderBreadcrumbs(crumbs) {
                        if (!this.hasBreadcrumbsTarget) {
                            return;
                        }
                        const rootLabel = this.escapeHtml(this.volumeLabelValue || this.volumeValue);
                        const safeVol = this.escapeAttr(this.volumeValue || '');
                        const fullPathTitle = this.escapeAttr(crumbs.map((c) => c.label).join(' / '));
                        const crumbDropActions = `click->${CTRL}#navigate:prevent dragover->${CTRL}#onFolderDragOver dragleave->${CTRL}#onFolderDragLeave drop->${CTRL}#onFolderDrop`;
                        const parts = [
                            `<a href="#" class="liveux-fm-crumb-link" title="${rootLabel}" data-action="${crumbDropActions}" data-path="" data-folder-path="" data-volume="${safeVol}">${rootLabel}</a>`,
                        ];

                        // Smart middle-ellipsis when deep (> 2 levels): Local Uploads › … › parent › current
                        let visibleCrumbs = crumbs;
                        if (crumbs.length > 2) {
                            const collapsedParent = crumbs[crumbs.length - 3];
                            const safeCollapsed = this.escapeAttr(collapsedParent.path);
                            parts.push('<span class="liveux-fm-crumb-sep">›</span>');
                            parts.push(`<a href="#" class="liveux-fm-crumb-link" title="${fullPathTitle}" data-action="${crumbDropActions}" data-path="${safeCollapsed}" data-folder-path="${safeCollapsed}" data-volume="${safeVol}">…</a>`);
                            visibleCrumbs = crumbs.slice(-2);
                        }

                        visibleCrumbs.forEach((crumb, idx) => {
                            const isLast = idx === visibleCrumbs.length - 1;
                            const safeLabel = this.escapeHtml(crumb.label);
                            const safeAttr = this.escapeAttr(crumb.label);
                            const safePath = this.escapeAttr(crumb.path);
                            parts.push('<span class="liveux-fm-crumb-sep">›</span>');
                            if (isLast) {
                                parts.push(`<span class="liveux-fm-crumb-current" title="${fullPathTitle || safeAttr}">${safeLabel}</span>`);
                            } else {
                                parts.push(`<a href="#" class="liveux-fm-crumb-link" title="${safeAttr}" data-action="${crumbDropActions}" data-path="${safePath}" data-folder-path="${safePath}" data-volume="${safeVol}">${safeLabel}</a>`);
                            }
                        });

                        this.breadcrumbsTarget.innerHTML = parts.join('');
                    }

                    updatePagination(total, page, totalPages) {
                        if (this.hasTotalCountTarget) {
                            if (total === 0) {
                                this.totalCountTarget.textContent = this.formatNumber(0);
                            } else if (totalPages <= 1) {
                                this.totalCountTarget.textContent = this.formatNumber(total);
                            } else {
                                const start = (page - 1) * this.perPageValue + 1;
                                const end = Math.min(total, page * this.perPageValue);
                                this.totalCountTarget.textContent = `${this.formatNumber(start)}–${this.formatNumber(end)} / ${this.formatNumber(total)}`;
                            }
                        }

                        const opts = Array.isArray(this.perPageOptionsValue)
                            ? this.perPageOptionsValue.map((n) => parseInt(n, 10)).filter((n) => !Number.isNaN(n) && n > 0)
                            : [24];
                        const minOpt = opts.length > 0 ? Math.min(...opts) : 24;
                        const showPerPage = total > minOpt;

                        if (this.hasPerPageSelectTarget) {
                            this.perPageSelectTarget.classList.toggle('liveux-fm-hidden', !showPerPage);
                        }

                        if (this.hasPaginationBarTarget) {
                            const showFooter = this.capabilitiesValue?.showFooter !== false;
                            const isFooterPager = Boolean(this.paginationBarTarget.classList?.contains?.('liveux-fm-footer-pager'));
                            const keepVisible = (!isFooterPager && showFooter) || totalPages > 1 || (showPerPage && this.capabilitiesValue?.showPerPageSelector !== false);
                            this.paginationBarTarget.classList.toggle('liveux-fm-hidden', !keepVisible);
                        }

                        if (!this.hasPagerGroupTarget) {
                            return;
                        }

                        if (totalPages <= 1) {
                            this.pagerGroupTarget.innerHTML = '';
                            return;
                        }

                        const pages = [];
                        const startPage = Math.max(1, page - 1);
                        const endPage = Math.min(totalPages, startPage + 2);
                        for (let p = startPage; p <= endPage; p += 1) {
                            pages.push(p);
                        }

                        const pageButtons = pages.map((p) => (
                            `<button type="button" class="liveux-fm-page-btn ${p === page ? 'is-active' : ''}"${p === page ? ' aria-current="page"' : ''} data-go-page="${p}">${this.escapeHtml(this.formatNumber(p))}</button>`
                        )).join('');

                        const firstLabel = this.escapeAttr(this.t('pagination.first'));
                        const lastLabel = this.escapeAttr(this.t('pagination.last'));
                        const prevLabel = this.escapeAttr(this.t('pagination.prev'));
                        const nextLabel = this.escapeAttr(this.t('pagination.next'));
                        const firstBtn = totalPages > 3
                            ? `<button type="button" class="liveux-fm-page-btn" data-pager-arrow data-go-page="1" ${page <= 1 ? 'disabled' : ''} title="${firstLabel}" aria-label="${firstLabel}">«</button>`
                            : '';
                        const lastBtn = totalPages > 3
                            ? `<button type="button" class="liveux-fm-page-btn" data-pager-arrow data-go-page="${totalPages}" ${page >= totalPages ? 'disabled' : ''} title="${lastLabel}" aria-label="${lastLabel}">»</button>`
                            : '';
                        const jumpHtml = totalPages > 4
                            ? `<div class="liveux-fm-row-sm liveux-fm-hide-md liveux-fm-muted" dir="ltr" style="margin-inline-start: 4px; font-size: 0.7rem;">
                <input type="text" class="liveux-fm-page-jump" value="${this.escapeAttr(this.formatNumber(page, false))}" data-page-jump-input title="${this.escapeAttr(this.t('ui.key_enter'))}" aria-label="${this.escapeAttr(this.t('ui.page_label'))}">
                <span class="liveux-fm-mono">/ ${this.escapeHtml(this.formatNumber(totalPages))}</span>
               </div>`
                            : '';

                        this.pagerGroupTarget.innerHTML = `
            <div class="liveux-fm-pager">
                ${firstBtn}
                <button type="button" class="liveux-fm-page-btn" data-pager-arrow data-go-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} title="${prevLabel}" aria-label="${prevLabel}">‹</button>
                ${pageButtons}
                <button type="button" class="liveux-fm-page-btn" data-pager-arrow data-go-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''} title="${nextLabel}" aria-label="${nextLabel}">›</button>
                ${lastBtn}
            </div>
            ${jumpHtml}
        `;

                        this.pagerGroupTarget.querySelectorAll('[data-go-page]').forEach((btn) => {
                            btn.addEventListener('click', () => {
                                const targetPage = parseInt(btn.dataset.goPage, 10);
                                if (!Number.isNaN(targetPage) && targetPage >= 1 && targetPage <= totalPages && targetPage !== this.pageValue) {
                                    this.pageValue = targetPage;
                                    if (this.isServerPaginatedDirectory()) {
                                        this.fetchServerPage(targetPage);
                                    } else {
                                        this.applyClientView();
                                    }
                                }
                            });
                        });

                        const jumpInput = this.pagerGroupTarget.querySelector('[data-page-jump-input]');
                        jumpInput?.addEventListener('keydown', (e) => {
                            if (e.isComposing || e.keyCode === 229) {
                                return;
                            }
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                const targetPage = this.parseLocalizedInt(jumpInput.value);
                                if (!Number.isNaN(targetPage)) {
                                    const clamped = Math.min(Math.max(1, targetPage), totalPages);
                                    this.pageValue = clamped;
                                    if (this.isServerPaginatedDirectory()) {
                                        this.fetchServerPage(clamped);
                                    } else {
                                        this.applyClientView();
                                    }
                                }
                            }
                        });
                    }

                    updatePerfBadge(fromCache, durationMs) {
                        const label = this.t(fromCache ? 'ui.cache_hit' : 'ui.cache_disk');
                        const text = `${label} • ${durationMs}ms`;
                        if (this.hasPerfBadgeTarget) {
                            this.perfBadgeTarget.textContent = text;
                        }
                        if (this.hasSettingsCachePillTarget) {
                            this.settingsCachePillTarget.textContent = text;
                        }
                    }

                    setLoading(loading) {
                        if (this.hasSpinnerTarget) {
                            this.spinnerTarget.classList.toggle('liveux-fm-hidden', !loading);
                        }
                    }

                    showStatus(message, type = 'success') {
                        if (!this.hasStatusBannerTarget) {
                            return;
                        }
                        const safeType = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
                        this.statusBannerTarget.className = `liveux-fm-banner is-${safeType}`;
                        this.statusBannerTarget.textContent = message;
                        clearTimeout(this.statusTimer);
                        this.statusTimer = setTimeout(() => {
                            if (this.hasStatusBannerTarget) {
                                this.statusBannerTarget.classList.add('liveux-fm-hidden');
                            }
                        }, 4000);
                    }

                    // =========================================================================
                    // Bulletproof Delegated Context Menu (Right-Click + "..." Button)
                    // =========================================================================
                    onDelegatedContextMenu(event) {
                        if (this.capabilitiesValue?.allowContextMenu === false) {
                            return;
                        }
                        const card = event.target.closest('[data-ctx-kind]');
                        if (card && this.element.contains(card)) {
                            event.preventDefault();
                            event.stopPropagation();
                            this.showContextMenuForElement(card, event.clientX, event.clientY);
                            return;
                        }

                        // Right-click on empty whitespace inside contentArea
                        if (this.hasContentAreaTarget && this.contentAreaTarget.contains(event.target) && !this.trashMode) {
                            event.preventDefault();
                            event.stopPropagation();
                            this.showEmptyAreaContextMenu(event.clientX, event.clientY);
                        }
                    }

                    buildContextNavHtml() {
                        const caps = this.capabilitiesValue;
                        if (caps.showContextNav === false) {
                            return '';
                        }

                        const buttons = [];
                        if (caps.showHistoryButtons !== false) {
                            buttons.push(`
                <button type="button" class="liveux-fm-ctx-nav-btn" data-ctx-nav="back" ${this.canGoBack() ? '' : 'disabled'} title="${this.escapeAttr(this.t('actions.back'))}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                </button>
                <button type="button" class="liveux-fm-ctx-nav-btn" data-ctx-nav="forward" ${this.canGoForward() ? '' : 'disabled'} title="${this.escapeAttr(this.t('actions.forward'))}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                </button>`);
                        }
                        if (caps.showUpButton !== false) {
                            buttons.push(`
                <button type="button" class="liveux-fm-ctx-nav-btn" data-ctx-nav="up" ${this.canGoUp() ? '' : 'disabled'} title="${this.escapeAttr(this.t('actions.up'))}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18"/></svg>
                </button>`);
                        }
                        if (caps.showRefreshButton !== false) {
                            buttons.push(`
                <button type="button" class="liveux-fm-ctx-nav-btn" data-ctx-nav="refresh" title="${this.escapeAttr(this.t('actions.refresh'))}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                </button>`);
                        }

                        if (buttons.length === 0) {
                            return '';
                        }

                        return `<div class="liveux-fm-ctx-nav">${buttons.join('')}</div>`;
                    }

                    bindContextNavHandlers(menu) {
                        menu.querySelectorAll('[data-ctx-nav]').forEach((btn) => {
                            btn.addEventListener('click', async (e) => {
                                e.stopPropagation();
                                if (btn.disabled) {
                                    return;
                                }
                                this.hideContextMenu();
                                const nav = btn.dataset.ctxNav;
                                if (nav === 'back') {
                                    await this.goBack();
                                } else if (nav === 'forward') {
                                    await this.goForward();
                                } else if (nav === 'up') {
                                    await this.goUp();
                                } else if (nav === 'refresh') {
                                    await this.refreshCurrent();
                                }
                            });
                        });
                    }

                    uiIcon(name, size = 14) {
                        const safeSize = Math.max(8, Math.min(64, parseInt(size, 10) || 14));
                        const aliasMap = {
                            newFolder: 'new_folder',
                            editImage: 'edit_image',
                            selectAll: 'select_all',
                            limit: 'grid',
                            delete: 'trash',
                        };
                        const resolvedKey = Object.prototype.hasOwnProperty.call(aliasMap, name) ? aliasMap[name] : name;
                        const uiMap = this.iconSetValue?.ui;
                        const rawSvg = uiMap && Object.prototype.hasOwnProperty.call(uiMap, resolvedKey) ? uiMap[resolvedKey] : '';
                        if (rawSvg) {
                            const clean = this.sanitizeInlineSvg(rawSvg, safeSize);
                            if (clean) {
                                return clean;
                            }
                        }

                        const fallbackPaths = {
                            open: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776"/>',
                            new_folder: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>',
                            upload: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>',
                            download: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>',
                            preview: '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
                            edit_image: '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>',
                            tag: '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/>',
                            archive: '<path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>',
                            link: '<path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/>',
                            share: '<path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/>',
                            move: '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>',
                            duplicate: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v8.25A2.25 2.25 0 0 0 6 16.5h2.25m8.25-8.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-7.5A2.25 2.25 0 0 1 8.25 18v-1.5m8.25-8.25h-6a2.25 2.25 0 0 0-2.25 2.25v6"/>',
                            color: '<path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z"/>',
                            cut: '<path stroke-linecap="round" stroke-linejoin="round" d="m7.848 8.25 1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3Zm1.536.887a2.165 2.165 0 0 1 1.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l2.077 1.199M7.848 15.75l1.536-.887m-1.536.887a3 3 0 1 1-5.196 3 3 3 0 0 1 5.196-3Zm1.536-.887a2.165 2.165 0 0 0 1.083-1.838c.005-.352.054-.695.14-1.025m-1.223 2.863 2.077-1.199m0-3.328a4.323 4.323 0 0 1 2.068-1.379l5.325-1.628a4.5 4.5 0 0 1 2.48-.044l.803.215-7.794 4.5m-2.882-1.664A4.33 4.33 0 0 0 10.607 12m3.736 0 7.794 4.5-.802.215a4.5 4.5 0 0 1-2.48-.043l-5.326-1.629a4.324 4.324 0 0 1-2.068-1.379M14.343 12l-2.882 1.664"/>',
                            copy: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75"/>',
                            paste: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/>',
                            select_all: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
                            refresh: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>',
                            sort: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5"/>',
                            grid: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>',
                            rename: '<path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>',
                            trash: '<path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>',
                            star: '<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/>',
                            fullscreen: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/>',
                            exit_fullscreen: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/>',
                        };
                        const d = Object.prototype.hasOwnProperty.call(fallbackPaths, resolvedKey) ? fallbackPaths[resolvedKey] : '';
                        return `<svg width="${safeSize}" height="${safeSize}" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
                    }

                    ctxIcon(name, size = 14) {
                        return `<span class="liveux-fm-ctx-icon">${this.uiIcon(name, size)}</span>`;
                    }

                    bindContextSubmenuToggles(menu) {
                        menu.querySelectorAll('.liveux-fm-ctx-sub > .liveux-fm-ctx-item').forEach((trigger) => {
                            trigger.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const parent = trigger.parentElement;
                                const willOpen = !parent.classList.contains('is-open');
                                menu.querySelectorAll('.liveux-fm-ctx-sub.is-open').forEach((node) => node.classList.remove('is-open'));
                                parent.classList.toggle('is-open', willOpen);
                            });
                        });
                    }

                    showEmptyAreaContextMenu(clientX, clientY) {
                        if (!this.hasContextMenuTarget) {
                            return;
                        }
                        const caps = this.capabilitiesValue;
                        const items = [];
                        const navHtml = this.buildContextNavHtml();
                        if (navHtml) {
                            items.push(navHtml);
                        }
                        if (caps.createFolder) {
                            items.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="newFolder">${this.ctxIcon('newFolder')}<span>${this.escapeHtml(this.t('actions.new_folder'))}</span></button>`);
                        }
                        if (caps.upload) {
                            items.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="uploadHere">${this.ctxIcon('upload')}<span>${this.escapeHtml(this.t('actions.upload_here'))}</span></button>`);
                        }
                        const canPaste = Boolean(this.clipboard && this.clipboard.paths.length > 0 && ((this.clipboard.mode === 'copy' && caps.copy) || (this.clipboard.mode === 'move' && caps.move)));
                        if (canPaste) {
                            items.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="paste">${this.ctxIcon('paste')}<span>${this.escapeHtml(this.t('actions.paste'))} (${this.clipboard.paths.length})</span></button>`);
                        }
                        if (this.renderedPaths.length > 0) {
                            items.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="selectAll">${this.ctxIcon('selectAll')}<span>${this.escapeHtml(this.t('actions.select_all'))}</span></button>`);
                        }
                        if (caps.showContextNav === false && caps.showRefreshButton !== false) {
                            items.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="refresh">${this.ctxIcon('refresh')}<span>${this.escapeHtml(this.t('actions.refresh'))}</span></button>`);
                        }

                        const showSortSub = caps.showSort !== false;
                        const showLimitSub = caps.showPerPageSelector !== false && caps.showPagination !== false;
                        if ((showSortSub || showLimitSub) && items.length > 0) {
                            items.push('<div class="liveux-fm-ctx-sep"></div>');
                        }

                        if (showSortSub) {
                            const sortFields = [
                                ['name', this.t('sort.field_name')],
                                ['size', this.t('sort.field_size')],
                                ['date', this.t('sort.field_date')],
                                ['type', this.t('sort.field_type')],
                            ];
                            const sortSubItems = sortFields.map(([val, label]) => (
                                `<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx-sort-by="${val}"><span class="liveux-fm-ctx-check">${this.sortByValue === val ? '✓' : ''}</span><span>${this.escapeHtml(label)}</span></button>`
                            )).join('')
                                + '<div class="liveux-fm-ctx-sep"></div>'
                                + `<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx-sort-dir="asc"><span class="liveux-fm-ctx-check">${this.sortDirValue === 'asc' ? '✓' : ''}</span><span>${this.escapeHtml(this.t('sort.asc'))}</span></button>`
                                + `<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx-sort-dir="desc"><span class="liveux-fm-ctx-check">${this.sortDirValue === 'desc' ? '✓' : ''}</span><span>${this.escapeHtml(this.t('sort.desc'))}</span></button>`;

                            items.push(`<div class="liveux-fm-ctx-sub">
                <button type="button" class="liveux-fm-ctx-item" role="menuitem"><span class="liveux-fm-row-sm">${this.ctxIcon('sort')}<span>${this.escapeHtml(this.t('sort.title'))}</span></span><span>▸</span></button>
                <div class="liveux-fm-ctx-submenu">${sortSubItems}</div>
            </div>`);
                        }

                        if (showLimitSub) {
                            const rawOpts = Array.isArray(this.perPageOptionsValue) ? this.perPageOptionsValue : [];
                            const sanitizedOpts = rawOpts
                                .map((n) => parseInt(n, 10))
                                .filter((n) => !Number.isNaN(n) && n >= 1 && n <= 500);
                            const perPageOpts = sanitizedOpts.length > 0 ? sanitizedOpts : [24, 48, 96];
                            const limitSubItems = perPageOpts.map((n) => (
                                `<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx-limit="${n}"><span class="liveux-fm-ctx-check">${this.perPageValue === n ? '✓' : ''}</span><span>${n}</span></button>`
                            )).join('');

                            items.push(`<div class="liveux-fm-ctx-sub">
                <button type="button" class="liveux-fm-ctx-item" role="menuitem"><span class="liveux-fm-row-sm">${this.ctxIcon('limit')}<span>${this.escapeHtml(this.t('sort.limit'))}</span></span><span>▸</span></button>
                <div class="liveux-fm-ctx-submenu">${limitSubItems}</div>
            </div>`);
                        }

                        if (items.length === 0) {
                            return;
                        }
                        const menu = this.contextMenuTarget;
                        menu.innerHTML = items.join('');
                        this.positionAndOpenContextMenu(menu, clientX, clientY);
                        this.bindContextNavHandlers(menu);
                        this.bindContextSubmenuToggles(menu);

                        menu.querySelectorAll('[data-ctx]').forEach((btn) => {
                            btn.addEventListener('click', async (e) => {
                                e.stopPropagation();
                                this.hideContextMenu();
                                const action = btn.dataset.ctx;
                                if (action === 'newFolder') {
                                    this.openNewFolderModal(this.pathValue);
                                } else if (action === 'uploadHere') {
                                    this.openUploadModal(this.pathValue);
                                } else if (action === 'paste') {
                                    await this.pasteClipboard();
                                } else if (action === 'selectAll') {
                                    this.selectAllVisible();
                                } else if (action === 'refresh') {
                                    await this.refreshCurrent();
                                }
                            });
                        });

                        menu.querySelectorAll('[data-ctx-sort-by]').forEach((btn) => {
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                this.hideContextMenu();
                                this.sortByValue = btn.dataset.ctxSortBy;
                                if (this.hasSortBySelectTarget) {
                                    this.sortBySelectTarget.value = this.sortByValue;
                                }
                                this.pageValue = 1;
                                this.applyClientView();
                            });
                        });

                        menu.querySelectorAll('[data-ctx-sort-dir]').forEach((btn) => {
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                this.hideContextMenu();
                                this.sortDirValue = btn.dataset.ctxSortDir;
                                this.syncSortDirButton();
                                this.pageValue = 1;
                                this.applyClientView();
                            });
                        });

                        menu.querySelectorAll('[data-ctx-limit]').forEach((btn) => {
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                this.hideContextMenu();
                                this.perPageValue = parseInt(btn.dataset.ctxLimit, 10) || 24;
                                if (this.hasPerPageSelectTarget) {
                                    this.perPageSelectTarget.value = String(this.perPageValue);
                                }
                                this.pageValue = 1;
                                this.applyClientView();
                            });
                        });
                    }

                    positionAndOpenContextMenu(menu, clientX, clientY) {
                        this.closeSettingsPopover();
                        if (typeof document !== 'undefined' && document.activeElement && document.activeElement !== document.body && !menu.contains?.(document.activeElement)) {
                            this._lastFocusedBeforeCtxMenu = document.activeElement;
                        }
                        menu.classList.add('is-open');
                        const rect = typeof menu.getBoundingClientRect === 'function' ? menu.getBoundingClientRect() : null;
                        const menuW = Math.max(200, Math.round(rect?.width || menu.offsetWidth || 215));
                        const menuH = Math.max(80, Math.round(rect?.height || menu.offsetHeight || 260));
                        const left = Math.min(clientX, window.innerWidth - menuW - 8);
                        const top = Math.min(clientY, window.innerHeight - menuH - 8);
                        menu.style.left = `${Math.max(8, left)}px`;
                        menu.style.top = `${Math.max(8, top)}px`;
                        const shouldFlipX = this.isRtl() ? (left - 190 < 0) : (left + menuW + 190 > window.innerWidth);
                        menu.classList.toggle('is-flip-left', shouldFlipX);
                        menu.classList.toggle('is-flip-x', shouldFlipX);
                        const firstMenuItem = menu.querySelector?.('button:not([disabled]), a[href]');
                        firstMenuItem?.focus?.();
                    }

                    buildMultiQuickBarHtml({ caps, count }) {
                        const btns = [];
                        if (caps.move) {
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-ctx="cut" title="${this.escapeAttr(`${this.t('actions.cut')} (${count})`)}" aria-label="${this.escapeAttr(this.t('actions.cut'))}">${this.uiIcon('cut', 15)}</button>`);
                        }
                        if (caps.copy) {
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-ctx="copy" title="${this.escapeAttr(`${this.t('actions.copy_verb')} (${count})`)}" aria-label="${this.escapeAttr(this.t('actions.copy_verb'))}">${this.uiIcon('copy', 15)}</button>`);
                        }
                        if (caps.delete) {
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn is-danger" data-ctx="delete" title="${this.escapeAttr(`${this.t('actions.delete')} (${count})`)}" aria-label="${this.escapeAttr(this.t('actions.delete'))}">${this.uiIcon('trash', 15)}</button>`);
                        }

                        return btns.length > 0 ? `<div class="liveux-fm-ctx-actions">${btns.join('')}</div>` : '';
                    }

                    buildItemQuickBarHtml({ isFolder, caps, isPinned, downloadUrl }) {
                        const btns = [];
                        if (caps.move) {
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-ctx="cut" title="${this.escapeAttr(`${this.t('actions.cut')} (Ctrl+X)`)}" aria-label="${this.escapeAttr(this.t('actions.cut'))}">${this.uiIcon('cut', 15)}</button>`);
                        }
                        if (caps.copy) {
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-ctx="copy" title="${this.escapeAttr(`${this.t('actions.copy_verb')} (Ctrl+C)`)}" aria-label="${this.escapeAttr(this.t('actions.copy_verb'))}">${this.uiIcon('copy', 15)}</button>`);
                        }
                        if (caps.rename) {
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-ctx="rename" title="${this.escapeAttr(`${this.t('actions.rename')} (F2)`)}" aria-label="${this.escapeAttr(this.t('actions.rename'))}">${this.uiIcon('rename', 15)}</button>`);
                        }
                        if (isFolder && caps.editMetadata) {
                            const pinLabel = this.t(isPinned ? 'actions.unpin' : 'actions.pin');
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn ${isPinned ? 'is-active' : ''}" data-ctx="togglePin" title="${this.escapeAttr(pinLabel)}" aria-label="${this.escapeAttr(pinLabel)}">${this.uiIcon('star', 15)}</button>`);
                        } else if (!isFolder && caps.download && this.isSafeHttpOrPathUrl(downloadUrl)) {
                            btns.push(`<a class="liveux-fm-ctx-action-btn" href="${this.escapeAttr(downloadUrl)}" data-turbo="false" download data-ctx-download title="${this.escapeAttr(this.t('actions.download'))}" aria-label="${this.escapeAttr(this.t('actions.download'))}">${this.uiIcon('download', 15)}</a>`);
                        }
                        if (caps.delete) {
                            const delKey = this.t('ui.key_delete');
                            btns.push(`<button type="button" class="liveux-fm-ctx-action-btn is-danger" data-ctx="delete" title="${this.escapeAttr(`${this.t('actions.delete')} (${delKey})`)}" aria-label="${this.escapeAttr(this.t('actions.delete'))}">${this.uiIcon('trash', 15)}</button>`);
                        }

                        return btns.length > 0 ? `<div class="liveux-fm-ctx-actions">${btns.join('')}</div>` : '';
                    }

                    openContextFromButton(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (this.capabilitiesValue?.allowContextMenu === false) {
                            return;
                        }
                        const card = event.currentTarget.closest('[data-ctx-kind]');
                        if (!card) {
                            return;
                        }
                        const rect = event.currentTarget.getBoundingClientRect();
                        this.showContextMenuForElement(card, rect.left, rect.bottom + 4);
                    }

                    showContextMenuForElement(el, clientX, clientY) {
                        if (!this.hasContextMenuTarget) {
                            return;
                        }
                        this.closeSettingsPopover();

                        const kind = el.dataset.ctxKind;
                        const isFolder = kind === 'folder';
                        const path = el.dataset.path || '';
                        const name = el.dataset.name || '';
                        const url = el.dataset.url || '';
                        const downloadUrl = el.dataset.downloadUrl || '';
                        const color = el.dataset.color || '';
                        const icon = el.dataset.icon || '';
                        const isPinned = el.dataset.pinned === '1';
                        const isImage = el.dataset.isImage === '1';
                        const extension = el.dataset.extension || '';
                        const caps = this.capabilitiesValue;
                        const previewable = !isFolder && this.getPreviewKind({ kind: 'file', isImage, extension, url, path, name }) !== null;

                        const isInContent = !this.hasContentAreaTarget || this.contentAreaTarget.contains(el);
                        const isMultiSelected = isInContent && this.selectedMap.size > 1 && this.selectedMap.has(path);
                        const selectedPaths = isMultiSelected ? Array.from(this.selectedMap.keys()) : [path];
                        const selectedCount = selectedPaths.length;

                        const moveActionLabel = caps.move ? this.t('actions.move') : this.t('actions.copy_verb');
                        const items = [];
                        if (isMultiSelected) {
                            const multiLabel = `${selectedCount} ${this.t('inspector.selected_count')}`;
                            items.push(`<div class="liveux-fm-ctx-header" title="${this.escapeAttr(multiLabel)}">${this.escapeHtml(multiLabel)}</div>`);
                            const multiQuickBarHtml = this.buildMultiQuickBarHtml({ caps, count: selectedCount });
                            if (multiQuickBarHtml) {
                                items.push(multiQuickBarHtml);
                            }

                            const bulkRows = [];
                            if (caps.move || caps.copy) {
                                bulkRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="move">${this.ctxIcon('move')}<span>${this.escapeHtml(moveActionLabel)} (${selectedCount})</span></button>`);
                            }
                            if (caps.downloadZip && this.zipUrlValue) {
                                bulkRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="downloadZip">${this.ctxIcon('archive')}<span>${this.escapeHtml(this.t('actions.download_zip'))} (${selectedCount})</span></button>`);
                            }
                            if (bulkRows.length > 0) {
                                items.push(...bulkRows);
                            }
                        } else {
                            items.push(`<div class="liveux-fm-ctx-header" title="${this.escapeAttr(name)}">${this.escapeHtml(name)}</div>`);
                            const quickBarHtml = this.buildItemQuickBarHtml({ isFolder, caps, isPinned, downloadUrl });
                            if (quickBarHtml) {
                                items.push(quickBarHtml);
                            }

                            const pushSection = (sectionRows) => {
                                if (sectionRows.length === 0) {
                                    return;
                                }
                                const last = items[items.length - 1] || '';
                                if (items.length > 0 && !last.includes('liveux-fm-ctx-header') && !last.includes('liveux-fm-ctx-actions') && !last.includes('liveux-fm-ctx-sep')) {
                                    items.push('<div class="liveux-fm-ctx-sep"></div>');
                                }
                                items.push(...sectionRows);
                            };

                            if (isFolder) {
                                const primaryRows = [
                                    `<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="open">${this.ctxIcon('open')}<span>${this.escapeHtml(this.t('actions.open'))}</span></button>`,
                                ];
                                const folderVol = el.dataset.volume || this.volumeValue;
                                const sameClipboardVol = Boolean(this.clipboard && this.clipboard.volume === folderVol);
                                const canPasteIntoFolder = Boolean(
                                    this.clipboard
                                    && this.clipboard.paths.length > 0
                                    && ((this.clipboard.mode === 'copy' && caps.copy) || (this.clipboard.mode === 'move' && caps.move))
                                    && (!sameClipboardVol || !this.clipboard.paths.some((p) => p === path || path.startsWith(`${p}/`))),
                                );
                                if (canPasteIntoFolder) {
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="pasteIntoFolder">${this.ctxIcon('paste')}<span>${this.escapeHtml(this.t('actions.paste_into_folder'))} (${this.clipboard.paths.length})</span></button>`);
                                }
                                if (caps.upload) {
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="uploadToFolder">${this.ctxIcon('upload')}<span>${this.escapeHtml(this.t('actions.upload_to_folder'))}</span></button>`);
                                }
                                if (caps.createFolder) {
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="newSubfolder">${this.ctxIcon('newFolder')}<span>${this.escapeHtml(this.t('actions.new_subfolder'))}</span></button>`);
                                }
                                pushSection(primaryRows);

                                const secondaryRows = [];
                                if (caps.move || caps.copy) {
                                    secondaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="move">${this.ctxIcon('move')}<span>${this.escapeHtml(moveActionLabel)}</span></button>`);
                                }
                                if (caps.editMetadata) {
                                    secondaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="color">${this.ctxIcon('color')}<span>${this.escapeHtml(this.t('actions.customize'))}</span></button>`);
                                }
                                pushSection(secondaryRows);
                            } else {
                                const primaryRows = [];
                                if (previewable) {
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="preview">${this.ctxIcon('preview')}<span>${this.escapeHtml(this.t('actions.preview'))}</span></button>`);
                                }
                                if (isImage && this.isEditableRasterExtension(extension) && caps.editImage && this.imageEditUrlValue) {
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="editImage">${this.ctxIcon('editImage')}<span>${this.escapeHtml(this.t('actions.edit_image'))}</span></button>`);
                                }
                                if (caps.editMetadata) {
                                    const metaLabel = this.t(isImage ? 'actions.edit_image_metadata' : 'actions.edit_metadata');
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="editMeta">${this.ctxIcon('tag')}<span>${this.escapeHtml(metaLabel)}</span></button>`);
                                }
                                if (caps.extractZip && extension.toLowerCase() === 'zip') {
                                    primaryRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="extractZip">${this.ctxIcon('archive')}<span>${this.escapeHtml(this.t('actions.extract_zip'))}</span></button>`);
                                }
                                pushSection(primaryRows);

                                const shareRows = [];
                                if (caps.download !== false && url && this.isSafeUrl(url)) {
                                    shareRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="copyUrl">${this.ctxIcon('link')}<span>${this.escapeHtml(this.t('actions.copy_url'))}</span></button>`);
                                }
                                if (caps.share) {
                                    shareRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="shareLink">${this.ctxIcon('share')}<span>${this.escapeHtml(this.t('actions.share_link'))}</span></button>`);
                                }
                                pushSection(shareRows);

                                const locationRows = [];
                                if (caps.move || caps.copy) {
                                    locationRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="move">${this.ctxIcon('move')}<span>${this.escapeHtml(moveActionLabel)}</span></button>`);
                                }
                                if (caps.clone) {
                                    locationRows.push(`<button type="button" class="liveux-fm-ctx-item" role="menuitem" data-ctx="clone">${this.ctxIcon('duplicate')}<span>${this.escapeHtml(this.t('actions.clone'))}</span></button>`);
                                }
                                pushSection(locationRows);
                            }
                        }

                        if (!items.some((row) => row.includes('data-ctx=') || row.includes('data-ctx-download'))) {
                            return;
                        }
                        const menu = this.contextMenuTarget;
                        menu.innerHTML = items.join('');
                        this.positionAndOpenContextMenu(menu, clientX, clientY);

                        menu.querySelector('[data-ctx-download]')?.addEventListener('click', () => {
                            this.hideContextMenu();
                        });

                        menu.querySelectorAll('[data-ctx]').forEach((btn) => {
                            btn.addEventListener('click', async (e) => {
                                e.stopPropagation();
                                this.hideContextMenu();
                                const action = btn.dataset.ctx;
                                if (action === 'open') {
                                    this.navigateTo(path);
                                } else if (action === 'pasteIntoFolder') {
                                    const targetVol = el.dataset.volume || this.volumeValue;
                                    await this.pasteClipboard(path, targetVol);
                                } else if (action === 'newSubfolder') {
                                    this.openNewFolderModal(path);
                                } else if (action === 'uploadToFolder') {
                                    this.openUploadModal(path);
                                } else if (action === 'togglePin') {
                                    this.toggleFolderPin(path);
                                } else if (action === 'cut') {
                                    const targets = isMultiSelected ? selectedPaths : [path];
                                    this.copyToClipboard('move', targets);
                                } else if (action === 'copy') {
                                    const targets = isMultiSelected ? selectedPaths : [path];
                                    this.copyToClipboard('copy', targets);
                                } else if (action === 'move') {
                                    if (isMultiSelected) {
                                        this.bulkMove();
                                    } else {
                                        this.openMoveModal(path, name, isFolder);
                                    }
                                } else if (action === 'downloadZip') {
                                    await this.downloadZip();
                                } else if (action === 'preview') {
                                    this.openPreview(path);
                                } else if (action === 'editImage') {
                                    this.openImageEditorModal(path, name, url, downloadUrl);
                                } else if (action === 'editMeta') {
                                    const found = this.getActiveItemList().find((i) => i.path === path);
                                    if (found) {
                                        this.openFileMetadataModal(found);
                                    }
                                } else if (action === 'extractZip') {
                                    this.extractZipArchive(path);
                                } else if (action === 'copyUrl') {
                                    if (url && this.isSafeUrl(url) && await this.copyTextToClipboard(url)) {
                                        this.showStatus(this.t('actions.copied'), 'success');
                                    }
                                } else if (action === 'shareLink') {
                                    const found = this.getActiveItemList().find((i) => i.path === path) || { path, name };
                                    this.openShareLinkModal(found);
                                } else if (action === 'rename') {
                                    this.openRenameModal(path, name);
                                } else if (action === 'clone') {
                                    this.openCloneModal(path, name);
                                } else if (action === 'color') {
                                    this.openFolderColorModal(path, name, color, icon);
                                } else if (action === 'delete') {
                                    if (isMultiSelected) {
                                        this.openDeleteModal(selectedPaths, `${selectedCount}`);
                                    } else {
                                        this.openDeleteModal([path], name);
                                    }
                                }
                            });
                        });
                    }

                    hideContextMenu(restoreFocus = false) {
                        const wasOpen = this.isContextMenuOpen();
                        if (this.hasContextMenuTarget) {
                            this.contextMenuTarget.classList.remove('is-open');
                        }
                        if (restoreFocus && wasOpen && this._lastFocusedBeforeCtxMenu?.isConnected) {
                            this._lastFocusedBeforeCtxMenu.focus?.();
                        }
                        this._lastFocusedBeforeCtxMenu = null;
                    }

                    // =========================================================================
                    // Sleek Inline Modals (No Ugly window.prompt!)
                    // =========================================================================
                    openModal(title, bodyHtml) {
                        if (!this.hasModalBackdropTarget) {
                            return;
                        }
                        if (typeof document !== 'undefined' && document.activeElement && document.activeElement !== document.body) {
                            this._lastFocusedBeforeModal = document.activeElement;
                        }
                        this.modalTitleTarget.textContent = title;
                        this.modalBodyTarget.innerHTML = bodyHtml;
                        this.modalBackdropTarget.classList.add('is-open');
                        const textInput = this.modalBodyTarget.querySelector('input[type="text"]');
                        if (textInput) {
                            textInput.focus?.();
                            textInput.select?.();
                        } else {
                            const focusable = this.modalBodyTarget.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex="0"]');
                            focusable?.focus?.();
                        }
                    }

                    closeModal() {
                        const wasOpen = this.isModalOpen();
                        this.uploadTargetDir = null;
                        this.runActiveDragCleanup();
                        if (this._editorRenderRaf) {
                            cancelAnimationFrame(this._editorRenderRaf);
                            this._editorRenderRaf = null;
                        }
                        if (this._editorImage) {
                            this._editorImage.onload = null;
                            this._editorImage.onerror = null;
                            this._editorImage = null;
                        }
                        if (this.hasModalBackdropTarget) {
                            this.modalBackdropTarget.classList.remove('is-open');
                        }
                        if (this.hasModalBodyTarget) {
                            this.modalBodyTarget.innerHTML = '';
                        }
                        if (wasOpen) {
                            const prev = this._lastFocusedBeforeModal;
                            this._lastFocusedBeforeModal = null;
                            if (prev && prev.isConnected !== false && typeof prev.focus === 'function') {
                                try { prev.focus(); } catch (_) { this.restoreRootFocus(); }
                            } else {
                                this.restoreRootFocus();
                            }
                        }
                    }

                    openUploadModal(targetOverride = null) {
                        const targetDir = typeof targetOverride === 'string' ? targetOverride : this.pathValue;
                        this.uploadTargetDir = targetDir;
                        const targetLabel = targetDir ? `/${targetDir}` : `/ (${this.volumeLabelValue || this.volumeValue})`;
                        const allowUrl = Boolean(this.capabilitiesValue?.remoteImport);
                        const effectiveChunkBytes = Math.max(262144, this.chunkSizeValue || 2097152);
                        const chunkHint = this.chunkEnabledValue
                            ? `${this.t('modals.upload_chunk_hint')} (${this.formatBytes(effectiveChunkBytes)})`
                            : '';
                        const imgConstraintHint = this.formatImageConstraintsHint();
                        const maxHint = [
                            this.uploadMaxSizeLabelValue ? this.t('modals.upload_max_size', { size: this.uploadMaxSizeLabelValue }) : '',
                            imgConstraintHint,
                            chunkHint,
                        ].filter(Boolean).join(' • ');

                        const tabsHtml = allowUrl
                            ? `<div class="liveux-fm-segmented" style="margin-bottom: 12px;" data-up-tabs>
                <button type="button" class="is-active" data-up-tab="device">${this.escapeHtml(this.t('modals.tab_device'))}</button>
                <button type="button" data-up-tab="url">${this.escapeHtml(this.t('modals.tab_url'))}</button>
               </div>`
                            : '';

                        const urlPaneHtml = allowUrl
                            ? `<form class="liveux-fm-hidden" data-up-pane="url" data-url-import-form>
                <input type="url" dir="ltr" class="liveux-fm-input liveux-fm-mono" style="margin-bottom: 8px;" name="url" placeholder="${this.escapeAttr(this.t('modals.url_placeholder'))}" required>
                <div class="liveux-fm-muted" style="font-size: 0.7rem; margin-bottom: 12px;">${this.escapeHtml(this.t('modals.url_hint'))}</div>
                <button type="submit" class="liveux-fm-btn liveux-fm-btn-primary liveux-fm-w-full">${this.escapeHtml(this.t('actions.import_url'))}</button>
               </form>`
                            : '';

                        this.openModal(this.t('modals.upload_title'), `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-path-bar">
                    ${this.renderFolderSvg(15, this.sanitizeHexColor(this.iconSetValue?.defaultFolderColor) || '#2563eb')}
                    <span>${this.escapeHtml(this.t('modals.location'))}:</span>
                    <code dir="ltr" title="${this.escapeAttr(targetLabel)}">${this.escapeHtml(targetLabel)}</code>
                </div>
                ${tabsHtml}

                <div data-up-pane="device">
                    <div class="liveux-fm-dropzone" data-upload-dropzone>
                        <svg width="32" height="32" style="color: var(--liveux-fm-accent); margin-bottom: 8px;" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/>
                        </svg>
                        <div class="liveux-fm-strong liveux-fm-text-sm" style="margin-bottom: 4px;">${this.escapeHtml(this.t('modals.upload_drop_title'))}</div>
                        <div class="liveux-fm-muted" style="font-size: 0.72rem; margin-bottom: 10px;">${this.escapeHtml(this.t('modals.upload_drop_hint'))}</div>
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-upload-browse>${this.escapeHtml(this.t('actions.browse_files'))}</button>
                    </div>
                    <div style="margin-top: 8px;" data-upload-modal-queue></div>
                </div>
                ${urlPaneHtml}
            </div>
            <div class="liveux-fm-modal-footer is-between">
                <span class="liveux-fm-muted" style="font-size: 0.7rem;">${this.escapeHtml(maxHint)}</span>
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.close'))}</button>
            </div>
        `);

                        this.modalBodyTarget.querySelectorAll('[data-up-tab]').forEach((btn) => {
                            btn.addEventListener('click', () => {
                                const activeTab = btn.dataset.upTab;
                                this.modalBodyTarget.querySelectorAll('[data-up-tab]').forEach((b) => b.classList.toggle('is-active', b.dataset.upTab === activeTab));
                                this.modalBodyTarget.querySelectorAll('[data-up-pane]').forEach((p) => p.classList.toggle('liveux-fm-hidden', p.dataset.upPane !== activeTab));
                            });
                        });

                        const urlForm = this.modalBodyTarget.querySelector('[data-url-import-form]');
                        urlForm?.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const fd = new FormData(urlForm);
                            const url = String(fd.get('url') || '').trim();
                            if (!url) {
                                return;
                            }
                            this.closeModal();
                            if (await this.callAction('importUrl', { url, targetDir })) {
                                this.invalidateClientCaches();
                                await Promise.all([
                                    this.fetchDirectory(this.volumeValue, this.pathValue),
                                    this.refreshFullTree(true),
                                ]);
                            }
                        });

                        const dz = this.modalBodyTarget.querySelector('[data-upload-dropzone]');
                        const browseBtn = this.modalBodyTarget.querySelector('[data-upload-browse]');
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());

                        const pickFiles = (e) => {
                            e.stopPropagation();
                            this.uploadTargetDir = targetDir;
                            this.triggerUpload();
                        };
                        dz?.addEventListener('click', pickFiles);
                        browseBtn?.addEventListener('click', pickFiles);

                        dz?.addEventListener('dragover', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            dz.classList.add('is-dragover');
                        });
                        dz?.addEventListener('dragleave', (e) => {
                            e.preventDefault();
                            dz.classList.remove('is-dragover');
                        });
                        dz?.addEventListener('drop', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            dz.classList.remove('is-dragover');
                            this.handleDataTransferDrop(e.dataTransfer, targetDir);
                        });

                        this.renderUploadQueue();
                    }

                    openImageEditorModal(path, name, url, downloadUrl = null) {
                        const foundItem = (this.dirCache && typeof this.getActiveItemList === 'function')
                            ? this.getActiveItemList().find((i) => i.path === path) || null
                            : null;
                        const ext = String(foundItem?.extension || (name || path || '').split('.').pop() || '').toLowerCase();
                        if (!this.isEditableRasterExtension(ext)) {
                            return;
                        }
                        const sourceVolume = this.volumeValue;
                        const origMime = (ext === 'jpg' || ext === 'jpeg')
                            ? 'image/jpeg'
                            : (ext === 'png' ? 'image/png' : (ext === 'webp' ? 'image/webp' : null));
                        const rawDl = downloadUrl || foundItem?.downloadUrl || '';
                        const inlineDlUrl = rawDl ? `${rawDl}${rawDl.includes('?') ? '&' : '?'}inline=1` : '';
                        const editorSrcUrl = (inlineDlUrl && this.isSafeUrl(inlineDlUrl))
                            ? inlineDlUrl
                            : (this.isSafeUrl(url) ? url : '');

                        // Exact 2x2 screen-space orientation matrix [a, b, c, d] so rotate & flip compose accurately in any order
                        let orientation = [1, 0, 0, 1];
                        let cropRatio = 'free';
                        let cropRect = null; // Normalized { x, y, w, h } in [0..1] on rotated workCanvas
                        let maxW = 0;
                        let maxH = 0;
                        let quality = 90;

                        // Detect browser Canvas WebP export capability gracefully
                        let supportsWebpExport = false;
                        try {
                            const probeCanvas = document.createElement('canvas');
                            probeCanvas.width = 2;
                            probeCanvas.height = 2;
                            supportsWebpExport = typeof probeCanvas.toDataURL === 'function'
                                && probeCanvas.toDataURL('image/webp').startsWith('data:image/webp');
                        } catch (_) { }

                        let outputMime = origMime === 'image/webp' && !supportsWebpExport
                            ? 'image/png'
                            : (origMime || 'image/png');
                        const canOverwrite = Boolean(this.capabilitiesValue?.delete && origMime && outputMime === origMime);
                        const autoPlaceholder = this.escapeAttr(this.t('ui.auto_placeholder'));

                        const formatOptions = [
                            `<option value="image/jpeg" ${outputMime === 'image/jpeg' ? 'selected' : ''}>JPEG</option>`,
                            `<option value="image/png" ${outputMime === 'image/png' ? 'selected' : ''}>PNG</option>`,
                            supportsWebpExport ? `<option value="image/webp" ${outputMime === 'image/webp' ? 'selected' : ''}>WebP</option>` : '',
                        ].join('');

                        this.openModal(`${this.t('modals.edit_image_title')} — ${name}`, `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-stage is-checker" style="aspect-ratio: 16 / 10; max-height: 290px; margin-bottom: 10px; user-select: none;">
                    <canvas data-editor-canvas class="liveux-fm-crop-canvas" style="max-width: 100%; max-height: 100%; display: block;"></canvas>
                </div>

                <div class="liveux-fm-row-between liveux-fm-row-wrap" style="margin-bottom: 8px; gap: 6px;">
                    <div class="liveux-fm-btn-group">
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-ed-op="rotL" title="${this.escapeAttr(this.t('modals.rotate_left'))}" aria-label="${this.escapeAttr(this.t('modals.rotate_left'))}">↺ 90°</button>
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-ed-op="rotR" title="${this.escapeAttr(this.t('modals.rotate_right'))}" aria-label="${this.escapeAttr(this.t('modals.rotate_right'))}">↻ 90°</button>
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-ed-op="flipH" title="${this.escapeAttr(this.t('modals.flip_h'))}" aria-label="${this.escapeAttr(this.t('modals.flip_h'))}">⇄</button>
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-ed-op="flipV" title="${this.escapeAttr(this.t('modals.flip_v'))}" aria-label="${this.escapeAttr(this.t('modals.flip_v'))}">⇅</button>
                    </div>
                    <div class="liveux-fm-row-sm liveux-fm-row-wrap">
                        <div class="liveux-fm-segmented" data-ed-ratios>
                            <button type="button" class="is-active" data-ed-ratio="free">${this.escapeHtml(this.t('modals.crop_free'))}</button>
                            <button type="button" data-ed-ratio="1:1">1:1</button>
                            <button type="button" data-ed-ratio="4:3">4:3</button>
                            <button type="button" data-ed-ratio="16:9">16:9</button>
                        </div>
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-ed-crop-reset title="${this.escapeAttr(this.t('modals.crop_reset'))}" aria-label="${this.escapeAttr(this.t('modals.crop_reset'))}">↺</button>
                    </div>
                </div>

                <div class="liveux-fm-row-between liveux-fm-row-wrap" style="gap: 8px; padding: 8px 0; border-top: 1px solid var(--liveux-fm-border); border-bottom: 1px solid var(--liveux-fm-border); margin-bottom: 6px;">
                    <div class="liveux-fm-row-sm liveux-fm-row-wrap">
                        <span class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('modals.resize_width'))}:</span>
                        <input type="number" min="16" max="8192" class="liveux-fm-input liveux-fm-mono" style="width: 68px; height: 24px; font-size: 0.72rem; padding: 0 6px;" placeholder="${autoPlaceholder}" aria-label="${this.escapeAttr(this.t('modals.resize_width'))}" data-ed-max-w>
                        <span class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('modals.resize_height'))}:</span>
                        <input type="number" min="16" max="8192" class="liveux-fm-input liveux-fm-mono" style="width: 68px; height: 24px; font-size: 0.72rem; padding: 0 6px;" placeholder="${autoPlaceholder}" aria-label="${this.escapeAttr(this.t('modals.resize_height'))}" data-ed-max-h>
                    </div>
                    <div class="liveux-fm-row-sm liveux-fm-row-wrap">
                        <span class="liveux-fm-muted liveux-fm-text-xs">${this.escapeHtml(this.t('modals.output_format'))}:</span>
                        <select class="liveux-fm-select liveux-fm-select-xs" aria-label="${this.escapeAttr(this.t('modals.output_format'))}" data-ed-format>${formatOptions}</select>
                        <span class="liveux-fm-muted liveux-fm-text-xs" data-ed-quality-wrap>${this.escapeHtml(this.t('modals.output_quality'))}:
                            <input type="range" class="liveux-fm-range" min="50" max="100" step="5" value="90" style="width: 64px; vertical-align: middle;" aria-label="${this.escapeAttr(this.t('modals.output_quality'))}" data-ed-quality>
                            <span class="liveux-fm-mono" data-ed-quality-val>90%</span>
                        </span>
                    </div>
                </div>

                <div class="liveux-fm-row-between" style="font-size: 0.7rem;">
                    <span class="liveux-fm-muted">${this.escapeHtml(this.t('modals.crop_hint'))}</span>
                    <span class="liveux-fm-muted liveux-fm-mono" dir="ltr" data-ed-dims>…</span>
                </div>
            </div>
            <div class="liveux-fm-modal-footer is-between">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                <div class="liveux-fm-row">
                    <button type="button" class="liveux-fm-btn liveux-fm-btn-outline-primary" data-ed-save="overwrite" disabled>${this.escapeHtml(this.t('modals.save_overwrite'))}</button>
                    <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" data-ed-save="copy" disabled>${this.escapeHtml(this.t('modals.save_copy'))}</button>
                </div>
            </div>
        `);

                        const editorModalHtmlSnapshot = this.modalBodyTarget?.innerHTML;
                        const canvas = this.modalBodyTarget.querySelector('[data-editor-canvas]');
                        const dimsEl = this.modalBodyTarget.querySelector('[data-ed-dims]');
                        const maxWInput = this.modalBodyTarget.querySelector('[data-ed-max-w]');
                        const maxHInput = this.modalBodyTarget.querySelector('[data-ed-max-h]');
                        const formatSelect = this.modalBodyTarget.querySelector('[data-ed-format]');
                        const qualityWrap = this.modalBodyTarget.querySelector('[data-ed-quality-wrap]');
                        const qualityInput = this.modalBodyTarget.querySelector('[data-ed-quality]');
                        const qualityVal = this.modalBodyTarget.querySelector('[data-ed-quality-val]');
                        const overwriteBtn = this.modalBodyTarget.querySelector('[data-ed-save="overwrite"]');
                        const saveCopyBtn = this.modalBodyTarget.querySelector('[data-ed-save="copy"]');
                        const saveButtons = Array.from(this.modalBodyTarget.querySelectorAll('[data-ed-save]'));

                        const workCanvas = document.createElement('canvas');
                        workCanvas.width = 0;
                        workCanvas.height = 0;
                        let imageLoaded = false;
                        let isSaving = false;
                        const img = new Image();
                        img.crossOrigin = 'anonymous';
                        this._editorImage = img;

                        const computePreviewDims = (rotW, rotH) => {
                            const dpr = Math.min(2, Math.max(1, (typeof window !== 'undefined' && Number(window.devicePixelRatio)) || 1));
                            const maxSide = Math.round(960 * dpr);
                            const longest = Math.max(rotW, rotH);
                            if (longest <= maxSide) {
                                return { pvW: rotW, pvH: rotH };
                            }
                            const scale = maxSide / longest;
                            return {
                                pvW: Math.max(1, Math.round(rotW * scale)),
                                pvH: Math.max(1, Math.round(rotH * scale)),
                            };
                        };

                        const computeOutputMetrics = () => {
                            const w = workCanvas.width || 0;
                            const h = workCanvas.height || 0;
                            if (!w || !h) {
                                return { sx: 0, sy: 0, sw: 0, sh: 0, outW: 0, outH: 0 };
                            }
                            const rect = cropRect || { x: 0, y: 0, w: 1, h: 1 };
                            const sx = Math.max(0, Math.round(rect.x * w));
                            const sy = Math.max(0, Math.round(rect.y * h));
                            const sw = Math.max(1, Math.min(w - sx, Math.round(rect.w * w)));
                            const sh = Math.max(1, Math.min(h - sy, Math.round(rect.h * h)));

                            let scale = 1;
                            if (maxW > 0 && sw > maxW) {
                                scale = Math.min(scale, maxW / sw);
                            }
                            if (maxH > 0 && sh > maxH) {
                                scale = Math.min(scale, maxH / sh);
                            }
                            const outW = Math.max(1, Math.round(sw * scale));
                            const outH = Math.max(1, Math.round(sh * scale));
                            return { sx, sy, sw, sh, outW, outH };
                        };

                        const syncFormatUi = () => {
                            const isLossless = outputMime === 'image/png';
                            if (qualityWrap) {
                                qualityWrap.style.opacity = isLossless ? '0.45' : '1';
                            }
                            if (qualityInput) {
                                qualityInput.disabled = isLossless || isSaving;
                            }
                            if (formatSelect) {
                                formatSelect.disabled = isSaving;
                            }
                            if (saveCopyBtn) {
                                saveCopyBtn.disabled = !imageLoaded || isSaving;
                            }
                            if (overwriteBtn) {
                                const canOverwriteNow = Boolean(imageLoaded && !isSaving && this.capabilitiesValue?.delete && origMime && outputMime === origMime);
                                overwriteBtn.disabled = !canOverwriteNow;
                                overwriteBtn.title = !canOverwriteNow && !isSaving
                                    ? (!this.capabilitiesValue?.delete ? this.t('errors.access_denied') : this.t('errors.overwrite_format_mismatch'))
                                    : '';
                            }
                        };
                        syncFormatUi();

                        const renderCropOverlay = () => {
                            if (!canvas || typeof canvas.getContext !== 'function' || !imageLoaded || !workCanvas.width || !workCanvas.height || canvas.isConnected === false) {
                                return;
                            }
                            const { pvW, pvH } = computePreviewDims(workCanvas.width, workCanvas.height);
                            if (canvas.width !== pvW || canvas.height !== pvH) {
                                canvas.width = pvW;
                                canvas.height = pvH;
                            }
                            const ctx = canvas.getContext('2d');
                            ctx.clearRect(0, 0, pvW, pvH);
                            ctx.drawImage(workCanvas, 0, 0, pvW, pvH);

                            if (cropRect && cropRect.w > 0.01 && cropRect.h > 0.01) {
                                const cx = cropRect.x * pvW;
                                const cy = cropRect.y * pvH;
                                const cw = cropRect.w * pvW;
                                const ch = cropRect.h * pvH;

                                ctx.save();
                                ctx.fillStyle = 'rgba(15, 23, 42, 0.55)';
                                ctx.beginPath();
                                ctx.rect(0, 0, pvW, pvH);
                                ctx.rect(cx, cy, cw, ch);
                                ctx.fill('evenodd');

                                const lineW = Math.max(2, Math.round(Math.max(pvW, pvH) / 260));
                                ctx.strokeStyle = '#ffffff';
                                ctx.lineWidth = lineW;
                                ctx.setLineDash([lineW * 4, lineW * 3]);
                                ctx.strokeRect(cx + lineW / 2, cy + lineW / 2, Math.max(1, cw - lineW), Math.max(1, ch - lineW));
                                ctx.restore();
                            }

                            const { outW, outH } = computeOutputMetrics();
                            if (dimsEl) {
                                dimsEl.textContent = `${this.formatNumber(outW)} × ${this.formatNumber(outH)} px`;
                            }
                        };

                        const scheduleOverlayRender = () => {
                            if (this._editorRenderRaf) {
                                return;
                            }
                            this._editorRenderRaf = requestAnimationFrame(() => {
                                this._editorRenderRaf = null;
                                renderCropOverlay();
                            });
                        };

                        const rebuildWorkCanvas = () => {
                            if (this.isDisconnected || !img.naturalWidth || (canvas && canvas.isConnected === false)) {
                                return;
                            }
                            const swapped = Math.abs(orientation[1]) === 1;
                            const rotW = swapped ? img.naturalHeight : img.naturalWidth;
                            const rotH = swapped ? img.naturalWidth : img.naturalHeight;

                            workCanvas.width = rotW;
                            workCanvas.height = rotH;
                            const wctx = workCanvas.getContext('2d');
                            wctx.clearRect(0, 0, rotW, rotH);
                            wctx.save();
                            wctx.translate(rotW / 2, rotH / 2);
                            if (typeof wctx.transform === 'function') {
                                wctx.transform(orientation[0], orientation[1], orientation[2], orientation[3], 0, 0);
                            }
                            wctx.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
                            wctx.restore();

                            if (!canvas) {
                                return;
                            }
                            const { pvW, pvH } = computePreviewDims(rotW, rotH);
                            canvas.width = pvW;
                            canvas.height = pvH;
                            renderCropOverlay();
                        };

                        const applyCenteredRatioCrop = (ratioStr) => {
                            if (ratioStr === 'free' || !workCanvas.width || !workCanvas.height) {
                                cropRect = null;
                                return;
                            }
                            const [rw, rh] = ratioStr.split(':').map(Number);
                            const targetRatio = rw / rh;
                            const curRatio = workCanvas.width / workCanvas.height;
                            if (curRatio > targetRatio) {
                                const wNorm = (workCanvas.height * targetRatio) / workCanvas.width;
                                cropRect = { x: (1 - wNorm) / 2, y: 0, w: wNorm, h: 1 };
                            } else {
                                const hNorm = (workCanvas.width / targetRatio) / workCanvas.height;
                                cropRect = { x: 0, y: (1 - hNorm) / 2, w: 1, h: hNorm };
                            }
                        };

                        let dragStart = null;
                        let activePointerId = null;
                        const pointToNorm = (e) => {
                            const rect = canvas.getBoundingClientRect();
                            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
                            return {
                                x: Math.min(1, Math.max(0, (clientX - rect.left) / Math.max(1, rect.width))),
                                y: Math.min(1, Math.max(0, (clientY - rect.top) / Math.max(1, rect.height))),
                            };
                        };

                        const onCropMove = (e) => {
                            if (!dragStart || !workCanvas.width || !workCanvas.height) {
                                return;
                            }
                            if (activePointerId !== null && typeof e?.pointerId === 'number' && e.pointerId !== activePointerId) {
                                return;
                            }
                            const cur = pointToNorm(e);
                            let dx = cur.x - dragStart.x;
                            let dy = cur.y - dragStart.y;

                            if (cropRatio !== 'free') {
                                const [rw, rh] = cropRatio.split(':').map(Number);
                                const targetRatio = rw / rh;
                                const signX = dx < 0 ? -1 : 1;
                                const signY = dy < 0 ? -1 : 1;
                                const maxPxW = (signX > 0 ? (1 - dragStart.x) : dragStart.x) * workCanvas.width;
                                const maxPxH = (signY > 0 ? (1 - dragStart.y) : dragStart.y) * workCanvas.height;
                                let pxW = Math.min(Math.abs(dx) * workCanvas.width, maxPxW);
                                let pxH = pxW / targetRatio;
                                if (pxH > maxPxH) {
                                    pxH = maxPxH;
                                    pxW = pxH * targetRatio;
                                }
                                dx = signX * (pxW / workCanvas.width);
                                dy = signY * (pxH / workCanvas.height);
                            }

                            const x1 = Math.min(1, Math.max(0, Math.min(dragStart.x, dragStart.x + dx)));
                            const y1 = Math.min(1, Math.max(0, Math.min(dragStart.y, dragStart.y + dy)));
                            const x2 = Math.min(1, Math.max(0, Math.max(dragStart.x, dragStart.x + dx)));
                            const y2 = Math.min(1, Math.max(0, Math.max(dragStart.y, dragStart.y + dy)));
                            cropRect = { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
                            scheduleOverlayRender();
                        };

                        const cleanupCropDrag = () => {
                            dragStart = null;
                            activePointerId = null;
                            if (this._editorRenderRaf) {
                                cancelAnimationFrame(this._editorRenderRaf);
                                this._editorRenderRaf = null;
                            }
                            document.removeEventListener('pointermove', onCropMove);
                            document.removeEventListener('pointerup', onCropEnd);
                            document.removeEventListener('pointercancel', onCropEnd);
                            window.removeEventListener('blur', onCropEnd);
                        };

                        const onCropEnd = (e) => {
                            if (!dragStart) {
                                return;
                            }
                            if (e && e.type !== 'blur' && activePointerId !== null && typeof e.pointerId === 'number' && e.pointerId !== activePointerId) {
                                return;
                            }
                            this.activeDragCleanup = null;
                            cleanupCropDrag();
                            if (cropRect && (cropRect.w < 0.02 || cropRect.h < 0.02)) {
                                cropRect = null;
                            }
                            renderCropOverlay();
                        };

                        canvas?.addEventListener('pointerdown', (e) => {
                            if (!imageLoaded || !img.naturalWidth || isSaving) {
                                return;
                            }
                            if (e.pointerType === 'mouse' && typeof e.button === 'number' && e.button !== 0) {
                                return;
                            }
                            if (activePointerId !== null) {
                                return;
                            }
                            e.preventDefault();
                            activePointerId = typeof e.pointerId === 'number' ? e.pointerId : null;
                            dragStart = pointToNorm(e);
                            this.registerDragCleanup(cleanupCropDrag);
                            document.addEventListener('pointermove', onCropMove);
                            document.addEventListener('pointerup', onCropEnd);
                            document.addEventListener('pointercancel', onCropEnd);
                            window.addEventListener('blur', onCropEnd);
                        });

                        img.onload = () => {
                            if (this.isDisconnected || (canvas && canvas.isConnected === false)) {
                                return;
                            }
                            imageLoaded = img.naturalWidth > 0 && img.naturalHeight > 0;
                            rebuildWorkCanvas();
                            syncFormatUi();
                        };
                        img.onerror = () => {
                            imageLoaded = false;
                            workCanvas.width = 0;
                            workCanvas.height = 0;
                            if (dimsEl) {
                                dimsEl.textContent = this.t('errors.preview_unsupported');
                            }
                            syncFormatUi();
                        };
                        if (editorSrcUrl) {
                            img.src = editorSrcUrl;
                        } else {
                            syncFormatUi();
                        }

                        this.modalBodyTarget.querySelectorAll('[data-ed-op]').forEach((btn) => {
                            btn.addEventListener('click', () => {
                                if (!imageLoaded) {
                                    return;
                                }
                                const [a, b, c, d] = orientation;
                                const op = btn.dataset.edOp;
                                if (op === 'rotL') {
                                    orientation = [b, -a, d, -c];
                                } else if (op === 'rotR') {
                                    orientation = [-b, a, -d, c];
                                } else if (op === 'flipH') {
                                    orientation = [-a, b, -c, d];
                                } else if (op === 'flipV') {
                                    orientation = [a, -b, c, -d];
                                }
                                cropRatio = 'free';
                                cropRect = null;
                                this.modalBodyTarget.querySelectorAll('[data-ed-ratio]').forEach((rb) => rb.classList.toggle('is-active', rb.dataset.edRatio === 'free'));
                                rebuildWorkCanvas();
                            });
                        });

                        this.modalBodyTarget.querySelectorAll('[data-ed-ratio]').forEach((btn) => {
                            btn.addEventListener('click', () => {
                                cropRatio = btn.dataset.edRatio || 'free';
                                this.modalBodyTarget.querySelectorAll('[data-ed-ratio]').forEach((b) => b.classList.toggle('is-active', b.dataset.edRatio === cropRatio));
                                applyCenteredRatioCrop(cropRatio);
                                renderCropOverlay();
                            });
                        });

                        this.modalBodyTarget.querySelector('[data-ed-crop-reset]')?.addEventListener('click', () => {
                            cropRatio = 'free';
                            cropRect = null;
                            this.modalBodyTarget.querySelectorAll('[data-ed-ratio]').forEach((b) => b.classList.toggle('is-active', b.dataset.edRatio === 'free'));
                            renderCropOverlay();
                        });

                        maxWInput?.addEventListener('input', (e) => {
                            maxW = Math.max(0, parseInt(e.target.value, 10) || 0);
                            renderCropOverlay();
                        });
                        maxHInput?.addEventListener('input', (e) => {
                            maxH = Math.max(0, parseInt(e.target.value, 10) || 0);
                            renderCropOverlay();
                        });
                        formatSelect?.addEventListener('change', (e) => {
                            outputMime = e.target.value || origMime || 'image/png';
                            syncFormatUi();
                        });
                        qualityInput?.addEventListener('input', (e) => {
                            quality = Math.min(100, Math.max(50, parseInt(e.target.value, 10) || 90));
                            if (qualityVal) {
                                qualityVal.textContent = `${quality}%`;
                            }
                        });

                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        saveButtons.forEach((btn) => {
                            btn.addEventListener('click', () => {
                                if (isSaving || btn.disabled || !imageLoaded || !workCanvas.width) {
                                    return;
                                }
                                isSaving = true;
                                syncFormatUi();
                                const saveMode = btn.dataset.edSave || 'copy';
                                const { sx, sy, sw, sh, outW, outH } = computeOutputMetrics();
                                const exportCanvas = document.createElement('canvas');
                                exportCanvas.width = outW;
                                exportCanvas.height = outH;
                                const ectx = exportCanvas.getContext('2d');
                                if (outputMime === 'image/jpeg') {
                                    ectx.fillStyle = '#ffffff';
                                    ectx.fillRect(0, 0, outW, outH);
                                }
                                ectx.drawImage(workCanvas, sx, sy, sw, sh, 0, 0, outW, outH);

                                try {
                                    exportCanvas.toBlob(async (blob) => {
                                        if (this.isDisconnected) {
                                            isSaving = false;
                                            return;
                                        }
                                        if (!blob) {
                                            isSaving = false;
                                            this.showStatus(this.t('errors.action_failed'), 'danger');
                                            syncFormatUi();
                                            return;
                                        }
                                        this.setLoading(true);
                                        const fd = new FormData();
                                        fd.append('profile', this.profileValue);
                                        fd.append('volume', sourceVolume || '');
                                        fd.append('locale', this.localeValue || '');
                                        fd.append('path', path);
                                        fd.append('saveMode', saveMode);
                                        fd.append('file', blob, name);

                                        try {
                                            const res = await fetch(this.imageEditUrlValue, {
                                                method: 'POST',
                                                headers: this.csrfHeaders(),
                                                body: fd,
                                            });
                                            if (this.isDisconnected) {
                                                return;
                                            }
                                            const data = await res.json().catch(() => ({}));
                                            if (!res.ok || !data.ok) {
                                                isSaving = false;
                                                this.showStatus(data.error || this.t('errors.action_failed'), 'danger');
                                                syncFormatUi();
                                                return;
                                            }
                                            isSaving = false;
                                            if (
                                                this.isModalOpen()
                                                && this.hasModalBodyTarget !== false
                                                && this.modalBodyTarget
                                                && (canvas ? this.modalBodyTarget.contains(canvas) : this.modalBodyTarget.innerHTML === editorModalHtmlSnapshot)
                                            ) {
                                                this.closeModal();
                                            }
                                            this.showStatus(data.message || this.t('messages.saved'), 'success');
                                            this.invalidateClientCaches();
                                            await Promise.all([
                                                this.fetchDirectory(this.volumeValue, this.pathValue),
                                                this.refreshFullTree(true),
                                            ]);
                                        } catch (_) {
                                            isSaving = false;
                                            if (!this.isDisconnected) {
                                                this.showStatus(this.t('errors.network'), 'danger');
                                                syncFormatUi();
                                            }
                                        } finally {
                                            isSaving = false;
                                            if (!this.isDisconnected) {
                                                this.setLoading(false);
                                            }
                                        }
                                    }, outputMime, quality / 100);
                                } catch (_) {
                                    isSaving = false;
                                    this.showStatus(this.t('errors.action_failed'), 'danger');
                                    syncFormatUi();
                                }
                            });
                        });
                    }

                    openNewFolderModal(parentOverride = null) {
                        const targetParent = typeof parentOverride === 'string' ? parentOverride : this.pathValue;
                        const targetLabel = targetParent ? `/${targetParent}` : `/ (${this.volumeLabelValue || this.volumeValue})`;

                        this.openModal(this.t('actions.new_folder'), `
            <form data-fm-modal-form>
                <div class="liveux-fm-modal-body">
                    <div class="liveux-fm-path-bar">
                        ${this.renderFolderSvg(15, '#2563eb')}
                        <span>${this.escapeHtml(this.t('modals.location'))}:</span>
                        <code dir="ltr" title="${this.escapeAttr(targetLabel)}">${this.escapeHtml(targetLabel)}</code>
                    </div>
                    <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.folder_name'))}</label>
                    <input type="text" class="liveux-fm-input" name="name" placeholder="${this.escapeAttr(this.t('ui.new_folder_placeholder'))}" required>
                </div>
                <div class="liveux-fm-modal-footer">
                    <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                    <button type="submit" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;">${this.escapeHtml(this.t('actions.create'))}</button>
                </div>
            </form>
        `);
                        this.bindModalForm(async (fd) => {
                            const name = String(fd.get('name') || '').trim();
                            if (name && await this.callAction('createFolder', { parentPath: targetParent, name })) {
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
                            }
                        });
                    }

                    openMoveModal(sourcePathsInput, name, isFolder) {
                        const paths = Array.isArray(sourcePathsInput) ? sourcePathsInput.filter(Boolean) : [sourcePathsInput].filter(Boolean);
                        if (paths.length === 0) {
                            return;
                        }
                        const sourceVolume = this.volumeValue;
                        const buildCandidates = (folders) => folders.filter((f) => {
                            if (!isFolder && paths.length === 1) {
                                return true;
                            }
                            return !paths.some((p) => f.path === p || f.path.startsWith(`${p}/`));
                        });
                        const needsLazyTreeFetch = !this.treeCache.has(sourceVolume) && Boolean(this.treeUrlValue);
                        let candidates = buildCandidates(this.treeCache.get(sourceVolume) || []);

                        const caps = this.capabilitiesValue;
                        let selectedTarget = '';
                        let mode = caps.move ? 'move' : 'copy';
                        let pickerSearch = '';
                        const pickerExpanded = new Set();
                        // Expand top-level folders initially for quick picking
                        candidates.filter((f) => !f.parent).forEach((f) => pickerExpanded.add(f.path));

                        const titleSuffix = paths.length > 1 ? `${this.formatNumber(paths.length)} ${this.t('inspector.selected_count')}` : name;
                        const modalVerbKey = caps.move ? 'actions.move' : 'actions.copy_verb';
                        this.openModal(`${this.t(modalVerbKey).replace('…', '')} — ${titleSuffix}`, `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-search-wrap liveux-fm-w-full" style="margin-bottom: 8px;">
                    <svg width="13" height="13" class="liveux-fm-search-icon" style="inset-inline-start: 8px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                    <input type="search" class="liveux-fm-input" style="padding-inline-start: 28px; font-size: 0.75rem; height: 28px;" placeholder="${this.escapeAttr(this.t('search.target_folder'))}" data-move-search>
                </div>

                <div class="liveux-fm-move-tree liveux-fm-tree" role="tree" aria-label="${this.escapeAttr(this.t('search.target_folder'))}" data-tree-lines="${this.treeLinesValue ? 'true' : 'false'}" data-move-tree-box></div>

                <div class="liveux-fm-path-bar" style="margin-bottom: 0;">
                    <span>${this.escapeHtml(this.t('modals.target'))}:</span>
                    <code dir="ltr" data-move-target-preview>/ (${this.escapeHtml(this.volumeLabelValue || this.volumeValue)})</code>
                </div>
            </div>
            <div class="liveux-fm-modal-footer is-between">
                <div class="liveux-fm-segmented" data-move-mode-group>
                    ${caps.move ? `<button type="button" class="${mode === 'move' ? 'is-active' : ''}" data-move-mode="move">${this.escapeHtml(this.t('actions.move_verb'))}</button>` : ''}
                    ${caps.copy ? `<button type="button" class="${mode === 'copy' ? 'is-active' : ''}" data-move-mode="copy">${this.escapeHtml(this.t('actions.copy_verb'))}</button>` : ''}
                </div>
                <div class="liveux-fm-row">
                    <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                    <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-move-submit>${this.escapeHtml(this.t('actions.apply'))}</button>
                </div>
            </div>
        `);

                        const treeBox = this.modalBodyTarget.querySelector('[data-move-tree-box]');
                        const targetPreview = this.modalBodyTarget.querySelector('[data-move-target-preview]');
                        const searchInput = this.modalBodyTarget.querySelector('[data-move-search]');

                        const renderPickerTree = () => {
                            const q = this.foldSearch(pickerSearch.trim());
                            const filtered = q
                                ? candidates.filter((f) => (
                                    (typeof f._foldedName === 'string' ? f._foldedName : this.foldSearch(f.name)).includes(q)
                                    || (typeof f._foldedPath === 'string' ? f._foldedPath : this.foldSearch(f.path)).includes(q)
                                ))
                                : candidates;

                            const byParent = new Map();
                            filtered.forEach((f) => {
                                const p = q ? '' : (f.parent || '');
                                if (!byParent.has(p)) {
                                    byParent.set(p, []);
                                }
                                byParent.get(p).push(f);
                            });

                            const collator = this.getCollator();
                            const buildUl = (parentPath) => {
                                const rawList = byParent.get(parentPath) || [];
                                if (rawList.length === 0) {
                                    return '';
                                }
                                const list = [...rawList].sort((a, b) => collator.compare(String(a.name || ''), String(b.name || '')));
                                return `<ul class="liveux-fm-tree-children" role="group">${list.map((f) => {
                                    const hasKids = (byParent.get(f.path) || []).length > 0;
                                    const open = pickerExpanded.has(f.path);
                                    const active = selectedTarget === f.path;
                                    const chevron = hasKids
                                        ? `<button type="button" class="liveux-fm-tree-chevron" aria-expanded="${open ? 'true' : 'false'}" aria-label="${this.escapeAttr(f.name)}" data-mp-toggle="${this.escapeAttr(f.path)}">${open ? '▾' : '▸'}</button>`
                                        : '<span style="width:16px;display:inline-block;flex-shrink:0;"></span>';

                                    return `<li role="none">
                        <div class="liveux-fm-tree-row ${active ? 'is-active' : ''}" role="treeitem" tabindex="0" aria-selected="${active ? 'true' : 'false'}"${hasKids ? ` aria-expanded="${open ? 'true' : 'false'}"` : ''} data-mp-pick="${this.escapeAttr(f.path)}">
                            ${chevron}
                            ${this.renderFolderSvg(15, f.color, active, f.icon || null)}
                            <span class="liveux-fm-truncate liveux-fm-grow">${this.escapeHtml(f.name)}</span>
                        </div>
                        ${hasKids && open ? buildUl(f.path) : ''}
                    </li>`;
                                }).join('')}</ul>`;
                            };

                            const rootActive = selectedTarget === '';
                            treeBox.innerHTML = `
                <div class="liveux-fm-tree-row ${rootActive ? 'is-active' : ''}" role="treeitem" tabindex="0" aria-selected="${rootActive ? 'true' : 'false'}" data-mp-pick="">
                    ${this.renderFolderSvg(15, null, rootActive)}
                    <span class="liveux-fm-truncate liveux-fm-grow liveux-fm-strong">${this.escapeHtml(this.volumeLabelValue || this.volumeValue)} (${this.escapeHtml(this.t('volumes.root_suffix'))})</span>
                </div>
                ${buildUl('')}
            `;

                            treeBox.querySelectorAll('[data-mp-toggle]').forEach((btn) => {
                                btn.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    const p = btn.dataset.mpToggle || '';
                                    if (pickerExpanded.has(p)) {
                                        pickerExpanded.delete(p);
                                    } else {
                                        pickerExpanded.add(p);
                                    }
                                    renderPickerTree();
                                    const rebuilt = treeBox.querySelectorAll('[data-mp-toggle]');
                                    for (const candidate of rebuilt) {
                                        if ((candidate.dataset?.mpToggle || '') === p) {
                                            candidate.focus?.();
                                            break;
                                        }
                                    }
                                });
                            });

                            treeBox.querySelectorAll('[data-mp-pick]').forEach((row) => {
                                const selectRow = () => {
                                    selectedTarget = row.dataset.mpPick || '';
                                    if (targetPreview) {
                                        targetPreview.textContent = selectedTarget
                                            ? `/${selectedTarget}`
                                            : `/ (${this.volumeLabelValue || this.volumeValue})`;
                                    }
                                    treeBox.querySelectorAll('[data-mp-pick]').forEach((el) => {
                                        const isSel = el === row;
                                        el.classList.toggle('is-active', isSel);
                                        el.setAttribute?.('aria-selected', isSel ? 'true' : 'false');
                                    });
                                };
                                row.addEventListener('click', selectRow);
                                row.addEventListener('keydown', (e) => {
                                    if (e.isComposing || e.keyCode === 229 || e.target?.closest?.('[data-mp-toggle]')) {
                                        return;
                                    }
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        selectRow();
                                    }
                                });
                            });
                        };

                        renderPickerTree();

                        if (needsLazyTreeFetch) {
                            if (treeBox) {
                                treeBox.insertAdjacentHTML?.('beforeend', `<div class="liveux-fm-muted liveux-fm-text-xs" style="padding: 6px 8px;" data-move-tree-loading>${this.escapeHtml(this.t('preview.loading'))}</div>`);
                            }
                            this.refreshFullTree(false, false, true).then(() => {
                                if (this.isDisconnected || !this.isModalOpen() || !treeBox || treeBox.isConnected === false) {
                                    return;
                                }
                                candidates = buildCandidates(this.treeCache.get(sourceVolume) || []);
                                candidates.filter((f) => !f.parent).forEach((f) => pickerExpanded.add(f.path));
                                renderPickerTree();
                            }).catch(() => {
                                treeBox?.querySelector?.('[data-move-tree-loading]')?.remove?.();
                            });
                        }

                        const onMoveSearch = (e) => {
                            if (e?.isComposing) {
                                return;
                            }
                            pickerSearch = e?.target?.value || '';
                            renderPickerTree();
                        };
                        searchInput?.addEventListener('input', onMoveSearch);
                        searchInput?.addEventListener('compositionend', onMoveSearch);

                        this.modalBodyTarget.querySelectorAll('[data-move-mode]').forEach((btn) => {
                            btn.addEventListener('click', () => {
                                mode = btn.dataset.moveMode;
                                this.modalBodyTarget.querySelectorAll('[data-move-mode]').forEach((b) => {
                                    b.classList.toggle('is-active', b.dataset.moveMode === mode);
                                });
                            });
                        });

                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        this.modalBodyTarget.querySelector('[data-move-submit]')?.addEventListener('click', async () => {
                            if (mode === 'move') {
                                const allAlreadyInTarget = paths.every((p) => {
                                    const parent = p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
                                    return parent === (selectedTarget || '');
                                });
                                if (allAlreadyInTarget) {
                                    this.closeModal();
                                    return;
                                }
                            }
                            this.closeModal();
                            const ok = paths.length > 1
                                ? await this.callAction('bulkMove', { paths, targetDir: selectedTarget, isCopy: mode === 'copy', volume: sourceVolume })
                                : await this.callAction('move', { sourcePath: paths[0], targetDir: selectedTarget, isCopy: mode === 'copy', volume: sourceVolume });
                            if (ok) {
                                if (mode !== 'copy') {
                                    this.pruneClipboardPaths(paths, sourceVolume);
                                }
                                this.clearSelection();
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true), this.refreshFullTree(true)]);
                                this.syncActiveTreeNode(false);
                            }
                        });
                    }

                    openRenameModal(path, currentName) {
                        const sourceVolume = this.volumeValue;
                        this.openModal(this.t('actions.rename'), `
            <form data-fm-modal-form>
                <div class="liveux-fm-modal-body">
                    <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.new_name'))}</label>
                    <input type="text" class="liveux-fm-input" name="newName" value="${this.escapeAttr(currentName)}" required>
                </div>
                <div class="liveux-fm-modal-footer">
                    <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                    <button type="submit" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;">${this.escapeHtml(this.t('actions.save'))}</button>
                </div>
            </form>
        `);
                        this.bindModalForm(async (fd) => {
                            const newName = String(fd.get('newName') || '').trim();
                            if (newName && newName !== currentName && await this.callAction('rename', { path, newName, volume: sourceVolume })) {
                                this.pruneClipboardPaths([path], sourceVolume);
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true), this.refreshFullTree(true)]);
                                this.syncActiveTreeNode(false);
                            }
                        });
                    }

                    openCloneModal(path, currentName) {
                        const hasCloneTpl = Boolean(this.i18nValue && Object.prototype.hasOwnProperty.call(this.i18nValue, 'modals.clone_default_name'));
                        const defaultCloneName = hasCloneTpl
                            ? this.t('modals.clone_default_name', { '%name%': currentName })
                            : `copy-${currentName}`;
                        this.openModal(this.t('modals.clone_title'), `
            <form data-fm-modal-form>
                <div class="liveux-fm-modal-body">
                    <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.clone_name'))}</label>
                    <input type="text" class="liveux-fm-input" name="newName" value="${this.escapeAttr(defaultCloneName)}" required>
                </div>
                <div class="liveux-fm-modal-footer">
                    <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                    <button type="submit" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;">${this.escapeHtml(this.t('actions.clone'))}</button>
                </div>
            </form>
        `);
                        this.bindModalForm(async (fd) => {
                            const newName = String(fd.get('newName') || '').trim();
                            if (newName && await this.callAction('clone', { path, newName })) {
                                this.invalidateClientCaches();
                                await Promise.all([
                                    this.fetchDirectory(this.volumeValue, this.pathValue),
                                    this.refreshFullTree(true),
                                ]);
                            }
                        });
                    }

                    openFolderColorModal(path, name, currentColor, currentIcon = '') {
                        let pickedColor = this.sanitizeHexColor(currentColor);
                        let pickedIcon = typeof currentIcon === 'string' && /^[a-zA-Z0-9_-]{1,32}$/.test(currentIcon.trim())
                            ? currentIcon.trim()
                            : '';
                        const defaultFolderColor = this.sanitizeHexColor(this.iconSetValue?.defaultFolderColor) || '#2563eb';
                        const previewColor = pickedColor || defaultFolderColor;
                        const badgesMap = this.iconSetValue?.badges && typeof this.iconSetValue.badges === 'object'
                            ? this.iconSetValue.badges
                            : {};
                        const badgeKeys = Object.keys(badgesMap);

                        const swatches = FOLDER_COLORS.map((c) => {
                            const isActive = pickedColor.toLowerCase() === c.value.toLowerCase();
                            const colorLabel = this.t(`colors.${c.key}`);
                            return `<button type="button"
                            title="${this.escapeAttr(`${colorLabel} (${c.value})`)}"
                            data-pick-color="${c.value}"
                            style="width:28px;height:28px;border-radius:999px;background:${c.value};border:2px solid ${isActive ? '#0f172a' : 'transparent'};box-shadow:${isActive ? '0 0 0 2px #ffffff inset' : '0 1px 2px rgba(0,0,0,0.12)'};cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#ffffff;font-size:0.72rem;">
                        ${isActive ? '✓' : ''}
                    </button>`;
                        }).join('');

                        const iconGridHtml = badgeKeys.length > 0
                            ? `<div class="liveux-fm-folder-icon-section">
                   <div class="liveux-fm-muted liveux-fm-text-sm" style="margin-bottom: 6px;">${this.escapeHtml(this.t('modals.folder_icon_label'))}</div>
                   <div class="liveux-fm-icon-picker-grid" data-folder-icon-grid>
                       <button type="button" class="liveux-fm-icon-pick-btn ${pickedIcon === '' ? 'is-active' : ''}" data-pick-icon="" title="${this.escapeAttr(this.t('modals.folder_icon_none'))}">—</button>
                       ${badgeKeys.map((k) => {
                                const svg = this.sanitizeInlineSvg(badgesMap[k] || '', 15);
                                if (!svg) {
                                    return '';
                                }
                                return `<button type="button" class="liveux-fm-icon-pick-btn ${pickedIcon === k ? 'is-active' : ''}" data-pick-icon="${this.escapeAttr(k)}" title="${this.escapeAttr(k)}">${svg}</button>`;
                            }).join('')}
                   </div>
               </div>`
                            : '';

                        this.openModal(this.t('actions.customize'), `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-color-preview">
                    <div data-color-preview-svg>${this.renderFolderSvg(36, previewColor, false, pickedIcon || null)}</div>
                    <div class="liveux-fm-minw-0">
                        <div class="liveux-fm-strong liveux-fm-truncate liveux-fm-text-sm">${this.escapeHtml(name)}</div>
                        <div class="liveux-fm-muted liveux-fm-mono" dir="ltr" style="font-size:0.7rem;" data-color-preview-hex>${this.escapeHtml(pickedColor || this.t('modals.color_default_label'))}</div>
                    </div>
                </div>

                <div class="liveux-fm-muted liveux-fm-text-sm" style="margin-bottom: 8px;">${this.escapeHtml(this.t('modals.color_palette'))}</div>
                <div class="liveux-fm-swatches" data-color-swatches>${swatches}</div>

                <div class="liveux-fm-row liveux-fm-row-wrap" style="padding-top: 10px; border-top: 1px solid var(--liveux-fm-border);">
                    <span class="liveux-fm-muted liveux-fm-text-sm">${this.escapeHtml(this.t('modals.color_custom'))}:</span>
                    <input type="color" class="liveux-fm-color-input" value="${this.escapeAttr(previewColor)}" data-color-native>
                    <input type="text" dir="ltr" class="liveux-fm-input liveux-fm-mono" value="${this.escapeAttr(pickedColor)}" placeholder="#2563eb" maxlength="7" data-color-hex style="width:92px;height:28px;font-size:0.74rem;">
                    <button type="button" class="liveux-fm-btn-link" style="margin-inline-start: auto; color: var(--liveux-fm-text-muted);" data-color-reset>${this.escapeHtml(this.t('actions.reset_default'))}</button>
                </div>

                ${iconGridHtml}
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-color-save>${this.escapeHtml(this.t('actions.save'))}</button>
            </div>
        `);

                        const previewSvg = this.modalBodyTarget.querySelector('[data-color-preview-svg]');
                        const previewHex = this.modalBodyTarget.querySelector('[data-color-preview-hex]');
                        const nativeInput = this.modalBodyTarget.querySelector('[data-color-native]');
                        const hexInput = this.modalBodyTarget.querySelector('[data-color-hex]');

                        const syncPreview = (val, nextIcon = pickedIcon) => {
                            pickedColor = val;
                            pickedIcon = nextIcon;
                            const effective = val || defaultFolderColor;
                            if (previewSvg) {
                                previewSvg.innerHTML = this.renderFolderSvg(36, effective, false, pickedIcon || null);
                            }
                            if (previewHex) {
                                const iconSuffix = pickedIcon ? ` • ${pickedIcon}` : '';
                                previewHex.textContent = `${val || this.t('modals.color_default_label')}${iconSuffix}`;
                            }
                            if (nativeInput && /^#[0-9a-f]{6}$/i.test(effective)) {
                                nativeInput.value = effective;
                            }
                            if (hexInput && document.activeElement !== hexInput) {
                                hexInput.value = val;
                            }
                            this.modalBodyTarget.querySelectorAll('[data-pick-color]').forEach((b) => {
                                const active = val && b.dataset.pickColor.toLowerCase() === val.toLowerCase();
                                b.style.borderColor = active ? '#0f172a' : 'transparent';
                                b.style.boxShadow = active ? '0 0 0 2px #ffffff inset' : '0 1px 2px rgba(0,0,0,0.12)';
                                b.textContent = active ? '✓' : '';
                            });
                            this.modalBodyTarget.querySelectorAll('[data-pick-icon]').forEach((b) => {
                                b.classList.toggle('is-active', (b.dataset.pickIcon || '') === pickedIcon);
                            });
                        };

                        this.modalBodyTarget.querySelectorAll('[data-pick-color]').forEach((btn) => {
                            btn.addEventListener('click', () => syncPreview(btn.dataset.pickColor || '', pickedIcon));
                        });
                        this.modalBodyTarget.querySelectorAll('[data-pick-icon]').forEach((btn) => {
                            btn.addEventListener('click', () => syncPreview(pickedColor, btn.dataset.pickIcon || ''));
                        });
                        nativeInput?.addEventListener('input', (e) => syncPreview(e.target.value, pickedIcon));
                        hexInput?.addEventListener('input', (e) => {
                            const v = e.target.value.trim();
                            if (v === '' || /^#[0-9a-f]{6}$/i.test(v)) {
                                syncPreview(v, pickedIcon);
                            }
                        });
                        this.modalBodyTarget.querySelector('[data-color-reset]')?.addEventListener('click', () => syncPreview('', ''));
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        this.modalBodyTarget.querySelector('[data-color-save]')?.addEventListener('click', async () => {
                            this.closeModal();
                            if (await this.callAction('saveMetadata', { path, color: pickedColor || null, icon: pickedIcon || null })) {
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
                            }
                        });
                    }

                    openFileMetadataModal(item) {
                        const meta = item.meta || {};
                        let focal = meta.focalPoint && Number.isFinite(Number(meta.focalPoint.x)) && Number.isFinite(Number(meta.focalPoint.y))
                            ? { x: this.clampUnit(meta.focalPoint.x), y: this.clampUnit(meta.focalPoint.y) }
                            : null;
                        const tagsStr = Array.isArray(meta.tags) ? meta.tags.join(', ') : '';
                        const rawPreview = this.resolveInlineImageUrl(item);
                        const showFocal = Boolean(item.isImage && rawPreview !== '');
                        const previewSrc = this.escapeAttr(rawPreview);

                        const focalSectionHtml = showFocal
                            ? `<div style="margin-bottom: 10px;">
                <div class="liveux-fm-row-between" style="margin-bottom: 4px;">
                    <label class="liveux-fm-label" style="margin: 0;">${this.escapeHtml(this.t('modals.focal_point'))}</label>
                    <div class="liveux-fm-row-sm">
                        <span class="liveux-fm-mono liveux-fm-muted liveux-fm-text-xs" dir="ltr" data-focal-readout>${focal ? `${this.escapeHtml(this.formatNumber(Math.round(focal.x * 100)))}% × ${this.escapeHtml(this.formatNumber(Math.round(focal.y * 100)))}%` : `${this.escapeHtml(this.formatNumber(50))}% × ${this.escapeHtml(this.formatNumber(50))}%`}</span>
                        <button type="button" class="liveux-fm-btn-link" data-focal-reset>${this.escapeHtml(this.t('modals.focal_point_reset'))}</button>
                    </div>
                </div>
                <div style="text-align: center;">
                    <div class="liveux-fm-focal-stage" data-focal-stage>
                        <img src="${previewSrc}" alt="${this.escapeAttr(item.name)}">
                        <div class="liveux-fm-focal-dot" data-focal-dot style="left: ${focal ? focal.x * 100 : 50}%; top: ${focal ? focal.y * 100 : 50}%;"></div>
                    </div>
                </div>
                <div class="liveux-fm-muted liveux-fm-text-xs" style="margin-top: 4px; text-align: center;">${this.escapeHtml(this.t('modals.focal_point_hint'))}</div>
            </div>`
                            : '';

                        const canAiEnrich = Boolean(this.capabilitiesValue?.aiEnrich);
                        const aiBtnHtml = canAiEnrich
                            ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-outline-primary" data-ai-suggest>✨ ${this.escapeHtml(this.t('actions.ai_suggest'))}</button>`
                            : '<span></span>';

                        this.openModal(`${this.t('modals.file_meta_title')} — ${item.name}`, `
            <form data-fm-file-meta-form>
                <div class="liveux-fm-modal-body">
                    ${focalSectionHtml}
                    <div style="margin-bottom: 8px;">
                        <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.meta_alt'))}</label>
                        <input type="text" class="liveux-fm-input" name="alt" maxlength="255" value="${this.escapeAttr(meta.alt || '')}" placeholder="${this.escapeAttr(this.t('modals.meta_alt_placeholder'))}">
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.meta_title'))}</label>
                        <input type="text" class="liveux-fm-input" name="title" maxlength="255" value="${this.escapeAttr(meta.title || '')}">
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.meta_caption'))}</label>
                        <textarea class="liveux-fm-textarea" name="caption" maxlength="1000">${this.escapeHtml(meta.caption || '')}</textarea>
                    </div>
                    <div>
                        <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.meta_tags'))}</label>
                        <input type="text" class="liveux-fm-input" name="tags" value="${this.escapeAttr(tagsStr)}" placeholder="${this.escapeAttr(this.t('modals.meta_tags_placeholder'))}">
                    </div>
                </div>
                <div class="liveux-fm-modal-footer is-between">
                    ${aiBtnHtml}
                    <div class="liveux-fm-row-sm">
                        <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                        <button type="submit" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;">${this.escapeHtml(this.t('actions.save'))}</button>
                    </div>
                </div>
            </form>
        `);

                        const stage = this.modalBodyTarget.querySelector('[data-focal-stage]');
                        const dot = this.modalBodyTarget.querySelector('[data-focal-dot]');
                        const readout = this.modalBodyTarget.querySelector('[data-focal-readout]');

                        const syncFocalUi = () => {
                            const fx = focal ? focal.x : 0.5;
                            const fy = focal ? focal.y : 0.5;
                            if (dot) {
                                dot.style.left = `${fx * 100}%`;
                                dot.style.top = `${fy * 100}%`;
                            }
                            if (readout) {
                                readout.textContent = `${this.formatNumber(Math.round(fx * 100))}% × ${this.formatNumber(Math.round(fy * 100))}%`;
                            }
                        };

                        stage?.addEventListener('click', (e) => {
                            const rect = stage.getBoundingClientRect();
                            const nx = Math.min(1, Math.max(0, (e.clientX - rect.left) / Math.max(1, rect.width)));
                            const ny = Math.min(1, Math.max(0, (e.clientY - rect.top) / Math.max(1, rect.height)));
                            focal = { x: Math.round(nx * 10000) / 10000, y: Math.round(ny * 10000) / 10000 };
                            syncFocalUi();
                        });

                        this.modalBodyTarget.querySelector('[data-focal-reset]')?.addEventListener('click', () => {
                            focal = null;
                            syncFocalUi();
                        });

                        const form = this.modalBodyTarget.querySelector('[data-fm-file-meta-form]');
                        const aiBtn = this.modalBodyTarget.querySelector('[data-ai-suggest]');
                        aiBtn?.addEventListener('click', async () => {
                            if (aiBtn.disabled) {
                                return;
                            }
                            const originalText = aiBtn.textContent;
                            aiBtn.disabled = true;
                            aiBtn.textContent = `⏳ ${this.t('actions.ai_generating')}`;
                            try {
                                const data = await this.callActionJson('suggestFileMetadata', { path: item.path });
                                const sug = data?.suggestion;
                                if (sug && form) {
                                    const altEl = form.querySelector('[name="alt"]');
                                    const titleEl = form.querySelector('[name="title"]');
                                    const captionEl = form.querySelector('[name="caption"]');
                                    const tagsEl = form.querySelector('[name="tags"]');
                                    if (altEl && typeof sug.alt === 'string' && sug.alt !== '') {
                                        altEl.value = sug.alt;
                                    }
                                    if (titleEl && typeof sug.title === 'string' && sug.title !== '') {
                                        titleEl.value = sug.title;
                                    }
                                    if (captionEl && typeof sug.caption === 'string' && sug.caption !== '') {
                                        captionEl.value = sug.caption;
                                    }
                                    if (tagsEl && Array.isArray(sug.tags) && sug.tags.length > 0) {
                                        tagsEl.value = sug.tags.join(', ');
                                    }
                                }
                            } finally {
                                if (aiBtn.isConnected) {
                                    aiBtn.disabled = false;
                                    aiBtn.textContent = originalText;
                                }
                            }
                        });

                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        form?.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const fd = new FormData(form);
                            const tagsRaw = String(fd.get('tags') || '');
                            const tags = tagsRaw.split(/[,，、،]+/).map((s) => s.trim()).filter(Boolean);
                            this.closeModal();

                            const ok = await this.callAction('saveFileMetadata', {
                                path: item.path,
                                alt: String(fd.get('alt') || '').trim() || null,
                                title: String(fd.get('title') || '').trim() || null,
                                caption: String(fd.get('caption') || '').trim() || null,
                                tags,
                                focalPoint: focal,
                            });
                            if (ok) {
                                this.invalidateClientCaches();
                                await Promise.all([
                                    this.fetchDirectory(this.volumeValue, this.pathValue),
                                    this.refreshFullTree(true),
                                ]);
                            }
                        });
                    }

    async extractZipArchive(path) {
                        if (!path || this.isExtractingZip) {
                            return;
                        }
                        this.isExtractingZip = true;
                        const extractBtn = this.hasInspectorTarget ? this.inspectorTarget.querySelector?.('[data-insp-extract-zip]') : null;
                        if (extractBtn) {
                            extractBtn.disabled = true;
                        }
                        try {
                            if (await this.callAction('extractZip', { path })) {
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
                            }
                        } finally {
                            this.isExtractingZip = false;
                            if (extractBtn && extractBtn.isConnected !== false) {
                                extractBtn.disabled = false;
                            }
                        }
                    }

                    openShareLinkModal(item) {
                        if (!item || !item.path) {
                            return;
                        }
                        const pathLabel = `/${item.path}`;

                        this.openModal(`${this.t('modals.share_title')} — ${item.name}`, `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-path-bar">
                    <span>🔐</span>
                    <code dir="ltr" title="${this.escapeAttr(pathLabel)}">${this.escapeHtml(pathLabel)}</code>
                </div>
                <div style="margin-bottom: 10px;">
                    <label class="liveux-fm-label">${this.escapeHtml(this.t('modals.share_duration'))}</label>
                    <select class="liveux-fm-select liveux-fm-w-full" data-share-ttl>
                        <option value="900">${this.escapeHtml(this.t('modals.share_ttl_15m'))}</option>
                        <option value="3600">${this.escapeHtml(this.t('modals.share_ttl_1h'))}</option>
                        <option value="86400" selected>${this.escapeHtml(this.t('modals.share_ttl_24h'))}</option>
                        <option value="604800">${this.escapeHtml(this.t('modals.share_ttl_7d'))}</option>
                        <option value="2592000">${this.escapeHtml(this.t('modals.share_ttl_30d'))}</option>
                    </select>
                </div>
                <label class="liveux-fm-row-sm liveux-fm-text-sm" style="margin-bottom: 12px; cursor: pointer;">
                    <input type="checkbox" class="liveux-fm-checkbox" checked data-share-inline>
                    <span>${this.escapeHtml(this.t('modals.share_inline'))}</span>
                </label>

                <div class="liveux-fm-hidden" data-share-result style="padding-top: 10px; border-top: 1px solid var(--liveux-fm-border);">
                    <div class="liveux-fm-row-sm" style="margin-bottom: 6px;">
                        <input type="text" class="liveux-fm-input liveux-fm-mono liveux-fm-grow" readonly data-share-url-input>
                        <button type="button" class="liveux-fm-btn liveux-fm-btn-primary liveux-fm-shrink-0" data-share-copy>${this.escapeHtml(this.t('modals.share_copy'))}</button>
                    </div>
                    <div class="liveux-fm-muted liveux-fm-text-xs liveux-fm-mono" data-share-expiry></div>
                </div>
                <div class="liveux-fm-muted liveux-fm-text-xs" style="margin-top: 8px;">${this.escapeHtml(this.t('modals.share_hint'))}</div>
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.close'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-share-generate>${this.escapeHtml(this.t('modals.share_generate'))}</button>
            </div>
        `);

                        const ttlSelect = this.modalBodyTarget.querySelector('[data-share-ttl]');
                        const inlineCb = this.modalBodyTarget.querySelector('[data-share-inline]');
                        const genBtn = this.modalBodyTarget.querySelector('[data-share-generate]');
                        const resultBox = this.modalBodyTarget.querySelector('[data-share-result]');
                        const urlInput = this.modalBodyTarget.querySelector('[data-share-url-input]');
                        const copyBtn = this.modalBodyTarget.querySelector('[data-share-copy]');
                        const expiryEl = this.modalBodyTarget.querySelector('[data-share-expiry]');

                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());

                        const generate = async () => {
                            if (!genBtn) {
                                return;
                            }
                            genBtn.disabled = true;
                            try {
                                const ttl = parseInt(ttlSelect?.value || '86400', 10) || 86400;
                                const inline = Boolean(inlineCb?.checked);
                                const data = await this.callActionJson('createShareLink', { path: item.path, ttl, inline });
                                if (!data || !data.ok || !data.url) {
                                    return;
                                }
                                if (urlInput) {
                                    urlInput.value = String(data.url);
                                }
                                if (expiryEl && data.expiresAt) {
                                    const formatted = this.formatDateTime(data.expiresAt, 'medium', 'short');
                                    expiryEl.textContent = `${this.t('modals.share_expires_at')}: ${formatted}`;
                                }
                                resultBox?.classList.remove('liveux-fm-hidden');
                                urlInput?.focus();
                                urlInput?.select();
                            } finally {
                                if (genBtn.isConnected) {
                                    genBtn.disabled = false;
                                }
                            }
                        };

                        genBtn?.addEventListener('click', () => {
                            generate();
                        });

                        copyBtn?.addEventListener('click', async () => {
                            const val = urlInput?.value || '';
                            if (!val) {
                                return;
                            }
                            const copied = await this.copyTextToClipboard(val);
                            if (!copied) {
                                this.showStatus(this.t('errors.action_failed'), 'danger');
                                return;
                            }
                            copyBtn.textContent = `✓ ${this.t('actions.copied')}`;
                            setTimeout(() => {
                                if (copyBtn.isConnected) {
                                    copyBtn.textContent = this.t('modals.share_copy');
                                }
                            }, 1600);
                        });
                    }

    async openDeleteModal(paths, label) {
                        const sourceVolume = this.volumeValue;
                        const executeDelete = async () => {
                            if (await this.callAction('delete', { paths, volume: sourceVolume })) {
                                this.pruneClipboardPaths(paths, sourceVolume);
                                this.clearSelection();
                                this.invalidateClientCaches();
                                if (this.trashMode) {
                                    await Promise.all([this.fetchTrash(), this.refreshFullTree(true)]);
                                } else {
                                    await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true), this.refreshFullTree(true)]);
                                    this.syncActiveTreeNode(false);
                                }
                            }
                        };

                        const usageGuardEnabled = this.capabilitiesValue?.usageGuard === true;

                        if (this.capabilitiesValue?.confirmDelete === false && !usageGuardEnabled) {
                            await executeDelete();
                            return;
                        }

                        // When confirmDelete is disabled but usageGuard is enabled, silently check first:
                        // only open the modal if active usages exist (or if check fails).
                        let preloadedUsage = null;
                        if (this.capabilitiesValue?.confirmDelete === false && usageGuardEnabled) {
                            preloadedUsage = await this.callActionJson('checkUsage', { paths, volume: sourceVolume }, { silent: true });
                            if (this.isDisconnected) {
                                return;
                            }
                            if (preloadedUsage && (preloadedUsage.totalUsages || 0) === 0 && !preloadedUsage.blocked) {
                                await executeDelete();
                                return;
                            }
                        }

                        const msg = paths.length > 1
                            ? this.t('modals.delete_multi', { count: paths.length })
                            : this.t('modals.delete_single', { name: label });

                        const usagePlaceholderHtml = usageGuardEnabled
                            ? `<div class="liveux-fm-usage-slot" data-fm-usage-slot>
                   <div class="liveux-fm-usage-loading">
                       <span class="liveux-fm-spinner liveux-fm-spinner-xs" aria-hidden="true"></span>
                       <span>${this.escapeHtml(this.t('modals.usage_checking'))}</span>
                   </div>
               </div>`
                            : '';

                        this.openModal(this.t('modals.delete_title'), `
            <div class="liveux-fm-modal-body">
                <p class="liveux-fm-text-sm" style="margin: 0;">${this.escapeHtml(msg)}</p>
                ${usagePlaceholderHtml}
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-danger" style="padding: 5px 14px;" data-fm-confirm-del${usageGuardEnabled ? ' disabled' : ''}>${this.escapeHtml(this.t('actions.delete'))}</button>
            </div>
        `);

                        const confirmBtn = this.modalBodyTarget.querySelector('[data-fm-confirm-del]');
                        const cancelBtn = this.modalBodyTarget.querySelector('[data-fm-cancel]');
                        const usageSlot = this.modalBodyTarget.querySelector('[data-fm-usage-slot]');

                        cancelBtn?.addEventListener('click', () => this.closeModal());
                        confirmBtn?.addEventListener('click', async () => {
                            if (confirmBtn.disabled) {
                                return;
                            }
                            this.closeModal();
                            await executeDelete();
                        });

                        if (!usageGuardEnabled || !usageSlot) {
                            return;
                        }

                        const report = preloadedUsage ?? await this.callActionJson('checkUsage', { paths, volume: sourceVolume }, { silent: true });
                        if (!this.modalBodyTarget.contains(usageSlot)) {
                            return;
                        }

                        this.applyUsageGuardReport(usageSlot, confirmBtn, report, paths.length > 1);
                    }

                    applyUsageGuardReport(usageSlot, confirmBtn, report, isMulti) {
                        if (!report) {
                            usageSlot.innerHTML = `<div class="liveux-fm-usage-alert is-warn">
                <div class="liveux-fm-usage-head">${this.escapeHtml(this.t('modals.usage_check_failed'))}</div>
            </div>`;
                            if (confirmBtn) {
                                confirmBtn.disabled = false;
                            }
                            return;
                        }

                        const total = Number(report.totalUsages || 0);
                        if (total <= 0) {
                            usageSlot.innerHTML = `<div class="liveux-fm-usage-clean">✓ ${this.escapeHtml(this.t('modals.usage_none'))}</div>`;
                            if (confirmBtn) {
                                confirmBtn.disabled = false;
                            }
                            return;
                        }

                        const isBlocked = report.blocked === true || report.policy === 'block';
                        usageSlot.innerHTML = this.renderUsageGuardHtml(report, isMulti, isBlocked);

                        if (!confirmBtn) {
                            return;
                        }

                        if (isBlocked) {
                            confirmBtn.disabled = true;
                            confirmBtn.hidden = true;
                        } else {
                            confirmBtn.disabled = false;
                            confirmBtn.textContent = this.t('modals.delete_anyway');
                        }
                    }

                    renderUsageGuardHtml(report, isMulti, isBlocked) {
                        const total = Number(report.totalUsages || 0);
                        const heading = isBlocked
                            ? this.t('modals.usage_block_title', { count: total })
                            : this.t('modals.usage_warn_title', { count: total });

                        const rows = [];
                        const items = Array.isArray(report.items) ? report.items : [];
                        for (const entry of items) {
                            const refs = Array.isArray(entry.references) ? entry.references : [];
                            const itemPath = typeof entry.path === 'string' ? entry.path : '';
                            for (const ref of refs) {
                                const labelText = typeof ref.label === 'string' ? ref.label : '';
                                const typeBadge = typeof ref.type === 'string' && ref.type.trim() !== ''
                                    ? `<span class="liveux-fm-usage-type">${this.escapeHtml(ref.type)}</span>`
                                    : '';
                                const pathTag = isMulti && itemPath !== ''
                                    ? `<code class="liveux-fm-usage-path" dir="ltr">${this.escapeHtml(itemPath)}</code>`
                                    : '';
                                const safeEditUrl = typeof ref.editUrl === 'string' && this.isSafeHttpOrPathUrl(ref.editUrl)
                                    ? ref.editUrl
                                    : null;
                                const linkHtml = safeEditUrl
                                    ? `<a class="liveux-fm-usage-link" href="${this.escapeAttr(safeEditUrl)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(this.t('modals.usage_open_record'))} ↗</a>`
                                    : '';

                                rows.push(`<li class="liveux-fm-usage-item">
                    <div class="liveux-fm-usage-main">
                        ${typeBadge}
                        <span class="liveux-fm-usage-label">${this.escapeHtml(labelText)}</span>
                        ${pathTag}
                    </div>
                    ${linkHtml}
                </li>`);
                            }
                        }

                        const hintHtml = isBlocked
                            ? `<p class="liveux-fm-usage-hint">${this.escapeHtml(this.t('modals.usage_block_hint'))}</p>`
                            : '';
                        const truncatedHtml = report.truncated === true
                            ? `<div class="liveux-fm-usage-more">${this.escapeHtml(this.t('modals.usage_truncated'))}</div>`
                            : '';

                        return `<div class="liveux-fm-usage-alert ${isBlocked ? 'is-block' : 'is-warn'}" role="alert">
            <div class="liveux-fm-usage-head">
                <span aria-hidden="true">${isBlocked ? '⛔' : '⚠️'}</span>
                <span>${this.escapeHtml(heading)}</span>
            </div>
            ${hintHtml}
            <ul class="liveux-fm-usage-list">${rows.join('')}</ul>
            ${truncatedHtml}
        </div>`;
                    }

                    openModalFromTemplate(title, templateEl) {
                        if (!this.hasModalBackdropTarget || !templateEl) {
                            return;
                        }
                        if (typeof document !== 'undefined' && document.activeElement && document.activeElement !== document.body) {
                            this._lastFocusedBeforeModal = document.activeElement;
                        }
                        this.modalTitleTarget.textContent = title;
                        this.modalBodyTarget.replaceChildren(templateEl.content.cloneNode(true));
                        this.modalBackdropTarget.classList.add('is-open');
                        const focusable = this.modalBodyTarget.querySelector('button:not([disabled]), input:not([type="hidden"]):not([disabled]), [tabindex="0"]');
                        focusable?.focus?.();
                    }

                    openShortcutsModal() {
                        this.closeSettingsPopover();
                        if (!this.hasShortcutsTemplateTarget) {
                            return;
                        }
                        const title = this.shortcutsTemplateTarget.dataset.title || this.t('shortcuts.title');
                        this.openModalFromTemplate(`⌨️ ${title}`, this.shortcutsTemplateTarget);
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                    }

                    openAbout() {
                        this.closeSettingsPopover();
                        if (!this.hasAboutTemplateTarget) {
                            return;
                        }
                        const title = this.aboutTemplateTarget.dataset.title || this.t('settings.about');
                        this.openModalFromTemplate(title, this.aboutTemplateTarget);
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                    }

                    bindModalForm(onSubmit) {
                        const form = this.modalBodyTarget.querySelector('[data-fm-modal-form]');
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        form?.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const fd = new FormData(form);
                            this.closeModal();
                            await onSubmit(fd);
                        });
                    }

                    invalidateClientCaches() {
                        this.dirCache.clear();
                        this.treeCache.clear();
                        this.recursiveItems = null;
                    }

    // =========================================================================
    // Trash (Lazy Loaded on Click)
    // =========================================================================
    async toggleTrash() {
                        this.dragging = null;
                        this.hideDropOverlay();
                        this.cancelRecursiveSearch();
                        if (this.isMobileSidebarOpen()) {
                            this.closeMobileSidebar();
                        }
                        if (this.trashMode) {
                            await this.exitTrashAndRestoreView();
                            return;
                        }
                        if (this.abortController) {
                            this.abortController.abort();
                            this.abortController = null;
                        }
                        this.navToken = (this.navToken || 0) + 1;
                        this.dirRequestToken = (this.dirRequestToken || 0) + 1;
                        this.navRollbackBase = null;
                        this.trashMode = true;
                        this.syncTrashUi();
                        this.clearSelection();
                        this.syncNavButtons();
                        if (this.hasPaginationBarTarget) {
                            this.paginationBarTarget.classList.add('liveux-fm-hidden');
                        }
                        await this.fetchTrash();
                    }

    async fetchTrash() {
                        if (this.trashAbortController) {
                            this.trashAbortController.abort();
                        }
                        const controller = new AbortController();
                        this.trashAbortController = controller;
                        this.setLoading(true);
                        try {
                            const params = new URLSearchParams({
                                profile: this.profileValue,
                                volume: this.volumeValue,
                                locale: this.localeValue || '',
                            });
                            const res = await fetch(`${this.trashUrlValue}?${params.toString()}`, {
                                signal: controller.signal,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            });
                            const data = await res.json().catch(() => ({}));
                            if (this.isDisconnected || !this.trashMode || controller.signal.aborted || this.trashAbortController !== controller) {
                                return;
                            }
                            if (!res.ok || !data.ok) {
                                this.showStatus(data.error || this.t('errors.load_trash'), 'danger');
                                return;
                            }
                            this.renderTrash(data.entries || []);
                            if (data.quota) {
                                this.renderQuota(data.quota);
                            }
                        } catch (err) {
                            if (err?.name !== 'AbortError' && this.trashAbortController === controller) {
                                this.showStatus(this.t('errors.network'), 'danger');
                            }
                        } finally {
                            if (this.trashAbortController === controller) {
                                this.trashAbortController = null;
                                this.setLoading(false);
                            }
                        }
                    }

                    renderTrash(entries) {
                        if (!this.hasContentAreaTarget) {
                            return;
                        }
                        if (this.hasTotalCountTarget) {
                            this.totalCountTarget.textContent = this.formatNumber(entries.length);
                        }
                        const canRestore = this.capabilitiesValue?.trashRestore !== false;
                        const canPurge = this.capabilitiesValue?.trashPurge !== false;
                        const formattedTotal = this.escapeHtml(this.formatNumber(entries.length));
                        const restoreArrow = this.isRtl() ? '↪' : '↩';

                        const header = `<div class="liveux-fm-row-between liveux-fm-row-wrap" style="margin-bottom: 12px;">
            <h6 style="margin: 0; font-size: 0.9rem; font-weight: 600; color: var(--liveux-fm-danger);">${this.escapeHtml(this.t('trash.title'))} (<span dir="ltr">${formattedTotal}</span>)</h6>
            <div class="liveux-fm-row liveux-fm-row-wrap">
                <button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-action="${CTRL}#toggleTrash">${this.isRtl() ? '→' : '←'} ${this.escapeHtml(this.t('trash.back'))}</button>
                ${entries.length > 0 && canPurge ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-danger liveux-fm-btn-xs" data-action="${CTRL}#emptyTrash">${this.escapeHtml(this.t('actions.empty_trash'))}</button>` : ''}
            </div>
        </div>`;

                        if (entries.length === 0) {
                            this.contentAreaTarget.innerHTML = `${header}<div class="liveux-fm-empty">${this.escapeHtml(this.t('trash.empty'))}</div>`;
                            return;
                        }

                        const rows = entries.map((e) => {
                            const deletedDateLabel = typeof e.deletedAtTs === 'number' && e.deletedAtTs > 0
                                ? this.formatDate(e.deletedAtTs)
                                : (typeof e.deletedAt === 'number' ? this.formatDate(e.deletedAt) : (e.deletedAt || ''));
                            return `
            <div class="liveux-fm-list-row liveux-fm-row-between liveux-fm-row-wrap">
                <div class="liveux-fm-minw-0">
                    <div class="liveux-fm-strong">${e.isFolder ? '📁' : '📄'} ${this.escapeHtml(e.name)}</div>
                    <div class="liveux-fm-muted liveux-fm-mono" style="font-size: 0.72rem;"><span dir="ltr">/${this.escapeHtml(e.originalPath)}</span> • ${this.escapeHtml(deletedDateLabel)}</div>
                </div>
                <div class="liveux-fm-row-sm">
                    ${canRestore ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-outline-success liveux-fm-btn-xs" data-trash-id="${this.escapeAttr(e.id)}" data-action="${CTRL}#restoreTrash">${restoreArrow} ${this.escapeHtml(this.t('actions.restore'))}</button>` : ''}
                    ${canPurge ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-outline-danger liveux-fm-btn-xs" data-trash-id="${this.escapeAttr(e.id)}" data-action="${CTRL}#purgeTrash">${this.escapeHtml(this.t('actions.delete_permanent'))}</button>` : ''}
                </div>
            </div>`;
                        }).join('');

                        this.contentAreaTarget.innerHTML = `${header}<div class="liveux-fm-list">${rows}</div>`;
                    }

                    syncTrashButton() {
                        if (this.hasTrashBtnTarget) {
                            this.trashBtnTarget.classList.toggle('liveux-fm-btn-danger', this.trashMode);
                        }
                        if (this.hasSidebarTrashBtnTarget) {
                            this.sidebarTrashBtnTarget.classList.toggle('is-active', this.trashMode);
                        }
                    }

    async restoreTrash(event) {
                        const btn = event.currentTarget;
                        if (btn.disabled) {
                            return;
                        }
                        const trashId = btn.dataset.trashId;
                        btn.disabled = true;
                        try {
                            if (await this.callAction('restoreTrash', { trashId })) {
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchTrash(), this.refreshFullTree(true)]);
                            }
                        } finally {
                            if (btn.isConnected) {
                                btn.disabled = false;
                            }
                        }
                    }

                    purgeTrash(event) {
                        const trashId = event.currentTarget.dataset.trashId;
                        this.openModal(this.t('trash.confirm_purge_title'), `
            <div class="liveux-fm-modal-body">
                <p class="liveux-fm-text-sm" style="margin: 0;">${this.escapeHtml(this.t('trash.confirm_purge_message'))}</p>
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-danger" style="padding: 5px 14px;" data-fm-confirm-purge>${this.escapeHtml(this.t('actions.delete_permanent'))}</button>
            </div>
        `);
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        this.modalBodyTarget.querySelector('[data-fm-confirm-purge]')?.addEventListener('click', async () => {
                            this.closeModal();
                            if (await this.callAction('purgeTrash', { trashId })) {
                                this.invalidateClientCaches();
                                await this.fetchTrash();
                            }
                        });
                    }

                    emptyTrash() {
                        this.openModal(this.t('trash.confirm_empty_title'), `
            <div class="liveux-fm-modal-body">
                <p class="liveux-fm-text-sm" style="margin: 0;">${this.escapeHtml(this.t('trash.confirm_empty_message'))}</p>
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-danger" style="padding: 5px 14px;" data-fm-confirm-empty>${this.escapeHtml(this.t('actions.empty_trash'))}</button>
            </div>
        `);
                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        this.modalBodyTarget.querySelector('[data-fm-confirm-empty]')?.addEventListener('click', async () => {
                            this.closeModal();
                            if (await this.callAction('emptyTrash')) {
                                this.invalidateClientCaches();
                                await this.fetchTrash();
                            }
                        });
                    }

    async clearCache(event) {
                        if (this.capabilitiesValue?.clearCache === false) {
                            return;
                        }
                        const scope = event.currentTarget.dataset.scope || 'dir';
                        this.closeSettingsPopover();
                        if (await this.callAction('clearCache', { scope, path: this.pathValue })) {
                            this.invalidateClientCaches();
                            if (this.trashMode) {
                                await this.refreshFullTree(true);
                            } else {
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
                            }
                        }
                    }

                    csrfHeaders(extra = {}) {
                        const headers = { 'X-Requested-With': 'XMLHttpRequest', ...extra };
                        if (this.csrfTokenValue) {
                            headers['X-LiveUX-FM-CSRF'] = this.csrfTokenValue;
                        }
                        return headers;
                    }

    async callAction(action, extra = {}, options = {}) {
                        const data = await this.callActionJson(action, extra, options);
                        return Boolean(data && data.ok);
                    }

    async callActionJson(action, extra = {}, options = {}) {
                        const silent = Boolean(options.silent);
                        this.lastActionError = null;
                        if (!silent) {
                            this.setLoading(true);
                        }
                        try {
                            const res = await fetch(this.actionUrlValue, {
                                method: 'POST',
                                headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                                body: JSON.stringify({
                                    action,
                                    profile: this.profileValue,
                                    volume: this.volumeValue,
                                    locale: this.localeValue || '',
                                    ...extra,
                                }),
                            });
                            if (this.isDisconnected) {
                                return null;
                            }
                            const data = await res.json().catch(() => ({}));
                            if (this.isDisconnected) {
                                return null;
                            }
                            if (!res.ok || !data.ok) {
                                this.lastActionError = data.error || this.t('errors.action_failed');
                                if (!silent) {
                                    this.showStatus(this.lastActionError, 'danger');
                                }
                                return null;
                            }
                            if (!silent && data.message) {
                                this.showStatus(data.message, 'success');
                            }
                            return data;
                        } catch (_) {
                            if (this.isDisconnected) {
                                return null;
                            }
                            this.lastActionError = this.t('errors.network');
                            if (!silent) {
                                this.showStatus(this.lastActionError, 'danger');
                            }
                            return null;
                        } finally {
                            if (!silent && !this.isDisconnected) {
                                this.setLoading(false);
                            }
                        }
                    }

                    // =========================================================================
                    // Universal Lightbox v2 (Image Gallery + Zoom, PDF, Video, Audio, Text/Code)
                    // =========================================================================
                    getPreviewKind(item) {
                        if (!item || item.kind === 'folder') {
                            return null;
                        }
                        if (item.isImage) {
                            return 'image';
                        }
                        const ext = String(item.extension || '').toLowerCase();
                        if (PDF_PREVIEW_EXTENSIONS.has(ext)) {
                            return this.capabilitiesValue?.allowPdfPreview === false ? null : 'pdf';
                        }
                        if (VIDEO_PREVIEW_EXTENSIONS.has(ext)) {
                            return this.capabilitiesValue?.allowVideoPreview === false ? null : 'video';
                        }
                        if (AUDIO_PREVIEW_EXTENSIONS.has(ext)) {
                            return 'audio';
                        }
                        if (TEXT_PREVIEW_EXTENSIONS.has(ext) && this.previewTextUrlValue) {
                            return 'text';
                        }
                        return null;
                    }

                    isLightboxOpen() {
                        return this.hasLightboxTarget && !this.lightboxTarget.classList.contains('liveux-fm-hidden');
                    }

                    openPreview(itemOrPath) {
                        if (!this.hasLightboxTarget) {
                            return;
                        }
                        const activeList = this.currentDisplayedItems.length > 0 ? this.currentDisplayedItems : this.getActiveItemList();
                        const targetItem = typeof itemOrPath === 'string'
                            ? activeList.find((i) => i.path === itemOrPath) || null
                            : itemOrPath;

                        if (!targetItem || this.getPreviewKind(targetItem) === null) {
                            this.showStatus(this.t('errors.preview_unsupported'), 'warning');
                            return;
                        }

                        const previewables = activeList.filter((i) => this.getPreviewKind(i) !== null);
                        const foundIdx = previewables.findIndex((i) => i.path === targetItem.path);
                        this.lightboxItems = foundIdx >= 0 ? previewables : [targetItem];
                        this.lightboxIndex = foundIdx >= 0 ? foundIdx : 0;
                        this.lightboxZoom = 1;
                        this.pauseInspectorMedia();
                        this.lightboxTarget.classList.remove('liveux-fm-hidden');
                        this.renderLightboxCurrent();
                        this.lightboxControlsTarget?.querySelector?.('button:not(.liveux-fm-hidden)')?.focus?.();
                    }

                    openLightbox(url, caption) {
                        if (!this.hasLightboxTarget || !this.isSafeUrl(url)) {
                            return;
                        }
                        this.lightboxItems = [{ kind: 'file', isImage: true, url, name: caption || '', path: '' }];
                        this.lightboxIndex = 0;
                        this.lightboxZoom = 1;
                        this.pauseInspectorMedia();
                        this.lightboxTarget.classList.remove('liveux-fm-hidden');
                        this.renderLightboxCurrent();
                        this.lightboxControlsTarget?.querySelector?.('button:not(.liveux-fm-hidden)')?.focus?.();
                    }

                    pauseInspectorMedia() {
                        if (!this.hasInspectorTarget || typeof this.inspectorTarget?.querySelectorAll !== 'function') {
                            return;
                        }
                        this.inspectorTarget.querySelectorAll('video, audio').forEach((media) => {
                            try { media.pause?.(); } catch (_) { }
                        });
                    }

                    cancelLightboxTextFetch() {
                        if (this.lightboxAbortController) {
                            try {
                                this.lightboxAbortController.abort();
                            } catch (_) { }
                            this.lightboxAbortController = null;
                        }
                    }

    async renderLightboxCurrent() {
                        this.cancelLightboxTextFetch();
                        const item = this.lightboxItems[this.lightboxIndex];
                        if (!item) {
                            return;
                        }
                        const kind = this.getPreviewKind(item) || 'image';
                        const total = this.lightboxItems.length;

                        if (this.hasLightboxCaptionTarget) {
                            this.lightboxCaptionTarget.textContent = item.name || '';
                        }
                        if (this.hasLightboxCounterTarget) {
                            this.lightboxCounterTarget.dir = 'ltr';
                            this.lightboxCounterTarget.textContent = total > 1
                                ? `${this.formatNumber(this.lightboxIndex + 1)} / ${this.formatNumber(total)}`
                                : '';
                        }
                        if (this.hasLightboxControlsTarget) {
                            this.lightboxControlsTarget.querySelectorAll('[data-lb-nav]').forEach((btn) => {
                                btn.classList.toggle('liveux-fm-hidden', total <= 1);
                            });
                            this.lightboxControlsTarget.querySelectorAll('[data-lb-zoom]').forEach((btn) => {
                                btn.classList.toggle('liveux-fm-hidden', kind !== 'image');
                            });
                            const activeEl = typeof document !== 'undefined' ? document.activeElement : null;
                            if (
                                !activeEl
                                || activeEl === document.body
                                || (typeof activeEl.classList?.contains === 'function' && activeEl.classList.contains('liveux-fm-hidden'))
                            ) {
                                const visibleBtn = this.lightboxControlsTarget.querySelector('button:not(.liveux-fm-hidden):not([disabled])');
                                visibleBtn?.focus?.({ preventScroll: true });
                            }
                        }

                        if (!this.hasLightboxStageTarget) {
                            return;
                        }

                        // Stop and detach any previously playing media
                        this.releaseMediaElements(this.lightboxStageTarget);

                        const rawImgUrl = this.resolveInlineImageUrl(item);
                        const safeUrl = rawImgUrl ? this.escapeAttr(rawImgUrl) : '';
                        const safeName = this.escapeHtml(item.name || '');
                        const safeNameAttr = this.escapeAttr(item.name || '');

                        if (kind === 'image') {
                            if (!safeUrl) {
                                this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code">${this.escapeHtml(this.t('errors.preview_unsupported'))}</div>`;
                                return;
                            }
                            this.lightboxStageTarget.innerHTML = `<img src="${safeUrl}" alt="${safeNameAttr}" class="liveux-fm-lightbox-img" style="transform: scale(${this.lightboxZoom}); transition: transform 0.15s ease;">`;
                            return;
                        }

                        if (kind === 'pdf') {
                            const pdfRawUrl = this.buildSafePdfPreviewUrl(item);
                            if (!pdfRawUrl) {
                                this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code">${this.escapeHtml(this.t('errors.preview_unsupported'))}</div>`;
                                return;
                            }
                            this.lightboxStageTarget.innerHTML = `<iframe src="${this.escapeAttr(pdfRawUrl)}" class="liveux-fm-lightbox-pdf" title="${safeNameAttr}" referrerpolicy="no-referrer"></iframe>`;
                            return;
                        }

                        if (kind === 'video') {
                            const videoRawUrl = this.isSafeHttpOrPathUrl(item.url) ? item.url : '';
                            if (!videoRawUrl) {
                                this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code">${this.escapeHtml(this.t('errors.preview_unsupported'))}</div>`;
                                return;
                            }
                            this.lightboxStageTarget.innerHTML = `<video src="${this.escapeAttr(videoRawUrl)}" class="liveux-fm-lightbox-video" controls autoplay playsinline></video>`;
                            return;
                        }

                        if (kind === 'audio') {
                            const audioRawUrl = this.isSafeHttpOrPathUrl(item.url) ? item.url : '';
                            if (!audioRawUrl) {
                                this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code">${this.escapeHtml(this.t('errors.preview_unsupported'))}</div>`;
                                return;
                            }
                            this.lightboxStageTarget.innerHTML = `
                <div class="liveux-fm-lightbox-audio">
                    <div class="liveux-fm-strong" style="margin-bottom: 10px;">🎵 ${safeName}</div>
                    <audio src="${this.escapeAttr(audioRawUrl)}" controls autoplay style="width: 100%;"></audio>
                </div>`;
                            return;
                        }

                        if (kind === 'text') {
                            this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code" style="text-align:center;">${this.escapeHtml(this.t('preview.loading'))}</div>`;
                            const controller = typeof AbortController === 'function' ? new AbortController() : null;
                            this.lightboxAbortController = controller;
                            const params = new URLSearchParams({
                                profile: this.profileValue,
                                volume: this.volumeValue || '',
                                path: item.path || '',
                                locale: this.localeValue || '',
                            });
                            try {
                                const res = await fetch(`${this.previewTextUrlValue}?${params.toString()}`, {
                                    signal: controller?.signal,
                                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                });
                                const data = await res.json().catch(() => ({}));
                                if (
                                    controller?.signal?.aborted
                                    || this.lightboxAbortController !== controller
                                    || !this.isLightboxOpen()
                                    || this.lightboxItems[this.lightboxIndex]?.path !== item.path
                                ) {
                                    return;
                                }
                                if (!res.ok || !data.ok) {
                                    this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code">${this.escapeHtml(data.error || this.t('errors.preview_unsupported'))}</div>`;
                                    return;
                                }
                                const pre = document.createElement('pre');
                                pre.className = 'liveux-fm-lightbox-code';
                                pre.dir = 'ltr';
                                pre.textContent = String(data.content || '');
                                this.lightboxStageTarget.innerHTML = '';
                                this.lightboxStageTarget.appendChild(pre);
                                if (data.truncated) {
                                    const note = document.createElement('div');
                                    note.className = 'liveux-fm-muted liveux-fm-text-xs';
                                    note.style.marginTop = '6px';
                                    note.textContent = this.t('preview.truncated');
                                    this.lightboxStageTarget.appendChild(note);
                                }
                            } catch (err) {
                                if (err?.name === 'AbortError' || controller?.signal?.aborted || this.lightboxAbortController !== controller) {
                                    return;
                                }
                                if (this.isLightboxOpen()) {
                                    this.lightboxStageTarget.innerHTML = `<div class="liveux-fm-lightbox-code">${this.escapeHtml(this.t('errors.network'))}</div>`;
                                }
                            } finally {
                                if (this.lightboxAbortController === controller) {
                                    this.lightboxAbortController = null;
                                }
                            }
                        }
                    }

                    lightboxPrev() {
                        if (this.lightboxItems.length <= 1) {
                            return;
                        }
                        this.lightboxIndex = (this.lightboxIndex - 1 + this.lightboxItems.length) % this.lightboxItems.length;
                        this.lightboxZoom = 1;
                        this.renderLightboxCurrent();
                    }

                    lightboxNext() {
                        if (this.lightboxItems.length <= 1) {
                            return;
                        }
                        this.lightboxIndex = (this.lightboxIndex + 1) % this.lightboxItems.length;
                        this.lightboxZoom = 1;
                        this.renderLightboxCurrent();
                    }

                    lightboxZoomIn() {
                        this.lightboxZoom = Math.min(3, Math.round((this.lightboxZoom + 0.25) * 100) / 100);
                        this.applyLightboxZoom();
                    }

                    lightboxZoomOut() {
                        this.lightboxZoom = Math.max(0.5, Math.round((this.lightboxZoom - 0.25) * 100) / 100);
                        this.applyLightboxZoom();
                    }

                    lightboxZoomReset() {
                        this.lightboxZoom = 1;
                        this.applyLightboxZoom();
                    }

                    applyLightboxZoom() {
                        const img = this.hasLightboxStageTarget ? this.lightboxStageTarget.querySelector('.liveux-fm-lightbox-img') : null;
                        if (img) {
                            img.style.transform = `scale(${this.lightboxZoom})`;
                        }
                    }

                    closeLightbox() {
                        this.cancelLightboxTextFetch();
                        const wasOpen = this.isLightboxOpen();
                        if (this.hasLightboxStageTarget) {
                            this.releaseMediaElements(this.lightboxStageTarget);
                            this.lightboxStageTarget.innerHTML = '';
                        }
                        if (this.hasLightboxTarget) {
                            this.lightboxTarget.classList.add('liveux-fm-hidden');
                        }
                        if (wasOpen) {
                            this.restoreRootFocus();
                        }
                    }

                    restoreRootFocus() {
                        if (!this.element || typeof this.element.focus !== 'function' || typeof document === 'undefined') {
                            return;
                        }
                        if (this.isModalOpen() || this.isLightboxOpen()) {
                            return;
                        }
                        const active = document.activeElement;
                        const inClosedModal = Boolean(active && this.hasModalBackdropTarget && this.modalBackdropTarget?.contains?.(active));
                        const inClosedLightbox = Boolean(active && this.hasLightboxTarget && this.lightboxTarget?.contains?.(active));
                        if (!inClosedModal && !inClosedLightbox && active && this.element.contains?.(active)) {
                            const tag = (active.tagName || '').toUpperCase();
                            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || active.isContentEditable) {
                                return;
                            }
                        }
                        this.element.focus({ preventScroll: true });
                    }

                    onRootPointerDown(event) {
                        const tag = (event.target?.tagName || '').toUpperCase();
                        if (['INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'BUTTON', 'A'].includes(tag)) {
                            return;
                        }
                        if (this.element && typeof this.element.focus === 'function' && !this.element.contains(document.activeElement)) {
                            this.element.focus({ preventScroll: true });
                        }
                    }

                    isFileManagerActive() {
                        if (this.isFullscreen === true || this.isLightboxOpen() || this.isModalOpen() || this.isContextMenuOpen()) {
                            return true;
                        }
                        const dialog = this.element?.closest?.('dialog');
                        if (dialog) {
                            return dialog.open === true;
                        }
                        return Boolean(this.element && typeof document !== 'undefined' && this.element.contains(document.activeElement));
                    }

                    isModalOpen() {
                        return Boolean(this.hasModalBackdropTarget && this.modalBackdropTarget.classList.contains('is-open'));
                    }

                    isContextMenuOpen() {
                        return Boolean(this.hasContextMenuTarget && this.contextMenuTarget.classList.contains('is-open'));
                    }

                    isSettingsPopoverOpen() {
                        return Boolean(this.hasSettingsPopoverTarget && this.settingsPopoverTarget.classList.contains('is-open'));
                    }

                    toggleViewMode() {
                        const nextView = this.viewModeValue === 'list' ? 'grid' : 'list';
                        this.viewModeValue = nextView;
                        if (this.hasViewButtonsTarget) {
                            this.viewButtonsTarget.querySelectorAll('button').forEach((btn) => {
                                btn.classList.toggle('is-active', btn.dataset.view === nextView);
                            });
                        }
                        if (!this.trashMode) {
                            this.applyClientView();
                        }
                    }

                    getSingleSelectedItem() {
                        if (this.selectedMap.size !== 1) {
                            return null;
                        }
                        const firstPath = Array.from(this.selectedMap.keys())[0];
                        return this.getActiveItemList().find((item) => item.path === firstPath) || null;
                    }

                    navigateSelectionByArrow(arrowKey) {
                        if (this.trashMode || !Array.isArray(this.renderedPaths) || this.renderedPaths.length === 0) {
                            return;
                        }
                        const paths = this.renderedPaths;
                        const currentPath = this.lastSelectedPath || Array.from(this.selectedMap.keys())[0] || null;
                        let idx = currentPath ? paths.indexOf(currentPath) : -1;
                        const activeViewMode = this.viewMode || this.viewModeValue || 'grid';
                        const effectiveArrow = (this.isRtl() && activeViewMode === 'grid')
                            ? (arrowKey === 'ArrowLeft' ? 'ArrowRight' : (arrowKey === 'ArrowRight' ? 'ArrowLeft' : arrowKey))
                            : arrowKey;

                        if (idx < 0) {
                            idx = (effectiveArrow === 'ArrowLeft' || effectiveArrow === 'ArrowUp') ? paths.length - 1 : 0;
                        } else if (effectiveArrow === 'ArrowRight') {
                            idx = Math.min(paths.length - 1, idx + 1);
                        } else if (effectiveArrow === 'ArrowLeft') {
                            idx = Math.max(0, idx - 1);
                        } else if (effectiveArrow === 'ArrowDown' || effectiveArrow === 'ArrowUp') {
                            let resolvedByGeometry = false;
                            if (this.viewModeValue !== 'list' && this.hasContentAreaTarget) {
                                const cards = Array.from(this.contentAreaTarget.querySelectorAll('[data-fm-file-card], [data-fm-folder-card]'));
                                const measured = cards.map((el, i) => {
                                    const rect = typeof el.getBoundingClientRect === 'function' ? el.getBoundingClientRect() : null;
                                    return rect ? {
                                        el,
                                        path: el.dataset?.path ?? paths[i],
                                        rect,
                                        centerX: (rect.left + rect.right) / 2,
                                        centerY: (rect.top + rect.bottom) / 2,
                                    } : null;
                                }).filter(Boolean);

                                const hasGeometry = measured.length > 1
                                    && measured.some((m) => m.rect.width > 0 || m.rect.height > 0 || m.rect.top !== 0 || m.rect.left !== 0);

                                if (hasGeometry) {
                                    const curEntry = measured.find((m) => m.path === paths[idx]) || measured[idx];
                                    if (curEntry) {
                                        resolvedByGeometry = true;
                                        const rowTol = Math.max(6, (curEntry.rect.height || 24) * 0.35);
                                        const candidates = arrowKey === 'ArrowDown'
                                            ? measured.filter((m) => m.centerY > curEntry.centerY + rowTol)
                                            : measured.filter((m) => m.centerY < curEntry.centerY - rowTol);

                                        if (candidates.length > 0) {
                                            let nearestRowCard = candidates[0];
                                            for (let c = 1; c < candidates.length; c += 1) {
                                                if (arrowKey === 'ArrowDown' && candidates[c].centerY < nearestRowCard.centerY) {
                                                    nearestRowCard = candidates[c];
                                                } else if (arrowKey === 'ArrowUp' && candidates[c].centerY > nearestRowCard.centerY) {
                                                    nearestRowCard = candidates[c];
                                                }
                                            }
                                            const targetRowTol = Math.max(6, (nearestRowCard.rect.height || 24) * 0.35);
                                            const rowCards = candidates.filter((m) => Math.abs(m.centerY - nearestRowCard.centerY) <= targetRowTol);
                                            let best = rowCards[0];
                                            let bestDist = Math.abs(best.centerX - curEntry.centerX);
                                            for (let r = 1; r < rowCards.length; r += 1) {
                                                const dist = Math.abs(rowCards[r].centerX - curEntry.centerX);
                                                if (dist < bestDist) {
                                                    best = rowCards[r];
                                                    bestDist = dist;
                                                }
                                            }
                                            const nextIdx = paths.indexOf(best.path);
                                            if (nextIdx >= 0) {
                                                idx = nextIdx;
                                            }
                                        }
                                    }
                                }
                            }

                            if (!resolvedByGeometry) {
                                let step = 1;
                                if (this.viewModeValue !== 'list' && this.hasContentAreaTarget) {
                                    const cards = Array.from(this.contentAreaTarget.querySelectorAll('[data-fm-file-card], [data-fm-folder-card]'));
                                    const currentEl = cards[idx];
                                    if (currentEl) {
                                        const container = currentEl.parentElement;
                                        const siblings = container ? Array.from(container.children) : [];
                                        const hasOffsetLayout = siblings.some((el) => typeof el.offsetTop === 'number' && el.offsetTop !== siblings[0]?.offsetTop);
                                        if (hasOffsetLayout) {
                                            const firstTop = siblings[0]?.offsetTop;
                                            const rowCount = siblings.filter((el) => el.offsetTop === firstTop).length;
                                            if (rowCount > 1) {
                                                step = rowCount;
                                            }
                                        }
                                    }
                                }
                                idx = arrowKey === 'ArrowDown'
                                    ? Math.min(paths.length - 1, idx + step)
                                    : Math.max(0, idx - step);
                            }
                        }

                        const nextPath = paths[idx];
                        const nextItem = this.getActiveItemList().find((item) => item.path === nextPath);
                        if (!nextItem) {
                            return;
                        }
                        this.selectedMap.clear();
                        if (nextItem.kind === 'folder') {
                            this.selectedMap.set(nextPath, { kind: 'folder', path: nextPath, name: nextItem.name, volume: this.volumeValue });
                        } else {
                            this.selectedMap.set(nextPath, { kind: 'file', path: nextPath, url: nextItem.url || '', name: nextItem.name });
                        }
                        this.lastSelectedPath = nextPath;
                        this.syncSelectionUi();

                        const targetEl = this.hasContentAreaTarget
                            ? Array.from(this.contentAreaTarget.querySelectorAll('[data-fm-file-card], [data-fm-folder-card]')).find((el) => el.dataset.path === nextPath)
                            : null;
                        targetEl?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
                    }

                    trapFocusInOverlay(event, container) {
                        if (!container || typeof container.querySelectorAll !== 'function' || typeof document === 'undefined') {
                            return false;
                        }
                        const selector = 'button:not([disabled]):not(.liveux-fm-hidden), a[href]:not(.liveux-fm-hidden), input:not([type="hidden"]):not([disabled]):not(.liveux-fm-hidden), select:not([disabled]):not(.liveux-fm-hidden), textarea:not([disabled]):not(.liveux-fm-hidden), [tabindex]:not([tabindex="-1"]):not(.liveux-fm-hidden)';
                        const focusables = Array.from(container.querySelectorAll(selector)).filter((el) => {
                            if (el.closest?.('.liveux-fm-hidden')) {
                                return false;
                            }
                            return true;
                        });
                        if (focusables.length === 0) {
                            return false;
                        }
                        const first = focusables[0];
                        const last = focusables[focusables.length - 1];
                        const active = document.activeElement;
                        if (event.shiftKey) {
                            if (!container.contains?.(active) || active === first) {
                                event.preventDefault();
                                last.focus?.({ preventScroll: true });
                                return true;
                            }
                        } else if (!container.contains?.(active) || active === last) {
                            event.preventDefault();
                            first.focus?.({ preventScroll: true });
                            return true;
                        }
                        return false;
                    }

                    onKeydown(event) {
                        if (event.isComposing || event.keyCode === 229) {
                            return;
                        }
                        if (!this.isFileManagerActive()) {
                            return;
                        }
                        if (event.key === 'Tab') {
                            if (this.isModalOpen() && this.hasModalBackdropTarget) {
                                const modalContainer = this.modalBackdropTarget.querySelector?.('.liveux-fm-modal-card') || this.modalBackdropTarget;
                                this.trapFocusInOverlay(event, modalContainer);
                                return;
                            }
                            if (this.isLightboxOpen() && this.hasLightboxTarget) {
                                this.trapFocusInOverlay(event, this.lightboxTarget);
                                return;
                            }
                            if (this.isContextMenuOpen()) {
                                this.hideContextMenu(false);
                                return;
                            }
                        }
                        if (event.key === 'Escape') {
                            const hadCtxMenu = this.isContextMenuOpen();
                            const hadInnerOverlay = hadCtxMenu || this.isLightboxOpen() || this.isModalOpen() || this.isSettingsPopoverOpen() || this.isMobileSidebarOpen();
                            if (hadInnerOverlay) {
                                event.preventDefault();
                                this.hideContextMenu(hadCtxMenu);
                                this.closeLightbox();
                                this.closeModal();
                                if (this.isMobileSidebarOpen()) {
                                    this.closeMobileSidebar();
                                }
                                this.closeSettingsPopover();
                                return;
                            }
                            if (!this.keyboardShortcutsValue) {
                                return;
                            }
                            if (this.selectedMap.size > 0) {
                                event.preventDefault();
                                this.clearSelection();
                                return;
                            }
                            if (this.clipboard && this.clipboard.paths.length > 0) {
                                event.preventDefault();
                                this.clearClipboard();
                                return;
                            }
                            if (this.isFullscreen && !this.element.closest('dialog')) {
                                event.preventDefault();
                                this.toggleFullscreen();
                            }
                            return;
                        }

                        if (this.isContextMenuOpen() && this.hasContextMenuTarget) {
                            const menuItems = Array.from(this.contextMenuTarget.querySelectorAll('button:not([disabled]), a[href]'));
                            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
                                event.preventDefault();
                                if (menuItems.length > 0) {
                                    const curIdx = menuItems.indexOf(document.activeElement);
                                    let nextIdx = 0;
                                    if (event.key === 'Home') {
                                        nextIdx = 0;
                                    } else if (event.key === 'End') {
                                        nextIdx = menuItems.length - 1;
                                    } else if (event.key === 'ArrowDown') {
                                        nextIdx = curIdx >= 0 ? (curIdx + 1) % menuItems.length : 0;
                                    } else {
                                        nextIdx = curIdx >= 0 ? (curIdx - 1 + menuItems.length) % menuItems.length : menuItems.length - 1;
                                    }
                                    menuItems[nextIdx]?.focus?.();
                                }
                                return;
                            }
                            // Never let background file-grid shortcuts (Delete, Enter, arrows, letters) fire while context menu is open.
                            return;
                        }

                        if (!this.keyboardShortcutsValue) {
                            return;
                        }

                        if (this.isLightboxOpen()) {
                            if (event.key === 'ArrowLeft') {
                                event.preventDefault();
                                if (this.isRtl()) {
                                    this.lightboxNext();
                                } else {
                                    this.lightboxPrev();
                                }
                            } else if (event.key === 'ArrowRight') {
                                event.preventDefault();
                                if (this.isRtl()) {
                                    this.lightboxPrev();
                                } else {
                                    this.lightboxNext();
                                }
                            } else if (event.key === '+' || event.key === '=' || event.code === 'Equal' || event.code === 'NumpadAdd') {
                                event.preventDefault();
                                this.lightboxZoomIn();
                            } else if (event.key === '-' || event.code === 'Minus' || event.code === 'NumpadSubtract') {
                                event.preventDefault();
                                this.lightboxZoomOut();
                            } else if (event.key === '0' || event.code === 'Digit0' || event.code === 'Numpad0') {
                                event.preventDefault();
                                this.lightboxZoomReset();
                            }
                            return;
                        }

                        if (this.isModalOpen()) {
                            return;
                        }

                        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                            // Let native buttons/links/summary handle Enter/Space themselves.
                            if (event.target?.closest?.('button, a, summary, [role="button"]')) {
                                return;
                            }
                        }

                        const activeEl = document.activeElement;
                        const tag = (activeEl?.tagName || '').toUpperCase();
                        const isEditable = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || Boolean(activeEl?.isContentEditable);
                        if (event.getModifierState?.('AltGraph') || (event.ctrlKey && event.altKey)) {
                            return;
                        }
                        const mod = event.ctrlKey || event.metaKey;
                        const rawKey = String(event.key || '');
                        const codeMatch = /^Key([A-Z])$/.exec(String(event.code || ''));
                        const keyLower = (rawKey === 'ı' || rawKey === 'İ')
                            ? 'i'
                            : (/^[a-z]$/i.test(rawKey)
                                ? rawKey.toLowerCase()
                                : (codeMatch ? codeMatch[1].toLowerCase() : rawKey.toLowerCase()));
                        const caps = this.capabilitiesValue || {};

                        if (mod && !event.shiftKey && keyLower === 'f') {
                            if (caps.showSearch !== false && this.hasSearchInputTarget) {
                                event.preventDefault();
                                this.searchInputTarget.focus();
                                this.searchInputTarget.select?.();
                            }
                            return;
                        }
                        if (isEditable) {
                            return;
                        }
                        if (mod && !event.shiftKey && keyLower === 'a' && !this.trashMode) {
                            event.preventDefault();
                            this.selectAllVisible();
                            return;
                        }
                        if (mod && !event.shiftKey && keyLower === 'c' && caps.copy && this.selectedMap.size > 0 && !String(window.getSelection?.() || '')) {
                            event.preventDefault();
                            this.copyToClipboard('copy');
                            return;
                        }
                        if (mod && !event.shiftKey && keyLower === 'x' && caps.move && this.selectedMap.size > 0 && !String(window.getSelection?.() || '')) {
                            event.preventDefault();
                            this.copyToClipboard('move');
                            return;
                        }

                        if (event.altKey && event.key === 'ArrowLeft') {
                            event.preventDefault();
                            if (this.isRtl()) {
                                this.goForward();
                            } else {
                                this.goBack();
                            }
                            return;
                        }
                        if (event.altKey && event.key === 'ArrowRight') {
                            event.preventDefault();
                            if (this.isRtl()) {
                                this.goBack();
                            } else {
                                this.goForward();
                            }
                            return;
                        }
                        if (event.altKey && event.key === 'ArrowUp') {
                            event.preventDefault();
                            this.goUp();
                            return;
                        }
                        if (mod || event.altKey) {
                            return;
                        }

                        if (event.key === '?' || event.key === '؟') {
                            event.preventDefault();
                            this.openShortcutsModal();
                        } else if (event.key === '/' && caps.showSearch !== false && this.hasSearchInputTarget) {
                            event.preventDefault();
                            this.searchInputTarget.focus();
                            this.searchInputTarget.select?.();
                        } else if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
                            event.preventDefault();
                            this.navigateSelectionByArrow(event.key);
                        } else if (keyLower === 'n' && caps.createFolder && !this.trashMode) {
                            event.preventDefault();
                            this.openNewFolderModal(this.pathValue);
                        } else if (keyLower === 'u' && caps.upload && !this.trashMode) {
                            event.preventDefault();
                            this.openUploadModal(this.pathValue);
                        } else if (keyLower === 'r' && caps.showRefreshButton !== false) {
                            event.preventDefault();
                            this.refreshCurrent();
                        } else if (keyLower === 'v' && caps.showViewToggle !== false && !this.trashMode) {
                            event.preventDefault();
                            this.toggleViewMode();
                        } else if (keyLower === 'b' && caps.showFolderTree !== false) {
                            event.preventDefault();
                            this.toggleSidebar();
                        } else if (keyLower === 't' && caps.trashEnabled) {
                            event.preventDefault();
                            this.toggleTrash();
                        } else if (keyLower === 'f' && caps.showFullscreenButton !== false) {
                            event.preventDefault();
                            this.toggleFullscreen();
                        } else if (keyLower === 'm' && this.selectedMap.size > 0 && (caps.move || caps.copy) && !this.trashMode) {
                            event.preventDefault();
                            if (this.selectedMap.size === 1) {
                                const first = Array.from(this.selectedMap.values())[0];
                                if (first?.path) {
                                    this.openMoveModal(first.path, first.name, first.kind === 'folder');
                                }
                            } else {
                                this.bulkMove();
                            }
                        } else if (keyLower === 'd' && this.selectedMap.size === 1 && caps.clone && !this.trashMode) {
                            const item = this.getSingleSelectedItem();
                            if (item && item.kind !== 'folder') {
                                event.preventDefault();
                                this.openCloneModal(item.path, item.name);
                            }
                        } else if (keyLower === 'i' && this.selectedMap.size === 1 && caps.editMetadata && !this.trashMode) {
                            const item = this.getSingleSelectedItem();
                            if (item && item.kind === 'folder') {
                                event.preventDefault();
                                this.openFolderColorModal(item.path, item.name, item.color || '', item.icon || '');
                            } else if (item) {
                                event.preventDefault();
                                this.openFileMetadataModal(item);
                            }
                        } else if (keyLower === 'e' && this.selectedMap.size === 1 && caps.editImage && this.imageEditUrlValue && !this.trashMode) {
                            const item = this.getSingleSelectedItem();
                            if (item && item.kind !== 'folder' && item.isImage && this.isEditableRasterExtension(item.extension)) {
                                event.preventDefault();
                                this.openImageEditorModal(item.path, item.name, item.url, item.downloadUrl);
                            }
                        } else if (keyLower === 'l' && this.selectedMap.size === 1 && caps.download !== false && !this.trashMode) {
                            const item = this.getSingleSelectedItem();
                            if (item && item.kind !== 'folder' && item.url && this.isSafeUrl(item.url)) {
                                event.preventDefault();
                                this.copyTextToClipboard(item.url).then((copied) => {
                                    if (copied) {
                                        this.showStatus(this.t('actions.copied'), 'success');
                                    }
                                });
                            }
                        } else if (keyLower === 's' && this.selectedMap.size === 1 && caps.share && !this.trashMode) {
                            const item = this.getSingleSelectedItem();
                            if (item && item.kind !== 'folder') {
                                event.preventDefault();
                                this.openShareLinkModal(item);
                            }
                        } else if (event.key === 'Backspace' && this.canGoUp()) {
                            event.preventDefault();
                            this.goUp();
                        } else if (event.key === 'F2' && this.selectedMap.size === 1 && caps.rename && !this.trashMode) {
                            const first = Array.from(this.selectedMap.values())[0];
                            if (first?.path) {
                                event.preventDefault();
                                this.openRenameModal(first.path, first.name);
                            }
                        } else if (event.key === 'Enter' && this.selectedMap.size > 0 && !this.trashMode) {
                            if (this.selectedMap.size === 1) {
                                const first = Array.from(this.selectedMap.values())[0];
                                if (first?.kind === 'folder') {
                                    event.preventDefault();
                                    this.navigateTo(first.path, first.volume || this.volumeValue);
                                    return;
                                }
                                if (!this.element.closest('dialog') && first?.path) {
                                    event.preventDefault();
                                    this.openPreview(first.path);
                                    return;
                                }
                            }
                            if (this.element.closest('dialog')) {
                                event.preventDefault();
                                this.confirmSelection();
                            }
                        } else if (event.key === 'Delete' && this.selectedMap.size > 0 && caps.delete && !this.trashMode) {
                            event.preventDefault();
                            this.bulkDelete();
                        } else if (event.key === ' ' && this.selectedMap.size === 1 && !this.trashMode) {
                            const first = Array.from(this.selectedMap.values())[0];
                            if (first?.path && first.kind !== 'folder') {
                                event.preventDefault();
                                this.openPreview(first.path);
                            }
                        }
                    }

                    onPaste(event) {
                        if (!this.isFileManagerActive() || this.trashMode || this.isLightboxOpen()) {
                            return;
                        }
                        const activeEl = document.activeElement;
                        const tag = (activeEl?.tagName || '').toUpperCase();
                        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || Boolean(activeEl?.isContentEditable)) {
                            return;
                        }
                        const isUploadModalOpen = Boolean(this.isModalOpen() && this.hasModalBodyTarget && this.modalBodyTarget.querySelector('[data-upload-dropzone]'));
                        if (this.isModalOpen() && !isUploadModalOpen) {
                            return;
                        }
                        const files = Array.from(event.clipboardData?.files || []);
                        if (files.length > 0 && this.capabilitiesValue?.upload) {
                            event.preventDefault();
                            const targetDir = isUploadModalOpen && typeof this.uploadTargetDir === 'string'
                                ? this.uploadTargetDir
                                : this.pathValue;
                            this.enqueueFiles(files, targetDir, this.volumeValue);
                            return;
                        }
                        if (!isUploadModalOpen && this.clipboard && this.clipboard.paths.length > 0) {
                            event.preventDefault();
                            this.pasteClipboard();
                        }
                    }

                    selectRangeTo(targetPath) {
                        if (this.isSinglePickerMode()) {
                            return false;
                        }
                        const fromIdx = this.renderedPaths.indexOf(this.lastSelectedPath);
                        const toIdx = this.renderedPaths.indexOf(targetPath);
                        if (fromIdx < 0 || toIdx < 0) {
                            return false;
                        }
                        const start = Math.min(fromIdx, toIdx);
                        const end = Math.max(fromIdx, toIdx);
                        const itemsByPath = new Map(this.getActiveItemList().map((i) => [i.path, i]));
                        this.selectedMap.clear();
                        for (let idx = start; idx <= end; idx += 1) {
                            const p = this.renderedPaths[idx];
                            const item = itemsByPath.get(p);
                            if (!item) {
                                continue;
                            }
                            if (item.kind === 'folder') {
                                this.selectedMap.set(p, { kind: 'folder', path: p, name: item.name, volume: this.volumeValue });
                            } else {
                                this.selectedMap.set(p, { kind: 'file', path: p, url: item.url || '', name: item.name });
                            }
                        }
                        return true;
                    }

                    selectAllVisible() {
                        if (this.isSinglePickerMode()) {
                            return;
                        }
                        const itemsByPath = new Map(this.getActiveItemList().map((i) => [i.path, i]));
                        this.selectedMap.clear();
                        this.renderedPaths.forEach((p) => {
                            const item = itemsByPath.get(p);
                            if (!item) {
                                return;
                            }
                            if (item.kind === 'folder') {
                                this.selectedMap.set(p, { kind: 'folder', path: p, name: item.name, volume: this.volumeValue });
                            } else {
                                this.selectedMap.set(p, { kind: 'file', path: p, url: item.url || '', name: item.name });
                            }
                        });
                        this.syncSelectionUi();
                    }

                    copyToClipboard(mode, explicitPaths = null) {
                        const caps = this.capabilitiesValue;
                        if (mode === 'copy' && !caps.copy) {
                            return;
                        }
                        if (mode === 'move' && !caps.move) {
                            return;
                        }
                        const paths = Array.isArray(explicitPaths) ? explicitPaths.filter(Boolean) : Array.from(this.selectedMap.keys());
                        if (paths.length === 0) {
                            return;
                        }
                        this.clipboard = { mode, volume: this.volumeValue, paths };
                        this.showStatus(this.t(mode === 'copy' ? 'messages.clipboard_copied' : 'messages.clipboard_cut', { count: paths.length }), 'info');
                        this.syncSelectionUi();
                    }

                    clearClipboard() {
                        this.clipboard = null;
                        this.syncSelectionUi();
                    }

                    pruneClipboardPaths(affectedPaths, volume = this.volumeValue) {
                        if (!this.clipboard || !Array.isArray(this.clipboard.paths) || this.clipboard.paths.length === 0) {
                            return;
                        }
                        const targetVol = volume || this.volumeValue;
                        if (this.clipboard.volume && targetVol && this.clipboard.volume !== targetVol) {
                            return;
                        }
                        const normalized = (Array.isArray(affectedPaths) ? affectedPaths : [affectedPaths])
                            .map((p) => String(p ?? '').replace(/^\/+|\/+$/g, ''))
                            .filter(Boolean);
                        if (normalized.length === 0) {
                            return;
                        }
                        const remaining = this.clipboard.paths.filter((clipPath) => {
                            const cleanClip = String(clipPath ?? '').replace(/^\/+|\/+$/g, '');
                            return !normalized.some((p) => cleanClip === p || cleanClip.startsWith(`${p}/`));
                        });
                        this.clipboard = remaining.length > 0 ? { ...this.clipboard, paths: remaining } : null;
                        this.syncSelectionUi(true);
                    }

    async pasteHere() {
                        await this.pasteClipboard();
                    }

                    syncClipboardUi() {
                        if (!this.hasClipboardBarTarget) {
                            return;
                        }
                        const hasClipboard = Boolean(this.clipboard && this.clipboard.paths.length > 0 && !this.trashMode);
                        const bulkVisible = this.selectedMap.size >= 2;
                        this.clipboardBarTarget.classList.toggle('liveux-fm-hidden', !hasClipboard || bulkVisible);
                        if (!hasClipboard) {
                            return;
                        }
                        const count = this.clipboard.paths.length;
                        const isCut = this.clipboard.mode === 'move';
                        if (this.hasClipboardCountTarget) {
                            this.clipboardCountTarget.textContent = String(count);
                        }
                        if (this.hasClipboardLabelTarget) {
                            const firstName = this.clipboard.paths[0].split('/').pop() || this.clipboard.paths[0];
                            const actionText = this.t(isCut ? 'messages.clipboard_cut' : 'messages.clipboard_copied', { count });
                            this.clipboardLabelTarget.textContent = count === 1 ? `${actionText} (${firstName})` : actionText;
                            this.clipboardLabelTarget.title = this.clipboard.paths.join(', ');
                        }
                        if (this.hasClipboardPasteBtnTarget) {
                            const curDir = this.pathValue || '';
                            const sameVol = this.clipboard.volume === this.volumeValue;
                            const sameDirForAllCut = isCut
                                && sameVol
                                && this.clipboard.paths.every((p) => {
                                    const parent = p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
                                    return parent === curDir;
                                });
                            const allIntoSelf = sameVol
                                && this.clipboard.paths.every((p) => p === curDir || curDir.startsWith(`${p}/`));
                            this.clipboardPasteBtnTarget.disabled = sameDirForAllCut || allIntoSelf;
                        }
                    }

    async pasteClipboard(overrideTargetDir = null, overrideTargetVolume = null) {
                        if (this.isPasting || !this.clipboard || this.clipboard.paths.length === 0 || this.trashMode) {
                            return;
                        }
                        const isCopy = this.clipboard.mode === 'copy';
                        const caps = this.capabilitiesValue;
                        if ((isCopy && !caps.copy) || (!isCopy && !caps.move)) {
                            return;
                        }
                        const targetDir = typeof overrideTargetDir === 'string' ? overrideTargetDir : this.pathValue;
                        const targetVolume = typeof overrideTargetVolume === 'string' && overrideTargetVolume !== '' ? overrideTargetVolume : this.volumeValue;
                        const sourceVolume = this.clipboard.volume;
                        const validPaths = this.clipboard.paths.filter((p) => {
                            if (sourceVolume !== targetVolume) {
                                return true;
                            }
                            if (!isCopy) {
                                const parent = p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
                                if (parent === (targetDir || '')) {
                                    return false;
                                }
                            }
                            return p !== targetDir && !targetDir.startsWith(`${p}/`);
                        });
                        if (validPaths.length === 0) {
                            return;
                        }

                        this.isPasting = true;
                        if (this.hasClipboardPasteBtnTarget) {
                            this.clipboardPasteBtnTarget.disabled = true;
                        }
                        try {
                            const ok = await this.callAction('bulkMove', {
                                volume: sourceVolume,
                                paths: validPaths,
                                targetDir,
                                targetVolume,
                                isCopy,
                            });
                            if (ok) {
                                if (!isCopy) {
                                    this.clipboard = null;
                                }
                                this.clearSelection();
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true), this.refreshFullTree(true)]);
                                this.syncActiveTreeNode(false);
                            }
                        } finally {
                            this.isPasting = false;
                            this.syncClipboardUi();
                        }
                    }

                    onFolderClick(event) {
                        if (event?.target?.closest?.('button, input')) {
                            return;
                        }
                        if (this.folderOpenOnValue === 'click') {
                            this.navigate(event);
                            return;
                        }

                        const el = event.currentTarget;
                        const path = el.dataset.path || el.dataset.folderPath || '';
                        const name = el.dataset.name || '';
                        const volume = el.dataset.volume || this.volumeValue;

                        if (!this.isSinglePickerMode() && event.shiftKey && this.lastSelectedPath && this.selectRangeTo(path)) {
                            this.syncSelectionUi();
                            return;
                        }

                        if (!this.isSinglePickerMode() && (event.ctrlKey || event.metaKey)) {
                            if (this.selectedMap.has(path)) {
                                this.selectedMap.delete(path);
                            } else {
                                this.selectedMap.set(path, { kind: 'folder', path, name, volume });
                            }
                        } else {
                            this.selectedMap.clear();
                            this.selectedMap.set(path, { kind: 'folder', path, name, volume });
                        }
                        this.lastSelectedPath = path;
                        this.syncSelectionUi();
                    }

                    onFolderDblClick(event) {
                        if (event?.target?.closest?.('button, input')) {
                            return;
                        }
                        this.navigate(event);
                    }

                    selectFile(event) {
                        if (event?.target?.closest?.('button, input')) {
                            return;
                        }
                        const el = event.currentTarget;
                        const path = el.dataset.path;
                        const url = el.dataset.url || '';
                        const name = el.dataset.name || '';

                        if (!this.isSinglePickerMode() && event.shiftKey && this.lastSelectedPath && this.selectRangeTo(path)) {
                            this.syncSelectionUi();
                            return;
                        }

                        if (!this.isSinglePickerMode() && (event.ctrlKey || event.metaKey)) {
                            if (this.selectedMap.has(path)) {
                                this.selectedMap.delete(path);
                            } else {
                                this.selectedMap.set(path, { kind: 'file', path, url, name });
                            }
                        } else {
                            this.selectedMap.clear();
                            this.selectedMap.set(path, { kind: 'file', path, url, name });
                        }
                        this.lastSelectedPath = path;
                        this.syncSelectionUi();
                    }

                    toggleCheckbox(event) {
                        event.stopPropagation();
                        const cb = event.currentTarget;
                        const path = cb.dataset.path;
                        if (cb.checked) {
                            if (this.isSinglePickerMode()) {
                                this.selectedMap.clear();
                            }
                            this.selectedMap.set(path, { kind: 'file', path, url: cb.dataset.url || '', name: cb.dataset.name || '' });
                            this.lastSelectedPath = path;
                        } else {
                            this.selectedMap.delete(path);
                        }
                        this.syncSelectionUi();
                    }

                    clearSelection() {
                        this.selectedMap.clear();
                        this.syncSelectionUi();
                    }

                    doubleClickFile(event) {
                        if (event?.target?.closest?.('button, input')) {
                            return;
                        }
                        if (event.ctrlKey || event.metaKey || event.shiftKey) {
                            // The two preceding clicks toggled the item; double-click must leave it selected.
                            const dblPath = event.currentTarget?.dataset?.path;
                            if (dblPath && !this.selectedMap.has(dblPath)) {
                                const ds = event.currentTarget.dataset;
                                this.selectedMap.set(dblPath, { kind: 'file', path: dblPath, url: ds.url || '', name: ds.name || '' });
                                this.lastSelectedPath = dblPath;
                                this.syncSelectionUi();
                            }
                        } else {
                            this.selectFile(event);
                        }
                        const isDialogPicker = Boolean(this.element?.closest?.('dialog'));
                        if (isDialogPicker && this.doubleClickToSelectValue) {
                            this.confirmSelection();
                            return;
                        }
                        const path = event?.currentTarget?.dataset?.path;
                        if (path) {
                            const item = this.getActiveItemList().find((i) => i.path === path);
                            if (item && this.getPreviewKind(item) !== null) {
                                this.openPreview(item);
                                return;
                            }
                        }
                        if (this.doubleClickToSelectValue) {
                            this.confirmSelection();
                        }
                    }

                    confirmSelection() {
                        const itemsByPath = new Map(this.getActiveItemList().map((i) => [i.path, i]));
                        let files = Array.from(this.selectedMap.values())
                            .filter((item) => item.kind !== 'folder')
                            .map((item) => {
                                const full = itemsByPath.get(item.path);
                                return {
                                    ...item,
                                    isImage: Boolean(full?.isImage),
                                    extension: full?.extension || '',
                                    meta: full?.meta || null,
                                };
                            });
                        if (files.length === 0) {
                            return;
                        }
                        if (this.isSinglePickerMode() && files.length > 1) {
                            files = files.slice(0, 1);
                        }
                        this.element.dispatchEvent(new CustomEvent('liveux:file-manager:selected', {
                            bubbles: true,
                            detail: { files },
                        }));
                    }

                    syncSelectionUi(skipInspector = false) {
                        const cutSet = (this.clipboard?.mode === 'move' && this.clipboard.volume === this.volumeValue)
                            ? new Set(this.clipboard.paths)
                            : null;

                        if (this.element && typeof this.element.querySelectorAll === 'function') {
                            this.element.querySelectorAll('[data-fm-file-card], [data-fm-folder-card]').forEach((card) => {
                                const p = card.dataset.path;
                                const selected = this.selectedMap.has(p);
                                card.classList.toggle('is-selected', selected);
                                card.classList.toggle('is-cut', Boolean(cutSet && cutSet.has(p)));
                                const cb = card.querySelector('input[type="checkbox"]');
                                if (cb) {
                                    cb.checked = selected;
                                }
                            });
                        }
                        if (this.hasBulkBarTarget && this.hasBulkCountTarget) {
                            this.bulkCountTarget.textContent = String(this.selectedMap.size);
                            this.bulkBarTarget.classList.toggle('liveux-fm-hidden', this.selectedMap.size < 2);
                        }
                        if (this.hasConfirmSelectionBtnTarget) {
                            const selectedFileCount = Array.from(this.selectedMap.values()).filter((i) => i.kind !== 'folder').length;
                            this.confirmSelectionBtnTarget.disabled = selectedFileCount === 0;
                        }
                        this.syncClipboardUi();
                        if (!skipInspector) {
                            this.renderInspector();
                        }
                    }

                    onContentMouseDown(event) {
                        if (event.button !== 0 || this.trashMode || this.isSinglePickerMode() || !this.hasContentAreaTarget) {
                            return;
                        }
                        if (event.target.closest('[data-fm-file-card], [data-fm-folder-card], .liveux-fm-list-header, button, input, select, a, label')) {
                            return;
                        }

                        const container = this.contentAreaTarget;
                        const rect = container.getBoundingClientRect();
                        const startClientX = event.clientX;
                        const startClientY = event.clientY;
                        const startClientLeft = container.clientLeft || 0;
                        const startClientTop = container.clientTop || 0;
                        const startClientWidth = container.clientWidth ?? rect.width;
                        const startClientHeight = container.clientHeight ?? rect.height;
                        // Ignore clicks on container scrollbars (right/bottom in LTR, left/bottom in RTL)
                        if (
                            startClientX < rect.left + startClientLeft
                            || startClientX >= rect.left + startClientLeft + startClientWidth
                            || startClientY < rect.top + startClientTop
                            || startClientY >= rect.top + startClientTop + startClientHeight
                        ) {
                            return;
                        }

                        const startScrollLeft = container.scrollLeft || 0;
                        const startScrollTop = container.scrollTop || 0;
                        const startX = startClientX - (rect.left + startClientLeft) + startScrollLeft;
                        const startY = startClientY - (rect.top + startClientTop) + startScrollTop;
                        const additive = Boolean(event.ctrlKey || event.metaKey);
                        const baseSelection = additive ? new Map(this.selectedMap) : new Map();

                        let cachedCards = null;
                        let marqueeEl = null;
                        let active = false;
                        let lastSelectionSig = Array.from(this.selectedMap.keys()).join('\n');

                        const onMouseMove = (moveEvt) => {
                            const dx = Math.abs(moveEvt.clientX - startClientX);
                            const dy = Math.abs(moveEvt.clientY - startClientY);
                            if (!active && dx < 6 && dy < 6) {
                                return;
                            }
                            const curRect = container.getBoundingClientRect();
                            const curScrollLeft = container.scrollLeft || 0;
                            const curScrollTop = container.scrollTop || 0;
                            const curClientLeft = container.clientLeft || 0;
                            const curClientTop = container.clientTop || 0;
                            const curClientWidth = container.clientWidth ?? curRect.width;
                            const curClientHeight = container.clientHeight ?? curRect.height;
                            const scrollW = container.scrollWidth ?? curClientWidth;
                            const scrollH = container.scrollHeight ?? curClientHeight;

                            if (!active) {
                                active = true;
                                cachedCards = Array.from(container.querySelectorAll('[data-fm-file-card], [data-fm-folder-card]')).map((card) => {
                                    const cr = card.getBoundingClientRect();
                                    return {
                                        path: card.dataset.path,
                                        name: card.dataset.name || card.dataset.path || '',
                                        url: card.dataset.url || '',
                                        volume: card.dataset.volume || this.volumeValue,
                                        isFolder: card.hasAttribute('data-fm-folder-card'),
                                        left: cr.left - (curRect.left + curClientLeft) + curScrollLeft,
                                        right: cr.right - (curRect.left + curClientLeft) + curScrollLeft,
                                        top: cr.top - (curRect.top + curClientTop) + curScrollTop,
                                        bottom: cr.bottom - (curRect.top + curClientTop) + curScrollTop,
                                    };
                                });
                                marqueeEl = document.createElement('div');
                                marqueeEl.className = 'liveux-fm-marquee';
                                container.appendChild(marqueeEl);
                                document.body.style.userSelect = 'none';
                            }

                            const isNegativeScrollRtl = curScrollLeft < 0 || (this.isRtl() && curScrollLeft <= 0);
                            const minX = isNegativeScrollRtl ? Math.min(0, curClientWidth - scrollW, curScrollLeft) : 0;
                            const maxX = isNegativeScrollRtl ? Math.max(curClientWidth, curScrollLeft + curClientWidth) : scrollW;
                            const rawX = moveEvt.clientX - (curRect.left + curClientLeft) + curScrollLeft;
                            const rawY = moveEvt.clientY - (curRect.top + curClientTop) + curScrollTop;
                            const curX = Math.max(minX, Math.min(maxX, rawX));
                            const curY = Math.max(0, Math.min(scrollH, rawY));

                            const left = Math.min(startX, curX);
                            const top = Math.min(startY, curY);
                            const width = Math.abs(curX - startX);
                            const height = Math.abs(curY - startY);
                            const right = left + width;
                            const bottom = top + height;

                            marqueeEl.style.left = `${left}px`;
                            marqueeEl.style.top = `${top}px`;
                            marqueeEl.style.width = `${width}px`;
                            marqueeEl.style.height = `${height}px`;

                            const nextMap = new Map(baseSelection);
                            let lastHitPath = null;
                            for (const item of cachedCards) {
                                const intersects = !(item.right < left || item.left > right || item.bottom < top || item.top > bottom);
                                const path = item.path;
                                if (!intersects || !path) {
                                    continue;
                                }
                                lastHitPath = path;
                                if (item.isFolder) {
                                    nextMap.set(path, { kind: 'folder', path, name: item.name, url: '', volume: item.volume });
                                } else {
                                    nextMap.set(path, { kind: 'file', path, name: item.name, url: item.url, volume: item.volume });
                                }
                            }

                            const nextSig = Array.from(nextMap.keys()).join('\n');
                            if (nextSig !== lastSelectionSig) {
                                lastSelectionSig = nextSig;
                                this.selectedMap = nextMap;
                                if (lastHitPath) {
                                    this.lastSelectedPath = lastHitPath;
                                } else if (nextMap.size === 0) {
                                    this.lastSelectedPath = null;
                                }
                                this.syncSelectionUi(true);
                            }
                        };

                        const cleanup = () => {
                            document.removeEventListener('mousemove', onMouseMove);
                            document.removeEventListener('mouseup', onMouseUp);
                            window.removeEventListener('blur', onMouseUp);
                            document.body.style.userSelect = '';
                            if (marqueeEl && marqueeEl.parentNode) {
                                marqueeEl.parentNode.removeChild(marqueeEl);
                            }
                        };

                        const onMouseUp = () => {
                            this.activeDragCleanup = null;
                            cleanup();
                            if (active) {
                                if (this.selectedMap.size === 0) {
                                    this.lastSelectedPath = null;
                                }
                                this.renderInspector();
                                this.justMarqueeSelected = true;
                                setTimeout(() => { this.justMarqueeSelected = false; }, 60);
                            }
                        };

                        this.registerDragCleanup(cleanup);
                        document.addEventListener('mousemove', onMouseMove);
                        document.addEventListener('mouseup', onMouseUp);
                        window.addEventListener('blur', onMouseUp);
                    }

                    onContentAreaClick(event) {
                        if (this.justMarqueeSelected) {
                            return;
                        }
                        if (event.target.closest('[data-fm-file-card], [data-fm-folder-card], .liveux-fm-list-header')) {
                            return;
                        }
                        if (this.selectedMap.size > 0) {
                            this.clearSelection();
                        }
                    }

                    cancelInspectorImageProbe() {
                        if (this._inspectorImageProbe) {
                            this._inspectorImageProbe.onload = null;
                            this._inspectorImageProbe.onerror = null;
                            this._inspectorImageProbe.src = '';
                            this._inspectorImageProbe = null;
                        }
                    }

                    isInspectorBreakpointHidden() {
                        return typeof window !== 'undefined'
                            && typeof window.matchMedia === 'function'
                            && window.matchMedia('(max-width: 991.98px)').matches;
                    }

                    isInspectorHidden() {
                        return Boolean(
                            this.isDisconnected
                            || !this.hasInspectorTarget
                            || !this.inspectorTarget
                            || this.inspectorModeValue === 'hidden'
                            || this.inspectorTarget.classList?.contains?.('liveux-fm-hidden')
                            || this.isInspectorBreakpointHidden(),
                        );
                    }

                    renderInspector() {
                        this.cancelInspectorImageProbe();
                        const selectedPaths = Array.from(this.selectedMap.keys());
                        const rawItems = this.getActiveItemList();
                        const activeItem = selectedPaths.length === 1
                            ? rawItems.find((i) => i.path === selectedPaths[0]) || null
                            : null;

                        if (this.hasFooterStatusTarget) {
                            const isInlineStatus = typeof this.footerStatusTarget.closest === 'function'
                                && Boolean(this.footerStatusTarget.closest('.liveux-fm-pagination'));
                            if (this.capabilitiesValue?.showFooter === false) {
                                this.footerStatusTarget.textContent = '';
                            } else if (selectedPaths.length > 1) {
                                this.footerStatusTarget.textContent = isInlineStatus
                                    ? `• ${selectedPaths.length} ${this.t('inspector.selected_count')}`
                                    : this.t('footer.selected_multi', { count: selectedPaths.length });
                            } else if (activeItem && activeItem.kind === 'folder') {
                                this.footerStatusTarget.textContent = isInlineStatus
                                    ? `• ${activeItem.name}`
                                    : this.t('footer.selected_folder', { name: activeItem.name });
                            } else if (activeItem) {
                                const footerSize = typeof activeItem.size === 'number'
                                    ? this.formatBytes(activeItem.size)
                                    : (activeItem.sizeFormatted || '');
                                this.footerStatusTarget.textContent = isInlineStatus
                                    ? `• ${activeItem.name}${footerSize ? ` (${footerSize})` : ''}`
                                    : this.t('footer.selected_single', { name: activeItem.name, size: footerSize });
                            } else {
                                this.footerStatusTarget.textContent = isInlineStatus ? '' : this.t('footer.hint');
                            }
                        }

                        if (!this.hasInspectorTarget || this.inspectorModeValue === 'hidden') {
                            return;
                        }

                        this.releaseMediaElements(this.inspectorTarget);

                        const hasSelection = selectedPaths.length > 0;
                        if (this.inspectorModeValue === 'on_select') {
                            this.inspectorTarget.classList.toggle('liveux-fm-hidden', !hasSelection);
                            if (!hasSelection) {
                                return;
                            }
                        }

                        if (this.isInspectorBreakpointHidden()) {
                            return;
                        }

                        const closeBtn = this.inspectorModeValue === 'on_select'
                            ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-ghost" title="${this.escapeAttr(this.t('actions.close'))}" data-insp-close style="line-height:1;">✕</button>`
                            : '';

                        if (selectedPaths.length > 1) {
                            this.inspectorTarget.innerHTML = `
                <div class="liveux-fm-row-between">
                    <span class="liveux-fm-section-label">${this.escapeHtml(this.t('inspector.title'))}</span>
                    ${closeBtn}
                </div>
                <div class="liveux-fm-inspector-box">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--liveux-fm-accent);">${this.escapeHtml(this.formatNumber(selectedPaths.length))}</div>
                    <div class="liveux-fm-muted liveux-fm-text-sm">${this.escapeHtml(this.t('inspector.selected_count'))}</div>
                </div>`;
                            this.inspectorTarget.querySelector('[data-insp-close]')?.addEventListener('click', () => this.clearSelection());
                            return;
                        }

                        if (!activeItem) {
                            this.inspectorTarget.innerHTML = `
                <span class="liveux-fm-section-label">${this.escapeHtml(this.t('inspector.title'))}</span>
                <div class="liveux-fm-empty" style="padding: 24px 8px;">${this.escapeHtml(this.t('inspector.empty'))}</div>`;
                            return;
                        }

                        const caps = this.capabilitiesValue;
                        const fmtDateTime = (ts) => this.formatDateTime(ts, 'short', 'short');
                        const dirPath = activeItem.path.includes('/') ? `/${activeItem.path.slice(0, activeItem.path.lastIndexOf('/'))}` : '/';

                        if (activeItem.kind === 'folder') {
                            const modifiedStr = fmtDateTime(activeItem.lastModified);
                            const folderActions = [];
                            if (caps.rename) {
                                folderActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-rename title="${this.escapeAttr(this.t('actions.rename'))}" aria-label="${this.escapeAttr(this.t('actions.rename'))}">${this.ctxIcon('rename', 15)}</button>`);
                            }
                            if (caps.move || caps.copy) {
                                folderActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-move title="${this.escapeAttr(this.t('actions.move'))}" aria-label="${this.escapeAttr(this.t('actions.move'))}">${this.ctxIcon('move', 15)}</button>`);
                            }
                            if (caps.editMetadata) {
                                const pinLabel = this.t(activeItem.pinned ? 'actions.unpin' : 'actions.pin');
                                folderActions.push(`<button type="button" class="liveux-fm-ctx-action-btn ${activeItem.pinned ? 'is-active' : ''}" data-insp-pin title="${this.escapeAttr(pinLabel)}" aria-label="${this.escapeAttr(pinLabel)}"><svg width="15" height="15" fill="${activeItem.pinned ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg></button>`);
                                folderActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-color title="${this.escapeAttr(this.t('actions.customize'))}" aria-label="${this.escapeAttr(this.t('actions.customize'))}">${this.ctxIcon('color', 15)}</button>`);
                            }
                            if (caps.delete) {
                                folderActions.push(`<button type="button" class="liveux-fm-ctx-action-btn is-danger" data-insp-delete title="${this.escapeAttr(this.t('actions.delete'))}" aria-label="${this.escapeAttr(this.t('actions.delete'))}">${this.ctxIcon('delete', 15)}</button>`);
                            }

                            this.inspectorTarget.innerHTML = `
                <div class="liveux-fm-row-between">
                    <span class="liveux-fm-section-label">${this.escapeHtml(this.t('inspector.title'))}</span>
                    ${closeBtn}
                </div>
                <div class="liveux-fm-stage">${this.renderFolderSvg(52, activeItem.color, false, activeItem.icon || null)}</div>
                <div class="liveux-fm-strong liveux-fm-break" style="font-size: 0.8rem;">${this.escapeHtml(activeItem.name)}</div>
                ${folderActions.length > 0 ? `<div class="liveux-fm-inspector-actions">${folderActions.join('')}</div>` : ''}
                <div class="liveux-fm-inspector-meta">
                    <div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.type'))}:</span><span>${this.escapeHtml(this.t('empty.subfolder_label'))}</span></div>
                    ${(caps.showFolderItemCount && typeof activeItem.itemCount === 'number') ? `<div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.item_count'))}:</span><span class="liveux-fm-mono">${this.escapeHtml(this.formatNumber(Math.floor(activeItem.itemCount)))}</span></div>` : ''}
                    ${activeItem.lastModified ? `<div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.modified'))}:</span><span class="liveux-fm-mono">${this.escapeHtml(modifiedStr)}</span></div>` : ''}
                    <div class="liveux-fm-row-between"><span class="liveux-fm-muted liveux-fm-shrink-0">${this.escapeHtml(this.t('inspector.path'))}:</span><span class="liveux-fm-mono liveux-fm-truncate" dir="ltr" title="${this.escapeAttr(dirPath)}">${this.escapeHtml(dirPath)}</span></div>
                </div>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary liveux-fm-btn-xs liveux-fm-w-full" data-insp-open-folder>${this.escapeHtml(this.t('actions.open'))}</button>
            `;
                            this.inspectorTarget.querySelector('[data-insp-close]')?.addEventListener('click', () => this.clearSelection());
                            this.inspectorTarget.querySelector('[data-insp-open-folder]')?.addEventListener('click', () => this.navigateTo(activeItem.path));
                            this.inspectorTarget.querySelector('[data-insp-rename]')?.addEventListener('click', () => this.openRenameModal(activeItem.path, activeItem.name));
                            this.inspectorTarget.querySelector('[data-insp-move]')?.addEventListener('click', () => this.openMoveModal(activeItem.path, activeItem.name, true));
                            this.inspectorTarget.querySelector('[data-insp-pin]')?.addEventListener('click', () => this.toggleFolderPin(activeItem.path));
                            this.inspectorTarget.querySelector('[data-insp-color]')?.addEventListener('click', () => this.openFolderColorModal(activeItem.path, activeItem.name, activeItem.color || '', activeItem.icon || ''));
                            this.inspectorTarget.querySelector('[data-insp-delete]')?.addEventListener('click', () => this.openDeleteModal([activeItem.path], activeItem.name));
                            return;
                        }

                        const previewKind = this.getPreviewKind(activeItem);
                        const fileMeta = activeItem.meta || null;
                        const rawFocal = fileMeta?.focalPoint || null;
                        const focal = (rawFocal && Number.isFinite(Number(rawFocal.x)) && Number.isFinite(Number(rawFocal.y)))
                            ? { x: this.clampUnit(rawFocal.x), y: this.clampUnit(rawFocal.y) }
                            : null;
                        const focalPctX = focal ? this.formatNumber(Math.round(focal.x * 100)) : '';
                        const focalPctY = focal ? this.formatNumber(Math.round(focal.y * 100)) : '';
                        const focalDotHtml = (activeItem.isImage && focal)
                            ? `<span class="liveux-fm-focal-dot" style="width:12px;height:12px;left:${(focal.x * 100).toFixed(2)}%;top:${(focal.y * 100).toFixed(2)}%;" title="${this.escapeAttr(`${focalPctX}% × ${focalPctY}%`)}"></span>`
                            : '';
                        let stage = `<div class="liveux-fm-stage" ${previewKind ? 'style="cursor:pointer;" data-inspector-preview' : ''}>${this.renderFileTypeSvg(activeItem.extension)}</div>`;
                        if (activeItem.isImage && this.isSafeUrl(activeItem.thumbUrl)) {
                            stage = `<div class="liveux-fm-stage ${this.supportsAlpha(activeItem.extension) ? 'is-checker' : ''}" data-fit="contain" style="cursor:zoom-in;position:relative;" data-inspector-preview><img src="${this.escapeAttr(activeItem.thumbUrl)}" alt="${this.escapeAttr(fileMeta?.alt || activeItem.name)}">${focalDotHtml}</div>`;
                        } else if (previewKind === 'video' && this.isSafeHttpOrPathUrl(activeItem.url)) {
                            const safeVideoUrl = this.escapeAttr(activeItem.url);
                            stage = `<div class="liveux-fm-stage is-media">
                <video src="${safeVideoUrl}#t=0.5" class="liveux-fm-inspector-video" preload="metadata" controls playsinline></video>
                <button type="button" class="liveux-fm-stage-expand" data-inspector-preview title="${this.escapeAttr(this.t('actions.preview'))}" aria-label="${this.escapeAttr(this.t('actions.preview'))}">${this.ctxIcon('fullscreen', 13)}</button>
            </div>`;
                        }

                        const createdStr = fmtDateTime(activeItem.createdAt);
                        const modifiedStr = fmtDateTime(activeItem.lastModified);
                        const modifiedTooltip = activeItem.createdAt ? `${this.t('inspector.created')}: ${createdStr}` : '';
                        const exactBytes = typeof activeItem.size === 'number' ? `${this.formatNumber(activeItem.size)} ${this.unitLabel('units.b', 'B')}` : '';
                        const inspectorSizeLabel = typeof activeItem.size === 'number'
                            ? this.formatBytes(activeItem.size)
                            : (activeItem.sizeFormatted || '—');
                        const tagsHtml = (fileMeta && Array.isArray(fileMeta.tags) && fileMeta.tags.length > 0)
                            ? `<div class="liveux-fm-tags">${fileMeta.tags.map((tg) => `<button type="button" class="liveux-fm-tag-chip" data-insp-filter-tag="${this.escapeAttr(tg)}">#${this.escapeHtml(tg)}</button>`).join('')}</div>`
                            : '';
                        const isZipFile = String(activeItem.extension || '').toLowerCase() === 'zip';
                        const metaBtnLabel = this.t(activeItem.isImage ? 'actions.edit_image_metadata' : 'actions.edit_metadata');

                        const quickActions = [];
                        if (previewKind) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-inspector-preview title="${this.escapeAttr(this.t('actions.preview'))}" aria-label="${this.escapeAttr(this.t('actions.preview'))}">${this.ctxIcon('preview', 15)}</button>`);
                        }
                        if (activeItem.isImage && this.isEditableRasterExtension(activeItem.extension) && caps.editImage && this.imageEditUrlValue) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-edit-image title="${this.escapeAttr(this.t('actions.edit_image'))}" aria-label="${this.escapeAttr(this.t('actions.edit_image'))}">${this.ctxIcon('editImage', 15)}</button>`);
                        }
                        if (caps.editMetadata) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-edit-meta title="${this.escapeAttr(metaBtnLabel)}" aria-label="${this.escapeAttr(metaBtnLabel)}">${this.ctxIcon('tag', 15)}</button>`);
                        }
                        if (caps.download !== false && activeItem.url && this.isSafeUrl(activeItem.url)) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-copy-url title="${this.escapeAttr(this.t('actions.copy_url'))}" aria-label="${this.escapeAttr(this.t('actions.copy_url'))}">${this.ctxIcon('link', 15)}</button>`);
                        }
                        if (caps.share) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-share-link title="${this.escapeAttr(this.t('actions.share_link'))}" aria-label="${this.escapeAttr(this.t('actions.share_link'))}">${this.ctxIcon('share', 15)}</button>`);
                        }
                        if (caps.rename) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn" data-insp-rename title="${this.escapeAttr(this.t('actions.rename'))}" aria-label="${this.escapeAttr(this.t('actions.rename'))}">${this.ctxIcon('rename', 15)}</button>`);
                        }
                        if (caps.delete) {
                            quickActions.push(`<button type="button" class="liveux-fm-ctx-action-btn is-danger" data-insp-delete title="${this.escapeAttr(this.t('actions.delete'))}" aria-label="${this.escapeAttr(this.t('actions.delete'))}">${this.ctxIcon('delete', 15)}</button>`);
                        }

                        if (!this.imageDimsCache) {
                            this.imageDimsCache = new Map();
                        }
                        const dimKey = activeItem.isImage
                            ? `${activeItem.volume || this.volumeValue || ''}::${activeItem.path}::${activeItem.lastModified || 0}::${activeItem.size ?? ''}`
                            : '';
                        const cachedDimText = (dimKey && this.imageDimsCache.has(dimKey)) ? this.imageDimsCache.get(dimKey) : null;

                        this.inspectorTarget.innerHTML = `
            <div class="liveux-fm-row-between">
                <span class="liveux-fm-section-label">${this.escapeHtml(this.t('inspector.title'))}</span>
                ${closeBtn}
            </div>
            ${stage}
            <div class="liveux-fm-strong liveux-fm-break" style="font-size: 0.8rem;">${this.escapeHtml(activeItem.name)}</div>
            ${quickActions.length > 0 ? `<div class="liveux-fm-inspector-actions">${quickActions.join('')}</div>` : ''}
            <div class="liveux-fm-inspector-meta">
                <div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.size'))}:</span><span class="liveux-fm-mono" title="${this.escapeAttr(exactBytes)}">${this.escapeHtml(inspectorSizeLabel)}</span></div>
                <div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.type'))}:</span><span class="liveux-fm-mono" style="text-transform:uppercase;">${this.escapeHtml(activeItem.extension || '—')}</span></div>
                ${activeItem.isImage ? `<div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.dimensions'))}:</span><span class="liveux-fm-mono" dir="ltr" data-insp-dims>${cachedDimText ? this.escapeHtml(cachedDimText) : '…'}</span></div>` : ''}
                ${focal ? `<div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.focal_point'))}:</span><span class="liveux-fm-mono" dir="ltr">${this.escapeHtml(`${focalPctX}% × ${focalPctY}%`)}</span></div>` : ''}
                ${fileMeta?.alt ? `<div class="liveux-fm-row-between"><span class="liveux-fm-muted liveux-fm-shrink-0">${this.escapeHtml(this.t('inspector.alt'))}:</span><span class="liveux-fm-truncate" title="${this.escapeAttr(fileMeta.alt)}">${this.escapeHtml(fileMeta.alt)}</span></div>` : ''}
                <div class="liveux-fm-row-between"><span class="liveux-fm-muted">${this.escapeHtml(this.t('inspector.modified'))}:</span><span class="liveux-fm-mono" title="${this.escapeAttr(modifiedTooltip)}">${this.escapeHtml(modifiedStr)}</span></div>
                <div class="liveux-fm-row-between"><span class="liveux-fm-muted liveux-fm-shrink-0">${this.escapeHtml(this.t('inspector.path'))}:</span><span class="liveux-fm-mono liveux-fm-truncate" dir="ltr" title="${this.escapeAttr(dirPath)}">${this.escapeHtml(dirPath)}</span></div>
            </div>
            ${tagsHtml}
            ${(isZipFile && caps.extractZip) ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-xs liveux-fm-w-full" data-insp-extract-zip>${this.escapeHtml(this.t('actions.extract_zip'))}</button>` : ''}
            ${caps.download && this.isSafeHttpOrPathUrl(activeItem.downloadUrl) ? `<a href="${this.escapeAttr(activeItem.downloadUrl)}" data-turbo="false" download class="liveux-fm-btn liveux-fm-btn-primary liveux-fm-btn-xs liveux-fm-w-full">${this.escapeHtml(this.t('actions.download'))}</a>` : ''}
        `;

                        const probeSrc = activeItem.isImage ? this.resolveInlineImageUrl(activeItem) : '';
                        if (probeSrc) {
                            const dimsEl = this.inspectorTarget.querySelector('[data-insp-dims]');
                            if (this.imageDimsCache.has(dimKey)) {
                                if (dimsEl) {
                                    dimsEl.textContent = this.imageDimsCache.get(dimKey);
                                }
                            } else if (typeof Image !== 'undefined') {
                                const expectedPath = activeItem.path;
                                const expectedVolume = activeItem.volume || this.volumeValue || '';
                                const isStillShowing = () => !this.isInspectorHidden()
                                    && expectedVolume === (this.volumeValue || '')
                                    && (!this.selectedMap || (this.selectedMap.size === 1 && this.selectedMap.has(expectedPath)));
                                const probe = new Image();
                                this._inspectorImageProbe = probe;
                                probe.onload = () => {
                                    if (this._inspectorImageProbe === probe) {
                                        this._inspectorImageProbe = null;
                                    }
                                    if (probe.naturalWidth > 0 && probe.naturalHeight > 0) {
                                        const dimText = `${this.formatNumber(probe.naturalWidth)} × ${this.formatNumber(probe.naturalHeight)} px`;
                                        if (this.imageDimsCache.size >= 500) {
                                            const oldestKey = this.imageDimsCache.keys().next().value;
                                            if (oldestKey !== undefined) {
                                                this.imageDimsCache.delete(oldestKey);
                                            }
                                        }
                                        this.imageDimsCache.set(dimKey, dimText);
                                        if (dimsEl && dimsEl.isConnected !== false && isStillShowing()) {
                                            dimsEl.textContent = dimText;
                                        }
                                    } else if (dimsEl && dimsEl.isConnected !== false && isStillShowing()) {
                                        dimsEl.textContent = '—';
                                    }
                                };
                                probe.onerror = () => {
                                    if (this._inspectorImageProbe === probe) {
                                        this._inspectorImageProbe = null;
                                    }
                                    if (dimsEl && dimsEl.isConnected !== false && isStillShowing()) {
                                        dimsEl.textContent = '—';
                                    }
                                };
                                probe.src = probeSrc;
                            }
                        }

                        this.inspectorTarget.querySelector('[data-insp-close]')?.addEventListener('click', () => this.clearSelection());
                        this.inspectorTarget.querySelectorAll('[data-inspector-preview]').forEach((el) => {
                            el.addEventListener('click', () => this.openPreview(activeItem));
                        });
                        this.inspectorTarget.querySelector('[data-insp-edit-image]')?.addEventListener('click', () => {
                            this.openImageEditorModal(activeItem.path, activeItem.name, activeItem.url, activeItem.downloadUrl);
                        });
                        this.inspectorTarget.querySelector('[data-insp-edit-meta]')?.addEventListener('click', () => {
                            this.openFileMetadataModal(activeItem);
                        });
                        this.inspectorTarget.querySelector('[data-insp-extract-zip]')?.addEventListener('click', () => {
                            this.extractZipArchive(activeItem.path);
                        });
                        this.inspectorTarget.querySelectorAll('[data-insp-filter-tag]').forEach((btn) => {
                            btn.addEventListener('click', () => {
                                const tagQ = `#${btn.dataset.inspFilterTag || ''}`;
                                this.rawSearchQuery = tagQ.trim().normalize('NFC').replace(/^＃/, '#');
                                this.searchQuery = this.foldSearch(tagQ.trim());
                                if (this.hasSearchInputTarget) {
                                    this.searchInputTarget.value = tagQ;
                                }
                                this.pageValue = 1;
                                if (this.recursiveSearch && this.isRecursiveQueryReady()) {
                                    this.fetchRecursiveSearch();
                                } else {
                                    this.applyClientView();
                                }
                            });
                        });
                        this.inspectorTarget.querySelector('[data-insp-copy-url]')?.addEventListener('click', async () => {
                            if (!this.isSafeUrl(activeItem.url)) {
                                return;
                            }
                            if (await this.copyTextToClipboard(activeItem.url)) {
                                this.showStatus(this.t('actions.copied'), 'success');
                            }
                        });
                        this.inspectorTarget.querySelector('[data-insp-share-link]')?.addEventListener('click', () => {
                            this.openShareLinkModal(activeItem);
                        });
                        this.inspectorTarget.querySelector('[data-insp-rename]')?.addEventListener('click', () => {
                            this.openRenameModal(activeItem.path, activeItem.name);
                        });
                        this.inspectorTarget.querySelector('[data-insp-delete]')?.addEventListener('click', () => {
                            this.openDeleteModal([activeItem.path], activeItem.name);
                        });
                    }

                    bulkMove() {
                        const entries = Array.from(this.selectedMap.values());
                        if (entries.length === 0) {
                            return;
                        }
                        const paths = entries.map((e) => e.path);
                        const hasFolder = entries.some((e) => e.kind === 'folder');
                        this.openMoveModal(paths, `${paths.length}`, hasFolder);
                    }

                    bulkDelete() {
                        const entries = Array.from(this.selectedMap.values());
                        if (entries.length > 0) {
                            const paths = entries.map((e) => e.path);
                            this.openDeleteModal(paths, entries[0]?.name || paths[0]);
                        }
                    }

    async downloadZip() {
                        const paths = Array.from(this.selectedMap.keys());
                        if (this.zipDownloading || paths.length === 0 || !this.zipUrlValue) {
                            return;
                        }
                        this.zipDownloading = true;
                        this.setLoading(true);
                        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                        this.zipAbortController = controller;
                        try {
                            const res = await fetch(this.zipUrlValue, {
                                method: 'POST',
                                headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                                signal: controller ? controller.signal : undefined,
                                body: JSON.stringify({
                                    profile: this.profileValue,
                                    volume: this.volumeValue,
                                    locale: this.localeValue || '',
                                    paths,
                                }),
                            });
                            if (this.isDisconnected || controller?.signal?.aborted) {
                                return;
                            }
                            if (!res.ok) {
                                const errData = await res.json().catch(() => ({}));
                                if (!this.isDisconnected) {
                                    this.showStatus(errData.error || this.t('errors.zip_failed'), 'danger');
                                }
                                return;
                            }
                            const blob = await res.blob();
                            if (this.isDisconnected || controller?.signal?.aborted) {
                                return;
                            }
                            const blobUrl = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = blobUrl;
                            a.download = `files-${Date.now()}.zip`;
                            a.click();
                            setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
                        } catch (err) {
                            if (err?.name !== 'AbortError' && !this.isDisconnected) {
                                this.showStatus(this.t('errors.network'), 'danger');
                            }
                        } finally {
                            if (this.zipAbortController === controller) {
                                this.zipAbortController = null;
                            }
                            this.zipDownloading = false;
                            if (!this.isDisconnected) {
                                this.setLoading(false);
                            }
                        }
                    }

                    triggerUpload() {
                        this.hasFileInputTarget && this.fileInputTarget.click();
                    }

                    onFileInputChange(event) {
                        const files = Array.from(event.target.files || []);
                        if (files.length > 0) {
                            const target = typeof this.uploadTargetDir === 'string' ? this.uploadTargetDir : this.pathValue;
                            if (!this.isModalOpen()) {
                                this.uploadTargetDir = null;
                            }
                            this.enqueueFiles(files, target);
                            event.target.value = '';
                        }
                    }

                    isExternalFileDrag(event) {
                        if (this.dragging || this.trashMode) {
                            return false;
                        }
                        if (!this.capabilitiesValue?.upload || this.capabilitiesValue?.allowDropzoneUpload === false) {
                            return false;
                        }
                        const types = Array.from(event.dataTransfer?.types || []);
                        return types.includes('Files');
                    }

                    showDropOverlay(targetDir = this.pathValue, targetVolume = this.volumeValue) {
                        if (!this.hasDropOverlayTarget) {
                            return;
                        }
                        const volName = targetVolume || this.volumeValue;
                        const volLabel = volName === this.volumeValue ? (this.volumeLabelValue || volName) : volName;
                        const label = targetDir
                            ? (volName && volName !== this.volumeValue ? `${volLabel}: /${targetDir}` : `/${targetDir}`)
                            : `/ (${volLabel})`;
                        if (this.hasDropOverlayPathTarget) {
                            this.dropOverlayPathTarget.textContent = this.t('dropzone.target', { path: label });
                        }
                        this.dropOverlayTarget.classList.remove('liveux-fm-hidden');
                    }

                    hideDropOverlay() {
                        this.externalDragDepth = 0;
                        if (this.hasDropOverlayTarget) {
                            this.dropOverlayTarget.classList.add('liveux-fm-hidden');
                        }
                        if (this.hasContentAreaTarget) {
                            this.contentAreaTarget.classList.remove('liveux-fm-drop-target');
                        }
                    }

                    onDropZoneDragEnter(event) {
                        if (!this.isExternalFileDrag(event)) {
                            return;
                        }
                        event.preventDefault();
                        this.externalDragDepth += 1;
                        this.showDropOverlay(this.pathValue);
                    }

                    onDropZoneDragOver(event) {
                        if (!this.isExternalFileDrag(event)) {
                            return;
                        }
                        event.preventDefault();
                        if (event.dataTransfer) {
                            event.dataTransfer.dropEffect = 'copy';
                        }
                        this.showDropOverlay(this.pathValue);
                    }

                    onDropZoneDragLeave() {
                        if (this.externalDragDepth <= 0) {
                            return;
                        }
                        this.externalDragDepth = Math.max(0, this.externalDragDepth - 1);
                        if (this.externalDragDepth === 0) {
                            this.hideDropOverlay();
                        }
                    }

                    onDropZoneDrop(event) {
                        const shouldHandle = this.isExternalFileDrag(event);
                        this.hideDropOverlay();
                        if (!shouldHandle) {
                            return;
                        }
                        event.preventDefault();
                        this.handleDataTransferDrop(event.dataTransfer, this.pathValue);
                    }

    async handleDataTransferDrop(dataTransfer, baseTargetDir, targetVolume = this.volumeValue) {
                        if (!dataTransfer) {
                            return;
                        }
                        const resolvedVol = targetVolume || this.volumeValue || '';
                        const items = Array.from(dataTransfer.items || []);
                        const entries = items
                            .map((it) => (typeof it.webkitGetAsEntry === 'function' ? it.webkitGetAsEntry() : null))
                            .filter(Boolean);

                        // Graceful fallback when FileSystemEntry API is unavailable (older browsers)
                        if (entries.length === 0) {
                            const files = Array.from(dataTransfer.files || []);
                            if (files.length > 0) {
                                this.enqueueFiles(files, baseTargetDir, resolvedVol);
                            }
                            return;
                        }

                        const hasDirectory = entries.some((entry) => entry.isDirectory);
                        if (!hasDirectory) {
                            const files = Array.from(dataTransfer.files || []);
                            if (files.length > 0) {
                                this.enqueueFiles(files, baseTargetDir, resolvedVol);
                            }
                            return;
                        }

                        const MAX_SCAN_FILES = 500;
                        const MAX_SCAN_DIRS = 250;
                        const MAX_SCAN_DEPTH = 10;
                        const dirsToCreate = new Set();
                        const collectedFiles = [];
                        let scanTruncated = false;

                        const readDirBatch = (reader) => new Promise((resolve) => {
                            reader.readEntries((batch) => resolve(batch || []), () => resolve([]));
                        });

                        const readFileEntry = (fileEntry) => new Promise((resolve) => {
                            fileEntry.file((file) => resolve(file), () => resolve(null));
                        });

                        const joinRel = (a, b) => [a, b].filter(Boolean).join('/');
                        const cleanBaseDir = String(baseTargetDir || '').replace(/^\/+|\/+$/g, '');

                        const walk = async (entry, relParent, depth) => {
                            if (this.isDisconnected || depth > MAX_SCAN_DEPTH) {
                                return;
                            }
                            const cleanName = String(entry.name || '').trim();
                            if (!cleanName || cleanName.startsWith('.')) {
                                return;
                            }
                            if (collectedFiles.length >= MAX_SCAN_FILES) {
                                scanTruncated = true;
                                return;
                            }
                            if (entry.isFile) {
                                const file = await readFileEntry(entry);
                                if (this.isDisconnected) {
                                    return;
                                }
                                if (file) {
                                    if (collectedFiles.length >= MAX_SCAN_FILES) {
                                        scanTruncated = true;
                                        return;
                                    }
                                    collectedFiles.push({ file, relDir: relParent });
                                }
                                return;
                            }
                            if (entry.isDirectory) {
                                if (dirsToCreate.size >= MAX_SCAN_DIRS) {
                                    scanTruncated = true;
                                    return;
                                }
                                const nextRel = joinRel(relParent, cleanName);
                                dirsToCreate.add(nextRel);
                                if (!this.capabilitiesValue?.createFolder) {
                                    return;
                                }
                                const reader = entry.createReader();
                                while (!scanTruncated && !this.isDisconnected) {
                                    const batch = await readDirBatch(reader);
                                    if (this.isDisconnected || batch.length === 0) {
                                        break;
                                    }
                                    for (const child of batch) {
                                        await walk(child, nextRel, depth + 1);
                                        if (scanTruncated || this.isDisconnected) {
                                            break;
                                        }
                                    }
                                }
                            }
                        };

                        for (const entry of entries) {
                            await walk(entry, '', 0);
                            if (scanTruncated || this.isDisconnected) {
                                break;
                            }
                        }
                        if (this.isDisconnected) {
                            return;
                        }

                        if (dirsToCreate.size > 0 && !this.capabilitiesValue?.createFolder) {
                            const errKey = (this.i18nValue && Object.prototype.hasOwnProperty.call(this.i18nValue, 'errors.access_denied'))
                                ? 'errors.access_denied'
                                : (this.i18nValue && Object.prototype.hasOwnProperty.call(this.i18nValue, 'errors.action_failed')
                                    ? 'errors.action_failed'
                                    : 'errors.access_denied');
                            this.showStatus(this.t(errKey), 'danger');
                            return;
                        }

                        const resolvedDirs = new Map([['', cleanBaseDir]]);
                        const failedDirs = new Set();
                        let firstFolderError = '';
                        const isFailedRelDir = (dir) => {
                            if (!dir) {
                                return false;
                            }
                            if (failedDirs.has(dir)) {
                                return true;
                            }
                            for (const failed of failedDirs) {
                                if (dir.startsWith(`${failed}/`)) {
                                    return true;
                                }
                            }
                            return false;
                        };

                        if (dirsToCreate.size > 0) {
                            const sortedDirs = Array.from(dirsToCreate).sort((a, b) => a.split('/').length - b.split('/').length);
                            for (const relDir of sortedDirs) {
                                if (this.isDisconnected) {
                                    return;
                                }
                                const slashIdx = relDir.lastIndexOf('/');
                                const rawParentRel = slashIdx >= 0 ? relDir.slice(0, slashIdx) : '';
                                const leafSegment = slashIdx >= 0 ? relDir.slice(slashIdx + 1) : relDir;
                                if (!leafSegment || isFailedRelDir(rawParentRel) || !resolvedDirs.has(rawParentRel)) {
                                    failedDirs.add(relDir);
                                    continue;
                                }
                                const resolvedParent = resolvedDirs.get(rawParentRel);
                                this.lastActionError = null;
                                const res = await this.callActionJson(
                                    'createFolder',
                                    { volume: resolvedVol, parentPath: resolvedParent, name: leafSegment, ifNotExists: true },
                                    { silent: true },
                                );
                                if (!res || res.ok === false || typeof res.path !== 'string' || res.path.trim() === '') {
                                    if (!firstFolderError) {
                                        firstFolderError = (res && typeof res.error === 'string' && res.error)
                                            || this.lastActionError
                                            || this.t('errors.action_failed');
                                    }
                                    failedDirs.add(relDir);
                                    continue;
                                }
                                const createdPath = res.path.trim().replace(/^\/+|\/+$/g, '');
                                resolvedDirs.set(relDir, createdPath);
                            }
                        }

                        if (this.isDisconnected) {
                            return;
                        }
                        const uploadableFiles = collectedFiles.filter(({ relDir }) => !isFailedRelDir(relDir) && resolvedDirs.has(relDir));
                        if (uploadableFiles.length > 0) {
                            const grouped = new Map();
                            uploadableFiles.forEach(({ file, relDir }) => {
                                const targetDir = resolvedDirs.get(relDir);
                                if (!grouped.has(targetDir)) {
                                    grouped.set(targetDir, []);
                                }
                                grouped.get(targetDir).push(file);
                            });
                            grouped.forEach((groupFiles, dir) => {
                                this.enqueueFiles(groupFiles, dir, resolvedVol);
                            });
                        } else if (dirsToCreate.size > failedDirs.size) {
                            this.invalidateClientCaches();
                            await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
                            if (this.isDisconnected) {
                                return;
                            }
                        }

                        const hasTruncKey = Boolean(this.i18nValue && Object.prototype.hasOwnProperty.call(this.i18nValue, 'errors.folder_drop_truncated'));
                        const truncMsg = scanTruncated
                            ? (hasTruncKey
                                ? this.t('errors.folder_drop_truncated', { '%max%': MAX_SCAN_FILES })
                                : `Folder drop truncated at ${MAX_SCAN_FILES} files.`)
                            : '';
                        if (failedDirs.size > 0) {
                            const baseErr = firstFolderError || this.t('errors.action_failed');
                            const errSummary = failedDirs.size > 1 ? `${baseErr} (${failedDirs.size})` : baseErr;
                            this.showStatus(truncMsg ? `${errSummary} • ${truncMsg}` : errSummary, 'danger');
                        } else if (truncMsg) {
                            this.showStatus(truncMsg, 'warning');
                        }
                    }

                    formatImageConstraintsHint() {
                        const c = this.imageConstraintsValue || {};
                        const parts = [];
                        if (c.minWidth || c.minHeight) {
                            parts.push(`${this.t('ui.min_label')} ${c.minWidth || 0}×${c.minHeight || 0}px`);
                        }
                        if (c.maxWidth || c.maxHeight) {
                            parts.push(`${this.t('ui.max_label')} ${c.maxWidth || '∞'}×${c.maxHeight || '∞'}px`);
                        }
                        if (c.aspectRatio) {
                            parts.push(String(c.aspectRatio));
                        }
                        if (parts.length === 0) {
                            return '';
                        }
                        return this.t('modals.upload_image_constraints', { rules: parts.join(', ') });
                    }

                    hasActiveImageConstraints() {
                        const c = this.imageConstraintsValue || {};
                        return Boolean(c.minWidth || c.minHeight || c.maxWidth || c.maxHeight || c.aspectRatio);
                    }

                    parseAspectRatioValue(raw) {
                        const m = String(raw || '').trim().match(/^(\d+(?:\.\d+)?)\s*[:/]\s*(\d+(?:\.\d+)?)$/);
                        if (!m) {
                            return null;
                        }
                        const w = parseFloat(m[1]);
                        const h = parseFloat(m[2]);
                        return w > 0 && h > 0 ? (w / h) : null;
                    }

                    readClientImageDimensions(file) {
                        return new Promise((resolve) => {
                            if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function' || typeof Image === 'undefined') {
                                resolve(null);
                                return;
                            }
                            const url = URL.createObjectURL(file);
                            const img = new Image();
                            const done = (dims) => {
                                try { URL.revokeObjectURL(url); } catch (_) {}
                                resolve(dims);
                            };
                            img.onload = () => done({ width: img.naturalWidth || 0, height: img.naturalHeight || 0 });
                            img.onerror = () => done(null);
                            img.src = url;
                        });
                    }

                    async checkClientImageConstraints(file) {
                        if (!this.hasActiveImageConstraints()) {
                            return null;
                        }
                        const rasterTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp'];
                        if (!file?.type || !rasterTypes.includes(file.type.toLowerCase())) {
                            return null;
                        }
                        const dims = await this.readClientImageDimensions(file);
                        if (!dims || dims.width <= 0 || dims.height <= 0) {
                            return null;
                        }
                        const c = this.imageConstraintsValue || {};
                        if ((c.minWidth && dims.width < c.minWidth) || (c.minHeight && dims.height < c.minHeight)) {
                            return this.t('errors.image_too_small', {
                                name: file.name,
                                width: dims.width,
                                height: dims.height,
                                minWidth: c.minWidth || 0,
                                minHeight: c.minHeight || 0,
                            });
                        }
                        if (!c.imageOptEnabled && ((c.maxWidth && dims.width > c.maxWidth) || (c.maxHeight && dims.height > c.maxHeight))) {
                            return this.t('errors.image_too_large_dim', {
                                name: file.name,
                                width: dims.width,
                                height: dims.height,
                                maxWidth: c.maxWidth || '∞',
                                maxHeight: c.maxHeight || '∞',
                            });
                        }
                        if (c.aspectRatio) {
                            const expected = this.parseAspectRatioValue(c.aspectRatio);
                            const tol = typeof c.aspectRatioTolerance === 'number' ? c.aspectRatioTolerance : 0.05;
                            if (expected && Math.abs((dims.width / dims.height) - expected) / expected > tol) {
                                return this.t('errors.image_aspect_ratio', {
                                    name: file.name,
                                    width: dims.width,
                                    height: dims.height,
                                    expected: c.aspectRatio,
                                });
                            }
                        }
                        return null;
                    }

                    matchesMimePatternList(mime, patterns) {
                        const normalizedMime = String(mime ?? '').split(';')[0].trim().toLowerCase();
                        if (!normalizedMime || !Array.isArray(patterns) || patterns.length === 0) {
                            return false;
                        }
                        for (const rawPattern of patterns) {
                            const pattern = String(rawPattern ?? '').split(';')[0].trim().toLowerCase();
                            if (!pattern) {
                                continue;
                            }
                            if (pattern === '*/*' || pattern === normalizedMime) {
                                return true;
                            }
                            if (pattern.endsWith('/*') && normalizedMime.startsWith(pattern.slice(0, -1))) {
                                return true;
                            }
                        }
                        return false;
                    }

                    async enqueueFiles(files, targetDir, targetVolume = this.volumeValue) {
                        if (this.isDisconnected) {
                            return;
                        }
                        const jobVolume = targetVolume || this.volumeValue || '';
                        for (const file of files) {
                            if (this.isDisconnected) {
                                return;
                            }
                            const jobId = `up_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

                            // Instant 0ms client-side pre-flight size validation (saves bandwidth & server load)
                            if (this.uploadMaxSizeValue > 0 && file.size > this.uploadMaxSizeValue) {
                                const msg = this.t('errors.file_too_large', {
                                    name: file.name,
                                    actual: this.formatBytes(file.size),
                                    max: this.uploadMaxSizeLabelValue || this.formatBytes(this.uploadMaxSizeValue),
                                });
                                this.uploadJobs.push({
                                    id: jobId, file, volume: jobVolume, targetDir, chunkIndex: 0, totalChunks: 1,
                                    status: 'error', retryCount: 0, retrying: false,
                                    paused: false, cancelled: false, error: msg, preFlightError: true,
                                });
                                this.showStatus(msg, 'danger');
                                continue;
                            }

                            // Instant 0ms client-side MIME hint check (server still enforces magic bytes)
                            if (file.type) {
                                const denied = this.matchesMimePatternList(file.type, this.uploadDenyValue);
                                const allowList = Array.isArray(this.uploadAllowValue) ? this.uploadAllowValue : [];
                                const notAllowed = allowList.length > 0 && !this.matchesMimePatternList(file.type, allowList);
                                if (denied || notAllowed) {
                                    const msg = this.t('errors.file_type_denied', { name: file.name, type: file.type });
                                    this.uploadJobs.push({
                                        id: jobId, file, volume: jobVolume, targetDir, chunkIndex: 0, totalChunks: 1,
                                        status: 'error', retryCount: 0, retrying: false,
                                        paused: false, cancelled: false, error: msg, preFlightError: true,
                                    });
                                    this.showStatus(msg, 'danger');
                                    continue;
                                }
                            }

                            // Optional client-side image resolution & aspect ratio pre-flight check
                            const imgErr = await this.checkClientImageConstraints(file);
                            if (this.isDisconnected) {
                                return;
                            }
                            if (imgErr) {
                                this.uploadJobs.push({
                                    id: jobId, file, volume: jobVolume, targetDir, chunkIndex: 0, totalChunks: 1,
                                    status: 'error', retryCount: 0, retrying: false,
                                    paused: false, cancelled: false, error: imgErr, preFlightError: true,
                                });
                                this.showStatus(imgErr, 'danger');
                                continue;
                            }

                            const effectiveChunk = Math.max(262144, this.chunkSizeValue || 2097152);
                            const totalChunks = this.chunkEnabledValue ? Math.max(1, Math.ceil(file.size / effectiveChunk)) : 1;
                            const job = {
                                id: jobId,
                                file, volume: jobVolume, targetDir, chunkIndex: 0, totalChunks, chunkSize: effectiveChunk,
                                status: 'queued', retryCount: 0, retrying: false,
                                paused: false, cancelled: false, abortController: null,
                                error: null, preFlightError: false,
                            };
                            this.uploadJobs.push(job);
                        }
                        if (this.isDisconnected) {
                            return;
                        }
                        this.renderUploadQueue();
                        this.pumpUploadQueue();
                    }

                    pumpUploadQueue() {
                        if (this.isDisconnected) {
                            return;
                        }
                        const limit = Math.max(1, Math.min(10, Number(this.parallelUploadsValue) || 3));
                        while (this.activeUploads < limit) {
                            const nextJob = this.uploadJobs.find((j) => (
                                j.status === 'queued'
                                && !j.paused
                                && !j.cancelled
                                && !j.error
                                && (j.activeWorkers || 0) === 0
                            ));
                            if (!nextJob) {
                                break;
                            }
                            nextJob.status = 'uploading';
                            this.activeUploads += 1;
                            this.processUploadJob(nextJob);
                        }
                    }

                    pauseUploadJob(jobId) {
                        const job = this.uploadJobs.find((j) => j.id === jobId);
                        if (!job || job.cancelled || job.error || job.paused) {
                            return;
                        }
                        job.paused = true;
                        job.status = 'paused';
                        job.retrying = false;
                        job.runToken = (job.runToken || 0) + 1;
                        if (job.retryTimer) {
                            clearTimeout(job.retryTimer);
                            job.retryTimer = null;
                        }
                        if (typeof job.retryResolve === 'function') {
                            const r = job.retryResolve;
                            job.retryResolve = null;
                            r();
                        }
                        if (job.abortController) {
                            try { job.abortController.abort(); } catch (_) { }
                            job.abortController = null;
                        }
                        this.renderUploadQueue();
                    }

                    resumeUploadJob(jobId) {
                        const job = this.uploadJobs.find((j) => j.id === jobId);
                        if (!job || job.cancelled) {
                            return;
                        }
                        job.paused = false;
                        job.error = null;
                        job.retryCount = 0;
                        job.retrying = false;
                        job.status = 'queued';
                        this.renderUploadQueue();
                        this.pumpUploadQueue();
                    }

                    cancelUploadJob(jobId) {
                        const job = this.uploadJobs.find((j) => j.id === jobId);
                        if (!job) {
                            return;
                        }
                        const shouldCleanupServer = job.totalChunks > 1
                            && !job.preFlightError
                            && (job.chunkIndex > 0 || job.status === 'uploading' || job.status === 'paused' || Boolean(job.error));
                        job.cancelled = true;
                        job.paused = false;
                        job.status = 'cancelled';
                        job.runToken = (job.runToken || 0) + 1;
                        if (job.retryTimer) {
                            clearTimeout(job.retryTimer);
                            job.retryTimer = null;
                        }
                        if (typeof job.retryResolve === 'function') {
                            const r = job.retryResolve;
                            job.retryResolve = null;
                            r();
                        }
                        if (job.abortController) {
                            try { job.abortController.abort(); } catch (_) { }
                            job.abortController = null;
                        }
                        this.uploadJobs = this.uploadJobs.filter((j) => j.id !== jobId);
                        this.renderUploadQueue();

                        if (shouldCleanupServer && this.actionUrlValue) {
                            const abortPayload = { uploadId: job.id };
                            if (job.volume && job.volume !== this.volumeValue) {
                                abortPayload.volume = job.volume;
                            }
                            if (job.targetDir) {
                                abortPayload.path = job.targetDir;
                            }
                            this.callAction('abortUpload', abortPayload, { silent: true });
                        }
                    }

                    pauseAllUploads() {
                        this.uploadJobs.forEach((job) => {
                            if (!job.cancelled && !job.error && !job.paused && (job.totalChunks > 1 || job.status === 'queued')) {
                                this.pauseUploadJob(job.id);
                            }
                        });
                    }

                    cancelAllUploads() {
                        const ids = this.uploadJobs.map((j) => j.id);
                        ids.forEach((id) => this.cancelUploadJob(id));
                    }

                    confirmCancelUploadJob(jobId) {
                        const job = this.uploadJobs.find((j) => j.id === jobId);
                        if (!job) {
                            return;
                        }
                        // Already failed / pre-flight error rows dismiss immediately without confirmation
                        if (job.error) {
                            this.cancelUploadJob(jobId);
                            return;
                        }
                        const message = this.t('modals.cancel_upload_single', { name: job.file.name });
                        this.openCancelUploadConfirmModal(message, () => this.cancelUploadJob(jobId));
                    }

                    confirmCancelAllUploads() {
                        const activeCount = this.uploadJobs.filter((j) => !j.error && !j.cancelled).length;
                        if (activeCount === 0) {
                            this.cancelAllUploads();
                            return;
                        }
                        const message = this.t('modals.cancel_upload_all', { count: activeCount });
                        this.openCancelUploadConfirmModal(message, () => this.cancelAllUploads());
                    }

                    openCancelUploadConfirmModal(message, onConfirm) {
                        if (!this.hasModalBackdropTarget || !this.hasModalBodyTarget) {
                            onConfirm();
                            return;
                        }

                        const wasInUploadModal = Boolean(this.modalBodyTarget.querySelector('[data-upload-modal-queue]'));
                        const prevTargetDir = typeof this.uploadTargetDir === 'string' ? this.uploadTargetDir : this.pathValue;
                        const finishModal = () => {
                            if (wasInUploadModal && this.uploadJobs.length > 0) {
                                this.openUploadModal(prevTargetDir);
                            } else {
                                this.closeModal();
                            }
                        };

                        this.openModal(this.t('modals.cancel_upload_title'), `
            <div class="liveux-fm-modal-body">
                <p class="liveux-fm-text-sm" style="margin: 0;">${this.escapeHtml(message)}</p>
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" style="padding: 5px 14px;" data-fm-keep-upload>${this.escapeHtml(this.t('actions.keep_uploading'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-danger" style="padding: 5px 14px;" data-fm-confirm-cancel-upload>${this.escapeHtml(this.t('actions.confirm_cancel_upload'))}</button>
            </div>
        `);

                        this.modalBodyTarget.querySelector('[data-fm-keep-upload]')?.addEventListener('click', () => {
                            if (wasInUploadModal) {
                                this.openUploadModal(prevTargetDir);
                            } else {
                                this.closeModal();
                            }
                        });
                        this.modalBodyTarget.querySelector('[data-fm-confirm-cancel-upload]')?.addEventListener('click', () => {
                            onConfirm();
                            finishModal();
                        });
                    }

    async processUploadJob(job) {
                        const chunkBytes = job.chunkSize || this.chunkSizeValue || 2097152;
                        const parsedRetries = Number(this.maxRetriesValue);
                        const maxRetries = Number.isFinite(parsedRetries) ? Math.max(0, parsedRetries) : 3;
                        const runToken = (job.runToken || 0) + 1;
                        job.runToken = runToken;
                        job.activeWorkers = (job.activeWorkers || 0) + 1;

                        try {
                            while (job.chunkIndex < job.totalChunks) {
                                if (job.cancelled || job.paused || job.runToken !== runToken) {
                                    return;
                                }
                                const start = job.chunkIndex * chunkBytes;
                                const end = job.totalChunks === 1 ? job.file.size : Math.min(job.file.size, start + chunkBytes);
                                const formData = new FormData();
                                formData.append('profile', this.profileValue);
                                formData.append('volume', job.volume || this.volumeValue || '');
                                formData.append('locale', this.localeValue || '');
                                formData.append('path', job.targetDir || '');
                                formData.append('uploadId', job.id);
                                formData.append('chunkIndex', String(job.chunkIndex));
                                formData.append('totalChunks', String(job.totalChunks));
                                formData.append('filename', job.file.name);
                                formData.append('file', job.file.slice(start, end), job.file.name);

                                let retryable = false;
                                let failureMessage = '';
                                const abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                                job.abortController = abortController;

                                try {
                                    const res = await fetch(this.uploadUrlValue, {
                                        method: 'POST',
                                        headers: this.csrfHeaders(),
                                        body: formData,
                                        signal: abortController ? abortController.signal : undefined,
                                    });
                                    const data = await res.json().catch(() => ({}));
                                    if (this.isDisconnected || job.cancelled) {
                                        return;
                                    }
                                    if (res.ok && data.ok) {
                                        const isFinalChunk = job.chunkIndex === job.totalChunks - 1;
                                        if (isFinalChunk && data.completed === false) {
                                            // Server lost prior chunks or failed to finalize: never discard silently.
                                            job.chunkIndex = 0;
                                            failureMessage = data.error || this.t('errors.upload_failed');
                                            retryable = true;
                                        } else {
                                            if (data.warning) {
                                                this.showStatus(data.warning, 'warning');
                                            }
                                            job.chunkIndex += 1;
                                            job.retryCount = 0;
                                            job.retrying = false;
                                            if (data.completed === true || job.chunkIndex >= job.totalChunks) {
                                                // Final chunk already committed server-side: complete even if paused mid-flight.
                                                this.uploadJobs = this.uploadJobs.filter((j) => j.id !== job.id);
                                                this.needsPostUploadRefresh = true;
                                                this.renderUploadQueue();
                                                return;
                                            }
                                            this.renderUploadQueue();
                                            if (this.isDisconnected || job.cancelled || job.paused || job.runToken !== runToken) {
                                                return;
                                            }
                                            continue;
                                        }
                                    }
                                    if (!failureMessage) {
                                        if (this.isDisconnected || job.cancelled || job.paused || job.runToken !== runToken) {
                                            return;
                                        }
                                        failureMessage = data.error || `${this.t('errors.upload_failed')} (HTTP ${res.status})`;
                                        retryable = res.status >= 500 || res.status === 429;
                                    }
                                } catch (_) {
                                    if (this.isDisconnected || job.cancelled || job.paused || job.runToken !== runToken) {
                                        return;
                                    }
                                    failureMessage = this.t('errors.upload_network');
                                    retryable = true;
                                } finally {
                                    if (job.abortController === abortController) {
                                        job.abortController = null;
                                    }
                                }

                                if (retryable && job.retryCount < maxRetries) {
                                    job.retryCount += 1;
                                    job.retrying = true;
                                    this.renderUploadQueue();
                                    const delayMs = 600 * Math.pow(2, job.retryCount - 1);
                                    await new Promise((resolve) => {
                                        job.retryResolve = resolve;
                                        job.retryTimer = setTimeout(() => {
                                            job.retryTimer = null;
                                            if (job.retryResolve === resolve) {
                                                job.retryResolve = null;
                                            }
                                            resolve();
                                        }, delayMs);
                                    });
                                    job.retrying = false;
                                    if (this.isDisconnected || job.cancelled || job.paused || job.runToken !== runToken) {
                                        return;
                                    }
                                    continue;
                                }

                                job.status = 'error';
                                job.retrying = false;
                                job.error = failureMessage;
                                this.showStatus(`${job.file.name} — ${failureMessage}`, 'danger');
                                this.renderUploadQueue();
                                return;
                            }

                            if (this.isDisconnected || job.cancelled || job.paused || job.runToken !== runToken) {
                                return;
                            }
                            this.uploadJobs = this.uploadJobs.filter((j) => j.id !== job.id);
                            this.needsPostUploadRefresh = true;
                            this.renderUploadQueue();
                        } finally {
                            if (job.runToken === runToken) {
                                job.abortController = null;
                            }
                            job.activeWorkers = Math.max(0, (job.activeWorkers || 1) - 1);
                            this.activeUploads = Math.max(0, this.activeUploads - 1);
                            this.pumpUploadQueue();
                            await this.maybeFlushPostUploadRefresh();
                        }
                    }

    async maybeFlushPostUploadRefresh() {
                        if (this.isDisconnected) {
                            return;
                        }
                        const hasPending = this.uploadJobs.some((j) => j.status === 'queued' || j.status === 'uploading');
                        if (this.activeUploads === 0 && !hasPending && this.needsPostUploadRefresh) {
                            this.needsPostUploadRefresh = false;
                            this.invalidateClientCaches();
                            if (this.trashMode) {
                                await this.refreshFullTree(true);
                                return;
                            }
                            await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue), this.refreshFullTree(true)]);
                        }
                    }

                    formatUploadJobProgress(job) {
                        const pct = Math.round((job.chunkIndex / Math.max(1, job.totalChunks)) * 100);
                        if (job.paused) {
                            return { pct, text: `${this.t('messages.upload_paused')} • ${pct}%` };
                        }
                        if (job.retrying) {
                            return { pct, text: `↻ ${job.retryCount}` };
                        }
                        const chunkLabel = job.totalChunks > 1 ? `${Math.min(job.chunkIndex + 1, job.totalChunks)}/${job.totalChunks} • ` : '';
                        return { pct, text: `${chunkLabel}${pct}%` };
                    }

                    ensureUploadQueueDelegation(container) {
                        if (!container || container.dataset.fmQueueBound === '1') {
                            return;
                        }
                        container.dataset.fmQueueBound = '1';
                        container.addEventListener('click', (event) => {
                            const btn = event.target?.closest?.('button');
                            if (!btn || !container.contains(btn)) {
                                return;
                            }
                            if (btn.hasAttribute('data-jobs-pause-all')) {
                                this.pauseAllUploads();
                            } else if (btn.hasAttribute('data-jobs-cancel-all')) {
                                this.confirmCancelAllUploads();
                            } else if (btn.dataset.jobPause) {
                                this.pauseUploadJob(btn.dataset.jobPause);
                            } else if (btn.dataset.jobResume) {
                                this.resumeUploadJob(btn.dataset.jobResume);
                            } else if (btn.dataset.jobRetry) {
                                this.resumeUploadJob(btn.dataset.jobRetry);
                            } else if (btn.dataset.jobCancel) {
                                this.confirmCancelUploadJob(btn.dataset.jobCancel);
                            }
                        });
                    }

                    syncUploadQueueContainer(container, html, structKey) {
                        this.ensureUploadQueueDelegation(container);

                        if (this.uploadJobs.length === 0) {
                            container.dataset.fmQueueSig = '';
                            container.innerHTML = '';
                            return;
                        }

                        const existingRows = container.dataset.fmQueueSig === structKey
                            ? Array.from(container.querySelectorAll('[data-job-row]'))
                            : [];

                        // Patch progress bar & status text in-place during chunk ticks so Pause/Cancel buttons
                        // are never detached from the DOM between mousedown and mouseup (instant 1-click response).
                        if (existingRows.length > 0) {
                            const rowMap = new Map();
                            existingRows.forEach((row) => {
                                if (row.dataset?.jobRow) {
                                    rowMap.set(row.dataset.jobRow, row);
                                }
                            });
                            let allMatched = true;
                            for (const job of this.uploadJobs) {
                                if (job.error) {
                                    continue;
                                }
                                const row = rowMap.get(job.id);
                                if (!row) {
                                    allMatched = false;
                                    break;
                                }
                                const { pct, text } = this.formatUploadJobProgress(job);
                                const bar = row.querySelector('[data-job-bar]');
                                const statusEl = row.querySelector('[data-job-status]');
                                if (bar) {
                                    bar.style.width = `${pct}%`;
                                }
                                if (statusEl) {
                                    statusEl.textContent = text;
                                }
                            }
                            if (allMatched) {
                                return;
                            }
                        }

                        container.innerHTML = html;
                        container.dataset.fmQueueSig = structKey;
                    }

                    renderUploadQueue() {
                        const activeOrQueued = this.uploadJobs.filter((j) => !j.error && !j.cancelled);
                        const canPauseAny = activeOrQueued.some((j) => !j.paused && (j.totalChunks > 1 || j.status === 'queued'));
                        const structKey = `${this.uploadJobs.length > 1 ? (canPauseAny ? 'multi-p' : 'multi') : 'single'}::` + this.uploadJobs.map((j) => {
                            if (j.error) {
                                return `${j.id}:${j.preFlightError ? 'preerr' : 'err'}:${j.error}`;
                            }
                            if (j.paused) {
                                return `${j.id}:paused`;
                            }
                            return `${j.id}:${(j.totalChunks > 1 || j.status === 'queued') ? 'pausable' : 'single'}`;
                        }).join('|');

                        const queueHeaderHtml = this.uploadJobs.length > 1
                            ? `<div class="liveux-fm-upload-queue-head">
                <span class="liveux-fm-muted liveux-fm-text-xs">${this.uploadJobs.length}</span>
                <div class="liveux-fm-row-sm">
                    ${canPauseAny ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-xs" data-jobs-pause-all>${this.escapeHtml(this.t('actions.pause_all'))}</button>` : ''}
                    <button type="button" class="liveux-fm-btn liveux-fm-btn-outline-danger liveux-fm-btn-xs" data-jobs-cancel-all>${this.escapeHtml(this.t('actions.cancel_all'))}</button>
                </div>
               </div>`
                            : '';

                        const html = queueHeaderHtml + this.uploadJobs.map((job) => {
                            const safeJobId = this.escapeAttr(job.id);
                            if (job.error) {
                                const retryBtn = !job.preFlightError
                                    ? `<button type="button" class="liveux-fm-btn liveux-fm-btn-outline-danger liveux-fm-btn-xs liveux-fm-shrink-0" data-job-retry="${safeJobId}">${this.escapeHtml(this.t('actions.retry'))}</button>`
                                    : '';
                                const cancelBtn = `<button type="button" class="liveux-fm-btn liveux-fm-btn-ghost liveux-fm-btn-xs liveux-fm-shrink-0" data-job-cancel="${safeJobId}" title="${this.escapeAttr(this.t('actions.cancel'))}" aria-label="${this.escapeAttr(this.t('actions.cancel'))}">✕</button>`;
                                const fullErrText = `${job.file.name} — ${job.error}`;
                                return `<div class="liveux-fm-inline-alert is-danger">
                    <span class="liveux-fm-break liveux-fm-minw-0" title="${this.escapeAttr(fullErrText)}"><strong>${this.escapeHtml(job.file.name)}</strong> — ${this.escapeHtml(job.error)}</span>
                    <span class="liveux-fm-row-sm liveux-fm-shrink-0">${retryBtn}${cancelBtn}</span>
                </div>`;
                            }

                            const { pct, text } = this.formatUploadJobProgress(job);
                            const canPause = job.totalChunks > 1 || job.status === 'queued';
                            let pauseResumeBtn = '';
                            if (job.paused) {
                                pauseResumeBtn = `<button type="button" class="liveux-fm-btn liveux-fm-btn-xs liveux-fm-upload-ctrl-btn" data-job-resume="${safeJobId}" title="${this.escapeAttr(this.t('actions.resume'))}" aria-label="${this.escapeAttr(this.t('actions.resume'))}">▶</button>`;
                            } else if (canPause) {
                                pauseResumeBtn = `<button type="button" class="liveux-fm-btn liveux-fm-btn-xs liveux-fm-upload-ctrl-btn" data-job-pause="${safeJobId}" title="${this.escapeAttr(this.t('actions.pause'))}" aria-label="${this.escapeAttr(this.t('actions.pause'))}">⏸</button>`;
                            }
                            const cancelBtn = `<button type="button" class="liveux-fm-btn liveux-fm-btn-outline-danger liveux-fm-btn-xs liveux-fm-upload-ctrl-btn" data-job-cancel="${safeJobId}" title="${this.escapeAttr(this.t('actions.cancel'))}" aria-label="${this.escapeAttr(this.t('actions.cancel'))}">✕</button>`;

                            return `<div class="liveux-fm-row liveux-fm-text-sm liveux-fm-upload-row ${job.paused ? 'is-paused' : ''}" data-job-row="${safeJobId}">
                <span class="liveux-fm-truncate" style="max-width:170px;" title="${this.escapeAttr(job.file.name)}">${this.escapeHtml(job.file.name)}</span>
                <div class="liveux-fm-progress"><div class="liveux-fm-progress-bar ${job.paused ? 'is-warning' : ''}" data-job-bar style="width:${pct}%"></div></div>
                <span class="liveux-fm-mono liveux-fm-text-xs liveux-fm-shrink-0" data-job-status>${this.escapeHtml(text)}</span>
                <span class="liveux-fm-row-sm liveux-fm-shrink-0">${pauseResumeBtn}${cancelBtn}</span>
            </div>`;
                        }).join('');

                        // Sync inside open Upload Modal if visible
                        const modalQueue = this.hasModalBodyTarget ? this.modalBodyTarget.querySelector('[data-upload-modal-queue]') : null;
                        if (modalQueue) {
                            this.syncUploadQueueContainer(modalQueue, html, structKey);
                        }

                        if (!this.hasUploadQueueTarget) {
                            return;
                        }
                        if (this.uploadJobs.length === 0) {
                            this.uploadQueueTarget.classList.add('liveux-fm-hidden');
                            this.syncUploadQueueContainer(this.uploadQueueTarget, '', '');
                            return;
                        }
                        this.uploadQueueTarget.classList.remove('liveux-fm-hidden');
                        this.syncUploadQueueContainer(this.uploadQueueTarget, html, structKey);
                    }

                    getSelectedPathSet() {
                        return this.selectedMap instanceof Map ? new Set(this.selectedMap.keys()) : new Set();
                    }

                    onItemDragStart(event) {
                        if (this.capabilitiesValue?.allowDragMove === false) {
                            event.preventDefault();
                            return;
                        }
                        const el = event.currentTarget;
                        const draggedPath = el.dataset.path || '';
                        const draggedName = el.dataset.name || '';
                        const selectedSet = this.getSelectedPathSet();
                        const isMultiDrag = Boolean(draggedPath && selectedSet.size > 1 && selectedSet.has(draggedPath));
                        const paths = isMultiDrag ? Array.from(selectedSet) : (draggedPath ? [draggedPath] : []);
                        const payload = { path: draggedPath, name: draggedName, paths, token: this.dragInstanceToken };
                        this.dragging = payload;
                        el.style.opacity = '0.5';
                        if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
                            const finishDrag = () => {
                                this.dragging = null;
                                this.hideDropOverlay();
                                el.style.opacity = '';
                                this.element?.querySelectorAll?.('.liveux-fm-drop-target')?.forEach((n) => n.classList.remove('liveux-fm-drop-target'));
                                document.removeEventListener('dragend', finishDrag);
                                document.removeEventListener('drop', finishDrag);
                            };
                            this.registerDragCleanup(() => {
                                document.removeEventListener('dragend', finishDrag);
                                document.removeEventListener('drop', finishDrag);
                            });
                            document.addEventListener('dragend', finishDrag);
                            document.addEventListener('drop', finishDrag);
                        }
                        if (event.dataTransfer) {
                            event.dataTransfer.effectAllowed = 'copyMove';
                            event.dataTransfer.setData('text/plain', paths.join('\n'));
                            event.dataTransfer.setData('application/x-liveux-fm', JSON.stringify(payload));
                        }
                    }

                    onItemDragEnd(event) {
                        if (event?.currentTarget) {
                            event.currentTarget.style.opacity = '';
                        }
                        this.dragging = null;
                        this.hideDropOverlay();
                        this.element.querySelectorAll('.liveux-fm-drop-target').forEach((n) => n.classList.remove('liveux-fm-drop-target'));
                    }

                    onFolderDragOver(event) {
                        if (this.trashMode || (!this.dragging && !this.isExternalFileDrag(event))) {
                            return;
                        }
                        event.preventDefault();
                        event.stopPropagation();
                        if (event.dataTransfer) {
                            const canDragCopy = this.capabilitiesValue?.copy !== false;
                            event.dataTransfer.dropEffect = !this.dragging || ((event.ctrlKey || event.altKey) && canDragCopy) ? 'copy' : 'move';
                        }
                        event.currentTarget.classList.add('liveux-fm-drop-target');
                        if (!this.dragging && this.isExternalFileDrag(event)) {
                            this.showDropOverlay(
                                event.currentTarget.dataset.folderPath ?? this.pathValue,
                                event.currentTarget.dataset.volume || this.volumeValue,
                            );
                        }
                    }

                    onFolderDragLeave(event) {
                        if (!event.currentTarget.contains(event.relatedTarget)) {
                            event.currentTarget.classList.remove('liveux-fm-drop-target');
                            if (!this.dragging) {
                                if (this.externalDragDepth > 0) {
                                    this.showDropOverlay(this.pathValue, this.volumeValue);
                                } else {
                                    this.hideDropOverlay();
                                }
                            }
                        }
                    }

    async onSidebarTreeDrop(event) {
                        return this.onFolderDrop(event);
                    }

    async onFolderDrop(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        event.currentTarget.classList.remove('liveux-fm-drop-target');
                        this.hideDropOverlay();

                        if (this.trashMode) {
                            return;
                        }

                        const targetDir = event.currentTarget.dataset.folderPath ?? '';
                        const targetVolume = event.currentTarget.dataset.volume || null;
                        const externalFiles = Array.from(event.dataTransfer?.files || []);

                        let dragItem = this.dragging ? { ...this.dragging } : null;
                        if (!dragItem && event.dataTransfer) {
                            const raw = event.dataTransfer.getData('application/x-liveux-fm');
                            if (raw) {
                                try {
                                    const parsed = JSON.parse(raw);
                                    if (
                                        parsed
                                        && typeof parsed === 'object'
                                        && parsed.token === this.dragInstanceToken
                                        && typeof parsed.path === 'string'
                                    ) {
                                        const parsedPaths = Array.isArray(parsed.paths)
                                            ? parsed.paths.filter((p) => typeof p === 'string' && p !== '')
                                            : (parsed.path ? [parsed.path] : []);
                                        dragItem = {
                                            path: parsed.path,
                                            name: typeof parsed.name === 'string' ? parsed.name : '',
                                            paths: parsedPaths,
                                        };
                                    }
                                } catch (_) { }
                            }
                        }

                        const hasExternalFiles = externalFiles.length > 0 || Array.from(event.dataTransfer?.types || []).includes('Files');
                        if (hasExternalFiles && !dragItem) {
                            if (this.capabilitiesValue?.upload && this.capabilitiesValue?.allowDropzoneUpload !== false) {
                                this.handleDataTransferDrop(event.dataTransfer, targetDir, targetVolume || this.volumeValue);
                            }
                            return;
                        }

                        if (this.capabilitiesValue?.allowDragMove === false) {
                            return;
                        }

                        if (!dragItem || (!dragItem.path && (!Array.isArray(dragItem.paths) || dragItem.paths.length === 0))) {
                            return;
                        }

                        const isCopy = Boolean((event.ctrlKey || event.altKey) && this.capabilitiesValue?.copy !== false);
                        const sourceVolume = this.volumeValue;
                        const sameVolume = !targetVolume || targetVolume === sourceVolume;
                        const rawPaths = Array.isArray(dragItem.paths) && dragItem.paths.length > 0
                            ? dragItem.paths
                            : [dragItem.path];
                        const validPaths = rawPaths.filter((p) => {
                            if (!p) {
                                return false;
                            }
                            if (sameVolume && (p === targetDir || targetDir.startsWith(`${p}/`))) {
                                return false;
                            }
                            if (!isCopy && sameVolume) {
                                const currentParent = p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
                                if (currentParent === targetDir) {
                                    return false;
                                }
                            }
                            return true;
                        });

                        this.dragging = null;
                        if (validPaths.length === 0) {
                            return;
                        }

                        const isBulk = validPaths.length > 1 || rawPaths.length > 1;
                        const executeMove = async () => {
                            const ok = isBulk
                                ? await this.callAction('bulkMove', {
                                    paths: validPaths,
                                    targetDir,
                                    isCopy,
                                    targetVolume,
                                    volume: sourceVolume,
                                })
                                : await this.callAction('move', {
                                    sourcePath: validPaths[0],
                                    targetDir,
                                    isCopy,
                                    targetVolume,
                                    volume: sourceVolume,
                                });
                            if (ok) {
                                if (!isCopy) {
                                    this.pruneClipboardPaths(validPaths, sourceVolume);
                                }
                                this.clearSelection();
                                this.invalidateClientCaches();
                                await Promise.all([this.fetchDirectory(this.volumeValue, this.pathValue, false, false, true), this.refreshFullTree(true)]);
                                this.syncActiveTreeNode(false);
                            }
                        };

                        if (!this.confirmMoveValue) {
                            await executeMove();
                            return;
                        }

                        const resolvedTargetVol = targetVolume || sourceVolume;
                        const targetVolLabel = (resolvedTargetVol && resolvedTargetVol !== this.volumeValue)
                            ? (this.findVolumeLabel?.(resolvedTargetVol) || resolvedTargetVol)
                            : (this.volumeLabelValue || resolvedTargetVol);
                        const targetLabel = targetDir ? `/${targetDir}` : `/ (${targetVolLabel})`;
                        const itemLabel = validPaths.length > 1
                            ? `${validPaths.length} ${this.t('inspector.selected_count')}`
                            : (dragItem.name || validPaths[0]);
                        const msg = this.t(isCopy ? 'modals.copy_confirm_message' : 'modals.move_confirm_message', {
                            name: itemLabel,
                            target: targetLabel,
                        });
                        const actionLabel = this.t(isCopy ? 'actions.copy_verb' : 'actions.move_verb');

                        this.openModal(this.t('modals.move_confirm_title'), `
            <div class="liveux-fm-modal-body">
                <div class="liveux-fm-path-bar">
                    ${this.renderFolderSvg(15, this.sanitizeHexColor(this.iconSetValue?.defaultFolderColor) || '#2563eb')}
                    <span>${this.escapeHtml(this.t('modals.target'))}:</span>
                    <code dir="ltr" title="${this.escapeAttr(targetLabel)}">${this.escapeHtml(targetLabel)}</code>
                </div>
                <p class="liveux-fm-text-sm" style="margin: 0;">${this.escapeHtml(msg)}</p>
            </div>
            <div class="liveux-fm-modal-footer">
                <button type="button" class="liveux-fm-btn" data-fm-cancel>${this.escapeHtml(this.t('actions.cancel'))}</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary" style="padding: 5px 14px;" data-fm-confirm-move>${this.escapeHtml(actionLabel)}</button>
            </div>
        `);

                        this.modalBodyTarget.querySelector('[data-fm-cancel]')?.addEventListener('click', () => this.closeModal());
                        this.modalBodyTarget.querySelector('[data-fm-confirm-move]')?.addEventListener('click', async () => {
                            this.closeModal();
                            await executeMove();
                        });
                    }

                    findVolumeLabel(volumeName) {
                        const target = String(volumeName || '');
                        if (!target) {
                            return '';
                        }
                        if (target === this.volumeValue && this.volumeLabelValue) {
                            return this.volumeLabelValue;
                        }
                        if (this.hasTreeTarget && typeof this.treeTarget.querySelectorAll === 'function') {
                            for (const node of this.treeTarget.querySelectorAll('[data-tree-node][data-volume]')) {
                                if (node.dataset?.volume === target && (node.dataset?.path || '') === '') {
                                    const txt = (node.textContent || '').trim();
                                    if (txt) {
                                        return txt;
                                    }
                                }
                            }
                        }
                        return target;
                    }

                    async copyTextToClipboard(text) {
                        const val = String(text ?? '');
                        if (!val) {
                            return false;
                        }
                        if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                            try {
                                await navigator.clipboard.writeText(val);
                                return true;
                            } catch (_) { }
                        }
                        const dialogEl = typeof this.element?.closest === 'function' ? this.element.closest('dialog') : null;
                        const mountParent = (dialogEl && typeof dialogEl.appendChild === 'function' ? dialogEl : null)
                            || (this.element && typeof this.element.appendChild === 'function' ? this.element : null)
                            || (typeof document !== 'undefined' ? document.body : null);
                        if (typeof document !== 'undefined' && typeof document.createElement === 'function' && mountParent && typeof document.execCommand === 'function') {
                            const ta = document.createElement('textarea');
                            ta.value = val;
                            ta.setAttribute('readonly', '');
                            ta.style.position = 'fixed';
                            ta.style.opacity = '0';
                            mountParent.appendChild(ta);
                            try {
                                ta.select();
                                return Boolean(document.execCommand('copy'));
                            } catch (_) {
                                return false;
                            } finally {
                                ta.remove?.();
                            }
                        }
                        return false;
                    }

                    onOutsideClick(event) {
                        if (this.hasContextMenuTarget && !this.contextMenuTarget.contains(event.target)) {
                            this.hideContextMenu();
                        }
                        if (this.hasSettingsPopoverTarget && !this.settingsPopoverTarget.contains(event.target)) {
                            this.closeSettingsPopover();
                        }
                    }

                    escapeHtml(str) {
                        return String(str ?? '')
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#39;');
                    }

                    formatBytes(bytes) {
                        const size = Number(bytes) || 0;
                        const fmtDec = (val, decimals) => {
                            if (decimals <= 0) {
                                return this.formatNumber(val);
                            }
                            const fmt = this.getNumberFormatter(decimals);
                            return fmt ? fmt.format(val) : val.toFixed(decimals);
                        };
                        if (size >= 1073741824) {
                            return `${fmtDec(size / 1073741824, 2)} ${this.unitLabel('units.gb', 'GB')}`;
                        }
                        if (size >= 1048576) {
                            return `${fmtDec(size / 1048576, 2)} ${this.unitLabel('units.mb', 'MB')}`;
                        }
                        if (size >= 1024) {
                            return `${fmtDec(size / 1024, 1)} ${this.unitLabel('units.kb', 'KB')}`;
                        }
                        return `${fmtDec(size, 0)} ${this.unitLabel('units.b', 'B')}`;
                    }

                    formatDate(timestamp) {
                        const raw = Number(timestamp);
                        if (!Number.isFinite(raw) || raw <= 0) {
                            return '—';
                        }
                        const ms = raw > 1e12 ? raw : raw * 1000;
                        const date = new Date(ms);
                        if (Number.isNaN(date.getTime())) {
                            return '—';
                        }
                        const fmt = this.getDateTimeFormatter('short', 'short', true);
                        if (fmt) {
                            try {
                                return fmt.format(date);
                            } catch (_) { }
                        }
                        const pad = (n) => String(n).padStart(2, '0');
                        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
                    }

                    sanitizeHexColor(val) {
                        const s = String(val ?? '').trim();
                        return /^#[0-9a-f]{6}$/i.test(s) ? s : '';
                    }

                    clampUnit(val) {
                        const n = Number(val);
                        if (!Number.isFinite(n)) {
                            return 0.5;
                        }
                        return Math.min(1, Math.max(0, n));
                    }

                    hasDangerousUrlSegments(rawUrl) {
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

                        const fillerRe = /[\u00AD\u034F\u115F\u1160\u17B4\u17B5\u180B-\u180F\u200B-\u200F\u2060-\u2064\u2066-\u206F\u2800\u3164\uFE00-\uFE0F\uFEFF\uFFA0\uFFF9-\uFFFB]|[\u{E0000}-\u{E007F}\u{E0100}-\u{E01EF}]/gu;
                        const decodeValidTriplets = (str) => str.replace(/(?:%[0-9a-fA-F]{2})+/g, (seq) => {
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

                    isSafeHttpOrPathUrl(url) {
                        const raw = String(url ?? '');
                        if (/[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/.test(raw)) {
                            return false;
                        }
                        const s = raw.trim();
                        if (!s || /(?:%0[0-9a-f]|%1[0-9a-f]|%7f|%25(?:0[0-9a-f]|1[0-9a-f]|7f))/i.test(s) || this.hasDangerousUrlSegments(s)) {
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

                    isSafeUrl(url) {
                        const raw = String(url ?? '');
                        if (/[\u0000-\u001F\u007F-\u009F\u200B\u200E\u200F\u2028-\u202E\u2060\u2066-\u2069\uFEFF\u061C]/.test(raw)) {
                            return false;
                        }
                        const s = raw.trim();
                        if (!s) {
                            return false;
                        }
                        if (this.isSafeHttpOrPathUrl(s)) {
                            return true;
                        }
                        return /^data:image\/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=]+$/i.test(s);
                    }

                    buildSafePdfPreviewUrl(item) {
                        // Prefer hardened DownloadController with inline=1 so finfo MIME verification,
                        // nosniff, and strict PDF CSP protect against polyglot HTML/PDF files on public disks.
                        if (item.downloadUrl && this.isSafeHttpOrPathUrl(item.downloadUrl)) {
                            const sep = item.downloadUrl.includes('?') ? '&' : '?';
                            return `${item.downloadUrl}${sep}inline=1`;
                        }
                        return this.isSafeHttpOrPathUrl(item.url) ? item.url : '';
                    }

                    sanitizeInlineSvg(rawSvg, size = 46) {
                        const source = String(rawSvg ?? '').trim();
                        if (!source) {
                            return '';
                        }
                        if (!this.svgSanitizeCache) {
                            this.svgSanitizeCache = new Map();
                        }
                        const safeSize = Math.max(8, Math.min(256, parseInt(size, 10) || 46));
                        const cacheKey = `${safeSize}::${source}`;
                        if (this.svgSanitizeCache.has(cacheKey)) {
                            return this.svgSanitizeCache.get(cacheKey);
                        }

                        if (typeof DOMParser === 'undefined' || typeof XMLSerializer === 'undefined') {
                            return '';
                        }

                        try {
                            const doc = new DOMParser().parseFromString(source, 'image/svg+xml');
                            const svg = doc.documentElement;
                            const SVG_NS = 'http://www.w3.org/2000/svg';
                            if (!svg || svg.localName !== 'svg' || svg.namespaceURI !== SVG_NS || doc.querySelector('parsererror')) {
                                return '';
                            }

                            // ALLOWLIST (not denylist): only plain SVG drawing elements in the SVG namespace survive.
                            // This blocks XHTML-namespaced "breakout" tags (<p>, <img>, <div> ...) that the HTML parser
                            // would hoist out of foreign content when the serialized markup is assigned via innerHTML.
                            const allowedTags = new Set(['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'title', 'desc', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'symbol']);
                            Array.from(svg.querySelectorAll('*'))
                                .filter((el) => el.namespaceURI !== SVG_NS || !allowedTags.has(String(el.localName || '').toLowerCase()))
                                .forEach((el) => el.remove());

                            // CDATA sections, comments and processing instructions are never needed in an icon and are the
                            // classic carrier for namespace-confusion (mXSS) payloads: drop every non-element, non-text node.
                            const walker = doc.createTreeWalker(svg, 0xFFFFFFFF);
                            const strayNodes = [];
                            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                                if (node.nodeType !== 1 && node.nodeType !== 3) {
                                    strayNodes.push(node);
                                }
                            }
                            strayNodes.forEach((node) => node.parentNode && node.parentNode.removeChild(node));

                            const cssPresentationAttrs = new Set([
                                'filter', 'fill', 'stroke', 'clip-path', 'mask', 'cursor',
                                'marker', 'marker-start', 'marker-mid', 'marker-end', 'background',
                            ]);
                            const uriAttrs = new Set(['href', 'xlink:href', 'src', 'srcset', 'imagesrcset', 'poster', 'srcdoc', 'action', 'formaction', 'ping']);
                            const allNodes = [svg, ...Array.from(svg.querySelectorAll('*'))];
                            allNodes.forEach((el) => {
                                for (const attr of Array.from(el.attributes)) {
                                    const name = attr.name.toLowerCase();
                                    const localName = String(attr.localName || name.split(':').pop() || '').toLowerCase();
                                    const val = attr.value.trim();
                                    if (
                                        name.startsWith('on')
                                        || localName.startsWith('on')
                                        || name.startsWith('data-')
                                        || localName.startsWith('data-')
                                        || name === 'style'
                                        || name === 'xml:base'
                                        || localName === 'base'
                                    ) {
                                        el.removeAttribute(attr.name);
                                        continue;
                                    }
                                    if (/javascript:|vbscript:|data:text\/html/i.test(attr.value.replace(/[\u0000-\u0020]+/g, ''))) {
                                        el.removeAttribute(attr.name);
                                        continue;
                                    }
                                    if (cssPresentationAttrs.has(name) || cssPresentationAttrs.has(localName)) {
                                        if (this.hasUnsafeSvgCss(attr.value)) {
                                            el.removeAttribute(attr.name);
                                            continue;
                                        }
                                    }
                                    if (uriAttrs.has(name) || uriAttrs.has(localName)) {
                                        if (name === 'srcdoc' || localName === 'srcdoc' || !/^#[a-zA-Z0-9_.:-]+$/.test(val)) {
                                            el.removeAttribute(attr.name);
                                        }
                                    }
                                }
                            });

                            svg.setAttribute('width', String(safeSize));
                            svg.setAttribute('height', String(safeSize));
                            const clean = new XMLSerializer().serializeToString(svg);
                            if (this.svgSanitizeCache) {
                                if (this.svgSanitizeCache.size >= 256) {
                                    const oldestKey = this.svgSanitizeCache.keys().next().value;
                                    if (oldestKey !== undefined) {
                                        this.svgSanitizeCache.delete(oldestKey);
                                    }
                                }
                                this.svgSanitizeCache.set(cacheKey, clean);
                            }
                            return clean;
                        } catch (_) {
                            return '';
                        }
                    }

                    hasUnsafeSvgCss(rawCss) {
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
                            if (/@import|javascript:|vbscript:|expression\(|-moz-binding|behavior:|data:text\/html|data:image\/svg|image-set\(|-webkit-image-set\(|cross-fade\(|-webkit-cross-fade\(|(?:-moz-)?element\(|data:/i.test(compact)) {
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

                    escapeAttr(str) {
                        return this.escapeHtml(str);
                    }
                }
