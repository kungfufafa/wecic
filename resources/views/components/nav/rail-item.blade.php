{{--
    An icon button of the far-left desktop activity rail (app.ui.railItem).
    `icon` accepts either an icon name from components/data/icons.php or SVG
    markup. `railSlot` sets data-rail-slot, `navSlot` optionally sets
    data-nav-slot, and `badge` attaches an optional data-nav-badge counter.
--}}
@props(['href', 'label', 'icon', 'active' => false, 'railSlot' => null, 'navSlot' => null, 'badge' => null])

<a
    href="{{ $href }}"
    {{ $attributes->class('group relative flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-subtle transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=page]:bg-black/[0.08] aria-[current=page]:text-ink data-[rail-selected=true]:bg-black/[0.08] data-[rail-selected=true]:text-ink dark:hover:bg-white/5 dark:aria-[current=page]:bg-surface dark:data-[rail-selected=true]:bg-surface') }}
    title="{{ $label }}"
    aria-label="{{ $label }}"
    @if ($railSlot) data-rail-slot="{{ $railSlot }}" @endif
    @if ($navSlot) data-nav-slot="{{ $navSlot }}" @endif
    @if ($active) aria-current="page" @endif
>
    <span class="flex shrink-0 text-ink-subtle transition-colors group-hover:text-ink-muted group-aria-[current=page]:text-ink" data-slot="sidebar-icon">
        @if (str_starts_with(ltrim((string) $icon), '<svg'))
            {!! $icon !!}
        @else
            <x-icon :name="$icon" />
        @endif
    </span>
    @if ($badge)
        <span class="pointer-events-none absolute -end-1 -top-1 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[10px] font-medium leading-4 text-white tabular-nums ring-2 ring-canvas data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="{{ $badge }}"></span>
    @endif
</a>
