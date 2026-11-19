{{-- The top of a dialog (app.ui.modalHeader): the title on the left, the close button on the right. --}}
<div {{ $attributes->class('flex items-start justify-between gap-3 border-b border-border-light px-4 py-4 sm:px-5') }}>
    {{ $slot }}
</div>
