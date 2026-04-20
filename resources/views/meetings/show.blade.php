@extends('layouts.app')

@section('title', 'Detail Pertemuan')
@section('page_id', 'meeting-show')

@section('content')
    <section class="page-shell">
        <section id="meeting-hero" class="page-hero">
            <div class="empty-shell">Menunggu ringkasan meeting.</div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.9fr)]">
            <section id="meeting-info" class="section-shell">
                <div class="empty-shell">Menunggu detail meeting.</div>
            </section>
            <aside id="meeting-actions" class="section-shell">
                <div class="empty-shell">Belum ada aksi meeting.</div>
            </aside>
        </div>

        <section id="meeting-content" class="section-shell">
            <div class="empty-shell">Belum ada materi meeting.</div>
        </section>
    </section>
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

            const render = (payload) => {
                const detail = payload.class_detail || {};
                const meetingInfo = detail.meeting_info || {};
                const lecturerInfo = detail.lecturer_info || {};
                const scheduleInfo = detail.schedule_info || {};
                const attendanceInfo = detail.attendance_info || {};
                const assignmentInfo = detail.assignment_info || {};
                const materials = detail.materials || {};
                const assignmentParams = parseAssignmentParams(assignmentInfo.upload_link);
                const inferredMeetingNumber =
                    assignmentParams?.pertemuan
                    || materials.assignment_topic?.match(/(\d+)/)?.[1]
                    || materials.main_topic?.match(/Pertemuan\s+(\d+)/i)?.[1]
                    || null;
                const attendanceStatusText = String(attendanceInfo.status || '').trim();
                const attendanceRecorded = Boolean(attendanceInfo.time)
                    || (/hadir|terlambat|izin|sakit/i.test(attendanceStatusText) && !/belum|silakan|scan|qr/i.test(attendanceStatusText));
                const hero = document.getElementById('meeting-hero');

                hero.innerHTML = `
                    <div class="page-hero-shell">
                        <div class="page-hero-main">
                            <p class="section-label">Pertemuan kelas</p>
                            <h1 data-display class="page-hero-title is-wide">${app.escapeHtml(materials.main_topic || materials.assignment_topic || 'Detail meeting')}</h1>
                            <p class="page-hero-copy">${app.escapeHtml(
                                [
                                    scheduleInfo.date,
                                    scheduleInfo.start_time,
                                    lecturerInfo.name,
                                ].filter(Boolean).join(' · ') || 'Periksa materi utama, presensi, dan instruksi tugas pada pertemuan ini.'
                            )}</p>
                            <div class="page-meta-row">
                                ${inferredMeetingNumber ? `<span class="meta-pill is-accent">Pertemuan ${app.escapeHtml(inferredMeetingNumber)}</span>` : ''}
                                ${scheduleInfo.date ? `<span class="meta-pill">${app.escapeHtml(scheduleInfo.date)}</span>` : ''}
                                <span class="meta-pill">${attendanceRecorded ? 'Absensi tercatat' : 'Absensi tersedia'}</span>
                            </div>
                        </div>
                        <div class="page-hero-actions">
                            <a href="/class" class="ghost-button">Kembali ke kelas</a>
                        </div>
                    </div>
                `;

                document.getElementById('meeting-info').innerHTML = `
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Informasi meeting</h2>
                            <p class="section-copy">Struktur inti pertemuan, jadwal, dan pengajar ditata di sini sebelum aksi presensi atau tugas dilakukan.</p>
                            <div class="page-meta-row">
                                ${inferredMeetingNumber ? `<span class="meta-pill is-accent">Pertemuan ${app.escapeHtml(inferredMeetingNumber)}</span>` : ''}
                                ${scheduleInfo.date ? `<span class="meta-pill">${app.escapeHtml(scheduleInfo.date)}</span>` : ''}
                                ${lecturerInfo.name ? `<span class="meta-pill">${app.escapeHtml(lecturerInfo.name)}</span>` : ''}
                            </div>
                        </div>
                        <div class="page-action-row">
                            <a href="/class" class="ghost-button">Kembali ke kelas</a>
                        </div>
                    </div>
                    <div class="property-grid mt-6">
                        <div class="property-panel">
                            ${app.renderKeyValueList([
                                { label: 'Dosen', value: lecturerInfo.name },
                                { label: 'Tanggal', value: scheduleInfo.date },
                                { label: 'Mulai', value: scheduleInfo.start_time },
                            ])}
                        </div>
                        <div class="property-panel">
                            ${app.renderKeyValueList([
                                { label: 'Pertemuan', value: inferredMeetingNumber },
                                { label: 'Kode kelas', value: coursesId },
                            ])}
                        </div>
                    </div>
                `;

                document.getElementById('meeting-actions').innerHTML = `
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Aksi</h2>
                            <p class="section-copy">Presensi dan pengumpulan tugas ditempatkan di sisi yang sama agar alur meeting terasa lebih ringkas.</p>
                        </div>
                    </div>
                    ${attendanceRecorded ? `
                        <div class="surface-card mt-6">
                            <p class="section-label">Absensi</p>
                            <p class="surface-card-title mt-3">${app.escapeHtml(attendanceStatusText)}</p>
                            ${attendanceInfo.time ? `<p class="surface-card-copy">Tercatat pada ${app.escapeHtml(attendanceInfo.time)}.</p>` : ''}
                        </div>
                    ` : `
                        <form id="attendance-form" class="surface-card mt-6 grid gap-3">
                            <label class="grid gap-2">
                                <span class="section-label">Kode QR / kode absensi</span>
                                <input id="attendance-code" type="text" class="field-shell" placeholder="009bfb3e" required>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" class="secondary-button justify-center" data-qr-scan>Scan QR</button>
                                <label class="secondary-button justify-center cursor-pointer">
                                    Upload foto
                                    <input type="file" class="hidden" data-qr-file accept="image/*">
                                </label>
                            </div>
                            <button type="submit" class="primary-button" data-online-required>Kirim absensi</button>
                        </form>
                    `}
                    ${assignmentParams ? `
                        <form id="assignment-form" class="surface-card mt-4 grid gap-3">
                            <label class="grid gap-2">
                                <span class="section-label">Upload tugas</span>
                                <input id="assignment-file" type="file" accept=".pdf" class="field-shell" required>
                            </label>
                            <label class="grid gap-2">
                                <span class="section-label">Keterangan</span>
                                <input id="assignment-note" type="text" class="field-shell" placeholder="Opsional">
                            </label>
                            <button type="submit" class="primary-button" data-online-required>Kirim tugas</button>
                        </form>
                    ` : ''}
                `;

                const contentParts = [];

                if (materials.main_topic || materials.main_description) {
                    contentParts.push(`
                        <article class="stack-item">
                            <div class="stack-item-shell">
                                <div class="stack-item-main">
                                    <span class="stack-item-kicker">Materi utama</span>
                                    <div>
                                        <h2 data-display class="stack-item-title">${app.escapeHtml(materials.main_topic || 'Materi')}</h2>
                                        ${materials.main_description ? `<p class="stack-item-copy">${app.escapeHtml(materials.main_description)}</p>` : ''}
                                    </div>
                                </div>
                                <div class="stack-item-aside">
                                    <div class="surface-card-soft">
                                        <p class="section-label">Lampiran</p>
                                        <div class="surface-card-actions">
                                            ${materials.main_material_iframe ? `<a href="${app.absoluteCampusUrl(materials.main_material_iframe)}" target="_blank" rel="noreferrer" class="secondary-button w-full justify-center">Buka materi utama</a>` : '<span class="secondary-button w-full justify-center opacity-50">Tidak ada lampiran</span>'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    `);
                }

                if (materials.assignment_topic || materials.assignment_instructions || materials.assignment_material_iframe) {
                    contentParts.push(`
                        <article class="stack-item">
                            <div class="stack-item-shell">
                                <div class="stack-item-main">
                                    <span class="stack-item-kicker">Tugas perkuliahan</span>
                                    <div>
                                        <h2 data-display class="stack-item-title">${app.escapeHtml(materials.assignment_topic || assignmentInfo.upload_text || 'Instruksi tugas')}</h2>
                                        ${materials.assignment_instructions ? `<p class="stack-item-copy">Batas waktu: ${app.escapeHtml(materials.assignment_instructions)}</p>` : ''}
                                    </div>
                                </div>
                                <div class="stack-item-aside">
                                    <div class="surface-card-soft">
                                        <p class="section-label">Lampiran tugas</p>
                                        <div class="surface-card-actions">
                                            ${materials.assignment_material_iframe ? `<a href="${app.absoluteCampusUrl(materials.assignment_material_iframe)}" target="_blank" rel="noreferrer" class="secondary-button w-full justify-center">Buka lampiran</a>` : '<span class="secondary-button w-full justify-center opacity-50">Tidak ada lampiran</span>'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    `);
                }

                if (!contentParts.length) {
                    contentParts.push('<div class="empty-shell">Belum ada materi atau instruksi tambahan pada pertemuan ini.</div>');
                }

                document.getElementById('meeting-content').innerHTML = `
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Konten meeting</h2>
                            <p class="section-copy">Materi utama dan instruksi tugas dipisah agar lebih mudah dipindai saat kelas sedang berlangsung.</p>
                        </div>
                    </div>
                    <div class="mt-6 stack-list">
                        ${contentParts.join('')}
                    </div>
                `;

                app.refreshOnlineRequiredState();
                const attendanceForm = document.getElementById('attendance-form');

                if (attendanceForm) {
                    app.bindAttendanceQrTools(
                        attendanceForm,
                        document.getElementById('attendance-code'),
                        {
                            onDetected: () => {
                                app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                                app.requestFormSubmit(attendanceForm);
                            },
                        }
                    );
                }

                attendanceForm?.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    if (attendanceForm.dataset.submitting === 'true') {
                        return;
                    }

                    if (!app.requireOnlineAction('Absen meeting butuh koneksi internet.')) {
                        return;
                    }

                    const kode = document.getElementById('attendance-code').value.trim();

                    attendanceForm.dataset.submitting = 'true';

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
                    } catch (error) {
                        app.showNotice(error.payload?.message || error.message, 'error');
                    } finally {
                        delete attendanceForm.dataset.submitting;
                    }
                });

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

                        try {
                            const result = await app.request('/api/assignment', {
                                method: 'POST',
                                formData,
                            });

                            app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        }
                    });
                }
            };

            app.primeCachedView(endpoint, render, 'detail meeting');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadMeeting = async () => {
                try {
                    const payload = await app.request(endpoint);
                    render(payload);
                    app.markViewSynced('Detail meeting');
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('meeting-show', loadMeeting);

            await loadMeeting();
        });
    </script>
@endpush
