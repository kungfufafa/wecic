@extends('layouts.app')

@section('title', 'Profile - Portal CIC')

@section('content')
<div class="min-h-screen bg-gray-50 pb-20">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 px-4 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Profile</h1>
                <p class="text-sm text-gray-500">Informasi Pribadi Mahasiswa</p>
            </div>
            <div class="flex items-center space-x-3">
                <div id="sessionStatus" class="flex items-center space-x-2 px-3 py-1.5 bg-green-50 text-green-700 text-xs rounded-full">
                    <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                    <span>Online</span>
                </div>
                <form action="/logout" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 rounded-lg">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="p-4">
                <!-- Profile Card -->
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Informasi Pribadi</h3>
                        <p class="text-sm text-gray-500">Data profil mahasiswa CIC</p>
                    </div>
                    <div class="p-6">
                        <div id="profileContent" class="space-y-6">
                            <!-- Loading State -->
                            <div class="flex items-center justify-center py-12">
                                <div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                                <span class="ml-3 text-gray-600">Memuat data profile...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    </main>

    <!-- Bottom Navigation -->
    <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 px-4 py-2 z-50">
        <div class="grid grid-cols-3 gap-1">
            <!-- Dashboard -->
            <a href="/dashboard" class="flex flex-col items-center py-2 px-3 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span class="text-xs font-medium">Beranda</span>
            </a>

            <!-- Classes -->
            <a href="/classes" class="flex flex-col items-center py-2 px-3 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
                <span class="text-xs font-medium">Kelas</span>
            </a>

            <!-- Profile -->
            <a href="/profile" class="flex flex-col items-center py-2 px-3 text-blue-600">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span class="text-xs font-medium">Profile</span>
            </a>
        </div>
    </nav>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 flex flex-col items-center space-y-3">
        <div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
        <span class="text-sm text-gray-700">Memuat data...</span>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load profile data
    loadProfileData();
});

async function loadProfileData() {
    try {
        showLoading(true);

        const response = await fetch('/api/campus/profile', {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const data = await response.json();
            updateProfileUI(data);
            updateUserName(data.profile?.personal_data || {});
        } else {
            showError('Gagal memuat data profile');
        }
    } catch (error) {
        console.error('Profile error:', error);
        showError('Terjadi kesalahan saat memuat data');
    } finally {
        showLoading(false);
    }
}

function updateProfileUI(data) {
    const profileContent = document.getElementById('profileContent');

    if (!profileContent) return;

    const profile = data.profile || {};
    const personalData = profile.personal_data || {};
    const contactData = profile.contact_data || {};
    const photoUrl = profile.photo_url;

    profileContent.innerHTML = `
        <div class="space-y-6">
            <!-- Foto Profile -->
            <div class="flex flex-col items-center">
                <div class="w-32 h-32 bg-gray-200 rounded-full overflow-hidden mb-4">
                    ${photoUrl ?
                        `<img src="${photoUrl}" alt="Profile Photo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                         <div class="w-full h-full bg-gray-200 rounded-full flex items-center justify-center" style="display:none;">
                            <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>` :
                        `<div class="w-full h-full bg-gray-200 rounded-full flex items-center justify-center">
                            <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>`
                    }
                </div>
                <div class="text-center">
                    <h4 class="text-lg font-semibold text-gray-900">${personalData.nama || 'N/A'}</h4>
                    <p class="text-sm text-gray-500">${personalData.nim || 'N/A'}</p>
                    <div class="mt-2">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${personalData.status === 'Aktif' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}">
                            ${personalData.status || 'N/A'}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Data Pribadi -->
            <div class="space-y-4">
                <div>
                    <h5 class="text-sm font-medium text-gray-700 mb-1">Nama Lengkap</h5>
                    <p class="text-gray-900">${personalData.nama || 'N/A'}</p>
                </div>
                <div>
                    <h5 class="text-sm font-medium text-gray-700 mb-1">NIM</h5>
                    <p class="text-gray-900">${personalData.nim || 'N/A'}</p>
                </div>
                <div>
                    <h5 class="text-sm font-medium text-gray-700 mb-1">Program Studi</h5>
                    <p class="text-gray-900">${personalData.program_studi || 'N/A'}</p>
                </div>
                <div>
                    <h5 class="text-sm font-medium text-gray-700 mb-1">Angkatan</h5>
                    <p class="text-gray-900">${personalData.angkatan || 'N/A'}</p>
                </div>
                <div>
                    <h5 class="text-sm font-medium text-gray-700 mb-1">Jenis Kelamin</h5>
                    <p class="text-gray-900">${personalData.jenis_kelamin || 'N/A'}</p>
                </div>
            </div>
        </div>

        <div class="space-y-6 pt-6 border-t border-gray-200">
            <!-- Informasi Kelahiran -->
            <div class="space-y-4">
                <h5 class="text-sm font-semibold text-gray-900">Informasi Kelahiran</h5>
                <div>
                    <h6 class="text-sm font-medium text-gray-700 mb-1">Tempat Lahir</h6>
                    <p class="text-gray-900">${personalData.tempat_lahir || 'N/A'}</p>
                </div>
                <div>
                    <h6 class="text-sm font-medium text-gray-700 mb-1">Tanggal Lahir</h6>
                    <p class="text-gray-900">${personalData.tanggal_lahir || 'N/A'}</p>
                </div>
            </div>

            <!-- Informasi Kontak -->
            <div class="space-y-4">
                <h5 class="text-sm font-semibold text-gray-900">Informasi Kontak</h5>
                <div>
                    <h6 class="text-sm font-medium text-gray-700 mb-1">Email</h6>
                    <p class="text-gray-900">${contactData.email || 'N/A'}</p>
                </div>
                <div>
                    <h6 class="text-sm font-medium text-gray-700 mb-1">No. HP</h6>
                    <p class="text-gray-900">${contactData.hp || contactData.telp || 'N/A'}</p>
                </div>
                <div>
                    <h6 class="text-sm font-medium text-gray-700 mb-1">Alamat Lengkap</h6>
                    <p class="text-gray-900">${contactData.alamat_rumah || 'N/A'}</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h6 class="text-sm font-medium text-gray-700 mb-1">Kota</h6>
                        <p class="text-gray-900">${contactData.kota || 'N/A'}</p>
                    </div>
                    <div>
                        <h6 class="text-sm font-medium text-gray-700 mb-1">Provinsi</h6>
                        <p class="text-gray-900">${contactData.propinsi || 'N/A'}</p>
                    </div>
                </div>
                <div>
                    <h6 class="text-sm font-medium text-gray-700 mb-1">Kode Pos</h6>
                    <p class="text-gray-900">${contactData.kodepos || 'N/A'}</p>
                </div>
            </div>
        </div>
    `;
}

function updateUserName(userInfo) {
    const userName = document.getElementById('userName');
    if (userName && userInfo && userInfo.nama) {
        userName.textContent = userInfo.nama;
    }
}

function showLoading(show) {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        if (show) {
            loadingOverlay.classList.remove('hidden');
        } else {
            loadingOverlay.classList.add('hidden');
        }
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
                <p class="text-sm text-gray-500">${message}</p>
                <button onclick="loadProfileData()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Coba Lagi
                </button>
            </div>
        `;
    }
}
</script>
@endsection