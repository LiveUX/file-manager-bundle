#!/usr/bin/env node
/**
 * Official production minifier for @liveux/file-manager-bundle.
 *
 * Minifies readable ES modules & core.css into assets/dist/ while preserving:
 *  - Clean ESM imports/exports (compatible with both Symfony AssetMapper & Webpack Encore)
 *  - Exact directory structure so relative imports & CSS url('../images/...') work zero-config
 *  - External @hotwired/stimulus import (never bundled)
 *
 * Usage:
 *   npm run build        -> rebuilds assets/dist/
 *   npm run build:check  -> exits non-zero if assets/dist/ is stale or out of sync (CI guard)
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import esbuild from 'esbuild';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DIST_DIR = path.join(__dirname, 'dist');
const CHECK_ONLY = process.argv.includes('--check');

const JS_FILES = [
    'controllers/browser_controller.js',
    'controllers/dialog_controller.js',
    'controllers/picker_controller.js',
    'js/file_manager_api.js',
    'js/integrations/_url_guard.js',
    'js/integrations/ckeditor5_adapter.js',
    'js/integrations/editorjs_adapter.js',
    'js/integrations/grapesjs_adapter.js',
    'js/integrations/lexical_adapter.js',
    'js/integrations/quill_adapter.js',
    'js/integrations/summernote_adapter.js',
    'js/integrations/tinymce_adapter.js',
    'js/integrations/tiptap_adapter.js',
    'js/integrations/trix_adapter.js',
];

const CSS_FILES = [
    'styles/core.css',
];

const STATIC_FILES = [
    'images/liveux-logo.webp',
];

function formatKb(bytes) {
    return `${(bytes / 1024).toFixed(1).padStart(6)} KB`;
}

async function buildOrCheck() {
    const diffs = [];
    let totalRaw = 0;
    let totalMin = 0;
    let totalGzip = 0;
    let totalBrotli = 0;

    if (!CHECK_ONLY) {
        fs.mkdirSync(DIST_DIR, { recursive: true });
    }

    const writeOrVerify = (relPath, expectedBuf) => {
        const dest = path.join(DIST_DIR, relPath);
        if (CHECK_ONLY) {
            if (!fs.existsSync(dest)) {
                diffs.push(`MISSING: dist/${relPath}`);
                return;
            }
            const currentBuf = fs.readFileSync(dest);
            if (!currentBuf.equals(expectedBuf)) {
                diffs.push(`STALE:   dist/${relPath}`);
            }
            return;
        }
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.writeFileSync(dest, expectedBuf);
    };

    const rows = [];

    // 1. Minify JS ESM modules individually (bundle: false keeps ESM imports & Stimulus external)
    for (const rel of JS_FILES) {
        const srcPath = path.join(__dirname, rel);
        const rawBuf = fs.readFileSync(srcPath);
        const result = await esbuild.transform(rawBuf.toString('utf8'), {
            loader: 'js',
            format: 'esm',
            target: ['es2022'],
            minify: true,
            legalComments: 'none',
            charset: 'utf8',
        });
        // Symfony 6.4 AssetMapper's JavaScriptImportPathCompiler regex requires a space
        // after `import` before `{` (`import {...}`) and before quotes (`import "../..."`).
        const sf64SafeCode = result.code
            .replace(/\bimport\{/g, 'import {')
            .replace(/\b(import|from)(["'])/g, '$1 $2');
        const minBuf = Buffer.from(sf64SafeCode, 'utf8');
        const gzBuf = zlib.gzipSync(minBuf, { level: 9 });
        const brBuf = zlib.brotliCompressSync(minBuf, {
            params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11 },
        });

        writeOrVerify(rel, minBuf);
        totalRaw += rawBuf.length;
        totalMin += minBuf.length;
        totalGzip += gzBuf.length;
        totalBrotli += brBuf.length;
        rows.push({ file: rel, raw: rawBuf.length, min: minBuf.length, gz: gzBuf.length, br: brBuf.length });
    }

    // 2. Minify CSS while preserving relative url('../images/...')
    for (const rel of CSS_FILES) {
        const srcPath = path.join(__dirname, rel);
        const rawBuf = fs.readFileSync(srcPath);
        const result = await esbuild.transform(rawBuf.toString('utf8'), {
            loader: 'css',
            target: ['chrome105', 'firefox105', 'safari15.4'],
            minify: true,
            legalComments: 'none',
            charset: 'utf8',
        });
        const minBuf = Buffer.from(result.code, 'utf8');
        const gzBuf = zlib.gzipSync(minBuf, { level: 9 });
        const brBuf = zlib.brotliCompressSync(minBuf, {
            params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11 },
        });

        writeOrVerify(rel, minBuf);
        totalRaw += rawBuf.length;
        totalMin += minBuf.length;
        totalGzip += gzBuf.length;
        totalBrotli += brBuf.length;
        rows.push({ file: rel, raw: rawBuf.length, min: minBuf.length, gz: gzBuf.length, br: brBuf.length });
    }

    // 3. Copy static binary assets (logo WebP)
    for (const rel of STATIC_FILES) {
        const srcPath = path.join(__dirname, rel);
        const rawBuf = fs.readFileSync(srcPath);
        writeOrVerify(rel, rawBuf);
    }

    if (CHECK_ONLY) {
        if (diffs.length > 0) {
            console.error('assets/dist/ is out of sync with source assets! Run "npm --prefix assets run build":');
            for (const d of diffs) {
                console.error(`  - ${d}`);
            }
            process.exit(1);
        }
        console.log('OK: assets/dist/ is 100% in sync with source assets.');
        return;
    }

    console.log('\nLiveUX FileManagerBundle — Asset Minification Report (assets/dist/):');
    console.log('─'.repeat(84));
    console.log(`${'File'.padEnd(42)} ${'Raw'.padStart(9)} ${'Minified'.padStart(10)} ${'Gzip'.padStart(9)} ${'Brotli'.padStart(9)}`);
    console.log('─'.repeat(84));
    for (const r of rows) {
        console.log(`${r.file.padEnd(42)} ${formatKb(r.raw)} ${formatKb(r.min)} ${formatKb(r.gz)} ${formatKb(r.br)}`);
    }
    console.log('─'.repeat(84));
    const savedPct = ((1 - totalMin / totalRaw) * 100).toFixed(1);
    console.log(`${`TOTAL (-${savedPct}% minified)`.padEnd(42)} ${formatKb(totalRaw)} ${formatKb(totalMin)} ${formatKb(totalGzip)} ${formatKb(totalBrotli)}`);
    console.log('─'.repeat(84) + '\n');
}

buildOrCheck().catch((err) => {
    console.error(err);
    process.exit(1);
});
