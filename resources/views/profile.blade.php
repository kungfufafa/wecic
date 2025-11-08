@extends('layouts.app')

@section('title', 'Profile - Portal CIC')

@section('content')
<x-page-shell title="Profil Mahasiswa" subtitle="Informasi Pribadi Mahasiswa CIC" active="profile">
    <x-async-card title="Informasi Mahasiswa" subtitle="Data profil mahasiswa CIC">
        <x-slot:action>
            <button onclick="loadProfileData()" class="inline-flex items-center justify-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm hover:shadow-md transition-all duration-200 text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9H4m0 0V4m0 5a8.003 8.003 0 0015.356 2H20m0 0v5m0-5h-.582"/>
                </svg>
                Refresh Data
            </button>
        </x-slot:action>

        <div id="profileContent">
                            <!-- Shimmer Loading State -->
                            <div class="space-y-6 animate-pulse">
                                <div class="grid gap-6 lg:grid-cols-3">
                                    <div class="rounded-2xl border border-gray-200 bg-white/80 p-6 space-y-4 lg:col-span-2">
                                        <div class="flex flex-col gap-4 md:flex-row md:items-center">
                                            <div class="w-24 h-24 bg-gray-200 rounded-full shimmer"></div>
                                            <div class="flex-1 space-y-3">
                                                <div class="h-5 w-48 bg-gray-200 rounded shimmer"></div>
                                                <div class="h-4 w-32 bg-gray-200 rounded shimmer"></div>
                                                <div class="grid grid-cols-2 gap-3">
                                                    <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                                    <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div class="h-16 bg-gray-100 rounded-xl border border-gray-200"></div>
                                            <div class="h-16 bg-gray-100 rounded-xl border border-gray-200"></div>
                                        </div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200 bg-white/80 p-6 space-y-4">
                                        <div class="h-5 w-32 bg-gray-200 rounded shimmer"></div>
                                        <div class="grid grid-cols-1 gap-3">
                                            <div class="h-16 bg-gray-100 rounded-xl border border-gray-200"></div>
                                            <div class="h-16 bg-gray-100 rounded-xl border border-gray-200"></div>
                                            <div class="h-16 bg-gray-100 rounded-xl border border-gray-200"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid gap-6 lg:grid-cols-2">
                                    <div class="rounded-2xl border border-gray-200 bg-white/80 p-6 space-y-4">
                                        <div class="h-5 w-40 bg-gray-200 rounded shimmer"></div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                        </div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200 bg-white/80 p-6 space-y-4">
                                        <div class="h-5 w-40 bg-gray-200 rounded shimmer"></div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-gray-200 bg-white/80 p-6 space-y-4">
                                    <div class="h-5 w-48 bg-gray-200 rounded shimmer"></div>
                                    <div class="space-y-3">
                                <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                <div class="h-4 w-5/6 bg-gray-200 rounded shimmer"></div>
                                <div class="h-4 w-2/3 bg-gray-200 rounded shimmer"></div>
                                    </div>
                                </div>
                        </div>
                    </div>
                </div>
            </div>
    </x-async-card>
</x-page-shell>

<script>
let profileSkeletonHtml = '';

document.addEventListener('DOMContentLoaded', function() {
    const profileContent = document.getElementById('profileContent');

    if (profileContent) {
        profileSkeletonHtml = profileContent.innerHTML;
    }

    loadProfileData();
});

async function loadProfileData() {
    const profileContent = document.getElementById('profileContent');

    if (profileContent && profileSkeletonHtml) {
        profileContent.innerHTML = profileSkeletonHtml;
    }

    try {
        const response = await fetch('/api/campus/profile', {
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'Gagal memuat data profile');
        }

        updateProfileUI(data);

        const userInfo = Object.keys(data.user_info || {}).length
            ? data.user_info
            : data.profile?.personal_data;

        updateUserName(userInfo);
    } catch (error) {
        console.error('Profile error:', error);
        showError(error.message || 'Terjadi kesalahan saat memuat data');
    }
}

function updateProfileUI(data) {
    const profileContent = document.getElementById('profileContent');

    if (!profileContent) {
        return;
    }

    const profile = data.profile || {};
    const personalData = profile.personal_data || {};
    const contactData = profile.contact_data || {};
    const educationData = profile.education_data || {};
    const photoUrl = profile.photo_url;
    const extractedAt = profile.extracted_at;

    const summaryItems = [
        { label: 'Program Studi', value: personalData.program_studi, icon: 'academic' },
        { label: 'Angkatan', value: personalData.angkatan, icon: 'calendar' },
        { label: 'Status', value: personalData.status, icon: 'status' },
        { label: 'Jenis Kelamin', value: personalData.jenis_kelamin, icon: 'profile' },
    ].filter(item => item.value);

    const academicInfo = [
        { label: 'Nama Lengkap', value: personalData.nama },
        { label: 'NIM', value: personalData.nim },
        { label: 'Program Studi', value: personalData.program_studi },
        { label: 'Status Akademik', value: personalData.status },
        { label: 'Angkatan', value: personalData.angkatan },
        { label: 'Jenjang', value: personalData.program || personalData.jenjang },
    ];

    const identityInfo = [
        { label: 'Tempat Lahir', value: personalData.tempat_lahir },
        { label: 'Tanggal Lahir', value: formatDateValue(personalData.tanggal_lahir) },
        { label: 'Jenis Kelamin', value: personalData.jenis_kelamin },
    ];

    const contactInfo = [
        { label: 'Email', value: contactData.email },
        { label: 'No. HP', value: contactData.hp || contactData.telp },
        { label: 'Alamat', value: contactData.alamat_rumah },
        { label: 'Kota', value: contactData.kota },
        { label: 'Provinsi', value: contactData.propinsi },
        { label: 'Kode Pos', value: contactData.kodepos },
    ];

    const educationInfo = [
        { label: 'NISN', value: educationData.nisn },
        { label: 'Tingkat Pendidikan', value: educationData.tingkat_pendidikan },
        { label: 'Asal Sekolah', value: educationData.asal_sekolah },
        { label: 'Alamat Sekolah', value: educationData.alamat_sekolah },
        { label: 'Kota Sekolah', value: educationData.kota_sekolah },
        { label: 'Provinsi Sekolah', value: educationData.propinsi_sekolah },
        { label: 'Negara Sekolah', value: educationData.negara_sekolah },
        { label: 'Telp Sekolah', value: educationData.telp_sekolah },
    ];

    const hasSummary = summaryItems.length > 0;
    const gridClass = hasSummary ? 'lg:grid-cols-3' : 'lg:grid-cols-1';

    profileContent.innerHTML = `
        <div class="space-y-6">
            <section class="grid gap-6 ${gridClass}">
                ${renderProfileHeader(personalData, photoUrl, hasSummary ? 'lg:col-span-2' : '')}
                ${hasSummary ? renderSummaryCards(summaryItems) : ''}
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                ${renderInfoCard('Data Akademik', 'Identitas mahasiswa aktif', academicInfo)}
                ${renderInfoCard('Informasi Kontak', 'Dapat dihubungi', contactInfo, 1)}
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                ${renderInfoCard('Data Kelahiran', 'Detail pribadi', identityInfo)}
                ${renderInfoCard('Riwayat Pendidikan', 'Sebelum CIC', educationInfo)}
            </section>

            ${extractedAt ? `
                <p class="text-xs text-center text-gray-500">
                    Data terakhir diperbarui: ${formatDateTime(extractedAt)}
                </p>
            ` : ''}
        </div>
    `;
}

function renderProfileHeader(personalData, photoUrl, extraClasses = '') {
    const highlightChips = [
        { label: 'Status', value: personalData.status, accent: personalData.status === 'Aktif' ? 'text-green-700 bg-green-100' : 'text-gray-700 bg-gray-100' },
        { label: 'Angkatan', value: personalData.angkatan, accent: 'text-blue-700 bg-blue-100' },
    ].filter(chip => chip.value);

    return `
        <div class="rounded-2xl border border-gray-200 bg-white/90 p-6 ${extraClasses}">
            <div class="flex flex-col gap-6 md:flex-row md:items-center md:text-left">
                <div class="flex flex-col items-center text-center md:flex-row md:text-left md:gap-6">
                    <div class="w-28 h-28 rounded-full overflow-hidden border-4 border-white shadow ring-4 ring-blue-50 bg-gray-100">
                        ${photoUrl ? `
                            <img src="${photoUrl}" alt="Foto Profil" class="w-full h-full object-cover" onerror="this.classList.add('hidden'); const fallback = this.parentElement.querySelector('[data-photo-fallback]'); if (fallback) { fallback.classList.remove('hidden'); }">
                            ${renderAvatarFallback(true)}
                        ` : renderAvatarFallback(false)}
                    </div>
                    <div class="mt-4 space-y-1 md:mt-0">
                        <h4 class="text-2xl font-bold text-gray-900">${personalData.nama || 'Mahasiswa CIC'}</h4>
                        <p class="text-sm text-gray-500">${personalData.nim || 'N/A'}</p>
                        ${personalData.program_studi ? `<p class="text-sm text-gray-500">${personalData.program_studi}</p>` : ''}
                        ${highlightChips.length ? `
                            <div class="mt-3 flex flex-wrap gap-2">
                                ${highlightChips.map(chip => `
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ${chip.accent}">
                                        ${chip.label}: ${chip.value}
                                    </span>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                ${renderHeaderMeta('Program Studi', personalData.program_studi)}
                ${renderHeaderMeta('NIM', personalData.nim)}
            </div>
        </div>
    `;
}

function renderAvatarFallback(isHidden) {
    return `
        <div data-photo-fallback class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-50 to-gray-100 text-gray-400 ${isHidden ? 'hidden' : ''}">
            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
        </div>
    `;
}

function renderSummaryCards(items) {
    return `
        <div class="rounded-2xl border border-gray-200 bg-white/80 p-6 space-y-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Ringkasan</p>
                <h5 class="text-lg font-semibold text-gray-900 mt-1">Status Akademik</h5>
            </div>
            <div class="space-y-3">
                ${items.map(renderSummaryCard).join('')}
            </div>
        </div>
    `;
}

function renderSummaryCard(item) {
    return `
        <div class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">${item.label}</p>
                <p class="mt-1 text-base font-semibold text-gray-900">${getDisplayValue(item.value)}</p>
            </div>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-gray-50 text-gray-600">
                ${renderSummaryIcon(item.icon)}
            </span>
        </div>
    `;
}

function renderInfoCard(title, subtitle, items, columns = 2) {
    const filteredItems = items.filter(item => item.value);

    if (!filteredItems.length) {
        return '';
    }

    const columnClass = columns > 1 ? `sm:grid-cols-${columns}` : 'sm:grid-cols-1';

    return `
        <section class="rounded-2xl border border-gray-200 bg-white/80 p-5 space-y-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">${subtitle}</p>
                <h5 class="text-lg font-semibold text-gray-900 mt-1">${title}</h5>
            </div>
            <div class="grid grid-cols-1 ${columnClass} gap-4">
                ${filteredItems.map(item => renderInfoItem(item.label, item.value)).join('')}
            </div>
        </section>
    `;
}

function renderInfoItem(label, value) {
    return `
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">${label}</p>
            <p class="text-sm font-semibold text-gray-900 mt-1">${getDisplayValue(value)}</p>
        </div>
    `;
}

function renderHeaderMeta(label, value) {
    if (!value) {
        return '';
    }

    return `
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">${label}</p>
            <p class="mt-1 text-base font-semibold text-gray-900">${value}</p>
        </div>
    `;
}

function renderSummaryIcon(type) {
    switch (type) {
        case 'academic':
            return `<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6l7 3-7 3-7-3 7-3zm0 12l-6-3.3V11.2m12 0v3.5L12 18"/></svg>`;
        case 'calendar':
            return `<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>`;
        case 'status':
            return `<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
        default:
            return `<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`;
    }
}

function getDisplayValue(value) {
    if (value === null || value === undefined || value === '') {
        return 'N/A';
    }

    return value;
}

function formatDateValue(value) {
    if (!value) {
        return null;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function formatDateTime(value) {
    if (!value) {
        return null;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function updateUserName(userInfo) {
    const userName = document.getElementById('userName');

    if (!userName || !userInfo) {
        return;
    }

    const name = userInfo.name || userInfo.nama;

    if (name) {
        userName.textContent = name;
    }
}

function showError(message) {
    const profileContent = document.getElementById('profileContent');

    if (profileContent) {
        profileContent.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-sm font-medium text-gray-900 mb-2">Gagal Memuat Data</h3>
                <p class="text-sm text-gray-500 mb-4">${message}</p>
                <button onclick="loadProfileData()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Coba Lagi
                </button>
            </div>
        `;
    }
}
</script>
@endsection
