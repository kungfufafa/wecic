{{--
    Pertemuan script, the attendance code: normalised, filled into the form
    shown now, sent after a scan, and the quick scan the Beranda asked for.
    Part of the DOMContentLoaded handler opened in meetings/show.blade.php:
    it shares that scope (app, meetingId, coursesId, endpoint and the
    constants in setup).
--}}
            // A scanned code is lowercased by the scanner; a typed one is sent the same way.
            const normalizeAttendanceCode = (value) => {
                const code = String(value || '').trim();

                return /^[0-9a-f]+$/i.test(code) ? code.toLowerCase() : code;
            };

            /**
             * The scanner keeps the input it was opened with; the page may have been
             * re-rendered since, so the code always goes into the form shown now.
             */
            const fillLiveAttendanceCode = (code) => {
                const input = document.getElementById('attendance-code');

                if (!input) {
                    app.showNotice('Form absensi sudah tidak ada di halaman ini. Status absensinya baru saja berubah.', 'warning');

                    return null;
                }

                input.value = normalizeAttendanceCode(code);

                return input;
            };

            const submitScannedCode = (code) => {
                if (!fillLiveAttendanceCode(code)) {
                    return;
                }

                app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                app.requestFormSubmit(document.getElementById('attendance-form'));
            };

            /**
             * `?qr=1` (the quick QR button) opens the scanner once, for the form on
             * screen. On a meeting dated another day it says why instead and opens
             * the "Tetap kirim kode" form, so a moved or make-up class can still
             * send its code; the portal decides.
             */
            const consumeQuickScanRequest = (attendanceState) => {
                const url = new URL(window.location.href);

                if (url.searchParams.get('qr') !== '1' || app.qrFromUrlHandled) {
                    return;
                }

                app.qrFromUrlHandled = true;
                url.searchParams.delete('qr');
                window.history.replaceState({}, '', url.toString());

                const input = document.getElementById('attendance-code');

                if (input && attendanceState === 'open') {
                    input.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    void app.openQrScanner(input, { onDetected: submitScannedCode });

                    return;
                }

                const notices = {
                    recorded: ['Kehadiranmu di pertemuan ini sudah tercatat.', 'success'],
                    past: ['Tanggal pertemuan ini sudah lewat. Kirim kode hanya bila kelasnya dipindah ke hari ini.', 'warning'],
                    upcoming: ['Pertemuan ini dijadwalkan hari lain. Kirim kode hanya bila kelasnya dimajukan ke hari ini.', 'warning'],
                };
                const [message, tone] = notices[attendanceState] || ['Absensi pertemuan ini tidak bisa diisi sekarang.', 'warning'];

                app.showNotice(message, tone);

                const override = document.querySelector('[data-attendance-override]');

                if (override && input) {
                    override.open = true;
                    input.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            };

