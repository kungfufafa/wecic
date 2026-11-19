{{--
    A button or, with `href`, a link that looks like one: `variant` is
    primary, secondary, ghost, destructive or icon (`on-brand`, from the
    old brand-blue greeting card, now draws secondary; delete it where you
    see it), `size="sm"` the compact
    one (still 44 px tall on phones). A <button> is type="button" unless the
    page says otherwise. Scripts draw the same classes with app.ui.button
    (or app.buttonClass()). A full-width button adds class="w-full".
--}}
@props(['variant' => 'primary', 'size' => null, 'href' => null])

@php
    $buttonClasses = [
        'primary' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-brand px-4 text-sm font-medium text-brand-ink shadow-xs ring-offset-panel transition-colors hover:bg-brand-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 active:bg-brand-pressed disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9',
        'secondary' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-border bg-panel px-4 text-sm font-medium text-ink shadow-xs ring-offset-panel transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9 dark:shadow-none',
        'ghost' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 text-sm font-medium text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9',
        'destructive' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-danger px-4 text-sm font-medium text-white shadow-xs ring-offset-panel transition-colors hover:bg-danger/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-9',
        'icon' => 'inline-flex size-9 shrink-0 cursor-pointer select-none items-center justify-center rounded-lg text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        'primarySm' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-brand px-3 text-[13px] font-medium text-brand-ink shadow-xs ring-offset-panel transition-colors hover:bg-brand-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 active:bg-brand-pressed disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8',
        'secondarySm' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-border bg-panel px-3 text-[13px] font-medium text-ink shadow-xs ring-offset-panel transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8 dark:shadow-none',
        'ghostSm' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-[13px] font-medium text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8',
        'destructiveSm' => 'inline-flex min-h-11 cursor-pointer select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-danger px-3 text-[13px] font-medium text-white shadow-xs ring-offset-panel transition-colors hover:bg-danger/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 sm:min-h-8',
        'iconSm' => 'inline-flex size-8 shrink-0 cursor-pointer select-none items-center justify-center rounded-lg text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
    ];
    $buttonVariant = $variant === 'on-brand' ? 'secondary' : \Illuminate\Support\Str::camel($variant);
    $buttonClass = $buttonClasses[$buttonVariant.($size === 'sm' ? 'Sm' : '')] ?? $buttonClasses['primary'];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($buttonClass) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($buttonClass) }}>{{ $slot }}</button>
@endif
