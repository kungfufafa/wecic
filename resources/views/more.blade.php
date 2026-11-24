@extends('layouts.app')

@section('title', 'Lainnya')
@section('page_id', 'more-index')

{{-- Groups follow the sidebar: Kuliah, Akademik, Keuangan, Notebook, Lainnya, Akun. --}}
@php
    $tileGroups = [
        [
            'title' => 'Kuliah',
            'tiles' => [
                ['key' => 'class', 'label' => 'Jadwal & Kelas', 'caption' => 'Jadwal & absensi', 'href' => route('class.index'), 'icon' => 'class'],
                ['key' => 'exam', 'label' => 'Ujian', 'caption' => 'UTS/UAS & upload', 'href' => route('exam.index'), 'icon' => 'exam'],
            ],
        ],
        [
            'title' => 'Akademik',
            'tiles' => [
                ['key' => 'krs', 'label' => 'KRS & Perwalian', 'caption' => 'Status & DPA', 'href' => route('krs.index'), 'icon' => 'list-check'],
                ['key' => 'grades', 'label' => 'Nilai (KHS)', 'caption' => 'Nilai per semester', 'href' => route('grades.index'), 'icon' => 'chart'],
                ['key' => 'transcript', 'label' => 'Transkrip', 'caption' => 'IPK & progres', 'href' => route('transcript.index'), 'icon' => 'file'],
            ],
        ],
        [
            'title' => 'Keuangan',
            'tiles' => [
                ['key' => 'finance', 'label' => 'Keuangan', 'caption' => 'Tagihan & riwayat', 'href' => route('finance.index'), 'icon' => 'finance'],
            ],
        ],
        [
            'title' => 'Notebook',
            'tiles' => [
                ['key' => 'notebook', 'label' => 'Notebook', 'caption' => 'Tanya jawab materi', 'href' => route('notebook.index'), 'icon' => 'notebook'],
                ['key' => 'ai-settings', 'label' => 'Pengaturan AI', 'caption' => 'Penyedia & API key', 'href' => route('notebook.ai-settings'), 'icon' => 'settings'],
            ],
        ],
        [
            'title' => 'Lainnya',
            'tiles' => [
                ['key' => 'skkm', 'label' => 'SKKM', 'caption' => 'Poin kegiatan', 'href' => route('skkm.index'), 'icon' => 'skkm'],
                ['key' => 'thesis', 'label' => 'Skripsi', 'caption' => 'Syarat & status', 'href' => route('thesis.index'), 'icon' => 'thesis'],
                ['key' => 'library', 'label' => 'Perpustakaan', 'caption' => 'Repositori & e-Book', 'href' => route('library.index'), 'icon' => 'library'],
                ['key' => 'announcement', 'label' => 'Pengumuman', 'caption' => 'Info & tenggat', 'href' => route('announcement.index'), 'icon' => 'announcement'],
                ['key' => 'archive', 'label' => 'Arsip WeCIC', 'caption' => 'Cadangan & materi', 'href' => route('archive.index'), 'icon' => 'archive'],
            ],
        ],
        [
            'title' => 'Akun',
            'tiles' => [
                ['key' => 'profile', 'label' => 'Profil', 'caption' => 'Data diri', 'href' => route('profile'), 'icon' => 'user'],
                ['key' => 'password', 'label' => 'Ubah password', 'caption' => 'Ganti password', 'href' => route('password.edit'), 'icon' => 'lock'],
                ['key' => 'privacy', 'label' => 'Privasi & bantuan', 'caption' => 'Privasi & bantuan', 'href' => route('legal.privacy'), 'icon' => 'help'],
                ['key' => 'portal', 'label' => 'Portal kampus', 'caption' => 'Situs resmi', 'href' => config('portal.base_url'), 'icon' => 'world', 'external' => true],
            ],
        ],
    ];
@endphp

@section('content')
    <x-page-hero title="Lainnya" />

    <x-split at="xl">
        {{-- Who is signed in (filled by session.js hydrateUser), with the way to the profile. --}}
        <x-card as="section" class="mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5" aria-label="Akun kamu">
            <div class="flex min-w-0 items-center gap-3">
                <span class="inline-flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface text-sm font-medium text-ink-muted ring-1 ring-border" aria-hidden="true" data-user-avatar>M</span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-ink" data-user-name>Mahasiswa</p>
                    <p class="truncate text-xs text-ink-muted tabular-nums" data-user-identity>Portal kampus</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <x-button variant="secondary" size="sm" :href="route('profile')"><x-icon name="user" size="sm" />Lihat profil</x-button>
                <x-button variant="ghost" size="sm" href="#panduan"><x-icon name="help" size="sm" />Panduan</x-button>
            </div>
        </x-card>

        <div class="grid gap-6 md:grid-cols-2 md:items-start">
            @foreach ($tileGroups as $group)
                <x-section :title="$group['title']" title-id="more-group-{{ $loop->index }}" class="min-w-0">
                    <x-card class="overflow-hidden">
                        <x-list>
                            @foreach ($group['tiles'] as $tile)
                                <a
                                    href="{{ $tile['href'] }}"
                                    class="flex min-h-11 cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-surface/60 focus-visible:bg-surface/60 focus-visible:outline-none sm:px-5"
                                    data-more-tile="{{ $tile['key'] }}"
                                    @if (! empty($tile['external'])) target="_blank" rel="noopener noreferrer" @endif
                                >
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-surface text-ink-muted ring-1 ring-border/60">
                                        <x-icon :name="$tile['icon']" size="sm" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-ink">{{ $tile['label'] }}</span>
                                        <span class="mt-0.5 line-clamp-2 text-xs text-ink-subtle" data-more-status="{{ $tile['key'] }}" data-default-caption="{{ $tile['caption'] }}">{{ $tile['caption'] }}</span>
                                    </span>
                                    <span class="h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="{{ $tile['key'] }}"></span>
                                    <x-icon :name="empty($tile['external']) ? 'chevron-right' : 'external-link'" size="sm" class="text-ink-subtle" />
                                </a>
                            @endforeach

                            @if ($loop->last)
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full cursor-pointer items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-danger-soft/40 focus-visible:bg-danger-soft/40 focus-visible:outline-none sm:px-5"
                                    data-logout
                                >
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-danger-soft/70 text-danger">
                                        <x-icon name="logout" size="sm" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-danger">Keluar</span>
                                    </span>
                                </button>
                            @endif
                        </x-list>
                    </x-card>
                </x-section>
            @endforeach
        </div>

        <x-slot:aside aria-label="Panduan dan bantuan">
            <x-card
                as="section"
                class="scroll-mt-20 overflow-hidden"
                id="panduan"
                aria-labelledby="more-guides-title"
            >
                <x-card.header>
                    <h2 class="text-sm font-semibold tracking-tight text-ink" id="more-guides-title">Panduan</h2>
                </x-card.header>
                <x-card.body id="more-guides">
                    <div class="flex flex-col gap-3" aria-busy="true">
                        <span class="sr-only">Memuat panduan…</span>
                        <x-skeleton class="h-4 w-2/3" />
                        <x-skeleton class="h-4 w-1/2" />
                    </div>
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
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
                        'Coba sinkronkan lagi.',
                        { icon: 'help' }
                    );
                    guidesRendered = context !== undefined;

                    return;
                }

                if (!links.length) {
                    guidesHost.innerHTML = app.renderEmptyState('Portal belum menyediakan panduan.', 'secondary', '', { icon: 'help' });
                    guidesRendered = true;

                    return;
                }

                // Flush rows edge to edge: the list undoes the padding of the card body it lands in.
                guidesHost.innerHTML = `
                    <div class="-m-4 sm:-m-5 ${app.ui.list}">
                        ${links.map((link) => {
                            const href = app.buildCampusDownloadUrl(link.path, link.filename);

                            return href ? `
                                <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="${app.ui.listRowFlushInteractive}" data-online-required>
                                    ${app.renderIcon('file', 'md', 'text-ink-subtle')}
                                    <span class="${app.ui.listRowBody}">
                                        <span class="block ${app.ui.listRowTitle}">${app.escapeHtml(link.label || link.filename || 'Panduan')}</span>
                                        <span class="block ${app.ui.listRowSubtitle}">PDF</span>
                                    </span>
                                    ${app.renderIcon('external-link', 'sm', app.ui.listRowChevron)}
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
                    element.classList.toggle('font-medium', Boolean(item));
                    element.classList.toggle('text-danger', tone === 'danger');
                    element.classList.toggle('text-warning', tone === 'warning');
                    element.classList.toggle('text-brand-text', Boolean(item) && tone !== 'danger' && tone !== 'warning');
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
