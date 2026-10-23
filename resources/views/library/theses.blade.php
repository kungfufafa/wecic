@extends('layouts.app')

@section('title', 'Repositori Skripsi')
@section('page_id', 'library-theses')

@section('content')
    <header class="mb-4">
        <a href="{{ route('library.index') }}" class="mycic-link mb-1 inline-flex min-h-9 items-center gap-1 text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6l6 6" /></svg>
            Perpustakaan
        </a>
        <h1 class="mycic-page-heading">Repositori skripsi</h1>
        <p class="mycic-page-subheading">Semua skripsi di SiMASITA Repositori, bisa dicari dan disaring tanpa membuka halaman portal satu per satu.</p>
        <p class="mt-1 min-h-4 text-caption text-ink-subtle" data-theses-updated></p>
    </header>

    <div class="mb-4 inline-flex items-center gap-1 rounded-xl border border-border/40 bg-surface p-1" role="group" aria-label="Mode repositori" id="theses-modes">
        <button type="button" class="inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold sm:min-h-10" data-theses-mode="search" aria-pressed="true">Cari</button>
        <button type="button" class="inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold sm:min-h-10" data-theses-mode="check" aria-pressed="false">Cek judul</button>
    </div>

    <section id="theses-search-panel" class="flex min-w-0 flex-col gap-3" aria-label="Cari skripsi">
        <form role="search" id="theses-form" class="sticky top-16 z-20 -mx-1 flex flex-col gap-2 bg-canvas/95 px-1 py-2 backdrop-blur-md">
            <div class="mycic-input-wrap w-full">
                <label for="theses-q" class="sr-only">Cari judul, topik, pembimbing, atau penulis</label>
                <span class="mycic-input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input type="search" id="theses-q" name="q" maxlength="100" class="mycic-input mycic-input-with-icon" placeholder="Cari judul, topik, pembimbing, atau penulis (tekan /)" autocomplete="off" enterkeyhint="search" aria-describedby="theses-result-status">
            </div>
            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                <div>
                    <label for="theses-prodi" class="sr-only">Program studi</label>
                    <select id="theses-prodi" class="mycic-input" data-theses-filter="prodi"><option value="">Semua prodi</option></select>
                </div>
                <div>
                    <label for="theses-ta" class="sr-only">Tahun akademik</label>
                    <select id="theses-ta" class="mycic-input" data-theses-filter="ta"><option value="">Semua T.A.</option></select>
                </div>
                <div>
                    <label for="theses-pembimbing" class="sr-only">Pembimbing</label>
                    <select id="theses-pembimbing" class="mycic-input" data-theses-filter="pembimbing"><option value="">Semua pembimbing</option></select>
                </div>
                <div>
                    <label for="theses-sort" class="sr-only">Urutkan</label>
                    <select id="theses-sort" class="mycic-input" data-theses-filter="sort">
                        <option value="">Urutan otomatis</option>
                        <option value="terbaru">Terbaru</option>
                        <option value="judul">Judul A–Z</option>
                        <option value="relevansi">Paling relevan</option>
                    </select>
                </div>
            </div>
        </form>

        <div id="theses-index-status" class="empty:hidden"></div>
        <div class="flex min-h-5 flex-wrap items-center justify-between gap-2">
            <p class="text-caption text-ink-subtle" id="theses-result-status" aria-live="polite"></p>
            <button type="button" class="mycic-btn-ghost-sm hidden" id="theses-reset">Hapus filter</button>
        </div>

        <ul id="theses-results" class="flex flex-col gap-3" aria-busy="true">
            <li class="sr-only">Memuat repositori…</li>
            @for ($i = 0; $i < 4; $i++)
                <li class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-2">
                        <div class="mycic-skeleton h-5 w-5/6"></div>
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </li>
            @endfor
        </ul>

        <div id="theses-more" class="flex flex-col items-center gap-2 text-center"></div>
    </section>

    <section id="theses-check-panel" class="hidden flex-col gap-3" aria-labelledby="theses-check-title">
        <form id="theses-check-form" class="mycic-card">
            <div class="mycic-card-body flex flex-col gap-3">
                <h2 class="text-base font-bold text-ink" id="theses-check-title">Cek kemiripan rencana judul</h2>
                <div class="mycic-field">
                    <label for="theses-check-input" class="mycic-label">Rencana judul</label>
                    <textarea id="theses-check-input" rows="3" maxlength="200" class="mycic-input py-2.5" placeholder="Contoh: Sistem informasi inventaris berbasis web pada koperasi" aria-describedby="theses-check-hint"></textarea>
                    <p class="mycic-field-hint" id="theses-check-hint">Judul ini hanya dibandingkan, tidak disimpan di server maupun di perangkat.</p>
                </div>
                <button type="submit" class="mycic-btn-primary self-start">Cek kemiripan</button>
            </div>
        </form>
        <p class="text-caption text-ink-subtle">Bukan cek plagiasi resmi. Kemiripan dihitung dari kata dan susunan huruf judul saja.</p>
        <div id="theses-check-results" aria-live="polite"></div>
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
            const modesHost = document.getElementById('theses-modes');
            const searchPanel = document.getElementById('theses-search-panel');
            const checkPanel = document.getElementById('theses-check-panel');
            const checkForm = document.getElementById('theses-check-form');
            const checkInput = document.getElementById('theses-check-input');
            const checkResults = document.getElementById('theses-check-results');
            const updatedLabel = document.querySelector('[data-theses-updated]');
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
                lastPayload: null,
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

            const describeFreshness = (payload) => {
                const meta = payload?.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || meta.cached_at);

                if (meta.index?.partial) {
                    return 'Indeks pertama sedang disiapkan';
                }

                if (!ago) {
                    return '';
                }

                if (meta.data_origin === 'device_cache') {
                    return `Indeks diperbarui ${ago} · data tersimpan di perangkat${meta.cache_is_stale ? ', mungkin sudah lama' : ''}`;
                }

                if (meta.status === 'syncing') {
                    return `Indeks diperbarui ${ago} · sedang diperbarui dari portal`;
                }

                if (meta.status === 'failed') {
                    return `Indeks diperbarui ${ago} · portal belum bisa dihubungi, ini indeks terakhir`;
                }

                return `Indeks diperbarui ${ago}`;
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
                    ? `<a href="${app.escapeHtml(href)}" target="_blank" rel="noopener" class="${app.buttonClass('secondary', { small })}" data-online-required>Baca PDF<span class="sr-only"> ${app.escapeHtml(item.title)} (tab baru)</span></a>`
                    : `<span class="text-caption text-ink-subtle">PDF tidak tersedia</span>`;
            };

            const advisorsLine = (item, tokens) => {
                const advisors = Array.isArray(item.advisors) ? item.advisors : [];

                return advisors.length
                    ? `Pembimbing: ${advisors.map((advisor) => highlight(advisor.name, tokens)).join(', ')}`
                    : 'Pembimbing tidak tercatat';
            };

            const renderItem = (item, tokens) => `
                <li class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-2">
                        <h3 class="line-clamp-3 text-sm font-bold text-ink">${highlight(item.title, tokens)}</h3>
                        <p class="text-caption text-ink-subtle">${app.escapeHtml([item.academic_year ? `T.A. ${item.academic_year}` : null, displayName(item.program)].filter(Boolean).join(' · '))}</p>
                        <p class="text-sm text-ink-muted">${advisorsLine(item, tokens)}</p>
                        ${item.author ? `<p class="text-caption text-ink-subtle">Oleh ${highlight(displayName(item.author), tokens)}</p>` : ''}
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            ${pdfLink(item)}
                            <button type="button" class="${app.buttonClass('ghost', { small: true })}" data-theses-detail="${app.escapeHtml(item.record_key)}" aria-haspopup="dialog">Detail</button>
                        </div>
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
                    indexStatusHost.innerHTML = `<div class="${app.alertTone('info')} mb-0 flex items-start gap-3" role="status"><span class="mycic-spinner mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></span><span>Menyiapkan indeks repositori… Sementara ini hasil dari halaman terbaru portal${index.total ? ` (${numberFormatter.format(index.record_count)} dari ${numberFormatter.format(index.total)} judul)` : ''}. Halaman ini diperbarui otomatis.</span></div>`;

                    return;
                }

                if (meta?.status === 'failed') {
                    indexStatusHost.innerHTML = `<div class="${app.alertTone('warning')} mb-0" role="status">Portal belum bisa dihubungi. Pencarian memakai indeks terakhir.</div>`;

                    return;
                }

                if (!index.complete && index.total) {
                    indexStatusHost.innerHTML = `<div class="${app.alertTone('info')} mb-0" role="status">Indeks belum lengkap: ${numberFormatter.format(index.record_count)} dari ${numberFormatter.format(index.total)} judul. Sisanya disinkronkan berikutnya.</div>`;

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
                state.lastPayload = payload;
                resultsHost.removeAttribute('aria-busy');

                if (meta.facets) {
                    fillSelect('prodi', meta.facets.prodi, 'Semua prodi');
                    fillSelect('ta', meta.facets.ta, 'Semua T.A.');
                    fillSelect('pembimbing', meta.facets.pembimbing, 'Semua pembimbing');
                }

                selects.sort.value = state.sort;
                renderIndexStatus(meta);
                resetButton.classList.toggle('hidden', !hasFilters());
                updatedLabel.textContent = describeFreshness(payload);

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
                    ? `<button type="button" class="${app.buttonClass('secondary')}" data-theses-more>Muat lagi</button><p class="text-caption text-ink-subtle">${numberFormatter.format(Math.max(0, total - state.items.length))} judul lagi</p>`
                    : (state.items.length > PER_PAGE ? '<p class="text-caption text-ink-subtle">Semua hasil sudah ditampilkan.</p>' : '');
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
                                <p class="line-clamp-2 text-sm font-semibold text-ink">${app.escapeHtml(candidate.title)}</p>
                                <p class="text-caption text-ink-subtle">${app.escapeHtml([candidate.academic_year ? `T.A. ${candidate.academic_year}` : null, displayName(candidate.program), `${candidate.similarity_percent}% mirip`].filter(Boolean).join(' · '))}</p>
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
                                    <h3 class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">Pembimbing</h3>
                                    <div class="mt-2 flex flex-col gap-2">
                                        ${advisors.map((advisor, index) => `<button type="button" class="${app.buttonClass('ghost', { small: true })} justify-start text-start" data-filter-advisor="${app.escapeHtml(advisor.name)}">Pembimbing ${index + 1}: ${app.escapeHtml(advisor.name)}<span class="sr-only"> (saring skripsi bimbingannya)</span></button>`).join('')}
                                    </div>
                                </div>
                            ` : ''}
                            <div>${pdfLink(item, { small: false })}</div>
                            <div>
                                <h3 class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">Skripsi serupa</h3>
                                <div class="mt-2" data-theses-similar><div class="mycic-skeleton h-10 w-full"></div></div>
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

                checkResults.innerHTML = '<div class="flex flex-col gap-2" aria-busy="true"><span class="sr-only">Membandingkan judul…</span><div class="mycic-skeleton h-16 w-full"></div><div class="mycic-skeleton h-16 w-full"></div></div>';

                try {
                    const payload = await app.request(`/api/library/theses?${new URLSearchParams({ similar_to: title }).toString()}`, { cache: false });
                    const items = Array.isArray(payload?.data?.items) ? payload.data.items : [];
                    const partial = payload?.meta?.index?.partial === true;

                    checkResults.innerHTML = `
                        ${partial ? `<div class="${app.alertTone('info')} mb-3" role="status">Indeks repositori masih disiapkan; perbandingan baru memakai judul-judul terbaru.</div>` : ''}
                        ${items.length ? `
                            <ul class="flex flex-col gap-3">
                                ${items.map((item) => {
                                    const percent = Number(item.similarity_percent) || 0;
                                    const tone = similarityTone(percent);

                                    return `
                                        <li class="mycic-card">
                                            <div class="mycic-card-body flex flex-col gap-2">
                                                <h3 class="line-clamp-3 min-w-0 text-sm font-bold text-ink">${app.escapeHtml(item.title)}</h3>
                                                ${app.renderProgressBar({ value: percent, max: 100, tone, label: 'Kemiripan', valueLabel: `${percent}%`, size: 'sm' })}
                                                <p class="text-caption text-ink-subtle">${app.escapeHtml([item.academic_year ? `T.A. ${item.academic_year}` : null, displayName(item.program)].filter(Boolean).join(' · '))}</p>
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
                    button.className = `inline-flex min-h-11 items-center rounded-lg px-4 text-sm sm:min-h-10 transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand ${active ? 'border border-border/40 bg-panel font-bold text-brand shadow-xs' : 'border border-transparent font-semibold text-ink-muted hover:text-ink'}`;
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

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && state.lastPayload) {
                    updatedLabel.textContent = describeFreshness(state.lastPayload);
                }
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
