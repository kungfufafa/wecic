@extends('layouts.app')

@section('title', 'Detail Pertemuan')
@section('page_id', 'meeting-show')

{{--
    Detail Pertemuan: a loading header (replaced by the script's plain
    page header), then the material beside the action column (presensi,
    tugas, notebook), which comes first on a phone; skeletons until the
    script fills them. The script is split into
    parts in resources/views/meetings/show/scripts, and render() into parts
    in scripts/render.
--}}
@section('content')
    <div id="meeting-hero">
        <x-page-hero title="Detail pertemuan" aria-busy="true">
            <span class="sr-only">Memuat detail pertemuan…</span>
            <div class="mt-2 flex flex-wrap items-center gap-2" aria-hidden="true">
                <x-skeleton class="h-5 w-32" />
                <x-skeleton class="h-5 w-12" />
                <x-skeleton class="h-5 w-20" />
            </div>
        </x-page-hero>
    </div>

    {{-- Attendance or upload queued for this meeting while offline: not at the portal yet. --}}
    <div class="mb-6 hidden" data-outbox-list data-outbox-match="meeting:{{ e($meetingId) }}" data-outbox-heading="Antrean offline pertemuan ini"></div>

    {{-- On a phone the action column (presensi first) comes before the material. --}}
    <x-split :sticky="false">
        <div class="flex flex-col gap-6" id="meeting-content">
            <x-card>
                <x-card.header>
                    <x-skeleton class="h-5 w-24" />
                </x-card.header>
                <x-card.body class="flex flex-col gap-4">
                    <x-skeleton class="h-5 w-2/3" />
                    <div class="flex flex-col gap-2">
                        <x-skeleton class="h-4 w-full" />
                        <x-skeleton class="h-4 w-5/6" />
                        <x-skeleton class="h-4 w-4/6" />
                    </div>
                    <x-skeleton class="h-20 w-full" />
                </x-card.body>
            </x-card>

            <x-card>
                <x-card.header>
                    <x-skeleton class="h-5 w-20" />
                </x-card.header>
                <x-card.body class="flex flex-col gap-4">
                    <x-skeleton class="h-5 w-1/2" />
                    <x-skeleton class="h-16 w-full" />
                </x-card.body>
            </x-card>
        </div>

        <x-slot:aside id="meeting-actions" class="order-first lg:order-none" aria-label="Presensi dan tugas">
            <x-card>
                <x-card.header>
                    <x-skeleton class="h-5 w-28" />
                </x-card.header>
                <x-card.body class="flex flex-col gap-3">
                    <div class="grid grid-cols-2 gap-2">
                        <x-skeleton class="h-9" />
                        <x-skeleton class="h-9" />
                    </div>
                    <x-skeleton class="h-9 w-full" />
                    <x-skeleton class="h-10 w-full" />
                </x-card.body>
            </x-card>

            <x-card>
                <x-card.header>
                    <x-skeleton class="h-5 w-24" />
                </x-card.header>
                <x-card.body class="flex flex-col gap-3">
                    <x-skeleton class="h-24 w-full" />
                    <x-skeleton class="h-10 w-full" />
                </x-card.body>
            </x-card>
        </x-slot:aside>
    </x-split>
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
