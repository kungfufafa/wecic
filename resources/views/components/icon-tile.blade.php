{{--
    A square holding an icon or a letter (app.ui.iconTile), for a list row
    whose icon tells the item's type (a file, a notification): `tone` is
    neutral (default, gray), brand, accent, success, warning, danger or info;
    a colour other than neutral is for status only. Not for decoration next
    to a heading or a metric. `accent` is ignored (the tile follows `tone`);
    delete it where you see it. `icon` draws an <x-icon> by name
    (`iconSize` sm, md, lg or xl) before the slot. `shape` rounds it: lg
    (default), md, xl or full. The page sets the size: class="size-9",
    "size-12 text-lg font-semibold"…
--}}
@props(['tone' => 'neutral', 'accent' => null, 'icon' => null, 'iconSize' => 'md', 'shape' => 'lg'])

@php
    $tileBases = [
        'lg' => 'flex shrink-0 items-center justify-center rounded-lg',
        'md' => 'flex shrink-0 items-center justify-center rounded-md',
        'xl' => 'flex shrink-0 items-center justify-center rounded-xl',
        'full' => 'flex shrink-0 items-center justify-center rounded-full',
    ];
    $toneColors = [
        'neutral' => 'bg-surface text-ink-muted',
        'brand' => 'bg-surface-tint text-brand-text',
        'accent' => 'bg-accent-soft text-amber-900 dark:text-amber-200',
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'info' => 'bg-info-soft text-info',
    ];
    $tileClass = [
        $tileBases[$shape] ?? $tileBases['lg'],
        $toneColors[$tone] ?? $toneColors['neutral'],
    ];
@endphp

<span {{ $attributes->class($tileClass) }}>@if (filled($icon))<x-icon :name="$icon" :size="$iconSize" />@endif{{ $slot }}</span>
