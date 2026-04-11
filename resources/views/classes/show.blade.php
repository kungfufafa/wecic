@extends('layouts.app')

@section('title', 'Detail Kelas')
@section('page_id', 'class-show')

@section('content')
    <section class="grid gap-6">
        <div class="app-panel">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="section-label">Detail kelas</p>
                    <h1 id="class-title" data-display class="mt-4 text-4xl font-semibold text-[color:var(--ink)]">Memuat kelas</h1>
                    <p id="class-subtitle" class="mt-3 text-sm leading-7 text-[color:var(--ink-soft)]">Mengambil detail kelas dari API.</p>
                </div>
                <div class="flex gap-2">
                    <a href="/class" class="ghost-button">Kembali</a>
                    <button type="button" id="class-refresh" class="secondary-button">Refresh</button>
                </div>
            </div>
        </div>

        <div id="class-info" class="app-panel">
            <div class="empty-shell">Menunggu detail kelas.</div>
        </div>

        <div id="class-materials" class="app-panel">
            <div class="empty-shell">Belum ada materi.</div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.9fr)]">
            <div id="class-attendance" class="app-panel">
                <div class="empty-shell">Belum ada data presensi.</div>
            </div>
            <div id="class-assignments" class="app-panel">
                <div class="empty-shell">Belum ada histori tugas.</div>
            </div>
        </div>

        <details class="app-panel">
            <summary class="cursor-pointer text-sm font-semibold text-[color:var(--ink)]">Payload mentah</summary>
            <pre id="class-payload" class="mt-4 overflow-x-auto text-xs leading-6 text-[color:var(--ink-soft)]">Menunggu data.</pre>
        </details>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

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
                const students = detail.students_list || [];

                document.getElementById('class-title').textContent = courseInfo.name || `Kelas ${classId}`;
                document.getElementById('class-subtitle').textContent = [courseInfo.code, courseInfo.class_name].filter(Boolean).join(' / ') || 'Detail kelas LMS dan jadwal.';

                document.getElementById('class-info').innerHTML = `
                    <p class="section-label">Informasi utama</p>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="app-panel-muted">
                            ${app.renderKeyValueList([
                                { label: 'Kode', value: courseInfo.code },
                                { label: 'Nama', value: courseInfo.name },
                                { label: 'Kelas', value: courseInfo.class_name },
                                { label: 'SKS', value: courseInfo.credits },
                            ])}
                        </div>
                        <div class="app-panel-muted">
                            ${app.renderKeyValueList([
                                { label: 'Dosen', value: lecturerInfo.name },
                                { label: 'Kontak', value: lecturerInfo.contact_info || lecturerInfo.contact },
                                { label: 'Email', value: lecturerInfo.email },
                            ])}
                        </div>
                    </div>
                `;

                if (materials.length) {
                    document.getElementById('class-materials').innerHTML = `
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="section-label">Materi</p>
                                <h2 data-display class="mt-3 text-2xl font-semibold text-[color:var(--ink)]">Daftar materi kelas</h2>
                            </div>
                            <span class="text-sm font-semibold text-[color:var(--ink-soft)]">${materials.length} item</span>
                        </div>
                        <div class="mt-6 grid gap-4">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const materialLabel = material.meeting_number ? `Materi ${material.meeting_number}` : `Materi ${index + 1}`;

                                return `
                                    <article class="app-panel-muted">
                                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                            <div class="space-y-3">
                                                <div>
                                                    <p class="section-label">${app.escapeHtml(materialLabel)}</p>
                                                    <h3 class="mt-3 text-lg font-semibold text-[color:var(--ink)]">${app.escapeHtml(material.title || 'Tanpa judul')}</h3>
                                                    <p class="mt-2 text-sm text-[color:var(--ink-soft)]">${app.escapeHtml([material.date, material.time].filter(Boolean).join(' / ') || '-')}</p>
                                                </div>
                                                ${material.description ? `<p class="text-sm leading-7 text-[color:var(--ink-soft)]">${app.escapeHtml(material.description)}</p>` : ''}
                                            </div>
                                            <div class="flex flex-col gap-2 lg:w-[12rem]">
                                                ${meetingUrl ? `<a href="${meetingUrl}" class="primary-button">Detail meeting</a>` : ''}
                                            </div>
                                        </div>
                                    </article>
                                `;
                            }).join('')}
                        </div>
                    `;
                } else {
                    document.getElementById('class-materials').innerHTML = '<div class="empty-shell">Belum ada materi perkuliahan.</div>';
                }

                if (attendance.summary || (attendance.meetings || []).length) {
                    const summary = attendance.summary || {};
                    const meetings = attendance.meetings || [];
                    const totalMeetings = Number(summary.total_meetings ?? meetings.length ?? 0);
                    const presentMeetings = Number(summary.present ?? 0);
                    const absentMeetings = Number(summary.absent ?? 0);

                    document.getElementById('class-attendance').innerHTML = `
                        <p class="section-label">Presensi</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            ${[
                                ['Total', totalMeetings],
                                ['Hadir', presentMeetings],
                                ['Absen', absentMeetings],
                            ].map(([label, value]) => `
                                <article class="app-panel-muted">
                                    <p class="section-label">${app.escapeHtml(label)}</p>
                                    <p class="mt-3 text-2xl font-semibold text-[color:var(--ink)]">${app.escapeHtml(value ?? '0')}</p>
                                </article>
                            `).join('')}
                        </div>
                        ${meetings.length ? `
                            <details class="mt-4 rounded-2xl border border-[color:var(--line)] bg-white/70 p-4">
                                <summary class="cursor-pointer text-sm font-semibold text-[color:var(--ink)]">Riwayat per pertemuan</summary>
                                <div class="mt-4 overflow-x-auto">
                                    <table class="compact-table min-w-[28rem]">
                                        <thead>
                                            <tr>
                                                <th>Pertemuan</th>
                                                <th>Status</th>
                                                <th>Tanggal / Jam</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${meetings.map((meeting) => {
                                                const normalizedStatus = String(meeting.status || '').toLowerCase();
                                                const isAbsent = normalizedStatus === 'absent';
                                                const tone = isAbsent ? 'var(--notice-error)' : 'var(--notice-success)';
                                                const label = isAbsent ? 'Absen' : 'Hadir';
                                                const details = meeting.details || {};
                                                const scheduleMeta = [details.attendance_date, details.attendance_time].filter(Boolean).join(' / ');

                                                return `
                                                    <tr>
                                                        <td class="font-semibold text-[color:var(--ink)]">Pertemuan ${app.escapeHtml(meeting.meeting_number)}</td>
                                                        <td>
                                                            <span class="inline-flex min-h-9 items-center justify-center rounded-full px-3 text-xs font-semibold uppercase tracking-[0.14em] text-white" style="background:${tone};">
                                                                ${label}
                                                            </span>
                                                        </td>
                                                        <td>${app.escapeHtml(scheduleMeta || '-')}</td>
                                                    </tr>
                                                `;
                                            }).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        ` : ''}
                    `;
                } else {
                    document.getElementById('class-attendance').innerHTML = '<div class="empty-shell">Belum ada data presensi.</div>';
                }

                if ((assignments.assignments || []).length) {
                    document.getElementById('class-assignments').innerHTML = `
                        <p class="section-label">Tugas</p>
                        <div class="mt-4 grid gap-3">
                            ${(assignments.assignments || []).map((assignment) => {
                                const fileInfo = assignment.file_info || {};
                                const submissionUrl = app.absoluteCampusUrl(fileInfo.href);

                                return `
                                <article class="app-panel-muted">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-[color:var(--ink)]">${app.escapeHtml(assignment.meeting || `Pertemuan ${assignment.no}`)}</h3>
                                            <p class="mt-2 text-xs uppercase tracking-[0.16em] text-[color:var(--ink-soft)]">${app.escapeHtml(assignment.date || '-')}</p>
                                        </div>
                                        <span class="text-sm font-semibold text-[color:var(--ink)]">${app.escapeHtml(assignment.score || '-')}</span>
                                    </div>
                                    ${submissionUrl ? `
                                        <div class="mt-4 flex flex-wrap items-center gap-3">
                                            <a href="${app.escapeHtml(submissionUrl)}" target="_blank" rel="noopener noreferrer" class="secondary-button">
                                                Lihat hasil upload
                                            </a>
                                            ${fileInfo.text ? `<span class="text-xs uppercase tracking-[0.14em] text-[color:var(--ink-soft)]">${app.escapeHtml(fileInfo.text)}</span>` : ''}
                                        </div>
                                    ` : ''}
                                </article>
                            `;
                            }).join('')}
                        </div>
                    `;
                } else {
                    document.getElementById('class-assignments').innerHTML = '<div class="empty-shell">Belum ada histori tugas.</div>';
                }

                document.getElementById('class-payload').textContent = JSON.stringify(payload, null, 2);
            };

            const loadClassDetail = async () => {
                try {
                    const payload = await app.request(endpoint());
                    render(payload);
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            document.getElementById('class-refresh').addEventListener('click', loadClassDetail);

            await loadClassDetail();
        });
    </script>
@endpush
