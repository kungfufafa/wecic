{{--
    The header strip of a <x-card> (app.ui.cardHeader): a row, title on the
    left and an action on the right. Put a title with a subtitle under it
    in a <div class="min-w-0">.
--}}
<div {{ $attributes->class('flex items-center justify-between gap-3 border-b border-border-light px-4 py-3.5 sm:px-5 sm:py-4') }} data-slot="card-header">
    {{ $slot }}
</div>
