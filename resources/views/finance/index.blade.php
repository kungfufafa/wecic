@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Keuangan</h1>
    </header>

    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-3 gap-3">
            <div class="mycic-metric">
                <div class="mycic-metric-label">Terbayar</div>
                <div class="mycic-metric-value text-success" data-finance-metric="total">
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
                <div class="mycic-metric-value text-lg sm:text-xl font-bold truncate" data-finance-metric="latest">
                    <div class="mycic-skeleton h-7 w-20"></div>
                </div>
            </div>
        </div>

        <section class="mycic-card" aria-label="Riwayat pembayaran">
            <div class="mycic-card-header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="mycic-card-title">Riwayat</h2>
                <div class="mycic-input-wrap w-full sm:w-64">
                    <span class="mycic-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    </span>
                    <input
                        type="search"
                        id="finance-search"
                        class="mycic-input-with-icon h-9 text-xs"
                        placeholder="Cari transaksi..."
                        autocomplete="off"
                        disabled
                    >
                </div>
            </div>

            <div id="finance-payments">
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

    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" id="finance-detail-modal">
        <div class="mycic-modal-backdrop" id="finance-modal-backdrop"></div>
        <div class="mycic-modal-panel max-w-md">
            <div class="mycic-modal-header">
                <h3 class="text-subtitle font-bold text-ink" id="modal-payment-title">Pembayaran</h3>
                <button type="button" class="mycic-modal-close" id="finance-modal-close" aria-label="Tutup">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6l-12 12" />
                        <path d="M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mycic-modal-body space-y-4" id="modal-payment-body"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const container = document.getElementById('finance-payments');
            const searchInput = document.getElementById('finance-search');
            const modal = document.getElementById('finance-detail-modal');
            const modalBackdrop = document.getElementById('finance-modal-backdrop');
            const modalClose = document.getElementById('finance-modal-close');
            const modalTitle = document.getElementById('modal-payment-title');
            const modalBody = document.getElementById('modal-payment-body');

            let allPayments = [];
            let currentFilter = '';

            const formatRupiah = (val) => {
                if (typeof val === 'string' && /^Rp\s*/i.test(val)) {
                    return val;
                }
                const num = typeof val === 'number' ? val : parseInt(String(val || '').replace(/[^\d]/g, ''), 10);
                if (Number.isNaN(num) || num === 0) {
                    return 'Rp 0';
                }
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0,
                }).format(num);
            };

            const openDetailModal = (payment) => {
                if (!modal || !modalBody) return;
                modalTitle.textContent = [payment.category, payment.description].filter(Boolean).join(' · ') || 'Pembayaran';

                const entries = [
                    { label: 'Nominal', value: payment.amount_label || formatRupiah(payment.amount) },
                    { label: 'Status', value: payment.status || 'Lunas' },
                    { label: 'Tanggal', value: payment.paid_at_label || '-' },
                    { label: 'Metode', value: payment.payment_channel || payment.bank || payment.raw?.Bank || '-' },
                    { label: 'Kwitansi', value: payment.reference || payment.raw?.Kwitansi || payment.raw?.['No. Kwitansi'] || '-' },
                    { label: 'Tahun', value: payment.academic_year || payment.raw?.['Tahun Akademik'] || '-' },
                    { label: 'Semester', value: payment.semester || payment.raw?.Semester || '-' },
                ].filter((item) => item.value && item.value !== '-');

                modalBody.innerHTML = `
                    <div class="rounded-xl border border-success/20 bg-success-soft p-4 text-center">
                        <div class="text-2xl font-extrabold text-success tabular-nums">${app.escapeHtml(payment.amount_label || formatRupiah(payment.amount))}</div>
                        <span class="mycic-badge-success mt-2">${app.escapeHtml(payment.status || 'Lunas')}</span>
                    </div>
                    <div class="divide-y divide-border-light text-sm">
                        ${entries.map((item) => `
                            <div class="flex items-center justify-between gap-4 py-2.5">
                                <span class="text-ink-subtle">${app.escapeHtml(item.label)}</span>
                                <span class="font-semibold text-ink text-end">${app.escapeHtml(item.value)}</span>
                            </div>
                        `).join('')}
                    </div>
                `;

                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            };

            const closeDetailModal = () => {
                if (!modal) return;
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            };

            modalBackdrop?.addEventListener('click', closeDetailModal);
            modalClose?.addEventListener('click', closeDetailModal);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !modal?.classList.contains('hidden')) {
                    closeDetailModal();
                }
            });

            const renderPaymentsList = () => {
                const query = currentFilter.trim().toLowerCase();
                const filtered = allPayments.filter((p) => {
                    if (!query) return true;
                    const desc = (p.description || '').toLowerCase();
                    const date = (p.paid_at_label || '').toLowerCase();
                    const channel = (p.payment_channel || p.bank || '').toLowerCase();
                    const ref = (p.reference || '').toLowerCase();
                    const status = (p.status || '').toLowerCase();
                    return desc.includes(query) || date.includes(query) || channel.includes(query) || ref.includes(query) || status.includes(query);
                });

                if (filtered.length === 0) {
                    container.innerHTML = `
                        <div class="mycic-card-body">
                            ${app.renderEmptyState(query ? 'Tidak ada hasil.' : 'Belum ada pembayaran.', 'secondary')}
                        </div>
                    `;
                    return;
                }

                let tableHtml = `
                    <div class="hidden md:block">
                        <div class="mycic-table-wrap border-0 rounded-b-[var(--radius-card)]">
                            <table class="mycic-table">
                                <thead>
                                    <tr>
                                        <th class="w-12 text-center">#</th>
                                        <th>Tanggal</th>
                                        <th>Uraian</th>
                                        <th>Metode</th>
                                        <th>Status</th>
                                        <th class="text-end">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;

                filtered.forEach((payment, index) => {
                    const channel = payment.payment_channel || payment.bank || payment.raw?.Bank || '';
                    const ref = payment.reference || payment.raw?.Kwitansi || '';
                    const status = payment.status || 'Lunas';
                    const isSuccess = /lunas|sukses|berhasil|verified/i.test(status);
                    const statusBadgeClass = isSuccess ? 'mycic-badge-success' : 'mycic-badge-info';

                    tableHtml += `
                        <tr class="hover:bg-surface/60 transition-colors cursor-pointer" data-payment-row="${index}">
                            <td class="text-center font-medium text-ink-subtle">${index + 1}</td>
                            <td class="whitespace-nowrap font-medium text-ink">${app.escapeHtml(payment.paid_at_label || '-')}</td>
                            <td>
                                <div class="font-bold text-ink">${app.escapeHtml([payment.category, payment.description].filter(Boolean).join(' · ') || 'Pembayaran')}</div>
                                ${ref ? `<div class="text-caption text-ink-subtle font-mono">${app.escapeHtml(ref)}</div>` : ''}
                            </td>
                            <td>
                                ${channel ? `<span class="mycic-badge-brand">${app.escapeHtml(channel)}</span>` : '<span class="text-ink-subtle text-caption">-</span>'}
                            </td>
                            <td>
                                <span class="${statusBadgeClass}">${app.escapeHtml(status)}</span>
                            </td>
                            <td class="whitespace-nowrap text-end font-bold tabular-nums text-success text-base">
                                ${app.escapeHtml(payment.amount_label || formatRupiah(payment.amount))}
                            </td>
                        </tr>
                    `;
                });

                tableHtml += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                let mobileHtml = '<div class="divide-y divide-border-light md:hidden">';
                filtered.forEach((payment, index) => {
                    const channel = payment.payment_channel || payment.bank || payment.raw?.Bank || '';
                    const status = payment.status || 'Lunas';
                    const isSuccess = /lunas|sukses|berhasil|verified/i.test(status);
                    const statusBadgeClass = isSuccess ? 'mycic-badge-success' : 'mycic-badge-info';

                    mobileHtml += `
                        <div class="p-4 transition-colors hover:bg-surface/50 active:bg-surface cursor-pointer" data-payment-card="${index}">
                            <div class="flex items-start justify-between gap-3 mb-2">
                                <div class="min-w-0 flex-1">
                                    <div class="font-bold text-sm text-ink leading-snug">${app.escapeHtml([payment.category, payment.description].filter(Boolean).join(' · ') || 'Pembayaran')}</div>
                                    <div class="mt-1 text-caption text-ink-subtle">${app.escapeHtml(payment.paid_at_label || '-')}</div>
                                </div>
                                <div class="shrink-0 text-end text-sm font-bold tabular-nums text-success">
                                    ${app.escapeHtml(payment.amount_label || formatRupiah(payment.amount))}
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                ${channel ? `<span class="mycic-badge-brand text-[11px]">${app.escapeHtml(channel)}</span>` : ''}
                                <span class="${statusBadgeClass} text-[11px]">${app.escapeHtml(status)}</span>
                            </div>
                        </div>
                    `;
                });
                mobileHtml += '</div>';

                container.innerHTML = tableHtml + mobileHtml;

                container.querySelectorAll('[data-payment-row]').forEach((row) => {
                    const idx = parseInt(row.getAttribute('data-payment-row'), 10);
                    row.addEventListener('click', () => openDetailModal(filtered[idx]));
                });
                container.querySelectorAll('[data-payment-card]').forEach((card) => {
                    const idx = parseInt(card.getAttribute('data-payment-card'), 10);
                    card.addEventListener('click', () => openDetailModal(filtered[idx]));
                });
            };

            searchInput?.addEventListener('input', (e) => {
                currentFilter = e.target.value;
                renderPaymentsList();
            });

            const renderFinance = (payload) => {
                const data = payload?.data || {};
                const summary = data.summary || {};
                allPayments = data.payment_history || [];

                const totalMetric = document.querySelector('[data-finance-metric="total"]');
                const countMetric = document.querySelector('[data-finance-metric="count"]');
                const latestMetric = document.querySelector('[data-finance-metric="latest"]');

                if (totalMetric) {
                    const totalPaid = summary.total_paid_amount || allPayments.reduce((acc, p) => acc + (p.amount || 0), 0);
                    totalMetric.textContent = formatRupiah(totalPaid);
                }
                if (countMetric) {
                    const count = summary.total_records !== undefined ? summary.total_records : allPayments.length;
                    countMetric.textContent = String(count);
                }
                if (latestMetric) {
                    latestMetric.textContent = summary.latest_payment_label || allPayments[0]?.paid_at_label || '-';
                }

                if (searchInput) {
                    searchInput.disabled = false;
                }

                renderPaymentsList();
            };

            app.primeCachedView('/api/finance/payments', renderFinance, 'riwayat pembayaran');

            const ready = await app.ensureAuthenticated();
            if (!ready) {
                return;
            }

            const loadFinance = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/finance/payments', { fresh: force });
                    renderFinance(payload);
                    app.markViewSynced('Keuangan', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('finance', loadFinance);
            await loadFinance();
        });
    </script>
@endpush
