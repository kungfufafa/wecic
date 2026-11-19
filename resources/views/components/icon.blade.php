{{--
    A tabler-style outline icon by `name` (resources/views/components/data/icons.php:
    home, class, academic, finance, calendar, clock, bell, search…). `size` is
    sm (16 px), md (18 px, the default), lg (20 px) or xl (24 px); a
    script draws the same with app.renderIcon(name, size). An unknown name
    draws nothing. The icon is decorative (aria-hidden): label its button.
--}}
@props(['name', 'size' => 'md'])

@php
    $iconPaths = require resource_path('views/components/data/icons.php');
    $iconSizes = [
        'sm' => ['class' => 'size-4 shrink-0', 'px' => 16],
        'md' => ['class' => 'size-[18px] shrink-0', 'px' => 18],
        'lg' => ['class' => 'size-5 shrink-0', 'px' => 20],
        'xl' => ['class' => 'size-6 shrink-0', 'px' => 24],
    ];
    $iconSize = $iconSizes[$size] ?? $iconSizes['md'];
@endphp

@isset($iconPaths[$name])
    <svg xmlns="http://www.w3.org/2000/svg" {{ $attributes->class($iconSize['class']) }} width="{{ $iconSize['px'] }}" height="{{ $iconSize['px'] }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="{{ $name }}">{!! $iconPaths[$name] !!}</svg>
@endisset
