{{--
    The dialog box, centred (app.ui.modalPanel; `size="lg"` is the wider
    app.ui.modalPanelLg). Give it role="dialog", aria-modal and
    aria-labelledby when the overlay does not carry them.
--}}
@props(['size' => 'md'])

<div {{ $attributes->class($size === 'lg' ? 'absolute left-1/2 top-1/2 z-10 max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border border-border bg-panel shadow-float focus:outline-none' : 'absolute left-1/2 top-1/2 z-10 max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border border-border bg-panel shadow-float focus:outline-none') }}>
    {{ $slot }}
</div>
