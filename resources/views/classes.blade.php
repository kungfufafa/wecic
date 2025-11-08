@extends('layouts.app')

@section('title', 'Daftar Kelas - Portal CIC')

@section('content')
<x-page-shell title="Daftar Kelas" subtitle="Informasi Kelas dan Mata Kuliah" active="classes">
    <x-async-card title="Daftar Kelas" subtitle="Kelas yang sedang diambil semester ini">
        <x-slot:action>
            <button onclick="loadClassesData()" class="px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white rounded-lg shadow-sm hover:shadow-md transition-all duration-200 text-sm font-medium">
                Refresh Data
            </button>
        </x-slot:action>

        <div id="classesContent">
            <!-- Shimmer Loading State -->
            <div class="space-y-4 animate-pulse">
                <!-- Class Card Skeleton 1 -->
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-12 h-12 bg-gray-200 rounded-lg shimmer flex-shrink-0"></div>
                            <div class="flex-1 space-y-3">
                                <div class="h-6 w-3/4 bg-gray-200 rounded shimmer"></div>
                                <div class="h-4 w-1/2 bg-gray-200 rounded shimmer"></div>
                                <div class="space-y-2">
                                    <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                    <div class="h-4 w-2/3 bg-gray-200 rounded shimmer"></div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="h-6 w-16 bg-gray-200 rounded-full shimmer"></div>
                                    <div class="h-6 w-20 bg-gray-200 rounded-full shimmer"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class Card Skeleton 2 -->
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-12 h-12 bg-gray-200 rounded-lg shimmer flex-shrink-0"></div>
                            <div class="flex-1 space-y-3">
                                <div class="h-6 w-2/3 bg-gray-200 rounded shimmer"></div>
                                <div class="h-4 w-1/2 bg-gray-200 rounded shimmer"></div>
                                <div class="space-y-2">
                                    <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                    <div class="h-4 w-3/4 bg-gray-200 rounded shimmer"></div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="h-6 w-16 bg-gray-200 rounded-full shimmer"></div>
                                    <div class="h-6 w-20 bg-gray-200 rounded-full shimmer"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class Card Skeleton 3 -->
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-12 h-12 bg-gray-200 rounded-lg shimmer flex-shrink-0"></div>
                            <div class="flex-1 space-y-3">
                                <div class="h-6 w-4/5 bg-gray-200 rounded shimmer"></div>
                                <div class="h-4 w-1/2 bg-gray-200 rounded shimmer"></div>
                                <div class="space-y-2">
                                    <div class="h-4 w-full bg-gray-200 rounded shimmer"></div>
                                    <div class="h-4 w-2/3 bg-gray-200 rounded shimmer"></div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="h-6 w-16 bg-gray-200 rounded-full shimmer"></div>
                                    <div class="h-6 w-20 bg-gray-200 rounded-full shimmer"></div>
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
    // Load classes data
    loadClassesData();
});

async function loadClassesData() {
    try {
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
    }
}

function updateClassesUI(data) {
    const classesContent = document.getElementById('classesContent');

    if (!classesContent) {
        return;
    }

    const classes = data.classes || [];
    const studentInfo = data.student_info || data.user_info || {};
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
                <button onclick="loadClassesData()" class="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:border-gray-400">
                    Muat Ulang
                </button>
            </div>
        `;
        return;
    }

    const sections = [
        renderStudentInfo(studentInfo),
        renderAcademicInfo(semesterInfo, academicSummary),
        `<div class="space-y-5">${classes.map(renderClassCard).join('')}</div>`,
        renderDataFootnote(data),
    ].filter(Boolean);

    classesContent.innerHTML = sections.join('');
}

function renderStudentInfo(studentInfo = {}) {
    const stats = [
        { label: 'NIM', value: studentInfo.nim },
        { label: 'Program Studi', value: studentInfo.program_studi },
    ].filter(stat => stat.value);

    if (!studentInfo.nama && !stats.length) {
        return '';
    }

    const gridColumns = stats.length ? `sm:grid-cols-${Math.min(stats.length, 3) || 1}` : null;

    return `
        <section class="mb-6 rounded-2xl border border-gray-200 bg-white/80 p-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Mahasiswa</p>
                    <p class="mt-2 text-lg font-semibold text-gray-900">${studentInfo.nama || '-'}</p>
                    <p class="text-sm text-gray-500">${studentInfo.nim || ''}</p>
                </div>
                ${studentInfo.program_studi ? `
                    <div class="rounded-full border border-gray-200 px-4 py-1 text-sm font-medium text-gray-700">
                        ${studentInfo.program_studi}
                    </div>
                ` : ''}
            </div>
            ${stats.length ? `
                <div class="mt-4 grid gap-4 ${gridColumns}">
                    ${stats.map(stat => renderInfoStat(stat.label, stat.value)).join('')}
                </div>
            ` : ''}
        </section>
    `;
}

function renderAcademicInfo(semesterInfo = {}, academicSummary = {}) {
    const stats = [
        { label: 'Semester', value: semesterInfo.semester || academicSummary.semester },
        { label: 'Total SKS', value: academicSummary.total_credits },
        { label: 'IPK', value: academicSummary.gpa },
        { label: 'Status', value: academicSummary.status },
    ].filter(stat => stat.value);

    if (!stats.length) {
        return '';
    }

    const gridColumns = `sm:grid-cols-${Math.min(stats.length, 4) || 1}`;

    return `
        <section class="mb-6 rounded-2xl border border-gray-200 bg-white/80 p-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Ringkasan Akademik</p>
                    <p class="mt-2 text-sm text-gray-500">Update jadwal & status perkuliahan</p>
                </div>
            </div>
            <div class="mt-4 grid gap-4 ${gridColumns}">
                ${stats.map(stat => renderInfoStat(stat.label, stat.value)).join('')}
            </div>
        </section>
    `;
}

function renderClassCard(cls) {
    const courseInfo = cls.course_info || {};
    const lecturerInfo = cls.lecturer_info || {};
    const scheduleInfo = cls.schedule || {};
    const joinLink = cls.join_link || {};
    const params = joinLink.params || {};
    const classRoomInfo = cls.class_room_info || {};
    const detailParams = params.id || params.course_id || classRoomInfo.class_id || cls.id || '';

    const detailItems = [
        { label: 'Dosen Pengampu', value: sanitizeLecturerName(lecturerInfo.name) },
        { label: 'Jadwal', value: formatSchedule(scheduleInfo) },
        { label: 'Ruang', value: classRoomInfo.room },
        { label: 'Lokasi', value: classRoomInfo.location || classRoomInfo.mode },
        { label: 'SKS', value: courseInfo.credits ? `${courseInfo.credits} SKS` : null },
    ].filter(item => item.value);

    const metaTags = [
        classRoomInfo.class_name ? `Grup ${classRoomInfo.class_name}` : '',
    ].filter(Boolean);

    return `
        <div class="rounded-2xl border border-gray-200 bg-white/80 p-5 shadow-sm transition-all duration-200 hover:border-gray-300 hover:shadow-md">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Mata Kuliah</p>
                    <h4 class="text-xl font-semibold text-gray-900">${courseInfo.name || 'Tanpa Judul'}</h4>
                    <p class="text-sm text-gray-500">${courseInfo.code || 'Kode belum tersedia'}</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    ${joinLink.link ? `
                        <a href="https://my.cic.ac.id/portal/sipandai/${joinLink.link}" target="_blank" class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800">
                            <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            ${joinLink.text || 'Masuk Portal'}
                        </a>
                    ` : ''}
                    <a href="/classes/${detailParams}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:border-gray-400">
                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Lihat Detail
                    </a>
                </div>
            </div>

            ${metaTags.length ? `
                <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold text-gray-700">
                    ${metaTags.map(tag => `<span class="inline-flex items-center rounded-full border border-gray-200 px-3 py-1">${tag}</span>`).join('')}
                </div>
            ` : ''}

            ${detailItems.length ? `
                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                    ${detailItems.map(item => renderDetailItem(item.label, item.value)).join('')}
                </div>
            ` : ''}
        </div>
    `;
}

function renderDataFootnote(data) {
    if (!data.extracted_at && !data.data_sources) {
        return '';
    }

    const sources = data.data_sources
        ? Object.keys(data.data_sources)
            .filter(key => data.data_sources[key])
            .map(source => {
                if (source === 'sipandai_dashboard') return 'Dashboard SIPANDAI';
                if (source === 'student_course') return 'Data Kursus Mahasiswa';
                return source;
            })
        : [];

    return `
        <div class="mt-6 text-center text-xs text-gray-500">
            ${data.extracted_at ? `<p>Data terakhir diperbarui: ${formatDateTimeValue(data.extracted_at)}</p>` : ''}
            ${sources.length ? `<p class="mt-1">Sumber data: ${sources.join(' • ')}</p>` : ''}
        </div>
    `;
}

function renderInfoStat(label, value) {
    if (!value) {
        return '';
    }

    return `
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">${label}</p>
            <p class="mt-2 text-base font-semibold text-gray-900">${value}</p>
        </div>
    `;
}

function renderDetailItem(label, value) {
    if (!value) {
        return '';
    }

    return `
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">${label}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900">${value}</p>
        </div>
    `;
}

function sanitizeLecturerName(name) {
    if (!name) {
        return null;
    }

    return name.split(' Kontak Dosen :')[0];
}

function formatSchedule(scheduleInfo = {}) {
    if (!Object.keys(scheduleInfo).length) {
        return null;
    }

    const time = scheduleInfo.formatted_time || scheduleInfo.time || scheduleInfo.raw;

    if (scheduleInfo.day && time) {
        return `${scheduleInfo.day}, ${time}`;
    }

    return scheduleInfo.day || time || null;
}

function formatDateTimeValue(value) {
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
    if (userName && userInfo) {
        const name = userInfo.name || userInfo.nama;
        if (name) {
            userName.textContent = name;
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
                <button onclick="loadClassesData()" class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:border-gray-400">
                    Coba Lagi
                </button>
            </div>
        `;
    }
}
</script>
@endsection
