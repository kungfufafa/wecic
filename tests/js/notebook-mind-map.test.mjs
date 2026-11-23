import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { viewSource } from './support/views.mjs';

/**
 * The clickable mind map in Catatan, run as written in
 * resources/views/notebook/show/scripts/notes.blade.php and ask.blade.php:
 * every node title is a button with its citations beside it, and its
 * actions ask in Chat (Jelaskan, with the parent as context and the map's
 * sources), open the quiz options with the node as focus (Latihan), or open
 * "Cek pemahamanku" in explain_concept mode. Titles are set as text.
 */
const source = viewSource('notebook/show');

function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

const MIND_MAP = slice('const mindMapNodeAt = (root, path) =>', 'const renderNoteBody = (message) =>');
const ASK = slice('const ask = async (question, { sourceIds = null } = {}) =>', 'const STUDIO_OPTION_KINDS =');

const MAP = {
    id: 12,
    role: 'assistant',
    kind: 'mind_map',
    pinned: true,
    citations: [{ n: 1, source_id: 3, source_title: 'Modul', page: 1, excerpt: 'SQL.' }],
    context: { source_ids: [3, 8] },
    data: {
        title: 'SQL',
        citations: [1],
        children: [
            { title: 'JOIN', citations: [1], children: [{ title: 'INNER <b>JOIN</b>', citations: [1] }] },
            { title: 'WHERE', children: [] },
        ],
    },
};

function mindMapPage({ aiBudget = { limit: 60, remaining: 40 } } = {}) {
    const requests = [];
    const calls = { tabs: [], studio: [], feedback: [] };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);
    const state = {
        messages: [structuredClone(MAP)],
        sources: [{ id: 3, status: 'ready' }, { id: 5, status: 'ready' }],
        aiAvailable: true,
        aiBudget,
        busy: false,
        askMode: 'answer',
    };
    const app = {
        escapeHtml,
        ui: { button: { secondarySm: 'secondary' }, spinner: 'spinner' },
        withSubmitLoading: async (button, callback) => callback(),
        request: async (url, options) => {
            requests.push({ url, options });

            return { data: { question: { id: 20, kind: 'chat', role: 'user' }, answer: { id: 21, kind: 'chat', role: 'assistant', content: 'Jawaban.' } } };
        },
        showNotice: () => {},
        announce: () => {},
    };
    const scope = {
        app,
        state,
        base: '/api/notebooks/7',
        document: { createElement: () => ({ remove: () => {} }) },
        askForm: { querySelector: () => ({}) },
        messagesHost: { innerHTML: '', appendChild: () => {}, scrollTop: 0, scrollHeight: 0 },
        questionInput: { value: '' },
        inline: (text) => escapeHtml(text),
        itemCitations: (item) => (Array.isArray(item?.citations) ? item.citations : []).map((n) => `<button data-cite="${n}">${n}</button>`).join(''),
        needsAiConsent: () => false,
        noContextChosen: () => false,
        contextSources: () => [{ id: 3 }],
        contextSourceIds: () => [],
        renderMessages: () => {},
        setAiBudget: () => {},
        plainText: (text) => String(text),
        noteAiStatus: () => {},
        describeAiError: (error, fallback) => fallback,
        setTab: (tab) => calls.tabs.push(tab),
        openStudioOptions: (button, kind, options) => calls.studio.push({ kind, ...options }),
        openFeedback: (prompt, mode, options) => calls.feedback.push({ prompt, mode, ...options }),
    };
    const keys = Object.keys(scope);
    const api = new Function(...keys, `${MIND_MAP}\n${ASK}\nreturn { renderMindMapNode, mindMapNodeAt, mindMapActionsBody, runMindMapAction };`)(...keys.map((key) => scope[key]));

    // A click on one of a node's actions, with that node's title the open one in its Catatan entry.
    const clickAction = (path, action) => {
        const nodeButton = { dataset: { mindmapNode: path } };
        const holder = {
            dataset: { messageId: String(MAP.id) },
            querySelector: (selector) => (selector === '[data-mindmap-node][aria-expanded="true"]' ? nodeButton : null),
        };

        return api.runMindMapAction({ dataset: { mindmapAction: action }, closest: (selector) => (selector === '[data-message-id]' ? holder : null) });
    };

    return { ...api, state, requests, calls, clickAction };
}

describe('notebook mind map', () => {
    it('renders every node title as a button with its citations, in nested details, as text', () => {
        const page = mindMapPage();
        const html = page.renderMindMapNode(MAP.data);

        assert.equal((html.match(/data-mindmap-node="/g) || []).length, 4);
        assert.match(html, /data-mindmap-node="" aria-expanded="false">SQL<\/button>/);
        assert.match(html, /data-mindmap-node="0\.0" aria-expanded="false">INNER &#60;b&#62;JOIN&#60;\/b&#62;<\/button>/);
        assert.ok(!html.includes('<b>JOIN</b>'));
        assert.equal((html.match(/data-cite="1"/g) || []).length, 3);
        assert.equal((html.match(/<details/g) || []).length, 2, 'only nodes with children fold');
        assert.match(html, /<details open>/);
        assert.ok(source.includes('data-mindmap="${message.id}">${renderMindMapNode(message.data || {})}</ul>'));
    });

    it('still renders an old mind map without citations', () => {
        const page = mindMapPage();
        const html = page.renderMindMapNode({ title: 'ERP', children: [{ title: 'Modul', children: [{ title: 'Keuangan' }] }] });

        assert.equal((html.match(/data-mindmap-node="/g) || []).length, 3);
        assert.ok(!html.includes('data-cite'));
    });

    it('finds a node and its parent by path', () => {
        const page = mindMapPage();

        assert.deepEqual(page.mindMapNodeAt(MAP.data, ''), { title: 'SQL', parent: null });
        assert.deepEqual(page.mindMapNodeAt(MAP.data, '0.0'), { title: 'INNER <b>JOIN</b>', parent: 'JOIN' });
        assert.equal(page.mindMapNodeAt(MAP.data, '4.1'), null);
        assert.equal(page.mindMapNodeAt(null, ''), null);
    });

    it('says each action costs one daily request, and disables them when the budget is spent', () => {
        assert.match(mindMapPage().mindMapActionsBody({ title: 'JOIN' }, 'x'), /Sisa kuota AI hari ini: 40 dari 60\. Tiap pilihan memakai 1 permintaan\./);
        assert.match(mindMapPage({ aiBudget: null }).mindMapActionsBody({ title: 'JOIN' }, 'x'), /Tiap pilihan memakai 1 permintaan dari kuota AI harianmu\./);

        const spent = mindMapPage({ aiBudget: { limit: 60, remaining: 0 } }).mindMapActionsBody({ title: 'JOIN' }, 'x');

        assert.equal((spent.match(/disabled aria-disabled="true"/g) || []).length, 3);
        assert.match(spent, /Kuota AI hari ini sudah habis/);
    });

    it('"Jelaskan" posts the node and its parent to /messages with the map\'s sources and shows Chat', async () => {
        const page = mindMapPage();

        await page.clickAction('0.0', 'explain');

        assert.equal(page.requests.length, 1);
        assert.equal(page.requests[0].url, '/api/notebooks/7/messages');
        assert.equal(page.requests[0].options.method, 'POST');
        assert.equal(page.requests[0].options.json.question, 'Jelaskan "INNER <b>JOIN</b>" dalam konteks "JOIN" berdasarkan sumber.');
        assert.deepEqual(page.requests[0].options.json.source_ids, [3], 'a removed source of the map is left out');
        assert.deepEqual(page.calls.tabs, ['chat']);
    });

    it('"Jelaskan" on the root asks without a parent, and falls back to the ticked sources', async () => {
        const page = mindMapPage();
        page.state.sources = [];

        await page.clickAction('', 'explain');

        assert.equal(page.requests[0].options.json.question, 'Jelaskan "SQL" berdasarkan sumber.');
        assert.deepEqual(page.requests[0].options.json.source_ids, []);
    });

    it('"Latihan" opens the quiz options focused on the node, and the third action opens explain_concept feedback', async () => {
        const page = mindMapPage();

        await page.clickAction('1', 'practice');
        await page.clickAction('1', 'feedback');

        assert.deepEqual(page.calls.studio, [{ kind: 'quiz', focus: 'WHERE (dalam konteks SQL)', sourceIds: [3] }]);
        assert.deepEqual(page.calls.feedback, [{ prompt: 'WHERE (dalam konteks SQL)', mode: 'explain_concept', sourceIds: [3] }]);
        assert.equal(page.requests.length, 0, 'both open a sheet first; nothing is spent yet');
    });

    it('is wired to the page click handler and the quiz options take a focus', () => {
        assert.ok(source.includes('toggleMindMapActions(mindMapNode);'));
        assert.ok(source.includes('await runMindMapAction(mindMapAction);'));
        assert.ok(source.includes('const openStudioOptions = (button, kind, { focus = null, sourceIds = null } = {}) =>'));
        assert.ok(source.includes('runStudio(button, kind, focus, {'));
    });
});

describe('mind map Escape', () => {
    const ESCAPE = slice("// Escape closes a mind map node's actions", '// The Catatan entries the student opened');

    class Node {
        constructor(matches) {
            this.matches = matches;
            this.focused = false;
        }

        closest(selector) {
            return this.matches[selector] ?? null;
        }

        focus() {
            this.focused = true;
        }
    }

    function escapeHandler() {
        const toggled = [];
        let listener;
        const title = new Node({});
        const notesHost = {
            addEventListener: (type, callback) => {
                listener = callback;
            },
            querySelector: (selector) => (selector === '[aria-controls="mindmap-12-0"]' ? title : null),
        };

        new Function('notesHost', 'Element', 'toggleMindMapActions', ESCAPE)(notesHost, Node, (button) => toggled.push(button));

        return { press: (target, key = 'Escape') => listener({ key, target }), toggled, title };
    }

    it('closes the actions from the open title itself and keeps focus there', () => {
        const page = escapeHandler();
        const openTitle = new Node({});

        openTitle.matches['[data-mindmap-node][aria-expanded="true"]'] = openTitle;
        page.press(openTitle);

        assert.deepEqual(page.toggled, [openTitle]);
        assert.ok(openTitle.focused);
    });

    it('closes the actions from inside them and returns to the title', () => {
        const page = escapeHandler();

        page.press(new Node({ '[data-mindmap-actions]': { id: 'mindmap-12-0' } }));

        assert.deepEqual(page.toggled, [page.title]);
        assert.ok(page.title.focused);
    });

    it('leaves other keys and a closed title alone', () => {
        const page = escapeHandler();
        const openTitle = new Node({});

        openTitle.matches['[data-mindmap-node][aria-expanded="true"]'] = openTitle;
        page.press(openTitle, 'Enter');
        page.press(new Node({}));

        assert.deepEqual(page.toggled, []);
    });
});
