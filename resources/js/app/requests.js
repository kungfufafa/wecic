import { csrfHeaders } from '../csrf';
import * as dateRules from '../lib/dates.js';
import * as httpRules from '../lib/http.js';

/**
 * Requests to MyCIC's own API (`app.request()`): JSON, CSRF, the
 * background header, timeouts, the device copy a failed read falls back to,
 * and the "Data diperbarui …" line a page writes after a read.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class RequestFeature {
    async request(url, options = {}) {
        // Decided as the read starts: one begun by background work before it
        // first waits is the app's own too (see runAsBackground()).
        const requestOptions = this.backgroundWork > 0 ? { ...options, background: true } : options;

        try {
            return await this.performRequest(url, requestOptions);
        } finally {
            if (this.pendingQuickAttendance && new URL(url, window.location.origin).pathname === '/api/dashboard') {
                // Runs after the page has rendered the dashboard it asked for.
                // While today's attendance is still being checked the request
                // stays open: a later dashboard read (or the 30 s timer) ends it.
                window.setTimeout(() => {
                    if (this.quickQrTargetFromCache() !== undefined) {
                        this.resolvePendingQuickAttendance({ final: true });
                    }
                }, 0);
            }
        }
    }

    async performRequest(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const requestUrl = this.buildRequestUrl(url, options);
        const cacheUrl = this.normalizeCacheUrl(requestUrl);
        const cachePolicy = this.resolveCachePolicy(cacheUrl, method, options);
        const cachedResponse = cachePolicy ? this.readCachedResponse(cacheUrl) : null;

        if (!navigator.onLine) {
            if (cachedResponse) {
                this.setSyncStatus(this.buildOfflineCacheMessage(cachePolicy.label, cachedResponse.isStale), 'warning');

                return this.decorateCachedPayload(cachedResponse, 'offline');
            }

            throw this.createOfflineError(
                method === 'GET'
                    ? 'Koneksi internet tidak tersedia dan data offline belum tersimpan.'
                    : 'Kamu sedang offline. Sambungkan internet untuk mengirim perubahan.'
            );
        }

        // Only a read marked as the app's own carries the header; a POST is
        // always the student's action and is never marked.
        const background = method === 'GET' && options.background === true;
        const config = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...csrfHeaders(),
                ...(background ? { [this.backgroundRequestHeader]: '1' } : {}),
                ...(options.headers || {}),
            },
        };

        if (options.json !== undefined) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(options.json);
        }

        if (options.formData instanceof FormData) {
            config.body = options.formData;
        }

        const timeoutMs = httpRules.requestTimeoutMs(method, options);
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        let timedOut = false;
        const timeoutTimer = controller && timeoutMs > 0
            ? window.setTimeout(() => {
                timedOut = true;
                controller.abort();
            }, timeoutMs)
            : null;

        if (controller) {
            config.signal = controller.signal;
        }

        try {
            const response = await fetch(requestUrl.toString(), config);

            // An answer arrived, so a "network error" earlier was a hiccup (an
            // iOS app switch, a Wi-Fi handoff) the browser never reported as
            // offline: the shell comes back online on its own.
            if (this.onlineState === false && navigator.onLine) {
                this.updateConnectionState(true, { announce: false });
            }

            const contentType = response.headers.get('content-type') || '';
            let payload = null;
            let unreadable = false;

            if (contentType.includes('application/json')) {
                try {
                    payload = await response.json();
                } catch (parseError) {
                    if (!(parseError instanceof SyntaxError)) {
                        throw parseError;
                    }

                    unreadable = true;
                }
            }

            const outcome = httpRules.responseOutcome(response, payload, {
                unreadable,
                redirectOnAuth: options.redirectOnAuth,
                authMode: document.body.dataset.auth,
            });

            if (outcome.action === 'login') {
                this.sendToLogin(outcome.message, outcome.tone);

                return null;
            }

            if (outcome.action === 'fail') {
                throw outcome.error;
            }

            if (cachePolicy && payload && typeof payload === 'object') {
                this.writeCachedResponse(cacheUrl, payload, cachePolicy);
            }

            return payload;
        } catch (error) {
            if (timedOut) {
                if (cachePolicy && cachedResponse) {
                    this.setSyncStatus(`Server lambat merespons. ${this.buildOfflineCacheMessage(cachePolicy.label, cachedResponse.isStale)}`, 'warning');

                    return this.decorateCachedPayload(cachedResponse, 'fallback');
                }

                throw this.createTimeoutError(
                    method === 'GET'
                        ? 'Server kampus lambat merespons. Coba lagi sebentar lagi.'
                        : 'Portal kampus lambat merespons. Status aksi akan diperiksa ulang.'
                );
            }

            if (cachePolicy && cachedResponse && this.isLikelyNetworkError(error)) {
                this.updateConnectionState(false, { announce: false });
                this.setSyncStatus(this.buildOfflineCacheMessage(cachePolicy.label, cachedResponse.isStale), 'warning');

                return this.decorateCachedPayload(cachedResponse, 'fallback');
            }

            if (this.isLikelyNetworkError(error)) {
                this.updateConnectionState(false, { announce: false });
                throw this.createOfflineError(
                    method === 'GET'
                        ? 'Koneksi internet terputus saat memuat data.'
                        : 'Koneksi internet terputus saat mengirim perubahan.'
                );
            }

            throw error;
        } finally {
            window.clearTimeout(timeoutTimer);
        }
    }

    isLikelyNetworkError(error) {
        return httpRules.isLikelyNetworkError(error);
    }

    createTimeoutError(message) {
        return httpRules.createTimeoutError(message);
    }

    createOfflineError(message) {
        return httpRules.createOfflineError(message);
    }

    /**
     * Message for a failed request, as a student should read it.
     */
    describeError(error, fallback) {
        return httpRules.describeError(error, fallback);
    }

    /**
     * The shared freshness line, in the same words as the pages' own
     * "Diperbarui …" lines: "Data diperbarui 5 menit lalu · sedang diperbarui".
     */
    markViewSynced(label, payload = null, { force = false } = {}) {
        const dataOrigin = payload?.meta?.data_origin || null;
        const portalTime = this.portalDataTime(payload);
        const updatedAgo = portalTime ? this.formatTimeAgo(portalTime) : '';
        const updatedLine = updatedAgo ? `Data diperbarui ${updatedAgo}` : 'Data baru saja dimuat';
        const integrity = payload?.meta?.integrity || null;

        if (integrity?.status === 'suspect') {
            this.setSyncStatus(`Data ${label} dari portal terlihat janggal. MyCIC tetap menampilkan versi tersimpan; periksa di menu Arsip.`, 'warning');

            return;
        }

        if (payload?.meta?.status === 'offline') {
            this.setSyncStatus(`Mode offline. Menampilkan arsip MyCIC${updatedAgo ? ` (terakhir dari portal ${updatedAgo})` : ''}.`, 'warning');

            return;
        }

        if (dataOrigin === 'archive') {
            const statusSuffix = payload?.meta?.status === 'syncing' ? ' · sedang diperbarui dari portal' : '';
            this.setSyncStatus(`Menampilkan arsip MyCIC${updatedAgo ? ` dari ${updatedAgo}` : ''}${statusSuffix}`, payload?.meta?.status === 'failed' ? 'warning' : 'info');

            return;
        }

        if (payload?.meta?.status === 'failed') {
            this.setSyncStatus(`Portal belum bisa dihubungi. Menampilkan data terakhir${updatedAgo ? ` (diperbarui ${updatedAgo})` : ''}.`, 'warning');

            return;
        }

        if (force) {
            if (dataOrigin === 'device_cache') {
                this.setSyncStatus('Server belum terjangkau. Menampilkan data tersimpan di perangkat.', 'warning');

                return;
            }

            this.setSyncStatus(updatedLine, 'success');

            return;
        }

        if (dataOrigin === 'snapshot' || dataOrigin === 'server_cache') {
            const statusSuffix = payload?.meta?.status === 'syncing' ? ' · sedang diperbarui dari portal' : '';
            this.setSyncStatus(`${updatedLine}${statusSuffix}`, 'info');

            return;
        }

        this.setSyncStatus(updatedLine, 'success');
    }

    portalDataTime(payload) {
        return dateRules.portalDataTime(payload);
    }
}
