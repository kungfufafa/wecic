@extends('layouts.app')

@section('title', 'Detail Pertemuan')
@section('page_id', 'meeting-show')

{{--
    Detail Pertemuan: loading skeletons for the hero, the content and the
    action column until the script fills them. The script is split into
    parts in resources/views/meetings/show/scripts, and render() into parts
    in scripts/render.
--}}
@section('content')
    <div id="meeting-hero" class="mb-5">
        <div class="mycic-hero">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <div class="mycic-skeleton h-8 w-28 rounded-lg bg-white/20"></div>
                        <div class="mycic-skeleton h-6 w-20 rounded-md bg-white/20"></div>
                    </div>
                    <div class="mycic-skeleton h-6 w-32 rounded-full bg-white/20"></div>
                </div>
                <div class="space-y-2">
                    <div class="mycic-skeleton h-7 w-3/4 max-w-md rounded-lg bg-white/20"></div>
                    <div class="mycic-skeleton h-4 w-1/2 max-w-sm rounded bg-white/20"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Attendance or upload queued for this meeting while offline: not at the portal yet. --}}
    <div class="mb-5 hidden" data-outbox-list data-outbox-match="meeting:{{ e($meetingId) }}" data-outbox-heading="Antrean offline pertemuan ini"></div>

    <div class="grid gap-5 lg:grid-cols-12">
        <div class="space-y-5 lg:col-span-8" id="meeting-content">
            <x-card>
                <x-card.body class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div class="mycic-skeleton h-6 w-40"></div>
                        <div class="mycic-skeleton h-8 w-24 rounded-lg"></div>
                    </div>
                    <div class="space-y-2">
                        <div class="mycic-skeleton h-4 w-full"></div>
                        <div class="mycic-skeleton h-4 w-5/6"></div>
                        <div class="mycic-skeleton h-4 w-4/6"></div>
                    </div>
                    <div class="mycic-skeleton h-20 w-full rounded-xl"></div>
                </x-card.body>
            </x-card>

            <x-card>
                <x-card.body class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div class="mycic-skeleton h-6 w-48"></div>
                        <div class="mycic-skeleton h-6 w-32 rounded-md"></div>
                    </div>
                    <div class="mycic-skeleton h-16 w-full rounded-xl"></div>
                </x-card.body>
            </x-card>
        </div>

        <div class="space-y-5 order-first lg:order-none lg:col-span-4" id="meeting-actions">
            <x-card>
                <x-card.header>
                    <div class="mycic-skeleton h-5 w-28"></div>
                </x-card.header>
                <x-card.body class="flex flex-col gap-3">
                    <div class="mycic-skeleton h-10 w-full rounded-lg"></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="mycic-skeleton h-10 rounded-lg"></div>
                        <div class="mycic-skeleton h-10 rounded-lg"></div>
                    </div>
                    <div class="mycic-skeleton h-11 w-full rounded-lg"></div>
                </x-card.body>
            </x-card>

            <x-card>
                <x-card.header>
                    <div class="mycic-skeleton h-5 w-24"></div>
                </x-card.header>
                <x-card.body class="flex flex-col gap-3">
                    <div class="mycic-skeleton h-24 w-full rounded-xl"></div>
                    <div class="mycic-skeleton h-11 w-full rounded-lg"></div>
                </x-card.body>
            </x-card>
        </div>
    </div>
@endsection

{{--
    One DOMContentLoaded handler for the whole page: its parts share one
    scope, so they are included in this order (setup first, load last).
--}}
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            @include('meetings.show.scripts.setup')
            @include('meetings.show.scripts.parse')
            @include('meetings.show.scripts.attendance-code')
            @include('meetings.show.scripts.slots')
            @include('meetings.show.scripts.render')
            @include('meetings.show.scripts.load')
        });
    </script>
@endpush
