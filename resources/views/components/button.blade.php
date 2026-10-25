{{--
    A button or, with `href`, a link that looks like one: `variant` is
    primary, secondary, ghost, destructive or icon (.mycic-btn-*), `size="sm"`
    the compact one. A <button> is type="button" unless the page says
    otherwise. Scripts draw the same classes with app.buttonClass().
--}}
@props(['variant' => 'primary', 'size' => null, 'href' => null])

@php
    $buttonClass = 'mycic-btn-'.$variant.($size === 'sm' ? '-sm' : '');
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($buttonClass) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($buttonClass) }}>{{ $slot }}</button>
@endif
