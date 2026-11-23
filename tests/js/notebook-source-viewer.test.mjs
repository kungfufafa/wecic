import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement } from './support/browser.mjs';
import { viewSource } from './support/views.mjs';

/**
 * Opening a citation in its source, run as written in
 * resources/views/notebook/show/scripts/ask.blade.php (the citation panel
 * with "Buka di sumber") and source-viewer.blade.php: the viewer asks for
 * the window around the cited position, groups chunks under "Halaman N"
 * (or "Bagian N" without pages), highlights the cited one, and sets chunk
 * text as text, never as HTML.
 */
const source = viewSource('notebook/show');

function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

const CITATIONS = slice('const findCitation = (messageId, number) =>', 'const ask = async (question) =>');
const VIEWER = slice('const SOURCE_VIEWER_CITED =', "document.addEventListener('click', async (event) => {");

const MESSAGE = {
    id: 9,
    citations: [
        { n: 1, source_id: 4, source_title: 'Modul <b>3</b>', page: 2, position: 5, chunk_id: 77, excerpt: 'Normalisasi.' },
        { n: 2, source_id: 4, source_title: 'Modul <b>3</b>', page: 2, excerpt: 'Rujukan lama.' },
    ],
};

function notebookViewer({ wide = false, response = null, sources = [{ id: 4, title: 'Modul 3' }] } = {}) {
    const requests = [];
    const announced = [];
    const tabs = [];
    const root = new FakeElement('html');
    const sheet = { title: null, pretitle: null, body: new FakeElement('div'), onClose: null, opened: 0 };
    root.append(sheet.body);
    const sourcesHost = new FakeElement('ul');
    const row = new FakeElement('li', { 'data-source-id': '4' });
    row.focused = false;
    row.focus = () => {
        row.focused = true;
    };
    sourcesHost.append(row);
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);
    const document = { createElement: (tag) => new FakeElement(tag) };
    const window = { matchMedia: () => ({ matches: wide }), setTimeout: () => 0 };
    const app = {
        escapeHtml,
        ui: { button: { ghostSm: 'ghost' } },
        openSheet: ({ title, pretitle, body, onClose = null }) => {
            sheet.opened += 1;
            sheet.onClose = onClose;
            sheet.title = title;
            sheet.pretitle = pretitle;
            sheet.body.replaceChildren(body);

            return sheet.body;
        },
        request: async (url) => {
            requests.push(url);

            return (typeof response === 'function' ? response(url) : response) ?? {
                data: {
                    source: { id: 4, title: 'Modul 3' },
                    chunks: [
                        { position: 4, page: 2, content: 'Sebelumnya.' },
                        { position: 5, page: 2, content: '<img src=x onerror=alert(1)> dirujuk' },
                        { position: 6, page: 3, content: 'Sesudahnya.' },
                    ],
                    around: 5,
                    has_before: true,
                    has_after: false,
                },
            };
        },
        announce: (text) => announced.push(text),
        describeError: (error, fallback) => fallback,
    };
    const state = { messages: [MESSAGE], sources };
    const scope = { app, state, base: '/api/notebooks/7', sourcesHost, setTab: (tab) => tabs.push(tab), document, window };
    const keys = Object.keys(scope);
    const api = new Function(...keys, `${CITATIONS}\n${VIEWER}\nreturn { showCitation, openSourceViewer, sourceViewerUrl };`)(...keys.map((key) => scope[key]));

    const closeSheet = () => {
        const onClose = sheet.onClose;
        sheet.onClose = null;
        onClose?.();
    };

    return { ...api, sheet, closeSheet, requests, announced, tabs, row };
}

function citationButton(n) {
    const holder = new FakeElement('li', { 'data-message-id': String(MESSAGE.id) });
    const panel = new FakeElement('div', { 'data-cite-panel': String(MESSAGE.id), class: 'hidden' });
    const button = new FakeElement('button', { 'data-cite': String(n) });
    holder.append(button, panel);

    return { button, panel };
}

describe('notebook source viewer', () => {
    it('offers "Buka di sumber" in the citation panel with the cited position', () => {
        const page = notebookViewer();
        const { button, panel } = citationButton(1);

        page.showCitation(button);

        assert.match(panel.innerHTML, /data-open-source="4" data-open-position="5" data-open-message="9" data-open-n="1">Buka di sumber<\/button>/);
        assert.match(panel.innerHTML, /Modul &#60;b&#62;3&#60;\/b&#62;/);
    });

    it('leaves the position empty for an old citation and hides the button once the source is gone', () => {
        const old = notebookViewer();
        const first = citationButton(2);
        old.showCitation(first.button);
        assert.match(first.panel.innerHTML, /data-open-position="" data-open-message="9" data-open-n="2"/);

        const gone = notebookViewer({ sources: [] });
        const second = citationButton(1);
        gone.showCitation(second.button);
        assert.doesNotMatch(second.panel.innerHTML, /Buka di sumber/);
    });

    it('requests /sources/{id}/chunks?around=position and highlights the cited chunk as text', async () => {
        const page = notebookViewer();

        await page.openSourceViewer({ sourceId: 4, around: 5, messageId: 9, n: 1, fromCitation: true });

        assert.deepEqual(page.requests, ['/api/notebooks/7/sources/4/chunks?around=5']);
        assert.equal(page.sheet.title, 'Modul 3');
        assert.equal(page.sheet.pretitle, 'Teks yang dibaca AI');
        assert.match(page.sheet.body.textContent, /teks yang dibaca AI/);

        const headings = page.sheet.body.querySelectorAll('h3').map((heading) => heading.textContent);
        assert.deepEqual(headings, ['Halaman 2', 'Halaman 3']);

        const cited = page.sheet.body.querySelector('[data-source-chunk-cited]');
        assert.equal(cited.dataset.sourceChunk, '5');
        assert.ok(cited.classList.contains('bg-warning-soft'));
        assert.equal(cited.textContent, '<img src=x onerror=alert(1)> dirujuk');
        assert.equal(cited.innerHTML, '', 'chunk text must never be set as HTML');
        assert.equal(page.sheet.body.querySelectorAll('[data-source-chunk-cited]').length, 1);
        assert.equal(page.sheet.body.querySelector('[data-source-viewer-around]').dataset.sourceViewerAround, '0');
    });

    it('asks the server to find an old citation by its message and number', async () => {
        const page = notebookViewer();

        await page.openSourceViewer({ sourceId: 4, around: null, messageId: 9, n: 2, fromCitation: true });

        assert.deepEqual(page.requests, ['/api/notebooks/7/sources/4/chunks?message=9&n=2']);
        assert.ok(page.sheet.body.querySelector('[data-source-chunk-cited]'));
    });

    it('opens a whole source from the start without a highlight', async () => {
        const page = notebookViewer({ response: { data: { chunks: [{ position: 0, page: null, content: 'Satu' }, { position: 1, page: null, content: 'Dua' }], around: null, has_before: false, has_after: true } } });

        await page.openSourceViewer({ sourceId: 4 });

        assert.deepEqual(page.requests, ['/api/notebooks/7/sources/4/chunks']);
        assert.deepEqual(page.sheet.body.querySelectorAll('h3').map((heading) => heading.textContent), ['Bagian 1', 'Bagian 2']);
        assert.equal(page.sheet.body.querySelector('[data-source-chunk-cited]'), null);
        assert.equal(page.sheet.body.querySelector('[data-source-viewer-around]').dataset.sourceViewerAround, '17');
        assert.deepEqual(page.tabs, []);
    });

    it('switches to Sumber and pulses the source row on a phone only once the viewer closes', async () => {
        const phone = notebookViewer();
        await phone.openSourceViewer({ sourceId: 4, around: 5, fromCitation: true });
        assert.deepEqual(phone.tabs, [], 'nothing changes behind the open sheet');
        assert.ok(!phone.row.classList.contains('animate-pulse'));

        phone.closeSheet();
        assert.deepEqual(phone.tabs, ['sources']);
        assert.ok(phone.row.classList.contains('animate-pulse'));
        assert.equal(phone.row.tabIndex, -1);
        assert.ok(phone.row.focused, 'focus moves to the pulsed row, not the hidden citation button');

        const desktop = notebookViewer({ wide: true });
        await desktop.openSourceViewer({ sourceId: 4, around: 5, fromCitation: true });
        desktop.closeSheet();
        assert.deepEqual(desktop.tabs, []);
        assert.ok(!desktop.row.classList.contains('animate-pulse'));

        const plain = notebookViewer();
        await plain.openSourceViewer({ sourceId: 4 });
        plain.closeSheet();
        assert.deepEqual(plain.tabs, [], '"Lihat isi" on a source does not switch tabs');
    });

    it('pages within the same sheet and keeps the cited chunk highlighted, not the pager anchor', async () => {
        const page = notebookViewer({
            response: (url) => (url.endsWith('around=50')
                ? { data: { chunks: [{ position: 49, page: null, content: 'A' }, { position: 50, page: null, content: 'Dirujuk' }], around: 50, has_before: true, has_after: false } }
                : { data: { chunks: [{ position: 24, page: null, content: 'B' }, { position: 25, page: null, content: 'C' }], around: 24, has_before: false, has_after: true } }),
        });

        await page.openSourceViewer({ sourceId: 4, around: 50, fromCitation: true });
        const pager = page.sheet.body.querySelector('[data-source-viewer-around]');
        assert.equal(pager.dataset.sourceViewerAround, '24');

        pager.dispatchEvent(new Event('click'));
        await new Promise((resolve) => setImmediate(resolve));

        assert.deepEqual(page.requests, ['/api/notebooks/7/sources/4/chunks?around=50', '/api/notebooks/7/sources/4/chunks?around=24']);
        assert.equal(page.sheet.opened, 1, 'paging loads into the open sheet');
        assert.equal(page.sheet.body.querySelector('[data-source-chunk-cited]'), null, 'chunk 24 was never cited');
        assert.equal(page.sheet.body.querySelector('[data-source-viewer-missing]'), null);
        assert.equal(typeof page.sheet.onClose, 'function', 'the pulse still runs when the sheet closes');
    });

    it('says when the cited part is no longer in the source', async () => {
        const page = notebookViewer({ response: { data: { chunks: [{ position: 0, page: 1, content: 'Lain' }], around: null, has_before: false, has_after: false } } });

        await page.openSourceViewer({ sourceId: 4, messageId: 9, n: 2, fromCitation: true });

        assert.match(page.announced[0], /tidak ditemukan lagi/);
        assert.match(page.sheet.body.querySelector('[data-source-viewer-missing]').textContent, /tidak ditemukan lagi/);
    });
});
