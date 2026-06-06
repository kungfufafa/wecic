@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@section('content')
    <div class="grid grid-cols-1 gap-4">
        <div class="col-span-12" id="finance-main-panel">
            <div class="mycic-card">
                <div class="mycic-card-header">
                    <h3 class="mycic-card-title">Riwayat Pembayaran</h3>
                </div>
                <div class="mycic-card-body" id="finance-payments">
                    {!! '<div class="mycic-alert-neutral mb-0">Memuat data pembayaran...</div>' !!}
                </div>
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
                html += '<thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th>Jumlah</th></tr></thead><tbody>';

                payments.forEach((payment, index) => {
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${payment.paid_at_label || '-'}</td>
                            <td>${payment.description || '-'}</td>
                            <td>${payment.amount_label || '-'}</td>
                        </tr>
                    `;
                });

                html += '</tbody></table></div></div>';

                html += '<div class="flex flex-col gap-3 md:hidden">';
                payments.forEach((payment, index) => {
                    html += `
                        <div class="mycic-card">
                            <div class="mycic-card-body">
                                <div class="flex items-start justify-between gap-2">
                                    <span class="text-caption text-ink-subtle">#${index + 1}</span>
                                    <span class="font-medium text-ink">${payment.amount_label || '-'}</span>
                                </div>
                                <div class="mt-1 text-sm font-medium text-ink">${payment.description || '-'}</div>
                                <div class="mt-1 text-caption text-ink-subtle">${payment.paid_at_label || '-'}</div>
                            </div>
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
