{{-- The title of a card header (app.ui.cardTitle): an h2 unless `as` says otherwise. --}}
@props(['as' => 'h2'])

<{{ $as }} {{ $attributes->class('text-sm font-semibold tracking-tight text-ink') }}>{{ $slot }}</{{ $as }}>
