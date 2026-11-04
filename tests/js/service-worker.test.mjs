import { beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

/**
 * public/sw.js runs here as the browser runs it: a classic script with
 * `self`, `caches` and `fetch`, driven through its own event listeners.
 */
const origin = 'https://app.invalid';
const serviceWorkerSource = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

function absolute(request) {
    return new URL(typeof request === 'string' ? request : request.url, origin).href;
}

function createCacheStorage() {
    const stores = new Map();
    // Every request a cache.addAll() fetched, as the service worker built it.
    const fetched = [];
    const open = (name) => {
        if (!stores.has(name)) {
            stores.set(name, new Map());
        }

        const store = stores.get(name);

        return {
            async put(request, response) {
                store.set(absolute(request), response);
            },
            async match(request) {
                return store.get(absolute(request));
            },
            async keys() {
                return [...store.keys()].map((url) => ({ url }));
            },
            async delete(request) {
                return store.delete(absolute(request));
            },
            async addAll(requests) {
                requests.forEach((request) => {
                    fetched.push(request);
                    store.set(absolute(request), page(absolute(request)));
                });
            },
        };
    };

    return {
        stores,
        fetched,
        async open(name) {
            return open(name);
        },
        async delete(name) {
            return stores.delete(name);
        },
        async keys() {
            return [...stores.keys()];
        },
        async match(request) {
            for (const store of stores.values()) {
                if (store.has(absolute(request))) {
                    return store.get(absolute(request));
                }
            }

            return undefined;
        },
    };
}

function page(url, { ok = true } = {}) {
    return { ok, url, clone() {
        return this;
    } };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

let worker;

function loadServiceWorker() {
    const listeners = {};
    const caches = createCacheStorage();
    const network = { respond: (request) => page(absolute(request)) };
    const context = vm.createContext({
        URL,
        // Resolved against the worker's own location, as in a browser.
        Request: class {
            constructor(url, init = {}) {
                this.url = new URL(url, origin).href;
                this.method = init.method ?? 'GET';
                this.cache = init.cache ?? 'default';
            }
        },
        console,
        caches,
        fetch: async (request) => network.respond(request),
        self: {
            location: { origin },
            clients: { claim() {} },
            skipWaiting() {},
            addEventListener(type, listener) {
                listeners[type] = listener;
            },
        },
    });

    vm.runInContext(serviceWorkerSource, context, { filename: 'public/sw.js' });

    const constant = (name) => vm.runInContext(name, context);

    return {
        caches,
        network,
        constant,
        carriesCredentials: (url) => context.carriesCredentials(new URL(url, origin)),
        storedUrls(cacheName) {
            return [...(caches.stores.get(cacheName)?.keys() ?? [])].map((url) => new URL(url).pathname + new URL(url).search);
        },
        async dispatch(type, event) {
            const pending = [];
            let response;

            listeners[type]({
                ...event,
                waitUntil: (promise) => pending.push(promise),
                respondWith: (promise) => {
                    response = promise;
                },
            });
            await Promise.all(pending);
            const answer = await response;
            await settle();

            return { handled: response !== undefined, answer };
        },
        navigate(path, mode = 'navigate') {
            return this.dispatch('fetch', { request: { method: 'GET', url: absolute(path), mode } });
        },
    };
}

beforeEach(() => {
    worker = loadServiceWorker();
});

describe('service worker caches', () => {
    it('keeps a signed-in page only in the cache that logout clears', async () => {
        const protectedCache = worker.constant('PROTECTED_PAGE_CACHE_NAME');

        await worker.navigate('/class/12?course_id=3');

        assert.deepEqual(worker.storedUrls(protectedCache), ['/class/12?course_id=3']);
        assert.deepEqual(worker.storedUrls(worker.constant('CACHE_NAME')), []);
    });

    it('is the cache the app clears on logout', () => {
        const appCacheName = appSource.match(/this\.protectedPageCacheName = '([^']+)';/)?.[1];

        assert.equal(appCacheName, worker.constant('PROTECTED_PAGE_CACHE_NAME'));
    });

    it('never stores a page whose URL carries a password or token', async () => {
        await worker.navigate('/password?password=rahasia&new_password=baru');
        await worker.navigate('/login?password=rahasia');
        await worker.navigate('/dashboard?token=abc');

        assert.deepEqual(worker.storedUrls(worker.constant('PROTECTED_PAGE_CACHE_NAME')), []);
        assert.deepEqual(worker.storedUrls(worker.constant('CACHE_NAME')), []);
    });

    it('never stores the login page in place of a signed-in page after the session ended', async () => {
        worker.network.respond = () => page(`${origin}/login`);

        await worker.navigate('/dashboard');

        assert.deepEqual(worker.storedUrls(worker.constant('PROTECTED_PAGE_CACHE_NAME')), []);
    });

    it('never stores a failed signed-in page', async () => {
        worker.network.respond = (request) => page(absolute(request), { ok: false });

        await worker.navigate('/exam/4');

        assert.deepEqual(worker.storedUrls(worker.constant('PROTECTED_PAGE_CACHE_NAME')), []);
    });

    it('keeps the guest login page for offline use, but no page it does not know', async () => {
        await worker.navigate('/login');
        await worker.navigate('/halaman-baru');

        assert.deepEqual(worker.storedUrls(worker.constant('CACHE_NAME')), ['/login']);
    });

    it('leaves API requests and other sites to the network', async () => {
        assert.equal((await worker.navigate('/api/dashboard', 'cors')).handled, false);
        assert.equal((await worker.dispatch('fetch', { request: { method: 'GET', url: 'https://portal.invalid/x', mode: 'navigate' } })).handled, false);
        assert.equal((await worker.dispatch('fetch', { request: { method: 'POST', url: absolute('/login'), mode: 'navigate' } })).handled, false);
    });

    it('keeps the SVG favicon and brand artwork available offline in the static cache', async () => {
        const assets = [
            '/favicon.svg',
            '/icons/wecic-logo.svg',
            '/icons/icon-192.png',
        ];

        for (const asset of assets) {
            await worker.navigate(asset, 'cors');
        }

        assert.deepEqual(worker.storedUrls(worker.constant('STATIC_CACHE_NAME')), assets);
        assert.deepEqual(worker.storedUrls(worker.constant('CACHE_NAME')), []);
        assert.deepEqual(worker.storedUrls(worker.constant('PROTECTED_PAGE_CACHE_NAME')), []);

        worker.network.respond = () => {
            throw new TypeError('Failed to fetch');
        };

        for (const asset of assets) {
            assert.equal((await worker.navigate(asset, 'cors')).answer.url, absolute(asset));
        }
    });

    it('refreshes a cached logo from the network for the next offline visit', async () => {
        const asset = '/icons/wecic-logo.svg';
        const previousArtwork = page(absolute(asset));
        const updatedArtwork = page(absolute(asset));
        worker.network.respond = () => previousArtwork;
        await worker.navigate(asset, 'cors');

        worker.network.respond = () => updatedArtwork;

        assert.equal((await worker.navigate(asset, 'cors')).answer, previousArtwork);

        worker.network.respond = () => {
            throw new TypeError('Failed to fetch');
        };

        assert.equal((await worker.navigate(asset, 'cors')).answer, updatedArtwork);
    });

    it('stores the offline page fetched past the HTTP cache, so a device gets the page this version ships', async () => {
        await worker.dispatch('install', {});

        assert.deepEqual(
            worker.caches.fetched.map((request) => ({ path: new URL(request.url).pathname, cache: request.cache })),
            [{ path: worker.constant('OFFLINE_URL'), cache: 'reload' }]
        );
        assert.deepEqual(worker.storedUrls(worker.constant('CACHE_NAME')), [worker.constant('OFFLINE_URL')]);
    });

    it('never answers the offline page\'s health probe from a cache', async () => {
        await worker.dispatch('install', {});
        await worker.navigate('/up', 'cors');

        assert.deepEqual([...worker.caches.stores.values()].flatMap((store) => [...store.keys()]).map((url) => new URL(url).pathname), [worker.constant('OFFLINE_URL')]);

        worker.network.respond = () => {
            throw new TypeError('Failed to fetch');
        };

        assert.equal((await worker.navigate('/up', 'cors')).answer, undefined, 'a server that is down must look down to the probe');
    });

    it('shows the stored signed-in page offline, else the offline page', async () => {
        await worker.dispatch('install', {});
        await worker.navigate('/krs');
        worker.network.respond = () => {
            throw new TypeError('Failed to fetch');
        };

        assert.equal((await worker.navigate('/krs')).answer.url, `${origin}/krs`);
        assert.equal((await worker.navigate('/grades')).answer.url, `${origin}${worker.constant('OFFLINE_URL')}`);
    });
});

describe('ending a session', () => {
    it('drops every signed-in page and keeps only the guest pages and the offline page', async () => {
        const general = worker.constant('CACHE_NAME');

        await worker.dispatch('install', {});
        await worker.navigate('/login');
        await worker.navigate('/dashboard');
        const generalCache = await worker.caches.open(general);
        await generalCache.put(`${origin}/archive/print/1`, page(`${origin}/archive/print/1`));

        await worker.dispatch('message', { data: { type: 'CLEAR_AUTH_CACHES' } });

        assert.equal(worker.caches.stores.has(worker.constant('PROTECTED_PAGE_CACHE_NAME')), false);
        assert.deepEqual(worker.storedUrls(general).sort(), [worker.constant('OFFLINE_URL'), '/login'].sort());
    });

    it('ignores other messages', async () => {
        await worker.navigate('/dashboard');
        await worker.dispatch('message', { data: { type: 'SOMETHING_ELSE' } });

        assert.deepEqual(worker.storedUrls(worker.constant('PROTECTED_PAGE_CACHE_NAME')), ['/dashboard']);
    });

    it('drops the previous shell and artwork caches on activate while preserving signed-in pages', async () => {
        await (await worker.caches.open('mycic-v6')).put(`${origin}/offline.html`, page(`${origin}/offline.html`));
        await (await worker.caches.open('mycic-static-v3')).put(`${origin}/icons/icon-192.png`, page(`${origin}/icons/icon-192.png`));
        await worker.dispatch('install', {});
        await worker.navigate('/favicon.svg', 'cors');
        await worker.navigate('/dashboard');

        await worker.dispatch('activate', {});

        assert.deepEqual((await worker.caches.keys()).sort(), [
            worker.constant('CACHE_NAME'),
            worker.constant('STATIC_CACHE_NAME'),
            worker.constant('PROTECTED_PAGE_CACHE_NAME'),
        ].sort());
        assert.equal(worker.caches.stores.has('mycic-v6'), false);
        assert.equal(worker.caches.stores.has('mycic-static-v3'), false);
        assert.deepEqual(worker.storedUrls(worker.constant('CACHE_NAME')), [worker.constant('OFFLINE_URL')]);
        assert.deepEqual(worker.storedUrls(worker.constant('STATIC_CACHE_NAME')), ['/favicon.svg']);
        assert.deepEqual(worker.storedUrls(worker.constant('PROTECTED_PAGE_CACHE_NAME')), ['/dashboard']);
    });
});

describe('carriesCredentials', () => {
    it('spots every password field the app has, in any case', () => {
        for (const name of ['password', 'new_password', 'password_confirmation', 'Password', 'token', '_token', 'client_secret']) {
            assert.equal(worker.carriesCredentials(`/x?${name}=1`), true, name);
        }
    });

    it('leaves ordinary parameters alone', () => {
        assert.equal(worker.carriesCredentials('/meeting/1?courses_id=2&class_id=3'), false);
        assert.equal(worker.carriesCredentials('/dashboard'), false);
    });
});
