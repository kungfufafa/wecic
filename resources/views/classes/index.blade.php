@extends('layouts.app')

@section('title', 'Jadwal & Kelas Perkuliahan')
@section('page_id', 'class-index')

{{--
    Jadwal & Kelas: the summary, the day strip, the Grid, Timeline and
    Daftar views, and the class detail modal (in classes/index/widgets).
    The script is split into parts in resources/views/classes/index/scripts.
--}}
@section('content')
    <x-page-header title="Jadwal & Kelas" class="flex items-center justify-between gap-3">
        <x-button :href="route('exam.index')" variant="secondary" size="sm" class="relative min-h-11 shrink-0 sm:min-h-9">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 15l2 2l4 -4" /></svg>
            Ujian
            <span class="mycic-nav-badge hidden" data-nav-badge="exam"></span>
        </x-button>
    </x-page-header>

    <div id="class-summary" class="mb-5 grid grid-cols-3 gap-3">
        <x-metric label="Kelas">
            <x-slot:value data-summary-value=""><div class="mycic-skeleton h-6 w-12"></div></x-slot:value>
        </x-metric>
        <x-metric label="Semester">
            <x-slot:value data-summary-value=""><div class="mycic-skeleton h-6 w-16"></div></x-slot:value>
        </x-metric>
        <x-metric label="Hari aktif">
            <x-slot:value id="summary-active-days"><div class="mycic-skeleton h-6 w-16"></div></x-slot:value>
            <x-slot:note id="summary-total-hours"></x-slot:note>
        </x-metric>
    </div>

    <div id="day-summary-strip" class="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <!-- Rendered by JS -->
    </div>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-view-tabs as="div" class="shrink-0" id="view-mode-tabs" role="tablist">
            <x-view-tab :active="true" data-view-target="blocking" aria-controls="view-blocking" id="tab-blocking">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>
                <span>Grid</span>
            </x-view-tab>
            <x-view-tab data-view-target="timeline" aria-controls="view-timeline" id="tab-timeline">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 15" /></svg>
                <span>Timeline</span>
            </x-view-tab>
            <x-view-tab data-view-target="list" aria-controls="view-list" id="tab-list">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></svg>
                <span>Daftar</span>
            </x-view-tab>
        </x-view-tabs>

        <div class="mycic-input-wrap w-full sm:w-64">
            <span class="mycic-input-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
            </span>
            <input
                type="search"
                id="class-search-input"
                class="mycic-input-with-icon h-9 text-xs"
                placeholder="Cari kelas..."
                aria-label="Cari mata kuliah, dosen, atau ruang"
                autocomplete="off"
            >
        </div>
    </div>

    <div id="view-blocking" class="view-panel mb-6" role="tabpanel" aria-labelledby="tab-blocking">
        <x-card class="overflow-hidden">
            <div id="blocking-grid-container" class="overflow-x-auto p-2 sm:p-4">
                <x-empty-state class="py-12" title="Memuat jadwal..." title-class="text-ink-subtle" />
            </div>
        </x-card>
    </div>

    <!-- View Container 2: Timeline & Jam Kosong -->
    <div id="view-timeline" class="view-panel mb-6 hidden" role="tabpanel" aria-labelledby="tab-timeline">
        <div id="timeline-container" class="flex flex-col gap-4">
            <x-empty-state class="py-12" title="Memuat jadwal..." title-class="text-ink-subtle" />
        </div>
    </div>

    <!-- View Container 3: Daftar Kelas Asli (List View) -->
    <div id="view-list" class="view-panel mb-6 hidden" role="tabpanel" aria-labelledby="tab-list">
        <div class="mb-4 flex flex-col gap-3" id="class-list">
            <x-empty-state class="py-8" title="Memuat jadwal..." title-class="text-ink-subtle" />
        </div>
    </div>

    <!-- Unscheduled / Flexible Classes Section (if any classes lack fixed day/time) -->
    <div id="unscheduled-classes-container" class="mb-6 hidden">
        <!-- Rendered if unscheduled classes exist -->
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
