import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { viewSource } from './support/views.mjs';

/**
 * Ulasan harian (resources/views/notebook/review.blade.php), run as written:
 * one card at a time, Spasi shows the answer, keys 1 to 4 grade it outside
 * text fields, each grade is one POST, and the queue is read again when it
 * runs out.
 */
const source = viewSource('notebook/review');
const script = source.slice(source.indexOf('<script>') + '<script>'.length, source.indexOf('</script>'));

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => `&#${character.charCodeAt(0)};`);

class FakeElement {
    constructor(tagName = 'DIV') {
        this.tagName = tagName;
        this.isContentEditable = false;
        this.innerHTML = '';
        this.textContent = '';
        this.attributes = {};
        this.listeners = {};
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    removeAttribute(name) {
        delete this.attributes[name];
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    // As in browsers, an empty selector is not a selector.
    querySelector(selector) {
        if (typeof selector !== 'string' || selector.trim() === '') {
            throw new SyntaxError(`'${selector}' is not a valid selector`);
        }

        return null;
    }

    closest() {
        return null;
    }
}

globalThis.Element = FakeElement;

const card = (id, extra = {}) => ({
    id,
    notebook_id: 3,
    notebook_title: 'Basis Data',
    front: `Depan ${id} <b>tebal</b>`,
    back: `Belakang ${id}`,
    citations: [{ n: 1, source_title: 'Modul 3', page: 4, excerpt: 'Bentuk normal' }],
    topic: null,
    meeting_number: 3,
    is_new: false,
    lapses: 0,
    next: { lagi: 10, sulit: 1440, bisa: 4320, mudah: 10080 },
    ...extra,
});

async function reviewPage(queues, { counts = null } = {}) {
    const requests = [];
    const announcements = [];
    const listeners = {};
    const elements = {
        'review-card': new FakeElement('SECTION'),
        'review-progress': new FakeElement('P'),
        'review-undo': new FakeElement('DIV'),
    };
    elements['review-undo'].hidden = true;
    let reads = 0;
    const app = {
        escapeHtml,
        ui: { button: { primarySm: 'primary', secondarySm: 'secondary', ghostSm: 'ghost' }, textarea: 'textarea', emptyIcon: 'icon' },
        renderBadge: (label) => `<span>${label}</span>`,
        renderIcon: () => '',
        request: async (url, options = {}) => {
            requests.push({ url, method: options.method || 'GET', json: options.json });

            if ((options.method || 'GET') === 'GET') {
                const queue = queues[Math.min(reads, queues.length - 1)];

                reads += 1;

                return { data: { cards: queue.map((item) => ({ ...item })), counts: counts ?? { due: queue.length, total: 5, suspended: 0 }, next_due_at: null } };
            }

            return { message: url.endsWith('/resume') ? '2 kartu kembali ke ulasan.' : 'Kartu disimpan.', data: { counts: { due: 0, total: 5, suspended: 0 } } };
        },
        announce: (text) => announcements.push(text),
        showNotice: () => {},
        describeError: (error, fallback) => fallback,
        withSubmitLoading: async (button, callback) => callback(),
        mountErrorState: () => {},
        ensureAuthenticated: async () => true,
    };
    const document = {
        addEventListener: (type, listener) => {
            listeners[type] = listener;
        },
        getElementById: (id) => elements[id] ?? null,
    };

    new Function('document', 'window', script)(document, { portalApp: app });
    await listeners.DOMContentLoaded();

    const key = async (value, target = new FakeElement('BODY'), modifiers = {}) => {
        const event = { key: value, target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...modifiers };

        await listeners.keydown(event);

        return event;
    };

    // A click on an element matching `selector` inside the card (or the undo bar).
    const click = async (selector, host = elements['review-card']) => {
        const target = new FakeElement('BUTTON');

        target.closest = (wanted) => (wanted === selector ? target : null);
        target.disabled = false;
        target.dataset = {};
        await host.listeners.click({ target });
    };

    return { requests, announcements, key, click, card: elements['review-card'], progress: elements['review-progress'], undo: elements['review-undo'] };
}

describe('Ulasan harian', () => {
    it('shows the first card escaped, with what is left today', async () => {
        const page = await reviewPage([[card(1), card(2, { is_new: true })]]);

        assert.match(page.card.innerHTML, /Depan 1 &#60;b&#62;tebal&#60;\/b&#62;/);
        assert.doesNotMatch(page.card.innerHTML, /<b>tebal<\/b>/);
        assert.match(page.card.innerHTML, /data-review-reveal/);
        assert.doesNotMatch(page.card.innerHTML, /Belakang 1/, 'The answer stays hidden until it is asked for.');
        assert.equal(page.progress.textContent, 'Sisa 2 kartu hari ini · 1 baru');
        assert.equal(page.card.attributes['aria-busy'], undefined);
    });

    it('reveals with Spasi and grades with keys 1 to 4, one POST each', async () => {
        const page = await reviewPage([[card(1), card(2)]]);

        await page.key('3');
        assert.equal(page.requests.filter((request) => request.method === 'POST').length, 0, 'No grade before the answer is shown.');

        await page.key(' ');
        assert.match(page.card.innerHTML, /Belakang 1/);
        assert.match(page.card.innerHTML, /data-review-grade="bisa"[\s\S]*3 hari/);
        assert.match(page.card.innerHTML, /data-review-grade="lagi"[\s\S]*10 mnt/);
        assert.match(page.card.innerHTML, /\[1\]<\/span> Modul 3, hlm\. 4/);
        assert.ok(page.announcements.includes('Jawaban: Belakang 1'));

        await page.key('3');
        assert.deepEqual(page.requests.at(-1), { url: '/api/notebooks/review/1', method: 'POST', json: { grade: 'bisa' } });
        assert.match(page.card.innerHTML, /Depan 2/);
        assert.equal(page.progress.textContent, 'Sisa 1 kartu hari ini');
        assert.ok(page.announcements.includes('Bisa. Muncul lagi dalam 3 hari.'));
    });

    it('leaves the keys to text fields and to shortcuts with modifiers', async () => {
        const page = await reviewPage([[card(1)]]);

        await page.key(' ');
        await page.key('1', new FakeElement('TEXTAREA'));
        await page.key('1', new FakeElement('INPUT'));
        await page.key('1', new FakeElement('BODY'), { ctrlKey: true });
        await page.key('1', new FakeElement('BODY'), { metaKey: true });

        assert.equal(page.requests.filter((request) => request.method === 'POST').length, 0);

        await page.key('1');
        assert.deepEqual(page.requests.find((request) => request.method === 'POST')?.json, { grade: 'lagi' });
    });

    it('reads the queue again when it runs out and says when the next card comes', async () => {
        const page = await reviewPage([[card(1)], []]);

        await page.key(' ');
        await page.key('4');

        assert.deepEqual(page.requests.map((request) => request.method), ['GET', 'POST', 'GET']);
        assert.match(page.card.innerHTML, /Selesai untuk sekarang/);
        assert.equal(page.progress.textContent, 'Tidak ada kartu yang menunggu.');
    });
});

describe('Tahan kartu and held cards', () => {
    it('holds a card with an undo that puts it back in front, as it was', async () => {
        const page = await reviewPage([[card(1), card(2)]]);

        await page.click('[data-review-suspend]');
        assert.deepEqual(page.requests.at(-1), { url: '/api/notebooks/review/1', method: 'PATCH', json: { suspended: true } });
        assert.match(page.card.innerHTML, /Depan 2/);
        assert.equal(page.undo.hidden, false);

        await page.click('[data-review-undo]', page.undo);
        assert.deepEqual(page.requests.at(-1), { url: '/api/notebooks/review/1', method: 'PATCH', json: { suspended: false } });
        assert.match(page.card.innerHTML, /Depan 1/);
        assert.equal(page.undo.hidden, true);
        assert.equal(page.progress.textContent, 'Sisa 2 kartu hari ini');
    });

    it('drops the undo once the next card is graded', async () => {
        const page = await reviewPage([[card(1), card(2), card(3)]]);

        await page.click('[data-review-suspend]');
        await page.key(' ');
        await page.key('3');

        assert.equal(page.undo.hidden, true);
    });

    it('says how many cards are held when done, and brings them all back', async () => {
        const page = await reviewPage([[], [card(4)]], { counts: { due: 0, total: 5, suspended: 2 } });

        assert.match(page.card.innerHTML, /Selesai untuk sekarang/);
        assert.match(page.card.innerHTML, /2 kartu sedang ditahan/);
        assert.match(page.card.innerHTML, /data-review-resume>Aktifkan lagi kartu yang ditahan/);

        await page.click('[data-review-resume]');

        assert.deepEqual(page.requests.map((request) => `${request.method} ${request.url}`), [
            'GET /api/notebooks/review?limit=50',
            'POST /api/notebooks/review/resume',
            'GET /api/notebooks/review?limit=50',
        ]);
        assert.match(page.card.innerHTML, /Depan 4/);
    });

    it('does not claim every card was reviewed when every card is held', async () => {
        const page = await reviewPage([[]], { counts: { due: 0, total: 3, suspended: 3 } });

        assert.match(page.card.innerHTML, /Semua kartu sedang ditahan/);
        assert.doesNotMatch(page.card.innerHTML, /sudah diulas/);

        const none = await reviewPage([[]], { counts: { due: 0, total: 0, suspended: 0 } });

        assert.match(none.card.innerHTML, /Belum ada kartu ulasan/);
        assert.doesNotMatch(none.card.innerHTML, /data-review-resume/);
    });
});

describe('Simpan ke ulasan on the notebook page', () => {
    const show = viewSource('notebook/show');
    const start = show.lastIndexOf('\n', show.indexOf('const reviewImportButton = ')) + 1;
    const end = show.lastIndexOf('\n', show.indexOf('const renderQuizItem = ', start)) + 1;
    const helpers = show.slice(start, end);

    function importer({ practiceSaves = {}, messages = [], savePractice = async () => true } = {}) {
        const requests = [];
        const notices = [];
        const state = { practiceSaves, messages };
        const app = {
            ui: { button: { secondarySm: 'secondary' } },
            request: async (url, options = {}) => {
                requests.push({ url, method: options.method, json: options.json });

                return { message: '2 kartu masuk Ulasan harian.' };
            },
            withSubmitLoading: async (button, callback) => callback(),
            showNotice: (text, tone) => notices.push({ text, tone }),
            describeError: (error, fallback) => fallback,
        };
        const api = new Function('app', 'base', 'state', 'savePractice', `${helpers}\nreturn { reviewImportButton, saveToReview };`)(app, '/api/notebooks/7', state, async (message) => {
            requests.push({ url: `save ${message.id}` });

            return savePractice(message);
        });

        return { api, requests, notices };
    }

    const button = (messageId, missed) => ({
        dataset: { reviewImport: String(messageId) },
        hasAttribute: (name) => name === 'data-review-missed' && missed,
    });

    it('saves every flashcard, or only the missed items, without any AI request', async () => {
        const { api, requests, notices } = importer();

        assert.match(api.reviewImportButton(40, 'Simpan ke ulasan'), /data-review-import="40"\s*>Simpan ke ulasan/);
        assert.match(api.reviewImportButton(41, 'Simpan yang salah ke ulasan', true), /data-review-import="41" data-review-missed>/);

        await api.saveToReview(button(40, false));
        await api.saveToReview(button(41, true));

        assert.deepEqual(requests, [
            { url: '/api/notebooks/7/messages/40/review-cards', method: 'POST', json: {} },
            { url: '/api/notebooks/7/messages/41/review-cards', method: 'POST', json: { only_missed: true } },
        ]);
        assert.equal(notices[0].tone, 'success');
        assert.match(notices[0].text, /^2 kartu masuk Ulasan harian\. Ulas di Notebook › Ulasan harian\.$/);
    });

    it('waits for the attempt still being saved before importing its missed questions', async () => {
        let finish;
        const order = [];
        const saving = new Promise((resolve) => {
            finish = resolve;
        }).then((saved) => {
            order.push('practice saved');

            return saved;
        });
        const { api, requests } = importer({ practiceSaves: { 41: saving } });
        const importing = api.saveToReview(button(41, true)).then(() => order.push('imported'));

        await Promise.resolve();
        assert.equal(requests.length, 0, 'Nothing is sent while the attempt is still on its way.');

        finish(true);
        await importing;

        assert.deepEqual(order, ['practice saved', 'imported']);
        assert.deepEqual(requests.map((request) => request.url), ['/api/notebooks/7/messages/41/review-cards']);
    });

    it('saves a failed attempt again first, and imports nothing while it is still not saved', async () => {
        const message = { id: 41, kind: 'quiz' };
        const retried = importer({ practiceSaves: { 41: Promise.resolve(false) }, messages: [message] });

        await retried.api.saveToReview(button(41, true));
        assert.deepEqual(retried.requests.map((request) => request.url), ['save 41', '/api/notebooks/7/messages/41/review-cards']);

        const stillFailing = importer({ practiceSaves: { 41: Promise.resolve(false) }, messages: [message], savePractice: async () => false });

        await stillFailing.api.saveToReview(button(41, true));
        assert.deepEqual(stillFailing.requests.map((request) => request.url), ['save 41']);
    });

    it('keeps each practice save so the import can wait for it', () => {
        assert.match(show, /state\.practiceSaves\[message\.id\] = saving;/);
    });

    it('is wired to the click handler and shown after a quiz, a flashcard round, and on a flashcards output', () => {
        assert.match(show, /const reviewImport = target\.closest\('\[data-review-import\]'\);/);
        assert.match(show, /\} else if \(reviewImport && !reviewImport\.disabled\) \{\s*await saveToReview\(reviewImport\);/);
        assert.match(show, /result\.missed\.length \? reviewImportButton\(message\.id, 'Simpan yang salah ke ulasan', true\)/);
        assert.match(show, /toLearn\.length \? reviewImportButton\(message\.id, 'Simpan yang belum hafal ke ulasan', true\)/);
        assert.match(show, /message\.kind === 'flashcards' && Array\.isArray\(message\.data\) && message\.data\.length \? reviewImportButton\(message\.id, 'Simpan ke ulasan'\)/);
    });
});
