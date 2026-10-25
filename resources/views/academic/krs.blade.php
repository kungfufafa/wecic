@extends('layouts.app')

@section('title', 'KRS & Perwalian')
@section('page_id', 'krs-index')

@php
    $activeTab = request()->query('tab') === 'riwayat' ? 'riwayat' : 'semester';
    $krsTabs = [
        'semester' => 'Semester ini',
        'riwayat' => 'Riwayat & DPA',
    ];
@endphp

{{--
    KRS & Perwalian: the "Semester ini" and "Riwayat & DPA" tabs, each a
    partial in resources/views/academic/krs/widgets. The script is split
    into parts in resources/views/academic/krs/scripts.
--}}
@section('content')
    <style>
        @media print {
            [data-sync-alert],
            [data-pull-indicator],
            [data-operation-status] {
                display: none !important;
            }

            .mycic-sticky-action {
                position: static !important;
                border: 0 !important;
            }
        }
    </style>

    <x-academic-menu active="krs" class="print:hidden" />

    <x-page-header title="KRS & Perwalian" class="print:hidden">
        <x-slot:subtitle id="krs-period-label">Status KRS, periode, dan kontak DPA kamu.</x-slot:subtitle>
        <p class="mt-1 text-caption text-ink-subtle" data-krs-updated></p>
    </x-page-header>

    <div class="hidden print:mb-4 print:block" id="krs-print-header"></div>

    <div class="mb-4 flex gap-1 border-b border-border-light print:hidden" role="tablist" aria-label="Bagian halaman KRS">
        @foreach ($krsTabs as $key => $label)
            <button
                type="button"
                role="tab"
                id="krs-tab-{{ $key }}"
                aria-controls="krs-panel-{{ $key }}"
                aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === $key ? '0' : '-1' }}"
                data-krs-tab="{{ $key }}"
                class="-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-3 text-sm font-semibold text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-selected:border-brand aria-selected:text-brand"
            >{{ $label }}</button>
        @endforeach
    </div>

    @include('academic.krs.widgets.semester')

    @include('academic.krs.widgets.riwayat')
@endsection

{{--
    One DOMContentLoaded handler for the whole page: its parts share one
    scope, so they are included in this order (setup first, load last).
--}}
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            @include('academic.krs.scripts.setup')
            @include('academic.krs.scripts.dpa-contact')
            @include('academic.krs.scripts.periods')
            @include('academic.krs.scripts.status')
            @include('academic.krs.scripts.schedule')
            @include('academic.krs.scripts.courses')
            @include('academic.krs.scripts.riwayat')
            @include('academic.krs.scripts.receive')
            @include('academic.krs.scripts.tabs')
            @include('academic.krs.scripts.load')
        });
    </script>
@endpush
