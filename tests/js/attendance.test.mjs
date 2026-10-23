import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    extractAttendanceCode,
    meetingAttendancePortalState,
    meetingAttendanceView,
    quickQrTargetFromSummary,
    todayAttendanceNote,
} from '../../resources/js/lib/attendance.js';

describe('extractAttendanceCode', () => {
    it('reads a bare code in lower case, cut to 8 characters', () => {
        assert.equal(extractAttendanceCode('1A2B3C4D'), '1a2b3c4d');
        assert.equal(extractAttendanceCode('  deadbeefcafe  '), 'deadbeef');
    });

    it('prefers the code after a hint word over an earlier number', () => {
        assert.equal(extractAttendanceCode('NIM 20230001 kode: abcdef12'), 'abcdef12');
        assert.equal(extractAttendanceCode('https://portal.invalid/absen?nim=20230001&code=12345678'), '12345678');
    });

    it('prefers a code with hex letters over a digits-only run', () => {
        assert.equal(extractAttendanceCode('20230001 9f8e7d6c'), '9f8e7d6c');
    });

    it('takes the first candidate when nothing else decides', () => {
        assert.equal(extractAttendanceCode('11111111 22222222'), '11111111');
    });

    it('gives null without a code', () => {
        for (const value of [null, undefined, '', '   ', 'abc', 'kode: zzzzzzzz', '1234567']) {
            assert.equal(extractAttendanceCode(value), null, String(value));
        }
    });
});

describe('quickQrTargetFromSummary', () => {
    const today = '2026-10-20';
    const summary = (classes, date = today) => ({ date, today_classes: classes });

    it('opens the scanner on the first class whose attendance can be filled', () => {
        const target = quickQrTargetFromSummary(summary([
            { attendance: { actionable: false, meeting_url: '/meeting/1?courses_id=1' } },
            { attendance: { actionable: true } },
            { attendance: { actionable: true, meeting_url: '/meeting/3?courses_id=9' } },
            { attendance: { actionable: true, meeting_url: '/meeting/4?courses_id=9' } },
        ]), today);

        assert.equal(target, '/meeting/3?courses_id=9&qr=1');
    });

    it('says today has nothing to scan', () => {
        assert.equal(quickQrTargetFromSummary(summary([]), today), null);
        assert.equal(quickQrTargetFromSummary(summary([{ attendance: null }, {}]), today), null);
    });

    it('waits while a class of today is still having its attendance checked', () => {
        assert.equal(quickQrTargetFromSummary(summary([
            { attendance: { actionable: false, state: 'checking' } },
            { attendance: { actionable: false, state: 'done' } },
        ]), today), undefined);
    });

    it('a class that can be scanned wins over one still being checked', () => {
        assert.equal(quickQrTargetFromSummary(summary([
            { attendance: { actionable: false, state: 'checking' } },
            { attendance: { actionable: true, meeting_url: '/meeting/7?courses_id=3' } },
        ]), today), '/meeting/7?courses_id=3&qr=1');
    });

    it('does not trust a schedule of another day or a missing one', () => {
        assert.equal(quickQrTargetFromSummary(summary([{ attendance: { actionable: true, meeting_url: '/meeting/3?courses_id=9' } }], '2026-10-19'), today), undefined);
        assert.equal(quickQrTargetFromSummary(undefined, today), undefined);
        assert.equal(quickQrTargetFromSummary({ date: today }, today), undefined);
    });

    it('uses a summary without a date as today\'s', () => {
        assert.equal(quickQrTargetFromSummary({ today_classes: [{ attendance: { actionable: true, meeting_url: '/meeting/5?courses_id=2' } }] }, today), '/meeting/5?courses_id=2&qr=1');
    });
});

describe('meetingAttendancePortalState', () => {
    it('keeps the state the server stored', () => {
        assert.equal(meetingAttendancePortalState({ status: 'Waktu Absensi Tidak Tersedia', time: '', state: 'unavailable' }), 'unavailable');
        assert.equal(meetingAttendancePortalState({ status: 'Hadir', time: '19:36:34', state: 'present' }), 'present');
    });

    it('reads a payload without a state like the server does', () => {
        assert.equal(meetingAttendancePortalState({ status: 'Waktu Absensi Tidak Tersedia', time: '' }), 'unavailable');
        assert.equal(meetingAttendancePortalState({ status: 'Hadir', time: '19:36:34' }), 'present');
        assert.equal(meetingAttendancePortalState({ status: 'Hadir (Terlambat)', time: '08:17:00' }), 'late');
        assert.equal(meetingAttendancePortalState({ status: 'Tidak Hadir' }), 'absent');
        assert.equal(meetingAttendancePortalState({ status: 'Alpa' }), 'absent');
        assert.equal(meetingAttendancePortalState({ status: 'Belum dibuka' }), 'unavailable');
        assert.equal(meetingAttendancePortalState({ status: 'Waktu absen sudah berakhir' }), 'unavailable');
        assert.equal(meetingAttendancePortalState({ status: 'Silakan scan QR untuk absensi' }), 'open');
        assert.equal(meetingAttendancePortalState({ status: 'Belum hadir' }), 'open');
        assert.equal(meetingAttendancePortalState({ status: 'Absen' }), 'unknown');
        assert.equal(meetingAttendancePortalState(null), 'unknown');
    });
});

describe('meetingAttendanceView', () => {
    const unavailable = { status: 'Waktu Absensi Tidak Tersedia', time: '', state: 'unavailable' };

    it('reads a past meeting without presence as not attended and a future one as not open yet', () => {
        assert.equal(meetingAttendanceView(unavailable, '2026-10-14', '2026-10-23'), 'past');
        assert.equal(meetingAttendanceView(unavailable, '2026-10-28', '2026-10-23'), 'upcoming');
    });

    it('tells a window not open yet from a closed one on the meeting day', () => {
        assert.equal(meetingAttendanceView(unavailable, '2026-10-23', '2026-10-23'), 'not_open');
        assert.equal(meetingAttendanceView({ status: 'Waktu absen sudah berakhir', state: 'unavailable' }, '2026-10-23', '2026-10-23'), 'closed');
        assert.equal(meetingAttendanceView({ status: 'Tidak Hadir', state: 'absent' }, '2026-10-23', '2026-10-23'), 'closed');
        assert.equal(meetingAttendanceView({ status: 'Silakan scan QR', state: 'open' }, '2026-10-23', '2026-10-23'), 'open');
        assert.equal(meetingAttendanceView({}, null, '2026-10-23'), 'open');
    });

    it('shows recorded attendance whatever the date', () => {
        assert.equal(meetingAttendanceView({ status: 'Hadir', time: '19:36:34', state: 'present' }, '2026-10-05', '2026-10-23'), 'recorded');
        assert.equal(meetingAttendanceView({ status: 'Hadir', time: '19:20:42' }, '2026-10-22', '2026-10-23'), 'recorded');
    });
});

describe('todayAttendanceNote', () => {
    it('says not attended once the window closed and not open yet before', () => {
        assert.match(todayAttendanceNote({ status: 'unavailable', window: 'closed' }), /^Tidak hadir/);
        assert.match(todayAttendanceNote({ status: 'unavailable', window: 'not_open' }), /belum dibuka/);
    });

    it('says nothing for any other status', () => {
        for (const status of [null, undefined, {}, { status: 'present' }, { status: 'absent' }, { status: 'open' }]) {
            assert.equal(todayAttendanceNote(status), null, JSON.stringify(status));
        }
    });
});
