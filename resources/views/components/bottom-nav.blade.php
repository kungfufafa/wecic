@props(['active' => 'dashboard'])

<!-- Clean Bottom Navigation -->
<nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 px-6 py-3 z-50 shadow-sm">
    <div class="max-w-md mx-auto grid grid-cols-3 gap-2">
        <!-- Dashboard -->
        <a href="/dashboard" class="group flex flex-col items-center py-2 px-3 {{ $active === 'dashboard' ? 'text-blue-600 rounded-xl bg-blue-50' : 'text-gray-500 hover:text-blue-600 rounded-xl hover:bg-blue-50/50' }} transition-all duration-200">
            <svg class="w-7 h-7 mb-1 group-hover:scale-110 transition-transform duration-200" fill="{{ $active === 'dashboard' ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span class="text-xs {{ $active === 'dashboard' ? 'font-bold' : 'font-semibold' }}">Beranda</span>
        </a>

        <!-- Classes -->
        <a href="/classes" class="group flex flex-col items-center py-2 px-3 {{ $active === 'classes' ? 'text-purple-600 rounded-xl bg-purple-50' : 'text-gray-500 hover:text-purple-600 rounded-xl hover:bg-purple-50/50' }} transition-all duration-200">
            <svg class="w-7 h-7 mb-1 group-hover:scale-110 transition-transform duration-200" fill="{{ $active === 'classes' ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            <span class="text-xs {{ $active === 'classes' ? 'font-bold' : 'font-semibold' }}">Kelas</span>
        </a>

        <!-- Profile -->
        <a href="/profile" class="group flex flex-col items-center py-2 px-3 {{ $active === 'profile' ? 'text-indigo-600 rounded-xl bg-indigo-50' : 'text-gray-500 hover:text-indigo-600 rounded-xl hover:bg-indigo-50/50' }} transition-all duration-200">
            <svg class="w-7 h-7 mb-1 group-hover:scale-110 transition-transform duration-200" fill="{{ $active === 'profile' ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <span class="text-xs {{ $active === 'profile' ? 'font-bold' : 'font-semibold' }}">Profile</span>
        </a>
    </div>
</nav>
