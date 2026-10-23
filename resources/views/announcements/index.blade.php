@extends('layouts.app')

@section('title', 'Pengumuman')
@section('page_id', 'announcement-index')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Pengumuman</h1>
        <p class="mycic-page-subheading">Info resmi kampus dari portal. Tenggat terdekat selalu ada di paling atas.</p>
        <p class="mt-1 min-h-4 text-caption text-ink-subtle" data-announcement-updated></p>
    </header>

    <div class="flex flex-col gap-5">
        <div id="announcement-notice" class="empty:hidden"></div>

        <section id="announcement-deadlines" class="hidden" aria-labelledby="announcement-deadlines-title">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="mycic-section-title" id="announcement-deadlines-title">Tenggat mendatang</h2>
                <span class="text-caption text-ink-subtle" id="announcement-deadline-count"></span>
            </div>
            <ul class="mycic-no-scrollbar relative flex snap-x snap-mandatory gap-3 overflow-x-auto pb-1 md:grid md:grid-cols-2 md:overflow-visible lg:grid-cols-3" id="announcement-deadline-list"></ul>
        </section>

        <section class="flex min-w-0 flex-col gap-3" aria-labelledby="announcement-list-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="mycic-section-title" id="announcement-list-title" tabindex="-1">Semua pengumuman</h2>
                <button type="button" class="mycic-btn-ghost-sm hidden" id="announcement-mark-all">Tandai semua sudah dibaca</button>
            </div>

            <div class="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="mycic-input-wrap w-full lg:w-80 lg:shrink-0">
                    <label for="announcement-search" class="sr-only">Cari pengumuman</label>
                    <span class="mycic-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    </span>
                    <input
                        type="search"
                        id="announcement-search"
                        class="mycic-input mycic-input-with-icon"
                        placeholder="Cari judul, isi, atau unit…"
                        autocomplete="off"
                        enterkeyhint="search"
                        aria-describedby="announcement-result-status"
                    >
                </div>
                <div class="mycic-no-scrollbar min-w-0 overflow-x-auto">
                    <div class="inline-flex items-center gap-1 rounded-xl border border-border/40 bg-surface p-1" role="group" aria-label="Saring kategori pengumuman" id="announcement-categories">
                        <span class="mycic-skeleton h-10 w-20"></span>
                        <span class="mycic-skeleton h-10 w-24"></span>
                        <span class="mycic-skeleton h-10 w-20"></span>
                    </div>
                </div>
            </div>

            <p class="min-h-4 text-caption text-ink-subtle" id="announcement-result-status" aria-live="polite"></p>

            <div id="announcement-list" class="flex flex-col gap-3" aria-busy="true">
                <span class="sr-only">Memuat pengumuman…</span>
                @for ($i = 0; $i < 3; $i++)
                    <div class="mycic-card">
                        <div class="mycic-card-body flex flex-col gap-3">
                            <div class="flex gap-2">
                                <div class="mycic-skeleton h-5 w-24"></div>
                                <div class="mycic-skeleton h-5 w-16"></div>
                            </div>
                            <div class="mycic-skeleton h-5 w-4/5"></div>
                            <div class="mycic-skeleton h-3.5 w-1/3"></div>
                            <div class="mycic-skeleton h-3.5 w-full"></div>
                        </div>
                    </div>
                @endfor
            </div>

            <div id="announcement-more" class="flex flex-col items-center gap-2 text-center"></div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const listHost = document.getElementById('announcement-list');
            const listTitle = document.getElementById('announcement-list-title');
            const moreHost = document.getElementById('announcement-more');
            const noticeHost = document.getElementById('announcement-notice');
            const deadlineSection = document.getElementById('announcement-deadlines');
            const deadlineList = document.getElementById('announcement-deadline-list');
            const deadlineCount = document.getElementById('announcement-deadline-count');
            const categoryHost = document.getElementById('announcement-categories');
            const searchInput = document.getElementById('announcement-search');
            const resultStatus = document.getElementById('announcement-result-status');
            const markAllButton = document.getElementById('announcement-mark-all');
            const updatedLabel = document.querySelector('[data-announcement-updated]');

            const READ_KEY = 'mycic-announcement-read';
            const DISMISSED_KEY = 'mycic-dismissed-notices';
            const AUTO_LOAD_LIMIT = 4;
            const CATEGORY_ORDER = ['krs', 'pembayaran', 'semester_pendek', 'surat', 'akademik', 'umum'];
            const CATEGORY_LABELS = {
                krs: 'KRS & Her-Registrasi',
                pembayaran: 'Keuangan',
                semester_pendek: 'Semester Pendek',
                surat: 'Surat',
                akademik: 'Akademik',
                umum: 'Umum',
            };
            const DEADLINE_TYPES = { deadline: 'Batas akhir', period: 'Periode', date: 'Tanggal penting' };
            const TILE_TONES = {
                danger: 'bg-danger-soft text-danger',
                warning: 'bg-warning-soft text-warning',
                info: 'bg-info-soft text-info',
            };

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
                        // Read marks are a per-device convenience; losing them is harmless.
                    }
                },
            };

            const params = new URLSearchParams(window.location.search);
            const requestedCategory = params.get('kategori');
            const state = {
                feedItems: [],
                extraItems: [],
                deadlines: [],
                notice: null,
                nextPage: null,
                total: null,
                // The portal total of the feed the loaded pages continue.
                feedTotal: null,
                feedComplete: false,
                category: CATEGORY_ORDER.includes(requestedCategory) ? requestedCategory : 'semua',
                query: params.get('q') || '',
                loadingMore: false,
                autoLoads: 0,
                rendered: false,
                readLoaded: false,
                readIds: new Set(),
                lastPayload: null,
            };

            const monthFormatter = new Intl.DateTimeFormat('id-ID', { month: 'short' });
            const shortDate = (value) => app.formatIndonesianDate(value, { month: 'short', year: false });
            const deadlineTone = (days) => (days <= 3 ? 'danger' : (days <= 7 ? 'warning' : 'info'));
            const isFiltering = () => state.category !== 'semua' || state.query.trim() !== '';

            const describeFreshness = (payload) => {
                const meta = payload?.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || meta.cached_at || payload?.data?.generated_at);

                if (!ago) {
                    return '';
                }

                if (meta.data_origin === 'device_cache') {
                    return `Diperbarui ${ago} · data tersimpan di perangkat${meta.cache_is_stale ? ', mungkin sudah lama' : ''}`;
                }

                if (meta.status === 'syncing') {
                    return `Diperbarui ${ago} · sedang diperbarui dari portal`;
                }

                if (meta.status === 'failed') {
                    return `Diperbarui ${ago} · portal belum bisa dihubungi, ini data terakhir`;
                }

                return `Diperbarui ${ago}`;
            };

            // The same wording is the same announcement, even under another id (a copy saved before stable ids).
            const itemKey = (item) => item.legacy_id || item.id;

            // Read marks saved before stable ids hold the old, wording-based id.
            const isRead = (item) => app.isAnnouncementRead(state.readIds, item);

            const allItems = () => {
                const seen = new Set();

                return [...state.feedItems, ...state.extraItems].filter((item) => {
                    if (!item || typeof item !== 'object' || !item.id || seen.has(itemKey(item))) {
                        return false;
                    }

                    seen.add(itemKey(item));

                    return true;
                });
            };

            const loadReadIds = (items) => {
                const stored = storage.read(READ_KEY);
                const ids = Array.isArray(stored?.ids) ? stored.ids : [];

                if (stored?.seeded) {
                    state.readIds = new Set(ids);
                    migrateReadIds(items);

                    return;
                }

                // First visit: only the last two weeks start out unread.
                const seeded = items
                    .filter((item) => (app.daysUntil(item.published_date) ?? 0) < -14)
                    .map((item) => item.id);

                state.readIds = new Set([...ids, ...seeded]);
                migrateReadIds(items);
                saveReadIds();
            };

            const saveReadIds = () => {
                storage.write(READ_KEY, { ids: Array.from(state.readIds).slice(-400), seeded: true });
            };

            // A mark saved under an old wording-based id moves to the stable id once its announcement is listed.
            const migrateReadIds = (items) => {
                const { ids, changed } = app.migrateAnnouncementReadIds(Array.from(state.readIds), items);

                if (changed) {
                    state.readIds = new Set(ids);
                    saveReadIds();
                }
            };

            const nextDeadline = (item) => (Array.isArray(item.deadlines) ? item.deadlines : [])
                .map((deadline) => ({ ...deadline, days: app.daysUntil(deadline?.date) }))
                .filter((deadline) => deadline.days !== null && deadline.days >= 0)
                .sort((first, second) => first.days - second.days)[0] || null;

            const isOngoing = (deadline) => {
                const startDays = app.daysUntil(deadline.start);

                return startDays !== null && startDays <= 0 && deadline.days >= 0;
            };

            const publishedLabel = (item) => {
                const days = app.daysUntil(item.published_date);

                if (days === 0) {
                    return 'Hari ini';
                }

                if (days === -1) {
                    return 'Kemarin';
                }

                if (days !== null && days < 0 && days > -7) {
                    return `${Math.abs(days)} hari lalu`;
                }

                return app.formatIndonesianDate(item.published_date, { month: 'short', fallback: 'Tanggal tidak terbaca' });
            };

            const isRecent = (item) => {
                const days = app.daysUntil(item.published_date);

                return days !== null && days <= 0 && days >= -7;
            };

            const noticeKey = (notice) => `${notice.kind || 'general'}:${String(notice.text || '').slice(0, 160)}`;

            const paymentStageIsPaid = (notice) => {
                // The server checks the stage against the payments snapshot (K5);
                // the device copy of the finance summary is only a fallback.
                if (typeof notice.stage_paid === 'boolean') {
                    return notice.stage_paid;
                }

                const stage = String(notice.text || '').match(/tahap\s*(?:ke[\s-]*)?(\d{1,2})/i);

                if (!stage) {
                    return false;
                }

                try {
                    const paid = app.getCachedResponse('/api/finance/summary')?.payload?.data?.current_year_stages_paid;

                    return Array.isArray(paid) && paid.includes(Number(stage[1]));
                } catch (error) {
                    return false;
                }
            };

            const renderNotice = () => {
                const notice = state.notice;

                noticeHost.innerHTML = '';

                if (!notice || !notice.text) {
                    return;
                }

                const futureDates = (Array.isArray(notice.dates) ? notice.dates : [])
                    .filter((date) => (app.daysUntil(date) ?? -1) >= 0)
                    .sort();

                if (!futureDates.length || (notice.kind === 'payment' && paymentStageIsPaid(notice))) {
                    return;
                }

                const dismissed = storage.read(DISMISSED_KEY);

                if (Array.isArray(dismissed) && dismissed.includes(noticeKey(notice))) {
                    return;
                }

                const lastDate = futureDates[futureDates.length - 1];
                const isPayment = notice.kind === 'payment';

                noticeHost.innerHTML = `
                    <div class="${app.alertTone(isPayment ? 'warning' : 'info')} flex items-start gap-3" role="status">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">${isPayment ? 'Pengingat pembayaran dari portal' : 'Info dari portal'} · ${app.escapeHtml(app.formatRelativeDays(lastDate))}</p>
                            <p class="mt-1 text-sm text-ink-muted">${app.escapeHtml(notice.text)}</p>
                            ${isPayment ? '<a href="/finance#tagihan" class="mycic-link mt-2 inline-flex min-h-9 items-center text-sm">Cek status tagihan</a>' : ''}
                        </div>
                        <button type="button" class="mycic-modal-close min-h-11 min-w-11 shrink-0 sm:min-h-9 sm:min-w-9" aria-label="Tutup pengingat ini" data-dismiss-notice>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                        </button>
                    </div>
                `;

                noticeHost.querySelector('[data-dismiss-notice]')?.addEventListener('click', () => {
                    const stored = storage.read(DISMISSED_KEY);
                    const next = Array.isArray(stored) ? stored : [];

                    next.push(noticeKey(notice));
                    storage.write(DISMISSED_KEY, next.slice(-30));
                    noticeHost.innerHTML = '';
                    listTitle.focus({ preventScroll: true });
                });
            };

            const renderDeadlines = () => {
                const upcoming = state.deadlines
                    .map((deadline) => ({ ...deadline, days: app.daysUntil(deadline?.date) }))
                    .filter((deadline) => deadline.days !== null && deadline.days >= 0 && deadline.announcement_id)
                    .sort((first, second) => first.days - second.days)
                    .slice(0, 6);

                deadlineSection.classList.toggle('hidden', upcoming.length === 0);

                if (!upcoming.length) {
                    deadlineList.innerHTML = '';

                    return;
                }

                deadlineCount.textContent = `${upcoming.length} tenggat dalam waktu dekat`;
                deadlineList.innerHTML = upcoming.map((deadline) => {
                    const tone = deadlineTone(deadline.days);
                    const date = app.toDate(deadline.date);
                    const ongoing = isOngoing(deadline);
                    const relative = app.formatRelativeDays(deadline.date);
                    const headline = ongoing ? `Berlangsung, berakhir ${relative}` : app.capitalize(relative);
                    const range = deadline.start && deadline.start !== deadline.date
                        ? `${shortDate(deadline.start)} – ${shortDate(deadline.date)}`
                        : shortDate(deadline.date);

                    return `
                        <li class="w-64 shrink-0 snap-start md:w-auto">
                            <a href="/announcement/${encodeURIComponent(deadline.announcement_id)}" class="mycic-card flex h-full min-w-0 flex-col gap-2.5 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg ${TILE_TONES[tone]}" aria-hidden="true">
                                        <span class="text-lg font-extrabold leading-none tabular-nums">${date ? date.getDate() : '–'}</span>
                                        <span class="mt-0.5 text-[0.65rem] font-bold uppercase">${date ? app.escapeHtml(monthFormatter.format(date)) : ''}</span>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-bold ${app.textToneClass(tone)}">${app.escapeHtml(headline)}</span>
                                        <span class="block truncate text-caption text-ink-subtle">${app.escapeHtml(DEADLINE_TYPES[deadline.type] || 'Tenggat')} · ${app.escapeHtml(range)}</span>
                                        <span class="sr-only">${app.escapeHtml(app.formatIndonesianDate(deadline.date, { weekday: true }))}.</span>
                                    </span>
                                </span>
                                <span class="line-clamp-2 text-sm font-semibold text-ink">${app.escapeHtml(deadline.title || 'Pengumuman')}</span>
                                <span class="mt-auto">${app.renderBadge(deadline.category_label || CATEGORY_LABELS[deadline.category] || 'Umum', 'secondary')}</span>
                            </a>
                        </li>
                    `;
                }).join('');
            };

            const categoryButtonClass = (active) => `inline-flex min-h-11 shrink-0 items-center sm:min-h-10 gap-1.5 whitespace-nowrap rounded-lg px-3 text-xs transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm ${active
                ? 'border border-border/40 bg-panel font-bold text-brand shadow-xs'
                : 'border border-transparent font-semibold text-ink-muted hover:text-ink'}`;

            const renderCategories = (items) => {
                const counts = new Map();
                const labels = new Map();

                items.forEach((item) => {
                    counts.set(item.category, (counts.get(item.category) || 0) + 1);

                    if (item.category_label) {
                        labels.set(item.category, item.category_label);
                    }
                });

                const keys = CATEGORY_ORDER.filter((key) => counts.has(key) || key === state.category);
                const chip = (key, label, count) => {
                    const active = state.category === key;

                    return `
                        <button type="button" class="${categoryButtonClass(active)}" aria-pressed="${active ? 'true' : 'false'}" data-category="${app.escapeHtml(key)}">
                            ${app.escapeHtml(label)}
                            <span class="tabular-nums ${active ? 'text-brand' : 'text-ink-subtle'}">${count}</span>
                        </button>
                    `;
                };

                categoryHost.innerHTML = chip('semua', 'Semua', items.length)
                    + keys.map((key) => chip(key, labels.get(key) || CATEGORY_LABELS[key], counts.get(key) || 0)).join('');
            };

            const normalize = (value) => String(value ?? '').toLocaleLowerCase('id-ID');

            const filteredItems = (items) => {
                const tokens = normalize(state.query).split(/\s+/).filter(Boolean);

                return items.filter((item) => {
                    if (state.category !== 'semua' && item.category !== state.category) {
                        return false;
                    }

                    if (!tokens.length) {
                        return true;
                    }

                    const haystack = normalize([
                        item.title,
                        item.excerpt,
                        item.body_text,
                        item.publisher,
                        item.publisher_unit,
                        item.publisher_unit_short,
                        item.category_label,
                    ].join(' '));

                    return tokens.every((token) => haystack.includes(token));
                });
            };

            const renderCard = (item) => {
                const unread = !isRead(item);
                const deadline = nextDeadline(item);
                const unit = item.publisher_unit_short || item.publisher_unit || item.publisher || '';
                let deadlineBadge = '';

                if (deadline) {
                    deadlineBadge = app.renderBadge(
                        isOngoing(deadline) ? `Berlangsung s.d. ${shortDate(deadline.date)}` : `Tenggat ${app.formatRelativeDays(deadline.date)}`,
                        deadlineTone(deadline.days)
                    );
                }

                return `
                    <a href="/announcement/${encodeURIComponent(item.id)}" class="mycic-card flex min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand md:p-5" data-announcement-card="${app.escapeHtml(item.id)}">
                        <span class="flex flex-wrap items-center gap-2">
                            ${unread ? '<span class="h-2.5 w-2.5 shrink-0 rounded-full bg-brand" aria-hidden="true" data-unread-dot></span><span class="sr-only">Belum dibaca.</span>' : ''}
                            ${app.renderBadge(item.category_label || CATEGORY_LABELS[item.category] || 'Umum', 'brand')}
                            ${unread && isRecent(item) ? '<span class="mycic-badge-accent">Baru</span>' : ''}
                            ${deadlineBadge}
                            ${item.duplicate_of ? app.renderBadge('Mirip pengumuman lain', 'secondary') : ''}
                        </span>
                        <span class="line-clamp-2 text-base ${unread ? 'font-bold' : 'font-semibold'} text-ink">${app.escapeHtml(item.title || 'Pengumuman')}</span>
                        <span class="text-caption text-ink-subtle">${app.escapeHtml([publishedLabel(item), unit].filter(Boolean).join(' · '))}</span>
                        ${item.excerpt ? `<span class="line-clamp-2 text-sm text-ink-muted">${app.escapeHtml(item.excerpt)}</span>` : ''}
                    </a>
                `;
            };

            const renderMore = () => {
                if (!state.nextPage) {
                    moreHost.innerHTML = state.rendered && state.feedComplete && allItems().length
                        ? '<p class="text-caption text-ink-subtle">Semua pengumuman sudah dimuat.</p>'
                        : '';
                    observer?.disconnect();

                    return;
                }

                moreHost.innerHTML = `
                    <button type="button" class="${app.buttonClass('secondary')} w-full sm:w-auto" data-load-more ${state.loadingMore ? 'disabled aria-busy="true"' : ''}>
                        ${state.loadingMore ? '<span class="mycic-spinner" aria-hidden="true"></span> Memuat pengumuman lama…' : 'Muat pengumuman lama'}
                    </button>
                    <p class="text-caption text-ink-subtle">Diambil dari portal kampus, 3 pengumuman per halaman.</p>
                `;
                observeMore();
            };

            const renderList = () => {
                const items = allItems();
                const filtered = filteredItems(items);
                const unreadCount = items.filter((item) => !isRead(item)).length;

                listHost.removeAttribute('aria-busy');
                searchInput.disabled = false;
                renderCategories(items);
                markAllButton.classList.toggle('hidden', unreadCount === 0);
                markAllButton.textContent = `Tandai ${unreadCount} sudah dibaca`;

                if (!items.length) {
                    listHost.innerHTML = app.renderEmptyState('Belum ada pengumuman.', 'secondary', 'Pengumuman baru dari portal kampus akan muncul di sini.');
                    resultStatus.textContent = '';
                    renderMore();

                    return;
                }

                listHost.innerHTML = filtered.length
                    ? filtered.map(renderCard).join('')
                    : `
                        <div class="mycic-empty">
                            <p class="mycic-empty-title">Tidak ada pengumuman yang cocok.</p>
                            <p class="mycic-empty-subtitle">${state.nextPage ? 'Coba kata kunci lain, atau muat pengumuman lama di bawah.' : 'Coba kata kunci lain atau pilih kategori Semua.'}</p>
                            <button type="button" class="${app.buttonClass('secondary', { small: true })} mt-2" data-reset-filters>Tampilkan semua</button>
                        </div>
                    `;

                const total = Number.isInteger(state.total) ? ` dari ${state.total} di portal` : '';

                resultStatus.textContent = isFiltering()
                    ? `${filtered.length} dari ${items.length} pengumuman yang sudah dimuat`
                    : `${items.length} pengumuman terbaru${total}`;
                renderMore();
            };

            const syncQuery = () => {
                try {
                    const url = new URL(window.location.href);

                    if (state.category === 'semua') {
                        url.searchParams.delete('kategori');
                    } else {
                        url.searchParams.set('kategori', state.category);
                    }

                    if (state.query.trim()) {
                        url.searchParams.set('q', state.query.trim());
                    } else {
                        url.searchParams.delete('q');
                    }

                    window.history.replaceState(window.history.state, '', url);
                } catch (error) {
                    // The address bar is only a convenience for sharing the filter.
                }
            };

            const appendPage = (payload) => {
                const data = payload?.data || {};
                const known = new Set(allItems().map(itemKey));
                const knownIds = new Set(allItems().map((item) => item.id));
                const added = [];

                (Array.isArray(data.items) ? data.items : []).forEach((item) => {
                    if (item?.id && !known.has(itemKey(item))) {
                        // Another announcement listed earlier already goes by this id (same title, day and unit): this one opens by its own wording.
                        const entry = knownIds.has(item.id) && item.legacy_id ? { ...item, id: item.legacy_id } : item;

                        state.extraItems.push(entry);
                        known.add(itemKey(entry));
                        knownIds.add(entry.id);
                        added.push(entry.id);
                    }
                });

                const paging = data.paging || {};

                state.nextPage = paging.has_next && paging.next_page ? paging.next_page : null;
                state.feedComplete = !state.nextPage;

                if (Number.isInteger(paging.total)) {
                    state.total = paging.total;
                }

                return added;
            };

            // A page is a window of the portal's newest-first list: a copy saved
            // before new posts were made is shifted, so it would leave a gap.
            const continuesFeed = (payload) => {
                const total = payload?.data?.paging?.total;

                return !Number.isInteger(state.feedTotal) || !Number.isInteger(total) || total === state.feedTotal;
            };

            const hydrateCachedPages = () => {
                for (let guard = 0; state.nextPage && guard < 20; guard += 1) {
                    const pageUrl = `/api/announcement?page=${state.nextPage}`;
                    let cached = null;

                    try {
                        cached = app.getCachedResponse(pageUrl);
                    } catch (error) {
                        cached = null;
                    }

                    if (!cached?.payload?.data) {
                        return;
                    }

                    if (!continuesFeed(cached.payload)) {
                        app.clearCachedResponse(pageUrl);

                        return;
                    }

                    appendPage(cached.payload);
                }
            };

            const loadMore = async ({ auto = false } = {}) => {
                if (state.loadingMore || !state.nextPage) {
                    return;
                }

                const page = state.nextPage;
                let added = [];

                state.loadingMore = true;
                renderMore();

                try {
                    const payload = await app.request(`/api/announcement?page=${page}`);

                    if (!payload) {
                        return;
                    }

                    added = appendPage(payload);

                    if (state.readLoaded) {
                        migrateReadIds(allItems());
                    }

                    if (auto) {
                        state.autoLoads += 1;
                    }
                } catch (error) {
                    state.autoLoads = AUTO_LOAD_LIMIT;
                    app.showNotice(`Pengumuman lama belum bisa dimuat. ${app.describeError(error)}`, 'error');
                } finally {
                    state.loadingMore = false;
                    renderList();
                }

                if (!auto && added.length) {
                    listHost.querySelector(`[data-announcement-card="${CSS.escape(added[0])}"]`)?.focus({ preventScroll: false });
                }
            };

            let observer = null;

            const observeMore = () => {
                if (!('IntersectionObserver' in window)) {
                    return;
                }

                observer ??= new IntersectionObserver((entries) => {
                    // Only after the student scrolls, so opening the page never pulls extra portal pages.
                    if (!entries.some((entry) => entry.isIntersecting) || window.scrollY < 80) {
                        return;
                    }

                    if (!isFiltering() && state.autoLoads < AUTO_LOAD_LIMIT && navigator.onLine) {
                        void loadMore({ auto: true });
                    }
                }, { rootMargin: '240px 0px' });

                observer.disconnect();
                observer.observe(moreHost);
            };

            const renderFeed = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                const paging = data.paging || {};

                state.feedItems = Array.isArray(data.items) ? data.items : [];
                state.deadlines = Array.isArray(data.upcoming_deadlines) ? data.upcoming_deadlines : [];
                state.notice = data.notice || null;
                state.lastPayload = payload;

                if (Number.isInteger(paging.total)) {
                    if (state.extraItems.length && Number.isInteger(state.feedTotal) && paging.total !== state.feedTotal) {
                        // New posts shifted every older page: load them again from this feed.
                        state.extraItems = [];
                        state.autoLoads = 0;
                    }

                    state.total = paging.total;
                    state.feedTotal = paging.total;
                }

                if (!state.extraItems.length) {
                    state.nextPage = paging.has_more && paging.next_page ? paging.next_page : null;
                    state.feedComplete = !state.nextPage && paging.complete !== false;
                    hydrateCachedPages();
                }

                if (!state.readLoaded) {
                    loadReadIds(allItems());
                    state.readLoaded = true;
                } else {
                    migrateReadIds(allItems());
                }

                state.rendered = true;
                renderNotice();
                renderDeadlines();
                renderList();
                updatedLabel.textContent = describeFreshness(payload);
            };

            searchInput.value = state.query;

            let searchTimer = null;

            searchInput.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(() => {
                    state.query = searchInput.value;
                    syncQuery();

                    if (state.rendered) {
                        renderList();
                    }
                }, 250);
            });

            categoryHost.addEventListener('click', (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-category]') : null;

                if (!button || !state.rendered) {
                    return;
                }

                state.category = button.dataset.category;
                syncQuery();
                renderList();
                categoryHost.querySelector(`[data-category="${CSS.escape(state.category)}"]`)?.focus();
            });

            listHost.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (target?.closest('[data-reset-filters]')) {
                    state.category = 'semua';
                    state.query = '';
                    searchInput.value = '';
                    syncQuery();
                    renderList();
                    searchInput.focus();

                    return;
                }

                const card = target?.closest('[data-announcement-card]');

                if (card) {
                    state.readIds.add(card.dataset.announcementCard);
                    saveReadIds();
                }
            });

            moreHost.addEventListener('click', (event) => {
                if (event.target instanceof Element && event.target.closest('[data-load-more]')) {
                    void loadMore();
                }
            });

            markAllButton.addEventListener('click', () => {
                allItems().forEach((item) => state.readIds.add(item.id));
                saveReadIds();
                renderList();
                listTitle.focus({ preventScroll: true });
                app.showNotice('Semua pengumuman ditandai sudah dibaca.', 'success');
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && state.rendered) {
                    renderDeadlines();
                    renderList();
                    updatedLabel.textContent = describeFreshness(state.lastPayload);
                }
            });

            try {
                app.primeCachedView('/api/announcement', renderFeed, 'pengumuman');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadAnnouncements = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/announcement', { fresh: force, background });

                    if (!payload) {
                        return;
                    }

                    renderFeed(payload);
                    app.markViewSynced('Pengumuman', payload, { force });
                } catch (error) {
                    if (state.rendered) {
                        app.setSyncStatus(`Pengumuman belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    listHost.removeAttribute('aria-busy');
                    resultStatus.textContent = '';
                    // Nothing to filter or search yet: no endless skeleton pills, no live search box.
                    categoryHost.innerHTML = '';
                    searchInput.disabled = true;
                    app.mountErrorState(listHost, 'Pengumuman gagal dimuat.', () => loadAnnouncements({ force }), {
                        subtitle: `${app.describeError(error)} Kalau terus gagal, pengumuman juga bisa dibaca di Portal kampus.`,
                    });
                    app.setSyncStatus('Pengumuman gagal dimuat.', 'error');
                }
            };

            app.registerSyncHandler('announcement-index', loadAnnouncements);

            await loadAnnouncements();
        });
    </script>
@endpush
