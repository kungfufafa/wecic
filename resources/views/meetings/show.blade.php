@extends('layouts.app')

@section('title', 'Detail Pertemuan')
@section('page_id', 'meeting-show')

@section('content')
    <section class="grid gap-6">
        <div class="app-panel">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="section-label">Pertemuan</p>
                    <h1 id="meeting-title" data-display class="mt-4 text-4xl font-semibold text-[color:var(--ink)]">Memuat detail meeting</h1>
                    <p id="meeting-subtitle" class="mt-3 text-sm leading-7 text-[color:var(--ink-soft)]">Membaca detail materi dan aktivitas pertemuan dari LMS.</p>
                </div>
                <div class="flex gap-2">
                    <a href="/class" class="ghost-button">Kembali</a>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.9fr)]">
            <div id="meeting-info" class="app-panel">
                <div class="empty-shell">Menunggu detail meeting.</div>
            </div>
            <div id="meeting-actions" class="app-panel">
                <div class="empty-shell">Belum ada aksi meeting.</div>
            </div>
        </div>

        <div id="meeting-content" class="app-panel">
            <div class="empty-shell">Belum ada materi meeting.</div>
        </div>

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
                const rpsInfo = detail.rps_info || {};
                const attendanceInfo = detail.attendance_info || {};
                const assignmentInfo = detail.assignment_info || {};
                const conformityInfo = detail.conformity_info || {};
                const materials = detail.materials || {};
                const instructions = detail.instructions || {};
                const assignmentParams = parseAssignmentParams(assignmentInfo.upload_link);
                const inferredMeetingNumber =
                    assignmentParams?.pertemuan
                    || materials.assignment_topic?.match(/(\d+)/)?.[1]
                    || materials.main_topic?.match(/Pertemuan\s+(\d+)/i)?.[1]
                    || null;
                const attendanceStatusText = String(attendanceInfo.status || '').trim();
                const attendanceRecorded = Boolean(attendanceInfo.time)
                    || (/hadir|terlambat|izin|sakit/i.test(attendanceStatusText) && !/belum|silakan|scan|qr/i.test(attendanceStatusText));

                document.getElementById('meeting-title').textContent = meetingInfo.title || `Meeting ${meetingId}`;
                document.getElementById('meeting-subtitle').textContent = [scheduleInfo.date, lecturerInfo.name].filter(Boolean).join(' / ') || 'Detail pertemuan LMS.';

                document.getElementById('meeting-info').innerHTML = `
                    <p class="section-label">Ringkasan</p>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="app-panel-muted">
                            ${app.renderKeyValueList([
                                { label: 'Dosen', value: lecturerInfo.name },
                                { label: 'Tanggal', value: scheduleInfo.date },
                                { label: 'Mulai', value: scheduleInfo.start_time },
                            ])}
                        </div>
                        <div class="app-panel-muted">
                            ${app.renderKeyValueList([
                                { label: 'Pertemuan', value: inferredMeetingNumber },
                                { label: 'Kode kelas', value: coursesId },
                            ])}
                        </div>
                    </div>
                `;

                document.getElementById('meeting-actions').innerHTML = `
                    <p class="section-label">Aksi</p>
                    ${attendanceRecorded ? `
                        <div class="app-panel-muted mt-4">
                            <p class="section-label">Absensi</p>
                            <p class="mt-3 text-sm font-semibold text-[color:var(--ink)]">${app.escapeHtml(attendanceStatusText)}</p>
                            ${attendanceInfo.time ? `<p class="mt-2 text-sm leading-6 text-[color:var(--ink-soft)]">Tercatat pada ${app.escapeHtml(attendanceInfo.time)}.</p>` : ''}
                        </div>
                    ` : `
                        <form id="attendance-form" class="mt-4 grid gap-3">
                            <label class="grid gap-2">
                                <span class="section-label">Kode QR / kode absensi</span>
                                <input id="attendance-code" type="text" class="field-shell" placeholder="35533429" required>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" class="secondary-button justify-center" data-qr-scan>Scan QR</button>
                                <label class="secondary-button justify-center cursor-pointer">
                                    Upload foto
                                    <input type="file" class="hidden" data-qr-file accept="image/*">
                                </label>
                            </div>
                            <button type="submit" class="secondary-button" data-online-required>Kirim</button>
                        </form>
                    `}
                    ${assignmentParams ? `
                        <form id="assignment-form" class="mt-6 grid gap-3">
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
                        <article class="app-panel-muted">
                            <p class="section-label">Materi utama</p>
                            <h2 data-display class="mt-3 text-2xl font-semibold text-[color:var(--ink)]">${app.escapeHtml(materials.main_topic || 'Materi')}</h2>
                            ${materials.main_description ? `<p class="mt-4 text-sm leading-7 text-[color:var(--ink-soft)]">${app.escapeHtml(materials.main_description)}</p>` : ''}
                            ${materials.main_material_iframe ? `<a href="${app.absoluteCampusUrl(materials.main_material_iframe)}" target="_blank" rel="noreferrer" class="secondary-button mt-4">Buka materi utama</a>` : ''}
                        </article>
                    `);
                }

                if (materials.assignment_topic || materials.assignment_instructions || materials.assignment_material_iframe) {
                    contentParts.push(`
                        <article class="app-panel-muted">
                            <p class="section-label">Tugas perkuliahan</p>
                            <h2 data-display class="mt-3 text-2xl font-semibold text-[color:var(--ink)]">${app.escapeHtml(materials.assignment_topic || assignmentInfo.upload_text || 'Instruksi tugas')}</h2>
                            ${materials.assignment_instructions ? `
                                <p class="mt-4 text-sm leading-7 text-[color:var(--ink-soft)]">
                                    Batas waktu: ${app.escapeHtml(materials.assignment_instructions)}
                                </p>
                            ` : ''}
                            ${materials.assignment_material_iframe ? `
                                <a href="${app.absoluteCampusUrl(materials.assignment_material_iframe)}" target="_blank" rel="noreferrer" class="secondary-button mt-4">
                                    Buka lampiran tugas
                                </a>
                            ` : ''}
                        </article>
                    `);
                }

                if (!contentParts.length) {
                    contentParts.push('<div class="empty-shell">Belum ada materi atau instruksi tambahan pada pertemuan ini.</div>');
                }

                document.getElementById('meeting-content').innerHTML = contentParts.join('');
                app.refreshOnlineRequiredState();
                const attendanceForm = document.getElementById('attendance-form');

                if (attendanceForm) {
                    app.bindAttendanceQrTools(
                        attendanceForm,
                        document.getElementById('attendance-code')
                    );
                }

                attendanceForm?.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    if (!app.requireOnlineAction('Absen meeting butuh koneksi internet.')) {
                        return;
                    }

                    const kode = document.getElementById('attendance-code').value.trim();

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
