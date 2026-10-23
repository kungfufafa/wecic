import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    CACHE_POLICIES,
    buildCacheKey,
    buildOfflineCacheMessage,
    buildRequestUrl,
    createCacheRecord,
    decorateCachedPayload,
    deviceCacheClaim,
    normalizeCacheUrl,
    readCacheRecord,
    resolveCachePolicy,
} from '../../resources/js/lib/cache.js';

const app = 'https://app.invalid';
const day = 24 * 60 * 60 * 1000;
const policyFor = (path, method = 'GET', options = {}) => resolveCachePolicy(CACHE_POLICIES, new URL(path, app), method, options, app);

describe('which answers are kept on the device', () => {
    it('keeps the pages that work offline, with their query', () => {
        assert.equal(policyFor('/api/dashboard').label, 'ringkasan dashboard');
        assert.equal(policyFor('/api/class/123?dosen_id=4&course_id=5').label, 'detail kelas');
        assert.equal(policyFor('/api/meeting/7?courses_id=9').label, 'detail meeting');
        assert.equal(policyFor('/api/academic/krs/20261').label, 'detail KRS');
        assert.equal(policyFor('/api/announcement?page=2').label, 'pengumuman');
    });

    it('never keeps the session check, downloads, uploads or anything unlisted', () => {
        for (const path of ['/api/auth/session', '/api/support/download?path=files%2Fa.pdf', '/api/operations/abc', '/api/notebook', '/api/archive', '/api/academic/krs/abc', '/api/exam/1/2']) {
            assert.equal(policyFor(path), null, path);
        }
    });

    it('never keeps a write, an opted-out read or another site\'s answer', () => {
        assert.equal(policyFor('/api/dashboard', 'POST'), null);
        assert.equal(policyFor('/api/library/theses', 'GET', { cache: false }), null);
        assert.equal(resolveCachePolicy(CACHE_POLICIES, new URL('https://elsewhere.invalid/api/dashboard'), 'GET', {}, app), null);
    });

    it('keeps payment history for at most 7 days', () => {
        for (const path of ['/api/finance/summary', '/api/finance/payments']) {
            assert.ok(policyFor(path).retainFor <= 7 * day, path);
        }
    });

    it('keeps no copy longer than 30 days or fresh longer than it is kept', () => {
        for (const policy of CACHE_POLICIES) {
            assert.ok(policy.retainFor <= 30 * day, policy.label);
            assert.ok(policy.freshFor > 0 && policy.freshFor <= policy.retainFor, policy.label);
            assert.match(policy.pattern.source, /^\^\\\/api\\\//, `${policy.label} must be an /api/ path anchored at the start`);
        }
    });

    it('cannot be changed by a page at runtime', () => {
        assert.ok(Object.isFrozen(CACHE_POLICIES));
    });
});

describe('cache keys', () => {
    it('asks for a fresh read without changing which copy it replaces', () => {
        const requestUrl = buildRequestUrl('/api/class/5?course_id=1', { fresh: true }, app);

        assert.equal(requestUrl.toString(), 'https://app.invalid/api/class/5?course_id=1&fresh=1');
        assert.equal(buildCacheKey('ns', normalizeCacheUrl(requestUrl)), 'ns:response:/api/class/5?course_id=1');
        assert.equal(buildRequestUrl('/api/dashboard', {}, app).search, '');
    });

    it('gives each query its own copy', () => {
        assert.notEqual(
            buildCacheKey('ns', new URL('/api/meeting/1?courses_id=1', app)),
            buildCacheKey('ns', new URL('/api/meeting/1?courses_id=2', app)),
        );
    });
});

describe('stored copies', () => {
    const policy = { freshFor: 60 * 1000, retainFor: 10 * 60 * 1000, label: 'contoh' };
    const savedAt = Date.UTC(2026, 9, 20, 1, 0);
    const stored = JSON.stringify(createCacheRecord({ data: { a: 1 } }, policy, savedAt));

    it('is fresh, then stale, then gone', () => {
        assert.equal(readCacheRecord(stored, savedAt + 30 * 1000).isStale, false);
        assert.equal(readCacheRecord(stored, savedAt + 5 * 60 * 1000).isStale, true);
        assert.equal(readCacheRecord(stored, savedAt + 10 * 60 * 1000 + 1), null);
    });

    it('keeps the payload, the time it was saved and the label', () => {
        const record = readCacheRecord(stored, savedAt);

        assert.deepEqual(record.payload, { data: { a: 1 } });
        assert.equal(record.cached_at, '2026-10-20T01:00:00.000Z');
        assert.equal(record.label, 'contoh');
    });

    it('treats a copy without an expiry, or anything else, as unusable', () => {
        for (const raw of ['{}', 'null', '42', '[]', '{not json', JSON.stringify({ payload: 1, expires_at: 'nanti' })]) {
            assert.equal(readCacheRecord(raw, savedAt), null, raw);
        }
    });

    it('treats a copy without a fresh-until time as stale', () => {
        assert.equal(readCacheRecord(JSON.stringify({ payload: 1, expires_at: '2026-10-21T00:00:00Z' }), savedAt).isStale, true);
    });
});

describe('decorateCachedPayload', () => {
    it('says in meta that the payload came from the device', () => {
        const record = { payload: { data: [1], meta: { synced_at: 'T0' } }, cached_at: 'T1', isStale: true };
        const payload = decorateCachedPayload(record, 'offline');

        assert.deepEqual(payload.meta, {
            synced_at: 'T0',
            offline_cache: true,
            cache_mode: 'offline',
            cached_at: 'T1',
            cache_is_stale: true,
            data_origin: 'device_cache',
        });
        assert.deepEqual(record.payload.meta, { synced_at: 'T0' }, 'The stored copy is not changed.');
    });

    it('returns other payloads as stored', () => {
        assert.deepEqual(decorateCachedPayload({ payload: [1, 2] }, 'prefill'), [1, 2]);
        assert.equal(decorateCachedPayload({ payload: 'x' }, 'prefill'), 'x');
        assert.equal(decorateCachedPayload(null, 'prefill'), null);
    });

    it('explains an old copy', () => {
        assert.equal(buildOfflineCacheMessage('KRS semester ini', false), 'Menampilkan KRS semester ini terakhir.');
        assert.equal(buildOfflineCacheMessage('KRS semester ini', true), 'Menampilkan KRS semester ini terakhir yang mungkin sudah lama.');
    });
});

describe('deviceCacheClaim', () => {
    const studentA = 'a'.repeat(32);
    const studentB = 'b'.repeat(32);
    const cleared = 'cleared';

    it('clears another student\'s copies before claiming the device', () => {
        assert.deepEqual(deviceCacheClaim(studentB, studentA, cleared), { clear: true, store: true });
    });

    it('clears copies of an unknown owner', () => {
        assert.deepEqual(deviceCacheClaim(studentA, null, cleared), { clear: true, store: true });
        assert.deepEqual(deviceCacheClaim(studentA, '', cleared), { clear: true, store: true });
    });

    it('claims a device a login just cleared without wiping the login\'s own copies', () => {
        assert.deepEqual(deviceCacheClaim(studentA, cleared, cleared), { clear: false, store: true });
    });

    it('leaves the same student\'s copies alone', () => {
        assert.deepEqual(deviceCacheClaim(studentA, studentA, cleared), { clear: false, store: false });
    });

    it('does nothing for a page that names no owner', () => {
        assert.deepEqual(deviceCacheClaim('', studentA, cleared), { clear: false, store: false });
    });
});
