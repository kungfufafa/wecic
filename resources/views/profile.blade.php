@extends('layouts.app')

@section('title', 'Profil')
@section('page_id', 'profile')

@section('content')
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
        {{-- The page header: the photo, the name (the h1) with its status, the NIM and prodi; the account actions on the right. --}}
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between" aria-label="Profil Mahasiswa" data-slot="page-hero">
            <div class="flex min-w-0 items-center gap-4">
                <x-avatar size="lg" id="profile-avatar" aria-hidden="true">M</x-avatar>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 id="profile-name" class="break-words text-xl font-semibold tracking-tight text-ink">Memuat...</h1>
                        {{-- The badge is inline-flex, which beats `hidden`: the script shows and hides this wrapper. --}}
                        <span class="hidden" data-profile-status-wrap>
                            <x-badge tone="success" id="profile-status"></x-badge>
                        </span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-1.5 text-sm text-ink-muted">
                        <span class="inline-flex items-center gap-1">
                            <span id="profile-nim-display" class="font-mono">-</span>
                            <x-button variant="icon" id="copy-nim-btn" title="Salin NIM" aria-label="Salin NIM">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                            </x-button>
                        </span>
                        <span id="profile-meta" class="break-words before:me-1.5 before:content-['·'] empty:hidden"></span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <x-button :href="route('password.edit')" variant="secondary" size="sm"><x-icon name="lock" size="sm" />Ubah password</x-button>
                <x-button :href="config('portal.base_url')" target="_blank" rel="noopener noreferrer" variant="secondary" size="sm"><x-icon name="external-link" size="sm" />Portal kampus</x-button>
            </div>
        </header>

        {{-- The details the header does not show, stacked in horizontal divided rows like Devin Preferences. --}}
        <div class="flex flex-col gap-6">
            <x-card as="section" title="Data pribadi" title-id="profile-personal-title" aria-labelledby="profile-personal-title">
                <x-card.body id="profile-personal">
                    <div class="flex flex-col divide-y divide-border-light">
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-24" />
                            <x-skeleton class="h-4 w-36" />
                        </div>
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-28" />
                            <x-skeleton class="h-4 w-24" />
                        </div>
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-20" />
                            <x-skeleton class="h-4 w-32" />
                        </div>
                    </div>
                </x-card.body>
            </x-card>

            <x-card as="section" title="Kontak" title-id="profile-contact-title" aria-labelledby="profile-contact-title">
                <x-card.body id="profile-contact">
                    <div class="flex flex-col divide-y divide-border-light">
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-20" />
                            <x-skeleton class="h-4 w-44" />
                        </div>
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-24" />
                            <x-skeleton class="h-4 w-32" />
                        </div>
                    </div>
                </x-card.body>
            </x-card>

            <x-card as="section" title="Pendidikan" title-id="profile-education-title" aria-labelledby="profile-education-title">
                <x-card.body id="profile-education">
                    <div class="flex flex-col divide-y divide-border-light">
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-24" />
                            <x-skeleton class="h-4 w-40" />
                        </div>
                        <div class="flex items-center justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                            <x-skeleton class="h-4 w-28" />
                            <x-skeleton class="h-4 w-28" />
                        </div>
                    </div>
                </x-card.body>
            </x-card>
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

            let profileRendered = false;

            // Labelled details in Devin-style horizontal divided rows; empty fields are left out.
            const renderDetails = (entries = []) => {
                const rows = entries
                    .filter((entry) => entry.value !== undefined && entry.value !== null && entry.value !== '')
                    .map((entry) => `
                        <div class="flex flex-col gap-1 py-3.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                            <dt class="text-sm font-medium text-ink">${app.escapeHtml(entry.label)}</dt>
                            <dd class="break-words text-sm text-ink-muted sm:text-end">${app.escapeHtml(entry.value)}</dd>
                        </div>
                    `)
                    .join('');

                return rows
                    ? `<dl class="flex flex-col divide-y divide-border-light">${rows}</dl>`
                    : app.renderEmptyState('Tidak ada data.', 'secondary');
            };

            const renderProfile = (payload) => {
                profileRendered = true;
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

                // Data pribadi: the name, NIM, prodi, angkatan and status are in the header already.
                document.getElementById('profile-personal').innerHTML = renderDetails([
                    { label: 'Tempat lahir', value: personal.tempat_lahir },
                    { label: 'Tgl lahir', value: personal.tanggal_lahir },
                    { label: 'Kelamin', value: personal.jenis_kelamin },
                ]);

                document.getElementById('profile-contact').innerHTML = renderDetails([
                    { label: 'Email', value: contact.email },
                    { label: 'HP', value: contact.hp },
                    { label: 'Telepon', value: contact.telp },
                    { label: 'Alamat', value: contact.alamat_rumah },
                    { label: 'Kota', value: contact.kota },
                    { label: 'Provinsi', value: contact.propinsi },
                    { label: 'Kode pos', value: contact.kodepos },
                ]);

                document.getElementById('profile-education').innerHTML = renderDetails([
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
                        avatarHost.innerHTML = `<img src="${app.escapeHtml(photoUrl)}" alt="Foto profil mahasiswa" class="size-full object-cover">`;
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
                    const statusWrap = statusHost.closest('[data-profile-status-wrap]') || statusHost;

                    if (personal.status) {
                        statusHost.textContent = personal.status;
                        statusWrap.classList.remove('hidden');
                    } else {
                        statusWrap.classList.add('hidden');
                    }
                }
            };

            app.primeCachedView('/api/profile', renderProfile, 'profil mahasiswa');

            const ready = await app.ensureAuthenticated();
            if (!ready) {
                return;
            }

            const loadProfile = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/profile', { fresh: force, background });
                    renderProfile(payload);
                    app.markViewSynced('Profil', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Profil gagal dimuat.', 'error');

                    if (profileRendered) {
                        app.showNotice(error.payload?.message || error.message, 'error');

                        return;
                    }

                    // Nothing on screen yet: replace the skeletons instead of leaving them spinning.
                    document.getElementById('profile-name').textContent = 'Profil gagal dimuat';
                    ['profile-contact', 'profile-education'].forEach((id) => {
                        document.getElementById(id).innerHTML = app.renderEmptyState('Data belum termuat.', 'secondary');
                    });
                    app.mountErrorState(document.getElementById('profile-personal'), 'Profil gagal dimuat.', () => loadProfile({ force }), {
                        subtitle: app.describeError(error),
                    });
                }
            };

            app.registerSyncHandler('profile', loadProfile);
            await loadProfile();
        });
    </script>
@endpush
