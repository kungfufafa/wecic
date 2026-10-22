@extends('layouts.app')

@section('title', 'Arsip MyCIC')
@section('page_id', 'archive-index')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Arsip MyCIC</h1>
        <p class="mycic-page-subheading">Cadangan data portal kampus milikmu. Tetap bisa dibuka saat portal bermasalah, dan jadi bukti kalau data di portal tiba-tiba berubah atau hilang.</p>
    </header>

    <div class="flex min-w-0 flex-col gap-6">
        <section aria-labelledby="archive-attention-title" id="archive-attention" class="hidden">
            <h2 class="mycic-section-title mb-2" id="archive-attention-title">Perlu kamu cek</h2>
            <div class="flex flex-col gap-2" id="archive-attention-list"></div>
        </section>

        <section aria-labelledby="archive-datasets-title">
            <h2 class="mycic-section-title mb-2" id="archive-datasets-title">Data akademik</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" id="archive-datasets" aria-busy="true">
                <span class="sr-only">Memuat arsip…</span>
                @for ($i = 0; $i < 6; $i++)
                    <div class="mycic-card p-4">
                        <div class="flex flex-col gap-2">
                            <div class="mycic-skeleton h-5 w-1/2"></div>
                            <div class="mycic-skeleton h-3.5 w-2/3"></div>
                        </div>
                    </div>
                @endfor
            </div>
        </section>

        <section class="mycic-card" aria-labelledby="archive-files-title">
            <div class="mycic-card-header flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="mycic-card-title" id="archive-files-title">Materi kuliah</h2>
                    <p class="mt-0.5 text-caption text-ink-subtle">Modul, latihan, dan RPS tersimpan otomatis setiap kali kamu membuka pertemuan, dan sinkronisasi di background mengarsipkan semua kelasmu secara berkala. Bisa dibuka tanpa portal dan dijadikan sumber Notebook.</p>
                </div>
                <form class="flex w-full flex-wrap items-end gap-2 sm:w-auto" id="archive-class-form">
                    <label class="mycic-field min-w-0 flex-1 sm:w-64 sm:flex-none">
                        <span class="mycic-label">Arsipkan semua materi kelas</span>
                        <select class="mycic-input" id="archive-class-select" required>
                            <option value="">Memuat daftar kelas…</option>
                        </select>
                    </label>
                    <button type="submit" class="mycic-btn-secondary" data-online-required>Arsipkan</button>
                </form>
            </div>
            <div class="mycic-card-body" id="archive-files">
                <div class="flex flex-col gap-3" aria-busy="true">
                    <span class="sr-only">Memuat berkas…</span>
                    <div class="mycic-skeleton h-4 w-2/3"></div>
                    <div class="mycic-skeleton h-4 w-1/2"></div>
                </div>
            </div>
        </section>

        <section class="mycic-card" aria-labelledby="archive-privacy-title">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="archive-privacy-title">Privasi arsip</h2>
            </div>
            <div class="mycic-card-body flex flex-col gap-3 text-sm text-ink-muted">
                <ul class="flex list-disc flex-col gap-1.5 ps-5">
                    <li>Data disimpan terenkripsi di server MyCIC dan hanya bisa dibuka lewat akunmu. Daftar teman sekelas tidak ikut diarsipkan.</li>
                    <li>Saat portal mati, kamu tetap bisa masuk dengan password terakhir yang berhasil login (maks. {{ (int) config('portal.archive.offline_login_days', 30) }} hari), dan MyCIC menyambung lagi otomatis begitu portal pulih.</li>
                    <li>Dengan sinkronisasi di background aktif, password terakhirmu disimpan terenkripsi supaya MyCIC bisa masuk ke portal sendiri setiap {{ (int) config('portal.background_sync.interval', 180) }} menit dan memperbarui arsip saat kamu tidak membuka MyCIC, termasuk detail kelas, pertemuan, dan materi semua kelasmu. Matikan di bawah kalau tidak mau; yang tersisa hanya hash password.</li>
                    <li>Kalau data dari portal terlihat janggal (nilai hilang, presensi berubah jadi absen, riwayat bayar berkurang), MyCIC tetap menampilkan versi terakhir yang dipercaya sampai kamu memeriksanya.</li>
                </ul>
                <div class="mycic-alert-info mb-0 flex flex-wrap items-center justify-between gap-3" id="archive-background-sync" hidden>
                    <span class="min-w-0">
                        <span class="block font-bold text-ink">Sinkronisasi di background</span>
                        <span class="block text-sm" id="archive-background-sync-caption"></span>
                    </span>
                    <label class="flex min-h-11 shrink-0 cursor-pointer items-center gap-2 text-sm font-semibold text-ink">
                        <input type="checkbox" class="h-5 w-5 cursor-pointer accent-brand" id="archive-background-sync-toggle">
                        <span>Aktif</span>
                    </label>
                </div>
                <div>
                    <button type="button" class="mycic-btn-destructive" id="archive-delete">Hapus seluruh arsip saya</button>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const datasetsHost = document.getElementById('archive-datasets');
            const attentionSection = document.getElementById('archive-attention');
            const attentionList = document.getElementById('archive-attention-list');
            const filesHost = document.getElementById('archive-files');
            const classForm = document.getElementById('archive-class-form');
            const classSelect = document.getElementById('archive-class-select');
            const deleteButton = document.getElementById('archive-delete');

            const formatBytes = (bytes) => {
                const value = Number(bytes) || 0;

                if (value >= 1024 * 1024) {
                    return `${(value / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
                }

                return `${Math.max(1, Math.round(value / 1024))} KB`;
            };

            const renderDatasets = (datasets) => {
                datasetsHost.removeAttribute('aria-busy');

                const pending = datasets.filter((dataset) => dataset.open_suspects > 0);
                attentionSection.classList.toggle('hidden', pending.length === 0);
                attentionList.innerHTML = pending.map((dataset) => `
                    <a href="/archive/${encodeURIComponent(dataset.dataset)}" class="mycic-alert-warning mb-0 flex items-center justify-between gap-3 transition-colors hover:border-warning/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        <span class="min-w-0">
                            <span class="block font-bold">${app.escapeHtml(dataset.label)}: ${dataset.open_suspects} perubahan janggal</span>
                            <span class="block text-caption">MyCIC menahan data dari portal dan tetap menampilkan versi tersimpan. Buka untuk memeriksa.</span>
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
                    </a>
                `).join('');

                datasetsHost.innerHTML = datasets.map((dataset) => {
                    const caption = dataset.versions > 0
                        ? [
                            `${dataset.versions} versi`,
                            dataset.groups > 1 ? `${dataset.groups} kelompok` : '',
                            dataset.last_seen_at ? `terakhir dicek ${app.formatTimeAgo(dataset.last_seen_at)}` : '',
                        ].filter(Boolean).join(' · ')
                        : 'Belum ada arsip. Tersimpan saat halamannya dibuka.';

                    return `
                        <a href="/archive/${encodeURIComponent(dataset.dataset)}" class="mycic-card flex min-h-24 min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-bold text-ink">${app.escapeHtml(dataset.label)}</span>
                                ${dataset.open_suspects > 0 ? app.renderBadge(`${dataset.open_suspects} janggal`, 'warning') : (dataset.versions > 0 ? app.renderBadge('Tersimpan', 'success') : '')}
                            </span>
                            <span class="text-caption text-ink-subtle">${app.escapeHtml(caption)}</span>
                        </a>
                    `;
                }).join('');
            };

            const renderFiles = (payload) => {
                const courses = Array.isArray(payload?.data?.courses) ? payload.data.courses : [];
                const totals = payload?.data?.totals || { count: 0, bytes: 0 };

                if (!courses.length) {
                    filesHost.innerHTML = app.renderEmptyState(
                        'Belum ada materi tersimpan.',
                        'secondary',
                        'Buka pertemuan di halaman Kuliah, atau pilih kelas di atas untuk mengarsipkan semua materinya.'
                    );

                    return;
                }

                filesHost.innerHTML = `
                    <p class="mb-3 text-caption text-ink-subtle">${totals.count} berkas · ${formatBytes(totals.bytes)}</p>
                    <div class="flex flex-col gap-5">
                        ${courses.map((course) => `
                            <section class="flex flex-col gap-1">
                                <h3 class="text-sm font-bold text-ink">${app.escapeHtml(course.course_name || 'Mata kuliah')}</h3>
                                <ul class="flex flex-col">
                                    ${course.files.map((file) => `
                                        <li>
                                            <a href="${app.escapeHtml(file.download_url)}" target="_blank" rel="noopener" class="mycic-list-row min-h-11 items-center rounded-md transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                                <span class="flex min-w-0 items-center gap-3">
                                                    ${app.renderBadge(file.kind_label || file.kind, file.kind === 'rps' ? 'info' : (file.kind === 'latihan' ? 'warning' : 'brand'))}
                                                    <span class="min-w-0">
                                                        <span class="block truncate text-sm font-semibold text-ink">${app.escapeHtml(file.title || file.filename)}</span>
                                                        <span class="block text-caption text-ink-subtle">${app.escapeHtml([file.meeting_number ? `Pertemuan ${file.meeting_number}` : '', file.filename, formatBytes(file.size)].filter(Boolean).join(' · '))}</span>
                                                    </span>
                                                </span>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 self-center text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
                                            </a>
                                        </li>
                                    `).join('')}
                                </ul>
                            </section>
                        `).join('')}
                    </div>
                `;
            };

            const loadClasses = async () => {
                try {
                    const payload = await app.request('/api/class');
                    const classes = Array.isArray(payload?.classes) ? payload.classes : [];
                    const options = classes
                        .map((item) => ({
                            id: item?.join_link?.params?.id || null,
                            name: item?.course_info?.name || item?.course_info?.course_name || 'Kelas',
                        }))
                        .filter((item) => item.id);

                    classSelect.innerHTML = options.length
                        ? `<option value="">Pilih kelas</option>${options.map((item) => `<option value="${app.escapeHtml(item.id)}">${app.escapeHtml(item.name)}</option>`).join('')}`
                        : '<option value="">Daftar kelas belum tersedia</option>';
                } catch (error) {
                    classSelect.innerHTML = '<option value="">Daftar kelas belum bisa dimuat</option>';
                }
            };

            const backgroundSyncHost = document.getElementById('archive-background-sync');
            const backgroundSyncCaption = document.getElementById('archive-background-sync-caption');
            const backgroundSyncToggle = document.getElementById('archive-background-sync-toggle');

            const describeInterval = (minutes) => {
                if (minutes % 1440 === 0) {
                    return `${minutes / 1440} hari`;
                }

                return minutes % 60 === 0 ? `${minutes / 60} jam` : `${minutes} menit`;
            };

            const describeCourseSync = (status) => {
                if (!status.courses_enabled) {
                    return '';
                }

                const last = status.last_course_sync_at
                    ? `terakhir ${app.formatTimeAgo(status.last_course_sync_at)}`
                    : 'belum pernah diarsipkan';

                return ` Materi semua kelas ${last} · setiap ${describeInterval(status.courses_interval_minutes)}.`;
            };

            const describeBackgroundSync = (status) => {
                if (!status.enabled) {
                    return 'Nonaktif. Password tidak disimpan; arsip hanya diperbarui saat kamu membuka MyCIC.';
                }

                if (status.rejected) {
                    return 'Portal menolak password yang tersimpan. Masuk lagi di MyCIC saat portal online untuk memperbaruinya.';
                }

                if (status.pending_login) {
                    return 'Aktif sejak kamu masuk lagi saat portal online.';
                }

                const lastSync = status.last_synced_at ? `Terakhir ${app.formatTimeAgo(status.last_synced_at)}` : 'Belum pernah berjalan';
                const outcome = {
                    synced: 'berhasil',
                    partial: 'sebagian berhasil',
                    failed: 'gagal',
                    unreachable: 'portal tidak bisa dihubungi',
                    rejected: 'password ditolak',
                }[status.last_status] || '';

                return `${lastSync}${outcome ? ` (${outcome})` : ''} · setiap ${describeInterval(status.interval_minutes)}, sampai ${status.credential_days} hari setelah login terakhirmu.${describeCourseSync(status)}`;
            };

            const renderBackgroundSync = (status) => {
                if (!status || !status.available) {
                    backgroundSyncHost.hidden = true;

                    return;
                }

                backgroundSyncHost.hidden = false;
                backgroundSyncToggle.checked = Boolean(status.enabled);
                backgroundSyncCaption.textContent = describeBackgroundSync(status);
            };

            backgroundSyncToggle.addEventListener('change', async () => {
                const enabled = backgroundSyncToggle.checked;

                if (!enabled && !window.confirm('Matikan sinkronisasi di background? Password yang tersimpan langsung dihapus dan arsip hanya diperbarui saat kamu membuka MyCIC.')) {
                    backgroundSyncToggle.checked = true;

                    return;
                }

                backgroundSyncToggle.disabled = true;

                try {
                    const payload = await app.request('/api/archive/background-sync', { method: 'PATCH', json: { enabled } });

                    renderBackgroundSync(payload?.data || null);
                    app.showNotice(payload?.message || 'Pengaturan sinkronisasi disimpan.', 'success');
                } catch (error) {
                    backgroundSyncToggle.checked = !enabled;
                    app.showNotice(`Pengaturan belum tersimpan. ${app.describeError(error)}`, 'error');
                } finally {
                    backgroundSyncToggle.disabled = false;
                }
            });

            const loadArchive = async () => {
                try {
                    const [summary, files] = await Promise.all([
                        app.request('/api/archive'),
                        app.request('/api/archive/files'),
                    ]);

                    if (!summary || !files) {
                        return;
                    }

                    renderDatasets(Array.isArray(summary?.data?.datasets) ? summary.data.datasets : []);
                    renderFiles(files);
                    renderBackgroundSync(summary?.data?.background_sync || null);
                    app.setSyncStatus('Arsip dibaca dari database MyCIC.', 'info');
                } catch (error) {
                    datasetsHost.removeAttribute('aria-busy');
                    app.mountErrorState(datasetsHost, 'Arsip gagal dimuat.', () => loadArchive(), {
                        subtitle: app.describeError(error),
                    });
                }
            };

            classForm.addEventListener('submit', (event) => {
                event.preventDefault();

                if (!classSelect.value || !app.requireOnlineAction('Mengarsipkan materi butuh koneksi ke portal.')) {
                    return;
                }

                const button = classForm.querySelector('button[type="submit"]');

                app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request('/api/archive/files/sync', {
                            method: 'POST',
                            json: { id: Number(classSelect.value) },
                        });

                        app.showNotice(payload?.message || 'Materi kelas sedang diarsipkan.', 'success');
                    } catch (error) {
                        app.showNotice(`Materi belum bisa diarsipkan. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            deleteButton.addEventListener('click', () => {
                if (!window.confirm('Hapus seluruh arsip MyCIC kamu? Riwayat data, berkas materi, dan akses offline akan hilang dan tidak bisa dikembalikan.')) {
                    return;
                }

                app.withSubmitLoading(deleteButton, async () => {
                    try {
                        const payload = await app.request('/api/archive', { method: 'DELETE', json: { confirm: true } });

                        app.showNotice(payload?.message || 'Arsip sudah dihapus.', 'success');
                        await loadArchive();
                    } catch (error) {
                        app.showNotice(`Arsip gagal dihapus. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            app.registerSyncHandler('archive-index', loadArchive, { aliases: ['archive-files'] });

            await Promise.all([loadArchive(), loadClasses()]);
        });
    </script>
@endpush
