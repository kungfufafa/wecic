@extends('layouts.app')

@section('title', 'Profil')
@section('page_id', 'profile')

@section('content')
    <div class="flex flex-col gap-5">
        {{-- Profile Hero Banner --}}
        <section class="mycic-hero relative overflow-hidden" aria-label="Profil Mahasiswa">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <div id="profile-avatar" class="flex h-16 w-16 sm:h-20 sm:w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border-2 border-white/25 bg-white/15 text-2xl font-extrabold text-white shadow-md backdrop-blur-sm" aria-hidden="true">
                        M
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 id="profile-name" class="break-words text-xl font-bold leading-tight text-white sm:text-2xl">Memuat...</h1>
                            <span id="profile-status" class="inline-flex items-center rounded-md bg-emerald-400/20 border border-emerald-300/30 px-2.5 py-0.5 text-xs font-semibold text-emerald-100 hidden"></span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/80">
                            <span class="inline-flex items-center gap-1 font-mono">
                                <span id="profile-nim-display">-</span>
                                <button type="button" id="copy-nim-btn" class="-my-2 inline-flex h-10 w-10 items-center justify-center rounded-lg text-white/70 transition-colors hover:bg-white/15 hover:text-white" title="Salin NIM" aria-label="Salin NIM">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                                </button>
                            </span>
                            <span class="text-white/40">·</span>
                            <span id="profile-meta" class="break-words text-white/85"></span>
                        </div>
                    </div>
                </div>

                {{-- Quick Actions --}}
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('password.edit') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition-colors hover:bg-white/20">
                        Password
                    </a>
                    <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition-colors hover:bg-white/20">
                        Portal
                    </a>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="mycic-metric">
                <div class="mycic-metric-label">NIM</div>
                <div class="mycic-metric-value text-base sm:text-lg font-mono" id="quick-nim">
                    <div class="mycic-skeleton h-6 w-24"></div>
                </div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Prodi</div>
                <div class="mycic-metric-value text-base sm:text-lg line-clamp-2 leading-tight" id="quick-prodi">
                    <div class="mycic-skeleton h-6 w-28"></div>
                </div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Angkatan</div>
                <div class="mycic-metric-value text-base sm:text-lg" id="quick-angkatan">
                    <div class="mycic-skeleton h-6 w-16"></div>
                </div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Status</div>
                <div class="mycic-metric-value text-base sm:text-lg" id="quick-status">
                    <div class="mycic-skeleton h-6 w-20"></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="flex flex-col gap-5">
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h2 class="mycic-card-title">Data pribadi</h2>
                    </div>
                    <div class="mycic-card-body" id="profile-personal">
                        <div class="flex flex-col gap-3">
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-4 w-1/2"></div>
                            <div class="mycic-skeleton h-4 w-2/3"></div>
                        </div>
                    </div>
                </div>

                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h2 class="mycic-card-title">Pendidikan</h2>
                    </div>
                    <div class="mycic-card-body" id="profile-education">
                        <div class="flex flex-col gap-3">
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-4 w-1/2"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-5">
                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h2 class="mycic-card-title">Kontak</h2>
                    </div>
                    <div class="mycic-card-body" id="profile-contact">
                        <div class="flex flex-col gap-3">
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-4 w-1/2"></div>
                            <div class="mycic-skeleton h-4 w-2/3"></div>
                        </div>
                    </div>
                </div>

                <div class="mycic-card">
                    <div class="mycic-card-header">
                        <h2 class="mycic-card-title">Keamanan</h2>
                    </div>
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-4 py-2 border-b border-border-light">
                            <div class="text-sm font-semibold text-ink">Password</div>
                            <a href="{{ route('password.edit') }}" class="mycic-btn-secondary-sm shrink-0">Ubah</a>
                        </div>
                        <div class="flex items-center justify-between gap-4 py-2">
                            <div class="text-sm font-semibold text-ink">Portal kampus</div>
                            <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="mycic-btn-secondary-sm shrink-0">Buka</a>
                        </div>
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

            const copyNimBtn = document.getElementById('copy-nim-btn');
            let currentNim = '';

            const copyToClipboard = async (text, label) => {
                if (!text || text === '-') return;
                try {
                    await navigator.clipboard.writeText(text);
                    app.showNotice(`${label} berhasil disalin.`, 'success');
                } catch {
                    const temp = document.createElement('textarea');
                    temp.value = text;
                    document.body.appendChild(temp);
                    temp.select();
                    document.execCommand('copy');
                    document.body.removeChild(temp);
                    app.showNotice(`${label} berhasil disalin.`, 'success');
                }
            };

            copyNimBtn?.addEventListener('click', () => {
                copyToClipboard(currentNim, 'NIM');
            });

            const renderProfile = (payload) => {
                const profile = payload.profile || {};
                const personal = profile.personal_data || {};
                const contact = profile.contact_data || {};
                const education = profile.education_data || {};
                const avatarHost = document.getElementById('profile-avatar');
                const nameHost = document.getElementById('profile-name');
                const metaHost = document.getElementById('profile-meta');
                const statusHost = document.getElementById('profile-status');
                const nimDisplay = document.getElementById('profile-nim-display');

                currentNim = personal.nim || '';
                if (nimDisplay) {
                    nimDisplay.textContent = currentNim || '-';
                }

                // Quick metrics
                const quickNim = document.getElementById('quick-nim');
                const quickProdi = document.getElementById('quick-prodi');
                const quickAngkatan = document.getElementById('quick-angkatan');
                const quickStatus = document.getElementById('quick-status');

                if (quickNim) quickNim.textContent = personal.nim || '-';
                if (quickProdi) quickProdi.textContent = personal.program_studi || '-';
                if (quickAngkatan) quickAngkatan.textContent = personal.angkatan || '-';
                if (quickStatus) quickStatus.textContent = personal.status || 'Aktif';

                // Data Pribadi
                document.getElementById('profile-personal').innerHTML = app.renderKeyValueList([
                    { label: 'Nama', value: personal.nama },
                    { label: 'NIM', value: personal.nim },
                    { label: 'Prodi', value: personal.program_studi },
                    { label: 'Angkatan', value: personal.angkatan },
                    { label: 'Status', value: personal.status },
                    { label: 'Tempat lahir', value: personal.tempat_lahir },
                    { label: 'Tgl lahir', value: personal.tanggal_lahir },
                    { label: 'Kelamin', value: personal.jenis_kelamin },
                ]);

                document.getElementById('profile-contact').innerHTML = app.renderKeyValueList([
                    { label: 'Email', value: contact.email },
                    { label: 'HP', value: contact.hp },
                    { label: 'Telepon', value: contact.telp },
                    { label: 'Alamat', value: contact.alamat_rumah },
                    { label: 'Kota', value: contact.kota },
                    { label: 'Provinsi', value: contact.propinsi },
                    { label: 'Kode pos', value: contact.kodepos },
                ]);

                document.getElementById('profile-education').innerHTML = app.renderKeyValueList([
                    { label: 'NISN', value: education.nisn },
                    { label: 'Tingkat', value: education.tingkat_pendidikan },
                    { label: 'Asal sekolah', value: education.asal_sekolah },
                    { label: 'Alamat', value: education.alamat_sekolah },
                    { label: 'Kota', value: education.kota_sekolah },
                    { label: 'Provinsi', value: education.propinsi_sekolah },
                ]);

                const fullName = String(personal.nama || '').trim();
                const photoUrl = profile.photo_url ? app.absoluteCampusUrl(profile.photo_url) : null;

                if (avatarHost) {
                    if (photoUrl) {
                        avatarHost.innerHTML = `<img src="${app.escapeHtml(photoUrl)}" alt="Foto profil mahasiswa" class="h-full w-full object-cover">`;
                    } else {
                        avatarHost.textContent = (fullName || 'M').charAt(0).toUpperCase();
                    }
                }

                if (nameHost) {
                    nameHost.textContent = fullName || 'Mahasiswa';
                }

                if (metaHost) {
                    metaHost.textContent = [personal.program_studi, personal.angkatan].filter(Boolean).join(' · ');
                }

                if (statusHost) {
                    if (personal.status) {
                        statusHost.textContent = personal.status;
                        statusHost.classList.remove('hidden');
                    } else {
                        statusHost.classList.add('hidden');
                    }
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
