{{-- A dialog's title (app.ui.modalTitle): an h2 unless `as` says otherwise. --}}
@props(['as' => 'h2'])

<{{ $as }} {{ $attributes->class('text-base font-semibold tracking-tight text-ink') }}>{{ $slot }}</{{ $as }}>
