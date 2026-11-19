{{--
    "Pusat tindakan": what needs the student now (filled by scripts/action-center).
    The script writes the number of items into #dashboard-actions-count, the
    muted count after the title ("Pusat tindakan · 3"); empty, it hides.
--}}
<x-section title="Pusat tindakan" title-id="dashboard-actions-title" class="min-w-0" data-dashboard-widget="actions">
    <x-slot:count id="dashboard-actions-count"></x-slot:count>
    <div id="dashboard-actions" aria-busy="true">
        <x-card>
            <x-card.body class="flex flex-col divide-y divide-border-light" aria-hidden="true">
                @for ($i = 0; $i < 3; $i++)
                    <div class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                        <x-skeleton class="h-4 w-20" />
                        <x-skeleton class="h-4 w-3/4" />
                        <x-skeleton class="h-3 w-1/2" />
                    </div>
                @endfor
            </x-card.body>
        </x-card>
        <span class="sr-only">Memuat pusat tindakan…</span>
    </div>
</x-section>
