{{--
    "Hari ini": today's classes as a vertical timeline, with their attendance
    (filled by scripts/today). The heading row carries
    #dashboard-today-date, which the script fills, as does the title
    ("Jadwal Kamis" while the snapshot is still a day old).
--}}
<x-section title="Hari ini" title-id="dashboard-today-title" heading-class="" class="min-w-0" data-dashboard-widget="today">
    <x-slot:aside class="mb-3 flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 gap-y-1" data-slot="section-heading">
        <span class="text-xs text-ink-muted" id="dashboard-today-date"></span>
    </x-slot:aside>
    <div id="dashboard-today-notice"></div>
    <div id="dashboard-class-summary" aria-busy="true">
        <div class="flex flex-col" aria-hidden="true">
            @for ($i = 0; $i < 2; $i++)
                <div class="relative flex gap-3 pb-5 last:pb-0">
                    <div class="flex w-10 shrink-0 justify-end pt-0.5 sm:w-12"><x-skeleton class="h-3.5 w-9" /></div>
                    <div class="relative flex w-3 shrink-0 justify-center">
                        <span class="mt-1.5 size-2.5 rounded-full bg-border"></span>
                        @if ($i === 0)
                            <span class="absolute top-4 -bottom-5 w-px bg-border"></span>
                        @endif
                    </div>
                    <x-card class="min-w-0 flex-1">
                        <x-card.body class="flex flex-col gap-3">
                            <x-skeleton class="h-3.5 w-1/3" />
                            <x-skeleton class="h-5 w-2/3" />
                            <x-skeleton class="h-3.5 w-1/2" />
                        </x-card.body>
                    </x-card>
                </div>
            @endfor
        </div>
        <span class="sr-only">Memuat jadwal hari ini…</span>
    </div>
</x-section>
