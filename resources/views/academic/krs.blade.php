@extends('layouts.app')

@section('title', 'KRS & Perwalian')
@section('page_id', 'krs-index')

@php
    $activeTab = request()->query('tab') === 'riwayat' ? 'riwayat' : 'semester';
    $krsTabs = [
        'semester' => 'Semester ini',
        'riwayat' => 'Riwayat & DPA',
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

            .mycic-sticky-action {
                position: static !important;
                border: 0 !important;
            }
        }
    </style>

    <x-academic-menu active="krs" class="print:hidden" />

    <x-page-header title="KRS & Perwalian" class="print:hidden">
        <x-slot:subtitle id="krs-period-label">Status KRS, periode, dan kontak DPA kamu.</x-slot:subtitle>
        <p class="mt-1 text-caption text-ink-subtle" data-krs-updated></p>
    </x-page-header>

    <div class="hidden print:mb-4 print:block" id="krs-print-header"></div>

    <div class="mb-4 flex gap-1 border-b border-border-light print:hidden" role="tablist" aria-label="Bagian halaman KRS">
        @foreach ($krsTabs as $key => $label)
            <button
                type="button"
                role="tab"
                id="krs-tab-{{ $key }}"
                aria-controls="krs-panel-{{ $key }}"
                aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === $key ? '0' : '-1' }}"
                data-krs-tab="{{ $key }}"
                class="-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-3 text-sm font-semibold text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-selected:border-brand aria-selected:text-brand"
            >{{ $label }}</button>
        @endforeach
    </div>

    <section
        id="krs-panel-semester"
        role="tabpanel"
        aria-labelledby="krs-tab-semester"
        class="flex flex-col gap-4 lg:grid lg:grid-cols-12 lg:items-start lg:gap-5"
        @if ($activeTab !== 'semester') hidden @endif
    >
        <div class="flex min-w-0 flex-col gap-4 lg:col-span-8">
            <div id="krs-status" class="print:hidden">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <span class="sr-only">Memuat status KRS…</span>
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-6 w-2/3"></div>
                        <div class="mycic-skeleton h-14 w-full"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
            </div>

            <div id="krs-courses">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-header">
                        <div class="mycic-skeleton h-5 w-48"></div>
                    </div>
                    <div class="mycic-card-body flex flex-col divide-y divide-border-light">
                        @for ($i = 0; $i < 4; $i++)
                            <x-list-row>
                                <div class="flex flex-col gap-1.5">
                                    <div class="mycic-skeleton h-3 w-16"></div>
                                    <div class="mycic-skeleton h-4 w-40 sm:w-56"></div>
                                </div>
                                <div class="mycic-skeleton h-5 w-14"></div>
                            </x-list-row>
                        @endfor
                    </div>
                </div>
            </div>
        </div>

        <aside class="flex min-w-0 flex-col gap-4 print:hidden lg:sticky lg:top-20 lg:col-span-4" aria-label="Jadwal dan catatan KRS">
            <div id="krs-schedule">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="mycic-skeleton h-4 w-1/2"></div>
                        <div class="mycic-skeleton h-3.5 w-3/4"></div>
                    </div>
                </div>
            </div>
            <div id="krs-notes"></div>
        </aside>
    </section>

    <section
        id="krs-panel-riwayat"
        role="tabpanel"
        aria-labelledby="krs-tab-riwayat"
        class="flex flex-col gap-4 print:hidden lg:grid lg:grid-cols-12 lg:items-start lg:gap-5"
        @if ($activeTab !== 'riwayat') hidden @endif
    >
        <div class="min-w-0 lg:order-2 lg:col-span-4" id="krs-dpa">
            <div class="mycic-card" aria-busy="true">
                <div class="mycic-card-body flex flex-col gap-3">
                    <span class="sr-only">Memuat kontak DPA…</span>
                    <div class="mycic-skeleton h-4 w-1/2"></div>
                    <div class="mycic-skeleton h-11 w-full"></div>
                    <div class="mycic-skeleton h-11 w-full"></div>
                </div>
            </div>
        </div>

        <div class="min-w-0 lg:order-1 lg:col-span-8" id="krs-history">
            <div class="mycic-card" aria-busy="true">
                <div class="mycic-card-body flex flex-col gap-3">
                    <span class="sr-only">Memuat riwayat KRS…</span>
                    @for ($i = 0; $i < 3; $i++)
                        <div class="flex items-center gap-3">
                            <div class="mycic-skeleton h-10 w-10 shrink-0 rounded-full"></div>
                            <div class="flex flex-1 flex-col gap-1.5">
                                <div class="mycic-skeleton h-4 w-1/2"></div>
                                <div class="mycic-skeleton h-3 w-1/3"></div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const portalUrl = @json(config('portal.base_url'));
            const guideUrl = @json(route('more').'#panduan');
            const classUrl = @json(route('class.index'));
            const gradesUrl = @json(route('grades.index'));
            const hosts = {
                status: document.getElementById('krs-status'),
                courses: document.getElementById('krs-courses'),
                schedule: document.getElementById('krs-schedule'),
                notes: document.getElementById('krs-notes'),
                printHeader: document.getElementById('krs-print-header'),
                dpa: document.getElementById('krs-dpa'),
                history: document.getElementById('krs-history'),
                updated: document.querySelector('[data-krs-updated]'),
                period: document.getElementById('krs-period-label'),
            };
            const state = { krs: null, krsMeta: null, advising: null, advisingMeta: null, detail: null, detailRequested: false, renderedDay: null };
            const todayKey = () => new Date().toLocaleDateString('sv-SE');
            const esc = (value) => app.escapeHtml(value);
            const icons = {
                check: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>',
                chevron: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>',
                external: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>',
                print: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>',
                chat: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /></svg>',
            };

            // Which KRS period matters for each status (same order as PortalKrsResolver).
            const relevantKinds = {
                not_submitted: ['her_registrasi', 'krs', 'perwalian_perbaikan'],
                pending_dpa: ['perwalian_perbaikan', 'krs', 'her_registrasi'],
                validated: ['perwalian_perbaikan', 'krs', 'her_registrasi'],
                unknown: ['her_registrasi', 'krs', 'perwalian_perbaikan'],
            };

            const cachedData = (url) => {
                try {
                    return app.getCachedResponse(url)?.payload?.data ?? null;
                } catch (error) {
                    return null;
                }
            };

            const primeFromCache = (url, render, label) => {
                try {
                    return app.primeCachedView(url, render, label);
                } catch (error) {
                    return false;
                }
            };

            const termLabel = (period) => {
                if (!period) {
                    return '';
                }

                const term = period.term === 'ganjil' ? 'Ganjil' : (period.term === 'genap' ? 'Genap' : '');

                return [period.academic_year ? `T.A ${period.academic_year}` : '', term].filter(Boolean).join(' · ') || period.label || '';
            };

            const shortRange = (start, end) => {
                const from = app.toDate(start);
                const to = app.toDate(end);

                if (from && to && from.getMonth() === to.getMonth() && from.getFullYear() === to.getFullYear()) {
                    return `${from.getDate()}–${app.formatIndonesianDate(end, { month: 'short' })}`;
                }

                return `${app.formatIndonesianDate(start, { month: 'short', year: false })} – ${app.formatIndonesianDate(end, { month: 'short' })}`;
            };

            const renderFreshness = () => {
                const meta = state.krsMeta || state.advisingMeta;

                if (!hosts.updated || !meta) {
                    return;
                }

                const data = state.krs || state.advising || {};
                const ago = app.formatTimeAgo(meta.synced_at || data.extracted_at || meta.cached_at);
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

            const hasContact = (dpa) => Boolean(dpa && ['name', 'phone', 'whatsapp', 'email'].some((key) => dpa[key]));

            const currentDpa = () => {
                const candidates = [state.advising?.dpa, state.krs?.insights?.dpa, state.krs?.dpa];

                return candidates.find(hasContact) || null;
            };

            const student = () => ({ ...(state.advising?.student || {}), ...(state.krs?.student || {}) });

            const greeting = () => {
                const hour = new Date().getHours();

                if (hour < 11) {
                    return 'Selamat pagi';
                }

                if (hour < 15) {
                    return 'Selamat siang';
                }

                return hour < 18 ? 'Selamat sore' : 'Selamat malam';
            };

            const dpaContact = (statusCode) => {
                const dpa = currentDpa();

                if (!dpa) {
                    return null;
                }

                const me = student();
                const identity = [me.name ? `Saya ${me.name}` : 'Saya', me.nim ? `NIM ${me.nim}` : ''].filter(Boolean).join(', ');
                const academicPeriod = state.krs?.academic_period;
                const termWord = academicPeriod?.term === 'ganjil' ? 'Ganjil' : (academicPeriod?.term === 'genap' ? 'Genap' : '');
                const period = academicPeriod?.academic_year
                    ? `T.A ${academicPeriod.academic_year}${termWord ? ` semester ${termWord}` : ''}`
                    : 'semester ini';
                const dpaName = String(dpa.name || '').replace(/[\s.]+$/, '');
                const purpose = {
                    pending_dpa: `Saya sudah mengajukan KRS ${period} dan mohon kesediaan Bapak/Ibu untuk memvalidasinya.`,
                    not_submitted: `Saya ingin berkonsultasi tentang pengisian KRS ${period}.`,
                }[statusCode] || `Saya ingin berkonsultasi tentang KRS ${period}.`;

                return {
                    name: dpa.name || 'Dosen Pembimbing Akademik',
                    role: 'Dosen Pembimbing Akademik (DPA)',
                    email: dpa.email || '',
                    phone: dpa.phone || '',
                    whatsapp: dpa.whatsapp || '',
                    message: `${greeting()} Bapak/Ibu ${dpaName}. ${identity}, mahasiswa bimbingan akademik Bapak/Ibu. ${purpose} Terima kasih.`.replace(/\s+/g, ' ').trim(),
                };
            };

            const latestKrsId = () => state.krs?.insights?.latest_krs_id ?? state.advising?.latest_krs_id ?? null;

            const currentSemesterNumber = () => {
                const number = state.advising?.latest_semester_number;

                return Number.isInteger(number) ? number : null;
            };

            /**
             * Periods with open/upcoming/past recomputed for today, so an old
             * snapshot never shows a countdown that already ran out.
             */
            const livePeriods = () => (state.krs?.insights?.periods || state.krs?.periods || [])
                .filter((period) => period?.start && period?.end)
                .map((period) => {
                    const untilStart = app.daysUntil(period.start);
                    const untilEnd = app.daysUntil(period.end);

                    return {
                        ...period,
                        label: period.label || 'Periode KRS',
                        isOpen: untilStart !== null && untilEnd !== null && untilStart <= 0 && untilEnd >= 0,
                        isUpcoming: untilStart !== null && untilStart > 0,
                        isPast: untilEnd !== null && untilEnd < 0,
                        daysLeft: untilEnd,
                        daysUntilStart: untilStart,
                    };
                });

            const pickPeriods = (statusCode) => {
                const periods = livePeriods();
                const order = relevantKinds[statusCode] || relevantKinds.unknown;
                const open = periods.filter((period) => period.isOpen);
                let active = null;

                for (const kind of order) {
                    const candidates = open.filter((period) => period.kind === kind).sort((left, right) => String(left.end).localeCompare(String(right.end)));

                    if (candidates.length) {
                        active = candidates[0];
                        break;
                    }
                }

                const upcoming = periods.filter((period) => period.isUpcoming).sort((left, right) => String(left.start).localeCompare(String(right.start)))[0] || null;
                const past = periods.filter((period) => period.isPast).sort((left, right) => String(right.end).localeCompare(String(left.end)))[0] || null;

                return { periods, active: active || open[0] || null, upcoming, past };
            };

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
                    done: 'border-success bg-success text-white',
                    current: 'border-brand bg-panel text-brand ring-4 ring-brand/15',
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
                                    <span class="absolute start-3.5 top-8 bottom-1 w-0.5 -translate-x-1/2 rounded-full ${stepState === 'done' ? 'bg-success' : 'bg-border'} group-last:hidden md:start-[calc(50%+1.25rem)] md:top-3.5 md:bottom-auto md:h-0.5 md:w-[calc(100%-1.75rem)] md:translate-x-0" aria-hidden="true"></span>
                                    <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold ${circles[stepState]}" aria-hidden="true">${mark}</span>
                                    <span class="min-w-0 pt-0.5 md:pt-0">
                                        <span class="block text-sm font-semibold ${stepState === 'current' ? 'text-brand-pressed' : 'text-ink'}">${esc(step.label)}</span>
                                        <span class="block text-caption text-ink-subtle">${esc(stateLabels[stepState])}</span>
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
                    const toneText = { danger: 'text-danger', warning: 'text-warning', brand: 'text-brand-pressed', neutral: 'text-ink' }[tone];
                    const panel = tone === 'danger' ? 'rounded-[var(--radius-control)] bg-danger-soft p-3' : app.statusPanelClass(tone);

                    return `
                        <div class="${panel} flex items-center gap-3" data-krs-countdown>
                            <div class="shrink-0 text-center">
                                <div class="text-3xl font-bold leading-none tabular-nums ${toneText}">${days === 0 ? 'H-0' : esc(days)}</div>
                                <div class="mt-1 text-[0.65rem] font-semibold uppercase tracking-wide ${toneText}">${days === 0 ? 'tutup hari ini' : 'hari lagi'}</div>
                            </div>
                            <div class="min-w-0 text-ink">
                                <div class="text-sm font-semibold">${days === 0 ? 'Periode ditutup hari ini' : `${calm ? 'Masih bisa diperbaiki' : `Sisa ${esc(days)} hari`} · tutup ${esc(app.formatIndonesianDate(active.end, { weekday: true }))}`}</div>
                                <div class="mt-0.5 text-caption text-ink-muted">${esc(active.label)} · ${esc(shortRange(active.start, active.end))} · sumber: ${esc(sourceLabel(active))}</div>
                            </div>
                        </div>
                    `;
                }

                if (upcoming) {
                    return `
                        <div class="${app.statusPanelClass('info')} text-ink" data-krs-countdown>
                            <div class="text-sm font-semibold">${esc(upcoming.label)} dibuka ${esc(app.formatRelativeDays(upcoming.start))}</div>
                            <div class="mt-0.5 text-caption text-ink-muted">${esc(app.formatIndonesianDate(upcoming.start, { weekday: true }))} s.d. ${esc(app.formatIndonesianDate(upcoming.end))} · sumber: ${esc(sourceLabel(upcoming))}</div>
                        </div>
                    `;
                }

                if (past) {
                    return `
                        <div class="${app.statusPanelClass('neutral')}" data-krs-countdown>
                            <div class="text-sm font-semibold text-ink">Periode KRS sudah ditutup ${esc(app.formatIndonesianDate(past.end))}</div>
                            <div class="mt-0.5 text-caption">${esc(past.label)} · ${esc(shortRange(past.start, past.end))}</div>
                        </div>
                    `;
                }

                return `
                    <div class="${app.statusPanelClass('neutral')}" data-krs-countdown>
                        <div class="text-sm font-semibold text-ink">Periode KRS belum terbaca dari portal</div>
                        <div class="mt-0.5 text-caption">Cek pengumuman kampus untuk jadwal her-registrasi dan KRS.</div>
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
                                : 'MyCIC belum bisa membaca status KRS dari portal. Buka portal kampus untuk memastikan.',
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
                    <section class="mycic-card" aria-labelledby="krs-status-title">
                        <div class="mycic-card-body flex flex-col gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">${esc(['KRS', termLabel(krs.academic_period)].filter(Boolean).join(' '))}</div>
                                    ${statusBadge(code, Boolean(active))}
                                </div>
                                <h2 class="mt-1 text-title font-bold text-ink" id="krs-status-title">${esc(copy.title)}</h2>
                                <p class="mt-1 text-sm text-ink-muted">${esc(copy.body)}</p>
                            </div>
                            ${renderCountdown(code)}
                            ${krs.found === false ? '' : renderTracker(insights.tracker)}
                            ${!contact && krs.found !== false ? `<p class="text-caption text-ink-subtle">Kontak DPA belum terbaca dari portal. Tanyakan lewat BAAK atau buka <a class="mycic-link" href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer">portal kampus</a>.</p>` : ''}
                            ${actions.length ? `<div class="flex flex-wrap gap-2">${actions.join('')}</div>` : ''}
                        </div>
                    </section>
                `;

                hosts.status.querySelector('[data-krs-contact-dpa]')?.addEventListener('click', () => {
                    app.renderContactSheet(dpaContact(code) || {});
                });
                app.refreshOnlineRequiredState?.();
            };

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
                        <div class="mb-2 text-caption font-semibold uppercase tracking-wide text-ink-subtle">SKS per semester kurikulum</div>
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
                    : (totals.source === 'computed' ? 'Total dihitung MyCIC dari daftar MK.' : '');
                const printable = krs.print_available === true;

                hosts.courses.innerHTML = `
                    <section class="mycic-card" aria-labelledby="krs-courses-title">
                        <div class="mycic-card-header flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <h2 class="mycic-card-title" id="krs-courses-title">Mata kuliah semester ini</h2>
                                ${retakes ? `<p class="text-caption text-ink-subtle">${esc(retakes)} MK diulang</p>` : ''}
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
                                            <div class="text-sm font-semibold text-ink">${esc(course.name || 'Mata kuliah')}</div>
                                            ${chips.length ? `<div class="mt-1 flex flex-wrap gap-1.5">${chips.join('')}</div>` : ''}
                                        </div>
                                        <span class="shrink-0 text-sm font-semibold tabular-nums text-ink">${course.sks === null || course.sks === undefined ? '–' : esc(course.sks)} <span class="text-caption font-normal text-ink-subtle">SKS</span></span>
                                    </li>
                                `;
                            }).join('')}
                        </ul>
                        ${renderComposition(courses)}
                        <div class="mycic-sticky-action md:border-t md:border-border-light md:px-5 md:py-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0 ps-2 md:ps-0">
                                    <div class="text-sm font-bold tabular-nums text-ink">${esc(courseCount)} MK · ${esc(sksTotal)} SKS</div>
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
                        <div class="text-lg font-bold text-ink">Kartu Rencana Studi (KRS) · ${esc(termLabel(krs.academic_period))}</div>
                        <div class="mt-1 text-sm text-ink-muted">${esc([me.name, me.nim, me.program].filter(Boolean).join(' · '))}</div>
                        <div class="mt-2 inline-block rounded border-2 border-ink px-2 py-0.5 text-sm font-bold tracking-wide text-ink">${esc(watermark)}</div>
                        <p class="mt-2 text-caption text-ink-subtle">Dibuat MyCIC dari data portal per ${esc(stamp)} · bukan dokumen resmi. Versi resmi ada di menu Reg & KRS Online portal kampus.</p>
                    </div>
                `;
            };

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

            const renderHeaderPeriod = () => {
                const label = termLabel(state.krs?.academic_period);

                if (hosts.period && label) {
                    hosts.period.textContent = `${label} · status KRS, periode, dan kontak DPA kamu.`;
                }
            };

            const renderAll = () => {
                renderHeaderPeriod();
                renderStatus();
                renderSchedule();
                renderNotes();
                renderCourses();
                renderDpaCard();
                renderHistory();
                renderFreshness();
            };

            const receiveKrs = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.krs = payload.data;
                state.krsMeta = payload.meta || {};
                renderAll();
            };

            const receiveAdvising = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.advising = payload.data;
                state.advisingMeta = payload.meta || {};

                const latestId = latestKrsId();
                const cachedDetail = latestId ? cachedData(`/api/academic/krs/${latestId}`) : null;

                if (cachedDetail?.found) {
                    state.detail = cachedDetail;
                }

                renderAll();
            };

            /**
             * Baru/Ulang only appears on the KRS detail page: read it once in
             * the background, after the page has rendered.
             */
            const loadLatestDetail = async ({ background = false } = {}) => {
                const latestId = latestKrsId();

                if (!latestId || state.detailRequested || (state.detail?.krs_id === latestId) || !state.krs?.courses?.length || !navigator.onLine) {
                    return;
                }

                state.detailRequested = true;

                try {
                    const payload = await app.request(`/api/academic/krs/${latestId}`, { redirectOnAuth: false, timeout: 20000, background });

                    if (payload?.data?.found) {
                        state.detail = payload.data;
                        renderCourses();
                        renderHistory();
                    }
                } catch (error) {
                    // Optional detail: the list stays without Baru/Ulang chips.
                }
            };

            const tabs = Array.from(document.querySelectorAll('[data-krs-tab]'));
            const panels = {
                semester: document.getElementById('krs-panel-semester'),
                riwayat: document.getElementById('krs-panel-riwayat'),
            };

            const selectTab = (name, { focus = false } = {}) => {
                tabs.forEach((tab) => {
                    const active = tab.dataset.krsTab === name;

                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    tab.tabIndex = active ? 0 : -1;

                    if (active && focus) {
                        tab.focus();
                    }
                });

                Object.entries(panels).forEach(([key, panel]) => {
                    panel.hidden = key !== name;
                });

                try {
                    const url = new URL(window.location.href);

                    if (name === 'riwayat') {
                        url.searchParams.set('tab', 'riwayat');
                    } else {
                        url.searchParams.delete('tab');
                    }

                    window.history.replaceState(window.history.state, '', url.toString());
                } catch (error) {
                    // The URL is a convenience only.
                }
            };

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => selectTab(tab.dataset.krsTab));
                tab.addEventListener('keydown', (event) => {
                    const moves = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 };

                    if (!(event.key in moves)) {
                        return;
                    }

                    event.preventDefault();
                    const target = tabs[(moves[event.key] + tabs.length) % tabs.length];

                    selectTab(target.dataset.krsTab, { focus: true });
                });
            });

            primeFromCache('/api/academic/advising', receiveAdvising, 'riwayat perwalian');
            primeFromCache('/api/academic/krs', receiveKrs, 'KRS semester ini');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                if (!(state.krs || state.advising)) {
                    app.mountErrorState(hosts.status, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    app.mountErrorState(hosts.history, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    hosts.courses.innerHTML = '';
                    hosts.schedule.innerHTML = '';
                    hosts.dpa.innerHTML = '';
                }

                return;
            }

            const loadKrs = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/academic/krs', { fresh: force, background });

                    receiveKrs(payload);

                    return payload;
                } catch (error) {
                    if (!state.krs) {
                        app.mountErrorState(hosts.status, 'KRS gagal dimuat.', () => loadPage({ force }), { subtitle: app.describeError(error) });
                        hosts.courses.innerHTML = '';
                        hosts.schedule.innerHTML = '';
                    } else {
                        app.showNotice(`KRS gagal diperbarui. ${app.describeError(error)}`, 'warning');
                    }

                    throw error;
                }
            };

            const loadAdvising = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/academic/advising', { fresh: force, background });

                    receiveAdvising(payload);

                    return payload;
                } catch (error) {
                    if (!state.advising) {
                        app.mountErrorState(hosts.history, 'Riwayat KRS gagal dimuat.', () => loadPage({ force }), { subtitle: app.describeError(error) });

                        if (!state.krs) {
                            hosts.dpa.innerHTML = '';
                        } else {
                            renderDpaCard();
                        }
                    }

                    throw error;
                }
            };

            const loadPage = async ({ force = false, background = false } = {}) => {
                const [krsResult] = await Promise.allSettled([loadKrs({ force, background }), loadAdvising({ force, background })]);

                if (krsResult.status === 'fulfilled' && krsResult.value) {
                    app.markViewSynced('KRS', krsResult.value, { force });
                } else {
                    app.setSyncStatus('KRS gagal dimuat.', 'error');
                }

                void loadLatestDetail({ background });
            };

            app.registerSyncHandler('krs-index', loadPage, { aliases: ['advising-index', 'announcement-index'] });

            // Countdowns count whole days, so they only change when the day does.
            // Re-rendering also rebuilds the action buttons: never do it while the
            // student is using them or while the DPA contact sheet is open.
            const refreshCountdowns = () => {
                if (!state.krs || state.renderedDay === todayKey()) {
                    return;
                }

                const focused = document.activeElement;
                const sheetOpen = Boolean(app.sheetState?.host && !app.sheetState.host.classList.contains('hidden'));

                if (sheetOpen || (focused && (hosts.status.contains(focused) || hosts.schedule.contains(focused)))) {
                    return;
                }

                renderStatus();
                renderSchedule();
            };

            window.setInterval(refreshCountdowns, 60000);

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && state.krs) {
                    refreshCountdowns();
                    renderFreshness();
                }
            });

            await loadPage();
        });
    </script>
@endpush
