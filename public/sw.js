const CACHE_NAME = 'mycic-v4';
const PROTECTED_PAGE_CACHE_NAME = 'mycic-pages-v2';
const STATIC_CACHE_NAME = 'mycic-static-v2';
const OFFLINE_URL = '/offline.html';
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
];
const STATIC_ASSET_PREFIXES = ['/build/', '/icons/', '/vendor/'];

const SHELL_ASSETS = [
    OFFLINE_URL,
];

function isProtectedNavigation(pathname) {
    return PROTECTED_PAGE_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

function isStaticAsset(pathname) {
    return pathname === '/manifest.json'
        || pathname === '/favicon.ico'
        || STATIC_ASSET_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}

// Install: cache offline page
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS))
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

    event.waitUntil(caches.delete(PROTECTED_PAGE_CACHE_NAME));
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

                if (networkResponse.ok && !finalUrl.pathname.startsWith('/login')) {
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
                // Cache successful HTML navigations for offline use
                if (
                    event.request.mode === 'navigate' &&
                    networkResponse.ok
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
