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
                            <h1 id="profile-name" class="truncate text-xl font-bold text-white sm:text-2xl">Memuat profil...</h1>
                            <span id="profile-status" class="inline-flex items-center rounded-md bg-emerald-400/20 border border-emerald-300/30 px-2.5 py-0.5 text-xs font-semibold text-emerald-100 hidden"></span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/80">
                            <span class="inline-flex items-center gap-1 font-mono">
                                <span id="profile-nim-display">-</span>
                                <button type="button" id="copy-nim-btn" class="inline-flex items-center rounded p-1 text-white/70 hover:bg-white/15 hover:text-white transition-colors" title="Salin NIM" aria-label="Salin NIM">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                                </button>
                            </span>
                            <span class="text-white/40">·</span>
                            <span id="profile-meta" class="truncate text-white/85">Portal Mahasiswa</span>
                        </div>
                    </div>
                </div>

                {{-- Quick Actions --}}
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('password.edit') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition-colors hover:bg-white/20">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
                        <span>Ubah Password</span>
                    </a>
                    <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition-colors hover:bg-white/20">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
                        <span>Portal Kampus</span>
                    </a>
                </div>
            </div>
        </section>

        {{-- Academic Summary Metric Cards --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="mycic-metric">
                <div class="mycic-metric-label">NIM</div>
                <div class="mycic-metric-value text-base sm:text-lg font-mono" id="quick-nim">
                    <div class="mycic-skeleton h-6 w-24"></div>
                </div>
                <div class="mycic-metric-note">Nomor Induk Mahasiswa</div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Program Studi</div>
                <div class="mycic-metric-value text-base sm:text-lg truncate" id="quick-prodi">
                    <div class="mycic-skeleton h-6 w-28"></div>
                </div>
                <div class="mycic-metric-note">Program sarjana aktif</div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Angkatan</div>
                <div class="mycic-metric-value text-base sm:text-lg" id="quick-angkatan">
                    <div class="mycic-skeleton h-6 w-16"></div>
                </div>
                <div class="mycic-metric-note">Tahun masuk kuliah</div>
            </div>
            <div class="mycic-metric">
                <div class="mycic-metric-label">Status Akademik</div>
                <div class="mycic-metric-value text-base sm:text-lg" id="quick-status">
                    <div class="mycic-skeleton h-6 w-20"></div>
                </div>
                <div class="mycic-metric-note">Status registrasi portal</div>
            </div>
        </div>

        {{-- Detail Grid: 2 Columns on LG --}}
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            {{-- Column 1: Personal & Education --}}
            <div class="flex flex-col gap-5">
                {{-- Data Pribadi --}}
                <div class="mycic-card">
                    <div class="mycic-card-header flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
                        </span>
                        <div>
                            <h2 class="mycic-card-title">Data Pribadi & Akademik</h2>
                            <p class="text-caption text-ink-subtle">Informasi identitas resmi mahasiswa</p>
                        </div>
                    </div>
                    <div class="mycic-card-body" id="profile-personal">
                        <div class="flex flex-col gap-3">
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-4 w-1/2"></div>
                            <div class="mycic-skeleton h-4 w-2/3"></div>
                            <div class="mycic-skeleton h-4 w-3/5"></div>
                        </div>
                    </div>
                </div>

                {{-- Riwayat Pendidikan --}}
                <div class="mycic-card">
                    <div class="mycic-card-header flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M22 9l-10 -4l-10 4l10 4l10 -4v6" /><path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4" /></svg>
                        </span>
                        <div>
                            <h2 class="mycic-card-title">Pendidikan Terakhir</h2>
                            <p class="text-caption text-ink-subtle">Riwayat asal sekolah dan NISN</p>
                        </div>
                    </div>
                    <div class="mycic-card-body" id="profile-education">
                        <div class="flex flex-col gap-3">
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-4 w-1/2"></div>
                            <div class="mycic-skeleton h-4 w-2/3"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Column 2: Contact & Security --}}
            <div class="flex flex-col gap-5">
                {{-- Data Kontak --}}
                <div class="mycic-card">
                    <div class="mycic-card-header flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" /></svg>
                        </span>
                        <div>
                            <h2 class="mycic-card-title">Kontak & Domisili</h2>
                            <p class="text-caption text-ink-subtle">Saluran komunikasi dan alamat tempat tinggal</p>
                        </div>
                    </div>
                    <div class="mycic-card-body" id="profile-contact">
                        <div class="flex flex-col gap-3">
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-4 w-1/2"></div>
                            <div class="mycic-skeleton h-4 w-2/3"></div>
                            <div class="mycic-skeleton h-4 w-3/5"></div>
                        </div>
                    </div>
                </div>

                {{-- Keamanan & Pengaturan Akun --}}
                <div class="mycic-card">
                    <div class="mycic-card-header flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-tint text-brand-pressed" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                        </span>
                        <div>
                            <h2 class="mycic-card-title">Keamanan Akun</h2>
                            <p class="text-caption text-ink-subtle">Akses akun dan kredensial portal</p>
                        </div>
                    </div>
                    <div class="mycic-card-body flex flex-col gap-4">
                        <div class="flex items-start justify-between gap-4 py-2 border-b border-border-light">
                            <div>
                                <div class="text-sm font-semibold text-ink">Kata Sandi Akun</div>
                                <div class="text-caption text-ink-subtle">Ganti kata sandi login secara berkala untuk menjaga keamanan.</div>
                            </div>
                            <a href="{{ route('password.edit') }}" class="mycic-btn-secondary-sm shrink-0">
                                Ubah
                            </a>
                        </div>
                        <div class="flex items-start justify-between gap-4 py-2 border-b border-border-light">
                            <div>
                                <div class="text-sm font-semibold text-ink">Sistem Portal Asli</div>
                                <div class="text-caption text-ink-subtle">Buka halaman web SiPANDAI / myUCIC di tab baru.</div>
                            </div>
                            <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="mycic-btn-secondary-sm shrink-0">
                                Kunjungi
                            </a>
                        </div>
                        <div class="rounded-xl border border-border bg-surface p-3 text-xs text-ink-subtle">
                            Data profil disinkronkan otomatis dari portal universitas. Jika terdapat kekeliruan data diri resmi, silakan hubungi Biro Administrasi Akademik (BAAK) UCIC.
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
                    { label: 'Nama Lengkap', value: personal.nama },
                    { label: 'NIM', value: personal.nim },
                    { label: 'Program Studi', value: personal.program_studi },
                    { label: 'Angkatan', value: personal.angkatan },
                    { label: 'Status Akademik', value: personal.status },
                    { label: 'Tempat Lahir', value: personal.tempat_lahir },
                    { label: 'Tanggal Lahir', value: personal.tanggal_lahir },
                    { label: 'Jenis Kelamin', value: personal.jenis_kelamin },
                ]);

                // Data Kontak
                document.getElementById('profile-contact').innerHTML = app.renderKeyValueList([
                    { label: 'Email Mahasiswa', value: contact.email },
                    { label: 'No. Handphone / WhatsApp', value: contact.hp },
                    { label: 'No. Telepon Rumah', value: contact.telp },
                    { label: 'Alamat Domisili', value: contact.alamat_rumah },
                    { label: 'Kota', value: contact.kota },
                    { label: 'Provinsi', value: contact.propinsi },
                    { label: 'Kode Pos', value: contact.kodepos },
                ]);

                // Riwayat Pendidikan
                document.getElementById('profile-education').innerHTML = app.renderKeyValueList([
                    { label: 'NISN', value: education.nisn },
                    { label: 'Tingkat Pendidikan', value: education.tingkat_pendidikan },
                    { label: 'Asal Sekolah', value: education.asal_sekolah },
                    { label: 'Alamat Sekolah', value: education.alamat_sekolah },
                    { label: 'Kota Sekolah', value: education.kota_sekolah },
                    { label: 'Provinsi Sekolah', value: education.propinsi_sekolah },
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
                    metaHost.textContent = [personal.program_studi, personal.angkatan ? `Angkatan ${personal.angkatan}` : null].filter(Boolean).join(' · ') || 'Portal Mahasiswa';
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
