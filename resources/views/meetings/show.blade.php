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
                <div class="grid grid-cols-2 gap-2 pt-2 sm:grid-cols-4">
                    <div class="h-16 rounded-lg bg-white/10 p-2.5 backdrop-blur-sm"></div>
                    <div class="h-16 rounded-lg bg-white/10 p-2.5 backdrop-blur-sm"></div>
                    <div class="h-16 rounded-lg bg-white/10 p-2.5 backdrop-blur-sm"></div>
                    <div class="h-16 rounded-lg bg-white/10 p-2.5 backdrop-blur-sm"></div>
                </div>
            </div>
        </div>
    </div>

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

        <div class="space-y-5 lg:col-span-4" id="meeting-actions">
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
                app.showNotice('Parameter courses_id wajib ada untuk memuat meeting.', 'warning');

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
                const inferredMeetingNumber = inferMeetingNumber(materials);

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
                const rpsInfo = detail.rps_info || {};
                const conformityInfo = detail.conformity_info || {};
                const instructions = detail.instructions || {};
                const assignmentParams = deriveAssignmentParams(assignmentInfo, materials);
                const assignmentSubmission = assignmentInfo.submission || null;
                const assignmentFileInfo = assignmentSubmission?.file_info || null;
                const assignmentDeletePayload = assignmentInfo.delete_payload || null;
                const assignmentAlreadyUploaded = assignmentInfo.status === 'uploaded' && assignmentFileInfo;
                const inferredMeetingNumber = assignmentParams?.pertemuan
                    || inferMeetingNumber(materials)
                    || (meetingInfo.breadcrumb?.match(/Pertemuan\s+(\d+)/i)?.[1])
                    || '';
                const attendanceStatusText = String(attendanceInfo.status || '').trim();
                const attendanceRecorded = Boolean(attendanceInfo.time)
                    || (/hadir|terlambat|izin|sakit/i.test(attendanceStatusText) && !/belum|silakan|scan|qr/i.test(attendanceStatusText));

                const courseName = extractCourseName(meetingInfo.breadcrumb) || 'Mata Kuliah';
                const mainTopicClean = cleanTopic(materials.main_topic || materials.assignment_topic) || 'Pembahasan Pertemuan';
                const meetingLabel = inferredMeetingNumber ? `Pertemuan ${inferredMeetingNumber}` : 'Pertemuan Kelas';

                // Render Hero
                const hero = document.getElementById('meeting-hero');
                const safeLecturer = app.escapeHtml(lecturerInfo.name || '-');
                const safeDate = app.escapeHtml(scheduleInfo.date || '-');
                const safeTime = app.escapeHtml(scheduleInfo.start_time || '-');
                const safeAttendanceStatus = app.escapeHtml(attendanceStatusText || (attendanceRecorded ? 'Hadir' : 'Belum Absen'));

                let attendanceBadgeMarkup = '';
                if (attendanceRecorded) {
                    attendanceBadgeMarkup = `
                        <div class="inline-flex items-center gap-1.5 rounded-full border border-emerald-400/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-emerald-200 backdrop-blur-sm">
                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                            <span>Presensi Tercatat</span>
                        </div>
                    `;
                } else {
                    attendanceBadgeMarkup = `
                        <div class="inline-flex items-center gap-1.5 rounded-full border border-amber-400/30 bg-amber-500/20 px-3 py-1 text-xs font-semibold text-amber-200 backdrop-blur-sm">
                            <span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                            <span>Presensi Terbuka</span>
                        </div>
                    `;
                }

                hero.innerHTML = `
                    <div class="mycic-hero shadow-lg">
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="/class" class="mycic-hero-pill inline-flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                                        <span>Kembali</span>
                                    </a>
                                    <span class="inline-flex items-center gap-1 rounded-lg border border-white/20 bg-white/10 px-3 py-1 text-xs font-medium text-white/90 backdrop-blur-sm">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-accent" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                        <span class="font-semibold">${app.escapeHtml(courseName)}</span>
                                    </span>
                                    <span class="inline-flex items-center rounded-lg bg-accent px-2.5 py-1 text-xs font-bold text-slate-900 shadow-xs">
                                        ${app.escapeHtml(meetingLabel)}
                                    </span>
                                </div>
                                <div>
                                    ${attendanceBadgeMarkup}
                                </div>
                            </div>

                            <div class="py-1">
                                <div class="text-xs font-medium uppercase tracking-wider text-white/70">Topik Perkuliahan</div>
                                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-white sm:text-3xl lg:text-4xl">
                                    ${app.escapeHtml(mainTopicClean)}
                                </h1>
                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/80">
                                    <div class="inline-flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <span>${safeLecturer}</span>
                                    </div>
                                    <span class="text-white/40">•</span>
                                    <div class="inline-flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <span>${safeDate}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 sm:grid-cols-4">
                                <div class="rounded-xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm transition-colors hover:bg-white/15">
                                    <div class="text-[0.7rem] font-medium uppercase tracking-wide text-white/70">Tanggal</div>
                                    <div class="mt-1 truncate text-sm font-bold text-white sm:text-base">${safeDate}</div>
                                </div>
                                <div class="rounded-xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm transition-colors hover:bg-white/15">
                                    <div class="text-[0.7rem] font-medium uppercase tracking-wide text-white/70">Waktu Mulai</div>
                                    <div class="mt-1 truncate text-sm font-bold text-white sm:text-base">${safeTime}</div>
                                </div>
                                <div class="rounded-xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm transition-colors hover:bg-white/15">
                                    <div class="text-[0.7rem] font-medium uppercase tracking-wide text-white/70">Dosen Pengampu</div>
                                    <div class="mt-1 truncate text-sm font-bold text-white sm:text-base">${safeLecturer}</div>
                                </div>
                                <div class="rounded-xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm transition-colors hover:bg-white/15">
                                    <div class="text-[0.7rem] font-medium uppercase tracking-wide text-white/70">Status Presensi</div>
                                    <div class="mt-1 truncate text-sm font-bold sm:text-base ${attendanceRecorded ? 'text-emerald-300' : 'text-amber-300'}">
                                        ${attendanceRecorded ? (attendanceInfo.time ? `Hadir (${app.escapeHtml(attendanceInfo.time)})` : 'Hadir') : 'Belum Absen'}
                                    </div>
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
                            <div class="mt-4">
                                <div class="mb-2.5 text-xs font-bold uppercase tracking-wider text-ink-subtle">
                                    Pokok Pembahasan & Capaian:
                                </div>
                                <div class="grid gap-2">
                                    ${outlineItems.map(item => `
                                        <div class="mycic-outline-item">
                                            <span class="mycic-outline-badge">${app.escapeHtml(item.num || '•')}</span>
                                            <span class="min-w-0 flex-1 text-sm font-medium text-ink">${app.escapeHtml(item.text)}</span>
                                        </div>
                                    `).join('')}
                                </div>
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
                            <div class="mt-5 rounded-xl border border-border bg-panel p-4 shadow-xs">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-danger-soft text-danger">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-bold text-ink">Modul Perkuliahan (PDF)</div>
                                            <div class="text-caption text-ink-subtle">Materi pembelajaran resmi dari dosen</div>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary')}" data-toggle-preview="preview-main-modul">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <span>Pratinjau</span>
                                        </button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary')}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                            <span>Buka Tab Baru</span>
                                        </a>
                                        ${downloadUrl ? `
                                            <a href="${downloadUrl}" class="${app.buttonClass('primary')}">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                <span>Download</span>
                                            </a>
                                        ` : ''}
                                    </div>
                                </div>
                                <div id="preview-main-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-border bg-surface px-3 py-2 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau Modul Perkuliahan</span>
                                        <button type="button" class="text-ink-subtle hover:text-ink font-semibold" data-close-preview="preview-main-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between border-b border-border-light pb-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                    </div>
                                    <div>
                                        <h2 class="text-subtitle font-bold text-ink">Materi Perkuliahan</h2>
                                        <div class="text-caption text-ink-subtle">Bahan ajar dan materi pokok</div>
                                    </div>
                                </div>
                                <div>
                                    ${app.renderBadge('Modul Utama', 'brand')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <div class="text-base font-bold text-ink">
                                    ${app.escapeHtml(mainTopicClean)}
                                </div>
                                ${outlineMarkup}
                                ${mainResourceMarkup}
                            </div>
                        </div>
                    `);
                }

                // 2. Tugas Perkuliahan
                if (materials.assignment_topic || materials.assignment_instructions || materials.assignment_material_iframe || assignmentInfo.upload_text) {
                    const assignmentTitle = app.escapeHtml(materials.assignment_topic || assignmentInfo.upload_text || 'Instruksi Tugas Perkuliahan');
                    const deadlineText = materials.assignment_instructions || assignmentInfo.deadline;
                    let assignmentResourceMarkup = '';

                    if (materials.assignment_material_iframe) {
                        const fileUrl = app.absoluteCampusUrl(materials.assignment_material_iframe);
                        const downloadUrl = app.buildCampusDownloadUrl(materials.assignment_material_iframe);

                        assignmentResourceMarkup = `
                            <div class="mt-4 rounded-xl border border-border bg-panel p-4 shadow-xs">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-warning-soft text-warning">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-bold text-ink">Lembar Soal / Panduan Tugas (PDF)</div>
                                            <div class="text-caption text-ink-subtle">Berkas instruksi pengerjaan tugas</div>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary')}" data-toggle-preview="preview-assignment-modul">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <span>Pratinjau</span>
                                        </button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary')}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                            <span>Buka Tab Baru</span>
                                        </a>
                                        ${downloadUrl ? `
                                            <a href="${downloadUrl}" class="${app.buttonClass('primary')}">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                <span>Download</span>
                                            </a>
                                        ` : ''}
                                    </div>
                                </div>
                                <div id="preview-assignment-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-border bg-surface px-3 py-2 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau Berkas Tugas</span>
                                        <button type="button" class="text-ink-subtle hover:text-ink font-semibold" data-close-preview="preview-assignment-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between border-b border-border-light pb-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-warning-soft text-warning">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                                    </div>
                                    <div>
                                        <h2 class="text-subtitle font-bold text-ink">Tugas Perkuliahan</h2>
                                        <div class="text-caption text-ink-subtle">Instruksi dan lembar kerja</div>
                                    </div>
                                </div>
                                <div>
                                    ${app.renderBadge('Wajib Mahasiswa', 'warning')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <div class="text-base font-bold text-ink">
                                    ${assignmentTitle}
                                </div>

                                ${deadlineText && deadlineText !== ':' ? `
                                    <div class="mt-3 flex items-center gap-3 rounded-xl border border-warning/30 bg-warning-soft/60 p-3.5 text-ink">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-warning" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <div class="text-sm">
                                            <span class="text-ink-subtle">Batas Pengumpulan:</span>
                                            <span class="font-bold text-warning">${app.escapeHtml(deadlineText)}</span>
                                        </div>
                                    </div>
                                ` : ''}

                                ${assignmentResourceMarkup}
                            </div>
                        </div>
                    `);
                }

                // 3. RPS (Rencana Pembelajaran Semester)
                if (rpsInfo.file_link) {
                    const rpsUrl = app.absoluteCampusUrl(rpsInfo.file_link);
                    const rpsDownload = app.buildCampusDownloadUrl(rpsInfo.file_link);

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between border-b border-border-light pb-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                    </div>
                                    <div>
                                        <h2 class="text-subtitle font-bold text-ink">Rencana Pembelajaran Semester (RPS)</h2>
                                        <div class="text-caption text-ink-subtle">Silabus dan pedoman capaian mata kuliah</div>
                                    </div>
                                </div>
                                <div>
                                    ${app.renderBadge('Kurikulum', 'neutral')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-sm text-ink-muted">
                                        Dokumen RPS berisi panduan materi, referensi, dan indikator penilaian semester ini.
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                                        <a href="${rpsUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary')}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                            <span>Buka Dokumen RPS</span>
                                        </a>
                                        ${rpsDownload ? `
                                            <a href="${rpsDownload}" class="${app.buttonClass('primary')}">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                <span>Download RPS</span>
                                            </a>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `);
                }

                // 4. Kesesuaian Materi dengan RPS (Feedback)
                if (conformityInfo.yes_button || conformityInfo.no_button) {
                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header flex items-center justify-between border-b border-border-light pb-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                    </div>
                                    <div>
                                        <h2 class="text-subtitle font-bold text-ink">Evaluasi Kesesuaian Materi</h2>
                                        <div class="text-caption text-ink-subtle">Konfirmasi kesesuaian dengan RPS</div>
                                    </div>
                                </div>
                                <div>
                                    ${app.renderBadge('Survei Kuliah', 'neutral')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <p class="text-sm text-ink-muted">
                                    ${app.escapeHtml(instructions.conformity_note || conformityInfo.description || 'Apakah materi yang disampaikan pada pertemuan ini sesuai dengan Rencana Pembelajaran Semester (RPS)?')}
                                </p>
                                <div class="mt-4 flex flex-wrap gap-2.5">
                                    ${conformityInfo.yes_button ? `
                                        <a href="${app.absoluteCampusUrl(conformityInfo.yes_button.href)}" target="_blank" rel="noreferrer" class="${app.buttonClass('primary')} inline-flex items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
                                            <span>${app.escapeHtml(conformityInfo.yes_button.text || 'Materi Sesuai Rencana')}</span>
                                        </a>
                                    ` : ''}
                                    ${conformityInfo.no_button ? `
                                        <a href="${app.absoluteCampusUrl(conformityInfo.no_button.href)}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary')} inline-flex items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                                            <span>${app.escapeHtml(conformityInfo.no_button.text || 'Materi Tidak Sesuai Rencana')}</span>
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `);
                }

                // Render to meeting-content
                const contentContainer = document.getElementById('meeting-content');
                if (contentSections.length > 0) {
                    contentContainer.innerHTML = `<div class="space-y-5">${contentSections.join('')}</div>`;
                } else {
                    contentContainer.innerHTML = app.renderEmptyState('Belum ada materi atau instruksi tambahan pada pertemuan ini.', 'secondary');
                }

                // Render Actions Sidebar
                const actionsContainer = document.getElementById('meeting-actions');

                let attendanceSectionMarkup = '';
                if (attendanceRecorded) {
                    attendanceSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header border-b border-border-light pb-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="mycic-card-title">Presensi Kehadiran</h3>
                                    ${app.renderBadge('Terverifikasi', 'success')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <div class="flex flex-col items-center rounded-xl border border-success/30 bg-success-soft/60 p-5 text-center">
                                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-600 shadow-inner">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    </div>
                                    <div class="mt-3 text-base font-bold text-ink">Presensi Berhasil Dicatat</div>
                                    <div class="mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                        ${safeAttendanceStatus}
                                    </div>
                                    ${attendanceInfo.time ? `
                                        <div class="mt-1 text-xs text-ink-subtle">
                                            Waktu: ${app.escapeHtml(attendanceInfo.time)} WIB
                                        </div>
                                    ` : ''}
                                </div>
                                <div class="mt-3 text-center text-xs text-ink-subtle">
                                    Data kehadiran telah tersinkronisasi dengan portal akademik SiPANDAI.
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    attendanceSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header border-b border-border-light pb-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="mycic-card-title">Presensi Pertemuan</h3>
                                    ${app.renderBadge('Wajib Diisi', 'warning')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <form id="attendance-form" class="space-y-4">
                                    <div class="rounded-xl border border-warning/20 bg-warning-soft/50 p-3 text-xs leading-relaxed text-ink">
                                        Masukkan kode absensi atau scan kode QR yang diberikan oleh dosen pengampu.
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        <button type="button" class="${app.buttonClass('secondary')} flex items-center justify-center gap-1.5 py-2.5 text-xs font-bold" data-qr-scan>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                            <span>Scan Kamera</span>
                                        </button>
                                        <label class="${app.buttonClass('secondary')} flex cursor-pointer items-center justify-center gap-1.5 py-2.5 text-xs font-bold">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                            <span>Upload QR</span>
                                            <input type="file" class="sr-only" data-qr-file accept="image/*" title="Pilih gambar QR">
                                        </label>
                                    </div>

                                    <div class="relative flex items-center py-1">
                                        <div class="flex-grow border-t border-border-light"></div>
                                        <span class="flex-shrink mx-2 text-[0.7rem] font-semibold uppercase tracking-wider text-ink-subtle">Atau ketik manual</span>
                                        <div class="flex-grow border-t border-border-light"></div>
                                    </div>

                                    <div>
                                        <label for="attendance-code" class="mycic-label mb-1.5 block">Kode Absen / QR</label>
                                        <input id="attendance-code" type="text" maxlength="20" class="mycic-input py-2.5 text-center font-mono text-base font-bold uppercase tracking-widest" placeholder="Contoh: 8A2B9C1D" required>
                                    </div>

                                    <div class="pt-1">
                                        <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block py-2.5 font-bold shadow-xs" data-online-required>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                            <span>Kirim Absensi Sekarang</span>
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
                            <div class="mycic-card-header border-b border-border-light pb-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="mycic-card-title">Status Tugas</h3>
                                    ${app.renderBadge('Sudah Terkirim', 'success')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <div class="rounded-xl border border-success/30 bg-success-soft/60 p-4">
                                    <div class="flex items-center gap-2.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-success" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                        <div class="text-sm font-bold text-ink">Tugas berhasil diunggah</div>
                                    </div>
                                    ${assignmentSubmission?.date ? `
                                        <div class="mt-1 text-xs text-ink-subtle">
                                            Diupload pada: ${app.escapeHtml(assignmentSubmission.date)}
                                        </div>
                                    ` : ''}
                                </div>

                                ${assignmentSubmission?.score ? `
                                    <div class="mt-3 flex items-center justify-between rounded-lg border border-accent/30 bg-accent-soft p-3">
                                        <span class="text-xs font-medium text-amber-900 dark:text-amber-200">Nilai Tugas:</span>
                                        <span class="text-base font-extrabold text-amber-900 dark:text-amber-200">${app.escapeHtml(assignmentSubmission.score)}</span>
                                    </div>
                                ` : ''}

                                <div class="mt-4 flex flex-col gap-2">
                                    <a href="${app.absoluteCampusUrl(assignmentFileInfo.href)}" target="_blank" rel="noreferrer" class="${app.buttonClass('primary')} mycic-btn-block">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <span>Lihat Berkas Tugas</span>
                                    </a>
                                    ${app.buildCampusDownloadUrl(assignmentFileInfo.href) ? `
                                        <a href="${app.buildCampusDownloadUrl(assignmentFileInfo.href)}" class="${app.buttonClass('secondary')} mycic-btn-block">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                            <span>Download Tugas</span>
                                        </a>
                                    ` : ''}
                                    ${assignmentDeletePayload ? `
                                        <button type="button" class="${app.buttonClass('destructive')} mycic-btn-block mt-1" id="assignment-delete" data-online-required>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            <span>Hapus Tugas</span>
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                } else if (assignmentParams) {
                    assignmentSectionMarkup = `
                        <div class="mycic-card">
                            <div class="mycic-card-header border-b border-border-light pb-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="mycic-card-title">Upload Tugas</h3>
                                    ${app.renderBadge('PDF Wajib', 'warning')}
                                </div>
                            </div>
                            <div class="mycic-card-body pt-4">
                                <form id="assignment-form" class="space-y-4">
                                    <div>
                                        <label class="mycic-label mb-1.5 block">File Tugas (PDF)</label>
                                        <label for="assignment-file" class="mycic-dropzone" id="assignment-dropzone">
                                            <div id="dropzone-empty" class="flex flex-col items-center">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-surface-tint text-brand-pressed">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                                </div>
                                                <div class="mt-2 text-xs font-semibold text-ink">Klik untuk memilih file PDF</div>
                                                <div class="text-[0.7rem] text-ink-subtle">Format .pdf (Maks. 10MB)</div>
                                            </div>
                                            <div id="dropzone-selected" class="hidden flex flex-col items-center">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-danger-soft text-danger">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                </div>
                                                <div class="mt-2 truncate max-w-full text-xs font-bold text-ink" id="selected-file-name">file.pdf</div>
                                                <div class="text-[0.7rem] text-ink-subtle" id="selected-file-size">0 KB</div>
                                                <div class="mt-1 text-[0.68rem] text-brand-pressed underline">Klik untuk ganti file</div>
                                            </div>
                                            <input id="assignment-file" type="file" accept=".pdf" class="sr-only" required>
                                        </label>
                                    </div>

                                    <div>
                                        <label for="assignment-note" class="mycic-label mb-1.5 block">Keterangan Tambahan (Opsional)</label>
                                        <textarea id="assignment-note" class="mycic-input" rows="2" placeholder="Catatan untuk dosen..."></textarea>
                                    </div>

                                    <div class="pt-1">
                                        <button type="submit" class="${app.buttonClass('primary')} mycic-btn-block py-2.5 font-bold shadow-xs" data-online-required>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                            <span>Kirim Tugas</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    `;
                }

                actionsContainer.innerHTML = `
                    <div class="space-y-5">
                        ${attendanceSectionMarkup}
                        ${assignmentSectionMarkup}
                    </div>
                `;

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

                    attendanceForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (attendanceForm.dataset.submitting === 'true') {
                            return;
                        }

                        if (!app.requireOnlineAction('Absen meeting butuh koneksi internet.')) {
                            return;
                        }

                        const kode = document.getElementById('attendance-code').value.trim();

                        await app.withSubmitLoading(attendanceForm.querySelector('button[type="submit"]'), async () => {
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

                                app.showNotice(result?.message || 'Absensi berhasil dikirim.', 'success');
                                app.clearCachedResponse('/api/dashboard');
                                app.clearCachedResponse('/api/attendance/today');
                                app.clearCachedResponse(endpoint);
                                await loadMeeting({ force: true });
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        });
                    });
                }

                // Assignment Form Listener
                const assignmentForm = document.getElementById('assignment-form');
                if (assignmentForm) {
                    assignmentForm.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.')) {
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

                        await app.withSubmitLoading(assignmentForm.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                const operationId = result?.data?.operation_id;
                                if (operationId) {
                                    app.trackOperation(operationId, 'Upload tugas');
                                    app.showNotice('Upload tugas diterima. Status akan diperbarui otomatis.', 'info');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                }
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        });
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
                                    json: assignmentDeletePayload,
                                });

                                app.showNotice(result?.message || 'Tugas berhasil dihapus.', 'success');
                                await loadMeeting({ force: true });
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

            const loadMeeting = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request(endpoint, { fresh: force });
                    render(payload);
                    app.markViewSynced('Detail meeting', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('meeting-show', loadMeeting);

            await loadMeeting();
        });
    </script>
@endpush
