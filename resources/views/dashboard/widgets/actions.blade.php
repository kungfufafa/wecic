{{-- "Pusat tindakan": what needs the student now (filled by scripts/action-center). --}}
<x-section title="Pusat tindakan" title-id="dashboard-actions-title" heading-class="mb-0" class="min-w-0" data-dashboard-widget="actions">
    <x-slot:aside class="mb-3 flex min-w-0 flex-wrap items-center gap-2">
        <span id="dashboard-actions-count"></span>
    </x-slot:aside>
    <div id="dashboard-actions" aria-busy="true">
        <x-card>
            <x-card.body class="flex flex-col divide-y divide-border-light" aria-hidden="true">
                @for ($i = 0; $i < 3; $i++)
                    <div class="flex gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="mycic-skeleton w-1 self-stretch"></div>
                        <div class="flex flex-1 flex-col gap-2">
                            <div class="mycic-skeleton h-4 w-20"></div>
                            <div class="mycic-skeleton h-4 w-3/4"></div>
                            <div class="mycic-skeleton h-3 w-1/2"></div>
                        </div>
                    </div>
                @endfor
            </x-card.body>
        </x-card>
        <span class="sr-only">Memuat pusat tindakan…</span>
    </div>
</x-section>
