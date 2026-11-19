{{--
    "Tugas": pending assignments in one list card (filled by scripts/assignments,
    which also writes their number into #dashboard-assignments-count: "Tugas · 4").
--}}
<x-section title="Tugas" title-id="dashboard-assignments-title" id="dashboard-assignments" class="min-w-0 scroll-mt-20" data-dashboard-widget="assignments">
    <x-slot:count id="dashboard-assignments-count"></x-slot:count>
    <div id="dashboard-pending-assignments" aria-busy="true">
        <x-card>
            <div class="flex flex-col gap-3 px-4 py-3 sm:px-5" aria-hidden="true">
                <x-skeleton class="h-3.5 w-1/3" />
                <x-skeleton class="h-5 w-2/3" />
                <x-skeleton class="h-3.5 w-1/2" />
            </div>
        </x-card>
        <span class="sr-only">Memuat tugas…</span>
    </div>
</x-section>
