import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';
import { ui } from '../../resources/js/app/ui.js';

/**
 * Action-centre navigation badges (resources/js/app/action-items.js).
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
