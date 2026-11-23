{{--
    KRS script, the Riwayat & DPA tab: the DPA card and the KRS history with
    each semester's IPS. Part of the DOMContentLoaded handler opened in
    academic/krs.blade.php: it shares that scope (app, esc, hosts, state and
    the helpers in setup).
--}}
            const renderDpaCard = () => {
                const dpa = currentDpa();
                const code = state.krs?.insights?.status?.code || state.krs?.status?.code || 'unknown';
                const contact = dpaContact(code);

                if (!state.advising && !state.krs) {
                    return;
                }

                if (!dpa || !contact) {
                    hosts.dpa.innerHTML = `
                        <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-dpa-title">
                            <div class="${app.ui.cardHeader}"><h2 class="${app.ui.cardTitle}" id="krs-dpa-title">Dosen Pembimbing Akademik</h2></div>
                            <div class="${app.ui.cardBody}">
                                ${app.renderEmptyState('Data DPA belum terbaca dari portal.', 'secondary', 'Cek menu Perwalian di portal kampus.', { icon: 'user' })}
                            </div>
                        </section>
                    `;

                    return;
                }

                const initials = String(dpa.name || 'DPA').replace(/[^A-Za-z\s]/g, ' ').split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0].toUpperCase()).join('') || 'D';

                hosts.dpa.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-dpa-title">
                        <div class="${app.ui.cardHeader}">
                            <h2 class="${app.ui.cardTitle}" id="krs-dpa-title">Dosen Pembimbing Akademik</h2>
                        </div>
                        <div class="${app.ui.cardBody} flex flex-col gap-4">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface text-sm font-medium text-ink-muted ring-1 ring-border" aria-hidden="true">${esc(initials)}</span>
                                <div class="min-w-0">
                                    <div class="text-sm font-medium leading-snug text-ink">${esc(dpa.name || 'Dosen Pembimbing Akademik')}</div>
                                    ${dpa.nidn ? `<div class="font-mono text-xs text-ink-subtle">NIDN ${esc(dpa.nidn)}</div>` : ''}
                                </div>
                            </div>
                            ${app.renderContactActions(contact)}
                        </div>
                    </section>
                `;
            };

            const historyIps = () => {
                const map = new Map();
                const grades = cachedData('/api/academic/grades');
                const transcript = cachedData('/api/academic/transcript');

                (grades?.semesters || []).forEach((semester) => {
                    if (Number.isInteger(semester.semester) && typeof semester.ips === 'number') {
                        map.set(semester.semester, semester.ips);
                    }
                });

                (transcript?.ips_series || []).forEach((point) => {
                    if (Number.isInteger(point.semester) && typeof point.ips === 'number' && !map.has(point.semester)) {
                        map.set(point.semester, point.ips);
                    }
                });

                return map;
            };

            const renderHistory = () => {
                const advising = state.advising;

                if (!advising) {
                    return;
                }

                if (advising.found === false) {
                    hosts.history.innerHTML = `
                        <div class="${app.ui.card}" data-slot="card"><div class="${app.ui.cardBody}">
                            ${app.renderEmptyState('Riwayat KRS belum terbaca dari portal.', 'secondary', 'Cek menu Perwalian di portal kampus.')}
                        </div></div>
                    `;

                    return;
                }

                const rows = Array.isArray(advising.krs_history) ? advising.krs_history : [];
                const info = advising.student || {};
                const facts = [info.status ? `Status ${info.status}` : '', info.cohort ? `Angkatan ${info.cohort}` : '', info.program || ''].filter(Boolean);

                if (!rows.length) {
                    hosts.history.innerHTML = `
                        <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-history-title">
                            <div class="${app.ui.cardHeader}"><h2 class="${app.ui.cardTitle}" id="krs-history-title">Riwayat KRS</h2></div>
                            <div class="${app.ui.cardBody}">${app.renderEmptyState('Belum ada riwayat KRS.', 'secondary', '', { icon: 'history' })}</div>
                        </section>
                    `;

                    return;
                }

                const ips = historyIps();

                hosts.history.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-history-title">
                        <div class="${app.ui.cardHeader}">
                            <div class="min-w-0">
                                <h2 class="${app.ui.cardTitle}" id="krs-history-title">Riwayat KRS</h2>
                                ${facts.length ? `<p class="${app.ui.cardSubtitle}">${esc(facts.join(' · '))}</p>` : ''}
                            </div>
                        </div>
                        <ol class="${app.ui.list}" aria-label="Riwayat KRS per semester">
                            ${rows.map((row) => {
                                const regular = row.semester_is_regular !== false && Number.isInteger(row.semester);
                                const detail = cachedData(`/api/academic/krs/${row.krs_id}`);
                                const facts = [row.date ? `Diajukan ${esc(app.formatIndonesianDate(row.date))}` : 'Tanggal tidak tercatat'];

                                if (!regular) {
                                    facts.push(row.semester === 'T' ? 'Konversi' : 'Non-reguler');
                                }

                                if (row.semester === 'T' && detail?.found && detail.totals) {
                                    facts.push(`${esc(detail.totals.courses)} MK · ${esc(detail.totals.sks)} SKS diakui`);
                                }

                                if (regular && detail?.found && detail.counts_as_study_load !== false && detail.totals) {
                                    facts.push(`${esc(detail.totals.courses)} MK · ${esc(detail.totals.sks)} SKS`);
                                }

                                if (regular && ips.has(row.semester)) {
                                    facts.push(`IPS ${esc(Number(ips.get(row.semester)).toFixed(2))}`);
                                }

                                const title = regular ? `Semester ${row.semester}` : 'Semester T';

                                return `
                                    <li>
                                        <a href="/krs/${esc(row.krs_id)}" class="${app.ui.listRowFlushInteractive}">
                                            <span class="${app.ui.listRowBody}">
                                                <span class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-medium leading-snug text-ink">${esc(title)}${row.academic_year ? ` · T.A ${esc(row.academic_year)}` : ''}</span>
                                                    ${row.krs_id === advising.latest_krs_id ? app.renderBadge('Terbaru', 'brand') : ''}
                                                </span>
                                                <span class="mt-0.5 block text-xs tabular-nums text-ink-subtle">${facts.join(' · ')}</span>
                                            </span>
                                            ${app.renderIcon('chevron-right', 'sm', app.ui.listRowChevron)}
                                        </a>
                                    </li>
                                `;
                            }).join('')}
                        </ol>
                    </section>
                `;
            };

