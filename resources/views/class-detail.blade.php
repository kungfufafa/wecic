@extends('layouts.app')

@section('title', 'Detail Kelas - Portal CIC')

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
                    <h1 class="text-lg font-semibold text-gray-900">Detail Kelas</h1>
                    <p class="text-sm text-gray-500">Informasi lengkap kelas dan materi perkuliahan</p>
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
                <!-- Back Button -->
                <div class="mb-6">
                    <a href="/classes" class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                        Kembali ke Daftar Kelas
                    </a>
                </div>

                <!-- Class Detail Card -->
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="p-4 lg:p-6 border-b border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Detail Kelas</h3>
                                <p class="text-sm text-gray-500">Informasi lengkap mengenai kelas dan materi perkuliahan</p>
                            </div>
                            <button onclick="loadClassDetail()" class="mt-3 sm:mt-0 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                Refresh Data
                            </button>
                        </div>
                    </div>
                    <div class="p-4 lg:p-6">
                        <div id="classDetailContent">
                            <!-- Loading State -->
                            <div class="flex items-center justify-center py-12">
                                <div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                                <span class="ml-3 text-gray-600">Memuat detail kelas...</span>
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

    // Load class detail data
    loadClassDetail();
});

async function loadClassDetail() {
    try {
        showLoading(true);

        const classId = '{{ $classId }}';
        const response = await fetch(`/api/campus/class-detail-by-id?class_id=${classId}`, {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const data = await response.json();
            updateClassDetailUI(data);
        } else {
            showError('Gagal memuat detail kelas');
        }
    } catch (error) {
        console.error('Class detail error:', error);
        showError('Terjadi kesalahan saat memuat data');
    } finally {
        showLoading(false);
    }
}

function updateClassDetailUI(data) {
    const content = document.getElementById('classDetailContent');
    const detail = data.class_detail || {};
    const courseInfo = detail.course_info || {};
    const classInfo = detail.class_info || {};
    const lecturerInfo = detail.lecturer_info || {};
    const attendanceInfo = detail.attendance_info || {};
    const assignmentHistory = detail.assignment_history || {};
    const courseMaterials = detail.course_materials || [];

    content.innerHTML = `
        <div class="space-y-6">
            <!-- Informasi Umum -->
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Informasi Kelas</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Mata Kuliah</h5>
                        <p class="text-gray-900 font-medium">${courseInfo.name || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Kode Mata Kuliah</h5>
                        <p class="text-gray-900">${courseInfo.code || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Nama Kelas</h5>
                        <p class="text-gray-900">${courseInfo.class_name || classInfo.name || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">SKS</h5>
                        <p class="text-gray-900">${courseInfo.credits || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Dosen Pengajar</h5>
                        <p class="text-gray-900">${lecturerInfo.name || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Kontak Dosen</h5>
                        <p class="text-gray-900">${lecturerInfo.contact_info || lecturerInfo.contact || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Email Dosen</h5>
                        <p class="text-gray-900">${lecturerInfo.email || 'N/A'}</p>
                    </div>
                </div>
            </div>

            <!-- Presensi Kehadiran -->
            ${attendanceInfo.attendance_data && attendanceInfo.attendance_data.length > 0 ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Presensi Kehadiran</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                ${attendanceInfo.table_headers.map(header => `
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">
                                        ${header}
                                    </th>
                                `).join('')}
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            ${attendanceInfo.attendance_data.map((row, rowIndex) => `
                                <tr>
                                    ${row.map((cell, cellIndex) => {
                                        if (cell.type === 'image') {
                                            return `<td class="px-4 py-3 text-center border-b border-gray-200">
                                                <img src="${cell.src}" alt="${cell.alt}" width="30" class="mx-auto">
                                            </td>`;
                                        } else if (cell.type === 'button') {
                                            return `<td class="px-4 py-3 text-center border-b border-gray-200">
                                                <button class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700"
                                                        title="${cell.title || ''}"
                                                        ${cell.href ? `onclick="window.open('${cell.href}', '_blank')"` : ''}>
                                                    ${cell.text}
                                                </button>
                                            </td>`;
                                        } else {
                                            return `<td class="px-4 py-3 text-center border-b border-gray-200 text-sm text-gray-900">
                                                ${cell.content}
                                            </td>`;
                                        }
                                    }).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
            ` : ''}

            <!-- Histori Upload Tugas -->
            ${assignmentHistory.assignments && assignmentHistory.assignments.length > 0 ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Histori Upload Tugas</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">No</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Tanggal</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Pertemuan</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">File Tugas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Nilai</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            ${assignmentHistory.assignments.map(assignment => `
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">${assignment.no}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">${assignment.date}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">${assignment.meeting}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">
                                        ${assignment.file_info ?
                                            `<a href="${assignment.file_info.href}" target="_blank" class="text-blue-600 hover:text-blue-800 underline">
                                                ${assignment.file_info.text}
                                            </a>` : 'N/A'
                                        }
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 border-b border-gray-200">${assignment.score}</td>
                                </tr>
                            `).join('')}
                            ${assignmentHistory.average_score ? `
                                <tr class="bg-gray-50">
                                    <td colspan="4" class="px-4 py-3 text-sm font-medium text-gray-900 border-b border-gray-200 text-right">
                                        RATA-RATA NILAI TUGAS
                                    </td>
                                    <td class="px-4 py-3 text-sm font-bold text-gray-900 border-b border-gray-200">
                                        ${assignmentHistory.average_score}
                                    </td>
                                </tr>
                            ` : ''}
                        </tbody>
                    </table>
                </div>
            </div>
            ` : ''}

            <!-- Materi Perkuliahan -->
            ${courseMaterials && courseMaterials.length > 0 ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Materi Perkuliahan</h4>
                <div class="space-y-4">
                    ${courseMaterials.map(material => `
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <h5 class="font-medium text-gray-900">${material.title}</h5>
                                    <div class="mt-1 text-sm text-gray-500">
                                        ${material.date ? `${material.date}` : ''}
                                        ${material.time ? `, Jam: ${material.time}` : ''}
                                    </div>
                                    ${material.description ? `
                                        <div class="mt-2 text-sm text-gray-700">
                                            <strong>Uraian Pokok Pembahasan:</strong>
                                            <div class="mt-1">${material.description.replace(/•/g, '• ')}</div>
                                        </div>
                                    ` : ''}
                                </div>
                                <div class="ml-4 flex gap-2">
                                    ${material.join_link ? `
                                        <a href="https://my.cic.ac.id/portal/sipandai/${material.join_link.href}" target="_blank"
                                           class="px-3 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 inline-flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                            </svg>
                                            ${material.join_link.text}
                                        </a>
                                    ` : ''}
                                    ${material.join_link ? `
                                        <a href="/meetings/${material.join_link.href.split('id=')[1].split('&')[0]}"
                                           class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 inline-flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Detail
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
            ` : ''}

            <!-- Source URL -->
            <div class="text-xs text-gray-500 pt-4 border-t border-gray-200">
                <p>Source: ${detail.request_url || 'N/A'}</p>
                <p>Extracted at: ${detail.extracted_at || 'N/A'}</p>
            </div>
        </div>
    `;
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
    const content = document.getElementById('classDetailContent');
    if (content) {
        content.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-sm font-medium text-gray-900 mb-2">Gagal Memuat Data</h3>
                <p class="text-sm text-gray-500 mb-4">${message}</p>
                <button onclick="loadClassDetail()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Coba Lagi
                </button>
            </div>
        `;
    }
}
</script>
@endsection