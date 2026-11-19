{{-- An inline text link (app.ui.link). --}}
@props(['href'])

<a href="{{ $href }}" {{ $attributes->class('font-medium text-brand-text underline-offset-4 hover:underline') }}>{{ $slot }}</a>
