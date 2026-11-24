@extends('layouts.app')

@section('title', 'Nilai (KHS)')
@section('page_id', 'grades-index')

@php
    $gradesMetricLabels = ['IPK', 'IPS', 'SKS lulus'];
@endphp

{{--
    Nilai (KHS): the hero with the academic tabs, the IPK/IPS/SKS strip, then
    the semester chips and the selected semester's courses beside a sticky
    aside with the IPS trend and the way to the transcript.
--}}
@section('content')
    <x-page-hero title="Akademik">
        <x-slot:tabs class="lg:hidden"><x-academic-menu active="grades" underline /></x-slot:tabs>
    </x-page-hero>

    <div class="flex flex-col gap-6">
        <x-stat-strip class="grid-cols-2 sm:grid-cols-3" id="grades-summary">
            @foreach ($gradesMetricLabels as $label)
                <x-metric :label="$label" :class="$loop->first ? 'col-span-2 sm:col-span-1' : ''">
                    <x-slot:value><x-skeleton class="h-8 w-16" /></x-slot:value>
                    <x-slot:note><x-skeleton as="span" class="block h-3 w-20" /></x-slot:note>
                </x-metric>
            @endforeach
        </x-stat-strip>

        <x-split>
            <div id="grades-alerts" class="flex flex-col gap-3 empty:hidden"></div>

            <x-section title="Per semester" title-id="grades-semester-heading" class="flex min-w-0 flex-col">
                <div class="flex flex-col gap-4">
                    <div class="-mx-4 overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden" id="grades-semester-scroller">
                        <div class="flex w-max gap-1 rounded-lg bg-surface p-1" role="tablist" aria-label="Pilih semester" id="grades-semesters">
                            @for ($i = 0; $i < 3; $i++)
                                <x-skeleton class="h-11 w-28 shrink-0 bg-panel!" />
                            @endfor
                        </div>
                    </div>

                    <div id="grades-panel" role="tabpanel" aria-labelledby="grades-semester-heading" tabindex="-1" class="flex flex-col gap-4 focus:outline-none">
                        <x-card aria-busy="true">
                            <x-card.body class="flex flex-col gap-3">
                                <span class="sr-only">Memuat nilai…</span>
                                <x-skeleton class="h-5 w-1/3" />
                                <x-skeleton class="h-3.5 w-1/2" />
                            </x-card.body>
                        </x-card>
                        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            @for ($i = 0; $i < 4; $i++)
                                <x-card class="p-4">
                                    <div class="flex items-start gap-3">
                                        <div class="flex flex-1 flex-col gap-1.5">
                                            <x-skeleton class="h-4 w-3/4" />
                                            <x-skeleton class="h-3 w-1/3" />
                                        </div>
                                    </div>
                                </x-card>
                            @endfor
                        </div>
                    </div>
                </div>
            </x-section>

            <x-slot:aside aria-label="Ringkasan IPS">
                <div id="grades-trend">
                    <x-card aria-busy="true">
                        <x-card.header><x-skeleton class="h-4 w-24" /></x-card.header>
                        <x-card.body><x-skeleton class="h-20 w-full" /></x-card.body>
                    </x-card>
                </div>

                <x-card as="a" href="{{ route('transcript.index') }}" interactive class="flex min-h-11 items-center gap-3 p-4 sm:p-5">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium leading-snug text-ink">Transkrip & progres studi</span>
                        <span class="block text-xs text-ink-subtle">IPK, sisa SKS & simulasi</span>
                    </span>
                    <x-icon name="chevron-right" size="sm" class="text-ink-subtle" />
                </x-card>
            </x-slot:aside>
        </x-split>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const endpoint = '/api/academic/grades';
            const krsUrl = @json(route('krs.index'));
            const hosts = {
                summary: document.getElementById('grades-summary'),
                alerts: document.getElementById('grades-alerts'),
                chips: document.getElementById('grades-semesters'),
                panel: document.getElementById('grades-panel'),
                trend: document.getElementById('grades-trend'),
            };
            const state = { data: null, selected: null, lowOnly: false };
            const esc = (value) => app.escapeHtml(value);
            const componentLabels = { absen: 'Absen', tugas: 'Tugas', uts: 'UTS', uas: 'UAS', quiz: 'Kuis', praktikum: 'Praktikum' };

            const letterTextClass = (letter) => (['D', 'E'].includes(String(letter || '').trim().toUpperCase().charAt(0)) ? 'text-danger' : 'text-ink');

            const formatIp = (value) => (typeof value === 'number' && Number.isFinite(value) ? value.toFixed(2) : '–');

            const semesterKey = (semester) => String(semester?.semester ?? semester?.label ?? '');

            const semesterName = (semester) => (semester?.is_regular !== false && Number.isInteger(semester?.semester) ? `Semester ${semester.semester}` : 'Semester T');

            const semesters = () => (Array.isArray(state.data?.semesters) ? state.data.semesters : []);

            const currentSemesterNumber = () => semesters()
                .filter((semester) => semester.is_regular !== false && Number.isInteger(semester.semester))
                .reduce((highest, semester) => Math.max(highest, semester.semester), 0) || null;

            const hasGrades = (semester) => ['graded', 'partial'].includes(semester?.status) && Number(semester?.graded_count) > 0;

            /**
             * One metric cell, the same markup as the x-metric component;
             * `value` and `note` are HTML. `wide` spans both phone columns
             * (the IPK leads the strip there).
             */
            const metricCard = ({ label, value, note = '', labelTitle = '', wide = false }) => `
                <div class="${app.ui.metric}${wide ? ' col-span-2 sm:col-span-1' : ''}" data-slot="metric">
                    <div class="${app.ui.metricLabel}" data-slot="metric-label"${labelTitle ? ` title="${esc(labelTitle)}"` : ''}>${esc(label)}</div>
                    <div class="${app.ui.metricValue}" data-slot="metric-value">${value}</div>
                    ${note ? `<div class="${app.ui.metricNote}" data-slot="metric-note">${note}</div>` : ''}
                </div>
            `;

            const renderSummary = () => {
                const summary = state.data?.summary || {};
                const latest = summary.computed?.latest_ips || null;
                const progress = summary.progress || {};
                const delta = typeof latest?.delta === 'number' ? latest.delta : null;
                const deltaText = delta === null || delta === 0
                    ? (delta === 0 ? 'sama dengan semester lalu' : '')
                    : `${delta > 0 ? '▲' : '▼'} ${Math.abs(delta).toFixed(2)}`;
                const deltaTone = delta === null || delta === 0 ? 'text-ink-subtle' : (delta > 0 ? 'text-success' : 'text-danger');
                const deltaLabel = delta === null || delta === 0 ? '' : `${delta > 0 ? 'naik' : 'turun'} ${Math.abs(delta).toFixed(2)} dari semester ${latest.previous_semester}`;

                hosts.summary.innerHTML = [
                    metricCard({
                        label: 'IPK',
                        wide: true,
                        value: esc(formatIp(summary.ipk)),
                        note: summary.ipk === null || summary.ipk === undefined ? 'Belum ada nilai' : (summary.source === 'official' ? 'Resmi portal' : 'Dihitung WeCIC'),
                    }),
                    metricCard({
                        label: 'IPS',
                        labelTitle: 'IPS semester terakhir yang sudah dinilai',
                        value: esc(formatIp(latest?.ips ?? null)),
                        note: `
                            ${latest ? `Semester ${esc(latest.semester)}${latest.is_complete === false ? ' · sebagian' : ''}` : 'Belum ada'}
                            ${deltaText ? `<span class="font-medium tabular-nums ${deltaTone}" aria-hidden="true">${esc(deltaText)}</span>` : ''}
                            ${deltaLabel ? `<span class="sr-only">${esc(deltaLabel)}</span>` : ''}
                        `,
                    }),
                    metricCard({
                        label: 'SKS lulus',
                        value: esc(summary.sks_passed ?? '–'),
                        note: `${progress.target_sks ? `dari ${esc(progress.target_sks)} SKS` : 'SKS terkumpul'}${summary.conversion_sks > 0 ? ` · ${esc(summary.conversion_sks)} konversi` : ''}`,
                    }),
                ].join('');
            };

            const renderAlerts = () => {
                const data = state.data || {};
                const alerts = [];

                if (data.sources?.is_partial) {
                    alerts.push(`
                        <div class="${app.alertTone('warning')}">
                            <div class="flex flex-wrap items-center gap-2 font-medium">${app.renderBadge('Data sebagian', 'warning')} Halaman nilai belum terbaca</div>
                            <p class="mt-1">Komponen nilai tidak tampil (memakai data KRS).</p>
                        </div>
                    `);
                }

                if (data.summary?.mismatch) {
                    alerts.push(`
                        <div class="${app.alertTone('info')}">
                            IPK resmi portal berbeda dengan hitungan WeCIC. <a class="${app.ui.link}" href="/transcript">Lihat Transkrip</a>.
                        </div>
                    `);
                }

                hosts.alerts.innerHTML = alerts.join('');
            };

            const chipCaption = (semester) => {
                if (semester.is_regular === false || !Number.isInteger(semester.semester)) {
                    return semester.semester === 'T' ? 'konversi' : 'non-reguler';
                }

                if (semester.status === 'graded') {
                    return `IPS ${formatIp(semester.ips)}`;
                }

                if (semester.status === 'partial') {
                    return `${semester.graded_count}/${semester.course_count} keluar`;
                }

                return 'belum keluar';
            };

            const renderChips = () => {
                const list = semesters();

                hosts.chips.innerHTML = list.map((semester) => {
                    const key = semesterKey(semester);
                    const selected = key === state.selected;

                    return `
                        <button
                            type="button"
                            role="tab"
                            id="grades-tab-${esc(key)}"
                            aria-controls="grades-panel"
                            aria-selected="${selected ? 'true' : 'false'}"
                            tabindex="${selected ? '0' : '-1'}"
                            data-grades-semester="${esc(key)}"
                            class="flex min-h-11 shrink-0 cursor-pointer flex-col items-start justify-center rounded-md px-3 py-1 text-start transition-colors hover:bg-panel/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-selected:bg-panel aria-selected:shadow-xs dark:aria-selected:bg-surface-tint"
                        >
                            <span class="text-sm font-medium text-ink">${esc(semesterName(semester))}</span>
                            <span class="text-xs tabular-nums ${hasGrades(semester) ? 'text-ink-muted' : 'text-ink-subtle'}">${esc(chipCaption(semester))}</span>
                        </button>
                    `;
                }).join('');

                const chips = Array.from(hosts.chips.querySelectorAll('[data-grades-semester]'));

                chips.forEach((chip, index) => {
                    chip.addEventListener('click', () => selectSemester(chip.dataset.gradesSemester));
                    chip.addEventListener('keydown', (event) => {
                        const moves = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: chips.length - 1 };

                        if (!(event.key in moves)) {
                            return;
                        }

                        event.preventDefault();
                        const target = chips[(moves[event.key] + chips.length) % chips.length];

                        selectSemester(target.dataset.gradesSemester, { focus: true });
                    });
                });

                // Keep the selected chip visible without moving the page itself.
                const selectedChip = hosts.chips.querySelector('[aria-selected="true"]');
                const scroller = document.getElementById('grades-semester-scroller');

                if (selectedChip && scroller && scroller.scrollWidth > scroller.clientWidth) {
                    const chipStart = selectedChip.offsetLeft - hosts.chips.offsetLeft;
                    const chipEnd = chipStart + selectedChip.offsetWidth;

                    if (chipStart < scroller.scrollLeft || chipEnd > scroller.scrollLeft + scroller.clientWidth) {
                        scroller.scrollLeft = Math.max(0, chipStart - 16);
                    }
                }
            };

            const emptyExplanation = (semester) => {
                if (semester.is_regular === false || !Number.isInteger(semester.semester)) {
                    return `
                        <div class="${app.alertTone('neutral')}">
                            <div class="font-medium text-ink">Semester T berisi mata kuliah konversi</div>
                            <p class="mt-1">Tidak masuk IPK; SKS tetap dihitung lulus.</p>
                        </div>
                    `;
                }

                if (semester.semester !== currentSemesterNumber()) {
                    return app.renderEmptyState('Belum ada nilai di semester ini.', 'secondary');
                }

                const validation = state.data?.krs_validation?.code || null;

                if (validation === 'pending_dpa') {
                    return `
                        <div class="${app.alertTone('warning')}" role="status">
                            <div class="font-medium">KRS kamu belum divalidasi DPA</div>
                            <p class="mt-1">Validasi KRS dulu agar nilai tampil setelah dosen mengisi nilai.</p>
                            <div class="mt-3"><a href="${esc(krsUrl)}" class="${app.buttonClass('secondary', { small: true })}">Cek KRS & hubungi DPA</a></div>
                        </div>
                    `;
                }

                if (validation === 'not_submitted') {
                    return `
                        <div class="${app.alertTone('warning')}" role="status">
                            <div class="font-medium">KRS semester ini belum diajukan</div>
                            <p class="mt-1">Ajukan dan validasi KRS agar nilai keluar.</p>
                            <div class="mt-3"><a href="${esc(krsUrl)}" class="${app.buttonClass('secondary', { small: true })}">Lihat status KRS</a></div>
                        </div>
                    `;
                }

                if (validation === 'validated') {
                    return `
                        <div class="${app.alertTone('info')}" role="status">
                            <div class="font-medium">Nilai semester ini belum keluar</div>
                            <p class="mt-1">Tampil setelah dosen mengisi nilai.</p>
                        </div>
                    `;
                }

                return `
                    <div class="${app.alertTone('info')}" role="status">
                        <div class="font-medium">Nilai semester ini belum keluar</div>
                        <p class="mt-1">Tampil setelah KRS divalidasi dan dosen mengisi nilai.</p>
                        <div class="mt-3"><a href="${esc(krsUrl)}" class="${app.buttonClass('secondary', { small: true })}">Cek status KRS</a></div>
                    </div>
                `;
            };

            const renderComponents = (course, columns) => {
                const keys = (Array.isArray(columns) && columns.length ? columns : Object.keys(course.components || {}))
                    .filter((key) => course.components && key in course.components);

                if (!keys.length) {
                    return '';
                }

                return `
                    <dl class="grid grid-cols-4 gap-x-3 gap-y-2">
                        ${keys.map((key) => {
                            const raw = course.components[key];
                            const value = typeof raw === 'number' && Number.isFinite(raw) ? raw : null;
                            const width = value === null ? 0 : Math.max(0, Math.min(100, value));
                            const label = componentLabels[key] || app.capitalize(String(key).replaceAll('_', ' '));

                            return `
                                <div class="min-w-0">
                                    <dt class="truncate text-xs text-ink-subtle">${esc(label)}</dt>
                                    <dd class="flex flex-col gap-1">
                                        <span class="text-sm font-medium tabular-nums text-ink">${value === null ? '–' : esc(Number.isInteger(value) ? value : value.toFixed(1))}</span>
                                        <span class="${app.ui.progressTrack} h-1.5" aria-hidden="true">${value === null ? '' : `<span class="${app.ui.progressFill} ${app.ui.fill.neutral}" style="width: ${width.toFixed(1)}%"></span>`}</span>
                                    </dd>
                                </div>
                            `;
                        }).join('')}
                    </dl>
                `;
            };

            const renderCourse = (course, columns, index) => {
                const graded = course.is_graded === true && course.letter;
                const facts = [
                    `<span class="font-mono">${esc(course.code || '–')}</span>`,
                    `${esc(course.sks ?? '–')} SKS`,
                    graded && course.weight !== null && course.weight !== undefined ? `bobot ${esc(course.weight)}` : '',
                ].filter(Boolean).join(' · ');

                return `
                    <article class="${app.ui.card} flex min-w-0 flex-col gap-3 p-4" data-slot="card" aria-labelledby="grades-course-${index}">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-medium leading-snug text-ink" id="grades-course-${index}">${esc(course.name || 'Mata kuliah')}</h3>
                                <p class="mt-0.5 text-xs text-ink-subtle">${facts}</p>
                                <p class="sr-only">${graded ? `Nilai ${esc(course.letter)}` : 'Nilai belum keluar'}</p>
                            </div>
                            <div class="shrink-0 text-end">
                                <div class="text-sm font-semibold tabular-nums ${graded ? letterTextClass(course.letter) : 'text-ink-subtle'}" aria-hidden="true">${graded ? esc(course.letter) : '–'}</div>
                                <div class="text-xs tabular-nums text-ink-subtle">Mutu ${graded && course.quality_points !== null && course.quality_points !== undefined ? esc(course.quality_points) : '–'}</div>
                            </div>
                        </div>
                        ${graded ? '' : '<p class="text-xs text-ink-subtle">Nilai belum keluar.</p>'}
                        ${renderComponents(course, columns)}
                    </article>
                `;
            };

            /** The IPS of every graded semester, drawn in the aside. */
            const renderTrend = () => {
                const series = semesters()
                    .filter((semester) => hasGrades(semester) && Number.isInteger(semester.semester) && typeof semester.ips === 'number')
                    .sort((left, right) => left.semester - right.semester);
                const best = series.reduce((top, semester) => (top === null || semester.ips > top.ips ? semester : top), null);

                hosts.trend.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="grades-trend-title">
                        <div class="${app.ui.cardHeader}">
                            <div class="min-w-0">
                                <h2 class="${app.ui.cardTitle}" id="grades-trend-title">Tren IPS</h2>
                                <p class="${app.ui.cardSubtitle} tabular-nums">${best ? `Tertinggi ${esc(formatIp(best.ips))} di semester ${esc(best.semester)}` : 'IPS tiap semester yang sudah dinilai'}</p>
                            </div>
                        </div>
                        <div class="${app.ui.cardBody}">
                            ${series.length ? app.renderSparkline(series.map((semester) => ({ label: `Smt ${semester.semester}`, value: semester.ips })), {
                                label: 'IPS per semester',
                                min: 0,
                                max: 4,
                                valueFormat: (value) => value.toFixed(2),
                            }) : app.renderEmptyState('Belum ada semester yang dinilai.', 'secondary', 'Tren muncul setelah nilai semester pertama keluar.', { icon: 'chart' })}
                        </div>
                    </section>
                `;
            };

            const renderPanel = () => {
                const semester = semesters().find((item) => semesterKey(item) === state.selected);

                if (!semester) {
                    hosts.panel.innerHTML = app.renderEmptyState('Belum ada KHS di portal.', 'secondary', 'Nilai muncul setelah semester pertamamu dinilai.', { icon: 'chart' });

                    return;
                }

                hosts.panel.setAttribute('aria-labelledby', `grades-tab-${semesterKey(semester)}`);

                const courses = Array.isArray(semester.courses) ? semester.courses : [];
                const lowCourses = courses.filter((course) => course.is_graded && typeof course.weight === 'number' && course.weight <= 2);
                const shown = state.lowOnly ? lowCourses : courses;
                const regular = semester.is_regular !== false && Number.isInteger(semester.semester);
                const statusBadge = !regular ? app.renderBadge(semester.semester === 'T' ? 'Konversi' : 'Non-reguler', 'secondary') : {
                    graded: app.renderBadge('Lengkap', 'success'),
                    partial: app.renderBadge('Sebagian keluar', 'warning'),
                    pending: app.renderBadge('Belum keluar', 'secondary'),
                    empty: app.renderBadge('Belum keluar', 'secondary'),
                }[semester.status] || '';
                const courseCount = Number(semester.course_count ?? courses.length) || 0;
                const facts = [
                    courseCount ? `${esc(courseCount)} MK` : '',
                    semester.total_sks ? `${esc(semester.total_sks)} SKS` : '',
                    hasGrades(semester) && semester.total_quality_points !== null ? `mutu ${esc(semester.total_quality_points)}` : '',
                ].filter(Boolean).join(' · ');

                const header = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="grades-panel-title">
                        <div class="${app.ui.cardBody} flex flex-wrap items-center justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold tracking-tight text-ink" id="grades-panel-title">${esc(semesterName(semester))}</h3>
                                    ${statusBadge}
                                </div>
                                ${facts ? `<p class="${app.ui.cardSubtitle} tabular-nums">${facts}</p>` : ''}
                            </div>
                            <div class="shrink-0 text-end">
                                <div class="${app.ui.metricLabel}">IPS</div>
                                <div class="text-2xl font-semibold tracking-tight tabular-nums ${hasGrades(semester) ? 'text-ink' : 'text-ink-subtle'}">${esc(formatIp(hasGrades(semester) ? semester.ips : null))}</div>
                            </div>
                        </div>
                        ${semester.status === 'partial' ? `<p class="border-t border-border-light px-4 py-3 text-xs text-ink-muted sm:px-5">Baru ${esc(semester.graded_count)} dari ${esc(semester.course_count)} nilai yang keluar. IPS bisa berubah setelah semua nilai masuk.</p>` : ''}
                        ${lowCourses.length ? `
                            <div class="border-t border-border-light px-4 py-3 sm:px-5">
                                <button type="button" class="${app.buttonClass('secondary', { small: true })} aria-pressed:border-brand aria-pressed:bg-surface-tint aria-pressed:text-brand-text" aria-pressed="${state.lowOnly ? 'true' : 'false'}" data-grades-low-toggle>
                                    ${state.lowOnly ? 'Tampilkan semua nilai' : `Hanya nilai C ke bawah (${esc(lowCourses.length)})`}
                                </button>
                            </div>
                        ` : ''}
                    </section>
                `;

                if (!hasGrades(semester) && !courses.some((course) => course.is_graded)) {
                    hosts.panel.innerHTML = `${header}${emptyExplanation(semester)}${courses.length ? `<div class="grid gap-3 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">${courses.map((course, index) => renderCourse(course, semester.columns, index)).join('')}</div>` : ''}`;

                    return;
                }

                hosts.panel.innerHTML = `
                    ${header}
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2" aria-label="Nilai mata kuliah">
                        ${shown.map((course, index) => renderCourse(course, semester.columns, index)).join('')}
                    </div>
                `;

                hosts.panel.querySelector('[data-grades-low-toggle]')?.addEventListener('click', () => {
                    state.lowOnly = !state.lowOnly;
                    renderPanel();
                    hosts.panel.querySelector('[data-grades-low-toggle]')?.focus();
                });
            };

            const defaultSelection = () => {
                const list = semesters();
                const requested = new URLSearchParams(window.location.search).get('semester');

                if (requested && list.some((semester) => semesterKey(semester) === requested)) {
                    return requested;
                }

                if (state.selected && list.some((semester) => semesterKey(semester) === state.selected)) {
                    return state.selected;
                }

                const graded = list
                    .filter((semester) => hasGrades(semester) && Number.isInteger(semester.semester))
                    .sort((left, right) => right.semester - left.semester)[0];

                return semesterKey(graded || list[0] || null) || null;
            };

            const selectSemester = (key, { focus = false } = {}) => {
                state.selected = key;
                state.lowOnly = false;
                renderChips();
                renderPanel();

                if (focus) {
                    hosts.chips.querySelector(`[data-grades-semester="${CSS.escape(String(key))}"]`)?.focus();
                }

                try {
                    const url = new URL(window.location.href);

                    url.searchParams.set('semester', key);
                    window.history.replaceState(window.history.state, '', url.toString());
                } catch (error) {
                    // The URL is a convenience only.
                }
            };

            const render = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.data = payload.data;
                state.selected = defaultSelection();
                renderSummary();
                renderAlerts();
                renderChips();
                renderPanel();
                renderTrend();
            };

            try {
                app.primeCachedView(endpoint, render, 'nilai semester');
            } catch (error) {
                // A blocked storage only skips the instant view.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                if (!(state.data)) {
                    app.mountErrorState(hosts.panel, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    hosts.chips.innerHTML = '';
                    hosts.trend.innerHTML = '';
                    hosts.summary.querySelectorAll('[data-slot="metric-value"]').forEach((node) => {
                        node.textContent = '–';
                    });
                }

                return;
            }

            const loadGrades = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(endpoint, { fresh: force, background });

                    if (!payload) {
                        return;
                    }

                    render(payload);
                    app.markViewSynced('Nilai', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Nilai gagal dimuat.', 'error');

                    if (!state.data) {
                        hosts.summary.querySelectorAll('[data-slot="metric-value"]').forEach((node) => {
                            node.textContent = '–';
                        });
                        hosts.chips.innerHTML = '';
                        hosts.trend.innerHTML = '';
                        app.mountErrorState(hosts.panel, 'Nilai gagal dimuat.', () => loadGrades({ force }), {
                            subtitle: `${app.describeError(error)} Kalau kamu belum punya nilai sama sekali, KHS memang belum tersedia.`,
                        });
                    } else {
                        app.showNotice(`Nilai gagal diperbarui. ${app.describeError(error)}`, 'warning');
                    }
                }
            };

            app.registerSyncHandler('grades-index', loadGrades, { aliases: ['krs-index'] });

            await loadGrades();
        });
    </script>
@endpush
