@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@php
    $portalFinanceUrl = \App\Services\PortalEndpointRegistry::url('student_finance');
@endphp

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Keuangan</h1>
        <p class="mycic-page-subheading">Tagihan aktif, tahap UKT, dan riwayat pembayaran dari portal kampus.</p>
        <p class="mt-1 min-h-4 text-caption text-ink-subtle" data-finance-updated></p>
    </header>

    <div class="flex flex-col gap-5" id="finance-page" data-portal-finance-url="{{ $portalFinanceUrl }}">
        <div class="grid items-start gap-5 lg:grid-cols-12">
            <section id="tagihan" class="scroll-mt-24 lg:sticky lg:top-24 lg:col-span-5" aria-labelledby="finance-bills-title">
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h2 class="mycic-card-title" id="finance-bills-title">Status tagihan</h2>
                    </div>
                    <div class="mycic-card-body flex flex-col gap-4" id="finance-bills" aria-busy="true">
                        <span class="sr-only">Memuat status tagihan…</span>
                        <div class="mycic-skeleton h-3.5 w-28"></div>
                        <div class="mycic-skeleton h-9 w-44"></div>
                        <div class="mycic-skeleton h-4 w-full"></div>
                        <div class="mycic-skeleton h-4 w-2/3"></div>
                    </div>
                </div>
            </section>

            <section id="tahap-ukt" class="scroll-mt-24 lg:col-span-7" aria-labelledby="finance-stages-title">
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h2 class="mycic-card-title" id="finance-stages-title">Progres UKT</h2>
                        <p class="mt-0.5 text-caption text-ink-subtle">Tahap pembayaran per tahun kuliah, dibaca dari histori dan tagihan portal.</p>
                    </div>
                    <div class="mycic-card-body flex flex-col gap-4" id="finance-stages" aria-busy="true">
                        <span class="sr-only">Memuat tahap UKT…</span>
                        <div class="mycic-skeleton h-4 w-40"></div>
                        <div class="grid grid-cols-4 gap-2">
                            @for ($i = 0; $i < 4; $i++)
                                <div class="flex flex-col items-center gap-2">
                                    <div class="mycic-skeleton h-9 w-9 rounded-full"></div>
                                    <div class="mycic-skeleton h-3 w-12"></div>
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="Ringkasan riwayat pembayaran" role="group">
            <div class="mycic-metric col-span-2 sm:col-span-1">
                <div class="mycic-metric-label">Terbayar</div>
                <div class="mycic-metric-value truncate text-success" data-finance-metric="total">
                    <div class="mycic-skeleton h-7 w-24"></div>
                </div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Transaksi</div>
                <div class="mycic-metric-value" data-finance-metric="count">
                    <div class="mycic-skeleton h-7 w-12"></div>
                </div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Terakhir</div>
                <div class="mycic-metric-value truncate text-lg font-bold sm:text-xl" data-finance-metric="latest">
                    <div class="mycic-skeleton h-7 w-20"></div>
                </div>
            </div>
        </div>

        <section class="mycic-card" aria-labelledby="finance-history-title">
            <div class="mycic-card-header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="mycic-card-title" id="finance-history-title">Riwayat pembayaran</h2>
                <div class="mycic-input-wrap w-full sm:w-64">
                    <label for="finance-search" class="sr-only">Cari transaksi</label>
                    <span class="mycic-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    </span>
                    <input
                        type="search"
                        id="finance-search"
                        class="mycic-input mycic-input-with-icon"
                        placeholder="Cari transaksi..."
                        autocomplete="off"
                        aria-describedby="finance-search-status"
                        disabled
                    >
                </div>
            </div>
            <p class="sr-only" aria-live="polite" id="finance-search-status"></p>

            <div id="finance-payments" aria-busy="true">
                <div class="mycic-card-body flex flex-col divide-y divide-border-light">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <div class="space-y-1.5">
                                <div class="mycic-skeleton h-4 w-36 sm:w-48"></div>
                                <div class="mycic-skeleton h-3 w-24"></div>
                            </div>
                            <div class="mycic-skeleton h-5 w-24"></div>
                        </div>
                    @endfor
                </div>
            </div>
        </section>
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
            const updatedLabel = document.querySelector('[data-finance-updated]');
            const portalFinanceUrl = app.safeHref(page.dataset.portalFinanceUrl) || '#';

            let allPayments = [];
            let currentFilter = '';
            let latestSummary = null;
            let latestPayments = null;
            let summaryRendered = false;
            let paymentsRendered = false;
            let lastPayload = null;

            const STAGE_STATUS = {
                paid: {
                    label: 'Lunas',
                    circle: 'bg-success text-white',
                    text: 'text-success',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>',
                },
                unpaid: {
                    label: 'Belum lunas',
                    circle: 'bg-danger-soft text-danger ring-2 ring-danger/40',
                    text: 'text-danger',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v4" /><path d="M12 16h.01" /></svg>',
                },
                upcoming: {
                    label: 'Akan datang',
                    circle: 'bg-surface-tint text-brand-pressed',
                    text: 'text-brand-pressed',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>',
                },
                unknown: {
                    label: 'Belum pasti',
                    circle: 'border border-dashed border-border bg-panel text-ink-subtle',
                    text: 'text-ink-subtle',
                    icon: '<span class="text-sm font-bold" aria-hidden="true">?</span>',
                },
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

            const describeFreshness = (payload) => {
                const meta = payload?.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || meta.cached_at || payload?.data?.extracted_at);

                if (!ago) {
                    return '';
                }

                if (meta.data_origin === 'device_cache') {
                    return `Diperbarui ${ago} · data tersimpan di perangkat${meta.cache_is_stale ? ', mungkin sudah lama' : ''}`;
                }

                if (meta.status === 'syncing') {
                    return `Diperbarui ${ago} · sedang diperbarui dari portal`;
                }

                if (meta.status === 'failed') {
                    return `Diperbarui ${ago} · portal belum bisa dihubungi, ini data terakhir`;
                }

                return `Diperbarui ${ago}`;
            };

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
                        <p class="font-semibold">Batas bayar ${deadline.stage ? `UKT tahap ${app.escapeHtml(deadline.stage)}` : 'kuliah'}: ${app.escapeHtml(app.formatIndonesianDate(deadline.date, { weekday: true }))}</p>
                        <p class="mt-0.5 text-sm text-ink-muted">${app.escapeHtml(app.capitalize(app.formatRelativeDays(deadline.date)))}. Bayar sebelum tanggal ini supaya akses portal tidak terganggu.</p>
                        ${deadline.text ? `
                            <details class="mt-2 text-caption">
                                <summary class="cursor-pointer font-semibold text-ink-muted">Lihat teks dari portal</summary>
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
                            <p class="font-semibold text-ink">Status tagihan tidak terbaca</p>
                            <p class="mt-1 text-sm">Tabel tagihan di portal tidak bisa dibaca, jadi MyCIC belum tahu ada tagihan atau tidak. Bukan berarti kamu tidak punya tagihan.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-finance-retry data-online-required>Coba lagi</button>
                                <a href="${app.escapeHtml(portalFinanceUrl)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('ghost', { small: true })}">Buka di portal<span class="sr-only"> (tab baru, perlu masuk lagi)</span></a>
                            </div>
                        </div>
                    `);
                } else if (status === 'clear') {
                    parts.push(`
                        <div class="${app.alertTone('success')}" role="status" data-finance-bill-status="clear">
                            <p class="font-semibold">Tidak ada tagihan aktif</p>
                            <p class="mt-1 text-sm text-ink-muted">${app.escapeHtml(renderLatestPayment(summary.latest_payment))}</p>
                        </div>
                    `);
                } else {
                    const total = Number.isFinite(Number(outstanding.total_amount)) && outstanding.total_amount !== null ? Number(outstanding.total_amount) : null;
                    const totalLabel = outstanding.total_amount_label || app.formatRupiah(total);
                    const count = Number.isInteger(outstanding.count) ? outstanding.count : bills.length;
                    const urgent = deadline !== null && deadline.days <= 7;

                    parts.push(`
                        <div class="${app.alertTone(urgent ? 'danger' : 'warning')}" role="status" data-finance-bill-status="outstanding">
                            <p class="text-caption font-semibold uppercase tracking-wide text-ink-subtle">Total tagihan aktif</p>
                            <p class="mt-1 text-3xl font-extrabold tracking-tight text-ink tabular-nums">${app.escapeHtml(totalLabel)}</p>
                            <p class="mt-0.5 text-sm text-ink-muted">${count} tagihan belum lunas</p>
                        </div>
                    `);

                    if (bills.length) {
                        parts.push(`
                            <ul class="flex flex-col" aria-label="Rincian tagihan">
                                ${bills.map((bill) => `
                                    <li class="mycic-list-row">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-bold text-ink wrap-break-word">${app.escapeHtml(bill.name || 'Tagihan')}</span>
                                            ${bill.description ? `<span class="mt-0.5 block text-caption text-ink-subtle">${app.escapeHtml(bill.description)}</span>` : ''}
                                        </span>
                                        <span class="shrink-0 text-sm font-bold text-ink tabular-nums">${app.escapeHtml(bill.amount_label || app.formatRupiah(bill.amount))}</span>
                                    </li>
                                `).join('')}
                            </ul>
                        `);
                    }

                    if (outstanding.rows_sum_matches_total === false) {
                        parts.push('<p class="text-caption text-ink-subtle">Jumlah rincian berbeda dengan total di portal. Pakai angka total di atas.</p>');
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
                            UKT tahap 1 tahun ini belum lunas. Pelunasan tahap 1 jadi syarat her-registrasi sebelum PIN KRS terbit.
                            <a href="/krs" class="mycic-link">Cek status KRS</a>
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

            const renderStepper = (year, nextStage) => {
                const stages = (Array.isArray(year.stages) ? year.stages : []).filter((stage) => Number.isInteger(stage?.stage));
                const inset = `calc(50% / ${Math.max(1, stages.length)})`;

                return `
                    <div class="relative">
                        <div class="absolute top-[1.125rem] h-0.5 bg-border-light" style="left: ${inset}; right: ${inset};" aria-hidden="true"></div>
                        <ol class="relative grid gap-1" style="grid-template-columns: repeat(${Math.max(1, stages.length)}, minmax(0, 1fr));" aria-label="Tahap UKT tahun ke-${year.study_year}">
                            ${stages.map((stage) => {
                                const status = STAGE_STATUS[stage.status] || STAGE_STATUS.unknown;
                                const isNext = nextStage && nextStage.study_year === year.study_year && nextStage.stage === stage.stage;

                                return `
                                    <li class="flex min-w-0 flex-col items-center gap-1 text-center" aria-label="${app.escapeHtml(stageSentence(stage))}" ${isNext ? 'aria-current="step"' : ''} title="${app.escapeHtml(stageSentence(stage))}">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-full ${status.circle} ${isNext && stage.status !== 'unpaid' ? 'ring-2 ring-brand ring-offset-2 ring-offset-panel' : ''}" aria-hidden="true">${status.icon}</span>
                                        <span class="text-xs font-bold text-ink" aria-hidden="true">Tahap ${stage.stage}</span>
                                        <span class="text-[0.7rem] font-semibold leading-tight ${status.text}" aria-hidden="true">${status.label}</span>
                                        <span class="line-clamp-2 max-w-full text-[0.7rem] leading-tight text-ink-subtle tabular-nums wrap-break-word" aria-hidden="true">${app.escapeHtml(stageDetail(stage))}</span>
                                        ${stage.is_partial ? '<span class="mycic-badge-warning px-1.5 text-[0.65rem]" aria-hidden="true">Sebagian</span>' : ''}
                                        ${isNext ? '<span class="mycic-badge-brand px-1.5 text-[0.65rem]" aria-hidden="true">Berikutnya</span>' : ''}
                                    </li>
                                `;
                            }).join('')}
                        </ol>
                    </div>
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
                        'Histori dan tagihan di portal belum mencantumkan tahap dan tahun kuliah.'
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
                            <span class="font-semibold text-ink">Berikutnya: tahap ${app.escapeHtml(nextStage.stage)}</span>
                            · ${app.escapeHtml((STAGE_STATUS[nextStage.status] || STAGE_STATUS.unknown).label.toLowerCase())}${nextStage.deadline ? `, jatuh tempo ${app.escapeHtml(app.formatIndonesianDate(nextStage.deadline))}` : ''}
                        </p>
                    `
                    : '';

                stagesHost.innerHTML = `
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-bold text-ink">${app.escapeHtml(heading(first))} ${first.is_current ? app.renderBadge('Berjalan', 'brand') : ''}</h3>
                            <span class="text-caption text-ink-subtle">${app.escapeHtml(yearSummary(first))}</span>
                        </div>
                        ${renderStepper(first, nextStage)}
                        ${nextLine}
                        ${subtotal(first) ? `<p class="text-caption text-ink-subtle">Total dibayar tahun ini: <span class="font-semibold text-ink tabular-nums">${app.escapeHtml(subtotal(first))}</span></p>` : ''}
                    </div>
                    ${rest.map((year) => `
                        <details class="group rounded-lg border border-border-light">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="block text-sm font-bold text-ink">${app.escapeHtml(heading(year))}</span>
                                    <span class="block text-caption text-ink-subtle">${app.escapeHtml([yearSummary(year), subtotal(year)].filter(Boolean).join(' · '))}</span>
                                </span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-ink-subtle transition-transform group-open:rotate-180" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                            </summary>
                            <div class="px-3 pb-3 pt-1">${renderStepper(year, nextStage)}</div>
                        </details>
                    `).join('')}
                    ${allStages.some((stage) => stage?.status === 'unknown') ? '<p class="text-caption text-ink-subtle"><span class="font-semibold text-ink-muted">Belum pasti</span> berarti tahap itu tidak ada di histori maupun tagihan portal. Tahap yang dibayar saat pendaftaran (PMB) sering tidak tercatat di histori.</p>' : ''}
                    ${allStages.some((stage) => stage?.is_partial) ? '<p class="text-caption text-ink-subtle"><span class="font-semibold text-ink-muted">Sebagian</span> berarti nominal yang tercatat kurang dari nominal tahap.</p>' : ''}
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
                            <div class="rounded-xl border border-success/20 bg-success-soft p-4 text-center">
                                <div class="text-2xl font-extrabold text-success tabular-nums">${app.escapeHtml(amount)}</div>
                                <span class="${partial ? 'mycic-badge-warning' : 'mycic-badge-success'} mt-2">${partial ? 'Dibayar sebagian' : 'Tercatat di histori portal'}</span>
                            </div>
                            ${app.renderKeyValueList(entries)}
                            <p class="text-caption text-ink-subtle">Ringkasan dari histori portal, bukan kwitansi resmi.</p>
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
                                <li>Portal kampus tidak punya tombol bayar atau virtual account. Bayar di luar portal sesuai petunjuk resmi Bagian Keuangan.</li>
                                <li>Pakai tombol <span class="font-semibold text-ink">Salin nominal</span> supaya jumlah yang kamu bayar pas.</li>
                                <li>Simpan bukti bayar. Tagihan baru hilang dari portal setelah Bagian Keuangan mencatat pembayaranmu, jadi bisa butuh beberapa hari.</li>
                            </ol>
                            <a href="/announcement?kategori=pembayaran" class="mycic-sheet-action">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-ink">Pengumuman keuangan</span>
                                    <span class="block text-caption text-ink-subtle">Info rekening dan tenggat terbaru dari kampus</span>
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
                        <div class="mycic-card-body">
                            ${app.renderEmptyState(query ? 'Tidak ada hasil.' : 'Belum ada pembayaran tercatat.', 'secondary', query ? 'Coba kata kunci lain.' : 'Pembayaran yang sudah dicatat Bagian Keuangan akan muncul di sini.')}
                        </div>
                    `;

                    return;
                }

                const showChannel = filtered.some((payment) => payment.payment_channel || payment.bank || payment.raw?.Bank);
                const showStatus = filtered.some((payment) => payment.status);
                const statusBadge = (status) => app.renderBadge(status, /lunas|sukses|berhasil|verified/i.test(status) ? 'success' : 'info');

                const tableHtml = `
                    <div class="hidden md:block">
                        <div class="mycic-table-wrap rounded-b-[var(--radius-card)] border-0">
                            <table class="mycic-table">
                                <caption class="sr-only">Riwayat pembayaran, pilih baris untuk melihat detail</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="w-12 text-center">#</th>
                                        <th scope="col">Tanggal</th>
                                        <th scope="col">Uraian</th>
                                        <th scope="col">Tahap</th>
                                        ${showChannel ? '<th scope="col">Metode</th>' : ''}
                                        ${showStatus ? '<th scope="col">Status</th>' : ''}
                                        <th scope="col" class="text-end">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${filtered.map((payment, index) => {
                                        const channel = payment.payment_channel || payment.bank || payment.raw?.Bank || '';
                                        const ref = payment.reference || payment.raw?.Kwitansi || '';

                                        return `
                                            <tr class="cursor-pointer transition-colors hover:bg-surface/60" data-payment-row="${index}">
                                                <td class="text-center font-medium text-ink-subtle">${index + 1}</td>
                                                <td class="whitespace-nowrap font-medium text-ink">${app.escapeHtml(payment.paid_at_label || '–')}</td>
                                                <td>
                                                    <button type="button" class="rounded text-start font-bold text-ink hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-payment-open="${index}">
                                                        ${app.escapeHtml(paymentTitle(payment))}
                                                        <span class="sr-only">, lihat detail</span>
                                                    </button>
                                                    ${ref ? `<div class="font-mono text-caption text-ink-subtle">${app.escapeHtml(ref)}</div>` : ''}
                                                </td>
                                                <td class="whitespace-nowrap">${stageLabel(payment) ? app.escapeHtml(stageLabel(payment)) : '<span class="text-caption text-ink-subtle">–</span>'}${isPartial(payment) ? ` ${app.renderBadge('Sebagian', 'warning')}` : ''}</td>
                                                ${showChannel ? `<td>${channel ? app.renderBadge(channel, 'brand') : '<span class="text-caption text-ink-subtle">–</span>'}</td>` : ''}
                                                ${showStatus ? `<td>${payment.status ? statusBadge(payment.status) : '<span class="text-caption text-ink-subtle">–</span>'}</td>` : ''}
                                                <td class="whitespace-nowrap text-end text-base font-bold text-success tabular-nums">${app.escapeHtml(amountLabel(payment))}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                const mobileBadges = (payment) => [
                    stageLabel(payment) && !/tahap/i.test(paymentTitle(payment)) ? app.renderBadge(stageLabel(payment), 'secondary') : '',
                    isPartial(payment) ? app.renderBadge('Sebagian', 'warning') : '',
                    payment.payment_channel || payment.bank ? app.renderBadge(payment.payment_channel || payment.bank, 'brand') : '',
                    payment.status ? statusBadge(payment.status) : '',
                ].filter(Boolean).join('');

                const mobileHtml = `
                    <ul class="divide-y divide-border-light md:hidden">
                        ${filtered.map((payment, index) => `
                            <li>
                                <button type="button" class="flex w-full flex-col gap-2 p-4 text-start transition-colors hover:bg-surface/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand active:bg-surface" data-payment-open="${index}">
                                    <span class="flex w-full items-start justify-between gap-3">
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-bold leading-snug text-ink">${app.escapeHtml(paymentTitle(payment))}</span>
                                            <span class="mt-1 block text-caption text-ink-subtle">${app.escapeHtml(payment.paid_at_label || '–')}</span>
                                        </span>
                                        <span class="shrink-0 text-end text-sm font-bold text-success tabular-nums">${app.escapeHtml(amountLabel(payment))}</span>
                                    </span>
                                    ${mobileBadges(payment) ? `<span class="flex flex-wrap items-center gap-1.5">${mobileBadges(payment)}</span>` : ''}
                                </button>
                            </li>
                        `).join('')}
                    </ul>
                `;

                container.innerHTML = tableHtml + mobileHtml;

                container.querySelectorAll('[data-payment-row]').forEach((row) => {
                    row.addEventListener('click', () => openDetail(filtered[Number(row.dataset.paymentRow)]));
                });
                container.querySelectorAll('.md\\:hidden [data-payment-open]').forEach((button) => {
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

                if (!lastPayload) {
                    updatedLabel.textContent = describeFreshness(payload);
                }
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
                lastPayload = payload;
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
                        <div class="mycic-card-body">
                            ${app.renderEmptyState('Histori pembayaran tidak terbaca dari portal.', 'secondary', 'Coba sinkronkan lagi nanti. Riwayatmu tidak hilang.')}
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

                updatedLabel.textContent = describeFreshness(payload);
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

            const loadFinance = async ({ force = false } = {}) => {
                const [summaryResult, paymentsResult] = await Promise.allSettled([
                    app.request('/api/finance/summary', { fresh: force }),
                    app.request('/api/finance/payments', { fresh: force }),
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
