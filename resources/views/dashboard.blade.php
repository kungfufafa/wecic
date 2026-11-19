@extends('layouts.app')

@section('title', 'Beranda')
@section('page_id', 'dashboard')

{{--
    The Beranda. Each widget is a partial in resources/views/dashboard/widgets
    with its script part in resources/views/dashboard/scripts (same name, or
    the section it fills: hero → period, actions → action-center). The
    greeting header (hero) opens the page in place of an <x-page-hero>.
--}}

@section('content')
    @include('dashboard.widgets.hero')

    {{-- Everything queued while offline (attendance, uploads): not at the portal yet. --}}
    <div class="mb-6 hidden" data-outbox-list data-outbox-heading="Antrean offline"></div>

    {{--
        The DOM follows the §4.2 mobile order (actions, today, assignments,
        metrics, announcements), so focus and reading order match the screen.
        arrange-columns moves the widgets, so the DOM keeps matching the
        screen there too: from lg the KPI strip (metrics) comes first, under
        the greeting header; from xl it spans the 12-column grid and the rest
        is a bento of the main column (today's timeline, tasks) and the
        aside (action centre, announcements).
    --}}
    <div class="flex flex-col gap-6 xl:grid xl:grid-cols-12 xl:items-start" data-dashboard-layout>
        <div class="hidden min-w-0 xl:col-span-8 xl:flex xl:flex-col xl:gap-6" data-dashboard-column="main"></div>
        <div class="hidden min-w-0 xl:col-span-4 xl:flex xl:flex-col xl:gap-6" data-dashboard-column="aside"></div>

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
