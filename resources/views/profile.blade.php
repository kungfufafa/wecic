@extends('layouts.app')

@section('title', 'Profil')
@section('page_id', 'profile')

@section('content')
    <div class="mx-auto flex max-w-2xl flex-col gap-4">
        <header class="mb-1">
            <h1 class="mycic-page-heading">Profil & Data Diri</h1>
            <p class="mycic-page-subheading">Data mahasiswa diambil langsung dari portal kampus.</p>
        </header>

        <div class="mycic-card">
            <div class="mycic-card-body flex items-center gap-4">
                <div id="profile-avatar" class="mycic-avatar-lg shrink-0" aria-hidden="true">M</div>
                <div class="min-w-0 flex-1">
                    <h2 id="profile-name" class="truncate text-title font-bold text-ink">Memuat profil...</h2>
                    <p id="profile-meta" class="mt-0.5 text-sm text-ink-muted">Menyiapkan data mahasiswa...</p>
                    <p id="profile-status" class="mycic-badge-success mt-2 hidden"></p>
                </div>
            </div>
        </div>

        <div class="mycic-card">
            <div class="mycic-card-header flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
                </span>
                <h3 class="mycic-card-title">Data Pribadi</h3>
            </div>
            <div class="mycic-card-body" id="profile-personal">
                <div class="mycic-skeleton h-4 w-2/3"></div>
            </div>
        </div>

        <div class="mycic-card">
            <div class="mycic-card-header flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" /></svg>
                </span>
                <h3 class="mycic-card-title">Kontak</h3>
            </div>
            <div class="mycic-card-body" id="profile-contact">
                <div class="mycic-skeleton h-4 w-2/3"></div>
            </div>
        </div>

        <div class="mycic-card">
            <div class="mycic-card-header flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c0 1.11 -.895 2 -2 2a2 2 0 0 1 -2 -2v-9a7 7 0 0 1 14 0v9a2 2 0 0 1 -2 2a2 2 0 0 1 -2 -2" /><path d="M3 21v-7" /><path d="M21 21v-7" /></svg>
                </span>
                <h3 class="mycic-card-title">Pendidikan</h3>
            </div>
            <div class="mycic-card-body" id="profile-education">
                <div class="mycic-skeleton h-4 w-2/3"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const renderProfile = (payload) => {
                const profile = payload.profile || {};
                const personal = profile.personal_data || {};
                const contact = profile.contact_data || {};
                const education = profile.education_data || {};
                const avatarHost = document.getElementById('profile-avatar');
                const nameHost = document.getElementById('profile-name');
                const metaHost = document.getElementById('profile-meta');
                const statusHost = document.getElementById('profile-status');

                document.getElementById('profile-personal').innerHTML = app.renderKeyValueList([
                    { label: 'Nama', value: personal.nama },
                    { label: 'NIM', value: personal.nim },
                    { label: 'Program studi', value: personal.program_studi },
                    { label: 'Angkatan', value: personal.angkatan },
                    { label: 'Status', value: personal.status },
                    { label: 'Tempat lahir', value: personal.tempat_lahir },
                    { label: 'Tanggal lahir', value: personal.tanggal_lahir },
                    { label: 'Jenis kelamin', value: personal.jenis_kelamin },
                ]);

                document.getElementById('profile-contact').innerHTML = app.renderKeyValueList([
                    { label: 'Alamat rumah', value: contact.alamat_rumah },
                    { label: 'Kota', value: contact.kota },
                    { label: 'Provinsi', value: contact.propinsi },
                    { label: 'Kode pos', value: contact.kodepos },
                    { label: 'Telepon', value: contact.telp },
                    { label: 'HP', value: contact.hp },
                    { label: 'Email', value: contact.email },
                ]);

                document.getElementById('profile-education').innerHTML = app.renderKeyValueList([
                    { label: 'NISN', value: education.nisn },
                    { label: 'Tingkat pendidikan', value: education.tingkat_pendidikan },
                    { label: 'Asal sekolah', value: education.asal_sekolah },
                    { label: 'Alamat sekolah', value: education.alamat_sekolah },
                    { label: 'Kota sekolah', value: education.kota_sekolah },
                    { label: 'Provinsi sekolah', value: education.propinsi_sekolah },
                ]);

                const fullName = String(personal.nama || '').trim();
                const photoUrl = profile.photo_url ? app.absoluteCampusUrl(profile.photo_url) : null;

                if (photoUrl) {
                    avatarHost.innerHTML = `<img src="${app.escapeHtml(photoUrl)}" alt="Foto profil mahasiswa">`;
                } else {
                    avatarHost.textContent = (fullName || 'M').charAt(0).toUpperCase();
                }

                nameHost.textContent = fullName || 'Mahasiswa';
                metaHost.textContent = [personal.nim, personal.program_studi].filter(Boolean).join(' · ') || 'Data akademik belum tersedia.';

                if (personal.status) {
                    statusHost.textContent = personal.status;
                    statusHost.classList.remove('hidden');
                } else {
                    statusHost.classList.add('hidden');
                }
            };

            app.primeCachedView('/api/profile', renderProfile, 'profil mahasiswa');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadProfile = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/profile', { fresh: force });
                    renderProfile(payload);
                    app.markViewSynced('Profil', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('profile', loadProfile);

            await loadProfile();
        });
    </script>
@endpush
