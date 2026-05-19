@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_id', 'dashboard')

@section('content')

    <div class="row row-cards mb-4">
        @foreach ([
            ['label' => 'Mata kuliah', 'note' => 'Jumlah kelas yang sedang aktif.'],
            ['label' => 'IPK', 'note' => 'Ringkasan terakhir.'],
            ['label' => 'Total SKS', 'note' => 'Kredit terkumpul.'],
            ['label' => 'Tagihan', 'note' => 'Nominal yang perlu dipantau.'],
        ] as $metric)
            <div class="col-sm-6 col-xl-3">
                <div class="card card-sm h-100">
                    <div class="card-body">
                        <div class="text-secondary text-uppercase small mb-1">{{ $metric['label'] }}</div>
                        <div class="h1 mb-1" data-dashboard-metric>-</div>
                        <div class="text-secondary small">{{ $metric['note'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row row-cards">
        <div class="col-lg-6">
            <h2 class="h3 mb-3">Jadwal hari ini</h2>
            <div id="dashboard-class-summary">
                {!! '<div class="alert alert-secondary mb-0 shadow-sm">Memuat jadwal kuliah hari ini.</div>' !!}
            </div>
        </div>

        <div class="col-lg-6">
            <h2 class="h3 mb-3">Tugas aktif</h2>
            <div id="dashboard-pending-assignments">
                {!! '<div class="alert alert-secondary mb-0 shadow-sm">Memuat daftar tugas yang belum dikumpulkan.</div>' !!}
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            let loadDashboard = async () => {};
            let loadPendingAssignments = async () => {};
            let latestClassesSummary = null;
            let latestAssignments = [];

            const renderDashboard = (payload) => {
                const data = payload?.data || {};
                const overview = data.dashboard_overview || {};
                const classesSummary = data.classes_summary || {};
                const summaryHost = document.getElementById('dashboard-class-summary');
                const todayClasses = classesSummary.today_classes || [];
                const dayLabel = classesSummary.day || 'Hari ini';

                [
                    classesSummary.total_classes || overview.total_courses || '0',
                    overview.gpa || '0.00',
                    classesSummary.total_credits || overview.total_credits || '0',
                    overview.outstanding_payments || 'Rp 0',
                ].forEach((value, index) => {
                    document.querySelectorAll('[data-dashboard-metric]')[index].textContent = value || '-';
                });

                latestClassesSummary = classesSummary;
                app.setSyncStatus(
                    todayClasses.length ? `Jadwal ${dayLabel.toLowerCase()} sedang disiapkan.` : 'Belum ada jadwal kuliah untuk hari ini.',
                    todayClasses.length ? 'info' : 'warning'
                );

                if (!todayClasses.length) {
                    summaryHost.innerHTML = app.renderEmptyState(`Tidak ada jadwal kuliah untuk ${dayLabel}.`, 'secondary');

                    return;
                }

                summaryHost.innerHTML = todayClasses.map((klass, index) => {
                    let statusColor = 'secondary';
                    if (klass.attendance?.actionable) statusColor = 'primary';
                    else if (klass.attendance?.status?.already_marked) statusColor = 'success';
                    else if (klass.attendance?.available) statusColor = 'warning';

                    return `
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="row g-3 align-items-center mb-3">
                                <div class="col">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <div class="text-secondary small fw-semibold d-flex align-items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-clock" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                                            ${app.escapeHtml(klass.schedule?.time || 'Jadwal belum tersedia')}
                                        </div>
                                        <span class="text-secondary">·</span>
                                        <div class="text-secondary small fw-semibold text-uppercase">${app.escapeHtml([klass.course_code, klass.class_name].filter(Boolean).join(' · ') || 'Kelas')}</div>
                                    </div>
                                    <h3 class="h2 mb-1 fw-bold text-dark">${app.escapeHtml(klass.course_name || 'Kelas')}</h3>
                                    <div class="text-muted d-flex flex-wrap align-items-center gap-2 small">
                                        <span class="d-flex align-items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-user" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
                                            ${app.escapeHtml(klass.lecturer_name || 'Dosen belum tersedia')}
                                        </span>
                                        <span class="text-secondary">·</span>
                                        <span class="d-flex align-items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-map-pin" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 11a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /><path d="M17.657 16.657l-4.243 4.243a2 2 0 0 1 -2.827 0l-4.244 -4.243a8 8 0 1 1 11.314 0z" /></svg>
                                            ${app.escapeHtml(klass.room || '-')}
                                        </span>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    ${klass.attendance?.meeting_url
                                        ? `<a href="${app.escapeHtml(klass.attendance.meeting_url)}" class="btn btn-outline-secondary btn-icon" aria-label="Detail meeting">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-arrow-right" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0" /><path d="M13 18l6 -6" /><path d="M13 6l6 6" /></svg>
                                           </a>`
                                        : app.renderDisabledButton('Detail meeting')}
                                </div>
                            </div>

                            <hr class="my-3">

                            <div class="mb-3 d-flex flex-wrap gap-3 text-secondary small">
                                <div><strong>Pertemuan:</strong> ${app.escapeHtml(klass.attendance?.meeting_number || '-')}</div>
                                <div><strong>Materi:</strong> <span class="fst-italic">${app.escapeHtml(klass.attendance?.today_material?.title && klass.attendance?.today_material?.title !== 'Materi terbaru belum terdeteksi.' ? klass.attendance?.today_material?.title : '-')}</span></div>
                            </div>

                            <div class="bg-${statusColor}-lt p-3 rounded">
                                ${klass.attendance?.actionable ? `
                                    <div class="fw-bold text-${statusColor} mb-2">Waktunya Absensi!</div>
                                    <form class="row g-2 align-items-center" data-attendance-card="${index}">
                                        <div class="col-md-5">
                                            <input type="text" class="form-control bg-white" data-field="kode" placeholder="Kode / PIN" required>
                                        </div>
                                        <div class="col-md-auto d-grid">
                                            <button type="button" class="btn btn-outline-${statusColor} bg-white" data-qr-scan>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-qrcode" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 17l0 .01" /><path d="M14 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 7l0 .01" /><path d="M4 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M17 7l0 .01" /><path d="M14 14l3 0" /><path d="M20 14l0 .01" /><path d="M14 14l0 3" /><path d="M14 20l3 0" /><path d="M17 17l3 0" /><path d="M20 17l0 3" /></svg>
                                                Scan QR
                                            </button>
                                        </div>
                                        <div class="col-md-auto">
                                            <input type="file" class="form-control bg-white d-none" id="qr-file-${index}" data-qr-file accept="image/*">
                                            <label class="btn btn-outline-${statusColor} bg-white mb-0" for="qr-file-${index}">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-photo" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 8h.01" /><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z" /><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5" /><path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3" /></svg>
                                                Upload QR
                                            </label>
                                        </div>
                                        <div class="col-md-auto ms-auto d-grid mt-3 mt-md-0">
                                            <button type="submit" class="btn btn-${statusColor}" data-online-required>Kirim Absen</button>
                                        </div>
                                    </form>
                                ` : klass.attendance?.status?.already_marked ? `
                                    <div class="d-flex align-items-center gap-2 text-${statusColor}">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-check" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                                        <div>
                                            <div class="fw-semibold">${app.escapeHtml(klass.attendance?.status?.label || 'Sudah tercatat')}</div>
                                            <div class="small opacity-75">${app.escapeHtml(klass.attendance?.status?.time ? `Tercatat ${klass.attendance.status.time}.` : 'Absensi sudah tercatat.')}</div>
                                        </div>
                                    </div>
                                ` : klass.attendance?.available ? `
                                    <div class="d-flex align-items-center gap-2 text-${statusColor}">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-alert-circle" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 8v4" /><path d="M12 16h.01" /></svg>
                                        <span class="fw-semibold">Absensi tersedia, buka detail kelas untuk melanjutkan.</span>
                                    </div>
                                ` : `
                                    <div class="text-secondary small">Belum ada absensi aktif untuk sesi ini.</div>
                                `}
                            </div>
                        </div>
                    </div>
                    `;
                }).join('');

                summaryHost.querySelectorAll('[data-attendance-card]').forEach((form) => {
                    const classIndex = Number(form.dataset.attendanceCard);
                    const klass = Number.isInteger(classIndex) ? todayClasses[classIndex] : null;
                    const attendancePayload = klass?.attendance?.payload || null;
                    const shortcutContext = klass?.attendance?.context || null;

                    if (!attendancePayload && !shortcutContext) {
                        return;
                    }

                    app.bindAttendanceQrTools(form, form.querySelector('[data-field="kode"]'), {
                        onDetected: () => {
                            app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                            app.requestFormSubmit(form);
                        },
                    });

                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (form.dataset.submitting === 'true') {
                            return;
                        }

                        if (!app.requireOnlineAction('Absen butuh koneksi internet.')) {
                            return;
                        }

                        const kode = form.querySelector('[data-field="kode"]').value.trim();

                        form.dataset.submitting = 'true';

                        try {
                            const body = attendancePayload
                                ? {
                                    ...attendancePayload,
                                    kode,
                                }
                                : {
                                    kode,
                                    shortcut_context: shortcutContext,
                                };

                            const result = await app.request('/api/attendance', {
                                method: 'POST',
                                json: body,
                            });

                            app.showNotice(result?.message || 'Absensi berhasil dikirim.', 'success');
                            app.clearCachedResponse('/api/dashboard');
                            app.clearCachedResponse('/api/attendance/today');

                            if (klass?.attendance?.meeting_url) {
                                app.clearCachedResponse(
                                    klass.attendance.meeting_url.replace('/meeting/', '/api/meeting/')
                                );
                            }

                            await loadDashboard({ force: true });
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        } finally {
                            delete form.dataset.submitting;
                        }
                    });
                });

                app.refreshOnlineRequiredState();
            };

            const renderPendingAssignments = (payload) => {
                const assignmentHost = document.getElementById('dashboard-pending-assignments');
                const pendingAssignments = payload?.data?.assignments || [];
                latestAssignments = pendingAssignments;

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = app.renderEmptyState('Tidak ada tugas yang menunggu dikumpulkan.', 'secondary');

                    return;
                }

                assignmentHost.innerHTML = pendingAssignments.map((assignment, index) => {
                    const isUrgent = /hari ini/i.test(String(assignment.deadline || ''));
                    const statusColor = isUrgent ? 'warning' : 'info';

                    return `
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
                                <div class="me-auto">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <div class="text-secondary small fw-semibold text-uppercase">${app.escapeHtml([assignment.course_code, assignment.lecturer_name].filter(Boolean).join(' · ') || '')}</div>
                                    </div>
                                    <h3 class="h2 mb-1 fw-bold text-dark">${app.escapeHtml(assignment.course_name || 'Tugas')}</h3>
                                    ${assignment.material_title && assignment.material_title !== assignment.course_name ? `
                                        <p class="text-secondary mb-0 small">${app.escapeHtml(assignment.material_title)}</p>
                                    ` : ''}
                                </div>
                                ${assignment.deadline ? `
                                    <div class="text-${statusColor} small fw-bold d-flex align-items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-clock" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                                        ${app.escapeHtml(assignment.deadline)}
                                    </div>
                                ` : ''}
                            </div>

                            <hr class="my-3">

                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                <div class="d-flex flex-wrap gap-2">
                                    ${assignment.assignment_file_url
                                        ? `<a href="${app.escapeHtml(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="btn btn-outline-secondary btn-sm">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-paperclip" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 0 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 9 9l6.5 -6.5" /></svg>
                                            Buka Lampiran
                                           </a>`
                                        : app.renderDisabledButton('Lampiran belum ada', 'btn-sm')}
                                </div>
                                <a href="${app.escapeHtml(assignment.meeting_url)}" class="text-decoration-none text-primary fw-semibold small d-flex align-items-center gap-1">
                                    Detail Meeting
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-external-link" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
                                </a>
                            </div>

                            <div class="bg-${statusColor}-lt p-3 rounded">
                                ${assignment.can_submit_directly ? `
                                    <div class="fw-bold text-${statusColor} mb-2 d-flex align-items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-upload" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg>
                                        Kumpulkan File (.pdf)
                                    </div>
                                    <form class="row g-2" data-assignment-card="${index}">
                                        <div class="col-md-8">
                                            <input type="file" class="form-control bg-white" data-field="attachment" accept=".pdf" required>
                                        </div>
                                        <div class="col-md-4 d-grid">
                                            <button type="submit" class="btn btn-${statusColor}" data-online-required>Upload Tugas</button>
                                        </div>
                                    </form>
                                ` : `
                                    <div class="text-secondary small d-flex align-items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-info-circle" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>
                                        Buka Detail Meeting untuk instruksi pengumpulan tugas ini.
                                    </div>
                                `}
                            </div>
                        </div>
                    </div>
                    `;
                }).join('');

                assignmentHost.querySelectorAll('[data-assignment-card]').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.')) {
                            return;
                        }

                        const assignmentIndex = Number(form.dataset.assignmentCard);
                        const candidate = Number.isInteger(assignmentIndex) ? pendingAssignments[assignmentIndex] : null;
                        const file = form.querySelector('[data-field="attachment"]').files[0];

                        if (!file) {
                            app.showNotice('Pilih file tugas terlebih dahulu.', 'warning');

                            return;
                        }

                        if (!candidate?.assignment_payload) {
                            app.showNotice('Tugas ini perlu dibuka dari detail meeting terlebih dahulu.', 'warning');

                            return;
                        }

                        const formData = new FormData();
                        formData.append('courses_id', candidate.assignment_payload.courses_id);
                        formData.append('id', candidate.assignment_payload.id);
                        formData.append('pertemuan', candidate.assignment_payload.pertemuan);
                        formData.append('attachment', file);

                        try {
                            const result = await app.request('/api/assignment', {
                                method: 'POST',
                                formData,
                            });

                            app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                            await loadPendingAssignments();
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        }
                    });
                });

                app.refreshOnlineRequiredState();
            };

            app.primeCachedView('/api/dashboard', renderDashboard, 'ringkasan dashboard');
            app.primeCachedView('/api/assignment/pending', renderPendingAssignments, 'tugas belum dikumpulkan');

            const ready = await app.ensureAuthenticated();
            let dashboardOverviewHealthy = false;

            if (!ready) {
                return;
            }

            loadDashboard = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/dashboard', { fresh: force });
                    renderDashboard(payload);
                    dashboardOverviewHealthy = true;
                    app.markViewSynced('Dashboard', payload, { force });
                } catch (error) {
                    dashboardOverviewHealthy = false;
                    app.setSyncStatus('Gagal memuat ringkasan dashboard.', 'error');
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            loadPendingAssignments = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/assignment/pending', { fresh: force });
                    renderPendingAssignments(payload);

                    if (dashboardOverviewHealthy) {
                        app.markViewSynced('Tugas', payload, { force });
                    }
                } catch (error) {
                    app.setSyncStatus('Gagal memuat daftar tugas yang belum dikumpulkan.', 'error');
                    document.getElementById('dashboard-pending-assignments').innerHTML = app.renderEmptyState('Gagal memuat daftar tugas yang belum dikumpulkan.', 'danger');
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('dashboard-overview', loadDashboard);
            app.registerSyncHandler('dashboard-pending-assignments', loadPendingAssignments);

            await loadDashboard();
            await loadPendingAssignments();
        });
    </script>
@endpush
