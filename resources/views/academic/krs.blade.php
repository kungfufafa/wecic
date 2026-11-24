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
    KRS & Perwalian: the hero with the academic tabs, then the "Semester
    ini" and "Riwayat & DPA" tabs (segmented, under the hero), each a
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

            [data-sticky-action] {
                position: static !important;
                border: 0 !important;
            }
        }
    </style>

    <x-page-hero title="Akademik" class="print:hidden">
        <x-slot:tabs class="lg:hidden"><x-academic-menu active="krs" underline /></x-slot:tabs>
    </x-page-hero>

    <div class="hidden print:mb-4 print:block" id="krs-print-header"></div>

    <x-view-tabs as="div" class="mb-6 print:hidden" role="tablist" aria-label="Bagian halaman KRS">
        @foreach ($krsTabs as $key => $label)
            <x-view-tab
                :active="$activeTab === $key"
                id="krs-tab-{{ $key }}"
                aria-controls="krs-panel-{{ $key }}"
                tabindex="{{ $activeTab === $key ? '0' : '-1' }}"
                data-krs-tab="{{ $key }}"
            >{{ $label }}</x-view-tab>
        @endforeach
    </x-view-tabs>

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
