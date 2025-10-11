{{-- Toast Component --}}
@if (isset($toast) && $toast)
    <div
        x-data="{
            show: true,
            type: '{{ $toast['type'] }}',
            message: '{{ $toast['message'] }}',
            duration: {{ $toast['duration'] ?? 3000 }},
            icon: '{{ $toast['icon'] ?? 'information-circle' }}',
            action: @json($toast['action'] ?? [])
        }"
        x-show="show"
        x-init="setTimeout(() => { show = false; }, duration)"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        class="fixed top-4 right-4 z-50 max-w-sm w-full pointer-events-none"
        style="pointer-events: auto;"
    >
        <div class="bg-white rounded-lg shadow-xl pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden">
            <div class="p-4">
                <div class="flex items-start">
                    <!-- Icon -->
                    <div class="flex-shrink-0">
                        @if ($toast['type'] === 'success')
                            <svg class="h-6 w-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                        @elseif ($toast['type'] === 'error')
                            <svg class="h-6 w-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l-2 2m-2-2l2-2m-2 2l2 2m-2-2l2-2"></path>
                                <circle cx="12" cy="12" r="9"></circle>
                            </svg>
                        @elseif ($toast['type'] === 'warning')
                            <svg class="h-6 w-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.532-3L16.5 9c-.313-.313-.562-.592-.532-.823L12.5 5.25 8.532 2.823c.03.231.219.51.532.823m-13.856 0L6.968 16.5C6.498 18.333 8.05 20 9.586 20m13.856-4c1.54 0 2.502-1.667 1.532-3L21 9c-.313-.313-.562-.592-.532-.823m0 0L9.468 2.823C8.05 1.667 6.498 0 4.968 0L3.464 5.823c-.03.231-.219.51-.532.823m0 0l7.064 14.917C11.05 20.833 12.95 20.833 15.032 20.833c1.54 0 2.502-1.667 1.532-3L16.5 9c-.313-.313-.562-.592-.532-.823"></path>
                            </svg>
                        @else
                            <svg class="h-6 w-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9h.01M12 12h.01M12 16h.01"></path>
                            </svg>
                        @endif
                    </div>

                    <!-- Content -->
                    <div class="ml-3 flex-1">
                        <p class="text-sm font-medium text-gray-900">
                            {{ $toast['message'] }}
                        </p>

                        @if (!empty($toast['action']) && isset($toast['action']['url']))
                            <div class="mt-2">
                                <a
                                    href="{{ $toast['action']['url'] }}"
                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                                >
                                    {{ $toast['action']['text'] }}
                                    <svg class="ml-1.5 -mr-0.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5v6m0-6L13 7"></path>
                                    </svg>
                                </a>
                            </div>
                        @endif
                    </div>

                    <!-- Close Button -->
                    <div class="ml-4 flex-shrink-0">
                        <button
                            type="button"
                            @click="show = false"
                            class="inline-flex text-gray-400 hover:text-gray-500 focus:outline-none focus:text-gray-500 transition-colors"
                        >
                            <span class="sr-only">Dismiss</span>
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293 4.293a1 1 0 001.414 1.414L10 10.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 00-1.414 1.414L10 11.414l4.293 4.293a1 1 0 001.414-1.414L10 10.586 5.707 6.293a1 1 0 01-1.414 1.414z" clip-rule="evenodd"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="absolute bottom-0 left-0 h-1 bg-gray-200 rounded-b-lg transition-all duration-300 ease-out"
             x-bind="style={`width: ${100 - ((Date.now() - startTime) / duration * 100)}%`}"
             x-data="{
                 startTime: Date.now(),
                 duration: {{ $toast['duration'] ?? 3000 }}
             }"
        ></div>
    </div>
@endif