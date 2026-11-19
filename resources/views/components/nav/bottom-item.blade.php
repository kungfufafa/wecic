{{--
    A link of the mobile bottom navigation (app.ui.bottomNavItem): its icon
    (SVG markup), label and the badge app.paintNavBadge() fills for `badge`.
    aria-current marks, and draws, the current section.
--}}
@props(['href', 'label', 'icon', 'badge', 'active' => false])

<a
    href="{{ $href }}"
    class="group flex flex-1 flex-col items-center gap-1 rounded-lg px-1 py-1 text-[11px] font-medium text-ink-subtle transition-colors active:bg-surface aria-[current=page]:text-brand-text"
    data-nav-slot="{{ $badge }}"
    @if ($active) aria-current="page" @endif
>
    <span class="relative flex h-7 w-11 items-center justify-center rounded-lg transition-colors group-aria-[current=page]:bg-surface-tint group-aria-[current=page]:text-brand-text" data-nav-icon>
        {!! $icon !!}
        <span class="pointer-events-none absolute end-1.5 -top-0.5 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[11px] font-medium leading-4 text-white tabular-nums ring-2 ring-panel data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2.5 data-[variant=dot]:min-w-2.5 data-[variant=dot]:px-0 hidden" data-nav-badge="{{ $badge }}"></span>
    </span>
    <span>{{ $label }}</span>
</a>
