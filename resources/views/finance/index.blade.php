@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@section('content')
    <header class="mb-5">
        <h1 class="mycic-page-heading">Keuangan</h1>
        <p class="mycic-page-subheading">Informasi tagihan terverifikasi dan riwayat transaksi dari portal kampus.</p>
    </header>

    <div class="flex flex-col gap-5">
        {{-- Summary Metric Cards --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="mycic-metric">
                <div class="flex items-center justify-between gap-2">
                    <div class="mycic-metric-label">Total Terbayar</div>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-success-soft text-success" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" /></svg>
                    </span>
                </div>
                <div class="mycic-metric-value text-success" data-finance-metric="total">
                    <div class="mycic-skeleton h-7 w-32"></div>
                </div>
                <div class="mycic-metric-note">Akumulasi dana terverifikasi</div>
            </div>

            <div class="mycic-metric">
                <div class="flex items-center justify-between gap-2">
                    <div class="mycic-metric-label">Total Transaksi</div>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 15l2 2l4 -4" /></svg>
                    </span>
                </div>
                <div class="mycic-metric-value" data-finance-metric="count">
                    <div class="mycic-skeleton h-7 w-16"></div>
                </div>
                <div class="mycic-metric-note">Kwitansi pembayaran tercatat</div>
            </div>

            <div class="mycic-metric">
                <div class="flex items-center justify-between gap-2">
                    <div class="mycic-metric-label">Transaksi Terakhir</div>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-info-soft text-info" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /><path d="M8 15h2v2h-2z" /></svg>
                    </span>
                </div>
                <div class="mycic-metric-value text-lg sm:text-xl font-bold truncate" data-finance-metric="latest">
                    <div class="mycic-skeleton h-7 w-28"></div>
                </div>
                <div class="mycic-metric-note">Tanggal pembayaran terbaru</div>
            </div>
        </div>

        {{-- Payment History Card --}}
        <section class="mycic-card" aria-label="Riwayat Pembayaran">
            <div class="mycic-card-header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h10l2 2l-2 2h-10a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1z" /><path d="M13 13h7l2 2l-2 2h-7a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1z" /><path d="M5 5m0 1a1 1 0 0 1 1 -1h1a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-1a1 1 0 0 1 -1 -1z" /></svg>
                    </span>
                    <div>
                        <h2 class="mycic-card-title">Riwayat Pembayaran</h2>
                        <p class="text-caption text-ink-subtle" id="finance-subtitle">Memuat data transaksi portal...</p>
                    </div>
                </div>

                {{-- Filter & Search --}}
                <div class="flex items-center gap-2">
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
            </div>

            {{-- Body Host --}}
            <div id="finance-payments">
                <div class="mycic-card-body flex flex-col divide-y divide-border-light">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <div class="flex items-center gap-3">
                                <div class="mycic-skeleton h-10 w-10 rounded-lg"></div>
                                <div class="space-y-1.5">
                                    <div class="mycic-skeleton h-4 w-36 sm:w-48"></div>
                                    <div class="mycic-skeleton h-3 w-24"></div>
                                </div>
                            </div>
                            <div class="mycic-skeleton h-5 w-24"></div>
                        </div>
                    @endfor
                </div>
            </div>
        </section>

        {{-- Verification Information Note --}}
        <div class="rounded-xl border border-border bg-surface p-4 text-xs text-ink-subtle">
            <div class="flex items-start gap-2.5">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-brand mt-0.5" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9h.01" /><path d="M11 12h1v4h1" /><path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z" /></svg>
                <div class="space-y-1">
                    <span class="font-semibold text-ink">Catatan Verifikasi Pembayaran</span>
                    <p class="mb-0 text-caption text-ink-subtle">Seluruh data transaksi ditarik secara langsung dari portal akademik SiPANDAI / myCIC. Jika terdapat pembayaran yang telah diselesaikan namun belum tercatat, silakan simpan bukti transaksi dan lakukan konfirmasi ke Bagian Keuangan Kampus.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Detail Modal --}}
    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" id="finance-detail-modal">
        <div class="mycic-modal-backdrop" id="finance-modal-backdrop"></div>
        <div class="mycic-modal-panel max-w-md">
            <div class="mycic-modal-header">
                <div>
                    <div class="text-caption uppercase tracking-wide text-ink-subtle">Detail Transaksi</div>
                    <h3 class="text-subtitle font-bold text-ink" id="modal-payment-title">Pembayaran</h3>
                </div>
                <button type="button" class="mycic-modal-close" id="finance-modal-close" aria-label="Tutup detail transaksi">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6l-12 12" />
                        <path d="M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mycic-modal-body space-y-4" id="modal-payment-body">
                {{-- Filled dynamically --}}
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const container = document.getElementById('finance-payments');
            const searchInput = document.getElementById('finance-search');
            const subtitleEl = document.getElementById('finance-subtitle');
            const modal = document.getElementById('finance-detail-modal');
            const modalBackdrop = document.getElementById('finance-modal-backdrop');
            const modalClose = document.getElementById('finance-modal-close');
            const modalTitle = document.getElementById('modal-payment-title');
            const modalBody = document.getElementById('modal-payment-body');

            let allPayments = [];
            let latestSummary = {};
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
                modalTitle.textContent = payment.description || 'Detail Pembayaran';

                const entries = [
                    { label: 'Nominal Pembayaran', value: payment.amount_label || formatRupiah(payment.amount) },
                    { label: 'Status', value: payment.status || 'Lunas' },
                    { label: 'Tanggal Pembayaran', value: payment.paid_at_label || '-' },
                    { label: 'Metode / Bank', value: payment.payment_channel || payment.bank || payment.raw?.Bank || '-' },
                    { label: 'No. Kwitansi / Ref', value: payment.reference || payment.raw?.Kwitansi || payment.raw?.['No. Kwitansi'] || '-' },
                    { label: 'Tahun Akademik', value: payment.academic_year || payment.raw?.['Tahun Akademik'] || '-' },
                    { label: 'Semester', value: payment.semester || payment.raw?.Semester || '-' },
                ].filter((item) => item.value && item.value !== '-');

                modalBody.innerHTML = `
                    <div class="rounded-xl border border-success/20 bg-success-soft p-4 text-center">
                        <div class="text-caption font-semibold uppercase tracking-wide text-success">Total Bayar</div>
                        <div class="text-2xl font-extrabold text-success tabular-nums mt-0.5">${app.escapeHtml(payment.amount_label || formatRupiah(payment.amount))}</div>
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

                if (subtitleEl) {
                    subtitleEl.textContent = query
                        ? `Menampilkan ${filtered.length} dari ${allPayments.length} transaksi`
                        : `${allPayments.length} transaksi pembayaran tercatat`;
                }

                if (filtered.length === 0) {
                    container.innerHTML = `
                        <div class="mycic-card-body">
                            ${app.renderEmptyState(query ? 'Tidak ada transaksi yang cocok dengan pencarian.' : 'Belum ada riwayat pembayaran.', 'secondary')}
                        </div>
                    `;
                    return;
                }

                // Desktop Table View
                let tableHtml = `
                    <div class="hidden md:block">
                        <div class="mycic-table-wrap border-0 rounded-b-[var(--radius-card)]">
                            <table class="mycic-table">
                                <thead>
                                    <tr>
                                        <th class="w-12 text-center">#</th>
                                        <th>Tanggal</th>
                                        <th>Uraian Pembayaran</th>
                                        <th>Metode / Bank</th>
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
                            <td class="whitespace-nowrap font-medium text-ink">
                                <div class="flex items-center gap-1.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-ink-subtle" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /></svg>
                                    ${app.escapeHtml(payment.paid_at_label || '-')}
                                </div>
                            </td>
                            <td>
                                <div class="font-bold text-ink">${app.escapeHtml(payment.description || 'Pembayaran')}</div>
                                ${ref ? `<div class="text-caption text-ink-subtle font-mono">No. Kwitansi: ${app.escapeHtml(ref)}</div>` : ''}
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

                // Mobile Card View
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
                                    <div class="font-bold text-sm text-ink leading-snug">${app.escapeHtml(payment.description || 'Pembayaran')}</div>
                                    <div class="mt-1 flex items-center gap-1.5 text-caption text-ink-subtle">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /></svg>
                                        <span>${app.escapeHtml(payment.paid_at_label || '-')}</span>
                                    </div>
                                </div>
                                <div class="shrink-0 text-end">
                                    <div class="text-sm font-bold tabular-nums text-success">${app.escapeHtml(payment.amount_label || formatRupiah(payment.amount))}</div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-1">
                                <div class="flex items-center gap-1.5">
                                    ${channel ? `<span class="mycic-badge-brand text-[11px]">${app.escapeHtml(channel)}</span>` : ''}
                                    <span class="${statusBadgeClass} text-[11px]">${app.escapeHtml(status)}</span>
                                </div>
                                <span class="text-caption font-semibold text-brand flex items-center gap-0.5">
                                    Detail
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 6l6 6l-6 6" /></svg>
                                </span>
                            </div>
                        </div>
                    `;
                });
                mobileHtml += '</div>';

                container.innerHTML = tableHtml + mobileHtml;

                // Bind click events to open modal
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
                const student = data.student_info || {};
                const summary = data.summary || {};
                allPayments = data.payment_history || [];
                latestSummary = summary;

                // Summary metrics
                const totalMetric = document.querySelector('[data-finance-metric="total"]');
                const countMetric = document.querySelector('[data-finance-metric="count"]');
                const latestMetric = document.querySelector('[data-finance-metric="latest"]');

                if (totalMetric) {
                    const totalPaid = summary.total_paid_amount || allPayments.reduce((acc, p) => acc + (p.amount || 0), 0);
                    totalMetric.textContent = formatRupiah(totalPaid);
                }
                if (countMetric) {
                    const count = summary.total_records !== undefined ? summary.total_records : allPayments.length;
                    countMetric.textContent = `${count} Transaksi`;
                }
                if (latestMetric) {
                    const latestDate = summary.latest_payment_label || allPayments[0]?.paid_at_label || '-';
                    latestMetric.textContent = latestDate;
                }

                // Enable search input
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
