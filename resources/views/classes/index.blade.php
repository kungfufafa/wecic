@extends('layouts.app')

@section('title', 'Jadwal & Kelas Perkuliahan')
@section('page_id', 'class-index')

{{--
    Jadwal & Kelas: the hero (with the Ujian link), the KPI strip, the week
    strip that filters by day, the Grid, Timeline and Daftar views, and the
    class detail modal (in classes/index/widgets).
    The script is split into parts in resources/views/classes/index/scripts.
--}}
@section('content')
    <x-page-hero title="Jadwal & Kelas">
        <x-slot:actions>
            <x-button :href="route('exam.index')" variant="secondary" size="sm" class="relative">
                <x-icon name="exam" size="sm" />
                Ujian
                <span class="pointer-events-none absolute -end-2 -top-1.5 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[11px] font-medium leading-4 text-white tabular-nums ring-2 ring-panel data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2.5 data-[variant=dot]:min-w-2.5 data-[variant=dot]:px-0 hidden" data-nav-badge="exam"></span>
            </x-button>
        </x-slot:actions>
    </x-page-hero>

    <x-stat-strip kpi id="class-summary" class="mb-6">
        <x-metric label="Kelas">
            <x-slot:value data-summary-value=""><x-skeleton class="h-7 w-10" /></x-slot:value>
        </x-metric>
        <x-metric label="Semester">
            <x-slot:value class="truncate text-lg! leading-snug sm:text-xl!" data-summary-value=""><x-skeleton class="h-7 w-16" /></x-slot:value>
        </x-metric>
        <x-metric label="Hari aktif">
            <x-slot:value id="summary-active-days"><x-skeleton class="h-7 w-10" /></x-slot:value>
            <x-slot:note id="summary-total-hours"></x-slot:note>
        </x-metric>
        <x-metric label="Hari ini">
            <x-slot:value id="summary-today"><x-skeleton class="h-7 w-10" /></x-slot:value>
            <x-slot:note id="summary-today-note"></x-slot:note>
        </x-metric>
    </x-stat-strip>

    <div class="flex flex-col gap-6">
        <x-section title="Minggu ini" title-id="class-week-title">
            {{-- The week strip (scripts/summary): one cell per day inside a bordered strip, today marked with a dot. --}}
            <div id="day-summary-strip" class="grid grid-cols-3 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-6">
                @for ($i = 0; $i < 6; $i++)
                    <div class="flex flex-col gap-2 bg-panel p-3.5 sm:p-4" aria-hidden="true">
                        <x-skeleton class="h-3.5 w-12" />
                        <x-skeleton class="h-5 w-16" />
                    </div>
                @endfor
            </div>
        </x-section>

        <x-section title="Jadwal" title-id="class-schedule-title" heading-class="">
            <x-slot:aside class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <x-view-tabs as="div" class="shrink-0" id="view-mode-tabs" role="tablist">
                        <x-view-tab :active="true" data-view-target="blocking" aria-controls="view-blocking" id="tab-blocking">
                            <x-icon name="calendar" size="sm" />
                            <span>Grid</span>
                        </x-view-tab>
                        <x-view-tab data-view-target="timeline" aria-controls="view-timeline" id="tab-timeline">
                            <x-icon name="clock" size="sm" />
                            <span>Timeline</span>
                        </x-view-tab>
                        <x-view-tab data-view-target="list" aria-controls="view-list" id="tab-list">
                            <x-icon name="list-check" size="sm" />
                            <span>Daftar</span>
                        </x-view-tab>
                    </x-view-tabs>

                    <div class="relative flex w-full items-center sm:w-56 lg:w-64">
                        <span class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle">
                            <x-icon name="search" size="sm" />
                        </span>
                        <x-input
                            icon
                            type="search"
                            id="class-search-input"
                            placeholder="Cari kelas"
                            aria-label="Cari mata kuliah, dosen, atau ruang"
                            autocomplete="off"
                        />
                    </div>
                </div>
            </x-slot:aside>

            <div id="view-blocking" class="view-panel" role="tabpanel" aria-labelledby="tab-blocking">
                <x-card class="overflow-hidden">
                    <div id="blocking-grid-container" class="isolate overflow-x-auto">
                        <div class="flex flex-col items-center gap-2 px-4 py-12 text-center">
                            <x-spinner class="text-ink-subtle" />
                            <p class="text-sm text-ink-subtle">Memuat jadwal...</p>
                        </div>
                    </div>
                </x-card>
            </div>

            <!-- View Container 2: Timeline & Jam Kosong -->
            <div id="view-timeline" class="view-panel hidden" role="tabpanel" aria-labelledby="tab-timeline">
                <div id="timeline-container" class="flex flex-col gap-4">
                    <x-empty-state title="Memuat jadwal..." title-class="font-normal! text-ink-subtle!" />
                </div>
            </div>

            <!-- View Container 3: Daftar Kelas Asli (List View) -->
            <div id="view-list" class="view-panel hidden" role="tabpanel" aria-labelledby="tab-list">
                <div class="flex flex-col gap-3" id="class-list">
                    <x-empty-state title="Memuat jadwal..." title-class="font-normal! text-ink-subtle!" />
                </div>
            </div>

            <!-- Unscheduled / Flexible Classes Section (if any classes lack fixed day/time) -->
            <div id="unscheduled-classes-container" class="mt-6 hidden">
                <!-- Rendered if unscheduled classes exist -->
            </div>
        </x-section>
    </div>

    <!-- Interactive Class Detail Modal -->
    @include('classes.index.widgets.detail-modal')
@endsection

{{--
    One DOMContentLoaded handler for the whole page: its parts share one
    scope, so they are included in this order (setup first, load last).
--}}
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            @include('classes.index.scripts.setup')
            @include('classes.index.scripts.parse')
            @include('classes.index.scripts.summary')
            @include('classes.index.scripts.grid')
            @include('classes.index.scripts.timeline')
            @include('classes.index.scripts.list')
            @include('classes.index.scripts.meetings')
            @include('classes.index.scripts.modal')
            @include('classes.index.scripts.view-modes')
            @include('classes.index.scripts.load')
        });
    </script>
@endpush
