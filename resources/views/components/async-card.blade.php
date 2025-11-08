@props([
    'title',
    'subtitle' => null,
    'padding' => 'p-4 lg:p-6',
])

<div {{ $attributes->class('bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow duration-300 border border-gray-200 fade-in') }}>
    <div class="{{ $padding }} border-b border-gray-200">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-bold text-gray-900">{{ $title }}</h3>
                @if($subtitle)
                    <p class="text-sm text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($action)
                <div class="mt-3 sm:mt-0 sm:flex-shrink-0">
                    {{ $action }}
                </div>
            @endisset
        </div>
    </div>

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>
</div>
