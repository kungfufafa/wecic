{{--
    KRS script, this semester's courses with their composition, and the
    header a printed KRS carries. Part of the DOMContentLoaded handler
    opened in academic/krs.blade.php: it shares that scope (app, esc, hosts,
    state and the helpers in setup).
--}}
            const detailByCode = () => {
                const map = new Map();
                const latestId = latestKrsId();
                const detail = state.detail && (!latestId || state.detail.krs_id === latestId) ? state.detail : null;

                (detail?.courses || []).forEach((course) => {
                    if (course?.code) {
                        map.set(String(course.code).toUpperCase(), course);
                    }
                });

                return map;
            };

            const renderComposition = (courses) => {
                const bySemester = new Map();

                courses.forEach((course) => {
                    const key = Number.isInteger(course.semester) ? course.semester : null;

                    bySemester.set(key, (bySemester.get(key) || 0) + (Number(course.sks) || 0));
                });

                if (bySemester.size < 2) {
                    return '';
                }

                const tones = ['brand', 'info', 'success', 'accent', 'warning'];
                const segments = Array.from(bySemester.entries())
                    .sort(([left], [right]) => (right ?? -1) - (left ?? -1))
                    .map(([semester, sks], index) => ({
                        label: semester === null ? 'Smt ?' : `MK smt ${semester}`,
                        value: sks,
                        tone: tones[index % tones.length],
                    }));

                return `
                    <div class="border-t border-border-light px-4 py-3 md:px-5">
                        <div class="mb-2 text-caption font-medium text-ink-subtle">SKS per semester kurikulum</div>
                        ${app.renderStackedBar(segments, { label: 'Komposisi SKS per semester kurikulum', valueFormat: (value) => `${value} SKS`, size: 'sm' })}
                    </div>
                `;
            };

            const renderCourses = () => {
                const krs = state.krs;

                if (!krs) {
                    return;
                }

                const courses = Array.isArray(krs.courses) ? krs.courses : [];
                const totals = krs.totals || {};

                if (krs.found === false || !courses.length) {
                    hosts.courses.innerHTML = `
                        <section class="mycic-card" aria-labelledby="krs-courses-title">
                            <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-courses-title">Mata kuliah semester ini</h2></div>
                            <div class="mycic-card-body">
                                ${app.renderEmptyState(
                                    krs.found === false ? 'Daftar mata kuliah belum terbaca.' : 'Belum ada mata kuliah di KRS semester ini.',
                                    'secondary',
                                    'Isi KRS lewat portal kampus selama periode dibuka. Panduannya ada di menu Lainnya › Panduan.'
                                )}
                                <div class="mt-3 flex justify-center"><a href="${esc(guideUrl)}" class="${app.buttonClass('secondary', { small: true })}">Buka panduan</a></div>
                            </div>
                        </section>
                    `;
                    hosts.printHeader.innerHTML = '';

                    return;
                }

                const details = detailByCode();
                const currentSemester = currentSemesterNumber();
                const retakes = courses.filter((course) => details.get(String(course.code).toUpperCase())?.is_retake).length;
                const courseCount = Number.isInteger(totals.courses) ? totals.courses : courses.length;
                const sksTotal = Number.isInteger(totals.sks) ? totals.sks : courses.reduce((sum, course) => sum + (Number(course.sks) || 0), 0);
                const totalsNote = totals.consistent === false
                    ? 'Total di portal berbeda dengan jumlah daftar MK. Cek lagi di portal kampus.'
                    : (totals.source === 'computed' ? 'Total dihitung WeCIC dari daftar MK.' : '');
                const printable = krs.print_available === true;

                hosts.courses.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-courses-title">
                        <div class="mycic-card-header flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <h2 class="mycic-card-title" id="krs-courses-title">Mata kuliah semester ini</h2>
                                ${retakes ? `<p class="mycic-card-subtitle">${esc(retakes)} MK diulang</p>` : ''}
                            </div>
                            <span class="mycic-badge-brand tabular-nums">${esc(courseCount)} MK · ${esc(sksTotal)} SKS</span>
                        </div>
                        <ul class="flex flex-col px-4 md:px-5" aria-label="Daftar mata kuliah KRS">
                            ${courses.map((course) => {
                                const detail = details.get(String(course.code || '').toUpperCase());
                                const chips = [];

                                if (detail?.is_retake) {
                                    chips.push('<span class="mycic-badge-warning">Ulang</span>');
                                } else if (detail?.status) {
                                    chips.push(`<span class="mycic-badge-neutral">${esc(detail.status)}</span>`);
                                }

                                if (Number.isInteger(course.semester) && course.semester !== currentSemester) {
                                    chips.push(`<span class="mycic-badge-neutral">MK smt ${esc(course.semester)}</span>`);
                                }

                                return `
                                    <li class="mycic-list-row items-center">
                                        <div class="min-w-0 flex-1">
                                            <div class="font-mono text-caption text-ink-subtle">${esc(course.code || '–')}</div>
                                            <div class="text-sm font-medium leading-snug text-ink">${esc(course.name || 'Mata kuliah')}</div>
                                            ${chips.length ? `<div class="mt-1 flex flex-wrap gap-1.5">${chips.join('')}</div>` : ''}
                                        </div>
                                        <span class="shrink-0 text-sm font-medium tabular-nums text-ink">${course.sks === null || course.sks === undefined ? '–' : esc(course.sks)} <span class="text-caption font-normal text-ink-subtle">SKS</span></span>
                                    </li>
                                `;
                            }).join('')}
                        </ul>
                        ${renderComposition(courses)}
                        <div class="mycic-sticky-action md:border-t md:border-border-light md:px-5 md:py-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0 ps-2 md:ps-0">
                                    <div class="font-mono text-sm font-medium tabular-nums text-ink">${esc(courseCount)} MK · ${esc(sksTotal)} SKS</div>
                                    ${totalsNote ? `<div class="text-caption text-ink-subtle">${esc(totalsNote)}</div>` : '<div class="text-caption text-ink-subtle">Total KRS semester ini</div>'}
                                </div>
                                ${printable ? `<button type="button" class="${app.buttonClass('secondary', { small: true })} shrink-0 print:hidden" data-krs-print>${icons.print}Cetak KRS</button>` : ''}
                            </div>
                        </div>
                    </section>
                `;

                hosts.courses.querySelector('[data-krs-print]')?.addEventListener('click', () => window.print());
                renderPrintHeader();
            };

            const renderPrintHeader = () => {
                const krs = state.krs;

                if (!krs?.found) {
                    hosts.printHeader.innerHTML = '';

                    return;
                }

                const me = student();
                const code = krs.insights?.status?.code || krs.status?.code || 'unknown';
                const watermark = { validated: 'TERVALIDASI DPA', pending_dpa: 'BELUM DIVALIDASI DPA', not_submitted: 'BELUM DIAJUKAN' }[code] || 'STATUS BELUM TERBACA';
                const stamp = app.formatIndonesianDate(state.krsMeta?.synced_at || krs.extracted_at, { time: true });

                hosts.printHeader.innerHTML = `
                    <div class="border-b border-border pb-3">
                        <div class="text-lg font-semibold text-ink">Kartu Rencana Studi (KRS) · ${esc(termLabel(krs.academic_period))}</div>
                        <div class="mt-1 text-sm text-ink-muted">${esc([me.name, me.nim, me.program].filter(Boolean).join(' · '))}</div>
                        <div class="mt-2 inline-block rounded border border-ink px-2 py-0.5 font-mono text-xs font-medium tracking-wide text-ink">${esc(watermark)}</div>
                        <p class="mt-2 text-caption text-ink-subtle">Dibuat WeCIC dari data portal per ${esc(stamp)} · bukan dokumen resmi. Versi resmi ada di menu Reg & KRS Online portal kampus.</p>
                    </div>
                `;
            };

