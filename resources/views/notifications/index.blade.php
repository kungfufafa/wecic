@extends('layouts.app')

@section('title', 'Notifikasi')
@section('page_id', 'notification-index')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Notifikasi</h1>
        <p class="mycic-page-subheading">Perubahan di portal kampus dan tenggat yang mendekat, tanpa perlu membuka portal.</p>
    </header>

    <section class="flex min-w-0 flex-col gap-3" aria-labelledby="notification-list-title">
        <h2 class="sr-only" id="notification-list-title" tabindex="-1">Daftar notifikasi</h2>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex items-center gap-1 rounded-xl border border-border/40 bg-surface p-1" role="group" aria-label="Saring notifikasi" id="notification-filters">
                <button type="button" class="inline-flex min-h-11 items-center rounded-lg border border-border/40 bg-panel px-3 text-sm font-bold text-brand shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:min-h-10" aria-pressed="true" data-notification-filter="all">Semua</button>
                <button type="button" class="inline-flex min-h-11 items-center rounded-lg border border-transparent px-3 text-sm font-semibold text-ink-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:min-h-10" aria-pressed="false" data-notification-filter="unread">Belum dibaca</button>
            </div>
            <button type="button" class="mycic-btn-ghost-sm hidden" id="notification-mark-all">Tandai semua sudah dibaca</button>
        </div>

        <p class="min-h-4 text-caption text-ink-subtle" id="notification-status" aria-live="polite"></p>

        <div id="notification-list" class="flex flex-col gap-5" aria-busy="true">
            <span class="sr-only">Memuat notifikasi…</span>
            @for ($i = 0; $i < 3; $i++)
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="mycic-skeleton h-5 w-24"></div>
                        <div class="mycic-skeleton h-5 w-4/5"></div>
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                    </div>
                </div>
            @endfor
        </div>

        <div id="notification-more" class="flex flex-col items-center gap-2 text-center"></div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const listHost = document.getElementById('notification-list');
            const moreHost = document.getElementById('notification-more');
            const statusHost = document.getElementById('notification-status');
            const markAllButton = document.getElementById('notification-mark-all');
            const filterButtons = Array.from(document.querySelectorAll('[data-notification-filter]'));
            const skeletonMarkup = listHost.innerHTML;

            const TYPE_LABELS = {
                assignment_new: 'Tugas',
                attendance_open: 'Absensi',
                exam_opened: 'Ujian',
                grade_released: 'Nilai',
                ips_complete: 'Nilai',
                krs_validated: 'KRS',
                krs_validation_reopened: 'KRS',
                krs_period_announced: 'KRS',
                bill_new: 'Keuangan',
                bill_cleared: 'Keuangan',
                payment_recorded: 'Keuangan',
                skkm_validated: 'SKKM',
                skkm_rejected: 'SKKM',
                announcement_new: 'Pengumuman',
                deadline_reminder: 'Tenggat',
                academic_period_changed: 'Portal',
                questionnaire_opened: 'Portal',
                portal_menu_added: 'Portal',
                thesis_status_changed: 'Skripsi',
                archive_suspect: 'Arsip',
                archive_class_not_run: 'Arsip',
            };
            const URGENCY_BADGES = { critical: 'danger', warning: 'warning', info: 'brand' };
            const GROUPS = [
                { key: 'today', label: 'Hari ini' },
                { key: 'week', label: 'Minggu ini' },
                { key: 'older', label: 'Lebih lama' },
            ];

            const params = new URLSearchParams(window.location.search);
            const unloadedPaging = () => ({ loaded: false, nextBefore: null, hasMore: false, top: 0 });
            // `items` holds every notification received, from either filter; each
            // filter pages on its own (`unread=1` asks the server for unread only)
            // and knows the range it received without a gap: from its floor up to `top`.
            const state = {
                items: [],
                unreadOnly: params.get('filter') === 'unread',
                unreadCount: 0,
                paging: { all: unloadedPaging(), unread: unloadedPaging() },
                loadingMore: false,
                rendered: false,
                // The filter whose first load failed: its error state stays until it loads.
                failedMode: null,
            };
            // Set once the session is confirmed; earlier clicks only render what is loaded.
            let loadNotifications = null;

            const modeOf = (unreadOnly) => (unreadOnly ? 'unread' : 'all');

            const listUrl = (mode, before = null) => {
                const query = new URLSearchParams();

                if (mode === 'unread') {
                    query.set('unread', '1');
                }

                if (before) {
                    query.set('before', String(before));
                }

                const search = query.toString();

                return `/api/notifications${search ? `?${search}` : ''}`;
            };

            // Ids at or above the floor are all known; Infinity means nothing loaded yet, 0 everything.
            const floorOf = (mode) => {
                const paging = state.paging[mode];

                if (!paging.loaded) {
                    return Infinity;
                }

                return paging.hasMore && paging.nextBefore ? Number(paging.nextBefore) : 0;
            };

            const rangeOf = (mode) => (state.paging[mode].loaded ? { floor: floorOf(mode), top: state.paging[mode].top } : null);

            /**
             * The ids a view shows without a gap, from its newest one down. Every
             * unread notification is also in the full list, so the unread view
             * reaches as deep as both ranges together where they meet.
             */
            const viewRange = (unreadOnly) => {
                const all = rangeOf('all');
                const unread = unreadOnly ? rangeOf('unread') : null;

                if (!all || !unread) {
                    return unreadOnly ? (all || unread) : all;
                }

                const [upper, lower] = all.top >= unread.top ? [all, unread] : [unread, all];

                return lower.top >= upper.floor ? { floor: Math.min(upper.floor, lower.floor), top: upper.top } : upper;
            };

            const coverageFloor = () => viewRange(state.unreadOnly)?.floor ?? Infinity;

            const canLoadMore = () => {
                const floor = coverageFloor();

                return Number.isFinite(floor) && floor > 0;
            };

            const maxKnownId = () => state.items.reduce((max, item) => Math.max(max, item.id), 0);

            const segmentClass = (active) => `inline-flex min-h-11 items-center rounded-lg px-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:min-h-10 ${active
                ? 'border border-border/40 bg-panel font-bold text-brand shadow-xs'
                : 'border border-transparent font-semibold text-ink-muted hover:text-ink'}`;

            const startOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());

            const groupOf = (item) => {
                const created = app.toDate(item.created_at);

                if (!created) {
                    return 'older';
                }

                const today = startOfDay(new Date());

                if (created >= today) {
                    return 'today';
                }

                return created >= new Date(today.getTime() - 6 * 24 * 60 * 60 * 1000) ? 'week' : 'older';
            };

            const visibleItems = () => {
                const floor = coverageFloor();

                return state.items.filter((item) => item.id >= floor && (!state.unreadOnly || !item.is_read));
            };

            const renderItem = (item) => {
                const unread = !item.is_read;
                const href = app.safeHref(item.url) && !app.isExternalHref(item.url) ? item.url : '';
                const tag = href ? 'a' : 'div';
                const meta = [app.formatTimeAgo(item.created_at), item.urgency === 'critical' ? 'Penting' : ''].filter(Boolean).join(' · ');

                return `
                    <li>
                        <${tag} ${href ? `href="${app.escapeHtml(href)}"` : ''} class="mycic-card flex min-w-0 gap-3 p-4 ${href ? 'transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand' : ''} ${unread ? 'border-brand/30 bg-surface-tint/40' : ''} md:p-5" data-notification-id="${item.id}">
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${unread ? 'bg-brand' : 'bg-transparent'}" aria-hidden="true"></span>
                            <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <span class="flex flex-wrap items-center gap-2">
                                    ${app.renderBadge(TYPE_LABELS[item.type] || 'Info', URGENCY_BADGES[item.urgency] || 'brand')}
                                    ${unread ? '<span class="sr-only">Belum dibaca.</span>' : ''}
                                    <span class="text-caption text-ink-subtle">${app.escapeHtml(meta)}</span>
                                </span>
                                <span class="text-base ${unread ? 'font-bold' : 'font-semibold'} text-ink">${app.escapeHtml(item.title || 'Notifikasi')}</span>
                                ${item.body ? `<span class="line-clamp-3 text-sm text-ink-muted">${app.escapeHtml(item.body)}</span>` : ''}
                            </span>
                        </${tag}>
                    </li>
                `;
            };

            const renderFilters = () => {
                filterButtons.forEach((button) => {
                    const active = (button.dataset.notificationFilter === 'unread') === state.unreadOnly;

                    button.className = segmentClass(active);
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                    button.textContent = button.dataset.notificationFilter === 'unread'
                        ? (state.unreadCount > 0 ? `Belum dibaca (${state.unreadCount > 99 ? '99+' : state.unreadCount})` : 'Belum dibaca')
                        : 'Semua';
                });
                markAllButton.classList.toggle('hidden', state.unreadCount === 0);
            };

            const renderMore = () => {
                moreHost.innerHTML = canLoadMore()
                    ? `<button type="button" class="${app.buttonClass('secondary')} w-full sm:w-auto" data-load-more ${state.loadingMore ? 'disabled aria-busy="true"' : ''}>${state.loadingMore ? 'Memuat…' : 'Muat notifikasi lama'}</button>`
                    : '';
            };

            const renderList = () => {
                renderFilters();

                if (!Number.isFinite(coverageFloor())) {
                    // This filter has nothing loaded yet: the skeleton, not the other filter's list, until it arrives.
                    if (state.failedMode !== modeOf(state.unreadOnly)) {
                        listHost.setAttribute('aria-busy', 'true');
                        listHost.innerHTML = skeletonMarkup;
                    }

                    moreHost.innerHTML = '';

                    return;
                }

                const items = visibleItems();

                listHost.removeAttribute('aria-busy');
                renderMore();

                if (items.length === 0) {
                    if (state.unreadOnly && canLoadMore()) {
                        listHost.innerHTML = app.renderEmptyState('Tidak ada yang belum dibaca di notifikasi terbaru.', 'secondary', 'Muat notifikasi lama untuk memeriksa yang lebih lama.');
                    } else {
                        listHost.innerHTML = state.unreadOnly
                            ? app.renderEmptyState('Semua notifikasi sudah dibaca.', 'secondary', 'Notifikasi baru akan muncul di sini.')
                            : app.renderEmptyState('Belum ada notifikasi.', 'secondary', 'MyCIC akan memberi tahu saat KRS divalidasi, nilai keluar, ada tagihan baru, pengumuman baru, atau tenggat mendekat.');
                    }

                    return;
                }

                listHost.innerHTML = GROUPS.map((group) => {
                    const groupItems = items.filter((item) => groupOf(item) === group.key);

                    if (groupItems.length === 0) {
                        return '';
                    }

                    return `
                        <section class="flex flex-col gap-2" aria-labelledby="notification-group-${group.key}">
                            <h3 class="mycic-section-title" id="notification-group-${group.key}">${group.label}</h3>
                            <ul class="flex flex-col gap-2">${groupItems.map(renderItem).join('')}</ul>
                        </section>
                    `;
                }).join('');
            };

            const mergeItems = (items) => {
                const byId = new Map(state.items.map((item) => [item.id, item]));

                items.forEach((item) => byId.set(item.id, item));
                state.items = Array.from(byId.values()).sort((left, right) => right.id - left.id);
            };

            /**
             * `append` continues the view's paging below its floor. Otherwise the
             * newest page is merged into the range the view already covers (a
             * realtime event or a sync must not drop the older pages the student
             * opened), unless the page does not reach down to that range (more
             * arrived than one page holds): then the filter's range starts over
             * from that page and "Muat notifikasi lama" fills the rest.
             */
            const receivePage = (payload, { mode, append = false, before = null } = {}) => {
                const data = payload?.data || {};
                const items = (Array.isArray(data.items) ? data.items : []).filter((item) => Number.isInteger(item?.id));
                const paging = { loaded: true, nextBefore: data.next_before ?? null, hasMore: data.has_more === true };
                const lowestId = items.reduce((min, item) => Math.min(min, item.id), Infinity);
                const highestId = items.reduce((max, item) => Math.max(max, item.id), 0);
                const pageFloor = paging.hasMore && paging.nextBefore ? Number(paging.nextBefore) : 0;
                const covered = viewRange(mode === 'unread');

                if (append) {
                    // A sync that moved the floor meanwhile leaves this older page outside the range.
                    if (covered && covered.floor === before) {
                        state.paging[mode] = { ...paging, top: Math.max(covered.top, highestId) };
                    }
                } else if (covered && (lowestId <= covered.top || !paging.hasMore)) {
                    const floor = Math.min(covered.floor, pageFloor);

                    state.paging[mode] = { loaded: true, nextBefore: floor > 0 ? floor : null, hasMore: floor > 0, top: Math.max(covered.top, highestId) };
                } else {
                    state.paging[mode] = { ...paging, top: highestId };
                }

                if (mode === 'unread' && !append) {
                    // Unread ones this page no longer lists were read elsewhere (another device).
                    const listed = new Set(items.map((item) => item.id));
                    const floor = paging.hasMore ? lowestId : 0;

                    state.items = state.items.map((item) => (!item.is_read && item.id >= floor && !listed.has(item.id) ? { ...item, is_read: true } : item));
                }

                mergeItems(items);

                if (state.failedMode === mode) {
                    state.failedMode = null;
                }

                state.unreadCount = Number(data.unread_count) || 0;
                state.rendered = true;
                app.setNotificationUnreadCount(state.unreadCount);
                renderList();
            };

            const applyRead = (ids, unreadCount, upTo = null) => {
                const marks = (item) => (ids === null ? upTo === null || item.id <= upTo : ids.includes(item.id));

                state.items = state.items.map((item) => (marks(item) ? { ...item, is_read: true } : item));
                state.unreadCount = Number.isFinite(unreadCount) ? unreadCount : state.items.filter((item) => !item.is_read).length;
                app.setNotificationUnreadCount(state.unreadCount);
                app.clearCachedResponse(listUrl('all'));
                app.clearCachedResponse(listUrl('unread'));
            };

            /**
             * `ids` null marks everything up to the newest notification this page
             * has shown: one stored meanwhile (another device, a background sync)
             * stays unread until the student has seen it.
             */
            const markRead = async (ids) => {
                const upTo = ids === null ? maxKnownId() || null : null;
                const payload = await app.request('/api/notifications/read', {
                    method: 'POST',
                    json: ids === null ? { all: true, ...(upTo ? { up_to: upTo } : {}) } : { ids },
                    timeout: 4000,
                });

                applyRead(ids, payload?.data?.unread_count, upTo);

                return payload;
            };

            // Marking read is local bookkeeping: only a missing connection blocks it, not portal-offline mode.
            const refreshMarkAllAvailability = () => {
                markAllButton.disabled = !navigator.onLine;

                if (navigator.onLine) {
                    markAllButton.removeAttribute('aria-disabled');
                    markAllButton.removeAttribute('title');
                } else {
                    markAllButton.setAttribute('aria-disabled', 'true');
                    markAllButton.setAttribute('title', 'Butuh koneksi internet');
                }
            };

            listHost.addEventListener('click', async (event) => {
                const card = event.target instanceof Element ? event.target.closest('[data-notification-id]') : null;
                const id = Number(card?.dataset.notificationId);
                const item = state.items.find((candidate) => candidate.id === id);

                if (!card || !item || item.is_read) {
                    return;
                }

                const href = card.getAttribute('href');
                const opensElsewhere = event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1;

                if (href && !opensElsewhere) {
                    event.preventDefault();
                }

                try {
                    await markRead([id]);
                } catch (error) {
                    // Opening the notification matters more than its read mark.
                }

                if (href && !opensElsewhere) {
                    window.location.href = href;
                } else {
                    renderList();
                }
            });

            markAllButton.addEventListener('click', async () => {
                await app.withSubmitLoading(markAllButton, async () => {
                    try {
                        await markRead(null);
                        renderList();

                        if (state.unreadCount > 0) {
                            app.showNotice('Notifikasi yang tampil ditandai sudah dibaca. Ada notifikasi baru yang belum kamu lihat.', 'info');

                            if (loadNotifications) {
                                void loadNotifications();
                            }
                        } else {
                            app.showNotice('Semua notifikasi ditandai sudah dibaca.', 'success');
                        }
                    } catch (error) {
                        app.showNotice(`Gagal menandai notifikasi. ${app.describeError(error)}`, 'error');
                    }
                });
                refreshMarkAllAvailability();
            });

            window.addEventListener('online', refreshMarkAllAvailability);
            window.addEventListener('offline', refreshMarkAllAvailability);
            refreshMarkAllAvailability();

            filterButtons.forEach((button) => button.addEventListener('click', () => {
                state.unreadOnly = button.dataset.notificationFilter === 'unread';
                // The filter is read again below, so an earlier failure no longer stands.
                state.failedMode = null;

                try {
                    const url = new URL(window.location.href);

                    if (state.unreadOnly) {
                        url.searchParams.set('filter', 'unread');
                    } else {
                        url.searchParams.delete('filter');
                    }

                    window.history.replaceState(null, '', url);
                } catch (error) {
                    // The filter still applies; only the shareable URL is lost.
                }

                renderList();

                // Each filter reads its own newest page (the unread one may reach further back).
                if (loadNotifications) {
                    void loadNotifications();
                }
            }));

            moreHost.addEventListener('click', async (event) => {
                if (!(event.target instanceof Element) || !event.target.closest('[data-load-more]') || state.loadingMore || !canLoadMore()) {
                    return;
                }

                const mode = modeOf(state.unreadOnly);
                const before = coverageFloor();

                state.loadingMore = true;
                renderMore();

                try {
                    const payload = await app.request(listUrl(mode, before), { cache: false });

                    if (payload) {
                        receivePage(payload, { mode, append: true, before });
                    }
                } catch (error) {
                    app.showNotice(`Notifikasi lama gagal dimuat. ${app.describeError(error)}`, 'error');
                } finally {
                    state.loadingMore = false;
                    renderMore();
                }
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && state.rendered) {
                    renderList();
                }
            });

            try {
                const initialMode = modeOf(state.unreadOnly);

                app.primeCachedView(listUrl(initialMode), (payload) => receivePage(payload, { mode: initialMode }), 'notifikasi');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            loadNotifications = async ({ background = false } = {}) => {
                const mode = modeOf(state.unreadOnly);

                try {
                    const payload = await app.request(listUrl(mode), { background });

                    if (!payload) {
                        return;
                    }

                    receivePage(payload, { mode });
                    statusHost.textContent = '';
                } catch (error) {
                    // The error state only replaces a view with nothing to show; the student may have switched filters meanwhile.
                    if (mode !== modeOf(state.unreadOnly) || Number.isFinite(coverageFloor())) {
                        statusHost.textContent = `Notifikasi belum bisa diperbarui. ${app.describeError(error)}`;

                        return;
                    }

                    state.failedMode = mode;
                    listHost.removeAttribute('aria-busy');
                    app.mountErrorState(listHost, 'Notifikasi gagal dimuat.', () => loadNotifications(), {
                        subtitle: app.describeError(error),
                    });
                }
            };

            app.registerSyncHandler('notification-index', loadNotifications);

            await loadNotifications();
        });
    </script>
@endpush
