{{--
    A nested sub-link inside the desktop sidebar tree (app.ui.sidebarSubItem).
    Uses aria-current="location" for the active sub-page so the parent section
    item keeps the single aria-current="page" within Navigasi utama.
--}}
@props(['href', 'label', 'icon' => null, 'active' => false, 'subSlot' => null, 'badge' => null])

<a
    href="{{ $href }}"
    {{ $attributes->class('group relative flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-ink-muted transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=location]:bg-black/[0.06] aria-[current=location]:text-ink dark:hover:bg-white/5 dark:aria-[current=location]:bg-surface') }}
    @if ($subSlot !== null) data-sidebar-subitem="{{ $subSlot }}" @endif
    @if ($active) aria-current="location" @endif
>
    @if ($icon)
        <span class="flex shrink-0 text-ink-subtle transition-colors group-hover:text-ink-muted group-aria-[current=location]:text-ink" data-slot="sidebar-subicon">
            @if (str_starts_with(ltrim((string) $icon), '<svg'))
                {!! $icon !!}
            @else
                <x-icon :name="$icon" size="sm" />
            @endif
        </span>
    @endif
    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
    @if ($badge)
        <span class="ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="{{ $badge }}"></span>
    @endif
</a>
