{{-- The footer strip of a <x-card> (app.ui.cardFooter): actions in a row under a top border. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-3 border-t border-border-light px-4 py-3 sm:px-5') }} data-slot="card-footer">
    {{ $slot }}
</div>
