@extends('layouts.app')

@section('title', 'Detail Pertemuan - Portal CIC')

@section('content')
<x-page-shell title="Detail Pertemuan" subtitle="Informasi lengkap pertemuan dan materi perkuliahan" active="classes">
    <div class="mb-6">
        <a href="/classes" class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900 font-medium transition-colors duration-200">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Kembali ke Daftar Kelas
        </a>
    </div>

    <x-async-card title="Detail Pertemuan" subtitle="Informasi lengkap pertemuan dan materi perkuliahan">
        <x-slot:action>
            <button onclick="loadMeetingDetail()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm hover:shadow-md transition-all duration-200 text-sm font-medium">
                Refresh Data
            </button>
        </x-slot:action>

        <div id="meetingDetailContent">
                            <!-- Shimmer Loading State -->
                            <div class="space-y-6 animate-pulse">
                                <!-- Meeting Info Card Skeleton -->
                                <div class="bg-gray-100 rounded-xl border border-gray-200 overflow-hidden">
                                    <div class="bg-gray-300 p-4">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-12 h-12 bg-gray-400 rounded-lg shimmer"></div>
                                            <div class="flex-1 space-y-2">
                                                <div class="h-6 w-3/4 bg-gray-400 rounded shimmer"></div>
                                                <div class="h-4 w-1/2 bg-gray-400 rounded shimmer"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="p-4 space-y-3">
                                        <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                        <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                        <div class="h-4 w-3/4 bg-gray-200 rounded shimmer"></div>
                                    </div>
                                </div>

                                <!-- Description Skeleton -->
                                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                                    <div class="bg-gray-300 p-4">
                                        <div class="h-6 w-40 bg-gray-400 rounded shimmer"></div>
                                    </div>
                                    <div class="p-4 space-y-3">
                                        <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                        <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                        <div class="h-4 w-2/3 bg-gray-200 rounded shimmer"></div>
                                    </div>
                                </div>

                                <!-- Materials Skeleton -->
                                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                                    <div class="bg-gray-300 p-4">
                                        <div class="h-6 w-32 bg-gray-400 rounded shimmer"></div>
                                    </div>
                                    <div class="p-4 space-y-4">
                                        <div class="border border-gray-200 rounded-lg p-4">
                                            <div class="h-6 w-3/4 bg-gray-200 rounded shimmer mb-3"></div>
                                            <div class="h-4 w-full bg-gray-200 rounded shimmer mb-2"></div>
                                            <div class="h-4 w-2/3 bg-gray-200 rounded shimmer"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-async-card>
</x-page-shell>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load meeting detail data
    loadMeetingDetail();
});

async function loadMeetingDetail() {
    try {
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
            <!-- Meeting Info Card -->
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">Detail Pertemuan</h3>
                        <p class="text-sm text-gray-600">${meetingInfo.title || scheduleInfo.date || 'N/A'}</p>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-sm text-gray-500">Dosen</span>
                        <span class="text-sm font-medium text-gray-900">${lecturerInfo.name || 'N/A'}</span>
                    </div>
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-sm text-gray-500">Tanggal</span>
                        <span class="text-sm font-medium text-gray-900">${scheduleInfo.date || 'N/A'}</span>
                    </div>
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <span class="text-sm text-gray-500">Waktu</span>
                        <span class="text-sm font-medium text-gray-900">${scheduleInfo.start_time || 'N/A'}</span>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="text-sm text-gray-500">Absensi</span>
                        <div class="text-right">
                            <span class="text-sm font-medium text-gray-900">${attendanceInfo.status || 'N/A'}</span>
                            ${attendanceInfo.time ? `<p class="text-xs text-gray-500">${attendanceInfo.time}</p>` : ''}
                        </div>
                    </div>
                </div>
            </div>

            <!-- RPS -->
            ${rpsInfo.file_link ? `
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    RPS (Rencana Pembelajaran Semester)
                </h4>
                <div class="bg-green-50 p-4 rounded-lg">
                    <a href="https://my.cic.ac.id/portal/sipandai/${rpsInfo.file_link}" target="_blank"
                       class="flex items-center justify-center w-full px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        ${rpsInfo.file_text || 'Buka RPS'}
                    </a>
                </div>
            </div>
            ` : ''}

            <!-- Upload Tugas -->
            ${assignmentInfo.upload_link ? `
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    Upload Tugas
                </h4>
                <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg">
                    ${assignmentInfo.deadline ? `
                        <div class="mb-3">
                            <p class="text-sm font-medium text-yellow-800">
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                ${assignmentInfo.deadline}
                            </p>
                        </div>
                    ` : ''}
                    <a href="https://my.cic.ac.id/portal/sipandai/${assignmentInfo.upload_link}" target="_blank"
                       class="flex items-center justify-center w-full px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
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
