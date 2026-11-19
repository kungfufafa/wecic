{{--
    "Pengumuman terbaru": the two latest announcements, each a card with a
    gray date tile (filled by scripts/announcements). Two side by side
    from sm, stacked again in the xl aside.
--}}
<x-section title="Pengumuman terbaru" title-id="dashboard-announcements-title" href="{{ route('announcement.index') }}" class="min-w-0" data-dashboard-widget="announcements">
    <div id="dashboard-announcements" aria-busy="true">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1" aria-hidden="true">
            @for ($i = 0; $i < 2; $i++)
                <x-card class="flex items-start gap-3 p-4">
                    <x-skeleton class="size-11 shrink-0 rounded-lg!" />
                    <div class="flex flex-1 flex-col gap-2.5">
                        <x-skeleton class="h-4 w-5/6" />
                        <x-skeleton class="h-3 w-1/2" />
                        <x-skeleton class="h-3 w-2/3" />
                    </div>
                </x-card>
            @endfor
        </div>
        <span class="sr-only">Memuat pengumuman…</span>
    </div>
</x-section>
