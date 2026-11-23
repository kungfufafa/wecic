@extends('layouts.app')

@section('title', 'Detail KRS')
@section('page_id', 'krs-show')

@section('content')
    <style>
        @media print {
            [data-sync-alert],
            [data-pull-indicator],
            [data-operation-status] {
                display: none !important;
            }
        }
    </style>

    <x-page-hero :back="route('krs.index', ['tab' => 'riwayat'])" back-label="Riwayat KRS">
        <x-slot:title id="krs-show-title">Detail KRS</x-slot:title>
        <x-slot:subtitle id="krs-show-subtitle"></x-slot:subtitle>
        <x-slot:meta>
            <span class="font-medium text-ink-muted" id="krs-show-pretitle">Kartu Rencana Studi</span>
            <span class="flex flex-wrap gap-2 empty:hidden" id="krs-show-chips"></span>
        </x-slot:meta>
        <x-slot:tabs class="print:hidden"><x-academic-menu active="krs" underline /></x-slot:tabs>
    </x-page-hero>

    <div data-krs-id="{{ $id }}" id="krs-show-root">
        <div class="hidden print:mb-4 print:block" id="krs-show-print-note"></div>

        <div id="krs-show-alerts" class="mb-6 flex flex-col gap-3 empty:hidden print:hidden"></div>

        <x-split>
            <div class="min-w-0" id="krs-show-courses">
                <x-card aria-busy="true">
                    <x-card.header>
                        <span class="sr-only">Memuat detail KRS…</span>
                        <x-skeleton class="h-5 w-40" />
                        <x-skeleton class="h-5 w-24" />
                    </x-card.header>
                    <x-list>
                        @for ($i = 0; $i < 4; $i++)
                            <x-list-row flush>
                                <x-slot:leading><x-skeleton class="size-11 rounded-lg!" /></x-slot:leading>
                                <div class="flex flex-col gap-1.5">
                                    <x-skeleton class="h-3 w-16" />
                                    <x-skeleton class="h-4 w-40 sm:w-56" />
                                </div>
                                <x-slot:trailing><x-skeleton class="size-10 rounded-lg!" /></x-slot:trailing>
                            </x-list-row>
                        @endfor
                    </x-list>
                </x-card>
            </div>

            <x-slot:aside id="krs-show-aside" class="print:hidden" aria-label="Ringkasan KRS">
                <x-card aria-busy="true">
                    <x-card.body class="flex flex-col gap-3">
                        <x-skeleton class="h-4 w-1/2" />
                        <x-skeleton class="h-8 w-1/3" />
                    </x-card.body>
                </x-card>
            </x-slot:aside>
        </x-split>

        <nav class="mt-8 grid grid-cols-2 gap-3 empty:hidden print:hidden" id="krs-show-nav" aria-label="Semester lain"></nav>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const root = document.getElementById('krs-show-root');
            const krsId = Number(root?.dataset.krsId);
            const endpoint = `/api/academic/krs/${krsId}`;
            const historyUrl = @json(route('krs.index', ['tab' => 'riwayat']));
            const krsUrl = @json(route('krs.index'));
            const gradesUrl = @json(route('grades.index'));
            const hosts = {
                pretitle: document.getElementById('krs-show-pretitle'),
                title: document.getElementById('krs-show-title'),
                subtitle: document.getElementById('krs-show-subtitle'),
                chips: document.getElementById('krs-show-chips'),
                alerts: document.getElementById('krs-show-alerts'),
                courses: document.getElementById('krs-show-courses'),
                aside: document.getElementById('krs-show-aside'),
                nav: document.getElementById('krs-show-nav'),
                print: document.getElementById('krs-show-print-note'),
            };
            const state = { detail: null, meta: null, grades: null, currentKrs: null, extrasRequested: false };
            const esc = (value) => app.escapeHtml(value);
            const printIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>';

            const cachedData = (url) => {
                try {
                    return app.getCachedResponse(url)?.payload?.data ?? null;
                } catch (error) {
                    return null;
                }
            };

            const letterTextClass = (letter) => (['D', 'E'].includes(String(letter || '').trim().toUpperCase().charAt(0)) ? 'text-danger' : 'text-ink');

            const isRegular = () => state.detail?.header?.semester_is_regular !== false && Number.isInteger(state.detail?.header?.semester);

            const semesterTitle = () => (isRegular() ? `Semester ${state.detail.header.semester}` : 'Semester T');

            const gradesSemester = () => {
                if (!isRegular()) {
                    return null;
                }

                return (state.grades?.semesters || []).find((semester) => semester.semester === state.detail.header.semester) || null;
            };

            const krsStatus = () => {
                if (!state.detail?.navigation?.is_latest || !state.currentKrs?.found) {
                    return null;
                }

                return state.currentKrs.insights?.status || state.currentKrs.status || null;
            };

            const renderHeader = () => {
                const detail = state.detail;
                const header = detail.header || {};
                const chips = [];

                // The portal's KRS record id means nothing to a student; it stays in the URL only.
                hosts.pretitle.textContent = 'Kartu Rencana Studi';
                hosts.title.textContent = `KRS ${semesterTitle()}`;
                hosts.subtitle.textContent = [
                    header.academic_year ? `T.A ${header.academic_year}` : '',
                    header.date ? `diajukan ${app.formatIndonesianDate(header.date, { weekday: true })}` : '',
                ].filter(Boolean).join(' · ') || '';
                document.title = `KRS ${semesterTitle()}`;

                if (detail.navigation?.is_latest) {
                    chips.push('<span class="text-ink-muted">KRS terbaru</span>');
                }

                if (!isRegular()) {
                    chips.push(`<span class="text-ink-muted">${state.detail.header?.semester === 'T' ? 'Konversi' : 'Non-reguler'}</span>`);
                }

                const status = krsStatus();

                if (status) {
                    const tone = { pending_dpa: 'warning', validated: 'success', not_submitted: 'danger' }[status.code] || 'secondary';

                    chips.push(app.renderBadge(status.label || 'Status KRS', tone));
                }

                hosts.chips.innerHTML = chips.join('');
            };

            const renderAlerts = () => {
                const detail = state.detail;
                const alerts = [];
                const status = krsStatus();

                if (!isRegular()) {
                    alerts.push(`
                        <div class="${app.alertTone('neutral')}">
                            <div class="font-medium text-ink">KRS semester T berisi mata kuliah konversi</div>
                            <p class="mt-1">Diakui sebagai SKS lulus; tidak masuk IPK karena tanpa nilai di portal.</p>
                        </div>
                    `);
                }

                if (status?.code === 'pending_dpa') {
                    alerts.push(`
                        <div class="${app.alertTone('warning')}">
                            <div class="font-medium">KRS ini belum divalidasi DPA</div>
                            <p class="mt-1">Nilai baru tampil setelah validasi DPA. <a class="${app.ui.link}" href="${esc(krsUrl)}">Hubungi DPA</a></p>
                        </div>
                    `);
                }

                hosts.alerts.innerHTML = alerts.join('');
            };

            const renderCourses = () => {
                const detail = state.detail;
                const courses = Array.isArray(detail.courses) ? detail.courses : [];
                const totals = detail.totals || {};
                const khs = gradesSemester();
                const byCode = new Map((khs?.courses || []).map((course) => [String(course.code || '').toUpperCase(), course]));
                const countsAsLoad = detail.counts_as_study_load !== false;
                const showLetters = Boolean(state.grades) && isRegular() && (khs?.courses || []).some((course) => course.is_graded);
                const semesterNumber = isRegular() ? detail.header.semester : null;

                if (!courses.length) {
                    hosts.courses.innerHTML = `
                        <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-show-courses-title">
                            <div class="${app.ui.cardHeader}"><h2 class="${app.ui.cardTitle}" id="krs-show-courses-title">Mata kuliah</h2></div>
                            <div class="${app.ui.cardBody}">${app.renderEmptyState('KRS ini tidak memuat mata kuliah.', 'secondary', 'Kalau seharusnya ada, cek langsung di menu Perwalian portal kampus.', { icon: 'list-check' })}</div>
                        </section>
                    `;

                    return;
                }

                hosts.courses.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-show-courses-title">
                        <div class="${app.ui.cardHeader} flex-wrap">
                            <h2 class="${app.ui.cardTitle}" id="krs-show-courses-title">Mata kuliah</h2>
                            <span class="whitespace-nowrap text-xs text-ink-muted tabular-nums">${countsAsLoad ? `${esc(totals.courses ?? courses.length)} MK · ${esc(totals.sks ?? '–')} SKS` : `${esc(courses.length)} MK · bukan beban studi`}</span>
                        </div>
                        <ul class="${app.ui.list}" aria-label="Mata kuliah di KRS ini">
                            ${courses.map((course) => {
                                const grade = byCode.get(String(course.code || '').toUpperCase());
                                const facts = [];

                                if (!course.is_retake && course.status) {
                                    facts.push(`<span>${esc(course.status)}</span>`);
                                }

                                if (Number.isInteger(course.semester) && course.semester !== semesterNumber) {
                                    facts.push(`<span>MK smt ${esc(course.semester)}</span>`);
                                }

                                const letter = grade?.is_graded ? grade.letter : null;

                                return `
                                    <li class="${app.ui.listRowFlush}">
                                        <div class="${app.ui.listRowBody}">
                                            <div class="text-xs text-ink-subtle"><span class="font-mono">${esc(course.code || '–')}</span> · <span class="tabular-nums">${course.sks === null || course.sks === undefined ? '–' : esc(course.sks)}</span> SKS</div>
                                            <div class="text-sm font-medium leading-snug text-ink">${esc(course.name || 'Mata kuliah')}</div>
                                            ${course.is_retake || facts.length ? `<div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-ink-subtle">${course.is_retake ? app.renderBadge('Ulang', 'warning') : ''}${facts.join('<span aria-hidden="true">·</span>')}</div>` : ''}
                                        </div>
                                        ${showLetters ? `
                                            <span class="w-8 shrink-0 text-end text-sm font-semibold tabular-nums ${letter ? letterTextClass(letter) : 'text-ink-subtle'}" title="${letter ? `Nilai ${esc(letter)}` : 'Nilai belum keluar'}">
                                                <span aria-hidden="true">${letter ? esc(letter) : '–'}</span>
                                                <span class="sr-only">${letter ? `Nilai ${esc(letter)}` : 'Nilai belum keluar'}</span>
                                            </span>
                                        ` : ''}
                                    </li>
                                `;
                            }).join('')}
                        </ul>
                        ${totals.consistent === false ? '<p class="border-t border-border-light px-4 py-3 text-xs text-warning sm:px-5">Total di portal berbeda dengan jumlah daftar MK. Cek lagi di portal kampus.</p>' : ''}
                    </section>
                `;
            };

            const renderAside = () => {
                const detail = state.detail;
                const khs = gradesSemester();
                const status = krsStatus();
                const printable = detail.print_available === true && detail.navigation?.is_latest === true;
                const retakes = (detail.courses || []).filter((course) => course.is_retake).length;
                let result = '';

                if (!isRegular()) {
                    result = '<p class="text-sm text-ink-muted">Semester T tidak punya kartu nilai di portal.</p>';
                } else if (!state.grades) {
                    result = '<p class="text-sm text-ink-muted">Nilai semester ini sedang dimuat…</p>';
                } else if (khs && ['graded', 'partial'].includes(khs.status) && khs.graded_count > 0) {
                    result = `
                        <dl class="grid grid-cols-3 divide-x divide-border rounded-lg bg-surface">
                            <div class="flex min-w-0 flex-col gap-1 p-3"><dt class="${app.ui.metricLabel}">IPS</dt><dd class="${app.ui.metricValue}">${typeof khs.ips === 'number' ? esc(khs.ips.toFixed(2)) : '–'}</dd></div>
                            <div class="flex min-w-0 flex-col gap-1 p-3"><dt class="${app.ui.metricLabel}">SKS</dt><dd class="${app.ui.metricValue}">${esc(khs.total_sks ?? '–')}</dd></div>
                            <div class="flex min-w-0 flex-col gap-1 p-3"><dt class="${app.ui.metricLabel}">Mutu</dt><dd class="${app.ui.metricValue}">${esc(khs.total_quality_points ?? '–')}</dd></div>
                        </dl>
                        ${khs.status === 'partial' ? `<p class="text-xs text-warning">Baru ${esc(khs.graded_count)} dari ${esc(khs.course_count)} nilai yang keluar.</p>` : ''}
                    `;
                } else {
                    const reason = status?.code === 'pending_dpa'
                        ? 'Menunggu validasi DPA dan nilai dosen.'
                        : 'Menunggu dosen mengisi nilai.';

                    result = `<div class="${app.ui.inset}"><p class="text-sm font-medium leading-snug text-ink">Nilai semester ini belum keluar</p><p class="mt-0.5 text-xs text-ink-subtle">${esc(reason)}</p></div>`;
                }

                hosts.aside.innerHTML = `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="krs-show-result-title">
                        <div class="${app.ui.cardHeader}">
                            <h2 class="${app.ui.cardTitle}" id="krs-show-result-title">Hasil ${esc(semesterTitle())}</h2>
                        </div>
                        <div class="${app.ui.cardBody} flex flex-col gap-4">
                            ${result}
                            ${retakes ? `<p class="text-xs text-ink-subtle">${esc(retakes)} MK ulang.</p>` : ''}
                            <div class="flex flex-wrap gap-2">
                                ${isRegular() ? `<a href="${esc(gradesUrl)}?semester=${esc(detail.header.semester)}" class="${app.buttonClass('secondary', { small: true })}">Lihat nilai lengkap</a>` : ''}
                                ${printable ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-krs-print>${printIcon}Cetak KRS</button>` : ''}
                            </div>
                        </div>
                    </section>
                `;

                hosts.aside.querySelector('[data-krs-print]')?.addEventListener('click', () => window.print());
            };

            const renderNavigation = () => {
                const navigation = state.detail.navigation || {};
                const link = (id, label, align) => (id ? `
                    <a href="/krs/${esc(id)}" class="${app.buttonClass('secondary')} w-full ${align === 'end' ? 'justify-end! text-end' : 'justify-start!'}" aria-label="KRS semester ${align === 'end' ? 'berikutnya' : 'sebelumnya'}">
                        ${align === 'end' ? `${esc(label)}<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>` : `<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6l6 6" /></svg>${esc(label)}`}
                    </a>
                ` : '<span></span>');

                hosts.nav.innerHTML = navigation.older_krs_id || navigation.newer_krs_id
                    ? `${link(navigation.older_krs_id, 'Sebelumnya', 'start')}${link(navigation.newer_krs_id, 'Berikutnya', 'end')}`
                    : '';
            };

            const renderPrintNote = () => {
                const detail = state.detail;
                const header = detail.header || {};
                const stamp = app.formatIndonesianDate(state.meta?.synced_at || detail.extracted_at, { time: true });

                hosts.print.innerHTML = `
                    <div class="border-b border-border pb-3 text-sm text-ink-muted">
                        <div>${esc([header.name, header.nim].filter(Boolean).join(' · '))}</div>
                        <p class="mt-1 text-xs">Dibuat WeCIC dari data portal per ${esc(stamp)} · bukan dokumen resmi.</p>
                    </div>
                `;
            };

            const render = () => {
                if (!state.detail) {
                    return;
                }

                renderHeader();
                renderAlerts();
                renderCourses();
                renderAside();
                renderNavigation();
                renderPrintNote();
            };

            const receive = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.detail = payload.data;
                state.meta = payload.meta || {};
                state.grades = state.grades || cachedData('/api/academic/grades');
                state.currentKrs = state.currentKrs || (payload.data.navigation?.is_latest ? cachedData('/api/academic/krs') : null);
                render();
            };

            /**
             * Grades and the current KRS status are optional extras: read them
             * once in the background when the device has no copy yet.
             */
            const loadExtras = async ({ background = false } = {}) => {
                if (state.extrasRequested || !state.detail || !navigator.onLine) {
                    return;
                }

                state.extrasRequested = true;
                const jobs = [];

                if (!state.grades && isRegular()) {
                    jobs.push(app.request('/api/academic/grades', { redirectOnAuth: false, timeout: 20000, background })
                        .then((payload) => {
                            state.grades = payload?.data || null;
                        })
                        .catch(() => {
                            state.grades = { semesters: [] };
                        }));
                }

                if (!state.currentKrs && state.detail.navigation?.is_latest) {
                    jobs.push(app.request('/api/academic/krs', { redirectOnAuth: false, timeout: 20000, background })
                        .then((payload) => {
                            state.currentKrs = payload?.data || null;
                        })
                        .catch(() => {}));
                }

                if (jobs.length) {
                    await Promise.allSettled(jobs);
                    render();
                }
            };

            // Only "not in your history" is final; a 404 for a page the portal could not serve is a hiccup.
            const isNotInHistory = (error) => error?.status === 404
                && (error?.payload?.reason === 'not_in_history' || (!error?.payload?.reason && /riwayat/i.test(error?.payload?.message || '')));

            const renderLoadError = (error, retry) => {
                const message = error?.payload?.message || app.describeError(error);

                hosts.alerts.innerHTML = '';
                hosts.aside.innerHTML = '';
                hosts.nav.innerHTML = '';
                hosts.chips.innerHTML = '';

                if (isNotInHistory(error)) {
                    try {
                        app.clearCachedResponse(endpoint);
                    } catch (storageError) {
                        // Nothing stored to forget.
                    }

                    hosts.title.textContent = 'KRS tidak ditemukan';
                    hosts.subtitle.textContent = 'KRS ini tidak ada di riwayat KRS kamu.';
                    hosts.courses.innerHTML = `
                        <div class="${app.ui.card}" data-slot="card"><div class="${app.ui.cardBody}">
                            ${app.renderEmptyState(message, 'secondary', 'Pilih semester dari riwayat KRS kamu.')}
                            <div class="mt-3 flex justify-center"><a href="${esc(historyUrl)}" class="${app.buttonClass('secondary', { small: true })}">Buka riwayat KRS</a></div>
                        </div></div>
                    `;

                    return;
                }

                app.mountErrorState(hosts.courses, 'Detail KRS gagal dimuat.', retry, { subtitle: message });
            };

            try {
                app.primeCachedView(endpoint, receive, 'detail KRS');
            } catch (error) {
                // A blocked storage only skips the instant view.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                if (!(state.detail)) {
                    app.mountErrorState(hosts.courses, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    hosts.aside.innerHTML = '';
                }

                return;
            }

            const loadDetail = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(endpoint, { fresh: force, background });

                    if (!payload) {
                        return;
                    }

                    receive(payload);
                    app.markViewSynced('Detail KRS', payload, { force });
                    void loadExtras({ background });
                } catch (error) {
                    app.setSyncStatus('Detail KRS gagal dimuat.', 'error');

                    if (!state.detail || isNotInHistory(error)) {
                        state.detail = null;
                        renderLoadError(error, () => loadDetail({ force }));
                    } else {
                        app.showNotice(`Detail KRS gagal diperbarui. ${error?.payload?.message || app.describeError(error)}`, 'warning');
                    }
                }
            };

            app.registerSyncHandler('krs-show', loadDetail, { aliases: ['advising-index'] });

            await loadDetail();
        });
    </script>
@endpush
