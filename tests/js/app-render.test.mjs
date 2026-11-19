import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';

/**
 * The shared HTML the pages render with (resources/js/app/render.js): tone
 * to class maps, badges, empty and error states, stat cards and key/value
 * lists. Every text a page passes in is escaped.
 */
let browser;
let app;

beforeEach(async () => {
    ({ app, browser } = await createPortalApp());
});

afterEach(() => browser?.dispose());

const squash = (html) => html.replace(/\s+/g, ' ').trim();

describe('tone classes', () => {
    it('maps every tone a page uses, and an unknown one to neutral', () => {
        assert.equal(app.alertTone('error'), 'mycic-alert-danger');
        assert.equal(app.alertTone('primary'), 'mycic-alert-brand');
        assert.equal(app.alertTone('anything'), 'mycic-alert-neutral');
        assert.equal(app.badgeTone('primary'), 'mycic-badge-brand');
        assert.equal(app.badgeTone('dark'), 'mycic-badge-neutral');
        assert.equal(app.badgeTone('anything'), 'mycic-badge-neutral');
        assert.equal(app.statusPanelClass('primary'), 'mycic-status-panel-brand');
        assert.equal(app.statusPanelClass('danger'), 'mycic-status-panel-neutral');
        assert.equal(app.textToneClass('error'), 'text-danger');
        assert.equal(app.textToneClass('anything'), 'text-ink-muted');
        assert.equal(app.fillToneClass('anything'), 'bg-brand');
    });

    it('keeps the 44 px touch target of small buttons on phones', () => {
        assert.equal(app.buttonClass('secondary', { small: true }), 'mycic-btn-secondary-sm min-h-11 sm:min-h-9');
        assert.equal(app.buttonClass('primary'), 'mycic-btn-primary');
        assert.equal(app.buttonClass('destructive'), 'mycic-btn-destructive');
        assert.equal(app.buttonClass('destructive', { small: true }), 'mycic-btn-destructive-sm min-h-11 sm:min-h-9');
        assert.equal(app.buttonClass('icon'), 'mycic-btn-icon');
        assert.equal(app.buttonClass('icon', { small: true }), 'mycic-btn-icon-sm');
        assert.equal(app.buttonClass('unknown'), 'mycic-btn-primary');
    });
});

describe('renderBadge', () => {
    it('escapes its label', () => {
        assert.equal(app.renderBadge('<b>Lulus</b>', 'success'), '<span class="mycic-badge-success">&lt;b&gt;Lulus&lt;/b&gt;</span>');
    });

    it('reads a severity as the action centre does', () => {
        assert.equal(app.renderSeverityBadge('critical'), '<span class="mycic-badge-danger">Mendesak</span>');
        assert.equal(app.renderSeverityBadge('info', 'Kabar'), '<span class="mycic-badge-info">Kabar</span>');
    });
});

describe('renderEmptyState', () => {
    it('is a calm empty box with an optional subtitle', () => {
        const html = squash(app.renderEmptyState('Belum ada tugas.'));

        assert.equal(html, '<div class="mycic-empty"> <p class="mycic-empty-title">Belum ada tugas.</p> </div>');
        assert.match(app.renderEmptyState('Kosong', 'secondary', 'Coba <lagi>'), /<p class="mycic-empty-subtitle">Coba &lt;lagi&gt;<\/p>/);
    });

    it('turns into an alert a screen reader announces for a warning or an error', () => {
        assert.equal(app.renderEmptyState('Gagal & coba lagi', 'error'), '<div class="mycic-alert-danger mb-0" role="alert">Gagal &amp; coba lagi</div>');
        assert.equal(app.renderEmptyState('Hati-hati', 'warning'), '<div class="mycic-alert-warning mb-0" role="alert">Hati-hati</div>');
    });
});

describe('renderErrorState', () => {
    it('offers a retry button only when the page can try again', () => {
        assert.match(app.renderErrorState('Gagal dimuat.'), /<div class="font-medium">Gagal dimuat\.<\/div>.*data-error-retry>Coba lagi<\/button>/s);
        assert.doesNotMatch(app.renderErrorState('Gagal dimuat.', { retry: false }), /data-error-retry/);
        assert.match(app.renderErrorState('Gagal', { subtitle: 'Server <lambat>', retryLabel: 'Ulangi' }), /Server &lt;lambat&gt;.*Ulangi/s);
    });
});

describe('renderStatCards and renderInlineStats', () => {
    it('leave out items without a value and show a dash for an empty one', () => {
        const html = app.renderStatCards([{ label: 'IPK', value: '' }, { label: 'SKS' }, null, { label: 'MK', value: 7, note: 'semester ini' }]);

        assert.equal((html.match(/mycic-metric"/g) || []).length, 2);
        assert.match(html, /mycic-metric-value">-<\/div>/);
        assert.match(html, /mycic-metric-note">semester ini<\/div>/);
        assert.equal(app.renderStatCards([]), '');
        assert.equal(app.renderInlineStats([{ label: 'x' }]), '');
    });
});

describe('renderKeyValueList', () => {
    it('drops empty values and says so when nothing is left', () => {
        const html = app.renderKeyValueList([{ label: 'NIM', value: '123' }, { label: 'Email', value: '' }, { label: 'HP', value: null }, { label: 'Angkatan', value: 0 }]);

        assert.match(html, /NIM/);
        assert.match(html, /Angkatan/);
        assert.doesNotMatch(html, /Email|HP/);
        assert.match(app.renderKeyValueList([{ label: 'Email', value: '' }]), /Tidak ada data\./);
    });
});

describe('renderPageHeader', () => {
    it('shows only the parts a page passes', () => {
        const html = app.renderPageHeader({ title: 'KRS <2026>', badges: [app.renderBadge('Aktif', 'success'), ''] });

        assert.match(html, /KRS &lt;2026&gt;/);
        assert.match(html, /font-semibold leading-\[1\.2\] tracking-\[-0\.025em\]/);
        assert.match(html, /mycic-badge-success/);
        assert.doesNotMatch(html, /mt-1\.5 text-sm/);
        assert.doesNotMatch(html, /shrink-0 flex-wrap gap-2 print:hidden/);

        const withDescription = app.renderPageHeader({ title: 'Kelas', description: 'Semester ganjil' });
        assert.match(withDescription, /mt-1\.5 text-sm leading-relaxed text-ink-muted">Semester ganjil</);
    });
});
