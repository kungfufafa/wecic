@extends('layouts.app')

@section('title', 'Detail Kelas')
@section('page_id', 'class-show')

@section('content')
    <div id="class-hero">
        {!! '<div class="mycic-alert-neutral mb-4">Menunggu ringkasan kelas.</div>' !!}
    </div>

    <div class="grid gap-4 lg:grid-cols-12">
        <div class="lg:col-span-8" id="class-materials">
            {!! '<div class="mycic-alert-neutral mb-0">Menunggu materi kelas.</div>' !!}
        </div>

        <div class="flex flex-col gap-4 lg:col-span-4">
            <div id="class-attendance">
                {!! '<div class="mycic-alert-neutral mb-0">Belum ada data presensi.</div>' !!}
            </div>

            <div id="class-assignments">
                {!! '<div class="mycic-alert-neutral mb-0">Belum ada histori tugas.</div>' !!}
            </div>
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
                            pretitle: 'Detail kelas',
                            title: courseInfo.name || 'Informasi kelas',
                            description: [
                                courseInfo.class_name,
                                courseInfo.credits ? `${courseInfo.credits} SKS` : null,
                                lecturerInfo.name,
                            ].filter(Boolean).join(' · ') || 'Lihat struktur kelas, materi, presensi, dan riwayat tugas dalam satu halaman.',
                            actions: [
                                `<a href="/class" class="${app.buttonClass('secondary')}">Kembali ke daftar</a>`,
                            ],
                        })}
                    </div>
                    ${app.renderStatCards([
                        { label: 'Dosen', value: lecturerInfo.name || '-', note: 'Pengampu utama kelas ini.' },
                        { label: 'SKS', value: courseInfo.credits || '-', note: 'Bobot kredit mata kuliah.' },
                        { label: 'Materi', value: materials.length || '0', note: 'Materi yang terdeteksi saat ini.' },
                        { label: 'Presensi', value: attendanceSummary.total_meetings ?? attendanceMeetings.length ?? 0, note: 'Pertemuan yang tercatat di portal.' },
                    ])}
                `;

                if (materials.length) {
                    document.getElementById('class-materials').innerHTML = `
                        <div class="mb-4 flex flex-col gap-3">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const materialLabel = material.meeting_number ? `Pertemuan ${material.meeting_number}` : `Pertemuan ${index + 1}`;

                                return `
                                    <div class="mycic-card">
                                        <div class="mycic-card-body">
                                            <div class="flex flex-col gap-3 md:flex-row md:items-center">
                                                <div class="min-w-0 flex-1">
                                                    <div class="mb-2 flex items-center gap-2">
                                                        ${app.renderBadge(materialLabel, 'brand')}
                                                        <span class="text-caption text-ink-subtle">${app.escapeHtml([material.date, material.time].filter(Boolean).join(' • ') || 'Jadwal belum ditentukan')}</span>
                                                    </div>
                                                    <h3 class="mb-1 text-subtitle font-bold text-ink">${app.escapeHtml(material.title || 'Materi Perkuliahan')}</h3>
                                                    ${material.description ? `<div class="mt-2 text-caption text-ink-subtle">${app.escapeHtml(material.description)}</div>` : ''}
                                                </div>
                                                <div class="shrink-0">
                                                    ${meetingUrl ? `<a href="${meetingUrl}" class="${app.buttonClass('ghost')} mycic-btn-block">Buka Pertemuan</a>` : app.renderDisabledButton('Belum tersedia')}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `;
                } else {
                    document.getElementById('class-materials').innerHTML = app.renderEmptyState('Belum ada materi perkuliahan.', 'secondary');
                }

                if (attendance.summary || (attendance.meetings || []).length) {
                    const summary = attendance.summary || {};
                    const meetings = attendance.meetings || [];
                    const totalMeetings = Number(summary.total_meetings ?? meetings.length ?? 0);
                    const presentMeetings = Number(summary.present ?? 0);
                    const absentMeetings = Number(summary.absent ?? 0);

                    document.getElementById('class-attendance').innerHTML = `
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Presensi</h3>
                            </div>
                            <div class="mycic-card-body pb-0">
                                <div class="mb-3">
                                    ${app.renderInlineStats([
                                        { label: 'Total', value: totalMeetings },
                                        { label: 'Hadir', value: presentMeetings },
                                        { label: 'Absen', value: absentMeetings },
                                    ])}
                                </div>
                            </div>
                            ${meetings.length ? `
                                <div class="mycic-table-wrap">
                                    <table class="mycic-table">
                                        <thead>
                                            <tr>
                                                <th>Pertemuan</th>
                                                <th>Status</th>
                                                <th>Tanggal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${meetings.map((meeting) => {
                                                const normalizedStatus = String(meeting.status || '').toLowerCase();
                                                const isAbsent = normalizedStatus === 'absent';
                                                const details = meeting.details || {};
                                                const scheduleMeta = [details.attendance_date, details.attendance_time].filter(Boolean).join(' / ');

                                                return `
                                                    <tr>
                                                        <td>P${app.escapeHtml(meeting.meeting_number)}</td>
                                                        <td>${app.renderBadge(isAbsent ? 'Absen' : 'Hadir', isAbsent ? 'danger' : 'success')}</td>
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
                                ${app.renderEmptyState('Belum ada data presensi.', 'secondary')}
                            </div>
                        </div>
                    `;
                }

                if ((assignments.assignments || []).length) {
                    document.getElementById('class-assignments').innerHTML = `
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Riwayat Tugas</h3>
                            </div>
                            <div class="mycic-card-body">
                                ${(assignments.assignments || []).map((assignment) => {
                                    const fileInfo = assignment.file_info || {};
                                    const submissionUrl = app.absoluteCampusUrl(fileInfo.href);
                                    const isGraded = assignment.score && assignment.score.toLowerCase() !== 'belum ada nilai';

                                    return `
                                        <div class="mycic-list-row">
                                            <div class="min-w-0 flex-1">
                                                <div class="mb-1 text-caption text-ink-subtle">${app.escapeHtml(assignment.date || 'Tanggal tidak tersedia')}</div>
                                                <div class="mb-2 font-medium text-ink">Tugas ${app.escapeHtml(assignment.meeting || `Pertemuan ${assignment.no}`)}</div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    ${isGraded
                                                        ? app.renderBadge(`Nilai: ${assignment.score}`, 'success')
                                                        : app.renderBadge('Belum dinilai', 'warning')}
                                                    ${fileInfo.text ? app.renderBadge(fileInfo.text, 'neutral') : ''}
                                                </div>
                                            </div>
                                            <div class="shrink-0">
                                                ${submissionUrl
                                                    ? `<a href="${app.escapeHtml(submissionUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('secondary', { small: true })}">Lihat File</a>`
                                                    : '<span class="text-caption text-ink-subtle">Tidak ada file</span>'}
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
                                ${app.renderEmptyState('Belum ada histori tugas.', 'secondary')}
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
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('class-show', loadClassDetail);

            await loadClassDetail();
        });
    </script>
@endpush
