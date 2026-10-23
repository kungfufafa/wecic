import { existsSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/**
 * What `npm run build` builds public/build from (vite.config.js): the entries
 * and everything they import, the files Tailwind scans for class names
 * (the `@source` lines of resources/css/app.css), the build configuration
 * and the installed packages.
 */
export const BUILD_INPUTS = ['resources/js', 'resources/css', 'resources/views', 'vite.config.js', 'package-lock.json'];

/**
 * The entry every page loads (`@vite` in the layouts).
 */
export const APP_ENTRY = 'resources/js/app.js';

const MANIFEST = 'public/build/manifest.json';

/**
 * The files of `path` (a file, or a directory searched recursively), with
 * their modification times.
 *
 * @param {string} path
 * @returns {Array<{ path: string, changedAt: number }>}
 */
function filesOf(path) {
    if (!existsSync(path)) {
        return [];
    }

    const stats = statSync(path);

    if (stats.isFile()) {
        return [{ path, changedAt: stats.mtimeMs }];
    }

    return readdirSync(path, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile())
        .map((entry) => {
            const file = join(entry.parentPath, entry.name);

            return { path: file, changedAt: statSync(file).mtimeMs };
        });
}

/**
 * The build inputs under `rootDir` changed after public/build was last built,
 * newest first and relative to `rootDir`; `null` when there is no build.
 *
 * @param {string} rootDir
 * @returns {string[] | null}
 */
export function buildInputsChangedSinceTheBuild(rootDir) {
    const manifest = join(rootDir, MANIFEST);

    if (!existsSync(manifest)) {
        return null;
    }

    const builtAt = statSync(manifest).mtimeMs;

    return BUILD_INPUTS.flatMap((input) => filesOf(join(rootDir, input)))
        .filter((file) => file.changedAt > builtAt)
        .sort((a, b) => b.changedAt - a.changedAt)
        .map((file) => relative(rootDir, file.path));
}

/**
 * The specs run the pages' built bundle (public/build). One older than its
 * sources runs yesterday's JavaScript, so a green run would prove nothing:
 * the run stops before any spec does.
 *
 * @param {string} rootDir
 * @throws {Error} when there is no build, or a build input changed after it
 */
export function assertFreshBuild(rootDir) {
    const changed = buildInputsChangedSinceTheBuild(rootDir);
    const remedy = 'Run `npm run build` first, or run the specs with `npm run test:e2e`, which builds before testing.';

    if (changed === null) {
        throw new Error(`[e2e] public/build has no manifest.json, so the pages have no bundle to run. ${remedy}`);
    }

    if (changed.length > 0) {
        const named = changed.slice(0, 5).join(', ') + (changed.length > 5 ? ` and ${changed.length - 5} more` : '');

        throw new Error(`[e2e] public/build is older than its sources (${named} changed after the last build): the specs would test the old bundle. ${remedy}`);
    }
}

/**
 * A page of the e2e server (`html`) must load the app's entry from the build
 * the run checked (`manifest`, public/build/manifest.json), on the server
 * itself (`appOrigin`): the specs let no other origin through, so a page
 * loading its scripts from elsewhere (a Vite dev server, an ASSET_URL) would
 * fail every spec with errors that say nothing about why.
 *
 * @param {string} html
 * @param {string} appOrigin
 * @param {Record<string, { file?: string }>} manifest
 * @throws {Error} when the page does not load the built entry from `appOrigin`
 */
export function assertPageLoadsTheBuild(html, appOrigin, manifest) {
    const entry = manifest?.[APP_ENTRY]?.file;

    if (!entry) {
        throw new Error(`[e2e] public/build/manifest.json has no ${APP_ENTRY}. Run \`npm run build\` again.`);
    }

    const expected = new URL(`/build/${entry}`, appOrigin).href;
    const scripts = [...html.matchAll(/<script\b[^>]*?\bsrc\s*=\s*["']([^"']+)["']/gi)]
        .map(([, src]) => new URL(src.replaceAll('&amp;', '&'), appOrigin).href);

    if (!scripts.includes(expected)) {
        throw new Error(
            `[e2e] The e2e server's pages do not load ${expected} (they load: ${scripts.join(', ') || 'no script'}). `
            + 'The specs only let the e2e server through: its pages must load the bundle in public/build from it, '
            + 'not from a Vite dev server or an ASSET_URL. Rebuild with `npm run build` and run the specs again.',
        );
    }
}
