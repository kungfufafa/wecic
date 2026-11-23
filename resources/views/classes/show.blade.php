@extends('layouts.app')

@section('title', 'Detail Kelas')
@section('page_id', 'class-show')

{{--
    Detail Kelas: a loading header and KPI strip in #class-hero that the
    script replaces with the class's own (app.ui.pageHero…, app.ui.statGridKpi),
    then the meetings and materials beside a column with the attendance,
    the submitted assignments and Arsip & belajar.
--}}
@section('content')
    <div id="class-hero">
        <x-page-hero title="Detail kelas" back="{{ route('class.index') }}" back-label="Kembali" aria-busy="true">
            <span class="sr-only">Memuat detail kelas…</span>
            <x-skeleton class="mt-2 h-4 w-64 max-w-full" />
        </x-page-hero>
        <x-stat-strip kpi class="mb-6" aria-hidden="true">
            @for ($i = 0; $i < 4; $i++)
                <div class="flex min-w-0 flex-col gap-2 bg-panel p-4 sm:p-5">
                    <x-skeleton class="h-4 w-16" />
                    <x-skeleton class="h-7 w-12" />
                </div>
            @endfor
        </x-stat-strip>
    </div>

    {{-- Attendance or uploads queued for this class while offline: not at the portal yet. --}}
    <div class="mb-6 hidden" data-outbox-list data-outbox-match="class:{{ e($id) }}{{ $courseId ? ' course:'.e($courseId) : '' }}" data-outbox-heading="Antrean offline kelas ini"></div>

    <x-split :sticky="false">
        <div id="class-materials">
            <x-card aria-busy="true">
                <x-card.header>
                    <x-skeleton class="h-5 w-40" />
                </x-card.header>
                <x-card.body class="flex flex-col divide-y divide-border-light">
                    @for ($i = 0; $i < 4; $i++)
                        <x-list-row>
                            <div class="flex flex-col gap-1.5">
                                <x-skeleton class="h-4 w-28" />
                                <x-skeleton class="h-4 w-56" />
                            </div>
                            <x-skeleton class="h-8 w-16" />
                        </x-list-row>
                    @endfor
                </x-card.body>
            </x-card>
        </div>

        <x-slot:aside aria-label="Presensi dan tugas kelas">
            <div id="class-attendance">
                <x-card aria-busy="true">
                    <x-card.body class="flex flex-col gap-3">
                        <x-skeleton class="h-5 w-24" />
                        <x-skeleton class="h-20 w-full" />
                    </x-card.body>
                </x-card>
            </div>

            <div id="class-assignments">
                <x-card aria-busy="true">
                    <x-card.body class="flex flex-col gap-3">
                        <x-skeleton class="h-5 w-28" />
                        <x-skeleton class="h-14 w-full" />
                    </x-card.body>
                </x-card>
            </div>

            <x-card as="section" id="class-study" aria-labelledby="class-study-title">
                <x-card.body class="flex flex-col gap-4">
                    <h2 class="text-sm font-semibold text-ink" id="class-study-title">Arsip &amp; belajar</h2>
                    <div class="flex flex-col gap-2">
                        <x-button class="w-full" id="class-open-notebook">Buka Notebook</x-button>
                        <x-button variant="secondary" class="w-full" id="class-archive-materials" data-online-required>Arsipkan materi</x-button>
                    </div>
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const classId = @json($id);
            const dosenId = @json($dosenId);
            const courseId = @json($courseId);
            const studyContext = { name: null, courseId };

            // A KPI cell (app.ui.metric) of the divider strip (app.ui.statGridKpi).
            const renderMetric = (label, value, note = '') => `
                <div class="${app.ui.metric}" data-slot="metric">
                    <div class="${app.ui.metricLabel}" data-slot="metric-label">${app.escapeHtml(label)}</div>
                    <div class="${app.ui.metricValue}" data-slot="metric-value">${app.escapeHtml(value)}</div>
                    ${note ? `<div class="${app.ui.metricNote}" data-slot="metric-note">${app.escapeHtml(note)}</div>` : ''}
                </div>
            `;

            // An empty card section: quieter than the dashed empty box, which is for whole areas.
            const renderCardEmpty = (title, message, subtitle = '') => `
                <div class="${app.ui.card}" data-slot="card">
                    <div class="${app.ui.cardHeader}">
                        <h3 class="${app.ui.cardTitle}">${app.escapeHtml(title)}</h3>
                    </div>
                    <div class="${app.ui.cardBody} flex flex-col gap-1">
                        <p class="text-sm text-ink-muted">${app.escapeHtml(message)}</p>
                        ${subtitle ? `<p class="${app.ui.hint}">${app.escapeHtml(subtitle)}</p>` : ''}
                    </div>
                </div>
            `;

            const endpoint = () => {
                const query = new URLSearchParams();

                if (dosenId) {
                    query.set('dosen_id', dosenId);
                }

                if (courseId) {
                    query.set('course_id', courseId);
                }

                return `/api/class/${classId}${query.toString() ? `?${query.toString()}` : ''}`;
            };

            const render = (payload) => {
                const detail = payload.class_detail || {};
                const courseInfo = detail.course_info || {};
                studyContext.name = courseInfo.name || studyContext.name;
                studyContext.courseId = payload.meta?.parameters?.course_id || studyContext.courseId;
                const lecturerInfo = detail.lecturer_info || {};
                const attendance = detail.attendance_info || {};
                const assignments = detail.assignment_history || {};
                const materials = detail.course_materials || [];
                const hero = document.getElementById('class-hero');
                const attendanceMeetings = attendance.meetings || [];
                const attendanceSummary = attendance.summary || {};
                document.getElementById('class-study').classList.remove('hidden');

                const heroDescription = [
                    courseInfo.class_name,
                    courseInfo.credits ? `${courseInfo.credits} SKS` : null,
                    lecturerInfo.name,
                ].filter(Boolean).join(' · ');

                const submittedAssignments = (assignments.assignments || []).length;
                const presentCount = Number(attendanceSummary.present ?? 0);

                hero.innerHTML = `
                    <header class="${app.ui.pageHero}" data-slot="page-hero">
                        <a href="/class" class="${app.ui.backLink} mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6l6 6" /></svg>
                            <span>Kembali</span>
                        </a>
                        <div class="${app.ui.pageHeroRow}">
                            <div class="min-w-0">
                                <h1 class="${app.ui.pageHeroTitle}">${app.escapeHtml(courseInfo.name || 'Kelas')}</h1>
                                ${heroDescription ? `<p class="${app.ui.pageHeroSubtitle}">${app.escapeHtml(heroDescription)}</p>` : ''}
                            </div>
                        </div>
                    </header>
                    <div class="${app.ui.statGridKpi} mb-6" data-slot="stat-strip">
                        ${renderMetric('Materi', materials.length || '0')}
                        ${renderMetric('Presensi', attendanceSummary.total_meetings ?? attendanceMeetings.length ?? 0, 'pertemuan')}
                        ${renderMetric('Hadir', presentCount)}
                        ${renderMetric('Tugas terkumpul', submittedAssignments)}
                    </div>
                `;

                if (materials.length) {
                    document.getElementById('class-materials').innerHTML = `
                        <section class="${app.ui.card} overflow-hidden" data-slot="card" aria-labelledby="class-materials-title">
                            <div class="${app.ui.cardHeader}">
                                <div class="${app.ui.sectionTitleRow}">
                                    <h2 class="${app.ui.cardTitle}" id="class-materials-title">Pertemuan &amp; materi</h2>
                                    <span class="${app.ui.sectionCount}">${materials.length}</span>
                                </div>
                            </div>
                            <ul class="${app.ui.list}">
                                ${materials.map((material, index) => {
                                    const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                    const materialLabel = `P${material.meeting_number || index + 1}`;
                                    const rowBody = `
                                        <span class="w-8 shrink-0 text-xs font-medium text-ink-subtle tabular-nums" aria-hidden="true">${app.escapeHtml(materialLabel)}</span>
                                        <div class="${app.ui.listRowBody}">
                                            <h3 class="text-sm font-medium leading-snug text-ink"><span class="sr-only">${app.escapeHtml(materialLabel)}: </span>${app.escapeHtml(material.title || 'Materi')}</h3>
                                            <p class="${app.ui.listRowSubtitle} tabular-nums">${app.escapeHtml([material.date, material.time].filter(Boolean).join(' · ') || '-')}</p>
                                        </div>
                                    `;

                                    return meetingUrl
                                        ? `<li><a href="${meetingUrl}" class="${app.ui.listRowFlushInteractive}">${rowBody}${app.renderIcon('chevron-right', 'sm', app.ui.listRowChevron)}</a></li>`
                                        : `<li class="${app.ui.listRowFlush}">${rowBody}</li>`;
                                }).join('')}
                            </ul>
                        </section>
                    `;
                } else {
                    document.getElementById('class-materials').innerHTML = app.renderEmptyState('Belum ada materi.', 'secondary', '', { icon: 'file' });
                }

                if (attendance.summary || (attendance.meetings || []).length) {
                    const summary = attendance.summary || {};
                    const meetings = attendance.meetings || [];
                    const totalMeetings = Number(summary.total_meetings ?? meetings.length ?? 0);
                    const upcomingMeetings = Number(summary.upcoming ?? meetings.filter((meeting) => meeting.status === 'upcoming').length);
                    const heldMeetings = Number(summary.held_meetings ?? (totalMeetings - upcomingMeetings));
                    const presentMeetings = Number(summary.present ?? 0);
                    const absentMeetings = Number(summary.absent ?? 0);
                    const STATUS_BADGES = {
                        present: ['Hadir', 'success'],
                        late: ['Terlambat', 'warning'],
                        permit: ['Izin', 'info'],
                        sick: ['Sakit', 'info'],
                        absent: ['Absen', 'danger'],
                        upcoming: ['Belum berlangsung', 'neutral'],
                        unknown: ['Belum tercatat', 'neutral'],
                    };

                    document.getElementById('class-attendance').innerHTML = `
                        <div class="${app.ui.card} overflow-hidden" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Presensi</h3>
                            </div>
                            <div class="${app.ui.cardBody}">
                                ${app.renderInlineStats([
                                    { label: 'Berjalan', value: `${heldMeetings}/${totalMeetings}` },
                                    { label: 'Hadir', value: presentMeetings },
                                    { label: 'Absen', value: absentMeetings },
                                ])}
                                ${upcomingMeetings ? `<p class="mt-3 text-xs text-ink-subtle">${upcomingMeetings} pertemuan belum berlangsung.</p>` : ''}
                            </div>
                            ${meetings.length ? `
                                <div class="overflow-x-auto border-t border-border-light">
                                    <table class="${app.ui.table}">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="${app.ui.th}">#</th>
                                                <th scope="col" class="${app.ui.th}">Status</th>
                                                <th scope="col" class="${app.ui.th}">Tanggal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${meetings.map((meeting) => {
                                                const normalizedStatus = String(meeting.status || '').toLowerCase();
                                                const [statusLabel, statusTone] = STATUS_BADGES[normalizedStatus] || STATUS_BADGES.unknown;
                                                const details = meeting.details || {};
                                                const scheduleMeta = [details.attendance_date, details.attendance_time].filter(Boolean).join(' / ');

                                                return `
                                                    <tr class="${app.ui.tr}${normalizedStatus === 'upcoming' ? ' opacity-60' : ''}">
                                                        <td class="${app.ui.td} font-medium tabular-nums">P${app.escapeHtml(meeting.meeting_number)}</td>
                                                        <td class="${app.ui.td}">${app.renderBadge(statusLabel, statusTone)}</td>
                                                        <td class="${app.ui.td} whitespace-nowrap text-xs text-ink-subtle tabular-nums">${app.escapeHtml(scheduleMeta || '-')}</td>
                                                    </tr>
                                                `;
                                            }).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            ` : ''}
                        </div>
                    `;
                } else {
                    document.getElementById('class-attendance').innerHTML = renderCardEmpty('Presensi', 'Belum ada presensi.');
                }

                if ((assignments.assignments || []).length) {
                    document.getElementById('class-assignments').innerHTML = `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h3 class="${app.ui.cardTitle}">Tugas terkumpul</h3>
                            </div>
                            <div class="${app.ui.cardBody}">
                                ${(assignments.assignments || []).map((assignment) => {
                                    const fileInfo = assignment.file_info || {};
                                    const submissionUrl = app.absoluteCampusUrl(fileInfo.href);
                                    const scoreText = String(assignment.score ?? '').trim();
                                    // The portal shows 0 until the lecturer grades it.
                                    const isGraded = scoreText !== '' && !/belum/i.test(scoreText) && !/^0+([.,]0+)?$/.test(scoreText);

                                    return `
                                        <div class="${app.ui.listRow}">
                                            <div class="min-w-0 flex-1">
                                                <div class="mb-1 text-xs text-ink-subtle tabular-nums">${app.escapeHtml(assignment.date || '-')}</div>
                                                <div class="mb-2 text-sm font-medium leading-snug text-ink">${app.escapeHtml(assignment.meeting || `P${assignment.no}`)}</div>
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    ${isGraded
                                                        ? app.renderBadge(`Nilai ${scoreText}`, 'success')
                                                        : app.renderBadge('Belum dinilai', 'warning')}
                                                    ${fileInfo.text ? `<span class="min-w-0 truncate text-xs text-ink-subtle">${app.escapeHtml(fileInfo.text)}</span>` : ''}
                                                </div>
                                            </div>
                                            <div class="shrink-0">
                                                ${submissionUrl
                                                    ? `<a href="${app.escapeHtml(submissionUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('secondary', { small: true })}">File</a>`
                                                    : ''}
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    `;
                } else {
                    document.getElementById('class-assignments').innerHTML = renderCardEmpty('Tugas terkumpul', 'Belum ada tugas yang kamu kumpulkan.', 'Tugas yang belum dikumpulkan dan tenggatnya ada di halaman pertemuan.');
                }
            };

            app.primeCachedView(endpoint(), render, 'detail kelas');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadClassDetail = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(endpoint(), { fresh: force, background });
                    render(payload);
                    app.markViewSynced('Detail kelas', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Detail kelas gagal dimuat.', 'error');
                    app.mountErrorState(
                        document.getElementById('class-hero'),
                        error.status === 404 || error.status === 422 ? 'Kelas tidak ditemukan.' : 'Detail kelas gagal dimuat.',
                        error.status === 404 || error.status === 422 ? null : () => loadClassDetail({ force }),
                        { subtitle: error.status === 404 || error.status === 422 ? 'Link yang kamu buka tidak cocok dengan kelas mana pun di daftar kelasmu.' : app.describeError(error) }
                    );
                    ['class-materials', 'class-attendance', 'class-assignments'].forEach((id) => {
                        document.getElementById(id).innerHTML = '';
                    });
                    if (error.status === 404 || error.status === 422) {
                        document.getElementById('class-materials').innerHTML = `<a href="/class" class="${app.buttonClass('primary')}">Ke daftar kelas</a>`;
                        // No notebook or archive for a class that is not in the student's list.
                        document.getElementById('class-study').classList.add('hidden');
                    }
                }
            };

            const archiveButton = document.getElementById('class-archive-materials');
            const notebookButton = document.getElementById('class-open-notebook');

            archiveButton.addEventListener('click', () => {
                if (!app.requireOnlineAction('Mengarsipkan materi butuh koneksi ke portal.')) {
                    return;
                }

                app.withSubmitLoading(archiveButton, async () => {
                    try {
                        const payload = await app.request('/api/archive/files/sync', { method: 'POST', json: { id: Number(classId) } });

                        app.showNotice(payload?.message || 'Materi kelas sedang diarsipkan.', 'success');
                    } catch (error) {
                        app.showNotice(`Materi belum bisa diarsipkan. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            notebookButton.addEventListener('click', () => {
                if (!studyContext.courseId) {
                    app.showNotice('Mata kuliah kelas ini belum dikenal. Muat ulang halaman lalu coba lagi.', 'warning');

                    return;
                }

                app.withSubmitLoading(notebookButton, async () => {
                    try {
                        const payload = await app.request('/api/notebooks/course', {
                            method: 'POST',
                            json: { course_key: String(studyContext.courseId) },
                        });
                        const notebook = payload?.data?.notebook;

                        if (notebook?.id) {
                            if (!notebook.meetings_count) {
                                app.storeNotice('Ini notebook mata kuliahmu. Tambahkan pertemuan agar catatan dan materinya masuk.', 'info');
                            }

                            window.location.href = `/notebook/${notebook.id}`;
                        }
                    } catch (error) {
                        app.showNotice(`Notebook gagal dibuka. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            app.registerSyncHandler('class-show', loadClassDetail);

            // Snapshot first (instant); a stale one refreshes in the background.
            await loadClassDetail();
        });
    </script>
@endpush
