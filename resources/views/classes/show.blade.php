@extends('layouts.app')

@section('title', 'Detail Kelas')
@section('page_id', 'class-show')

@section('content')
    <section class="page-shell">
        <section id="class-hero" class="page-hero">
            <div class="empty-shell">Menunggu ringkasan kelas.</div>
        </section>

        <section id="class-info" class="section-shell">
            <div class="empty-shell">Menunggu detail kelas.</div>
        </section>

        <section id="class-materials" class="section-shell">
            <div class="empty-shell">Belum ada materi.</div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.9fr)]">
            <section id="class-attendance" class="section-shell">
                <div class="empty-shell">Belum ada data presensi.</div>
            </section>
            <section id="class-assignments" class="section-shell">
                <div class="empty-shell">Belum ada histori tugas.</div>
            </section>
        </div>
    </section>
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

                hero.innerHTML = `
                    <div class="page-hero-shell">
                        <div class="page-hero-main">
                            <p class="section-label">Detail kelas</p>
                            <h1 data-display class="page-hero-title is-wide">${app.escapeHtml(courseInfo.name || 'Informasi kelas')}</h1>
                            <p class="page-hero-copy">${app.escapeHtml(
                                [
                                    courseInfo.class_name,
                                    courseInfo.credits ? `${courseInfo.credits} SKS` : null,
                                    lecturerInfo.name,
                                ].filter(Boolean).join(' · ') || 'Lihat struktur kelas, materi, presensi, dan riwayat tugas dalam satu halaman.'
                            )}</p>
                            <div class="page-meta-row">
                                ${courseInfo.code ? `<span class="meta-pill is-accent">${app.escapeHtml(courseInfo.code)}</span>` : ''}
                                ${courseInfo.class_name ? `<span class="meta-pill">${app.escapeHtml(courseInfo.class_name)}</span>` : ''}
                                ${materials.length ? `<span class="meta-pill">${app.escapeHtml(`${materials.length} materi`)}</span>` : ''}
                            </div>
                        </div>
                        <div class="page-hero-actions">
                            <a href="/class" class="ghost-button">Kembali ke daftar</a>
                        </div>
                    </div>
                `;

                document.getElementById('class-info').innerHTML = `
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Ringkasan kelas</h2>
                            <p class="section-copy">Identitas mata kuliah dan pengajar utama dirangkum di sini sebelum masuk ke materi dan aktivitas kelas.</p>
                            <div class="page-meta-row">
                                ${courseInfo.code ? `<span class="meta-pill is-accent">${app.escapeHtml(courseInfo.code)}</span>` : ''}
                                ${courseInfo.class_name ? `<span class="meta-pill">${app.escapeHtml(courseInfo.class_name)}</span>` : ''}
                                ${courseInfo.credits ? `<span class="meta-pill">${app.escapeHtml(courseInfo.credits)} SKS</span>` : ''}
                            </div>
                        </div>
                        <div class="page-action-row">
                            <a href="/class" class="ghost-button">Kembali ke daftar</a>
                        </div>
                    </div>
                    <div class="property-grid mt-6">
                        <div class="property-panel">
                            ${app.renderKeyValueList([
                                { label: 'Kode', value: courseInfo.code },
                                { label: 'Nama', value: courseInfo.name },
                                { label: 'Kelas', value: courseInfo.class_name },
                                { label: 'SKS', value: courseInfo.credits },
                            ])}
                        </div>
                        <div class="property-panel">
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
                        <div class="section-head">
                            <div class="section-head-main">
                                <h2 data-display class="section-title">Materi</h2>
                                <p class="section-copy">Setiap materi ditata sebagai jalur baca cepat menuju detail meeting dan dokumen pendukung.</p>
                            </div>
                            <p class="section-meta">${materials.length} item</p>
                        </div>
                        <div class="mt-6 stack-list">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const materialLabel = material.meeting_number ? `Materi ${material.meeting_number}` : `Materi ${index + 1}`;

                                return `
                                    <article class="stack-item">
                                        <div class="stack-item-shell">
                                            <div class="stack-item-main">
                                                <div class="space-y-3">
                                                    <span class="stack-item-kicker">${app.escapeHtml(materialLabel)}</span>
                                                    <div>
                                                        <h3 class="stack-item-title">${app.escapeHtml(material.title || 'Tanpa judul')}</h3>
                                                        <p class="stack-item-copy">${app.escapeHtml([material.date, material.time].filter(Boolean).join(' / ') || '-')}</p>
                                                    </div>
                                                    ${material.description ? `<p class="stack-item-copy">${app.escapeHtml(material.description)}</p>` : ''}
                                                </div>
                                            </div>
                                            <div class="stack-item-aside">
                                                <div class="surface-card-soft">
                                                    <p class="section-label">Akses cepat</p>
                                                    <div class="surface-card-actions">
                                                        ${meetingUrl ? `<a href="${meetingUrl}" class="primary-button w-full justify-center">Detail meeting</a>` : '<span class="secondary-button w-full justify-center opacity-50">Meeting belum tersedia</span>'}
                                                    </div>
                                                </div>
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
                        <div class="section-head">
                            <div class="section-head-main">
                                <h2 data-display class="section-title">Presensi</h2>
                                <p class="section-copy">Pantau total kehadiran, pertemuan yang terlewat, dan riwayat absensi yang tercatat.</p>
                            </div>
                        </div>
                        <div class="dense-metric-grid mt-6">
                            ${[
                                ['Total', totalMeetings, 'Jumlah pertemuan yang tercatat'],
                                ['Hadir', presentMeetings, 'Pertemuan yang sudah dihadiri'],
                                ['Absen', absentMeetings, 'Pertemuan tanpa kehadiran'],
                            ].map(([label, value, note]) => `
                                <article class="dense-metric">
                                    <span class="dense-metric-label">${app.escapeHtml(label)}</span>
                                    <strong class="dense-metric-value">${app.escapeHtml(value ?? '0')}</strong>
                                    <p class="dense-metric-copy">${app.escapeHtml(note)}</p>
                                </article>
                            `).join('')}
                        </div>
                        ${meetings.length ? `
                            <details class="surface-card mt-6">
                                <summary class="cursor-pointer text-sm font-semibold text-[color:var(--ink)]">Riwayat per pertemuan</summary>
                                <div class="table-shell mt-4 overflow-x-auto">
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
                                                const label = isAbsent ? 'Absen' : 'Hadir';
                                                const pillClass = isAbsent ? 'meta-pill is-danger' : 'meta-pill is-success';
                                                const details = meeting.details || {};
                                                const scheduleMeta = [details.attendance_date, details.attendance_time].filter(Boolean).join(' / ');

                                                return `
                                                    <tr>
                                                        <td class="font-semibold text-[color:var(--ink)]">Pertemuan ${app.escapeHtml(meeting.meeting_number)}</td>
                                                        <td><span class="${pillClass}">${label}</span></td>
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
                        <div class="section-head">
                            <div class="section-head-main">
                                <h2 data-display class="section-title">Tugas</h2>
                                <p class="section-copy">Riwayat file yang pernah dikumpulkan dan nilai yang sudah kembali dari portal.</p>
                            </div>
                        </div>
                        <div class="mt-6 stack-list">
                            ${(assignments.assignments || []).map((assignment) => {
                                const fileInfo = assignment.file_info || {};
                                const submissionUrl = app.absoluteCampusUrl(fileInfo.href);

                                return `
                                    <article class="stack-item">
                                        <div class="stack-item-shell">
                                            <div class="stack-item-main">
                                                <div class="space-y-3">
                                                    <span class="stack-item-kicker">${app.escapeHtml(assignment.meeting || `Pertemuan ${assignment.no}`)}</span>
                                                    <div>
                                                        <h3 class="stack-item-title">${app.escapeHtml(assignment.score || 'Belum ada nilai')}</h3>
                                                        <p class="stack-item-copy">${app.escapeHtml(assignment.date || '-')}</p>
                                                    </div>
                                                </div>
                                                <div>
                                                    ${app.renderKeyValueList([
                                                        { label: 'File', value: fileInfo.text },
                                                        { label: 'Nilai', value: assignment.score },
                                                    ])}
                                                </div>
                                            </div>
                                            <div class="stack-item-aside">
                                                <div class="surface-card-soft">
                                                    <p class="section-label">Aksi file</p>
                                                    <div class="surface-card-actions">
                                                        ${submissionUrl ? `
                                                            <a href="${app.escapeHtml(submissionUrl)}" target="_blank" rel="noopener noreferrer" class="secondary-button w-full justify-center">
                                                                Lihat hasil upload
                                                            </a>
                                                        ` : '<span class="secondary-button w-full justify-center opacity-50">Belum ada file</span>'}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                `;
                            }).join('')}
                        </div>
                    `;
                } else {
                    document.getElementById('class-assignments').innerHTML = '<div class="empty-shell">Belum ada histori tugas.</div>';
                }
            };

            app.primeCachedView(endpoint(), render, 'detail kelas');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadClassDetail = async () => {
                try {
                    const payload = await app.request(endpoint());
                    render(payload);
                    app.markViewSynced('Detail kelas');
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('class-show', loadClassDetail);

            await loadClassDetail();
        });
    </script>
@endpush
