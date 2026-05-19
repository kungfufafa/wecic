@extends('layouts.app')

@section('title', 'Detail Pertemuan')
@section('page_id', 'meeting-show')

@section('content')
    <div id="meeting-hero">
        {!! '<div class="alert alert-secondary mb-4">Menunggu ringkasan meeting.</div>' !!}
    </div>

    <div class="row row-cards">
        <div class="col-lg-8" id="meeting-content">
            {!! '<div class="alert alert-secondary mb-0">Menunggu materi meeting.</div>' !!}
        </div>

        <div class="col-lg-4" id="meeting-actions">
            {!! '<div class="alert alert-secondary mb-0">Belum ada aksi meeting.</div>' !!}
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

            const render = (payload) => {
                const detail = payload.class_detail || {};
                const lecturerInfo = detail.lecturer_info || {};
                const scheduleInfo = detail.schedule_info || {};
                const attendanceInfo = detail.attendance_info || {};
                const assignmentInfo = detail.assignment_info || {};
                const materials = detail.materials || {};
                const assignmentParams = deriveAssignmentParams(assignmentInfo, materials);
                const assignmentSubmission = assignmentInfo.submission || null;
                const assignmentFileInfo = assignmentSubmission?.file_info || null;
                const assignmentDeletePayload = assignmentInfo.delete_payload || null;
                const assignmentAlreadyUploaded = assignmentInfo.status === 'uploaded' && assignmentFileInfo;
                const inferredMeetingNumber = assignmentParams?.pertemuan || inferMeetingNumber(materials);
                const attendanceStatusText = String(attendanceInfo.status || '').trim();
                const attendanceRecorded = Boolean(attendanceInfo.time)
                    || (/hadir|terlambat|izin|sakit/i.test(attendanceStatusText) && !/belum|silakan|scan|qr/i.test(attendanceStatusText));
                const hero = document.getElementById('meeting-hero');

                hero.innerHTML = `
                    <div class="mb-4">
                        ${app.renderPageHeader({
                            pretitle: 'Pertemuan kelas',
                            title: materials.main_topic || materials.assignment_topic || 'Detail meeting',
                            actions: [
                                '<a href="/class" class="btn btn-outline-secondary">Kembali ke kelas</a>',
                            ],
                        })}
                    </div>
                    ${app.renderStatCards([
                        { label: 'Tanggal', value: scheduleInfo.date || '-', note: 'Tanggal pelaksanaan meeting.' },
                        { label: 'Mulai', value: scheduleInfo.start_time || '-', note: 'Jam mulai yang tercatat.' },
                        { label: 'Dosen', value: lecturerInfo.name || '-', note: 'Pengampu meeting ini.' },
                        { label: 'Status', value: attendanceRecorded ? 'Tercatat' : 'Tersedia', note: 'Kondisi absensi saat ini.' },
                    ])}
                `;

                document.getElementById('meeting-actions').innerHTML = `
                    <div class="d-flex flex-column gap-3">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Presensi</h3>
                            </div>
                            <div class="card-body">
                                ${attendanceRecorded ? `
                                    <div class="alert alert-success mb-0">
                                        <div class="d-flex align-items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-md" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                                            <div>
                                                <div class="fw-semibold">${app.escapeHtml(attendanceStatusText || 'Absensi tercatat')}</div>
                                                ${attendanceInfo.time ? `<div class="text-success small">${app.escapeHtml(`Tercatat pada ${attendanceInfo.time}`)}</div>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                ` : `
                                    <form id="attendance-form">
                                        <div class="mb-3">
                                            <label for="attendance-code" class="form-label">Kode absen / QR</label>
                                            <input id="attendance-code" type="text" class="form-control" placeholder="Ketik kode di sini" required>
                                        </div>
                                        <div class="row g-2 mb-3">
                                            <div class="col-sm-5">
                                                <button type="button" class="btn btn-outline-primary w-100" data-qr-scan>
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                                                    Kamera
                                                </button>
                                            </div>
                                            <div class="col-sm-7">
                                                <input type="file" class="form-control" data-qr-file accept="image/*" title="Pilih gambar QR">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100" data-online-required>Kirim absensi</button>
                                    </form>
                                `}
                            </div>
                        </div>

                        ${assignmentAlreadyUploaded ? `
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Tugas</h3>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-success mb-3">
                                    <div class="fw-semibold">Tugas sudah diupload.</div>
                                    ${assignmentSubmission?.date ? `<div class="text-success small">Diupload pada ${app.escapeHtml(assignmentSubmission.date)}</div>` : ''}
                                </div>
                                <div class="d-grid gap-2">
                                    <a href="${app.absoluteCampusUrl(assignmentFileInfo.href)}" target="_blank" rel="noreferrer" class="btn btn-primary">Lihat tugas</a>
                                    ${app.buildCampusDownloadUrl(assignmentFileInfo.href) ? `<a href="${app.buildCampusDownloadUrl(assignmentFileInfo.href)}" class="btn btn-outline-primary">Download tugas</a>` : ''}
                                    ${assignmentDeletePayload ? '<button type="button" class="btn btn-outline-danger" id="assignment-delete" data-online-required>Hapus tugas</button>' : ''}
                                </div>
                                ${assignmentSubmission?.score ? `<div class="text-secondary small mt-3">Nilai: ${app.escapeHtml(assignmentSubmission.score)}</div>` : ''}
                            </div>
                        </div>
                        ` : assignmentParams ? `
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Upload Tugas</h3>
                            </div>
                            <div class="card-body">
                                <form id="assignment-form">
                                    <div class="mb-3">
                                        <label for="assignment-file" class="form-label">File tugas (PDF)</label>
                                        <input id="assignment-file" type="file" accept=".pdf" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="assignment-note" class="form-label">Keterangan opsional</label>
                                        <textarea id="assignment-note" class="form-control" rows="2" placeholder="Tambahkan catatan..."></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100" data-online-required>Kirim tugas</button>
                                </form>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                `;

                const contentParts = [];

                if (materials.main_topic || materials.main_description) {
                    contentParts.push(`
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="text-secondary text-uppercase small mb-2 d-flex align-items-center gap-2">
                                            <span class="badge bg-primary-lt">Materi Utama</span>
                                        </div>
                                        <h3 class="h3 mb-2">${app.escapeHtml(materials.main_topic || 'Materi')}</h3>
                                        ${materials.main_description ? `<div class="text-secondary mb-0">${app.escapeHtml(materials.main_description)}</div>` : ''}
                                    </div>
                                    <div class="col-12 col-md-auto mt-3 mt-md-0">
                                        ${materials.main_material_iframe
                                            ? `<div class="d-flex flex-wrap gap-2"><a href="${app.absoluteCampusUrl(materials.main_material_iframe)}" target="_blank" rel="noreferrer" class="btn btn-primary">Buka materi</a><a href="${app.buildCampusDownloadUrl(materials.main_material_iframe)}" class="btn btn-outline-primary">Download</a></div>`
                                            : app.renderDisabledButton('Tidak ada lampiran')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `);
                }

                if (materials.assignment_topic || materials.assignment_instructions || materials.assignment_material_iframe) {
                    contentParts.push(`
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="text-secondary text-uppercase small mb-2 d-flex align-items-center gap-2">
                                            <span class="badge bg-warning-lt">Tugas Perkuliahan</span>
                                        </div>
                                        <h3 class="h3 mb-2">${app.escapeHtml(materials.assignment_topic || assignmentInfo.upload_text || 'Instruksi tugas')}</h3>
                                        ${materials.assignment_instructions ? `<div class="text-secondary mb-0">Batas waktu: <span class="fw-medium text-warning">${app.escapeHtml(materials.assignment_instructions)}</span></div>` : ''}
                                    </div>
                                    <div class="col-12 col-md-auto mt-3 mt-md-0">
                                        ${materials.assignment_material_iframe
                                            ? `<div class="d-flex flex-wrap gap-2"><a href="${app.absoluteCampusUrl(materials.assignment_material_iframe)}" target="_blank" rel="noreferrer" class="btn btn-outline-warning">Lampiran tugas</a><a href="${app.buildCampusDownloadUrl(materials.assignment_material_iframe)}" class="btn btn-warning">Download</a></div>`
                                            : app.renderDisabledButton('Tidak ada lampiran')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `);
                }

                document.getElementById('meeting-content').innerHTML = contentParts.length 
                    ? `<div class="d-flex flex-column">${contentParts.join('')}</div>`
                    : app.renderEmptyState('Belum ada materi atau instruksi tambahan pada pertemuan ini.', 'secondary');

                app.refreshOnlineRequiredState();
                const attendanceForm = document.getElementById('attendance-form');

                if (attendanceForm) {
                    app.bindAttendanceQrTools(attendanceForm, document.getElementById('attendance-code'), {
                        onDetected: () => {
                            app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                            app.requestFormSubmit(attendanceForm);
                        },
                    });
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
                        app.clearCachedResponse('/api/dashboard');
                        app.clearCachedResponse('/api/attendance/today');
                        app.clearCachedResponse(endpoint);
                        await loadMeeting({ force: true });
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

                const assignmentDeleteButton = document.getElementById('assignment-delete');

                assignmentDeleteButton?.addEventListener('click', async () => {
                    if (!app.requireOnlineAction('Hapus tugas butuh koneksi internet.')) {
                        return;
                    }

                    if (!window.confirm('Hapus tugas yang sudah diupload untuk meeting ini?')) {
                        return;
                    }

                    assignmentDeleteButton.disabled = true;

                    try {
                        const result = await app.request('/api/assignment', {
                            method: 'DELETE',
                            json: assignmentDeletePayload,
                        });

                        app.showNotice(result?.message || 'Tugas berhasil dihapus.', 'success');
                        await loadMeeting({ force: true });
                    } catch (error) {
                        app.showNotice(error.payload?.message || error.message, 'error');
                    } finally {
                        assignmentDeleteButton.disabled = false;
                    }
                });
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
