@extends('layouts.app')

@section('title', 'Detail Kelas')
@section('page_id', 'class-show')

@section('content')
    <div id="class-hero">
        {!! '<div class="alert alert-secondary mb-4">Menunggu ringkasan kelas.</div>' !!}
    </div>

    <div class="row row-cards">
        <div class="col-lg-8" id="class-materials">
            {!! '<div class="alert alert-secondary mb-0">Menunggu materi kelas.</div>' !!}
        </div>

        <div class="col-lg-4 d-flex flex-column gap-3">
            <div id="class-attendance">
                {!! '<div class="alert alert-secondary mb-0">Belum ada data presensi.</div>' !!}
            </div>

            <div id="class-assignments">
                {!! '<div class="alert alert-secondary mb-0">Belum ada histori tugas.</div>' !!}
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
                                '<a href="/class" class="btn btn-outline-secondary">Kembali ke daftar</a>',
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

                // class-info has been removed to reduce redundancy, as hero stat cards provide the necessary context.

                if (materials.length) {
                    document.getElementById('class-materials').innerHTML = `
                        <div class="d-flex flex-column gap-3 mb-4">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const materialLabel = material.meeting_number ? `Pertemuan ${material.meeting_number}` : `Pertemuan ${index + 1}`;

                                return `
                                    <div class="list-group-item px-4 py-3 rounded border" style="border-top-width: 1px;">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span class="badge bg-primary-lt">${app.escapeHtml(materialLabel)}</span>
                                                    <span class="text-secondary small">${app.escapeHtml([material.date, material.time].filter(Boolean).join(' • ') || 'Jadwal belum ditentukan')}</span>
                                                </div>
                                                <h4 class="h3 mb-1">${app.escapeHtml(material.title || 'Materi Perkuliahan')}</h4>
                                                ${material.description ? `<div class="text-secondary small mt-2">${app.escapeHtml(material.description)}</div>` : ''}
                                            </div>
                                            <div class="col-12 col-md-auto mt-3 mt-md-0">
                                                ${meetingUrl ? `<a href="${meetingUrl}" class="btn btn-primary-lt w-100">Buka Pertemuan</a>` : `<button class="btn btn-outline-secondary w-100 disabled" title="Belum tersedia">Belum tersedia</button>`}
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `;
                } else {
                    document.getElementById('class-materials').innerHTML = `
                        <div class="list-group-item py-4 rounded border" style="border-top-width: 1px;">
                            ${app.renderEmptyState('Belum ada materi perkuliahan.', 'secondary')}
                        </div>
                    `;
                }

                if (attendance.summary || (attendance.meetings || []).length) {
                    const summary = attendance.summary || {};
                    const meetings = attendance.meetings || [];
                    const totalMeetings = Number(summary.total_meetings ?? meetings.length ?? 0);
                    const presentMeetings = Number(summary.present ?? 0);
                    const absentMeetings = Number(summary.absent ?? 0);

                    document.getElementById('class-attendance').innerHTML = `
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Presensi</h3>
                            </div>
                            <div class="card-body pb-0">
                                <div class="mb-3">
                                    ${app.renderInlineStats([
                                        { label: 'Total', value: totalMeetings },
                                        { label: 'Hadir', value: presentMeetings },
                                        { label: 'Absen', value: absentMeetings },
                                    ])}
                                </div>
                            </div>
                            ${meetings.length ? `
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table table-sm">
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
                                                        <td class="text-secondary small">${app.escapeHtml(scheduleMeta || '-')}</td>
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
                        <div class="card">
                            <div class="card-body">
                                ${app.renderEmptyState('Belum ada data presensi.', 'secondary')}
                            </div>
                        </div>
                    `;
                }

                if ((assignments.assignments || []).length) {
                    document.getElementById('class-assignments').innerHTML = `
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="card-title">Riwayat Tugas</h3>
                            </div>
                            <div class="list-group list-group-flush">
                                ${(assignments.assignments || []).map((assignment) => {
                                    const fileInfo = assignment.file_info || {};
                                    const submissionUrl = app.absoluteCampusUrl(fileInfo.href);
                                    const isGraded = assignment.score && assignment.score.toLowerCase() !== 'belum ada nilai';

                                    return `
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="text-secondary small mb-1">${app.escapeHtml(assignment.date || 'Tanggal tidak tersedia')}</div>
                                                    <div class="fw-semibold text-body mb-2">Tugas ${app.escapeHtml(assignment.meeting || `Pertemuan ${assignment.no}`)}</div>
                                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                                        ${isGraded 
                                                            ? app.renderBadge(`Nilai: ${assignment.score}`, 'success') 
                                                            : app.renderBadge('Belum dinilai', 'warning')}
                                                        ${fileInfo.text ? app.renderBadge(fileInfo.text, 'secondary') : ''}
                                                    </div>
                                                </div>
                                                <div class="col-auto mt-3 mt-md-0">
                                                    ${submissionUrl
                                                        ? `<a href="${app.escapeHtml(submissionUrl)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">Lihat File</a>`
                                                        : '<span class="text-secondary small">Tidak ada file</span>'}
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    `;
                } else {
                    document.getElementById('class-assignments').innerHTML = `
                        <div class="card">
                            <div class="card-body">
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
