@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_id', 'dashboard')

@section('content')
    <section class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)]">
        <div class="grid gap-6">
            <div class="app-panel">
                <p class="section-label">Ringkasan</p>
                <h1 data-display class="mt-4 text-3xl font-semibold text-[color:var(--ink)] sm:text-4xl">Dashboard Mahasiswa</h1>
                <p id="dashboard-summary-copy" class="mt-3 text-sm leading-7 text-[color:var(--ink-soft)]">Memuat ringkasan portal kampus.</p>
            </div>

            <div id="dashboard-metrics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['Mata kuliah', 'IPK', 'Total SKS', 'Tagihan'] as $metric)
                    <article class="hero-metric">
                        <strong>-</strong>
                        <span>{{ $metric }}</span>
                    </article>
                @endforeach
            </div>

            <div class="app-panel">
                <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="section-label">Hari ini</p>
                        <h2 data-display class="mt-3 text-xl font-semibold text-[color:var(--ink)] sm:text-2xl">Absen dan kumpul tugas</h2>
                    </div>
                    <button id="dashboard-shortcut-refresh" type="button" class="secondary-button">Refresh</button>
                </div>
                <div id="dashboard-shortcut" class="mt-6 grid gap-4">
                    <div class="empty-shell">Mencari kelas yang relevan untuk hari ini.</div>
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
            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const renderDashboard = (payload) => {
                const data = payload?.data || {};
                const overview = data.dashboard_overview || {};
                const classesSummary = data.classes_summary || {};

                document.getElementById('dashboard-summary-copy').textContent =
                    data.timestamp ? `Data terakhir sinkron: ${app.formatDate(data.timestamp)}.` : 'Ringkasan portal kampus aktif.';

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
                const host = document.getElementById('dashboard-shortcut');
                const data = payload?.data || {};
                const classes = data.classes || [];

                if (!classes.length) {
                    host.innerHTML = `<div class="empty-shell">Tidak ada kelas yang cocok untuk ${app.escapeHtml(data.day || 'hari ini')}.</div>`;

                    return;
                }

                host.innerHTML = classes.map((klass, index) => `
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
                                <form class="grid gap-3" data-attendance-card="${index}">
                                    <label class="grid gap-2">
                                        <span class="section-label">Kode QR / kode absensi</span>
                                        <input type="text" class="field-shell" data-field="kode" placeholder="35533429" required>
                                    </label>
                                    <button type="submit" class="primary-button">Absen</button>
                                </form>
                                <form class="grid gap-3" data-assignment-card="${index}">
                                    <label class="grid gap-2">
                                        <span class="section-label">File tugas</span>
                                        <input type="file" class="field-shell" data-field="attachment" accept=".pdf" required>
                                    </label>
                                    <label class="grid gap-2">
                                        <span class="section-label">Keterangan</span>
                                        <input type="text" class="field-shell" data-field="note" placeholder="Opsional">
                                    </label>
                                    <button type="submit" class="secondary-button">Kumpul tugas</button>
                                </form>
                            </div>
                        </div>
                    </article>
                `).join('');

                host.querySelectorAll('[data-attendance-card]').forEach((form, index) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

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

                host.querySelectorAll('[data-assignment-card]').forEach((form, index) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        const candidate = classes[index];
                        const file = form.querySelector('[data-field="attachment"]').files[0];

                        if (!file) {
                            app.showNotice('Pilih file tugas terlebih dahulu.', 'warning');

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
            };

            const loadDashboard = async () => {
                try {
                    const payload = await app.request('/api/dashboard');
                    renderDashboard(payload);
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            const loadTodayShortcut = async () => {
                try {
                    const payload = await app.request('/api/attendance/today');
                    renderTodayShortcut(payload);
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            document.getElementById('dashboard-shortcut-refresh').addEventListener('click', loadTodayShortcut);

            await loadDashboard();
            await loadTodayShortcut();
        });
    </script>
@endpush
