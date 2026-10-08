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
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="mycic-section-title mb-0" id="dashboard-today-title">Hari ini</h2>
                <span class="text-sm text-ink-subtle" id="dashboard-today-date"></span>
            </div>
            <div id="dashboard-today-notice"></div>
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

                // A GPA of 0.00 means "no grades yet" for a new student, not a zero.
                const gpaValue = parseFloat(String(overview.gpa || '').replace(',', '.'));
                const gpaLabel = Number.isFinite(gpaValue) && gpaValue > 0 ? overview.gpa : '–';

                [
                    classesSummary.total_classes || overview.total_courses || '0',
                    gpaLabel,
                    classesSummary.total_credits || overview.total_credits || '0',
                ].forEach((value, index) => {
                    const node = document.querySelectorAll('[data-dashboard-metric]')[index];
                    if (node) {
                        node.textContent = String(value || '-');
                        if (index === 1) {
                            node.title = gpaLabel === '–' ? 'Belum ada nilai yang keluar' : '';
                        }
                    }
                });

                latestClassesSummary = classesSummary;

                // The snapshot may still describe yesterday while today's is being fetched.
                const todayIso = new Date().toLocaleDateString('sv-SE');
                const snapshotIsToday = !classesSummary.date || classesSummary.date === todayIso;
                const titleNode = document.getElementById('dashboard-today-title');
                const dateNode = document.getElementById('dashboard-today-date');
                const noticeNode = document.getElementById('dashboard-today-notice');
                const longDate = classesSummary.date
                    ? new Date(`${classesSummary.date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' })
                    : dayLabel;

                titleNode.textContent = snapshotIsToday ? 'Hari ini' : `Jadwal ${dayLabel}`;
                dateNode.textContent = snapshotIsToday ? longDate : `${longDate} · data lama`;
                noticeNode.innerHTML = snapshotIsToday ? '' : `
                    <div class="${app.alertTone('info')} mb-3 flex items-center gap-2">
                        <span class="mycic-spinner shrink-0" aria-hidden="true"></span>
                        <span>Jadwal hari ini sedang diambil dari portal kampus. Yang tampil di bawah masih jadwal ${app.escapeHtml(dayLabel)}.</span>
                    </div>
                `;

                // Where the quick QR button in the mobile nav should go.
                const scannable = todayClasses.find((klass) => klass.attendance?.actionable && klass.attendance?.meeting_url);
                app.setQuickQrTarget(
                    scannable ? `${scannable.attendance.meeting_url}&qr=1` : null,
                    todayClasses.length
                        ? 'Absensi untuk kelas hari ini belum dibuka dosen. Coba lagi saat kelas berlangsung.'
                        : `Tidak ada kelas ${dayLabel}, jadi tidak ada absensi yang perlu diisi.`
                );

                if (!todayClasses.length) {
                    summaryHost.innerHTML = app.renderEmptyState(`Tidak ada jadwal ${dayLabel}.`, 'secondary', 'Selamat beristirahat.');

                    return;
                }

                summaryHost.innerHTML = todayClasses.map((klass, index) => {
                    const attendance = klass.attendance || {};
                    const targetReliable = attendance.target_reliable !== false;
                    let statusColor = 'secondary';
                    if (attendance.actionable) statusColor = 'primary';
                    else if (attendance.status?.already_marked && targetReliable) statusColor = 'success';
                    else if (attendance.available && targetReliable) statusColor = 'warning';

                    const materialTitle = attendance.today_material?.title;
                    const materialIsToday = attendance.today_material?.is_today !== false;
                    const materialLabel = materialTitle && materialTitle !== 'Materi terbaru belum terdeteksi.' ? materialTitle : null;
                    // Without today's material, "Buka" leads to the class page, not an old meeting.
                    const openUrl = targetReliable ? attendance.meeting_url : (attendance.class_url || attendance.meeting_url);

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
                                    ${openUrl
                                        ? `<a href="${app.escapeHtml(openUrl)}" class="${app.buttonClass('secondary', { small: true })}">Buka</a>`
                                        : ''}
                                </div>
                            </div>

                            ${(attendance.meeting_number || materialLabel) && (materialIsToday || materialLabel) ? `
                                <div class="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-sm text-ink-subtle">
                                    ${materialIsToday && attendance.meeting_number ? `<span>P${app.escapeHtml(attendance.meeting_number)}</span>` : ''}
                                    ${materialLabel ? `<span class="italic">${materialIsToday ? '' : 'Materi terakhir: '}${app.escapeHtml(materialLabel)}</span>` : ''}
                                </div>
                            ` : ''}

                            <div class="${panelTone(statusColor)}">
                                ${!targetReliable ? `
                                    <div class="text-sm text-ink-subtle">Materi hari ini belum diunggah dosen, jadi absensi belum bisa dibuka dari sini.</div>
                                ` : attendance.actionable ? `
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
                                ` : attendance.status?.already_marked ? `
                                    <div class="text-sm font-medium ${textTone(statusColor)}">
                                        ${app.escapeHtml(attendance.status?.label || 'Hadir')}${attendance.status?.time ? ` · ${app.escapeHtml(attendance.status.time)}` : ''}
                                    </div>
                                ` : attendance.available ? `
                                    <div class="flex flex-wrap items-center justify-between gap-2 ${textTone(statusColor)}">
                                        <span class="text-sm font-medium">Absensi tersedia</span>
                                        <a href="${app.escapeHtml(attendance.meeting_url || '/class')}" class="text-xs font-bold underline">Buka</a>
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

                        if (!app.requireOnlineAction('Absen butuh koneksi internet.')) {
                            return;
                        }

                        const kode = form.querySelector('[data-field="kode"]').value.trim();

                        await app.guardFormSubmit(form, () => app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
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

                                app.showNotice(
                                    result?.data?.status === 'already_marked'
                                        ? 'Kehadiran kamu sudah tercatat untuk pertemuan ini.'
                                        : 'Absensi berhasil dikirim.',
                                    'success'
                                );
                                app.clearCachedResponse('/api/dashboard');
                                app.clearCachedResponse('/api/attendance/today');

                                if (klass?.attendance?.meeting_url) {
                                    app.clearCachedResponse(
                                        klass.attendance.meeting_url.replace('/meeting/', '/api/meeting/')
                                    );
                                }

                                // The server already shows this class as attended, so
                                // re-reading its snapshot is instant (no portal scrape).
                                await loadDashboard();
                            } catch (error) {
                                if (error.isTimeout) {
                                    app.showNotice('Portal lambat merespons. Mengecek status absensi...', 'warning');
                                    await loadDashboard();

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
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

                const describeDeadline = (assignment) => {
                    if (!assignment.deadline_date) {
                        return { label: assignment.deadline || null, tone: 'brand' };
                    }

                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const due = new Date(`${assignment.deadline_date}T00:00:00`);
                    const daysLeft = Math.round((due - today) / 86400000);

                    if (daysLeft < 0) {
                        return { label: `Lewat ${Math.abs(daysLeft)} hari · ${assignment.deadline}`, tone: 'danger' };
                    }
                    if (daysLeft === 0) {
                        return { label: `Hari ini · ${assignment.deadline}`, tone: 'danger' };
                    }
                    if (daysLeft === 1) {
                        return { label: `Besok · ${assignment.deadline}`, tone: 'warning' };
                    }
                    if (daysLeft <= 3) {
                        return { label: `${daysLeft} hari lagi · ${assignment.deadline}`, tone: 'warning' };
                    }

                    return { label: `${daysLeft} hari lagi · ${assignment.deadline}`, tone: 'brand' };
                };

                assignmentHost.innerHTML = pendingAssignments.map((assignment, index) => {
                    const deadline = describeDeadline(assignment);
                    const statusColor = deadline.tone;

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
                                ${deadline.label ? `
                                    <div class="text-sm font-semibold ${textTone(statusColor)}">${app.escapeHtml(deadline.label)}</div>
                                ` : ''}
                            </div>

                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                ${assignment.assignment_file_url
                                    ? `<a href="${app.escapeHtml(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Lampiran</a>`
                                    : '<span></span>'}
                                <a href="${app.escapeHtml(assignment.meeting_url)}" class="${app.buttonClass('secondary', { small: true })}">Detail</a>
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

                        await app.guardFormSubmit(form, () => app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                const operationId = result?.data?.operation_id;

                                if (operationId) {
                                    // Keep the form locked until the portal has the file;
                                    // on completion the list re-renders without this task.
                                    await app.trackOperation(operationId, 'Upload tugas');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                    await loadPendingAssignments();
                                }
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
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
                    app.mountErrorState(
                        document.getElementById('dashboard-class-summary'),
                        'Jadwal hari ini gagal dimuat.',
                        () => loadDashboard({ force }),
                        { subtitle: app.describeError(error) }
                    );
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
                    app.mountErrorState(
                        document.getElementById('dashboard-pending-assignments'),
                        'Daftar tugas gagal dimuat.',
                        () => loadPendingAssignments({ force }),
                        { subtitle: app.describeError(error) }
                    );
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
