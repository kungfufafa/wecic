import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';
import { ui } from '../../resources/js/app/ui.js';

/**
 * The shared HTML the pages render with (resources/js/app/render.js): tone
 * to class maps, badges, empty and error states, inline stats and key/value
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
    it('reads every class from app.ui', () => {
        assert.equal(app.ui, ui);
        assert.ok(Object.isFrozen(ui));
    });

    it('maps every tone a page uses, and an unknown one to neutral', () => {
        assert.equal(app.alertTone('error'), ui.alert.danger);
        assert.equal(app.alertTone('primary'), ui.alert.brand);
        assert.equal(app.alertTone('anything'), ui.alert.neutral);
        assert.equal(app.badgeTone('primary'), ui.badge.brand);
        assert.equal(app.badgeTone('accent'), ui.badge.accent);
        assert.equal(app.badgeTone('dark'), ui.badge.neutral);
        assert.equal(app.badgeTone('anything'), ui.badge.neutral);
        assert.equal(app.statusPanelClass('primary'), ui.panel.brand);
        assert.equal(app.statusPanelClass('danger'), ui.panel.danger);
        assert.equal(app.statusPanelClass('anything'), ui.panel.neutral);
        assert.equal(app.textToneClass('error'), 'text-danger');
        assert.equal(app.textToneClass('anything'), 'text-ink-muted');
        assert.equal(app.fillToneClass('anything'), 'bg-brand');
    });

    it('keeps the 44 px touch target of small buttons on phones', () => {
        assert.equal(app.buttonClass('secondary', { small: true }), ui.button.secondarySm);
        assert.match(ui.button.secondarySm, /(^| )min-h-11 .* sm:min-h-8( |$)/);
        assert.equal(app.buttonClass('primary'), ui.button.primary);
        assert.equal(app.buttonClass('destructive'), ui.button.destructive);
        assert.equal(app.buttonClass('destructive', { small: true }), ui.button.destructiveSm);
        assert.equal(app.buttonClass('icon'), ui.button.icon);
        assert.equal(app.buttonClass('icon', { small: true }), ui.button.iconSm);
        assert.equal(app.buttonClass('unknown'), ui.button.primary);
    });

    it('never puts a display class that beats `hidden` on what scripts hide', () => {
        // Sorted after `hidden`, these would keep a hidden element on screen.
        const beatsHidden = /(^| )(inline-flex|inline-block|inline|table|contents)( |$)/;

        [
            ui.spinner, ui.modal, ui.sheet, ui.navBadge, ui.navBadgeBottom, ui.navBadgeInline,
            ui.dropdownPanel, ui.dropdownPanelUp, ...Object.values(ui.alert), ...Object.values(ui.syncStrip),
        ].forEach((classes) => assert.doesNotMatch(classes, beatsHidden, classes));
    });
});

describe('renderBadge', () => {
    it('escapes its label', () => {
        assert.equal(app.renderBadge('<b>Lulus</b>', 'success'), `<span class="${ui.badge.success}">&lt;b&gt;Lulus&lt;/b&gt;</span>`);
    });

    it('reads a severity as the action centre does', () => {
        assert.equal(app.renderSeverityBadge('critical'), `<span class="${ui.badge.danger}">Mendesak</span>`);
        assert.equal(app.renderSeverityBadge('info', 'Kabar'), `<span class="${ui.badge.info}">Kabar</span>`);
    });
});

describe('renderEmptyState', () => {
    it('is a calm empty box with an optional subtitle', () => {
        const html = squash(app.renderEmptyState('Belum ada tugas.'));

        assert.equal(html, `<div class="${ui.empty}"> <p class="${ui.emptyTitle}">Belum ada tugas.</p> </div>`);
        assert.ok(app.renderEmptyState('Kosong', 'secondary', 'Coba <lagi>').includes(`<p class="${ui.emptySubtitle}">Coba &lt;lagi&gt;</p>`));
    });

    it('turns into an alert a screen reader announces for a warning or an error', () => {
        assert.equal(app.renderEmptyState('Gagal & coba lagi', 'error'), `<div class="${ui.alert.danger}" role="alert">Gagal &amp; coba lagi</div>`);
        assert.equal(app.renderEmptyState('Hati-hati', 'warning'), `<div class="${ui.alert.warning}" role="alert">Hati-hati</div>`);
    });
    it('puts a small neutral icon above the title and ignores an accent', () => {
        const withIcon = app.renderEmptyState('Belum ada pengumuman.', 'secondary', '', { icon: 'announcement', accent: 'orange' });

        assert.ok(withIcon.includes(`<div class="${ui.emptyIcon}" aria-hidden="true">${app.renderIcon('announcement', 'lg')}</div>`));
        assert.doesNotMatch(ui.emptyIcon, /\b(bg-|rounded-|size-)/);
        assert.ok(app.renderEmptyState('Kosong', 'secondary', '', { icon: 'inbox' }).includes(`<div class="${ui.emptyIcon}" aria-hidden="true">`));
        assert.ok(!app.renderEmptyState('Kosong', 'secondary', '', { icon: 'no-such-icon' }).includes('aria-hidden'));
    });
});

describe('icons', () => {
    it('draws an icon as <x-icon> does, and nothing for an unknown name', () => {
        const icon = app.renderIcon('calendar', 'sm', 'text-ink-subtle');

        assert.ok(icon.startsWith(`<svg xmlns="http://www.w3.org/2000/svg" class="${ui.iconSize.sm} text-ink-subtle" width="16" height="16" viewBox="0 0 24 24"`));
        assert.ok(icon.includes('aria-hidden="true" data-icon="calendar">'));
        assert.ok(icon.endsWith(`${ui.icons.calendar}</svg>`));
        assert.ok(app.renderIcon('home').includes(`class="${ui.iconSize.md}" width="18"`));
        assert.ok(app.renderIcon('home', 'huge').includes('width="18"'));
        assert.equal(app.renderIcon('no-such-icon'), '');
    });
});

describe('renderErrorState', () => {
    it('offers a retry button only when the page can try again', () => {
        assert.match(app.renderErrorState('Gagal dimuat.'), /<div class="font-medium">Gagal dimuat\.<\/div>.*data-error-retry>Coba lagi<\/button>/s);
        assert.doesNotMatch(app.renderErrorState('Gagal dimuat.', { retry: false }), /data-error-retry/);
        assert.match(app.renderErrorState('Gagal', { subtitle: 'Server <lambat>', retryLabel: 'Ulangi' }), /Server &lt;lambat&gt;.*Ulangi/s);
    });
});

describe('renderInlineStats', () => {
    it('leaves out items without a value and shows a dash for an empty one', () => {
        const html = app.renderInlineStats([{ label: 'IPK', value: '' }, { label: 'SKS' }, null, { label: 'MK', value: 7, note: 'semester ini' }]);

        assert.equal(html.split('tabular-nums').length - 1, 2);
        assert.ok(html.includes('<div class="font-medium text-ink tabular-nums">-</div>'));
        assert.ok(html.includes('<div class="font-medium text-ink tabular-nums">7</div>'));
        assert.ok(html.includes('<div class="mt-1 text-xs text-ink-subtle">semester ini</div>'));
        assert.equal(app.renderInlineStats([]), '');
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
