{{--
    KRS script, the status card: badge, tracker, countdown, and the copy for
    each status. Part of the DOMContentLoaded handler opened in
    academic/krs.blade.php: it shares that scope (app, esc, hosts, state and
    the helpers in setup).
--}}
            const sourceLabel = (period) => (period.source === 'announcement' ? 'pengumuman her-registrasi' : 'halaman KRS portal');

            const statusBadge = (code, periodOpen) => {
                const badges = {
                    pending_dpa: ['warning', 'Menunggu DPA'],
                    validated: ['success', 'Tervalidasi'],
                    not_submitted: [periodOpen ? 'danger' : 'secondary', 'Belum diajukan'],
                    unknown: ['secondary', 'Belum terbaca'],
                };
                const [tone, label] = badges[code] || badges.unknown;

                return app.renderBadge(label, tone);
            };

            const renderTracker = (steps) => {
                if (!Array.isArray(steps) || !steps.length) {
                    return '';
                }

                const stateLabels = { done: 'Selesai', current: 'Sedang berjalan', todo: 'Belum', unknown: 'Belum diketahui' };
                const circles = {
                    done: 'border-success/30 bg-success-soft text-success',
                    current: 'border-brand bg-surface-tint text-brand-text',
                    todo: 'border-border bg-panel text-ink-subtle',
                    unknown: 'border-dashed border-border bg-panel text-ink-subtle',
                };

                return `
                    <ol class="flex flex-col md:flex-row md:gap-2" aria-label="Tahapan KRS">
                        ${steps.map((step, index) => {
                            const stepState = circles[step.state] ? step.state : 'unknown';
                            const mark = stepState === 'done' ? icons.check : (stepState === 'unknown' ? '?' : String(index + 1));

                            return `
                                <li class="group relative flex min-w-0 gap-3 pb-5 last:pb-0 md:flex-1 md:flex-col md:items-center md:gap-2 md:pb-0 md:text-center"${stepState === 'current' ? ' aria-current="step"' : ''}>
                                    <span class="absolute start-3.5 top-8 bottom-1 w-0.5 -translate-x-1/2 rounded-full ${stepState === 'done' ? 'bg-success/40' : 'bg-border'} group-last:hidden md:start-[calc(50%+1.25rem)] md:top-3.5 md:bottom-auto md:h-0.5 md:w-[calc(100%-1.75rem)] md:translate-x-0" aria-hidden="true"></span>
                                    <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums ${circles[stepState]}" aria-hidden="true">${mark}</span>
                                    <span class="min-w-0 pt-0.5 md:pt-0">
                                        <span class="block text-sm font-medium leading-snug text-ink">${esc(step.label)}</span>
                                        <span class="block text-xs text-ink-subtle">${esc(stateLabels[stepState])}</span>
                                    </span>
                                </li>
                            `;
                        }).join('')}
                    </ol>
                `;
            };

            const renderCountdown = (statusCode) => {
                const { active, upcoming, past } = pickPeriods(statusCode);

                if (active) {
                    const days = active.daysLeft ?? 0;
                    const calm = statusCode === 'validated';
                    const tone = calm ? 'neutral' : (days <= 3 ? 'danger' : (days <= 7 ? 'warning' : 'brand'));
                    const toneText = { danger: app.ui.text.danger, warning: app.ui.text.warning, brand: app.ui.text.brand, neutral: 'text-ink' }[tone];
                    const panel = { danger: app.ui.alert.danger, warning: app.ui.alert.warning, brand: app.ui.alert.brand, neutral: app.ui.inset }[tone];

                    return `
                        <div class="${panel} flex items-center gap-4" data-krs-countdown>
                            <div class="min-w-14 shrink-0 text-center">
                                <div class="text-3xl font-semibold leading-none tracking-tight tabular-nums ${toneText}">${days === 0 ? 'H-0' : esc(days)}</div>
                                <div class="mt-1 text-[11px] font-medium ${toneText}">${days === 0 ? 'tutup hari ini' : 'hari lagi'}</div>
                            </div>
                            <div class="min-w-0 text-ink">
                                <div class="text-sm font-medium leading-snug">${days === 0 ? 'Periode ditutup hari ini' : `${calm ? 'Masih bisa diperbaiki' : `Sisa ${esc(days)} hari`} · tutup ${esc(app.formatIndonesianDate(active.end, { weekday: true }))}`}</div>
                                <div class="mt-0.5 text-xs text-ink-muted">${esc(active.label)} · ${esc(shortRange(active.start, active.end))} · sumber: ${esc(sourceLabel(active))}</div>
                            </div>
                        </div>
                    `;
                }

                if (upcoming) {
                    return `
                        <div class="${app.ui.alert.info}" data-krs-countdown>
                            <div class="text-sm font-medium leading-snug">${esc(upcoming.label)} dibuka ${esc(app.formatRelativeDays(upcoming.start))}</div>
                            <div class="mt-0.5 text-xs text-ink-muted">${esc(app.formatIndonesianDate(upcoming.start, { weekday: true }))} s.d. ${esc(app.formatIndonesianDate(upcoming.end))} · sumber: ${esc(sourceLabel(upcoming))}</div>
                        </div>
                    `;
                }

                if (past) {
                    return `
                        <div class="${app.ui.inset} text-ink-muted" data-krs-countdown>
                            <div class="text-sm font-medium leading-snug text-ink">Periode KRS sudah ditutup ${esc(app.formatIndonesianDate(past.end))}</div>
                            <div class="mt-0.5 text-xs">${esc(past.label)} · ${esc(shortRange(past.start, past.end))}</div>
                        </div>
                    `;
                }

                return `
                    <div class="${app.ui.inset} text-ink-muted" data-krs-countdown>
                        <div class="text-sm font-medium leading-snug text-ink">Periode KRS belum terbaca dari portal</div>
                        <div class="mt-0.5 text-xs">Cek pengumuman kampus untuk jadwal her-registrasi dan KRS.</div>
                    </div>
                `;
            };

            const statusCopy = (code, rawText) => {
                const { active, upcoming, past } = pickPeriods(code);
                const closeDate = active ? app.formatIndonesianDate(active.end) : null;

                switch (code) {
                    case 'pending_dpa':
                        return {
                            title: 'Menunggu validasi DPA',
                            body: active
                                ? `KRS kamu sudah diajukan. Minta DPA memvalidasi sebelum ${closeDate}, karena nilai semester ini baru tampil setelah KRS divalidasi.`
                                : 'KRS kamu sudah diajukan tapi belum divalidasi DPA. Segera hubungi DPA atau BAAK, karena nilai semester ini baru tampil setelah KRS divalidasi.',
                        };
                    case 'validated':
                        return {
                            title: 'KRS sudah divalidasi DPA',
                            body: 'Mata kuliah di bawah sudah jadi rencana studi resmi semester ini. Jadwal dan kelasnya bisa kamu cek di menu Kuliah.',
                        };
                    case 'not_submitted':
                        if (active) {
                            return { title: 'KRS belum diajukan', body: `Isi KRS di portal kampus sebelum ${closeDate}, lalu minta DPA memvalidasinya.` };
                        }

                        if (upcoming) {
                            return { title: 'KRS belum diajukan', body: `Periode pengisian dibuka ${app.formatIndonesianDate(upcoming.start)}. Siapkan rencana mata kuliahmu dari sekarang.` };
                        }

                        return {
                            title: 'KRS belum diajukan',
                            body: past
                                ? `Periode KRS sudah ditutup ${app.formatIndonesianDate(past.end)}. Hubungi DPA atau BAAK kalau kamu belum mengisi KRS.`
                                : 'Isi KRS di portal kampus selama periode dibuka, lalu minta DPA memvalidasinya.',
                        };
                    default:
                        return {
                            title: 'Status KRS belum terbaca',
                            body: rawText
                                ? `Portal menulis: “${rawText}”. Buka portal kampus kalau kamu ragu.`
                                : 'WeCIC belum bisa membaca status KRS dari portal. Buka portal kampus untuk memastikan.',
                        };
                }
            };

            const renderStatus = () => {
                const krs = state.krs;

                if (!krs) {
                    return;
                }

                state.renderedDay = todayKey();

                const insights = krs.insights || {};
                const status = insights.status || krs.status || { code: 'unknown' };
                const code = krs.found === false ? 'unknown' : (status.code || 'unknown');
                const { active } = pickPeriods(code);
                const copy = krs.found === false
                    ? { title: 'KRS semester ini belum terbaca', body: 'Portal belum menampilkan halaman KRS. Coba sinkronkan lagi nanti, atau buka portal kampus.' }
                    : statusCopy(code, status.raw_text);
                const contact = dpaContact(code);
                const latestId = latestKrsId();
                const urgent = code === 'pending_dpa' || (code === 'not_submitted' && active);
                const actions = [];

                if (code === 'not_submitted' && active) {
                    actions.push(`<a href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('primary')}" data-online-required>${icons.external}Isi KRS di portal</a>`);
                }

                if (contact) {
                    actions.push(`<button type="button" class="${app.buttonClass(urgent && code === 'pending_dpa' ? 'primary' : 'secondary')}" data-krs-contact-dpa>${icons.chat}Hubungi DPA</button>`);
                }

                if (code === 'validated') {
                    actions.push(`<a href="${esc(classUrl)}" class="${app.buttonClass('secondary')}">Lihat kelas</a>`);
                }

                if (latestId) {
                    actions.push(`<a href="/krs/${esc(latestId)}" class="${app.buttonClass('ghost')}">Detail KRS</a>`);
                }

                hosts.status.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-status-title">
                        <div class="${app.ui.cardBody} flex flex-col gap-5 sm:p-6">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-xs font-medium text-ink-subtle">${esc(['KRS', termLabel(krs.academic_period)].filter(Boolean).join(' '))}</div>
                                    ${statusBadge(code, Boolean(active))}
                                </div>
                                <h2 class="mt-1.5 text-lg font-semibold tracking-tight text-ink" id="krs-status-title">${esc(copy.title)}</h2>
                                <p class="mt-1 text-sm text-ink-muted">${esc(copy.body)}</p>
                            </div>
                            ${renderCountdown(code)}
                            ${krs.found === false ? '' : renderTracker(insights.tracker)}
                            ${!contact && krs.found !== false ? `<p class="text-xs text-ink-subtle">Kontak DPA belum terbaca dari portal. Tanyakan lewat BAAK atau buka <a class="${app.ui.link}" href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer">portal kampus</a>.</p>` : ''}
                            ${actions.length ? `<div class="flex flex-wrap gap-2">${actions.join('')}</div>` : ''}
                        </div>
                    </section>
                `;

                hosts.status.querySelector('[data-krs-contact-dpa]')?.addEventListener('click', () => {
                    app.renderContactSheet(dpaContact(code) || {});
                });
                app.refreshOnlineRequiredState?.();
            };

