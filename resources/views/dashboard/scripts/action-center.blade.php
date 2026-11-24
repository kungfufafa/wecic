{{--
    Beranda script, the "Pusat tindakan" list (GET /api/action-items).
    Part of the DOMContentLoaded handler opened in dashboard.blade.php: it
    shares that scope (app, esc, the constants and state in setup).
--}}
            // ---- Pusat tindakan (GET /api/action-items) ---------------------------------------

            const actionsHost = document.getElementById('dashboard-actions');
            const actionsCountHost = document.getElementById('dashboard-actions-count');

            const describeDue = (item) => {
                const days = item.due_at ? app.daysUntil(item.due_at) : (Number.isInteger(item.days_left) ? item.days_left : null);

                if (days === null) {
                    return null;
                }

                const date = item.due_at ? ` · ${app.formatIndonesianDate(item.due_at, { month: 'short', year: false })}` : '';

                if (days < 0) {
                    return { days, label: `Lewat ${Math.abs(days)} hari${date}`, tone: 'text-danger' };
                }

                if (days === 0) {
                    return { days, label: `Hari ini${date}`, tone: 'text-danger' };
                }

                if (days === 1) {
                    return { days, label: `Besok${date}`, tone: 'text-danger' };
                }

                return { days, label: `${days} hari lagi${date}`, tone: days <= 3 ? 'text-warning' : 'text-ink-subtle' };
            };

            const dpaMessage = (item) => {
                const student = studentInfo();
                const name = student.name || student.nama || '';
                const nim = student.student_id || student.nim || '';
                const intro = name ? `Saya ${name}${nim ? ` (NIM ${nim})` : ''}, mahasiswa bimbingan Bapak/Ibu.` : 'Saya mahasiswa bimbingan Bapak/Ibu.';
                const request = item.type === 'krs_validation'
                    ? 'Mohon bantuan validasi KRS saya di portal.'
                    : 'Saya ingin berkonsultasi tentang pengisian KRS semester ini.';

                return `Selamat ${partOfDay()}, Bapak/Ibu. ${intro} ${request} Terima kasih.`;
            };

            const hasContact = (contact) => Boolean(contact && (contact.whatsapp || contact.phone || contact.email));

            const actionIcon = (item) => {
                const context = `${item.type || ''} ${item.source || ''} ${item.cta_url || ''}`.toLowerCase();

                if (/exam|ujian/.test(context)) {
                    return 'exam';
                }

                if (/krs|advising|perwalian/.test(context)) {
                    return 'list-check';
                }

                if (/payment|finance|bill|tagihan|ukt/.test(context)) {
                    return 'credit-card';
                }

                if (/skkm/.test(context)) {
                    return 'skkm';
                }

                if (/assignment|tugas/.test(context)) {
                    return 'notebook';
                }

                return 'bell';
            };

            const compactActionDescription = (description) => {
                const text = String(description || '').trim();

                if (!text) {
                    return '';
                }

                const stripped = text.replace(/\s*\([^)]*\)\s*\.?$/, '').trim();

                return stripped || text;
            };

            const renderActionRow = (item) => {
                const tone = app.severityTone(item.severity);
                const due = describeDue(item);
                // The server writes "· 3 hari lagi" into some titles; the live countdown replaces it.
                const title = due
                    ? String(item.title || '').replace(/\s·\s(?:\d+ hari lagi|hari terakhir)$/u, '')
                    : String(item.title || '');
                const description = compactActionDescription(item.description);
                const href = app.safeHref(item.cta_url);
                const external = Boolean(href) && (item.cta_external === true || app.isExternalHref(href));
                const contact = hasContact(item.contact) ? item.contact : null;
                const ctaVariant = tone === 'danger' ? 'primary' : 'secondary';
                const iconTileClass = app.ui.iconTile[tone] || app.ui.iconTile.neutral;
                const iconName = actionIcon(item);

                return `
                    <li class="py-3.5 first:pt-0 last:pb-0" data-action-item="${esc(item.type || 'item')}">
                        <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div class="flex min-w-0 flex-1 items-start gap-3">
                                <span class="${iconTileClass} mt-0.5 size-9 rounded-lg" aria-hidden="true">
                                    ${app.renderIcon(iconName, 'sm')}
                                </span>
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        ${app.renderSeverityBadge(item.severity)}
                                        ${due ? `
                                            <span class="inline-flex items-center gap-1 text-xs font-medium tabular-nums ${due.tone}">
                                                <span class="size-1.5 rounded-full ${app.fillToneClass(tone)}" aria-hidden="true"></span>
                                                ${ICONS.clock}<span class="sr-only">Tenggat:</span> ${esc(due.label)}
                                            </span>
                                        ` : ''}
                                    </div>
                                    <h3 class="text-sm font-semibold leading-snug text-ink">${esc(title || 'Perlu dicek')}</h3>
                                    ${description ? `<p class="line-clamp-1 text-xs text-ink-muted" title="${esc(item.description)}">${esc(description)}</p>` : ''}
                                </div>
                            </div>
                            ${href || contact ? `
                                <div class="flex shrink-0 flex-wrap items-center gap-1.5 ps-12 sm:self-center sm:ps-0${href && contact ? ' sm:flex-col sm:items-end' : ''}">
                                    ${href ? `
                                        <a href="${esc(href)}" class="${app.buttonClass(ctaVariant, { small: true })}"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>
                                            ${esc(item.cta_label || 'Buka')}
                                            ${external ? `${ICONS.external}<span class="sr-only">(membuka tab baru)</span>` : ''}
                                        </a>
                                    ` : ''}
                                    ${contact ? app.renderContactButton({
                                        ...contact,
                                        role: 'Dosen Pembimbing Akademik (DPA)',
                                        message: dpaMessage(item),
                                    }, { label: 'Hubungi DPA' }) : ''}
                                </div>
                            ` : ''}
                        </div>
                    </li>
                `;
            };

            const renderActionNotes = (payload, shownCount) => {
                const meta = payload?.meta || {};
                const pending = Array.isArray(meta.pending_sources) ? meta.pending_sources : [];
                const failed = Array.isArray(meta.failed_sources) ? meta.failed_sources : [];
                const total = Number(payload?.data?.counts?.total);
                const notes = [];

                if (pending.length) {
                    notes.push(`
                        <p class="flex items-start gap-2 text-xs text-ink-subtle" data-action-pending>
                            <span class="${app.ui.spinner} mt-0.5 size-3!" aria-hidden="true"></span>
                            <span>Data ${esc(joinSourceLabels(pending))} sedang diperbarui.</span>
                        </p>
                    `);
                }

                if (failed.length) {
                    notes.push(`
                        <p class="flex items-start gap-2 text-xs text-ink-muted" data-action-failed>
                            <span class="mt-0.5 shrink-0 text-warning">${ICONS.alert}</span>
                            <span>Data ${esc(joinSourceLabels(failed))} tidak terbaca dari portal.</span>
                        </p>
                    `);
                }

                if (Number.isFinite(total) && total > shownCount && shownCount > 0) {
                    notes.push(`<p class="text-xs text-ink-subtle">Menampilkan ${shownCount} dari ${total} tindakan.</p>`);
                }

                if (meta.cache_is_stale) {
                    const updatedAgo = app.formatTimeAgo(meta.generated_at || meta.cached_at);

                    notes.push(`<p class="text-xs text-ink-subtle">Data tersimpan di perangkat${updatedAgo ? `, diperbarui ${esc(updatedAgo)}` : ''}.</p>`);
                }

                return notes.length ? `<div class="mt-3 flex flex-col gap-1.5" role="status">${notes.join('')}</div>` : '';
            };

            const renderActionEmpty = (payload) => {
                const meta = payload?.meta || {};
                const pending = Array.isArray(meta.pending_sources) ? meta.pending_sources : [];
                const complete = meta.is_complete !== false && pending.length === 0;

                if (!complete) {
                    return app.renderEmptyState(
                        'Belum ada tindakan',
                        'secondary',
                        'Sebagian data masih dimuat.',
                        { icon: 'inbox' }
                    );
                }

                return `
                    <div class="${app.ui.empty}" data-slot="empty" data-action-empty>
                        <span class="${app.ui.emptyIcon}" aria-hidden="true">${ICONS.check}</span>
                        <p class="${app.ui.emptyTitle}">Semua beres</p>
                        <p class="${app.ui.emptySubtitle}">Tidak ada tenggat aktif.</p>
                    </div>
                `;
            };

            // The muted count after the heading ("Pusat tindakan · 3"): each row already
            // carries its own severity badge, so the count repeats no colour.
            const renderActionCount = (items) => (items.length ? String(items.length) : '');

            const renderActionCenter = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                latestActionPayload = payload;

                const items = app.extractActionItems(payload);
                const meta = payload.meta || {};

                scheduleRecheck('actions', Array.isArray(meta.pending_sources) && meta.pending_sources.length > 0, (options) => loadActionCenter(options));

                if (!hasChanged('actions', {
                    items,
                    counts: payload.data?.counts,
                    pending: meta.pending_sources,
                    failed: meta.failed_sources,
                    complete: meta.is_complete,
                    stale: meta.cache_is_stale === true,
                    day: todayIso(),
                })) {
                    return;
                }

                const preview = items.slice(0, ACTION_PREVIEW_LIMIT);

                actionsHost.removeAttribute('aria-busy');
                actionsCountHost.textContent = renderActionCount(items);
                actionsHost.innerHTML = items.length
                    ? `
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardBody}">
                                <ul class="flex flex-col divide-y divide-border-light" aria-label="Tindakan paling mendesak">
                                    ${preview.map(renderActionRow).join('')}
                                </ul>
                                ${renderActionNotes(payload, items.length)}
                                ${items.length > ACTION_PREVIEW_LIMIT ? `
                                    <button type="button" class="${app.buttonClass('ghost', { small: true })} mt-3 w-full" data-action-center-all aria-haspopup="dialog">
                                        Lihat semua ${items.length} tindakan
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `
                    : `${renderActionEmpty(payload)}${renderActionNotes(payload, 0)}`;
                actionsHost.querySelector('[data-action-center-all]')?.addEventListener('click', openAllActions);
            };

            const renderActionCenterFromDevice = () => {
                const cached = app.getCachedResponse('/api/action-items');

                if (cached?.payload) {
                    renderActionCenter(cached.payload);
                }
            };

            function openAllActions() {
                const items = app.extractActionItems(latestActionPayload);

                if (!items.length) {
                    return;
                }

                app.openSheet({
                    pretitle: `${items.length} hal perlu diurus`,
                    title: 'Pusat tindakan',
                    body: `
                        <ul class="flex flex-col divide-y divide-border-light" aria-label="Semua tindakan">
                            ${items.map(renderActionRow).join('')}
                        </ul>
                        ${renderActionNotes(latestActionPayload, items.length)}
                    `,
                });
            }

            // The nav badges (PortalApp.loadActionItems) and realtime updates read the same
            // endpoint; whoever fetched it, this card re-renders from the stored response.
            window.addEventListener('portal:action-items', renderActionCenterFromDevice);

