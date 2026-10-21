@extends('layouts.app')

@section('title', 'Beranda')
@section('page_id', 'dashboard')

@php
    $metricTiles = [
        ['key' => 'ipk', 'label' => 'IPK', 'href' => route('transcript.index'), 'bar' => false],
        ['key' => 'sks_passed', 'label' => 'SKS lulus', 'href' => route('transcript.index'), 'bar' => true],
        ['key' => 'sks_current', 'label' => 'SKS semester ini', 'href' => route('krs.index'), 'bar' => false],
        ['key' => 'bills', 'label' => 'Tagihan', 'href' => route('finance.index'), 'bar' => false],
    ];
@endphp

@section('content')
    <section class="mycic-hero mb-5" aria-labelledby="dashboard-greeting" data-dashboard-widget="hero">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-white/20 bg-white/15 text-sm font-bold text-white" aria-hidden="true" data-user-avatar>M</span>
            <h1 class="min-w-0" id="dashboard-greeting">
                <span class="block text-xs font-medium text-white/75">Halo,</span>
                <span class="block truncate text-base font-bold text-white sm:text-lg" data-user-name>Mahasiswa</span>
            </h1>
        </div>
        <ul class="mt-4 flex flex-wrap gap-2" id="dashboard-period" aria-label="Periode akademik" aria-busy="true">
            <li class="h-7 w-44 animate-pulse rounded-lg bg-white/15" aria-hidden="true"></li>
            <li class="sr-only">Memuat periode akademik…</li>
        </ul>
    </section>

    {{--
        The DOM follows the §4.2 mobile order (actions, today, assignments,
        metrics, announcements), so focus and reading order match the screen.
        From lg the script below moves the widgets into the 8/4 columns, so
        the main column is read before the aside there too.
    --}}
    <div class="flex flex-col gap-5 lg:grid lg:grid-cols-12 lg:items-start lg:gap-6" data-dashboard-layout>
        <div class="hidden min-w-0 lg:col-span-8 lg:flex lg:flex-col lg:gap-5" data-dashboard-column="main"></div>
        <div class="hidden min-w-0 lg:col-span-4 lg:flex lg:flex-col lg:gap-5" data-dashboard-column="aside"></div>

        <section class="min-w-0" aria-labelledby="dashboard-actions-title" data-dashboard-widget="actions">
            <div class="mb-3 flex min-w-0 flex-wrap items-center gap-2">
                <h2 class="mycic-section-title mb-0" id="dashboard-actions-title">Pusat tindakan</h2>
                <span id="dashboard-actions-count"></span>
            </div>
            <div id="dashboard-actions" aria-busy="true">
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col divide-y divide-border-light" aria-hidden="true">
                        @for ($i = 0; $i < 3; $i++)
                            <div class="flex gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="mycic-skeleton w-1 self-stretch"></div>
                                <div class="flex flex-1 flex-col gap-2">
                                    <div class="mycic-skeleton h-4 w-20"></div>
                                    <div class="mycic-skeleton h-4 w-3/4"></div>
                                    <div class="mycic-skeleton h-3 w-1/2"></div>
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>
                <span class="sr-only">Memuat pusat tindakan…</span>
            </div>
        </section>

        <section class="min-w-0" aria-labelledby="dashboard-today-title" data-dashboard-widget="today">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="mycic-section-title mb-0" id="dashboard-today-title">Hari ini</h2>
                <span class="text-sm text-ink-subtle" id="dashboard-today-date"></span>
            </div>
            <div id="dashboard-today-notice"></div>
            <div id="dashboard-class-summary" aria-busy="true">
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-3" aria-hidden="true">
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-5 w-2/3"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
                <span class="sr-only">Memuat jadwal hari ini…</span>
            </div>
        </section>

        <section class="min-w-0" aria-labelledby="dashboard-assignments-title" data-dashboard-widget="assignments">
            <h2 class="mycic-section-title mb-3" id="dashboard-assignments-title">Tugas</h2>
            <div id="dashboard-pending-assignments" aria-busy="true">
                <div class="mycic-card">
                    <div class="mycic-card-body flex flex-col gap-3" aria-hidden="true">
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-5 w-2/3"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
                <span class="sr-only">Memuat tugas…</span>
            </div>
        </section>

        <section class="min-w-0" aria-labelledby="dashboard-metrics-title" data-dashboard-widget="metrics">
            <h2 class="mycic-section-title mb-3" id="dashboard-metrics-title">Ringkasan studi</h2>
            <div class="grid grid-cols-2 gap-3">
                @foreach ($metricTiles as $tile)
                    <a
                        href="{{ $tile['href'] }}"
                        class="mycic-metric min-w-0 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99]"
                        data-dashboard-metric="{{ $tile['key'] }}"
                        aria-busy="true"
                    >
                        <span class="mycic-metric-label whitespace-normal">{{ $tile['label'] }}</span>
                        <span class="mycic-metric-value break-words" data-metric-value>
                            <span class="mycic-skeleton block h-7 w-16" aria-hidden="true"></span>
                        </span>
                        <span class="mycic-metric-note flex items-center gap-1.5" data-metric-note>Memuat…</span>
                        @if ($tile['bar'])
                            <span class="mt-auto block pt-1" data-metric-bar aria-hidden="true"></span>
                        @endif
                    </a>
                @endforeach
            </div>
            <a
                href="{{ route('skkm.index') }}"
                class="mycic-card mt-3 flex min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99] md:p-5"
                data-dashboard-metric="skkm"
                aria-busy="true"
            >
                <span class="flex items-baseline justify-between gap-3">
                    <span class="mycic-metric-label">Poin SKKM</span>
                    <span class="shrink-0 text-sm font-bold tabular-nums" data-metric-value>
                        <span class="mycic-skeleton block h-4 w-16" aria-hidden="true"></span>
                    </span>
                </span>
                <span class="block" data-metric-bar aria-hidden="true">
                    <span class="mycic-progress-track h-1.5"></span>
                </span>
                <span class="mycic-metric-note flex items-center gap-1.5" data-metric-note>Memuat…</span>
            </a>
        </section>

        <section class="min-w-0" aria-labelledby="dashboard-announcements-title" data-dashboard-widget="announcements">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2 class="mycic-section-title mb-0" id="dashboard-announcements-title">Pengumuman terbaru</h2>
                <a href="{{ route('announcement.index') }}" class="mycic-link inline-flex min-h-11 items-center rounded-md px-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Lihat semua</a>
            </div>
            <div id="dashboard-announcements" aria-busy="true">
                <div class="grid gap-3 sm:grid-cols-2" aria-hidden="true">
                    @for ($i = 0; $i < 2; $i++)
                        <div class="mycic-card flex flex-col gap-2.5 p-4">
                            <div class="mycic-skeleton h-4 w-24"></div>
                            <div class="mycic-skeleton h-4 w-5/6"></div>
                            <div class="mycic-skeleton h-3 w-1/2"></div>
                        </div>
                    @endfor
                </div>
                <span class="sr-only">Memuat pengumuman…</span>
            </div>
        </section>
    </div>
    <script>
        (() => {
            const layout = document.querySelector('[data-dashboard-layout]');

            if (!layout) {
                return;
            }

            const columns = {
                main: layout.querySelector('[data-dashboard-column="main"]'),
                aside: layout.querySelector('[data-dashboard-column="aside"]'),
            };
            const widget = (key) => layout.querySelector(`[data-dashboard-widget="${key}"]`);
            const wide = window.matchMedia('(min-width: 64rem)');
            const arrange = () => {
                if (wide.matches) {
                    ['today', 'assignments', 'announcements'].forEach((key) => columns.main.appendChild(widget(key)));
                    ['actions', 'metrics'].forEach((key) => columns.aside.appendChild(widget(key)));

                    return;
                }

                ['actions', 'today', 'assignments', 'metrics', 'announcements'].forEach((key) => layout.appendChild(widget(key)));
            };

            arrange();
            wide.addEventListener('change', arrange);
        })();
    </script>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const esc = (value) => app.escapeHtml(value);
            const todayIso = () => new Date().toLocaleDateString('sv-SE');

            const ACTION_PREVIEW_LIMIT = 3;
            const ASSIGNMENT_PREVIEW_LIMIT = 3;
            const RECHECK_LIMIT = 3;
            const RECHECK_DELAY_MS = 20000;
            const SECONDARY_REFRESH_AFTER_MS = 10 * 60 * 1000;
            const SECONDARY_REFRESH_COOLDOWN_MS = 60 * 1000;
            // Snapshots behind the hero, the metrics and the action centre: a pull-to-refresh
            // asks the server to rebuild the stale ones in the background (GET ?fresh=1 only
            // forces the main dashboard dataset).
            const SECONDARY_DATASETS = ['portal_context', 'krs', 'advising', 'grades', 'payments', 'skkm', 'announcements', 'exams'];
            const SOURCE_LABELS = {
                portal_context: 'info periode',
                krs: 'KRS',
                advising: 'perwalian',
                grades: 'nilai',
                payments: 'tagihan',
                skkm: 'SKKM',
                announcements: 'pengumuman',
                assignments: 'tugas',
                exams: 'ujian',
                dashboard: 'jadwal',
            };
            const ANNOUNCEMENT_TONES = {
                krs: 'brand',
                pembayaran: 'warning',
                semester_pendek: 'info',
                akademik: 'info',
                surat: 'secondary',
                umum: 'secondary',
            };
            const METRIC_VALUE_TONES = ['text-success', 'text-danger', 'text-warning', 'text-ink-subtle'];
            const METRIC_LONG_VALUE_CLASSES = ['text-lg', 'sm:text-2xl', 'lg:text-lg', 'xl:text-2xl'];
            const ICONS = {
                clock: '<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>',
                check: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>',
                alert: '<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>',
                external: '<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>',
            };

            let loadDashboard = async () => {};
            let loadPendingAssignments = async () => {};
            let loadActionCenter = async () => {};
            let loadAnnouncements = async () => {};
            let latestClassesSummary = null;
            let latestAssignments = [];
            let latestDashboardPayload = null;
            let latestAssignmentsPayload = null;
            let latestActionPayload = null;
            let latestAnnouncementPayload = null;
            let ownActionItemsRequest = null;
            let assignmentsExpanded = false;
            let lastSecondaryRefreshAt = 0;
            let lastActionFetchAt = 0;
            let lastDashboardFetchAt = 0;
            let renderedDay = todayIso();
            const renderSignatures = new Map();
            const recheckTimers = new Map();
            const recheckAttempts = new Map();

            const panelTone = (color) => app.statusPanelClass(color === 'primary' ? 'brand' : color);
            const textTone = (color) => app.textToneClass(color === 'primary' ? 'brand' : color);

            /**
             * True when `value` differs from what this widget last rendered. Background syncs
             * re-run every widget; an unchanged one is left alone so a typed attendance code or
             * a chosen file is never wiped.
             */
            const hasChanged = (key, value) => {
                const signature = JSON.stringify(value ?? null);

                if (renderSignatures.get(key) === signature) {
                    return false;
                }

                renderSignatures.set(key, signature);

                return true;
            };

            /**
             * "KRS", "KRS dan tagihan", "KRS, nilai, dan tagihan".
             */
            const joinSourceLabels = (sources) => {
                const labels = [...new Set((sources || []).map((source) => SOURCE_LABELS[source] || source))];

                if (labels.length <= 1) {
                    return labels.join('');
                }

                if (labels.length === 2) {
                    return labels.join(' dan ');
                }

                return `${labels.slice(0, -1).join(', ')}, dan ${labels[labels.length - 1]}`;
            };

            const studentInfo = () => {
                const fromDashboard = latestDashboardPayload?.data?.user_info;

                if (fromDashboard && (fromDashboard.name || fromDashboard.nama)) {
                    return fromDashboard;
                }

                try {
                    return app.getAuthSnapshot()?.data?.user_info || {};
                } catch (error) {
                    return {};
                }
            };

            /**
             * A snapshot that is still being built is re-read a few times when no realtime
             * connection will announce it.
             */
            const scheduleRecheck = (key, isPending, run) => {
                if (!isPending) {
                    window.clearTimeout(recheckTimers.get(key));
                    recheckTimers.delete(key);
                    recheckAttempts.delete(key);

                    return;
                }

                // One re-read waits at a time; repeated renders of the same data do not stack.
                if (recheckTimers.has(key)) {
                    return;
                }

                const attempt = (recheckAttempts.get(key) || 0) + 1;

                if (attempt > RECHECK_LIMIT) {
                    return;
                }

                recheckAttempts.set(key, attempt);
                recheckTimers.set(key, window.setTimeout(() => {
                    recheckTimers.delete(key);

                    // With realtime, the finished sync re-runs the widget by itself.
                    if (app.realtimeConnected || !navigator.onLine) {
                        return;
                    }

                    void run();
                }, RECHECK_DELAY_MS * attempt));
            };

            const whenIdle = (callback) => {
                if (typeof window.requestIdleCallback === 'function') {
                    window.requestIdleCallback(callback, { timeout: 2000 });
                } else {
                    window.setTimeout(callback, 600);
                }
            };

            // ---- Hero: academic period chip (K1, K2) ----------------------------------------

            const periodHost = document.getElementById('dashboard-period');
            const periodChipClass = 'inline-flex items-center rounded-lg border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold text-white';

            const renderPeriod = (context, pendingSources) => {
                const period = context?.academic_period || null;
                const termLabel = period?.term ? app.capitalize(period.term) : null;
                const periodLabel = [period?.academic_year ? `T.A ${period.academic_year}` : null, termLabel].filter(Boolean).join(' · ');
                const chips = [];

                if (periodLabel) {
                    chips.push({ label: periodLabel, title: '' });
                }

                if (context?.semester_label) {
                    chips.push({
                        label: context.semester_label,
                        title: context.semester_is_estimate ? 'Dihitung dari angkatan, bisa berbeda dengan data portal.' : '',
                    });
                }

                if (!chips.length && context?.summary_label) {
                    chips.push({ label: context.summary_label, title: '' });
                }

                periodHost.removeAttribute('aria-busy');

                if (chips.length) {
                    periodHost.innerHTML = chips.map((chip) => `
                        <li class="${periodChipClass}"${chip.title ? ` title="${esc(chip.title)}"` : ''}>${esc(chip.label)}</li>
                    `).join('');
                    periodHost.classList.remove('hidden');

                    return;
                }

                if (pendingSources.includes('portal_context')) {
                    periodHost.innerHTML = `
                        <li class="h-7 w-44 animate-pulse rounded-lg bg-white/15" aria-hidden="true"></li>
                        <li class="sr-only">Periode akademik sedang diambil dari portal.</li>
                    `;
                    periodHost.classList.remove('hidden');

                    return;
                }

                // Secondary data: hidden rather than shown as an error (F01).
                periodHost.innerHTML = '';
                periodHost.classList.add('hidden');
            };

            // ---- Metric tiles (K11): IPK · SKS lulus · SKS semester ini · Tagihan --------------

            const parsePositiveInt = (value) => {
                const number = Number.parseInt(String(value ?? '').replace(/\D/g, ''), 10);

                return Number.isFinite(number) && number > 0 ? number : null;
            };

            const compactAmount = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 2 });

            const formatPoints = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(Number(value));

            const clampPercent = (value) => Math.max(0, Math.min(100, Number(value) || 0));

            const notReady = (status, { pending, unavailable, failed, unavailableNote = 'Belum terbaca' }) => {
                if (status === 'pending') {
                    return { value: '–', note: 'Sedang diambil…', title: pending, pending: true };
                }

                if (status === 'failed') {
                    return { value: '–', note: 'Gagal dibaca', title: failed };
                }

                return { value: '–', note: unavailableNote, title: unavailable };
            };

            const describeIpk = (metric) => {
                if (metric?.status === 'ready' && Number.isFinite(Number(metric.value)) && metric.value !== null) {
                    return {
                        value: Number(metric.value).toFixed(2),
                        note: metric.source === 'computed' ? 'Dihitung dari KHS' : 'IPK resmi portal',
                        title: metric.predicate_estimate ? `Perkiraan predikat: ${metric.predicate_estimate}` : '',
                    };
                }

                // Fase 0.7: an IPK that is missing is unread, not "no grades yet".
                return notReady(metric?.status, {
                    pending: 'IPK sedang diambil dari portal.',
                    unavailable: 'IPK belum bisa dibaca dari portal.',
                    failed: 'IPK belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeSksPassed = (metric) => {
                if (metric?.status === 'ready' && Number.isInteger(metric.value)) {
                    const target = Number.isInteger(metric.target_sks) ? metric.target_sks : null;
                    const percent = metric.progress_percent !== null && metric.progress_percent !== undefined
                        ? clampPercent(metric.progress_percent)
                        : (target ? clampPercent((metric.value / target) * 100) : null);

                    return {
                        value: String(metric.value),
                        note: target ? `dari ${target} SKS${percent !== null ? ` · ${Math.round(percent)}%` : ''}` : 'SKS terkumpul',
                        title: [
                            Number.isInteger(metric.remaining_sks) ? `Sisa ${metric.remaining_sks} SKS lagi untuk lulus.` : '',
                            metric.conversion_sks > 0 ? `Termasuk ${metric.conversion_sks} SKS konversi.` : '',
                        ].filter(Boolean).join(' '),
                        progress: percent,
                    };
                }

                return notReady(metric?.status, {
                    pending: 'SKS lulus sedang diambil dari portal.',
                    unavailable: 'SKS lulus belum bisa dibaca dari portal.',
                    failed: 'SKS lulus belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeSksCurrent = (metric, data) => {
                if (metric?.status === 'ready' && Number.isInteger(metric.value)) {
                    return {
                        value: String(metric.value),
                        note: Number.isInteger(metric.courses) ? `${metric.courses} MK` : 'SKS di KRS',
                        title: 'Total SKS di KRS semester ini.',
                    };
                }

                // Until the KRS is read, the class list of this semester is the next best source.
                const credits = parsePositiveInt(data?.dashboard_overview?.total_credits);
                const courses = parsePositiveInt(data?.classes_summary?.total_classes ?? data?.dashboard_overview?.total_courses);

                if (credits !== null && metric?.status !== 'ready') {
                    return {
                        value: String(credits),
                        note: `${courses !== null ? `${courses} MK · ` : ''}dari jadwal kelas`,
                        title: 'Dihitung dari daftar kelas karena KRS belum terbaca.',
                    };
                }

                return notReady(metric?.status, {
                    pending: 'KRS sedang diambil dari portal.',
                    unavailable: 'KRS semester ini belum bisa dibaca dari portal.',
                    failed: 'KRS belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeBills = (metric) => {
                if (metric?.status === 'clear') {
                    return { value: 'Lunas', note: 'Tidak ada tagihan aktif', tone: 'text-success', title: '' };
                }

                if (metric?.status === 'outstanding') {
                    const amount = app.parseAmount(metric.total_amount ?? metric.total_amount_label);
                    const fullLabel = metric.total_amount_label || app.formatRupiah(metric.total_amount, { fallback: '' });
                    // "Rp 12,5 jt" fits a half-width tile; the exact amount stays readable.
                    const shortLabel = amount !== null && amount >= 1000000 ? `Rp ${compactAmount.format(amount)}` : fullLabel;

                    return {
                        value: shortLabel || 'Ada',
                        srValue: shortLabel !== fullLabel ? fullLabel : '',
                        note: Number.isInteger(metric.count) && metric.count > 0
                            ? `${metric.count} tagihan belum lunas`
                            : (fullLabel ? 'Belum lunas' : 'Nominal belum terbaca'),
                        tone: 'text-danger',
                        title: fullLabel ? `Total ${fullLabel} belum lunas.` : 'Ada tagihan yang belum lunas.',
                    };
                }

                if (metric?.status === 'unknown') {
                    // An unreadable bill table is never shown as a zero amount (F34).
                    return { value: '–', note: 'Status tidak terbaca', title: 'Status tagihan tidak terbaca dari portal.' };
                }

                return notReady(metric?.status, {
                    pending: 'Tagihan sedang diambil dari portal.',
                    unavailable: 'Status tagihan tidak terbaca dari portal.',
                    failed: 'Tagihan belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeSkkm = (metric) => {
                if (metric?.status === 'ready' && metric.valid_points !== null && metric.valid_points !== undefined) {
                    const required = metric.required_points ?? null;
                    const percent = metric.progress_percent !== null && metric.progress_percent !== undefined
                        ? clampPercent(metric.progress_percent)
                        : (required ? clampPercent((Number(metric.valid_points) / Number(required)) * 100) : null);
                    let note = 'Target poin belum diketahui';

                    if (metric.is_met === true) {
                        note = 'Syarat lulus terpenuhi';
                    } else if (metric.remaining_points !== null && metric.remaining_points !== undefined && Number(metric.remaining_points) > 0) {
                        note = `Kurang ${formatPoints(metric.remaining_points)} poin untuk syarat lulus`;
                    } else if (required) {
                        note = `${Math.round(percent ?? 0)}% dari target`;
                    }

                    return {
                        value: required ? `${formatPoints(metric.valid_points)}/${formatPoints(required)} poin` : `${formatPoints(metric.valid_points)} poin`,
                        note,
                        tone: metric.is_met === true ? 'text-success' : null,
                        title: 'Poin SKKM yang sudah divalidasi.',
                        progress: percent,
                        progressTone: metric.is_met === true ? 'success' : 'brand',
                    };
                }

                return notReady(metric?.status, {
                    pending: 'Poin SKKM sedang diambil dari SIREKA.',
                    unavailable: 'SIREKA tidak bisa diakses, jadi poin SKKM belum terbaca.',
                    unavailableNote: 'SIREKA belum bisa dibaca',
                    failed: 'Poin SKKM belum bisa dibaca. Coba sinkronkan lagi.',
                });
            };

            const renderProgressTrack = (percent, tone = 'brand') => `
                <span class="mycic-progress-track h-1.5">
                    ${percent !== null && percent > 0 ? `<span class="mycic-progress-fill ${app.fillToneClass(tone)}" style="width: ${percent.toFixed(1)}%"></span>` : ''}
                </span>
            `;

            const setMetric = (key, { value = '–', srValue = '', note = '', title = '', tone = null, pending = false, progress = null, progressTone = 'brand' } = {}) => {
                const tile = document.querySelector(`[data-dashboard-metric="${key}"]`);

                if (!tile) {
                    return;
                }

                const valueNode = tile.querySelector('[data-metric-value]');
                const noteNode = tile.querySelector('[data-metric-note]');
                const barNode = tile.querySelector('[data-metric-bar]');
                const text = String(value ?? '–');

                if (text === '–') {
                    valueNode.innerHTML = '<span aria-hidden="true">–</span><span class="sr-only">belum ada data</span>';
                } else if (srValue) {
                    valueNode.innerHTML = `<span aria-hidden="true">${esc(text)}</span><span class="sr-only">${esc(srValue)}</span>`;
                } else {
                    valueNode.textContent = text;
                }
                valueNode.classList.remove(...METRIC_VALUE_TONES);

                if (tone) {
                    valueNode.classList.add(tone);
                }

                // A long value ("Rp 750.000") must fit a half-width tile at 375px and in the lg aside.
                if (valueNode.classList.contains('mycic-metric-value')) {
                    const isLong = text.length > 8;

                    METRIC_LONG_VALUE_CLASSES.forEach((className) => valueNode.classList.toggle(className, isLong));
                }

                noteNode.innerHTML = pending
                    ? `<span class="mycic-spinner h-3 w-3 shrink-0" aria-hidden="true"></span><span>${esc(note)}</span>`
                    : `<span>${esc(note)}</span>`;

                if (barNode) {
                    barNode.innerHTML = renderProgressTrack(progress, progressTone);
                }

                if (title) {
                    tile.title = title;
                } else {
                    tile.removeAttribute('title');
                }

                tile.removeAttribute('aria-busy');
            };

            const renderMetrics = (data) => {
                const metrics = data?.metrics;

                // A device copy from before the metrics existed: keep the skeletons.
                if (!metrics || typeof metrics !== 'object') {
                    return;
                }

                setMetric('ipk', describeIpk(metrics.ipk));
                setMetric('sks_passed', describeSksPassed(metrics.sks_passed));
                setMetric('sks_current', describeSksCurrent(metrics.sks_current, data));
                setMetric('bills', describeBills(metrics.bills));
                setMetric('skkm', describeSkkm(metrics.skkm));
            };

            const markMetricsUnavailable = () => {
                ['ipk', 'sks_passed', 'sks_current', 'bills', 'skkm'].forEach((key) => {
                    const tile = document.querySelector(`[data-dashboard-metric="${key}"]`);

                    if (tile?.hasAttribute('aria-busy')) {
                        setMetric(key, { value: '–', note: 'Gagal dimuat', title: 'Ringkasan belum bisa dimuat. Coba sinkronkan lagi.' });
                    }
                });
            };

            // ---- Pusat tindakan (GET /api/action-items) ---------------------------------------

            const actionsHost = document.getElementById('dashboard-actions');
            const actionsCountHost = document.getElementById('dashboard-actions-count');

            const describeDue = (item) => {
                const days = item.due_at ? app.daysUntil(item.due_at) : (Number.isInteger(item.days_left) ? item.days_left : null);

                if (days === null) {
                    return null;
                }

                const date = item.due_at ? ` · ${app.formatIndonesianDate(item.due_at, { month: 'short', year: false })}` : '';

                if (days < 0) {
                    return { days, label: `Lewat ${Math.abs(days)} hari${date}`, tone: 'text-danger' };
                }

                if (days === 0) {
                    return { days, label: `Hari ini${date}`, tone: 'text-danger' };
                }

                if (days === 1) {
                    return { days, label: `Besok${date}`, tone: 'text-danger' };
                }

                return { days, label: `${days} hari lagi${date}`, tone: days <= 3 ? 'text-warning' : 'text-ink-subtle' };
            };

            const partOfDay = () => {
                const hour = new Date().getHours();

                if (hour < 11) {
                    return 'pagi';
                }

                if (hour < 15) {
                    return 'siang';
                }

                return hour < 18 ? 'sore' : 'malam';
            };

            const dpaMessage = (item) => {
                const student = studentInfo();
                const name = student.name || student.nama || '';
                const nim = student.student_id || student.nim || '';
                const intro = name ? `Saya ${name}${nim ? ` (NIM ${nim})` : ''}, mahasiswa bimbingan Bapak/Ibu.` : 'Saya mahasiswa bimbingan Bapak/Ibu.';
                const request = item.type === 'krs_validation'
                    ? 'Mohon bantuan validasi KRS saya di portal.'
                    : 'Saya ingin berkonsultasi tentang pengisian KRS semester ini.';

                return `Selamat ${partOfDay()}, Bapak/Ibu. ${intro} ${request} Terima kasih.`;
            };

            const hasContact = (contact) => Boolean(contact && (contact.whatsapp || contact.phone || contact.email));

            const renderActionRow = (item) => {
                const tone = app.severityTone(item.severity);
                const due = describeDue(item);
                // The server writes "· 3 hari lagi" into some titles; the live countdown replaces it.
                const title = due
                    ? String(item.title || '').replace(/\s·\s(?:\d+ hari lagi|hari terakhir)$/u, '')
                    : String(item.title || '');
                const href = app.safeHref(item.cta_url);
                const external = Boolean(href) && (item.cta_external === true || app.isExternalHref(href));
                const contact = hasContact(item.contact) ? item.contact : null;
                const ctaVariant = tone === 'danger' ? 'primary' : 'secondary';

                return `
                    <li class="flex gap-3 py-3 first:pt-0 last:pb-0" data-action-item="${esc(item.type || 'item')}">
                        <span class="w-1 shrink-0 self-stretch rounded-full ${app.fillToneClass(tone === 'neutral' ? 'secondary' : tone)}" aria-hidden="true"></span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                ${app.renderSeverityBadge(item.severity)}
                                ${due ? `
                                    <span class="inline-flex items-center gap-1 text-caption font-semibold tabular-nums ${due.tone}">
                                        ${ICONS.clock}<span class="sr-only">Tenggat:</span> ${esc(due.label)}
                                    </span>
                                ` : ''}
                            </div>
                            <h3 class="text-sm font-semibold text-ink">${esc(title || 'Perlu dicek')}</h3>
                            ${item.description ? `<p class="line-clamp-2 text-caption text-ink-subtle">${esc(item.description)}</p>` : ''}
                            ${href || contact ? `
                                <div class="mt-1 flex flex-wrap gap-2">
                                    ${href ? `
                                        <a href="${esc(href)}" class="${app.buttonClass(ctaVariant, { small: true })} min-h-11 sm:min-h-9"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>
                                            ${esc(item.cta_label || 'Buka')}
                                            ${external ? `${ICONS.external}<span class="sr-only">(membuka tab baru)</span>` : ''}
                                        </a>
                                    ` : ''}
                                    ${contact ? app.renderContactButton({
                                        ...contact,
                                        role: 'Dosen Pembimbing Akademik (DPA)',
                                        message: dpaMessage(item),
                                    }, { label: 'Hubungi DPA', className: 'min-h-11 sm:min-h-9' }) : ''}
                                </div>
                            ` : ''}
                        </div>
                    </li>
                `;
            };

            const renderActionNotes = (payload, shownCount) => {
                const meta = payload?.meta || {};
                const pending = Array.isArray(meta.pending_sources) ? meta.pending_sources : [];
                const failed = Array.isArray(meta.failed_sources) ? meta.failed_sources : [];
                const total = Number(payload?.data?.counts?.total);
                const notes = [];

                if (pending.length) {
                    notes.push(`
                        <p class="flex items-start gap-2 text-caption text-ink-subtle" data-action-pending>
                            <span class="mycic-spinner mt-0.5 h-3 w-3 shrink-0" aria-hidden="true"></span>
                            <span>Data ${esc(joinSourceLabels(pending))} sedang diperbarui dari portal. Daftar ini menyesuaikan sendiri setelah selesai.</span>
                        </p>
                    `);
                }

                if (failed.length) {
                    notes.push(`
                        <p class="flex items-start gap-2 text-caption text-ink-muted" data-action-failed>
                            <span class="mt-0.5 shrink-0 text-warning">${ICONS.alert}</span>
                            <span>Data ${esc(joinSourceLabels(failed))} tidak terbaca dari portal, jadi belum masuk daftar ini.</span>
                        </p>
                    `);
                }

                if (Number.isFinite(total) && total > shownCount && shownCount > 0) {
                    notes.push(`<p class="text-caption text-ink-subtle">Menampilkan ${shownCount} dari ${total} hal yang paling mendesak.</p>`);
                }

                if (meta.cache_is_stale) {
                    const updatedAgo = app.formatTimeAgo(meta.generated_at || meta.cached_at);

                    notes.push(`<p class="text-caption text-ink-subtle">Data tersimpan di perangkat${updatedAgo ? `, diperbarui ${esc(updatedAgo)}` : ''}.</p>`);
                }

                return notes.length ? `<div class="mt-3 flex flex-col gap-1.5" role="status">${notes.join('')}</div>` : '';
            };

            const renderActionEmpty = (payload) => {
                const meta = payload?.meta || {};
                const pending = Array.isArray(meta.pending_sources) ? meta.pending_sources : [];
                const complete = meta.is_complete !== false && pending.length === 0;

                if (!complete) {
                    return app.renderEmptyState(
                        'Belum ada yang perlu diurus',
                        'secondary',
                        'Sebagian data masih diambil dari portal, jadi daftar ini bisa bertambah.'
                    );
                }

                return `
                    <div class="mycic-empty" data-action-empty>
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-success-soft text-success">${ICONS.check}</span>
                        <p class="mycic-empty-title">Semua beres</p>
                        <p class="mycic-empty-subtitle">Tidak ada tenggat atau kewajiban yang perlu kamu urus sekarang.</p>
                    </div>
                `;
            };

            const renderActionCount = (items) => {
                const critical = items.filter((item) => app.severityTone(item.severity) === 'danger').length;
                const warning = items.filter((item) => app.severityTone(item.severity) === 'warning').length;

                if (critical) {
                    return app.renderBadge(`${critical} mendesak`, 'danger');
                }

                return warning ? app.renderBadge(`${warning} segera`, 'warning') : '';
            };

            const renderActionCenter = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                latestActionPayload = payload;

                const items = app.extractActionItems(payload);
                const meta = payload.meta || {};

                scheduleRecheck('actions', Array.isArray(meta.pending_sources) && meta.pending_sources.length > 0, () => loadActionCenter());

                if (!hasChanged('actions', {
                    items,
                    counts: payload.data?.counts,
                    pending: meta.pending_sources,
                    failed: meta.failed_sources,
                    complete: meta.is_complete,
                    stale: meta.cache_is_stale === true,
                    day: todayIso(),
                })) {
                    return;
                }

                const preview = items.slice(0, ACTION_PREVIEW_LIMIT);

                actionsHost.removeAttribute('aria-busy');
                actionsCountHost.innerHTML = renderActionCount(items);
                actionsHost.innerHTML = items.length
                    ? `
                        <div class="mycic-card">
                            <div class="mycic-card-body">
                                <ul class="flex flex-col divide-y divide-border-light" aria-label="Tindakan paling mendesak">
                                    ${preview.map(renderActionRow).join('')}
                                </ul>
                                ${renderActionNotes(payload, items.length)}
                                ${items.length > ACTION_PREVIEW_LIMIT ? `
                                    <button type="button" class="${app.buttonClass('ghost', { small: true })} mt-3 w-full min-h-11 sm:min-h-9" data-action-center-all aria-haspopup="dialog">
                                        Lihat semua ${items.length} tindakan
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `
                    : `${renderActionEmpty(payload)}${renderActionNotes(payload, 0)}`;
                actionsHost.querySelector('[data-action-center-all]')?.addEventListener('click', openAllActions);
            };

            const renderActionCenterFromDevice = () => {
                const cached = app.getCachedResponse('/api/action-items');

                if (cached?.payload) {
                    renderActionCenter(cached.payload);
                }
            };

            function openAllActions() {
                const items = app.extractActionItems(latestActionPayload);

                if (!items.length) {
                    return;
                }

                app.openSheet({
                    pretitle: `${items.length} hal perlu diurus`,
                    title: 'Pusat tindakan',
                    body: `
                        <ul class="flex flex-col divide-y divide-border-light" aria-label="Semua tindakan">
                            ${items.map(renderActionRow).join('')}
                        </ul>
                        ${renderActionNotes(latestActionPayload, items.length)}
                    `,
                });
            }

            // The nav badges (PortalApp.loadActionItems) and realtime updates read the same
            // endpoint; whoever fetched it, this card re-renders from the stored response.
            window.addEventListener('portal:action-items', renderActionCenterFromDevice);

            // ---- Pengumuman terbaru (GET /api/announcement) -------------------------------------

            const announcementsHost = document.getElementById('dashboard-announcements');
            let announcementsRendered = false;

            const describePublished = (item) => {
                const days = app.daysUntil(item.published_date);

                if (days === null) {
                    return item.published_label || '';
                }

                if (days === 0) {
                    return 'Hari ini';
                }

                if (days === -1) {
                    return 'Kemarin';
                }

                if (days < 0 && days > -7) {
                    return `${Math.abs(days)} hari lalu`;
                }

                return app.formatIndonesianDate(item.published_date, { month: 'short' });
            };

            const nextDeadline = (item) => (Array.isArray(item.deadlines) ? item.deadlines : [])
                .map((deadline) => ({ ...deadline, days: app.daysUntil(deadline?.date) }))
                .filter((deadline) => deadline.days !== null && deadline.days >= 0)
                .sort((first, second) => first.days - second.days)[0] || null;

            // Same rule as /announcement: unread on this device and published in the last 7 days.
            const readAnnouncementIds = () => {
                try {
                    const stored = JSON.parse(window.localStorage.getItem('mycic-announcement-read') || 'null');

                    return new Set(Array.isArray(stored?.ids) ? stored.ids : []);
                } catch (error) {
                    return new Set();
                }
            };

            const renderAnnouncementCard = (item, readIds = new Set()) => {
                const id = /^[0-9a-f]{40}$/.test(String(item.id || '')) ? item.id : null;
                const href = id ? `/announcement/${id}` : '/announcement';
                const publishedDays = app.daysUntil(item.published_date);
                const isNew = !readIds.has(item.id) && publishedDays !== null && publishedDays <= 0 && publishedDays >= -7;
                const deadline = nextDeadline(item);
                const deadlineLabel = deadline
                    ? `Tenggat ${deadline.days === 0 ? 'hari ini' : (deadline.days === 1 ? 'besok' : `${deadline.days} hari lagi`)}`
                    : '';
                const deadlineTone = deadline ? (deadline.days <= 2 ? 'danger' : (deadline.days <= 7 ? 'warning' : 'info')) : null;
                const meta = [describePublished(item), item.publisher_unit_short || item.publisher_unit].filter(Boolean).join(' · ');

                return `
                    <li class="min-w-0">
                        <a href="${esc(href)}" class="mycic-card flex h-full min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99]">
                            <span class="flex flex-wrap items-center gap-2">
                                ${app.renderBadge(item.category_label || 'Umum', ANNOUNCEMENT_TONES[item.category] || 'secondary')}
                                ${isNew ? app.renderBadge('Baru', 'brand') : ''}
                                ${deadline ? app.renderBadge(deadlineLabel, deadlineTone) : ''}
                            </span>
                            <span class="line-clamp-2 text-sm font-semibold text-ink">${esc(item.title || 'Pengumuman kampus')}</span>
                            ${meta ? `<span class="text-caption text-ink-subtle">${esc(meta)}</span>` : ''}
                            ${item.excerpt ? `<span class="line-clamp-2 text-caption text-ink-muted">${esc(item.excerpt)}</span>` : ''}
                        </a>
                    </li>
                `;
            };

            const renderAnnouncements = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                latestAnnouncementPayload = payload;

                const items = (Array.isArray(payload.data?.items) ? payload.data.items : [])
                    .filter((item) => item && !item.duplicate_of)
                    .slice(0, 2);

                announcementsRendered = true;

                const readIds = readAnnouncementIds();

                if (!hasChanged('announcements', { items, day: todayIso(), read: items.map((item) => readIds.has(item.id)) })) {
                    return;
                }

                announcementsHost.removeAttribute('aria-busy');
                announcementsHost.innerHTML = items.length
                    ? `<ul class="grid gap-3 sm:grid-cols-2">${items.map((item) => renderAnnouncementCard(item, readIds)).join('')}</ul>`
                    : app.renderEmptyState('Belum ada pengumuman.', 'secondary', 'Pengumuman baru dari portal kampus akan muncul di sini.');
            };

            // ---- Jadwal hari ini & absen ---------------------------------------------------------

            const renderTodaySchedule = (classesSummary) => {
                const summaryHost = document.getElementById('dashboard-class-summary');
                const todayClasses = classesSummary.today_classes || [];
                const dayLabel = classesSummary.day || 'Hari ini';

                latestClassesSummary = classesSummary;

                // The snapshot may still describe yesterday while today's is being fetched.
                const snapshotIsToday = !classesSummary.date || classesSummary.date === todayIso();
                const titleNode = document.getElementById('dashboard-today-title');
                const dateNode = document.getElementById('dashboard-today-date');
                const noticeNode = document.getElementById('dashboard-today-notice');
                const longDate = classesSummary.date
                    ? new Date(`${classesSummary.date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' })
                    : dayLabel;

                titleNode.textContent = snapshotIsToday ? 'Hari ini' : `Jadwal ${dayLabel}`;
                dateNode.textContent = snapshotIsToday ? longDate : `${longDate} · data lama`;
                noticeNode.innerHTML = snapshotIsToday ? '' : `
                    <div class="${app.alertTone('info')} mb-3 flex items-center gap-2">
                        <span class="mycic-spinner shrink-0" aria-hidden="true"></span>
                        <span>Jadwal hari ini sedang diambil dari portal kampus. Yang tampil di bawah masih jadwal ${esc(dayLabel)}.</span>
                    </div>
                `;

                // Where the quick QR button in the mobile nav should go.
                const scannable = todayClasses.find((klass) => klass.attendance?.actionable && klass.attendance?.meeting_url);
                app.setQuickQrTarget(
                    scannable ? `${scannable.attendance.meeting_url}&qr=1` : null,
                    todayClasses.length
                        ? 'Absensi untuk kelas hari ini belum dibuka dosen. Coba lagi saat kelas berlangsung.'
                        : `Tidak ada kelas ${dayLabel}, jadi tidak ada absensi yang perlu diisi.`
                );

                if (!hasChanged('today', { date: classesSummary.date, day: classesSummary.day, todayClasses })) {
                    return;
                }

                summaryHost.removeAttribute('aria-busy');

                if (!todayClasses.length) {
                    summaryHost.innerHTML = app.renderEmptyState(`Tidak ada jadwal ${dayLabel}.`, 'secondary', 'Selamat beristirahat.');

                    return;
                }

                // A changed schedule re-renders the forms; keep any code already typed in.
                const typedCodes = new Map();
                summaryHost.querySelectorAll('[data-attendance-card]').forEach((form) => {
                    const typed = form.querySelector('[data-field="kode"]')?.value;

                    if (typed) {
                        typedCodes.set(form.dataset.attendanceKey, typed);
                    }
                });

                summaryHost.innerHTML = todayClasses.map((klass, index) => {
                    const attendance = klass.attendance || {};
                    const targetReliable = attendance.target_reliable !== false;
                    let statusColor = 'secondary';
                    if (attendance.actionable) statusColor = 'primary';
                    else if (attendance.status?.already_marked && targetReliable) statusColor = 'success';
                    else if (attendance.available && targetReliable) statusColor = 'warning';

                    const materialTitle = attendance.today_material?.title;
                    const materialIsToday = attendance.today_material?.is_today !== false;
                    const materialLabel = materialTitle && materialTitle !== 'Materi terbaru belum terdeteksi.' ? materialTitle : null;
                    // Without today's material, "Buka" leads to the class page, not an old meeting.
                    const openUrl = targetReliable ? attendance.meeting_url : (attendance.class_url || attendance.meeting_url);
                    const attendanceKey = attendance.meeting_url || [klass.course_code, klass.class_name, index].filter(Boolean).join('|');

                    return `
                    <div class="mycic-card mb-3">
                        <div class="mycic-card-body">
                            <div class="mb-3 flex flex-wrap items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex flex-wrap items-center gap-2 text-sm text-ink-subtle">
                                        <span class="font-medium">${esc(klass.schedule?.time || '-')}</span>
                                        <span>·</span>
                                        <span class="font-medium uppercase">${esc([klass.course_code, klass.class_name].filter(Boolean).join(' · ') || 'Kelas')}</span>
                                    </div>
                                    <h3 class="mb-1 text-title font-bold text-ink">${esc(klass.course_name || 'Kelas')}</h3>
                                    <div class="text-sm text-ink-muted">
                                        ${esc(klass.lecturer_name || '-')} · ${esc(klass.room || '-')}
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    ${openUrl
                                        ? `<a href="${esc(openUrl)}" class="${app.buttonClass('secondary', { small: true })}">Buka</a>`
                                        : ''}
                                </div>
                            </div>

                            ${(attendance.meeting_number || materialLabel) && (materialIsToday || materialLabel) ? `
                                <div class="mb-3 flex flex-wrap gap-x-3 gap-y-1 text-sm text-ink-subtle">
                                    ${materialIsToday && attendance.meeting_number ? `<span>P${esc(attendance.meeting_number)}</span>` : ''}
                                    ${materialLabel ? `<span class="italic">${materialIsToday ? '' : 'Materi terakhir: '}${esc(materialLabel)}</span>` : ''}
                                </div>
                            ` : ''}

                            <div class="${panelTone(statusColor)}">
                                ${!targetReliable ? `
                                    <div class="text-sm text-ink-subtle">Materi hari ini belum diunggah dosen, jadi absensi belum bisa dibuka dari sini.</div>
                                ` : attendance.actionable ? `
                                    <form class="flex flex-wrap items-center gap-2" data-attendance-card="${index}" data-attendance-key="${esc(attendanceKey)}">
                                        <div class="w-full min-w-0 md:w-auto md:min-w-[12rem] md:flex-1">
                                            <label class="sr-only" for="dashboard-attendance-code-${index}">Kode absen ${esc(klass.course_name || 'kelas ini')}</label>
                                            <input type="text" class="mycic-input bg-panel" id="dashboard-attendance-code-${index}" data-field="kode" placeholder="Kode absen" autocomplete="off" autocapitalize="off" spellcheck="false" required>
                                        </div>
                                        <button type="button" class="${app.buttonClass('secondary')} bg-panel" data-qr-scan>Scan QR</button>
                                        <div class="shrink-0">
                                            <input type="file" class="peer sr-only" id="qr-file-${index}" data-qr-file accept="image/*">
                                            <label class="${app.buttonClass('secondary')} mb-0 cursor-pointer bg-panel peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand" for="qr-file-${index}">Upload QR</label>
                                        </div>
                                        <button type="submit" class="${app.buttonClass('primary')} md:ms-auto" data-online-required>Kirim</button>
                                    </form>
                                ` : attendance.status?.already_marked ? `
                                    <div class="text-sm font-medium ${textTone(statusColor)}">
                                        ${esc(attendance.status?.label || 'Hadir')}${attendance.status?.time ? ` · ${esc(attendance.status.time)}` : ''}
                                    </div>
                                ` : attendance.available ? `
                                    <div class="flex flex-wrap items-center justify-between gap-2 ${textTone(statusColor)}">
                                        <span class="text-sm font-medium">Absensi tersedia</span>
                                        <a href="${esc(attendance.meeting_url || '/class')}" class="text-xs font-bold underline">Buka</a>
                                    </div>
                                ` : `
                                    <div class="text-sm text-ink-subtle">Belum ada absensi.</div>
                                `}
                            </div>
                        </div>
                    </div>
                    `;
                }).join('');

                summaryHost.querySelectorAll('[data-attendance-card]').forEach((form) => {
                    const classIndex = Number(form.dataset.attendanceCard);
                    const klass = Number.isInteger(classIndex) ? todayClasses[classIndex] : null;
                    const attendancePayload = klass?.attendance?.payload || null;
                    const shortcutContext = klass?.attendance?.context || null;
                    const codeInput = form.querySelector('[data-field="kode"]');

                    if (typedCodes.has(form.dataset.attendanceKey)) {
                        codeInput.value = typedCodes.get(form.dataset.attendanceKey);
                    }

                    if (!attendancePayload && !shortcutContext) {
                        return;
                    }

                    app.bindAttendanceQrTools(form, codeInput, {
                        onDetected: () => {
                            app.showNotice('QR terbaca. Mengirim absensi...', 'info');
                            app.requestFormSubmit(form);
                        },
                    });

                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Absen butuh koneksi internet.')) {
                            return;
                        }

                        const kode = codeInput.value.trim();

                        await app.guardFormSubmit(form, () => app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                            try {
                                const body = attendancePayload
                                    ? {
                                        ...attendancePayload,
                                        kode,
                                    }
                                    : {
                                        kode,
                                        shortcut_context: shortcutContext,
                                    };

                                const result = await app.request('/api/attendance', {
                                    method: 'POST',
                                    json: body,
                                });

                                app.showNotice(
                                    result?.data?.status === 'already_marked'
                                        ? 'Kehadiran kamu sudah tercatat untuk pertemuan ini.'
                                        : 'Absensi berhasil dikirim.',
                                    'success'
                                );
                                app.clearCachedResponse('/api/dashboard');
                                app.clearCachedResponse('/api/attendance/today');

                                if (klass?.attendance?.meeting_url) {
                                    app.clearCachedResponse(
                                        klass.attendance.meeting_url.replace('/meeting/', '/api/meeting/')
                                    );
                                }

                                // The server already shows this class as attended, so
                                // re-reading its snapshot is instant (no portal scrape).
                                await loadDashboard();
                            } catch (error) {
                                if (error.isTimeout) {
                                    app.showNotice('Portal lambat merespons. Mengecek status absensi...', 'warning');
                                    await loadDashboard();

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                });

                app.refreshOnlineRequiredState();
            };

            const renderDashboard = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                const data = payload.data || {};
                latestDashboardPayload = payload;

                if (data.user_info && (data.user_info.name || data.user_info.nama || data.user_info.student_id || data.user_info.nim)) {
                    app.hydrateUser(payload);
                }

                const pendingSources = Array.isArray(data.pending_sources) ? data.pending_sources : [];

                renderPeriod(data.portal_context, pendingSources);
                renderMetrics(data);
                renderTodaySchedule(data.classes_summary || {});
                scheduleRecheck('dashboard', pendingSources.length > 0, () => loadDashboard());
            };

            // ---- Tugas ---------------------------------------------------------------------------

            const renderPendingAssignments = (payload) => {
                const assignmentHost = document.getElementById('dashboard-pending-assignments');
                const pendingAssignments = payload?.data?.assignments || [];
                latestAssignments = pendingAssignments;
                latestAssignmentsPayload = payload;

                if (!hasChanged('assignments', { pendingAssignments, day: todayIso() })) {
                    return;
                }

                assignmentHost.removeAttribute('aria-busy');

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = app.renderEmptyState('Tidak ada tugas.', 'secondary');

                    return;
                }

                const describeDeadline = (assignment) => {
                    if (!assignment.deadline_date) {
                        return { label: assignment.deadline || null, tone: 'brand' };
                    }

                    const daysLeft = app.daysUntil(assignment.deadline_date);

                    if (daysLeft === null) {
                        return { label: assignment.deadline || null, tone: 'brand' };
                    }
                    if (daysLeft < 0) {
                        return { label: `Lewat ${Math.abs(daysLeft)} hari · ${assignment.deadline}`, tone: 'danger' };
                    }
                    if (daysLeft === 0) {
                        return { label: `Hari ini · ${assignment.deadline}`, tone: 'danger' };
                    }
                    if (daysLeft === 1) {
                        return { label: `Besok · ${assignment.deadline}`, tone: 'warning' };
                    }
                    if (daysLeft <= 3) {
                        return { label: `${daysLeft} hari lagi · ${assignment.deadline}`, tone: 'warning' };
                    }

                    return { label: `${daysLeft} hari lagi · ${assignment.deadline}`, tone: 'brand' };
                };

                const hiddenCount = Math.max(0, pendingAssignments.length - ASSIGNMENT_PREVIEW_LIMIT);

                assignmentHost.innerHTML = `
                    <div id="dashboard-assignment-list">
                    ${pendingAssignments.map((assignment, index) => {
                        const deadline = describeDeadline(assignment);
                        const statusColor = deadline.tone;
                        const isExtra = index >= ASSIGNMENT_PREVIEW_LIMIT;

                        return `
                        <div class="mycic-card mb-3${isExtra && !assignmentsExpanded ? ' hidden' : ''}"${isExtra ? ' data-assignment-extra' : ''}>
                            <div class="mycic-card-body">
                                <div class="mb-3 flex flex-wrap items-start gap-2">
                                    <div class="me-auto min-w-0">
                                        <div class="mb-1 text-sm font-medium uppercase text-ink-subtle">${esc([assignment.course_code, assignment.lecturer_name].filter(Boolean).join(' · ') || '')}</div>
                                        <h3 class="mb-1 text-title font-bold text-ink">${esc(assignment.course_name || 'Tugas')}</h3>
                                        ${assignment.material_title && assignment.material_title !== assignment.course_name ? `
                                            <p class="mb-0 text-sm text-ink-subtle">${esc(assignment.material_title)}</p>
                                        ` : ''}
                                    </div>
                                    ${deadline.label ? `
                                        <div class="text-sm font-semibold ${textTone(statusColor)}">${esc(deadline.label)}</div>
                                    ` : ''}
                                </div>

                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    ${assignment.assignment_file_url
                                        ? `<a href="${esc(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Lampiran</a>`
                                        : '<span></span>'}
                                    <a href="${esc(assignment.meeting_url)}" class="${app.buttonClass('secondary', { small: true })}">Detail</a>
                                </div>

                                <div class="rounded-[var(--radius-control)] border border-border bg-surface/75 p-3.5">
                                    ${assignment.can_submit_directly ? `
                                        <form class="flex flex-col gap-2 sm:flex-row sm:items-center" data-assignment-card="${index}">
                                            <div class="min-w-0 flex-1">
                                                <label class="sr-only" for="dashboard-assignment-file-${index}">File tugas ${esc(assignment.course_name || '')} (PDF)</label>
                                                <input type="file" class="mycic-input bg-panel" id="dashboard-assignment-file-${index}" data-field="attachment" accept=".pdf" required>
                                            </div>
                                            <button type="submit" class="${app.buttonClass('primary')}" data-online-required>Kirim</button>
                                        </form>
                                    ` : `
                                        <div class="text-sm text-ink-subtle">Buka detail untuk mengumpulkan.</div>
                                    `}
                                </div>
                            </div>
                        </div>
                        `;
                    }).join('')}
                    </div>
                    ${hiddenCount ? `
                        <button type="button" class="${app.buttonClass('secondary')} w-full" data-assignment-toggle aria-controls="dashboard-assignment-list" aria-expanded="${assignmentsExpanded ? 'true' : 'false'}">
                            ${assignmentsExpanded ? 'Tampilkan lebih sedikit' : `Lihat ${hiddenCount} tugas lainnya`}
                        </button>
                    ` : ''}
                `;

                assignmentHost.querySelector('[data-assignment-toggle]')?.addEventListener('click', (event) => {
                    assignmentsExpanded = !assignmentsExpanded;
                    assignmentHost.querySelectorAll('[data-assignment-extra]').forEach((card) => card.classList.toggle('hidden', !assignmentsExpanded));
                    event.currentTarget.setAttribute('aria-expanded', assignmentsExpanded ? 'true' : 'false');
                    event.currentTarget.textContent = assignmentsExpanded ? 'Tampilkan lebih sedikit' : `Lihat ${hiddenCount} tugas lainnya`;
                });

                assignmentHost.querySelectorAll('[data-assignment-card]').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.')) {
                            return;
                        }

                        const assignmentIndex = Number(form.dataset.assignmentCard);
                        const candidate = Number.isInteger(assignmentIndex) ? pendingAssignments[assignmentIndex] : null;
                        const file = form.querySelector('[data-field="attachment"]').files[0];

                        if (!file) {
                            app.showNotice('Pilih file tugas terlebih dahulu.', 'warning');

                            return;
                        }

                        if (!candidate?.assignment_payload) {
                            app.showNotice('Tugas ini perlu dibuka dari detail meeting terlebih dahulu.', 'warning');

                            return;
                        }

                        const formData = new FormData();
                        formData.append('courses_id', candidate.assignment_payload.courses_id);
                        formData.append('id', candidate.assignment_payload.id);
                        formData.append('pertemuan', candidate.assignment_payload.pertemuan);
                        formData.append('attachment', file);

                        await app.guardFormSubmit(form, () => app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                const operationId = result?.data?.operation_id;

                                if (operationId) {
                                    // Keep the form locked until the portal has the file;
                                    // on completion the list re-renders without this task.
                                    await app.trackOperation(operationId, 'Upload tugas');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                    await loadPendingAssignments();
                                }
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                });

                app.refreshOnlineRequiredState();
            };

            // ---- Background refresh of the secondary snapshots -----------------------------------

            const secondarySourceStates = () => {
                const states = {
                    ...(latestDashboardPayload?.data?.metrics?.sources || {}),
                    ...(latestActionPayload?.meta?.sources || {}),
                };
                const context = latestDashboardPayload?.data?.portal_context;
                const announcementMeta = latestAnnouncementPayload?.meta;

                if (!states.portal_context && context?.extracted_at) {
                    states.portal_context = { status: 'ready', synced_at: context.extracted_at };
                }

                if (!states.announcements && announcementMeta?.synced_at && announcementMeta.data_origin !== 'device_cache') {
                    states.announcements = { status: announcementMeta.status === 'syncing' ? 'syncing' : 'ready', synced_at: announcementMeta.synced_at };
                }

                return states;
            };

            const staleSecondaryDatasets = () => {
                const states = secondarySourceStates();

                return app.filterRefreshableDomains(SECONDARY_DATASETS.filter((dataset) => {
                    const state = states[dataset];

                    if (!state) {
                        return true;
                    }

                    // Already being built or refreshed in the background.
                    if (state.status === 'syncing' || state.status === 'pending') {
                        return false;
                    }

                    const syncedAt = app.toDate(state.synced_at)?.getTime();

                    return !syncedAt || Date.now() - syncedAt > SECONDARY_REFRESH_AFTER_MS;
                }));
            };

            /**
             * Pull-to-refresh: rebuild the stale snapshots behind the hero, metrics, action
             * centre and announcements in one background job; the realtime sync re-renders them.
             * At most once a minute, since it shares the portal action rate limit.
             */
            const queueSecondaryRefresh = async () => {
                if (!navigator.onLine || Date.now() - lastSecondaryRefreshAt < SECONDARY_REFRESH_COOLDOWN_MS) {
                    return;
                }

                const domains = staleSecondaryDatasets();

                if (!domains.length) {
                    return;
                }

                lastSecondaryRefreshAt = Date.now();

                try {
                    await app.request('/api/refresh', {
                        method: 'POST',
                        json: { domains },
                    });
                } catch (error) {
                    // The snapshots on screen stay usable; the next pull tries again.
                    lastSecondaryRefreshAt = 0;
                }
            };

            // ---- Time-sensitive labels (countdowns, "Hari ini") ----------------------------------

            const refreshForNewDay = () => {
                if (todayIso() === renderedDay) {
                    return false;
                }

                renderedDay = todayIso();
                renderSignatures.clear();
                renderActionCenter(latestActionPayload);
                renderAnnouncements(latestAnnouncementPayload);
                renderDashboard(latestDashboardPayload);

                if (latestAssignmentsPayload) {
                    renderPendingAssignments(latestAssignmentsPayload);
                }

                void Promise.allSettled([loadDashboard(), loadPendingAssignments(), loadActionCenter()]);

                return true;
            };

            window.setInterval(refreshForNewDay, 60000);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState !== 'visible' || refreshForNewDay() || !navigator.onLine) {
                    return;
                }

                // Back after a while: re-read the snapshots (never a forced portal scrape).
                if (Date.now() - lastActionFetchAt > 5 * 60 * 1000) {
                    void loadActionCenter();
                }

                if (Date.now() - lastDashboardFetchAt > 10 * 60 * 1000) {
                    void loadDashboard();
                }
            });

            // ---- Load ----------------------------------------------------------------------------

            app.primeCachedView('/api/announcement', renderAnnouncements, 'pengumuman');
            app.primeCachedView('/api/action-items', renderActionCenter, 'pusat tindakan');
            app.primeCachedView('/api/assignment/pending', renderPendingAssignments, 'tugas belum dikumpulkan');
            app.primeCachedView('/api/dashboard', renderDashboard, 'ringkasan beranda');

            const ready = await app.ensureAuthenticated();
            let dashboardOverviewHealthy = false;

            if (!ready) {
                return;
            }

            loadDashboard = async ({ force = false } = {}) => {
                lastDashboardFetchAt = Date.now();

                try {
                    const payload = await app.request('/api/dashboard', { fresh: force });
                    renderDashboard(payload);
                    dashboardOverviewHealthy = true;
                    app.markViewSynced('Beranda', payload, { force });
                } catch (error) {
                    dashboardOverviewHealthy = false;
                    app.setSyncStatus('Gagal memuat beranda.', 'error');
                    if (periodHost.hasAttribute('aria-busy')) {
                        renderPeriod(null, []);
                    }

                    markMetricsUnavailable();

                    const summaryHost = document.getElementById('dashboard-class-summary');
                    const shownSchedule = latestClassesSummary && (!latestClassesSummary.date || latestClassesSummary.date === todayIso());

                    if (shownSchedule) {
                        // Today's schedule from the device stays usable; say it may be outdated.
                        const noticeNode = document.getElementById('dashboard-today-notice');
                        noticeNode.innerHTML = `
                            <div class="${app.alertTone('warning')} mb-3 flex flex-wrap items-center justify-between gap-2" role="status">
                                <span>Jadwal belum bisa diperbarui. Yang tampil adalah data tersimpan.</span>
                                <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-dashboard-retry>Coba lagi</button>
                            </div>
                        `;
                        noticeNode.querySelector('[data-dashboard-retry]')?.addEventListener('click', () => {
                            noticeNode.innerHTML = '';
                            void loadDashboard({ force });
                        });
                    } else {
                        renderSignatures.delete('today');
                        app.mountErrorState(
                            summaryHost,
                            'Jadwal hari ini gagal dimuat.',
                            () => loadDashboard({ force }),
                            { subtitle: app.describeError(error) }
                        );
                    }
                }

                if (force) {
                    void queueSecondaryRefresh();
                }
            };

            loadPendingAssignments = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/assignment/pending', { fresh: force });
                    renderPendingAssignments(payload);

                    // The action centre only reuses the assignments snapshot; this read just built it.
                    if (latestActionPayload?.meta?.sources?.assignments?.status === 'missing') {
                        void loadActionCenter();
                    }

                    if (dashboardOverviewHealthy) {
                        app.markViewSynced('Tugas', payload, { force });
                    }
                } catch (error) {
                    app.setSyncStatus('Gagal memuat tugas.', 'error');
                    renderSignatures.delete('assignments');
                    app.mountErrorState(
                        document.getElementById('dashboard-pending-assignments'),
                        'Daftar tugas gagal dimuat.',
                        () => loadPendingAssignments({ force }),
                        { subtitle: app.describeError(error) }
                    );
                }
            };

            loadActionCenter = async ({ force = false } = {}) => {
                const shared = app.actionItemsRequest;

                // A realtime update (or the nav badges) already started a read: reuse it.
                if (!force && shared && shared !== ownActionItemsRequest) {
                    ownActionItemsRequest = shared;
                    await shared;

                    const cached = app.getCachedResponse('/api/action-items');

                    if (cached?.payload) {
                        renderActionCenter(cached.payload);

                        return;
                    }

                    // That read failed and left nothing behind: ask once more so the card can say why.
                }

                lastActionFetchAt = Date.now();

                // Built from snapshots only, so this never waits on the portal.
                const request = app.request('/api/action-items', { fresh: force, timeout: 15000 });
                ownActionItemsRequest = request
                    .then((payload) => app.extractActionItems(payload))
                    .catch(() => app.actionItems || []);
                // The nav badges read this same response instead of asking again.
                app.actionItemsRequest = ownActionItemsRequest;

                try {
                    const payload = await request;

                    if (!payload) {
                        return;
                    }

                    renderActionCenter(payload);
                    app.receiveActionItems(app.extractActionItems(payload));
                } catch (error) {
                    if (latestActionPayload) {
                        // Keep the list on screen; the sync strip says the refresh failed.
                        app.setSyncStatus('Pusat tindakan belum bisa diperbarui.', 'warning');

                        return;
                    }

                    renderSignatures.delete('actions');
                    actionsHost.removeAttribute('aria-busy');
                    app.mountErrorState(
                        actionsHost,
                        'Pusat tindakan gagal dimuat.',
                        () => loadActionCenter({ force }),
                        { subtitle: app.describeError(error) }
                    );
                }
            };

            loadAnnouncements = async ({ force = false } = {}) => {
                try {
                    // A snapshot read: pull-to-refresh rebuilds the feed in the background
                    // (queueSecondaryRefresh) instead of scraping three portal pages here.
                    const payload = await app.request('/api/announcement', { fresh: false });

                    renderAnnouncements(payload);
                } catch (error) {
                    if (announcementsRendered) {
                        return;
                    }

                    renderSignatures.delete('announcements');
                    announcementsHost.removeAttribute('aria-busy');
                    app.mountErrorState(
                        announcementsHost,
                        'Pengumuman gagal dimuat.',
                        () => loadAnnouncements({ force }),
                        { subtitle: app.describeError(error) }
                    );
                }
            };

            app.registerSyncHandler('dashboard-overview', loadDashboard, { aliases: ['portal-context'] });
            app.registerSyncHandler('dashboard-pending-assignments', loadPendingAssignments);
            app.registerSyncHandler('dashboard-action-items', loadActionCenter);
            app.registerSyncHandler('dashboard-announcements', loadAnnouncements, { aliases: ['announcement-index'] });

            // The announcement card is secondary: it loads once the first paint is done.
            const announcementsLoaded = new Promise((resolve) => {
                whenIdle(() => resolve(loadAnnouncements()));
            });

            await Promise.allSettled([
                loadDashboard(),
                loadPendingAssignments(),
                loadActionCenter(),
                announcementsLoaded,
            ]);
        });
    </script>
@endpush
