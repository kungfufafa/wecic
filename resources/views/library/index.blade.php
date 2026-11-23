@extends('layouts.app')

@section('title', 'Perpustakaan')
@section('page_id', 'library-index')

@section('content')
    <x-page-hero title="Perpustakaan">
        <x-slot:meta><span data-library-updated></span></x-slot:meta>
    </x-page-hero>

    <x-split at="xl" :sticky="false">
        <form action="{{ route('library.theses') }}" method="get" role="search" aria-labelledby="library-search-title" class="rounded-xl border border-border bg-panel p-4 sm:p-5">
            <h2 class="text-sm font-semibold tracking-tight text-ink" id="library-search-title">Cari skripsi di Repositori UCIC</h2>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <div class="relative flex min-w-0 flex-1 items-center">
                    <label for="library-search" class="sr-only">Judul, topik, pembimbing, atau penulis</label>
                    <span class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    </span>
                    <x-input icon type="search" id="library-search" name="q" maxlength="100" placeholder="Judul, topik, atau penulis" autocomplete="off" enterkeyhint="search" />
                </div>
                <x-button type="submit">Cari</x-button>
            </div>
            <x-link href="{{ route('library.theses') }}?mode=cek" class="mt-2 inline-flex min-h-11 items-center gap-1.5 text-sm sm:min-h-9">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>
                Punya rencana judul? Cek kemiripannya
            </x-link>
        </form>

        <x-card as="section" aria-labelledby="library-repository-title" title="Repositori skripsi" title-id="library-repository-title">
            <x-slot:actions>
                <a href="{{ route('library.theses') }}" class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-sm text-[13px] font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-0">Lihat semua</a>
            </x-slot:actions>
            <x-card.body class="flex flex-col gap-6" id="library-repository" aria-busy="true">
                <span class="sr-only">Memuat ringkasan repositori…</span>
                <div class="flex flex-wrap gap-2">
                    @for ($i = 0; $i < 5; $i++)
                        <x-skeleton class="h-9 w-24" />
                    @endfor
                </div>
                <div class="flex flex-col gap-3">
                    @for ($i = 0; $i < 4; $i++)
                        <x-skeleton class="h-10 w-full" />
                    @endfor
                </div>
            </x-card.body>
        </x-card>

        <x-slot:aside>
            <x-card as="section" aria-labelledby="library-links-title" title="Perpustakaan digital" title-id="library-links-title">
                <x-card.body class="has-[>ul]:p-0" id="library-links" aria-busy="true">
                    <div class="flex flex-col gap-3">
                        @for ($i = 0; $i < 4; $i++)
                            <x-skeleton class="h-12 w-full" />
                        @endfor
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
            const repositoryHost = document.getElementById('library-repository');
            const linksHost = document.getElementById('library-links');
            const updatedLabel = document.querySelector('[data-library-updated]');
            const SEEN_KEY = 'mycic-library-repository-seen';
            const numberFormatter = new Intl.NumberFormat('id-ID');
            let lastPayload = null;
            let rendered = false;
            let seenRecorded = false;

            const storage = {
                read(key) {
                    try {
                        const raw = window.localStorage.getItem(key);

                        return raw ? JSON.parse(raw) : null;
                    } catch (error) {
                        return null;
                    }
                },
                write(key, value) {
                    try {
                        window.localStorage.setItem(key, JSON.stringify(value));
                    } catch (error) {
                        // "New since last visit" is a per-device convenience.
                    }
                },
            };

            const describeFreshness = (payload) => {
                const meta = payload?.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || meta.cached_at || payload?.data?.extracted_at);

                if (!ago) {
                    return '';
                }

                if (meta.data_origin === 'device_cache') {
                    return `Diperbarui ${ago} · data tersimpan di perangkat${meta.cache_is_stale ? ', mungkin sudah lama' : ''}`;
                }

                if (meta.status === 'failed') {
                    return `Diperbarui ${ago} · portal belum bisa dihubungi, ini data terakhir`;
                }

                return `Diperbarui ${ago}`;
            };

            // Read once: this visit's own write must not reset the "new" count on re-render.
            const previousSeen = storage.read(SEEN_KEY);

            const searchUrl = (params) => `/library/theses?${new URLSearchParams(params).toString()}`;

            /** The repository's one-line count: total, new since the last visit, the latest academic year. */
            const summaryLine = (count, newCount, seen, repository) => {
                const facts = [
                    `<span class="font-semibold tabular-nums text-ink">${numberFormatter.format(count)}</span> judul`,
                    seen && newCount > 0 ? `<span class="tabular-nums">${numberFormatter.format(newCount)}</span> baru sejak kunjungan lalu` : '',
                    repository.complete ? (repository.latest_academic_year ? `terbaru T.A. ${app.escapeHtml(repository.latest_academic_year)}` : '') : 'indeks sedang dilengkapi',
                ].filter(Boolean);

                return `<p class="text-sm text-ink-muted">${facts.join(' · ')}</p>`;
            };

            const renderRepository = (repository) => {
                repositoryHost.removeAttribute('aria-busy');
                const count = Number(repository?.record_count) || 0;
                const status = repository?.status || 'missing';
                const allPrograms = Array.isArray(repository?.programs) ? repository.programs : [];

                if (!count) {
                    const preparing = ['pending', 'missing', 'syncing'].includes(status);

                    repositoryHost.innerHTML = preparing
                        ? `<div class="${app.ui.inset} flex items-center gap-3 text-sm text-ink-muted" role="status"><span class="${app.ui.spinner}" aria-hidden="true"></span><span>Menyiapkan indeks repositori… Kamu sudah bisa mencari judul terbaru sekarang.</span></div>`
                        : app.renderEmptyState(status === 'failed' ? 'Repositori belum bisa dibaca dari portal.' : 'Repositori portal masih kosong.', 'secondary', status === 'failed' ? 'Coba lagi nanti; pencarian tetap memakai halaman terbaru portal.' : '', { icon: 'library' });

                    return;
                }

                const seen = previousSeen;
                const newCount = seen && Number.isFinite(Number(seen.count)) ? Math.max(0, count - Number(seen.count)) : 0;
                const programs = allPrograms.slice(0, 6);
                const topProgramCount = Math.max(1, ...programs.map((program) => Number(program.count) || 0));
                const keywords = Array.isArray(repository.top_keywords) ? repository.top_keywords : [];
                const advisors = Array.isArray(repository.top_advisors) ? repository.top_advisors : [];

                if (!seenRecorded && repository.complete) {
                    storage.write(SEEN_KEY, { count, first: repository.first_record_key || null });
                    seenRecorded = true;
                }

                if (!keywords.length && !programs.length && !advisors.length) {
                    repositoryHost.innerHTML = `${summaryLine(count, newCount, seen, repository)}<p class="text-sm text-ink-muted">Topik dan program studi tampil setelah indeks selesai diringkas.</p>`;

                    return;
                }

                repositoryHost.innerHTML = `
                    ${summaryLine(count, newCount, seen, repository)}
                    ${keywords.length ? `
                        <div>
                            <h3 class="text-xs font-medium text-ink-subtle">Topik populer</h3>
                            <div class="mt-2 flex flex-wrap gap-2">
                                ${keywords.map((keyword) => `<a href="${app.escapeHtml(searchUrl({ q: keyword.word }))}" class="inline-flex min-h-11 items-center gap-1.5 rounded-md border border-border bg-panel px-2.5 sm:min-h-9 text-xs font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">${app.escapeHtml(keyword.word)} <span class="text-ink-subtle tabular-nums">${keyword.count}</span></a>`).join('')}
                            </div>
                        </div>
                    ` : ''}
                    ${programs.length ? `
                        <div>
                            <h3 class="text-xs font-medium text-ink-subtle">Per program studi</h3>
                            <ul class="mt-1 ${app.ui.list}">
                                ${programs.map((program) => `
                                    <li>
                                        <a href="${app.escapeHtml(searchUrl({ prodi: program.value }))}" class="-mx-2 flex min-h-11 flex-col gap-1.5 rounded-lg px-2 py-2.5 transition-colors hover:bg-surface/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">
                                            <span class="flex items-baseline justify-between gap-3">
                                                <span class="min-w-0 truncate text-sm font-medium text-ink">${app.escapeHtml(program.value)}</span>
                                                <span class="shrink-0 text-xs text-ink-muted tabular-nums">${numberFormatter.format(program.count)}</span>
                                            </span>
                                            <span class="block h-1 overflow-hidden rounded-full bg-surface" aria-hidden="true"><span class="block h-1 rounded-full ${app.ui.fill.brand}" style="width: ${Math.max(4, Math.round(((Number(program.count) || 0) / topProgramCount) * 100))}%"></span></span>
                                        </a>
                                    </li>
                                `).join('')}
                            </ul>
                        </div>
                    ` : ''}
                    ${advisors.length ? `<p class="text-xs leading-relaxed text-ink-subtle">Pembimbing paling aktif: ${advisors.slice(0, 3).map((advisor) => `<a class="${app.ui.link}" href="${app.escapeHtml(searchUrl({ pembimbing: advisor.value }))}">${app.escapeHtml(advisor.value)}</a> (${advisor.count})`).join(', ')}</p>` : ''}
                `;
            };

            const renderLinks = (data) => {
                linksHost.removeAttribute('aria-busy');
                const links = Array.isArray(data?.links) ? data.links : [];

                if (!links.length) {
                    linksHost.innerHTML = app.renderEmptyState('Belum ada tautan perpustakaan dari portal.', 'secondary', '', { icon: 'world' });

                    return;
                }

                linksHost.innerHTML = `
                    ${data.source === 'config' ? `<p class="border-b border-border-light px-4 py-3 text-xs text-ink-subtle sm:px-5">Halaman e-Book portal belum terbaca; ini daftar bawaan WeCIC.</p>` : ''}
                    <ul class="${app.ui.list}">
                        ${links.map((link) => {
                            if (link.is_internal) {
                                return `
                                    <li class="flex items-center gap-3 px-4 py-3.5 sm:px-5">
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(link.title)}</span>
                                            <span class="mt-1 inline-flex">${app.renderBadge('Di WeCIC', 'secondary')}</span>
                                        </span>
                                        <a href="${app.escapeHtml(link.internal_route || '/library/theses')}" class="${app.ui.button.secondarySm}">Buka</a>
                                    </li>
                                `;
                            }

                            const href = app.safeHref(link.url);

                            if (!href) {
                                return '';
                            }

                            return `
                                <li class="flex items-start gap-3 px-4 py-3.5 sm:px-5">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(link.title)}</span>
                                        <span class="block truncate text-xs text-ink-subtle">${app.escapeHtml(link.host || '')}</span>
                                        ${link.requires_account ? `<span class="mt-1.5 flex">${app.renderBadge('Perlu akun terpisah', 'warning')}</span>` : ''}
                                        <span class="mt-2.5 flex flex-wrap gap-2">
                                            <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener noreferrer" class="${app.ui.button.secondarySm}" data-online-required>Buka<span class="sr-only"> ${app.escapeHtml(link.title)} (tab baru)</span></a>
                                            <button type="button" class="${app.ui.button.ghostSm}" data-copy-link="${app.escapeHtml(href)}" aria-label="Salin tautan ${app.escapeHtml(link.title)}">Salin</button>
                                        </span>
                                    </span>
                                </li>
                            `;
                        }).join('')}
                    </ul>
                `;
                app.refreshOnlineRequiredState();
            };

            const renderLibrary = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                lastPayload = payload;
                rendered = true;
                renderLinks(data);
                renderRepository(data.repository);
                updatedLabel.textContent = describeFreshness(payload);
            };

            linksHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-copy-link]') : null;

                if (button) {
                    void app.copyText(button.dataset.copyLink, 'Tautan');
                }
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && lastPayload) {
                    updatedLabel.textContent = describeFreshness(lastPayload);
                }
            });

            try {
                app.primeCachedView('/api/library', renderLibrary, 'perpustakaan digital');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadLibrary = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/library', { fresh: force, background });

                    if (payload) {
                        renderLibrary(payload);
                        app.markViewSynced('Perpustakaan', payload, { force });
                    }
                } catch (error) {
                    if (rendered) {
                        app.setSyncStatus(`Perpustakaan belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    app.mountErrorState(linksHost, 'Daftar perpustakaan gagal dimuat.', () => loadLibrary({ force }), {
                        subtitle: app.describeError(error),
                    });
                    linksHost.removeAttribute('aria-busy');
                    repositoryHost.removeAttribute('aria-busy');
                    repositoryHost.innerHTML = app.renderEmptyState('Ringkasan repositori akan tampil setelah data termuat.', 'secondary', 'Pencarian skripsi tetap bisa dicoba.', { icon: 'library' });
                    app.setSyncStatus('Perpustakaan gagal dimuat.', 'error');
                }
            };

            app.registerSyncHandler('library-index', loadLibrary);

            await loadLibrary();
        });
    </script>
@endpush
