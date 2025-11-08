@props([
    'title',
    'subtitle' => null,
    'active' => null,
    'showStatus' => true,
    'showLogout' => true,
])

<div {{ $attributes->class('min-h-screen bg-gray-50 pb-20') }}>
    <header class="bg-white sticky top-0 z-40 px-4 py-4 shadow-sm border-b border-gray-200">
        <div class="flex items-center justify-between">
            <div class="slide-in">
                <h1 class="text-xl font-bold text-gray-900">{{ $title }}</h1>
                @if($subtitle)
                    <p class="text-sm text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>

            @if($showStatus || $showLogout || isset($headerActions))
                <div class="flex items-center space-x-3">
                    @if($showStatus)
                        <div id="sessionStatus" class="flex items-center space-x-2 px-3 py-1.5 bg-green-50 text-green-700 text-xs rounded-full">
                            <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                            <span class="font-medium">Online</span>
                        </div>
                    @endif

                    @isset($headerActions)
                        {{ $headerActions }}
                    @endisset

                    @if($showLogout)
                        <form action="/logout" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200">
                                Logout
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </header>

    <main class="p-4">
        {{ $slot }}
    </main>

    @if($active)
        <x-bottom-nav :active="$active" />
    @endif
</div>
