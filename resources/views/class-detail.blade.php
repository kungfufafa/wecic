@extends('layouts.app')

@section('title', 'Detail Kelas - Portal CIC')

@section('content')
<div class="min-h-screen bg-gray-50 pb-20">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 px-4 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Detail Kelas</h1>
                <p class="text-sm text-gray-500">Informasi lengkap kelas dan materi perkuliahan</p>
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
            <a href="/classes" class="flex flex-col items-center py-2 px-3 text-blue-600">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
                <span class="text-xs font-medium">Kelas</span>
            </a>

            <!-- Profile -->
            <a href="/profile" class="flex flex-col items-center py-2 px-3 text-gray-500 hover:text-gray-700">
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
            <!-- Course Info Card -->
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">${courseInfo.name || 'N/A'}</h3>
                        <p class="text-sm text-gray-600">${courseInfo.code || 'N/A'} • ${courseInfo.class_name || classInfo.name || 'N/A'}</p>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-sm text-gray-500">Dosen</span>
                        <span class="text-sm font-medium text-gray-900">${lecturerInfo.name || 'N/A'}</span>
                    </div>
                    ${lecturerInfo.contact_info || lecturerInfo.contact ? `
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-sm text-gray-500">Kontak</span>
                        <span class="text-sm font-medium text-gray-900">${lecturerInfo.contact_info || lecturerInfo.contact}</span>
                    </div>
                    ` : ''}
                    ${lecturerInfo.email ? `
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-sm text-gray-500">Email</span>
                        <span class="text-sm font-medium text-gray-900 text-blue-600">${lecturerInfo.email}</span>
                    </div>
                    ` : ''}
                    ${courseInfo.credits ? `
                    <div class="flex items-center justify-between py-2">
                        <span class="text-sm text-gray-500">SKS</span>
                        <span class="text-sm font-medium text-gray-900">${courseInfo.credits}</span>
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- Presensi Kehadiran -->
            ${attendanceInfo.attendance_data && attendanceInfo.attendance_data.length > 0 ? `
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Presensi Kehadiran
                </h4>
                <div class="overflow-x-auto">
                    <div class="min-w-full">
                        ${attendanceInfo.attendance_data.map((row, rowIndex) => `
                            <div class="border border-gray-200 rounded-lg mb-3 p-3">
                                <div class="grid grid-cols-2 gap-2 text-sm">
                                    ${row.map((cell, cellIndex) => {
                                        const header = attendanceInfo.table_headers[cellIndex] || `Info ${cellIndex + 1}`;
                                        if (cell.type === 'image') {
                                            return `
                                            <div class="col-span-2 text-center">
                                                <p class="text-xs text-gray-500 mb-1">${header}</p>
                                                <img src="${cell.src}" alt="${cell.alt}" width="40" class="mx-auto rounded">
                                            </div>`;
                                        } else if (cell.type === 'button') {
                                            return `
                                            <div class="col-span-2 text-center">
                                                <p class="text-xs text-gray-500 mb-2">${header}</p>
                                                <button class="px-3 py-2 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 w-full"
                                                        title="${cell.title || ''}"
                                                        ${cell.href ? `onclick="window.open('${cell.href}', '_blank')"` : ''}>
                                                    ${cell.text}
                                                </button>
                                            </div>`;
                                        } else {
                                            return `
                                            <div>
                                                <p class="text-xs text-gray-500">${header}</p>
                                                <p class="font-medium text-gray-900">${cell.content}</p>
                                            </div>`;
                                        }
                                    }).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Histori Upload Tugas -->
            ${assignmentHistory.assignments && assignmentHistory.assignments.length > 0 ? `
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Histori Upload Tugas
                </h4>
                <div class="space-y-3">
                    ${assignmentHistory.assignments.map(assignment => `
                        <div class="border border-gray-200 rounded-lg p-3">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                            ${assignment.no}
                                        </span>
                                        <span class="text-xs text-gray-500">${assignment.date}</span>
                                    </div>
                                    <p class="text-sm font-medium text-gray-900 mb-2">${assignment.meeting}</p>
                                    ${assignment.file_info ? `
                                        <a href="${assignment.file_info.href}" target="_blank"
                                           class="inline-flex items-center text-xs text-blue-600 hover:text-blue-800">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                            ${assignment.file_info.text}
                                        </a>
                                    ` : '<span class="text-xs text-gray-500">Tidak ada file</span>'}
                                </div>
                                <div class="ml-3">
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full ${
                                        parseFloat(assignment.score) >= 80 ? 'bg-green-100 text-green-800' :
                                        parseFloat(assignment.score) >= 60 ? 'bg-yellow-100 text-yellow-800' :
                                        'bg-red-100 text-red-800'
                                    }">
                                        ${assignment.score}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                    ${assignmentHistory.average_score ? `
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">Rata-rata Nilai Tugas</span>
                                <span class="text-lg font-bold text-gray-900">
                                    ${assignmentHistory.average_score}
                                </span>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
            ` : ''}

            <!-- Materi Perkuliahan -->
            ${courseMaterials && courseMaterials.length > 0 ? `
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                    </svg>
                    Materi Perkuliahan
                </h4>
                <div class="space-y-4">
                    ${courseMaterials.map(material => `
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="space-y-3">
                                <div>
                                    <h5 class="font-medium text-gray-900 text-sm">${material.title}</h5>
                                    <div class="flex items-center space-x-3 mt-1 text-xs text-gray-500">
                                        ${material.date ? `
                                            <span class="flex items-center">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                                ${material.date}
                                            </span>
                                        ` : ''}
                                        ${material.time ? `
                                            <span class="flex items-center">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                ${material.time}
                                            </span>
                                        ` : ''}
                                    </div>
                                </div>

                                ${material.description ? `
                                    <div class="text-sm text-gray-700">
                                        <p class="font-medium text-xs text-gray-500 mb-1">Uraian Pokok Pembahasan:</p>
                                        <div class="text-xs leading-relaxed">${material.description.replace(/•/g, '• ')}</div>
                                    </div>
                                ` : ''}

                                ${material.join_link ? `
                                    <div class="flex flex-col sm:flex-row gap-2 pt-2">
                                        <a href="https://my.cic.ac.id/portal/sipandai/${material.join_link.href}" target="_blank"
                                           class="flex-1 px-3 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 inline-flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                            </svg>
                                            ${material.join_link.text}
                                        </a>
                                        <a href="/meetings/${material.join_link.href.split('id=')[1].split('&')[0]}"
                                           class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 inline-flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Detail Pertemuan
                                        </a>
                                    </div>
                                ` : ''}
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