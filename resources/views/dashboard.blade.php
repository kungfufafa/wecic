@extends('layouts.app')

@section('title', 'Beranda')
@section('page_id', 'dashboard')

{{--
    The Beranda. Each widget is a partial in resources/views/dashboard/widgets
    with its script part in resources/views/dashboard/scripts (same name, or
    the section it fills: hero → period, actions → action-center).
--}}

@section('content')
    @include('dashboard.widgets.hero')

    {{-- Everything queued while offline (attendance, uploads): not at the portal yet. --}}
    <div class="mb-5 hidden" data-outbox-list data-outbox-heading="Antrean offline"></div>

    {{--
        The DOM follows the §4.2 mobile order (actions, today, assignments,
        metrics, announcements), so focus and reading order match the screen.
        From lg the script below moves the widgets into the 8/4 columns, so
        the main column is read before the aside there too.
    --}}
    <div class="flex flex-col gap-5 lg:grid lg:grid-cols-12 lg:items-start lg:gap-6" data-dashboard-layout>
        <div class="hidden min-w-0 lg:col-span-8 lg:flex lg:flex-col lg:gap-5" data-dashboard-column="main"></div>
        <div class="hidden min-w-0 lg:col-span-4 lg:flex lg:flex-col lg:gap-5" data-dashboard-column="aside"></div>

        @include('dashboard.widgets.actions')
        @include('dashboard.widgets.today')
        @include('dashboard.widgets.assignments')
        @include('dashboard.widgets.metrics')
        @include('dashboard.widgets.announcements')
    </div>
    @include('dashboard.arrange-columns')
@endsection

{{--
    One DOMContentLoaded handler for the whole page: its parts share one
    scope, so they are included in this order (setup first, load last).
--}}
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            @include('dashboard.scripts.setup')
            @include('dashboard.scripts.period')
            @include('dashboard.scripts.metrics')
            @include('dashboard.scripts.action-center')
            @include('dashboard.scripts.announcements')
            @include('dashboard.scripts.today')
            @include('dashboard.scripts.assignments')
            @include('dashboard.scripts.secondary-refresh')
            @include('dashboard.scripts.new-day')
            @include('dashboard.scripts.load')
        });
    </script>
@endpush
