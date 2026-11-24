@extends('layouts.app')

@section('title', 'Hapus Akun')
@section('page_id', 'account-delete')
{{-- Nothing on this page comes from a dataset, so there is nothing to sync. --}}
@section('hide_sync_refresh', '1')

@section('content')
    <div class="mx-auto w-full max-w-2xl">
        <a
            href="{{ route('profile') }}"
            class="mb-3 inline-flex min-h-9 items-center gap-1.5 rounded-md text-xs font-medium text-ink-subtle transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
        >
            <x-icon name="chevron-left" class="size-3.5!" width="14" height="14" />
            <span>Kembali ke Profil</span>
        </a>

        <x-page-hero title="Hapus akun" />

        <x-card as="section" title="Yang ikut dihapus" title-id="account-delete-title" aria-labelledby="account-delete-title">
            <x-card.body class="flex flex-col gap-4 text-sm text-ink-muted">
                <ul class="flex list-disc flex-col gap-2 ps-5 leading-relaxed marker:text-ink-subtle">
                    <li>Arsip data portal, riwayat versi, dan berkas materi.</li>
                    <li>Notebook, sumber, unggahan, dan percakapan.</li>
                    <li>Akses offline dan sinkronisasi di background.</li>
                    <li>Notifikasi, antrean offline, dan semua sesi login.</li>
                </ul>
                <p class="leading-relaxed">Akun portal kampus tidak terpengaruh. Data yang dihapus tidak bisa dikembalikan.</p>
            </x-card.body>
            <x-card.footer>
                <x-button variant="destructive" size="sm" id="account-delete">Hapus akun saya</x-button>
            </x-card.footer>
        </x-card>
    </div>
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
