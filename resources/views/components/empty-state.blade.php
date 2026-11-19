{{--
    An empty or loading box (app.ui.empty), as app.renderEmptyState() draws
    it from a script: a title, an optional subtitle, an optional small
    neutral icon above the title (app.ui.emptyIcon, no chip), and anything
    else (a button) in the slot. `icon` is an <x-icon> name or a slot of SVG
    markup. `accent` is ignored; delete it where you see it.
--}}
@props(['title', 'subtitle' => null, 'titleClass' => null, 'icon' => null, 'accent' => null])

@php
    $iconAttributes = $icon instanceof \Illuminate\View\ComponentSlot ? $icon->attributes : new \Illuminate\View\ComponentAttributeBag;
@endphp

<div {{ $attributes->class('flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center') }} data-slot="empty">
    @if ($icon !== null && $icon !== '')
        <div {{ $iconAttributes->class('mb-1 flex text-ink-subtle') }} aria-hidden="true">
            @if ($icon instanceof \Illuminate\View\ComponentSlot)
                {{ $icon }}
            @else
                <x-icon :name="$icon" size="lg" />
            @endif
        </div>
    @endif
    <p class="{{ trim('text-sm font-medium text-ink '.$titleClass) }}">{{ $title }}</p>
    @if (filled($subtitle))
        <p class="max-w-sm text-xs leading-relaxed text-ink-subtle">{{ $subtitle }}</p>
    @endif
    {{ $slot }}
</div>
