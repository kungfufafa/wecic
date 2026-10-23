@extends('layouts.app')

@section('title', 'Detail KRS')
@section('page_id', 'krs-show')

@php
    $academicLinks = [
        ['href' => route('krs.index'), 'label' => 'KRS & Perwalian', 'active' => true],
        ['href' => route('grades.index'), 'label' => 'Nilai', 'active' => false],
        ['href' => route('transcript.index'), 'label' => 'Transkrip', 'active' => false],
    ];
@endphp

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

    <nav class="mb-4 print:hidden" aria-label="Menu akademik">
        <ul class="inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-xl border border-border/40 bg-surface p-1">
            @foreach ($academicLinks as $link)
                <li class="shrink-0">
                    <a
                        href="{{ $link['href'] }}"
                        class="inline-flex min-h-11 items-center whitespace-nowrap sm:min-h-10 rounded-lg px-3 py-2 text-xs font-semibold transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm {{ $link['active'] ? 'border border-border/40 bg-panel font-bold text-brand shadow-xs' : 'text-ink-muted hover:text-ink' }}"
                        @if ($link['active']) aria-current="page" @endif
                    >{{ $link['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <a href="{{ route('krs.index', ['tab' => 'riwayat']) }}" class="mb-3 inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-brand-pressed hover:text-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand print:hidden">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6l6 6" /></svg>
        Riwayat KRS
    </a>

    <div data-krs-id="{{ $id }}" id="krs-show-root">
        <header class="mb-4">
            <div class="text-caption font-semibold uppercase tracking-wide text-ink-subtle" id="krs-show-pretitle">Kartu Rencana Studi</div>
            <h1 class="mycic-page-heading" id="krs-show-title">Detail KRS</h1>
            <p class="mycic-page-subheading" id="krs-show-subtitle">Rencana studi satu semester beserta nilainya bila sudah keluar.</p>
            <div class="mt-2 flex flex-wrap gap-2" id="krs-show-chips"></div>
            <p class="mt-1 text-caption text-ink-subtle print:hidden" data-krs-show-updated></p>
        </header>

        <div class="hidden print:mb-4 print:block" id="krs-show-print-note"></div>

        <div id="krs-show-alerts" class="flex flex-col gap-3 print:hidden"></div>

        <div class="mt-4 flex flex-col gap-4 lg:grid lg:grid-cols-12 lg:items-start lg:gap-5">
            <div class="min-w-0 lg:col-span-8" id="krs-show-courses">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-header">
                        <span class="sr-only">Memuat detail KRS…</span>
                        <div class="mycic-skeleton h-5 w-40"></div>
                    </div>
                    <div class="mycic-card-body flex flex-col divide-y divide-border-light">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="flex flex-col gap-1.5">
                                    <div class="mycic-skeleton h-3 w-16"></div>
                                    <div class="mycic-skeleton h-4 w-40 sm:w-56"></div>
                                </div>
                                <div class="mycic-skeleton h-8 w-8"></div>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>

            <aside class="flex min-w-0 flex-col gap-4 print:hidden lg:sticky lg:top-20 lg:col-span-4" id="krs-show-aside" aria-label="Ringkasan KRS">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="mycic-skeleton h-4 w-1/2"></div>
                        <div class="mycic-skeleton h-8 w-1/3"></div>
                    </div>
                </div>
            </aside>
        </div>

        <nav class="mt-5 grid grid-cols-2 gap-3 print:hidden" id="krs-show-nav" aria-label="Semester lain"></nav>
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
                updated: document.querySelector('[data-krs-show-updated]'),
                alerts: document.getElementById('krs-show-alerts'),
                courses: document.getElementById('krs-show-courses'),
                aside: document.getElementById('krs-show-aside'),
                nav: document.getElementById('krs-show-nav'),
                print: document.getElementById('krs-show-print-note'),
            };
            const state = { detail: null, meta: null, grades: null, currentKrs: null, extrasRequested: false };
            const esc = (value) => app.escapeHtml(value);
            const letterClasses = {
                success: 'bg-success-soft text-success',
                brand: 'bg-surface-tint text-brand-pressed',
                warning: 'bg-warning-soft text-warning',
                danger: 'bg-danger-soft text-danger',
                neutral: 'bg-surface text-ink-subtle',
            };
            const printIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>';

            const cachedData = (url) => {
                try {
                    return app.getCachedResponse(url)?.payload?.data ?? null;
                } catch (error) {
                    return null;
                }
            };

            const letterTone = (letter) => ({ A: 'success', B: 'brand', C: 'warning', D: 'danger', E: 'danger' }[String(letter || '').trim().toUpperCase().charAt(0)] || 'neutral');

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

            const renderFreshness = () => {
                const meta = state.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || state.detail?.extracted_at || meta.cached_at);
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

            const renderHeader = () => {
                const detail = state.detail;
                const header = detail.header || {};
                const chips = [];

                // The portal's KRS record id means nothing to a student; it stays in the URL only.
                hosts.pretitle.textContent = header.academic_year ? `Kartu Rencana Studi · T.A ${header.academic_year}` : 'Kartu Rencana Studi';
                hosts.title.textContent = `KRS ${semesterTitle()}`;
                hosts.subtitle.textContent = [
                    header.academic_year ? `T.A ${header.academic_year}` : '',
                    header.date ? `diajukan ${app.formatIndonesianDate(header.date, { weekday: true })}` : '',
                ].filter(Boolean).join(' · ') || 'Rencana studi satu semester.';
                document.title = `KRS ${semesterTitle()}`;

                if (detail.navigation?.is_latest) {
                    chips.push(app.renderBadge('KRS terbaru', 'brand'));
                }

                if (!isRegular()) {
                    chips.push(app.renderBadge(state.detail.header?.semester === 'T' ? 'Konversi' : 'Non-reguler', 'secondary'));
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
                            <div class="font-semibold text-ink">KRS semester T berisi mata kuliah konversi</div>
                            <p class="mt-1">Mata kuliah ini diakui dari studi sebelumnya, misalnya D3 ke S1 lanjutan. SKS-nya dihitung sebagai SKS lulus dan mengurangi sisa SKS, tetapi bukan beban studi semester ini dan tidak masuk IPK karena portal tidak mencantumkan nilainya.</p>
                        </div>
                    `);
                }

                if (status?.code === 'pending_dpa') {
                    alerts.push(`
                        <div class="${app.alertTone('warning')}">
                            <div class="font-semibold">KRS ini belum divalidasi DPA</div>
                            <p class="mt-1">Nilai semester ini tidak akan tampil sampai DPA memvalidasi KRS. <a class="mycic-link" href="${esc(krsUrl)}">Lihat status & hubungi DPA</a></p>
                        </div>
                    `);
                } else if (!detail.navigation?.is_latest && isRegular()) {
                    alerts.push(`<p class="text-caption text-ink-subtle">KRS semester lalu. Status validasi hanya ditampilkan untuk KRS terbaru, karena portal menulis teks status yang sama di semua KRS.</p>`);
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
                        <section class="mycic-card" aria-labelledby="krs-show-courses-title">
                            <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-show-courses-title">Mata kuliah</h2></div>
                            <div class="mycic-card-body">${app.renderEmptyState('KRS ini tidak memuat mata kuliah.', 'secondary', 'Kalau seharusnya ada, cek langsung di menu Perwalian portal kampus.')}</div>
                        </section>
                    `;

                    return;
                }

                hosts.courses.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-show-courses-title">
                        <div class="mycic-card-header flex flex-wrap items-center justify-between gap-2">
                            <h2 class="mycic-card-title" id="krs-show-courses-title">Mata kuliah</h2>
                            ${countsAsLoad ? `<span class="mycic-badge-brand tabular-nums">${esc(totals.courses ?? courses.length)} MK · ${esc(totals.sks ?? '–')} SKS</span>` : `<span class="mycic-badge-neutral">${esc(courses.length)} MK · bukan beban studi</span>`}
                        </div>
                        <ul class="flex flex-col px-4 md:px-5" aria-label="Mata kuliah di KRS ini">
                            ${courses.map((course) => {
                                const grade = byCode.get(String(course.code || '').toUpperCase());
                                const chips = [];

                                if (course.is_retake) {
                                    chips.push('<span class="mycic-badge-warning">Ulang</span>');
                                } else if (course.status) {
                                    chips.push(`<span class="mycic-badge-neutral">${esc(course.status)}</span>`);
                                }

                                if (Number.isInteger(course.semester) && course.semester !== semesterNumber) {
                                    chips.push(`<span class="mycic-badge-neutral">MK smt ${esc(course.semester)}</span>`);
                                }

                                const letter = grade?.is_graded ? grade.letter : null;
                                const tone = letter ? letterTone(letter) : 'neutral';

                                return `
                                    <li class="mycic-list-row items-center">
                                        <div class="min-w-0 flex-1">
                                            <div class="font-mono text-caption text-ink-subtle">${esc(course.code || '–')}</div>
                                            <div class="text-sm font-semibold text-ink">${esc(course.name || 'Mata kuliah')}</div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                <span class="text-caption tabular-nums text-ink-muted">${course.sks === null || course.sks === undefined ? '–' : esc(course.sks)} SKS</span>
                                                ${chips.join('')}
                                            </div>
                                        </div>
                                        ${showLetters ? `
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold ${letterClasses[tone]}" title="${letter ? `Nilai ${esc(letter)}` : 'Nilai belum keluar'}">
                                                <span aria-hidden="true">${letter ? esc(letter) : '–'}</span>
                                                <span class="sr-only">${letter ? `Nilai ${esc(letter)}` : 'Nilai belum keluar'}</span>
                                            </span>
                                        ` : ''}
                                    </li>
                                `;
                            }).join('')}
                        </ul>
                        ${totals.consistent === false ? '<p class="border-t border-border-light px-4 py-3 text-caption text-warning md:px-5">Total di portal berbeda dengan jumlah daftar MK. Cek lagi di portal kampus.</p>' : ''}
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
                        <div class="grid grid-cols-3 gap-3">
                            <div><div class="mycic-metric-label">IPS</div><div class="text-2xl font-bold tabular-nums text-ink">${typeof khs.ips === 'number' ? esc(khs.ips.toFixed(2)) : '–'}</div></div>
                            <div><div class="mycic-metric-label">SKS</div><div class="text-2xl font-bold tabular-nums text-ink">${esc(khs.total_sks ?? '–')}</div></div>
                            <div><div class="mycic-metric-label">Mutu</div><div class="text-2xl font-bold tabular-nums text-ink">${esc(khs.total_quality_points ?? '–')}</div></div>
                        </div>
                        ${khs.status === 'partial' ? `<p class="text-caption text-warning">Baru ${esc(khs.graded_count)} dari ${esc(khs.course_count)} nilai yang keluar.</p>` : ''}
                    `;
                } else {
                    const reason = status?.code === 'pending_dpa'
                        ? 'Nilai baru tampil setelah DPA memvalidasi KRS dan dosen mengisi nilai.'
                        : 'Nilai muncul setelah dosen mengisi nilai di SIPANDAI.';

                    result = `<p class="text-sm font-semibold text-ink">Nilai semester ini belum keluar</p><p class="text-caption text-ink-subtle">${esc(reason)}</p>`;
                }

                hosts.aside.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-show-result-title">
                        <div class="mycic-card-header"><h2 class="mycic-card-title" id="krs-show-result-title">Hasil ${esc(semesterTitle())}</h2></div>
                        <div class="mycic-card-body flex flex-col gap-3">
                            ${result}
                            ${retakes ? `<p class="text-caption text-ink-subtle">${esc(retakes)} MK di KRS ini adalah mata kuliah ulang.</p>` : ''}
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
                    <a href="/krs/${esc(id)}" class="${app.buttonClass('secondary')} w-full ${align === 'end' ? 'justify-end text-end' : 'justify-start'}" aria-label="KRS semester ${align === 'end' ? 'berikutnya' : 'sebelumnya'}">
                        ${align === 'end' ? `${esc(label)}<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>` : `<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6l6 6" /></svg>${esc(label)}`}
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
                        <p class="mt-1 text-caption">Dibuat MyCIC dari data portal per ${esc(stamp)} · bukan dokumen resmi.</p>
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
                renderFreshness();
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
                        <div class="mycic-card"><div class="mycic-card-body">
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
