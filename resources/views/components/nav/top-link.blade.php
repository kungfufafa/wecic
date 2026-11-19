{{--
    A compact top-bar link (app.ui.topNavLink): its icon (SVG markup), a
    label shown from lg up, and the badge app.paintNavBadge() fills for
    `badge`. The shell's sidebar uses <x-nav.sidebar-item> instead.
--}}
@props(['href', 'label', 'icon', 'badge', 'active' => false])

<a
    href="{{ $href }}"
    class="inline-flex min-h-9 shrink-0 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=page]:bg-surface aria-[current=page]:text-ink"
    title="{{ $label }}"
    data-nav-slot="{{ $badge }}"
    @if ($active) aria-current="page" @endif
>
    <span class="relative flex">
        {!! $icon !!}
        <span class="pointer-events-none absolute -end-2 -top-1.5 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[11px] font-medium leading-4 text-white tabular-nums ring-2 ring-panel data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2.5 data-[variant=dot]:min-w-2.5 data-[variant=dot]:px-0 hidden" data-nav-badge="{{ $badge }}"></span>
    </span>
    <span class="sr-only lg:not-sr-only">{{ $label }}</span>
</a>
