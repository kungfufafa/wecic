import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { createPortalApp } from './support/browser.mjs';

/**
 * resources/js/app.js builds PortalApp from the feature classes in
 * resources/js/app/: every feature file is mixed in, every method the pages
 * call comes from exactly one of them, and the entry itself keeps only the
 * constructor (all state) and init(). resources/js/app/ui.js is not a
 * feature: it is the class map the constructor puts on `app.ui`.
 */
const featureDirectory = new URL('../../resources/js/app/', import.meta.url);
const entry = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const classMapFile = 'ui.js';
const featureFiles = readdirSync(featureDirectory).filter((file) => file.endsWith('.js') && file !== classMapFile).sort();

let browser;

afterEach(() => browser?.dispose());

/**
 * The PortalApp class and every feature module, loaded through the same
 * loader hooks as the app (stubbed Echo and jsQR).
 */
async function load() {
    const created = await createPortalApp();

    browser = created.browser;

    const features = await Promise.all(featureFiles.map(async (file) => {
        const module = await import(new URL(file, featureDirectory).href);
        const classes = Object.values(module).filter((value) => typeof value === 'function');

        assert.equal(classes.length, 1, `${file} exports one feature class`);

        return { file, feature: classes[0] };
    }));

    return { PortalApp: created.app.constructor, features };
}

const methodsOf = (feature) => Object.getOwnPropertyNames(feature.prototype).filter((name) => name !== 'constructor');

describe('PortalApp features', () => {
    it('imports and mixes in every feature file, and nothing else', () => {
        const imported = [...entry.matchAll(/^import \{ (\w+) \} from '\.\/app\/([\w-]+\.js)';$/gm)].map((match) => match[2]).sort();
        const mixed = entry.slice(entry.indexOf('mixFeatures(\n')).match(/^ {4}(\w+Feature),$/gm).map((line) => line.trim().replace(',', ''));

        assert.deepEqual(imported.filter((file) => file !== classMapFile), featureFiles);
        assert.ok(imported.includes(classMapFile), 'app.js puts the class map on app.ui');
        assert.equal(mixed.length, featureFiles.length);
    });

    it('gives the app each feature\'s methods as written, none of them defined twice', async () => {
        const { PortalApp, features } = await load();
        const owners = new Map();

        features.forEach(({ file, feature }) => {
            assert.ok(methodsOf(feature).length > 0, `${file} defines methods`);

            methodsOf(feature).forEach((name) => {
                assert.equal(owners.has(name), false, `${name}() is defined in ${owners.get(name)} and ${file}`);
                owners.set(name, file);
                assert.equal(PortalApp.prototype[name], feature.prototype[name], `${name}() from ${file}`);
            });
        });

        const own = Object.getOwnPropertyNames(PortalApp.prototype).filter((name) => !owners.has(name)).sort();

        assert.deepEqual(own, ['constructor', 'init']);
    });

    it('keeps every method a page script or another feature calls on the app', async () => {
        const { PortalApp } = await load();
        const views = new URL('../../resources/views/', import.meta.url);
        const sources = [
            ...readdirSync(views, { recursive: true }).filter((file) => String(file).endsWith('.blade.php')).map((file) => readFileSync(new URL(String(file), views), 'utf8')),
            ...featureFiles.map((file) => readFileSync(new URL(file, featureDirectory), 'utf8')),
            entry,
        ];
        const called = new Set(sources.flatMap((source) => [...source.matchAll(/\b(?:app|portalApp|this)\??\.(\w+)\(/g)].map((match) => match[1])));
        const missing = [...called].filter((name) => typeof PortalApp.prototype[name] !== 'function');

        assert.ok(called.size > 150, `found ${called.size} method calls`);
        assert.deepEqual(missing, []);
    });
});
