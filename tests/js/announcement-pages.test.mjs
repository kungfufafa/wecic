import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';
import { viewSource } from './support/views.mjs';

/**
 * The read marks and ticked steps code inside the announcement pages and
 * the Beranda card (resources/views/announcements/*, dashboard), run as
 * written against a real PortalApp and the fake browser's storage. Marks
 * saved before stable ids hold the old wording-based id (`legacy_id`).
 */
const view = (name) => viewSource(name);

/**
 * The source from the line holding `from` up to (not including) the line
 * holding `to`.
 */
function slice(source, from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

/**
 * Run a page's snippet with `scope` as its variables; returns `names`.
 */
function run(snippet, scope, names) {
    const keys = Object.keys(scope);

    return new Function(...keys, `${snippet}\nreturn { ${names.join(', ')} };`)(...keys.map((key) => scope[key]));
}

const READ_KEY = 'mycic-announcement-read';
const STEPS_KEY = 'mycic-announcement-steps';
const firstStable = '1'.repeat(40);
const firstLegacy = 'a'.repeat(40);
const secondStable = '2'.repeat(40);
const secondLegacy = 'b'.repeat(40);
const older = 'f'.repeat(40);
const first = { id: firstStable, legacy_id: firstLegacy, title: 'Jadwal UTS', published_date: '2026-10-20' };
const second = { id: secondStable, legacy_id: secondLegacy, title: 'Pembayaran UKT', published_date: '2026-10-21' };

let browser;
let app;

afterEach(() => browser?.dispose());

async function openApp() {
    const created = await createPortalApp({ url: '/announcement', page: 'announcement-index' });

    browser = created.browser;
    app = created.app;
}

const stored = (key) => JSON.parse(browser.localStorage.getItem(key));

describe('announcement list (/announcement)', () => {
    const source = view('announcements/index');

    function listPage(state) {
        const { storage } = run(slice(source, 'const storage = {', 'const params = new URLSearchParams'), { window: browser.window }, ['storage']);

        return run(
            slice(source, '// The same wording is the same announcement', 'const nextDeadline = (item)'),
            { app, state, storage, READ_KEY },
            ['isRead', 'allItems', 'loadReadIds', 'migrateReadIds']
        );
    }

    it('counts an announcement read before the deploy as read and moves its mark to the stable id', async () => {
        await openApp();
        browser.localStorage.setItem(READ_KEY, JSON.stringify({ ids: [older, firstLegacy], seeded: true }));

        const state = { feedItems: [first, second], extraItems: [], readIds: new Set() };
        const page = listPage(state);

        page.loadReadIds(page.allItems());

        assert.equal(page.isRead(first), true);
        assert.equal(page.isRead(second), false);
        assert.deepEqual(stored(READ_KEY), { ids: [older, firstStable], seeded: true });
    });

    it('moves the marks of an older page once that page is listed', async () => {
        await openApp();
        browser.localStorage.setItem(READ_KEY, JSON.stringify({ ids: [secondLegacy], seeded: true }));

        const state = { feedItems: [first], extraItems: [], readIds: new Set() };
        const page = listPage(state);

        page.loadReadIds(page.allItems());
        assert.deepEqual(stored(READ_KEY).ids, [secondLegacy], 'not listed yet: the mark stays as it was');

        state.extraItems.push(second);
        assert.equal(page.isRead(second), true);
        page.migrateReadIds(page.allItems());

        assert.deepEqual(stored(READ_KEY).ids, [secondStable]);
        assert.deepEqual([...state.readIds], [secondStable]);
    });

    it('a later-page post that kept its old id (its stable id was taken) is not touched', async () => {
        await openApp();
        browser.localStorage.setItem(READ_KEY, JSON.stringify({ ids: [secondLegacy], seeded: true }));

        const state = { feedItems: [first], extraItems: [{ ...second, id: secondLegacy }], readIds: new Set() };
        const page = listPage(state);

        page.loadReadIds(page.allItems());

        assert.equal(page.isRead(state.extraItems[0]), true);
        assert.deepEqual(stored(READ_KEY).ids, [secondLegacy]);
    });
});

describe('announcement detail (/announcement/{id})', () => {
    const source = view('announcements/show');

    function detailPage() {
        const { storage } = run(slice(source, 'const storage = {', 'const shortDate = '), { window: browser.window }, ['storage']);
        const marks = run(slice(source, '// A mark saved under the old wording-based id', 'const linkHost = '), { app, storage, READ_KEY }, ['markRead']);
        const steps = run(slice(source, '// Ticks saved under the old wording-based id', 'const renderSteps = '), { app, storage, STEPS_KEY }, ['readSteps', 'writeSteps']);

        return { ...marks, ...steps };
    }

    it('opening an announcement read before the deploy moves its mark instead of adding a second one', async () => {
        await openApp();
        browser.localStorage.setItem(READ_KEY, JSON.stringify({ ids: [firstLegacy, older], seeded: true }));

        detailPage().markRead(first);

        assert.deepEqual(stored(READ_KEY), { ids: [firstStable, older], seeded: true });
    });

    it('opening an unread announcement marks it under its stable id', async () => {
        await openApp();

        const page = detailPage();

        page.markRead(first);
        page.markRead(first);

        assert.deepEqual(stored(READ_KEY), { ids: [firstStable], seeded: false });
    });

    it('keeps the steps ticked before the deploy', async () => {
        await openApp();
        browser.localStorage.setItem(STEPS_KEY, JSON.stringify({ [older]: [1], [firstLegacy]: [1, 3] }));

        const page = detailPage();

        assert.deepEqual(page.readSteps(first), [1, 3]);
        assert.deepEqual(stored(STEPS_KEY), { [older]: [1], [firstStable]: [1, 3] });

        page.writeSteps(first.id, [1, 2, 3]);
        assert.deepEqual(page.readSteps(first), [1, 2, 3]);
    });
});

describe('Beranda announcement card', () => {
    const source = view('dashboard');

    function card() {
        return run(
            slice(source, 'const readAnnouncementIds = ', 'const renderAnnouncementCard = '),
            { app, window: browser.window, ANNOUNCEMENT_READ_KEY: READ_KEY },
            ['readAnnouncementIds']
        );
    }

    it('reads a mark saved before the deploy and moves it to the stable id', async () => {
        await openApp();
        browser.localStorage.setItem(READ_KEY, JSON.stringify({ ids: [firstLegacy], seeded: true }));

        const readIds = card().readAnnouncementIds([first, second]);

        assert.equal(app.isAnnouncementRead(readIds, first), true);
        assert.equal(app.isAnnouncementRead(readIds, second), false);
        assert.deepEqual(stored(READ_KEY), { ids: [firstStable], seeded: true });
    });

    it('writes nothing for a device that never opened the list, and survives a broken copy', async () => {
        await openApp();

        assert.deepEqual([...card().readAnnouncementIds([first])], []);
        assert.equal(browser.localStorage.getItem(READ_KEY), null);

        browser.localStorage.setItem(READ_KEY, '{rusak');
        assert.deepEqual([...card().readAnnouncementIds([first])], []);
        assert.equal(browser.localStorage.getItem(READ_KEY), '{rusak');
    });
});
