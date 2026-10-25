{{--
    Pertemuan script, the attendance card's slot and its form: typing or
    scanning the code, sending it, and queueing it offline. Part of render()
    opened in meetings/show/scripts/render.blade.php: it shares that scope
    (payload, detail and the meeting, attendance and assignment state
    derived there) and the handler's (app, the helpers and the action
    slots).
--}}
                renderSlot('attendance', attendanceSectionMarkup, { inferredMeetingNumber }, (slot) => {
                    const attendanceForm = slot.querySelector('#attendance-form');

                    if (!attendanceForm) {
                        // Attendance just stopped being open (recorded in another tab,
                        // or the fresh copy says so): a running scan has nothing to fill.
                        if (app.qrScannerState?.stream) {
                            void app.closeQrScanner();
                        }

                        return;
                    }

                    app.bindAttendanceQrTools(attendanceForm, slot.querySelector('#attendance-code'), {
                        onDetected: submitScannedCode,
                    });

                    attendanceForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Absen meeting butuh koneksi internet.', { queueable: true })) {
                            return;
                        }

                        const kode = normalizeAttendanceCode(document.getElementById('attendance-code')?.value);

                        if (!kode) {
                            app.showNotice('Isi kode absen terlebih dahulu.', 'warning');

                            return;
                        }

                        await app.guardFormSubmit(attendanceForm, () => app.withSubmitLoading(attendanceForm.querySelector('button[type="submit"]'), async () => {
                            try {
                                const body = {
                                    courseid: coursesId,
                                    modulesid: meetingId,
                                    kode,
                                };

                                if (inferredMeetingNumber) {
                                    body.pertemuan = inferredMeetingNumber;
                                }

                                const result = await app.request('/api/attendance', {
                                    method: 'POST',
                                    json: body,
                                });

                                // Offline session: the code waits in the outbox, not at the portal.
                                if (app.handleQueuedActionResult(result)) {
                                    return;
                                }

                                app.showNotice(
                                    result?.data?.status === 'already_marked'
                                        ? 'Kehadiran kamu sudah tercatat untuk pertemuan ini.'
                                        : 'Absensi berhasil dikirim.',
                                    'success'
                                );
                                app.clearCachedResponse('/api/dashboard');
                                app.clearCachedResponse('/api/attendance/today');
                                app.clearCachedResponse(endpoint);
                                // The server already shows this meeting as attended,
                                // so re-reading it is instant (no portal scrape).
                                await loadMeeting();
                            } catch (error) {
                                if (error.isTimeout) {
                                    app.showNotice('Portal lambat merespons. Mengecek status absensi...', 'warning');
                                    await loadMeeting();

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                });

