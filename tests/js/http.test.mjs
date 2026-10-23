import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    createOfflineError,
    createTimeoutError,
    describeError,
    httpErrorMessage,
    isLikelyNetworkError,
    requestTimeoutMs,
    responseError,
    responseOutcome,
} from '../../resources/js/lib/http.js';

function response(status, headers = {}) {
    return { status, ok: status >= 200 && status < 300, headers: new Headers(headers) };
}

describe('httpErrorMessage', () => {
    it('passes the app\'s own message through', () => {
        assert.equal(httpErrorMessage(response(422), { message: '  Kode absensi salah.  ' }), 'Kode absensi salah.');
        assert.equal(httpErrorMessage(response(429), { message: 'Batas unggah tercapai.' }), 'Batas unggah tercapai.');
    });

    it('replaces the framework\'s English defaults with words a student can act on', () => {
        for (const message of ['Too Many Attempts.', 'Server Error', 'Service Unavailable', 'CSRF token mismatch.', 'Unauthenticated.', 'Forbidden', 'No query results for model [App\\Models\\Notebook] 7', 'The route api/x could not be found.', 'The POST method is not supported for route api/x.', 'page expired']) {
            assert.equal(httpErrorMessage(response(503), { message }), 'Server sedang bermasalah. Coba lagi sebentar lagi.', message);
        }

        assert.equal(httpErrorMessage(response(419), { message: 'CSRF token mismatch.' }), 'Halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        assert.equal(httpErrorMessage(response(404), { message: 'No query results for model [App\\Models\\Notebook] 7' }), 'Data yang diminta tidak ditemukan.');
    });

    it('tells how long to wait after too many attempts', () => {
        assert.equal(httpErrorMessage(response(429, { 'Retry-After': '42' }), { message: 'Too Many Attempts.' }), 'Terlalu sering mencoba. Coba lagi dalam 42 detik.');
        assert.equal(httpErrorMessage(response(429, { 'Retry-After': '60' }), null), 'Terlalu sering mencoba. Coba lagi dalam 1 menit.');
        assert.equal(httpErrorMessage(response(429, { 'Retry-After': '61' }), null), 'Terlalu sering mencoba. Coba lagi dalam 2 menit.');
    });

    it('asks for a short wait when the retry header is missing or unusable', () => {
        for (const headers of [{}, { 'Retry-After': '0' }, { 'Retry-After': '-5' }, { 'Retry-After': 'Wed, 21 Oct 2026 07:28:00 GMT' }]) {
            assert.equal(httpErrorMessage(response(429, headers), null), 'Terlalu sering mencoba. Tunggu sebentar lalu coba lagi.', JSON.stringify(headers));
        }
    });

    it('maps each status without a usable message', () => {
        assert.equal(httpErrorMessage(response(401), null), 'Sesi kampus sudah berakhir. Silakan masuk lagi.');
        assert.equal(httpErrorMessage(response(403), {}), 'Kamu tidak punya akses ke data ini.');
        assert.equal(httpErrorMessage(response(404), { message: '' }), 'Data yang diminta tidak ditemukan.');
        assert.equal(httpErrorMessage(response(422), { message: 42 }), 'Permintaan tidak bisa diproses. Coba lagi sebentar lagi.');
    });

    it('turns a bare proxy page (502/504 HTML, no JSON) into a server problem', () => {
        assert.equal(httpErrorMessage(response(502), null), 'Server sedang bermasalah. Coba lagi sebentar lagi.');
        assert.equal(httpErrorMessage(response(504), null), 'Server sedang bermasalah. Coba lagi sebentar lagi.');
    });
});

describe('responseOutcome', () => {
    it('sends an ended campus session on a signed-in page to the login page with the server\'s words', () => {
        assert.deepEqual(
            responseOutcome(response(401), { message: 'Sesi kampus berakhir karena tidak aktif.' }, { authMode: 'required' }),
            { action: 'login', message: 'Sesi kampus berakhir karena tidak aktif.', tone: 'error' },
        );
        assert.deepEqual(
            responseOutcome(response(401), null, { authMode: 'required' }),
            { action: 'login', message: 'Sesi kampus sudah berakhir.', tone: 'error' },
        );
    });

    it('sends an expired app session (419) on a signed-in page to the login page without the CSRF wording', () => {
        assert.deepEqual(
            responseOutcome(response(419), { message: 'CSRF token mismatch.' }, { authMode: 'required' }),
            { action: 'login', message: 'Sesi aplikasi sudah berakhir. Silakan masuk lagi.', tone: 'warning' },
        );
    });

    it('lets a guest page handle 401 and 419 itself instead of looping back to the login page', () => {
        for (const status of [401, 419]) {
            const outcome = responseOutcome(response(status), { message: 'CSRF token mismatch.' }, { authMode: 'guest' });

            assert.equal(outcome.action, 'fail', String(status));
            assert.equal(outcome.error.status, status);
        }

        assert.equal(responseOutcome(response(419), null, { authMode: 'guest' }).error.message, 'Halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    });

    it('leaves the session to callers that opt out of the redirect', () => {
        const outcome = responseOutcome(response(401), { message: 'Unauthenticated.' }, { authMode: 'required', redirectOnAuth: false });

        assert.equal(outcome.action, 'fail');
        assert.equal(outcome.error.status, 401);
        assert.equal(outcome.error.message, 'Sesi kampus sudah berakhir. Silakan masuk lagi.');
    });

    it('redirects when the caller does not say otherwise', () => {
        assert.equal(responseOutcome(response(401), null, { authMode: 'required', redirectOnAuth: undefined }).action, 'login');
        assert.equal(responseOutcome(response(401), null, { authMode: 'required', redirectOnAuth: null }).action, 'login');
    });

    it('fails an answer that claimed JSON but could not be read', () => {
        const outcome = responseOutcome(response(200), null, { unreadable: true, authMode: 'required' });

        assert.equal(outcome.action, 'fail');
        assert.equal(outcome.error.status, 200);
        assert.equal(outcome.error.message, 'Server mengirim data yang tidak bisa dibaca. Coba lagi sebentar lagi.');
    });

    it('fails other errors with their status and accepts a good answer', () => {
        assert.equal(responseOutcome(response(500), null, { authMode: 'required' }).error.status, 500);
        assert.equal(responseOutcome(response(403), null, { authMode: 'required' }).action, 'fail');
        assert.deepEqual(responseOutcome(response(200), { data: [] }, { authMode: 'required' }), { action: 'accept' });
        assert.deepEqual(responseOutcome(response(204), null), { action: 'accept' });
    });
});

describe('responseError', () => {
    it('keeps the payload for the page and puts the shown words in its message', () => {
        const payload = { message: 'Too Many Attempts.', errors: { kode: ['Salah.'] } };
        const error = responseError(response(429, { 'Retry-After': '30' }), payload);

        assert.equal(error.message, 'Terlalu sering mencoba. Coba lagi dalam 30 detik.');
        assert.deepEqual(error.payload, { message: 'Terlalu sering mencoba. Coba lagi dalam 30 detik.', errors: { kode: ['Salah.'] } });
        assert.equal(payload.message, 'Too Many Attempts.', 'The decoded payload itself is not changed.');
    });

    it('keeps a non-object payload as it is', () => {
        assert.equal(responseError(response(500), null).payload, null);
        assert.deepEqual(responseError(response(500), ['x']).payload, ['x']);
    });
});

describe('requestTimeoutMs', () => {
    it('waits longest for uploads and lets the caller decide', () => {
        assert.equal(requestTimeoutMs('GET'), 30000);
        assert.equal(requestTimeoutMs('POST', {}), 60000);
        assert.equal(requestTimeoutMs('POST', { formData: new FormData() }), 180000);
        assert.equal(requestTimeoutMs('GET', { timeout: 15000 }), 15000);
        assert.equal(requestTimeoutMs('POST', { timeout: 0, formData: new FormData() }), 0);
    });
});

describe('describeError', () => {
    it('explains offline and slow portals before any message', () => {
        assert.equal(describeError(createOfflineError('x')), 'Kamu sedang offline. Sambungkan internet lalu coba lagi.');
        assert.equal(describeError(createTimeoutError('x')), 'Portal kampus lambat merespons. Coba lagi sebentar lagi.');
    });

    it('prefers the payload message, then the error message, then the fallback', () => {
        const error = new Error('Pesan error');

        assert.equal(describeError(error), 'Pesan error');
        error.payload = { message: 'Pesan server' };
        assert.equal(describeError(error), 'Pesan server');
        assert.equal(describeError(null), 'Terjadi kesalahan saat memuat data.');
        assert.equal(describeError({}, 'Gagal memuat KRS.'), 'Gagal memuat KRS.');
    });
});

describe('network errors', () => {
    it('treats a failed fetch and an abort as network errors only', () => {
        assert.equal(isLikelyNetworkError(new TypeError('Failed to fetch')), true);
        assert.equal(isLikelyNetworkError(new DOMException('aborted', 'AbortError')), true);
        assert.equal(isLikelyNetworkError(new Error('500')), false);
        assert.equal(isLikelyNetworkError(null), false);
    });

    it('marks offline and timeout errors with status 0', () => {
        assert.deepEqual({ ...createOfflineError('a') }, { isOffline: true, status: 0 });
        assert.deepEqual({ ...createTimeoutError('b') }, { isTimeout: true, status: 0 });
        assert.equal(createTimeoutError('b').message, 'b');
    });
});
