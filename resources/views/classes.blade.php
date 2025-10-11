@extends('layouts.app')

@section('title', 'Daftar Kelas - Portal CIC')

@section('content')
<div class="min-h-screen bg-gray-50 pb-20">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 px-4 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Daftar Kelas</h1>
                <p class="text-sm text-gray-500">Informasi Kelas dan Mata Kuliah</p>
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
    // Load classes data
    loadClassesData();
});

async function loadClassesData() {
    try {
        showLoading(true);

        const response = await fetch('/api/campus/merged-classes', {
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
    const studentInfo = data.student_info || {};
    const semesterInfo = data.semester_info || {};
    const academicSummary = data.academic_summary || {};

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

    // Build student info section
    const studentInfoHtml = studentInfo.nama || studentInfo.nim ? `
        <div class="mb-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
            <h3 class="text-sm font-semibold text-blue-900 mb-2 flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Informasi Mahasiswa
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                ${studentInfo.nama ? `
                    <div>
                        <span class="text-gray-600">Nama:</span>
                        <span class="ml-2 font-medium text-gray-900">${studentInfo.nama}</span>
                    </div>
                ` : ''}
                ${studentInfo.nim ? `
                    <div>
                        <span class="text-gray-600">NIM:</span>
                        <span class="ml-2 font-medium text-gray-900">${studentInfo.nim}</span>
                    </div>
                ` : ''}
                ${studentInfo.program_studi ? `
                    <div>
                        <span class="text-gray-600">Program Studi:</span>
                        <span class="ml-2 font-medium text-gray-900">${studentInfo.program_studi}</span>
                    </div>
                ` : ''}
            </div>
        </div>
    ` : '';

    // Build semester info section
    const semesterInfoHtml = semesterInfo.semester || academicSummary.semester ? `
        <div class="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
            <h3 class="text-sm font-semibold text-green-900 mb-2 flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                Informasi Akademik
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                ${semesterInfo.semester ? `
                    <div>
                        <span class="text-gray-600">Semester:</span>
                        <span class="ml-2 font-medium text-gray-900">${semesterInfo.semester}</span>
                    </div>
                ` : ''}
                ${academicSummary.total_credits ? `
                    <div>
                        <span class="text-gray-600">Total SKS:</span>
                        <span class="ml-2 font-medium text-gray-900">${academicSummary.total_credits}</span>
                    </div>
                ` : ''}
                ${academicSummary.gpa ? `
                    <div>
                        <span class="text-gray-600">IPK:</span>
                        <span class="ml-2 font-medium text-gray-900">${academicSummary.gpa}</span>
                    </div>
                ` : ''}
                ${academicSummary.status ? `
                    <div>
                        <span class="text-gray-600">Status:</span>
                        <span class="ml-2 font-medium text-green-600">${academicSummary.status}</span>
                    </div>
                ` : ''}
            </div>
        </div>
    ` : '';

    classesContent.innerHTML = `
        ${studentInfoHtml}
        ${semesterInfoHtml}

        <div class="space-y-4">
            ${classes.map(cls => {
                const courseInfo = cls.course_info || {};
                const lecturerInfo = cls.lecturer_info || {};
                const scheduleInfo = cls.schedule || {};
                const joinLink = cls.join_link || {};
                const params = joinLink.params || {};
                const classRoomInfo = cls.class_room_info || {};

                return `
                <div class="bg-white rounded-lg border border-gray-200 hover:shadow-md transition-all duration-200 overflow-hidden">
                    <div class="p-4">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex-1">
                                <div class="flex items-start space-x-3">
                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-gray-900 text-lg mb-1">${courseInfo.name || 'N/A'}</h4>
                                        <p class="text-sm text-gray-600 mb-3">${courseInfo.code || 'N/A'}${classRoomInfo.class_name ? ` • Kelas ${classRoomInfo.class_name}` : ''}</p>

                                        <div class="space-y-2">
                                            <div class="flex items-center text-sm text-gray-700">
                                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                                <div>
                                                    ${scheduleInfo.day ? `<span class="font-medium">${scheduleInfo.day}</span>` : ''}
                                                    ${scheduleInfo.formatted_time ?
                                                        `<div class="text-xs text-gray-500">${scheduleInfo.formatted_time}</div>` :
                                                        scheduleInfo.time ?
                                                            `<div class="text-xs text-gray-500">${scheduleInfo.time}</div>` :
                                                            scheduleInfo.raw ?
                                                                `<div class="text-xs text-gray-500">${scheduleInfo.raw}</div>` :
                                                                ''
                                                    }
                                                </div>
                                            </div>

                                            ${classRoomInfo.room ? `
                                                <div class="flex items-center text-sm text-gray-700">
                                                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                                    </svg>
                                                    ${classRoomInfo.room}
                                                </div>
                                            ` : ''}

                                            <div class="flex items-center text-sm text-gray-700">
                                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                                </svg>
                                                ${lecturerInfo.name ? lecturerInfo.name.split(' Kontak Dosen :')[0] : 'N/A'}
                                            </div>

                                            ${lecturerInfo.contact || lecturerInfo.email ? `
                                                <div class="flex items-center text-sm text-gray-500">
                                                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                                    </svg>
                                                    ${lecturerInfo.contact ? `📱 ${lecturerInfo.contact}` : ''}
                                                    ${lecturerInfo.contact && lecturerInfo.email ? ' • ' : ''}
                                                    ${lecturerInfo.email ? `✉️ ${lecturerInfo.email}` : ''}
                                                </div>
                                            ` : ''}
                                        </div>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">
                                                #${cls.no || 'N/A'}
                                            </span>
                                            ${courseInfo.credits ? `
                                                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                                    ${courseInfo.credits} SKS
                                                </span>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 lg:mt-0 lg:ml-6 flex flex-col sm:flex-row gap-2 lg:gap-3">
                                ${joinLink.link ? `
                                    <a href="https://my.cic.ac.id/portal/sipandai/${joinLink.link}" target="_blank" class="px-4 py-2.5 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 text-sm font-medium inline-flex items-center justify-center shadow-sm transition-all duration-200">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                        ${joinLink.text || 'Join Kelas'}
                                    </a>
                                ` : ''}
                                <a href="/classes/${params.id || params.course_id || ''}" class="px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 text-sm font-medium inline-flex items-center justify-center shadow-sm transition-all duration-200">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Lihat Detail
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `}).join('')}
        </div>

        ${data.extracted_at ? `
            <div class="mt-6 text-center text-xs text-gray-500">
                <p>Data terakhir diperbarui: ${new Date(data.extracted_at).toLocaleString('id-ID')}</p>
                ${data.data_sources ? `
                    <p class="mt-1">Sumber data: ${Object.keys(data.data_sources).filter(key => data.data_sources[key]).map(source => {
                        if (source === 'sipandai_dashboard') return 'Dashboard SIPANDAI';
                        if (source === 'student_course') return 'Data Kursus Mahasiswa';
                        return source;
                    }).join(' + ')}</p>
                ` : ''}
            </div>
        ` : ''}
    `;
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