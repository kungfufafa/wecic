import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';

/**
 * The sync strip (resources/js/app/connection.js and requests.js): which
 * datasets "Sinkronkan data" asks the server to rebuild for a page, and the
 * freshness line a page writes after a read.
 */
let browser;
let app;

afterEach(() => browser?.dispose());

async function open(options = {}, refreshable = 'dashboard,assignments,classes,krs,advising') {
    ({ app, browser } = await createPortalApp(options));

    if (refreshable !== null) {
        browser.document.body.setAttribute('data-refreshable-datasets', refreshable);
    }

    browser.element('div', { 'data-sync-alert': '', class: 'mycic-sync-strip' });
    browser.element('span', { 'data-sync-status': '' });
}

const line = () => browser.document.querySelector('[data-sync-status]');
const strip = () => browser.document.querySelector('[data-sync-alert]').className;

describe('refreshDomainsForCurrentPage', () => {
    it('asks only for the datasets the server can rebuild in the background', async () => {
        await open({ page: 'dashboard' });

        assert.deepEqual(app.refreshDomainsForCurrentPage(), ['dashboard', 'assignments', 'krs']);
    });

    it('keeps every dataset when the layout lists none', async () => {
        await open({ page: 'krs-index' }, null);

        assert.deepEqual(app.refreshDomainsForCurrentPage(), ['krs', 'advising']);
    });

    it('never scrapes the portal for a detail page or a page it does not know', async () => {
        await open({ page: 'meeting-show' });
        assert.deepEqual(app.refreshDomainsForCurrentPage(), []);

        browser.document.body.setAttribute('data-page', 'halaman-baru');
        assert.deepEqual(app.refreshDomainsForCurrentPage(), []);
    });
});

describe('setSyncStatus', () => {
    it('writes the line and tints the strip by tone', async () => {
        await open();

        app.setSyncStatus('Portal lambat.', 'error');
        assert.equal(line().textContent, 'Portal lambat.');
        assert.equal(line().dataset.tone, 'error');
        assert.equal(strip(), 'mycic-sync-strip mycic-sync-strip-danger');

        app.setSyncStatus('Siap.', 'info');
        assert.equal(strip(), 'mycic-sync-strip');
    });
});

describe('markViewSynced', () => {
    it('warns about data the server flagged as suspect, before anything else', async () => {
        await open();

        app.markViewSynced('KRS', { meta: { integrity: { status: 'suspect' }, status: 'offline' } });

        assert.match(line().textContent, /^Data KRS dari portal terlihat janggal\./);
        assert.equal(line().dataset.tone, 'warning');
    });

    it('names the archive in an offline session', async () => {
        await open();

        app.markViewSynced('KRS', { meta: { status: 'offline' } });

        assert.equal(line().textContent, 'Mode offline. Menampilkan arsip WeCIC.');
    });

    it('says a snapshot is being rebuilt while it shows it', async () => {
        await open();

        app.markViewSynced('KRS', { meta: { data_origin: 'snapshot', status: 'syncing' } });

        assert.equal(line().textContent, 'Data baru saja dimuat · sedang diperbarui dari portal');
        assert.equal(line().dataset.tone, 'info');
    });

    it('admits a forced sync that only reached the device copy', async () => {
        await open();

        app.markViewSynced('KRS', { meta: { data_origin: 'device_cache' } }, { force: true });

        assert.equal(line().textContent, 'Server belum terjangkau. Menampilkan data tersimpan di perangkat.');
        assert.equal(line().dataset.tone, 'warning');
    });

    it('reads a fresh answer as success', async () => {
        await open();

        app.markViewSynced('KRS', { meta: {} });

        assert.equal(line().textContent, 'Data baru saja dimuat');
        assert.equal(line().dataset.tone, 'success');
    });
});

describe('requireOnlineAction', () => {
    it('blocks a portal action in an offline session unless it can wait in the outbox', async () => {
        await open({ portalOffline: true });

        const notices = [];

        app.showNotice = (message, type) => notices.push([message, type]);

        assert.equal(app.requireOnlineAction('Butuh internet.'), false);
        assert.equal(app.requireOnlineAction('Butuh internet.', { queueable: true }), true);
        assert.match(notices[0][0], /^Kamu sedang di mode offline\./);
    });

    it('blocks any action while the device is offline', async () => {
        await open({ online: false });

        const notices = [];

        app.showNotice = (message, type) => notices.push([message, type]);

        assert.equal(app.requireOnlineAction('Butuh internet.', { queueable: true }), false);
        assert.deepEqual(notices, [['Butuh internet.', 'warning']]);
    });
});
