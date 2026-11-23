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

            /**
             * The leading date tile (gray): month and day of the publication, or the
             * announcement icon in the same gray square when the date is unknown.
             */
            const renderAnnouncementDate = (item) => {
                const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(item.published_date || ''));

                if (!match) {
                    return `<span class="${app.ui.iconTile.neutral} size-11" aria-hidden="true">${app.renderIcon('announcement')}</span>`;
                }

                const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

                return `
                    <span class="${app.ui.dateTile}" aria-hidden="true">
                        <span class="${app.ui.dateTileMonth}">${esc(date.toLocaleDateString('id-ID', { month: 'short' }).replace('.', ''))}</span>
                        <span class="${app.ui.dateTileDay}">${date.getDate()}</span>
                    </span>
                `;
            };

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
                const deadlineTone = deadline ? (deadline.days <= 2 ? 'danger' : (deadline.days <= 7 ? 'warning' : 'neutral')) : null;
                // One badge at most: a near deadline, else "Baru". The category is plain text.
                const badge = deadline
                    ? app.renderBadge(deadlineLabel, deadlineTone)
                    : (isNew ? app.renderBadge('Baru', 'brand') : '');
                // The date tile shows the date; the meta line names it only when the tile cannot.
                const hasDateTile = /^\d{4}-\d{2}-\d{2}/.test(String(item.published_date || ''));
                const meta = [hasDateTile ? '' : describePublished(item), item.category_label || 'Umum', item.publisher_unit_short || item.publisher_unit].filter(Boolean).join(' · ');

                return `
                    <li class="min-w-0">
                        <a href="${esc(href)}" class="${app.ui.cardInteractive} h-full min-w-0 p-4" data-slot="card">
                            <span class="flex min-w-0 items-start gap-3">
                            ${renderAnnouncementDate(item)}
                            <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <span class="line-clamp-2 text-sm font-medium leading-snug text-ink">${esc(item.title || 'Pengumuman kampus')}</span>
                                ${hasDateTile ? `<span class="sr-only">${esc(describePublished(item))}.</span>` : ''}
                                ${meta ? `<span class="text-xs text-ink-muted">${esc(meta)}</span>` : ''}
                                ${badge ? `<span class="flex">${badge}</span>` : ''}
                                ${item.excerpt ? `<span class="line-clamp-2 text-[13px] text-ink-muted">${esc(item.excerpt)}</span>` : ''}
                            </span>
                            </span>
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
                    ? `<ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">${items.map((item) => renderAnnouncementCard(item, readIds)).join('')}</ul>`
                    : app.renderEmptyState('Belum ada pengumuman.', 'secondary', '', { icon: 'announcement' });
            };

