@extends('layouts.app')

@section('title', 'Dashboard - Portal CIC')

@section('content')
<x-page-shell title="Dashboard" subtitle="Portal Mahasiswa CIC" active="dashboard">
                <!-- Welcome Card -->
                <div class="bg-blue-600 rounded-xl p-6 text-white mb-6 shadow-sm hover:shadow-md transition-shadow duration-300 fade-in">
                    <h2 class="text-2xl font-bold mb-2">Selamat Datang!</h2>
                    <p id="welcomeMessage" class="text-blue-100 mb-4">Sedang memuat informasi...</p>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                            </svg>
                            <span id="studentId" class="font-medium">Loading...</span>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-2 gap-4 mb-6 fade-in">
                    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow duration-200">
                        <div class="flex items-center justify-between mb-2">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                </svg>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-900" id="totalCourses">-</div>
                        <div class="text-sm text-gray-600 font-medium">Mata Kuliah</div>
                    </div>

                    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow duration-200">
                        <div class="flex items-center justify-between mb-2">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-900" id="gpa">-</div>
                        <div class="text-sm text-gray-600 font-medium">IPK</div>
                    </div>

                    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow duration-200">
                        <div class="flex items-center justify-between mb-2">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-900" id="totalCredits">-</div>
                        <div class="text-sm text-gray-600 font-medium">Total SKS</div>
                    </div>

                    <div class="bg-white rounded-xl p-4 border border-gray-200 hover:shadow-md transition-shadow duration-200">
                        <div class="flex items-center justify-between mb-2">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-900" id="outstandingPayments">-</div>
                        <div class="text-sm text-gray-600 font-medium">Tagihan</div>
                    </div>
                </div>

                <!-- Status Card -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow duration-200 fade-in">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-sm font-bold text-gray-900">Status Portal</h3>
                    </div>
                    <div class="p-4">
                        <div id="recentActivity" class="space-y-3 text-sm">
                            <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span class="text-gray-500 font-medium">Memuat data...</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
</x-page-shell>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load dashboard data
    loadDashboardData();
});

async function loadDashboardData() {
    try {
        const response = await fetch('/api/campus/dashboard', {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const data = await response.json();
            updateDashboardUI(data);
        } else {
            showError('Gagal memuat data dashboard');
        }
    } catch (error) {
        console.error('Dashboard error:', error);
        showError('Terjadi kesalahan saat memuat data');
    }
}

function updateDashboardUI(data) {
    // Update user info
    const userName = document.getElementById('userName');
    const welcomeMessage = document.getElementById('welcomeMessage');
    const studentId = document.getElementById('studentId');

    if (data.user_info) {
        const name = data.user_info.name || 'User';
        const nim = data.user_info.student_id || 'N/A';

        if (userName) userName.textContent = name;
        if (welcomeMessage) welcomeMessage.textContent = `Selamat datang, ${name}!`;
        if (studentId) studentId.textContent = nim;
    }

    // Update stats
    const stats = data.dashboard_overview || {};

    updateElement('totalCourses', stats.total_courses || '0');
    updateElement('gpa', stats.gpa || '0.00');
    updateElement('totalCredits', stats.total_credits || '0');
    updateElement('outstandingPayments', stats.outstanding_payments || 'Rp 0');

    // Update status
    const recentActivity = document.getElementById('recentActivity');
    if (recentActivity) {
        recentActivity.innerHTML = `
            <div class="flex items-center space-x-3 p-3 bg-green-50 rounded-lg">
                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="text-green-700">Terhubung ke portal CIC</span>
            </div>
        `;
    }

    // Update session status
    updateSessionStatus(true);
}

function updateElement(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function updateSessionStatus(isActive) {
    const sessionStatus = document.getElementById('sessionStatus');
    if (sessionStatus) {
        if (isActive) {
            sessionStatus.className = 'flex items-center space-x-2 px-3 py-1.5 bg-green-50 text-green-700 text-xs rounded-full';
            sessionStatus.innerHTML = '<div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div><span>Online</span>';
        } else {
            sessionStatus.className = 'flex items-center space-x-2 px-3 py-1.5 bg-red-50 text-red-700 text-xs rounded-full';
            sessionStatus.innerHTML = '<div class="w-2 h-2 bg-red-500 rounded-full"></div><span>Offline</span>';
        }
    }
}

function showError(message) {
    const recentActivity = document.getElementById('recentActivity');
    if (recentActivity) {
        recentActivity.innerHTML = `
            <div class="flex items-center space-x-3 p-3 bg-red-50 rounded-lg">
                <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="text-red-700">${message}</span>
            </div>
        `;
    }
}
</script>
@endsection
