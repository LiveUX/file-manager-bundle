import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const assetsDir = path.resolve(__dirname, '../../assets');

/**
 * Loads a real Stimulus controller ESM file from assets/ in Node without requiring npm packages,
 * replacing only the `@hotwired/stimulus` base class import with a lightweight stub.
 */
async function loadStimulusController(relPath) {
    const absPath = path.join(assetsDir, relPath);
    const apiFileUrl = pathToFileURL(path.join(assetsDir, 'js/file_manager_api.js')).href;
    const source = fs.readFileSync(absPath, 'utf8')
        .replace(
            /import\s*\{\s*Controller\s*\}\s*from\s*['"]@hotwired\/stimulus['"]\s*;/,
            'class Controller { constructor() { this.element = null; } }',
        )
        .replace(
            /from\s*['"]\.\.\/js\/file_manager_api\.js['"]/g,
            `from ${JSON.stringify(apiFileUrl)}`,
        );
    const dataUrl = `data:text/javascript;base64,${Buffer.from(source, 'utf8').toString('base64')}`;
    const mod = await import(dataUrl);
    return mod.default;
}

function createFakeElement(tag = 'div') {
    const attrs = new Map();
    const classes = new Set();
    const el = {
        tagName: tag.toUpperCase(),
        innerHTML: '',
        textContent: '',
        value: '',
        title: '',
        disabled: false,
        dataset: {},
        style: {},
        attributes: attrs,
        classList: {
            add: (...c) => c.forEach((x) => classes.add(x)),
            remove: (...c) => c.forEach((x) => classes.delete(x)),
            toggle: (c, force) => (force === undefined ? (classes.has(c) ? classes.delete(c) : classes.add(c)) : (force ? classes.add(c) : classes.delete(c))),
            contains: (c) => classes.has(c),
        },
        setAttribute: (k, v) => attrs.set(k, String(v)),
        getAttribute: (k) => attrs.get(k) ?? null,
        removeAttribute: (k) => attrs.delete(k),
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        removeEventListener: () => {},
        replaceChildren: (...nodes) => {
            el._children = nodes;
        },
        appendChild: (node) => {
            el._children = el._children || [];
            el._children.push(node);
        },
        scrollIntoView: () => {},
        contains: () => false,
        closest: () => null,
        dispatchEvent: () => true,
        open: false,
        showModal() { this.open = true; },
        close() { this.open = false; },
    };
    return el;
}

const MALICIOUS_URLS = [
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    '  javascript:alert(1)  ',
    '\x01javascript:alert(1)',
    'java\nscript:alert(1)',
    'vbscript:msgbox(1)',
    'data:text/html,<script>alert(1)</script>',
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+PC9zdmc+',
    'data:image/png;base64,AAA" onerror="alert(1)',
    '//evil.example/xss.png',
    '/\\evil.example/xss.png',
    '\\/evil.example/xss.png',
    'https://evil.example/a\r\nSet-Cookie:a=b',
    'https://evil.example/a\x00.png',
    'blob:https://example.com/uuid-123',
    'file:///etc/passwd',
    'ftp://evil.example/a.png',
    '',
    '   ',
];

const SAFE_HTTP_OR_PATH_URLS = [
    '/uploads/photo.jpg',
    '/_liveux-file-manager/download?profile=default&path=a.pdf',
    'https://cdn.example.com/media/banner.webp?v=123',
    'http://localhost:8000/uploads/file%20name.png',
];

const XSS_PAYLOAD = '"><img src=x onerror=alert(1)><script>alert(2)</script>\'';

/**
 * Parses actual HTML tags and their attribute names/values (outside quoted strings)
 * to assert zero real <script>, zero real on*= event handlers, and zero unsafe src/href values.
 */
function assertSafeRenderedHtml(html, contextLabel) {
    const tagRegex = /<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^>]*)?>/g;
    let match;
    while ((match = tagRegex.exec(html)) !== null) {
        const tagName = match[1].toLowerCase();
        const attrChunk = match[2] || '';
        assert.notEqual(tagName, 'script', `[${contextLabel}] Must not emit <script> tag`);
        assert.notEqual(tagName, 'object', `[${contextLabel}] Must not emit <object> tag`);
        assert.notEqual(tagName, 'embed', `[${contextLabel}] Must not emit <embed> tag`);

        const attrRegex = /([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
        let attrMatch;
        while ((attrMatch = attrRegex.exec(attrChunk)) !== null) {
            const attrName = attrMatch[1].toLowerCase();
            const attrVal = attrMatch[2] ?? attrMatch[3] ?? attrMatch[4] ?? '';
            assert.equal(
                attrName.startsWith('on'),
                false,
                `[${contextLabel}] Must not emit inline event handler "${attrName}" on <${tagName}>`,
            );
            if (['src', 'href', 'xlink:href', 'action', 'formaction'].includes(attrName)) {
                assert.doesNotMatch(
                    attrVal.trim(),
                    /^(javascript|vbscript|data:text\/html|data:image\/svg|\/\/|\/\\)/i,
                    `[${contextLabel}] Unsafe ${attrName}="${attrVal}" on <${tagName}>`,
                );
                assert.doesNotMatch(attrVal, /evil\.com/i, `[${contextLabel}] External evil.com in ${attrName}`);
            }
            if (attrName === 'style') {
                assert.doesNotMatch(attrVal, /(javascript:|expression\s*\(|url\s*\()/i, `[${contextLabel}] Unsafe CSS style="${attrVal}"`);
            }
        }
    }
}

test('BrowserController: isSafeUrl & isSafeHttpOrPathUrl block all bypass vectors', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const ctrl = new BrowserController();

    for (const bad of MALICIOUS_URLS) {
        assert.equal(ctrl.isSafeUrl(bad), false, `isSafeUrl MUST reject: ${JSON.stringify(bad)}`);
        assert.equal(ctrl.isSafeHttpOrPathUrl(bad), false, `isSafeHttpOrPathUrl MUST reject: ${JSON.stringify(bad)}`);
    }

    for (const good of SAFE_HTTP_OR_PATH_URLS) {
        assert.equal(ctrl.isSafeUrl(good), true, `isSafeUrl should allow: ${good}`);
        assert.equal(ctrl.isSafeHttpOrPathUrl(good), true, `isSafeHttpOrPathUrl should allow: ${good}`);
    }

    // Valid raster base64 data URI allowed for isSafeUrl (<img>) ONLY, NOT for isSafeHttpOrPathUrl (<iframe>/<video>)
    const validPngData = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    assert.equal(ctrl.isSafeUrl(validPngData), true);
    assert.equal(ctrl.isSafeHttpOrPathUrl(validPngData), false);
});

test('BrowserController: escapes hostile filenames, paths, metadata, colors, focalPoint, and perPageOptions', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const ctrl = new BrowserController();
    ctrl.volumeValue = 'local" onclick="alert(1)';
    ctrl.volumeLabelValue = 'Uploads<script>alert(1)</script>';
    ctrl.pathValue = '';
    ctrl.thumbFitValue = 'contain';
    ctrl.thumbnailsEnabledValue = true;
    ctrl.capabilitiesValue = { allowDragMove: true, allowContextMenu: true, editMetadata: true, download: true, share: true };
    ctrl.i18nValue = { 'empty.subfolder_label': 'Folder', 'sidebar.pinned': 'Pinned', 'actions.preview': 'Preview' };
    ctrl.iconSetValue = { defaultFolderColor: 'red;background:url(javascript:alert(1))', icons: {} };
    ctrl.perPageOptionsValue = ['<img src=x onerror=alert(1)>', '99999', 24, 48];
    ctrl.perPageValue = 24;
    ctrl.sortByValue = 'name';
    ctrl.sortDirValue = 'asc';
    ctrl.renderedPaths = [];
    ctrl.dirCache = new Map();
    ctrl.treeCache = new Map();
    ctrl.expandedFolders = new Set();
    ctrl.selectedMap = new Map();
    ctrl.history = [{ volume: 'local', path: '' }];
    ctrl.historyIndex = 0;

    // 1. Grid & List item rendering with hostile file and folder objects
    const hostileFile = {
        kind: 'file',
        name: XSS_PAYLOAD,
        path: `dir/${XSS_PAYLOAD}`,
        size: 123,
        sizeFormatted: '123 B<script>',
        extension: 'png" onmouseover="alert(1)',
        isImage: true,
        url: 'javascript:alert(1)',
        downloadUrl: '/\\evil.com/steal.png',
        thumbUrl: 'data:image/svg+xml;base64,PHN2Zz4=',
        meta: {
            alt: `</textarea><script>alert(1)</script>`,
            title: XSS_PAYLOAD,
            caption: `</textarea><img src=x onerror=alert(1)>`,
            tags: [XSS_PAYLOAD],
            focalPoint: { x: '50%;background:red', y: NaN },
        },
    };

    const gridHtml = ctrl.renderGridItem(hostileFile, true);
    const listHtml = ctrl.renderListItem(hostileFile, true);
    for (const html of [gridHtml, listHtml]) {
        assertSafeRenderedHtml(html, 'grid/list hostile file');
        assert.doesNotMatch(html, /<img\b/i, 'Hostile thumbUrl must not emit <img>');
        assert.match(html, /data-url=""/, 'Hostile url must be cleared to empty');
        assert.match(html, /data-download-url=""/, 'Hostile downloadUrl must be cleared to empty');
    }

    // 2. Hostile folder color CSS injection blocked
    const hostileFolder = {
        kind: 'folder',
        name: XSS_PAYLOAD,
        path: `folder/${XSS_PAYLOAD}`,
        color: '#2563eb; background: url(javascript:alert(1))',
        pinned: true,
    };
    const folderHtml = ctrl.renderGridItem(hostileFolder, true);
    assertSafeRenderedHtml(folderHtml, 'hostile folder');
    assert.doesNotMatch(folderHtml, /background:/i);

    // 3. Prototype property lookup resistance (t() and renderFileTypeSvg())
    assert.equal(ctrl.t('toString'), 'toString');
    assert.equal(ctrl.t('constructor'), 'constructor');
    assert.equal(ctrl.t('__proto__'), '__proto__');
    for (const protoExt of ['constructor', 'toString', '__proto__', 'valueOf', 'hasOwnProperty']) {
        const badge = ctrl.renderFileTypeSvg(protoExt);
        assert.equal(typeof badge, 'string');
        assert.doesNotMatch(badge, /function\s+/i);
    }

    // 4. Empty area context menu strips non-numeric perPageOptionsValue
    const menuEl = createFakeElement('div');
    ctrl.hasContextMenuTarget = true;
    ctrl.contextMenuTarget = menuEl;
    ctrl.hasSettingsPopoverTarget = false;
    globalThis.window = { innerWidth: 1280, innerHeight: 800 };
    ctrl.showEmptyAreaContextMenu(10, 10);
    assert.doesNotMatch(menuEl.innerHTML, /<img/i);
    assert.doesNotMatch(menuEl.innerHTML, /onerror/i);
    assert.match(menuEl.innerHTML, /data-ctx-limit="24"/);
    assert.doesNotMatch(menuEl.innerHTML, /99999/);

    // 5. Lightbox PDF routing & unsafe URL rejection
    const stageEl = createFakeElement('div');
    ctrl.hasLightboxStageTarget = true;
    ctrl.lightboxStageTarget = stageEl;
    ctrl.hasLightboxCaptionTarget = false;
    ctrl.hasLightboxCounterTarget = false;
    ctrl.hasLightboxControlsTarget = false;
    ctrl.lightboxIndex = 0;

    // 5a. Safe PDF routes via hardened downloadUrl + inline=1 + referrerpolicy="no-referrer"
    ctrl.lightboxItems = [{
        kind: 'file',
        name: 'report.pdf',
        extension: 'pdf',
        url: '/uploads/report.pdf',
        downloadUrl: '/_liveux-file-manager/download?profile=default&path=report.pdf',
    }];
    await ctrl.renderLightboxCurrent();
    assert.match(stageEl.innerHTML, /<iframe\s+src="\/_liveux-file-manager\/download\?profile=default&amp;path=report\.pdf&amp;inline=1"/);
    assert.match(stageEl.innerHTML, /referrerpolicy="no-referrer"/);

    // 5b. Unsafe PDF URL renders ZERO <iframe> (prevents empty src="" self-framing or javascript: execution)
    ctrl.lightboxItems = [{
        kind: 'file',
        name: 'bad.pdf',
        extension: 'pdf',
        url: 'javascript:alert(1)',
        downloadUrl: '/\\evil.com/bad.pdf',
    }];
    await ctrl.renderLightboxCurrent();
    assert.doesNotMatch(stageEl.innerHTML, /<iframe/i);

    // 5c. Unsafe Image/Video/Audio renders ZERO media tags
    for (const [ext, isImg] of [['jpg', true], ['mp4', false], ['mp3', false]]) {
        ctrl.lightboxItems = [{ kind: 'file', name: `x.${ext}`, extension: ext, isImage: isImg, url: '/\\evil.com/x' }];
        await ctrl.renderLightboxCurrent();
        assert.doesNotMatch(stageEl.innerHTML, /<(img|video|audio|iframe)\b/i);
    }

    // 6. Inspector focalPoint clamping with hostile values
    globalThis.Image = class FakeImage { set src(v) { this._src = v; } };
    const inspEl = createFakeElement('aside');
    ctrl.hasInspectorTarget = true;
    ctrl.inspectorTarget = inspEl;
    ctrl.inspectorModeValue = 'always';
    ctrl.hasFooterStatusTarget = false;
    ctrl.localeValue = 'en';
    ctrl.dirCache.set(ctrl.cacheKey(ctrl.volumeValue, ctrl.pathValue), [
        { ...hostileFile, url: '/uploads/safe.png', thumbUrl: '/uploads/thumb.webp', downloadUrl: '/_liveux-file-manager/download?path=a.png' },
    ]);
    ctrl.selectedMap.set(hostileFile.path, { kind: 'file', path: hostileFile.path, name: hostileFile.name });
    ctrl.renderInspector();
    assertSafeRenderedHtml(inspEl.innerHTML, 'inspector hostile file');
    assert.doesNotMatch(inspEl.innerHTML, /background:red/i);
    assert.doesNotMatch(inspEl.innerHTML, /NaN%/);

    // 7. Trash rendering escapes all fields
    const contentEl = createFakeElement('div');
    ctrl.hasContentAreaTarget = true;
    ctrl.contentAreaTarget = contentEl;
    ctrl.renderTrash([{ id: XSS_PAYLOAD, name: XSS_PAYLOAD, originalPath: XSS_PAYLOAD, isFolder: false, deletedAt: XSS_PAYLOAD }]);
    assertSafeRenderedHtml(contentEl.innerHTML, 'trash hostile entry');

    // 8. Inline modals (Rename, Clone, Folder Color, File Metadata, Share Link) escape all fields
    const modalBackdrop = createFakeElement('div');
    const modalTitle = createFakeElement('h6');
    const modalBody = createFakeElement('div');
    ctrl.hasModalBackdropTarget = true;
    ctrl.modalBackdropTarget = modalBackdrop;
    ctrl.modalTitleTarget = modalTitle;
    ctrl.modalBodyTarget = modalBody;

    ctrl.openRenameModal(hostileFile.path, hostileFile.name);
    assertSafeRenderedHtml(modalBody.innerHTML, 'rename modal');

    ctrl.openCloneModal(hostileFile.path, hostileFile.name);
    assertSafeRenderedHtml(modalBody.innerHTML, 'clone modal');

    ctrl.openFolderColorModal(hostileFolder.path, hostileFolder.name, hostileFolder.color);
    assertSafeRenderedHtml(modalBody.innerHTML, 'folder color modal');

    ctrl.openFileMetadataModal(hostileFile);
    assertSafeRenderedHtml(modalBody.innerHTML, 'file metadata modal');
    assert.doesNotMatch(modalBody.innerHTML, /data-ai-suggest/, 'AI button hidden when aiEnrich=false');

    ctrl.capabilitiesValue.aiEnrich = true;
    ctrl.openFileMetadataModal(hostileFile);
    assertSafeRenderedHtml(modalBody.innerHTML, 'file metadata modal with AI');
    assert.match(modalBody.innerHTML, /data-ai-suggest/, 'AI button visible when aiEnrich=true');

    ctrl.openShareLinkModal(hostileFile);
    assertSafeRenderedHtml(modalBody.innerHTML, 'share link modal');

    // 8b. Compact Flat Context Menu (Quick Action Bar + Unified SVG 1-Click Rows) escapes hostile data
    ctrl.imageEditUrlValue = '/_liveux-file-manager/api/image-edit';
    ctrl.capabilitiesValue = {
        ...ctrl.capabilitiesValue,
        move: true, copy: true, rename: true, download: true, delete: true,
        editImage: true, editMetadata: true, share: true, clone: true, createFolder: true, upload: true,
    };
    const fakeFileCard = createFakeElement('div');
    fakeFileCard.dataset = {
        ctxKind: 'file', path: hostileFile.path, name: hostileFile.name,
        url: '/uploads/safe.jpg', downloadUrl: '/_liveux-file-manager/download?path=safe.jpg',
        isImage: '1', extension: 'jpg',
    };
    ctrl.showContextMenuForElement(fakeFileCard, 20, 20);
    assertSafeRenderedHtml(menuEl.innerHTML, 'file context menu');
    assert.match(menuEl.innerHTML, /liveux-fm-ctx-actions/, 'Must render Quick Action Bar');
    assert.doesNotMatch(menuEl.innerHTML, /liveux-fm-ctx-nav/, 'Item context menu must NOT render navigation strip');
    assert.doesNotMatch(menuEl.innerHTML, /liveux-fm-ctx-submenu/, 'File context menu must be direct 1-click without nested hover submenus');
    assert.match(menuEl.innerHTML, /data-ctx="copyUrl"/);
    assert.match(menuEl.innerHTML, /data-ctx="shareLink"/);

    const fakeFolderCard = createFakeElement('div');
    fakeFolderCard.dataset = {
        ctxKind: 'folder', path: hostileFolder.path, name: hostileFolder.name,
        color: hostileFolder.color, pinned: '1',
    };
    ctrl.showContextMenuForElement(fakeFolderCard, 20, 20);
    assertSafeRenderedHtml(menuEl.innerHTML, 'folder context menu');
    assert.match(menuEl.innerHTML, /liveux-fm-ctx-actions/);
    assert.match(menuEl.innerHTML, /data-ctx="togglePin"/);
    assert.doesNotMatch(menuEl.innerHTML, /liveux-fm-ctx-submenu/, 'Folder context menu must be direct 1-click');

    // 8c. Actionable empty state & breadcrumb drop attributes escape hostile data
    ctrl.searchQuery = '';
    ctrl.typeFilterValue = 'all';
    assertSafeRenderedHtml(ctrl.renderEmptyState(), 'empty folder state');
    ctrl.searchQuery = XSS_PAYLOAD;
    assertSafeRenderedHtml(ctrl.renderEmptyState(), 'filtered empty state');
    ctrl.searchQuery = '';

    const crumbsEl = createFakeElement('nav');
    ctrl.hasBreadcrumbsTarget = true;
    ctrl.breadcrumbsTarget = crumbsEl;
    ctrl.renderBreadcrumbs([{ label: XSS_PAYLOAD, path: `a/${XSS_PAYLOAD}` }, { label: 'Leaf', path: 'a/b' }]);
    assertSafeRenderedHtml(crumbsEl.innerHTML, 'breadcrumbs with drop handlers');
    assert.match(crumbsEl.innerHTML, /onFolderDrop/);

    // 8d. Fullscreen toggle works in both <dialog> (data-fullscreen="true") and inline (.is-fullscreen) modes
    const fakeDialog = createFakeElement('dialog');
    fakeDialog.hasAttribute = (k) => fakeDialog.attributes.has(k);
    const fakeRoot = createFakeElement('div');
    fakeRoot.closest = (sel) => (sel === 'dialog' ? fakeDialog : null);
    ctrl.element = fakeRoot;
    ctrl.hasFullscreenBtnTarget = false;
    ctrl.toggleFullscreen();
    assert.equal(fakeDialog.getAttribute('data-fullscreen'), 'true', 'Dialog fullscreen must set explicit data-fullscreen="true"');
    ctrl.toggleFullscreen();
    assert.equal(fakeDialog.getAttribute('data-fullscreen'), null, 'Second toggle must exit dialog fullscreen');

    fakeRoot.closest = () => null;
    ctrl.toggleFullscreen();
    assert.equal(fakeRoot.classList.contains('is-fullscreen'), true, 'Inline fullscreen must add .is-fullscreen');
    ctrl.toggleFullscreen();
    assert.equal(fakeRoot.classList.contains('is-fullscreen'), false, 'Second toggle must remove .is-fullscreen');

    // 8e. Cut / Clipboard state shows floating clipboard bar & pasteIntoFolder in folder context menu
    const clipBar = createFakeElement('div');
    const clipCount = createFakeElement('span');
    const clipLabel = createFakeElement('span');
    const clipPasteBtn = createFakeElement('button');
    ctrl.hasClipboardBarTarget = true;
    ctrl.clipboardBarTarget = clipBar;
    ctrl.hasClipboardCountTarget = true;
    ctrl.clipboardCountTarget = clipCount;
    ctrl.hasClipboardLabelTarget = true;
    ctrl.clipboardLabelTarget = clipLabel;
    ctrl.hasClipboardPasteBtnTarget = true;
    ctrl.clipboardPasteBtnTarget = clipPasteBtn;
    ctrl.volumeValue = 'local';
    ctrl.pathValue = '';
    ctrl.trashMode = false;
    ctrl.selectedMap.clear();
    ctrl.copyToClipboard('move', ['photo.jpg']);
    assert.equal(clipBar.classList.contains('liveux-fm-hidden'), false, 'Clipboard bar must appear after Cut');
    assert.equal(clipCount.textContent, '1');
    assert.equal(clipPasteBtn.disabled, true, 'Paste Here disabled in exact same source dir for Cut');
    ctrl.pathValue = 'resimler';
    ctrl.syncClipboardUi();
    assert.equal(clipPasteBtn.disabled, false, 'Paste Here enabled once navigated into another folder');

    const targetFolderCard = createFakeElement('div');
    targetFolderCard.dataset = { ctxKind: 'folder', path: 'resimler', name: 'resimler', volume: 'local' };
    ctrl.showContextMenuForElement(targetFolderCard, 20, 20);
    assert.match(menuEl.innerHTML, /data-ctx="pasteIntoFolder"/, 'Folder context menu must expose Paste Into Folder when clipboard has items');
    ctrl.clearClipboard();
    assert.equal(clipBar.classList.contains('liveux-fm-hidden'), true, 'Clipboard bar hides after clearClipboard');

    // 8f. Keyboard shortcuts (N, U, V, B, I/ı, S, D, M, /, Arrow nav, modal guard, layered Escape)
    const actionsTriggered = [];
    ctrl.keyboardShortcutsValue = true;
    ctrl.element = fakeRoot;
    fakeRoot.contains = () => true;
    globalThis.document = { activeElement: fakeRoot, body: createFakeElement('body') };
    modalBackdrop.classList.remove('is-open');
    menuEl.classList.remove('is-open');
    ctrl.hasLightboxTarget = false;
    ctrl.hasSettingsPopoverTarget = false;
    ctrl.openNewFolderModal = () => actionsTriggered.push('newFolder');
    ctrl.openUploadModal = () => actionsTriggered.push('upload');
    ctrl.toggleSidebar = () => actionsTriggered.push('sidebar');
    ctrl.openFileMetadataModal = () => actionsTriggered.push('meta');
    ctrl.openShareLinkModal = () => actionsTriggered.push('share');
    ctrl.openCloneModal = () => actionsTriggered.push('clone');
    ctrl.openMoveModal = () => actionsTriggered.push('move');
    ctrl.applyClientView = () => {};
    ctrl.viewModeValue = 'grid';
    ctrl.hasViewButtonsTarget = false;
    ctrl.renderedPaths = ['photo.jpg', 'resimler'];
    ctrl.dirCache.set(ctrl.cacheKey('local', 'resimler'), [
        { kind: 'file', path: 'photo.jpg', name: 'photo.jpg', isImage: true, url: '/uploads/photo.jpg' },
        { kind: 'folder', path: 'resimler', name: 'resimler' },
    ]);
    const fireKey = (key, extra = {}) => {
        let prevented = false;
        ctrl.onKeydown({ key, ctrlKey: false, metaKey: false, altKey: false, preventDefault: () => { prevented = true; }, ...extra });
        return prevented;
    };
    assert.equal(fireKey('n'), true);
    assert.equal(fireKey('u'), true);
    assert.equal(fireKey('b'), true);
    assert.equal(fireKey('v'), true);
    assert.equal(ctrl.viewModeValue, 'list', 'V toggles Grid/List view');
    assert.equal(fireKey('ArrowRight'), true);
    assert.equal(ctrl.selectedMap.has('photo.jpg'), true, 'ArrowRight selects first item');
    assert.equal(fireKey('ı'), true, 'Turkish dotless ı triggers I metadata shortcut');
    assert.equal(fireKey('s'), true);
    assert.equal(fireKey('d'), true);
    assert.equal(fireKey('m'), true);
    assert.deepEqual(actionsTriggered, ['newFolder', 'upload', 'sidebar', 'meta', 'share', 'clone', 'move']);

    // Modal open guard blocks single-key shortcuts behind an open modal
    modalBackdrop.classList.add('is-open');
    actionsTriggered.length = 0;
    fireKey('n');
    fireKey('u');
    assert.deepEqual(actionsTriggered, [], 'Must NOT fire background shortcuts when a modal is open');

    // Layered Escape closes inner modal first without clearing selection
    assert.equal(fireKey('Escape'), true);
    assert.equal(modalBackdrop.classList.contains('is-open'), false);
    assert.equal(ctrl.selectedMap.size, 1, 'First Escape closes modal only, preserving selection');
    assert.equal(fireKey('Escape'), true);
    assert.equal(ctrl.selectedMap.size, 0, 'Second Escape clears selection');

    // 8g. Per-profile folder item count badge renders only when showFolderItemCount=true
    ctrl.capabilitiesValue.showFolderItemCount = true;
    const countedFolderHtml = ctrl.renderGridItem({ kind: 'folder', path: 'katalog', name: 'katalog', itemCount: 42 }, false);
    assertSafeRenderedHtml(countedFolderHtml, 'counted folder tile');
    assert.match(countedFolderHtml, /liveux-fm-folder-count"[^>]*>42</, 'Shows numeric count when showFolderItemCount=true');

    ctrl.capabilitiesValue.showFolderItemCount = false;
    const hiddenCountHtml = ctrl.renderGridItem({ kind: 'folder', path: 'katalog', name: 'katalog', itemCount: 42 }, false);
    assert.doesNotMatch(hiddenCountHtml, /liveux-fm-folder-count/, 'Hides numeric count when showFolderItemCount=false');

    // 9. Upload queue escapes malicious filenames & error messages
    const queueEl = createFakeElement('div');
    ctrl.hasUploadQueueTarget = true;
    ctrl.uploadQueueTarget = queueEl;
    ctrl.hasModalBodyTarget = false;
    ctrl.uploadJobs = [{
        id: XSS_PAYLOAD,
        file: { name: XSS_PAYLOAD },
        chunkIndex: 0,
        totalChunks: 1,
        error: XSS_PAYLOAD,
        preFlightError: true,
    }];
    ctrl.renderUploadQueue();
    assertSafeRenderedHtml(queueEl.innerHTML, 'upload queue');
});

test('PickerController: blocks malicious URLs and escapes filenames in all variants', async () => {
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const ctrl = new PickerController();

    for (const bad of MALICIOUS_URLS) {
        assert.equal(ctrl.isSafeUrl(bad), false, `Picker isSafeUrl MUST reject: ${JSON.stringify(bad)}`);
    }

    ctrl.inputTarget = createFakeElement('input');
    ctrl.inputTarget.value = 'javascript:alert(1).png';
    ctrl.hasClearBtnTarget = false;
    ctrl.hasFileLabelTarget = false;
    ctrl.hasSinglePreviewTarget = true;
    ctrl.singlePreviewTarget = createFakeElement('div');
    ctrl.variantValue = 'compact';
    ctrl.renderSinglePreview();
    assert.doesNotMatch(ctrl.singlePreviewTarget.innerHTML, /<img/i);

    // Gallery mode with hostile JSON items
    ctrl.hasGalleryGridTarget = true;
    ctrl.galleryGridTarget = createFakeElement('div');
    ctrl.hasCountLabelTarget = false;
    ctrl.maxFilesValue = 0;
    ctrl.buttonLabelValue = 'Select';
    ctrl.removeLabelValue = 'Remove';
    ctrl.inputTarget.value = JSON.stringify([
        'javascript:alert(1).jpg',
        '/\\evil.com/a.png',
        `/uploads/${XSS_PAYLOAD}.png`,
    ]);

    for (const variant of ['compact', 'input', 'avatar']) {
        ctrl.variantValue = variant;
        ctrl.renderGallery();
        assertSafeRenderedHtml(ctrl.galleryGridTarget.innerHTML, `picker gallery ${variant}`);
        assert.doesNotMatch(ctrl.galleryGridTarget.innerHTML, /<img[^>]+(javascript:|evil\.com)/i);
    }
});

test('DialogController: enforces same-origin frameUrl, mode/position allowlists, and X-LiveUX-FM-Dialog header', async () => {
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const ctrl = new DialogController();

    // Same-origin frameUrl validation
    for (const badFrame of ['https://evil.com/dialog', '//evil.com/dialog', '/\\evil.com/dialog', 'javascript:alert(1)', '/ok\r\n']) {
        assert.equal(ctrl.isSafeSameOriginPath(badFrame), false, `frameUrl MUST reject: ${badFrame}`);
    }
    assert.equal(ctrl.isSafeSameOriginPath('/_liveux-file-manager/dialog'), true);

    // Hostile mode/position clamped + missing X-LiveUX-FM-Dialog header rejected!
    ctrl.dialogTarget = createFakeElement('dialog');
    ctrl.bodyTarget = createFakeElement('div');
    ctrl.frameUrlValue = '/_liveux-file-manager/dialog';
    ctrl.profileValue = 'default';
    ctrl.errorMessageValue = 'Blocked safely.';
    ctrl.loadedKey = null;

    globalThis.document = {
        createElement: (tag) => createFakeElement(tag),
    };
    globalThis.fetch = async () => ({
        ok: true,
        headers: new Map([['content-type', 'text/html']]),
        text: async () => '<html><body><h1>Login Page</h1><script>alert(1)</script></body></html>',
    });

    await ctrl.handleGlobalOpen({
        detail: {
            mode: '"><script>alert(1)</script>',
            position: 'evil',
            profile: 'default',
        },
    });

    assert.equal(ctrl.dialogTarget.getAttribute('data-mode'), 'modal');
    assert.equal(ctrl.dialogTarget.getAttribute('data-position'), 'end');
    assert.equal(ctrl.loadedKey, null, 'Must NOT cache or mount response lacking X-LiveUX-FM-Dialog: 1');
    assert.equal(ctrl.bodyTarget._children[0].textContent, 'Blocked safely.');
});

test('WYSIWYG Adapters (CKEditor 5, TinyMCE, Tiptap) reject all malicious URL schemes', async () => {
    const ckMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/ckeditor5_adapter.js')).href);
    const tinyMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tinymce_adapter.js')).href);
    const tipMod = await import(pathToFileURL(path.join(assetsDir, 'js/integrations/tiptap_adapter.js')).href);

    const maliciousFiles = [
        { url: 'javascript:alert(1)', name: 'xss.jpg', isImage: true },
        { url: 'data:text/html,<script>alert(1)</script>', name: 'doc.html', isImage: false },
        { url: '//evil.com/a.jpg', name: 'proto.jpg', isImage: true },
        { url: '/\\evil.com/b.jpg', name: 'slash.jpg', isImage: true },
    ];

    globalThis.window = {
        LiveUXFileManager: {
            open: async () => maliciousFiles,
        },
    };

    // 1. CKEditor 5
    let ckExecuted = 0;
    const fakeCk = {
        model: { change: () => { ckExecuted += 1; }, insertContent: () => { ckExecuted += 1; } },
        commands: { get: () => true },
        execute: () => { ckExecuted += 1; },
    };
    const ckRes = await ckMod.openFileManagerForCKEditor5(fakeCk);
    assert.equal(ckRes.length, 0);
    assert.equal(ckExecuted, 0);

    // 2. TinyMCE
    let tinyCalled = 0;
    const tinyPicker = tinyMod.createTinyMceFilePicker();
    await tinyPicker(() => { tinyCalled += 1; }, '', { filetype: 'image' });
    assert.equal(tinyCalled, 0);

    // 3. Tiptap
    let tipInserted = 0;
    const fakeTiptap = {
        chain: () => ({
            focus() { return this; },
            setImage() { tipInserted += 1; return this; },
            insertContent() { tipInserted += 1; return this; },
            run() {},
        }),
    };
    await tipMod.insertFromFileManager(fakeTiptap);
    assert.equal(tipInserted, 0);

    // Now verify valid URLs pass cleanly across all 3 adapters!
    globalThis.window.LiveUXFileManager.open = async () => [
        { url: '/uploads/valid-photo.jpg', name: 'valid-photo.jpg', isImage: true, meta: { alt: 'Safe Alt', title: 'Safe Title' } },
    ];

    let ckValidSrc = null;
    await ckMod.openFileManagerForCKEditor5({
        model: { change: () => {} },
        commands: { get: () => true },
        execute: (_cmd, args) => { ckValidSrc = args.source; },
    });
    assert.deepEqual(ckValidSrc, ['/uploads/valid-photo.jpg']);

    let tinyValid = null;
    await tinyPicker((u, m) => { tinyValid = { u, m }; }, '', { filetype: 'image' });
    assert.equal(tinyValid.u, '/uploads/valid-photo.jpg');
    assert.equal(tinyValid.m.alt, 'Safe Alt');

    let tipValid = null;
    await tipMod.insertFromFileManager({
        chain: () => ({
            focus() { return this; },
            setImage(args) { tipValid = args; return this; },
            insertContent() { return this; },
            run() {},
        }),
    });
    assert.equal(tipValid.src, '/uploads/valid-photo.jpg');
    assert.equal(tipValid.alt, 'Safe Alt');
});

test('file_manager_api.js freezes window.LiveUXFileManager against tampering', async () => {
    globalThis.window = {};
    const mod = await import(pathToFileURL(path.join(assetsDir, 'js/file_manager_api.js')).href);
    if (typeof mod.ensureFileManagerGlobal === 'function') {
        mod.ensureFileManagerGlobal();
    }
    assert.equal(Object.isFrozen(globalThis.window.LiveUXFileManager), true);
    assert.equal(typeof globalThis.window.LiveUXFileManager.open, 'function');
});

test('browser_controller.js pre-delete Usage Guard escapes hostile references and enforces block policy', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const ctrl = new BrowserController();
    ctrl.i18nValue = {
        'modals.usage_warn_title': 'Warning: %count%',
        'modals.usage_block_title': 'Blocked: %count%',
        'modals.usage_block_hint': 'Unlink first',
        'modals.usage_truncated': 'More items…',
        'modals.usage_open_record': 'Open',
        'modals.usage_none': 'Safe to delete',
        'modals.delete_anyway': 'Delete Anyway',
    };

    const hostileReport = {
        enabled: true,
        policy: 'warn',
        blocked: false,
        totalUsages: 2,
        truncated: true,
        items: [
            {
                path: `katalog/${XSS_PAYLOAD}`,
                isFolder: false,
                references: [
                    { label: XSS_PAYLOAD, type: XSS_PAYLOAD, editUrl: 'javascript:alert(1)' },
                    { label: 'Safe Product #42', type: 'Product', editUrl: '/panel/product/42/edit' },
                ],
            },
        ],
    };

    const warnHtml = ctrl.renderUsageGuardHtml(hostileReport, true, false);
    assertSafeRenderedHtml(warnHtml, 'usage guard warn report');
    assert.doesNotMatch(warnHtml, /javascript:/i, 'Unsafe editUrl must never render as link');
    assert.match(warnHtml, /href="\/panel\/product\/42\/edit"/, 'Safe relative editUrl must render as link');
    assert.match(warnHtml, /rel="noopener noreferrer"/, 'External/new-tab links must include noopener noreferrer');

    const usageSlot = createFakeElement('div');
    const confirmBtn = createFakeElement('button');
    confirmBtn.disabled = true;
    confirmBtn.hidden = false;

    // Warn policy unlocks button and updates label to "Delete Anyway"
    ctrl.applyUsageGuardReport(usageSlot, confirmBtn, hostileReport, false);
    assert.equal(confirmBtn.disabled, false);
    assert.equal(confirmBtn.hidden, false);
    assert.equal(confirmBtn.textContent, 'Delete Anyway');

    // Block policy keeps confirm button disabled & hidden
    ctrl.applyUsageGuardReport(usageSlot, confirmBtn, { ...hostileReport, policy: 'block', blocked: true }, false);
    assert.equal(confirmBtn.disabled, true);
    assert.equal(confirmBtn.hidden, true);
    assert.match(usageSlot.innerHTML, /is-block/);
});

test('browser_controller.js quota breakdown modal, folder badges, and custom UI icons escape hostile payloads', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const ctrl = new BrowserController();
    ctrl.svgSanitizeCache = new Map();
    ctrl.i18nValue = {
        'quota.used': 'Used',
        'quota.remaining': 'Remaining',
        'quota.unlimited': 'Unlimited',
        'quota.files_folders': 'Files/Folders',
        'quota.by_type': 'By type',
        'quota.top_folders': 'Top Folders',
        'quota.largest_files': 'Largest Files',
        'quota.root_folder': '/ (Root)',
        'quota.trash_usage': 'Trash',
        'quota.cat_image': 'Images',
        'quota.cat_video': 'Video',
        'quota.cat_doc': 'Docs',
        'quota.cat_archive': 'Archives',
        'quota.cat_other': 'Other',
        'quota.empty_storage': 'Empty',
        'quota.capped_notice': 'Capped',
        'actions.close': 'Close',
    };
    ctrl.iconSetValue = {
        defaultFolderColor: '#2563eb',
        icons: { folder: '<svg viewBox="0 0 24 24"><path d="M2 6h20v12H2z"/></svg>' },
        ui: {
            upload: '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><path d="M12 3v12"/></svg>',
        },
        badges: {
            bag: '<svg viewBox="0 0 24 24"><path d="M6 7h12v12H6z"/></svg>',
        },
    };

    // 1. Quota Details HTML escapes hostile folder & file names
    const quotaHtml = ctrl.renderQuotaDetailsHtml({
        summary: { enabled: true, usedFormatted: '10 MB', maxFormatted: '100M', remainingFormatted: '90 MB', percent: 10 },
        counts: { files: 2, folders: 1, trashFiles: 1, trashBytes: 100, trashFormatted: '100 B', trashPercent: 1 },
        categories: [{ key: 'image', formatted: '10 MB', count: 2, percent: 10 }],
        topFolders: [{ path: `folder/${XSS_PAYLOAD}`, formatted: '10 MB', fileCount: 2 }],
        largestFiles: [{ name: XSS_PAYLOAD, path: `folder/${XSS_PAYLOAD}`, folder: `folder/${XSS_PAYLOAD}`, formatted: '8 MB' }],
        capped: true,
    });
    assertSafeRenderedHtml(quotaHtml, 'quota details modal');

    // 2. Folder SVG fail-closes without DOMParser AND renders sanitized badge overlay when available, ignoring prototype keys
    const bagSvg = ctrl.iconSetValue.badges.bag;
    ctrl.svgSanitizeCache.set(`11::${bagSvg}`, '<svg width="11" height="11" viewBox="0 0 24 24"><path d="M6 7h12v12H6z"/></svg>');
    const badgedFolder = ctrl.renderFolderSvg(24, '#0d9488', false, 'bag');
    assert.match(badgedFolder, /liveux-fm-folder-badge/);
    const protoFolder = ctrl.renderFolderSvg(24, '#0d9488', false, 'constructor');
    assert.doesNotMatch(protoFolder, /liveux-fm-folder-badge/);

    // 3. Hostile custom UI icon fails closed without DOMParser (falls back cleanly to safe built-in SVG with zero script/onload)
    const customUploadSvg = ctrl.uiIcon('upload', 15);
    assert.match(customUploadSvg, /<svg\b/);
    assert.doesNotMatch(customUploadSvg, /<script/i);
    assert.doesNotMatch(customUploadSvg, /onload/i);

    // 4. showQuota=false hides quota box & pill even when server quota payload is enabled
    const quotaBox = createFakeElement('button');
    const quotaPill = createFakeElement('button');
    ctrl.hasQuotaBoxTarget = true;
    ctrl.quotaBoxTarget = quotaBox;
    ctrl.hasQuotaPillTarget = true;
    ctrl.quotaPillTarget = quotaPill;
    ctrl.hasSettingsQuotaBadgeTarget = false;
    ctrl.capabilitiesValue = { showQuota: false, showQuotaDetails: false, download: false };
    ctrl.renderQuota({ enabled: true, percent: 50, usedFormatted: '50 MB', maxFormatted: '100M' });
    assert.equal(quotaBox.classList.contains('liveux-fm-hidden'), true, 'Quota box must stay hidden when showQuota=false');
    assert.equal(quotaPill.classList.contains('liveux-fm-hidden'), true, 'Quota pill must stay hidden when showQuota=false');

    // 5. openQuotaDetailsModal is a strict no-op when showQuotaDetails=false
    let modalOpened = false;
    ctrl.openModal = () => { modalOpened = true; };
    await ctrl.openQuotaDetailsModal();
    assert.equal(modalOpened, false, 'openQuotaDetailsModal must not open modal when showQuotaDetails=false');

    // 6. copyUrl is omitted from context menu when capabilities.download=false
    const menuEl = createFakeElement('div');
    ctrl.hasContextMenuTarget = true;
    ctrl.contextMenuTarget = menuEl;
    ctrl.hasSettingsPopoverTarget = false;
    ctrl.volumeValue = 'local';
    ctrl.pathValue = '';
    ctrl.selectedMap = new Map();
    ctrl.dirCache = new Map();
    globalThis.window = { innerWidth: 1280, innerHeight: 800 };
    const noDlCard = createFakeElement('div');
    noDlCard.dataset = { ctxKind: 'file', path: 'doc.pdf', name: 'doc.pdf', url: '/uploads/doc.pdf', extension: 'pdf' };
    ctrl.showContextMenuForElement(noDlCard, 10, 10);
    assert.doesNotMatch(menuEl.innerHTML, /data-ctx="copyUrl"/, 'copyUrl must be hidden when download=false');

    // 7. sanitizeInlineSvg uses a namespace-checked ALLOWLIST (denylists miss XHTML 'breakout' tags / CDATA mXSS).
    const browserSource = fs.readFileSync(path.join(assetsDir, 'controllers/browser_controller.js'), 'utf8');
    const allowMatch = browserSource.match(/const allowedTags = new Set\(\[([^\]]*)\]\)/);
    assert.ok(allowMatch, 'sanitizeInlineSvg must define an allowedTags allowlist');
    for (const tag of ['script', 'foreignobject', 'feimage', 'handler', 'listener', 'animatecolor', 'form', 'input', 'applet', 'style', 'use', 'set', 'animate', 'p', 'img', 'div', 'iframe']) {
        assert.doesNotMatch(allowMatch[1], new RegExp(`'${tag}'`, 'i'), `<${tag}> must NOT be allowlisted`);
    }
    assert.match(browserSource, /el\.namespaceURI !== SVG_NS/, 'non-SVG-namespace elements must be removed');
    assert.match(browserSource, /node\.nodeType !== 1 && node\.nodeType !== 3/, 'CDATA / comments / PIs must be stripped');

    // 8. Inspector renders safe inline <video>, keeps PDF clean (no narrow sidebar iframe), and honors allowPdfPreview/allowVideoPreview
    const inspEl = createFakeElement('aside');
    ctrl.hasInspectorTarget = true;
    ctrl.inspectorTarget = inspEl;
    ctrl.inspectorModeValue = 'always';
    ctrl.hasFooterStatusTarget = false;
    ctrl.localeValue = 'en';
    ctrl.capabilitiesValue = { download: true, share: true, allowPdfPreview: true, allowVideoPreview: true };
    const safeVideo = { kind: 'file', name: 'promo.mp4', path: 'promo.mp4', extension: 'mp4', isImage: false, url: '/uploads/promo.mp4', downloadUrl: '/_liveux-file-manager/download?path=promo.mp4' };
    const safePdf = { kind: 'file', name: 'catalog.pdf', path: 'catalog.pdf', extension: 'pdf', isImage: false, url: '/uploads/catalog.pdf', downloadUrl: '/_liveux-file-manager/download?path=catalog.pdf' };
    const badVideo = { kind: 'file', name: 'evil.mp4', path: 'evil.mp4', extension: 'mp4', isImage: false, url: 'javascript:alert(1)', downloadUrl: '' };
    ctrl.dirCache.set(ctrl.cacheKey(ctrl.volumeValue, ctrl.pathValue), [safeVideo, safePdf, badVideo]);

    ctrl.selectedMap = new Map([['promo.mp4', safeVideo]]);
    ctrl.renderInspector();
    assertSafeRenderedHtml(inspEl.innerHTML, 'inspector video preview');
    assert.match(inspEl.innerHTML, /<video\s+src="\/uploads\/promo\.mp4#t=0\.5"/);

    ctrl.selectedMap = new Map([['catalog.pdf', safePdf]]);
    ctrl.renderInspector();
    assertSafeRenderedHtml(inspEl.innerHTML, 'inspector pdf icon stage');
    assert.doesNotMatch(inspEl.innerHTML, /<iframe\b/i, 'Right inspector must NOT embed a cramped mini PDF iframe');
    assert.match(inspEl.innerHTML, /data-inspector-preview/, 'PDF inspector stage remains clickable to open Lightbox when allowPdfPreview=true');

    ctrl.selectedMap = new Map([['evil.mp4', badVideo]]);
    ctrl.renderInspector();
    assertSafeRenderedHtml(inspEl.innerHTML, 'inspector unsafe video');
    assert.doesNotMatch(inspEl.innerHTML, /<video\b/i);

    // 8b. Config toggles allowPdfPreview=false and allowVideoPreview=false disable preview kinds & inline video player
    ctrl.capabilitiesValue = { download: true, share: true, allowPdfPreview: false, allowVideoPreview: false };
    assert.equal(ctrl.getPreviewKind(safePdf), null, 'PDF preview disabled when allowPdfPreview=false');
    assert.equal(ctrl.getPreviewKind(safeVideo), null, 'Video preview disabled when allowVideoPreview=false');
    ctrl.selectedMap = new Map([['promo.mp4', safeVideo]]);
    ctrl.renderInspector();
    assert.doesNotMatch(inspEl.innerHTML, /<video\b/i, 'Inspector must not render <video> when allowVideoPreview=false');
    assert.doesNotMatch(inspEl.innerHTML, /data-inspector-preview/, 'Inspector must not render preview trigger when allowVideoPreview=false');

    // 9. Inline double-click opens Lightbox preview for previewable Video/PDF files
    ctrl.capabilitiesValue = { download: true, share: true, allowPdfPreview: true, allowVideoPreview: true };
    let previewedItem = null;
    ctrl.element = createFakeElement('div');
    ctrl.selectFile = () => {};
    ctrl.openPreview = (item) => { previewedItem = item; };
    ctrl.doubleClickFile({ currentTarget: { dataset: { path: 'promo.mp4' } } });
    assert.equal(previewedItem?.path, 'promo.mp4', 'Inline double-click on video must open Lightbox preview');

    // 10. Upload Queue renders Pause / Resume / Cancel controls and aborts in-flight fetch cleanly
    const queueEl = createFakeElement('div');
    ctrl.hasUploadQueueTarget = true;
    ctrl.uploadQueueTarget = queueEl;
    ctrl.hasModalBodyTarget = false;
    ctrl.i18nValue = {
        ...ctrl.i18nValue,
        'actions.pause': 'Pause',
        'actions.resume': 'Resume',
        'actions.cancel': 'Cancel',
        'actions.retry': 'Retry',
        'actions.pause_all': 'Pause All',
        'actions.cancel_all': 'Cancel All',
        'messages.upload_paused': 'Paused',
    };
    ctrl.actionUrlValue = '/_liveux-file-manager/api/action';
    const abortCalls = [];
    ctrl.callAction = async (action, extra) => {
        abortCalls.push({ action, ...extra });
        return true;
    };
    let abortedSignalCount = 0;
    const fakeAbortCtrl = { abort: () => { abortedSignalCount += 1; } };
    ctrl.uploadJobs = [
        {
            id: 'up_chunk_123456',
            file: { name: 'big-video.mp4' },
            targetDir: '',
            chunkIndex: 1,
            totalChunks: 4,
            status: 'uploading',
            retryCount: 0,
            retrying: false,
            paused: false,
            cancelled: false,
            abortController: fakeAbortCtrl,
            error: null,
            preFlightError: false,
        },
        {
            id: 'up_single_123456',
            file: { name: 'photo.jpg' },
            targetDir: '',
            chunkIndex: 0,
            totalChunks: 1,
            status: 'uploading',
            retryCount: 0,
            retrying: false,
            paused: false,
            cancelled: false,
            abortController: null,
            error: null,
            preFlightError: false,
        },
    ];
    ctrl.renderUploadQueue();
    assertSafeRenderedHtml(queueEl.innerHTML, 'upload queue with pause/cancel controls');
    assert.match(queueEl.innerHTML, /data-jobs-pause-all/, 'Multi-file queue shows Pause All');
    assert.match(queueEl.innerHTML, /data-jobs-cancel-all/, 'Multi-file queue shows Cancel All');
    assert.match(queueEl.innerHTML, /data-job-pause="up_chunk_123456"/, 'Chunked upload shows Pause button');
    assert.match(queueEl.innerHTML, /data-job-cancel="up_chunk_123456"/, 'Chunked upload shows Cancel button');
    assert.match(queueEl.innerHTML, /data-job-cancel="up_single_123456"/, 'Normal single upload shows Cancel button');

    ctrl.pauseUploadJob('up_chunk_123456');
    assert.equal(abortedSignalCount, 1, 'Pause aborts in-flight chunk fetch');
    assert.equal(ctrl.uploadJobs[0].paused, true);
    assert.equal(ctrl.uploadJobs[0].chunkIndex, 1, 'Pause preserves exact chunkIndex');
    assert.match(queueEl.innerHTML, /data-job-resume="up_chunk_123456"/, 'Paused job switches to Resume button');

    ctrl.cancelUploadJob('up_chunk_123456');
    assert.equal(ctrl.uploadJobs.length, 1, 'Cancelled job removed from queue');
    assert.deepEqual(abortCalls, [{ action: 'abortUpload', uploadId: 'up_chunk_123456' }], 'Cancelling partial chunked upload notifies server abortUpload');

    // 10b. Chunk progress tick patches bar & status in-place without rebuilding innerHTML (prevents dropped clicks!)
    const barEl = createFakeElement('div');
    const statusEl = createFakeElement('span');
    const rowEl = createFakeElement('div');
    rowEl.dataset = { jobRow: 'up_patch_123456' };
    rowEl.querySelector = (sel) => (sel === '[data-job-bar]' ? barEl : (sel === '[data-job-status]' ? statusEl : null));
    let innerHtmlWrites = 0;
    const patchQueue = createFakeElement('div');
    let rawHtml = '';
    Object.defineProperty(patchQueue, 'innerHTML', {
        get: () => rawHtml,
        set: (v) => { innerHtmlWrites += 1; rawHtml = v; },
    });
    patchQueue.querySelectorAll = (sel) => (sel === '[data-job-row]' && rawHtml ? [rowEl] : []);
    ctrl.uploadQueueTarget = patchQueue;
    ctrl.uploadJobs = [{
        id: 'up_patch_123456',
        file: { name: 'stream.mp4' },
        targetDir: '',
        chunkIndex: 0,
        totalChunks: 4,
        status: 'uploading',
        retryCount: 0,
        retrying: false,
        paused: false,
        cancelled: false,
        abortController: null,
        error: null,
        preFlightError: false,
    }];
    ctrl.renderUploadQueue();
    assert.equal(innerHtmlWrites, 1, 'First render populates innerHTML once');
    ctrl.uploadJobs[0].chunkIndex = 2;
    ctrl.renderUploadQueue();
    assert.equal(innerHtmlWrites, 1, 'Chunk progress tick must NOT overwrite innerHTML');
    assert.equal(barEl.style.width, '50%', 'Progress bar width patched in-place');
    assert.equal(statusEl.textContent, '3/4 • 50%', 'Status text patched in-place');

    // 10c. Cancelling an active upload opens confirmation modal with escaped filename; error rows dismiss immediately
    delete ctrl.openModal;
    const confirmBackdrop = createFakeElement('div');
    const confirmTitle = createFakeElement('h6');
    const confirmBody = createFakeElement('div');
    ctrl.hasModalBackdropTarget = true;
    ctrl.modalBackdropTarget = confirmBackdrop;
    ctrl.modalTitleTarget = confirmTitle;
    ctrl.hasModalBodyTarget = true;
    ctrl.modalBodyTarget = confirmBody;
    ctrl.i18nValue = {
        ...ctrl.i18nValue,
        'modals.cancel_upload_title': 'Cancel Upload',
        'modals.cancel_upload_single': 'Cancel "%name%"?',
        'modals.cancel_upload_all': 'Cancel %count% uploads?',
        'actions.confirm_cancel_upload': 'Yes, Cancel',
        'actions.keep_uploading': 'Continue Uploading',
    };
    ctrl.uploadJobs = [
        {
            id: 'up_xss_confirm',
            file: { name: XSS_PAYLOAD },
            targetDir: '',
            chunkIndex: 1,
            totalChunks: 4,
            status: 'uploading',
            retryCount: 0,
            retrying: false,
            paused: false,
            cancelled: false,
            abortController: null,
            error: null,
            preFlightError: false,
        },
        {
            id: 'up_err_dismiss',
            file: { name: 'failed.bin' },
            targetDir: '',
            chunkIndex: 0,
            totalChunks: 1,
            status: 'error',
            retryCount: 0,
            retrying: false,
            paused: false,
            cancelled: false,
            abortController: null,
            error: 'Too large',
            preFlightError: true,
        },
    ];
    ctrl.confirmCancelUploadJob('up_xss_confirm');
    assert.equal(ctrl.uploadJobs.length, 2, 'Active job must NOT be cancelled before user confirms modal');
    assertSafeRenderedHtml(confirmBody.innerHTML, 'cancel upload confirmation modal');
    assert.match(confirmBody.innerHTML, /data-fm-confirm-cancel-upload/);
    assert.match(confirmBody.innerHTML, /data-fm-keep-upload/);

    ctrl.confirmCancelUploadJob('up_err_dismiss');
    assert.equal(ctrl.uploadJobs.length, 1, 'Error row dismisses immediately without confirmation modal');
});

test('browser_controller.js right-click context menu on multi-selection (folder + files) deletes and moves ALL selected items', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const ctrl = new BrowserController();
    ctrl.svgSanitizeCache = new Map();
    ctrl.capabilitiesValue = {
        move: true,
        copy: true,
        delete: true,
        rename: true,
        download: true,
        downloadZip: true,
        editMetadata: true,
        createFolder: true,
        upload: true,
    };
    ctrl.zipUrlValue = '/_liveux-file-manager/zip';
    ctrl.iconSetValue = { defaultFolderColor: '#2563eb', icons: {}, ui: {}, badges: {} };
    ctrl.i18nValue = {
        'inspector.selected_count': 'items selected',
        'actions.cut': 'Cut',
        'actions.copy_verb': 'Copy',
        'actions.delete': 'Delete',
        'actions.move': 'Move to Folder',
        'actions.download_zip': 'Download ZIP',
        'actions.open': 'Open',
        'actions.rename': 'Rename',
    };

    const contentArea = createFakeElement('div');
    const folderEl = createFakeElement('div');
    folderEl.dataset = { ctxKind: 'folder', path: 'klasor-a', name: 'klasor-a' };
    const unselectedFileEl = createFakeElement('div');
    unselectedFileEl.dataset = { ctxKind: 'file', path: 'other.txt', name: 'other.txt', url: '/uploads/other.txt', downloadUrl: '/dl/other.txt', extension: 'txt', isImage: '0' };
    contentArea.contains = (node) => node === folderEl || node === unselectedFileEl;

    const menuListeners = [];
    const menuEl = createFakeElement('div');
    menuEl.querySelectorAll = (selector) => {
        if (selector !== '[data-ctx]') {
            return [];
        }
        const matches = [...menuEl.innerHTML.matchAll(/data-ctx="([^"]+)"/g)];
        return matches.map((m) => {
            const btn = {
                dataset: { ctx: m[1] },
                addEventListener: (ev, fn) => {
                    if (ev === 'click') {
                        menuListeners.push({ action: m[1], fn });
                    }
                },
            };
            return btn;
        });
    };

    ctrl.hasContentAreaTarget = true;
    ctrl.contentAreaTarget = contentArea;
    ctrl.hasContextMenuTarget = true;
    ctrl.contextMenuTarget = menuEl;
    ctrl.hasSettingsPopoverTarget = false;
    ctrl.positionAndOpenContextMenu = () => {};
    ctrl.hideContextMenu = () => {};

    let deletedArgs = null;
    ctrl.openDeleteModal = (paths, label) => {
        deletedArgs = { paths, label };
    };
    let bulkMoveCalled = false;
    ctrl.bulkMove = () => {
        bulkMoveCalled = true;
    };

    // Select 1 folder + 2 files together
    ctrl.selectedMap = new Map([
        ['klasor-a', { kind: 'folder', path: 'klasor-a', name: 'klasor-a' }],
        ['fatura.pdf', { kind: 'file', path: 'fatura.pdf', name: 'fatura.pdf' }],
        ['logo.png', { kind: 'file', path: 'logo.png', name: 'logo.png' }],
    ]);

    // 1. Right-click on a selected folder shows multi-select menu and deletes ALL 3 items
    ctrl.showContextMenuForElement(folderEl, 100, 100);
    assert.match(menuEl.innerHTML, /3 items selected/);
    assert.doesNotMatch(menuEl.innerHTML, /data-ctx="rename"/, 'Single-item rename must be hidden in multi-select menu');
    assert.match(menuEl.innerHTML, /data-ctx="downloadZip"/);

    const deleteHandler = menuListeners.find((h) => h.action === 'delete');
    assert.ok(deleteHandler, 'Multi-select context menu must wire delete handler');
    await deleteHandler.fn({ stopPropagation() {} });
    assert.deepEqual(deletedArgs.paths, ['klasor-a', 'fatura.pdf', 'logo.png']);

    const moveHandler = menuListeners.find((h) => h.action === 'move');
    assert.ok(moveHandler);
    await moveHandler.fn({ stopPropagation() {} });
    assert.equal(bulkMoveCalled, true, 'Multi-select move must invoke bulkMove()');

    // 2. Right-click on an UNSELECTED file only deletes that single file
    menuListeners.length = 0;
    deletedArgs = null;
    ctrl.showContextMenuForElement(unselectedFileEl, 120, 120);
    assert.match(menuEl.innerHTML, /other\.txt/);
    const singleDeleteHandler = menuListeners.find((h) => h.action === 'delete');
    assert.ok(singleDeleteHandler);
    await singleDeleteHandler.fn({ stopPropagation() {} });
    assert.deepEqual(deletedArgs.paths, ['other.txt']);
});

test('picker_controller.js stays synced across Symfony UX LiveComponent / LiveCollectionType re-renders and dispatches input+change', async () => {
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const ctrl = new PickerController();

    const emittedEvents = [];
    const inputEl = createFakeElement('input');
    inputEl.value = '["/uploads/first.jpg"]';
    inputEl.dispatchEvent = (ev) => {
        emittedEvents.push(ev.type);
        return true;
    };
    const gridEl = createFakeElement('div');
    const countEl = createFakeElement('span');

    let renderFinishedCb = null;
    const fakeLiveRoot = createFakeElement('div');
    fakeLiveRoot.__component = {
        on(hook, cb) {
            if (hook === 'render:finished') {
                renderFinishedCb = cb;
            }
        },
        off() {},
    };

    const rootEl = createFakeElement('div');
    rootEl.closest = (selector) => (selector === '[data-controller~="live"]' ? fakeLiveRoot : null);

    ctrl.element = rootEl;
    ctrl.multipleValue = true;
    ctrl.variantValue = 'compact';
    ctrl.maxFilesValue = 5;
    ctrl.buttonLabelValue = 'Add';
    ctrl.removeLabelValue = 'Remove';
    ctrl.hasInputTarget = true;
    ctrl.inputTarget = inputEl;
    ctrl.hasGalleryGridTarget = true;
    ctrl.galleryGridTarget = gridEl;
    ctrl.hasCountLabelTarget = true;
    ctrl.countLabelTarget = countEl;

    ctrl.connect();
    assert.match(gridEl.innerHTML, /first\.jpg/);
    assert.equal(countEl.textContent, '1');
    assert.equal(typeof renderFinishedCb, 'function', 'Must subscribe to LiveComponent render:finished hook');

    // Updating gallery values dispatches BOTH input and change for LiveComponent & CollectionType compatibility
    ctrl.writeGalleryValues(['/uploads/first.jpg', '/uploads/second.webp']);
    assert.deepEqual(emittedEvents, ['input', 'change']);

    // Simulate Idiomorph wiping galleryGrid and morphing input value after deleting row 0 in LiveCollectionType
    gridEl.innerHTML = '';
    inputEl.value = '["/uploads/second.webp"]';
    renderFinishedCb();
    await new Promise((resolve) => queueMicrotask(resolve));

    assert.match(gridEl.innerHTML, /second\.webp/, 'Picker must automatically re-render gallery after LiveComponent morph');
    assert.equal(countEl.textContent, '1');

    ctrl.disconnect();
});

test('picker_controller.js MutationObserver drains own records and NEVER enters an infinite re-render loop (Firefox freeze guard)', async () => {
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const prevObserver = globalThis.MutationObserver;

    let observedCallbacks = [];
    let pendingRecords = [];
    globalThis.MutationObserver = class FakeMutationObserver {
        constructor(cb) {
            this.cb = cb;
            observedCallbacks.push(this);
        }
        observe() {}
        disconnect() {
            pendingRecords = [];
        }
        takeRecords() {
            const drained = pendingRecords;
            pendingRecords = [];
            return drained;
        }
    };

    try {
        const ctrl = new PickerController();
        const inputEl = createFakeElement('input');
        inputEl.value = '/uploads/banner.webp';
        const previewEl = createFakeElement('div');
        let innerHtmlWrites = 0;
        let _html = '';
        Object.defineProperty(previewEl, 'innerHTML', {
            get: () => _html,
            set: (val) => {
                innerHtmlWrites += 1;
                _html = val;
                // Simulate browser queuing a MutationRecord on innerHTML write
                pendingRecords.push({ type: 'childList', target: previewEl });
                queueMicrotask(() => {
                    if (pendingRecords.length > 0) {
                        const batch = pendingRecords;
                        pendingRecords = [];
                        observedCallbacks.forEach((o) => o.cb(batch, o));
                    }
                });
            },
        });

        ctrl.element = createFakeElement('div');
        ctrl.multipleValue = false;
        ctrl.variantValue = 'compact';
        ctrl.placeholderValue = 'None';
        ctrl.hasInputTarget = true;
        ctrl.inputTarget = inputEl;
        ctrl.hasSinglePreviewTarget = true;
        ctrl.singlePreviewTarget = previewEl;
        ctrl.hasClearBtnTarget = false;
        ctrl.hasFileLabelTarget = false;

        ctrl.connect();
        // Flush several microtask turns to confirm zero infinite loop
        for (let i = 0; i < 6; i += 1) {
            await new Promise((r) => queueMicrotask(r));
        }
        assert.equal(innerHtmlWrites, 1, 'Self innerHTML mutation must NOT re-trigger MutationObserver loop');

        // Simulate Idiomorph resetting previewEl (removing dataset.fmSynced and replacing HTML)
        delete previewEl.dataset.fmSynced;
        _html = '<span>placeholder</span>';
        pendingRecords.push({ type: 'childList', target: previewEl });
        observedCallbacks.forEach((o) => o.cb(pendingRecords, o));
        pendingRecords = [];
        for (let i = 0; i < 4; i += 1) {
            await new Promise((r) => queueMicrotask(r));
        }
        assert.equal(innerHtmlWrites, 2, 'External morph wiping preview triggers exactly ONE re-render');
        assert.match(previewEl.innerHTML, /banner\.webp/);

        ctrl.disconnect();
    } finally {
        globalThis.MutationObserver = prevObserver;
    }
});

test('Audit regressions: formatDate in List View, confirmSelection metadata, Dialog close resolve & cacheKey, Picker private download URLs', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');

    // 1. BrowserController.renderListItem() with lastModified must NOT throw TypeError
    const b = new BrowserController();
    b.localeValue = 'en';
    b.volumeValue = 'local';
    b.pathValue = '';
    b.thumbFitValue = 'contain';
    b.thumbnailsEnabledValue = true;
    b.capabilitiesValue = { allowDragMove: true, allowContextMenu: true, showFolderItemCount: true };
    b.iconSetValue = { defaultFolderColor: '#2563eb', icons: {}, ui: {}, badges: {} };
    b.i18nValue = {};
    b.activeFolderStyle = 'card';
    b.dirCache = new Map();
    b.selectedMap = new Map();

    const listRow = b.renderListItem({
        kind: 'file',
        name: 'catalog.pdf',
        path: 'catalog.pdf',
        size: 2048,
        sizeFormatted: '2.0 KB',
        extension: 'pdf',
        isImage: false,
        lastModified: 1732000000,
        url: '/uploads/catalog.pdf',
        downloadUrl: '/_liveux-file-manager/download?path=catalog.pdf',
    });
    assert.match(listRow, /catalog\.pdf/);
    assert.doesNotMatch(listRow, />—<\/span>\s*<span class="liveux-fm-list-col-act">/, 'Valid lastModified must format as date, not em-dash');

    // 2. folder_style=card uses inline count markup (not absolute tile badge)
    const cardFolder = b.renderGridItem({ kind: 'folder', name: 'Assets', path: 'Assets', itemCount: 7 });
    assert.doesNotMatch(cardFolder, /liveux-fm-folder-count/, 'Card folder must render inline count instead of absolute tile badge');

    // 3. confirmSelection() includes isImage and extension from full item cache
    let selectedPayload = null;
    b.element = createFakeElement('div');
    b.element.dispatchEvent = (ev) => { selectedPayload = ev.detail; return true; };
    b.dirCache.set(b.cacheKey('local', ''), [
        { kind: 'file', path: 'hero.webp', name: 'hero.webp', isImage: true, extension: 'webp', url: '/uploads/hero.webp', meta: { alt: 'Hero' } },
    ]);
    b.selectedMap.set('hero.webp', { kind: 'file', path: 'hero.webp', name: 'hero.webp', url: '/uploads/hero.webp' });
    b.confirmSelection();
    assert.equal(selectedPayload.files[0].isImage, true);
    assert.equal(selectedPayload.files[0].extension, 'webp');
    assert.equal(selectedPayload.files[0].meta.alt, 'Hero');

    // 4. DialogController resolves Promise on native close event & caches by volume+path
    const d = new DialogController();
    d.dialogTarget = createFakeElement('dialog');
    d.bodyTarget = createFakeElement('div');
    d.hasDialogTarget = true;
    d.hasBodyTarget = true;
    d.frameUrlValue = '/_liveux-file-manager/dialog';
    d.profileValue = 'default';
    d.loadedKey = null;
    d.isDisconnected = false;

    const fetchedUrls = [];
    globalThis.DOMParser = class FakeDomParser {
        parseFromString() {
            return { body: { querySelectorAll: () => [], firstChild: null } };
        }
    };
    globalThis.document = {
        createElement: (tag) => createFakeElement(tag),
        createDocumentFragment: () => ({ appendChild() {} }),
    };
    globalThis.fetch = async (url) => {
        fetchedUrls.push(String(url));
        return {
            ok: true,
            headers: new Map([['content-type', 'text/html'], ['x-liveux-fm-dialog', '1']]),
            text: async () => '<div>ok</div>',
        };
    };

    let resolved1 = null;
    await d.handleGlobalOpen({ detail: { profile: 'default', volume: 'volA', path: 'dir1', resolve: (v) => { resolved1 = v; } } });
    d.onDialogClose();
    assert.deepEqual(resolved1, [], 'Native Escape/close must resolve pending Promise with []');

    await d.handleGlobalOpen({ detail: { profile: 'default', volume: 'volB', path: 'dir2', resolve: () => {} } });
    assert.equal(fetchedUrls.length, 2, 'Different volume/path MUST NOT reuse stale cached dialog frame');

    // 5. PickerController resolves filename, extension & image preview for private download URLs
    const p = new PickerController();
    const privateUrl = '/_liveux-file-manager/download?profile=default&volume=priv&path=products%2Fbanner.webp&inline=1';
    assert.equal(p.extractFilename(privateUrl), 'banner.webp');
    assert.equal(p.extractExtension(privateUrl), 'WEBP');
    assert.equal(p.isImageValue(privateUrl), true);

    p.inputTarget = createFakeElement('input');
    p.inputTarget.value = privateUrl;
    p.hasSinglePreviewTarget = true;
    p.singlePreviewTarget = createFakeElement('div');
    p.hasClearBtnTarget = true;
    p.clearBtnTarget = createFakeElement('button');
    p.hasFileLabelTarget = true;
    p.fileLabelTarget = createFakeElement('div');
    p.variantValue = 'compact';
    p.previewValue = true;
    p.renderSinglePreview();
    assert.match(p.singlePreviewTarget.innerHTML, /<img\s+src="/, 'Private download URL pointing to .webp must render <img> preview');
    assert.equal(p.fileLabelTarget.textContent, 'banner.webp');

    p.previewValue = false;
    p.renderSinglePreview();
    assert.doesNotMatch(p.singlePreviewTarget.innerHTML, /<img\b/, 'previewValue=false must suppress <img> rendering');
});

test('Second-pass audit regressions: single-select picker cap, trash exit refetch, GIF overwrite disable, volume snapshot, Escape & disabled picker', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');

    // 1. BrowserController single-select modal picker enforces max 1 selection and disables confirmSelectionBtn when empty
    const b = new BrowserController();
    b.modeValue = 'modal';
    b.multipleValue = false;
    b.volumeValue = 'local';
    b.pathValue = '';
    b.dirCache = new Map();
    b.selectedMap = new Map();
    b.element = createFakeElement('div');
    const confirmBtn = createFakeElement('button');
    confirmBtn.disabled = true;
    b.hasConfirmSelectionBtnTarget = true;
    b.confirmSelectionBtnTarget = confirmBtn;
    b.hasBulkBarTarget = false;
    b.hasClipboardBarTarget = false;
    b.hasInspectorTarget = false;
    b.hasFooterStatusTarget = false;

    const cardA = createFakeElement('div');
    cardA.dataset = { path: 'a.jpg', name: 'a.jpg', url: '/uploads/a.jpg' };
    const cardB = createFakeElement('div');
    cardB.dataset = { path: 'b.jpg', name: 'b.jpg', url: '/uploads/b.jpg' };

    b.selectFile({ target: cardA, currentTarget: cardA, ctrlKey: false, metaKey: false, shiftKey: false });
    assert.equal(b.selectedMap.size, 1);
    assert.equal(confirmBtn.disabled, false, 'Confirm button enables once 1 file is selected');

    // Ctrl+Click in single-select modal replaces instead of adding a second file
    b.selectFile({ target: cardB, currentTarget: cardB, ctrlKey: true, metaKey: false, shiftKey: false });
    assert.equal(b.selectedMap.size, 1, 'Single-select modal must keep exactly 1 selected file even with Ctrl+Click');
    assert.equal(b.selectedMap.has('b.jpg'), true);

    // Even if selectedMap somehow holds 2 files, confirmSelection caps to 1 in single-select modal
    b.selectedMap.set('a.jpg', { kind: 'file', path: 'a.jpg', name: 'a.jpg', url: '/uploads/a.jpg' });
    let emittedFiles = null;
    b.element.dispatchEvent = (ev) => { emittedFiles = ev.detail.files; return true; };
    b.confirmSelection();
    assert.equal(emittedFiles.length, 1, 'confirmSelection must emit at most 1 file when multiple=false');

    // 2. doubleClickFile ignores clicks originating on a card button or checkbox input
    let dblSelected = false;
    b.selectFile = () => { dblSelected = true; };
    const innerBtn = createFakeElement('button');
    innerBtn.closest = (sel) => (sel.includes('button') ? innerBtn : null);
    b.doubleClickFile({ target: innerBtn, currentTarget: cardA });
    assert.equal(dblSelected, false, 'doubleClickFile must ignore button/input targets');

    // 3. Exiting Trash refetches directory when dirCache was invalidated (e.g., after restoreTrash)
    let fetchedOnTrashExit = null;
    b.trashMode = true;
    b.listUrlValue = '/_liveux-file-manager/api/list';
    b.dirCache.clear();
    b.fetchDirectory = async (vol, p) => { fetchedOnTrashExit = { vol, p }; return true; };
    b.hasTrashBtnTarget = false;
    b.hasSidebarTrashBtnTarget = false;
    b.hasTreeTarget = false;
    b.hasPinnedFoldersTarget = false;
    b.hasNewFolderBtnTarget = false;
    b.hasUploadBtnTarget = false;
    b.hasSortBySelectTarget = false;
    b.hasSortDirBtnTarget = false;
    await b.toggleTrash();
    assert.deepEqual(fetchedOnTrashExit, { vol: 'local', p: '' }, 'Exiting Trash after cache clear must refetch directory');

    // 4. applyClientView prunes selectedMap against filtered items and calls syncSelectionUi on empty result
    const b2 = new BrowserController();
    b2.volumeValue = 'local';
    b2.pathValue = '';
    b2.trashMode = false;
    b2.searchQuery = 'nonexistent';
    b2.typeFilterValue = 'all';
    b2.sortByValue = 'name';
    b2.sortDirValue = 'asc';
    b2.perPageValue = 24;
    b2.pageValue = 1;
    b2.capabilitiesValue = {};
    b2.i18nValue = { 'empty.filtered': 'None', 'actions.clear_filters': 'Clear' };
    b2.hasContentAreaTarget = true;
    b2.contentAreaTarget = createFakeElement('div');
    b2.element = createFakeElement('div');
    b2.dirCache = new Map([['local::', [{ kind: 'file', path: 'a.jpg', name: 'a.jpg', category: 'image' }]]]);
    b2.selectedMap = new Map([['a.jpg', { kind: 'file', path: 'a.jpg', name: 'a.jpg' }]]);
    b2.hasBackBtnTarget = false;
    b2.hasForwardBtnTarget = false;
    b2.hasUpBtnTarget = false;
    b2.hasTotalCountTarget = false;
    b2.hasPerPageSelectTarget = false;
    b2.hasPaginationBarTarget = false;
    b2.hasPagerGroupTarget = false;
    b2.hasBulkBarTarget = false;
    b2.hasClipboardBarTarget = false;
    b2.hasInspectorTarget = false;
    b2.hasFooterStatusTarget = false;
    let syncCalled = false;
    const origSync = b2.syncSelectionUi.bind(b2);
    b2.syncSelectionUi = (...args) => { syncCalled = true; return origSync(...args); };
    b2.applyClientView();
    assert.equal(b2.selectedMap.size, 0, 'Hidden filtered-out items must be pruned from selectedMap');
    assert.equal(syncCalled, true, 'syncSelectionUi must run even when pageItems is empty');

    // 5. Escape closes inner modal even when keyboardShortcutsValue === false
    b2.keyboardShortcutsValue = false;
    b2.isFileManagerActive = () => true;
    b2.hasModalBackdropTarget = true;
    b2.modalBackdropTarget = createFakeElement('div');
    b2.modalBackdropTarget.classList.add('is-open');
    b2.hasContextMenuTarget = false;
    b2.hasLightboxTarget = false;
    b2.hasSettingsPopoverTarget = false;
    let escPrevented = false;
    b2.onKeydown({ key: 'Escape', preventDefault: () => { escPrevented = true; } });
    assert.equal(escPrevented, true);
    assert.equal(b2.modalBackdropTarget.classList.contains('is-open'), false, 'Escape must close inner modals regardless of keyboardShortcutsValue');

    // 6. Upload job snapshots target volume at enqueue time
    b2.uploadJobs = [];
    b2.uploadMaxSizeValue = 0;
    b2.uploadAllowValue = [];
    b2.uploadDenyValue = [];
    b2.imageConstraintsValue = {};
    b2.chunkEnabledValue = false;
    b2.hasUploadQueueTarget = false;
    b2.hasModalBodyTarget = false;
    b2.pumpUploadQueue = () => {};
    b2.volumeValue = 's3_bucket';
    await b2.enqueueFiles([{ name: 'doc.pdf', size: 100, type: 'application/pdf' }], 'invoices', 's3_bucket');
    b2.volumeValue = 'local';
    assert.equal(b2.uploadJobs[0].volume, 's3_bucket', 'Upload job must preserve snapshotted volume even after user switches volumes');

    // 7. Image editor disables Overwrite for .gif images
    const bImg = new BrowserController();
    bImg.hasModalBackdropTarget = true;
    bImg.modalBackdropTarget = createFakeElement('div');
    bImg.modalTitleTarget = createFakeElement('h6');
    bImg.modalBodyTarget = createFakeElement('div');
    bImg.i18nValue = { 'modals.edit_image_title': 'Edit', 'errors.overwrite_format_mismatch': 'Mismatch', 'ui.auto_placeholder': 'auto' };
    globalThis.document = {
        createElement: (tag) => ({
            ...createFakeElement(tag),
            toDataURL: () => 'data:image/png;base64,',
        }),
    };
    globalThis.Image = class FakeImg { set src(v) { this._src = v; } };
    bImg.openImageEditorModal('anim.gif', 'anim.gif', '/uploads/anim.gif');
    assert.equal(bImg.modalBodyTarget.innerHTML, '', 'Unsupported non-editable formats (.gif/.svg/.ico) must be blocked by openImageEditorModal guard');

    // 8. DialogController applies zIndexValue via CSS custom property, resolves filetype, and honors cancelable dialog-cancel
    const d = new DialogController();
    const cssProps = new Map();
    d.element = createFakeElement('div');
    d.element.style = { setProperty: (k, v) => cssProps.set(k, v) };
    d.zIndexValue = 2048;
    globalThis.window = { addEventListener: () => {}, removeEventListener: () => {} };
    d.connect();
    assert.equal(cssProps.get('--liveux-fm-z-base'), '2048', 'DialogController must apply zIndexValue to CSS var without inline style');
    assert.equal(d.resolveTypeFilter('', 'image'), 'image');
    assert.equal(d.resolveTypeFilter('', 'media'), 'video');
    assert.equal(d.resolveTypeFilter('doc', 'image'), 'doc');

    d.bodyTarget = createFakeElement('div');
    d.bodyTarget.querySelector = () => ({ dispatchEvent: () => false });
    let dialogCancelPrevented = false;
    d.onDialogCancel({ preventDefault: () => { dialogCancelPrevented = true; } });
    assert.equal(dialogCancelPrevented, true, 'Inner overlay cancelling dialog-cancel must prevent native <dialog> close');

    // 9. PickerController ignores mutations when input is disabled
    const p = new PickerController();
    p.hasInputTarget = true;
    p.inputTarget = createFakeElement('input');
    p.inputTarget.disabled = true;
    p.inputTarget.value = '/uploads/locked.jpg';
    let openCalled = false;
    globalThis.window.LiveUXFileManager = { open: async () => { openCalled = true; return []; } };
    await p.open();
    p.clearSingle();
    assert.equal(openCalled, false, 'Disabled picker must not open FileManager dialog');
    assert.equal(p.inputTarget.value, '/uploads/locked.jpg', 'Disabled picker must not clear value');
});


test('Third-pass frontend audit: stale directory responses, search controller isolation, Enter/Space guards, picker path previews, prototype-safe lookups', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const PickerController = await loadStimulusController('controllers/picker_controller.js');
    const DialogController = await loadStimulusController('controllers/dialog_controller.js');
    const savedFetch = globalThis.fetch;

    const makeBrowser = () => {
        const b = new BrowserController();
        b.listUrlValue = '/api/list';
        b.profileValue = 'default';
        b.localeValue = 'en';
        b.volumeValue = 'local';
        b.volumeLabelValue = 'local';
        b.pathValue = '';
        b.pageValue = 1;
        b.dirCache = new Map();
        b.abortController = null;
        b.searchAbortController = null;
        b.navToken = 0;
        b.dirRequestToken = 0;
        b.history = [];
        b.historyIndex = -1;
        b.expandedFolders = new Set();
        b.selectedMap = new Map();
        b.recursiveItems = null;
        b.hasVolumeSelectTarget = false;
        for (const fn of ['setLoading', 'renderQuota', 'renderBreadcrumbs', 'updatePerfBadge', 'applyClientView', 'persistLastLocation', 'showStatus', 'clearSelection', 'syncTrashButton', 'syncTrashToolbarState', 'syncNavButtons', 'renderSidebarTree']) {
            b[fn] = () => {};
        }
        b.buildBreadcrumbs = () => [];
        b.t = (k) => k;
        return b;
    };
    const deferred = () => {
        let resolve;
        const promise = new Promise((r) => { resolve = r; });
        return { promise, resolve };
    };
    const jsonRes = (data) => ({ ok: true, json: async () => data });

    // 1. A late response of an earlier folder must not overwrite state after a cache-hit navigation.
    const b = makeBrowser();
    const slow = deferred();
    globalThis.fetch = () => slow.promise;
    const pending = b.fetchDirectory('local', 'slow', true);
    b.dirCache.set(b.cacheKey('local', 'cached'), [{ kind: 'file', path: 'cached/x.png', name: 'x.png' }]);
    const navOk = await b.navigateTo('cached');
    assert.equal(navOk, true);
    assert.equal(b.pathValue, 'cached');
    slow.resolve(jsonRes({ ok: true, volume: 'local', path: 'slow', items: [{ path: 'slow/a' }] }));
    assert.equal(await pending, null, 'stale fetch result must be discarded (null = superseded)');
    assert.equal(b.pathValue, 'cached', 'stale response must not overwrite newer path');
    assert.equal(b.dirCache.has(b.cacheKey('local', 'slow')), false, 'stale response must not populate cache');

    // 2a. Older fetch superseded by a newer fetch is dropped; newer one wins.
    const b2 = makeBrowser();
    const d1 = deferred();
    const d2 = deferred();
    const queue = [d1, d2];
    globalThis.fetch = () => queue.shift().promise;
    const f1 = b2.fetchDirectory('local', 'one', true);
    const f2 = b2.fetchDirectory('local', 'two', true);
    d2.resolve(jsonRes({ ok: true, volume: 'local', path: 'two', items: [] }));
    assert.equal(await f2, true);
    d1.resolve(jsonRes({ ok: true, volume: 'local', path: 'one', items: [] }));
    assert.equal(await f1, null);
    assert.equal(b2.pathValue, 'two');

    // 2b. Rollback is skipped when the failure is an abort / a newer navigation started.
    const b3 = makeBrowser();
    b3.fetchDirectory = async () => null;
    b3.pathValue = 'prev';
    const rolled = await b3.navigateTo('target');
    assert.equal(rolled, null);
    assert.equal(b3.pathValue, 'target', 'AbortError must not roll back to the previous folder');

    // 2b'. A GENUINE failure of the latest navigation must still roll back, even when an earlier
    //      request was aborted concurrently (regression: a shared "lastFetchAborted" flag leaked across requests).
    const b3b = makeBrowser();
    const first = deferred();
    globalThis.fetch = (url, opts) => new Promise((resolve, reject) => {
        if (String(url).includes('path=a')) {
            opts.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
            first.promise.then(resolve);
            return;
        }
        resolve({ ok: false, json: async () => ({ ok: false, error: 'boom' }) });
    });
    b3b.pathValue = 'start';
    const navA = b3b.navigateTo('a');
    const navB = b3b.navigateTo('b');
    assert.equal(await navA, null);
    assert.equal(await navB, false);
    assert.equal(b3b.pathValue, 'start', 'genuine failure of the newest navigation must roll back');
    globalThis.fetch = savedFetch;

    // 2c. Recursive search uses its own AbortController and must not abort directory loads.
    const b4 = makeBrowser();
    let dirAborted = false;
    b4.abortController = { abort: () => { dirAborted = true; } };
    b4.searchQuery = 'abc';
    b4.typeFilterValue = 'all';
    globalThis.fetch = async () => jsonRes({ ok: true, items: [] });
    await b4.fetchRecursiveSearch();
    assert.equal(dirAborted, false, 'search must not abort the directory-load controller');
    assert.deepEqual(b4.recursiveItems, []);
    globalThis.fetch = savedFetch;

    // 3. Modal backdrop requires mousedown AND click on the backdrop itself.
    const b5 = makeBrowser();
    b5.hasModalBackdropTarget = true;
    b5.modalBackdropTarget = createFakeElement('div');
    b5.modalBackdropTarget.classList.add('is-open');
    b5.modalBodyTarget = createFakeElement('div');
    b5.hasModalBodyTarget = true;
    const backdrop = {};
    const inner = {};
    b5.onModalBackdropMouseDown({ target: inner, currentTarget: backdrop });
    b5.closeModalFromBackdrop({ target: backdrop, currentTarget: backdrop });
    assert.equal(b5.modalBackdropTarget.classList.contains('is-open'), true, 'drag from inside card must not close');
    b5.onModalBackdropMouseDown({ target: backdrop, currentTarget: backdrop });
    b5.closeModalFromBackdrop({ target: backdrop, currentTarget: backdrop });
    assert.equal(b5.modalBackdropTarget.classList.contains('is-open'), false, 'mousedown+click on backdrop closes');

    // 8. Enter/Space on inner buttons/links must not trigger file-manager shortcuts.
    const b6 = makeBrowser();
    b6.isFileManagerActive = () => true;
    b6.keyboardShortcutsValue = true;
    b6.hasLightboxTarget = false;
    b6.hasModalBackdropTarget = false;
    b6.hasContextMenuTarget = false;
    b6.hasSettingsPopoverTarget = false;
    b6.capabilitiesValue = {};
    b6.trashMode = false;
    b6.selectedMap.set('a.png', { kind: 'file', path: 'a.png', name: 'a.png' });
    globalThis.document = { activeElement: null };
    const btn = createFakeElement('button');
    btn.closest = (sel) => (sel.includes('button') ? btn : null);
    for (const key of ['Enter', ' ']) {
        let prevented = false;
        b6.onKeydown({ key, target: btn, preventDefault: () => { prevented = true; } });
        assert.equal(prevented, false, `${JSON.stringify(key)} on a button must not be hijacked`);
    }

    // 9. Ctrl+double-click keeps the item selected.
    const b7 = makeBrowser();
    b7.element = createFakeElement('div');
    b7.syncSelectionUi = () => {};
    b7.doubleClickToSelectValue = false;
    const card = createFakeElement('div');
    card.dataset = { path: 'a.png', name: 'a.png', url: '/u/a.png' };
    b7.doubleClickFile({ target: card, currentTarget: card, ctrlKey: true });
    assert.equal(b7.selectedMap.has('a.png'), true);

    // 12. Prototype keys must not leak Object.prototype members into the quota breakdown.
    const bq = makeBrowser();
    bq.i18nValue = {};
    bq.escapeHtml = (s) => String(s);
    bq.escapeAttr = (s) => String(s);
    bq.iconSetValue = { icons: {}, ui: {}, badges: {} };
    let html = '';
    try {
        html = bq.renderQuotaDetailsHtml({ summary: { enabled: true, percent: 10 }, counts: {}, categories: [{ key: 'constructor', percent: 40, formatted: '1 B', count: 1 }, { key: '__proto__', percent: 10, formatted: '1 B', count: 1 }, { key: 'toString', percent: 5, formatted: '1 B', count: 1 }] });
    } catch (e) {
        assert.fail(`renderQuotaDetailsHtml threw: ${e.message}`);
    }
    assert.doesNotMatch(html, /function|\[object|native code/);
    assert.match(html, /background:#64748b/);

    // 11. SVG sanitizer matches blocked tags case-insensitively (XML selectors are case-sensitive).
    const browserSrc = fs.readFileSync(path.join(assetsDir, 'controllers/browser_controller.js'), 'utf8');
    assert.match(browserSrc, /allowedTags\.has\(String\(el\.localName \|\| ''\)\.toLowerCase\(\)\)/);
    const dialogSrc = fs.readFileSync(path.join(assetsDir, 'controllers/dialog_controller.js'), 'utf8');
    assert.match(dialogSrc, /DANGEROUS_TAGS = '[^']*\bstyle\b[^']*'/);
    assert.match(dialogSrc, /srcdoc/);

    // 6. Picker dropzone keydown ignores events bubbling from inner buttons.
    const p = new PickerController();
    let opened = 0;
    p.open = () => { opened++; };
    const zone = {};
    let prevented = false;
    p.onDropzoneKeydown({ key: 'Enter', target: {}, currentTarget: zone, preventDefault: () => { prevented = true; } });
    assert.equal(opened, 0);
    assert.equal(prevented, false);
    p.onDropzoneKeydown({ key: ' ', target: zone, currentTarget: zone, preventDefault: () => { prevented = true; } });
    assert.equal(opened, 1);
    assert.equal(prevented, true);

    // 7. return_type=path previews and '..' filtering by path segment.
    const pp = new PickerController();
    assert.equal(pp.previewSrc('uploads/a.png'), '/uploads/a.png');
    assert.equal(pp.previewSrc('/uploads/a.png'), '/uploads/a.png');
    assert.equal(pp.previewSrc('a..b.png'), '/a..b.png');
    pp.previewUrlPrefixValue = '/uploads';
    assert.equal(pp.previewSrc('products/hero.jpg'), '/uploads/products/hero.jpg');
    assert.equal(pp.previewSrc('uploads/products/hero.jpg'), '/uploads/products/hero.jpg');
    pp.previewUrlPrefixValue = 'https://cdn.example.com/media';
    assert.equal(pp.previewSrc('products/hero.jpg'), 'https://cdn.example.com/media/products/hero.jpg');
    pp.previewUrlPrefixValue = '';
    assert.equal(pp.previewSrc('../a.png'), '');
    assert.equal(pp.previewSrc('x/../a.png'), '');
    assert.equal(pp.previewSrc('javascript:alert(1)'), '');
    assert.equal(pp.previewSrc('//evil.example/a.png'), '');
    assert.equal(pp.previewSrc('\\\\evil\\a.png'), '');
    pp.returnTypeValue = 'path';
    pp.hasInputTarget = true;
    pp.inputTarget = createFakeElement('input');
    pp.syncView = () => {};
    pp.dispatchChanged = () => {};
    globalThis.window = { LiveUXFileManager: { open: async () => [{ path: '../evil.png' }, { path: 'a/../b.png' }, { path: 'a..b.png' }] } };
    await pp.open();
    assert.equal(pp.inputTarget.value, 'a..b.png', "names like 'a..b.png' pass while '..' segments are rejected");

    // 5. Dialog resets loadedKey when its in-flight fetch is aborted by close.
    const d = new DialogController();
    d.loadedKey = 'k';
    d.fetchController = { abort: () => {} };
    d.pendingResolve = null;
    d.onDialogClose();
    assert.equal(d.loadedKey, null);
});

test('WYSIWYG Adapters (Quill, Trix, Summernote, Editor.js) block malicious URLs and insert safe media', async () => {
    const { openFileManagerForQuill } = await import('../../assets/js/integrations/quill_adapter.js');
    const { openFileManagerForTrix } = await import('../../assets/js/integrations/trix_adapter.js');
    const { openFileManagerForSummernote } = await import('../../assets/js/integrations/summernote_adapter.js');
    const { openFileManagerForEditorJs } = await import('../../assets/js/integrations/editorjs_adapter.js');

    const malicious = [
        { url: 'javascript:alert(1)', name: 'xss.png', isImage: true },
        { url: '//evil.example/a.png', name: 'proto.png', isImage: true },
        { url: '/uploads/../.env', name: 'env', isImage: false },
        { url: 'data:text/html,<script>alert(1)</script>', name: 'data.html', isImage: false },
    ];
    const valid = [
        { url: '/uploads/photo.webp', name: 'photo.webp', isImage: true, meta: { alt: 'Hero <script>' } },
        { url: '/uploads/spec.pdf', name: 'spec <1>.pdf', isImage: false },
    ];

    globalThis.window = {
        LiveUXFileManager: { open: async () => [...malicious, ...valid] },
    };
    globalThis.document = undefined;

    // 1. Quill
    const quillEmbeds = [];
    const quillTexts = [];
    const fakeQuill = {
        getSelection: () => ({ index: 0 }),
        getLength: () => 1,
        insertEmbed: (idx, type, val) => quillEmbeds.push({ idx, type, val }),
        insertText: (idx, text, fmt, val) => quillTexts.push({ idx, text, fmt, val }),
        setSelection: () => {},
    };
    const qRes = await openFileManagerForQuill(fakeQuill, { multiple: true });
    assert.equal(qRes.length, 2);
    assert.deepEqual(quillEmbeds, [{ idx: 0, type: 'image', val: '/uploads/photo.webp' }]);
    assert.ok(quillTexts.some((t) => t.fmt === 'link' && t.val === '/uploads/spec.pdf'));

    // 2. Trix
    const trixHtml = [];
    const fakeTrix = {
        editor: { insertHTML: (h) => trixHtml.push(h) },
        focus: () => {},
    };
    const tRes = await openFileManagerForTrix(fakeTrix, { multiple: true });
    assert.equal(tRes.length, 2);
    assert.ok(trixHtml[0].includes('src="/uploads/photo.webp"'));
    assert.ok(!trixHtml[0].includes('<script>'));
    assert.ok(trixHtml[1].includes('href="/uploads/spec.pdf"'));
    assert.ok(trixHtml[1].includes('&lt;1&gt;'));

    // 3. Summernote
    const snCalls = [];
    const fakeSn = { summernote: (cmd, ...args) => snCalls.push({ cmd, args }) };
    const sRes = await openFileManagerForSummernote(fakeSn, { multiple: true });
    assert.equal(sRes.length, 2);
    assert.equal(snCalls[0].cmd, 'insertImage');
    assert.equal(snCalls[0].args[0], '/uploads/photo.webp');
    assert.equal(snCalls[1].cmd, 'createLink');
    assert.equal(snCalls[1].args[0].url, '/uploads/spec.pdf');

    // 4. Editor.js
    const ejsBlocks = [];
    const fakeEjs = { blocks: { insert: (type, data) => ejsBlocks.push({ type, data }) } };
    const eRes = await openFileManagerForEditorJs(fakeEjs, { multiple: true });
    assert.equal(eRes.length, 2);
    assert.equal(ejsBlocks[0].type, 'image');
    assert.equal(ejsBlocks[0].data.file.url, '/uploads/photo.webp');
    assert.equal(ejsBlocks[1].type, 'paragraph');
    assert.ok(ejsBlocks[1].data.text.includes('href="/uploads/spec.pdf"'));
    assert.ok(!ejsBlocks[1].data.text.includes('<1>'));
});

test('WYSIWYG & Page Builder Adapters (GrapesJS, Lexical) block malicious URLs and insert safe media', async () => {
    const {
        createGrapesJsAssetManagerConfig,
        openFileManagerForGrapesJs,
    } = await import('../../assets/js/integrations/grapesjs_adapter.js');
    const { openFileManagerForLexical } = await import('../../assets/js/integrations/lexical_adapter.js');

    const malicious = [
        { url: 'javascript:alert(1)', name: 'xss.png', isImage: true },
        { url: '//evil.example/a.png', name: 'proto.png', isImage: true },
        { url: '/uploads/../.env', name: 'env', isImage: false },
        { url: 'data:text/html,<script>alert(1)</script>', name: 'data.html', isImage: false },
    ];
    const valid = [
        { url: '/uploads/hero.webp', name: 'hero.webp', isImage: true, meta: { alt: 'Hero Banner' } },
        { url: '/uploads/brochure.pdf', name: 'brochure.pdf', isImage: false },
    ];

    globalThis.window = {
        LiveUXFileManager: { open: async () => [...malicious, ...valid] },
    };
    globalThis.document = undefined;

    // 1. GrapesJS programmatic insertion
    const gjsAssets = [];
    const gjsComponents = [];
    const fakeGjs = {
        AssetManager: { add: (asset) => gjsAssets.push(asset) },
        getSelected: () => null,
        addComponents: (cmp) => gjsComponents.push(cmp),
    };
    const gRes = await openFileManagerForGrapesJs(fakeGjs, { multiple: true });
    assert.equal(gRes.length, 2);
    assert.equal(gjsAssets.length, 1);
    assert.equal(gjsAssets[0].src, '/uploads/hero.webp');
    assert.equal(gjsComponents[0].type, 'image');
    assert.equal(gjsComponents[0].attributes.src, '/uploads/hero.webp');
    assert.equal(gjsComponents[1].type, 'link');
    assert.equal(gjsComponents[1].attributes.href, '/uploads/brochure.pdf');

    // 2. GrapesJS custom AssetManager config blocks malicious first item
    let selectedAsset = null;
    let closed = false;
    const amConfig = createGrapesJsAssetManagerConfig({ profile: 'default' });
    await amConfig.custom.open({
        types: ['image'],
        select: (asset) => { selectedAsset = asset; },
        close: () => { closed = true; },
    });
    assert.equal(selectedAsset, null);
    assert.equal(closed, true);

    // 3. Lexical command dispatch
    const lexicalDispatched = [];
    const INSERT_IMAGE = { type: 'INSERT_IMAGE_COMMAND' };
    const INSERT_LINK = { type: 'TOGGLE_LINK_COMMAND' };
    const fakeLexical = {
        dispatchCommand: (cmd, payload) => {
            lexicalDispatched.push({ cmd, payload });
            return true;
        },
    };
    const lRes = await openFileManagerForLexical(fakeLexical, {
        multiple: true,
        insertImageCommand: INSERT_IMAGE,
        insertLinkCommand: INSERT_LINK,
    });
    assert.equal(lRes.length, 2);
    assert.equal(lexicalDispatched[0].cmd, INSERT_IMAGE);
    assert.equal(lexicalDispatched[0].payload.src, '/uploads/hero.webp');
    assert.equal(lexicalDispatched[0].payload.altText, 'Hero Banner');
    assert.equal(lexicalDispatched[1].cmd, INSERT_LINK);
    assert.equal(lexicalDispatched[1].payload.url, '/uploads/brochure.pdf');
});

test('BrowserController: expandCompactItems reconstructs compact JSON wire payloads accurately', async () => {
    const BrowserController = await loadStimulusController('controllers/browser_controller.js');
    const ctrl = new BrowserController();
    ctrl.profileValue = 'default';
    ctrl.volumeValue = 'media';
    ctrl.localeValue = 'en';

    const expanded = ctrl.expandCompactItems(
        [
            { kind: 'folder', name: 'Products', path: 'Products', lastModified: 1700000000, itemCount: 5 },
            { kind: 'file', name: 'mug.webp', path: 'Products/mug.webp', size: 2048, extension: 'webp', category: 'image', lastModified: 1700000010, isImage: true, url: '/uploads/media/Products/mug.webp' },
            { kind: 'file', name: 'secret.pdf', path: 'secret.pdf', size: 1024, extension: 'pdf', category: 'doc', lastModified: 1700000020 },
        ],
        { download: '/fm/download', thumb: '/fm/thumb' },
        'media',
    );

    assert.equal(expanded[0].pinned, false);
    assert.equal(expanded[0].color, null);
    assert.equal(expanded[0].itemCount, 5);
    assert.equal(expanded[1].url, '/uploads/media/Products/mug.webp');
    assert.ok(expanded[1].downloadUrl.startsWith('/fm/download?'));
    assert.ok(expanded[1].thumbUrl.startsWith('/fm/thumb?'));
    assert.equal(expanded[1].isImage, true);
    assert.ok(expanded[1].sizeFormatted.includes('2'));
    assert.ok(expanded[2].url.includes('inline=1'));
    assert.equal(expanded[2].thumbUrl, null);
    assert.equal(expanded[2].isImage, false);
});

