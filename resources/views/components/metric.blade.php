{{--
    One cell of a <x-stat-strip> (app.ui.metric): a label, a value and an
    optional note, on the panel colour with no border of its own (the strip
    draws the frame and the 1px dividers). `label` is a string or a named
    slot. The value and the note are named slots, so a page can give them
    the id or data attribute its script fills (`<x-slot:value id="quick-nim">`).
    With `href` the cell is a link (app.ui.metricInteractive).

    `icon` (a prop or a slot) and `accent` are ignored: metrics carry no
    icon chip. Delete them where you see them.
--}}
@props(['label' => null, 'href' => null, 'icon' => null, 'accent' => null])

@php
    $labelAttributes = $label instanceof \Illuminate\View\ComponentSlot ? $label->attributes : new \Illuminate\View\ComponentAttributeBag;
@endphp

<{{ $href !== null ? 'a' : 'div' }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class($href !== null ? 'flex min-w-0 cursor-pointer flex-col gap-1 bg-panel p-4 transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-5' : 'flex min-w-0 flex-col gap-1 bg-panel p-4 sm:p-5') }} data-slot="metric">
    <div {{ $labelAttributes->class('truncate text-xs font-medium text-ink-muted') }} data-slot="metric-label">{{ $label }}</div>
    <div {{ $value->attributes->class('text-2xl font-semibold tracking-tight text-ink tabular-nums') }} data-slot="metric-value">{{ $value }}</div>
    @isset($note)
        <div {{ $note->attributes->class('text-xs text-ink-subtle') }} data-slot="metric-note">{{ $note }}</div>
    @endisset
</{{ $href !== null ? 'a' : 'div' }}>
