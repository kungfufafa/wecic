{{--
    "Ringkasan studi": the KPI strip (IPK, SKS lulus, SKS semester ini,
    Tagihan, Poin SKKM), one bordered box whose cells are split by 1px
    dividers, filled by scripts/metrics. Two columns with Poin SKKM and its
    progress bar across the row below lg, four from lg with Poin SKKM
    across the second row, and one row of five from xl. The script writes
    into [data-metric-value], [data-metric-note] and [data-metric-bar];
    [data-metric-fit] marks a value that shrinks when it is long ("Rp
    750.000" in a half-width cell). From lg the strip sits right under the
    page header (arrange-columns), so its heading is only read there.
--}}
@php
    $metricTiles = [
        ['key' => 'ipk', 'label' => 'IPK', 'href' => route('transcript.index'), 'bar' => false],
        ['key' => 'sks_passed', 'label' => 'SKS lulus', 'href' => route('transcript.index'), 'bar' => true],
        ['key' => 'sks_current', 'label' => 'SKS semester ini', 'href' => route('krs.index'), 'bar' => false],
        ['key' => 'bills', 'label' => 'Tagihan', 'href' => route('finance.index'), 'bar' => false],
    ];
@endphp

<x-section title="Ringkasan studi" title-id="dashboard-metrics-title" heading-class="mb-3 lg:sr-only" class="min-w-0 xl:col-span-12" data-dashboard-widget="metrics">
    <x-stat-strip kpi class="xl:grid-cols-5">
        @foreach ($metricTiles as $tile)
            <x-metric :href="$tile['href']" data-dashboard-metric="{{ $tile['key'] }}" aria-busy="true">
                <x-slot:label class="whitespace-normal!">{{ $tile['label'] }}</x-slot:label>
                <x-slot:value>
                    <span class="block break-words" data-metric-value data-metric-fit>
                        <x-skeleton as="span" class="block h-8 w-16" />
                    </span>
                </x-slot:value>
                <x-slot:note class="flex flex-col gap-2">
                    <span class="flex items-center gap-1.5" data-metric-note>Memuat…</span>
                    @if ($tile['bar'])
                        <span class="block" data-metric-bar aria-hidden="true"></span>
                    @endif
                </x-slot:note>
            </x-metric>
        @endforeach

        <x-metric :href="route('skkm.index')" class="col-span-2 lg:col-span-4 xl:col-span-1" data-dashboard-metric="skkm" aria-busy="true">
            <x-slot:label class="whitespace-normal!">Poin SKKM</x-slot:label>
            <x-slot:value>
                <span class="block break-words" data-metric-value>
                    <x-skeleton as="span" class="block h-8 w-28" />
                </span>
            </x-slot:value>
            <x-slot:note class="flex flex-col gap-2 sm:flex-row-reverse sm:items-center sm:gap-4 xl:flex-col xl:items-stretch xl:gap-2">
                <span class="flex shrink-0 items-center gap-1.5" data-metric-note>Memuat…</span>
                <span class="block sm:flex-1 xl:flex-none" data-metric-bar aria-hidden="true">
                    <span class="flex h-1.5 w-full gap-0.5 overflow-hidden rounded-full bg-surface"></span>
                </span>
            </x-slot:note>
        </x-metric>
    </x-stat-strip>
</x-section>
