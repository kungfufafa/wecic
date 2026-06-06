@extends('layouts.app')

@section('title', 'Profil')
@section('page_id', 'profile')

@section('content')
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div class="hidden lg:col-span-4" id="profile-photo-panel"></div>

        <div class="col-span-12" id="profile-main-panel">
            <div class="grid grid-cols-1 gap-4">
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h3 class="mycic-card-title">Data pribadi</h3>
                    </div>
                    <div class="mycic-card-body" id="profile-personal">
                        {!! '<div class="mycic-alert-neutral mb-0">Belum ada data pribadi.</div>' !!}
                    </div>
                </div>

                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h3 class="mycic-card-title">Kontak</h3>
                    </div>
                    <div class="mycic-card-body" id="profile-contact">
                        {!! '<div class="mycic-alert-neutral mb-0">Belum ada data kontak.</div>' !!}
                    </div>
                </div>

                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h3 class="mycic-card-title">Pendidikan</h3>
                    </div>
                    <div class="mycic-card-body" id="profile-education">
                        {!! '<div class="mycic-alert-neutral mb-0">Belum ada data pendidikan.</div>' !!}
                    </div>
                </div>
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
                const photoPanel = document.getElementById('profile-photo-panel');
                const mainPanel = document.getElementById('profile-main-panel');

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
                    mainPanel.classList.remove('col-span-12');
                    mainPanel.classList.add('lg:col-span-8');
                    photoPanel.innerHTML = `
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h3 class="mycic-card-title">Foto profil</h3>
                            </div>
                            <div class="mycic-card-body">
                                <img src="${app.absoluteCampusUrl(profile.photo_url)}" alt="Foto profil mahasiswa" class="w-full rounded-lg">
                            </div>
                        </div>
                    `;
                } else {
                    photoPanel.classList.add('hidden');
                    photoPanel.innerHTML = '';
                    mainPanel.classList.remove('lg:col-span-8');
                    mainPanel.classList.add('col-span-12');
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
