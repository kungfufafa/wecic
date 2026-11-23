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
                    <form id="attendance-form" class="flex flex-col gap-4">
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-qr-scan>
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                                <span>Scan QR</span>
                            </button>
                            <label class="${app.buttonClass('secondary', { small: true })} focus-within:ring-2 focus-within:ring-brand/40">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 8h.01" /><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z" /><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5" /><path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3" /></svg>
                                <span>Upload QR</span>
                                <input type="file" class="sr-only" data-qr-file accept="image/*" title="Pilih gambar QR">
                            </label>
                        </div>

                        <div class="${app.ui.field}">
                            <label for="attendance-code" class="${app.ui.label}">Kode absen</label>
                            <input id="attendance-code" type="text" maxlength="20" class="${app.ui.input} text-center font-mono font-medium tracking-widest" placeholder="Contoh: 8a2b9c1d" autocomplete="off" autocapitalize="off" spellcheck="false" required>
                        </div>

                        <div>
                            <button type="submit" class="${app.buttonClass('primary')} w-full" data-online-required data-outbox-capable>
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                <span>Kirim absen</span>
                            </button>
                        </div>
                    </form>
                `;

                let attendanceSectionMarkup = '';
                if (attendanceState === 'recorded') {
                    // The header badge already says "Hadir": the body repeats only what it adds.
                    const statusRepeatsBadge = /^hadir$/i.test(String(attendanceStatusText || 'Hadir').trim());
                    const attendanceTimeLine = attendanceInfo.time && !attendanceStatusText.includes(attendanceInfo.time)
                        ? `Absen jam ${app.escapeHtml(attendanceInfo.time)}`
                        : '';

                    attendanceSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Presensi</h3>
                                ${app.renderBadge('Hadir', 'success')}
                            </div>
                            <div class="${app.ui.cardBody}">
                                <div class="min-w-0 text-sm">
                                    ${statusRepeatsBadge ? '' : `<div class="font-medium text-ink">${safeAttendanceStatus}</div>`}
                                    ${attendanceTimeLine
                                        ? `<div class="text-ink-muted tabular-nums">${attendanceTimeLine}</div>`
                                        : (statusRepeatsBadge ? '<div class="text-ink-muted">Tercatat di portal.</div>' : '')}
                                </div>
                            </div>
                        </div>
                    `;
                } else if (attendanceState === 'past' || attendanceState === 'upcoming') {
                    // Direct POST stays directly visible even when the meeting date
                    // is another day (e.g. make-up or moved class); the portal decides.
                    const isPast = attendanceState === 'past';

                    attendanceSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Presensi</h3>
                                ${isPast ? app.renderBadge('Sudah lewat', 'danger') : app.renderBadge('Belum dibuka', 'neutral')}
                            </div>
                            <div class="${app.ui.cardBody}">
                                <div class="${app.alertTone(isPast ? 'warning' : 'secondary')} mb-4">
                                    ${isPast
                                        ? `Jadwal ${safeDate}, kehadiranmu belum terlihat tercatat di portal.`
                                        : `Absensi biasanya dibuka ${safeDate}.`}
                                </div>
                                ${attendanceFormMarkup}
                            </div>
                        </div>
                    `;
                } else {
                    const expiredNotice = attendanceExpired ? `
                        <div class="${app.alertTone('danger')} mb-4 flex gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="${app.ui.alertIcon.danger}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8v4" /><path d="M12 16h.01" /></svg>
                            <div class="min-w-0">
                                <div class="font-medium text-danger">Waktu absen telah berakhir</div>
                                <div class="mt-0.5 text-xs leading-relaxed text-ink-muted">${app.escapeHtml(attendanceStatusText)}.</div>
                            </div>
                        </div>
                    ` : (attendanceNotOpen ? `
                        <div class="${app.alertTone('secondary')} mb-4">
                            Portal belum membuka absensi pertemuan ini${attendanceStatusText ? ` (${app.escapeHtml(attendanceStatusText)})` : ''}.
                        </div>
                    ` : '');

                    attendanceSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Presensi</h3>
                                ${attendanceExpired ? app.renderBadge('Ditutup', 'danger') : (attendanceNotOpen ? app.renderBadge('Belum dibuka', 'neutral') : app.renderBadge('Belum Absen', 'warning'))}
                            </div>
                            <div class="${app.ui.cardBody}">
                                ${expiredNotice}
                                ${attendanceFormMarkup}
                            </div>
                        </div>
                    `;
                }

                const uploadFormMarkup = `
                    <form id="assignment-form" class="flex flex-col gap-4">
                        <div class="${app.ui.field}">
                            <span class="${app.ui.label}">File PDF</span>
                            <label for="assignment-file" class="${app.ui.dropzone} focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/20" id="assignment-dropzone">
                                <span class="${app.ui.emptyIcon}" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg></span>
                                <div id="dropzone-empty" class="flex flex-col items-center gap-0.5">
                                    <div class="text-sm font-medium text-ink">Pilih PDF</div>
                                    <div class="text-xs text-ink-subtle">Maks. 10 MB</div>
                                </div>
                                <div id="dropzone-selected" class="hidden flex flex-col items-center gap-0.5">
                                    <div class="max-w-full truncate text-sm font-medium text-ink" id="selected-file-name">file.pdf</div>
                                    <div class="text-xs text-ink-subtle tabular-nums" id="selected-file-size">0 KB</div>
                                </div>
                                <input id="assignment-file" type="file" accept=".pdf,application/pdf" class="sr-only" required>
                            </label>
                        </div>

                        <div class="${app.ui.field}">
                            <label for="assignment-note" class="${app.ui.label}">Catatan</label>
                            <textarea id="assignment-note" class="${app.ui.textarea}" rows="2" placeholder="Opsional"></textarea>
                        </div>

                        <button type="submit" class="${app.buttonClass('primary')} w-full" data-online-required data-outbox-capable>
                            <span>Kirim</span>
                        </button>
                    </form>
                `;

                let assignmentSectionMarkup = '';
                if (assignmentAlreadyUploaded) {
                    assignmentSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Tugas</h3>
                                ${app.renderBadge('Terkirim', 'success')}
                            </div>
                            <div class="${app.ui.cardBody}">
                                ${assignmentSubmission?.date ? `
                                    <div class="mb-3 text-xs text-ink-subtle tabular-nums">${app.escapeHtml(assignmentSubmission.date)}</div>
                                ` : ''}
                                ${(() => {
                                    const score = String(assignmentSubmission?.score ?? '').trim();
                                    const graded = score !== '' && !/^0+([.,]0+)?$/.test(score) && !/belum/i.test(score);

                                    return graded ? `
                                        <div class="mb-3 flex items-center justify-between rounded-lg bg-surface p-3">
                                            <span class="text-[13px] font-medium text-ink-muted">Nilai</span>
                                            <span class="text-xl font-semibold tracking-tight text-ink tabular-nums">${app.escapeHtml(score)}</span>
                                        </div>
                                    ` : `
                                        <div class="mb-3 text-xs text-ink-subtle">Belum dinilai dosen.</div>
                                    `;
                                })()}
                                <div class="flex flex-col gap-2">
                                    ${assignmentFileInfo?.href ? `
                                        <a href="${app.absoluteCampusUrl(assignmentFileInfo.href)}" target="_blank" rel="noreferrer" class="${app.buttonClass('primary')} w-full">Buka file</a>
                                    ` : `
                                        <div class="text-sm text-ink-subtle">File sudah diterima portal.</div>
                                    `}
                                    ${assignmentFileInfo?.href && app.buildCampusDownloadUrl(assignmentFileInfo.href) ? `
                                        <a href="${app.buildCampusDownloadUrl(assignmentFileInfo.href)}" class="${app.buttonClass('secondary')} w-full">Download</a>
                                    ` : ''}
                                    ${assignmentDeletePayload ? `
                                        <button type="button" class="${app.buttonClass('destructive')} w-full" id="assignment-delete" data-online-required>Hapus</button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                } else if (assignmentStatus === 'upload_unavailable') {
                    // The meeting page no longer links the upload form: still keep
                    // direct POST upload visible so the portal endpoint decides.
                    assignmentSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Tugas</h3>
                                ${app.renderBadge('Ditutup', 'danger')}
                            </div>
                            <div class="${app.ui.cardBody} flex flex-col gap-4"${assignmentParams ? ' data-assignment-closed' : ''}>
                                <div class="${app.alertTone('secondary')}">
                                    Upload ditutup di portal${hasDeadline ? ` (${app.escapeHtml(deadlineText)})` : ''}. Kirim langsung — portal menentukan hasilnya.
                                </div>
                                ${assignmentParams ? uploadFormMarkup : ''}
                            </div>
                        </div>
                    `;
                } else if (assignmentStatus === 'unresolved') {
                    assignmentSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Upload tugas</h3>
                                ${app.renderBadge('Status belum diketahui', 'neutral')}
                            </div>
                            <div class="${app.ui.cardBody} flex flex-col gap-4">
                                <div class="${app.alertTone('warning')}">
                                    Status tugas belum terbaca. Cek ulang dulu agar tidak terkirim dua kali.
                                </div>
                                <button type="button" class="${app.buttonClass('secondary')} w-full" data-assignment-recheck>Cek ulang status</button>
                                ${assignmentParams ? uploadFormMarkup : ''}
                            </div>
                        </div>
                    `;
                } else if (assignmentParams) {
                    assignmentSectionMarkup = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Upload tugas</h3>
                            </div>
                            <div class="${app.ui.cardBody}">
                                ${uploadFormMarkup}
                            </div>
                        </div>
                    `;
                }

                const notebookSectionMarkup = `
                    <div class="${app.ui.card}" data-slot="card">
                        <div class="${app.ui.cardHeader}">
                            <div class="min-w-0">
                                <h3 class="${app.ui.cardTitle}">Notebook</h3>
                                ${notebookLimitReached
                                    ? `<p class="mt-0.5 text-[13px] leading-normal text-warning">Notebook penuh. Hapus sumber yang tidak perlu di notebook.</p>`
                                    : ''}
                            </div>
                        </div>
                        <div class="${app.ui.cardBody} flex flex-col gap-2">
                            <button type="button" class="${app.buttonClass('secondary')} w-full" id="meeting-add-notebook">Buka di Notebook</button>
                        </div>
                    </div>
                `;

