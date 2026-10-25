{{-- "Tugas": pending assignments (filled by scripts/assignments). --}}
<x-section title="Tugas" title-id="dashboard-assignments-title" class="min-w-0" data-dashboard-widget="assignments">
    <div id="dashboard-pending-assignments" aria-busy="true">
        <x-card>
            <x-card.body class="flex flex-col gap-3" aria-hidden="true">
                <div class="mycic-skeleton h-3.5 w-1/3"></div>
                <div class="mycic-skeleton h-5 w-2/3"></div>
                <div class="mycic-skeleton h-3.5 w-1/2"></div>
            </x-card.body>
        </x-card>
        <span class="sr-only">Memuat tugas…</span>
    </div>
</x-section>
