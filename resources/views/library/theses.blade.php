@extends('layouts.app')

@section('title', 'Repositori Skripsi')
@section('page_id', 'library-theses')

@section('content')
    <x-page-hero title="Repositori skripsi">
        <x-slot:tabs>
            <x-view-tabs as="div" underline role="group" aria-label="Mode repositori" id="theses-modes">
                <button type="button" class="inline-flex min-h-11 shrink-0 cursor-pointer select-none items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-1 text-sm font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-inset aria-selected:border-ink aria-selected:text-ink aria-pressed:border-ink aria-pressed:text-ink aria-[current=page]:border-ink aria-[current=page]:text-ink" data-theses-mode="search" aria-pressed="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    Cari
                </button>
                <button type="button" class="inline-flex min-h-11 shrink-0 cursor-pointer select-none items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-1 text-sm font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-inset aria-selected:border-ink aria-selected:text-ink aria-pressed:border-ink aria-pressed:text-ink aria-[current=page]:border-ink aria-[current=page]:text-ink" data-theses-mode="check" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3l8 -8" /><path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg>
                    Cek judul
                </button>
            </x-view-tabs>
        </x-slot:tabs>
    </x-page-hero>

    <section id="theses-search-panel" class="flex min-w-0 flex-col gap-3" aria-label="Cari skripsi">
        <form role="search" id="theses-form" class="sticky top-14 z-20 -mx-4 flex flex-col gap-2 border-b border-border-light bg-panel/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6 lg:-mx-8 lg:top-16 lg:px-8">
            <div class="relative flex w-full items-center">
                <label for="theses-q" class="sr-only">Cari judul, topik, pembimbing, atau penulis</label>
                <span class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <x-input icon type="search" id="theses-q" name="q" maxlength="100" placeholder="Cari skripsi (tekan /)" autocomplete="off" enterkeyhint="search" aria-describedby="theses-result-status" />
            </div>
            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                <div>
                    <label for="theses-prodi" class="sr-only">Program studi</label>
                    <x-select id="theses-prodi" data-theses-filter="prodi"><option value="">Semua prodi</option></x-select>
                </div>
                <div>
                    <label for="theses-ta" class="sr-only">Tahun akademik</label>
                    <x-select id="theses-ta" data-theses-filter="ta"><option value="">Semua T.A.</option></x-select>
                </div>
                <div>
                    <label for="theses-pembimbing" class="sr-only">Pembimbing</label>
                    <x-select id="theses-pembimbing" data-theses-filter="pembimbing"><option value="">Semua pembimbing</option></x-select>
                </div>
                <div>
                    <label for="theses-sort" class="sr-only">Urutkan</label>
                    <x-select id="theses-sort" data-theses-filter="sort">
                        <option value="">Urutan otomatis</option>
                        <option value="terbaru">Terbaru</option>
                        <option value="judul">Judul A–Z</option>
                        <option value="relevansi">Paling relevan</option>
                    </x-select>
                </div>
            </div>
        </form>

        <div id="theses-index-status" class="empty:hidden"></div>
        <div class="flex min-h-5 flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-ink-subtle tabular-nums" id="theses-result-status" aria-live="polite"></p>
            <div class="hidden" data-theses-reset-wrap>
                <x-button variant="ghost" size="sm" id="theses-reset">Hapus filter</x-button>
            </div>
        </div>

        <ul id="theses-results" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 [&>:not([data-slot=card])]:col-span-full" aria-busy="true">
            <li class="sr-only">Memuat repositori…</li>
            @for ($i = 0; $i < 6; $i++)
                <x-card as="li">
                    <x-card.body class="flex flex-col gap-2">
                        <x-skeleton class="h-5 w-24" />
                        <x-skeleton class="h-4 w-5/6" />
                        <x-skeleton class="h-4 w-2/3" />
                        <x-skeleton class="h-3.5 w-1/2" />
                    </x-card.body>
                </x-card>
            @endfor
        </ul>

        <div id="theses-more" class="flex flex-col items-center gap-2 text-center"></div>
    </section>

    <section id="theses-check-panel" class="hidden flex-col gap-3" aria-labelledby="theses-check-title">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)] xl:items-start xl:gap-8">
            <div class="flex min-w-0 flex-col gap-3 xl:sticky xl:top-20">
                <x-card as="form" id="theses-check-form">
                    <x-card.header>
                        <x-card.title id="theses-check-title">Cek kemiripan rencana judul</x-card.title>
                    </x-card.header>
                    <x-card.body class="flex flex-col gap-4">
                        <x-field label="Rencana judul" for="theses-check-input">
                            <x-textarea id="theses-check-input" rows="3" maxlength="200" placeholder="Contoh: Sistem informasi inventaris berbasis web pada koperasi"></x-textarea>
                        </x-field>
                        <x-button type="submit" class="self-start">Cek kemiripan</x-button>
                    </x-card.body>
                </x-card>
                <p class="text-xs text-ink-subtle">Bukan cek plagiasi resmi.</p>
            </div>
            <div class="min-w-0" id="theses-check-results" aria-live="polite">
                <x-empty-state title="Hasil kemiripan tampil di sini" icon="search" />
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const form = document.getElementById('theses-form');
            const queryInput = document.getElementById('theses-q');
            const selects = {
                prodi: document.getElementById('theses-prodi'),
                ta: document.getElementById('theses-ta'),
                pembimbing: document.getElementById('theses-pembimbing'),
                sort: document.getElementById('theses-sort'),
            };
            const resultsHost = document.getElementById('theses-results');
            const moreHost = document.getElementById('theses-more');
            const indexStatusHost = document.getElementById('theses-index-status');
            const resultStatus = document.getElementById('theses-result-status');
            const resetButton = document.getElementById('theses-reset');
            const resetWrap = document.querySelector('[data-theses-reset-wrap]');
            const modesHost = document.getElementById('theses-modes');
            const searchPanel = document.getElementById('theses-search-panel');
            const checkPanel = document.getElementById('theses-check-panel');
            const checkForm = document.getElementById('theses-check-form');
            const checkInput = document.getElementById('theses-check-input');
            const checkResults = document.getElementById('theses-check-results');
            const PER_PAGE = 20;
            const FILTER_KEYS = ['prodi', 'ta', 'pembimbing', 'sort'];
            const params = new URLSearchParams(window.location.search);
            const numberFormatter = new Intl.NumberFormat('id-ID');
            const state = {
                mode: params.get('mode') === 'cek' ? 'check' : 'search',
                q: (params.get('q') || '').slice(0, 100),
                prodi: params.get('prodi') || '',
                ta: /^\d{4}\/\d{4}$/.test(params.get('ta') || '') ? params.get('ta') : '',
                pembimbing: params.get('pembimbing') || '',
                sort: ['terbaru', 'judul', 'relevansi'].includes(params.get('sort')) ? params.get('sort') : '',
                items: [],
                meta: null,
                page: 1,
                requestId: 0,
                rendered: false,
                loadingMore: false,
            };
            let debounceTimer = null;

            const titleCase = (value) => String(value ?? '')
                .toLocaleLowerCase('id-ID')
                .replace(/(^|[\s(/-])(\p{L})/gu, (match, separator, letter) => `${separator}${letter.toLocaleUpperCase('id-ID')}`);
            // Portal names are mostly UPPERCASE; mixed-case names are kept as written.
            const displayName = (value) => (value && value === value.toUpperCase() ? titleCase(value) : (value || ''));
            const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            const highlight = (text, tokens) => {
                const value = String(text ?? '');
                const usable = (Array.isArray(tokens) ? tokens : []).filter((token) => token && token.length >= 2);

                if (!usable.length) {
                    return app.escapeHtml(value);
                }

                // Split on the raw text, then escape every part: a token never matches inside an entity.
                const pattern = new RegExp(`(${usable.map(escapeRegExp).join('|')})`, 'giu');

                return value.split(pattern).map((part, index) => (index % 2 === 1
                    ? `<mark class="rounded-sm bg-warning-soft px-0.5 text-ink">${app.escapeHtml(part)}</mark>`
                    : app.escapeHtml(part))).join('');
            };

            const hasFilters = () => state.q.trim() !== '' || FILTER_KEYS.some((key) => state[key] !== '');

            const queryString = (page = 1) => {
                const query = new URLSearchParams();

                if (state.q.trim()) {
                    query.set('q', state.q.trim());
                }

                FILTER_KEYS.forEach((key) => {
                    if (state[key]) {
                        query.set(key, state[key]);
                    }
                });

                if (page > 1) {
                    query.set('page', String(page));
                    query.set('per_page', String(PER_PAGE));
                }

                return query.toString();
            };

            const syncUrl = () => {
                const query = new URLSearchParams(queryString());

                if (state.mode === 'check') {
                    query.set('mode', 'cek');
                }

                const next = `${window.location.pathname}${query.toString() ? `?${query.toString()}` : ''}`;

                try {
                    window.history.replaceState(window.history.state, '', next);
                } catch (error) {
                    // Sharing the URL is a convenience; the search itself still works.
                }
            };

            const fileName = (item) => {
                const safe = (value, length = 60) => String(value ?? '')
                    .normalize('NFKD')
                    .replace(/[^\w\s-]/g, '')
                    .trim()
                    .replace(/\s+/g, '-')
                    .slice(0, length);

                return `${[safe((item.academic_year || '').replace('/', '-'), 9), safe(item.program, 30), safe(item.title)].filter(Boolean).join('_') || 'skripsi'}.pdf`;
            };

            const pdfLink = (item, { small = true } = {}) => {
                const href = item.file_path ? app.buildCampusDownloadUrl(item.file_path, fileName(item)) : null;

                return href
                    ? `<a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="${small ? app.ui.button.secondarySm : app.ui.button.secondary}" data-online-required>Baca PDF<span class="sr-only"> ${app.escapeHtml(item.title)} (tab baru)</span></a>`
                    : `<span class="text-xs text-ink-subtle">PDF tidak tersedia</span>`;
            };

            const advisorsLine = (item, tokens) => {
                const advisors = Array.isArray(item.advisors) ? item.advisors : [];

                return advisors.length
                    ? `Pembimbing: ${advisors.map((advisor) => highlight(advisor.name, tokens)).join(', ')}`
                    : 'Pembimbing tidak tercatat';
            };

            // "T.A. 2023/2024 · Teknik Informatika" as one muted line: the year is context, not a status.
            const metaLine = (item) => `<p class="truncate text-xs text-ink-muted tabular-nums">${app.escapeHtml([item.academic_year ? `T.A. ${item.academic_year}` : null, displayName(item.program)].filter(Boolean).join(' · '))}</p>`;

            const renderItem = (item, tokens) => `
                <li class="${app.ui.card} flex flex-col" data-slot="card">
                    <div class="${app.ui.cardBody} flex flex-1 flex-col gap-2">
                        ${metaLine(item)}
                        <h3 class="line-clamp-3 text-sm font-medium leading-snug text-ink">${highlight(item.title, tokens)}</h3>
                        <p class="text-[13px] text-ink-muted">${advisorsLine(item, tokens)}</p>
                        ${item.author ? `<p class="mt-auto pt-1 text-xs text-ink-subtle">Oleh ${highlight(displayName(item.author), tokens)}</p>` : ''}
                    </div>
                    <div class="${app.ui.cardFooter} gap-2!">
                        ${pdfLink(item)}
                        <button type="button" class="${app.ui.button.ghostSm}" data-theses-detail="${app.escapeHtml(item.record_key)}" aria-haspopup="dialog">Detail</button>
                    </div>
                </li>
            `;

            // Mirrors ThesisRepositorySearch::advisorKey: an advisor matches without academic titles.
            const facetKey = (key, value) => {
                const source = key === 'pembimbing' ? String(value ?? '').split(',')[0].replace(/\./g, ' ') : String(value ?? '');
                const normalized = source.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

                return key === 'pembimbing' ? (normalized.replace(/^(?:(?:dr|drs|dra|prof|ir|h|hj) )+/, '') || normalized) : normalized;
            };

            const fillSelect = (key, facet, emptyLabel) => {
                const select = selects[key];
                const options = Array.isArray(facet) ? facet : [];
                const current = state[key];
                const match = current ? options.find((option) => facetKey(key, option.value) === facetKey(key, current)) : null;

                select.innerHTML = `<option value="">${app.escapeHtml(emptyLabel)}</option>`
                    + (current && !match ? `<option value="${app.escapeHtml(current)}">${app.escapeHtml(key === 'ta' ? current : displayName(current))} (0)</option>` : '')
                    + options.map((option) => `<option value="${app.escapeHtml(option.value)}">${app.escapeHtml(key === 'ta' ? option.value : displayName(option.value))} (${numberFormatter.format(option.count)})</option>`).join('');

                select.value = match ? match.value : current;
            };

            const renderIndexStatus = (meta) => {
                const index = meta?.index || {};

                if (index.partial) {
                    indexStatusHost.innerHTML = `<div class="${app.ui.alert.info} flex items-start gap-3" role="status"><span class="${app.ui.spinner} mt-0.5 text-info" aria-hidden="true"></span><span>Menyiapkan indeks repositori… Sementara ini hasil dari halaman terbaru portal${index.total ? ` (${numberFormatter.format(index.record_count)} dari ${numberFormatter.format(index.total)} judul)` : ''}. Halaman ini diperbarui otomatis.</span></div>`;

                    return;
                }

                if (meta?.status === 'failed') {
                    indexStatusHost.innerHTML = `<div class="${app.ui.alert.warning}" role="status">Portal belum bisa dihubungi. Pencarian memakai indeks terakhir.</div>`;

                    return;
                }

                if (!index.complete && index.total) {
                    indexStatusHost.innerHTML = `<div class="${app.ui.alert.info}" role="status">Indeks belum lengkap: ${numberFormatter.format(index.record_count)} dari ${numberFormatter.format(index.total)} judul. Sisanya disinkronkan berikutnya.</div>`;

                    return;
                }

                indexStatusHost.innerHTML = '';
            };

            const renderResults = (payload, { append = false } = {}) => {
                const meta = payload?.meta || {};
                const items = Array.isArray(payload?.data?.items) ? payload.data.items : [];
                const tokens = Array.isArray(meta.tokens) ? meta.tokens : [];

                state.meta = meta;
                state.page = Number(meta.page) || 1;
                state.items = append ? [...state.items, ...items] : items;
                state.rendered = true;
                resultsHost.removeAttribute('aria-busy');

                if (meta.facets) {
                    fillSelect('prodi', meta.facets.prodi, 'Semua prodi');
                    fillSelect('ta', meta.facets.ta, 'Semua T.A.');
                    fillSelect('pembimbing', meta.facets.pembimbing, 'Semua pembimbing');
                }

                selects.sort.value = state.sort;
                renderIndexStatus(meta);
                resetWrap.classList.toggle('hidden', !hasFilters());

                const total = Number(meta.total) || 0;

                if (!state.items.length) {
                    resultStatus.textContent = hasFilters() ? 'Tidak ada skripsi yang cocok.' : '';
                    resultsHost.innerHTML = `<li>${hasFilters()
                        ? app.renderEmptyState('Tidak ada skripsi yang cocok.', 'secondary', 'Coba kata lain atau hapus sebagian filter.')
                        : app.renderEmptyState('Repositori portal masih kosong.', 'secondary', 'Belum ada skripsi yang diunggah ke SiMASITA Repositori.')}</li>`;
                    moreHost.innerHTML = '';

                    return;
                }

                resultStatus.textContent = `${numberFormatter.format(total)} skripsi${hasFilters() ? ' cocok' : ''} · menampilkan ${numberFormatter.format(state.items.length)}`;

                if (append) {
                    resultsHost.insertAdjacentHTML('beforeend', items.map((item) => renderItem(item, tokens)).join(''));
                } else {
                    resultsHost.innerHTML = state.items.map((item) => renderItem(item, tokens)).join('');
                }

                moreHost.innerHTML = meta.has_more
                    ? `<button type="button" class="${app.ui.button.secondary}" data-theses-more>Muat lagi</button><p class="text-xs text-ink-subtle tabular-nums">${numberFormatter.format(Math.max(0, total - state.items.length))} judul lagi</p>`
                    : (state.items.length > PER_PAGE ? '<p class="text-xs text-ink-subtle">Semua hasil sudah ditampilkan.</p>' : '');
                app.refreshOnlineRequiredState();
            };

            const fetchResults = async ({ force = false, append = false, background = false } = {}) => {
                const requestId = ++state.requestId;
                const page = append ? state.page + 1 : 1;
                const query = queryString(page);
                const url = `/api/library/theses${query ? `?${query}` : ''}`;
                // Only the unfiltered first page is kept on the device (see cachePolicies).
                const cacheable = !query;

                if (!append) {
                    resultsHost.setAttribute('aria-busy', 'true');
                }

                try {
                    const payload = await app.request(url, cacheable ? { fresh: force, background } : { fresh: force, cache: false, background });

                    if (requestId !== state.requestId || !payload) {
                        return;
                    }

                    renderResults(payload, { append });

                    if (!append) {
                        app.markViewSynced('Repositori skripsi', payload, { force });
                    }
                } catch (error) {
                    if (requestId !== state.requestId) {
                        return;
                    }

                    resultsHost.removeAttribute('aria-busy');

                    if (append) {
                        app.showNotice(`Hasil berikutnya belum bisa dimuat. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    if (state.rendered && !hasFilters()) {
                        app.setSyncStatus(`Repositori belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    moreHost.innerHTML = '';
                    resultStatus.textContent = '';
                    app.mountErrorState(resultsHost, 'Repositori skripsi gagal dimuat.', () => fetchResults({ force }), {
                        subtitle: `${app.describeError(error)} Repositori juga bisa dibuka lewat e-Book di Portal Kampus.`,
                    });
                    app.setSyncStatus('Repositori skripsi gagal dimuat.', 'error');
                }
            };

            const scheduleSearch = () => {
                window.clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(() => {
                    const query = queryInput.value.trim();

                    // One or two letters match almost everything; wait for more.
                    if (query.length > 0 && query.length < 2) {
                        return;
                    }

                    state.q = query;
                    syncUrl();
                    void fetchResults();
                }, 300);
            };

            const findItem = (recordKey) => state.items.find((item) => item.record_key === recordKey) || null;

            const loadSimilar = async (item, host) => {
                try {
                    const payload = await app.request(`/api/library/theses?${new URLSearchParams({ similar_to: item.title.slice(0, 200) }).toString()}`, { cache: false });
                    const similar = (Array.isArray(payload?.data?.items) ? payload.data.items : [])
                        .filter((candidate) => candidate.record_key !== item.record_key)
                        .slice(0, 4);

                    host.innerHTML = similar.length
                        ? `<ul class="flex flex-col divide-y divide-border-light">${similar.map((candidate) => `
                            <li class="py-2.5">
                                <p class="line-clamp-2 text-sm font-medium leading-snug text-ink">${app.escapeHtml(candidate.title)}</p>
                                <p class="text-xs text-ink-subtle">${app.escapeHtml([candidate.academic_year ? `T.A. ${candidate.academic_year}` : null, displayName(candidate.program), `${candidate.similarity_percent}% mirip`].filter(Boolean).join(' · '))}</p>
                            </li>`).join('')}</ul>`
                        : '<p class="text-sm text-ink-muted">Belum ada skripsi lain yang mirip.</p>';
                } catch (error) {
                    host.innerHTML = `<p class="text-sm text-ink-muted">Skripsi serupa belum bisa dimuat. ${app.escapeHtml(app.describeError(error))}</p>`;
                }
            };

            const openDetail = (item) => {
                const advisors = Array.isArray(item.advisors) ? item.advisors : [];
                const panel = app.openSheet({
                    pretitle: [item.academic_year ? `T.A. ${item.academic_year}` : null, displayName(item.program)].filter(Boolean).join(' · '),
                    title: item.title,
                    body: `
                        <div class="flex flex-col gap-4">
                            ${app.renderKeyValueList([
                                { label: 'Penulis', value: displayName(item.author) },
                                { label: 'NIM', value: item.nim },
                                { label: 'Tahun akademik', value: item.academic_year },
                                { label: 'Program studi', value: displayName(item.program) },
                            ])}
                            ${advisors.length ? `
                                <div>
                                    <h3 class="text-xs font-medium text-ink-subtle">Pembimbing</h3>
                                    <div class="mt-2 flex flex-col gap-2">
                                        ${advisors.map((advisor, index) => `<button type="button" class="${app.ui.button.ghostSm} justify-start! whitespace-normal! py-2 text-start" data-filter-advisor="${app.escapeHtml(advisor.name)}">Pembimbing ${index + 1}: ${app.escapeHtml(advisor.name)}<span class="sr-only"> (saring skripsi bimbingannya)</span></button>`).join('')}
                                    </div>
                                </div>
                            ` : ''}
                            <div>${pdfLink(item, { small: false })}</div>
                            <div>
                                <h3 class="text-xs font-medium text-ink-subtle">Skripsi serupa</h3>
                                <div class="mt-2" data-theses-similar><div class="${app.ui.skeleton} h-10 w-full" data-slot="skeleton"></div></div>
                            </div>
                        </div>
                    `,
                });

                app.refreshOnlineRequiredState();
                panel?.querySelectorAll('[data-filter-advisor]').forEach((button) => {
                    button.addEventListener('click', () => {
                        state.pembimbing = button.dataset.filterAdvisor || '';
                        app.closeSheet();
                        syncUrl();
                        void fetchResults();
                        queryInput.focus({ preventScroll: true });
                    });
                });

                const similarHost = panel?.querySelector('[data-theses-similar]');

                if (similarHost) {
                    void loadSimilar(item, similarHost);
                }
            };

            const similarityTone = (percent) => (percent > 70 ? 'danger' : (percent >= 40 ? 'warning' : 'success'));

            const runCheck = async () => {
                const title = checkInput.value.trim();

                if (title.length < 5) {
                    checkResults.innerHTML = app.renderEmptyState('Tulis rencana judul minimal 5 karakter.', 'secondary');
                    checkInput.focus();

                    return;
                }

                checkResults.innerHTML = `<div class="flex flex-col gap-3" aria-busy="true"><span class="sr-only">Membandingkan judul…</span><div class="${app.ui.skeleton} h-28 w-full rounded-xl!" data-slot="skeleton"></div><div class="${app.ui.skeleton} h-28 w-full rounded-xl!" data-slot="skeleton"></div></div>`;

                try {
                    const payload = await app.request(`/api/library/theses?${new URLSearchParams({ similar_to: title }).toString()}`, { cache: false });
                    const items = Array.isArray(payload?.data?.items) ? payload.data.items : [];
                    const partial = payload?.meta?.index?.partial === true;

                    checkResults.innerHTML = `
                        ${partial ? `<div class="${app.ui.alert.info} mb-3" role="status">Indeks repositori masih disiapkan; perbandingan baru memakai judul-judul terbaru.</div>` : ''}
                        ${items.length ? `
                            <ul class="grid gap-3 md:grid-cols-2">
                                ${items.map((item) => {
                                    const percent = Number(item.similarity_percent) || 0;
                                    const tone = similarityTone(percent);

                                    return `
                                        <li class="${app.ui.card}" data-slot="card">
                                            <div class="${app.ui.cardBody} flex flex-col gap-2">
                                                <h3 class="line-clamp-3 min-w-0 text-sm font-medium leading-snug text-ink">${app.escapeHtml(item.title)}</h3>
                                                ${app.renderProgressBar({ value: percent, max: 100, tone, label: 'Kemiripan', valueLabel: `${percent}%`, size: 'sm' })}
                                                ${metaLine(item)}
                                                <div>${pdfLink(item)}</div>
                                            </div>
                                        </li>
                                    `;
                                }).join('')}
                            </ul>
                        ` : app.renderEmptyState('Tidak ada judul yang mirip di repositori.', 'secondary', 'Tetap konsultasikan rencana judul dengan calon pembimbing.')}
                    `;
                    app.refreshOnlineRequiredState();
                } catch (error) {
                    app.mountErrorState(checkResults, 'Kemiripan judul gagal dicek.', runCheck, { subtitle: app.describeError(error) });
                }
            };

            const setMode = (mode, { focus = false } = {}) => {
                state.mode = mode === 'check' ? 'check' : 'search';
                modesHost.querySelectorAll('[data-theses-mode]').forEach((button) => {
                    const active = button.dataset.thesesMode === state.mode;

                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                searchPanel.classList.toggle('hidden', state.mode !== 'search');
                searchPanel.classList.toggle('flex', state.mode === 'search');
                checkPanel.classList.toggle('hidden', state.mode !== 'check');
                checkPanel.classList.toggle('flex', state.mode === 'check');
                syncUrl();

                if (focus) {
                    (state.mode === 'check' ? checkInput : queryInput).focus();
                }
            };

            modesHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-theses-mode]') : null;

                if (button) {
                    setMode(button.dataset.thesesMode, { focus: true });
                }
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                window.clearTimeout(debounceTimer);
                state.q = queryInput.value.trim();
                syncUrl();
                void fetchResults();
            });

            queryInput.addEventListener('input', scheduleSearch);

            Object.entries(selects).forEach(([key, select]) => {
                select.addEventListener('change', () => {
                    state[key] = select.value;
                    syncUrl();
                    void fetchResults();
                });
            });

            resetButton.addEventListener('click', () => {
                state.q = '';
                FILTER_KEYS.forEach((key) => {
                    state[key] = '';
                });
                queryInput.value = '';
                syncUrl();
                void fetchResults();
                queryInput.focus();
            });

            resultsHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-theses-detail]') : null;
                const item = button ? findItem(button.dataset.thesesDetail) : null;

                if (item) {
                    openDetail(item);
                }
            });

            moreHost.addEventListener('click', async (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-theses-more]') : null;

                if (!button || state.loadingMore) {
                    return;
                }

                state.loadingMore = true;
                button.disabled = true;
                button.textContent = 'Memuat…';

                try {
                    await fetchResults({ append: true });
                } finally {
                    state.loadingMore = false;

                    // A loaded page re-renders the button; after a failed one it is still this one.
                    if (button.isConnected) {
                        button.disabled = false;
                        button.textContent = 'Muat lagi';
                    }
                }
            });

            checkForm.addEventListener('submit', (event) => {
                event.preventDefault();
                void runCheck();
            });

            // "/" focuses the search, like the command palette (§4.3).
            document.addEventListener('keydown', (event) => {
                const target = event.target instanceof HTMLElement ? event.target : null;

                if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || target?.closest('input, textarea, select, [contenteditable="true"]')) {
                    return;
                }

                event.preventDefault();
                (state.mode === 'check' ? checkInput : queryInput).focus();
            });

            queryInput.value = state.q;
            setMode(state.mode);

            if (!hasFilters()) {
                try {
                    app.primeCachedView('/api/library/theses', (payload) => renderResults(payload), 'repositori skripsi');
                } catch (error) {
                    // A blocked device cache only means the page waits for the server.
                }
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            app.registerSyncHandler('library-theses', ({ force = false, background = false } = {}) => fetchResults({ force, background }));

            await fetchResults();
        });
    </script>
@endpush
