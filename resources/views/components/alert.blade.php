{{--
    A message box (app.ui.alert): `tone` is neutral (default), brand, info,
    success, warning or danger. `icon` puts the tone's icon before the text,
    `title` a bold first line. Give it role="alert" or role="status" when a
    script fills it. Scripts: app.alertTone(tone) or app.ui.alert[tone].
--}}
@props(['tone' => 'neutral', 'icon' => false, 'title' => null])

@php
    $alertTone = ['primary' => 'brand', 'secondary' => 'neutral', 'error' => 'danger'][$tone] ?? $tone;
    $alertClasses = [
        'neutral' => 'rounded-lg border border-border bg-panel px-4 py-3 text-sm text-ink-muted',
        'brand' => 'rounded-lg border border-brand/20 bg-surface-tint px-4 py-3 text-sm text-ink',
        'info' => 'rounded-lg border border-info/20 bg-info-soft px-4 py-3 text-sm text-ink',
        'success' => 'rounded-lg border border-success/25 bg-success-soft px-4 py-3 text-sm text-ink',
        'warning' => 'rounded-lg border border-warning/25 bg-warning-soft px-4 py-3 text-sm text-ink',
        'danger' => 'rounded-lg border border-danger/25 bg-danger-soft px-4 py-3 text-sm text-ink',
    ];
    $alertIconClasses = [
        'neutral' => 'mt-0.5 size-4 shrink-0 text-ink-subtle',
        'brand' => 'mt-0.5 size-4 shrink-0 text-brand-text',
        'info' => 'mt-0.5 size-4 shrink-0 text-info',
        'success' => 'mt-0.5 size-4 shrink-0 text-success',
        'warning' => 'mt-0.5 size-4 shrink-0 text-warning',
        'danger' => 'mt-0.5 size-4 shrink-0 text-danger',
    ];
    $alertTone = array_key_exists($alertTone, $alertClasses) ? $alertTone : 'neutral';
@endphp

<div {{ $attributes->class([$alertClasses[$alertTone], 'flex gap-3' => $icon]) }}>
    @if ($icon)
        @if (in_array($alertTone, ['warning', 'danger'], true))
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $alertIconClasses[$alertTone] }}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
        @elseif ($alertTone === 'success')
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $alertIconClasses[$alertTone] }}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
        @else
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $alertIconClasses[$alertTone] }}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>
        @endif
        <div class="min-w-0 flex-1">
            @if (filled($title))
                <p class="font-medium">{{ $title }}</p>
            @endif
            {{ $slot }}
        </div>
    @else
        @if (filled($title))
            <p class="font-medium">{{ $title }}</p>
        @endif
        {{ $slot }}
    @endif
</div>
