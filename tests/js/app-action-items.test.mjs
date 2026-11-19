import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';
import { ui } from '../../resources/js/app/ui.js';

/**
 * Action-centre rows and navigation badges (resources/js/app/action-items.js).
 * The browser stand-in has no CSS.escape, so the badge tests lend one.
 */
let browser;
let app;
let savedCss;

beforeEach(async () => {
    ({ app, browser } = await createPortalApp());
    savedCss = Object.getOwnPropertyDescriptor(globalThis, 'CSS');
    Object.defineProperty(globalThis, 'CSS', { value: { escape: (value) => value }, configurable: true, writable: true });
});

afterEach(() => {
    if (savedCss) {
        Object.defineProperty(globalThis, 'CSS', savedCss);
    } else {
        delete globalThis.CSS;
    }

    browser?.dispose();
});

describe('renderActionItem', () => {
    it('reads the deadline in words, red from tomorrow on', () => {
        assert.match(app.renderActionItem({ title: 'Isi KRS', days_left: 0 }), /text-danger">Tenggat hari ini<\/span>/);
        assert.match(app.renderActionItem({ title: 'Isi KRS', days_left: 1 }), /text-danger">Tenggat besok<\/span>/);
        assert.match(app.renderActionItem({ title: 'Isi KRS', days_left: 3 }), /text-warning">Tenggat 3 hari lagi<\/span>/);
        assert.match(app.renderActionItem({ title: 'Isi KRS', days_left: -2 }), /Lewat 2 hari<\/span>/);
        assert.doesNotMatch(app.renderActionItem({ title: 'Isi KRS' }), /Tenggat|Lewat/);
    });

    it('links a safe target, in a new tab when it leaves MyCIC', () => {
        const html = app.renderActionItem({ title: 'Bayar', cta_url: '/finance', cta_label: 'Lihat' });

        assert.ok(html.startsWith(`<a href="/finance" class="${ui.listRow} ${ui.listRowInteractive}`));
        assert.doesNotMatch(html, /font-semibold/);
        assert.match(app.renderActionItem({ title: 'Portal', cta_url: 'https://portal.invalid/x' }), /target="_blank" rel="noopener noreferrer"/);
    });

    it('never links a script URL', () => {
        const html = app.renderActionItem({ title: '<Hack>', cta_url: 'javascript:alert(1)' });

        assert.ok(html.startsWith(`<div class="${ui.listRow}">`));
        assert.match(html, /&lt;Hack&gt;/);
        assert.doesNotMatch(html, /javascript:/);
    });
});

describe('paintNavBadge', () => {
    it('shows a count, caps it at 9+, and hides at zero', () => {
        const badge = browser.element('span', { 'data-nav-badge': 'kuliah', class: `${ui.navBadge} hidden` });

        app.paintNavBadge('kuliah', 12, { tone: 'warning', label: '12 tugas' });
        assert.equal(badge.classList.contains('hidden'), false);
        assert.equal(badge.dataset.tone, 'warning');
        assert.match(badge.innerHTML, /<span aria-hidden="true">9\+<\/span><span class="sr-only">12 tugas<\/span>/);

        app.paintNavBadge('kuliah', 0);
        assert.equal(badge.classList.contains('hidden'), true);
        assert.equal(badge.textContent, '');
        assert.equal(badge.dataset.tone, 'warning');
    });

    it('draws a dot for `true`', () => {
        const badge = browser.element('span', { 'data-nav-badge': 'akademik', class: `${ui.navBadge} hidden` });

        app.paintNavBadge('akademik', true);

        assert.equal(badge.dataset.variant, 'dot');
        assert.match(badge.innerHTML, /^<span class="sr-only">1 hal perlu dicek<\/span>$/);
    });
});

describe('setNavBadge', () => {
    it('lets a page value win over the action items until the page drops it', () => {
        const badge = browser.element('span', { 'data-nav-badge': 'exam', class: `${ui.navBadge} hidden` });

        app.setNavBadge('exam', 2, { label: '2 ujian' });
        app.applyActionItemBadges([]);
        assert.match(badge.innerHTML, /2 ujian/);

        app.setNavBadge('exam', null);
        assert.equal(badge.classList.contains('hidden'), true);
    });
});
