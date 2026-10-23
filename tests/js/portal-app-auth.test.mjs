import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp, json, settle } from './support/browser.mjs';

/**
 * PortalApp's session handling (resources/js/app.js): the session check
 * pages run before loading data, the page restored from the back/forward
 * cache, and Keluar together with the logout notice it broadcasts.
 */
const authSnapshotKey = 'mycic-cache-v3:auth';
const ownerKey = 'mycic-cache-v3:owner';
const sessionPayload = { status: 'success', data: { user_info: { nim: '2201010001', name: 'Rina Wati' } } };

let browser;

afterEach(() => browser?.dispose());

async function page(options = {}) {
    const created = await createPortalApp(options);

    browser = created.browser;
    browser.element('span', { 'data-user-name': '', class: 'hidden' });
    browser.element('span', { 'data-user-identity': '', class: 'hidden' });
    browser.element('span', { 'data-user-avatar': '' });
    browser.element('p', { 'data-sync-status': '' });

    return created.app;
}

const sessionChecks = () => browser.requests.filter((request) => request.url.pathname === '/api/auth/session');
const text = (selector) => browser.document.querySelector(selector).textContent;
const storedNotice = () => JSON.parse(browser.sessionStorage.getItem('portal-notice'));

describe('ensureAuthenticated', () => {
    it('checks the session once for every caller, keeps the identity and shows it', async () => {
        const app = await page();

        browser.server = () => json(200, sessionPayload);

        const [first, second] = await Promise.all([app.ensureAuthenticated(), app.ensureAuthenticated()]);

        assert.deepEqual(first, sessionPayload);
        assert.equal(second, first);
        assert.equal(sessionChecks().length, 1);
        assert.deepEqual(app.getAuthSnapshot(), sessionPayload);
        assert.equal(text('[data-user-name]'), 'Rina Wati');
        assert.equal(text('[data-user-identity]'), '2201010001');
        assert.equal(browser.document.querySelector('[data-user-identity]').classList.contains('hidden'), false);
        assert.equal(text('[data-user-avatar]'), 'R');

        await app.ensureAuthenticated();
        assert.equal(sessionChecks().length, 1, 'a verified session is not asked again');

        await app.ensureAuthenticated({ force: true });
        assert.equal(sessionChecks().length, 2, 'force asks the server again');
    });

    it('an ended campus session on a signed-in page goes to the login page with the reason', async () => {
        const app = await page();

        app.storeAuthSnapshot(sessionPayload);
        browser.server = () => json(401, { message: 'Sesi kampus sudah berakhir.' });

        assert.equal(await app.ensureAuthenticated(), null);
        assert.deepEqual(browser.location.assignments, ['/login']);
        assert.equal(browser.localStorage.getItem(authSnapshotKey), null);
        assert.deepEqual(storedNotice(), { message: 'Sesi kampus sudah berakhir.', type: 'error' });
    });

    it('an expired app session (419) goes to the login page with words a student understands', async () => {
        const app = await page();

        browser.server = () => json(419, { message: 'CSRF token mismatch.' });

        assert.equal(await app.ensureAuthenticated(), null);
        assert.deepEqual(browser.location.assignments, ['/login']);
        assert.deepEqual(storedNotice(), { message: 'Sesi aplikasi sudah berakhir. Silakan masuk lagi.', type: 'warning' });
    });

    it('on a guest page an ended session is reported in place, and the next call asks again', async () => {
        const app = await page({ auth: 'guest', page: 'login' });

        browser.server = () => json(419, { message: 'CSRF token mismatch.' });

        assert.equal(await app.ensureAuthenticated(), null);
        assert.deepEqual(browser.location.assignments, [], 'a guest page never redirects to itself');
        assert.deepEqual(browser.toasts(), [{ message: 'Halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.', tone: 'danger' }]);

        browser.server = () => json(200, sessionPayload);

        assert.deepEqual(await app.ensureAuthenticated(), sessionPayload);
        assert.equal(sessionChecks().length, 2);
    });

    it('a check the server cannot finish keeps the page usable with the last known identity', async () => {
        const app = await page();

        app.storeAuthSnapshot(sessionPayload);
        browser.server = () => json(503, { message: 'Service Unavailable' });

        assert.deepEqual(await app.ensureAuthenticated(), sessionPayload);
        assert.deepEqual(browser.location.assignments, []);
        assert.equal(text('[data-user-name]'), 'Rina Wati');
        assert.equal(text('[data-sync-status]'), 'Sesi belum bisa diperiksa ulang. Data tetap dimuat bila server siap.');
        assert.deepEqual(app.getAuthSnapshot(), sessionPayload, 'the identity is kept for the next page');
    });

    it('without a kept identity the page still loads, unverified', async () => {
        const app = await page();

        browser.server = () => json(502, null);

        assert.deepEqual(await app.ensureAuthenticated(), { status: 'unverified', data: null });
        assert.deepEqual(browser.location.assignments, []);
        assert.equal(text('[data-sync-status]'), 'Sesi belum bisa diperiksa ulang. Data tetap dimuat bila server siap.');
    });

    it('offline, it uses the kept identity without asking the server', async () => {
        const app = await page({ online: false });

        app.storeAuthSnapshot(sessionPayload);

        assert.deepEqual(await app.ensureAuthenticated(), sessionPayload);
        assert.equal(browser.requests.length, 0);
        assert.equal(text('[data-sync-status]'), 'Offline. Identitas dan data terakhir tetap tersedia.');
    });

    it('a session check that hangs ends after 30 s and the page goes on', async () => {
        const app = await page();

        browser.server = () => new Promise(() => {});

        const result = app.ensureAuthenticated();

        await settle();
        await browser.clock.advance(30000);

        assert.deepEqual(await result, { status: 'unverified', data: null });
        assert.equal(text('[data-sync-status]'), 'Sesi belum bisa diperiksa ulang. Data tetap dimuat bila server siap.');
    });

    it('a check the app makes on its own (a timer, a tab shown again, a realtime event) is not the student\'s activity', async () => {
        const app = await page();

        browser.server = () => json(200, sessionPayload);

        await app.ensureAuthenticated();
        await app.runAsBackground(() => app.ensureAuthenticated({ force: true }));
        await app.ensureAuthenticated({ force: true, background: true });
        await app.ensureAuthenticated({ force: true });

        assert.deepEqual(sessionChecks().map((request) => request.headers['X-MyCIC-Background'] ?? null), [null, '1', '1', null]);
    });
});

describe('page restored from the back/forward cache', () => {
    function restore() {
        const event = new Event('pageshow');

        event.persisted = true;
        browser.window.dispatchEvent(event);
    }

    it('is hidden and reloaded once the session ended (Keluar, or another student signed in)', async () => {
        const app = await page({ cacheOwner: 'a'.repeat(32) });

        app.bindHistoryRestore();
        app.storeAuthSnapshot(sessionPayload);
        browser.localStorage.setItem(ownerKey, 'b'.repeat(32));
        restore();

        assert.equal(browser.document.body.hidden, true);
        assert.equal(browser.location.reloads, 1);

        browser.document.body.hidden = false;
        browser.localStorage.setItem(ownerKey, 'a'.repeat(32));
        browser.localStorage.removeItem(authSnapshotKey);
        restore();

        assert.equal(browser.location.reloads, 2, 'no identity left: Keluar ran');
    });

    it('asks the server again when the same student is still signed in', async () => {
        const app = await page({ cacheOwner: 'a'.repeat(32) });

        browser.server = () => json(200, sessionPayload);
        app.bindHistoryRestore();
        app.storeAuthSnapshot(sessionPayload);
        browser.localStorage.setItem(ownerKey, 'a'.repeat(32));
        restore();
        await settle();

        assert.equal(browser.document.body.hidden, false);
        assert.equal(browser.location.reloads, 0);
        assert.equal(sessionChecks().length, 1);
        assert.equal(sessionChecks()[0].headers['X-MyCIC-Background'], undefined, 'going back is the student\'s own navigation');
    });
});

describe('Keluar', () => {
    async function signedInTab() {
        const app = await page();
        const button = browser.element('button', { 'data-logout': '' });

        app.storeAuthSnapshot(sessionPayload);
        browser.localStorage.setItem('mycic-cache-v3:response:/api/dashboard', '{}');
        app.bindLogout();
        app.initRealtime();

        return { app, button, channel: [...browser.echoes[0].channels.values()][0] };
    }

    async function click(button) {
        button.dispatchEvent(new Event('click', { cancelable: true }));
        await settle();
    }

    it('closes the session, drops the device copies and goes to the login page once, despite its own broadcast', async () => {
        const { app, button, channel } = await signedInTab();

        browser.server = (request) => {
            // The server broadcasts the notice before it answers.
            channel.receive('.portal.session.notice', { status: 'logged_out', message: 'Akun keluar dari perangkat ini.', urgency: 'warning' });

            return json(200, { status: 'success', message: 'Logout berhasil' });
        };
        await click(button);

        assert.equal(browser.requests.length, 1);
        assert.equal(browser.requests[0].url.pathname, '/api/auth/logout');
        assert.equal(browser.requests[0].method, 'POST');
        assert.equal(browser.requests[0].headers['X-CSRF-TOKEN'], 'csrf-token-1');
        assert.deepEqual(browser.location.assignments, ['/login']);
        assert.deepEqual(storedNotice(), { message: 'Logout berhasil', type: 'success' });
        assert.equal(browser.localStorage.getItem(authSnapshotKey), null);
        assert.equal(browser.localStorage.getItem('mycic-cache-v3:response:/api/dashboard'), null);
        assert.equal(browser.echoes[0].disconnected, true);
        assert.equal(app.echo, null);
    });

    it('a session that already ended in another tab counts as signed out', async () => {
        const { button } = await signedInTab();

        browser.server = () => json(419, { message: 'CSRF token mismatch.' });
        await click(button);

        assert.deepEqual(browser.location.assignments, ['/login']);
        assert.deepEqual(storedNotice(), { message: 'Kamu sudah keluar dari akun.', type: 'success' });
    });

    it('a failed logout keeps the student on the page and says the session is still open', async () => {
        const { app, button, channel } = await signedInTab();

        browser.server = () => json(503, { message: 'Service Unavailable' });
        await click(button);

        assert.deepEqual(browser.location.assignments, []);
        assert.equal(app.isLoggingOut, false);
        assert.equal(button.disabled, false);
        assert.notEqual(browser.localStorage.getItem(authSnapshotKey), null);
        assert.equal(text('[data-sync-status]'), 'Belum berhasil keluar. Sesi masih aktif di perangkat ini.');
        assert.deepEqual(browser.toasts(), [{ message: 'Belum berhasil keluar. Server sedang bermasalah. Coba lagi sebentar lagi.', tone: 'danger' }]);

        channel.receive('.portal.session.notice', { status: 'logged_out', message: 'Akun keluar dari perangkat ini.', urgency: 'warning' });

        assert.deepEqual(browser.location.assignments, ['/login'], 'a later logout from another tab is followed again');
    });
});
