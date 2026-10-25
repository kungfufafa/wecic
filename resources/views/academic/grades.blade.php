@extends('layouts.app')

@section('title', 'Nilai (KHS)')
@section('page_id', 'grades-index')

@section('content')
    <x-academic-menu active="grades" />

    <x-page-header title="Nilai (KHS)" subtitle="Nilai tiap mata kuliah dan IPS per semester.">
        <p class="mt-1 text-caption text-ink-subtle" data-grades-updated></p>
    </x-page-header>

    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-3 gap-3" id="grades-summary">
            @foreach (['IPK', 'IPS', 'SKS lulus'] as $label)
                <x-metric :label="$label">
                    <x-slot:value><div class="mycic-skeleton h-7 w-14"></div></x-slot:value>
                </x-metric>
            @endforeach
        </div>

        <div id="grades-alerts" class="flex flex-col gap-3 empty:hidden"></div>

        <section aria-labelledby="grades-semester-heading" class="flex min-w-0 flex-col gap-3">
            <h2 class="mycic-section-title" id="grades-semester-heading">Per semester</h2>
            <div class="mycic-no-scrollbar -mx-4 overflow-x-auto px-4 md:mx-0 md:px-0" id="grades-semester-scroller">
                <div class="flex w-max gap-2" role="tablist" aria-label="Pilih semester" id="grades-semesters">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="mycic-skeleton h-12 w-28 shrink-0"></div>
                    @endfor
                </div>
            </div>

            <div id="grades-panel" role="tabpanel" aria-labelledby="grades-semester-heading" tabindex="-1" class="flex flex-col gap-3 focus:outline-none">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <span class="sr-only">Memuat nilai…</span>
                        <div class="mycic-skeleton h-5 w-1/3"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="mycic-card p-4">
                            <div class="flex items-start gap-3">
                                <div class="mycic-skeleton h-12 w-12 shrink-0"></div>
                                <div class="flex flex-1 flex-col gap-1.5">
                                    <div class="mycic-skeleton h-4 w-3/4"></div>
                                    <div class="mycic-skeleton h-3 w-1/3"></div>
                                </div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
        </section>

        <a href="{{ route('transcript.index') }}" class="mycic-card flex min-h-11 items-center justify-between gap-3 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            <span class="min-w-0">
                <span class="block text-sm font-bold text-ink">Transkrip & progres studi</span>
                <span class="block text-caption text-ink-subtle">IPK, sisa SKS, tren IPS, dan simulasi target IPK</span>
            </span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>
        </a>
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
                updated: document.querySelector('[data-grades-updated]'),
            };
            const state = { data: null, meta: null, selected: null, lowOnly: false };
            const esc = (value) => app.escapeHtml(value);
            const letterClasses = {
                success: 'bg-success-soft text-success',
                brand: 'bg-surface-tint text-brand-pressed',
                warning: 'bg-warning-soft text-warning',
                danger: 'bg-danger-soft text-danger',
                neutral: 'bg-surface text-ink-subtle',
            };
            const componentLabels = { absen: 'Absen', tugas: 'Tugas', uts: 'UTS', uas: 'UAS', quiz: 'Kuis', praktikum: 'Praktikum' };

            const letterTone = (letter) => ({ A: 'success', B: 'brand', C: 'warning', D: 'danger', E: 'danger' }[String(letter || '').trim().toUpperCase().charAt(0)] || 'neutral');

            const formatIp = (value) => (typeof value === 'number' && Number.isFinite(value) ? value.toFixed(2) : '–');

            const semesterKey = (semester) => String(semester?.semester ?? semester?.label ?? '');

            const semesterName = (semester) => (semester?.is_regular !== false && Number.isInteger(semester?.semester) ? `Semester ${semester.semester}` : 'Semester T');

            const semesters = () => (Array.isArray(state.data?.semesters) ? state.data.semesters : []);

            const currentSemesterNumber = () => semesters()
                .filter((semester) => semester.is_regular !== false && Number.isInteger(semester.semester))
                .reduce((highest, semester) => Math.max(highest, semester.semester), 0) || null;

            const hasGrades = (semester) => ['graded', 'partial'].includes(semester?.status) && Number(semester?.graded_count) > 0;

            const renderFreshness = () => {
                const meta = state.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || state.data?.extracted_at || meta.cached_at);
                const parts = [ago ? `Diperbarui ${ago}` : 'Waktu pembaruan belum diketahui'];
                let warn = false;

                if (meta.status === 'failed') {
                    parts.push('gagal diperbarui, ini data terakhir');
                    warn = true;
                } else if (meta.status === 'syncing') {
                    parts.push('sedang diperbarui dari portal');
                }

                if (meta.data_origin === 'device_cache') {
                    parts.push(meta.cache_is_stale ? 'data tersimpan di perangkat, mungkin sudah lama' : 'data tersimpan di perangkat');
                    warn = warn || Boolean(meta.cache_is_stale);
                }

                hosts.updated.textContent = parts.join(' · ');
                hosts.updated.classList.toggle('text-warning', warn);
                hosts.updated.classList.toggle('text-ink-subtle', !warn);
            };

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

                hosts.summary.innerHTML = `
                    <div class="mycic-metric">
                        <div class="mycic-metric-label">IPK</div>
                        <div class="mycic-metric-value">${esc(formatIp(summary.ipk))}</div>
                        <div class="mycic-metric-note">${summary.ipk === null || summary.ipk === undefined ? 'Belum ada nilai' : (summary.source === 'official' ? 'Resmi portal' : 'Dihitung MyCIC')}</div>
                    </div>
                    <div class="mycic-metric">
                        <div class="mycic-metric-label" title="IPS semester terakhir yang sudah dinilai">IPS</div>
                        <div class="mycic-metric-value">${esc(formatIp(latest?.ips ?? null))}</div>
                        <div class="mycic-metric-note">
                            ${latest ? `Semester ${esc(latest.semester)}${latest.is_complete === false ? ' · sebagian' : ''}` : 'Belum ada'}
                            ${deltaText ? `<span class="font-semibold ${deltaTone}" aria-hidden="true">${esc(deltaText)}</span>` : ''}
                            ${deltaLabel ? `<span class="sr-only">${esc(deltaLabel)}</span>` : ''}
                        </div>
                    </div>
                    <div class="mycic-metric">
                        <div class="mycic-metric-label">SKS lulus</div>
                        <div class="mycic-metric-value">${esc(summary.sks_passed ?? '–')}</div>
                        <div class="mycic-metric-note">${progress.target_sks ? `dari ${esc(progress.target_sks)} SKS` : 'SKS terkumpul'}${summary.conversion_sks > 0 ? ` · ${esc(summary.conversion_sks)} konversi` : ''}</div>
                    </div>
                `;
            };

            const renderAlerts = () => {
                const data = state.data || {};
                const alerts = [];

                if (data.sources?.is_partial) {
                    alerts.push(`
                        <div class="${app.alertTone('warning')}">
                            <div class="flex flex-wrap items-center gap-2 font-semibold">${app.renderBadge('Data sebagian', 'warning')} Halaman nilai SIPANDAI gagal dibaca</div>
                            <p class="mt-1">MyCIC memakai nilai dari halaman KRS, jadi komponen absen dan tugas tidak tampil. Coba sinkronkan lagi nanti.</p>
                        </div>
                    `);
                }

                if (data.summary?.mismatch) {
                    alerts.push(`
                        <div class="${app.alertTone('info')}">
                            IPK resmi portal berbeda dengan hitungan MyCIC dari KHS. MyCIC menampilkan angka resmi portal; rinciannya ada di <a class="mycic-link" href="/transcript">Transkrip</a>.
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
                            class="flex min-h-11 shrink-0 flex-col items-start justify-center rounded-lg border border-border bg-panel px-3 py-1.5 text-start transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-selected:border-brand aria-selected:bg-surface-tint"
                        >
                            <span class="text-sm font-bold text-ink">${esc(semesterName(semester))}</span>
                            <span class="text-caption tabular-nums ${hasGrades(semester) ? 'text-ink-muted' : 'text-ink-subtle'}">${esc(chipCaption(semester))}</span>
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
                            <div class="font-semibold text-ink">Semester T berisi mata kuliah konversi</div>
                            <p class="mt-1">Portal tidak mencantumkan nilai MK konversi, jadi kartu nilai semester T selalu kosong dan tidak masuk IPK. SKS-nya tetap dihitung sebagai SKS lulus dari KRS semester T.</p>
                        </div>
                    `;
                }

                if (semester.semester !== currentSemesterNumber()) {
                    return app.renderEmptyState('Belum ada nilai di semester ini.', 'secondary', 'Kalau seharusnya ada, cek langsung di menu KHS portal kampus.');
                }

                const validation = state.data?.krs_validation?.code || null;

                if (validation === 'pending_dpa') {
                    return `
                        <div class="${app.alertTone('warning')}" role="status">
                            <div class="font-semibold">Nilai belum bisa tampil: KRS kamu belum divalidasi DPA</div>
                            <p class="mt-1">Nilai semester ini baru keluar setelah DPA memvalidasi KRS dan dosen mengisi nilai di SIPANDAI.</p>
                            <div class="mt-3"><a href="${esc(krsUrl)}" class="${app.buttonClass('secondary', { small: true })}">Cek KRS & hubungi DPA</a></div>
                        </div>
                    `;
                }

                if (validation === 'not_submitted') {
                    return `
                        <div class="${app.alertTone('warning')}" role="status">
                            <div class="font-semibold">KRS semester ini belum diajukan</div>
                            <p class="mt-1">Tanpa KRS yang divalidasi DPA, nilai semester ini tidak akan keluar.</p>
                            <div class="mt-3"><a href="${esc(krsUrl)}" class="${app.buttonClass('secondary', { small: true })}">Lihat status KRS</a></div>
                        </div>
                    `;
                }

                if (validation === 'validated') {
                    return `
                        <div class="${app.alertTone('info')}" role="status">
                            <div class="font-semibold">Nilai semester ini belum keluar</div>
                            <p class="mt-1">KRS kamu sudah divalidasi DPA. Nilai muncul setelah dosen mengisi nilai di SIPANDAI, biasanya setelah UAS.</p>
                        </div>
                    `;
                }

                return `
                    <div class="${app.alertTone('info')}" role="status">
                        <div class="font-semibold">Nilai semester ini belum keluar</div>
                        <p class="mt-1">Nilai baru tampil setelah KRS divalidasi DPA dan dosen mengisi nilai di SIPANDAI.</p>
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
                                    <dt class="text-caption text-ink-subtle">${esc(label)}</dt>
                                    <dd class="flex flex-col gap-1">
                                        <span class="text-sm font-semibold tabular-nums text-ink">${value === null ? '–' : esc(Number.isInteger(value) ? value : value.toFixed(1))}</span>
                                        <span class="mycic-progress-track h-1.5" aria-hidden="true">${value === null ? '' : `<span class="mycic-progress-fill block bg-brand" style="width: ${width.toFixed(1)}%"></span>`}</span>
                                    </dd>
                                </div>
                            `;
                        }).join('')}
                    </dl>
                `;
            };

            const renderCourse = (course, columns, index) => {
                const graded = course.is_graded === true && course.letter;
                const tone = graded ? letterTone(course.letter) : 'neutral';
                const facts = [
                    `<span class="font-mono">${esc(course.code || '–')}</span>`,
                    `${esc(course.sks ?? '–')} SKS`,
                    graded && course.weight !== null && course.weight !== undefined ? `bobot ${esc(course.weight)}` : '',
                ].filter(Boolean).join(' · ');

                return `
                    <article class="mycic-card flex min-w-0 flex-col gap-3 p-4" aria-labelledby="grades-course-${index}">
                        <div class="flex items-start gap-3">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-bold ${letterClasses[tone]}" aria-hidden="true">${graded ? esc(course.letter) : '–'}</span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-bold text-ink" id="grades-course-${index}">${esc(course.name || 'Mata kuliah')}</h3>
                                <p class="text-caption text-ink-subtle">${facts}</p>
                                <p class="sr-only">${graded ? `Nilai ${esc(course.letter)}` : 'Nilai belum keluar'}</p>
                            </div>
                            <div class="shrink-0 text-end">
                                <div class="text-caption text-ink-subtle">Mutu</div>
                                <div class="text-sm font-bold tabular-nums text-ink">${graded && course.quality_points !== null && course.quality_points !== undefined ? esc(course.quality_points) : '–'}</div>
                            </div>
                        </div>
                        ${graded ? '' : '<p class="text-caption text-ink-subtle">Nilai belum keluar.</p>'}
                        ${renderComponents(course, columns)}
                    </article>
                `;
            };

            const renderPanel = () => {
                const semester = semesters().find((item) => semesterKey(item) === state.selected);

                if (!semester) {
                    hosts.panel.innerHTML = app.renderEmptyState('Belum ada KHS di portal.', 'secondary', 'Nilai muncul setelah semester pertamamu dinilai.');

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
                    <section class="mycic-card" aria-labelledby="grades-panel-title">
                        <div class="mycic-card-body flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-subtitle font-bold text-ink" id="grades-panel-title">${esc(semesterName(semester))}</h3>
                                    ${statusBadge}
                                </div>
                                ${facts ? `<p class="mt-0.5 text-caption text-ink-subtle">${facts}</p>` : ''}
                            </div>
                            <div class="shrink-0 text-end">
                                <div class="mycic-metric-label">IPS</div>
                                <div class="text-2xl font-bold tabular-nums text-ink">${esc(formatIp(hasGrades(semester) ? semester.ips : null))}</div>
                            </div>
                        </div>
                        ${semester.status === 'partial' ? `<p class="border-t border-border-light px-4 py-3 text-caption text-ink-muted md:px-5">Baru ${esc(semester.graded_count)} dari ${esc(semester.course_count)} nilai yang keluar. IPS bisa berubah setelah semua nilai masuk.</p>` : ''}
                        ${lowCourses.length ? `
                            <div class="border-t border-border-light px-4 py-3 md:px-5">
                                <button type="button" class="${app.buttonClass('secondary', { small: true })} aria-pressed:border-brand aria-pressed:text-brand" aria-pressed="${state.lowOnly ? 'true' : 'false'}" data-grades-low-toggle>
                                    ${state.lowOnly ? 'Tampilkan semua nilai' : `Hanya nilai C ke bawah (${esc(lowCourses.length)})`}
                                </button>
                            </div>
                        ` : ''}
                    </section>
                `;

                if (!hasGrades(semester) && !courses.some((course) => course.is_graded)) {
                    hosts.panel.innerHTML = `${header}${emptyExplanation(semester)}${courses.length ? `<div class="grid gap-3 md:grid-cols-2">${courses.map((course, index) => renderCourse(course, semester.columns, index)).join('')}</div>` : ''}`;

                    return;
                }

                hosts.panel.innerHTML = `
                    ${header}
                    <div class="grid gap-3 md:grid-cols-2" aria-label="Nilai mata kuliah">
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
                state.meta = payload.meta || {};
                state.selected = defaultSelection();
                renderSummary();
                renderAlerts();
                renderChips();
                renderPanel();
                renderFreshness();
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
                    hosts.summary.querySelectorAll('.mycic-metric-value').forEach((node) => {
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
                        hosts.summary.querySelectorAll('.mycic-metric-value').forEach((node) => {
                            node.textContent = '–';
                        });
                        hosts.chips.innerHTML = '';
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
