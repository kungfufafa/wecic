{{-- "Ringkasan studi": four metric tiles and the SKKM bar (filled by scripts/metrics). --}}
@php
    $metricTiles = [
        ['key' => 'ipk', 'label' => 'IPK', 'href' => route('transcript.index'), 'bar' => false],
        ['key' => 'sks_passed', 'label' => 'SKS lulus', 'href' => route('transcript.index'), 'bar' => true],
        ['key' => 'sks_current', 'label' => 'SKS semester ini', 'href' => route('krs.index'), 'bar' => false],
        ['key' => 'bills', 'label' => 'Tagihan', 'href' => route('finance.index'), 'bar' => false],
    ];
@endphp

<x-section title="Ringkasan studi" title-id="dashboard-metrics-title" class="min-w-0" data-dashboard-widget="metrics">
    <div class="grid grid-cols-2 gap-3">
        @foreach ($metricTiles as $tile)
            <a
                href="{{ $tile['href'] }}"
                class="mycic-metric min-w-0 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99]"
                data-dashboard-metric="{{ $tile['key'] }}"
                aria-busy="true"
            >
                <span class="mycic-metric-label whitespace-normal">{{ $tile['label'] }}</span>
                <span class="mycic-metric-value break-words" data-metric-value>
                    <span class="mycic-skeleton block h-7 w-16" aria-hidden="true"></span>
                </span>
                <span class="mycic-metric-note flex items-center gap-1.5" data-metric-note>Memuat…</span>
                @if ($tile['bar'])
                    <span class="mt-auto block pt-1" data-metric-bar aria-hidden="true"></span>
                @endif
            </a>
        @endforeach
    </div>
    <x-card
        as="a"
        :href="route('skkm.index')"
        class="mt-3 flex min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.99] md:p-5"
        data-dashboard-metric="skkm"
        aria-busy="true"
    >
        <span class="flex items-baseline justify-between gap-3">
            <span class="mycic-metric-label">Poin SKKM</span>
            <span class="shrink-0 text-sm font-bold tabular-nums" data-metric-value>
                <span class="mycic-skeleton block h-4 w-16" aria-hidden="true"></span>
            </span>
        </span>
        <span class="block" data-metric-bar aria-hidden="true">
            <span class="mycic-progress-track h-1.5"></span>
        </span>
        <span class="mycic-metric-note flex items-center gap-1.5" data-metric-note>Memuat…</span>
    </x-card>
</x-section>
