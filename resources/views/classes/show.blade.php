@extends('layouts.app')

@section('title', 'Detail Kelas')
@section('page_id', 'class-show')

@section('content')
    <div id="class-hero">
        {!! '<div class="mycic-alert-neutral mb-4">Memuat...</div>' !!}
    </div>

    {{-- Attendance or uploads queued for this class while offline: not at the portal yet. --}}
    <div class="mb-4 hidden" data-outbox-list data-outbox-match="class:{{ e($id) }}{{ $courseId ? ' course:'.e($courseId) : '' }}" data-outbox-heading="Antrean offline kelas ini"></div>

    <div class="grid gap-4 lg:grid-cols-12">
        <div class="lg:col-span-8" id="class-materials">
            {!! '<div class="mycic-alert-neutral mb-0">Memuat...</div>' !!}
        </div>

        <div class="flex flex-col gap-4 lg:col-span-4">
            <div id="class-attendance">
                {!! '<div class="mycic-alert-neutral mb-0">Memuat...</div>' !!}
            </div>

            <div id="class-assignments">
                {!! '<div class="mycic-alert-neutral mb-0">Memuat...</div>' !!}
            </div>

            <section class="mycic-card" aria-labelledby="class-study-title">
                <div class="mycic-card-header">
                    <h2 class="mycic-card-title" id="class-study-title">Arsip & belajar</h2>
                    <p class="mt-0.5 text-caption text-ink-subtle">Setiap mata kuliah punya notebook sendiri. Pertemuan yang kamu buka masuk otomatis; dari notebook kamu bisa belajar mandiri atau menyusun kerangka tugas dari materinya.</p>
                </div>
                <div class="mycic-card-body flex flex-col gap-2">
                    <button type="button" class="mycic-btn-primary" id="class-open-notebook">Buka notebook mata kuliah ini</button>
                    <button type="button" class="mycic-btn-secondary" id="class-archive-materials" data-online-required>Arsipkan semua materi</button>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const classId = @json($id);
            const dosenId = @json($dosenId);
            const courseId = @json($courseId);
            const studyContext = { name: null, courseId };

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

                hero.innerHTML = `
                    <div class="mb-4">
                        ${app.renderPageHeader({
                            title: courseInfo.name || 'Kelas',
                            description: [
                                courseInfo.class_name,
                                courseInfo.credits ? `${courseInfo.credits} SKS` : null,
                                lecturerInfo.name,
                            ].filter(Boolean).join(' · '),
                            actions: [
                                `<a href="/class" class="${app.buttonClass('secondary')}">Kembali</a>`,
                            ],
                        })}
                    </div>
                    <div class="mb-4 grid grid-cols-2 gap-3">
                        <div class="mycic-metric">
                            <div class="mycic-metric-label">Materi</div>
                            <div class="mycic-metric-value">${materials.length || '0'}</div>
                        </div>
                        <div class="mycic-metric">
                            <div class="mycic-metric-label">Presensi</div>
                            <div class="mycic-metric-value">${attendanceSummary.total_meetings ?? attendanceMeetings.length ?? 0}</div>
                        </div>
                    </div>
                `;

                if (materials.length) {
                    document.getElementById('class-materials').innerHTML = `
                        <div class="mb-4 flex flex-col gap-3">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const materialLabel = `P${material.meeting_number || index + 1}`;

                                return `
                                    <div class="mycic-card">
                                        <div class="mycic-card-body">
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                                <div class="min-w-0 flex-1">
                                                    <div class="mb-1 flex items-center gap-2">
                                                        ${app.renderBadge(materialLabel, 'brand')}
                                                        <span class="text-caption text-ink-subtle">${app.escapeHtml([material.date, material.time].filter(Boolean).join(' · ') || '-')}</span>
                                                    </div>
                                                    <h3 class="text-subtitle font-bold text-ink">${app.escapeHtml(material.title || 'Materi')}</h3>
                                                </div>
                                                <div class="shrink-0">
                                                    ${meetingUrl ? `<a href="${meetingUrl}" class="${app.buttonClass('secondary', { small: true })}">Buka</a>` : ''}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `;
                } else {
                    document.getElementById('class-materials').innerHTML = app.renderEmptyState('Belum ada materi.', 'secondary');
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
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Presensi</h3>
                            </div>
                            <div class="mycic-card-body pb-0">
                                <div class="mb-3">
                                    ${app.renderInlineStats([
                                        { label: 'Berjalan', value: `${heldMeetings}/${totalMeetings}` },
                                        { label: 'Hadir', value: presentMeetings },
                                        { label: 'Absen', value: absentMeetings },
                                    ])}
                                    ${upcomingMeetings ? `<p class="mt-2 text-caption text-ink-subtle">${upcomingMeetings} pertemuan belum berlangsung.</p>` : ''}
                                </div>
                            </div>
                            ${meetings.length ? `
                                <div class="mycic-table-wrap">
                                    <table class="mycic-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Status</th>
                                                <th>Tanggal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${meetings.map((meeting) => {
                                                const normalizedStatus = String(meeting.status || '').toLowerCase();
                                                const [statusLabel, statusTone] = STATUS_BADGES[normalizedStatus] || STATUS_BADGES.unknown;
                                                const details = meeting.details || {};
                                                const scheduleMeta = [details.attendance_date, details.attendance_time].filter(Boolean).join(' / ');

                                                return `
                                                    <tr class="${normalizedStatus === 'upcoming' ? 'opacity-60' : ''}">
                                                        <td>P${app.escapeHtml(meeting.meeting_number)}</td>
                                                        <td>${app.renderBadge(statusLabel, statusTone)}</td>
                                                        <td class="text-caption text-ink-subtle">${app.escapeHtml(scheduleMeta || '-')}</td>
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
                    document.getElementById('class-attendance').innerHTML = `
                        <div class="mycic-card">
                            <div class="mycic-card-body">
                                ${app.renderEmptyState('Belum ada presensi.', 'secondary')}
                            </div>
                        </div>
                    `;
                }

                if ((assignments.assignments || []).length) {
                    document.getElementById('class-assignments').innerHTML = `
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Tugas</h3>
                            </div>
                            <div class="mycic-card-body">
                                ${(assignments.assignments || []).map((assignment) => {
                                    const fileInfo = assignment.file_info || {};
                                    const submissionUrl = app.absoluteCampusUrl(fileInfo.href);
                                    const scoreText = String(assignment.score ?? '').trim();
                                    // The portal shows 0 until the lecturer grades it.
                                    const isGraded = scoreText !== '' && !/belum/i.test(scoreText) && !/^0+([.,]0+)?$/.test(scoreText);

                                    return `
                                        <div class="mycic-list-row">
                                            <div class="min-w-0 flex-1">
                                                <div class="mb-1 text-caption text-ink-subtle">${app.escapeHtml(assignment.date || '-')}</div>
                                                <div class="mb-2 font-medium text-ink">${app.escapeHtml(assignment.meeting || `P${assignment.no}`)}</div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    ${isGraded
                                                        ? app.renderBadge(`Nilai ${scoreText}`, 'success')
                                                        : app.renderBadge('Belum dinilai', 'warning')}
                                                    ${fileInfo.text ? app.renderBadge(fileInfo.text, 'neutral') : ''}
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
                    document.getElementById('class-assignments').innerHTML = `
                        <div class="mycic-card">
                            <div class="mycic-card-body">
                                ${app.renderEmptyState('Belum ada tugas.', 'secondary')}
                            </div>
                        </div>
                    `;
                }
            };

            app.primeCachedView(endpoint(), render, 'detail kelas');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadClassDetail = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request(endpoint(), { fresh: force });
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
