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
                        <section class="mycic-card" aria-labelledby="krs-schedule-title">
                            <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-schedule-title">Jadwal KRS</h2></div>
                            <div class="mycic-card-body">
                                <p class="text-sm text-ink-muted">Jadwal belum terbaca dari portal. Cek <a class="mycic-link" href="/announcement">pengumuman</a> untuk periode her-registrasi dan KRS.</p>
                            </div>
                        </section>
                    `;

                    return;
                }

                hosts.schedule.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-schedule-title">
                        <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-schedule-title">Jadwal KRS</h2></div>
                        <ul class="mycic-card-body flex flex-col py-1">
                            ${periods.map((period) => {
                                const chip = period.isOpen
                                    ? app.renderBadge('Berlangsung', period === active ? 'brand' : 'info')
                                    : (period.isUpcoming ? app.renderBadge('Akan datang', 'info') : app.renderBadge('Selesai', 'secondary'));
                                const source = period.source === 'announcement' && period.announcement_id
                                    ? `<a class="mycic-link" href="/announcement/${esc(period.announcement_id)}">pengumuman</a>`
                                    : esc(sourceLabel(period));

                                return `
                                    <li class="mycic-list-row">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-semibold text-ink">${esc(period.label)}</div>
                                            <div class="text-caption text-ink-subtle">${esc(app.formatIndonesianDate(period.start))} s.d. ${esc(app.formatIndonesianDate(period.end))}</div>
                                            <div class="text-caption text-ink-subtle">Sumber: ${source}</div>
                                        </div>
                                        <div class="shrink-0">${chip}</div>
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
                    <section class="mycic-card" aria-labelledby="krs-notes-title">
                        <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-notes-title">Perlu diingat</h2></div>
                        <div class="mycic-card-body flex flex-col gap-3 text-sm text-ink-muted">
                            <p>Pengisian dan perbaikan KRS dilakukan di portal kampus. MyCIC hanya menampilkan statusnya.</p>
                            ${notes.length ? `
                                <details class="group rounded-[var(--radius-control)] border border-border-light bg-surface/40 p-3">
                                    <summary class="cursor-pointer list-none text-sm font-semibold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Catatan dari portal <span class="text-caption font-normal text-ink-subtle">(ketuk untuk membaca)</span></summary>
                                    <div class="mt-2 flex flex-col gap-2 text-caption leading-relaxed text-ink-muted">
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

