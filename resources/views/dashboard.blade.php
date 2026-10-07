@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_id', 'dashboard')

@section('content')
    <section class="mycic-hero mb-5" aria-label="Ringkasan akun">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-sm font-bold text-white border border-white/20" aria-hidden="true" data-user-avatar>M</span>
            <div class="min-w-0">
                <div class="text-xs font-medium text-white/75">Halo,</div>
                <div class="truncate text-base font-bold text-white sm:text-lg" data-user-name>Mahasiswa</div>
            </div>
        </div>
    </section>

    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-3 gap-3">
            @foreach (['Matkul', 'IPK', 'SKS'] as $label)
                <div class="mycic-metric">
                    <div class="mycic-metric-label">{{ $label }}</div>
                    <div class="mycic-metric-value" data-dashboard-metric>-</div>
                </div>
            @endforeach
        </div>

        <section aria-label="Jadwal hari ini">
            <h2 class="mycic-section-title mb-3">Hari ini</h2>
            <div id="dashboard-class-summary">
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-5 w-2/3"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
            </div>
        </section>

        <section aria-label="Tugas aktif">
            <h2 class="mycic-section-title mb-3">Tugas</h2>
            <div id="dashboard-pending-assignments">
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-5 w-2/3"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
            </div>
        </section>
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

            const panelTone = (color) => app.statusPanelClass(color === 'primary' ? 'brand' : color);
            const textTone = (color) => app.textToneClass(color === 'primary' ? 'brand' : color);

            const renderDashboard = (payload) => {
                const data = payload?.data || {};
                if (data.user_info && (data.user_info.name || data.user_info.nama || data.user_info.student_id || data.user_info.nim)) {
                    app.hydrateUser(payload);
                }
                const overview = data.dashboard_overview || {};
                const classesSummary = data.classes_summary || {};
                const summaryHost = document.getElementById('dashboard-class-summary');
                const todayClasses = classesSummary.today_classes || [];
                const dayLabel = classesSummary.day || 'Hari ini';

                [
                    classesSummary.total_classes || overview.total_courses || '0',
                    overview.gpa || '0.00',
                    classesSummary.total_credits || overview.total_credits || '0',
                ].forEach((value, index) => {
                    const node = document.querySelectorAll('[data-dashboard-metric]')[index];
                    if (node) {
                        node.textContent = String(value || '-');
                    }
                });

                latestClassesSummary = classesSummary;

                if (!todayClasses.length) {
                    summaryHost.innerHTML = app.renderEmptyState(`Tidak ada jadwal ${dayLabel}.`, 'secondary');

                    return;
                }

                summaryHost.innerHTML = todayClasses.map((klass, index) => {
                    let statusColor = 'secondary';
                    if (klass.attendance?.actionable) statusColor = 'primary';
                    else if (klass.attendance?.status?.already_marked) statusColor = 'success';
                    else if (klass.attendance?.available) statusColor = 'warning';

                    const materialTitle = klass.attendance?.today_material?.title;
                    const materialLabel = materialTitle && materialTitle !== 'Materi terbaru belum terdeteksi.' ? materialTitle : null;

                    return `
                    <div class="mycic-card mb-3">
                        <div class="mycic-card-body">
                            <div class="mb-3 flex flex-wrap items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex flex-wrap items-center gap-2 text-sm text-ink-subtle">
                                        <span class="font-medium">${app.escapeHtml(klass.schedule?.time || '-')}</span>
                                        <span>·</span>
                                        <span class="font-medium uppercase">${app.escapeHtml([klass.course_code, klass.class_name].filter(Boolean).join(' · ') || 'Kelas')}</span>
                                    </div>
                                    <h3 class="mb-1 text-title font-bold text-ink">${app.escapeHtml(klass.course_name || 'Kelas')}</h3>
                                    <div class="text-sm text-ink-muted">
                                        ${app.escapeHtml(klass.lecturer_name || '-')} · ${app.escapeHtml(klass.room || '-')}
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    ${klass.attendance?.meeting_url
                                        ? `<a href="${app.escapeHtml(klass.attendance.meeting_url)}" class="${app.buttonClass('secondary', { small: true })}">Buka</a>`
                                        : ''}
                                </div>
                            </div>

                            ${(klass.attendance?.meeting_number || materialLabel) ? `
                                <div class="mb-3 flex flex-wrap gap-3 text-sm text-ink-subtle">
                                    ${klass.attendance?.meeting_number ? `<span>P${app.escapeHtml(klass.attendance.meeting_number)}</span>` : ''}
                                    ${materialLabel ? `<span class="italic">${app.escapeHtml(materialLabel)}</span>` : ''}
                                </div>
                            ` : ''}

                            <div class="${panelTone(statusColor)}">
                                ${klass.attendance?.actionable ? `
                                    <form class="flex flex-wrap items-center gap-2" data-attendance-card="${index}">
                                        <div class="w-full min-w-0 md:w-auto md:min-w-[12rem] md:flex-1">
                                            <input type="text" class="mycic-input bg-panel" data-field="kode" placeholder="Kode absen" required>
                                        </div>
                                        <button type="button" class="${app.buttonClass('secondary')} bg-panel" data-qr-scan>Scan QR</button>
                                        <div class="shrink-0">
                                            <input type="file" class="mycic-input hidden" id="qr-file-${index}" data-qr-file accept="image/*">
                                            <label class="${app.buttonClass('secondary')} mb-0 bg-panel" for="qr-file-${index}">Upload QR</label>
                                        </div>
                                        <button type="submit" class="${app.buttonClass('primary')} md:ms-auto" data-online-required>Kirim</button>
                                    </form>
                                ` : klass.attendance?.status?.already_marked ? `
                                    <div class="text-sm font-medium ${textTone(statusColor)}">
                                        ${app.escapeHtml(klass.attendance?.status?.label || 'Hadir')}${klass.attendance?.status?.time ? ` · ${app.escapeHtml(klass.attendance.status.time)}` : ''}
                                    </div>
                                ` : klass.attendance?.available ? `
                                    <div class="flex flex-wrap items-center justify-between gap-2 ${textTone(statusColor)}">
                                        <span class="text-sm font-medium">Absensi tersedia</span>
                                        <a href="${app.escapeHtml(klass.attendance?.meeting_url || '/class')}" class="text-xs font-bold underline">Buka</a>
                                    </div>
                                ` : `
                                    <div class="text-sm text-ink-subtle">Belum ada absensi.</div>
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

                        await app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
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
                            }
                        });
                    });
                });

                app.refreshOnlineRequiredState();
            };

            const renderPendingAssignments = (payload) => {
                const assignmentHost = document.getElementById('dashboard-pending-assignments');
                const pendingAssignments = payload?.data?.assignments || [];
                latestAssignments = pendingAssignments;

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = app.renderEmptyState('Tidak ada tugas.', 'secondary');

                    return;
                }

                assignmentHost.innerHTML = pendingAssignments.map((assignment, index) => {
                    const isUrgent = /hari ini/i.test(String(assignment.deadline || ''));
                    const statusColor = isUrgent ? 'warning' : 'brand';

                    return `
                    <div class="mycic-card mb-3">
                        <div class="mycic-card-body">
                            <div class="mb-3 flex flex-wrap items-start gap-2">
                                <div class="me-auto min-w-0">
                                    <div class="mb-1 text-sm font-medium uppercase text-ink-subtle">${app.escapeHtml([assignment.course_code, assignment.lecturer_name].filter(Boolean).join(' · ') || '')}</div>
                                    <h3 class="mb-1 text-title font-bold text-ink">${app.escapeHtml(assignment.course_name || 'Tugas')}</h3>
                                    ${assignment.material_title && assignment.material_title !== assignment.course_name ? `
                                        <p class="mb-0 text-sm text-ink-subtle">${app.escapeHtml(assignment.material_title)}</p>
                                    ` : ''}
                                </div>
                                ${assignment.deadline ? `
                                    <div class="text-sm font-semibold ${textTone(statusColor)}">${app.escapeHtml(assignment.deadline)}</div>
                                ` : ''}
                            </div>

                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                ${assignment.assignment_file_url
                                    ? `<a href="${app.escapeHtml(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Lampiran</a>`
                                    : '<span></span>'}
                                <a href="${app.escapeHtml(assignment.meeting_url)}" class="mycic-link text-sm font-medium">Detail</a>
                            </div>

                            <div class="rounded-[var(--radius-control)] border border-border bg-surface/75 p-3.5">
                                ${assignment.can_submit_directly ? `
                                    <form class="flex flex-col gap-2 sm:flex-row sm:items-center" data-assignment-card="${index}">
                                        <div class="min-w-0 flex-1">
                                            <input type="file" class="mycic-input bg-panel" data-field="attachment" accept=".pdf" required>
                                        </div>
                                        <button type="submit" class="${app.buttonClass('primary')}" data-online-required>Kirim</button>
                                    </form>
                                ` : `
                                    <div class="text-sm text-ink-subtle">Buka detail untuk mengumpulkan.</div>
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

                        await app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                const operationId = result?.data?.operation_id;

                                if (operationId) {
                                    app.trackOperation(operationId, 'Upload tugas');
                                    app.showNotice('Upload tugas diterima. Status akan diperbarui otomatis.', 'info');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                    await loadPendingAssignments();
                                }
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        });
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
                    app.setSyncStatus('Gagal memuat dashboard.', 'error');
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
                    app.setSyncStatus('Gagal memuat tugas.', 'error');
                    document.getElementById('dashboard-pending-assignments').innerHTML = app.renderEmptyState('Gagal memuat tugas.', 'danger');
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('dashboard-overview', loadDashboard);
            app.registerSyncHandler('dashboard-pending-assignments', loadPendingAssignments);

            await Promise.allSettled([
                loadDashboard(),
                loadPendingAssignments(),
            ]);
        });
    </script>
@endpush
