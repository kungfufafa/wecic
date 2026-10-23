/**
 * How the app reads an answer from its own API: which failures send the
 * student to the login page, and the words a failed request shows.
 * Pure (no DOM, no storage), so tests/js runs it under Node.
 */

/**
 * The framework's English defaults: a student cannot act on them, so the
 * status decides the words instead.
 */
const FRAMEWORK_DEFAULT_MESSAGE = /^(?:Too Many Attempts\.|Server Error|Service Unavailable|CSRF token mismatch\.|Unauthenticated\.|Unauthorized|Forbidden|This action is unauthorized\.|Not Found|No query results for model .*|The route .* could not be found\.|Method Not Allowed|The \S+ method is not supported for route .*|Page Expired)$/i;

/**
 * The message for a failed response. The app's own messages pass through;
 * the framework's English defaults ("Too Many Attempts.", "Server Error")
 * and bare proxy pages (502/504 HTML) become words a student can act on.
 *
 * @param {{ status: number, headers: { get(name: string): string|null } }} response
 * @param {unknown} payload The decoded JSON body, or null.
 * @returns {string}
 */
export function httpErrorMessage(response, payload) {
    const message = typeof payload?.message === 'string' ? payload.message.trim() : '';

    if (message !== '' && !FRAMEWORK_DEFAULT_MESSAGE.test(message)) {
        return message;
    }

    if (response.status === 429) {
        const seconds = Number.parseInt(response.headers.get('retry-after') || '', 10);

        if (!Number.isFinite(seconds) || seconds <= 0) {
            return 'Terlalu sering mencoba. Tunggu sebentar lalu coba lagi.';
        }

        return seconds >= 60
            ? `Terlalu sering mencoba. Coba lagi dalam ${Math.ceil(seconds / 60)} menit.`
            : `Terlalu sering mencoba. Coba lagi dalam ${seconds} detik.`;
    }

    if (response.status === 419) {
        return 'Halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    }

    if (response.status === 401) {
        return 'Sesi kampus sudah berakhir. Silakan masuk lagi.';
    }

    if (response.status === 403) {
        return 'Kamu tidak punya akses ke data ini.';
    }

    if (response.status === 404) {
        return 'Data yang diminta tidak ditemukan.';
    }

    if (response.status >= 500) {
        return 'Server sedang bermasalah. Coba lagi sebentar lagi.';
    }

    return 'Permintaan tidak bisa diproses. Coba lagi sebentar lagi.';
}

/**
 * The error a failed (or unreadable) response throws. Pages show
 * `error.payload.message` first, so it carries the same words.
 *
 * @param {{ ok: boolean, status: number, headers: { get(name: string): string|null } }} response
 * @param {unknown} payload
 * @returns {Error & { status: number, payload: unknown }}
 */
export function responseError(response, payload) {
    const message = response.ok
        ? 'Server mengirim data yang tidak bisa dibaca. Coba lagi sebentar lagi.'
        : httpErrorMessage(response, payload);
    const error = new Error(message);

    error.status = response.status;
    error.payload = payload && typeof payload === 'object' && !Array.isArray(payload)
        ? { ...payload, message }
        : payload;

    return error;
}

/**
 * What a request does with the answer it got:
 * - `{ action: 'login', message, tone }`: the session ended on a page that
 *   needs one. A 401 is the campus session; a 419 is the app session (the
 *   CSRF token no longer matches), and "CSRF token mismatch" means nothing
 *   to a student. Guest pages and `redirectOnAuth: false` callers get the
 *   error instead.
 * - `{ action: 'fail', error }`: a failed or unreadable answer.
 * - `{ action: 'accept' }`: use the payload.
 *
 * @param {{ ok: boolean, status: number, headers: { get(name: string): string|null } }} response
 * @param {unknown} payload
 * @param {{ unreadable?: boolean, redirectOnAuth?: boolean, authMode?: string }} options
 *        `authMode` is the page's `body[data-auth]` ('required' | 'guest').
 * @returns {{ action: 'login', message: string, tone: string } | { action: 'fail', error: Error } | { action: 'accept' }}
 */
export function responseOutcome(response, payload, { unreadable = false, redirectOnAuth = true, authMode = '' } = {}) {
    const redirects = redirectOnAuth !== false && authMode === 'required';

    if (response.status === 401 && redirects) {
        return { action: 'login', message: payload?.message || 'Sesi kampus sudah berakhir.', tone: 'error' };
    }

    if (response.status === 419 && redirects) {
        return { action: 'login', message: 'Sesi aplikasi sudah berakhir. Silakan masuk lagi.', tone: 'warning' };
    }

    if (!response.ok || unreadable) {
        return { action: 'fail', error: responseError(response, payload) };
    }

    return { action: 'accept' };
}

/**
 * How long a request may wait: a slow campus portal must not leave the UI
 * waiting forever. Uploads get the longest wait; `options.timeout` wins,
 * and 0 waits without a limit.
 *
 * @param {string} method
 * @param {{ timeout?: number, formData?: unknown }} options
 * @returns {number}
 */
export function requestTimeoutMs(method, options = {}) {
    return options.timeout ?? (
        method === 'GET' ? 30000 : (options.formData instanceof FormData ? 180000 : 60000)
    );
}

/**
 * The words a page shows for an error it caught.
 *
 * @param {unknown} error
 * @param {string} fallback
 * @returns {string}
 */
export function describeError(error, fallback = 'Terjadi kesalahan saat memuat data.') {
    if (error?.isOffline) {
        return 'Kamu sedang offline. Sambungkan internet lalu coba lagi.';
    }

    if (error?.isTimeout) {
        return 'Portal kampus lambat merespons. Coba lagi sebentar lagi.';
    }

    return error?.payload?.message || error?.message || fallback;
}

/**
 * @param {unknown} error
 * @returns {boolean}
 */
export function isLikelyNetworkError(error) {
    return error instanceof TypeError || error?.name === 'AbortError';
}

/**
 * @param {string} message
 * @returns {Error & { isTimeout: true, status: 0 }}
 */
export function createTimeoutError(message) {
    const error = new Error(message);
    error.isTimeout = true;
    error.status = 0;

    return error;
}

/**
 * @param {string} message
 * @returns {Error & { isOffline: true, status: 0 }}
 */
export function createOfflineError(message) {
    const error = new Error(message);
    error.isOffline = true;
    error.status = 0;

    return error;
}
