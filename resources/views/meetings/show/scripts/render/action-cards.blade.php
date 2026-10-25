{{--
    Pertemuan script, the markup of the action column's cards: attendance
    (with its form), assignment (with its upload form), and Notebook. Part
    of render() opened in meetings/show/scripts/render.blade.php: it shares
    that scope (payload, detail and the meeting, attendance and assignment
    state derived there) and the handler's (app, the helpers and the action
    slots).
--}}
                // Render Actions Sidebar
                const attendanceFormMarkup = `
                    <form id="attendance-form" class="space-y-4">
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" class="${app.buttonClass('secondary')} flex items-center justify-center gap-1.5 py-2.5 text-xs font-bold" data-qr-scan>
                                <span>Scan QR</span>
                            </button>
                            <label class="${app.buttonClass('secondary')} flex cursor-pointer items-center justify-center gap-1.5 py-2.5 text-xs font-bold">
                                <span>Upload QR</span>
                                <input type="file" class="sr-only" data-qr-file accept="image/*" title="Pilih gambar QR">
                            </label>
                        </div>

                        <div>
                            <label for="attendance-code" class="mycic-label mb-1.5 block">Kode absen</label>
                            <input id="attendance-code" type="text" maxlength="20" class="mycic-input py-2.5 text-center font-mono text-base font-bold tracking-widest" placeholder="Contoh: 8a2b9c1d" autocomplete="off" autocapitalize="off" spellcheck="false" required>
                        </div>

                        <div class="pt-1">
                            <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block py-2.5 font-bold shadow-xs" data-online-required data-outbox-capable>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                <span>Kirim absen</span>
                            </button>
                        </div>
                    </form>
                `;

                let attendanceSectionMarkup = '';
                if (attendanceState === 'recorded') {
                    attendanceSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Presensi</h3>
                                ${app.renderBadge('Hadir', 'success')}
                            </div>
                            <div class="mycic-card-body">
                                <div class="rounded-xl border border-success/30 bg-success-soft/60 p-4 text-center">
                                    <div class="text-sm font-bold text-ink">${safeAttendanceStatus}</div>
                                    ${attendanceInfo.time && !attendanceStatusText.includes(attendanceInfo.time) ? `<div class="mt-1 text-xs text-ink-subtle">Absen jam ${app.escapeHtml(attendanceInfo.time)}</div>` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                } else if (attendanceState === 'past' || attendanceState === 'upcoming') {
                    // The date gate guards against sending today's code to an old
                    // meeting; a moved or make-up class can still send it on purpose.
                    const isPast = attendanceState === 'past';

                    attendanceSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Presensi</h3>
                                ${isPast ? app.renderBadge('Sudah lewat', 'danger') : app.renderBadge('Belum dibuka', 'neutral')}
                            </div>
                            <div class="mycic-card-body space-y-4">
                                <div class="${app.alertTone(isPast ? 'warning' : 'secondary')} mb-0">
                                    ${isPast
                                        ? `Pertemuan ini dijadwalkan ${safeDate} dan kehadiranmu belum terlihat tercatat di portal. Absensi biasanya hanya diterima saat kelas berlangsung.`
                                        : `Absensi dibuka saat kelas berlangsung (${safeDate}).`}
                                </div>
                                <details class="rounded-xl border border-border-light" data-attendance-override>
                                    <summary class="cursor-pointer px-3 py-2 text-sm font-semibold text-ink">Tetap kirim kode</summary>
                                    <div class="space-y-3 border-t border-border-light p-3">
                                        <p class="text-xs text-ink-subtle">Hanya untuk kelas pengganti atau kelas yang jadwalnya dipindah ke hari ini. Portal yang memutuskan: kode ditolak bila absensi pertemuan ini tidak dibuka.</p>
                                        ${attendanceFormMarkup}
                                    </div>
                                </details>
                            </div>
                        </div>
                    `;
                } else {
                    const expiredNotice = attendanceExpired ? `
                        <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3.5 mb-3 text-xs text-rose-600 dark:text-rose-300">
                            <div class="font-bold flex items-center gap-1.5 mb-1">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <span>Waktu Absen Telah Berakhir</span>
                            </div>
                            <div>${app.escapeHtml(attendanceStatusText)}. Kode presensi yang dikirim kemungkinan akan ditolak oleh sistem kampus karena batas waktu sudah lewat.</div>
                        </div>
                    ` : (attendanceNotOpen ? `
                        <div class="${app.alertTone('secondary')} mb-3">
                            Portal belum membuka absensi pertemuan ini${attendanceStatusText ? ` (${app.escapeHtml(attendanceStatusText)})` : ''}. Kirim kode saat dosen membukanya di kelas.
                        </div>
                    ` : '');

                    attendanceSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Presensi</h3>
                                ${attendanceExpired ? app.renderBadge('Ditutup', 'danger') : (attendanceNotOpen ? app.renderBadge('Belum dibuka', 'neutral') : app.renderBadge('Belum Absen', 'warning'))}
                            </div>
                            <div class="mycic-card-body">
                                ${expiredNotice}
                                ${attendanceFormMarkup}
                            </div>
                        </div>
                    `;
                }

                const uploadFormMarkup = `
                    <form id="assignment-form" class="space-y-4">
                        <div>
                            <label class="mycic-label mb-1.5 block">File PDF</label>
                            <label for="assignment-file" class="mycic-dropzone" id="assignment-dropzone">
                                <div id="dropzone-empty" class="flex flex-col items-center">
                                    <div class="mt-1 text-xs font-semibold text-ink">Pilih PDF</div>
                                    <div class="text-[0.7rem] text-ink-subtle">Maks. 10MB</div>
                                </div>
                                <div id="dropzone-selected" class="hidden flex flex-col items-center">
                                    <div class="mt-1 truncate max-w-full text-xs font-bold text-ink" id="selected-file-name">file.pdf</div>
                                    <div class="text-[0.7rem] text-ink-subtle" id="selected-file-size">0 KB</div>
                                </div>
                                <input id="assignment-file" type="file" accept=".pdf,application/pdf" class="sr-only" required>
                            </label>
                        </div>

                        <div>
                            <label for="assignment-note" class="mycic-label mb-1.5 block">Catatan</label>
                            <textarea id="assignment-note" class="mycic-input" rows="2" placeholder="Opsional"></textarea>
                        </div>

                        <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block py-2.5 font-bold shadow-xs" data-online-required data-outbox-capable>
                            <span>Kirim</span>
                        </button>
                    </form>
                `;

                let assignmentSectionMarkup = '';
                if (assignmentAlreadyUploaded) {
                    assignmentSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Tugas</h3>
                                ${app.renderBadge('Terkirim', 'success')}
                            </div>
                            <div class="mycic-card-body">
                                ${assignmentSubmission?.date ? `
                                    <div class="mb-3 text-xs text-ink-subtle">${app.escapeHtml(assignmentSubmission.date)}</div>
                                ` : ''}
                                ${(() => {
                                    const score = String(assignmentSubmission?.score ?? '').trim();
                                    const graded = score !== '' && !/^0+([.,]0+)?$/.test(score) && !/belum/i.test(score);

                                    return graded ? `
                                        <div class="mb-3 flex items-center justify-between rounded-lg border border-accent/30 bg-accent-soft p-3">
                                            <span class="text-xs font-medium text-amber-900 dark:text-amber-200">Nilai</span>
                                            <span class="text-base font-extrabold text-amber-900 dark:text-amber-200">${app.escapeHtml(score)}</span>
                                        </div>
                                    ` : `
                                        <div class="mb-3 text-xs text-ink-subtle">Belum dinilai dosen.</div>
                                    `;
                                })()}
                                <div class="flex flex-col gap-2">
                                    ${assignmentFileInfo?.href ? `
                                        <a href="${app.absoluteCampusUrl(assignmentFileInfo.href)}" target="_blank" rel="noreferrer" class="${app.buttonClass('primary')} mycic-btn-block">Buka file</a>
                                    ` : `
                                        <div class="text-sm text-ink-subtle">File sudah diterima portal.</div>
                                    `}
                                    ${assignmentFileInfo?.href && app.buildCampusDownloadUrl(assignmentFileInfo.href) ? `
                                        <a href="${app.buildCampusDownloadUrl(assignmentFileInfo.href)}" class="${app.buttonClass('secondary')} mycic-btn-block">Download</a>
                                    ` : ''}
                                    ${assignmentDeletePayload ? `
                                        <button type="button" class="${app.buttonClass('destructive')} mycic-btn-block" id="assignment-delete" data-online-required>Hapus</button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                } else if (assignmentStatus === 'upload_unavailable') {
                    // The meeting page no longer links the upload form: the portal
                    // is not taking files for this assignment.
                    assignmentSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Tugas</h3>
                                ${app.renderBadge('Ditutup', 'danger')}
                            </div>
                            <div class="mycic-card-body">
                                <div class="${app.alertTone('warning')} mb-0">
                                    Pengumpulan tugas ini sudah ditutup portal${hasDeadline ? ` (deadline ${app.escapeHtml(deadlineText)})` : ''}.
                                </div>
                            </div>
                        </div>
                    `;
                } else if (assignmentStatus === 'unresolved') {
                    assignmentSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Upload tugas</h3>
                                ${app.renderBadge('Status belum diketahui', 'neutral')}
                            </div>
                            <div class="mycic-card-body space-y-4">
                                <div class="${app.alertTone('warning')} mb-0">
                                    Status pengumpulan belum bisa dicek karena halaman kelas di portal tidak terbaca. Kalau kamu sudah mengirim tugas ini, cek ulang dulu supaya file tidak terkirim dua kali.
                                </div>
                                <button type="button" class="${app.buttonClass('secondary')} mycic-btn-block" data-assignment-recheck>Cek ulang status</button>
                                ${assignmentParams ? uploadFormMarkup : ''}
                            </div>
                        </div>
                    `;
                } else if (assignmentParams) {
                    assignmentSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Upload tugas</h3>
                            </div>
                            <div class="mycic-card-body">
                                ${uploadFormMarkup}
                            </div>
                        </div>
                    `;
                }

                const notebookSectionMarkup = `
                    <div class="mycic-card">
                        <div class="mycic-card-header">
                            <h3 class="mycic-card-title">Notebook</h3>
                            ${notebookLimitReached
                                ? `<p class="mt-0.5 text-caption text-warning">Notebook mata kuliah ini sudah penuh; pertemuan ini belum dimasukkan. Hapus sumber yang tidak perlu di notebook.</p>`
                                : `<p class="mt-0.5 text-caption text-ink-subtle">Saat dibuka, catatan pertemuan ini dan berkas materinya masuk otomatis ke notebook ${app.escapeHtml(courseName)}: untuk belajar mandiri, tanya jawab bersitasi, dan kerangka tugas. Tombol ini memperbarui catatannya dan membuka notebooknya.</p>`}
                        </div>
                        <div class="mycic-card-body flex flex-col gap-2">
                            <button type="button" class="${app.buttonClass('secondary')} mycic-btn-block" id="meeting-add-notebook">Buka di Notebook</button>
                        </div>
                    </div>
                `;

