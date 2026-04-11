const CACHE_NAME = 'mycic-v1';
const OFFLINE_URL = '/offline.html';

const SHELL_ASSETS = [
    OFFLINE_URL,
];

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
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
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
