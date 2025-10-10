@extends('layouts.app')

@section('title', 'Detail Pertemuan - Portal CIC')

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
                    <h1 class="text-lg font-semibold text-gray-900">Detail Pertemuan</h1>
                    <p class="text-sm text-gray-500">Informasi lengkap pertemuan dan materi perkuliahan</p>
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
                    <a href="/classes" class="flex items-center space-x-3 px-3 py-2 text-sm font-medium rounded-lg text-gray-700 hover:bg-gray-50">
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

                <!-- Meeting Detail Card -->
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="p-4 lg:p-6 border-b border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Detail Pertemuan</h3>
                                <p class="text-sm text-gray-500">Informasi lengkap pertemuan dan materi perkuliahan</p>
                            </div>
                            <button onclick="loadMeetingDetail()" class="mt-3 sm:mt-0 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                Refresh Data
                            </button>
                        </div>
                    </div>
                    <div class="p-4 lg:p-6">
                        <div id="meetingDetailContent">
                            <!-- Loading State -->
                            <div class="flex items-center justify-center py-12">
                                <div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                                <span class="ml-3 text-gray-600">Memuat detail pertemuan...</span>
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

    // Load meeting detail data
    loadMeetingDetail();
});

async function loadMeetingDetail() {
    try {
        showLoading(true);

        const meetingId = '{{ $meetingId }}';
        const response = await fetch(`/api/campus/meeting-detail-by-id?meeting_id=${meetingId}`, {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const data = await response.json();
            updateMeetingDetailUI(data);
        } else {
            showError('Gagal memuat detail pertemuan');
        }
    } catch (error) {
        console.error('Meeting detail error:', error);
        showError('Terjadi kesalahan saat memuat data');
    } finally {
        showLoading(false);
    }
}

function updateMeetingDetailUI(data) {
    const content = document.getElementById('meetingDetailContent');
    const detail = data.class_detail || {};
    const meetingInfo = detail.meeting_info || {};
    const lecturerInfo = detail.lecturer_info || {};
    const scheduleInfo = detail.schedule_info || {};
    const rpsInfo = detail.rps_info || {};
    const attendanceInfo = detail.attendance_info || {};
    const assignmentInfo = detail.assignment_info || {};
    const conformityInfo = detail.conformity_info || {};
    const materials = detail.materials || {};
    const instructions = detail.instructions || {};

    content.innerHTML = `
        <div class="space-y-6">
            <!-- Breadcrumb -->
            <div>
                <nav class="text-sm text-gray-500">
                    ${meetingInfo.breadcrumb || ''}
                </nav>
            </div>

            <!-- Informasi Umum Pertemuan -->
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Informasi Pertemuan</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Dosen Pengajar</h5>
                        <p class="text-gray-900 font-medium">${lecturerInfo.name || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Tanggal Pertemuan</h5>
                        <p class="text-gray-900">${scheduleInfo.date || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Mulai Kelas</h5>
                        <p class="text-gray-900">${scheduleInfo.start_time || 'N/A'}</p>
                    </div>
                    <div>
                        <h5 class="text-sm font-medium text-gray-700 mb-1">Absensi</h5>
                        <p class="text-gray-900">${attendanceInfo.status || 'N/A'}</p>
                        ${attendanceInfo.time ? `<p class="text-sm text-gray-500">Jam: ${attendanceInfo.time}</p>` : ''}
                    </div>
                </div>
            </div>

            <!-- RPS -->
            ${rpsInfo.file_link ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">RPS (Rencana Pembelajaran Semester)</h4>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <a href="https://my.cic.ac.id/portal/sipandai/${rpsInfo.file_link}" target="_blank"
                       class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        ${rpsInfo.file_text || 'Open RPS'}
                    </a>
                </div>
            </div>
            ` : ''}

            <!-- Upload Tugas -->
            ${assignmentInfo.upload_link ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Upload Tugas</h4>
                <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg">
                    <div class="mb-3">
                        <p class="text-sm font-medium text-yellow-800">${assignmentInfo.deadline || 'N/A'}</p>
                    </div>
                    <a href="https://my.cic.ac.id/portal/sipandai/${assignmentInfo.upload_link}" target="_blank"
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        ${assignmentInfo.upload_text || 'Upload Tugas'}
                    </a>
                </div>
            </div>
            ` : ''}

            <!-- Kesesuaian Materi -->
            ${conformityInfo.description ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Kesesuaian Materi dengan RPS</h4>
                <div class="bg-blue-50 p-4 rounded-lg mb-4">
                    <p class="text-sm text-gray-700">${conformityInfo.description}</p>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    ${conformityInfo.yes_button ? `
                        <a href="https://my.cic.ac.id/portal/sipandai/${conformityInfo.yes_button.href}"
                           onclick="return confirm('Yakin Anda Akan Menyatakan Perkuliahan Ada, Materi Sesuai Rencana?')"
                           class="inline-flex items-center justify-center px-4 py-3 bg-green-600 text-white rounded hover:bg-green-700 w-full">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            ${conformityInfo.yes_button.text}
                        </a>
                    ` : ''}
                    ${conformityInfo.no_button ? `
                        <a href="https://my.cic.ac.id/portal/sipandai/${conformityInfo.no_button.href}"
                           onclick="return confirm('Yakin Anda Akan Menyatakan Perkuliahan Ada, Materi Tidak Sesuai Rencana?')"
                           class="inline-flex items-center justify-center px-4 py-3 bg-yellow-500 text-black rounded hover:bg-yellow-600 w-full">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            ${conformityInfo.no_button.text}
                        </a>
                    ` : ''}
                </div>
            </div>
            ` : ''}

            <!-- Materi Perkuliahan -->
            ${materials.main_topic ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-4">Materi Perkuliahan</h4>
                <div class="space-y-4">
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h5 class="font-medium text-gray-900 mb-2">${materials.main_topic}</h5>
                        ${materials.main_description ? `
                            <div class="text-sm text-gray-700 mb-4">
                                <strong>Uraian Pokok Pembahasan:</strong>
                                <div class="mt-1">${materials.main_description.replace(/•/g, '• ')}</div>
                            </div>
                        ` : ''}
                        ${materials.main_material_iframe ? `
                            <div class="bg-gray-100 p-2 rounded">
                                <p class="text-xs text-gray-500 mb-2">Materi Perkuliahan:</p>
                                <iframe src="https://my.cic.ac.id/portal/sipandai/${materials.main_material_iframe}"
                                        width="100%" height="400" class="border border-gray-300 rounded"></iframe>
                            </div>
                        ` : ''}
                    </div>

                    ${materials.assignment_topic ? `
                        <div class="border border-gray-200 rounded-lg p-4">
                            <h5 class="font-medium text-gray-900 mb-2">${materials.assignment_topic}</h5>
                            ${materials.assignment_instructions ? `
                                <div class="text-sm text-gray-700 mb-4">
                                    <strong>Due Date:</strong> ${materials.assignment_instructions}
                                </div>
                            ` : ''}
                            ${materials.assignment_material_iframe ? `
                                <div class="bg-gray-100 p-2 rounded">
                                    <p class="text-xs text-gray-500 mb-2">Instruksi Tugas:</p>
                                    <iframe src="https://my.cic.ac.id/portal/sipandai/${materials.assignment_material_iframe}"
                                            width="100%" height="400" class="border border-gray-300 rounded"></iframe>
                                </div>
                            ` : ''}
                        </div>
                    ` : ''}
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
    const content = document.getElementById('meetingDetailContent');
    if (content) {
        content.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-sm font-medium text-gray-900 mb-2">Gagal Memuat Data</h3>
                <p class="text-sm text-gray-500 mb-4">${message}</p>
                <button onclick="loadMeetingDetail()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Coba Lagi
                </button>
            </div>
        `;
    }
}
</script>
@endsection