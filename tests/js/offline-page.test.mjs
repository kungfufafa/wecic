import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { installBrowser, json } from './support/browser.mjs';

/**
 * public/offline.html: the page the service worker shows when a page could
 * not be loaded. Its inline script runs here against the fake browser, so
 * the retry timing is checked as the browser would run it.
 */
const offlinePage = readFileSync(new URL('../../public/offline.html', import.meta.url), 'utf8');
const inlineScript = (() => {
    const scripts = [...offlinePage.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]);

    assert.equal(scripts.length, 1, 'offline.html carries one inline script');

    return scripts[0];
})();
const attemptKey = 'mycic-offline-retry';

let browser;

afterEach(() => browser?.dispose());

/**
 * Open the offline page. `server` answers the probes (and anything else);
 * `stored` is what an earlier copy of the page left in sessionStorage.
 */
function openOfflinePage({ online = true, server = () => Promise.reject(new TypeError('Failed to fetch')), stored = null } = {}) {
    browser = installBrowser({ auth: 'guest', online });
    browser.server = server;

    if (stored) {
        browser.sessionStorage.setItem(attemptKey, JSON.stringify(stored));
    }

    browser.element('div', { id: 'offline-status', class: 'status-strip' });
    browser.element('span', { id: 'offline-status-label' });
    browser.element('h1', { id: 'offline-title' });
    browser.element('p', { id: 'offline-copy' });
    browser.element('p', { id: 'offline-meta' });
    browser.element('button', { id: 'retry-button', type: 'button' });

    new Function(inlineScript)();

    return browser;
}

const text = (id) => browser.document.getElementById(id).textContent;
const probes = () => browser.requests.filter((request) => request.url.pathname === '/up');

/**
 * Advance the clock and note when each probe left.
 */
async function probeTimesOver(milliseconds, step = 1000) {
    const times = [];
    let seen = probes().length;

    for (let elapsed = 0; elapsed < milliseconds; elapsed += step) {
        await browser.clock.advance(step);

        while (probes().length > seen) {
            times.push(browser.clock.now);
            seen += 1;
        }
    }

    return times;
}

describe('offline page while the device is online but the server does not answer', () => {
    it('says it is trying again and never reloads on its own while the probe fails', async () => {
        openOfflinePage();

        assert.equal(text('offline-status-label'), 'Mencoba menyambung lagi…');
        assert.equal(text('offline-title'), 'WeCIC belum bisa dihubungi');
        assert.equal(text('offline-meta'), 'Dicoba lagi otomatis dalam 2 detik.');
        assert.equal(browser.document.getElementById('retry-button').disabled, false, 'the student can still retry at once');

        await probeTimesOver(10 * 60 * 1000);

        assert.equal(browser.location.reloads, 0, 'a failing server never makes the page reload itself');
    });

    it('waits 2 s, 4 s, 8 s … between probes, never more than 60 s', async () => {
        openOfflinePage();

        const times = await probeTimesOver(6 * 60 * 1000);
        const gaps = times.map((time, index) => time - (index === 0 ? 0 : times[index - 1]));

        assert.deepEqual(gaps.slice(0, 7), [2000, 4000, 8000, 16000, 32000, 60000, 60000]);
        assert.ok(gaps.every((gap) => gap <= 60000));
        assert.equal(text('offline-meta'), 'Dicoba lagi otomatis dalam 60 detik.');
    });

    it('asks the health route, uncached', async () => {
        openOfflinePage();

        await browser.clock.advance(2000);

        assert.equal(probes().length, 1);
        assert.equal(browser.requests.length, 1, 'nothing but the probe is requested');
        assert.equal(probes()[0].method, 'GET');
        assert.equal(probes()[0].cache, 'no-store');
    });

    it('reloads once the probe gets an answer, and remembers how far the retries got', async () => {
        let answer = false;

        openOfflinePage({
            server: () => (answer ? json(200, { status: 'up' }) : Promise.reject(new TypeError('Failed to fetch'))),
        });

        await browser.clock.advance(2000 + 4000);
        assert.equal(browser.location.reloads, 0);

        answer = true;
        await browser.clock.advance(8000);

        assert.equal(browser.location.reloads, 1);
        assert.equal(JSON.parse(browser.sessionStorage.getItem(attemptKey)).attempt, 3);

        await browser.clock.advance(5 * 60 * 1000);
        assert.equal(browser.location.reloads, 1, 'one answer, one reload');
    });

    it('an error page from a proxy is not an answer from WeCIC', async () => {
        openOfflinePage({ server: () => json(502, { message: 'Bad Gateway' }) });

        await browser.clock.advance(2 * 60 * 1000);

        assert.ok(probes().length >= 3);
        assert.equal(browser.location.reloads, 0);
    });

    it('gives up on a probe that hangs and tries again later', async () => {
        openOfflinePage({ server: () => new Promise(() => {}) });

        await browser.clock.advance(2000);
        assert.equal(probes().length, 1);
        assert.equal(text('offline-meta'), 'Memeriksa server WeCIC…');

        await browser.clock.advance(8000);
        assert.equal(text('offline-meta'), 'Dicoba lagi otomatis dalam 4 detik.');

        await browser.clock.advance(4000);
        assert.equal(probes().length, 2);
        assert.equal(browser.location.reloads, 0);
    });

    it('a copy of the page shown again right after its reload carries on with the longer wait', async () => {
        openOfflinePage({ stored: { attempt: 3, at: Date.now() } });

        assert.equal(text('offline-meta'), 'Dicoba lagi otomatis dalam 16 detik.');

        await browser.clock.advance(15000);
        assert.equal(probes().length, 0);

        await browser.clock.advance(1000);
        assert.equal(probes().length, 1);
    });

    it('an old count starts again from the shortest wait', async () => {
        openOfflinePage({ stored: { attempt: 5, at: Date.now() - 10 * 60 * 1000 } });

        assert.equal(text('offline-meta'), 'Dicoba lagi otomatis dalam 2 detik.');
    });

    it('the retry button reloads at once', async () => {
        openOfflinePage();

        browser.document.getElementById('retry-button').dispatchEvent(new Event('click'));

        assert.equal(browser.location.reloads, 1);
    });
});

describe('offline page while the device has no connection', () => {
    it('waits for the connection without asking the server', async () => {
        openOfflinePage({ online: false });

        assert.equal(text('offline-status-label'), 'Offline');
        assert.equal(text('offline-title'), 'Tidak ada koneksi');

        await browser.clock.advance(10 * 60 * 1000);

        assert.equal(browser.requests.length, 0);
        assert.equal(browser.location.reloads, 0);
    });

    it('reloads when the connection comes back', async () => {
        openOfflinePage({ online: false });

        browser.navigator.onLine = true;
        browser.window.dispatchEvent(new Event('online'));

        assert.equal(text('offline-status-label'), 'Online lagi');
        assert.equal(browser.document.getElementById('retry-button').disabled, true);

        await browser.clock.advance(600);

        assert.equal(browser.location.reloads, 1);
    });

    it('losing the connection stops the probes until it is back', async () => {
        openOfflinePage();

        await browser.clock.advance(2000);
        assert.equal(probes().length, 1);

        browser.navigator.onLine = false;
        browser.window.dispatchEvent(new Event('offline'));

        assert.equal(text('offline-status-label'), 'Offline');

        await browser.clock.advance(10 * 60 * 1000);

        assert.equal(probes().length, 1);
        assert.equal(browser.location.reloads, 0);
    });
});
