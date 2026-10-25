{{--
    A link of the mobile bottom navigation: its icon (SVG markup), label and
    the badge app.paintNavBadge() fills for `badge`.
--}}
@props(['href', 'label', 'icon', 'badge', 'active' => false])

<a
    href="{{ $href }}"
    class="mycic-bottom-nav-item {{ $active ? 'mycic-bottom-nav-item-active' : 'mycic-bottom-nav-item-inactive' }}"
    data-nav-slot="{{ $badge }}"
    @if ($active) aria-current="page" @endif
>
    <span class="mycic-nav-icon">
        {!! $icon !!}
        <span class="mycic-nav-badge hidden" data-nav-badge="{{ $badge }}"></span>
    </span>
    <span>{{ $label }}</span>
</a>
