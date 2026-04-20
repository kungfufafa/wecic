@extends('layouts.app')

@section('title', 'Profil')
@section('page_id', 'profile')

@section('content')
    <section class="page-shell">
        <section id="profile-hero" class="page-hero">
            <div class="empty-shell">Memuat identitas mahasiswa.</div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <section id="profile-photo-panel" class="section-shell hidden"></section>
            <div class="grid gap-6">
                <section class="section-shell">
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Data pribadi</h2>
                            <p class="section-copy">Informasi inti mahasiswa yang digunakan sebagai rujukan identitas akademik di portal.</p>
                        </div>
                    </div>
                    <div id="profile-personal" class="mt-6">
                        <div class="empty-shell">Belum ada data pribadi.</div>
                    </div>
                </section>
                <section class="section-shell">
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Kontak</h2>
                            <p class="section-copy">Saluran komunikasi dan alamat yang tersimpan pada data kampus aktif.</p>
                        </div>
                    </div>
                    <div id="profile-contact" class="mt-6">
                        <div class="empty-shell">Belum ada data kontak.</div>
                    </div>
                </section>
                <section class="section-shell">
                    <div class="section-head">
                        <div class="section-head-main">
                            <h2 data-display class="section-title">Pendidikan</h2>
                            <p class="section-copy">Riwayat pendidikan dasar yang dipakai sistem untuk validasi data mahasiswa.</p>
                        </div>
                    </div>
                    <div id="profile-education" class="mt-6">
                        <div class="empty-shell">Belum ada data pendidikan.</div>
                    </div>
                </section>
            </div>
        </div>
    </section>
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
                const photoPanel = document.getElementById('profile-photo-panel');
                const hero = document.getElementById('profile-hero');

                hero.innerHTML = `
                    <div class="page-hero-shell">
                        <div class="page-hero-main">
                            <p class="section-label">Profil mahasiswa</p>
                            <h1 data-display class="page-hero-title is-wide">${app.escapeHtml(personal.nama || 'Profil mahasiswa')}</h1>
                            <p class="page-hero-copy">
                                ${app.escapeHtml(
                                    [
                                        personal.program_studi,
                                        personal.status,
                                        personal.angkatan ? `angkatan ${personal.angkatan}` : null,
                                    ].filter(Boolean).join(' · ') || 'Data profil akademik mahasiswa aktif.'
                                )}
                            </p>
                            <div class="page-meta-row">
                                ${personal.nim ? `<span class="meta-pill is-accent">${app.escapeHtml(personal.nim)}</span>` : ''}
                                ${personal.status ? `<span class="meta-pill">${app.escapeHtml(personal.status)}</span>` : ''}
                                ${personal.angkatan ? `<span class="meta-pill">${app.escapeHtml(`Angkatan ${personal.angkatan}`)}</span>` : ''}
                            </div>
                        </div>
                        <div class="page-hero-grid lg:w-[22rem]">
                            <div class="page-hero-panel">
                                <p class="section-label">Kontak utama</p>
                                <p class="page-hero-panel-title">${app.escapeHtml(contact.email || contact.hp || 'Belum ada kontak utama')}</p>
                                <p class="page-hero-panel-copy">${app.escapeHtml(contact.kota || contact.propinsi || 'Tambahkan data kontak di portal kampus jika masih kosong.')}</p>
                            </div>
                            <div class="page-hero-panel">
                                <p class="section-label">Asal pendidikan</p>
                                <p class="page-hero-panel-title">${app.escapeHtml(education.asal_sekolah || 'Belum ada asal sekolah')}</p>
                                <p class="page-hero-panel-copy">${app.escapeHtml(education.tingkat_pendidikan || education.kota_sekolah || 'Riwayat pendidikan dasar belum lengkap.')}</p>
                            </div>
                        </div>
                    </div>
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
                    photoPanel.classList.remove('hidden');
                    photoPanel.innerHTML = `
                        <div class="section-head">
                            <div class="section-head-main">
                                <h2 data-display class="section-title">Foto profil</h2>
                                <p class="section-copy">Pratinjau foto mahasiswa yang tersimpan di sistem akademik CIC.</p>
                            </div>
                        </div>
                        <div class="mt-6 overflow-hidden rounded-[1rem] border border-[color:var(--line-soft)] bg-[color:var(--surface-muted)]">
                            <img src="${app.absoluteCampusUrl(profile.photo_url)}" alt="Foto profil mahasiswa" class="h-auto w-full object-cover">
                        </div>
                    `;
                } else {
                    photoPanel.classList.add('hidden');
                    photoPanel.innerHTML = '';
                }
            };

            app.primeCachedView('/api/profile', renderProfile, 'profil mahasiswa');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadProfile = async () => {
                try {
                    const payload = await app.request('/api/profile');
                    renderProfile(payload);
                    app.markViewSynced('Profil');
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                }
            };

            app.registerSyncHandler('profile', loadProfile);

            await loadProfile();
        });
    </script>
@endpush
