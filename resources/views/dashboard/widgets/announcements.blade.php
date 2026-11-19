{{-- "Pengumuman terbaru": the latest announcements (filled by scripts/announcements). --}}
<x-section title="Pengumuman terbaru" title-id="dashboard-announcements-title" heading-class="mb-0" class="min-w-0" data-dashboard-widget="announcements">
    <x-slot:aside class="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <a href="{{ route('announcement.index') }}" class="mycic-link inline-flex min-h-11 sm:min-h-9 items-center rounded-md px-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Lihat semua</a>
    </x-slot:aside>
    <div id="dashboard-announcements" aria-busy="true">
        <div class="grid gap-3 sm:grid-cols-2" aria-hidden="true">
            @for ($i = 0; $i < 2; $i++)
                <x-card class="flex flex-col gap-2.5 p-4">
                    <div class="mycic-skeleton h-4 w-24"></div>
                    <div class="mycic-skeleton h-4 w-5/6"></div>
                    <div class="mycic-skeleton h-3 w-1/2"></div>
                </x-card>
            @endfor
        </div>
        <span class="sr-only">Memuat pengumuman…</span>
    </div>
</x-section>
