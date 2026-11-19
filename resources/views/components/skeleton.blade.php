{{--
    A loading placeholder (app.ui.skeleton, data-slot="skeleton"): the page
    sets its size (class="h-4 w-4/5"). `as="span"` inside inline content.
--}}
@props(['as' => 'div'])

<{{ $as }} {{ $attributes->merge(['aria-hidden' => 'true'])->class('animate-pulse rounded-md bg-surface') }} data-slot="skeleton"></{{ $as }}>
