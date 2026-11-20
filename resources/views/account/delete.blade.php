@extends('layouts.app')

@section('title', 'Hapus Akun')
@section('page_id', 'account-delete')
{{-- Nothing on this page comes from a dataset, so there is nothing to sync. --}}
@section('hide_sync_refresh', '1')

@section('content')
    <x-page-hero title="Hapus akun" subtitle="Hapus akun WeCIC dan semua data yang disimpan server WeCIC untukmu" />

    <x-split at="xl">
        <x-card as="section" title="Yang ikut dihapus" title-id="account-delete-title" aria-labelledby="account-delete-title">
            <x-card.body class="flex flex-col gap-4 text-sm text-ink-muted">
                <ul class="flex list-disc flex-col gap-2.5 ps-5 leading-relaxed marker:text-ink-subtle">
                    <li>Arsip data portal dan semua riwayat versinya, termasuk berkas materi kuliah yang tersimpan.</li>
                    <li>Notebook beserta sumber, berkas unggahan, dan percakapannya.</li>
                    <li>Akses offline: hash password dan password terenkripsi untuk sinkronisasi di background.</li>
                    <li>Notifikasi, token notifikasi push, antrean offline, dan catatan unggahan ujian.</li>
                    <li>Data portal yang tersimpan sementara di server, serta semua sesi web dan login aplikasi di setiap perangkat.</li>
                </ul>
                <p class="leading-relaxed">Akun portal akademik kampusmu sendiri tidak tersentuh. Kamu bisa masuk lagi ke WeCIC kapan saja, tetapi data yang dihapus tidak bisa dikembalikan.</p>
            </x-card.body>
            <x-card.footer>
                <x-button variant="destructive" size="sm" id="account-delete">Hapus akun dan semua data saya</x-button>
            </x-card.footer>
        </x-card>
    </x-split>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const deleteButton = document.getElementById('account-delete');

            deleteButton.addEventListener('click', () => {
                if (!window.confirm('Hapus akun WeCIC dan semua datamu? Kamu akan keluar dari semua perangkat dan data tidak bisa dikembalikan.')) {
                    return;
                }

                app.isLoggingOut = true;

                app.withSubmitLoading(deleteButton, async () => {
                    try {
                        const payload = await app.request('/api/account', {
                            method: 'DELETE',
                            json: { confirm: true },
                            redirectOnAuth: false,
                            timeout: 45000,
                        });

                        app.storeNotice(payload?.message || 'Akun WeCIC kamu sudah dihapus.', 'success');
                        app.disconnectRealtime();
                        app.clearOfflineData();
                        window.location.href = '/login';
                    } catch (error) {
                        app.isLoggingOut = false;
                        app.showNotice(`Akun gagal dihapus. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            await app.ensureAuthenticated();
        });
    </script>
@endpush
