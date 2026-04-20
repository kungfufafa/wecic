@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_id', 'dashboard')
@section('hide_status_strip', '1')

@section('content')
    <section class="page-shell dashboard-shell">
        <section class="dashboard-focus-grid">
            <article class="section-shell dashboard-focus-card" data-dashboard-focus="class">
                <div class="empty-shell">Menyusun fokus kelas hari ini.</div>
            </article>
            <article class="section-shell dashboard-focus-card" data-dashboard-focus="assignment">
                <div class="empty-shell">Menyusun deadline terdekat.</div>
            </article>
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.18fr)_minmax(21rem,0.82fr)]">
            <section class="section-shell">
                <div class="section-head">
                    <div class="section-head-main">
                        <h2 data-display class="section-title dashboard-section-title">Jadwal hari ini</h2>
                        <p class="section-copy dashboard-section-copy">Buka meeting yang relevan, lalu ekspand panel absensi hanya saat Anda benar-benar siap mengirim.</p>
                    </div>
                </div>
                <div id="dashboard-class-summary" class="mt-6 stack-list">
                    <div class="empty-shell">Memuat jadwal kuliah hari ini.</div>
                </div>
            </section>

            <section class="section-shell">
                <div class="section-head">
                    <div class="section-head-main">
                        <h2 data-display class="section-title dashboard-section-title">Tugas</h2>
                        <p class="section-copy dashboard-section-copy">Lihat deadline lebih dulu, lalu buka panel unggah hanya untuk tugas yang memang siap dikumpulkan sekarang.</p>
                    </div>
                </div>
                <div id="dashboard-pending-assignments" class="mt-6 stack-list">
                    <div class="empty-shell">Memuat daftar tugas yang belum dikumpulkan.</div>
                </div>
            </section>
        </div>

        <div id="dashboard-metrics" class="dashboard-summary-grid">
            @foreach (['Mata kuliah', 'IPK', 'Total SKS', 'Tagihan'] as $metric)
                <article class="summary-tile dashboard-summary-card">
                    <span class="dense-metric-label dashboard-summary-label">{{ $metric }}</span>
                    <strong class="summary-tile-value dashboard-summary-value" data-dashboard-metric>-</strong>
                    <p class="summary-tile-copy dashboard-summary-copy">
                        @if ($metric === 'Mata kuliah')
                            Referensi jumlah kelas yang sedang aktif.
                        @elseif ($metric === 'IPK')
                            Ringkasan akademik terakhir yang tercatat.
                        @elseif ($metric === 'Total SKS')
                            Kredit yang sudah terkumpul sampai saat ini.
                        @else
                            Angka finansial yang masih perlu dipantau.
                        @endif
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            let loadDashboard = async () => {};
            let loadPendingAssignments = async () => {};
            let latestClassesSummary = null;
            let latestAssignments = [];

            const renderFocusStrip = () => {
                const classHost = document.querySelector('[data-dashboard-focus="class"]');
                const assignmentHost = document.querySelector('[data-dashboard-focus="assignment"]');
                const todayClasses = latestClassesSummary?.today_classes || [];
                const nextClass = todayClasses.find((klass) => klass.attendance?.actionable) || todayClasses[0] || null;
                const urgentAssignment = latestAssignments[0] || null;

                if (classHost) {
                    if (!nextClass) {
                        classHost.innerHTML = `
                            <p class="section-label">Fokus kelas</p>
                            <h2 data-display class="dashboard-focus-title">Tidak ada kelas aktif.</h2>
                            <p class="dashboard-focus-copy">Gunakan waktu kosong ini untuk mengecek tugas yang belum dikumpulkan atau membuka ulang materi terakhir.</p>
                            <div class="dashboard-focus-actions">
                                <a href="/class" class="ghost-button">Lihat semua kelas</a>
                            </div>
                        `;
                    } else {
                        const focusBadge = nextClass.attendance?.actionable ? 'Perlu absen' : 'Kelas berikutnya';
                        const actionHref = nextClass.attendance?.meeting_url || '/class';
                        const focusNote = nextClass.attendance?.actionable
                            ? 'Absensi aktif terdeteksi. Buka meeting untuk konteks kelas, lalu buka panel absensi di bawah saat siap.'
                            : 'Buka detail meeting jika Anda perlu materi, presensi, atau instruksi kelas terdekat.';

                        classHost.innerHTML = `
                            <div class="dashboard-focus-head">
                                <div>
                                    <p class="section-label">Fokus kelas</p>
                                    <h2 data-display class="dashboard-focus-title">${app.escapeHtml(nextClass.course_name || 'Kelas hari ini')}</h2>
                                </div>
                                <span class="meta-pill ${nextClass.attendance?.actionable ? 'is-accent' : 'is-sky'} dashboard-pill">${app.escapeHtml(focusBadge)}</span>
                            </div>
                            <p class="dashboard-focus-meta">${app.escapeHtml([nextClass.course_code, nextClass.class_name, nextClass.schedule?.time].filter(Boolean).join(' · ') || 'Jadwal aktif')}</p>
                            <p class="dashboard-focus-copy">${app.escapeHtml(focusNote)}</p>
                            <div class="dashboard-focus-actions">
                                <a href="${app.escapeHtml(actionHref)}" class="primary-button">${nextClass.attendance?.actionable ? 'Buka meeting aktif' : 'Lihat detail meeting'}</a>
                                <a href="/class" class="ghost-button">Daftar kelas</a>
                            </div>
                        `;
                    }
                }

                if (assignmentHost) {
                    if (!urgentAssignment) {
                        assignmentHost.innerHTML = `
                            <p class="section-label">Deadline terdekat</p>
                            <h2 data-display class="dashboard-focus-title">Tidak ada tugas mendesak.</h2>
                            <p class="dashboard-focus-copy">Semua tugas yang tertangkap di dashboard sudah aman atau belum memiliki deadline aktif.</p>
                            <div class="dashboard-focus-actions">
                                <a href="/exam" class="ghost-button">Buka ujian</a>
                            </div>
                        `;
                    } else {
                        const deadlineTone = /hari ini/i.test(String(urgentAssignment.deadline || '')) ? 'is-warning' : 'is-gold';
                        const canDirectSubmit = Boolean(urgentAssignment.can_submit_directly);

                        assignmentHost.innerHTML = `
                            <div class="dashboard-focus-head">
                                <div>
                                    <p class="section-label">Deadline terdekat</p>
                                    <h2 data-display class="dashboard-focus-title">${app.escapeHtml(urgentAssignment.course_name || 'Tugas aktif')}</h2>
                                </div>
                                ${urgentAssignment.deadline ? `<span class="meta-pill ${deadlineTone} dashboard-pill">${app.escapeHtml(urgentAssignment.deadline)}</span>` : ''}
                            </div>
                            <p class="dashboard-focus-meta">${app.escapeHtml([urgentAssignment.course_code, urgentAssignment.lecturer_name].filter(Boolean).join(' · ') || 'Tugas aktif')}</p>
                            <p class="dashboard-focus-copy">${app.escapeHtml(
                                canDirectSubmit
                                    ? 'Berkas bisa dikumpulkan langsung dari dashboard, tetapi panel unggah disembunyikan sampai Anda membutuhkannya.'
                                    : 'Buka detail meeting untuk melihat lampiran, instruksi, dan langkah pengumpulan yang lengkap.'
                            )}</p>
                            <div class="dashboard-focus-actions">
                                <a href="${app.escapeHtml(urgentAssignment.meeting_url || '#dashboard-pending-assignments')}" class="primary-button">${canDirectSubmit ? 'Buka tugas utama' : 'Lihat detail meeting'}</a>
                                <a href="#dashboard-pending-assignments" class="ghost-button">Lihat daftar tugas</a>
                            </div>
                        `;
                    }
                }
            };

            const renderDashboard = (payload) => {
                const data = payload?.data || {};
                const overview = data.dashboard_overview || {};
                const classesSummary = data.classes_summary || {};

                const metrics = [
                    classesSummary.total_classes || overview.total_courses || '0',
                    overview.gpa || '0.00',
                    classesSummary.total_credits || overview.total_credits || '0',
                    overview.outstanding_payments || 'Rp 0',
                ];

                document.querySelectorAll('[data-dashboard-metric]').forEach((element, index) => {
                    element.textContent = metrics[index] || '-';
                });

                const summaryHost = document.getElementById('dashboard-class-summary');
                const todayClasses = classesSummary.today_classes || [];
                const dayLabel = classesSummary.day || 'Hari ini';
                latestClassesSummary = classesSummary;

                app.setSyncStatus(todayClasses.length ? `Jadwal ${dayLabel.toLowerCase()} sedang disiapkan.` : 'Belum ada jadwal kuliah untuk hari ini.', todayClasses.length ? 'info' : 'warning');
                renderFocusStrip();

                if (!todayClasses.length) {
                    summaryHost.innerHTML = `<div class="empty-shell">Tidak ada jadwal kuliah untuk ${app.escapeHtml(dayLabel)}.</div>`;
                } else {
                    summaryHost.innerHTML = `
                        ${todayClasses.map((klass, index) => `
                            <article class="surface-card dashboard-card">
                                <div class="grid gap-4">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div class="space-y-3">
                                            <div class="page-meta-row">
                                                <span class="stack-item-kicker dashboard-card-kicker">${app.escapeHtml([klass.course_code, klass.class_name].filter(Boolean).join(' / ') || 'Kelas')}</span>
                                            </div>
                                            <div>
                                                <h3 class="stack-item-title dashboard-card-title">${app.escapeHtml(klass.course_name || 'Kelas')}</h3>
                                                <p class="surface-card-copy dashboard-card-meta">${app.escapeHtml(klass.lecturer_name || 'Dosen belum tersedia')}</p>
                                            </div>
                                        </div>
                                        <p class="section-meta dashboard-card-time lg:text-right">${app.escapeHtml(klass.schedule?.time || 'Jadwal belum tersedia')}</p>
                                    </div>
                                    <div class="grid gap-3 md:grid-cols-3">
                                        ${[
                                            ['Ruang', klass.room || '-'],
                                            ['Pertemuan', klass.attendance?.meeting_number || '-'],
                                            ['Materi', klass.attendance?.today_material?.title && klass.attendance?.today_material?.title !== 'Materi terbaru belum terdeteksi.' ? klass.attendance?.today_material?.title : '-'],
                                        ].map(([label, value]) => `
                                            <div class="surface-card-soft dashboard-subcard">
                                                <p class="section-label dashboard-subcard-label">${app.escapeHtml(label)}</p>
                                                <p class="surface-card-title dashboard-subcard-value">${app.escapeHtml(value)}</p>
                                            </div>
                                        `).join('')}
                                    </div>
                                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_14rem]">
                                        ${klass.attendance?.actionable ? `
                                            <details class="dashboard-action-disclosure">
                                                <summary class="dashboard-action-summary">
                                                    <div class="dashboard-action-summary-copy">
                                                        <p class="section-label dashboard-subcard-label">Absensi aktif</p>
                                                        <p class="dashboard-action-title">Buka panel absensi saat siap.</p>
                                                    </div>
                                                    <span class="secondary-button dashboard-action-trigger">Buka panel</span>
                                                </summary>
                                                <form class="dashboard-action-form" data-attendance-card="${index}">
                                                    <label class="grid gap-2">
                                                        <span class="section-label dashboard-subcard-label">Kode QR / kode absensi</span>
                                                        <input type="text" class="field-shell" data-field="kode" placeholder="009bfb3e" required>
                                                    </label>
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <button type="button" class="secondary-button justify-center" data-qr-scan>Scan QR</button>
                                                        <label class="secondary-button justify-center cursor-pointer">
                                                            Upload foto
                                                            <input type="file" class="hidden" data-qr-file accept="image/*">
                                                        </label>
                                                    </div>
                                                    <button type="submit" class="primary-button" data-online-required>Absen sekarang</button>
                                                </form>
                                            </details>
                                        ` : klass.attendance?.status?.already_marked ? `
                                            <div class="surface-card-soft dashboard-subcard">
                                                <p class="section-label dashboard-subcard-label">Absensi</p>
                                                <p class="surface-card-title dashboard-subcard-value">${app.escapeHtml(klass.attendance?.status?.label || 'Sudah tercatat')}</p>
                                                <p class="surface-card-copy dashboard-card-copy">
                                                    ${app.escapeHtml(klass.attendance?.status?.time ? `Tercatat ${klass.attendance.status.time}.` : 'Sudah tercatat.')}
                                                </p>
                                            </div>
                                        ` : klass.attendance?.available ? `
                                            <div class="surface-card-soft dashboard-subcard">
                                                <p class="section-label dashboard-subcard-label">Absensi</p>
                                                <p class="surface-card-title dashboard-subcard-value">Buka detail kelas.</p>
                                                <p class="surface-card-copy dashboard-card-copy">Absensi belum tersedia dari dashboard.</p>
                                            </div>
                                        ` : `
                                            <div class="surface-card-soft dashboard-subcard">
                                                <p class="section-label dashboard-subcard-label">Absensi</p>
                                                <p class="surface-card-title dashboard-subcard-value">Belum ada absensi aktif.</p>
                                            </div>
                                        `}
                                        <div class="flex items-start lg:justify-end">
                                            ${klass.attendance?.meeting_url ? `
                                                <a href="${app.escapeHtml(klass.attendance.meeting_url)}" class="ghost-button w-full justify-center lg:w-auto">Detail meeting</a>
                                            ` : '<span class="ghost-button w-full justify-center opacity-50 lg:w-auto">Detail meeting</span>'}
                                        </div>
                                    </div>
                                </div>
                            </article>
                        `).join('')}
                    `;
                }

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
                            await loadDashboard();
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
                renderFocusStrip();

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = '<div class="empty-shell">Tidak ada tugas yang menunggu dikumpulkan.</div>';
                } else {
                    assignmentHost.innerHTML = pendingAssignments.map((assignment, index) => `
                        <article class="surface-card dashboard-card">
                            <div class="grid gap-4">
                                <div class="space-y-3">
                                    <div>
                                        <h3 class="stack-item-title dashboard-card-title">${app.escapeHtml(assignment.course_name || 'Tugas')}</h3>
                                        <p class="surface-card-copy dashboard-card-meta">${app.escapeHtml([assignment.course_code, assignment.lecturer_name].filter(Boolean).join(' / ') || '')}</p>
                                    </div>
                                    ${assignment.material_title && assignment.material_title !== assignment.course_name ? `
                                        <p class="stack-item-copy dashboard-card-copy">${app.escapeHtml(assignment.material_title)}</p>
                                    ` : ''}
                                    <div class="page-meta-row">
                                        ${assignment.deadline ? `<span class="meta-pill dashboard-pill">${app.escapeHtml(assignment.deadline)}</span>` : ''}
                                    </div>
                                </div>
                                <div class="grid gap-3">
                                    <div class="stack-item-actions">
                                        ${assignment.assignment_file_url ? `
                                            <a href="${app.escapeHtml(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="secondary-button justify-center">
                                                Buka lampiran
                                            </a>
                                        ` : `
                                            <span class="secondary-button justify-center opacity-50">Lampiran belum ada</span>
                                        `}
                                        <a href="${app.escapeHtml(assignment.meeting_url)}" class="ghost-button justify-center">Detail meeting</a>
                                    </div>
                                    ${assignment.can_submit_directly ? `
                                        <details class="dashboard-action-disclosure">
                                            <summary class="dashboard-action-summary">
                                                <div class="dashboard-action-summary-copy">
                                                    <p class="section-label dashboard-subcard-label">Unggah cepat</p>
                                                    <p class="dashboard-action-title">Buka panel saat file sudah siap.</p>
                                                </div>
                                                <span class="secondary-button dashboard-action-trigger">Buka panel</span>
                                            </summary>
                                            <form class="dashboard-action-form" data-assignment-card="${index}">
                                                <label class="grid gap-2">
                                                    <span class="section-label dashboard-subcard-label">File</span>
                                                    <input type="file" class="field-shell" data-field="attachment" accept=".pdf" required>
                                                </label>
                                                <button type="submit" class="primary-button" data-online-required>Kumpul sekarang</button>
                                            </form>
                                        </details>
                                    ` : `
                                        <div class="surface-card-soft dashboard-subcard">
                                            <p class="surface-card-title dashboard-subcard-value">Buka detail meeting.</p>
                                        </div>
                                    `}
                                </div>
                            </div>
                        </article>
                    `).join('');
                }

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

            loadDashboard = async () => {
                try {
                    const payload = await app.request('/api/dashboard');
                    renderDashboard(payload);
                    dashboardOverviewHealthy = true;
                    app.markViewSynced('Dashboard');
                } catch (error) {
                    dashboardOverviewHealthy = false;
                    app.setSyncStatus('Gagal memuat ringkasan dashboard.', 'error');
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            loadPendingAssignments = async () => {
                try {
                    const payload = await app.request('/api/assignment/pending');
                    renderPendingAssignments(payload);

                    if (dashboardOverviewHealthy) {
                        app.markViewSynced('Tugas');
                    }
                } catch (error) {
                    app.setSyncStatus('Gagal memuat daftar tugas yang belum dikumpulkan.', 'error');
                    document.getElementById('dashboard-pending-assignments').innerHTML = '<div class="empty-shell">Gagal memuat daftar tugas yang belum dikumpulkan.</div>';
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
