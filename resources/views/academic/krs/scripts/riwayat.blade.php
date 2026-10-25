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
                        <section class="mycic-card" aria-labelledby="krs-dpa-title">
                            <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-dpa-title">Dosen Pembimbing Akademik</h2></div>
                            <div class="mycic-card-body">
                                ${app.renderEmptyState('Data DPA belum terbaca dari portal.', 'secondary', 'Tanyakan nama DPA kamu lewat BAAK atau buka menu Perwalian di portal kampus.')}
                            </div>
                        </section>
                    `;

                    return;
                }

                const initials = String(dpa.name || 'DPA').replace(/[^A-Za-z\s]/g, ' ').split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0].toUpperCase()).join('') || 'D';
                const source = state.advising?.dpa && hasContact(state.advising.dpa) ? 'menu Perwalian portal' : 'halaman KRS portal';

                hosts.dpa.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-dpa-title">
                        <div class="mycic-card-header">
                            <h2 class="mycic-card-title" id="krs-dpa-title">Dosen Pembimbing Akademik</h2>
                            <p class="mt-0.5 text-caption text-ink-subtle">Sumber: ${esc(source)}</p>
                        </div>
                        <div class="mycic-card-body flex flex-col gap-4">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="mycic-avatar shrink-0" aria-hidden="true">${esc(initials)}</span>
                                <div class="min-w-0">
                                    <div class="font-semibold text-ink">${esc(dpa.name || 'Dosen Pembimbing Akademik')}</div>
                                    ${dpa.nidn ? `<div class="text-caption text-ink-subtle">NIDN ${esc(dpa.nidn)}</div>` : ''}
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
                        <div class="mycic-card"><div class="mycic-card-body">
                            ${app.renderEmptyState('Riwayat KRS belum terbaca dari portal.', 'secondary', 'Coba sinkronkan lagi nanti, atau buka menu Perwalian di portal kampus.')}
                        </div></div>
                    `;

                    return;
                }

                const rows = Array.isArray(advising.krs_history) ? advising.krs_history : [];
                const info = advising.student || {};
                const facts = [info.status ? `Status ${info.status}` : '', info.cohort ? `Angkatan ${info.cohort}` : '', info.program || ''].filter(Boolean);

                if (!rows.length) {
                    hosts.history.innerHTML = `
                        <section class="mycic-card" aria-labelledby="krs-history-title">
                            <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-history-title">Riwayat KRS</h2></div>
                            <div class="mycic-card-body">${app.renderEmptyState('Belum ada riwayat KRS.', 'secondary', 'Riwayat muncul setelah KRS pertama kamu diajukan.')}</div>
                        </section>
                    `;

                    return;
                }

                const ips = historyIps();

                hosts.history.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-history-title">
                        <div class="mycic-card-header">
                            <h2 class="mycic-card-title" id="krs-history-title">Riwayat KRS</h2>
                            ${facts.length ? `<p class="mt-0.5 text-caption text-ink-subtle">${esc(facts.join(' · '))}</p>` : ''}
                        </div>
                        <ol class="flex flex-col px-2 py-1 md:px-3" aria-label="Riwayat KRS per semester">
                            ${rows.map((row) => {
                                const regular = row.semester_is_regular !== false && Number.isInteger(row.semester);
                                const detail = cachedData(`/api/academic/krs/${row.krs_id}`);
                                const chips = [];

                                if (row.krs_id === advising.latest_krs_id) {
                                    chips.push(app.renderBadge('Terbaru', 'brand'));
                                }

                                if (!regular) {
                                    chips.push(app.renderBadge(row.semester === 'T' ? 'Konversi' : 'Non-reguler', 'secondary'));
                                }

                                if (row.semester === 'T' && detail?.found && detail.totals) {
                                    chips.push(`<span class="mycic-chip tabular-nums">${esc(detail.totals.courses)} MK · ${esc(detail.totals.sks)} SKS diakui</span>`);
                                }

                                if (regular && detail?.found && detail.counts_as_study_load !== false && detail.totals) {
                                    chips.push(`<span class="mycic-chip tabular-nums">${esc(detail.totals.courses)} MK · ${esc(detail.totals.sks)} SKS</span>`);
                                }

                                if (regular && ips.has(row.semester)) {
                                    chips.push(`<span class="mycic-chip tabular-nums">IPS ${esc(Number(ips.get(row.semester)).toFixed(2))}</span>`);
                                }

                                const title = regular ? `Semester ${row.semester}` : 'Semester T';

                                return `
                                    <li class="border-b border-border-light last:border-b-0">
                                        <a href="/krs/${esc(row.krs_id)}" class="flex min-h-11 items-center gap-3 rounded-md px-2 py-3 transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${regular ? 'bg-surface-tint text-brand-pressed' : 'bg-surface text-ink-subtle'} text-sm font-bold" aria-hidden="true">${esc(regular ? row.semester : 'T')}</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-semibold text-ink">${esc(title)}${row.academic_year ? ` · T.A ${esc(row.academic_year)}` : ''}</span>
                                                <span class="block text-caption text-ink-subtle">${row.date ? `Diajukan ${esc(app.formatIndonesianDate(row.date))}` : 'Tanggal tidak tercatat'}</span>
                                                ${chips.length ? `<span class="mt-1.5 flex flex-wrap gap-1.5">${chips.join('')}</span>` : ''}
                                            </span>
                                            ${icons.chevron}
                                        </a>
                                    </li>
                                `;
                            }).join('')}
                        </ol>
                        <p class="border-t border-border-light px-4 py-3 text-caption text-ink-subtle md:px-5">Nilai tiap semester ada di halaman <a class="mycic-link" href="${esc(gradesUrl)}">Nilai</a>. Semester T tidak dihitung sebagai beban studi.</p>
                    </section>
                `;
            };

