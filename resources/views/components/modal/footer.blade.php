{{-- A dialog's actions (app.ui.modalFooter): stacked on a phone, in a row on the right from sm up. --}}
<div {{ $attributes->class('flex flex-col-reverse gap-2 border-t border-border-light px-4 py-3 sm:flex-row sm:justify-end sm:px-5') }}>
    {{ $slot }}
</div>
