{{--
    A link of the desktop sidebar (app.ui.sidebarItem): its icon (SVG markup,
    optional), label and, for `badge`, the count app.paintNavBadge() fills.
    The current page is marked with aria-current, which also draws it raised
    (the panel colour and a ring, its icon in ink): neutral, no colour.
    `navSlot` names the item (data-nav-slot) and defaults to `badge`.
    `accent` is ignored.
--}}
@props(['href', 'label', 'icon' => null, 'badge', 'navSlot' => null, 'active' => false, 'accent' => null])

<a
    href="{{ $href }}"
    {{ $attributes->class('group relative flex h-9 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=page]:bg-panel aria-[current=page]:text-ink aria-[current=page]:shadow-xs aria-[current=page]:ring-1 aria-[current=page]:ring-border dark:hover:bg-white/5 dark:aria-[current=page]:shadow-none') }}
    data-nav-slot="{{ $navSlot ?? $badge }}"
    @if ($active) aria-current="page" @endif
>
    @if ($icon)
        <span class="flex shrink-0 text-ink-subtle transition-colors group-hover:text-ink-muted group-aria-[current=page]:text-ink" data-slot="sidebar-icon">{!! $icon !!}</span>
    @endif
    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
    <span class="ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="{{ $badge }}"></span>
</a>
