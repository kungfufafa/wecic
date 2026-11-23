import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { viewSource } from './support/views.mjs';

/**
 * The quiz and flashcard practice in Catatan, run as written in
 * resources/views/notebook/show/scripts/notes.blade.php: progress lives in
 * state.practice, so redrawing Catatan (renderNotes) keeps it; the score,
 * Ulangi, Tinjau yang salah and Buat latihan dari yang salah (with what is
 * left of today's AI budget) appear after the last answer; an answer and
 * its explanation stay hidden until the student picks an option; items
 * marked "Soal ini salah?" leave the score.
 */
const source = viewSource('notebook/show');

function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

const NOTES = slice('const quizPractice = (messageId) =>', 'const renderStudioButtons = () => {');

const QUIZ = {
    id: 5,
    role: 'assistant',
    kind: 'quiz',
    pinned: true,
    created_at: null,
    citations: [{ n: 1, source_id: 1, source_title: 'Modul', page: 2, excerpt: 'JOIN menggabungkan tabel.' }],
    context: null,
    data: [
        { question: 'Fungsi JOIN?', options: ['Menghapus', 'Menggabungkan tabel'], answer_index: 1, explanation: 'JOIN menggabungkan tabel.', topic: 'JOIN', citations: [1] },
        { question: 'Fungsi WHERE?', options: ['Menyaring baris', 'Mengurutkan'], answer_index: 0, explanation: 'WHERE menyaring baris.', citations: [1] },
    ],
};

const CARDS = {
    id: 6,
    role: 'assistant',
    kind: 'flashcards',
    pinned: true,
    created_at: null,
    citations: [],
    context: null,
    data: [
        { front: 'Kunci primer', back: 'Unik', citations: [] },
        { front: 'Kunci asing', back: 'Rujukan', citations: [] },
        { front: 'Indeks', back: 'Mempercepat', citations: [] },
    ],
};

function notebookNotes({ messages, aiBudget = null } = {}) {
    const requests = [];
    const notesHost = { innerHTML: '', querySelector: () => null };
    const state = {
        messages: structuredClone(messages),
        studioKinds: { quiz: 'Kuis', flashcards: 'Flashcard' },
        practice: {},
        practiceSaves: {},
        openNoteIds: new Set(),
        aiAvailable: true,
        aiBudget,
    };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);
    const app = {
        escapeHtml,
        formatTimeAgo: () => 'baru saja',
        ui: { button: { ghostSm: 'ghost', secondarySm: 'secondary', primarySm: 'primary' } },
        request: async (url, options) => {
            requests.push({ url, options });

            return { data: { message: { context: { practice: { score: 1, total: 2 } } } } };
        },
        showNotice: () => {},
    };
    const scope = {
        app,
        state,
        notesHost,
        base: '/api/notebooks/7',
        inline: (text) => escapeHtml(text),
        citationButton: (n) => `<button data-cite="${n}">${n}</button>`,
        renderMarkdown: (text) => escapeHtml(text),
        citationPanel: () => '',
        contextLine: () => '',
    };
    const keys = Object.keys(scope);
    const api = new Function(...keys, `${NOTES}\nreturn { renderNotes, renderQuiz, renderFlashcards, quizPractice, quizScore, savePractice, flashToLearn };`)(...keys.map((key) => scope[key]));

    return { ...api, state, notesHost, requests };
}

describe('notebook quiz practice', () => {
    it('keeps the answers given when Catatan is redrawn', () => {
        const page = notebookNotes({ messages: [QUIZ] });

        page.state.openNoteIds.add(5);
        page.quizPractice(5).choices[0] = 0;
        page.renderNotes();
        page.renderNotes();

        assert.deepEqual(page.state.practice[5].choices, [0], 'renderNotes must not reset the practice');
        assert.match(page.notesHost.innerHTML, /data-message-id="5" open/);
        assert.match(page.notesHost.innerHTML, /Terjawab 1\/2/);
        assert.match(page.notesHost.innerHTML, /Pilihanmu, salah/);
        assert.match(page.notesHost.innerHTML, /Salah\. Jawaban benar: Menggabungkan tabel\./);
    });

    it('hides an answer and its explanation until an option is picked', () => {
        const page = notebookNotes({ messages: [QUIZ] });
        const html = page.renderQuiz(page.state.messages[0]);

        assert.doesNotMatch(html, /data-quiz-explanation/);
        assert.doesNotMatch(html, /JOIN menggabungkan tabel\./);
        assert.doesNotMatch(html, /· Benar/);
        assert.doesNotMatch(html, /Skor /);
    });

    it('shows the explanation with its citation and the verdict in words after a choice', () => {
        const page = notebookNotes({ messages: [QUIZ] });

        page.quizPractice(5).choices[0] = 1;
        const html = page.renderQuiz(page.state.messages[0]);

        assert.match(html, /<span class="font-medium text-ink">Benar\.<\/span> JOIN menggabungkan tabel\.<button data-cite="1">1<\/button>/);
        assert.match(html, /Topik: JOIN/);
    });

    it('shows the score, Ulangi, Tinjau yang salah and the remaining budget before the practice button after the last answer', () => {
        const page = notebookNotes({ messages: [QUIZ], aiBudget: { limit: 60, remaining: 3 } });

        page.quizPractice(5).choices = [1, 1];
        page.renderNotes();
        const html = page.notesHost.innerHTML;

        assert.match(html, /Skor 1\/2/);
        assert.match(html, /data-quiz-retry="5">Ulangi/);
        assert.match(html, /data-quiz-review="5" aria-pressed="false">Tinjau yang salah/);
        assert.match(html, /data-practice-from="5" data-practice-missed="1"/);
        assert.ok(html.indexOf('Sisa kuota AI hari ini: 3 dari 60. Membuat latihan memakai 1.') < html.indexOf('Buat latihan dari yang salah'), 'the budget is said before the button');
    });

    it('lists only the missed questions while reviewing', () => {
        const page = notebookNotes({ messages: [QUIZ] });
        const practice = page.quizPractice(5);

        practice.choices = [1, 1];
        practice.reviewing = true;
        const html = page.renderQuiz(page.state.messages[0]);

        assert.doesNotMatch(html, /Fungsi JOIN\?/);
        assert.match(html, /Fungsi WHERE\?/);
        assert.match(html, /Tampilkan semua soal/);
    });

    it('keeps a question marked wrong while reviewing, so the mark can be undone', () => {
        const page = notebookNotes({ messages: [QUIZ] });
        const practice = page.quizPractice(5);

        practice.choices = [1, 1];
        practice.reviewing = true;
        practice.flagged = [1];
        const html = page.renderQuiz(page.state.messages[0]);

        assert.match(html, /Skor 1\/1/);
        assert.match(html, /Fungsi WHERE\?/, 'the marked question stays listed');
        assert.match(html, /data-quiz-flag="1" aria-pressed="true">Batalkan tanda salah/);
        assert.match(html, /data-quiz-review="5" aria-pressed="true">Tampilkan semua soal/, 'the way back to every question stays');
        assert.doesNotMatch(html, /data-practice-from/, 'nothing is left to practise');
    });

    it('shows every question when reviewing finds nothing missed or marked', () => {
        const page = notebookNotes({ messages: [QUIZ] });
        const practice = page.quizPractice(5);

        practice.choices = [1, 0];
        practice.reviewing = true;
        const html = page.renderQuiz(page.state.messages[0]);

        assert.match(html, /Fungsi JOIN\?/);
        assert.match(html, /Fungsi WHERE\?/);
        assert.doesNotMatch(html, /data-quiz-review/);
    });

    it('leaves an item marked wrong out of the score and disables making a set when the budget is spent', () => {
        const page = notebookNotes({ messages: [QUIZ], aiBudget: { limit: 60, remaining: 0 } });
        const practice = page.quizPractice(5);

        practice.flagged = [1];
        practice.choices = [1];
        assert.deepEqual(page.quizScore(QUIZ.data, practice), { total: 1, answered: 1, score: 1, missed: [], done: true });
        assert.match(page.renderQuiz(page.state.messages[0]), /Skor 1\/1/);
        assert.match(page.renderQuiz(page.state.messages[0]), /Ditandai salah, tidak dihitung dalam skor\./);

        practice.flagged = [];
        practice.choices = [1, 1];
        assert.match(page.renderQuiz(page.state.messages[0]), /data-practice-missed="1" disabled/);
        assert.match(page.renderQuiz(page.state.messages[0]), /Kuota AI hari ini sudah habis/);
    });

    it('saves the choices for the server to score, skipped ones as null', async () => {
        const page = notebookNotes({ messages: [QUIZ] });
        const message = page.state.messages[0];

        page.quizPractice(5).choices[1] = 0;
        page.quizPractice(5).flagged = [0];
        await page.savePractice(message);

        assert.equal(page.requests[0].url, '/api/notebooks/7/messages/5');
        assert.deepEqual(page.requests[0].options, { method: 'PATCH', json: { practice: { choices: [null, 0], flagged: [0] } } });
        assert.deepEqual(message.context, { practice: { score: 1, total: 2 } });
    });
});

describe('notebook flashcard deck', () => {
    it('shows one card at a time and keeps its place when Catatan is redrawn', () => {
        const page = notebookNotes({ messages: [CARDS] });

        page.renderNotes();
        Object.assign(page.state.practice[6], { position: 1, flipped: true, known: [0] });
        page.renderNotes();
        const html = page.notesHost.innerHTML;

        assert.match(html, /Kartu 2\/3 · 1 hafal/);
        assert.match(html, />Rujukan<\/span>/, 'the flipped card shows its back');
        assert.match(html, /data-flash-known="6">Sudah hafal/);
        assert.match(html, /data-flash-again="6">Ulangi/);
        assert.match(html, /data-flash-mode="deck" aria-pressed="true"/);
    });

    it('sums up a finished pass and keeps the grid as the other view', async () => {
        const page = notebookNotes({ messages: [CARDS] });
        const message = page.state.messages[0];

        page.renderFlashcards(message);
        Object.assign(page.state.practice[6], { position: 3, known: [0, 2], again: [1] });
        assert.match(page.renderFlashcards(message), /Hafal 2\/3/);
        assert.match(page.renderFlashcards(message), /Ulangi yang belum hafal/);

        await page.savePractice(message);
        assert.deepEqual(page.requests[0].options.json, { practice: { score: 2, total: 3, missed: [1] } });

        page.state.practice[6].mode = 'grid';
        assert.equal((page.renderFlashcards(message).match(/data-flashcard /g) || []).length, 3);
    });

    it('counts the cards skipped with Berikutnya as still to learn', async () => {
        const page = notebookNotes({ messages: [CARDS] });
        const message = page.state.messages[0];

        page.renderFlashcards(message);
        // Two cards skipped, the last one graded "Sudah hafal".
        Object.assign(page.state.practice[6], { position: 3, known: [2], again: [] });
        const html = page.renderFlashcards(message);

        assert.match(html, /Hafal 1\/3/);
        assert.match(html, /2 kartu perlu diulang\./);
        assert.doesNotMatch(html, /Semua kartu sudah hafal/);
        assert.match(html, /Ulangi yang belum hafal/);
        assert.match(html, /data-practice-missed="0,1"/);
        assert.deepEqual(page.flashToLearn(page.state.practice[6], 3), [0, 1]);

        await page.savePractice(message);
        assert.deepEqual(page.requests[0].options.json, { practice: { score: 1, total: 3, missed: [0, 1] } });
    });

    it('keeps the grid view', () => {
        const page = notebookNotes({ messages: [CARDS] });
        const message = page.state.messages[0];

        page.renderFlashcards(message);
        page.state.practice[6].mode = 'grid';
        assert.equal((page.renderFlashcards(message).match(/data-flashcard /g) || []).length, 3);
    });
});
