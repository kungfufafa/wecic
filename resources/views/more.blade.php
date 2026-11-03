@extends('layouts.app')

@section('title', 'Lainnya')
@section('page_id', 'more-index')

@php
    $tileIcons = [
        'class' => '<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /><path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /><path d="M3 6l0 13" /><path d="M12 6l0 13" /><path d="M21 6l0 13" />',
        'exam' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 15l2 2l4 -4" />',
        'krs' => '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 14l2 2l4 -4" />',
        'grades' => '<path d="M3 21l18 0" /><path d="M5 21v-8a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v8" /><path d="M10 21v-12a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12" /><path d="M15 21v-16a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v16" />',
        'transcript' => '<path d="M22 9l-10 -4l-10 4l10 4l10 -4v6" /><path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4" />',
        'finance' => '<path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" />',
        'skkm' => '<path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873z" />',
        'announcement' => '<path d="M18 8a3 3 0 0 1 0 6" /><path d="M10 8v11a1 1 0 0 1 -1 1h-1a1 1 0 0 1 -1 -1v-5" /><path d="M12 8h0l4.524 -3.77a.9 .9 0 0 1 1.476 .692v12.156a.9 .9 0 0 1 -1.476 .692l-4.524 -3.77h-8a1 1 0 0 1 -1 -1v-4a1 1 0 0 1 1 -1h8" />',
        'thesis' => '<path d="M15 15m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /><path d="M13 17.5v4.5l2 -1.5l2 1.5v-4.5" /><path d="M10 19h-5a2 2 0 0 1 -2 -2v-10c0 -1.1 .9 -2 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -1 1.73" /><path d="M6 9l12 0" /><path d="M6 12l3 0" /><path d="M6 15l2 0" />',
        'library' => '<path d="M5 4m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /><path d="M9 4m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /><path d="M5 8h4" /><path d="M9 16h4" /><path d="M13.803 4.56l2.184 -.53c.562 -.135 1.133 .19 1.282 .732l3.695 13.418a1.02 1.02 0 0 1 -.634 1.219l-.133 .041l-2.184 .53c-.562 .135 -1.133 -.19 -1.282 -.732l-3.695 -13.418a1.02 1.02 0 0 1 .634 -1.219l.133 -.041z" /><path d="M14 9l4 -1" /><path d="M16 16l3.923 -.98" />',
        'notebook' => '<path d="M6 4h11a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-11a1 1 0 0 1 -1 -1v-14a1 1 0 0 1 1 -1m3 0v18" /><path d="M13 8l2 0" /><path d="M13 12l2 0" />',
        'archive' => '<path d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-10" /><path d="M10 12l4 0" />',
        'profile' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />',
        'password' => '<path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" />',
        'portal' => '<path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" />',
        'logout' => '<path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" />',
    ];

    $tileGroups = [
        [
            'title' => 'Kuliah',
            'tiles' => [
                ['key' => 'class', 'label' => 'Jadwal & Kelas', 'caption' => 'Jadwal, materi, dan absensi', 'href' => route('class.index')],
                ['key' => 'exam', 'label' => 'Ujian', 'caption' => 'Jadwal UTS/UAS dan upload jawaban', 'href' => route('exam.index')],
            ],
        ],
        [
            'title' => 'Akademik',
            'tiles' => [
                ['key' => 'krs', 'label' => 'KRS & Perwalian', 'caption' => 'Status KRS dan kontak DPA', 'href' => route('krs.index')],
                ['key' => 'grades', 'label' => 'Nilai (KHS)', 'caption' => 'Nilai per semester', 'href' => route('grades.index')],
                ['key' => 'transcript', 'label' => 'Transkrip', 'caption' => 'IPK dan progres studi', 'href' => route('transcript.index')],
            ],
        ],
        [
            'title' => 'Keuangan & kegiatan',
            'tiles' => [
                ['key' => 'finance', 'label' => 'Keuangan', 'caption' => 'Tagihan dan riwayat bayar', 'href' => route('finance.index')],
                ['key' => 'skkm', 'label' => 'SKKM', 'caption' => 'Poin kegiatan mahasiswa', 'href' => route('skkm.index')],
                ['key' => 'announcement', 'label' => 'Pengumuman', 'caption' => 'Info dan tenggat kampus', 'href' => route('announcement.index')],
            ],
        ],
        [
            'title' => 'Skripsi & pustaka',
            'tiles' => [
                ['key' => 'thesis', 'label' => 'Skripsi', 'caption' => 'Syarat, status, dan kesiapan', 'href' => route('thesis.index')],
                ['key' => 'library', 'label' => 'Perpustakaan', 'caption' => 'Repositori skripsi dan e-Book', 'href' => route('library.index')],
            ],
        ],
        [
            'title' => 'Belajar & cadangan',
            'tiles' => [
                ['key' => 'notebook', 'label' => 'Notebook', 'caption' => 'Tanya jawab dan ringkasan dari materi kuliah', 'href' => route('notebook.index')],
                ['key' => 'archive', 'label' => 'Arsip WeCIC', 'caption' => 'Cadangan data portal dan materi', 'href' => route('archive.index')],
            ],
        ],
        [
            'title' => 'Akun',
            'tiles' => [
                ['key' => 'profile', 'label' => 'Profil', 'caption' => 'Data diri di portal', 'href' => route('profile')],
                ['key' => 'password', 'label' => 'Ubah password', 'caption' => 'Ganti password portal', 'href' => route('password.edit')],
                ['key' => 'portal', 'label' => 'Portal kampus', 'caption' => 'Buka situs resmi', 'href' => config('portal.base_url'), 'external' => true],
            ],
        ],
    ];
@endphp

@section('content')
    <x-page-header title="Lainnya" subtitle="Semua menu portal kampus di satu tempat. Angka merah atau kuning berarti ada yang perlu kamu urus." />

    <div class="flex flex-col gap-6">
        @foreach ($tileGroups as $group)
            <section aria-labelledby="more-group-{{ $loop->index }}">
                <h2 class="mb-2 text-caption font-semibold uppercase tracking-wide text-ink-subtle" id="more-group-{{ $loop->index }}">{{ $group['title'] }}</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ($group['tiles'] as $tile)
                        <a
                            href="{{ $tile['href'] }}"
                            class="mycic-card flex min-h-28 min-w-0 flex-col gap-3 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99]"
                            data-more-tile="{{ $tile['key'] }}"
                            @if (! empty($tile['external'])) target="_blank" rel="noopener noreferrer" @endif
                        >
                            <span class="mycic-tile-icon relative h-10 w-10">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $tileIcons[$tile['key']] !!}</svg>
                                <span class="mycic-nav-badge hidden" data-nav-badge="{{ $tile['key'] }}"></span>
                            </span>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-sm font-bold text-ink">{{ $tile['label'] }}</span>
                                <span class="line-clamp-2 text-caption text-ink-subtle" data-more-status="{{ $tile['key'] }}" data-default-caption="{{ $tile['caption'] }}">{{ $tile['caption'] }}</span>
                            </span>
                        </a>
                    @endforeach

                    @if ($loop->last)
                        <button
                            type="button"
                            class="mycic-card flex min-h-28 min-w-0 flex-col items-start gap-3 p-4 text-start transition-colors hover:border-danger/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger active:scale-[0.99]"
                            data-logout
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-danger-soft text-danger">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $tileIcons['logout'] !!}</svg>
                            </span>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-sm font-bold text-danger">Keluar</span>
                                <span class="line-clamp-2 text-caption text-ink-subtle">Keluar dari perangkat ini</span>
                            </span>
                        </button>
                    @endif
                </div>
            </section>
        @endforeach

        <section class="mycic-card scroll-mt-20" id="panduan" aria-labelledby="more-guides-title">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="more-guides-title">Panduan</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Panduan resmi dari portal kampus, dibuka lewat WeCIC.</p>
            </div>
            <div class="mycic-card-body" id="more-guides">
                <div class="flex flex-col gap-3" aria-busy="true">
                    <span class="sr-only">Memuat panduan…</span>
                    <div class="mycic-skeleton h-4 w-2/3"></div>
                    <div class="mycic-skeleton h-4 w-1/2"></div>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const guidesHost = document.getElementById('more-guides');
            const portalUrl = @json(config('portal.base_url'));
            let guidesRendered = false;

            // `/api/portal/context` serves the context itself; `/api/dashboard` nests it.
            const renderGuides = (payload) => {
                const data = payload?.data;
                const context = data && Object.prototype.hasOwnProperty.call(data, 'guide_links') ? data : data?.portal_context;
                const links = Array.isArray(context?.guide_links) ? context.guide_links : null;

                if (!links) {
                    guidesHost.innerHTML = app.renderEmptyState(
                        'Panduan belum terbaca dari portal.',
                        'secondary',
                        'Coba sinkronkan lagi nanti, atau buka langsung di Portal kampus.'
                    );
                    guidesRendered = context !== undefined;

                    return;
                }

                if (!links.length) {
                    guidesHost.innerHTML = app.renderEmptyState('Portal belum menyediakan panduan.', 'secondary');
                    guidesRendered = true;

                    return;
                }

                guidesHost.innerHTML = `
                    <div class="flex flex-col">
                        ${links.map((link) => {
                            const href = app.buildCampusDownloadUrl(link.path, link.filename);

                            return href ? `
                                <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="mycic-list-row min-h-11 items-center rounded-md transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-online-required>
                                    <span class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17h6" /><path d="M9 13h6" /></svg>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-ink">${app.escapeHtml(link.label || link.filename || 'Panduan')}</span>
                                            <span class="block text-caption text-ink-subtle">PDF · butuh koneksi internet</span>
                                        </span>
                                    </span>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 self-center text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                            ` : '';
                        }).join('')}
                    </div>
                `;
                guidesRendered = true;
            };

            const renderTileStatus = (items = []) => {
                const byTile = new Map();

                items.forEach((item) => {
                    const key = app.actionItemBadgeKey(item);

                    if (key && !byTile.has(key) && app.severityTone(item?.severity) !== 'success') {
                        byTile.set(key, item);
                    }
                });

                document.querySelectorAll('[data-more-status]').forEach((element) => {
                    const item = byTile.get(element.dataset.moreStatus);
                    const tone = item ? app.severityTone(item.severity) : null;

                    element.textContent = item?.title || element.dataset.defaultCaption || '';
                    element.classList.toggle('text-ink-subtle', !item);
                    element.classList.toggle('font-semibold', Boolean(item));
                    element.classList.toggle('text-danger', tone === 'danger');
                    element.classList.toggle('text-warning', tone === 'warning');
                    element.classList.toggle('text-brand-pressed', Boolean(item) && tone !== 'danger' && tone !== 'warning');
                });
            };

            window.addEventListener('portal:action-items', (event) => renderTileStatus(event.detail?.items || []));

            if (Array.isArray(app.actionItems)) {
                renderTileStatus(app.actionItems);
            }

            if (!app.primeCachedView('/api/portal/context', renderGuides, 'panduan')) {
                const cachedDashboard = app.getCachedResponse('/api/dashboard');

                if (cachedDashboard?.payload?.data?.portal_context) {
                    renderGuides(cachedDashboard.payload);
                }
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadMore = async ({ force = false, background = false } = {}) => {
                const actionItems = app.loadActionItems({ force, background });

                try {
                    const payload = await app.request('/api/portal/context', { fresh: force, background });

                    renderGuides(payload);
                    app.markViewSynced('Lainnya', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Panduan gagal dimuat.', 'error');

                    if (!guidesRendered) {
                        app.mountErrorState(guidesHost, 'Panduan gagal dimuat.', () => loadMore({ force }), {
                            subtitle: `${app.describeError(error)} Panduan juga tersedia di Portal kampus (${portalUrl}).`,
                        });
                    }
                }

                await actionItems;
            };

            app.registerSyncHandler('more-index', loadMore, { aliases: ['portal-context'] });

            await loadMore();
        });
    </script>
@endpush
