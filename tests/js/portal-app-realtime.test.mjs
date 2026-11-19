import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createPortalApp, json, settle } from './support/browser.mjs';
import { ui } from '../../resources/js/app/ui.js';

/**
 * PortalApp's realtime side (resources/js/app.js) against a fake Echo: the
 * channel it joins, the events it listens for, and what a session notice
 * broadcast from another tab or a background job does to this tab.
 */
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * The events the server broadcasts (App\Enums\PortalRealtimeEvent).
 */
const serverEvents = [...read('app/Enums/PortalRealtimeEvent.php').matchAll(/case \w+ = '([^']+)';/g)].map((match) => match[1]);

/**
 * The private channel routes/channels.php authorizes for a session.
 */
const channelPattern = /Broadcast::channel\('([^']+)'/.exec(read('routes/channels.php'))[1];
const serverChannelFor = (sessionId) => channelPattern.replace('{sessionId}', sessionId);

const authSnapshotKey = 'mycic-cache-v3:auth';
const dashboardCopyKey = 'mycic-cache-v3:response:/api/dashboard';

let browser;

afterEach(() => browser?.dispose());

async function signedInApp(options = {}) {
    const created = await createPortalApp(options);

    browser = created.browser;
    browser.element('span', { 'data-realtime-label': '' });
    browser.element('span', { 'data-realtime-chip': '', class: ui.chipOnline });
    browser.element('p', { 'data-sync-status': '' });

    return created.app;
}

function onlyChannel() {
    assert.equal(browser.echoes.length, 1, 'one socket per page');

    const channels = [...browser.echoes[0].channels.values()];

    assert.equal(channels.length, 1);

    return channels[0];
}

const realtimeLabel = () => browser.document.querySelector('[data-realtime-label]').textContent;
const realtimeChip = () => browser.document.querySelector('[data-realtime-chip]');
const syncStatus = () => browser.document.querySelector('[data-sync-status]');

function seedSignedInDevice(app) {
    app.storeAuthSnapshot({ status: 'success', data: { user_info: { nim: '2201010001', name: 'Rina' } } });
    browser.localStorage.setItem(dashboardCopyKey, JSON.stringify({ payload: { data: {} } }));
}

describe('realtime channel', () => {
    it('reads the server\'s event names', () => {
        assert.ok(serverEvents.includes('portal.session.notice'), serverEvents.join(', '));
        assert.ok(serverEvents.length >= 5);
        assert.equal(serverChannelFor('abc'), 'portal.session.abc');
    });

    it('joins the private channel of its own session and listens for every event the server broadcasts', async () => {
        const app = await signedInApp({ sessionId: 'Zx9LaravelSession' });

        app.initRealtime();

        const channel = onlyChannel();

        assert.equal(channel.name, serverChannelFor('Zx9LaravelSession'));
        assert.deepEqual([...channel.listeners.keys()].sort(), serverEvents.map((event) => `.${event}`).sort());
    });

    it('reports updates as active only once the channel was joined', async () => {
        const app = await signedInApp();

        app.initRealtime();

        assert.equal(realtimeLabel(), 'Menghubungkan real-time...');
        assert.equal(realtimeChip().className, ui.chipOffline);
        assert.equal(app.realtimeConnected, false);

        onlyChannel().onSubscribed();

        assert.equal(realtimeLabel(), 'Pembaruan otomatis aktif.');
        assert.equal(realtimeChip().className, ui.chipOnline);
        assert.equal(app.realtimeConnected, true);

        onlyChannel().onError();

        assert.equal(realtimeLabel(), 'Pembaruan otomatis tidak tersedia.');
        assert.equal(app.realtimeConnected, false);
    });

    it('opens no socket on a guest page, while offline, or a second time', async () => {
        let app = await signedInApp({ auth: 'guest', page: 'login' });

        app.initRealtime();
        assert.equal(browser.echoes.length, 0, 'guest page');

        app = await signedInApp({ online: false });
        app.initRealtime();
        assert.equal(browser.echoes.length, 0, 'offline');

        app = await signedInApp();
        app.initRealtime();
        app.initRealtime();
        assert.equal(browser.echoes.length, 1, 'already connected');
    });

    it('waits for a session id before joining any channel', async () => {
        const app = await signedInApp({ sessionId: '' });

        app.initRealtime();

        assert.equal(browser.echoes.length, 1);
        assert.equal(browser.echoes[0].channels.size, 0);
        assert.equal(realtimeLabel(), 'Menunggu sesi real-time...');
    });

    it('says automatic updates are off when Reverb is not configured', async () => {
        const app = await signedInApp({ reverb: false });

        app.initRealtime();

        assert.equal(app.echo, null);
        assert.equal(realtimeLabel(), 'Pembaruan otomatis belum aktif.');
    });
});

describe('session notice', () => {
    it('a logout in another tab sends this tab to the login page and drops its device copies', async () => {
        const app = await signedInApp();

        seedSignedInDevice(app);
        app.initRealtime();
        onlyChannel().receive('.portal.session.notice', { status: 'logged_out', message: 'Akun keluar dari perangkat ini.', urgency: 'warning' });

        assert.deepEqual(browser.location.assignments, ['/login']);
        assert.equal(browser.localStorage.getItem(authSnapshotKey), null);
        assert.equal(browser.localStorage.getItem(dashboardCopyKey), null);
        assert.deepEqual(browser.caches.deleted, ['mycic-pages-v2']);
        assert.equal(browser.echoes[0].disconnected, true);
        assert.equal(app.echo, null);
        assert.equal(syncStatus().textContent, 'Akun keluar dari perangkat ini.');
        assert.deepEqual(browser.toasts(), [{ message: 'Akun keluar dari perangkat ini.', tone: 'warning' }]);
    });

    it('a campus session that must be signed into again sends the tab to the login page', async () => {
        const app = await signedInApp();

        seedSignedInDevice(app);
        app.initRealtime();
        onlyChannel().receive('.portal.session.notice', { status: 'manual_relogin_required', message: 'Sesi portal kedaluwarsa. Silakan login ulang.', urgency: 'error' });

        assert.deepEqual(browser.location.assignments, ['/login']);
        assert.equal(browser.localStorage.getItem(authSnapshotKey), null);
        assert.deepEqual(browser.toasts(), [{ message: 'Sesi portal kedaluwarsa. Silakan login ulang.', tone: 'danger' }]);
    });

    it('the tab that pressed Keluar ignores its own logout notice and finishes the request itself', async () => {
        const app = await signedInApp();

        seedSignedInDevice(app);
        app.initRealtime();
        app.isLoggingOut = true;
        onlyChannel().receive('.portal.session.notice', { status: 'logged_out', message: 'Akun keluar dari perangkat ini.', urgency: 'warning' });

        assert.deepEqual(browser.location.assignments, []);
        assert.equal(browser.echoes[0].disconnected, false);
        assert.notEqual(browser.localStorage.getItem(authSnapshotKey), null);
        assert.deepEqual(browser.toasts(), []);
    });

    it('a resumed offline session opens portal actions again without leaving the page', async () => {
        const app = await signedInApp({ portalOffline: true });
        const banner = browser.element('div', { 'data-portal-offline-banner': '' });
        const attendButton = browser.element('button', { 'data-online-required': '' });
        let resumedEvents = 0;

        browser.window.addEventListener('portal:online-resumed', () => {
            resumedEvents += 1;
        });
        app.refreshOnlineRequiredState();

        assert.equal(attendButton.disabled, true);
        assert.equal(attendButton.getAttribute('title'), 'Tidak tersedia di mode offline');

        app.initRealtime();
        onlyChannel().receive('.portal.session.notice', {
            status: 'offline_session_resumed',
            message: 'Portal kampus kembali bisa diakses. Sesi tersambung lagi dan data sedang diperbarui.',
            urgency: 'success',
        });

        assert.equal(browser.document.body.dataset.portalOffline, '0');
        assert.equal(banner.parentNode, null);
        assert.equal(attendButton.disabled, false);
        assert.equal(attendButton.hasAttribute('aria-disabled'), false);
        assert.equal(resumedEvents, 1);
        assert.deepEqual(browser.location.assignments, []);
        assert.equal(browser.echoes[0].disconnected, false);
        assert.deepEqual(browser.toasts(), [{ message: 'Portal kampus kembali bisa diakses. Sesi tersambung lagi dan data sedang diperbarui.', tone: 'success' }]);
    });

    it('other notices only inform', async () => {
        const app = await signedInApp();

        seedSignedInDevice(app);
        app.initRealtime();

        const channel = onlyChannel();

        channel.receive('.portal.session.notice', { status: 'auto_relogin_succeeded', message: 'Sesi portal berhasil diperbarui otomatis.', urgency: 'success' });
        channel.receive('.portal.session.notice', { status: 'offline_resume_unavailable', message: 'Sesi offline ini tidak bisa tersambung otomatis.', urgency: 'warning' });
        channel.receive('.portal.session.notice', {});

        assert.deepEqual(browser.location.assignments, []);
        assert.notEqual(browser.localStorage.getItem(authSnapshotKey), null);
        assert.equal(browser.echoes[0].disconnected, false);
        assert.deepEqual(browser.toasts().map((toast) => toast.message), [
            'Sesi portal berhasil diperbarui otomatis.',
            'Sesi offline ini tidak bisa tersambung otomatis.',
            'Status sesi portal berubah.',
        ]);
    });

    it('a page event cannot fake a session notice', async () => {
        const app = await signedInApp();

        assert.equal(typeof app.bindRealtimeTestHook, 'undefined');
        browser.window.dispatchEvent(new CustomEvent('portal:test-session-notice', {
            detail: { status: 'logged_out', message: 'Akun keluar dari perangkat ini.', urgency: 'warning' },
        }));

        assert.deepEqual(browser.location.assignments, []);
    });

    it('a resume that cannot log in by itself rewrites the offline banner', async () => {
        const app = await signedInApp();
        const banner = browser.element('span', { 'data-portal-offline-reconnect-text': '' });

        browser.document.body.dataset.portalOfflineReconnect = 'notify';
        app.handleSessionNotice({ status: 'offline_resume_unavailable', message: 'Portal kampus sudah bisa diakses lagi.', urgency: 'info' });

        assert.equal(browser.document.body.dataset.portalOfflineReconnect, 'portal_back');
        assert.equal(banner.textContent, 'Portal kampus sudah bisa diakses lagi.');
        assert.deepEqual(browser.location.assignments, []);
    });
});

describe('socket drops', () => {
    async function connectedApp() {
        const app = await signedInApp();
        const handlerCalls = [];

        browser.server = (request) => (request.url.pathname === '/api/notifications/unread-count'
            ? json(200, { data: { unread_count: 2 } })
            : json(404, { message: 'Not Found' }));
        app.registerSyncHandler('dashboard', async (options) => {
            handlerCalls.push(options);
        });
        app.initRealtime();
        onlyChannel().onSubscribed();

        return { app, handlerCalls, connection: browser.echoes[0].connection };
    }

    const unreadCountRequests = () => browser.requests.filter((request) => request.url.pathname === '/api/notifications/unread-count');

    it('a reconnect after a drop reads again what was broadcast meanwhile, in the background', async () => {
        const { app, handlerCalls, connection } = await connectedApp();

        connection.change('connecting', 'connected');
        await settle();
        assert.equal(handlerCalls.length, 0, 'a first connect is not a gap');

        connection.change('connected', 'connecting');

        assert.equal(realtimeLabel(), 'Menyambung ulang pembaruan otomatis...');
        assert.equal(app.realtimeConnected, false);

        connection.change('connecting', 'connected');
        await settle();

        assert.deepEqual(handlerCalls, [{ force: false, background: true }]);
        assert.equal(unreadCountRequests().length, 1);
        assert.equal(unreadCountRequests()[0].headers['X-MyCIC-Background'], '1');
    });

    it('a catch-up does not re-render over a form the student is filling', async () => {
        const { handlerCalls, connection } = await connectedApp();
        const form = browser.element('form');
        const codeInput = browser.element('input', { name: 'code', value: '' }, form);

        codeInput.value = 'AB12';
        connection.change('connected', 'unavailable');

        assert.equal(realtimeLabel(), 'Pembaruan otomatis tidak tersedia.');

        connection.change('unavailable', 'connected');
        await settle();

        assert.deepEqual(handlerCalls, []);
        assert.equal(unreadCountRequests().length, 1, 'the bell count is still read again');
    });

    it('ignores state changes of a socket the page already closed', async () => {
        const { app, handlerCalls, connection } = await connectedApp();

        app.disconnectRealtime();
        connection.change('connected', 'unavailable');
        connection.change('unavailable', 'connected');
        await settle();

        assert.equal(realtimeLabel(), 'Pembaruan otomatis terputus.');
        assert.deepEqual(handlerCalls, []);
        assert.equal(unreadCountRequests().length, 0);
    });
});
