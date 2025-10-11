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
    const lecturerInfo = detail.lecturer_info || {};
    const attendanceInfo = detail.attendance_info || {};
    const assignmentHistory = detail.assignment_history || {};
    const courseMaterials = detail.course_materials || [];
    const studentsList = detail.students_list || [];

    content.innerHTML = `
        <div class="space-y-6">
            <!-- Enhanced Course Info Card -->
            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl border border-blue-200 overflow-hidden">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-bold text-lg">${courseInfo.name || 'N/A'}</h3>
                            <p class="text-blue-100">${courseInfo.code || 'N/A'} • ${courseInfo.class_name || 'N/A'}</p>
                        </div>
                        ${courseInfo.credits ? `
                            <div class="px-3 py-1 bg-white/20 rounded-full text-sm font-medium">
                                ${courseInfo.credits} SKS
                            </div>
                        ` : ''}
                    </div>
                </div>

                <div class="p-4 space-y-3">
                    ${lecturerInfo.name ? `
                    <div class="flex items-center justify-between py-3 border-b border-blue-100">
                        <span class="text-sm font-medium text-gray-700 flex items-center">
                            <svg class="w-4 h-4 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            Dosen Pengampu
                        </span>
                        <span class="text-sm font-semibold text-gray-900">${lecturerInfo.name}</span>
                    </div>
                    ` : ''}

                    ${lecturerInfo.contact_info || lecturerInfo.contact ? `
                    <div class="flex items-center justify-between py-3 border-b border-blue-100">
                        <span class="text-sm font-medium text-gray-700 flex items-center">
                            <svg class="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            Kontak Dosen
                        </span>
                        <span class="text-sm font-medium text-gray-900">${lecturerInfo.contact_info || lecturerInfo.contact}</span>
                    </div>
                    ` : ''}

                    ${lecturerInfo.email ? `
                    <div class="flex items-center justify-between py-3 border-b border-blue-100">
                        <span class="text-sm font-medium text-gray-700 flex items-center">
                            <svg class="w-4 h-4 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Email Dosen
                        </span>
                        <a href="mailto:${lecturerInfo.email}" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                            ${lecturerInfo.email}
                        </a>
                    </div>
                    ` : ''}

                    ${courseInfo.rps_link ? `
                    <div class="flex items-center justify-between py-3">
                        <span class="text-sm font-medium text-gray-700 flex items-center">
                            <svg class="w-4 h-4 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            RPS (Rencana Pembelajaran Semester)
                        </span>
                        <a href="${courseInfo.rps_link}" target="_blank" class="px-3 py-1 bg-orange-600 text-white text-sm rounded-lg hover:bg-orange-700 inline-flex items-center">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Unduh RPS
                        </a>
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- Enhanced Attendance Information -->
            ${attendanceInfo.meetings && attendanceInfo.meetings.length > 0 ? `
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-green-600 to-emerald-600 text-white p-4">
                    <h4 class="font-semibold flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Presensi Kehadiran
                        ${attendanceInfo.student_name ? ` • ${attendanceInfo.student_name}` : ''}
                    </h4>
                </div>

                <!-- Attendance Summary -->
                ${attendanceInfo.summary ? `
                <div class="p-4 bg-gray-50 border-b border-gray-200">
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-center">
                        <div class="bg-white rounded-lg p-3">
                            <div class="text-2xl font-bold text-gray-900">${attendanceInfo.summary.total_meetings}</div>
                            <div class="text-xs text-gray-600">Total Pertemuan</div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-3">
                            <div class="text-2xl font-bold text-green-600">${attendanceInfo.summary.present}</div>
                            <div class="text-xs text-gray-600">Hadir</div>
                        </div>
                        <div class="bg-red-50 rounded-lg p-3">
                            <div class="text-2xl font-bold text-red-600">${attendanceInfo.summary.absent}</div>
                            <div class="text-xs text-gray-600">Absen</div>
                        </div>
                        <div class="bg-yellow-50 rounded-lg p-3">
                            <div class="text-2xl font-bold text-yellow-600">${attendanceInfo.summary.permit}</div>
                            <div class="text-xs text-gray-600">Izin</div>
                        </div>
                        <div class="bg-purple-50 rounded-lg p-3">
                            <div class="text-2xl font-bold text-purple-600">${attendanceInfo.summary.sick}</div>
                            <div class="text-xs text-gray-600">Sakit</div>
                        </div>
                    </div>
                    ${attendanceInfo.summary.attendance_rate ? `
                        <div class="mt-3 text-center">
                            <div class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                                attendanceInfo.summary.attendance_rate >= 75 ? 'bg-green-100 text-green-800' :
                                attendanceInfo.summary.attendance_rate >= 60 ? 'bg-yellow-100 text-yellow-800' :
                                'bg-red-100 text-red-800'
                            }">
                                Persentase Kehadiran: ${attendanceInfo.summary.attendance_rate}%
                            </div>
                        </div>
                    ` : ''}
                </div>
                ` : ''}

                <!-- Detailed Attendance -->
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        ${attendanceInfo.meetings.map(meeting => {
                            const statusConfig = {
                                present: { color: 'green', icon: '✓', label: 'Hadir' },
                                absent: { color: 'red', icon: '✗', label: 'Absen' },
                                permit: { color: 'yellow', icon: 'I', label: 'Izin' },
                                sick: { color: 'purple', icon: 'S', label: 'Sakit' },
                                unknown: { color: 'gray', icon: '?', label: 'Unknown' }
                            };

                            const config = statusConfig[meeting.status] || statusConfig.unknown;

                            return `
                                <div class="border border-gray-200 rounded-lg p-3 bg-${config.color}-50">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-medium text-gray-600">Pertemuan ${meeting.meeting_number}</span>
                                        <span class="w-6 h-6 bg-${config.color}-600 text-white rounded-full flex items-center justify-center text-xs font-bold">
                                            ${config.icon}
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-700">
                                        ${meeting.details.attendance_date ? `
                                            <div>📅 ${meeting.details.attendance_date}</div>
                                        ` : ''}
                                        ${meeting.details.attendance_time ? `
                                            <div>🕐 ${meeting.details.attendance_time}</div>
                                        ` : ''}
                                    </div>
                                    <div class="mt-2">
                                        <span class="px-2 py-1 bg-${config.color}-100 text-${config.color}-800 rounded-full text-xs font-medium">
                                            ${config.label}
                                        </span>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Enhanced Assignment History -->
            ${assignmentHistory.assignments && assignmentHistory.assignments.length > 0 ? `
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-orange-600 to-red-600 text-white p-4">
                    <h4 class="font-semibold flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Histori Upload Tugas
                    </h4>
                </div>

                <div class="p-4">
                    <div class="space-y-3">
                        ${assignmentHistory.assignments.map(assignment => {
                            const scoreColor = parseFloat(assignment.score) >= 80 ? 'green' :
                                             parseFloat(assignment.score) >= 60 ? 'yellow' : 'red';

                            return `
                                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded-full text-xs font-medium">
                                                    #${assignment.no}
                                                </span>
                                                <span class="text-xs text-gray-500">
                                                    <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                    ${assignment.date}
                                                </span>
                                            </div>
                                            <h5 class="font-medium text-gray-900 mb-3">${assignment.meeting}</h5>
                                            ${assignment.file_info ? `
                                                <a href="${assignment.file_info.href}" target="_blank"
                                                   class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 font-medium">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                    </svg>
                                                    ${assignment.file_info.text}
                                                </a>
                                            ` : '<span class="text-sm text-gray-500">📄 Tidak ada file terupload</span>'}
                                        </div>
                                        <div class="ml-4">
                                            <span class="inline-flex items-center px-3 py-1 text-sm font-bold rounded-full bg-${scoreColor}-100 text-${scoreColor}-800">
                                                ${assignment.score}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>

                    ${assignmentHistory.average_score ? `
                        <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-700 flex items-center">
                                    <svg class="w-4 h-4 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                    </svg>
                                    Rata-rata Nilai Tugas
                                </span>
                                <span class="text-xl font-bold text-orange-600">
                                    ${assignmentHistory.average_score}
                                </span>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
            ` : ''}

            <!-- Enhanced Course Materials -->
            ${courseMaterials && courseMaterials.length > 0 ? `
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-purple-600 to-pink-600 text-white p-4">
                    <h4 class="font-semibold flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                        Materi Perkuliahan
                    </h4>
                </div>

                <div class="p-4">
                    <div class="space-y-4">
                        ${courseMaterials.map((material, index) => `
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-all duration-200">
                                <div class="space-y-3">
                                    <div>
                                        <h5 class="font-semibold text-gray-900 text-lg mb-2">
                                            ${index + 1}. ${material.title}
                                        </h5>
                                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                                            ${material.date ? `
                                                <span class="flex items-center">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                    ${material.date}
                                                </span>
                                            ` : ''}
                                            ${material.time ? `
                                                <span class="flex items-center">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                    ${material.time}
                                                </span>
                                            ` : ''}
                                        </div>
                                    </div>

                                    ${material.description ? `
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <h6 class="text-xs font-semibold text-gray-700 mb-2">📋 Uraian Pokok Pembahasan:</h6>
                                            <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-line">${material.description.replace(/•/g, '• ')}</div>
                                        </div>
                                    ` : ''}

                                    ${material.join_link ? `
                                        <div class="flex flex-col sm:flex-row gap-3 pt-2">
                                            <a href="https://my.cic.ac.id/portal/sipandai/${material.join_link.href}" target="_blank"
                                               class="flex-1 px-4 py-2.5 bg-gradient-to-r from-green-600 to-green-700 text-white text-sm font-medium rounded-lg hover:from-green-700 hover:to-green-800 inline-flex items-center justify-center shadow-sm transition-all duration-200">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                                </svg>
                                                ${material.join_link.text}
                                            </a>
                                            <a href="/meetings/${material.join_link.href.split('id=')[1].split('&')[0]}"
                                               class="flex-1 px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-sm font-medium rounded-lg hover:from-blue-700 hover:to-blue-800 inline-flex items-center justify-center shadow-sm transition-all duration-200">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            </div>
            ` : ''}

            <!-- Students List (if available) -->
            ${studentsList && studentsList.length > 0 ? `
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white p-4">
                    <h4 class="font-semibold flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Daftar Mahasiswa (${studentsList.length})
                    </h4>
                </div>

                <div class="p-4">
                    <div class="overflow-x-auto">
                        <div class="min-w-full">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                ${studentsList.slice(0, 20).map(student => `
                                    <div class="flex items-center space-x-2 p-2 bg-gray-50 rounded-lg text-sm">
                                        <span class="font-medium text-gray-700">${student.nim}</span>
                                        <span class="text-gray-900">${student.name}</span>
                                    </div>
                                `).join('')}
                            </div>
                            ${studentsList.length > 20 ? `
                                <div class="mt-3 text-center text-sm text-gray-500">
                                    ... dan ${studentsList.length - 20} mahasiswa lainnya
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Enhanced Footer Info -->
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-gray-600">
                    <div>
                        <strong>Source:</strong> ${detail.request_url || 'N/A'}
                    </div>
                    <div>
                        <strong>Extracted at:</strong> ${detail.extracted_at ? new Date(detail.extracted_at).toLocaleString('id-ID') : 'N/A'}
                    </div>
                    <div>
                        <strong>Content Type:</strong> ${detail.content_type || 'N/A'}
                    </div>
                    <div>
                        <strong>Data Quality:</strong> <span class="text-green-600 font-medium">✓ Verified</span>
                    </div>
                </div>
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

// showToast and removeToast functions are now global (defined in layout)
</script>
@endsection