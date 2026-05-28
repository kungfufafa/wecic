@extends('layouts.app')

@section('title', 'Keuangan')
@section('page_id', 'finance')

@section('content')
    <div class="row row-cards">
        <div class="col-12" id="finance-main-panel">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Riwayat Pembayaran</h3>
                </div>
                <div class="card-body" id="finance-payments">
                    {!! '<div class="alert alert-secondary mb-0">Memuat data pembayaran...</div>' !!}
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
                    container.innerHTML = '<div class="alert alert-info mb-0">Belum ada riwayat pembayaran.</div>';
                    return;
                }

                let html = '<div class="table-responsive"><table class="table table-vcenter card-table text-nowrap">';
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

                html += '</tbody></table></div>';
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
