@extends('layouts.app')

@section('title', 'Arsip WeCIC')
@section('page_id', 'archive-index')

@section('content')
    <x-page-hero title="Arsip WeCIC" subtitle="Cadangan data portal kampus milikmu, tetap bisa dibuka saat portal bermasalah." />

    <x-split :sticky="false">
        <x-section title="Perlu kamu cek" title-id="archive-attention-title" id="archive-attention" class="hidden">
            <div class="flex flex-col gap-2" id="archive-attention-list"></div>
        </x-section>

        <x-section title="Data akademik" title-id="archive-datasets-title">
            <x-slot:count id="archive-datasets-count"></x-slot:count>
            <x-card class="overflow-hidden">
                <x-list id="archive-datasets" aria-busy="true">
                    <span class="sr-only">Memuat arsip…</span>
                    @for ($i = 0; $i < 6; $i++)
                        <div class="flex items-center gap-3 px-4 py-3.5 sm:px-5">
                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                <x-skeleton class="h-4 w-1/3" />
                                <x-skeleton class="h-3.5 w-1/2" />
                            </div>
                        </div>
                    @endfor
                </x-list>
            </x-card>
        </x-section>

        <x-card as="section" class="overflow-hidden" aria-labelledby="archive-files-title">
            <x-card.header>
                <div class="min-w-0">
                    <x-card.title id="archive-files-title">Materi kuliah</x-card.title>
                    <x-card.subtitle>Modul, latihan, dan RPS tersimpan otomatis setiap kali kamu membuka pertemuan, dan sinkronisasi di background mengarsipkan semua kelasmu secara berkala. Bisa dibuka tanpa portal dan dijadikan sumber Notebook.</x-card.subtitle>
                </div>
            </x-card.header>
            <form class="flex flex-wrap items-end gap-2 border-b border-border-light bg-surface/40 px-4 py-3 sm:px-5" id="archive-class-form">
                <label class="flex min-w-0 flex-1 basis-56 flex-col gap-1.5">
                    <span class="text-sm font-medium text-ink">Arsipkan semua materi kelas</span>
                    <x-select id="archive-class-select" required>
                        <option value="">Memuat daftar kelas…</option>
                    </x-select>
                </label>
                <x-button variant="secondary" type="submit" data-online-required>Arsipkan</x-button>
            </form>
            <x-card.body id="archive-files">
                <div class="flex flex-col gap-3" aria-busy="true">
                    <span class="sr-only">Memuat berkas…</span>
                    <x-skeleton class="h-4 w-2/3" />
                    <x-skeleton class="h-4 w-1/2" />
                </div>
            </x-card.body>
        </x-card>

        <x-slot:aside aria-label="Privasi dan sinkronisasi arsip">
            <x-card as="section" aria-labelledby="archive-privacy-title">
                <x-card.header>
                    <x-card.title id="archive-privacy-title">Privasi arsip</x-card.title>
                </x-card.header>
                <x-card.body class="flex flex-col gap-4 text-[13px] text-ink-muted">
                    <ul class="flex list-disc flex-col gap-2.5 ps-5 leading-relaxed marker:text-ink-subtle">
                        <li>Data disimpan terenkripsi di server WeCIC dan hanya bisa dibuka lewat akunmu. Daftar teman sekelas tidak ikut diarsipkan.</li>
                        <li>Saat portal mati, kamu tetap bisa masuk dengan password terakhir yang berhasil login (maks. {{ \App\Models\PortalOfflineAccount::offlineLoginDays() }} hari), dan WeCIC menyambung lagi otomatis begitu portal pulih selama sinkronisasi background menyala. Selama mati, password tidak disimpan: WeCIC hanya memberi tahu saat portal pulih dan kamu masuk lagi sendiri.</li>
                        <li>Sinkronisasi di background mati sampai kamu menyalakannya. Kalau dinyalakan, password portalmu disimpan terenkripsi supaya WeCIC bisa masuk ke portal sendiri setiap {{ (int) config('portal.background_sync.interval', 180) }} menit dan memperbarui arsip saat kamu tidak membuka WeCIC, termasuk detail kelas, pertemuan, dan materi semua kelasmu. Mematikannya atau keluar dari WeCIC langsung menghapus password itu; yang tersisa hanya hash password.</li>
                        <li>Semua itu tidak disimpan selamanya: {{ \App\Models\PortalOfflineAccount::credentialDays() }} hari setelah login online terakhirmu, password terenkripsi dihapus otomatis dan sinkronisasi di background berhenti; hash password dan data untuk masuk saat portal mati dihapus {{ \App\Models\PortalOfflineAccount::offlineLoginDays() }} hari sejak login itu. Password yang ditolak portal langsung dihapus. Arsipmu sendiri tetap tersimpan sampai kamu menghapusnya.</li>
                        <li>Kalau data dari portal terlihat janggal (nilai hilang, presensi berubah jadi absen, riwayat bayar berkurang), WeCIC tetap menampilkan versi terakhir yang dipercaya sampai kamu memeriksanya.</li>
                    </ul>
                    <x-alert tone="info" class="flex flex-wrap items-center justify-between gap-3" id="archive-background-sync" hidden>
                        <span class="min-w-0">
                            <span class="block font-medium text-ink">Sinkronisasi di background</span>
                            <span class="block text-sm text-ink-muted" id="archive-background-sync-caption"></span>
                        </span>
                        <label class="flex min-h-11 shrink-0 cursor-pointer items-center gap-2 text-sm font-medium text-ink">
                            <input type="checkbox" class="size-5 cursor-pointer accent-brand" id="archive-background-sync-toggle">
                            <span>Aktif</span>
                        </label>
                        <form class="flex w-full flex-col gap-2" id="archive-background-sync-form" method="post" novalidate hidden>
                            <x-field label="Password portal" for="archive-background-sync-password">
                                <x-input id="archive-background-sync-password" name="password" type="password" autocomplete="current-password" required />
                            </x-field>
                            <span class="flex gap-2">
                                <x-button type="submit" size="sm" id="archive-background-sync-confirm">Nyalakan</x-button>
                                <x-button type="button" size="sm" variant="secondary" id="archive-background-sync-cancel">Batal</x-button>
                            </span>
                        </form>
                    </x-alert>
                </x-card.body>
                <x-card.footer>
                    <x-button variant="destructive" size="sm" id="archive-delete">Hapus seluruh arsip saya</x-button>
                </x-card.footer>
            </x-card>
        </x-slot:aside>
    </x-split>
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
            const datasetsCount = document.getElementById('archive-datasets-count');
            // Each dataset with the icon of the page it comes from: the icon tells the data's type.
            const DATASET_ICON = {
                classes: 'class',
                class_detail: 'class',
                meeting_detail: 'presentation',
                exams: 'exam',
                krs: 'list-check',
                krs_detail: 'list-check',
                advising: 'users',
                grades: 'chart',
                payments: 'finance',
                skkm: 'skkm',
                thesis: 'thesis',
                announcements: 'announcement',
                profile: 'user',
            };

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

                datasetsCount.textContent = datasets.length ? `${datasets.filter((dataset) => dataset.versions > 0).length} dari ${datasets.length} tersimpan` : '';

                attentionSection.classList.toggle('hidden', pending.length === 0);
                attentionList.innerHTML = pending.map((dataset) => `
                    <a href="/archive/${encodeURIComponent(dataset.dataset)}" class="${app.ui.alert.warning} flex min-h-11 cursor-pointer items-center gap-3 transition-colors hover:border-warning/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">${app.escapeHtml(dataset.label)}: ${dataset.open_suspects} perubahan janggal</span>
                            <span class="block text-xs text-ink-muted">WeCIC menahan data dari portal dan tetap menampilkan versi tersimpan. Buka untuk memeriksa.</span>
                        </span>
                        ${app.renderIcon('chevron-right', 'sm', app.ui.listRowChevron)}
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
                    const icon = DATASET_ICON[dataset.dataset] || 'archive';

                    return `
                        <a href="/archive/${encodeURIComponent(dataset.dataset)}" class="${app.ui.listRowFlushInteractive}">
                            <span class="flex shrink-0 text-ink-subtle" aria-hidden="true">${app.renderIcon(icon, 'sm')}</span>
                            <span class="${app.ui.listRowBody}">
                                <span class="block ${app.ui.listRowTitle}">${app.escapeHtml(dataset.label)}</span>
                                <span class="block ${app.ui.listRowSubtitle}">${app.escapeHtml(caption)}</span>
                            </span>
                            ${dataset.open_suspects > 0 ? app.renderBadge(`${dataset.open_suspects} janggal`, 'warning') : ''}
                            ${app.renderIcon('chevron-right', 'sm', app.ui.listRowChevron)}
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
                        'Buka pertemuan di halaman Kuliah, atau pilih kelas di atas untuk mengarsipkan semua materinya.',
                        { icon: 'folder' }
                    );

                    return;
                }

                filesHost.innerHTML = `
                    <p class="mb-4 text-xs text-ink-subtle tabular-nums">${totals.count} berkas · ${formatBytes(totals.bytes)}</p>
                    <div class="flex flex-col gap-6">
                        ${courses.map((course) => `
                            <section class="flex flex-col gap-1">
                                <div class="${app.ui.sectionTitleRow}">
                                    <h3 class="min-w-0 truncate text-sm font-semibold leading-snug text-ink">${app.escapeHtml(course.course_name || 'Mata kuliah')}</h3>
                                    <span class="${app.ui.sectionCount}">${course.files.length}</span>
                                </div>
                                <div class="${app.ui.list}">
                                    ${course.files.map((file) => `
                                        <a href="${app.escapeHtml(file.download_url)}" target="_blank" rel="noopener" class="-mx-2 flex min-h-11 items-center gap-3 rounded-lg px-2 py-2.5 transition-colors hover:bg-surface/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">
                                            <span class="${app.ui.listRowBody}">
                                                <span class="block ${app.ui.listRowTitle}">${app.escapeHtml(file.title || file.filename)}</span>
                                                <span class="block ${app.ui.listRowSubtitle}">${app.escapeHtml([file.meeting_number ? `Pertemuan ${file.meeting_number}` : '', file.filename, formatBytes(file.size)].filter(Boolean).join(' · '))}</span>
                                            </span>
                                            <span class="flex shrink-0 items-center gap-2">
                                                ${app.renderBadge(file.kind_label || file.kind, 'secondary')}
                                                ${app.renderIcon('download', 'sm', app.ui.listRowChevron)}
                                            </span>
                                        </a>
                                    `).join('')}
                                </div>
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
            const backgroundSyncForm = document.getElementById('archive-background-sync-form');
            const backgroundSyncPassword = document.getElementById('archive-background-sync-password');
            const backgroundSyncCancel = document.getElementById('archive-background-sync-cancel');
            let backgroundSyncStatus = null;

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
                    return 'Nonaktif. Password tidak disimpan; arsip hanya diperbarui saat kamu membuka WeCIC. Untuk menyalakannya, masukkan password portalmu.';
                }

                if (status.rejected) {
                    return 'Portal menolak password yang tersimpan. Masuk lagi di WeCIC saat portal online untuk memperbaruinya.';
                }

                if (status.pending_login) {
                    return 'Aktif sejak kamu masuk lagi saat portal online.';
                }

                if (status.last_status === 'running') {
                    return `Sedang berjalan, mulai ${app.formatTimeAgo(status.last_synced_at)} · setiap ${describeInterval(status.interval_minutes)}, sampai ${status.credential_days} hari setelah login terakhirmu.${describeCourseSync(status)}`;
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

                backgroundSyncStatus = status;
                backgroundSyncHost.hidden = false;
                backgroundSyncToggle.checked = Boolean(status.enabled);
                backgroundSyncCaption.textContent = describeBackgroundSync(status);
            };

            const hideBackgroundSyncForm = () => {
                backgroundSyncForm.hidden = true;
                backgroundSyncPassword.value = '';
            };

            const saveBackgroundSync = async (enabled, password = null) => {
                backgroundSyncToggle.disabled = true;

                try {
                    const payload = await app.request('/api/archive/background-sync', {
                        method: 'PATCH',
                        json: password === null ? { enabled } : { enabled, password },
                    });

                    hideBackgroundSyncForm();
                    renderBackgroundSync(payload?.data || null);
                    app.showNotice(payload?.message || 'Pengaturan sinkronisasi disimpan.', 'success');
                } catch (error) {
                    const effectiveStatus = error?.payload?.data;

                    if (effectiveStatus && typeof effectiveStatus === 'object') {
                        renderBackgroundSync(effectiveStatus);
                    } else if (!(error?.status === 422 && password !== null)) {
                        backgroundSyncToggle.checked = !enabled;
                    }

                    app.showNotice(`Pengaturan belum tersimpan. ${app.describeError(error)}`, 'error');
                } finally {
                    backgroundSyncToggle.disabled = false;
                }
            };

            backgroundSyncToggle.addEventListener('change', async () => {
                const enabled = backgroundSyncToggle.checked;

                if (enabled && !backgroundSyncStatus?.active) {
                    backgroundSyncForm.hidden = false;
                    backgroundSyncPassword.focus();

                    return;
                }

                hideBackgroundSyncForm();

                if (!enabled && !window.confirm('Matikan sinkronisasi di background? Password yang tersimpan langsung dihapus dan arsip hanya diperbarui saat kamu membuka WeCIC.')) {
                    backgroundSyncToggle.checked = true;

                    return;
                }

                await saveBackgroundSync(enabled);
            });

            backgroundSyncCancel.addEventListener('click', () => {
                hideBackgroundSyncForm();
                backgroundSyncToggle.checked = Boolean(backgroundSyncStatus?.enabled);
            });

            backgroundSyncForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (backgroundSyncPassword.value === '') {
                    backgroundSyncPassword.focus();

                    return;
                }

                await saveBackgroundSync(true, backgroundSyncPassword.value);
            });

            const loadArchive = async ({ background = false } = {}) => {
                try {
                    const [summary, files] = await Promise.all([
                        app.request('/api/archive', { background }),
                        app.request('/api/archive/files', { background }),
                    ]);

                    if (!summary || !files) {
                        return;
                    }

                    renderDatasets(Array.isArray(summary?.data?.datasets) ? summary.data.datasets : []);
                    renderFiles(files);
                    renderBackgroundSync(summary?.data?.background_sync || null);
                    app.setSyncStatus('Arsip dibaca dari database WeCIC.', 'info');
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
                if (!window.confirm('Hapus seluruh arsip WeCIC kamu? Riwayat data, berkas materi, dan akses offline akan hilang dan tidak bisa dikembalikan.')) {
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
