{{-- "Hari ini": today's classes and their attendance (filled by scripts/today). --}}
<x-section title="Hari ini" title-id="dashboard-today-title" heading-class="mb-0" class="min-w-0" data-dashboard-widget="today">
    <x-slot:aside class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <span class="text-sm text-ink-subtle" id="dashboard-today-date"></span>
    </x-slot:aside>
    <div id="dashboard-today-notice"></div>
    <div id="dashboard-class-summary" aria-busy="true">
        <x-card>
            <x-card.body class="flex flex-col gap-3" aria-hidden="true">
                <div class="mycic-skeleton h-3.5 w-1/3"></div>
                <div class="mycic-skeleton h-5 w-2/3"></div>
                <div class="mycic-skeleton h-3.5 w-1/2"></div>
            </x-card.body>
        </x-card>
        <span class="sr-only">Memuat jadwal hari ini…</span>
    </div>
</x-section>
