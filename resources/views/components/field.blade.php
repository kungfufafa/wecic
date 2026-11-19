{{--
    A form field (app.ui.field): its label (`label`, for the control whose
    id is `for`), the control in the slot, then an optional `hint` and
    `error`. The hint's id is `{for}-hint` and the error's `{for}-error`
    unless `hintId` / `errorId` say otherwise; point the control's
    aria-describedby at them.
--}}
@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null, 'hintId' => null, 'errorId' => null])

<div {{ $attributes->class('flex flex-col gap-1.5') }}>
    @if (filled($label))
        <label @if ($for) for="{{ $for }}" @endif class="text-sm font-medium text-ink">{{ $label }}</label>
    @endif
    {{ $slot }}
    @if (filled($hint))
        <p class="text-xs leading-relaxed text-ink-subtle" @if ($hintId ?? ($for ? $for.'-hint' : null)) id="{{ $hintId ?? $for.'-hint' }}" @endif>{{ $hint }}</p>
    @endif
    @if (filled($error))
        <p class="text-xs font-medium leading-relaxed text-danger" @if ($errorId ?? ($for ? $for.'-error' : null)) id="{{ $errorId ?? $for.'-error' }}" @endif>{{ $error }}</p>
    @endif
</div>
