import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    daysUntil,
    formatDate,
    formatIndonesianDate,
    formatRelativeDays,
    formatTimeAgo,
    formatTimeOnly,
    portalDataTime,
    toDate,
} from '../../resources/js/lib/dates.js';

// The campus is in WIB; the calendar-day rules must hold there.
process.env.TZ = 'Asia/Jakarta';

describe('toDate', () => {
    it('reads a bare YYYY-MM-DD as the local calendar day, not UTC midnight', () => {
        const date = toDate('2026-10-20');

        assert.deepEqual([date.getFullYear(), date.getMonth(), date.getDate(), date.getHours()], [2026, 9, 20, 0]);
    });

    it('reads the portal\'s "YYYY-MM-DD HH:MM" as local time', () => {
        const date = toDate('2026-10-20 08:30');

        assert.deepEqual([date.getDate(), date.getHours(), date.getMinutes()], [20, 8, 30]);
    });

    it('accepts dates, timestamps and ISO strings and rejects the rest', () => {
        const now = new Date(2026, 9, 20, 9);

        assert.equal(toDate(now), now);
        assert.equal(toDate(now.getTime()).getTime(), now.getTime());
        assert.equal(toDate('2026-10-20T01:00:00Z').getHours(), 8);

        for (const value of [null, undefined, '', 'besok', new Date('x'), Number.NaN]) {
            assert.equal(toDate(value), null, String(value));
        }
    });
});

describe('formatIndonesianDate', () => {
    it('writes the date in Indonesian', () => {
        assert.equal(formatIndonesianDate('2026-10-20'), '20 Oktober 2026');
        assert.equal(formatIndonesianDate('2026-10-20', { weekday: true }), 'Selasa, 20 Oktober 2026');
        assert.equal(formatIndonesianDate('2026-10-20', { month: 'short', year: false }), '20 Okt');
    });

    it('adds the time when asked', () => {
        assert.match(formatIndonesianDate('2026-10-20 08:30', { time: true }), /^20 Oktober 2026(,| pukul) 08[.:]30$/);
    });

    it('gives the fallback for a missing date, never "Invalid Date"', () => {
        assert.equal(formatIndonesianDate(null), '–');
        assert.equal(formatIndonesianDate('bukan tanggal', { fallback: '-' }), '-');
    });
});

describe('daysUntil and formatRelativeDays', () => {
    const lateEvening = new Date(2026, 9, 20, 23, 30);

    it('counts calendar days, not 24-hour blocks', () => {
        assert.equal(daysUntil('2026-10-21', lateEvening), 1);
        assert.equal(daysUntil(new Date(2026, 9, 21, 0, 5), lateEvening), 1);
        assert.equal(daysUntil('2026-10-20', lateEvening), 0);
        assert.equal(daysUntil('2026-10-18', lateEvening), -2);
        assert.equal(daysUntil('x', lateEvening), null);
    });

    it('counts across the end of a month and a year', () => {
        assert.equal(daysUntil('2026-11-01', new Date(2026, 9, 31, 22)), 1);
        assert.equal(daysUntil('2027-01-01', new Date(2026, 11, 31, 23, 59)), 1);
    });

    it('says the day in words', () => {
        assert.equal(formatRelativeDays('2026-10-20', { now: lateEvening }), 'hari ini');
        assert.equal(formatRelativeDays('2026-10-21', { now: lateEvening }), 'besok');
        assert.equal(formatRelativeDays('2026-10-19', { now: lateEvening }), 'kemarin');
        assert.equal(formatRelativeDays('2026-10-23', { now: lateEvening }), '3 hari lagi');
        assert.equal(formatRelativeDays('2026-10-18', { now: lateEvening }), 'lewat 2 hari');
    });

    it('keeps counting when natural words are off and falls back for no date', () => {
        assert.equal(formatRelativeDays('2026-10-21', { now: lateEvening, natural: false }), '1 hari lagi');
        assert.equal(formatRelativeDays('2026-10-19', { now: lateEvening, natural: false }), 'lewat 1 hari');
        assert.equal(formatRelativeDays(null, { now: lateEvening, fallback: 'Belum dijadwalkan' }), 'Belum dijadwalkan');
    });
});

describe('formatTimeAgo', () => {
    const now = new Date(2026, 9, 20, 10, 0);

    it('reads minutes, hours and days ago', () => {
        assert.equal(formatTimeAgo(new Date(2026, 9, 20, 9, 59, 30), { now }), 'baru saja');
        assert.equal(formatTimeAgo(new Date(2026, 9, 20, 9, 55), { now }), '5 menit lalu');
        assert.equal(formatTimeAgo(new Date(2026, 9, 20, 7, 50), { now }), '2 jam lalu');
        assert.equal(formatTimeAgo(new Date(2026, 9, 19, 8, 0), { now }), 'kemarin');
        assert.equal(formatTimeAgo(new Date(2026, 9, 17, 12, 0), { now }), '3 hari lalu');
    });

    it('says "baru saja" for a time slightly ahead of the device clock', () => {
        assert.equal(formatTimeAgo(new Date(2026, 9, 20, 10, 2), { now }), 'baru saja');
    });

    it('gives nothing for a missing date', () => {
        assert.equal(formatTimeAgo(null, { now }), '');
    });
});

describe('formatDate and formatTimeOnly', () => {
    it('writes date and time in Indonesian', () => {
        assert.match(formatDate('2026-10-20T01:30:00Z'), /^20 Okt 2026(,| pukul) 08[.:]30$/);
        assert.match(formatTimeOnly('2026-10-20T01:30:00Z'), /^08[.:]30$/);
    });

    it('keeps an unreadable value and marks a missing one', () => {
        assert.equal(formatDate(''), '-');
        assert.equal(formatDate('Senin pagi'), 'Senin pagi');
        assert.equal(formatTimeOnly('nanti'), '-');
    });
});

describe('portalDataTime', () => {
    it('prefers the sync time, then the first section read from the portal', () => {
        assert.equal(portalDataTime({ meta: { synced_at: '2026-10-20T01:00:00Z' } }).toISOString(), '2026-10-20T01:00:00.000Z');
        assert.equal(
            portalDataTime({ meta: { synced_at: 'kemarin' }, data: { total: 3, krs: { extracted_at: '2026-10-19T02:00:00Z' } } }).toISOString(),
            '2026-10-19T02:00:00.000Z',
        );
    });

    it('gives null when the payload says nothing usable', () => {
        assert.equal(portalDataTime(null), null);
        assert.equal(portalDataTime({ data: 'x' }), null);
        assert.equal(portalDataTime({ data: { krs: { extracted_at: 'tadi' } } }), null);
    });
});
