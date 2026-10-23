import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { isOfflineReconnectMode, offlineReconnectMessage } from '../../resources/js/lib/offline.js';

describe('offlineReconnectMessage', () => {
    it('tells a student without a stored password to log in again', () => {
        assert.match(offlineReconnectMessage('notify'), /tidak tersambung otomatis/);
        assert.match(offlineReconnectMessage('portal_back'), /sudah bisa diakses lagi/);
        assert.match(offlineReconnectMessage('manual'), /Keluar lalu masuk lagi/);
    });

    it('promises the automatic reconnect only for the automatic mode or no mode', () => {
        const automatic = 'MyCIC menyambung lagi otomatis begitu portal kampus bisa diakses.';

        assert.equal(offlineReconnectMessage('automatic'), automatic);
        assert.equal(offlineReconnectMessage(''), automatic);
        assert.equal(offlineReconnectMessage(undefined), automatic);
    });
});

describe('isOfflineReconnectMode', () => {
    it('accepts only the modes the server sends', () => {
        for (const mode of ['automatic', 'notify', 'portal_back', 'manual']) {
            assert.equal(isOfflineReconnectMode(mode), true, mode);
        }

        for (const mode of ['', null, undefined, 'toString', 'online']) {
            assert.equal(isOfflineReconnectMode(mode), false, String(mode));
        }
    });
});
