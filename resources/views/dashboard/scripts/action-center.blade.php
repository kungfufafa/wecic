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

            const partOfDay = () => {
                const hour = new Date().getHours();

                if (hour < 11) {
                    return 'pagi';
                }

                if (hour < 15) {
                    return 'siang';
                }

                return hour < 18 ? 'sore' : 'malam';
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

            const renderActionRow = (item) => {
                const tone = app.severityTone(item.severity);
                const due = describeDue(item);
                // The server writes "· 3 hari lagi" into some titles; the live countdown replaces it.
                const title = due
                    ? String(item.title || '').replace(/\s·\s(?:\d+ hari lagi|hari terakhir)$/u, '')
                    : String(item.title || '');
                const href = app.safeHref(item.cta_url);
                const external = Boolean(href) && (item.cta_external === true || app.isExternalHref(href));
                const contact = hasContact(item.contact) ? item.contact : null;
                const ctaVariant = tone === 'danger' ? 'primary' : 'secondary';

                return `
                    <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0" data-action-item="${esc(item.type || 'item')}">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full ${app.fillToneClass(tone === 'neutral' ? 'secondary' : tone)}" aria-hidden="true"></span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                ${app.renderSeverityBadge(item.severity)}
                                ${due ? `
                                    <span class="inline-flex items-center gap-1 font-mono text-caption font-medium tabular-nums ${due.tone}">
                                        ${ICONS.clock}<span class="sr-only">Tenggat:</span> ${esc(due.label)}
                                    </span>
                                ` : ''}
                            </div>
                            <h3 class="text-sm font-medium leading-snug text-ink">${esc(title || 'Perlu dicek')}</h3>
                            ${item.description ? `<p class="line-clamp-2 text-caption text-ink-subtle">${esc(item.description)}</p>` : ''}
                            ${href || contact ? `
                                <div class="mt-1 flex flex-wrap gap-2">
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
                        <p class="flex items-start gap-2 text-caption text-ink-subtle" data-action-pending>
                            <span class="mycic-spinner mt-0.5 h-3 w-3 shrink-0" aria-hidden="true"></span>
                            <span>Data ${esc(joinSourceLabels(pending))} sedang diperbarui dari portal. Daftar ini menyesuaikan sendiri setelah selesai.</span>
                        </p>
                    `);
                }

                if (failed.length) {
                    notes.push(`
                        <p class="flex items-start gap-2 text-caption text-ink-muted" data-action-failed>
                            <span class="mt-0.5 shrink-0 text-warning">${ICONS.alert}</span>
                            <span>Data ${esc(joinSourceLabels(failed))} tidak terbaca dari portal, jadi belum masuk daftar ini.</span>
                        </p>
                    `);
                }

                if (Number.isFinite(total) && total > shownCount && shownCount > 0) {
                    notes.push(`<p class="text-caption text-ink-subtle">Menampilkan ${shownCount} dari ${total} hal yang paling mendesak.</p>`);
                }

                if (meta.cache_is_stale) {
                    const updatedAgo = app.formatTimeAgo(meta.generated_at || meta.cached_at);

                    notes.push(`<p class="text-caption text-ink-subtle">Data tersimpan di perangkat${updatedAgo ? `, diperbarui ${esc(updatedAgo)}` : ''}.</p>`);
                }

                return notes.length ? `<div class="mt-3 flex flex-col gap-1.5" role="status">${notes.join('')}</div>` : '';
            };

            const renderActionEmpty = (payload) => {
                const meta = payload?.meta || {};
                const pending = Array.isArray(meta.pending_sources) ? meta.pending_sources : [];
                const complete = meta.is_complete !== false && pending.length === 0;

                if (!complete) {
                    return app.renderEmptyState(
                        'Belum ada yang perlu diurus',
                        'secondary',
                        'Sebagian data masih diambil dari portal, jadi daftar ini bisa bertambah.'
                    );
                }

                return `
                    <div class="mycic-empty" data-action-empty>
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-success-soft text-success">${ICONS.check}</span>
                        <p class="mycic-empty-title">Semua beres</p>
                        <p class="mycic-empty-subtitle">Tidak ada tenggat atau kewajiban yang perlu kamu urus sekarang.</p>
                    </div>
                `;
            };

            const renderActionCount = (items) => {
                const critical = items.filter((item) => app.severityTone(item.severity) === 'danger').length;
                const warning = items.filter((item) => app.severityTone(item.severity) === 'warning').length;

                if (critical) {
                    return app.renderBadge(`${critical} mendesak`, 'danger');
                }

                return warning ? app.renderBadge(`${warning} segera`, 'warning') : '';
            };

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
                actionsCountHost.innerHTML = renderActionCount(items);
                actionsHost.innerHTML = items.length
                    ? `
                        <div class="mycic-card">
                            <div class="mycic-card-body">
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

