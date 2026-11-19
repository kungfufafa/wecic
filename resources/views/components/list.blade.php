{{--
    A divided list of <x-list-row flush> rows (app.ui.list), straight inside
    an <x-card> (no <x-card.body>). `as="ul"` or "ol" for a real list (its
    rows then need an <li> each).
--}}
@props(['as' => 'div'])

<{{ $as }} {{ $attributes->class('divide-y divide-border-light') }} data-slot="list">
    {{ $slot }}
</{{ $as }}>
