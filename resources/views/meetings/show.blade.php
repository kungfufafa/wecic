@extends('layouts.app')

@section('title', 'Detail Pertemuan')
@section('page_id', 'meeting-show')

@section('content')
    <div id="meeting-hero" class="mb-5">
        <div class="mycic-hero">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <div class="mycic-skeleton h-8 w-28 rounded-lg bg-white/20"></div>
                        <div class="mycic-skeleton h-6 w-20 rounded-md bg-white/20"></div>
                    </div>
                    <div class="mycic-skeleton h-6 w-32 rounded-full bg-white/20"></div>
                </div>
                <div class="space-y-2">
                    <div class="mycic-skeleton h-7 w-3/4 max-w-md rounded-lg bg-white/20"></div>
                    <div class="mycic-skeleton h-4 w-1/2 max-w-sm rounded bg-white/20"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Attendance or upload queued for this meeting while offline: not at the portal yet. --}}
    <div class="mb-5 hidden" data-outbox-list data-outbox-match="meeting:{{ e($meetingId) }}" data-outbox-heading="Antrean offline pertemuan ini"></div>

    <div class="grid gap-5 lg:grid-cols-12">
        <div class="space-y-5 lg:col-span-8" id="meeting-content">
            <div class="mycic-card">
                <div class="mycic-card-body flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div class="mycic-skeleton h-6 w-40"></div>
                        <div class="mycic-skeleton h-8 w-24 rounded-lg"></div>
                    </div>
                    <div class="space-y-2">
                        <div class="mycic-skeleton h-4 w-full"></div>
                        <div class="mycic-skeleton h-4 w-5/6"></div>
                        <div class="mycic-skeleton h-4 w-4/6"></div>
                    </div>
                    <div class="mycic-skeleton h-20 w-full rounded-xl"></div>
                </div>
            </div>

            <div class="mycic-card">
                <div class="mycic-card-body flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div class="mycic-skeleton h-6 w-48"></div>
                        <div class="mycic-skeleton h-6 w-32 rounded-md"></div>
                    </div>
                    <div class="mycic-skeleton h-16 w-full rounded-xl"></div>
                </div>
            </div>
        </div>

        <div class="space-y-5 order-first lg:order-none lg:col-span-4" id="meeting-actions">
            <div class="mycic-card">
                <div class="mycic-card-header">
                    <div class="mycic-skeleton h-5 w-28"></div>
                </div>
                <div class="mycic-card-body flex flex-col gap-3">
                    <div class="mycic-skeleton h-10 w-full rounded-lg"></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="mycic-skeleton h-10 rounded-lg"></div>
                        <div class="mycic-skeleton h-10 rounded-lg"></div>
                    </div>
                    <div class="mycic-skeleton h-11 w-full rounded-lg"></div>
                </div>
            </div>

            <div class="mycic-card">
                <div class="mycic-card-header">
                    <div class="mycic-skeleton h-5 w-24"></div>
                </div>
                <div class="mycic-card-body flex flex-col gap-3">
                    <div class="mycic-skeleton h-24 w-full rounded-xl"></div>
                    <div class="mycic-skeleton h-11 w-full rounded-lg"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const meetingId = @json($meetingId);
            const coursesId = @json($coursesId);

            if (!coursesId) {
                app.showNotice('courses_id wajib.', 'warning');

                return;
            }

            const endpoint = `/api/meeting/${meetingId}?courses_id=${coursesId}`;

            const parseAssignmentParams = (href) => {
                if (!href) {
                    return null;
                }

                const query = href.includes('?') ? href.split('?')[1] : href;
                const params = new URLSearchParams(query);

                if (!params.get('courses_id') || !params.get('id') || !params.get('pertemuan')) {
                    return null;
                }

                return {
                    courses_id: params.get('courses_id'),
                    id: params.get('id'),
                    pertemuan: params.get('pertemuan'),
                };
            };

            const inferMeetingNumber = (materials) => {
                return materials.assignment_topic?.match(/(\d+)/)?.[1]
                    || materials.main_topic?.match(/Pertemuan\s+(\d+)/i)?.[1]
                    || null;
            };

            const deriveAssignmentParams = (assignmentInfo, materials) => {
                const directParams = parseAssignmentParams(assignmentInfo.upload_link);

                if (directParams) {
                    return directParams;
                }

                const hasAssignmentContent = Boolean(
                    materials.assignment_topic
                    || materials.assignment_instructions
                    || materials.assignment_material_iframe
                    || String(assignmentInfo.upload_text || '').trim()
                    || String(assignmentInfo.deadline || '').trim()
                );
                // The server resolves the meeting number from "Pertemuan N";
                // the topic regex is only a fallback.
                const inferredMeetingNumber = assignmentInfo.meeting_number || inferMeetingNumber(materials);

                if (!hasAssignmentContent || !coursesId || !meetingId || !inferredMeetingNumber) {
                    return null;
                }

                return {
                    courses_id: String(coursesId),
                    id: String(meetingId),
                    pertemuan: String(inferredMeetingNumber),
                };
            };

            const extractCourseName = (breadcrumb) => {
                if (!breadcrumb) {
                    return null;
                }

                const match = breadcrumb.match(/Kelas\s+([^>]+?)(?:\s*>|$)/i);
                if (match && match[1]) {
                    return match[1].trim();
                }

                return null;
            };

            const cleanTopic = (topic) => {
                if (!topic) {
                    return '';
                }

                return topic.replace(/^(?:Pokok\s+Bahasan|Materi|Topik)\s*:\s*/i, '').trim();
            };

            const parseOutlineItems = (text) => {
                if (!text) {
                    return [];
                }

                const clean = text.replace(/&amp;/g, '&');
                if (/\d+\.\s*/.test(clean)) {
                    const parts = clean.split(/(?=\d+\.\s*)/).map(s => s.trim()).filter(Boolean);
                    if (parts.length > 1) {
                        return parts.map(p => {
                            const match = p.match(/^(\d+)\.\s*(.*)$/s);
                            if (match) {
                                return { num: match[1], text: match[2].trim() };
                            }
                            return { num: '', text: p };
                        });
                    }
                }

                const lines = clean.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                if (lines.length > 1) {
                    return lines.map((l, i) => ({ num: String(i + 1), text: l }));
                }

                return [{ num: '', text: clean }];
            };

            const formatBytes = (bytes) => {
                if (!bytes || bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
            };

            const render = (payload) => {
                const detail = payload.class_detail || {};
                const meetingInfo = detail.meeting_info || {};
                const lecturerInfo = detail.lecturer_info || {};
                const scheduleInfo = detail.schedule_info || {};
                const attendanceInfo = detail.attendance_info || {};
                const assignmentInfo = detail.assignment_info || {};
                const materials = detail.materials || {};
                const instructions = detail.instructions || {};
                const assignmentParams = deriveAssignmentParams(assignmentInfo, materials);
                const assignmentSubmission = assignmentInfo.submission || null;
                const assignmentFileInfo = assignmentSubmission?.file_info || null;
                const assignmentDeletePayload = assignmentInfo.delete_payload || null;
                const assignmentAlreadyUploaded = assignmentInfo.status === 'uploaded';
                const inferredMeetingNumber = assignmentParams?.pertemuan
                    || assignmentInfo.meeting_number
                    || inferMeetingNumber(materials)
                    || (meetingInfo.breadcrumb?.match(/Pertemuan\s+(\d+)/i)?.[1])
                    || '';
                const attendanceStatusText = String(attendanceInfo.status || '').trim();
                const attendanceRecorded = Boolean(attendanceInfo.time)
                    || (/hadir|terlambat|izin|sakit/i.test(attendanceStatusText) && !/belum|silakan|scan|qr/i.test(attendanceStatusText));

                const courseName = extractCourseName(meetingInfo.breadcrumb) || 'Mata Kuliah';
                const mainTopicClean = cleanTopic(materials.main_topic || materials.assignment_topic) || 'Pembahasan Pertemuan';
                const meetingLabel = inferredMeetingNumber ? `P${inferredMeetingNumber}` : 'Pertemuan';

                // Render Hero
                const hero = document.getElementById('meeting-hero');
                const safeLecturer = app.escapeHtml(lecturerInfo.name || '-');
                const safeDate = app.escapeHtml(scheduleInfo.date || '-');
                const safeTime = app.escapeHtml(scheduleInfo.start_time || '-');
                const safeAttendanceStatus = app.escapeHtml(attendanceStatusText || (attendanceRecorded ? 'Hadir' : 'Belum Absen'));

                const attendanceExpired = !attendanceRecorded && /berakhir|ditutup|tutup|selesai/i.test(attendanceStatusText);
                const attendanceBadgeMarkup = attendanceRecorded
                    ? `<span class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/20 px-2.5 py-0.5 text-xs font-semibold text-emerald-200">Hadir</span>`
                    : (attendanceExpired
                        ? `<span class="inline-flex items-center rounded-full border border-rose-400/30 bg-rose-500/20 px-2.5 py-0.5 text-xs font-semibold text-rose-200">Ditutup</span>`
                        : `<span class="inline-flex items-center rounded-full border border-amber-400/30 bg-amber-500/20 px-2.5 py-0.5 text-xs font-semibold text-amber-200">Belum</span>`);

                hero.innerHTML = `
                    <div class="mycic-hero">
                        <div class="flex flex-col gap-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="/class" class="mycic-hero-pill">Kembali</a>
                                    <span class="inline-flex items-center rounded-lg border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-semibold text-white/90">${app.escapeHtml(courseName)}</span>
                                    <span class="inline-flex items-center rounded-lg bg-accent px-2.5 py-1 text-xs font-bold text-slate-900">${app.escapeHtml(meetingLabel)}</span>
                                </div>
                                ${attendanceBadgeMarkup}
                            </div>
                            <div>
                                <h1 class="text-xl font-extrabold tracking-tight text-white sm:text-2xl">
                                    ${app.escapeHtml(mainTopicClean)}
                                </h1>
                                <div class="mt-1.5 text-sm text-white/80">
                                    ${safeLecturer} · ${safeDate}${safeTime !== '-' ? ` · ${safeTime}` : ''}${attendanceRecorded && attendanceInfo.time ? ` · ${app.escapeHtml(attendanceInfo.time)}` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                // Render Content Sections
                const contentSections = [];

                // 1. Materi Utama
                if (materials.main_topic || materials.main_description || materials.main_material_iframe) {
                    const outlineItems = parseOutlineItems(materials.main_description);
                    let outlineMarkup = '';

                    if (outlineItems.length > 1) {
                        outlineMarkup = `
                            <div class="mt-4 grid gap-2">
                                ${outlineItems.map(item => `
                                    <div class="mycic-outline-item">
                                        <span class="mycic-outline-badge">${app.escapeHtml(item.num || '•')}</span>
                                        <span class="min-w-0 flex-1 text-sm font-medium text-ink">${app.escapeHtml(item.text)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        `;
                    } else if (materials.main_description) {
                        outlineMarkup = `
                            <div class="mt-3 rounded-xl border border-border-light bg-surface/40 p-4 text-sm leading-relaxed text-ink">
                                ${app.escapeHtml(materials.main_description)}
                            </div>
                        `;
                    }

                    let mainResourceMarkup = '';
                    if (materials.main_material_iframe) {
                        const fileUrl = app.absoluteCampusUrl(materials.main_material_iframe);
                        const downloadUrl = app.buildCampusDownloadUrl(materials.main_material_iframe);

                        mainResourceMarkup = `
                            <div class="mt-4 rounded-xl border border-border bg-panel p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-bold text-ink">Modul PDF</div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-toggle-preview="preview-main-modul">Pratinjau</button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                        ${downloadUrl ? `<a href="${downloadUrl}" class="${app.buttonClass('primary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </div>
                                <div id="preview-main-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-border bg-surface px-3 py-2 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau</span>
                                        <button type="button" class="text-ink-subtle hover:text-ink font-semibold" data-close-preview="preview-main-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h2 class="mycic-card-title">Materi</h2>
                            </div>
                            <div class="mycic-card-body">
                                <div class="text-base font-bold text-ink">${app.escapeHtml(mainTopicClean)}</div>
                                ${outlineMarkup}
                                ${mainResourceMarkup}
                            </div>
                        </div>
                    `);
                }

                // 2. Tugas Perkuliahan
                if (materials.assignment_topic || materials.assignment_instructions || materials.assignment_material_iframe || assignmentInfo.upload_text) {
                    const assignmentTitle = app.escapeHtml(materials.assignment_topic || assignmentInfo.upload_text || 'Tugas');
                    const deadlineText = materials.assignment_instructions || assignmentInfo.deadline;
                    let assignmentResourceMarkup = '';

                    if (materials.assignment_material_iframe) {
                        const fileUrl = app.absoluteCampusUrl(materials.assignment_material_iframe);
                        const downloadUrl = app.buildCampusDownloadUrl(materials.assignment_material_iframe);

                        assignmentResourceMarkup = `
                            <div class="mt-4 rounded-xl border border-border bg-panel p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-bold text-ink">Soal PDF</div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-toggle-preview="preview-assignment-modul">Pratinjau</button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                        ${downloadUrl ? `<a href="${downloadUrl}" class="${app.buttonClass('primary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </div>
                                <div id="preview-assignment-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-border bg-surface px-3 py-2 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau</span>
                                        <button type="button" class="text-ink-subtle hover:text-ink font-semibold" data-close-preview="preview-assignment-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h2 class="mycic-card-title">Tugas</h2>
                            </div>
                            <div class="mycic-card-body">
                                <div class="text-base font-bold text-ink">${assignmentTitle}</div>
                                ${deadlineText && deadlineText !== ':' ? `
                                    <div class="mt-3 rounded-xl border border-warning/30 bg-warning-soft/60 px-3 py-2 text-sm">
                                        <span class="text-ink-subtle">Deadline:</span>
                                        <span class="font-bold text-warning">${app.escapeHtml(deadlineText)}</span>
                                    </div>
                                ` : ''}
                                ${assignmentResourceMarkup}
                            </div>
                        </div>
                    `);
                }

                // Render to meeting-content
                const contentContainer = document.getElementById('meeting-content');
                if (contentSections.length > 0) {
                    contentContainer.innerHTML = `<div class="space-y-5">${contentSections.join('')}</div>`;
                } else {
                    contentContainer.innerHTML = app.renderEmptyState('Belum ada materi.', 'secondary');
                }

                // Render Actions Sidebar
                const actionsContainer = document.getElementById('meeting-actions');

                let attendanceSectionMarkup = '';
                if (attendanceRecorded) {
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
                } else {
                    const expiredNotice = attendanceExpired ? `
                        <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3.5 mb-3 text-xs text-rose-600 dark:text-rose-300">
                            <div class="font-bold flex items-center gap-1.5 mb-1">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <span>Waktu Absen Telah Berakhir</span>
                            </div>
                            <div>${app.escapeHtml(attendanceStatusText)}. Kode presensi yang dikirim kemungkinan akan ditolak oleh sistem kampus karena batas waktu sudah lewat.</div>
                        </div>
                    ` : '';

                    attendanceSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between">
                                <h3 class="mycic-card-title">Presensi</h3>
                                ${attendanceExpired ? app.renderBadge('Ditutup', 'danger') : app.renderBadge('Belum Absen', 'warning')}
                            </div>
                            <div class="mycic-card-body">
                                ${expiredNotice}
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
                                        <input id="attendance-code" type="text" maxlength="20" class="mycic-input py-2.5 text-center font-mono text-base font-bold uppercase tracking-widest" placeholder="Contoh: 8A2B9C1D" required>
                                    </div>

                                    <div class="pt-1">
                                        <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block py-2.5 font-bold shadow-xs" data-online-required data-outbox-capable>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                            <span>Kirim absen</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    `;
                }

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
                } else if (assignmentParams) {
                    assignmentSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Upload tugas</h3>
                            </div>
                            <div class="mycic-card-body">
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
                                            <input id="assignment-file" type="file" accept=".pdf" class="sr-only" required>
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
                            </div>
                        </div>
                    `;
                }

                const notebookSectionMarkup = `
                    <div class="mycic-card">
                        <div class="mycic-card-header">
                            <h3 class="mycic-card-title">Notebook</h3>
                            <p class="mt-0.5 text-caption text-ink-subtle">Saat dibuka, catatan pertemuan ini dan berkas materinya masuk otomatis ke notebook ${app.escapeHtml(courseName)}: untuk belajar mandiri, tanya jawab bersitasi, dan kerangka tugas. Tombol ini memperbarui catatannya dan membuka notebooknya.</p>
                        </div>
                        <div class="mycic-card-body flex flex-col gap-2">
                            <button type="button" class="${app.buttonClass('secondary')} mycic-btn-block" id="meeting-add-notebook">Buka di Notebook</button>
                        </div>
                    </div>
                `;

                actionsContainer.innerHTML = `
                    <div class="space-y-5">
                        ${attendanceSectionMarkup}
                        ${assignmentSectionMarkup}
                        ${notebookSectionMarkup}
                    </div>
                `;

                const addNotebookButton = document.getElementById('meeting-add-notebook');
                addNotebookButton?.addEventListener('click', () => {
                    app.withSubmitLoading(addNotebookButton, async () => {
                        try {
                            const result = await app.request('/api/notebooks/meetings', {
                                method: 'POST',
                                json: { meeting_id: String(meetingId), courses_id: String(coursesId) },
                                timeout: 120000,
                            });
                            const notebook = result?.data?.notebook;
                            const queued = Number(result?.data?.meeting?.queued_files || 0);

                            app.showNotice(`${result?.message || 'Pertemuan masuk ke notebook.'}${queued ? ` ${queued} berkas diarsipkan di background.` : ''}`, 'success');

                            if (notebook?.id) {
                                addNotebookButton.outerHTML = `<a href="/notebook/${notebook.id}" class="${app.buttonClass('primary')} mycic-btn-block">Buka di Notebook</a>`;
                            }
                        } catch (error) {
                            app.showNotice(`Belum bisa dimasukkan ke notebook. ${app.describeError(error)}`, 'error');
                        }
                    });
                });

                // Wire up preview toggles
                document.querySelectorAll('[data-toggle-preview]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const targetId = btn.getAttribute('data-toggle-preview');
                        const target = document.getElementById(targetId);
                        if (target) {
                            target.classList.toggle('hidden');
                        }
                    });
                });

                document.querySelectorAll('[data-close-preview]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const targetId = btn.getAttribute('data-close-preview');
                        const target = document.getElementById(targetId);
                        if (target) {
                            target.classList.add('hidden');
                        }
                    });
                });

                // Wire up file dropzone
                const fileInput = document.getElementById('assignment-file');
                const dropzoneEmpty = document.getElementById('dropzone-empty');
                const dropzoneSelected = document.getElementById('dropzone-selected');
                const selectedFileName = document.getElementById('selected-file-name');
                const selectedFileSize = document.getElementById('selected-file-size');

                if (fileInput) {
                    fileInput.addEventListener('change', () => {
                        const file = fileInput.files?.[0];
                        if (file) {
                            if (dropzoneEmpty) dropzoneEmpty.classList.add('hidden');
                            if (dropzoneSelected) dropzoneSelected.classList.remove('hidden');
                            if (selectedFileName) selectedFileName.textContent = file.name;
                            if (selectedFileSize) selectedFileSize.textContent = formatBytes(file.size);
                        } else {
                            if (dropzoneEmpty) dropzoneEmpty.classList.remove('hidden');
                            if (dropzoneSelected) dropzoneSelected.classList.add('hidden');
                        }
                    });
                }

                app.refreshOnlineRequiredState();

                // Attendance Form Listener
                const attendanceForm = document.getElementById('attendance-form');
                if (attendanceForm) {
                    app.bindAttendanceQrTools(attendanceForm, document.getElementById('attendance-code'), {
                        onDetected: () => {
                            app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                            app.requestFormSubmit(attendanceForm);
                        },
                    });
                    app.openQrScannerFromUrl(document.getElementById('attendance-code'));

                    attendanceForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Absen meeting butuh koneksi internet.', { queueable: true })) {
                            return;
                        }

                        const kode = document.getElementById('attendance-code').value.trim();

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
                }

                // Assignment Form Listener
                const assignmentForm = document.getElementById('assignment-form');
                if (assignmentForm) {
                    assignmentForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.', { queueable: true })) {
                            return;
                        }

                        const file = document.getElementById('assignment-file').files[0];

                        if (!file) {
                            app.showNotice('Pilih file PDF untuk tugas.', 'warning');
                            return;
                        }

                        const formData = new FormData();
                        formData.append('courses_id', assignmentParams.courses_id);
                        formData.append('id', assignmentParams.id);
                        formData.append('pertemuan', assignmentParams.pertemuan);
                        formData.append('attachment', file);

                        const note = document.getElementById('assignment-note').value.trim();
                        if (note) {
                            formData.append('form_values[keterangan]', note);
                        }

                        await app.guardFormSubmit(assignmentForm, () => app.withSubmitLoading(assignmentForm.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                // Offline session: the file waits in the outbox, not at the portal.
                                if (app.handleQueuedActionResult(result)) {
                                    assignmentForm.reset();

                                    return;
                                }

                                const operationId = result?.data?.operation_id;
                                if (operationId) {
                                    // Keep the form locked until the portal has the file;
                                    // on completion this meeting re-renders as submitted.
                                    await app.trackOperation(operationId, 'Upload tugas');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                    await loadMeeting();
                                }
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                }

                // Assignment Delete Listener
                const assignmentDeleteButton = document.getElementById('assignment-delete');
                if (assignmentDeleteButton) {
                    assignmentDeleteButton.addEventListener('click', async () => {
                        if (!app.requireOnlineAction('Hapus tugas butuh koneksi internet.')) {
                            return;
                        }

                        if (!window.confirm('Hapus tugas yang sudah diupload untuk meeting ini?')) {
                            return;
                        }

                        await app.withSubmitLoading(assignmentDeleteButton, async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'DELETE',
                                    json: { ...assignmentDeletePayload, courses_id: coursesId },
                                });

                                app.showNotice(result?.message || 'Tugas berhasil dihapus.', 'success');
                                app.clearCachedResponse('/api/assignment/pending');
                                await loadMeeting();
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        });
                    });
                }
            };

            app.primeCachedView(endpoint, render, 'detail meeting');

            const ready = await app.ensureAuthenticated();
            if (!ready) {
                return;
            }

            const renderNotFound = (message) => {
                document.getElementById('meeting-hero').innerHTML = `
                    <div class="mycic-hero">
                        <div class="flex flex-col gap-3">
                            <div><a href="/class" class="mycic-hero-pill">Kembali</a></div>
                            <h1 class="text-xl font-extrabold tracking-tight text-white sm:text-2xl">Pertemuan tidak ditemukan</h1>
                            <p class="text-sm text-white/80">${app.escapeHtml(message)}</p>
                        </div>
                    </div>
                `;
                document.getElementById('meeting-content').innerHTML = app.renderEmptyState(
                    'Tidak ada materi untuk link ini.',
                    'secondary',
                    'Buka halaman Kelas lalu pilih pertemuan dari daftar materi.'
                );
                document.getElementById('meeting-actions').innerHTML = `
                    <div class="mycic-card"><div class="mycic-card-body"><a href="/class" class="${app.buttonClass('primary')} mycic-btn-block">Ke daftar kelas</a></div></div>
                `;
            };

            const loadMeeting = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request(endpoint, { fresh: force });

                    // Snapshots built before the server learned to flag empty
                    // pages carry no `found`; judge those by their content.
                    const detail = payload?.class_detail || {};
                    const hasContent = [
                        detail.meeting_info?.breadcrumb,
                        detail.lecturer_info?.name,
                        detail.schedule_info?.date,
                        detail.materials?.main_topic,
                        detail.materials?.assignment_topic,
                        detail.attendance_info?.status,
                    ].some((value) => String(value || '').trim() !== '');

                    if (detail.found === false || !hasContent) {
                        renderNotFound('Pertemuan ini tidak ada di portal, atau link yang kamu buka sudah tidak berlaku.');

                        return;
                    }

                    render(payload);
                    app.markViewSynced('Detail meeting', payload, { force });
                } catch (error) {
                    if (error.status === 404) {
                        renderNotFound(app.describeError(error));

                        return;
                    }

                    app.setSyncStatus('Detail pertemuan gagal dimuat.', 'error');
                    app.mountErrorState(
                        document.getElementById('meeting-content'),
                        'Detail pertemuan gagal dimuat.',
                        () => loadMeeting({ force }),
                        { subtitle: app.describeError(error) }
                    );
                    document.getElementById('meeting-actions').innerHTML = '';
                }
            };

            app.registerSyncHandler('meeting-show', loadMeeting);

            await loadMeeting();
        });
    </script>
@endpush
