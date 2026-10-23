import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import {
    assertFreshBuild,
    assertPageLoadsTheBuild,
    buildInputsChangedSinceTheBuild,
} from '../e2e/support/build-guard.mjs';

/**
 * tests/e2e/support/build-guard.mjs: the Playwright run (global-setup.ts)
 * refuses a bundle older than its sources, and a server whose pages load
 * their scripts from anywhere but the build on the e2e server.
 */
const builtAt = new Date('2026-10-22T18:44:00Z');
const before = new Date('2026-10-22T18:00:00Z');
const after = new Date('2026-10-23T09:00:00Z');

let rootDir = null;

afterEach(() => {
    if (rootDir) {
        rmSync(rootDir, { recursive: true, force: true });
        rootDir = null;
    }
});

/**
 * A checkout whose sources all predate a build made at `builtAt` (no build
 * when `built` is false).
 */
function checkout({ built = true } = {}) {
    rootDir = mkdtempSync(join(tmpdir(), 'mycic-e2e-build-guard-'));

    [
        'resources/js/app.js',
        'resources/js/lib/attendance.js',
        'resources/css/app.css',
        'resources/views/layouts/app.blade.php',
        'vite.config.js',
        'package-lock.json',
        'tests/e2e/login.spec.ts',
    ].forEach((file) => touch(file, before));

    if (built) {
        touch('public/build/manifest.json', builtAt);
    }

    return rootDir;
}

function touch(file, changedAt) {
    const path = join(rootDir, file);

    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, '');
    utimesSync(path, changedAt, changedAt);
}

describe('assertFreshBuild', () => {
    it('lets a build newer than every source through', () => {
        checkout();

        assert.doesNotThrow(() => assertFreshBuild(rootDir));
        assert.deepEqual(buildInputsChangedSinceTheBuild(rootDir), []);
    });

    it('stops the run when there is no build at all', () => {
        checkout({ built: false });

        assert.equal(buildInputsChangedSinceTheBuild(rootDir), null);
        assert.throws(() => assertFreshBuild(rootDir), /public\/build has no manifest\.json.*npm run build/);
    });

    for (const source of [
        'resources/js/app.js',
        'resources/js/lib/attendance.js',
        'resources/css/app.css',
        'resources/views/layouts/app.blade.php',
        'vite.config.js',
        'package-lock.json',
    ]) {
        it(`stops the run when ${source} changed after the build`, () => {
            checkout();
            touch(source, after);

            assert.deepEqual(buildInputsChangedSinceTheBuild(rootDir), [source]);
            assert.throws(
                () => assertFreshBuild(rootDir),
                (error) => error.message.includes(`(${source} changed after the last build)`)
                    && error.message.includes('`npm run build`')
                    && error.message.includes('`npm run test:e2e`'),
            );
        });
    }

    it('names the newest changes first and counts the rest', () => {
        checkout();

        for (let index = 0; index < 7; index += 1) {
            touch(`resources/js/lib/changed-${index}.js`, new Date(after.getTime() + index * 1000));
        }

        assert.deepEqual(buildInputsChangedSinceTheBuild(rootDir).slice(0, 2), ['resources/js/lib/changed-6.js', 'resources/js/lib/changed-5.js']);
        assert.throws(() => assertFreshBuild(rootDir), /changed-6\.js, .*changed-2\.js and 2 more changed after the last build/);
    });

    it('ignores files the build does not read', () => {
        checkout();
        touch('tests/e2e/login.spec.ts', after);
        touch('routes/e2e.php', after);
        touch('public/offline.html', after);

        assert.doesNotThrow(() => assertFreshBuild(rootDir));
    });
});

describe('assertPageLoadsTheBuild', () => {
    const origin = 'http://127.0.0.1:8000';
    const manifest = {
        'resources/css/app.css': { file: 'assets/app-Ce_wTKRf.css' },
        'resources/js/app.js': { file: 'assets/app-New12345.js' },
    };

    /**
     * The head `@vite` renders from the build (Laravel's Vite helper).
     */
    const builtHead = (scriptOrigin = origin, file = 'assets/app-New12345.js') => `<!DOCTYPE html><html><head>
        <link rel="preload" as="style" href="${scriptOrigin}/build/assets/app-Ce_wTKRf.css" />
        <link rel="modulepreload" href="${scriptOrigin}/build/${file}" />
        <link rel="stylesheet" href="${scriptOrigin}/build/assets/app-Ce_wTKRf.css" />
        <script type="module" src="${scriptOrigin}/build/${file}"></script>
    </head><body data-auth="guest"></body></html>`;

    it('lets a page that loads the built entry from the e2e server through', () => {
        assert.doesNotThrow(() => assertPageLoadsTheBuild(builtHead(), origin, manifest));
        assert.doesNotThrow(() => assertPageLoadsTheBuild('<script type="module" src="/build/assets/app-New12345.js"></script>', origin, manifest));
    });

    it('stops the run when the pages load the Vite dev server (public/hot)', () => {
        const hotHead = '<script type="module" src="http://[::1]:5173/@vite/client"></script>'
            + '<link rel="stylesheet" href="http://[::1]:5173/resources/css/app.css" />'
            + '<script type="module" src="http://[::1]:5173/resources/js/app.js"></script>';

        assert.throws(
            () => assertPageLoadsTheBuild(hotHead, origin, manifest),
            (error) => error.message.includes(`do not load ${origin}/build/assets/app-New12345.js`)
                && error.message.includes('http://[::1]:5173/resources/js/app.js')
                && error.message.includes('Vite dev server'),
        );
    });

    it('stops the run when the bundle comes from another origin (ASSET_URL)', () => {
        assert.throws(() => assertPageLoadsTheBuild(builtHead('https://cdn.invalid'), origin, manifest), /https:\/\/cdn\.invalid\/build\/assets\/app-New12345\.js/);
    });

    it('stops the run when the page loads another build than the one checked', () => {
        assert.throws(() => assertPageLoadsTheBuild(builtHead(origin, 'assets/app-Old00000.js'), origin, manifest), /do not load http:\/\/127\.0\.0\.1:8000\/build\/assets\/app-New12345\.js/);
    });

    it('stops the run when the page loads no script, or the manifest has no app entry', () => {
        assert.throws(() => assertPageLoadsTheBuild('<html><body></body></html>', origin, manifest), /they load: no script/);
        assert.throws(() => assertPageLoadsTheBuild(builtHead(), origin, {}), /manifest\.json has no resources\/js\/app\.js/);
    });
});
