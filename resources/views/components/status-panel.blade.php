{{--
    A soft tinted box without a border (app.ui.panel): a result, a status
    line. `tone` is neutral (default), brand, info, success, warning or
    danger. Scripts: app.statusPanelClass(tone) or app.ui.panel[tone].
--}}
@props(['tone' => 'neutral'])

@php
    $panelClasses = [
        'neutral' => 'rounded-lg bg-surface p-3 text-ink-muted',
        'brand' => 'rounded-lg bg-surface-tint p-3 text-brand-text',
        'info' => 'rounded-lg bg-info-soft p-3 text-info',
        'success' => 'rounded-lg bg-success-soft p-3 text-success',
        'warning' => 'rounded-lg bg-warning-soft p-3 text-warning',
        'danger' => 'rounded-lg bg-danger-soft p-3 text-danger',
    ];
    $panelTone = ['primary' => 'brand', 'secondary' => 'neutral', 'error' => 'danger'][$tone] ?? $tone;
@endphp

<div {{ $attributes->class($panelClasses[$panelTone] ?? $panelClasses['neutral']) }}>{{ $slot }}</div>
