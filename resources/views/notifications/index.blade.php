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
            <button type="button" class="mycic-btn-ghost-sm hidden" id="notification-mark-all" data-online-required>Tandai semua sudah dibaca</button>
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
            };
            const URGENCY_BADGES = { critical: 'danger', warning: 'warning', info: 'brand' };
            const GROUPS = [
                { key: 'today', label: 'Hari ini' },
                { key: 'week', label: 'Minggu ini' },
                { key: 'older', label: 'Lebih lama' },
            ];

            const params = new URLSearchParams(window.location.search);
            const state = {
                items: [],
                unreadOnly: params.get('filter') === 'unread',
                unreadCount: 0,
                nextBefore: null,
                hasMore: false,
                loadingMore: false,
                rendered: false,
            };

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

            const visibleItems = () => (state.unreadOnly ? state.items.filter((item) => !item.is_read) : state.items);

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
                moreHost.innerHTML = state.hasMore && !state.unreadOnly
                    ? `<button type="button" class="${app.buttonClass('secondary')} w-full sm:w-auto" data-load-more ${state.loadingMore ? 'disabled aria-busy="true"' : ''}>${state.loadingMore ? 'Memuat…' : 'Muat notifikasi lama'}</button>`
                    : '';
            };

            const renderList = () => {
                const items = visibleItems();

                listHost.removeAttribute('aria-busy');
                renderFilters();
                renderMore();

                if (items.length === 0) {
                    listHost.innerHTML = state.unreadOnly
                        ? app.renderEmptyState('Semua notifikasi sudah dibaca.', 'secondary', 'Notifikasi baru akan muncul di sini.')
                        : app.renderEmptyState('Belum ada notifikasi.', 'secondary', 'MyCIC akan memberi tahu saat KRS divalidasi, nilai keluar, ada tagihan baru, pengumuman baru, atau tenggat mendekat.');

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

            const receivePage = (payload, { append = false } = {}) => {
                const data = payload?.data || {};
                const items = Array.isArray(data.items) ? data.items : [];

                state.items = append ? [...state.items, ...items.filter((item) => !state.items.some((known) => known.id === item.id))] : items;
                state.unreadCount = Number(data.unread_count) || 0;
                state.hasMore = data.has_more === true;
                state.nextBefore = data.next_before ?? null;
                state.rendered = true;
                app.setNotificationUnreadCount(state.unreadCount);
                renderList();
            };

            const applyRead = (ids, unreadCount) => {
                state.items = state.items.map((item) => (ids === null || ids.includes(item.id) ? { ...item, is_read: true } : item));
                state.unreadCount = Number.isFinite(unreadCount) ? unreadCount : state.items.filter((item) => !item.is_read).length;
                app.setNotificationUnreadCount(state.unreadCount);
                app.clearCachedResponse('/api/notifications');
            };

            const markRead = async (ids) => {
                const payload = await app.request('/api/notifications/read', {
                    method: 'POST',
                    json: ids === null ? { all: true } : { ids },
                    timeout: 4000,
                });

                applyRead(ids, payload?.data?.unread_count);

                return payload;
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

            markAllButton.addEventListener('click', () => app.withSubmitLoading(markAllButton, async () => {
                try {
                    await markRead(null);
                    renderList();
                    app.showNotice('Semua notifikasi ditandai sudah dibaca.', 'success');
                } catch (error) {
                    app.showNotice(`Gagal menandai notifikasi. ${app.describeError(error)}`, 'error');
                }
            }));

            filterButtons.forEach((button) => button.addEventListener('click', () => {
                state.unreadOnly = button.dataset.notificationFilter === 'unread';

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
            }));

            moreHost.addEventListener('click', async (event) => {
                if (!(event.target instanceof Element) || !event.target.closest('[data-load-more]') || state.loadingMore || !state.nextBefore) {
                    return;
                }

                state.loadingMore = true;
                renderMore();

                try {
                    receivePage(await app.request(`/api/notifications?before=${encodeURIComponent(state.nextBefore)}`, { cache: false }), { append: true });
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
                app.primeCachedView('/api/notifications', receivePage, 'notifikasi');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadNotifications = async () => {
                try {
                    const payload = await app.request('/api/notifications');

                    if (!payload) {
                        return;
                    }

                    receivePage(payload);
                    statusHost.textContent = '';
                } catch (error) {
                    if (state.rendered) {
                        statusHost.textContent = `Notifikasi belum bisa diperbarui. ${app.describeError(error)}`;

                        return;
                    }

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
