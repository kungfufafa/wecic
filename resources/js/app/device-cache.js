import * as cacheRules from '../lib/cache.js';

/**
 * Device copies of API answers in localStorage (policies in lib/cache.js):
 * reading one to paint a page before the network answers, writing, pruning
 * and clearing them, and the service worker's cache of signed-in pages.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class DeviceCacheFeature {
    getCachedResponse(url) {
        try {
            const requestUrl = this.normalizeCacheUrl(new URL(url, window.location.origin));
            const record = this.readCachedResponse(requestUrl);

            if (!record) {
                return null;
            }

            return {
                ...record,
                payload: this.decorateCachedPayload(record, 'prefill'),
            };
        } catch (error) {
            return null;
        }
    }

    primeCachedView(url, render, label = null) {
        const cached = this.getCachedResponse(url);

        if (!cached) {
            return false;
        }

        try {
            render(cached.payload);
        } catch (error) {
            // A device copy from an older payload shape must not break the page;
            // the live request renders it again.
            console.warn('Cached view could not be rendered', error);

            return false;
        }

        const cacheLabel = label || cached.label || 'data';

        if (navigator.onLine) {
            this.setSyncStatus(`Menampilkan ${cacheLabel} tersimpan. Sinkronkan ke server bila perlu.`, 'info');
        } else {
            this.setSyncStatus(this.buildOfflineCacheMessage(cacheLabel, cached.isStale), 'warning');
        }

        return true;
    }

    resolveCachePolicy(requestUrl, method, options) {
        return cacheRules.resolveCachePolicy(this.cachePolicies, requestUrl, method, options, window.location.origin);
    }

    buildRequestUrl(url, options = {}) {
        return cacheRules.buildRequestUrl(url, options, window.location.origin);
    }

    normalizeCacheUrl(requestUrl) {
        return cacheRules.normalizeCacheUrl(requestUrl);
    }

    buildCacheKey(requestUrl) {
        return cacheRules.buildCacheKey(this.cacheNamespace, requestUrl);
    }

    /**
     * Keep a device copy of a response. Never throws: a full or blocked
     * storage must not turn a successful response into an error.
     */
    writeCachedResponse(requestUrl, payload, policy) {
        try {
            localStorage.setItem(this.buildCacheKey(requestUrl), JSON.stringify(cacheRules.createCacheRecord(payload, policy)));
        } catch (error) {
            // Storage full or blocked: the copy is a convenience, skip it.
        }
    }

    readCachedResponse(requestUrl) {
        const cacheKey = this.buildCacheKey(requestUrl);
        let rawRecord = null;

        try {
            rawRecord = localStorage.getItem(cacheKey);
        } catch (error) {
            return null;
        }

        if (!rawRecord) {
            return null;
        }

        const record = cacheRules.readCacheRecord(rawRecord);

        if (!record) {
            try {
                localStorage.removeItem(cacheKey);
            } catch (error) {
                // Storage blocked: ignore.
            }
        }

        return record;
    }

    pruneExpiredCacheEntries() {
        let keys = [];

        try {
            keys = Object.keys(localStorage);
        } catch (error) {
            return;
        }

        keys.forEach((key) => {
            if (!key.startsWith(`${this.cacheNamespace}:response:`)) {
                return;
            }

            try {
                if (!cacheRules.readCacheRecord(localStorage.getItem(key) || '{}')) {
                    localStorage.removeItem(key);
                }
            } catch (error) {
                // Storage blocked: ignore.
            }
        });
    }

    decorateCachedPayload(record, mode) {
        return cacheRules.decorateCachedPayload(record, mode);
    }

    buildOfflineCacheMessage(label, isStale) {
        return cacheRules.buildOfflineCacheMessage(label, isStale);
    }

    clearCachedResponses() {
        try {
            Object.keys(localStorage).forEach((key) => {
                if (key.startsWith(`${this.cacheNamespace}:response:`)) {
                    localStorage.removeItem(key);
                }
            });
        } catch (error) {
            // Storage blocked: nothing is cached on this device anyway.
        }
    }

    clearCachedResponse(url) {
        if (!url) {
            return;
        }

        try {
            const requestUrl = this.normalizeCacheUrl(new URL(url, window.location.origin));
            localStorage.removeItem(this.buildCacheKey(requestUrl));
        } catch (error) {
            // Storage blocked: nothing to clear.
        }
    }

    clearProtectedPageCache() {
        if ('caches' in window) {
            window.caches.delete(this.protectedPageCacheName).catch(() => {});
        }

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready
                .then((registration) => {
                    registration.active?.postMessage({ type: 'CLEAR_AUTH_CACHES' });
                })
                .catch(() => {});
        }
    }
}
