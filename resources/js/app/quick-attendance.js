import * as attendanceRules from '../lib/attendance.js';

/**
 * Attendance from anywhere: the quick QR button in the bottom nav, the
 * `?absen=1` shortcut, and filling a code into the page's input (rules in
 * lib/attendance.js).
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class QuickAttendanceFeature {
    bindQuickQrAction() {
        document.querySelectorAll('[data-qr-quick]').forEach((button) => {
            button.addEventListener('click', async () => {
                const input = document.querySelector('[data-field="kode"]') || document.querySelector('#attendance-code');

                if (input) {
                    await this.openQrScanner(input);

                    return;
                }

                // No attendance form on this page: a page can name the meeting
                // where the scanner should open instead (see the dashboard).
                if (this.quickQrTarget) {
                    window.location.href = this.quickQrTarget;

                    return;
                }

                // Pages that say nothing about attendance fall back to today's
                // schedule already stored on this device (no portal request).
                if (this.quickQrEmptyMessage === null) {
                    const cachedTarget = this.quickQrTargetFromCache();

                    if (cachedTarget === undefined) {
                        window.location.href = '/dashboard?absen=1';

                        return;
                    }

                    if (cachedTarget) {
                        window.location.href = cachedTarget;

                        return;
                    }
                }

                this.showNotice(this.quickQrEmptyMessage || 'Tidak ada absensi yang bisa diisi sekarang. Buka pertemuan yang sedang berlangsung dari halaman Kuliah.', 'warning');
            });
        });
    }

    /**
     * Where the quick QR button should take the student when the current page
     * has no attendance form. `null` keeps the explanatory message.
     */
    setQuickQrTarget(url, emptyMessage = null) {
        this.quickQrTarget = url || null;
        this.quickQrEmptyMessage = emptyMessage;

        if (this.quickQrTarget) {
            this.resolvePendingQuickAttendance();
        }
    }

    /**
     * The meeting to scan for, read from today's dashboard snapshot cached on
     * this device. `null` when today has nothing to scan, `undefined` when the
     * device has no usable copy of today's schedule.
     */
    quickQrTargetFromCache() {
        const summary = this.getCachedResponse('/api/dashboard')?.payload?.data?.classes_summary;

        return attendanceRules.quickQrTargetFromSummary(summary, new Date().toLocaleDateString('sv-SE'));
    }

    /**
     * `?absen=1` (the "Absen sekarang" PWA shortcut, or the quick QR button
     * on a page without today's schedule) opens the scanner as soon as the
     * page knows which class is running.
     */
    consumeQuickAttendanceRequest() {
        const url = new URL(window.location.href);

        if (url.searchParams.get('absen') !== '1') {
            return;
        }

        url.searchParams.delete('absen');
        window.history.replaceState({}, '', url.toString());
        this.pendingQuickAttendance = true;
        this.pendingQuickAttendanceTimer = window.setTimeout(() => {
            this.resolvePendingQuickAttendance({ final: true });
        }, 30000);

        const input = document.querySelector('[data-field="kode"]') || document.querySelector('#attendance-code');

        if (input) {
            this.resolvePendingQuickAttendance();
        }
    }

    resolvePendingQuickAttendance({ final = false } = {}) {
        if (!this.pendingQuickAttendance) {
            return;
        }

        const input = document.querySelector('[data-field="kode"]') || document.querySelector('#attendance-code');

        if (!input && !this.quickQrTarget && !final) {
            return;
        }

        this.pendingQuickAttendance = false;
        window.clearTimeout(this.pendingQuickAttendanceTimer);

        if (input) {
            void this.openQrScanner(input);

            return;
        }

        if (this.quickQrTarget) {
            window.location.href = this.quickQrTarget;

            return;
        }

        this.showNotice(this.quickQrEmptyMessage || 'Tidak ada absensi yang bisa diisi sekarang.', 'warning');
    }

    extractAttendanceCode(value) {
        return attendanceRules.extractAttendanceCode(value);
    }

    meetingAttendanceView(attendanceInfo, meetingDay, todayIso) {
        return attendanceRules.meetingAttendanceView(attendanceInfo, meetingDay, todayIso);
    }

    todayAttendanceNote(status) {
        return attendanceRules.todayAttendanceNote(status);
    }

    fillAttendanceCode(input, rawValue) {
        const code = this.extractAttendanceCode(rawValue);

        if (!code) {
            this.showNotice('QR terdeteksi, tetapi tidak menemukan kode absensi yang valid.', 'warning');

            return false;
        }

        input.value = code;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        this.showNotice(`Kode absensi ${code} terisi otomatis.`, 'success');

        return true;
    }
}
