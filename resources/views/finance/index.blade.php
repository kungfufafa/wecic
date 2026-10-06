@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@section('content')
    <div class="mx-auto flex max-w-2xl flex-col gap-4">
        <header class="mb-1">
            <h1 class="mycic-page-heading">Keuangan</h1>
            <p class="mycic-page-subheading">Riwayat pembayaran kuliah dari portal kampus.</p>
        </header>

        <div class="mycic-card">
            <div class="mycic-card-header flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" /></svg>
                </span>
                <h3 class="mycic-card-title">Riwayat Pembayaran</h3>
            </div>
            <div class="mycic-card-body" id="finance-payments">
                <div class="mycic-skeleton h-4 w-2/3"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const renderFinance = (payload) => {
                const data = payload.data || {};
                const payments = data.payment_history || [];
                const container = document.getElementById('finance-payments');

                if (payments.length === 0) {
                    container.innerHTML = '<div class="mycic-alert-info mb-0">Belum ada riwayat pembayaran.</div>';
                    return;
                }

                let html = '<div class="hidden md:block"><div class="mycic-table-wrap"><table class="mycic-table">';
                html += '<thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th class="text-end">Jumlah</th></tr></thead><tbody>';

                payments.forEach((payment, index) => {
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td class="whitespace-nowrap">${payment.paid_at_label || '-'}</td>
                            <td>${payment.description || '-'}</td>
                            <td class="whitespace-nowrap text-end font-bold tabular-nums text-ink">${payment.amount_label || '-'}</td>
                        </tr>
                    `;
                });

                html += '</tbody></table></div></div>';

                html += '<div class="flex flex-col divide-y divide-border-light md:hidden">';
                payments.forEach((payment) => {
                    html += `
                        <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-success-soft text-success" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7l10 10" /><path d="M17 7v10h-10" /></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-ink">${payment.description || '-'}</div>
                                <div class="mt-0.5 text-caption text-ink-subtle">${payment.paid_at_label || '-'}</div>
                            </div>
                            <div class="shrink-0 text-sm font-bold text-ink">${payment.amount_label || '-'}</div>
                        </div>
                    `;
                });
                html += '</div>';

                container.innerHTML = html;
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
