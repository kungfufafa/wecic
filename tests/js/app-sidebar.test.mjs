import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp } from './support/browser.mjs';

/**
 * Two-tier desktop sidebar behaviour (resources/js/app/header.js):
 * collapsing/expanding contextual trails, `/` keyboard shortcut (including
 * on no-trail pages such as Beranda/Keuangan), and active-trail search.
 */
let browser;

afterEach(() => browser?.dispose());

async function setupSidebar({ activeContext = 'kuliah', hasTrail = activeContext !== 'beranda' && activeContext !== 'keuangan', url = '/class' } = {}) {
    const created = await createPortalApp({ url });

    browser = created.browser;

    const rail = browser.element('nav', { 'aria-label': 'Navigasi utama', 'data-activity-rail': '' });
    const railKuliah = browser.element('a', {
        href: '/class',
        'data-rail-slot': 'kuliah',
        'data-nav-slot': 'kuliah',
        'data-rail-has-trail': '1',
    }, rail);
    const railAkademik = browser.element('a', {
        href: '/krs',
        'data-rail-slot': 'akademik',
        'data-nav-slot': 'akademik',
        'data-rail-has-trail': '1',
    }, rail);

    const toggle = browser.element('button', {
        type: 'button',
        'data-sidebar-toggle': '',
        'aria-expanded': 'true',
    });
    const panel = browser.element('div', {
        'data-sidebar-panel': '',
        'data-sidebar-active-context': activeContext,
        'data-sidebar-has-trail': hasTrail ? '1' : '0',
        class: hasTrail ? 'flex' : 'hidden',
    });
    const searchInput = browser.element('input', {
        type: 'search',
        'data-sidebar-search': '',
    }, panel);
    const kbd = browser.element('kbd', { 'data-sidebar-search-kbd': '' }, panel);

    const addLink = (parent, href, subSlot, label) => {
        const link = browser.element('a', { href, 'data-sidebar-subitem': subSlot }, parent);

        link.textContent = label;

        return link;
    };

    const kuliahTrail = browser.element('nav', { 'data-sidebar-trail': 'kuliah', class: 'hidden' }, panel);
    const subClass = addLink(kuliahTrail, '/class', 'class', 'Jadwal & Kelas');
    const subExam = addLink(kuliahTrail, '/exam', 'exam', 'Ujian');

    const akademikTrail = browser.element('nav', { 'data-sidebar-trail': 'akademik', class: 'hidden' }, panel);
    const subKrs = addLink(akademikTrail, '/krs', 'krs', 'KRS & Perwalian');
    const subGrades = addLink(akademikTrail, '/grades', 'grades', 'Nilai');
    const subTranscript = addLink(akademikTrail, '/transcript', 'transcript', 'Transkrip');

    const notebookTrail = browser.element('nav', { 'data-sidebar-trail': 'notebook', class: 'hidden' }, panel);
    const subNotebook = addLink(notebookTrail, '/notebook', 'notebook', 'Notebook');
    const subNotebookAi = addLink(notebookTrail, '/notebook/pengaturan-ai', 'notebook-ai-settings', 'Pengaturan AI');

    const moreTrail = browser.element('nav', { 'data-sidebar-trail': 'more', class: 'hidden' }, panel);
    const subSkkm = addLink(moreTrail, '/skkm', 'skkm', 'SKKM');
    const subMore = addLink(moreTrail, '/more', 'more', 'Semua menu');

    const settingsTrail = browser.element('nav', {
        'data-sidebar-trail': 'settings',
        'data-sidebar-context-nav': 'settings',
        class: 'hidden',
    }, panel);
    const settingsAccountGroup = browser.element('div', { role: 'group' }, settingsTrail);
    const settingsAccountLabel = browser.element('p', {}, settingsAccountGroup);

    settingsAccountLabel.textContent = 'Akun & Preferensi';

    const settingsProfile = addLink(settingsAccountGroup, '/profile', 'profile', 'Profil');
    const settingsPassword = addLink(settingsAccountGroup, '/password', 'password', 'Ubah password');

    const settingsPrivacyGroup = browser.element('div', { role: 'group' }, settingsTrail);
    const settingsPrivacyLabel = browser.element('p', {}, settingsPrivacyGroup);

    settingsPrivacyLabel.textContent = 'Privasi & Data';

    const settingsDelete = addLink(settingsPrivacyGroup, '/account/delete', 'account-delete', 'Hapus akun');

    const empty = browser.element('p', { class: 'hidden', 'data-sidebar-search-empty': '' }, panel);

    empty.textContent = 'Menu tidak ditemukan.';

    created.app.bindSidebar();

    return {
        app: created.app,
        panel,
        rail: { railKuliah, railAkademik },
        toggle,
        searchInput,
        kbd,
        empty,
        trails: { kuliahTrail, akademikTrail, notebookTrail, moreTrail, settingsTrail },
        links: {
            subClass,
            subExam,
            subKrs,
            subGrades,
            subTranscript,
            subNotebook,
            subNotebookAi,
            subSkkm,
            subMore,
            settingsProfile,
            settingsPassword,
            settingsDelete,
        },
        groups: { settingsAccountGroup, settingsPrivacyGroup },
    };
}

function keyEvent(key, extra = {}) {
    const event = new Event('keydown', { cancelable: true, bubbles: true });

    event.key = key;
    Object.assign(event, extra);

    return event;
}

describe('sidebar collapse and contextual trail search', () => {
    it('toggles trail collapse state, persists to localStorage, and un-collapses when clicking a rail item with a trail', async () => {
        const { toggle, rail } = await setupSidebar({ activeContext: 'kuliah', url: '/class' });

        toggle.dispatchEvent(new Event('click'));

        assert.equal(browser.document.documentElement.dataset.sidebarCollapsed, '1');
        assert.equal(browser.localStorage.getItem('mycic-sidebar-collapsed'), '1');
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(toggle.getAttribute('aria-label'), 'Perluas sidebar');

        // Clicking the current page's rail item while collapsed expands in place instead of reloading.
        const clickCurrent = new Event('click', { cancelable: true });

        rail.railKuliah.dispatchEvent(clickCurrent);

        assert.equal(clickCurrent.defaultPrevented, true);
        assert.equal(browser.document.documentElement.dataset.sidebarCollapsed, undefined);
        assert.equal(browser.localStorage.getItem('mycic-sidebar-collapsed'), null);
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');

        // Clicking another section's rail item while collapsed un-collapses and allows navigation.
        toggle.dispatchEvent(new Event('click'));
        assert.equal(browser.document.documentElement.dataset.sidebarCollapsed, '1');

        const clickOther = new Event('click', { cancelable: true });

        rail.railAkademik.dispatchEvent(clickOther);

        assert.equal(clickOther.defaultPrevented, false);
        assert.equal(browser.document.documentElement.dataset.sidebarCollapsed, undefined);
    });

    it('opens sidebar search on / shortcut even from no-trail pages, ignores inputs or dialogs, and hides on Escape', async () => {
        const { app, panel, searchInput, toggle, trails } = await setupSidebar({ activeContext: 'beranda', hasTrail: false, url: '/dashboard' });
        let focused = 0;

        searchInput.focus = () => {
            focused += 1;
        };

        assert.equal(panel.classList.contains('hidden'), true);
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');

        // Clicking toggle on a no-trail page opens the sidebar with the fallback trail and toggles back closed.
        toggle.dispatchEvent(new Event('click'));

        assert.equal(panel.classList.contains('hidden'), false);
        assert.equal(trails.moreTrail.classList.contains('flex'), true);
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');

        toggle.dispatchEvent(new Event('click'));

        assert.equal(panel.classList.contains('hidden'), true);
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');

        app.setSidebarCollapsed(true);

        const slash = keyEvent('/');

        browser.document.dispatchEvent(slash);

        assert.equal(slash.defaultPrevented, true);
        assert.equal(browser.document.documentElement.dataset.sidebarCollapsed, undefined);
        assert.equal(panel.classList.contains('hidden'), false);
        assert.equal(panel.classList.contains('flex'), true);
        assert.equal(focused, 1);

        // Escape on a no-trail page closes the search panel even without a query.
        searchInput.dispatchEvent(keyEvent('Escape'));

        assert.equal(panel.classList.contains('hidden'), true);

        const inputTarget = browser.element('input', { type: 'text' });
        const fromInput = keyEvent('/');

        Object.defineProperty(fromInput, 'target', { value: inputTarget });
        browser.document.dispatchEvent(fromInput);

        assert.equal(fromInput.defaultPrevented, false);
        assert.equal(focused, 1);

        const sheet = browser.element('div', { 'data-app-sheet': '', class: 'fixed inset-0' });
        const withDialog = keyEvent('/');

        browser.document.dispatchEvent(withDialog);

        assert.equal(withDialog.defaultPrevented, false);
        assert.equal(focused, 1);
        sheet.remove();
    });

    it('shows only the active section trail by default, searches only within the active rail trail, activates on Enter, and restores on Escape', async () => {
        const { app, panel, searchInput, kbd, empty, trails, links, groups } = await setupSidebar({ activeContext: 'kuliah', url: '/class' });
        let clicked = 0;

        links.subExam.addEventListener('click', () => {
            clicked += 1;
        });

        assert.equal(panel.classList.contains('hidden'), false);
        assert.equal(trails.kuliahTrail.classList.contains('flex'), true);
        assert.equal(trails.akademikTrail.classList.contains('hidden'), true);
        assert.equal(trails.notebookTrail.classList.contains('hidden'), true);
        assert.equal(trails.moreTrail.classList.contains('hidden'), true);
        assert.equal(trails.settingsTrail.classList.contains('hidden'), true);

        // Search finds matching item inside the active Kuliah trail and activates it on Enter.
        searchInput.value = 'ujian';
        searchInput.dispatchEvent(new Event('input'));

        assert.equal(kbd.classList.contains('hidden'), true);
        assert.equal(empty.classList.contains('hidden'), true);
        assert.equal(trails.kuliahTrail.classList.contains('hidden'), false);
        assert.equal(links.subExam.classList.contains('hidden'), false);
        assert.equal(links.subExam.getAttribute('data-sidebar-match'), '1');
        assert.equal(links.subClass.classList.contains('hidden'), true);
        assert.equal(trails.akademikTrail.classList.contains('hidden'), true);
        assert.equal(trails.notebookTrail.classList.contains('hidden'), true);
        assert.equal(trails.moreTrail.classList.contains('hidden'), true);
        assert.equal(trails.settingsTrail.classList.contains('hidden'), true);

        const enter = keyEvent('Enter');

        searchInput.dispatchEvent(enter);

        assert.equal(enter.defaultPrevented, true);
        assert.equal(clicked, 1);

        // Items from another rail (e.g. KRS in Akademik) do not appear while Kuliah is active.
        searchInput.value = 'krs';
        searchInput.dispatchEvent(new Event('input'));

        assert.equal(empty.classList.contains('hidden'), false);
        assert.equal(trails.kuliahTrail.classList.contains('hidden'), true);
        assert.equal(trails.akademikTrail.classList.contains('hidden'), true);
        assert.equal(links.subKrs.getAttribute('data-sidebar-match'), null);

        // Escape clears the query and restores only Kuliah trail.
        searchInput.dispatchEvent(keyEvent('Escape'));

        assert.equal(searchInput.value, '');
        assert.equal(empty.classList.contains('hidden'), true);
        assert.equal(kbd.classList.contains('hidden'), false);
        assert.equal(trails.kuliahTrail.classList.contains('flex'), true);
        assert.equal(trails.kuliahTrail.classList.contains('hidden'), false);
        assert.equal(trails.akademikTrail.classList.contains('hidden'), true);
        assert.equal(trails.settingsTrail.classList.contains('hidden'), true);

        // Searching grouped settings trail when Pengaturan is active hides non-matching groups and other trails.
        panel.dataset.sidebarActiveContext = 'settings';
        app.applySidebarContext('settings');
        searchInput.value = 'password';
        searchInput.dispatchEvent(new Event('input'));

        assert.equal(empty.classList.contains('hidden'), true);
        assert.equal(trails.settingsTrail.classList.contains('hidden'), false);
        assert.equal(links.settingsPassword.classList.contains('hidden'), false);
        assert.equal(links.settingsPassword.getAttribute('data-sidebar-match'), '1');
        assert.equal(groups.settingsAccountGroup.classList.contains('hidden'), false);
        assert.equal(groups.settingsPrivacyGroup.classList.contains('hidden'), true);
        assert.equal(trails.kuliahTrail.classList.contains('hidden'), true);
        assert.equal(trails.akademikTrail.classList.contains('hidden'), true);
        assert.equal(trails.notebookTrail.classList.contains('hidden'), true);
        assert.equal(trails.moreTrail.classList.contains('hidden'), true);
    });
});
