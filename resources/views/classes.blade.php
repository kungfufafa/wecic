@extends('layouts.app')

@section('title', 'Daftar Kelas - Portal CIC')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <button id="mobileMenuBtn" class="lg:hidden p-2 text-gray-600 hover:text-gray-900">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <div>
                    <h1 class="text-lg font-semibold text-gray-900">Daftar Kelas</h1>
                    <p class="text-sm text-gray-500">Informasi Kelas dan Mata Kuliah</p>
                </div>
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

    <div class="flex">
        <!-- Sidebar -->
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-200 transform -translate-x-full transition-transform lg:translate-x-0 lg:static lg:inset-0">
            <div class="flex flex-col h-full">
                <!-- Sidebar Header -->
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">CIC Portal</h2>
                            <p id="userName" class="text-xs text-gray-500">Loading...</p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 p-4 space-y-1">
                    <a href="/dashboard" class="flex items-center space-x-3 px-3 py-2 text-sm font-medium rounded-lg text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Dashboard</span>
                    </a>
                    <a href="/profile" class="flex items-center space-x-3 px-3 py-2 text-sm font-medium rounded-lg text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span>Profile</span>
                    </a>
                    <a href="/classes" class="flex items-center space-x-3 px-3 py-2 text-sm font-medium rounded-lg bg-blue-50 text-blue-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                        <span>Daftar Kelas</span>
                    </a>
                </nav>
            </div>
        </aside>

        <!-- Mobile Sidebar Overlay -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

        <!-- Main Content -->
        <main class="flex-1 lg:ml-0">
            <div class="p-4 lg:p-6">
                <!-- Classes Card -->
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="p-4 lg:p-6 border-b border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Daftar Kelas</h3>
                                <p class="text-sm text-gray-500">Kelas yang sedang diambil semester ini</p>
                            </div>
                            <button onclick="loadClassesData()" class="mt-3 sm:mt-0 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                Refresh Data
                            </button>
                        </div>
                    </div>
                    <div class="p-4 lg:p-6">
                        <div id="classesContent">
                            <!-- Loading State -->
                            <div class="flex items-center justify-center py-12">
                                <div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                                <span class="ml-3 text-gray-600">Memuat daftar kelas...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class Detail Modal -->
                <div id="classDetailModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden">
                        <div class="p-4 lg:p-6 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900">Detail Kelas</h3>
                                <button onclick="closeClassDetail()" class="p-2 text-gray-500 hover:text-gray-700">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="p-4 lg:p-6 overflow-y-auto max-h-[70vh]">
                            <div id="classDetailContent">
                                Loading...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
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
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    // Mobile menu toggle
    mobileMenuBtn?.addEventListener('click', function() {
        sidebar.classList.toggle('-translate-x-full');
        sidebarOverlay.classList.toggle('hidden');
    });

    // Close sidebar when clicking overlay
    sidebarOverlay?.addEventListener('click', function() {
        sidebar.classList.add('-translate-x-full');
        sidebarOverlay.classList.add('hidden');
    });

    // Load classes data
    loadClassesData();
});

async function loadClassesData() {
    try {
        showLoading(true);

        const response = await fetch('/api/campus/classes', {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const data = await response.json();
            updateClassesUI(data);
            updateUserName(data.user_info);
        } else {
            showError('Gagal memuat daftar kelas');
        }
    } catch (error) {
        console.error('Classes error:', error);
        showError('Terjadi kesalahan saat memuat data');
    } finally {
        showLoading(false);
    }
}

function updateClassesUI(data) {
    const classesContent = document.getElementById('classesContent');

    if (!classesContent) return;

    const classes = data.classes || [];

    if (classes.length === 0) {
        classesContent.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
                <h3 class="text-sm font-medium text-gray-900 mb-2">Belum Ada Kelas</h3>
                <p class="text-sm text-gray-500">Tidak ada kelas yang tersedia saat ini.</p>
            </div>
        `;
        return;
    }

    classesContent.innerHTML = `
        <div class="space-y-4">
            ${classes.map(cls => `
                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex-1">
                            <div class="flex items-start space-x-3">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-900">${cls.course_name || 'N/A'}</h4>
                                    <p class="text-sm text-gray-600 mt-1">${cls.course_code || 'N/A'} • ${cls.credits || 0} SKS</p>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded">${cls.class_name || 'N/A'}</span>
                                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded">${cls.lecturer || 'N/A'}</span>
                                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded">${cls.schedule || 'N/A'}</span>
                                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded">${cls.room || 'N/A'}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 lg:mt-0 lg:ml-4">
                            <button onclick="showClassDetail('${cls.id || ''}', '${cls.course_id || ''}', '${cls.dosen_id || ''}')" class="px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                                Lihat Detail
                            </button>
                        </div>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

async function showClassDetail(classId, courseId, dosenId) {
    const modal = document.getElementById('classDetailModal');
    const content = document.getElementById('classDetailContent');

    modal.classList.remove('hidden');
    content.innerHTML = '<div class="text-center py-8"><div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div><p class="mt-2 text-gray-600">Memuat detail kelas...</p></div>';

    try {
        const response = await fetch(`/api/campus/class-detail?class_id=${classId}&course_id=${courseId}&dosen_id=${dosenId}`, {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const data = await response.json();
            updateClassDetailUI(data);
        } else {
            content.innerHTML = '<div class="text-center py-8 text-red-600">Gagal memuat detail kelas</div>';
        }
    } catch (error) {
        console.error('Class detail error:', error);
        content.innerHTML = '<div class="text-center py-8 text-red-600">Terjadi kesalahan saat memuat data</div>';
    }
}

function updateClassDetailUI(data) {
    const content = document.getElementById('classDetailContent');
    const detail = data.class_detail || {};

    content.innerHTML = `
        <div class="space-y-6">
            <!-- Informasi Umum -->
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Informasi Kelas</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Mata Kuliah</h5>
                        <p class="text-gray-900">${detail.course_name || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Kode Mata Kuliah</h5>
                        <p class="text-gray-900">${detail.course_code || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Nama Kelas</h5>
                        <p class="text-gray-900">${detail.class_name || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Dosen Pengajar</h5>
                        <p class="text-gray-900">${detail.lecturer || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Jadwal</h5>
                        <p class="text-gray-900">${detail.schedule || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Ruang</h5>
                        <p class="text-gray-900">${detail.room || 'N/A'}</p>
                    </div>
                </div>
            </div>

            <!-- Daftar Mahasiswa -->
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Daftar Mahasiswa</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">NIM</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            ${(detail.students || []).map((student, index) => `
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${index + 1}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${student.nim || 'N/A'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${student.name || 'N/A'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Aktif
                                        </span>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${(detail.students || []).length === 0 ? `
                        <div class="text-center py-8 text-gray-500">
                            Tidak ada data mahasiswa
                        </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
}

function closeClassDetail() {
    document.getElementById('classDetailModal').classList.add('hidden');
}

function updateUserName(userInfo) {
    const userName = document.getElementById('userName');
    if (userName && userInfo && userInfo.name) {
        userName.textContent = userInfo.name;
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
    const classesContent = document.getElementById('classesContent');
    if (classesContent) {
        classesContent.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-sm font-medium text-gray-900 mb-2">Gagal Memuat Data</h3>
                <p class="text-sm text-gray-500 mb-4">${message}</p>
                <button onclick="loadClassesData()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Coba Lagi
                </button>
            </div>
        `;
    }
}
</script>
@endsection