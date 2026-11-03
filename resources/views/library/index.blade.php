@extends('layouts.app')

@section('title', 'Perpustakaan')
@section('page_id', 'library-index')

@section('content')
    <x-page-header title="Perpustakaan" subtitle="Repositori skripsi kampus yang bisa dicari, plus perpustakaan digital dari halaman e-Book Portal Kampus.">
        <p class="mt-1 min-h-4 text-caption text-ink-subtle" data-library-updated></p>
    </x-page-header>

    <div class="grid items-start gap-5 lg:grid-cols-12">
        <div class="flex min-w-0 flex-col gap-5 lg:col-span-7">
            <form action="{{ route('library.theses') }}" method="get" role="search" class="mycic-card" aria-labelledby="library-search-title">
                <div class="mycic-card-body flex flex-col gap-3">
                    <h2 class="text-base font-bold text-ink" id="library-search-title">Cari skripsi di Repositori UCIC</h2>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="mycic-input-wrap min-w-0 flex-1">
                            <label for="library-search" class="sr-only">Judul, topik, pembimbing, atau penulis</label>
                            <span class="mycic-input-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                            </span>
                            <input type="search" id="library-search" name="q" maxlength="100" class="mycic-input mycic-input-with-icon" placeholder="Judul, topik, pembimbing, atau penulis" autocomplete="off" enterkeyhint="search">
                        </div>
                        <button type="submit" class="mycic-btn-primary">Cari</button>
                    </div>
                    <a href="{{ route('library.theses') }}?mode=cek" class="mycic-link inline-flex min-h-9 items-center self-start text-sm">Punya rencana judul? Cek kemiripannya</a>
                </div>
            </form>

            <section class="mycic-card" aria-labelledby="library-repository-title">
                <div class="mycic-card-header flex flex-wrap items-center justify-between gap-2">
                    <h2 class="mycic-card-title" id="library-repository-title">Repositori skripsi</h2>
                    <a href="{{ route('library.theses') }}" class="mycic-btn-secondary-sm min-h-11 sm:min-h-9">Buka repositori</a>
                </div>
                <div class="mycic-card-body flex flex-col gap-4" id="library-repository" aria-busy="true">
                    <span class="sr-only">Memuat ringkasan repositori…</span>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="mycic-skeleton h-16 w-full"></div>
                        <div class="mycic-skeleton h-16 w-full"></div>
                    </div>
                    <div class="mycic-skeleton h-4 w-2/3"></div>
                </div>
            </section>
        </div>

        <section class="mycic-card lg:col-span-5" aria-labelledby="library-links-title">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="library-links-title">Perpustakaan digital</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Dibuka di situs masing-masing, di luar WeCIC.</p>
            </div>
            <div class="mycic-card-body" id="library-links" aria-busy="true">
                <div class="flex flex-col gap-3">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="mycic-skeleton h-12 w-full"></div>
                    @endfor
                </div>
            </div>
        </section>
    </div>
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

            const renderRepository = (repository) => {
                repositoryHost.removeAttribute('aria-busy');
                const count = Number(repository?.record_count) || 0;
                const status = repository?.status || 'missing';

                if (!count) {
                    const preparing = ['pending', 'missing', 'syncing'].includes(status);

                    repositoryHost.innerHTML = preparing
                        ? `<div class="flex items-center gap-3 text-sm text-ink-muted" role="status"><span class="mycic-spinner h-5 w-5" aria-hidden="true"></span>Menyiapkan indeks repositori… Kamu sudah bisa mencari judul terbaru sekarang.</div>`
                        : app.renderEmptyState(status === 'failed' ? 'Repositori belum bisa dibaca dari portal.' : 'Repositori portal masih kosong.', 'secondary', status === 'failed' ? 'Coba lagi nanti; pencarian tetap memakai halaman terbaru portal.' : '');

                    return;
                }

                const seen = previousSeen;
                const newCount = seen && Number.isFinite(Number(seen.count)) ? Math.max(0, count - Number(seen.count)) : 0;
                const programs = Array.isArray(repository.programs) ? repository.programs.slice(0, 5) : [];
                const keywords = Array.isArray(repository.top_keywords) ? repository.top_keywords : [];
                const advisors = Array.isArray(repository.top_advisors) ? repository.top_advisors : [];

                if (!seenRecorded && repository.complete) {
                    storage.write(SEEN_KEY, { count, first: repository.first_record_key || null });
                    seenRecorded = true;
                }

                repositoryHost.innerHTML = `
                    <div class="grid grid-cols-2 gap-3">
                        <div class="mycic-metric p-3 sm:p-4">
                            <div class="mycic-metric-label">Judul terindeks</div>
                            <div class="mycic-metric-value tabular-nums">${numberFormatter.format(count)}</div>
                            ${repository.complete ? '' : '<div class="mycic-metric-note">Indeks sedang dilengkapi</div>'}
                        </div>
                        <div class="mycic-metric p-3 sm:p-4">
                            <div class="mycic-metric-label">Baru sejak kunjungan lalu</div>
                            <div class="mycic-metric-value tabular-nums">${seen ? numberFormatter.format(newCount) : '–'}</div>
                            ${repository.latest_academic_year ? `<div class="mycic-metric-note">Terbaru T.A. ${app.escapeHtml(repository.latest_academic_year)}</div>` : ''}
                        </div>
                    </div>
                    ${keywords.length ? `
                        <div>
                            <h3 class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">Topik populer</h3>
                            <div class="mt-2 flex flex-wrap gap-2">
                                ${keywords.map((keyword) => `<a href="${app.escapeHtml(searchUrl({ q: keyword.word }))}" class="mycic-chip min-h-9 hover:border-brand/40 hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">${app.escapeHtml(keyword.word)} <span class="tabular-nums text-ink-subtle">${keyword.count}</span></a>`).join('')}
                            </div>
                        </div>
                    ` : ''}
                    ${programs.length ? `
                        <div>
                            <h3 class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">Per program studi</h3>
                            <ul class="mt-2 flex flex-col divide-y divide-border-light">
                                ${programs.map((program) => `
                                    <li><a href="${app.escapeHtml(searchUrl({ prodi: program.value }))}" class="flex min-h-11 items-center justify-between gap-3 py-2 text-sm hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"><span class="min-w-0 truncate text-ink">${app.escapeHtml(program.value)}</span><span class="shrink-0 tabular-nums text-ink-subtle">${numberFormatter.format(program.count)}</span></a></li>
                                `).join('')}
                            </ul>
                        </div>
                    ` : ''}
                    ${advisors.length ? `<p class="text-caption text-ink-subtle">Pembimbing paling aktif: ${advisors.slice(0, 3).map((advisor) => `<a class="mycic-link" href="${app.escapeHtml(searchUrl({ pembimbing: advisor.value }))}">${app.escapeHtml(advisor.value)}</a> (${advisor.count})`).join(', ')}</p>` : ''}
                `;
            };

            const renderLinks = (data) => {
                linksHost.removeAttribute('aria-busy');
                const links = Array.isArray(data?.links) ? data.links : [];

                if (!links.length) {
                    linksHost.innerHTML = app.renderEmptyState('Belum ada tautan perpustakaan dari portal.', 'secondary');

                    return;
                }

                linksHost.innerHTML = `
                    ${data.source === 'config' ? `<p class="mb-3 text-caption text-ink-subtle">Halaman e-Book portal belum terbaca; ini daftar bawaan WeCIC.</p>` : ''}
                    <ul class="flex flex-col divide-y divide-border-light">
                        ${links.map((link) => {
                            if (link.is_internal) {
                                return `
                                    <li class="flex items-center justify-between gap-3 py-3">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-ink">${app.escapeHtml(link.title)}</span>
                                            <span class="mt-1 inline-flex">${app.renderBadge('Di WeCIC', 'brand')}</span>
                                        </span>
                                        <a href="${app.escapeHtml(link.internal_route || '/library/theses')}" class="${app.buttonClass('secondary', { small: true })} shrink-0">Buka</a>
                                    </li>
                                `;
                            }

                            const href = app.safeHref(link.url);

                            if (!href) {
                                return '';
                            }

                            return `
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-ink">${app.escapeHtml(link.title)}</span>
                                        <span class="block truncate text-caption text-ink-subtle">${app.escapeHtml(link.host || '')}</span>
                                        <span class="mt-1 flex flex-wrap gap-1.5">
                                            ${app.renderBadge('Eksternal', 'secondary')}
                                            ${link.requires_account ? app.renderBadge('Perlu akun terpisah', 'warning') : ''}
                                        </span>
                                    </span>
                                    <span class="flex shrink-0 gap-2">
                                        <button type="button" class="${app.buttonClass('ghost', { small: true })}" data-copy-link="${app.escapeHtml(href)}" aria-label="Salin tautan ${app.escapeHtml(link.title)}">Salin</button>
                                        <a href="${app.escapeHtml(href)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('secondary', { small: true })}" data-online-required>Buka<span class="sr-only"> ${app.escapeHtml(link.title)} (tab baru)</span></a>
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
                    repositoryHost.innerHTML = app.renderEmptyState('Ringkasan repositori akan tampil setelah data termuat.', 'secondary', 'Pencarian skripsi tetap bisa dicoba.');
                    app.setSyncStatus('Perpustakaan gagal dimuat.', 'error');
                }
            };

            app.registerSyncHandler('library-index', loadLibrary);

            await loadLibrary();
        });
    </script>
@endpush
