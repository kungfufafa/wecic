import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { viewSource } from './support/views.mjs';

/**
 * The source guide on the notebook page, run as written in
 * resources/views/notebook/show/scripts/sources.blade.php and load.blade.php:
 * a source's topics as badges and its understanding questions as buttons
 * that ask about that source only, and the empty chat's starters from
 * `suggestions`. Everything the model wrote is set as text, never as HTML.
 */
const source = viewSource('notebook/show');

function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);
const app = { escapeHtml, ui: { badge: { neutral: 'badge' }, button: { secondarySm: 'button' } } };

const sourceGuide = Function('app', `${slice('const sourceGuide = (source) =>', 'const sourceGroup = (source) =>')}\nreturn sourceGuide;`)(app);

const STARTERS = ['Ringkas inti materi ini dalam 5 poin.'];
const receiveSuggestions = Function('state', 'STARTER_QUESTIONS', `return (data) => {\n${slice('const suggestions = Array.isArray(data.suggestions)', 'setAiStatus(data);')}};`);

describe('source guide', () => {
    it('shows topics as badges and questions as buttons scoped to the source, as text', () => {
        const html = sourceGuide({
            id: 4,
            status: 'ready',
            topics: ['Normalisasi', '<img src=x onerror=alert(1)>'],
            suggested_questions: ['Mengapa "1NF" penting?', null],
        });

        assert.match(html, /<li class="badge">Normalisasi<\/li>/);
        assert.ok(!html.includes('<img'));
        assert.ok(html.includes('&#60;img src=x onerror=alert(1)&#62;'));
        assert.match(html, /data-suggestion="Mengapa &#34;1NF&#34; penting\?" data-suggestion-source="4"/);
        assert.equal((html.match(/data-suggestion=/g) || []).length, 1);
    });

    it('shows nothing for a source read before guides, and no questions while it is not ready', () => {
        assert.equal(sourceGuide({ id: 1, status: 'ready', topics: null }).trim(), '');
        assert.ok(!sourceGuide({ id: 2, status: 'processing', topics: [], suggested_questions: ['Mengapa?'] }).includes('data-suggestion'));
    });

    it('takes the empty chat starters from the payload and falls back to the static list', () => {
        const state = {};
        const receive = receiveSuggestions(state, STARTERS);

        receive({ suggestions: ['Mengapa P1?', '', 7] });
        assert.deepEqual(state.suggestions, ['Mengapa P1?']);

        receive({});
        assert.deepEqual(state.suggestions, STARTERS);
    });

    it('asks a guide question about its source only', () => {
        assert.ok(source.includes("ask(suggestion.dataset.suggestion, sourceId ? { sourceIds: [sourceId] } : {});"));
        assert.ok(source.includes('source_ids: sourceIds || contextSourceIds()'));
        assert.ok(source.includes("state.askMode === 'tutor' ? TUTOR_SUGGESTIONS : state.suggestions"));
    });
});
