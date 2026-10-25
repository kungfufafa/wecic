{{--
    A link of the desktop top navigation: its icon (SVG markup), a label
    shown from lg up, and the badge app.paintNavBadge() fills for `badge`.
--}}
@props(['href', 'label', 'icon', 'badge', 'active' => false])

<a
    href="{{ $href }}"
    class="mycic-top-nav-link {{ $active ? 'mycic-top-nav-link-active' : '' }}"
    title="{{ $label }}"
    data-nav-slot="{{ $badge }}"
    @if ($active) aria-current="page" @endif
>
    <span class="relative inline-flex">
        {!! $icon !!}
        <span class="mycic-nav-badge hidden" data-nav-badge="{{ $badge }}"></span>
    </span>
    <span class="sr-only lg:not-sr-only">{{ $label }}</span>
</a>
