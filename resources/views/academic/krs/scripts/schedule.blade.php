{{--
    KRS script, the period schedule and the notes beside it. Part of the
    DOMContentLoaded handler opened in academic/krs.blade.php: it shares
    that scope (app, esc, hosts, state and the helpers in setup).
--}}
            const renderSchedule = () => {
                const krs = state.krs;

                if (!krs) {
                    return;
                }

                const code = krs.insights?.status?.code || krs.status?.code || 'unknown';
                const { periods, active } = pickPeriods(code);

                if (!periods.length) {
                    hosts.schedule.innerHTML = `
                        <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-schedule-title">
                            <div class="${app.ui.cardHeader}"><h2 class="${app.ui.cardTitle}" id="krs-schedule-title">Jadwal KRS</h2></div>
                            <div class="${app.ui.cardBody}">
                                <p class="text-sm text-ink-muted">Jadwal belum terbaca dari portal. Cek <a class="${app.ui.link}" href="/announcement">pengumuman</a> untuk periode her-registrasi dan KRS.</p>
                            </div>
                        </section>
                    `;

                    return;
                }

                hosts.schedule.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-schedule-title">
                        <div class="${app.ui.cardHeader}">
                            <h2 class="${app.ui.cardTitle}" id="krs-schedule-title">Jadwal KRS</h2>
                        </div>
                        <ul class="${app.ui.list}">
                            ${periods.map((period) => {
                                const chip = period.isOpen
                                    ? app.renderBadge('Berlangsung', period === active ? 'brand' : 'info')
                                    : (period.isUpcoming ? app.renderBadge('Akan datang', 'info') : app.renderBadge('Selesai', 'secondary'));
                                const announcementLink = period.source === 'announcement' && period.announcement_id
                                    ? `<div class="text-xs text-ink-subtle"><a class="${app.ui.link}" href="/announcement/${esc(period.announcement_id)}">Lihat pengumuman</a></div>`
                                    : '';

                                const start = app.toDate(period.start);

                                return `
                                    <li class="${app.ui.listRowFlush} items-start">
                                        <span class="${app.ui.dateTile}" aria-hidden="true">
                                            <span class="${app.ui.dateTileMonth}">${start ? esc(start.toLocaleDateString('id-ID', { month: 'short' })) : '–'}</span>
                                            <span class="${app.ui.dateTileDay}">${start ? esc(start.getDate()) : '–'}</span>
                                        </span>
                                        <div class="${app.ui.listRowBody}">
                                            <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                                                <div class="text-sm font-medium leading-snug text-ink">${esc(period.label)}</div>
                                                ${chip}
                                            </div>
                                            <div class="mt-0.5 text-xs tabular-nums text-ink-muted">${esc(app.formatIndonesianDate(period.start))} s.d. ${esc(app.formatIndonesianDate(period.end))}</div>
                                            ${announcementLink}
                                        </div>
                                    </li>
                                `;
                            }).join('')}
                        </ul>
                    </section>
                `;
            };

            const renderNotes = () => {
                const krs = state.krs;

                if (!krs) {
                    return;
                }

                const notes = [krs.notice_text, krs.notice?.text].filter((text) => typeof text === 'string' && text.trim() !== '');

                hosts.notes.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-notes-title">
                        <div class="${app.ui.cardHeader}">
                            <h2 class="${app.ui.cardTitle}" id="krs-notes-title">Tautan &amp; catatan</h2>
                        </div>
                        <div class="${app.ui.cardBody} flex flex-col gap-4 text-sm text-ink-muted">
                            ${notes.length ? `
                                <details class="group rounded-lg bg-surface">
                                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-9">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0 text-ink-subtle transition-transform group-open:rotate-90" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
                                        Catatan dari portal
                                    </summary>
                                    <div class="flex flex-col gap-2 px-3 pb-3 text-xs leading-relaxed text-ink-muted">
                                        ${notes.map((text) => `<p>${esc(text)}</p>`).join('')}
                                    </div>
                                </details>
                            ` : ''}
                            <div class="flex flex-wrap gap-2">
                                <a href="${esc(guideUrl)}" class="${app.buttonClass('secondary', { small: true })}">Panduan KRS</a>
                                <a href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('secondary', { small: true })}" data-online-required>${icons.external}Portal kampus</a>
                            </div>
                        </div>
                    </section>
                `;
            };

