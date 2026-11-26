import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const assetsDir = path.resolve(__dirname, '../../assets');

async function loadStimulusController(relPath) {
    const absPath = path.join(assetsDir, relPath);
    const apiHref = pathToFileURL(path.join(assetsDir, 'js/file_manager_api.js')).href;
    const source = fs.readFileSync(absPath, 'utf8')
        .replace(
            /import\s*\{\s*Controller\s*\}\s*from\s*['"]@hotwired\/stimulus['"]\s*;/,
            'class Controller { constructor() { this.element = null; } }',
        )
        .replace(
            /from\s*['"]\.\.\/js\/file_manager_api\.js['"]/g,
            `from ${JSON.stringify(apiHref)}`,
        );
    const dataUrl = `data:text/javascript;base64,${Buffer.from(source, 'utf8').toString('base64')}`;
    const mod = await import(dataUrl);
    return mod.default;
}

function createFakeEl(tag = 'div') {
    const classes = new Set();
    const attrs = new Map();
    const el = {
        tagName: tag.toUpperCase(),
        innerHTML: '',
        textContent: '',
        value: '',
        title: '',
        dir: '',
        lang: '',
        disabled: false,
        isConnected: true,
        dataset: {},
        style: { setProperty() { } },
        classList: {
            add: (...c) => c.forEach((x) => classes.add(x)),
            remove: (...c) => c.forEach((x) => classes.delete(x)),
            toggle: (c, f) => (f === undefined ? (classes.has(c) ? classes.delete(c) : classes.add(c)) : (f ? classes.add(c) : classes.delete(c))),
            contains: (c) => classes.has(c),
        },
        setAttribute: (k, v) => {
            attrs.set(k, String(v));
            if (k === 'dir') el.dir = String(v);
            if (k === 'lang') el.lang = String(v);
        },
        getAttribute: (k) => attrs.get(k) ?? null,
        removeAttribute: (k) => attrs.delete(k),
        querySelector: () => null,
        querySelectorAll: () => [],
        replaceChildren: () => { },
        appendChild: () => { },
        addEventListener: () => { },
        removeEventListener: () => { },
        contains: () => false,
        closest: () => null,
        focus: () => { },
        scrollIntoView: () => { },
        dispatchEvent: () => true,
        open: false,
        showModal() { this.open = true; },
        close() { this.open = false; },
    };
    return el;
}

test('1. Stimulus auto-registration & WYSIWYG adapters work even without preloaded window.LiveUXFileManager', async () => {
    const dispatched = [];
    globalThis.window = {
        dispatchEvent: (ev) => {
            dispatched.push(ev.detail);
            ev.preventDefault();
            ev.detail.resolve([{ url: '/uploads/auto.jpg', path: 'auto.jpg', name: 'auto.jpg', isImage: true }]);
            return false;
        },
        addEventListener: () => { },
        removeEventListener: () => { },
    };

    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);

    const dlg = new DialogController();
    dlg.element = createFakeEl('div');
    dlg.connect();
    assert.equal(typeof globalThis.window.LiveUXFileManager?.open, 'function');

    // Delete window.LiveUXFileManager to verify direct openFileManager fallback in Picker & all 3 adapters
    delete globalThis.window.LiveUXFileManager;

    const picker = new PickerController();
    picker.profileValue = 'products';
    picker.returnTypeValue = 'url';
    picker.multipleValue = false;
    picker.hasInputTarget = true;
    picker.inputTarget = createFakeEl('input');
    picker.syncView = () => { };
    picker.dispatchChanged = () => { };
    await picker.open();
    assert.equal(picker.inputTarget.value, '/uploads/auto.jpg');

    let ckInserted = null;
    await ckMod.openFileManagerForCKEditor5({
        model: { change: () => { } },
        commands: { get: () => true },
        execute: (_cmd, args) => { ckInserted = args.source; },
    });
    assert.deepEqual(ckInserted, ['/uploads/auto.jpg']);

    let tinyPicked = null;
    await tinyMod.createTinyMceFilePicker()((url) => { tinyPicked = url; }, '', { filetype: 'image' });
    assert.equal(tinyPicked, '/uploads/auto.jpg');

    let tipPicked = null;
    await tipMod.insertFromFileManager({
        chain: () => ({
            focus() { return this; },
            setImage(args) { tipPicked = args.src; return this; },
            insertContent() { return this; },
            run() { },
        }),
    });
    assert.equal(tipPicked, '/uploads/auto.jpg');
    assert.equal(dispatched.length, 4);
});

test('2. Picker URL encoding & filename extraction handles banner #2.png, 50%off.png, spaces, +, and private /download?path=...', async () => {
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const p = new PickerController();

    // Raw relative paths (return_type: 'path'): literal % is allowed, while unencoded ? / # are rejected like PHP
    assert.equal(p.extractExtension('a.svg?x=1'), 'SVG');

    assert.equal(p.extractFilename('products/50%off.png'), '50%off.png');
    assert.equal(p.isImageValue('products/50%off.png'), true);

    assert.equal(p.extractFilename('products/summer + winter.webp'), 'summer + winter.webp');
    assert.equal(p.isImageValue('products/summer + winter.webp'), true);

    // previewSrc encodes %, spaces, + per path segment; unencoded ? and # in raw relative paths are blocked
    p.previewUrlPrefixValue = '/uploads';
    assert.equal(p.isSafeRelativePath('products/banner #2.png'), false);
    assert.equal(p.previewSrc('products/banner #2.png'), '');
    assert.equal(p.previewSrc('products/50%off.png'), '/uploads/products/50%25off.png');
    assert.equal(p.previewSrc('products/summer + winter.webp'), '/uploads/products/summer%20%2B%20winter.webp');
    assert.equal(p.isSafeRelativePath('products/a + b?1.jpg'), false);
    assert.equal(p.previewSrc('products/a + b?1.jpg'), '');
    p.previewUrlPrefixValue = 'https://cdn.example.com/assets';
    assert.equal(p.previewSrc('katalog/summer + winter.png'), 'https://cdn.example.com/assets/katalog/summer%20%2B%20winter.png');
    assert.equal(p.previewSrc('katalog/banner #2.png'), '');

    // Private volume controller URLs with ?path=...
    const privHash = '/_liveux-file-manager/download?profile=default&volume=docs&path=invoices%2Fbanner%20%232.png&inline=1';
    assert.equal(p.extractFilename(privHash), 'banner #2.png');
    assert.equal(p.isImageValue(privHash), true);

    const privPct = '/_liveux-file-manager/download?profile=default&volume=docs&path=invoices%2F50%25off.png&inline=1';
    assert.equal(p.extractFilename(privPct), '50%off.png');
    assert.equal(p.isImageValue(privPct), true);

    const privPlus = '/_liveux-file-manager/download?profile=default&volume=docs&path=invoices%2Fa+%2B+b.webp&inline=1';
    assert.equal(p.extractFilename(privPlus), 'a + b.webp');
    assert.equal(p.isImageValue(privPlus), true);

    // Malformed percent-encoding in ?path= never throws URIError
    const privMalformed = '/_liveux-file-manager/download?path=invoices%2Fbad%ZZname.png';
    assert.equal(p.extractFilename(privMalformed), 'bad%ZZname.png');
    assert.equal(p.isImageValue(privMalformed), true);
});

test('3. Private SVG prefers thumbUrl, Canvas Image Editor restricted to jpg/jpeg/png/webp & uses same-origin downloadUrl?inline=1', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const b = new BrowserController();
    b.i18nValue = { 'modals.edit_image_title': 'Edit', 'modals.file_meta_title': 'Meta', 'ui.auto_placeholder': 'auto' };
    b.capabilitiesValue = { editImage: true, editMetadata: true, download: true };
    b.imageEditUrlValue = '/_liveux-file-manager/image-edit';
    b.dirCache = new Map();
    b.selectedMap = new Map();
    b.volumeValue = 'priv';
    b.pathValue = '';

    const svgItem = {
        kind: 'file',
        name: 'logo.svg',
        path: 'logo.svg',
        extension: 'svg',
        isImage: true,
        url: '/_liveux-file-manager/download?profile=default&volume=priv&path=logo.svg&inline=1',
        downloadUrl: '/_liveux-file-manager/download?profile=default&volume=priv&path=logo.svg',
        thumbUrl: '/_liveux-file-manager/thumb?profile=default&volume=priv&path=logo.svg&v=1',
    };

    // Lightbox uses thumbUrl for SVG
    b.hasLightboxStageTarget = true;
    b.lightboxStageTarget = createFakeEl('div');
    b.lightboxItems = [svgItem];
    b.lightboxIndex = 0;
    await b.renderLightboxCurrent();
    assert.match(b.lightboxStageTarget.innerHTML, /\/_liveux-file-manager\/thumb\?/);

    // Metadata focal-point stage uses thumbUrl for SVG
    b.hasModalBackdropTarget = true;
    b.modalBackdropTarget = createFakeEl('div');
    b.modalTitleTarget = createFakeEl('h6');
    b.modalBodyTarget = createFakeEl('div');
    b.openFileMetadataModal(svgItem);
    assert.match(b.modalBodyTarget.innerHTML, /\/_liveux-file-manager\/thumb\?/);

    // Non-editable formats blocked from Edit Image
    for (const ext of ['svg', 'gif', 'avif', 'bmp', 'ico']) {
        assert.equal(b.isEditableRasterExtension(ext), false);
        b.modalBodyTarget.innerHTML = '';
        b.openImageEditorModal(`x.${ext}`, `x.${ext}`, `/uploads/x.${ext}`);
        assert.equal(b.modalBodyTarget.innerHTML, '', `.${ext} must not open Canvas Image Editor`);
    }

    // Editable raster format prefers same-origin downloadUrl + inline=1 over cross-origin S3 URL
    let loadedImgSrc = null;
    globalThis.document = {
        createElement: (tag) => ({ ...createFakeEl(tag), toDataURL: () => 'data:image/webp;base64,' }),
    };
    globalThis.Image = class FakeImg { set src(v) { loadedImgSrc = v; } };
    b.openImageEditorModal(
        'hero.jpg',
        'hero.jpg',
        'https://s3.amazonaws.com/bucket/hero.jpg',
        '/_liveux-file-manager/download?profile=default&volume=s3&path=hero.jpg',
    );
    assert.equal(loadedImgSrc, '/_liveux-file-manager/download?profile=default&volume=s3&path=hero.jpg&inline=1');
});

test('4. Multi-instance remember_last_dir isolation, onDialogOpened reset, race cancellation, double-submit guards, slugged folder drop, clipboard prune & multi-drag', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');

    // A. Explicit volume picker never hijacks or gets hijacked by another volume in localStorage
    const storage = new Map([
        ['liveux_fm_last_vol:default', 'docs'],
        ['liveux_fm_last_dir:default', 'invoices'],
        ['liveux_fm_last_dir:default:photos', '2026/summer'],
    ]);
    globalThis.localStorage = {
        getItem: (k) => (storage.has(k) ? storage.get(k) : null),
        setItem: (k, v) => storage.set(k, String(v)),
        removeItem: (k) => storage.delete(k),
    };

    const bVol = new BrowserController();
    bVol.profileValue = 'default';
    bVol.volumeValue = 'photos';
    bVol.pathValue = '';
    bVol.rememberLastDirValue = true;
    bVol.explicitVolumeRequested = true;
    bVol.element = createFakeEl('div');
    let navigatedArgs = null;
    bVol.navigateTo = async (p, v) => { navigatedArgs = { p, v }; return true; };
    await bVol.restoreLastVisitedLocation();
    assert.deepEqual(navigatedArgs, { p: '2026/summer', v: 'photos' }, 'Explicit volume picker must stay on photos and restore photos-scoped dir');

    // B. Double-submit guards on pasteClipboard, extractZipArchive, toggleFolderPin
    const bGuard = new BrowserController();
    bGuard.capabilitiesValue = { move: true, copy: true };
    bGuard.volumeValue = 'local';
    bGuard.pathValue = 'target';
    bGuard.clipboard = { mode: 'copy', volume: 'local', paths: ['a.jpg'] };
    bGuard.dirCache = new Map();
    bGuard.treeCache = new Map();
    bGuard.selectedMap = new Map();
    let actionCount = 0;
    let releaseAction;
    bGuard.callAction = () => new Promise((r) => { actionCount += 1; releaseAction = () => r(true); });
    bGuard.fetchDirectory = async () => true;
    bGuard.refreshFullTree = async () => { };
    bGuard.syncSelectionUi = () => { };
    bGuard.syncClipboardUi = () => { };

    const p1 = bGuard.pasteClipboard();
    const p2 = bGuard.pasteClipboard();
    assert.equal(actionCount, 1, 'Second concurrent pasteClipboard must be ignored');
    releaseAction();
    await Promise.all([p1, p2]);

    // C. OS Folder Drop createFolder=false guard & slugged path mapping
    const bDrop = new BrowserController();
    bDrop.volumeValue = 'local';
    bDrop.i18nValue = { 'errors.access_denied': 'Forbidden' };
    let statusMsg = null;
    bDrop.showStatus = (m) => { statusMsg = m; };
    bDrop.capabilitiesValue = { upload: true, createFolder: false };

    const makeFakeDirDataTransfer = () => ({
        items: [{
            webkitGetAsEntry: () => ({
                isDirectory: true,
                isFile: false,
                name: 'My Folder',
                createReader: () => {
                    let read = false;
                    return {
                        readEntries: (cb) => {
                            if (read) { cb([]); return; }
                            read = true;
                            cb([{
                                isDirectory: false,
                                isFile: true,
                                name: 'pic.jpg',
                                file: (fcb) => fcb({ name: 'pic.jpg', size: 10, type: 'image/jpeg' }),
                            }]);
                        },
                    };
                },
            }),
        }],
    });

    await bDrop.handleDataTransferDrop(makeFakeDirDataTransfer(), 'base');
    assert.equal(statusMsg, 'Forbidden', 'Folder drop must fail with error when createFolder=false');

    bDrop.capabilitiesValue = { upload: true, createFolder: true };
    const createdCalls = [];
    const uploadedTargets = [];
    bDrop.callActionJson = async (_act, payload) => {
        createdCalls.push(payload);
        return { ok: true, path: 'base/my-folder' };
    };
    bDrop.enqueueFiles = (_files, dir) => { uploadedTargets.push(dir); };
    await bDrop.handleDataTransferDrop(makeFakeDirDataTransfer(), 'base');
    assert.deepEqual(createdCalls, [{ volume: 'local', parentPath: 'base', name: 'My Folder', ifNotExists: true }]);
    assert.deepEqual(uploadedTargets, ['base/my-folder'], 'Files must upload into server-slugged path');

    // Failed createFolder (e.g. 403/422) must NOT fall back to un-slugified raw folder name or upload child files
    const failedUploads = [];
    const nestedCalls = [];
    bDrop.callActionJson = async (_act, payload) => {
        nestedCalls.push(payload);
        return null; // Simulate 403 / 422 failure
    };
    bDrop.enqueueFiles = (_files, dir) => { failedUploads.push(dir); };
    const makeNestedForbiddenDrop = () => ({
        items: [{
            webkitGetAsEntry: () => ({
                isDirectory: true,
                isFile: false,
                name: 'Locked Folder',
                createReader: () => {
                    let read = false;
                    return {
                        readEntries: (cb) => {
                            if (read) { cb([]); return; }
                            read = true;
                            cb([
                                {
                                    isDirectory: true,
                                    isFile: false,
                                    name: 'child',
                                    createReader: () => {
                                        let r2 = false;
                                        return {
                                            readEntries: (cb2) => {
                                                if (r2) { cb2([]); return; }
                                                r2 = true;
                                                cb2([{
                                                    isDirectory: false,
                                                    isFile: true,
                                                    name: 'secret.jpg',
                                                    file: (fcb) => fcb({ name: 'secret.jpg', size: 10, type: 'image/jpeg' }),
                                                }]);
                                            },
                                        };
                                    },
                                },
                                {
                                    isDirectory: false,
                                    isFile: true,
                                    name: 'top.jpg',
                                    file: (fcb) => fcb({ name: 'top.jpg', size: 10, type: 'image/jpeg' }),
                                },
                            ]);
                        },
                    };
                },
            }),
        }],
    });
    await bDrop.handleDataTransferDrop(makeNestedForbiddenDrop(), 'base');
    assert.equal(nestedCalls.length, 1, 'Child subdirectories of a failed ancestor must be skipped');
    assert.deepEqual(failedUploads, [], 'Files inside failed folders must never fall back to raw paths or be enqueued');

    // D. Clipboard pruning & Multi-selection drag-and-drop
    const bClip = new BrowserController();
    bClip.volumeValue = 'local';
    bClip.syncSelectionUi = () => { };
    bClip.clipboard = { mode: 'copy', volume: 'local', paths: ['docs/a.pdf', 'docs/sub/b.pdf', 'keep.png'] };
    bClip.pruneClipboardPaths(['docs'], 'local');
    assert.deepEqual(bClip.clipboard.paths, ['keep.png']);
    bClip.pruneClipboardPaths(['keep.png'], 'local');
    assert.equal(bClip.clipboard, null);

    bClip.capabilitiesValue = { allowDragMove: true, copy: true, move: true };
    bClip.confirmMoveValue = false;
    bClip.dirCache = new Map();
    bClip.treeCache = new Map();
    bClip.selectedMap = new Map([
        ['a.jpg', { kind: 'file', path: 'a.jpg', name: 'a.jpg' }],
        ['b.jpg', { kind: 'file', path: 'b.jpg', name: 'b.jpg' }],
        ['dest', { kind: 'folder', path: 'dest', name: 'dest' }],
    ]);
    bClip.fetchDirectory = async () => true;
    bClip.refreshFullTree = async () => { };
    let bulkMovePayload = null;
    bClip.callAction = async (act, payload) => {
        if (act === 'bulkMove') {
            bulkMovePayload = payload;
        }
        return true;
    };
    const dragEl = createFakeEl('div');
    dragEl.dataset = { path: 'a.jpg', name: 'a.jpg' };
    bClip.onItemDragStart({ currentTarget: dragEl, dataTransfer: { setData() { } } });
    assert.deepEqual(bClip.dragging.paths, ['a.jpg', 'b.jpg', 'dest']);

    const dropTarget = createFakeEl('div');
    dropTarget.dataset = { folderPath: 'dest', volume: 'local' };
    await bClip.onFolderDrop({
        currentTarget: dropTarget,
        ctrlKey: false,
        altKey: false,
        preventDefault() { },
        stopPropagation() { },
        dataTransfer: { files: [], types: [], getData: () => '' },
    });
    assert.deepEqual(bulkMovePayload.paths, ['a.jpg', 'b.jpg'], 'Multi-drag onto selected folder filters out self target and bulk-moves remaining items');

    // E. Visual geometry ArrowDown / ArrowUp across mixed column grids
    const bNav = new BrowserController();
    bNav.trashMode = false;
    bNav.viewModeValue = 'grid';
    bNav.volumeValue = 'local';
    bNav.renderedPaths = ['f1', 'f2', 'f3', 'img1', 'img2', 'img3', 'img4'];
    bNav.dirCache = new Map([['local::', bNav.renderedPaths.map((p, idx) => ({ kind: idx < 3 ? 'folder' : 'file', path: p, name: p }))]]);
    bNav.selectedMap = new Map([['f3', { kind: 'folder', path: 'f3', name: 'f3' }]]);
    bNav.lastSelectedPath = 'f3';
    bNav.syncSelectionUi = () => { };
    bNav.hasContentAreaTarget = true;

    const boxes = {
        f1: { left: 0, right: 200, top: 0, bottom: 40, width: 200, height: 40 },
        f2: { left: 210, right: 410, top: 0, bottom: 40, width: 200, height: 40 },
        f3: { left: 420, right: 620, top: 0, bottom: 40, width: 200, height: 40 }, // centerX = 520
        img1: { left: 0, right: 140, top: 60, bottom: 200, width: 140, height: 140 },
        img2: { left: 150, right: 290, top: 60, bottom: 200, width: 140, height: 140 },
        img3: { left: 300, right: 440, top: 60, bottom: 200, width: 140, height: 140 }, // centerX = 370
        img4: { left: 450, right: 590, top: 60, bottom: 200, width: 140, height: 140 }, // centerX = 520 (exact column match!)
    };
    bNav.contentAreaTarget = {
        querySelectorAll: () => bNav.renderedPaths.map((p) => ({
            dataset: { path: p },
            getBoundingClientRect: () => boxes[p],
            scrollIntoView: () => { },
        })),
    };
    bNav.navigateSelectionByArrow('ArrowDown');
    assert.equal(bNav.lastSelectedPath, 'img4', 'ArrowDown from f3 (centerX=520) lands on visually aligned img4 (centerX=520), not f3+3=img3');
    bNav.navigateSelectionByArrow('ArrowUp');
    assert.equal(bNav.lastSelectedPath, 'f3', 'ArrowUp from img4 returns to visually aligned f3');
});

test('5. onDialogOpened resets stale Trash/Search/TypeFilter/Volume, async races are aborted, and inline fullscreen retains Escape/focus', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');

    // A. onDialogOpened resets trashMode, searchQuery, recursiveItems, typeFilter, and diverged explicit volume
    const bOpen = new BrowserController();
    bOpen.volumeValue = 's3_other';
    bOpen.pathValue = 'deep/sub';
    bOpen.initialVolume = 'photos';
    bOpen.initialPath = '';
    bOpen.initialTypeFilter = 'image';
    bOpen.explicitVolumeRequested = true;
    bOpen.rememberLastDirValue = false;
    bOpen.trashMode = true;
    bOpen.searchQuery = 'stale';
    bOpen.recursiveSearch = true;
    bOpen.recursiveItems = [{ kind: 'file', path: 'x.png', name: 'x.png' }];
    bOpen.typeFilterValue = 'doc';
    bOpen.hasSearchInputTarget = true;
    bOpen.searchInputTarget = createFakeEl('input');
    bOpen.searchInputTarget.value = 'stale';
    bOpen.hasRecursiveSearchBtnTarget = true;
    bOpen.recursiveSearchBtnTarget = createFakeEl('button');
    bOpen.recursiveSearchBtnTarget.classList.add('is-active');
    bOpen.hasTypeFiltersTarget = true;
    bOpen.typeFiltersTarget = createFakeEl('select');
    bOpen.selectedMap = new Map();
    bOpen.dirCache = new Map();
    for (const fn of ['hideContextMenu', 'closeLightbox', 'closeModal', 'syncFullscreenButton', 'clearSelection', 'syncTrashUi']) {
        bOpen[fn] = () => { };
    }
    let reopenedNav = null;
    bOpen.navigateTo = async (p, v) => {
        reopenedNav = { p, v };
        bOpen.pathValue = p;
        bOpen.volumeValue = v;
        return true;
    };
    await bOpen.onDialogOpened({ detail: { volume: 'photos', explicitVolume: true, path: '', type: 'image' } });
    assert.equal(bOpen.trashMode, false);
    assert.equal(bOpen.searchQuery, '');
    assert.equal(bOpen.searchInputTarget.value, '');
    assert.equal(bOpen.recursiveItems, null);
    assert.equal(bOpen.typeFilterValue, 'image');
    assert.deepEqual(reopenedNav, { p: '', v: 'photos' });

    // B. In-flight recursive search does NOT overwrite navigateTo()
    const savedFetch = globalThis.fetch;
    try {
        const bRace = new BrowserController();
        bRace.listUrlValue = '/api/list';
        bRace.profileValue = 'default';
        bRace.volumeValue = 'local';
        bRace.pathValue = 'dirA';
        bRace.searchQuery = 'logo';
        bRace.recursiveSearch = true;
        bRace.dirCache = new Map([['local::dirB', []]]);
        bRace.history = [{ volume: 'local', path: 'dirA' }];
        bRace.historyIndex = 0;
        bRace.expandedFolders = new Set();
        bRace.selectedMap = new Map();
        for (const fn of ['setLoading', 'showStatus', 'updatePerfBadge', 'applyClientView', 'clearSelection', 'syncTrashButton', 'syncTrashToolbarState', 'syncNavButtons', 'persistLastLocation', 'renderBreadcrumbs', 'renderSidebarTree']) {
            bRace[fn] = () => { };
        }

        let resolveSearch;
        globalThis.fetch = () => new Promise((r) => { resolveSearch = r; });
        const searchPromise = bRace.fetchRecursiveSearch();
        await bRace.navigateTo('dirB', 'local');
        resolveSearch({ ok: true, json: async () => ({ ok: true, items: [{ path: 'stale.png' }] }) });
        await searchPromise;
        assert.equal(bRace.recursiveItems, null, 'Superseded recursive search must NOT populate recursiveItems after navigateTo');

        // C. toggleTrash() supersedes in-flight navigateTo() so navigation failure cannot roll back trashMode
        let rejectDir;
        globalThis.fetch = (_url, opts) => new Promise((resolve, reject) => {
            if (String(_url).includes('trash')) {
                resolve({ ok: true, json: async () => ({ ok: true, entries: [] }) });
                return;
            }
            rejectDir = () => reject(new Error('network fail'));
            opts?.signal?.addEventListener?.('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
        });
        bRace.trashUrlValue = '/api/trash';
        bRace.renderTrash = () => { };
        bRace.syncTrashUi = () => { };
        const navInFlight = bRace.navigateTo('slowDir', 'local', true);
        await bRace.toggleTrash();
        if (rejectDir) {
            rejectDir();
        }
        await navInFlight;
        assert.equal(bRace.trashMode, true, 'In-flight navigateTo must not roll back trashMode=true');
    } finally {
        globalThis.fetch = savedFetch;
    }

    // D. Inline fullscreen keeps isFileManagerActive() === true and closeModal restores root focus
    const bFocus = new BrowserController();
    let rootFocused = 0;
    const rootEl = createFakeEl('div');
    rootEl.focus = () => { rootFocused += 1; };
    bFocus.element = rootEl;
    bFocus.isFullscreen = true;
    globalThis.document = { activeElement: createFakeEl('body'), body: createFakeEl('body') };
    assert.equal(bFocus.isFileManagerActive(), true, 'isFileManagerActive() must return true whenever isFullscreen === true');

    bFocus.hasModalBackdropTarget = true;
    bFocus.modalBackdropTarget = createFakeEl('div');
    bFocus.modalBackdropTarget.classList.add('is-open');
    bFocus.closeModal();
    assert.equal(rootFocused, 1, 'Closing an open modal restores focus to root element');
});

test('6. Pass-5 regressions: Picker private SVG previewSrc, Browser foldersFirst=false, t() prototype safety, and ImageEditor delete permission', async () => {
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');

    // A. Picker previewSrc() rewrites private /download?... to /thumb?... for .svg while leaving .png and public SVGs intact
    const p = new PickerController();
    const privSvg = '/_liveux-file-manager/download?profile=default&volume=priv&path=icons%2Fbrand-logo.svg&inline=1';
    const privSvgAbs = 'https://app.example.com/panel/_liveux-file-manager/download?profile=docs&path=vec.SVG';
    const privPng = '/_liveux-file-manager/download?profile=default&volume=priv&path=photos%2Fbanner.png&inline=1';
    const pubSvg = '/uploads/icons/brand-logo.svg';
    const cdnSvg = 'https://cdn.example.com/assets/brand-logo.svg?v=2';
    const shareSvg = '/_liveux-file-manager/share?profile=default&path=icons%2Fbrand-logo.svg&_hash=abc';

    assert.equal(
        p.previewSrc(privSvg),
        '/_liveux-file-manager/thumb?profile=default&volume=priv&path=icons%2Fbrand-logo.svg&inline=1',
        'Private download endpoint SVG must rewrite /download? to /thumb?',
    );
    assert.equal(
        p.previewSrc(privSvgAbs),
        'https://app.example.com/panel/_liveux-file-manager/thumb?profile=docs&path=vec.SVG',
        'Prefixed/absolute download endpoint SVG must rewrite /download? to /thumb?',
    );
    assert.equal(p.previewSrc(privPng), privPng, 'Private raster PNG download URL must remain untouched');
    assert.equal(p.previewSrc(pubSvg), pubSvg, 'Public SVG URL must remain untouched');
    assert.equal(p.previewSrc(cdnSvg), cdnSvg, 'CDN SVG URL with query string must remain untouched');
    assert.equal(p.previewSrc(shareSvg), shareSvg, 'Signed share SVG URL must not be rewritten to /thumb');

    // B. Browser foldersFirst: false sorts folders alongside files instead of forcing folders first
    const bSort = new BrowserController();
    bSort.volumeValue = 'local';
    bSort.pathValue = '';
    bSort.sortByValue = 'name';
    bSort.sortDirValue = 'asc';
    bSort.perPageValue = 24;
    bSort.pageValue = 1;
    bSort.viewModeValue = 'list';
    bSort.typeFilterValue = 'all';
    bSort.searchQuery = '';
    bSort.selectedMap = new Map();
    bSort.hasContentAreaTarget = true;
    bSort.contentAreaTarget = createFakeEl('div');
    bSort.updatePagination = () => { };
    bSort.syncSelectionUi = () => { };
    bSort.renderListHeader = () => '';
    bSort.renderListItem = (item) => `<div data-item="${item.path}"></div>`;

    const mixedItems = [
        { kind: 'folder', path: 'zebra', name: 'zebra', size: 0, lastModified: 10 },
        { kind: 'file', path: 'alpha.jpg', name: 'alpha.jpg', extension: 'jpg', size: 100, lastModified: 30 },
        { kind: 'folder', path: 'beta', name: 'beta', size: 0, lastModified: 20 },
        { kind: 'file', path: 'middle.pdf', name: 'middle.pdf', extension: 'pdf', size: 50, lastModified: 40 },
    ];
    bSort.dirCache = new Map([['local::', mixedItems]]);

    bSort.capabilitiesValue = { foldersFirst: true };
    bSort.applyClientView();
    assert.deepEqual(bSort.renderedPaths, ['beta', 'zebra', 'alpha.jpg', 'middle.pdf'], 'foldersFirst=true keeps folders before files');

    bSort.capabilitiesValue = { foldersFirst: false };
    bSort.applyClientView();
    assert.deepEqual(bSort.renderedPaths, ['alpha.jpg', 'beta', 'middle.pdf', 'zebra'], 'foldersFirst=false sorts folders alongside files by name');

    // C. Browser t() prototype safety (toString / constructor / valueOf keys)
    const bI18n = new BrowserController();
    bI18n.i18nValue = { 'actions.save': 'Save %name%' };
    assert.equal(bI18n.t('toString'), 'toString');
    assert.equal(bI18n.t('constructor'), 'constructor');
    assert.equal(bI18n.t('valueOf'), 'valueOf');
    assert.equal(bI18n.t('__proto__'), '__proto__');
    assert.equal(bI18n.t('actions.save', { name: 'File' }), 'Save File');
    bI18n.i18nValue = null;
    assert.equal(bI18n.t('toString'), 'toString');

    // D. Image Editor disables Overwrite when capabilities.delete is false & keeps modal open on save error
    const bEd = new BrowserController();
    bEd.i18nValue = { 'modals.edit_image_title': 'Edit', 'ui.auto_placeholder': 'auto', 'errors.access_denied': 'Denied', 'errors.action_failed': 'Failed', 'messages.saved': 'Saved' };
    bEd.capabilitiesValue = { editImage: true, delete: false };
    bEd.imageEditUrlValue = '/_liveux-file-manager/image-edit';
    bEd.hasModalBackdropTarget = true;
    bEd.modalBackdropTarget = createFakeEl('div');
    bEd.modalTitleTarget = createFakeEl('h6');
    bEd.modalBodyTarget = createFakeEl('div');
    let lastEdStatus = null;
    bEd.showStatus = (msg, type) => { lastEdStatus = { msg, type }; };
    bEd.setLoading = () => { };
    bEd.invalidateClientCaches = () => { };
    bEd.fetchDirectory = async () => true;

    let copyClickHandler = null;
    const copyBtnEl = { ...createFakeEl('button'), dataset: { edSave: 'copy' }, disabled: false, addEventListener: (ev, fn) => { if (ev === 'click') copyClickHandler = fn; } };
    const overwriteBtnEl = { ...createFakeEl('button'), dataset: { edSave: 'overwrite' }, disabled: true, addEventListener: () => { } };
    bEd.modalBodyTarget.querySelector = (sel) => {
        if (sel === '[data-ed-save="overwrite"]') return overwriteBtnEl;
        if (sel === '[data-ed-save="copy"]') return copyBtnEl;
        return null;
    };
    bEd.modalBodyTarget.querySelectorAll = (sel) => (sel === '[data-ed-save]' ? [overwriteBtnEl, copyBtnEl] : []);

    let blobRunner = null;
    const fakeWorkCanvas = {
        ...createFakeEl('canvas'),
        width: 100,
        height: 80,
        getContext: () => ({ clearRect() { }, save() { }, translate() { }, rotate() { }, scale() { }, drawImage() { }, restore() { }, fillRect() { } }),
        toDataURL: () => 'data:image/webp;base64,',
        toBlob: (cb) => { blobRunner = cb; },
    };
    globalThis.document = {
        createElement: (tag) => {
            if (tag === 'canvas') {
                return { ...fakeWorkCanvas };
            }
            return createFakeEl(tag);
        },
    };
    globalThis.Image = class FakeImg {
        set src(v) {
            this._src = v;
            this.naturalWidth = 100;
            this.naturalHeight = 80;
            this.onload?.();
        }
    };
    bEd.openImageEditorModal('photo.jpg', 'photo.jpg', '/uploads/photo.jpg');
    assert.match(
        bEd.modalBodyTarget.innerHTML,
        /data-ed-save="overwrite"\s+disabled/,
        'Overwrite button must be disabled in HTML when capabilities.delete is false',
    );
    assert.equal(overwriteBtnEl.disabled, true, 'syncFormatUi keeps overwriteBtn disabled when delete=false');

    // Trigger Save Copy with failing HTTP 422 response: modal MUST stay open and re-enable Save Copy
    const origFetch = globalThis.fetch;
    try {
        let closeModalCalls = 0;
        bEd.closeModal = () => { closeModalCalls += 1; };

        globalThis.fetch = async () => ({ ok: false, status: 422, json: async () => ({ ok: false, error: 'Quota exceeded' }) });
        copyClickHandler();
        assert.equal(copyBtnEl.disabled, true, 'Save Copy disabled while saving to prevent double-submit');
        await blobRunner(new Blob(['img'], { type: 'image/jpeg' }));
        assert.equal(closeModalCalls, 0, 'Modal MUST remain open when image-edit save fails');
        assert.deepEqual(lastEdStatus, { msg: 'Quota exceeded', type: 'danger' });
        assert.equal(copyBtnEl.disabled, false, 'Save Copy re-enabled after save failure');
        assert.equal(overwriteBtnEl.disabled, true, 'Overwrite remains disabled when delete=false after failure');

        // Trigger Save Copy with 200 OK response: modal closes now
        globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ ok: true, message: 'Saved OK' }) });
        copyClickHandler();
        await blobRunner(new Blob(['img'], { type: 'image/jpeg' }));
        assert.equal(closeModalCalls, 1, 'Modal closes after image-edit save succeeds');
    } finally {
        globalThis.fetch = origFetch;
    }
});

test('7. Pass-6 BCP-47 Intl safety: underscore/POSIX locales never throw RangeError in sort, date, inspector, share modal, or formatBytes', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const localesToTest = ['pt_BR', 'zh_Hans_CN', 'zh_CN', 'fr_FR', 'ar_SA', 'en_US.UTF-8', 'sr_RS@latin', 'invalid_$$$_locale'];

    for (const loc of localesToTest) {
        const b = new BrowserController();
        b.localeValue = loc;
        b.volumeValue = 'local';
        b.volumeLabelValue = 'Local';
        b.pathValue = '';
        b.perPageValue = 24;
        b.pageValue = 1;
        b.viewModeValue = 'grid';
        b.typeFilterValue = 'all';
        b.sortDirValue = 'asc';
        b.capabilitiesValue = { foldersFirst: true, share: true, download: true };
        b.i18nValue = {
            'units.b': 'o',
            'units.kb': 'Ko',
            'units.mb': 'Mo',
            'units.gb': 'Go',
            'modals.clone_default_name': 'copie-%name%',
            'modals.share_expires_at': 'Expires',
        };
        b.iconSetValue = { defaultFolderColor: '#2563eb', icons: {}, ui: {}, badges: {} };
        b.hasContentAreaTarget = true;
        b.contentAreaTarget = createFakeEl('div');
        b.hasInspectorTarget = true;
        b.inspectorTarget = createFakeEl('aside');
        b.inspectorModeValue = 'always';
        b.hasModalBackdropTarget = true;
        b.modalBackdropTarget = createFakeEl('div');
        b.modalTitleTarget = createFakeEl('h6');
        b.modalBodyTarget = createFakeEl('div');
        b.hasTreeTarget = true;
        const treeChildBox = createFakeEl('div');
        treeChildBox.dataset = { volumeTreeContainer: 'local' };
        b.treeTarget = {
            ...createFakeEl('div'),
            querySelectorAll: (sel) => (sel === '[data-volume-tree-container]' ? [treeChildBox] : []),
        };

        const items = [
            { kind: 'file', path: 'item-10.png', name: 'item-10.png', extension: 'png', size: 2097152, createdAt: 1730000000, lastModified: 1732000000, url: '/uploads/item-10.png' },
            { kind: 'file', path: 'item-2.png', name: 'item-2.png', extension: 'png', size: 1024, createdAt: 1730000000, lastModified: 1732000100, url: '/uploads/item-2.png' },
            { kind: 'file', path: 'doc-1.pdf', name: 'doc-1.pdf', extension: 'pdf', size: 512, createdAt: 1730000000, lastModified: 1732000200, url: '/uploads/doc-1.pdf' },
        ];
        b.dirCache = new Map([['local::', items]]);
        b.treeCache = new Map([['local', [
            { path: 'folder-10', name: 'folder-10', parent: '' },
            { path: 'folder-2', name: 'folder-2', parent: '' },
        ]]]);
        b.expandedFolders = new Set();
        b.selectedMap = new Map([['item-10.png', items[0]]]);

        // 1. Name sort & Type sort (with natural numeric name tie-breaker) never throw RangeError
        b.sortByValue = 'name';
        assert.doesNotThrow(() => b.applyClientView(), `applyClientView(name) threw for locale ${loc}`);
        assert.deepEqual(b.renderedPaths, ['doc-1.pdf', 'item-2.png', 'item-10.png'], `Natural numeric name sort failed for ${loc}`);

        b.sortByValue = 'type';
        assert.doesNotThrow(() => b.applyClientView(), `applyClientView(type) threw for locale ${loc}`);
        assert.deepEqual(b.renderedPaths, ['doc-1.pdf', 'item-2.png', 'item-10.png'], `Type sort with numeric name tie-breaker failed for ${loc}`);

        // 2. Sidebar tree & Move modal tree sort identically using getCollator()
        assert.doesNotThrow(() => b.renderSidebarTree(), `renderSidebarTree threw for ${loc}`);
        const idx2 = treeChildBox.innerHTML.indexOf('folder-2');
        const idx10 = treeChildBox.innerHTML.indexOf('folder-10');
        assert.ok(idx2 >= 0 && idx2 < idx10, `Sidebar tree must natural-sort folder-2 before folder-10 for ${loc}`);

        // 3. formatDate, formatDateTime, formatNumber, formatBytes, renderInspector & openShareLinkModal
        const formattedDate = b.formatDate(1732000000);
        assert.notEqual(formattedDate, '—');
        assert.doesNotThrow(() => b.renderInspector(), `renderInspector threw for ${loc}`);
        assert.match(b.inspectorTarget.innerHTML, /item-10\.png/);

        let shareGenHandler = null;
        const shareGenBtn = { ...createFakeEl('button'), isConnected: true, addEventListener: (ev, fn) => { if (ev === 'click') shareGenHandler = fn; } };
        const shareExpiryEl = createFakeEl('div');
        b.modalBodyTarget.querySelector = (sel) => {
            if (sel === '[data-share-generate]') return shareGenBtn;
            if (sel === '[data-share-expiry]') return shareExpiryEl;
            return null;
        };
        b.callActionJson = async () => ({ ok: true, url: '/_liveux-file-manager/share?t=1', expiresAt: 1735000000 });
        assert.doesNotThrow(() => b.openShareLinkModal(items[0]), `openShareLinkModal threw for ${loc}`);
        await shareGenHandler();
        assert.match(shareExpiryEl.textContent, /^Expires:\s+\S+/);

        // 4. Localized formatBytes & openCloneModal
        assert.match(b.formatBytes(500), /\s+o$/);
        assert.match(b.formatBytes(2048), /\s+Ko$/);
        assert.match(b.formatBytes(2097152), /\s+Mo$/);
        b.modalBodyTarget.querySelector = () => null;
        b.openCloneModal('doc-1.pdf', 'doc-1.pdf');
        assert.match(b.modalBodyTarget.innerHTML, /value="copie-doc-1\.pdf"/);
    }

    const bCanon = new BrowserController();
    assert.equal(bCanon.bcp47Locale('pt_BR'), 'pt-BR');
    assert.equal(bCanon.bcp47Locale('zh_Hans_CN'), 'zh-Hans-CN');
    assert.equal(bCanon.bcp47Locale('en_US.UTF-8'), 'en-US');
    assert.equal(bCanon.bcp47Locale('invalid_$$$'), undefined);
});

test('8. Pass-6 IME composition guards, 1-char CJK recursive search, NFC + Turkish İ/I/ı search folding, global tag commas & surrogate-safe badges', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');

    // A. IME isComposing / keyCode === 229 on Escape and Enter NEVER closes modals or clears selections
    const bIme = new BrowserController();
    bIme.isFileManagerActive = () => true;
    bIme.keyboardShortcutsValue = true;
    bIme.hasModalBackdropTarget = true;
    bIme.modalBackdropTarget = createFakeEl('div');
    bIme.modalBackdropTarget.classList.add('is-open');
    bIme.selectedMap = new Map([['kanji.png', { kind: 'file', path: 'kanji.png', name: 'kanji.png' }]]);

    for (const imeEvt of [
        { key: 'Escape', isComposing: true },
        { key: 'Escape', isComposing: false, keyCode: 229 },
        { key: 'Enter', isComposing: true },
        { key: 'Enter', isComposing: false, keyCode: 229 },
    ]) {
        let prevented = false;
        bIme.onKeydown({ ...imeEvt, preventDefault: () => { prevented = true; } });
        assert.equal(prevented, false);
        assert.equal(bIme.modalBackdropTarget.classList.contains('is-open'), true, 'IME candidate Escape/Enter must NOT close open modal');
        assert.equal(bIme.selectedMap.size, 1, 'IME candidate Escape/Enter must NOT clear selection');
    }

    // Even when modal is closed, IME Escape must NOT clear selection
    bIme.modalBackdropTarget.classList.remove('is-open');
    bIme.onKeydown({ key: 'Escape', isComposing: true, preventDefault: () => { } });
    bIme.onKeydown({ key: 'Escape', keyCode: 229, preventDefault: () => { } });
    assert.equal(bIme.selectedMap.size, 1, 'IME Escape outside modal must still preserve selection');

    // Picker dropzone keydown ignores IME composition
    const pIme = new PickerController();
    let pickerOpened = 0;
    pIme.open = () => { pickerOpened += 1; };
    const dz = {};
    pIme.onDropzoneKeydown({ key: 'Enter', isComposing: true, target: dz, currentTarget: dz, preventDefault: () => { } });
    pIme.onDropzoneKeydown({ key: 'Enter', keyCode: 229, target: dz, currentTarget: dz, preventDefault: () => { } });
    assert.equal(pickerOpened, 0, 'Picker dropzone must ignore IME Enter');

    // B. 1-character CJK recursive search readiness + deferred recursive fetch during IME composition
    assert.equal(bIme.isRecursiveQueryReady('猫'), true);
    assert.equal(bIme.isRecursiveQueryReady('車'), true);
    assert.equal(bIme.isRecursiveQueryReady('집'), true);
    assert.equal(bIme.isRecursiveQueryReady('#猫'), true);
    assert.equal(bIme.isRecursiveQueryReady('＃猫'), true);
    assert.equal(bIme.isRecursiveQueryReady('a'), false);
    assert.equal(bIme.isRecursiveQueryReady('#a'), false);
    assert.equal(bIme.isRecursiveQueryReady('ab'), true);

    const bRec = new BrowserController();
    bRec.recursiveSearch = true;
    bRec.trashMode = false;
    bRec.applyClientView = () => { };
    let recursiveFetchCalls = 0;
    bRec.fetchRecursiveSearch = async () => { recursiveFetchCalls += 1; };

    await bRec.onSearchInput({ isComposing: true, currentTarget: { value: '猫' } });
    assert.equal(Boolean(bRec.recursiveTimer), false, 'Must not schedule recursive search while IME isComposing=true');
    assert.equal(recursiveFetchCalls, 0);

    await bRec.onSearchInput({ isComposing: false, currentTarget: { value: '猫' } });
    assert.notEqual(bRec.recursiveTimer, null, 'compositionend schedules recursive search for 1-char CJK query');
    clearTimeout(bRec.recursiveTimer);

    // C. NFD/NFC + Turkish İ/I/ı search folding across foldSearch, itemMatchesSearch & renderSidebarTree
    assert.equal(bRec.foldSearch('Cafe\u0301'), bRec.foldSearch('café'));
    assert.equal(bRec.foldSearch('か\u3099'), bRec.foldSearch('が'));
    assert.equal(bRec.foldSearch('İletişim'), bRec.foldSearch('iletişim'));
    assert.equal(bRec.foldSearch('IMG_001'), bRec.foldSearch('img_001'));
    assert.equal(bRec.foldSearch('Işık'), bRec.foldSearch('ışık'));
    assert.equal(bRec.foldSearch('＃猫'), '#猫');

    const cafeItem = { kind: 'file', name: 'Cafe\u0301-menu.pdf', meta: { tags: ['İletişim', 'がぞう'], alt: 'Işık Raporu' } };
    assert.equal(bRec.itemMatchesSearch(cafeItem, 'café'), true);
    assert.equal(bRec.itemMatchesSearch(cafeItem, 'iletişim'), true);
    assert.equal(bRec.itemMatchesSearch(cafeItem, '#iletişim'), true);
    assert.equal(bRec.itemMatchesSearch(cafeItem, '＃か\u3099ぞう'), true);
    assert.equal(bRec.itemMatchesSearch(cafeItem, 'ışık'), true);

    // D. Global tag commas (, ， 、 ،) in openFileMetadataModal & surrogate-safe extension badges
    const bMeta = new BrowserController();
    bMeta.i18nValue = { 'modals.file_meta_title': 'Meta' };
    bMeta.capabilitiesValue = { editMetadata: true };
    bMeta.dirCache = new Map();
    bMeta.hasModalBackdropTarget = true;
    bMeta.modalBackdropTarget = createFakeEl('div');
    bMeta.modalTitleTarget = createFakeEl('h6');
    let formSubmitHandler = null;
    const fakeMetaForm = {
        ...createFakeEl('form'),
        addEventListener: (ev, fn) => { if (ev === 'submit') formSubmitHandler = fn; },
    };
    bMeta.modalBodyTarget = {
        ...createFakeEl('div'),
        querySelector: (sel) => (sel === '[data-fm-file-meta-form]' ? fakeMetaForm : null),
    };
    const origFormData = globalThis.FormData;
    try {
        globalThis.FormData = class FakeFD {
            get(k) {
                return k === 'tags' ? 'design, 设计，デザイン、تصميم' : '';
            }
        };
        let savedMetaPayload = null;
        bMeta.callAction = async (_act, payload) => { savedMetaPayload = payload; return false; };
        bMeta.openFileMetadataModal({ kind: 'file', path: 'poster.png', name: 'poster.png', isImage: false });
        await formSubmitHandler({ preventDefault() { } });
        assert.deepEqual(savedMetaPayload.tags, ['design', '设计', 'デザイン', 'تصميم']);
    } finally {
        globalThis.FormData = origFormData;
    }

    // Surrogate-safe emoji extension slicing (never emits lone surrogates \uD800-\uDFFF)
    const emojiBadge = bMeta.renderFileTypeSvg('🎨🖼️📷🎬🎵🚀');
    assert.equal(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(emojiBadge), false, 'Browser badge must not split surrogate pairs');
    const pBadge = new PickerController();
    pBadge.fileLabelValue = 'DOSYA';
    assert.equal(pBadge.extractExtension('README'), 'DOSYA');
    const extEmoji = pBadge.extractExtension('archive.🎨🖼️📷🎬🎵🚀');
    const emojiGraphemes = typeof Intl?.Segmenter === 'function'
        ? Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(extEmoji), (s) => s.segment)
        : Array.from(extEmoji);
    assert.equal(emojiGraphemes.length, 5);
    assert.equal(extEmoji.includes('🖼️'), true, 'Picker badge must preserve VS16 emoji grapheme cluster');
    assert.equal(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(extEmoji), false, 'Picker badge must not split surrogate pairs');
});

test('9. Pass-6 RTL detection, Dialog lang/dir sync, RTL sidebar resize drag math, and RTL grid ArrowLeft/ArrowRight inversion', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');

    const b = new BrowserController();
    b.element = createFakeEl('div');

    for (const rtlLoc of ['ar', 'ar_SA', 'he', 'fa', 'ckb', 'pa_Arab', 'ur_PK']) {
        b.localeValue = rtlLoc;
        b.syncLocaleAttributes();
        assert.equal(b.isRtl(), true, `${rtlLoc} must resolve as RTL`);
        assert.equal(b.element.dir, 'rtl', `element.dir must be rtl for ${rtlLoc}`);
    }
    for (const ltrLoc of ['uz_Latn', 'en_US', 'zh_CN', 'zh_Hans_CN', 'fr_FR', 'pa_Guru']) {
        b.localeValue = ltrLoc;
        b.syncLocaleAttributes();
        assert.equal(b.isRtl(), false, `${ltrLoc} must resolve as LTR even after previous RTL state`);
        assert.equal(b.element.dir, 'ltr', `element.dir must switch back to ltr for ${ltrLoc}`);
    }

    // DialogController sets dialogTarget.dir and dialogTarget.lang on open()
    const dlg = new DialogController();
    dlg.element = createFakeEl('div');
    dlg.dialogTarget = createFakeEl('dialog');
    dlg.bodyTarget = createFakeEl('div');
    dlg.frameUrlValue = 'invalid-for-early-stop';
    globalThis.window = { addEventListener() { }, removeEventListener() { } };
    globalThis.document = { createElement: (t) => createFakeEl(t), documentElement: { dir: 'ltr', lang: 'en' } };
    dlg.connect();

    await dlg.open({ locale: 'ar_SA', mode: 'offcanvas', position: 'end' });
    assert.equal(dlg.dialogTarget.dir, 'rtl');
    assert.equal(dlg.dialogTarget.lang, 'ar-SA');

    await dlg.open({ locale: 'pt_BR', mode: 'offcanvas', position: 'end' });
    assert.equal(dlg.dialogTarget.dir, 'ltr');
    assert.equal(dlg.dialogTarget.lang, 'pt-BR');

    // RTL sidebar resize: dragging left (clientX < startX) increases sidebar width in RTL
    const bDrag = new BrowserController();
    bDrag.localeValue = 'ar';
    bDrag.hasSidebarTarget = true;
    bDrag.sidebarTarget = {
        ...createFakeEl('aside'),
        style: { width: '200px' },
        getBoundingClientRect: () => ({ width: 200 }),
    };
    let moveListener = null;
    let upListener = null;
    globalThis.document = {
        body: { style: {} },
        addEventListener: (ev, fn) => {
            if (ev === 'mousemove') moveListener = fn;
            if (ev === 'mouseup') upListener = fn;
        },
        removeEventListener: () => { },
    };
    globalThis.window = { addEventListener() { }, removeEventListener() { } };
    bDrag.startSidebarResize({ preventDefault() { }, currentTarget: createFakeEl('div'), clientX: 500 });
    moveListener({ clientX: 440 }); // Dragged 60px to the left in RTL -> +60px wider!
    assert.equal(bDrag.sidebarTarget.style.width, '260px');
    upListener();

    // RTL grid ArrowLeft / ArrowRight inversion (while List view stays un-inverted)
    const bArrow = new BrowserController();
    bArrow.localeValue = 'ar';
    bArrow.trashMode = false;
    bArrow.viewModeValue = 'grid';
    bArrow.volumeValue = 'local';
    bArrow.renderedPaths = ['a.jpg', 'b.jpg', 'c.jpg'];
    bArrow.dirCache = new Map([['local::', bArrow.renderedPaths.map((p) => ({ kind: 'file', path: p, name: p }))]]);
    bArrow.selectedMap = new Map([['a.jpg', { kind: 'file', path: 'a.jpg', name: 'a.jpg' }]]);
    bArrow.lastSelectedPath = 'a.jpg';
    bArrow.syncSelectionUi = () => { };

    bArrow.navigateSelectionByArrow('ArrowLeft');
    assert.equal(bArrow.lastSelectedPath, 'b.jpg', 'In RTL grid, ArrowLeft advances to next item (+1)');
    bArrow.navigateSelectionByArrow('ArrowRight');
    assert.equal(bArrow.lastSelectedPath, 'a.jpg', 'In RTL grid, ArrowRight moves to previous item (-1)');

    bArrow.viewModeValue = 'list';
    bArrow.navigateSelectionByArrow('ArrowRight');
    assert.equal(bArrow.lastSelectedPath, 'b.jpg', 'In RTL list view, ArrowRight stays standard (+1)');
});

test('10. Pass-6 Picker private return_type=path thumbUrl preview, SVG CSS comment-in-string sanitizer, Folder drop truncation/error toast & ImageEditor stale modal guard', async () => {
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');

    // A. Picker return_type: 'path' on private volume (previewUrlPrefix: '', thumbUrl: '/_liveux-file-manager/thumb')
    const pPriv = new PickerController();
    pPriv.profileValue = 'vault';
    pPriv.volumeValue = 'private_docs';
    pPriv.previewUrlPrefixValue = '';
    pPriv.thumbUrlValue = '/_liveux-file-manager/thumb';

    assert.equal(
        pPriv.previewSrc('contracts/2026/scan + 1.png'),
        '/_liveux-file-manager/thumb?profile=vault&volume=private_docs&path=contracts%2F2026%2Fscan%20%2B%201.png',
        'Private volume relative path must resolve via thumbUrl instead of 404 /contracts/...',
    );

    // Public volume relative SVG uses thumbUrl, while public raster uses previewUrlPrefix directly
    pPriv.previewUrlPrefixValue = '/uploads/vault';
    assert.equal(
        pPriv.previewSrc('icons/brand.svg'),
        '/_liveux-file-manager/thumb?profile=vault&volume=private_docs&path=icons%2Fbrand.svg',
    );
    assert.equal(
        pPriv.previewSrc('photos/hero.webp'),
        '/uploads/vault/photos/hero.webp',
    );

    // B. Inline SVG CSS comment-in-string bypass & unclosed comment detection
    const bCss = new BrowserController();
    assert.equal(
        bCss.hasUnsafeSvgCss('a { content: "/*"; } @import "https://evil.example/steal.css"; b { content: "*/"; }'),
        true,
        'CSS comment delimiters inside string literals must NOT mask @import!',
    );
    assert.equal(
        bCss.hasUnsafeSvgCss('a { fill: #2563eb; } /* unclosed comment @import "x"'),
        true,
        'Unclosed /* comment must be rejected',
    );
    assert.equal(
        bCss.hasUnsafeSvgCss('/* normal comment */ .icon { fill: currentColor; stroke-width: 1.5; }'),
        false,
        'Clean SVG CSS with closed comments is allowed',
    );

    // C. Folder drop passes { silent: true }, shows error summary on failed folder, and warns on > MAX_SCAN_FILES truncation
    const bDrop = new BrowserController();
    bDrop.volumeValue = 'local';
    bDrop.capabilitiesValue = { upload: true, createFolder: true };
    bDrop.i18nValue = { 'errors.folder_drop_truncated': 'Truncated at %max% files' };
    const callOptionsSeen = [];
    const statusHistory = [];
    bDrop.showStatus = (msg, type) => { statusHistory.push({ msg, type }); };
    bDrop.enqueueFiles = () => { };
    bDrop.callActionJson = async (_act, _payload, opts) => {
        callOptionsSeen.push(opts);
        return { ok: true, path: 'big-dir' };
    };

    const manyEntries = Array.from({ length: 505 }, (_, idx) => ({
        isDirectory: false,
        isFile: true,
        name: `img_${idx}.jpg`,
        file: (cb) => cb({ name: `img_${idx}.jpg`, size: 10, type: 'image/jpeg' }),
    }));
    const bigDrop = {
        items: [{
            webkitGetAsEntry: () => ({
                isDirectory: true,
                isFile: false,
                name: 'big-dir',
                createReader: () => {
                    let done = false;
                    return {
                        readEntries: (cb) => {
                            if (done) { cb([]); return; }
                            done = true;
                            cb(manyEntries);
                        },
                    };
                },
            }),
        }],
    };
    await bDrop.handleDataTransferDrop(bigDrop, '');
    assert.deepEqual(callOptionsSeen, [{ silent: true }], 'createFolder inside folder drop loop must pass { silent: true }');
    assert.deepEqual(statusHistory, [{ msg: 'Truncated at 500 files', type: 'warning' }]);

    // D. openImageEditorModal guard does NOT close a different modal that replaced modalBodyTarget during async save
    const bEdGuard = new BrowserController();
    bEdGuard.i18nValue = { 'modals.edit_image_title': 'Edit', 'ui.auto_placeholder': 'auto', 'messages.saved': 'Saved' };
    bEdGuard.capabilitiesValue = { editImage: true, delete: true };
    bEdGuard.imageEditUrlValue = '/_liveux-file-manager/image-edit';
    bEdGuard.hasModalBackdropTarget = true;
    bEdGuard.modalBackdropTarget = createFakeEl('div');
    bEdGuard.modalTitleTarget = createFakeEl('h6');
    bEdGuard.hasModalBodyTarget = true;
    bEdGuard.modalBodyTarget = createFakeEl('div');
    bEdGuard.showStatus = () => { };
    bEdGuard.setLoading = () => { };
    bEdGuard.invalidateClientCaches = () => { };
    bEdGuard.fetchDirectory = async () => true;

    const editorCanvas = createFakeEl('canvas');
    let saveClick = null;
    const saveBtn = { ...createFakeEl('button'), dataset: { edSave: 'copy' }, disabled: false, addEventListener: (ev, fn) => { if (ev === 'click') saveClick = fn; } };
    let canvasStillMounted = false;
    bEdGuard.modalBodyTarget.contains = (node) => node === editorCanvas && canvasStillMounted;
    bEdGuard.modalBodyTarget.querySelector = (sel) => {
        if (sel === '[data-editor-canvas]') return editorCanvas;
        if (sel === '[data-ed-save="copy"]') return saveBtn;
        return null;
    };
    bEdGuard.modalBodyTarget.querySelectorAll = (sel) => (sel === '[data-ed-save]' ? [saveBtn] : []);

    let runBlob = null;
    globalThis.document = {
        createElement: (tag) => (tag === 'canvas'
            ? {
                ...createFakeEl('canvas'),
                width: 50,
                height: 50,
                getContext: () => ({ clearRect() { }, save() { }, translate() { }, rotate() { }, scale() { }, drawImage() { }, restore() { }, fillRect() { } }),
                toDataURL: () => 'data:image/webp;base64,',
                toBlob: (cb) => { runBlob = cb; },
            }
            : createFakeEl(tag)),
    };
    globalThis.Image = class FakeImg {
        set src(v) {
            this._src = v;
            this.naturalWidth = 100;
            this.naturalHeight = 80;
            this.onload?.();
        }
    };
    bEdGuard.openImageEditorModal('pic.jpg', 'pic.jpg', '/uploads/pic.jpg');

    const prevFetch = globalThis.fetch;
    try {
        let closeCalls = 0;
        bEdGuard.closeModal = () => { closeCalls += 1; };
        globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ ok: true }) });
        // Simulate another modal replacing modalBodyTarget while save was in flight (contains(canvas) === false)
        canvasStillMounted = false;
        await saveClick();
        await runBlob(new Blob(['x'], { type: 'image/jpeg' }));
        assert.equal(closeCalls, 0, 'Must NOT close modal if another modal replaced the Image Editor canvas');
    } finally {
        globalThis.fetch = prevFetch;
    }
});

test('11. Pass-7 regressions: W3C CSS url(#x?a=/*) desync defense, URL userinfo rejection, localized item/quota/trash formatting, RTL trash arrow, and Greek sigma + IME tree search', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);

    // 1. G-01: W3C CSS unquoted url(#x?a=/*) comment-lexer desynchronization defense
    const bCss = new BrowserController();
    assert.equal(
        bCss.hasUnsafeSvgCss('rect { filter: url(#x?a=/*); } @import "https://evil.example/xss.css"; c { filter: url(#y?b=*/); }'),
        true,
        'Unquoted url(#x?a=/*) comment desync smuggling @import must be rejected',
    );
    assert.equal(
        bCss.hasUnsafeSvgCss('.shape { fill: url(#grad-1); clip-path: url("#clip_2"); mask: url(\'#mask:3\'); }'),
        false,
        'Strict local SVG fragment ID url(#grad-1) must be allowed',
    );
    assert.equal(
        bCss.hasUnsafeSvgCss('.shape { fill: url(https://evil.example/track.svg#id); }'),
        true,
        'External URL in url(...) must be rejected',
    );

    // 2. G-09: Embedded credentials in https://user:pass@host/ rejected across Browser, Picker, and WYSIWYG adapters
    const credUrl = 'https://user:pass@cdn.example.com/a.jpg';
    const userOnlyUrl = 'https://user@cdn.example.com/a.jpg';
    const cleanUrl = 'https://cdn.example.com/a.jpg';
    const pUrl = new PickerController();

    assert.equal(bCss.isSafeHttpOrPathUrl(credUrl), false);
    assert.equal(bCss.isSafeUrl(credUrl), false);
    assert.equal(bCss.isSafeUrl(userOnlyUrl), false);
    assert.equal(bCss.isSafeUrl(cleanUrl), true);
    assert.equal(pUrl.isSafeUrl(credUrl), false);
    assert.equal(pUrl.isSafeUrl(userOnlyUrl), false);
    assert.equal(pUrl.isSafeUrl(cleanUrl), true);

    globalThis.window = {
        LiveUXFileManager: {
            open: async () => [{ url: credUrl, path: 'a.jpg', name: 'a.jpg', isImage: true }],
        },
    };
    let ckCalled = false;
    await ckMod.openFileManagerForCKEditor5({
        model: { change: () => { } },
        commands: { get: () => true },
        execute: () => { ckCalled = true; },
    });
    assert.equal(ckCalled, false, 'CKEditor 5 adapter must reject URL with embedded credentials');

    let tinyCalled = false;
    await tinyMod.createTinyMceFilePicker()(() => { tinyCalled = true; }, '', { filetype: 'image' });
    assert.equal(tinyCalled, false, 'TinyMCE adapter must reject URL with embedded credentials');

    let tipCalled = false;
    await tipMod.insertFromFileManager({
        chain: () => ({
            focus() { return this; },
            setImage() { tipCalled = true; return this; },
            insertContent() { tipCalled = true; return this; },
            run() { },
        }),
    });
    assert.equal(tipCalled, false, 'Tiptap adapter must reject URL with embedded credentials');

    // 3. U-05 & U-07: Localized formatBytes for file items & quota in fr_FR (Mo / Ko) + RTL trash arrow & deletedAtTs
    const bLoc = new BrowserController();
    bLoc.localeValue = 'fr_FR';
    bLoc.i18nValue = {
        'units.b': 'o',
        'units.kb': 'Ko',
        'units.mb': 'Mo',
        'units.gb': 'Go',
        'trash.title': 'Corbeille',
        'trash.back': 'Retour',
        'quota.used': 'Utilisé',
        'quota.remaining': 'Restant',
    };
    bLoc.capabilitiesValue = { showQuota: true };
    bLoc.hasQuotaTextTarget = true;
    bLoc.quotaTextTarget = createFakeEl('span');

    const fileItem = {
        kind: 'file',
        path: 'brochure.pdf',
        name: 'brochure.pdf',
        extension: 'pdf',
        size: 2097152,
        sizeFormatted: '2.00 MB',
        url: '/uploads/brochure.pdf',
    };
    assert.match(bLoc.renderGridItem(fileItem), /Mo/, 'Grid card must format numeric item.size using localized unit Mo');
    assert.match(bLoc.renderListItem(fileItem), /Mo/, 'List row must format numeric item.size using localized unit Mo');

    bLoc.renderQuota({
        enabled: true,
        percent: 25,
        usedBytes: 2097152,
        usedFormatted: '2.00 MB',
        maxBytes: 10485760,
        maxFormatted: '10.00 MB',
    });
    assert.match(bLoc.quotaTextTarget.textContent, /Mo.*\/.*Mo/, 'Quota bar must use localized formatBytes for numeric byte counts');

    const quotaModalHtml = bLoc.renderQuotaDetailsHtml({
        summary: { enabled: true, percent: 25, usedBytes: 2097152, maxBytes: 10485760, remainingBytes: 8388608 },
        counts: { files: 1, folders: 1, trashBytes: 1024, trashFiles: 1, trashPercent: 1 },
        categories: [{ key: 'doc', bytes: 2097152, count: 1, percent: 25 }],
        topFolders: [{ path: 'docs', bytes: 2097152, fileCount: 1 }],
        largestFiles: [{ name: 'brochure.pdf', path: 'docs/brochure.pdf', folder: 'docs', bytes: 2097152 }],
    });
    assert.match(quotaModalHtml, /Mo/);
    assert.match(quotaModalHtml, /Ko/);

    const bRtl = new BrowserController();
    bRtl.element = createFakeEl('div');
    bRtl.localeValue = 'ar_SA';
    bRtl.i18nValue = { 'trash.title': 'سلة المحذوفات', 'trash.back': 'رجوع' };
    bRtl.syncLocaleAttributes();
    bRtl.hasContentAreaTarget = true;
    bRtl.contentAreaTarget = createFakeEl('div');
    bRtl.renderTrash([{ id: 't1', name: 'old.png', originalPath: 'img/old.png', isFolder: false, deletedAt: '2026-11-24 12:00', deletedAtTs: 1732000000 }]);
    assert.match(bRtl.contentAreaTarget.innerHTML, /→\s*رجوع/, 'RTL Trash Back button must use right-pointing arrow →');
    assert.equal(bRtl.contentAreaTarget.innerHTML.includes('2026-11-24 12:00'), false, 'renderTrash must format numeric deletedAtTs with locale formatDate instead of raw ISO string');

    // 4. U-06: Greek final sigma (ς -> σ) folding & onTreeSearchInput IME composition guard
    assert.equal(bLoc.foldSearch('Ὀδυσσεύς'), bLoc.foldSearch('ὀδυσσεύσ'));
    let treeRenderCalls = 0;
    bLoc.renderSidebarTree = () => { treeRenderCalls += 1; };
    bLoc.treeSearchQuery = 'initial';
    bLoc.onTreeSearchInput({ isComposing: true, currentTarget: { value: 'かん' } });
    assert.equal(bLoc.treeSearchQuery, 'initial', 'onTreeSearchInput must ignore IME isComposing=true events');
    assert.equal(treeRenderCalls, 0);
    bLoc.onTreeSearchInput({ isComposing: false, currentTarget: { value: '漢字' } });
    assert.equal(bLoc.treeSearchQuery, '漢字');
    assert.equal(treeRenderCalls, 1);
});

test('12. Pass-8 regressions: hasUnsafeSvgCss escape/comment smuggling, sanitizeInlineSvg presentation/href/xml:base scrub, TEXT_PREVIEW_EXTENSIONS, imageDimsCache, C1/BiDi/CJK path checks, and RTL Lightbox keys', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);

    // 1. T-01: hasUnsafeSvgCss blocking escaped comment opener + split @import and backslash-newline continuation in url()
    const b = new BrowserController();
    assert.equal(
        b.hasUnsafeSvgCss('@unknown \\/*; @im/**/port "https://evil.example/xss.css";'),
        true,
        'Escaped \\/* followed by @im/**/port must be blocked',
    );
    assert.equal(
        b.hasUnsafeSvgCss('fill: u\\\nrl(https://evil.example/a)'),
        true,
        'CSS backslash-newline continuation inside url(...) must be blocked',
    );
    assert.equal(
        b.hasUnsafeSvgCss('fill: u\\\r\nrl(https://evil.example/a)'),
        true,
        'CSS backslash-CRLF continuation inside url(...) must be blocked',
    );

    // 2. T-01: sanitizeInlineSvg stripping xml:base, unsafe fill="url(https://evil.example/x)", and non-ID href="#javascript:alert(1)"
    const prevDOMParser = globalThis.DOMParser;
    const prevXMLSerializer = globalThis.XMLSerializer;
    try {
        const makeAttr = (name, value) => ({
            name,
            localName: name.includes(':') ? name.split(':').pop() : name,
            value,
        });
        const makeSvgEl = (tag, initialAttrs) => {
            const attrMap = new Map(initialAttrs.map((a) => [a.name, a]));
            return {
                nodeType: 1,
                localName: tag,
                namespaceURI: 'http://www.w3.org/2000/svg',
                get attributes() { return Array.from(attrMap.values()); },
                setAttribute(k, v) { attrMap.set(k, makeAttr(k, String(v))); },
                getAttribute(k) { return attrMap.get(k)?.value ?? null; },
                removeAttribute(k) { attrMap.delete(k); },
                remove() { },
            };
        };

        const childUse = makeSvgEl('path', [
            makeAttr('xml:base', 'https://evil.example/'),
            makeAttr('fill', 'url(https://evil.example/x)'),
            makeAttr('filter', 'url(#safe-filter)'),
            makeAttr('href', '#javascript:alert(1)'),
            makeAttr('xlink:href', '#valid_icon-1'),
        ]);
        const rootSvg = makeSvgEl('svg', [
            makeAttr('xml:base', 'https://evil.example/root'),
            makeAttr('stroke', 'u\\\nrl(https://evil.example/s)'),
        ]);
        rootSvg.querySelectorAll = () => [childUse];

        globalThis.DOMParser = class FakeDOMParser {
            parseFromString() {
                return {
                    documentElement: rootSvg,
                    querySelector: () => null,
                    createTreeWalker: () => ({ nextNode: () => null }),
                };
            }
        };
        globalThis.XMLSerializer = class FakeXMLSerializer {
            serializeToString() {
                const dump = (el) => el.attributes.map((a) => `${a.name}="${a.value}"`).join(' ');
                return `<svg ${dump(rootSvg)}><path ${dump(childUse)}/></svg>`;
            }
        };

        const sanitized = b.sanitizeInlineSvg('<svg></svg>', 32);
        assert.equal(sanitized.includes('xml:base'), false, 'xml:base must be stripped');
        assert.equal(sanitized.includes('evil.example'), false, 'External url(...) in fill/stroke must be stripped');
        assert.equal(sanitized.includes('javascript:alert'), false, 'Non-ID href="#javascript:alert(1)" must be stripped');
        assert.equal(sanitized.includes('filter="url(#safe-filter)"'), true, 'Safe local fragment ID url(#safe-filter) must be kept');
        assert.equal(sanitized.includes('xlink:href="#valid_icon-1"'), true, 'Valid local fragment ID #valid_icon-1 must be kept');
    } finally {
        globalThis.DOMParser = prevDOMParser;
        globalThis.XMLSerializer = prevXMLSerializer;
    }

    // 3. T-07: TEXT_PREVIEW_EXTENSIONS excludes xml, js, mjs while keeping txt, md, json, yaml, css, sql
    b.previewTextUrlValue = '/_liveux-file-manager/api/preview-text';
    for (const blockedExt of ['xml', 'js', 'mjs']) {
        assert.equal(
            b.getPreviewKind({ kind: 'file', name: `file.${blockedExt}`, extension: blockedExt, isImage: false }),
            null,
            `TEXT_PREVIEW_EXTENSIONS must exclude .${blockedExt}`,
        );
    }
    for (const allowedExt of ['txt', 'md', 'json', 'yaml', 'css', 'sql']) {
        assert.equal(
            b.getPreviewKind({ kind: 'file', name: `file.${allowedExt}`, extension: allowedExt, isImage: false }),
            'text',
            `TEXT_PREVIEW_EXTENSIONS must still allow .${allowedExt}`,
        );
    }

    // 4. P8-12: renderInspector caches image dimensions in imageDimsCache without re-fetching on repeat selection
    const prevImage = globalThis.Image;
    try {
        let imageConstructCount = 0;
        let lastCreatedProbe = null;
        globalThis.Image = class ProbeImage {
            constructor() {
                imageConstructCount += 1;
                this.naturalWidth = 1920;
                this.naturalHeight = 1080;
                this.onload = null;
                this.onerror = null;
                lastCreatedProbe = this;
            }
            set src(v) {
                this._src = v;
                if (v && typeof this.onload === 'function') {
                    this.onload();
                }
            }
        };

        const bInsp = new BrowserController();
        bInsp.volumeValue = 'local';
        bInsp.pathValue = '';
        bInsp.inspectorModeValue = 'always';
        bInsp.capabilitiesValue = {};
        bInsp.i18nValue = {};
        bInsp.hasInspectorTarget = true;
        const dimsEl = createFakeEl('span');
        bInsp.inspectorTarget = {
            ...createFakeEl('aside'),
            querySelector: (sel) => (sel === '[data-insp-dims]' ? dimsEl : null),
            querySelectorAll: () => [],
        };
        const imgItem = {
            kind: 'file',
            path: 'hero.webp',
            name: 'hero.webp',
            extension: 'webp',
            isImage: true,
            size: 4096,
            lastModified: 1732000000,
            url: '/uploads/hero.webp',
            thumbUrl: '/uploads/hero.webp',
        };
        bInsp.dirCache = new Map([['local::', [imgItem]]]);
        bInsp.selectedMap = new Map([['hero.webp', imgItem]]);

        bInsp.renderInspector();
        const expectedDims = `${bInsp.formatNumber(1920)} × ${bInsp.formatNumber(1080)} px`;
        assert.equal(imageConstructCount, 1, 'First selection probes image dimensions once');
        assert.equal(dimsEl.textContent, expectedDims);
        assert.equal(bInsp.imageDimsCache.get('local::hero.webp::1732000000::4096'), expectedDims);

        dimsEl.textContent = '…';
        bInsp.renderInspector();
        assert.equal(imageConstructCount, 1, 'Repeat selection of same image must read from imageDimsCache without creating new Image()');
        assert.equal(dimsEl.textContent, expectedDims);
        assert.ok(lastCreatedProbe !== null);
    } finally {
        globalThis.Image = prevImage;
    }

    // 5. P8-13: isSafeUrl / isSafeRelativePath blocking C1/BiDi/zero-width controls and CJK dot traversal
    const p = new PickerController();
    p.previewUrlPrefixValue = '/uploads';
    const d = new DialogController();

    for (const badControlUrl of [
        '/uploads/a\u0085b.jpg', // C1 control NEL
        '/uploads/a\u200Bb.jpg', // Zero-width space
        '/uploads/a\u202Eb.jpg', // RLO BiDi override
        '/uploads/a\u2028b.jpg', // Line separator
        '/uploads/a\u2066b.jpg', // LRI BiDi isolate
        '/uploads/a\uFEFFb.jpg', // BOM
        '/uploads/a\u061Cb.jpg', // Arabic letter mark
    ]) {
        assert.equal(b.isSafeUrl(badControlUrl), false, `Browser isSafeUrl must block ${JSON.stringify(badControlUrl)}`);
        assert.equal(p.isSafeUrl(badControlUrl), false, `Picker isSafeUrl must block ${JSON.stringify(badControlUrl)}`);
        assert.equal(d.isSafeSameOriginPath(badControlUrl), false, `Dialog isSafeSameOriginPath must block ${JSON.stringify(badControlUrl)}`);
    }

    assert.equal(p.isSafeRelativePath('。\u{3002}/a.jpg'), false, 'CJK ideographic fullstop traversal 。。/a.jpg must be blocked');
    assert.equal(p.isSafeRelativePath('.\uFF0E/a.jpg'), false, 'Fullwidth fullstop traversal .．/a.jpg must be blocked');
    assert.equal(p.isSafeRelativePath('.trash/a.jpg'), false, '.trash/a.jpg must be blocked');
    assert.equal(p.isSafeRelativePath('sub/.trash/a.jpg'), false, 'sub/.trash/a.jpg must be blocked');
    assert.equal(p.isSafeRelativePath('.hidden/a.jpg'), false, 'Leading-dot segment must be blocked');
    assert.equal(p.isSafeRelativePath('/abs/a.jpg'), false, 'Leading slash must be blocked in relative path');
    assert.equal(p.isSafeRelativePath('\\abs\\a.jpg'), false, 'Leading backslash must be blocked in relative path');
    assert.equal(p.isSafeRelativePath('products/\u202Egpj.exe'), false, 'BiDi override in relative path must be blocked');
    assert.equal(p.isSafeRelativePath('products/2026/hero.jpg'), true, 'Clean relative path must be allowed');
    assert.equal(p.previewSrc('。\u{3002}/a.jpg'), '', 'previewSrc must return empty string for CJK dot traversal');
    assert.equal(p.previewSrc('.trash/a.jpg'), '', 'previewSrc must return empty string for .trash path');

    // Verify WYSIWYG adapters also block BiDi/C1/zero-width URLs
    globalThis.window = {
        LiveUXFileManager: {
            open: async () => [{ url: 'https://cdn.example.com/\u202Egpj.exe', path: 'a.jpg', name: 'a.jpg', isImage: true }],
        },
    };
    let adapterAccepted = false;
    await ckMod.openFileManagerForCKEditor5({
        model: { change: () => { } },
        commands: { get: () => true },
        execute: () => { adapterAccepted = true; },
    });
    await tinyMod.createTinyMceFilePicker()(() => { adapterAccepted = true; }, '', { filetype: 'image' });
    await tipMod.insertFromFileManager({
        chain: () => ({
            focus() { return this; },
            setImage() { adapterAccepted = true; return this; },
            insertContent() { adapterAccepted = true; return this; },
            run() { },
        }),
    });
    assert.equal(adapterAccepted, false, 'All 3 WYSIWYG adapters must reject URLs containing BiDi overrides');

    // 6. P8-14: RTL Lightbox ArrowLeft / ArrowRight inversion
    const bLb = new BrowserController();
    bLb.element = createFakeEl('div');
    bLb.keyboardShortcutsValue = true;
    bLb.isFileManagerActive = () => true;
    bLb.isLightboxOpen = () => true;
    let prevCalls = 0;
    let nextCalls = 0;
    bLb.lightboxPrev = () => { prevCalls += 1; };
    bLb.lightboxNext = () => { nextCalls += 1; };

    bLb.localeValue = 'ar_SA';
    bLb.syncLocaleAttributes();
    bLb.onKeydown({ key: 'ArrowLeft', preventDefault() { } });
    assert.equal(nextCalls, 1, 'In RTL Lightbox, ArrowLeft must advance to next slide (lightboxNext)');
    assert.equal(prevCalls, 0);
    bLb.onKeydown({ key: 'ArrowRight', preventDefault() { } });
    assert.equal(prevCalls, 1, 'In RTL Lightbox, ArrowRight must move to previous slide (lightboxPrev)');

    bLb.localeValue = 'en_US';
    bLb.syncLocaleAttributes();
    bLb.onKeydown({ key: 'ArrowLeft', preventDefault() { } });
    assert.equal(prevCalls, 2, 'In LTR Lightbox, ArrowLeft calls lightboxPrev');
    bLb.onKeydown({ key: 'ArrowRight', preventDefault() { } });
    assert.equal(nextCalls, 2, 'In LTR Lightbox, ArrowRight calls lightboxNext');
});

test('13. Pass-9 regressions: Dialog CSS comment/escape sanitizer & frameUrl query sep, Picker path hardening, RTL marquee, IME onSearchInput, Inspector probe & Lightbox abort', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');

    // 1. DialogController::sanitizeHtmlFragment() CSS comment/escape/line-continuation blocking + frameUrl '?' vs '&'
    const d = new DialogController();
    const prevDomParser = globalThis.DOMParser;
    const prevDoc = globalThis.document;
    const prevWin = globalThis.window;
    const prevFetch = globalThis.fetch;
    try {
        const makeEl = (tag, attrsObj) => {
            const attrs = new Map(Object.entries(attrsObj).map(([k, v]) => [k, { name: k, localName: k.split(':').pop(), value: v }]));
            return {
                tagName: tag.toUpperCase(),
                get attributes() { return Array.from(attrs.values()); },
                removeAttribute(k) { attrs.delete(k); },
                getAttribute(k) { return attrs.get(k)?.value ?? null; },
                querySelectorAll: () => [],
            };
        };

        const testNodes = [
            makeEl('div', { id: 'comment', style: 'background: u/*x*/rl(https://evil.example/x)' }),
            makeEl('div', { id: 'escape', style: 'background: \\75rl(https://evil.example/x)' }),
            makeEl('div', { id: 'cont', style: 'background: u\\\nrl(https://evil.example/x)' }),
            makeEl('div', { id: 'import', style: '@im/**/port "https://evil.example/a.css"' }),
            makeEl('div', { id: 'moz', style: '-moz-binding: url("https://evil.example/xbl.xml#x")' }),
            makeEl('div', { id: 'behav', style: 'behavior: url(https://evil.example/a.htc)' }),
            makeEl('div', { id: 'imgset', style: 'background-image: image-set("https://evil.example/a.png" 1x)' }),
            makeEl('div', { id: 'wkimgset', style: 'background-image: -webkit-image-set("https://evil.example/a.png" 1x)' }),
            makeEl('div', { id: 'svgdata', style: 'background: url(data:image/svg+xml;base64,PHN2Zz4=)' }),
            makeEl('path', { id: 'desync', fill: 'url(#x?a=/*)', filter: 'url(#safe-grad)' }),
            makeEl('div', { id: 'safe', style: 'width: 120px; color: var(--liveux-fm-accent);' }),
        ];

        globalThis.DOMParser = class FakeDOMParser {
            parseFromString() {
                let consumed = false;
                return {
                    body: {
                        querySelectorAll: (sel) => (sel === '*' ? testNodes : []),
                        get firstChild() {
                            if (consumed) return null;
                            consumed = true;
                            return testNodes[0];
                        },
                    },
                };
            }
        };
        globalThis.document = {
            documentElement: { dir: 'ltr', lang: 'en' },
            createElement: (t) => createFakeEl(t),
            createDocumentFragment: () => ({ children: [], appendChild(n) { this.children.push(n); } }),
        };

        d.sanitizeHtmlFragment('<div>test</div>');
        for (const n of testNodes.slice(0, 9)) {
            assert.equal(n.getAttribute('style'), null, `Unsafe CSS on #${n.getAttribute('id')} must be stripped`);
        }
        assert.equal(testNodes[9].getAttribute('fill'), null, 'W3C comment desync url(#x?a=/*) on SVG fill must be stripped');
        assert.equal(testNodes[9].getAttribute('filter'), 'url(#safe-grad)', 'Safe local SVG fragment url(#safe-grad) must remain');
        assert.equal(testNodes[10].getAttribute('style'), 'width: 120px; color: var(--liveux-fm-accent);', 'Safe inline style must remain');

        // frameUrlValue with pre-existing query string uses '&' instead of '?'
        d.dialogTarget = createFakeEl('dialog');
        d.bodyTarget = createFakeEl('div');
        d.hasBodyTarget = true;
        d.frameUrlValue = '/_liveux-file-manager/dialog?tenant=acme';
        d.profileValue = 'default';
        d.errorMessageValue = 'Err';
        let seenFetchUrl = '';
        globalThis.fetch = async (url) => {
            seenFetchUrl = String(url);
            return { ok: false, status: 500, text: async () => '' };
        };
        await d.handleGlobalOpen({ detail: { profile: 'default' } });
        assert.match(seenFetchUrl, /^\/_liveux-file-manager\/dialog\?tenant=acme&profile=default/);
    } finally {
        globalThis.DOMParser = prevDomParser;
        globalThis.document = prevDoc;
        globalThis.window = prevWin;
        globalThis.fetch = prevFetch;
    }

    // 2. PickerController::isSafeRelativePath() & extractExtension('a.svg?x=1')
    const p = new PickerController();
    for (const unsafeRel of [
        'a\uFF0Fb.jpg',
        'a\u2215b.jpg',
        'a\u2044b.jpg',
        'a\uFF3Cb.jpg',
        '%2e%2e/a.jpg',
        'sub/%2e%2e/a.jpg',
        'a%2fb.jpg',
        'a%5cb.jpg',
        'a%00b.jpg',
        'con.jpg',
        'CON',
        'sub/prn.png',
        'aux.webp',
        'nul.jpg',
        'com1.png',
        'lpt9.jpg',
        'conin$.jpg',
        'conout$.png',
        'C:secret.jpg',
        'c:\\windows\\win.ini',
        'a.svg?x=1',
        'a.png#frag',
    ]) {
        assert.equal(p.isSafeRelativePath(unsafeRel), false, `isSafeRelativePath MUST reject: ${JSON.stringify(unsafeRel)}`);
    }
    assert.equal(p.isSafeRelativePath('products/50%off.png'), true);
    assert.equal(p.isSafeRelativePath('products/2026/hero.webp'), true);
    assert.equal(p.extractExtension('a.svg?x=1'), 'SVG');

    // 3. BrowserController RTL marquee: left-scrollbar guard, deferred card query, negative scrollLeft, 0-selection anchor reset & file volume
    try {
        const bMarq = new BrowserController();
        bMarq.element = createFakeEl('div');
        bMarq.localeValue = 'ar_SA';
        bMarq.syncLocaleAttributes();
        bMarq.trashMode = false;
        bMarq.modeValue = 'inline';
        bMarq.multipleValue = true;
        bMarq.volumeValue = 'media';
        bMarq.hasContentAreaTarget = true;
        bMarq.selectedMap = new Map();
        bMarq.lastSelectedPath = 'stale.jpg';
        bMarq.syncSelectionUi = () => { };
        bMarq.renderInspector = () => { };

        let queryCalls = 0;
        let rectCalls = 0;
        let appendedMarquee = null;
        const listeners = new Map();
        globalThis.document = {
            body: { style: {} },
            createElement: (t) => createFakeEl(t),
            addEventListener: (ev, fn) => listeners.set(ev, fn),
            removeEventListener: (ev) => listeners.delete(ev),
        };
        globalThis.window = {
            addEventListener: (ev, fn) => listeners.set(`w:${ev}`, fn),
            removeEventListener: (ev) => listeners.delete(`w:${ev}`),
        };

        const fakeFileCard = {
            dataset: { path: 'rtl-pic.jpg', name: 'rtl-pic.jpg', url: '/uploads/rtl-pic.jpg', volume: 'media' },
            hasAttribute: () => false,
            getBoundingClientRect: () => {
                rectCalls += 1;
                // Inside scrolled RTL container (scrollLeft = -120): cr.left - (0 + 16) + (-120) = -46 .. 54
                return { left: 90, right: 190, top: 20, bottom: 120 };
            },
        };
        const fakeContainer = {
            clientLeft: 16,
            clientTop: 0,
            clientWidth: 484,
            clientHeight: 400,
            scrollWidth: 700,
            scrollHeight: 600,
            scrollLeft: -120,
            scrollTop: 0,
            getBoundingClientRect: () => ({ left: 0, top: 0, width: 500, height: 400 }),
            querySelectorAll: () => {
                queryCalls += 1;
                return [fakeFileCard];
            },
            appendChild: (node) => { appendedMarquee = node; },
        };
        bMarq.contentAreaTarget = fakeContainer;

        // 3a. Click on RTL left scrollbar (startClientX = 8 < 0 + 16) is ignored immediately
        bMarq.onContentMouseDown({ button: 0, clientX: 8, clientY: 50, target: { closest: () => null } });
        assert.equal(listeners.has('mousemove'), false, 'RTL left-scrollbar click must NOT start marquee listeners');

        // 3b. Click inside content area defers querySelectorAll / getBoundingClientRect until dx/dy >= 6
        bMarq.onContentMouseDown({ button: 0, clientX: 70, clientY: 15, target: { closest: () => null } });
        assert.equal(queryCalls, 0, 'Must NOT query cards synchronously on mousedown');
        assert.equal(rectCalls, 0, 'Must NOT measure card rects on mousedown');
        listeners.get('mousemove')({ clientX: 73, clientY: 18 }); // dx=3, dy=3 (< 6)
        assert.equal(queryCalls, 0, 'Must NOT query cards before 6px threshold');

        // Drag across negative scrollLeft area in RTL (left = -46..64) selects rtl-pic.jpg with volume
        listeners.get('mousemove')({ clientX: 200, clientY: 130 });
        assert.equal(queryCalls, 1, 'Queries cards once active transitions to true');
        assert.equal(appendedMarquee?.style?.left, '-66px', 'RTL negative scrollLeft must preserve negative left coordinate without clamping to 0');
        assert.equal(bMarq.selectedMap.get('rtl-pic.jpg')?.volume, 'media');
        assert.equal(bMarq.lastSelectedPath, 'rtl-pic.jpg');
        listeners.get('mouseup')();

        // 3c. Dragging marquee in empty area (0 items selected) resets lastSelectedPath to null
        bMarq.onContentMouseDown({ button: 0, clientX: 300, clientY: 300, target: { closest: () => null } });
        listeners.get('mousemove')({ clientX: 350, clientY: 350 });
        listeners.get('mouseup')();
        assert.equal(bMarq.selectedMap.size, 0);
        assert.equal(bMarq.lastSelectedPath, null, 'Empty marquee drag must reset lastSelectedPath to null');

        // 4. onSearchInput IME isComposing guard + itemMatchesSearch(item, query, true) + RTL Alt+Arrow + Inspector probe cancel + Lightbox AbortController
        const bSearch = new BrowserController();
        bSearch.searchQuery = 'kept';
        bSearch.rawSearchQuery = 'kept';
        bSearch.recursiveSearch = false;
        bSearch.trashMode = false;
        let searchViewCalls = 0;
        bSearch.applyClientView = () => { searchViewCalls += 1; };
        await bSearch.onSearchInput({ isComposing: true, currentTarget: { value: 'か' } });
        assert.equal(bSearch.searchQuery, 'kept', 'onSearchInput must return early during IME isComposing');
        assert.equal(searchViewCalls, 0, 'onSearchInput must not call applyClientView during IME isComposing');
        assert.equal(bSearch.itemMatchesSearch({ kind: 'file', name: 'İletişim.pdf' }, 'iletişim', true), true);

        // Pre-folded treeCache entries match in renderSidebarTree
        const folded = bSearch.normalizeTreeFolders([{ name: 'İletişim', path: 'kurumsal/iletişim', parent: '' }]);
        assert.equal(folded[0]._foldedName, 'iletişim');
        assert.equal(folded[0]._foldedPath, 'kurumsal/iletişim');

        // RTL Alt+ArrowLeft / Alt+ArrowRight
        const bHist = new BrowserController();
        bHist.element = createFakeEl('div');
        bHist.localeValue = 'ar_SA';
        bHist.syncLocaleAttributes();
        bHist.isFileManagerActive = () => true;
        bHist.keyboardShortcutsValue = true;
        bHist.isLightboxOpen = () => false;
        bHist.isModalOpen = () => false;
        bHist.isContextMenuOpen = () => false;
        bHist.isSettingsPopoverOpen = () => false;
        let histNav = [];
        bHist.goBack = () => histNav.push('back');
        bHist.goForward = () => histNav.push('forward');
        globalThis.document = { activeElement: bHist.element, body: createFakeEl('body') };
        bHist.onKeydown({ key: 'ArrowLeft', altKey: true, ctrlKey: false, metaKey: false, preventDefault() { } });
        bHist.onKeydown({ key: 'ArrowRight', altKey: true, ctrlKey: false, metaKey: false, preventDefault() { } });
        assert.deepEqual(histNav, ['forward', 'back'], 'RTL Alt+ArrowLeft goes forward and Alt+ArrowRight goes back');

        // Inspector probe cancellation on early return (inspectorMode = hidden)
        const bInspCancel = new BrowserController();
        bInspCancel.hasFooterStatusTarget = false;
        bInspCancel.hasInspectorTarget = true;
        bInspCancel.inspectorTarget = createFakeEl('aside');
        bInspCancel.inspectorModeValue = 'hidden';
        const staleProbe = { onload: () => { }, onerror: () => { }, src: '/uploads/old.png' };
        bInspCancel._inspectorImageProbe = staleProbe;
        bInspCancel.selectedMap = new Map();
        bInspCancel.dirCache = new Map();
        bInspCancel.renderInspector();
        assert.equal(bInspCancel._inspectorImageProbe, null, 'renderInspector must clear active image probe before early return');
        assert.equal(staleProbe.src, '', 'renderInspector must cancel in-flight Image probe src before early return');

        // Lightbox text fetch aborts previous request on closeLightbox()
        const bLbAbort = new BrowserController();
        bLbAbort.profileValue = 'default';
        bLbAbort.volumeValue = 'local';
        bLbAbort.previewTextUrlValue = '/_liveux-file-manager/api/preview-text';
        bLbAbort.hasLightboxTarget = true;
        bLbAbort.lightboxTarget = createFakeEl('div');
        bLbAbort.hasLightboxStageTarget = true;
        bLbAbort.lightboxStageTarget = createFakeEl('div');
        bLbAbort.lightboxItems = [{ kind: 'file', name: 'notes.txt', path: 'notes.txt', extension: 'txt' }];
        bLbAbort.lightboxIndex = 0;
        bLbAbort.i18nValue = { 'preview.loading': 'Loading...' };
        let lbSignal = null;
        globalThis.fetch = (_url, opts) => new Promise(() => { lbSignal = opts?.signal; });
        bLbAbort.renderLightboxCurrent();
        assert.equal(lbSignal?.aborted, false);
        bLbAbort.closeLightbox();
        assert.equal(lbSignal?.aborted, true, 'closeLightbox must abort pending lightbox text fetch');
    } finally {
        globalThis.document = prevDoc;
        globalThis.window = prevWin;
        globalThis.fetch = prevFetch;
    }
});

test('14. Pass-10 regressions: ZWNJ/ZWJ global scripts, Dialog stale fetch & namespaced href, Browser MIME wildcards, disconnect/trashMode guards, dirCache LRU cap, media release, RTL/Intl formatting & core.css/package.json', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);

    const prevDoc = globalThis.document;
    const prevWin = globalThis.window;
    const prevFetch = globalThis.fetch;
    const prevDomParser = globalThis.DOMParser;

    try {
        // 1. P10-01: ZWNJ (Persian/Indic) and ZWJ (Emoji/scripts) allowed inside valid filenames/URLs,
        // while edge ZWNJ/ZWJ, extension-splitting ZWNJ/ZWJ, and U+2024 traversal are rejected.
        const p = new PickerController();
        const b = new BrowserController();
        const d = new DialogController();

        const validZwnjRel = 'گالری/می‌روم.jpg';
        const validZwjRel = 'emoji/👨‍👩‍👧.png';
        const validZwnjUrl = '/uploads/می‌روم.jpg';
        const validZwjUrl = 'https://cdn.example.com/👨‍👩‍👧.png';

        assert.equal(p.isSafeRelativePath(validZwnjRel), true);
        assert.equal(p.isSafeRelativePath(validZwjRel), true);
        assert.equal(p.isSafeUrl(validZwnjUrl), true);
        assert.equal(p.isSafeUrl(validZwjUrl), true);
        assert.equal(b.isSafeUrl(validZwnjUrl), true);
        assert.equal(b.isSafeUrl(validZwjUrl), true);
        assert.equal(d.isSafeSameOriginPath(validZwnjUrl), true);
        assert.equal(d.isSafeSameOriginPath(`/uploads/${'👨‍👩‍👧'}.png`), true);

        for (const badRel of [
            '\u200Csecret.jpg',
            'secret.jpg\u200D',
            'xss.s\u200Cvg',
            'shell.p\u200Dhp',
            '‥/etc/passwd',
            'dir/‥/secret.txt',
        ]) {
            assert.equal(p.isSafeRelativePath(badRel), false, `Must reject spoofed path: ${JSON.stringify(badRel)}`);
        }

        // Editor adapters preserve valid ZWNJ/ZWJ URLs (not rejected by [\u200B-\u200F])
        globalThis.window = {
            dispatchEvent: (ev) => {
                ev.preventDefault();
                ev.detail.resolve([
                    { url: validZwnjUrl, path: validZwnjRel, name: 'می‌روم.jpg', isImage: true },
                    { url: validZwjUrl, path: validZwjRel, name: '👨‍👩‍👧.png', isImage: true },
                ]);
                return false;
            },
            addEventListener: () => { },
            removeEventListener: () => { },
        };
        let ckImgs = [];
        await ckMod.openFileManagerForCKEditor5(
            { model: { change: () => { } }, commands: { get: () => true }, execute: (_c, a) => { ckImgs = a.source; } },
            { multiple: true },
        );
        assert.deepEqual(ckImgs, [validZwnjUrl, validZwjUrl]);

        let tinyUrl = null;
        await tinyMod.createTinyMceFilePicker()((u) => { tinyUrl = u; }, '', { filetype: 'image' });
        assert.equal(tinyUrl, validZwnjUrl);

        let tipSrc = null;
        await tipMod.insertFromFileManager({
            chain: () => ({ focus() { return this; }, setImage(a) { tipSrc = a.src; }, run() { } }),
        });
        assert.equal(tipSrc, validZwnjUrl);

        // 2. P10-02: DialogController.sanitizeHtmlFragment strips namespaced attributes (localName === 'href'/'src')
        const removedAttrs = [];
        const svgUseEl = {
            tagName: 'USE',
            attributes: [{ name: 'svg:href', localName: 'href', value: 'javascript:alert(1)' }],
            removeAttribute: (n) => removedAttrs.push(n),
        };
        globalThis.DOMParser = class FakeDOMParser {
            parseFromString() {
                return {
                    body: {
                        firstChild: null,
                        querySelectorAll: (sel) => (sel === '*' ? [svgUseEl] : []),
                    },
                };
            }
        };
        globalThis.document = {
            createElement: (t) => createFakeEl(t),
            createDocumentFragment: () => ({ appendChild() { }, querySelectorAll: () => [] }),
        };
        d.sanitizeHtmlFragment('<svg><use svg:href="javascript:alert(1)"></use></svg>');
        assert.deepEqual(removedAttrs, ['svg:href'], 'Namespaced svg:href javascript: URI must be stripped');

        // 3. P10-03: BrowserController.matchesMimePatternList supports image/*, */*, and ;charset parameters
        assert.equal(b.matchesMimePatternList('image/webp', ['image/*']), true);
        assert.equal(b.matchesMimePatternList('image/png; charset=utf-8', ['image/png']), true);
        assert.equal(b.matchesMimePatternList('application/pdf', ['*/*']), true);
        assert.equal(b.matchesMimePatternList('text/html', ['image/*', 'application/pdf']), false);

        // 4. P10-13: DialogController.handleGlobalOpen ignores superseded response before DOM mutation
        const dlgRace = new DialogController();
        dlgRace.element = createFakeEl('div');
        dlgRace.dialogTarget = createFakeEl('dialog');
        dlgRace.bodyTarget = createFakeEl('div');
        dlgRace.hasBodyTarget = true;
        dlgRace.frameUrlValue = '/_fm/dialog';
        let fetchCount = 0;
        let resolveFirst = null;
        globalThis.fetch = () => {
            fetchCount += 1;
            const seq = fetchCount;
            if (seq === 1) {
                return new Promise((resolve) => {
                    resolveFirst = () => resolve({
                        ok: true,
                        headers: new Map([['content-type', 'text/html'], ['x-liveux-fm-dialog', '1']]),
                        text: async () => '<div>STALE</div>',
                    });
                });
            }
            return Promise.resolve({
                ok: true,
                headers: new Map([['content-type', 'text/html'], ['x-liveux-fm-dialog', '1']]),
                text: async () => '<div>FRESH</div>',
            });
        };
        const fragTexts = [];
        dlgRace.sanitizeHtmlFragment = (html) => ({ querySelector: () => null, _html: html });
        dlgRace.bodyTarget.replaceChildren = (frag) => { fragTexts.push(frag._html); };
        const p1 = dlgRace.handleGlobalOpen({ detail: { profile: 'a' } });
        const p2 = dlgRace.handleGlobalOpen({ detail: { profile: 'b' } });
        resolveFirst();
        await Promise.all([p1, p2]);
        assert.deepEqual(fragTexts, ['<div>FRESH</div>'], 'Superseded DialogController fetch must never overwrite fresher modal body');

        // 5. P10-13: disconnect() guards enqueueFiles/pumpUploadQueue/fetchDirectory, resets needsPostUploadRefresh & releases media
        const bLife = new BrowserController();
        const fakeSource = { removeAttribute: (a) => removedAttrs.push(`source:${a}`) };
        let mediaPaused = 0;
        let mediaLoaded = 0;
        let mediaSrcCleared = false;
        const fakeMedia = {
            pause: () => { mediaPaused += 1; },
            removeAttribute: (a) => { if (a === 'src') mediaSrcCleared = true; },
            querySelectorAll: () => [fakeSource],
            load: () => { mediaLoaded += 1; },
        };
        bLife.element = {
            ...createFakeEl('div'),
            querySelectorAll: (sel) => (sel === 'video, audio' ? [fakeMedia] : []),
        };
        bLife.needsPostUploadRefresh = true;
        bLife.listUrlValue = '/api/list';
        bLife.uploadJobs = [];
        globalThis.document = { removeEventListener() { } };
        bLife.disconnect();
        assert.equal(bLife.needsPostUploadRefresh, false);
        assert.equal(mediaPaused, 1);
        assert.equal(mediaSrcCleared, true);
        assert.equal(mediaLoaded, 1);
        assert.equal(await bLife.fetchDirectory('local', ''), null, 'Disconnected controller must not fetch directory');
        await bLife.enqueueFiles([{ name: 'a.jpg', size: 10, type: 'image/jpeg' }], '');
        assert.equal(bLife.uploadJobs.length, 0, 'Disconnected controller must not enqueue upload jobs');

        // maybeFlushPostUploadRefresh skips fetchDirectory when trashMode === true
        const bTrashFlush = new BrowserController();
        bTrashFlush.isDisconnected = false;
        bTrashFlush.uploadJobs = [];
        bTrashFlush.activeUploads = 0;
        bTrashFlush.needsPostUploadRefresh = true;
        bTrashFlush.trashMode = true;
        bTrashFlush.invalidateClientCaches = () => { };
        let fetchDirCalls = 0;
        let refreshTreeCalls = 0;
        bTrashFlush.fetchDirectory = async () => { fetchDirCalls += 1; return true; };
        bTrashFlush.refreshFullTree = async () => { refreshTreeCalls += 1; };
        await bTrashFlush.maybeFlushPostUploadRefresh();
        assert.equal(fetchDirCalls, 0, 'Must not overwrite trash view with fetchDirectory after background upload');
        assert.equal(refreshTreeCalls, 1);

        // 6. P10-13: setDirCache LRU cap at 64 & Intl formatter caching with locale invalidation
        const bCache = new BrowserController();
        for (let i = 0; i < 70; i += 1) {
            bCache.setDirCache(`local::dir_${i}`, [{ name: `f${i}` }]);
        }
        assert.equal(bCache.dirCache.size, 64, 'dirCache must be capped at 64 entries');
        assert.equal(bCache.dirCache.has('local::dir_0'), false, 'Oldest entries must be evicted');
        assert.equal(bCache.dirCache.has('local::dir_69'), true);

        bCache.localeValue = 'en_US';
        const fmt1 = bCache.getNumberFormatter(0);
        const fmt2 = bCache.getNumberFormatter(0);
        assert.equal(fmt1, fmt2, 'Intl.NumberFormat must be reused from _intlCache for same locale');
        assert.equal(bCache.formatNumber(1280), '1,280');
        bCache.localeValue = 'de_DE';
        const fmtDe = bCache.getNumberFormatter(0);
        assert.notEqual(fmtDe, fmt1, 'Changing locale must use distinct Intl formatter');
        assert.equal(bCache.formatNumber(1280), '1.280');

        // RTL dir="ltr" and localized number on lightboxCounterTarget & inspector
        const bRtlUi = new BrowserController();
        bRtlUi.localeValue = 'de_DE';
        bRtlUi.hasLightboxCounterTarget = true;
        bRtlUi.lightboxCounterTarget = createFakeEl('span');
        bRtlUi.hasLightboxStageTarget = true;
        bRtlUi.lightboxStageTarget = createFakeEl('div');
        bRtlUi.lightboxItems = Array.from({ length: 1200 }, (_, i) => ({ kind: 'file', name: `f${i}.jpg`, url: '/uploads/a.jpg', isImage: true }));
        bRtlUi.lightboxIndex = 999;
        await bRtlUi.renderLightboxCurrent();
        assert.equal(bRtlUi.lightboxCounterTarget.dir, 'ltr');
        assert.equal(bRtlUi.lightboxCounterTarget.textContent, '1.000 / 1.200');

        // 7. Static file checks: picker.html.twig, core.css & assets/package.json
        const bundleRoot = path.resolve(__dirname, '../..');
        const pickerTwig = fs.readFileSync(path.join(bundleRoot, 'templates/form/picker.html.twig'), 'utf8');
        assert.match(pickerTwig, /data-fm-color-scheme="/);

        const coreCss = fs.readFileSync(path.join(bundleRoot, 'assets/styles/core.css'), 'utf8');
        const darkTailwindOccurrences = coreCss.match(/\.liveux-fm-picker\[data-fm-color-scheme="dark"\]\[data-liveux-theme="tailwind"\]/g) || [];
        assert.equal(darkTailwindOccurrences.length, 1, 'Duplicate standalone Tailwind picker dark rule in core.css must be removed');
        assert.match(coreCss, /\[data-share-url-input\]/);

        const pkgJson = JSON.parse(fs.readFileSync(path.join(bundleRoot, 'assets/package.json'), 'utf8'));
        assert.equal(pkgJson.type, 'module');
    } finally {
        globalThis.document = prevDoc;
        globalThis.window = prevWin;
        globalThis.fetch = prevFetch;
        globalThis.DOMParser = prevDomParser;
    }
});

test('15. Pass-11 regressions: picker previewSrc host scope, encoded-slash URL guards, global RTL/search folding, disconnect hygiene & accessible Twig/CSS', async () => {
    const prevDoc = globalThis.document;
    const prevFetch = globalThis.fetch;

    try {
        const PickerController = await loadStimulusController('controllers/picker_controller.js');
        const BrowserController = await loadStimulusController('controllers/browser_controller.js');
        const DialogController = await loadStimulusController('controllers/dialog_controller.js');

        // 1. P11-06 / T11-09: Picker previewSrc() does not rewrite third-party CDN /download URLs
        const p = new PickerController();
        const cdnDownloadSvg = 'https://cdn.example.com/download?path=brand-logo.svg';
        const bundleDownloadSvg = 'https://app.example.com/admin/_liveux-file-manager/download?profile=docs&path=vec.SVG';
        const rootDownloadSvg = '/download?profile=docs&path=vec.svg';
        assert.equal(p.previewSrc(cdnDownloadSvg), cdnDownloadSvg, 'Third-party /download URLs must never be rewritten to /thumb');
        assert.equal(
            p.previewSrc(bundleDownloadSvg),
            'https://app.example.com/admin/_liveux-file-manager/thumb?profile=docs&path=vec.SVG',
        );
        assert.equal(p.previewSrc(rootDownloadSvg), '/thumb?profile=docs&path=vec.svg');

        // 2. P11-07: Encoded slash/backslash bypasses right after leading '/' are rejected across controllers
        assert.equal(p.isSafeUrl('/%2fevil.com/a.png'), false);
        assert.equal(p.isSafeUrl('/%5cevil.com/a.png'), false);
        assert.equal(p.isSafeUrl('/uploads/safe.png'), true);

        const d = new DialogController();
        assert.equal(d.isSafeSameOriginPath('/%2fevil'), false);
        assert.equal(d.isSafeSameOriginPath('/%5Cevil'), false);
        assert.equal(d.isSafeSameOriginPath('/_liveux-file-manager/dialog'), true);

        const b = new BrowserController();
        assert.equal(b.isSafeHttpOrPathUrl('/%2Fevil.com'), false);
        assert.equal(b.isSafeHttpOrPathUrl('/%5cevil.com'), false);
        assert.equal(b.isSafeHttpOrPathUrl('/uploads/ok.jpg'), true);

        // 3. P11-12: Global RTL script detection (Arabic/Hebrew vs Greek/Armenian/Georgian/Bengali) & German ß / Greek ς / Turkish İ search folding
        assert.equal(d.isRtlLocale('sd-Arab'), true);
        assert.equal(d.isRtlLocale('sd-Deva'), false);
        assert.equal(b.isRtlLocale('ks-Arab'), true);
        assert.equal(b.isRtlLocale('ks-Grek'), false);
        assert.equal(b.isRtlLocale('ka-Geor'), false);

        assert.equal(b.foldSearch('Straße'), 'strasse');
        assert.equal(b.foldSearch('STRAẞE'), 'strasse');
        assert.equal(b.foldSearch('İstanbul'), 'istanbul');
        assert.equal(b.foldSearch('λόγος'), 'λόγοσ');
        assert.equal(b.foldSearch('مرحبًا'), 'مرحبًا');

        // 4. P11-12: SVG CSS guard blocks data: URLs consistently with DialogController
        assert.equal(b.hasUnsafeSvgCss('background: data:text/plain,abc'), true);
        assert.equal(d.hasUnsafeCssValue('background: data:text/plain,abc'), true);

        // 5. P11-12: Disconnected controller clears/ignores refreshFullTree & fetchTrash
        const bTree = new BrowserController();
        bTree.isDisconnected = true;
        bTree.treeUrlValue = '/api/tree';
        bTree.volumeValue = 'local';
        bTree.treeCache = new Map();
        globalThis.fetch = async () => ({
            ok: true,
            json: async () => ({ ok: true, folders: [{ name: 'Stale', path: 'stale' }] }),
        });
        await bTree.refreshFullTree(true);
        assert.equal(bTree.treeCache.size, 0, 'Disconnected BrowserController must not populate treeCache');
        assert.equal(bTree.treeAbortController, null, 'refreshFullTree must clear treeAbortController in finally');

        // 6. Editor adapters reject encoded slashes after '/'
        const adapterNames = ['ckeditor5_adapter.js', 'tinymce_adapter.js', 'tiptap_adapter.js'];
        const bundleRoot = path.resolve(__dirname, '../..');
        for (const adapterFile of adapterNames) {
            const src = fs.readFileSync(path.join(bundleRoot, 'assets/js/integrations', adapterFile), 'utf8');
            assert.match(src, /%2\[fF\]\|%5\[cC\]/, `${adapterFile} must block /%2f and /%5c URLs`);
        }

        // 7. Static Twig, CSS, Skill & Doc checks (T11-06, T11-07, accessibility, RTL)
        const topbarTwig = fs.readFileSync(path.join(bundleRoot, 'templates/base/parts/_topbar.html.twig'), 'utf8');
        assert.match(topbarTwig, /aria-live="polite"/);
        const sidebarTwig = fs.readFileSync(path.join(bundleRoot, 'templates/base/parts/_sidebar.html.twig'), 'utf8');
        assert.match(sidebarTwig, /class="liveux-fm-quota-more"\s+aria-hidden="true"/);

        const coreCss = fs.readFileSync(path.join(bundleRoot, 'assets/styles/core.css'), 'utf8');
        assert.match(coreCss, /\[data-page-jump-input\]/);
        assert.match(coreCss, /\.liveux-fm-path-bar code/);

        const themeSkill = fs.readFileSync(path.join(bundleRoot, 'skills/liveux-file-manager-theming-and-icons/SKILL.md'), 'utf8');
        assert.equal(themeSkill.includes("{% extends '@!LiveUXFileManager"), false, 'Theming SKILL.md must not instruct broken @!LiveUXFileManager extends');
        assert.match(themeSkill, /theme:\s*'file_manager\/corporate_layout\.html\.twig'/);
    } finally {
        globalThis.document = prevDoc;
        globalThis.fetch = prevFetch;
    }
});

test('16. Pass-12 Global UI, Folder Count, CSS/SVG XSS, Encoded URL, Non-Latin Shortcuts & LocalStorage Regression', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');

    const bundleRoot = path.resolve(__dirname, '../..');
    const b = new BrowserController();
    const d = new DialogController();
    const p = new PickerController();

    // 1. P12 CSS image() / cross-fade() blocking in both BrowserController & DialogController
    assert.equal(b.hasUnsafeSvgCss('background: image("https://evil.test/x.png")'), true);
    assert.equal(b.hasUnsafeSvgCss('background: -webkit-cross-fade(url(https://evil.test/a.png), url(https://evil.test/b.png), 50%)'), true);
    assert.equal(d.hasUnsafeCssValue('background: image("https://evil.test/x.png")'), true);
    assert.equal(d.hasUnsafeCssValue('background: cross-fade(url(https://evil.test/a.png), 50%)'), true);

    // 2. P12 Double-encoded slash/backslash & leading space bypasses blocked consistently
    for (const bad of ['/%252fevil.com/x.png', '/%255cevil.com/x.png', '/ evil.com/x.png']) {
        assert.equal(b.isSafeHttpOrPathUrl(bad), false);
        assert.equal(d.isSafeSameOriginPath(bad), false);
        assert.equal(p.isSafeUrl(bad), false);
    }

    // 3. Editor adapters block %25 double-encoded slashes too
    for (const adapterFile of ['ckeditor5_adapter.js', 'tinymce_adapter.js', 'tiptap_adapter.js']) {
        const src = fs.readFileSync(path.join(bundleRoot, 'assets/js/integrations', adapterFile), 'utf8');
        assert.match(src, /%25\(/, `${adapterFile} must block double-encoded /%252f and /%255c URLs`);
    }

    // 4. PickerController custom prefix previewSrc() + Hangul invisible filler / trailing dot guard
    p.thumbUrlValue = '/media/fm/thumb';
    assert.equal(
        p.previewSrc('/media/fm/download?profile=docs&path=logo.svg'),
        '/media/fm/thumb?profile=docs&path=logo.svg',
    );
    assert.equal(
        p.previewSrc('https://cdn.example.com/download?path=logo.svg'),
        'https://cdn.example.com/download?path=logo.svg',
    );
    assert.equal(p.isSafeRelativePath('folder/file\u3164.png'), false);
    assert.equal(p.isSafeRelativePath('folder/name.'), false);

    // 5. Global folding (Fullwidth ASCII, German Eszett, Greek final sigma, Turkish dotted I) + multilingual digits
    assert.equal(b.foldSearch('ＰＤＦ'), 'pdf');
    assert.equal(b.foldSearch('Straße'), 'strasse');
    assert.equal(b.foldSearch('λόγος'), 'λόγοσ');
    assert.equal(b.foldSearch('İstanbul'), 'istanbul');
    assert.equal(b.parseLocalizedInt('١٢'), 12);
    assert.equal(b.parseLocalizedInt('۴۵'), 45);
    assert.equal(b.parseLocalizedInt('२३'), 23);

    // 6. Folder item counts render with dir="ltr" + 'text' variant and localized formatter
    b.capabilitiesValue = { showFolderItemCount: true };
    b.localeValue = 'en';
    assert.equal(b.renderFolderCountHtml(12, 'tile'), '<span class="liveux-fm-folder-count" dir="ltr">12</span>');
    assert.equal(b.renderFolderCountHtml(12, 'tree'), '<span class="liveux-fm-tree-count" dir="ltr">12</span>');
    assert.equal(b.renderFolderCountHtml(12, 'text'), '12');

    // 7. Non-Latin keyboard shortcuts resolve via event.code fallback (e.g. Cyrillic Ctrl+Ф/KeyA)
    const prevDoc = globalThis.document;
    globalThis.document = { activeElement: null };
    try {
        let selectedAllCalled = false;
        b.trashMode = false;
        b.selectedMap = new Map();
        b.element = { closest: () => null, contains: () => true };
        b.capabilitiesValue = { showFolderItemCount: true, keyboardShortcuts: true };
        b.keyboardShortcutsValue = true;
        b.isModalOpen = () => false;
        b.isFileManagerActive = () => true;
        b.selectAllVisible = () => { selectedAllCalled = true; };
        let prevented = false;
        b.onKeydown({
            ctrlKey: true,
            metaKey: false,
            key: 'ф',
            code: 'KeyA',
            target: null,
            preventDefault: () => { prevented = true; },
        });
        assert.equal(prevented, true);
        assert.equal(selectedAllCalled, true);

        // 8. doubleClickFile in dialog falls back to preview when doubleClickToSelect is false
        let previewOpened = false;
        b.element = { closest: (sel) => (sel === 'dialog' ? {} : null) };
        b.doubleClickToSelectValue = false;
        b.selectFile = () => { };
        b.getActiveItemList = () => [{ path: 'photo.jpg', kind: 'file', isImage: true }];
        b.getPreviewKind = () => 'image';
        b.openPreview = () => { previewOpened = true; };
        b.doubleClickFile({
            ctrlKey: false,
            metaKey: false,
            shiftKey: false,
            target: { closest: () => null },
            currentTarget: { dataset: { path: 'photo.jpg' } },
        });
        assert.equal(previewOpened, true);
    } finally {
        globalThis.document = prevDoc;
    }

    // 9. Static Dialog SVG attribute scrubbers & core.css folder tile selection/BiDi rules
    const dlgSrc = fs.readFileSync(path.join(bundleRoot, 'assets/controllers/dialog_controller.js'), 'utf8');
    assert.match(dlgSrc, /name === 'poster'.*srcset.*imagesrcset/);

    const coreCss = fs.readFileSync(path.join(bundleRoot, 'assets/styles/core.css'), 'utf8');
    assert.match(coreCss, /\.liveux-fm-folder-tile\.is-selected\s+\.liveux-fm-folder-count/);
    assert.match(coreCss, /\.liveux-fm-folder-tile:focus-within\s+\.liveux-fm-folder-count/);
    assert.match(coreCss, /unicode-bidi:\s*isolate/);
});

test('17. Pass-13 hardening: cross-origin drag token, ImageEditor onload guard, upload pause/resume worker lock, AltGr & CJK/grouped numerals, VS16 emoji paths, compact tile padding, and adapter path checks', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tiptapMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);

    // 1. Drag instance token prevents cross-origin / cross-instance drag spoofing
    const bDrag = new BrowserController();
    bDrag.dragInstanceToken = 'inst-alpha';
    bDrag.capabilitiesValue = { allowDragMove: true, copy: true };
    bDrag.trashMode = false;
    bDrag.volumeValue = 'local';
    let spoofCalled = false;
    bDrag.callAction = async () => { spoofCalled = true; return true; };
    await bDrag.onFolderDrop({
        preventDefault: () => { },
        stopPropagation: () => { },
        ctrlKey: false,
        altKey: false,
        currentTarget: { dataset: { folderPath: 'target', volume: 'local' }, classList: { remove: () => { } } },
        dataTransfer: {
            files: [],
            types: ['application/x-liveux-fm'],
            getData: (t) => (t === 'application/x-liveux-fm' ? JSON.stringify({ path: 'secret.txt', paths: ['secret.txt'], token: 'evil-origin' }) : ''),
        },
    });
    assert.equal(spoofCalled, false, 'Mismatched drag instance token MUST NOT trigger move');

    // 2. Image Editor keeps Save Copy & Overwrite disabled until img.onload completes
    const bImg = new BrowserController();
    bImg.i18nValue = { 'modals.edit_image_title': 'Edit', 'ui.auto_placeholder': 'auto' };
    bImg.capabilitiesValue = { editImage: true, delete: true };
    bImg.hasModalBackdropTarget = true;
    bImg.modalBackdropTarget = createFakeEl('div');
    bImg.modalTitleTarget = createFakeEl('h6');
    bImg.modalBodyTarget = createFakeEl('div');
    const edCopyBtn = { ...createFakeEl('button'), dataset: { edSave: 'copy' }, disabled: false, addEventListener: () => { } };
    const edOverBtn = { ...createFakeEl('button'), dataset: { edSave: 'overwrite' }, disabled: false, addEventListener: () => { } };
    bImg.modalBodyTarget.querySelector = (sel) => {
        if (sel === '[data-ed-save="copy"]') return edCopyBtn;
        if (sel === '[data-ed-save="overwrite"]') return edOverBtn;
        return null;
    };
    bImg.modalBodyTarget.querySelectorAll = (sel) => (sel === '[data-ed-save]' ? [edOverBtn, edCopyBtn] : []);
    const prevDoc2 = globalThis.document;
    const prevImg2 = globalThis.Image;
    let deferredImg = null;
    try {
        globalThis.document = {
            createElement: (tag) => (tag === 'canvas'
                ? { ...createFakeEl('canvas'), width: 300, height: 150, getContext: () => ({ clearRect() { }, save() { }, translate() { }, rotate() { }, scale() { }, drawImage() { }, restore() { }, fillRect() { } }) }
                : createFakeEl(tag)),
        };
        globalThis.Image = class DeferredImg {
            set src(v) { this._src = v; deferredImg = this; }
        };
        bImg.openImageEditorModal('photo.jpg', 'photo.jpg', '/uploads/photo.jpg');
        assert.equal(edCopyBtn.disabled, true, 'Save Copy must stay disabled before img.onload');
        assert.equal(edOverBtn.disabled, true, 'Overwrite must stay disabled before img.onload');
        deferredImg.naturalWidth = 640;
        deferredImg.naturalHeight = 480;
        deferredImg.onload();
        assert.equal(edCopyBtn.disabled, false, 'Save Copy enables once img.onload finishes');
        assert.equal(edOverBtn.disabled, false, 'Overwrite enables once img.onload finishes');
    } finally {
        globalThis.document = prevDoc2;
        globalThis.Image = prevImg2;
    }

    // 3. Upload Pause -> Resume does not launch duplicate worker while old worker is in flight
    const bUp = new BrowserController();
    bUp.activeUploads = 0;
    bUp.uploadUrlValue = '/_liveux-file-manager/upload';
    bUp.parallelUploadsValue = 3;
    bUp.maxRetriesValue = 0;
    bUp.csrfHeaders = () => ({});
    bUp.renderUploadQueue = () => { };
    bUp.showStatus = () => { };
    bUp.invalidateClientCaches = () => { };
    bUp.refreshFullTree = async () => { };
    bUp.fetchDirectory = async () => true;
    const job = {
        id: 'u1',
        file: { name: 'big.bin', size: 100, slice: () => new Blob(['x']) },
        chunkSize: 100,
        chunkIndex: 0,
        totalChunks: 2,
        targetDir: '',
        volume: 'local',
        status: 'queued',
        paused: false,
        cancelled: false,
        retryCount: 0,
        retrying: false,
        error: null,
        activeWorkers: 0,
        runToken: 0,
    };
    bUp.uploadJobs = [job];
    let resolveFetch1 = null;
    let fetchCalls = 0;
    const prevFetch2 = globalThis.fetch;
    try {
        globalThis.fetch = () => {
            fetchCalls += 1;
            if (fetchCalls === 1) {
                return new Promise((r) => { resolveFetch1 = r; });
            }
            return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true }) });
        };
        bUp.pumpUploadQueue();
        assert.equal(fetchCalls, 1);
        assert.equal(job.activeWorkers, 1);
        bUp.pauseUploadJob('u1');
        bUp.resumeUploadJob('u1');
        assert.equal(fetchCalls, 1, 'Resuming while old worker still unwinding must wait for old worker finally before spawning next worker');
        resolveFetch1({ ok: true, status: 200, json: async () => ({ ok: true }) });
        await new Promise((r) => setTimeout(r, 20));
        assert.equal(fetchCalls, 2, 'After old worker unwinds, resumed job proceeds cleanly with single worker');
        assert.equal(job.activeWorkers, 0);
    } finally {
        globalThis.fetch = prevFetch2;
    }

    // 4. Multilingual numerals (CJK fullwidth, Bengali, Thai, grouped numbers) & AltGr guard
    const bNum = new BrowserController();
    assert.equal(bNum.parseLocalizedInt('１２３'), 123);
    assert.equal(bNum.parseLocalizedInt('১,২৩৪'), 1234);
    assert.equal(bNum.parseLocalizedInt('๔๕'), 45);
    assert.equal(bNum.parseLocalizedInt('1,250'), 1250);
    assert.equal(bNum.parseLocalizedInt('1.250'), 1250);
    assert.equal(bNum.formatNumber(1234, false), '1234');

    const prevDoc3 = globalThis.document;
    globalThis.document = { activeElement: null };
    try {
        let altGrHitShortcut = false;
        bNum.trashMode = false;
        bNum.isModalOpen = () => false;
        bNum.isFileManagerActive = () => true;
        bNum.hasSearchInputTarget = true;
        bNum.searchInputTarget = { focus: () => { altGrHitShortcut = true; } };
        bNum.capabilitiesValue = { showSearch: true };
        bNum.keyboardShortcutsValue = true;
        bNum.onKeydown({
            ctrlKey: true,
            altKey: true,
            metaKey: false,
            key: 'f',
            code: 'KeyF',
            getModifierState: (m) => m === 'AltGraph',
            preventDefault: () => { altGrHitShortcut = true; },
        });
        assert.equal(altGrHitShortcut, false, 'AltGr chord must never trigger keyboard shortcuts');
    } finally {
        globalThis.document = prevDoc3;
    }

    // 5. Picker allows non-leading Emoji/CJK variation selector stems while still blocking extension spoofing
    const p = new PickerController();
    assert.equal(p.isSafeRelativePath('photos/❤️.png'), true);
    assert.equal(p.isSafeRelativePath('photos/葛\u{E0100}.pdf'), true);
    assert.equal(p.isSafeRelativePath('photos/\u{FE0F}bad.png'), false);
    assert.equal(p.isSafeRelativePath('photos/bad.p\u{FE0F}ng'), false);
    assert.equal(p.isSafeRelativePath('photos/bad.\u{FE0F}'), false);

    // 6. Compact folder tile preserves padding-inline-end & editors block .trash/dot URLs and CKEditor5 links when insertImage missing
    const pkgRoot = path.resolve(assetsDir, '..');
    const coreCss13 = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');
    assert.doesNotMatch(coreCss13, /data-size-tier="compact"\]\s*\.liveux-fm-folder-tile\s*\{[^}]*padding-inline:\s*8px\s+26px/);
    assert.match(coreCss13, /data-size-tier="compact"\]\s*\.liveux-fm-folder-tile\s*\{[^}]*padding-inline-start:\s*8px/);

    for (const adapterFile of ['ckeditor5_adapter.js', 'tinymce_adapter.js', 'tiptap_adapter.js']) {
        const src = fs.readFileSync(path.join(pkgRoot, 'assets/js/integrations', adapterFile), 'utf8');
        assert.match(src, /hasDangerousPathSegments/, `${adapterFile} must reject dot/traversal URL path segments`);
    }

    const prevWin = globalThis.window;
    let ckLinkCreated = false;
    try {
        globalThis.window = {
            LiveUXFileManager: {
                open: async () => [
                    { url: 'https://cdn.example.com/.trash/leak.png', name: 'leak.png', isImage: true },
                    { url: 'https://cdn.example.com/media/banner.png', name: 'banner.png', isImage: true },
                ],
            },
        };
        const fakeEditor = {
            commands: { get: () => null },
            model: {
                document: { selection: {} },
                change: (fn) => fn({ createText: (t, attrs) => ({ t, attrs }) }),
                insertContent: (node) => { if (node?.attrs?.linkHref === 'https://cdn.example.com/media/banner.png') ckLinkCreated = true; },
            },
            editing: { view: { focus: () => { } } },
        };
        const insertedCk = await ckMod.openFileManagerForCKEditor5(fakeEditor, { multiple: true });
        assert.equal(insertedCk.length, 1, 'CKEditor5 must drop .trash URL and insert safe image');
        assert.equal(ckLinkCreated, true, 'CKEditor5 must fall back to inserting image link when insertImage command is absent');
    } finally {
        globalThis.window = prevWin;
    }

    const skillRef = fs.readFileSync(path.join(pkgRoot, 'skills/liveux-file-manager-forms-and-editors/references/picker-and-js-api.md'), 'utf8');
    assert.match(skillRef, /`color_scheme`/);
    assert.ok(tinyMod && tiptapMod);
});

test('18. Pass-14 hardening: lazy folder tree, Pause->Resume runToken abort guard, WYSIWYG query/double-decode/filler bypasses, Picker live:connect/disabled/skeleton parity, SVG data-* strip, DevTools chord, Dialog defaultPrevented, CSS & Twig RTL/a11y', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const apiMod = await import(pathToFileURL(path.join(assetsDir, 'js/file_manager_api.js')).href);
    const pkgRoot = path.resolve(assetsDir, '..');

    // 1. Lazy folder tree when show_folder_tree=false: toggleSidebar() forces /api/tree fetch when unseeded,
    //    but does NOT re-fetch on subsequent toggles for a flat root volume with 0 cached subfolders.
    const bLazy = new BrowserController();
    bLazy.volumeValue = 'local';
    bLazy.showTreeValue = false;
    bLazy.hasSidebarTarget = true;
    bLazy.sidebarTarget = createFakeEl('aside');
    bLazy.treeCache = new Map();
    let forcedTreeCalls = 0;
    bLazy.refreshFullTree = async (force) => {
        if (force) {
            forcedTreeCalls += 1;
            bLazy.treeCache.set('local', []);
        }
    };
    bLazy.toggleSidebar();
    assert.equal(forcedTreeCalls, 1, 'toggleSidebar() must force refreshFullTree(true) when treeCache is unseeded');
    bLazy.toggleSidebar(); // close
    bLazy.toggleSidebar(); // reopen with empty [] cache
    assert.equal(forcedTreeCalls, 1, 'toggleSidebar() must NOT re-fetch when empty [] tree is already cached');

    // 2. Upload Pause -> Resume: old worker AbortError must NOT kill resumed upload or clobber Worker 2 abortController
    const bRace = new BrowserController();
    bRace.isDisconnected = false;
    bRace.activeUploads = 0;
    bRace.uploadUrlValue = '/_liveux-file-manager/upload';
    bRace.parallelUploadsValue = 2;
    bRace.maxRetriesValue = 0;
    bRace.csrfHeaders = () => ({});
    bRace.renderUploadQueue = () => { };
    bRace.showStatus = () => { };
    bRace.invalidateClientCaches = () => { };
    bRace.refreshFullTree = async () => { };
    bRace.fetchDirectory = async () => true;
    const job = {
        id: 'u14',
        file: { name: 'vid.mp4', size: 100, slice: () => new Blob(['x']) },
        chunkSize: 100,
        chunkIndex: 0,
        totalChunks: 2,
        targetDir: '',
        volume: 'local',
        status: 'queued',
        paused: false,
        cancelled: false,
        retryCount: 0,
        retrying: false,
        error: null,
        activeWorkers: 0,
        runToken: 0,
    };
    bRace.uploadJobs = [job];
    let rejectFirst = null;
    let resolveSecond = null;
    let calls = 0;
    const prevFetch = globalThis.fetch;
    try {
        globalThis.fetch = () => {
            calls += 1;
            if (calls === 1) {
                return new Promise((_res, rej) => { rejectFirst = rej; });
            }
            if (calls === 2) {
                return new Promise((res) => { resolveSecond = res; });
            }
            return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true }) });
        };
        bRace.pumpUploadQueue();
        bRace.pauseUploadJob('u14');
        // Resume immediately while Worker 1 is still rejecting
        bRace.resumeUploadJob('u14');
        // Simulate Worker 1 AbortError settling AFTER job.paused became false and runToken bumped
        rejectFirst(Object.assign(new Error('Aborted'), { name: 'AbortError' }));
        await new Promise((r) => setTimeout(r, 20));
        assert.notEqual(job.status, 'error', 'Stale worker AbortError after Resume must never mark job as error');
        if (resolveSecond) {
            resolveSecond({ ok: true, status: 200, json: async () => ({ ok: true }) });
        }
        await new Promise((r) => setTimeout(r, 20));
    } finally {
        globalThis.fetch = prevFetch;
    }

    // 3. WYSIWYG adapter rejects ?path= traversal, double-encoded %252e%252e, invisible filler \u3164.trash, and raw .. before new URL() collapse
    const prevWin = globalThis.window;
    try {
        globalThis.window = {
            LiveUXFileManager: {
                open: async () => [
                    { url: '/_liveux-file-manager/download?profile=default&path=../.env', name: 'a.png', isImage: true },
                    { url: '/uploads/%252e%252e/secret.png', name: 'b.png', isImage: true },
                    { url: '/uploads/%E3%85%A4.trash/leak.png', name: 'c.png', isImage: true },
                    { url: 'https://cdn.example.com/a/../secret.png', name: 'd.png', isImage: true },
                    { url: '/_liveux-file-manager/download?path=../../.env&path=ok.png', name: 'e.png', isImage: true },
                    { url: 'https://cdn.example.com/media/clean.webp', name: 'clean.webp', isImage: true },
                ],
            },
        };
        const fakeEditor = {
            commands: { get: () => true },
            execute: () => { },
            model: { change: () => { } },
        };
        const kept = await ckMod.openFileManagerForCKEditor5(fakeEditor, { multiple: true });
        assert.deepEqual(kept.map((f) => f.url), ['https://cdn.example.com/media/clean.webp']);
    } finally {
        globalThis.window = prevWin;
    }

    // 4. PickerController: skeleton reserved name, trailing space, VS1..VS16, dangerous URL query/path segments & bubbling live:connect guard
    const p = new PickerController();
    assert.equal(p.isSafeRelativePath('con\uFE0F.png'), false, 'Skeleton Windows reserved name con+VS16 must be rejected');
    assert.equal(p.isSafeRelativePath('dir \uFE0F/a.png'), false, 'Trailing whitespace before VS16 must be rejected');
    assert.equal(p.isSafeRelativePath('sub /file.png'), false, 'Untrimmed segment whitespace must be rejected');
    assert.equal(p.isSafeRelativePath('photos/❤️.png'), true);
    assert.equal(p.isSafeUrl('/_liveux-file-manager/download?path=../.env'), false);
    assert.equal(p.isSafeUrl('/uploads/.trash/a.png'), false);
    assert.equal(p.isSafeUrl('/uploads/gallery/hero.webp'), true);

    // Bubbling live:connect from nested child component must NOT replace parent liveComponent
    const parentComp = { on() { }, off() { } };
    const childComp = { on() { }, off() { } };
    p.element = { closest: () => null, classList: { toggle() { } } };
    p.hasInputTarget = false;
    p.connect();
    const fakeLiveRoot = {};
    p.liveRoot = fakeLiveRoot;
    p.liveComponent = parentComp;
    p.boundLiveConnect({ target: {}, detail: { component: childComp } });
    assert.equal(p.liveComponent, parentComp, 'Nested child live:connect event must NOT hijack parent liveComponent');

    // 5. BrowserController: recursiveItems cleared on invalidateClientCaches, Ctrl+Shift+C DevTools not hijacked, sanitizeInlineSvg strips data-action & ev:onclick
    const bSec = new BrowserController();
    bSec.dirCache = new Map();
    bSec.treeCache = new Map();
    bSec.recursiveItems = [{ path: 'stale.png' }];
    bSec.invalidateClientCaches();
    assert.equal(bSec.recursiveItems, null, 'invalidateClientCaches must clear recursiveItems');

    let copyTriggered = false;
    bSec.isFileManagerActive = () => true;
    bSec.keyboardShortcutsValue = true;
    bSec.capabilitiesValue = { copy: true };
    bSec.selectedMap = new Map([['a.png', { path: 'a.png' }]]);
    bSec.isModalOpen = () => false;
    bSec.isLightboxOpen = () => false;
    bSec.isContextMenuOpen = () => false;
    bSec.isSettingsPopoverOpen = () => false;
    bSec.copyToClipboard = () => { copyTriggered = true; };
    const prevDoc = globalThis.document;
    try {
        globalThis.document = { activeElement: null };
        bSec.onKeydown({ ctrlKey: true, shiftKey: true, metaKey: false, altKey: false, key: 'C', code: 'KeyC', preventDefault() { } });
        assert.equal(copyTriggered, false, 'Ctrl+Shift+C (DevTools Inspect) must not be hijacked');
    } finally {
        globalThis.document = prevDoc;
    }

    // 6. DialogController ignores already-defaultPrevented events & openFileManager(null) does not throw
    const d = new DialogController();
    let handled = false;
    d.dialogTarget = { removeAttribute() { handled = true; } };
    await d.handleGlobalOpen({ defaultPrevented: true, preventDefault() { }, detail: {} });
    assert.equal(handled, false, 'Second DialogHost must skip already defaultPrevented open event');

    const prevWin2 = globalThis.window;
    const prevCE = globalThis.CustomEvent;
    try {
        globalThis.CustomEvent = class FakeCE { constructor(t, init) { this.type = t; this.detail = init?.detail; } };
        globalThis.window = { dispatchEvent: () => true };
        const resNull = await apiMod.openFileManager(null);
        assert.deepEqual(resNull, []);
    } finally {
        globalThis.window = prevWin2;
        globalThis.CustomEvent = prevCE;
    }

    // 7. Static CSS & Twig checks: 100dvh, touch checkboxes, [data-job-status] BiDi, RTL Lightbox arrows & removed dead groupedVolumes
    const coreCss14 = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');
    assert.match(coreCss14, /height:\s*100dvh;/);
    assert.match(coreCss14, /\.liveux-fm-card-check:focus-visible/);
    assert.match(coreCss14, /\[data-job-status\]/);

    const overlaysTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_overlays.html.twig'), 'utf8');
    assert.match(overlaysTwig, /data-lb-nav="prev"[^>]*>‹<\/button>/);
    assert.match(overlaysTwig, /data-lb-nav="next"[^>]*>›<\/button>/);
    assert.match(coreCss14, /\[data-lb-nav\]/);
    const layoutTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/layout.html.twig'), 'utf8');
    assert.equal(layoutTwig.includes('this.groupedVolumes'), false, 'layout.html.twig must not perform unused this.groupedVolumes call');
});

test('19. Pass-15 hardening: leading-space HPP & backslash URL guards, CSS newline/element() & ev:onclick, upload final-chunk & paused-at-100%, lazy tree gating, 2x2 ImageEditor flip, Picker dynamic disabled & POSIX formatCount, Dialog focus restore, shortcuts & a11y/i18n parity', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);
    const pkgRoot = path.resolve(assetsDir, '..');

    // 1. Leading-space query HPP, backslash authority/path bypass & encoded controls blocked across Picker, Browser & ALL 3 WYSIWYG adapters
    const hostileUrls = [
        '/_liveux-file-manager/download?%20path=../../etc/passwd',
        '/_liveux-file-manager/download?+path=..%2Fsecret.png',
        '/_liveux-file-manager/download?path=ok.png&+path=../../etc/passwd',
        '/_liveux-file-manager/download?+path[]=ok.png',
        'https://cdn.example.com\\..\\.trash\\secret.png',
        '/uploads/file%00.png',
        '/uploads/file%0d%0a.png',
    ];
    const pSec = new PickerController();
    const bSec = new BrowserController();
    for (const bad of hostileUrls) {
        assert.equal(pSec.isSafeUrl(bad), false, `Picker must reject ${bad}`);
        assert.equal(bSec.isSafeHttpOrPathUrl(bad), false, `Browser must reject ${bad}`);
    }
    // Encoded %70ath SVG download rewrite
    pSec.returnTypeValue = 'url';
    assert.equal(
        pSec.previewSrc('/_liveux-file-manager/download?profile=default&%70ath=logo.svg'),
        '/_liveux-file-manager/thumb?profile=default&%70ath=logo.svg',
    );
    // POSIX locale suffix in Picker formatCount
    pSec.localeValue = 'ar_SA.UTF-8';
    assert.equal(typeof pSec.formatCount(1234), 'string');
    assert.ok(pSec.formatCount(1234).length > 0);

    // WYSIWYG adapters reject hostile URLs, respect destroyed editor & handle Tiptap without link mark
    const prevWin = globalThis.window;
    try {
        for (const bad of hostileUrls) {
            globalThis.window = {
                LiveUXFileManager: { open: async () => [{ url: bad, name: 'x.png', isImage: true }] },
            };
            let tinyCalled = false;
            await tinyMod.createTinyMceFilePicker()(() => { tinyCalled = true; }, '', { filetype: 'image' });
            assert.equal(tinyCalled, false, `TinyMCE must reject ${bad}`);
            const ckRes = await ckMod.openFileManagerForCKEditor5({ model: { change() { } }, commands: { get: () => true }, execute() { } });
            assert.deepEqual(ckRes, [], `CKEditor5 must reject ${bad}`);
            const tipRes = await tipMod.insertFromFileManager({ chain: () => ({ focus() { return this; }, setImage() { return this; }, insertContent() { return this; }, run() { } }) });
            assert.deepEqual(tipRes, [], `Tiptap must reject ${bad}`);
        }
        // Destroyed editors after await must no-op cleanly
        globalThis.window = { LiveUXFileManager: { open: async () => [{ url: '/uploads/a.png', name: 'a.png', isImage: true }] } };
        let ckExec = false;
        assert.deepEqual(await ckMod.openFileManagerForCKEditor5({ state: 'destroyed', model: { change() { } }, commands: { get: () => true }, execute() { ckExec = true; } }), []);
        assert.equal(ckExec, false);
        let tipRun = false;
        assert.deepEqual(await tipMod.insertFromFileManager({ isDestroyed: true, chain: () => ({ focus() { return this; }, run() { tipRun = true; } }) }), []);
        assert.equal(tipRun, false);
        // Tiptap without link mark falls back to plain text node without throwing
        globalThis.window = { LiveUXFileManager: { open: async () => [{ url: '/uploads/doc.pdf', name: 'doc.pdf', isImage: false }] } };
        const tipNodes = [];
        await tipMod.insertFromFileManager({
            schema: { marks: {} },
            chain: () => ({
                focus() { return this; },
                insertContent(node) { tipNodes.push(node); return this; },
                run() { },
            }),
        });
        assert.equal(tipNodes[0]?.type, 'text');
        assert.equal(tipNodes[0]?.marks, undefined, 'Tiptap must omit link mark when schema lacks link extension');
    } finally {
        globalThis.window = prevWin;
    }

    // 2. CSS W3C newline-terminating string + element() + Dialog focus restore & namespaced ev:onclick scrub
    const dSec = new DialogController();
    assert.equal(dSec.hasUnsafeCssValue("background: \"x\n\" url(https://evil.example/a)"), true);
    assert.equal(dSec.hasUnsafeCssValue('background: -moz-element(#secret)'), true);
    assert.equal(bSec.hasUnsafeSvgCss("fill: \"x\n\" url(https://evil.example/a)"), true);
    assert.equal(bSec.hasUnsafeSvgCss('filter: element(#x)'), true);

    let triggerFocused = false;
    const prevBtn = { isConnected: true, focus() { triggerFocused = true; } };
    dSec.previousActiveElement = prevBtn;
    dSec.onDialogClose();
    assert.equal(triggerFocused, true, 'DialogController.onDialogClose() must restore focus to previousActiveElement');

    // 3. Upload final-chunk completed:false data-loss guard & Paused-at-100% completion + chunk 0 cancel cleanup
    const bUp = new BrowserController();
    bUp.isDisconnected = false;
    bUp.activeUploads = 0;
    bUp.uploadUrlValue = '/_liveux-file-manager/upload';
    bUp.actionUrlValue = '/_liveux-file-manager/action';
    bUp.maxRetriesValue = 0;
    bUp.csrfHeaders = () => ({});
    bUp.renderUploadQueue = () => { };
    bUp.showStatus = () => { };
    bUp.pumpUploadQueue = () => { };
    bUp.maybeFlushPostUploadRefresh = async () => { };
    bUp.t = (k) => k;

    const prevFetch = globalThis.fetch;
    try {
        // Case A: Final chunk returns ok:true, completed:false -> MUST NOT silently discard; resets chunkIndex=0 & marks error
        globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ ok: true, completed: false }) });
        const lostJob = { id: 'u15a', file: { name: 'a.bin', size: 100, slice: () => new Blob(['x']) }, chunkSize: 50, chunkIndex: 1, totalChunks: 2, targetDir: '', volume: 'local', status: 'uploading', paused: false, cancelled: false, retryCount: 0, error: null, runToken: 0, activeWorkers: 0 };
        bUp.uploadJobs = [lostJob];
        await bUp.processUploadJob(lostJob);
        assert.equal(lostJob.status, 'error', 'Final chunk with completed:false must fail instead of silently discarding');
        assert.equal(lostJob.chunkIndex, 0, 'Final chunk with completed:false must reset chunkIndex to 0 for clean retry');
        assert.equal(bUp.uploadJobs.length, 1);

        // Case B: Paused mid-flight on final chunk -> server already committed, so job MUST complete and schedule refresh
        const pausedFinalJob = { id: 'u15b', file: { name: 'b.bin', size: 100, slice: () => new Blob(['x']) }, chunkSize: 100, chunkIndex: 0, totalChunks: 1, targetDir: '', volume: 'local', status: 'uploading', paused: false, cancelled: false, retryCount: 0, error: null, runToken: 0, activeWorkers: 0 };
        globalThis.fetch = async () => {
            pausedFinalJob.paused = true;
            return { ok: true, status: 200, json: async () => ({ ok: true, completed: true }) };
        };
        bUp.uploadJobs = [pausedFinalJob];
        bUp.needsPostUploadRefresh = false;
        await bUp.processUploadJob(pausedFinalJob);
        assert.equal(bUp.uploadJobs.length, 0, 'Final chunk committed server-side must complete even if paused mid-flight');
        assert.equal(bUp.needsPostUploadRefresh, true);

        // Case C: Cancelling chunked job while uploading chunk 0 still sends abortUpload cleanup
        let abortedId = null;
        bUp.callAction = async (act, payload) => { if (act === 'abortUpload') abortedId = payload.uploadId; };
        const chunk0Job = { id: 'u15c', file: { name: 'c.bin', size: 500 }, totalChunks: 5, chunkIndex: 0, status: 'uploading', preFlightError: false, volume: 'local', targetDir: '' };
        bUp.uploadJobs = [chunk0Job];
        bUp.cancelUploadJob('u15c');
        assert.equal(abortedId, 'u15c', 'Cancelling active chunk-0 multi-chunk upload must trigger server abortUpload');
    } finally {
        globalThis.fetch = prevFetch;
    }

    // 4. Lazy tree gating when show_folder_tree=false: refreshFullTree() skips fetch unless allowHidden=true
    const bGate = new BrowserController();
    bGate.volumeValue = 'local';
    bGate.showTreeValue = false;
    bGate.hasSidebarTarget = true;
    bGate.treeUrlValue = '/_liveux-file-manager/tree';
    bGate.treeCache = new Map([['local', [{ path: 'old' }]]]);
    let treeFetched = 0;
    const prevFetch2 = globalThis.fetch;
    try {
        globalThis.fetch = async () => { treeFetched += 1; return { ok: true, json: async () => ({ ok: true, folders: [] }) }; };
        await bGate.refreshFullTree(true, false, false);
        assert.equal(treeFetched, 0, 'Hidden sidebar refreshFullTree must not fetch /tree');
        assert.equal(bGate.treeCache.has('local'), false, 'Hidden forced refresh must evict stale treeCache entry');
        bGate.renderSidebarTree = () => { };
        await bGate.refreshFullTree(false, false, true);
        assert.equal(treeFetched, 1, 'openMoveModal allowHidden=true must fetch /tree on demand');
    } finally {
        globalThis.fetch = prevFetch2;
    }

    // 5. Picker dynamic disabled (<fieldset disabled> via :disabled + post-await disabled guard)
    const pDis = new PickerController();
    pDis.hasInputTarget = true;
    pDis.inputTarget = { ...createFakeEl('input'), disabled: false, matches: (sel) => sel === ':disabled' };
    assert.equal(pDis.isDisabled(), true, 'Picker must detect inherited <fieldset disabled> via :disabled');

    // 6. BrowserController shortcuts: AZERTY/Arabic zoom & '?' (U+061F)
    const bKey = new BrowserController();
    bKey.keyboardShortcutsValue = true;
    bKey.isFileManagerActive = () => true;
    bKey.isModalOpen = () => false;
    bKey.isContextMenuOpen = () => false;
    bKey.isSettingsPopoverOpen = () => false;
    let zoomInCount = 0;
    let zoomResetCount = 0;
    let shortcutsOpened = 0;
    bKey.lightboxZoomIn = () => { zoomInCount += 1; };
    bKey.lightboxZoomReset = () => { zoomResetCount += 1; };
    bKey.openShortcutsModal = () => { shortcutsOpened += 1; };
    const prevDoc = globalThis.document;
    try {
        globalThis.document = { activeElement: null };
        bKey.isLightboxOpen = () => true;
        bKey.onKeydown({ key: 'à', code: 'Digit0', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, preventDefault() { } });
        bKey.onKeydown({ key: '٪', code: 'Equal', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, preventDefault() { } });
        assert.equal(zoomResetCount, 1, 'Digit0 code must reset Lightbox zoom on AZERTY/non-Latin keyboards');
        assert.equal(zoomInCount, 1, 'Equal code must zoom in Lightbox on non-Latin keyboards');
        bKey.isLightboxOpen = () => false;
        bKey.onKeydown({ key: '؟', code: 'Slash', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, preventDefault() { } });
        assert.equal(shortcutsOpened, 1, 'Arabic question mark U+061F must open shortcuts modal');
    } finally {
        globalThis.document = prevDoc;
    }

    // 7. Static Twig, CSS & 3-language YAML translation key parity assertions
    const topbarTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_topbar.html.twig'), 'utf8');
    const sidebarTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_sidebar.html.twig'), 'utf8');
    const pickerTwig = fs.readFileSync(path.join(pkgRoot, 'templates/form/picker.html.twig'), 'utf8');
    const coreCss15 = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');
    assert.match(topbarTwig, /aria-haspopup="true"/);
    assert.match(topbarTwig, /ui\.select_volume/);
    assert.match(topbarTwig, /this\.isRtl \? 'Alt\+→' : 'Alt\+←'/);
    assert.match(sidebarTwig, /class="liveux-fm-tree"\s+role="tree"/);
    assert.match(pickerTwig, /<div class="liveux-fm-picker-dropzone" data-\{\{ ctrl \}\}-target="dropzone"/);
    assert.match(coreCss15, /\.liveux-fm-sort-group:focus-within/);
    assert.match(coreCss15, /\.liveux-fm-tree-row:focus-visible/);
    for (const lang of ['en', 'tr', 'fr']) {
        const yaml = fs.readFileSync(path.join(pkgRoot, `translations/LiveUXFileManagerBundle.${lang}.yaml`), 'utf8');
        for (const key of ['key_delete:', 'key_space:', 'key_backspace:', 'select_volume:']) {
            assert.equal(yaml.includes(key), true, `${lang} translation must define ${key}`);
        }
    }
});

test('20. Mobile-first modal & offcanvas UX: mobile_dialog_mode API/Dialog/Picker wiring, auto-collapsed mobile sidebar drawer, backdrop & auto-close, clean 2-row topbar & zero-overflow footer', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const apiMod = await import(pathToFileURL(path.join(assetsDir, 'js/file_manager_api.js')).href);
    const pkgRoot = path.resolve(assetsDir, '..');

    // 1. openFileManager() forwards mobileMode option
    const prevWin = globalThis.window;
    const prevCE = globalThis.CustomEvent;
    let capturedDetail = null;
    try {
        globalThis.CustomEvent = class FakeCE { constructor(t, init) { this.type = t; this.detail = init?.detail; } };
        globalThis.window = {
            dispatchEvent(ev) {
                capturedDetail = ev.detail;
                ev.detail.resolve([]);
                return false;
            },
        };
        await apiMod.openFileManager({ mode: 'modal', mobileMode: 'sheet' });
        assert.equal(capturedDetail?.mobileMode, 'sheet');
    } finally {
        globalThis.window = prevWin;
        globalThis.CustomEvent = prevCE;
    }

    // 2. DialogController validates mobileMode allowlist & inherits data-mobile-dialog-mode from profile when unset
    const dlg = new DialogController();
    dlg.dialogTarget = createFakeEl('dialog');
    dlg.bodyTarget = createFakeEl('div');
    dlg.frameUrlValue = '/_liveux-file-manager/dialog';
    dlg.loadedKey = 'default|||modal|0||||all';
    const fakeInner = createFakeEl('div');
    fakeInner.setAttribute('data-mobile-dialog-mode', 'sheet');
    dlg.bodyTarget.querySelector = () => fakeInner;
    await dlg.handleGlobalOpen({ detail: { profile: 'default', mode: 'modal', mobileMode: 'bogus_mode' } });
    assert.equal(dlg.dialogTarget.getAttribute('data-mobile-mode'), 'sheet', 'Unrecognized mobileMode must fall back to profile data-mobile-dialog-mode');
    await dlg.handleGlobalOpen({ detail: { profile: 'default', mode: 'modal', mobileMode: 'fullscreen' } });
    assert.equal(dlg.dialogTarget.getAttribute('data-mobile-mode'), 'fullscreen', 'Explicit mobileMode=fullscreen must override profile default');

    // 3. PickerController forwards mobileModeValue to openFn
    const prevWinPicker = globalThis.window;
    let pickerOpenOpts = null;
    try {
        globalThis.window = {
            LiveUXFileManager: {
                open: async (opts) => { pickerOpenOpts = opts; return []; },
            },
        };
        const p = new PickerController();
        p.profileValue = 'default';
        p.modeValue = 'offcanvas';
        p.positionValue = 'end';
        p.mobileModeValue = 'sheet';
        p.hasInputTarget = true;
        p.inputTarget = createFakeEl('input');
        await p.open();
        assert.equal(pickerOpenOpts?.mobileMode, 'sheet');
    } finally {
        globalThis.window = prevWinPicker;
    }

    // 4. BrowserController mobile viewport: sidebar auto-collapses on mobile, closes on navigate/toggleTrash/Escape, and empty footer pager hides cleanly
    const prevWinMob = globalThis.window;
    try {
        globalThis.window = { matchMedia: (q) => ({ matches: q.includes('767.98px') }) };
        const b = new BrowserController();
        b.hasSidebarTarget = true;
        b.sidebarTarget = createFakeEl('aside');
        b.hasShellTarget = true;
        b.shellTarget = createFakeEl('div');
        b.showTreeValue = true;
        assert.equal(b.isMobileViewport(), true);
        assert.equal(b.isMobileSidebarOpen(), true);

        // navigate() auto-closes mobile sidebar drawer
        let navCalled = false;
        b.navigateTo = async () => { navCalled = true; };
        await b.navigate({ currentTarget: { dataset: { path: 'photos', volume: 'local' } } });
        assert.equal(navCalled, true);
        assert.equal(b.showTreeValue, false);
        assert.equal(b.sidebarTarget.classList.contains('liveux-fm-hidden'), true);
        assert.equal(b.shellTarget.dataset.sidebarOpen, 'false');

        // Escape closes open mobile sidebar before closing outer dialog
        b.showTreeValue = true;
        b.sidebarTarget.classList.remove('liveux-fm-hidden');
        b.shellTarget.dataset.sidebarOpen = 'true';
        let cancelPrevented = false;
        b.isContextMenuOpen = () => false;
        b.isLightboxOpen = () => false;
        b.isModalOpen = () => false;
        b.isSettingsPopoverOpen = () => false;
        b.hideContextMenu = () => {};
        b.closeLightbox = () => {};
        b.closeModal = () => {};
        b.onDialogCancel({ preventDefault() { cancelPrevented = true; } });
        assert.equal(cancelPrevented, true, 'onDialogCancel must close open mobile sidebar instead of dismissing outer dialog');
        assert.equal(b.showTreeValue, false);

        // Single-page modal footer hides empty .liveux-fm-footer-pager even when showFooter=true
        b.capabilitiesValue = { showFooter: true, showPerPageSelector: true };
        b.perPageOptionsValue = [24, 48];
        b.perPageValue = 24;
        b.hasPaginationBarTarget = true;
        b.paginationBarTarget = createFakeEl('div');
        b.paginationBarTarget.classList.add('liveux-fm-footer-pager');
        b.hasPagerGroupTarget = true;
        b.pagerGroupTarget = createFakeEl('div');
        b.updatePagination(5, 1, 1);
        assert.equal(b.paginationBarTarget.classList.contains('liveux-fm-hidden'), true, 'Empty mobile footer pager must hide on single-page dialogs');
    } finally {
        globalThis.window = prevWinMob;
    }

    // 5. Static Twig & CSS mobile architecture checks
    const dialogHostTwig = fs.readFileSync(path.join(pkgRoot, 'templates/components/DialogHost.html.twig'), 'utf8');
    const browserTwig = fs.readFileSync(path.join(pkgRoot, 'templates/components/Browser.html.twig'), 'utf8');
    const layoutTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/layout.html.twig'), 'utf8');
    const topbarTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_topbar.html.twig'), 'utf8');
    const footerTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_footer.html.twig'), 'utf8');
    const coreCss = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');

    assert.match(dialogHostTwig, /data-mobile-mode="auto"/);
    assert.match(browserTwig, /data-mobile-dialog-mode="\{\{\s*this\.resolvedMobileDialogMode\s*\}\}"/);
    assert.match(layoutTwig, /liveux-fm-sidebar-backdrop/);
    assert.match(topbarTwig, /liveux-fm-topbar-end/);
    assert.match(topbarTwig, /liveux-fm-topbar-close/);
    assert.match(footerTwig, /liveux-fm-footer-actions/);
    assert.match(coreCss, /dialog\.liveux-fm-dialog\s+\.liveux-fm-body\s*\{\s*min-height:\s*0;/);
    assert.match(coreCss, /data-mobile-mode="sheet"/);
    assert.match(coreCss, /data-mobile-mode="fullscreen"/);
    assert.match(coreCss, /env\(safe-area-inset-bottom/);
    assert.match(coreCss, /\.liveux-fm-footer-actions\s*\{[\s\S]*grid-template-columns:\s*repeat\(2,/);
    assert.match(coreCss, /html:has\(dialog\.liveux-fm-dialog\[open\]\)\s*\{\s*overflow:\s*hidden;/);
    assert.match(coreCss, /\.liveux-fm-shell\s+\.liveux-fm-sidebar-fallback\s*\{\s*display:\s*none\s*!important;/);
    assert.match(coreCss, /@media\s*\(hover:\s*none\),\s*\(pointer:\s*coarse\)[\s\S]*\.liveux-fm-folder-tile:hover\s+\.liveux-fm-folder-count[\s\S]*opacity:\s*1;/);
});

test('21. Pass-16 hardening: mobile CSS specificity, dialog loading/error close & async close race, unstyled opaque surfaces, hidden inspector bandwidth guard, dialog clipboard, single-pass t() & picker inheritance', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const pkgRoot = path.resolve(assetsDir, '..');
    const coreCss = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');
    const dialogHostTwig = fs.readFileSync(path.join(pkgRoot, 'templates/components/DialogHost.html.twig'), 'utf8');
    const pickerTwig = fs.readFileSync(path.join(pkgRoot, 'templates/form/picker.html.twig'), 'utf8');

    // 1. Mobile CSS specificity (0,4,1), unstyled opaque elevated tokens, fallback close, modal footer wrap & ctx safe-area
    assert.match(coreCss, /dialog\.liveux-fm-dialog\[data-mode\]\[data-position\]\[data-mobile-mode="fullscreen"\]/);
    assert.match(coreCss, /dialog\.liveux-fm-dialog\[data-mode\]\[data-position\]\[data-mobile-mode="sheet"\]/);
    assert.match(coreCss, /--liveux-fm-elevated-bg:\s*#ffffff;/);
    assert.match(coreCss, /dialog\.liveux-fm-dialog\s+\[data-liveux-theme="unstyled"\]/);
    assert.match(coreCss, /\.liveux-fm-dialog-fallback-close\s*\{/);
    assert.match(coreCss, /\.liveux-fm-modal-footer\s*\{[\s\S]*flex-wrap:\s*wrap;/);
    assert.match(coreCss, /padding:\s*10px 12px calc\(18px \+ env\(safe-area-inset-bottom/);
    assert.match(dialogHostTwig, /data-liveux--file-manager-bundle--dialog-close-label-value=/);
    assert.match(pickerTwig, /mobile_mode\|default\(''\)/);

    // 2. DialogController: loading/error states render close button & onDialogClose ignores stale close when reopened
    const prevDoc = globalThis.document;
    const makeTreeEl = (tag) => {
        const el = createFakeEl(tag);
        el.children = [];
        el.appendChild = (child) => { el.children.push(child); return child; };
        el.replaceChildren = (...nodes) => { el.children = [...nodes]; };
        return el;
    };
    try {
        globalThis.document = {
            createElement: (tag) => makeTreeEl(tag),
            documentElement: { dir: 'ltr', lang: 'en' },
        };
        const dlg = new DialogController();
        dlg.dialogTarget = makeTreeEl('dialog');
        dlg.bodyTarget = makeTreeEl('div');
        dlg.closeLabelValue = 'Kapat';
        dlg.errorMessageValue = 'Load failed';

        dlg.renderLoading();
        const loadingCard = dlg.bodyTarget.children[0];
        assert.equal(loadingCard?.className, 'liveux-fm-dialog-loading');
        assert.equal(loadingCard?.children[0]?.className, 'liveux-fm-dialog-fallback-close');
        assert.equal(loadingCard?.children[0]?.getAttribute('aria-label'), 'Kapat');

        dlg.renderError('Network lost');
        const errCard = dlg.bodyTarget.children[0];
        assert.equal(errCard?.className, 'liveux-fm-dialog-error');
        assert.equal(errCard?.textContent, 'Network lost');
        assert.equal(errCard?.children[0]?.className, 'liveux-fm-dialog-fallback-close');

        // Stale async native 'close' event must NOT abort a reopened dialog
        let aborted = false;
        let resolvedPrematurely = false;
        dlg.dialogTarget.open = true;
        dlg.fetchController = { abort() { aborted = true; } };
        dlg.pendingResolve = () => { resolvedPrematurely = true; };
        dlg.onDialogClose({ type: 'close' });
        assert.equal(aborted, false, 'Stale close event while dialog.open=true must not abort new fetch');
        assert.equal(resolvedPrematurely, false, 'Stale close event while dialog.open=true must not resolve new promise');
    } finally {
        globalThis.document = prevDoc;
    }

    // 3. PickerController defaults mobileMode to '' so profile ui.mobile_dialog_mode is inherited
    const prevWinPicker = globalThis.window;
    try {
        let seenOpts = null;
        globalThis.window = {
            LiveUXFileManager: {
                open: async (opts) => { seenOpts = opts; return []; },
            },
        };
        const p = new PickerController();
        p.profileValue = 'default';
        p.modeValue = 'modal';
        p.positionValue = 'end';
        p.mobileModeValue = '';
        p.hasInputTarget = true;
        p.inputTarget = createFakeEl('input');
        await p.open();
        assert.equal(seenOpts?.mobileMode, '');
    } finally {
        globalThis.window = prevWinPicker;
    }

    // 4. BrowserController: single-pass t(), toggleSidebar re-render, hidden mobile inspector probe guard,
    //    closeSettingsPopover aria-expanded, dialog-scoped clipboard textarea, uploadTargetDir lifecycle & Trash wrap
    const prevWinB = globalThis.window;
    const prevDocB = globalThis.document;
    const prevImage = globalThis.Image;
    const prevNav = globalThis.navigator;
    try {
        let imageProbeCreated = 0;
        globalThis.Image = class {
            constructor() { imageProbeCreated++; }
        };
        globalThis.window = {
            matchMedia: (q) => ({ matches: q.includes('991.98px') || q.includes('767.98px') }),
        };

        const b = new BrowserController();
        b.i18nValue = {
            'move.msg': 'Move %name% to %target%',
            'footer.selected_single': '%name% (%size%)',
            'trash.title': 'Trash',
            'trash.back': 'Back',
            'trash.empty': 'Empty',
        };
        assert.equal(
            b.t('move.msg', { name: 'sale_%target%.jpg', target: 'archive' }),
            'Move sale_%target%.jpg to archive',
            'Single-pass t() must not expand placeholder tokens inside filenames',
        );

        // toggleSidebar re-renders cached tree on reopen
        let treeRendered = 0;
        b.hasSidebarTarget = true;
        b.sidebarTarget = createFakeEl('aside');
        b.hasShellTarget = true;
        b.shellTarget = createFakeEl('div');
        b.showTreeValue = false;
        b.volumeValue = 's3';
        b.treeCache = new Map([['s3', []]]);
        b.renderSidebarTree = () => { treeRendered++; };
        b.toggleSidebar();
        assert.equal(treeRendered, 1, 'toggleSidebar() must re-render sidebar tree even on cache hit');

        // Hidden mobile inspector skips Image probe while still updating footer status
        b.hasInspectorTarget = true;
        b.inspectorTarget = createFakeEl('aside');
        b.inspectorModeValue = 'on_select';
        b.hasFooterStatusTarget = true;
        b.footerStatusTarget = createFakeEl('span');
        b.capabilitiesValue = { showFooter: true };
        b.selectedMap = new Map([['hero.jpg', { path: 'hero.jpg' }]]);
        b.getActiveItemList = () => [{ path: 'hero.jpg', name: 'hero.jpg', kind: 'file', isImage: true, size: 1024, url: '/uploads/hero.jpg' }];
        b.renderInspector();
        assert.equal(b.isInspectorHidden(), true);
        assert.equal(imageProbeCreated, 0, 'Hidden mobile inspector must not download full-resolution images');
        assert.match(b.footerStatusTarget.textContent, /hero\.jpg/);

        // closeSettingsPopover resets aria-expanded="false"
        const settingsBtn = createFakeEl('button');
        settingsBtn.setAttribute('aria-expanded', 'true');
        b.element = createFakeEl('div');
        b.element.querySelector = (sel) => (sel.includes('toggleSettings') ? settingsBtn : null);
        b.hasSettingsPopoverTarget = true;
        b.settingsPopoverTarget = createFakeEl('div');
        b.settingsPopoverTarget.classList.add('is-open');
        b.closeSettingsPopover();
        assert.equal(b.settingsPopoverTarget.classList.contains('is-open'), false);
        assert.equal(settingsBtn.getAttribute('aria-expanded'), 'false');

        // copyTextToClipboard mounts textarea inside enclosing <dialog> (not inert document.body)
        const fakeDialog = createFakeEl('dialog');
        const fakeBody = createFakeEl('body');
        b.element.closest = (sel) => (sel === 'dialog' ? fakeDialog : null);
        let mountedInDialog = false;
        const origAppend = fakeDialog.appendChild.bind(fakeDialog);
        fakeDialog.appendChild = (node) => { mountedInDialog = true; return origAppend(node); };
        Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true });
        globalThis.document = {
            body: fakeBody,
            createElement: (tag) => {
                const el = createFakeEl(tag);
                el.select = () => {};
                el.remove = () => {};
                return el;
            },
            execCommand: () => true,
        };
        const copied = await b.copyTextToClipboard('/uploads/a.jpg');
        assert.equal(copied, true);
        assert.equal(mountedInDialog, true, 'Fallback clipboard textarea must mount inside <dialog> to avoid top-layer inertness');

        // uploadTargetDir stays set during onFileInputChange while upload modal is open, clears on closeModal()
        b.uploadTargetDir = 'invoices';
        b.hasModalBackdropTarget = true;
        b.modalBackdropTarget = createFakeEl('div');
        b.modalBackdropTarget.classList.add('is-open');
        b.hasModalBodyTarget = true;
        b.modalBodyTarget = createFakeEl('div');
        b.enqueueFiles = () => {};
        b.onFileInputChange({ target: { files: [{ name: 'a.pdf' }], value: 'x' } });
        assert.equal(b.uploadTargetDir, 'invoices');
        b.closeModal();
        assert.equal(b.uploadTargetDir, null);

        // renderTrash header includes liveux-fm-row-wrap & context quickbar rejects data:image href
        b.hasContentAreaTarget = true;
        b.contentAreaTarget = createFakeEl('div');
        b.renderTrash([]);
        assert.match(b.contentAreaTarget.innerHTML, /liveux-fm-row-between liveux-fm-row-wrap/);
        const quickHtml = b.buildItemQuickBarHtml({
            isFolder: false,
            caps: { download: true },
            isPinned: false,
            downloadUrl: 'data:image/png;base64,AAAA',
        });
        assert.doesNotMatch(quickHtml, /href="data:/);
    } finally {
        globalThis.window = prevWinB;
        globalThis.document = prevDocB;
        globalThis.Image = prevImage;
        Object.defineProperty(globalThis, 'navigator', { value: prevNav, configurable: true });
    }
});

test('22. Pass-17 hardening: percent-encoded BiDi/C1 URL guards across all 6 JS files, 5-theme dark/unstyled/daisyui tokens, DnD overlay unlock, inline modal/lightbox Escape & Tab trap, touch/keyboard picker reorder, fallback history & RTL/mobile polish', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);
    const pkgRoot = path.resolve(assetsDir, '..');

    // 1. Percent-encoded BiDi RLO (%E2%80%AE), ZWSP (%E2%80%8B), BOM (%EF%BB%BF), C1 (%C2%85) rejected across all 6 JS files
    const pSec = new PickerController();
    const bSec = new BrowserController();
    const dSec = new DialogController();
    const spoofUrls = [
        '/uploads/report%E2%80%AEgnp.exe',
        '/uploads/a%E2%80%8Bb.png',
        '/uploads/%EF%BB%BFhidden.jpg',
        '/uploads/ctrl%C2%85file.png',
        '/uploads/double%25E2%2580%25AE.png',
    ];
    for (const u of spoofUrls) {
        assert.equal(pSec.isSafeUrl(u), false, `Picker must reject ${u}`);
        assert.equal(bSec.isSafeHttpOrPathUrl(u), false, `Browser must reject ${u}`);
        assert.equal(dSec.isSafeSameOriginPath(u), false, `Dialog must reject ${u}`);
    }
    assert.equal(pSec.extractFilename('/uploads/report%E2%80%AEgnp.exe'), 'reportgnp.exe', 'extractFilename must strip decoded BiDi RLO control chars');

    // Verify all 3 WYSIWYG adapters reject percent-encoded BiDi URLs
    const prevWinAd = globalThis.window;
    try {
        globalThis.window = {
            LiveUXFileManager: {
                open: async () => [{ url: '/uploads/report%E2%80%AEgnp.exe', name: 'bad.png', isImage: true }],
            },
        };
        let ckInserted = false;
        await ckMod.openFileManagerForCKEditor5({ model: { change() { ckInserted = true; } } });
        assert.equal(ckInserted, false, 'CKEditor5 adapter must reject percent-encoded BiDi URL');

        let tinyCb = null;
        await tinyMod.createTinyMceFilePicker()((u) => { tinyCb = u; }, '', {});
        assert.equal(tinyCb, null, 'TinyMCE adapter must reject percent-encoded BiDi URL');

        let tipRan = false;
        await tipMod.insertFromFileManager({
            schema: { marks: {} },
            chain: () => ({ focus() { return this; }, insertContent() { tipRan = true; return this; }, run() {} }),
        });
        assert.equal(tipRan, false, 'Tiptap adapter must reject percent-encoded BiDi URL');
    } finally {
        globalThis.window = prevWinAd;
    }

    // 2. DnD overlay unlock on onFolderDragLeave when externalDragDepth === 0 + stale this.dragging cleared on applyClientView
    const bDnd = new BrowserController();
    bDnd.dragging = null;
    bDnd.externalDragDepth = 0;
    let overlayHidden = 0;
    bDnd.hideDropOverlay = () => { overlayHidden += 1; };
    bDnd.showDropOverlay = () => {};
    const fakeFolderNode = createFakeEl('div');
    fakeFolderNode.contains = () => false;
    bDnd.onFolderDragLeave({ currentTarget: fakeFolderNode, relatedTarget: null });
    assert.equal(overlayHidden, 1, 'Leaving sidebar/breadcrumb folder with externalDragDepth=0 must hide drop overlay');

    bDnd.dragging = { path: 'a.jpg' };
    bDnd.hasContentAreaTarget = false;
    bDnd.cancelScheduledRender = () => {};
    bDnd.applyClientView();
    assert.equal(bDnd.dragging, null, 'applyClientView must clear stale internal dragging state');

    // 3. Inline mode Escape & Tab trap when focus drops to document.body inside open modal/lightbox
    const prevDocA11y = globalThis.document;
    try {
        const fakeBody = createFakeEl('body');
        const btnA = createFakeEl('button');
        const btnB = createFakeEl('button');
        let focusedBtn = null;
        btnA.focus = () => { focusedBtn = btnA; };
        btnB.focus = () => { focusedBtn = btnB; };
        globalThis.document = { body: fakeBody, activeElement: fakeBody };

        const bA11y = new BrowserController();
        bA11y.isFullscreen = false;
        bA11y.element = createFakeEl('div');
        bA11y.element.contains = () => false;
        bA11y.hasModalBackdropTarget = true;
        bA11y.modalBackdropTarget = createFakeEl('div');
        bA11y.modalBackdropTarget.classList.add('is-open');
        bA11y.modalBackdropTarget.querySelector = () => null;
        bA11y.modalBackdropTarget.querySelectorAll = () => [btnA, btnB];
        bA11y.modalBackdropTarget.contains = (el) => el === btnA || el === btnB;
        assert.equal(bA11y.isFileManagerActive(), true, 'Open modal must keep isFileManagerActive() true even when activeElement is document.body');

        // Tab wraps cleanly from outside/last -> first
        let tabPrevented = false;
        bA11y.onKeydown({ key: 'Tab', shiftKey: false, preventDefault() { tabPrevented = true; } });
        assert.equal(tabPrevented, true);
        assert.equal(focusedBtn, btnA, 'Tab must trap focus into first modal focusable');

        // Shift+Tab on first wraps to last
        globalThis.document.activeElement = btnA;
        tabPrevented = false;
        bA11y.onKeydown({ key: 'Tab', shiftKey: true, preventDefault() { tabPrevented = true; } });
        assert.equal(tabPrevented, true);
        assert.equal(focusedBtn, btnB, 'Shift+Tab on first control must wrap to last modal focusable');
    } finally {
        globalThis.document = prevDocA11y;
    }

    // 4. Touch & Keyboard multi-picker gallery reorder
    const pRe = new PickerController();
    pRe.hasInputTarget = true;
    pRe.inputTarget = createFakeEl('input');
    pRe.inputTarget.value = JSON.stringify(['/uploads/a.jpg', '/uploads/b.jpg', '/uploads/c.jpg']);
    pRe.hasGalleryGridTarget = true;
    pRe.galleryGridTarget = createFakeEl('div');
    pRe.element = createFakeEl('div');
    pRe.variantValue = 'card';
    pRe.multipleValue = true;
    pRe.syncView = () => {};
    pRe.moveGalleryItem({ currentTarget: { dataset: { index: '0', delta: '1' } }, preventDefault() {}, stopPropagation() {} });
    assert.deepEqual(JSON.parse(pRe.inputTarget.value), ['/uploads/b.jpg', '/uploads/a.jpg', '/uploads/c.jpg']);
    pRe.renderGallery();
    assert.match(pRe.galleryGridTarget.innerHTML, /liveux-fm-picker-reorder-btn/, 'Gallery cards must render touch-accessible reorder buttons');

    // 5. Fallback history reconciliation & switchVolume rollback safety
    const bHist = new BrowserController();
    bHist.expandedFolders = new Set(['local::dead', 'local::dead/child', 'local::alive']);
    bHist.history = [
        { volume: 'local', path: '' },
        { volume: 'local', path: 'dead' },
        { volume: 'local', path: 'dead/child' },
    ];
    bHist.historyIndex = 2;
    bHist.syncNavButtons = () => {};
    bHist.reconcileFallbackNavigationState('local', 'dead/child', '');
    assert.equal(bHist.expandedFolders.has('local::dead/child'), false);
    assert.deepEqual(bHist.history[bHist.historyIndex], { volume: 'local', path: '' });

    const bVol = new BrowserController();
    bVol.volumeValue = 'local';
    bVol.volumeLabelValue = 'Local Disk';
    let labelAtNavStart = null;
    bVol.navigateTo = async () => { labelAtNavStart = bVol.volumeLabelValue; return false; };
    await bVol.switchVolume({ currentTarget: { value: 's3', selectedIndex: 0, options: [{ text: 'Broken S3' }] } });
    assert.equal(labelAtNavStart, 'Local Disk', 'switchVolume must not overwrite volumeLabelValue before navigateTo captures navRollbackBase');

    // 6. DialogHost loadingLabel aria-label & stale data-fm-color-scheme cleared on profile switch
    const makeTreeNode = (tag) => {
        const el = createFakeEl(tag);
        el.children = [];
        el.appendChild = (c) => { el.children.push(c); return c; };
        return el;
    };
    const dlg2 = new DialogController();
    dlg2.dialogTarget = makeTreeNode('dialog');
    dlg2.dialogTarget.setAttribute('data-fm-color-scheme', 'dark');
    dlg2.bodyTarget = makeTreeNode('div');
    dlg2.frameUrlValue = '/_liveux-file-manager/dialog';
    dlg2.loadingLabelValue = 'Yükleniyor…';
    const prevFetchDlg = globalThis.fetch;
    const prevDocDlg = globalThis.document;
    try {
        globalThis.document = {
            documentElement: makeTreeNode('html'),
            createElement: (tag) => makeTreeNode(tag),
        };
        globalThis.fetch = async () => ({ ok: false, text: async () => 'fail' });
        await dlg2.handleGlobalOpen({ detail: { profile: 'other' } });
        assert.equal(dlg2.dialogTarget.getAttribute('data-fm-color-scheme'), null, 'Switching profiles must clear stale data-fm-color-scheme on dialog');
        dlg2.bodyTarget.children = [];
        dlg2.renderLoading();
        const wrap = dlg2.bodyTarget.children[0];
        const spinner = wrap?.children?.find((c) => c.getAttribute('role') === 'status');
        assert.equal(spinner?.getAttribute('aria-label'), 'Yükleniyor…', 'Dialog loading spinner must include localized aria-label');
    } finally {
        globalThis.fetch = prevFetchDlg;
        globalThis.document = prevDocDlg;
    }

    // 7. Static CSS & Twig checks for all 5 theme fixes, BiDi isolation, mobile wraps & picker dir="ltr"
    const coreCss = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');
    const pickerTwig = fs.readFileSync(path.join(pkgRoot, 'templates/form/picker.html.twig'), 'utf8');
    const mainTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_main.html.twig'), 'utf8');
    const dialogTwig = fs.readFileSync(path.join(pkgRoot, 'templates/components/DialogHost.html.twig'), 'utf8');

    assert.match(coreCss, /\[data-fm-color-scheme="dark"\]\s*\[data-liveux-theme="tabler"\][\s\S]*--liveux-fm-accent-soft:\s*rgba\(32,\s*107,\s*196/);
    assert.match(coreCss, /\[data-fm-color-scheme="dark"\]\s*\[data-liveux-theme="preline"\][\s\S]*--liveux-fm-accent-soft:\s*rgba\(59,\s*130,\s*246/);
    assert.match(coreCss, /\[data-fm-color-scheme="dark"\]\s*\[data-liveux-theme="shadcn"\][\s\S]*--liveux-fm-ring:\s*rgba\(250,\s*250,\s*250/);
    assert.match(coreCss, /\[data-fm-color-scheme="light"\]\s*\[data-liveux-theme="daisyui"\]/);
    assert.match(coreCss, /\[data-liveux-theme="unstyled"\]\s*\.liveux-fm-lightbox/);
    assert.match(coreCss, /\[data-liveux-theme="unstyled"\]\s*\.liveux-fm-list-header/);
    assert.match(coreCss, /\[data-position="top"\]\s*:is\(\.liveux-fm-shell,\s*\.liveux-fm-dialog-loading,\s*\.liveux-fm-dialog-error\)/);
    assert.match(coreCss, /\[data-url-import-form\]\s*input\[type="url"\]/);
    assert.match(coreCss, /@media\s*\(max-width:\s*575\.98px\)[\s\S]*\.liveux-fm-picker-single\s*\{[\s\S]*flex-wrap:\s*wrap/);
    assert.match(pickerTwig, /value is not empty \? 'dir="ltr"' : ''/);
    assert.match(mainTwig, /liveux-fm-row-sm liveux-fm-row-wrap/);
    assert.match(dialogTwig, /data-liveux--file-manager-bundle--dialog-loading-label-value=/);
});

test('23. Pass-18 regressions: unstyled dark lightbox & WCAG contrast tokens, restoreLastVisitedLocation abort race, context menu focus/keyboard guard, tree focus & ARIA roles, picker RTL dir sync & localized reorder aria-label, mobile folder/avatar/ctx CSS & RTL shortcuts', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');

    // 1. P18-16: navigateTo returns `null` when superseded/aborted so restoreLastVisitedLocation does NOT clobber newer user navigation or wipe localStorage
    const bNav = new BrowserController();
    bNav.profileValue = 'default';
    bNav.volumeValue = 'local';
    bNav.volumeLabelValue = 'Local';
    bNav.pathValue = '';
    bNav.trashMode = false;
    bNav.navToken = 1;
    bNav.history = [{ volume: 'local', path: '' }];
    bNav.historyIndex = 0;
    bNav.dirCache = new Map();
    bNav.selectedMap = new Map();
    bNav.expandedFolders = new Set();
    bNav.getAvailableVolumeNames = () => new Set(['local', 's3']);
    bNav.syncTrashButton = () => {};
    bNav.syncTrashToolbarState = () => {};
    bNav.renderBreadcrumbs = () => {};
    bNav.syncNavButtons = () => {};
    bNav.renderSidebarTree = () => {};
    bNav.buildBreadcrumbs = () => [];
    bNav.fetchDirectory = async () => null; // Aborted / superseded by newer user click
    const navRes = await bNav.navigateTo('archive', 's3', false, { silent: true, allowFallback: true });
    assert.equal(navRes, null, 'Superseded/aborted navigateTo() must return null, not false');

    let clearedStorage = false;
    let rolledBack = false;
    const prevStorage = globalThis.localStorage;
    try {
        globalThis.localStorage = {
            getItem: (k) => (k === 'liveux_fm_last_vol:default' ? 's3' : (k === 'liveux_fm_last_dir:default:s3' ? 'slow-dir' : null)),
            removeItem: () => { clearedStorage = true; },
        };
        const bRest = new BrowserController();
        bRest.profileValue = 'default';
        bRest.volumeValue = 'local';
        bRest.volumeLabelValue = 'Local';
        bRest.pathValue = '';
        bRest.navToken = 1;
        bRest.isDisconnected = false;
        bRest.explicitVolumeRequested = false;
        bRest.getAvailableVolumeNames = () => new Set(['local', 's3']);
        bRest.renderSidebarTree = () => { rolledBack = true; };
        bRest.renderBreadcrumbs = () => {};
        bRest.navigateTo = async () => {
            // Simulate user clicking another folder mid-restore (increments navToken & aborts restore -> returns null)
            bRest.navToken += 2;
            bRest.volumeValue = 'local';
            bRest.pathValue = 'user-clicked';
            return null;
        };
        await bRest.restoreLastVisitedLocation();
        assert.equal(clearedStorage, false, 'Aborted restoreLastVisitedLocation must NOT wipe localStorage');
        assert.equal(rolledBack, false, 'Aborted restoreLastVisitedLocation must NOT roll back over newer navigation');
        assert.equal(bRest.pathValue, 'user-clicked');
    } finally {
        globalThis.localStorage = prevStorage;
    }

    // 2. P18-18: Context menu focus management, arrow navigation, Escape focus restore & background grid shortcut guard
    const prevDocCtx = globalThis.document;
    const prevWinCtx = globalThis.window;
    try {
        const triggerBtn = createFakeEl('button');
        triggerBtn.isConnected = true;
        let focusedEl = triggerBtn;
        triggerBtn.focus = () => { focusedEl = triggerBtn; globalThis.document.activeElement = triggerBtn; };

        const item1 = createFakeEl('button');
        const item2 = createFakeEl('button');
        item1.focus = () => { focusedEl = item1; globalThis.document.activeElement = item1; };
        item2.focus = () => { focusedEl = item2; globalThis.document.activeElement = item2; };

        globalThis.window = { innerWidth: 1024, innerHeight: 768 };
        globalThis.document = { body: createFakeEl('body'), activeElement: triggerBtn };

        const bCtx = new BrowserController();
        bCtx.element = createFakeEl('div');
        bCtx.element.contains = () => false;
        bCtx.keyboardShortcutsValue = true;
        bCtx.hasContextMenuTarget = true;
        bCtx.contextMenuTarget = createFakeEl('div');
        bCtx.contextMenuTarget.querySelector = () => item1;
        bCtx.contextMenuTarget.querySelectorAll = () => [item1, item2];
        bCtx.contextMenuTarget.contains = (el) => el === item1 || el === item2;
        bCtx.closeSettingsPopover = () => {};
        bCtx.isRtl = () => false;
        let gridArrowCalled = false;
        bCtx.navigateSelectionByArrow = () => { gridArrowCalled = true; };

        bCtx.positionAndOpenContextMenu(bCtx.contextMenuTarget, 100, 100);
        assert.equal(focusedEl, item1, 'Opening context menu must focus first interactive menu item');
        assert.equal(bCtx.isFileManagerActive(), true, 'Open context menu must keep isFileManagerActive() true');

        let arrowPrevented = false;
        bCtx.onKeydown({ key: 'ArrowDown', preventDefault() { arrowPrevented = true; } });
        assert.equal(arrowPrevented, true);
        assert.equal(focusedEl, item2, 'ArrowDown inside open context menu must cycle to next menu item');
        assert.equal(gridArrowCalled, false, 'ArrowDown while context menu is open must NOT move background grid selection');

        let escPrevented = false;
        bCtx.onKeydown({ key: 'Escape', preventDefault() { escPrevented = true; } });
        assert.equal(escPrevented, true);
        assert.equal(bCtx.isContextMenuOpen(), false, 'Escape must close context menu');
        assert.equal(focusedEl, triggerBtn, 'Escape closing context menu must restore focus to trigger element');
    } finally {
        globalThis.document = prevDocCtx;
        globalThis.window = prevWinCtx;
    }

    // 3. P18-20 & P18-21: Picker inputTarget dir="ltr" sync on select/clear & localized reorder aria-label
    const pRtl = new PickerController();
    pRtl.hasInputTarget = true;
    pRtl.inputTarget = createFakeEl('input');
    pRtl.inputTarget.type = 'text';
    pRtl.inputTarget.value = '/uploads/photo.jpg';
    pRtl.element = createFakeEl('div');
    pRtl.element.querySelectorAll = () => [];
    pRtl.isDisabled = () => false;
    pRtl.renderSinglePreview();
    assert.equal(pRtl.inputTarget.getAttribute('dir'), 'ltr', 'Populated compact/input picker must set dir="ltr" on inputTarget');
    pRtl.inputTarget.value = '';
    pRtl.renderSinglePreview();
    assert.equal(pRtl.inputTarget.getAttribute('dir'), null, 'Cleared compact/input picker must remove dir="ltr" so RTL placeholder aligns naturally');

    const pGal = new PickerController();
    pGal.hasInputTarget = true;
    pGal.inputTarget = createFakeEl('input');
    pGal.inputTarget.value = JSON.stringify(['/uploads/1.jpg', '/uploads/2.jpg']);
    pGal.hasGalleryGridTarget = true;
    pGal.galleryGridTarget = createFakeEl('div');
    pGal.element = createFakeEl('div');
    pGal.variantValue = 'avatar';
    pGal.multipleValue = true;
    pGal.movePrevLabelValue = 'Önceki';
    pGal.moveNextLabelValue = 'Sonraki';
    pGal.bindGalleryReorder = () => {};
    pGal.renderGallery();
    assert.match(pGal.galleryGridTarget.innerHTML, /aria-label="Önceki"/, 'Reorder prev button must use localized movePrevLabelValue');
    assert.match(pGal.galleryGridTarget.innerHTML, /aria-label="Sonraki"/, 'Reorder next button must use localized moveNextLabelValue');

    // 4. P18-22: toggleTreeBranch restores focus to rebuilt chevron
    const rebuiltChevron = createFakeEl('button');
    rebuiltChevron.dataset = { folderPath: 'docs/2026' };
    let chevronFocused = false;
    rebuiltChevron.focus = () => { chevronFocused = true; };
    const bTree = new BrowserController();
    bTree.volumeValue = 'local';
    bTree.expandedFolders = new Set();
    bTree.hasTreeTarget = true;
    bTree.treeTarget = createFakeEl('div');
    bTree.treeTarget.querySelectorAll = () => [rebuiltChevron];
    bTree.renderSidebarTree = () => {};
    bTree.toggleTreeBranch({ stopPropagation() {}, currentTarget: { dataset: { folderPath: 'docs/2026' } } });
    assert.equal(chevronFocused, true, 'toggleTreeBranch must restore keyboard focus to rebuilt chevron');

    // 5. Static CSS & Twig checks for Pass-18 contrast, layout, ARIA & RTL fixes
    const pkgRoot = path.resolve(__dirname, '../..');
    const coreCss = fs.readFileSync(path.join(pkgRoot, 'assets/styles/core.css'), 'utf8');
    const pickerTwig = fs.readFileSync(path.join(pkgRoot, 'templates/form/picker.html.twig'), 'utf8');
    const sidebarTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_sidebar.html.twig'), 'utf8');
    const overlaysTwig = fs.readFileSync(path.join(pkgRoot, 'templates/base/parts/_overlays.html.twig'), 'utf8');
    const browserJs = fs.readFileSync(path.join(pkgRoot, 'assets/controllers/browser_controller.js'), 'utf8');

    assert.match(coreCss, /--liveux-fm-elevated-text:\s*#f1f5f9/, 'Unstyled dark mode must define --liveux-fm-elevated-text for Lightbox contrast');
    assert.match(coreCss, /--liveux-fm-danger-solid:\s*#dc2626/, 'Dark mode must define WCAG AA --liveux-fm-danger-solid');
    assert.match(coreCss, /--liveux-fm-success-solid:\s*#15803d/, 'Must define WCAG AA --liveux-fm-success-solid for success badges');
    assert.match(coreCss, /\.liveux-fm-picker-avatar-chip\s*\{[\s\S]*height:\s*auto/);
    assert.doesNotMatch(coreCss, /\.liveux-fm-picker\s+input\.liveux-fm-mono,/);
    assert.match(coreCss, /\.liveux-fm-ctx-menu\s*\{[\s\S]*max-height:\s*calc\(100dvh - 16px\)/);
    assert.match(coreCss, /\.liveux-fm-folder-tile\s*>\s*\.liveux-fm-folder-count\s*\{[\s\S]*position:\s*static/);
    assert.match(pickerTwig, /move-prev-label-value=/);
    assert.match(pickerTwig, /move-next-label-value=/);
    assert.match(sidebarTwig, /class="liveux-fm-pinned-box liveux-fm-hidden" role="tree"/);
    assert.match(overlaysTwig, /this\.isRtl \? 'Alt\+→ \/ ←' : 'Alt\+← \/ →'/);
    assert.match(browserJs, /role="tree" aria-label="[^"]*" data-tree-lines=.*data-move-tree-box/);
    assert.match(browserJs, /aria-current="page"/);
    assert.match(browserJs, /this\.t\('ui\.key_delete'\)/);
    assert.match(browserJs, /class="liveux-fm-range"[^>]*data-ed-quality/);
});
