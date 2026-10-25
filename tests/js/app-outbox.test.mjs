import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';

/**
 * The MyCIC outbox list (resources/js/app/outbox.js): what an offline
 * session queued is never worded as received by the portal, and a page's
 * host shows only the items it asks for.
 */
let browser;
let app;

beforeEach(async () => {
    ({ app, browser } = await createPortalApp());
});

afterEach(() => browser?.dispose());

const queued = { id: 7, action: 'attendance', action_label: 'Absen', status: 'queued', status_label: 'Menunggu portal', subject: 'Basis Data P3', match_keys: ['meeting:41', 'course:9'] };

describe('renderOutboxItems', () => {
    it('renders nothing for an empty outbox', () => {
        assert.equal(app.renderOutboxItems([]), '');
    });

    it('says a queued item has not reached the portal and lets the student cancel it', () => {
        const html = app.renderOutboxItems([{ ...queued, deadline_at: '2026-10-30T23:59:00+07:00' }]);

        assert.match(html, /Portal belum menerima ini\. MyCIC mengirimnya otomatis begitu portal bisa diakses, selama tenggatnya belum lewat\./);
        assert.match(html, /data-outbox-cancel="7">Batalkan<\/button>/);
        assert.match(html, /<span class="mycic-badge-warning">Menunggu portal<\/span>/);
        assert.match(html, /aria-label="Antrean offline"/);
    });

    it('warns in words once the deadline of a queued item passed', () => {
        const html = app.renderOutboxItems([{ ...queued, deadline_passed: true }]);

        assert.match(html, /<span class="mycic-badge-danger">Tenggat lewat, belum terkirim ke portal<\/span>/);
    });

    it('asks to check the portal before sending an unverified upload again', () => {
        const html = app.renderOutboxItems([{ ...queued, status: 'unverified', status_label: 'Belum pasti', last_error: 'Portal tidak menjawab' }], { heading: 'Antrean <ujian>' });

        assert.match(html, /Cek halamannya di portal sebelum mengirim ulang/);
        assert.match(html, /text-warning">Portal tidak menjawab<\/div>/);
        assert.doesNotMatch(html, /data-outbox-cancel/);
        assert.match(html, /aria-label="Antrean &lt;ujian&gt;"/);
    });
});

describe('renderOutboxHost', () => {
    it('shows only the items a host matches and counts what still waits', () => {
        const host = browser.element('div', { 'data-outbox-list': '', 'data-outbox-match': 'meeting:41' });
        const other = { ...queued, id: 8, match_keys: ['meeting:99'] };
        const sent = { ...queued, id: 9, status: 'sent', status_label: 'Terkirim' };

        app.renderOutboxHost(host, [queued, other, sent]);

        assert.equal(host.dataset.outboxQueued, '1');
        assert.equal(host.classList.contains('hidden'), false);
        assert.match(host.innerHTML, /data-outbox-item="7"/);
        assert.match(host.innerHTML, /data-outbox-item="9"/);
        assert.doesNotMatch(host.innerHTML, /data-outbox-item="8"/);
    });

    it('hides a host none of the items belong to', () => {
        const host = browser.element('div', { 'data-outbox-list': '', 'data-outbox-match': 'exam:3' });

        app.renderOutboxHost(host, [queued]);

        assert.equal(host.classList.contains('hidden'), true);
        assert.equal(host.dataset.outboxQueued, '0');
        assert.equal(host.innerHTML, '');
    });
});
