import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { viewSource } from './support/views.mjs';

/**
 * "Cek pemahamanku" on the notebook page, run as written in
 * resources/views/notebook/show/scripts/feedback.blade.php and
 * markdown.blade.php: "Jawab" on a study guide's practice questions, the
 * sheet that posts the student's own answer to /feedback, and the result
 * (the verdict in words, points with citation buttons, "Jawab lagi"),
 * with everything the model or the student wrote set as text.
 */
const source = viewSource('notebook/show');

function slice(from, to) {
    const start = source.lastIndexOf('\n', source.indexOf(from)) + 1;
    const end = source.lastIndexOf('\n', source.indexOf(to, start)) + 1;

    assert.ok(source.indexOf(from) >= 0 && source.indexOf(to, start) > start, `${from} … ${to}`);

    return source.slice(start, end);
}

const MARKDOWN = slice('const citationButton = (number) =>', 'const citationPanel = (message) =>');
const FEEDBACK = slice('const FEEDBACK_VERDICT_TONES =', 'const openMeetingPicker = async () =>');

const STUDY_GUIDE = [
    '## Konsep kunci',
    '- **Normalisasi**: mengurangi redundansi [1]',
    '## Pertanyaan latihan',
    '1. Mengapa 3NF menghapus ketergantungan transitif? [2]',
    '2. Apa beda "1NF" dan <b>2NF</b>?',
].join('\n');

function feedbackPage({ aiConsent = true, excluded = false, aiBudget = { limit: 60, remaining: 59 } } = {}) {
    const requests = [];
    const notices = [];
    const sheet = { body: null, submit: null, answer: '' };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);
    const bodyHost = { innerHTML: '' };
    const form = {
        isConnected: true,
        elements: { answer: { get value() { return sheet.answer; }, focus: () => {} } },
        querySelector: () => ({}),
        addEventListener: (type, handler) => {
            sheet.submit = handler;
        },
    };
    const panel = {
        querySelector: (selector) => (selector === '[data-feedback-form]' ? form : selector === '[data-app-sheet-body]' ? bodyHost : null),
    };
    const state = { messages: [], aiAvailable: true, aiBudget };
    const app = {
        escapeHtml,
        ui: {
            button: { ghostSm: 'ghost', secondarySm: 'secondary', primary: 'primary' },
            badge: { neutral: 'neutral', success: 'success', warning: 'warning', danger: 'danger' },
            field: 'field',
            label: 'label',
            textarea: 'textarea',
        },
        openSheet: ({ body }) => {
            sheet.body = body;

            return panel;
        },
        withSubmitLoading: async (button, callback) => callback(),
        request: async (url, options) => {
            requests.push({ url, options });

            return {
                data: {
                    message: {
                        id: 9,
                        kind: 'feedback',
                        citations: [{ n: 2, source_id: 1, source_title: 'Modul', page: 2, excerpt: '3NF.' }],
                        context: { feedback_mode: 'practice_answer', feedback_prompt: 'Mengapa 3NF?' },
                        data: {
                            verdict: 'sebagian',
                            strengths: [{ point: 'Menyebut <script>transitif</script>.', citations: [2] }],
                            gaps: [{ point: 'Belum menyebut 2NF.', citations: [] }],
                            misconceptions: [{ claim: 'Semua atribut', correction: 'Hanya atribut bukan kunci.', citations: [2] }],
                            next_step: 'Baca ulang 2NF.',
                            follow_up_question: 'Apa syarat "2NF"?',
                        },
                    },
                    ai_budget: { limit: 60, remaining: 58 },
                },
            };
        },
        showNotice: (text, tone) => notices.push({ text, tone }),
        announce: () => {},
    };
    const scope = {
        app,
        state,
        base: '/api/notebooks/7',
        FEEDBACK_MODES: { practice_answer: 'Jawaban latihan', explain_concept: 'Penjelasan konsep', outline_check: 'Cek kerangka' },
        FEEDBACK_VERDICTS: { tepat: 'Sudah tepat', sebagian: 'Sebagian tepat', belum_tepat: 'Belum tepat' },
        needsAiConsent: () => !aiConsent,
        noContextChosen: () => excluded,
        contextSources: () => [{ id: 1 }],
        contextSourceIds: () => [],
        budgetLine: () => 'Membuat latihan memakai 1 permintaan dari kuota AI harianmu.',
        itemCitations: (item) => (item.citations || []).map((n) => `<button data-cite="${n}">${n}</button>`).join(''),
        citationPanel: (message) => `<div data-cite-panel="${message.id}"></div>`,
        renderNotes: () => {},
        setAiBudget: (data) => {
            state.aiBudget = data.ai_budget;
        },
        noteAiStatus: () => {},
        describeAiError: (error, fallback) => fallback,
    };
    const keys = Object.keys(scope);
    const api = new Function(...keys, `${MARKDOWN}\n${FEEDBACK}\nreturn { renderMarkdown, practiceQuestionAction, renderFeedback, openFeedback };`)(...keys.map((key) => scope[key]));

    return { ...api, state, sheet, form, bodyHost, requests, notices };
}

describe('notebook feedback (Cek pemahamanku)', () => {
    it('puts "Jawab" on the study guide practice questions only, with the question as text', () => {
        const page = feedbackPage();
        const html = page.renderMarkdown(STUDY_GUIDE, { listItemAction: page.practiceQuestionAction });

        assert.equal((html.match(/>Jawab<\/button>/g) || []).length, 2);
        assert.match(html, /data-feedback-prompt="Mengapa 3NF menghapus ketergantungan transitif\?" data-feedback-mode="practice_answer"/);
        assert.match(html, /data-feedback-prompt="Apa beda &#34;1NF&#34; dan &#60;b&#62;2NF&#60;\/b&#62;\?"/);
        assert.ok(!html.includes('data-feedback-prompt="Normalisasi'));
        assert.ok(!html.includes('<b>2NF</b>'));
        assert.ok(!page.renderMarkdown(STUDY_GUIDE).includes('data-feedback-prompt'), 'other outputs keep their lists as they were');
    });

    it('the study guide and assignment outline are wired to the feedback entry points', () => {
        assert.ok(source.includes("return renderMarkdown(message.content, { listItemAction: practiceQuestionAction });"));
        assert.ok(source.includes("message.kind === 'assignment_plan' ? outlineCheckButton(message) : ''"));
        assert.ok(source.includes('data-feedback-mode="outline_check">Cek kerangkaku</button>'));
        assert.ok(source.includes('openFeedback(feedback.dataset.feedbackPrompt, feedback.dataset.feedbackMode);'));
    });

    it('opens a sheet that says it gives feedback, not the assignment, and posts the answer to /feedback', async () => {
        const page = feedbackPage();

        assert.ok(page.openFeedback('Mengapa 3NF?', 'practice_answer'));
        assert.match(page.sheet.body, /Kami memberi umpan balik, bukan menuliskan tugasmu/);
        assert.match(page.sheet.body, /maxlength="6000"/);

        page.sheet.answer = '  3NF menghapus ketergantungan transitif.  ';
        await page.sheet.submit({ preventDefault: () => {} });

        assert.equal(page.requests.length, 1);
        assert.equal(page.requests[0].url, '/api/notebooks/7/feedback');
        assert.equal(page.requests[0].options.method, 'POST');
        assert.deepEqual(page.requests[0].options.json, { prompt: 'Mengapa 3NF?', answer: '3NF menghapus ketergantungan transitif.', mode: 'practice_answer', source_ids: [] });
        assert.equal(page.state.messages.length, 1);
        assert.equal(page.state.aiBudget.remaining, 58);
        assert.match(page.bodyHost.innerHTML, /data-message-id="9"/);
        assert.match(page.bodyHost.innerHTML, /data-cite-panel="9"/);
        assert.match(page.bodyHost.innerHTML, /Sebagian tepat/);
    });

    it('keeps the result out of a sheet that was closed or reopened while the request ran', async () => {
        const page = feedbackPage();

        page.openFeedback('Mengapa 3NF?');
        page.sheet.answer = '3NF menghapus ketergantungan transitif.';
        page.bodyHost.innerHTML = '<form data-feedback-form>Pertanyaan B</form>';
        page.form.isConnected = false;
        await page.sheet.submit({ preventDefault: () => {} });

        assert.equal(page.requests.length, 1);
        assert.equal(page.state.messages.length, 1, 'the feedback is still kept for Catatan');
        assert.equal(page.bodyHost.innerHTML, '<form data-feedback-form>Pertanyaan B</form>');
        assert.deepEqual(page.notices.at(-1), { text: 'Umpan balik tersimpan di Catatan.', tone: 'success' });
    });

    it('speaks of the feedback check in its budget line, and blocks the check when the quota is used up', () => {
        const page = feedbackPage();

        page.openFeedback('Mengapa 3NF?');
        assert.match(page.sheet.body, /Sisa kuota AI hari ini: 59 dari 60\. Cek pemahaman memakai 1\./);
        assert.ok(!/<button type="submit"[^>]*disabled/.test(page.sheet.body));

        const spent = feedbackPage({ aiBudget: { limit: 60, remaining: 0 } });

        spent.openFeedback('Mengapa 3NF?');
        assert.match(spent.sheet.body, /Kuota AI hari ini sudah habis\. Cek pemahaman bisa dipakai lagi besok\./);
        assert.ok(!/latihan/i.test(spent.sheet.body.replace('Jawaban latihan', '')));
        assert.match(spent.sheet.body, /<button type="submit"[^>]*disabled aria-disabled="true"/);
    });

    it('does not send an answer that is too short, nor open without consent or a chosen source', async () => {
        const page = feedbackPage();

        page.openFeedback('Mengapa 3NF?');
        page.sheet.answer = 'Pendek.';
        await page.sheet.submit({ preventDefault: () => {} });
        assert.equal(page.requests.length, 0);
        assert.match(page.notices[0].text, /minimal 20 karakter/);

        assert.equal(feedbackPage({ aiConsent: false }).openFeedback('Mengapa 3NF?'), null);
        assert.equal(feedbackPage({ excluded: true }).openFeedback('Mengapa 3NF?'), null);
    });

    it('scopes the request to given sources and an unknown mode falls back to a practice answer', async () => {
        const page = feedbackPage();

        page.openFeedback('Normalisasi', 'kerjakan', { sourceIds: [4] });
        page.sheet.answer = 'Normalisasi mengurangi redundansi data.';
        await page.sheet.submit({ preventDefault: () => {} });

        assert.equal(page.requests[0].options.json.mode, 'practice_answer');
        assert.deepEqual(page.requests[0].options.json.source_ids, [4]);
    });

    it('shows the verdict in words, the points with citations, and "Jawab lagi", all as text', () => {
        const page = feedbackPage();
        const html = page.renderFeedback({
            id: 3,
            context: { feedback_mode: 'outline_check', feedback_prompt: 'Makalah <i>normalisasi</i>' },
            data: {
                verdict: 'belum_tepat',
                strengths: [],
                gaps: [{ point: '<img src=x onerror=alert(1)>', citations: [1] }],
                misconceptions: [],
                next_step: '',
                follow_up_question: 'Apa "inti" 3NF?',
            },
        });

        assert.match(html, /<span class="danger">Belum tepat<\/span>/);
        assert.match(html, /Cek kerangka:<\/span> Makalah &#60;i&#62;normalisasi&#60;\/i&#62;/);
        assert.ok(!html.includes('<img'));
        assert.match(html, /Yang belum dibahas/);
        assert.ok(!html.includes('Yang sudah tepat'), 'an empty list is left out');
        assert.match(html, /<button data-cite="1">1<\/button>/);
        assert.match(html, /data-feedback-prompt="Apa &#34;inti&#34; 3NF\?" data-feedback-mode="practice_answer">Jawab lagi<\/button>/);
        assert.ok(!/jawaban (yang )?benar|perbaikan/i.test(html));
    });
});
