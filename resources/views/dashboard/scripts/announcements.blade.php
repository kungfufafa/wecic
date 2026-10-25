{{--
    Beranda script, the "Pengumuman terbaru" cards (GET /api/announcement)
    and their read marks. Part of the DOMContentLoaded handler opened in
    dashboard.blade.php: it shares that scope (app, esc, the constants and
    state in setup).
--}}
            // ---- Pengumuman terbaru (GET /api/announcement) -------------------------------------

            const announcementsHost = document.getElementById('dashboard-announcements');
            let announcementsRendered = false;

            const describePublished = (item) => {
                const days = app.daysUntil(item.published_date);

                if (days === null) {
                    return item.published_label || '';
                }

                if (days === 0) {
                    return 'Hari ini';
                }

                if (days === -1) {
                    return 'Kemarin';
                }

                if (days < 0 && days > -7) {
                    return `${Math.abs(days)} hari lalu`;
                }

                return app.formatIndonesianDate(item.published_date, { month: 'short' });
            };

            const nextDeadline = (item) => (Array.isArray(item.deadlines) ? item.deadlines : [])
                .map((deadline) => ({ ...deadline, days: app.daysUntil(deadline?.date) }))
                .filter((deadline) => deadline.days !== null && deadline.days >= 0)
                .sort((first, second) => first.days - second.days)[0] || null;

            // Same rule as /announcement: unread on this device and published in the last 7 days.
            // A mark saved under an announcement's old wording-based id moves to its stable id.
            const readAnnouncementIds = (feedItems) => {
                let stored = null;

                try {
                    stored = JSON.parse(window.localStorage.getItem(ANNOUNCEMENT_READ_KEY) || 'null');
                } catch (error) {
                    return new Set();
                }

                const { ids, changed } = app.migrateAnnouncementReadIds(stored?.ids, feedItems);

                if (changed && stored && typeof stored === 'object' && !Array.isArray(stored)) {
                    try {
                        window.localStorage.setItem(ANNOUNCEMENT_READ_KEY, JSON.stringify({ ...stored, ids }));
                    } catch (error) {
                        // The moved marks still apply to this render; the next one moves them again.
                    }
                }

                return new Set(ids);
            };

            const renderAnnouncementCard = (item, readIds = new Set()) => {
                const id = /^[0-9a-f]{40}$/.test(String(item.id || '')) ? item.id : null;
                const href = id ? `/announcement/${id}` : '/announcement';
                const publishedDays = app.daysUntil(item.published_date);
                const isNew = !app.isAnnouncementRead(readIds, item) && publishedDays !== null && publishedDays <= 0 && publishedDays >= -7;
                const deadline = nextDeadline(item);
                const deadlineLabel = deadline
                    ? `Tenggat ${deadline.days === 0 ? 'hari ini' : (deadline.days === 1 ? 'besok' : `${deadline.days} hari lagi`)}`
                    : '';
                const deadlineTone = deadline ? (deadline.days <= 2 ? 'danger' : (deadline.days <= 7 ? 'warning' : 'info')) : null;
                const meta = [describePublished(item), item.publisher_unit_short || item.publisher_unit].filter(Boolean).join(' · ');

                return `
                    <li class="min-w-0">
                        <a href="${esc(href)}" class="mycic-card flex h-full min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99]">
                            <span class="flex flex-wrap items-center gap-2">
                                ${app.renderBadge(item.category_label || 'Umum', ANNOUNCEMENT_TONES[item.category] || 'secondary')}
                                ${isNew ? app.renderBadge('Baru', 'brand') : ''}
                                ${deadline ? app.renderBadge(deadlineLabel, deadlineTone) : ''}
                            </span>
                            <span class="line-clamp-2 text-sm font-semibold text-ink">${esc(item.title || 'Pengumuman kampus')}</span>
                            ${meta ? `<span class="text-caption text-ink-subtle">${esc(meta)}</span>` : ''}
                            ${item.excerpt ? `<span class="line-clamp-2 text-caption text-ink-muted">${esc(item.excerpt)}</span>` : ''}
                        </a>
                    </li>
                `;
            };

            const renderAnnouncements = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                latestAnnouncementPayload = payload;

                const feedItems = Array.isArray(payload.data?.items) ? payload.data.items : [];
                const items = feedItems
                    .filter((item) => item && !item.duplicate_of)
                    .slice(0, 2);

                announcementsRendered = true;

                const readIds = readAnnouncementIds(feedItems);

                if (!hasChanged('announcements', { items, day: todayIso(), read: items.map((item) => app.isAnnouncementRead(readIds, item)) })) {
                    return;
                }

                announcementsHost.removeAttribute('aria-busy');
                announcementsHost.innerHTML = items.length
                    ? `<ul class="grid gap-3 sm:grid-cols-2">${items.map((item) => renderAnnouncementCard(item, readIds)).join('')}</ul>`
                    : app.renderEmptyState('Belum ada pengumuman.', 'secondary', 'Pengumuman baru dari portal kampus akan muncul di sini.');
            };

