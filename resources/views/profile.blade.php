@extends('layouts.app')

@section('title', 'Profil')
@section('page_id', 'profile')

@section('content')
    <section class="grid gap-6 lg:grid-cols-[minmax(0,0.88fr)_minmax(0,1.12fr)]">
        <div class="grid gap-6">
            <div class="app-panel">
                <p class="section-label">Profil</p>
                <h1 data-display class="mt-4 text-3xl font-semibold text-[color:var(--ink)] sm:text-4xl">Identitas Mahasiswa</h1>
                <div id="profile-hero" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <div class="empty-shell">Memuat profil.</div>
                </div>
            </div>
            <div id="profile-photo-panel" class="app-panel hidden"></div>
        </div>

        <div class="grid gap-6">
            <section class="app-panel">
                <p class="section-label">Data pribadi</p>
                <div id="profile-personal" class="mt-4">
                    <div class="empty-shell">Belum ada data pribadi.</div>
                </div>
            </section>
            <section class="app-panel">
                <p class="section-label">Kontak</p>
                <div id="profile-contact" class="mt-4">
                    <div class="empty-shell">Belum ada data kontak.</div>
                </div>
            </section>
            <section class="app-panel">
                <p class="section-label">Riwayat pendidikan</p>
                <div id="profile-education" class="mt-4">
                    <div class="empty-shell">Belum ada data pendidikan.</div>
                </div>
            </section>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            try {
                const payload = await app.request('/api/profile');
                const profile = payload.profile || {};
                const personal = profile.personal_data || {};
                const contact = profile.contact_data || {};
                const education = profile.education_data || {};

                document.getElementById('profile-hero').innerHTML = `
                    <article class="app-panel-muted sm:col-span-2 lg:col-span-1">
                        <p class="section-label">Nama</p>
                        <p class="mt-3 text-2xl font-semibold text-[color:var(--ink)]">${app.escapeHtml(personal.nama || 'Mahasiswa')}</p>
                    </article>
                    <article class="app-panel-muted">
                        <p class="section-label">NIM</p>
                        <p class="mt-3 text-sm font-medium text-[color:var(--ink)]">${app.escapeHtml(personal.nim || '-')}</p>
                    </article>
                    <article class="app-panel-muted">
                        <p class="section-label">Program studi</p>
                        <p class="mt-3 text-sm font-medium text-[color:var(--ink)]">${app.escapeHtml(personal.program_studi || '-')}</p>
                    </article>
                `;

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

                if (profile.photo_url) {
                    const photoPanel = document.getElementById('profile-photo-panel');
                    photoPanel.classList.remove('hidden');
                    photoPanel.innerHTML = `
                        <p class="section-label">Foto profil</p>
                        <div class="mt-4 overflow-hidden rounded-2xl border border-[color:var(--line)]">
                            <img src="${app.absoluteCampusUrl(profile.photo_url)}" alt="Foto profil mahasiswa" class="h-auto w-full object-cover">
                        </div>
                    `;
                }
            } catch (error) {
                app.showNotice(error.payload?.message || error.message, 'error');
            }
        });
    </script>
@endpush
