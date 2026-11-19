{{--
    A dialog's overlay (app.ui.modal), hidden until a script removes
    `hidden` (keep that toggle: the overlay has no display class of its own,
    so `hidden` always wins). Inside: <x-modal.backdrop>, then
    <x-modal.panel> holding <x-modal.header> (title and <x-modal.close>),
    <x-modal.body> and <x-modal.footer>. `open` renders it shown.
--}}
@props(['open' => false])

<div {{ $attributes->merge(['tabindex' => '-1', 'aria-hidden' => $open ? 'false' : 'true'])->class(['fixed inset-0 z-50', 'hidden' => ! $open]) }}>
    {{ $slot }}
</div>
