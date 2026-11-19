{{--
    A small pastel tag (app.ui.badge): `tone` is neutral (default), brand,
    accent, success, warning, danger or info. Scripts: app.renderBadge(label,
    tone) or app.ui.badge[tone].
--}}
@props(['tone' => 'neutral'])

@php
    $badgeClasses = [
        'neutral' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-surface px-2 py-0.5 text-xs font-medium text-ink-muted ring-1 ring-inset ring-border',
        'brand' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-surface-tint px-2 py-0.5 text-xs font-medium text-brand-text ring-1 ring-inset ring-brand/20',
        'accent' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-accent-soft px-2 py-0.5 text-xs font-medium text-amber-900 ring-1 ring-inset ring-accent/40 dark:text-amber-200',
        'success' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-success-soft px-2 py-0.5 text-xs font-medium text-success ring-1 ring-inset ring-success/20',
        'warning' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning ring-1 ring-inset ring-warning/20',
        'danger' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-danger-soft px-2 py-0.5 text-xs font-medium text-danger ring-1 ring-inset ring-danger/20',
        'info' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-info-soft px-2 py-0.5 text-xs font-medium text-info ring-1 ring-inset ring-info/20',
    ];
    $badgeTone = ['primary' => 'brand', 'secondary' => 'neutral', 'error' => 'danger'][$tone] ?? $tone;
@endphp

<span {{ $attributes->class($badgeClasses[$badgeTone] ?? $badgeClasses['neutral']) }}>{{ $slot }}</span>
