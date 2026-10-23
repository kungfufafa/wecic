import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp, json, settle } from './support/browser.mjs';

/**
 * PortalApp.updateConnectionState and the way requests move the shell
 * between online and offline (resources/js/app.js).
 */
let browser;

afterEach(() => browser?.dispose());

async function shell(options = {}) {
    const created = await createPortalApp(options);
    const notices = [];
    const showNotice = created.app.showNotice.bind(created.app);

    browser = created.browser;
    browser.element('span', { 'data-connection-chip': '', class: 'mycic-chip-online' });
    browser.element('span', { 'data-connection-label': '' }).textContent = 'Online';
    browser.element('p', { 'data-sync-status': '' });
    browser.element('button', { 'data-sync-refresh': '' });
    browser.element('button', { 'data-online-required': '' });
    created.app.showNotice = (message, type) => {
        notices.push([message, type]);
        showNotice(message, type);
    };

    return { app: created.app, notices };
}

const chip = () => browser.document.querySelector('[data-connection-chip]');
const label = () => browser.document.querySelector('[data-connection-label]').textContent;
const syncStatus = () => browser.document.querySelector('[data-sync-status]');
const onlineRequired = () => browser.document.querySelector('[data-online-required]');
const syncRefresh = () => browser.document.querySelector('[data-sync-refresh]');

function goOffline(app, options) {
    browser.navigator.onLine = false;
    app.updateConnectionState(false, options);
}

function goOnline(app, options) {
    browser.navigator.onLine = true;
    app.updateConnectionState(true, options);
}

describe('updateConnectionState', () => {
    it('a signed-in page that loads online connects realtime and shows the default status', async () => {
        const { app, notices } = await shell();

        app.updateConnectionState(true, { announce: false });

        assert.equal(chip().className, 'mycic-chip-online');
        assert.equal(label(), 'Online');
        assert.equal(browser.echoes.length, 1);
        assert.equal(syncStatus().textContent, 'Data siap dipakai.');
        assert.deepEqual(notices, []);
    });

    it('keeps a status a page already wrote', async () => {
        const { app } = await shell();

        syncStatus().textContent = 'Menampilkan ringkasan dashboard tersimpan.';
        app.updateConnectionState(true, { announce: false });

        assert.equal(syncStatus().textContent, 'Menampilkan ringkasan dashboard tersimpan.');
    });

    it('a guest page never opens a socket', async () => {
        const { app } = await shell({ auth: 'guest', page: 'login' });

        app.updateConnectionState(true, { announce: false });
        goOffline(app);
        goOnline(app);

        assert.equal(browser.echoes.length, 0);
    });

    it('going offline marks the shell offline, closes the socket and says so once', async () => {
        const { app, notices } = await shell();

        app.updateConnectionState(true, { announce: false });
        goOffline(app);

        assert.equal(chip().className, 'mycic-chip-offline');
        assert.equal(label(), 'Offline');
        assert.equal(onlineRequired().disabled, true);
        assert.equal(onlineRequired().getAttribute('title'), 'Butuh koneksi internet');
        assert.equal(syncRefresh().disabled, true);
        assert.equal(browser.echoes[0].disconnected, true);
        assert.equal(app.echo, null);
        assert.equal(syncStatus().textContent, 'Data terakhir dipakai bila tersedia.');
        assert.equal(syncStatus().dataset.tone, 'warning');

        goOffline(app);

        assert.deepEqual(notices, [['Koneksi internet terputus. Menampilkan data terakhir yang tersimpan.', 'warning']]);
    });

    it('a page that opens offline does not announce it', async () => {
        const { app, notices } = await shell({ online: false });

        app.updateConnectionState(false, { announce: false });

        assert.equal(chip().className, 'mycic-chip-offline');
        assert.deepEqual(notices, []);
        assert.equal(browser.echoes.length, 0);
    });

    it('coming back online reconnects and re-reads the page in the background', async () => {
        const { app, notices } = await shell();
        const handlerCalls = [];

        browser.server = () => json(200, { status: 'success', data: { schedule: [] } });
        app.registerSyncHandler('dashboard', async (options) => {
            handlerCalls.push(options);
            await app.request('/api/dashboard');
        });
        app.updateConnectionState(true, { announce: false });
        goOffline(app);
        goOnline(app);
        await settle();

        assert.equal(chip().className, 'mycic-chip-online');
        assert.equal(label(), 'Online');
        assert.equal(onlineRequired().disabled, false);
        assert.equal(onlineRequired().hasAttribute('title'), false);
        assert.equal(syncRefresh().disabled, false);
        assert.equal(browser.echoes.length, 2);
        assert.equal(app.echo, browser.echoes[1]);
        assert.deepEqual(handlerCalls, [{ force: false, background: true }]);
        assert.equal(browser.requests.length, 1);
        assert.equal(browser.requests[0].headers['X-MyCIC-Background'], '1', 'a reconnect is not the student\'s activity');
        assert.deepEqual(notices.at(-1), ['Koneksi kembali. Data aplikasi sedang dimuat ulang.', 'info']);
    });
});

describe('requests and the connection state', () => {
    it('a network error flips the shell offline quietly, and the next answer brings it back', async () => {
        const { app, notices } = await shell();
        let reachable = false;

        app.updateConnectionState(true, { announce: false });
        browser.server = () => {
            if (!reachable) {
                throw new TypeError('Failed to fetch');
            }

            return json(200, { status: 'success', data: { unread_count: 0 } });
        };

        await assert.rejects(app.request('/api/notifications/unread-count'), (error) => {
            assert.equal(error.isOffline, true);
            assert.equal(error.message, 'Koneksi internet terputus saat memuat data.');

            return true;
        });
        assert.equal(chip().className, 'mycic-chip-offline');
        assert.equal(app.echo, null);

        reachable = true;
        assert.deepEqual(await app.request('/api/notifications/unread-count'), { status: 'success', data: { unread_count: 0 } });

        assert.equal(chip().className, 'mycic-chip-online');
        assert.equal(label(), 'Online');
        assert.equal(browser.echoes.length, 2, 'realtime is joined again');
        assert.deepEqual(notices, [], 'a hiccup the browser never reported is not announced');
    });

    it('a network error falls back to the device copy of a kept answer', async () => {
        const { app } = await shell();

        browser.server = () => json(200, { status: 'success', data: { name: 'Dashboard' } });
        await app.request('/api/dashboard');
        browser.server = () => {
            throw new TypeError('Load failed');
        };

        const payload = await app.request('/api/dashboard');

        assert.equal(payload.data.name, 'Dashboard');
        assert.equal(payload.meta.cache_mode, 'fallback');
        assert.equal(chip().className, 'mycic-chip-offline');
        assert.equal(syncStatus().dataset.tone, 'warning');
    });

    it('while the browser is offline, requests use the device copy or fail without fetching', async () => {
        const { app } = await shell();

        browser.server = () => json(200, { status: 'success', data: { name: 'Dashboard' } });
        await app.request('/api/dashboard');
        browser.navigator.onLine = false;

        assert.equal((await app.request('/api/dashboard')).meta.cache_mode, 'offline');
        await assert.rejects(app.request('/api/profile'), (error) => error.isOffline === true
            && error.message === 'Koneksi internet tidak tersedia dan data offline belum tersimpan.');
        await assert.rejects(app.request('/api/attendance', { method: 'POST', json: { code: 'AB12' } }), (error) => error.isOffline === true
            && error.message === 'Kamu sedang offline. Sambungkan internet untuk mengirim perubahan.');
        assert.equal(browser.requests.length, 1, 'only the first, online request reached the network');
    });

    it('a request the server never answers stops after its timeout', async () => {
        const { app } = await shell();

        browser.server = () => new Promise(() => {});

        const pending = assert.rejects(app.request('/api/profile'), (error) => error.isTimeout === true
            && error.message === 'Server kampus lambat merespons. Coba lagi sebentar lagi.');

        await settle();
        await browser.clock.advance(29999);
        assert.equal(browser.clock.timers.size, 1, 'still waiting just before 30 s');
        await browser.clock.advance(1);
        await pending;

        assert.equal(chip().className, 'mycic-chip-online', 'a slow server is not an offline device');
    });

    it('sends the CSRF token and marks only GETs made by background work', async () => {
        const { app } = await shell({ csrfToken: 'token-123' });

        browser.server = () => json(200, { status: 'success' });
        await app.request('/api/profile');
        await app.runAsBackground(() => app.request('/api/profile'));
        await app.runAsBackground(() => app.request('/api/attendance', { method: 'POST', json: { code: 'AB12' } }));

        assert.deepEqual(browser.requests.map((request) => [request.method, request.headers['X-CSRF-TOKEN'], request.headers['X-MyCIC-Background'] ?? null]), [
            ['GET', 'token-123', null],
            ['GET', 'token-123', '1'],
            ['POST', 'token-123', null],
        ]);
    });

    it('a read the student starts while background work still waits stays the student\'s own', async () => {
        const { app } = await shell();
        const answers = [];

        browser.server = (request) => (request.url.pathname === '/api/dashboard'
            ? new Promise((resolve) => answers.push(() => resolve(json(200, { status: 'success' }))))
            : json(200, { status: 'success' }));

        const work = app.runAsBackground(async ({ background }) => {
            await app.request('/api/dashboard', { background });
            await app.request('/api/action-items', { background });
        });

        await settle();
        await app.request('/api/profile');
        await app.request('/api/announcement', { background: false });
        answers.forEach((answer) => answer());
        await work;

        assert.deepEqual(browser.requests.map((request) => [request.url.pathname, request.headers['X-MyCIC-Background'] ?? null]), [
            ['/api/dashboard', '1'],
            ['/api/profile', null],
            ['/api/announcement', null],
            ['/api/action-items', '1'],
        ]);
    });

    it('background work marks the read it starts before it first waits, and later ones only when it passes the flag on', async () => {
        const { app } = await shell();

        browser.server = () => json(200, { status: 'success' });

        await app.runAsBackground(async () => {
            await app.request('/api/dashboard');
            await app.request('/api/profile');
        });
        await app.runAsBackground(async (options) => {
            await app.request('/api/dashboard', options);
            await app.request('/api/profile', options);
        });

        assert.deepEqual(browser.requests.map((request) => request.headers['X-MyCIC-Background'] ?? null), ['1', null, '1', '1']);
    });

    it('a task that fails before it waits still ends its background scope', async () => {
        const { app } = await shell();

        browser.server = () => json(200, { status: 'success' });

        await assert.rejects(app.runAsBackground(() => {
            throw new Error('broken handler');
        }), /broken handler/);
        await app.request('/api/profile');

        assert.equal(browser.requests[0].headers['X-MyCIC-Background'], undefined);
    });
});

describe('forgetOfflineRetries', () => {
    it('a page of the app that loads lets the offline page start again from the shortest wait', async () => {
        const { app } = await shell();

        browser.sessionStorage.setItem('mycic-offline-retry', JSON.stringify({ attempt: 4, at: 1 }));
        browser.sessionStorage.setItem('portal-notice', 'kept');
        app.forgetOfflineRetries();

        assert.equal(browser.sessionStorage.getItem('mycic-offline-retry'), null);
        assert.equal(browser.sessionStorage.getItem('portal-notice'), 'kept');
    });

    it('blocked storage does not stop the page', async () => {
        const { app } = await shell();

        browser.sessionStorage.removeItem = () => {
            throw new DOMException('denied', 'SecurityError');
        };

        assert.doesNotThrow(() => app.forgetOfflineRetries());
    });
});
