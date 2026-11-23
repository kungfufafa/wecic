@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@php
    $portalFinanceUrl = \App\Services\PortalEndpointRegistry::url('student_finance');
@endphp

@section('content')
    <x-page-hero title="Keuangan" />

    <div class="flex flex-col gap-6" id="finance-page" data-portal-finance-url="{{ $portalFinanceUrl }}">
        <x-stat-strip class="grid-cols-2 sm:grid-cols-3" aria-label="Ringkasan riwayat pembayaran" role="group">
            <x-metric label="Terbayar" class="col-span-2 sm:col-span-1">
                <x-slot:value class="truncate max-sm:text-lg" data-finance-metric="total">
                    <x-skeleton class="h-7 w-24" />
                </x-slot:value>
            </x-metric>
            <x-metric label="Transaksi">
                <x-slot:value class="max-sm:text-lg" data-finance-metric="count">
                    <x-skeleton class="h-7 w-12" />
                </x-slot:value>
            </x-metric>
            <x-metric label="Pembayaran terakhir">
                <x-slot:value class="truncate max-sm:text-lg" data-finance-metric="latest">
                    <x-skeleton class="h-7 w-20" />
                </x-slot:value>
            </x-metric>
        </x-stat-strip>

        <div class="grid items-start gap-6 lg:grid-cols-12">
            <section id="tagihan" class="scroll-mt-20 lg:col-span-5 xl:col-span-4" aria-labelledby="finance-bills-title">
                <x-card class="overflow-hidden">
                    <x-card.header>
                        <div class="min-w-0">
                            <x-card.title id="finance-bills-title">Status tagihan</x-card.title>
                        </div>
                    </x-card.header>
                    <x-card.body class="flex flex-col gap-4" id="finance-bills" aria-busy="true">
                        <span class="sr-only">Memuat status tagihan…</span>
                        <x-skeleton class="h-3.5 w-28" />
                        <x-skeleton class="h-9 w-44" />
                        <x-skeleton class="h-4 w-full" />
                        <x-skeleton class="h-4 w-2/3" />
                    </x-card.body>
                </x-card>
            </section>

            <section id="tahap-ukt" class="scroll-mt-20 lg:col-span-7 xl:col-span-8" aria-labelledby="finance-stages-title">
                <x-card>
                    <x-card.header>
                        <x-card.title id="finance-stages-title">Progres UKT</x-card.title>
                    </x-card.header>
                    <x-card.body class="flex flex-col gap-4" id="finance-stages" aria-busy="true">
                        <span class="sr-only">Memuat tahap UKT…</span>
                        <x-skeleton class="h-4 w-40" />
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @for ($i = 0; $i < 4; $i++)
                                <div class="flex flex-col gap-2">
                                    <x-skeleton class="h-1 w-full rounded-full!" />
                                    <x-skeleton class="h-3 w-14" />
                                    <x-skeleton class="h-3 w-10" />
                                </div>
                            @endfor
                        </div>
                    </x-card.body>
                </x-card>
            </section>
        </div>

        <x-card as="section" aria-labelledby="finance-history-title">
            <x-card.header class="flex-col items-stretch! sm:flex-row sm:items-center!">
                <div class="min-w-0">
                    <x-card.title id="finance-history-title">Riwayat pembayaran</x-card.title>
                </div>
                <div class="relative flex w-full items-center sm:w-64">
                    <label for="finance-search" class="sr-only">Cari transaksi</label>
                    <span class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle">
                        <x-icon name="search" size="sm" />
                    </span>
                    <x-input
                        icon
                        type="search"
                        id="finance-search"
                        placeholder="Cari transaksi"
                        autocomplete="off"
                        aria-describedby="finance-search-status"
                        disabled
                    />
                </div>
            </x-card.header>
            <p class="sr-only" aria-live="polite" id="finance-search-status"></p>

            <div id="finance-payments" aria-busy="true">
                <div class="divide-y divide-border-light">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <x-skeleton class="h-4 w-36 sm:w-48" />
                                <x-skeleton class="h-3 w-24" />
                            </div>
                            <x-skeleton class="h-5 w-24" />
                        </div>
                    @endfor
                </div>
            </div>
        </x-card>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const page = document.getElementById('finance-page');
            const billsHost = document.getElementById('finance-bills');
            const stagesHost = document.getElementById('finance-stages');
            const container = document.getElementById('finance-payments');
            const searchInput = document.getElementById('finance-search');
            const searchStatus = document.getElementById('finance-search-status');
            const portalFinanceUrl = app.safeHref(page.dataset.portalFinanceUrl) || '#';

            let allPayments = [];
            let currentFilter = '';
            let latestSummary = null;
            let latestPayments = null;
            let summaryRendered = false;
            let paymentsRendered = false;

            const STAGE_STATUS = {
                paid: { label: 'Lunas', text: app.ui.text.success },
                unpaid: { label: 'Belum lunas', text: app.ui.text.danger },
                upcoming: { label: 'Akan datang', text: app.ui.text.muted },
                unknown: { label: 'Belum pasti', text: app.ui.text.subtle },
            };
            const REASONS = {
                in_history: 'tercatat di histori portal',
                in_bills: 'tercantum di tagihan aktif',
                future_year: 'belum jatuh tempo',
                not_recorded: 'tidak tercatat di histori portal',
                deadline_ahead: 'belum jatuh tempo',
                deadline_passed_without_bill: 'tenggat lewat, tapi tidak ada tagihan',
                no_deadline: 'belum diketahui dari portal',
            };
            const REASON_SHORT = {
                not_recorded: 'Tidak tercatat',
                future_year: 'Belum jatuh tempo',
                deadline_passed_without_bill: 'Tidak ada tagihan',
                no_deadline: 'Tenggat belum ada',
            };

            const shortDate = (value) => app.formatIndonesianDate(value, { month: 'short', year: false });

            const stageLabel = (payment) => (payment?.installment_stage
                ? `Tahap ${payment.installment_stage}${payment.study_year ? ` · tahun ke-${payment.study_year}` : ''}`
                : '');

            const amountLabel = (payment) => payment?.amount_label || app.formatRupiah(payment?.amount);

            const paymentTitle = (payment) => [payment.category, payment.description].filter(Boolean).join(' · ') || 'Pembayaran';

            const isPartial = (payment) => Number(payment?.installment_amount) > 0
                && Number(payment?.amount) > 0
                && Number(payment.amount) < Number(payment.installment_amount);

            const activeDeadline = (summary) => {
                const deadline = summary?.active_deadline;
                const days = deadline?.date ? app.daysUntil(deadline.date) : null;

                return days === null || days < 0 ? null : { ...deadline, days };
            };

            const renderDeadline = (deadline) => {
                if (!deadline) {
                    return '';
                }

                const tone = deadline.days <= 3 ? 'danger' : (deadline.days <= 14 ? 'warning' : 'info');

                return `
                    <div class="${app.alertTone(tone)}" role="status" data-finance-deadline>
                        <p class="font-medium">Batas bayar ${deadline.stage ? `UKT tahap ${app.escapeHtml(deadline.stage)}` : 'kuliah'}: ${app.escapeHtml(app.formatIndonesianDate(deadline.date, { weekday: true }))}</p>
                        <p class="mt-0.5 text-sm text-ink-muted">${app.escapeHtml(app.capitalize(app.formatRelativeDays(deadline.date)))}.</p>
                        ${deadline.text ? `
                            <details class="mt-2 text-xs">
                                <summary class="cursor-pointer font-medium text-ink-muted">Lihat teks dari portal</summary>
                                <p class="mt-1 text-ink-muted">${app.escapeHtml(deadline.text)}</p>
                            </details>
                        ` : ''}
                    </div>
                `;
            };

            const renderLatestPayment = (latest) => {
                if (!latest) {
                    return 'Portal tidak mencantumkan tagihan yang belum dibayar.';
                }

                const date = latest.paid_at ? app.formatIndonesianDate(latest.paid_at) : (latest.paid_at_label || '');
                const stage = stageLabel(latest);

                return `Pembayaran terakhir ${[date, latest.amount_label || app.formatRupiah(latest.amount), stage].filter(Boolean).join(' · ')}.`;
            };

            const renderBills = (summary) => {
                const outstanding = summary?.outstanding_summary || {};
                const status = ['clear', 'outstanding', 'unknown'].includes(outstanding.status) ? outstanding.status : 'unknown';
                const bills = Array.isArray(summary?.outstanding_bills) ? summary.outstanding_bills : [];
                const deadline = activeDeadline(summary);
                const parts = [renderDeadline(deadline)];

                billsHost.removeAttribute('aria-busy');

                if (status === 'unknown') {
                    parts.push(`
                        <div class="${app.alertTone('neutral')}" role="status" data-finance-bill-status="unknown">
                            <p class="font-medium text-ink">Status tagihan tidak terbaca</p>
                            <p class="mt-1 text-sm">Tabel tagihan portal belum terbaca. Cek ulang atau buka portal.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-finance-retry data-online-required>Coba lagi</button>
                                <a href="${app.escapeHtml(portalFinanceUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('ghost', { small: true })}">Buka di portal<span class="sr-only"> (tab baru, perlu masuk lagi)</span></a>
                            </div>
                        </div>
                    `);
                } else if (status === 'clear') {
                    parts.push(`
                        <div role="status" data-finance-bill-status="clear">
                            <p class="flex items-center gap-2 text-sm font-medium text-ink"><span class="size-2 shrink-0 rounded-full bg-success" aria-hidden="true"></span>Tidak ada tagihan aktif</p>
                            <p class="mt-1 text-sm text-ink-muted">${app.escapeHtml(renderLatestPayment(summary.latest_payment))}</p>
                        </div>
                    `);
                } else {
                    const total = Number.isFinite(Number(outstanding.total_amount)) && outstanding.total_amount !== null ? Number(outstanding.total_amount) : null;
                    const totalLabel = outstanding.total_amount_label || app.formatRupiah(total);
                    const count = Number.isInteger(outstanding.count) ? outstanding.count : bills.length;
                    const urgent = deadline !== null && deadline.days <= 7;

                    parts.push(`
                        <div role="status" data-finance-bill-status="outstanding">
                            <p class="text-xs font-medium text-ink-muted">Total tagihan aktif</p>
                            <p class="mt-1 text-3xl font-semibold tracking-tight text-ink tabular-nums">${app.escapeHtml(totalLabel)}</p>
                            <p class="mt-1 text-sm font-medium ${urgent ? app.ui.text.danger : app.ui.text.warning}">${count} tagihan belum lunas</p>
                        </div>
                    `);

                    if (bills.length) {
                        parts.push(`
                            <ul class="flex flex-col" aria-label="Rincian tagihan">
                                ${bills.map((bill) => `
                                    <li class="${app.ui.listRow}">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-ink wrap-break-word">${app.escapeHtml(bill.name || 'Tagihan')}</span>
                                            ${bill.description ? `<span class="mt-0.5 block text-xs text-ink-subtle">${app.escapeHtml(bill.description)}</span>` : ''}
                                        </span>
                                        <span class="shrink-0 text-sm font-medium text-ink tabular-nums">${app.escapeHtml(bill.amount_label || app.formatRupiah(bill.amount))}</span>
                                    </li>
                                `).join('')}
                            </ul>
                        `);
                    }

                    if (outstanding.rows_sum_matches_total === false) {
                        parts.push('<p class="text-xs text-ink-subtle">Jumlah rincian berbeda dengan total di portal. Pakai angka total di atas.</p>');
                    }

                    parts.push(`
                        <div class="flex flex-wrap gap-2">
                            ${total !== null ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-copy-text="${total}" data-copy-label="Nominal">Salin nominal</button>` : ''}
                            <button type="button" class="${app.buttonClass('ghost', { small: true })}" data-finance-howto>Cara bayar</button>
                        </div>
                    `);
                }

                if (summary?.stage1_paid_for_current_year === false) {
                    parts.push(`
                        <p class="${app.alertTone('info')}">
                            UKT tahap 1 belum lunas (syarat her-registrasi KRS).
                            <a href="/krs" class="${app.ui.link}">Cek KRS</a>
                        </p>
                    `);
                }

                billsHost.innerHTML = parts.filter(Boolean).join('');
                app.refreshOnlineRequiredState();
            };

            const stageYears = () => {
                const fromPayments = Array.isArray(latestPayments?.payment_stages) ? latestPayments.payment_stages : [];
                const current = latestSummary?.current_year || null;
                const years = fromPayments
                    .filter((year) => year && Number.isInteger(year.study_year))
                    .map((year) => (current && year.study_year === current.study_year ? current : year));

                if (current && Number.isInteger(current.study_year) && !years.some((year) => year.study_year === current.study_year)) {
                    years.push(current);
                }

                return years.sort((first, second) => Number(Boolean(second.is_current)) - Number(Boolean(first.is_current)) || second.study_year - first.study_year);
            };

            const stageDetail = (stage) => {
                if (stage.status === 'paid') {
                    return stage.paid_at ? shortDate(stage.paid_at) : (stage.paid_at_label || 'Tercatat');
                }

                if (stage.status === 'unpaid') {
                    return stage.bill_amount !== null && stage.bill_amount !== undefined ? app.formatRupiah(stage.bill_amount) : 'Ada di tagihan';
                }

                if (stage.reason === 'deadline_ahead' && stage.deadline) {
                    return `Tempo ${shortDate(stage.deadline)}`;
                }

                return REASON_SHORT[stage.reason] || 'Belum diketahui';
            };

            const stageSentence = (stage) => {
                const status = STAGE_STATUS[stage.status] || STAGE_STATUS.unknown;
                const details = [status.label.toLowerCase()];

                if (stage.status === 'paid' && (stage.paid_at || stage.paid_at_label)) {
                    details.push(`dibayar ${stage.paid_at ? app.formatIndonesianDate(stage.paid_at) : stage.paid_at_label}`);
                } else if (stage.reason && REASONS[stage.reason]) {
                    details.push(REASONS[stage.reason]);
                }

                if (stage.deadline) {
                    details.push(`jatuh tempo ${app.formatIndonesianDate(stage.deadline)}`);
                }

                if (stage.is_partial) {
                    details.push('dibayar sebagian');
                }

                return `Tahap ${stage.stage}: ${details.join(', ')}`;
            };

            const STEP_COLUMNS = {
                1: 'sm:grid-cols-1',
                2: 'sm:grid-cols-2',
                3: 'sm:grid-cols-3',
                4: 'sm:grid-cols-4',
                5: 'sm:grid-cols-5',
                6: 'sm:grid-cols-6',
            };
            const STEP_BAR = {
                paid: app.fillToneClass('success'),
                unpaid: app.fillToneClass('danger'),
                upcoming: 'bg-border',
                unknown: 'bg-border',
            };

            const renderStepper = (year, nextStage) => {
                const stages = (Array.isArray(year.stages) ? year.stages : []).filter((stage) => Number.isInteger(stage?.stage));

                return `
                    <ol class="grid grid-cols-2 gap-x-3 gap-y-5 ${STEP_COLUMNS[stages.length] || STEP_COLUMNS[4]}" aria-label="Tahap UKT tahun ke-${year.study_year}">
                        ${stages.map((stage) => {
                            const status = STAGE_STATUS[stage.status] || STAGE_STATUS.unknown;
                            const isNext = nextStage && nextStage.study_year === year.study_year && nextStage.stage === stage.stage;
                            const bar = isNext && stage.status !== 'paid' && stage.status !== 'unpaid' ? app.ui.fill.brand : (STEP_BAR[stage.status] || STEP_BAR.unknown);

                            return `
                                <li class="flex min-w-0 flex-col gap-2" aria-label="${app.escapeHtml(stageSentence(stage))}" ${isNext ? 'aria-current="step"' : ''} title="${app.escapeHtml(stageSentence(stage))}">
                                    <span class="h-1 rounded-full ${bar}" aria-hidden="true"></span>
                                    <span class="min-w-0" aria-hidden="true">
                                        <span class="block text-xs font-medium text-ink">Tahap ${stage.stage}</span>
                                        <span class="block truncate text-xs ${status.text}">${status.label}${stage.is_partial ? ' · sebagian' : ''}</span>
                                        <span class="mt-0.5 line-clamp-2 max-w-full text-xs text-ink-subtle tabular-nums wrap-break-word">${app.escapeHtml(stageDetail(stage))}</span>
                                    </span>
                                </li>
                            `;
                        }).join('')}
                    </ol>
                `;
            };

            const yearSummary = (year) => {
                const stages = Array.isArray(year.stages) ? year.stages : [];
                const paid = stages.filter((stage) => stage?.status === 'paid').length;
                const unpaid = stages.filter((stage) => stage?.status === 'unpaid').length;
                const unknown = stages.filter((stage) => stage?.status === 'unknown').length;

                return [
                    `${paid} dari ${stages.length} tahap lunas`,
                    unpaid ? `${unpaid} belum lunas` : null,
                    unknown ? `${unknown} belum pasti` : null,
                ].filter(Boolean).join(' · ');
            };

            const renderStages = () => {
                if (!latestSummary && !latestPayments) {
                    return;
                }

                stagesHost.removeAttribute('aria-busy');

                const years = stageYears();

                if (!years.length) {
                    stagesHost.innerHTML = app.renderEmptyState(
                        'Tahap UKT belum terbaca.',
                        'secondary',
                        'Histori dan tagihan di portal belum mencantumkan tahap dan tahun kuliah.',
                        { icon: 'calendar' }
                    );

                    return;
                }

                const nextStage = latestSummary?.next_stage || latestPayments?.payment_stage_summary?.next_stage || null;
                const subtotals = new Map((Array.isArray(latestPayments?.summary?.by_study_year) ? latestPayments.summary.by_study_year : [])
                    .map((row) => [row.study_year, row]));
                const allStages = years.flatMap((year) => (Array.isArray(year.stages) ? year.stages : []));
                const heading = (year) => `Tahun ke-${year.study_year}${year.academic_year ? ` · ${year.academic_year}` : ''}`;
                const subtotal = (year) => subtotals.get(year.study_year)?.total_amount_label || '';
                const [first, ...rest] = years;
                const nextLine = first.is_current && nextStage && nextStage.study_year === first.study_year
                    ? `
                        <p class="text-sm text-ink-muted">
                            <span class="font-medium text-ink">Berikutnya: tahap ${app.escapeHtml(nextStage.stage)}</span>
                            · ${app.escapeHtml((STAGE_STATUS[nextStage.status] || STAGE_STATUS.unknown).label.toLowerCase())}${nextStage.deadline ? `, jatuh tempo ${app.escapeHtml(app.formatIndonesianDate(nextStage.deadline))}` : ''}
                        </p>
                    `
                    : '';

                stagesHost.innerHTML = `
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="flex flex-wrap items-center gap-2 text-sm font-medium leading-snug text-ink">${app.escapeHtml(heading(first))} ${first.is_current ? app.renderBadge('Berjalan', 'brand') : ''}</h3>
                            <span class="text-xs text-ink-subtle">${app.escapeHtml(yearSummary(first))}</span>
                        </div>
                        ${renderStepper(first, nextStage)}
                        ${nextLine}
                        ${subtotal(first) ? `<p class="text-xs text-ink-subtle">Total dibayar tahun ini: <span class="font-medium text-ink tabular-nums">${app.escapeHtml(subtotal(first))}</span></p>` : ''}
                    </div>
                    ${rest.length ? `<div class="divide-y divide-border-light border-t border-border-light">${rest.map((year) => `
                        <details class="group">
                            <summary class="-mx-2 flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-md px-2 py-2.5 transition-colors hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-ink">${app.escapeHtml(heading(year))}</span>
                                    <span class="block text-xs text-ink-subtle">${app.escapeHtml([yearSummary(year), subtotal(year)].filter(Boolean).join(' · '))}</span>
                                </span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-ink-subtle transition-transform group-open:rotate-180" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                            </summary>
                            <div class="pb-3 pt-1">${renderStepper(year, nextStage)}</div>
                        </details>
                    `).join('')}</div>` : ''}
                `;
            };

            const openDetail = (payment) => {
                const amount = amountLabel(payment);
                const partial = isPartial(payment);
                const entries = [
                    { label: 'Tanggal', value: payment.paid_at ? app.formatIndonesianDate(payment.paid_at, { weekday: true }) : payment.paid_at_label },
                    { label: 'Jenis', value: payment.category },
                    { label: 'Keterangan', value: payment.description },
                    { label: 'Tahap', value: stageLabel(payment) },
                    { label: 'Tahun akademik', value: payment.academic_year || payment.raw?.['Tahun Akademik'] },
                    { label: 'Nominal tahap', value: Number(payment.installment_amount) > 0 ? app.formatRupiah(payment.installment_amount) : null },
                    { label: 'Metode', value: payment.payment_channel || payment.bank || payment.raw?.Bank },
                    { label: 'Kwitansi', value: payment.reference || payment.raw?.Kwitansi || payment.raw?.['No. Kwitansi'] },
                    { label: 'Status', value: payment.status },
                ];

                app.openSheet({
                    pretitle: 'Detail pembayaran',
                    title: paymentTitle(payment),
                    body: `
                        <div class="flex flex-col gap-4">
                            <div class="${app.ui.inset} flex flex-col items-center gap-2 text-center">
                                <div class="text-2xl font-semibold tracking-tight text-ink tabular-nums">${app.escapeHtml(amount)}</div>
                                <span class="${partial ? app.ui.badge.warning : app.ui.badge.success}">${partial ? 'Dibayar sebagian' : 'Tercatat di histori portal'}</span>
                            </div>
                            ${app.renderKeyValueList(entries)}
                            <p class="text-xs text-ink-subtle">Bukan kwitansi resmi.</p>
                        </div>
                    `,
                });
            };

            const openHowToPay = () => {
                app.openSheet({
                    pretitle: 'Keuangan',
                    title: 'Cara bayar tagihan',
                    body: `
                        <div class="flex flex-col gap-3 text-sm text-ink-muted">
                            <ol class="flex list-decimal flex-col gap-2 ps-5">
                                <li>Bayar di luar portal sesuai petunjuk Bagian Keuangan.</li>
                                <li>Gunakan <span class="font-medium text-ink">Salin nominal</span> agar jumlah sesuai.</li>
                                <li>Simpan bukti bayar; status terbarui setelah diverifikasi Bagian Keuangan.</li>
                            </ol>
                            <a href="/announcement?kategori=pembayaran" class="${app.ui.sheetAction}">
                                <span class="${app.ui.sheetActionIcon}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a3 3 0 0 1 0 6" /><path d="M10 8v11a1 1 0 0 1 -1 1h-1a1 1 0 0 1 -1 -1v-5" /><path d="M12 8h0l4.524 -3.77a.9 .9 0 0 1 1.476 .692v12.156a.9 .9 0 0 1 -1.476 .692l-4.524 -3.77h-8a1 1 0 0 1 -1 -1v-4a1 1 0 0 1 1 -1h8" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium text-ink">Pengumuman keuangan</span>
                                    <span class="block text-xs text-ink-subtle">Info rekening dan tenggat terbaru dari kampus</span>
                                </span>
                            </a>
                        </div>
                    `,
                });
            };

            billsHost.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (target?.closest('[data-finance-howto]')) {
                    openHowToPay();

                    return;
                }

                if (target?.closest('[data-finance-retry]')) {
                    void loadFinance({ force: true });
                }
            });

            const renderPaymentsList = () => {
                const query = currentFilter.trim().toLowerCase();
                const filtered = allPayments.filter((payment) => {
                    if (!query) {
                        return true;
                    }

                    return [
                        payment.description,
                        payment.category,
                        payment.paid_at_label,
                        payment.payment_channel || payment.bank,
                        payment.reference,
                        payment.status,
                        payment.academic_year,
                        payment.amount_label,
                        stageLabel(payment),
                    ].some((value) => String(value || '').toLowerCase().includes(query));
                });

                container.removeAttribute('aria-busy');
                searchStatus.textContent = query ? `${filtered.length} transaksi cocok` : '';

                if (filtered.length === 0) {
                    container.innerHTML = `
                        <div class="${app.ui.cardBody}">
                            ${app.renderEmptyState(query ? 'Tidak ada hasil.' : 'Belum ada pembayaran tercatat.', 'secondary', query ? 'Coba kata kunci lain.' : '', { icon: query ? 'search' : 'receipt' })}
                        </div>
                    `;

                    return;
                }

                const showChannel = filtered.some((payment) => payment.payment_channel || payment.bank || payment.raw?.Bank);
                const showStatus = filtered.some((payment) => payment.status);
                const statusBadge = (status) => app.renderBadge(status, /lunas|sukses|berhasil|verified/i.test(status) ? 'success' : 'info');

                const cellEmpty = '<span class="text-xs text-ink-subtle">–</span>';

                const tableHtml = `
                    <div class="hidden md:block">
                        <div class="overflow-x-auto">
                            <table class="${app.ui.table}">
                                <caption class="sr-only">Riwayat pembayaran, pilih baris untuk melihat detail</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="${app.ui.th} w-12 text-center!">#</th>
                                        <th scope="col" class="${app.ui.th}">Tanggal</th>
                                        <th scope="col" class="${app.ui.th}">Uraian</th>
                                        <th scope="col" class="${app.ui.th}">Tahap</th>
                                        ${showChannel ? `<th scope="col" class="${app.ui.th}">Metode</th>` : ''}
                                        ${showStatus ? `<th scope="col" class="${app.ui.th}">Status</th>` : ''}
                                        <th scope="col" class="${app.ui.th} text-end!">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${filtered.map((payment, index) => {
                                        const channel = payment.payment_channel || payment.bank || payment.raw?.Bank || '';
                                        const ref = payment.reference || payment.raw?.Kwitansi || '';

                                        return `
                                            <tr class="${app.ui.tr} cursor-pointer" data-payment-row="${index}">
                                                <td class="${app.ui.td} text-center text-ink-subtle tabular-nums">${index + 1}</td>
                                                <td class="${app.ui.td} whitespace-nowrap text-ink-muted tabular-nums">${app.escapeHtml(payment.paid_at_label || '–')}</td>
                                                <td class="${app.ui.td}">
                                                    <button type="button" class="cursor-pointer rounded-sm text-start font-medium text-ink hover:text-brand-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-payment-open="${index}">
                                                        ${app.escapeHtml(paymentTitle(payment))}
                                                        <span class="sr-only">, lihat detail</span>
                                                    </button>
                                                    ${ref ? `<div class="mt-0.5 font-mono text-xs text-ink-subtle">${app.escapeHtml(ref)}</div>` : ''}
                                                </td>
                                                <td class="${app.ui.td} whitespace-nowrap text-ink-muted">${stageLabel(payment) ? app.escapeHtml(stageLabel(payment)) : cellEmpty}${isPartial(payment) ? `<span class="block text-xs ${app.ui.text.warning}">Sebagian</span>` : ''}</td>
                                                ${showChannel ? `<td class="${app.ui.td} text-ink-muted">${channel ? app.escapeHtml(channel) : cellEmpty}</td>` : ''}
                                                ${showStatus ? `<td class="${app.ui.td}">${payment.status ? statusBadge(payment.status) : cellEmpty}</td>` : ''}
                                                <td class="${app.ui.td} whitespace-nowrap text-end font-medium text-ink tabular-nums">${app.escapeHtml(amountLabel(payment))}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                const mobileMeta = (payment) => [
                    payment.paid_at_label || '–',
                    stageLabel(payment) && !/tahap/i.test(paymentTitle(payment)) ? stageLabel(payment) : '',
                    payment.payment_channel || payment.bank || '',
                ].filter(Boolean).join(' · ');

                const mobileBadge = (payment) => {
                    if (isPartial(payment)) {
                        return app.renderBadge('Sebagian', 'warning');
                    }

                    return payment.status ? statusBadge(payment.status) : '';
                };

                const mobileHtml = `
                    <ul class="divide-y divide-border-light md:hidden" data-payment-list>
                        ${filtered.map((payment, index) => `
                            <li>
                                <button type="button" class="flex min-h-11 w-full cursor-pointer items-start justify-between gap-3 px-4 py-3 text-start transition-colors hover:bg-surface/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand active:bg-surface sm:px-5" data-payment-open="${index}">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(paymentTitle(payment))}</span>
                                        <span class="mt-0.5 block text-xs text-ink-muted tabular-nums">${app.escapeHtml(mobileMeta(payment))}</span>
                                    </span>
                                    <span class="flex shrink-0 flex-col items-end gap-1 text-end">
                                        <span class="text-sm font-semibold text-ink tabular-nums">${app.escapeHtml(amountLabel(payment))}</span>
                                        ${mobileBadge(payment)}
                                    </span>
                                </button>
                            </li>
                        `).join('')}
                    </ul>
                `;

                container.innerHTML = tableHtml + mobileHtml;

                container.querySelectorAll('[data-payment-row]').forEach((row) => {
                    row.addEventListener('click', () => openDetail(filtered[Number(row.dataset.paymentRow)]));
                });
                container.querySelectorAll('[data-payment-list] [data-payment-open]').forEach((button) => {
                    button.addEventListener('click', () => openDetail(filtered[Number(button.dataset.paymentOpen)]));
                });
            };

            searchInput?.addEventListener('input', (event) => {
                currentFilter = event.target.value;
                renderPaymentsList();
            });

            const setMetric = (name, value, title = '') => {
                const node = document.querySelector(`[data-finance-metric="${name}"]`);

                if (node) {
                    node.textContent = value;
                    node.title = title;
                }
            };

            const renderSummary = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                latestSummary = data;
                summaryRendered = true;
                renderBills(data);
                renderStages();
            };

            const renderPayments = (payload) => {
                const data = payload?.data;

                if (!data || typeof data !== 'object') {
                    return;
                }

                const summary = data.summary || {};
                const historyAvailable = summary.history_available !== false;
                const unreadable = 'Histori pembayaran tidak terbaca dari portal';

                latestPayments = data;
                paymentsRendered = true;
                allPayments = Array.isArray(data.payment_history) ? data.payment_history : [];

                const total = summary.portal_total_amount ?? summary.total_paid_amount ?? allPayments.reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0);
                const latest = summary.latest_payment;

                setMetric('total', historyAvailable ? app.formatRupiah(total) : '–', historyAvailable ? '' : unreadable);
                setMetric('count', historyAvailable ? String(summary.total_records ?? allPayments.length) : '–', historyAvailable ? '' : unreadable);
                setMetric(
                    'latest',
                    latest?.paid_at ? app.formatIndonesianDate(latest.paid_at, { month: 'short' }) : (summary.latest_payment_label || allPayments[0]?.paid_at_label || '–'),
                    historyAvailable ? '' : unreadable
                );

                if (searchInput) {
                    searchInput.disabled = false;
                }

                if (historyAvailable) {
                    renderPaymentsList();
                } else {
                    container.removeAttribute('aria-busy');
                    container.innerHTML = `
                        <div class="${app.ui.cardBody}">
                            ${app.renderEmptyState('Histori pembayaran tidak terbaca dari portal.', 'secondary', 'Coba sinkronkan lagi nanti. Riwayatmu tidak hilang.', { icon: 'history' })}
                        </div>
                    `;
                }

                renderStages();

                if (!latestSummary) {
                    // Older device copies of the summary may be missing; the payments payload carries the same bill table.
                    renderBills({
                        outstanding_summary: data.outstanding_summary,
                        outstanding_bills: data.outstanding_bills,
                        latest_payment: summary.latest_payment,
                        stage1_paid_for_current_year: data.payment_stage_summary?.stage1_paid_for_current_year,
                    });
                }
            };

            const handleSummaryError = (error, force) => {
                if (summaryRendered || paymentsRendered) {
                    app.setSyncStatus(`Status tagihan belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                    return;
                }

                billsHost.removeAttribute('aria-busy');
                app.mountErrorState(billsHost, 'Status tagihan gagal dimuat.', () => loadFinance({ force }), {
                    subtitle: `${app.describeError(error)} Tagihan juga bisa dicek di Portal kampus.`,
                });
            };

            const handlePaymentsError = (error, force) => {
                if (paymentsRendered) {
                    app.setSyncStatus(`Riwayat pembayaran belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                    return;
                }

                ['total', 'count', 'latest'].forEach((name) => setMetric(name, '–', 'Riwayat pembayaran gagal dimuat'));
                container.removeAttribute('aria-busy');
                app.mountErrorState(container, 'Riwayat pembayaran gagal dimuat.', () => loadFinance({ force }), {
                    subtitle: app.describeError(error),
                });

                if (!summaryRendered) {
                    stagesHost.removeAttribute('aria-busy');
                    stagesHost.innerHTML = app.renderEmptyState('Tahap UKT belum bisa ditampilkan.', 'secondary', 'Muncul lagi setelah data keuangan berhasil dimuat.');
                }
            };

            const loadFinance = async ({ force = false, background = false } = {}) => {
                const [summaryResult, paymentsResult] = await Promise.allSettled([
                    app.request('/api/finance/summary', { fresh: force, background }),
                    app.request('/api/finance/payments', { fresh: force, background }),
                ]);

                if (summaryResult.status === 'fulfilled') {
                    renderSummary(summaryResult.value);
                } else {
                    handleSummaryError(summaryResult.reason, force);
                }

                if (paymentsResult.status === 'fulfilled') {
                    renderPayments(paymentsResult.value);
                } else {
                    handlePaymentsError(paymentsResult.reason, force);
                }

                const synced = [paymentsResult, summaryResult].find((result) => result.status === 'fulfilled' && result.value)?.value;

                if (synced) {
                    app.markViewSynced('Keuangan', synced, { force });
                } else if (summaryResult.status === 'rejected' && paymentsResult.status === 'rejected') {
                    app.setSyncStatus('Data keuangan gagal dimuat.', 'error');
                }
            };

            try {
                app.primeCachedView('/api/finance/summary', renderSummary, 'ringkasan keuangan');
                app.primeCachedView('/api/finance/payments', renderPayments, 'riwayat pembayaran');
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            app.registerSyncHandler('finance', loadFinance, { aliases: ['portal-context', 'announcement-index'] });

            await loadFinance();
        });
    </script>
@endpush
