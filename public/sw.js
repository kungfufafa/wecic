// v5: v4 could hold signed-in pages (archive print-outs) outside the cache
// that logout clears; activate drops it. Installing v5 also stores the
// offline page that probes the server before it reloads. A device keeps its
// stored offline.html until this file changes, so a change to that page
// ships with a change here.
const CACHE_NAME = 'mycic-v7';
const PROTECTED_PAGE_CACHE_NAME = 'mycic-pages-v2';
const STATIC_CACHE_NAME = 'mycic-static-v4';
const OFFLINE_URL = '/offline.html';
// Every signed-in page: cached only in PROTECTED_PAGE_CACHE_NAME, which
// logout and an ended session clear.
const PROTECTED_PAGE_PREFIXES = [
    '/dashboard',
    '/profile',
    '/password',
    '/class',
    '/meeting',
    '/exam',
    '/finance',
    '/krs',
    '/grades',
    '/transcript',
    '/announcement',
    '/skkm',
    '/thesis',
    '/library',
    '/more',
    '/notifications',
    '/notebook',
    '/archive',
    '/account',
];
// Pages without a session that may be kept for offline use.
const GUEST_PAGES = ['/login'];
const STATIC_ASSET_PREFIXES = ['/build/', '/icons/', '/vendor/'];

const SHELL_ASSETS = [
    OFFLINE_URL,
];

function isProtectedNavigation(pathname) {
    return PROTECTED_PAGE_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

// A URL that carries a password, token or API key (a form that ever
// submitted natively) never becomes a Cache Storage key.
function carriesCredentials(url) {
    return [...url.searchParams.keys()].some((name) => /password|secret|token|api_?key/i.test(name));
}

function isStaticAsset(pathname) {
    return pathname === '/manifest.json'
        || pathname === '/favicon.ico'
        || pathname === '/favicon.svg'
        || STATIC_ASSET_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}

// Install: cache offline page. Fetched past the browser's HTTP cache, so a
// copy it still holds of an older offline page is never what gets stored.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(
            SHELL_ASSETS.map((url) => new Request(url, { cache: 'reload' }))
        ))
    );
    self.skipWaiting();
});

// Activate: remove old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => ![CACHE_NAME, STATIC_CACHE_NAME, PROTECTED_PAGE_CACHE_NAME].includes(key))
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('message', (event) => {
    if (event.data?.type !== 'CLEAR_AUTH_CACHES') {
        return;
    }

    event.waitUntil(Promise.all([
        caches.delete(PROTECTED_PAGE_CACHE_NAME),
        // Anything else in the general cache goes too; the offline page and
        // the guest pages (no session, nothing personal) stay.
        caches.open(CACHE_NAME).then(async (cache) => {
            const requests = await cache.keys();
            const kept = [...SHELL_ASSETS, ...GUEST_PAGES];

            await Promise.all(requests
                .filter((request) => !kept.includes(new URL(request.url).pathname))
                .map((request) => cache.delete(request)));
        }),
    ]));
});

// Fetch: network-first, fallback to cache then offline page
self.addEventListener('fetch', (event) => {
    // Only handle GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);

    // Skip cross-origin requests (fonts, APIs, etc.)
    if (url.origin !== self.location.origin) {
        return;
    }

    // Skip API requests — always network only
    if (url.pathname.startsWith('/api/')) {
        return;
    }

    if (isStaticAsset(url.pathname)) {
        event.respondWith((async () => {
            const cache = await caches.open(STATIC_CACHE_NAME);
            const cached = await cache.match(event.request);
            const networkRequest = fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        cache.put(event.request, response.clone());
                    }

                    return response;
                })
                .catch(() => cached);

            return cached || networkRequest;
        })());

        return;
    }

    if (event.request.mode === 'navigate' && isProtectedNavigation(url.pathname)) {
        event.respondWith((async () => {
            const cache = await caches.open(PROTECTED_PAGE_CACHE_NAME);

            try {
                const networkResponse = await fetch(event.request);
                const finalUrl = new URL(networkResponse.url);

                if (networkResponse.ok && !finalUrl.pathname.startsWith('/login') && !carriesCredentials(url)) {
                    cache.put(event.request, networkResponse.clone());
                }

                return networkResponse;
            } catch (error) {
                const cachedPage = await cache.match(event.request);

                if (cachedPage) {
                    return cachedPage;
                }

                return caches.match(OFFLINE_URL);
            }
        })());

        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((networkResponse) => {
                // Only guest pages are kept here: a signed-in page this list
                // does not know must never outlive the session.
                if (
                    event.request.mode === 'navigate' &&
                    networkResponse.ok &&
                    GUEST_PAGES.includes(url.pathname) &&
                    !carriesCredentials(url)
                ) {
                    const clone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) =>
                        cache.put(event.request, clone)
                    );
                }
                return networkResponse;
            })
            .catch(async () => {
                // Try cache first
                const cached = await caches.match(event.request);
                if (cached) {
                    return cached;
                }

                // Navigation fallback to offline page
                if (event.request.mode === 'navigate') {
                    return caches.match(OFFLINE_URL);
                }
            })
    );
});
