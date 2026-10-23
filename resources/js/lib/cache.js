/**
 * The device copies of API answers (localStorage): which answers are kept,
 * for how long, under which key, and whose they are. Pure (the storage
 * itself stays in app.js), so tests/js runs it under Node.
 */

/**
 * The GET answers kept on the device. `freshFor`: used as is without asking
 * the server; `retainFor`: shown offline at most this long. Anything not
 * listed (the session check, downloads, notebooks, archives) is never kept.
 *
 * @type {ReadonlyArray<{ pattern: RegExp, freshFor: number, retainFor: number, label: string }>}
 */
export const CACHE_POLICIES = Object.freeze([
    { pattern: /^\/api\/dashboard$/, freshFor: 30 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'ringkasan dashboard' },
    { pattern: /^\/api\/attendance\/today$/, freshFor: 15 * 60 * 1000, retainFor: 48 * 60 * 60 * 1000, label: 'shortcut hari ini' },
    { pattern: /^\/api\/assignment\/pending$/, freshFor: 20 * 60 * 1000, retainFor: 48 * 60 * 60 * 1000, label: 'tugas belum dikumpulkan' },
    { pattern: /^\/api\/profile$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'profil mahasiswa' },
    { pattern: /^\/api\/class(?:\?.*)?$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'daftar kelas' },
    { pattern: /^\/api\/lms\/class$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'kelas LMS' },
    { pattern: /^\/api\/schedule\/class$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'jadwal kelas' },
    { pattern: /^\/api\/class\/[^/?]+(?:\?.*)?$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'detail kelas' },
    { pattern: /^\/api\/meeting\/[^/?]+(?:\?.*)?$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'detail meeting' },
    { pattern: /^\/api\/exam$/, freshFor: 2 * 60 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'daftar ujian' },
    { pattern: /^\/api\/exam\/[^/?]+$/, freshFor: 2 * 60 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'detail ujian' },
    { pattern: /^\/api\/academic\/krs$/, freshFor: 15 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'KRS semester ini' },
    { pattern: /^\/api\/academic\/krs\/\d+$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'detail KRS' },
    { pattern: /^\/api\/academic\/advising$/, freshFor: 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'riwayat perwalian' },
    { pattern: /^\/api\/academic\/grades$/, freshFor: 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'nilai semester' },
    { pattern: /^\/api\/academic\/transcript$/, freshFor: 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'transkrip' },
    { pattern: /^\/api\/announcement(?:\?.*)?$/, freshFor: 30 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'pengumuman' },
    { pattern: /^\/api\/announcement\/[^/?]+$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'detail pengumuman' },
    // Payment history is sensitive: kept on the device for at most 7 days.
    { pattern: /^\/api\/finance\/summary$/, freshFor: 30 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'ringkasan keuangan' },
    { pattern: /^\/api\/finance\/payments$/, freshFor: 30 * 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'riwayat pembayaran' },
    { pattern: /^\/api\/skkm$/, freshFor: 30 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'poin SKKM' },
    { pattern: /^\/api\/thesis$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'syarat & status skripsi' },
    { pattern: /^\/api\/thesis\/readiness$/, freshFor: 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'kesiapan skripsi' },
    { pattern: /^\/api\/library$/, freshFor: 24 * 60 * 60 * 1000, retainFor: 30 * 24 * 60 * 60 * 1000, label: 'perpustakaan digital' },
    // Only the unfiltered first page is kept; searches and filters request with { cache: false }.
    { pattern: /^\/api\/library\/theses$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'repositori skripsi' },
    { pattern: /^\/api\/action-items$/, freshFor: 5 * 60 * 1000, retainFor: 2 * 24 * 60 * 60 * 1000, label: 'pusat tindakan' },
    { pattern: /^\/api\/portal\/context$/, freshFor: 6 * 60 * 60 * 1000, retainFor: 14 * 24 * 60 * 60 * 1000, label: 'info periode & panduan' },
    { pattern: /^\/api\/notifications$/, freshFor: 60 * 1000, retainFor: 7 * 24 * 60 * 60 * 1000, label: 'notifikasi' },
]);

/**
 * The policy that keeps a copy of this request, or null: only same-origin
 * GETs that did not ask for `{ cache: false }`.
 *
 * @param {ReadonlyArray<{ pattern: RegExp }>} policies
 * @param {URL} requestUrl
 * @param {string} method
 * @param {{ cache?: boolean }} options
 * @param {string} origin The app's origin.
 * @returns {object|null}
 */
export function resolveCachePolicy(policies, requestUrl, method, options, origin) {
    if (options.cache === false || method !== 'GET' || requestUrl.origin !== origin) {
        return null;
    }

    const key = `${requestUrl.pathname}${requestUrl.search}`;

    return policies.find((policy) => policy.pattern.test(key) || policy.pattern.test(requestUrl.pathname)) || null;
}

/**
 * The URL a request goes to; `{ fresh: true }` asks the server to read the
 * portal again instead of its snapshot.
 *
 * @param {string|URL} url
 * @param {{ fresh?: boolean }} options
 * @param {string} origin
 * @returns {URL}
 */
export function buildRequestUrl(url, options, origin) {
    const requestUrl = new URL(url, origin);

    if (options.fresh === true) {
        requestUrl.searchParams.set('fresh', '1');
    }

    return requestUrl;
}

/**
 * The URL a copy is kept under: a `fresh` read replaces the same copy.
 *
 * @param {URL} requestUrl
 * @returns {URL}
 */
export function normalizeCacheUrl(requestUrl) {
    const normalizedUrl = new URL(requestUrl.toString());

    normalizedUrl.searchParams.delete('fresh');

    return normalizedUrl;
}

/**
 * @param {string} namespace
 * @param {URL} requestUrl
 * @returns {string}
 */
export function buildCacheKey(namespace, requestUrl) {
    return `${namespace}:response:${requestUrl.pathname}${requestUrl.search}`;
}

/**
 * The stored form of a copy.
 *
 * @param {unknown} payload
 * @param {{ freshFor: number, retainFor: number, label: string }} policy
 * @param {number} now Epoch milliseconds.
 * @returns {{ payload: unknown, cached_at: string, fresh_until: string, expires_at: string, label: string }}
 */
export function createCacheRecord(payload, policy, now = Date.now()) {
    return {
        payload,
        cached_at: new Date(now).toISOString(),
        fresh_until: new Date(now + policy.freshFor).toISOString(),
        expires_at: new Date(now + policy.retainFor).toISOString(),
        label: policy.label,
    };
}

/**
 * A stored copy with `isStale`, or null when it has expired, carries no
 * expiry or is not a record at all; the caller then drops it.
 *
 * @param {string} rawRecord
 * @param {number} now Epoch milliseconds.
 * @returns {object|null}
 */
export function readCacheRecord(rawRecord, now = Date.now()) {
    try {
        const record = JSON.parse(rawRecord);
        const expiresAt = new Date(record.expires_at).getTime();

        if (Number.isNaN(expiresAt) || now > expiresAt) {
            return null;
        }

        const freshUntil = new Date(record.fresh_until).getTime();

        return {
            ...record,
            isStale: Number.isNaN(freshUntil) ? true : now > freshUntil,
        };
    } catch (error) {
        return null;
    }
}

/**
 * A copy of the payload whose `meta` says it came from the device, so a
 * page can say how old it is. Not an object: the payload as stored.
 *
 * @param {{ payload?: unknown, cached_at?: string, isStale?: boolean }|null} record
 * @param {'prefill'|'offline'|'fallback'} mode
 * @returns {unknown}
 */
export function decorateCachedPayload(record, mode) {
    if (!record?.payload || typeof record.payload !== 'object' || Array.isArray(record.payload)) {
        return record?.payload ?? null;
    }

    const payload = JSON.parse(JSON.stringify(record.payload));
    const currentMeta = payload.meta && typeof payload.meta === 'object' ? payload.meta : {};

    payload.meta = {
        ...currentMeta,
        offline_cache: true,
        cache_mode: mode,
        cached_at: record.cached_at,
        cache_is_stale: record.isStale,
        data_origin: 'device_cache',
    };

    return payload;
}

/**
 * @param {string} label
 * @param {boolean} isStale
 * @returns {string}
 */
export function buildOfflineCacheMessage(label, isStale) {
    return `Menampilkan ${label} terakhir${isStale ? ' yang mungkin sudah lama' : ''}.`;
}

/**
 * What a signed-in page does with the device copies when it loads. Copies
 * belong to one student: any other stored owner, or none at all (copies
 * kept before owners were recorded), is cleared. A device a login just
 * cleared (`clearedOwner`) is claimed without clearing, so the page keeps
 * the copy of itself the login stored.
 *
 * @param {string} owner The signed-in student's digest ('' when unknown).
 * @param {string|null} storedOwner
 * @param {string} clearedOwner The mark a login leaves on a cleared device.
 * @returns {{ clear: boolean, store: boolean }}
 */
export function deviceCacheClaim(owner, storedOwner, clearedOwner) {
    if (owner === '' || storedOwner === owner) {
        return { clear: false, store: false };
    }

    return { clear: storedOwner !== clearedOwner, store: true };
}
