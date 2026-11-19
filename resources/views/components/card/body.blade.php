{{-- The padded content of a <x-card> (app.ui.cardBody). --}}
<div {{ $attributes->class('p-4 sm:p-5') }} data-slot="card-body">
    {{ $slot }}
</div>
