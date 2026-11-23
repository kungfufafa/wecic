import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { viewSource } from './support/views.mjs';

/**
 * The student's own notes in Catatan and the AI context ticks kept on the
 * server, run as written in resources/views/notebook/show/scripts
 * (notes.blade.php, controls.blade.php and load.blade.php): the editor saves
 * with one PATCH when it loses focus and only when the text changed, a note
 * is made a source after its save, and the ticks come from and go to
 * `context_source_ids`.
 */
const source = viewSource('notebook/show');

function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

const NOTES = slice('const noteTitle = (message) =>', '// Studio outputs live only here');
const RECEIVE = slice('const receive = (data) => {', 'const load = async (');
const CONTEXT = slice('const saveContextChoice = async () =>', "sourcesHost.addEventListener('change', (event) => {");

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);

function notesPage({ messages = [], sources = [], sourceLimit = 30, failSave = false } = {}) {
    const requests = [];
    const notices = [];
    const statuses = {};
    const renders = [];
    const state = {
        messages,
        sources,
        sourceLimit,
        noteSaved: Object.fromEntries(messages.filter((message) => message.kind === 'note').map((message) => [message.id, message.content])),
        noteSaving: {},
        noteDrafts: {},
        openNoteIds: new Set(),
    };
    const app = {
        escapeHtml,
        ui: { textarea: 'textarea', button: { secondarySm: 'secondary' } },
        request: async (url, options = {}) => {
            requests.push({ url, method: options.method || 'GET', json: options.json, ...(options.keepalive ? { keepalive: true } : {}) });

            if (failSave && options.method === 'PATCH') {
                throw new Error('offline');
            }

            if (url.endsWith('/source')) {
                const id = Number(url.split('/').at(-2));

                return {
                    message: 'Catatan menjadi sumber.',
                    data: {
                        source: { id: 90, title: 'Normalisasi', kind: 'text', status: 'ready' },
                        message: { ...state.messages.find((item) => item.id === id), context: { note_source: { id: 90, title: 'Normalisasi', at: 'now' } } },
                    },
                };
            }

            if (url.endsWith('/notes')) {
                return { data: { message: { id: 70, kind: 'note', role: 'user', pinned: true, content: options.json.from_message_id ? 'Jawaban [1].' : '', citations: [] } } };
            }

            return { data: { message: {} } };
        },
        withSubmitLoading: async (button, callback) => callback(),
        showNotice: (text, tone) => notices.push({ text, tone }),
        describeError: (error, fallback) => fallback,
    };
    const notesHost = {
        querySelector: (selector) => {
            const match = selector.match(/^\[data-note-status="(\d+)"\]$/);

            return match ? { set textContent(text) { statuses[match[1]] = text; } } : null;
        },
    };
    const scope = {
        app,
        state,
        base: '/api/notebooks/7',
        notesHost,
        NOTE_MAX_CHARS: 10000,
        renderMarkdown: (text) => `<p>${escapeHtml(text)}</p>`,
        renderNotes: () => renders.push('notes'),
        renderSources: () => renders.push('sources'),
        renderHeader: () => renders.push('header'),
        renderStudioButtons: () => renders.push('studio'),
        document: { createElement: () => ({}), body: { appendChild: () => {} } },
    };
    const keys = Object.keys(scope);
    const api = new Function(...keys, `${NOTES}\nreturn { noteTitle, renderNoteEditor, noteActions, editNote, saveNote, flushNotes, createNote, noteToSource };`)(...keys.map((key) => scope[key]));

    return { ...api, state, requests, notices, statuses, renders };
}

describe('notebook own notes', () => {
    it('saves the editor with one PATCH on blur, and none when nothing changed', async () => {
        const note = { id: 41, kind: 'note', role: 'user', content: 'Awal' };
        const page = notesPage({ messages: [note] });

        await page.saveNote(41);
        assert.equal(page.requests.length, 0, 'an unchanged note is not sent');

        note.content = '## Normalisasi\n- 1NF';
        await Promise.all([page.saveNote(41), page.saveNote(41)]);
        await page.saveNote(41);

        assert.deepEqual(page.requests, [{ url: '/api/notebooks/7/messages/41', method: 'PATCH', json: { content: '## Normalisasi\n- 1NF' } }]);
        assert.equal(page.statuses[41], 'Tersimpan');
        assert.ok(source.includes("notesHost.addEventListener('focusout', (event) => {"));
        assert.match(source, /if \(editor\) \{\n\s+saveNote\(Number\(editor\.dataset\.noteEditor\)\);/);
    });

    it('tries a failed save again on the next blur and says it is not saved', async () => {
        const note = { id: 41, kind: 'note', role: 'user', content: 'Awal' };
        const page = notesPage({ messages: [note], failSave: true });

        note.content = 'Baru';
        assert.equal(await page.saveNote(41), false);
        await page.saveNote(41);

        assert.equal(page.requests.length, 2);
        assert.equal(page.statuses[41], 'Belum tersimpan');
        assert.equal(page.notices[0].tone, 'warning');
    });

    it('renders the note as an escaped editor with a Markdown preview and its title from the first line', () => {
        const note = { id: 41, kind: 'note', role: 'user', content: '# **Normal<b>isasi</b>**\nIsi' };
        const page = notesPage({ messages: [note] });
        const html = page.renderNoteEditor(note);

        assert.match(html, /data-note-editor="41" maxlength="10000"/);
        assert.ok(html.includes('&#35;') || html.includes('# **Normal&#60;b&#62;isasi&#60;/b&#62;**'));
        assert.ok(!html.includes('<b>isasi</b>'));
        assert.match(html, /data-note-preview="41"><p>/);
        assert.equal(page.noteTitle(note), 'Normal<b>isasi</b>');
        assert.equal(page.noteTitle({ content: '  \n' }), 'Catatan baru');
    });

    it('offers Jadikan sumber until the note is a source, and not when the notebook is full', () => {
        const note = { id: 41, kind: 'note', content: 'Isi' };

        assert.match(notesPage({ messages: [note] }).noteActions(note), /data-note-source="41" >Jadikan sumber/);
        assert.match(notesPage({ messages: [note], sources: [{ id: 1 }], sourceLimit: 1 }).noteActions(note), /data-note-source="41" disabled/);

        const converted = { ...note, context: { note_source: { id: 90, title: 'Normalisasi' } } };

        assert.match(notesPage({ messages: [converted], sources: [{ id: 90, title: 'Normalisasi' }] }).noteActions(converted), /Sudah jadi sumber: Normalisasi/);
        assert.match(notesPage({ messages: [converted], sources: [] }).noteActions(converted), /Jadikan sumber/, 'a deleted source can be made again');
        assert.match(notesPage().noteActions(note), /data-note-download="41">Unduh \.md/);
    });

    it('saves a changed note before making it a source, then lists the source', async () => {
        const note = { id: 41, kind: 'note', role: 'user', content: 'Awal' };
        const page = notesPage({ messages: [note] });

        note.content = 'Normalisasi basis data';
        await page.noteToSource({ dataset: { noteSource: '41' } });

        assert.deepEqual(page.requests.map((request) => `${request.method} ${request.url}`), [
            'PATCH /api/notebooks/7/messages/41',
            'POST /api/notebooks/7/notes/41/source',
        ]);
        assert.equal(page.state.sources[0].id, 90);
        assert.equal(note.context.note_source.id, 90);
        assert.deepEqual(page.renders, ['header', 'sources', 'notes', 'studio']);
    });

    it('does not send an empty note to be a source', async () => {
        const note = { id: 41, kind: 'note', role: 'user', content: '   ' };
        const page = notesPage({ messages: [note] });

        await page.noteToSource({ dataset: { noteSource: '41' } });

        assert.equal(page.requests.length, 0);
        assert.equal(page.notices[0].text, 'Catatan ini masih kosong.');
    });

    it('copies an answer to a new note without AI, and starts an empty one', async () => {
        const page = notesPage();

        await page.createNote({ fromMessageId: 21 });
        await page.createNote();

        assert.deepEqual(page.requests.map((request) => request.json), [{ from_message_id: 21 }, { content: '' }]);
        assert.equal(page.state.messages.length, 2);
        assert.ok(page.state.openNoteIds.has(70));
        assert.ok(source.includes('data-copy-to-note="${message.id}">Salin ke catatan</button>'));
        assert.ok(source.includes('id="source-add-text">+ Tempel teks sebagai sumber</x-button>'));
        assert.ok(source.includes('id="notebook-note-new">+ Catatan baru</x-button>'));
    });
});

describe('notebook context ticks on the server', () => {
    function receivePage(state) {
        const scope = {
            state,
            STARTER_QUESTIONS: [],
            setAiStatus: () => {},
            setAiConsent: () => {},
            setAiBudget: () => {},
        };
        const keys = Object.keys(scope);

        return new Function(...keys, `${RECEIVE}\nreturn receive;`)(...keys.map((key) => scope[key]));
    }

    const freshState = () => ({ excluded: new Set(), contextChoiceKey: null, noteSaved: {}, noteDrafts: {} });
    const data = (contextSourceIds) => ({
        notebook: { id: 7 },
        sources: [{ id: 1 }, { id: 2 }, { id: 3 }],
        messages: [{ id: 41, kind: 'note', content: 'Isi' }],
        context_source_ids: contextSourceIds,
    });

    it('sets the ticks from context_source_ids and leaves them when a poll brings the same choice', () => {
        const state = freshState();
        const receive = receivePage(state);

        receive(data([1, 3]));
        assert.deepEqual([...state.excluded], [2]);
        assert.equal(state.noteSaved[41], 'Isi');

        state.excluded.add(3);
        receive(data([1, 3]));
        assert.deepEqual([...state.excluded].sort(), [2, 3], 'an unsaved tick is not undone by a poll');

        receive(data(null));
        assert.deepEqual([...state.excluded], []);
    });

    it('keeps the page ticks when an older server sends no choice', () => {
        const state = freshState();

        state.excluded.add(2);
        state.excluded.add(9);
        const { context_source_ids: missing, ...older } = data(null);

        receivePage(state)({ ...older, sources: [{ id: 1 }, { id: 2 }] });

        assert.equal(missing, null);

        assert.deepEqual([...state.excluded], [2], 'a deleted source is dropped');
    });

    it('saves the ticks as context_source_ids, null for every source', async () => {
        const requests = [];
        const state = { excluded: new Set([2]), sources: [{ id: 1 }, { id: 2 }, { id: 3 }], contextChoiceKey: null };
        const app = {
            request: async (url, options) => requests.push({ url, ...options }),
            showNotice: () => {},
            describeError: () => '',
        };
        const scope = { app, state, base: '/api/notebooks/7', renderSources: () => {}, renderStudioButtons: () => {} };
        const keys = Object.keys(scope);
        const { saveContextChoice } = new Function(...keys, `${CONTEXT}\nreturn { saveContextChoice };`)(...keys.map((key) => scope[key]));

        await saveContextChoice();
        state.excluded.clear();
        await saveContextChoice();

        assert.deepEqual(requests.map((request) => [request.url, request.method, request.json]), [
            ['/api/notebooks/7', 'PATCH', { context_source_ids: [1, 3] }],
            ['/api/notebooks/7', 'PATCH', { context_source_ids: null }],
        ]);
        assert.equal(state.contextChoiceKey, 'all');
    });
});

describe('notebook notes while the page reloads or closes', () => {
    const receiveInto = (state) => {
        const scope = { state, STARTER_QUESTIONS: [], setAiStatus: () => {}, setAiConsent: () => {}, setAiBudget: () => {} };
        const keys = Object.keys(scope);

        return new Function(...keys, `${RECEIVE}\nreturn receive;`)(...keys.map((key) => scope[key]));
    };
    const typeInto = (page, id, value) => page.editNote({ dataset: { noteEditor: String(id) }, value, closest: () => null });
    const serverCopy = (content) => ({ notebook: { id: 7 }, sources: [], messages: [{ id: 41, kind: 'note', content }] });

    it('keeps the text being typed when a poll brings the server copy, and still saves it on blur', async () => {
        const page = notesPage({ messages: [{ id: 41, kind: 'note', role: 'user', content: 'Awal' }] });
        const receive = receiveInto(page.state);

        page.state.excluded = new Set();
        typeInto(page, 41, 'Awal, lalu ditambah');
        receive(serverCopy('Awal'));

        assert.equal(page.state.messages[0].content, 'Awal, lalu ditambah');
        assert.equal(page.state.noteSaved[41], 'Awal');

        await page.saveNote(41);

        assert.deepEqual(page.requests.map((request) => request.json), [{ content: 'Awal, lalu ditambah' }]);
        assert.deepEqual(page.state.noteDrafts, {}, 'a saved draft is let go');

        receive(serverCopy('Awal, lalu ditambah'));
        assert.equal(page.state.messages[0].content, 'Awal, lalu ditambah');
    });

    it('lets go of a draft once the server has the same text', () => {
        const page = notesPage({ messages: [{ id: 41, kind: 'note', role: 'user', content: 'Awal' }] });

        page.state.excluded = new Set();
        typeInto(page, 41, 'Sama');
        receiveInto(page.state)(serverCopy('Sama'));

        assert.deepEqual(page.state.noteDrafts, {});
    });

    it('sends every unsaved note as a keepalive request when the page goes away', async () => {
        const page = notesPage({ messages: [
            { id: 41, kind: 'note', role: 'user', content: 'Awal' },
            { id: 42, kind: 'note', role: 'user', content: 'Tetap' },
        ] });

        typeInto(page, 41, 'Belum di-blur');
        await page.flushNotes({ keepalive: true });

        assert.deepEqual(page.requests, [{ url: '/api/notebooks/7/messages/41', method: 'PATCH', json: { content: 'Belum di-blur' }, keepalive: true }]);
        assert.match(source, /window\.addEventListener\('pagehide', \(\) => flushNotes\(\{ keepalive: true \}\)\);/);
        assert.match(source, /notePauseTimers\[messageId\] = window\.setTimeout\(\(\) => saveNote\(messageId\), NOTE_PAUSE_SAVE_MS\);/);
    });
});
