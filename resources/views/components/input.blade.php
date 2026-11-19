{{--
    A text input (app.ui.input). `icon` leaves room for an icon on the start
    side, `toggle` for a button on the end side (both inside a
    <div class="relative flex items-center">, app.ui.inputWrap). A file
    input (type="file") takes app.ui.fileInput. Mark an error with
    aria-invalid="true".
--}}
@props(['icon' => false, 'toggle' => false])

@php
    $inputClass = match (true) {
        $attributes->get('type') === 'file' => 'w-full min-w-0 cursor-pointer rounded-lg border border-border bg-panel py-1.5 pe-3 ps-1.5 text-xs text-ink-subtle shadow-xs transition-[color,box-shadow,border-color] file:me-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-tint focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:opacity-60 dark:shadow-none',
        $icon && $toggle => 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-9 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
        $icon => 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-3 ps-9 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
        $toggle => 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel pe-11 ps-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
        default => 'h-11 w-full min-w-0 rounded-lg border border-border bg-panel px-3 text-base text-ink shadow-xs transition-[color,box-shadow,border-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand/20 disabled:cursor-not-allowed disabled:bg-surface disabled:opacity-60 aria-invalid:border-danger aria-invalid:ring-danger/20 sm:h-9 sm:text-sm dark:shadow-none',
    };
@endphp

<input {{ $attributes->merge(['type' => 'text'])->class($inputClass) }}>
