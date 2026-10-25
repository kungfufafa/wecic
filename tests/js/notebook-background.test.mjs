import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createPortalApp, json, settle } from './support/browser.mjs';
import { viewSource } from './support/views.mjs';

/**
 * The notebook page re-reads itself while a source is still being read (a
 * 4 s, then 20 s poll for up to an hour) and when the background meeting
 * sync announces itself. Run as written in
 * resources/views/notebook/show.blade.php against a real PortalApp, those
 * re-reads must carry the background header
 * (PortalSessionMiddleware::BACKGROUND_HEADER): a notebook tab left open
 * must not keep the campus session from idling out. Opening the page is the
 * student's own read. The script is read with its parts
 * (resources/views/notebook/show/scripts) included, as the page renders it.
 */
const source = viewSource('notebook/show');
const BACKGROUND_HEADER = 'X-MyCIC-Background';
const BASE = '/api/notebooks/7';

/**
 * The source from the line holding `from` up to (not including) the line
 * holding `to`.
 */
function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

/**
 * A `const NAME = <arithmetic>;` from the page.
 */
function constant(name) {
    const match = source.match(new RegExp(`const ${name} = ([\\d\\s*+]+);`));

    assert.ok(match, name);

    return Function(`return (${match[1]});`)();
}

const PAGE = [
    slice('const schedulePoll = () => {', 'const receive = (data) => {'),
    slice('const load = async (', 'const addSources = async ('),
    slice("app.registerSyncHandler('notebook-show'", 'const ready = await app.ensureAuthenticated();'),
].join('\n');

let browser;
let app;

afterEach(() => browser?.dispose());

const marked = (request) => request.headers[BACKGROUND_HEADER] === '1';
const reads = () => browser.requests.filter((request) => request.url.pathname === BASE);

/**
 * A notebook page with one source the server still reports as being read.
 */
async function openNotebook() {
    const created = await createPortalApp({ url: '/notebook/7', page: 'notebook' });
    const state = { sources: [], pollTimer: null, pollReading: '', pollStartedAt: null };
    const noop = () => {};

    browser = created.browser;
    app = created.app;
    browser.server = () => json(200, { status: 'success', data: { notebook: { id: 7 }, sources: [{ id: 1, status: 'processing' }], messages: [] } });

    const scope = {
        app,
        window: browser.window,
        state,
        base: BASE,
        POLL_LIMIT_MS: constant('POLL_LIMIT_MS'),
        POLL_SLOW_AFTER_MS: constant('POLL_SLOW_AFTER_MS'),
        receive: (data) => {
            state.sources = data.sources;
        },
        renderAll: noop,
        renderHeader: noop,
        renderSources: noop,
        renderStudioButtons: noop,
        renderMessages: noop,
        sourcesHost: { removeAttribute: noop },
        messagesHost: {},
    };
    const keys = Object.keys(scope);

    return new Function(...keys, `${PAGE}\nreturn { load };`)(...keys.map((key) => scope[key]));
}

describe('notebook re-reads while a source is being read', () => {
    it('opening the page is the student\'s read; the poll after it is background work', async () => {
        const { load } = await openNotebook();

        await load();
        await settle();
        assert.deepEqual(reads().map(marked), [false]);

        await browser.clock.advance(4000);
        await settle();
        await browser.clock.advance(4000);
        await settle();

        assert.equal(reads().length, 3, 'the poll keeps going while the source is still being read');
        assert.deepEqual(reads().map(marked), [false, true, true], 'notebook/show.blade.php schedulePoll: the poll must read with the background header (cross-package request to the notebook owner)');
    });

    it('the re-read the background meeting sync sets off is background work', async () => {
        await openNotebook();

        await app.runSyncHandlers({ background: true });
        await settle();

        assert.equal(reads().length, 1);
        assert.equal(marked(reads()[0]), true);
    });
});
