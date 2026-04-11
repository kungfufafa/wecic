@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_id', 'dashboard')

@section('content')
    <section class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)]">
        <div class="grid gap-6">
            <div class="app-panel">
                <p class="section-label">Ringkasan</p>
                <h1 data-display class="mt-4 text-3xl font-semibold text-[color:var(--ink)] sm:text-4xl">Dashboard Mahasiswa</h1>
            </div>

            <div id="dashboard-metrics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['Mata kuliah', 'IPK', 'Total SKS', 'Tagihan'] as $metric)
                    <article class="hero-metric">
                        <strong>-</strong>
                        <span>{{ $metric }}</span>
                    </article>
                @endforeach
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                <div class="app-panel">
                    <div>
                        <p class="section-label">Hari ini</p>
                        <h2 data-display class="mt-3 text-xl font-semibold text-[color:var(--ink)] sm:text-2xl">Absen hari ini</h2>
                    </div>
                    <div id="dashboard-attendance-shortcut" class="mt-6 grid gap-4">
                        <div class="empty-shell">Mencari kelas yang relevan untuk absensi hari ini.</div>
                    </div>
                </div>

                <div class="app-panel">
                    <div>
                        <p class="section-label">Tugas</p>
                        <h2 data-display class="mt-3 text-xl font-semibold text-[color:var(--ink)] sm:text-2xl">Belum dikumpulkan</h2>
                    </div>
                    <div id="dashboard-pending-assignments" class="mt-6 grid gap-4">
                        <div class="empty-shell">Memuat daftar tugas yang belum dikumpulkan.</div>
                    </div>
                </div>
            </div>
        </div>

        <aside class="grid gap-6">
            <div class="app-panel">
                <p class="section-label">Hari ini</p>
                <h2 data-display class="mt-3 text-xl font-semibold text-[color:var(--ink)] sm:text-2xl">Jadwal kelas</h2>
                <div id="dashboard-class-summary" class="mt-4 grid gap-3">
                    <div class="empty-shell">Memuat jadwal kuliah hari ini.</div>
                </div>
            </div>

            <div class="app-panel">
                <p class="section-label">Akses cepat</p>
                <div class="mt-4 grid gap-3">
                    <a href="/class" class="secondary-button justify-between">Daftar kelas <span aria-hidden="true">/</span></a>
                    <a href="/exam" class="secondary-button justify-between">Ujian <span aria-hidden="true">/</span></a>
                    <a href="/profile" class="secondary-button justify-between">Profil <span aria-hidden="true">/</span></a>
                </div>
            </div>
        </aside>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

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

                document.querySelectorAll('#dashboard-metrics strong').forEach((element, index) => {
                    element.textContent = metrics[index] || '-';
                });

                const summaryHost = document.getElementById('dashboard-class-summary');
                const todayClasses = classesSummary.today_classes || [];
                const dayLabel = classesSummary.day || 'Hari ini';
                const semesterLabel = classesSummary.active_semester && classesSummary.active_semester !== 'N/A'
                    ? `Semester aktif ${classesSummary.active_semester}`
                    : 'Semester aktif belum terdeteksi';

                if (!todayClasses.length) {
                    summaryHost.innerHTML = `<div class="empty-shell">Tidak ada jadwal kuliah untuk ${app.escapeHtml(dayLabel)}.</div>`;
                } else {
                    summaryHost.innerHTML = `
                        <article class="app-panel-muted">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="section-label">${app.escapeHtml(dayLabel)}</p>
                                    <p class="mt-3 text-sm leading-6 text-[color:var(--ink-soft)]">${app.escapeHtml(semesterLabel)}</p>
                                </div>
                                <strong class="text-2xl font-semibold text-[color:var(--ink)]">${app.escapeHtml(String(classesSummary.today_classes_count || todayClasses.length))}</strong>
                            </div>
                        </article>
                        ${todayClasses.map((klass) => `
                            <article class="app-panel-muted">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-semibold text-[color:var(--ink)]">${app.escapeHtml(klass.course_name || 'Kelas')}</h3>
                                            ${klass.active_now ? `
                                                <span class="rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-[color:var(--accent-strong)]" style="border-color: color-mix(in oklab, var(--accent) 24%, transparent); background: color-mix(in oklab, var(--accent) 10%, white);">
                                                    Sedang berlangsung
                                                </span>
                                            ` : ''}
                                        </div>
                                        <p class="mt-2 text-xs uppercase tracking-[0.16em] text-[color:var(--ink-soft)]">${app.escapeHtml([klass.course_code, klass.class_name].filter(Boolean).join(' / ') || '-')}</p>
                                    </div>
                                    <p class="text-sm font-semibold text-[color:var(--ink)] sm:text-right">${app.escapeHtml(klass.schedule?.time || '-')}</p>
                                </div>
                                <div class="mt-4">
                                    ${app.renderKeyValueList([
                                        { label: 'Dosen', value: klass.lecturer_name },
                                        { label: 'Ruang', value: klass.room },
                                    ])}
                                </div>
                            </article>
                        `).join('')}
                    `;
                }
            };

            const renderTodayShortcut = (payload) => {
                const attendanceHost = document.getElementById('dashboard-attendance-shortcut');
                const data = payload?.data || {};
                const classes = data.classes || [];

                if (!classes.length) {
                    attendanceHost.innerHTML = `<div class="empty-shell">Tidak ada kelas yang cocok untuk ${app.escapeHtml(data.day || 'hari ini')}.</div>`;
                } else {
                    attendanceHost.innerHTML = classes.map((klass, index) => `
                    <article class="app-panel-muted">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-3">
                                <div>
                                    <p class="section-label">${klass.active_now ? 'Sedang berlangsung' : 'Jadwal hari ini'}</p>
                                    <h3 class="mt-3 text-lg font-semibold text-[color:var(--ink)]">${app.escapeHtml(klass.course_name || 'Kelas')}</h3>
                                    <p class="mt-2 text-sm text-[color:var(--ink-soft)]">${app.escapeHtml([klass.course_code, klass.lecturer_name].filter(Boolean).join(' / ') || '-')}</p>
                                </div>
                                ${app.renderKeyValueList([
                                    { label: 'Waktu', value: klass.schedule?.time },
                                    { label: 'Pertemuan', value: klass.meeting_number },
                                    { label: 'Materi', value: klass.today_material?.title },
                                ])}
                            </div>
                            <div class="grid w-full gap-4 lg:w-[21rem]">
                                ${klass.attendance_status?.already_marked ? `
                                    <div class="app-panel-muted">
                                        <p class="section-label">Absensi</p>
                                        <p class="mt-3 text-sm font-semibold text-[color:var(--ink)]">
                                            ${app.escapeHtml(klass.attendance_status.label || 'Sudah tercatat')}
                                        </p>
                                        <p class="mt-2 text-sm leading-6 text-[color:var(--ink-soft)]">
                                            ${app.escapeHtml(klass.attendance_status.time ? `Absensi tercatat pada ${klass.attendance_status.time}.` : 'Absensi untuk pertemuan ini sudah tercatat.')}
                                        </p>
                                    </div>
                                ` : `
                                    <form class="grid gap-3" data-attendance-card="${index}">
                                        <label class="grid gap-2">
                                            <span class="section-label">Kode QR / kode absensi</span>
                                            <input type="text" class="field-shell" data-field="kode" placeholder="35533429" required>
                                        </label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <button type="button" class="secondary-button justify-center" data-qr-scan>Scan QR</button>
                                            <label class="secondary-button justify-center cursor-pointer">
                                                Upload foto
                                                <input type="file" class="hidden" data-qr-file accept="image/*">
                                            </label>
                                        </div>
                                        <button type="submit" class="primary-button" data-online-required>Absen</button>
                                    </form>
                                `}
                                <form class="grid gap-3" data-assignment-card="${index}">
                                    <label class="grid gap-2">
                                        <span class="section-label">File tugas</span>
                                        <input type="file" class="field-shell" data-field="attachment" accept=".pdf" required>
                                    </label>
                                    <label class="grid gap-2">
                                        <span class="section-label">Keterangan</span>
                                        <input type="text" class="field-shell" data-field="note" placeholder="Opsional">
                                    </label>
                                    <button type="submit" class="secondary-button" data-online-required>Kumpul tugas</button>
                                </form>
                            </div>
                        </div>
                    </article>
                    `).join('');
                }

                attendanceHost.querySelectorAll('[data-attendance-card]').forEach((form, index) => {
                    app.bindAttendanceQrTools(form, form.querySelector('[data-field="kode"]'));

                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Absen butuh koneksi internet.')) {
                            return;
                        }

                        const candidate = classes[index];
                        const kode = form.querySelector('[data-field="kode"]').value.trim();

                        try {
                            const result = await app.request('/api/attendance', {
                                method: 'POST',
                                json: {
                                    ...candidate.attendance_payload,
                                    kode,
                                },
                            });

                            app.showNotice(result?.message || 'Absensi berhasil dikirim.', 'success');
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                        }
                    });
                });

                app.refreshOnlineRequiredState();
            };

            const renderPendingAssignments = (payload) => {
                const assignmentHost = document.getElementById('dashboard-pending-assignments');
                const pendingAssignments = payload?.data?.assignments || [];

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = '<div class="empty-shell">Tidak ada tugas yang menunggu dikumpulkan.</div>';
                } else {
                    assignmentHost.innerHTML = pendingAssignments.map((assignment, index) => `
                        <article class="app-panel-muted">
                            <div class="space-y-3">
                                <div>
                                    <p class="section-label">Pertemuan ${app.escapeHtml(assignment.meeting_number || '-')}</p>
                                    <h3 class="mt-3 text-lg font-semibold text-[color:var(--ink)]">${app.escapeHtml(assignment.course_name || 'Tugas')}</h3>
                                    <p class="mt-2 text-sm text-[color:var(--ink-soft)]">${app.escapeHtml([assignment.course_code, assignment.lecturer_name].filter(Boolean).join(' / ') || '-')}</p>
                                </div>
                                ${app.renderKeyValueList([
                                    { label: 'Judul', value: assignment.material_title },
                                    { label: 'Tanggal materi', value: assignment.material_date },
                                    { label: 'Batas waktu', value: assignment.deadline },
                                ])}
                                <div class="grid grid-cols-2 gap-2">
                                    ${assignment.assignment_file_url ? `
                                        <a href="${app.escapeHtml(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="secondary-button justify-center">
                                            Buka lampiran tugas
                                        </a>
                                    ` : `
                                        <span class="secondary-button justify-center opacity-50">Lampiran belum tersedia</span>
                                    `}
                                    <a href="${app.escapeHtml(assignment.meeting_url)}" class="ghost-button justify-center">Detail meeting</a>
                                </div>
                                ${assignment.can_submit_directly ? `
                                    <form class="grid gap-3" data-assignment-card="${index}">
                                        <label class="grid gap-2">
                                            <span class="section-label">File tugas</span>
                                            <input type="file" class="field-shell" data-field="attachment" accept=".pdf" required>
                                        </label>
                                        <label class="grid gap-2">
                                            <span class="section-label">Keterangan</span>
                                            <input type="text" class="field-shell" data-field="note" placeholder="Opsional">
                                        </label>
                                        <button type="submit" class="secondary-button" data-online-required>Kumpul tugas</button>
                                    </form>
                                ` : `
                                    <div class="grid gap-3">
                                        <p class="text-sm leading-6 text-[color:var(--ink-soft)]">Tugas tersedia, tetapi pengiriman langsung belum terbuka dari dashboard. Buka detail meeting untuk melihat instruksi lengkap.</p>
                                        <a href="${app.escapeHtml(assignment.meeting_url)}" class="secondary-button justify-center">Buka detail meeting</a>
                                    </div>
                                `}
                            </div>
                        </article>
                    `).join('');
                }

                assignmentHost.querySelectorAll('[data-assignment-card]').forEach((form, index) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.')) {
                            return;
                        }

                        const candidate = pendingAssignments[index];
                        const file = form.querySelector('[data-field="attachment"]').files[0];

                        if (!file) {
                            app.showNotice('Pilih file tugas terlebih dahulu.', 'warning');

                            return;
                        }

                        if (!candidate.assignment_payload) {
                            app.showNotice('Tugas ini perlu dibuka dari detail meeting terlebih dahulu.', 'warning');

                            return;
                        }

                        const formData = new FormData();
                        formData.append('courses_id', candidate.assignment_payload.courses_id);
                        formData.append('id', candidate.assignment_payload.id);
                        formData.append('pertemuan', candidate.assignment_payload.pertemuan);
                        formData.append('attachment', file);

                        const note = form.querySelector('[data-field="note"]').value.trim();
                        if (note !== '') {
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
                });

                app.refreshOnlineRequiredState();
            };

            app.primeCachedView('/api/dashboard', renderDashboard, 'ringkasan dashboard');
            app.primeCachedView('/api/attendance/today', renderTodayShortcut, 'shortcut hari ini');
            app.primeCachedView('/api/assignment/pending', renderPendingAssignments, 'tugas belum dikumpulkan');

            const ready = await app.ensureAuthenticated();
            let dashboardOverviewHealthy = false;

            if (!ready) {
                return;
            }

            const loadDashboard = async () => {
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

            const loadTodayShortcut = async () => {
                try {
                    const payload = await app.request('/api/attendance/today');
                    renderTodayShortcut(payload);

                    if (dashboardOverviewHealthy) {
                        app.markViewSynced('Shortcut hari ini');
                    }
                } catch (error) {
                    app.setSyncStatus('Gagal memuat shortcut hari ini.', 'error');
                    document.getElementById('dashboard-attendance-shortcut').innerHTML = '<div class="empty-shell">Gagal memuat absensi hari ini.</div>';
                    document.getElementById('dashboard-pending-assignments').innerHTML = '<div class="empty-shell">Gagal memuat daftar tugas yang belum dikumpulkan.</div>';
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            const loadPendingAssignments = async () => {
                try {
                    const payload = await app.request('/api/assignment/pending');
                    renderPendingAssignments(payload);

                    if (dashboardOverviewHealthy) {
                        app.markViewSynced('Tugas belum dikumpulkan');
                    }
                } catch (error) {
                    app.setSyncStatus('Gagal memuat daftar tugas yang belum dikumpulkan.', 'error');
                    document.getElementById('dashboard-pending-assignments').innerHTML = '<div class="empty-shell">Gagal memuat daftar tugas yang belum dikumpulkan.</div>';
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('dashboard-overview', loadDashboard);
            app.registerSyncHandler('dashboard-shortcuts', loadTodayShortcut);
            app.registerSyncHandler('dashboard-pending-assignments', loadPendingAssignments);

            await loadDashboard();
            await loadTodayShortcut();
            await loadPendingAssignments();
        });
    </script>
@endpush
