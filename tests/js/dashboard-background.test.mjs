import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createPortalApp, json, settle } from './support/browser.mjs';

/**
 * The Beranda re-reads it starts on its own (a snapshot still being built, a
 * new day, the tab shown again), run as written in
 * resources/views/dashboard.blade.php, with the page's own loaders, against a
 * real PortalApp. Those reads carry the background header
 * (PortalSessionMiddleware::BACKGROUND_HEADER), so an open tab nobody touches
 * still idles out; the page's first load and anything the student starts
 * (pull-to-refresh, Coba lagi) do not, even while a re-read is still waiting.
 */
const source = readFileSync(new URL('../../resources/views/dashboard.blade.php', import.meta.url), 'utf8');
const BACKGROUND_HEADER = 'X-MyCIC-Background';

/**
 * The source from the line holding `from` up to (not including) the line
 * holding `to`.
 */
function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

function constant(name) {
    const match = source.match(new RegExp(`const ${name} = (\\d+);`));

    assert.ok(match, name);

    return Number(match[1]);
}

/**
 * The page's re-read timers, its new-day and tab-switch handlers and its four
 * loaders, sharing one scope as they do on the page. Rendering is stubbed.
 */
const PAGE = [
    slice('const scheduleRecheck = (key, isPending, run) => {', 'const whenIdle = (callback) => {'),
    slice('const refreshForNewDay = () => {', '// ---- Load ----'),
    slice('\n            loadDashboard = async (', "app.registerSyncHandler('dashboard-overview'"),
].join('\n');

let browser;
let app;

afterEach(() => browser?.dispose());

const marked = (request) => request.headers[BACKGROUND_HEADER] === '1';
const reads = (path) => browser.requests.filter((request) => request.url.pathname === path);
const paths = () => browser.requests.map((request) => request.url.pathname).sort();

/**
 * A Beranda whose server answers every read, unless `hold(path)` says to
 * keep that read waiting.
 */
async function openDashboard({ today = { value: '2026-10-22' }, renderedDay = '2026-10-22', hold = () => false, latestActionPayload = null } = {}) {
    const created = await createPortalApp({ url: '/dashboard', page: 'dashboard' });
    const intervals = [];
    const document = new EventTarget();

    browser = created.browser;
    app = created.app;
    browser.server = (request) => (hold(request)
        ? new Promise(() => {})
        : json(200, { status: 'success', data: {}, meta: {} }));
    document.visibilityState = 'visible';

    const scope = {
        app,
        window: {
            setTimeout: browser.window.setTimeout,
            clearTimeout: browser.window.clearTimeout,
            setInterval: (callback, delay) => intervals.push({ callback, delay }),
        },
        document,
        navigator: browser.navigator,
        todayIso: () => today.value,
        renderedDay,
        renderSignatures: new Map(),
        recheckTimers: new Map(),
        recheckAttempts: new Map(),
        RECHECK_LIMIT: constant('RECHECK_LIMIT'),
        RECHECK_DELAY_MS: constant('RECHECK_DELAY_MS'),
        renderActionCenter: () => {},
        renderAnnouncements: () => {},
        renderDashboard: () => {},
        renderPendingAssignments: () => {},
        renderPeriod: () => {},
        markMetricsUnavailable: () => {},
        queueSecondaryRefresh: () => {},
        periodHost: { hasAttribute: () => false },
        actionsHost: { removeAttribute: () => {} },
        announcementsHost: { removeAttribute: () => {} },
        announcementsRendered: false,
        dashboardOverviewHealthy: false,
        latestClassesSummary: null,
        latestActionPayload,
        latestAnnouncementPayload: null,
        latestDashboardPayload: null,
        latestAssignmentsPayload: null,
        ownActionItemsRequest: null,
        lastActionFetchAt: 0,
        lastDashboardFetchAt: 0,
        loadDashboard: undefined,
        loadPendingAssignments: undefined,
        loadActionCenter: undefined,
        loadAnnouncements: undefined,
    };
    const keys = Object.keys(scope);
    const page = new Function(...keys, `${PAGE}\nreturn { scheduleRecheck, loadDashboard, loadPendingAssignments, loadActionCenter, loadAnnouncements };`)(...keys.map((key) => scope[key]));

    return { ...page, intervals, document };
}

describe('Beranda re-read of a snapshot still being built', () => {
    it('re-reads on its timer as background work, never as the student\'s activity', async () => {
        const page = await openDashboard();

        await page.loadDashboard();
        assert.equal(marked(reads('/api/dashboard')[0]), false, 'opening the page is the student\'s own request');

        page.scheduleRecheck('dashboard', true, (options) => page.loadDashboard(options));
        await browser.clock.advance(constant('RECHECK_DELAY_MS'));
        await settle();

        assert.equal(reads('/api/dashboard').length, 2);
        assert.equal(marked(reads('/api/dashboard')[1]), true);
    });

    it('the page wires its re-reads to pass the background flag on to the loader', () => {
        assert.ok(source.includes("scheduleRecheck('dashboard', pendingSources.length > 0 || attendanceChecking, (options) => loadDashboard(options));"));
        assert.ok(source.includes("scheduleRecheck('actions', Array.isArray(meta.pending_sources) && meta.pending_sources.length > 0, (options) => loadActionCenter(options));"));
    });

    it('leaves the re-read to realtime when it is connected', async () => {
        const page = await openDashboard();

        app.realtimeConnected = true;
        page.scheduleRecheck('dashboard', true, (options) => page.loadDashboard(options));
        await browser.clock.advance(constant('RECHECK_DELAY_MS'));

        assert.equal(reads('/api/dashboard').length, 0);
    });
});

describe('Beranda re-read on a new day or a tab shown again', () => {
    it('the minute timer re-reads a new day as background work', async () => {
        const today = { value: '2026-10-22' };
        const { intervals } = await openDashboard({ today });

        assert.equal(intervals.length, 1);
        assert.equal(intervals[0].delay, 60000);

        intervals[0].callback();
        await settle();
        assert.equal(browser.requests.length, 0, 'the same day re-reads nothing');

        today.value = '2026-10-23';
        intervals[0].callback();
        await settle();

        assert.deepEqual(paths(), ['/api/action-items', '/api/assignment/pending', '/api/dashboard']);
        assert.ok(browser.requests.every(marked), 'every re-read of a new day is background work');
    });

    it('a tab shown again after a while re-reads as background work', async () => {
        const { document } = await openDashboard();

        document.dispatchEvent(new Event('visibilitychange'));
        await settle();

        assert.deepEqual(paths(), ['/api/action-items', '/api/dashboard']);
        assert.ok(browser.requests.every(marked));
    });

    it('a hidden tab re-reads nothing', async () => {
        const { document } = await openDashboard();

        document.visibilityState = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));
        await settle();

        assert.equal(browser.requests.length, 0);
    });

    it('the action centre a background task-list read sets off is background work too', async () => {
        const page = await openDashboard({ latestActionPayload: { meta: { sources: { assignments: { status: 'missing' } } } } });

        await page.loadPendingAssignments({ background: true });
        await settle();

        assert.deepEqual(paths(), ['/api/action-items', '/api/assignment/pending']);
        assert.ok(browser.requests.every(marked));
    });
});

describe('Beranda reads the student starts while a re-read is still waiting', () => {
    it('a pull-to-refresh stays the student\'s own', async () => {
        // The re-reads of a tab shown again never answer, so they are still open below.
        const { document, loadDashboard, loadActionCenter } = await openDashboard({ hold: (request) => !request.url.searchParams.has('fresh') });

        app.registerSyncHandler('dashboard-overview', loadDashboard);
        app.registerSyncHandler('dashboard-action-items', loadActionCenter);
        document.dispatchEvent(new Event('visibilitychange'));
        await settle();
        assert.ok(browser.requests.length === 2 && browser.requests.every(marked));

        // bindPullToRefresh: the student's own forced re-read of every widget.
        await app.runSyncHandlers({ force: true });
        await settle();

        const forced = browser.requests.filter((request) => request.url.searchParams.has('fresh'));

        assert.deepEqual(forced.map((request) => request.url.pathname).sort(), ['/api/action-items', '/api/dashboard']);
        assert.ok(forced.every((request) => !marked(request)), 'the student\'s refresh counts as activity');
    });

    it('the student\'s own read of a card stays theirs while its re-read waits', async () => {
        const page = await openDashboard({ hold: (request) => request.headers[BACKGROUND_HEADER] === '1' });

        void page.loadAnnouncements({ background: true });
        await settle();
        await page.loadAnnouncements();
        await settle();

        assert.deepEqual(reads('/api/announcement').map(marked), [true, false]);
    });
});
